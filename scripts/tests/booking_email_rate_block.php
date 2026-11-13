<?php
if (PHP_SAPI !== 'cli') { http_response_code(403); exit("CLI only.\n"); } // never runnable over the web
/**
 * F10 guest booking emails: the appended {{booked_rate_block}} ("Your rate": booked rate x nights + rate plan; "Your
 * rooms": every live room's reference for a party). Run from the TEST hotel's root only (Liwonde):
 *   php scripts/tests/booking_email_rate_block.php
 * Sends NO email. The block builder and the template filler are tested on in-memory rows and in-memory templates (the
 * stored templates and their cache are never read for these cases, never written). The party cases insert rows inside
 * ONE transaction that is rolled back; a second connection then checks nothing was left behind.
 */
$root = dirname(__DIR__, 2);
chdir($root);
if (stripos(str_replace('\\', '/', $root), 'liwonde') === false) {
    fwrite(STDERR, "Refused: run this test from the Liwonde (test hotel) folder only.\n");
    exit(2);
}
require_once $root . '/config/database.php';
if (stripos((string)DB_NAME, 'rosalyn') !== false) { // Rosalyn's is LIVE: DB tests run at the test hotel only
    fwrite(STDERR, "Refused: run this test from the Liwonde (test hotel) folder only.\n");
    exit(2);
}
require_once $root . '/config/email.php'; // only after both refusals
require_once $root . '/includes/booked-rate.php';

$pass = 0; $fail = 0;
function ok(string $name, bool $cond, string $detail = ''): void {
    global $pass, $fail;
    if ($cond) { $pass++; echo "[PASS] $name\n"; } else { $fail++; echo "[FAIL] $name" . ($detail !== '' ? ": $detail" : '') . "\n"; }
}
$warn = 0;
set_error_handler(static function (int $no, string $str, string $file = '', int $line = 0) use (&$warn): bool {
    $warn++;
    echo "  (warning: $str at " . basename($file) . ":$line)\n";
    return true;
}, E_WARNING | E_NOTICE | E_USER_WARNING | E_USER_NOTICE);
$has = static fn(string $hay, string $needle): bool => strpos($hay, $needle) !== false;
$cur = 'MWK';

/** In-memory stored row (gross figures: total_with_vat is what the guest pays). */
$row = static function (array $o): array {
    return $o + [
        'id' => 0, 'booking_reference' => '', 'room_name' => 'Deluxe Room', 'room_label' => '', 'number_of_nights' => 3,
        'total_amount' => 300000, 'vat_amount' => 0, 'total_with_vat' => 300000, 'tourism_levy_amount' => 0,
        'tourism_levy_percent' => 0, 'package_total' => 0, 'child_supplement_total' => 0, 'rate_plan_label' => null,
        'rate_plan_discount' => null, 'status' => 'confirmed', 'primary_booking_id' => null,
    ];
};

// ───────────── 1. Single room, no rate plan ─────────────
$b = rh_email_booked_rate_block([$row(['id' => 901, 'booking_reference' => 'TRB1'])], 901, 300000.0, $cur);
ok('single, no plan: "Your rate" with booked rate x 3 nights', $has($b, 'Your rate') && $has($b, 'MWK 100,000.00 × 3 nights'), $b);
ok('single, no plan: no rate plan line', !$has($b, 'Rate plan'));
ok('single, no plan: no room reference list', !$has($b, 'Your rooms'));

// VAT + levy wording comes from the shared helper (same as documents)
$b = rh_email_booked_rate_block([$row(['id' => 901, 'vat_amount' => 40000, 'tourism_levy_amount' => 6000])], 901, 300000.0, $cur);
ok('single: "(a night, incl. VAT and tourism levy)" as on documents', $has($b, 'incl. VAT and tourism levy'), $b);

// ───────────── 2. Rate plan discount / surcharge ─────────────
$b = rh_email_booked_rate_block([$row(['id' => 902, 'rate_plan_label' => 'Early Bird', 'rate_plan_discount' => 10000])], 902, 300000.0, $cur);
ok('discount plan: rate line still shown', $has($b, 'MWK 100,000.00 × 3 nights'), $b);
ok('discount plan: "Rate plan: Early Bird - MWK 10,000.00 a night off"', $has($b, 'Rate plan: Early Bird - MWK 10,000.00 a night off, already in your rate'), $b);
$b = rh_email_booked_rate_block([$row(['id' => 903, 'rate_plan_label' => 'Festive Season', 'rate_plan_discount' => -5000])], 903, 300000.0, $cur);
ok('surcharge plan: "MWK 5,000.00 a night added"', $has($b, 'Rate plan: Festive Season - MWK 5,000.00 a night added, already in your rate'), $b);
$b = rh_email_booked_rate_block([$row(['id' => 904, 'rate_plan_label' => 'Corporate', 'rate_plan_discount' => 0])], 904, 300000.0, $cur);
ok('plan without a figure: label only', $has($b, 'Rate plan: Corporate</p>'), $b);

// ───────────── 3. Parties ─────────────
$same = [
    $row(['id' => 910, 'booking_reference' => 'TRBS', 'room_label' => '12']),
    $row(['id' => 911, 'booking_reference' => 'TRBS-2', 'room_label' => '14', 'total_amount' => 240000, 'total_with_vat' => 240000, 'primary_booking_id' => 910]),
];
$b = rh_email_booked_rate_block($same, 910, 540000.0, $cur);
ok('same-type party: one line per room', $has($b, 'Room 1: Deluxe Room - MWK 100,000.00 × 3 nights') && $has($b, 'Room 2: Deluxe Room - MWK 80,000.00 × 3 nights'), $b);
ok('same-type party: every reference with its room', $has($b, 'Your rooms') && $has($b, 'TRBS - Deluxe Room - 12') && $has($b, 'TRBS-2 - Deluxe Room - 14'), $b);
$mixed = [
    $row(['id' => 920, 'booking_reference' => 'TRBM', 'room_name' => 'Garden Suite', 'rate_plan_label' => 'Early Bird', 'rate_plan_discount' => 10000]),
    $row(['id' => 921, 'booking_reference' => 'TRBM-2', 'room_name' => 'Standard Room', 'total_amount' => 150000, 'total_with_vat' => 150000, 'rate_plan_label' => 'Early Bird', 'rate_plan_discount' => 5000, 'primary_booking_id' => 920]),
];
$b = rh_email_booked_rate_block($mixed, 920, 450000.0, $cur);
ok('mixed party: both room types and references', $has($b, 'TRBM - Garden Suite') && $has($b, 'TRBM-2 - Standard Room') && $has($b, 'Room 2: Standard Room - MWK 50,000.00 × 3 nights'), $b);
ok('mixed party: rate plan worded per room when the figures differ', $has($b, 'Rate plan, room 1: Early Bird - MWK 10,000.00') && $has($b, 'Rate plan, room 2: Early Bird - MWK 5,000.00'), $b);
// Rooms that do not add up to the printed total: no rate figures at all, references still listed
$b = rh_email_booked_rate_block($same, 910, 999999.0, $cur);
ok('party not matching the printed total: no rate line', !$has($b, '× 3 nights'), $b);
ok('party not matching the printed total: references still listed', $has($b, 'TRBS-2'), $b);
// Total printed is this room's own (an email about one room of a party): only its own line
$b = rh_email_booked_rate_block($same, 911, 240000.0, $cur);
ok('one room of a party: only its own rate line', $has($b, 'Room: Deluxe Room - MWK 80,000.00') && !$has($b, 'MWK 100,000.00'), $b);
// Rows unreadable: the caller's all_references give the room list, never rates
$b = rh_email_booked_rate_block([], 930, 300000.0, $cur, ['TRBF', 'TRBF-2']);
ok('rows unreadable: references from all_references, no rate line', $has($b, 'TRBF-2') && !$has($b, 'Your rate'), $b);

// ───────────── 4. Nothing to say ─────────────
ok('no stored rate (total 0): no block', rh_email_booked_rate_block([$row(['id' => 940, 'total_amount' => 0, 'total_with_vat' => 0])], 940, 0.0, $cur) === '');
ok('no rows, no references: no block', rh_email_booked_rate_block([], 941, 300000.0, $cur) === '');
$w0 = $warn;
$vNo = buildBookingEmailVariables(['booking_reference' => 'TRBN', 'guest_name' => 'Zz Test', 'check_in_date' => date('Y-m-d', strtotime('+60 days')),
    'check_out_date' => date('Y-m-d', strtotime('+63 days')), 'number_of_nights' => 3, 'total_amount' => 300000, 'total_with_vat' => 300000], ['name' => 'Deluxe Room']);
ok('booking without id: booked_rate_block empty', ($vNo['booked_rate_block'] ?? null) === '');
ok('booking without id: no warnings', $warn === $w0);

// ───────────── 5. Escaping ─────────────
$b = rh_email_booked_rate_block([$row(['id' => 950, 'room_name' => '<i>Suite</i>', 'rate_plan_label' => '<script>alert(1)</script>', 'rate_plan_discount' => 1000])], 950, 300000.0, $cur);
ok('HTML in rate plan label / room name is escaped', !$has($b, '<script>') && !$has($b, '<i>') && $has($b, '&lt;script&gt;alert(1)&lt;/script&gt;') && $has($b, '&lt;i&gt;Suite'), $b);

// ───────────── 6. Template filler (in-memory templates, nothing stored is read) ─────────────
$block = rh_email_booked_rate_block([$row(['id' => 960, 'rate_plan_label' => "O'Brien Rate", 'rate_plan_discount' => 10000])], 960, 300000.0, $cur);
$vars = ['booking_reference' => 'TRBT', 'booked_rate_block' => $block, 'cancellation_policy_text' => '<p>Cancelled less than 7 days before arrival: 50%.</p>', 'cancellation_fee_charged' => ''];
$tpl = ['subject' => 'Booking {{booking_reference}}', 'html_body' => '<p>Hello {{booking_reference}}</p>', 'text_body' => 'Hello {{booking_reference}}'];
$r = rh_render_booking_email_template_row('booking_confirmed', $tpl, $vars);
ok('booking_confirmed: block appended to the HTML', substr_count($r['html_body'], 'Your rate') === 1 && $has($r['html_body'], 'MWK 100,000.00 × 3 nights'), $r['html_body']);
ok('booking_confirmed: block comes before the cancellation policy', strpos($r['html_body'], 'Your rate') < strpos($r['html_body'], 'Cancellation policy'), $r['html_body']);
ok('text part: plain lines, no tags, entities decoded', $has($r['text_body'], "Your rate\nRoom: Deluxe Room - MWK 100,000.00 × 3 nights") && $has($r['text_body'], "Rate plan: O'Brien Rate") && !$has($r['text_body'], '<p') && !$has($r['text_body'], '&#039;'), $r['text_body']);
foreach (['booking_received', 'tentative_booking_created', 'tentative_booking_converted', 'tentative_quotation'] as $k) {
    $r = rh_render_booking_email_template_row($k, $tpl, $vars);
    ok("$k: block appended", substr_count($r['html_body'], 'Your rate') === 1);
}
$tplTag = ['subject' => 'x', 'html_body' => '<p>Hi</p>{{booked_rate_block}}<p>Bye</p>', 'text_body' => "Hi\n{{booked_rate_block}}\nBye"];
$r = rh_render_booking_email_template_row('booking_confirmed', $tplTag, $vars);
ok('template that places the tag: block shown once, where placed', substr_count($r['html_body'], 'Your rate') === 1 && strpos($r['html_body'], 'Your rate') < strpos($r['html_body'], 'Bye'), $r['html_body']);
ok('template that places the tag in the text part: plain lines there, once', substr_count($r['text_body'], 'Your rate') === 1 && !$has($r['text_body'], '<p') && !$has($r['text_body'], '<h3'), $r['text_body']);
foreach (['booking_cancelled', 'booking_reminder', 'refund_notification', 'payment_invoice'] as $k) {
    $r = rh_render_booking_email_template_row($k, $tpl, $vars);
    ok("$k: no block", !$has($r['html_body'], 'Your rate') && !$has($r['text_body'], 'Your rate'));
}
$r = rh_render_booking_email_template_row('booking_confirmed', $tpl, ['booking_reference' => 'TRBT', 'booked_rate_block' => '']);
ok('empty block: template output unchanged', $r['html_body'] === '<p>Hello TRBT</p>' && $r['text_body'] === 'Hello TRBT', json_encode($r));

// ───────────── 7. Stored rows (one transaction, rolled back) ─────────────
$rooms = $pdo->query("SELECT * FROM rooms WHERE is_active = 1 ORDER BY id")->fetchAll(PDO::FETCH_ASSOC);
$roomA = $rooms[0] ?? null; $roomB = null;
foreach ($rooms as $rm) { if ($roomA && $rm['name'] !== $roomA['name']) { $roomB = $rm; break; } }
$prefix = 'TRB' . strtoupper(bin2hex(random_bytes(3)));
$email = 'zz.ratemail.' . strtolower(bin2hex(random_bytes(3))) . '@example.invalid';
if (!$roomA || !$roomB) {
    ok('needs two active rooms with different names', false);
} else {
    $ins = $pdo->prepare("INSERT INTO bookings (booking_reference, room_id, guest_name, guest_email, guest_phone, check_in_date, check_out_date,
            number_of_nights, number_of_guests, adult_guests, child_guests, total_amount, vat_amount, tourism_levy_amount, total_with_vat,
            amount_paid, amount_due, status, is_tentative, primary_booking_id, rate_plan_label, rate_plan_discount)
        VALUES (?, ?, 'Zz Rate Mail Test', ?, '+265000000000', DATE_ADD(CURDATE(), INTERVAL 60 DAY), DATE_ADD(CURDATE(), INTERVAL 63 DAY),
            3, 2, 2, 0, ?, 0, 0, ?, 0, ?, ?, 0, ?, ?, ?)");
    $add = static function (string $ref, array $room, float $total, string $status, ?int $lead, ?string $plan, ?float $disc) use ($ins, $email, $pdo): int {
        $ins->execute([$ref, (int)$room['id'], $email, $total, $total, $total, $status, $lead, $plan, $disc]);
        return (int)$pdo->lastInsertId();
    };
    $dbCur = trim((string)getSetting('currency_symbol', 'MWK'));
    $m = static fn(float $v): string => htmlspecialchars(rh_booked_rate_money($v, $dbCur !== '' ? $dbCur : 'MWK'), ENT_QUOTES, 'UTF-8');
    $pdo->beginTransaction();
    try {
        // Party: lead + a second room of another type + a cancelled third room
        $R = $prefix . 'P';
        $lead = $add($R, $roomA, 300000, 'confirmed', null, 'Early Bird', 10000);
        $add($R . '-2', $roomB, 240000, 'confirmed', $lead, 'Early Bird', 10000);
        $add($R . '-3', $roomA, 150000, 'cancelled', $lead, 'Early Bird', 10000);
        // As the public booking page passes it: party totals, no rate plan columns, every reference made.
        $v = buildBookingEmailVariables(['id' => $lead, 'booking_reference' => $R, 'room_id' => $roomA['id'], 'guest_name' => 'Zz Rate Mail Test',
            'guest_email' => $email, 'check_in_date' => date('Y-m-d', strtotime('+60 days')), 'check_out_date' => date('Y-m-d', strtotime('+63 days')),
            'number_of_nights' => 3, 'number_of_guests' => 4, 'total_amount' => 540000, 'vat_amount' => 0, 'tourism_levy_amount' => 0,
            'total_with_vat' => 540000, 'all_references' => [$R, $R . '-2', $R . '-3']], $roomA);
        $blk = (string)($v['booked_rate_block'] ?? '');
        ok('stored party: rate plan label filled from the stored row', $v['rate_plan_label'] === htmlspecialchars('Early Bird', ENT_QUOTES, 'UTF-8'), (string)$v['rate_plan_label']);
        ok('stored party: plan money vars not filled from one room of a group', $v['rate_plan_discount_formatted'] === '', (string)$v['rate_plan_discount_formatted']);
        ok('stored party: both live rooms listed with their references', $has($blk, $R . ' - ') && $has($blk, $R . '-2 - '), $blk);
        ok('stored party: cancelled room left out', !$has($blk, $R . '-3'), $blk);
        ok('stored party: rates of the live rooms (agree with the 540,000 total)', $has($blk, $m(100000) . ' × 3 nights') && $has($blk, $m(80000) . ' × 3 nights'), $blk);
        ok('stored party: one plan line for every room', $has($blk, 'Rate plan for every room: Early Bird - ' . $m(10000) . ' a night off'), $blk);
        ok('stored party: total_amount_formatted untouched', $v['total_amount_formatted'] === number_format(540000, 0), (string)$v['total_amount_formatted']);

        // Single stored room: plan label + its money vars filled
        $S = $prefix . 'S';
        $sid = $add($S, $roomA, 300000, 'pending', null, 'Early Bird', 10000);
        $v = buildBookingEmailVariables(['id' => $sid, 'booking_reference' => $S, 'room_id' => $roomA['id'], 'guest_name' => 'Zz Rate Mail Test',
            'number_of_nights' => 3, 'total_amount' => 300000, 'total_with_vat' => 300000, 'all_references' => [$S]], $roomA);
        $blk = (string)($v['booked_rate_block'] ?? '');
        ok('stored single: plan label and discount vars filled', $v['rate_plan_label'] === 'Early Bird' && $v['rate_plan_discount_formatted'] === number_format(30000, 0), json_encode([$v['rate_plan_label'], $v['rate_plan_discount_formatted']]));
        ok('stored single: rate x nights, no reference list', $has($blk, $m(100000) . ' × 3 nights') && !$has($blk, 'Your rooms'), $blk);
        $r = rh_render_booking_email_template_row('booking_received', ['subject' => 's', 'html_body' => '<p>{{rate_plan_label}}</p>', 'text_body' => ''], $v);
        ok('stored single: booking_received gets the block', $has($r['html_body'], 'Your rate') && $has($r['html_body'], '<p>Early Bird</p>'), $r['html_body']);
    } catch (Throwable $e) {
        ok('stored rows: no error', false, $e->getMessage());
    } finally {
        if ($pdo->inTransaction()) { $pdo->rollBack(); }
    }
    $probe = new PDO("mysql:host=" . DB_HOST . ";port=" . DB_PORT . ";dbname=" . DB_NAME . ";charset=" . DB_CHARSET, DB_USER, DB_PASS, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
    $left = $probe->prepare("SELECT COUNT(*) FROM bookings WHERE booking_reference LIKE ? OR guest_email = ?");
    $left->execute([$prefix . '%', $email]);
    ok('no test bookings left behind', (int)$left->fetchColumn() === 0);
}

restore_error_handler();
ok('no PHP warnings or notices', $warn === 0, "$warn raised");
echo "\n$pass passed, $fail failed\n";
exit($fail > 0 ? 1 : 0);
