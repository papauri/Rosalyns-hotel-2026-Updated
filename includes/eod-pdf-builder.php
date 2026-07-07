<?php

declare(strict_types=1);

/**
 * Shared EOD Report PDF builder.
 *
 * Usage: $pdfString = buildEodPdf($d, $site_name, $currency_symbol, $user_name);
 *
 * Returns the PDF as a raw binary string ready to echo or attach to email.
 */

if (!class_exists('TCPDF')) {
    // Prefer the shared resilient loader (composer autoload, vendor TCPDF, or a
    // standalone TCPDF/ folder). Fall back to the direct vendor path if the
    // helper isn't loaded in this context.
    if (function_exists('hotel_load_tcpdf')) {
        hotel_load_tcpdf();
    } elseif (is_file(__DIR__ . '/../vendor/tecnickcom/tcpdf/tcpdf.php')) {
        require_once __DIR__ . '/../vendor/tecnickcom/tcpdf/tcpdf.php';
    } elseif (is_file(__DIR__ . '/../TCPDF/tcpdf.php')) {
        require_once __DIR__ . '/../TCPDF/tcpdf.php';
    }
}

if (!class_exists('RhEodPdf')) {
    class RhEodPdf extends TCPDF
    {
        public array  $bg_cream   = [243, 236, 228];
        public array  $ac_gold    = [177, 130, 71];
        public array  $ac_divider = [229, 217, 201];
        public string $footer_site     = '';
        public string $footer_date     = '';
        public string $footer_currency = '';

        public function AddPage(
            $orientation = '',
            $format      = '',
            $keepmargins = false,
            $tocpage     = false
        ): void {
            parent::AddPage($orientation, $format, $keepmargins, $tocpage);
            // Cream background
            $this->SetFillColorArray($this->bg_cream);
            $this->Rect(0, 0, $this->getPageWidth(), $this->getPageHeight(), 'F');
            // Gold top strip
            $this->SetFillColorArray($this->ac_gold);
            $this->Rect(0, 0, $this->getPageWidth(), 2.5, 'F');
        }

        public function Footer(): void
        {
            $this->SetY(-10);
            $this->SetDrawColorArray($this->ac_divider);
            $this->Line(12, $this->GetY(), 198, $this->GetY());
            $this->SetY($this->GetY() + 1.5);
            $this->SetFont('helvetica', '', 6.5);
            $this->SetTextColor(168, 150, 131);
            $txt = $this->footer_site
                . '  |  EOD Report  |  ' . $this->footer_date
                . '  |  All amounts in ' . $this->footer_currency
                . '  |  Page ' . $this->getAliasNumPage() . ' of ' . $this->getAliasNbPages();
            $this->Cell(0, 4, $txt, 0, 0, 'C');
        }
    }
}

function buildEodPdf(
    array  $d,
    string $site_name,
    string $currency_symbol,
    string $user_name
): string {
    // ── Colours ────────────────────────────────────────────────────────────────
    $CHARCOAL = [35,  31,  28];
    $GOLD     = [177, 130, 71];
    $CREAM    = [243, 236, 228];
    $LIGHT_BG = [247, 243, 238];
    $BROWN    = [138, 119, 95];
    $TEXT2    = [94,  85,  77];
    $DIVIDER  = [229, 217, 201];
    $WHITE    = [255, 255, 255];
    $GREEN    = [22,  101, 52];
    $MIDGREEN = [21,  128, 61];
    $AMBER    = [146, 64,  14];
    $RED      = [185, 28,  28];
    $MUTED    = [168, 150, 131];

    // ── Unpack $d with safe defaults ────────────────────────────────────────────
    $date    = (string)($d['date'] ?? date('Y-m-d'));
    $ops     = (array)($d['ops'] ?? []);
    $rev     = (array)($d['rev'] ?? []);
    $gross   = (float)($d['gross'] ?? 0);
    $net     = (float)($d['net']   ?? 0);
    $adr     = (float)($d['adr']   ?? 0);
    $revpar  = (float)($d['revpar'] ?? 0);
    $methods = (array)($d['methods'] ?? []);
    $cash_total  = (float)($d['cash_total'] ?? 0);
    $pos     = (array)($d['pos'] ?? ['orders' => 0, 'gross' => 0, 'cogs' => 0, 'voided_count' => 0, 'voided_value' => 0]);
    $pos_by_type  = (array)($d['pos_by_type'] ?? []);
    $top_items    = (array)($d['top_items']   ?? []);
    $void_reasons = (array)($d['void_reasons'] ?? []);
    $hk           = (array)($d['hk'] ?? ['pending' => 0, 'in_progress' => 0, 'completed' => 0]);
    $reviewRow    = (array)($d['reviewRow'] ?? ['cnt' => 0, 'avg_rating' => 0]);
    $outstanding  = (float)($d['outstanding'] ?? 0);
    $rooms_total    = (int)($d['rooms_total']    ?? 0);
    $rooms_occupied = (int)($d['rooms_occupied'] ?? 0);
    $rooms_oo       = (int)($d['rooms_oo']       ?? 0);
    $occupancy_pct  = (float)($d['occupancy_pct'] ?? 0);
    $tom          = (array)($d['tom'] ?? ['arrivals' => 0, 'departures' => 0, 'rev_forecast' => 0]);
    $score        = (int)($d['score'] ?? 0);
    $score_label  = (string)($d['score_label'] ?? 'Needs attention');
    $net_change   = (float)($d['net_change']  ?? 0);
    $pos_change   = (float)($d['pos_change']  ?? 0);
    $occ_change   = (float)($d['occ_change']  ?? 0);
    $arrivals_remaining    = (int)($d['arrivals_remaining']    ?? 0);
    $departures_remaining  = (int)($d['departures_remaining']  ?? 0);
    $rooms_unsold          = (int)($d['rooms_unsold']          ?? 0);
    $empty_room_opportunity = (float)($d['empty_room_opportunity'] ?? 0);
    $payment_capture_rate  = (float)($d['payment_capture_rate']  ?? 100);
    $room_type_perf  = (array)($d['room_type_perf']  ?? []);
    $guest_intel     = (array)($d['guest_intel']     ?? ['new_guests' => 0, 'returning_guests' => 0, 'avg_lead_days' => 0]);
    $returning_rate  = (float)($d['returning_rate']  ?? 0);
    $closeout_alerts = (array)($d['closeout_alerts'] ?? []);
    $maintenance     = (array)($d['maintenance']     ?? ['urgent' => 0, 'high' => 0, 'medium' => 0, 'low' => 0, 'total_open' => 0]);
    $quotation_stats = (array)($d['quotation_stats'] ?? ['sent_today' => 0, 'accepted_today' => 0, 'total_active' => 0, 'pipeline_value' => 0.0]);

    $tomorrow   = date('Y-m-d', strtotime($date . ' +1 day'));
    $dateLabel  = date('l, F j, Y', strtotime($date));
    $curr_trim  = trim($currency_symbol);

    // ── Helpers ────────────────────────────────────────────────────────────────
    $m   = fn(float $v): string => $currency_symbol . number_format($v, 2);
    $sgn = fn(float $v): string => $v >= 0 ? '+' : '-';

    $pos_gross      = (float)$pos['gross'];
    $pos_cogs       = (float)$pos['cogs'];
    $pos_margin     = $pos_gross - $pos_cogs;
    $pos_margin_pct = $pos_gross > 0 ? ($pos_margin / $pos_gross) * 100 : 0;
    $avg_order      = (int)$pos['orders'] > 0 ? $pos_gross / (int)$pos['orders'] : 0;

    // Non-cash total derived from methods
    $ncash_total = 0.0;
    foreach ($methods as $mRow) {
        $mn = strtolower(trim((string)($mRow['method'] ?? '')));
        if (strpos($mn, 'cash') === false && $mn !== '' && $mn !== 'unassigned') {
            $ncash_total += (float)($mRow['total'] ?? 0);
        }
    }

    $score_color = $score >= 90 ? $GREEN : ($score >= 75 ? $MIDGREEN : ($score >= 55 ? $AMBER : $RED));

    // Layout constants (A4 portrait, 12mm margins)
    $LM  = 12.0;   // left margin / content start x
    $CW  = 186.0;  // content width  (210 - 12 - 12)
    $COL = 91.0;   // each column width
    $GAP = 4.0;    // column gap
    $CRX = $LM + $COL + $GAP;  // right column x = 107mm
    $ROW = 5.8;    // standard row height
    $SH  = 7.0;    // section header height

    // ── PDF setup ──────────────────────────────────────────────────────────────
    $pdf = new RhEodPdf('P', 'mm', 'A4', true, 'UTF-8', false);
    $pdf->footer_site     = $site_name;
    $pdf->footer_date     = $date;
    $pdf->footer_currency = $curr_trim;
    $pdf->bg_cream        = $CREAM;
    $pdf->ac_gold         = $GOLD;
    $pdf->ac_divider      = $DIVIDER;
    $pdf->SetCreator($site_name);
    $pdf->SetAuthor($site_name);
    $pdf->SetTitle('End of Day Report — ' . $dateLabel);
    $pdf->SetSubject('EOD Report');
    $pdf->SetAutoPageBreak(false);
    $pdf->setPrintHeader(false);
    $pdf->setPrintFooter(true);
    $pdf->SetMargins($LM, 14, $LM);
    $pdf->AddPage();

    $y = 5.0;  // current cursor (below the 2.5mm gold strip)

    // ══════════════════════════════════════════════════════════════════════════
    // HEADER BAND (32mm tall, charcoal)
    // ══════════════════════════════════════════════════════════════════════════
    $HH = 32.0;
    $pdf->SetFillColorArray($CHARCOAL);
    $pdf->Rect($LM, $y, $CW, $HH, 'F');

    // Gold left accent bar
    $pdf->SetFillColorArray($GOLD);
    $pdf->Rect($LM, $y, 3.5, $HH, 'F');

    // Eyebrow: END OF DAY REPORT
    $pdf->SetTextColorArray($GOLD);
    $pdf->SetFont('helvetica', 'B', 7);
    $pdf->SetXY($LM + 6, $y + 5);
    $pdf->Cell(120, 5, 'END OF DAY REPORT', 0, 0, 'L');

    // Hotel name
    $pdf->SetTextColorArray($CREAM);
    $pdf->SetFont('helvetica', 'B', 16);
    $pdf->SetXY($LM + 6, $y + 10);
    $pdf->Cell(120, 9, $site_name, 0, 0, 'L');

    // Date + generated by
    $pdf->SetTextColorArray($MUTED);
    $pdf->SetFont('helvetica', '', 7);
    $pdf->SetXY($LM + 6, $y + 20);
    $pdf->Cell(120, 5, $dateLabel . '   ·   Generated ' . date('H:i') . '   ·   ' . $user_name, 0, 0, 'L');

    // Health score badge (right side of header band, 19×24mm)
    $badgeX = $LM + $CW - 23;
    $badgeY = $y + 4;
    $pdf->SetFillColorArray($score_color);
    $pdf->RoundedRect($badgeX, $badgeY, 19, 24, 2, '1111', 'F');

    $pdf->SetTextColorArray($WHITE);
    $pdf->SetFont('helvetica', 'B', 14);
    $pdf->SetXY($badgeX, $badgeY + 3);
    $pdf->Cell(19, 9, (string)$score, 0, 0, 'C');

    $pdf->SetFont('helvetica', '', 6);
    $pdf->SetXY($badgeX, $badgeY + 11);
    $pdf->Cell(19, 5, '/ 100', 0, 0, 'C');

    $pdf->SetFont('helvetica', 'B', 5.5);
    $pdf->SetXY($badgeX, $badgeY + 16.5);
    $pdf->Cell(19, 5, strtoupper($score_label), 0, 0, 'C');

    $y += $HH + 2;

    // ══════════════════════════════════════════════════════════════════════════
    // KPI STRIP (4 boxes, 20mm tall)
    // ══════════════════════════════════════════════════════════════════════════
    $kpiW = ($CW - 3 * 2) / 4;   // 45mm each, 2mm gaps
    $kpiH = 20.0;

    $net_sub_c = $net_change >= 0 ? $GREEN : $RED;
    $occ_sub_c = $occ_change >= 0 ? $GREEN : $RED;
    $pos_sub_c = $pos_change >= 0 ? $GREEN : $RED;

    // Preset flags — hotel KPIs (occupancy/ADR) are meaningless for
    // bookings-off businesses (supermarket, retail, gym, bar); they get
    // POS-relevant KPIs in those two slots instead. Defaults keep the
    // classic hotel layout if the module system isn't loaded.
    $eodModBookings   = !function_exists('moduleEnabled') || moduleEnabled('bookings');
    $eodModConference = !function_exists('moduleEnabled') || moduleEnabled('conference');
    $eodModGym        = !function_exists('moduleEnabled') || moduleEnabled('gym');
    $eodModEvents     = function_exists('isEventsEnabled') ? isEventsEnabled() : true;

    if ($eodModBookings) {
        $kpi_slot2 = [
            'label' => 'OCCUPANCY',
            'value' => number_format($occupancy_pct, 1) . '%',
            'sub'   => $rooms_occupied . '/' . $rooms_total . ' rooms  (' . ($occ_change >= 0 ? '+' : '') . number_format($occ_change, 1) . ' pts)',
            'sub_c' => $occ_sub_c,
        ];
        $kpi_slot3 = [
            'label' => 'ADR',
            'value' => $m($adr),
            'sub'   => 'RevPAR ' . $m($revpar),
            'sub_c' => $TEXT2,
        ];
    } else {
        $eodOrders   = (int)($pos['orders'] ?? 0);
        $kpi_slot2 = [
            'label' => 'ORDERS TODAY',
            'value' => (string)$eodOrders,
            'sub'   => (int)($pos['voided_count'] ?? 0) . ' void(s)',
            'sub_c' => $TEXT2,
        ];
        $kpi_slot3 = [
            'label' => 'AVG ORDER VALUE',
            'value' => $m($eodOrders > 0 ? ((float)($pos['gross'] ?? 0)) / $eodOrders : 0),
            'sub'   => 'per settled order',
            'sub_c' => $TEXT2,
        ];
    }

    $kpis = [
        [
            'label' => 'NET REVENUE',
            'value' => $m($net),
            'sub'   => ($net_change >= 0 ? '+' : '') . $m($net_change) . ' vs yday',
            'sub_c' => $net_sub_c,
        ],
        $kpi_slot2,
        $kpi_slot3,
        [
            'label' => isRestaurantEnabled() ? 'F&B / POS' : 'POS',
            'value' => $m($pos_gross),
            'sub'   => ($pos_change >= 0 ? '+' : '') . $m($pos_change) . ' vs yday',
            'sub_c' => $pos_sub_c,
        ],
    ];

    $kx = $LM;
    foreach ($kpis as $ki) {
        // Box
        $pdf->SetFillColorArray($CREAM);
        $pdf->Rect($kx, $y, $kpiW, $kpiH, 'F');
        $pdf->SetDrawColorArray($DIVIDER);
        $pdf->Rect($kx, $y, $kpiW, $kpiH, 'D');
        // Gold bottom accent bar
        $pdf->SetFillColorArray($GOLD);
        $pdf->Rect($kx, $y + $kpiH - 1, $kpiW, 1, 'F');
        // Label
        $pdf->SetTextColorArray($BROWN);
        $pdf->SetFont('helvetica', 'B', 6);
        $pdf->SetXY($kx + 2, $y + 2.5);
        $pdf->Cell($kpiW - 4, 4, $ki['label'], 0, 0, 'L');
        // Value
        $pdf->SetTextColorArray($CHARCOAL);
        $pdf->SetFont('helvetica', 'B', 10.5);
        $pdf->SetXY($kx + 2, $y + 7);
        $pdf->Cell($kpiW - 4, 7, $ki['value'], 0, 0, 'L');
        // Sub-text
        $pdf->SetTextColorArray($ki['sub_c']);
        $pdf->SetFont('helvetica', '', 6);
        $pdf->SetXY($kx + 2, $y + 14.5);
        $pdf->Cell($kpiW - 4, 4, $ki['sub'], 0, 0, 'L');
        $kx += $kpiW + 2;
    }

    $y += $kpiH + 4;

    // ══════════════════════════════════════════════════════════════════════════
    // TWO-COLUMN LAYOUT
    // ══════════════════════════════════════════════════════════════════════════
    $yL = $y;
    $yR = $y;

    // Column section header helper
    $secL = function (string $lbl) use ($pdf, &$yL, $LM, $COL, $CHARCOAL, $GOLD, $WHITE, $SH): void {
        // Charcoal fill
        $pdf->SetFillColorArray($CHARCOAL);
        $pdf->Rect($LM, $yL, $COL, $SH, 'F');
        // Gold left accent
        $pdf->SetFillColorArray($GOLD);
        $pdf->Rect($LM, $yL, 2.5, $SH, 'F');
        $pdf->SetTextColorArray($WHITE);
        $pdf->SetFont('helvetica', 'B', 7.5);
        $pdf->SetXY($LM + 5, $yL + 1.5);
        $pdf->Cell($COL - 5, $SH - 2, strtoupper($lbl), 0, 0, 'L');
        $yL += $SH + 1;
    };
    $secR = function (string $lbl, array $color) use ($pdf, &$yR, $CRX, $COL, $GOLD, $WHITE, $SH): void {
        $pdf->SetFillColorArray($color);
        $pdf->Rect($CRX, $yR, $COL, $SH, 'F');
        $pdf->SetFillColorArray($GOLD);
        $pdf->Rect($CRX, $yR, 2.5, $SH, 'F');
        $pdf->SetTextColorArray($WHITE);
        $pdf->SetFont('helvetica', 'B', 7.5);
        $pdf->SetXY($CRX + 5, $yR + 1.5);
        $pdf->Cell($COL - 5, $SH - 2, strtoupper($lbl), 0, 0, 'L');
        $yR += $SH + 1;
    };
    $rowL = function (string $lbl, string $val, bool $bold = false, int $i = 0) use ($pdf, &$yL, $LM, $LIGHT_BG, $CREAM, $DIVIDER, $TEXT2, $CHARCOAL, $ROW): void {
        $bg = $i % 2 === 0 ? $LIGHT_BG : $CREAM;
        $pdf->SetFillColorArray($bg);
        $pdf->SetDrawColorArray($DIVIDER);
        $pdf->SetFont('helvetica', $bold ? 'B' : '', 7.5);
        $pdf->SetTextColorArray($TEXT2);
        $pdf->SetXY($LM, $yL);
        $pdf->Cell(58, $ROW, '  ' . $lbl, 'B', 0, 'L', true);
        $pdf->SetTextColorArray($CHARCOAL);
        $pdf->SetFont('helvetica', $bold ? 'B' : '', 7.5);
        $pdf->Cell(33, $ROW, $val, 'B', 0, 'R', true);
        $yL += $ROW;
    };
    $rowR = function (string $lbl, string $val, bool $bold = false, int $i = 0) use ($pdf, &$yR, $CRX, $COL, $LIGHT_BG, $CREAM, $DIVIDER, $TEXT2, $CHARCOAL, $ROW): void {
        $bg = $i % 2 === 0 ? $LIGHT_BG : $CREAM;
        $pdf->SetFillColorArray($bg);
        $pdf->SetDrawColorArray($DIVIDER);
        $pdf->SetFont('helvetica', $bold ? 'B' : '', 7.5);
        $pdf->SetTextColorArray($TEXT2);
        $pdf->SetXY($CRX, $yR);
        $pdf->Cell(58, $ROW, '  ' . $lbl, 'B', 0, 'L', true);
        $pdf->SetTextColorArray($CHARCOAL);
        $pdf->SetFont('helvetica', $bold ? 'B' : '', 7.5);
        $pdf->Cell(33, $ROW, $val, 'B', 0, 'R', true);
        $yR += $ROW;
    };

    // ── LEFT: Revenue by Source ───────────────────────────────────────────────
    $secL('Revenue by Source');
    $i = 0;
    if ($eodModBookings)   { $rowL('Rooms',       $m((float)($rev['room_gross'] ?? 0)), false, $i++); }
    if ($eodModConference) { $rowL('Conferences', $m((float)($rev['conf_gross'] ?? 0)), false, $i++); }
    $rowL(isRestaurantEnabled() ? 'F&B / POS' : 'POS', $m((float)($rev['fnb_gross'] ?? 0)),  false, $i++);
    if ($eodModGym    || (float)($rev['gym_gross'] ?? 0) > 0)    { $rowL('Gym',    $m((float)($rev['gym_gross'] ?? 0)),    false, $i++); }
    if ($eodModEvents || (float)($rev['events_gross'] ?? 0) > 0) { $rowL('Events', $m((float)($rev['events_gross'] ?? 0)), false, $i++); }

    // Gross Total gold bar
    $pdf->SetFillColorArray($GOLD);
    $pdf->SetFont('helvetica', 'B', 7.5);
    $pdf->SetTextColorArray($WHITE);
    $pdf->SetXY($LM, $yL);
    $pdf->Cell(58, $ROW, '  Gross Total', 0, 0, 'L', true);
    $pdf->Cell(33, $ROW, $m($gross), 0, 0, 'R', true);
    $yL += $ROW;
    $i = 0;

    if ((float)($rev['refunds'] ?? 0) > 0) {
        $pdf->SetFillColorArray($CREAM);
        $pdf->SetDrawColorArray($DIVIDER);
        $pdf->SetFont('helvetica', '', 7.5);
        $pdf->SetTextColorArray($RED);
        $pdf->SetXY($LM, $yL);
        $pdf->Cell(58, $ROW, '  Less Refunds', 'B', 0, 'L', true);
        $pdf->Cell(33, $ROW, '-' . $m((float)$rev['refunds']), 'B', 0, 'R', true);
        $yL += $ROW;
        $i++;
    }

    // NET REVENUE bold row
    $pdf->SetFillColorArray($CHARCOAL);
    $pdf->SetFont('helvetica', 'B', 7.5);
    $pdf->SetTextColorArray($CREAM);
    $pdf->SetXY($LM, $yL);
    $pdf->Cell(58, $ROW, '  NET REVENUE', 0, 0, 'L', true);
    $pdf->Cell(33, $ROW, $m($net), 0, 0, 'R', true);
    $yL += $ROW;
    $i = 0;

    $rowL('VAT Collected', $m((float)($rev['total_vat'] ?? 0)), false, $i++);
    $rowL('Transactions',  (string)(int)($rev['txn_count'] ?? 0), false, $i++);
    if ((float)($rev['pending'] ?? 0) > 0) {
        $rowL('Pending / Partial', $m((float)$rev['pending']), false, $i++);
    }
    if ($outstanding > 0) {
        $rowL('Outstanding Folio', $m($outstanding), false, $i++);
    }
    if ($payment_capture_rate < 99.5) {
        $rowL('Payment Capture Rate', number_format($payment_capture_rate, 1) . '%', false, $i++);
    }

    $yL += 3;

    // ── LEFT: Front Office (hotel businesses only) ───────────────────────────
    if ($eodModBookings) {
        $secL('Front Office');
        $i = 0;
        $rowL('Arrivals',
            (int)($ops['arrivals_completed'] ?? 0) . ' done / ' . (int)($ops['expected_arrivals'] ?? 0) . ' exp.',
            false, $i++);
        $rowL('Departures',
            (int)($ops['departures_completed'] ?? 0) . ' done / ' . (int)($ops['expected_departures'] ?? 0) . ' exp.',
            false, $i++);
        $rowL('Stay-overs (in-house)', (string)(int)($ops['stayovers'] ?? 0),    false, $i++);
        $rowL('New Bookings Today',   (string)(int)($ops['new_bookings'] ?? 0),  false, $i++);
        $rowL('Cancellations',        (string)(int)($ops['cancellations'] ?? 0), false, $i++);
        $rowL('No-shows',             (string)(int)($ops['no_shows'] ?? 0),      false, $i++);
        $rowL('Unsold Rooms',         $rooms_unsold . ' of ' . $rooms_total,     false, $i++);
        if ($empty_room_opportunity > 0) {
            $rowL('Empty-room Opportunity', $m($empty_room_opportunity), false, $i++);
        }
        $rowL('ADR',    $m($adr),    false, $i++);
        $rowL('RevPAR', $m($revpar), false, $i++);
    }

    // ── RIGHT: POS / F&B ─────────────────────────────────────────────────────
    $secR(isRestaurantEnabled() ? 'POS / F&B' : 'POS', $CHARCOAL);
    $i = 0;
    $rowR('Total Orders',    (string)(int)$pos['orders'], false, $i++);
    $rowR('Gross Revenue',   $m($pos_gross), false, $i++);
    $rowR('Cost of Goods',   $m($pos_cogs),  false, $i++);
    $rowR('Gross Margin',    $m($pos_margin) . ' (' . number_format($pos_margin_pct, 1) . '%)', false, $i++);
    $rowR('Avg Order Value', $m($avg_order),  false, $i++);
    $rowR('Voids',           (int)$pos['voided_count'] . ' — ' . $m((float)$pos['voided_value']), false, $i++);
    if ($pos_change !== 0.0) {
        $vc = $pos_change >= 0 ? $GREEN : $RED;
        $pdf->SetFillColorArray($i % 2 === 0 ? $LIGHT_BG : $CREAM);
        $pdf->SetDrawColorArray($DIVIDER);
        $pdf->SetFont('helvetica', '', 7.5);
        $pdf->SetTextColorArray($TEXT2);
        $pdf->SetXY($CRX, $yR);
        $pdf->Cell(58, $ROW, '  vs Yesterday', 'B', 0, 'L', true);
        $pdf->SetTextColorArray($vc);
        $pdf->SetFont('helvetica', 'B', 7.5);
        $pdf->Cell(33, $ROW, ($pos_change >= 0 ? '+' : '') . $m($pos_change), 'B', 0, 'R', true);
        $yR += $ROW;
        $i++;
    }

    // POS by type breakdown
    if (!empty($pos_by_type)) {
        $yR += 2;
        $pdf->SetFillColorArray($BROWN);
        $pdf->SetTextColorArray($WHITE);
        $pdf->SetFont('helvetica', 'B', 7);
        $pdf->SetXY($CRX, $yR);
        $pdf->Cell($COL, 5.5, '  ORDER TYPE BREAKDOWN', 0, 0, 'L', true);
        $yR += 5.5;
        $typeLabels = [
            'walk_in'      => 'Walk-in / Dine-in',
            'dine_in'      => 'Dine-in',
            'room_service' => 'Room Service',
            'takeaway'     => 'Takeaway',
            'delivery'     => 'Delivery',
            'other'        => 'Other',
        ];
        foreach ($pos_by_type as $pi => $pt) {
            $tl = $typeLabels[$pt['order_type'] ?? ''] ?? ucfirst((string)($pt['order_type'] ?? ''));
            $bg = $pi % 2 === 0 ? $LIGHT_BG : $CREAM;
            $pdf->SetFillColorArray($bg);
            $pdf->SetDrawColorArray($DIVIDER);
            $pdf->SetFont('helvetica', '', 7);
            $pdf->SetTextColorArray($TEXT2);
            $pdf->SetXY($CRX, $yR);
            $pdf->Cell(44, 5, '  ' . $tl, 'B', 0, 'L', true);
            $pdf->SetTextColorArray($CHARCOAL);
            $pdf->Cell(22, 5, (int)($pt['cnt'] ?? 0) . ' orders', 'B', 0, 'C', true);
            $pdf->Cell(25, 5, $m((float)($pt['gross'] ?? 0)), 'B', 0, 'R', true);
            $yR += 5;
        }
    }

    // ── RIGHT: Payment Method Mix ─────────────────────────────────────────────
    $yR += 3;
    $pdf->SetFillColorArray($BROWN);
    $pdf->SetTextColorArray($WHITE);
    $pdf->SetFont('helvetica', 'B', 7);
    $pdf->SetXY($CRX, $yR);
    $pdf->Cell($COL, 5.5, '  PAYMENT METHOD MIX', 0, 0, 'L', true);
    $yR += 5.5;

    foreach ($methods as $mi => $mRow) {
        $mLabel = ucwords(str_replace('_', ' ', (string)($mRow['method'] ?? '')));
        $mTotal = (float)($mRow['total'] ?? 0);
        $mCnt   = (int)($mRow['cnt'] ?? 0);
        $mShare = $gross > 0 ? ($mTotal / $gross) * 100 : 0;
        $bg = $mi % 2 === 0 ? $LIGHT_BG : $CREAM;

        $pdf->SetFillColorArray($bg);
        $pdf->SetDrawColorArray($DIVIDER);
        $pdf->SetFont('helvetica', '', 7);
        $pdf->SetTextColorArray($TEXT2);
        $pdf->SetXY($CRX, $yR);
        $cnt_str = $mCnt > 0 ? '  ' . $mLabel . ' (' . $mCnt . 'x)' : '  ' . $mLabel;
        $pdf->Cell(44, $ROW, $cnt_str, 'B', 0, 'L', true);

        // Progress bar (37mm wide track, gold fill proportional to share)
        $barTrackW = 20.0;
        $barFillW  = min($barTrackW, ($mShare / 100) * $barTrackW);
        $barX = $CRX + 44;
        $barY = $yR + ($ROW / 2) - 1.5;
        $pdf->SetFillColorArray($DIVIDER);
        $pdf->Rect($barX, $barY, $barTrackW, 3, 'F');
        if ($barFillW > 0) {
            $pdf->SetFillColorArray($GOLD);
            $pdf->Rect($barX, $barY, $barFillW, 3, 'F');
        }
        $pdf->SetFillColorArray($bg);
        $pdf->SetDrawColorArray($DIVIDER);
        $pdf->SetXY($barX + $barTrackW, $yR);
        $pdf->SetTextColorArray($TEXT2);
        $pdf->Cell(7, $ROW, number_format($mShare, 0) . '%', 'B', 0, 'C', true);
        $pdf->SetTextColorArray($CHARCOAL);
        $pdf->SetFont('helvetica', '', 7);
        $pdf->Cell(20, $ROW, $m($mTotal), 'B', 0, 'R', true);
        $yR += $ROW;
    }

    // Cash / Non-cash summary rows
    $pdf->SetFillColorArray($CREAM);
    $pdf->SetDrawColorArray($DIVIDER);
    $pdf->SetFont('helvetica', 'B', 7);
    $pdf->SetTextColorArray($BROWN);
    $pdf->SetXY($CRX, $yR);
    $pdf->Cell(58, $ROW, '  Cash to reconcile', 'B', 0, 'L', true);
    $pdf->SetTextColorArray($CHARCOAL);
    $pdf->Cell(33, $ROW, $m($cash_total), 'B', 0, 'R', true);
    $yR += $ROW;

    $pdf->SetTextColorArray($BROWN);
    $pdf->SetXY($CRX, $yR);
    $pdf->Cell(58, $ROW, '  Non-cash collected', 'B', 0, 'L', true);
    $pdf->SetTextColorArray($CHARCOAL);
    $pdf->Cell(33, $ROW, $m($ncash_total), 'B', 0, 'R', true);
    $yR += $ROW;

    // Advance Y past both columns
    $y = max($yL, $yR) + 6;

    // ══════════════════════════════════════════════════════════════════════════
    // FULL-WIDTH SECTION HELPER
    // ══════════════════════════════════════════════════════════════════════════
    $fwSec = function (string $lbl, array $col) use ($pdf, &$y, $LM, $CW, $GOLD, $WHITE, $SH): void {
        $pdf->SetFillColorArray($col);
        $pdf->Rect($LM, $y, $CW, $SH, 'F');
        $pdf->SetFillColorArray($GOLD);
        $pdf->Rect($LM, $y, 3, $SH, 'F');
        $pdf->SetTextColorArray($WHITE);
        $pdf->SetFont('helvetica', 'B', 8);
        $pdf->SetXY($LM + 6, $y + 1.5);
        $pdf->Cell($CW - 6, $SH - 2, strtoupper($lbl), 0, 0, 'L');
        $y += $SH + 1;
    };
    $fwRow = function (string $lbl, string $val, bool $bold = false, int $i = 0) use ($pdf, &$y, $LM, $CW, $LIGHT_BG, $CREAM, $DIVIDER, $TEXT2, $CHARCOAL, $ROW): void {
        $bg = $i % 2 === 0 ? $LIGHT_BG : $CREAM;
        $pdf->SetFillColorArray($bg);
        $pdf->SetDrawColorArray($DIVIDER);
        $pdf->SetFont('helvetica', $bold ? 'B' : '', 7.5);
        $pdf->SetTextColorArray($TEXT2);
        $pdf->SetXY($LM, $y);
        $pdf->Cell(130, $ROW, '  ' . $lbl, 'B', 0, 'L', true);
        $pdf->SetTextColorArray($CHARCOAL);
        $pdf->SetFont('helvetica', $bold ? 'B' : '', 7.5);
        $pdf->Cell(56, $ROW, $val, 'B', 0, 'R', true);
        $y += $ROW;
    };
    $pb = function () use ($pdf, &$y): void {
        if ($y > 235) {
            $pdf->AddPage();
            $y = 5.0;
        }
    };

    // ══════════════════════════════════════════════════════════════════════════
    // CLOSEOUT ALERTS
    // ══════════════════════════════════════════════════════════════════════════
    $activeAlerts = array_filter($closeout_alerts, fn($a) => in_array($a['level'] ?? '', ['warn', 'watch'], true));
    if (!empty($activeAlerts)) {
        $pb();
        $fwSec('Closeout Alerts', $CHARCOAL);
        foreach ($activeAlerts as $al) {
            $lvl  = (string)($al['level'] ?? 'watch');
            $barC = $lvl === 'warn' ? $RED : $AMBER;
            $bgC  = $lvl === 'warn' ? [255, 241, 242] : [255, 251, 235];
            $pdf->SetFillColorArray($bgC);
            $pdf->Rect($LM, $y, $CW, 7, 'F');
            $pdf->SetDrawColorArray([229, 217, 201]);
            $pdf->Rect($LM, $y, $CW, 7, 'D');
            $pdf->SetFillColorArray($barC);
            $pdf->Rect($LM, $y, 3.5, 7, 'F');
            $pdf->SetFont('helvetica', 'B', 7.5);
            $pdf->SetTextColorArray($barC);
            $pdf->SetXY($LM + 6, $y + 1);
            $pdf->Cell(60, 5, strip_tags((string)($al['title'] ?? '')), 0, 0, 'L');
            $pdf->SetFont('helvetica', '', 7.5);
            $pdf->SetTextColorArray($TEXT2);
            $pdf->SetXY($LM + 66, $y + 1);
            $pdf->Cell($CW - 70, 5, strip_tags((string)($al['detail'] ?? '')), 0, 0, 'L');
            $y += 7.5;
        }
        $y += 2;
    }

    // ══════════════════════════════════════════════════════════════════════════
    // TOP SELLING ITEMS
    // ══════════════════════════════════════════════════════════════════════════
    if (!empty($top_items)) {
        $pb();
        $fwSec('Top Selling Items', $CHARCOAL);
        // Table header
        $pdf->SetFillColorArray($CREAM);
        $pdf->SetDrawColorArray($DIVIDER);
        $pdf->SetFont('helvetica', 'B', 7.5);
        $pdf->SetTextColorArray($BROWN);
        $pdf->SetXY($LM, $y);
        $pdf->Cell(96, 5.5, '  Item', 'B', 0, 'L', true);
        $pdf->Cell(32, 5.5, 'Type', 'B', 0, 'C', true);
        $pdf->Cell(20, 5.5, 'Qty', 'B', 0, 'C', true);
        $pdf->Cell(38, 5.5, 'Revenue', 'B', 0, 'R', true);
        $y += 5.5;
        foreach ($top_items as $ti => $it) {
            $bg = $ti % 2 === 0 ? $LIGHT_BG : $CREAM;
            $pdf->SetFillColorArray($bg);
            $pdf->SetDrawColorArray($DIVIDER);
            $pdf->SetFont('helvetica', '', 7.5);
            $pdf->SetTextColorArray($TEXT2);
            $pdf->SetXY($LM, $y);
            $pdf->Cell(96, $ROW, '  ' . (string)($it['item_name'] ?? ''), 'B', 0, 'L', true);
            $pdf->Cell(32, $ROW, (string)($it['menu_type'] ?? ''), 'B', 0, 'C', true);
            $qty = rtrim(rtrim(number_format((float)($it['qty'] ?? 0), 2, '.', ''), '0'), '.');
            $pdf->Cell(20, $ROW, $qty, 'B', 0, 'C', true);
            $pdf->SetFont('helvetica', 'B', 7.5);
            $pdf->SetTextColorArray($CHARCOAL);
            $pdf->Cell(38, $ROW, $m((float)($it['revenue'] ?? 0)), 'B', 0, 'R', true);
            $y += $ROW;
        }
        $y += 3;
    }

    // ══════════════════════════════════════════════════════════════════════════
    // POS VOID REASONS
    // ══════════════════════════════════════════════════════════════════════════
    if (!empty($void_reasons)) {
        $pb();
        $fwSec('POS Void Reasons', $RED);
        foreach ($void_reasons as $vi => $vr) {
            $bg = $vi % 2 === 0 ? $LIGHT_BG : $CREAM;
            $pdf->SetFillColorArray($bg);
            $pdf->SetDrawColorArray($DIVIDER);
            $pdf->SetFont('helvetica', '', 7.5);
            $pdf->SetTextColorArray($TEXT2);
            $pdf->SetXY($LM, $y);
            $pdf->Cell(120, $ROW, '  ' . (string)($vr['reason'] ?? ''), 'B', 0, 'L', true);
            $pdf->SetTextColorArray($CHARCOAL);
            $pdf->Cell(30, $ROW, (int)($vr['cnt'] ?? 0) . ' ×', 'B', 0, 'C', true);
            $pdf->Cell(36, $ROW, '— ' . $m((float)($vr['value'] ?? 0)), 'B', 0, 'R', true);
            $y += $ROW;
        }
        $y += 3;
    }

    // ══════════════════════════════════════════════════════════════════════════
    // ROOM TYPE REVENUE
    // ══════════════════════════════════════════════════════════════════════════
    if (!empty($room_type_perf)) {
        $pb();
        $fwSec('Room Type Revenue', $BROWN);
        $maxRev = max(array_column($room_type_perf, 'revenue') ?: [1]);
        foreach ($room_type_perf as $ri => $rt) {
            $bg = $ri % 2 === 0 ? $LIGHT_BG : $CREAM;
            $rtRev = (float)($rt['revenue'] ?? 0);
            $pdf->SetFillColorArray($bg);
            $pdf->SetDrawColorArray($DIVIDER);
            $pdf->SetFont('helvetica', '', 7.5);
            $pdf->SetTextColorArray($TEXT2);
            $pdf->SetXY($LM, $y);
            $pdf->Cell(80, $ROW, '  ' . (string)($rt['room_type'] ?? ''), 'B', 0, 'L', true);
            $pdf->Cell(25, $ROW, (int)($rt['bookings'] ?? 0) . ' booking(s)', 'B', 0, 'C', true);
            $pdf->SetTextColorArray($CHARCOAL);
            $pdf->SetFont('helvetica', 'B', 7.5);
            $pdf->Cell(35, $ROW, $m($rtRev), 'B', 0, 'R', true);
            // Horizontal revenue bar
            $barTrack = 40.0;
            $barFill  = $maxRev > 0 ? ($rtRev / $maxRev) * $barTrack : 0;
            $bx = $LM + 140 + 2;
            $by = $y + $ROW / 2 - 1.5;
            $pdf->SetFillColorArray($DIVIDER);
            $pdf->Rect($bx, $by, $barTrack, 3, 'F');
            if ($barFill > 0) {
                $pdf->SetFillColorArray($GOLD);
                $pdf->Rect($bx, $by, $barFill, 3, 'F');
            }
            $pdf->SetFillColorArray($bg);
            $pdf->SetDrawColorArray($DIVIDER);
            $pdf->SetXY($bx + $barTrack, $y);
            $pdf->Cell($CW - 140 - 2 - $barTrack, $ROW, '', 'B', 0, 'L', true);
            $y += $ROW;
        }
        $y += 3;
    }

    // ══════════════════════════════════════════════════════════════════════════
    // GUEST INTELLIGENCE
    // ══════════════════════════════════════════════════════════════════════════
    $gi_total = (int)($guest_intel['new_guests'] ?? 0) + (int)($guest_intel['returning_guests'] ?? 0);
    if ($gi_total > 0) {
        $pb();
        $fwSec('Guest Intelligence', $BROWN);
        $i = 0;
        $fwRow('New Guests Today',      (string)(int)($guest_intel['new_guests'] ?? 0),      false, $i++);
        $fwRow('Returning Guests',      (string)(int)($guest_intel['returning_guests'] ?? 0), false, $i++);
        $fwRow('Repeat Rate',           number_format($returning_rate, 1) . '%',              false, $i++);
        if ((int)($guest_intel['avg_lead_days'] ?? 0) > 0) {
            $fwRow('Avg Lead Time',     (int)$guest_intel['avg_lead_days'] . ' days',         false, $i++);
        }
        $y += 3;
    }

    // ══════════════════════════════════════════════════════════════════════════
    // HOUSEKEEPING & GUEST REVIEWS (module-gated)
    // ══════════════════════════════════════════════════════════════════════════
    $eodModHousekeeping = !function_exists('moduleEnabled') || moduleEnabled('housekeeping');
    if ($eodModHousekeeping || (int)($reviewRow['cnt'] ?? 0) > 0) {
        $pb();
        $fwSec($eodModHousekeeping ? 'Housekeeping & Guest Reviews' : 'Customer Reviews', $BROWN);
        $i = 0;
        if ($eodModHousekeeping) {
            $fwRow('HK Pending',         (string)(int)($hk['pending']     ?? 0), false, $i++);
            $fwRow('HK In Progress',     (string)(int)($hk['in_progress'] ?? 0), false, $i++);
            $fwRow('HK Completed Today', (string)(int)($hk['completed']   ?? 0), false, $i++);
        }
        if ((int)($reviewRow['cnt'] ?? 0) > 0) {
            $fwRow('Reviews Received',
                (int)$reviewRow['cnt'] . ' — avg ' . number_format((float)($reviewRow['avg_rating'] ?? 0), 1) . '/5',
                false, $i++);
        }
        $y += 3;
    }

    // ══════════════════════════════════════════════════════════════════════════
    // OPEN MAINTENANCE TASKS (room maintenance — hotel businesses only)
    // ══════════════════════════════════════════════════════════════════════════
    if ($eodModBookings && (int)($maintenance['total_open'] ?? 0) > 0) {
        $pb();
        $fwSec('Open Maintenance Tasks', $AMBER);
        $i = 0;
        if ((int)($maintenance['urgent'] ?? 0) > 0) {
            $pdf->SetFillColorArray([255, 241, 242]);
            $pdf->SetDrawColorArray($DIVIDER);
            $pdf->SetFont('helvetica', 'B', 7.5);
            $pdf->SetTextColorArray($RED);
            $pdf->SetXY($LM, $y);
            $pdf->Cell(130, $ROW, '  Urgent', 'B', 0, 'L', true);
            $pdf->Cell(56, $ROW, (string)(int)$maintenance['urgent'], 'B', 0, 'R', true);
            $y += $ROW;
            $i++;
        }
        if ((int)($maintenance['high'] ?? 0) > 0) {
            $bg = $i % 2 === 0 ? $LIGHT_BG : $CREAM;
            $pdf->SetFillColorArray($bg);
            $pdf->SetDrawColorArray($DIVIDER);
            $pdf->SetFont('helvetica', '', 7.5);
            $pdf->SetTextColorArray($AMBER);
            $pdf->SetXY($LM, $y);
            $pdf->Cell(130, $ROW, '  High Priority', 'B', 0, 'L', true);
            $pdf->SetTextColorArray($CHARCOAL);
            $pdf->Cell(56, $ROW, (string)(int)$maintenance['high'], 'B', 0, 'R', true);
            $y += $ROW;
            $i++;
        }
        if ((int)($maintenance['medium'] ?? 0) > 0) {
            $fwRow('Medium Priority', (string)(int)$maintenance['medium'], false, $i++);
        }
        $fwRow('Total Open Tasks', (string)(int)$maintenance['total_open'], true, $i);
        $y += 3;
    }

    // ══════════════════════════════════════════════════════════════════════════
    // QUOTATION PIPELINE (billing businesses only — matches the on-screen gate)
    // ══════════════════════════════════════════════════════════════════════════
    $eodBilling = !function_exists('rh_module_key_enabled') || rh_module_key_enabled('billing');
    if ($eodBilling && ((int)($quotation_stats['sent_today'] ?? 0) > 0 || (int)($quotation_stats['total_active'] ?? 0) > 0)) {
        $pb();
        $fwSec('Quotation Pipeline', $CHARCOAL);
        $i = 0;
        $fwRow('Quotes Sent Today',    (string)(int)($quotation_stats['sent_today']     ?? 0), false, $i++);
        $fwRow('Accepted Today',       (string)(int)($quotation_stats['accepted_today'] ?? 0), false, $i++);
        $fwRow('Active Open Quotes',   (string)(int)($quotation_stats['total_active']   ?? 0), false, $i++);
        $fwRow('Pipeline Value',       $m((float)($quotation_stats['pipeline_value']    ?? 0)), true,  $i++);
        $y += 3;
    }

    // ══════════════════════════════════════════════════════════════════════════
    // TOMORROW PREVIEW BAND (arrivals/departures — hotel businesses only)
    // ══════════════════════════════════════════════════════════════════════════
    if ($eodModBookings) {
    $pb();
    $TBH = 22.0;
    // Gold top strip
    $pdf->SetFillColorArray($GOLD);
    $pdf->Rect($LM, $y, $CW, 1.5, 'F');
    // Charcoal fill
    $pdf->SetFillColorArray($CHARCOAL);
    $pdf->Rect($LM, $y + 1.5, $CW, $TBH - 3, 'F');
    // Gold bottom strip
    $pdf->SetFillColorArray($GOLD);
    $pdf->Rect($LM, $y + $TBH - 1.5, $CW, 1.5, 'F');

    // Header label
    $tomLabel = 'TOMORROW — ' . strtoupper(date('l, F j', strtotime($tomorrow)));
    $pdf->SetTextColorArray($GOLD);
    $pdf->SetFont('helvetica', 'B', 8);
    $pdf->SetXY($LM, $y + 2.5);
    $pdf->Cell($CW, 5, $tomLabel, 0, 0, 'C');

    // Three columns
    $colTW = $CW / 3;
    $tvY   = $y + 8;

    $tomCols = [
        ['label' => 'ARRIVALS',         'val' => (string)(int)($tom['arrivals']   ?? 0), 'gold' => false],
        ['label' => 'DEPARTURES',       'val' => (string)(int)($tom['departures'] ?? 0), 'gold' => false],
        ['label' => 'REVENUE FORECAST', 'val' => $m((float)($tom['rev_forecast'] ?? 0)), 'gold' => true],
    ];

    foreach ($tomCols as $ci => $tc) {
        $tx = $LM + $ci * $colTW;
        // Vertical divider (skip first)
        if ($ci > 0) {
            $pdf->SetDrawColorArray([61, 55, 51]);
            $pdf->Line($tx, $tvY - 1, $tx, $tvY + 14);
        }
        $pdf->SetFont('helvetica', '', 6.5);
        $pdf->SetTextColorArray([168, 150, 131]);
        $pdf->SetXY($tx, $tvY);
        $pdf->Cell($colTW, 5, $tc['label'], 0, 0, 'C');

        $pdf->SetFont('helvetica', 'B', 14);
        $pdf->SetTextColorArray($tc['gold'] ? $GOLD : $WHITE);
        $pdf->SetXY($tx, $tvY + 5);
        $pdf->Cell($colTW, 9, $tc['val'], 0, 0, 'C');
    }

    $y += $TBH + 4;
    } // end tomorrow band (bookings)

    // ── Output ─────────────────────────────────────────────────────────────────
    return $pdf->Output('eod-report-' . $date . '.pdf', 'S');
}
