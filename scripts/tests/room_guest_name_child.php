<?php
if (PHP_SAPI !== 'cli') { http_response_code(403); exit("CLI only.\n"); }
/**
 * In-process scenario for scripts/tests/room_guest_name.php. NOT run on its own: it is "the page" that
 * scripts/tests/page_harness.php executes (one outer transaction that can never commit). It seeds a 2-room group,
 * builds the group invoice and the room quotation with and without per-room guest names, and prints one JSON object:
 * the names are on the room lines, escaped, and every amount printed is identical with or without them.
 * The PDF renderer is replaced by a capture stub BEFORE the config loads, so the document HTML can be inspected;
 * a final real PDF is also built to prove the name reaches the file.
 */
$rid = (int)($_POST['rid'] ?? 0);
$GLOBALS['rgn_html'] = [];
if (!function_exists('bookingRenderPdfFromHtml')) {
    function bookingRenderPdfFromHtml(string $html, string $title = 'Document', array $opts = []): string
    {
        $GLOBALS['rgn_html'][] = $html;
        return '%PDF-capture-stub ' . $title;
    }
}
require_once $root . '/config/email.php';
require_once $root . '/config/invoice.php';
require_once $root . '/includes/quotation-pdf.php';
require_once $root . '/includes/pricing.php';

$out = [];
$run = strtoupper(bin2hex(random_bytes(3)));
$pid = random_int(2000000000, 2100000000);
$sid = $pid + 1;
$in = date('Y-m-d', strtotime('+400 day'));
$outd = date('Y-m-d', strtotime('+402 day'));
$ins = $pdo->prepare("INSERT INTO bookings (id, booking_reference, room_id, primary_booking_id, guest_name, guest_email, guest_phone, guest_country, number_of_guests, adult_guests, child_guests,
        child_price_multiplier, check_in_date, check_out_date, number_of_nights, total_amount, child_supplement_total, tourism_levy_amount, tourism_levy_percent,
        vat_rate, vat_amount, total_with_vat, status, payment_status, is_tentative, occupancy_type, client_uuid, created_at)
    VALUES (?,?,?,?,?,?,?,?,2,2,0,50,?,?,2,100000,0,0,0,0,0,100000,'confirmed','unpaid',0,'double',?,NOW())");
$ins->execute([$pid, 'RGN' . $run . 'A', $rid, null, 'RGN LEAD', 'rgn@example.invalid', '+265000000004', 'Malawi', $in, $outd, bin2hex(random_bytes(16))]);
$ins->execute([$sid, 'RGN' . $run . 'B', $rid, $pid, 'RGN LEAD', 'rgn@example.invalid', '+265000000004', 'Malawi', $in, $outd, bin2hex(random_bytes(16))]);
$setNames = $pdo->prepare("UPDATE bookings SET room_guest_name = ? WHERE id = ?");
$evil = '<script>alert(1)</script>';

/** The money printed on a document: every "1,234.56" / "MWK1,234" amount in the plain text, in order. */
$amounts = static function (string $html): array {
    $txt = html_entity_decode(strip_tags(str_replace(['</td>', '</tr>'], [' | ', "\n"], $html)), ENT_QUOTES, 'UTF-8');
    preg_match_all('/\d{1,3}(?:,\d{3})+(?:\.\d{2})?|\d+\.\d{2}/', $txt, $m);
    return $m[0];
};
$lastHtml = static function (): string { $h = $GLOBALS['rgn_html']; return (string)end($h); };

// ---- group invoice -----------------------------------------------------------------------------------------
$setNames->execute([null, $pid]); $setNames->execute([null, $sid]);
$GLOBALS['rgn_html'] = [];
$r0 = generateInvoicePDF($pid, 'INV-RGN-' . $run . '-0');
$htmlPlain = $lastHtml();
$setNames->execute(['Mr & Mrs Banda', $pid]); $setNames->execute([$evil, $sid]);
$GLOBALS['rgn_html'] = [];
$r1 = generateInvoicePDF($pid, 'INV-RGN-' . $run . '-1');
$htmlNamed = $lastHtml();
$out['invoice_built'] = is_array($r0) && is_array($r1) && $htmlPlain !== '' && $htmlNamed !== '';
$out['invoice_has_names'] = strpos($htmlNamed, 'Mr &amp; Mrs Banda') !== false && strpos($htmlPlain, 'Mrs Banda') === false;
$out['invoice_escaped'] = strpos($htmlNamed, '&lt;script&gt;alert(1)&lt;/script&gt;') !== false && strpos($htmlNamed, '<script>alert(1)</script>') === false;
$out['invoice_money_same'] = $htmlPlain !== '' && $amounts($htmlPlain) === $amounts($htmlNamed) && count($amounts($htmlNamed)) > 2;
foreach ([$r0, $r1] as $rr) { if (is_array($rr) && !empty($rr['filepath'])) { @unlink($rr['filepath']); } }

// ---- quotation (one room) ----------------------------------------------------------------------------------
$room = $pdo->query("SELECT * FROM rooms WHERE id = " . $rid)->fetch(PDO::FETCH_ASSOC);
$bk = static function (int $id) use ($pdo): array {
    $q = $pdo->prepare("SELECT b.*, r.name AS room_name FROM bookings b JOIN rooms r ON r.id = b.room_id WHERE b.id = ?");
    $q->execute([$id]);
    return $q->fetch(PDO::FETCH_ASSOC);
};
$setNames->execute([null, $pid]);
$GLOBALS['rgn_html'] = [];
generateQuotationPDF($bk($pid), $room, []);
$qPlain = $lastHtml();
$setNames->execute(['Mr & Mrs Banda', $pid]);
$GLOBALS['rgn_html'] = [];
generateQuotationPDF($bk($pid), $room, []);
$qNamed = $lastHtml();
$out['quote_has_name'] = $qNamed !== '' && strpos($qNamed, 'Mr &amp; Mrs Banda') !== false && strpos($qPlain, 'Mrs Banda') === false;
$out['quote_money_same'] = $qPlain !== '' && $amounts($qPlain) === $amounts($qNamed) && count($amounts($qNamed)) > 1;

echo json_encode($out);
