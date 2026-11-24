<?php
require_once __DIR__ . '/admin-init.php';
// The installed admin app opens here (admin/manifest.php start_url), so send each person to their own
// main page from their permissions, not one fixed screen. No main page: dashboard.php shows the no-access card.
header('Location: ' . (rhUserHomePage((int)$user['id'], (string)($user['role'] ?? '')) ?? 'dashboard.php'));
exit;
