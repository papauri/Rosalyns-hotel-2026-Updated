<?php
/**
 * Extend for maintenance jobs and housekeeping tasks (GRID_UNIFY_PLAN Phase 3, owner 2026-11-23: "extend for more
 * days or months, years, time"). Blocks have their own engine (includes/block-save.php rh_block_extend).
 *
 *  - Maintenance (admin/room-maintenance.php bulk_schedules, bulk_op=extend): new end = end + N days / weeks /
 *    months / years (time of day kept; months clamp to the month end) or a chosen date + time. Must be later than
 *    the old end (the drag handle may also shorten: 'resize', floor start + 1 hour, the update rule). At most 3 years
 *    after the start. Completed / cancelled / verified jobs are skipped. A job that closes its room (block_room = 1)
 *    is checked on the NEWLY covered nights (includes/room-night-clash.php): a booked night refuses that job (owner
 *    answer 1) and lists the bookings + free dates; the other jobs extend.
 *  - Housekeeping (admin/housekeeping.php bulk_assignments, bulk_op=extend): repeat the task every day or week until
 *    a date, same scheduled time; new rows copy room, type, priority, assignee, time, duration and notes, status
 *    pending, is_recurring 0 (the recurring generator never spawns more). A day that already has an open task of the
 *    same room + type is skipped. A task that already repeats is skipped. No booking check (rule R6).
 *
 * Each action: ONE transaction (all or nothing on any error), the room locks first for maintenance (the booking lock
 * order), the housekeeping add lock for housekeeping, one audit row per job / new task and ONE Staff activity row.
 * $opt['preview'] = read only (what would happen, nothing saved, no locks). $opt['before_commit'] = callable($pdo)
 * run just before COMMIT (tests force a failure there). $opt['reconcile'] = callable(int $roomId) run inside the
 * transaction for each room touched (the page's room-status reconcile).
 */

require_once __DIR__ . '/block-save.php'; // rh_extend_add, RH_EXTEND_MAX_NIGHTS, the clash helper

if (!function_exists('rh_maint_extend')) {

    /** One Staff activity row (System Logs) for a whole Extend action. */
    function rh_ops_extend_log(PDO $pdo, array $actor, string $action, string $details): void
    {
        $st = $pdo->prepare('INSERT INTO admin_activity_log (user_id, username, action, details, ip_address, created_at) VALUES (?, ?, ?, ?, ?, NOW())');
        $st->execute([max(0, (int)($actor['id'] ?? 0)), mb_substr((string)($actor['name'] ?? ''), 0, 100), $action, mb_substr($details, 0, 60000),
            substr((string)($_SERVER['REMOTE_ADDR'] ?? ''), 0, 45)]);
    }

    /** "3 Jan 2027 11:00" */
    function rh_ops_dt_text(string $dt): string
    {
        $t = strtotime($dt);
        return $t === false ? $dt : date('j M Y H:i', $t);
    }

    /**
     * The new end of one job, or why not. $how: ['mode' => 'by', 'n', 'unit'] | ['mode' => 'until', 'until' =>
     * 'Y-m-d H:i[:s]' or 'Y-m-d' (then the job's end time is kept)]. $resize (drag handle): the end may also move
     * earlier, but stays at least 1 hour after the start.
     *
     * @return array{end?:string,why?:string}
     */
    function rh_maint_extend_new_end(array $row, array $how, bool $resize = false): array
    {
        $start = (string)($row['start_date'] ?? '');
        $end = (string)($row['end_date'] ?? '');
        $s = strtotime($start);
        $e = strtotime($end);
        if ($s === false || $e === false) {
            return ['why' => 'the job has no valid dates'];
        }
        $time = date('H:i:s', $e);
        if (($how['mode'] ?? 'by') === 'until') {
            $raw = trim(str_replace('T', ' ', (string)($how['until'] ?? '')));
            if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $raw)) {
                $raw .= ' ' . $time;
            }
            $t = preg_match('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}(:\d{2})?$/', $raw) ? strtotime($raw) : false;
            if ($t === false) {
                return ['why' => 'choose the date and time to extend until'];
            }
            $new = date('Y-m-d H:i:s', $t);
        } else {
            $n = (int)($how['n'] ?? 0);
            $unit = (string)($how['unit'] ?? 'days');
            if ($n < 1 || $n > 1100 || !in_array($unit, ['days', 'weeks', 'months', 'years'], true)) {
                return ['why' => 'enter how many days, weeks, months or years to extend by (1 or more)'];
            }
            $d = rh_extend_add(date('Y-m-d', $e), $n, $unit);
            if ($d === '') {
                return ['why' => 'the date could not be read'];
            }
            $new = $d . ' ' . $time;
        }
        $nt = strtotime($new);
        if ($resize) {
            if ($nt < $s + 3600) {
                return ['why' => 'a job must end at least 1 hour after it starts'];
            }
        } elseif ($nt <= $e) {
            return ['why' => 'the new end is not after the current end'];
        }
        if ($nt === $e) {
            return ['why' => 'the job already ends then'];
        }
        if ($nt > strtotime(date('Y-m-d H:i:s', $s) . ' +' . RH_EXTEND_MAX_NIGHTS . ' days')) {
            return ['why' => 'a job can run at most 3 years (' . RH_EXTEND_MAX_NIGHTS . ' days) from its start'];
        }
        return ['end' => $new];
    }

    /**
     * Extend (or, with $opt['resize'], move the end of) maintenance jobs. $ids: job ids. $actor: ['id', 'name'].
     *
     * @return array{ok:bool,message:string,done:int,skipped:array<string,int>,refused:string[],changes:array}
     */
    function rh_maint_extend(PDO $pdo, array $ids, array $how, array $actor, array $opt = []): array
    {
        $ids = array_values(array_unique(array_filter(array_map('intval', $ids), static fn($i) => $i > 0)));
        $out = ['ok' => false, 'message' => '', 'done' => 0, 'skipped' => [], 'refused' => [], 'changes' => []];
        if (!$ids) {
            $out['message'] = 'Select at least one job.';
            return $out;
        }
        $preview = !empty($opt['preview']);
        $resize = !empty($opt['resize']);
        $skip = static function (array &$o, string $why): void {
            $o['skipped'][$why] = ($o['skipped'][$why] ?? 0) + 1;
        };
        $label = static function (array $row): string {
            return 'Room ' . (string)($row['room_number'] ?? $row['individual_room_id']) . ' "' . (string)($row['title'] ?? '') . '" (#' . (int)$row['id'] . ')';
        };
        $ph = implode(',', array_fill(0, count($ids), '?'));
        $load = "SELECT m.*, r.room_number FROM room_maintenance_schedules m LEFT JOIN individual_rooms r ON r.id = m.individual_room_id WHERE m.id = ?";

        // One job: what would change (and the clash check for a job that closes its room).
        $step = static function (array $row) use ($pdo, $how, $resize, $preview, $label, $skip, &$out): ?string {
            if (!rh_maint_status_is_open($row['status'] ?? '')) {
                $skip($out, 'completed, cancelled or verified jobs are not extended');
                return null;
            }
            $ne = rh_maint_extend_new_end($row, $how, $resize);
            if (isset($ne['why'])) {
                $skip($out, $ne['why']);
                return null;
            }
            if ((int)($row['block_room'] ?? 1) === 1 && strtotime($ne['end']) > strtotime((string)$row['end_date'])) {
                $c = rh_maint_clash_check($pdo, (int)$row['individual_room_id'], (string)$row['start_date'], $ne['end'], $row, $preview);
                if (!$c['ok']) {
                    $skip($out, 'the room is booked on the new nights (listed below)');
                    $out['refused'][] = $label($row) . ': ' . $c['message'];
                    return null;
                }
            }
            $out['changes'][] = ['id' => (int)$row['id'], 'label' => $label($row), 'from' => (string)$row['end_date'], 'to' => $ne['end'], 'room' => (int)$row['individual_room_id']];
            return $ne['end'];
        };

        if ($preview) {
            foreach ($ids as $id) {
                $st = $pdo->prepare($load);
                $st->execute([$id]);
                $row = $st->fetch(PDO::FETCH_ASSOC);
                if (!$row) {
                    $skip($out, 'not found');
                    continue;
                }
                if ($step($row) !== null) {
                    $out['done']++;
                }
            }
            $out['ok'] = $out['done'] > 0;
            $out['message'] = rh_maint_extend_message($out, true);
            return $out;
        }

        // Rooms first (plain read, BEFORE the transaction), then the locks, then everything else.
        $st = $pdo->prepare("SELECT DISTINCT individual_room_id FROM room_maintenance_schedules WHERE id IN ($ph)");
        $st->execute($ids);
        $rooms = array_values(array_filter(array_map('intval', $st->fetchAll(PDO::FETCH_COLUMN))));
        $plan = rh_room_nights_lock_plan($pdo, [['scope' => 'room', 'room_ids' => $rooms]]);
        $pdo->beginTransaction();
        try {
            rh_room_nights_take_locks($pdo, $plan);
            $touched = [];
            foreach ($ids as $id) {
                $st = $pdo->prepare($load . ' FOR UPDATE');
                $st->execute([$id]);
                $row = $st->fetch(PDO::FETCH_ASSOC);
                if (!$row) {
                    $skip($out, 'not found');
                    continue;
                }
                if (!in_array((int)$row['individual_room_id'], $plan['room_ids'], true)) {
                    $skip($out, 'moved to another room while saving; try again');
                    continue;
                }
                $newEnd = $step($row);
                if ($newEnd === null) {
                    continue;
                }
                $pdo->prepare('UPDATE room_maintenance_schedules SET end_date = ? WHERE id = ?')->execute([$newEnd, $id]);
                $after = $pdo->prepare('SELECT * FROM room_maintenance_schedules WHERE id = ?');
                $after->execute([$id]);
                $before = $row;
                unset($before['room_number']);
                logMaintenanceAction($id, 'updated', $before, $after->fetch(PDO::FETCH_ASSOC) ?: null, isset($actor['id']) ? (int)$actor['id'] : null, $actor['name'] ?? null);
                $touched[(int)$row['individual_room_id']] = true;
                $out['done']++;
            }
            if ($out['done'] > 0) {
                $lines = array_map(static fn($c) => $c['label'] . ': ' . rh_ops_dt_text($c['from']) . ' -> ' . rh_ops_dt_text($c['to']), $out['changes']);
                rh_ops_extend_log($pdo, $actor, 'maintenance_extend', ($resize ? 'Changed the end of ' : 'Extended ') . $out['done'] . ' maintenance job(s): ' . implode('; ', $lines)
                    . ($out['refused'] ? ' | Not extended (booked): ' . implode(' ', $out['refused']) : ''));
                if (isset($opt['reconcile']) && is_callable($opt['reconcile'])) {
                    foreach (array_keys($touched) as $rid) {
                        ($opt['reconcile'])($rid);
                    }
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
            if (rh_is_lock_conflict($e)) {
                return ['ok' => false, 'message' => rh_room_nights_lock_message()] + $out;
            }
            error_log('rh_maint_extend: ' . $e->getMessage());
            return ['ok' => false, 'message' => 'The jobs could not be extended. Nothing was changed.', 'done' => 0, 'changes' => []] + $out;
        }
        $out['ok'] = $out['done'] > 0;
        $out['message'] = rh_maint_extend_message($out, false);
        return $out;
    }

    /** Staff wording of a rh_maint_extend() result. */
    function rh_maint_extend_message(array $o, bool $preview): string
    {
        $parts = [];
        if ($o['done'] > 0) {
            $lines = array_map(static fn($c) => $c['label'] . ' to ' . rh_ops_dt_text($c['to']), array_slice($o['changes'], 0, 8));
            $more = count($o['changes']) - 8;
            $parts[] = ($preview ? 'Will change ' : 'Changed ') . $o['done'] . ' job(s): ' . implode('; ', $lines) . ($more > 0 ? '; and ' . $more . ' more' : '') . '.';
        } else {
            $parts[] = $preview ? 'No job would change.' : 'No job was changed.';
        }
        if ($o['skipped']) {
            $s = [];
            foreach ($o['skipped'] as $why => $n) {
                $s[] = $why . ' x' . $n;
            }
            $parts[] = array_sum($o['skipped']) . ' skipped (' . implode('; ', $s) . ').';
        }
        foreach (array_slice($o['refused'], 0, 6) as $r) {
            $parts[] = $r;
        }
        return implode(' ', $parts);
    }

    /* ------------------------------------------------------------------ housekeeping */

    /** Most new tasks one Repeat action may add (ASSUMPTION 2026-11-23). */
    if (!defined('RH_HK_REPEAT_MAX_ROWS')) {
        define('RH_HK_REPEAT_MAX_ROWS', 2000);
    }

    /**
     * Repeat housekeeping tasks every day or week until a date. $how: ['every' => 'daily'|'weekly', 'until' => Y-m-d].
     *
     * @return array{ok:bool,message:string,added:int,dupes:int,done:int,skipped:array<string,int>,lines:string[]}
     */
    function rh_hk_extend(PDO $pdo, array $ids, array $how, array $actor, array $opt = []): array
    {
        $ids = array_values(array_unique(array_filter(array_map('intval', $ids), static fn($i) => $i > 0)));
        $out = ['ok' => false, 'message' => '', 'added' => 0, 'dupes' => 0, 'done' => 0, 'skipped' => [], 'lines' => []];
        $every = (string)($how['every'] ?? 'daily');
        $until = rh_normalize_block_date((string)($how['until'] ?? ''));
        $today = date('Y-m-d');
        $maxUntil = date('Y-m-d', strtotime($today . ' +366 days'));
        if (!$ids) {
            $out['message'] = 'Select at least one task.';
            return $out;
        }
        if (!in_array($every, ['daily', 'weekly'], true)) {
            $out['message'] = 'Choose daily or weekly.';
            return $out;
        }
        if ($until === null) {
            $out['message'] = 'Choose the date to repeat until.';
            return $out;
        }
        if ($until > $maxUntil) {
            $out['message'] = 'Repeat at most one year ahead (until ' . date('j M Y', strtotime($maxUntil)) . ' or earlier). Nothing was changed.';
            return $out;
        }
        $preview = !empty($opt['preview']);
        $stepDays = $every === 'weekly' ? 7 : 1;
        $skip = static function (array &$o, string $why): void {
            $o['skipped'][$why] = ($o['skipped'][$why] ?? 0) + 1;
        };

        $run = static function () use ($pdo, $ids, $until, $today, $stepDays, $every, $actor, $opt, $preview, $skip, &$out): void {
            $cols = [];
            foreach ($pdo->query('SHOW COLUMNS FROM housekeeping_assignments')->fetchAll(PDO::FETCH_ASSOC) as $c) {
                $cols[(string)$c['Field']] = true;
            }
            $hasType = isset($cols['assignment_type']);
            $touched = [];
            foreach ($ids as $id) {
                $st = $pdo->prepare('SELECT h.*, r.room_number FROM housekeeping_assignments h LEFT JOIN individual_rooms r ON r.id = h.individual_room_id WHERE h.id = ?' . ($preview ? '' : ' FOR UPDATE'));
                $st->execute([$id]);
                $row = $st->fetch(PDO::FETCH_ASSOC);
                if (!$row) {
                    $skip($out, 'not found');
                    continue;
                }
                if (!empty($row['is_recurring'])) {
                    $skip($out, 'already repeats; change its repeat end date instead');
                    continue;
                }
                $due = substr((string)$row['due_date'], 0, 10);
                $dates = [];
                for ($d = date('Y-m-d', strtotime($due . ' +' . $stepDays . ' days')); $d <= $until; $d = date('Y-m-d', strtotime($d . ' +' . $stepDays . ' days'))) {
                    if ($d >= $today) {
                        $dates[] = $d;
                    }
                }
                if (!$dates) {
                    $skip($out, 'the repeat end is not after the task\'s date');
                    continue;
                }
                $type = $hasType ? (string)($row['assignment_type'] ?? '') : '';
                $added = 0;
                foreach ($dates as $d) {
                    // One open task per room + type + day (the add form's rule, hkFindOpenDuplicateAssignment).
                    $sql = "SELECT id FROM housekeeping_assignments WHERE individual_room_id = ? AND due_date = ? AND status IN ('pending','in_progress','blocked')";
                    $p = [(int)$row['individual_room_id'], $d];
                    if ($hasType) {
                        $sql .= ' AND assignment_type = ?';
                        $p[] = $type;
                    }
                    $dup = $pdo->prepare($sql . ' LIMIT 1');
                    $dup->execute($p);
                    if ($dup->fetchColumn()) {
                        $out['dupes']++;
                        continue;
                    }
                    if ($out['added'] >= RH_HK_REPEAT_MAX_ROWS) {
                        throw new DomainException('At most ' . RH_HK_REPEAT_MAX_ROWS . ' new tasks can be added in one go. Choose an earlier end date or fewer tasks. Nothing was changed.');
                    }
                    $out['added']++;
                    $added++;
                    if ($preview) {
                        continue;
                    }
                    $new = ['individual_room_id' => (int)$row['individual_room_id'], 'status' => 'pending', 'due_date' => $d, 'assigned_to' => $row['assigned_to'] ?? null,
                        'created_by' => isset($actor['id']) ? (int)$actor['id'] : null, 'notes' => $row['notes'] ?? null];
                    foreach (['priority', 'assignment_type', 'scheduled_time', 'estimated_duration'] as $c) {
                        if (isset($cols[$c])) {
                            $new[$c] = $row[$c] ?? null;
                        }
                    }
                    if (isset($cols['is_recurring'])) {
                        $new['is_recurring'] = 0;
                    }
                    $names = array_keys($new);
                    $pdo->prepare('INSERT INTO housekeeping_assignments (' . implode(', ', $names) . ') VALUES (' . implode(', ', array_fill(0, count($names), '?')) . ')')
                        ->execute(array_values($new));
                    $newId = (int)$pdo->lastInsertId();
                    logHousekeepingAction($newId, 'created', null, $new + ['repeated_from' => (int)$id], isset($actor['id']) ? (int)$actor['id'] : null, $actor['name'] ?? null);
                }
                if ($added > 0) {
                    $out['done']++;
                    $touched[(int)$row['individual_room_id']] = true;
                    $out['lines'][] = 'Room ' . (string)($row['room_number'] ?? $row['individual_room_id']) . ' ' . str_replace('_', ' ', $type !== '' ? $type : 'task')
                        . ' (#' . (int)$id . ') ' . $every . ' to ' . date('j M Y', strtotime($until)) . ': ' . $added . ' new';
                } else {
                    $skip($out, 'every day already has an open task of the same type');
                }
            }
            if ($preview) {
                return;
            }
            if ($out['added'] > 0) {
                rh_ops_extend_log($pdo, $actor, 'housekeeping_repeat', 'Repeated ' . $out['done'] . ' housekeeping task(s), ' . $out['added'] . ' new: ' . implode('; ', $out['lines'])
                    . ($out['dupes'] ? ' | ' . $out['dupes'] . ' day(s) skipped: an open task of the same type already exists' : ''));
                if (isset($opt['reconcile']) && is_callable($opt['reconcile'])) {
                    foreach (array_keys($touched) as $rid) {
                        ($opt['reconcile'])($rid);
                    }
                }
            }
            if (isset($opt['before_commit']) && is_callable($opt['before_commit'])) {
                ($opt['before_commit'])($pdo);
            }
        };

        if ($preview) {
            try {
                $run();
            } catch (DomainException $e) {
                return ['ok' => false, 'message' => $e->getMessage()] + $out;
            }
            $out['ok'] = $out['added'] > 0;
            $out['message'] = rh_hk_extend_message($out, true);
            return $out;
        }
        try {
            // The add form's lock: a repeat and a new task for the same room + day cannot both pass the duplicate check.
            return rh_with_create_lock($pdo, 'hk_assignment', static function () use ($pdo, $run, &$out) {
                $pdo->beginTransaction();
                try {
                    $run();
                    $pdo->commit();
                } catch (Throwable $e) {
                    if ($pdo->inTransaction()) {
                        $pdo->rollBack();
                    }
                    $base = ['added' => 0, 'dupes' => 0, 'done' => 0, 'lines' => []] + $out;
                    if ($e instanceof DomainException) {
                        return ['ok' => false, 'message' => $e->getMessage()] + $base;
                    }
                    error_log('rh_hk_extend: ' . $e->getMessage());
                    return ['ok' => false, 'message' => 'The tasks could not be repeated. Nothing was changed.'] + $base;
                }
                $out['ok'] = $out['added'] > 0;
                $out['message'] = rh_hk_extend_message($out, false);
                return $out;
            });
        } catch (RuntimeException $e) {
            return ['ok' => false, 'message' => 'Someone else is saving a housekeeping task right now. Please try again in a moment. Nothing was changed.'] + $out;
        }
    }

    /** Staff wording of a rh_hk_extend() result. */
    function rh_hk_extend_message(array $o, bool $preview): string
    {
        $parts = [];
        if ($o['added'] > 0) {
            $parts[] = ($preview ? 'Will add ' : 'Added ') . $o['added'] . ' new task(s): ' . implode('; ', array_slice($o['lines'], 0, 8)) . (count($o['lines']) > 8 ? '; and more' : '') . '.';
        } else {
            $parts[] = $preview ? 'No new task would be added.' : 'No new task was added.';
        }
        if ($o['dupes'] > 0) {
            $parts[] = $o['dupes'] . ' day(s) skipped: that room already has an open task of the same type that day.';
        }
        if ($o['skipped']) {
            $s = [];
            foreach ($o['skipped'] as $why => $n) {
                $s[] = $why . ' x' . $n;
            }
            $parts[] = array_sum($o['skipped']) . ' task(s) skipped (' . implode('; ', $s) . ').';
        }
        return implode(' ', $parts);
    }
}
