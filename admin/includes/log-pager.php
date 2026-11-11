<?php
/**
 * Server-side pager for long lists (System Logs, owner 2026-11-12: "how come system logs have no pagination").
 *
 * One list = one key (e.g. 'ev'): its page is ?{key}_page=, its rows per page ?{key}_size= (10 / 25 / 50 / 100,
 * default 10). The markup matches the client pager in admin/js/admin-section-pagination.js (Prev, numbered pages,
 * Next, "Showing x–y of N", rows picker) so every screen looks the same; mark the list's wrapper
 * data-disable-auto-pagination so the client pager does not page it again.
 *
 * Counting is capped (RH_LP_COUNT_CAP rows, or deeper when an older page is asked for, never past RH_LP_MAX_ROWS):
 * above the cap the summary says "of 10,000+" and Next keeps going while there are more rows. A page past the end is
 * moved to the last page. If the count fails, the rows still show ("count unavailable") with Next while a full page
 * came back.
 *
 * Loading this file connects to nothing. Pure helpers are tested by scripts/tests/log_pager_test.php.
 * Flow: $p = rh_lp_parse($_GET, 'ev'); $total = rh_lp_count($pdo, $countSql, $args); $p = rh_lp_clamp($p, $total);
 *       rows = "... LIMIT {$p['size']} + 1 OFFSET {$p['offset']}"; $p = rh_lp_more($p, count($rows)); echo rh_lp_render(...).
 */

if (!function_exists('rh_lp_parse')) {

    if (!defined('RH_LP_COUNT_CAP')) {
        define('RH_LP_COUNT_CAP', 10000);
    }
    if (!defined('RH_LP_MAX_ROWS')) {
        define('RH_LP_MAX_ROWS', 100000); // deepest row a pager reaches (no huge OFFSET)
    }

    /** The rows-per-page choices (same as the client pager's picker). */
    function rh_lp_sizes(): array
    {
        return [10, 25, 50, 100];
    }

    /**
     * Page and size for one list from the query string. Bad input (missing, 0, negative, text, an array, a huge
     * number) = page 1; a size not on the list = 10. Returns [key, page, size, offset, total, capped, more, pages].
     */
    function rh_lp_parse(array $get, string $key): array
    {
        $rawPage = $get[$key . '_page'] ?? null;
        $page = (is_string($rawPage) || is_int($rawPage)) && preg_match('/^\d{1,6}$/', (string)$rawPage) ? (int)$rawPage : 1;
        if ($page < 1) {
            $page = 1;
        }
        $rawSize = $get[$key . '_size'] ?? null;
        $size = (is_string($rawSize) || is_int($rawSize)) && in_array((int)$rawSize, rh_lp_sizes(), true) && preg_match('/^\d{1,3}$/', (string)$rawSize) ? (int)$rawSize : 10;
        return ['key' => $key, 'page' => $page, 'size' => $size, 'offset' => ($page - 1) * $size,
            'total' => 0, 'capped' => false, 'unknown' => false, 'more' => false, 'pages' => 1];
    }

    /** How deep to count for this page: the usual cap, or as far as the page asked for (at most RH_LP_MAX_ROWS). */
    function rh_lp_cap_for(array $p): int
    {
        return max(RH_LP_COUNT_CAP, min(RH_LP_MAX_ROWS, $p['offset'] + $p['size']));
    }

    /** The last page a pager can reach at this size. */
    function rh_lp_max_page(array $p): int
    {
        return max(1, (int)ceil(RH_LP_MAX_ROWS / $p['size']));
    }

    /**
     * Fit the page to the count. $total is the counted rows (at most $cap + 1; -1 = the count failed). Below the cap
     * the page is moved to the last page; at the cap the total is "10,000+" and paging goes on (rh_lp_more decides
     * Next); a failed count keeps the page and lets the rows decide. Never past RH_LP_MAX_ROWS.
     */
    function rh_lp_clamp(array $p, int $total, int $cap = RH_LP_COUNT_CAP): array
    {
        $p['unknown'] = $total < 0;
        $total = max(0, $total);
        $p['capped'] = !$p['unknown'] && $total > $cap;
        $p['total'] = $p['capped'] ? $cap : $total;
        $p['pages'] = max(1, (int)ceil($p['total'] / $p['size']));
        if ($p['capped'] || $p['unknown']) {
            $p['page'] = min($p['page'], rh_lp_max_page($p));
            $p['pages'] = max($p['pages'], $p['page']);
        } elseif ($p['page'] > $p['pages']) {
            $p['page'] = $p['pages'];
        }
        $p['offset'] = ($p['page'] - 1) * $p['size'];
        $p['more'] = $p['page'] < $p['pages'];
        return $p;
    }

    /**
     * After fetching size + 1 rows: whether there is a next page. Below the cap the count already says so; at the cap
     * the extra row does (and the page count grows with it).
     */
    function rh_lp_more(array $p, int $fetched): array
    {
        if ($p['capped'] || !empty($p['unknown'])) {
            $p['more'] = $fetched > $p['size'] && $p['page'] < rh_lp_max_page($p);
            $p['pages'] = max($p['pages'], $p['page'] + ($p['more'] ? 1 : 0));
        } else {
            $p['more'] = $p['page'] < $p['pages'];
        }
        return $p;
    }

    /**
     * Page a list already in memory (grouped events, sign-in bundles): the page and size come from the query string
     * ({key}_page / {key}_size), then count, move to the last page if past it, slice. Returns [p, rows of the page].
     */
    function rh_lp_slice(array $get, string $key, array $rows): array
    {
        $rows = array_values($rows);
        $p = rh_lp_clamp(rh_lp_parse($get, $key), count($rows), PHP_INT_MAX);
        return [$p, array_slice($rows, $p['offset'], $p['size'])];
    }

    /**
     * Capped COUNT: SELECT COUNT(*) FROM (<$innerSql> LIMIT cap+1). $innerSql selects 1 per row with its WHERE.
     * -1 when the count fails (the list still shows; the pager says the count is unavailable).
     */
    function rh_lp_count(PDO $pdo, string $innerSql, array $args = [], int $cap = RH_LP_COUNT_CAP): int
    {
        try {
            $st = $pdo->prepare('SELECT COUNT(*) FROM (' . $innerSql . ' LIMIT ' . ((int)$cap + 1) . ') rh_lp_x');
            $st->execute($args);
            return (int)$st->fetchColumn();
        } catch (Throwable $e) {
            error_log('log pager count: ' . $e->getMessage());
            return -1;
        }
    }

    /**
     * One page of a list: capped count ($countSql selects 1 per row, same WHERE), clamp, then $rowsSql (ending in its
     * ORDER BY) with LIMIT size+1 OFFSET. Both use $args. Returns [p, rows of this page]. Query errors are thrown to
     * the caller (the page's own try/catch shows an empty list).
     */
    function rh_lp_fetch(PDO $pdo, array $get, string $key, string $countSql, string $rowsSql, array $args = []): array
    {
        $p = rh_lp_parse($get, $key);
        $cap = rh_lp_cap_for($p);
        $p = rh_lp_clamp($p, rh_lp_count($pdo, $countSql, $args, $cap), $cap);
        $st = $pdo->prepare($rowsSql . rh_lp_limit_sql($p));
        $st->execute($args);
        $rows = $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
        $p = rh_lp_more($p, count($rows));
        return [$p, array_slice($rows, 0, $p['size'])];
    }

    /** "LIMIT 11 OFFSET 20": size + 1 rows (to know whether there is more), validated integers only. */
    function rh_lp_limit_sql(array $p): string
    {
        return ' LIMIT ' . ((int)$p['size'] + 1) . ' OFFSET ' . max(0, (int)$p['offset']);
    }

    /** "Showing 11–20 of 1,234" / "Showing 0 of 0" / "Showing 1–10 of 10,000+". $shown = rows on this page. */
    function rh_lp_summary(array $p, int $shown): string
    {
        if (!empty($p['unknown'])) {
            return $shown <= 0 ? 'Showing 0 (count unavailable)'
                : 'Showing ' . number_format($p['offset'] + 1) . "\u{2013}" . number_format($p['offset'] + $shown) . ' (count unavailable)';
        }
        if ($p['total'] <= 0) {
            return 'Showing 0 of 0';
        }
        $of = number_format($p['total']) . ($p['capped'] ? '+' : '');
        if ($shown <= 0) {
            return 'Showing 0 of ' . $of;
        }
        $from = $p['offset'] + 1;
        $to = $p['offset'] + $shown;
        return 'Showing ' . number_format($from) . "\u{2013}" . number_format($to) . ' of ' . $of;
    }

    /** Page numbers around the current one, like the client pager: 1 … 4 5 6 … 20 ('…' = 'ellipsis'). */
    function rh_lp_window(int $page, int $pages, bool $lastKnown = true): array
    {
        $out = [];
        $start = max(1, $page - 2);
        $end = min($pages, $page + 2);
        if ($start > 1) {
            $out[] = 1;
            if ($start > 2) {
                $out[] = 'ellipsis';
            }
        }
        for ($i = $start; $i <= $end; $i++) {
            $out[] = $i;
        }
        if ($end < $pages) {
            if ($end < $pages - 1) {
                $out[] = 'ellipsis';
            }
            $out[] = $pages;
        }
        if (!$lastKnown) {
            $out[] = 'ellipsis'; // more pages past the count cap
        }
        return $out;
    }

    /**
     * The query string kept by pager links and the rows picker: every other plain filter on the page (string values,
     * safe keys), without this list's page, an export or the auto-reload marker.
     */
    function rh_lp_keep(array $get, string $key): array
    {
        $out = [];
        foreach ($get as $k => $v) {
            if (!is_string($k) || !is_string($v) || !preg_match('/^[a-z][a-z0-9_]{0,30}$/', $k)) {
                continue;
            }
            if ($k === $key . '_page' || in_array($k, ['export', 'r', 'action', 'csrf_token'], true)) {
                continue;
            }
            $out[$k] = $v;
        }
        return $out;
    }

    /** A link to page $page of this list, keeping everything else and returning to the list's anchor. */
    function rh_lp_url(string $base, array $get, array $p, int $page, string $anchor): string
    {
        $q = rh_lp_keep($get, $p['key']);
        if ($page > 1) {
            $q[$p['key'] . '_page'] = (string)$page;
        }
        $q[$p['key'] . '_size'] = (string)$p['size'];
        return $base . '?' . http_build_query($q) . ($anchor !== '' ? '#' . $anchor : '');
    }

    /**
     * The pager: Prev, numbered pages, Next, "Showing x–y of N", rows picker (a GET form the site's instant filters
     * submit on change). $note: an extra plain sentence under it (e.g. how a grouped list was built). Escaped here.
     */
    function rh_lp_render(string $base, array $get, array $p, int $shown, string $anchor, string $label = 'List pages', string $note = ''): string
    {
        $h = static function ($s): string {
            return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8');
        };
        if ($p['total'] <= 0 && empty($p['unknown']) && !$p['more'] && $p['page'] <= 1) {
            return ''; // an empty list says so itself; no pager
        }
        $single = !$p['more'] && $p['page'] <= 1;
        $o = '<nav class="bookings-pagination admin-auto-pagination rh-log-pager' . ($single ? ' admin-auto-pagination--single' : '') . '" aria-label="' . $h($label) . '">';
        if ($p['page'] > 1) {
            $o .= '<a class="pg-btn" href="' . $h(rh_lp_url($base, $get, $p, $p['page'] - 1, $anchor)) . '">&lsaquo; Prev</a>';
        } else {
            $o .= '<a class="pg-btn disabled" aria-disabled="true">&lsaquo; Prev</a>';
        }
        foreach (rh_lp_window($p['page'], $p['pages'], !(($p['capped'] || !empty($p['unknown'])) && $p['more'])) as $item) {
            if ($item === 'ellipsis') {
                $o .= '<span class="pg-ellipsis">&hellip;</span>';
            } elseif ($item === $p['page']) {
                $o .= '<span class="pg-current" aria-current="page">' . (int)$item . '</span>';
            } else {
                $o .= '<a class="pg-btn" href="' . $h(rh_lp_url($base, $get, $p, (int)$item, $anchor)) . '">' . (int)$item . '</a>';
            }
        }
        if ($p['more']) {
            $o .= '<a class="pg-btn" href="' . $h(rh_lp_url($base, $get, $p, $p['page'] + 1, $anchor)) . '">Next &rsaquo;</a>';
        } else {
            $o .= '<a class="pg-btn disabled" aria-disabled="true">Next &rsaquo;</a>';
        }
        $o .= '<span class="pg-summary">' . $h(rh_lp_summary($p, $shown)) . '</span>';
        // Rows picker: changing it starts again at page 1 of this list and keeps every other filter.
        $o .= '<form method="get" action="' . $h($base . ($anchor !== '' ? '#' . $anchor : '')) . '" class="pg-size-form" style="display:inline-flex;align-items:center;margin:0;">';
        foreach (rh_lp_keep($get, $p['key']) as $k => $v) {
            if ($k === $p['key'] . '_size') {
                continue;
            }
            $o .= '<input type="hidden" name="' . $h($k) . '" value="' . $h($v) . '">';
        }
        $o .= '<label class="pg-size">Show <select name="' . $h($p['key'] . '_size') . '" aria-label="Rows per page" style="min-height:44px;">';
        foreach (rh_lp_sizes() as $n) {
            $o .= '<option value="' . $n . '"' . ($n === $p['size'] ? ' selected' : '') . '>' . $n . '</option>';
        }
        $o .= '</select> per page</label><noscript><button type="submit" class="pg-btn">Show</button></noscript></form>';
        $o .= '</nav>';
        if ($note !== '') {
            $o .= '<p class="log-events__foot">' . $h($note) . '</p>';
        }
        return $o;
    }

    /** The note under a capped count. */
    function rh_lp_cap_note(array $p): string
    {
        if (!empty($p['unknown'])) {
            return 'The total could not be counted just now; Next keeps going while there are more rows.';
        }
        return $p['capped'] ? 'Counting stops at ' . number_format($p['total']) . ' rows; Next keeps going to older entries (up to ' . number_format(RH_LP_MAX_ROWS) . ').' : '';
    }
}
