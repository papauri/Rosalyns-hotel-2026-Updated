<?php

/**
 * Self-service password change with email OTP confirmation.
 *
 * Two-step flow for logged-in admin users (any role — every employee can
 * change their own password):
 *   1. pwc_start()   — verify current password, validate the new one,
 *                      generate a 6-digit OTP. Caller emails the OTP and
 *                      keeps $store (normally $_SESSION['pwc']) server-side.
 *   2. pwc_confirm() — verify the OTP (10-minute expiry, max 5 attempts),
 *                      then write the new password hash.
 *
 * The pending state lives in $store passed by reference so the same logic
 * is unit-testable with a plain array and session-backed in the page.
 */

const PWC_OTP_TTL_SECONDS = 600;
const PWC_MAX_ATTEMPTS    = 5;

/**
 * @return array{success:bool,message:string,otp?:string}
 */
function pwc_start(array &$store, PDO $pdo, int $userId, string $currentPassword, string $newPassword, string $confirmPassword): array
{
    $store = []; // any prior pending change is invalidated

    if ($newPassword !== $confirmPassword) {
        return ['success' => false, 'message' => 'New password and confirmation do not match.'];
    }
    if (strlen($newPassword) < 8) {
        return ['success' => false, 'message' => 'New password must be at least 8 characters.'];
    }
    if ($currentPassword === $newPassword) {
        return ['success' => false, 'message' => 'New password must be different from the current one.'];
    }

    $stmt = $pdo->prepare("SELECT id, username, full_name, email, password_hash AS password, is_active FROM admin_users WHERE id = ?");
    $stmt->execute([$userId]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$row || !(int)$row['is_active']) {
        return ['success' => false, 'message' => 'Account not found or inactive.'];
    }
    if (!password_verify($currentPassword, (string)$row['password'])) {
        return ['success' => false, 'message' => 'Current password is incorrect.'];
    }
    $email = trim((string)($row['email'] ?? ''));
    if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
        return ['success' => false, 'message' => 'Your account has no email address on file — ask an administrator to set one, or to reset your password for you.'];
    }

    $otp = str_pad((string)random_int(0, 999999), 6, '0', STR_PAD_LEFT);
    $store = [
        'user_id'  => $userId,
        'otp_hash' => hash('sha256', $otp),
        'new_hash' => password_hash($newPassword, PASSWORD_DEFAULT),
        'expires'  => time() + PWC_OTP_TTL_SECONDS,
        'attempts' => 0,
        'email'    => $email,
        'name'     => (string)($row['full_name'] ?: $row['username']),
    ];

    return ['success' => true, 'message' => 'Verification code generated.', 'otp' => $otp];
}

/**
 * @return array{success:bool,message:string,done?:bool}
 */
function pwc_confirm(array &$store, PDO $pdo, int $userId, string $otpInput): array
{
    if (empty($store) || (int)($store['user_id'] ?? 0) !== $userId) {
        return ['success' => false, 'message' => 'No pending password change — start again.'];
    }
    if (time() > (int)($store['expires'] ?? 0)) {
        $store = [];
        return ['success' => false, 'message' => 'Verification code expired — start again.'];
    }
    $store['attempts'] = (int)($store['attempts'] ?? 0) + 1;
    if ($store['attempts'] > PWC_MAX_ATTEMPTS) {
        $store = [];
        return ['success' => false, 'message' => 'Too many incorrect attempts — start again.'];
    }
    $otpInput = preg_replace('/\D+/', '', trim($otpInput));
    if (!hash_equals((string)$store['otp_hash'], hash('sha256', (string)$otpInput))) {
        $left = PWC_MAX_ATTEMPTS - $store['attempts'];
        return ['success' => false, 'message' => 'Incorrect code. ' . max(0, $left) . ' attempt(s) remaining.'];
    }

    $pdo->prepare("UPDATE admin_users SET password_hash = ? WHERE id = ?")->execute([(string)$store['new_hash'], $userId]);
    if (function_exists('logActivity')) {
        try { logActivity($userId, 'password_changed', 'Self-service password change (email OTP verified)'); } catch (Throwable $e) { /* non-fatal */ }
    }
    $store = [];
    return ['success' => true, 'message' => 'Password changed successfully.', 'done' => true];
}
