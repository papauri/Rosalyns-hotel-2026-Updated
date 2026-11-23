<?php

/**
 * Recurring maintenance generation (shared by the daily scheduler job and any admin caller).
 *
 * Next-due is based on the previous occurrence's own date (due_date, else start_date, else
 * completed_at, else created_at), never on when the row happened to be inserted. 'monthly'
 * means a real calendar month. A series is the room + title with is_recurring = 1; only the
 * newest occurrence spawns the next one, and nothing is created while an occurrence of the
 * series is still open, so running this repeatedly is idempotent.
 */

require_once __DIR__ . '/room-management.php';
require_once __DIR__ . '/room-night-clash.php';

if (!function_exists('rh_maint_next_due_date')) {

    /**
     * Next due date for a recurring schedule row, or null when the pattern is unknown.
     */
    function rh_maint_next_due_date(array $row): ?DateTime
    {
        $base = null;
        foreach (['due_date', 'start_date', 'completed_at', 'created_at'] as $col) {
            $v = $row[$col] ?? null;
            if ($v !== null && $v !== '' && strpos((string)$v, '0000-00-00') !== 0) {
                $base = (string)$v;
                break;
            }
        }
        if ($base === null) {
            return null;
        }
        try {
            $d = new DateTime(substr($base, 0, 10));
        } catch (Throwable $e) {
            return null;
        }
        switch ($row['recurring_pattern'] ?? '') {
            case 'daily':
                $d->modify('+1 day');
                break;
            case 'weekly':
                $d->modify('+7 days');
                break;
            case 'monthly':
                $d->modify('+1 month');
                break;
            default:
                return null;
        }
        return $d;
    }

    /**
     * Create the next occurrence of every due recurring maintenance series.
     *
     * The new occurrence copies the series' Block room tick, except on nights a booking sleeps in the room: then it is
     * created with block_room = 0 (a work reminder that never closes the room over a guest; owner 2026-11-23). That
     * is decided per occurrence under the room locks (rh_room_nights_take_locks) in its own transaction.
     *
     * @param int|null $performedBy admin user id; null (scheduler) reuses the series' created_by
     * @param string[] $notes filled with one line per occurrence that could not close its room
     * @return int number of schedules created
     */
    function rh_create_recurring_maintenance(PDO $pdo, ?int $performedBy = null, array &$notes = []): int
    {
        $has = static function (string $c) use ($pdo): bool {
            return rh_maint_schedule_column_exists($pdo, $c);
        };
        if (!$has('is_recurring') || !$has('recurring_pattern')) {
            return 0;
        }

        $today = date('Y-m-d'); // hotel timezone
        $created = 0;

        $where = [
            "s.is_recurring = 1",
            "s.recurring_pattern IN ('daily','weekly','monthly')",
            "s.status = 'completed'",
            // only the newest occurrence of the series spawns the next one
            "NOT EXISTS (SELECT 1 FROM room_maintenance_schedules nx
                WHERE nx.individual_room_id = s.individual_room_id AND nx.title = s.title
                  AND nx.is_recurring = 1 AND nx.id > s.id)",
            // ... and never while an occurrence of the series is still open
            "NOT EXISTS (SELECT 1 FROM room_maintenance_schedules op
                WHERE op.individual_room_id = s.individual_room_id AND op.title = s.title
                  AND op.is_recurring = 1 AND op.status IN ('planned', '', 'in_progress'))",
        ];
        $params = [];
        if ($has('recurring_end_date')) {
            $where[] = "(s.recurring_end_date IS NULL OR s.recurring_end_date >= ?)";
            $params[] = $today;
        }

        $st = $pdo->prepare("SELECT s.* FROM room_maintenance_schedules s WHERE " . implode(' AND ', $where));
        $st->execute($params);
        $series = $st->fetchAll(PDO::FETCH_ASSOC);

        foreach ($series as $row) {
            $next = rh_maint_next_due_date($row);
            if ($next === null || $next->format('Y-m-d') > $today) {
                continue; // not due yet
            }
            // A series that was neglected for a while restarts from today instead of back-filling.
            $dueDate = max($next->format('Y-m-d'), $today);
            if (!empty($row['recurring_end_date']) && $dueDate > $row['recurring_end_date']) {
                continue;
            }

            $startDate = $dueDate . ' 00:00:00';
            $endDate = date('Y-m-d H:i:s', strtotime($dueDate . ' 00:00:00 +1 day'));
            $owner = $performedBy ?? (isset($row['created_by']) ? (int)$row['created_by'] : null);

            $cols = ['individual_room_id', 'title', 'description', 'status', 'start_date', 'end_date', 'assigned_to', 'created_by'];
            $vals = [$row['individual_room_id'], $row['title'], $row['description'], 'planned', $startDate, $endDate, $row['assigned_to'], $owner];
            $newData = [
                'individual_room_id' => $row['individual_room_id'], 'title' => $row['title'], 'description' => $row['description'],
                'status' => 'pending', 'start_date' => $startDate, 'end_date' => $endDate,
                'assigned_to' => $row['assigned_to'], 'created_by' => $owner,
            ];
            $optional = [
                'due_date' => $dueDate,
                'maintenance_type' => $row['maintenance_type'] ?? 'inspection',
                'priority' => $row['priority'] ?? 'medium',
                'is_recurring' => 1,
                'recurring_pattern' => $row['recurring_pattern'],
                'recurring_end_date' => $row['recurring_end_date'] ?? null,
                'estimated_duration' => $row['estimated_duration'] ?? 60,
            ];
            foreach ($optional as $col => $val) {
                if ($has($col)) {
                    $cols[] = $col;
                    $vals[] = $val;
                    $newData[$col] = $val;
                }
            }

            $roomId = (int)$row['individual_room_id'];
            $own = !$pdo->inTransaction();
            try {
                $plan = $own ? rh_room_nights_lock_plan($pdo, [['scope' => 'room', 'room_ids' => [$roomId]]]) : null;
                if ($own) {
                    $pdo->beginTransaction();
                    rh_room_nights_take_locks($pdo, $plan);
                }
                if ($has('block_room')) {
                    $block = (int)($row['block_room'] ?? 1) === 1 ? 1 : 0;
                    if ($block === 1 && !rh_maint_job_can_close_room($pdo, $roomId, $startDate, $endDate)) {
                        $block = 0;
                        $notes[] = rh_block_label($pdo, null, [$roomId]) . ' is booked on ' . rh_block_nights_text(rh_maint_job_nights($startDate, $endDate))
                            . ', so the repeat of "' . (string)$row['title'] . '" does not close the room.';
                    }
                    $cols[] = 'block_room';
                    $vals[] = $block;
                    $newData['block_room'] = $block;
                }
                $ins = $pdo->prepare("INSERT INTO room_maintenance_schedules (" . implode(', ', $cols) . ") VALUES (" . implode(', ', array_fill(0, count($cols), '?')) . ")");
                $ins->execute($vals);
                $newId = (int)$pdo->lastInsertId();
                if (function_exists('logMaintenanceAction')) {
                    logMaintenanceAction($newId, 'recurring_created', null, $newData, $owner);
                }
                if ($own) {
                    $pdo->commit();
                }
                $created++;
            } catch (Throwable $e) {
                if ($own && $pdo->inTransaction()) {
                    $pdo->rollBack();
                }
                error_log('rh_create_recurring_maintenance (series room ' . $roomId . '): ' . $e->getMessage());
            }
        }

        return $created;
    }
}
