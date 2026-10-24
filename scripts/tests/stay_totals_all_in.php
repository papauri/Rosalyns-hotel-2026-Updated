<?php
if (PHP_SAPI !== 'cli') { http_response_code(403); exit("CLI only.\n"); }
/** Owner 2026-10-24: a room price is ALL-IN (base + VAT + levy). Pure, no DB writes. Usage: php scripts/tests/stay_totals_all_in.php */
$root = dirname(__DIR__, 2);
chdir($root);
require_once $root . '/config/database.php';
require_once $root . '/includes/pricing.php';
$pass = 0; $fail = 0;
function ok(string $n, bool $c, string $d = ''): void { global $pass, $fail; if ($c) { $pass++; echo "[PASS] $n\n"; } else { $fail++; echo "[FAIL] $n" . ($d !== '' ? ": $d" : '') . "\n"; } }
function nr($a, $b): bool { return abs($a - $b) <= 0.011; }
global $_SITE_SETTINGS;
function cfg(string $mode, bool $vat, bool $levy): void {
    global $_SITE_SETTINGS;
    $_SITE_SETTINGS['vat_enabled'] = $vat ? '1' : '0';
    $_SITE_SETTINGS['vat_rate'] = '17.5';
    $_SITE_SETTINGS['vat_pricing_mode'] = $mode;
    $_SITE_SETTINGS['tourism_levy_enabled'] = $levy ? '1' : '0';
    $_SITE_SETTINGS['tourism_levy_percent'] = '1';
}
cfg('inclusive', true, true);
$t = rh_stay_totals(712000, 'price');
ok('inclusive VAT+levy: total 712,000', nr($t['total_with_vat'], 712000), json_encode($t));
ok('inclusive VAT+levy: VAT 105,147.68', nr($t['vat'], 105147.68), json_encode($t));
ok('inclusive VAT+levy: levy 6,008.44', nr($t['levy'], 6008.44), json_encode($t));
ok('inclusive VAT+levy: net 600,843.88 and parts foot', nr($t['net'], 600843.88) && nr($t['net'] + $t['vat'] + $t['levy'], 712000), json_encode($t));
ok('inclusive price == final basis', rh_stay_totals(712000, 'final') === $t);
$p = rh_stay_totals(100000, 'price', false);
ok('applyLevy=false: no levy, VAT extracted, total = price', $p['levy'] == 0.0 && nr($p['total_with_vat'], 100000) && nr($p['vat'], 14893.62), json_encode($p));
cfg('inclusive', true, false);
$t = rh_stay_totals(100000, 'price');
ok('VAT on, levy off: VAT 14,893.62, total 100,000', nr($t['vat'], 14893.62) && $t['levy'] == 0.0 && nr($t['total_with_vat'], 100000), json_encode($t));
cfg('off', false, true);
$t = rh_stay_totals(100000, 'price');
ok('VAT off, levy on: levy 990.10, total 100,000', nr($t['levy'], 990.10) && $t['vat'] == 0.0 && nr($t['total_with_vat'], 100000), json_encode($t));
cfg('inclusive', false, false);
$t = rh_stay_totals(100000, 'price');
ok('both off: net = price', nr($t['net'], 100000) && $t['vat'] == 0.0 && $t['levy'] == 0.0 && nr($t['total_with_vat'], 100000), json_encode($t));
cfg('exclusive', true, true);
$t = rh_stay_totals(100000, 'price');
ok('exclusive mode is also all-in (room price never gets VAT/levy on top): total 100,000, VAT 14,767.93, levy 843.88', nr($t['vat'], 14767.93) && nr($t['levy'], 843.88) && nr($t['total_with_vat'], 100000) && nr($t['net'], 84388.19), json_encode($t));
echo "\n$pass passed, $fail failed\n";
exit($fail > 0 ? 1 : 0);
