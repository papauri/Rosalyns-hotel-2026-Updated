<?php

/**
 * Make a staff account a super admin (owner 2026-11-11). Command line only.
 *
 *   php scripts/make-super-admin.php <email> [--proceed]
 *
 * A super admin is an administrator (role 'admin') with admin_users.is_super_admin = 1: everything an administrator has,
 * plus changing administrators' permissions and managing other super admins (see rh_is_super_admin() in
 * admin/includes/permissions.php). Needs migration 080 (php admin/migrations/migrate.php --run).
 *
 * The email is matched case-insensitively. If one account has it, that account becomes an active super admin. If none
 * does, a new account is made with a password nobody knows: use "Forgot password" on the admin login to set it.
 * Never put a real email address in this file (the repository is public).
 */

declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(403); exit("CLI only.\n"); } // never runnable over the web

$ROOT = dirname(__DIR__);
require_once $ROOT . '/config/database.php';

$argv = $argv ?? [];
$email = strtolower(trim((string)($argv[1] ?? '')));
if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL) || strlen($email) > 255) {
    fwrite(STDERR, "Usage: php scripts/make-super-admin.php <email> [--proceed]\n");
    exit(1);
}
if (!isset($pdo) || !($pdo instanceof PDO)) {
    fwrite(STDERR, "Database connection not available.\n");
    exit(1);
}

try {
    $st = $pdo->prepare("SELECT COUNT(*) FROM information_schema.COLUMNS
                          WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'admin_users' AND COLUMN_NAME = 'is_super_admin'");
    $st->execute();
    if ((int)$st->fetchColumn() === 0) {
        fwrite(STDERR, "The super admin column is missing: run php admin/migrations/migrate.php --run first.\n");
        exit(1);
    }

    $pdo->beginTransaction();
    $st = $pdo->prepare("SELECT id, username, full_name, role, is_active, is_super_admin FROM admin_users WHERE LOWER(TRIM(email)) = ? FOR UPDATE");
    $st->execute([$email]);
    $rows = $st->fetchAll(PDO::FETCH_ASSOC);

    if (count($rows) > 1) {
        $pdo->rollBack();
        fwrite(STDERR, count($rows) . " accounts share this email. Give each its own email on the Users page, then run this again.\n");
        exit(1);
    }

    if (count($rows) === 1) {
        $u = $rows[0];
        $id = (int)$u['id'];
        $changes = [];
        if ((string)$u['role'] !== 'admin') {
            $changes[] = "role {$u['role']} -> admin";
        }
        if ((int)$u['is_active'] !== 1) {
            $changes[] = 'active no -> yes';
        }
        if ((int)$u['is_super_admin'] !== 1) {
            $changes[] = 'super admin no -> yes';
        }
        if ($changes === []) {
            $pdo->commit();
            echo "Account #{$id} ({$u['username']}) is already an active super admin. Nothing changed.\n";
            exit(0);
        }
        // Permissions this account switched off for someone BEFORE that person became an administrator would count as a
        // super admin's limits on them the moment this account is flagged. Stop and name them unless --proceed is given.
        $st = $pdo->prepare("SELECT u.username, COUNT(*) AS n FROM user_permissions up JOIN admin_users u ON u.id = up.user_id
                              WHERE up.granted_by = ? AND up.is_granted = 0 AND u.role = 'admin' AND u.id <> ?
                              GROUP BY u.username ORDER BY u.username");
        $st->execute([$id, $id]);
        $affected = $st->fetchAll(PDO::FETCH_ASSOC);
        if ($affected !== [] && !in_array('--proceed', $argv, true)) {
            $pdo->rollBack();
            foreach ($affected as $r) {
                echo "Administrator '{$r['username']}': {$r['n']} permission(s) switched off earlier by this account (before they were an administrator) would start to apply.\n";
            }
            echo "Nothing changed. Run again with --proceed to go ahead (then a super admin can use Users > Permissions > Reset to give them full access), or decide first how to clear those old rows.\n";
            exit(2);
        }
        $pdo->prepare("UPDATE admin_users SET role = 'admin', is_active = 1, is_super_admin = 1 WHERE id = ?")->execute([$id]);
        try {
            $pdo->prepare("UPDATE admin_users SET failed_login_attempts = 0 WHERE id = ?")->execute([$id]);
        } catch (Throwable $e) { /* older copies have no such column */ }
        // A super admin carries no rows of their own: a leftover "View only" row would grey them out (includes/view-only.php)
        // and nothing in the app can clear a super admin's rows. Same as rh_set_super_admin_flag() on the Users page.
        $pdo->prepare("DELETE FROM user_permissions WHERE user_id = ?")->execute([$id]);
        $what = implode(', ', $changes);
        try {
            $pdo->prepare("INSERT INTO admin_activity_log (user_id, action, details, ip_address, created_at) VALUES (?, 'super_admin_changed', ?, '', NOW())")
                ->execute([$id, "Command line (scripts/make-super-admin.php): {$what}"]);
        } catch (Throwable $e) { /* the change stands even if the log row cannot be written */ }
        $pdo->commit();
        echo "Account #{$id} ({$u['username']}, {$u['full_name']}): {$what}.\n";
        foreach ($affected as $r) {
            echo "Note: administrator '{$r['username']}' now has {$r['n']} permission(s) switched off (old rows from this account).\n";
        }
        echo "Sign out and in again (or refresh) to see Super Admin next to your name.\n";
        exit(0);
    }

    // No account with this email: make one with an unusable password.
    $local = substr($email, 0, (int)strpos($email, '@'));
    $base = preg_replace('/[^a-z0-9._-]/', '', $local);
    if (strlen((string)$base) < 3) {
        $base = 'superadmin';
    }
    $base = substr((string)$base, 0, 90);
    $username = $base;
    $exists = $pdo->prepare("SELECT 1 FROM admin_users WHERE LOWER(username) = ? LIMIT 1");
    for ($n = 2; ; $n++) {
        $exists->execute([strtolower($username)]);
        if (!$exists->fetchColumn()) {
            break;
        }
        if ($n > 999) {
            throw new RuntimeException('Could not find a free username.');
        }
        $username = $base . $n;
    }
    $hash = password_hash(bin2hex(random_bytes(32)), PASSWORD_DEFAULT);
    $pdo->prepare("INSERT INTO admin_users (username, email, password_hash, full_name, role, is_active, is_super_admin, created_at)
                   VALUES (?, ?, ?, ?, 'admin', 1, 1, NOW())")
        ->execute([$username, $email, $hash, $local]);
    $id = (int)$pdo->lastInsertId();
    try {
        $pdo->prepare("INSERT INTO admin_activity_log (user_id, action, details, ip_address, created_at) VALUES (?, 'super_admin_changed', ?, '', NOW())")
            ->execute([$id, 'Command line (scripts/make-super-admin.php): new super admin account created']);
    } catch (Throwable $e) { /* the change stands even if the log row cannot be written */ }
    $pdo->commit();
    echo "Created super admin account #{$id}, username '{$username}', for {$email}.\n";
    echo "Use 'Forgot password' on the admin login with this email to set your password.\n";
    exit(0);
} catch (Throwable $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    fwrite(STDERR, 'Not changed: ' . $e->getMessage() . "\n");
    exit(1);
}
