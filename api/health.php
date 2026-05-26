<?php
/**
 * Public Health Endpoint
 *
 * GET /api/health.php
 *
 *   Returns JSON describing the operational status of the system. Intended for:
 *     - cPanel / external uptime monitors
 *     - The admin dashboard's "system health" tile
 *     - The offline banner heartbeat
 *
 *   Surface area is intentionally tiny — never expose schema, version, paths, or env.
 *
 *   Rate limit: 30 requests / minute / IP via APCu (when present) or a tiny file lock.
 *   Fails open if the limiter itself errors — we don't want a broken limiter to
 *   produce a false "unhealthy" reading.
 */

declare(strict_types=1);

header('Content-Type: application/json');
header('Cache-Control: no-store');
header('X-Content-Type-Options: nosniff');

$ROOT = dirname(__DIR__);

// --- Rate limiting --------------------------------------------------------------------------
$ip = $_SERVER['REMOTE_ADDR'] ?? 'unknown';
$rateOk = true;
try {
    if (function_exists('apcu_enabled') && apcu_enabled()) {
        $key    = 'rh_health_' . $ip . '_' . floor(time() / 60);
        $exists = false; // will be set by reference inside apcu_inc
        $count  = function_exists('apcu_inc') ? apcu_inc($key, 1, $exists, 70) : false;
        if ($count !== false && $count > 30) { $rateOk = false; }
    } else {
        $bucket = sys_get_temp_dir() . '/rh_health_' . hash('sha256', $ip) . '.txt';
        $now = time();
        $window = 60;
        $hits = [];
        if (is_file($bucket)) {
            $lines = @file($bucket, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
            foreach ((array)$lines as $t) {
                if (((int)$t) >= $now - $window) { $hits[] = (int)$t; }
            }
        }
        $hits[] = $now;
        @file_put_contents($bucket, implode("\n", $hits));
        if (count($hits) > 30) { $rateOk = false; }
    }
} catch (Throwable $e) {
    // fail open
}

if (!$rateOk) {
    http_response_code(429);
    echo json_encode(['ok' => false, 'error' => 'rate_limited']);
    exit;
}

// --- DB ping --------------------------------------------------------------------------------
$dbOk = false;
$lastBackupAt = null;
$lastTentativeSweepAt = null;
$lastBackupSize = null;
try {
    require_once $ROOT . '/config/database.php';
    if ($pdo instanceof PDO) {
        $row = $pdo->query("SELECT 1")->fetch(PDO::FETCH_NUM);
        $dbOk = !empty($row);

        $stmt = $pdo->prepare("SELECT setting_key, setting_value FROM site_settings WHERE setting_key IN ('last_backup_at','last_tentative_sweep_at','last_backup_size')");
        $stmt->execute();
        foreach ($stmt as $r) {
            switch ($r['setting_key']) {
                case 'last_backup_at':           $lastBackupAt           = $r['setting_value']; break;
                case 'last_tentative_sweep_at':  $lastTentativeSweepAt   = $r['setting_value']; break;
                case 'last_backup_size':         $lastBackupSize         = (int)$r['setting_value']; break;
            }
        }
    }
} catch (Throwable $e) {
    $dbOk = false;
}

// --- Compute freshness flags ----------------------------------------------------------------
$backupStale = true;
if ($lastBackupAt) {
    $age = time() - strtotime($lastBackupAt);
    $backupStale = ($age > 36 * 3600); // > 36h since last backup considered stale
}

$payload = [
    'ok'                       => $dbOk,
    'db'                       => $dbOk ? 'ok' : 'down',
    'server_time'              => date('c'),
    'last_backup_at'           => $lastBackupAt,
    'last_backup_age_hours'    => $lastBackupAt ? round((time() - strtotime($lastBackupAt)) / 3600, 1) : null,
    'last_backup_stale'        => $backupStale,
    'last_backup_size_bytes'   => $lastBackupSize,
    'last_tentative_sweep_at'  => $lastTentativeSweepAt,
];

http_response_code($dbOk ? 200 : 503);
echo json_encode($payload, JSON_UNESCAPED_SLASHES);
