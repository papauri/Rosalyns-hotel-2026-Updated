<?php
require_once 'config/database.php';
require_once 'config/settings.php';
require_once 'includes/functions.php';

$ref = trim(strip_tags($_GET['ref'] ?? ''));

$enquiry = null;
$room    = null;
$error   = null;

if ($ref === '') {
    $error = 'No enquiry reference provided.';
} else {
    try {
        $stmt = $pdo->prepare("
            SELECT ci.*, cr.name AS room_name, cr.capacity AS room_capacity
            FROM conference_inquiries ci
            LEFT JOIN conference_rooms cr ON ci.conference_room_id = cr.id
            WHERE ci.inquiry_reference = ?
            LIMIT 1
        ");
        $stmt->execute([$ref]);
        $enquiry = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$enquiry) {
            $error = 'Enquiry not found. Please check your reference number.';
        }
    } catch (PDOException $e) {
        $error = 'Unable to retrieve enquiry details.';
        error_log('conference-confirmation.php: ' . $e->getMessage());
    }
}

$site_name        = getSetting('site_name');
$currency_symbol  = getSetting('currency_symbol');
$phone_main       = getSetting('phone_main');
$email_events     = getSetting('email_events', getSetting('email_reservations', ''));
$whatsapp_number  = getSetting('whatsapp_number');

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
        'title'       => 'Conference Enquiry Received | ' . $site_name,
        'description' => "Your conference enquiry at {$site_name} has been received.",
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
                    <h1>Enquiry Not Found</h1>
                    <p><?php echo htmlspecialchars($error); ?></p>
                    <a href="conference.php" class="conf-btn conf-btn--primary">Back to Conference</a>
                </div>
            </div>
        <?php else:
            $event_date_fmt  = !empty($enquiry['event_date']) ? date('D, M j, Y', strtotime($enquiry['event_date'])) : '—';
            $start_fmt       = !empty($enquiry['start_time']) ? date('g:i A', strtotime($enquiry['start_time'])) : '—';
            $end_fmt         = !empty($enquiry['end_time'])   ? date('g:i A', strtotime($enquiry['end_time']))   : '—';
            $attendees       = (int)($enquiry['number_of_attendees'] ?? 0);
            $total           = (float)($enquiry['total_amount'] ?? 0);
            $catering        = !empty($enquiry['catering_required']) && $enquiry['catering_required'] != '0';
            $event_type      = trim((string)($enquiry['event_type'] ?? ''));
            $av_equipment    = trim((string)($enquiry['av_equipment'] ?? ''));
            $special_req     = trim((string)($enquiry['special_requirements'] ?? ''));
        ?>

            <!-- Hero -->
            <div class="conf-hero">
                <div class="conf-icon-ring tentative">
                    <i class="fas fa-calendar-check"></i>
                </div>
                <h1 class="conf-heading">Conference Enquiry Received</h1>
                <p class="conf-subtitle">Thank you for contacting <?php echo htmlspecialchars($site_name); ?>. Our events team will review your request and confirm availability within 24 hours.</p>
                <span class="conf-type-pill tentative">
                    <i class="fas fa-clock"></i> Pending Confirmation
                </span>
            </div>

            <!-- 2-col body grid -->
            <div class="conf-body-grid">

                <!-- LEFT / MAIN column -->
                <div class="conf-col-main">

                    <!-- Event details card -->
                    <div class="conf-card">
                        <div class="conf-card-body">
                            <div class="conf-card-title"><i class="fas fa-building"></i> Event Details</div>
                            <div class="conf-room-name"><?php echo htmlspecialchars($enquiry['room_name'] ?? 'Conference Room'); ?></div>

                            <div class="conf-dates-row" style="grid-template-columns: 1fr auto 1fr;">
                                <div class="conf-date-block">
                                    <div class="conf-date-label"><i class="fas fa-calendar"></i> Event Date</div>
                                    <div class="conf-date-val"><?php echo $event_date_fmt; ?></div>
                                </div>
                                <div class="conf-nights-pill">
                                    <span class="conf-nights-num"><?php echo $attendees; ?></span>
                                    <span class="conf-nights-lbl">guests</span>
                                </div>
                                <div class="conf-date-block conf-date-block--right">
                                    <div class="conf-date-label"><i class="fas fa-clock"></i> Time</div>
                                    <div class="conf-date-val"><?php echo $start_fmt; ?></div>
                                    <div class="conf-date-time">until <?php echo $end_fmt; ?></div>
                                </div>
                            </div>

                            <div class="conf-stay-meta">
                                <div class="conf-guests-line">
                                    <i class="fas fa-users"></i>
                                    <?php echo $attendees; ?> attendee<?php echo $attendees === 1 ? '' : 's'; ?>
                                </div>
                                <?php if ($total > 0): ?>
                                <div class="conf-total-block">
                                    <div class="conf-total-label">Estimated</div>
                                    <div class="conf-total-amount"><?php echo $currency_symbol . number_format($total, 0); ?></div>
                                </div>
                                <?php endif; ?>
                            </div>

                            <?php if ($event_type !== '' || $catering || $av_equipment !== ''): ?>
                            <div class="conf-stay-tags">
                                <?php if ($event_type !== ''): ?>
                                    <span class="conf-stay-tag"><i class="fas fa-tag"></i> <?php echo htmlspecialchars($event_type); ?></span>
                                <?php endif; ?>
                                <?php if ($catering): ?>
                                    <span class="conf-stay-tag"><i class="fas fa-utensils"></i> Catering Requested</span>
                                <?php endif; ?>
                                <?php if ($av_equipment !== ''): ?>
                                    <span class="conf-stay-tag"><i class="fas fa-tv"></i> AV Equipment</span>
                                <?php endif; ?>
                            </div>
                            <?php endif; ?>

                            <?php if ($special_req !== ''): ?>
                            <div class="conf-extras" style="margin-top:14px;padding-top:14px;border-top:1px solid rgba(139,115,85,0.08);">
                                <div class="conf-extras-title"><i class="fas fa-sticky-note"></i> Special Requirements</div>
                                <p style="font-size:0.84rem;color:#5C5549;line-height:1.6;margin:0;"><?php echo nl2br(htmlspecialchars($special_req)); ?></p>
                            </div>
                            <?php endif; ?>
                        </div>
                    </div>

                    <!-- What happens next card -->
                    <div class="conf-card">
                        <div class="conf-card-body">
                            <div class="conf-card-title"><i class="fas fa-list-check"></i> What Happens Next</div>
                            <ul class="conf-steps">
                                <li class="conf-step"><span class="conf-step-num">1</span><span>Our events team reviews your enquiry and checks room availability.</span></li>
                                <li class="conf-step"><span class="conf-step-num">2</span><span>We contact you within 24 hours to confirm and discuss any specifics.</span></li>
                                <li class="conf-step"><span class="conf-step-num">3</span><span>Once confirmed, you'll receive a formal booking confirmation with payment details.</span></li>
                            </ul>
                        </div>
                    </div>

                </div><!-- /.conf-col-main -->

                <!-- RIGHT / ASIDE column -->
                <div class="conf-col-aside">

                    <!-- Reference card -->
                    <div class="conf-card">
                        <div class="conf-card-body">
                            <div class="conf-card-title"><i class="fas fa-hashtag"></i> Enquiry Reference</div>
                            <div class="conf-ref-number"><?php echo htmlspecialchars($enquiry['inquiry_reference']); ?></div>
                            <p class="conf-ref-note">Please save this reference. You'll need it when following up with our team.</p>
                        </div>
                    </div>

                    <!-- Contact details card -->
                    <div class="conf-card">
                        <div class="conf-card-body">
                            <div class="conf-card-title"><i class="fas fa-user-tie"></i> Your Details</div>
                            <div class="conf-detail-list">
                                <?php if (!empty($enquiry['company_name'])): ?>
                                <div class="conf-detail-row">
                                    <span>Company</span>
                                    <span><?php echo htmlspecialchars($enquiry['company_name']); ?></span>
                                </div>
                                <?php endif; ?>
                                <div class="conf-detail-row">
                                    <span>Contact</span>
                                    <span><?php echo htmlspecialchars($enquiry['contact_person']); ?></span>
                                </div>
                                <div class="conf-detail-row">
                                    <span>Email</span>
                                    <span><?php echo htmlspecialchars($enquiry['email']); ?></span>
                                </div>
                                <?php if (!empty($enquiry['phone'])): ?>
                                <div class="conf-detail-row">
                                    <span>Phone</span>
                                    <span><?php echo htmlspecialchars($enquiry['phone']); ?></span>
                                </div>
                                <?php endif; ?>
                                <?php if ($total > 0): ?>
                                <div class="conf-detail-row conf-detail-row--total">
                                    <span>Estimated Total</span>
                                    <span><?php echo $currency_symbol . number_format($total, 0); ?></span>
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
                <a href="https://wa.me/<?php echo rawurlencode(preg_replace('/[^0-9+]/', '', (string)$whatsapp_number)); ?>?text=<?php echo rawurlencode('Hi, I have a conference enquiry (' . $enquiry['inquiry_reference'] . ')'); ?>" class="conf-btn conf-btn--whatsapp" target="_blank" rel="noopener">
                    <i class="fab fa-whatsapp"></i> WhatsApp
                </a>
                <?php endif; ?>
                <a href="mailto:<?php echo $email_events; ?>?subject=Conference+Enquiry+<?php echo urlencode($enquiry['inquiry_reference']); ?>" class="conf-btn conf-btn--ghost">
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
