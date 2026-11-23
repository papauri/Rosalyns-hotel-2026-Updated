<?php
/**
 * One save engine for blocked nights (GRID_UNIFY_PLAN Phase 2): the Blocked Dates calendar (grid_apply), its list
 * forms (block_date / block_multiple), the Calendar's Block nights, the Assistant's Block dates card and the API.
 *
 * Every block save runs under the blocked_dates named lock, then ONE transaction that first takes the room-type and
 * room row locks (the booking lock order), runs the clash check (includes/room-night-clash.php), saves only what the
 * check allows, writes the log and commits. Booked nights are skipped and listed; a room-type / All-rooms row on a
 * night with bookings blocks only its free rooms, one by one (owner answers 2026-11-23).
 */

require_once __DIR__ . '/room-night-clash.php';
require_once __DIR__ . '/block-log.php';
require_once __DIR__ . '/form-validation.php';

if (!function_exists('rh_block_save')) {

    /**
     * Individual-room ids a posted picker value stands for. "pair:<combination id>" is a joined-only pair (ONE room
     * to staff) and means BOTH of its rooms; a plain number (or a member of a joined-only pair) is resolved the same
     * way, so a member room is never blocked on its own.
     *
     * @return int[]
     */
    function rh_block_room_ids(string $raw): array
    {
        $raw = trim($raw);
        if (strpos($raw, 'pair:') === 0) {
            $pairs = rh_joined_only_pairs(true);
            $pair = $pairs[(int)substr($raw, 5)] ?? null;
            return $pair ? $pair['room_ids'] : [];
        }
        $id = (int)$raw;
        if ($id <= 0) {
            return [];
        }
        $map = rh_joined_only_pair_map();
        return isset($map[$id]) ? $map[$id]['room_ids'] : [$id];
    }

    /**
     * Nights (from $dates) on which this target already has its OWN block row: night => how many of its rooms hold
     * that row (a joined room has two; a room type / all-rooms row counts as 1).
     */
    function rh_block_grid_direct_nights(PDO $pdo, array $target, array $dates): array
    {
        if (!$dates) {
            return [];
        }
        $ph = implode(',', array_fill(0, count($dates), '?'));
        if ($target['scope'] === 'room') {
            $rph = implode(',', array_fill(0, count($target['room_ids']), '?'));
            $st = $pdo->prepare("SELECT block_date, COUNT(DISTINCT individual_room_id) FROM individual_room_blocked_dates WHERE individual_room_id IN ($rph) AND block_date IN ($ph) GROUP BY block_date");
            $st->execute(array_merge($target['room_ids'], $dates));
        } elseif ($target['scope'] === 'type') {
            $st = $pdo->prepare("SELECT block_date, 1 FROM blocked_dates WHERE room_id = ? AND block_date IN ($ph) GROUP BY block_date");
            $st->execute(array_merge([$target['id']], $dates));
        } else {
            $st = $pdo->prepare("SELECT block_date, 1 FROM blocked_dates WHERE room_id IS NULL AND block_date IN ($ph) GROUP BY block_date");
            $st->execute($dates);
        }
        $out = [];
        foreach ($st->fetchAll(PDO::FETCH_NUM) as [$d, $n]) {
            $out[substr((string)$d, 0, 10)] = (int)$n;
        }
        return $out;
    }

    /** Admin-format target (scope, id, room_ids, type_id, label, dates) -> clash-helper target. */
    function rh_block_clash_target(array $tg): array
    {
        return ['scope' => $tg['scope'], 'room_ids' => $tg['room_ids'] ?? [], 'type_id' => $tg['scope'] === 'type' ? (int)$tg['id'] : ($tg['type_id'] ?? null),
            'label' => (string)$tg['label'], 'nights' => $tg['dates'] ?? []];
    }

    /** Merge night lists keyed by label. */
    function rh_block_by_add(array &$by, string $label, array $nights): void
    {
        if (!$nights) {
            return;
        }
        $m = array_values(array_unique(array_merge($by[$label] ?? [], $nights)));
        sort($m);
        $by[$label] = $m;
    }

    /**
     * What a save of these targets WOULD do, read only (the confirm text before the rooms go off sale).
     * @return array{skipped:array,free:array,singles:array,singles_of:array,left_open:array,bookings:array}
     */
    function rh_block_preview(PDO $pdo, array $targets): array
    {
        $res = rh_room_nights_clash($pdo, array_map('rh_block_clash_target', $targets), ['advisory' => true, 'convert' => true]);
        return rh_block_clash_summary($targets, $res);
    }

    /** The clash result regrouped by label for messages. */
    function rh_block_clash_summary(array $targets, array $res): array
    {
        $sum = ['skipped' => [], 'free' => [], 'singles' => [], 'singles_of' => [], 'left_open' => [], 'bookings' => $res['bookings'] ?? []];
        foreach ($targets as $i => $tg) {
            $r = $res['targets'][$i] ?? ['free' => [], 'booked' => [], 'singles' => [], 'left_open' => []];
            rh_block_by_add($sum['skipped'], $tg['label'], $r['booked']);
            rh_block_by_add($sum['free'], $tg['label'], $r['free']);
            foreach ($r['singles'] as $n => $units) {
                rh_block_by_add($sum['singles_of'], $tg['label'], [$n]);
                foreach ($units as $u) {
                    rh_block_by_add($sum['singles'], $u['label'], [$n]);
                }
            }
            foreach ($r['left_open'] as $n => $labels) {
                foreach ($labels as $l) {
                    rh_block_by_add($sum['left_open'], $l, [$n]);
                }
            }
        }
        return $sum;
    }

    /** Number of nights in a [label => nights] map. */
    function rh_block_by_count(array $by): int
    {
        return (int)array_sum(array_map('count', $by));
    }

    /**
     * Sentence for the Block confirm (appended to "Block ...? Guests will not be able to book them.").
     * @return array{note:string,can_block:bool,skip_nights:int,free_nights:int,single_nights:int}
     */
    function rh_block_preview_note(array $sum): array
    {
        $skip = rh_block_by_count($sum['skipped']);
        $free = rh_block_by_count($sum['free']);
        $singleNights = rh_block_by_count($sum['singles_of']);
        $bookings = $sum['bookings'] ? ' (' . rh_room_nights_clash_text($sum['bookings'], 6) . ')' : '';
        if ($free === 0 && $singleNights === 0) {
            return ['note' => 'Every selected night is booked. Nothing to block' . ($skip ? ': ' . rh_block_where_text($sum['skipped']) . $bookings : '') . '.',
                'can_block' => false, 'skip_nights' => $skip, 'free_nights' => 0, 'single_nights' => 0];
        }
        $parts = [];
        if ($skip > 0) {
            $parts[] = $skip . ' booked night(s) will be skipped and stay open: ' . rh_block_where_text($sum['skipped']) . '.';
        }
        if ($singleNights > 0) {
            $parts[] = 'On ' . $singleNights . ' night(s) ' . implode(' and ', array_keys($sum['singles_of'])) . ' have bookings, so only the free rooms will be blocked one by one: '
                . rh_block_where_text($sum['singles']) . '.'
                . ($sum['left_open'] ? ' Left open: ' . rh_block_where_text($sum['left_open']) . '.' : '');
        }
        if ($sum['bookings']) {
            $parts[] = 'Bookings: ' . rh_room_nights_clash_text($sum['bookings'], 6) . '.';
        }
        $parts[] = $skip > 0 || $singleNights > 0 ? 'The other ' . $free . ' night(s) will be blocked.' : 'No bookings sleep on these nights.';
        return ['note' => implode(' ', $parts), 'can_block' => true, 'skip_nights' => $skip, 'free_nights' => $free, 'single_nights' => $singleNights];
    }

    /**
     * Write what a clash result allows (INSIDE the save transaction, after the locks and rh_room_nights_clash()).
     * Each target carries its own 'block_type' and 'reason'. $keepExisting (Extend): a room that already has its own
     * block row on a night keeps that row untouched (never rewritten); otherwise (Block) it gets the new type and reason.
     *
     * @return array{added:array,updated:array,singles:array,singles_of:array,log_added:array,log_updated:array}
     */
    function rh_block_write_free(PDO $pdo, array $targets, array $res, ?int $by, bool $keepExisting = false): array
    {
        $out = ['added' => [], 'updated' => [], 'singles' => [], 'singles_of' => [], 'log_added' => [], 'log_updated' => []];
        foreach ($targets as $i => $tg) {
            $label = $tg['label'];
            $blockType = rh_normalize_block_type($tg['block_type'] ?? 'manual');
            $reason = rh_normalize_block_reason($tg['reason'] ?? null);
            $r = $res['targets'][$i] ?? ['free' => [], 'singles' => []];
            $direct = rh_block_grid_direct_nights($pdo, $tg, $r['free']);
            $have = []; // room => night => true (Extend: rows that must stay as they are)
            if ($keepExisting && $tg['scope'] === 'room' && $r['free'] && $tg['room_ids']) {
                $rph = implode(',', array_fill(0, count($tg['room_ids']), '?'));
                $dph = implode(',', array_fill(0, count($r['free']), '?'));
                $st = $pdo->prepare("SELECT individual_room_id, block_date FROM individual_room_blocked_dates WHERE individual_room_id IN ($rph) AND block_date IN ($dph)");
                $st->execute(array_merge($tg['room_ids'], $r['free']));
                foreach ($st->fetchAll(PDO::FETCH_NUM) as [$rid, $d]) {
                    $have[(int)$rid][substr((string)$d, 0, 10)] = true;
                }
            }
            $added = [];
            $updated = [];
            foreach ($r['free'] as $d) {
                $full = ($direct[$d] ?? 0) >= max(1, count($tg['room_ids']));
                if ($keepExisting && $full) {
                    continue; // already blocked on this row: left exactly as it is
                }
                if ($tg['scope'] === 'room') {
                    foreach ($tg['room_ids'] as $rid) {
                        if (!empty($have[$rid][$d])) {
                            continue;
                        }
                        if (!blockIndividualRoomDate($rid, $d, $blockType, $reason, $by)) {
                            throw new RuntimeException('Could not block ' . $label . ' on ' . rh_block_nights_text([$d]) . '.');
                        }
                    }
                } elseif (!blockRoomDate($tg['scope'] === 'type' ? (int)$tg['id'] : null, $d, $blockType, $reason, $by)) {
                    throw new RuntimeException('Could not block ' . $label . ' on ' . rh_block_nights_text([$d]) . '.');
                }
                // "Updated" only when every room of the row was already blocked (half a joined room counts as new).
                if ($full) {
                    $updated[] = $d;
                } else {
                    $added[] = $d;
                }
            }
            rh_block_by_add($out['added'], $label, $added);
            rh_block_by_add($out['updated'], $label, $updated);
            $entry = ['label' => $label, 'scope' => $tg['scope'], 'type_id' => $tg['scope'] === 'type' ? (int)$tg['id'] : ($tg['type_id'] ?? null),
                'room_ids' => $tg['room_ids'], 'still' => []];
            $out['log_added'][] = $entry + ['dates' => $added];
            $out['log_updated'][] = $entry + ['dates' => $updated];
            // Type / All rooms on a night with bookings: the free rooms, one by one (rooms with no own block yet).
            $singleEntries = [];
            foreach ($r['singles'] ?? [] as $d => $units) {
                rh_block_by_add($out['singles_of'], $label, [$d]);
                foreach ($units as $u) {
                    foreach ($u['room_ids'] as $rid) {
                        if (!blockIndividualRoomDate($rid, $d, $blockType, $reason, $by)) {
                            throw new RuntimeException('Could not block ' . $u['label'] . ' on ' . rh_block_nights_text([$d]) . '.');
                        }
                    }
                    rh_block_by_add($out['singles'], $u['label'], [$d]);
                    $k = $u['label'];
                    $singleEntries[$k] = $singleEntries[$k] ?? ['label' => $k, 'scope' => 'room', 'type_id' => $u['type_id'], 'room_ids' => $u['room_ids'], 'still' => [], 'dates' => []];
                    $singleEntries[$k]['dates'][] = $d;
                }
            }
            foreach ($singleEntries as $e) {
                $out['log_added'][] = $e;
            }
        }
        return $out;
    }

    /**
     * Block the free nights of $targets (admin-format targets) in ONE transaction under the blocked_dates lock and the
     * room locks. $meta = log meta for rh_block_log_change (user_id, user_name, actor_type, via) + 'by' (created_by).
     * Blocking a night already blocked on the same row updates its type and reason (free nights only).
     *
     * @return array{ok:bool,message:string,added:array,updated:array,singles:array,singles_of:array,skipped:array,left_open:array,bookings:array,saved:int}
     */
    function rh_block_save(PDO $pdo, array $targets, string $blockType, ?string $reason, array $meta = []): array
    {
        $empty = ['added' => [], 'updated' => [], 'singles' => [], 'singles_of' => [], 'skipped' => [], 'left_open' => [], 'bookings' => [], 'saved' => 0];
        $blockType = rh_normalize_block_type($blockType);
        $reason = rh_normalize_block_reason($reason);
        $by = isset($meta['by']) && (int)$meta['by'] > 0 ? (int)$meta['by'] : null;
        try {
            return rh_with_create_lock($pdo, 'blocked_dates', static function () use ($pdo, $targets, $blockType, $reason, $meta, $by, $empty) {
                $plan = rh_room_nights_lock_plan($pdo, $targets); // plain reads: before BEGIN
                $out = $empty;
                $pdo->beginTransaction();
                try {
                    rh_room_nights_take_locks($pdo, $plan);
                    $res = rh_room_nights_clash($pdo, array_map('rh_block_clash_target', $targets), ['convert' => true]);
                    $sum = rh_block_clash_summary($targets, $res);
                    $out['skipped'] = $sum['skipped'];
                    $out['left_open'] = $sum['left_open'];
                    $out['bookings'] = $sum['bookings'];
                    foreach ($targets as $i => $tg) {
                        $targets[$i]['block_type'] = $blockType;
                        $targets[$i]['reason'] = $reason;
                    }
                    $w = rh_block_write_free($pdo, $targets, $res, $by, false);
                    foreach (['added', 'updated', 'singles', 'singles_of'] as $k) {
                        $out[$k] = $w[$k];
                    }
                    $logAdded = $w['log_added'];
                    $logUpdated = $w['log_updated'];
                    $out['saved'] = rh_block_by_count($out['added']) + rh_block_by_count($out['updated']) + rh_block_by_count($out['singles']);
                    if ($out['saved'] > 0) {
                        // Logged in the same transaction: a save that rolls back leaves no record.
                        rh_block_log_change($pdo, 'block', $logAdded, ['block_type' => $blockType, 'reason' => $reason] + $meta, $logUpdated);
                    }
                    $pdo->commit();
                } catch (Throwable $e) {
                    if ($pdo->inTransaction()) {
                        $pdo->rollBack();
                    }
                    if (rh_is_lock_conflict($e)) {
                        return ['ok' => false, 'message' => rh_room_nights_lock_message(), 'busy' => true] + $empty;
                    }
                    error_log('rh_block_save: ' . $e->getMessage());
                    return ['ok' => false, 'message' => ($e instanceof RuntimeException ? $e->getMessage() . ' ' : '') . 'Nothing was changed.'] + $empty;
                }
                $out['ok'] = $out['saved'] > 0;
                $out['message'] = '';
                return $out;
            });
        } catch (RuntimeException $e) {
            return ['ok' => false, 'message' => 'Someone else is saving blocks right now. Please try again in a moment. Nothing was changed.', 'busy' => true] + $empty;
        }
    }

    /** Staff wording of a rh_block_save() result. */
    function rh_block_save_message(array $res, int $pastSkipped = 0): string
    {
        if (!$res['ok'] && $res['message'] !== '' && empty($res['skipped'])) {
            return $res['message'];
        }
        $pastText = $pastSkipped > 0 ? ' ' . $pastSkipped . ' past night(s) in the selection were skipped (past nights cannot be changed).' : '';
        $added = rh_block_by_count($res['added']);
        $updated = rh_block_by_count($res['updated']);
        $singles = rh_block_by_count($res['singles']);
        $skipped = rh_block_by_count($res['skipped']);
        $bookings = $res['bookings'] ? rh_room_nights_clash_text($res['bookings']) : '';
        if ($res['saved'] === 0) {
            return 'Nothing was blocked: every selected night is booked (' . rh_block_where_text($res['skipped']) . ').'
                . ($bookings !== '' ? ' Booked by: ' . $bookings . '.' : '') . ' Move those bookings first, or block single free rooms.' . $pastText;
        }
        $msg = $added > 0 ? 'Blocked ' . $added . ' night(s): ' . rh_block_where_text($res['added']) . '.' : ($singles > 0 ? '' : 'No new nights were blocked.');
        $msg .= $pastText;
        if ($updated > 0) {
            $msg .= ' ' . $updated . ' night(s) were already blocked and now show the new block type and reason: ' . rh_block_where_text($res['updated']) . '.';
        }
        if ($singles > 0) {
            $rows = implode(' and ', array_keys($res['singles_of']));
            $msg .= ' ' . $rows . ' had bookings on ' . rh_block_nights_text(array_merge(...array_values($res['singles_of']))) . ', so only the free rooms were blocked one by one: '
                . rh_block_where_text($res['singles'], 12) . '.'
                . ($res['left_open'] ? ' Left open (booked, or kept for bookings with no room yet): ' . rh_block_where_text($res['left_open'], 12) . '.' : '')
                . ' Unblocking ' . $rows . ' later does not remove these single-room blocks.';
        }
        if ($skipped > 0) {
            $msg .= ' Skipped ' . $skipped . ' booked night(s): ' . rh_block_where_text($res['skipped']) . '.';
        }
        if ($bookings !== '') {
            $msg .= ' Booked by: ' . $bookings . '.' . ($skipped > 0 ? ' Move those bookings first, then block those nights.' : '');
        }
        return trim($msg);
    }

    /** API skipped[] list: one row per (night, row, booking). */
    function rh_block_api_skipped(array $res): array
    {
        $out = [];
        foreach (array_merge($res['skipped'] ?? [], $res['left_open'] ?? []) as $label => $nights) {
            foreach ($nights as $n) {
                $hit = false;
                foreach ($res['bookings'] ?? [] as $b) {
                    foreach ((array)($b['where'] ?? []) as $l => $ns) {
                        if (in_array($n, (array)$ns, true) && ($l === $label || isset(($res['skipped'] ?? [])[$l]))) {
                            $out[] = ['night' => $n, 'room' => $label, 'booking_reference' => $b['ref'], 'guest_name' => $b['guest']];
                            $hit = true;
                            break;
                        }
                    }
                }
                if (!$hit) {
                    $out[] = ['night' => $n, 'room' => $label, 'booking_reference' => null, 'guest_name' => null];
                }
            }
        }
        return $out;
    }

    /**
     * One API target: 'individual' (a joined room = both rooms) or room type / All rooms. Returns the admin-format
     * target or an error string.
     */
    function rh_block_api_target(PDO $pdo, string $scope, ?int $roomTypeId, ?int $individualRoomId, array $dates)
    {
        if ($scope === 'individual') {
            if (!$individualRoomId) {
                return 'individual_room_id is required for individual room blocks';
            }
            $ids = rh_block_room_ids((string)$individualRoomId);
            sort($ids);
            if (!$ids) {
                return 'Individual room not found';
            }
            $ph = implode(',', array_fill(0, count($ids), '?'));
            $st = $pdo->prepare("SELECT room_number, room_type_id FROM individual_rooms WHERE id IN ($ph) ORDER BY id");
            $st->execute($ids);
            $rows = $st->fetchAll(PDO::FETCH_ASSOC);
            if (!$rows) {
                return 'Individual room not found';
            }
            return ['scope' => 'room', 'id' => $ids[0], 'room_ids' => $ids, 'type_id' => (int)$rows[0]['room_type_id'],
                'label' => rh_block_room_text(array_column($rows, 'room_number')), 'dates' => $dates];
        }
        if ($roomTypeId === null) {
            return ['scope' => 'all', 'id' => null, 'room_ids' => [], 'type_id' => null, 'label' => 'All rooms', 'dates' => $dates];
        }
        return ['scope' => 'type', 'id' => $roomTypeId, 'room_ids' => [], 'type_id' => $roomTypeId, 'label' => rh_block_label($pdo, $roomTypeId), 'dates' => $dates];
    }

    /**
     * API POST body (api/blocked-dates.php): block the free nights, skip and list the booked ones.
     * @return array{code:int,body:array}
     */
    function rh_block_api_create(PDO $pdo, array $input, array $client): array
    {
        $scope = ($input['block_scope'] ?? 'type') === 'individual' ? 'individual' : 'type';
        $raw = isset($input['block_date']) ? [$input['block_date']] : (is_array($input['dates'] ?? null) ? $input['dates'] : []);
        $today = date('Y-m-d');
        $dates = [];
        $errors = [];
        foreach ($raw as $d) {
            $n = rh_normalize_block_date(substr(trim((string)$d), 0, 10));
            if ($n === null) {
                $errors[] = 'Invalid date format: ' . (is_scalar($d) ? (string)$d : '?');
            } elseif ($n < $today) {
                $errors[] = 'Date ' . $n . ' is in the past';
            } else {
                $dates[$n] = $n;
            }
        }
        ksort($dates);
        $dates = array_values(array_slice($dates, 0, 366));
        if (!$dates) {
            return ['code' => 422, 'body' => ['success' => false, 'message' => 'No valid dates provided', 'details' => $errors]];
        }
        $type = $input['block_type'] ?? 'manual';
        if (!in_array($type, ['maintenance', 'event', 'manual', 'full'], true)) {
            return ['code' => 422, 'body' => ['success' => false, 'message' => 'Validation failed', 'details' => ['block_type' => 'Invalid block type. Must be one of: maintenance, event, manual, full']]];
        }
        $roomTypeId = isset($input['room_id']) && $input['room_id'] !== '' && $input['room_id'] !== null ? (int)$input['room_id'] : null;
        $irId = isset($input['individual_room_id']) && $input['individual_room_id'] !== '' ? (int)$input['individual_room_id'] : null;
        if ($scope === 'type' && $roomTypeId !== null) {
            $st = $pdo->prepare('SELECT COUNT(*) FROM rooms WHERE id = ?');
            $st->execute([$roomTypeId]);
            if ((int)$st->fetchColumn() === 0) {
                return ['code' => 422, 'body' => ['success' => false, 'message' => 'Validation failed', 'details' => ['room_id' => 'Room not found']]];
            }
        }
        $target = rh_block_api_target($pdo, $scope, $roomTypeId, $irId, $dates);
        if (is_string($target)) {
            return ['code' => 422, 'body' => ['success' => false, 'message' => $target]];
        }
        $who = (string)($client['client_name'] ?? 'unknown client');
        $res = rh_block_save($pdo, [$target], (string)$type, isset($input['reason']) ? (string)$input['reason'] : null,
            ['by' => (int)($client['id'] ?? 0), 'user_id' => 0, 'user_name' => 'API: ' . $who, 'actor_type' => 'api', 'via' => 'the integration API (' . $who . ')']);
        $skipped = rh_block_api_skipped($res);
        if (!$res['ok']) {
            if (!empty($res['skipped'])) {
                return ['code' => 409, 'body' => ['success' => false, 'message' => 'Nothing was blocked: every requested night is booked. ' . rh_block_save_message($res), 'skipped' => $skipped, 'warnings' => []]];
            }
            return ['code' => !empty($res['busy']) ? 409 : 500, 'body' => ['success' => false, 'message' => $res['message'] !== '' ? $res['message'] : 'Failed to block dates']];
        }
        return ['code' => 201, 'body' => ['success' => true, 'message' => rh_block_save_message($res),
            'data' => ['blocked_count' => $res['saved'], 'total_requested' => count($raw), 'errors' => $errors],
            'skipped' => $skipped, 'warnings' => []]];
    }

    /**
     * API PUT (move a block to another night / type / reason): the new night must be free, otherwise 409 and the old
     * block is kept. Runs under the same locks as every block save.
     * @return array{code:int,body:array}
     */
    function rh_block_api_move(PDO $pdo, array $current, array $input, array $client): array
    {
        $scope = ($current['block_scope'] ?? 'type') === 'individual' && !empty($current['individual_room_id']) ? 'individual' : 'type';
        $oldDate = substr((string)$current['block_date'], 0, 10);
        $newDate = rh_normalize_block_date(substr((string)($input['block_date'] ?? $oldDate), 0, 10));
        if ($newDate === null) {
            return ['code' => 422, 'body' => ['success' => false, 'message' => 'Validation failed', 'details' => ['block_date' => 'Invalid date format']]];
        }
        $type = rh_normalize_block_type($input['block_type'] ?? $current['block_type']);
        $reason = rh_normalize_block_reason(isset($input['reason']) ? (string)$input['reason'] : ($current['reason'] ?? null));
        $oldTypeId = ($current['room_id'] ?? null) === null || $current['room_id'] === '' ? null : (int)$current['room_id'];
        $irId = $scope === 'individual' ? (int)$current['individual_room_id'] : null;
        $newTypeId = $scope === 'type' ? (isset($input['room_id']) && $input['room_id'] !== '' ? (int)$input['room_id'] : $oldTypeId) : null;
        $target = $scope === 'individual'
            ? ['scope' => 'room', 'id' => $irId, 'room_ids' => [$irId], 'type_id' => (int)$pdo->query('SELECT room_type_id FROM individual_rooms WHERE id = ' . (int)$irId)->fetchColumn(),
                'label' => rh_block_label($pdo, null, [$irId]), 'dates' => [$newDate]]
            : rh_block_api_target($pdo, 'type', $newTypeId, null, [$newDate]);
        $who = (string)($client['client_name'] ?? 'unknown client');
        $meta = ['user_id' => 0, 'user_name' => 'API: ' . $who, 'actor_type' => 'api', 'via' => 'the integration API (' . $who . ')'];
        try {
            return rh_with_create_lock($pdo, 'blocked_dates', static function () use ($pdo, $target, $scope, $oldDate, $newDate, $type, $reason, $irId, $oldTypeId, $newTypeId, $client, $meta) {
                $plan = rh_room_nights_lock_plan($pdo, [$target]);
                $pdo->beginTransaction();
                try {
                    rh_room_nights_take_locks($pdo, $plan);
                    $sameRow = $oldDate === $newDate && ($scope === 'individual' || $oldTypeId === $newTypeId);
                    if (!$sameRow) {
                        // Moving onto another night (or row): that night must be free. No single-room conversion here.
                        $res = rh_room_nights_clash($pdo, [rh_block_clash_target($target)]);
                        if (!empty($res['targets'][0]['booked'])) {
                            $pdo->rollBack();
                            return ['code' => 409, 'body' => ['success' => false, 'message' => $target['label'] . ' is booked on ' . rh_block_nights_text([$newDate]) . ' ('
                                . rh_room_nights_clash_text($res['bookings']) . '). The old block was kept.', 'skipped' => rh_block_api_skipped(['skipped' => [$target['label'] => [$newDate]], 'bookings' => $res['bookings']])]];
                        }
                    }
                    $by = (int)($client['id'] ?? 0) ?: null;
                    if ($scope === 'individual') {
                        $ok = unblockIndividualRoomDate($irId, $oldDate) && blockIndividualRoomDate($irId, $newDate, $type, $reason, $by);
                        $oldEntry = rh_block_entry($pdo, 'room', null, [$irId], [$oldDate]);
                        $newEntry = rh_block_entry($pdo, 'room', null, [$irId], [$newDate]);
                    } else {
                        $ok = unblockRoomDate($oldTypeId, $oldDate) && blockRoomDate($newTypeId, $newDate, $type, $reason, $by);
                        $oldEntry = rh_block_entry($pdo, $oldTypeId === null ? 'all' : 'type', $oldTypeId, [], [$oldDate]);
                        $newEntry = rh_block_entry($pdo, $newTypeId === null ? 'all' : 'type', $newTypeId, [], [$newDate]);
                    }
                    if (!$ok) {
                        throw new RuntimeException('block update failed');
                    }
                    rh_block_log_change($pdo, 'unblock', [$oldEntry], $meta);
                    rh_block_log_change($pdo, 'block', [$newEntry], ['block_type' => $type, 'reason' => $reason] + $meta);
                    $pdo->commit();
                } catch (Throwable $e) {
                    if ($pdo->inTransaction()) {
                        $pdo->rollBack();
                    }
                    error_log('api blocked-dates PUT: ' . $e->getMessage());
                    return ['code' => rh_is_lock_conflict($e) ? 409 : 500, 'body' => ['success' => false, 'message' => rh_is_lock_conflict($e) ? rh_room_nights_lock_message() : 'Failed to update blocked date; the old block was kept']];
                }
                $rows = $scope === 'individual' ? getIndividualRoomBlockedDates($irId, $newDate, $newDate) : getBlockedDates($newTypeId, $newDate, $newDate);
                return ['code' => 200, 'body' => ['success' => true, 'message' => 'Blocked date updated successfully', 'data' => $rows ? $rows[0] : null]];
            });
        } catch (RuntimeException $e) {
            return ['code' => 409, 'body' => ['success' => false, 'message' => 'Someone else is saving blocks right now. Please try again in a moment. The old block was kept.']];
        }
    }
    /* ------------------------------------------------------------------ Extend (GRID_UNIFY_PLAN Phase 3) */

    /** Most new nights one row may get in one Extend (ASSUMPTION 2026-11-23: 3 years). */
    if (!defined('RH_EXTEND_MAX_NIGHTS')) {
        define('RH_EXTEND_MAX_NIGHTS', 1096);
    }
    /** Most new nights one Extend action may save over all rows (ASSUMPTION 2026-11-23). */
    if (!defined('RH_EXTEND_MAX_ACTION')) {
        define('RH_EXTEND_MAX_ACTION', 10000);
    }

    /**
     * $date (Y-m-d) moved on by $n days / weeks / months / years. Months and years keep the day of the month and
     * clamp to the month's last day (31 Jan + 1 month = 28 or 29 Feb; 29 Feb + 1 year = 28 Feb). '' for bad input.
     */
    function rh_extend_add(string $date, int $n, string $unit): string
    {
        if (!preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $date, $m) || !checkdate((int)$m[2], (int)$m[3], (int)$m[1]) || $n < 0) {
            return '';
        }
        $y = (int)$m[1];
        $mo = (int)$m[2];
        $d = (int)$m[3];
        if ($unit === 'days' || $unit === 'weeks') {
            $dt = new DateTimeImmutable(sprintf('%04d-%02d-%02d', $y, $mo, $d), new DateTimeZone('UTC'));
            return $dt->modify('+' . ($unit === 'weeks' ? 7 * $n : $n) . ' days')->format('Y-m-d');
        }
        if ($unit !== 'months' && $unit !== 'years') {
            return '';
        }
        $total = ($y * 12 + ($mo - 1)) + ($unit === 'years' ? 12 * $n : $n);
        $ny = intdiv($total, 12);
        $nm = $total % 12 + 1;
        $last = (int)(new DateTimeImmutable(sprintf('%04d-%02d-01', $ny, $nm), new DateTimeZone('UTC')))->format('t');
        return sprintf('%04d-%02d-%02d', $ny, $nm, min($d, $last));
    }

    /**
     * How far an Extend goes, from the posted fields: mode 'by' (n + unit) or 'until' (a date). Returns the new
     * last date for a run ending on $last, or a plain error message starting with '!'.
     */
    function rh_extend_target_date(string $last, array $how): string
    {
        $mode = (string)($how['mode'] ?? 'by');
        if ($mode === 'until') {
            $u = rh_normalize_block_date(substr((string)($how['until'] ?? ''), 0, 10));
            return $u === null ? '!Choose the date to extend until.' : $u;
        }
        $n = (int)($how['n'] ?? 0);
        $unit = (string)($how['unit'] ?? 'days');
        if ($n < 1 || $n > 1100 || !in_array($unit, ['days', 'weeks', 'months', 'years'], true)) {
            return '!Enter how many days, weeks, months or years to extend by (1 or more).';
        }
        $to = rh_extend_add($last, $n, $unit);
        return $to === '' ? '!The date could not be read.' : $to;
    }

    /**
     * The own block a run ends on: ['block_type', 'reason'] when the row itself (not its room type or All rooms) is
     * blocked on $night (a joined room: both rooms), else null. Read inside the save transaction (after the locks).
     */
    function rh_block_own_source(PDO $pdo, array $tg, string $night): ?array
    {
        if ($tg['scope'] === 'room') {
            if (!$tg['room_ids']) {
                return null;
            }
            $ph = implode(',', array_fill(0, count($tg['room_ids']), '?'));
            $st = $pdo->prepare("SELECT individual_room_id, block_type, reason FROM individual_room_blocked_dates WHERE individual_room_id IN ($ph) AND block_date = ? ORDER BY individual_room_id");
            $st->execute(array_merge($tg['room_ids'], [$night]));
            $rows = $st->fetchAll(PDO::FETCH_ASSOC);
            $seen = [];
            foreach ($rows as $r) {
                $seen[(int)$r['individual_room_id']] = true;
            }
            return $rows && count($seen) >= count($tg['room_ids']) ? ['block_type' => (string)$rows[0]['block_type'], 'reason' => $rows[0]['reason']] : null;
        }
        if ($tg['scope'] === 'type') {
            $st = $pdo->prepare('SELECT block_type, reason FROM blocked_dates WHERE room_id = ? AND block_date = ? ORDER BY id LIMIT 1');
            $st->execute([(int)$tg['id'], $night]);
        } else {
            $st = $pdo->prepare('SELECT block_type, reason FROM blocked_dates WHERE room_id IS NULL AND block_date = ? ORDER BY id LIMIT 1');
            $st->execute([$night]);
        }
        $r = $st->fetch(PDO::FETCH_ASSOC);
        return $r ? ['block_type' => (string)$r['block_type'], 'reason' => $r['reason']] : null;
    }

    /** Row key of an admin-format target (a joined room is one row). */
    function rh_block_row_key(array $tg): string
    {
        if ($tg['scope'] === 'room') {
            $ids = $tg['room_ids'];
            sort($ids, SORT_NUMERIC);
            return 'room:' . implode('+', $ids);
        }
        return $tg['scope'] === 'type' ? 'type:' . (int)$tg['id'] : 'all';
    }

    /**
     * The last night of the row's own block run that $night belongs to (walks forward over back-to-back nights the
     * row itself blocks, at most RH_EXTEND_MAX_NIGHTS). So "extend" always starts after the whole run, even when only
     * part of it was selected.
     */
    function rh_block_own_run_end(PDO $pdo, array $tg, string $night): string
    {
        $days = rh_rnc_days(date('Y-m-d', strtotime($night . ' +1 day')), date('Y-m-d', strtotime($night . ' +' . RH_EXTEND_MAX_NIGHTS . ' days')), RH_EXTEND_MAX_NIGHTS);
        $direct = rh_block_grid_direct_nights($pdo, $tg, $days);
        $need = max(1, count($tg['room_ids']));
        $end = $night;
        foreach ($days as $d) {
            if (($direct[$d] ?? 0) < $need) {
                break;
            }
            $end = $d;
        }
        return $end;
    }

    /**
     * Extend block runs (Extend... and the drag handle). $items = [['target' => admin-format target (scope, id,
     * room_ids, type_id, label), 'last' => a night of the run (normally its last), 'how' => ['mode' => 'by', 'n', 'unit']
     * or ['mode' => 'until', 'until' => Y-m-d]]].
     * ONE transaction for the whole action under the blocked_dates lock and the room locks (Fable blocker 3):
     *  - every item's night must carry the row's OWN block (an inherited room-type / All-rooms block, or no block,
     *    refuses the whole action); two items for one row with a different type / reason refuse it too;
     *  - the new nights run from the night after the run's end to the new last night (months / years clamp to the
     *    month end); past nights are dropped; at most RH_EXTEND_MAX_NIGHTS per row and RH_EXTEND_MAX_ACTION in all;
     *  - new nights copy that row's block type and reason; a new night the row already blocks keeps its row exactly
     *    as it is (reported "already blocked", never rewritten);
     *  - the rest goes through the clash check: booked nights skipped and listed; a type / All-rooms night with
     *    bookings blocks the free rooms one by one;
     *  - one Staff activity row; any error rolls everything back.
     * $opt['preview'] = read only (advisory clash check, no locks, nothing saved).
     * $opt['before_commit'] = callable($pdo) run just before COMMIT (tests use it to force a failure).
     *
     * @return array{ok:bool,message:string,saved:int,added:array,already:array,singles:array,singles_of:array,skipped:array,left_open:array,bookings:array}
     */
    function rh_block_extend(PDO $pdo, array $items, array $meta = [], array $opt = []): array
    {
        $empty = ['added' => [], 'already' => [], 'singles' => [], 'singles_of' => [], 'skipped' => [], 'left_open' => [], 'bookings' => [], 'saved' => 0, 'new_nights' => 0];
        if (!$items) {
            return ['ok' => false, 'message' => 'Select a blocked night to extend.'] + $empty;
        }
        $by = isset($meta['by']) && (int)$meta['by'] > 0 ? (int)$meta['by'] : null;

        // The steps that read the rows: inside the save transaction (after the locks), or plain reads (preview).
        $build = static function () use ($pdo, $items): array {
            $today = date('Y-m-d');
            $rows = [];   // row key => merged target
            $total = 0;
            foreach ($items as $it) {
                $tg = $it['target'];
                $last = (string)$it['last'];
                $src = rh_block_own_source($pdo, $tg, $last);
                if ($src === null) {
                    throw new DomainException($tg['label'] . ' has no block of its own on ' . rh_block_nights_text([$last])
                        . ' (it is covered by a room-type or All rooms block, or it is not blocked). Extend that line instead. Nothing was changed.');
                }
                $k = rh_block_row_key($tg);
                $type = rh_normalize_block_type($src['block_type']);
                $reason = rh_normalize_block_reason($src['reason']);
                $kind = $type . '|' . (string)$reason;
                if (isset($rows[$k]) && $rows[$k]['kind'] !== $kind) {
                    throw new DomainException($tg['label'] . ' has two selected blocks with a different type or reason. Extend them one at a time. Nothing was changed.');
                }
                $end = rh_block_own_run_end($pdo, $tg, $last);
                $to = rh_extend_target_date($end, (array)($it['how'] ?? []));
                if ($to !== '' && $to[0] === '!') {
                    throw new DomainException(substr($to, 1));
                }
                $from = max(date('Y-m-d', strtotime($end . ' +1 day')), $today);
                $nights = $to >= $from ? rh_rnc_days($from, $to, RH_EXTEND_MAX_NIGHTS + 1) : [];
                if (count($nights) > RH_EXTEND_MAX_NIGHTS) {
                    throw new DomainException($tg['label'] . ': at most 3 years (' . RH_EXTEND_MAX_NIGHTS . ' nights) can be added in one go. Nothing was changed.');
                }
                if (!isset($rows[$k])) {
                    $rows[$k] = ['kind' => $kind, 'block_type' => $type, 'reason' => $reason] + $tg;
                    $rows[$k]['dates'] = [];
                }
                $before = count($rows[$k]['dates']);
                $rows[$k]['dates'] = array_values(array_unique(array_merge($rows[$k]['dates'], $nights)));
                sort($rows[$k]['dates']);
                $total += count($rows[$k]['dates']) - $before;
                if ($total > RH_EXTEND_MAX_ACTION) {
                    throw new DomainException('Too many nights at once (over ' . RH_EXTEND_MAX_ACTION . '). Extend fewer rows. Nothing was changed.');
                }
            }
            if ($total === 0) {
                throw new DomainException('There are no new nights to add: the new end must be after the last blocked night, today or later. Nothing was changed.');
            }
            // New nights the row already blocks on its own: left exactly as they are.
            $targets = [];
            $already = [];
            foreach ($rows as $tg) {
                $direct = rh_block_grid_direct_nights($pdo, $tg, $tg['dates']);
                $need = max(1, count($tg['room_ids']));
                $keep = [];
                foreach ($tg['dates'] as $d) {
                    if (($direct[$d] ?? 0) >= $need) {
                        rh_block_by_add($already, $tg['label'], [$d]);
                    } else {
                        $keep[] = $d;
                    }
                }
                if ($keep) {
                    $tg['dates'] = $keep;
                    $targets[] = $tg;
                }
            }
            return [$targets, $already, $total];
        };

        if (!empty($opt['preview'])) {
            try {
                [$targets, $already, $total] = $build();
            } catch (DomainException $e) {
                return ['ok' => false, 'message' => $e->getMessage(), 'refused' => true] + $empty;
            }
            $out = $empty;
            $out['already'] = $already;
            $out['new_nights'] = $total;
            $out['preview'] = true;
            if ($targets) {
                $sum = rh_block_clash_summary($targets, rh_room_nights_clash($pdo, array_map('rh_block_clash_target', $targets), ['advisory' => true, 'convert' => true]));
                foreach (['skipped', 'left_open', 'bookings', 'singles', 'singles_of'] as $k) {
                    $out[$k] = $sum[$k];
                }
                $out['added'] = $sum['free'];
                $out['saved'] = rh_block_by_count($sum['free']) + rh_block_by_count($sum['singles']);
            }
            $out['ok'] = $out['saved'] > 0;
            $out['message'] = rh_block_extend_message($out);
            return $out;
        }

        try {
            return rh_with_create_lock($pdo, 'blocked_dates', static function () use ($pdo, $items, $meta, $opt, $by, $empty, $build) {
                $plan = rh_room_nights_lock_plan($pdo, array_map(static fn($it) => $it['target'], $items)); // plain reads: before BEGIN
                $out = $empty;
                $out['new_nights'] = 0;
                $pdo->beginTransaction();
                try {
                    rh_room_nights_take_locks($pdo, $plan);
                    [$targets, $already, $total] = $build();
                    $out['new_nights'] = $total;
                    $out['already'] = $already;
                    if ($targets) {
                        $res = rh_room_nights_clash($pdo, array_map('rh_block_clash_target', $targets), ['convert' => true]);
                        $sum = rh_block_clash_summary($targets, $res);
                        $out['skipped'] = $sum['skipped'];
                        $out['left_open'] = $sum['left_open'];
                        $out['bookings'] = $sum['bookings'];
                        $w = rh_block_write_free($pdo, $targets, $res, $by, true);
                        foreach (['added', 'singles', 'singles_of'] as $k) {
                            $out[$k] = $w[$k];
                        }
                        $out['saved'] = rh_block_by_count($out['added']) + rh_block_by_count($out['singles']);
                        if ($out['saved'] > 0) {
                            $kinds = [];
                            foreach ($targets as $tg) {
                                $kinds[$tg['kind']] = rh_block_type_label($tg['block_type']) . ($tg['reason'] !== null && $tg['reason'] !== '' ? '; reason: ' . $tg['reason'] : '');
                            }
                            // ONE Staff activity row for the whole action, written in the same transaction.
                            rh_block_log_change($pdo, 'block', $w['log_added'], ['block_type' => $targets[0]['block_type'], 'reason' => $targets[0]['reason'],
                                'note' => 'Extended (type and reason copied from the last blocked night)', 'kinds' => array_values($kinds)] + $meta);
                        }
                    }
                    if (isset($opt['before_commit']) && is_callable($opt['before_commit'])) {
                        ($opt['before_commit'])($pdo);
                    }
                    $pdo->commit();
                } catch (Throwable $e) {
                    if ($pdo->inTransaction()) {
                        $pdo->rollBack();
                    }
                    if ($e instanceof DomainException) {
                        return ['ok' => false, 'message' => $e->getMessage(), 'refused' => true] + $empty;
                    }
                    if (rh_is_lock_conflict($e)) {
                        return ['ok' => false, 'message' => rh_room_nights_lock_message(), 'busy' => true] + $empty;
                    }
                    error_log('rh_block_extend: ' . $e->getMessage());
                    return ['ok' => false, 'message' => ($e instanceof RuntimeException ? $e->getMessage() . ' ' : '') . 'Nothing was changed.'] + $empty;
                }
                $out['ok'] = $out['saved'] > 0;
                $out['message'] = rh_block_extend_message($out);
                return $out;
            });
        } catch (RuntimeException $e) {
            return ['ok' => false, 'message' => 'Someone else is saving blocks right now. Please try again in a moment. Nothing was changed.', 'busy' => true] + $empty;
        }
    }

    /** Staff wording of a rh_block_extend() result (a preview says "will"). */
    function rh_block_extend_message(array $res): string
    {
        $pv = !empty($res['preview']);
        $added = rh_block_by_count($res['added']);
        $singles = rh_block_by_count($res['singles']);
        $already = rh_block_by_count($res['already']);
        $skipped = rh_block_by_count($res['skipped']);
        $bookings = $res['bookings'] ? rh_room_nights_clash_text($res['bookings']) : '';
        $parts = [];
        if ((int)$res['saved'] === 0) {
            $parts[] = $pv ? 'Nothing new would be blocked.' : 'Nothing was extended.';
        }
        if ($added > 0) {
            $parts[] = ($pv ? 'Will block ' : 'Blocked ') . $added . ' more night(s): ' . rh_block_where_text($res['added']) . '.';
        }
        if ($singles > 0) {
            $rows = implode(' and ', array_keys($res['singles_of']));
            $parts[] = $rows . ' had bookings on ' . rh_block_nights_text(array_merge(...array_values($res['singles_of']))) . ', so only the free rooms '
                . ($pv ? 'will be' : 'were') . ' blocked one by one: ' . rh_block_where_text($res['singles'], 12) . '.'
                . ($res['left_open'] ? ' Left open: ' . rh_block_where_text($res['left_open'], 12) . '.' : '');
        }
        if ($already > 0) {
            $parts[] = $already . ' night(s) were already blocked and ' . ($pv ? 'stay' : 'were left') . ' as they are: ' . rh_block_where_text($res['already']) . '.';
        }
        if ($skipped > 0) {
            $parts[] = ($pv ? 'Will skip ' : 'Skipped ') . $skipped . ' booked night(s): ' . rh_block_where_text($res['skipped']) . '.';
        }
        if ($bookings !== '') {
            $parts[] = 'Booked by: ' . $bookings . '. Move those bookings first, then block those nights.';
        }
        return implode(' ', $parts);
    }
}
