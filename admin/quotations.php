<?php
require_once 'admin-init.php';
/** @var PDO $pdo */
/** @var array $user */
/** @var string $csrf_token */

require_once '../includes/quotation-pdf.php';
require_once '../config/email.php';
require_once __DIR__ . '/includes/booking-lifecycle.php';
require_once __DIR__ . '/../includes/alert.php';

$site_name       = getSetting('site_name');
$currency_symbol = getSetting('currency_symbol', 'MWK');

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
                $qStmt = $pdo->prepare("SELECT q.*, b.* FROM quotations q LEFT JOIN bookings b ON q.booking_id = b.id WHERE q.id = ?");
                $qStmt->execute([$quotation_id]);
                $qRow = $qStmt->fetch(PDO::FETCH_ASSOC);

                if (!$qRow) {
                    throw new Exception('Quotation not found.');
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
                    $pdo->prepare("UPDATE quotations SET status = 'sent', sent_at = NOW(), updated_at = NOW() WHERE id = ?")->execute([$quotation_id]);
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
            $mqStmt = $pdo->prepare("SELECT q.*, b.status as booking_status, b.amount_paid, b.amount_due, b.total_amount FROM quotations q LEFT JOIN bookings b ON q.booking_id = b.id WHERE q.id = ?");
            $mqStmt->execute([$quotation_id]);
            $mqRow = $mqStmt->fetch(PDO::FETCH_ASSOC);
            if ($mqRow) {
                $expiryDate = !empty($mqRow['expires_at']) ? $mqRow['expires_at'] : null;
                if ($expiryDate && $expiryDate < date('Y-m-d')) {
                    $error = 'Cannot accept an expired quotation (expired ' . $expiryDate . ').';
                } else {
                    $lcCheck = bookingAllowsAction(['status' => $mqRow['booking_status'] ?? 'pending', 'amount_paid' => $mqRow['amount_paid'] ?? 0, 'amount_due' => $mqRow['amount_due'] ?? 0, 'total_amount' => $mqRow['total_amount'] ?? 0], 'mark_quotation');
                    if (!$lcCheck['allowed']) {
                        $error = $lcCheck['reason'];
                    } else {
                        $pdo->prepare("UPDATE quotations SET status = 'accepted', updated_at = NOW() WHERE id = ?")->execute([$quotation_id]);
                        if (!empty($mqRow['booking_id'])) {
                            $pdo->prepare("UPDATE bookings SET status = 'confirmed', updated_at = NOW() WHERE id = ? AND status IN ('pending','tentative')")->execute([$mqRow['booking_id']]);
                        }
                        $message = 'Quotation marked as accepted and booking confirmed.';
                    }
                }
            } else {
                $error = 'Quotation not found.';
            }
        }

        if ($action === 'mark_declined' && $quotation_id > 0) {
            $mdStmt = $pdo->prepare("SELECT q.*, b.status as booking_status, b.amount_paid, b.amount_due, b.total_amount FROM quotations q LEFT JOIN bookings b ON q.booking_id = b.id WHERE q.id = ?");
            $mdStmt->execute([$quotation_id]);
            $mdRow = $mdStmt->fetch(PDO::FETCH_ASSOC);
            if ($mdRow) {
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
if (!empty($_SESSION['qt_flash'])) {
    $flash = $_SESSION['qt_flash'];
    unset($_SESSION['qt_flash']);
    if ($flash['type'] === 'success') {
        $message = $flash['msg'];
    } else {
        $error = $flash['msg'];
    }
}

// ── Handle PDF download ───────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'GET' && isset($_GET['download'])) {
    $dlId = (int)($_GET['download'] ?? 0);
    if ($dlId > 0) {
        $dlStmt = $pdo->prepare("SELECT q.*, b.* FROM quotations q LEFT JOIN bookings b ON q.booking_id = b.id WHERE q.id = ?");
        $dlStmt->execute([$dlId]);
        $dlRow = $dlStmt->fetch(PDO::FETCH_ASSOC);
        if ($dlRow) {
            // Try saved PDF first
            if (!empty($dlRow['pdf_path'])) {
                $absPath = dirname(__DIR__) . '/' . ltrim($dlRow['pdf_path'], '/');
                if (file_exists($absPath)) {
                    header('Content-Type: application/pdf');
                    header('Content-Disposition: attachment; filename="' . $dlRow['quote_reference'] . '.pdf"');
                    header('Content-Length: ' . filesize($absPath));
                    readfile($absPath);
                    exit;
                }
            }
            // Regenerate on the fly
            $booking = $dlRow;
            $rStmt   = $pdo->prepare("SELECT * FROM rooms WHERE id = ? LIMIT 1");
            $rStmt->execute([$dlRow['booking_id']]);
            $room    = $rStmt->fetch(PDO::FETCH_ASSOC) ?: [];

            try {
                $pdfBinary = generateQuotationPDF($booking, $room, [
                    'valid_days'      => (int)($dlRow['valid_days'] ?? 7),
                    'quotation_notes' => $dlRow['quotation_notes'] ?? '',
                ]);
                // Save regenerated PDF
                $quotationsDir = dirname(__DIR__) . '/quotations';
                if (!is_dir($quotationsDir)) {
                    mkdir($quotationsDir, 0755, true);
                }
                $fname   = $dlRow['quote_reference'] . '-' . date('Ymd') . '.pdf';
                $absFile = $quotationsDir . '/' . $fname;
                file_put_contents($absFile, $pdfBinary);
                $pdo->prepare("UPDATE quotations SET pdf_path = ? WHERE id = ?")->execute(['quotations/' . $fname, $dlId]);

                header('Content-Type: application/pdf');
                header('Content-Disposition: attachment; filename="' . $dlRow['quote_reference'] . '.pdf"');
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

if ($filter_type !== '') {
    $where[]  = 'q.booking_type = ?';
    $params[] = $filter_type;
}
if ($filter_status !== '') {
    $where[]  = 'q.status = ?';
    $params[] = $filter_status;
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
$stats      = ['total' => 0, 'sent' => 0, 'accepted' => 0, 'expired' => 0, 'declined' => 0, 'total_value' => 0];

try {
    $listStmt   = $pdo->prepare($sql);
    $listStmt->execute($params);
    $quotations = $listStmt->fetchAll(PDO::FETCH_ASSOC);

    foreach ($quotations as $q) {
        $stats['total']++;
        $stats[$q['status'] ?? 'sent'] = ($stats[$q['status'] ?? 'sent'] ?? 0) + 1;
        $stats['total_value']          += (float)($q['total_amount'] ?? 0);

        // Auto-expire
        if ($q['status'] === 'sent' && !empty($q['valid_until']) && strtotime($q['valid_until']) < strtotime('today')) {
            $pdo->prepare("UPDATE quotations SET status = 'expired', updated_at = NOW() WHERE id = ?")->execute([$q['id']]);
        }
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
    <link rel="stylesheet" href="css/admin-styles.css">
    <link rel="stylesheet" href="css/admin-components.css">
    <link rel="stylesheet" href="css/quotations.css">
</head>

<body>
    <?php require_once 'includes/admin-header.php'; ?>

    <div class="admin-container">
        <div class="qt-header">
            <h1><i class="fas fa-file-invoice-dollar" style="color:#B18247;"></i> Quotations</h1>
            <div style="display:flex;gap:10px;align-items:center;">
                <a href="bookings.php" class="btn btn-secondary" style="font-size:13px;padding:7px 14px;">
                    <i class="fas fa-calendar-check"></i> All Bookings
                </a>
                <a href="tentative-bookings.php" class="btn btn-secondary" style="font-size:13px;padding:7px 14px;">
                    <i class="fas fa-clock"></i> Tentative
                </a>
            </div>
        </div>

        <?php if ($message): showAlert($message, 'success'); endif; ?>
        <?php if ($error): showAlert($error, 'error'); endif; ?>

        <!-- Stats -->
        <div class="qt-stats">
            <div class="qt-stat">
                <div class="qt-stat__label">Total Issued</div>
                <div class="qt-stat__value"><?php echo $stats['total']; ?></div>
            </div>
            <div class="qt-stat">
                <div class="qt-stat__label">Sent / Active</div>
                <div class="qt-stat__value" style="color:#2F4F78;"><?php echo $stats['sent']; ?></div>
            </div>
            <div class="qt-stat">
                <div class="qt-stat__label">Accepted</div>
                <div class="qt-stat__value" style="color:#155724;"><?php echo $stats['accepted']; ?></div>
            </div>
            <div class="qt-stat">
                <div class="qt-stat__label">Expired</div>
                <div class="qt-stat__value" style="color:#888;"><?php echo $stats['expired']; ?></div>
            </div>
            <div class="qt-stat">
                <div class="qt-stat__label">Declined</div>
                <div class="qt-stat__value" style="color:#721C24;"><?php echo $stats['declined']; ?></div>
            </div>
            <div class="qt-stat qt-stat--value">
                <div class="qt-stat__label">Total Quoted Value</div>
                <div class="qt-stat__value"><?php echo $currency_symbol . ' ' . number_format($stats['total_value'], 0); ?></div>
            </div>
        </div>

        <!-- Filters -->
        <form method="GET" class="qt-filters">
            <div>
                <label>Search</label>
                <input type="text" name="search" placeholder="Guest name, email, reference…" value="<?php echo htmlspecialchars($search); ?>">
            </div>
            <div>
                <label>Type</label>
                <select name="type">
                    <option value="">All types</option>
                    <option value="room" <?php echo $filter_type === 'room'       ? 'selected' : ''; ?>>Room</option>
                    <option value="conference" <?php echo $filter_type === 'conference' ? 'selected' : ''; ?>>Conference</option>
                    <option value="event" <?php echo $filter_type === 'event'      ? 'selected' : ''; ?>>Event</option>
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

        <!-- Table -->
        <div class="table-container">
            <?php if (!empty($quotations)): ?>
                <table class="table">
                    <thead>
                        <tr>
                            <th>Quote Ref</th>
                            <th>Guest</th>
                            <th>Booking Ref</th>
                            <th>Type</th>
                            <th>Room / Package</th>
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
                            $displayStatus = $isExpired ? 'expired' : ($q['status'] ?? 'sent');
                            $statusLabels  = ['sent' => 'Sent', 'accepted' => 'Accepted', 'expired' => 'Expired', 'declined' => 'Declined'];
                            $statusIcons   = ['sent' => 'fa-paper-plane', 'accepted' => 'fa-circle-check', 'expired' => 'fa-clock', 'declined' => 'fa-circle-xmark'];
                            $bookingLink   = $q['booking_type'] === 'conference'
                                ? 'conference-management.php'
                                : 'booking-details.php?id=' . (int)$q['booking_id'];
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
                                        <?php if ($displayStatus !== 'accepted'): ?>
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
                        Send a quotation from a booking's detail page, the bookings list, or the tentative bookings page.
                    </p>
                    <div style="display:flex;gap:10px;justify-content:center;margin-top:16px;">
                        <a href="bookings.php" class="btn btn-primary" style="font-size:13px;padding:8px 16px;">
                            <i class="fas fa-calendar-check"></i> Go to Bookings
                        </a>
                        <a href="tentative-bookings.php" class="btn btn-secondary" style="font-size:13px;padding:8px 16px;">
                            <i class="fas fa-clock"></i> Tentative Bookings
                        </a>
                    </div>
                </div>
            <?php endif; ?>
        </div>
    </div>

    <?php require_once 'includes/admin-footer.php'; ?>
</body>

</html>

