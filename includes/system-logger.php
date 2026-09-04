<?php
/**
 * Shared system event logger.
 *
 * Writes high-signal operational events to both the database and
 * logs/system-events.log so the admin portal can show live system activity
 * even if one logging backend is temporarily unavailable.
 */

if (!defined('RH_SYSTEM_LOGGER_LOADED')) {
    define('RH_SYSTEM_LOGGER_LOADED', true);
}

if (!function_exists('rh_ensure_system_event_log_table')) {
    function rh_ensure_system_event_log_table(?PDO $pdo = null): void
    {
        static $checked = false;
        if ($checked) {
            return;
        }

        $pdo = $pdo ?: ($GLOBALS['pdo'] ?? null);
        if (!$pdo instanceof PDO) {
            return;
        }

        /* NEVER run this DDL inside someone else's transaction. CREATE TABLE is an implicit
         * COMMIT in MySQL, so a first-of-request rh_log_event() fired from inside a business
         * transaction silently committed that transaction mid-flight: the caller's later
         * commit() then threw "There is no active transaction" and reported failure to the
         * user for work that had, in fact, already been permanently written. That is exactly
         * how a POS settlement came back as an error after the payment row was committed —
         * the sort of thing that gets a guest charged twice. Skipping the lazy create while a
         * transaction is open costs nothing: the log write below degrades to a no-op for this
         * one call, and the table gets created on the next call outside a transaction. Same
         * guard finance_ensure_sequence_tables() already uses for the same reason. */
        if ($pdo->inTransaction()) {
            return;
        }

        try {
            $pdo->exec("\n                CREATE TABLE IF NOT EXISTS system_event_log (\n                    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,\n                    source VARCHAR(80) NOT NULL,\n                    level VARCHAR(20) NOT NULL DEFAULT 'info',\n                    message VARCHAR(1000) NOT NULL,\n                    context_json LONGTEXT NULL,\n                    user_id INT NULL,\n                    username VARCHAR(100) NULL,\n                    ip_address VARCHAR(45) NULL,\n                    request_uri VARCHAR(500) NULL,\n                    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,\n                    INDEX idx_system_event_created (created_at),\n                    INDEX idx_system_event_source (source),\n                    INDEX idx_system_event_level (level)\n                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci\n            ");
            $checked = true;
        } catch (Throwable $e) {
            error_log('system_event_log ensure failed: ' . $e->getMessage());
        }
    }
}

if (!function_exists('rh_scrub_log_context')) {
    function rh_scrub_log_context(mixed $value): mixed
    {
        if (!is_array($value)) {
            return $value;
        }

        $clean = [];
        foreach ($value as $key => $item) {
            $keyString = strtolower((string)$key);
            if (preg_match('/(password|token|secret|api[_-]?key|authorization|credential)/', $keyString)) {
                $clean[$key] = '[redacted]';
                continue;
            }
            $clean[$key] = is_array($item) ? rh_scrub_log_context($item) : $item;
        }

        return $clean;
    }
}

if (!function_exists('rh_log_event')) {
    function rh_log_event(string $source, string $level, string $message, array $context = []): void
    {
        $allowedLevels = ['debug', 'info', 'warning', 'error', 'critical'];
        $level = strtolower(trim($level));
        if (!in_array($level, $allowedLevels, true)) {
            $level = 'info';
        }

        $source = substr(preg_replace('/[^a-z0-9_.:-]/i', '_', trim($source)) ?: 'system', 0, 80);
        $message = substr(trim($message), 0, 1000);
        if ($message === '') {
            $message = 'System event';
        }

        $context = rh_scrub_log_context($context);
        $contextJson = $context ? json_encode($context, JSON_UNESCAPED_SLASHES) : null;
        if ($contextJson !== null && strlen($contextJson) > 65000) {
            $contextJson = substr($contextJson, 0, 65000);
        }

        $userId = isset($_SESSION['admin_user_id']) ? (int)$_SESSION['admin_user_id'] : null;
        $username = $_SESSION['admin_username'] ?? ($GLOBALS['user']['username'] ?? null);
        $ip = $_SERVER['REMOTE_ADDR'] ?? null;
        $uri = isset($_SERVER['REQUEST_URI']) ? substr((string)$_SERVER['REQUEST_URI'], 0, 500) : null;

        $pdo = $GLOBALS['pdo'] ?? null;
        if ($pdo instanceof PDO) {
            try {
                rh_ensure_system_event_log_table($pdo);
                $stmt = $pdo->prepare("\n                    INSERT INTO system_event_log\n                    (source, level, message, context_json, user_id, username, ip_address, request_uri, created_at)\n                    VALUES (?, ?, ?, ?, ?, ?, ?, ?, NOW())\n                ");
                $stmt->execute([$source, $level, $message, $contextJson, $userId, $username, $ip, $uri]);
            } catch (Throwable $e) {
                error_log('rh_log_event database write failed: ' . $e->getMessage());
            }
        }

        try {
            $logDir = dirname(__DIR__) . '/logs';
            if (!is_dir($logDir)) {
                @mkdir($logDir, 0755, true);
            }
            $line = json_encode([
                'time' => date('c'),
                'source' => $source,
                'level' => $level,
                'message' => $message,
                'user_id' => $userId,
                'username' => $username,
                'ip' => $ip,
                'uri' => $uri,
                'context' => $context,
            ], JSON_UNESCAPED_SLASHES) . "\n";
            @file_put_contents($logDir . '/system-events.log', $line, FILE_APPEND | LOCK_EX);
        } catch (Throwable $e) {
            error_log('rh_log_event file write failed: ' . $e->getMessage());
        }
    }
}
