<?php
require_once 'config/database.php';
require_once 'config/settings.php';
require_once 'includes/functions.php';

$ref = trim(strip_tags($_GET['ref'] ?? ''));

$inquiry = null;
$error   = null;

if ($ref === '') {
    $error = 'No booking reference provided.';
} else {
    try {
        $stmt = $pdo->prepare("
            SELECT ei.*, e.title AS event_title, e.event_date AS event_date
            FROM event_inquiries ei
            LEFT JOIN events e ON e.id = ei.event_id
            WHERE ei.reference_number = ? LIMIT 1
        ");
        $stmt->execute([$ref]);
        $inquiry = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$inquiry) {
            $error = 'Booking not found. Please check your reference number.';
        }
    } catch (PDOException $e) {
        $error = 'Unable to retrieve booking details.';
        error_log('events-confirmation.php: ' . $e->getMessage());
    }
}

$site_name       = getSetting('site_name');
$currency_symbol = getSetting('currency_symbol');
$phone_main      = getSetting('phone_main');
$email_main      = getSetting('email_reservations', '');
$whatsapp_number = getSetting('whatsapp_number');

$policies = [];
try {
    $ps = $pdo->query("SELECT slug, title, summary, content FROM policies WHERE is_active = 1 ORDER BY display_order ASC, id ASC");
    $policies = $ps->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <?php
    $seo_data = [
        'title'       => 'Event Booking Received | ' . $site_name,
        'description' => "Your event booking request at {$site_name} has been received.",
        'noindex'     => true,
        'type'        => 'website'
    ];
    require_once 'includes/seo-meta.php';
    ?>
    <meta name="mobile-web-app-capable" content="yes">
    <meta name="apple-mobile-web-app-status-bar-style" content="black-translucent">
    <meta name="format-detection" content="telephone=yes">

    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Cormorant+Garamond:ital,wght@0,300;0,400;0,500;0,600;1,300;1,400;1,500&family=Jost:wght@300;400;500;600&display=swap" rel="stylesheet" media="print" onload="this.media='all'">

    <link rel="stylesheet" href="css/base/critical.css">
    <link rel="stylesheet" href="css/main.css">
</head>

<body class="confirmation-page">
    <?php include 'includes/loader.php'; ?>
    <?php include 'includes/header.php'; ?>
    <?php include 'includes/alert.php'; ?>

    <main id="main-content">
        <div class="conf-wrap">

        <?php if ($error): ?>
            <div class="conf-card">
                <div class="conf-card-body conf-error">
                    <i class="fas fa-exclamation-circle"></i>
                    <h1>Booking Not Found</h1>
                    <p><?php echo htmlspecialchars($error); ?></p>
                    <a href="events.php" class="conf-btn conf-btn--primary">Back to Events</a>
                </div>
            </div>
        <?php else:
            $event_date_fmt = !empty($inquiry['event_date']) ? date('D, M j, Y', strtotime($inquiry['event_date'])) : '—';
            $guests         = (int)($inquiry['guests'] ?? 1);
            $event_title    = trim((string)($inquiry['event_title'] ?? ''));
            $guest_message  = trim((string)($inquiry['message'] ?? ''));
        ?>

            <!-- Hero -->
            <div class="conf-hero">
                <div class="conf-icon-ring tentative">
                    <i class="fas fa-calendar-check"></i>
                </div>
                <h1 class="conf-heading">Event Booking Received</h1>
                <p class="conf-subtitle">Thank you for choosing <?php echo htmlspecialchars($site_name); ?>. Our team will review your request and confirm your booking shortly.</p>
                <span class="conf-type-pill tentative">
                    <i class="fas fa-clock"></i> Pending Confirmation
                </span>
            </div>

            <!-- 2-col body grid -->
            <div class="conf-body-grid">

                <!-- LEFT / MAIN column -->
                <div class="conf-col-main">

                    <!-- Booking details card -->
                    <div class="conf-card">
                        <div class="conf-card-body">
                            <div class="conf-card-title"><i class="fas fa-calendar-alt"></i> Booking Details</div>
                            <?php if ($event_title !== ''): ?>
                            <div class="conf-room-name"><?php echo htmlspecialchars($event_title); ?></div>
                            <?php endif; ?>

                            <div class="conf-dates-row" style="grid-template-columns: 1fr auto 1fr;">
                                <div class="conf-date-block">
                                    <div class="conf-date-label"><i class="fas fa-calendar"></i> Event Date</div>
                                    <div class="conf-date-val"><?php echo $event_date_fmt; ?></div>
                                </div>
                                <div class="conf-nights-pill">
                                    <span class="conf-nights-num"><?php echo $guests; ?></span>
                                    <span class="conf-nights-lbl"><?php echo $guests === 1 ? 'guest' : 'guests'; ?></span>
                                </div>
                            </div>

                            <div class="conf-stay-meta">
                                <div class="conf-guests-line">
                                    <i class="fas fa-users"></i>
                                    <?php echo $guests; ?> guest<?php echo $guests === 1 ? '' : 's'; ?>
                                </div>
                            </div>
                        </div>
                    </div>

                    <?php if ($guest_message !== ''): ?>
                    <!-- Message card -->
                    <div class="conf-card">
                        <div class="conf-card-body">
                            <div class="conf-card-title"><i class="fas fa-comment"></i> Your Message</div>
                            <p style="font-size:0.84rem;color:#5C5549;line-height:1.7;margin:0;"><?php echo nl2br(htmlspecialchars($guest_message)); ?></p>
                        </div>
                    </div>
                    <?php endif; ?>

                    <!-- What happens next card -->
                    <div class="conf-card">
                        <div class="conf-card-body">
                            <div class="conf-card-title"><i class="fas fa-list-check"></i> What Happens Next</div>
                            <ul class="conf-steps">
                                <li class="conf-step"><span class="conf-step-num">1</span><span>Our events team reviews your booking request.</span></li>
                                <li class="conf-step"><span class="conf-step-num">2</span><span>We contact you to confirm your place at the event.</span></li>
                                <li class="conf-step"><span class="conf-step-num">3</span><span>Arrive on the day and enjoy the event!</span></li>
                            </ul>
                        </div>
                    </div>

                </div><!-- /.conf-col-main -->

                <!-- RIGHT / ASIDE column -->
                <div class="conf-col-aside">

                    <!-- Reference card -->
                    <div class="conf-card">
                        <div class="conf-card-body">
                            <div class="conf-card-title"><i class="fas fa-hashtag"></i> Booking Reference</div>
                            <div class="conf-ref-number"><?php echo htmlspecialchars($inquiry['reference_number']); ?></div>
                            <p class="conf-ref-note">Keep this reference number for when you follow up with our team.</p>
                        </div>
                    </div>

                    <!-- Contact details card -->
                    <div class="conf-card">
                        <div class="conf-card-body">
                            <div class="conf-card-title"><i class="fas fa-user"></i> Your Details</div>
                            <div class="conf-detail-list">
                                <div class="conf-detail-row">
                                    <span>Name</span>
                                    <span><?php echo htmlspecialchars($inquiry['name']); ?></span>
                                </div>
                                <div class="conf-detail-row">
                                    <span>Email</span>
                                    <span><?php echo htmlspecialchars($inquiry['email']); ?></span>
                                </div>
                                <?php if (!empty($inquiry['phone'])): ?>
                                <div class="conf-detail-row">
                                    <span>Phone</span>
                                    <span><?php echo htmlspecialchars($inquiry['phone']); ?></span>
                                </div>
                                <?php endif; ?>
                            </div>
                        </div>
                    </div>

                </div><!-- /.conf-col-aside -->

            </div><!-- /.conf-body-grid -->

            <!-- Action buttons -->
            <div class="conf-actions">
                <a href="tel:<?php echo str_replace(' ', '', $phone_main); ?>" class="conf-btn conf-btn--ghost">
                    <i class="fas fa-phone"></i> Call Hotel
                </a>
                <?php if (!empty($whatsapp_number)): ?>
                <a href="https://wa.me/<?php echo rawurlencode(preg_replace('/[^0-9+]/', '', (string)$whatsapp_number)); ?>?text=<?php echo rawurlencode('Hi, I have an event booking (' . $inquiry['reference_number'] . ')'); ?>" class="conf-btn conf-btn--whatsapp" target="_blank" rel="noopener">
                    <i class="fab fa-whatsapp"></i> WhatsApp
                </a>
                <?php endif; ?>
                <a href="mailto:<?php echo $email_main; ?>?subject=Event+Booking+<?php echo urlencode($inquiry['reference_number']); ?>" class="conf-btn conf-btn--ghost">
                    <i class="fas fa-envelope"></i> Email
                </a>
                <button onclick="window.print()" class="conf-btn conf-btn--ghost">
                    <i class="fas fa-print"></i> Print
                </button>
                <a href="index.php" class="conf-btn conf-btn--home">
                    <i class="fas fa-home"></i> Back to Home
                </a>
            </div>

            <p class="conf-contact-note">
                <i class="fas fa-question-circle"></i>
                Questions? Call us at <a href="tel:<?php echo str_replace(' ', '', $phone_main); ?>"><?php echo htmlspecialchars($phone_main); ?></a>
            </p>

        <?php endif; ?>
        </div><!-- /.conf-wrap -->
    </main>

    <script src="js/main.js"></script>

    <?php include 'includes/footer.php'; ?>
</body>
</html>
