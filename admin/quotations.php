<?php
require_once 'admin-init.php';
/** @var PDO $pdo */
/** @var array $user */
/** @var string $csrf_token */

require_once '../includes/quotation-pdf.php';
require_once '../config/email.php';
require_once __DIR__ . '/includes/booking-lifecycle.php';
require_once __DIR__ . '/../includes/alert.php';
require_once __DIR__ . '/includes/finance-account-sync.php';
require_once __DIR__ . '/../includes/quotation-convert.php'; // Convert to invoice (rules, locking, numbering)

$site_name       = getSetting('site_name');
$currency_symbol = getSetting('currency_symbol', 'MWK');

// Module flags — gate quick-links and type options by the active preset.
$qt_mod_bookings = function_exists('moduleEnabled') && moduleEnabled('bookings');
$qt_mod_conf     = function_exists('moduleEnabled') && moduleEnabled('conference');
$qt_mod_events   = function_exists('isEventsEnabled') && isEventsEnabled();

$message = '';
$error   = '';

// ── POST actions ─────────────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!validateCsrfToken($_POST['csrf_token'] ?? '')) {
        $error = 'Security token invalid. Please refresh and try again.';
    } else {
        $action       = $_POST['action'] ?? '';
        $quotation_id = (int)($_POST['quotation_id'] ?? 0);

        if ($action === 'resend' && $quotation_id > 0) {
            try {
                // The booking join only applies to room quotations: conference/event quotation
                // booking_id values belong to other tables and must never match a room booking.
                $qStmt = $pdo->prepare("SELECT q.*, b.*, q.booking_type AS qt_booking_type FROM quotations q LEFT JOIN bookings b ON q.booking_id = b.id AND q.booking_type = 'room' WHERE q.id = ?");
                $qStmt->execute([$quotation_id]);
                $qRow = $qStmt->fetch(PDO::FETCH_ASSOC);

                if (!$qRow) {
                    throw new Exception('Quotation not found.');
                }
                if ((string)$qRow['qt_booking_type'] !== 'room') {
                    throw new Exception('Only room quotations can be re-sent from here. Re-send conference and event quotations from their own enquiry page.');
                }
                $qcOwn = $pdo->prepare('SELECT * FROM quotations WHERE id = ?');
                $qcOwn->execute([$quotation_id]);
                if (rh_qc_is_converted($qcOwn->fetch(PDO::FETCH_ASSOC) ?: [])) {
                    throw new Exception('This quotation is already an invoice. Send the invoice from the booking instead.');
                }

                // Lifecycle guard
                $lcCheck = bookingAllowsAction(['status' => $qRow['status'] ?? 'pending', 'amount_paid' => $qRow['amount_paid'] ?? 0, 'amount_due' => $qRow['amount_due'] ?? 0, 'total_amount' => $qRow['total_amount'] ?? 0], 'send_quotation');
                if (!$lcCheck['allowed']) {
                    throw new Exception($lcCheck['reason']);
                }

                $booking = [];
                foreach ($qRow as $k => $v) {
                    $booking[$k] = $v;
                }

                $result = sendTentativeQuotationEmail($booking, [
                    'valid_days'      => (int)($qRow['valid_days'] ?? 7),
                    'quotation_notes' => $qRow['quotation_notes'] ?? '',
                ]);

                if ($result['success']) {
                    // A resend also refreshes the register total to the VAT-inclusive figure the guest was just quoted.
                    if (!function_exists('rh_doc_stay_lines')) {
                        require_once __DIR__ . '/../includes/pricing.php';
                    }
                    $pdo->prepare("UPDATE quotations SET status = 'sent', sent_at = NOW(), total_amount = ?, updated_at = NOW() WHERE id = ?")->execute([rh_doc_stay_lines($booking)['total'], $quotation_id]);
                    $pdo->prepare("UPDATE bookings SET last_quotation_sent_at = NOW() WHERE id = ?")->execute([$qRow['booking_id']]);
                    $_SESSION['qt_flash'] = ['type' => 'success', 'msg' => 'Quotation resent to ' . ($qRow['guest_email'] ?? '')];
                } else {
                    $_SESSION['qt_flash'] = ['type' => 'error', 'msg' => 'Resend failed: ' . $result['message']];
                }
            } catch (Throwable $e) {
                $_SESSION['qt_flash'] = ['type' => 'error', 'msg' => 'Error: ' . $e->getMessage()];
            }
            header('Location: quotations.php');
            exit;
        }

        if ($action === 'mark_accepted' && $quotation_id > 0) {
            $mqStmt = $pdo->prepare("SELECT q.*, b.status as booking_status, b.amount_paid, b.amount_due, b.total_amount FROM quotations q LEFT JOIN bookings b ON q.booking_id = b.id AND q.booking_type = 'room' WHERE q.id = ?");
            $mqStmt->execute([$quotation_id]);
            $mqRow = $mqStmt->fetch(PDO::FETCH_ASSOC);
            if ($mqRow) {
                // quotations has valid_until (there is no expires_at column).
                $expiryDate = !empty($mqRow['valid_until']) ? $mqRow['valid_until'] : null;
                if ($expiryDate && $expiryDate < date('Y-m-d')) {
                    $error = 'Cannot accept an expired quotation (expired ' . $expiryDate . ').';
                } else {
                    $lcCheck = bookingAllowsAction(['status' => $mqRow['booking_status'] ?? 'pending', 'amount_paid' => $mqRow['amount_paid'] ?? 0, 'amount_due' => $mqRow['amount_due'] ?? 0, 'total_amount' => $mqRow['total_amount'] ?? 0], 'mark_quotation');
                    if (!$lcCheck['allowed']) {
                        $error = $lcCheck['reason'];
                    } else {
                        $pdo->prepare("UPDATE quotations SET status = 'accepted', updated_at = NOW() WHERE id = ?")->execute([$quotation_id]);
                        if (!empty($mqRow['booking_id']) && (string)$mqRow['booking_type'] === 'room' && !hasPermission((int)($user['id'] ?? 0), 'edit_booking')) {
                            $message = 'Quotation marked as accepted. Confirming the booking needs the Edit Booking permission - ask a colleague who has it.';
                        } elseif (!empty($mqRow['booking_id']) && (string)$mqRow['booking_type'] === 'room') {
                            $pdo->prepare(rh_qc_confirm_booking_sql())->execute([$mqRow['booking_id']]); // clears is_tentative, as the Tentative page
                            $message = 'Quotation marked as accepted and booking confirmed.';
                        } else {
                            $message = 'Quotation marked as accepted. Confirm the enquiry from its own page.';
                        }
                    }
                }
            } else {
                $error = 'Quotation not found.';
            }
        }

        if ($action === 'convert_invoice' && $quotation_id > 0) {
            // Accept + issue ONE invoice in one locked transaction (includes/quotation-convert.php); the PDF, email and
            // History come after the commit and always reuse the linked number.
            require_once '../config/invoice.php';
            if (!function_exists('rh_doc_stay_lines')) {
                require_once __DIR__ . '/../includes/pricing.php';
            }
            $convRes = rh_qc_convert($pdo, $quotation_id, (int)($user['id'] ?? 0));
            if (empty($convRes['ok'])) {
                $_SESSION['qt_flash'] = ['type' => 'error', 'msg' => (string)$convRes['message']];
                header('Location: quotations.php?convert=' . $quotation_id . '#qt-convert');
                exit;
            }
            $convAfter = rh_qc_after_convert($pdo, $convRes, !empty($_POST['email_invoice']), $user);
            $_SESSION['qt_flash'] = ['type' => 'success', 'msg' => $convAfter['message'], 'sticky' => true];
            if ($convAfter['warning'] !== '') {
                $_SESSION['qt_flash_warn'] = $convAfter['warning'];
            }
            header('Location: quotations.php?search=' . rawurlencode((string)$convRes['quote_reference']));
            exit;
        }

        if ($action === 'mark_declined' && $quotation_id > 0) {
            $mdStmt = $pdo->prepare("SELECT q.*, b.status as booking_status, b.amount_paid, b.amount_due, b.total_amount FROM quotations q LEFT JOIN bookings b ON q.booking_id = b.id AND q.booking_type = 'room' WHERE q.id = ?");
            $mdStmt->execute([$quotation_id]);
            $mdRow = $mdStmt->fetch(PDO::FETCH_ASSOC);
            if ($mdRow && rh_qc_is_converted($mdRow)) {
                $error = 'This quotation is already invoice ' . (string)($mdRow['converted_invoice_number'] ?? '') . ', so it cannot be declined.';
            } elseif ($mdRow) {
                $lcCheck = bookingAllowsAction(['status' => $mdRow['booking_status'] ?? 'pending', 'amount_paid' => $mdRow['amount_paid'] ?? 0, 'amount_due' => $mdRow['amount_due'] ?? 0, 'total_amount' => $mdRow['total_amount'] ?? 0], 'mark_quotation');
                if (!$lcCheck['allowed']) {
                    $error = $lcCheck['reason'];
                } else {
                    $pdo->prepare("UPDATE quotations SET status = 'declined', updated_at = NOW() WHERE id = ?")->execute([$quotation_id]);
                    $message = 'Quotation marked as declined.';
                }
            } else {
                $error = 'Quotation not found.';
            }
        }
    }
}

// ── Session flash (set by PRG redirect from resend action) ────────────────────
$messageSticky = false;
$qtWarning     = '';
if (!empty($_SESSION['qt_flash'])) {
    $flash = $_SESSION['qt_flash'];
    unset($_SESSION['qt_flash']);
    if ($flash['type'] === 'success') {
        $message = $flash['msg'];
        $messageSticky = !empty($flash['sticky']);
    } else {
        $error = $flash['msg'];
    }
}
if (!empty($_SESSION['qt_flash_warn'])) {
    $qtWarning = (string)$_SESSION['qt_flash_warn'];
    unset($_SESSION['qt_flash_warn']);
}

// ── Download the invoice a quotation was converted to ─────────────────────────
// The stored file is served as issued; a missing file is rebuilt under the SAME linked number, unless the account is
// now paid in full (then it gets its receipt, never an invoice).
if ($_SERVER['REQUEST_METHOD'] === 'GET' && isset($_GET['invoice'])) {
    $ivId = (int)($_GET['invoice'] ?? 0);
    try {
        if ($ivId <= 0 || !rh_qc_columns_present($pdo)) {
            throw new RuntimeException('Invoice not found.');
        }
        $ivSt = $pdo->prepare('SELECT * FROM quotations WHERE id = ?');
        $ivSt->execute([$ivId]);
        $ivQ = $ivSt->fetch(PDO::FETCH_ASSOC);
        $ivNum = trim((string)($ivQ['converted_invoice_number'] ?? ''));
        if (!$ivQ || $ivNum === '') {
            throw new RuntimeException('This quotation has not been converted to an invoice.');
        }
        $ivType = trim((string)($ivQ['booking_type'] ?? '')) ?: 'room';
        if (!rh_qc_user_perms((int)($user['id'] ?? 0), $ivType)['can_invoice']) {
            throw new RuntimeException('Opening this invoice needs the "' . rh_qc_permission_label($ivType) . '" permission.');
        }
        $ivAbs = '';
        $ivRel = trim((string)($ivQ['converted_invoice_path'] ?? ''));
        if ($ivRel !== '') {
            $ivCandidate = dirname(__DIR__) . '/invoices/' . basename($ivRel);
            if (is_file($ivCandidate)) {
                $ivAbs = $ivCandidate;
            }
        }
        if ($ivAbs === '') {
            require_once '../config/invoice.php';
            // Rebuild only for a record that still exists, still matches the quotation and may still be invoiced.
            $ivKeys = rh_qc_record_keys($ivType);
            $ivRec = null;
            if ($ivKeys !== null) {
                $ivRecSt = $pdo->prepare('SELECT * FROM `' . $ivKeys['table'] . '` WHERE id = ? LIMIT 1');
                $ivRecSt->execute([(int)$ivQ['booking_id']]);
                $ivRec = $ivRecSt->fetch(PDO::FETCH_ASSOC) ?: null;
            }
            $ivBlock = rh_qc_rebuild_block_reason($ivType, $ivQ, $ivRec);
            if ($ivBlock !== null) {
                throw new RuntimeException($ivBlock);
            }
            if (rh_account_fully_paid($pdo, $ivType, (int)$ivQ['booking_id'])) {
                throw new RuntimeException('This ' . ($ivType === 'room' ? 'booking' : 'enquiry') . ' is now paid in full, so it gets a receipt, not an invoice. Open it to send the receipt.');
            }
            $ivGen = rh_qc_generate_pdf($ivType, (int)$ivQ['booking_id'], $ivNum);
            if (!$ivGen || empty($ivGen['filepath']) || !is_file((string)$ivGen['filepath'])) {
                throw new RuntimeException('The invoice file could not be made just now. Please try again.');
            }
            $ivAbs = (string)$ivGen['filepath'];
            $pdo->prepare('UPDATE quotations SET converted_invoice_path = ? WHERE id = ?')->execute([(string)$ivGen['relative_path'], $ivId]);
        }
        $ivIsPdf = strtolower(pathinfo($ivAbs, PATHINFO_EXTENSION)) === 'pdf';
        $ivName = preg_replace('/[^A-Za-z0-9_-]+/', '-', $ivNum) . ($ivIsPdf ? '.pdf' : '.html');
        header('Content-Type: ' . ($ivIsPdf ? 'application/pdf' : 'text/html; charset=UTF-8'));
        header('Content-Disposition: attachment; filename="' . $ivName . '"');
        header('Content-Length: ' . filesize($ivAbs));
        readfile($ivAbs);
        exit;
    } catch (Throwable $e) {
        $error = $e instanceof RuntimeException && !($e instanceof PDOException)
            ? $e->getMessage()
            : 'The invoice could not be opened. Please try again.';
    }
}

// ── Handle PDF download ───────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'GET' && isset($_GET['download'])) {
    $dlId = (int)($_GET['download'] ?? 0);
    if ($dlId > 0) {
        $dlQStmt = $pdo->prepare("SELECT * FROM quotations WHERE id = ?");
        $dlQStmt->execute([$dlId]);
        $dlQuote = $dlQStmt->fetch(PDO::FETCH_ASSOC);
        if ($dlQuote) {
            // Try saved PDF first
            if (!empty($dlQuote['pdf_path'])) {
                $absPath = dirname(__DIR__) . '/' . ltrim($dlQuote['pdf_path'], '/');
                if (file_exists($absPath)) {
                    header('Content-Type: application/pdf');
                    header('Content-Disposition: attachment; filename="' . $dlQuote['quote_reference'] . '.pdf"');
                    header('Content-Length: ' . filesize($absPath));
                    readfile($absPath);
                    exit;
                }
            }
            // Regenerate on the fly, per booking type
            $dlType  = $dlQuote['booking_type'] ?: 'room';
            $dlOpts  = [
                'valid_days'      => (int)($dlQuote['valid_days'] ?? 7),
                'quotation_notes' => $dlQuote['quotation_notes'] ?? '',
                'quote_reference' => $dlQuote['quote_reference'] ?? '',
            ];

            try {
                // booking_id means a different table per type (rh_qc_record_keys): rooms = bookings, conference and gym =
                // their enquiries, event = the events LISTING the offer was made for. The record found must also carry
                // this quotation's own reference before anything is generated from it, so an id can never produce
                // another client's document.
                $dlKeys = rh_qc_record_keys($dlType);
                if ($dlKeys === null) {
                    throw new RuntimeException('This quotation type cannot be rebuilt here.');
                }
                $dlRecSt = $pdo->prepare('SELECT * FROM `' . $dlKeys['table'] . '` WHERE id = ? LIMIT 1');
                $dlRecSt->execute([(int)$dlQuote['booking_id']]);
                $dlRec = $dlRecSt->fetch(PDO::FETCH_ASSOC);
                if (!$dlRec) {
                    throw new RuntimeException('The ' . ($dlType === 'room' ? 'booking' : ($dlType === 'event' ? 'event' : 'enquiry')) . ' for this quotation no longer exists.');
                }
                if (!rh_qc_record_matches($dlType, $dlQuote, $dlRec)) {
                    throw new RuntimeException('The saved PDF for this quotation is missing and the record it points to does not carry its reference, so it cannot be rebuilt safely. Send a new quotation instead.');
                }
                if ($dlType === 'conference') {
                    require_once '../config/invoice.php';
                    $crStmt = $pdo->prepare("SELECT * FROM conference_rooms WHERE id = ? LIMIT 1");
                    $crStmt->execute([$dlRec['conference_room_id'] ?? 0]);
                    $confRoom  = $crStmt->fetch(PDO::FETCH_ASSOC) ?: [];
                    $pdfBinary = generateConferenceQuotationPDF($dlRec, $confRoom, $dlOpts);
                } elseif ($dlType === 'gym') {
                    require_once '../config/invoice.php';
                    $pdfBinary = generateGymQuotationPDF($dlRec, $dlOpts);
                } elseif ($dlType === 'event') {
                    // The attendee count is not stored: it is the quoted total / ticket price, and only when that is a
                    // whole number (else the price changed since and a rebuilt PDF would show different money).
                    $dlAtt = rh_qc_event_attendees((float)($dlQuote['total_amount'] ?? 0), (float)($dlRec['ticket_price'] ?? 0));
                    if ($dlAtt === null) {
                        throw new RuntimeException('The saved PDF for this event quotation is missing and the ticket price has changed since, so it cannot be rebuilt with the quoted figures. Send a new quotation from Events.');
                    }
                    $pdfBinary = generateEventQuotationPDF($dlRec, [
                        'name'  => (string)($dlQuote['guest_name'] ?? ''),
                        'email' => (string)($dlQuote['guest_email'] ?? ''),
                    ], $dlOpts + ['attendee_count' => $dlAtt]);
                } else {
                    $rStmt = $pdo->prepare("SELECT * FROM rooms WHERE id = ? LIMIT 1");
                    $rStmt->execute([$dlRec['room_id'] ?? 0]);
                    $room      = $rStmt->fetch(PDO::FETCH_ASSOC) ?: [];
                    $pdfBinary = generateQuotationPDF($dlRec, $room, $dlOpts);
                }
                // Save regenerated PDF
                $quotationsDir = dirname(__DIR__) . '/quotations';
                if (!is_dir($quotationsDir)) {
                    mkdir($quotationsDir, 0755, true);
                }
                $fname   = preg_replace('/[^A-Za-z0-9_-]/', '', (string)$dlQuote['quote_reference']) . '-' . date('Ymd') . '.pdf';
                $absFile = $quotationsDir . '/' . $fname;
                file_put_contents($absFile, $pdfBinary);
                $pdo->prepare("UPDATE quotations SET pdf_path = ? WHERE id = ?")->execute(['quotations/' . $fname, $dlId]);

                header('Content-Type: application/pdf');
                header('Content-Disposition: attachment; filename="' . $dlQuote['quote_reference'] . '.pdf"');
                header('Content-Length: ' . strlen($pdfBinary));
                echo $pdfBinary;
                exit;
            } catch (Throwable $e) {
                $error = 'PDF generation failed: ' . $e->getMessage();
            }
        }
    }
}

// ── Filters ───────────────────────────────────────────────────────────────────
$filter_type   = $_GET['type']   ?? '';
$filter_status = $_GET['status'] ?? '';
$filter_from   = $_GET['from']   ?? '';
$filter_to     = $_GET['to']     ?? '';
$search        = trim($_GET['search'] ?? '');

$where  = ['1=1'];
$params = [];

// Preset scoping: default list shows quotations for enabled modules only
// (untyped/legacy rows always stay visible). ?type= and ?scope=all bypass.
$scopeAll            = (($_GET['scope'] ?? '') === 'all');
$allBookingTypes     = ['room', 'conference', 'restaurant', 'gym', 'event'];
$enabledBookingTypes = function_exists('rh_enabled_booking_types') ? rh_enabled_booking_types() : [];
$scopeActive         = $filter_type === '' && !$scopeAll
    && !empty($enabledBookingTypes)
    && count($enabledBookingTypes) < count($allBookingTypes);
$hiddenBookingTypes  = $scopeActive ? array_values(array_diff($allBookingTypes, $enabledBookingTypes)) : [];

if ($filter_type !== '') {
    $where[]  = 'q.booking_type = ?';
    $params[] = $filter_type;
} elseif ($scopeActive) {
    $where[] = '(q.booking_type IS NULL OR q.booking_type IN (' . implode(',', array_fill(0, count($enabledBookingTypes), '?')) . '))';
    $params  = array_merge($params, $enabledBookingTypes);
}
// "Converted" is not a stored status: a converted quotation stays 'accepted' and carries converted_at (migration 082).
$qcCols  = rh_qc_columns_present($pdo);
$qcToday = date('Y-m-d');
if ($filter_status === 'converted') {
    $where[] = $qcCols ? 'q.converted_at IS NOT NULL' : '1 = 0';
} elseif ($filter_status === 'accepted' && $qcCols) {
    $where[] = "q.status = 'accepted' AND q.converted_at IS NULL";
} elseif ($filter_status !== '') {
    $where[]  = 'q.status = ?';
    $params[] = $filter_status;
}

// ── Convert to invoice: the single-quotation screen (?convert=ID) ─────────────
$qcPanel = null;
if ($_SERVER['REQUEST_METHOD'] === 'GET' && isset($_GET['convert'])) {
    $cvId = (int)($_GET['convert'] ?? 0);
    try {
        $cvSt = $pdo->prepare('SELECT * FROM quotations WHERE id = ?');
        $cvSt->execute([$cvId]);
        $cvQ = $cvSt->fetch(PDO::FETCH_ASSOC);
        if ($cvQ) {
            if (!function_exists('rh_doc_stay_lines')) {
                require_once __DIR__ . '/../includes/pricing.php';
            }
            require_once __DIR__ . '/../includes/booked-rate.php';
            $qcPanel = ['q' => $cvQ] + rh_qc_preview($pdo, $cvQ, (int)($user['id'] ?? 0));
        } else {
            $error = 'Quotation not found.';
        }
    } catch (Throwable $e) {
        error_log('[quotations] convert preview: ' . $e->getMessage());
        $error = 'The quotation could not be loaded. Please try again.';
    }
}
if ($filter_from !== '') {
    $where[]  = 'DATE(q.sent_at) >= ?';
    $params[] = $filter_from;
}
if ($filter_to !== '') {
    $where[]  = 'DATE(q.sent_at) <= ?';
    $params[] = $filter_to;
}
if ($search !== '') {
    $where[]  = '(q.guest_name LIKE ? OR q.guest_email LIKE ? OR q.quote_reference LIKE ? OR q.booking_reference LIKE ?)';
    $like     = '%' . $search . '%';
    $params   = array_merge($params, [$like, $like, $like, $like]);
}

$sql = "
    SELECT q.*
    FROM quotations q
    WHERE " . implode(' AND ', $where) . "
    ORDER BY q.sent_at DESC, q.id DESC
";

$quotations = [];
$stats      = ['total' => 0, 'sent' => 0, 'accepted' => 0, 'expired' => 0, 'declined' => 0, 'converted' => 0, 'total_value' => 0];

try {
    // Expire lapsed quotations BEFORE reading, so the tiles, the Status filter and the table all
    // agree (previously the tiles counted a lapsed quotation as "Sent" while the table showed "Expired").
    $pdo->exec("UPDATE quotations SET status = 'expired', updated_at = NOW()
                WHERE status = 'sent' AND valid_until IS NOT NULL AND DATE(valid_until) < CURDATE()");

    $listStmt   = $pdo->prepare($sql);
    $listStmt->execute($params);
    $quotations = $listStmt->fetchAll(PDO::FETCH_ASSOC);

    // Rows hidden by preset scoping (for the notice above the list)
    $hiddenScopedCount = 0;
    if ($scopeActive && $hiddenBookingTypes !== []) {
        $hPh = implode(',', array_fill(0, count($hiddenBookingTypes), '?'));
        $hStmt = $pdo->prepare("SELECT COUNT(*) FROM quotations q WHERE q.booking_type IN ($hPh)");
        $hStmt->execute($hiddenBookingTypes);
        $hiddenScopedCount = (int)$hStmt->fetchColumn();
    }

    foreach ($quotations as $q) {
        $stats['total']++;
        $qcStatKey = rh_qc_is_converted($q) ? 'converted' : ($q['status'] ?? 'sent');
        $stats[$qcStatKey] = ($stats[$qcStatKey] ?? 0) + 1;
        $stats['total_value']          += (float)($q['total_amount'] ?? 0);
    }
} catch (Throwable $e) {
    $error = 'Failed to load quotations: ' . $e->getMessage();
}
?>
<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="csrf-token" content="<?= htmlspecialchars($csrf_token, ENT_QUOTES) ?>">
    <title>Quotations | <?php echo htmlspecialchars($site_name); ?> Admin</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Cormorant+Garamond:ital,wght@0,300;0,400;0,600;1,400&family=Jost:wght@300;400;500;600&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="css/admin-styles.css?v=<?php echo @filemtime(__DIR__ . '/css/admin-styles.css'); ?>">
    <link rel="stylesheet" href="css/admin-components.css?v=<?php echo @filemtime(__DIR__ . '/css/admin-components.css'); ?>">
    <link rel="stylesheet" href="css/quotations.css?v=<?php echo @filemtime(__DIR__ . '/css/quotations.css'); ?>">
</head>

<body>
    <?php require_once 'includes/admin-header.php'; ?>

    <div class="admin-container">
        <div class="qt-header">
            <h1><i class="fas fa-file-invoice-dollar" style="color:#8F6A35;"></i> Quotations</h1>
            <div style="display:flex;gap:10px;align-items:center;">
                <?php if ($qt_mod_bookings): ?>
                <a href="bookings.php" class="btn btn-secondary" style="font-size:13px;padding:7px 14px;">
                    <i class="fas fa-calendar-check"></i> All Bookings
                </a>
                <a href="tentative-bookings.php" class="btn btn-secondary" style="font-size:13px;padding:7px 14px;">
                    <i class="fas fa-clock"></i> Tentative
                </a>
                <?php elseif ($qt_mod_conf): ?>
                <a href="conference-management.php" class="btn btn-secondary" style="font-size:13px;padding:7px 14px;">
                    <i class="fas fa-briefcase"></i> Conference Bookings
                </a>
                <?php endif; ?>
            </div>
        </div>

        <?php if ($message): showAlert($message, 'success', $messageSticky ? ['timeout' => 0] : []); endif; ?>
        <?php if ($qtWarning !== ''): showAlert($qtWarning, 'warning', ['timeout' => 0]); endif; ?>
        <?php if ($error): showAlert($error, 'error'); endif; ?>

        <!-- Stats -->
        <div class="ck-kpis ck-kpis--3">
            <div class="ck-kpi">
                <span class="ck-kpi__label">Total Issued</span>
                <span class="ck-kpi__value"><?php echo $stats['total']; ?></span>
            </div>
            <div class="ck-kpi ck-kpi--info">
                <span class="ck-kpi__label">Sent / Active</span>
                <span class="ck-kpi__value"><?php echo $stats['sent']; ?></span>
            </div>
            <div class="ck-kpi ck-kpi--good">
                <span class="ck-kpi__label">Accepted</span>
                <span class="ck-kpi__value"><?php echo $stats['accepted']; ?></span>
                <?php if ($qcCols): ?><span class="ck-kpi__sub">Accepted, not yet made into an invoice</span><?php endif; ?>
            </div>
            <?php if ($qcCols): ?>
            <div class="ck-kpi ck-kpi--good">
                <span class="ck-kpi__label">Converted to invoice</span>
                <span class="ck-kpi__value"><?php echo (int)$stats['converted']; ?></span>
                <span class="ck-kpi__sub">Quotations that became an invoice with Convert to invoice</span>
            </div>
            <?php endif; ?>
            <div class="ck-kpi">
                <span class="ck-kpi__label">Expired</span>
                <span class="ck-kpi__value"><?php echo $stats['expired']; ?></span>
            </div>
            <div class="ck-kpi ck-kpi--alert">
                <span class="ck-kpi__label">Declined</span>
                <span class="ck-kpi__value"><?php echo $stats['declined']; ?></span>
            </div>
            <div class="ck-kpi">
                <span class="ck-kpi__label">Total Quoted Value</span>
                <span class="ck-kpi__value ck-kpi__value--money"><span class="ck-cur"><?php echo htmlspecialchars($currency_symbol); ?></span><?php echo number_format($stats['total_value'], 0); ?></span>
            </div>
        </div>

        <!-- Filters -->
        <form method="GET" class="qt-filters">
            <div>
                <label>Search</label>
                <input type="text" name="search" placeholder="<?php echo $qt_mod_bookings ? 'Guest' : 'Client'; ?> name, email, reference…" value="<?php echo htmlspecialchars($search); ?>">
            </div>
            <div>
                <label>Type</label>
                <select name="type">
                    <option value="">All types</option>
                    <?php if ($qt_mod_bookings || $filter_type === 'room'): ?><option value="room" <?php echo $filter_type === 'room'       ? 'selected' : ''; ?>>Room</option><?php endif; ?>
                    <?php if ($qt_mod_conf || $filter_type === 'conference'): ?><option value="conference" <?php echo $filter_type === 'conference' ? 'selected' : ''; ?>>Conference</option><?php endif; ?>
                    <?php if ($qt_mod_events || $filter_type === 'event'): ?><option value="event" <?php echo $filter_type === 'event'      ? 'selected' : ''; ?>>Event</option><?php endif; ?>
                    <?php $qt_mod_gym = function_exists('moduleEnabled') && moduleEnabled('gym'); ?>
                    <?php if ($qt_mod_gym || $filter_type === 'gym'): ?><option value="gym" <?php echo $filter_type === 'gym'        ? 'selected' : ''; ?>>Gym</option><?php endif; ?>
                </select>
            </div>
            <div>
                <label>Status</label>
                <select name="status">
                    <option value="">All statuses</option>
                    <option value="sent" <?php echo $filter_status === 'sent'     ? 'selected' : ''; ?>>Sent</option>
                    <option value="accepted" <?php echo $filter_status === 'accepted' ? 'selected' : ''; ?>>Accepted</option>
                    <option value="expired" <?php echo $filter_status === 'expired'  ? 'selected' : ''; ?>>Expired</option>
                    <option value="declined" <?php echo $filter_status === 'declined' ? 'selected' : ''; ?>>Declined</option>
                    <option value="converted" <?php echo $filter_status === 'converted' ? 'selected' : ''; ?>>Converted to invoice</option>
                </select>
            </div>
            <div>
                <label>From</label>
                <input type="date" name="from" value="<?php echo htmlspecialchars($filter_from); ?>">
            </div>
            <div>
                <label>To</label>
                <input type="date" name="to" value="<?php echo htmlspecialchars($filter_to); ?>">
            </div>
            <div class="filter-actions">
                <button type="submit" class="btn btn-primary" style="font-size:13px;padding:8px 16px;"><i class="fas fa-filter"></i> Filter</button>
                <a href="quotations.php" class="btn btn-secondary" style="font-size:13px;padding:8px 14px;">Clear</a>
            </div>
        </form>

        <?php
        $scopeQs = $_GET;
        unset($scopeQs['scope']);
        if ($scopeActive && ($hiddenScopedCount ?? 0) > 0): ?>
            <div style="background:#faf8f4; border:1px solid #e5d9c9; border-radius:10px; padding:10px 14px; margin:0 0 14px; display:flex; align-items:center; justify-content:space-between; gap:12px; flex-wrap:wrap; font-size:13px; color:#7a6f63;">
                <span><i class="fas fa-filter" style="margin-right:6px;"></i>Showing quotations for your active modules only (<?php echo number_format($hiddenScopedCount); ?> older record<?php echo (int)$hiddenScopedCount === 1 ? '' : 's'; ?> from disabled modules hidden).</span>
                <a href="?<?php echo htmlspecialchars(http_build_query(array_merge($scopeQs, ['scope' => 'all']))); ?>" style="color:#7E684B; font-weight:600; text-decoration:none;">Show all history &rarr;</a>
            </div>
        <?php elseif ($scopeAll && $filter_type === ''): ?>
            <div style="background:#faf8f4; border:1px solid #e5d9c9; border-radius:10px; padding:10px 14px; margin:0 0 14px; display:flex; align-items:center; justify-content:space-between; gap:12px; flex-wrap:wrap; font-size:13px; color:#7a6f63;">
                <span><i class="fas fa-clock-rotate-left" style="margin-right:6px;"></i>Showing full quotation history, including records from disabled modules.</span>
                <a href="?<?php echo htmlspecialchars(http_build_query($scopeQs)); ?>" style="color:#7E684B; font-weight:600; text-decoration:none;">Show relevant only &rarr;</a>
            </div>
        <?php endif; ?>

        <?php
        // Who may convert / open converted invoices, per quotation type (rh_qc_permission_for_type).
        $qcCanInvoice = [];
        foreach (rh_qc_convertible_types() as $qcT) {
            $qcCanInvoice[$qcT] = rh_qc_user_perms((int)($user['id'] ?? 0), $qcT)['can_invoice'];
        }
        if (!$qcCols): ?>
            <div style="background:#faf8f4; border:1px solid #e5d9c9; border-radius:10px; padding:10px 14px; margin:0 0 14px; font-size:13px; color:#7a6f63;">
                <i class="fas fa-circle-info" style="margin-right:6px;"></i>Convert to invoice will appear on room and conference quotations once the administrator runs the database updates (082_quotation_invoice_link).
            </div>
        <?php endif; ?>

        <?php if ($qcPanel !== null):
            $cvQ    = $qcPanel['q'];
            $cvEl   = $qcPanel['eligibility'];
            $cvAcct = $qcPanel['account'];
            $cvAmt  = $qcPanel['amount'];
            $cvType = $qcPanel['type'];
            $cvWord = $cvType === 'room' ? 'booking' : 'enquiry';
            $cvLink = $cvType === 'conference' ? 'conference-management.php'
                : ($cvType === 'event' ? 'events-management.php' : 'booking-details.php?id=' . (int)$cvQ['booking_id']);
            $cvEmail = trim((string)($cvAcct['email'] ?? ''));
            $cvEmailOk = $cvEmail !== '' && filter_var($cvEmail, FILTER_VALIDATE_EMAIL);
            $cvHelp = 'display:block;color:#8a7f73;font-size:12px;line-height:1.4;margin-top:2px;';
        ?>
            <section id="qt-convert" class="qt-convert" aria-labelledby="qt-convert-title" style="background:#fff; border:1px solid #e5d9c9; border-radius:12px; padding:18px 20px; margin:0 0 18px;">
                <h2 id="qt-convert-title" style="font-size:1.15rem; margin:0 0 4px;">
                    <i class="fas fa-file-invoice" style="color:#8F6A35;"></i>
                    Convert quotation <?php echo htmlspecialchars((string)$cvQ['quote_reference']); ?> to an invoice
                </h2>
                <p style="margin:0 0 14px; color:#7a6f63; font-size:13px;">
                    Converting accepts the quotation and issues one invoice for the <?php echo $cvWord; ?>. The quotation and the invoice then point to each other.
                </p>

                <?php if ($cvAcct !== null && $cvAmt !== null): ?>
                    <dl style="display:grid; grid-template-columns:minmax(120px, max-content) 1fr; gap:10px 16px; margin:0 0 14px; font-size:14px;">
                        <dt style="font-weight:600;">Quoted total</dt>
                        <dd style="margin:0;"><?php echo htmlspecialchars(rh_qc_money((float)$cvAmt['quoted'], $currency_symbol)); ?>
                            <small style="<?php echo $cvHelp; ?>">The total on the quotation when it was sent.</small></dd>

                        <dt style="font-weight:600;"><?php echo $cvType === 'room' ? 'Stay total now' : 'Enquiry total now'; ?></dt>
                        <dd style="margin:0;"><?php echo htmlspecialchars(rh_qc_money((float)$cvAmt['current'], $currency_symbol)); ?>
                            <small style="<?php echo $cvHelp; ?>"><?php echo $cvType === 'room'
                                ? 'The booking\'s own stay total at the booked rate &times; nights, VAT and tourism levy included. Never today\'s room price.'
                                : 'The enquiry\'s own total, VAT included.'; ?></small></dd>

                        <?php $cvExtras = $cvType === 'room' ? (float)($cvAcct['row']['folio_charges_total'] ?? 0) : 0.0; ?>
                        <?php if ($cvExtras > BALANCE_TOLERANCE): ?>
                            <dt style="font-weight:600;">Extras on the bill</dt>
                            <dd style="margin:0;"><?php echo htmlspecialchars(rh_qc_money($cvExtras, $currency_symbol)); ?>
                                <small style="<?php echo $cvHelp; ?>">Charges added to the booking after the quotation (meals, services). The invoice lists them too.</small></dd>
                        <?php endif; ?>

                        <?php if ($cvType === 'room' && $qcPanel['rate_text'] !== ''): ?>
                            <dt style="font-weight:600;">Booked rate</dt>
                            <dd style="margin:0;"><?php echo htmlspecialchars($qcPanel['rate_text']); ?>
                                <small style="<?php echo $cvHelp; ?>">The nightly rate the room was booked at, times the nights.</small></dd>
                        <?php endif; ?>

                        <dt style="font-weight:600;">Paid so far</dt>
                        <dd style="margin:0;"><?php echo htmlspecialchars(rh_qc_money((float)$cvAcct['paid'], $currency_symbol)); ?>
                            <small style="<?php echo $cvHelp; ?>">Money received on this <?php echo $cvWord; ?>, less refunds.</small></dd>

                        <dt style="font-weight:600;">Still owed</dt>
                        <dd style="margin:0;"><?php echo htmlspecialchars(rh_qc_money((float)$cvAcct['due'], $currency_symbol)); ?>
                            <small style="<?php echo $cvHelp; ?>">What is still to be paid<?php echo $cvType === 'room' ? ', including any extras on the bill' : ''; ?>. The invoice asks for this.</small></dd>

                        <dt style="font-weight:600;">Invoice number</dt>
                        <dd style="margin:0;"><?php if ($qcPanel['existing_number'] !== ''): ?>
                                <?php echo htmlspecialchars($qcPanel['existing_number']); ?>
                                <small style="<?php echo $cvHelp; ?>">Already issued for this <?php echo $cvWord; ?>; the quotation is linked to it and no new number is used.</small>
                            <?php else: ?>
                                The next invoice number
                                <small style="<?php echo $cvHelp; ?>">Given when you convert, from the normal invoice numbering.</small>
                            <?php endif; ?></dd>
                    </dl>

                    <?php if (!empty($cvAmt['changed'])): ?>
                        <p style="background:#FFF3CD; color:#6b5203; border-radius:8px; padding:10px 12px; margin:0 0 14px; font-size:13px;">
                            <i class="fas fa-triangle-exclamation" style="margin-right:6px;"></i>
                            The <?php echo $cvType === 'room' ? 'stay' : 'enquiry'; ?> total changed since the quotation: quoted <?php echo htmlspecialchars(rh_qc_money((float)$cvAmt['quoted'], $currency_symbol)); ?>, now <?php echo htmlspecialchars(rh_qc_money((float)$cvAmt['current'], $currency_symbol)); ?>.
                            The invoice uses the current total, <?php echo htmlspecialchars(rh_qc_money((float)$cvAmt['current'], $currency_symbol)); ?>.
                        </p>
                    <?php endif; ?>
                <?php endif; ?>

                <?php if (!empty($cvEl['ok'])): ?>
                    <form method="POST" action="quotations.php" onsubmit="var b=this.querySelector('button[type=submit]');if(b){b.disabled=true;}">
                        <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrf_token, ENT_QUOTES, 'UTF-8'); ?>">
                        <input type="hidden" name="action" value="convert_invoice">
                        <input type="hidden" name="quotation_id" value="<?php echo (int)$cvQ['id']; ?>">
                        <?php if (!empty($cvEl['will_confirm'])): ?>
                            <p style="margin:0 0 10px; font-size:13px;"><i class="fas fa-circle-check" style="color:#155724; margin-right:6px;"></i>The booking is <?php echo htmlspecialchars((string)($cvAcct['status'] ?? '')); ?> now and will be confirmed.</p>
                        <?php elseif ((string)$cvEl['confirm_note'] !== ''): ?>
                            <p style="margin:0 0 10px; font-size:13px;"><i class="fas fa-circle-info" style="margin-right:6px;"></i><?php echo htmlspecialchars((string)$cvEl['confirm_note']); ?></p>
                        <?php endif; ?>
                        <label style="display:flex; gap:10px; align-items:flex-start; min-height:44px; margin:0 0 12px; cursor:pointer;">
                            <input type="checkbox" name="email_invoice" value="1" <?php echo $cvEmailOk ? 'checked' : 'disabled'; ?> style="width:20px; height:20px; margin-top:2px;">
                            <span>Email the invoice now
                                <small style="<?php echo $cvHelp; ?>"><?php echo $cvEmailOk
                                    ? 'To ' . htmlspecialchars($cvEmail) . ', with the usual invoice copies. Untick to keep it as a draft and send it later from the ' . $cvWord . '.'
                                    : 'There is no valid email address on the ' . $cvWord . ', so the invoice is kept as a draft to send later.'; ?></small>
                            </span>
                        </label>
                        <div style="display:flex; gap:10px; flex-wrap:wrap;">
                            <button type="submit" class="btn btn-primary" style="min-height:44px;"><i class="fas fa-file-invoice"></i> Convert to invoice</button>
                            <a href="quotations.php" class="btn btn-secondary" style="min-height:44px; display:inline-flex; align-items:center;">Cancel</a>
                        </div>
                    </form>
                <?php else: ?>
                    <p role="alert" style="background:#F8F4EE; color:#5c4a33; border-radius:8px; padding:10px 12px; margin:0 0 12px; font-size:13px;">
                        <i class="fas fa-circle-info" style="margin-right:6px;"></i><?php echo htmlspecialchars((string)$cvEl['reason']); ?>
                    </p>
                    <div style="display:flex; gap:10px; flex-wrap:wrap;">
                        <?php if ($cvAcct !== null): ?>
                            <a href="<?php echo htmlspecialchars($cvLink); ?>" class="btn btn-secondary" style="min-height:44px; display:inline-flex; align-items:center;">
                                <?php echo ($cvEl['offer'] ?? '') === 'receipt' ? 'Open the ' . $cvWord . ' to send the receipt' : 'Open the ' . $cvWord; ?>
                            </a>
                        <?php endif; ?>
                        <a href="quotations.php" class="btn btn-secondary" style="min-height:44px; display:inline-flex; align-items:center;">Back to quotations</a>
                    </div>
                <?php endif; ?>
            </section>
        <?php endif; ?>

        <!-- Table -->
        <div class="table-container" data-rh-results data-page-size-picker>
            <?php if (!empty($quotations)): ?>
                <table class="table">
                    <thead>
                        <tr>
                            <th>Quote Ref</th>
                            <th><?php echo $qt_mod_bookings ? 'Guest' : 'Client'; ?></th>
                            <th>Booking Ref</th>
                            <th>Type</th>
                            <th><?php echo $qt_mod_bookings ? 'Room / Package' : 'Package'; ?></th>
                            <th>Total</th>
                            <th>Valid Until</th>
                            <th>Status</th>
                            <th>Sent</th>
                            <th>Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($quotations as $q):
                            $isExpired = $q['status'] === 'sent'
                                && !empty($q['valid_until'])
                                && strtotime($q['valid_until']) < strtotime('today');
                            $displayStatus = rh_qc_display_status($q, $qcToday);
                            $statusLabels  = ['sent' => 'Sent', 'accepted' => 'Accepted', 'expired' => 'Expired', 'declined' => 'Declined', 'converted' => 'Converted'];
                            $statusIcons   = ['sent' => 'fa-paper-plane', 'accepted' => 'fa-circle-check', 'expired' => 'fa-clock', 'declined' => 'fa-circle-xmark', 'converted' => 'fa-file-invoice'];
                            $qcType        = trim((string)($q['booking_type'] ?? '')) ?: 'room';
                            $qcShowConvert = $qcCols && !empty($qcCanInvoice[$qcType]) && in_array($displayStatus, ['sent', 'accepted'], true);
                            $bookingLink   = $q['booking_type'] === 'conference'
                                ? 'conference-management.php'
                                : ($q['booking_type'] === 'event'
                                    ? 'events-management.php'
                                    : 'booking-details.php?id=' . (int)$q['booking_id']);
                        ?>
                            <tr>
                                <td data-label="Quote Ref">
                                    <span class="tbl-ref"><?php echo htmlspecialchars($q['quote_reference']); ?></span>
                                </td>
                                <td data-label="Guest">
                                    <strong><?php echo htmlspecialchars($q['guest_name'] ?? '—'); ?></strong>
                                    <?php if (!empty($q['guest_email'])): ?>
                                        <br><small style="color:#777;"><?php echo htmlspecialchars($q['guest_email']); ?></small>
                                    <?php endif; ?>
                                </td>
                                <td data-label="Booking Ref">
                                    <?php echo htmlspecialchars($q['booking_reference'] ?? '—'); ?>
                                </td>
                                <td data-label="Type">
                                    <span class="qt-type-badge"><?php echo htmlspecialchars(ucfirst($q['booking_type'] ?? 'room')); ?></span>
                                </td>
                                <td data-label="Room"><?php echo htmlspecialchars($q['room_name'] ?? '—'); ?></td>
                                <td data-label="Total">
                                    <strong><?php echo $currency_symbol . ' ' . number_format((float)$q['total_amount'], 0); ?></strong>
                                </td>
                                <td data-label="Valid Until">
                                    <?php if (!empty($q['valid_until'])): ?>
                                        <?php echo date('M j, Y', strtotime($q['valid_until'])); ?>
                                        <?php if ($isExpired): ?>
                                            <br><small style="color:#dc3545;">Expired</small>
                                        <?php elseif ($q['status'] === 'sent'): ?>
                                            <?php $daysLeft = (int)ceil((strtotime($q['valid_until']) - time()) / 86400); ?>
                                            <br><small style="color:<?php echo $daysLeft <= 2 ? '#e65c00' : '#888'; ?>;"><?php echo $daysLeft; ?> day<?php echo $daysLeft !== 1 ? 's' : ''; ?> left</small>
                                        <?php endif; ?>
                                    <?php else: ?>
                                        —
                                    <?php endif; ?>
                                </td>
                                <td data-label="Status">
                                    <span class="qt-badge qt-badge--<?php echo $displayStatus; ?>">
                                        <i class="fas <?php echo $statusIcons[$displayStatus] ?? 'fa-circle'; ?>"></i>
                                        <?php echo $statusLabels[$displayStatus] ?? ucfirst($displayStatus); ?>
                                    </span>
                                    <?php if ($displayStatus === 'converted'): $qcInv = trim((string)($q['converted_invoice_number'] ?? '')); ?>
                                        <br><small style="color:#555;">To invoice
                                            <?php if (!empty($qcCanInvoice[$qcType])): ?>
                                                <a href="quotations.php?invoice=<?php echo (int)$q['id']; ?>" data-no-spa="1" data-no-admin-loader="1" title="Download invoice <?php echo htmlspecialchars($qcInv, ENT_QUOTES); ?>"><?php echo htmlspecialchars($qcInv); ?></a>
                                            <?php else: ?>
                                                <?php echo htmlspecialchars($qcInv); ?>
                                            <?php endif; ?>
                                            <?php if (!empty($q['converted_at'])): ?><br><?php echo date('M j, Y', strtotime((string)$q['converted_at'])); ?><?php endif; ?>
                                        </small>
                                    <?php endif; ?>
                                </td>
                                <td data-label="Sent">
                                    <?php echo !empty($q['sent_at']) ? date('M j, Y', strtotime($q['sent_at'])) : '—'; ?>
                                    <?php if (!empty($q['sent_at'])): ?>
                                        <br><small style="color:#888;"><?php echo date('g:i A', strtotime($q['sent_at'])); ?></small>
                                    <?php endif; ?>
                                </td>
                                <td data-label="Actions">
                                    <div class="tbl-actions">
                                        <a href="quotations.php?download=<?php echo (int)$q['id']; ?>" class="tbl-btn tbl-btn--download" title="Download PDF" data-no-spa="1" data-no-admin-loader="1">
                                            <i class="fas fa-file-pdf"></i> PDF
                                        </a>
                                        <?php if (!in_array($displayStatus, ['accepted', 'converted'], true)): ?>
                                            <form method="POST" style="display:inline;">
                                                <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrf_token, ENT_QUOTES, 'UTF-8'); ?>">
                                                <input type="hidden" name="action" value="resend">
                                                <input type="hidden" name="quotation_id" value="<?php echo (int)$q['id']; ?>">
                                                <button type="submit" class="tbl-btn tbl-btn--resend" title="Resend quotation email">
                                                    <i class="fas fa-paper-plane"></i> Resend
                                                </button>
                                            </form>
                                        <?php endif; ?>
                                        <a href="<?php echo htmlspecialchars($bookingLink); ?>" class="tbl-btn tbl-btn--view" title="View booking">
                                            <i class="fas fa-circle-info"></i> Booking
                                        </a>
                                        <?php if ($qcShowConvert): ?>
                                            <a href="quotations.php?convert=<?php echo (int)$q['id']; ?>#qt-convert" class="tbl-btn tbl-btn--confirm" title="Convert this quotation to an invoice">
                                                <i class="fas fa-file-invoice"></i> Convert to invoice
                                            </a>
                                        <?php endif; ?>
                                        <?php if ($q['status'] === 'sent'): ?>
                                            <form method="POST" style="display:inline;">
                                                <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrf_token, ENT_QUOTES, 'UTF-8'); ?>">
                                                <input type="hidden" name="action" value="mark_accepted">
                                                <input type="hidden" name="quotation_id" value="<?php echo (int)$q['id']; ?>">
                                                <button type="submit" class="tbl-btn tbl-btn--accept" title="Mark accepted">
                                                    <i class="fas fa-check"></i>
                                                </button>
                                            </form>
                                            <form method="POST" style="display:inline;">
                                                <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrf_token, ENT_QUOTES, 'UTF-8'); ?>">
                                                <input type="hidden" name="action" value="mark_declined">
                                                <input type="hidden" name="quotation_id" value="<?php echo (int)$q['id']; ?>">
                                                <button type="submit" class="tbl-btn tbl-btn--decline" title="Mark declined">
                                                    <i class="fas fa-times"></i>
                                                </button>
                                            </form>
                                        <?php endif; ?>
                                    </div>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            <?php else: ?>
                <div class="qt-empty">
                    <i class="fas fa-file-invoice-dollar"></i>
                    <strong style="display:block;font-size:1.1rem;color:#555;margin-bottom:6px;">No quotations yet</strong>
                    <p style="font-size:13px;">
                        <?php if ($qt_mod_bookings): ?>
                        Send a quotation from a booking's detail page, the bookings list, or the tentative bookings page.
                        <?php else: ?>
                        Send a quotation from a conference or event enquiry's detail page.
                        <?php endif; ?>
                    </p>
                    <div style="display:flex;gap:10px;justify-content:center;margin-top:16px;">
                        <?php if ($qt_mod_bookings): ?>
                        <a href="bookings.php" class="btn btn-primary" style="font-size:13px;padding:8px 16px;">
                            <i class="fas fa-calendar-check"></i> Go to Bookings
                        </a>
                        <a href="tentative-bookings.php" class="btn btn-secondary" style="font-size:13px;padding:8px 16px;">
                            <i class="fas fa-clock"></i> Tentative Bookings
                        </a>
                        <?php elseif ($qt_mod_conf): ?>
                        <a href="conference-management.php" class="btn btn-primary" style="font-size:13px;padding:8px 16px;">
                            <i class="fas fa-briefcase"></i> Go to Conference Bookings
                        </a>
                        <?php endif; ?>
                    </div>
                </div>
            <?php endif; ?>
        </div>
    </div>

    <?php require_once 'includes/admin-footer.php'; ?>
</body>

</html>

