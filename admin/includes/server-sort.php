<?php
/**
 * Server-side column sort for paginated admin lists (only one page of rows reaches the browser,
 * so a header click must re-query the whole list). The ORDER BY text always comes from the fixed
 * $map below, never from the request; `dir` is only asc|desc.
 *
 * Page:  [$order_sql, $sort_key, $sort_dir] = rh_server_sort_resolve($map, 'p.id DESC', 'p.id DESC');
 * Table: <table data-server-sort="1"> ... rh_server_sort_th('Date', 'date', $sort_key, $sort_dir)
 * Links: array_merge($other_params, rh_server_sort_params($sort_key, $sort_dir)) on every pager link
 *        and rh_server_sort_hidden($sort_key, $sort_dir) inside every filter form.
 * admin-main.js reads data-sort-key, sets sort/dir, drops page and reloads the section.
 */

function rh_server_sort_resolve(array $map, string $default_order, string $tiebreak = ''): array
{
    $key = isset($_GET['sort']) && is_string($_GET['sort']) ? $_GET['sort'] : '';
    if ($key === '' || !isset($map[$key])) {
        return [$default_order, '', 'desc'];
    }
    $dir = (isset($_GET['dir']) && strtolower((string)$_GET['dir']) === 'asc') ? 'asc' : 'desc';
    $order = $map[$key] . ' ' . strtoupper($dir);
    if ($tiebreak !== '') {
        $order .= ', ' . $tiebreak;
    }
    return [$order, $key, $dir];
}

function rh_server_sort_params(string $key, string $dir): array
{
    return $key === '' ? [] : ['sort' => $key, 'dir' => $dir];
}

function rh_server_sort_hidden(string $key, string $dir): string
{
    if ($key === '') {
        return '';
    }
    return '<input type="hidden" name="sort" value="' . htmlspecialchars($key, ENT_QUOTES) . '">'
        . '<input type="hidden" name="dir" value="' . htmlspecialchars($dir, ENT_QUOTES) . '">';
}

/** One sortable <th>. $label_html is trusted markup written in the page (never user data). */
function rh_server_sort_th(string $label_html, string $key, string $active_key, string $active_dir, string $class = '', string $style = ''): void
{
    $is_active = ($key === $active_key && $key !== '');
    $aria  = $is_active ? ($active_dir === 'asc' ? 'ascending' : 'descending') : 'none';
    $icon  = $is_active ? ($active_dir === 'asc' ? 'fa-sort-up' : 'fa-sort-down') : 'fa-sort';
    $class = trim('admin-auto-sortable ' . $class);
    echo '<th class="' . htmlspecialchars($class, ENT_QUOTES) . '" data-sort-key="' . htmlspecialchars($key, ENT_QUOTES) . '"'
        . ' tabindex="0" role="button" aria-sort="' . $aria . '" style="cursor:pointer;' . htmlspecialchars($style, ENT_QUOTES) . '">'
        . $label_html
        . ' <i class="fas ' . $icon . ' admin-sort-icon" aria-hidden="true" style="margin-left:6px;opacity:' . ($is_active ? '0.95' : '0.55') . ';"></i></th>';
}
