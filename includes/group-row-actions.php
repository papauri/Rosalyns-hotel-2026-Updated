<?php
/**
 * Group table row actions on admin/booking-details.php (owner 2026-11-18, tasks G2 + G3).
 *
 * G2 "Change dates" on any room of the group: no new date logic. rh_group_row_date_path() only says which EXISTING
 * path that room uses (Adjust Stay Dates before check-out, Correct Stay Dates after) and whether this user may use it,
 * with the same rules the room's own page uses for its header buttons ($can_adjust_dates && edit_booking;
 * $bd_can_correct_stay). The button opens that room's page with ?open=dates, which opens the dialog there.
 *
 * G3 "Who's staying" (bookings.room_guest_name, migration 066): rh_group_room_guest_save() sets or clears the optional
 * name on one room of the group, any status except deleted, permission edit_booking, History on that room.
 */

if (!function_exists('rh_group_row_date_path')) {
    /** The permission's own label ("Edit Booking"), else the key. */
    function rh_group_row_perm_label(string $key): string
    {
        if (function_exists('getAllPermissions')) {
            $all = getAllPermissions();
            if (!empty($all[$key]['label'])) {
                return (string)$all[$key]['label'];
            }
        }
        return $key;
    }

    /**
     * Which existing date path a group room uses, for this user. Pure (no DB).
     * @return array{path: string, label: string, reason: string} path 'adjust' | 'correct' | '' (reason says why not)
     */
    function rh_group_row_date_path(string $status, bool $deleted, bool $permEdit, bool $permCorrect): array
    {
        $status = strtolower(trim($status));
        if ($deleted) {
            return ['path' => '', 'label' => '', 'reason' => 'This room has been deleted, so its dates cannot be changed. Restore it first.'];
        }
        if (in_array($status, ['cancelled', 'no-show', 'expired'], true)) {
            return ['path' => '', 'label' => '', 'reason' => 'This room is ' . str_replace('-', ' ', $status) . ', so its dates cannot be changed.'];
        }
        if ($status === 'checked-out') {
            return $permCorrect
                ? ['path' => 'correct', 'label' => 'Correct Stay Dates', 'reason' => '']
                : ['path' => '', 'label' => '', 'reason' => 'This guest has checked out. Changing a checked-out room\'s dates needs the "'
                    . rh_group_row_perm_label('correct_stay_after_checkout') . '" permission.'];
        }
        return $permEdit
            ? ['path' => 'adjust', 'label' => 'Adjust Stay Dates', 'reason' => '']
            : ['path' => '', 'label' => '', 'reason' => 'Changing this room\'s dates needs the "' . rh_group_row_perm_label('edit_booking') . '" permission.'];
    }

    /**
     * Set or clear "Who's staying" on one room of the group the current page belongs to.
     * $opts: user_id, user_name, refresh (callable(PDO, int): string; default rh_stay_correction_refresh_invoices) — called
     * after the save for a checked-out room only, so its issued invoice PDFs show the new name under the same numbers.
     * @return array{success: bool, message: string, value: string, display: string, changed: bool, status: int}
     */
    function rh_group_room_guest_save(PDO $pdo, int $currentId, int $targetId, $raw, array $opts = []): array
    {
        $fail = static function (string $m, int $code = 422): array {
            return ['success' => false, 'message' => $m, 'value' => '', 'display' => '', 'changed' => false, 'status' => $code];
        };
        $uid = (int)($opts['user_id'] ?? 0);
        if ($uid <= 0 || !function_exists('hasPermission') || !hasPermission($uid, 'edit_booking')) {
            return $fail('You do not have permission to do that (' . rh_group_row_perm_label('edit_booking') . ').', 403);
        }
        if (!is_scalar($raw) && $raw !== null) {
            return $fail('Enter the name as text. Nothing was changed.');
        }
        if (!function_exists('rh_group_ops_root')) {
            require_once __DIR__ . '/booking-group-ops.php';
        }
        $value = rh_clean_room_guest_name((string)$raw); // trimmed, control characters out, at most 150 characters
        $root = rh_group_ops_root($pdo, $currentId);
        if ($targetId <= 0 || $root <= 0 || rh_group_ops_root($pdo, $targetId) !== $root) {
            return $fail('That room is not in this group. Reload the page.');
        }
        $own = !$pdo->inTransaction();
        try {
            if ($own) {
                $pdo->beginTransaction();
            }
            $st = $pdo->prepare('SELECT b.id, b.booking_reference, b.guest_name, b.room_guest_name, b.status, b.deleted_at, b.primary_booking_id,
                    COALESCE((SELECT r.name FROM rooms r WHERE r.id = b.room_id), \'\') AS room_name
                FROM bookings b WHERE b.id = ? FOR UPDATE');
            $st->execute([$targetId]);
            $row = $st->fetch(PDO::FETCH_ASSOC);
            $rowRoot = $row ? ((int)($row['primary_booking_id'] ?? 0) > 0 ? (int)$row['primary_booking_id'] : (int)$row['id']) : 0;
            if (!$row || $rowRoot !== $root) {
                if ($own) {
                    $pdo->rollBack();
                }
                return $fail('That room is not in this group. Reload the page.');
            }
            if (!empty($row['deleted_at'])) {
                if ($own) {
                    $pdo->rollBack();
                }
                return $fail('That room has been deleted. Restore it before making changes.');
            }
            $old = trim((string)($row['room_guest_name'] ?? ''));
            $display = $value !== '' ? $value : trim((string)($row['guest_name'] ?? ''));
            if ($old === $value) {
                if ($own) {
                    $pdo->commit();
                }
                return ['success' => true, 'message' => 'No change.', 'value' => $value, 'display' => $display, 'changed' => false, 'status' => 200];
            }
            $pdo->prepare('UPDATE bookings SET room_guest_name = ?, updated_at = NOW() WHERE id = ?')
                ->execute([$value !== '' ? $value : null, $targetId]);
            $fromNote = $currentId !== $targetId ? ' Changed from the group table on another room\'s page (booking #' . $currentId . ').' : '';
            if ($value === '') {
                $desc = 'Name on the room cleared (was "' . $old . '"). The lead booker\'s name shows for this room again.' . $fromNote;
            } elseif ($old === '') {
                $desc = 'Name on the room set to "' . $value . '".' . $fromNote;
            } else {
                $desc = 'Name on the room changed from "' . $old . '" to "' . $value . '".' . $fromNote;
            }
            rh_group_ops_log($row, 'Name on the room changed', $desc, $old !== '' ? $old : '(none)', $value !== '' ? $value : '(none)', $opts,
                ['room_guest_name' => ['old' => $old !== '' ? $old : null, 'new' => $value !== '' ? $value : null], 'from_booking_id' => $currentId]);
            if ($own) {
                $pdo->commit();
            }
        } catch (Throwable $e) {
            if ($own && $pdo->inTransaction()) {
                $pdo->rollBack();
            }
            error_log('rh_group_room_guest_save: booking ' . $targetId . ': ' . $e->getMessage());
            return $fail('Could not save the name. Nothing was changed.', 500);
        }
        $msg = $value === '' ? 'Name on the room cleared.' : 'Saved.';
        // The name prints on the room's invoice lines (config/invoice.php): a checked-out room's issued PDFs are rebuilt
        // under the same numbers so they show it (never emailed), as Correct Stay Dates does.
        if (strtolower((string)$row['status']) === 'checked-out') {
            $refresh = $opts['refresh'] ?? null;
            if (!is_callable($refresh)) {
                if (!function_exists('rh_stay_correction_refresh_invoices')) {
                    require_once __DIR__ . '/stay-correction.php';
                }
                $refresh = 'rh_stay_correction_refresh_invoices';
            }
            $msg .= (string)$refresh($pdo, $targetId);
        }
        return ['success' => true, 'message' => $msg, 'value' => $value, 'display' => $display, 'changed' => true, 'status' => 200];
    }
}
