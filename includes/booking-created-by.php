<?php
/**
 * "Who created this booking" for the Bookings list (small print under the Created date).
 *
 * Pure part (no database): rh_created_by_pick() + rh_created_by_html(), unit tested by
 * scripts/tests/booking_created_by.php. Loader: rh_created_by_map() runs ONE read-only query for
 * the bookings on the current page.
 *
 * Sources (same order as the Assistant's "who made this booking", includes/ai/ai-desk.php rh_ai_booking_made):
 *   1. booking_audit_log 'created' row with a staff id (admin create-booking, logBookingAudit) -> staff
 *   2. booking_timeline_logs 'create' row by performed_by_type: admin -> staff; guest / api -> online; else system
 *   3. audit 'created' row whose note says "Booking made online" -> online (logBookingCreatedAudit stores
 *      the GUEST's name in performed_by_name with no id, so it must never read as "by <guest>")
 *   4. audit 'created' row with only a stored name -> "by <name>"
 *   5. nothing recorded -> no line at all
 * Staff names come from rh_hist_who() (includes/booking-history-format.php); the card shows the name only,
 * the "Name (role)" form goes in the title attribute.
 */

require_once __DIR__ . '/booking-history-format.php';

if (!function_exists('rh_created_by_pick')) {
    /**
     * @param array|null $au audit row: performed_by, performed_by_name, note, au_staff_name, au_staff_username, au_staff_role
     * @param array|null $tl timeline row: performed_by_type, performed_by_id, performed_by_name, tl_staff_name, tl_staff_username, tl_staff_role
     * @return array{kind:string,label:string,title:string} kind: staff | online | system | none
     */
    function rh_created_by_pick(?array $au, ?array $tl): array
    {
        $none = ['kind' => 'none', 'label' => '', 'title' => ''];
        $staff = static function ($id, $name, $staffName, $username, $role): array {
            $id = (int)$id;
            $map = [];
            if ($id > 0 && trim((string)$staffName) !== '') {
                $map[$id] = ['name' => trim((string)$staffName), 'username' => (string)$username, 'role' => (string)$role];
            }
            $full = rh_hist_who($map, $id, $name, null);
            $short = $map ? $map[$id]['name'] : $full;
            return ['kind' => 'staff', 'label' => 'by ' . $short, 'title' => 'Created by ' . $full];
        };
        $online = ['kind' => 'online', 'label' => 'Online booking', 'title' => 'Made online (website or booking link)'];

        if ($au && (int)($au['performed_by'] ?? 0) > 0) {
            return $staff($au['performed_by'], $au['performed_by_name'] ?? null, $au['au_staff_name'] ?? '', $au['au_staff_username'] ?? '', $au['au_staff_role'] ?? '');
        }
        if ($tl) {
            $type = strtolower(trim((string)($tl['performed_by_type'] ?? '')));
            if ($type === 'guest' || $type === 'api') {
                return $online;
            }
            if ($type === 'admin' || $type === 'staff') {
                $id = (int)($tl['performed_by_id'] ?? 0);
                $name = trim((string)($tl['performed_by_name'] ?? ''));
                if ($id > 0 || $name !== '') {
                    return $staff($id, $name, $tl['tl_staff_name'] ?? '', $tl['tl_staff_username'] ?? '', $tl['tl_staff_role'] ?? '');
                }
            }
            return ['kind' => 'system', 'label' => 'System', 'title' => 'Created by the system'];
        }
        if ($au) {
            if (preg_match('/^Booking made online/i', trim((string)($au['note'] ?? '')))) {
                return $online;
            }
            if (trim((string)($au['performed_by_name'] ?? '')) !== '') {
                return $staff(0, $au['performed_by_name'], '', '', '');
            }
        }
        return $none;
    }

    /** The small line under the Created date; '' when nothing is recorded. Escaped. */
    function rh_created_by_html(array $pick): string
    {
        if (($pick['label'] ?? '') === '') {
            return '';
        }
        return '<span class="bk-created-by bk-created-by--' . htmlspecialchars((string)$pick['kind'], ENT_QUOTES) . '" title="'
            . htmlspecialchars((string)$pick['title'], ENT_QUOTES) . '">' . htmlspecialchars((string)$pick['label'], ENT_QUOTES) . '</span>';
    }

    /**
     * booking id => pick, for the given ids (one page). ONE read-only query; [] on any error (then no line is shown,
     * never a false "not recorded"). Separate columns per source (no UNION), so differing column collations between
     * the two log tables cannot break it.
     */
    function rh_created_by_map(PDO $pdo, array $ids): array
    {
        $ids = array_values(array_unique(array_filter(array_map('intval', $ids), static function ($v) { return $v > 0; })));
        if (!$ids) {
            return [];
        }
        $in = implode(',', array_fill(0, count($ids), '?'));
        try {
            $st = $pdo->prepare("
                SELECT x.id AS booking_id,
                       a.id AS au_row, a.performed_by, a.performed_by_name, a.note,
                       COALESCE(NULLIF(ua.full_name, ''), ua.username) AS au_staff_name, ua.username AS au_staff_username, ua.role AS au_staff_role,
                       t.id AS tl_row, t.performed_by_type, t.performed_by_id, t.performed_by_name AS tl_name,
                       COALESCE(NULLIF(ut.full_name, ''), ut.username) AS tl_staff_name, ut.username AS tl_staff_username, ut.role AS tl_staff_role
                FROM (
                    SELECT b.id,
                           (SELECT a1.id FROM booking_audit_log a1 WHERE a1.booking_id = b.id AND a1.action = 'created' ORDER BY a1.id ASC LIMIT 1) AS au_id,
                           (SELECT t1.id FROM booking_timeline_logs t1 WHERE t1.booking_id = b.id AND t1.action_type = 'create' ORDER BY t1.id ASC LIMIT 1) AS tl_id
                    FROM bookings b
                    WHERE b.id IN ($in)
                ) x
                LEFT JOIN booking_audit_log a ON a.id = x.au_id
                LEFT JOIN admin_users ua ON ua.id = a.performed_by
                LEFT JOIN booking_timeline_logs t ON t.id = x.tl_id
                LEFT JOIN admin_users ut ON ut.id = t.performed_by_id
            ");
            $st->execute($ids);
            $out = [];
            foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) {
                $au = $r['au_row'] !== null ? $r : null;
                $tl = $r['tl_row'] !== null ? ['performed_by_type' => $r['performed_by_type'], 'performed_by_id' => $r['performed_by_id'],
                    'performed_by_name' => $r['tl_name'], 'tl_staff_name' => $r['tl_staff_name'], 'tl_staff_username' => $r['tl_staff_username'],
                    'tl_staff_role' => $r['tl_staff_role']] : null;
                $out[(int)$r['booking_id']] = rh_created_by_pick($au, $tl);
            }
            return $out;
        } catch (Throwable $e) {
            error_log('rh_created_by_map: ' . $e->getMessage());
            return [];
        }
    }
}
