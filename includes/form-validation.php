<?php

/**
 * Shared server-side validation for admin create/edit forms.
 *
 * The schema is locked (no new UNIQUE indexes), so duplicate protection lives here:
 *   - rh_clean_text()       trims and collapses whitespace, so "Deluxe  Room " == "Deluxe Room".
 *   - rh_find_duplicate()   case/space-insensitive lookup of an existing row with the same
 *                           natural key (optionally scoped, excluding the row being edited).
 *   - rh_with_create_lock() runs check + insert under a MySQL named lock, so two people (or a
 *                           double-click) saving the same thing at the same moment cannot both
 *                           pass the duplicate check.
 * Table and column names are always code constants from the caller — never user input.
 */

if (!function_exists('rh_clean_text')) {

    /** Trim, collapse internal whitespace, strip control characters. */
    function rh_clean_text($value): string
    {
        $v = (string)($value ?? '');
        $v = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/u', '', $v) ?? '';
        $v = preg_replace('/\s+/u', ' ', $v) ?? '';
        return trim($v);
    }

    /** Identifier guard for table/column names passed by calling code. */
    function rh_sql_ident(string $name): string
    {
        if (!preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/', $name)) {
            throw new InvalidArgumentException('Invalid identifier');
        }
        return '`' . $name . '`';
    }

    /**
     * Find an existing row whose $column matches $value ignoring case and repeated spaces.
     *
     * @param array      $scope     extra exact-match conditions, column => value (e.g. ['category' => 'Mains'])
     * @param int|null   $excludeId the row being edited (skipped)
     * @param string     $extraSql  optional extra SQL condition written by the caller (no user input), e.g. "deleted_at IS NULL"
     * @return array|null the duplicate row (id + matched column) or null
     */
    function rh_find_duplicate(PDO $pdo, string $table, string $column, string $value, array $scope = [], ?int $excludeId = null, string $idColumn = 'id', string $extraSql = ''): ?array
    {
        $value = rh_clean_text($value);
        if ($value === '') {
            return null;
        }
        $t = rh_sql_ident($table);
        $c = rh_sql_ident($column);
        $i = rh_sql_ident($idColumn);

        // Editing a record without changing its key (name + scope) is never a new duplicate —
        // even if an identical twin already existed before these checks were added.
        if ($excludeId !== null && $excludeId > 0) {
            $cols = [$c . ' AS v'];
            foreach (array_keys($scope) as $k => $col) {
                $cols[] = rh_sql_ident((string)$col) . ' AS s' . $k;
            }
            $cur = $pdo->prepare('SELECT ' . implode(', ', $cols) . " FROM {$t} WHERE {$i} = ? LIMIT 1");
            $cur->execute([$excludeId]);
            $row = $cur->fetch(PDO::FETCH_ASSOC);
            if ($row && mb_strtolower(rh_clean_text((string)$row['v'])) === mb_strtolower($value)) {
                $sameScope = true;
                foreach (array_values($scope) as $k => $val) {
                    if ((string)($row['s' . $k] ?? '') !== (string)($val ?? '')) {
                        $sameScope = false;
                        break;
                    }
                }
                if ($sameScope) {
                    return null;
                }
            }
        }
        $sql = "SELECT {$i} AS id, {$c} AS value FROM {$t}
                WHERE LOWER(TRIM(REGEXP_REPLACE({$c}, '[[:space:]]+', ' '))) = LOWER(?)";
        $params = [$value];
        foreach ($scope as $col => $val) {
            if ($val === null) {
                $sql .= ' AND ' . rh_sql_ident((string)$col) . ' IS NULL';
            } else {
                $sql .= ' AND ' . rh_sql_ident((string)$col) . ' = ?';
                $params[] = $val;
            }
        }
        if ($excludeId !== null && $excludeId > 0) {
            $sql .= " AND {$i} <> ?";
            $params[] = $excludeId;
        }
        if ($extraSql !== '') {
            $sql .= ' AND (' . $extraSql . ')';
        }
        $sql .= ' LIMIT 1';
        try {
            $st = $pdo->prepare($sql);
            $st->execute($params);
        } catch (PDOException $e) {
            // Older MySQL without REGEXP_REPLACE: fall back to trim + case only.
            $sql = str_replace("TRIM(REGEXP_REPLACE({$c}, '[[:space:]]+', ' '))", "TRIM({$c})", $sql);
            $st = $pdo->prepare($sql);
            $st->execute($params);
        }
        $row = $st->fetch(PDO::FETCH_ASSOC);
        if (!$row) {
            return null;
        }
        // 'value' goes straight into error messages, some of which are shown as HTML (toasts):
        // make it inert in any context. 'raw' keeps the stored text for code that needs it.
        $row['raw'] = (string)$row['value'];
        $row['value'] = rh_msg_value($row['raw']);
        return $row;
    }

    /** A stored value made safe to drop into a message, whether the page escapes it or not. */
    function rh_msg_value(string $v): string
    {
        $v = strip_tags($v);
        return str_replace(['<', '>', '"', "'", '`'], ['‹', '›', '”', '’', 'ʻ'], $v);
    }

    /**
     * Run $fn while holding a short MySQL named lock for $name (e.g. 'room_type').
     * Wrap the duplicate check AND the insert/update together inside $fn.
     * If the lock cannot be taken within 10 s the save is refused rather than risking a duplicate.
     */
    function rh_with_create_lock(PDO $pdo, string $name, callable $fn)
    {
        $lockName = 'rh_form_' . preg_replace('/[^a-z0-9_]/i', '_', $name) . '_' . (defined('DB_NAME') ? DB_NAME : 'db');
        $lockName = substr($lockName, 0, 64);
        $got = (int)$pdo->query('SELECT GET_LOCK(' . $pdo->quote($lockName) . ', 10)')->fetchColumn();
        if ($got !== 1) {
            throw new RuntimeException('Someone else is saving the same kind of record right now. Please try again in a moment.');
        }
        try {
            return $fn();
        } finally {
            try {
                $pdo->query('SELECT RELEASE_LOCK(' . $pdo->quote($lockName) . ')');
            } catch (Throwable $e) {
                // the lock is released when the connection closes anyway
            }
        }
    }

    /** Standard duplicate message. */
    function rh_duplicate_message(string $thing, string $value): string
    {
        return 'A ' . $thing . ' called "' . rh_msg_value($value) . '" already exists. Use a different name or edit the existing one.';
    }
}
