<?php

/**
 * Staff managed from the licence panel (owner 2026-11-10). The panel never logs into this server: changes reach the HMS only
 * inside the panel's SIGNED licence answer (includes/licence.php verifies the signature, the tenant, the domain and that the
 * answer is not older than one already accepted). This file then:
 *
 *   1. runs each staff change in that answer whose id is higher than the last one run (licence_staff_done_id), oldest first,
 *      only straight after a check-in that succeeded and only from an answer issued in the last 10 minutes (an older stored
 *      answer never replays anything, and a change cancelled on the panel meanwhile never runs);
 *   2. reports back to the panel (api/hms-staff.php, next to LICENCE_URL): what happened to each change, and the staff list
 *      (id, username, email, name, role, active, last sign-in, each person's own permission changes) with this system's roles
 *      and permissions. NEVER a password, a password hash or any guest / booking / money data.
 *
 * Changes (owner decisions 2026-11-10): deactivate, activate, role, permissions (replaces the person's own changes), create
 * (the account gets no usable password: this system emails its own invitation link), invite. The hotel's own administrator
 * can still undo any of them on the Users page. The last active administrator can never be deactivated or demoted.
 * Every change is written to the activity log as "by the system provider (licence panel)".
 *
 * Settings rows used (site_settings, no new tables): licence_staff_done_id, licence_staff_results (JSON, results not yet
 * confirmed by the panel), licence_staff_sent_hash / licence_staff_sent_at (last report), licence_staff_list_seen,
 * licence_staff_error (why the last run did not finish, '' when it did; sent with the next check-in so the panel can show it).
 *
 * Nothing here throws to the caller, and nothing here can affect the licence status itself.
 */

require_once __DIR__ . '/licence.php';

if (!function_exists('rh_licence_staff_run')) {

    define('RH_LICENCE_STAFF_KINDS', ['deactivate', 'activate', 'role', 'permissions', 'create', 'invite']);
    define('RH_LICENCE_STAFF_FRESH_SECONDS', 600);
    define('RH_LICENCE_STAFF_REPORT_EVERY', 86400);

    // ------------------------------------------------------------------ pure

    /** The staff changes in a VERIFIED payload, cleaned and sorted by id. Anything malformed is dropped. */
    function rh_licence_staff_commands(?array $payload): array
    {
        $out = [];
        $list = is_array($payload) && isset($payload['staff_commands']) && is_array($payload['staff_commands']) ? $payload['staff_commands'] : [];
        foreach (array_slice($list, 0, 50) as $c) {
            if (!is_array($c) || !is_int($c['id'] ?? null) || $c['id'] <= 0 || !in_array($c['kind'] ?? null, RH_LICENCE_STAFF_KINDS, true)
                || !is_string($c['username'] ?? null) || trim($c['username']) === '' || strlen($c['username']) > 100) {
                continue;
            }
            $out[$c['id']] = ['id' => $c['id'], 'kind' => $c['kind'], 'username' => trim($c['username']),
                'user_id' => is_int($c['user_id'] ?? null) ? max(0, $c['user_id']) : 0, 'data' => is_array($c['data'] ?? null) ? $c['data'] : []];
        }
        ksort($out);
        return array_values($out);
    }

    /** The changes still to run: newer than the last one run, and only from a fresh answer (10 minutes). */
    function rh_licence_staff_todo(array $commands, int $doneId, int $issuedAt, int $now): array
    {
        if ($issuedAt <= 0 || $now - $issuedAt > RH_LICENCE_STAFF_FRESH_SECONDS) {
            return [];
        }
        return array_values(array_filter($commands, static fn($c) => $c['id'] > $doneId));
    }

    /** Should the staff report go now? After changes, when results wait, when asked, when the list changed, or once a day. */
    function rh_licence_staff_report_due(bool $ran, bool $resultsWaiting, int $askedAt, int $seenAsk, string $hash, string $sentHash, int $sentAt, int $now): bool
    {
        return $ran || $resultsWaiting || $askedAt > $seenAsk || $hash !== $sentHash || $now - $sentAt >= RH_LICENCE_STAFF_REPORT_EVERY;
    }

    /** api/hms-staff.php next to the configured licence address, or '' when the address is not the usual .../licence.php. */
    function rh_licence_staff_report_url(string $licenceUrl): string
    {
        return preg_match('#^(https?://[^?\#]+/)licence\.php$#i', trim($licenceUrl), $m) ? $m[1] . 'hms-staff.php' : '';
    }

    /** Results kept until the panel confirms them (newest 50). */
    function rh_licence_staff_merge_results(array $kept, array $new, array $acked = []): array
    {
        $by = [];
        foreach (array_merge($kept, $new) as $r) {
            if (is_array($r) && is_int($r['id'] ?? null) && !in_array($r['id'], $acked, true)) {
                $by[$r['id']] = ['id' => $r['id'], 'ok' => (bool)($r['ok'] ?? false), 'message' => mb_substr((string)($r['message'] ?? ''), 0, 255)];
            }
        }
        ksort($by);
        return array_slice(array_values($by), -50);
    }

    // ------------------------------------------------------------------ storage (site_settings rows)

    function rh_licence_staff_setting_keys(): array
    {
        return ['licence_staff_done_id', 'licence_staff_results', 'licence_staff_sent_hash', 'licence_staff_sent_at', 'licence_staff_list_seen',
            'licence_staff_error'];
    }

    function rh_licence_staff_set(PDO $pdo, string $key, string $value): void
    {
        if (!in_array($key, rh_licence_staff_setting_keys(), true)) {
            return;
        }
        $pdo->prepare('INSERT INTO site_settings (setting_key, setting_value) VALUES (?, ?)
                       ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value), updated_at = CURRENT_TIMESTAMP')->execute([$key, $value]);
        if (isset($GLOBALS['_SITE_SETTINGS'][$key])) {
            unset($GLOBALS['_SITE_SETTINGS'][$key]);
        }
        if (function_exists('deleteCache')) {
            deleteCache("setting_{$key}");
        }
    }

    // ------------------------------------------------------------------ running one change

    function rh_licence_staff_log(string $what): void
    {
        $text = 'By the system provider (licence panel): ' . $what;
        if (function_exists('logActivity')) {
            logActivity(0, 'provider_staff_change', $text); // user 0 = not a person in this system
        }
        if (function_exists('rh_log_event')) {
            rh_log_event('licence', 'info', $text);
        }
    }

    function rh_licence_staff_active_admins(PDO $pdo): int
    {
        return (int)$pdo->query("SELECT COUNT(*) FROM admin_users WHERE role = 'admin' AND is_active = 1")->fetchColumn();
    }

    /** @return array{ok:bool,message:string} */
    function rh_licence_staff_apply(PDO $pdo, array $c): array
    {
        $fail = static fn(string $m): array => ['ok' => false, 'message' => $m];
        $roles = getAllRoles();
        if ($c['kind'] === 'create') {
            return rh_licence_staff_create($pdo, $c);
        }
        $st = $pdo->prepare('SELECT id, username, email, full_name, role, is_active FROM admin_users WHERE username = ?');
        $st->execute([$c['username']]);
        $u = $st->fetch(PDO::FETCH_ASSOC);
        if (!$u) {
            return $fail('There is no user "' . $c['username'] . '" in this system any more.');
        }
        $uid = (int)$u['id'];
        if ($c['user_id'] > 0 && $c['user_id'] !== $uid) {
            return $fail('The user "' . $c['username'] . '" is a different account now; nothing was changed.');
        }
        $isLastAdmin = $u['role'] === 'admin' && (int)$u['is_active'] === 1 && rh_licence_staff_active_admins($pdo) <= 1;
        // A super admin (owner 2026-11-11) is changed only by another super admin on the Users page, never from the panel.
        $isSuper = function_exists('rh_is_super_admin_account') && rh_is_super_admin_account($uid);
        if ($isSuper) { // no change of any kind (deactivate, activate, role, permissions, invite) from the panel
            return $fail('This is a super admin; only another super admin can change them, on the Users page.');
        }
        switch ($c['kind']) {
            case 'deactivate':
                if ((int)$u['is_active'] !== 1) {
                    return ['ok' => true, 'message' => 'Was already deactivated.'];
                }
                if ($isLastAdmin) {
                    return $fail('This is the last active administrator.');
                }
                $pdo->prepare('UPDATE admin_users SET is_active = 0 WHERE id = ?')->execute([$uid]);
                rh_licence_staff_log("deactivated {$u['username']}");
                return ['ok' => true, 'message' => ''];
            case 'activate':
                if ((int)$u['is_active'] === 1) {
                    return ['ok' => true, 'message' => 'Was already active.'];
                }
                $pdo->prepare('UPDATE admin_users SET is_active = 1 WHERE id = ?')->execute([$uid]);
                try {
                    $pdo->prepare('UPDATE admin_users SET failed_login_attempts = 0 WHERE id = ?')->execute([$uid]);
                } catch (Throwable $e) { /* older copies have no such column */ }
                rh_licence_staff_log("reactivated {$u['username']}");
                return ['ok' => true, 'message' => ''];
            case 'role':
                $role = (string)($c['data']['role'] ?? '');
                if (!isset($roles[$role])) {
                    return $fail('This system has no role "' . $role . '".');
                }
                if ($role === $u['role']) {
                    return ['ok' => true, 'message' => 'Already had that role.'];
                }
                if ($u['role'] === 'admin' && function_exists('rh_admin_is_limited') && rh_admin_is_limited($uid)) {
                    return $fail('A super admin has switched off some permissions of this administrator, so only a super admin can change their role.');
                }
                if ($isLastAdmin && $role !== 'admin') {
                    return $fail('This is the last active administrator.');
                }
                $pdo->prepare('UPDATE admin_users SET role = ? WHERE id = ?')->execute([$role, $uid]);
                if ($role !== 'admin') {
                    resetUserPermissionsToDefault($uid, null); // same as the Users page: a new role starts from its usual set
                } elseif (function_exists('rh_clear_user_permission_rows')) {
                    rh_clear_user_permission_rows($uid); // a new administrator carries nothing over from the old role
                }
                rh_licence_staff_log("changed {$u['username']} from {$u['role']} to {$role}");
                return ['ok' => true, 'message' => ''];
            case 'permissions':
                if ($u['role'] === 'admin') {
                    return $fail('Administrators have every permission.');
                }
                if (isset($c['data']['role']) && (string)$c['data']['role'] !== (string)$u['role']) {
                    return $fail('Their role changed here before this arrived; open them again on the panel and set the permissions again.');
                }
                $all = getAllPermissions();
                $over = is_array($c['data']['overrides'] ?? null) ? $c['data']['overrides'] : [];
                $rows = [];
                foreach ($over as $k => $v) {
                    if (is_string($k) && isset($all[$k]) && $k !== 'user_management' && ($v === 0 || $v === 1 || is_bool($v))) {
                        // Owner 2026-11-11: administrators-only keys are never stored as granted below administrator.
                        $adminOnlyKey = function_exists('rh_admin_only_permissions') && in_array($k, rh_admin_only_permissions(), true);
                        $rows[$k] = ($v && !$adminOnlyKey) ? 1 : 0;
                    }
                }
                $pdo->beginTransaction();
                try {
                    $pdo->prepare('DELETE FROM user_permissions WHERE user_id = ?')->execute([$uid]);
                    $ins = $pdo->prepare('INSERT INTO user_permissions (user_id, permission_key, is_granted, granted_by) VALUES (?, ?, ?, NULL)');
                    foreach ($rows as $k => $v) {
                        $ins->execute([$uid, $k, $v]);
                    }
                    $pdo->commit();
                } catch (Throwable $e) {
                    $pdo->rollBack();
                    throw $e;
                }
                $added = count(array_filter($rows));
                rh_licence_staff_log('set the permissions of ' . $u['username'] . ' (' . $added . ' added to and '
                    . (count($rows) - $added) . ' removed from the ' . $u['role'] . ' set)');
                return ['ok' => true, 'message' => ''];
            case 'invite':
                $r = rh_licence_staff_invite($pdo, $uid);
                if (empty($r['success'])) {
                    return $fail('The invitation email could not be sent: ' . mb_substr((string)($r['message'] ?? ''), 0, 180));
                }
                rh_licence_staff_log("sent an invitation to {$u['username']}");
                return ['ok' => true, 'message' => 'Invitation emailed.'];
        }
        return $fail('Unknown change.');
    }

    /**
     * This system's own invitation email, with the link on the site's own address (site_url), never the request's Host
     * header: this runs in the panel's unauthenticated nudge. @return array{success:bool,message:string}
     */
    function rh_licence_staff_invite(PDO $pdo, int $uid): array
    {
        if (!function_exists('rh_staff_invite_send')) {
            require_once dirname(__DIR__) . '/admin/includes/staff-invite-lib.php';
        }
        $base = trim((string)(function_exists('getSetting') ? getSetting('site_url', '') : ''));
        if ($base !== '' && !preg_match('#^https?://#i', $base)) {
            $base = 'https://' . $base;
        }
        if ($base === '' || !filter_var($base, FILTER_VALIDATE_URL)) {
            return ['success' => false, 'message' => 'The site address (site_url setting) is not set, so no invitation link can be made.'];
        }
        $GLOBALS['rh_staff_invite_base_url'] = $base;
        try {
            return rh_staff_invite_send($pdo, $uid, 'Your system provider');
        } finally {
            unset($GLOBALS['rh_staff_invite_base_url']);
        }
    }

    /** A new account with no usable password, then this system's own invitation email. @return array{ok:bool,message:string} */
    function rh_licence_staff_create(PDO $pdo, array $c): array
    {
        if (!function_exists('rh_with_create_lock')) {
            require_once dirname(__DIR__) . '/includes/form-validation.php';
        }
        // Cleaned exactly as the Users page cleans what an administrator types.
        $username = rh_clean_text($c['username']);
        $email = strtolower(rh_clean_text($c['data']['email'] ?? ''));
        $name = rh_clean_text($c['data']['full_name'] ?? '');
        $role = (string)($c['data']['role'] ?? '');
        if (strlen($username) < 3 || strlen($username) > 100 || preg_match('/\s/', $username)) {
            return ['ok' => false, 'message' => 'The username needs 3 to 100 characters and no spaces.'];
        }
        if ($name === '' || strlen($name) > 255 || !filter_var($email, FILTER_VALIDATE_EMAIL) || strlen($email) > 255) {
            return ['ok' => false, 'message' => 'A full name and a valid email are required.'];
        }
        if (!isset(getAllRoles()[$role])) {
            return ['ok' => false, 'message' => 'This system has no role "' . $role . '".'];
        }
        if (!function_exists('rh_staff_invite_send')) {
            require_once dirname(__DIR__) . '/admin/includes/staff-invite-lib.php';
        }
        $dup = '';
        $newId = 0;
        rh_with_create_lock($pdo, 'admin_user', static function () use ($pdo, $username, $email, $name, $role, &$dup, &$newId) {
            if (rh_find_duplicate($pdo, 'admin_users', 'username', $username)) {
                $dup = 'The username "' . $username . '" is already taken in this system.';
                return;
            }
            $st = $pdo->prepare('SELECT 1 FROM admin_users WHERE LOWER(TRIM(email)) = ? LIMIT 1');
            $st->execute([$email]);
            if ($st->fetchColumn()) {
                $dup = 'Another staff member already uses this email in this system.';
                return;
            }
            $pdo->prepare('INSERT INTO admin_users (username, email, password_hash, full_name, role, created_at) VALUES (?, ?, ?, ?, ?, NOW())')
                ->execute([$username, $email, rh_staff_invite_placeholder_hash(), $name, $role]);
            $newId = (int)$pdo->lastInsertId();
        });
        if ($dup !== '' || $newId <= 0) {
            return ['ok' => false, 'message' => $dup !== '' ? $dup : 'The account could not be created.'];
        }
        rh_licence_staff_log("added {$username} ({$name}) as {$role}");
        $r = rh_licence_staff_invite($pdo, $newId);
        if (empty($r['success'])) {
            return ['ok' => true, 'message' => 'Added, but the invitation email could not be sent: ' . mb_substr((string)($r['message'] ?? ''), 0, 150)
                . ' The hotel can use "Resend invitation" on the Users page.'];
        }
        return ['ok' => true, 'message' => 'Added; invitation emailed to ' . $email . '.'];
    }

    // ------------------------------------------------------------------ the report

    /**
     * Each person's own permission changes, reduced to what differs from their role's usual set (the Users page stores a row
     * for every permission, which would make the report needlessly large). Pure. @return array{grants:string[],denies:string[]}
     */
    function rh_licence_staff_own_changes(array $explicit, array $roleDefaults): array
    {
        $grants = [];
        $denies = [];
        foreach ($explicit as $key => $on) {
            $inRole = in_array($key, $roleDefaults, true);
            if ($on && !$inRole) {
                $grants[] = (string)$key;
            } elseif (!$on && $inRole) {
                $denies[] = (string)$key;
            }
        }
        return ['grants' => $grants, 'denies' => $denies];
    }

    /** @return array{0:array,1:array} [staff list, roles and permissions] */
    function rh_licence_staff_snapshot(PDO $pdo): array
    {
        $own = [];
        try {
            foreach ($pdo->query('SELECT user_id, permission_key, is_granted FROM user_permissions')->fetchAll(PDO::FETCH_ASSOC) as $r) {
                $own[(int)$r['user_id']][(string)$r['permission_key']] = (int)$r['is_granted'] === 1;
            }
        } catch (Throwable $e) { /* no table: nobody has their own changes */ }
        $staff = [];
        foreach ($pdo->query('SELECT id, username, email, full_name, role, is_active, last_login FROM admin_users ORDER BY id')->fetchAll(PDO::FETCH_ASSOC) as $u) {
            $id = (int)$u['id'];
            $role = (string)$u['role'];
            $ch = ($role === 'admin' || empty($own[$id])) ? ['grants' => [], 'denies' => []]
                : rh_licence_staff_own_changes($own[$id], array_values(getDefaultPermissionsForRole($role)));
            if ($role !== 'admin' && function_exists('rh_admin_only_permissions')) {
                // Owner 2026-11-11: an administrators-only key never counts below administrator, whatever row is saved.
                $ch['grants'] = array_values(array_diff($ch['grants'], rh_admin_only_permissions()));
            }
            if ($role === 'admin' && function_exists('rh_admin_permission_overrides')
                && !(function_exists('rh_is_super_admin') && rh_is_super_admin($id))) {
                $ov = rh_admin_permission_overrides($id);
                if (function_exists('rh_admin_real_limits')) {
                    // Owner 2026-11-11: denies = a super admin's real limits (super-admin-only keys left out, they are off
                    // by default); grants = super-admin-only keys a super admin switched on for them.
                    $ch['denies'] = array_keys(rh_admin_real_limits($ov));
                    $ch['grants'] = rh_super_admins_in_charge()
                        ? array_values(array_intersect(array_keys($ov['grants'] ?? []), rh_super_only_permissions())) : [];
                } else {
                    $ch['denies'] = array_keys($ov['denies']); // a super admin's limits on them
                }
            }
            $staff[] = ['id' => $id, 'username' => (string)$u['username'], 'email' => (string)$u['email'], 'full_name' => (string)$u['full_name'],
                'role' => $role, 'is_active' => (int)$u['is_active'], 'last_login' => $u['last_login'] !== null ? (string)$u['last_login'] : null,
                'grants' => $ch['grants'], 'denies' => $ch['denies'],
                'super_admin' => (function_exists('rh_is_super_admin_account') && rh_is_super_admin_account($id)) ? 1 : 0]; // the panel cannot change them
        }
        $roles = [];
        foreach (getAllRoles() as $k => $r) {
            $roles[] = ['key' => (string)$k, 'label' => (string)($r['label'] ?? $k), 'level' => (int)($r['level'] ?? 0),
                'defaults' => ($r['permissions'] ?? null) === null ? null : array_values(getDefaultPermissionsForRole((string)$k))];
        }
        $perms = [];
        foreach (getAllPermissions() as $k => $p) {
            // tier (owner 2026-11-11): 'super' = super admins only (plain administrators only when switched on for them, or
            // while no super admin exists), 'admin' = administrators only, '' = any role. Extra field: older panels ignore it.
            $tier = '';
            if (function_exists('rh_super_only_permissions') && in_array((string)$k, rh_super_only_permissions(), true)) {
                $tier = 'super';
            } elseif (function_exists('rh_admin_only_permissions') && in_array((string)$k, rh_admin_only_permissions(), true)) {
                $tier = 'admin';
            }
            $perms[] = ['key' => (string)$k, 'label' => (string)($p['label'] ?? $k), 'category' => (string)($p['category'] ?? 'Other'), 'tier' => $tier];
        }
        return [$staff, ['roles' => $roles, 'permissions' => $perms]];
    }

    /**
     * After a licence check: run the panel's new staff changes, then report. Called only where nobody is waiting (after the
     * reply has been sent, or from the command line). One run at a time. Never throws.
     * @return array{ran:int,failed:int,reported:bool,error:string}
     */
    function rh_licence_staff_run(int $timeout = 6): array
    {
        $out = ['ran' => 0, 'failed' => 0, 'reported' => false, 'error' => ''];
        $pdo = $GLOBALS['pdo'] ?? null;
        if (!($pdo instanceof PDO) || !rh_licence_enabled()) {
            return $out;
        }
        $locked = false;
        try {
            $lockName = rh_licence_lock_name($pdo, 'rh_licence_staff');
            $locked = (int)$pdo->query('SELECT GET_LOCK(' . $pdo->quote($lockName) . ', 0)')->fetchColumn() === 1;
            if (!$locked) {
                return $out;
            }
            $st = rh_licence_status(true);
            $payload = is_array($st['payload'] ?? null) && ($st['reason'] ?? '') === 'ok' ? $st['payload'] : null;
            if ($payload === null) {
                $out['error'] = 'No verified licence answer (' . (string)($st['reason'] ?? '?') . ').';
                return $out; // nothing to run, nothing to report
            }
            if (!function_exists('getAllRoles')) {
                require_once dirname(__DIR__) . '/admin/includes/permissions.php';
            }
            $s = rh_licence_settings_fresh($pdo, rh_licence_staff_setting_keys());
            $doneId = max(0, (int)$s['licence_staff_done_id']);
            $kept = json_decode($s['licence_staff_results'], true);
            $kept = is_array($kept) ? $kept : [];
            $now = time();

            $new = [];
            foreach (rh_licence_staff_todo(rh_licence_staff_commands($payload), $doneId, (int)($payload['issued_at'] ?? 0), $now) as $c) {
                try {
                    $r = rh_licence_staff_apply($pdo, $c);
                } catch (Throwable $e) {
                    error_log('licence staff change ' . $c['id'] . ': ' . $e->getMessage());
                    $r = ['ok' => false, 'message' => 'This system could not make the change (an internal error).'];
                }
                $new[] = ['id' => $c['id'], 'ok' => $r['ok'], 'message' => $r['message']];
                $out['ran']++;
                $out['failed'] += $r['ok'] ? 0 : 1;
                // Recorded after each change, so a crash never runs one twice.
                rh_licence_staff_set($pdo, 'licence_staff_done_id', (string)$c['id']);
                $kept = rh_licence_staff_merge_results($kept, [end($new)]);
                rh_licence_staff_set($pdo, 'licence_staff_results', (string)json_encode($kept));
            }

            [$staff, $catalog] = rh_licence_staff_snapshot($pdo);
            $hash = sha1((string)json_encode([$staff, $catalog]));
            $asked = is_int($payload['staff_list_requested_at'] ?? null) ? $payload['staff_list_requested_at'] : 0;
            if (!rh_licence_staff_report_due($out['ran'] > 0, $kept !== [], $asked, (int)$s['licence_staff_list_seen'], $hash,
                $s['licence_staff_sent_hash'], (int)$s['licence_staff_sent_at'], $now)) {
                return $out;
            }
            $cfg = rh_licence_config();
            $url = rh_licence_staff_report_url($cfg['url']);
            if ($url === '' || $cfg['key'] === '') {
                $out['error'] = $url === '' ? 'No report address: LICENCE_URL does not end in /licence.php.' : 'No licence key.';
                return $out;
            }
            $body = json_encode(['tenant' => $cfg['tenant'], 'domain' => rh_licence_site_domain(), 'key' => $cfg['key'],
                'results' => $kept, 'staff' => $staff, 'catalog' => $catalog], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
            $http = rh_licence_http_post($url, (string)$body, $timeout);
            $ans = json_decode($http['body'], true);
            if ($http['error'] !== '' || $http['code'] !== 200 || !is_array($ans) || ($ans['ok'] ?? null) !== true) {
                $code = is_array($ans) && is_string($ans['error'] ?? null) && preg_match('/^[a-z_]{1,30}$/', $ans['error']) ? ' ' . $ans['error'] : '';
                $out['error'] = 'The staff report was not accepted (HTTP ' . $http['code'] . $code . ', ' . strlen((string)$body) . ' bytes'
                    . ($http['error'] !== '' ? ', ' . $http['error'] : '') . ').';
                return $out; // results stay kept and go with the next report
            }
            $acked = array_values(array_filter(is_array($ans['acked'] ?? null) ? $ans['acked'] : [], 'is_int'));
            rh_licence_staff_set($pdo, 'licence_staff_results', (string)json_encode(rh_licence_staff_merge_results($kept, [], $acked)));
            rh_licence_staff_set($pdo, 'licence_staff_sent_hash', $hash);
            rh_licence_staff_set($pdo, 'licence_staff_sent_at', (string)$now);
            rh_licence_staff_set($pdo, 'licence_staff_list_seen', (string)$asked);
            $out['reported'] = true;
            return $out;
        } catch (Throwable $e) {
            error_log('licence staff: ' . $e->getMessage());
            $out['error'] = 'The staff step failed: ' . get_class($e) . ' ' . mb_substr($e->getMessage(), 0, 150);
            return $out;
        } finally {
            if ($locked) {
                try {
                    rh_licence_staff_set($pdo, 'licence_staff_error', mb_substr($out['error'], 0, 240));
                } catch (Throwable $e) { /* best effort */ }
                try { $pdo->query('SELECT RELEASE_LOCK(' . $pdo->quote($lockName) . ')')->fetchColumn(); } catch (Throwable $e) { /* released at disconnect */ }
            }
        }
    }
}
