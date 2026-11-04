<?php
if (PHP_SAPI !== 'cli') { http_response_code(403); exit("CLI only.\n"); } // never runnable over the web
/**
 * The Accounting "Decisions" panel (.claude/AI_REVIEW_PANELS_PARKED.md, Phase 3): the dashboard prints nothing when the
 * panel is off (AI off / no permission / finance module off: empty string, nothing printed, engine not loaded) and the
 * page source differs from before only by one require and one echo inside an existing PHP block (byte-identical when
 * off); the five accounting checks (cash outlook, collections, profit by department, control/leakage, VAT and levy) on
 * fixture rows, each figure equal to the screen's own rule; refunds shown in full and never netted; tiers; the panel
 * rendered after one fake wording call (names never leave), plain wording, a helper line on every column and figure.
 *
 * Usage (from the repo root): php scripts/tests/ai_review_accounting_test.php
 * No network (fake transport). Live DB (Liwonde, the test hotel): every fixture is written inside a transaction that is
 * rolled back; settings are overridden in memory only.
 */
$root = dirname(__DIR__, 2);
chdir($root);
$pass = 0; $fail = 0; $skip = 0;
function ok(string $l): void { global $pass; $pass++; echo "PASS $l\n"; }
function bad(string $l, string $d = ''): void { global $fail; $fail++; echo "FAIL $l" . ($d !== '' ? ": $d" : '') . "\n"; }
function check(bool $c, string $l, string $d = ''): bool { $c ? ok($l) : bad($l, $d); return $c; }
function skip(string $l): void { global $skip; $skip++; echo "SKIP $l\n"; }
function near(float $a, float $b): bool { return abs($a - $b) < BALANCE_TOLERANCE; }

require_once $root . '/config/database.php';
require_once $root . '/config/security.php';
require_once $root . '/admin/includes/permissions.php';
require_once $root . '/includes/ai/ai-core.php';
require_once $root . '/admin/includes/review-panel.php';

$engineFile = realpath($root . '/includes/ai/ai-decisions.php');
$engineLoaded = static fn(): bool => in_array($engineFile, array_map('realpath', get_included_files()), true);
$onSql = ["UPDATE site_settings SET setting_value = '1' WHERE setting_key = 'ai_enabled'", "UPDATE site_settings SET setting_value = '' WHERE setting_key = 'ai_paused_reason'",
    "UPDATE enabled_modules SET is_enabled = 1 WHERE module_key = 'ai_assistant'"];
$adm = $pdo->query("SELECT id, role, username, full_name FROM admin_users WHERE is_active = 1 AND role = 'admin' ORDER BY id LIMIT 1")->fetch(PDO::FETCH_ASSOC);
if (!$adm) {
    echo "No active admin account: nothing can be checked.\n";
    exit(1);
}
$adm['id'] = (int)$adm['id'];
$_SESSION = ['admin_user_id' => $adm['id'], 'admin_role' => 'admin', 'admin_username' => (string)$adm['username'], 'admin_full_name' => (string)$adm['full_name']];
$mkUser = static function (PDO $db, string $tag, array $grant): int {
    $db->prepare("INSERT INTO admin_users (username, email, password_hash, full_name, role, is_active) VALUES (?, ?, ?, ?, 'receptionist', 1)")
        ->execute(['dectest_' . $tag . bin2hex(random_bytes(2)), 'dectest.' . $tag . '@example.com', password_hash(bin2hex(random_bytes(8)), PASSWORD_DEFAULT), 'Dectest ' . ucfirst($tag)]);
    $id = (int)$db->lastInsertId();
    $g = $db->prepare("INSERT INTO user_permissions (user_id, permission_key, is_granted) VALUES (?, ?, ?) ON DUPLICATE KEY UPDATE is_granted = VALUES(is_granted)");
    foreach (array_keys(getAllPermissions()) as $k) {
        $g->execute([$id, $k, in_array($k, $grant, true) ? 1 : 0]);
    }
    return $id;
};
$render = static function (): array {
    ob_start();
    $h = rh_review_panel('accounting');
    return [$h, (string)ob_get_clean()];
};

// ════ 1. The dashboard is unchanged when the panel is off ════
$dash = 'admin/accounting-dashboard.php';
$req = "require_once __DIR__ . '/includes/review-panel.php'; // the Decisions panel: prints nothing unless switched on and allowed\n";
$echoNew = "<?php endif; echo rh_review_panel('accounting'); ?>";
$norm = static fn(string $s): string => str_replace("\r\n", "\n", $s);
$src = $norm((string)file_get_contents($root . '/' . $dash));
check(substr_count($src, "rh_review_panel('accounting')") === 1 && substr_count($src, $echoNew) === 1 && substr_count($src, $req) === 1,
    'dashboard: one require and one echo, appended inside an existing PHP block (no new line, no whitespace printed)');
$ckOpen = strpos($src, '<div class="ck ck-acct" data-ck-root>');
$kpi = strpos($src, '<!-- ============ KPI STRIP');
$at = strpos($src, $echoNew);
check($ckOpen !== false && $kpi !== false && $at > $ckOpen && $at < $kpi, 'dashboard: the panel sits inside the page container, after the period filter and before the key figures');
$git = static fn(string $args): string => (string)shell_exec('git -C ' . escapeshellarg($root) . ' ' . $args . ' 2>' . (DIRECTORY_SEPARATOR === '\\' ? 'NUL' : '/dev/null'));
$intro = trim((string)preg_replace('/\s+/', ' ', $git('log --format=%H -S' . escapeshellarg("rh_review_panel('accounting')") . ' -- ' . $dash)));
$intro = $intro !== '' ? (string)substr($intro, strrpos(' ' . $intro, ' ')) : '';
$after = $intro === '' ? $src : $norm($git('show ' . $intro . ':' . $dash));
$before = $norm($git('show ' . ($intro === '' ? 'HEAD' : $intro . '~1') . ':' . $dash));
if ($before === '') {
    skip('dashboard source before the panel could not be read from git');
} else {
    $stripped = str_replace([$req, $echoNew], ['', '<?php endif; ?>'], $after);
    check($stripped === $before, 'dashboard: with the one require and the one echo taken out, the source is byte-for-byte what it was before the panel', $intro === '' ? 'vs HEAD' : 'vs ' . $intro . '~1');
}

// ════ 2. Gate off: nothing at all ════
$pdo->beginTransaction();
try {
    $pdo->exec("UPDATE site_settings SET setting_value = '0' WHERE setting_key = 'ai_enabled'");
    [$h, $printed] = $render();
    check($h === '' && $printed === '' && !$engineLoaded(), 'AI off: the accounting panel is an empty string, nothing printed, the engine not loaded');
    foreach ($onSql as $q) { $pdo->exec($q); }
    if (!rh_ai_enabled($pdo)) {
        skip('AI could not be switched on inside the test transaction: permission / module cases skipped');
    } else {
        $noUse = $mkUser($pdo, 'nouse', ['dashboard', 'accounting', 'reports']);
        $noAcc = $mkUser($pdo, 'noacc', ['dashboard', 'ai_assistant_use', 'bookings', 'stock_dashboard']);
        foreach ([[$noUse, 'no Assistant use permission'], [$noAcc, 'no accounting or reports permission']] as [$u, $why]) {
            $_SESSION['admin_user_id'] = $u;
            $_SESSION['admin_role'] = 'receptionist';
            [$h, $printed] = $render();
            check($h === '' && $printed === '' && !$engineLoaded(), "permission off ($why): empty string, nothing printed, engine not loaded");
        }
        $_SESSION['admin_user_id'] = $adm['id'];
        $_SESSION['admin_role'] = 'admin';
        $GLOBALS['__rh_ai_module_override'] = ['finance' => false];
        [$h, $printed] = $render();
        check($h === '' && $printed === '' && !$engineLoaded(), 'finance module off: empty string, nothing printed, engine not loaded');
        unset($GLOBALS['__rh_ai_module_override']);
    }
} finally {
    $pdo->rollBack();
    $_SESSION['admin_user_id'] = $adm['id'];
    $_SESSION['admin_role'] = 'admin';
}

// ════ 3. The accounting checks ════
require_once $root . '/includes/ai/ai-decisions.php';
if (!rh_dec_ready($pdo, true)) {
    skip('migration 075 has not run on this database: accounting checks skipped');
    echo "\n$pass passed, $fail failed, $skip skipped\n";
    exit($fail > 0 ? 1 : 0);
}
$ids = array_keys(rh_dec_finders()['accounting']);
check($ids === ['acc_collections', 'acc_leakage', 'acc_cash', 'acc_profit', 'acc_tax'], 'accounting has the five checks: collections, control/leakage, cash outlook, profit by department, VAT and levy', implode(',', $ids));
$b0 = rh_dec_build($pdo, 'accounting', rh_dec_context($pdo, true));
check(count($b0['checks']) === 5 && !array_filter($b0['checks'], static fn($k) => empty($k['ok'])), 'every accounting check runs on this database as it is', json_encode($b0['checks']));
$engSrc = (string)file_get_contents($engineFile);
preg_match_all('/(INSERT INTO|(?<!KEY )UPDATE|DELETE FROM)\s+`?([a-z_]+)/i', $engSrc, $wm);
check(!array_values(array_filter(array_unique($wm[2]), static fn($t) => strpos($t, 'ai_review_') !== 0)), 'the engine still writes only to the ai_review_* tables');
$a0 = strpos($engSrc, 'function rh_dec_shortages_by_staff');
$a1 = strpos($engSrc, '// ── Finders: stock');
$accSrc = ($a0 !== false && $a1 !== false) ? substr($engSrc, $a0, $a1 - $a0) : '';
check($accSrc !== '' && !preg_match('/\b(MRA|submit|submission|fiscal|file the|return to)/i', $accSrc), 'no MRA, submission or filing wording in the accounting checks');
check($accSrc !== '' && !preg_match('/[^=!<>]==[^=]\s*[0-9.]+|\$\w+\s*==\s*0\.0/', $accSrc), 'money in the accounting checks is compared with the tolerance, never with float equality');

$settingsKeep = $GLOBALS['_SITE_SETTINGS'] ?? [];
$GLOBALS['dec_calls'] = [];
putenv('RH_AI_FAKE_PROVIDER=dectest');
rh_ai_set_transport(static function ($method, $url, $headers, $body, $timeout) {
    $GLOBALS['dec_calls'][] = ['body' => (string)$body];
    // Word every card with its own fixed texts (only figures it already holds), found inside the request
    $find = static function ($v) use (&$find) {
        if (is_string($v) && strpos($v, '"cards"') !== false) {
            $j = json_decode($v, true);
            if (is_array($j) && isset($j['cards'])) { return $j; }
        }
        if (is_array($v)) { foreach ($v as $x) { $r = $find($x); if ($r !== null) { return $r; } } }
        return null;
    };
    $p = $find(json_decode((string)$body, true));
    $out = ['order' => [], 'cards' => []];
    foreach ((array)($p['cards'] ?? []) as $c) {
        $out['order'][] = $c['id'];
        $out['cards'][$c['id']] = ['decision' => $c['decision'], 'why_now' => $c['why_now'], 'recommendation' => $c['recommendation']];
    }
    return ['http' => 200, 'body' => json_encode(rh_ai_fake_body('google', ['text' => json_encode($out), 'usage' => ['in' => 90, 'out' => 60]], 'test-fast-model'))];
});
$GLOBALS['__rh_dec_models'] = ['provider' => 'google', 'fast' => ['model_id' => 'test-fast-model', 'price_in_per_mtok' => null, 'price_out_per_mtok' => null]];

$winFrom = date('Y-m-d', strtotime('-' . (2 * rh_dec_num('ai_review_base_days', 7, 92) + 5) . ' days'));
$stLive = $pdo->prepare("SELECT (SELECT COUNT(*) FROM payments WHERE deleted_at IS NULL AND payment_date >= ?) + (SELECT COUNT(*) FROM stock_shift_closes WHERE shift_date >= ?)");
$stLive->execute([$winFrom, $winFrom]);
$live = (int)$stLive->fetchColumn();
if ($live > 0) {
    skip('fixture figures: this database has ' . $live . ' real payment / till-close rows in the last ' . (2 * rh_dec_num('ai_review_base_days', 7, 92) + 5) . ' days, so the exact-figure checks run only on the test hotel (nothing is changed or locked here)');
} else {
$pdo->beginTransaction();
try {
    foreach ($onSql as $q) { $pdo->exec($q); }
    $set = $pdo->prepare("INSERT INTO site_settings (setting_key, setting_value, setting_group) VALUES (?, ?, 'ai') ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)");
    foreach (['ai_cloud_enabled' => '1', 'ai_review_writer_enabled' => '1', 'ai_review_impact_min_pct' => '0.01', 'ai_review_cards_max' => '8'] as $k => $v) { $set->execute([$k, $v]); }
    rh_ai_settings($pdo, true);
    // VAT on at 17.5%, prices include VAT (in memory only)
    foreach (['vat_enabled' => '1', 'vat_rate' => '17.5', 'vat_pricing_mode' => 'inclusive'] as $k => $v) { $GLOBALS['_SITE_SETTINGS'][$k] = $v; }
    $today = date('Y-m-d');
    $days = rh_dec_num('ai_review_base_days', 7, 92);
    $old = date('Y-m-d', strtotime('-' . (2 * $days - 1) . ' days')); // inside the period before the base period
    $pdo->exec("DELETE FROM ai_review_cache WHERE cache_day = CURDATE() AND area = 'accounting'");
    $pdo->prepare("INSERT INTO gym_inquiries (reference_number, name, email, phone, total_amount, vat_amount, total_with_vat) VALUES ('DECTEST-G', 'Dectest Member', 'dectest.member@example.com', '000', 1000, 175, 1175)")->execute();
    $gymId = (int)$pdo->lastInsertId();
    $pay = $pdo->prepare("INSERT INTO payments (payment_reference, booking_type, booking_id, booking_reference, payment_date, payment_amount, vat_rate, vat_amount, total_amount, payment_method, payment_type, payment_status, refund_status, refund_amount, refund_reason, recorded_by)
                          VALUES (?, ?, ?, 'DECTEST-B', ?, ?, ?, ?, ?, 'cash', ?, 'completed', ?, ?, ?, ?)");
    $p = static function (string $ref, string $type, string $date, float $net, float $rate, float $vat, float $tot, string $ptype = 'full_payment', ?string $rs = null, float $ra = 0.0, int $acc = 0) use ($pay, $adm) {
        $pay->execute(['DECTEST-' . $ref, $type, $acc, $date, $net, $rate, $vat, $tot, $ptype, $rs, $ra, $ptype === 'refund' ? 'other' : null, $adm['id']]);
    };
    $p('NOVAT', 'room', $today, 1000, 0, 0, 1000);             // no VAT, account with no VAT of its own (zero-rated): not questioned
    $p('NOVATACC', 'gym', $today, 1175, 0, 0, 1175, 'full_payment', null, 0.0, $gymId); // no VAT, the account carries 175 of 1175: 175.00
    $p('OK', 'restaurant', $today, 1000, 17.5, 175, 1175);     // right
    $p('SPLIT', 'conference', $today, 1000, 17.5, 300, 1175);  // 1000 + 300 is not 1175: 125.00
    $p('OVER', 'gym', $today, 800, 17.5, 200, 1000);           // more than 17.5/117.5 of 1000 (148.94): 51.06
    $p('OLDRATE', 'room', $today, 1000, 16.5, 165, 1165);      // right at its own (older) rate: listed only
    $p('REFUND', 'restaurant', $today, 500, 0, 0, 500, 'refund', 'completed', 500);
    $p('PENDREF', 'room', $today, 300, 0, 0, 300, 'refund', 'pending', 300);
    $p('EARLIER', 'room', $old, 8510.64, 17.5, 1489.36, 10000); // the period before: rooms took more
    $pdo->prepare("INSERT INTO stock_purchase_orders (reference, status, order_date, subtotal, total_cost) VALUES ('DECTEST-PO1', 'sent', ?, 4000, 4000), ('DECTEST-PO2', 'partial', ?, 1000, 1000)")->execute([$today, $today]);
    $sc = $pdo->prepare("INSERT INTO stock_shift_closes (user_id, user_name, shift_date, closed_at, variance_cash, override_applied) VALUES (?, 'Dectest Tillstaff', ?, NOW(), -50, ?)");
    $sc->execute([$adm['id'], $today, 0]);
    $sc->execute([$adm['id'], date('Y-m-d', strtotime('-1 day')), 1]);

    // Cash covered (takings after refunds 6190.00 over the period against 5300.00 due out): no card
    $covered = rh_dec_f_cash($pdo, rh_dec_context($pdo, true));
    check($covered['ok'] && !$covered['cards'] && $covered['total'] > 0, 'cash: the recent takings cover what is due out: no card (the check still counts as run, with a checked total)', json_encode($covered));
    $pdo->prepare("INSERT INTO stock_purchase_orders (reference, status, order_date, subtotal, total_cost) VALUES ('DECTEST-PO3', 'sent', ?, 2000, 2000)")->execute([$today]);

    $ctx = rh_dec_context($pdo, true);
    $ml = $ctx['run']('money_leaks', ['from' => $ctx['from'], 'to' => $ctx['to']]);
    $b = rh_dec_build($pdo, 'accounting', $ctx);
    $cards = [];
    foreach (array_merge($b['main'], $b['more']) as $c) { $cards[$c['key']] = $c; }
    check(!array_filter($b['checks'], static fn($k) => empty($k['ok'])), 'with the fixture rows every accounting check still runs');

    // VAT and levy
    $t = $cards['acc_tax'] ?? null;
    check($t !== null && near((float)$t['at_stake'], 351.06), 'VAT check: at stake = no-VAT row on an account that carries VAT (its own share, 175.00) + split that does not add up (125.00) + VAT over the rate (51.06)', json_encode($t['at_stake'] ?? null));
    $dashSql = preg_match('/\$financialStmt = \$pdo->prepare\("(.*?)"\);/s', (string)file_get_contents($root . '/' . $dash), $mm) ? $mm[1] : '';
    $vatRow = null;
    foreach ((array)($t['evidence']['rows'] ?? []) as $r) { if (strpos((string)$r['label'], 'VAT owed') === 0) { $vatRow = $r; } }
    if ($dashSql !== '') {
        $st = $pdo->prepare($dashSql);
        $st->execute([$ctx['from'], $ctx['to']]);
        $dv = (float)($st->fetch(PDO::FETCH_ASSOC)['total_vat_collected'] ?? -1);
        check($vatRow !== null && near((float)$vatRow['amount'], $dv) && near($dv, 840.00), 'VAT check: the VAT owed equals the Accounting dashboard\'s own query for the same days (840.00)', json_encode([$vatRow['amount'] ?? null, $dv]));
    } else {
        bad('the dashboard\'s summary query could not be found to compare with');
    }
    $labels = implode(' | ', array_column((array)($t['evidence']['rows'] ?? []), 'label'));
    check(strpos($labels, 'other than today') !== false && strpos($labels, 'DECTEST') === false && (int)$t['tier'] === 3 && strpos((string)$t['link'][0], 'tab=vat&start_date=' . $ctx['from'] . '&end_date=' . $ctx['to']) !== false,
        'VAT check: older-rate rows listed only, tier 3 (never automatic), the link opens the VAT register for the same days', $labels);
    check(count(array_filter((array)$t['evidence']['rows'], static fn($r) => in_array($r['reference'] ?? '', ['DECTEST-OK', 'DECTEST-OLDRATE', 'DECTEST-NOVAT'], true))) === 0, 'VAT check: right rows, and a no-VAT row on a zero-rated account, are not questioned');
    $GLOBALS['_SITE_SETTINGS']['vat_enabled'] = '0';
    $off = rh_dec_f_tax($pdo, $ctx);
    check($off['ok'] && !$off['cards'], 'VAT switched off: no VAT row is questioned (and no levy flag without bookings)');
    $GLOBALS['_SITE_SETTINGS']['vat_enabled'] = '1';

    // Control and leakage: refunds in full, listed, never counted or netted
    $l = $cards['acc_leakage'] ?? null;
    $expL = (float)($ml['till_voids']['total'] ?? 0) + (float)($ml['till_shortages']['total'] ?? 0) + (float)($ml['folio_charges_voided']['total'] ?? 0);
    check($l !== null && near((float)$l['at_stake'], $expL) && near((float)$ml['till_shortages']['total'], 100.0), 'control: at stake = voids + till shortages + removed charges (the shared read), refunds not in it', json_encode([$l['at_stake'] ?? null, $expL]));
    $rows = (array)($l['evidence']['rows'] ?? []);
    $shortRow = array_values(array_filter($rows, static fn($r) => ($r['staff_name'] ?? '') === 'Dectest Tillstaff'))[0] ?? null;
    check($shortRow !== null && strpos((string)$shortRow['label'], '2 time(s), repeated, 1 with override') !== false && near((float)$shortRow['amount'], 100.0), 'control: day-close shortages grouped by person, repeats and overrides shown', json_encode($shortRow));
    $refRow = array_values(array_filter($rows, static fn($r) => strpos((string)$r['label'], 'Refunds paid out in full') === 0))[0] ?? null;
    check($refRow !== null && near((float)$refRow['amount'], 500.0) && near((float)$ml['refunds']['total'], 500.0) && strpos((string)$l['why_now'], 'in full') !== false, 'control: refunds shown at the full amount paid back (500.00), listed, not counted');

    // Cash outlook
    $c = $cards['acc_cash'] ?? null;
    $poSum = (float)$pdo->query("SELECT COALESCE(SUM(total_cost), 0) FROM stock_purchase_orders WHERE status IN ('sent', 'partial')")->fetchColumn();
    $pendSum = (float)$pdo->query("SELECT COALESCE(SUM(refund_amount), 0) FROM payments WHERE deleted_at IS NULL AND payment_type = 'refund' AND refund_status = 'pending'")->fetchColumn();
    check($c !== null && near((float)$c['at_stake'], $poSum + $pendSum) && $poSum >= 5000 - BALANCE_TOLERANCE, 'cash: due out = open purchase orders at order cost + refunds waiting, in full', json_encode([$c['at_stake'] ?? null, $poSum, $pendSum]));
    $pace = round(((float)$ctx['base']['amount'] - (float)$ml['refunds']['total']) / $days, 2);
    $exp = round($pace * $days - ($poSum + $pendSum), 2);
    $hist = rh_dec_payment_history_days($pdo);
    $o0 = $c['options'][0] ?? [];
    if ($hist >= (int)$ctx['history_min']) {
        check(near((float)$o0['expected'], $exp) && near((float)$o0['worst'], -($poSum + $pendSum)) && near((float)$o0['best'], $exp + (float)$ml['still_owed']['left_or_overdue_total']),
            'cash: expected = recent takings pace x days - due out; worst = nothing comes in; best adds the overdue money', json_encode([$o0, $exp]));
        check(($exp < 0) === ((int)$c['recommend'] === 1) && (int)$c['tier'] === (int)$c['options'][(int)$c['recommend']]['tier'], 'cash: holding orders is recommended only when the expected position is below zero; the card\'s tier is the recommended option\'s');
    } else {
        check($o0['expected'] === null, 'cash: without enough history no expected figure is invented');
    }
    check(stripos((string)$c['at_stake_note'], 'not a runway') !== false && !preg_match('/\brunway of\b/i', (string)$c['why_now']), 'cash: no runway is claimed (no cash or bank balance on record)');

    // Profit by department
    $pr = $cards['acc_profit'] ?? null;
    $net = static function (PDO $db, string $type, string $f, string $t): float {
        $st = $db->prepare("SELECT COALESCE(SUM(CASE WHEN COALESCE(payment_type, '') != 'refund' AND payment_status IN ('completed', 'paid', 'refunded', 'partially_refunded') THEN total_amount - vat_amount ELSE 0 END), 0)
                                 - COALESCE(SUM(CASE WHEN payment_type = 'refund' AND refund_status IN ('completed', 'processing') THEN refund_amount - vat_amount ELSE 0 END), 0)
                            FROM payments WHERE deleted_at IS NULL AND booking_type = ? AND payment_date BETWEEN ? AND ?");
        $st->execute([$type, $f, $t]);
        return (float)$st->fetchColumn();
    };
    $pFrom = date('Y-m-d', strtotime($ctx['from'] . ' -' . $days . ' days'));
    $pTo = date('Y-m-d', strtotime($ctx['from'] . ' -1 day'));
    $drop = round($net($pdo, 'room', $pFrom, $pTo) - $net($pdo, 'room', $ctx['from'], $ctx['to']), 2);
    if ($hist >= 2 * $days) {
        check($pr !== null && near((float)$pr['at_stake'], $drop) && near($drop, 6510.64) && strpos((string)$pr['decision'], 'Rooms') !== false,
            'profit: the biggest fall (Rooms, takings excluding VAT and refunds, against the period before) = 6510.64', json_encode([$pr['at_stake'] ?? null, $drop]));
    } else {
        skip('profit: not enough payment history on this database to compare two periods');
    }
    $prLabels = implode(' | ', array_column((array)($pr['evidence']['rows'] ?? []), 'label'));
    check($pr !== null && !preg_match('/(Rooms|Conference|Gym|Events)[^|]*margin/i', $prLabels) && (int)$pr['tier'] === 1 && (int)$pr['options'][1]['tier'] === 3,
        'profit: no margin shown for departments without costs on record; looking into it is tier 1, prices tier 3', $prLabels);

    // Every card: shape, plain wording, figures traceable, links carry the base period where they are period screens
    foreach ($cards as $k => $cd) {
        check($cd['decision'] !== '' && count($cd['options']) >= 1 && in_array((int)$cd['tier'], [1, 2, 3], true) && $cd['evidence']['total'] > 0 && $cd['at_stake_note'] !== '',
            "card $k: decision, options, tier, a checked total and a note on what the money figure uses");
        check(!preg_match('/\b(AI|smart|insights?|assistant|generated|powered|model|MRA)\b/i', $cd['decision'] . ' ' . $cd['why_now'] . ' ' . $cd['recommendation'] . ' ' . implode(' ', array_column($cd['options'], 'label'))), "card $k: plain wording");
    }
    check(rh_dec_proof_log($pdo, $t, 'ran', $adm['id']) === 0, 'a tier 3 card (VAT corrections) can never be logged as run by itself');

    // The panel, after one wording call
    $r = rh_dec_refresh($pdo, 'accounting', true, $adm['id'], true);
    $body = (string)($GLOBALS['dec_calls'][0]['body'] ?? '');
    check($r['model_called'] && count($GLOBALS['dec_calls']) === 1, 'one wording call for the accounting area');
    check($body !== '' && stripos($body, 'Dectest Tillstaff') === false && strpos($body, 'DECTEST-') === false, 'nothing personal leaves: no staff name, no payment or order reference in the call');
    [$html, $printed] = $render();
    if ((string)getenv('RH_DEC_DUMP') !== '') {
        file_put_contents((string)getenv('RH_DEC_DUMP'), $html); // for the headless look check only (fixture figures)
    }
    check($html !== '' && $printed === '' && strpos($html, '>Decisions</h2>') !== false, 'a good review: the accounting panel is returned (printed only by the dashboard\'s echo)');
    foreach (array_keys(array_filter($cards, static fn($x) => $x['share_pct'] !== null)) as $k) {
        check(strpos($html, 'data-key="' . $k . '"') !== false, "panel shows card $k");
    }
    $ths = substr_count($html, '<th scope="col" class="no-sort">');
    check($ths > 0 && $ths === preg_match_all('/<th scope="col" class="no-sort">[^<]+<span class="rh-dec__help">/', $html), 'every column header in the panel has a plain helper line');
    check(preg_match_all('/<dt>[^<]+<\/dt><dd[^>]*>[^<]*<\/dd><dd class="rh-dec__help">/', $html) === substr_count($html, '<dt>'), 'every figure on every card has a plain helper line');
    check(!preg_match('/\b(AI|smart|insights?|assistant|generated|powered by|thinking)\b/i', strip_tags($html)) && !preg_match('/fa-(magic|wand|robot|brain|star|microchip|hat-wizard)|sparkle|gradient|\bMRA\b/i', $html), 'the panel has no AI-looking words or icons and no MRA wording');
    check(strpos($html, 'Dectest Tillstaff') !== false, 'evidence names are shown on the hotel\'s own screen (they never left it)');
    $GLOBALS['__rh_dec_models'] = ['provider' => ''];
    [$h2, $p2] = $render();
    check($h2 === '' && $p2 === '', 'no model set up: the accounting panel disappears (empty string, no templated cards)');
} finally {
    $pdo->rollBack();
    $GLOBALS['_SITE_SETTINGS'] = $settingsKeep;
    rh_ai_settings($pdo, true);
    unset($GLOBALS['__rh_dec_models']);
    rh_ai_set_transport(null);
    putenv('RH_AI_FAKE_PROVIDER');
}
}
check((int)$pdo->query("SELECT COUNT(*) FROM payments WHERE payment_reference LIKE 'DECTEST-%'")->fetchColumn() === 0
    && (int)$pdo->query("SELECT COUNT(*) FROM gym_inquiries WHERE reference_number = 'DECTEST-G'")->fetchColumn() === 0
    && (int)$pdo->query("SELECT COUNT(*) FROM stock_purchase_orders WHERE reference LIKE 'DECTEST-%'")->fetchColumn() === 0
    && (int)$pdo->query("SELECT COUNT(*) FROM stock_shift_closes WHERE user_name = 'Dectest Tillstaff'")->fetchColumn() === 0
    && (int)$pdo->query("SELECT COUNT(*) FROM admin_users WHERE username LIKE 'dectest\\_%'")->fetchColumn() === 0
    && (int)$pdo->query("SELECT COUNT(*) FROM ai_usage_daily WHERE model_id = 'test-fast-model'")->fetchColumn() === 0, 'everything the checks wrote was rolled back');

echo "\n$pass passed, $fail failed, $skip skipped\n";
exit($fail > 0 ? 1 : 0);
