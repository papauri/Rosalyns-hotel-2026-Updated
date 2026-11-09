<?php
/**
 * Room / rate price history writer (table room_price_history, migration 077).
 *
 *   rh_price_history_snapshot(PDO $pdo, string $table, int $id, array $fields): array   the values before a save
 *   rh_price_history_log(PDO $pdo, string $entityType, int $entityId, array $changes, ?array $user = null): int
 *       $changes: field => [old, new]; unchanged fields (money within BALANCE_TOLERANCE) are skipped.
 *
 * Never creates the table and never throws: when the table is missing (migration not run yet) or a write fails,
 * nothing is recorded and the save carries on.
 */

if (!function_exists('rh_price_history_ready')) {
    function rh_price_history_ready(PDO $pdo): bool
    {
        static $ready = [];
        $k = spl_object_id($pdo);
        if (!isset($ready[$k])) {
            try {
                $ready[$k] = $pdo->query("SHOW TABLES LIKE 'room_price_history'")->rowCount() > 0;
            } catch (Throwable $e) {
                $ready[$k] = false;
            }
        }
        return $ready[$k];
    }

    /** Current values of $fields on one row ($table from a fixed list), for comparing after the save. */
    function rh_price_history_snapshot(PDO $pdo, string $table, int $id, array $fields): array
    {
        if ($id <= 0 || !in_array($table, ['rooms', 'room_combinations', 'rate_plans'], true) || !rh_price_history_ready($pdo)) {
            return [];
        }
        $cols = array_values(array_filter($fields, function ($f) { return (bool)preg_match('/^[a-z_]{1,64}$/', (string)$f); }));
        if (!$cols) return [];
        try {
            $st = $pdo->prepare('SELECT ' . implode(', ', $cols) . " FROM {$table} WHERE id = ?");
            $st->execute([$id]);
            return $st->fetch(PDO::FETCH_ASSOC) ?: [];
        } catch (Throwable $e) {
            error_log('rh_price_history_snapshot: ' . $e->getMessage());
            return [];
        }
    }

    function rh_price_history_log(PDO $pdo, string $entityType, int $entityId, array $changes, ?array $user = null): int
    {
        if ($entityId <= 0 || !in_array($entityType, ['room_type', 'individual_room', 'room_combination', 'rate_plan'], true) || !rh_price_history_ready($pdo)) {
            return 0;
        }
        if ($user === null) {
            $user = $GLOBALS['user'] ?? [];
        }
        $uid = (int)($user['id'] ?? 0);
        $name = (string)($user['full_name'] ?? ($user['username'] ?? ''));
        $tol = defined('BALANCE_TOLERANCE') ? (float)BALANCE_TOLERANCE : 0.01;
        $n = 0;
        try {
            $ins = $pdo->prepare('INSERT INTO room_price_history (entity_type, entity_id, field, old_value, new_value, changed_by, changed_by_name, changed_at)
                VALUES (?, ?, ?, ?, ?, ?, ?, NOW())');
            foreach ($changes as $field => $pair) {
                [$old, $new] = array_values((array)$pair) + [null, null];
                $o = ($old === null || $old === '') ? null : (string)$old;
                $nv = ($new === null || $new === '') ? null : (string)$new;
                if ($o === $nv) continue;
                if ($o !== null && $nv !== null && is_numeric($o) && is_numeric($nv) && abs((float)$o - (float)$nv) <= $tol) continue;
                $ins->execute([$entityType, $entityId, mb_substr((string)$field, 0, 64),
                    $o === null ? null : mb_substr($o, 0, 255), $nv === null ? null : mb_substr($nv, 0, 255),
                    $uid > 0 ? $uid : null, $name !== '' ? mb_substr($name, 0, 255) : null]);
                $n++;
            }
        } catch (Throwable $e) {
            error_log('rh_price_history_log: ' . $e->getMessage());
        }
        return $n;
    }
}
