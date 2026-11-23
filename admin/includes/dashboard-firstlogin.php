<?php
/**
 * Dashboard first-login wording (plan .claude/DASHBOARD_FIRSTLOGIN_PLAN.md, tasks A2-A4). Pure: no database, no
 * settings, no session. The readers that feed it are in admin/includes/dashboard-since-data.php.
 * Tested by scripts/tests/dashboard_firstlogin.php.
 */

require_once __DIR__ . '/login-bundles.php'; // rh_login_browser_label (pure)

if (!function_exists('rh_dash_device_words')) {
    /** "Chrome on Windows" / "Chrome" / "Unknown browser on Android" / "Unknown browser" (never blank). */
    function rh_dash_device_words(string $ua): string
    {
        $label = rh_login_browser_label($ua); // "Chrome / Windows", "Unknown", "Unknown / Linux"
        $parts = explode(' / ', $label, 2);
        $browser = ($parts[0] === '' || $parts[0] === 'Unknown') ? 'Unknown browser' : $parts[0];
        return isset($parts[1]) && $parts[1] !== '' ? $browser . ' on ' . $parts[1] : $browser;
    }
}

if (!function_exists('rh_dash_last_signin_text')) {
    /**
     * $prev: rh_dash_prev_signin() result (['at' => 'Y-m-d H:i:s', 'ua' => user agent]); null or no 'at' = no earlier
     * sign-in. $today: 'Y-m-d'. -> "Last signed in Mon 23 Nov at 18:40, Chrome on Windows." ("today" / "yesterday"
     * for those days, the year added when it is not this year) or "First sign-in on this account."
     */
    function rh_dash_last_signin_text(?array $prev, string $today): string
    {
        $at = $prev['at'] ?? null;
        $ts = is_string($at) && $at !== '' ? strtotime($at) : false;
        $todayTs = strtotime($today . ' 00:00:00');
        if ($ts === false || $todayTs === false) {
            return 'First sign-in on this account.';
        }
        $day = date('Y-m-d', $ts);
        if ($day === $today) {
            $when = 'today';
        } elseif ($day === date('Y-m-d', strtotime('-1 day', $todayTs))) {
            $when = 'yesterday';
        } elseif (date('Y', $ts) === date('Y', $todayTs)) {
            $when = date('D j M', $ts);
        } else {
            $when = date('D j M Y', $ts);
        }
        return 'Last signed in ' . $when . ' at ' . date('H:i', $ts) . ', ' . rh_dash_device_words((string)($prev['ua'] ?? '')) . '.';
    }
}

if (!function_exists('rh_dash_eod_chip')) {
    /**
     * The day status chip next to the date. $status: rh_dash_eod_status() result (day, sent_at, test).
     * $today: 'Y-m-d'. -> ['state' => 'ok'|'warn', 'text' => plain text, 'href' => ?string]
     * Sent: "Yesterday closed · End of Day sent 07:12" (+ " (test)"). Not sent: "Yesterday's End of Day not run",
     * linking to that day's End of Day report. A day other than yesterday is named ("Sat 21 Nov closed").
     */
    function rh_dash_eod_chip(array $status, string $today): array
    {
        $day = (string)($status['day'] ?? '');
        $dayTs = strtotime($day . ' 00:00:00');
        $todayTs = strtotime($today . ' 00:00:00');
        if ($dayTs === false || $todayTs === false) {
            $name = 'Last trading day';
        } elseif ($day === date('Y-m-d', strtotime('-1 day', $todayTs))) {
            $name = 'Yesterday';
        } elseif ($day === $today) {
            $name = 'Today';
        } else {
            $name = date('D j M', $dayTs);
        }
        $sentAt = $status['sent_at'] ?? null;
        $sentTs = is_string($sentAt) && $sentAt !== '' ? strtotime($sentAt) : false;
        if ($sentTs !== false) {
            return [
                'state' => 'ok',
                'text' => $name . ' closed · End of Day sent ' . date('H:i', $sentTs) . (!empty($status['test']) ? ' (test)' : ''),
                'href' => null,
            ];
        }
        return [
            'state' => 'warn',
            'text' => $name . "'s End of Day not run",
            'href' => $dayTs !== false ? 'end-of-day-report.php?date=' . rawurlencode($day) : 'end-of-day-report.php',
        ];
    }
}
