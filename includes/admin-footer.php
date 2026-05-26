<?php
/**
 * Admin Footer HTML Output
 * Shared footer and scripts for admin pages
 * 
 * NOTE: This file outputs HTML.
 */

// Load permissions system
require_once __DIR__ . '/permissions.php';
?>
    <!-- Main Script (Navigation Toggle) -->
    <script src="js/admin-main.js" defer></script>

    <!-- Components Script (Modals, Alerts, etc.) -->
    <script src="js/admin-components.js" defer></script>