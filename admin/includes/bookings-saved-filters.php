<?php
/**
 * Saved filter sets on the Bookings list (plan .claude/BOOKINGS_LIST_PLAN.md T9, Decision 3, spec-check S1 items 6-7).
 * One row per person in admin_user_preferences, key 'bookings_saved_filters_v1', value a JSON list of
 * {"name": "...", "query": "..."} (no migration). The query is never stored as sent: it is rebuilt from the whitelisted
 * list params only (rh_bk_filters_from_get + rh_bk_keep_params, sort only when it is a known sort key), on save AND
 * again on read, so a link printed from it can only carry known keys.
 * Limits: 20 sets a person, a name of 1 to 60 characters (refused, never cut), each query and the whole stored value
 * capped in bytes (refused over the cap). Same name (any letter case) = replace that set; delete is by name.
 * bookings.php reads the list while it renders (view-only people still see and open their sets); the endpoint
 * admin/api/bookings-saved-filters.php only saves and deletes. Tested by scripts/tests/bookings_saved_filters.php.
 */

require_once __DIR__ . '/bookings-list-filters.php';

if (!function_exists('rh_bk_saved_key')) {
    function rh_bk_saved_key(): string
    {
        return 'bookings_saved_filters_v1';
    }

    /** Most sets a person keeps. */
    function rh_bk_saved_max(): int
    {
        return 20;
    }

    /** Longest name, in characters. */
    function rh_bk_saved_name_max(): int
    {
        return 60;
    }

    /** Longest query text as sent (bytes), before it is rebuilt. */
    function rh_bk_saved_raw_max(): int
    {
        return 4000;
    }

    /** Longest rebuilt query kept for one set (bytes). */
    function rh_bk_saved_query_max(): int
    {
        return 1500;
    }

    /** Longest whole stored value (bytes); the column is TEXT (65,535 bytes). */
    function rh_bk_saved_value_max(): int
    {
        return 40000;
    }

    /** A clean name: control characters out, spaces collapsed, 1 to 60 characters, valid UTF-8; null = refused. */
    function rh_bk_saved_name($raw): ?string
    {
        if (!is_string($raw) || $raw === '' || preg_match('//u', $raw) !== 1) {
            return null;
        }
        $n = (string)preg_replace('/[\x00-\x1F\x7F]+/u', ' ', $raw);
        $n = trim((string)preg_replace('/\s+/u', ' ', $n));
        if ($n === '') {
            return null;
        }
        $chars = preg_match_all('/./us', $n);
        return ($chars !== false && $chars <= rh_bk_saved_name_max()) ? $n : null;
    }

    /** Lower-case form for comparing names (same name in any letter case = the same set). */
    function rh_bk_saved_name_cmp(string $name): string
    {
        return function_exists('mb_strtolower') ? mb_strtolower($name, 'UTF-8') : strtolower($name);
    }

    /**
     * The list query rebuilt from whitelisted params only ('' = the plain list); null when the text sent is too long or
     * the rebuilt query is over the cap. Page is never kept (a set opens on page 1).
     */
    function rh_bk_saved_query($raw): ?string
    {
        if (!is_string($raw) || strlen($raw) > rh_bk_saved_raw_max()) {
            return null;
        }
        parse_str(ltrim($raw, '?'), $get);
        $params = rh_bk_keep_params(rh_bk_filters_from_get($get), ['page']);
        $sort = $get['sort'] ?? '';
        if (is_string($sort) && $sort !== '' && isset(rh_bk_sort_map()[$sort])) {
            $params['sort'] = $sort;
            $params['dir'] = (is_string($get['dir'] ?? null) && strtolower((string)$get['dir']) === 'asc') ? 'asc' : 'desc';
        }
        $q = http_build_query($params, '', '&', PHP_QUERY_RFC3986);
        return strlen($q) <= rh_bk_saved_query_max() ? $q : null;
    }

    /**
     * The stored value as a clean list. null / '' (nothing stored) = []. Anything json_decode cannot read as a list
     * (bad JSON, an object, a number) = null: the caller must then leave the stored value alone. Entries that are not a
     * valid {name, query} are left out; every query is rebuilt.
     */
    function rh_bk_saved_decode($stored): ?array
    {
        if ($stored === null || $stored === '') {
            return [];
        }
        if (!is_string($stored)) {
            return null;
        }
        $d = json_decode($stored, true);
        if (!is_array($d) || ($d !== [] && array_keys($d) !== range(0, count($d) - 1))) {
            return null;
        }
        $out = [];
        $seen = [];
        foreach ($d as $e) {
            if (!is_array($e)) {
                continue;
            }
            $name = rh_bk_saved_name($e['name'] ?? null);
            $query = rh_bk_saved_query($e['query'] ?? null);
            if ($name === null || $query === null || isset($seen[rh_bk_saved_name_cmp($name)])) {
                continue;
            }
            $seen[rh_bk_saved_name_cmp($name)] = true;
            $out[] = ['name' => $name, 'query' => $query];
            if (count($out) >= rh_bk_saved_max()) {
                break;
            }
        }
        return $out;
    }

    /**
     * Pure change on a clean list. $action 'save' ($in name, query) or 'delete' ($in name).
     * @return array{ok: bool, status: int, error: string, list: array}
     */
    function rh_bk_saved_change(array $list, string $action, array $in): array
    {
        $no = static function (int $status, string $error) use ($list): array {
            return ['ok' => false, 'status' => $status, 'error' => $error, 'list' => $list];
        };
        $name = rh_bk_saved_name($in['name'] ?? null);
        if ($action !== 'save' && $action !== 'delete') {
            return $no(400, 'Unknown action.');
        }
        if ($name === null) {
            return $no(400, 'Give the filter set a name of 1 to ' . rh_bk_saved_name_max() . ' characters.');
        }
        $at = null;
        foreach ($list as $i => $e) {
            if (rh_bk_saved_name_cmp((string)$e['name']) === rh_bk_saved_name_cmp($name)) {
                $at = $i;
                break;
            }
        }
        if ($action === 'delete') {
            if ($at === null) {
                return $no(404, 'That saved filter set is no longer there.');
            }
            array_splice($list, $at, 1);
            return ['ok' => true, 'status' => 200, 'error' => '', 'list' => array_values($list)];
        }
        $query = rh_bk_saved_query($in['query'] ?? null);
        if ($query === null) {
            return $no(400, 'These filters are too long to save.');
        }
        if ($at !== null) {
            $list[$at] = ['name' => $name, 'query' => $query]; // same name: replace it where it is
        } elseif (count($list) >= rh_bk_saved_max()) {
            return $no(409, 'You already have ' . rh_bk_saved_max() . ' saved filter sets. Delete one first.');
        } else {
            $list[] = ['name' => $name, 'query' => $query];
        }
        return ['ok' => true, 'status' => 200, 'error' => '', 'list' => array_values($list)];
    }

    /** For the page and the endpoint's answer: [{name, url}] (url = bookings.php?<rebuilt query>). */
    function rh_bk_saved_links(array $list): array
    {
        $out = [];
        foreach ($list as $e) {
            $q = (string)($e['query'] ?? '');
            $out[] = ['name' => (string)($e['name'] ?? ''), 'url' => 'bookings.php' . ($q !== '' ? '?' . $q : '')];
        }
        return $out;
    }

    /** The signed-in person's own sets (read only; [] when none, the table is missing or the value cannot be read). */
    function rh_bk_saved_list(PDO $pdo, int $userId): array
    {
        if ($userId <= 0) {
            return [];
        }
        try {
            $st = $pdo->prepare('SELECT preference_value FROM admin_user_preferences WHERE user_id = ? AND preference_key = ? LIMIT 1');
            $st->execute([$userId, rh_bk_saved_key()]);
            $v = $st->fetchColumn();
        } catch (Throwable $e) {
            return [];
        }
        return rh_bk_saved_decode($v === false ? null : $v) ?? [];
    }

    /** True for "table does not exist" (before admin_user_preferences was created). */
    function rh_bk_saved_table_missing(Throwable $e): bool
    {
        if (!$e instanceof PDOException) {
            return false;
        }
        $info = $e->errorInfo;
        return (string)$e->getCode() === '42S02'
            || (is_array($info) && ((string)($info[0] ?? '') === '42S02' || (int)($info[1] ?? 0) === 1146));
    }

    /**
     * Save or delete for $userId (the caller's own id, never one from the request), locked: in one transaction the row
     * is seeded '[]' when missing (INSERT IGNORE), read FOR UPDATE, changed and written back. A stored value that
     * json_decode cannot read as a list is never overwritten. Inside a caller's transaction (tests) it joins that one.
     * @return array{ok: bool, status: int, error: string, list: array}
     */
    function rh_bk_saved_write(PDO $pdo, int $userId, string $action, array $in): array
    {
        $fail = static function (int $status, string $error): array {
            return ['ok' => false, 'status' => $status, 'error' => $error, 'list' => []];
        };
        if ($userId <= 0) {
            return $fail(401, 'Not signed in.');
        }
        $own = !$pdo->inTransaction();
        try {
            if ($own) {
                $pdo->beginTransaction();
            }
            $pdo->prepare('INSERT IGNORE INTO admin_user_preferences (user_id, preference_key, preference_value) VALUES (?, ?, ?)')
                ->execute([$userId, rh_bk_saved_key(), '[]']);
            $st = $pdo->prepare('SELECT preference_value FROM admin_user_preferences WHERE user_id = ? AND preference_key = ? FOR UPDATE');
            $st->execute([$userId, rh_bk_saved_key()]);
            $stored = $st->fetchColumn();
            if ($stored === false) { // the seed did not land (INSERT IGNORE hides why): never report a save that wrote nothing
                if ($own) {
                    $pdo->rollBack();
                }
                return $fail(500, 'Could not save that right now. Please try again.');
            }
            $list = rh_bk_saved_decode($stored);
            if ($list === null) {
                if ($own) {
                    $pdo->rollBack();
                }
                return $fail(409, 'Your saved filter sets could not be read, so nothing was changed. Ask your administrator to check them.');
            }
            $r = rh_bk_saved_change($list, $action, $in);
            if (!$r['ok']) {
                if ($own) {
                    $pdo->rollBack();
                }
                return $r;
            }
            $json = json_encode($r['list'], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
            if (!is_string($json) || strlen($json) > rh_bk_saved_value_max()) {
                if ($own) {
                    $pdo->rollBack();
                }
                return $fail(413, 'Too much to save. Delete a saved filter set first.');
            }
            $pdo->prepare('UPDATE admin_user_preferences SET preference_value = ?, updated_at = NOW() WHERE user_id = ? AND preference_key = ?')
                ->execute([$json, $userId, rh_bk_saved_key()]);
            if ($own) {
                $pdo->commit();
            }
            return $r;
        } catch (Throwable $e) {
            if ($own && $pdo->inTransaction()) {
                $pdo->rollBack();
            }
            if (rh_bk_saved_table_missing($e)) {
                return $fail(503, 'Saved filters are not set up on this system yet.');
            }
            error_log('bookings saved filters: ' . $e->getMessage());
            return $fail(500, 'Could not save that right now. Please try again.');
        }
    }
}
