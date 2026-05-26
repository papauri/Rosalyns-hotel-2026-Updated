<?php
/**
 * Page Guard
 * Include this early in any public page to check if the page is enabled
 * in the site_pages table. If the page is disabled, the visitor is
 * redirected to the home page.
 *
 * Usage (at the top of a public page, after database.php):
 *   require_once 'includes/page-guard.php';
 *
 * The guard auto-detects the current filename and looks it up.
 * If the site_pages table doesn't exist yet, the page loads normally.
 */

if (!isset($pdo)) return; // No DB connection — skip guard

$_pg_file = basename($_SERVER['PHP_SELF']);

// Never block the home page, booking confirmation, or core contact pages
$_pg_skip = ['index.php', 'booking-confirmation.php', 'review-confirmation.php', 'submit-review.php', 'test-base-url.php', 'contact-us.php'];
if (in_array($_pg_file, $_pg_skip, true)) return;

try {
    $_pg_stmt = $pdo->prepare("SELECT is_enabled FROM site_pages WHERE file_path = ? LIMIT 1");
    $_pg_stmt->execute([$_pg_file]);
    $_pg_row = $_pg_stmt->fetch(PDO::FETCH_ASSOC);

    // If the page isn't in the table at all, allow it (not yet managed)
    if ($_pg_row === false) return;

    // If explicitly disabled, redirect to home
    if (!(int)$_pg_row['is_enabled']) {
        if (defined('BASE_URL')) {
            header('Location: ' . BASE_URL);
        } elseif (function_exists('siteUrl')) {
            header('Location: ' . siteUrl('/'));
        } else {
            header('Location: /');
        }
        exit;
    }
} catch (PDOException $e) {
    // Table doesn't exist yet — allow all pages
    return;
}
