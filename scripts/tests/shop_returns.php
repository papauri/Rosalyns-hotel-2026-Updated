<?php
if (PHP_SAPI !== 'cli') { http_response_code(403); exit("CLI only.\n"); }
/**
 * Shop / supermarket returns and customer-document wording (owner 2026-10-22). Usage (project root):
 *   php scripts/tests/shop_returns.php
 *  - Shop mode (restaurant off): a void or refund of a paid sale puts the goods BACK on the shelf; flagged
 *    "damaged" it books waste instead; the void endpoint says which. Doing it twice never restores twice.
 *  - Restaurant on: today's kitchen rule is untouched (served food stays consumed, voided to wastage).
 *  - Till receipts: neutral shop wording and an itemised payment PDF in shop mode; dining wording kept otherwise.
 * Every scenario runs in scripts/tests/page_harness.php (outer transaction that never commits).
 */
$root = dirname(__DIR__, 2);
chdir($root);
$harness = $root . '/scripts/tests/page_harness.php';
$pass = 0; $fail = 0;
function ok(string $name, bool $cond, string $detail = ''): void {
    global $pass, $fail;
    if ($cond) { $pass++; echo "[PASS] $name\n"; } else { $fail++; echo "[FAIL] $name" . ($detail !== '' ? ": $detail" : '') . "\n"; }
}
function sr_run(array $post, array $verify = []): array {
    global $harness;
    $cfg = base64_encode(json_encode(['post' => $post, 'ajax' => true, 'verify' => $verify]));
    $out = shell_exec(escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($harness) . ' scripts/tests/shop_returns_child.php ' . escapeshellarg($cfg) . ' 2>&1');
    $res = ['error' => 'no result', 'raw' => substr((string)$out, -600), 'rolled_back' => false];
    if (preg_match('/@@RESULT@@(.*)$/m', (string)$out, $m)) { $r = json_decode($m[1], true); if (is_array($r)) { $res = $r; } }
    return $res;
}
function sr_clean(array $r): bool { return empty($r['leak']) && ($r['rolled_back'] ?? false) && empty($r['marker_lost']); }
$near = static fn($a, $b): bool => abs((float)$a - (float)$b) < 0.0001;

foreach (['void' => 'Void', 'refund' => 'Refund'] as $sc => $label) {
    echo "== Shop mode: $label ==\n";
    $r = sr_run(['scenario' => $sc, 'shop' => '1', 'dmg' => '0']);
    $j = $r['json'] ?? [];
    ok("$label (shop): the sale took 2 off the shelf", ($j['deducted'] ?? false) === true && $near($j['shelf_after_sale'] ?? -1, 8), json_encode($j ?: $r));
    ok("$label (shop): goods back on the shelf", $near($j['shelf_after'] ?? -1, 10) && (int)($j['restored'] ?? 0) === 1 && (int)($j['wasted'] ?? 9) === 0, json_encode($j));
    ok("$label (shop): no waste booked", (int)($j['waste_rows'] ?? 9) === 0);
    ok("$label (shop): line stock flag cleared", (int)($j['line']['stock_deducted'] ?? 1) === 0);
    if ($sc === 'void') {
        ok('Void (shop): the money is reversed', (int)($j['payments_reversed'] ?? 0) >= 1);
        ok('Void (shop): voiding again is refused and puts nothing back twice', ($j['second_void_blocked'] ?? false) === true && $near($j['shelf_after_second'] ?? -1, 10), json_encode($j));
    }
    ok("$label (shop): rolled back", sr_clean($r));

    $r = sr_run(['scenario' => $sc, 'shop' => '1', 'dmg' => '1']);
    $j = $r['json'] ?? [];
    ok("$label (shop, damaged): stays off the shelf", $near($j['shelf_after'] ?? -1, 8) && (int)($j['restored'] ?? 9) === 0 && (int)($j['wasted'] ?? 0) === 1, json_encode($j ?: $r));
    ok("$label (shop, damaged): waste is booked", (int)($j['waste_rows'] ?? 0) >= 1, json_encode($j));
    ok("$label (shop, damaged): rolled back", sr_clean($r));

    echo "== Restaurant on: $label unchanged ==\n";
    $r = sr_run(['scenario' => $sc, 'shop' => '0', 'dmg' => '1']); // even a stray damaged flag changes nothing
    $j = $r['json'] ?? [];
    if ($sc === 'void') {
        ok("$label (restaurant): served food is NOT restored (kitchen rule)", $near($j['shelf_after'] ?? -1, 8) && (int)($j['restored'] ?? 9) === 0 && (int)($j['wasted'] ?? 0) === 1, json_encode($j ?: $r));
        ok("$label (restaurant): booked as wastage", (int)($j['waste_rows'] ?? 0) >= 1, json_encode($j));
    } else {
        ok("$label (restaurant): served line is left alone (food went out)", $near($j['shelf_after'] ?? -1, 8) && (int)($j['restored'] ?? 9) === 0 && (int)($j['wasted'] ?? 9) === 0 && (string)($j['line']['kds_status'] ?? '') === 'served', json_encode($j ?: $r));
    }
    ok("$label (restaurant): rolled back", sr_clean($r));
}

echo "== api/void-order.php message ==\n";
$verifyShelf = ["SELECT current_quantity FROM stock_ingredients WHERE name = 'SHOPRET FIXTURE'"];
$r = sr_run(['scenario' => 'endpoint', 'shop' => '1', 'damaged' => '0'], $verifyShelf);
$j = $r['json'] ?? [];
ok('Endpoint (shop): ok and says the stock was put back', ($j['ok'] ?? false) === true && stripos((string)($j['message'] ?? ''), 'Stock put back') !== false && stripos((string)$j['message'], 'waste') === false, json_encode($j ?: $r));
ok('Endpoint (shop): shelf is whole again', $near($r['verify'][0][0]['current_quantity'] ?? -1, 10), json_encode($r['verify'] ?? null));
ok('Endpoint (shop): rolled back', sr_clean($r));
$r = sr_run(['scenario' => 'endpoint', 'shop' => '1', 'damaged' => '1'], $verifyShelf);
$j = $r['json'] ?? [];
ok('Endpoint (shop, damaged): says recorded as waste', ($j['ok'] ?? false) === true && stripos((string)($j['message'] ?? ''), 'Recorded as waste') !== false && stripos((string)$j['message'], 'Stock put back') === false, json_encode($j ?: $r));
ok('Endpoint (shop, damaged): shelf stays down', $near($r['verify'][0][0]['current_quantity'] ?? -1, 8), json_encode($r['verify'] ?? null));
$r = sr_run(['scenario' => 'endpoint', 'shop' => '0', 'damaged' => '0'], $verifyShelf);
$j = $r['json'] ?? [];
ok('Endpoint (restaurant): served food says recorded as waste, never "restored"', ($j['ok'] ?? false) === true && stripos((string)($j['message'] ?? ''), 'Recorded as waste') !== false && stripos((string)$j['message'], 'Stock restored') === false, json_encode($j ?: $r));
ok('Endpoint (restaurant): shelf stays down', $near($r['verify'][0][0]['current_quantity'] ?? -1, 8), json_encode($r['verify'] ?? null));
ok('Endpoint (restaurant): rolled back', sr_clean($r));

echo "== Customer documents ==\n";
$r = sr_run(['scenario' => 'wording']);
$j = $r['json'] ?? [];
ok('Shop receipt email title is RECEIPT, not RESTAURANT RECEIPT', ($j['shop_email_title'] ?? '') === 'RECEIPT', json_encode($j ?: $r));
ok('Shop receipt footer says shopping (or the hotel own text)', ($j['shop_footer'] ?? '') === ($j['exp_shop'] ?? '?') && ($j['shop_email_footer'] ?? false) === true, json_encode($j));
ok('Restaurant receipt title and footer unchanged', ($j['restaurant_email_title'] ?? '') === 'RESTAURANT RECEIPT' && ($j['restaurant_footer'] ?? '') === ($j['exp_rest'] ?? '?') && ($j['restaurant_email_footer'] ?? false) === true, json_encode($j));
ok('Payment receipt PDF lists the items bought (shop)', ($j['shop_pdf_itemised'] ?? false) === true && ($j['shop_pdf_lump'] ?? true) === false, json_encode($j));
ok('Payment receipt PDF lists the items bought (restaurant on)', ($j['restaurant_pdf_itemised'] ?? false) === true && ($j['restaurant_pdf_lump'] ?? true) === false, json_encode($j));
ok('Lines that do not add up keep the single summary line', ($j['shop_pdf_mismatch_lump'] ?? false) === true && ($j['restaurant_pdf_mismatch_lump'] ?? false) === true, json_encode($j));
ok('Documents scenario rolled back', sr_clean($r));
$r = sr_run(['scenario' => 'vatmodes']);
$j = $r['json'] ?? [];
ok('VAT exclusive: itemised lines are net and add up to the net subtotal', ($j['excl_lines_net'] ?? false) === true, json_encode($j ?: $r));
ok('VAT exclusive: VAT row and total unchanged', ($j['excl_vat_row'] ?? false) === true && ($j['excl_total'] ?? false) === true, json_encode($j));
ok('VAT exclusive: discount/service rows scale to the net too', ($j['excl_disc_rows'] ?? false) === true, json_encode($j));
ok('VAT exclusive: net + VAT not equal to total keeps the summary line', ($j['excl_mismatch_lump'] ?? false) === true, json_encode($j));
ok('VAT inclusive: lines gross, "include VAT" note, no net subtotal', ($j['incl_lines_gross'] ?? false) === true && ($j['incl_vat_note'] ?? false) === true, json_encode($j));
ok('VAT off: plain gross lines, no VAT wording', ($j['off_plain'] ?? false) === true, json_encode($j));
ok('VAT scenario rolled back', sr_clean($r));

echo "\n$pass passed, $fail failed\n";
exit($fail === 0 ? 0 : 1);
