<?php
if (PHP_SAPI !== 'cli') { http_response_code(403); exit("CLI only.\n"); } // never runnable over the web
/**
 * Bookings list over real HTTP (plan .claude/BOOKINGS_LIST_PLAN.md T3). Liwonde (the test platform) only; refuses a DB
 * listed in .claude/LIVE_TARGETS and any site that is not Liwonde. Read-only: it only GETs pages and exports (a page view
 * does what it always does, e.g. the tentative-expiry sweep); activity-log rows this run's user wrote are removed.
 *
 *   php scripts/tests/bookings_list_http.php --db --record=<file>    store what the list shows for every shape (run at HEAD)
 *   php scripts/tests/bookings_list_http.php --db --baseline=<file>  compare with that file + the T3 checks below
 *   php scripts/tests/bookings_list_http.php --db                    the T3 checks only
 *
 * Shapes: every existing deep link into bookings.php (params action, booking_id, filter, status, type, search, flash,
 * date_from, date_to, date_type, arrival, departure, payment_status, balance, export; plus filter_status, since,
 * since_kind, kind, room_type, pay, sort, dir, page, size, unknown params) and the new ?room= / ?group= / ?source= /
 * ?created_by= / sort=balance. Per shape (size=all): the booking ids in screen order, the "N matching" count, the kept
 * hidden inputs, the pager and insight-card links, and the CSV export's references in order.
 * T3 checks: screen ids (order included) = the shared SQL (rh_bk_list_scope + the list's ORDER BY), export rows = list
 * count and export order = screen order (also across size=10 pages), CSV Balance due = rh_bk_balance_due() per row,
 * unknown params ignored, source filter splits the list exactly, Back to Bookings (bookings_list_return) reproduces
 * the list, assign-room on a cancelled booking still goes back to booking-details.
 */
$root = dirname(__DIR__, 2);
chdir($root);
$opt = ['db' => false, 'record' => '', 'baseline' => ''];
foreach (array_slice($argv, 1) as $a) {
    if ($a === '--db') { $opt['db'] = true; }
    elseif (strpos($a, '--record=') === 0) { $opt['record'] = substr($a, 9); }
    elseif (strpos($a, '--baseline=') === 0) { $opt['baseline'] = substr($a, 11); }
}
if (!$opt['db']) { echo "bookings_list_http: needs --db (Liwonde only). Nothing run.\n"; exit(0); }
if (!function_exists('curl_init')) { echo "bookings_list_http: php curl extension missing. Nothing run.\n"; exit(1); }
if (stripos($root, 'Liwonde') === false) { fwrite(STDERR, "Refused: runs in the Liwonde repo only.\n"); exit(1); }

require $root . '/config/database.php';
$db = (string)$pdo->query('SELECT DATABASE()')->fetchColumn();
$live = (string)@file_get_contents($root . '/.claude/LIVE_TARGETS');
if ($db === '' || stripos($live, $db) !== false) { fwrite(STDERR, "Refused: $db is a live target.\n"); exit(1); }
if (stripos((string)getSetting('site_name', ''), 'liwonde') === false) { fwrite(STDERR, "Refused: site is not Liwonde.\n"); exit(1); }
require_once $root . '/admin/includes/bookings-list-filters.php';
$t3 = function_exists('rh_bk_list_scope'); // false at HEAD (record run)

$pass = 0; $fail = 0;
function check(bool $c, string $l, string $d = ''): void { global $pass, $fail; if ($c) { $pass++; } else { $fail++; echo "FAIL $l" . ($d !== '' ? ": $d" : '') . "\n"; } }

// ---- Fixture values (same queries in the record and the compare run)
$one = static function (string $sql) use ($pdo) { $v = $pdo->query($sql)->fetchColumn(); return $v === false ? '' : (string)$v; };
$firstBk = $pdo->query("SELECT id, booking_reference, guest_name FROM bookings WHERE deleted_at IS NULL ORDER BY id LIMIT 1")->fetch(PDO::FETCH_ASSOC);
$ref = (string)$firstBk['booking_reference'];
$nameParts = preg_split('/\s+/', trim((string)$firstBk['guest_name']));
$word = (string)end($nameParts);
$rtId = $one("SELECT room_id FROM bookings WHERE deleted_at IS NULL AND room_id IS NOT NULL GROUP BY room_id ORDER BY COUNT(*) DESC, room_id LIMIT 1");
$confirmedId = $one("SELECT id FROM bookings WHERE status = 'confirmed' AND deleted_at IS NULL ORDER BY id LIMIT 1");
$cancelledId = $one("SELECT id FROM bookings WHERE status = 'cancelled' AND deleted_at IS NULL ORDER BY id LIMIT 1");
$inHouseId = $one("SELECT id FROM bookings WHERE status = 'checked-in' AND deleted_at IS NULL ORDER BY id LIMIT 1");
$roomId = $one("SELECT individual_room_id FROM bookings WHERE individual_room_id IS NOT NULL AND deleted_at IS NULL GROUP BY individual_room_id ORDER BY COUNT(*) DESC, individual_room_id LIMIT 1");
$groupId = $one("SELECT primary_booking_id FROM bookings WHERE primary_booking_id IS NOT NULL ORDER BY primary_booking_id LIMIT 1");
// A creator that is a staff account here (the Made-by whitelist is admin_users; mirrored logs can name ids that are not).
$creatorId = $one("SELECT a.performed_by FROM booking_audit_log a JOIN admin_users u ON u.id = a.performed_by WHERE a.action = 'created' GROUP BY a.performed_by ORDER BY COUNT(*) DESC, a.performed_by LIMIT 1");
$today = date('Y-m-d');
$d30 = date('Y-m-d', strtotime('-30 days'));
$p30 = date('Y-m-d', strtotime('+30 days'));
$since = date('Y-m-d', strtotime('-60 days')) . ' 00:00:00';
$tom = date('Y-m-d', strtotime('+1 day'));

// ---- Shapes: label => query (size=all is added to each list fetch)
$shapes = ['all' => ''];
foreach (array_keys(rh_bk_tab_map()) as $k) { $shapes["filter=$k"] = "filter=$k"; }
$shapes += [
    'status alias checked-in' => 'status=checked-in',
    'status alias pending' => 'status=pending',
    'status alias confirmed' => 'status=confirmed',
    'status alias tentative' => 'status=tentative',
    'filter_status cancelled' => 'filter_status=cancelled',
    'filter_status checked-out' => 'filter_status=checked-out',
    'filter_status no-show' => 'filter_status=no-show',
    'status expired' => 'status=expired',
    'status deleted' => 'status=deleted',
    'status garbage' => 'status=garbage',
    'status paid (not a status)' => 'status=paid',
    'payment_status paid' => 'payment_status=paid',
    'payment_status unpaid' => 'payment_status=unpaid',
    'arrival overdue' => 'arrival=overdue',
    'departure overdue' => 'departure=overdue',
    'balance owing + checkout_today' => 'filter=checkout_today&balance=owing',
    'balance owing' => 'balance=owing',
    'since booked' => 'since=' . rawurlencode($since) . '&since_kind=booked',
    'since cancelled' => 'since=' . rawurlencode($since) . '&since_kind=cancelled',
    'since moved' => 'since=' . rawurlencode($since) . '&since_kind=moved',
    'since without kind' => 'since=' . rawurlencode($since),
    'search guest word' => 'search=' . rawurlencode($word),
    'search reference' => 'search=' . rawurlencode($ref),
    'search + type + flash' => 'search=' . rawurlencode($ref) . '&type=room&flash=' . rawurlencode($ref),
    'flash only' => 'flash=booking-' . (int)$firstBk['id'],
    'type only' => 'type=room',
    'dates arrival' => "date_from=$d30&date_to=$p30&date_type=arrival",
    'dates departure' => "date_from=$d30&date_to=$p30&date_type=departure",
    'dates staying' => "date_from=$today&date_to=$today&date_type=staying",
    'dates booked' => "date_from=$d30&date_to=$today&date_type=booked",
    'dates swapped, no type' => "date_from=$p30&date_to=$d30",
    'date_to only' => "date_to=$today",
    'invalid dates' => 'date_from=2026-02-30&date_to=nonsense',
    'kind group' => 'kind=group',
    'kind single' => 'kind=single',
    'kind joined' => 'kind=joined',
    'room_type' => "room_type=$rtId",
    'pay part' => 'pay=part',
    'pay fee_owed' => 'pay=fee_owed',
    'pay paid' => 'pay=paid',
    'pay refunded' => 'pay=refunded',
    'sort check_in asc' => 'sort=check_in&dir=asc',
    'sort room desc' => 'sort=room&dir=desc',
    'sort total asc' => 'sort=total&dir=asc',
    'sort payment desc + unpaid tab' => 'filter=unpaid&sort=payment&dir=desc',
    'sort guest, no dir' => 'sort=guest',
    'action assign-room (confirmed)' => "action=assign-room&booking_id=$confirmedId",
    'action room-change (in house)' => "action=room-change&booking_id=$inHouseId",
    'action checkout (in house)' => "action=checkout&booking_id=$inHouseId",
    'action checkin (confirmed)' => "action=checkin&booking_id=$confirmedId",
    'action unknown' => "action=delete&booking_id=$confirmedId",
    'Assistant: departures with a balance' => "status=checked-in&date_type=departure&date_to=$tom&balance=owing",
    'Assistant: unpaid arriving' => "filter=unpaid&date_type=arrival&date_from=$today&date_to=$p30",
    'Assistant: pending upcoming' => "status=pending&date_type=arrival&date_from=$today",
    'unknown params ignored' => 'foo=bar&filter=bogus&kind=nope&pay=nope&room_type=999999&date_type=nope&since_kind=nope',
    'page 2 of 10' => 'page=2&size=10',
];
$newShapes = [
    'room' => "room=$roomId",
    'group' => "group=$groupId",
    'source staff' => 'source=staff',
    'source online' => 'source=online',
    'source system' => 'source=system',
    'source none' => 'source=none',
    'created_by' => "created_by=$creatorId",
    'created_by + In house' => "created_by=$creatorId&filter=checked_in",
    'sort balance desc' => 'sort=balance&dir=desc',
    'sort balance asc + cancelled' => 'filter_status=cancelled&sort=balance&dir=asc',
    'room unknown id ignored' => 'room=99999999',
    'group not a lead ignored' => 'group=99999999',
    'created_by unknown ignored' => 'created_by=99999999',
    'source unknown ignored' => 'source=nope',
];

// ---- Server + sign in (first active admin)
$admin = $pdo->query("SELECT id, username FROM admin_users WHERE is_active = 1 AND role = 'admin' ORDER BY id LIMIT 1")->fetch(PDO::FETCH_ASSOC);
$maxLog = (int)$pdo->query('SELECT COALESCE(MAX(id), 0) FROM admin_activity_log')->fetchColumn();
$sock = stream_socket_server('tcp://127.0.0.1:0');
$port = (int)substr(strrchr(stream_socket_get_name($sock, false), ':'), 1);
fclose($sock);
$base = "http://127.0.0.1:$port";
$null = stripos(PHP_OS, 'WIN') === 0 ? 'NUL' : '/dev/null';
$srv = proc_open([PHP_BINARY, '-S', "127.0.0.1:$port", '-t', $root, $root . '/scripts/tests/dev-router.php'],
    [0 => ['file', $null, 'r'], 1 => ['file', $null, 'w'], 2 => ['file', $null, 'w']], $pipes, $root);
$jar = tempnam(sys_get_temp_dir(), 'bklh');

$req = function (string $path, bool $follow = true) use ($base, $jar): array {
    $ch = curl_init($base . $path);
    $headers = [];
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true, CURLOPT_COOKIEJAR => $jar, CURLOPT_COOKIEFILE => $jar, CURLOPT_FOLLOWLOCATION => $follow,
        CURLOPT_TIMEOUT => 120,
        CURLOPT_HEADERFUNCTION => function ($c, $h) use (&$headers) { $headers[] = trim($h); return strlen($h); },
    ]);
    $body = (string)curl_exec($ch);
    $code = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    curl_close($ch);
    return [$code, $body, $headers];
};
$canon = static function (string $href): string {
    $q = (string)parse_url(html_entity_decode($href, ENT_QUOTES), PHP_URL_QUERY);
    parse_str($q, $a);
    ksort($a);
    return (string)parse_url(html_entity_decode($href, ENT_QUOTES), PHP_URL_PATH) . '?' . json_encode($a);
};
$listUrl = static function (string $q): string {
    return '/admin/bookings.php?' . ($q !== '' ? $q . '&' : '') . (preg_match('/(^|&)size=/', $q) ? '' : 'size=all');
};
$csvUrl = static function (string $q): string { return '/admin/bookings.php?' . ($q !== '' ? $q . '&' : '') . 'export=csv'; };
$readList = function (string $q) use ($req, $listUrl, $canon): array {
    [$code, $html] = $req($listUrl($q));
    $ok = $code === 200 && strpos($html, 'id="booking-results"') !== false;
    preg_match_all('/data-focus="booking-(\d+)"/', $html, $m);
    preg_match('/\(([\d,]+)(?: of ([\d,]+))? (matching|total)/', $html, $c);
    $count = isset($c[1]) ? (int)str_replace(',', '', $c[2] ?? '' ?: $c[1]) : -1;
    preg_match_all('/<input type="hidden" name="([^"]+)" value="([^"]*)" data-bk-keep>/', $html, $k, PREG_SET_ORDER);
    $keep = [];
    foreach ($k as $kk) { $keep[$kk[1]] = html_entity_decode($kk[2], ENT_QUOTES); }
    ksort($keep);
    preg_match_all('/<a href="(bookings\.php\?[^"]*)" class="pg-btn"/', $html, $pg);
    $pager = array_values(array_unique(array_map($canon, $pg[1])));
    sort($pager);
    preg_match_all('/"href":"(bookings\.php[^"]*)"/', $html, $ins);
    $insight = array_values(array_unique(array_map(static function ($h) use ($canon) { return $canon((string)json_decode('"' . $h . '"')); }, $ins[1])));
    sort($insight);
    return ['ok' => $ok, 'code' => $code, 'ids' => array_map('intval', $m[1]), 'count' => $count, 'matching' => ($c[3] ?? '') === 'matching',
        'keep' => $keep, 'pager' => $pager, 'insight' => $insight];
};
$readCsv = function (string $q) use ($req, $csvUrl): array {
    [$code, $body] = $req($csvUrl($q));
    $fh = fopen('php://temp', 'r+');
    fwrite($fh, $body);
    rewind($fh);
    $head = fgetcsv($fh) ?: [];
    $rows = [];
    while (($r = fgetcsv($fh)) !== false) { if ($r !== [null]) { $rows[] = $r; } }
    fclose($fh);
    return ['code' => $code, 'head' => $head, 'rows' => $rows];
};

$record = [];
try {
    $up = false;
    for ($i = 0; $i < 50 && !$up; $i++) { usleep(200000); $c = @fsockopen('127.0.0.1', $port, $e1, $e2, 0.2); if ($c) { fclose($c); $up = true; } }
    check($up, 'dev server started');
    [$code, $html] = $req('/__devlogin?to=dashboard.php');
    check($code === 200, 'signed in as an admin', "HTTP $code");

    $refById = [];
    foreach ($pdo->query('SELECT id, booking_reference FROM bookings')->fetchAll(PDO::FETCH_ASSOC) as $r) { $refById[(int)$r['id']] = (string)$r['booking_reference']; }

    $all = $shapes + ($t3 ? $newShapes : []);
    foreach ($all as $label => $q) {
        $l = $readList($q);
        check($l['ok'], "$label: list page renders", 'HTTP ' . $l['code']);
        $csv = $readCsv(preg_replace('/(^|&)(page|size)=[^&]*/', '', $q));
        check($csv['code'] === 200 && ($csv['head'][0] ?? '') === 'Reference', "$label: CSV export answers", 'HTTP ' . $csv['code']);
        $csvRefs = array_map(static function ($r) { return (string)$r[0]; }, $csv['rows']);
        $record[$label] = ['q' => $q, 'ids' => $l['ids'], 'count' => $l['count'], 'keep' => $l['keep'], 'pager' => $l['pager'],
            'insight' => $l['insight'], 'csv' => $csvRefs];
        if (!$t3) { continue; }

        // T3: export rows = list count, export order = screen order (size=all; page 2 of 10 is one page of it).
        $screenRefs = array_map(static function ($id) use ($refById) { return $refById[$id] ?? '?'; }, $l['ids']);
        if ($q !== 'page=2&size=10') {
            check(count($l['ids']) === $l['count'], "$label: every row on one page (size=all)", count($l['ids']) . ' vs ' . $l['count']);
            check(count($csvRefs) === $l['count'], "$label: export rows = list count", count($csvRefs) . ' vs ' . $l['count']);
            check($csvRefs === $screenRefs, "$label: export order = screen order");
        }
        // T3: the screen = the shared SQL (scope + order), except the deep links that swap the search for a reference.
        if (strpos($q, 'action=') === false) {
            parse_str($q, $get);
            $sqlIds = bklh_sql_ids($pdo, $get);
            if ($q === 'page=2&size=10') { $sqlIds = array_slice($sqlIds, 10, 10); }
            check($sqlIds === $l['ids'], "$label: screen rows and order = shared SQL", json_encode(['sql' => array_slice($sqlIds, 0, 8), 'screen' => array_slice($l['ids'], 0, 8)]));
        }
        // T3: Balance due column (after Payment) = rh_bk_balance_due() per row.
        $bi = array_search('Balance due', $csv['head'], true);
        $pi = array_search('Payment', $csv['head'], true);
        check($bi !== false && $pi !== false && $bi === $pi + 1, "$label: CSV has Balance due after Payment");
        if ($bi !== false && $csv['rows']) {
            $bad = bklh_balance_mismatches($pdo, $csv['rows'], (int)$bi);
            check($bad === [], "$label: CSV Balance due = rh_bk_balance_due() on every row", implode('; ', array_slice($bad, 0, 5)));
        }
    }

    // Back to Bookings (bookings_list_return): a list GET is remembered; a deep-link GET never overwrites it.
    $backQ = 'filter=unpaid&search=' . rawurlencode($word);
    $req('/admin/bookings.php?' . $backQ);
    [, $bd] = $req('/admin/booking-details.php?id=' . (int)$firstBk['id']);
    preg_match('/<a href="([^"]*)" class="bd-back"/', $bd, $bm);
    $back1 = html_entity_decode($bm[1] ?? '', ENT_QUOTES);
    check($back1 === 'bookings.php?' . $backQ, 'Back to Bookings reproduces the list URL', $back1);
    $req('/admin/bookings.php?action=assign-room&booking_id=' . $confirmedId);
    [, $bd] = $req('/admin/booking-details.php?id=' . (int)$firstBk['id']);
    preg_match('/<a href="([^"]*)" class="bd-back"/', $bd, $bm);
    check(html_entity_decode($bm[1] ?? '', ENT_QUOTES) === $back1, 'a deep-link GET leaves the remembered list alone', $bm[1] ?? '');
    $record['__back'] = $back1;
    // Assign room on a cancelled booking: back to booking-details with a message (no list).
    [$code, , $hdr] = $req('/admin/bookings.php?action=assign-room&booking_id=' . $cancelledId, false);
    $loc = '';
    foreach ($hdr as $h) { if (stripos($h, 'Location:') === 0) { $loc = trim(substr($h, 9)); } }
    check($code === 302 && $loc === 'booking-details.php?id=' . $cancelledId, 'assign-room on a cancelled booking -> booking-details', "HTTP $code $loc");
    $req('/admin/booking-details.php?id=' . $cancelledId); // shows (and so clears) the message

    if ($t3) {
        // Unknown params / ids are ignored: same rows as the plain list.
        foreach (['unknown params ignored', 'room unknown id ignored', 'group not a lead ignored', 'created_by unknown ignored', 'source unknown ignored', 'type only', 'flash only'] as $lb) {
            check($record[$lb]['ids'] === $record['all']['ids'], "$lb: same rows as the plain list");
        }
        // Source splits the list exactly; the new filters find rows on Liwonde.
        $srcSum = 0;
        foreach (['staff', 'online', 'system', 'none'] as $s) { $srcSum += $record["source $s"]['count']; }
        check($srcSum === $record['all']['count'], 'source staff + online + system + none = the whole list', "$srcSum vs " . $record['all']['count']);
        foreach (['room', 'group', 'source staff', 'created_by'] as $lb) {
            check($record[$lb]['count'] > 0, "$lb: finds rows on Liwonde", (string)$record[$lb]['count']);
            check($record[$lb]['count'] < $record['all']['count'], "$lb: narrows the list");
            check(($record[$lb]['keep'][explode('=', $record[$lb]['q'])[0]] ?? '') !== '', "$lb: kept by the search form (hidden input)");
        }
        // Export order follows the screen across size=10 pages too.
        foreach (['', 'filter=unpaid', 'sort=check_in&dir=asc', 'sort=balance&dir=desc'] as $q) {
            $ids = [];
            for ($p = 1; $p <= 20; $p++) {
                $pageIds = $readList(($q !== '' ? $q . '&' : '') . "size=10&page=$p")['ids'];
                $ids = array_merge($ids, $pageIds);
                if (count($pageIds) < 10) { break; }
            }
            $csvRefs = array_map(static function ($r) { return (string)$r[0]; }, $readCsv($q)['rows']);
            check(array_map(static function ($id) use ($refById) { return $refById[$id] ?? '?'; }, $ids) === $csvRefs,
                "pages of 10 joined = export order ($q)", count($ids) . ' vs ' . count($csvRefs));
        }
    }

    if ($opt['baseline'] !== '') {
        $base0 = json_decode((string)@file_get_contents($opt['baseline']), true);
        check(is_array($base0), 'baseline file readable', $opt['baseline']);
        $same = 0;
        foreach ((array)$base0 as $label => $b) {
            if ($label === '__back') { check($record['__back'] === $b, 'Back link same as HEAD'); continue; }
            $n = $record[$label] ?? null;
            if ($n === null) { check(false, "$label: shape still fetched"); continue; }
            $s1 = $b['ids']; sort($s1); $s2 = $n['ids']; sort($s2);
            $c1 = $b['csv']; sort($c1); $c2 = $n['csv']; sort($c2);
            // A page of 10 holds other rows when the default order changed (Decision 5): the count is compared there.
            $paged = preg_match('/(^|&)page=/', (string)$b['q']) === 1;
            check(($paged || $s1 === $s2) && $b['count'] === $n['count'], "$label: same rows as HEAD" . ($paged ? ' (count; paged)' : ''), json_encode(['head' => $b['count'], 'now' => $n['count']]));
            check($b['keep'] === $n['keep'], "$label: same kept inputs as HEAD", json_encode(['head' => $b['keep'], 'now' => $n['keep']]));
            check($b['pager'] === $n['pager'], "$label: same pager links as HEAD", json_encode(['head' => array_slice($b['pager'], 0, 2), 'now' => array_slice($n['pager'], 0, 2)]));
            check($b['insight'] === $n['insight'], "$label: same insight links as HEAD");
            check($c1 === $c2, "$label: same export rows as HEAD", count($c1) . ' vs ' . count($c2));
            $same++;
            if ($b['ids'] !== $n['ids']) { echo "INFO $label: order differs from HEAD (Decision 5 default order or export sort)\n"; }
        }
        echo "compared with HEAD: $same shapes\n";
    }
    if ($opt['record'] !== '') {
        file_put_contents($opt['record'], json_encode($record, JSON_PRETTY_PRINT));
        echo 'recorded ' . (count($record) - 1) . ' shapes to ' . $opt['record'] . "\n";
    }
    $counts = [];
    foreach ($record as $lb => $r) { if (is_array($r)) { $counts[] = "$lb=" . $r['count']; } }
    echo 'rows per shape: ' . implode(', ', $counts) . "\n";
} finally {
    $cl = $pdo->prepare('DELETE FROM admin_activity_log WHERE id > ? AND user_id = ?');
    $cl->execute([$maxLog, (int)$admin['id']]);
    echo 'activity-log rows from this run removed: ' . $cl->rowCount() . "\n";
    if (is_resource($srv)) { proc_terminate($srv); proc_close($srv); }
    @unlink($jar);
}

/** Booking ids in list order from the shared helpers, for a GET array (the page's own option lists as whitelists). */
function bklh_sql_ids(PDO $pdo, array $get): array
{
    $known = bklh_known($pdo, $get);
    $f = rh_bk_filters_from_get($get, $known);
    $scope = rh_bk_list_scope($f, bookingAvailabilityTableExists('room_combinations'));
    $order = rh_bk_default_order($f['tab'], $f['status']);
    $map = rh_bk_sort_map();
    $key = isset($get['sort']) && is_string($get['sort']) && isset($map[$get['sort']]) ? $get['sort'] : '';
    if ($key !== '') {
        $dir = (isset($get['dir']) && strtolower((string)$get['dir']) === 'asc') ? 'asc' : 'desc';
        $order = $key === 'room' ? rh_bk_room_sort_order($dir) : $map[$key] . ' ' . strtoupper($dir);
    }
    $where = $scope['clauses'] ? 'WHERE ' . implode(' AND ', $scope['clauses']) : '';
    $st = $pdo->prepare("SELECT b.id FROM bookings b LEFT JOIN rooms r ON b.room_id = r.id
        LEFT JOIN individual_rooms ir ON b.individual_room_id = ir.id {$where} ORDER BY {$order}, b.id DESC");
    $st->execute($scope['params']);
    return array_map('intval', $st->fetchAll(PDO::FETCH_COLUMN));
}

/** The whitelists bookings.php builds (room types, rooms, staff, groups incl. any lead named by ?group=). */
function bklh_known(PDO $pdo, array $get): array
{
    $groups = array_map('intval', $pdo->query('SELECT DISTINCT primary_booking_id FROM bookings WHERE primary_booking_id IS NOT NULL')->fetchAll(PDO::FETCH_COLUMN));
    return [
        'room_types' => array_map('intval', $pdo->query('SELECT id FROM rooms')->fetchAll(PDO::FETCH_COLUMN)),
        'rooms' => array_map('intval', $pdo->query('SELECT id FROM individual_rooms')->fetchAll(PDO::FETCH_COLUMN)),
        'staff' => array_map('intval', $pdo->query('SELECT id FROM admin_users')->fetchAll(PDO::FETCH_COLUMN)),
        'groups' => $groups,
    ];
}

/** CSV rows whose Balance due differs from the PHP rule (rh_bk_balance_due with the page's tagged-expired test). */
function bklh_balance_mismatches(PDO $pdo, array $rows, int $col): array
{
    $st = $pdo->prepare("SELECT b.id, b.status, b.payment_status, b.amount_paid, b.amount_due, b.credit_balance, b.deleted_at,
        EXISTS (SELECT 1 FROM booking_charges c WHERE c.booking_id = b.id AND c.description LIKE CONCAT('%[noshow-fee:', b.id, ']')) AS tagged
        FROM bookings b WHERE b.booking_reference = ?");
    $bad = [];
    foreach ($rows as $r) {
        $st->execute([(string)$r[0]]);
        $b = $st->fetch(PDO::FETCH_ASSOC);
        $tagged = $b && (string)$b['status'] === 'expired' && empty($b['deleted_at']) && (float)$b['amount_due'] > BALANCE_TOLERANCE && (int)$b['tagged'] === 1;
        $want = $b ? rh_bk_balance_due($b, $tagged) : 'missing';
        $wantS = $want === null ? '' : (is_float($want) ? number_format($want, 2, '.', '') : $want);
        if ((string)$r[$col] !== $wantS) { $bad[] = $r[0] . ' csv ' . $r[$col] . ' want ' . $wantS; }
    }
    return $bad;
}

echo "bookings_list_http: $pass passed, $fail failed\n";
exit($fail ? 1 : 0);
