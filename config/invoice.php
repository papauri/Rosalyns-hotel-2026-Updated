<?php

/**
 * Invoice Generation and Email System
 * Generates professional PDF invoices for booking payments
 */

require_once __DIR__ . '/database.php';
require_once __DIR__ . '/email.php';
require_once __DIR__ . '/../includes/finance-sequences.php';

if (!function_exists('rh_invoice_signed_payments')) {
    /**
     * Invoice payment history rows: collected payments as positives and settled refunds
     * (completed / processing) as negatives, so the list sums to the Amount Paid line.
     */
    function rh_invoice_signed_payments(array $rows): array
    {
        foreach ($rows as &$r) {
            if ((string)($r['payment_type'] ?? '') === 'refund') {
                $r['total_amount'] = -abs((float)$r['total_amount']);
                $r['payment_method'] = 'refund_' . (string)($r['payment_method'] ?? '');
            }
        }
        unset($r);
        return $rows;
    }
}

// Import PHPMailer classes
use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;

// Try to load TCPDF if available
$tcpdf_loaded = false;
if (file_exists(__DIR__ . '/../vendor/autoload.php')) {
    // Try Composer autoload
    $autoload = include __DIR__ . '/../vendor/autoload.php';
    if (class_exists('TCPDF')) {
        // Check if constants are defined (they might not be with autoload only)
        if (!defined('PDF_PAGE_ORIENTATION') || !defined('PDF_UNIT') || !defined('PDF_PAGE_FORMAT')) {
            // Try to include tcpdf.php directly to get constants
            if (file_exists(__DIR__ . '/../vendor/tecnickcom/tcpdf/tcpdf.php')) {
                require_once __DIR__ . '/../vendor/tecnickcom/tcpdf/tcpdf.php';
            }
        }
        // Verify constants are now defined
        if (defined('PDF_PAGE_ORIENTATION') && defined('PDF_UNIT') && defined('PDF_PAGE_FORMAT')) {
            $tcpdf_loaded = true;
        }
    }
} elseif (file_exists(__DIR__ . '/../TCPDF/tcpdf.php')) {
    // Try direct TCPDF include
    require_once __DIR__ . '/../TCPDF/tcpdf.php';
    if (class_exists('TCPDF') && defined('PDF_PAGE_ORIENTATION') && defined('PDF_UNIT') && defined('PDF_PAGE_FORMAT')) {
        $tcpdf_loaded = true;
    }
}

// Define fallback constants if TCPDF is not loaded or constants are missing
if (!defined('PDF_PAGE_ORIENTATION')) {
    define('PDF_PAGE_ORIENTATION', 'P');
}
if (!defined('PDF_UNIT')) {
    define('PDF_UNIT', 'mm');
}
if (!defined('PDF_PAGE_FORMAT')) {
    define('PDF_PAGE_FORMAT', 'A4');
}

/**
 * Generate PDF invoice for a booking
 *
 * @param int $booking_id Booking ID
 * @param string|null $invoice_number_override Optional invoice number to reuse when regenerating an existing invoice
 * @return array|false Returns array with keys: filename, path, relative_path, invoice_number — or false on failure
 */
function generateInvoicePDF(int $booking_id, ?string $invoice_number_override = null)
{
    global $pdo, $tcpdf_loaded;

    try {
        // Get the requested booking
        $stmt = $pdo->prepare("
            SELECT b.*, r.name as room_name, r.image_url,
                   s.setting_value as site_name
            FROM bookings b
            JOIN rooms r ON b.room_id = r.id
            JOIN site_settings s ON s.setting_key = 'site_name'
            WHERE b.id = ?
        ");
        $stmt->execute([$booking_id]);
        $booking = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$booking) {
            throw new Exception("Booking not found");
        }

        if (function_exists('getBookingRoomLabel')) {
            $room_assignment = getBookingRoomLabel((int)$booking['id']);
            if ($room_assignment !== '') {
                $booking['room_name'] = trim((string)$booking['room_name'] . ' - ' . $room_assignment);
            }
        }

        // For group bookings, always work from the primary booking so the invoice
        // shows all rooms.  If the requested booking is a secondary one, redirect
        // the invoice to its primary.
        $is_secondary = !empty($booking['primary_booking_id']);
        if ($is_secondary) {
            $primary_id = (int)$booking['primary_booking_id'];
            $stmt->execute([$primary_id]);
            $primary = $stmt->fetch(PDO::FETCH_ASSOC);
            if ($primary) {
                $booking    = $primary;
                $booking_id = $primary_id;
                if (function_exists('getBookingRoomLabel')) {
                    $room_assignment = getBookingRoomLabel((int)$booking['id']);
                    if ($room_assignment !== '') {
                        $booking['room_name'] = trim((string)$booking['room_name'] . ' - ' . $room_assignment);
                    }
                }
            }
        }

        // Fetch all sibling bookings for multi-room groups (secondaries that share
        // this primary booking id, including the primary itself).
        $group_bookings = [];
        $siblings_stmt  = $pdo->prepare("
            SELECT b.id, b.booking_reference, b.total_amount, b.child_supplement_total,
                   b.tourism_levy_amount, b.vat_amount, b.total_with_vat,
                   b.occupancy_type, b.number_of_nights,
                   r.name as room_name
            FROM bookings b
            JOIN rooms r ON b.room_id = r.id
            WHERE (b.id = ? OR b.primary_booking_id = ?)
            ORDER BY b.id ASC
        ");
        $siblings_stmt->execute([$booking_id, $booking_id]);
        $group_bookings = $siblings_stmt->fetchAll(PDO::FETCH_ASSOC);
        if (function_exists('getBookingRoomLabel')) {
            foreach ($group_bookings as &$group_booking) {
                $room_assignment = getBookingRoomLabel((int)$group_booking['id']);
                if ($room_assignment !== '') {
                    $group_booking['room_name'] = trim((string)$group_booking['room_name'] . ' - ' . $room_assignment);
                }
            }
            unset($group_booking);
        }
        // If only one result it's a normal single-room booking — pass empty array
        if (count($group_bookings) <= 1) {
            $group_bookings = [];
        }

        // Get hotel contact details
        $site_name = getSetting('site_name');
        $email_address = getSetting('email_from_email');
        $phone_number = getSetting('phone_main');
        $address = getSetting('address_line1') . ', ' .
            getSetting('address_line2') . ', ' .
            getSetting('address_country');
        $currency_symbol = getSetting('currency_symbol');

        // Create invoice directory if it doesn't exist
        $invoiceDir = __DIR__ . '/../invoices';
        if (!file_exists($invoiceDir)) {
            mkdir($invoiceDir, 0755, true);
        }

        // Generate unique invoice filename - use sequence unless an existing invoice number is provided
        $invoice_number_override = trim((string)$invoice_number_override);
        if ($invoice_number_override !== '') {
            $invoice_number = $invoice_number_override;
        } else {
            $invoice_prefix = getSetting('invoice_prefix', 'INV');
            $invoice_start = (int)getSetting('invoice_start_number', 1000);
            $invoice_number = finance_next_invoice_number($pdo, $invoice_prefix, $invoice_start, date('Y-m-d'), 'room');
        }

        $safeInvoiceNumber = preg_replace('/[^A-Za-z0-9_-]+/', '-', $invoice_number);
        if ($safeInvoiceNumber === null || $safeInvoiceNumber === '') {
            $safeInvoiceNumber = 'INV-' . date('YmdHis');
        }

        $filename = $safeInvoiceNumber . '.pdf';
        $filepath = $invoiceDir . '/' . $filename;

        if ($tcpdf_loaded) {
            // Build HTML content
            $html = buildInvoiceHTML($booking, $invoice_number, $site_name, $email_address, $phone_number, $address, $currency_symbol, $group_bookings);

            // Use shared renderer so preview and generated PDF follow the same style rules.
            if (function_exists('bookingRenderPdfFromHtml')) {
                file_put_contents($filepath, bookingRenderPdfFromHtml($html, 'Invoice ' . $invoice_number));
            } else {
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
                $pdf->SetCreator($site_name);
                $pdf->SetAuthor($site_name);
                $pdf->SetTitle('Invoice ' . $invoice_number);
                $pdf->SetSubject('Payment Invoice');
                $pdf->setPrintHeader(false);
                $pdf->setPrintFooter(false);
                $pdf->SetMargins(8, 8, 8);
                $pdf->SetAutoPageBreak(true, 10);
                $pdf->SetFont('helvetica', '', 10);
                $pdf->AddPage();
                $pdf->writeHTML($html, true, false, true, false, '');
                $pdf->Output($filepath, 'F');
            }
        } else {
            // Fallback: Generate HTML invoice and save as file
            $html = buildInvoiceHTML($booking, $invoice_number, $site_name, $email_address, $phone_number, $address, $currency_symbol, $group_bookings);

            // Wrap in complete HTML document
            $fullHtml = '<!DOCTYPE html>
<html>
<head>
    <meta charset="UTF-8">
    <title>Invoice ' . $invoice_number . '</title>
    <style>
        body { font-family: Arial, sans-serif; margin: 0; padding: 20px; }
        .invoice-container { max-width: 800px; margin: 0 auto; border: 1px solid #ddd; }
        .invoice-header { background: linear-gradient(135deg, #1A1A1A 0%, #2A2A2A 100%); color: white; padding: 30px; }
        .invoice-header h1 { margin: 0; color: #8B7355; }
        .invoice-body { padding: 30px; }
        .invoice-details { margin-bottom: 30px; }
        .invoice-row { display: flex; justify-content: space-between; padding: 10px 0; border-bottom: 1px solid #eee; }
        .invoice-label { font-weight: bold; color: #333; }
        .invoice-value { color: #666; }
        .total-section { background: #f8f9fa; padding: 20px; border-radius: 5px; margin-top: 20px; }
        .total-row { display: flex; justify-content: space-between; font-size: 18px; font-weight: bold; color: #8B7355; }
        .footer { text-align: center; padding: 20px; background: #f8f9fa; border-top: 1px solid #ddd; }
    </style>
</head>
<body>' . $html . '</body></html>';

            // Save as HTML (can be opened in browser and printed as PDF)
            $htmlFilepath = str_replace('.pdf', '.html', $filepath);
            file_put_contents($htmlFilepath, $fullHtml);

            // Return array with both paths and invoice number
            return [
                'filepath' => $htmlFilepath,
                'invoice_number' => $invoice_number,
                'relative_path' => 'invoices/' . basename($htmlFilepath)
            ];
        }

        // Return array with both paths and invoice number
        return [
            'filepath' => $filepath,
            'invoice_number' => $invoice_number,
            'relative_path' => 'invoices/' . $filename
        ];
    } catch (Exception $e) {
        error_log("Generate Invoice PDF Error: " . $e->getMessage());
        return false;
    }
}

if (!function_exists('getBookingDocumentTemplateUpdatedTimestamp')) {
    function getBookingDocumentTemplateUpdatedTimestamp(string $templateKey): ?int
    {
        global $pdo;

        if (!function_exists('bookingEmailTemplatesTableExists') || !bookingEmailTemplatesTableExists()) {
            return null;
        }

        try {
            $stmt = $pdo->prepare('SELECT updated_at FROM booking_email_templates WHERE template_key = ? LIMIT 1');
            $stmt->execute([$templateKey]);
            $updatedAt = (string)($stmt->fetchColumn() ?: '');
            if ($updatedAt === '') {
                return null;
            }

            $timestamp = strtotime($updatedAt);
            return $timestamp === false ? null : $timestamp;
        } catch (Throwable $e) {
            error_log('Failed to fetch booking document template updated time for ' . $templateKey . ': ' . $e->getMessage());
            return null;
        }
    }
}

/**
 * Get hotel logo URL for invoices
 * IMPORTANT: PDF generators and emails require absolute URLs for images
 */
function getInvoiceLogoUrl()
{
    $site_url = trim((string)getSetting('site_url', ''));
    $candidates = [
        (string)getSetting('site_logo', ''),
        (string)getSetting('logo_url', ''),
        (string)getSetting('hotel_logo', ''),
        'images/logo/logo.png',
    ];

    $selected = '';
    foreach ($candidates as $candidate) {
        $candidate = trim($candidate);
        if ($candidate === '') {
            continue;
        }

        if (preg_match('#^https?://#i', $candidate)) {
            return $candidate;
        }

        $relative = ltrim($candidate, '/');
        $localPath = __DIR__ . '/../' . $relative;
        if (is_file($localPath)) {
            $selected = $relative;
            break;
        }

        if ($selected === '') {
            $selected = $relative;
        }
    }

    if ($selected === '') {
        return '';
    }

    $base_url = $site_url !== '' ? $site_url : (defined('BASE_URL') ? (string)BASE_URL : '');
    if ($base_url === '') {
        return $selected;
    }

    return rtrim($base_url, '/') . '/' . ltrim($selected, '/');
}

/* ═══════════════════════════════════════════════════════════════════
 *  Document kit helpers for invoices and quotations
 *  (layout lives in config/document-theme.php; these only assemble it)
 * ═══════════════════════════════════════════════════════════════════ */

if (!function_exists('rh_inv_custom_template')) {
    /**
     * Return the admin-customised HTML for a DB document template, or '' when the
     * stored copy is just the seeded stock layout (then the kit layout is used).
     *
     * invoice_document_template_mode setting: 'kit' = never use DB templates,
     * 'custom' = always use the stored template, anything else = auto-detect
     * (a stored copy with the same wording as the built-in default counts as stock).
     */
    function rh_inv_custom_template(string $templateKey, string $stockHtml = ''): string
    {
        static $cache = [];
        if (isset($cache[$templateKey])) {
            return $cache[$templateKey];
        }
        $cache[$templateKey] = '';
        $mode = strtolower(trim((string)getSetting('invoice_document_template_mode', '')));
        if ($mode === 'kit' || !function_exists('getBookingEmailTemplateConfig')) {
            return '';
        }
        $tpl = getBookingEmailTemplateConfig($templateKey, []);
        $html = (string)($tpl['html_body'] ?? '');
        if ($html === '' || (int)($tpl['is_active'] ?? 1) !== 1) {
            return '';
        }
        if ($mode !== 'custom' && $stockHtml !== '') {
            // Same wording as the code default (only old styling differs) = stock copy, not a customisation.
            if (rh_pdf_template_is_stock($html, $stockHtml)) {
                return '';
            }
        }
        return $cache[$templateKey] = $html;
    }
}

if (!function_exists('rh_inv_keep_together')) {
    /** Ask TCPDF not to split a table (e.g. totals) across two pages. */
    function rh_inv_keep_together(string $tableHtml): string
    {
        return preg_replace('/^<table /', '<table nobr="true" ', $tableHtml, 1) ?? $tableHtml;
    }
}

if (!function_exists('rh_inv_sub')) {
    /** Small muted second line under an item description (HTML, escaped). */
    function rh_inv_sub(string $text): string
    {
        if (trim($text) === '') {
            return '';
        }
        return '<br/><span style="color:' . hotel_brand_tokens()['colors']['muted_dark'] . ';font-size:7.5pt;">' . rh_pdf_e($text) . '</span>';
    }
}

if (!function_exists('rh_inv_payments_html')) {
    /**
     * Payment history block. $payments must already be signed (refunds negative, method prefixed
     * "refund_") - see rh_invoice_signed_payments().
     */
    function rh_inv_payments_html(array $payments, string $cur): string
    {
        if (!$payments) {
            return '';
        }
        $rows = [];
        foreach ($payments as $p) {
            $ts = strtotime((string)($p['payment_date'] ?? ''));
            $method = ucwords(str_replace('_', ' ', (string)($p['payment_method'] ?? '')));
            $ref = trim((string)($p['payment_reference'] ?? ''));
            $rows[] = [
                rh_pdf_e($ts ? date('d M Y', $ts) : ''),
                rh_pdf_e($method) . rh_inv_sub($ref),
                rh_pdf_e(rh_pdf_money((float)($p['total_amount'] ?? 0), $cur)),
            ];
        }
        return rh_pdf_spacer(5) . rh_pdf_section_title('Payment history') . rh_pdf_spacer(1)
            . rh_pdf_items_table([
                ['label' => 'Date', 'width' => 24, 'align' => 'left'],
                ['label' => 'Method', 'width' => 52, 'align' => 'left'],
                ['label' => 'Amount', 'width' => 24, 'align' => 'right'],
            ], $rows);
    }
}

if (!function_exists('rh_inv_bank_terms_html')) {
    /** Bank details + payment terms (from settings) and the shared document terms. */
    function rh_inv_bank_terms_html(string $siteName, string $email, string $phone, bool $owing): string
    {
        $out = '';
        if ($owing) {
            $bank = rh_pdf_meta_table([
                ['Bank', trim((string)getSetting('bank_name', ''))],
                ['Account name', trim((string)getSetting('bank_account_name', ''))],
                ['Account no.', trim((string)getSetting('bank_account_number', ''))],
                ['Branch', trim((string)getSetting('bank_branch', ''))],
            ]);
            if ($bank !== '') {
                $out .= rh_pdf_spacer(5) . rh_pdf_section_title('Bank details') . rh_pdf_spacer(1) . $bank;
            }
            $terms = trim((string)getSetting('invoice_terms', getSetting('payment_terms', '')));
            if ($terms !== '') {
                $terms = strtr($terms, ['{{contact_email}}' => $email, '{{contact_phone}}' => $phone, '{{site_name}}' => $siteName]);
                $out .= rh_pdf_spacer(5) . rh_pdf_section_title('Payment terms') . rh_pdf_spacer(1) . rh_pdf_note($terms);
            }
        }
        $shared = hotel_brand_tokens()['terms_text'];
        if ($shared !== '') {
            $out .= rh_pdf_spacer(4) . rh_pdf_note($shared);
        }
        return $out;
    }
}

if (!function_exists('rh_inv_closing_html')) {
    /** Centered serif closing line (invoice_footer setting overrides the per-document default). */
    function rh_inv_closing_html(string $default): string
    {
        $t = hotel_brand_tokens()['colors'];
        $text = trim((string)getSetting('invoice_footer', ''));
        if ($text === '') {
            $text = $default;
        }
        return rh_pdf_spacer(6) . '<table width="100%" cellpadding="4" cellspacing="0" border="0"><tr><td align="center" style="font-family:times;font-size:11pt;color:' . $t['muted_dark'] . ';">'
            . rh_pdf_e($text) . '</td></tr></table>';
    }
}

if (!function_exists('rh_inv_flat_invoice')) {
    /**
     * Single-line invoice used by conference / gym / event invoices.
     *
     * @param array $a title, invoice_number, issued, meta (rows), line_desc_html, qty, subtotal, vat_enabled,
     *                 vat_amount, vat_rate, total, amount_paid, amount_due, deposit (['required'=>bool,'amount'=>,'paid'=>]),
     *                 payments (signed), closing, site_name, email, phone, currency
     */
    function rh_inv_flat_invoice(array $a): string
    {
        $cur = (string)($a['currency'] ?? '');
        if ($cur === '') {
            $cur = hotel_brand_tokens()['hotel']['currency'];
        }
        $money = static function ($v) use ($cur): string {
            return rh_pdf_money((float)$v, $cur);
        };
        $subtotal = (float)$a['subtotal'];
        $total = (float)$a['total'];
        $paid = (float)$a['amount_paid'];
        $due = (float)$a['amount_due'];
        $owing = $due > BALANCE_TOLERANCE;
        $vatEnabled = !empty($a['vat_enabled']);
        $vatAmount = (float)$a['vat_amount'];
        $vatRate = (float)$a['vat_rate'];
        $mode = function_exists('vat_mode') ? vat_mode() : 'exclusive';

        $items = rh_pdf_items_table([
            ['label' => 'Description', 'width' => 46, 'align' => 'left'],
            ['label' => 'Qty', 'width' => 10, 'align' => 'right'],
            ['label' => 'Unit', 'width' => 21, 'align' => 'right'],
            ['label' => 'Amount', 'width' => 23, 'align' => 'right'],
        ], [[(string)$a['line_desc_html'], rh_pdf_e((string)($a['qty'] ?? 1)), rh_pdf_e($money($subtotal)), rh_pdf_e($money($subtotal))]]);

        $totals = [];
        $dep = $a['deposit'] ?? [];
        if (!empty($dep['required'])) {
            $totals[] = ['label' => 'Deposit required', 'value' => $money($dep['amount'] ?? 0), 'type' => 'muted'];
            $totals[] = [
                'label' => 'Deposit paid',
                'value' => $money($dep['paid'] ?? 0),
                'type' => ((float)($dep['paid'] ?? 0) >= (float)($dep['amount'] ?? 0)) ? 'success' : 'danger',
            ];
        }
        if ($mode === 'inclusive') {
            foreach (rh_pdf_vat_rows($vatAmount, $vatRate, $cur) as $row) {
                $totals[] = $row;
            }
        } elseif ($vatEnabled && $vatAmount > 0) {
            $rateLabel = rtrim(rtrim(number_format($vatRate, 2), '0'), '.');
            $totals[] = ['label' => 'Subtotal (excl. VAT)', 'value' => $money($subtotal)];
            $totals[] = ['label' => 'VAT (' . $rateLabel . '%)', 'value' => $money($vatAmount), 'type' => 'vat'];
        }
        $totalLabel = $vatEnabled ? 'Total (incl. VAT)' : 'Total';
        if ($owing) {
            $totals[] = ['label' => $totalLabel, 'value' => $money($total), 'type' => 'strong'];
            $totals[] = ['label' => 'Amount paid', 'value' => $money($paid), 'type' => 'paid'];
            $totals[] = ['label' => 'Balance due', 'value' => $money($due), 'type' => 'total'];
        } else {
            $totals[] = ['label' => $totalLabel, 'value' => $money($total), 'type' => 'total'];
            $totals[] = ['label' => 'Amount paid', 'value' => $money($paid), 'type' => 'paid'];
        }

        $body = $items . rh_pdf_spacer(3) . rh_inv_keep_together(rh_pdf_totals_table($totals))
            . rh_inv_payments_html((array)($a['payments'] ?? []), $cur)
            . rh_inv_bank_terms_html((string)($a['site_name'] ?? ''), (string)($a['email'] ?? ''), (string)($a['phone'] ?? ''), $owing)
            . rh_inv_closing_html((string)($a['closing'] ?? 'Thank you for your payment.'));

        $meta = array_merge([
            ['Invoice No.', (string)$a['invoice_number']],
            ['Issued', (string)($a['issued'] ?? date('d M Y'))],
        ], (array)$a['meta']);

        return rh_pdf_document_shell((string)$a['title'], $meta, $body, [
            'banner' => ['text' => $owing ? 'Balance due' : 'Paid in full', 'tone' => $owing ? 'danger' : 'neutral'],
        ]);
    }
}

if (!function_exists('rh_inv_quotation_html')) {
    /**
     * Quotation document on the kit.
     *
     * @param array $a title, quote_ref, valid_until (DateTime), meta (rows), line_desc_html, qty, base, vat_amount,
     *                 vat_rate, total, deposit_amount, payment_policy, notes
     */
    function rh_inv_quotation_html(array $a): string
    {
        $cur = hotel_brand_tokens()['hotel']['currency'];
        $money = static function ($v) use ($cur): string {
            return rh_pdf_money((float)$v, $cur);
        };
        $base = (float)$a['base'];
        $vatAmount = (float)$a['vat_amount'];
        $mode = function_exists('vat_mode') ? vat_mode() : 'exclusive';
        $validText = $a['valid_until']->format('d M Y');

        $items = rh_pdf_items_table([
            ['label' => 'Description', 'width' => 46, 'align' => 'left'],
            ['label' => 'Qty', 'width' => 10, 'align' => 'right'],
            ['label' => 'Unit', 'width' => 21, 'align' => 'right'],
            ['label' => 'Amount', 'width' => 23, 'align' => 'right'],
        ], [[(string)$a['line_desc_html'], rh_pdf_e((string)($a['qty'] ?? 1)), rh_pdf_e($money($base)), rh_pdf_e($money($base))]]);

        $totals = [];
        if ($mode === 'exclusive' && $vatAmount > 0) {
            $totals[] = ['label' => 'Subtotal (excl. VAT)', 'value' => $money($base)];
        }
        foreach (rh_pdf_vat_rows($vatAmount, (float)$a['vat_rate'], $cur) as $row) {
            $totals[] = $row;
        }
        if ((float)$a['deposit_amount'] > 0) {
            $totals[] = ['label' => 'Deposit required', 'value' => $money($a['deposit_amount']), 'type' => 'muted'];
        }
        $totals[] = ['label' => 'Total quotation', 'value' => $money($a['total']), 'type' => 'total'];

        $body = $items . rh_pdf_spacer(3) . rh_inv_keep_together(rh_pdf_totals_table($totals));
        $policy = trim((string)$a['payment_policy']);
        if ($policy !== '') {
            $body .= rh_pdf_spacer(5) . rh_pdf_section_title('Payment terms') . rh_pdf_spacer(1) . rh_pdf_note($policy);
        }
        $notes = trim((string)$a['notes']);
        if ($notes !== '') {
            $body .= rh_pdf_spacer(4) . rh_pdf_section_title('Notes') . rh_pdf_spacer(1) . rh_pdf_note($notes);
        }
        $shared = hotel_brand_tokens()['terms_text'];
        if ($shared !== '') {
            $body .= rh_pdf_spacer(4) . rh_pdf_note($shared);
        }
        $validity = trim((string)getSetting('quotation_footer_text', ''));
        if ($validity === '') {
            $validity = 'This quotation is valid until {{valid_until}}. Availability and rates are subject to confirmation at acceptance.';
        }
        $validity = str_replace('{{valid_until}}', $validText, $validity);
        $body .= rh_pdf_spacer(6) . '<table width="100%" cellpadding="4" cellspacing="0" border="0"><tr><td align="center" style="font-family:times;font-size:10.5pt;color:'
            . hotel_brand_tokens()['colors']['muted_dark'] . ';">' . rh_pdf_e($validity) . '</td></tr></table>';

        $meta = array_merge([
            ['Quotation ref', (string)$a['quote_ref']],
            ['Valid until', $validText],
        ], (array)$a['meta']);

        return rh_pdf_document_shell((string)$a['title'], $meta, $body);
    }
}

/**
 * Build HTML content for invoice - Stunning State-of-the-Art PDF Design
 */
function buildInvoiceHTML(array $booking, string $invoice_number, string $site_name, string $email_address, string $phone_number, string $address, string $currency_symbol, array $group_bookings = [], ?array $preload = null)
{
    global $pdo;

    $check_in  = date('j F Y', strtotime($booking['check_in_date']));
    $check_out = date('j F Y', strtotime($booking['check_out_date']));
    $issued    = date('j F Y');

    // Logo
    $logo_url  = function_exists('hotel_invoice_logo_src')
        ? hotel_invoice_logo_src()
        : getInvoiceLogoUrl();
    $logo_html = !empty($logo_url)
        ? '<img src="' . htmlspecialchars($logo_url) . '" alt="' . htmlspecialchars($site_name) . '" height="64" style="height:64px; width:auto; display:block; margin:0 auto;">'
        : '';

    // Guest counts
    $childGuests        = (int)($booking['child_guests'] ?? 0);
    $adultGuests        = (int)($booking['adult_guests'] ?? max(1, ((int)($booking['number_of_guests'] ?? 1)) - $childGuests));
    $pkgTotal           = (float)($booking['package_total']          ?? 0);
    // rate_plan_discount is stored per room per night; the invoice line is a booking total.
    $ratePlanNightsOf = static function (array $b): int {
        $n = (int)($b['number_of_nights'] ?? 0);
        if (!empty($b['check_in_date']) && !empty($b['check_out_date'])) {
            $ci = strtotime((string)$b['check_in_date']);
            $co = strtotime((string)$b['check_out_date']);
            if ($ci !== false && $co !== false) {
                $n = (int)round(($co - $ci) / 86400);
            }
        }
        return max(1, $n);
    };
    $ratePlanDiscount   = (float)($booking['rate_plan_discount']     ?? 0) * $ratePlanNightsOf($booking);
    $ratePlanLabel      = (string)($booking['rate_plan_label']       ?? '');

    // When group_bookings is provided, use combined figures from all rooms.
    // Otherwise fall back to single-booking columns.
    if (!empty($group_bookings)) {
        $childSuppTotal  = array_sum(array_column($group_bookings, 'child_supplement_total'));
        $tourismLevyAmt  = array_sum(array_column($group_bookings, 'tourism_levy_amount'));
        $tourismLevyPct  = (float)($group_bookings[0]['tourism_levy_percent'] ?? ($booking['tourism_levy_percent'] ?? 0));
        // roomSubtotal = sum of all (total_amount - package_total per booking)
        // packages live on the primary booking so we subtract once
        $roomSubtotal    = array_sum(array_column($group_bookings, 'total_amount')) - $pkgTotal;
    } else {
        $childSuppTotal  = (float)($booking['child_supplement_total'] ?? 0);
        $tourismLevyAmt  = (float)($booking['tourism_levy_amount']    ?? 0);
        $tourismLevyPct  = (float)($booking['tourism_levy_percent']   ?? 0);
        $roomSubtotal    = (float)$booking['total_amount'] - $pkgTotal;
    }
    $pkgRows = [];
    try {
        $ps2 = $pdo->prepare("SELECT * FROM booking_packages WHERE booking_id = ? ORDER BY id ASC");
        $ps2->execute([$booking['id']]);
        $pkgRows = $ps2->fetchAll(PDO::FETCH_ASSOC);
    } catch (PDOException $e) {
        error_log("Invoice booking_packages error: " . $e->getMessage());
    }

    // VAT
    $vatEnabled = in_array(getSetting('vat_enabled'), ['1', 1, true, 'true', 'on'], true);
    $vatRate    = $vatEnabled ? (float)getSetting('vat_rate') : 0;
    $vatNumber  = getSetting('vat_number');

    // Folio charges
    $folioCharges = [];
    $folioTotal   = 0.0;
    $folioVat     = 0.0;
    try {
        $cs = $pdo->prepare("SELECT charge_type, description, quantity, unit_price, line_subtotal, vat_amount, line_total, posted_at
                              FROM booking_charges WHERE booking_id = ? AND voided = 0
                              ORDER BY posted_at ASC, id ASC");
        $cs->execute([$booking['id']]);
        $folioCharges = $cs->fetchAll(PDO::FETCH_ASSOC);
        foreach ($folioCharges as $c) {
            $folioTotal += (float)$c['line_total'];
            $folioVat += (float)$c['vat_amount'];
        }
    } catch (PDOException $e) {
        error_log("Invoice folio error: " . $e->getMessage());
    }

    // Payments — for group bookings collect across all room booking IDs
    if (!empty($group_bookings)) {
        $grp_ids    = array_map(fn($g) => (int)$g['id'], $group_bookings);
        $placeholders = implode(',', array_fill(0, count($grp_ids), '?'));
        $ps = $pdo->prepare("SELECT * FROM payments WHERE booking_type='room' AND booking_id IN ($placeholders) AND ((payment_status IN ('completed','paid','refunded','partially_refunded') AND COALESCE(payment_type,'') <> 'refund') OR (payment_type='refund' AND refund_status IN ('completed','processing'))) AND deleted_at IS NULL ORDER BY payment_date ASC, id ASC");
        $ps->execute($grp_ids);
        $payments = rh_invoice_signed_payments($ps->fetchAll(PDO::FETCH_ASSOC));
    } else {
        $ps = $pdo->prepare("SELECT * FROM payments WHERE booking_type='room' AND booking_id=? AND ((payment_status IN ('completed','paid','refunded','partially_refunded') AND COALESCE(payment_type,'') <> 'refund') OR (payment_type='refund' AND refund_status IN ('completed','processing'))) AND deleted_at IS NULL ORDER BY payment_date ASC, id ASC");
        $ps->execute([$booking['id']]);
        $payments = rh_invoice_signed_payments($ps->fetchAll(PDO::FETCH_ASSOC));
    }

    // Sample rendering only (scripts/send-test-documents.php): supply rows instead of reading the DB.
    if ($preload !== null) {
        $pkgRows      = (array)($preload['packages'] ?? $pkgRows);
        $folioCharges = (array)($preload['folio_charges'] ?? $folioCharges);
        $payments     = rh_invoice_signed_payments((array)($preload['payments'] ?? $payments));
        $folioTotal   = 0.0;
        $folioVat     = 0.0;
        foreach ($folioCharges as $c) {
            $folioTotal += (float)$c['line_total'];
            $folioVat   += (float)$c['vat_amount'];
        }
    }

    // Totals — VAT per installation mode: exclusive adds on top of the room
    // base, inclusive extracts from it (total never inflates), off is zero.
    // Folio lines carry their own stored VAT split either way.
    $taxBase       = $roomSubtotal + $pkgTotal;
    $baseVatParts  = vat_components($taxBase);
    $vatRate       = $baseVatParts['rate'];
    $vatAmount     = $baseVatParts['vat'] + $folioVat;
    $totalWithVat  = $baseVatParts['total'] + $folioTotal;
    $amountPaid    = array_sum(array_column($payments, 'total_amount'));
    $balanceDue    = max(0.0, $totalWithVat - $amountPaid);

    // Paid / total / balance come from the folio summary (canonical net-paid rule, folio
    // charges and levy included) so the invoice always agrees with the booking page.
    $summaryIds = !empty($group_bookings) ? array_map(fn($g) => (int)$g['id'], $group_bookings) : [(int)$booking['id']];
    if ($preload !== null && isset($preload['summary'])) {
        $summaryIds = [(int)$booking['id']]; // sample rendering: one supplied summary for the whole document
    }
    $sumGrand = 0.0; $sumPaid = 0.0; $sumDue = 0.0; $summaryOk = true;
    foreach ($summaryIds as $sid) {
        $fs = ($preload !== null && isset($preload['summary'])) ? (array)$preload['summary'] : getBookingFolioSummary($sid);
        if (isset($fs['error']) || !isset($fs['grand_total'], $fs['amount_paid'], $fs['balance_due'])) {
            $summaryOk = false;
            break;
        }
        $sumGrand += (float)$fs['grand_total'];
        $sumPaid  += (float)$fs['amount_paid'];
        $sumDue   += (float)$fs['balance_due'];
    }
    if ($summaryOk) {
        $totalWithVat = $sumGrand;
        $amountPaid   = $sumPaid;
        $balanceDue   = ($sumDue <= BALANCE_TOLERANCE) ? 0.0 : $sumDue;
    }

    // Status badge
    $isPaid        = $balanceDue <= BALANCE_TOLERANCE;
    $statusText    = $isPaid ? 'PAID IN FULL' : 'BALANCE DUE';
    $statusBg      = $isPaid ? '#E7F4EA' : '#FCE8E6';
    $statusFg      = $isPaid ? '#1E6C43' : '#A63A3A';
    $totalDueDisplay = $currency_symbol . ' ' . number_format($totalWithVat, 2);
    $amountPaidDisplay = $currency_symbol . ' ' . number_format($amountPaid, 2);
    $balanceDueDisplay = $currency_symbol . ' ' . number_format($balanceDue, 2);

    // An admin-customised DB template (payment_invoice_document) keeps being honoured;
    // the stock seeded copy is ignored in favour of the kit layout below.
    $customTemplate = function_exists('hotel_default_payment_invoice_document_html')
        ? rh_inv_custom_template('payment_invoice_document', hotel_default_payment_invoice_document_html())
        : rh_inv_custom_template('payment_invoice_document');
    if ($customTemplate !== '') {
        return rh_inv_legacy_room_template(get_defined_vars(), $customTemplate);
    }

    // ═══════════════════════════════════════════════════════
    //  KIT LAYOUT (config/document-theme.php)
    // ═══════════════════════════════════════════════════════
    $cur   = trim((string)$currency_symbol) !== '' ? trim((string)$currency_symbol) : hotel_brand_tokens()['hotel']['currency'];
    $money = static function ($v) use ($cur): string {
        return rh_pdf_money((float)$v, $cur);
    };
    $tc = hotel_brand_tokens()['colors'];

    $guestCountStr = $adultGuests . ' Adult' . ($adultGuests > 1 ? 's' : '') . ($childGuests > 0 ? ', ' . $childGuests . ' Child' . ($childGuests > 1 ? 'ren' : '') : '');

    // Item rows: [description, qty, unit, amount]
    $itemRows = [];
    if (!empty($group_bookings)) {
        foreach ($group_bookings as $grm) {
            $grmSubtotal = (float)$grm['total_amount'];
            $nightRate   = $grmSubtotal / max(1, (int)$grm['number_of_nights']);
            $itemRows[] = [
                rh_pdf_e($grm['room_name']) . rh_inv_sub('Accommodation'),
                rh_pdf_e((string)(int)$grm['number_of_nights']),
                rh_pdf_e($money($nightRate)),
                rh_pdf_e($money($grmSubtotal)),
            ];
        }
    } else {
        $nightRate = $roomSubtotal / max(1, (int)$booking['number_of_nights']);
        $itemRows[] = [
            rh_pdf_e($booking['room_name']) . rh_inv_sub('Accommodation'),
            rh_pdf_e((string)(int)$booking['number_of_nights']),
            rh_pdf_e($money($nightRate)),
            rh_pdf_e($money($roomSubtotal)),
        ];
    }
    if ($childGuests > 0 && $childSuppTotal > 0) {
        $itemRows[] = [
            rh_pdf_e('Child supplement x ' . $childGuests),
            rh_pdf_e((string)$childGuests),
            rh_pdf_e($money($childSuppTotal / $childGuests)),
            rh_pdf_e($money($childSuppTotal)),
        ];
    }
    if ($ratePlanDiscount > 0 && $ratePlanLabel !== '') {
        $itemRows[] = [
            rh_pdf_e($ratePlanLabel) . rh_inv_sub('Rate discount'),
            rh_pdf_e((string)(int)$booking['number_of_nights']),
            '',
            '<span style="color:' . $tc['success'] . ';">' . rh_pdf_e($money(-$ratePlanDiscount)) . '</span>',
        ];
    }
    foreach ($pkgRows as $pkg) {
        $itemRows[] = [
            rh_pdf_e($pkg['package_name']) . rh_inv_sub('Package add-on'),
            rh_pdf_e((string)(int)$pkg['quantity']),
            rh_pdf_e($money((float)$pkg['price_amount'])),
            rh_pdf_e($money((float)$pkg['total_cost'])),
        ];
    }
    foreach ($folioCharges as $fc) {
        $typeLabel = match (strtolower((string)($fc['charge_type'] ?? ''))) {
            'food'  => 'Food',
            'drink' => 'Beverage',
            'spa'   => 'Spa',
            default => ucfirst((string)(($fc['charge_type'] ?? '') !== '' ? $fc['charge_type'] : 'Extra')),
        };
        $itemRows[] = [
            rh_pdf_e($fc['description']) . rh_inv_sub($typeLabel),
            rh_pdf_e((string)(int)$fc['quantity']),
            rh_pdf_e($money((float)$fc['unit_price'])),
            rh_pdf_e($money((float)$fc['line_total'])),
        ];
    }
    $itemsHtml = rh_pdf_items_table([
        ['label' => 'Description', 'width' => 46, 'align' => 'left'],
        ['label' => 'Qty', 'width' => 10, 'align' => 'right'],
        ['label' => 'Unit', 'width' => 21, 'align' => 'right'],
        ['label' => 'Amount', 'width' => 23, 'align' => 'right'],
    ], $itemRows);

    // Totals - same figures as before; only the presentation follows vat_mode().
    $vMode = vat_mode();
    $totals = [];
    if ($vMode === 'inclusive') {
        $totals[] = ['label' => 'Subtotal (incl. VAT)', 'value' => $money($baseVatParts['total'] + $folioTotal)];
    } elseif ($vMode === 'exclusive') {
        $totals[] = ['label' => 'Subtotal (excl. VAT)', 'value' => $money($baseVatParts['net'] + $folioTotal - $folioVat)];
    } else {
        // VAT off: no VAT row prints, so the subtotal must carry any VAT recorded
        // earlier for the rows to foot to the invoice total.
        $totals[] = ['label' => 'Subtotal', 'value' => $money((float)$totalWithVat - (float)$tourismLevyAmt)];
    }
    foreach (rh_pdf_vat_rows((float)$vatAmount, (float)$vatRate, $cur) as $vrow) {
        $totals[] = $vrow;
    }
    if ($tourismLevyAmt > 0) {
        $totals[] = ['label' => 'Tourism levy' . ($tourismLevyPct > 0 ? ' (' . number_format($tourismLevyPct, 1) . '%)' : ''), 'value' => $money($tourismLevyAmt)];
    }
    $totals[] = ['label' => 'Invoice total', 'value' => $money($totalWithVat), 'type' => 'strong'];
    $totals[] = ['label' => 'Amount paid', 'value' => $money($amountPaid), 'type' => 'paid'];
    $totals[] = ['label' => 'Balance due', 'value' => $money(max(0, $balanceDue)), 'type' => 'total'];

    $body = $itemsHtml . rh_pdf_spacer(3) . rh_inv_keep_together(rh_pdf_totals_table($totals))
        . rh_inv_payments_html($payments, $cur)
        . rh_inv_bank_terms_html($site_name, $email_address, $phone_number, !$isPaid)
        . rh_inv_closing_html('Thank you for choosing us.');

    $meta = [
        ['Invoice No.', $invoice_number],
        ['Issued', date('d M Y')],
        ['Billed to', (string)$booking['guest_name']],
        ['Booking ref', (string)$booking['booking_reference']],
        ['Email', (string)($booking['guest_email'] ?? '')],
        ['Check-in', date('d M Y', strtotime((string)$booking['check_in_date']))],
        ['Phone', (string)($booking['guest_phone'] ?? '')],
        ['Check-out', date('d M Y', strtotime((string)$booking['check_out_date']))],
        ['Room', (string)$booking['room_name']],
        ['Nights', (string)(int)$booking['number_of_nights']],
        ['Guests', $guestCountStr],
    ];

    return rh_pdf_document_shell('Invoice', $meta, $body, [
        'banner' => ['text' => $isPaid ? 'Paid in full' : 'Balance due', 'tone' => $isPaid ? 'neutral' : 'danger'],
    ]);
}
/**
 * Renders an admin-customised payment_invoice_document DB template (legacy placeholders).
 * Receives the variable scope of buildInvoiceHTML().
 */
function rh_inv_legacy_room_template(array $c, string $templateHtml): string
{
    extract($c, EXTR_SKIP);

    // ── HELPER: section label ─────────────────────────────────
    $sectionLabel  = static fn(string $t) =>
    '<p style="margin:0 0 6px 0; font-size:9px; font-weight:700; letter-spacing:2px; text-transform:uppercase; color:#B18247;">' . $t . '</p>';

    // ── CHARGES ROWS ─────────────────────────────────────────
    $chargeRows = '';
    $rowBg = ['#F7F3EE', '#EDE8E0'];
    $ri    = 0;

    // Accommodation rows — one per room for group bookings, single row otherwise
    if (!empty($group_bookings)) {
        foreach ($group_bookings as $grm) {
            $grm_subtotal = (float)$grm['total_amount'];
            $night_rate   = $grm_subtotal / max(1, (int)$grm['number_of_nights']);
            $chargeRows .= '<tr style="background:' . $rowBg[$ri++ % 2] . ';">
                <td width="50%" style="padding:6px 8px; font-size:12px; color:#231F1C; font-family:Helvetica,Arial,sans-serif; border:1px solid #C8BEB0; line-height:1.35;">' . htmlspecialchars($grm['room_name']) . ' — Accommodation</td>
                <td width="10%" style="padding:6px 8px; font-size:12px; color:#5E554D; text-align:center; font-family:Helvetica,Arial,sans-serif; border:1px solid #C8BEB0;">' . (int)$grm['number_of_nights'] . '</td>
                <td width="15%" style="padding:6px 8px; font-size:12px; color:#5E554D; text-align:right; font-family:Helvetica,Arial,sans-serif; border:1px solid #C8BEB0;">' . $currency_symbol . ' ' . number_format($night_rate, 2) . '</td>
                <td width="25%" style="padding:6px 8px; font-size:12px; color:#231F1C; font-weight:600; text-align:right; font-family:Helvetica,Arial,sans-serif; border:1px solid #C8BEB0;">' . $currency_symbol . ' ' . number_format($grm_subtotal, 2) . '</td>
            </tr>';
        }
    } else {
        $nightRate = $roomSubtotal / max(1, (int)$booking['number_of_nights']);
        $chargeRows .= '<tr style="background:' . $rowBg[$ri++ % 2] . ';">
            <td width="50%" style="padding:6px 8px; font-size:12px; color:#231F1C; font-family:Helvetica,Arial,sans-serif; border:1px solid #C8BEB0; line-height:1.35;">' . htmlspecialchars($booking['room_name']) . ' — Accommodation</td>
            <td width="10%" style="padding:6px 8px; font-size:12px; color:#5E554D; text-align:center; font-family:Helvetica,Arial,sans-serif; border:1px solid #C8BEB0;">' . (int)$booking['number_of_nights'] . '</td>
            <td width="15%" style="padding:6px 8px; font-size:12px; color:#5E554D; text-align:right; font-family:Helvetica,Arial,sans-serif; border:1px solid #C8BEB0;">' . $currency_symbol . ' ' . number_format($nightRate, 2) . '</td>
            <td width="25%" style="padding:6px 8px; font-size:12px; color:#231F1C; font-weight:600; text-align:right; font-family:Helvetica,Arial,sans-serif; border:1px solid #C8BEB0;">' . $currency_symbol . ' ' . number_format($roomSubtotal, 2) . '</td>
        </tr>';
    }

    if ($childGuests > 0 && $childSuppTotal > 0) {
        $chargeRows .= '<tr style="background:' . $rowBg[$ri++ % 2] . ';">
            <td width="50%" style="padding:6px 8px; font-size:12px; color:#231F1C; font-family:Helvetica,Arial,sans-serif; border:1px solid #C8BEB0; line-height:1.35;">Child Supplement &times; ' . $childGuests . '</td>
            <td width="10%" style="padding:6px 8px; font-size:12px; color:#5E554D; text-align:center; font-family:Helvetica,Arial,sans-serif; border:1px solid #C8BEB0;">' . $childGuests . '</td>
            <td width="15%" style="padding:6px 8px; font-size:12px; color:#5E554D; text-align:right; font-family:Helvetica,Arial,sans-serif; border:1px solid #C8BEB0;">' . $currency_symbol . ' ' . number_format($childSuppTotal / $childGuests, 2) . '</td>
            <td width="25%" style="padding:6px 8px; font-size:12px; color:#231F1C; font-weight:600; text-align:right; font-family:Helvetica,Arial,sans-serif; border:1px solid #C8BEB0;">' . $currency_symbol . ' ' . number_format($childSuppTotal, 2) . '</td>
        </tr>';
    }

    // Rate plan discount row
    if ($ratePlanDiscount > 0 && $ratePlanLabel !== '') {
        $chargeRows .= '<tr style="background:' . $rowBg[$ri++ % 2] . ';">
            <td width="50%" style="padding:6px 8px; font-size:12px; color:#1a3c2a; font-family:Helvetica,Arial,sans-serif; border:1px solid #C8BEB0; line-height:1.35;">' . htmlspecialchars($ratePlanLabel) . ' — Rate Discount</td>
            <td width="10%" style="padding:6px 8px; font-size:12px; color:#5E554D; text-align:center; font-family:Helvetica,Arial,sans-serif; border:1px solid #C8BEB0;">' . (int)$booking['number_of_nights'] . '</td>
            <td width="15%" style="padding:6px 8px; font-size:12px; color:#5E554D; text-align:right; font-family:Helvetica,Arial,sans-serif; border:1px solid #C8BEB0;">—</td>
            <td width="25%" style="padding:6px 8px; font-size:12px; color:#1a3c2a; font-weight:600; text-align:right; font-family:Helvetica,Arial,sans-serif; border:1px solid #C8BEB0;">-' . $currency_symbol . ' ' . number_format($ratePlanDiscount, 2) . '</td>
        </tr>';
    }

    // Package add-on rows
    foreach ($pkgRows as $pkg) {
        $chargeRows .= '<tr style="background:' . $rowBg[$ri++ % 2] . ';">
            <td width="50%" style="padding:6px 8px; font-size:12px; color:#231F1C; font-family:Helvetica,Arial,sans-serif; border:1px solid #C8BEB0; line-height:1.35;">' . htmlspecialchars($pkg['package_name']) . ' — Package Add-on</td>
            <td width="10%" style="padding:6px 8px; font-size:12px; color:#5E554D; text-align:center; font-family:Helvetica,Arial,sans-serif; border:1px solid #C8BEB0;">' . (int)$pkg['quantity'] . '</td>
            <td width="15%" style="padding:6px 8px; font-size:12px; color:#5E554D; text-align:right; font-family:Helvetica,Arial,sans-serif; border:1px solid #C8BEB0;">' . $currency_symbol . ' ' . number_format((float)$pkg['price_amount'], 2) . '</td>
            <td width="25%" style="padding:6px 8px; font-size:12px; color:#231F1C; font-weight:600; text-align:right; font-family:Helvetica,Arial,sans-serif; border:1px solid #C8BEB0;">' . $currency_symbol . ' ' . number_format((float)$pkg['total_cost'], 2) . '</td>
        </tr>';
    }

    if ($tourismLevyAmt > 0) {
        $chargeRows .= '<tr style="background:' . $rowBg[$ri++ % 2] . ';">
            <td width="50%" style="padding:6px 8px; font-size:12px; color:#231F1C; font-family:Helvetica,Arial,sans-serif; border:1px solid #C8BEB0; line-height:1.35;">Tourism Levy ' . ($tourismLevyPct > 0 ? '(' . number_format($tourismLevyPct, 1) . '%)' : '') . '</td>
            <td width="10%" style="padding:6px 8px; font-size:12px; color:#5E554D; text-align:center; font-family:Helvetica,Arial,sans-serif; border:1px solid #C8BEB0;">1</td>
            <td width="15%" style="padding:6px 8px; font-size:12px; color:#5E554D; text-align:right; font-family:Helvetica,Arial,sans-serif; border:1px solid #C8BEB0;">—</td>
            <td width="25%" style="padding:6px 8px; font-size:12px; color:#231F1C; font-weight:600; text-align:right; font-family:Helvetica,Arial,sans-serif; border:1px solid #C8BEB0;">' . $currency_symbol . ' ' . number_format($tourismLevyAmt, 2) . '</td>
        </tr>';
    }

    foreach ($folioCharges as $fc) {
        $typeIcon = match (strtolower($fc['charge_type'] ?? '')) {
            'food'  => 'Food',
            'drink' => 'Beverage',
            'spa'   => 'Spa',
            default => ucfirst((string)($fc['charge_type'] ?? 'Extra')),
        };
        $chargeRows .= '<tr style="background:' . $rowBg[$ri++ % 2] . ';">
            <td width="50%" style="padding:4px 6px; font-size:11px; color:#231F1C; font-family:Helvetica,Arial,sans-serif; border:1px solid #C8BEB0; line-height:1.25;">' . htmlspecialchars($fc['description']) . ' <span style="font-size:9px; color:#B18247; font-style:italic;">' . $typeIcon . '</span></td>
            <td width="10%" style="padding:6px 8px; font-size:12px; color:#5E554D; text-align:center; font-family:Helvetica,Arial,sans-serif; border:1px solid #C8BEB0;">' . (int)$fc['quantity'] . '</td>
            <td width="15%" style="padding:6px 8px; font-size:12px; color:#5E554D; text-align:right; font-family:Helvetica,Arial,sans-serif; border:1px solid #C8BEB0;">' . $currency_symbol . ' ' . number_format((float)$fc['unit_price'], 2) . '</td>
            <td width="25%" style="padding:6px 8px; font-size:12px; color:#231F1C; font-weight:600; text-align:right; font-family:Helvetica,Arial,sans-serif; border:1px solid #C8BEB0;">' . $currency_symbol . ' ' . number_format((float)$fc['line_total'], 2) . '</td>
        </tr>';
    }

    // ── PAYMENT ROWS ────────────────────────────────────────
    $paymentRows = '';
    foreach ($payments as $pmt) {
        $paymentRows .= '<tr>
            <td style="padding:4px 0; font-size:11px; color:#5E554D; border-bottom:1px solid #EDE8E2;">' . date('j M Y', strtotime($pmt['payment_date'])) . '</td>
            <td style="padding:4px 0; font-size:11px; color:#5E554D; border-bottom:1px solid #EDE8E2;">' . htmlspecialchars(ucwords(str_replace('_', ' ', $pmt['payment_method']))) . '</td>
            <td style="padding:4px 0; font-size:11px; color:#231F1C; font-weight:600; text-align:right; border-bottom:1px solid #EDE8E2;">' . $currency_symbol . ' ' . number_format((float)$pmt['total_amount'], 2) . '</td>
        </tr>';
    }

    $guestCountStr = $adultGuests . ' Adult' . ($adultGuests > 1 ? 's' : '') . ($childGuests > 0 ? ', ' . $childGuests . ' Child' . ($childGuests > 1 ? 'ren' : '') : '');

    // ── TOTALS ROWS (appended directly into charges tbody) ───
    $cSub = number_format($baseVatParts['net'] + $folioTotal - $folioVat, 2);
    $totalsRows  = '<tr><td colspan="3" style="padding:6px 8px 3px; text-align:right; font-size:12px; color:#5E554D; font-family:Helvetica,Arial,sans-serif; border-top:2px solid #D8CDBE; border-left:1px solid #C8BEB0;">Subtotal</td><td width="25%" style="padding:6px 8px 3px; text-align:right; font-size:12px; color:#231F1C; font-family:Helvetica,Arial,sans-serif; border-top:2px solid #D8CDBE; border-right:1px solid #C8BEB0; white-space:nowrap;">' . $currency_symbol . ' ' . $cSub . '</td></tr>';
    if ($vatEnabled && $vatAmount > 0) {
        $totalsRows .= '<tr><td colspan="3" style="padding:3px 8px; text-align:right; font-size:12px; color:#5E554D; font-family:Helvetica,Arial,sans-serif; border-bottom:1px solid #C8BEB0; border-left:1px solid #C8BEB0;">VAT (' . number_format($vatRate, 1) . '%)</td><td width="25%" style="padding:3px 8px; text-align:right; font-size:12px; color:#231F1C; font-family:Helvetica,Arial,sans-serif; border-bottom:1px solid #C8BEB0; border-right:1px solid #C8BEB0; white-space:nowrap;">' . htmlspecialchars(vat_document_value($currency_symbol . ' ' . number_format($vatAmount, 2)), ENT_QUOTES, 'UTF-8') . '</td></tr>';
    }
    if ($tourismLevyAmt > 0) {
        $totalsRows .= '<tr><td colspan="3" style="padding:3px 8px; text-align:right; font-size:12px; color:#5E554D; font-family:Helvetica,Arial,sans-serif; border-bottom:1px solid #C8BEB0; border-left:1px solid #C8BEB0;">Tourism levy</td><td width="25%" style="padding:3px 8px; text-align:right; font-size:12px; color:#231F1C; font-family:Helvetica,Arial,sans-serif; border-bottom:1px solid #C8BEB0; border-right:1px solid #C8BEB0; white-space:nowrap;">' . $currency_symbol . ' ' . number_format($tourismLevyAmt, 2) . '</td></tr>';
    }
    $totalsRows .= '<tr><td colspan="3" bgcolor="#20303E" style="padding:7px 8px; background-color:#20303E; font-size:12px; font-weight:700; color:#F7F3EE; font-family:Helvetica,Arial,sans-serif; text-align:right; border-top:2px solid #4A5E6D;">Invoice Total</td><td width="25%" bgcolor="#20303E" style="padding:7px 8px; background-color:#20303E; font-size:12px; font-weight:700; color:#D5B37C; font-family:Helvetica,Arial,sans-serif; text-align:right; border-top:2px solid #4A5E6D; white-space:nowrap;">' . $currency_symbol . ' ' . number_format($totalWithVat, 2) . '</td></tr>';
    $totalsRows .= '<tr><td colspan="3" style="padding:5px 8px 3px; text-align:right; font-size:12px; color:#5E554D; font-family:Helvetica,Arial,sans-serif; border-bottom:1px solid #C8BEB0; border-left:1px solid #C8BEB0;">Amount Paid</td><td width="25%" style="padding:5px 8px 3px; text-align:right; font-size:12px; color:#4a7c5e; font-weight:700; font-family:Helvetica,Arial,sans-serif; border-bottom:1px solid #C8BEB0; border-right:1px solid #C8BEB0; white-space:nowrap;">' . $currency_symbol . ' ' . number_format($amountPaid, 2) . '</td></tr>';
    $balanceDueDisplayValue = max(0, $balanceDue);
    $balanceDueColor = $balanceDue > 0 ? '#9b2c2c' : '#4a7c5e';
    $totalsRows .= '<tr><td colspan="3" style="padding:3px 8px 6px; text-align:right; font-size:12px; color:' . $balanceDueColor . '; font-weight:700; font-family:Helvetica,Arial,sans-serif; border-bottom:2px solid #C8BEB0; border-left:1px solid #C8BEB0;">Balance Due</td><td width="25%" style="padding:3px 8px 6px; text-align:right; font-size:12px; color:' . $balanceDueColor . '; font-weight:700; font-family:Helvetica,Arial,sans-serif; border-bottom:2px solid #C8BEB0; border-right:1px solid #C8BEB0; white-space:nowrap;">' . $currency_symbol . ' ' . number_format($balanceDueDisplayValue, 2) . '</td></tr>';

    // ── DB TEMPLATE (admin-customised) ────────
    {
        $docTemplate = ['html_body' => $templateHtml];
        {
            $vatNumberHtml = $vatNumber
                ? '<p style="margin:2px 0 0; font-size:10px; color:#8A775F; font-family:Helvetica,Arial,sans-serif; letter-spacing:0.04em;">VAT Reg: ' . htmlspecialchars($vatNumber) . '</p>'
                : '';
            $roomIconHtml = '<span style="color:#B18247;font-size:11px;vertical-align:middle;">&#9679;</span>';
            $payHistorySection = '';
            if (!empty($payments)) {
                $payHistorySection = '<div style="background:#FCFAF7;padding:10px 12px;border-top:2px solid #D5B37C;">'
                    . '<p style="margin:0 0 6px;font-size:11px;letter-spacing:0.12em;text-transform:uppercase;color:#20303E;font-weight:700;">Payment History</p>'
                    . '<table style="width:100%; border-collapse:collapse;" cellpadding="0" cellspacing="0">'
                    . '<tr>'
                    . '<th style="padding:0 0 4px; text-align:left; font-size:11px; letter-spacing:0.08em; text-transform:uppercase; color:#7C6E5B; font-family:Helvetica,Arial,sans-serif; font-weight:700;">Date</th>'
                    . '<th style="padding:0 0 4px; text-align:left; font-size:11px; letter-spacing:0.08em; text-transform:uppercase; color:#7C6E5B; font-family:Helvetica,Arial,sans-serif; font-weight:700;">Method</th>'
                    . '<th style="padding:0 0 4px; text-align:right; font-size:11px; letter-spacing:0.08em; text-transform:uppercase; color:#7C6E5B; font-family:Helvetica,Arial,sans-serif; font-weight:700;">Amount</th>'
                    . '</tr>' . $paymentRows . '</table></div>';
            }

            $bankRows = [];
            $bankName = trim((string)getSetting('bank_name', ''));
            $bankAccountName = trim((string)getSetting('bank_account_name', ''));
            $bankAccountNumber = trim((string)getSetting('bank_account_number', ''));
            $bankBranch = trim((string)getSetting('bank_branch', ''));
            if ($bankName !== '') {
                $bankRows[] = '<tr><td style="padding:3px 0; font-size:11px; color:#1F1C17; line-height:1.45; text-align:left;"><span style="color:#7A6F63; font-weight:700;">Bank:</span> <span style="font-weight:600;">' . htmlspecialchars($bankName, ENT_QUOTES, 'UTF-8') . '</span></td></tr>';
            }
            if ($bankAccountName !== '') {
                $bankRows[] = '<tr><td style="padding:3px 0; font-size:11px; color:#1F1C17; line-height:1.45; text-align:left;"><span style="color:#7A6F63; font-weight:700;">Account Name:</span> <span style="font-weight:600;">' . htmlspecialchars($bankAccountName, ENT_QUOTES, 'UTF-8') . '</span></td></tr>';
            }
            if ($bankAccountNumber !== '') {
                $bankRows[] = '<tr><td style="padding:3px 0; font-size:11px; color:#1F1C17; line-height:1.45; text-align:left;"><span style="color:#7A6F63; font-weight:700;">Account No.:</span> <span style="font-weight:600;">' . htmlspecialchars($bankAccountNumber, ENT_QUOTES, 'UTF-8') . '</span></td></tr>';
            }
            if ($bankBranch !== '') {
                $bankRows[] = '<tr><td style="padding:3px 0; font-size:11px; color:#1F1C17; line-height:1.45; text-align:left;"><span style="color:#7A6F63; font-weight:700;">Branch:</span> <span style="font-weight:600;">' . htmlspecialchars($bankBranch, ENT_QUOTES, 'UTF-8') . '</span></td></tr>';
            }
            $bankDetailsHtml = $bankRows !== []
                ? '<div style="background:#FCFAF7;padding:10px 12px;border-top:2px solid #D5B37C;text-align:left;">'
                . '<p style="margin:0 0 7px;font-size:11px;letter-spacing:0.12em;text-transform:uppercase;color:#20303E;font-weight:700;">Bank Details</p>'
                . '<table style="width:100%;border-collapse:collapse;" cellpadding="0" cellspacing="0">' . implode('', $bankRows) . '</table>'
                . '</div>'
                : '';

            $invoiceTermsText = trim((string)getSetting('invoice_terms', getSetting('payment_terms', '')));
            if ($invoiceTermsText !== '') {
                $invoiceTermsText = strtr($invoiceTermsText, [
                    '{{contact_email}}' => $email_address,
                    '{{contact_phone}}' => $phone_number,
                    '{{site_name}}' => $site_name,
                ]);
            }
            $invoiceTermsHtml = $invoiceTermsText !== ''
                ? '<div style="background:#FCFAF7;padding:9px 11px;border-top:2px solid #20303E;">'
                . '<p style="margin:0 0 5px;font-size:10px;letter-spacing:0.12em;text-transform:uppercase;color:#20303E;font-weight:700;">Invoice Terms</p>'
                . '<p style="margin:0;font-size:10px;line-height:1.5;color:#5F5343;">' . nl2br(htmlspecialchars($invoiceTermsText, ENT_QUOTES, 'UTF-8')) . '</p>'
                . '</div>'
                : '';

            $replacements = [
                '{{invoice_number}}'          => htmlspecialchars($invoice_number),
                '{{issued_date}}'             => $issued,
                '{{guest_name}}'              => htmlspecialchars($booking['guest_name']),
                '{{guest_email}}'             => htmlspecialchars($booking['guest_email']),
                '{{guest_phone}}'             => htmlspecialchars($booking['guest_phone']),
                '{{booking_reference}}'       => htmlspecialchars($booking['booking_reference']),
                '{{room_icon_html}}'          => $roomIconHtml,
                '{{room_name}}'               => htmlspecialchars($booking['room_name']),
                '{{check_in}}'                => $check_in,
                '{{check_out}}'               => $check_out,
                '{{nights}}'                  => (string)(int)$booking['number_of_nights'],
                '{{guests}}'                  => $guestCountStr,
                '{{status_text}}'             => $statusText,
                '{{status_bg}}'               => $statusBg,
                '{{status_fg}}'               => $statusFg,
                '{{total_due}}'              => $totalDueDisplay,
                '{{amount_paid}}'            => $amountPaidDisplay,
                '{{balance_due}}'            => $balanceDueDisplay,
                '{{site_name}}'               => htmlspecialchars($site_name),
                '{{address}}'                 => htmlspecialchars($address),
                '{{contact_email}}'           => htmlspecialchars($email_address),
                '{{contact_phone}}'           => htmlspecialchars($phone_number),
                '{{vat_number_html}}'         => $vatNumberHtml,
                '{{logo_html}}'               => $logo_html,
                '{{currency_symbol}}'         => htmlspecialchars($currency_symbol),
                '{{charges_table_rows}}'      => $chargeRows,
                '{{totals_rows}}'             => $totalsRows,
                '{{payment_history_section}}' => $payHistorySection,
                '{{bank_details}}'            => $bankDetailsHtml,
                '{{invoice_terms}}'           => $invoiceTermsHtml,
            ];
            return strtr($docTemplate['html_body'], $replacements);
        }
    }
    return $templateHtml;
}


/**
 * Send payment invoice email to guest and copy recipients
 *
 * @param int $booking_id Booking ID
 * @return array Result array with success status and message
 */
function sendPaymentInvoiceEmail(int $booking_id)
{
    global $pdo;

    try {
        // Get booking details
        $stmt = $pdo->prepare("SELECT * FROM bookings WHERE id = ?");
        $stmt->execute([$booking_id]);
        $booking = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$booking) {
            throw new Exception("Booking not found");
        }

        // Get latest payment row and reuse existing invoice when available.
        $paymentStmt = $pdo->prepare("\n            SELECT id, invoice_generated, invoice_path, invoice_number\n            FROM payments\n            WHERE booking_type = 'room' AND booking_id = ? AND deleted_at IS NULL\n            ORDER BY id DESC\n            LIMIT 1\n        ");
        $paymentStmt->execute([$booking_id]);
        $latestPayment = $paymentStmt->fetch(PDO::FETCH_ASSOC);

        if (!$latestPayment) {
            throw new Exception("No payment record found for booking");
        }

        $invoice_file = '';
        $invoice_number = (string)($latestPayment['invoice_number'] ?? '');
        $invoice_path = (string)($latestPayment['invoice_path'] ?? '');

        if (!empty($latestPayment['invoice_generated']) && $invoice_path !== '') {
            $existingInvoiceFile = dirname(__DIR__) . '/' . ltrim($invoice_path, '/\\');
            // Only reuse a cached file if it is a proper PDF (not an HTML fallback)
            if (is_file($existingInvoiceFile) && strtolower(pathinfo($existingInvoiceFile, PATHINFO_EXTENSION)) === 'pdf') {
                $invoice_file = $existingInvoiceFile;
                if ($invoice_number === '') {
                    $invoice_number = (string)pathinfo($invoice_path, PATHINFO_FILENAME);
                }
            }
        }

        $needs_regeneration = ($invoice_file === '');
        if (!$needs_regeneration && function_exists('getBookingDocumentTemplateUpdatedTimestamp')) {
            $template_updated_ts = getBookingDocumentTemplateUpdatedTimestamp('payment_invoice_document');
            $file_updated_ts = @filemtime($invoice_file);
            if ($template_updated_ts !== null && $file_updated_ts !== false && $file_updated_ts < $template_updated_ts) {
                $needs_regeneration = true;
            }
        }

        if ($needs_regeneration) {
            $invoice_result = generateInvoicePDF($booking_id, $invoice_number !== '' ? $invoice_number : null);
            if ($invoice_result) {
                $invoice_file = $invoice_result['filepath'];
                $invoice_number = $invoice_result['invoice_number'];
                $invoice_path = $invoice_result['relative_path'];

                // Persist generated invoice against the latest payment row.
                $update_stmt = $pdo->prepare("\n                UPDATE payments\n                SET invoice_path = ?, invoice_number = ?, invoice_generated = 1\n                WHERE id = ?\n            ");
                $update_stmt->execute([$invoice_path, $invoice_number, (int)$latestPayment['id']]);
            } elseif ($invoice_file === '') {
                throw new Exception("Failed to generate invoice");
            }
        }

        // Get invoice recipients (comma-separated)
        $invoice_recipients = getEmailSetting('invoice_recipients', '');
        $smtp_username = getEmailSetting('smtp_username', '');

        // Parse recipients from comma-separated string
        $cc_recipients = array_filter(array_map('trim', explode(',', $invoice_recipients)));

        // Always add SMTP username to CC list
        if (!empty($smtp_username) && !in_array($smtp_username, $cc_recipients)) {
            $cc_recipients[] = $smtp_username;
        }

        // Send invoice to guest with CC recipients
        $result = sendInvoiceEmailToGuestWithCC($booking, $invoice_file, $cc_recipients, $invoice_number);

        return [
            'success' => $result['success'],
            'message' => $result['message'],
            'invoice_file' => $invoice_file,
            'invoice_number' => $invoice_number,
            'invoice_path' => $invoice_path,
            'cc_recipients' => $cc_recipients
        ];
    } catch (Exception $e) {
        error_log("Send Payment Invoice Email Error: " . $e->getMessage());
        return [
            'success' => false,
            'message' => $e->getMessage()
        ];
    }
}

/**
 * Send payment invoice email with custom CC recipients
 *
 * @param int $booking_id Booking ID
 * @param array $ccRecipients Array of CC email addresses
 * @return array Result array with success status and message
 */
function sendPaymentInvoiceEmailWithCC(int $booking_id, array $ccRecipients = [])
{
    global $pdo;

    try {
        // Get booking details
        $stmt = $pdo->prepare("SELECT * FROM bookings WHERE id = ?");
        $stmt->execute([$booking_id]);
        $booking = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$booking) {
            throw new Exception("Booking not found");
        }

        // Get latest payment row and reuse existing invoice when available.
        $paymentStmt = $pdo->prepare("
            SELECT id, invoice_generated, invoice_path, invoice_number
            FROM payments
            WHERE booking_type = 'room' AND booking_id = ? AND deleted_at IS NULL
            ORDER BY id DESC
            LIMIT 1
        ");
        $paymentStmt->execute([$booking_id]);
        $latestPayment = $paymentStmt->fetch(PDO::FETCH_ASSOC);

        if (!$latestPayment) {
            throw new Exception('No payment record found for booking');
        }

        $invoice_file = '';
        $invoice_number = (string)($latestPayment['invoice_number'] ?? '');
        $invoice_path = (string)($latestPayment['invoice_path'] ?? '');

        if (!empty($latestPayment['invoice_generated']) && $invoice_path !== '') {
            $existingInvoiceFile = dirname(__DIR__) . '/' . ltrim($invoice_path, '/\\');
            // Only reuse a cached file if it is a proper PDF (not an HTML fallback)
            if (is_file($existingInvoiceFile) && strtolower(pathinfo($existingInvoiceFile, PATHINFO_EXTENSION)) === 'pdf') {
                $invoice_file = $existingInvoiceFile;
                if ($invoice_number === '') {
                    $invoice_number = (string)pathinfo($invoice_path, PATHINFO_FILENAME);
                }
            }
        }

        $needs_regeneration = ($invoice_file === '');
        if (!$needs_regeneration && function_exists('getBookingDocumentTemplateUpdatedTimestamp')) {
            $template_updated_ts = getBookingDocumentTemplateUpdatedTimestamp('payment_invoice_document');
            $file_updated_ts = @filemtime($invoice_file);
            if ($template_updated_ts !== null && $file_updated_ts !== false && $file_updated_ts < $template_updated_ts) {
                $needs_regeneration = true;
            }
        }

        if ($needs_regeneration) {
            $invoice_result = generateInvoicePDF($booking_id, $invoice_number !== '' ? $invoice_number : null);
            if ($invoice_result) {
                $invoice_file = $invoice_result['filepath'];
                $invoice_number = $invoice_result['invoice_number'];
                $invoice_path = $invoice_result['relative_path'];

                // Persist generated invoice against the latest payment row.
                $update_stmt = $pdo->prepare("
                UPDATE payments
                SET invoice_path = ?, invoice_number = ?, invoice_generated = 1
                WHERE id = ?
            ");
                $update_stmt->execute([$invoice_path, $invoice_number, (int)$latestPayment['id']]);
            } elseif ($invoice_file === '') {
                throw new Exception('Failed to generate invoice');
            }
        }

        // Send invoice to guest with custom CC recipients
        $result = sendInvoiceEmailToGuestWithCC($booking, $invoice_file, $ccRecipients, $invoice_number);

        return [
            'success' => $result['success'],
            'message' => $result['message'],
            'invoice_file' => $invoice_file,
            'invoice_number' => $invoice_number,
            'invoice_path' => $invoice_path,
            'cc_recipients' => $ccRecipients
        ];
    } catch (Exception $e) {
        error_log("Send Payment Invoice Email with CC Error: " . $e->getMessage());
        return [
            'success' => false,
            'message' => $e->getMessage()
        ];
    }
}

/**
 * Send invoice email to guest with CC recipients
 */
function sendInvoiceEmailToGuestWithCC(array $booking, string $invoice_file, array $cc_recipients = [], string $invoice_number = '')
{
    global $pdo, $email_from_name, $email_from_email, $email_site_name, $email_site_url;

    try {
        // Get room details
        $stmt = $pdo->prepare("SELECT * FROM rooms WHERE id = ?");
        $stmt->execute([$booking['room_id']]);
        $room = $stmt->fetch(PDO::FETCH_ASSOC);

        $checkOut = !empty($booking['check_out_date']) ? date('F j, Y', strtotime((string)$booking['check_out_date'])) : '';
        $siteUrl  = rtrim((string)($email_site_url ?? ''), '/');
        $invoiceLink = $siteUrl . '/booking-lookup.php?ref=' . urlencode((string)($booking['booking_reference'] ?? ''));
        $templateVars = function_exists('buildBookingEmailVariables')
            ? buildBookingEmailVariables($booking, $room, [
                'invoice_number' => $invoice_number,
                'check_out'      => $checkOut,
                'invoice_link'   => $invoiceLink,
            ])
            : [];
        $dbTemplate = function_exists('renderBookingEmailTemplate')
            ? renderBookingEmailTemplate('payment_invoice', $templateVars)
            : null;

        $currency_symbol = getSetting('currency_symbol');

        // Get logo for email
        $logo_url = getInvoiceLogoUrl();
        $logo_html_email = '';
        if (!empty($logo_url)) {
            $logo_html_email = '<img src="' . htmlspecialchars($logo_url) . '" alt="' . htmlspecialchars($email_site_name) . '" style="max-width: 110px; height: auto; display: block; margin: 0 auto 15px auto;">';
        }

        // Prepare email content - Stunning State-of-the-Art Design
        $htmlBody = '
        <!DOCTYPE html>
        <html>
        <head>
            <meta charset="UTF-8">
            <meta name="viewport" content="width=device-width, initial-scale=1.0">
        </head>
        <body style="margin: 0; padding: 0; background-color: #F5F5F5;">
            <div style="font-family: Helvetica, Arial, sans-serif; max-width: 600px; margin: 0 auto; background-color: #FFFFFF; box-shadow: 0 4px 6px rgba(0,0,0,0.1);">

                <!-- HEADER -->
                <div style="background: linear-gradient(135deg, #1A1A1A 0%, #2D2D2D 100%); padding: 40px 30px; text-align: center;">
                    ' . $logo_html_email . '
                    <h1 style="color: #8B7355; margin: 0 0 10px 0; font-size: 28px; font-weight: 300; letter-spacing: 4px;">PAYMENT CONFIRMED</h1>
                    <p style="color: #FFFFFF; margin: 0; font-size: 16px; opacity: 0.9;">Thank you for your payment</p>
                </div>

                <!-- SUCCESS BANNER -->
                <div style="background: linear-gradient(135deg, #E8F5E9 0%, #C8E6C9 100%); padding: 20px 30px; text-align: center;">
                    <span style="color: #2E7D32; font-size: 18px; font-weight: 600;">✓ Your booking is confirmed</span>
                </div>

                <!-- CONTENT -->
                <div style="padding: 40px 30px;">

                    <p style="margin: 0 0 20px 0; font-size: 16px; color: #424242; line-height: 1.6;">
                        Dear <strong>' . htmlspecialchars($booking['guest_name']) . '</strong>,
                    </p>

                    <p style="margin: 0 0 30px 0; font-size: 15px; color: #616161; line-height: 1.7;">
                        We are pleased to confirm that your payment has been received. Your booking reference is <span style="color: #8B7355; font-weight: 600;">' . htmlspecialchars($booking['booking_reference']) . '</span>. Please find your detailed invoice attached to this email.
                    </p>

                    <!-- BOOKING DETAILS CARD -->
                    <div style="background: #FAFAFA; border-radius: 12px; padding: 25px; margin: 0 0 30px 0; border: 1px solid #E0E0E0;">
                        <h3 style="color: #8B7355; font-size: 12px; margin: 0 0 20px 0; text-transform: uppercase; letter-spacing: 2px; font-weight: 600;">Booking Summary</h3>

                        <table style="width: 100%; border-collapse: collapse;">
                            <tr>
                                <td style="padding: 12px 0; border-bottom: 1px solid #E0E0E0;">
                                    <span style="font-size: 11px; color: #9E9E9E; text-transform: uppercase; letter-spacing: 0.5px;">Room Type</span>
                                    <p style="margin: 5px 0 0 0; font-size: 15px; font-weight: 600; color: #212121;">' . htmlspecialchars($room['name']) . '</p>
                                </td>
                                <td style="padding: 12px 0; border-bottom: 1px solid #E0E0E0;">
                                    <span style="font-size: 11px; color: #9E9E9E; text-transform: uppercase; letter-spacing: 0.5px;">Guests</span>
                                    <p style="margin: 5px 0 0 0; font-size: 15px; font-weight: 600; color: #212121;">' . (int)$booking['number_of_guests'] . '</p>
                                </td>
                            </tr>
                            <tr>
                                <td style="padding: 12px 0; border-bottom: 1px solid #E0E0E0;">
                                    <span style="font-size: 11px; color: #9E9E9E; text-transform: uppercase; letter-spacing: 0.5px;">Check-in</span>
                                    <p style="margin: 5px 0 0 0; font-size: 15px; font-weight: 500; color: #424242;">' . date('F j, Y', strtotime($booking['check_in_date'])) . '</p>
                                </td>
                                <td style="padding: 12px 0; border-bottom: 1px solid #E0E0E0;">
                                    <span style="font-size: 11px; color: #9E9E9E; text-transform: uppercase; letter-spacing: 0.5px;">Check-out</span>
                                    <p style="margin: 5px 0 0 0; font-size: 15px; font-weight: 500; color: #424242;">' . date('F j, Y', strtotime($booking['check_out_date'])) . '</p>
                                </td>
                            </tr>
                        </table>

                        <!-- TOTAL -->
                        <div style="margin-top: 20px; padding-top: 20px; border-top: 2px solid #8B7355; text-align: center;">
                            <span style="font-size: 12px; color: #9E9E9E; text-transform: uppercase; letter-spacing: 1px;">Total Amount Paid</span>
                            <p style="margin: 8px 0 0 0; font-size: 28px; font-weight: 600; color: #8B7355;">' . $currency_symbol . ' ' . number_format($booking['total_amount'], 2) . '</p>
                        </div>
                    </div>

                    <!-- NEXT STEPS -->
                    <div style="background: linear-gradient(135deg, #E3F2FD 0%, #BBDEFB 100%); border-radius: 12px; padding: 25px; margin: 0 0 30px 0;">
                        <h3 style="color: #1565C0; font-size: 14px; margin: 0 0 15px 0; font-weight: 600;">📋 Important Information</h3>
                        <ul style="margin: 0; padding-left: 20px; color: #1565C0;">
                            <li style="margin-bottom: 8px; font-size: 14px; line-height: 1.5;">Check-in time: <strong>' . getSetting('check_in_time', '2:00 PM') . '</strong></li>
                            <li style="margin-bottom: 8px; font-size: 14px; line-height: 1.5;">Check-out time: <strong>' . getSetting('check_out_time', '11:00 AM') . '</strong></li>
                            <li style="margin-bottom: 0; font-size: 14px; line-height: 1.5;">Please bring a valid ID for registration</li>
                        </ul>
                    </div>

                    <!-- CONTACT -->
                    <div style="text-align: center; margin-bottom: 30px;">
                        <p style="margin: 0 0 10px 0; font-size: 14px; color: #616161;">
                            Questions? Contact us at
                            <a href="mailto:' . htmlspecialchars($email_from_email) . '" style="color: #8B7355; text-decoration: none; font-weight: 600;">' . htmlspecialchars($email_from_email) . '</a>
                        </p>
                    </div>

                </div>

                <!-- FOOTER -->
                <div style="background: #1A1A1A; padding: 30px; text-align: center;">
                    <p style="margin: 0 0 10px 0; font-size: 18px; font-weight: 600; color: #FFFFFF;">' . htmlspecialchars($email_site_name) . '</p>
                    <p style="margin: 0 0 5px 0; font-size: 13px; color: #9E9E9E;">' . htmlspecialchars(getSetting('address_line1') . ', ' . getSetting('address_line2')) . '</p>
                    <p style="margin: 0 0 20px 0; font-size: 13px; color: #9E9E9E;">' . htmlspecialchars(getSetting('address_country')) . '</p>
                    <a href="' . htmlspecialchars($email_site_url) . '" style="display: inline-block; background: #8B7355; color: #FFFFFF; padding: 12px 30px; border-radius: 25px; text-decoration: none; font-size: 14px; font-weight: 600;">Visit Our Website</a>
                </div>

            </div>
        </body>
        </html>';

        $subject = 'Payment Invoice - ' . htmlspecialchars($email_site_name) . ' [' . $booking['booking_reference'] . ']';
        $textBody = '';
        if ($dbTemplate) {
            $subject = $dbTemplate['subject'];
            $htmlBody = $dbTemplate['html_body'];
            $textBody = $dbTemplate['text_body'] ?? '';
        }

        // Append full invoice document HTML to the email body
        if (function_exists('buildInvoiceHTML')) {
            try {
                $invDocHtml = buildInvoiceHTML(
                    $booking,
                    $invoice_number,
                    getSetting('site_name'),
                    getSetting('email_from_email'),
                    getSetting('phone_main'),
                    implode(', ', array_filter([getSetting('address_line1'), getSetting('address_line2'), getSetting('address_country')])),
                    getSetting('currency_symbol')
                );
                if ($invDocHtml !== '') {
                    $docSection = '<div style="background:#d5cfc4;padding:24px 20px 0;">'
                        . '<div style="max-width:720px;margin:0 auto;">'
                        . '<p style="font-family:Helvetica,Arial,sans-serif;font-size:10px;letter-spacing:2px;text-transform:uppercase;color:#9b8f7e;margin:0 0 12px;text-align:center;">Full Invoice</p>'
                        . $invDocHtml
                        . '</div></div>';
                    if (stripos($htmlBody, '</body>') !== false) {
                        $htmlBody = (string)preg_replace('/<\/body>/i', $docSection . '</body>', $htmlBody, 1);
                    } else {
                        $htmlBody .= $docSection;
                    }
                }
            } catch (Throwable $e) {
                error_log('sendInvoiceEmailToGuestWithCC: failed to build invoice doc HTML: ' . $e->getMessage());
            }
        }

        // Send email with attachment and CC recipients
        return sendEmailWithAttachmentAndCC(
            $booking['guest_email'],
            $booking['guest_name'],
            $subject,
            $htmlBody,
            $invoice_file,
            $cc_recipients,
            $textBody
        );
    } catch (Exception $e) {
        error_log("Send Invoice to Guest Error: " . $e->getMessage());
        return ['success' => false, 'message' => $e->getMessage()];
    }
}

/**
 * Send invoice copy emails
 */
function sendInvoiceCopyEmails(array $booking, string $invoice_file, array $recipients)
{
    if (empty($recipients)) {
        return ['success' => true, 'message' => 'No copy recipients'];
    }

    global $email_site_name;
    $currency_symbol = getSetting('currency_symbol');

    $htmlBody = '
    <div style="font-family: Arial, sans-serif; max-width: 600px; margin: 0 auto;">
        <div style="background: #1A1A1A; padding: 20px; text-align: center; border-radius: 10px 10px 0 0;">
            <h1 style="color: #8B7355; margin: 0; font-size: 24px;">INVOICE COPY</h1>
            <p style="color: white; margin: 10px 0 0 0;">Administrative Copy</p>
        </div>

        <div style="background: #f8f9fa; padding: 30px; border: 1px solid #ddd; border-top: none; border-radius: 0 0 10px 10px;">
            <p>A payment has been received for booking <strong>' . htmlspecialchars($booking['booking_reference']) . '</strong>.</p>

            <div style="background: white; padding: 20px; border-radius: 8px; margin: 20px 0;">
                <h3 style="color: #1A1A1A; margin-top: 0;">Payment Details</h3>
                <p><strong>Guest:</strong> ' . htmlspecialchars($booking['guest_name']) . '</p>
                <p><strong>Email:</strong> ' . htmlspecialchars($booking['guest_email']) . '</p>
                <p><strong>Amount Paid:</strong> <span style="color: #8B7355; font-weight: bold;">' . $currency_symbol . ' ' . number_format($booking['total_amount'], 0) . '</span></p>
                <p><strong>Payment Date:</strong> ' . date('F j, Y g:i A') . '</p>
            </div>

            <p>Please find the invoice attached for your records.</p>
        </div>
    </div>';

    // Send to all recipients
    $all_success = true;
    foreach ($recipients as $recipient) {
        $result = sendEmailWithAttachment(
            $recipient,
            'Accounts Team',
            'Invoice Copy - ' . htmlspecialchars($email_site_name) . ' [' . $booking['booking_reference'] . ']',
            $htmlBody,
            $invoice_file
        );
        if (!$result['success']) {
            $all_success = false;
            error_log("Failed to send invoice copy to $recipient: " . $result['message']);
        }
    }

    return ['success' => $all_success, 'message' => $all_success ? 'All copies sent' : 'Some copies failed'];
}

/**
 * Send email with attachment (wrapper for sendEmailWithAttachmentAndCC)
 *
 * @param string $to Recipient email
 * @param string $toName Recipient name
 * @param string $subject Email subject
 * @param string $htmlBody HTML email body
 * @param string $attachmentPath Path to attachment file
 * @return array Result array with success status and message
 */
function sendEmailWithAttachment(string $to, ?string $toName, string $subject, string $htmlBody, string $attachmentPath)
{
    // Call the CC version with empty CC array
    return sendEmailWithAttachmentAndCC($to, $toName, $subject, $htmlBody, $attachmentPath, []);
}

/**
 * Send email with attachment and CC recipients
 * Uses the same email configuration as config/email.php
 */
function sendEmailWithAttachmentAndCC(string $to, ?string $toName, string $subject, string $htmlBody, string $attachmentPath, array $ccRecipients = [], string $textBody = '')
{
    global $email_from_name, $email_from_email, $email_admin_email;
    global $smtp_host, $smtp_port, $smtp_username, $smtp_password, $smtp_secure, $smtp_timeout, $smtp_debug, $email_site_name;
    global $email_bcc_admin, $development_mode, $email_log_enabled, $email_preview_enabled;

    // Check if we're on localhost
    $is_localhost = isset($_SERVER['HTTP_HOST']) && (
        strpos($_SERVER['HTTP_HOST'], 'localhost') !== false ||
        strpos($_SERVER['HTTP_HOST'], '127.0.0.1') !== false ||
        strpos($_SERVER['HTTP_HOST'], '.local') !== false
    );

    // Development mode: show previews on localhost unless explicitly disabled
    $dev_mode = $is_localhost && $development_mode;

    // If in development mode and no password or preview enabled, show preview
    if ($dev_mode && (empty($smtp_password) || $email_preview_enabled)) {
        return createEmailPreview($to, $toName, $subject, $htmlBody);
    }

    try {
        $mail = new PHPMailer(true);

        $smtpSecureNormalized = strtolower(trim((string)$smtp_secure));
        if ($smtpSecureNormalized === '' && (int)$smtp_port === 587) {
            $smtpSecureNormalized = 'tls';
        } elseif ($smtpSecureNormalized === '' && (int)$smtp_port === 465) {
            $smtpSecureNormalized = 'ssl';
        }

        /**
         * Generate and send final invoice at checkout
         * Includes idempotency safeguards to avoid duplicate invoice generation
         *
         * @param int $booking_id Booking ID
         * @param int|null $processed_by Admin user ID processing the checkout
         * @return array Result with success status, invoice details, and any warnings
         */
        function generateAndSendFinalInvoice(int $booking_id, ?int $processed_by = null): array
        {
            global $pdo;

            try {
                // Check if booking exists
                $stmt = $pdo->prepare("SELECT id, booking_reference, guest_name, guest_email, status, final_invoice_generated FROM bookings WHERE id = ?");
                $stmt->execute([$booking_id]);
                $booking = $stmt->fetch(PDO::FETCH_ASSOC);

                if (!$booking) {
                    return ['success' => false, 'message' => 'Booking not found'];
                }

                // Check if final invoice already generated (idempotency)
                if ($booking['final_invoice_generated']) {
                    // Return existing invoice details
                    $existingStmt = $pdo->prepare("SELECT final_invoice_path, final_invoice_number, final_invoice_sent_at FROM bookings WHERE id = ?");
                    $existingStmt->execute([$booking_id]);
                    $existing = $existingStmt->fetch(PDO::FETCH_ASSOC);

                    return [
                        'success' => true,
                        'message' => 'Final invoice already generated',
                        'invoice_path' => $existing['final_invoice_path'],
                        'invoice_number' => $existing['final_invoice_number'],
                        'sent_at' => $existing['final_invoice_sent_at'],
                        'idempotent' => true
                    ];
                }

                // Recalculate folio before generating invoice
                recalculateBookingFinancials($booking_id);

                // Generate final invoice
                $invoice_result = generateInvoicePDF($booking_id);
                if (!$invoice_result) {
                    return ['success' => false, 'message' => 'Failed to generate final invoice'];
                }

                $invoice_file = $invoice_result['filepath'];
                $invoice_number = $invoice_result['invoice_number'];
                $invoice_path = $invoice_result['relative_path'];

                // Update booking with final invoice details
                $updateStmt = $pdo->prepare("
                    UPDATE bookings
                    SET final_invoice_generated = 1,
                        final_invoice_path = ?,
                        final_invoice_number = ?,
                        final_invoice_sent_at = NULL,
                        checkout_processed_by = ?,
                        updated_at = NOW()
                    WHERE id = ?
                ");
                $updateStmt->execute([$invoice_path, $invoice_number, $processed_by, $booking_id]);

                // Send final invoice email
                $email_sent = false;
                $email_error = null;

                try {
                    // Get invoice recipients
                    $invoice_recipients = getEmailSetting('invoice_recipients', '');
                    $smtp_username = getEmailSetting('smtp_username', '');

                    // Parse recipients
                    $cc_recipients = array_filter(array_map('trim', explode(',', $invoice_recipients)));

                    // Always add SMTP username to CC
                    if (!empty($smtp_username) && !in_array($smtp_username, $cc_recipients)) {
                        $cc_recipients[] = $smtp_username;
                    }

                    // Send email
                    $email_result = sendFinalInvoiceEmail($booking, $invoice_file, $cc_recipients);
                    $email_sent = $email_result['success'];

                    if ($email_sent) {
                        // Update sent timestamp
                        $pdo->prepare("UPDATE bookings SET final_invoice_sent_at = NOW() WHERE id = ?")
                            ->execute([$booking_id]);
                    } else {
                        $email_error = $email_result['message'];
                    }
                } catch (Exception $e) {
                    $email_error = $e->getMessage();
                    error_log("Final invoice email error: " . $email_error);
                }

                return [
                    'success' => true,
                    'message' => 'Final invoice generated' . ($email_sent ? ' and sent' : ' (email failed)'),
                    'invoice_file' => $invoice_file,
                    'invoice_number' => $invoice_number,
                    'invoice_path' => $invoice_path,
                    'email_sent' => $email_sent,
                    'email_error' => $email_error,
                    'idempotent' => false
                ];
            } catch (Exception $e) {
                error_log("Generate and send final invoice error: " . $e->getMessage());
                return ['success' => false, 'message' => $e->getMessage()];
            }
        }

        /**
         * Send final invoice email at checkout
         *
         * @param array $booking Booking details
         * @param string $invoice_file Path to invoice file
         * @param array $cc_recipients CC recipients
         * @return array Result with success status
         */
        function sendFinalInvoiceEmail(array $booking, string $invoice_file, array $cc_recipients = [])
        {
            global $pdo, $email_from_name, $email_from_email, $email_site_name, $email_site_url;

            try {
                // Get room details
                $stmt = $pdo->prepare("SELECT * FROM rooms WHERE id = ?");
                $stmt->execute([$booking['room_id']]);
                $room = $stmt->fetch(PDO::FETCH_ASSOC);

                $currency_symbol = getSetting('currency_symbol');

                // Build email content
                $htmlBody = '
                <div style="font-family: Arial, sans-serif; max-width: 600px; margin: 0 auto;">
                    <div style="background: linear-gradient(135deg, #1A1A1A 0%, #2A2A2A 100%); padding: 30px; text-align: center; border-radius: 10px 10px 0 0;">
                        <h1 style="color: #8B7355; margin: 0; font-size: 32px;">✓ CHECKOUT COMPLETE</h1>
                        <p style="color: white; margin: 10px 0 0 0; font-size: 18px;">Thank you for your stay!</p>
                    </div>

                    <div style="background: #f8f9fa; padding: 30px; border: 1px solid #ddd; border-top: none; border-radius: 0 0 10px 10px;">
                        <p>Dear ' . htmlspecialchars($booking['guest_name']) . ',</p>

                        <p>We hope you enjoyed your stay at <strong>' . htmlspecialchars($email_site_name) . '</strong>. Your checkout has been completed and your final invoice is ready.</p>

                        <div style="background: white; padding: 20px; border-radius: 8px; margin: 20px 0; border-left: 4px solid #8B7355;">
                            <h3 style="color: #1A1A1A; margin-top: 0;">Final Invoice Details</h3>

                            <div style="display: flex; justify-content: space-between; padding: 8px 0; border-bottom: 1px solid #eee;">
                                <span style="font-weight: bold; color: #333;">Booking Reference:</span>
                                <span style="color: #666;">' . htmlspecialchars($booking['booking_reference']) . '</span>
                            </div>

                            <div style="display: flex; justify-content: space-between; padding: 8px 0; border-bottom: 1px solid #eee;">
                                <span style="font-weight: bold; color: #333;">Room:</span>
                                <span style="color: #666;">' . htmlspecialchars($room['name']) . '</span>
                            </div>

                            <div style="display: flex; justify-content: space-between; padding: 8px 0; border-bottom: 1px solid #eee;">
                                <span style="font-weight: bold; color: #333;">Check-out Date:</span>
                                <span style="color: #666;">' . date('F j, Y') . '</span>
                            </div>
                        </div>

                        <div style="background: #d4edda; padding: 15px; border-left: 4px solid #28a745; border-radius: 5px; margin: 20px 0;">
                            <h3 style="color: #155724; margin-top: 0;">✅ Final Invoice Attached</h3>
                            <p style="color: #155724; margin: 0;">Please find your final invoice attached to this email. It includes all room charges, extras, and payments made during your stay.</p>
                        </div>

                        <div style="background: #e7f3ff; padding: 15px; border-left: 4px solid #0d6efd; border-radius: 5px;">
                            <h3 style="color: #0d6efd; margin-top: 0;">We Hope to See You Again!</h3>
                            <p style="color: #0d6efd; margin: 0;">Thank you for choosing ' . htmlspecialchars($email_site_name) . '. We look forward to welcoming you back soon.</p>
                        </div>

                        <p style="margin-top: 30px;">If you have any questions about your invoice or your stay, please contact us at <a href="mailto:' . htmlspecialchars($email_from_email) . '">' . htmlspecialchars($email_from_email) . '</a>.</p>

                        <p style="margin-top: 20px;">Safe travels!</p>

                        <div style="text-align: center; margin-top: 40px; padding-top: 20px; border-top: 2px solid #1A1A1A;">
                            <p style="color: #666; font-size: 14px; margin: 5px 0;"><strong>The ' . htmlspecialchars($email_site_name) . ' Team</strong></p>
                            <p style="color: #666; font-size: 14px; margin: 5px 0;"><a href="' . htmlspecialchars($email_site_url) . '">' . htmlspecialchars($email_site_url) . '</a></p>
                        </div>
                    </div>
                </div>';

                $subject = 'Final Invoice - ' . htmlspecialchars($email_site_name) . ' [' . $booking['booking_reference'] . ']';

                // Send email with attachment
                return sendEmailWithAttachmentAndCC(
                    $booking['guest_email'],
                    $booking['guest_name'],
                    $subject,
                    $htmlBody,
                    $invoice_file,
                    $cc_recipients
                );
            } catch (Exception $e) {
                error_log("Send final invoice email error: " . $e->getMessage());
                return ['success' => false, 'message' => $e->getMessage()];
            }
        }
        // Many SMTP relays require From to match authenticated mailbox.
        $fromAddress = $smtp_username;

        // Server settings - loaded from database
        $mail->isSMTP();
        $mail->Host = $smtp_host;
        $mail->SMTPAuth = true;
        $mail->Username = $smtp_username;
        $mail->Password = $smtp_password;
        if ($smtpSecureNormalized !== '') {
            $mail->SMTPSecure = $smtpSecureNormalized;
        }
        $mail->Port = $smtp_port;
        $mail->Timeout = $smtp_timeout;

        if ($smtp_debug > 0) {
            $mail->SMTPDebug = $smtp_debug;
        }

        // Recipients
        $mail->setFrom($fromAddress, $email_from_name ?: $email_site_name);
        $mail->addAddress($to, $toName ?? '');
        if (!empty($email_from_email) && filter_var($email_from_email, FILTER_VALIDATE_EMAIL)) {
            $mail->addReplyTo($email_from_email, $email_from_name ?: $email_site_name);
        }

        // Add CC recipients from invoice_recipients setting
        foreach ($ccRecipients as $cc) {
            if (!empty($cc) && filter_var($cc, FILTER_VALIDATE_EMAIL)) {
                $mail->addCC($cc);
            }
        }

        // Add BCC for admin if enabled
        if ($email_bcc_admin && !empty($email_admin_email)) {
            $mail->addBCC($email_admin_email);
        }

        // Add attachment
        if (file_exists($attachmentPath)) {
            $mail->addAttachment($attachmentPath, basename($attachmentPath), PHPMailer::ENCODING_BASE64, 'application/pdf');
        }

        // Content
        $mail->CharSet  = PHPMailer::CHARSET_UTF8;
        $mail->Encoding = PHPMailer::ENCODING_BASE64;
        $mail->isHTML(true);
        $mail->Subject = $subject;
        $mail->Body = hotel_embed_logo_cid($mail, wrapEmailTemplate($htmlBody, $subject));
        $mail->AltBody = $textBody ?: strip_tags($htmlBody);

        $mail->send();

        // Log email if enabled
        if ($email_log_enabled) {
            $cc_list = implode(', ', $ccRecipients);
            logEmail($to, $toName, $subject, 'sent', '', "CC: $cc_list");
        }

        return [
            'success' => true,
            'message' => 'Email sent successfully via SMTP with ' . count($ccRecipients) . ' CC recipients'
        ];
    } catch (Exception $e) {
        error_log("PHPMailer Error (sendEmailWithAttachmentAndCC): " . $e->getMessage());

        // Log error if enabled
        if ($email_log_enabled) {
            $cc_list = implode(', ', $ccRecipients);
            logEmail($to, $toName, $subject, 'failed', $e->getMessage(), "CC: $cc_list");
        }

        // If development mode, show preview instead of failing
        if ($dev_mode) {
            return createEmailPreview($to, $toName, $subject, $htmlBody);
        }

        return [
            'success' => false,
            'message' => 'Failed to send email: ' . $e->getMessage()
        ];
    }
}

/**
 * Generate PDF invoice for a conference enquiry
 *
 * @param int $enquiry_id Conference enquiry ID
 * @return array{filepath:string,invoice_number:string,relative_path:string}|false Returns array with file details or false on failure
 */
function generateConferenceInvoicePDF(int $enquiry_id)
{
    global $pdo, $tcpdf_loaded;

    try {
        // Get enquiry details
        $stmt = $pdo->prepare("
            SELECT ci.*, cr.name as room_name,
                   s.setting_value as site_name
            FROM conference_inquiries ci
            LEFT JOIN conference_rooms cr ON ci.conference_room_id = cr.id
            JOIN site_settings s ON s.setting_key = 'site_name'
            WHERE ci.id = ?
        ");
        $stmt->execute([$enquiry_id]);
        $enquiry = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$enquiry) {
            throw new Exception("Conference enquiry not found");
        }

        // Get hotel contact details
        $site_name = getSetting('site_name');
        $email_address = getSetting('email_from_email');
        $phone_number = getSetting('phone_main');
        $address = getSetting('address_line1') . ', ' .
            getSetting('address_line2') . ', ' .
            getSetting('address_country');
        $currency_symbol = getSetting('currency_symbol');

        // Create invoice directory if it doesn't exist
        $invoiceDir = __DIR__ . '/../invoices';
        if (!file_exists($invoiceDir)) {
            mkdir($invoiceDir, 0755, true);
        }

        // Generate unique invoice filename - use sequential invoice number from settings
        $invoice_prefix = getSetting('invoice_prefix', 'INV');
        $invoice_start = (int)getSetting('invoice_start_number', 1000);

        $invoice_number = finance_next_invoice_number($pdo, 'CONF-' . $invoice_prefix, $invoice_start, date('Y-m-d'), 'conference');
        $filename = $invoice_number . '.pdf';
        $filepath = $invoiceDir . '/' . $filename;

        if ($tcpdf_loaded) {
            $html = buildConferenceInvoiceHTML($enquiry, $invoice_number, $site_name, $email_address, $phone_number, $address, $currency_symbol);

            // Use shared renderer so conference invoice PDFs match HTML preview styling.
            if (function_exists('bookingRenderPdfFromHtml')) {
                file_put_contents($filepath, bookingRenderPdfFromHtml($html, 'Invoice ' . $invoice_number));
            } else {
                $tcpdfClass = 'TCPDF';
                $pdf = new $tcpdfClass(PDF_PAGE_ORIENTATION, PDF_UNIT, PDF_PAGE_FORMAT, true, 'UTF-8', false);
                $pdf->SetCreator($site_name);
                $pdf->SetAuthor($site_name);
                $pdf->SetTitle('Invoice ' . $invoice_number);
                $pdf->SetSubject('Conference Payment Invoice');
                $pdf->setPrintHeader(false);
                $pdf->setPrintFooter(false);
                $pdf->SetMargins(8, 8, 8);
                $pdf->SetAutoPageBreak(true, 10);
                $pdf->SetFont('helvetica', '', 10);
                $pdf->AddPage();
                // Fill entire page with Japandi warm cream
                $pdf->SetFillColor(247, 243, 238);
                $pdf->Rect(0, 0, 210, 297, 'F');
                $pdf->writeHTML($html, true, false, true, false, '');
                $pdf->Output($filepath, 'F');
            }
        } else {
            // Fallback: Generate HTML invoice and save as file
            $html = buildConferenceInvoiceHTML($enquiry, $invoice_number, $site_name, $email_address, $phone_number, $address, $currency_symbol);

            // Wrap in complete HTML document
            $fullHtml = '<!DOCTYPE html>
<html>
<head>
    <meta charset="UTF-8">
    <title>Invoice ' . $invoice_number . '</title>
    <style>
        body { font-family: Arial, sans-serif; margin: 0; padding: 20px; }
        .invoice-container { max-width: 800px; margin: 0 auto; border:1px solid #ddd; }
        .invoice-header { background: linear-gradient(135deg, #1A1A1A 0%, #2A2A2A 100%); color: white; padding: 30px; }
        .invoice-header h1 { margin: 0; color: #8B7355; }
        .invoice-body { padding: 30px; }
        .invoice-details { margin-bottom: 30px; }
        .invoice-row { display: flex; justify-content: space-between; padding: 10px 0; border-bottom:1px solid #eee; }
        .invoice-label { font-weight: bold; color: #333; }
        .invoice-value { color: #666; }
        .total-section { background: #f8f9fa; padding: 20px; border-radius:5px; margin-top: 20px; }
        .total-row { display: flex; justify-content: space-between; font-size: 18px; font-weight: bold; color: #8B7355; }
        .footer { text-align: center; padding: 20px; background: #f8f9fa; border-top: 1px solid #ddd; }
    </style>
</head>
<body>' . $html . '</body></html>';

            // Save as HTML (can be opened in browser and printed as PDF)
            $htmlFilepath = str_replace('.pdf', '.html', $filepath);
            file_put_contents($htmlFilepath, $fullHtml);

            // Return array with both paths and invoice number
            return [
                'filepath' => $htmlFilepath,
                'invoice_number' => $invoice_number,
                'relative_path' => 'invoices/' . basename($htmlFilepath)
            ];
        }

        // Return array with both paths and invoice number
        return [
            'filepath' => $filepath,
            'invoice_number' => $invoice_number,
            'relative_path' => 'invoices/' . $filename
        ];
    } catch (Exception $e) {
        error_log("Generate Conference Invoice PDF Error: " . $e->getMessage());
        return false;
    }
}

/**
 * Build HTML content for conference invoice
 */
function buildConferenceInvoiceHTML(array $enquiry, string $invoice_number, string $site_name, string $email_address, string $phone_number, string $address, string $currency_symbol)
{
    global $pdo;

    $event_date = date('j F Y', strtotime($enquiry['event_date']));

    // VAT settings
    $vatEnabled = in_array(getSetting('vat_enabled'), ['1', 1, true, 'true', 'on'], true);
    $vatRate = $vatEnabled ? (float)getSetting('vat_rate') : 0;

    // Get payment details for this conference enquiry - genuine receipts plus
    // settled refunds (refund rows also sit at payment_status='completed' and
    // must show as negative, not as extra payments).
    $paymentsStmt = $pdo->prepare("
        SELECT * FROM payments
        WHERE booking_type = 'conference' AND booking_id = ?
        AND deleted_at IS NULL
        AND ((payment_status IN ('completed','paid','refunded','partially_refunded') AND COALESCE(payment_type,'') <> 'refund')
             OR (payment_type = 'refund' AND refund_status IN ('completed','processing')))
        ORDER BY payment_date ASC
    ");
    $paymentsStmt->execute([$enquiry['id']]);
    $payments = rh_invoice_signed_payments($paymentsStmt->fetchAll(PDO::FETCH_ASSOC));

    // Totals - prefer the VAT breakdown LOCKED on the record at invoice time so a
    // re-sent invoice never re-bases to the current rate; only compute fresh when
    // the record was never populated.
    $subtotal = (float)$enquiry['total_amount'];
    if ((float)($enquiry['total_with_vat'] ?? 0) > 0.001) {
        $vatRate      = (float)($enquiry['vat_rate'] ?? $vatRate);
        $vatAmount    = (float)($enquiry['vat_amount'] ?? 0);
        $totalWithVat = (float)$enquiry['total_with_vat'];
    } else {
        $vatParts = vat_components($subtotal);
        $vatRate = $vatParts['rate'];
        $vatAmount = $vatParts['vat'];
        $totalWithVat = $vatParts['total'];
    }
    $amountPaid = (float)($enquiry['amount_paid'] ?? $totalWithVat);
    $balanceDue = (float)($enquiry['amount_due'] ?? max(0, $totalWithVat - $amountPaid));

    $startTs = strtotime((string)($enquiry['start_time'] ?? ''));
    $endTs   = strtotime((string)($enquiry['end_time'] ?? ''));
    $eventTime = ($startTs && $endTs) ? date('H:i', $startTs) . ' - ' . date('H:i', $endTs) : '';

    // An admin-customised DB template keeps being honoured; the stock seeded copy is replaced by the kit layout.
    $customTemplate = function_exists('hotel_default_conference_invoice_document_html')
        ? rh_inv_custom_template('conference_invoice_document', hotel_default_conference_invoice_document_html())
        : '';
    if ($customTemplate !== '' && function_exists('renderBookingDocumentTemplate')) {
        $logoSrc = function_exists('hotel_invoice_logo_src') ? hotel_invoice_logo_src() : getInvoiceLogoUrl();
        $logoHtml = $logoSrc !== ''
            ? '<img src="' . htmlspecialchars($logoSrc, ENT_QUOTES, 'UTF-8') . '" alt="' . htmlspecialchars($site_name, ENT_QUOTES, 'UTF-8') . '" height="64" style="height:64px;width:auto;display:block;margin:0 auto;">'
            : '';

        return renderBookingDocumentTemplate('conference_invoice_document', [
            'logo_html' => $logoHtml,
            'site_name' => htmlspecialchars($site_name, ENT_QUOTES, 'UTF-8'),
            'address' => htmlspecialchars($address, ENT_QUOTES, 'UTF-8'),
            'contact_email' => htmlspecialchars($email_address, ENT_QUOTES, 'UTF-8'),
            'contact_phone' => htmlspecialchars($phone_number, ENT_QUOTES, 'UTF-8'),
            'invoice_number' => htmlspecialchars($invoice_number, ENT_QUOTES, 'UTF-8'),
            'issued_date' => htmlspecialchars(date('j F Y'), ENT_QUOTES, 'UTF-8'),
            'status_text' => htmlspecialchars($balanceDue > 0 ? 'BALANCE DUE' : 'PAID IN FULL', ENT_QUOTES, 'UTF-8'),
            'inquiry_reference' => htmlspecialchars((string)($enquiry['inquiry_reference'] ?? ''), ENT_QUOTES, 'UTF-8'),
            'company_name' => htmlspecialchars((string)($enquiry['company_name'] ?? ''), ENT_QUOTES, 'UTF-8'),
            'contact_person' => htmlspecialchars((string)($enquiry['contact_person'] ?? ''), ENT_QUOTES, 'UTF-8'),
            'client_email' => htmlspecialchars((string)($enquiry['email'] ?? ''), ENT_QUOTES, 'UTF-8'),
            'client_phone' => htmlspecialchars((string)($enquiry['phone'] ?? ''), ENT_QUOTES, 'UTF-8'),
            'conference_room' => htmlspecialchars((string)($enquiry['room_name'] ?? ''), ENT_QUOTES, 'UTF-8'),
            'event_date' => htmlspecialchars($event_date, ENT_QUOTES, 'UTF-8'),
            'event_time' => htmlspecialchars($eventTime, ENT_QUOTES, 'UTF-8'),
            'attendees' => (string)((int)($enquiry['number_of_attendees'] ?? 0)),
            'event_type' => htmlspecialchars((string)($enquiry['event_type'] ?? 'Conference'), ENT_QUOTES, 'UTF-8'),
            'total_amount' => htmlspecialchars($currency_symbol . ' ' . number_format($totalWithVat, 2), ENT_QUOTES, 'UTF-8'),
            'amount_paid' => htmlspecialchars($currency_symbol . ' ' . number_format($amountPaid, 2), ENT_QUOTES, 'UTF-8'),
            'balance_due' => htmlspecialchars($currency_symbol . ' ' . number_format($balanceDue, 2), ENT_QUOTES, 'UTF-8'),
        ], $customTemplate);
    }

    $desc = rh_pdf_e('Conference facilities' . (!empty($enquiry['room_name']) ? ' - ' . $enquiry['room_name'] : ''))
        . rh_inv_sub(trim((string)($enquiry['event_type'] ?? '')) . ($event_date !== '' ? ' | ' . $event_date : '') . ($eventTime !== '' ? ', ' . $eventTime : ''));

    return rh_inv_flat_invoice([
        'title' => 'Conference Invoice',
        'invoice_number' => $invoice_number,
        'meta' => [
            ['Company', (string)($enquiry['company_name'] ?? '')],
            ['Reference', (string)($enquiry['inquiry_reference'] ?? '')],
            ['Contact', (string)($enquiry['contact_person'] ?? '')],
            ['Room', (string)($enquiry['room_name'] ?? '')],
            ['Email', (string)($enquiry['email'] ?? '')],
            ['Event date', $event_date],
            ['Phone', (string)($enquiry['phone'] ?? '')],
            ['Time', $eventTime],
            ['Event type', (string)($enquiry['event_type'] ?? '')],
            ['Attendees', (string)(int)($enquiry['number_of_attendees'] ?? 0)],
            ['Catering', !empty($enquiry['catering_required']) ? 'Yes' : 'No'],
            ['AV equipment', (string)($enquiry['av_equipment'] ?? '') !== '' ? (string)$enquiry['av_equipment'] : 'None'],
        ],
        'line_desc_html' => $desc,
        'subtotal' => $subtotal,
        'vat_enabled' => $vatEnabled,
        'vat_amount' => $vatAmount,
        'vat_rate' => $vatRate,
        'total' => $totalWithVat,
        'amount_paid' => $amountPaid,
        'amount_due' => $balanceDue,
        'deposit' => [
            'required' => !empty($enquiry['deposit_required']) && $enquiry['deposit_required'] > 0,
            'amount' => (float)($enquiry['deposit_amount'] ?? 0),
            'paid' => (float)($enquiry['deposit_paid'] ?? 0),
        ],
        'payments' => $payments,
        'closing' => 'Thank you for your payment! We look forward to hosting your event.',
        'site_name' => $site_name,
        'email' => $email_address,
        'phone' => $phone_number,
        'currency' => $currency_symbol,
    ]);
}

/**
 * Send conference payment invoice email
 *
 * @param int $enquiry_id Conference enquiry ID
 * @return array Result array with success status and message
 */
function sendConferenceInvoiceEmail(int $enquiry_id)
{
    global $pdo;

    try {
        // Get enquiry details
        $stmt = $pdo->prepare("SELECT * FROM conference_inquiries WHERE id = ?");
        $stmt->execute([$enquiry_id]);
        $enquiry = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$enquiry) {
            throw new Exception("Conference enquiry not found");
        }

        // Generate invoice PDF/HTML
        $invoice_result = generateConferenceInvoicePDF($enquiry_id);
        if (!$invoice_result) {
            throw new Exception("Failed to generate invoice");
        }

        $invoice_file = $invoice_result['filepath'];
        $invoice_number = $invoice_result['invoice_number'];
        $invoice_path = $invoice_result['relative_path'];

        // Update the payment record with invoice path and invoice number
        $update_stmt = $pdo->prepare("
            UPDATE payments
            SET invoice_path = ?, invoice_number = ?, invoice_generated = 1
            WHERE booking_type = 'conference' AND booking_id = ?
            ORDER BY id DESC LIMIT 1
        ");
        $update_stmt->execute([$invoice_path, $invoice_number, $enquiry_id]);

        // Get invoice recipients (comma-separated)
        $invoice_recipients = getEmailSetting('invoice_recipients', '');
        $smtp_username = getEmailSetting('smtp_username', '');

        // Parse recipients from comma-separated string
        $cc_recipients = array_filter(array_map('trim', explode(',', $invoice_recipients)));

        // Always add SMTP username to CC list
        if (!empty($smtp_username) && !in_array($smtp_username, $cc_recipients)) {
            $cc_recipients[] = $smtp_username;
        }

        // Send invoice to client with CC recipients
        $result = sendConferenceInvoiceEmailToClient($enquiry, $invoice_file, $cc_recipients);

        return [
            'success' => $result['success'],
            'message' => $result['message'],
            'invoice_file' => $invoice_file,
            'invoice_number' => $invoice_number,
            'invoice_path' => $invoice_path,
            'cc_recipients' => $cc_recipients
        ];
    } catch (Exception $e) {
        error_log("Send Conference Invoice Email Error: " . $e->getMessage());
        return [
            'success' => false,
            'message' => $e->getMessage()
        ];
    }
}

/**
 * Send conference payment invoice email with custom CC recipients
 *
 * @param int $enquiry_id Conference enquiry ID
 * @param array $ccRecipients Array of CC email addresses
 * @return array Result array with success status and message
 */
function sendConferenceInvoiceEmailWithCC(int $enquiry_id, array $ccRecipients = [])
{
    global $pdo;

    try {
        // Get enquiry details
        $stmt = $pdo->prepare("SELECT * FROM conference_inquiries WHERE id = ?");
        $stmt->execute([$enquiry_id]);
        $enquiry = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$enquiry) {
            throw new Exception("Conference enquiry not found");
        }

        // Generate invoice PDF/HTML
        $invoice_result = generateConferenceInvoicePDF($enquiry_id);
        if (!$invoice_result) {
            throw new Exception("Failed to generate invoice");
        }

        $invoice_file = $invoice_result['filepath'];
        $invoice_number = $invoice_result['invoice_number'];
        $invoice_path = $invoice_result['relative_path'];

        // Update the payment record with invoice path and invoice number
        $update_stmt = $pdo->prepare("
            UPDATE payments
            SET invoice_path = ?, invoice_number = ?, invoice_generated = 1
            WHERE booking_type = 'conference' AND booking_id = ?
            ORDER BY id DESC LIMIT 1
        ");
        $update_stmt->execute([$invoice_path, $invoice_number, $enquiry_id]);

        // Send invoice to client with custom CC recipients
        $result = sendConferenceInvoiceEmailToClient($enquiry, $invoice_file, $ccRecipients);

        return [
            'success' => $result['success'],
            'message' => $result['message'],
            'invoice_file' => $invoice_file,
            'invoice_number' => $invoice_number,
            'invoice_path' => $invoice_path,
            'cc_recipients' => $ccRecipients
        ];
    } catch (Exception $e) {
        error_log("Send Conference Invoice Email with CC Error: " . $e->getMessage());
        return [
            'success' => false,
            'message' => $e->getMessage()
        ];
    }
}

/**
 * Send conference invoice email to client with CC recipients
 */
function sendConferenceInvoiceEmailToClient(array $enquiry, string $invoice_file, array $cc_recipients = [])
{
    global $pdo, $email_from_name, $email_from_email, $email_site_name, $email_site_url;

    try {
        // Get conference room details
        $stmt = $pdo->prepare("SELECT * FROM conference_rooms WHERE id = ?");
        $stmt->execute([$enquiry['conference_room_id']]);
        $room = $stmt->fetch(PDO::FETCH_ASSOC);

        $currency_symbol = getSetting('currency_symbol');

        // VAT / tax vars for conference invoice email
        $ciVatNum     = (string)getSetting('vat_number', '');
        $ciSubtotal   = (float)($enquiry['total_amount'] ?? 0);
        // Match the attached PDF invoice: use the VAT breakdown locked on the
        // record; only compute from the current mode when never populated.
        if ((float)($enquiry['total_with_vat'] ?? 0) > 0.001) {
            $ciVatRate      = (float)($enquiry['vat_rate'] ?? 0);
            $ciVatAmt       = (float)($enquiry['vat_amount'] ?? 0);
            $ciTotalWithVat = (float)$enquiry['total_with_vat'];
        } else {
            $ciVatParts     = vat_components($ciSubtotal);
            $ciVatRate      = $ciVatParts['rate'];
            $ciVatAmt       = $ciVatParts['vat'];
            $ciTotalWithVat = $ciVatParts['total'];
        }
        $ciVatNumHtml = $ciVatNum !== ''
            ? '<p style="margin:8px 0 0;font-size:11px;color:#9b8f7e;text-align:center;">VAT Reg. No.: ' . htmlspecialchars($ciVatNum, ENT_QUOTES, 'UTF-8') . '</p>'
            : '';
        $ciLogoSrc  = function_exists('hotel_email_logo_url') ? hotel_email_logo_url() : '';
        $ciLogoHtml = $ciLogoSrc !== ''
            ? '<img src="' . htmlspecialchars($ciLogoSrc, ENT_QUOTES, 'UTF-8') . '" alt="' . htmlspecialchars((string)$email_site_name, ENT_QUOTES, 'UTF-8') . '" style="max-width:110px;height:auto;display:block;margin:0 auto;">'
            : '';

        $templateVars = [
            'site_name' => htmlspecialchars((string)$email_site_name, ENT_QUOTES, 'UTF-8'),
            'logo_html' => $ciLogoHtml,
            'address'   => htmlspecialchars((string)getSetting('hotel_address', getSetting('address', '')), ENT_QUOTES, 'UTF-8'),
            'inquiry_reference' => htmlspecialchars((string)($enquiry['inquiry_reference'] ?? ''), ENT_QUOTES, 'UTF-8'),
            'company_name' => htmlspecialchars((string)($enquiry['company_name'] ?? ''), ENT_QUOTES, 'UTF-8'),
            'contact_person' => htmlspecialchars((string)($enquiry['contact_person'] ?? ''), ENT_QUOTES, 'UTF-8'),
            'conference_room' => htmlspecialchars((string)($room['name'] ?? ''), ENT_QUOTES, 'UTF-8'),
            'event_date' => htmlspecialchars(date('F j, Y', strtotime((string)$enquiry['event_date'])), ENT_QUOTES, 'UTF-8'),
            'event_time' => htmlspecialchars(date('H:i', strtotime((string)$enquiry['start_time'])) . ' - ' . date('H:i', strtotime((string)$enquiry['end_time'])), ENT_QUOTES, 'UTF-8'),
            'attendees' => (string)((int)($enquiry['number_of_attendees'] ?? 0)),
            'subtotal_amount' => htmlspecialchars($currency_symbol . ' ' . number_format($ciSubtotal, 2), ENT_QUOTES, 'UTF-8'),
            'vat_rate' => $ciVatRate > 0.0 ? number_format($ciVatRate, 1) : '0',
            'vat_amount' => $ciVatAmt > 0.0 ? htmlspecialchars(vat_document_value($currency_symbol . ' ' . number_format($ciVatAmt, 2)), ENT_QUOTES, 'UTF-8') : '—',
            'total_amount' => htmlspecialchars($currency_symbol . ' ' . number_format($ciTotalWithVat, 2), ENT_QUOTES, 'UTF-8'),
            'vat_number' => htmlspecialchars($ciVatNum, ENT_QUOTES, 'UTF-8'),
            'vat_number_html' => $ciVatNumHtml,
            'contact_email' => htmlspecialchars((string)$email_from_email, ENT_QUOTES, 'UTF-8'),
            'contact_phone' => htmlspecialchars((string)getSetting('phone_main', ''), ENT_QUOTES, 'UTF-8'),
        ];
        $dbTemplate = function_exists('renderBookingEmailTemplate')
            ? renderBookingEmailTemplate('conference_invoice', $templateVars)
            : null;

        // Prepare email content
        $subject = 'Conference Payment Invoice - ' . htmlspecialchars($email_site_name) . ' [' . $enquiry['inquiry_reference'] . ']';
        $htmlBody = '
        <div style="font-family: Arial, sans-serif; max-width: 600px; margin: 0 auto;">
            <div style="background: linear-gradient(135deg, #1A1A1A 0%, #2A2A2A 100%); padding: 30px; text-align: center; border-radius: 10px 10px 0 0;">
                <h1 style="color: #8B7355; margin: 0; font-size: 32px;">✓ PAYMENT CONFIRMED</h1>
                <p style="color: white; margin: 10px 0 0 0; font-size: 18px;">Thank you for your conference payment!</p>
            </div>

            <div style="background: #f8f9fa; padding: 30px; border:1px solid #ddd; border-top: none; border-radius: 0 0 10px 10px;">
                <p>Dear ' . htmlspecialchars($enquiry['contact_person']) . ',</p>

                <p>We are pleased to confirm that your payment has been received. Please find attached your official invoice/receipt for conference booking <strong>' . htmlspecialchars($enquiry['inquiry_reference']) . '</strong>.</p>

                <div style="background: white; padding: 20px; border-radius: 8px; margin: 20px 0; border-left: 4px solid #8B7355;">
                    <h3 style="color: #1A1A1A; margin-top: 0;">Conference Summary</h3>

                    <div style="display: flex; justify-content: space-between; padding: 8px 0; border-bottom:1px solid #eee;">
                        <span style="font-weight: bold; color: #333;">Conference Room:</span>
                        <span style="color: #666;">' . htmlspecialchars($room['name']) . '</span>
                    </div>

                    <div style="display: flex; justify-content: space-between; padding: 8px 0; border-bottom: 1px solid #eee;">
                        <span style="font-weight: bold; color: #333;">Event Date:</span>
                        <span style="color: #666;">' . date('F j, Y', strtotime($enquiry['event_date'])) . '</span>
                    </div>

                    <div style="display: flex; justify-content: space-between; padding: 8px 0; border-bottom: 1px solid #eee;">
                        <span style="font-weight: bold; color: #333;">Event Time:</span>
                        <span style="color: #666;">' . date('H:i', strtotime($enquiry['start_time'])) . ' - ' . date('H:i', strtotime($enquiry['end_time'])) . '</span>
                    </div>

                    <div style="display: flex; justify-content: space-between; padding: 15px 0;">
                        <span style="font-weight: bold; color: #8B7355; font-size: 18px;">Total Paid:</span>
                        <span style="color: #8B7355; font-weight: bold; font-size: 18px;">' . $currency_symbol . ' ' . number_format($enquiry['total_amount'], 0) . '</span>
                    </div>
                </div>

                <div style="background: #d4edda; padding: 15px; border-left: 4px solid #28a745; border-radius: 5px; margin: 20px 0;">
                    <h3 style="color: #155724; margin-top: 0;">✅ Payment Status: PAID</h3>
                    <p style="color: #155724; margin: 0;">Your conference booking is now fully paid and confirmed. We look forward to hosting your event!</p>
                </div>

                <div style="background: #e7f3ff; padding: 15px; border-left: 4px solid #0d6efd; border-radius: 5px;">
                    <h3 style="color: #0d6efd; margin-top: 0;">Next Steps</h3>
                    <ul style="color: #0d6efd; margin: 10px 0; padding-left: 20px;">
                        <li>Please save your booking reference: <strong>' . htmlspecialchars($enquiry['inquiry_reference']) . '</strong></li>
                        <li>Arrive at least 30 minutes before your event start time</li>
                        <li>Contact us if you need to make any changes</li>
                    </ul>
                </div>

                <p style="margin-top: 30px;">If you have any questions, please contact us at <a href="mailto:' . htmlspecialchars($email_from_email) . '">' . htmlspecialchars($email_from_email) . '</a>.</p>

                <p style="margin-top: 20px;">We look forward to hosting your event at <strong>' . htmlspecialchars($email_site_name) . '</strong>!</p>

                <div style="text-align: center; margin-top: 40px; padding-top: 20px; border-top: 2px solid #1A1A1A;">
                    <p style="color: #666; font-size: 14px; margin: 5px 0;"><strong>The ' . htmlspecialchars($email_site_name) . ' Team</strong></p>
                    <p style="color: #666; font-size: 14px; margin: 5px 0;"><a href="' . htmlspecialchars($email_site_url) . '">' . htmlspecialchars($email_site_url) . '</a></p>
                </div>
            </div>
        </div>';

        if ($dbTemplate) {
            $subject = $dbTemplate['subject'];
            $htmlBody = $dbTemplate['html_body'];
        }

        // Send email with attachment and CC recipients
        return sendEmailWithAttachmentAndCC(
            $enquiry['email'],
            $enquiry['contact_person'],
            $subject,
            $htmlBody,
            $invoice_file,
            $cc_recipients,
            $dbTemplate['text_body'] ?? ''
        );
    } catch (Exception $e) {
        error_log("Send Conference Invoice to Client Error: " . $e->getMessage());
        return ['success' => false, 'message' => $e->getMessage()];
    }
}

/**
 * Build HTML content for a gym membership invoice.
 * Simpler than the conference invoice (no room/attendees/AV fields) —
 * gym_inquiries only carries name/membership_type/preferred_date/guests.
 */
function buildGymInvoiceHTML(array $inquiry, string $invoice_number, string $site_name, string $email_address, string $phone_number, string $address, string $currency_symbol)
{
    global $pdo;

    $vatEnabled = in_array(getSetting('vat_enabled'), ['1', 1, true, 'true', 'on'], true);
    $vatRate = $vatEnabled ? (float)getSetting('vat_rate') : 0;

    // Genuine receipts plus settled refunds (shown negative).
    $paymentsStmt = $pdo->prepare("
        SELECT * FROM payments
        WHERE booking_type = 'gym' AND booking_id = ?
        AND deleted_at IS NULL
        AND ((payment_status IN ('completed','paid','refunded','partially_refunded') AND COALESCE(payment_type,'') <> 'refund')
             OR (payment_type = 'refund' AND refund_status IN ('completed','processing')))
        ORDER BY payment_date ASC
    ");
    $paymentsStmt->execute([$inquiry['id']]);
    $payments = rh_invoice_signed_payments($paymentsStmt->fetchAll(PDO::FETCH_ASSOC));

    $subtotal = (float)$inquiry['total_amount'];
    // Prefer the VAT breakdown locked on the record at invoice time; only
    // compute from the current mode when it was never populated.
    if ((float)($inquiry['total_with_vat'] ?? 0) > 0.001) {
        $vatRate      = (float)($inquiry['vat_rate'] ?? $vatRate);
        $vatAmount    = (float)($inquiry['vat_amount'] ?? 0);
        $totalWithVat = (float)$inquiry['total_with_vat'];
    } else {
        $vatParts = vat_components($subtotal);
        $vatRate = $vatParts['rate'];
        $vatAmount = $vatParts['vat'];
        $totalWithVat = $vatParts['total'];
    }
    $amountPaid = (float)($inquiry['amount_paid'] ?? $totalWithVat);
    $balanceDue = (float)($inquiry['amount_due'] ?? 0);

    $package = trim((string)($inquiry['membership_type'] ?? ''));
    $desc = rh_pdf_e('Gym membership') . rh_inv_sub($package !== '' ? $package : '');

    return rh_inv_flat_invoice([
        'title' => 'Gym Membership Invoice',
        'invoice_number' => $invoice_number,
        'meta' => [
            ['Member', (string)($inquiry['name'] ?? '')],
            ['Reference', (string)($inquiry['reference_number'] ?? '')],
            ['Email', (string)($inquiry['email'] ?? '')],
            ['Package', $package !== '' ? $package : 'N/A'],
            ['Phone', (string)($inquiry['phone'] ?? '')],
            ['Start date', !empty($inquiry['preferred_date']) ? date('d M Y', strtotime((string)$inquiry['preferred_date'])) : ''],
        ],
        'line_desc_html' => $desc,
        'subtotal' => $subtotal,
        'vat_enabled' => $vatEnabled,
        'vat_amount' => $vatAmount,
        'vat_rate' => $vatRate,
        'total' => $totalWithVat,
        'amount_paid' => $amountPaid,
        'amount_due' => $balanceDue,
        'deposit' => [
            'required' => !empty($inquiry['deposit_required']) && $inquiry['deposit_required'] > 0,
            'amount' => (float)($inquiry['deposit_amount'] ?? 0),
            'paid' => (float)($inquiry['deposit_paid'] ?? 0),
        ],
        'payments' => $payments,
        'closing' => 'Thank you for your payment! We look forward to seeing you at the gym.',
        'site_name' => $site_name,
        'email' => $email_address,
        'phone' => $phone_number,
        'currency' => $currency_symbol,
    ]);
}

/**
 * Generate PDF invoice for a gym membership payment (mirrors generateConferenceInvoicePDF).
 */
function generateGymInvoicePDF(int $inquiry_id)
{
    global $pdo, $tcpdf_loaded;

    try {
        $stmt = $pdo->prepare("
            SELECT gi.*, s.setting_value as site_name
            FROM gym_inquiries gi
            JOIN site_settings s ON s.setting_key = 'site_name'
            WHERE gi.id = ?
        ");
        $stmt->execute([$inquiry_id]);
        $inquiry = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$inquiry) {
            throw new Exception("Gym inquiry not found");
        }

        $site_name = getSetting('site_name');
        $email_address = getSetting('email_from_email');
        $phone_number = getSetting('phone_main');
        $address = getSetting('address_line1') . ', ' .
            getSetting('address_line2') . ', ' .
            getSetting('address_country');
        $currency_symbol = getSetting('currency_symbol');

        $invoiceDir = __DIR__ . '/../invoices';
        if (!file_exists($invoiceDir)) {
            mkdir($invoiceDir, 0755, true);
        }

        $invoice_prefix = getSetting('invoice_prefix', 'INV');
        $invoice_start = (int)getSetting('invoice_start_number', 1000);

        $invoice_number = finance_next_invoice_number($pdo, 'GYM-' . $invoice_prefix, $invoice_start, date('Y-m-d'), 'gym');
        $filename = $invoice_number . '.pdf';
        $filepath = $invoiceDir . '/' . $filename;

        if ($tcpdf_loaded) {
            $html = buildGymInvoiceHTML($inquiry, $invoice_number, $site_name, $email_address, $phone_number, $address, $currency_symbol);

            if (function_exists('bookingRenderPdfFromHtml')) {
                file_put_contents($filepath, bookingRenderPdfFromHtml($html, 'Invoice ' . $invoice_number));
            } else {
                $tcpdfClass = 'TCPDF';
                $pdf = new $tcpdfClass(PDF_PAGE_ORIENTATION, PDF_UNIT, PDF_PAGE_FORMAT, true, 'UTF-8', false);
                $pdf->SetCreator($site_name);
                $pdf->SetAuthor($site_name);
                $pdf->SetTitle('Invoice ' . $invoice_number);
                $pdf->SetSubject('Gym Membership Invoice');
                $pdf->setPrintHeader(false);
                $pdf->setPrintFooter(false);
                $pdf->SetMargins(8, 8, 8);
                $pdf->SetAutoPageBreak(true, 10);
                $pdf->SetFont('helvetica', '', 10);
                $pdf->AddPage();
                $pdf->SetFillColor(247, 243, 238);
                $pdf->Rect(0, 0, 210, 297, 'F');
                $pdf->writeHTML($html, true, false, true, false, '');
                $pdf->Output($filepath, 'F');
            }
        } else {
            $html = buildGymInvoiceHTML($inquiry, $invoice_number, $site_name, $email_address, $phone_number, $address, $currency_symbol);
            $fullHtml = '<!DOCTYPE html>
<html>
<head>
    <meta charset="UTF-8">
    <title>Invoice ' . $invoice_number . '</title>
    <style>
        body { font-family: Arial, sans-serif; margin: 0; padding: 20px; }
        .invoice-container { max-width: 800px; margin: 0 auto; border:1px solid #ddd; }
        .invoice-header { background: linear-gradient(135deg, #1A1A1A 0%, #2A2A2A 100%); color: white; padding: 30px; }
        .invoice-header h1 { margin: 0; color: #8B7355; }
        .invoice-body { padding: 30px; }
        .invoice-details { margin-bottom: 30px; }
        .invoice-row { display: flex; justify-content: space-between; padding: 10px 0; border-bottom:1px solid #eee; }
        .invoice-label { font-weight: bold; color: #333; }
        .invoice-value { color: #666; }
        .total-section { background: #f8f9fa; padding: 20px; border-radius:5px; margin-top: 20px; }
        .total-row { display: flex; justify-content: space-between; font-size: 18px; font-weight: bold; color: #8B7355; }
        .footer { text-align: center; padding: 20px; background: #f8f9fa; border-top: 1px solid #ddd; }
    </style>
</head>
<body>' . $html . '</body></html>';

            $htmlFilepath = str_replace('.pdf', '.html', $filepath);
            file_put_contents($htmlFilepath, $fullHtml);

            return [
                'filepath' => $htmlFilepath,
                'invoice_number' => $invoice_number,
                'relative_path' => 'invoices/' . basename($htmlFilepath)
            ];
        }

        return [
            'filepath' => $filepath,
            'invoice_number' => $invoice_number,
            'relative_path' => 'invoices/' . $filename
        ];
    } catch (Exception $e) {
        error_log("Generate Gym Invoice PDF Error: " . $e->getMessage());
        return false;
    }
}

/**
 * Send gym membership payment invoice email (mirrors sendConferenceInvoiceEmail).
 */
function sendGymInvoiceEmail(int $inquiry_id)
{
    global $pdo;

    try {
        $stmt = $pdo->prepare("SELECT * FROM gym_inquiries WHERE id = ?");
        $stmt->execute([$inquiry_id]);
        $inquiry = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$inquiry) {
            throw new Exception("Gym inquiry not found");
        }

        $invoice_result = generateGymInvoicePDF($inquiry_id);
        if (!$invoice_result) {
            throw new Exception("Failed to generate invoice");
        }

        $invoice_file = $invoice_result['filepath'];
        $invoice_number = $invoice_result['invoice_number'];
        $invoice_path = $invoice_result['relative_path'];

        $update_stmt = $pdo->prepare("
            UPDATE payments
            SET invoice_path = ?, invoice_number = ?, invoice_generated = 1
            WHERE booking_type = 'gym' AND booking_id = ?
            ORDER BY id DESC LIMIT 1
        ");
        $update_stmt->execute([$invoice_path, $invoice_number, $inquiry_id]);

        $invoice_recipients = getEmailSetting('invoice_recipients', '');
        $smtp_username = getEmailSetting('smtp_username', '');
        $cc_recipients = array_filter(array_map('trim', explode(',', $invoice_recipients)));
        if (!empty($smtp_username) && !in_array($smtp_username, $cc_recipients)) {
            $cc_recipients[] = $smtp_username;
        }

        $result = sendGymInvoiceEmailToClient($inquiry, $invoice_file, $cc_recipients);

        return [
            'success' => $result['success'],
            'message' => $result['message'],
            'invoice_file' => $invoice_file,
            'invoice_number' => $invoice_number,
            'invoice_path' => $invoice_path,
            'cc_recipients' => $cc_recipients
        ];
    } catch (Exception $e) {
        error_log("Send Gym Invoice Email Error: " . $e->getMessage());
        return [
            'success' => false,
            'message' => $e->getMessage()
        ];
    }
}

/**
 * Send gym invoice email to client with CC recipients (mirrors sendConferenceInvoiceEmailToClient).
 */
function sendGymInvoiceEmailToClient(array $inquiry, string $invoice_file, array $cc_recipients = [])
{
    global $email_from_email, $email_site_name, $email_site_url;

    try {
        $currency_symbol = getSetting('currency_symbol');
        $totalAmount = (float)($inquiry['total_amount'] ?? 0);
        $amountPaid = (float)($inquiry['amount_paid'] ?? $totalAmount);
        $amountDue = (float)($inquiry['amount_due'] ?? 0);

        $subject = 'Gym Membership Payment Invoice - ' . htmlspecialchars($email_site_name) . ' [' . $inquiry['reference_number'] . ']';
        $htmlBody = '
        <div style="font-family: Arial, sans-serif; max-width: 600px; margin: 0 auto;">
            <div style="background: linear-gradient(135deg, #1A1A1A 0%, #2A2A2A 100%); padding: 30px; text-align: center; border-radius: 10px 10px 0 0;">
                <h1 style="color: #8B7355; margin: 0; font-size: 32px;">&#10003; PAYMENT CONFIRMED</h1>
                <p style="color: white; margin: 10px 0 0 0; font-size: 18px;">Thank you for your gym membership payment!</p>
            </div>

            <div style="background: #f8f9fa; padding: 30px; border:1px solid #ddd; border-top: none; border-radius: 0 0 10px 10px;">
                <p>Dear ' . htmlspecialchars($inquiry['name']) . ',</p>

                <p>We are pleased to confirm that your payment has been received. Please find attached your official invoice/receipt for membership booking <strong>' . htmlspecialchars($inquiry['reference_number']) . '</strong>.</p>

                <div style="background: white; padding: 20px; border-radius: 8px; margin: 20px 0; border-left: 4px solid #8B7355;">
                    <h3 style="color: #1A1A1A; margin-top: 0;">Membership Summary</h3>

                    <div style="display: flex; justify-content: space-between; padding: 8px 0; border-bottom:1px solid #eee;">
                        <span style="font-weight: bold; color: #333;">Package:</span>
                        <span style="color: #666;">' . htmlspecialchars($inquiry['membership_type'] ?? 'N/A') . '</span>
                    </div>

                    <div style="display: flex; justify-content: space-between; padding: 15px 0;">
                        <span style="font-weight: bold; color: #8B7355; font-size: 18px;">Total Paid:</span>
                        <span style="color: #8B7355; font-weight: bold; font-size: 18px;">' . $currency_symbol . ' ' . number_format($amountPaid, 2) . '</span>
                    </div>
                </div>

                <div style="background: #d4edda; padding: 15px; border-left: 4px solid #28a745; border-radius: 5px; margin: 20px 0;">
                    <h3 style="color: #155724; margin-top: 0;">&#9989; Payment Status: ' . ($amountDue > 0 ? 'PARTIALLY PAID' : 'PAID IN FULL') . '</h3>
                    <p style="color: #155724; margin: 0;">Your gym membership booking has been recorded. We look forward to seeing you!</p>
                </div>

                <p style="margin-top: 30px;">If you have any questions, please contact us at <a href="mailto:' . htmlspecialchars($email_from_email) . '">' . htmlspecialchars($email_from_email) . '</a>.</p>

                <div style="text-align: center; margin-top: 40px; padding-top: 20px; border-top: 2px solid #1A1A1A;">
                    <p style="color: #666; font-size: 14px; margin: 5px 0;"><strong>The ' . htmlspecialchars($email_site_name) . ' Team</strong></p>
                    <p style="color: #666; font-size: 14px; margin: 5px 0;"><a href="' . htmlspecialchars($email_site_url) . '">' . htmlspecialchars($email_site_url) . '</a></p>
                </div>
            </div>
        </div>';

        return sendEmailWithAttachmentAndCC(
            $inquiry['email'],
            $inquiry['name'],
            $subject,
            $htmlBody,
            $invoice_file,
            $cc_recipients,
            ''
        );
    } catch (Exception $e) {
        error_log("Send Gym Invoice to Client Error: " . $e->getMessage());
        return ['success' => false, 'message' => $e->getMessage()];
    }
}

/**
 * Build the HTML body for an event booking invoice (mirrors buildGymInvoiceHTML).
 */
function buildEventInvoiceHTML(array $inquiry, string $invoice_number, string $site_name, string $email_address, string $phone_number, string $address, string $currency_symbol)
{
    global $pdo;

    $vatEnabled = in_array(getSetting('vat_enabled'), ['1', 1, true, 'true', 'on'], true);
    $vatRate = $vatEnabled ? (float)getSetting('vat_rate') : 0;

    // Genuine receipts plus settled refunds (shown negative).
    $paymentsStmt = $pdo->prepare("
        SELECT * FROM payments
        WHERE booking_type = 'event' AND booking_id = ?
        AND deleted_at IS NULL
        AND ((payment_status IN ('completed','paid','refunded','partially_refunded') AND COALESCE(payment_type,'') <> 'refund')
             OR (payment_type = 'refund' AND refund_status IN ('completed','processing')))
        ORDER BY payment_date ASC
    ");
    $paymentsStmt->execute([$inquiry['id']]);
    $payments = rh_invoice_signed_payments($paymentsStmt->fetchAll(PDO::FETCH_ASSOC));

    $subtotal = (float)$inquiry['total_amount'];
    // Prefer the VAT breakdown locked on the record at invoice time; only
    // compute from the current mode when it was never populated.
    if ((float)($inquiry['total_with_vat'] ?? 0) > 0.001) {
        $vatRate      = (float)($inquiry['vat_rate'] ?? $vatRate);
        $vatAmount    = (float)($inquiry['vat_amount'] ?? 0);
        $totalWithVat = (float)$inquiry['total_with_vat'];
    } else {
        $vatParts = vat_components($subtotal);
        $vatRate = $vatParts['rate'];
        $vatAmount = $vatParts['vat'];
        $totalWithVat = $vatParts['total'];
    }
    $amountPaid = (float)($inquiry['amount_paid'] ?? $totalWithVat);
    $balanceDue = (float)($inquiry['amount_due'] ?? 0);

    $guests = (int)($inquiry['guests'] ?? 1);
    $eventTitle = trim((string)($inquiry['event_title'] ?? ''));
    $desc = rh_pdf_e('Event booking' . ($eventTitle !== '' ? ' - ' . $eventTitle : ''))
        . rh_inv_sub($guests . ' attendee' . ($guests === 1 ? '' : 's'));

    return rh_inv_flat_invoice([
        'title' => 'Event Booking Invoice',
        'invoice_number' => $invoice_number,
        'meta' => [
            ['Attendee', (string)($inquiry['name'] ?? '')],
            ['Reference', (string)($inquiry['reference_number'] ?? '')],
            ['Email', (string)($inquiry['email'] ?? '')],
            ['Event', $eventTitle !== '' ? $eventTitle : 'N/A'],
            ['Phone', (string)($inquiry['phone'] ?? '')],
            ['Event date', !empty($inquiry['event_date']) ? date('d M Y', strtotime((string)$inquiry['event_date'])) : ''],
            ['Attendees', (string)$guests],
        ],
        'line_desc_html' => $desc,
        'subtotal' => $subtotal,
        'vat_enabled' => $vatEnabled,
        'vat_amount' => $vatAmount,
        'vat_rate' => $vatRate,
        'total' => $totalWithVat,
        'amount_paid' => $amountPaid,
        'amount_due' => $balanceDue,
        'deposit' => [
            'required' => !empty($inquiry['deposit_required']) && $inquiry['deposit_required'] > 0,
            'amount' => (float)($inquiry['deposit_amount'] ?? 0),
            'paid' => (float)($inquiry['deposit_paid'] ?? 0),
        ],
        'payments' => $payments,
        'closing' => 'Thank you for your payment! We look forward to seeing you at the event.',
        'site_name' => $site_name,
        'email' => $email_address,
        'phone' => $phone_number,
        'currency' => $currency_symbol,
    ]);
}

/**
 * Generate PDF invoice for an event booking payment (mirrors generateGymInvoicePDF).
 */
function generateEventInvoicePDF(int $inquiry_id)
{
    global $pdo, $tcpdf_loaded;

    try {
        $stmt = $pdo->prepare("
            SELECT ei.*, e.title AS event_title, e.event_date AS event_date, s.setting_value as site_name
            FROM event_inquiries ei
            LEFT JOIN events e ON e.id = ei.event_id
            JOIN site_settings s ON s.setting_key = 'site_name'
            WHERE ei.id = ?
        ");
        $stmt->execute([$inquiry_id]);
        $inquiry = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$inquiry) {
            throw new Exception("Event booking not found");
        }

        $site_name = getSetting('site_name');
        $email_address = getSetting('email_from_email');
        $phone_number = getSetting('phone_main');
        $address = getSetting('address_line1') . ', ' .
            getSetting('address_line2') . ', ' .
            getSetting('address_country');
        $currency_symbol = getSetting('currency_symbol');

        $invoiceDir = __DIR__ . '/../invoices';
        if (!file_exists($invoiceDir)) {
            mkdir($invoiceDir, 0755, true);
        }

        $invoice_prefix = getSetting('invoice_prefix', 'INV');
        $invoice_start = (int)getSetting('invoice_start_number', 1000);

        $invoice_number = finance_next_invoice_number($pdo, 'EVT-' . $invoice_prefix, $invoice_start, date('Y-m-d'), 'event');
        $filename = $invoice_number . '.pdf';
        $filepath = $invoiceDir . '/' . $filename;

        if ($tcpdf_loaded) {
            $html = buildEventInvoiceHTML($inquiry, $invoice_number, $site_name, $email_address, $phone_number, $address, $currency_symbol);

            if (function_exists('bookingRenderPdfFromHtml')) {
                file_put_contents($filepath, bookingRenderPdfFromHtml($html, 'Invoice ' . $invoice_number));
            } else {
                $tcpdfClass = 'TCPDF';
                $pdf = new $tcpdfClass(PDF_PAGE_ORIENTATION, PDF_UNIT, PDF_PAGE_FORMAT, true, 'UTF-8', false);
                $pdf->SetCreator($site_name);
                $pdf->SetAuthor($site_name);
                $pdf->SetTitle('Invoice ' . $invoice_number);
                $pdf->SetSubject('Event Booking Invoice');
                $pdf->setPrintHeader(false);
                $pdf->setPrintFooter(false);
                $pdf->SetMargins(8, 8, 8);
                $pdf->SetAutoPageBreak(true, 10);
                $pdf->SetFont('helvetica', '', 10);
                $pdf->AddPage();
                $pdf->SetFillColor(247, 243, 238);
                $pdf->Rect(0, 0, 210, 297, 'F');
                $pdf->writeHTML($html, true, false, true, false, '');
                $pdf->Output($filepath, 'F');
            }
        } else {
            $html = buildEventInvoiceHTML($inquiry, $invoice_number, $site_name, $email_address, $phone_number, $address, $currency_symbol);
            $fullHtml = '<!DOCTYPE html>
<html>
<head>
    <meta charset="UTF-8">
    <title>Invoice ' . $invoice_number . '</title>
    <style>
        body { font-family: Arial, sans-serif; margin: 0; padding: 20px; }
        .invoice-container { max-width: 800px; margin: 0 auto; border:1px solid #ddd; }
        .invoice-header { background: linear-gradient(135deg, #1A1A1A 0%, #2A2A2A 100%); color: white; padding: 30px; }
        .invoice-header h1 { margin: 0; color: #8B7355; }
        .invoice-body { padding: 30px; }
        .invoice-details { margin-bottom: 30px; }
        .invoice-row { display: flex; justify-content: space-between; padding: 10px 0; border-bottom:1px solid #eee; }
        .invoice-label { font-weight: bold; color: #333; }
        .invoice-value { color: #666; }
        .total-section { background: #f8f9fa; padding: 20px; border-radius:5px; margin-top: 20px; }
        .total-row { display: flex; justify-content: space-between; font-size: 18px; font-weight: bold; color: #8B7355; }
        .footer { text-align: center; padding: 20px; background: #f8f9fa; border-top: 1px solid #ddd; }
    </style>
</head>
<body>' . $html . '</body></html>';

            $htmlFilepath = str_replace('.pdf', '.html', $filepath);
            file_put_contents($htmlFilepath, $fullHtml);

            return [
                'filepath' => $htmlFilepath,
                'invoice_number' => $invoice_number,
                'relative_path' => 'invoices/' . basename($htmlFilepath)
            ];
        }

        return [
            'filepath' => $filepath,
            'invoice_number' => $invoice_number,
            'relative_path' => 'invoices/' . $filename
        ];
    } catch (Exception $e) {
        error_log("Generate Event Invoice PDF Error: " . $e->getMessage());
        return false;
    }
}

/**
 * Send event booking payment invoice email (mirrors sendGymInvoiceEmail).
 */
function sendEventInvoiceEmail(int $inquiry_id)
{
    global $pdo;

    try {
        $stmt = $pdo->prepare("
            SELECT ei.*, e.title AS event_title, e.event_date AS event_date
            FROM event_inquiries ei
            LEFT JOIN events e ON e.id = ei.event_id
            WHERE ei.id = ?
        ");
        $stmt->execute([$inquiry_id]);
        $inquiry = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$inquiry) {
            throw new Exception("Event booking not found");
        }

        $invoice_result = generateEventInvoicePDF($inquiry_id);
        if (!$invoice_result) {
            throw new Exception("Failed to generate invoice");
        }

        $invoice_file = $invoice_result['filepath'];
        $invoice_number = $invoice_result['invoice_number'];
        $invoice_path = $invoice_result['relative_path'];

        $update_stmt = $pdo->prepare("
            UPDATE payments
            SET invoice_path = ?, invoice_number = ?, invoice_generated = 1
            WHERE booking_type = 'event' AND booking_id = ?
            ORDER BY id DESC LIMIT 1
        ");
        $update_stmt->execute([$invoice_path, $invoice_number, $inquiry_id]);

        $invoice_recipients = getEmailSetting('invoice_recipients', '');
        $smtp_username = getEmailSetting('smtp_username', '');
        $cc_recipients = array_filter(array_map('trim', explode(',', $invoice_recipients)));
        if (!empty($smtp_username) && !in_array($smtp_username, $cc_recipients)) {
            $cc_recipients[] = $smtp_username;
        }

        $result = sendEventInvoiceEmailToClient($inquiry, $invoice_file, $cc_recipients);

        return [
            'success' => $result['success'],
            'message' => $result['message'],
            'invoice_file' => $invoice_file,
            'invoice_number' => $invoice_number,
            'invoice_path' => $invoice_path,
            'cc_recipients' => $cc_recipients
        ];
    } catch (Exception $e) {
        error_log("Send Event Invoice Email Error: " . $e->getMessage());
        return [
            'success' => false,
            'message' => $e->getMessage()
        ];
    }
}

/**
 * Send event invoice email to client with CC recipients (mirrors sendGymInvoiceEmailToClient).
 */
function sendEventInvoiceEmailToClient(array $inquiry, string $invoice_file, array $cc_recipients = [])
{
    global $email_from_email, $email_site_name, $email_site_url;

    try {
        $currency_symbol = getSetting('currency_symbol');
        $totalAmount = (float)($inquiry['total_amount'] ?? 0);
        $amountPaid = (float)($inquiry['amount_paid'] ?? $totalAmount);
        $amountDue = (float)($inquiry['amount_due'] ?? 0);

        $subject = 'Event Booking Payment Invoice - ' . htmlspecialchars($email_site_name) . ' [' . $inquiry['reference_number'] . ']';
        $htmlBody = '
        <div style="font-family: Arial, sans-serif; max-width: 600px; margin: 0 auto;">
            <div style="background: linear-gradient(135deg, #1A1A1A 0%, #2A2A2A 100%); padding: 30px; text-align: center; border-radius: 10px 10px 0 0;">
                <h1 style="color: #8B7355; margin: 0; font-size: 32px;">&#10003; PAYMENT CONFIRMED</h1>
                <p style="color: white; margin: 10px 0 0 0; font-size: 18px;">Thank you for your event booking payment!</p>
            </div>

            <div style="background: #f8f9fa; padding: 30px; border:1px solid #ddd; border-top: none; border-radius: 0 0 10px 10px;">
                <p>Dear ' . htmlspecialchars($inquiry['name']) . ',</p>

                <p>We are pleased to confirm that your payment has been received. Please find attached your official invoice/receipt for event booking <strong>' . htmlspecialchars($inquiry['reference_number']) . '</strong>.</p>

                <div style="background: white; padding: 20px; border-radius: 8px; margin: 20px 0; border-left: 4px solid #8B7355;">
                    <h3 style="color: #1A1A1A; margin-top: 0;">Booking Summary</h3>

                    <div style="display: flex; justify-content: space-between; padding: 8px 0; border-bottom:1px solid #eee;">
                        <span style="font-weight: bold; color: #333;">Event:</span>
                        <span style="color: #666;">' . htmlspecialchars($inquiry['event_title'] ?? 'N/A') . '</span>
                    </div>

                    <div style="display: flex; justify-content: space-between; padding: 15px 0;">
                        <span style="font-weight: bold; color: #8B7355; font-size: 18px;">Total Paid:</span>
                        <span style="color: #8B7355; font-weight: bold; font-size: 18px;">' . $currency_symbol . ' ' . number_format($amountPaid, 2) . '</span>
                    </div>
                </div>

                <div style="background: #d4edda; padding: 15px; border-left: 4px solid #28a745; border-radius: 5px; margin: 20px 0;">
                    <h3 style="color: #155724; margin-top: 0;">&#9989; Payment Status: ' . ($amountDue > 0 ? 'PARTIALLY PAID' : 'PAID IN FULL') . '</h3>
                    <p style="color: #155724; margin: 0;">Your event booking has been recorded. We look forward to seeing you!</p>
                </div>

                <p style="margin-top: 30px;">If you have any questions, please contact us at <a href="mailto:' . htmlspecialchars($email_from_email) . '">' . htmlspecialchars($email_from_email) . '</a>.</p>

                <div style="text-align: center; margin-top: 40px; padding-top: 20px; border-top: 2px solid #1A1A1A;">
                    <p style="color: #666; font-size: 14px; margin: 5px 0;"><strong>The ' . htmlspecialchars($email_site_name) . ' Team</strong></p>
                    <p style="color: #666; font-size: 14px; margin: 5px 0;"><a href="' . htmlspecialchars($email_site_url) . '">' . htmlspecialchars($email_site_url) . '</a></p>
                </div>
            </div>
        </div>';

        return sendEmailWithAttachmentAndCC(
            $inquiry['email'],
            $inquiry['name'],
            $subject,
            $htmlBody,
            $invoice_file,
            $cc_recipients,
            ''
        );
    } catch (Exception $e) {
        error_log("Send Event Invoice to Client Error: " . $e->getMessage());
        return ['success' => false, 'message' => $e->getMessage()];
    }
}

/**
 * Generate a gym membership quotation PDF (mirrors generateConferenceQuotationPDF —
 * in-memory only, no invoice-number sequence since a quotation isn't a payment record).
 *
 * @param array $inquiry Row from gym_inquiries.
 * @param array $options Optional keys: valid_days, quotation_notes, quote_reference.
 * @return string Raw PDF binary.
 */
function generateGymQuotationPDF(array $inquiry, array $options = []): string
{
    hotel_load_tcpdf();

    $siteName = (string)getSetting('site_name', "Rosalyn's Beach Hotel");
    $sitePhone = (string)getSetting('phone_main', '');
    $siteEmail = (string)getSetting('email_main', getSetting('email_from_email', ''));
    $siteAddress = trim((string)getSetting('address_line1', ''));
    $currency = (string)getSetting('currency_symbol', 'MWK ');
    $paymentPolicy = (string)getSetting('payment_policy', 'Payment terms apply as agreed with our reception team.');

    $validDays = max(1, (int)($options['valid_days'] ?? 7));
    $validUntil = (new DateTime())->modify('+' . $validDays . ' days');
    $notes = trim((string)($options['quotation_notes'] ?? ($inquiry['notes'] ?? '')));
    $quoteRef = trim((string)($options['quote_reference'] ?? ''));
    if ($quoteRef === '') {
        $quoteRef = 'GQ-' . strtoupper((string)($inquiry['reference_number'] ?? ('GYM-' . (int)($inquiry['id'] ?? 0))));
    }

    $baseAmount = (float)($inquiry['total_amount'] ?? 0);
    $vatRate = (float)($inquiry['vat_rate'] ?? 0);
    $vatAmount = (float)($inquiry['vat_amount'] ?? 0);
    if ($vatAmount <= 0 && $vatRate > 0) {
        // Fallback derives per installation mode (on top / extracted / off).
        $vatAmount = vat_components($baseAmount)['vat'];
    }
    $totalAmount = (float)($inquiry['total_with_vat'] ?? 0);
    if ($totalAmount <= 0) {
        $totalAmount = (function_exists('vat_mode') && vat_mode() === 'inclusive')
            ? $baseAmount
            : $baseAmount + $vatAmount;
    }
    $depositAmount = (float)($inquiry['deposit_amount'] ?? 0);
    $membershipType = (string)($inquiry['membership_type'] ?? 'Gym Membership');

    $html = rh_inv_quotation_html([
        'title' => 'Gym Membership Quotation',
        'quote_ref' => $quoteRef,
        'valid_until' => $validUntil,
        'meta' => [
            ['Prepared for', (string)($inquiry['name'] ?? 'Member')],
            ['Inquiry ref', (string)($inquiry['reference_number'] ?? '')],
            ['Phone', (string)($inquiry['phone'] ?? '')],
            ['Email', (string)($inquiry['email'] ?? '')],
            ['Package', $membershipType],
        ],
        'line_desc_html' => rh_pdf_e('Membership package') . rh_inv_sub($membershipType),
        'base' => $baseAmount,
        'vat_amount' => $vatAmount,
        'vat_rate' => $vatRate,
        'total' => $totalAmount,
        'deposit_amount' => (!empty($inquiry['deposit_required']) && $depositAmount > 0) ? $depositAmount : 0.0,
        'payment_policy' => $paymentPolicy,
        'notes' => $notes,
    ]);

    return bookingRenderPdfFromHtml($html, 'Gym Quotation ' . $quoteRef, array_intersect_key($options, ['watermark' => true]));
}

/**
 * Generate an event booking quotation PDF (mirrors generateGymQuotationPDF).
 *
 * @param array $inquiry Row from event_inquiries.
 * @param array $options Optional keys: valid_days, quotation_notes, quote_reference.
 * @return string Raw PDF binary.
 */
function generateEventInquiryQuotationPDF(array $inquiry, array $options = []): string
{
    hotel_load_tcpdf();

    $siteName = (string)getSetting('site_name', "Rosalyn's Beach Hotel");
    $sitePhone = (string)getSetting('phone_main', '');
    $siteEmail = (string)getSetting('email_main', getSetting('email_from_email', ''));
    $siteAddress = trim((string)getSetting('address_line1', ''));
    $currency = (string)getSetting('currency_symbol', 'MWK ');
    $paymentPolicy = (string)getSetting('payment_policy', 'Payment terms apply as agreed with our reception team.');

    $validDays = max(1, (int)($options['valid_days'] ?? 7));
    $validUntil = (new DateTime())->modify('+' . $validDays . ' days');
    $notes = trim((string)($options['quotation_notes'] ?? ($inquiry['notes'] ?? '')));
    $quoteRef = trim((string)($options['quote_reference'] ?? ''));
    if ($quoteRef === '') {
        $quoteRef = 'EQ-' . strtoupper((string)($inquiry['reference_number'] ?? ('EVT-' . (int)($inquiry['id'] ?? 0))));
    }

    $baseAmount = (float)($inquiry['total_amount'] ?? 0);
    $vatRate = (float)($inquiry['vat_rate'] ?? 0);
    $vatAmount = (float)($inquiry['vat_amount'] ?? 0);
    if ($vatAmount <= 0 && $vatRate > 0) {
        // Fallback derives per installation mode (on top / extracted / off).
        $vatAmount = vat_components($baseAmount)['vat'];
    }
    $totalAmount = (float)($inquiry['total_with_vat'] ?? 0);
    if ($totalAmount <= 0) {
        $totalAmount = (function_exists('vat_mode') && vat_mode() === 'inclusive')
            ? $baseAmount
            : $baseAmount + $vatAmount;
    }
    $depositAmount = (float)($inquiry['deposit_amount'] ?? 0);
    $eventTitle = (string)($inquiry['event_title'] ?? 'Event Booking');
    $guests = max(1, (int)($inquiry['guests'] ?? 1));

    $html = rh_inv_quotation_html([
        'title' => 'Event Booking Quotation',
        'quote_ref' => $quoteRef,
        'valid_until' => $validUntil,
        'meta' => [
            ['Prepared for', (string)($inquiry['name'] ?? 'Attendee')],
            ['Inquiry ref', (string)($inquiry['reference_number'] ?? '')],
            ['Phone', (string)($inquiry['phone'] ?? '')],
            ['Email', (string)($inquiry['email'] ?? '')],
            ['Event', $eventTitle],
            ['Attendees', (string)$guests],
        ],
        'line_desc_html' => rh_pdf_e('Event booking') . rh_inv_sub($eventTitle . ' | ' . $guests . ' attendee' . ($guests === 1 ? '' : 's')),
        'base' => $baseAmount,
        'vat_amount' => $vatAmount,
        'vat_rate' => $vatRate,
        'total' => $totalAmount,
        'deposit_amount' => (!empty($inquiry['deposit_required']) && $depositAmount > 0) ? $depositAmount : 0.0,
        'payment_policy' => $paymentPolicy,
        'notes' => $notes,
    ]);

    return bookingRenderPdfFromHtml($html, 'Event Quotation ' . $quoteRef, array_intersect_key($options, ['watermark' => true]));
}
