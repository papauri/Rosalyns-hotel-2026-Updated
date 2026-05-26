<?php
/**
 * Migration Script: Add Tourism Levy Support
 * 
 * This script adds tourism levy columns to the bookings table and
 * inserts default settings into the site_settings table.
 * 
 * Usage: Access this file directly in a browser or run via CLI:
 *        php admin/migrations/012_add_tourism_levy.php
 */

// Load database configuration
require_once __DIR__ . '/../../config/database.php';

echo "<h2>Tourism Levy Migration</h2>";
echo "<p>Adding tourism levy support to the system...</p>";

try {
    // Check if columns already exist in bookings table
    $checkColumns = $pdo->query("SHOW COLUMNS FROM bookings LIKE 'tourism_levy_amount'");
    $levyAmountExists = $checkColumns->rowCount() > 0;
    
    $checkColumns = $pdo->query("SHOW COLUMNS FROM bookings LIKE 'tourism_levy_percent'");
    $levyPercentExists = $checkColumns->rowCount() > 0;
    
    // Add tourism_levy_amount column if it doesn't exist
    if (!$levyAmountExists) {
        $sql = "ALTER TABLE bookings ADD COLUMN tourism_levy_amount DECIMAL(10,2) DEFAULT 0.00 COMMENT 'Tourism levy amount charged'";
        $pdo->exec($sql);
        echo "<p>✓ Added <code>tourism_levy_amount</code> column to <code>bookings</code> table</p>";
    } else {
        echo "<p>✓ Column <code>tourism_levy_amount</code> already exists in <code>bookings</code> table</p>";
    }
    
    // Add tourism_levy_percent column if it doesn't exist
    if (!$levyPercentExists) {
        $sql = "ALTER TABLE bookings ADD COLUMN tourism_levy_percent DECIMAL(5,2) DEFAULT 0.00 COMMENT 'Tourism levy percentage applied'";
        $pdo->exec($sql);
        echo "<p>✓ Added <code>tourism_levy_percent</code> column to <code>bookings</code> table</p>";
    } else {
        echo "<p>✓ Column <code>tourism_levy_percent</code> already exists in <code>bookings</code> table</p>";
    }
    
    // Insert default settings into site_settings table
    // Check if settings already exist
    $checkSettings = $pdo->prepare("SELECT COUNT(*) FROM site_settings WHERE setting_key = ?");
    $checkSettings->execute(['tourism_levy_enabled']);
    $enabledExists = $checkSettings->fetchColumn() > 0;
    
    $checkSettings->execute(['tourism_levy_percent']);
    $percentExists = $checkSettings->fetchColumn() > 0;
    
    // Insert tourism_levy_enabled setting if it doesn't exist
    if (!$enabledExists) {
        $sql = "INSERT INTO site_settings (setting_key, setting_value, setting_group, updated_at)
                VALUES ('tourism_levy_enabled', '0', 'booking', NOW())";
        $pdo->exec($sql);
        echo "<p>✓ Added <code>tourism_levy_enabled</code> setting to <code>site_settings</code> table (default: disabled)</p>";
    } else {
        echo "<p>✓ Setting <code>tourism_levy_enabled</code> already exists in <code>site_settings</code> table</p>";
    }
    
    // Insert tourism_levy_percent setting if it doesn't exist
    if (!$percentExists) {
        $sql = "INSERT INTO site_settings (setting_key, setting_value, setting_group, updated_at)
                VALUES ('tourism_levy_percent', '1.00', 'booking', NOW())";
        $pdo->exec($sql);
        echo "<p>✓ Added <code>tourism_levy_percent</code> setting to <code>site_settings</code> table (default: 1.00%)</p>";
    } else {
        echo "<p>✓ Setting <code>tourism_levy_percent</code> already exists in <code>site_settings</code> table</p>";
    }
    
    echo "<h3>Migration Completed Successfully!</h3>";
    echo "<p><strong>Summary:</strong></p>";
    echo "<ul>";
    echo "<li>Bookings table columns: <code>tourism_levy_amount</code>, <code>tourism_levy_percent</code></li>";
    echo "<li>Site settings: <code>tourism_levy_enabled</code> (disabled by default), <code>tourism_levy_percent</code> (1.00% by default)</li>";
    echo "</ul>";
    echo "<p><em>You can enable and configure the tourism levy in the admin dashboard settings.</em></p>";
    
} catch (PDOException $e) {
    echo "<p style='color: red;'><strong>Error:</strong> " . htmlspecialchars($e->getMessage()) . "</p>";
    echo "<p>Migration failed. Please check the error message above.</p>";
    exit;
}
