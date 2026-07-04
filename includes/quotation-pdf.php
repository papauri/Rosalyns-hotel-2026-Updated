<?php

require_once __DIR__ . '/../config/email.php';

if (!function_exists('quotationPdfLogoHtml')) {
    function quotationPdfLogoHtml(): string
    {
        $logoSrc = function_exists('hotel_invoice_logo_src') ? hotel_invoice_logo_src() : '';
        if ($logoSrc === '') {
            return '';
        }

        return '<img src="' . htmlspecialchars($logoSrc, ENT_QUOTES, 'UTF-8') . '" alt="Logo" height="88" style="height:88px;width:auto;display:block;margin:0 auto;">';
    }
}

if (!function_exists('quotationPdfRenderDocument')) {
    function quotationPdfRenderDocument(string $templateKey, string $fallbackHtml, array $vars, string $title): string
    {
        $html = function_exists('renderBookingDocumentTemplate')
            ? renderBookingDocumentTemplate($templateKey, $vars, $fallbackHtml)
            : strtr($fallbackHtml, function_exists('bookingTemplateReplaceMap') ? bookingTemplateReplaceMap($vars) : []);

        if (function_exists('bookingRenderPdfFromHtml')) {
            return bookingRenderPdfFromHtml($html, $title);
        }

        if (!hotel_load_tcpdf()) {
            throw new RuntimeException('The PDF engine (TCPDF) is not installed on this server. Upload the vendor/ folder (composer install) or a TCPDF/ folder to enable PDF documents.');
        }
        if (!class_exists('JapandiTCPDF')) {
            class JapandiTCPDF extends TCPDF {
                public function AddPage($orientation = '', $format = '', $keepmargins = false, $tocpage = false): void
                {
                    parent::AddPage($orientation, $format, $keepmargins, $tocpage);
                    $this->SetFillColor(247, 243, 238);
                    $this->Rect(0, 0, $this->getPageWidth(), $this->getPageHeight(), 'F');
                }
            }
        }
        $pdf = new JapandiTCPDF('P', 'mm', 'A4', true, 'UTF-8', false);
        $pdf->setPrintHeader(false);
        $pdf->setPrintFooter(false);
        $pdf->SetMargins(12, 12, 12);
        $pdf->SetAutoPageBreak(true, 14);
        $pdf->SetTitle($title);
        $pdf->AddPage();
        $pdf->writeHTML($html, true, false, true, false, '');

        return $pdf->Output('', 'S');
    }
}

/**
 * Quotation PDF Generator
 * Generates a premium TCPDF quotation for tentative bookings.
 *
 * Usage:
 *   require_once 'includes/quotation-pdf.php';
 *   $pdfContent = generateQuotationPDF($booking, $room, $options);
 *   // $pdfContent is a binary string (can be attached to email or saved)
 *
 * @param array $booking  Full row from bookings table
 * @param array $room     Row from rooms table
 * @param array $options {
 *   valid_days       int     Days quotation is valid (default 7)
 *   quotation_notes  string  Admin note (default '')
 * }
 * @return string  Raw PDF binary string
 */
function generateQuotationPDF(array $booking, array $room, array $options = []): string
{
    if (!hotel_load_tcpdf()) {
        throw new RuntimeException('The PDF engine (TCPDF) is not installed on this server. Upload the vendor/ folder (composer install) or a TCPDF/ folder to enable PDF documents.');
    }

    // ── Config ────────────────────────────────────────────────────────────────
    $site_name      = getSetting('site_name', "Rosalyn's Beach Hotel");
    $site_address   = getSetting('address_line1', '') . (getSetting('address_line2', '') ? ', ' . getSetting('address_line2', '') : '');
    $site_phone     = getSetting('phone_main', '');
    $site_email     = getSetting('email_main', getSetting('email_from_email', ''));
    $site_website   = getSetting('site_url', '');
    $currency       = getSetting('currency_symbol', 'MWK ');
    $vat_enabled    = in_array(getSetting('vat_enabled'), ['1', 1, true, 'true', 'on'], true);
    $check_in_time  = getSetting('check_in_time', '2:00 PM');
    $check_out_time = getSetting('check_out_time', '11:00 AM');
    $payment_policy = getSetting('payment_policy', 'Full payment is due on arrival.');

    $valid_days   = max(1, (int)($options['valid_days'] ?? 7));
    $notes        = trim((string)($options['quotation_notes'] ?? ''));
    $valid_until  = (new DateTime())->modify("+{$valid_days} days");
    $quote_ref    = 'QT-' . strtoupper((string)$booking['booking_reference']);

    // ── Text truncation helper — keeps layout to 1 page ──────────────────────
    $pdfTrunc = static function (string $s, int $max): string {
        $s = trim((string)preg_replace('/\s+/', ' ', $s)); // collapse whitespace / newlines
        return mb_strlen($s) > $max ? mb_substr($s, 0, $max - 1) . '\u2026' : $s;
    };
    $notes          = $pdfTrunc($notes, 220);
    $payment_policy = $pdfTrunc($payment_policy, 190);

    // ── Pricing ───────────────────────────────────────────────────────────────
    $nights       = (int)$booking['number_of_nights'];
    $adults       = (int)($booking['adult_guests'] ?? $booking['number_of_guests'] ?? 1);
    $children     = (int)($booking['child_guests'] ?? 0);
    $total        = (float)$booking['total_amount'];
    $vat_amount   = (float)($booking['vat_amount'] ?? 0);
    $vat_rate     = (float)($booking['vat_rate'] ?? 0);
    $child_supp   = (float)($booking['child_supplement_total'] ?? 0);
    $deposit_req  = !empty($booking['deposit_required']);
    $deposit_amt  = (float)($booking['deposit_amount'] ?? 0);

    // Exclusive: VAT was added on top, so strip it to recover the room line.
    // Inclusive/off: the priced total IS the room line (VAT, if any, is inside it).
    $room_subtotal  = $total - (vat_mode() === 'exclusive' ? $vat_amount : 0.0) - $child_supp;
    $rate_per_night = $nights > 0 ? $room_subtotal / $nights : (float)$room['price_per_night'];

    $fmt = static function (float $v) use ($currency): string {
        return $currency . number_format($v, 0);
    };

    if (function_exists('hotel_default_room_quotation_document_html')) {
        $guestsLabel = $adults . ' adult' . ($adults !== 1 ? 's' : '');
        if ($children > 0) {
            $guestsLabel .= ', ' . $children . ' child' . ($children !== 1 ? 'ren' : '');
        }

        return quotationPdfRenderDocument(
            'tentative_quotation_document',
            hotel_default_room_quotation_document_html(),
            [
                'logo_html' => quotationPdfLogoHtml(),
                'site_name' => htmlspecialchars($site_name, ENT_QUOTES, 'UTF-8'),
                'address' => htmlspecialchars($site_address, ENT_QUOTES, 'UTF-8'),
                'contact_phone' => htmlspecialchars($site_phone, ENT_QUOTES, 'UTF-8'),
                'contact_email' => htmlspecialchars($site_email, ENT_QUOTES, 'UTF-8'),
                'quotation_reference' => htmlspecialchars($quote_ref, ENT_QUOTES, 'UTF-8'),
                'valid_until' => htmlspecialchars($valid_until->format('F j, Y'), ENT_QUOTES, 'UTF-8'),
                'guest_name' => htmlspecialchars((string)($booking['guest_name'] ?? ''), ENT_QUOTES, 'UTF-8'),
                'booking_reference' => htmlspecialchars((string)($booking['booking_reference'] ?? ''), ENT_QUOTES, 'UTF-8'),
                'room_name' => htmlspecialchars((string)($room['name'] ?? ''), ENT_QUOTES, 'UTF-8'),
                'check_in_date' => htmlspecialchars(!empty($booking['check_in_date']) ? date('l, F j, Y', strtotime((string)$booking['check_in_date'])) : '', ENT_QUOTES, 'UTF-8'),
                'check_out_date' => htmlspecialchars(!empty($booking['check_out_date']) ? date('l, F j, Y', strtotime((string)$booking['check_out_date'])) : '', ENT_QUOTES, 'UTF-8'),
                'nights' => (string)$nights,
                'guests' => htmlspecialchars($guestsLabel, ENT_QUOTES, 'UTF-8'),
                'rate_per_night' => htmlspecialchars($fmt($rate_per_night), ENT_QUOTES, 'UTF-8'),
                'room_subtotal' => htmlspecialchars($fmt($room_subtotal), ENT_QUOTES, 'UTF-8'),
                'vat_amount' => htmlspecialchars(vat_document_value($fmt($vat_amount)), ENT_QUOTES, 'UTF-8'),
                'deposit_amount' => htmlspecialchars($fmt($deposit_amt), ENT_QUOTES, 'UTF-8'),
                'total_amount' => htmlspecialchars($fmt($total), ENT_QUOTES, 'UTF-8'),
                'balance_due' => htmlspecialchars($fmt(max(0, $total - $deposit_amt)), ENT_QUOTES, 'UTF-8'),
                'payment_policy' => nl2br(htmlspecialchars($payment_policy, ENT_QUOTES, 'UTF-8')),
                'quotation_notes' => nl2br(htmlspecialchars($notes, ENT_QUOTES, 'UTF-8')),
            ],
            'Quotation ' . $quote_ref
        );
    }

    // ── Colours & fonts ───────────────────────────────────────────────────────
    // Warm wood brown (primary)
    $c_primary = [138, 119, 95];
    // Gold accent
    $c_gold    = [177, 130, 71];
    // Deep ink
    $c_ink     = [35, 31, 28];
    // Warm cream
    $c_cream   = [243, 236, 228];
    // Muted mid-tone text
    $c_muted   = [94, 85, 77];
    // Table alt row
    $c_alt     = [250, 246, 240];
    // Total row background
    $c_total   = [230, 218, 200];
    // Warning amber
    $c_amber   = [218, 163, 71];
    // Info blue
    $c_blue    = [74, 111, 165];
    // White
    $c_white   = [255, 255, 255];
    // Border grey
    $c_border  = [210, 200, 188];
    // Light grey
    $c_light   = [245, 241, 236];

    // ── TCPDF setup ───────────────────────────────────────────────────────────
    if (!class_exists('JapandiTCPDF')) {
        class JapandiTCPDF extends TCPDF {
            public function AddPage($orientation = '', $format = '', $keepmargins = false, $tocpage = false): void
            {
                parent::AddPage($orientation, $format, $keepmargins, $tocpage);
                $this->SetFillColor(247, 243, 238);
                $this->Rect(0, 0, $this->getPageWidth(), $this->getPageHeight(), 'F');
            }
        }
    }
    $pdf = new JapandiTCPDF('P', 'mm', 'A4', true, 'UTF-8', false);
    $pdf->setPrintHeader(false);
    $pdf->setPrintFooter(false);
    $pdf->SetMargins(18, 18, 18);
    $pdf->SetAutoPageBreak(true, 20);
    $pdf->SetCreator($site_name);
    $pdf->SetAuthor($site_name);
    $pdf->SetTitle('Quotation ' . $quote_ref);
    $pdf->SetSubject('Hotel Booking Quotation');
    $pdf->AddPage();

    $w = 174; // usable width (210 - 18*2)

    // ── 1. HEADER BAND ────────────────────────────────────────────────────────
    // Full-width colour band
    $pdf->SetFillColorArray($c_primary);
    $pdf->Rect(0, 0, 210, 42, 'F');

    // Gold accent bar
    $pdf->SetFillColorArray($c_gold);
    $pdf->Rect(0, 42, 210, 2, 'F');

    // Hotel name
    $pdf->SetFont('helvetica', 'B', 22);
    $pdf->SetTextColorArray($c_white);
    $pdf->SetXY(18, 10);
    $pdf->Cell($w, 10, strtoupper($site_name), 0, 1, 'L');

    // Sub-line
    $pdf->SetFont('helvetica', '', 9);
    $pdf->SetTextColorArray([220, 210, 195]);
    $pdf->SetXY(18, 21);
    if ($site_address) {
        $pdf->Cell($w / 2, 5, $site_address, 0, 0, 'L');
    }
    if ($site_phone || $site_email) {
        $right = array_filter([$site_phone, $site_email]);
        $pdf->SetXY(18 + $w / 2, 21);
        $pdf->Cell($w / 2, 5, implode('  |  ', $right), 0, 1, 'R');
    }

    // QUOTATION label
    $pdf->SetFont('helvetica', 'B', 12);
    $pdf->SetTextColorArray([230, 220, 205]);
    $pdf->SetXY(18, 28);
    $pdf->Cell($w, 8, 'HOTEL QUOTATION', 0, 0, 'L');

    // Quote reference on right
    $pdf->SetFont('helvetica', 'B', 13);
    $pdf->SetTextColorArray($c_gold);
    $pdf->Cell(0, 8, $quote_ref, 0, 1, 'R');

    $pdf->SetY(46);

    // ── 2. QUOTE META BAND ────────────────────────────────────────────────────
    $pdf->SetFillColorArray($c_cream);
    $pdf->SetDrawColorArray($c_border);
    $pdf->RoundedRect(18, 48, $w, 18, 2, '1111', 'DF');

    $col = $w / 3;
    $metaItems = [
        ['Date Issued', date('F j, Y')],
        ['Valid Until', $valid_until->format('F j, Y')],
        ['Prepared For', $booking['guest_name']],
    ];
    $pdf->SetFont('helvetica', '', 7.5);
    $pdf->SetTextColorArray($c_muted);
    foreach ($metaItems as $i => $item) {
        $x = 20 + $i * $col;
        $pdf->SetXY($x, 50);
        $pdf->Cell($col - 4, 4, strtoupper($item[0]), 0, 2, 'L');
        $pdf->SetFont('helvetica', 'B', 9.5);
        $pdf->SetTextColorArray($c_ink);
        $pdf->SetX($x);
        $pdf->Cell($col - 4, 5, $item[1], 0, 0, 'L');
        $pdf->SetFont('helvetica', '', 7.5);
        $pdf->SetTextColorArray($c_muted);
    }

    $pdf->SetY(70);

    // ── 3. ROOM & STAY DETAILS ────────────────────────────────────────────────
    // Two-column layout: Room | Stay
    $colW  = ($w - 4) / 2;
    $startY = $pdf->GetY();

    // Room card
    $pdf->SetFillColorArray($c_light);
    $pdf->SetDrawColorArray($c_border);
    $pdf->RoundedRect(18, $startY, $colW, 52, 2, '1111', 'DF');

    $pdf->SetFont('helvetica', 'B', 8);
    $pdf->SetTextColorArray($c_muted);
    $pdf->SetXY(20, $startY + 3);
    $pdf->Cell($colW - 4, 4, 'ROOM', 0, 2, 'L');

    $pdf->SetFont('helvetica', 'B', 12);
    $pdf->SetTextColorArray($c_primary);
    $pdf->SetX(20);
    $pdf->MultiCell($colW - 4, 6, (string)$room['name'], 0, 'L', false, 1);

    if (!empty($room['short_description'])) {
        $pdf->SetFont('helvetica', 'I', 8);
        $pdf->SetTextColorArray($c_muted);
        $pdf->SetX(20);
        $pdf->MultiCell($colW - 4, 4.5, $pdfTrunc((string)$room['short_description'], 130), 0, 'L', false, 1, '', '', true, 0, false, true, 13);
    }

    // Room meta pills
    $roomMeta = array_filter([
        !empty($room['bed_type'])    ? (string)$room['bed_type'] : '',
        !empty($room['size_sqm'])    ? $room['size_sqm'] . ' m²' : '',
        !empty($room['max_guests'])  ? 'Max ' . (int)$room['max_guests'] . ' guests' : '',
    ]);
    if (!empty($roomMeta)) {
        $pdf->SetFont('helvetica', '', 7.5);
        $pdf->SetTextColorArray($c_muted);
        $pdf->SetX(20);
        $pdf->Cell($colW - 4, 5, implode('   ·   ', $roomMeta), 0, 2, 'L');
    }

    // Rate
    $pdf->SetFont('helvetica', 'B', 9.5);
    $pdf->SetTextColorArray($c_primary);
    $pdf->SetX(20);
    $pdf->Cell($colW - 4, 6, $fmt($rate_per_night) . ' / night', 0, 0, 'L');

    // Stay card
    $pdf->SetFillColorArray($c_light);
    $pdf->SetDrawColorArray($c_border);
    $pdf->RoundedRect(18 + $colW + 4, $startY, $colW, 52, 2, '1111', 'DF');

    $x2 = 20 + $colW + 4;

    $pdf->SetFont('helvetica', 'B', 8);
    $pdf->SetTextColorArray($c_muted);
    $pdf->SetXY($x2, $startY + 3);
    $pdf->Cell($colW - 4, 4, 'STAY DETAILS', 0, 2, 'L');

    $stayRows = [
        ['Check-in',  date('D, d M Y', strtotime($booking['check_in_date'])) . '  —  from ' . $check_in_time],
        ['Check-out', date('D, d M Y', strtotime($booking['check_out_date'])) . '  —  by ' . $check_out_time],
        ['Duration',  $nights . ' night' . ($nights !== 1 ? 's' : '')],
        ['Guests',    $adults . ' adult' . ($adults !== 1 ? 's' : '') . ($children > 0 ? ', ' . $children . ' child' . ($children !== 1 ? 'ren' : '') : '')],
    ];
    if (!empty($booking['special_requests'])) {
        $stayRows[] = ['Requests', $pdfTrunc((string)$booking['special_requests'], 90)];
    }

    foreach ($stayRows as $sr) {
        $pdf->SetFont('helvetica', '', 7.5);
        $pdf->SetTextColorArray($c_muted);
        $pdf->SetXY($x2, $pdf->GetY());
        $pdf->Cell(20, 4.8, $sr[0], 0, 0, 'L');
        $pdf->SetFont('helvetica', 'B', 8);
        $pdf->SetTextColorArray($c_ink);
        $pdf->MultiCell($colW - 24, 4.8, $sr[1], 0, 'L', false, 1, $x2 + 20);
    }

    $pdf->SetY($startY + 56);

    // ── 4. PRICING TABLE ──────────────────────────────────────────────────────
    $pdf->SetFont('helvetica', 'B', 10);
    $pdf->SetTextColorArray($c_primary);
    $pdf->SetXY(18, $pdf->GetY() + 2);
    $pdf->Cell($w, 6, 'PRICING BREAKDOWN', 0, 1, 'L');

    // Gold underline
    $pdf->SetFillColorArray($c_gold);
    $pdf->Rect(18, $pdf->GetY(), $w, 0.6, 'F');
    $pdf->SetY($pdf->GetY() + 3);

    $rowH    = 7.5;
    $labelW  = $w * 0.62;
    $amtW    = $w * 0.38;
    $isAlt   = false;

    $priceRows = [];
    $roomLineLabel = (string)$room['name'] . '  ×  ' . $nights . ' night' . ($nights !== 1 ? 's' : '') . '  @  ' . $fmt($rate_per_night) . '/night';
    $priceRows[] = [$roomLineLabel, $fmt($room_subtotal), false];

    if ($children > 0 && $child_supp > 0) {
        $priceRows[] = ['Child supplement  (' . $children . ' child' . ($children !== 1 ? 'ren' : '') . ')', $fmt($child_supp), false];
    }
    if ($vat_enabled && $vat_amount > 0) {
        $priceRows[] = ['VAT  (' . number_format($vat_rate, 0) . '%)', vat_document_value($fmt($vat_amount)), false];
    }

    foreach ($priceRows as [$label, $amount, $isBold]) {
        $bg = $isAlt ? $c_alt : $c_cream;
        $pdf->SetFillColorArray($bg);
        $yy = $pdf->GetY();
        $pdf->Rect(18, $yy, $w, $rowH, 'F');

        $pdf->SetFont('helvetica', $isBold ? 'B' : '', 8.5);
        $pdf->SetTextColorArray($c_ink);
        $pdf->SetXY(20, $yy + 1.2);
        $pdf->Cell($labelW - 2, $rowH - 2, $label, 0, 0, 'L');
        $pdf->Cell($amtW, $rowH - 2, $amount, 0, 1, 'R');
        $pdf->SetY($yy + $rowH);
        $isAlt = !$isAlt;
    }

    // Total row
    $pdf->SetFillColorArray($c_total);
    $yy = $pdf->GetY();
    $pdf->Rect(18, $yy, $w, $rowH + 1, 'F');
    $pdf->SetFont('helvetica', 'B', 10.5);
    $pdf->SetTextColorArray($c_ink);
    $pdf->SetXY(20, $yy + 1.5);
    $pdf->Cell($labelW - 2, $rowH, 'TOTAL AMOUNT', 0, 0, 'L');
    $pdf->SetTextColorArray($c_primary);
    $pdf->Cell($amtW, $rowH, $fmt($total), 0, 1, 'R');
    $pdf->SetY($yy + $rowH + 3);

    // ── 5. PAYMENT TERMS PANEL ────────────────────────────────────────────────
    $pdf->SetY($pdf->GetY() + 2);
    $panelY = $pdf->GetY();
    $panelH = 22;

    if ($deposit_req && $deposit_amt > 0) {
        $balance = $total - $deposit_amt;
        $pdf->SetFillColorArray([255, 248, 220]);
        $pdf->SetDrawColorArray([218, 163, 71]);
        $pdf->RoundedRect(18, $panelY, $w, $panelH, 2, '1111', 'DF');
        $pdf->SetLineWidth(0.8);
        $pdf->SetFillColorArray([218, 163, 71]);
        $pdf->Rect(18, $panelY, 1.4, $panelH, 'F');
        $pdf->SetLineWidth(0.2);

        $pdf->SetFont('helvetica', 'B', 9);
        $pdf->SetTextColorArray([133, 100, 4]);
        $pdf->SetXY(22, $panelY + 3);
        $pdf->Cell($w - 4, 5, 'Deposit Required to Confirm', 0, 2, 'L');
        $pdf->SetFont('helvetica', '', 8.5);
        $pdf->SetX(22);
        $pdf->MultiCell($w - 6, 5, 'A deposit of ' . $fmt($deposit_amt) . ' is required to confirm this booking. The remaining balance of ' . $fmt($balance) . ' is due on arrival.', 0, 'L', false, 1, '', '', true, 0, false, true, 12);
    } else {
        $pdf->SetFillColorArray([255, 248, 220]);
        $pdf->SetDrawColorArray([218, 163, 71]);
        $pdf->RoundedRect(18, $panelY, $w, $panelH, 2, '1111', 'DF');
        $pdf->SetFillColorArray([218, 163, 71]);
        $pdf->Rect(18, $panelY, 1.4, $panelH, 'F');

        $pdf->SetFont('helvetica', 'B', 9);
        $pdf->SetTextColorArray([133, 100, 4]);
        $pdf->SetXY(22, $panelY + 3);
        $pdf->Cell($w - 4, 5, 'Payment Terms', 0, 2, 'L');
        $pdf->SetFont('helvetica', '', 8.5);
        $pdf->SetX(22);
        $pdf->MultiCell($w - 6, 5, $payment_policy, 0, 'L', false, 1, '', '', true, 0, false, true, 12);
    }

    $pdf->SetY($panelY + $panelH + 3);

    // ── 6. ADMIN NOTE ─────────────────────────────────────────────────────────
    if ($notes !== '') {
        $noteY = $pdf->GetY() + 1;
        $pdf->SetFillColorArray([240, 247, 255]);
        $pdf->SetDrawColorArray($c_blue);
        $pdf->RoundedRect(18, $noteY, $w, 20, 2, '1111', 'DF');
        $pdf->SetFillColorArray($c_blue);
        $pdf->Rect(18, $noteY, 1.4, 20, 'F');

        $pdf->SetFont('helvetica', 'B', 9);
        $pdf->SetTextColorArray([26, 74, 138]);
        $pdf->SetXY(22, $noteY + 3);
        $pdf->Cell($w - 4, 5, 'Note from our team', 0, 2, 'L');
        $pdf->SetFont('helvetica', '', 8.5);
        $pdf->SetX(22);
        $pdf->MultiCell($w - 6, 5, $notes, 0, 'L', false, 1, '', '', true, 0, false, true, 13);

        $pdf->SetY($noteY + 23);
    }

    // ── 7. VALIDITY NOTICE ────────────────────────────────────────────────────
    $pdf->SetY($pdf->GetY() + 2);
    $pdf->SetFont('helvetica', 'I', 8);
    $pdf->SetTextColorArray($c_muted);
    $pdf->SetXY(18, $pdf->GetY());
    $pdf->Cell(
        $w,
        5,
        'This quotation is valid until ' . $valid_until->format('F j, Y') . '. Rates and availability are subject to change after this date.',
        0,
        1,
        'C'
    );

    // ── 8. FOOTER ─────────────────────────────────────────────────────────────
    // Disable auto-page-break so the absolutely-positioned footer doesn't
    // trigger new pages (footer sits inside the bottom margin zone y > 277).
    $pdf->SetAutoPageBreak(false);
    $pdf->SetFillColorArray($c_gold);
    $pdf->Rect(18, 272, $w, 0.6, 'F');

    $pdf->SetFillColorArray($c_primary);
    $pdf->Rect(0, 274, 210, 23, 'F');

    $pdf->SetFont('helvetica', 'B', 9);
    $pdf->SetTextColorArray($c_white);
    $pdf->SetXY(18, 277);
    $pdf->Cell($w / 3, 5, $site_name, 0, 0, 'L');

    $pdf->SetFont('helvetica', '', 8);
    $pdf->SetTextColorArray([210, 198, 180]);
    $ctct = array_filter([$site_phone, $site_email, $site_website]);
    $pdf->SetXY(18 + $w / 3, 277);
    $pdf->Cell($w * 2 / 3, 5, implode('   |   ', $ctct), 0, 1, 'C');

    $pdf->SetFont('helvetica', 'I', 7.5);
    $pdf->SetTextColorArray([180, 165, 145]);
    $pdf->SetXY(18, 283);
    $pdf->Cell($w, 5, 'We look forward to welcoming you.', 0, 0, 'C');

    return $pdf->Output('', 'S'); // Return as string
}

/**
 * Generate a conference quotation PDF.
 *
 * @param array $enquiry Conference enquiry row.
 * @param array $room    Conference room row.
 * @param array $options Optional keys: valid_days, quotation_notes, quote_reference.
 */
function generateConferenceQuotationPDF(array $enquiry, array $room, array $options = []): string
{
    if (!hotel_load_tcpdf()) {
        throw new RuntimeException('The PDF engine (TCPDF) is not installed on this server. Upload the vendor/ folder (composer install) or a TCPDF/ folder to enable PDF documents.');
    }

    $siteName = (string)getSetting('site_name', "Rosalyn's Beach Hotel");
    $sitePhone = (string)getSetting('phone_main', '');
    $siteEmail = (string)getSetting('email_main', getSetting('email_from_email', ''));
    $siteAddress = trim((string)getSetting('address_line1', ''));
    $currency = (string)getSetting('currency_symbol', 'MWK ');
    $paymentPolicy = (string)getSetting('payment_policy', 'Payment terms apply as agreed with our reservations team.');

    $validDays = max(1, (int)($options['valid_days'] ?? 7));
    $validUntil = (new DateTime())->modify('+' . $validDays . ' days');
    $notes = trim((string)($options['quotation_notes'] ?? ($enquiry['notes'] ?? '')));
    $quoteRef = trim((string)($options['quote_reference'] ?? ''));
    if ($quoteRef === '') {
        $baseRef = (string)($enquiry['inquiry_reference'] ?? ('CONF-' . (int)($enquiry['id'] ?? 0)));
        $quoteRef = 'CQ-' . strtoupper($baseRef);
    }

    $baseAmount = (float)($enquiry['total_amount'] ?? 0);
    $vatRate = (float)($enquiry['vat_rate'] ?? 0);
    $vatAmount = (float)($enquiry['vat_amount'] ?? 0);
    if ($vatAmount <= 0 && $vatRate > 0) {
        // Fallback derives per installation mode (on top / extracted / off).
        $vatAmount = vat_components($baseAmount)['vat'];
    }
    $totalAmount = (float)($enquiry['total_with_vat'] ?? 0);
    if ($totalAmount <= 0) {
        $totalAmount = (function_exists('vat_mode') && vat_mode() === 'inclusive')
            ? $baseAmount
            : $baseAmount + $vatAmount;
    }

    $depositRequired = (float)($enquiry['deposit_required'] ?? 0);
    $roomName = (string)($room['name'] ?? 'Conference Room');
    $eventType = (string)($enquiry['event_type'] ?? 'Conference Event');
    $attendees = max(1, (int)($enquiry['number_of_attendees'] ?? 1));
    $eventDate = !empty($enquiry['event_date'])
        ? date('l, F j, Y', strtotime((string)$enquiry['event_date']))
        : 'To be confirmed';
    $startTime = !empty($enquiry['start_time']) ? date('H:i', strtotime((string)$enquiry['start_time'])) : '';
    $endTime = !empty($enquiry['end_time']) ? date('H:i', strtotime((string)$enquiry['end_time'])) : '';
    $eventTime = trim($startTime . ($endTime !== '' ? ' - ' . $endTime : ''));
    if ($eventTime === '') {
        $eventTime = 'To be confirmed';
    }

    $fmt = static function (float $value) use ($currency): string {
        return $currency . number_format($value, 0);
    };
    $esc = static function (mixed $value): string {
        return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
    };

    if (function_exists('hotel_default_conference_quotation_document_html')) {
        return quotationPdfRenderDocument(
            'conference_quotation_document',
            hotel_default_conference_quotation_document_html(),
            [
                'logo_html' => quotationPdfLogoHtml(),
                'site_name' => $esc($siteName),
                'address' => $esc($siteAddress),
                'contact_phone' => $esc($sitePhone),
                'contact_email' => $esc($siteEmail),
                'quotation_reference' => $esc($quoteRef),
                'valid_until' => $esc($validUntil->format('F j, Y')),
                'inquiry_reference' => $esc((string)($enquiry['inquiry_reference'] ?? '')),
                'company_name' => $esc((string)($enquiry['company_name'] ?? '')),
                'contact_person' => $esc((string)($enquiry['contact_person'] ?? '')),
                'conference_room' => $esc($roomName),
                'event_date' => $esc($eventDate),
                'event_time' => $esc($eventTime),
                'attendees' => (string)$attendees,
                'total_amount' => $esc($fmt($totalAmount)),
                'vat_amount' => $esc(vat_document_value($fmt($vatAmount))),
                'deposit_amount' => $esc($fmt($depositRequired)),
                'payment_policy' => nl2br($esc($paymentPolicy)),
                'quotation_notes' => nl2br($esc($notes)),
            ],
            'Conference Quotation ' . $quoteRef
        );
    }

    if (!class_exists('JapandiTCPDF')) {
        class JapandiTCPDF extends TCPDF {
            public function AddPage($orientation = '', $format = '', $keepmargins = false, $tocpage = false): void
            {
                parent::AddPage($orientation, $format, $keepmargins, $tocpage);
                $this->SetFillColor(247, 243, 238);
                $this->Rect(0, 0, $this->getPageWidth(), $this->getPageHeight(), 'F');
            }
        }
    }
    $pdf = new JapandiTCPDF('P', 'mm', 'A4', true, 'UTF-8', false);
    $pdf->setPrintHeader(false);
    $pdf->setPrintFooter(false);
    $pdf->SetMargins(16, 16, 16);
    $pdf->SetAutoPageBreak(true, 18);
    $pdf->SetTitle('Conference Quotation ' . $quoteRef);
    $pdf->SetAuthor($siteName);
    $pdf->AddPage();

    $vatRow = '';
    if ($vatAmount > 0) {
        $vatLabel = $vatRate > 0 ? 'VAT (' . number_format($vatRate, 0) . '%)' : 'VAT';
        $vatRow = '<tr><td>' . $esc($vatLabel) . '</td><td class="right">' . $esc(vat_document_value($fmt($vatAmount))) . '</td></tr>';
    }

    $depositRow = '';
    if ($depositRequired > 0) {
        $depositRow = '<tr><td>Deposit Required</td><td class="right">' . $esc($fmt($depositRequired)) . '</td></tr>';
    }

    $notesBlock = '';
    if ($notes !== '') {
        $notesBlock = '<div class="note-block">'
            . '<h3>Notes</h3>'
            . '<p>' . nl2br($esc($notes)) . '</p>'
            . '</div>';
    }

    $html = '
<style>
body { font-family: helvetica; color: #2A2723; font-size: 10.5px; background: #F7F3EE; }
.header { background: #8A775F; color: #ffffff; padding: 16px; }
.header h1 { margin: 0 0 4px; font-size: 20px; letter-spacing: 0.8px; }
.header p { margin: 0; font-size: 10px; color: #E6DBCF; }
.meta { margin-top: 10px; border: 1px solid #E3D8CA; }
.meta td { padding: 8px; font-size: 10px; }
.meta .label { color: #7A6A58; text-transform: uppercase; letter-spacing: 0.6px; width: 35%; }
.section-title { margin: 14px 0 6px; color: #8A775F; font-size: 12px; letter-spacing: 0.4px; }
.detail-table, .price-table { border: 1px solid #E3D8CA; border-collapse: collapse; }
.detail-table td, .price-table td { border: 1px solid #EDE4D8; padding: 7px; font-size: 10px; }
.detail-table td:first-child, .price-table td:first-child { background: #F7F3EE; width: 38%; color: #5F5343; }
.right { text-align: right; }
.total-row td { background: #EDE2D4; font-weight: bold; color: #231F1C; }
.policy { margin-top: 10px; background: #FFF8EC; border-left: 3px solid #B18247; padding: 10px; }
.note-block { margin-top: 10px; background: #F2F7FC; border-left: 3px solid #4A6FA5; padding: 10px; }
.note-block h3 { margin: 0 0 6px; font-size: 11px; color: #2D4F7A; }
.footer { margin-top: 16px; font-size: 9px; color: #766A5E; text-align: center; }
</style>

<div class="header">
    <h1>' . $esc($siteName) . '</h1>
    <p>' . $esc($siteAddress) . ' | ' . $esc($sitePhone) . ' | ' . $esc($siteEmail) . '</p>
</div>

<table class="meta" width="100%" cellspacing="0" cellpadding="0">
    <tr>
        <td class="label">Quotation Ref</td>
        <td>' . $esc($quoteRef) . '</td>
        <td class="label">Valid Until</td>
        <td>' . $esc($validUntil->format('F j, Y')) . '</td>
    </tr>
    <tr>
        <td class="label">Prepared For</td>
        <td>' . $esc((string)($enquiry['contact_person'] ?? 'Guest')) . '</td>
        <td class="label">Company</td>
        <td>' . $esc((string)($enquiry['company_name'] ?? '')) . '</td>
    </tr>
</table>

<h2 class="section-title">Conference Details</h2>
<table class="detail-table" width="100%" cellspacing="0" cellpadding="0">
    <tr><td>Enquiry Reference</td><td>' . $esc((string)($enquiry['inquiry_reference'] ?? '')) . '</td></tr>
    <tr><td>Event Type</td><td>' . $esc($eventType) . '</td></tr>
    <tr><td>Room</td><td>' . $esc($roomName) . '</td></tr>
    <tr><td>Event Date</td><td>' . $esc($eventDate) . '</td></tr>
    <tr><td>Event Time</td><td>' . $esc($eventTime) . '</td></tr>
    <tr><td>Attendees</td><td>' . $esc((string)$attendees) . '</td></tr>
</table>

<h2 class="section-title">Price Breakdown</h2>
<table class="price-table" width="100%" cellspacing="0" cellpadding="0">
    <tr><td>Conference Package</td><td class="right">' . $esc($fmt($baseAmount)) . '</td></tr>'
        . $vatRow
        . $depositRow
        . '<tr class="total-row"><td>Total Quotation</td><td class="right">' . $esc($fmt($totalAmount)) . '</td></tr>
</table>

<div class="policy">
    <strong>Payment Terms</strong><br>' . $esc($paymentPolicy) . '
</div>'
        . $notesBlock . '

<div class="footer">
    This quotation is valid until ' . $esc($validUntil->format('F j, Y')) . '. Availability and rates are subject to confirmation at acceptance.
</div>';

    if (function_exists('bookingRenderPdfFromHtml')) {
        return bookingRenderPdfFromHtml($html, 'Conference Quotation ' . $quoteRef);
    }

    $pdf->writeHTML($html, true, false, true, false, '');

    return $pdf->Output('', 'S');
}

/**
 * Generate an event quotation PDF for manual event booking proposals.
 *
 * @param array $event     Event row from events table.
 * @param array $recipient Keys: name, email, phone.
 * @param array $options   Optional keys: attendee_count, valid_days, quotation_notes, quote_reference.
 */
function generateEventQuotationPDF(array $event, array $recipient, array $options = []): string
{
    if (!hotel_load_tcpdf()) {
        throw new RuntimeException('The PDF engine (TCPDF) is not installed on this server. Upload the vendor/ folder (composer install) or a TCPDF/ folder to enable PDF documents.');
    }

    $siteName = (string)getSetting('site_name', "Rosalyn's Beach Hotel");
    $sitePhone = (string)getSetting('phone_main', '');
    $siteEmail = (string)getSetting('email_main', getSetting('email_from_email', ''));
    $currency = (string)getSetting('currency_symbol', 'MWK ');

    $attendeeCount = max(1, (int)($options['attendee_count'] ?? 1));
    $validDays = max(1, (int)($options['valid_days'] ?? 7));
    $validUntil = (new DateTime())->modify('+' . $validDays . ' days');
    $notes = trim((string)($options['quotation_notes'] ?? ''));

    $quoteRef = trim((string)($options['quote_reference'] ?? ''));
    if ($quoteRef === '') {
        $eventId = (int)($event['id'] ?? 0);
        $seed = (string)($recipient['email'] ?? ($recipient['name'] ?? time()));
        $quoteRef = 'EQ-' . strtoupper((string)$eventId) . '-' . strtoupper(substr(hash('crc32b', $seed), 0, 6));
    }

    $unitPrice = (float)($event['ticket_price'] ?? 0);
    $totalAmount = $unitPrice * $attendeeCount;

    $eventDate = !empty($event['event_date'])
        ? date('l, F j, Y', strtotime((string)$event['event_date']))
        : 'To be confirmed';
    $startTime = !empty($event['start_time']) ? date('H:i', strtotime((string)$event['start_time'])) : '';
    $endTime = !empty($event['end_time']) ? date('H:i', strtotime((string)$event['end_time'])) : '';
    $eventTime = trim($startTime . ($endTime !== '' ? ' - ' . $endTime : ''));
    if ($eventTime === '') {
        $eventTime = 'To be confirmed';
    }

    $location = (string)($event['location'] ?? 'To be confirmed');
    if ($location === '') {
        $location = 'To be confirmed';
    }

    $fmt = static function (float $value) use ($currency): string {
        return $currency . number_format($value, 0);
    };
    $esc = static function (mixed $value): string {
        return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
    };

    if (function_exists('hotel_default_event_quotation_document_html')) {
        return quotationPdfRenderDocument(
            'event_quotation_document',
            hotel_default_event_quotation_document_html(),
            [
                'logo_html' => quotationPdfLogoHtml(),
                'site_name' => $esc($siteName),
                'address' => '',
                'contact_phone' => $esc($sitePhone),
                'contact_email' => $esc($siteEmail),
                'quotation_reference' => $esc($quoteRef),
                'valid_until' => $esc($validUntil->format('F j, Y')),
                'recipient_name' => $esc((string)($recipient['name'] ?? 'Guest')),
                'event_title' => $esc((string)($event['title'] ?? 'Event')),
                'event_date' => $esc($eventDate),
                'event_time' => $esc($eventTime),
                'event_location' => $esc($location),
                'attendee_count' => (string)$attendeeCount,
                'rate_per_attendee' => $esc($fmt($unitPrice)),
                'total_amount' => $esc($fmt($totalAmount)),
                'quotation_notes' => nl2br($esc($notes)),
            ],
            'Event Quotation ' . $quoteRef
        );
    }

    if (!class_exists('JapandiTCPDF')) {
        class JapandiTCPDF extends TCPDF {
            public function AddPage($orientation = '', $format = '', $keepmargins = false, $tocpage = false): void
            {
                parent::AddPage($orientation, $format, $keepmargins, $tocpage);
                $this->SetFillColor(247, 243, 238);
                $this->Rect(0, 0, $this->getPageWidth(), $this->getPageHeight(), 'F');
            }
        }
    }
    $pdf = new JapandiTCPDF('P', 'mm', 'A4', true, 'UTF-8', false);
    $pdf->setPrintHeader(false);
    $pdf->setPrintFooter(false);
    $pdf->SetMargins(16, 16, 16);
    $pdf->SetAutoPageBreak(true, 18);
    $pdf->SetTitle('Event Quotation ' . $quoteRef);
    $pdf->SetAuthor($siteName);
    $pdf->AddPage();

    $notesBlock = '';
    if ($notes !== '') {
        $notesBlock = '<div class="note">'
            . '<strong>Notes</strong><br>' . nl2br($esc($notes))
            . '</div>';
    }

    $html = '
<style>
body { font-family: helvetica; color: #2A2723; font-size: 10.5px; background: #F7F3EE; }
.head { background: #8A775F; color: #ffffff; padding: 15px; }
.head h1 { margin: 0 0 4px; font-size: 20px; }
.head p { margin: 0; font-size: 10px; color: #E6DBCF; }
.meta, .tbl { border: 1px solid #E3D8CA; border-collapse: collapse; margin-top: 10px; }
.meta td, .tbl td { border: 1px solid #EDE4D8; padding: 7px; font-size: 10px; }
.meta td:nth-child(odd), .tbl td:first-child { background: #F7F3EE; color: #5F5343; }
.right { text-align: right; }
.section { margin-top: 14px; color: #8A775F; font-size: 12px; }
.total td { background: #EDE2D4; font-weight: bold; }
.note { margin-top: 10px; background: #F2F7FC; border-left: 3px solid #4A6FA5; padding: 10px; }
.foot { margin-top: 16px; font-size: 9px; color: #766A5E; text-align: center; }
</style>

<div class="head">
    <h1>' . $esc($siteName) . '</h1>
    <p>' . $esc($sitePhone) . ' | ' . $esc($siteEmail) . '</p>
</div>

<table class="meta" width="100%" cellspacing="0" cellpadding="0">
    <tr>
        <td>Quotation Ref</td><td>' . $esc($quoteRef) . '</td>
        <td>Valid Until</td><td>' . $esc($validUntil->format('F j, Y')) . '</td>
    </tr>
    <tr>
        <td>Prepared For</td><td>' . $esc((string)($recipient['name'] ?? 'Guest')) . '</td>
        <td>Recipient Email</td><td>' . $esc((string)($recipient['email'] ?? '')) . '</td>
    </tr>
</table>

<h2 class="section">Event Details</h2>
<table class="tbl" width="100%" cellspacing="0" cellpadding="0">
    <tr><td>Event</td><td>' . $esc((string)($event['title'] ?? 'Event')) . '</td></tr>
    <tr><td>Date</td><td>' . $esc($eventDate) . '</td></tr>
    <tr><td>Time</td><td>' . $esc($eventTime) . '</td></tr>
    <tr><td>Location</td><td>' . $esc($location) . '</td></tr>
    <tr><td>Attendee Count</td><td>' . $esc((string)$attendeeCount) . '</td></tr>
</table>

<h2 class="section">Price Breakdown</h2>
<table class="tbl" width="100%" cellspacing="0" cellpadding="0">
    <tr><td>Rate Per Attendee</td><td class="right">' . $esc($fmt($unitPrice)) . '</td></tr>
    <tr><td>Attendees</td><td class="right">' . $esc((string)$attendeeCount) . '</td></tr>
    <tr class="total"><td>Total Quotation</td><td class="right">' . $esc($fmt($totalAmount)) . '</td></tr>
</table>'
        . $notesBlock . '

<div class="foot">
    This quotation is valid until ' . $esc($validUntil->format('F j, Y')) . '. Please confirm before the validity date to secure your event booking.
</div>';

    if (function_exists('bookingRenderPdfFromHtml')) {
        return bookingRenderPdfFromHtml($html, 'Event Quotation ' . $quoteRef);
    }

    $pdf->writeHTML($html, true, false, true, false, '');

    return $pdf->Output('', 'S');
}
