<?php
if (PHP_SAPI !== 'cli') { http_response_code(403); exit("CLI only.\n"); } // never runnable over the web
/**
 * New Booking "Payment Received Now" (admin/create-booking.php). NO DATABASE: reads the page source only.
 * Story (owner 2026-11-27): a full payment was refused as "more than the balance due" because the page priced a
 * double/triple room with no price of its own at x1.2 / x1.4 while the save used the nightly price; the amount
 * also stopped following the total, and a refused save came back with the room and payment cleared.
 * Usage (repo root): php scripts/tests/create_booking_payment_test.php
 */
$root = dirname(__DIR__, 2);
$pass = 0; $fail = 0;
function ok(string $l): void { global $pass; $pass++; echo "PASS $l\n"; }
function bad(string $l, string $d = ''): void { global $fail; $fail++; echo "FAIL $l" . ($d !== '' ? ": $d" : '') . "\n"; }
function check(bool $c, string $l, string $d = ''): bool { $c ? ok($l) : bad($l, $d); return $c; }

$src = (string)file_get_contents($root . '/admin/create-booking.php');
check($src !== '', 'create-booking.php read');

// 1. The page and the save use the same tier fallback: no price of its own = the nightly price.
check(!preg_match('/price_per_night\'\]\s*\*\s*1\.[0-9]/', $src), 'no invented x1.2 / x1.4 tier price on the page');
foreach (['single', 'double', 'triple'] as $t) {
    check(strpos($src, "'price_$t'           => (float)(!empty(\$r['price_{$t}_occupancy']) ? \$r['price_{$t}_occupancy'] : \$r['price_per_night'])") !== false,
        "page $t price falls back to the nightly price");
    check(strpos($src, "'$t' => !empty(\$r_row['price_{$t}_occupancy']) ? (float)\$r_row['price_{$t}_occupancy'] : (float)\$r_row['price_per_night']") !== false
        || strpos($src, "'$t'   => !empty(\$r_row['price_{$t}_occupancy']) ? (float)\$r_row['price_{$t}_occupancy'] : (float)\$r_row['price_per_night']") !== false
        || preg_match("/'$t'\s*=>\s*!empty\(\\\$r_row\['price_{$t}_occupancy'\]\)\s*\?\s*\(float\)\\\$r_row\['price_{$t}_occupancy'\]\s*:\s*\(float\)\\\$r_row\['price_per_night'\]/", $src),
        "save $t price falls back to the nightly price");
}

// 2. The auto-filled amount keeps following the total: only typing clears data-auto.
check(strpos($src, 'oninput="this.removeAttribute(\'data-auto\'); checkPartialPayment()"') !== false, 'typing an amount clears data-auto');
$cpp = '';
if (preg_match('/function checkPartialPayment\(\) \{(.*?)\n        \}/s', $src, $m)) { $cpp = $m[1]; }
check($cpp !== '', 'checkPartialPayment found');
check(strpos($cpp, "removeAttribute('data-auto')") === false, 'checkPartialPayment does not clear data-auto');

// 3. A refused save brings back the room types and payment, never the overpay tick.
check(strpos($src, "\$_SERVER['REQUEST_METHOD'] === 'POST' && \$error !== ''") !== false, 'repost only after a refused POST');
check(strpos($src, 'const cbRepost = <?php echo json_encode($cb_repost, JSON_HEX_TAG') !== false, 'repost data reaches the page escaped');
check(strpos($src, "cbRepost.room_types.forEach(t => addRoomLine(t))") !== false, 'room lines come back');
check(strpos($src, 'payCb.checked = true;') !== false && strpos($src, '!payCb.disabled') !== false, 'payment comes back unless payment is disabled');
$rp = '';
if (preg_match('/\$cb_repost = \[(.*?)\];/s', $src, $m)) { $rp = $m[1]; }
check($rp !== '' && strpos($rp, 'confirm_overpay') === false, 'the overpay tick is never brought back');
check(preg_match("/in_array\(\\\$cbRpMethod, \['cash', 'bank_transfer', 'mobile_money', 'card', 'cheque', 'other'\], true\)/", $src) === 1, 'payment method is whitelisted');

echo "\n$pass passed, $fail failed\n";
exit($fail ? 1 : 0);
