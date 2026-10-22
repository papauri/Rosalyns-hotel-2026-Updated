<?php
/**
 * Error-log housekeeping for Cache Management (admin only).
 *
 * Lists the web server's PHP error_log files from a FIXED whitelist and empties them (truncate to
 * 0 bytes, file kept so the server can keep writing). File contents are never read or shown, and a
 * path is never taken from a request: callers send a whitelist key only.
 *
 * Every path is re-resolved on each call and must (a) exist as a regular file, (b) not be a symlink
 * and (c) have a real path exactly equal to <site root>/<whitelisted relative path>, so a symlinked
 * folder or file can never point the clear at something outside the site.
 */

if (!function_exists('rh_errorlog_whitelist')) {

    /** Whitelist key => [label, path relative to the site root]. */
    function rh_errorlog_whitelist(): array
    {
        return [
            'root'        => ['Site root',                    'error_log'],
            'admin'       => ['Admin pages (admin/)',         'admin/error_log'],
            'api'         => ['Public API (api/)',            'api/error_log'],
            'admin_api'   => ['Admin API (admin/api/)',       'admin/api/error_log'],
            'docs_assets' => ['Guides assets (docs/guides/assets/)', 'docs/guides/assets/error_log'],
        ];
    }

    /** The site root as a real path. $rootOverride exists for tests only. */
    function rh_errorlog_root(?string $rootOverride = null): ?string
    {
        $root = realpath($rootOverride ?? dirname(__DIR__, 2));
        return $root === false ? null : rtrim($root, '/\\');
    }

    /** Case-insensitive path comparison on Windows, exact elsewhere. */
    function rh_errorlog_same_path(string $a, string $b): bool
    {
        $a = str_replace('\\', '/', $a);
        $b = str_replace('\\', '/', $b);
        return DIRECTORY_SEPARATOR === '\\' ? strcasecmp($a, $b) === 0 : $a === $b;
    }

    /** True when $path is strictly inside $root. */
    function rh_errorlog_inside(string $path, string $root): bool
    {
        $path = str_replace('\\', '/', $path);
        $root = rtrim(str_replace('\\', '/', $root), '/') . '/';
        $head = substr($path, 0, strlen($root));
        return strlen($path) > strlen($root)
            && (DIRECTORY_SEPARATOR === '\\' ? strcasecmp($head, $root) === 0 : $head === $root);
    }

    /**
     * The key => [label, absolute expected path] map actually in play: the fixed whitelist plus
     * PHP's own error_log setting when that is an absolute path inside the site root.
     * $iniPath is the value of ini_get('error_log') captured by the caller (null = read it now).
     */
    function rh_errorlog_candidates(?string $rootOverride = null, ?string $iniPath = null): array
    {
        $root = rh_errorlog_root($rootOverride);
        if ($root === null) {
            return [];
        }
        $out = [];
        foreach (rh_errorlog_whitelist() as $key => [$label, $rel]) {
            $out[$key] = ['label' => $label, 'rel' => $rel, 'expected' => $root . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $rel)];
        }

        $ini = trim((string)($iniPath ?? ini_get('error_log')));
        if ($ini !== '' && strtolower($ini) !== 'syslog' && preg_match('#^([a-zA-Z]:[\\\\/]|/)#', $ini) && strpos($ini, "\0") === false) {
            $real = realpath($ini);
            // Only a log-named file (error_log, *.log): a misconfigured ini path must never let this
            // clear an ordinary site file.
            $base = strtolower(basename((string)$real));
            if ($real !== false && rh_errorlog_inside($real, $root) && ($base === 'error_log' || substr($base, -4) === '.log')) {
                $dupe = false;
                foreach ($out as $c) {
                    if (rh_errorlog_same_path($c['expected'], $real)) {
                        $dupe = true;
                        break;
                    }
                }
                if (!$dupe) {
                    $rel = ltrim(substr(str_replace('\\', '/', $real), strlen(str_replace('\\', '/', $root))), '/');
                    $out['php_ini'] = ['label' => 'PHP configured log', 'rel' => $rel, 'expected' => $real];
                }
            }
        }
        return $out;
    }

    /**
     * Resolve a whitelist key to a safe real path, or null (unknown key, missing, not a regular
     * file, a symlink, or resolving anywhere other than its expected place inside the site root).
     */
    function rh_errorlog_resolve($key, ?string $rootOverride = null, ?string $iniPath = null): ?array
    {
        if (!is_string($key) || $key === '') {
            return null;
        }
        $root = rh_errorlog_root($rootOverride);
        $cands = rh_errorlog_candidates($rootOverride, $iniPath);
        if ($root === null || !isset($cands[$key])) {
            return null;
        }
        $c = $cands[$key];
        if (is_link($c['expected'])) {
            return null;
        }
        $real = realpath($c['expected']);
        if ($real === false || !is_file($real) || !rh_errorlog_same_path($real, $c['expected']) || !rh_errorlog_inside($real, $root)) {
            return null;
        }
        return ['key' => $key, 'label' => $c['label'], 'rel' => $c['rel'], 'path' => $real];
    }

    /** Existing, safe log files: key, label, relative path, size (bytes), modified (timestamp). */
    function rh_errorlog_list(?string $rootOverride = null, ?string $iniPath = null): array
    {
        $rows = [];
        foreach (array_keys(rh_errorlog_candidates($rootOverride, $iniPath)) as $key) {
            $r = rh_errorlog_resolve($key, $rootOverride, $iniPath);
            if ($r === null) {
                continue;
            }
            clearstatcache(true, $r['path']);
            $rows[] = ['key' => $key, 'label' => $r['label'], 'rel' => $r['rel'],
                       'size' => (int)@filesize($r['path']), 'modified' => (int)@filemtime($r['path'])];
        }
        return $rows;
    }

    /** Only a signed-in, active admin may clear logs (the page's "cache" permission can be given to others). */
    function rh_errorlog_user_is_admin(): bool
    {
        global $pdo;
        $uid = (int)($_SESSION['admin_user_id'] ?? 0);
        if ($uid <= 0 || !($pdo instanceof PDO)) {
            return false;
        }
        try {
            $st = $pdo->prepare('SELECT role, is_active FROM admin_users WHERE id = ?');
            $st->execute([$uid]);
            $u = $st->fetch(PDO::FETCH_ASSOC);
            return $u && (int)$u['is_active'] === 1 && $u['role'] === 'admin';
        } catch (Throwable $e) {
            return false;
        }
    }

    /**
     * Empty one whitelisted log (truncate to 0 bytes; the file stays).
     * Returns ['ok' => bool, 'rel' => string, 'size_before' => int, 'error' => string].
     */
    function rh_errorlog_clear($key, ?string $rootOverride = null, ?string $iniPath = null): array
    {
        $r = rh_errorlog_resolve($key, $rootOverride, $iniPath);
        if ($r === null) {
            return ['ok' => false, 'rel' => '', 'size_before' => 0, 'error' => 'That log file is not available to clear.'];
        }
        clearstatcache(true, $r['path']);
        $before = (int)@filesize($r['path']);
        $fh = @fopen($r['path'], 'r+b'); // never creates, never follows to another name
        if (!$fh) {
            return ['ok' => false, 'rel' => $r['rel'], 'size_before' => $before, 'error' => 'The server would not let us empty ' . $r['rel'] . ' (file permissions).'];
        }
        $ok = @ftruncate($fh, 0);
        fclose($fh);
        clearstatcache(true, $r['path']);
        if (!$ok || (int)@filesize($r['path']) !== 0) {
            return ['ok' => false, 'rel' => $r['rel'], 'size_before' => $before, 'error' => 'Could not empty ' . $r['rel'] . '.'];
        }
        return ['ok' => true, 'rel' => $r['rel'], 'size_before' => $before, 'error' => ''];
    }

    /** Record a clear in the system event log and the admin activity log (who, which file, size before). */
    function rh_errorlog_record(string $key, array $result): void
    {
        $uid = (int)($_SESSION['admin_user_id'] ?? 0);
        $who = (string)($_SESSION['admin_username'] ?? ('user ' . $uid));
        $detail = 'Error log ' . ($result['rel'] ?: $key) . ': ' . ($result['ok'] ? 'cleared' : 'clear failed') . ' (size before ' . (int)$result['size_before'] . ' bytes) by ' . $who;
        if (function_exists('rh_log_event')) {
            rh_log_event('error_log_clear', $result['ok'] ? 'warning' : 'error', $result['ok'] ? 'Error log cleared' : 'Error log clear failed',
                ['file' => $result['rel'], 'key' => $key, 'size_before_bytes' => (int)$result['size_before'], 'cleared_by' => $who]);
        }
        if ($uid > 0 && function_exists('logActivity')) {
            logActivity($uid, 'error_log_cleared', $detail);
        }
    }
}
