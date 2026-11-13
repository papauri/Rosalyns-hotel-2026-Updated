<?php

/**
 * Bulk report mailer.
 *
 * sendReportEmail($recipients, $subject, $htmlBody, $attachments)
 *   $recipients  — list of ['email'=>..., 'name'=>...] (multiple supported, sent as separate addresses on the same email)
 *   $attachments — list of ['filename'=>'foo.csv', 'content'=>string, 'mime'=>'text/csv']
 *
 * Uses the same SMTP settings as config/email.php (no new credentials).
 */

declare(strict_types=1);

require_once __DIR__ . '/../config/email.php';

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception as PHPMailerException;

/**
 * @param list<array{email:string, name?:string}> $recipients
 * @param list<array{filename:string, content:string, mime?:string}> $attachments
 * @param list<string> $ccEmails
 */
function sendReportEmail(array $recipients, string $subject, string $htmlBody, array $attachments = [], string $textBody = '', array $ccEmails = []): array
{
    global $email_from_name, $email_from_email, $email_admin_email, $email_site_name;
    global $smtp_host, $smtp_port, $smtp_username, $smtp_password, $smtp_secure, $smtp_timeout, $smtp_debug;
    global $email_log_enabled;

    // Test mode (owner B3 2026-11-13): while the automated test context is set (the timer in test mode, or a clicked
    // report send at a hotel in test mode - rh_report_with_test_ctx), a report goes ONLY to the test address,
    // subject [TEST], with no visible copy and no hidden staff copy. An unusable test address fails closed.
    $testTo = trim((string)($GLOBALS['rh_automated_ctx']['redirect'] ?? ''));
    $isTest = $testTo !== '';
    unset($GLOBALS['rh_tp_redirected_from']);
    if ($isTest) {
        if (function_exists('rh_test_platform_active') && rh_test_platform_active()) {
            // Test platform notice lists who the report would have gone to.
            $GLOBALS['rh_tp_redirected_from'] = array_values(array_filter(array_merge(
                array_map(static fn($r) => trim((string)($r['email'] ?? '')), $recipients), array_map('strval', $ccEmails))));
        }
        $recipients = filter_var($testTo, FILTER_VALIDATE_EMAIL) ? [['email' => $testTo, 'name' => '']] : [];
        $ccEmails = [];
        if (strpos($subject, '[TEST]') !== 0) {
            $subject = '[TEST] ' . $subject;
        }
    }

    // Failures before a message exists still reach System Logs > Emails sent (L20).
    $logEarly = static function (string $msg) use ($subject, $recipients): array {
        if (function_exists('rh_email_send_log_mail')) {
            rh_email_send_log_mail('failed', null, $subject, $msg, implode(', ', array_filter(array_map(static fn($r) => trim((string)($r['email'] ?? '')), $recipients))));
        }
        return ['success' => false, 'message' => $msg, 'sent_to' => []];
    };

    $valid = [];
    foreach ($recipients as $r) {
        $email = trim((string)($r['email'] ?? ''));
        if ($email !== '' && filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $valid[] = ['email' => $email, 'name' => (string)($r['name'] ?? '')];
        }
    }
    if (!$valid) {
        return $logEarly($isTest ? 'Test mode is on but the test address is not a valid email, so nothing was sent' : 'No valid recipients supplied');
    }

    if (empty($smtp_host) || empty($smtp_username) || empty($smtp_password)) {
        return $logEarly('SMTP is not fully configured');
    }

    try {
        $mail = new PHPMailer(true);
        $secure = strtolower(trim((string)$smtp_secure));
        if ($secure === '' && (int)$smtp_port === 587) $secure = 'tls';
        elseif ($secure === '' && (int)$smtp_port === 465) $secure = 'ssl';

        $mail->isSMTP();
        $mail->Host       = $smtp_host;
        $mail->SMTPAuth   = true;
        $mail->Username   = $smtp_username;
        $mail->Password   = $smtp_password;
        if ($secure !== '') {
            $mail->SMTPSecure = $secure;
        }
        $mail->Port       = (int)$smtp_port;
        $mail->Timeout    = (int)$smtp_timeout ?: 30;
        if ((int)$smtp_debug > 0) {
            $mail->SMTPDebug = (int)$smtp_debug;
        }

        $fromAddress = $smtp_username;
        $mail->setFrom($fromAddress, $email_from_name ?: ($email_site_name ?: 'Hotel Reports'));
        if (!empty($email_from_email) && filter_var($email_from_email, FILTER_VALIDATE_EMAIL)) {
            $mail->addReplyTo($email_from_email, $email_from_name ?: ($email_site_name ?: 'Hotel Reports'));
        }

        foreach ($valid as $r) {
            $mail->addAddress($r['email'], $r['name'] ?? '');
        }

        foreach ($ccEmails as $ccEmail) {
            $ccEmail = trim((string)$ccEmail);
            if ($ccEmail !== '' && filter_var($ccEmail, FILTER_VALIDATE_EMAIL)) {
                $mail->addCC($ccEmail);
            }
        }
        // Staff-only report: one hidden copy to the staff copy email while the switch is on, unless it is
        // already a recipient (includes/email-identity.php). Never on a test send (L1).
        if (!$isTest) {
            rh_mail_add_staff_bcc($mail, (string)($valid[0]['email'] ?? ''), (bool)getEmailSetting('email_bcc_admin', 0));
        }

        $mail->CharSet  = PHPMailer::CHARSET_UTF8;
        $mail->Encoding = PHPMailer::ENCODING_BASE64;
        $mail->isHTML(true);
        $mail->Subject  = $subject;
        $mail->Body     = function_exists('hotel_embed_logo_cid')
            ? hotel_embed_logo_cid($mail, function_exists('wrapEmailTemplate') ? wrapEmailTemplate($htmlBody, $subject) : $htmlBody)
            : (function_exists('wrapEmailTemplate') ? wrapEmailTemplate($htmlBody, $subject) : $htmlBody);
        $mail->AltBody  = $textBody !== '' ? $textBody : strip_tags($htmlBody);

        foreach ($attachments as $att) {
            $name    = (string)($att['filename'] ?? 'report.csv');
            $content = (string)($att['content']  ?? '');
            $mime    = (string)($att['mime']     ?? 'text/csv');
            if ($content === '') {
                continue;
            }
            $mail->addStringAttachment($content, $name, PHPMailer::ENCODING_BASE64, $mime);
        }

        if (function_exists('rh_test_platform_mail_apply')) { rh_test_platform_mail_apply($mail); } // test platform: only the test recipient
        $mail->send();

        if ($email_log_enabled) {
            foreach ($valid as $r) {
                logEmail($r['email'], $r['name'] ?? '', $subject, 'sent', '', 'reports:' . count($attachments));
            }
        }
        if (function_exists('rh_email_send_log_mail')) {
            rh_email_send_log_mail('sent', $mail, $subject, '', implode(', ', array_column($valid, 'email')), count($valid) === 1 ? (string)($valid[0]['name'] ?? '') : null);
        }

        return [
            'success' => true,
            'message' => 'Sent to ' . count($valid) . ' recipient(s) with ' . count($attachments) . ' attachment(s)',
            'sent_to' => array_column($valid, 'email'),
        ];
    } catch (PHPMailerException $e) {
        error_log('sendReportEmail PHPMailer: ' . $e->getMessage());
        if (function_exists('rh_email_send_log_mail')) {
            rh_email_send_log_mail('failed', $mail ?? null, $subject, $e->getMessage(), implode(', ', array_column($valid, 'email')));
        }
        return ['success' => false, 'message' => $e->getMessage(), 'sent_to' => []];
    } catch (Throwable $e) {
        error_log('sendReportEmail: ' . $e->getMessage());
        if (function_exists('rh_email_send_log_mail')) {
            rh_email_send_log_mail('failed', $mail ?? null, $subject, $e->getMessage(), implode(', ', array_column($valid, 'email')));
        }
        return ['success' => false, 'message' => $e->getMessage(), 'sent_to' => []];
    }
}
