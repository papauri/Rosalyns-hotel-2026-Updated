<?php

/**
 * Review Submission Confirmation
 * Hotel Website - Professional Confirmation Page
 */

// Start session
session_start();

require_once __DIR__ . '/config/database.php';
require_once __DIR__ . '/config/base-url.php';

// Require session data — redirect away if accessed directly
if (empty($_SESSION['review_details'])) {
    header('Location: ' . (defined('BASE_URL') ? BASE_URL : '/') . 'index.php');
    exit;
}
$review_details = $_SESSION['review_details'];
unset($_SESSION['review_details']);

// Get site name
$site_name = getSetting('site_name', 'Hotel Website');
?>
<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <?php
    $seo_data = [
        'title' => 'Review Submitted | ' . $site_name,
        'description' => 'Thank you for sharing your experience at ' . $site_name . '.',
        'type' => 'website',
        'noindex' => true,
    ];
    require_once 'includes/seo-meta.php';
    ?>

    <!-- Fonts -->
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Cormorant+Garamond:ital,wght@0,300;0,400;0,500;0,600;1,300;1,400;1,500&family=Jost:wght@300;400;500;600&display=swap" rel="stylesheet" media="print" onload="this.media='all'">

    <!-- Font Awesome -->
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.7.2/css/all.min.css">

    <!-- Main CSS - Loads all stylesheets in correct order -->
    <link rel="stylesheet" href="css/main.css">
    <!-- Review form CSS (confirmation classes) -->
    <link rel="stylesheet" href="css/review-form.css">
</head>

<body>
    <?php include 'includes/loader.php'; ?>
    <?php include 'includes/header.php'; ?>

    <main id="main-content" class="review-confirmation-page">
        <div class="confirmation-container">
            <div class="success-icon">
                <i class="fas fa-check"></i>
            </div>

            <h1 class="confirmation-title">Thank You!</h1>

            <p class="confirmation-message">
                Your review has been successfully submitted and is pending moderation.
                We appreciate you taking the time to share your experience.
            </p>

            <div class="confirmation-details">
                <p>
                    <i class="fas fa-clock"></i>
                    Your review will be visible within 24-48 hours
                </p>
                <p>
                    <i class="fas fa-shield-alt"></i>
                    All reviews are verified before publication
                </p>
                <p>
                    <i class="fas fa-heart"></i>
                    Your feedback helps us improve our services
                </p>
            </div>

            <div class="confirmation-actions">
                <a href="index.php" class="btn btn-primary">
                    <i class="fas fa-home"></i> Return Home
                </a>
                <a href="rooms-gallery.php" class="btn btn-secondary">
                    <i class="fas fa-bed"></i> View Rooms
                </a>
            </div>

            <p class="footer-note">
                <?php echo htmlspecialchars($site_name); ?> &copy; <?php echo date('Y'); ?>
            </p>
        </div>
    </main>

    <?php include 'includes/footer.php'; ?>
</body>

</html>
