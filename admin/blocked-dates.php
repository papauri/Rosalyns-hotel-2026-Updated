<?php

/**
 * Blocked Dates Management Page
 * Hotel Website - Admin Panel
 *
 * Allows administrators to block/unblock room dates at two levels:
 * 1. Room Type Level (blocks all rooms of a type)
 * 2. Individual Room Level (blocks specific individual rooms)
 */

// Include admin initialization (PHP-only, no HTML output)
require_once 'admin-init.php';
require_once __DIR__ . '/../includes/form-validation.php';
/** @var string $csrf_token */

require_once '../includes/modal.php';
require_once '../includes/alert.php';
require_once __DIR__ . '/includes/bulk-actions.php';
require_once __DIR__ . '/../includes/block-log.php'; // wording + Staff activity / Booking History records for blocks

// Handle form submissions
$message = '';
$messageType = '';
$isAjax = !empty($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest';
// Blocking / unblocking dates takes rooms off sale: it needs the Block Rooms permission (viewing does not).
$canBlockRooms = rhCanBlockRooms((int)($user['id'] ?? 0));

/**
 * Keep only valid, not-in-the-past blocked nights. A block is one NIGHT: blocking the 5th
 * stops a stay that sleeps on the night of the 5th; a guest checking out on the 5th is fine.
 */
function rh_clean_block_dates($dates): array
{
    $today = date('Y-m-d');
    $out = [];
    foreach ((array)$dates as $d) {
        $n = rh_normalize_block_date((string)$d);
        if ($n !== null && $n >= $today) {
            $out[$n] = $n;
        }
    }
    ksort($out);
    return array_values(array_slice($out, 0, 366));
}

/** Error text if the posted room type id (null = all rooms) does not exist, else null. */
function rh_block_check_room_type(PDO $pdo, ?int $roomId): ?string
{
    if ($roomId === null) {
        return null;
    }
    $st = $pdo->prepare('SELECT COUNT(*) FROM rooms WHERE id = ?');
    $st->execute([$roomId]);
    return (int)$st->fetchColumn() > 0 ? null : 'The selected room type does not exist.';
}

/** Error text if the individual room does not exist or is switched off, else null. */
function rh_block_check_individual_room(PDO $pdo, int $roomId): ?string
{
    $st = $pdo->prepare('SELECT is_active FROM individual_rooms WHERE id = ?');
    $st->execute([$roomId]);
    $active = $st->fetchColumn();
    if ($active === false) {
        return 'The selected room does not exist.';
    }
    return (int)$active === 1 ? null : 'The selected room is switched off (inactive), so it cannot be blocked.';
}

/** The valid "YYYY-MM" month the calendar shows ($raw), or the current month. */
function rh_block_grid_month($raw): string
{
    $raw = is_string($raw) ? trim($raw) : '';
    if (preg_match('/^\d{4}-\d{2}$/', $raw)) {
        $d = DateTime::createFromFormat('!Y-m-d', $raw . '-01');
        if ($d && $d->format('Y-m') === $raw && (int)$d->format('Y') >= 2000 && (int)$d->format('Y') <= 2100) {
            return $raw;
        }
    }
    return date('Y-m');
}

/**
 * Calendar cells posted by the grid -> targets. Each item is {t: "all"|"type"|"room", id, d: [nights]}.
 * "room" is an individual room id; a member of a joined room (pair) stands for BOTH of its rooms.
 * Past or invalid nights are dropped. Returns a list of targets, or an error string.
 *
 * @return array<int,array{scope:string,id:?int,room_ids:int[],type_id:?int,label:string,dates:string[]}>|string
 */
function rh_block_grid_targets(PDO $pdo, $rawJson, int &$pastSkipped = 0)
{
    $pastSkipped = 0;
    $items = is_string($rawJson) ? json_decode($rawJson, true) : null;
    if (!is_array($items) || !$items) {
        return 'Select at least one night on the calendar.';
    }
    if (count($items) > 300) {
        return 'Too many rooms at once. Select fewer rooms and try again.';
    }
    $targets = [];
    $nights = 0;
    $pastSeen = []; // row key => past night => true (a night posted twice for one row counts once)
    $today = date('Y-m-d');
    foreach ($items as $it) {
        if (!is_array($it)) {
            return 'The selection could not be read. Reload the page and try again.';
        }
        $t = (string)($it['t'] ?? '');
        $id = isset($it['id']) && $it['id'] !== null && $it['id'] !== '' ? (int)$it['id'] : null;
        $rawDates = is_array($it['d'] ?? null) ? $it['d'] : [];
        $dates = rh_clean_block_dates($rawDates);
        // Valid nights before today are dropped (never saved); count them once per row so the message can say so.
        // A joined room is one row whichever of its rooms was posted.
        if ($t === 'all') {
            $rowKey = 'all';
        } elseif ($t === 'type' && $id !== null && $id > 0) {
            $rowKey = 'type:' . $id;
        } elseif ($t === 'room' && $id !== null && $id > 0) {
            $keyIds = rh_block_room_ids((string)$id);
            sort($keyIds);
            $rowKey = 'room:' . ($keyIds ? implode('+', $keyIds) : $id);
        } else {
            return 'The selection could not be read. Reload the page and try again.';
        }
        foreach ($rawDates as $x) {
            $n = is_string($x) ? rh_normalize_block_date($x) : null;
            if ($n !== null && $n < $today) {
                $pastSeen[$rowKey][$n] = true;
            }
        }
        if (!$dates) {
            continue;
        }
        if ($t === 'all') {
            $key = 'all';
            $target = ['scope' => 'all', 'id' => null, 'room_ids' => [], 'type_id' => null, 'label' => 'All rooms'];
        } elseif ($t === 'type' && $id !== null && $id > 0) {
            $st = $pdo->prepare('SELECT name FROM rooms WHERE id = ?');
            $st->execute([$id]);
            $name = $st->fetchColumn();
            if ($name === false) {
                return 'A selected room type no longer exists. Reload the page.';
            }
            $key = 'type:' . $id;
            $target = ['scope' => 'type', 'id' => $id, 'room_ids' => [], 'type_id' => $id, 'label' => 'All ' . $name . ' rooms'];
        } elseif ($t === 'room' && $id !== null && $id > 0) {
            if (($err = rh_block_check_individual_room($pdo, $id)) !== null) {
                return $err . ' Reload the page.';
            }
            $roomIds = rh_block_room_ids((string)$id);
            sort($roomIds);
            if (!$roomIds) {
                return 'A selected room could not be found. Reload the page.';
            }
            foreach ($roomIds as $rid) {
                if (($err = rh_block_check_individual_room($pdo, $rid)) !== null) {
                    return 'Both rooms of a joined room must be active to block it. ' . $err;
                }
            }
            $ph = implode(',', array_fill(0, count($roomIds), '?'));
            $st = $pdo->prepare("SELECT room_number, room_type_id FROM individual_rooms WHERE id IN ($ph) ORDER BY id");
            $st->execute($roomIds);
            $rows = $st->fetchAll(PDO::FETCH_ASSOC);
            $key = 'room:' . implode('+', $roomIds); // a pair is one target whichever member was posted
            $target = ['scope' => 'room', 'id' => $roomIds[0], 'room_ids' => $roomIds, 'type_id' => (int)$rows[0]['room_type_id'],
                'label' => rh_block_room_text(array_column($rows, 'room_number'))];
        } else {
            return 'The selection could not be read. Reload the page and try again.';
        }
        $prev = $targets[$key]['dates'] ?? [];
        $target['dates'] = rh_clean_block_dates(array_merge($prev, $dates));
        $nights += count($target['dates']) - count($prev);
        $targets[$key] = $target;
        if ($nights > 5000) {
            return 'Too many nights at once (over 5000). Select fewer and try again.';
        }
    }
    foreach ($pastSeen as $seen) {
        $pastSkipped += count($seen);
    }
    if (!$targets) {
        return $pastSkipped > 0 ? 'Past nights cannot be blocked or unblocked. Select nights from today onwards.' : 'Select at least one night on the calendar.';
    }
    return array_values($targets);
}

/**
 * Nights (from $dates) on which this target already has its OWN block row: night => how many of its
 * rooms hold that row (a joined room has two; a room type / all-rooms row counts as 1).
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

/** Nights (from $dates) on which a wider block covers this target: its room type's block or an all-rooms block. */
function rh_block_grid_inherited_nights(PDO $pdo, array $target, array $dates): array
{
    if (!$dates || $target['scope'] === 'all') {
        return [];
    }
    $ph = implode(',', array_fill(0, count($dates), '?'));
    if ($target['scope'] === 'type') {
        // A room-type row's own block is not "wider": only the all-rooms block covers it.
        $st = $pdo->prepare("SELECT DISTINCT block_date FROM blocked_dates WHERE room_id IS NULL AND block_date IN ($ph)");
        $st->execute($dates);
    } else {
        $st = $pdo->prepare("SELECT DISTINCT block_date FROM blocked_dates WHERE (room_id IS NULL OR room_id = ?) AND block_date IN ($ph)");
        $st->execute(array_merge([$target['type_id']], $dates));
    }
    $out = [];
    foreach ($st->fetchAll(PDO::FETCH_COLUMN) as $d) {
        $out[substr((string)$d, 0, 10)] = true;
    }
    return $out;
}

/**
 * Block or unblock the calendar's selection in ONE transaction under the blocked_dates lock: every night
 * is saved, or nothing is. Blocking a night that is already blocked on the same row updates its type and
 * reason. Unblocking only removes a row's OWN block: a night covered by its room type's (or the all-rooms)
 * block stays blocked and is reported, because a room cannot be let out of a wider block on its own.
 *
 * @return array{ok:bool,message:string}
 */
function rh_block_grid_apply(PDO $pdo, string $op, array $targets, string $blockType, ?string $reason, ?int $by, int $pastSkipped = 0, string $byName = ''): array
{
    $result = rh_block_locked($pdo, function () use ($pdo, $op, $targets, $blockType, $reason, $by, $pastSkipped, $byName) {
        $noteAffected = [];
        $addedBy = [];   // row label ("Room 101", "All Deluxe rooms", "All rooms") => nights
        $updatedBy = [];
        $removedBy = [];
        $stillBy = [];
        $notBy = [];
        $count = static function (array $by): int {
            return (int)array_sum(array_map('count', $by));
        };
        $pdo->beginTransaction();
        try {
            foreach ($targets as $tg) {
                $label = $tg['label'];
                $direct = rh_block_grid_direct_nights($pdo, $tg, $tg['dates']);
                if ($op === 'block') {
                    foreach ($tg['dates'] as $d) {
                        if ($tg['scope'] === 'room') {
                            foreach ($tg['room_ids'] as $rid) {
                                if (!blockIndividualRoomDate($rid, $d, $blockType, $reason, $by)) {
                                    throw new RuntimeException('Could not block ' . $label . ' on ' . rh_block_nights_text([$d]) . '.');
                                }
                            }
                        } elseif (!blockRoomDate($tg['scope'] === 'type' ? $tg['id'] : null, $d, $blockType, $reason, $by)) {
                            throw new RuntimeException('Could not block ' . $label . ' on ' . rh_block_nights_text([$d]) . '.');
                        }
                        // "Updated" only when every room of the row was already blocked (half a joined room counts as new).
                        if (($direct[$d] ?? 0) >= max(1, count($tg['room_ids']))) {
                            $updatedBy[$label][] = $d;
                        } else {
                            $addedBy[$label][] = $d;
                        }
                    }
                } else {
                    $inherited = rh_block_grid_inherited_nights($pdo, $tg, $tg['dates']);
                    foreach ($tg['dates'] as $d) {
                        if (isset($direct[$d])) {
                            if ($tg['scope'] === 'room') {
                                foreach ($tg['room_ids'] as $rid) {
                                    if (!unblockIndividualRoomDate($rid, $d)) {
                                        throw new RuntimeException('Could not unblock ' . $label . ' on ' . rh_block_nights_text([$d]) . '.');
                                    }
                                }
                            } elseif (!unblockRoomDate($tg['scope'] === 'type' ? $tg['id'] : null, $d)) {
                                throw new RuntimeException('Could not unblock ' . $label . ' on ' . rh_block_nights_text([$d]) . '.');
                            }
                            $removedBy[$label][] = $d;
                            if (isset($inherited[$d])) {
                                $stillBy[$label][] = $d;
                            }
                        } elseif (isset($inherited[$d])) {
                            $stillBy[$label][] = $d;
                        } else {
                            $notBy[$label][] = $d;
                        }
                    }
                }
            }
            // Record the change (Staff activity + a note on every booking sleeping on those nights) in the same
            // transaction, so a save that rolls back leaves no record of a change that never happened.
            $entryOf = static function (array $tg, array $dates, array $still = []): array {
                return ['label' => $tg['label'], 'scope' => $tg['scope'], 'type_id' => $tg['scope'] === 'type' ? $tg['id'] : $tg['type_id'],
                    'room_ids' => $tg['room_ids'], 'dates' => $dates, 'still' => $still];
            };
            $logEntries = [];
            $logUpdated = [];
            foreach ($targets as $tg) {
                $l = $tg['label'];
                if ($op === 'block') {
                    $logEntries[] = $entryOf($tg, $addedBy[$l] ?? []);
                    $logUpdated[] = $entryOf($tg, $updatedBy[$l] ?? []);
                } else {
                    $logEntries[] = $entryOf($tg, $removedBy[$l] ?? [], array_values(array_intersect($stillBy[$l] ?? [], $removedBy[$l] ?? [])));
                }
            }
            rh_block_log_change($pdo, $op, $logEntries, ['block_type' => $blockType, 'reason' => $reason, 'user_id' => (int)$by, 'user_name' => $byName], $logUpdated);
            if ($op === 'block') {
                $noteAffected = rh_block_affected_bookings(array_merge($logEntries, $logUpdated));
            }
            $pdo->commit();
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            error_log('blocked-dates grid_apply: ' . $e->getMessage());
            return ['ok' => false, 'message' => ($e instanceof RuntimeException ? $e->getMessage() . ' ' : '') . 'Nothing was changed.'];
        }

        $pastText = $pastSkipped > 0 ? ' ' . $pastSkipped . ' past night(s) in the selection were skipped (past nights cannot be changed).' : '';

        if ($op === 'block') {
            $added = $count($addedBy);
            $updated = $count($updatedBy);
            $msg = $added > 0 ? 'Blocked ' . $added . ' night(s): ' . rh_block_where_text($addedBy) . '.' : 'No new nights were blocked.';
            $msg .= $pastText;
            if ($updated > 0) {
                $msg .= ' ' . $updated . ' night(s) were already blocked and now show the new block type and reason: ' . rh_block_where_text($updatedBy) . '.';
            }
            // Heads-up: bookings that already sleep on the blocked nights (blocks never move them).
            if ($noteAffected) {
                $msg .= ' Note: ' . count($noteAffected) . ' booking(s) already sleep on these nights and were not moved: '
                    . rh_block_bookings_text($noteAffected) . '. Reassign the room or contact the guest; each booking\'s History has a note.';
            }
            return ['ok' => true, 'message' => $msg];
        }

        $removed = $count($removedBy);
        $stillBlocked = $count($stillBy);
        $notBlocked = $count($notBy);
        if ($removed === 0 && $stillBlocked === 0) {
            return ['ok' => false, 'message' => 'None of the selected nights were blocked, so nothing was changed ('
                . rh_block_where_text($notBy) . ').' . $pastText];
        }
        $msg = $removed > 0 ? 'Unblocked ' . $removed . ' night(s): ' . rh_block_where_text($removedBy) . '.' : 'Nothing was unblocked.';
        $msg .= $pastText;
        if ($stillBlocked > 0) {
            $msg .= ' ' . $stillBlocked . ' selected night(s) are still blocked by a room-type or all-rooms block: ' . rh_block_where_text($stillBy)
                . '. Unblock those on the "All ... rooms" or "All rooms" line.';
        }
        if ($notBlocked > 0) {
            $msg .= ' ' . $notBlocked . ' selected night(s) were not blocked: ' . rh_block_where_text($notBy) . '.';
        }
        return ['ok' => $removed > 0, 'message' => $msg];
    });
    return is_array($result) ? $result : ['ok' => false, 'message' => 'Someone else is saving blocks right now. Please try again in a moment. Nothing was changed.'];
}

/**
 * Individual-room ids a posted picker value stands for. "pair:<combination id>" is a joined-only
 * pair (ONE room to staff) and means BOTH of its rooms; a plain number (or a member of a
 * joined-only pair) is resolved the same way, so a member room is never blocked on its own.
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

/** Unblock one night of a room; a member of a joined room is unblocked together with its partner. */
function rh_unblock_room_or_pair(int $roomId, string $blockDate): bool
{
    $ok = true;
    foreach (rh_block_room_ids((string)$roomId) as $rid) {
        $ok = unblockIndividualRoomDate($rid, $blockDate) && $ok;
    }
    return $ok;
}

/** Run a block write under a lock so two simultaneous saves cannot both insert the same night. */
function rh_block_locked(PDO $pdo, callable $fn)
{
    try {
        return rh_with_create_lock($pdo, 'blocked_dates', $fn);
    } catch (RuntimeException $e) {
        return false;
    }
}

/** Heads-up appended to a success message when existing bookings sleep on the blocked nights. */
function rh_block_overlap_note(?int $roomTypeId, ?int $individualRoomId, array $dates): string
{
    $rows = getBookingsOverlappingBlockDates($roomTypeId, $individualRoomId, $dates);
    if (empty($rows)) {
        return '';
    }
    $refs = array_map(function ($b) {
        return $b['booking_reference'];
    }, array_slice($rows, 0, 5));
    return ' Note: ' . count($rows) . ' existing booking(s) already sleep on these nights (' . implode(', ', $refs)
        . (count($rows) > 5 ? ', ...' : '') . '). Blocks do not cancel or move bookings; reassign or contact those guests.';
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!validateCsrfToken($_POST['csrf_token'] ?? '')) {
        if ($isAjax) {
            header('Content-Type: application/json');
            echo json_encode(['success' => false, 'message' => 'Security token invalid.']);
            exit;
        }
        header('Location: ' . basename($_SERVER['PHP_SELF']));
        exit;
    }
    $action = $_POST['action'] ?? '';
    // Who is changing blocks, for Staff activity and Booking History.
    $blockActorName = trim((string)($user['full_name'] ?? '')) !== '' ? trim((string)$user['full_name']) : (string)($user['username'] ?? '');
    $blockLogMeta = static function (?string $type, ?string $reason) use ($user, $blockActorName): array {
        return ['block_type' => rh_normalize_block_type($type), 'reason' => rh_normalize_block_reason($reason),
            'user_id' => (int)($user['id'] ?? 0), 'user_name' => $blockActorName];
    };

    if (!$canBlockRooms && in_array($action, ['block_date', 'block_multiple', 'unblock_date', 'bulk_unblock', 'grid_apply', 'preview_block'], true)) {
        // Server-side refusal: the buttons are disabled for this user, but never trust the page.
        $message = rhBlockRoomsDeniedMessage();
        $messageType = 'error';
        if ($action === 'grid_apply' || $action === 'preview_block') {
            header('Content-Type: application/json; charset=utf-8');
            echo json_encode(['success' => false, 'message' => $message]);
            exit;
        }
    } elseif ($action === 'preview_block') {
        // Read only (nothing is saved): the bookings that already sleep on the selected nights, shown in the
        // Block confirm so staff decide BEFORE the rooms go off sale.
        $previewPast = 0;
        $targets = rh_block_grid_targets($pdo, $_POST['items'] ?? '', $previewPast);
        header('Content-Type: application/json; charset=utf-8');
        if (is_string($targets)) {
            echo json_encode(['success' => false, 'message' => $targets]);
            exit;
        }
        $entries = array_map(static fn($tg) => ['label' => $tg['label'], 'scope' => $tg['scope'], 'type_id' => $tg['scope'] === 'type' ? $tg['id'] : $tg['type_id'],
            'room_ids' => $tg['room_ids'], 'dates' => $tg['dates'], 'still' => []], $targets);
        $affected = rh_block_affected_bookings($entries);
        $byLabel = [];
        foreach ($targets as $tg) {
            $byLabel[$tg['label']] = $tg['dates'];
        }
        echo json_encode(['success' => true, 'where' => rh_block_where_text($byLabel), 'past_skipped' => $previewPast,
            'booking_count' => count($affected), 'bookings' => $affected ? rh_block_bookings_text($affected, 8) : '']);
        exit;
    } elseif ($action === 'grid_apply') {
        // Calendar grid: block or unblock any mix of rooms, room types and the all-rooms row in one save.
        $gridOp = (string)($_POST['op'] ?? '');
        $gridPast = 0;
        $targets = in_array($gridOp, ['block', 'unblock'], true)
            ? rh_block_grid_targets($pdo, $_POST['items'] ?? '', $gridPast)
            : 'Unknown action.';
        $out = is_string($targets)
            ? ['ok' => false, 'message' => $targets]
            : rh_block_grid_apply($pdo, $gridOp, $targets, rh_normalize_block_type($_POST['block_type'] ?? 'manual'),
                rh_normalize_block_reason(isset($_POST['reason']) ? (string)$_POST['reason'] : null), isset($user['id']) ? (int)$user['id'] : null, $gridPast, $blockActorName);
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(['success' => $out['ok'], 'message' => $out['message']]);
        exit;
    } elseif ($action === 'block_date') {
        $block_scope = $_POST['block_scope'] ?? 'type'; // 'type' or 'individual'

        if ($block_scope === 'individual') {
            // Individual room blocking
            // A joined room (pair) blocks both of its rooms together.
            $block_room_ids = rh_block_room_ids((string)($_POST['individual_room_id'] ?? ''));
            $individual_room_id = $block_room_ids[0] ?? null;
            $block_date = $_POST['block_date'] ?? '';
            $block_type = $_POST['block_type'] ?? 'manual';
            $reason = $_POST['reason'] ?? null;
            $created_by = $user['id'] ?? null;

            $clean_dates = rh_clean_block_dates([$block_date]);
            if (empty($individual_room_id) || empty($block_date)) {
                $message = 'Please select a room and date to block';
                $messageType = 'error';
            } elseif (empty($clean_dates)) {
                $message = 'Please choose a valid date that is today or later';
                $messageType = 'error';
            } elseif (($chkErr = rh_block_check_individual_room($pdo, (int)$individual_room_id)) !== null) {
                $message = $chkErr;
                $messageType = 'error';
            } else {
                $result = rh_block_locked($pdo, function () use ($pdo, $block_room_ids, $clean_dates, $block_type, $reason, $created_by) {
                    // A joined room is blocked on both its rooms or on neither.
                    $pdo->beginTransaction();
                    $okAll = true;
                    foreach ($block_room_ids as $blockRoomId) {
                        $okAll = blockIndividualRoomDate($blockRoomId, $clean_dates[0], $block_type, $reason, $created_by) && $okAll;
                    }
                    $okAll ? $pdo->commit() : $pdo->rollBack();
                    return $okAll;
                });

                if ($result) {
                    rh_block_log_change($pdo, 'block', [rh_block_entry($pdo, 'room', null, $block_room_ids, $clean_dates)], $blockLogMeta($block_type, $reason));
                    $message = rh_block_label($pdo, null, $block_room_ids) . ' blocked on ' . rh_block_nights_text($clean_dates)
                        . (count($block_room_ids) > 1 ? ' (both rooms of the joined room).' : '.') . rh_block_overlap_note(null, $individual_room_id, $clean_dates);
                    $messageType = 'success';
                } else {
                    $message = 'Failed to block date';
                    $messageType = 'error';
                }
                if ($isAjax) {
                    header('Content-Type: application/json; charset=utf-8');
                    echo json_encode(['success' => $messageType === 'success', 'message' => $message]);
                    exit;
                }
            }
        } else {
            // Room type blocking
            $room_id = !empty($_POST['room_id']) ? (int)$_POST['room_id'] : null;
            $block_date = $_POST['block_date'] ?? '';
            $block_type = $_POST['block_type'] ?? 'manual';
            $reason = $_POST['reason'] ?? null;
            $created_by = $user['id'] ?? null;

            $clean_dates = rh_clean_block_dates([$block_date]);
            if (empty($block_date)) {
                $message = 'Please select a date to block';
                $messageType = 'error';
            } elseif (empty($clean_dates)) {
                $message = 'Please choose a valid date that is today or later';
                $messageType = 'error';
            } elseif (($chkErr = rh_block_check_room_type($pdo, $room_id)) !== null) {
                $message = $chkErr;
                $messageType = 'error';
            } else {
                $result = rh_block_locked($pdo, function () use ($room_id, $clean_dates, $block_type, $reason, $created_by) {
                    return blockRoomDate($room_id, $clean_dates[0], $block_type, $reason, $created_by);
                });

                if ($result) {
                    rh_block_log_change($pdo, 'block', [rh_block_entry($pdo, $room_id === null ? 'all' : 'type', $room_id, [], $clean_dates)], $blockLogMeta($block_type, $reason));
                    $message = rh_block_label($pdo, $room_id) . ' blocked on ' . rh_block_nights_text($clean_dates) . '.' . rh_block_overlap_note($room_id, null, $clean_dates);
                    $messageType = 'success';
                } else {
                    $message = 'Failed to block date';
                    $messageType = 'error';
                }
                if ($isAjax) {
                    header('Content-Type: application/json; charset=utf-8');
                    echo json_encode(['success' => $messageType === 'success', 'message' => $message]);
                    exit;
                }
            }
        }
    } elseif ($action === 'bulk_unblock') {
        // Bulk bar: unblock every selected row the same way as its Unblock button (id + scope).
        $tokens = array_slice(array_values(array_filter((array)($_POST['bulk_ids'] ?? []), static fn($t) => is_string($t) && preg_match('/^(type|individual)-\d{1,18}$/', $t))), 0, 500);
        $done = 0;
        $skipped = [];
        if (!$tokens) {
            $message = 'Select at least one blocked date.';
            $messageType = 'error';
        } else {
            $all = getBlockedDates(null, null, null);
            $bulkEntries = [];
            foreach ($tokens as $tok) {
                [$scope, $tid] = explode('-', $tok, 2);
                $target = null;
                foreach ($all as $bd) {
                    if ((int)$bd['id'] === (int)$tid && ($bd['block_scope'] ?? 'type') === $scope) {
                        $target = $bd;
                        break;
                    }
                }
                if (!$target) {
                    adminBulkSkip($skipped, 'not found');
                    continue;
                }
                $ok = $scope === 'individual' && !empty($target['individual_room_id'])
                    ? rh_unblock_room_or_pair((int)$target['individual_room_id'], $target['block_date'])
                    : unblockRoomDate($target['room_id'], $target['block_date']);
                if ($ok) {
                    $done++;
                    $bulkTypeId = ($target['room_id'] ?? null) === null || $target['room_id'] === '' ? null : (int)$target['room_id'];
                    $bulkEntries[] = $scope === 'individual' && !empty($target['individual_room_id'])
                        ? rh_block_entry($pdo, 'room', null, rh_block_room_ids((string)$target['individual_room_id']), [$target['block_date']])
                        : rh_block_entry($pdo, $bulkTypeId === null ? 'all' : 'type', $bulkTypeId, [], [$target['block_date']]);
                } else {
                    adminBulkSkip($skipped, 'could not unblock');
                }
            }
            if (!empty($bulkEntries)) {
                rh_block_log_change($pdo, 'unblock', $bulkEntries, $blockLogMeta(null, null));
            }
            [$okMsg, $errMsg] = adminBulkOutcome('unblocked', $done, $skipped);
            $message = $okMsg !== '' ? $okMsg : $errMsg;
            $messageType = $okMsg !== '' ? 'success' : 'error';
        }
    } elseif ($action === 'unblock_date') {
        $id = (int)($_POST['id'] ?? 0);
        $block_scope = $_POST['block_scope'] ?? 'type';

        if ($id > 0) {
            // Get blocked date details
            $blocked_dates = getBlockedDates(null, null, null);
            $target_date = null;

            // Type blocks and individual-room blocks have separate id sequences, so the id
            // alone can match one row of each - match the scope too, or the wrong block is removed.
            foreach ($blocked_dates as $bd) {
                if ((int)$bd['id'] === $id && ($bd['block_scope'] ?? 'type') === ($block_scope === 'individual' ? 'individual' : 'type')) {
                    $target_date = $bd;
                    break;
                }
            }

            if ($target_date) {
                if ($block_scope === 'individual' && !empty($target_date['individual_room_id'])) {
                    $result = rh_unblock_room_or_pair((int)$target_date['individual_room_id'], $target_date['block_date']);
                } else {
                    $result = unblockRoomDate($target_date['room_id'], $target_date['block_date']);
                }

                if ($result) {
                    $unblockLabel = $block_scope === 'individual' && !empty($target_date['individual_room_id'])
                        ? rh_block_label($pdo, null, rh_block_room_ids((string)$target_date['individual_room_id']))
                        : rh_block_label($pdo, ($target_date['room_id'] ?? null) === null || $target_date['room_id'] === '' ? null : (int)$target_date['room_id']);
                    $message = $unblockLabel . ' unblocked on ' . rh_block_nights_text([substr((string)$target_date['block_date'], 0, 10)]) . '.';
                    $unblockTypeId = ($target_date['room_id'] ?? null) === null || $target_date['room_id'] === '' ? null : (int)$target_date['room_id'];
                    rh_block_log_change($pdo, 'unblock', [$block_scope === 'individual' && !empty($target_date['individual_room_id'])
                        ? rh_block_entry($pdo, 'room', null, rh_block_room_ids((string)$target_date['individual_room_id']), [$target_date['block_date']], $unblockLabel)
                        : rh_block_entry($pdo, $unblockTypeId === null ? 'all' : 'type', $unblockTypeId, [], [$target_date['block_date']], $unblockLabel)], $blockLogMeta(null, null));
                    $messageType = 'success';
                } else {
                    $message = 'Failed to unblock date';
                    $messageType = 'error';
                }
            } else {
                $message = 'Blocked date not found';
                $messageType = 'error';
            }
        }
    } elseif ($action === 'block_multiple') {
        $block_scope = $_POST['block_scope'] ?? 'type';

        if ($block_scope === 'individual') {
            // Individual room blocking
            $block_room_ids = rh_block_room_ids((string)($_POST['individual_room_id'] ?? ''));
            $individual_room_id = $block_room_ids[0] ?? null;
            $dates_json = $_POST['dates'] ?? '';
            $block_type = $_POST['block_type'] ?? 'manual';
            $reason = $_POST['reason'] ?? null;
            $created_by = $user['id'] ?? null;

            // Decode JSON dates array
            $dates = !empty($dates_json) ? json_decode($dates_json, true) : [];

            $dates = rh_clean_block_dates($dates);
            if (empty($individual_room_id) || empty($dates)) {
                $message = 'Please select a room and at least one valid date (today or later) to block';
                $messageType = 'error';
            } elseif (($chkErr = rh_block_check_individual_room($pdo, (int)$individual_room_id)) !== null) {
                $message = $chkErr;
                $messageType = 'error';
            } else {
                $blocked_count = (int)rh_block_locked($pdo, function () use ($pdo, $block_room_ids, $dates, $block_type, $reason, $created_by) {
                    // A joined room blocks both rooms; the count is nights blocked on the joined room.
                    // Both rooms must take the same nights, otherwise nothing is kept.
                    $pdo->beginTransaction();
                    $counts = [];
                    foreach ($block_room_ids as $blockRoomId) {
                        $counts[] = blockIndividualRoomDates($blockRoomId, $dates, $block_type, $reason, $created_by);
                    }
                    if ($counts && min($counts) === max($counts)) {
                        $pdo->commit();
                        return min($counts);
                    }
                    $pdo->rollBack();
                    return 0;
                });

                if ($blocked_count > 0) {
                    rh_block_log_change($pdo, 'block', [rh_block_entry($pdo, 'room', null, $block_room_ids, $dates)], $blockLogMeta($block_type, $reason));
                    $message = 'Blocked ' . $blocked_count . ' night(s): ' . rh_block_label($pdo, null, $block_room_ids) . ' on ' . rh_block_nights_text($dates)
                        . (count($block_room_ids) > 1 ? ' (both rooms of the joined room).' : '.') . rh_block_overlap_note(null, $individual_room_id, $dates);
                    $messageType = 'success';
                } else {
                    $message = 'Failed to block dates';
                    $messageType = 'error';
                }
                if ($isAjax) {
                    header('Content-Type: application/json; charset=utf-8');
                    echo json_encode(['success' => $messageType === 'success', 'message' => $message]);
                    exit;
                }
            }
        } else {
            // Room type blocking
            $room_id = !empty($_POST['room_id']) ? (int)$_POST['room_id'] : null;
            $dates_json = $_POST['dates'] ?? '';
            $block_type = $_POST['block_type'] ?? 'manual';
            $reason = $_POST['reason'] ?? null;
            $created_by = $user['id'] ?? null;

            // Decode JSON dates array
            $dates = !empty($dates_json) ? json_decode($dates_json, true) : [];

            $dates = rh_clean_block_dates($dates);
            if (empty($dates)) {
                $message = 'Please select at least one valid date (today or later) to block';
                $messageType = 'error';
            } elseif (($chkErr = rh_block_check_room_type($pdo, $room_id)) !== null) {
                $message = $chkErr;
                $messageType = 'error';
            } else {
                $blocked_count = (int)rh_block_locked($pdo, function () use ($room_id, $dates, $block_type, $reason, $created_by) {
                    return blockRoomDates($room_id, $dates, $block_type, $reason, $created_by);
                });

                if ($blocked_count > 0) {
                    rh_block_log_change($pdo, 'block', [rh_block_entry($pdo, $room_id === null ? 'all' : 'type', $room_id, [], $dates)], $blockLogMeta($block_type, $reason));
                    $message = 'Blocked ' . $blocked_count . ' night(s): ' . rh_block_label($pdo, $room_id) . ' on ' . rh_block_nights_text($dates) . '.' . rh_block_overlap_note($room_id, null, $dates);
                    $messageType = 'success';
                } else {
                    $message = 'Failed to block dates';
                    $messageType = 'error';
                }
                if ($isAjax) {
                    header('Content-Type: application/json; charset=utf-8');
                    echo json_encode(['success' => $messageType === 'success', 'message' => $message]);
                    exit;
                }
            }
        }
    }
}

// Validation failures in the AJAX block forms fall through to here; answer in JSON so the
// modal shows the message instead of choking on the HTML page.
if ($isAjax && $_SERVER['REQUEST_METHOD'] === 'POST' && in_array($_POST['action'] ?? '', ['block_date', 'block_multiple'], true)) {
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['success' => $messageType === 'success', 'message' => $message !== '' ? $message : 'Nothing was blocked']);
    exit;
}

// Get filter parameters
$filter_room_id = isset($_GET['room_id']) ? ($_GET['room_id'] === 'all' ? null : (int)$_GET['room_id']) : null;
// The individual-room filter is a room id or "pair:<id>" (a joined room - ONE room to staff).
$joinedPairsActive = rh_joined_only_pairs(true);
$joinedPairByRoomId = rh_joined_only_pair_map();
$filter_individual_token = null;
$filter_individual_room_id = null;
$filter_pair_room_ids = [];
if (isset($_GET['individual_room_id']) && $_GET['individual_room_id'] !== 'all') {
    $filter_pair_room_ids = rh_block_room_ids((string)$_GET['individual_room_id']);
    if (count($filter_pair_room_ids) > 1) {
        $pairHit = $joinedPairByRoomId[$filter_pair_room_ids[0]] ?? null;
        $filter_individual_token = $pairHit ? 'pair:' . $pairHit['id'] : null;
    } else {
        $filter_pair_room_ids = [];
        $filter_individual_room_id = (int)$_GET['individual_room_id'];
        $filter_individual_token = (string)$filter_individual_room_id;
    }
}
// Invalid dates fall back to the current month (a bad value must not reach the query).
$filter_start_date = rh_normalize_block_date((string)($_GET['start_date'] ?? '')) ?? date('Y-m-01');
$filter_end_date = rh_normalize_block_date((string)($_GET['end_date'] ?? '')) ?? date('Y-m-t');
if ($filter_end_date < $filter_start_date) {
    [$filter_start_date, $filter_end_date] = [$filter_end_date, $filter_start_date];
}

// Get blocked dates (both type and individual level)
$blocked_dates = getBlockedDates($filter_room_id, $filter_start_date, $filter_end_date, $filter_individual_room_id);
if ($filter_pair_room_ids) {
    // Filtering by a joined room: its rooms' blocks (plus the room-type / all-rooms blocks, as for any room)
    $blocked_dates = getBlockedDates($filter_room_id, $filter_start_date, $filter_end_date, null);
    $blocked_dates = array_values(array_filter($blocked_dates, static function ($bd) use ($filter_pair_room_ids) {
        return ($bd['block_scope'] ?? 'type') !== 'individual' || in_array((int)$bd['individual_room_id'], $filter_pair_room_ids, true);
    }));
}
// Filtering by one room: the room-type blocks shown are only the ones that cover THAT room
// (its own type and all-rooms), not every other type's blocks.
$filter_room_type_ids = $filter_pair_room_ids ?: ($filter_individual_room_id !== null ? [$filter_individual_room_id] : []);
if ($filter_room_type_ids) {
    $ph = implode(',', array_fill(0, count($filter_room_type_ids), '?'));
    $tst = $pdo->prepare("SELECT DISTINCT room_type_id FROM individual_rooms WHERE id IN ($ph)");
    $tst->execute($filter_room_type_ids);
    $filterTypeOfRoom = array_map('intval', $tst->fetchAll(PDO::FETCH_COLUMN));
    $blocked_dates = array_values(array_filter($blocked_dates, static function ($bd) use ($filterTypeOfRoom) {
        return ($bd['block_scope'] ?? 'type') === 'individual' || $bd['room_id'] === null || in_array((int)$bd['room_id'], $filterTypeOfRoom, true);
    }));
}

// Get all rooms for dropdown
$rooms = getCachedRooms();

// Get all individual rooms for dropdown
$individual_rooms = [];
try {
    $stmt = $pdo->query("
        SELECT ir.id, ir.room_number, r.name as room_type_name
        FROM individual_rooms ir
        JOIN rooms r ON ir.room_type_id = r.id
        WHERE ir.is_active = 1
        ORDER BY r.name, ir.room_number
    ");
    $individual_rooms = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    error_log("Error fetching individual rooms: " . $e->getMessage());
}

// Joined rooms (room_combinations): a pair is sold as its own room type, but each member is
// still a physical room. Label them so a member room is never mistaken for a plain room, and so
// staff can see what a block does to the pair.
$joined_type_ids = [];   // room types sold as pairs
$room_joined_label = []; // individual room id => "joined room X (with 1B)"
try {
    $jst = $pdo->query("
        SELECT rc.combined_name, rc.combined_room_type_id, rc.room_a_id, rc.room_b_id,
               ra.room_number AS a_number, rb.room_number AS b_number
        FROM room_combinations rc
        JOIN individual_rooms ra ON ra.id = rc.room_a_id
        JOIN individual_rooms rb ON rb.id = rc.room_b_id
        WHERE rc.is_active = 1
    ");
    foreach ($jst->fetchAll(PDO::FETCH_ASSOC) as $jr) {
        $joined_type_ids[(int)$jr['combined_room_type_id']] = true;
        $room_joined_label[(int)$jr['room_a_id']][] = $jr['combined_name'] . ' (with ' . $jr['b_number'] . ')';
        $room_joined_label[(int)$jr['room_b_id']][] = $jr['combined_name'] . ' (with ' . $jr['a_number'] . ')';
    }
} catch (PDOException $e) {
    error_log('Error fetching joined rooms: ' . $e->getMessage());
}
$room_type_label = static function (array $room) use ($joined_type_ids): string {
    return (string)$room['name'] . (isset($joined_type_ids[(int)$room['id']]) ? ' - joined pairs' : '');
};
$individual_room_label = static function (array $ir) use ($room_joined_label): string {
    if (!empty($ir['option_label'])) {
        return (string)$ir['option_label'];
    }
    $label = 'Room ' . $ir['room_number'];
    if (!empty($room_joined_label[(int)$ir['id']])) {
        $label .= ' - part of joined room ' . implode(', ', $room_joined_label[(int)$ir['id']]);
    }
    return $label;
};
// Individual rooms grouped by their room type for <optgroup>s. A joined-only pair is ONE option
// ("101A + 101B (Joined Room)", value "pair:<id>") that blocks / unblocks both of its rooms.
$individual_rooms_by_type = [];
$pair_seen = [];
foreach ($individual_rooms as $ir) {
    $pairHit = $joinedPairByRoomId[(int)$ir['id']] ?? null;
    if ($pairHit) {
        if (isset($pair_seen[$pairHit['id']])) {
            continue;
        }
        $pair_seen[$pairHit['id']] = true;
        $ir['option_value'] = 'pair:' . $pairHit['id'];
        $ir['option_label'] = $pairHit['label'] . ' (Joined Room)';
    } else {
        $ir['option_value'] = (string)(int)$ir['id'];
        $ir['option_label'] = null;
    }
    $individual_rooms_by_type[(string)$ir['room_type_name']][] = $ir;
}

// The list shows a block on a joined room ONCE per night (it is stored on both of its rooms).
$blocked_dates = array_values(array_filter($blocked_dates, static function ($bd) use ($joinedPairByRoomId) {
    static $seen = [];
    if (($bd['block_scope'] ?? 'type') !== 'individual') {
        return true;
    }
    $pairHit = $joinedPairByRoomId[(int)($bd['individual_room_id'] ?? 0)] ?? null;
    if (!$pairHit) {
        return true;
    }
    $key = $pairHit['id'] . '|' . $bd['block_date'];
    if (isset($seen[$key])) {
        return false;
    }
    $seen[$key] = true;
    return true;
}));

// ── Calendar view (default): rooms down the side, the month's nights across ──────────────────
$bdView = ($_GET['view'] ?? '') === 'list' ? 'list' : 'calendar';
$gridMonth = rh_block_grid_month($_GET['month'] ?? '');
$gridStart = $gridMonth . '-01';
$gridEnd = date('Y-m-t', strtotime($gridStart));
$gridToday = date('Y-m-d');
$gridDays = [];
$gridTypes = [];       // active room types, by name
$gridLanes = [];       // room type id => lanes (a joined-only pair is ONE lane, as on the Calendar)
$gridAll = [];         // night => all-rooms block row
$gridType = [];        // room type id => night => room-type block row
$gridRoom = [];        // lane id => night => individual block row
$gridBooked = [];      // lane id => night => booking references sleeping there
$gridError = null;
if ($bdView === 'calendar') {
    require_once __DIR__ . '/includes/calendar-data.php';
    for ($d = new DateTime($gridStart), $e = new DateTime($gridEnd); $d <= $e; $d->modify('+1 day')) {
        $gridDays[] = $d->format('Y-m-d');
    }
    $calData = rh_calendar_load($pdo, $gridStart, $gridEnd);
    $gridError = $calData['error'];
    $gridTypes = $calData['roomTypes'];
    foreach ($calData['rooms'] as $lane) {
        $gridLanes[(int)$lane['room_type_id']][] = $lane;
    }
    foreach ($calData['blockedDates'] as $bd) {
        $night = substr((string)$bd['block_date'], 0, 10);
        if (($bd['block_scope'] ?? 'type') === 'individual') {
            $rid = (int)$bd['individual_room_id'];
            $gridRoom[(int)($calData['laneOfRoom'][$rid] ?? $rid)][$night] = $gridRoom[(int)($calData['laneOfRoom'][$rid] ?? $rid)][$night] ?? $bd;
        } elseif ($bd['room_id'] === null) {
            $gridAll[$night] = $bd;
        } else {
            $gridType[(int)$bd['room_id']][$night] = $bd;
        }
    }
    foreach ($calData['bookingsByDate'] as $night => $byLane) {
        foreach ($byLane as $laneKey => $list) {
            if (strpos((string)$laneKey, 'ir_') !== 0) {
                continue;
            }
            foreach ($list as $b) {
                $gridBooked[(int)substr((string)$laneKey, 3)][$night][(string)$b['booking_reference']] = (string)($b['guest_name'] ?? '');
            }
        }
    }
}
$gridBlockLabels = ['manual' => 'Blocked', 'maintenance' => 'Maintenance', 'event' => 'Event', 'full' => 'Fully booked'];
$gridBlockCodes = ['manual' => 'B', 'maintenance' => 'M', 'event' => 'E', 'full' => 'F'];
$gridPrevMonth = date('Y-m', strtotime($gridStart . ' -1 month'));
$gridNextMonth = date('Y-m', strtotime($gridStart . ' +1 month'));

$site_name = getSetting('site_name');
?>
<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="csrf-token" content="<?= htmlspecialchars($csrf_token, ENT_QUOTES) ?>">
    <script>
        (function() {
            var _t = '<?= htmlspecialchars($csrf_token, ENT_QUOTES) ?>';
            var _f = window.fetch;
            window.fetch = function(u, o) {
                if (o && o.body instanceof FormData && !o.body.has('csrf_token')) o.body.append('csrf_token', _t);
                return _f.apply(this, arguments);
            };
        })();
    </script>
    <title>Blocked Dates | <?php echo htmlspecialchars($site_name); ?> Admin</title>

    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Cormorant+Garamond:ital,wght@0,300;0,400;0,500;0,600;1,300;1,400;1,500&family=Jost:wght@300;400;500;600&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="css/admin-styles.css?v=<?php echo @filemtime(__DIR__ . '/css/admin-styles.css'); ?>">
    <link rel="stylesheet" href="css/admin-components.css?v=<?php echo @filemtime(__DIR__ . '/css/admin-components.css'); ?>">
    <link rel="stylesheet" href="css/blocked-dates.css?v=<?php echo @filemtime(__DIR__ . '/css/blocked-dates.css'); ?>">

    <!-- Flatpickr CSS -->
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/flatpickr/dist/flatpickr.min.css">
</head>

<body>

    <?php require_once 'includes/admin-header.php'; ?>

    <div class="content blocked-dates-page">

        <!-- Page Header -->
        <div class="blocked-dates-header">
            <div class="blocked-dates-header__title">
                <h2 class="section-title">Blocked Dates</h2>
                <p class="blocked-dates-header__subtitle">Block room types or individual rooms from being booked on specific dates<?php echo $canBlockRooms ? '' : '. You can view blocked dates here; blocking or unblocking needs the Block Rooms permission.'; ?></p>
            </div>
            <div class="page-actions">
                <?php if ($canBlockRooms): ?>
                <button class="btn btn-primary" data-modal-open="blockSingleDateModal" data-help="Availability block|Block a single date for a room type or individual room.">
                    <i class="fas fa-calendar-day"></i> Block Single Date
                </button>
                <button class="btn btn-secondary" data-modal-open="blockDateRangeModal" data-help="Availability block|Block a date range for a room type or individual room.">
                    <i class="fas fa-calendar-week"></i> Block Date Range
                </button>
                <?php else: ?>
                <button type="button" class="btn btn-primary" disabled aria-disabled="true" title="<?php echo htmlspecialchars(rhBlockRoomsTooltip(), ENT_QUOTES); ?>">
                    <i class="fas fa-calendar-day"></i> Block Single Date
                </button>
                <button type="button" class="btn btn-secondary" disabled aria-disabled="true" title="<?php echo htmlspecialchars(rhBlockRoomsTooltip(), ENT_QUOTES); ?>">
                    <i class="fas fa-calendar-week"></i> Block Date Range
                </button>
                <?php endif; ?>
            </div>
        </div>

        <!-- Alert Message -->
        <?php if ($message): ?>
            <div class="alert alert-<?php echo $messageType === 'success' ? 'success' : 'danger'; ?>">
                <i class="fas fa-<?php echo $messageType === 'success' ? 'check-circle' : 'exclamation-circle'; ?>"></i>
                <?php echo htmlspecialchars($message); ?>
            </div>
        <?php endif; ?>

        <nav class="bd-viewtabs" aria-label="Blocked dates view">
            <a href="blocked-dates.php?month=<?php echo htmlspecialchars($gridMonth, ENT_QUOTES); ?>" class="bd-viewtab<?php echo $bdView === 'calendar' ? ' is-active' : ''; ?>"<?php echo $bdView === 'calendar' ? ' aria-current="page"' : ''; ?>>
                <i class="fas fa-calendar-days"></i> Calendar
            </a>
            <a href="blocked-dates.php?view=list" class="bd-viewtab<?php echo $bdView === 'list' ? ' is-active' : ''; ?>"<?php echo $bdView === 'list' ? ' aria-current="page"' : ''; ?>>
                <i class="fas fa-list"></i> List
            </a>
        </nav>

        <?php if ($bdView === 'calendar'): ?>
        <div class="card bdg-card">
            <div class="card-header bdg-toolbar rh-toolbar">
                <div class="bdg-monthnav rh-toolbar__group">
                    <a class="btn btn-ghost rh-toolbar__icon" href="blocked-dates.php?month=<?php echo $gridPrevMonth; ?>" aria-label="Previous month"><i class="fas fa-chevron-left"></i></a>
                    <h3 class="bdg-month rh-toolbar__title"><?php echo date('F Y', strtotime($gridStart)); ?></h3>
                    <a class="btn btn-ghost rh-toolbar__icon" href="blocked-dates.php?month=<?php echo $gridNextMonth; ?>" aria-label="Next month"><i class="fas fa-chevron-right"></i></a>
                    <a class="btn btn-secondary" href="blocked-dates.php">This month</a>
                    <form method="GET" class="bdg-jump rh-toolbar__group">
                        <label class="rh-visually-hidden" for="bdgJumpMonth">Go to month</label>
                        <input type="month" id="bdgJumpMonth" name="month" class="form-control" value="<?php echo htmlspecialchars($gridMonth, ENT_QUOTES); ?>" onchange="this.form.submit()">
                    </form>
                </div>
                <div class="bdg-typefilter rh-toolbar__field">
                    <label class="form-label" for="bdgTypeFilter">Room type</label>
                    <select id="bdgTypeFilter" class="form-select">
                        <option value="">All room types</option>
                        <?php foreach ($gridTypes as $gt): ?>
                            <option value="<?php echo (int)$gt['id']; ?>"><?php echo htmlspecialchars($room_type_label($gt)); ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
            </div>
            <div class="card-body">
                <div class="bdg-legend">
                    <span class="bdg-legend__item"><span class="bdg-swatch bdg-swatch--blocked">B</span> Blocked for this room (B Blocked, M Maintenance, E Event, F Fully booked)</span>
                    <span class="bdg-legend__item"><span class="bdg-swatch bdg-swatch--inherited"></span> Blocked by its room type or All rooms</span>
                    <span class="bdg-legend__item"><span class="bdg-swatch bdg-swatch--booked"></span> A booking sleeps here</span>
                    <span class="bdg-legend__item"><span class="bdg-swatch bdg-swatch--conflict"></span> Booked AND blocked: move or contact the guest</span>
                    <i class="help" data-tip="Each line is one room (a joined room is one line), with its room type's line above it and All rooms at the top. Each column is one night of <?php echo htmlspecialchars(date('F Y', strtotime($gridStart)), ENT_QUOTES); ?>: blocking the 5th stops a stay sleeping on the night of the 5th, but a guest can still check out on the 5th. Click a night, or drag across nights and rooms, then press Block or Unblock below. Past nights cannot be changed.">?</i>
                </div>
                <?php if ($gridError): ?>
                    <div class="alert alert-danger"><i class="fas fa-exclamation-circle"></i> Some calendar data could not be loaded. Reload the page; if it keeps happening use the List view.</div>
                <?php endif; ?>
                <?php if ($canBlockRooms): ?>
                    <p class="bdg-hint"><i class="fas fa-hand-pointer"></i> Click a night to select it, drag to select several nights or rooms, or click a room name to select its whole month. Shift-click selects a range.</p>
                <?php endif; ?>

                <div class="bdg-scroll" id="bdgWrap">
                    <table class="bdg-table no-card-mobile" id="bdgTable" role="grid" aria-label="Blocked nights for <?php echo htmlspecialchars(date('F Y', strtotime($gridStart)), ENT_QUOTES); ?>" data-can-edit="<?php echo $canBlockRooms ? '1' : '0'; ?>">
                        <thead>
                            <tr>
                                <th scope="col" class="bdg-corner">Room</th>
                                <?php foreach ($gridDays as $night): $ts = strtotime($night); $wd = (int)date('N', $ts); ?>
                                    <th scope="col" class="bdg-dayhead<?php echo $wd >= 6 ? ' is-weekend' : ''; ?><?php echo $night === $gridToday ? ' is-today' : ''; ?><?php echo $night < $gridToday ? ' is-past' : ''; ?>" title="<?php echo date('l j F Y', $ts); ?>">
                                        <span class="bdg-dayhead__wd"><?php echo substr(date('D', $ts), 0, 2); ?></span>
                                        <span class="bdg-dayhead__n"><?php echo date('j', $ts); ?></span>
                                    </th>
                                <?php endforeach; ?>
                            </tr>
                        </thead>
                        <?php
                        /** One night cell. $direct = this row's own block row; $inherited = a wider block that covers it. */
                        $gridCell = static function (string $t, ?int $id, string $rowLabel, string $night, ?array $direct, ?array $inherited, array $booked) use ($gridToday, $canBlockRooms, $gridBlockLabels, $gridBlockCodes): string {
                            $ts = strtotime($night);
                            $cls = ['bdg-cell'];
                            if ((int)date('N', $ts) >= 6) { $cls[] = 'is-weekend'; }
                            if ($night === $gridToday) { $cls[] = 'is-today'; }
                            $past = $night < $gridToday;
                            if ($past) { $cls[] = 'is-past'; }
                            $info = [$rowLabel, date('D j M Y', $ts) . ' (night)'];
                            $code = '';
                            if ($direct) {
                                $bt = (string)($direct['block_type'] ?? 'manual');
                                $cls[] = 'is-blocked';
                                $cls[] = 'bdg-code-' . (isset($gridBlockCodes[$bt]) ? $bt : 'manual');
                                $code = $gridBlockCodes[$bt] ?? 'B';
                                $info[] = 'Blocked: ' . ($gridBlockLabels[$bt] ?? 'Blocked')
                                    . (!empty($direct['reason']) ? ' - ' . $direct['reason'] : '')
                                    . ' (by ' . ($direct['created_by_name'] ?? 'System') . ')';
                            }
                            if ($inherited) {
                                $bt = (string)($inherited['block_type'] ?? 'manual');
                                if (!$direct) { $cls[] = 'is-inherited'; }
                                $info[] = 'Also blocked by ' . ($inherited['room_id'] === null ? 'All rooms' : 'room type ' . ($inherited['room_name'] ?? '')) . ': '
                                    . ($gridBlockLabels[$bt] ?? 'Blocked') . (!empty($inherited['reason']) ? ' - ' . $inherited['reason'] : '');
                            }
                            if (!$direct && !$inherited) {
                                $info[] = 'Open for sale';
                            }
                            $bookedHtml = '';
                            if ($booked) {
                                $cls[] = 'is-booked';
                                if ($direct || $inherited) { $cls[] = 'is-conflict'; }
                                $parts = [];
                                foreach ($booked as $ref => $guest) {
                                    $parts[] = $ref . ($guest !== '' ? ' (' . $guest . ')' : '');
                                }
                                $info[] = 'Booked: ' . implode(', ', $parts);
                                $bookedHtml = '<span class="bdg-bookbar" aria-hidden="true"></span>';
                            }
                            $infoText = implode(' · ', $info);
                            $attrs = ' class="' . implode(' ', $cls) . '" title="' . htmlspecialchars($infoText, ENT_QUOTES) . '" data-info="' . htmlspecialchars($infoText, ENT_QUOTES) . '"';
                            if (!$past && $canBlockRooms) {
                                $attrs .= ' data-sel="1" data-t="' . $t . '" data-id="' . ($id === null ? '' : (int)$id) . '" data-d="' . $night . '"'
                                    . ' data-state="' . ($direct ? 'direct' : ($inherited ? 'inherited' : 'open')) . '"' . ($booked ? ' data-booked="1"' : '')
                                    . ' role="gridcell" aria-selected="false" tabindex="-1"';
                            } else {
                                $attrs .= ' role="gridcell"';
                            }
                            return '<td' . $attrs . '>' . ($code !== '' ? '<span class="bdg-code">' . $code . '</span>' : '') . $bookedHtml . '</td>';
                        };
                        ?>
                        <tbody class="bdg-group bdg-group--all">
                            <tr class="bdg-row bdg-row--all" data-row-label="All rooms">
                                <th scope="row" class="bdg-rowhead" data-row-select tabindex="0" title="Blocks here stop every room in the hotel">
                                    <span class="bdg-rowhead__name"><i class="fas fa-hotel"></i> All rooms</span>
                                    <span class="bdg-rowhead__sub">whole hotel</span>
                                </th>
                                <?php foreach ($gridDays as $night) { echo $gridCell('all', null, 'All rooms', $night, $gridAll[$night] ?? null, null, []); } ?>
                            </tr>
                        </tbody>
                        <?php foreach ($gridTypes as $gt): $tid = (int)$gt['id']; $typeName = (string)$gt['name']; ?>
                            <tbody class="bdg-group" data-type-group="<?php echo $tid; ?>">
                                <tr class="bdg-row bdg-row--type" data-row-label="<?php echo htmlspecialchars('All ' . $typeName . ' rooms', ENT_QUOTES); ?>">
                                    <th scope="row" class="bdg-rowhead" data-row-select tabindex="0" title="<?php echo htmlspecialchars(isset($joined_type_ids[$tid]) ? 'Blocks here stop the joined pairs of this type only; the rooms can still be sold on their own.' : 'Blocks here stop every ' . $typeName . ' room, and joined pairs that use them.', ENT_QUOTES); ?>">
                                        <span class="bdg-rowhead__name"><i class="fas fa-layer-group"></i> All <?php echo htmlspecialchars($typeName); ?></span>
                                        <span class="bdg-rowhead__sub"><?php echo isset($joined_type_ids[$tid]) ? 'joined pairs (room type)' : 'room type'; ?></span>
                                    </th>
                                    <?php foreach ($gridDays as $night) { echo $gridCell('type', $tid, 'All ' . $typeName . ' rooms', $night, $gridType[$tid][$night] ?? null, $gridAll[$night] ?? null, []); } ?>
                                </tr>
                                <?php foreach ($gridLanes[$tid] ?? [] as $lane):
                                    $lid = (int)$lane['id'];
                                    $isPair = !empty($lane['is_joined_lane']);
                                    $laneLabel = rh_block_room_text(preg_split('/\s*\+\s*/', (string)$lane['room_number']));
                                    $laneStatus = (string)($lane['status'] ?? '');
                                    $laneNote = $isPair ? 'Joined room: blocks both rooms' : (!empty($room_joined_label[$lid]) ? 'Part of joined room ' . implode(', ', $room_joined_label[$lid]) . ': blocking this room also stops that pair' : '');
                                ?>
                                    <tr class="bdg-row bdg-row--room" data-row-label="<?php echo htmlspecialchars($laneLabel, ENT_QUOTES); ?>">
                                        <th scope="row" class="bdg-rowhead" data-row-select tabindex="0"<?php echo $laneNote !== '' ? ' title="' . htmlspecialchars($laneNote, ENT_QUOTES) . '"' : ''; ?>>
                                            <span class="bdg-rowhead__name">
                                                <?php echo htmlspecialchars((string)$lane['room_number']); ?>
                                                <?php if ($isPair || !empty($room_joined_label[$lid])): ?><i class="fas fa-link bdg-rowhead__icon" aria-label="<?php echo htmlspecialchars($laneNote, ENT_QUOTES); ?>"></i><?php endif; ?>
                                            </span>
                                            <?php if (in_array($laneStatus, ['maintenance', 'out_of_order'], true)): ?>
                                                <span class="bdg-rowhead__status"><?php echo $laneStatus === 'maintenance' ? 'Maintenance' : 'Out of order'; ?> now</span>
                                            <?php elseif (!empty($lane['room_name']) && $lane['room_name'] !== $lane['room_number']): ?>
                                                <span class="bdg-rowhead__sub"><?php echo htmlspecialchars((string)$lane['room_name']); ?></span>
                                            <?php endif; ?>
                                        </th>
                                        <?php foreach ($gridDays as $night) {
                                            echo $gridCell('room', $lid, $laneLabel, $night, $gridRoom[$lid][$night] ?? null, $gridType[$tid][$night] ?? ($gridAll[$night] ?? null), $gridBooked[$lid][$night] ?? []);
                                        } ?>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        <?php endforeach; ?>
                    </table>
                </div>

                <?php if ($canBlockRooms): ?>
                <div class="bdg-actionbar" id="bdgBar">
                    <div class="bdg-actionbar__summary" id="bdgSummary" aria-live="polite">No nights selected</div>
                    <div class="bdg-actionbar__fields">
                        <label class="rh-visually-hidden" for="bdgBlockType">Block type</label>
                        <select id="bdgBlockType" class="form-select form-select-sm">
                            <option value="manual">Blocked</option>
                            <option value="maintenance">Maintenance</option>
                            <option value="event">Event</option>
                            <option value="full">Fully booked</option>
                        </select>
                        <label class="rh-visually-hidden" for="bdgReason">Reason (optional)</label>
                        <input type="text" id="bdgReason" class="form-control form-control-sm" maxlength="255" placeholder="Reason (optional)">
                        <button type="button" class="btn btn-primary btn-sm" id="bdgBlockBtn" disabled><i class="fas fa-ban"></i> Block</button>
                        <button type="button" class="btn btn-secondary btn-sm" id="bdgUnblockBtn" disabled><i class="fas fa-unlock"></i> Unblock</button>
                        <button type="button" class="btn btn-ghost btn-sm" id="bdgClearBtn" disabled>Clear</button>
                    </div>
                    <div id="bdgFeedback" class="admin-modal-feedback" role="status"></div>
                </div>
                <?php endif; ?>
            </div>
        </div>
        <?php else: ?>

        <!-- Filters -->
        <div class="card blocked-dates-filter-card">
            <div class="card-header blocked-dates-filter-card__header">
                <span><i class="fas fa-magnifying-glass"></i> Filter Blocked Dates</span>
            </div>
            <div class="card-body">
                <form method="GET" class="blocked-dates-filter-form">
                    <input type="hidden" name="view" value="list">
                    <div class="bdfilter-field">
                        <label class="form-label">Room Type</label>
                        <select name="room_id" class="form-select">
                            <option value="all">All Room Types</option>
                            <?php foreach ($rooms as $room): ?>
                                <option value="<?php echo $room['id']; ?>" <?php echo $filter_room_id === (int)$room['id'] ? 'selected' : ''; ?>>
                                    <?php echo htmlspecialchars($room_type_label($room)); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="bdfilter-field">
                        <label class="form-label">Individual Room</label>
                        <select name="individual_room_id" class="form-select">
                            <option value="all">All Individual Rooms</option>
                            <?php foreach ($individual_rooms_by_type as $irTypeName => $irGroup): ?>
                                <optgroup label="<?php echo htmlspecialchars($irTypeName, ENT_QUOTES); ?>">
                                    <?php foreach ($irGroup as $ir): ?>
                                        <option value="<?php echo htmlspecialchars((string)$ir['option_value'], ENT_QUOTES); ?>" <?php echo $filter_individual_token !== null && $filter_individual_token === (string)$ir['option_value'] ? 'selected' : ''; ?>>
                                            <?php echo htmlspecialchars($individual_room_label($ir)); ?>
                                        </option>
                                    <?php endforeach; ?>
                                </optgroup>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="bdfilter-field">
                        <label class="form-label">Start Date</label>
                        <input type="date" name="start_date" class="form-control" value="<?php echo htmlspecialchars($filter_start_date); ?>">
                    </div>
                    <div class="bdfilter-field">
                        <label class="form-label">End Date</label>
                        <input type="date" name="end_date" class="form-control" value="<?php echo htmlspecialchars($filter_end_date); ?>">
                    </div>
                    <div class="bdfilter-field bdfilter-field--actions">
                        <label class="form-label">&nbsp;</label>
                        <div class="bdfilter-actions-row">
                            <button type="submit" class="btn btn-primary">
                                <i class="fas fa-magnifying-glass"></i> Apply
                            </button>
                            <a href="blocked-dates.php?view=list" class="btn btn-ghost">Reset</a>
                        </div>
                    </div>
                </form>
            </div>
        </div>

        <!-- Blocked Dates List -->
        <div class="card" id="bdListCard">
            <div class="card-header">
                <i class="fas fa-list"></i> Blocked Dates
                <span class="badge badge-info blocked-dates-count"><?php echo count($blocked_dates); ?> dates</span>
            </div>
            <div class="card-body">
                <?php if (empty($blocked_dates)): ?>
                    <div class="blocked-dates-empty">
                        <i class="fas fa-calendar-check"></i>
                        <h5>No blocked dates found</h5>
                        <p>Use the buttons above to block dates</p>
                    </div>
                <?php else: ?>
                    <div class="table-container" id="blockedDatesContainer">
                        <table class="table table-hover blocked-dates-table">
                            <thead>
                                <tr>
                                    <?php if ($canBlockRooms): ?><th class="admin-bulk-col"><?php echo adminBulkAllBox('Select all visible blocked dates'); ?></th><?php endif; ?>
                                    <th>Date</th>
                                    <th>Scope</th>
                                    <th>Room</th>
                                    <th>Block Type</th>
                                    <th>Reason</th>
                                    <th>Created By</th>
                                    <th>Created At</th>
                                    <th>Actions</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($blocked_dates as $bd): ?>
                                    <tr>
                                        <?php if ($canBlockRooms): ?><td class="admin-bulk-col"><input type="checkbox" class="admin-bulk-check" data-bulk-row value="<?php echo (($bd['block_scope'] ?? 'type') === 'individual' ? 'individual' : 'type') . '-' . (int)$bd['id']; ?>" aria-label="Select blocked date <?php echo htmlspecialchars((string)$bd['block_date'], ENT_QUOTES); ?>"></td><?php endif; ?>
                                        <td data-label="Date">
                                            <i class="fas fa-calendar-day blocked-dates-date-icon"></i>
                                            <?php echo date('M j, Y', strtotime($bd['block_date'])); ?>
                                        </td>
                                        <td data-label="Scope">
                                            <?php if (($bd['block_scope'] ?? 'type') === 'individual'): ?>
                                                <span class="badge badge-warning">
                                                    <i class="fas fa-door-open"></i> Individual Room
                                                </span>
                                            <?php else: ?>
                                                <span class="badge badge-info">
                                                    <i class="fas fa-layer-group"></i> Room Type
                                                </span>
                                            <?php endif; ?>
                                        </td>
                                        <td data-label="Room">
                                            <?php if (($bd['block_scope'] ?? 'type') === 'individual'): ?>
                                                <?php $bdPair = $joinedPairByRoomId[(int)($bd['individual_room_id'] ?? 0)] ?? null; ?>
                                                <span class="badge badge-info">
                                                    <?php echo htmlspecialchars($bdPair ? ($bd['room_name'] . ' - ' . $bdPair['label'] . ' (Joined Room)') : ($bd['room_name'] . ' - ' . $bd['individual_room_number'])); ?>
                                                </span>
                                                <?php if ($bdPair): ?>
                                                    <small class="blocked-dates-muted" style="display:block;margin-top:3px;"><i class="fas fa-link"></i> Blocks both rooms</small>
                                                <?php elseif (!empty($room_joined_label[(int)($bd['individual_room_id'] ?? 0)])): ?>
                                                    <small class="blocked-dates-muted" style="display:block;margin-top:3px;"><i class="fas fa-link"></i> Also blocks joined room <?php echo htmlspecialchars(implode(', ', $room_joined_label[(int)$bd['individual_room_id']])); ?></small>
                                                <?php endif; ?>
                                            <?php else: ?>
                                                <?php if ($bd['room_id']): ?>
                                                    <span class="badge badge-info">
                                                        <?php echo htmlspecialchars($bd['room_name']); ?>
                                                    </span>
                                                <?php else: ?>
                                                    <span class="badge badge-completed">All Room Types</span>
                                                <?php endif; ?>
                                            <?php endif; ?>
                                        </td>
                                        <td data-label="Block Type">
                                            <span class="badge badge-completed">
                                                <?php echo ucfirst($bd['block_type'] ?? 'manual'); ?>
                                            </span>
                                        </td>
                                        <td data-label="Reason">
                                            <?php if ($bd['reason']): ?>
                                                <?php echo htmlspecialchars($bd['reason']); ?>
                                            <?php else: ?>
                                                <span class="blocked-dates-muted">-</span>
                                            <?php endif; ?>
                                        </td>
                                        <td data-label="Created By">
                                            <?php echo htmlspecialchars($bd['created_by_name'] ?? 'System'); ?>
                                        </td>
                                        <td data-label="Created At">
                                            <?php echo date('M j, Y g:i A', strtotime($bd['created_at'])); ?>
                                        </td>
                                        <td data-label="Actions">
                                            <?php if ($canBlockRooms): ?>
                                            <form method="POST" class="blocked-dates-inline-form" data-admin-confirm="Unblock this date and make it available for booking again?" data-admin-confirm-title="Unblock date" data-admin-confirm-ok="Unblock" data-admin-confirm-icon="fa-unlock">
                                                <input type="hidden" name="action" value="unblock_date">
                                                <input type="hidden" name="id" value="<?php echo $bd['id']; ?>">
                                                <input type="hidden" name="block_scope" value="<?php echo $bd['block_scope'] ?? 'type'; ?>">
                                                <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf_token, ENT_QUOTES) ?>">
                                                <button type="submit" class="btn btn-danger btn-sm blocked-dates-unblock-btn">
                                                    <i class="fas fa-unlock"></i> Unblock
                                                </button>
                                            </form>
                                            <?php else: ?>
                                            <button type="button" class="btn btn-danger btn-sm blocked-dates-unblock-btn" disabled aria-disabled="true" title="<?php echo htmlspecialchars(rhBlockRoomsTooltip(), ENT_QUOTES); ?>">
                                                <i class="fas fa-unlock"></i> Unblock
                                            </button>
                                            <?php endif; ?>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                        <?php if ($canBlockRooms): ?>
                        <?php adminBulkBarStart('bulk_unblock', '#blockedDatesContainer', $csrf_token); ?>
                            <?php adminBulkButton('unblock', 'Unblock', 'fa-unlock', 'Unblock {n} selected date(s) and make them available for booking again?', 'btn-primary', '', 'Unblock'); ?>
                        <?php adminBulkBarEnd(); ?>
                        <?php endif; ?>
                    </div>
                <?php endif; ?>
            </div>
        </div>
        <?php endif; ?>
    </div>

    <!-- Block Single Date Modal -->
    <div class="modal-overlay" id="blockSingleDateModal-overlay" data-modal-overlay data-close-on-overlay="true"></div>
    <div class="modal" id="blockSingleDateModal" data-modal>
        <div class="modal-content">
            <div class="modal-header">
                <h3 class="modal-title">
                    <i class="fas fa-calendar-day"></i> Block Single Date
                </h3>
                <button type="button" class="modal-close" data-modal-close="blockSingleDateModal" aria-label="Close">&times;</button>
            </div>
            <form method="POST" id="blockSingleDateForm">
            <div class="modal-body">
                                    <input type="hidden" name="action" value="block_date">
                    <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf_token, ENT_QUOTES) ?>">
                    <input type="hidden" name="block_scope" id="blockSingleScope" value="type">

                    <div class="mb-3">
                        <label class="form-label">Block Level</label>
                        <select name="block_scope" id="blockSingleScopeSelect" class="form-select" required onchange="toggleBlockSingleLevel()">
                            <option value="type">Room Type Level (All rooms of type)</option>
                            <option value="individual">Individual Room Level (Specific room)</option>
                        </select>
                    </div>

                    <div class="mb-3" id="blockSingleRoomTypeGroup">
                        <label class="form-label">Room Type</label>
                        <select name="room_id" id="blockSingleRoomType" class="form-select">
                            <option value="">All Room Types</option>
                            <?php foreach ($rooms as $room): ?>
                                <option value="<?php echo $room['id']; ?>">
                                    <?php echo htmlspecialchars($room_type_label($room)); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                        <small class="text-muted">Select "All Room Types" to block all room types for this date. Blocking a room type also stops joined pairs that use its rooms; blocking a joined-pairs type stops only the pairs (the rooms can still be sold on their own).</small>
                    </div>

                    <div class="mb-3 d-none" id="blockSingleIndividualRoomGroup">
                        <label class="form-label">Individual Room</label>
                        <select name="individual_room_id" id="blockSingleIndividualRoom" class="form-select">
                            <?php foreach ($individual_rooms_by_type as $irTypeName => $irGroup): ?>
                                <optgroup label="<?php echo htmlspecialchars($irTypeName, ENT_QUOTES); ?>">
                                    <?php foreach ($irGroup as $ir): ?>
                                        <option value="<?php echo htmlspecialchars((string)$ir['option_value'], ENT_QUOTES); ?>">
                                            <?php echo htmlspecialchars($individual_room_label($ir)); ?>
                                        </option>
                                    <?php endforeach; ?>
                                </optgroup>
                            <?php endforeach; ?>
                        </select>
                        <small class="text-muted">Select a specific physical room to block. A room that is part of a joined room also takes that pair off sale on the blocked nights.</small>
                    </div>

                    <div class="mb-3">
                        <label class="form-label">Date to Block</label>
                        <input type="date" name="block_date" id="singleDateInput" class="form-control" required min="<?php echo date('Y-m-d'); ?>">
                    </div>

                    <div class="mb-3">
                        <label class="form-label">Block Type</label>
                        <select name="block_type" class="form-select" required>
                            <option value="manual">Manual Block</option>
                            <option value="maintenance">Maintenance</option>
                            <option value="event">Event</option>
                            <option value="full">Fully Booked</option>
                        </select>
                    </div>

                    <div class="mb-3">
                        <label class="form-label">Reason (Optional)</label>
                        <textarea name="reason" class="form-control" rows="3" placeholder="Enter reason for blocking this date..."></textarea>
                    </div>

                    <div id="blockSingleFeedback" class="admin-modal-feedback mb-3"></div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-modal-close="blockSingleDateModal">Close</button>
                        <button type="submit" id="blockSingleSaveBtn" class="btn btn-primary">
                            <i class="fas fa-ban"></i> Block Date
                        </button>
                    </div>
                </form>
        </div>
    </div>

    <!-- Block Date Range Modal -->
    <div class="modal-overlay" id="blockDateRangeModal-overlay" data-modal-overlay data-close-on-overlay="true"></div>
    <div class="modal" id="blockDateRangeModal" data-modal>
        <div class="modal-content">
            <div class="modal-header">
                <h3 class="modal-title">
                    <i class="fas fa-calendar-week"></i> Block Date Range
                </h3>
                <button type="button" class="modal-close" data-modal-close="blockDateRangeModal" aria-label="Close">&times;</button>
            </div>
            <form method="POST" id="blockRangeForm">
            <div class="modal-body">
                                    <input type="hidden" name="action" value="block_multiple">
                    <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf_token, ENT_QUOTES) ?>">
                    <input type="hidden" name="dates" id="selectedDatesArray">
                    <input type="hidden" name="block_scope" id="blockRangeScope" value="type">

                    <div class="mb-3">
                        <label class="form-label">Block Level</label>
                        <select name="block_scope" id="blockRangeScopeSelect" class="form-select" required onchange="toggleBlockRangeLevel()">
                            <option value="type">Room Type Level (All rooms of type)</option>
                            <option value="individual">Individual Room Level (Specific room)</option>
                        </select>
                    </div>

                    <div class="mb-3" id="blockRangeRoomTypeGroup">
                        <label class="form-label">Room Type</label>
                        <select name="room_id" id="blockRangeRoomType" class="form-select">
                            <option value="">All Room Types</option>
                            <?php foreach ($rooms as $room): ?>
                                <option value="<?php echo $room['id']; ?>">
                                    <?php echo htmlspecialchars($room_type_label($room)); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                        <small class="text-muted">Select "All Room Types" to block all room types for these dates. Blocking a room type also stops joined pairs that use its rooms; blocking a joined-pairs type stops only the pairs (the rooms can still be sold on their own).</small>
                    </div>

                    <div class="mb-3 d-none" id="blockRangeIndividualRoomGroup">
                        <label class="form-label">Individual Room</label>
                        <select name="individual_room_id" id="blockRangeIndividualRoom" class="form-select">
                            <?php foreach ($individual_rooms_by_type as $irTypeName => $irGroup): ?>
                                <optgroup label="<?php echo htmlspecialchars($irTypeName, ENT_QUOTES); ?>">
                                    <?php foreach ($irGroup as $ir): ?>
                                        <option value="<?php echo htmlspecialchars((string)$ir['option_value'], ENT_QUOTES); ?>">
                                            <?php echo htmlspecialchars($individual_room_label($ir)); ?>
                                        </option>
                                    <?php endforeach; ?>
                                </optgroup>
                            <?php endforeach; ?>
                        </select>
                        <small class="text-muted">Select a specific physical room to block. A room that is part of a joined room also takes that pair off sale on the blocked nights.</small>
                    </div>

                    <div class="mb-3">
                        <label class="form-label">Date Range</label>
                        <input type="text" id="dateRangeInput" class="form-control" placeholder="Select start and end dates">
                        <small class="text-muted">Every date in the range is a blocked night (first to last, inclusive). A guest may still check out on the day after the last date.</small>
                    </div>

                    <div class="mb-3">
                        <label class="form-label">Selected Dates</label>
                        <div id="selectedDatesDisplay" class="alert alert-info">
                            <span class="text-muted">No dates selected</span>
                        </div>
                    </div>

                    <div class="mb-3">
                        <label class="form-label">Block Type</label>
                        <select name="block_type" class="form-select" required>
                            <option value="manual">Manual Block</option>
                            <option value="maintenance">Maintenance</option>
                            <option value="event">Event</option>
                            <option value="full">Fully Booked</option>
                        </select>
                    </div>

                    <div class="mb-3">
                        <label class="form-label">Reason (Optional)</label>
                        <textarea name="reason" class="form-control" rows="3" placeholder="Enter reason for blocking these dates..."></textarea>
                    </div>

                    <div id="blockRangeFeedback" class="admin-modal-feedback mb-3"></div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-modal-close="blockDateRangeModal">Close</button>
                        <button type="submit" id="blockRangeSaveBtn" class="btn btn-primary">
                            <i class="fas fa-ban"></i> Block Dates
                        </button>
                    </div>
                </form>
        </div>
    </div>

    <!-- Flatpickr JS -->
    <script src="https://cdn.jsdelivr.net/npm/flatpickr"></script>

    <script>

        function initBlockedDatePickers() {
            if (typeof flatpickr !== 'function') {
                return;
            }

            // Initialize single date picker
            flatpickr('#singleDateInput', {
                minDate: 'today',
                dateFormat: 'Y-m-d'
            });

            // Initialize date range picker
            flatpickr('#dateRangeInput', {
                mode: 'range',
                minDate: 'today',
                dateFormat: 'Y-m-d',
                onChange: function(selectedDates, dateStr, instance) {
                    const display = document.getElementById('selectedDatesDisplay');
                    const input = document.getElementById('selectedDatesArray');

                    if (selectedDates.length === 2) {
                        const startDate = new Date(selectedDates[0]);
                        const endDate = new Date(selectedDates[1]);
                        const dates = [];

                        // Generate all dates in range
                        const currentDate = new Date(startDate);
                        while (currentDate <= endDate) {
                            dates.push(instance.formatDate(currentDate, 'Y-m-d'));
                            currentDate.setDate(currentDate.getDate() + 1);
                        }

                        input.value = JSON.stringify(dates);

                        if (dates.length <= 10) {
                            display.innerHTML = '<strong>' + dates.length + ' dates:</strong> ' + dates.join(', ');
                        } else {
                            display.innerHTML = '<strong>' + dates.length + ' dates:</strong> ' + dates.slice(0, 5).join(', ') + ' ... ' + dates.slice(-2).join(', ');
                        }
                    } else {
                        input.value = '';
                        display.innerHTML = '<span class="blocked-dates-muted">Select start and end dates</span>';
                    }
                }
            });
        }

        initBlockedDatePickers();

        // Toggle between room type and individual room blocking (single date)
        function toggleBlockSingleLevel() {
            const scope = document.getElementById('blockSingleScopeSelect').value;
            const roomTypeGroup = document.getElementById('blockSingleRoomTypeGroup');
            const individualRoomGroup = document.getElementById('blockSingleIndividualRoomGroup');
            const roomTypeSelect = document.getElementById('blockSingleRoomType');
            const individualRoomSelect = document.getElementById('blockSingleIndividualRoom');
            const scopeInput = document.getElementById('blockSingleScope');

            scopeInput.value = scope;

            if (scope === 'individual') {
                roomTypeGroup.classList.add('d-none');
                individualRoomGroup.classList.remove('d-none');
                roomTypeSelect.removeAttribute('required');
                individualRoomSelect.setAttribute('required', 'required');
            } else {
                roomTypeGroup.classList.remove('d-none');
                individualRoomGroup.classList.add('d-none');
                roomTypeSelect.setAttribute('required', 'required');
                individualRoomSelect.removeAttribute('required');
            }
        }

        // Toggle between room type and individual room blocking (date range)
        function toggleBlockRangeLevel() {
            const scope = document.getElementById('blockRangeScopeSelect').value;
            const roomTypeGroup = document.getElementById('blockRangeRoomTypeGroup');
            const individualRoomGroup = document.getElementById('blockRangeIndividualRoomGroup');
            const roomTypeSelect = document.getElementById('blockRangeRoomType');
            const individualRoomSelect = document.getElementById('blockRangeIndividualRoom');
            const scopeInput = document.getElementById('blockRangeScope');

            scopeInput.value = scope;

            if (scope === 'individual') {
                roomTypeGroup.classList.add('d-none');
                individualRoomGroup.classList.remove('d-none');
                roomTypeSelect.removeAttribute('required');
                individualRoomSelect.setAttribute('required', 'required');
            } else {
                roomTypeGroup.classList.remove('d-none');
                individualRoomGroup.classList.add('d-none');
                roomTypeSelect.setAttribute('required', 'required');
                individualRoomSelect.removeAttribute('required');
            }
        }

        // ── AJAX save — keep modals open ──────────────────────────────────────
        function handleBlockFormSubmit(formId, saveBtnId, feedbackId) {
            const form = document.getElementById(formId);
            const saveBtn = document.getElementById(saveBtnId);
            const fb = document.getElementById(feedbackId);
            const origHtml = saveBtn.innerHTML;
            saveBtn.disabled = true;
            saveBtn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Saving…';
            fb.className = 'admin-modal-feedback';
            fb.innerHTML = '';
            fetch(window.location.pathname, {
                    method: 'POST',
                    headers: {
                        'X-Requested-With': 'XMLHttpRequest'
                    },
                    body: new FormData(form)
                })
                .then(function(r) {
                    if (!r.ok) throw new Error('HTTP ' + r.status);
                    return r.json();
                })
                .then(function(res) {
                    saveBtn.disabled = false;
                    saveBtn.innerHTML = origHtml;
                    fb.className = 'admin-modal-feedback ' + (res.success ? 'admin-modal-feedback--success' : 'admin-modal-feedback--error') + ' visible';
                    fb.innerHTML = '<i class="fas fa-' + (res.success ? 'check-circle' : 'exclamation-circle') + '"></i> ' + res.message;
                    if (res.success) {
                        refreshBlockedDatesTable();
                        if (res.success && formId === 'blockSingleDateForm') {
                            form.reset();
                            const fp = document.getElementById('singleDateInput')._flatpickr;
                            if (fp) fp.clear();
                        }
                    }
                })
                .catch(function() {
                    saveBtn.disabled = false;
                    saveBtn.innerHTML = origHtml;
                    fb.className = 'admin-modal-feedback admin-modal-feedback--error visible';
                    fb.innerHTML = '<i class="fas fa-exclamation-circle"></i> Network error — please try again.';
                });
        }
        document.getElementById('blockSingleDateForm').addEventListener('submit', function(e) {
            e.preventDefault();
            handleBlockFormSubmit('blockSingleDateForm', 'blockSingleSaveBtn', 'blockSingleFeedback');
        });
        document.getElementById('blockRangeForm').addEventListener('submit', function(e) {
            e.preventDefault();
            handleBlockFormSubmit('blockRangeForm', 'blockRangeSaveBtn', 'blockRangeFeedback');
        });

        function refreshBlockedDatesTable() {
            // no-store: a cached copy of the page would show the blocks as they were before the save.
            fetch(window.location.href, { cache: 'no-store', credentials: 'same-origin' })
                .then(function(r) {
                    return r.text();
                })
                .then(function(html) {
                    const doc = new DOMParser().parseFromString(html, 'text/html');
                    // List view: the whole list card (it may have been the "no blocked dates" state).
                    // Calendar view: the grid; the grid script then clears its selection.
                    ['bdListCard', 'bdgWrap'].forEach(function(id) {
                        const next = doc.getElementById(id);
                        const cur = document.getElementById(id);
                        if (next && cur) cur.outerHTML = next.outerHTML;
                    });
                    if (window.bdgAfterRefresh) window.bdgAfterRefresh();
                }).catch(function() {});
        }
        window.refreshBlockedDatesTable = refreshBlockedDatesTable;
    </script>
    <script src="js/admin-components.js"></script>
    <?php if ($bdView === 'calendar' && $canBlockRooms): ?>
    <script src="js/blocked-dates-grid.js?v=<?php echo @filemtime(__DIR__ . '/js/blocked-dates-grid.js'); ?>"></script>
    <?php endif; ?>

    <?php require_once 'includes/admin-footer.php'; ?>

