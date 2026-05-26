<?php
/**
 * DATABASE ENVIRONMENT LOADER
 *
 * This file contains NO credentials and is safe to commit to Git.
 *
 * Credentials are read from:
 *   1. A .env file at the project root (gitignored, lives only on the server)
 *   2. Server / OS environment variables (set via cPanel, SSH export, or hosting panel)
 *
 * HOW TO SET CREDENTIALS ON THE SERVER
 * ─────────────────────────────────────
 * Option A (recommended) — .env file:
 *   1. Copy .env.example → .env at the project root on the server.
 *   2. Fill in the real values. The file is already in .gitignore.
 *   3. Set file permissions to 600: chmod 600 .env
 *
 * Option B — cPanel Environment Variables:
 *   cPanel → Software → MultiPHP Manager → PHP Options, or set via SSH:
 *     export DB_HOST=...   DB_NAME=...   DB_USER=...   DB_PASS=...   DB_PORT=3306
 *
 * DO NOT put credentials directly in this file.
 */

// ── Load .env file if present (project root) ────────────────────────────────
$_envFile = dirname(__DIR__) . '/.env';
if (is_file($_envFile) && is_readable($_envFile)) {
    $lines = file($_envFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    foreach ((array) $lines as $_line) {
        $_line = trim($_line);
        // Skip comments and lines without an = sign
        if ($_line === '' || $_line[0] === '#' || strpos($_line, '=') === false) {
            continue;
        }
        [$_key, $_val] = explode('=', $_line, 2);
        $_key = trim($_key);
        // Strip optional surrounding quotes from the value
        $_val = trim($_val);
        if (strlen($_val) >= 2) {
            $q = $_val[0];
            if (($q === '"' || $q === "'") && $_val[strlen($_val) - 1] === $q) {
                $_val = substr($_val, 1, -1);
            }
        }
        // Only set if not already in the environment (server env takes priority)
        if ($_key !== '' && getenv($_key) === false) {
            putenv("$_key=$_val");
            $_ENV[$_key]    = $_val;
            $_SERVER[$_key] = $_val;
        }
    }
    unset($_line, $_key, $_val, $q, $lines);
}
unset($_envFile);

// ── Read $db_* variables from environment ───────────────────────────────────
$db_host    = getenv('DB_HOST')    ?: '';
$db_name    = getenv('DB_NAME')    ?: '';
$db_user    = getenv('DB_USER')    ?: '';
$db_pass    = getenv('DB_PASS')    ?: '';
$db_port    = getenv('DB_PORT')    ?: '3306';
$db_charset = 'utf8mb4';
