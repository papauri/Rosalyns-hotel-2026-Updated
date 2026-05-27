<?php

/**
 * Scheduled Cache Clear Runner
 *
 * Reads schedule settings saved from admin/cache-management.php and clears cache
 * only when the configured interval is due.
 *
 * Usage:
 *   php scripts/scheduled-cache-clear.php
 *   php scripts/scheduled-cache-clear.php --force
 *   php scripts/scheduled-cache-clear.php --dry-run --verbose
 *   php scripts/scheduled-cache-clear.php --quiet
 */

declare(strict_types=1);

if (php_sapi_name() !== 'cli') {
    http_response_code(403);
    echo "This script may only be executed from CLI.\n";
    exit(1);
}

$ROOT = dirname(__DIR__);

require_once $ROOT . '/config/database.php';
require_once $ROOT . '/config/cache.php';
require_once $ROOT . '/config/page-cache.php';

$opts = getopt('', ['force', 'dry-run', 'verbose', 'quiet']);
$force = isset($opts['force']);
$dryRun = isset($opts['dry-run']);
$verbose = isset($opts['verbose']);
$quiet = isset($opts['quiet']);

$allowedIntervals = ['30sec', '1min', '5min', '15min', '30min', 'hourly', '6hours', '12hours', 'daily', 'weekly', 'custom'];
$secondsMap = [
    '30sec' => 30,
    '1min' => 60,
    '5min' => 300,
    '15min' => 900,
    '30min' => 1800,
    'hourly' => 3600,
    '6hours' => 21600,
    '12hours' => 43200,
];

$logDir = $ROOT . '/logs';
$cacheLog = $logDir . '/cache-clear.log';
$cronErrLog = $logDir . '/cron-errors.log';
$lockPath = $logDir . '/cache-clear.lock';

if (!is_dir($logDir)) {
    @mkdir($logDir, 0775, true);
}

function log_cache_line(string $path, string $message): void
{
    @file_put_contents($path, date('Y-m-d H:i:s') . ' - ' . $message . PHP_EOL, FILE_APPEND | LOCK_EX);
}

function cli_out(string $message, bool $quiet, bool $verbose = false, bool $always = false): void
{
    if ($always || (!$quiet && ($verbose || true))) {
        echo $message . PHP_EOL;
    }
}

function normalize_time(string $raw): string
{
    return preg_match('/^(?:[01]\d|2[0-3]):[0-5]\d$/', $raw) ? $raw : '00:00';
}

function get_settings(PDO $pdo): array
{
    $settings = [];
    $stmt = $pdo->query("SELECT setting_key, setting_value FROM site_settings WHERE setting_key IN ('cache_schedule_enabled','cache_schedule_interval','cache_schedule_time','cache_custom_seconds','cache_last_run')");
    foreach (($stmt->fetchAll(PDO::FETCH_ASSOC) ?: []) as $row) {
        $settings[(string)$row['setting_key']] = (string)$row['setting_value'];
    }
    return $settings;
}

function upsert_setting(PDO $pdo, string $key, string $value): void
{
    $stmt = $pdo->prepare("INSERT INTO site_settings (setting_key, setting_value, updated_at) VALUES (?, ?, NOW()) ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value), updated_at = NOW()");
    $stmt->execute([$key, $value]);
}

function weekly_anchor_timestamp(int $nowTs, string $hhmm): int
{
    $dayOfWeek = (int)date('w', $nowTs); // 0=Sunday
    $sunday = strtotime('-' . $dayOfWeek . ' days', $nowTs);
    $datePart = date('Y-m-d', $sunday);
    $target = strtotime($datePart . ' ' . $hhmm . ':00');
    return $target ?: strtotime($datePart . ' 00:00:00');
}

function should_run(array $cfg, int $nowTs, array $secondsMap, bool $force): array
{
    if ($force) {
        return [true, 'forced'];
    }

    $enabled = ((string)($cfg['cache_schedule_enabled'] ?? '0')) === '1';
    if (!$enabled) {
        return [false, 'schedule_disabled'];
    }

    $interval = (string)($cfg['cache_schedule_interval'] ?? 'daily');
    $time = normalize_time((string)($cfg['cache_schedule_time'] ?? '00:00'));
    $customSeconds = (int)($cfg['cache_custom_seconds'] ?? 60);
    $customSeconds = max(10, min(86400, $customSeconds));
    $lastRun = (int)($cfg['cache_last_run'] ?? 0);

    if (isset($secondsMap[$interval])) {
        $every = (int)$secondsMap[$interval];
        $due = ($lastRun <= 0) || (($nowTs - $lastRun) >= $every);
        return [$due, 'interval_' . $interval . '_every_' . $every . 's'];
    }

    if ($interval === 'custom') {
        $due = ($lastRun <= 0) || (($nowTs - $lastRun) >= $customSeconds);
        return [$due, 'interval_custom_every_' . $customSeconds . 's'];
    }

    if ($interval === 'daily') {
        $todayTarget = strtotime(date('Y-m-d', $nowTs) . ' ' . $time . ':00');
        if ($todayTarget === false) {
            $todayTarget = strtotime(date('Y-m-d', $nowTs) . ' 00:00:00');
        }
        $due = ($nowTs >= $todayTarget) && ($lastRun < $todayTarget);
        return [$due, 'interval_daily_at_' . $time];
    }

    if ($interval === 'weekly') {
        $weeklyTarget = weekly_anchor_timestamp($nowTs, $time);
        $due = ($nowTs >= $weeklyTarget) && ($lastRun < $weeklyTarget);
        return [$due, 'interval_weekly_sunday_' . $time];
    }

    return [false, 'interval_unknown'];
}

$lockHandle = @fopen($lockPath, 'c+');
if (!$lockHandle) {
    $msg = 'Cannot open lock file: ' . $lockPath;
    log_cache_line($cronErrLog, $msg);
    fwrite(STDERR, $msg . PHP_EOL);
    exit(2);
}
if (!@flock($lockHandle, LOCK_EX | LOCK_NB)) {
    log_cache_line($cacheLog, 'Scheduled cache clear skipped: lock already held.');
    cli_out('Skipped: cache clear is already running.', $quiet, $verbose);
    fclose($lockHandle);
    exit(0);
}
register_shutdown_function(static function () use (&$lockHandle): void {
    if (is_resource($lockHandle)) {
        @flock($lockHandle, LOCK_UN);
        @fclose($lockHandle);
    }
});

try {
    global $pdo;
    if (!$pdo instanceof PDO) {
        throw new RuntimeException('Database connection unavailable.');
    }

    $settings = get_settings($pdo);

    $interval = (string)($settings['cache_schedule_interval'] ?? 'daily');
    if (!in_array($interval, $allowedIntervals, true)) {
        $interval = 'daily';
    }
    $settings['cache_schedule_interval'] = $interval;
    $settings['cache_schedule_time'] = normalize_time((string)($settings['cache_schedule_time'] ?? '00:00'));

    [$shouldRun, $reason] = should_run($settings, time(), $secondsMap, $force);

    if (!$shouldRun) {
        cli_out('No run needed (' . $reason . ').', $quiet, $verbose);
        exit(0);
    }

    if ($dryRun) {
        $msg = 'DRY RUN: Scheduled cache clear due (' . $reason . ').';
        log_cache_line($cacheLog, $msg);
        cli_out($msg, $quiet, $verbose, true);
        exit(0);
    }

    $mainCleared = (int)clearCache();
    $pageCleared = (int)clearPageCache();
    $totalCleared = $mainCleared + $pageCleared;

    $now = (string)time();
    upsert_setting($pdo, 'cache_last_run', $now);

    $msg = 'Scheduled cache clear executed. Interval: ' . $interval
        . ', Reason: ' . $reason
        . ', Files cleared: ' . $totalCleared
        . ' (main+image=' . $mainCleared . ', pages=' . $pageCleared . ')';
    log_cache_line($cacheLog, $msg);
    cli_out($msg, $quiet, $verbose, true);

    exit(0);
} catch (Throwable $e) {
    $err = 'Scheduled cache clear failed: ' . $e->getMessage();
    log_cache_line($cronErrLog, $err);
    fwrite(STDERR, $err . PHP_EOL);
    exit(1);
}
