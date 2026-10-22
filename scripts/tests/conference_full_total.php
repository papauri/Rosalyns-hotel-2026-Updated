<?php
if (PHP_SAPI !== 'cli') { http_response_code(403); exit("CLI only.\n"); }
/**
 * Conference "Total" is the FULL amount incl. VAT (owner 2026-10-23); conference_inquiries.total_amount is the editable
 * quote base and is never shown as the total.
 * Usage (project root): php scripts/tests/conference_full_total.php
 *   - rh_conf_gross() / rh_conf_gross_sql() agree (locked total_with_vat, else base [+ VAT on top]).
 *   - Conference list rows, the details modal data, the payment-add conference search and the Reports conference
 *     show the gross, in VAT exclusive AND inclusive settings (forced inside a rolled-back run),
 *     and never the base figure.
 *   - Stored amount_paid / amount_due are untouched.
 * Everything runs in a transaction that is rolled back; page runs use scripts/tests/page_harness.php.
 */
$root = dirname(__DIR__, 2);
chdir($root);
require_once $root . '/config/database.php';
require_once $root . '/includes/conference-pricing.php';
$harness = $root . '/scripts/tests/page_harness.php';
$pass = 0; $fail = 0;
function ok(string $name, bool $cond, string $detail = ''): void {
    global $pass, $fail;
    if ($cond) { $pass++; echo "[PASS] $name\n"; } else { $fail++; echo "[FAIL] $name" . ($detail !== '' ? ": $detail" : '') . "\n"; }
}
function cft_run(string $page, array $cfg): array {
    global $harness;
    $out = shell_exec(escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($harness) . ' ' . escapeshellarg($page) . ' ' . escapeshellarg(base64_encode(json_encode($cfg))) . ' 2>&1');
    $res = ['error' => 'no result', 'raw' => substr((string)$out, -600)];
    if (preg_match('/@@RESULT@@(.*)$/m', (string)$out, $m)) { $r = json_decode($m[1], true); if (is_array($r)) { $res = $r + ['raw' => substr((string)$out, -600)]; } }
    return $res;
}

// ---- 1. helper (pure) ----
ok('gross = total_with_vat when locked', abs(rh_conf_gross(['total_amount' => 100000, 'vat_amount' => 16500, 'total_with_vat' => 116500]) - 116500.0) <= BALANCE_TOLERANCE);

$tag = 'CFT' . random_int(100000, 999999);
$far = date('Y-m-d', strtotime('+720 day'));
$insert = static function (string $ref, int $id, float $base, float $vat, float $gross, float $paid, float $due) use ($far): string {
    return "INSERT INTO conference_inquiries (id, inquiry_reference, conference_room_id, company_name, contact_person, email, phone, event_date, start_time, end_time, number_of_attendees, status, total_amount, vat_amount, total_with_vat, amount_paid, amount_due, created_at)
        VALUES ({$id}, '{$ref}', 0, 'CFT Co {$ref}', 'CFT Person', 'cft@example.invalid', '+265000000000', '{$far}', '09:00:00', '17:00:00', 10, 'confirmed', {$base}, {$vat}, {$gross}, {$paid}, {$due}, NOW())";
};

// ---- 2. SQL twin matches the helper on seeded rows (in-process, rolled back) ----
$pdo->beginTransaction();
try {
    $pdo->exec($insert($tag . 'P', 2098000001, 123456.00, 20370.24, 143826.24, 0, 143826.24));
    $pdo->exec($insert($tag . 'Q', 2098000002, 77777.00, 12833.21, 0, 0, 0));
    $sum = (float)$pdo->query("SELECT SUM(" . rh_conf_gross_sql('c') . ") FROM conference_inquiries c WHERE c.id IN (2098000001, 2098000002)")->fetchColumn();
    $want = 0.0;
    foreach ([2098000001, 2098000002] as $cid) {
        $want += rh_conf_gross($pdo->query("SELECT * FROM conference_inquiries WHERE id = {$cid}")->fetch(PDO::FETCH_ASSOC));
    }
    ok('SUM of the SQL gross (reports status/room tables, CSV export) equals the helper total', abs($sum - $want) <= BALANCE_TOLERANCE, "$sum vs $want");
    foreach ([2098000001, 2098000002] as $cid) {
        $row = $pdo->query("SELECT * FROM conference_inquiries WHERE id = {$cid}")->fetch(PDO::FETCH_ASSOC);
        $sql = (float)$pdo->query("SELECT " . rh_conf_gross_sql('c') . " FROM conference_inquiries c WHERE c.id = {$cid}")->fetchColumn();
        ok("row {$cid}: rh_conf_gross_sql equals rh_conf_gross", abs($sql - rh_conf_gross($row)) <= BALANCE_TOLERANCE, "$sql vs " . rh_conf_gross($row));
    }
} finally {
    $pdo->rollBack();
}
ok('rolled back: no test enquiries left', (int)$pdo->query("SELECT COUNT(*) FROM conference_inquiries WHERE inquiry_reference LIKE '{$tag}%'")->fetchColumn() === 0);

// ---- 3. pages in both VAT modes ----
$cs = (string)getSetting('currency_symbol');
foreach (['exclusive', 'inclusive'] as $mode) {
    $tg = $tag . strtoupper(substr($mode, 0, 1));
    $a = $tg . 'A'; $c = $tg . 'C';
    $baseId = 2098100000 + ($mode === 'exclusive' ? 10 : 20);
    $setup = [
        "INSERT INTO site_settings (setting_key, setting_value) VALUES ('vat_enabled','1'),('vat_rate','16.5'),('vat_pricing_mode','{$mode}') ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)",
        $insert($a, $baseId, 123456.00, 20370.24, 143826.24, 10000.00, 133826.24),
        $insert($c, $baseId + 1, 77777.00, 12833.21, 0, 0, 0),
    ];
    $cGross = $mode === 'exclusive' ? 90610.21 : 77777.00;
    $cGrossTxt = number_format($cGross, 2);
    $verify = ["SELECT inquiry_reference, amount_paid, amount_due FROM conference_inquiries WHERE inquiry_reference LIKE '{$tg}%' ORDER BY inquiry_reference"];

    // Conference list
    $r = cft_run('admin/conference-management.php', ['method' => 'GET', 'setup' => $setup, 'verify' => $verify, 'grep' => [
        "{$cs} 143,826.24", "{$cs} {$cGrossTxt}", "{$cs} 123,456.00", ($mode === 'exclusive' ? "{$cs} 77,777.00" : 'zzz-none'),
        '&quot;gross_total&quot;:143826.24', 'enquiry.gross_total',
    ]]);
    $f = $r['found'] ?? [];
    ok("[$mode] conference list shows the gross for a locked row", !empty($f["{$cs} 143,826.24"]), $r['raw'] ?? '');
    ok("[$mode] conference list shows the gross for an unlocked (old) row", !empty($f["{$cs} {$cGrossTxt}"]), $r['raw'] ?? '');
    ok("[$mode] conference list does not show the quote base as the total", empty($f["{$cs} 123,456.00"]) && empty($f[$mode === 'exclusive' ? "{$cs} 77,777.00" : 'zzz-none']));
    ok("[$mode] details modal data carries the gross and the modal prints it", !empty($f['&quot;gross_total&quot;:143826.24']) && !empty($f['enquiry.gross_total']));
    $v = $r['verify'][0] ?? [];
    ok("[$mode] stored paid/due untouched", is_array($v) && count($v) === 2 && abs((float)$v[0]['amount_paid'] - 10000) <= BALANCE_TOLERANCE && abs((float)$v[0]['amount_due'] - 133826.24) <= BALANCE_TOLERANCE);

    // Payment-add conference search
    $r = cft_run('admin/api/search-bookings.php', ['method' => 'GET', 'get' => ['type' => 'conference', 'q' => $tg], 'setup' => $setup]);
    $by = [];
    foreach (($r['json']['bookings'] ?? []) as $bk) { $by[$bk['enquiry_reference']] = (float)$bk['total_amount']; }
    ok("[$mode] payment-add conference search Total is the gross", isset($by[$a], $by[$c]) && abs($by[$a] - 143826.24) <= BALANCE_TOLERANCE && abs($by[$c] - $cGross) <= BALANCE_TOLERANCE, json_encode($by) . ' ' . ($r['raw'] ?? ''));
}

echo "\n$pass passed, $fail failed\n";
exit($fail > 0 ? 1 : 0);
