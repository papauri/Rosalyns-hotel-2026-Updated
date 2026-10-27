<?php

/**
 * Lost-demand log for the guest booking search (.claude/AI_ASSISTANT_PLAN.md Phase 4c item 4).
 *
 * check-availability.php calls rh_demand_log() AFTER the guest's answer has been sent, so nothing here can delay
 * or change a search result. It records only searches that found no room: room type, dates, party size and why.
 * No name, email, phone or address; the visitor is a short one-way hash (session id + salt) used only to count a
 * repeated search once. Every error is swallowed: before migration 073 (no demand_log table) it is silently a
 * no-op. Read by the Assistant's lost-demand and rate-suggestion lookups, which only ever suggest.
 */

if (!function_exists('rh_demand_log')) {
    /**
     * @param array $rows each ['room_type_id' => int, 'check_in' => Y-m-d, 'check_out' => Y-m-d, 'guests' => int,
     *                    'available' => bool, 'reason' => string]
     */
    function rh_demand_log(?PDO $db, array $rows): void
    {
        try {
            if (!empty($GLOBALS['__rh_demand_log_force_error'])) { // tests only: prove a logging error never reaches the guest
                throw new RuntimeException('forced demand-log error');
            }
            if (!$db instanceof PDO || !$rows) {
                return;
            }
            $lost = [];
            foreach ($rows as $r) {
                if (!empty($r['available'])) {
                    continue;
                }
                $ci = (string)($r['check_in'] ?? '');
                $co = (string)($r['check_out'] ?? '');
                $rt = (int)($r['room_type_id'] ?? 0);
                if ($rt <= 0 || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $ci) || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $co) || $co <= $ci) {
                    continue;
                }
                $reason = preg_replace('/[^a-z_]/', '', strtolower((string)($r['reason'] ?? 'unavailable'))) ?: 'unavailable';
                $lost[] = [$rt, $ci, $co, max(1, min(99, (int)($r['guests'] ?? 1))), substr($reason, 0, 20)];
            }
            if (!$lost) {
                return;
            }
            $salt = (string)($_ENV['APP_ENCRYPTION_SALT'] ?? getenv('APP_ENCRYPTION_SALT') ?: 'rh-demand');
            $sid = session_status() === PHP_SESSION_ACTIVE ? session_id() : (string)($_SERVER['REMOTE_ADDR'] ?? '');
            $visitor = substr(hash('sha256', $salt . '|' . $sid . '|' . date('Y-m-d')), 0, 16);
            $st = $db->prepare("INSERT INTO demand_log (room_type_id, check_in, check_out, guests, reason, visitor_hash) VALUES (?, ?, ?, ?, ?, ?)");
            foreach (array_slice($lost, 0, 20) as $l) {
                $st->execute([$l[0], $l[1], $l[2], $l[3], $l[4], $visitor]);
            }
        } catch (Throwable $e) {
            // Never reaches the guest. A missing table (before migration 073) is expected and not logged.
            if (strpos($e->getMessage(), '42S02') === false && strpos($e->getMessage(), "doesn't exist") === false) {
                error_log('Demand log skipped: ' . $e->getMessage());
            }
        }
    }
}
