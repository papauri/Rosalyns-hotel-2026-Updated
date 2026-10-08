<?php

/**
 * includes/guide-system-knowledge.php
 *
 * Knowledge that comes straight from the system's own code, so it can never drift from it:
 *   - the admin menu        (admin/includes/admin-nav-items.php)
 *   - permissions and roles (admin/includes/permissions.php: getAllPermissions(), getAllRoles())
 *   - modules               (admin/module-settings.php labels; page gating from getModuleForPage())
 *
 * Feeds the knowledge base (includes/guide-knowledge-base.php) with "where is…", "what
 * permission…", "what can a … do" answers, and renders docs/guides/system-map.php,
 * including the per-page help that the admin's Help button opens.
 * Reads code only; no database.
 */

declare(strict_types=1);

/** Files whose content this knowledge is generated from (for the index cache signature). */
function rh_kb_sys_sources(): array
{
    $root = dirname(__DIR__);
    return array_merge([
        __FILE__,
        $root . '/admin/includes/admin-nav-items.php',
        $root . '/admin/includes/permissions.php',
        $root . '/admin/module-settings.php',
    ], rh_kb_sys_hint_files());
}

/** Admin pages that carry on-screen hints (data-help="Title|What it does"). */
function rh_kb_sys_hint_files(): array
{
    static $memo = null;
    if ($memo === null) {
        $memo = [];
        foreach (glob(dirname(__DIR__) . '/admin/*.php') ?: [] as $f) {
            if (strpos((string)@file_get_contents($f), 'data-help="') !== false) {
                $memo[] = $f;
            }
        }
    }
    return $memo;
}

/**
 * The on-screen hints written into the admin pages: page => [[title, text], ...].
 * Only static hints are taken; one built by PHP at run time is skipped.
 */
function rh_kb_sys_hints(): array
{
    $out = [];
    foreach (rh_kb_sys_hint_files() as $f) {
        $page = basename($f);
        if ($page === 'help-tooltips.php') {
            continue; // the hints switch itself
        }
        if (!preg_match_all('/data-help="([^"<>]+)"/', (string)@file_get_contents($f), $m)) {
            continue;
        }
        foreach ($m[1] as $raw) {
            $raw = html_entity_decode($raw, ENT_QUOTES | ENT_HTML5, 'UTF-8');
            if (strpos($raw, '|') === false || strpos($raw, '...') !== false || strpos($raw, '<?') !== false) {
                continue;
            }
            [$title, $text] = array_map('trim', explode('|', $raw, 2));
            if ($title !== '' && $text !== '') {
                $out[$page][$title . '|' . $text] = [$title, $text];
            }
        }
    }
    return array_map('array_values', $out);
}

function rh_kb_sys_load(): bool
{
    $root = dirname(__DIR__);
    if (!function_exists('rh_admin_nav_groups')) {
        require_once $root . '/admin/includes/admin-nav-items.php';
    }
    if (!function_exists('getAllPermissions')) {
        require_once $root . '/admin/includes/permissions.php';
    }
    return function_exists('rh_admin_nav_groups') && function_exists('getAllPermissions') && function_exists('getAllRoles');
}

/** Module key => ['label', 'desc'], read from the Modules settings page plus the derived keys. */
function rh_kb_sys_modules(): array
{
    static $memo = null;
    if ($memo !== null) {
        return $memo;
    }
    $memo = [];
    $src = (string)@file_get_contents(dirname(__DIR__) . '/admin/module-settings.php');
    if (preg_match_all("/'(\w+)'\s*=>\s*\[[^\]]*?'label'\s*=>\s*'([^']+)'[^\]]*?'desc'\s*=>\s*'([^']+)'/s", $src, $m, PREG_SET_ORDER)) {
        foreach ($m as $row) {
            $memo[$row[1]] = ['label' => $row[2], 'desc' => $row[3]];
        }
    }
    // Keys checked by rh_module_key_enabled() that are not rows on the Modules page.
    $memo += [
        'events' => ['label' => $memo['events_page']['label'] ?? 'Events Page', 'desc' => $memo['events_page']['desc'] ?? 'Guest events page and event bookings.'],
        'billing' => ['label' => 'Bookings, Conference or Gym', 'desc' => 'Invoices and quotations exist for businesses that bill a named client in advance (rooms, conferences, gym memberships).'],
        'advance_booking' => ['label' => 'Bookings or Conference', 'desc' => 'Credit notes exist for businesses that take deposits against future stays or events.'],
    ];
    return $memo;
}

/** "the Bookings & Reservations module is on" for a page's module key(s); '' when not gated. */
function rh_kb_sys_module_phrase($module): string
{
    if ($module === null || $module === '' || $module === []) {
        return '';
    }
    $mods = rh_kb_sys_modules();
    $names = [];
    foreach ((array)$module as $k) {
        $names[] = $mods[$k]['label'] ?? ucwords(str_replace('_', ' ', (string)$k));
    }
    return implode(' and ', array_unique($names));
}

/** Role labels that have a permission by default (Administrator always). */
function rh_kb_sys_roles_with(string $perm): array
{
    $out = [];
    foreach (getAllRoles() as $role) {
        if ($role['permissions'] === null || in_array($perm, (array)$role['permissions'], true)) {
            $out[] = $role['label'];
        }
    }
    return $out;
}

/**
 * Menu items whose label depends on the restaurant setting, read from the menu file:
 * page => [label with the restaurant on, label with it off] ("Menu" / "Products").
 */
function rh_kb_sys_label_variants(): array
{
    $out = [];
    $src = (string)@file_get_contents(dirname(__DIR__) . '/admin/includes/admin-nav-items.php');
    foreach (preg_split('/\R/', $src) as $line) {
        if (!preg_match("/^\s*\['([\w.-]+\.php)'/", $line, $pm)) {
            continue;
        }
        if (preg_match_all("/\?\s*'([^']+)'\s*:\s*'([^']+)'/", $line, $tm, PREG_SET_ORDER)) {
            foreach ($tm as $t) {
                if (strpos($t[1], 'fa-') === false) { // skip the icon ternary
                    $out[$pm[1]] = [$t[1], $t[2]];
                }
            }
        }
    }
    return $out;
}

/** Every menu item with its location, permission, module and default roles. */
function rh_kb_sys_menu(): array
{
    static $memo = null;
    if ($memo !== null) {
        return $memo;
    }
    $memo = [];
    if (!rh_kb_sys_load()) {
        return $memo;
    }
    $perms = getAllPermissions();
    $variants = rh_kb_sys_label_variants();
    foreach (rh_admin_nav_groups() as $group => $items) {
        foreach ($items as $it) {
            $href = (string)($it[0] ?? '');
            if ($href === '' || strpos($href, '../') === 0) {
                continue; // links out of the admin (the guides themselves)
            }
            $perm = $it[3] ?? null;
            $memo[] = [
                'page' => $href,
                'label' => $variants[$href][0] ?? (string)$it[2],
                'alias' => $variants[$href][1] ?? '',
                'group' => (string)$group,
                'perm' => $perm,
                'perm_label' => $perm !== null ? ($perms[$perm]['label'] ?? $perm) : '',
                'perm_desc' => $perm !== null ? ($perms[$perm]['description'] ?? '') : '',
                'module' => $it[5] ?? null,
                'module_text' => rh_kb_sys_module_phrase($it[5] ?? null),
                'roles' => $perm !== null ? rh_kb_sys_roles_with($perm) : ['Everyone who can sign in'],
            ];
        }
    }
    return $memo;
}

/**
 * The module a page needs: its own menu entry, else its parent page's, else its file-name family
 * (gym-*, stock-*). Null when the page is not module-gated.
 */
function rh_kb_sys_page_module(string $page, array $menuModules)
{
    if (!empty($menuModules[$page])) {
        return $menuModules[$page];
    }
    $parent = rh_kb_sys_parent_page($page);
    if ($parent !== null && !empty($menuModules[$parent])) {
        return $menuModules[$parent];
    }
    static $byPage = ['room-management.php' => 'bookings', 'room-dashboard.php' => 'bookings', 'restaurant-tables.php' => 'pos',
        'kds.php' => ['pos', 'station_kds'], 'kds-report.php' => ['pos', 'station_kds'], 'bds.php' => ['pos', 'station_bds'],
        'cds.php' => ['pos', 'station_cds'], 'room-service-dashboard.php' => ['pos', 'station_room_service']];
    if (isset($byPage[$page])) {
        return $byPage[$page];
    }
    foreach (['gym-' => 'gym', 'stock-' => 'stock', 'conference-' => 'conference', 'housekeeping' => 'housekeeping'] as $prefix => $mod) {
        if (strpos($page, $prefix) === 0) {
            return $mod;
        }
    }
    return null;
}

/** The menu page a sub-page belongs to (from admin-nav-items.php), or null. */
function rh_kb_sys_parent_page(string $page): ?string
{
    if (!function_exists('rh_admin_parent_pages')) {
        require_once dirname(__DIR__) . '/admin/includes/admin-nav-items.php';
    }
    $map = function_exists('rh_admin_parent_pages') ? rh_admin_parent_pages() : [];
    $parent = $map[$page] ?? null;
    return $parent !== null && $parent !== $page ? $parent : null;
}

function rh_kb_sys_anchor(string $kind, string $key): string
{
    return $kind . '-' . preg_replace('/[^a-z0-9]+/', '-', strtolower(preg_replace('/\.php$/', '', $key)));
}

/** Plain-language sentence for where a menu page lives and who sees it. */
function rh_kb_sys_page_answer(array $p): string
{
    $s = 'Admin menu → ' . $p['group'] . ' → ' . $p['label'] . ' (/admin/' . $p['page'] . ')'
        . ($p['alias'] !== '' ? ', called ' . $p['alias'] . ' when the restaurant is switched off' : '') . '. ';
    $s .= $p['perm'] !== null
        ? 'Needs the "' . $p['perm_label'] . '" permission' . ($p['perm_desc'] !== '' ? ' (' . rtrim($p['perm_desc'], '.') . ')' : '') . '. '
        : 'Every signed-in member of staff can open it. ';
    if ($p['perm'] !== null) {
        $s .= 'Given by default to: ' . implode(', ', $p['roles']) . '. ';
    }
    if ($p['module_text'] !== '') {
        $s .= 'Only shown while ' . $p['module_text'] . ' is switched on in Settings → Modules. ';
    }
    return trim($s);
}

/**
 * Knowledge-base entries generated from the code. Each links to its card on system-map.php.
 * Types: page (menu item), permission, role, module.
 */
function rh_kb_sys_entries(): array
{
    if (!rh_kb_sys_load()) {
        return [];
    }
    $file = 'system-map.php';
    $guide = 'System map';
    $entries = [];
    $pageModules = [];
    foreach (rh_kb_sys_menu() as $p) {
        $pageModules[$p['page']] = $p['module'];
    }

    foreach (rh_kb_sys_menu() as $p) {
        $entries[] = [
            'type' => 'page', 'file' => $file, 'guide' => $guide, 'anchor' => rh_kb_sys_anchor('page', $p['page']),
            'title' => $p['label'], 'parent' => $p['group'] . ' menu',
            'text' => 'Where is ' . $p['label'] . '? Where do I find ' . $p['label'] . '? ' . rh_kb_sys_page_answer($p),
            'page' => $p['page'], 'module' => $p['module'],
        ];
    }

    $menuByPage = [];
    foreach (rh_kb_sys_menu() as $p) {
        $menuByPage[$p['page']] = $p;
    }
    foreach (getAllPermissions() as $key => $info) {
        $roles = rh_kb_sys_roles_with($key);
        $page = (string)($info['page'] ?? '');
        $where = isset($menuByPage[$page]) ? ' It opens ' . $menuByPage[$page]['group'] . ' → ' . $menuByPage[$page]['label'] . '.' : '';
        $entries[] = [
            'type' => 'permission', 'file' => $file, 'guide' => $guide, 'anchor' => rh_kb_sys_anchor('perm', $key),
            'title' => $info['label'], 'parent' => 'Permission · ' . ($info['category'] ?? ''),
            'module' => rh_kb_sys_page_module((string)($info['page'] ?? ''), $pageModules),
            'text' => 'Permission "' . $info['label'] . '": ' . rtrim((string)($info['description'] ?? ''), '.') . '.' . $where
                . ' Who has it by default: ' . implode(', ', $roles) . '. Administrators always have every permission.'
                . ' To give or take it away: Settings → Staff & Access → the person\'s Permissions (access, rights, allow, grant).',
        ];
    }

    $perms = getAllPermissions();
    foreach (getAllRoles() as $key => $role) {
        $list = $role['permissions'] === null ? ['every permission'] : array_map(static function ($k) use ($perms) {
            return $perms[$k]['label'] ?? $k;
        }, (array)$role['permissions']);
        $entries[] = [
            'type' => 'role', 'file' => $file, 'guide' => $guide, 'anchor' => rh_kb_sys_anchor('role', $key),
            'title' => $role['label'] . ' role', 'parent' => 'Role',
            'module' => ['receptionist' => 'bookings', 'housekeeping' => 'housekeeping', 'restaurant_staff' => 'pos', 'chef' => 'station_kds',
                'bar_staff' => 'station_bds', 'coffee_staff' => 'station_cds', 'room_service' => 'station_room_service',
                'gym_staff' => 'gym', 'conference_staff' => 'conference'][$key] ?? null,
            'text' => 'What can a ' . $role['label'] . ' do? ' . rtrim((string)$role['description'], '.') . '. Starts with: '
                . implode(', ', $list) . '. An administrator can add or remove single permissions for one person in Staff & Access.',
        ];
    }

    // On-screen hints: what a button or field on a page does, in the words of the page itself.
    $pageLabels = [];
    foreach (rh_kb_sys_menu() as $p) {
        $pageLabels[$p['page']] = $p['label'];
    }
    foreach ($perms as $info) {
        if (!empty($info['page']) && !isset($pageLabels[$info['page']])) {
            $pageLabels[$info['page']] = $info['label'];
        }
    }
    foreach (rh_kb_sys_hints() as $page => $hints) {
        $where = $pageLabels[$page] ?? ucwords(str_replace(['-', '.php'], [' ', ''], $page));
        foreach ($hints as [$title, $text]) {
            $entries[] = [
                'type' => 'hint', 'file' => $file . '?page=' . $page, 'guide' => 'On-screen help', 'anchor' => 'hints',
                'title' => $title, 'parent' => $where . ' page', 'text' => $text, 'page' => $page, 'module' => rh_kb_sys_page_module($page, $pageModules),
            ];
        }
    }

    $menu = rh_kb_sys_menu();
    foreach (rh_kb_sys_modules() as $key => $mod) {
        if (in_array($key, ['events', 'billing', 'advance_booking'], true)) {
            continue;
        }
        $pages = [];
        foreach ($menu as $p) {
            if (in_array($key, (array)$p['module'], true) || ($key === 'events_page' && in_array('events', (array)$p['module'], true))) {
                $pages[] = $p['label'];
            }
        }
        $entries[] = [
            'type' => 'module', 'file' => $file, 'guide' => $guide, 'anchor' => rh_kb_sys_anchor('module', $key),
            'title' => $mod['label'], 'parent' => 'Module',
            'text' => 'Module ' . $mod['label'] . ': ' . rtrim($mod['desc'], '.') . '.'
                . ($pages ? ' Menu items hidden when it is off: ' . implode(', ', $pages) . '.' : '')
                . ' Switched on and off in Settings → Modules (administrators).',
        ];
    }
    return $entries;
}

/**
 * Guide sections and problems that explain a page, best first: sections naming the page's file,
 * then sections titled with its label, then problems in those guides that name it.
 */
function rh_kb_sys_help_for_page(string $page, array $index, int $limit = 10): array
{
    $label = '';
    foreach (rh_kb_sys_menu() as $p) {
        if ($p['page'] === $page) {
            $label = $p['label'];
        }
    }
    $fileRe = '/(^|[^\w-])' . preg_quote($page, '/') . '\b/i';
    $entries = $index['entries'];
    if (function_exists('rh_kb_module_state')) { // guides for switched-off modules are not offered
        $kbState = rh_kb_module_state();
        $entries = array_filter($entries, static function (array $e) use ($kbState): bool {
            return rh_kb_entry_allowed($e, $kbState);
        });
    }

    // 1. The guide(s) written for this page: their facts box ("Where to find it") names the file.
    $primary = [];
    foreach ($entries as $e) {
        if ($e['type'] === 'section' && $e['anchor'] === '' && preg_match($fileRe, $e['text'])) {
            $primary[$e['file']] = true;
        }
    }
    $out = [];
    $seen = [];
    $take = static function (array $e) use (&$out, &$seen): void {
        $key = $e['file'] . '#' . $e['anchor'] . '|' . $e['title'];
        if (!isset($seen[$key])) {
            $seen[$key] = true;
            $out[] = $e;
        }
    };
    // 0. A page outside the menu (Edit Booking, Gym Check-In) has no menu label to match on:
    //    lead with the knowledge base's own best sections for the page's name.
    if ($label === '' && function_exists('rh_kb_search')) {
        $name = str_replace(['-', '.php'], [' ', ''], $page);
        $hits = rh_kb_search($name, 6)['results'];
        $top = $hits ? (float)$hits[0]['score'] : 0.0;
        foreach ($hits as $hit) {
            // Only answers about as good as the best one: synonyms ("edit" ~ "change") drift fast.
            if ($hit['type'] === 'section' && $hit['anchor'] !== '' && count($out) < 3 && (float)$hit['score'] >= 0.6 * $top) {
                unset($hit['score'], $hit['snippet'], $hit['url']);
                $take($hit);
            }
        }
    }
    // Its sections (not the reference lists at the end): first those about this exact page (a guide
    // can cover several, e.g. Bookings and Calendar), then the rest in reading order.
    $labelRe = $label !== '' ? '/\b' . preg_quote($label, '/') . '\b/i' : null;
    foreach ([2, 1, 0] as $pass) { // 2 = names the file, 1 = titled with the page's name, 0 = the rest
        foreach ($entries as $e) {
            if (!isset($primary[$e['file']]) || $e['type'] !== 'section' || $e['anchor'] === '' || !empty($e['heading'])
                || in_array($e['anchor'], ['rules', 'problems'], true)) {
                continue;
            }
            $rank = preg_match($fileRe, $e['text']) ? 2 : (($labelRe && preg_match($labelRe, $e['title'])) ? 1 : 0);
            if ($rank === $pass) {
                $take($e);
            }
        }
    }
    // 2. Sections elsewhere about the page: titled with its name, naming its menu path
    //    ("Stock → Stock Count") or its file. Best first; reading order breaks ties.
    $group = '';
    foreach (rh_kb_sys_menu() as $p) {
        if ($p['page'] === $page) {
            $group = $p['group'];
        }
    }
    $pathRe = $label !== '' ? '/' . ($group !== '' ? preg_quote($group, '/') . '\s*→\s*' : '→\s*') . preg_quote($label, '/') . '\b/iu' : null;
    $candidates = [];
    foreach ($entries as $n => $e) {
        if (!in_array($e['type'], ['section', 'faq'], true) || isset($primary[$e['file']])) {
            continue;
        }
        $score = 0;
        if ($labelRe && preg_match($labelRe, $e['title'])) {
            // "Check-In" (Gym) must not pull in the room check-in sections: the guide has to belong
            // to the page's menu group, unless the section also names the page's path or file.
            $groupWord = strtolower((string)strtok($group, ' &'));
            $score += ($groupWord === '' || stripos($e['guide'] . ' ' . $e['text'], $groupWord) !== false) ? 4 : 1;
        }
        if ($pathRe && preg_match($pathRe, $e['text'])) {
            $score += 3;
        }
        if (preg_match($fileRe, $e['text'])) {
            $score += 2;
        }
        if ($score >= 2) {
            $candidates[] = [$score, $n, $e];
        }
    }
    usort($candidates, static function ($a, $b) {
        return [$b[0], $a[1]] <=> [$a[0], $b[1]];
    });
    foreach ($candidates as $c) {
        $take($c[2]);
    }
    // 4. A page opened from another one (Booking Details from Bookings) is explained in its
    //    parent's guide: add the parent's sections when the page has few of its own.
    $parent = rh_kb_sys_parent_page($page);
    if ($parent !== null && count($out) < 3) {
        foreach (rh_kb_sys_help_for_page($parent, $index, $limit) as $pe) {
            if ($pe['type'] !== 'problem') {
                $take($pe);
            }
        }
    }
    $sections = array_slice($out, 0, $limit);

    // 3. Problems: rows of the primary guide's table, then rows anywhere that name the page.
    $problems = [];
    foreach ($entries as $e) {
        if ($e['type'] === 'problem' && (isset($primary[$e['file']]) || preg_match($fileRe, $e['text']))) {
            $problems[] = $e;
        }
    }
    return array_merge($sections, array_slice($problems, 0, 10));
}
