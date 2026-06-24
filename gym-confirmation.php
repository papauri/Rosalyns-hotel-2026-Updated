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
            SELECT * FROM gym_inquiries WHERE reference_number = ? LIMIT 1
        ");
        $stmt->execute([$ref]);
        $inquiry = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$inquiry) {
            $error = 'Booking not found. Please check your reference number.';
        }
    } catch (PDOException $e) {
        $error = 'Unable to retrieve booking details.';
        error_log('gym-confirmation.php: ' . $e->getMessage());
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
        'title'       => 'Gym Booking Received | ' . $site_name,
        'description' => "Your gym booking request at {$site_name} has been received.",
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
                    <a href="gym.php" class="conf-btn conf-btn--primary">Back to Wellness</a>
                </div>
            </div>
        <?php else:
            $preferred_date_fmt = !empty($inquiry['preferred_date']) ? date('D, M j, Y', strtotime($inquiry['preferred_date'])) : '—';
            $preferred_time_fmt = !empty($inquiry['preferred_time']) ? date('g:i A', strtotime($inquiry['preferred_time'])) : (trim((string)($inquiry['preferred_time'] ?? '')) ?: '—');
            $guests             = (int)($inquiry['guests'] ?? 1);
            $membership_type    = trim((string)($inquiry['membership_type'] ?? ''));
            $goals_notes        = trim((string)($inquiry['message'] ?? ''));
        ?>

            <!-- Hero -->
            <div class="conf-hero">
                <div class="conf-icon-ring tentative">
                    <i class="fas fa-dumbbell"></i>
                </div>
                <h1 class="conf-heading">Wellness Booking Received</h1>
                <p class="conf-subtitle">Thank you for choosing <?php echo htmlspecialchars($site_name); ?>. Our team will review your request and confirm your session within 24 hours.</p>
                <span class="conf-type-pill tentative">
                    <i class="fas fa-clock"></i> Pending Confirmation
                </span>
            </div>

            <!-- 2-col body grid -->
            <div class="conf-body-grid">

                <!-- LEFT / MAIN column -->
                <div class="conf-col-main">

                    <!-- Session details card -->
                    <div class="conf-card">
                        <div class="conf-card-body">
                            <div class="conf-card-title"><i class="fas fa-heartbeat"></i> Session Details</div>
                            <?php if ($membership_type !== ''): ?>
                            <div class="conf-room-name"><?php echo htmlspecialchars($membership_type); ?></div>
                            <?php endif; ?>

                            <div class="conf-dates-row" style="grid-template-columns: 1fr auto 1fr;">
                                <div class="conf-date-block">
                                    <div class="conf-date-label"><i class="fas fa-calendar"></i> Preferred Date</div>
                                    <div class="conf-date-val"><?php echo $preferred_date_fmt; ?></div>
                                </div>
                                <div class="conf-nights-pill">
                                    <span class="conf-nights-num"><?php echo $guests; ?></span>
                                    <span class="conf-nights-lbl"><?php echo $guests === 1 ? 'guest' : 'guests'; ?></span>
                                </div>
                                <div class="conf-date-block conf-date-block--right">
                                    <div class="conf-date-label"><i class="fas fa-clock"></i> Preferred Time</div>
                                    <div class="conf-date-val"><?php echo $preferred_time_fmt; ?></div>
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

                    <?php if ($goals_notes !== ''): ?>
                    <!-- Goals/notes card -->
                    <div class="conf-card">
                        <div class="conf-card-body">
                            <div class="conf-card-title"><i class="fas fa-bullseye"></i> Fitness Goals &amp; Notes</div>
                            <p style="font-size:0.84rem;color:#5C5549;line-height:1.7;margin:0;"><?php echo nl2br(htmlspecialchars($goals_notes)); ?></p>
                        </div>
                    </div>
                    <?php endif; ?>

                    <!-- What happens next card -->
                    <div class="conf-card">
                        <div class="conf-card-body">
                            <div class="conf-card-title"><i class="fas fa-list-check"></i> What Happens Next</div>
                            <ul class="conf-steps">
                                <li class="conf-step"><span class="conf-step-num">1</span><span>Our wellness team reviews your request and confirms availability.</span></li>
                                <li class="conf-step"><span class="conf-step-num">2</span><span>We contact you within 24 hours to confirm your session time.</span></li>
                                <li class="conf-step"><span class="conf-step-num">3</span><span>Arrive at the time confirmed and enjoy your wellness experience.</span></li>
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
                <a href="https://wa.me/<?php echo rawurlencode(preg_replace('/[^0-9+]/', '', (string)$whatsapp_number)); ?>?text=<?php echo rawurlencode('Hi, I have a gym booking (' . $inquiry['reference_number'] . ')'); ?>" class="conf-btn conf-btn--whatsapp" target="_blank" rel="noopener">
                    <i class="fab fa-whatsapp"></i> WhatsApp
                </a>
                <?php endif; ?>
                <a href="mailto:<?php echo $email_main; ?>?subject=Gym+Booking+<?php echo urlencode($inquiry['reference_number']); ?>" class="conf-btn conf-btn--ghost">
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
