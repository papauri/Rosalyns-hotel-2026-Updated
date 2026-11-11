<?php
/**
 * System Logs > Recent Logins: sign-in records (admin_activity_log, action login_*) bundled into one row per person,
 * device and session, so repeated retries do not flood the table. Pure: no database, no settings. Moved out of
 * admin/system-logs.php (2026-11-12) so the bundling and its paging are tested by scripts/tests/log_pager_test.php.
 */

if (!function_exists('rh_login_bundles')) {

    function rh_login_browser_label(string $ua): string
    {
        $browserLabel = 'Unknown';
        if (stripos($ua, 'Edg/') !== false) {
            $browserLabel = 'Edge';
        } elseif (stripos($ua, 'OPR/') !== false) {
            $browserLabel = 'Opera';
        } elseif (stripos($ua, 'Chrome/') !== false) {
            $browserLabel = 'Chrome';
        } elseif (stripos($ua, 'Firefox/') !== false) {
            $browserLabel = 'Firefox';
        } elseif (stripos($ua, 'Safari/') !== false) {
            $browserLabel = 'Safari';
        }

        if (stripos($ua, 'Windows') !== false) {
            $browserLabel .= ' / Windows';
        } elseif (stripos($ua, 'Macintosh') !== false) {
            $browserLabel .= ' / macOS';
        } elseif (stripos($ua, 'Linux') !== false) {
            $browserLabel .= ' / Linux';
        } elseif (stripos($ua, 'Android') !== false) {
            $browserLabel .= ' / Android';
        } elseif (stripos($ua, 'iPhone') !== false || stripos($ua, 'iPad') !== false) {
            $browserLabel .= ' / iOS';
        }

        return $browserLabel;
    }

    /**
     * $rows: sign-in records newest first (user_id, username, full_name, role, action, details, ip_address,
     * user_agent, created_at). Consecutive records with the same session (sid: in details, else username + IP +
     * browser) within $windowSeconds join one bundle. Returns the bundles newest first, each with its counts,
     * attempts, attempt_count, result_class and result_label.
     */
    function rh_login_bundles(array $rows, int $windowSeconds = 2700): array
    {
        $bundles = [];
        // Bundle consecutive login events from the same source (IP + session-like signature)
        // into one expandable row so repeated retries don't flood the table.
        foreach ($rows as $loginRow) {
            $timestamp = strtotime((string)($loginRow['created_at'] ?? '')) ?: 0;
            $usernameNorm = strtolower(trim((string)($loginRow['username'] ?? '')));
            $ipNorm = trim((string)($loginRow['ip_address'] ?? ''));
            $userAgent = (string)($loginRow['user_agent'] ?? '');
            $detailsText = (string)($loginRow['details'] ?? '');

            $sessionKey = $usernameNorm . '|' . $ipNorm . '|' . sha1($userAgent);
            if (preg_match('/sid:([a-f0-9]{8,64})/i', $detailsText, $sidMatch)) {
                $sessionKey = 'sid:' . strtolower((string)$sidMatch[1]);
            }

            $lastIndex = count($bundles) - 1;
            $canMerge = false;
            if ($lastIndex >= 0) {
                $lastBundle = $bundles[$lastIndex];
                $gapSeconds = $lastBundle['latest_ts'] - $timestamp;
                $canMerge = $lastBundle['session_key'] === $sessionKey
                    && $gapSeconds >= 0
                    && $gapSeconds <= $windowSeconds;
            }

            if (!$canMerge) {
                $bundles[] = [
                    'session_key' => $sessionKey,
                    'latest_ts' => $timestamp,
                    'latest_at' => (string)($loginRow['created_at'] ?? ''),
                    'oldest_at' => (string)($loginRow['created_at'] ?? ''),
                    'username' => (string)($loginRow['username'] ?? ''),
                    'full_name' => (string)($loginRow['full_name'] ?? ''),
                    'role' => (string)($loginRow['role'] ?? ''),
                    'ip_address' => (string)($loginRow['ip_address'] ?? ''),
                    'browser_label' => rh_login_browser_label($userAgent),
                    'success_count' => 0,
                    'failed_count' => 0,
                    'blocked_count' => 0,
                    'attempts' => [],
                ];
                $lastIndex = count($bundles) - 1;
            }

            $attemptAction = (string)($loginRow['action'] ?? '');
            if ($attemptAction === 'login_success') {
                $bundles[$lastIndex]['success_count']++;
            } elseif ($attemptAction === 'login_blocked') {
                $bundles[$lastIndex]['blocked_count']++;
            } else {
                $bundles[$lastIndex]['failed_count']++;
            }

            $bundles[$lastIndex]['oldest_at'] = (string)($loginRow['created_at'] ?? $bundles[$lastIndex]['oldest_at']);
            $bundles[$lastIndex]['attempts'][] = [
                'created_at' => (string)($loginRow['created_at'] ?? ''),
                'action' => $attemptAction,
                'details' => $detailsText,
            ];
        }
        foreach ($bundles as &$bundle) {
            $bundle['attempt_count'] = count($bundle['attempts']);
            $typeCount = 0;
            if ($bundle['success_count'] > 0) $typeCount++;
            if ($bundle['failed_count'] > 0) $typeCount++;
            if ($bundle['blocked_count'] > 0) $typeCount++;

            if ($typeCount > 1) {
                $bundle['result_class'] = 'warning';
                $bundle['result_label'] = 'Mixed';
            } elseif ($bundle['success_count'] > 0) {
                $bundle['result_class'] = 'success';
                $bundle['result_label'] = 'Success';
            } elseif ($bundle['blocked_count'] > 0) {
                $bundle['result_class'] = 'warning';
                $bundle['result_label'] = 'Blocked';
            } else {
                $bundle['result_class'] = 'danger';
                $bundle['result_label'] = 'Failed';
            }
        }
        unset($bundle);
        return $bundles;
    }
}
