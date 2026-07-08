<?php

/**
 * Credit Notes — Core Business Logic
 *
 * Functions:
 *  issueCreditNote()        — create a CN record, generate number, optionally PDF + email
 *  applyCreditNote()        — redeem a CN against a booking (creates payments row)
 *  voidCreditNote()         — void an active/partially_applied CN
 *  getCreditNoteBalance()   — return available balance for a CN
 *  checkExpiredCreditNotes()— batch-expire CNs whose expires_at has passed
 *  generateCreditNotePDF()  — TCPDF credit note document
 *  sendCreditNoteEmail()    — email CN PDF to guest
 */

require_once __DIR__ . '/database.php';
require_once __DIR__ . '/email.php';
require_once __DIR__ . '/../includes/finance-sequences.php';

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception as PHPMailerException;

// ─────────────────────────────────────────────────────────────────────────────
// issueCreditNote
// ─────────────────────────────────────────────────────────────────────────────
if (!function_exists('issueCreditNote')) {
    /**
     * Issue a new credit note.
     *
     * @param PDO   $pdo
     * @param array $data {
     *   booking_id?         int    — originating booking (NULL for goodwill)
     *   booking_reference?  string
     *   booking_type?       string room|conference|restaurant|goodwill
     *   guest_name          string
     *   guest_email?        string
     *   amount              float  — face value (incl. VAT)
     *   vat_rate?           float  — if omitted, reads vat_rate site_setting
     *   reason              string — cancellation|service_issue|early_checkout|overpayment|goodwill|pricing_error|other
     *   reason_notes?       string
     *   expires_at?         string — Y-m-d; if omitted, calculated from credit_note_expiry_months setting
     *   original_payment_id? int   — refund payment row that triggered this CN
     *   issued_by           int    — admin user ID
     *   send_email?         bool   — default false; set true to auto-email guest
     *   generate_pdf?       bool   — default true
     * }
     * @return array ['success'=>bool, 'credit_note_id'=>int|null, 'credit_note_number'=>string|null, 'error'=>string|null]
     */
    function issueCreditNote(PDO $pdo, array $data): array
    {
        try {
            $amount = round((float)($data['amount'] ?? 0), 2);
            if ($amount <= 0) {
                throw new RuntimeException('Credit note amount must be greater than zero.');
            }

            $guestName = trim((string)($data['guest_name'] ?? ''));
            if ($guestName === '') {
                throw new RuntimeException('Guest name is required.');
            }

            $validReasons = ['cancellation', 'service_issue', 'early_checkout', 'overpayment', 'goodwill', 'pricing_error', 'other'];
            $reason = trim((string)($data['reason'] ?? 'other'));
            if (!in_array($reason, $validReasons, true)) {
                throw new RuntimeException('Invalid credit note reason.');
            }

            $bookingType = trim((string)($data['booking_type'] ?? 'goodwill'));
            if (!in_array($bookingType, ['room', 'conference', 'restaurant', 'goodwill'], true)) {
                $bookingType = 'goodwill';
            }

            // VAT breakdown — stored for reporting but CN amount is total (incl. VAT)
            $vatEnabled = in_array(getSetting('vat_enabled'), ['1', 1, true, 'true', 'on'], true);
            $vatRate    = isset($data['vat_rate']) ? (float)$data['vat_rate'] : ($vatEnabled ? (float)getSetting('vat_rate') : 0.0);
            $vatAmount  = $vatRate > 0 ? round($amount * ($vatRate / (100 + $vatRate)), 2) : 0.0;

            // Expiry
            $expiresAt = null;
            if (isset($data['expires_at']) && $data['expires_at'] !== '') {
                $expiresAt = $data['expires_at'];
            } else {
                $months = (int)getSetting('credit_note_expiry_months', '12');
                if ($months > 0) {
                    $expiresAt = date('Y-m-d', strtotime("+{$months} months"));
                }
            }

            $issuedBy = (int)($data['issued_by'] ?? 0);
            if ($issuedBy <= 0) {
                throw new RuntimeException('issued_by (admin user ID) is required.');
            }

            // Ensure sequence tables exist before allocating a number
            finance_ensure_sequence_tables($pdo);

            $cnNumber = finance_next_credit_note_number($pdo);

            $stmt = $pdo->prepare("
                INSERT INTO credit_notes (
                    credit_note_number, booking_id, booking_reference, booking_type,
                    guest_name, guest_email,
                    original_amount, amount_used, balance,
                    vat_rate, vat_amount,
                    reason, reason_notes, status,
                    issued_by, issued_at, expires_at,
                    original_payment_id
                ) VALUES (
                    ?, ?, ?, ?,
                    ?, ?,
                    ?, 0.00, ?,
                    ?, ?,
                    ?, ?, 'active',
                    ?, NOW(), ?,
                    ?
                )
            ");
            $stmt->execute([
                $cnNumber,
                $data['booking_id'] ?? null,
                $data['booking_reference'] ?? null,
                $bookingType,
                $guestName,
                $data['guest_email'] ?? null,
                $amount,
                $amount,          // original_amount, balance
                $vatRate,
                $vatAmount,
                $reason,
                $data['reason_notes'] ?? null,
                $issuedBy,
                $expiresAt,
                $data['original_payment_id'] ?? null,
            ]);
            $cnId = (int)$pdo->lastInsertId();

            rh_log_event('credit-notes', 'info', "Credit note {$cnNumber} issued", [
                'credit_note_id'     => $cnId,
                'credit_note_number' => $cnNumber,
                'amount'             => $amount,
                'guest_name'         => $guestName,
                'reason'             => $reason,
                'booking_id'         => $data['booking_id'] ?? null,
                'issued_by'          => $issuedBy,
            ]);

            // Booking timeline log (room bookings only)
            $tlBookingId = (int)($data['booking_id'] ?? 0);
            $tlBookingRef = trim((string)($data['booking_reference'] ?? ''));
            if ($tlBookingId > 0 && $bookingType === 'room' && function_exists('logBookingEvent')) {
                if ($tlBookingRef === '') {
                    $r = $pdo->prepare("SELECT booking_reference FROM bookings WHERE id = ?");
                    $r->execute([$tlBookingId]);
                    $tlBookingRef = (string)($r->fetchColumn() ?: '');
                }
                logBookingEvent(
                    $tlBookingId,
                    $tlBookingRef,
                    'credit_note_issued',
                    'financial',
                    "Credit note {$cnNumber} issued for " . getSetting('currency_symbol', 'MWK') . ' ' . number_format($amount, 2) . " (reason: {$reason})",
                    null,
                    $cnNumber,
                    'admin',
                    $issuedBy
                );
            }

            // Generate PDF
            $generatePdf = ($data['generate_pdf'] ?? true) !== false;
            if ($generatePdf) {
                generateCreditNotePDF($pdo, $cnId);
            }

            // Send email
            if (!empty($data['send_email']) && !empty($data['guest_email'])) {
                sendCreditNoteEmail($pdo, $cnId);
            }

            return [
                'success'            => true,
                'credit_note_id'     => $cnId,
                'credit_note_number' => $cnNumber,
                'error'              => null,
            ];
        } catch (Throwable $e) {
            error_log('[credit-notes] issueCreditNote: ' . $e->getMessage());
            return ['success' => false, 'credit_note_id' => null, 'credit_note_number' => null, 'error' => $e->getMessage()];
        }
    }
}

// ─────────────────────────────────────────────────────────────────────────────
// applyCreditNote
// ─────────────────────────────────────────────────────────────────────────────
if (!function_exists('applyCreditNote')) {
    /**
     * Redeem a credit note (partial or full) against a booking.
     * Creates a payments row with payment_method='credit_note'.
     *
     * @param PDO   $pdo
     * @param int   $creditNoteId
     * @param array $bookingData {
     *   booking_id    int
     *   booking_type  string  room|conference|restaurant
     *   booking_reference? string
     * }
     * @param float  $amountToApply
     * @param int    $adminUserId
     * @param string $notes
     * @return array ['success'=>bool, 'payment_id'=>int|null, 'remaining_balance'=>float, 'error'=>string|null]
     */
    function applyCreditNote(PDO $pdo, int $creditNoteId, array $bookingData, float $amountToApply, int $adminUserId, string $notes = ''): array
    {
        try {
            $amountToApply = round($amountToApply, 2);
            if ($amountToApply <= 0) {
                throw new RuntimeException('Amount to apply must be greater than zero.');
            }

            $bookingId   = (int)($bookingData['booking_id'] ?? 0);
            $bookingType = trim((string)($bookingData['booking_type'] ?? ''));
            if (!in_array($bookingType, ['room', 'conference', 'restaurant'], true)) {
                throw new RuntimeException('Invalid booking type for credit note application.');
            }
            if ($bookingId <= 0) {
                throw new RuntimeException('Valid booking ID is required.');
            }

            $pdo->beginTransaction();

            // Lock the CN row
            $cnStmt = $pdo->prepare("SELECT * FROM credit_notes WHERE id = ? FOR UPDATE");
            $cnStmt->execute([$creditNoteId]);
            $cn = $cnStmt->fetch(PDO::FETCH_ASSOC);

            if (!$cn) {
                throw new RuntimeException('Credit note not found.');
            }
            if (!in_array((string)$cn['status'], ['active', 'partially_applied'], true)) {
                throw new RuntimeException('This credit note is ' . $cn['status'] . ' and cannot be applied.');
            }
            if ($cn['expires_at'] !== null && $cn['expires_at'] < date('Y-m-d')) {
                // Auto-expire
                $pdo->prepare("UPDATE credit_notes SET status='expired', updated_at=NOW() WHERE id=?")->execute([$creditNoteId]);
                $pdo->commit();
                throw new RuntimeException('This credit note has expired and can no longer be used.');
            }

            $availableBalance = round((float)$cn['balance'], 2);
            if ($amountToApply > $availableBalance) {
                throw new RuntimeException('Amount to apply (' . number_format($amountToApply, 2) . ') exceeds available balance (' . number_format($availableBalance, 2) . ').');
            }

            // Resolve booking reference
            $bookingReference = $bookingData['booking_reference'] ?? '';
            if ($bookingReference === '') {
                if ($bookingType === 'room') {
                    $refRow = $pdo->prepare("SELECT booking_reference FROM bookings WHERE id = ?");
                    $refRow->execute([$bookingId]);
                    $bookingReference = (string)($refRow->fetchColumn() ?: '');
                } elseif ($bookingType === 'conference') {
                    // Try common column names
                    foreach (['reference_number', 'enquiry_reference', 'conference_reference', 'id'] as $col) {
                        try {
                            $refRow = $pdo->prepare("SELECT {$col} FROM conference_inquiries WHERE id = ?");
                            $refRow->execute([$bookingId]);
                            $val = $refRow->fetchColumn();
                            if ($val !== false) {
                                $bookingReference = (string)$val;
                                break;
                            }
                        } catch (Throwable $ignored) {
                        }
                    }
                }
            }

            // VAT split — use the rate LOCKED on the credit note at issue time so the
            // application mirrors the original refund's tax treatment; fall back to
            // the current setting only for legacy notes issued without a rate.
            $vatEnabled = in_array(getSetting('vat_enabled'), ['1', 1, true, 'true', 'on'], true);
            $vatRate    = (float)($cn['vat_rate'] ?? 0) > 0
                ? (float)$cn['vat_rate']
                : ($vatEnabled ? (float)getSetting('vat_rate') : 0.0);
            $vatAmount  = $vatRate > 0 ? round($amountToApply * ($vatRate / (100 + $vatRate)), 2) : 0.0;
            $netAmount  = $amountToApply - $vatAmount;

            // Payment type logic
            $outstandingAmount = 0.0;
            if ($bookingType === 'room') {
                $bkRow = $pdo->prepare("SELECT amount_due FROM bookings WHERE id = ?");
                $bkRow->execute([$bookingId]);
                $outstandingAmount = (float)($bkRow->fetchColumn() ?: 0);
            } elseif ($bookingType === 'conference') {
                $ciRow = $pdo->prepare("SELECT amount_due FROM conference_inquiries WHERE id = ?");
                $ciRow->execute([$bookingId]);
                $outstandingAmount = (float)($ciRow->fetchColumn() ?: 0);
            }
            $paymentType = ($amountToApply >= $outstandingAmount - 0.01 && $outstandingAmount > 0)
                ? 'full_payment'
                : 'partial_payment';

            // Generate payment reference
            do {
                $payRef = 'CNPAY' . date('Ym') . strtoupper(substr(uniqid(), -5));
                $refCheck = $pdo->prepare("SELECT COUNT(*) FROM payments WHERE payment_reference = ?");
                $refCheck->execute([$payRef]);
            } while ((int)$refCheck->fetchColumn() > 0);

            // Insert payments row
            $payStmt = $pdo->prepare("
                INSERT INTO payments (
                    payment_reference, booking_type, booking_id, booking_reference,
                    payment_date, payment_amount, vat_rate, vat_amount, total_amount,
                    payment_method, payment_type, payment_status, status,
                    credit_note_id, notes, recorded_by, created_at
                ) VALUES (
                    ?, ?, ?, ?,
                    CURDATE(), ?, ?, ?, ?,
                    'credit_note', ?, 'completed', 'completed',
                    ?, ?, ?, NOW()
                )
            ");
            $payStmt->execute([
                $payRef,
                $bookingType,
                $bookingId,
                $bookingReference,
                $netAmount,
                $vatRate,
                $vatAmount,
                $amountToApply,
                $paymentType,
                $creditNoteId,
                $notes ?: "Credit note {$cn['credit_note_number']} applied",
                $adminUserId,
            ]);
            $paymentId = (int)$pdo->lastInsertId();

            // Deduct from CN balance
            $newUsed    = round((float)$cn['amount_used'] + $amountToApply, 2);
            $newBalance = round((float)$cn['original_amount'] - $newUsed, 2);
            $newStatus  = $newBalance <= 0.005 ? 'fully_applied' : 'partially_applied';
            $pdo->prepare("UPDATE credit_notes SET amount_used=?, balance=?, status=?, updated_at=NOW() WHERE id=?")
                ->execute([$newUsed, max(0, $newBalance), $newStatus, $creditNoteId]);

            // Insert application ledger row
            $appStmt = $pdo->prepare("
                INSERT INTO credit_note_applications (
                    credit_note_id, payment_id, applied_to_booking_id,
                    applied_to_booking_reference, applied_to_booking_type,
                    amount_applied, applied_by, applied_at, notes
                ) VALUES (?, ?, ?, ?, ?, ?, ?, NOW(), ?)
            ");
            $appStmt->execute([
                $creditNoteId,
                $paymentId,
                $bookingId,
                $bookingReference,
                $bookingType,
                $amountToApply,
                $adminUserId,
                $notes ?: null,
            ]);

            // Recalculate booking balances — every receivable type must resync or the
            // applied credit shows in the ledger but the account still reads unpaid.
            $syncInclude = __DIR__ . '/../admin/includes/finance-account-sync.php';
            if (is_file($syncInclude)) {
                require_once $syncInclude;
            }
            if ($bookingType === 'room') {
                if (function_exists('recalculateBookingFinancials')) {
                    recalculateBookingFinancials($bookingId);
                } elseif (function_exists('updateRoomBookingPayments')) {
                    updateRoomBookingPayments($pdo, $bookingId);
                }
            } elseif ($bookingType === 'conference') {
                if (function_exists('syncConferenceInquiryPaymentSnapshot')) {
                    syncConferenceInquiryPaymentSnapshot($pdo, $bookingId);
                } elseif (function_exists('updateConferenceEnquiryPayments')) {
                    updateConferenceEnquiryPayments($pdo, $bookingId);
                }
            } elseif ($bookingType === 'gym' && function_exists('syncGymInquiryPaymentSnapshot')) {
                syncGymInquiryPaymentSnapshot($pdo, $bookingId);
            } elseif ($bookingType === 'event' && function_exists('syncEventInquiryPaymentSnapshot')) {
                syncEventInquiryPaymentSnapshot($pdo, $bookingId);
            }

            $pdo->commit();

            rh_log_event('credit-notes', 'info', "Credit note {$cn['credit_note_number']} applied", [
                'credit_note_id'   => $creditNoteId,
                'amount_applied'   => $amountToApply,
                'booking_id'       => $bookingId,
                'booking_type'     => $bookingType,
                'booking_reference' => $bookingReference,
                'payment_id'       => $paymentId,
                'new_balance'      => $newBalance,
                'applied_by'       => $adminUserId,
            ]);

            // Booking timeline log (room bookings)
            if ($bookingId > 0 && $bookingType === 'room' && function_exists('logBookingEvent')) {
                logBookingEvent(
                    $bookingId,
                    $bookingReference,
                    'credit_note_applied',
                    'payment',
                    "Credit note {$cn['credit_note_number']} applied: " . getSetting('currency_symbol', 'MWK') . ' ' . number_format($amountToApply, 2) . " (remaining balance: " . number_format(max(0, $newBalance), 2) . ")",
                    null,
                    $cn['credit_note_number'],
                    'admin',
                    $adminUserId
                );
            }

            return [
                'success'           => true,
                'payment_id'        => $paymentId,
                'remaining_balance' => max(0.0, $newBalance),
                'error'             => null,
            ];
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            error_log('[credit-notes] applyCreditNote: ' . $e->getMessage());
            return ['success' => false, 'payment_id' => null, 'remaining_balance' => 0.0, 'error' => $e->getMessage()];
        }
    }
}

// ─────────────────────────────────────────────────────────────────────────────
// voidCreditNote
// ─────────────────────────────────────────────────────────────────────────────
if (!function_exists('voidCreditNote')) {
    /**
     * Void a credit note. Only active or partially_applied CNs can be voided.
     */
    function voidCreditNote(PDO $pdo, int $creditNoteId, string $reason, int $adminUserId): array
    {
        try {
            if (trim($reason) === '') {
                throw new RuntimeException('A void reason is required.');
            }
            $cn = $pdo->prepare("SELECT * FROM credit_notes WHERE id = ?");
            $cn->execute([$creditNoteId]);
            $cn = $cn->fetch(PDO::FETCH_ASSOC);
            if (!$cn) {
                throw new RuntimeException('Credit note not found.');
            }
            if (!in_array((string)$cn['status'], ['active', 'partially_applied'], true)) {
                throw new RuntimeException('Only active or partially applied credit notes can be voided. This one is: ' . $cn['status']);
            }
            $pdo->prepare("
                UPDATE credit_notes
                SET status='voided', voided_at=NOW(), voided_by=?, void_reason=?, updated_at=NOW()
                WHERE id=?
            ")->execute([$adminUserId, mb_substr($reason, 0, 1000), $creditNoteId]);

            rh_log_event('credit-notes', 'info', "Credit note {$cn['credit_note_number']} voided", [
                'credit_note_id' => $creditNoteId,
                'voided_by'      => $adminUserId,
                'reason'         => $reason,
            ]);

            // Booking timeline log if CN was linked to a room booking
            $voidBookingId = (int)($cn['booking_id'] ?? 0);
            if ($voidBookingId > 0 && (string)$cn['booking_type'] === 'room' && function_exists('logBookingEvent')) {
                $voidRef = trim((string)($cn['booking_reference'] ?? ''));
                if ($voidRef === '') {
                    $r = $pdo->prepare("SELECT booking_reference FROM bookings WHERE id = ?");
                    $r->execute([$voidBookingId]);
                    $voidRef = (string)($r->fetchColumn() ?: '');
                }
                logBookingEvent(
                    $voidBookingId,
                    $voidRef,
                    'credit_note_voided',
                    'financial',
                    "Credit note {$cn['credit_note_number']} voided. Reason: {$reason}",
                    'active',
                    'voided',
                    'admin',
                    $adminUserId
                );
            }

            return ['success' => true, 'error' => null];
        } catch (Throwable $e) {
            error_log('[credit-notes] voidCreditNote: ' . $e->getMessage());
            return ['success' => false, 'error' => $e->getMessage()];
        }
    }
}

// ─────────────────────────────────────────────────────────────────────────────
// getCreditNoteBalance
// ─────────────────────────────────────────────────────────────────────────────
if (!function_exists('getCreditNoteBalance')) {
    function getCreditNoteBalance(PDO $pdo, int $creditNoteId): float
    {
        $stmt = $pdo->prepare("SELECT balance, status, expires_at FROM credit_notes WHERE id = ?");
        $stmt->execute([$creditNoteId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$row) {
            return 0.0;
        }
        if (!in_array((string)$row['status'], ['active', 'partially_applied'], true)) {
            return 0.0;
        }
        if ($row['expires_at'] !== null && $row['expires_at'] < date('Y-m-d')) {
            return 0.0;
        }
        return max(0.0, (float)$row['balance']);
    }
}

// ─────────────────────────────────────────────────────────────────────────────
// checkExpiredCreditNotes
// ─────────────────────────────────────────────────────────────────────────────
if (!function_exists('checkExpiredCreditNotes')) {
    /**
     * Batch-expire credit notes whose expires_at has passed.
     * Safe to call on every page load (fast if nothing to do).
     *
     * @return int number of CNs updated to 'expired'
     */
    function checkExpiredCreditNotes(PDO $pdo): int
    {
        try {
            $stmt = $pdo->prepare("
                UPDATE credit_notes
                SET status='expired', updated_at=NOW()
                WHERE status IN ('active','partially_applied')
                  AND expires_at IS NOT NULL
                  AND expires_at < CURDATE()
            ");
            $stmt->execute();
            return $stmt->rowCount();
        } catch (Throwable $e) {
            error_log('[credit-notes] checkExpiredCreditNotes: ' . $e->getMessage());
            return 0;
        }
    }
}

// ─────────────────────────────────────────────────────────────────────────────
// generateCreditNotePDF
// ─────────────────────────────────────────────────────────────────────────────
if (!function_exists('generateCreditNotePDF')) {
    /**
     * Generate a TCPDF-based credit note PDF.
     * Saves to invoices/credit-notes/CN-YYYY-000001.pdf
     *
     * @return array|false ['pdf_path'=>string, 'relative_path'=>string] or false on failure
     */
    function generateCreditNotePDF(PDO $pdo, int $creditNoteId)
    {
        try {
            // Ensure TCPDF class and constants are available
            if (!class_exists('TCPDF') || !defined('PDF_PAGE_ORIENTATION')) {
                $tcpdfFile = __DIR__ . '/../vendor/tecnickcom/tcpdf/tcpdf.php';
                if (!file_exists($tcpdfFile)) {
                    $tcpdfFile = __DIR__ . '/../TCPDF/tcpdf.php';
                }
                if (file_exists($tcpdfFile)) {
                    require_once $tcpdfFile;
                }
            }
            $tcpdfAvailable = class_exists('TCPDF') && defined('PDF_PAGE_ORIENTATION');
            $cn = $pdo->prepare("SELECT * FROM credit_notes WHERE id = ?");
            $cn->execute([$creditNoteId]);
            $cn = $cn->fetch(PDO::FETCH_ASSOC);
            if (!$cn) {
                throw new RuntimeException('Credit note not found.');
            }

            // Site info
            $siteName    = getSetting('site_name')    ?: 'The Hotel';
            $siteAddress = getSetting('site_address') ?: '';
            $sitePhone   = getSetting('site_phone')   ?: '';
            $siteEmail   = getSetting('contact_email') ?: getSetting('smtp_username') ?: '';
            $siteWebsite = getSetting('site_url')     ?: '';
            $currencySymbol = getSetting('currency_symbol') ?: 'MWK';
            $vatNumber   = getSetting('vat_number')   ?: '';

            // Output directory
            $outputDir = __DIR__ . '/../invoices/credit-notes/';
            if (!is_dir($outputDir)) {
                mkdir($outputDir, 0755, true);
            }

            $filename     = $cn['credit_note_number'] . '.pdf';
            $fullPath     = $outputDir . $filename;
            $relativePath = 'invoices/credit-notes/' . $filename;

            // Applications history for this CN
            $appStmt = $pdo->prepare("
                SELECT cna.*, au.full_name AS applied_by_name
                FROM credit_note_applications cna
                LEFT JOIN admin_users au ON au.id = cna.applied_by
                WHERE cna.credit_note_id = ?
                ORDER BY cna.applied_at ASC
            ");
            $appStmt->execute([$creditNoteId]);
            $applications = $appStmt->fetchAll(PDO::FETCH_ASSOC);

            if ($tcpdfAvailable && function_exists('hotel_default_credit_note_document_html') && function_exists('renderBookingDocumentTemplate') && function_exists('bookingRenderPdfFromHtml')) {
                $logoSrc = function_exists('hotel_invoice_logo_src') ? hotel_invoice_logo_src() : '';
                $logoHtml = $logoSrc !== ''
                    ? '<img src="' . htmlspecialchars($logoSrc, ENT_QUOTES, 'UTF-8') . '" alt="' . htmlspecialchars($siteName, ENT_QUOTES, 'UTF-8') . '" height="64" style="height:64px;width:auto;display:block;margin:0 auto;">'
                    : '';

                $html = renderBookingDocumentTemplate('credit_note_document', [
                    'logo_html' => $logoHtml,
                    'site_name' => htmlspecialchars($siteName, ENT_QUOTES, 'UTF-8'),
                    'address' => htmlspecialchars($siteAddress, ENT_QUOTES, 'UTF-8'),
                    'contact_email' => htmlspecialchars($siteEmail, ENT_QUOTES, 'UTF-8'),
                    'contact_phone' => htmlspecialchars($sitePhone, ENT_QUOTES, 'UTF-8'),
                    'credit_note_number' => htmlspecialchars((string)$cn['credit_note_number'], ENT_QUOTES, 'UTF-8'),
                    'issued_date' => htmlspecialchars(date('d M Y', strtotime((string)$cn['issued_at'])), ENT_QUOTES, 'UTF-8'),
                    'guest_name' => htmlspecialchars((string)($cn['guest_name'] ?? ''), ENT_QUOTES, 'UTF-8'),
                    'guest_email' => htmlspecialchars((string)($cn['guest_email'] ?? ''), ENT_QUOTES, 'UTF-8'),
                    'booking_reference' => htmlspecialchars((string)($cn['booking_reference'] ?? ''), ENT_QUOTES, 'UTF-8'),
                    'reason' => htmlspecialchars(ucfirst(str_replace('_', ' ', (string)($cn['reason'] ?? ''))), ENT_QUOTES, 'UTF-8'),
                    'reason_notes' => nl2br(htmlspecialchars((string)($cn['reason_notes'] ?? ''), ENT_QUOTES, 'UTF-8')),
                    'expires_at' => htmlspecialchars($cn['expires_at'] ? date('d M Y', strtotime((string)$cn['expires_at'])) : 'No expiry', ENT_QUOTES, 'UTF-8'),
                    'amount' => htmlspecialchars($currencySymbol . ' ' . number_format((float)$cn['original_amount'], 2), ENT_QUOTES, 'UTF-8'),
                    'amount_used' => htmlspecialchars($currencySymbol . ' ' . number_format((float)$cn['amount_used'], 2), ENT_QUOTES, 'UTF-8'),
                    'balance' => htmlspecialchars($currencySymbol . ' ' . number_format((float)$cn['balance'], 2), ENT_QUOTES, 'UTF-8'),
                ], hotel_default_credit_note_document_html());

                file_put_contents($fullPath, bookingRenderPdfFromHtml($html, 'Credit Note ' . (string)$cn['credit_note_number']));
                $pdo->prepare("UPDATE credit_notes SET pdf_path=?, pdf_generated=1, updated_at=NOW() WHERE id=?")
                    ->execute([$relativePath, $creditNoteId]);

                return ['pdf_path' => $fullPath, 'relative_path' => $relativePath];
            }

            if (!$tcpdfAvailable) {
                // Fallback: plain HTML→text file if TCPDF unavailable
                $html  = "<h2>CREDIT NOTE — {$cn['credit_note_number']}</h2>";
                $html .= "<p>Guest: {$cn['guest_name']}</p>";
                $html .= "<p>Amount: {$currencySymbol} " . number_format((float)$cn['original_amount'], 2) . "</p>";
                $html .= "<p>Balance: {$currencySymbol} " . number_format((float)$cn['balance'], 2) . "</p>";
                $html .= "<p>Status: {$cn['status']}</p>";
                if ($cn['expires_at']) {
                    $html .= "<p>Expires: {$cn['expires_at']}</p>";
                }
                file_put_contents($fullPath . '.html', $html);
                $pdo->prepare("UPDATE credit_notes SET pdf_path=?, pdf_generated=1, updated_at=NOW() WHERE id=?")
                    ->execute([$relativePath . '.html', $creditNoteId]);
                return ['pdf_path' => $fullPath . '.html', 'relative_path' => $relativePath . '.html'];
            }

            // ── TCPDF document ─────────────────────────────────────────────
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
            $pdf = new JapandiTCPDF(PDF_PAGE_ORIENTATION, PDF_UNIT, PDF_PAGE_FORMAT, true, 'UTF-8', false);
            $pdf->SetCreator($siteName);
            $pdf->SetAuthor($siteName);
            $pdf->SetTitle('Credit Note ' . $cn['credit_note_number']);
            $pdf->SetSubject('Credit Note');
            $pdf->setPrintHeader(false);
            $pdf->setPrintFooter(false);
            $pdf->SetMargins(15, 15, 15);
            $pdf->SetAutoPageBreak(true, 20);
            $pdf->AddPage();
            $pdf->SetFont('helvetica', '', 9);

            $headerBg  = '#231F1C';
            $gold      = '#B18247';
            $lightBg   = '#F7F3EE';
            $textColor = '#2A2723';
            $muted     = '#5E554D';
            $dangerBg  = '#3c1a1a';
            $successBg = '#1a3c2a';

            // ── Header bar ────────────────────────────────────────────────
            $pdf->SetFillColor(35, 31, 28);
            $pdf->Rect(0, 0, 210, 38, 'F');

            $pdf->SetFont('helvetica', 'B', 18);
            $pdf->SetTextColor(177, 130, 71);
            $pdf->SetXY(15, 8);
            $pdf->Cell(100, 10, strtoupper($siteName), 0, 0, 'L');

            $pdf->SetFont('helvetica', '', 8);
            $pdf->SetTextColor(200, 190, 180);
            $pdf->SetXY(15, 20);
            $pdf->MultiCell(95, 4, implode(' · ', array_filter([$siteAddress, $sitePhone, $siteEmail, $siteWebsite])), 0, 'L');

            // CN badge (top-right)
            $pdf->SetFont('helvetica', 'B', 20);
            $pdf->SetTextColor(177, 130, 71);
            $pdf->SetXY(110, 8);
            $pdf->Cell(85, 10, 'CREDIT NOTE', 0, 0, 'R');

            $pdf->SetFont('helvetica', '', 9);
            $pdf->SetTextColor(200, 190, 180);
            $pdf->SetXY(110, 20);
            $pdf->Cell(85, 5, $cn['credit_note_number'], 0, 0, 'R');
            $pdf->SetXY(110, 26);
            $pdf->Cell(85, 5, 'Issued: ' . date('d M Y', strtotime((string)$cn['issued_at'])), 0, 0, 'R');

            $pdf->SetY(44);

            // ── CN details box ────────────────────────────────────────────
            $pdf->SetFillColor(247, 243, 238);
            $pdf->SetTextColor(42, 39, 35);
            $pdf->SetFont('helvetica', 'B', 8);
            $pdf->SetX(15);
            $pdf->Cell(85, 5, 'ISSUED TO', 0, 1, 'L', true);
            $pdf->SetFont('helvetica', '', 9);
            $pdf->SetX(15);
            $pdf->Cell(85, 6, $cn['guest_name'], 0, 1, 'L');
            if (!empty($cn['guest_email'])) {
                $pdf->SetX(15);
                $pdf->Cell(85, 5, $cn['guest_email'], 0, 1, 'L');
            }
            if (!empty($cn['booking_reference'])) {
                $pdf->SetX(15);
                $pdf->Cell(85, 5, 'Booking: ' . $cn['booking_reference'], 0, 1, 'L');
            }

            // Right side: amounts
            $pdf->SetXY(110, 44);
            $pdf->SetFillColor(247, 243, 238);
            $pdf->SetFont('helvetica', 'B', 8);
            $pdf->Cell(85, 5, 'CREDIT NOTE DETAILS', 0, 1, 'L', true);
            $pdf->SetFont('helvetica', '', 9);

            $detailsY = $pdf->GetY();
            $rightRows = [
                ['Original Value:', $currencySymbol . ' ' . number_format((float)$cn['original_amount'], 2)],
                ['Used:', $currencySymbol . ' ' . number_format((float)$cn['amount_used'], 2)],
                ['Available Balance:', $currencySymbol . ' ' . number_format((float)$cn['balance'], 2)],
                ['Status:', ucfirst((string)$cn['status'])],
            ];
            if ($cn['expires_at']) {
                $rightRows[] = ['Valid Until:', date('d M Y', strtotime((string)$cn['expires_at']))];
            }
            if ($vatNumber) {
                $rightRows[] = ['VAT Reg:', $vatNumber];
            }
            foreach ($rightRows as [$label, $val]) {
                $pdf->SetXY(110, $detailsY);
                $pdf->SetTextColor(94, 85, 77);
                $pdf->Cell(50, 5, $label, 0, 0, 'L');
                $pdf->SetTextColor(42, 39, 35);
                $pdf->SetFont('helvetica', 'B', 9);
                $pdf->Cell(35, 5, $val, 0, 0, 'R');
                $pdf->SetFont('helvetica', '', 9);
                $detailsY += 5;
            }

            $pdf->SetY(max($pdf->GetY(), $detailsY) + 8);

            // ── Reason section ────────────────────────────────────────────
            $pdf->SetFillColor(177, 130, 71);
            $pdf->SetTextColor(255, 255, 255);
            $pdf->SetFont('helvetica', 'B', 8);
            $pdf->SetX(15);
            $pdf->Cell(180, 6, '  REASON FOR CREDIT NOTE', 0, 1, 'L', true);

            $reasonLabels = [
                'cancellation'  => 'Booking Cancellation',
                'service_issue' => 'Service Issue / Complaint',
                'early_checkout' => 'Early Checkout',
                'overpayment'   => 'Overpayment',
                'goodwill'      => 'Goodwill Gesture',
                'pricing_error' => 'Pricing / Billing Error',
                'other'         => 'Other',
            ];
            $reasonLabel = $reasonLabels[$cn['reason']] ?? ucfirst((string)$cn['reason']);

            $pdf->SetFillColor(247, 243, 238);
            $pdf->SetTextColor(42, 39, 35);
            $pdf->SetFont('helvetica', '', 9);
            $pdf->SetX(15);
            $pdf->Cell(180, 6, $reasonLabel, 0, 1, 'L', true);

            if (!empty($cn['reason_notes'])) {
                $pdf->SetX(15);
                $pdf->SetFont('helvetica', 'I', 8);
                $pdf->SetTextColor(94, 85, 77);
                $pdf->MultiCell(180, 5, $cn['reason_notes'], 0, 'L');
            }

            $pdf->Ln(4);

            // ── Redemption history (if any) ───────────────────────────────
            if (!empty($applications)) {
                $pdf->SetFillColor(177, 130, 71);
                $pdf->SetTextColor(255, 255, 255);
                $pdf->SetFont('helvetica', 'B', 8);
                $pdf->SetX(15);
                $pdf->Cell(180, 6, '  REDEMPTION HISTORY', 0, 1, 'L', true);

                $pdf->SetFillColor(247, 243, 238);
                $pdf->SetTextColor(94, 85, 77);
                $pdf->SetFont('helvetica', 'B', 8);
                $pdf->SetX(15);
                $pdf->Cell(40, 5, 'Date', 1, 0, 'L', true);
                $pdf->Cell(55, 5, 'Booking', 1, 0, 'L', true);
                $pdf->Cell(35, 5, 'Amount Applied', 1, 0, 'R', true);
                $pdf->Cell(50, 5, 'Processed By', 1, 1, 'L', true);

                $pdf->SetFont('helvetica', '', 8);
                $pdf->SetTextColor(42, 39, 35);
                foreach ($applications as $app) {
                    $pdf->SetX(15);
                    $pdf->Cell(40, 5, date('d M Y', strtotime((string)$app['applied_at'])), 1, 0, 'L');
                    $pdf->Cell(55, 5, htmlspecialchars((string)($app['applied_to_booking_reference'] ?: 'N/A')), 1, 0, 'L');
                    $pdf->Cell(35, 5, $currencySymbol . ' ' . number_format((float)$app['amount_applied'], 2), 1, 0, 'R');
                    $pdf->Cell(50, 5, htmlspecialchars((string)($app['applied_by_name'] ?? 'Admin')), 1, 1, 'L');
                }
                $pdf->Ln(4);
            }

            // ── Balance summary box ───────────────────────────────────────
            $pdf->SetFillColor(35, 31, 28);
            $pdf->SetTextColor(177, 130, 71);
            $pdf->SetFont('helvetica', 'B', 11);
            $pdf->SetX(100);
            $pdf->Cell(95, 8, 'AVAILABLE BALANCE: ' . $currencySymbol . ' ' . number_format(max(0, (float)$cn['balance']), 2), 0, 1, 'R', true);

            $pdf->Ln(6);

            // ── Footer ────────────────────────────────────────────────────
            $pdf->SetFillColor(35, 31, 28);
            $pdf->SetTextColor(200, 190, 180);
            $pdf->SetFont('helvetica', '', 7);
            $pdf->SetX(15);
            $terms  = 'This credit note may be applied to any future booking at ' . $siteName . '. ';
            if ($cn['expires_at']) {
                $terms .= 'Valid until ' . date('d M Y', strtotime((string)$cn['expires_at'])) . '. ';
            }
            $terms .= 'Credit notes are non-transferable and cannot be exchanged for cash. ';
            $terms .= 'Reference: ' . $cn['credit_note_number'] . '.';
            $pdf->MultiCell(180, 4, $terms, 0, 'C');

            $pdf->Output($fullPath, 'F');

            $pdo->prepare("UPDATE credit_notes SET pdf_path=?, pdf_generated=1, updated_at=NOW() WHERE id=?")
                ->execute([$relativePath, $creditNoteId]);

            return ['pdf_path' => $fullPath, 'relative_path' => $relativePath];
        } catch (Throwable $e) {
            error_log('[credit-notes] generateCreditNotePDF: ' . $e->getMessage());
            return false;
        }
    }
}

// ─────────────────────────────────────────────────────────────────────────────
// sendCreditNoteEmail
// ─────────────────────────────────────────────────────────────────────────────
if (!function_exists('sendCreditNoteEmail')) {
    /**
     * Email a credit note PDF to the guest.
     *
     * @return array ['success'=>bool, 'message'=>string]
     */
    function sendCreditNoteEmail(PDO $pdo, int $creditNoteId): array
    {
        try {
            $cn = $pdo->prepare("SELECT * FROM credit_notes WHERE id = ?");
            $cn->execute([$creditNoteId]);
            $cn = $cn->fetch(PDO::FETCH_ASSOC);
            if (!$cn) {
                throw new RuntimeException('Credit note not found.');
            }
            if (empty($cn['guest_email'])) {
                throw new RuntimeException('No guest email address on record for this credit note.');
            }
            if (!filter_var($cn['guest_email'], FILTER_VALIDATE_EMAIL)) {
                throw new RuntimeException('Guest email address is invalid.');
            }

            // Regenerate PDF if missing
            if (empty($cn['pdf_path']) || !file_exists(__DIR__ . '/../' . $cn['pdf_path'])) {
                $pdfResult = generateCreditNotePDF($pdo, $creditNoteId);
                if (!$pdfResult) {
                    throw new RuntimeException('Unable to generate credit note PDF for email.');
                }
                $cn2 = $pdo->prepare("SELECT pdf_path FROM credit_notes WHERE id = ?");
                $cn2->execute([$creditNoteId]);
                $cn['pdf_path'] = (string)$cn2->fetchColumn();
            }

            $site_name       = getSetting('site_name')                 ?: 'The Hotel';
            $currency_symbol = getSetting('currency_symbol')           ?: 'MWK';
            $hotel_phone     = getSetting('hotel_phone')               ?: '';
            $hotel_address   = getSetting('hotel_address')             ?: '';
            $fromEmail       = getEmailSetting('email_from_email', '') ?: getEmailSetting('smtp_username', '');
            $fromName        = getEmailSetting('email_from_name', '')  ?: $site_name;
            $smtpHost        = getEmailSetting('smtp_host',     '');
            $smtpPort        = (int)getEmailSetting('smtp_port',  587);
            $smtpUser        = getEmailSetting('smtp_username', '');
            $smtpPass        = getEmailSetting('smtp_password', '');
            $smtpSecure      = getEmailSetting('smtp_secure',   'tls');

            if (empty($smtpHost)) {
                throw new RuntimeException('SMTP host is not configured. Please set up email settings in admin.');
            }

            // If the From address domain differs from the SMTP user domain, fall back to
            // the SMTP-authenticated address to prevent relay rejection ("data not accepted").
            if ($smtpUser && $fromEmail) {
                $fromDomain = strtolower(substr($fromEmail, strrpos($fromEmail, '@') + 1));
                $smtpDomain = strtolower(substr($smtpUser,  strrpos($smtpUser,  '@') + 1));
                if ($fromDomain !== $smtpDomain) {
                    $fromEmail = $smtpUser;
                }
            }
            if (empty($fromEmail)) {
                throw new RuntimeException('Email from address is not configured.');
            }

            // Render HTML body from file-based template (used as fallback when no DB template)
            ob_start();
            require __DIR__ . '/../templates/emails/credit-note.php';
            $htmlBody = ob_get_clean();

            // Override with the editable DB template for 'credit_note' if one has been saved
            $cnEmailSubject = 'Credit Note ' . $cn['credit_note_number'] . ' — ' . $site_name;
            if (function_exists('getBookingEmailTemplateConfig')) {
                $dbTpl = getBookingEmailTemplateConfig('credit_note', []);
                if (!empty($dbTpl['html_body'])) {
                    $cnLogoUrl  = function_exists('hotel_email_logo_url') ? hotel_email_logo_url() : '';
                    $cnLogoHtml = $cnLogoUrl !== ''
                        ? '<img src="' . htmlspecialchars($cnLogoUrl, ENT_QUOTES, 'UTF-8') . '" alt="' . htmlspecialchars($site_name, ENT_QUOTES, 'UTF-8') . '" style="max-width:110px;height:auto;display:block;margin:0 auto;">'
                        : '';
                    $cnPlaceholders = [
                        '{{site_name}}'          => htmlspecialchars($site_name),
                        '{{guest_name}}'         => htmlspecialchars((string)$cn['guest_name']),
                        '{{credit_note_number}}' => htmlspecialchars((string)$cn['credit_note_number']),
                        '{{amount}}'             => $currency_symbol . ' ' . number_format((float)$cn['original_amount'], 2),
                        '{{balance}}'            => $currency_symbol . ' ' . number_format((float)$cn['balance'], 2),
                        '{{amount_used}}'        => $currency_symbol . ' ' . number_format((float)$cn['amount_used'], 2),
                        '{{reason}}'             => htmlspecialchars(ucfirst(str_replace('_', ' ', (string)$cn['reason']))),
                        '{{reason_notes}}'       => htmlspecialchars((string)($cn['reason_notes'] ?? '')),
                        '{{expires_at}}'         => $cn['expires_at'] ? date('d M Y', strtotime((string)$cn['expires_at'])) : 'No expiry',
                        '{{hotel_phone}}'        => htmlspecialchars($hotel_phone),
                        '{{hotel_address}}'      => htmlspecialchars($hotel_address),
                        '{{booking_reference}}'  => htmlspecialchars((string)($cn['booking_reference'] ?? '')),
                        '{{currency_symbol}}'    => htmlspecialchars($currency_symbol),
                        '{{contact_email}}'      => htmlspecialchars($fromEmail),
                        '{{logo_html}}'          => $cnLogoHtml,
                    ];
                    $htmlBody = str_replace(array_keys($cnPlaceholders), array_values($cnPlaceholders), $dbTpl['html_body']);
                    if (!empty($dbTpl['subject'])) {
                        $cnEmailSubject = str_replace(array_keys($cnPlaceholders), array_values($cnPlaceholders), $dbTpl['subject']);
                    }
                }
            }

            $mail = new PHPMailer(true);
            $mail->isSMTP();
            $mail->Host        = $smtpHost;
            $mail->Port        = $smtpPort;
            $mail->Username    = $smtpUser;
            $mail->Password    = $smtpPass;
            $mail->SMTPAuth    = true;
            $mail->SMTPSecure  = $smtpSecure;
            $mail->CharSet     = 'UTF-8';
            $mail->SMTPOptions = ['ssl' => ['verify_peer' => false, 'verify_peer_name' => false]];

            $mail->setFrom($fromEmail, $fromName);
            $mail->addAddress((string)$cn['guest_email'], (string)$cn['guest_name']);

            $invoiceRecipients = getEmailSetting('invoice_recipients', '');
            foreach (array_filter(array_map('trim', explode(',', $invoiceRecipients))) as $cc) {
                if (filter_var($cc, FILTER_VALIDATE_EMAIL) && $cc !== $cn['guest_email']) {
                    $mail->addCC($cc);
                }
            }

            $mail->isHTML(true);
            $mail->Subject = $cnEmailSubject;
            $mail->Body    = hotel_embed_logo_cid($mail, wrapEmailTemplate($htmlBody, $cnEmailSubject));
            $mail->AltBody = "Dear {$cn['guest_name']},\n\nYour credit note {$cn['credit_note_number']} for {$currency_symbol} " . number_format((float)$cn['original_amount'], 2) . " is attached.\n\nPlease quote this reference when making your next booking.\n\nWarm regards,\n{$site_name}";

            // Fix: if the stored PDF path is an HTML fallback, regenerate a real PDF first
            $pdfRelPath  = (string)$cn['pdf_path'];
            $pdfFullPath = __DIR__ . '/../' . $pdfRelPath;
            $isHtmlFallback = (str_ends_with($pdfRelPath, '.html') || str_ends_with($pdfRelPath, '.htm'));
            if ($isHtmlFallback || !file_exists($pdfFullPath)) {
                $pdfResult = generateCreditNotePDF($pdo, $creditNoteId);
                if ($pdfResult && isset($pdfResult['pdf_path']) && file_exists($pdfResult['pdf_path'])) {
                    $pdfFullPath = $pdfResult['pdf_path'];
                    $pdfRelPath  = $pdfResult['relative_path'];
                }
            }
            if (file_exists($pdfFullPath) && !str_ends_with($pdfFullPath, '.html') && !str_ends_with($pdfFullPath, '.htm')) {
                $mail->addAttachment(
                    $pdfFullPath,
                    $cn['credit_note_number'] . '.pdf',
                    \PHPMailer\PHPMailer\PHPMailer::ENCODING_BASE64,
                    'application/pdf'
                );
            }

            $mail->send();

            $pdo->prepare("UPDATE credit_notes SET email_sent=1, email_sent_at=NOW(), updated_at=NOW() WHERE id=?")
                ->execute([$creditNoteId]);

            rh_log_event('credit-notes', 'info', "Credit note email sent: {$cn['credit_note_number']}", [
                'credit_note_id' => $creditNoteId,
                'guest_email'    => $cn['guest_email'],
            ]);

            return ['success' => true, 'message' => 'Credit note email sent to ' . $cn['guest_email']];
        } catch (Throwable $e) {
            error_log('[credit-notes] sendCreditNoteEmail: ' . $e->getMessage());
            rh_log_event('credit-notes', 'error', 'Credit note email failed: ' . $e->getMessage(), ['credit_note_id' => $creditNoteId]);
            return ['success' => false, 'message' => $e->getMessage()];
        }
    }
}
