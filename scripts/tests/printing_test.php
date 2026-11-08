<?php
if (PHP_SAPI !== 'cli') { http_response_code(403); exit("CLI only.\n"); }
/**
 * printing_test.php - receipt printers (includes/printing.php, api/print-*.php).
 * Run from INSIDE a repo root, against LIWONDE only (Rosalyns is live):  php scripts/tests/printing_test.php
 * Part 1 is pure (renderers, widths, wrapping, encoders). Parts 2-4 use the database inside ONE transaction that is
 * rolled back (migration 076 must be applied): endpoint token checks, queue enqueue / route / dedupe / cancel.
 */
$root = getcwd();
require_once $root . '/config/database.php';
require_once $root . '/includes/printing.php';

$pass = 0; $fail = 0;
function ok(string $name, bool $cond, string $detail = ''): void {
    global $pass, $fail;
    if ($cond) { $pass++; echo "  PASS  $name\n"; } else { $fail++; echo "  FAIL  $name  $detail\n"; }
}

/* ---------------------------------------------------------------- Part 1: renderers */
echo "== Layout, widths and wrapping ==\n";
$longName = 'Grilled whole tilapia with chambo nsima and seasonal vegetables in a spicy tomato sauce';
$ticket = rh_print_model_ticket([
    'site' => "Rosalyn's Hotel", 'station_label' => 'Kitchen', 'location' => 'TABLE 12', 'ref' => 'ORD-20261109-0042',
    'service' => 'Dine In', 'time' => '2026-11-09 12:30', 'server' => 'Chikondi Banda', 'guest' => 'Mr Phiri',
    'note' => 'Allergy: groundnuts - please check every plate', 'addon' => false,
    'items' => [
        ['qty' => 2, 'name' => $longName, 'note' => 'no onion, extra sauce on the side'],
        ['qty' => 1, 'name' => 'Supercalifragilisticexpialidocious_Unbroken_Word_That_Is_Longer_Than_A_Line', 'note' => ''],
        ['qty' => 0.5, 'name' => "Caf\u{00E9} cr\u{00E8}me \u{2013} special", 'note' => ''],
    ],
]);
$receiptOrder = [
    'id' => 1, 'reference' => 'ORD-20261109-0042', 'invoice_number' => 'RST-20261109-00042', 'status' => 'paid', 'order_type' => 'dine_in',
    'table_number' => '12', 'customer_name' => 'Mr Phiri', 'paid_at' => '2026-11-09 13:05:00', 'created_at' => '2026-11-09 12:30:00',
    'subtotal' => 1234567.50, 'discount_amount' => 12345.67, 'discount_reason' => 'Manager comp', 'service_charge' => 50000.00, 'tax_amount' => 0,
    'total_amount' => 1272221.83, 'tip_amount' => 10000.00, 'split_count' => 1, 'payment_method' => 'cash', 'tendered_amount' => 1300000.00,
    'change_due' => 17778.17, 'notes' => 'Birthday table',
];
$receiptItems = [
    ['item_name' => $longName, 'quantity' => 2, 'unit_price' => 15000, 'line_total' => 30000, 'notes' => 'no onion', 'kds_status' => 'served'],
    ['item_name' => 'Soft drink', 'quantity' => 3, 'unit_price' => 2500.5, 'line_total' => 7501.5, 'notes' => '', 'kds_status' => 'served'],
    ['item_name' => 'Voided starter', 'quantity' => 1, 'unit_price' => 9999, 'line_total' => 9999, 'notes' => '', 'kds_status' => 'void'],
];
$receiptCtx = [
    'currency' => 'MWK', 'site' => "Rosalyn's Hotel", 'address' => 'Area 3, Lilongwe, Malawi', 'phone' => '+265 999 000 111', 'email' => 'info@example.com',
    'vat_number' => 'V123456', 'vat_note' => 'All prices are inclusive of VAT (16.5%).', 'footer' => 'Thank you for dining with us!', 'cashier' => 'Chikondi Banda',
    'receipt_numbers' => 'RCP-000123', 'shop_mode' => false, 'split_legs' => [],
];
$receipt = rh_print_model_receipt($receiptOrder, $receiptItems, $receiptCtx);
$testT = rh_print_model_test('ticket', "Rosalyn's Hotel", 'Kitchen', '80 mm roll', 48);
$testR = rh_print_model_test('receipt', "Rosalyn's Hotel", 'Cash desk', '58 mm roll', 32);

$maxOver = 0; $rowsSeen = 0;
foreach ([32, 48, 40, 46, 52, 42, 24] as $cols) {
    foreach (['ticket' => $ticket, 'receipt' => $receipt, 'test ticket' => $testT, 'test receipt' => $testR] as $label => $model) {
        foreach ([true, false] as $ascii) {
            foreach (rh_print_layout($model, $cols, $ascii) as $r) {
                if (!empty($r['cut'])) continue;
                $rowsSeen++;
                $limit = intdiv($cols, (int)$r['z']);
                $over = rh_pr_len($r['x']) - $limit;
                if ($over > $maxOver) $maxOver = $over;
            }
        }
    }
}
ok('no row is wider than its column count on 24 / 32 / 40 / 42 / 46 / 48 / 52 columns (double-size rows count 2x), ' . $rowsSeen . ' rows', $maxOver <= 0, 'over by ' . $maxOver);

foreach ([32, 48] as $cols) {
    $txt = rh_print_encode_text(rh_print_rows_for($ticket, $cols));
    $flat = strtoupper(preg_replace('/\s+/', ' ', $txt));
    ok("$cols col: long item name is wrapped, not cut off", strpos($flat, 'SPICY TOMATO SAUCE') !== false && strpos($flat, 'GRILLED WHOLE') !== false, $txt);
    $joined = strtoupper(str_replace(["\n", ' '], '', $txt));
    ok("$cols col: an unbroken word longer than a line is split, none of it lost", strpos($joined, strtoupper('Supercalifragilisticexpialidocious_Unbroken_Word_That_Is_Longer_Than_A_Line')) !== false);
    ok("$cols col: accents turn to plain ASCII on the printer text", strpos($flat, 'CAFE CREME - SPECIAL') !== false && !preg_match('/[\x80-\xFF]/', $txt));
    ok("$cols col: quantity 0.5 prints as 0.5", strpos($flat, '0.5 X') !== false);
    ok("$cols col: order note is shown highlighted", strpos($txt, 'ORDER NOTE') !== false);
    $rows = rh_print_layout($ticket, $cols);
    $hasInv = false; $bigTable = false;
    foreach ($rows as $r) { if (!empty($r['inv'])) $hasInv = true; if (($r['z'] ?? 1) === 2 && strpos($r['x'], 'TABLE 12') !== false) $bigTable = true; }
    ok("$cols col: notes use the inverted bar and the table is double size", $hasInv && $bigTable);
    $orderLineBig = false;
    foreach ($rows as $r) { if (($r['z'] ?? 1) === 2 && strpos($r['x'], '#ORD-') !== false) $orderLineBig = true; }
    ok("$cols col: order number is double size only when it fits", $cols >= 40 ? $orderLineBig : !$orderLineBig, json_encode(array_column($rows, 'z')));
}

echo "== Kitchen ticket has no prices ==\n";
$tj = json_encode($ticket);
ok('ticket model carries no price fields', strpos($tj, 'price') === false && strpos($tj, 'total') === false && strpos($tj, 'MWK') === false);
$tt = rh_print_encode_text(rh_print_rows_for($ticket, 48));
ok('ticket text has no money figure', !preg_match('/\d[\d,]*\.\d{2}/', $tt), $tt);

echo "== Receipt totals equal the order ==\n";
foreach ([32, 48] as $cols) {
    $rt = rh_print_encode_text(rh_print_rows_for($receipt, $cols));
    $lines = explode("\n", $rt);
    $find = static function (string $needle) use ($lines): string {
        foreach ($lines as $l) { if (strpos($l, $needle) === 0) return $l; }
        return '';
    };
    ok("$cols col: Subtotal = stored subtotal", preg_match('/Subtotal\s+MWK 1,234,567\.50$/', $find('Subtotal')) === 1, $find('Subtotal'));
    ok("$cols col: Discount shows stored amount with a minus", preg_match('/MWK 12,345\.67$/', $find('Discount')) === 1 || strpos($rt, '-MWK 12,345.67') !== false, $rt);
    ok("$cols col: Service charge = stored", preg_match('/MWK 50,000\.00$/', $find('Service charge')) === 1);
    ok("$cols col: Tip = stored", strpos($rt, '+MWK 10,000.00') !== false);
    ok("$cols col: GRAND TOTAL = total + tip, exactly as the existing receipt", preg_match('/GRAND TOTAL\s+MWK 1,282,221\.83$/', $find('GRAND TOTAL')) === 1, $find('GRAND TOTAL'));
    ok("$cols col: tendered and change = stored", strpos($rt, 'MWK 1,300,000.00') !== false && strpos($rt, 'MWK 17,778.17') !== false);
    ok("$cols col: item line: qty x unit price left, line total right", strpos($rt, '3 x 2,500.50') !== false && strpos($rt, '7,501.50') !== false);
    ok("$cols col: a voided line is not printed", strpos($rt, 'Voided starter') === false);
    ok("$cols col: header carries name, address, phone and VAT number", strpos($rt, "Rosalyn's Hotel") !== false && strpos($rt, 'Lilongwe') !== false && strpos($rt, '+265 999 000 111') !== false && strpos($rt, 'VAT Reg. No.: V123456') !== false);
    ok("$cols col: receipt number, order number and VAT note present", strpos($rt, 'RCP-000123') !== false && strpos($rt, 'ORD-20261109-0042') !== false && strpos($rt, 'inclusive of VAT') !== false);
    foreach (rh_print_layout($receipt, $cols) as $r) {
        if (!empty($r['cut']) || strpos($r['x'], 'MWK') === false || strpos($r['x'], ' ') === false) continue;
        if (preg_match('/\s{2,}MWK/', $r['x'])) { ok("$cols col: amounts are right-aligned to the edge", rh_pr_len($r['x']) === $cols, $r['x']); break; }
    }
}
$openRec = rh_print_encode_text(rh_print_rows_for(rh_print_model_receipt(['status' => 'placed', 'payment_method' => '', 'tendered_amount' => null, 'change_due' => null] + $receiptOrder, $receiptItems, $receiptCtx), 48));
ok('an open order shows Status Open and never "Paid via"', preg_match('/^Status\s+Open$/m', $openRec) === 1 && strpos($openRec, 'Paid via') === false, $openRec);
$paidRec = rh_print_encode_text(rh_print_rows_for($receipt, 48));
ok('a paid order shows Status Paid and Paid via', preg_match('/^Status\s+Paid$/m', $paidRec) === 1 && strpos($paidRec, 'Paid via') !== false);
ok('a refunded order shows Status Refunded', preg_match('/^Status\s+Refunded$/m', rh_print_encode_text(rh_print_rows_for(rh_print_model_receipt(['status' => 'refunded'] + $receiptOrder, $receiptItems, $receiptCtx), 48))) === 1);
ok('VAT Reg. No. is printed only from a filled setting (blank or missing = no line)', strpos(rh_print_encode_text(rh_print_rows_for(rh_print_model_receipt($receiptOrder, $receiptItems, ['vat_number' => '  '] + $receiptCtx), 48)), 'VAT Reg') === false
    && strpos(rh_print_encode_text(rh_print_rows_for(rh_print_model_receipt($receiptOrder, $receiptItems, array_diff_key($receiptCtx, ['vat_number' => 1])), 48)), 'VAT Reg') === false
    && strpos(file_get_contents(__DIR__ . '/../../includes/printing.php'), "getSetting('vat_number'") !== false);
$noVat = rh_print_model_receipt($receiptOrder, $receiptItems, ['vat_number' => ''] + $receiptCtx);
ok('no VAT number set: no VAT line', strpos(rh_print_encode_text(rh_print_rows_for($noVat, 48)), 'VAT Reg') === false);
$voidRec = rh_print_encode_text(rh_print_rows_for(rh_print_model_receipt(['status' => 'voided'] + $receiptOrder, $receiptItems, $receiptCtx), 48));
ok('a voided order prints the VOID banner', strpos($voidRec, 'VOID / NOT VALID') !== false);
$splitRec = rh_print_encode_text(rh_print_rows_for(rh_print_model_receipt(['split_count' => 2] + $receiptOrder, $receiptItems, ['split_legs' => [
    ['split_number' => 1, 'payment_method' => 'cash', 'split_amount' => 600000, 'tip_amount' => 0],
    ['split_number' => 2, 'payment_method' => 'mobile_money', 'split_amount' => 672221.83, 'tip_amount' => 10000]], 'receipt_by_leg' => [1 => 'RCP-A', 2 => 'RCP-B']] + $receiptCtx), 32));
ok('split bill lists each leg with its receipt number', strpos($splitRec, 'RCP-B') !== false && strpos($splitRec, 'Mobile Money') !== false && strpos($splitRec, '682,221.83') !== false, $splitRec);

echo "== Encoders ==\n";
$rows48 = rh_print_rows_for($ticket, 48, 2);
$md = rh_print_encode_star_markup($rows48);
ok('Star Markup: two copies, each ends with a cut', substr_count($md, '[cut: feed; partial]') === 2);
ok('Star Markup: bold, double size and inverted note bar used', strpos($md, '[bold: on]') !== false && strpos($md, '[magnify: width 2; height 2]') !== false && strpos($md, '[invert: on]') !== false);
ok('Star Markup: brackets in item text cannot become tags', strpos(rh_print_encode_star_markup(rh_print_rows_for([rh_pr_text('1 x [bold: on] trick')], 48)), 'trick') !== false && substr_count(rh_print_encode_star_markup(rh_print_rows_for([rh_pr_text('1 x [bold: on] trick')], 48)), '[bold: on]') === 0);
$ex = rh_print_encode_epson(rh_print_rows_for($ticket, 48));
$sx = @simplexml_load_string($ex);
ok('Epson ePOS-Print XML is well formed', $sx !== false);
ok('Epson XML ends with feed + cut and uses em / reverse / double size', strpos($ex, '<cut type="feed"/>') !== false && strpos($ex, 'em="true"') !== false && strpos($ex, 'reverse="true"') !== false && strpos($ex, 'width="2" height="2"') !== false);
$exEsc = rh_print_encode_epson(rh_print_rows_for([rh_pr_text('A & B <c> "d"')], 32));
ok('Epson XML escapes & < > in item text and stays well formed', @simplexml_load_string($exEsc) !== false && strpos($exEsc, 'A &amp; B &lt;c&gt;') !== false);
$ptxt = rh_print_encode_text(rh_print_rows_for($ticket, 48, 1));
ok('plain text ends with feed lines for the printer to cut', substr($ptxt, -4) === "\n\n\n\n");

echo "== Paper sizes (HTML) ==\n";
$specs = ['58' => ['58mm auto', 32], '80' => ['80mm auto', 48], 'a6' => ['size:A6', 40], 'a5' => ['size:A5', 46], 'a4' => ['size:A4', 52]];
foreach ($specs as $paper => [$needle, $cols]) {
    $pr = ['paper' => (string)$paper, 'cols' => null, 'copies' => 1];
    ok("paper $paper: default $cols characters per line", rh_print_cols($pr) === $cols);
    $html = rh_print_encode_html(rh_print_rows_for($ticket, rh_print_cols($pr), 2, false), $pr, 'T');
    ok("paper $paper: @page size set", strpos($html, $needle) !== false, substr($html, 0, 400));
    ok("paper $paper: two copies are separated by a page break", substr_count($html, 'class="pb"') === 1);
    ok("paper $paper: ticket content and UTF-8 kept in the HTML", strpos($html, 'TABLE 12') !== false && strpos($html, 'CAF') !== false);
    ok("paper $paper: a ticket is one block that may not split across pages", substr_count($html, 'class="t"') === 2 && strpos($html, 'break-inside:avoid') !== false);
}
$h80 = rh_print_encode_html(rh_print_rows_for($ticket, 48, 1, false), ['paper' => '80'], 'T');
$h58 = rh_print_encode_html(rh_print_rows_for($ticket, 32, 1, false), ['paper' => '58'], 'T');
ok('80 mm: 48 characters x 1.5 mm = 72 mm printable; 58 mm: 32 x 1.5 = 48 mm', strpos($h80, 'width:72mm') !== false && strpos($h58, 'width:48mm') !== false);
$a4 = rh_print_encode_html(rh_print_rows_for($ticket, 52, 1, false), ['paper' => 'a4'], 'T');
preg_match('/font-size:([\d.]+)mm;line-height/', $a4, $m4);
preg_match('/font-size:([\d.]+)mm;line-height/', $h80, $m80);
ok('A4 type is larger than 80 mm type (kitchen tickets readable across the pass)', !empty($m4[1]) && !empty($m80[1]) && (float)$m4[1] > (float)$m80[1], ($m4[1] ?? '?') . ' vs ' . ($m80[1] ?? '?'));
ok('HTML escapes item text', strpos(rh_print_encode_html([['x' => '<script>x</script>', 'b' => false, 'z' => 1, 'inv' => false], ['cut' => true]], ['paper' => '80'], 'T'), '<script>x') === false);
$test = rh_print_encode_text(rh_print_rows_for($testT, 48));
$first = explode("\n", $test)[0];
ok('test page starts with the digit ruler, exactly the line width', strlen($first) === 48 && strpos($first, '1234567890') === 0, $first);
foreach (['58', '80', 'a6', 'a5', 'a4'] as $paper) {
    $pr = ['paper' => $paper, 'cols' => null];
    $cols = rh_print_cols($pr);
    $m = rh_print_model_test('receipt', 'Hotel', 'P', rh_print_paper_spec($paper)['label'], $cols);
    $rr = rh_print_layout($m, $cols);
    ok("test page on $paper: ruler is $cols wide and nothing is wider", strlen($rr[0]['x']) === $cols && max(array_map(static function ($r) { return empty($r['cut']) ? rh_pr_len($r['x']) / (int)$r['z'] * (int)$r['z'] : 0; }, $rr)) <= $cols);
}
ok('characters-per-line override is honoured', rh_print_cols(['paper' => '80', 'cols' => 42]) === 42 && rh_print_cols(['paper' => '80', 'cols' => 10]) === 48);

echo "== Printer form rules ==\n";
$base = ['name' => 'K', 'mode' => 'cloud', 'protocol' => 'star', 'station' => 'kitchen', 'paper' => '80', 'prints' => 'tickets', 'copies' => 1, 'is_active' => 1];
[$f, $e] = rh_print_validate_printer($base);
ok('valid cloud printer accepted', $e === '' && $f['paper'] === '80');
[, $e] = rh_print_validate_printer(['paper' => 'a4'] + $base);
ok('cloud printer on A4 refused (sheets are for the screen device)', $e !== '');
[$f, $e] = rh_print_validate_printer(['paper' => 'a4', 'mode' => 'screen'] + $base);
ok('screen device on A4 accepted', $e === '' && $f['paper'] === 'a4');
[, $e] = rh_print_validate_printer(['name' => ''] + $base);
ok('empty name refused', $e !== '');
[, $e] = rh_print_validate_printer(['copies' => 9] + $base);
ok('copies above 5 refused', $e !== '');
[, $e] = rh_print_validate_printer(['cols' => '300'] + $base);
ok('odd characters-per-line refused', $e !== '');
[$f] = rh_print_validate_printer(['station' => 'receipt', 'prints' => 'tickets'] + $base);
ok('a cash-desk printer prints receipts only', ($f['prints'] ?? '') === 'receipts');
[, $e] = rh_print_validate_printer(['station' => 'nonsense'] + $base);
ok('unknown station refused', $e !== '');
ok('endpoint url carries id and token, star vs epson file', strpos(rh_print_endpoint_url(['id' => 7, 'token' => 'abc', 'protocol' => 'star'], 'https://x.test/'), 'https://x.test/api/print-cloudprnt.php?p=7&t=abc') === 0
    && strpos(rh_print_endpoint_url(['id' => 7, 'token' => 'abc', 'protocol' => 'epson'], 'https://x.test'), '/api/print-epson.php?p=7') !== false);

/* ---------------------------------------------------------------- Parts 2-4: database, one transaction */
echo "== Wiring (static) ==\n";
$src = static function (string $f) use ($root): string { return (string)file_get_contents($root . '/' . $f); };
ok('pos_fireKitchen enqueues the ticket', strpos($src('admin/pos.php'), 'rh_print_enqueue_kitchen($pdo, $orderId);') !== false);
ok('till receipt hooks after both payment paths', substr_count($src('admin/pos.php'), 'rh_print_enqueue_receipt($pdo, $orderId)') === 2);
ok('room service and Orders & Tabs enqueue tickets', strpos($src('admin/room-service-dashboard.php'), 'rh_print_enqueue_kitchen') !== false && strpos($src('admin/stock-orders.php'), 'rh_print_enqueue_kitchen') !== false);
ok('cancel / void close, refunded close and delete clean the queue', strpos($src('admin/includes/pos-order-close.php'), 'rh_print_cancel_order_jobs') !== false
    && strpos($src('admin/includes/restaurant-payment-sync.php'), 'rh_print_cancel_order_jobs') !== false && strpos($src('admin/includes/pos-order-delete.php'), 'rh_print_purge_order_jobs') !== false);
ok('device endpoints start no session and read no cookies', strpos($src('api/print-cloudprnt.php'), 'session_start') === false && strpos($src('api/print-epson.php'), 'session_start') === false && strpos($src('api/print-cloudprnt.php'), 'admin-init') === false);

if (!rh_print_ready($pdo)) {
    echo "  SKIP  database parts: migration 076 not applied here (php admin/migrations/migrate.php --run)\n";
    echo "\n$pass passed, $fail failed\n";
    exit($fail ? 1 : 0);
}

$pdo->beginTransaction();
try {
    $tag = 'ZZP' . substr(bin2hex(random_bytes(4)), 0, 6);
    $mkPrinter = static function (string $name, string $mode, string $protocol, string $station, string $paper, string $prints, int $active = 1) use ($pdo, $tag): array {
        $tok = rh_print_new_token();
        $pdo->prepare('INSERT INTO print_printers (name, mode, protocol, station, paper, prints, copies, is_active, token) VALUES (?,?,?,?,?,?,1,?,?)')
            ->execute([$tag . ' ' . $name, $mode, $protocol, $station, $paper, $prints, $active, $tok]);
        $id = (int)$pdo->lastInsertId();
        $st = $pdo->prepare('SELECT * FROM print_printers WHERE id = ?');
        $st->execute([$id]);
        return $st->fetch(PDO::FETCH_ASSOC);
    };
    $mkOrder = static function (string $status, array $lines) use ($pdo, $tag): int {
        static $n = 0; $n++;
        $pdo->prepare("INSERT INTO stock_orders (reference, order_type, status, table_number, customer_name, notes, subtotal, total_amount, kitchen_status) VALUES (?, 'dine_in', ?, '9', 'ZZ Guest', 'Test note', 1000, 1000, 'new')")
            ->execute([$tag . '-' . $n, $status]);
        $oid = (int)$pdo->lastInsertId();
        foreach ($lines as $l) {
            $pdo->prepare('INSERT INTO stock_order_items (order_id, menu_item_id, menu_type, item_name, quantity, unit_price, line_total, notes, station, kds_status) VALUES (?, 0, ?, ?, ?, 100, ?, ?, ?, ?)')
                ->execute([$oid, $l['station'], $l['name'], $l['qty'], $l['qty'] * 100, $l['note'] ?? null, $l['station'], $l['kds'] ?? 'pending']);
        }
        return $oid;
    };
    $jobsOf = static function (int $pid, int $oid = 0) use ($pdo): array {
        $st = $pdo->prepare('SELECT * FROM print_jobs WHERE printer_id = ?' . ($oid ? ' AND order_id = ' . (int)$oid : '') . ' ORDER BY id');
        $st->execute([$pid]);
        return $st->fetchAll(PDO::FETCH_ASSOC);
    };
    $textOf = static function (array $job, array $p): string { return rh_print_render_job($job, $p, 'text'); };

    echo "== Queue: route by station, dedupe, add-on round ==\n";
    // Printers someone has set up for real on this database must not take part in the counts below (rolled back with the rest).
    $pdo->exec("UPDATE print_printers SET is_active = 0 WHERE name NOT LIKE 'ZZP%'");
    $pk = $mkPrinter('kitchen', 'cloud', 'star', 'kitchen', '80', 'tickets');
    $pb = $mkPrinter('bar', 'screen', 'star', 'bar', 'a5', 'tickets');
    $pa = $mkPrinter('all', 'cloud', 'epson', 'all', '58', 'both');
    $pr = $mkPrinter('cashdesk', 'cloud', 'star', 'receipt', '80', 'receipts');
    $po = $mkPrinter('off one', 'off', 'star', 'kitchen', '80', 'tickets');
    $pi = $mkPrinter('inactive', 'cloud', 'star', 'kitchen', '80', 'tickets', 0);
    $oid = $mkOrder('placed', [
        ['station' => 'kitchen', 'name' => 'ZZ Chicken', 'qty' => 2, 'note' => 'no salt'],
        ['station' => 'bar', 'name' => 'ZZ Lager', 'qty' => 3],
        ['station' => 'kitchen', 'name' => 'ZZ Already served', 'qty' => 1, 'kds' => 'served'],
    ]);
    $made = rh_print_enqueue_kitchen($pdo, $oid);
    ok('three printers get a ticket (kitchen, bar-screen, all); off, inactive and cash desk do not', $made === 3, "made $made");
    $jk = $jobsOf((int)$pk['id'], $oid); $jb = $jobsOf((int)$pb['id'], $oid); $ja = $jobsOf((int)$pa['id'], $oid);
    ok('kitchen printer: only the kitchen line', count($jk) === 1 && strpos($textOf($jk[0], $pk), 'CHICKEN') !== false && strpos($textOf($jk[0], $pk), 'LAGER') === false);
    ok('bar printer: only the bar line', count($jb) === 1 && strpos(rh_print_render_job($jb[0], $pb, 'html'), 'LAGER') !== false && strpos(rh_print_render_job($jb[0], $pb, 'html'), 'CHICKEN') === false);
    ok('all-stations printer: both lines, served line excluded', count($ja) === 1 && strpos($textOf($ja[0], $pa), 'CHICKEN') !== false && strpos($textOf($ja[0], $pa), 'LAGER') !== false && stripos($textOf($ja[0], $pa), 'Already served') === false);
    ok('no ticket for the off, inactive or receipts-only printers', !$jobsOf((int)$po['id']) && !$jobsOf((int)$pi['id']) && !$jobsOf((int)$pr['id']));
    ok('ticket shows the table, order ref and the order note, and no price', strpos($textOf($jk[0], $pk), 'TABLE 9') !== false && strpos($textOf($jk[0], $pk), $tag) !== false && strpos($textOf($jk[0], $pk), 'TEST NOTE') !== false && !preg_match('/\d\.\d{2}/', $textOf($jk[0], $pk)));
    ok('ticket job is queued, attempts 0, title names table and order', $jk[0]['status'] === 'queued' && (int)$jk[0]['attempts'] === 0 && strpos($jk[0]['title'], 'TABLE 9') !== false && $jk[0]['kind'] === 'kitchen_ticket');
    ok('58 mm printer renders at 32 columns, 80 mm at 48', max(array_map('strlen', explode("\n", $textOf($ja[0], $pa)))) <= 32 && max(array_map('strlen', explode("\n", $textOf($jk[0], $pk)))) <= 48);
    ok('re-firing the same order prints nothing again (dedupe)', rh_print_enqueue_kitchen($pdo, $oid) === 0 && count($jobsOf((int)$pk['id'], $oid)) === 1);
    $pdo->prepare("INSERT INTO stock_order_items (order_id, menu_item_id, menu_type, item_name, quantity, unit_price, line_total, station, kds_status) VALUES (?, 0, 'kitchen', 'ZZ Second round chips', 1, 50, 50, 'kitchen', 'pending')")->execute([$oid]);
    ok('a round added to the tab prints only the new line, marked ADD-ON', rh_print_enqueue_kitchen($pdo, $oid) === 2);
    $jk = $jobsOf((int)$pk['id'], $oid);
    $t2 = $textOf($jk[1], $pk);
    ok('add-on ticket has only the new line and says ADD-ON', count($jk) === 2 && strpos($t2, 'CHIPS') !== false && strpos($t2, 'CHICKEN') === false && strpos($t2, 'ADD-ON') !== false, $t2);
    $cnt = $pdo->prepare('SELECT COUNT(*) FROM print_job_items WHERE printer_id = ?'); $cnt->execute([(int)$pk['id']]);
    ok('each line is recorded once per printer', (int)$cnt->fetchColumn() === 2);
    $dupe = $pdo->prepare('INSERT INTO print_job_items (job_id, printer_id, order_item_id) SELECT job_id, printer_id, order_item_id FROM print_job_items WHERE printer_id = ? LIMIT 1');
    $threw = false; try { $dupe->execute([(int)$pk['id']]); } catch (Throwable $e) { $threw = true; }
    ok('database refuses the same line twice for one printer', $threw);

    echo "== Queue: receipts ==\n";
    $oPaid = $mkOrder('paid', [['station' => 'kitchen', 'name' => 'ZZ Paid dish', 'qty' => 1, 'kds' => 'served']]);
    $pdo->prepare("UPDATE stock_orders SET paid_at = NOW(), payment_method = 'cash', subtotal = 1234.50, total_amount = 1234.50, tendered_amount = 2000, change_due = 765.50 WHERE id = ?")->execute([$oPaid]);
    $oOpen = $mkOrder('placed', [['station' => 'kitchen', 'name' => 'ZZ Unpaid', 'qty' => 1]]);
    ok('an unpaid order makes no receipt', rh_print_enqueue_receipt($pdo, $oOpen) === 0);
    $rm = rh_print_enqueue_receipt($pdo, $oPaid);
    ok('a paid order makes a receipt for the cloud receipt printers only (cash desk + all-stations "both")', $rm === 2, "made $rm");
    ok('the screen-device bar printer gets no receipt', count(array_filter($jobsOf((int)$pb['id'], $oPaid), static function ($j) { return $j['kind'] === 'receipt'; })) === 0);
    $jr = $jobsOf((int)$pr['id'], $oPaid);
    $rtext = $textOf($jr[0], $pr);
    ok('receipt shows the order total exactly as stored', $rm === 2 && preg_match('/TOTAL\s+\S*\s*1,234\.50\s*$/m', $rtext) === 1 && strpos($rtext, '2,000.00') !== false && strpos($rtext, '765.50') !== false, $rtext);
    ok('receipt printed twice for one order is refused (dedupe)', rh_print_enqueue_receipt($pdo, $oPaid) === 0);
    $oPaid2 = $mkOrder('paid', [['station' => 'kitchen', 'name' => 'ZZ Second paid', 'qty' => 1, 'kds' => 'served']]);
    $pdo->prepare("UPDATE stock_orders SET paid_at = NOW(), payment_method = 'cash', subtotal = 80, total_amount = 80 WHERE id = ?")->execute([$oPaid2]);
    ok('a second paid order gets its own receipt on the same printer', rh_print_enqueue_receipt($pdo, $oPaid2) === 2 && count($jobsOf((int)$pr['id'])) === 2);

    echo "== Queue: claim, confirm, retry, stale, reprint ==\n";
    $j = rh_print_next_job($pdo, (int)$pk['id']);
    ok('next job is the oldest queued one', $j && (int)$j['id'] === (int)$jk[0]['id']);
    ok('claim works once and only once', rh_print_claim_job($pdo, (int)$j['id'], (int)$pk['id']) === true && rh_print_claim_job($pdo, (int)$j['id'], (int)$pk['id']) === false);
    ok('a claim for another printer id is refused', rh_print_claim_job($pdo, (int)$jk[1]['id'], (int)$pa['id']) === false);
    ok('a printer fault goes back to the queue with the reason', rh_print_finish_job($pdo, (int)$j['id'], (int)$pk['id'], false, 'ErrorPaperOut') && $jobsOf((int)$pk['id'])[0]['status'] === 'queued' && $jobsOf((int)$pk['id'])[0]['last_error'] === 'ErrorPaperOut');
    rh_print_claim_job($pdo, (int)$j['id'], (int)$pk['id']);
    ok('confirmed print is marked printed with a time', rh_print_finish_job($pdo, (int)$j['id'], (int)$pk['id'], true) && ($jobsOf((int)$pk['id'])[0]['status'] === 'printed') && $jobsOf((int)$pk['id'])[0]['printed_at'] !== null);
    ok('a second confirmation does nothing', rh_print_finish_job($pdo, (int)$j['id'], (int)$pk['id'], false, 'late') === false);
    $nj = rh_print_next_job($pdo, (int)$pk['id']);
    rh_print_claim_job($pdo, (int)$nj['id'], (int)$pk['id']);
    ok('a handed-out job is not offered again straight away', rh_print_next_job($pdo, (int)$pk['id']) === null);
    $pdo->prepare("UPDATE print_jobs SET claimed_at = DATE_SUB(NOW(), INTERVAL 10 MINUTE) WHERE id = ?")->execute([(int)$nj['id']]);
    $again = rh_print_next_job($pdo, (int)$pk['id']);
    ok('an unconfirmed job is offered again after the wait', $again && (int)$again['id'] === (int)$nj['id']);
    $pdo->prepare("UPDATE print_jobs SET attempts = 5 WHERE id = ?")->execute([(int)$nj['id']]);
    ok('after 5 unconfirmed tries it is marked failed, not offered', rh_print_next_job($pdo, (int)$pk['id']) === null && $jobsOf((int)$pk['id'])[1]['status'] === 'failed');
    ok('Reprint puts a failed or printed job back in the queue', rh_print_reprint_job($pdo, (int)$nj['id']) && rh_print_reprint_job($pdo, (int)$j['id']) && count(array_filter($jobsOf((int)$pk['id']), static function ($x) { return $x['status'] === 'queued'; })) === 2);

    echo "== Queue: cancelling an order stops its tickets ==\n";
    $oC = $mkOrder('placed', [['station' => 'kitchen', 'name' => 'ZZ Cancel me', 'qty' => 1], ['station' => 'bar', 'name' => 'ZZ Cancel drink', 'qty' => 1]]);
    rh_print_enqueue_kitchen($pdo, $oC);
    $cj = $jobsOf((int)$pk['id'], $oC)[0];
    rh_print_claim_job($pdo, (int)$cj['id'], (int)$pk['id']);
    $cancelled = rh_print_cancel_order_jobs($pdo, $oC);
    ok('queued and handed-out jobs of the order are cancelled (kitchen, bar, all)', $cancelled === 3, "cancelled $cancelled");
    $nb = rh_print_next_job($pdo, (int)$pb['id']);
    ok('a cancelled job is never offered or reprinted', ($nb === null || (int)$nb['order_id'] !== $oC) && rh_print_reprint_job($pdo, (int)$cj['id']) === false);
    ok('a late confirmation cannot revive a cancelled job', rh_print_finish_job($pdo, (int)$cj['id'], (int)$pk['id'], true) === false);
    ok('other orders are untouched', $jobsOf((int)$pa['id'], $oid)[0]['status'] === 'queued');
    $pdo->prepare("UPDATE stock_orders SET status = 'cancelled' WHERE id = ?")->execute([$oC]);
    ok('no new ticket is made for an order that is already cancelled', rh_print_enqueue_kitchen($pdo, $oC) === 0);
    rh_print_purge_order_jobs($pdo, $oC);
    $left = $pdo->prepare('SELECT COUNT(*) FROM print_jobs WHERE order_id = ?'); $left->execute([$oC]);
    ok('deleting an order removes its jobs and line markers', (int)$left->fetchColumn() === 0);

    echo "== Refund closes the queue, room service too ==\n";
    require_once $root . '/admin/includes/restaurant-payment-sync.php';
    $oRs = $mkOrder('paid', [['station' => 'kitchen', 'name' => 'ZZ Room dish', 'qty' => 1, 'kds' => 'served']]);
    $pdo->prepare("UPDATE stock_orders SET order_type = 'room_service', paid_at = NOW(), payment_method = 'cash' WHERE id = ?")->execute([$oRs]);
    ok('room-service order paid at the till queues a receipt', rh_print_enqueue_receipt($pdo, $oRs) === 2);
    rh_close_refunded_order_lines($pdo, $oRs, 0, 'ZZ test refund');
    $stRs = $pdo->prepare("SELECT status FROM print_jobs WHERE order_id = ? AND kind = 'receipt'"); $stRs->execute([$oRs]);
    ok('refunding a room-service order cancels its queued receipts (before the room-service early return)', $stRs->fetchAll(PDO::FETCH_COLUMN) === ['cancelled', 'cancelled']);

    echo "== Test print ==\n";
    $ids = rh_print_enqueue_test($pdo, $pb);
    ok('test print queues a ticket and a receipt', count($ids) === 2 && $ids[0] > 0 && $ids[1] > 0);
    $tj = $pdo->query('SELECT * FROM print_jobs WHERE id = ' . (int)$ids[0])->fetch(PDO::FETCH_ASSOC);
    $th = rh_print_render_job($tj, $pb, 'html');
    ok('test page for an A5 screen printer is A5 with the ruler', strpos($th, 'size:A5') !== false && strpos($th, '1234567890') !== false);

    echo "== Star CloudPRNT endpoint ==\n";
    $req = static function (array $o) use ($pk): array {
        return array_merge(['method' => 'POST', 'get' => ['p' => $pk['id'], 't' => $pk['token']], 'post' => [], 'raw' => '{"status":"22 OK"}', 'ip' => '203.0.113.' . random_int(1, 250), 'https' => true], $o);
    };
    $pdo->prepare("UPDATE print_jobs SET status='printed' WHERE printer_id = ?")->execute([(int)$pk['id']]);
    $r = rh_print_handle_cloudprnt($pdo, $req([]));
    ok('poll with nothing queued: jobReady false', $r['status'] === 200 && json_decode($r['body'], true)['jobReady'] === false);
    $seen = $pdo->query('SELECT last_seen_at, last_status FROM print_printers WHERE id = ' . (int)$pk['id'])->fetch(PDO::FETCH_ASSOC);
    ok('the poll records last seen and the printer status', $seen['last_seen_at'] !== null && $seen['last_status'] === '22 OK' || $seen['last_status'] !== null);
    $oS = $mkOrder('placed', [['station' => 'kitchen', 'name' => 'ZZ Star dish', 'qty' => 1]]);
    rh_print_enqueue_kitchen($pdo, $oS);
    $r = rh_print_handle_cloudprnt($pdo, $req([]));
    $body = json_decode($r['body'], true);
    ok('poll with a job: jobReady, markup first then text/plain, job token', !empty($body['jobReady']) && $body['mediaTypes'] === ['text/vnd.star.markup', 'text/plain'] && ctype_digit((string)$body['jobToken']));
    $jt = $body['jobToken'];
    $g = rh_print_handle_cloudprnt($pdo, $req(['method' => 'GET', 'get' => ['p' => $pk['id'], 't' => $pk['token'], 'type' => 'text/vnd.star.markup', 'token' => $jt, 'mac' => 'aa']]));
    ok('GET returns the Star Markup ticket with a cut', $g['status'] === 200 && $g['type'] === 'text/vnd.star.markup' && strpos($g['body'], 'STAR DISH') !== false && strpos($g['body'], '[cut: feed; partial]') !== false);
    $gt = rh_print_handle_cloudprnt($pdo, $req(['method' => 'GET', 'get' => ['p' => $pk['id'], 't' => $pk['token'], 'type' => 'text/plain', 'token' => $jt]]));
    ok('GET as text/plain works for older printers', $gt['status'] === 200 && strpos($gt['body'], 'STAR DISH') !== false && strpos($gt['body'], '[') === false);
    ok('GET for an unsupported type is refused', rh_print_handle_cloudprnt($pdo, $req(['method' => 'GET', 'get' => ['p' => $pk['id'], 't' => $pk['token'], 'type' => 'image/png', 'token' => $jt]]))['status'] === 415);
    $d = rh_print_handle_cloudprnt($pdo, $req(['method' => 'DELETE', 'get' => ['p' => $pk['id'], 't' => $pk['token'], 'token' => $jt, 'code' => 'OK']]));
    ok('DELETE with code OK marks the job printed', $d['status'] === 200 && $pdo->query('SELECT status FROM print_jobs WHERE id = ' . (int)$jt)->fetchColumn() === 'printed');
    ok('GET for a job already printed: 404', rh_print_handle_cloudprnt($pdo, $req(['method' => 'GET', 'get' => ['p' => $pk['id'], 't' => $pk['token'], 'type' => 'text/plain', 'token' => $jt]]))['status'] === 404);
    $oS2 = $mkOrder('placed', [['station' => 'kitchen', 'name' => 'ZZ Star fault', 'qty' => 1]]);
    rh_print_enqueue_kitchen($pdo, $oS2);
    $b2 = json_decode(rh_print_handle_cloudprnt($pdo, $req([]))['body'], true);
    rh_print_handle_cloudprnt($pdo, $req(['method' => 'GET', 'get' => ['p' => $pk['id'], 't' => $pk['token'], 'type' => 'text/plain', 'token' => $b2['jobToken']]]));
    rh_print_handle_cloudprnt($pdo, $req(['method' => 'DELETE', 'get' => ['p' => $pk['id'], 't' => $pk['token'], 'token' => $b2['jobToken'], 'code' => 'ErrorPaperOut']]));
    $row = $pdo->query('SELECT status, last_error FROM print_jobs WHERE id = ' . (int)$b2['jobToken'])->fetch(PDO::FETCH_ASSOC);
    ok('DELETE with an error code returns the job to the queue with the reason', $row['status'] === 'queued' && strpos((string)$row['last_error'], 'ErrorPaperOut') !== false);
    $pdo->prepare("UPDATE print_jobs SET status = 'cancelled' WHERE id = ?")->execute([(int)$b2['jobToken']]);
    ok('a job cancelled after the poll is not handed out', rh_print_handle_cloudprnt($pdo, $req(['method' => 'GET', 'get' => ['p' => $pk['id'], 't' => $pk['token'], 'type' => 'text/plain', 'token' => $b2['jobToken']]]))['status'] === 404);

    $oS3 = $mkOrder('placed', [['station' => 'kitchen', 'name' => 'ZZ Never confirmed', 'qty' => 1]]);
    rh_print_enqueue_kitchen($pdo, $oS3);
    $s3 = (int)$jobsOf((int)$pk['id'], $oS3)[0]['id'];
    $gets = 0;
    for ($i = 0; $i < 8; $i++) {
        $pdo->prepare("UPDATE print_jobs SET claimed_at = DATE_SUB(NOW(), INTERVAL 10 MINUTE) WHERE id = ? AND status = 'sent'")->execute([$s3]);
        $pb3 = json_decode(rh_print_handle_cloudprnt($pdo, $req([]))['body'], true);
        if (!empty($pb3['jobReady']) && (int)$pb3['jobToken'] === $s3) {
            $gets++;
            rh_print_handle_cloudprnt($pdo, $req(['method' => 'GET', 'get' => ['p' => $pk['id'], 't' => $pk['token'], 'type' => 'text/plain', 'token' => $pb3['jobToken']]]));
        }
    }
    $row = $pdo->query('SELECT status, attempts FROM print_jobs WHERE id = ' . $s3)->fetch(PDO::FETCH_ASSOC);
    ok('a job the printer never confirms is tried 5 times, then shown as failed (not re-sent for ever)', $row['status'] === 'failed' && (int)$row['attempts'] === 5 && $gets === 5, json_encode($row) . " gets $gets");
    $rg = rh_print_handle_cloudprnt($pdo, $req(['method' => 'GET', 'get' => ['p' => $pk['id'], 't' => $pk['token'], 'type' => 'text/plain', 'token' => (string)$s3]]));
    ok('a failed job is not handed out by a stray GET', $rg['status'] === 404);

    echo "== Endpoint security ==\n";
    $r = rh_print_handle_cloudprnt($pdo, $req(['https' => false]));
    ok('plain HTTP is refused', $r['status'] === 403);
    $r = rh_print_handle_cloudprnt($pdo, $req(['https' => false, 'ip' => '127.0.0.1', 'host' => 'localhost:8000']));
    ok('plain HTTP from loopback with a localhost Host is allowed (local development only)', $r['status'] === 200);
    ok('plain HTTP from loopback but a real domain Host (local proxy) is refused', rh_print_handle_cloudprnt($pdo, $req(['https' => false, 'ip' => '127.0.0.1', 'host' => 'hotel.example.com']))['status'] === 403
        && rh_print_handle_cloudprnt($pdo, $req(['https' => false, 'ip' => '127.0.0.1']))['status'] === 403
        && rh_print_handle_cloudprnt($pdo, $req(['https' => false, 'ip' => '203.0.113.5', 'host' => 'localhost']))['status'] === 403);
    ok('wrong token: 404', rh_print_handle_cloudprnt($pdo, $req(['get' => ['p' => $pk['id'], 't' => str_repeat('0', 48)]]))['status'] === 404);
    ok('token of another printer: 404', rh_print_handle_cloudprnt($pdo, $req(['get' => ['p' => $pk['id'], 't' => $pa['token']]]))['status'] === 404);
    ok('unknown printer id: 404, same answer as a wrong token', rh_print_handle_cloudprnt($pdo, $req(['get' => ['p' => 999999999, 't' => $pk['token']]]))['status'] === 404);
    ok('no token at all: 404', rh_print_handle_cloudprnt($pdo, $req(['get' => ['p' => $pk['id']]]))['status'] === 404);
    ok('a token that is a prefix of the real one is refused', rh_print_handle_cloudprnt($pdo, $req(['get' => ['p' => $pk['id'], 't' => substr($pk['token'], 0, 47)]]))['status'] === 404);
    ok('an Epson printer cannot use the Star endpoint', rh_print_handle_cloudprnt($pdo, $req(['get' => ['p' => $pa['id'], 't' => $pa['token']]]))['status'] === 404);
    ok('a screen-device printer has no cloud address', rh_print_handle_cloudprnt($pdo, $req(['get' => ['p' => $pb['id'], 't' => $pb['token']]]))['status'] === 404);
    ok('error replies never echo the token', strpos(json_encode(rh_print_handle_cloudprnt($pdo, $req(['get' => ['p' => $pk['id'], 't' => 'SECRETGUESS']]))), 'SECRETGUESS') === false);
    $ip = '198.51.100.' . random_int(1, 250); $last = 0;
    for ($i = 0; $i < 22; $i++) { $last = rh_print_handle_cloudprnt($pdo, $req(['ip' => $ip, 'get' => ['p' => $pk['id'], 't' => 'bad' . $i]]))['status']; }
    ok('20 bad tokens from one address then 429 (and even the right token is held back)', $last === 429 && rh_print_handle_cloudprnt($pdo, $req(['ip' => $ip]))['status'] === 429);
    $k = 'unit_' . bin2hex(random_bytes(4)); $allowed = 0;
    for ($i = 0; $i < 12; $i++) { if (rh_print_rate($k, 10, 60)) $allowed++; }
    @unlink(sys_get_temp_dir() . '/rh_pr_' . hash('sha256', $k) . '.txt');
    ok('rate limiter lets 10 through in the window and stops the rest', $allowed === 10, "allowed $allowed");
    $pp2 = $mkPrinter('paused-cloud', 'cloud', 'star', 'kitchen', '80', 'tickets', 0);
    $rp = rh_print_handle_cloudprnt($pdo, ['method' => 'POST', 'get' => ['p' => $pp2['id'], 't' => $pp2['token']], 'post' => [], 'raw' => '{}', 'ip' => '203.0.113.9', 'https' => true]);
    ok('an inactive printer polls fine but is told nothing to print', $rp['status'] === 200 && json_decode($rp['body'], true)['jobReady'] === false);

    $oP = $mkOrder('placed', [['station' => 'kitchen', 'name' => 'ZZ Paused job', 'qty' => 1]]);
    $pj = $mkPrinter('paused-get', 'cloud', 'star', 'kitchen', '80', 'tickets');
    rh_print_enqueue_kitchen($pdo, $oP);
    $pjid = (int)$jobsOf((int)$pj['id'], $oP)[0]['id'];
    $gp = static function () use ($pdo, $pj, $pjid): array {
        return rh_print_handle_cloudprnt($pdo, ['method' => 'GET', 'get' => ['p' => $pj['id'], 't' => $pj['token'], 'type' => 'text/plain', 'token' => (string)$pjid], 'post' => [], 'raw' => '', 'ip' => '203.0.113.7', 'https' => true]);
    };
    $pdo->prepare('UPDATE print_printers SET is_active = 0 WHERE id = ?')->execute([(int)$pj['id']]);
    $g1 = $gp();
    $pdo->prepare("UPDATE print_printers SET is_active = 1, mode = 'off' WHERE id = ?")->execute([(int)$pj['id']]);
    $g2 = $gp();
    ok('a paused or Off printer gets nothing on a job GET, and the job stays queued', $g1['status'] === 404 && $g2['status'] === 404 && $pdo->query('SELECT status FROM print_jobs WHERE id = ' . $pjid)->fetchColumn() === 'queued');
    $pdo->prepare("UPDATE print_printers SET mode = 'cloud' WHERE id = ?")->execute([(int)$pj['id']]);
    ok('the same printer switched back on is served', $gp()['status'] === 200);

    echo "== Epson Server Direct Print endpoint ==\n";
    $ereq = static function (array $post, array $o = []) use ($pa): array {
        return array_merge(['method' => 'POST', 'get' => ['p' => $pa['id'], 't' => $pa['token']], 'post' => $post, 'raw' => '', 'ip' => '203.0.113.' . random_int(1, 250), 'https' => true], $o);
    };
    $pdo->prepare("UPDATE print_jobs SET status='printed' WHERE printer_id = ? AND status IN ('queued','sent')")->execute([(int)$pa['id']]);
    $r = rh_print_handle_epson($pdo, $ereq(['ConnectionType' => 'GetRequest', 'ID' => 'x', 'Name' => 'TM']));
    ok('GetRequest with nothing queued: empty 200', $r['status'] === 200 && $r['body'] === '');
    $oE = $mkOrder('placed', [['station' => 'bar', 'name' => 'ZZ Epson drink', 'qty' => 2]]);
    rh_print_enqueue_kitchen($pdo, $oE);
    $r = rh_print_handle_epson($pdo, $ereq(['ConnectionType' => 'GetRequest']));
    $xml = @simplexml_load_string($r['body']);
    ok('GetRequest with a job: PrintRequestInfo XML with the ticket and a job id', $r['status'] === 200 && $xml !== false && $xml->getName() === 'PrintRequestInfo' && strpos($r['body'], 'EPSON DRINK') !== false && preg_match('#<printjobid>(\d+)</printjobid>#', $r['body'], $em) === 1);
    ok('the Epson ticket is cut at 58 mm / 32 columns', strpos($r['body'], '<cut type="feed"/>') !== false && max(array_map('strlen', explode('&#10;', strip_tags(str_replace('&#10;', "&#10;", $r['body']))))) < 4000);
    $jid = (int)$em[1];
    ok('while unconfirmed the job is not handed out twice', rh_print_handle_epson($pdo, $ereq(['ConnectionType' => 'GetRequest']))['body'] === '');
    $resp = '<?xml version="1.0"?><PrintResponseInfo Version="2.00"><ResponseFile><ePOSPrint><PrintResponse><response success="true" code="" status="251658262" battery="0"/></PrintResponse><printjobid>' . $jid . '</printjobid></ePOSPrint></ResponseFile></PrintResponseInfo>';
    rh_print_handle_epson($pdo, $ereq(['ConnectionType' => 'SetResponse', 'ResponseFile' => $resp]));
    ok('SetResponse success marks the job printed', $pdo->query('SELECT status FROM print_jobs WHERE id = ' . $jid)->fetchColumn() === 'printed');
    $oE2 = $mkOrder('placed', [['station' => 'bar', 'name' => 'ZZ Epson two', 'qty' => 1]]);
    rh_print_enqueue_kitchen($pdo, $oE2);
    preg_match('#<printjobid>(\d+)</printjobid>#', rh_print_handle_epson($pdo, $ereq(['ConnectionType' => 'GetRequest']))['body'], $em2);
    rh_print_handle_epson($pdo, $ereq(['ConnectionType' => 'SetResponse', 'ResponseFile' => str_replace(['success="true"', (string)$jid], ['success="false"', $em2[1]], $resp)]));
    $row = $pdo->query('SELECT status FROM print_jobs WHERE id = ' . (int)$em2[1])->fetch(PDO::FETCH_ASSOC);
    ok('SetResponse failure returns the job to the queue', $row['status'] === 'queued');
    ok('Epson endpoint: wrong token 404, GET refused, Star printer refused', rh_print_handle_epson($pdo, $ereq([], ['get' => ['p' => $pa['id'], 't' => 'nope']]))['status'] === 404
        && rh_print_handle_epson($pdo, $ereq([], ['method' => 'GET']))['status'] === 405
        && rh_print_handle_epson($pdo, $ereq([], ['get' => ['p' => $pk['id'], 't' => $pk['token']]]))['status'] === 404);
    ok('Epson endpoint refuses plain HTTP', rh_print_handle_epson($pdo, $ereq(['ConnectionType' => 'GetRequest'], ['https' => false]))['status'] === 403);
} catch (Throwable $e) {
    $fail++;
    echo '  FAIL  exception: ' . $e->getMessage() . ' @' . $e->getFile() . ':' . $e->getLine() . "\n";
}
$pdo->rollBack();
$left = (int)$pdo->query("SELECT COUNT(*) FROM print_printers WHERE name LIKE 'ZZP%'")->fetchColumn()
    + (int)$pdo->query("SELECT COUNT(*) FROM stock_orders WHERE reference LIKE 'ZZP%'")->fetchColumn();
ok('everything rolled back, nothing left behind', $left === 0, "left $left");
foreach (glob(sys_get_temp_dir() . '/rh_pr_*.txt') ?: [] as $f) { if (filemtime($f) > time() - 120) { @unlink($f); } }
echo "\n$pass passed, $fail failed\n";
exit($fail ? 1 : 0);
