<?php
if (PHP_SAPI !== 'cli') { http_response_code(403); exit("CLI only.\n"); } // never runnable over the web
/**
 * cms_content.php - rolled-back checks of the Website & CMS screens and what they drive on the guest site:
 * gallery (cache, hide/delete, linked media rows, files, reorder), section headers (hidden headers,
 * headers that only exist in code), footer links and fallback FAQ, Media Portal inactive filter.
 * Run from the repo root:  php scripts/tests/cms_content.php
 * Page scenarios run in page_harness.php (one never-committing transaction, rolled back). The only
 * things that outlive a run are two throw-away files under images/ and cache files, removed here.
 */
$root = getcwd();
$h = __DIR__ . '/page_harness.php';
$pass = 0; $fail = 0;
function ok(string $name, bool $cond, string $detail = ''): void {
    global $pass, $fail;
    if ($cond) { $pass++; echo "  PASS  $name\n"; } else { $fail++; echo "  FAIL  $name  $detail\n"; }
}
function run_page(string $page, array $post, array $extra = []): array {
    global $h;
    $cfg = base64_encode(json_encode(array_merge(['post' => $post, 'ajax' => false], $extra)));
    $cmd = 'php ' . escapeshellarg($h) . ' ' . escapeshellarg($page) . ' ' . escapeshellarg($cfg) . ' 2>&1';
    $out = shell_exec($cmd);
    if (trim((string)$out) === '') { usleep(500000); $out = shell_exec($cmd); }
    if (preg_match('/@@RESULT@@(.*)$/m', (string)$out, $m)) { $r = json_decode($m[1], true); if (is_array($r)) { return $r; } }
    return ['error' => 'no result', 'raw' => substr((string)$out, -400), 'rolled_back' => false];
}

require_once $root . '/config/database.php';
try { $pdo->exec('SET SESSION wait_timeout = 1800'); } catch (Throwable $e) {}
require_once $root . '/includes/section-headers.php';
require_once $root . '/includes/footer-links.php';

// Cache files are written and checked directly: the live site setting may have caching switched off.
function cache_path(string $key): string { return CACHE_DIR . '/' . getReadableCacheFilename($key); }
function cache_put(string $key): void { if (!is_dir(CACHE_DIR)) { mkdir(CACHE_DIR, 0755, true); } file_put_contents(cache_path($key), json_encode(['key' => $key, 'data' => ['stale'], 'created' => time(), 'expiry' => time() + 3600, 'ttl' => 3600])); }
function cache_has(string $key): bool { clearstatcache(); return is_file(cache_path($key)); }

echo "== Footer links (includes/footer-links.php) ==\n";
$cases = [
    ['https://example.com/menu', false, 'https://example.com/menu'],
    ['mailto:front@desk.test', false, 'mailto:front@desk.test'],
    ['tel:+265123456', false, 'tel:+265123456'],
    ['tel:123', true, 'tel:123'],
    ['images/menu.pdf', false, 'images/menu.pdf'],
    ['rooms-gallery.php', false, 'rooms-gallery.php'],
    ['events', false, 'index.php#events'],
    ['events', true, '#events'],
    ['contact', false, 'contact-us.php#contact-info'],
    ['api/gym.php', false, 'gym.php'],
    // A typed link can never run code or leave via an odd scheme.
    ['javascript:alert(1)', false, ''],
    ['JaVaScRiPt:alert(1)', true, ''],
    ['data:text/html,<b>x</b>', false, ''],
    ['vbscript:msgbox(1)', false, ''],
    ['//evil.example', false, ''],
];
foreach ($cases as [$in, $idx, $want]) {
    ok("footer link '$in' (" . ($idx ? 'home' : 'other page') . ")", footer_normalize_href($in, $idx) === $want, footer_normalize_href($in, $idx));
}
$footerSrc = (string)file_get_contents($root . '/includes/footer.php');
ok('fallback FAQ has no literal HTML tags', strpos($footerSrc, '<strong>Q:') === false && strpos($footerSrc, 'Q: Is parking available?') !== false);
ok('gallery shows no empty image tag', strpos((string)file_get_contents($root . '/includes/hotel-gallery.php'), "elseif (trim((string)resolveImageUrl") !== false);
ok('scaffolded pages link the site CSS', substr_count((string)file_get_contents($root . '/admin/page-management.php'), 'css/main.css') >= 1);

echo "== Section headers: a header switched off shows nothing ==\n";
$pdo->beginTransaction();
try {
    $ins = $pdo->prepare("INSERT INTO section_headers (section_key, page, section_label, section_subtitle, section_title, section_description, display_order, is_active) VALUES (?, ?, 'L', NULL, ?, 'D', 1, ?)");
    $ins->execute(['zz_cms_on', 'zz-cms', 'ZZ On Title', 1]);
    $ins->execute(['zz_cms_off', 'zz-cms', 'ZZ Off Title', 0]);
    $fb = ['label' => 'Fallback', 'title' => 'ZZ Fallback Title', 'description' => ''];
    $on = getSectionHeader('zz_cms_on', 'zz-cms', $fb);
    $off = getSectionHeader('zz_cms_off', 'zz-cms', $fb);
    $none = getSectionHeader('zz_cms_none', 'zz-cms', $fb);
    ok('active header: text from the row', $on['title'] === 'ZZ On Title' && empty($on['hidden']));
    ok('inactive header: marked hidden, not replaced by fallback text', !empty($off['hidden']));
    ok('no row: fallback text still shown', $none['title'] === 'ZZ Fallback Title' && empty($none['hidden']));
    ob_start(); renderSectionHeader('zz_cms_off', 'zz-cms', $fb); $html = ob_get_clean();
    ok('inactive header prints nothing', trim($html) === '', $html);
    ob_start(); renderSectionHeader('zz_cms_on', 'zz-cms', $fb); $html = ob_get_clean();
    ok('active header prints its title', strpos($html, 'ZZ On Title') !== false);
} finally {
    $pdo->rollBack();
}
$left = $pdo->query("SELECT COUNT(*) FROM section_headers WHERE page = 'zz-cms'")->fetchColumn();
ok('section header test rolled back', (int)$left === 0);

echo "== Settings cache is cleared with other content (Media Portal change to a setting) ==\n";
cache_put('setting_zz_cms_probe');
$before = cache_has('setting_zz_cms_probe');
invalidateDataCaches();
ok('single-setting cache dropped by invalidateDataCaches', $before && !cache_has('setting_zz_cms_probe'));

echo "== Section Headers: headers that only exist in code ==\n";
$gone = $pdo->prepare("SELECT COUNT(*) FROM section_headers WHERE section_key = 'contact_main' AND page = 'contact-us'");
$gone->execute();
$hadContact = (int)$gone->fetchColumn() > 0;
$r = run_page('admin/section-headers-management.php', ['action' => 'add_missing_header', 'section_key' => 'contact_main', 'page' => 'contact-us'],
    ['verify' => ["SELECT section_label, section_title, section_description, is_active FROM section_headers WHERE section_key = 'contact_main' AND page = 'contact-us'"]]);
$row = $r['verify'][0][0] ?? null;
ok('contact_main can be added with the wording the page shows today', $hadContact || ($row && $row['section_title'] === 'Contact Us' && $row['section_label'] === 'Get In Touch' && (int)$row['is_active'] === 1), json_encode($r['verify'] ?? $r));
ok('section header add rolled back', !empty($r['rolled_back']));
$r = run_page('admin/section-headers-management.php', ['action' => 'add_missing_header', 'section_key' => 'made_up_key', 'page' => 'nowhere'],
    ['verify' => ["SELECT COUNT(*) AS n FROM section_headers WHERE section_key = 'made_up_key'"]]);
ok('a header no page uses cannot be added', (int)($r['verify'][0][0]['n'] ?? 1) === 0);
$r = run_page('admin/section-headers-management.php', ['action' => 'x'], ['method' => 'GET', 'grep' => $hadContact ? ['section-headers'] : ['Not editable yet', 'guest_services_main']]);
ok('Section Headers page lists headers that are not editable yet', !in_array(false, $r['found'] ?? [false], true), json_encode($r['found'] ?? $r));

echo "== Gallery (admin/gallery-management.php) ==\n";
$dirA = $root . '/images/hotel_gallery';
$fileA = $dirA . '/zz_cms_a.jpg';       // a file the gallery owns
$fileKeep = $root . '/images/zz_cms_keep.jpg'; // a site file somebody pointed a gallery item at
if (!is_dir($dirA)) { mkdir($dirA, 0755, true); }
file_put_contents($fileA, 'x');
file_put_contents($fileKeep, 'x');
$setup = [
    "INSERT INTO hotel_gallery (title, description, image_url, video_path, video_type, category, is_active, display_order) VALUES ('ZZ Cms A', '', 'images/hotel_gallery/zz_cms_a.jpg', NULL, NULL, 'general', 1, 10), ('ZZ Cms B', '', 'images/zz_cms_keep.jpg', NULL, NULL, 'general', 1, 20), ('ZZ Cms C', '', 'images/hotel_gallery/zz_cms_c.jpg', NULL, NULL, 'general', 1, 30)",
    "INSERT INTO managed_media_catalog (title, description, media_type, source_type, media_url, placement_key, is_active, display_order) VALUES ('ZZ Cms A (Image)', '', 'image', 'upload', 'images/hotel_gallery/zz_cms_a.jpg', 'index_hotel_gallery', 1, 10)",
    "INSERT INTO managed_media_links (media_catalog_id, source_table, source_record_id, source_column, source_context, media_type, placement_key, use_case, display_order, is_active) SELECT c.id, 'hotel_gallery', CAST(g.id AS CHAR), 'image_url', '', 'image', 'index_hotel_gallery', 'gallery_image', 10, 1 FROM managed_media_catalog c JOIN hotel_gallery g ON g.title = 'ZZ Cms A' WHERE c.title = 'ZZ Cms A (Image)'",
];
$vars = [
    'a' => "SELECT id FROM hotel_gallery WHERE title = 'ZZ Cms A'",
    'b' => "SELECT id FROM hotel_gallery WHERE title = 'ZZ Cms B'",
    'c' => "SELECT id FROM hotel_gallery WHERE title = 'ZZ Cms C'",
];
$vOrder = "SELECT title, display_order FROM hotel_gallery WHERE title LIKE 'ZZ Cms %' ORDER BY display_order, id";
$vCat = "SELECT is_active FROM managed_media_catalog WHERE title = 'ZZ Cms A (Image)'";
$vLinks = "SELECT COUNT(*) AS n FROM managed_media_links l JOIN managed_media_catalog c ON c.id = l.media_catalog_id WHERE c.title = 'ZZ Cms A (Image)'";

// Cache: put a stale gallery list in the cache, then act; the stale copy must be gone afterwards.
cache_put('gallery_images');
ok('gallery cache primed', cache_has('gallery_images'));
$r = run_page('admin/gallery-management.php', ['action' => 'reorder', 'order' => json_encode(['{c}', '{a}', '{b}'])],
    ['ajax' => true, 'setup' => $setup, 'vars' => $vars, 'verify' => [$vOrder]]);
$titles = array_map(fn($x) => $x['title'], $r['verify'][0] ?? []);
ok('reorder saves the new order', $titles === ['ZZ Cms C', 'ZZ Cms A', 'ZZ Cms B'], json_encode($r['verify'][0] ?? $r));
ok('reorder answers success', ($r['json']['success'] ?? false) === true, json_encode($r['json'] ?? $r));
ok('reorder numbers the items 10, 20, 30', array_map(fn($x) => (int)$x['display_order'], $r['verify'][0] ?? []) === [10, 20, 30]);
ok('reorder cleared the gallery cache', !cache_has('gallery_images'));
ok('reorder test rolled back', !empty($r['rolled_back']) && empty($r['leak']));

$r = run_page('admin/gallery-management.php', ['action' => 'reorder', 'order' => 'not json'], ['ajax' => true, 'setup' => $setup, 'vars' => $vars, 'verify' => [$vOrder]]);
ok('reorder with a bad payload is refused and changes nothing', ($r['json']['success'] ?? true) === false && array_map(fn($x) => $x['title'], $r['verify'][0] ?? []) === ['ZZ Cms A', 'ZZ Cms B', 'ZZ Cms C']);

$r = run_page('admin/gallery-management.php', ['action' => 'reorder', 'order' => json_encode(['{b}']), 'csrf_token' => 'wrong'], ['ajax' => true, 'setup' => $setup, 'vars' => $vars, 'verify' => [$vOrder]]);
ok('reorder without the security token is refused', ($r['json']['success'] ?? true) === false && array_map(fn($x) => $x['title'], $r['verify'][0] ?? []) === ['ZZ Cms A', 'ZZ Cms B', 'ZZ Cms C']);

cache_put('gallery_images');
$r = run_page('admin/gallery-management.php', ['action' => 'toggle_active', 'id' => '{a}'], ['setup' => $setup, 'vars' => $vars, 'verify' => ["SELECT is_active FROM hotel_gallery WHERE title = 'ZZ Cms A'", $vCat]]);
ok('hiding a gallery item hides it', (int)($r['verify'][0][0]['is_active'] ?? 1) === 0, json_encode($r['verify'] ?? $r));
ok('hiding a gallery item switches its media-catalog row off', (int)($r['verify'][1][0]['is_active'] ?? 1) === 0, json_encode($r['verify'][1] ?? $r));
ok('hiding cleared the gallery cache', !cache_has('gallery_images'));

cache_put('gallery_images');
$r = run_page('admin/gallery-management.php', ['action' => 'delete', 'id' => '{a}'], ['setup' => $setup, 'vars' => $vars, 'verify' => ["SELECT COUNT(*) AS n FROM hotel_gallery WHERE title = 'ZZ Cms A'", $vLinks, "SELECT COUNT(*) AS n FROM managed_media_catalog WHERE title = 'ZZ Cms A (Image)'"]]);
ok('delete removes the gallery row', (int)($r['verify'][0][0]['n'] ?? 1) === 0, json_encode($r['verify'] ?? $r));
ok('delete removes the media links', (int)($r['verify'][1][0]['n'] ?? 1) === 0);
ok('delete removes the media-catalog row', (int)($r['verify'][2][0]['n'] ?? 1) === 0);
ok('delete removed the file the gallery stored', !file_exists($fileA));
ok('delete cleared the gallery cache', !cache_has('gallery_images'));
ok('delete test rolled back', !empty($r['rolled_back']) && empty($r['leak']));

$r = run_page('admin/gallery-management.php', ['action' => 'delete', 'id' => '{b}'], ['setup' => $setup, 'vars' => $vars]);
ok('delete never removes a site file the item only pointed at', file_exists($fileKeep));

$r = run_page('admin/gallery-management.php', ['action' => 'update', 'id' => '{a}', 'title' => 'ZZ Cms B', 'category' => 'general', 'display_order' => '10'],
    ['ajax' => true, 'setup' => $setup, 'vars' => $vars, 'verify' => ["SELECT title FROM hotel_gallery WHERE title LIKE 'ZZ Cms%' ORDER BY id"]]);
ok('edit to a duplicate title is refused before anything is stored', ($r['json']['success'] ?? true) === false && count($r['verify'][0] ?? []) === 3, json_encode($r['json'] ?? $r));

echo "== Media Portal: inactive items can be found ==\n";
$mSetup = ["INSERT INTO managed_media_catalog (title, description, media_type, source_type, media_url, placement_key, is_active, display_order) VALUES ('ZZ Cms Inactive Item', '', 'image', 'url', 'https://example.com/zz-cms.jpg', 'zz_cms_placement', 0, 1)"];
$r = run_page('admin/media-management.php', ['x' => '1'], ['method' => 'GET', 'setup' => $mSetup, 'grep' => ['aria-label="Edit ZZ Cms Inactive Item"']]);
ok('default view hides the inactive item', ($r['found']['aria-label="Edit ZZ Cms Inactive Item"'] ?? true) === false, json_encode($r['found'] ?? $r));
$r = run_page('admin/media-management.php', ['x' => '1'], ['method' => 'GET', 'get' => ['filter_status' => 'inactive'], 'setup' => $mSetup, 'grep' => ['aria-label="Edit ZZ Cms Inactive Item"']]);
ok('Inactive filter shows it', ($r['found']['aria-label="Edit ZZ Cms Inactive Item"'] ?? false) === true, json_encode($r['found'] ?? $r));
ok('media page test rolled back', !empty($r['rolled_back']) && empty($r['leak']));

@unlink($fileA);
@unlink($fileKeep);
clearCacheByPattern('gallery_images');

echo "\ncms_content: PASS=$pass FAIL=$fail\n";
exit($fail ? 1 : 0);
