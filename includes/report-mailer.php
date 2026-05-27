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

    $valid = [];
    foreach ($recipients as $r) {
        $email = trim((string)($r['email'] ?? ''));
        if ($email !== '' && filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $valid[] = ['email' => $email, 'name' => (string)($r['name'] ?? '')];
        }
    }
    if (!$valid) {
        return ['success' => false, 'message' => 'No valid recipients supplied', 'sent_to' => []];
    }

    if (empty($smtp_host) || empty($smtp_username) || empty($smtp_password)) {
        return ['success' => false, 'message' => 'SMTP is not fully configured', 'sent_to' => []];
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

        $mail->send();

        if ($email_log_enabled) {
            foreach ($valid as $r) {
                logEmail($r['email'], $r['name'] ?? '', $subject, 'sent', '', 'reports:' . count($attachments));
            }
        }

        return [
            'success' => true,
            'message' => 'Sent to ' . count($valid) . ' recipient(s) with ' . count($attachments) . ' attachment(s)',
            'sent_to' => array_column($valid, 'email'),
        ];
    } catch (PHPMailerException $e) {
        error_log('sendReportEmail PHPMailer: ' . $e->getMessage());
        return ['success' => false, 'message' => $e->getMessage(), 'sent_to' => []];
    } catch (Throwable $e) {
        error_log('sendReportEmail: ' . $e->getMessage());
        return ['success' => false, 'message' => $e->getMessage(), 'sent_to' => []];
    }
}
