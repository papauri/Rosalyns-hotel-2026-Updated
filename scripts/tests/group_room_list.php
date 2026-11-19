<?php
if (PHP_SAPI !== 'cli') { http_response_code(403); exit("CLI only.\n"); } // never runnable over the web
/**
 * Bundle B (owner 2026-11-19): the shared group room list (includes/group-room-list.php). NO DATABASE: only the pure
 * helpers are loaded and fed prepared rows (the shape rh_grl_rows() gives). Covers one room (no list), three rooms with
 * and without names, mixed dates, a cancelled room with and without a fee kept, escaping, the plain-text part, the PDF
 * copy and the group quotation sums.
 * Usage (test hotel root): php scripts/tests/group_room_list.php
 */
$root = dirname(__DIR__, 2);
if (stripos(str_replace('\\', '/', $root), 'liwonde') === false) {
    fwrite(STDERR, "Refused: run this test from the Liwonde (test hotel) folder only.\n");
    exit(2);
}
$pass = 0; $fail = 0;
function check(bool $c, string $l, string $d = ''): void
{
    global $pass, $fail;
    if ($c) { $pass++; echo "PASS $l\n"; } else { $fail++; echo "FAIL $l" . ($d !== '' ? ": $d" : '') . "\n"; }
}
$warn = 0;
set_error_handler(static function (int $no, string $str, string $file = '', int $line = 0) use (&$warn): bool {
    $warn++;
    echo "  (warning: $str at " . basename($file) . ":$line)\n";
    return true;
});

define('BALANCE_TOLERANCE', 0.01);
require_once $root . '/includes/group-room-list.php';
check(!function_exists('getSetting') && !isset($GLOBALS['pdo']), 'the pure helpers load without the database or settings');

$row = static function (array $o): array {
    return $o + ['booking_reference' => 'LSH1', 'room_name' => 'Deluxe Room', 'room_label' => '', 'room_guest_name' => null,
        'check_in_date' => '2026-12-18', 'check_out_date' => '2026-12-21', 'number_of_nights' => 3, 'status' => 'confirmed',
        'rh_rate_text' => 'MWK 100,000.00 × 3 nights = MWK 300,000.00', 'rh_kept' => 0, 'rh_currency' => 'MWK'];
};

// 1. dates + nights
check(rh_grl_dates('2026-12-18', '2026-12-21') === '18 Dec 2026 to 21 Dec 2026 (3 nights)', 'dates: three nights');
check(rh_grl_dates('2026-12-18', '2026-12-19') === '18 Dec 2026 to 19 Dec 2026 (1 night)', 'dates: one night, singular');
check(rh_grl_dates('', '2026-12-19') === '' && rh_grl_dates('2026-12-18', '') === '', 'dates: none without both dates');
check(rh_grl_dates('2026-12-18', '2026-12-18', 2) === '18 Dec 2026 to 18 Dec 2026 (2 nights)', 'dates: stored nights when the dates give none');

// 2. room title
check(rh_grl_room_title($row(['room_label' => '12'])) === 'Deluxe Room - 12', 'title: type then number');
check(rh_grl_room_title($row(['room_name' => 'Room 12', 'room_label' => '12'])) === 'Room 12', 'title: number already in the type is not repeated');
check(rh_grl_room_title($row([])) === 'Deluxe Room', 'title: no number assigned');

// 3. one room: no list (a single booking renders exactly as before)
check(rh_grl_lines([$row([])], ['money' => true]) === [], 'one room: no list');
check(rh_grl_html([], 'Rooms in your group') === '' && rh_grl_plain([], 'Rooms in your group') === '', 'one room: no HTML, no text');

// 4. three rooms, mixed dates, with and without names
$three = [
    $row(['booking_reference' => 'LSH1', 'room_label' => '12', 'room_guest_name' => 'Mr Banda']),
    $row(['booking_reference' => 'LSH1-2', 'room_name' => 'Garden Suite', 'room_label' => '14', 'check_in_date' => '2026-12-17',
        'check_out_date' => '2026-12-23', 'number_of_nights' => 6, 'rh_rate_text' => 'MWK 80,000.00 × 6 nights = MWK 480,000.00']),
    $row(['booking_reference' => 'LSH1-3', 'room_guest_name' => '   ']),
];
$plain = rh_grl_lines($three);
check(count($plain) === 3, 'three rooms: three lines');
check($plain[0] === 'LSH1 - Deluxe Room - 12 - Guest: Mr Banda - 18 Dec 2026 to 21 Dec 2026 (3 nights)', 'named room: reference, room, guest, own dates', $plain[0]);
check($plain[1] === 'LSH1-2 - Garden Suite - 14 - 17 Dec 2026 to 23 Dec 2026 (6 nights)', 'unnamed room: no Guest part, its own (different) dates', $plain[1]);
check($plain[2] === 'LSH1-3 - Deluxe Room - 18 Dec 2026 to 21 Dec 2026 (3 nights)', 'blank name = no name (never invented)', $plain[2]);
check(strpos(implode(' ', $plain), '×') === false, 'without money: no rates');
$money = rh_grl_lines($three, ['money' => true]);
check($money[0] === $plain[0] . ' - MWK 100,000.00 × 3 nights = MWK 300,000.00', 'with money: booked rate x nights at the end', $money[0]);
check(substr($money[1], -strlen('MWK 80,000.00 × 6 nights = MWK 480,000.00')) === 'MWK 80,000.00 × 6 nights = MWK 480,000.00', 'with money: each room its own rate and nights', $money[1]);
check(rh_grl_line($three[0], ['ref' => false]) === 'Deluxe Room - 12 - Guest: Mr Banda - 18 Dec 2026 to 21 Dec 2026 (3 nights)', 'ref off: starts at the room');

// 5. a cancelled room: its state, never payable; the fee kept only when one was
$cx = $row(['booking_reference' => 'LSH1-4', 'status' => 'cancelled']);
check(rh_grl_line($cx, ['money' => true]) === 'LSH1-4 - Deluxe Room - 18 Dec 2026 to 21 Dec 2026 (3 nights) - Cancelled', 'cancelled, no fee: state only, no rate', rh_grl_line($cx, ['money' => true]));
$cxFee = $row(['booking_reference' => 'LSH1-4', 'status' => 'cancelled', 'rh_kept' => 100000]);
check(rh_grl_line($cxFee, ['money' => true]) === 'LSH1-4 - Deluxe Room - 18 Dec 2026 to 21 Dec 2026 (3 nights) - Cancelled, fee kept: MWK 100,000.00', 'cancelled with a fee kept: the fee, not the rate', rh_grl_line($cxFee, ['money' => true]));
check(rh_grl_line($cxFee) === 'LSH1-4 - Deluxe Room - 18 Dec 2026 to 21 Dec 2026 (3 nights) - Cancelled', 'cancelled, no money in the email: state only');
check(substr(rh_grl_line($row(['status' => 'no-show', 'rh_kept' => 0.004]), ['money' => true]), -strlen(' - No-show')) === ' - No-show', 'no-show under the tolerance: no fee shown');
check(substr(rh_grl_line($row(['status' => 'expired']), ['money' => true]), -strlen(' - Expired')) === ' - Expired', 'expired: state shown');
check(rh_grl_state($row(['status' => 'checked-out'])) === '', 'a stayed room is not a closed state');

// 6. HTML (escaped) + plain text
$evil = [$row(['room_guest_name' => '<script>alert(1)</script> & "Co"']), $row(['booking_reference' => 'LSH1-2'])];
$html = rh_grl_html(rh_grl_lines($evil), 'Rooms in your group', "Each room's dates:");
check(strpos($html, '<script>') === false && strpos($html, '&lt;script&gt;alert(1)&lt;/script&gt; &amp; &quot;Co&quot;') !== false, 'HTML: every line escaped', $html);
check(strpos($html, 'Each room&#039;s dates:') !== false && strpos($html, '<h3') !== false && substr_count($html, '<p ') === 3, 'HTML: heading, intro and one paragraph per room');
$txt = rh_grl_plain(rh_grl_lines($evil), 'Rooms in your group', "Each room's dates:");
check(strpos($txt, "Rooms in your group\nEach room's dates:\nLSH1 - Deluxe Room - Guest: <script>alert(1)</script> & \"Co\"") === 0 && strpos($txt, '&lt;') === false, 'text: plain lines, not escaped', $txt);

// 7. PDF copy: plain characters for the core font
$pdfLine = rh_grl_line($row(['room_guest_name' => 'A – B']), ['money' => true, 'pdf' => true]);
check(strpos($pdfLine, '×') === false && strpos($pdfLine, '–') === false && strpos($pdfLine, ' x 3 nights') !== false, 'PDF copy: x and plain dash', $pdfLine);

// 8. group quotation sums: live rooms only, each on its own lines, deposit = the live rooms' deposits
$qRows = [
    $row(['room_label' => '12', 'room_guest_name' => 'Mr Banda', 'adult_guests' => 2, 'child_guests' => 1]),
    $row(['booking_reference' => 'LSH1-2', 'room_name' => 'Garden Suite', 'number_of_nights' => 6, 'check_in_date' => '2026-12-17', 'check_out_date' => '2026-12-23', 'adult_guests' => 2, 'deposit_required' => 1, 'deposit_amount' => 50000]),
    $row(['booking_reference' => 'LSH1-3', 'status' => 'cancelled', 'adult_guests' => 2, 'deposit_required' => 1, 'deposit_amount' => 99999]),
];
$qLines = [
    ['room' => 300000, 'child' => 10000, 'pkg' => 0, 'vat' => 50000, 'levy' => 3000, 'total' => 363000],
    ['room' => 480000, 'child' => 0, 'pkg' => 20000, 'vat' => 80000, 'levy' => 4800, 'total' => 584800],
    ['room' => 300000, 'child' => 0, 'pkg' => 0, 'vat' => 50000, 'levy' => 3000, 'total' => 353000],
];
$q = rh_grl_quote_sum($qRows, $qLines);
check(is_array($q) && $q['count'] === 2, 'quotation: the cancelled room is never quoted');
check(is_array($q) && abs($q['total'] - 947800) < 0.001 && abs($q['room'] - 780000) < 0.001 && abs($q['vat'] - 130000) < 0.001
    && abs($q['levy'] - 7800) < 0.001 && abs($q['child'] - 10000) < 0.001 && abs($q['pkg'] - 20000) < 0.001, 'quotation: sums of the live rooms\' own lines', json_encode($q));
check(is_array($q) && abs($q['deposit'] - 50000) < 0.001 && $q['adults'] === 4 && $q['children'] === 1, 'quotation: deposit and guests of the live rooms');
check(is_array($q) && $q['ci'] === '2026-12-17' && $q['co'] === '2026-12-23', 'quotation: first arrival, last departure');
check(is_array($q) && $q['rooms'][0]['label'] === 'Deluxe Room - 12 – Mr Banda' && abs($q['rooms'][0]['rate'] - 100000) < 0.001 && abs($q['rooms'][1]['rate'] - 80000) < 0.001
    && $q['rooms'][1]['label'] === 'Garden Suite' && $q['rooms'][1]['dates'] === '17 Dec 2026 to 23 Dec 2026 (6 nights)', 'quotation: one line per room, its own rate and dates', json_encode($q['rooms'] ?? null, JSON_UNESCAPED_UNICODE));
check(rh_grl_quote_sum([$qRows[0], $qRows[2]], [$qLines[0], $qLines[2]]) === null, 'quotation: one live room left = the one-room quotation (null)');
check(rh_grl_quote_sum([$qRows[0]], [$qLines[0]]) === null, 'quotation: single booking = null');

restore_error_handler();
check($warn === 0, 'no PHP warnings or notices', "$warn raised");
echo "\n{$pass} passed, {$fail} failed\n";
exit($fail > 0 ? 1 : 0);
