<?php

/**
 * Staff invitations: a new (or re-invited) admin user gets a branded email with their
 * username, role and a one-time "Accept invitation & set your password" link. No password
 * is ever emailed. The link is a password_resets token (sha256 stored, 72 h, single use)
 * consumed by admin/reset-password.php, which greets first-time users as an invitation.
 *
 * Copies (e.g. to the owner) get the same email with the button replaced by a note: a
 * working link in two inboxes would be two holders of the same credential.
 */

require_once __DIR__ . '/../../config/email.php';

if (!function_exists('rh_staff_invite_send')) {

    define('RH_STAFF_INVITE_HOURS', 72);

    /** Random password nobody knows: the account is unusable until the invite is accepted. */
    function rh_staff_invite_placeholder_hash(): string
    {
        return password_hash(bin2hex(random_bytes(24)), PASSWORD_DEFAULT);
    }

    /** Issue a fresh single-use link token; earlier unused links for the user stop working. */
    function rh_staff_invite_issue_token(PDO $pdo, int $userId): array
    {
        $pdo->prepare('UPDATE password_resets SET used_at = NOW() WHERE user_id = ? AND used_at IS NULL')->execute([$userId]);
        $token = bin2hex(random_bytes(32));
        $expires = date('Y-m-d H:i:s', time() + RH_STAFF_INVITE_HOURS * 3600);
        $pdo->prepare('INSERT INTO password_resets (user_id, token, expires_at, created_at) VALUES (?, ?, ?, NOW())')
            ->execute([$userId, hash('sha256', $token), $expires]);
        return ['token' => $token, 'expires_at' => $expires];
    }

    /**
     * Accept link on the domain the inviting admin is using, so an invite sent from a
     * test/staging domain lands there and one sent from the live domain lands there,
     * whatever site_url says. Invites are only sent from signed-in admin POSTs (session +
     * CSRF), so the Host header is the admin's own browser's. The site_url setting and
     * BASE_URL are fallbacks for non-HTTP contexts (CLI, cron).
     */
    function rh_staff_invite_url(string $token): string
    {
        $base = '';
        $host = (string)($_SERVER['HTTP_HOST'] ?? '');
        if (PHP_SAPI !== 'cli' && $host !== '' && preg_match('/^[a-z0-9.-]+(:\d{1,5})?$/i', $host)
            && function_exists('detectBaseUrl')) {
            $base = (string)detectBaseUrl();
        }
        if ($base === '') {
            $base = trim((string)getSetting('site_url', ''));
        }
        if ($base === '' && defined('BASE_URL')) {
            $base = (string)BASE_URL;
        }
        return rtrim($base, '/') . '/admin/reset-password.php?token=' . $token;
    }

    /**
     * Inner HTML for the invitation (wrapped in the hotel email shell by sendEmail()).
     * $acceptUrl null = copy for someone else: the button is replaced by a note.
     */
    function rh_staff_invite_html(array $u, string $roleLabel, ?string $acceptUrl, string $expiresText, string $invitedBy, ?string $copyFor = null): string
    {
        global $email_site_name;
        $e = static fn($v): string => htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8');
        $site = $e($email_site_name);
        $first = $e(trim(explode(' ', (string)$u['full_name'])[0] ?? '') ?: (string)$u['full_name']);
        $row = static fn(string $k, string $v, bool $last = false): string =>
            '<tr><td style="padding:10px 10px 10px 0;font-weight:bold;color:#1A1A1A;width:40%;vertical-align:top;' . ($last ? '' : 'border-bottom:1px solid #e8e0d4;') . '">' . $k . '</td>'
            . '<td style="padding:10px 0 10px 6px;color:#333;' . ($last ? '' : 'border-bottom:1px solid #e8e0d4;') . '">' . $v . '</td></tr>';

        $copyBanner = $copyFor !== null
            ? '<div style="background:#EEF2F7;border-left:4px solid #5B7083;padding:12px 15px;border-radius:5px;margin:0 0 20px;font-size:13px;color:#33475B;">'
              . '<strong>Copy for your records.</strong> This invitation was sent to ' . $e($copyFor) . '. The personal sign-in link is only in their copy.</div>'
            : '';

        $button = $acceptUrl !== null
            ? '<p style="text-align:center;margin:28px 0 10px;">'
              . '<a href="' . $e($acceptUrl) . '" style="display:inline-block;background:#7E684B;color:#ffffff;padding:14px 32px;text-decoration:none;border-radius:4px;font-size:15px;letter-spacing:0.04em;">Accept invitation &amp; set your password &rarr;</a></p>'
              . '<p style="text-align:center;font-size:12px;color:#888;margin:0 0 24px;">Button not working? Copy this link into your browser:<br><span style="word-break:break-all;color:#7E684B;">' . $e($acceptUrl) . '</span></p>'
            : '<p style="text-align:center;margin:28px 0;padding:14px;border:1px dashed #C8A45A;border-radius:6px;color:#7E684B;font-size:14px;">[ Accept invitation button &mdash; sent privately to ' . $e($u['email']) . ' ]</p>';

        return $copyBanner . '
        <h1 style="color:#7E684B;text-align:center;margin-bottom:6px;">You&rsquo;re invited to join the team</h1>
        <p style="text-align:center;color:#8a7f70;margin-top:0;">' . $site . ' &middot; Staff portal</p>
        <p>Dear ' . $first . ',</p>
        <p>' . $e($invitedBy) . ' has created a staff account for you at <strong>' . $site . '</strong>. Accept the invitation to choose your own password and sign in &mdash; it only takes a minute.</p>

        <div style="background:#FAF6F0;border:2px solid #C8A45A;padding:20px;margin:22px 0;border-radius:10px;">
            <h2 style="color:#7E684B;margin-top:0;text-align:left;">Your account</h2>
            <table role="presentation" width="100%" cellspacing="0" cellpadding="0" style="margin:0;">'
            . $row('Name:', $e($u['full_name']))
            . $row('Username:', '<span style="font-family:monospace;font-size:15px;">' . $e($u['username']) . '</span>')
            . $row('Email:', $e($u['email']))
            . $row('Role:', $e($roleLabel), true) . '
            </table>
        </div>
        ' . $button . '
        <div style="background:#FDF6EC;padding:15px;border-left:4px solid #C8A45A;border-radius:5px;margin:20px 0;">
            <p style="color:#5C4A32;margin:0 0 6px;font-size:13px;"><strong>How it works</strong></p>
            <ol style="color:#5C4A32;margin:0;padding-left:18px;font-size:13px;line-height:1.6;">
                <li>Click <em>Accept invitation</em> and choose a password (at least 8 characters).</li>
                <li>Sign in with your username <strong>' . $e($u['username']) . '</strong> and that password.</li>
                <li>The link works once and expires on <strong>' . $e($expiresText) . '</strong>. Ask an administrator to resend it if it runs out.</li>
            </ol>
        </div>
        <p style="font-size:13px;color:#777;">Didn&rsquo;t expect this? You can ignore this email &mdash; nothing happens until the invitation is accepted.</p>
        <p style="margin:28px 0 0;font-size:14px;color:#777;text-align:center;font-style:italic;">Warm regards &mdash; ' . $site . '</p>';
    }

    /**
     * Send (or re-send) the invitation for an existing admin user.
     *
     * @param string[] $copyTo extra recipients who get the copy without the link
     * @return array{success:bool,message:string,expires_at?:string,copies?:array}
     */
    function rh_staff_invite_send(PDO $pdo, int $userId, string $invitedBy, array $copyTo = []): array
    {
        $st = $pdo->prepare('SELECT id, username, email, full_name, role FROM admin_users WHERE id = ?');
        $st->execute([$userId]);
        $u = $st->fetch(PDO::FETCH_ASSOC);
        if (!$u) {
            return ['success' => false, 'message' => 'User not found.'];
        }
        if (!filter_var((string)$u['email'], FILTER_VALIDATE_EMAIL)) {
            return ['success' => false, 'message' => 'This user has no valid email address.'];
        }
        $roles = function_exists('getAllRoles') ? getAllRoles() : [];
        $roleLabel = (string)($roles[$u['role']]['label'] ?? ucwords(str_replace('_', ' ', (string)$u['role'])));

        $tok = rh_staff_invite_issue_token($pdo, (int)$u['id']);
        $expiresText = date('l j F Y, H:i', strtotime($tok['expires_at'])) . ' (' . date_default_timezone_get() . ' time)';
        global $email_site_name;
        $subject = 'You’re invited to ' . $email_site_name . ' — accept your staff account';

        $html = rh_staff_invite_html($u, $roleLabel, rh_staff_invite_url($tok['token']), $expiresText, $invitedBy);
        $r = sendEmail((string)$u['email'], (string)$u['full_name'], $subject, $html);
        if (empty($r['success'])) {
            return ['success' => false, 'message' => 'Invitation email failed: ' . (string)($r['message'] ?? 'unknown error')];
        }

        $copies = [];
        foreach (array_unique(array_filter($copyTo, static fn($c) => filter_var($c, FILTER_VALIDATE_EMAIL) && strcasecmp($c, (string)$u['email']) !== 0)) as $cc) {
            $copyHtml = rh_staff_invite_html($u, $roleLabel, null, $expiresText, $invitedBy, (string)$u['email']);
            $cr = sendEmail($cc, null, '[Copy] ' . $subject, $copyHtml);
            $copies[$cc] = !empty($cr['success']) ? 'sent' : ('failed: ' . (string)($cr['message'] ?? ''));
        }
        return ['success' => true, 'message' => 'Invitation sent to ' . $u['email'] . '.', 'expires_at' => $tok['expires_at'], 'copies' => $copies];
    }
}
