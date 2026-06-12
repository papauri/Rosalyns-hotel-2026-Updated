<?php

/**
 * Migration 036 — Facebook Settings
 * Seeds site_settings with facebook_* keys (safe defaults, posting disabled until configured).
 * Run: php admin/migrations/036_facebook_settings.php
 */

require_once __DIR__ . '/../../config/database.php';

$rows = [
    ['facebook_posting_enabled',  '0'],
    ['facebook_page_id',          ''],
    ['facebook_page_name',        ''],
    ['facebook_page_access_token', ''],
    ['facebook_default_hashtags', '#hotel #accommodation #luxury'],
    ['facebook_rooms_enabled',    '1'],
    ['facebook_events_enabled',   '1'],
    ['facebook_conference_enabled', '1'],
    ['facebook_menu_enabled',     '1'],
    ['facebook_post_log_enabled', '1'],
];

$stmt = $pdo->prepare("
    INSERT INTO site_settings (setting_key, setting_value)
    VALUES (?, ?)
    ON DUPLICATE KEY UPDATE setting_value = setting_value
");

$count = 0;
foreach ($rows as [$key, $value]) {
    $stmt->execute([$key, $value]);
    if ($stmt->rowCount() > 0) {
        $count++;
        echo "Inserted: $key\n";
    } else {
        echo "Skipped (already exists): $key\n";
    }
}

echo "\nDone. $count new row(s) inserted.\n";

