<?php
require_once 'admin-init.php';
/** @var \PDO $pdo */
/** @var array $user */
/** @var string $csrf_token */

$user = [
    'id'        => $_SESSION['admin_user_id'],
    'username'  => $_SESSION['admin_username'],
    'role'      => $_SESSION['admin_role'],
    'full_name' => $_SESSION['admin_full_name'],
];

$message = '';
$error   = '';

// ── Auto-expire any stale bookings silently before rendering ─────────────────
try {
    $auto_expired = $pdo->prepare("
        UPDATE bookings
        SET    status = 'expired', is_tentative = 0, expired_at = NOW()
        WHERE  is_tentative = 1
          AND  status       = 'tentative'
          AND  tentative_expires_at < NOW()
    ");
    $auto_expired->execute();
    $auto_expired_count = $auto_expired->rowCount();
    if ($auto_expired_count > 0) {
        // Log each auto-expired booking
        $stale = $pdo->query("
            SELECT id FROM bookings
            WHERE status = 'expired' AND expired_at >= DATE_SUB(NOW(), INTERVAL 30 SECOND)
        ")->fetchAll(PDO::FETCH_COLUMN);
        $ins = $pdo->prepare("
            INSERT IGNORE INTO tentative_booking_log (booking_id, action, performed_by, action_reason)
            VALUES (?, 'expired', NULL, 'Auto-expired by system on page load')
        ");
        foreach ($stale as $sid) {
            $ins->execute([$sid]);
        }
    }
} catch (PDOException $e) {
    error_log('Auto-expire error: ' . $e->getMessage());
    $auto_expired_count = 0;
}

// ── Handle form POST actions (convert / cancel) ──────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!validateCsrfToken($_POST['csrf_token'] ?? '')) {
        if (!empty($_SERVER['HTTP_X_REQUESTED_WITH'])) {
            header('Content-Type: application/json');
            echo json_encode(['success' => false, 'message' => 'Security token invalid.']);
            exit;
        }
        header('Location: ' . basename($_SERVER['PHP_SELF']));
        exit;
    }

    try {
        $action     = $_POST['action'] ?? '';
        $booking_id = (int)($_POST['id'] ?? 0);

        if ($booking_id <= 0) {
            throw new Exception('Invalid booking id');
        }

        if (!in_array($user['role'] ?? '', ['admin', 'manager'], true)) {
            throw new Exception('Your role does not have permission to manage tentative bookings.');
        }

        if ($action === 'convert') {
            $stmt = $pdo->prepare("SELECT * FROM bookings WHERE id = ?");
            $stmt->execute([$booking_id]);
            $booking = $stmt->fetch(PDO::FETCH_ASSOC);

            if (!$booking || $booking['status'] !== 'tentative' || !$booking['is_tentative']) {
                throw new Exception('This is not an active tentative booking.');
            }

            $pdo->prepare("UPDATE bookings SET status = 'confirmed', is_tentative = 0, converted_to_confirmed_at = NOW(), converted_from_tentative = 1 WHERE id = ?")
                ->execute([$booking_id]);

            $pdo->prepare("INSERT INTO tentative_booking_log (booking_id, action, performed_by, action_reason) VALUES (?, 'converted', ?, ?)")
                ->execute([$booking_id, (int)$user['id'], 'Converted to confirmed by ' . $user['full_name']]);

            require_once '../config/email.php';
            $email_result = sendTentativeBookingConvertedEmail($booking);
            $message = 'Booking converted to confirmed!'
                . ($email_result['success'] ? ' Confirmation email sent.' : ' (Email failed: ' . htmlspecialchars($email_result['message'] ?? '') . ')');
        } elseif ($action === 'cancel') {
            $stmt = $pdo->prepare("SELECT b.*, r.name as room_name FROM bookings b LEFT JOIN rooms r ON b.room_id = r.id WHERE b.id = ?");
            $stmt->execute([$booking_id]);
            $booking = $stmt->fetch(PDO::FETCH_ASSOC);

            if (!$booking) {
                throw new Exception('Booking not found.');
            }

            $pdo->prepare("UPDATE bookings SET status = 'cancelled', is_tentative = 0 WHERE id = ?")
                ->execute([$booking_id]);

            $pdo->prepare("INSERT INTO tentative_booking_log (booking_id, action, performed_by, action_reason) VALUES (?, 'cancelled', ?, ?)")
                ->execute([$booking_id, (int)$user['id'], 'Cancelled by ' . $user['full_name']]);

            // Notify guest
            require_once '../config/email.php';
            $cancelReason = 'Tentative hold cancelled by hotel';
            $cancel_email_result = sendBookingCancelledEmail($booking, $cancelReason);
            logCancellationToDatabase(
                $booking['id'],
                $booking['booking_reference'],
                'room',
                $booking['guest_email'],
                $user['id'],
                $cancelReason,
                $cancel_email_result['success'],
                $cancel_email_result['message'] ?? ''
            );

            $message = 'Tentative booking cancelled and room hold released.'
                . ($cancel_email_result['success'] ? ' Guest notified.' : ' (Guest notification failed.)');
        }
    } catch (Exception $e) {
        $error = $e->getMessage();
    }
}

// ── Fetch active tentative bookings ─────────────────────────────────────────
$tentative_bookings_feature_enabled = getSetting('tentative_bookings_enabled', '1') !== '0';
$tentative_reminder_hours = (int)getSetting('tentative_reminder_hours', 24);
$site_name      = getSetting('site_name');
$currency_symbol = getSetting('currency_symbol');

try {
    $stmt = $pdo->query("
        SELECT b.*,
               r.name AS room_name,
               TIMESTAMPDIFF(SECOND, NOW(), b.tentative_expires_at) AS seconds_remaining
        FROM   bookings b
        LEFT JOIN rooms r ON b.room_id = r.id
        WHERE  b.status = 'tentative' AND b.is_tentative = 1
        ORDER  BY b.tentative_expires_at ASC
    ");
    $active = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    $error  = 'Error fetching tentative bookings: ' . $e->getMessage();
    $active = [];
}

// ── Fetch recently expired tentative bookings (last 14 days) ────────────────
try {
    $stmt = $pdo->query("
        SELECT b.*, r.name AS room_name
        FROM   bookings b
        LEFT JOIN rooms r ON b.room_id = r.id
        WHERE  b.status = 'expired'
          AND  b.converted_from_tentative = 0
          AND  b.expired_at >= DATE_SUB(NOW(), INTERVAL 14 DAY)
        ORDER  BY b.expired_at DESC
        LIMIT  30
    ");
    $recently_expired = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    $recently_expired = [];
}

// ── Calculate stats ───────────────────────────────────────────────────────────
$stat_total           = count($active);
$stat_expiring_today  = 0;
$stat_reminder_due    = 0;
$stat_expired_14d     = count($recently_expired);

$now = new DateTime();
foreach ($active as $bk) {
    $exp_ts        = (int)strtotime((string)$bk['tentative_expires_at']);
    $hours_left    = ($exp_ts - $now->getTimestamp()) / 3600;
    $reminder_due  = $exp_ts - ($tentative_reminder_hours * 3600);

    if ($hours_left <= 24) {
        $stat_expiring_today++;
    }
    if (!$bk['reminder_sent'] && $reminder_due < $now->getTimestamp()) {
        $stat_reminder_due++;
    }
}
?>
<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="csrf-token" content="<?php echo htmlspecialchars($csrf_token, ENT_QUOTES, 'UTF-8'); ?>">
    <title>Tentative Bookings — <?php echo htmlspecialchars($site_name); ?> Admin</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Cormorant+Garamond:ital,wght@0,300;0,400;0,500;0,600;1,300;1,400;1,500&family=Jost:wght@300;400;500;600&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="css/admin-styles.css?v=<?php echo @filemtime(__DIR__ . '/css/admin-styles.css'); ?>">
    <link rel="stylesheet" href="css/admin-components.css?v=<?php echo @filemtime(__DIR__ . '/css/admin-components.css'); ?>">
    <link rel="stylesheet" href="css/admin-finance.css?v=<?php echo @filemtime(__DIR__ . '/css/admin-finance.css'); ?>">
</head>

<body>

    <?php require_once 'includes/admin-header.php'; ?>

    <div class="content finance-page">

        <!-- Page header -->
        <div class="acct-page-header">
            <div class="acct-page-header__copy">
                <h1 class="acct-page-header__title"><i class="fas fa-clock"></i> Tentative Bookings</h1>
                <p class="acct-page-header__subtitle">Hold management, expiry tracking, and guest follow-up</p>
            </div>
            <div style="display: flex; gap: 8px; flex-wrap: wrap;">
                <a href="bookings.php" class="acct-quick-action">
                    <i class="fas fa-arrow-left"></i> All Bookings
                </a>
                <a href="booking-settings.php" class="acct-quick-action">
                    <i class="fas fa-gear"></i> Hold Settings
                </a>
            </div>
        </div>

        <?php if (!$tentative_bookings_feature_enabled): ?>
            <div class="acct-error" style="margin-bottom: 16px; background: #fff7ed; border-color: #fb923c; color: #9a3412;">
                <i class="fas fa-toggle-off"></i>
                <strong>Tentative bookings are currently disabled.</strong>
                Guests cannot create new tentative holds — the option is hidden on the public booking page and admin forms.
                <a href="booking-settings.php#tentative" style="color: inherit; font-weight: 700; margin-left: 8px;">Enable in Booking Settings →</a>
            </div>
        <?php endif; ?>

        <?php if ($auto_expired_count > 0): ?>
            <div class="acct-info" style="margin-bottom: 16px;">
                <i class="fas fa-clock-rotate-left"></i>
                <?php echo $auto_expired_count; ?> overdue tentative booking<?php echo $auto_expired_count === 1 ? '' : 's'; ?> auto-expired and room<?php echo $auto_expired_count === 1 ? '' : 's'; ?> released.
            </div>
        <?php endif; ?>

        <?php if ($message): ?>
            <div class="acct-success" style="margin-bottom: 16px;">
                <i class="fas fa-circle-check"></i> <?php echo htmlspecialchars($message); ?>
            </div>
        <?php endif; ?>

        <?php if ($error): ?>
            <div class="acct-error" style="margin-bottom: 16px;">
                <i class="fas fa-circle-exclamation"></i> <?php echo htmlspecialchars($error); ?>
            </div>
        <?php endif; ?>

        <!-- KPI Strip -->
        <div class="acct-kpis">
            <div class="acct-kpi acct-kpi--revenue">
                <div class="acct-kpi__label">Active Holds</div>
                <div class="acct-kpi__value"><?php echo $stat_total; ?></div>
                <div class="acct-kpi__meta">Rooms on tentative hold</div>
            </div>
            <div class="acct-kpi <?php echo $stat_expiring_today > 0 ? 'acct-kpi--receivables' : 'acct-kpi--cash'; ?>">
                <div class="acct-kpi__label">Expiring in 24h</div>
                <div class="acct-kpi__value"><?php echo $stat_expiring_today; ?></div>
                <div class="acct-kpi__meta">Need urgent follow-up</div>
            </div>
            <div class="acct-kpi <?php echo $stat_reminder_due > 0 ? 'acct-kpi--vat' : 'acct-kpi--cash'; ?>">
                <div class="acct-kpi__label">Reminders Overdue</div>
                <div class="acct-kpi__value"><?php echo $stat_reminder_due; ?></div>
                <div class="acct-kpi__meta">Guests not yet reminded</div>
            </div>
            <div class="acct-kpi">
                <div class="acct-kpi__label">Expired (14 days)</div>
                <div class="acct-kpi__value"><?php echo $stat_expired_14d; ?></div>
                <div class="acct-kpi__meta">Lapsed holds for review</div>
            </div>
        </div>

        <!-- Filter tabs -->
        <?php
        $tab_counts = [
            'all'      => count($active),
            'soon'     => 0,
            'reminder' => 0,
        ];
        foreach ($active as $bk) {
            $exp_ts     = (int)strtotime((string)$bk['tentative_expires_at']);
            $hrs_left   = ($exp_ts - $now->getTimestamp()) / 3600;
            $rem_due_ts = $exp_ts - ($tentative_reminder_hours * 3600);
            if ($hrs_left <= 24) {
                $tab_counts['soon']++;
            }
            if (!$bk['reminder_sent'] && $rem_due_ts < $now->getTimestamp()) {
                $tab_counts['reminder']++;
            }
        }
        ?>
        <div class="tb-tabs" id="tb-tabs">
            <button class="tb-tab tb-tab--active" data-filter="all">
                <i class="fas fa-list"></i> All Active
                <span class="tb-tab__count"><?php echo $tab_counts['all']; ?></span>
            </button>
            <button class="tb-tab" data-filter="soon">
                <i class="fas fa-fire"></i> Expiring Soon
                <span class="tb-tab__count"><?php echo $tab_counts['soon']; ?></span>
            </button>
            <button class="tb-tab" data-filter="reminder">
                <i class="fas fa-bell-slash"></i> Reminder Overdue
                <span class="tb-tab__count"><?php echo $tab_counts['reminder']; ?></span>
            </button>
        </div>

        <!-- Active tentative bookings table -->
        <div class="acct-panel" style="margin-bottom: 24px;">
            <div class="acct-panel__head">
                <h3 class="acct-panel__title"><i class="fas fa-hourglass-half"></i> Active Tentative Holds</h3>
                <span class="acct-panel__sub" id="tb-visible-count"><?php echo count($active); ?> booking<?php echo count($active) === 1 ? '' : 's'; ?></span>
            </div>

            <?php if (empty($active)): ?>
                <div style="padding: 48px 20px; text-align: center;">
                    <i class="fas fa-circle-check" style="font-size: 40px; color: #10b981; margin-bottom: 14px; display: block;"></i>
                    <p style="font-size: 15px; font-weight: 600; color: var(--finance-text); margin: 0 0 6px;">No active tentative holds</p>
                    <p style="font-size: 13px; color: var(--finance-muted); margin: 0;">All rooms are either confirmed, expired, or clear.</p>
                </div>
            <?php else: ?>
                <div class="acct-table-wrap">
                    <table class="acct-table" id="tb-table">
                        <thead>
                            <tr>
                                <th>Reference</th>
                                <th>Guest</th>
                                <th>Room</th>
                                <th>Dates</th>
                                <th class="num">Total</th>
                                <th>Expires</th>
                                <th>Reminder</th>
                                <th>Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($active as $bk):
                                $exp_ts      = (int)strtotime((string)$bk['tentative_expires_at']);
                                $secs_left   = $exp_ts - $now->getTimestamp();
                                $hrs_left    = $secs_left / 3600;
                                $is_critical = $hrs_left <= 4  && $hrs_left > 0;
                                $is_soon     = $hrs_left <= 24 && $hrs_left > 0;
                                $rem_due_ts  = $exp_ts - ($tentative_reminder_hours * 3600);
                                $rem_sent    = !empty($bk['reminder_sent']);
                                $rem_overdue = !$rem_sent && $rem_due_ts < $now->getTimestamp();

                                if ($is_critical) {
                                    $row_class = 'tb-row--expired'; // same tint for critical
                                } elseif ($is_soon) {
                                    $row_class = 'tb-row--soon';
                                } else {
                                    $row_class = '';
                                }

                                $filter_tags = 'all';
                                if ($is_soon || $is_critical) {
                                    $filter_tags .= ' soon';
                                }
                                if ($rem_overdue) {
                                    $filter_tags .= ' reminder';
                                }
                            ?>
                                <tr class="<?php echo $row_class; ?>" data-filters="<?php echo htmlspecialchars($filter_tags); ?>"
                                    data-booking-id="<?php echo (int)$bk['id']; ?>"
                                    data-expires-ts="<?php echo $exp_ts; ?>"
                                    data-expires-formatted="<?php echo htmlspecialchars(date('M j, Y · H:i', $exp_ts)); ?>">

                                    <td>
                                        <strong style="font-family: 'Courier New', monospace; font-size: 12px;"><?php echo htmlspecialchars($bk['booking_reference']); ?></strong>
                                    </td>

                                    <td>
                                        <div style="font-weight: 600; font-size: 13px;"><?php echo htmlspecialchars($bk['guest_name']); ?></div>
                                        <div style="font-size: 11.5px; color: var(--finance-muted);"><?php echo htmlspecialchars($bk['guest_email']); ?></div>
                                        <?php if (!empty($bk['guest_phone'])): ?>
                                            <div style="font-size: 11.5px; color: var(--finance-muted);"><?php echo htmlspecialchars($bk['guest_phone']); ?></div>
                                        <?php endif; ?>
                                    </td>

                                    <td><?php echo htmlspecialchars($bk['room_name']); ?></td>

                                    <td>
                                        <div style="font-size: 12.5px;">
                                            <?php echo date('M j', strtotime($bk['check_in_date'])); ?> →
                                            <?php echo date('M j, Y', strtotime($bk['check_out_date'])); ?>
                                        </div>
                                        <div style="font-size: 11.5px; color: var(--finance-muted);">
                                            <?php echo (int)$bk['number_of_nights']; ?> night<?php echo (int)$bk['number_of_nights'] === 1 ? '' : 's'; ?> · <?php echo (int)$bk['number_of_guests']; ?> guest<?php echo (int)$bk['number_of_guests'] === 1 ? '' : 's'; ?>
                                        </div>
                                    </td>

                                    <td class="num">
                                        <strong><?php echo htmlspecialchars($currency_symbol); ?><?php echo number_format((float)$bk['total_amount'], 0); ?></strong>
                                    </td>

                                    <td>
                                        <div style="font-size: 12px; color: var(--finance-muted); margin-bottom: 4px;">
                                            <?php echo date('M j · H:i', $exp_ts); ?>
                                        </div>
                                        <span class="tb-countdown <?php echo $is_critical ? 'tb-countdown--critical' : ($is_soon ? 'tb-countdown--soon' : 'tb-countdown--active'); ?>"
                                            data-countdown="<?php echo $exp_ts; ?>">
                                            <i class="fas fa-clock"></i>
                                            <span class="tb-countdown__text"><?php
                                                                                if ($secs_left <= 0) {
                                                                                    echo 'Expiring';
                                                                                } elseif ($secs_left < 3600) {
                                                                                    echo round($secs_left / 60) . 'm left';
                                                                                } elseif ($secs_left < 86400) {
                                                                                    echo round($secs_left / 3600, 1) . 'h left';
                                                                                } else {
                                                                                    echo round($secs_left / 86400, 1) . 'd left';
                                                                                }
                                                                                ?></span>
                                        </span>
                                    </td>

                                    <td>
                                        <?php if ($rem_sent): ?>
                                            <span class="tb-reminder tb-reminder--sent">
                                                <i class="fas fa-envelope-circle-check"></i> Sent
                                            </span>
                                            <?php if (!empty($bk['reminder_sent_at'])): ?>
                                                <div style="font-size: 11px; color: var(--finance-muted); margin-top: 3px;">
                                                    <?php echo date('M j, H:i', strtotime($bk['reminder_sent_at'])); ?>
                                                </div>
                                            <?php endif; ?>
                                        <?php elseif ($rem_overdue): ?>
                                            <span class="tb-reminder tb-reminder--overdue">
                                                <i class="fas fa-triangle-exclamation"></i> Overdue
                                            </span>
                                            <div style="font-size: 11px; color: var(--finance-muted); margin-top: 3px;">
                                                Was due <?php echo date('M j, H:i', $rem_due_ts); ?>
                                            </div>
                                        <?php else: ?>
                                            <span class="tb-reminder tb-reminder--due">
                                                <i class="fas fa-bell"></i> Due <?php echo date('M j · H:i', $rem_due_ts); ?>
                                            </span>
                                            <div style="font-size: 11px; color: var(--finance-muted); margin-top: 3px;">
                                                in <?php echo round(($rem_due_ts - $now->getTimestamp()) / 3600, 1); ?>h
                                            </div>
                                        <?php endif; ?>
                                    </td>

                                    <td>
                                        <div class="tb-actions">
                                            <!-- Convert to confirmed -->
                                            <form method="POST" style="width: 100%;" data-admin-submit-text="Converting...">
                                                <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrf_token, ENT_QUOTES, 'UTF-8'); ?>">
                                                <input type="hidden" name="action" value="convert">
                                                <input type="hidden" name="id" value="<?php echo (int)$bk['id']; ?>">
                                                <button type="submit" class="acct-quick-action acct-quick-action--accent"
                                                    style="width: 100%;"
                                                    data-admin-confirm="Convert this tentative booking to confirmed and send a confirmation email?"
                                                    data-admin-confirm-title="Confirm Booking"
                                                    data-admin-confirm-ok="Confirm"
                                                    data-admin-confirm-icon="fa-circle-check">
                                                    <i class="fas fa-circle-check"></i> Confirm
                                                </button>
                                            </form>

                                            <!-- Send reminder email -->
                                            <button type="button" class="acct-quick-action tb-btn-reminder"
                                                style="width: 100%;"
                                                data-id="<?php echo (int)$bk['id']; ?>"
                                                data-name="<?php echo htmlspecialchars($bk['guest_name'], ENT_QUOTES); ?>"
                                                data-email="<?php echo htmlspecialchars($bk['guest_email'], ENT_QUOTES); ?>"
                                                title="<?php echo $rem_sent ? 'Resend reminder email to guest' : 'Send expiry reminder email'; ?>">
                                                <i class="fas fa-<?php echo $rem_sent ? 'rotate-right' : 'bell'; ?>"></i>
                                                <?php echo $rem_sent ? 'Resend Reminder' : 'Send Reminder'; ?>
                                            </button>

                                            <!-- Extend hold -->
                                            <button type="button" class="acct-quick-action tb-btn-extend"
                                                style="width: 100%;"
                                                data-id="<?php echo (int)$bk['id']; ?>"
                                                data-ref="<?php echo htmlspecialchars($bk['booking_reference'], ENT_QUOTES); ?>"
                                                data-expires="<?php echo htmlspecialchars(date('M j, Y · H:i', $exp_ts)); ?>">
                                                <i class="fas fa-clock-rotate-left"></i> Extend Hold
                                            </button>

                                            <!-- Send quotation -->
                                            <button type="button" class="acct-quick-action tb-btn-quote"
                                                style="width: 100%;"
                                                data-id="<?php echo (int)$bk['id']; ?>"
                                                data-ref="<?php echo htmlspecialchars($bk['booking_reference'], ENT_QUOTES); ?>"
                                                data-name="<?php echo htmlspecialchars($bk['guest_name'], ENT_QUOTES); ?>"
                                                data-email="<?php echo htmlspecialchars($bk['guest_email'], ENT_QUOTES); ?>">
                                                <i class="fas fa-file-invoice"></i> Send Quote
                                                <?php if (!empty($bk['last_quotation_sent_at'])): ?>
                                                    <span class="acct-pill acct-pill--completed" style="font-size: 9.5px; padding: 1px 5px; margin-left: 2px;">Sent</span>
                                                <?php endif; ?>
                                            </button>

                                            <!-- View history -->
                                            <button type="button" class="acct-quick-action tb-btn-history"
                                                style="width: 100%;"
                                                data-id="<?php echo (int)$bk['id']; ?>"
                                                data-ref="<?php echo htmlspecialchars($bk['booking_reference'], ENT_QUOTES); ?>">
                                                <i class="fas fa-timeline"></i> History
                                            </button>

                                            <!-- View full booking -->
                                            <a href="booking-details.php?id=<?php echo (int)$bk['id']; ?>" class="acct-quick-action" style="width: 100%;">
                                                <i class="fas fa-arrow-up-right-from-square"></i> View
                                            </a>

                                            <!-- Cancel -->
                                            <form method="POST" style="width: 100%;" data-admin-submit-text="Cancelling...">
                                                <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrf_token, ENT_QUOTES, 'UTF-8'); ?>">
                                                <input type="hidden" name="action" value="cancel">
                                                <input type="hidden" name="id" value="<?php echo (int)$bk['id']; ?>">
                                                <button type="submit" class="acct-quick-action" style="width: 100%; color: #dc2626;"
                                                    data-admin-confirm="Cancel this tentative hold and release the room?"
                                                    data-admin-confirm-title="Cancel Tentative Hold"
                                                    data-admin-confirm-ok="Cancel Hold"
                                                    data-admin-confirm-tone="danger"
                                                    data-admin-confirm-icon="fa-ban">
                                                    <i class="fas fa-ban"></i> Cancel Hold
                                                </button>
                                            </form>
                                        </div>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php endif; ?>
        </div>

        <!-- Recently expired section -->
        <?php if (!empty($recently_expired)): ?>
            <div class="acct-panel">
                <div class="acct-panel__head">
                    <h3 class="acct-panel__title"><i class="fas fa-calendar-xmark"></i> Recently Expired Holds</h3>
                    <span class="acct-panel__sub">Last 14 days · <?php echo count($recently_expired); ?> records</span>
                </div>
                <div class="acct-table-wrap">
                    <table class="acct-table">
                        <thead>
                            <tr>
                                <th>Reference</th>
                                <th>Guest</th>
                                <th>Room</th>
                                <th>Check-in</th>
                                <th class="num">Total</th>
                                <th>Expired At</th>
                                <th>Reminder Sent</th>
                                <th></th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($recently_expired as $bk): ?>
                                <tr>
                                    <td style="font-family: 'Courier New', monospace; font-size: 12px;"><?php echo htmlspecialchars($bk['booking_reference']); ?></td>
                                    <td>
                                        <div style="font-weight: 600; font-size: 13px;"><?php echo htmlspecialchars($bk['guest_name']); ?></div>
                                        <div style="font-size: 11.5px; color: var(--finance-muted);"><?php echo htmlspecialchars($bk['guest_email']); ?></div>
                                    </td>
                                    <td><?php echo htmlspecialchars($bk['room_name']); ?></td>
                                    <td><?php echo date('M j, Y', strtotime($bk['check_in_date'])); ?></td>
                                    <td class="num"><?php echo htmlspecialchars($currency_symbol); ?><?php echo number_format((float)$bk['total_amount'], 0); ?></td>
                                    <td>
                                        <span class="tb-countdown tb-countdown--expired">
                                            <i class="fas fa-clock-rotate-left"></i>
                                            <?php echo date('M j, Y · H:i', strtotime($bk['expired_at'])); ?>
                                        </span>
                                    </td>
                                    <td>
                                        <?php if (!empty($bk['reminder_sent'])): ?>
                                            <span class="tb-reminder tb-reminder--sent"><i class="fas fa-check"></i> Yes</span>
                                        <?php else: ?>
                                            <span class="tb-reminder tb-reminder--na"><i class="fas fa-minus"></i> No</span>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <a href="booking-details.php?id=<?php echo (int)$bk['id']; ?>" class="acct-link">View →</a>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        <?php endif; ?>

    </div><!-- /.content -->

    <!-- ── Extend Hold Modal ─────────────────────────────────────────────── -->
    <div id="tb-extend-modal" class="admin-modal-overlay" style="display: none;" role="dialog" aria-modal="true" aria-labelledby="tb-extend-title">
        <div class="admin-modal admin-modal--md">
            <div class="admin-modal__header">
                <h3 id="tb-extend-title" class="admin-modal__title"><i class="fas fa-clock-rotate-left"></i> Extend Tentative Hold</h3>
                <button type="button" class="admin-modal__close" id="tb-extend-close" aria-label="Close">&times;</button>
            </div>
            <div class="admin-modal__body">
                <div id="tb-extend-summary" style="background: #faf8f4; border-radius: 8px; padding: 13px 16px; margin-bottom: 18px; font-size: 13px; color: var(--finance-muted);"></div>
                <p style="font-size: 13px; font-weight: 600; color: var(--finance-text); margin: 0 0 10px;">Extend the hold by:</p>
                <div class="tb-extend-grid" id="tb-extend-options">
                    <div class="tb-extend-option" data-hours="12">
                        <span class="tb-extend-option__hours">+12</span>
                        <span class="tb-extend-option__label">hours</span>
                    </div>
                    <div class="tb-extend-option tb-extend-option--selected" data-hours="24">
                        <span class="tb-extend-option__hours">+24</span>
                        <span class="tb-extend-option__label">hours</span>
                    </div>
                    <div class="tb-extend-option" data-hours="48">
                        <span class="tb-extend-option__hours">+48</span>
                        <span class="tb-extend-option__label">hours</span>
                    </div>
                    <div class="tb-extend-option" data-hours="72">
                        <span class="tb-extend-option__hours">+72</span>
                        <span class="tb-extend-option__label">hours</span>
                    </div>
                </div>
                <div id="tb-extend-feedback" style="display: none; margin-top: 14px;"></div>
            </div>
            <div class="admin-modal__footer">
                <button type="button" class="acct-btn--cancel" id="tb-extend-close-2">Cancel</button>
                <button type="button" id="tb-extend-submit" class="acct-btn--primary">
                    <i class="fas fa-check"></i> Extend Hold
                </button>
            </div>
        </div>
    </div>

    <!-- ── Quotation Modal ───────────────────────────────────────────────── -->
    <div id="tb-quote-modal" class="admin-modal-overlay" style="display: none;" role="dialog" aria-modal="true" aria-labelledby="tb-quote-title">
        <div class="admin-modal admin-modal--md">
            <div class="admin-modal__header">
                <h3 id="tb-quote-title" class="admin-modal__title"><i class="fas fa-file-invoice"></i> Send Quotation</h3>
                <button type="button" class="admin-modal__close" id="tb-quote-close" aria-label="Close">&times;</button>
            </div>
            <div class="admin-modal__body">
                <div id="tb-quote-summary" style="background: #faf8f4; border-radius: 8px; padding: 13px 16px; margin-bottom: 18px; font-size: 13px;"></div>
                <div style="margin-bottom: 14px;">
                    <label style="font-size: 13px; font-weight: 600; color: var(--finance-text); display: block; margin-bottom: 6px;">Quotation valid for</label>
                    <select id="tb-quote-valid-days" style="width: 100%; padding: 9px 12px; border: 1.5px solid var(--finance-border); border-radius: 8px; font-size: 13.5px; font-family: 'Jost', sans-serif; color: var(--finance-text);">
                        <option value="1">1 day</option>
                        <option value="2">2 days</option>
                        <option value="3">3 days</option>
                        <option value="5">5 days</option>
                        <option value="7" selected>7 days</option>
                        <option value="14">14 days</option>
                        <option value="30">30 days</option>
                    </select>
                </div>
                <div>
                    <label style="font-size: 13px; font-weight: 600; color: var(--finance-text); display: block; margin-bottom: 6px;">Note to guest <span style="color: var(--finance-muted); font-weight: 400;">(optional)</span></label>
                    <textarea id="tb-quote-notes" rows="3" placeholder="e.g. Rates include complimentary breakfast." style="width: 100%; padding: 9px 12px; border: 1.5px solid var(--finance-border); border-radius: 8px; font-size: 13px; font-family: 'Jost', sans-serif; resize: vertical; box-sizing: border-box;"></textarea>
                </div>
                <div id="tb-quote-feedback" style="display: none; margin-top: 14px;"></div>
            </div>
            <div class="admin-modal__footer">
                <button type="button" class="acct-btn--cancel" id="tb-quote-close-2">Cancel</button>
                <button type="button" id="tb-quote-submit" class="acct-btn--primary">
                    <i class="fas fa-paper-plane"></i> Send Quotation
                </button>
            </div>
        </div>
    </div>

    <!-- ── History Modal ────────────────────────────────────────────────── -->
    <div id="tb-history-modal" class="admin-modal-overlay" style="display: none;" role="dialog" aria-modal="true" aria-labelledby="tb-history-title">
        <div class="admin-modal admin-modal--md">
            <div class="admin-modal__header">
                <h3 id="tb-history-title" class="admin-modal__title"><i class="fas fa-timeline"></i> Hold Activity</h3>
                <button type="button" class="admin-modal__close" id="tb-history-close" aria-label="Close">&times;</button>
            </div>
            <div class="admin-modal__body">
                <div id="tb-history-body" style="min-height: 80px;">
                    <p style="color: var(--finance-muted); text-align: center; padding: 20px 0;">Loading…</p>
                </div>
            </div>
        </div>
    </div>

    <script>
        (function() {
            'use strict';

            function getCsrf() {
                if (typeof window._rhCsrf === 'string' && window._rhCsrf) return window._rhCsrf;
                var m = document.querySelector('meta[name="csrf-token"]');
                return m ? m.getAttribute('content') : '';
            }

            function esc(str) {
                var d = document.createElement('div');
                d.appendChild(document.createTextNode(String(str)));
                return d.innerHTML;
            }

            function showFeedback(el, ok, msg) {
                el.style.cssText = 'display:block;padding:11px 14px;border-radius:8px;font-size:13px;' +
                    (ok ? 'background:#f0fdf4;color:#166534;border:1px solid #bbf7d0;' :
                        'background:#fff1f2;color:#be123c;border:1px solid #fecdd3;');
                el.innerHTML = '<i class="fas fa-' + (ok ? 'circle-check' : 'circle-exclamation') + '"></i> ' + esc(msg);
            }

            function apiPost(payload, onOk, onErr) {
                fetch('api/tentative-actions.php', {
                        method: 'POST',
                        headers: {
                            'Content-Type': 'application/json',
                            'X-Requested-With': 'XMLHttpRequest'
                        },
                        body: JSON.stringify(Object.assign({
                            csrf: getCsrf()
                        }, payload))
                    })
                    .then(function(r) {
                        return r.json();
                    })
                    .then(function(d) {
                        d.success ? onOk(d) : onErr(d.error || 'Unknown error');
                    })
                    .catch(function() {
                        onErr('Network error. Please try again.');
                    });
            }

            // ── Filter tabs ─────────────────────────────────────────────────
            var table = document.getElementById('tb-table');
            var cntEl = document.getElementById('tb-visible-count');
            var active_filter = 'all';

            document.querySelectorAll('.tb-tab').forEach(function(tab) {
                tab.addEventListener('click', function() {
                    document.querySelectorAll('.tb-tab').forEach(function(t) {
                        t.classList.remove('tb-tab--active');
                    });
                    tab.classList.add('tb-tab--active');
                    active_filter = tab.getAttribute('data-filter');
                    applyFilter();
                });
            });

            function applyFilter() {
                if (!table) return;
                var rows = table.querySelectorAll('tbody tr');
                var visible = 0;
                rows.forEach(function(row) {
                    var filters = (row.getAttribute('data-filters') || '').split(' ');
                    var show = active_filter === 'all' || filters.indexOf(active_filter) !== -1;
                    row.style.display = show ? '' : 'none';
                    if (show) {
                        visible++;
                    }
                });
                if (cntEl) {
                    cntEl.textContent = visible + ' booking' + (visible === 1 ? '' : 's');
                }
            }

            // ── Live countdowns ──────────────────────────────────────────────
            function updateCountdowns() {
                var now = Math.floor(Date.now() / 1000);
                document.querySelectorAll('[data-countdown]').forEach(function(el) {
                    var target = parseInt(el.getAttribute('data-countdown'), 10);
                    var secs = target - now;
                    var txt = el.querySelector('.tb-countdown__text');
                    if (!txt) return;

                    el.className = el.className.replace(/tb-countdown--\S+/g, '').trim();

                    if (secs <= 0) {
                        el.classList.add('tb-countdown--expired');
                        txt.textContent = 'Expired';
                    } else if (secs < 14400) { // < 4 hours
                        el.classList.add('tb-countdown--critical');
                        var m = Math.floor(secs / 60);
                        var h = Math.floor(m / 60);
                        txt.textContent = h > 0 ? h + 'h ' + (m % 60) + 'm left' : m + 'm left';
                    } else if (secs < 86400) { // < 24 hours
                        el.classList.add('tb-countdown--soon');
                        txt.textContent = (secs / 3600).toFixed(1) + 'h left';
                    } else {
                        el.classList.add('tb-countdown--active');
                        txt.textContent = (secs / 86400).toFixed(1) + 'd left';
                    }
                });
            }

            updateCountdowns();
            setInterval(updateCountdowns, 30000);

            // ── Send reminder ────────────────────────────────────────────────
            document.querySelectorAll('.tb-btn-reminder').forEach(function(btn) {
                btn.addEventListener('click', function() {
                    var id = parseInt(btn.getAttribute('data-id'), 10);
                    var name = btn.getAttribute('data-name');
                    var email = btn.getAttribute('data-email');

                    window.AdminConfirm.request({
                        title: 'Send Reminder',
                        message: 'Send a reminder email to ' + name + ' (' + email + ')?',
                        confirmText: 'Send Reminder',
                        tone: 'info',
                        icon: 'fa-envelope'
                    }).then(function(confirmed) {
                        if (!confirmed) return;

                        btn.disabled = true;
                        var orig = btn.innerHTML;
                        btn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Sending…';

                        apiPost({
                                action: 'send_reminder',
                                booking_id: id
                            },
                            function(d) {
                                btn.innerHTML = '<i class="fas fa-check"></i> Sent';
                                setTimeout(function() {
                                    location.reload();
                                }, 1600);
                            },
                            function(err) {
                                var fb = document.createElement('div');
                                btn.parentNode.appendChild(fb);
                                showFeedback(fb, false, 'Failed: ' + err);
                                setTimeout(function() {
                                    fb.remove();
                                }, 4000);
                                btn.disabled = false;
                                btn.innerHTML = orig;
                            }
                        );
                    });
                });
            });

            // ── Extend hold modal ────────────────────────────────────────────
            var extModal = document.getElementById('tb-extend-modal');
            var extFeedback = document.getElementById('tb-extend-feedback');
            var extSubmit = document.getElementById('tb-extend-submit');
            var extBookingId = 0;
            var extHours = 24;

            document.querySelectorAll('.tb-btn-extend').forEach(function(btn) {
                btn.addEventListener('click', function() {
                    extBookingId = parseInt(btn.getAttribute('data-id'), 10);
                    var ref = btn.getAttribute('data-ref');
                    var expires = btn.getAttribute('data-expires');

                    document.getElementById('tb-extend-summary').innerHTML =
                        '<strong>' + esc(ref) + '</strong>' +
                        ' &mdash; currently expires <strong>' + esc(expires) + '</strong>';

                    extFeedback.style.display = 'none';
                    extFeedback.innerHTML = '';
                    extSubmit.disabled = false;
                    extSubmit.innerHTML = '<i class="fas fa-check"></i> Extend Hold';

                    // Reset selection to 24h
                    extHours = 24;
                    document.querySelectorAll('.tb-extend-option').forEach(function(o) {
                        o.classList.toggle('tb-extend-option--selected', parseInt(o.getAttribute('data-hours'), 10) === 24);
                    });

                    extModal.style.display = 'flex';
                });
            });

            document.querySelectorAll('.tb-extend-option').forEach(function(opt) {
                opt.addEventListener('click', function() {
                    document.querySelectorAll('.tb-extend-option').forEach(function(o) {
                        o.classList.remove('tb-extend-option--selected');
                    });
                    opt.classList.add('tb-extend-option--selected');
                    extHours = parseInt(opt.getAttribute('data-hours'), 10);
                });
            });

            function closeExtend() {
                extModal.style.display = 'none';
                extBookingId = 0;
            }
            document.getElementById('tb-extend-close').addEventListener('click', closeExtend);
            document.getElementById('tb-extend-close-2').addEventListener('click', closeExtend);
            extModal.addEventListener('click', function(e) {
                if (e.target === extModal) {
                    closeExtend();
                }
            });

            extSubmit.addEventListener('click', function() {
                if (!extBookingId) {
                    return;
                }
                extSubmit.disabled = true;
                extSubmit.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Extending…';

                apiPost({
                        action: 'extend',
                        booking_id: extBookingId,
                        hours: extHours
                    },
                    function(d) {
                        showFeedback(extFeedback, true, d.message);
                        extSubmit.innerHTML = '<i class="fas fa-check"></i> Done';
                        setTimeout(function() {
                            closeExtend();
                            location.reload();
                        }, 1600);
                    },
                    function(err) {
                        showFeedback(extFeedback, false, err);
                        extSubmit.disabled = false;
                        extSubmit.innerHTML = '<i class="fas fa-check"></i> Extend Hold';
                    }
                );
            });

            // ── Quotation modal ──────────────────────────────────────────────
            var quoteModal = document.getElementById('tb-quote-modal');
            var quoteFeedback = document.getElementById('tb-quote-feedback');
            var quoteSubmit = document.getElementById('tb-quote-submit');
            var quoteId = 0;

            document.querySelectorAll('.tb-btn-quote').forEach(function(btn) {
                btn.addEventListener('click', function() {
                    quoteId = parseInt(btn.getAttribute('data-id'), 10);
                    var name = btn.getAttribute('data-name');
                    var email = btn.getAttribute('data-email');
                    var ref = btn.getAttribute('data-ref');

                    document.getElementById('tb-quote-summary').innerHTML =
                        '<strong>' + esc(name) + '</strong> &mdash; ' + esc(email) +
                        '<br><small style="color: var(--finance-muted);">Ref: ' + esc(ref) + '</small>';

                    document.getElementById('tb-quote-valid-days').value = '7';
                    document.getElementById('tb-quote-notes').value = '';
                    quoteFeedback.style.display = 'none';
                    quoteSubmit.disabled = false;
                    quoteSubmit.innerHTML = '<i class="fas fa-paper-plane"></i> Send Quotation';
                    quoteModal.style.display = 'flex';
                });
            });

            function closeQuote() {
                quoteModal.style.display = 'none';
                quoteId = 0;
            }
            document.getElementById('tb-quote-close').addEventListener('click', closeQuote);
            document.getElementById('tb-quote-close-2').addEventListener('click', closeQuote);
            quoteModal.addEventListener('click', function(e) {
                if (e.target === quoteModal) {
                    closeQuote();
                }
            });

            quoteSubmit.addEventListener('click', function() {
                if (!quoteId) {
                    return;
                }
                var validDays = parseInt(document.getElementById('tb-quote-valid-days').value, 10);
                var notes = document.getElementById('tb-quote-notes').value.trim();

                quoteSubmit.disabled = true;
                quoteSubmit.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Sending…';

                fetch('api/send-quotation.php', {
                        method: 'POST',
                        headers: {
                            'Content-Type': 'application/json',
                            'X-Requested-With': 'XMLHttpRequest'
                        },
                        body: JSON.stringify({
                            csrf: getCsrf(),
                            booking_id: quoteId,
                            valid_days: validDays,
                            quotation_notes: notes
                        })
                    })
                    .then(function(r) {
                        return r.json();
                    })
                    .then(function(d) {
                        if (d.success) {
                            showFeedback(quoteFeedback, true, d.message || 'Quotation sent.');
                            quoteSubmit.innerHTML = '<i class="fas fa-check"></i> Sent';
                            setTimeout(function() {
                                closeQuote();
                                location.reload();
                            }, 1600);
                        } else {
                            showFeedback(quoteFeedback, false, d.error || 'Failed to send quotation.');
                            quoteSubmit.disabled = false;
                            quoteSubmit.innerHTML = '<i class="fas fa-paper-plane"></i> Retry';
                        }
                    })
                    .catch(function() {
                        showFeedback(quoteFeedback, false, 'Network error. Please try again.');
                        quoteSubmit.disabled = false;
                        quoteSubmit.innerHTML = '<i class="fas fa-paper-plane"></i> Retry';
                    });
            });

            // ── History modal ────────────────────────────────────────────────
            var histModal = document.getElementById('tb-history-modal');
            var histBody = document.getElementById('tb-history-body');

            var logIcons = {
                created: {
                    icon: 'fa-plus',
                    cls: 'tb-log-icon--created'
                },
                extended: {
                    icon: 'fa-clock-rotate-left',
                    cls: 'tb-log-icon--extended'
                },
                reminder_sent: {
                    icon: 'fa-envelope',
                    cls: 'tb-log-icon--reminder_sent'
                },
                converted: {
                    icon: 'fa-circle-check',
                    cls: 'tb-log-icon--converted'
                },
                expired: {
                    icon: 'fa-clock',
                    cls: 'tb-log-icon--expired'
                },
                cancelled: {
                    icon: 'fa-ban',
                    cls: 'tb-log-icon--cancelled'
                },
            };

            document.querySelectorAll('.tb-btn-history').forEach(function(btn) {
                btn.addEventListener('click', function() {
                    var id = parseInt(btn.getAttribute('data-id'), 10);
                    var ref = btn.getAttribute('data-ref');

                    document.getElementById('tb-history-title').innerHTML =
                        '<i class="fas fa-timeline"></i> Hold Activity — ' + esc(ref);
                    histBody.innerHTML = '<p style="color: var(--finance-muted); text-align: center; padding: 20px 0;">Loading…</p>';
                    histModal.style.display = 'flex';

                    fetch('api/tentative-actions.php', {
                            method: 'POST',
                            headers: {
                                'Content-Type': 'application/json',
                                'X-Requested-With': 'XMLHttpRequest'
                            },
                            body: JSON.stringify({
                                csrf: getCsrf(),
                                action: 'get_history',
                                booking_id: id
                            })
                        })
                        .then(function(r) {
                            return r.json();
                        })
                        .then(function(d) {
                            if (!d.success || !d.log || !d.log.length) {
                                histBody.innerHTML = '<p style="color: var(--finance-muted); text-align: center; padding: 20px 0;">No activity recorded yet.</p>';
                                return;
                            }
                            var html = '';
                            d.log.forEach(function(entry) {
                                var meta = logIcons[entry.action] || {
                                    icon: 'fa-circle',
                                    cls: ''
                                };
                                var label = (entry.action || '').replace(/_/g, ' ').replace(/\b\w/g, function(c) {
                                    return c.toUpperCase();
                                });
                                html += '<div class="tb-log-entry">' +
                                    '<div class="tb-log-icon ' + meta.cls + '"><i class="fas ' + meta.icon + '"></i></div>' +
                                    '<div class="tb-log-body">' +
                                    '<div class="tb-log-what">' + esc(label) + '</div>' +
                                    '<div class="tb-log-meta">' +
                                    (entry.action_reason ? esc(entry.action_reason) + ' &nbsp;·&nbsp; ' : '') +
                                    esc(entry.created_at || '') + '</div>' +
                                    '</div></div>';
                            });
                            histBody.innerHTML = html;
                        })
                        .catch(function() {
                            histBody.innerHTML = '<p style="color: #dc2626; text-align: center; padding: 20px 0;">Failed to load history.</p>';
                        });
                });
            });

            function closeHistory() {
                histModal.style.display = 'none';
            }
            document.getElementById('tb-history-close').addEventListener('click', closeHistory);
            histModal.addEventListener('click', function(e) {
                if (e.target === histModal) {
                    closeHistory();
                }
            });

            // Auto-refresh every 3 minutes
            setTimeout(function() {
                location.reload();
            }, 180000);
        })();
    </script>


    <?php require_once 'includes/admin-footer.php'; ?>

