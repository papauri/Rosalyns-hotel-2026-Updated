<?php
if (PHP_SAPI !== 'cli') { http_response_code(403); exit("CLI only.\n"); } // never runnable over the web
/**
 * Hotel backups never hold, and hotel restores never touch, the MSP control panel's own tables (cp_*), which share
 * Liwonde's database (owner 2026-11-23, backup review). NO DATABASE: only the pure helpers in includes/db-backup.php
 * are called. Usage (repo root): php scripts/tests/db_backup_panel_tables.php
 */
$root = dirname(__DIR__, 2);
$pass = 0; $fail = 0;
function ok(string $l): void { global $pass; $pass++; echo "PASS $l\n"; }
function bad(string $l, string $d = ''): void { global $fail; $fail++; echo "FAIL $l" . ($d !== '' ? ": $d" : '') . "\n"; }
function check(bool $c, string $l, string $d = ''): bool { $c ? ok($l) : bad($l, $d); return $c; }

require_once $root . '/includes/db-backup.php';

// Which tables are skipped
foreach (['cp_tenants', 'cp_admins', 'CP_Payments', 'cp_ai_config'] as $t) {
    check(rh_db_backup_skip_table($t), "panel table skipped: $t");
}
foreach (['bookings', 'rooms', 'site_settings', 'cpanel_notes', 'c_p', 'ecp_x', 'admin_users', ''] as $t) {
    check(!rh_db_backup_skip_table($t), "hotel table kept: '$t'");
}

// Restore statements that write to a panel table are skipped (as the splitter emits them: comments stripped,
// executable /*! */ comments kept)
$panel = [
    'DROP TABLE IF EXISTS `cp_tenants`',
    "CREATE TABLE `cp_tenants` (\n  `id` int NOT NULL\n)",
    'CREATE TABLE IF NOT EXISTS `cp_admins` (`id` int)',
    "INSERT INTO `cp_payments` (`id`) VALUES (1),(2)",
    'INSERT IGNORE INTO cp_payments VALUES (1)',
    'REPLACE INTO `cp_x` VALUES (1)',
    'LOCK TABLES `cp_tenants` WRITE',
    '/*!40000 ALTER TABLE `cp_tenants` DISABLE KEYS */',
    '/*!40000 ALTER TABLE `cp_tenants` ENABLE KEYS */',
    "  \n drop table if exists `CP_Tenants`",
    'TRUNCATE TABLE `cp_x`',
];
foreach ($panel as $sql) {
    check(rh_db_backup_is_panel_stmt($sql), 'restore skips: ' . substr(preg_replace('/\s+/', ' ', $sql), 0, 50));
}
$hotel = [
    'DROP TABLE IF EXISTS `bookings`',
    'CREATE TABLE `rooms` (`id` int)',
    "INSERT INTO `site_settings` (`setting_key`,`setting_value`) VALUES ('note','cp_tenants')",
    "INSERT INTO `bookings` VALUES (1,'DROP TABLE cp_tenants')",
    '/*!40000 ALTER TABLE `bookings` DISABLE KEYS */',
    'UNLOCK TABLES',
    "/*!40101 SET NAMES utf8mb4 */",
    'SET FOREIGN_KEY_CHECKS=0',
    'CREATE TABLE `cpanel_notes` (`id` int)',
    'INSERT INTO `ecp_x` VALUES (1)',
];
foreach ($hotel as $sql) {
    check(!rh_db_backup_is_panel_stmt($sql), 'restore keeps: ' . substr(preg_replace('/\s+/', ' ', $sql), 0, 50));
}

echo "\n$pass passed, $fail failed\n";
exit($fail === 0 ? 0 : 1);
