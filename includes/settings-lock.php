<?php

/**
 * High-value settings lock (owner 2026-11-25): VAT, bank account details, tourism levy and the restaurant service
 * charge are greyed out on screen and need the signed-in person's OWN password again before they can be changed.
 *
 *   Unlock  admin/api/settings-unlock.php (POST + admin CSRF + finance_settings + own password) calls
 *           rh_settings_lock_unlock(): stores a session token for that one group, valid RH_SETTINGS_LOCK_TTL seconds.
 *   Save    every writer of a locked setting calls rh_settings_lock_is_open() BEFORE it reads any input and refuses
 *           the whole save without a token; after a save that passed validation it calls rh_settings_lock_use(),
 *           so the page comes back locked.
 *   Limit   RH_SETTINGS_LOCK_MAX_FAILS wrong passwords per session in RH_SETTINGS_LOCK_WINDOW seconds, then refused.
 *
 * Session only: no table, no schema change. Passwords are never stored or logged. $now is for tests.
 */

if (!function_exists('rh_settings_lock_groups')) {

    if (!defined('RH_SETTINGS_LOCK_TTL')) {
        define('RH_SETTINGS_LOCK_TTL', 600);          // 10 minutes
    }
    if (!defined('RH_SETTINGS_LOCK_MAX_FAILS')) {
        define('RH_SETTINGS_LOCK_MAX_FAILS', 5);
    }
    if (!defined('RH_SETTINGS_LOCK_WINDOW')) {
        define('RH_SETTINGS_LOCK_WINDOW', 900);       // 15 minutes
    }

    /** group => plain name used in messages ("Unlock the <name> with your password first."). */
    function rh_settings_lock_groups(): array
    {
        return [
            'vat'            => 'VAT settings',
            'bank'           => 'bank account details',
            'levy'           => 'tourism levy',
            'service_charge' => 'restaurant service charge',
        ];
    }

    function rh_settings_lock_valid_group(string $group): bool
    {
        return isset(rh_settings_lock_groups()[$group]);
    }

    /** The plain refusal a save shows when its group is still locked. */
    function rh_settings_lock_refusal(string $group): string
    {
        $name = rh_settings_lock_groups()[$group] ?? 'these settings';
        return 'Nothing was saved. Unlock the ' . $name . ' with your password first (the "Change (password needed)" button), then save within 10 minutes.';
    }

    /** Seconds left on this user's token for the group (0 = locked). */
    function rh_settings_lock_seconds_left(string $group, int $userId, ?int $now = null): int
    {
        $now = $now ?? time();
        $t = $_SESSION['rh_settings_lock'][$group] ?? null;
        if (!is_array($t) || (int)($t['uid'] ?? 0) !== $userId || $userId <= 0) {
            return 0;
        }
        $left = (int)($t['exp'] ?? 0) - $now;
        return $left > 0 ? $left : 0;
    }

    function rh_settings_lock_is_open(string $group, int $userId, ?int $now = null): bool
    {
        return rh_settings_lock_valid_group($group) && rh_settings_lock_seconds_left($group, $userId, $now) > 0;
    }

    /** Use the token up (a save went through), so the fields are locked again. */
    function rh_settings_lock_use(string $group): void
    {
        if (isset($_SESSION['rh_settings_lock'][$group])) {
            unset($_SESSION['rh_settings_lock'][$group]);
        }
    }

    /** Wrong passwords still inside the window (old ones dropped). */
    function rh_settings_lock_recent_fails(?int $now = null): array
    {
        $now = $now ?? time();
        $fails = $_SESSION['rh_settings_lock_fails'] ?? [];
        if (!is_array($fails)) {
            $fails = [];
        }
        return array_values(array_filter($fails, static fn($ts): bool => is_int($ts) && $ts > $now - RH_SETTINGS_LOCK_WINDOW));
    }

    /**
     * Check the password and open one group. Never logs or keeps the password.
     * @return array{0:bool,1:string,2:int} [ok, plain message, seconds the unlock lasts]
     */
    function rh_settings_lock_unlock(
        PDO $pdo,
        int $userId,
        string $group,
        #[\SensitiveParameter]
        string $password,
        ?int $now = null
    ): array
    {
        $now = $now ?? time();
        $log = static function (string $level, string $msg, array $ctx) use ($userId, $group): void {
            if (function_exists('rh_log_event')) {
                rh_log_event('admin/settings-lock', $level, $msg, ['user_id' => $userId, 'group' => $group] + $ctx);
            }
        };
        if (!rh_settings_lock_valid_group($group)) {
            return [false, 'Unknown settings group.', 0];
        }
        if ($userId <= 0 || !function_exists('hasPermission') || !hasPermission($userId, 'finance_settings')) {
            $log('warning', 'Settings unlock refused: no permission', []);
            return [false, 'You do not have permission to change these settings.', 0];
        }
        $fails = rh_settings_lock_recent_fails($now);
        if (count($fails) >= RH_SETTINGS_LOCK_MAX_FAILS) {
            $wait = max(1, (int)ceil((min($fails) + RH_SETTINGS_LOCK_WINDOW - $now) / 60));
            $_SESSION['rh_settings_lock_fails'] = $fails;
            $log('warning', 'Settings unlock refused: too many wrong passwords', ['fails' => count($fails)]);
            return [false, 'Too many wrong passwords. Try again in ' . $wait . ' minute' . ($wait === 1 ? '' : 's') . '.', 0];
        }
        $hash = false;
        if ($password !== '') {
            $st = $pdo->prepare("SELECT password_hash FROM admin_users WHERE id = ? AND is_active = 1");
            $st->execute([$userId]);
            $hash = $st->fetchColumn();
        }
        if (!is_string($hash) || $hash === '' || !password_verify($password, $hash)) {
            $fails[] = $now;
            $_SESSION['rh_settings_lock_fails'] = $fails;
            $log('warning', 'Settings unlock failed: wrong password', ['fails' => count($fails)]);
            $left = RH_SETTINGS_LOCK_MAX_FAILS - count($fails);
            return [false, $password === '' ? 'Enter your own password.' : 'That password is not correct.' . ($left > 0 && $left <= 2 ? ' ' . $left . ' more tr' . ($left === 1 ? 'y' : 'ies') . ' before it stops for 15 minutes.' : ''), 0];
        }
        $_SESSION['rh_settings_lock_fails'] = [];
        if (!isset($_SESSION['rh_settings_lock']) || !is_array($_SESSION['rh_settings_lock'])) {
            $_SESSION['rh_settings_lock'] = [];
        }
        $_SESSION['rh_settings_lock'][$group] = ['uid' => $userId, 'exp' => $now + RH_SETTINGS_LOCK_TTL];
        $log('info', 'Settings unlocked for editing', ['minutes' => (int)(RH_SETTINGS_LOCK_TTL / 60)]);
        return [true, 'Unlocked for 10 minutes. Save to lock again.', RH_SETTINGS_LOCK_TTL];
    }

    /**
     * Markup for the lock bar above a locked block: the "Change (password needed)" button for users who may
     * change it, or the unlocked line when a token is already open (e.g. after a save that failed validation).
     */
    function rh_settings_lock_bar(string $group, string $fieldsetId, bool $canChange, int $userId): string
    {
        if (!$canChange) {
            return '';
        }
        $e = static fn($v): string => htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8');
        $open = rh_settings_lock_is_open($group, $userId);
        $name = rh_settings_lock_groups()[$group] ?? '';
        return '<div class="rh-lockbar" data-lock-bar="' . $e($group) . '">'
            . '<button type="button" class="btn btn-outline rh-lockbar__btn" data-settings-unlock="' . $e($group) . '" data-settings-unlock-name="' . $e($name) . '" aria-controls="' . $e($fieldsetId) . '"' . ($open ? ' hidden' : '') . '>'
            . '<i class="fas fa-lock" aria-hidden="true"></i> Change (password needed)</button>'
            . '<p class="rh-lockbar__state" data-lock-state role="status" aria-live="polite">'
            . ($open ? '<i class="fas fa-lock-open" aria-hidden="true"></i> Unlocked for 10 minutes. Save to lock again.' : '<i class="fas fa-lock" aria-hidden="true"></i> Locked to avoid mistakes. You need your password to change the ' . $e($name) . '.')
            . '</p></div>';
    }
}
