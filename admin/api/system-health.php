<?php
/**
 * Admin System Health API
 *
 * GET /admin/api/system-health.php
 *
 * Returns extended system status for the admin dashboard health monitor.
 * Protected by admin session — returns 401 if unauthenticated.
 */
declare(strict_types=1);

require_once __DIR__ . '/api-init.php';

header('Content-Type: application/json');
header('Cache-Control: no-store');

$ROOT = dirname(dirname(__DIR__));

$result = [
    'ok'                       => false,
    'server_time'              => date('c'),
    'php_version'              => PHP_VERSION,
    'db'                       => 'down',
    'last_backup_at'           => null,
    'last_backup_age_hours'    => null,
    'last_backup_stale'        => true,
    'last_backup_size_bytes'   => null,
    'last_tentative_sweep_at'  => null,
    'disk_free_bytes'          => null,
    'disk_total_bytes'         => null,
    'disk_free_pct'            => null,
    'log_error_size_bytes'     => null,
    'backup_count_month'       => null,
];

// --- Database ping + settings ------------------------------------------------------------------
try {
    if (isset($pdo) && $pdo instanceof PDO) {
        $row = $pdo->query("SELECT 1")->fetch(PDO::FETCH_NUM);
        $result['db'] = !empty($row) ? 'ok' : 'down';

        $stmt = $pdo->prepare(
            "SELECT setting_key, setting_value FROM site_settings
             WHERE setting_key IN ('last_backup_at','last_tentative_sweep_at','last_backup_size')"
        );
        $stmt->execute();
        foreach ($stmt as $r) {
            switch ($r['setting_key']) {
                case 'last_backup_at':          $result['last_backup_at']        = $r['setting_value']; break;
                case 'last_tentative_sweep_at': $result['last_tentative_sweep_at'] = $r['setting_value']; break;
                case 'last_backup_size':        $result['last_backup_size_bytes'] = (int)$r['setting_value']; break;
            }
        }
    }
} catch (Throwable $e) {
    $result['db'] = 'down';
}

// --- Backup freshness --------------------------------------------------------------------------
if ($result['last_backup_at']) {
    $age = time() - (int)strtotime($result['last_backup_at']);
    $result['last_backup_age_hours'] = round($age / 3600, 1);
    $result['last_backup_stale']     = ($age > 36 * 3600);
}

$result['ok'] = ($result['db'] === 'ok');

// --- Disk space --------------------------------------------------------------------------------
try {
    $free  = @disk_free_space($ROOT);
    $total = @disk_total_space($ROOT);
    if ($free !== false && $total !== false && $total > 0) {
        $result['disk_free_bytes']  = (int)$free;
        $result['disk_total_bytes'] = (int)$total;
        $result['disk_free_pct']    = round(($free / $total) * 100, 1);
    }
} catch (Throwable $e) {}

// --- Log file size -----------------------------------------------------------------------------
try {
    $errLog = $ROOT . '/logs/php-errors.log';
    if (is_file($errLog)) {
        $result['log_error_size_bytes'] = (int)filesize($errLog);
    }
} catch (Throwable $e) {}

// --- Backup count this month -------------------------------------------------------------------
try {
    $bkDir = $ROOT . '/backups/' . date('Y') . '/' . date('m');
    if (is_dir($bkDir)) {
        $files = glob($bkDir . '/db-*.sql.gz');
        $result['backup_count_month'] = $files !== false ? count($files) : 0;
    }
} catch (Throwable $e) {}

echo json_encode($result, JSON_UNESCAPED_SLASHES);
