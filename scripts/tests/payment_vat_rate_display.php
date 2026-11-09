<?php
if (PHP_SAPI !== 'cli') { http_response_code(403); exit("CLI only.\n"); } // never runnable over the web
/**
 * Payment VAT rate with tourism levy (pure maths, NO database): rh_vat_split_from_totals() used by
 * rh_account_vat_split(), and rh_payment_row_vat_display() used by admin/payment-details.php.
 * The levy sits outside the VAT base, so the rate shown/stored is VAT / net-without-levy.
 * Usage (from the repo root): php scripts/tests/payment_vat_rate_display.php
 */
$root = dirname(__DIR__, 2);
chdir($root);
$pass = 0; $fail = 0;
function ok(string $l): void { global $pass; $pass++; echo "PASS $l\n"; }
function bad(string $l, string $d = ''): void { global $fail; $fail++; echo "FAIL $l" . ($d !== '' ? ": $d" : '') . "\n"; }
function check(bool $c, string $l, string $d = ''): bool { $c ? ok($l) : bad($l, $d); return $c; }
function near(float $a, float $b): bool { return abs($a - $b) < 0.005; }

if (!defined('BALANCE_TOLERANCE')) {
    define('BALANCE_TOLERANCE', 0.01);
}
require_once $root . '/admin/includes/finance-account-sync.php'; // functions only, no DB at load

// Example account: net 1,140,000 + VAT 17.5% 199,500 + levy 1% 11,400 = 1,350,900
$G = 1350900.0; $V = 199500.0; $L = 11400.0;

// ---- Full payment ----
$s = rh_vat_split_from_totals(1350900, $G, $V, $L);
check($s !== null, 'levy account returns a split');
check(near($s['vat'], 199500.00), 'full payment VAT 199,500', (string)$s['vat']);
check(near($s['levy'], 11400.00), 'full payment levy 11,400', (string)$s['levy']);
check(near($s['net_ex_levy'], 1140000.00), 'full payment net (excl. VAT and levy) 1,140,000', (string)$s['net_ex_levy']);
check(near($s['net'], 1151400.00), 'full payment stored net (net + levy) stays 1,151,400', (string)$s['net']);
check(near($s['rate'], 17.50), 'full payment rate 17.50 (not 17.33)', (string)$s['rate']);

// ---- Refund / overpayment 282,900 ----
$r = rh_vat_split_from_totals(282900, $G, $V, $L);
check(near($r['vat'], 41778.48), 'refund VAT 41,778.48', (string)$r['vat']);
check(near($r['levy'], 2387.34), 'refund levy 2,387.34', (string)$r['levy']);
check(near($r['net_ex_levy'], 238734.18), 'refund net 238,734.18', (string)$r['net_ex_levy']);
check(near($r['rate'], 17.50), 'refund rate 17.50', (string)$r['rate']);
check(near($r['vat'] + $r['levy'] + $r['net_ex_levy'], 282900.00), 'refund parts add up to the gross');

// ---- Levy-free account: identical to the old formula (VAT / gross ratio, rate = ratio / (1 - ratio)) ----
$nl = rh_vat_split_from_totals(117500, 117500, 17500, 0.0);
$ratio = 17500 / 117500;
check(near($nl['vat'], round(117500 * $ratio, 2)) && near($nl['vat'], 17500.00), 'levy-free VAT unchanged');
check($nl['rate'] === round($ratio / (1 - $ratio) * 100, 2), 'levy-free rate is the old formula exactly', (string)$nl['rate']);
check(near($nl['rate'], 17.50) && near($nl['levy'], 0.0) && near($nl['net'], 100000.00) && near($nl['net_ex_levy'], 100000.00), 'levy-free net 100,000, levy 0');
// Zero-rated extras still dilute the ratio exactly as before (no levy)
$zr = rh_vat_split_from_totals(1000, 1217.5, 17.5 * 10, 0.0);
$zrRatio = (17.5 * 10) / 1217.5;
check($zr['rate'] === round($zrRatio / (1 - $zrRatio) * 100, 2) && near($zr['vat'], round(1000 * $zrRatio, 2)), 'zero-rated dilution unchanged without levy');
// The 0.99 cap is kept
$cap = rh_vat_split_from_totals(100, 100, 100, 0.0);
check(near($cap['vat'], 99.00) && $cap['rate'] === round(0.99 / 0.01 * 100, 2), 'ratio cap 0.99 kept');

// ---- Edge cases ----
check(rh_vat_split_from_totals(1000, 0, 0, 0) === null, 'no account gross -> null (caller falls back to the rate)');
check(rh_vat_split_from_totals(1000, 1000, 0, 10) === null, 'account without VAT (levy only) -> null');
$z = rh_vat_split_from_totals(0, $G, $V, $L);
check(near($z['vat'], 0) && near($z['levy'], 0) && near($z['net'], 0) && near($z['rate'], 17.50), 'zero gross -> zeros, rate still 17.50');
$neg = rh_vat_split_from_totals(100, 100, 50, 60); // nonsense totals: levy capped, no division by <= 0
check($neg !== null && $neg['levy'] <= $neg['net'] + 0.001 && is_finite($neg['rate']), 'levy larger than the VAT-able base is capped, rate stays finite');

// ---- Display of STORED rows (payment-details) ----
// Existing payment 2099976256: payment_amount 1,151,400 (net + levy), VAT 199,500, stored rate 17.33
$d = rh_payment_row_vat_display(1151400, 199500, 1350900, 17.33, $G, $L);
check(near($d['levy'], 11400.00), 'stored payment shows levy 11,400', (string)$d['levy']);
check(near($d['net'], 1140000.00), 'stored payment shows net 1,140,000', (string)$d['net']);
check(near($d['rate'], 17.50), 'stored payment shows 17.50% (data unchanged)', (string)$d['rate']);
check(near($d['net'] + 199500 + $d['levy'], 1350900.00), 'shown lines add up to the total');
// Existing refund REF-2026-000003: 282,900, VAT 41,778.48, payment_amount 241,121.52, stored rate 17.33
$dr = rh_payment_row_vat_display(241121.52, 41778.48, 282900, 17.33, $G, $L);
check(near($dr['levy'], 2387.34) && near($dr['net'], 238734.18) && near($dr['rate'], 17.50), 'stored refund shows levy 2,387.34 / net 238,734.18 / 17.50%', json_encode($dr));
// No levy: stored figures as they are
$dn = rh_payment_row_vat_display(100000, 17500, 117500, 17.5, 117500, 0);
check(near($dn['levy'], 0) && near($dn['net'], 100000) && near($dn['rate'], 17.5), 'levy-free row shown as stored');
// Levy-only row (VAT off): stored rate kept, levy shown
$dv = rh_payment_row_vat_display(1010, 0, 1010, 0, 1010, 10);
check(near($dv['levy'], 10) && near($dv['net'], 1000) && near($dv['rate'], 0), 'VAT-free levy row: levy split out, rate untouched');
// Zero / missing account gross
$d0 = rh_payment_row_vat_display(500, 50, 550, 10, 0, 10);
check(near($d0['levy'], 0) && near($d0['net'], 500) && near($d0['rate'], 10), 'no account gross -> stored figures');

// ---- rh_payment_vat_lines (payment-details, receipts, payments CSV) with an in-memory stand-in for PDO ----
// No database: the stand-in answers the one bookings SELECT with a fixed row and counts the queries.
class PvrFakeStmt extends PDOStatement {
    public $row = false;
    public function execute(?array $params = null): bool { return true; }
    public function fetch(int $mode = PDO::FETCH_DEFAULT, int $cursorOrientation = PDO::FETCH_ORI_NEXT, int $cursorOffset = 0): mixed { return $this->row; }
}
class PvrFakePdo extends PDO {
    public $row; public $queries = 0;
    public function __construct($row) { $this->row = $row; }
    public function prepare(string $query, array $options = []): PDOStatement|false { $this->queries++; $s = new PvrFakeStmt(); $s->row = $this->row; return $s; }
}
$bk = ['tourism_levy_amount' => 11400, 'total_with_vat' => 1350900, 'total_amount' => 1151400, 'vat_amount' => 199500, 'vat_rate' => 17.5, 'status' => 'checked-out'];
$pdoL = new PvrFakePdo($bk);
$payRow = ['booking_type' => 'room', 'booking_id' => 2100008830, 'payment_amount' => 1151400, 'vat_amount' => 199500, 'total_amount' => 1350900, 'vat_rate' => 17.33];
$pl = rh_payment_vat_lines($pdoL, $payRow);
check(near($pl['net'], 1140000) && near($pl['levy'], 11400) && near($pl['rate'], 17.50), 'payment lines: stored payment 2099976256 shows 1,140,000 / 11,400 / 17.50%', json_encode($pl));
$rl = rh_payment_vat_lines($pdoL, ['payment_amount' => 241121.52, 'vat_amount' => 41778.48, 'total_amount' => 282900, 'vat_rate' => 17.33] + $payRow);
check(near($rl['net'], 238734.18) && near($rl['levy'], 2387.34) && near($rl['rate'], 17.50), 'payment lines: refund REF-2026-000003 shows 238,734.18 / 2,387.34 / 17.50%', json_encode($rl));
check(near($pl['net'] + 199500 + $pl['levy'], 1350900) && near($rl['net'] + 41778.48 + $rl['levy'], 282900), 'CSV columns Net + Tourism levy + VAT = Total');
$pdoL->queries = 0;
rh_payment_vat_lines($pdoL, $payRow, true); rh_payment_vat_lines($pdoL, $payRow, true);
check($pdoL->queries <= 1, 'CSV cache: one booking read for many rows of the same booking', (string)$pdoL->queries);
$pdoN = new PvrFakePdo(['tourism_levy_amount' => 0, 'total_with_vat' => 117500, 'total_amount' => 100000, 'vat_amount' => 17500]);
$nlv = rh_payment_vat_lines($pdoN, ['booking_type' => 'room', 'booking_id' => 7, 'payment_amount' => 100000, 'vat_amount' => 17500, 'total_amount' => 117500, 'vat_rate' => 17.5]);
check(near($nlv['levy'], 0) && near($nlv['net'], 100000) && near($nlv['rate'], 17.5), 'payment lines: levy-free room shown as stored');
$pdoX = new PvrFakePdo($bk);
$nr = rh_payment_vat_lines($pdoX, ['booking_type' => 'restaurant', 'booking_id' => 5, 'payment_amount' => 850.0, 'vat_amount' => 150.0, 'total_amount' => 1000.0, 'vat_rate' => 17.5]);
check($pdoX->queries === 0 && near($nr['levy'], 0) && near($nr['net'], 850) && near($nr['rate'], 17.5), 'payment lines: non-room row as stored, no query');
$pdoM = new PvrFakePdo(false);
$mb = rh_payment_vat_lines($pdoM, ['booking_id' => 99] + $payRow);
check(near($mb['levy'], 0) && near($mb['net'], 1151400) && near($mb['rate'], 17.33), 'payment lines: missing booking -> stored figures');

// Cancelled booking: its folio bills no levy, so its rows are shown as stored (no levy line, stored rate)
$pdoC = new PvrFakePdo(['status' => 'cancelled'] + $bk);
$cx = rh_payment_vat_lines($pdoC, ['booking_id' => 501, 'payment_amount' => 85106.38, 'vat_amount' => 14893.62, 'total_amount' => 100000, 'vat_rate' => 17.5] + $payRow);
check(near($cx['levy'], 0) && near($cx['net'], 85106.38) && near($cx['rate'], 17.5), 'cancelled booking: stored figures, no levy line', json_encode($cx));
// Pre-2026-10-01-style row: VAT = gross x 17.5/117.5 (levy inside it) on a levy booking would read 17.68%, so stored
$flatVat = round(100000 * 17.5 / 117.5, 2);
$fx = rh_payment_vat_lines($pdoL, ['booking_id' => 502, 'payment_amount' => round(100000 - $flatVat, 2), 'vat_amount' => $flatVat, 'total_amount' => 100000, 'vat_rate' => 17.5] + $payRow);
check(near($fx['levy'], 0) && near($fx['net'], round(100000 - $flatVat, 2)) && near($fx['rate'], 17.5), 'flat-rate row on a levy booking: stored figures (not 84,262.49 / 843.89 / 17.68%)', json_encode($fx));
$raw = rh_payment_row_vat_display(round(100000 - $flatVat, 2), $flatVat, 100000, 17.5, 1350900, 11400);
check($raw['rate'] > 17.55, 'the guard is needed: the raw split of that row reads ' . $raw['rate'] . '%');
// The levy booking's own pro-rata rows still pass the guard
$ok2 = rh_payment_vat_lines($pdoL, ['booking_id' => 503] + $payRow);
check(near($ok2['levy'], 11400) && near($ok2['rate'], 17.50), 'pro-rata row still shows levy 11,400 at 17.50% with the guard on');
// issueCreditNote's vat_amount path is not tested here: config/credit-notes.php loads config/database.php (DB).

// ---- rh_refund_leg_rate: the rate STORED on a refund leg (refunds against older 17.33 payments) ----
// Only the rate changes; the leg's VAT amount / payment_amount still come from rh_refund_vat_split()
// (config/database.php, needs the DB to load), and this helper returns just a float.
// Allocation-shaped row (allocateBookingRefunds / rh_allocate_account_refunds now select booking_id + payment_amount)
$allocRow = ['id' => 77, 'payment_method' => 'cash', 'booking_type' => 'room', 'booking_id' => 2100008830, 'booking_reference' => 'RBH1',
    'payment_amount' => 1151400, 'vat_rate' => 17.33, 'vat_amount' => 199500, 'total_amount' => 1350900, 'already_refunded' => 0];
$lr = rh_refund_leg_rate($pdoL, $allocRow, 17.33);
check(is_float($lr) && near($lr, 17.50), 'refund leg of a levy-room payment stores the true 17.50 (not 17.33)', (string)$lr);
// payment-refund.php locked row: same answer
$lrL = rh_refund_leg_rate($pdoL, ['id' => 77, 'booking_type' => 'room', 'booking_id' => 2100008830, 'payment_amount' => 1151400,
    'total_amount' => 1350900, 'vat_amount' => 199500, 'vat_rate' => 17.33, 'payment_status' => 'completed'], 17.33);
check(near($lrL, 17.50), 'manual refund (locked payment row) stores 17.50', (string)$lrL);
// Row without payment_amount: worked out as total - VAT (levy stays in it), same rate
$noPa = $allocRow; unset($noPa['payment_amount']);
check(near(rh_refund_leg_rate($pdoL, $noPa, 17.33), 17.50), 'row without payment_amount: net = total - VAT, still 17.50');
// Fallbacks keep the stored rate
check(near(rh_refund_leg_rate($pdoC, ['booking_id' => 501] + $allocRow, 17.33), 17.33), 'cancelled booking: refund keeps the stored rate');
$flatRow = ['booking_id' => 502, 'payment_amount' => round(100000 - $flatVat, 2), 'vat_amount' => $flatVat, 'total_amount' => 100000, 'vat_rate' => 17.5] + $allocRow;
check(near(rh_refund_leg_rate($pdoL, $flatRow, 17.5), 17.5), 'flat-rate (pre-2026-10-01) payment: refund keeps the stored 17.5');
$pdoX->queries = 0;
check(near(rh_refund_leg_rate($pdoX, ['booking_type' => 'restaurant', 'booking_id' => 5, 'payment_amount' => 850.0, 'vat_amount' => 150.0, 'total_amount' => 1000.0, 'vat_rate' => 17.5], 17.5), 17.5)
    && $pdoX->queries === 0, 'non-room payment: stored rate, no query');
check(near(rh_refund_leg_rate($pdoL, ['booking_id' => 0] + $allocRow, 17.33), 17.33), 'row with no booking id: stored rate');
check(near(rh_refund_leg_rate($pdoN, ['booking_id' => 7, 'payment_amount' => 100000, 'vat_amount' => 17500, 'total_amount' => 117500] + $allocRow, 17.5), 17.5), 'levy-free room: stored rate');
// The refund VAT pro rata stays the same as before: 282,900 x 199,500 / 1,350,900 = 41,778.48, now stored at 17.50%
$legVat = round(282900 * (199500 / 1350900), 2);
check(near($legVat, 41778.48) && near(rh_payment_row_vat_display(round(282900 - $legVat, 2), $legVat, 282900, $lr, $G, $L)['rate'], 17.50),
    'refund leg 282,900: VAT 41,778.48 unchanged, row reads 17.50% with the new stored rate');

echo "\n$pass passed, $fail failed\n";
exit($fail > 0 ? 1 : 0);
