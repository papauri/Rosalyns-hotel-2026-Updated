<?php

/**
 * Custom 404 Not Found Page
 */

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

require_once __DIR__ . '/config/database.php';
require_once __DIR__ . '/config/base-url.php';
require_once __DIR__ . '/includes/page-guard.php';
require_once __DIR__ . '/includes/booking-functions.php';

http_response_code(404);

$site_name = getSetting('site_name', 'Hotel');
$site_logo = getSetting('site_logo', '');
$phone_main = getSetting('phone_main', '');

$policies = [];
try {
    $policyStmt = $pdo->query("SELECT slug, title, summary, content FROM policies WHERE is_active = 1 ORDER BY display_order ASC, id ASC");
    $policies = $policyStmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    // non-fatal
}
?>
<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <?php
    $seo_data = [
        'title' => 'Page Not Found | ' . $site_name,
        'description' => 'The page you are looking for could not be found.',
        'noindex' => true,
        'type' => 'website',
    ];
    require_once 'includes/seo-meta.php';
    ?>

    <meta name="mobile-web-app-capable" content="yes">
    <meta name="apple-mobile-web-app-capable" content="yes">
    <meta name="apple-mobile-web-app-status-bar-style" content="black-translucent">

    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Cormorant+Garamond:ital,wght@0,300;0,400;0,500;0,600;1,300;1,400;1,500&family=Jost:wght@300;400;500;600&display=swap" rel="stylesheet" media="print" onload="this.media='all'">

    <link rel="stylesheet" href="css/base/critical.css">
    <link rel="stylesheet" href="css/main.css">

    <style>
        .not-found-section {
            min-height: 70vh;
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 4rem 1.5rem;
        }

        .not-found-inner {
            text-align: center;
            max-width: 560px;
            margin: 0 auto;
        }

        .not-found-code {
            font-family: var(--font-serif, 'Cormorant Garamond', serif);
            font-size: clamp(6rem, 18vw, 12rem);
            font-weight: 300;
            line-height: 1;
            color: var(--gold, #b8985a);
            letter-spacing: 0.08em;
            margin-bottom: 0;
        }

        .not-found-title {
            font-family: var(--font-serif, 'Cormorant Garamond', serif);
            font-size: clamp(1.5rem, 4vw, 2.25rem);
            font-weight: 400;
            margin: 0.5rem 0 1rem;
            color: var(--text-dark, #1a1a1a);
        }

        .not-found-desc {
            font-family: var(--font-sans, 'Jost', sans-serif);
            font-size: 1rem;
            color: var(--text-muted, #666);
            margin-bottom: 2.5rem;
            line-height: 1.7;
        }

        .not-found-actions {
            display: flex;
            gap: 1rem;
            justify-content: center;
            flex-wrap: wrap;
        }

        .not-found-divider {
            width: 48px;
            height: 1px;
            background: var(--gold, #b8985a);
            margin: 1.25rem auto;
        }
    </style>
</head>

<body class="not-found-page">
    <?php include 'includes/loader.php'; ?>
    <?php include 'includes/header.php'; ?>

    <main id="main-content">
        <section class="not-found-section">
            <div class="not-found-inner">
                <div class="not-found-code">404</div>
                <div class="not-found-divider"></div>
                <h1 class="not-found-title">Page Not Found</h1>
                <p class="not-found-desc">
                    The page you were looking for doesn't exist or may have moved.<br>
                    <?php if (!empty($phone_main)): ?>
                        If you need assistance, call us at <a href="tel:<?php echo htmlspecialchars(preg_replace('/[^0-9+]/', '', $phone_main)); ?>"><?php echo htmlspecialchars($phone_main); ?></a>.
                    <?php endif; ?>
                </p>
                <div class="not-found-actions">
                    <a href="index.php" class="btn btn-primary"><i class="fas fa-home"></i> Back to Home</a>
                    <a href="rooms-gallery.php" class="btn btn-secondary"><i class="fas fa-bed"></i> View Rooms</a>
                    <a href="booking.php" class="btn btn-secondary"><i class="fas fa-calendar-check"></i> Make a Booking</a>
                </div>
            </div>
        </section>
    </main>

    <?php include 'includes/footer.php'; ?>
</body>

</html>
