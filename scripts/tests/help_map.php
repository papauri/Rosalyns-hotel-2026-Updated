<?php
if (PHP_SAPI !== 'cli') { http_response_code(403); exit("CLI only.\n"); }

/**
 * The round ? helper and the Help button: every admin menu page and every sub-page must open a
 * guide section that exists, hand-named sections must point at real anchors, and the hint text
 * must not carry stale words. Read-only (reads code and docs/guides, no writes).
 */
$root = dirname(__DIR__, 2);
chdir($root);
require $root . '/config/database.php';

// Judge the guides as if every module were switched on, so the answer does not depend on live settings.
$src = (string)file_get_contents($root . '/includes/guide-knowledge-base.php');
$src = str_replace(['<?php', 'declare(strict_types=1);', '__DIR__', "function rh_kb_module_state(): ?array\n{"],
    ['', '', "'" . $root . "/includes'", "function rh_kb_module_state(): ?array\n{ return null;"], $src);
if (strpos($src, 'return null;') === false) { echo "FAIL could not neutralise module state\n"; exit(1); }
eval($src);
require $root . '/includes/guide-system-knowledge.php';
rh_kb_sys_load();

$pass = 0; $fail = 0;
$ok = function (bool $c, string $msg) use (&$pass, &$fail) {
    if ($c) { $pass++; } else { $fail++; echo "FAIL $msg\n"; }
};
$idsOf = function (string $file): ?array {
    static $memo = [];
    if (!array_key_exists($file, $memo)) {
        $p = dirname(__DIR__, 2) . '/docs/guides/' . $file;
        $memo[$file] = is_file($p) ? (preg_match_all('/\bid="([^"]+)"/', (string)file_get_contents($p), $m) ? array_flip($m[1]) : []) : null;
    }
    return $memo[$file];
};

$index = rh_kb_index();
$pages = [];
foreach (rh_kb_sys_menu() as $p) { $pages[$p['page']] = true; }
foreach (array_keys(rh_admin_parent_pages()) as $p) { $pages[$p] = true; }
foreach (array_keys($pages) as $pg) {
    $ok(is_file($root . '/admin/' . $pg), "$pg: menu page file missing");
    $secs = array_values(array_filter(rh_kb_sys_help_for_page($pg, $index, 14), fn($e) => $e['type'] !== 'problem'));
    $ok(count($secs) > 0, "$pg: Help opens no guide section");
    foreach ($secs as $e) {
        $f = preg_replace('/[?#].*/', '', (string)$e['file']);
        $ids = $idsOf($f);
        $ok($ids !== null, "$pg: guide file $f missing");
        if ($ids !== null && $e['anchor'] !== '') { $ok(isset($ids[$e['anchor']]), "$pg: $f has no #" . $e['anchor']); }
    }
}
foreach (rh_kb_sys_page_guides() as $pg => [$file, $anchor]) {
    $ids = $idsOf($file);
    $ok($ids !== null && isset($ids[$anchor]), "named section for $pg: $file#$anchor does not exist");
    $secs = rh_kb_sys_help_for_page($pg, $index, 3);
    $ok($secs && $secs[0]['file'] === $file && $secs[0]['anchor'] === $anchor, "$pg: named section is not the first one offered");
}

// Hint text: no stale or banned wording.
foreach (rh_kb_sys_hints() as $pg => $hints) {
    foreach ($hints as [$t, $x]) {
        $ok(!preg_match('/\bMRA\b|auto-?create|\bwand\b|\bsmart\b|\bAI\b/i', $t . ' ' . $x), "$pg: stale hint wording: $t");
    }
}
$main = (string)file_get_contents($root . '/admin/js/admin-main.js');
$ok(!preg_match('/auto-?create|\bMRA\b/i', $main), 'admin-main.js: stale hint wording');

// The ? key must not fire while typing.
$tips = (string)file_get_contents($root . '/admin/includes/help-tooltips.php');
$ok(strpos($tips, "closest('input, textarea") !== false, 'help-tooltips.php: the ? key is not ignored inside text boxes');

echo "PASS=$pass FAIL=$fail\n";
exit($fail ? 1 : 0);
