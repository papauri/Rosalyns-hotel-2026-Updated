<?php
require_once __DIR__ . '/../config/database.php';

// Create UAT test user (idempotent)
$hash = '$2y$10$47ta.7Oa3FCYben4DinxlO.m6e6BeHJtoXvyoW5PoEMUHB3F9/Bc.';
$ins = $pdo->prepare("INSERT INTO admin_users (username, password_hash, full_name, email, role, is_active, created_at)
    VALUES ('rosalyns_uat', ?, 'UAT Test Account', 'uat@rosalyns.local', 'manager', 1, NOW())
    ON DUPLICATE KEY UPDATE password_hash = VALUES(password_hash), is_active = 1");
$ins->execute([$hash]);
echo "UAT user created/updated: rosalyns_uat / RosalynsUAT2026!\n";

// Admin users
$st = $pdo->query("SELECT username, full_name, email, role FROM admin_users ORDER BY id LIMIT 10");
echo "\n=== ADMIN USERS ===\n";
while ($row = $st->fetch(PDO::FETCH_ASSOC)) {
    echo $row['username'] . ' | ' . $row['full_name'] . ' | ' . $row['email'] . ' | ' . $row['role'] . "\n";
}
