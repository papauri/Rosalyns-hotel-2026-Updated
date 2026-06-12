<?php
/**
 * Migration 020: Restaurant Staff role + dedicated POS till permission.
 *
 *  - Extends admin_users.role enum to include 'restaurant_staff'.
 *  - Adds a `pos_till` permission key.
 *  - Adds `restaurant_staff` and `pos_till` mappings into the page->permission table
 *    is handled in admin/includes/permissions.php (code edit).
 */
require_once __DIR__ . '/../../config/database.php';

function out020(string $m, string $t = 'info'): void { echo "[$t] $m\n"; }

try {
    $pdo->exec("ALTER TABLE admin_users MODIFY role ENUM('admin','manager','receptionist','housekeeping','accountant','viewer','restaurant_staff') NOT NULL DEFAULT 'receptionist'");
    out020('admin_users.role enum extended with restaurant_staff', 'ok');

    out020('Migration 020 done.', 'done');
} catch (Throwable $e) {
    out020('FAIL: ' . $e->getMessage(), 'err');
    exit(1);
}

