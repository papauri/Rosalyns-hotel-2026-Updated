<?php

/**
 * Database-Driven Email Configuration for any hotel
 * All settings stored in database - no hardcoded files
 */

// Require base URL configuration first (needed for absolute logo URLs)
require_once __DIR__ . '/base-url.php';

// Require database connection for settings
require_once __DIR__ . '/database.php';

// WhatsApp notification functions
if (file_exists(__DIR__ . '/../includes/whatsapp-functions.php')) {
    require_once __DIR__ . '/../includes/whatsapp-functions.php';
}

// Load PHPMailer
use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;

// Load PHPMailer from local directory
if (file_exists(__DIR__ . '/../PHPMailer/src/PHPMailer.php')) {
    require_once __DIR__ . '/../PHPMailer/src/PHPMailer.php';
    require_once __DIR__ . '/../PHPMailer/src/SMTP.php';
    require_once __DIR__ . '/../PHPMailer/src/Exception.php';
} elseif (file_exists(__DIR__ . '/../vendor/autoload.php')) {
    // Fallback to Composer autoloader
    require_once __DIR__ . '/../vendor/autoload.php';
}

// Get email settings from database - NO HARCODED DEFAULTS
$email_from_name = getEmailSetting('email_from_name', '');
$email_from_email = getEmailSetting('email_from_email', '');
$email_admin_email = getEmailSetting('email_admin_email', '');
$email_site_name = getSetting('site_name', '');
$email_site_url = getSetting('site_url', '');

// SMTP Configuration - From database only
$smtp_host = getEmailSetting('smtp_host', '');
$smtp_port = (int)getEmailSetting('smtp_port', 0);
$smtp_username = getEmailSetting('smtp_username', '');
$smtp_password = getEmailSetting('smtp_password', '');
$smtp_secure = getEmailSetting('smtp_secure', '');
$smtp_timeout = (int)getEmailSetting('smtp_timeout', 30);
$smtp_debug = (int)getEmailSetting('smtp_debug', 0);

// Email settings
$email_bcc_admin = (bool)getEmailSetting('email_bcc_admin', 0);
$email_development_mode = (bool)getEmailSetting('email_development_mode', 0);
$email_log_enabled = (bool)getEmailSetting('email_log_enabled', 0);
$email_preview_enabled = (bool)getEmailSetting('email_preview_enabled', 0);

// Check if we're on localhost
$is_localhost = isset($_SERVER['HTTP_HOST']) && (
    strpos($_SERVER['HTTP_HOST'], 'localhost') !== false ||
    strpos($_SERVER['HTTP_HOST'], '127.0.0.1') !== false ||
    strpos($_SERVER['HTTP_HOST'], '.local') !== false
);

// Development mode: show previews on localhost unless explicitly disabled
$development_mode = $is_localhost && $email_development_mode;

/**
 * Send email using PHPMailer
 *
 * @param string $to Recipient email
 * @param string $toName Recipient name
 * @param string $subject Email subject
 * @param string $htmlBody HTML email body
 * @param string $textBody Plain text body (optional)
 * @return array Result array with success status and message
 */
function sendEmail(string $to, ?string $toName, string $subject, string $htmlBody, string $textBody = '')
{
    global $email_from_name, $email_from_email, $email_admin_email, $email_site_name;
    global $smtp_host, $smtp_port, $smtp_username, $smtp_password, $smtp_secure, $smtp_timeout, $smtp_debug;
    global $email_bcc_admin, $development_mode, $email_log_enabled, $email_preview_enabled;

    // If in development mode and no password or preview enabled, show preview
    if ($development_mode && (empty($smtp_password) || $email_preview_enabled)) {
        return createEmailPreview($to, $toName, $subject, $htmlBody, $textBody);
    }

    try {
        // Create PHPMailer instance
        $mail = new PHPMailer(true);

        $smtpSecureNormalized = strtolower(trim((string)$smtp_secure));
        if ($smtpSecureNormalized === '' && (int)$smtp_port === 587) {
            $smtpSecureNormalized = 'tls';
        } elseif ($smtpSecureNormalized === '' && (int)$smtp_port === 465) {
            $smtpSecureNormalized = 'ssl';
        }
        // Many SMTP relays require From to match authenticated mailbox.
        $fromAddress = $smtp_username;

        // Server settings
        $mail->isSMTP();
        $mail->Host = $smtp_host;
        $mail->SMTPAuth = true;
        $mail->Username = $smtp_username;
        $mail->Password = $smtp_password;
        if ($smtpSecureNormalized !== '') {
            $mail->SMTPSecure = $smtpSecureNormalized;
        }
        $mail->Port = $smtp_port;
        $mail->Timeout = $smtp_timeout;

        if ($smtp_debug > 0) {
            $mail->SMTPDebug = $smtp_debug;
        }

        // Recipients
        $mail->setFrom($fromAddress, $email_from_name ?: $email_site_name);
        $mail->addAddress($to, $toName ?? '');
        if (!empty($email_from_email) && filter_var($email_from_email, FILTER_VALIDATE_EMAIL)) {
            $mail->addReplyTo($email_from_email, $email_from_name ?: $email_site_name);
        }

        // Add BCC for admin if enabled
        if ($email_bcc_admin && !empty($email_admin_email)) {
            $mail->addBCC($email_admin_email);
        }

        // Content — force UTF-8 so em-dashes (—), currency, and accented chars render correctly
        $mail->CharSet  = PHPMailer::CHARSET_UTF8;
        $mail->Encoding = PHPMailer::ENCODING_BASE64;
        $mail->isHTML(true);
        $mail->Subject = $subject;
        $mail->Body = wrapEmailTemplate($htmlBody, $subject);
        $mail->AltBody = $textBody ?: strip_tags($htmlBody);

        $mail->send();

        // Log email if enabled
        if ($email_log_enabled) {
            logEmail($to, $toName, $subject, 'sent');
        }

        return [
            'success' => true,
            'message' => 'Email sent successfully via SMTP'
        ];
    } catch (Exception $e) {
        error_log("PHPMailer Error: " . $e->getMessage());

        // Log error if enabled
        if ($email_log_enabled) {
            logEmail($to, $toName, $subject, 'failed', $e->getMessage());
        }

        // If development mode, show preview instead of failing
        if ($development_mode) {
            return createEmailPreview($to, $toName, $subject, $htmlBody, $textBody);
        }

        return [
            'success' => false,
            'message' => 'Failed to send email: ' . $e->getMessage()
        ];
    }
}

/**
 * Send simple status update email for booking status changes.
 * Consolidated here so all email logic uses a single runtime file.
 */
function sendSimpleStatusUpdateEmail(array $booking, string $status)
{
    global $email_site_name, $email_from_email, $email_site_url;

    try {
        global $pdo;
        $stmt = $pdo->prepare("SELECT * FROM rooms WHERE id = ?");
        $stmt->execute([$booking['room_id']]);
        $room = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$room) {
            throw new Exception("Room not found");
        }

        $statusMessages = [
            'checked-in' => [
                'subject' => 'Check-in Confirmed',
                'title' => 'Welcome! You are Checked In',
                'message' => 'You have been successfully checked in. We hope you enjoy your stay!',
                'color' => '#28a745'
            ],
            'confirmed' => [
                'subject' => 'Booking Status Updated',
                'title' => 'Booking Status: Confirmed',
                'message' => 'Your booking status has been updated to confirmed.',
                'color' => '#8B7355'
            ],
            'cancelled' => [
                'subject' => 'Booking Cancelled',
                'title' => 'Booking Cancelled',
                'message' => 'Your booking has been cancelled.',
                'color' => '#dc3545'
            ]
        ];

        if (!isset($statusMessages[$status])) {
            throw new Exception("Unknown status: $status");
        }

        $msg = $statusMessages[$status];

        $htmlBody = '
        <h1 style="color: ' . $msg['color'] . '; text-align: center;">' . htmlspecialchars($msg['title']) . '</h1>
        <p>Dear ' . htmlspecialchars($booking['guest_name']) . ',</p>
        <p>' . $msg['message'] . '</p>

        <div style="background: #FAF6F0; border: 2px solid #C8A45A; padding: 20px; margin: 20px 0; border-radius: 10px;">
            <h2 style="color: #8B7355; margin-top: 0;;text-align:left;">Booking Details</h2>

            <table role="presentation" width="100%" cellspacing="0" cellpadding="0" style="margin:0;"><tr><td style="padding:10px 10px 10px 0;font-weight:bold;color:#1A1A1A;width:44%;vertical-align:top;font-family:\'Segoe UI\',Tahoma,Verdana,sans-serif;border-bottom:1px solid #e8e0d4;">Booking Reference:</td><td style="padding:10px 0 10px 6px;color: #8B7355; font-weight: bold; font-size: 18px;;text-align:left;vertical-align:top;font-family:\'Segoe UI\',Tahoma,Verdana,sans-serif;border-bottom:1px solid #e8e0d4;">' . htmlspecialchars($booking['booking_reference']) . '</td></tr></table>

            <table role="presentation" width="100%" cellspacing="0" cellpadding="0" style="margin:0;"><tr><td style="padding:10px 10px 10px 0;font-weight:bold;color:#1A1A1A;width:44%;vertical-align:top;font-family:\'Segoe UI\',Tahoma,Verdana,sans-serif;border-bottom:1px solid #e8e0d4;">Room:</td><td style="padding:10px 0 10px 6px;color: #333;;text-align:left;vertical-align:top;font-family:\'Segoe UI\',Tahoma,Verdana,sans-serif;border-bottom:1px solid #e8e0d4;">' . htmlspecialchars($room['name']) . '</td></tr></table>

            <table role="presentation" width="100%" cellspacing="0" cellpadding="0" style="margin:0;"><tr><td style="padding:10px 10px 10px 0;font-weight:bold;color:#1A1A1A;width:44%;vertical-align:top;font-family:\'Segoe UI\',Tahoma,Verdana,sans-serif;border-bottom:1px solid #e8e0d4;">Check-in Date:</td><td style="padding:10px 0 10px 6px;color: #333;;text-align:left;vertical-align:top;font-family:\'Segoe UI\',Tahoma,Verdana,sans-serif;border-bottom:1px solid #e8e0d4;">' . date('F j, Y', strtotime($booking['check_in_date'])) . '</td></tr></table>

            <table role="presentation" width="100%" cellspacing="0" cellpadding="0" style="margin:0;"><tr><td style="padding:10px 10px 10px 0;font-weight:bold;color:#1A1A1A;width:44%;vertical-align:top;font-family:\'Segoe UI\',Tahoma,Verdana,sans-serif;border-bottom:1px solid #e8e0d4;">Check-out Date:</td><td style="padding:10px 0 10px 6px;color: #333;;text-align:left;vertical-align:top;font-family:\'Segoe UI\',Tahoma,Verdana,sans-serif;border-bottom:1px solid #e8e0d4;">' . date('F j, Y', strtotime($booking['check_out_date'])) . '</td></tr></table>

            <table role="presentation" width="100%" cellspacing="0" cellpadding="0" style="margin:0;"><tr><td style="padding:10px 10px 10px 0;font-weight:bold;color:#1A1A1A;width:44%;vertical-align:top;font-family:\'Segoe UI\',Tahoma,Verdana,sans-serif;">Status:</td><td style="padding:10px 0 10px 6px;color: ' . $msg['color'] . '; font-weight: bold; text-transform: uppercase;;text-align:left;vertical-align:top;font-family:\'Segoe UI\',Tahoma,Verdana,sans-serif;">' . htmlspecialchars($status) . '</td></tr></table>
        </div>

        <p>If you have any questions, please contact us at <a href="mailto:' . htmlspecialchars($email_from_email) . '">' . htmlspecialchars($email_from_email) . '</a>.</p>
        <p style="margin:28px 0 0;font-size:14px;color:#777;text-align:center;font-style:italic;">
            Warm regards &mdash; see you soon.
        </p>';

        return sendEmail(
            $booking['guest_email'],
            $booking['guest_name'],
            $msg['subject'] . ' - ' . htmlspecialchars($email_site_name) . ' [' . $booking['booking_reference'] . ']',
            $htmlBody
        );
    } catch (Exception $e) {
        error_log("Send Simple Status Update Email Error: " . $e->getMessage());
        return [
            'success' => false,
            'message' => $e->getMessage()
        ];
    }
}

/**
 * Create email preview for development mode
 */
function createEmailPreview(string $to, ?string $toName, string $subject, string $htmlBody, string $textBody = '')
{
    global $email_from_name, $email_from_email, $email_admin_email, $email_site_name, $email_site_url;
    global $email_log_enabled;

    // Log email if enabled
    if ($email_log_enabled) {
        logEmail($to, $toName, $subject, 'preview');
    }

    // Create email preview file
    $previewDir = __DIR__ . '/../logs/email-previews';
    if (!file_exists($previewDir)) {
        mkdir($previewDir, 0755, true);
    }

    $previewFile = $previewDir . '/' . date('Y-m-d-His') . '-' . preg_replace('/[^a-z0-9]/i', '-', strtolower($subject)) . '.html';
    $previewContent = '<!DOCTYPE html>
    <html>
    <head>
        <meta charset="UTF-8">
        <title>Email Preview: ' . htmlspecialchars($subject) . '</title>
        <style>
            body { font-family: Arial, sans-serif; padding: 20px; background: #f5f5f5; }
            .email-preview { max-width: 800px; margin: 0 auto; background: white; padding: 30px; border-radius: 10px; box-shadow: 0 2px 10px rgba(0,0,0,0.1); }
            .email-info { background: #e3f2fd; padding: 15px; border-radius: 5px; margin-bottom: 20px; }
            .email-info h3 { margin-top: 0; color: #1565c0; }
            .email-info p { margin: 5px 0; }
            .email-content { border: 1px solid #ddd; padding: 20px; border-radius: 5px; }
            .dev-note { background: #fff3cd; padding: 10px; border-left: 4px solid #ffc107; margin-top: 20px; border-radius: 5px; }
        </style>
    </head>
    <body>
        <div class="email-preview">
            <div class="email-info">
                <h3>📧 Email Preview (Development Mode)</h3>
                <p><strong>From:</strong> ' . htmlspecialchars($email_from_name) . ' <' . htmlspecialchars($email_from_email) . '></p>
                <p><strong>To:</strong> ' . htmlspecialchars($toName) . ' <' . htmlspecialchars($to) . '></p>
                <p><strong>Subject:</strong> ' . htmlspecialchars($subject) . '</p>
                <p><strong>Time:</strong> ' . date('Y-m-d H:i:s') . '</p>
                <p><strong>Status:</strong> Preview only - email would be sent via SMTP in production</p>
            </div>
            <div class="email-content">' . $htmlBody . '</div>
            <div class="dev-note">
                <p><strong>💡 Development Note:</strong> This is a preview. In production, emails will be sent automatically using SMTP.</p>
            </div>
        </div>
    </body>
    </html>';

    file_put_contents($previewFile, $previewContent);

    return [
        'success' => true,
        'message' => 'Email preview created (development mode)',
        'preview_url' => str_replace(__DIR__ . '/../', '', $previewFile)
    ];
}

/**
 * Log email activity
 */
function logEmail(string $to, ?string $toName, string $subject, string $status, string $error = '', string $meta = '')
{
    $logDir = __DIR__ . '/../logs';
    if (!file_exists($logDir)) {
        mkdir($logDir, 0755, true);
    }

    $logFile = $logDir . '/email-log.txt';
    $logEntry = "[" . date('Y-m-d H:i:s') . "] [$status] $subject to $to ($toName)";
    if ($error) {
        $logEntry .= " - Error: $error";
    }
    if ($meta) {
        $logEntry .= " - Meta: $meta";
    }
    $logEntry .= "\n";
    file_put_contents($logFile, $logEntry, FILE_APPEND);
}

if (!function_exists('hotel_inline_image_src')) {
    function hotel_inline_image_src(string $filePath): string
    {
        if ($filePath === '' || !is_file($filePath) || !is_readable($filePath)) {
            return '';
        }

        $bytes = @file_get_contents($filePath);
        if ($bytes === false) {
            return '';
        }

        $extension = strtolower((string)pathinfo($filePath, PATHINFO_EXTENSION));
        $mime = match ($extension) {
            'png' => 'image/png',
            'jpg', 'jpeg' => 'image/jpeg',
            'gif' => 'image/gif',
            'webp' => 'image/webp',
            'svg' => 'image/svg+xml',
            default => function_exists('mime_content_type') ? (string)mime_content_type($filePath) : 'application/octet-stream',
        };

        return 'data:' . $mime . ';base64,' . base64_encode($bytes);
    }
}

if (!function_exists('hotel_invoice_logo_src')) {
    function hotel_invoice_logo_src(): string
    {
        $siteUrl = trim((string)getSetting('site_url', ''));
        $remoteFallback = '';
        $relativeFallback = '';
        $candidates = [
            (string)getSetting('site_logo', ''),
            (string)getSetting('logo_url', ''),
            (string)getSetting('hotel_logo', ''),
            'images/logo/logo.png',
        ];

        foreach ($candidates as $candidate) {
            $candidate = trim($candidate);
            if ($candidate === '') {
                continue;
            }

            if (preg_match('#^https?://#i', $candidate)) {
                if ($remoteFallback === '') {
                    $remoteFallback = $candidate;
                }
                continue;
            }

            $relativePath = ltrim($candidate, '/');
            $localPath = __DIR__ . '/../' . $relativePath;
            if (is_file($localPath)) {
                $inlineSrc = hotel_inline_image_src($localPath);
                if ($inlineSrc !== '') {
                    return $inlineSrc;
                }

                return $siteUrl !== ''
                    ? rtrim($siteUrl, '/') . '/' . $relativePath
                    : $relativePath;
            }

            if ($relativeFallback === '') {
                $relativeFallback = $relativePath;
            }
        }

        if ($remoteFallback !== '') {
            return $remoteFallback;
        }

        if ($relativeFallback !== '') {
            return $siteUrl !== ''
                ? rtrim($siteUrl, '/') . '/' . $relativeFallback
                : $relativeFallback;
        }

        return '';
    }
}

if (!function_exists('hotel_default_payment_invoice_document_html')) {
    function hotel_default_payment_invoice_document_html(): string
    {
        return <<<'HTML'
<div style="font-family:Arial,Helvetica,sans-serif;color:#1E2430;background:#FFFFFF;padding:0;">
    <table style="width:100%;border-collapse:collapse;background:#FFFFFF;" cellpadding="0" cellspacing="0">
        <tr>
            <td style="padding:0 18px 0;">
                <div style="height:1px;background:#20303E;"></div>
            </td>
        </tr>
        <tr>
            <td style="padding:0 18px 1px;text-align:center;">
                <div style="max-width:360px;margin:0 auto 0;">{{logo_html}}</div>
                <p style="margin:2px 0 0;font-size:8px;letter-spacing:1.6px;text-transform:uppercase;color:#20303E;font-weight:700;">{{site_name}}</p>
                <p style="margin:1px 0 0;font-size:6px;line-height:1.2;color:#5F655F;">{{address}}</p>
                <p style="margin:1px 0 0;font-size:6px;color:#5F655F;">{{contact_phone}} | {{contact_email}}</p>
                {{vat_number_html}}
            </td>
        </tr>
        <tr>
            <td style="padding:0 18px 2px;">
                    <div style="background:#F8F4EE;padding:8px 6px 5px;border-top:2px solid #D5B37C;">
                        <table style="width:100%;border-collapse:collapse;" cellpadding="0" cellspacing="0">
                            <tr>
                                <td style="width:26%;padding:0 6px 0 0;vertical-align:top;border-right:1px solid #E7DED3;text-align:center;"><span style="display:inline-block;font-size:6px;letter-spacing:1px;text-transform:uppercase;color:#7C6E5B;font-weight:700;">Invoice</span></td>
                                <td style="width:37%;padding:0 6px;vertical-align:top;border-right:1px solid #E7DED3;text-align:center;"><span style="display:inline-block;font-size:6px;letter-spacing:1px;text-transform:uppercase;color:#7C6E5B;font-weight:700;">Bill To</span></td>
                                <td style="width:37%;padding:0 0 0 6px;vertical-align:top;text-align:center;"><span style="display:inline-block;font-size:6px;letter-spacing:1px;text-transform:uppercase;color:#7C6E5B;font-weight:700;">Stay Summary</span></td>
                            </tr>
                            <tr>
                                <td style="width:26%;padding:6px 6px 0 0;vertical-align:top;border-right:1px solid #E7DED3;text-align:center;">
                                    <div style="font-size:10px;line-height:1;color:#1E2430;font-weight:700;">{{invoice_number}}</div>
                                </td>
                                <td style="width:37%;padding:6px 6px 0;vertical-align:top;border-right:1px solid #E7DED3;text-align:center;">
                                    <div style="font-size:6px;color:#5F655F;line-height:1.2;">Name: {{guest_name}}</div>
                                </td>
                                <td style="width:37%;padding:6px 0 0 6px;vertical-align:top;text-align:center;">
                                    <div style="font-size:6px;color:#5F655F;line-height:1.2;">Reference: {{booking_reference}}</div>
                                </td>
                            </tr>
                            <tr>
                                <td style="width:26%;padding:2px 6px 0 0;vertical-align:top;border-right:1px solid #E7DED3;text-align:center;">
                                    <div style="font-size:6px;color:#5F655F;line-height:1.2;">Issued {{issued_date}}</div>
                                    <div style="margin-top:3px;">
                                        <div style="display:inline-block;padding:2px 4px;background:{{status_bg}};color:{{status_fg}};font-size:6px;letter-spacing:0.7px;text-transform:uppercase;font-weight:700;border-radius:999px;">{{status_text}}</div>
                                    </div>
                                </td>
                                <td style="width:37%;padding:1px 6px 0;vertical-align:top;border-right:1px solid #E7DED3;text-align:center;">
                                    <div style="font-size:6px;color:#5F655F;line-height:1.2;">Email: {{guest_email}}</div>
                                    <div style="font-size:6px;color:#5F655F;line-height:1.2;">Phone: {{guest_phone}}</div>
                                </td>
                                <td style="width:37%;padding:1px 0 0 6px;vertical-align:top;text-align:center;">
                                    <div style="font-size:6px;color:#5F655F;line-height:1.2;">Room: {{room_name}}</div>
                                    <div style="font-size:6px;color:#5F655F;line-height:1.2;">Check-in: {{check_in}}</div>
                                    <div style="font-size:6px;color:#5F655F;line-height:1.2;">Check-out: {{check_out}}</div>
                                    <div style="font-size:6px;color:#5F655F;line-height:1.2;">Guests: {{guests}}</div>
                                    <div style="font-size:6px;color:#5F655F;line-height:1.2;">Duration: {{nights}} night(s)</div>
                                </td>
                            </tr>
                        </table>
                    </div>
            </td>
        </tr>
        <tr>
            <td style="padding:0 18px;">
                <div style="background:#FCFAF7;padding:4px 6px;border-top:2px solid #20303E;">
                    <p style="margin:0 0 2px;font-size:6px;letter-spacing:0.7px;text-transform:uppercase;color:#20303E;font-weight:700;">Invoice Items (Description, Qty, Unit Rate, Line Total)</p>
                    <table style="width:100%;border-collapse:collapse;" cellpadding="0" cellspacing="0">
                        <tr>
                            <td width="58%" style="padding:3px 5px;background:#314654;color:#FFFFFF;font-size:7px;letter-spacing:0.2px;text-transform:none;font-weight:700;">Description</td>
                            <td width="8%" style="padding:3px 5px;background:#314654;color:#FFFFFF;font-size:7px;letter-spacing:0.2px;text-transform:none;font-weight:700;text-align:center;">Qty</td>
                            <td width="16%" style="padding:3px 5px;background:#314654;color:#FFFFFF;font-size:7px;letter-spacing:0.2px;text-transform:none;font-weight:700;text-align:right;">Unit Rate</td>
                            <td width="18%" style="padding:3px 5px;background:#314654;color:#FFFFFF;font-size:7px;letter-spacing:0.2px;text-transform:none;font-weight:700;text-align:right;">Line Total</td>
                        </tr>
                        {{charges_table_rows}}
                        {{totals_rows}}
                    </table>
                </div>
            </td>
        </tr>
        <tr>
            <td style="padding:3px 18px 0;">{{payment_history_section}}</td>
        </tr>
        <tr>
            <td style="padding:2px 18px 0;">{{bank_details}}</td>
        </tr>
        <tr>
            <td style="padding:2px 18px 0;">{{invoice_terms}}</td>
        </tr>
        <tr>
            <td style="padding:2px 18px 3px;">
                <div style="background:#F8F4EE;padding:6px 8px;text-align:center;border-top:2px solid #D5B37C;">
                    <p style="margin:0;font-size:7px;letter-spacing:1.8px;text-transform:uppercase;color:#20303E;font-weight:700;">{{site_name}}</p>
                    <p style="margin:2px 0 0;font-size:6px;line-height:1.2;color:#6D655C;">Thank you for choosing us</p>
                </div>
            </td>
        </tr>
    </table>
</div>
HTML;
    }
}

/**
 * Ensure booking email template defaults exist in DB
 */
function ensureBookingEmailTemplateDefaults()
{
    if (!function_exists('ensureBookingEmailTemplatesTable') || !function_exists('upsertBookingEmailTemplateConfig')) {
        return;
    }

    if (!ensureBookingEmailTemplatesTable()) {
        return;
    }

    $defaults = [
        'booking_received' => [
            'name' => 'Booking Received (Customer)',
            'subject' => 'Booking Received - {{site_name}} [{{booking_reference}}]',
            'html' => '<h1 style="color:#1A1A1A;text-align:center;">Booking Received - Awaiting Confirmation</h1><p>Dear {{guest_name}},</p><p>Thank you for your booking request with <strong>{{site_name}}</strong>.</p><p><strong>Reference:</strong> {{booking_reference}}<br><strong>Room:</strong> {{room_name}}<br><strong>Check-in:</strong> {{check_in_date_formatted}}<br><strong>Check-out:</strong> {{check_out_date_formatted}}<br><strong>Nights:</strong> {{number_of_nights}}<br><strong>Guests:</strong> {{number_of_guests}}<br><strong>Total:</strong> {{currency_symbol}} {{total_amount_formatted}}</p><p>{{payment_policy}}</p><p>Contact: <a href="mailto:{{contact_email}}">{{contact_email}}</a> | {{phone_main}}</p>',
        ],
        'booking_confirmed' => [
            'name' => 'Booking Confirmed (Customer)',
            'subject' => 'Booking Confirmed - {{site_name}} [{{booking_reference}}]',
            'html' => '<h1 style="color:#1A1A1A;text-align:center;">Booking Confirmed!</h1><p>Dear {{guest_name}},</p><p>Your booking with <strong>{{site_name}}</strong> is confirmed.</p><p><strong>Reference:</strong> {{booking_reference}}<br><strong>Room:</strong> {{room_name}}<br><strong>Check-in:</strong> {{check_in_date_formatted}}<br><strong>Check-out:</strong> {{check_out_date_formatted}}<br><strong>Nights:</strong> {{number_of_nights}}<br><strong>Guests:</strong> {{number_of_guests}}<br><strong>Total:</strong> {{currency_symbol}} {{total_amount_formatted}}</p><p>{{payment_policy}}</p><p>Check-in: {{check_in_time}} | Check-out: {{check_out_time}}</p><p>Contact: <a href="mailto:{{contact_email}}">{{contact_email}}</a> | {{phone_main}}</p>',
        ],
        'booking_cancelled' => [
            'name' => 'Booking Cancelled (Customer)',
            'subject' => 'Booking Cancelled - {{site_name}} [{{booking_reference}}]',
            'html' => '<h1 style="color:#dc3545;text-align:center;">Booking Cancelled</h1><p>Dear {{guest_name}},</p><p>Your booking with <strong>{{site_name}}</strong> has been cancelled.</p><p><strong>Reference:</strong> {{booking_reference}}<br><strong>Room:</strong> {{room_name}}<br><strong>Check-in:</strong> {{check_in_date_formatted}}<br><strong>Check-out:</strong> {{check_out_date_formatted}}<br><strong>Total:</strong> {{currency_symbol}} {{total_amount_formatted}}</p><p><strong>Reason:</strong> {{cancellation_reason}}</p><p>Contact: <a href="mailto:{{contact_email}}">{{contact_email}}</a> | {{phone_main}}</p>',
        ],
        'payment_invoice' => [
            'name' => 'Payment Invoice (Customer)',
            'subject' => 'Payment Invoice - {{site_name}} [{{booking_reference}}]',
            'html' => '<h1 style="color:#1A1A1A;text-align:center;">Payment Confirmed</h1><p>Dear {{guest_name}},</p><p>Thank you. Your payment has been received for booking <strong>{{booking_reference}}</strong>.</p><p><strong>Room:</strong> {{room_name}}<br><strong>Check-in:</strong> {{check_in_date_formatted}}<br><strong>Check-out:</strong> {{check_out_date_formatted}}<br><strong>Total Paid:</strong> {{currency_symbol}} {{total_amount_formatted}}</p><p>Your invoice is attached to this email.</p><p>Contact: <a href="mailto:{{contact_email}}">{{contact_email}}</a> | {{phone_main}}</p>',
        ],
        'tentative_booking_created' => [
            'name' => 'Tentative Booking Created',
            'subject' => 'Tentative Booking Created - {{site_name}} [{{booking_reference}}]',
            'html' => '<h1 style="color:#8B7355;text-align:center;">Tentative Booking Created</h1><p>Dear {{guest_name}},</p><p>Your room has been placed on tentative hold.</p><p><strong>Reference:</strong> {{booking_reference}}<br><strong>Room:</strong> {{room_name}}<br><strong>Check-in:</strong> {{check_in_date_formatted}}<br><strong>Check-out:</strong> {{check_out_date_formatted}}<br><strong>Total:</strong> {{currency_symbol}} {{total_amount_formatted}}<br><strong>Hold Expires:</strong> {{tentative_expires_at_formatted}}</p><p>Please confirm before the hold expiry date.</p><p>Contact: <a href="mailto:{{contact_email}}">{{contact_email}}</a> | {{phone_main}}</p>',
        ],
        'tentative_booking_reminder' => [
            'name' => 'Tentative Booking Reminder',
            'subject' => 'Reminder: Tentative Booking Expires Soon - {{booking_reference}}',
            'html' => '<h1 style="color:#8B7355;text-align:center;">Tentative Hold Reminder</h1><p>Dear {{guest_name}},</p><p>This is a reminder that your tentative booking will expire soon.</p><p><strong>Reference:</strong> {{booking_reference}}<br><strong>Room:</strong> {{room_name}}<br><strong>Hold Expires:</strong> {{tentative_expires_at_formatted}}</p><p>Reply to this email or contact us to confirm the booking.</p><p>Contact: <a href="mailto:{{contact_email}}">{{contact_email}}</a> | {{phone_main}}</p>',
        ],
        'tentative_booking_expired' => [
            'name' => 'Tentative Booking Expired',
            'subject' => 'Tentative Booking Expired - {{booking_reference}}',
            'html' => '<h1 style="color:#6c757d;text-align:center;">Tentative Booking Expired</h1><p>Dear {{guest_name}},</p><p>Your tentative booking has expired and the hold has been released.</p><p><strong>Reference:</strong> {{booking_reference}}<br><strong>Room:</strong> {{room_name}}<br><strong>Check-in:</strong> {{check_in_date_formatted}}<br><strong>Check-out:</strong> {{check_out_date_formatted}}</p><p>You can make a new booking at any time.</p><p>Contact: <a href="mailto:{{contact_email}}">{{contact_email}}</a> | {{phone_main}}</p>',
        ],
        'tentative_booking_converted' => [
            'name' => 'Tentative Booking Converted',
            'subject' => 'Booking Confirmed - {{site_name}} [{{booking_reference}}]',
            'html' => '<h1 style="color:#8B7355;text-align:center;">Booking Confirmed</h1><p>Dear {{guest_name}},</p><p>Your tentative booking has now been confirmed.</p><p><strong>Reference:</strong> {{booking_reference}}<br><strong>Room:</strong> {{room_name}}<br><strong>Check-in:</strong> {{check_in_date_formatted}} from {{check_in_time}}<br><strong>Check-out:</strong> {{check_out_date_formatted}} by {{check_out_time}}<br><strong>Total:</strong> {{currency_symbol}} {{total_amount_formatted}}</p><p>{{payment_policy}}</p><p>Contact: <a href="mailto:{{contact_email}}">{{contact_email}}</a> | {{phone_main}}</p>',
        ],
        'tentative_quotation' => [
            'name' => 'Tentative Booking Quotation',
            'subject' => 'Quotation for Your Stay - {{site_name}} [{{quotation_reference}}]',
            'html' => '<h1 style="color:#8B7355;text-align:center;">Your Stay Quotation</h1><p>Dear {{guest_name}},</p><p>Please find your quotation details below:</p><p><strong>Quote Ref:</strong> {{quotation_reference}}<br><strong>Booking Ref:</strong> {{booking_reference}}<br><strong>Room:</strong> {{room_name}}<br><strong>Check-in:</strong> {{check_in_date}}<br><strong>Check-out:</strong> {{check_out_date}}<br><strong>Total:</strong> {{total_amount}}<br><strong>Valid Until:</strong> {{valid_until}}</p><p>{{quotation_notes}}</p><p>Contact: <a href="mailto:{{contact_email}}">{{contact_email}}</a> | {{contact_phone}}</p>',
        ],
        'conference_quotation' => [
            'name' => 'Conference Quotation',
            'subject' => 'Conference Quotation - {{site_name}} [{{inquiry_reference}}]',
            'html' => '<h1 style="color:#8B7355;text-align:center;">Conference Quotation</h1><p>Dear {{contact_person}},</p><p>Your conference quotation is ready.</p><p><strong>Inquiry Ref:</strong> {{inquiry_reference}}<br><strong>Quotation Ref:</strong> {{quotation_reference}}<br><strong>Company:</strong> {{company_name}}<br><strong>Room:</strong> {{conference_room}}<br><strong>Date:</strong> {{event_date}}<br><strong>Time:</strong> {{event_time}}<br><strong>Attendees:</strong> {{attendees}}<br><strong>Total:</strong> {{total_amount}}<br><strong>Valid Until:</strong> {{valid_until}}</p><p>{{quotation_notes}}</p><p>Contact: <a href="mailto:{{contact_email}}">{{contact_email}}</a> | {{contact_phone}}</p>',
        ],
        'event_quotation' => [
            'name' => 'Event Quotation',
            'subject' => 'Event Quotation - {{site_name}} [{{quotation_reference}}]',
            'html' => '<h1 style="color:#8B7355;text-align:center;">Event Quotation</h1><p>Dear {{recipient_name}},</p><p>Your event quotation is ready.</p><p><strong>Quotation Ref:</strong> {{quotation_reference}}<br><strong>Event:</strong> {{event_title}}<br><strong>Date:</strong> {{event_date}}<br><strong>Time:</strong> {{event_time}}<br><strong>Location:</strong> {{event_location}}<br><strong>Attendees:</strong> {{attendee_count}}<br><strong>Total:</strong> {{total_amount}}<br><strong>Valid Until:</strong> {{valid_until}}</p><p>{{quotation_notes}}</p><p>Contact: <a href="mailto:{{contact_email}}">{{contact_email}}</a> | {{contact_phone}}</p>',
        ],
        'credit_note' => [
            'name' => 'Credit Note (Guest)',
            'subject' => 'Credit Note {{credit_note_number}} — {{site_name}}',
            'html' => '<!DOCTYPE html><html lang="en"><head><meta charset="UTF-8"><meta name="viewport" content="width=device-width,initial-scale=1"></head><body style="font-family:Segoe UI,Tahoma,Geneva,Verdana,sans-serif;background:#F7F3EE;margin:0;padding:20px;"><div style="max-width:600px;margin:0 auto;background:#fff;border-radius:10px;overflow:hidden;box-shadow:0 4px 12px rgba(0,0,0,.1);"><div style="background:#231F1C;padding:32px 40px;text-align:center;"><p style="font-family:Georgia,serif;font-size:28px;color:#B18247;margin:0;letter-spacing:.05em;">{{site_name}}</p><p style="color:#C8C0B8;font-size:12px;margin:6px 0 0;letter-spacing:.08em;text-transform:uppercase;">Credit Note</p><div style="display:inline-block;background:#B18247;color:#fff;border-radius:4px;padding:6px 18px;font-size:12px;font-weight:600;letter-spacing:.06em;margin-top:16px;">CREDIT NOTE {{credit_note_number}}</div></div><div style="padding:32px 40px;"><p style="font-size:17px;color:#2A2723;margin:0 0 16px;">Dear {{guest_name}},</p><p style="color:#5E554D;font-size:15px;line-height:1.6;margin:0 0 24px;">A credit note has been issued to your account. The details are outlined below and the PDF is attached for your records.</p><div style="background:#F7F3EE;border-radius:8px;padding:20px 24px;margin-bottom:24px;"><table style="width:100%;border-collapse:collapse;"><tr><td style="padding:6px 0;color:#5E554D;font-size:14px;">Credit Note No.</td><td style="text-align:right;font-weight:600;color:#2A2723;font-size:14px;">{{credit_note_number}}</td></tr><tr><td style="padding:6px 0;color:#5E554D;font-size:14px;">Face Value</td><td style="text-align:right;font-weight:600;color:#2A2723;font-size:14px;">{{amount}}</td></tr><tr><td style="padding:6px 0;color:#5E554D;font-size:14px;border-top:1px solid #EAE1D8;">Available Balance</td><td style="text-align:right;font-weight:700;color:#1f7a42;font-size:15px;border-top:1px solid #EAE1D8;">{{balance}}</td></tr><tr><td style="padding:6px 0;color:#5E554D;font-size:14px;">Reason</td><td style="text-align:right;font-weight:500;color:#2A2723;font-size:14px;">{{reason}}</td></tr><tr><td style="padding:6px 0;color:#5E554D;font-size:14px;">Valid Until</td><td style="text-align:right;font-weight:500;color:#2A2723;font-size:14px;">{{expires_at}}</td></tr></table></div><p style="color:#5E554D;font-size:14px;line-height:1.6;margin:0 0 20px;">Please quote <strong>{{credit_note_number}}</strong> when making your next booking. This credit note is non-transferable and cannot be exchanged for cash.</p><div style="background:#231F1C;border-radius:8px;padding:16px 20px;text-align:center;"><p style="color:#B18247;font-size:18px;font-weight:700;margin:0;">Available Balance: {{balance}}</p></div><p style="margin:24px 0 0;color:#5E554D;font-size:13px;">Questions? Contact us at <a href="mailto:{{contact_email}}" style="color:#B18247;">{{contact_email}}</a>.</p></div><div style="background:#F7F3EE;padding:16px 40px;text-align:center;border-top:1px solid #EAE1D8;"><p style="color:#8A775F;font-size:12px;margin:0;">{{site_name}}</p></div></div></body></html>',
        ],
        'payment_invoice_document' => [
            'name' => 'Invoice Document (PDF Attachment)',
            'subject' => 'Invoice Document',
            'html' => hotel_default_payment_invoice_document_html(),
        ],
    ];

    foreach ($defaults as $key => $def) {
        $existing = getBookingEmailTemplateConfig($key, []);
        if (empty($existing['subject']) || empty($existing['html_body'])) {
            upsertBookingEmailTemplateConfig($key, $def['name'], $def['subject'], $def['html'], '', 1);
        }
    }
}

/**
 * Build booking email variables
 */
function buildBookingEmailVariables(array $booking, ?array $room = null, array $extra = [])
{
    global $email_site_name, $email_site_url, $email_from_email;

    $currency = getSetting('currency_symbol');
    $roomTypeName = $room['name'] ?? ($booking['room_name'] ?? '');
    $roomAssignment = '';
    if (!empty($booking['id']) && function_exists('getBookingRoomLabel')) {
        $roomAssignment = getBookingRoomLabel((int)$booking['id']);
    }
    $displayRoomName = $roomAssignment !== '' ? trim($roomTypeName . ' - ' . $roomAssignment) : $roomTypeName;
    $vars = [
        'site_name' => $email_site_name,
        'site_url' => $email_site_url,
        'contact_email' => $email_from_email ?: getSetting('email_main', ''),
        'phone_main' => getSetting('phone_main', ''),
        'currency_symbol' => $currency,
        'payment_policy' => getSetting('payment_policy', ''),
        'check_in_time' => getSetting('check_in_time', '2:00 PM'),
        'check_out_time' => getSetting('check_out_time', '11:00 AM'),
        'booking_reference' => $booking['booking_reference'] ?? '',
        'guest_name' => $booking['guest_name'] ?? '',
        'guest_email' => $booking['guest_email'] ?? '',
        'guest_phone' => $booking['guest_phone'] ?? '',
        'room_name' => $displayRoomName,
        'room_assignment' => $roomAssignment,
        'room_numbers' => $roomAssignment,
        'check_in_date_formatted' => !empty($booking['check_in_date']) ? date('F j, Y', strtotime($booking['check_in_date'])) : '',
        'check_out_date_formatted' => !empty($booking['check_out_date']) ? date('F j, Y', strtotime($booking['check_out_date'])) : '',
        'number_of_nights' => (string)($booking['number_of_nights'] ?? ''),
        'number_of_guests' => (string)($booking['number_of_guests'] ?? ''),
        'adult_guests' => (string)($booking['adult_guests'] ?? max(1, ((int)($booking['number_of_guests'] ?? 1)) - (int)($booking['child_guests'] ?? 0))),
        'child_guests' => (string)($booking['child_guests'] ?? 0),
        'tentative_expires_at_formatted' => !empty($booking['tentative_expires_at']) ? date('F j, Y g:i A', strtotime((string)$booking['tentative_expires_at'])) : '',
        'tentative_status' => ucfirst((string)($booking['status'] ?? '')),
        'child_price_multiplier' => (string)($booking['child_price_multiplier'] ?? getSetting('booking_child_price_multiplier', getSetting('child_guest_price_multiplier', 50))),
        'child_supplement_total_formatted' => isset($booking['child_supplement_total']) ? number_format((float)$booking['child_supplement_total'], 0) : number_format(0, 0),
        'total_amount_formatted' => isset($booking['total_amount']) ? number_format((float)$booking['total_amount'], 0) : '',
        'special_requests' => $booking['special_requests'] ?? '',
        'cancellation_reason' => $extra['cancellation_reason'] ?? '',
        'rate_plan_label' => $booking['rate_plan_label'] ?? '',
        'rate_plan_discount_formatted' => isset($booking['rate_plan_discount']) && (float)$booking['rate_plan_discount'] > 0
            ? number_format((float)$booking['rate_plan_discount'], 0) : '',
        'package_total_formatted' => isset($booking['package_total']) && (float)$booking['package_total'] > 0
            ? number_format((float)$booking['package_total'], 0) : '',
    ];

    return array_merge($vars, $extra);
}

/**
 * Render booking email template from DB
 */
function renderBookingEmailTemplate(string $templateKey, array $vars)
{
    if (!function_exists('getBookingEmailTemplateConfig')) {
        return null;
    }

    $template = getBookingEmailTemplateConfig($templateKey, []);
    if (empty($template['subject']) || empty($template['html_body']) || (int)($template['is_active'] ?? 1) !== 1) {
        return null;
    }

    $replace = [];
    foreach ($vars as $k => $v) {
        $replace['{{' . $k . '}}'] = (string)$v;
    }

    return [
        'subject' => strtr($template['subject'], $replace),
        'html_body' => strtr($template['html_body'], $replace),
        'text_body' => !empty($template['text_body']) ? strtr($template['text_body'], $replace) : ''
    ];
}

ensureBookingEmailTemplateDefaults();

/**
 * Log cancellation to database
 *
 * @param int $booking_id The booking ID
 * @param string $booking_reference The booking reference
 * @param string $booking_type Type of booking (room/conference)
 * @param string $guest_email Guest email address
 * @param int $cancelled_by Admin user ID who cancelled
 * @param string $cancellation_reason Reason for cancellation
 * @param bool $email_sent Whether email was sent successfully
 * @param string $email_status Email status message
 * @return bool Success status
 */
function logCancellationToDatabase(int $booking_id, string $booking_reference, string $booking_type, string $guest_email, mixed $cancelled_by, string $cancellation_reason = '', bool $email_sent = false, string $email_status = '')
{
    global $pdo;

    try {
        $stmt = $pdo->prepare("
            INSERT INTO cancellation_log
            (booking_id, booking_reference, booking_type, guest_email, cancelled_by, cancellation_reason, email_sent, email_status)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?)
        ");
        $stmt->execute([
            $booking_id,
            $booking_reference,
            $booking_type,
            $guest_email,
            $cancelled_by,
            $cancellation_reason,
            $email_sent ? 1 : 0,
            $email_status
        ]);
        return true;
    } catch (Exception $e) {
        error_log("Failed to log cancellation to database: " . $e->getMessage());
        return false;
    }
}

/**
 * Log cancellation to file
 *
 * @param string $booking_reference The booking reference
 * @param string $booking_type Type of booking (room/conference)
 * @param string $guest_email Guest email address
 * @param string $cancelled_by_name Name of admin who cancelled
 * @param string $cancellation_reason Reason for cancellation
 * @param bool $email_sent Whether email was sent successfully
 * @param string $email_status Email status message
 * @return bool Success status
 */
function logCancellationToFile(string $booking_reference, string $booking_type, string $guest_email, string $cancelled_by_name, string $cancellation_reason = '', bool $email_sent = false, string $email_status = '')
{
    $logDir = __DIR__ . '/../logs';
    if (!file_exists($logDir)) {
        mkdir($logDir, 0755, true);
    }

    $logFile = $logDir . '/cancellations.log';
    $timestamp = date('Y-m-d H:i:s');
    $emailStatus = $email_sent ? 'SENT' : 'FAILED';

    $logEntry = "[$timestamp] CANCELLATION - Ref: $booking_reference | Type: $booking_type | Email: $guest_email | Cancelled by: $cancelled_by_name | Reason: $cancellation_reason | Email: $emailStatus ($email_status)\n";

    $result = file_put_contents($logFile, $logEntry, FILE_APPEND);
    return $result !== false;
}

/**
 * Send booking received email (sent immediately when user submits booking)
 */
function sendBookingReceivedEmail(array $booking)
{
    global $pdo, $email_from_name, $email_from_email, $email_admin_email, $email_site_name, $email_site_url;

    try {
        // Get room details
        $stmt = $pdo->prepare("SELECT * FROM rooms WHERE id = ?");
        $stmt->execute([$booking['room_id']]);
        $room = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$room) {
            throw new Exception("Room not found");
        }

        $templateVars = buildBookingEmailVariables($booking, $room);
        $dbTemplate = renderBookingEmailTemplate('booking_received', $templateVars);
        if ($dbTemplate) {
            return sendEmail(
                $booking['guest_email'],
                $booking['guest_name'],
                $dbTemplate['subject'],
                $dbTemplate['html_body'],
                $dbTemplate['text_body']
            );
        }

        // Prepare email content
        $htmlBody = '
        <h1 style="color: #8B7355; text-align: center;">Booking Received - Awaiting Confirmation</h1>
        <p>Dear ' . htmlspecialchars($booking['guest_name']) . ',</p>
        <p>Thank you for your booking request with <strong>' . htmlspecialchars($email_site_name) . '</strong>. We have received your reservation and it is currently being reviewed by our team.</p>

        <div style="background: #FAF6F0; border: 2px solid #C8A45A; padding: 20px; margin: 20px 0; border-radius: 10px;">
            <h2 style="color: #8B7355; margin-top: 0;;text-align:left;">Booking Details</h2>

            <table role="presentation" width="100%" cellspacing="0" cellpadding="0" style="margin:0;"><tr><td style="padding:10px 10px 10px 0;font-weight:bold;color:#1A1A1A;width:44%;vertical-align:top;font-family:\'Segoe UI\',Tahoma,Verdana,sans-serif;border-bottom:1px solid #e8e0d4;">Booking Reference:</td><td style="padding:10px 0 10px 6px;color: #8B7355; font-weight: bold; font-size: 18px;;text-align:left;vertical-align:top;font-family:\'Segoe UI\',Tahoma,Verdana,sans-serif;border-bottom:1px solid #e8e0d4;">' . htmlspecialchars($booking['booking_reference']) . '</td></tr></table>

            <table role="presentation" width="100%" cellspacing="0" cellpadding="0" style="margin:0;"><tr><td style="padding:10px 10px 10px 0;font-weight:bold;color:#1A1A1A;width:44%;vertical-align:top;font-family:\'Segoe UI\',Tahoma,Verdana,sans-serif;border-bottom:1px solid #e8e0d4;">Room:</td><td style="padding:10px 0 10px 6px;color: #333;;text-align:left;vertical-align:top;font-family:\'Segoe UI\',Tahoma,Verdana,sans-serif;border-bottom:1px solid #e8e0d4;">' . htmlspecialchars($room['name']) . '</td></tr></table>

            <table role="presentation" width="100%" cellspacing="0" cellpadding="0" style="margin:0;"><tr><td style="padding:10px 10px 10px 0;font-weight:bold;color:#1A1A1A;width:44%;vertical-align:top;font-family:\'Segoe UI\',Tahoma,Verdana,sans-serif;border-bottom:1px solid #e8e0d4;">Check-in Date:</td><td style="padding:10px 0 10px 6px;color: #333;;text-align:left;vertical-align:top;font-family:\'Segoe UI\',Tahoma,Verdana,sans-serif;border-bottom:1px solid #e8e0d4;">' . date('F j, Y', strtotime($booking['check_in_date'])) . '</td></tr></table>

            <table role="presentation" width="100%" cellspacing="0" cellpadding="0" style="margin:0;"><tr><td style="padding:10px 10px 10px 0;font-weight:bold;color:#1A1A1A;width:44%;vertical-align:top;font-family:\'Segoe UI\',Tahoma,Verdana,sans-serif;border-bottom:1px solid #e8e0d4;">Check-out Date:</td><td style="padding:10px 0 10px 6px;color: #333;;text-align:left;vertical-align:top;font-family:\'Segoe UI\',Tahoma,Verdana,sans-serif;border-bottom:1px solid #e8e0d4;">' . date('F j, Y', strtotime($booking['check_out_date'])) . '</td></tr></table>

            <table role="presentation" width="100%" cellspacing="0" cellpadding="0" style="margin:0;"><tr><td style="padding:10px 10px 10px 0;font-weight:bold;color:#1A1A1A;width:44%;vertical-align:top;font-family:\'Segoe UI\',Tahoma,Verdana,sans-serif;border-bottom:1px solid #e8e0d4;">Number of Nights:</td><td style="padding:10px 0 10px 6px;color: #333;;text-align:left;vertical-align:top;font-family:\'Segoe UI\',Tahoma,Verdana,sans-serif;border-bottom:1px solid #e8e0d4;">' . $booking['number_of_nights'] . ' night' . ($booking['number_of_nights'] != 1 ? 's' : '') . '</td></tr></table>

            <table role="presentation" width="100%" cellspacing="0" cellpadding="0" style="margin:0;"><tr><td style="padding:10px 10px 10px 0;font-weight:bold;color:#1A1A1A;width:44%;vertical-align:top;font-family:\'Segoe UI\',Tahoma,Verdana,sans-serif;border-bottom:1px solid #e8e0d4;">Number of Guests:</td><td style="padding:10px 0 10px 6px;color: #333;;text-align:left;vertical-align:top;font-family:\'Segoe UI\',Tahoma,Verdana,sans-serif;border-bottom:1px solid #e8e0d4;">' . $booking['number_of_guests'] . ' guest' . ($booking['number_of_guests'] != 1 ? 's' : '') . '</td></tr></table>

            <table role="presentation" width="100%" cellspacing="0" cellpadding="0" style="margin:0;"><tr><td style="padding:10px 10px 10px 0;font-weight:bold;color:#1A1A1A;width:44%;vertical-align:top;font-family:\'Segoe UI\',Tahoma,Verdana,sans-serif;">Total Amount:</td><td style="padding:10px 0 10px 6px;color: #8B7355; font-weight: bold; font-size: 18px;;text-align:left;vertical-align:top;font-family:\'Segoe UI\',Tahoma,Verdana,sans-serif;">' . getSetting('currency_symbol') . ' ' . number_format($booking['total_amount'], 0) . '</td></tr></table>
        </div>

        <div style="background: #FDF6EC; padding: 15px; border-left: 4px solid #8B7355; border-radius: 5px; margin: 20px 0;">
            <h3 style="color: #5C4A32; margin-top: 0;;text-align:left;">What Happens Next?</h3>
            <p style="color: #5C4A32; margin: 0;">
                <strong>Our team will review your booking and contact you within 24 hours to confirm availability.</strong><br>
                Once confirmed, you will receive a second email with final confirmation.
            </p>
        </div>

        <div style="background: #fff3cd; padding: 15px; border-left: 4px solid #ffc107; border-radius: 5px; margin: 20px 0;">
            <h3 style="color: #856404; margin-top: 0;;text-align:left;">Payment Information</h3>
            <p style="color: #856404; margin: 0;">
                ' . getSetting('payment_policy', 'Payment will be made at the hotel upon arrival.<br>We accept cash payments only. Please bring the total amount of <strong>' . getSetting('currency_symbol') . ' ' . number_format($booking['total_amount'], 0) . '</strong> with you.') . '
            </p>
        </div>';

        if (!empty($booking['special_requests'])) {
            $htmlBody .= '
            <div style="background: #FDF6EC; padding: 15px; border-left: 4px solid #8B7355; border-radius: 5px; margin: 20px 0;">
                <h3 style="color: #5C4A32; margin-top: 0;;text-align:left;">Special Requests</h3>
                <p style="color: #5C4A32; margin: 0;">' . htmlspecialchars($booking['special_requests']) . '</p>
            </div>';
        }

        $htmlBody .= '
        <p>If you have any questions, please contact us at <a href="mailto:' . htmlspecialchars($email_from_email) . '">' . htmlspecialchars($email_from_email) . '</a> or call ' . getSetting('phone_main') . '.</p>

        <p>Thank you for choosing ' . htmlspecialchars($email_site_name) . '!</p>
        <p style="margin:28px 0 0;font-size:14px;color:#777;text-align:center;font-style:italic;">
            Warm regards &mdash; see you soon.
        </p>';

        // Send email
        return sendEmail(
            $booking['guest_email'],
            $booking['guest_name'],
            'Booking Received - ' . htmlspecialchars($email_site_name) . ' [' . $booking['booking_reference'] . ']',
            $htmlBody
        );
    } catch (Exception $e) {
        error_log("Send Booking Received Email Error: " . $e->getMessage());
        return [
            'success' => false,
            'message' => $e->getMessage()
        ];
    }
}

/**
 * Send booking confirmed email (sent when admin approves booking)
 */
function sendBookingConfirmedEmail(array $booking)
{
    global $pdo, $email_from_name, $email_from_email, $email_admin_email, $email_site_name, $email_site_url;

    try {
        // Get room details
        $stmt = $pdo->prepare("SELECT * FROM rooms WHERE id = ?");
        $stmt->execute([$booking['room_id']]);
        $room = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$room) {
            throw new Exception("Room not found");
        }

        $templateVars = buildBookingEmailVariables($booking, $room);
        $dbTemplate = renderBookingEmailTemplate('booking_confirmed', $templateVars);
        if ($dbTemplate) {
            return sendEmail(
                $booking['guest_email'],
                $booking['guest_name'],
                $dbTemplate['subject'],
                $dbTemplate['html_body'],
                $dbTemplate['text_body']
            );
        }

        // Prepare email content
        $htmlBody = '
        <h1 style="color: #8B7355; text-align: center;">Booking Confirmed!</h1>
        <p>Dear ' . htmlspecialchars($booking['guest_name']) . ',</p>
        <p>Great news! Your booking with <strong>' . htmlspecialchars($email_site_name) . '</strong> has been confirmed by our team.</p>

        <div style="background: #FAF6F0; border: 2px solid #C8A45A; padding: 20px; margin: 20px 0; border-radius: 10px;">
            <h2 style="color: #8B7355; margin-top: 0;;text-align:left;">Booking Details</h2>

            <table role="presentation" width="100%" cellspacing="0" cellpadding="0" style="margin:0;"><tr><td style="padding:10px 10px 10px 0;font-weight:bold;color:#1A1A1A;width:44%;vertical-align:top;font-family:\'Segoe UI\',Tahoma,Verdana,sans-serif;border-bottom:1px solid #e8e0d4;">Booking Reference:</td><td style="padding:10px 0 10px 6px;color: #8B7355; font-weight: bold; font-size: 18px;;text-align:left;vertical-align:top;font-family:\'Segoe UI\',Tahoma,Verdana,sans-serif;border-bottom:1px solid #e8e0d4;">' . htmlspecialchars($booking['booking_reference']) . '</td></tr></table>

            <table role="presentation" width="100%" cellspacing="0" cellpadding="0" style="margin:0;"><tr><td style="padding:10px 10px 10px 0;font-weight:bold;color:#1A1A1A;width:44%;vertical-align:top;font-family:\'Segoe UI\',Tahoma,Verdana,sans-serif;border-bottom:1px solid #e8e0d4;">Room:</td><td style="padding:10px 0 10px 6px;color: #333;;text-align:left;vertical-align:top;font-family:\'Segoe UI\',Tahoma,Verdana,sans-serif;border-bottom:1px solid #e8e0d4;">' . htmlspecialchars($room['name']) . '</td></tr></table>

            <table role="presentation" width="100%" cellspacing="0" cellpadding="0" style="margin:0;"><tr><td style="padding:10px 10px 10px 0;font-weight:bold;color:#1A1A1A;width:44%;vertical-align:top;font-family:\'Segoe UI\',Tahoma,Verdana,sans-serif;border-bottom:1px solid #e8e0d4;">Check-in Date:</td><td style="padding:10px 0 10px 6px;color: #333;;text-align:left;vertical-align:top;font-family:\'Segoe UI\',Tahoma,Verdana,sans-serif;border-bottom:1px solid #e8e0d4;">' . date('F j, Y', strtotime($booking['check_in_date'])) . '</td></tr></table>

            <table role="presentation" width="100%" cellspacing="0" cellpadding="0" style="margin:0;"><tr><td style="padding:10px 10px 10px 0;font-weight:bold;color:#1A1A1A;width:44%;vertical-align:top;font-family:\'Segoe UI\',Tahoma,Verdana,sans-serif;border-bottom:1px solid #e8e0d4;">Check-out Date:</td><td style="padding:10px 0 10px 6px;color: #333;;text-align:left;vertical-align:top;font-family:\'Segoe UI\',Tahoma,Verdana,sans-serif;border-bottom:1px solid #e8e0d4;">' . date('F j, Y', strtotime($booking['check_out_date'])) . '</td></tr></table>

            <table role="presentation" width="100%" cellspacing="0" cellpadding="0" style="margin:0;"><tr><td style="padding:10px 10px 10px 0;font-weight:bold;color:#1A1A1A;width:44%;vertical-align:top;font-family:\'Segoe UI\',Tahoma,Verdana,sans-serif;border-bottom:1px solid #e8e0d4;">Number of Nights:</td><td style="padding:10px 0 10px 6px;color: #333;;text-align:left;vertical-align:top;font-family:\'Segoe UI\',Tahoma,Verdana,sans-serif;border-bottom:1px solid #e8e0d4;">' . $booking['number_of_nights'] . ' night' . ($booking['number_of_nights'] != 1 ? 's' : '') . '</td></tr></table>

            <table role="presentation" width="100%" cellspacing="0" cellpadding="0" style="margin:0;"><tr><td style="padding:10px 10px 10px 0;font-weight:bold;color:#1A1A1A;width:44%;vertical-align:top;font-family:\'Segoe UI\',Tahoma,Verdana,sans-serif;border-bottom:1px solid #e8e0d4;">Number of Guests:</td><td style="padding:10px 0 10px 6px;color: #333;;text-align:left;vertical-align:top;font-family:\'Segoe UI\',Tahoma,Verdana,sans-serif;border-bottom:1px solid #e8e0d4;">' . $booking['number_of_guests'] . ' guest' . ($booking['number_of_guests'] != 1 ? 's' : '') . '</td></tr></table>

            <table role="presentation" width="100%" cellspacing="0" cellpadding="0" style="margin:0;"><tr><td style="padding:10px 10px 10px 0;font-weight:bold;color:#1A1A1A;width:44%;vertical-align:top;font-family:\'Segoe UI\',Tahoma,Verdana,sans-serif;">Total Amount:</td><td style="padding:10px 0 10px 6px;color: #8B7355; font-weight: bold; font-size: 18px;;text-align:left;vertical-align:top;font-family:\'Segoe UI\',Tahoma,Verdana,sans-serif;">' . getSetting('currency_symbol') . ' ' . number_format($booking['total_amount'], 0) . '</td></tr></table>
        </div>

        <div style="background: #d4edda; padding: 15px; border-left: 4px solid #28a745; border-radius: 5px; margin: 20px 0;">
            <h3 style="color: #155724; margin-top: 0;;text-align:left;">✅ Booking Status: Confirmed</h3>
            <p style="color: #155724; margin: 0;">
                <strong>Your booking is now confirmed and guaranteed!</strong><br>
                We look forward to welcoming you to ' . htmlspecialchars($email_site_name) . '.
            </p>
        </div>

        <div style="background: #fff3cd; padding: 15px; border-left: 4px solid #ffc107; border-radius: 5px; margin: 20px 0;">
            <h3 style="color: #856404; margin-top: 0;;text-align:left;">Payment Information</h3>
            <p style="color: #856404; margin: 0;">
                ' . getSetting('payment_policy', 'Payment will be made at the hotel upon arrival.<br>We accept cash payments only. Please bring the total amount of <strong>' . getSetting('currency_symbol') . ' ' . number_format($booking['total_amount'], 0) . '</strong> with you.') . '
            </p>
        </div>

        <div style="background: #FDF6EC; padding: 15px; border-left: 4px solid #8B7355; border-radius: 5px; margin: 20px 0;">
            <h3 style="color: #5C4A32; margin-top: 0;;text-align:left;">Next Steps</h3>
            <p style="color: #5C4A32; margin: 0;">
                <strong>Please save your booking reference:</strong> ' . htmlspecialchars($booking['booking_reference']) . '<br>
                <strong>Check-in time:</strong> ' . getSetting('check_in_time', '2:00 PM') . '<br>
                <strong>Check-out time:</strong> ' . getSetting('check_out_time', '11:00 AM') . '<br>
                <strong>Contact us:</strong> If you need to make any changes, please contact us at least ' . getSetting('booking_change_policy', '48 hours') . ' before your arrival.
            </p>
        </div>';

        if (!empty($booking['special_requests'])) {
            $htmlBody .= '
            <div style="background: #FDF6EC; padding: 15px; border-left: 4px solid #8B7355; border-radius: 5px; margin: 20px 0;">
                <h3 style="color: #5C4A32; margin-top: 0;;text-align:left;">Special Requests</h3>
                <p style="color: #5C4A32; margin: 0;">' . htmlspecialchars($booking['special_requests']) . '</p>
            </div>';
        }

        $htmlBody .= '
        <p>If you have any questions, please contact us at <a href="mailto:' . htmlspecialchars($email_from_email) . '">' . htmlspecialchars($email_from_email) . '</a> or call ' . getSetting('phone_main') . '.</p>

        <p>We look forward to welcoming you to ' . htmlspecialchars($email_site_name) . '!</p>
        <p style="margin:28px 0 0;font-size:14px;color:#777;text-align:center;font-style:italic;">
            Warm regards &mdash; see you soon.
        </p>';

        // Send email
        $emailResult = sendEmail(
            $booking['guest_email'],
            $booking['guest_name'],
            'Booking Confirmed - ' . htmlspecialchars($email_site_name) . ' [' . $booking['booking_reference'] . ']',
            $htmlBody
        );

        // Send WhatsApp notification if enabled
        $whatsappResult = ['guest' => ['success' => false, 'message' => 'WhatsApp not available'], 'hotel' => ['success' => false, 'message' => 'WhatsApp not available']];
        if (function_exists('sendBookingConfirmedWhatsApp') && function_exists('isWhatsAppEnabled') && isWhatsAppEnabled()) {
            $bookingForWhatsApp = $booking;
            $bookingForWhatsApp['room_name'] = $room['name'];
            $whatsappResult = sendBookingConfirmedWhatsApp($bookingForWhatsApp, $room);
        }

        return [
            'success' => $emailResult['success'],
            'message' => $emailResult['message'],
            'whatsapp_guest_sent' => $whatsappResult['guest']['success'] ?? false,
            'whatsapp_hotel_sent' => $whatsappResult['hotel']['success'] ?? false
        ];
    } catch (Exception $e) {
        error_log("Send Booking Confirmed Email Error: " . $e->getMessage());
        return [
            'success' => false,
            'message' => $e->getMessage()
        ];
    }
}

/**
 * Send booking modified email (sent when admin edits a booking)
 */
function sendBookingModifiedEmail(array $booking, array $changes = [])
{
    global $pdo, $email_from_name, $email_from_email, $email_admin_email, $email_site_name, $email_site_url;

    try {
        $stmt = $pdo->prepare("SELECT * FROM rooms WHERE id = ?");
        $stmt->execute([$booking['room_id']]);
        $room = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$room) {
            throw new Exception("Room not found");
        }

        $changesHtml = '';
        if (!empty($changes)) {
            $changesHtml = '
            <div style="background: #FDF6EC; padding: 15px; border-left: 4px solid #8B7355; border-radius: 5px; margin: 20px 0;">
                <h3 style="color: #5C4A32; margin-top: 0;;text-align:left;">What Changed</h3>
                <table style="width: 100%; border-collapse: collapse; font-size: 14px;">';

            $fieldLabels = [
                'room' => 'Room',
                'check_in_date' => 'Check-in Date',
                'check_out_date' => 'Check-out Date',
                'number_of_nights' => 'Number of Nights',
                'number_of_guests' => 'Number of Guests',
                'occupancy_type' => 'Occupancy Type',
                'total_amount' => 'Total Amount',
                'guest_name' => 'Guest Name',
                'guest_email' => 'Email',
                'guest_phone' => 'Phone',
                'guest_country' => 'Country'
            ];

            foreach ($changes as $field => $change) {
                $label = $fieldLabels[$field] ?? ucfirst(str_replace('_', ' ', $field));
                $changesHtml .= '
                    <tr style="border-bottom: 1px solid #bbdefb;">
                        <td style="padding: 8px; font-weight: bold; color: #8B7355; width: 40%;">' . htmlspecialchars($label) . '</td>
                        <td style="padding: 8px; color: #999; text-decoration: line-through;">' . htmlspecialchars($change['old']) . '</td>
                        <td style="padding: 8px; color: #155724; font-weight: 600;">' . htmlspecialchars($change['new']) . '</td>
                    </tr>';
            }
            $changesHtml .= '</table></div>';
        }

        $htmlBody = '
        <h1 style="color: #8B7355; text-align: center;">Booking Updated</h1>
        <p>Dear ' . htmlspecialchars($booking['guest_name']) . ',</p>
        <p>Your booking with <strong>' . htmlspecialchars($email_site_name) . '</strong> has been updated by our team. Please review the details below.</p>
        ' . $changesHtml . '
        <div style="background: #FAF6F0; border: 2px solid #C8A45A; padding: 20px; margin: 20px 0; border-radius: 10px;">
            <h2 style="color: #8B7355; margin-top: 0;;text-align:left;">Updated Booking Details</h2>
            <table role="presentation" width="100%" cellspacing="0" cellpadding="0" style="margin:0;"><tr><td style="padding:10px 10px 10px 0;font-weight:bold;color:#1A1A1A;width:44%;vertical-align:top;font-family:\'Segoe UI\',Tahoma,Verdana,sans-serif;border-bottom:1px solid #e8e0d4;">Booking Reference:</td><td style="padding:10px 0 10px 6px;color: #8B7355; font-weight: bold; font-size: 18px;;text-align:left;vertical-align:top;font-family:\'Segoe UI\',Tahoma,Verdana,sans-serif;border-bottom:1px solid #e8e0d4;">' . htmlspecialchars($booking['booking_reference']) . '</td></tr></table>
            <table role="presentation" width="100%" cellspacing="0" cellpadding="0" style="margin:0;"><tr><td style="padding:10px 10px 10px 0;font-weight:bold;color:#1A1A1A;width:44%;vertical-align:top;font-family:\'Segoe UI\',Tahoma,Verdana,sans-serif;border-bottom:1px solid #e8e0d4;">Room:</td><td style="padding:10px 0 10px 6px;color: #333;;text-align:left;vertical-align:top;font-family:\'Segoe UI\',Tahoma,Verdana,sans-serif;border-bottom:1px solid #e8e0d4;">' . htmlspecialchars($room['name']) . '</td></tr></table>
            <table role="presentation" width="100%" cellspacing="0" cellpadding="0" style="margin:0;"><tr><td style="padding:10px 10px 10px 0;font-weight:bold;color:#1A1A1A;width:44%;vertical-align:top;font-family:\'Segoe UI\',Tahoma,Verdana,sans-serif;border-bottom:1px solid #e8e0d4;">Check-in Date:</td><td style="padding:10px 0 10px 6px;color: #333;;text-align:left;vertical-align:top;font-family:\'Segoe UI\',Tahoma,Verdana,sans-serif;border-bottom:1px solid #e8e0d4;">' . date('F j, Y', strtotime($booking['check_in_date'])) . '</td></tr></table>
            <table role="presentation" width="100%" cellspacing="0" cellpadding="0" style="margin:0;"><tr><td style="padding:10px 10px 10px 0;font-weight:bold;color:#1A1A1A;width:44%;vertical-align:top;font-family:\'Segoe UI\',Tahoma,Verdana,sans-serif;border-bottom:1px solid #e8e0d4;">Check-out Date:</td><td style="padding:10px 0 10px 6px;color: #333;;text-align:left;vertical-align:top;font-family:\'Segoe UI\',Tahoma,Verdana,sans-serif;border-bottom:1px solid #e8e0d4;">' . date('F j, Y', strtotime($booking['check_out_date'])) . '</td></tr></table>
            <table role="presentation" width="100%" cellspacing="0" cellpadding="0" style="margin:0;"><tr><td style="padding:10px 10px 10px 0;font-weight:bold;color:#1A1A1A;width:44%;vertical-align:top;font-family:\'Segoe UI\',Tahoma,Verdana,sans-serif;border-bottom:1px solid #e8e0d4;">Number of Nights:</td><td style="padding:10px 0 10px 6px;color: #333;;text-align:left;vertical-align:top;font-family:\'Segoe UI\',Tahoma,Verdana,sans-serif;border-bottom:1px solid #e8e0d4;">' . $booking['number_of_nights'] . ' night' . ($booking['number_of_nights'] != 1 ? 's' : '') . '</td></tr></table>
            <table role="presentation" width="100%" cellspacing="0" cellpadding="0" style="margin:0;"><tr><td style="padding:10px 10px 10px 0;font-weight:bold;color:#1A1A1A;width:44%;vertical-align:top;font-family:\'Segoe UI\',Tahoma,Verdana,sans-serif;border-bottom:1px solid #e8e0d4;">Number of Guests:</td><td style="padding:10px 0 10px 6px;color: #333;;text-align:left;vertical-align:top;font-family:\'Segoe UI\',Tahoma,Verdana,sans-serif;border-bottom:1px solid #e8e0d4;">' . $booking['number_of_guests'] . ' guest' . ($booking['number_of_guests'] != 1 ? 's' : '') . '</td></tr></table>
            <table role="presentation" width="100%" cellspacing="0" cellpadding="0" style="margin:0;"><tr><td style="padding:10px 10px 10px 0;font-weight:bold;color:#1A1A1A;width:44%;vertical-align:top;font-family:\'Segoe UI\',Tahoma,Verdana,sans-serif;">Total Amount:</td><td style="padding:10px 0 10px 6px;color: #8B7355; font-weight: bold; font-size: 18px;;text-align:left;vertical-align:top;font-family:\'Segoe UI\',Tahoma,Verdana,sans-serif;">' . getSetting('currency_symbol') . ' ' . number_format($booking['total_amount'], 0) . '</td></tr></table>
        </div>
        <div style="background: #d4edda; padding: 15px; border-left: 4px solid #28a745; border-radius: 5px; margin: 20px 0;">
            <h3 style="color: #155724; margin-top: 0;;text-align:left;">Next Steps</h3>
            <p style="color: #155724; margin: 0;">
                <strong>Check-in time:</strong> ' . getSetting('check_in_time', '2:00 PM') . '<br>
                <strong>Check-out time:</strong> ' . getSetting('check_out_time', '11:00 AM') . '<br>
                <strong>Contact us:</strong> If you have questions about these changes, please reach out.
            </p>
        </div>
        <p>If you have any questions, please contact us at <a href="mailto:' . htmlspecialchars($email_from_email) . '">' . htmlspecialchars($email_from_email) . '</a> or call ' . getSetting('phone_main') . '.</p>
        <p>Thank you for choosing ' . htmlspecialchars($email_site_name) . '!</p>
        <p style="margin:28px 0 0;font-size:14px;color:#777;text-align:center;font-style:italic;">
            Warm regards &mdash; see you soon.
        </p>';

        return sendEmail(
            $booking['guest_email'],
            $booking['guest_name'],
            'Booking Updated - ' . htmlspecialchars($email_site_name) . ' [' . $booking['booking_reference'] . ']',
            $htmlBody
        );
    } catch (Exception $e) {
        error_log("Send Booking Modified Email Error: " . $e->getMessage());
        return ['success' => false, 'message' => $e->getMessage()];
    }
}

/**
 * Send admin notification email
 */
function sendAdminNotificationEmail(array $booking)
{
    global $email_from_name, $email_from_email, $email_admin_email, $email_site_name, $email_site_url;

    try {
        $notificationEmail = trim((string)getSetting('booking_notification_email', ''));
        if (empty($notificationEmail) || !filter_var($notificationEmail, FILTER_VALIDATE_EMAIL)) {
            $notificationEmail = trim((string)getSetting('admin_notification_email', ''));
        }
        if (empty($notificationEmail) || !filter_var($notificationEmail, FILTER_VALIDATE_EMAIL)) {
            $notificationEmail = trim((string)$email_admin_email);
        }

        if (empty($notificationEmail) || !filter_var($notificationEmail, FILTER_VALIDATE_EMAIL)) {
            return [
                'success' => false,
                'message' => 'No valid booking notification email configured'
            ];
        }

        $adminBaseUrl = rtrim((string)$email_site_url, '/');
        $bookingId = (int)($booking['id'] ?? 0);
        $bookingReference = trim((string)($booking['booking_reference'] ?? ''));
        $adminBookingUrl = $adminBaseUrl . '/admin/bookings.php';
        if ($bookingId > 0) {
            $adminBookingUrl = $adminBaseUrl . '/admin/booking-details.php?id=' . $bookingId;
        } elseif ($bookingReference !== '') {
            $adminBookingUrl = $adminBaseUrl . '/admin/bookings.php?search=' . rawurlencode($bookingReference);
        }

        $htmlBody = '
        <h1 style="color: #8B7355; text-align: center;">📋 New Booking Received</h1>
        <p>A new booking has been made on the website.</p>

        <div style="background: #FAF6F0; border: 2px solid #C8A45A; padding: 20px; margin: 20px 0; border-radius: 10px;">
            <h2 style="color: #8B7355; margin-top: 0;;text-align:left;">Booking Details</h2>

            <table role="presentation" width="100%" cellspacing="0" cellpadding="0" style="margin:0;"><tr><td style="padding:10px 10px 10px 0;font-weight:bold;color:#1A1A1A;width:44%;vertical-align:top;font-family:\'Segoe UI\',Tahoma,Verdana,sans-serif;border-bottom:1px solid #e8e0d4;">Booking Reference:</td><td style="padding:10px 0 10px 6px;color: #8B7355; font-weight: bold;;text-align:left;vertical-align:top;font-family:\'Segoe UI\',Tahoma,Verdana,sans-serif;border-bottom:1px solid #e8e0d4;">' . htmlspecialchars($booking['booking_reference']) . '</td></tr></table>

            <table role="presentation" width="100%" cellspacing="0" cellpadding="0" style="margin:0;"><tr><td style="padding:10px 10px 10px 0;font-weight:bold;color:#1A1A1A;width:44%;vertical-align:top;font-family:\'Segoe UI\',Tahoma,Verdana,sans-serif;border-bottom:1px solid #e8e0d4;">Guest Name:</td><td style="padding:10px 0 10px 6px;color: #333;;text-align:left;vertical-align:top;font-family:\'Segoe UI\',Tahoma,Verdana,sans-serif;border-bottom:1px solid #e8e0d4;">' . htmlspecialchars($booking['guest_name']) . '</td></tr></table>

            <table role="presentation" width="100%" cellspacing="0" cellpadding="0" style="margin:0;"><tr><td style="padding:10px 10px 10px 0;font-weight:bold;color:#1A1A1A;width:44%;vertical-align:top;font-family:\'Segoe UI\',Tahoma,Verdana,sans-serif;border-bottom:1px solid #e8e0d4;">Guest Email:</td><td style="padding:10px 0 10px 6px;color: #333;;text-align:left;vertical-align:top;font-family:\'Segoe UI\',Tahoma,Verdana,sans-serif;border-bottom:1px solid #e8e0d4;">' . htmlspecialchars($booking['guest_email']) . '</td></tr></table>

            <table role="presentation" width="100%" cellspacing="0" cellpadding="0" style="margin:0;"><tr><td style="padding:10px 10px 10px 0;font-weight:bold;color:#1A1A1A;width:44%;vertical-align:top;font-family:\'Segoe UI\',Tahoma,Verdana,sans-serif;border-bottom:1px solid #e8e0d4;">Guest Phone:</td><td style="padding:10px 0 10px 6px;color: #333;;text-align:left;vertical-align:top;font-family:\'Segoe UI\',Tahoma,Verdana,sans-serif;border-bottom:1px solid #e8e0d4;">' . htmlspecialchars($booking['guest_phone']) . '</td></tr></table>

            <table role="presentation" width="100%" cellspacing="0" cellpadding="0" style="margin:0;"><tr><td style="padding:10px 10px 10px 0;font-weight:bold;color:#1A1A1A;width:44%;vertical-align:top;font-family:\'Segoe UI\',Tahoma,Verdana,sans-serif;border-bottom:1px solid #e8e0d4;">Check-in Date:</td><td style="padding:10px 0 10px 6px;color: #333;;text-align:left;vertical-align:top;font-family:\'Segoe UI\',Tahoma,Verdana,sans-serif;border-bottom:1px solid #e8e0d4;">' . date('F j, Y', strtotime($booking['check_in_date'])) . '</td></tr></table>

            <table role="presentation" width="100%" cellspacing="0" cellpadding="0" style="margin:0;"><tr><td style="padding:10px 10px 10px 0;font-weight:bold;color:#1A1A1A;width:44%;vertical-align:top;font-family:\'Segoe UI\',Tahoma,Verdana,sans-serif;border-bottom:1px solid #e8e0d4;">Check-out Date:</td><td style="padding:10px 0 10px 6px;color: #333;;text-align:left;vertical-align:top;font-family:\'Segoe UI\',Tahoma,Verdana,sans-serif;border-bottom:1px solid #e8e0d4;">' . date('F j, Y', strtotime($booking['check_out_date'])) . '</td></tr></table>

            <table role="presentation" width="100%" cellspacing="0" cellpadding="0" style="margin:0;"><tr><td style="padding:10px 10px 10px 0;font-weight:bold;color:#1A1A1A;width:44%;vertical-align:top;font-family:\'Segoe UI\',Tahoma,Verdana,sans-serif;">Total Amount:</td><td style="padding:10px 0 10px 6px;color: #8B7355; font-weight: bold;;text-align:left;vertical-align:top;font-family:\'Segoe UI\',Tahoma,Verdana,sans-serif;">' . getSetting('currency_symbol') . ' ' . number_format($booking['total_amount'], 0) . '</td></tr></table>
        </div>

        <div style="text-align: center; margin-top: 30px;">
            <a href="' . htmlspecialchars($adminBookingUrl) . '" style="display: inline-block; background: #8B7355; color: #1A1A1A; padding: 12px 24px; text-decoration: none; border-radius: 5px; font-weight: bold; font-size: 16px;">
                Open Booking in Admin Panel
            </a>
        </div>';

        // Send email
        $primaryResult = sendEmail(
            $notificationEmail,
            'Reservations Team',
            'New Booking - ' . htmlspecialchars($email_site_name) . ' [' . $booking['booking_reference'] . ']',
            $htmlBody
        );

        // Optional CC notifications configured from booking settings
        $ccRaw = trim((string)getSetting('booking_notification_cc_emails', ''));
        if (!empty($ccRaw)) {
            $ccList = array_filter(array_map('trim', explode(',', $ccRaw)));
            foreach ($ccList as $ccEmail) {
                if (!filter_var($ccEmail, FILTER_VALIDATE_EMAIL)) {
                    continue;
                }

                // avoid duplicate send to primary recipient
                if (strcasecmp($ccEmail, $notificationEmail) === 0) {
                    continue;
                }

                sendEmail(
                    $ccEmail,
                    'Reservations Team',
                    'New Booking - ' . htmlspecialchars($email_site_name) . ' [' . $booking['booking_reference'] . ']',
                    $htmlBody
                );
            }
        }

        return $primaryResult;
    } catch (Exception $e) {
        error_log("Send Admin Notification Error: " . $e->getMessage());
        return [
            'success' => false,
            'message' => $e->getMessage()
        ];
    }
}

/**
 * Send conference enquiry email (sent when customer submits enquiry)
 */
function sendConferenceEnquiryEmail(array $data)
{
    global $pdo, $email_from_name, $email_from_email, $email_admin_email, $email_site_name, $email_site_url;

    try {
        // Get conference room details
        $stmt = $pdo->prepare("SELECT * FROM conference_rooms WHERE id = ?");
        $stmt->execute([$data['conference_room_id']]);
        $room = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$room) {
            throw new Exception("Conference room not found");
        }

        $currency_symbol = getSetting('currency_symbol');
        $total_amount = $data['total_amount'] ? number_format($data['total_amount'], 0) : 'To be determined';

        // Prepare template data
        $template_data = [
            'contact_person' => $data['contact_person'],
            'inquiry_reference' => $data['inquiry_reference'],
            'company_name' => $data['company_name'],
            'room_name' => $room['name'],
            'event_date' => date('F j, Y', strtotime($data['event_date'])),
            'start_time' => date('H:i', strtotime($data['start_time'])),
            'end_time' => date('H:i', strtotime($data['end_time'])),
            'number_of_attendees' => (int)$data['number_of_attendees'],
            'total_amount' => $total_amount,
            'event_type' => $data['event_type'] ?? '',
            'catering_required' => $data['catering_required'] ?? false,
            'av_equipment' => $data['av_equipment'] ?? '',
            'special_requirements' => $data['special_requirements'] ?? ''
        ];

        // Try to load template, fall back to hardcoded HTML if template not found
        $htmlBody = loadEmailTemplate('conference-enquiry-customer.html', $template_data);

        if (empty($htmlBody)) {
            // Fallback to hardcoded HTML if template fails
            $htmlBody = '
            <h1 style="color: #8B7355; text-align: center;">Conference Enquiry Received</h1>
            <p>Dear ' . htmlspecialchars($data['contact_person']) . ',</p>
            <p>Thank you for your conference enquiry with <strong>' . htmlspecialchars($email_site_name) . '</strong>. We have received your request and it is currently being reviewed by our team.</p>

            <div style="background: #FAF6F0; border: 2px solid #C8A45A; padding: 20px; margin: 20px 0; border-radius: 10px;">
                <h2 style="color: #8B7355; margin-top: 0;;text-align:left;">Enquiry Details</h2>

                <table role="presentation" width="100%" cellspacing="0" cellpadding="0" style="margin:0;"><tr><td style="padding:10px 10px 10px 0;font-weight:bold;color:#1A1A1A;width:44%;vertical-align:top;font-family:\'Segoe UI\',Tahoma,Verdana,sans-serif;border-bottom:1px solid #e8e0d4;">Enquiry Reference:</td><td style="padding:10px 0 10px 6px;color: #8B7355; font-weight: bold; font-size: 18px;;text-align:left;vertical-align:top;font-family:\'Segoe UI\',Tahoma,Verdana,sans-serif;border-bottom:1px solid #e8e0d4;">' . htmlspecialchars($data['inquiry_reference']) . '</td></tr></table>

                <table role="presentation" width="100%" cellspacing="0" cellpadding="0" style="margin:0;"><tr><td style="padding:10px 10px 10px 0;font-weight:bold;color:#1A1A1A;width:44%;vertical-align:top;font-family:\'Segoe UI\',Tahoma,Verdana,sans-serif;border-bottom:1px solid #e8e0d4;">Company:</td><td style="padding:10px 0 10px 6px;color: #333;;text-align:left;vertical-align:top;font-family:\'Segoe UI\',Tahoma,Verdana,sans-serif;border-bottom:1px solid #e8e0d4;">' . htmlspecialchars($data['company_name']) . '</td></tr></table>

                <table role="presentation" width="100%" cellspacing="0" cellpadding="0" style="margin:0;"><tr><td style="padding:10px 10px 10px 0;font-weight:bold;color:#1A1A1A;width:44%;vertical-align:top;font-family:\'Segoe UI\',Tahoma,Verdana,sans-serif;border-bottom:1px solid #e8e0d4;">Conference Room:</td><td style="padding:10px 0 10px 6px;color: #333;;text-align:left;vertical-align:top;font-family:\'Segoe UI\',Tahoma,Verdana,sans-serif;border-bottom:1px solid #e8e0d4;">' . htmlspecialchars($room['name']) . '</td></tr></table>

                <table role="presentation" width="100%" cellspacing="0" cellpadding="0" style="margin:0;"><tr><td style="padding:10px 10px 10px 0;font-weight:bold;color:#1A1A1A;width:44%;vertical-align:top;font-family:\'Segoe UI\',Tahoma,Verdana,sans-serif;border-bottom:1px solid #e8e0d4;">Event Date:</td><td style="padding:10px 0 10px 6px;color: #333;;text-align:left;vertical-align:top;font-family:\'Segoe UI\',Tahoma,Verdana,sans-serif;border-bottom:1px solid #e8e0d4;">' . date('F j, Y', strtotime($data['event_date'])) . '</td></tr></table>

                <table role="presentation" width="100%" cellspacing="0" cellpadding="0" style="margin:0;"><tr><td style="padding:10px 10px 10px 0;font-weight:bold;color:#1A1A1A;width:44%;vertical-align:top;font-family:\'Segoe UI\',Tahoma,Verdana,sans-serif;border-bottom:1px solid #e8e0d4;">Event Time:</td><td style="padding:10px 0 10px 6px;color: #333;;text-align:left;vertical-align:top;font-family:\'Segoe UI\',Tahoma,Verdana,sans-serif;border-bottom:1px solid #e8e0d4;">' . date('H:i', strtotime($data['start_time'])) . ' - ' . date('H:i', strtotime($data['end_time'])) . '</td></tr></table>

                <table role="presentation" width="100%" cellspacing="0" cellpadding="0" style="margin:0;"><tr><td style="padding:10px 10px 10px 0;font-weight:bold;color:#1A1A1A;width:44%;vertical-align:top;font-family:\'Segoe UI\',Tahoma,Verdana,sans-serif;border-bottom:1px solid #e8e0d4;">Number of Attendees:</td><td style="padding:10px 0 10px 6px;color: #333;;text-align:left;vertical-align:top;font-family:\'Segoe UI\',Tahoma,Verdana,sans-serif;border-bottom:1px solid #e8e0d4;">' . (int) $data['number_of_attendees'] . '</td></tr></table>

                <table role="presentation" width="100%" cellspacing="0" cellpadding="0" style="margin:0;"><tr><td style="padding:10px 10px 10px 0;font-weight:bold;color:#1A1A1A;width:44%;vertical-align:top;font-family:\'Segoe UI\',Tahoma,Verdana,sans-serif;">Estimated Amount:</td><td style="padding:10px 0 10px 6px;color: #8B7355; font-weight: bold; font-size: 18px;;text-align:left;vertical-align:top;font-family:\'Segoe UI\',Tahoma,Verdana,sans-serif;">' . $currency_symbol . ' ' . $total_amount . '</td></tr></table>
            </div>

            <div style="background: #FDF6EC; padding: 15px; border-left: 4px solid #8B7355; border-radius: 5px; margin: 20px 0;">
                <h3 style="color: #5C4A32; margin-top: 0;;text-align:left;">What Happens Next?</h3>
                <p style="color: #5C4A32; margin: 0;">
                    <strong>Our team will review your enquiry and contact you within 24 hours to confirm availability and finalize details.</strong><br>
                    Once confirmed, you will receive a second email with final confirmation and payment instructions.
                </p>
            </div>';

            if (!empty($data['event_type'])) {
                $htmlBody .= '
                <div style="background: #FDF6EC; padding: 15px; border-left: 4px solid #8B7355; border-radius: 5px; margin: 20px 0;">
                    <h3 style="color: #5C4A32; margin-top: 0;;text-align:left;">Event Type</h3>
                    <p style="color: #5C4A32; margin: 0;">' . htmlspecialchars($data['event_type']) . '</p>
                </div>';
            }

            if ($data['catering_required']) {
                $htmlBody .= '
                <div style="background: #FDF6EC; padding: 15px; border-left: 4px solid #8B7355; border-radius: 5px; margin: 20px 0;">
                    <h3 style="color: #5C4A32; margin-top: 0;;text-align:left;">Catering</h3>
                    <p style="color: #5C4A32; margin: 0;">Catering services have been requested for your event.</p>
                </div>';
            }

            if (!empty($data['av_equipment'])) {
                $htmlBody .= '
                <div style="background: #FDF6EC; padding: 15px; border-left: 4px solid #8B7355; border-radius: 5px; margin: 20px 0;">
                    <h3 style="color: #5C4A32; margin-top: 0;;text-align:left;">AV Equipment Requirements</h3>
                    <p style="color: #5C4A32; margin: 0;">' . htmlspecialchars($data['av_equipment']) . '</p>
                </div>';
            }

            if (!empty($data['special_requirements'])) {
                $htmlBody .= '
                <div style="background: #FDF6EC; padding: 15px; border-left: 4px solid #8B7355; border-radius: 5px; margin: 20px 0;">
                    <h3 style="color: #5C4A32; margin-top: 0;;text-align:left;">Special Requirements</h3>
                    <p style="color: #5C4A32; margin: 0;">' . htmlspecialchars($data['special_requirements']) . '</p>
                </div>';
            }

            $htmlBody .= '
            <p>If you have any questions, please contact us at <a href="mailto:' . htmlspecialchars($email_from_email) . '">' . htmlspecialchars($email_from_email) . '</a> or call ' . getSetting('phone_main') . '.</p>

            <p>Thank you for considering ' . htmlspecialchars($email_site_name) . ' for your event!</p>
        <p style="margin:28px 0 0;font-size:14px;color:#777;text-align:center;font-style:italic;">
            Warm regards &mdash; see you soon.
        </p>';
        }

        // Send email
        return sendEmail(
            $data['email'],
            $data['contact_person'],
            'Conference Enquiry Received - ' . htmlspecialchars($email_site_name) . ' [' . $data['inquiry_reference'] . ']',
            $htmlBody
        );
    } catch (Exception $e) {
        error_log("Send Conference Enquiry Email Error: " . $e->getMessage());
        return [
            'success' => false,
            'message' => $e->getMessage()
        ];
    }
}

/**
 * Send admin notification for conference enquiry
 */
function sendConferenceAdminNotificationEmail(array $data)
{
    global $pdo, $email_from_name, $email_from_email, $email_admin_email, $email_site_name, $email_site_url;

    try {
        // Get conference room details
        $stmt = $pdo->prepare("SELECT * FROM conference_rooms WHERE id = ?");
        $stmt->execute([$data['conference_room_id']]);
        $room = $stmt->fetch(PDO::FETCH_ASSOC);

        $currency_symbol = getSetting('currency_symbol');
        $total_amount = $data['total_amount'] ? number_format($data['total_amount'], 0) : 'To be determined';

        // Get conference email with fallback to main contact email
        $conference_email = getSetting('conference_email');
        if (empty($conference_email) || !filter_var($conference_email, FILTER_VALIDATE_EMAIL)) {
            $conference_email = getSetting('email_main');
        }
        if (empty($conference_email) || !filter_var($conference_email, FILTER_VALIDATE_EMAIL)) {
            $conference_email = $email_admin_email;
        }

        // Prepare template data
        $template_data = [
            'inquiry_reference' => $data['inquiry_reference'],
            'company_name' => $data['company_name'],
            'contact_person' => $data['contact_person'],
            'email' => $data['email'],
            'phone' => $data['phone'],
            'room_name' => $room['name'],
            'event_date' => date('F j, Y', strtotime($data['event_date'])),
            'start_time' => date('H:i', strtotime($data['start_time'])),
            'end_time' => date('H:i', strtotime($data['end_time'])),
            'number_of_attendees' => (int)$data['number_of_attendees'],
            'event_type' => $data['event_type'] ?: 'Not specified',
            'total_amount' => $total_amount,
            'catering_required' => $data['catering_required'] ?? false,
            'av_equipment' => $data['av_equipment'] ?? '',
            'special_requirements' => $data['special_requirements'] ?? ''
        ];

        // Try to load template, fall back to hardcoded HTML if template not found
        $htmlBody = loadEmailTemplate('conference-enquiry-admin.html', $template_data);

        if (empty($htmlBody)) {
            // Fallback to hardcoded HTML if template fails
            $htmlBody = '
            <h1 style="color: #8B7355; text-align: center;">📋 New Conference Enquiry Received</h1>
            <p>A new conference enquiry has been submitted on the website.</p>

            <div style="background: #FAF6F0; border: 2px solid #C8A45A; padding: 20px; margin: 20px 0; border-radius: 10px;">
                <h2 style="color: #8B7355; margin-top: 0;;text-align:left;">Enquiry Details</h2>

                <table role="presentation" width="100%" cellspacing="0" cellpadding="0" style="margin:0;"><tr><td style="padding:10px 10px 10px 0;font-weight:bold;color:#1A1A1A;width:44%;vertical-align:top;font-family:\'Segoe UI\',Tahoma,Verdana,sans-serif;border-bottom:1px solid #e8e0d4;">Enquiry Reference:</td><td style="padding:10px 0 10px 6px;color: #8B7355; font-weight: bold;;text-align:left;vertical-align:top;font-family:\'Segoe UI\',Tahoma,Verdana,sans-serif;border-bottom:1px solid #e8e0d4;">' . htmlspecialchars($data['inquiry_reference']) . '</td></tr></table>

                <table role="presentation" width="100%" cellspacing="0" cellpadding="0" style="margin:0;"><tr><td style="padding:10px 10px 10px 0;font-weight:bold;color:#1A1A1A;width:44%;vertical-align:top;font-family:\'Segoe UI\',Tahoma,Verdana,sans-serif;border-bottom:1px solid #e8e0d4;">Company:</td><td style="padding:10px 0 10px 6px;color: #333;;text-align:left;vertical-align:top;font-family:\'Segoe UI\',Tahoma,Verdana,sans-serif;border-bottom:1px solid #e8e0d4;">' . htmlspecialchars($data['company_name']) . '</td></tr></table>

                <table role="presentation" width="100%" cellspacing="0" cellpadding="0" style="margin:0;"><tr><td style="padding:10px 10px 10px 0;font-weight:bold;color:#1A1A1A;width:44%;vertical-align:top;font-family:\'Segoe UI\',Tahoma,Verdana,sans-serif;border-bottom:1px solid #e8e0d4;">Contact Person:</td><td style="padding:10px 0 10px 6px;color: #333;;text-align:left;vertical-align:top;font-family:\'Segoe UI\',Tahoma,Verdana,sans-serif;border-bottom:1px solid #e8e0d4;">' . htmlspecialchars($data['contact_person']) . '</td></tr></table>

                <table role="presentation" width="100%" cellspacing="0" cellpadding="0" style="margin:0;"><tr><td style="padding:10px 10px 10px 0;font-weight:bold;color:#1A1A1A;width:44%;vertical-align:top;font-family:\'Segoe UI\',Tahoma,Verdana,sans-serif;border-bottom:1px solid #e8e0d4;">Email:</td><td style="padding:10px 0 10px 6px;color: #333;;text-align:left;vertical-align:top;font-family:\'Segoe UI\',Tahoma,Verdana,sans-serif;border-bottom:1px solid #e8e0d4;">' . htmlspecialchars($data['email']) . '</td></tr></table>

                <table role="presentation" width="100%" cellspacing="0" cellpadding="0" style="margin:0;"><tr><td style="padding:10px 10px 10px 0;font-weight:bold;color:#1A1A1A;width:44%;vertical-align:top;font-family:\'Segoe UI\',Tahoma,Verdana,sans-serif;border-bottom:1px solid #e8e0d4;">Phone:</td><td style="padding:10px 0 10px 6px;color: #333;;text-align:left;vertical-align:top;font-family:\'Segoe UI\',Tahoma,Verdana,sans-serif;border-bottom:1px solid #e8e0d4;">' . htmlspecialchars($data['phone']) . '</td></tr></table>

                <table role="presentation" width="100%" cellspacing="0" cellpadding="0" style="margin:0;"><tr><td style="padding:10px 10px 10px 0;font-weight:bold;color:#1A1A1A;width:44%;vertical-align:top;font-family:\'Segoe UI\',Tahoma,Verdana,sans-serif;border-bottom:1px solid #e8e0d4;">Conference Room:</td><td style="padding:10px 0 10px 6px;color: #333;;text-align:left;vertical-align:top;font-family:\'Segoe UI\',Tahoma,Verdana,sans-serif;border-bottom:1px solid #e8e0d4;">' . htmlspecialchars($room['name']) . '</td></tr></table>

                <table role="presentation" width="100%" cellspacing="0" cellpadding="0" style="margin:0;"><tr><td style="padding:10px 10px 10px 0;font-weight:bold;color:#1A1A1A;width:44%;vertical-align:top;font-family:\'Segoe UI\',Tahoma,Verdana,sans-serif;border-bottom:1px solid #e8e0d4;">Event Date:</td><td style="padding:10px 0 10px 6px;color: #333;;text-align:left;vertical-align:top;font-family:\'Segoe UI\',Tahoma,Verdana,sans-serif;border-bottom:1px solid #e8e0d4;">' . date('F j, Y', strtotime($data['event_date'])) . '</td></tr></table>

                <table role="presentation" width="100%" cellspacing="0" cellpadding="0" style="margin:0;"><tr><td style="padding:10px 10px 10px 0;font-weight:bold;color:#1A1A1A;width:44%;vertical-align:top;font-family:\'Segoe UI\',Tahoma,Verdana,sans-serif;border-bottom:1px solid #e8e0d4;">Event Time:</td><td style="padding:10px 0 10px 6px;color: #333;;text-align:left;vertical-align:top;font-family:\'Segoe UI\',Tahoma,Verdana,sans-serif;border-bottom:1px solid #e8e0d4;">' . date('H:i', strtotime($data['start_time'])) . ' - ' . date('H:i', strtotime($data['end_time'])) . '</td></tr></table>

                <table role="presentation" width="100%" cellspacing="0" cellpadding="0" style="margin:0;"><tr><td style="padding:10px 10px 10px 0;font-weight:bold;color:#1A1A1A;width:44%;vertical-align:top;font-family:\'Segoe UI\',Tahoma,Verdana,sans-serif;border-bottom:1px solid #e8e0d4;">Number of Attendees:</td><td style="padding:10px 0 10px 6px;color: #333;;text-align:left;vertical-align:top;font-family:\'Segoe UI\',Tahoma,Verdana,sans-serif;border-bottom:1px solid #e8e0d4;">' . (int) $data['number_of_attendees'] . '</td></tr></table>

                <table role="presentation" width="100%" cellspacing="0" cellpadding="0" style="margin:0;"><tr><td style="padding:10px 10px 10px 0;font-weight:bold;color:#1A1A1A;width:44%;vertical-align:top;font-family:\'Segoe UI\',Tahoma,Verdana,sans-serif;border-bottom:1px solid #e8e0d4;">Event Type:</td><td style="padding:10px 0 10px 6px;color: #333;;text-align:left;vertical-align:top;font-family:\'Segoe UI\',Tahoma,Verdana,sans-serif;border-bottom:1px solid #e8e0d4;">' . htmlspecialchars($data['event_type'] ?: 'Not specified') . '</td></tr></table>

                <table role="presentation" width="100%" cellspacing="0" cellpadding="0" style="margin:0;"><tr><td style="padding:10px 10px 10px 0;font-weight:bold;color:#1A1A1A;width:44%;vertical-align:top;font-family:\'Segoe UI\',Tahoma,Verdana,sans-serif;">Estimated Amount:</td><td style="padding:10px 0 10px 6px;color: #8B7355; font-weight: bold;;text-align:left;vertical-align:top;font-family:\'Segoe UI\',Tahoma,Verdana,sans-serif;">' . $currency_symbol . ' ' . $total_amount . '</td></tr></table>
            </div>';

            if ($data['catering_required']) {
                $htmlBody .= '
                <div style="background: #FDF6EC; padding: 15px; border-left: 4px solid #8B7355; border-radius: 5px; margin: 20px 0;">
                    <h3 style="color: #5C4A32; margin-top: 0;;text-align:left;">Catering Required</h3>
                    <p style="color: #5C4A32; margin: 0;">Yes - catering services requested</p>
                </div>';
            }

            if (!empty($data['av_equipment'])) {
                $htmlBody .= '
                <div style="background: #FDF6EC; padding: 15px; border-left: 4px solid #8B7355; border-radius: 5px; margin: 20px 0;">
                    <h3 style="color: #5C4A32; margin-top: 0;;text-align:left;">AV Equipment Requirements</h3>
                    <p style="color: #5C4A32; margin: 0;">' . htmlspecialchars($data['av_equipment']) . '</p>
                </div>';
            }

            if (!empty($data['special_requirements'])) {
                $htmlBody .= '
                <div style="background: #FDF6EC; padding: 15px; border-left: 4px solid #8B7355; border-radius: 5px; margin: 20px 0;">
                    <h3 style="color: #5C4A32; margin-top: 0;;text-align:left;">Special Requirements</h3>
                    <p style="color: #5C4A32; margin: 0;">' . htmlspecialchars($data['special_requirements']) . '</p>
                </div>';
            }

            $htmlBody .= '
            <div style="text-align: center; margin-top: 30px;">
                <a href="' . htmlspecialchars($email_site_url) . '/admin/conference-management.php" style="display: inline-block; background: #8B7355; color: #1A1A1A; padding: 12px 24px; text-decoration: none; border-radius: 5px; font-weight: bold; font-size: 16px;">
                    View Enquiry in Admin Panel
                </a>
            </div>';
        }

        // Send email
        return sendEmail(
            $conference_email,
            'Conference Team',
            'New Conference Enquiry - ' . htmlspecialchars($email_site_name) . ' [' . $data['inquiry_reference'] . ']',
            $htmlBody
        );
    } catch (Exception $e) {
        error_log("Send Conference Admin Notification Error: " . $e->getMessage());
        return [
            'success' => false,
            'message' => $e->getMessage()
        ];
    }
}

/**
 * Send conference payment confirmation email
 */
function sendConferencePaymentEmail(array $data)
{
    global $pdo, $email_from_name, $email_from_email, $email_admin_email, $email_site_name, $email_site_url;

    try {
        // Get conference room details
        $stmt = $pdo->prepare("SELECT * FROM conference_rooms WHERE id = ?");
        $stmt->execute([$data['conference_room_id']]);
        $room = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$room) {
            throw new Exception("Conference room not found");
        }

        $currency_symbol = getSetting('currency_symbol');
        $total_amount = number_format($data['total_amount'], 0);
        $payment_amount = number_format($data['payment_amount'], 0);
        $payment_date = date('F j, Y', strtotime($data['payment_date']));

        // Prepare email content
        $htmlBody = '
        <h1 style="color: #8B7355; text-align: center;">Payment Confirmation</h1>
        <p>Dear ' . htmlspecialchars($data['contact_person']) . ',</p>
        <p>We are pleased to confirm that we have received your payment for the conference booking at <strong>' . htmlspecialchars($email_site_name) . '</strong>.</p>

        <div style="background: #d4edda; padding: 15px; border-left: 4px solid #28a745; border-radius: 5px; margin: 20px 0;">
            <h3 style="color: #155724; margin-top: 0;;text-align:left;">✅ Payment Received</h3>
            <p style="color: #155724; margin: 0;">
                <strong>Payment Date:</strong> ' . $payment_date . '<br>
                <strong>Amount Paid:</strong> ' . $currency_symbol . ' ' . $payment_amount . '<br>
                <strong>Payment Method:</strong> ' . htmlspecialchars($data['payment_method'] ?: 'Cash') . '<br>
                <strong>Transaction Reference:</strong> ' . htmlspecialchars($data['payment_reference'] ?: $data['inquiry_reference']) . '
            </p>
        </div>

        <div style="background: #FAF6F0; border: 2px solid #C8A45A; padding: 20px; margin: 20px 0; border-radius: 10px;">
            <h2 style="color: #8B7355; margin-top: 0;;text-align:left;">Final Booking Summary</h2>

            <table role="presentation" width="100%" cellspacing="0" cellpadding="0" style="margin:0;"><tr><td style="padding:10px 10px 10px 0;font-weight:bold;color:#1A1A1A;width:44%;vertical-align:top;font-family:\'Segoe UI\',Tahoma,Verdana,sans-serif;border-bottom:1px solid #e8e0d4;">Booking Reference:</td><td style="padding:10px 0 10px 6px;color: #8B7355; font-weight: bold; font-size: 18px;;text-align:left;vertical-align:top;font-family:\'Segoe UI\',Tahoma,Verdana,sans-serif;border-bottom:1px solid #e8e0d4;">' . htmlspecialchars($data['inquiry_reference']) . '</td></tr></table>

            <table role="presentation" width="100%" cellspacing="0" cellpadding="0" style="margin:0;"><tr><td style="padding:10px 10px 10px 0;font-weight:bold;color:#1A1A1A;width:44%;vertical-align:top;font-family:\'Segoe UI\',Tahoma,Verdana,sans-serif;border-bottom:1px solid #e8e0d4;">Company:</td><td style="padding:10px 0 10px 6px;color: #333;;text-align:left;vertical-align:top;font-family:\'Segoe UI\',Tahoma,Verdana,sans-serif;border-bottom:1px solid #e8e0d4;">' . htmlspecialchars($data['company_name']) . '</td></tr></table>

            <table role="presentation" width="100%" cellspacing="0" cellpadding="0" style="margin:0;"><tr><td style="padding:10px 10px 10px 0;font-weight:bold;color:#1A1A1A;width:44%;vertical-align:top;font-family:\'Segoe UI\',Tahoma,Verdana,sans-serif;border-bottom:1px solid #e8e0d4;">Conference Room:</td><td style="padding:10px 0 10px 6px;color: #333;;text-align:left;vertical-align:top;font-family:\'Segoe UI\',Tahoma,Verdana,sans-serif;border-bottom:1px solid #e8e0d4;">' . htmlspecialchars($room['name']) . '</td></tr></table>

            <table role="presentation" width="100%" cellspacing="0" cellpadding="0" style="margin:0;"><tr><td style="padding:10px 10px 10px 0;font-weight:bold;color:#1A1A1A;width:44%;vertical-align:top;font-family:\'Segoe UI\',Tahoma,Verdana,sans-serif;border-bottom:1px solid #e8e0d4;">Event Date:</td><td style="padding:10px 0 10px 6px;color: #333;;text-align:left;vertical-align:top;font-family:\'Segoe UI\',Tahoma,Verdana,sans-serif;border-bottom:1px solid #e8e0d4;">' . date('F j, Y', strtotime($data['event_date'])) . '</td></tr></table>

            <table role="presentation" width="100%" cellspacing="0" cellpadding="0" style="margin:0;"><tr><td style="padding:10px 10px 10px 0;font-weight:bold;color:#1A1A1A;width:44%;vertical-align:top;font-family:\'Segoe UI\',Tahoma,Verdana,sans-serif;border-bottom:1px solid #e8e0d4;">Event Time:</td><td style="padding:10px 0 10px 6px;color: #333;;text-align:left;vertical-align:top;font-family:\'Segoe UI\',Tahoma,Verdana,sans-serif;border-bottom:1px solid #e8e0d4;">' . date('H:i', strtotime($data['start_time'])) . ' - ' . date('H:i', strtotime($data['end_time'])) . '</td></tr></table>

            <table role="presentation" width="100%" cellspacing="0" cellpadding="0" style="margin:0;"><tr><td style="padding:10px 10px 10px 0;font-weight:bold;color:#1A1A1A;width:44%;vertical-align:top;font-family:\'Segoe UI\',Tahoma,Verdana,sans-serif;border-bottom:1px solid #e8e0d4;">Number of Attendees:</td><td style="padding:10px 0 10px 6px;color: #333;;text-align:left;vertical-align:top;font-family:\'Segoe UI\',Tahoma,Verdana,sans-serif;border-bottom:1px solid #e8e0d4;">' . (int) $data['number_of_attendees'] . '</td></tr></table>

            <table role="presentation" width="100%" cellspacing="0" cellpadding="0" style="margin:0;"><tr><td style="padding:10px 10px 10px 0;font-weight:bold;color:#1A1A1A;width:44%;vertical-align:top;font-family:\'Segoe UI\',Tahoma,Verdana,sans-serif;">Total Amount:</td><td style="padding:10px 0 10px 6px;color: #8B7355; font-weight: bold; font-size: 18px;;text-align:left;vertical-align:top;font-family:\'Segoe UI\',Tahoma,Verdana,sans-serif;">' . $currency_symbol . ' ' . $total_amount . '</td></tr></table>
        </div>

        <div style="background: #d4edda; padding: 15px; border-left: 4px solid #28a745; border-radius: 5px; margin: 20px 0;">
            <h3 style="color: #155724; margin-top: 0;;text-align:left;">✅ Booking Status: Fully Paid</h3>
            <p style="color: #155724; margin: 0;">
                <strong>Your conference booking is now fully paid and confirmed!</strong><br>
                We look forward to hosting your event at ' . htmlspecialchars($email_site_name) . '.
            </p>
        </div>';

        if ($data['catering_required']) {
            $htmlBody .= '
            <div style="background: #FDF6EC; padding: 15px; border-left: 4px solid #8B7355; border-radius: 5px; margin: 20px 0;">
                <h3 style="color: #5C4A32; margin-top: 0;;text-align:left;">Catering</h3>
                <p style="color: #5C4A32; margin: 0;">Catering services have been confirmed for your event.</p>
            </div>';
        }

        if (!empty($data['av_equipment'])) {
            $htmlBody .= '
            <div style="background: #FDF6EC; padding: 15px; border-left: 4px solid #8B7355; border-radius: 5px; margin: 20px 0;">
                <h3 style="color: #5C4A32; margin-top: 0;;text-align:left;">AV Equipment</h3>
                <p style="color: #5C4A32; margin: 0;">' . htmlspecialchars($data['av_equipment']) . '</p>
            </div>';
        }

        if (!empty($data['special_requirements'])) {
            $htmlBody .= '
            <div style="background: #FDF6EC; padding: 15px; border-left: 4px solid #8B7355; border-radius: 5px; margin: 20px 0;">
                <h3 style="color: #5C4A32; margin-top: 0;;text-align:left;">Special Requirements</h3>
                <p style="color: #5C4A32; margin: 0;">' . htmlspecialchars($data['special_requirements']) . '</p>
            </div>';
        }

        $htmlBody .= '
        <div style="background: #FDF6EC; padding: 15px; border-left: 4px solid #8B7355; border-radius: 5px; margin: 20px 0;">
            <h3 style="color: #5C4A32; margin-top: 0;;text-align:left;">Next Steps</h3>
            <p style="color: #5C4A32; margin: 0;">
                <strong>Please save your booking reference:</strong> ' . htmlspecialchars($data['inquiry_reference']) . '<br>
                <strong>Arrival:</strong> Please arrive at least 30 minutes before your event start time<br>
                <strong>Contact us:</strong> If you need to make any changes, please contact us at least ' . getSetting('booking_change_policy', '48 hours') . ' before your event.
            </p>
        </div>

        <p>If you have any questions, please contact us at <a href="mailto:' . htmlspecialchars($email_from_email) . '">' . htmlspecialchars($email_from_email) . '</a> or call ' . getSetting('phone_main') . '.</p>

        <p>Thank you for your payment! We look forward to hosting your event at ' . htmlspecialchars($email_site_name) . '.</p>
        <p style="margin:28px 0 0;font-size:14px;color:#777;text-align:center;font-style:italic;">
            Warm regards &mdash; see you soon.
        </p>';

        // Send email
        return sendEmail(
            $data['email'],
            $data['contact_person'],
            'Payment Confirmation - ' . htmlspecialchars($email_site_name) . ' [' . $data['inquiry_reference'] . ']',
            $htmlBody
        );
    } catch (Exception $e) {
        error_log("Send Conference Payment Email Error: " . $e->getMessage());
        return [
            'success' => false,
            'message' => $e->getMessage()
        ];
    }
}

/**
 * Send conference enquiry confirmed email
 */
function sendConferenceConfirmedEmail(array $enquiry)
{
    global $pdo, $email_from_name, $email_from_email, $email_admin_email, $email_site_name, $email_site_url;

    try {
        // Get conference room details
        $stmt = $pdo->prepare("SELECT * FROM conference_rooms WHERE id = ?");
        $stmt->execute([$enquiry['conference_room_id']]);
        $room = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$room) {
            throw new Exception("Conference room not found");
        }

        $currency_symbol = getSetting('currency_symbol');
        $total_amount = $enquiry['total_amount'] ? number_format($enquiry['total_amount'], 0) : 'To be determined';

        // Prepare email content
        $htmlBody = '
            <h1 style="color: #8B7355; text-align: center;">Conference Booking Confirmed!</h1>
            <p>Dear ' . htmlspecialchars($enquiry['contact_person']) . ',</p>
            <p>Great news! Your conference booking with <strong>' . htmlspecialchars($email_site_name) . '</strong> has been confirmed by our team.</p>

            <div style="background: #FAF6F0; border: 2px solid #C8A45A; padding: 20px; margin: 20px 0; border-radius: 10px;">
                <h2 style="color: #8B7355; margin-top: 0;;text-align:left;">Conference Details</h2>

                <table role="presentation" width="100%" cellspacing="0" cellpadding="0" style="margin:0;"><tr><td style="padding:10px 10px 10px 0;font-weight:bold;color:#1A1A1A;width:44%;vertical-align:top;font-family:\'Segoe UI\',Tahoma,Verdana,sans-serif;border-bottom:1px solid #e8e0d4;">Reference:</td><td style="padding:10px 0 10px 6px;color: #8B7355; font-weight: bold; font-size: 18px;;text-align:left;vertical-align:top;font-family:\'Segoe UI\',Tahoma,Verdana,sans-serif;border-bottom:1px solid #e8e0d4;">' . htmlspecialchars($enquiry['inquiry_reference']) . '</td></tr></table>

                <table role="presentation" width="100%" cellspacing="0" cellpadding="0" style="margin:0;"><tr><td style="padding:10px 10px 10px 0;font-weight:bold;color:#1A1A1A;width:44%;vertical-align:top;font-family:\'Segoe UI\',Tahoma,Verdana,sans-serif;border-bottom:1px solid #e8e0d4;">Company:</td><td style="padding:10px 0 10px 6px;color: #333;;text-align:left;vertical-align:top;font-family:\'Segoe UI\',Tahoma,Verdana,sans-serif;border-bottom:1px solid #e8e0d4;">' . htmlspecialchars($enquiry['company_name']) . '</td></tr></table>

                <table role="presentation" width="100%" cellspacing="0" cellpadding="0" style="margin:0;"><tr><td style="padding:10px 10px 10px 0;font-weight:bold;color:#1A1A1A;width:44%;vertical-align:top;font-family:\'Segoe UI\',Tahoma,Verdana,sans-serif;border-bottom:1px solid #e8e0d4;">Conference Room:</td><td style="padding:10px 0 10px 6px;color: #333;;text-align:left;vertical-align:top;font-family:\'Segoe UI\',Tahoma,Verdana,sans-serif;border-bottom:1px solid #e8e0d4;">' . htmlspecialchars($room['name']) . '</td></tr></table>

                <table role="presentation" width="100%" cellspacing="0" cellpadding="0" style="margin:0;"><tr><td style="padding:10px 10px 10px 0;font-weight:bold;color:#1A1A1A;width:44%;vertical-align:top;font-family:\'Segoe UI\',Tahoma,Verdana,sans-serif;border-bottom:1px solid #e8e0d4;">Event Date:</td><td style="padding:10px 0 10px 6px;color: #333;;text-align:left;vertical-align:top;font-family:\'Segoe UI\',Tahoma,Verdana,sans-serif;border-bottom:1px solid #e8e0d4;">' . date('F j, Y', strtotime($enquiry['event_date'])) . '</td></tr></table>

                <table role="presentation" width="100%" cellspacing="0" cellpadding="0" style="margin:0;"><tr><td style="padding:10px 10px 10px 0;font-weight:bold;color:#1A1A1A;width:44%;vertical-align:top;font-family:\'Segoe UI\',Tahoma,Verdana,sans-serif;border-bottom:1px solid #e8e0d4;">Event Time:</td><td style="padding:10px 0 10px 6px;color: #333;;text-align:left;vertical-align:top;font-family:\'Segoe UI\',Tahoma,Verdana,sans-serif;border-bottom:1px solid #e8e0d4;">' . date('H:i', strtotime($enquiry['start_time'])) . ' - ' . date('H:i', strtotime($enquiry['end_time'])) . '</td></tr></table>

                <table role="presentation" width="100%" cellspacing="0" cellpadding="0" style="margin:0;"><tr><td style="padding:10px 10px 10px 0;font-weight:bold;color:#1A1A1A;width:44%;vertical-align:top;font-family:\'Segoe UI\',Tahoma,Verdana,sans-serif;border-bottom:1px solid #e8e0d4;">Number of Attendees:</td><td style="padding:10px 0 10px 6px;color: #333;;text-align:left;vertical-align:top;font-family:\'Segoe UI\',Tahoma,Verdana,sans-serif;border-bottom:1px solid #e8e0d4;">' . (int) $enquiry['number_of_attendees'] . '</td></tr></table>

                <table role="presentation" width="100%" cellspacing="0" cellpadding="0" style="margin:0;"><tr><td style="padding:10px 10px 10px 0;font-weight:bold;color:#1A1A1A;width:44%;vertical-align:top;font-family:\'Segoe UI\',Tahoma,Verdana,sans-serif;">Total Amount:</td><td style="padding:10px 0 10px 6px;color: #8B7355; font-weight: bold; font-size: 18px;;text-align:left;vertical-align:top;font-family:\'Segoe UI\',Tahoma,Verdana,sans-serif;">' . $currency_symbol . ' ' . $total_amount . '</td></tr></table>
            </div>

            <div style="background: #d4edda; padding: 15px; border-left: 4px solid #28a745; border-radius: 5px; margin: 20px 0;">
                <h3 style="color: #155724; margin-top: 0;;text-align:left;">✅ Booking Status: Confirmed</h3>
                <p style="color: #155724; margin: 0;">
                    <strong>Your conference booking is now confirmed!</strong><br>
                    We look forward to hosting your event at ' . htmlspecialchars($email_site_name) . '.
                </p>
            </div>';

        if ($enquiry['catering_required']) {
            $htmlBody .= '
                <div style="background: #FDF6EC; padding: 15px; border-left: 4px solid #8B7355; border-radius: 5px; margin: 20px 0;">
                    <h3 style="color: #5C4A32; margin-top: 0;;text-align:left;">Catering</h3>
                    <p style="color: #5C4A32; margin: 0;">Catering services have been requested for your event.</p>
                </div>';
        }

        if (!empty($enquiry['av_equipment'])) {
            $htmlBody .= '
                <div style="background: #FDF6EC; padding: 15px; border-left: 4px solid #8B7355; border-radius: 5px; margin: 20px 0;">
                    <h3 style="color: #5C4A32; margin-top: 0;;text-align:left;">AV Equipment</h3>
                    <p style="color: #5C4A32; margin: 0;">' . htmlspecialchars($enquiry['av_equipment']) . '</p>
                </div>';
        }

        if (!empty($enquiry['special_requirements'])) {
            $htmlBody .= '
                <div style="background: #FDF6EC; padding: 15px; border-left: 4px solid #8B7355; border-radius: 5px; margin: 20px 0;">
                    <h3 style="color: #5C4A32; margin-top: 0;;text-align:left;">Special Requirements</h3>
                    <p style="color: #5C4A32; margin: 0;">' . htmlspecialchars($enquiry['special_requirements']) . '</p>
                </div>';
        }

        $htmlBody .= '
            <div style="background: #FDF6EC; padding: 15px; border-left: 4px solid #8B7355; border-radius: 5px; margin: 20px 0;">
                <h3 style="color: #5C4A32; margin-top: 0;;text-align:left;">Next Steps</h3>
                <p style="color: #5C4A32; margin: 0;">
                    <strong>Please save your booking reference:</strong> ' . htmlspecialchars($enquiry['inquiry_reference']) . '<br>
                    <strong>Arrival:</strong> Please arrive at least 30 minutes before your event start time<br>
                    <strong>Contact us:</strong> If you need to make any changes, please contact us at least ' . getSetting('booking_change_policy', '48 hours') . ' before your event.
                </p>
            </div>

            <p>If you have any questions, please contact us at <a href="mailto:' . htmlspecialchars($email_from_email) . '">' . htmlspecialchars($email_from_email) . '</a> or call ' . getSetting('phone_main') . '.</p>

            <p>We look forward to hosting your event at ' . htmlspecialchars($email_site_name) . '!</p>
        <p style="margin:28px 0 0;font-size:14px;color:#777;text-align:center;font-style:italic;">
            Warm regards &mdash; see you soon.
        </p>';

        // Send email
        return sendEmail(
            $enquiry['email'],
            $enquiry['contact_person'],
            'Conference Confirmed - ' . htmlspecialchars($email_site_name) . ' [' . $enquiry['inquiry_reference'] . ']',
            $htmlBody
        );
    } catch (Exception $e) {
        error_log("Send Conference Confirmed Email Error: " . $e->getMessage());
        return [
            'success' => false,
            'message' => $e->getMessage()
        ];
    }
}

/**
 * Send conference cancelled email
 */
function sendConferenceCancelledEmail(array $enquiry)
{
    global $pdo, $email_from_name, $email_from_email, $email_admin_email, $email_site_name, $email_site_url;

    try {
        // Get conference room details
        $stmt = $pdo->prepare("SELECT * FROM conference_rooms WHERE id = ?");
        $stmt->execute([$enquiry['conference_room_id']]);
        $room = $stmt->fetch(PDO::FETCH_ASSOC);

        // Prepare email content
        $htmlBody = '
            <h1 style="color: #dc3545; text-align: center;">Conference Booking Cancelled</h1>
            <p>Dear ' . htmlspecialchars($enquiry['contact_person']) . ',</p>
            <p>We regret to inform you that your conference booking with <strong>' . htmlspecialchars($email_site_name) . '</strong> has been cancelled.</p>

            <div style="background: #FAF6F0; border: 2px solid #dc3545; padding: 20px; margin: 20px 0; border-radius: 10px;">
                <h2 style="color: #dc3545; margin-top: 0;;text-align:left;">Cancelled Booking Details</h2>

                <table role="presentation" width="100%" cellspacing="0" cellpadding="0" style="margin:0;"><tr><td style="padding:10px 10px 10px 0;font-weight:bold;color:#1A1A1A;width:44%;vertical-align:top;font-family:\'Segoe UI\',Tahoma,Verdana,sans-serif;border-bottom:1px solid #e8e0d4;">Reference:</td><td style="padding:10px 0 10px 6px;color: #dc3545; font-weight: bold; font-size: 18px;;text-align:left;vertical-align:top;font-family:\'Segoe UI\',Tahoma,Verdana,sans-serif;border-bottom:1px solid #e8e0d4;">' . htmlspecialchars($enquiry['inquiry_reference']) . '</td></tr></table>

                <table role="presentation" width="100%" cellspacing="0" cellpadding="0" style="margin:0;"><tr><td style="padding:10px 10px 10px 0;font-weight:bold;color:#1A1A1A;width:44%;vertical-align:top;font-family:\'Segoe UI\',Tahoma,Verdana,sans-serif;border-bottom:1px solid #e8e0d4;">Company:</td><td style="padding:10px 0 10px 6px;color: #333;;text-align:left;vertical-align:top;font-family:\'Segoe UI\',Tahoma,Verdana,sans-serif;border-bottom:1px solid #e8e0d4;">' . htmlspecialchars($enquiry['company_name']) . '</td></tr></table>

                <table role="presentation" width="100%" cellspacing="0" cellpadding="0" style="margin:0;"><tr><td style="padding:10px 10px 10px 0;font-weight:bold;color:#1A1A1A;width:44%;vertical-align:top;font-family:\'Segoe UI\',Tahoma,Verdana,sans-serif;border-bottom:1px solid #e8e0d4;">Conference Room:</td><td style="padding:10px 0 10px 6px;color: #333;;text-align:left;vertical-align:top;font-family:\'Segoe UI\',Tahoma,Verdana,sans-serif;border-bottom:1px solid #e8e0d4;">' . htmlspecialchars($room['name'] ?? 'N/A') . '</td></tr></table>

                <table role="presentation" width="100%" cellspacing="0" cellpadding="0" style="margin:0;"><tr><td style="padding:10px 10px 10px 0;font-weight:bold;color:#1A1A1A;width:44%;vertical-align:top;font-family:\'Segoe UI\',Tahoma,Verdana,sans-serif;border-bottom:1px solid #e8e0d4;">Event Date:</td><td style="padding:10px 0 10px 6px;color: #333;;text-align:left;vertical-align:top;font-family:\'Segoe UI\',Tahoma,Verdana,sans-serif;border-bottom:1px solid #e8e0d4;">' . date('F j, Y', strtotime($enquiry['event_date'])) . '</td></tr></table>

                <table role="presentation" width="100%" cellspacing="0" cellpadding="0" style="margin:0;"><tr><td style="padding:10px 10px 10px 0;font-weight:bold;color:#1A1A1A;width:44%;vertical-align:top;font-family:\'Segoe UI\',Tahoma,Verdana,sans-serif;">Event Time:</td><td style="padding:10px 0 10px 6px;color: #333;;text-align:left;vertical-align:top;font-family:\'Segoe UI\',Tahoma,Verdana,sans-serif;">' . date('H:i', strtotime($enquiry['start_time'])) . ' - ' . date('H:i', strtotime($enquiry['end_time'])) . '</td></tr></table>
            </div>

            <div style="background: #f8d7da; padding: 15px; border-left: 4px solid #dc3545; border-radius: 5px; margin: 20px 0;">
                <h3 style="color: #721c24; margin-top: 0;;text-align:left;">❌ Booking Status: Cancelled</h3>
                <p style="color: #721c24; margin: 0;">
                    <strong>This booking has been cancelled.</strong><br>
                    If you believe this is an error, please contact us immediately.
                </p>
            </div>

            <p>If you have any questions or would like to reschedule, please contact us at <a href="mailto:' . htmlspecialchars($email_from_email) . '">' . htmlspecialchars($email_from_email) . '</a> or call ' . getSetting('phone_main') . '.</p>

            <p>We hope to have the opportunity to serve you in the future.</p>
        <p style="margin:28px 0 0;font-size:14px;color:#777;text-align:center;font-style:italic;">
            Warm regards &mdash; see you soon.
        </p>';

        // Send email
        return sendEmail(
            $enquiry['email'],
            $enquiry['contact_person'],
            'Conference Cancelled - ' . htmlspecialchars($email_site_name) . ' [' . $enquiry['inquiry_reference'] . ']',
            $htmlBody
        );
    } catch (Exception $e) {
        error_log("Send Conference Cancelled Email Error: " . $e->getMessage());
        return [
            'success' => false,
            'message' => $e->getMessage()
        ];
    }
}

/**
 * Send booking cancelled email
 */
function sendBookingCancelledEmail(array $booking, string $cancellation_reason = '')
{
    global $pdo, $email_from_name, $email_from_email, $email_admin_email, $email_site_name, $email_site_url;

    try {
        // Get room details
        $stmt = $pdo->prepare("SELECT * FROM rooms WHERE id = ?");
        $stmt->execute([$booking['room_id']]);
        $room = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$room) {
            throw new Exception("Room not found");
        }

        $templateVars = buildBookingEmailVariables($booking, $room, [
            'cancellation_reason' => $cancellation_reason
        ]);
        $dbTemplate = renderBookingEmailTemplate('booking_cancelled', $templateVars);
        if ($dbTemplate) {
            $ccEmails = getCCEmails();
            return sendEmailWithCC(
                $booking['guest_email'],
                $booking['guest_name'],
                $dbTemplate['subject'],
                $dbTemplate['html_body'],
                $dbTemplate['text_body'],
                $ccEmails
            );
        }

        // Prepare email content
        $htmlBody = '
        <h1 style="color: #dc3545; text-align: center;">Booking Cancelled</h1>
        <p>Dear ' . htmlspecialchars($booking['guest_name']) . ',</p>
        <p>We regret to inform you that your booking with <strong>' . htmlspecialchars($email_site_name) . '</strong> has been cancelled.</p>

        <div style="background: #FAF6F0; border: 2px solid #dc3545; padding: 20px; margin: 20px 0; border-radius: 10px;">
            <h2 style="color: #dc3545; margin-top: 0;;text-align:left;">Cancelled Booking Details</h2>

            <table role="presentation" width="100%" cellspacing="0" cellpadding="0" style="margin:0;"><tr><td style="padding:10px 10px 10px 0;font-weight:bold;color:#1A1A1A;width:44%;vertical-align:top;font-family:\'Segoe UI\',Tahoma,Verdana,sans-serif;border-bottom:1px solid #e8e0d4;">Booking Reference:</td><td style="padding:10px 0 10px 6px;color: #dc3545; font-weight: bold; font-size: 18px;;text-align:left;vertical-align:top;font-family:\'Segoe UI\',Tahoma,Verdana,sans-serif;border-bottom:1px solid #e8e0d4;">' . htmlspecialchars($booking['booking_reference']) . '</td></tr></table>

            <table role="presentation" width="100%" cellspacing="0" cellpadding="0" style="margin:0;"><tr><td style="padding:10px 10px 10px 0;font-weight:bold;color:#1A1A1A;width:44%;vertical-align:top;font-family:\'Segoe UI\',Tahoma,Verdana,sans-serif;border-bottom:1px solid #e8e0d4;">Room:</td><td style="padding:10px 0 10px 6px;color: #333;;text-align:left;vertical-align:top;font-family:\'Segoe UI\',Tahoma,Verdana,sans-serif;border-bottom:1px solid #e8e0d4;">' . htmlspecialchars($room['name']) . '</td></tr></table>

            <table role="presentation" width="100%" cellspacing="0" cellpadding="0" style="margin:0;"><tr><td style="padding:10px 10px 10px 0;font-weight:bold;color:#1A1A1A;width:44%;vertical-align:top;font-family:\'Segoe UI\',Tahoma,Verdana,sans-serif;border-bottom:1px solid #e8e0d4;">Check-in Date:</td><td style="padding:10px 0 10px 6px;color: #333;;text-align:left;vertical-align:top;font-family:\'Segoe UI\',Tahoma,Verdana,sans-serif;border-bottom:1px solid #e8e0d4;">' . date('F j, Y', strtotime($booking['check_in_date'])) . '</td></tr></table>

            <table role="presentation" width="100%" cellspacing="0" cellpadding="0" style="margin:0;"><tr><td style="padding:10px 10px 10px 0;font-weight:bold;color:#1A1A1A;width:44%;vertical-align:top;font-family:\'Segoe UI\',Tahoma,Verdana,sans-serif;border-bottom:1px solid #e8e0d4;">Check-out Date:</td><td style="padding:10px 0 10px 6px;color: #333;;text-align:left;vertical-align:top;font-family:\'Segoe UI\',Tahoma,Verdana,sans-serif;border-bottom:1px solid #e8e0d4;">' . date('F j, Y', strtotime($booking['check_out_date'])) . '</td></tr></table>

            <table role="presentation" width="100%" cellspacing="0" cellpadding="0" style="margin:0;"><tr><td style="padding:10px 10px 10px 0;font-weight:bold;color:#1A1A1A;width:44%;vertical-align:top;font-family:\'Segoe UI\',Tahoma,Verdana,sans-serif;border-bottom:1px solid #e8e0d4;">Number of Nights:</td><td style="padding:10px 0 10px 6px;color: #333;;text-align:left;vertical-align:top;font-family:\'Segoe UI\',Tahoma,Verdana,sans-serif;border-bottom:1px solid #e8e0d4;">' . $booking['number_of_nights'] . ' night' . ($booking['number_of_nights'] != 1 ? 's' : '') . '</td></tr></table>

            <table role="presentation" width="100%" cellspacing="0" cellpadding="0" style="margin:0;"><tr><td style="padding:10px 10px 10px 0;font-weight:bold;color:#1A1A1A;width:44%;vertical-align:top;font-family:\'Segoe UI\',Tahoma,Verdana,sans-serif;border-bottom:1px solid #e8e0d4;">Number of Guests:</td><td style="padding:10px 0 10px 6px;color: #333;;text-align:left;vertical-align:top;font-family:\'Segoe UI\',Tahoma,Verdana,sans-serif;border-bottom:1px solid #e8e0d4;">' . $booking['number_of_guests'] . ' guest' . ($booking['number_of_guests'] != 1 ? 's' : '') . '</td></tr></table>

            <table role="presentation" width="100%" cellspacing="0" cellpadding="0" style="margin:0;"><tr><td style="padding:10px 10px 10px 0;font-weight:bold;color:#1A1A1A;width:44%;vertical-align:top;font-family:\'Segoe UI\',Tahoma,Verdana,sans-serif;">Total Amount:</td><td style="padding:10px 0 10px 6px;color: #8B7355; font-weight: bold; font-size: 18px;;text-align:left;vertical-align:top;font-family:\'Segoe UI\',Tahoma,Verdana,sans-serif;">' . getSetting('currency_symbol') . ' ' . number_format($booking['total_amount'], 0) . '</td></tr></table>
        </div>';

        if ($cancellation_reason) {
            $htmlBody .= '
            <div style="background: #fff3cd; padding: 15px; border-left: 4px solid #ffc107; border-radius: 5px; margin: 20px 0;">
                <h3 style="color: #856404; margin-top: 0;;text-align:left;">Cancellation Reason</h3>
                <p style="color: #856404; margin: 0;">' . htmlspecialchars($cancellation_reason) . '</p>
            </div>';
        }

        $htmlBody .= '
        <div style="background: #f8d7da; padding: 15px; border-left: 4px solid #dc3545; border-radius: 5px; margin: 20px 0;">
            <h3 style="color: #721c24; margin-top: 0;;text-align:left;">❌ Booking Status: Cancelled</h3>
            <p style="color: #721c24; margin: 0;">
                <strong>This booking has been cancelled.</strong><br>
                If you believe this is an error, please contact us immediately.
            </p>
        </div>

        <p>If you have any questions or would like to make a new booking, please contact us at <a href="mailto:' . htmlspecialchars($email_from_email) . '">' . htmlspecialchars($email_from_email) . '</a> or call ' . getSetting('phone_main') . '.</p>

        <p>We hope to have the opportunity to serve you in the future.</p>
        <p style="margin:28px 0 0;font-size:14px;color:#777;text-align:center;font-style:italic;">
            Warm regards &mdash; see you soon.
        </p>';

        // Get CC emails for invoice recipients
        $ccEmails = getCCEmails();

        // Send email with CC to admin/invoice recipients
        $emailResult = sendEmailWithCC(
            $booking['guest_email'],
            $booking['guest_name'],
            'Booking Cancelled - ' . htmlspecialchars($email_site_name) . ' [' . $booking['booking_reference'] . ']',
            $htmlBody,
            '',
            $ccEmails
        );

        // Send WhatsApp notification if enabled
        $whatsappResult = ['guest' => ['success' => false, 'message' => 'WhatsApp not available'], 'hotel' => ['success' => false, 'message' => 'WhatsApp not available']];
        if (function_exists('sendBookingCancelledWhatsApp') && function_exists('isWhatsAppEnabled') && isWhatsAppEnabled()) {
            $bookingForWhatsApp = $booking;
            $bookingForWhatsApp['room_name'] = $room['name'];
            $whatsappResult = sendBookingCancelledWhatsApp($bookingForWhatsApp, $room);
        }

        return [
            'success' => $emailResult['success'],
            'message' => $emailResult['message'],
            'whatsapp_guest_sent' => $whatsappResult['guest']['success'] ?? false,
            'whatsapp_hotel_sent' => $whatsappResult['hotel']['success'] ?? false
        ];
    } catch (Exception $e) {
        error_log("Send Booking Cancelled Email Error: " . $e->getMessage());
        return [
            'success' => false,
            'message' => $e->getMessage()
        ];
    }
}

/**
 * Send booking room upgrade email
 */
function sendBookingRoomUpgradeEmail(array $booking)
{
    global $pdo, $email_from_name, $email_from_email, $email_admin_email, $email_site_name, $email_site_url;

    try {
        $old_room_name = $booking['old_room_name'] ?? 'Previous Room';
        $new_room_name = $booking['new_room_name'] ?? 'New Room';
        $old_total = (float)($booking['old_total'] ?? 0);
        $new_total = (float)($booking['new_total'] ?? 0);
        $price_difference = (float)($booking['price_difference'] ?? 0);

        $currency_symbol = getSetting('currency_symbol');

        $htmlBody = '
        <h1 style="color: #8B7355; text-align: center;">Room Upgrade Confirmed</h1>
        <p>Dear ' . htmlspecialchars($booking['guest_name']) . ',</p>
        <p>We are pleased to inform you that your booking with <strong>' . htmlspecialchars($email_site_name) . '</strong> has been upgraded to a better room!</p>

        <div style="background: #FAF6F0; border: 2px solid #8B7355; padding: 20px; margin: 20px 0; border-radius: 10px;">
            <h2 style="color: #8B7355; margin-top: 0;;text-align:left;">Upgrade Details</h2>

            <table role="presentation" width="100%" cellspacing="0" cellpadding="0" style="margin:0;"><tr><td style="padding:10px 10px 10px 0;font-weight:bold;color:#1A1A1A;width:44%;vertical-align:top;font-family:\'Segoe UI\',Tahoma,Verdana,sans-serif;border-bottom:1px solid #e8e0d4;">Booking Reference:</td><td style="padding:10px 0 10px 6px;color: #8B7355; font-weight: bold; font-size: 18px;;text-align:left;vertical-align:top;font-family:\'Segoe UI\',Tahoma,Verdana,sans-serif;border-bottom:1px solid #e8e0d4;">' . htmlspecialchars($booking['booking_reference']) . '</td></tr></table>

            <div style="background: #fff3cd; padding: 12px; border-radius: 6px; margin: 16px 0;">
                <table role="presentation" width="100%" cellspacing="0" cellpadding="0" style="margin:0 0 6px;"><tr><td style="padding:6px 8px 6px 0;font-weight:bold;color:#666;width:44%;vertical-align:top;">Previous Room:</td><td style="padding:6px 0 6px 8px;color:#666;text-decoration:line-through;text-align:left;vertical-align:top;">' . htmlspecialchars($old_room_name) . '</td></tr></table>
                <table role="presentation" width="100%" cellspacing="0" cellpadding="0" style="margin:0;"><tr><td style="padding:6px 8px 6px 0;font-weight:bold;color:#28a745;font-size:16px;width:44%;vertical-align:top;">New Room:</td><td style="padding:6px 0 6px 8px;color:#28a745;font-weight:bold;font-size:16px;text-align:left;vertical-align:top;">' . htmlspecialchars($new_room_name) . '</td></tr></table>
            </div>

            <table role="presentation" width="100%" cellspacing="0" cellpadding="0" style="margin:0;"><tr><td style="padding:10px 10px 10px 0;font-weight:bold;color:#1A1A1A;width:44%;vertical-align:top;font-family:\'Segoe UI\',Tahoma,Verdana,sans-serif;border-bottom:1px solid #e8e0d4;">Check-in Date:</td><td style="padding:10px 0 10px 6px;color: #333;;text-align:left;vertical-align:top;font-family:\'Segoe UI\',Tahoma,Verdana,sans-serif;border-bottom:1px solid #e8e0d4;">' . date('F j, Y', strtotime($booking['check_in_date'])) . '</td></tr></table>

            <table role="presentation" width="100%" cellspacing="0" cellpadding="0" style="margin:0;"><tr><td style="padding:10px 10px 10px 0;font-weight:bold;color:#1A1A1A;width:44%;vertical-align:top;font-family:\'Segoe UI\',Tahoma,Verdana,sans-serif;border-bottom:1px solid #e8e0d4;">Check-out Date:</td><td style="padding:10px 0 10px 6px;color: #333;;text-align:left;vertical-align:top;font-family:\'Segoe UI\',Tahoma,Verdana,sans-serif;border-bottom:1px solid #e8e0d4;">' . date('F j, Y', strtotime($booking['check_out_date'])) . '</td></tr></table>

            <div style="background: #e7f3ff; padding: 12px; border-radius: 6px; margin: 16px 0;">
                <table role="presentation" width="100%" cellspacing="0" cellpadding="0" style="margin:0 0 6px;"><tr><td style="padding:6px 8px 6px 0;font-weight:bold;color:#666;width:44%;vertical-align:top;">Previous Total:</td><td style="padding:6px 0 6px 8px;color:#666;text-align:left;vertical-align:top;">' . $currency_symbol . ' ' . number_format($old_total, 0) . '</td></tr></table>
                <table role="presentation" width="100%" cellspacing="0" cellpadding="0" style="margin:0;"><tr><td style="padding:6px 8px 6px 0;font-weight:bold;color:#666;width:44%;vertical-align:top;">New Total:</td><td style="padding:6px 0 6px 8px;color:#333;text-align:left;vertical-align:top;">' . $currency_symbol . ' ' . number_format($new_total, 0) . '</td></tr></table>';

        if ($price_difference > 0) {
            $htmlBody .= '
                <table role="presentation" width="100%" cellspacing="0" cellpadding="0" style="margin:0;border-top:1px dashed #ccc;"><tr><td style="padding:8px 8px 6px 0;font-weight:bold;color:#dc3545;width:44%;vertical-align:top;">Additional Amount:</td><td style="padding:8px 0 6px 8px;color:#dc3545;font-weight:bold;font-size:16px;text-align:left;vertical-align:top;">+' . $currency_symbol . ' ' . number_format($price_difference, 0) . '</td></tr></table>
                <p style="margin: 8px 0 0 0; font-size: 12px; color: #666;">* Please pay the additional amount upon check-in.</p>';
        } elseif ($price_difference < 0) {
            $htmlBody .= '
                <table role="presentation" width="100%" cellspacing="0" cellpadding="0" style="margin:0;border-top:1px dashed #ccc;"><tr><td style="padding:8px 8px 6px 0;font-weight:bold;color:#28a745;width:44%;vertical-align:top;">Discount Applied:</td><td style="padding:8px 0 6px 8px;color:#28a745;font-weight:bold;font-size:16px;text-align:left;vertical-align:top;">-' . $currency_symbol . ' ' . number_format(abs($price_difference), 0) . '</td></tr></table>
                <p style="margin: 8px 0 0 0; font-size: 12px; color: #666;">* A discount has been applied to your booking!</p>';
        } else {
            $htmlBody .= '
                <table role="presentation" width="100%" cellspacing="0" cellpadding="0" style="margin:0;border-top:1px dashed #ccc;"><tr><td style="padding:8px 8px 6px 0;font-weight:bold;color:#8B7355;width:44%;vertical-align:top;">Price Adjustment:</td><td style="padding:8px 0 6px 8px;color:#8B7355;text-align:left;vertical-align:top;">No change in total amount</td></tr></table>';
        }

        $htmlBody .= '
            </div>
        </div>

        <div style="background: #d4edda; padding: 15px; border-left: 4px solid #28a745; border-radius: 5px; margin: 20px 0;">
            <h3 style="color: #155724; margin-top: 0;;text-align:left;"><i class="fas fa-arrow-up"></i> Room Upgraded Successfully!</h3>
            <p style="color: #155724; margin: 0;">
                Your room has been upgraded from <strong>' . htmlspecialchars($old_room_name) . '</strong> to <strong>' . htmlspecialchars($new_room_name) . '</strong>.
                We hope you enjoy your enhanced stay with us!
            </p>
        </div>

        <p>If you have any questions about your upgrade, please contact us at <a href="mailto:' . htmlspecialchars($email_from_email) . '">' . htmlspecialchars($email_from_email) . '</a>.</p>
        <p style="margin:28px 0 0;font-size:14px;color:#777;text-align:center;font-style:italic;">
            Warm regards &mdash; see you soon.
        </p>';

        // Get CC emails
        $ccEmails = getCCEmails();

        // Send email with CC
        $emailResult = sendEmailWithCC(
            $booking['guest_email'],
            $booking['guest_name'],
            'Room Upgraded - ' . htmlspecialchars($email_site_name) . ' [' . $booking['booking_reference'] . ']',
            $htmlBody,
            '',
            $ccEmails
        );

        return [
            'success' => $emailResult['success'],
            'message' => $emailResult['message']
        ];
    } catch (Exception $e) {
        error_log("Send Room Upgrade Email Error: " . $e->getMessage());
        return [
            'success' => false,
            'message' => $e->getMessage()
        ];
    }
}

/**
 * Send email with CC recipients
 */
function sendEmailWithCC(string $to, ?string $toName, string $subject, string $htmlBody, string $textBody = '', array $ccEmails = [])
{
    global $email_from_name, $email_from_email, $email_admin_email;
    global $smtp_host, $smtp_port, $smtp_username, $smtp_password, $smtp_secure, $smtp_timeout, $smtp_debug;
    global $email_bcc_admin, $development_mode, $email_log_enabled, $email_preview_enabled;

    // If in development mode and no password or preview enabled, show preview
    if ($development_mode && (empty($smtp_password) || $email_preview_enabled)) {
        return createEmailPreview($to, $toName, $subject, $htmlBody, $textBody);
    }

    try {
        // Create PHPMailer instance
        $mail = new PHPMailer(true);

        // Server settings
        $mail->isSMTP();
        $mail->Host = $smtp_host;
        $mail->SMTPAuth = true;
        $mail->Username = $smtp_username;
        $mail->Password = $smtp_password;
        $mail->SMTPSecure = $smtp_secure;
        $mail->Port = $smtp_port;
        $mail->Timeout = $smtp_timeout;

        if ($smtp_debug > 0) {
            $mail->SMTPDebug = $smtp_debug;
        }

        // Recipients
        $mail->setFrom($smtp_username, $email_from_name);
        $mail->addAddress($to, $toName ?? '');
        $mail->addReplyTo($email_from_email, $email_from_name);

        // Add CC recipients
        if (!empty($ccEmails)) {
            foreach ($ccEmails as $ccEmail) {
                $mail->addCC($ccEmail);
            }
        }

        // Add BCC for admin if enabled
        if ($email_bcc_admin && !empty($email_admin_email)) {
            $mail->addBCC($email_admin_email);
        }

        // Content — force UTF-8
        $mail->CharSet  = PHPMailer::CHARSET_UTF8;
        $mail->Encoding = PHPMailer::ENCODING_BASE64;
        $mail->isHTML(true);
        $mail->Subject = $subject;
        $mail->Body = wrapEmailTemplate($htmlBody, $subject);
        $mail->AltBody = $textBody ?: strip_tags($htmlBody);

        $mail->send();

        // Log email if enabled
        if ($email_log_enabled) {
            logEmail($to, $toName, $subject, 'sent');
        }

        return [
            'success' => true,
            'message' => 'Email sent successfully via SMTP'
        ];
    } catch (Exception $e) {
        error_log("PHPMailer Error: " . $e->getMessage());

        // Log error if enabled
        if ($email_log_enabled) {
            logEmail($to, $toName, $subject, 'failed', $e->getMessage());
        }

        // If development mode, show preview instead of failing
        if ($development_mode) {
            return createEmailPreview($to, $toName, $subject, $htmlBody, $textBody);
        }

        return [
            'success' => false,
            'message' => 'Failed to send email: ' . $e->getMessage()
        ];
    }
}

/**
 * Get CC emails from settings
 */
function getCCEmails()
{
    global $pdo;

    try {
        $stmt = $pdo->query("SELECT email_value FROM email_settings WHERE email_key = 'cc_emails' LIMIT 1");
        $result = $stmt->fetch(PDO::FETCH_ASSOC);

        if ($result && !empty($result['email_value'])) {
            $emails = json_decode($result['email_value'], true);
            if (is_array($emails) && !empty($emails)) {
                return array_filter($emails, function (mixed $email) {
                    return filter_var($email, FILTER_VALIDATE_EMAIL);
                });
            }
        }
    } catch (Exception $e) {
        error_log("Error getting CC emails: " . $e->getMessage());
    }

    return [];
}

/**
 * Generate WhatsApp link with pre-filled message
 */
function generateWhatsAppLink(array $booking, array $room)
{
    $whatsapp_number = getSetting('whatsapp_number', getSetting('phone_main', ''));

    // Remove any non-numeric characters except +
    $whatsapp_number = preg_replace('/[^0-9+]/', '', $whatsapp_number);

    // Remove + if present (WhatsApp API format doesn't use +)
    $whatsapp_number = ltrim($whatsapp_number, '+');

    // Create pre-filled message
    $message = "Hello! I would like to confirm my tentative booking:\n\n";
    $message .= "📅 Booking Reference: " . $booking['booking_reference'] . "\n";
    $message .= "👤 Guest Name: " . $booking['guest_name'] . "\n";
    $message .= "🏨 Room: " . $room['name'] . "\n";
    $message .= "📆 Check-in: " . date('F j, Y', strtotime($booking['check_in_date'])) . "\n";
    $message .= "📆 Check-out: " . date('F j, Y', strtotime($booking['check_out_date'])) . "\n";
    $message .= "💰 Total Amount: " . getSetting('currency_symbol') . ' ' . number_format($booking['total_amount'], 0) . "\n\n";
    $message .= "Please confirm my booking. Thank you!";

    return 'https://wa.me/' . $whatsapp_number . '?text=' . urlencode($message);
}

/**
 * Send tentative booking confirmed email (sent when tentative booking is created)
 */
function sendTentativeBookingConfirmedEmail(array $booking)
{
    global $pdo, $email_from_name, $email_from_email, $email_admin_email, $email_site_name, $email_site_url;

    try {
        // Get room details
        $stmt = $pdo->prepare("SELECT * FROM rooms WHERE id = ?");
        $stmt->execute([$booking['room_id']]);
        $room = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$room) {
            throw new Exception("Room not found");
        }

        // Get tentative settings
        $tentative_duration_hours = (int)getSetting('tentative_duration_hours', 48);
        $expires_at = new DateTime($booking['tentative_expires_at']);
        $hours_until_expiry = (new DateTime())->diff($expires_at)->h + ((new DateTime())->diff($expires_at)->days * 24);

        // Generate WhatsApp link
        $whatsapp_link = generateWhatsAppLink($booking, $room);

        $templateVars = buildBookingEmailVariables($booking, $room, [
            'tentative_duration_hours' => (string)$tentative_duration_hours,
            'tentative_expires_at_formatted' => $expires_at->format('F j, Y g:i A'),
            'hours_until_expiry' => (string)$hours_until_expiry,
            'whatsapp_link' => $whatsapp_link,
        ]);
        $renderedTemplate = renderBookingEmailTemplate('tentative_booking_created', $templateVars);
        if ($renderedTemplate !== null) {
            return sendEmail(
                $booking['guest_email'],
                $booking['guest_name'],
                $renderedTemplate['subject'],
                $renderedTemplate['html_body']
            );
        }

        // Prepare email content
        $htmlBody = '
        <h1 style="color: #8B7355; text-align: center;">Tentative Booking Confirmed</h1>
        <p>Dear ' . htmlspecialchars($booking['guest_name']) . ',</p>
        <p>Thank you for your interest in <strong>' . htmlspecialchars($email_site_name) . '</strong>. Your room has been placed on tentative hold.</p>

        <div style="background: #fff3cd; padding: 15px; border-left: 4px solid #ffc107; border-radius: 5px; margin: 20px 0;">
            <h3 style="color: #856404; margin-top: 0;;text-align:left;">⏰ Tentative Hold Period</h3>
            <p style="color: #856404; margin: 0;">
                <strong>Your room is reserved until:</strong><br>
                ' . $expires_at->format('F j, Y') . ' at ' . $expires_at->format('g:i A') . '<br><br>
                You will receive a reminder email 24 hours before expiration.
            </p>
        </div>

        <div style="background: #FAF6F0; border: 2px solid #C8A45A; padding: 20px; margin: 20px 0; border-radius: 10px;">
            <h2 style="color: #8B7355; margin-top: 0;;text-align:left;">Booking Details</h2>

            <table role="presentation" width="100%" cellspacing="0" cellpadding="0" style="margin:0;"><tr><td style="padding:10px 10px 10px 0;font-weight:bold;color:#1A1A1A;width:44%;vertical-align:top;font-family:\'Segoe UI\',Tahoma,Verdana,sans-serif;border-bottom:1px solid #e8e0d4;">Booking Reference:</td><td style="padding:10px 0 10px 6px;color: #8B7355; font-weight: bold; font-size: 18px;;text-align:left;vertical-align:top;font-family:\'Segoe UI\',Tahoma,Verdana,sans-serif;border-bottom:1px solid #e8e0d4;">' . htmlspecialchars($booking['booking_reference']) . '</td></tr></table>

            <table role="presentation" width="100%" cellspacing="0" cellpadding="0" style="margin:0;"><tr><td style="padding:10px 10px 10px 0;font-weight:bold;color:#1A1A1A;width:44%;vertical-align:top;font-family:\'Segoe UI\',Tahoma,Verdana,sans-serif;border-bottom:1px solid #e8e0d4;">Room:</td><td style="padding:10px 0 10px 6px;color: #333;;text-align:left;vertical-align:top;font-family:\'Segoe UI\',Tahoma,Verdana,sans-serif;border-bottom:1px solid #e8e0d4;">' . htmlspecialchars($room['name']) . '</td></tr></table>

            <table role="presentation" width="100%" cellspacing="0" cellpadding="0" style="margin:0;"><tr><td style="padding:10px 10px 10px 0;font-weight:bold;color:#1A1A1A;width:44%;vertical-align:top;font-family:\'Segoe UI\',Tahoma,Verdana,sans-serif;border-bottom:1px solid #e8e0d4;">Check-in Date:</td><td style="padding:10px 0 10px 6px;color: #333;;text-align:left;vertical-align:top;font-family:\'Segoe UI\',Tahoma,Verdana,sans-serif;border-bottom:1px solid #e8e0d4;">' . date('F j, Y', strtotime($booking['check_in_date'])) . '</td></tr></table>

            <table role="presentation" width="100%" cellspacing="0" cellpadding="0" style="margin:0;"><tr><td style="padding:10px 10px 10px 0;font-weight:bold;color:#1A1A1A;width:44%;vertical-align:top;font-family:\'Segoe UI\',Tahoma,Verdana,sans-serif;border-bottom:1px solid #e8e0d4;">Check-out Date:</td><td style="padding:10px 0 10px 6px;color: #333;;text-align:left;vertical-align:top;font-family:\'Segoe UI\',Tahoma,Verdana,sans-serif;border-bottom:1px solid #e8e0d4;">' . date('F j, Y', strtotime($booking['check_out_date'])) . '</td></tr></table>

            <table role="presentation" width="100%" cellspacing="0" cellpadding="0" style="margin:0;"><tr><td style="padding:10px 10px 10px 0;font-weight:bold;color:#1A1A1A;width:44%;vertical-align:top;font-family:\'Segoe UI\',Tahoma,Verdana,sans-serif;border-bottom:1px solid #e8e0d4;">Number of Nights:</td><td style="padding:10px 0 10px 6px;color: #333;;text-align:left;vertical-align:top;font-family:\'Segoe UI\',Tahoma,Verdana,sans-serif;border-bottom:1px solid #e8e0d4;">' . $booking['number_of_nights'] . ' night' . ($booking['number_of_nights'] != 1 ? 's' : '') . '</td></tr></table>

            <table role="presentation" width="100%" cellspacing="0" cellpadding="0" style="margin:0;"><tr><td style="padding:10px 10px 10px 0;font-weight:bold;color:#1A1A1A;width:44%;vertical-align:top;font-family:\'Segoe UI\',Tahoma,Verdana,sans-serif;border-bottom:1px solid #e8e0d4;">Number of Guests:</td><td style="padding:10px 0 10px 6px;color: #333;;text-align:left;vertical-align:top;font-family:\'Segoe UI\',Tahoma,Verdana,sans-serif;border-bottom:1px solid #e8e0d4;">' . $booking['number_of_guests'] . ' guest' . ($booking['number_of_guests'] != 1 ? 's' : '') . '</td></tr></table>

            <table role="presentation" width="100%" cellspacing="0" cellpadding="0" style="margin:0;"><tr><td style="padding:10px 10px 10px 0;font-weight:bold;color:#1A1A1A;width:44%;vertical-align:top;font-family:\'Segoe UI\',Tahoma,Verdana,sans-serif;">Total Amount:</td><td style="padding:10px 0 10px 6px;color: #8B7355; font-weight: bold; font-size: 18px;;text-align:left;vertical-align:top;font-family:\'Segoe UI\',Tahoma,Verdana,sans-serif;">' . getSetting('currency_symbol') . ' ' . number_format($booking['total_amount'], 0) . '</td></tr></table>
        </div>

        <div style="background: #d4edda; padding: 15px; border-left: 4px solid #28a745; border-radius: 5px; margin: 20px 0;">
            <h3 style="color: #155724; margin-top: 0;;text-align:left;">What Happens Next?</h3>
            <p style="color: #155724; margin: 0;">
                <strong>1.</strong> You will receive a reminder 24 hours before expiration<br>
                <strong>2.</strong> Confirm your booking anytime before expiration via WhatsApp<br>
                <strong>3.</strong> No penalty if you decide not to book
            </p>
        </div>

        <div style="text-align: center; margin-top: 30px;">
            <a href="' . htmlspecialchars($whatsapp_link) . '"
               style="display: inline-block; background: #25D366; color: white; padding: 12px 24px; text-decoration: none; border-radius: 5px; font-weight: bold; font-size: 16px;"
               target="_blank">
                💬 Confirm My Booking Now via WhatsApp
            </a>
        </div>';

        if (!empty($booking['special_requests'])) {
            $htmlBody .= '
            <div style="background: #FDF6EC; padding: 15px; border-left: 4px solid #8B7355; border-radius: 5px; margin: 20px 0;">
                <h3 style="color: #5C4A32; margin-top: 0;;text-align:left;">Special Requests</h3>
                <p style="color: #5C4A32; margin: 0;">' . htmlspecialchars($booking['special_requests']) . '</p>
            </div>';
        }

        $htmlBody .= '
        <p>If you have any questions, please contact us at <a href="mailto:' . htmlspecialchars($email_from_email) . '">' . htmlspecialchars($email_from_email) . '</a> or call ' . getSetting('phone_main') . '.</p>

        <p>Thank you for considering ' . htmlspecialchars($email_site_name) . '!</p>
        <p style="margin:28px 0 0;font-size:14px;color:#777;text-align:center;font-style:italic;">
            Warm regards &mdash; see you soon.
        </p>';

        // Send email
        return sendEmail(
            $booking['guest_email'],
            $booking['guest_name'],
            'Tentative Booking Confirmed - ' . htmlspecialchars($email_site_name) . ' [' . $booking['booking_reference'] . ']',
            $htmlBody
        );
    } catch (Exception $e) {
        error_log("Send Tentative Booking Confirmed Email Error: " . $e->getMessage());
        return [
            'success' => false,
            'message' => $e->getMessage()
        ];
    }
}

/**
 * Send tentative booking reminder email (24 hours before expiration)
 */
function sendTentativeBookingReminderEmail(array $booking)
{
    global $pdo, $email_from_name, $email_from_email, $email_admin_email, $email_site_name, $email_site_url;

    try {
        // Get room details
        $stmt = $pdo->prepare("SELECT * FROM rooms WHERE id = ?");
        $stmt->execute([$booking['room_id']]);
        $room = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$room) {
            throw new Exception("Room not found");
        }

        $expires_at = new DateTime($booking['tentative_expires_at']);

        $templateVars = buildBookingEmailVariables($booking, $room, [
            'tentative_expires_at_formatted' => $expires_at->format('F j, Y g:i A'),
            'whatsapp_link' => generateWhatsAppLink($booking, $room),
        ]);
        $renderedTemplate = renderBookingEmailTemplate('tentative_booking_reminder', $templateVars);
        if ($renderedTemplate !== null) {
            return sendEmail(
                $booking['guest_email'],
                $booking['guest_name'],
                $renderedTemplate['subject'],
                $renderedTemplate['html_body']
            );
        }

        // Prepare email content
        $htmlBody = '
        <h1 style="color: #dc3545; text-align: center;">⏰ Your Tentative Booking Expires Soon</h1>
        <p>Dear ' . htmlspecialchars($booking['guest_name']) . ',</p>
        <p>This is a friendly reminder that your tentative booking at <strong>' . htmlspecialchars($email_site_name) . '</strong> will expire in 24 hours.</p>

        <div style="background: #f8d7da; padding: 15px; border-left: 4px solid #dc3545; border-radius: 5px; margin: 20px 0;">
            <h3 style="color: #721c24; margin-top: 0;;text-align:left;">Expiration Details</h3>
            <p style="color: #721c24; margin: 0;">
                <strong>Expires:</strong> ' . $expires_at->format('F j, Y') . ' at ' . $expires_at->format('g:i A') . '<br>
                <strong>Booking Reference:</strong> ' . htmlspecialchars($booking['booking_reference']) . '<br>
                <strong>Room:</strong> ' . htmlspecialchars($room['name']) . '
            </p>
        </div>

        <div style="background: #FAF6F0; border: 2px solid #C8A45A; padding: 20px; margin: 20px 0; border-radius: 10px;">
            <h2 style="color: #8B7355; margin-top: 0;;text-align:left;">Your Booking</h2>

            <table role="presentation" width="100%" cellspacing="0" cellpadding="0" style="margin:0;"><tr><td style="padding:10px 10px 10px 0;font-weight:bold;color:#1A1A1A;width:44%;vertical-align:top;font-family:\'Segoe UI\',Tahoma,Verdana,sans-serif;border-bottom:1px solid #e8e0d4;">Check-in:</td><td style="padding:10px 0 10px 6px;color: #333;;text-align:left;vertical-align:top;font-family:\'Segoe UI\',Tahoma,Verdana,sans-serif;border-bottom:1px solid #e8e0d4;">' . date('F j, Y', strtotime($booking['check_in_date'])) . '</td></tr></table>

            <table role="presentation" width="100%" cellspacing="0" cellpadding="0" style="margin:0;"><tr><td style="padding:10px 10px 10px 0;font-weight:bold;color:#1A1A1A;width:44%;vertical-align:top;font-family:\'Segoe UI\',Tahoma,Verdana,sans-serif;border-bottom:1px solid #e8e0d4;">Check-out:</td><td style="padding:10px 0 10px 6px;color: #333;;text-align:left;vertical-align:top;font-family:\'Segoe UI\',Tahoma,Verdana,sans-serif;border-bottom:1px solid #e8e0d4;">' . date('F j, Y', strtotime($booking['check_out_date'])) . '</td></tr></table>

            <table role="presentation" width="100%" cellspacing="0" cellpadding="0" style="margin:0;"><tr><td style="padding:10px 10px 10px 0;font-weight:bold;color:#1A1A1A;width:44%;vertical-align:top;font-family:\'Segoe UI\',Tahoma,Verdana,sans-serif;">Total Amount:</td><td style="padding:10px 0 10px 6px;color: #8B7355; font-weight: bold; font-size: 18px;;text-align:left;vertical-align:top;font-family:\'Segoe UI\',Tahoma,Verdana,sans-serif;">' . getSetting('currency_symbol') . ' ' . number_format($booking['total_amount'], 0) . '</td></tr></table>
        </div>

        <div style="text-align: center; margin-top: 30px;">
            <a href="' . htmlspecialchars(generateWhatsAppLink($booking, $room)) . '"
               style="display: inline-block; background: #25D366; color: white; padding: 12px 24px; text-decoration: none; border-radius: 5px; font-weight: bold; font-size: 16px;"
               target="_blank">
                💬 Confirm My Booking Now via WhatsApp
            </a>
        </div>

        <p style="margin-top: 20px;">If you have any questions, please contact us at <a href="mailto:' . htmlspecialchars($email_from_email) . '">' . htmlspecialchars($email_from_email) . '</a> or call ' . getSetting('phone_main') . '.</p>
        <p style="margin:28px 0 0;font-size:14px;color:#777;text-align:center;font-style:italic;">
            Warm regards &mdash; see you soon.
        </p>';

        // Send email
        return sendEmail(
            $booking['guest_email'],
            $booking['guest_name'],
            'Reminder: Tentative Booking Expiring Soon - ' . $booking['booking_reference'],
            $htmlBody
        );
    } catch (Exception $e) {
        error_log("Send Tentative Booking Reminder Email Error: " . $e->getMessage());
        return [
            'success' => false,
            'message' => $e->getMessage()
        ];
    }
}

/**
 * Send tentative booking expired email (when booking expires)
 */
function sendTentativeBookingExpiredEmail(array $booking)
{
    global $pdo, $email_from_name, $email_from_email, $email_admin_email, $email_site_name, $email_site_url;

    try {
        // Get room details
        $stmt = $pdo->prepare("SELECT * FROM rooms WHERE id = ?");
        $stmt->execute([$booking['room_id']]);
        $room = $stmt->fetch(PDO::FETCH_ASSOC);

        $templateVars = buildBookingEmailVariables($booking, $room ?: null, [
            'tentative_expires_at_formatted' => !empty($booking['tentative_expires_at'])
                ? date('F j, Y g:i A', strtotime((string)$booking['tentative_expires_at']))
                : '',
        ]);
        $renderedTemplate = renderBookingEmailTemplate('tentative_booking_expired', $templateVars);
        if ($renderedTemplate !== null) {
            return sendEmail(
                $booking['guest_email'],
                $booking['guest_name'],
                $renderedTemplate['subject'],
                $renderedTemplate['html_body']
            );
        }

        // Prepare email content
        $htmlBody = '
        <h1 style="color: #6c757d; text-align: center;">Tentative Booking Expired</h1>
        <p>Dear ' . htmlspecialchars($booking['guest_name']) . ',</p>
        <p>Your tentative booking at <strong>' . htmlspecialchars($email_site_name) . '</strong> has expired.</p>

        <div style="background: #e2e3e5; padding: 15px; border-left: 4px solid #6c757d; border-radius: 5px; margin: 20px 0;">
            <h3 style="color: #383d41; margin-top: 0;;text-align:left;">What This Means</h3>
            <p style="color: #383d41; margin: 0;">
                Your room hold has been released and is now available for other guests.<br>
                There is no penalty for an expired tentative booking.
            </p>
        </div>

        <div style="background: #FAF6F0; border: 2px solid #C8A45A; padding: 20px; margin: 20px 0; border-radius: 10px;">
            <h2 style="color: #8B7355; margin-top: 0;;text-align:left;">Expired Booking Details</h2>

            <table role="presentation" width="100%" cellspacing="0" cellpadding="0" style="margin:0;"><tr><td style="padding:10px 10px 10px 0;font-weight:bold;color:#1A1A1A;width:44%;vertical-align:top;font-family:\'Segoe UI\',Tahoma,Verdana,sans-serif;border-bottom:1px solid #e8e0d4;">Booking Reference:</td><td style="padding:10px 0 10px 6px;color: #6c757d; font-weight: bold; font-size: 18px;;text-align:left;vertical-align:top;font-family:\'Segoe UI\',Tahoma,Verdana,sans-serif;border-bottom:1px solid #e8e0d4;">' . htmlspecialchars($booking['booking_reference']) . '</td></tr></table>

            <table role="presentation" width="100%" cellspacing="0" cellpadding="0" style="margin:0;"><tr><td style="padding:10px 10px 10px 0;font-weight:bold;color:#1A1A1A;width:44%;vertical-align:top;font-family:\'Segoe UI\',Tahoma,Verdana,sans-serif;border-bottom:1px solid #e8e0d4;">Room:</td><td style="padding:10px 0 10px 6px;color: #333;;text-align:left;vertical-align:top;font-family:\'Segoe UI\',Tahoma,Verdana,sans-serif;border-bottom:1px solid #e8e0d4;">' . htmlspecialchars($room['name'] ?? 'N/A') . '</td></tr></table>

            <table role="presentation" width="100%" cellspacing="0" cellpadding="0" style="margin:0;"><tr><td style="padding:10px 10px 10px 0;font-weight:bold;color:#1A1A1A;width:44%;vertical-align:top;font-family:\'Segoe UI\',Tahoma,Verdana,sans-serif;border-bottom:1px solid #e8e0d4;">Check-in Date:</td><td style="padding:10px 0 10px 6px;color: #333;;text-align:left;vertical-align:top;font-family:\'Segoe UI\',Tahoma,Verdana,sans-serif;border-bottom:1px solid #e8e0d4;">' . date('F j, Y', strtotime($booking['check_in_date'])) . '</td></tr></table>

            <table role="presentation" width="100%" cellspacing="0" cellpadding="0" style="margin:0;"><tr><td style="padding:10px 10px 10px 0;font-weight:bold;color:#1A1A1A;width:44%;vertical-align:top;font-family:\'Segoe UI\',Tahoma,Verdana,sans-serif;">Check-out Date:</td><td style="padding:10px 0 10px 6px;color: #333;;text-align:left;vertical-align:top;font-family:\'Segoe UI\',Tahoma,Verdana,sans-serif;">' . date('F j, Y', strtotime($booking['check_out_date'])) . '</td></tr></table>
        </div>

        <p>If you are still interested in booking with us, please visit our website to check availability and make a new booking.</p>

        <div style="text-align: center; margin-top: 30px;">
            <a href="' . htmlspecialchars($email_site_url) . '/booking.php"
               style="display: inline-block; background: #8B7355; color: #1A1A1A; padding: 12px 24px; text-decoration: none; border-radius: 5px; font-weight: bold;">
                Make a New Booking
            </a>
        </div>

        <p style="margin-top: 20px;">If you have any questions, please contact us at <a href="mailto:' . htmlspecialchars($email_from_email) . '">' . htmlspecialchars($email_from_email) . '</a> or call ' . getSetting('phone_main') . '.</p>
        <p style="margin:28px 0 0;font-size:14px;color:#777;text-align:center;font-style:italic;">
            Warm regards &mdash; see you soon.
        </p>';

        // Send email
        return sendEmail(
            $booking['guest_email'],
            $booking['guest_name'],
            'Tentative Booking Expired - ' . $booking['booking_reference'],
            $htmlBody
        );
    } catch (Exception $e) {
        error_log("Send Tentative Booking Expired Email Error: " . $e->getMessage());
        return [
            'success' => false,
            'message' => $e->getMessage()
        ];
    }
}

/**
 * Send tentative booking converted email (when converted to confirmed)
 */
function sendTentativeBookingConvertedEmail(array $booking)
{
    global $pdo, $email_from_name, $email_from_email, $email_admin_email, $email_site_name, $email_site_url;

    try {
        // Debug: Log what data we received
        error_log("sendTentativeBookingConvertedEmail called with booking data: " . json_encode($booking));

        // Check if room_id exists
        if (!isset($booking['room_id']) || empty($booking['room_id'])) {
            throw new Exception("Room ID not found in booking data. Available keys: " . implode(', ', array_keys($booking)));
        }

        // Get room details
        $stmt = $pdo->prepare("SELECT * FROM rooms WHERE id = ?");
        $stmt->execute([$booking['room_id']]);
        $room = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$room) {
            throw new Exception("Room not found with ID: " . $booking['room_id']);
        }

        error_log("Room found: " . json_encode($room));

        $templateVars = buildBookingEmailVariables($booking, $room, [
            'conversion_status' => 'converted',
        ]);
        $renderedTemplate = renderBookingEmailTemplate('tentative_booking_converted', $templateVars);
        if ($renderedTemplate !== null) {
            return sendEmail(
                $booking['guest_email'],
                $booking['guest_name'],
                $renderedTemplate['subject'],
                $renderedTemplate['html_body']
            );
        }

        // Prepare email content specifically for conversion
        $htmlBody = '
        <h1 style="color: #8B7355; text-align: center;">Booking Confirmed!</h1>
        <p>Dear ' . htmlspecialchars($booking['guest_name']) . ',</p>

        <div style="background: #d4edda; padding: 15px; border-left: 4px solid #28a745; border-radius: 5px; margin: 20px 0;">
            <h3 style="color: #155724; margin-top: 0;;text-align:left;">✅ Great News!</h3>
            <p style="color: #155724; margin: 0;">
                <strong>Your tentative booking has been successfully converted to a confirmed booking!</strong><br>
                Your reservation is now guaranteed and we look forward to welcoming you to ' . htmlspecialchars($email_site_name) . '.
            </p>
        </div>

        <div style="background: #FAF6F0; border: 2px solid #C8A45A; padding: 20px; margin: 20px 0; border-radius: 10px;">
            <h2 style="color: #8B7355; margin-top: 0;;text-align:left;">Booking Details</h2>

            <table role="presentation" width="100%" cellspacing="0" cellpadding="0" style="margin:0;"><tr><td style="padding:10px 10px 10px 0;font-weight:bold;color:#1A1A1A;width:44%;vertical-align:top;font-family:\'Segoe UI\',Tahoma,Verdana,sans-serif;border-bottom:1px solid #e8e0d4;">Booking Reference:</td><td style="padding:10px 0 10px 6px;color: #8B7355; font-weight: bold; font-size: 18px;;text-align:left;vertical-align:top;font-family:\'Segoe UI\',Tahoma,Verdana,sans-serif;border-bottom:1px solid #e8e0d4;">' . htmlspecialchars($booking['booking_reference']) . '</td></tr></table>

            <table role="presentation" width="100%" cellspacing="0" cellpadding="0" style="margin:0;"><tr><td style="padding:10px 10px 10px 0;font-weight:bold;color:#1A1A1A;width:44%;vertical-align:top;font-family:\'Segoe UI\',Tahoma,Verdana,sans-serif;border-bottom:1px solid #e8e0d4;">Room:</td><td style="padding:10px 0 10px 6px;color: #333;;text-align:left;vertical-align:top;font-family:\'Segoe UI\',Tahoma,Verdana,sans-serif;border-bottom:1px solid #e8e0d4;">' . htmlspecialchars($room['name']) . '</td></tr></table>

            <table role="presentation" width="100%" cellspacing="0" cellpadding="0" style="margin:0;"><tr><td style="padding:10px 10px 10px 0;font-weight:bold;color:#1A1A1A;width:44%;vertical-align:top;font-family:\'Segoe UI\',Tahoma,Verdana,sans-serif;border-bottom:1px solid #e8e0d4;">Check-in Date:</td><td style="padding:10px 0 10px 6px;color: #333;;text-align:left;vertical-align:top;font-family:\'Segoe UI\',Tahoma,Verdana,sans-serif;border-bottom:1px solid #e8e0d4;">' . date('F j, Y', strtotime($booking['check_in_date'])) . '</td></tr></table>

            <table role="presentation" width="100%" cellspacing="0" cellpadding="0" style="margin:0;"><tr><td style="padding:10px 10px 10px 0;font-weight:bold;color:#1A1A1A;width:44%;vertical-align:top;font-family:\'Segoe UI\',Tahoma,Verdana,sans-serif;border-bottom:1px solid #e8e0d4;">Check-out Date:</td><td style="padding:10px 0 10px 6px;color: #333;;text-align:left;vertical-align:top;font-family:\'Segoe UI\',Tahoma,Verdana,sans-serif;border-bottom:1px solid #e8e0d4;">' . date('F j, Y', strtotime($booking['check_out_date'])) . '</td></tr></table>

            <table role="presentation" width="100%" cellspacing="0" cellpadding="0" style="margin:0;"><tr><td style="padding:10px 10px 10px 0;font-weight:bold;color:#1A1A1A;width:44%;vertical-align:top;font-family:\'Segoe UI\',Tahoma,Verdana,sans-serif;border-bottom:1px solid #e8e0d4;">Number of Nights:</td><td style="padding:10px 0 10px 6px;color: #333;;text-align:left;vertical-align:top;font-family:\'Segoe UI\',Tahoma,Verdana,sans-serif;border-bottom:1px solid #e8e0d4;">' . $booking['number_of_nights'] . ' night' . ($booking['number_of_nights'] != 1 ? 's' : '') . '</td></tr></table>

            <table role="presentation" width="100%" cellspacing="0" cellpadding="0" style="margin:0;"><tr><td style="padding:10px 10px 10px 0;font-weight:bold;color:#1A1A1A;width:44%;vertical-align:top;font-family:\'Segoe UI\',Tahoma,Verdana,sans-serif;border-bottom:1px solid #e8e0d4;">Number of Guests:</td><td style="padding:10px 0 10px 6px;color: #333;;text-align:left;vertical-align:top;font-family:\'Segoe UI\',Tahoma,Verdana,sans-serif;border-bottom:1px solid #e8e0d4;">' . $booking['number_of_guests'] . ' guest' . ($booking['number_of_guests'] != 1 ? 's' : '') . '</td></tr></table>

            <table role="presentation" width="100%" cellspacing="0" cellpadding="0" style="margin:0;"><tr><td style="padding:10px 10px 10px 0;font-weight:bold;color:#1A1A1A;width:44%;vertical-align:top;font-family:\'Segoe UI\',Tahoma,Verdana,sans-serif;">Total Amount:</td><td style="padding:10px 0 10px 6px;color: #8B7355; font-weight: bold; font-size: 18px;;text-align:left;vertical-align:top;font-family:\'Segoe UI\',Tahoma,Verdana,sans-serif;">' . getSetting('currency_symbol') . ' ' . number_format($booking['total_amount'], 0) . '</td></tr></table>
        </div>

        <div style="background: #d4edda; padding: 15px; border-left: 4px solid #28a745; border-radius: 5px; margin: 20px 0;">
            <h3 style="color: #155724; margin-top: 0;;text-align:left;">✅ Booking Status: Confirmed</h3>
            <p style="color: #155724; margin: 0;">
                <strong>Your booking is now confirmed and guaranteed!</strong><br>
                We look forward to welcoming you to ' . htmlspecialchars($email_site_name) . '.
            </p>
        </div>

        <div style="background: #fff3cd; padding: 15px; border-left: 4px solid #ffc107; border-radius: 5px; margin: 20px 0;">
            <h3 style="color: #856404; margin-top: 0;;text-align:left;">Payment Information</h3>
            <p style="color: #856404; margin: 0;">
                ' . getSetting('payment_policy', 'Payment will be made at the hotel upon arrival.<br>We accept cash payments only. Please bring the total amount of <strong>' . getSetting('currency_symbol') . ' ' . number_format($booking['total_amount'], 0) . '</strong> with you.') . '
            </p>
        </div>

        <div style="background: #FDF6EC; padding: 15px; border-left: 4px solid #8B7355; border-radius: 5px; margin: 20px 0;">
            <h3 style="color: #5C4A32; margin-top: 0;;text-align:left;">Next Steps</h3>
            <p style="color: #5C4A32; margin: 0;">
                <strong>Please save your booking reference:</strong> ' . htmlspecialchars($booking['booking_reference']) . '<br>
                <strong>Check-in time:</strong> ' . getSetting('check_in_time', '2:00 PM') . '<br>
                <strong>Check-out time:</strong> ' . getSetting('check_out_time', '11:00 AM') . '<br>
                <strong>Contact us:</strong> If you need to make any changes, please contact us at least ' . getSetting('booking_change_policy', '48 hours') . ' before your arrival.
            </p>
        </div>';

        if (!empty($booking['special_requests'])) {
            $htmlBody .= '
            <div style="background: #FDF6EC; padding: 15px; border-left: 4px solid #8B7355; border-radius: 5px; margin: 20px 0;">
                <h3 style="color: #5C4A32; margin-top: 0;;text-align:left;">Special Requests</h3>
                <p style="color: #5C4A32; margin: 0;">' . htmlspecialchars($booking['special_requests']) . '</p>
            </div>';
        }

        $htmlBody .= '
        <p>If you have any questions, please contact us at <a href="mailto:' . htmlspecialchars($email_from_email) . '">' . htmlspecialchars($email_from_email) . '</a> or call ' . getSetting('phone_main') . '.</p>

        <p>We look forward to welcoming you to ' . htmlspecialchars($email_site_name) . '!</p>
        <p style="margin:28px 0 0;font-size:14px;color:#777;text-align:center;font-style:italic;">
            Warm regards &mdash; see you soon.
        </p>';

        // Send email with unique subject line for conversion
        return sendEmail(
            $booking['guest_email'],
            $booking['guest_name'],
            'Booking Confirmed (Converted) - ' . htmlspecialchars($email_site_name) . ' [' . $booking['booking_reference'] . ']',
            $htmlBody
        );
    } catch (Exception $e) {
        error_log("Send Tentative Booking Converted Email Error: " . $e->getMessage());
        return [
            'success' => false,
            'message' => $e->getMessage()
        ];
    }
}

/**
 * Send pending booking expired email (when pending booking expires)
 */
function sendPendingBookingExpiredEmail(array $booking, ?array $room = null)
{
    global $pdo, $email_from_name, $email_from_email, $email_admin_email, $email_site_name, $email_site_url;

    try {
        // Get room details if not provided
        if (!$room) {
            $stmt = $pdo->prepare("SELECT * FROM rooms WHERE id = ?");
            $stmt->execute([$booking['room_id']]);
            $room = $stmt->fetch(PDO::FETCH_ASSOC);
        }

        // Prepare email content
        $htmlBody = '
        <h1 style="color: #6c757d; text-align: center;">Pending Booking Expired</h1>
        <p>Dear ' . htmlspecialchars($booking['guest_name']) . ',</p>
        <p>Your pending booking at <strong>' . htmlspecialchars($email_site_name) . '</strong> has expired due to lack of confirmation.</p>

        <div style="background: #e2e3e5; padding: 15px; border-left: 4px solid #6c757d; border-radius: 5px; margin: 20px 0;">
            <h3 style="color: #383d41; margin-top: 0;;text-align:left;">What This Means</h3>
            <p style="color: #383d41; margin: 0;">
                Your booking request was not confirmed within the required time period.<br>
                The room has been released and is now available for other guests.<br>
                There is no penalty for an expired pending booking.
            </p>
        </div>

        <div style="background: #FAF6F0; border: 2px solid #C8A45A; padding: 20px; margin: 20px 0; border-radius: 10px;">
            <h2 style="color: #8B7355; margin-top: 0;;text-align:left;">Expired Booking Details</h2>

            <table role="presentation" width="100%" cellspacing="0" cellpadding="0" style="margin:0;"><tr><td style="padding:10px 10px 10px 0;font-weight:bold;color:#1A1A1A;width:44%;vertical-align:top;font-family:\'Segoe UI\',Tahoma,Verdana,sans-serif;border-bottom:1px solid #e8e0d4;">Booking Reference:</td><td style="padding:10px 0 10px 6px;color: #6c757d; font-weight: bold; font-size: 18px;;text-align:left;vertical-align:top;font-family:\'Segoe UI\',Tahoma,Verdana,sans-serif;border-bottom:1px solid #e8e0d4;">' . htmlspecialchars($booking['booking_reference']) . '</td></tr></table>

            <table role="presentation" width="100%" cellspacing="0" cellpadding="0" style="margin:0;"><tr><td style="padding:10px 10px 10px 0;font-weight:bold;color:#1A1A1A;width:44%;vertical-align:top;font-family:\'Segoe UI\',Tahoma,Verdana,sans-serif;border-bottom:1px solid #e8e0d4;">Room:</td><td style="padding:10px 0 10px 6px;color: #333;;text-align:left;vertical-align:top;font-family:\'Segoe UI\',Tahoma,Verdana,sans-serif;border-bottom:1px solid #e8e0d4;">' . htmlspecialchars($room['name'] ?? 'N/A') . '</td></tr></table>

            <table role="presentation" width="100%" cellspacing="0" cellpadding="0" style="margin:0;"><tr><td style="padding:10px 10px 10px 0;font-weight:bold;color:#1A1A1A;width:44%;vertical-align:top;font-family:\'Segoe UI\',Tahoma,Verdana,sans-serif;border-bottom:1px solid #e8e0d4;">Check-in Date:</td><td style="padding:10px 0 10px 6px;color: #333;;text-align:left;vertical-align:top;font-family:\'Segoe UI\',Tahoma,Verdana,sans-serif;border-bottom:1px solid #e8e0d4;">' . date('F j, Y', strtotime($booking['check_in_date'])) . '</td></tr></table>

            <table role="presentation" width="100%" cellspacing="0" cellpadding="0" style="margin:0;"><tr><td style="padding:10px 10px 10px 0;font-weight:bold;color:#1A1A1A;width:44%;vertical-align:top;font-family:\'Segoe UI\',Tahoma,Verdana,sans-serif;border-bottom:1px solid #e8e0d4;">Check-out Date:</td><td style="padding:10px 0 10px 6px;color: #333;;text-align:left;vertical-align:top;font-family:\'Segoe UI\',Tahoma,Verdana,sans-serif;border-bottom:1px solid #e8e0d4;">' . date('F j, Y', strtotime($booking['check_out_date'])) . '</td></tr></table>

            <table role="presentation" width="100%" cellspacing="0" cellpadding="0" style="margin:0;"><tr><td style="padding:10px 10px 10px 0;font-weight:bold;color:#1A1A1A;width:44%;vertical-align:top;font-family:\'Segoe UI\',Tahoma,Verdana,sans-serif;">Total Amount:</td><td style="padding:10px 0 10px 6px;color: #8B7355; font-weight: bold; font-size: 18px;;text-align:left;vertical-align:top;font-family:\'Segoe UI\',Tahoma,Verdana,sans-serif;">' . getSetting('currency_symbol') . ' ' . number_format($booking['total_amount'], 0) . '</td></tr></table>
        </div>

        <p>If you are still interested in booking with us, please visit our website to check availability and make a new booking.</p>

        <div style="text-align: center; margin-top: 30px;">
            <a href="' . htmlspecialchars($email_site_url) . '/booking.php"
               style="display: inline-block; background: #8B7355; color: #1A1A1A; padding: 12px 24px; text-decoration: none; border-radius: 5px; font-weight: bold;">
                Make a New Booking
            </a>
        </div>

        <p style="margin-top: 20px;">If you have any questions, please contact us at <a href="mailto:' . htmlspecialchars($email_from_email) . '">' . htmlspecialchars($email_from_email) . '</a> or call ' . getSetting('phone_main') . '.</p>
        <p style="margin:28px 0 0;font-size:14px;color:#777;text-align:center;font-style:italic;">
            Warm regards &mdash; see you soon.
        </p>';

        // Send email
        return sendEmail(
            $booking['guest_email'],
            $booking['guest_name'],
            'Pending Booking Expired - ' . $booking['booking_reference'],
            $htmlBody
        );
    } catch (Exception $e) {
        error_log("Send Pending Booking Expired Email Error: " . $e->getMessage());
        return [
            'success' => false,
            'message' => $e->getMessage()
        ];
    }
}

/**
 * Send admin notification for expired booking
 */
function sendAdminBookingExpiredNotification(array $booking, string $booking_type = 'tentative')
{
    global $pdo, $email_from_name, $email_from_email, $email_admin_email, $email_site_name, $email_site_url;

    try {
        // Get room details
        $stmt = $pdo->prepare("SELECT * FROM rooms WHERE id = ?");
        $stmt->execute([$booking['room_id']]);
        $room = $stmt->fetch(PDO::FETCH_ASSOC);

        // Get admin notification email with fallback
        $admin_notification_email = getSetting('admin_notification_email');
        if (empty($admin_notification_email) || !filter_var($admin_notification_email, FILTER_VALIDATE_EMAIL)) {
            $admin_notification_email = $email_admin_email;
        }

        // Determine reason based on booking type
        $reason = $booking_type === 'tentative'
            ? 'Tentative booking expired (not confirmed within time limit)'
            : 'Pending booking expired (not confirmed within time limit)';

        $type_label = $booking_type === 'tentative' ? 'Tentative' : 'Pending';

        // Prepare email content
        $htmlBody = '
        <h1 style="color: #dc3545; text-align: center;">🔔 Booking Auto-Expired</h1>
        <p>A ' . strtolower($type_label) . ' booking has been automatically expired by the system.</p>

        <div style="background: #f8d7da; padding: 15px; border-left: 4px solid #dc3545; border-radius: 5px; margin: 20px 0;">
            <h3 style="color: #721c24; margin-top: 0;;text-align:left;">Expiration Details</h3>
            <p style="color: #721c24; margin: 0;">
                <strong>Booking Type:</strong> ' . htmlspecialchars($type_label) . '<br>
                <strong>Reason:</strong> ' . htmlspecialchars($reason) . '<br>
                <strong>Expired At:</strong> ' . date('F j, Y g:i A') . '
            </p>
        </div>

        <div style="background: #FAF6F0; border: 2px solid #C8A45A; padding: 20px; margin: 20px 0; border-radius: 10px;">
            <h2 style="color: #8B7355; margin-top: 0;;text-align:left;">Booking Details</h2>

            <table role="presentation" width="100%" cellspacing="0" cellpadding="0" style="margin:0;"><tr><td style="padding:10px 10px 10px 0;font-weight:bold;color:#1A1A1A;width:44%;vertical-align:top;font-family:\'Segoe UI\',Tahoma,Verdana,sans-serif;border-bottom:1px solid #e8e0d4;">Booking Reference:</td><td style="padding:10px 0 10px 6px;color: #dc3545; font-weight: bold; font-size: 18px;;text-align:left;vertical-align:top;font-family:\'Segoe UI\',Tahoma,Verdana,sans-serif;border-bottom:1px solid #e8e0d4;">' . htmlspecialchars($booking['booking_reference']) . '</td></tr></table>

            <table role="presentation" width="100%" cellspacing="0" cellpadding="0" style="margin:0;"><tr><td style="padding:10px 10px 10px 0;font-weight:bold;color:#1A1A1A;width:44%;vertical-align:top;font-family:\'Segoe UI\',Tahoma,Verdana,sans-serif;border-bottom:1px solid #e8e0d4;">Guest Name:</td><td style="padding:10px 0 10px 6px;color: #333;;text-align:left;vertical-align:top;font-family:\'Segoe UI\',Tahoma,Verdana,sans-serif;border-bottom:1px solid #e8e0d4;">' . htmlspecialchars($booking['guest_name']) . '</td></tr></table>

            <table role="presentation" width="100%" cellspacing="0" cellpadding="0" style="margin:0;"><tr><td style="padding:10px 10px 10px 0;font-weight:bold;color:#1A1A1A;width:44%;vertical-align:top;font-family:\'Segoe UI\',Tahoma,Verdana,sans-serif;border-bottom:1px solid #e8e0d4;">Guest Email:</td><td style="padding:10px 0 10px 6px;color: #333;;text-align:left;vertical-align:top;font-family:\'Segoe UI\',Tahoma,Verdana,sans-serif;border-bottom:1px solid #e8e0d4;">' . htmlspecialchars($booking['guest_email']) . '</td></tr></table>

            <table role="presentation" width="100%" cellspacing="0" cellpadding="0" style="margin:0;"><tr><td style="padding:10px 10px 10px 0;font-weight:bold;color:#1A1A1A;width:44%;vertical-align:top;font-family:\'Segoe UI\',Tahoma,Verdana,sans-serif;border-bottom:1px solid #e8e0d4;">Guest Phone:</td><td style="padding:10px 0 10px 6px;color: #333;;text-align:left;vertical-align:top;font-family:\'Segoe UI\',Tahoma,Verdana,sans-serif;border-bottom:1px solid #e8e0d4;">' . htmlspecialchars($booking['guest_phone']) . '</td></tr></table>

            <table role="presentation" width="100%" cellspacing="0" cellpadding="0" style="margin:0;"><tr><td style="padding:10px 10px 10px 0;font-weight:bold;color:#1A1A1A;width:44%;vertical-align:top;font-family:\'Segoe UI\',Tahoma,Verdana,sans-serif;border-bottom:1px solid #e8e0d4;">Room:</td><td style="padding:10px 0 10px 6px;color: #333;;text-align:left;vertical-align:top;font-family:\'Segoe UI\',Tahoma,Verdana,sans-serif;border-bottom:1px solid #e8e0d4;">' . htmlspecialchars($room['name'] ?? 'N/A') . '</td></tr></table>

            <table role="presentation" width="100%" cellspacing="0" cellpadding="0" style="margin:0;"><tr><td style="padding:10px 10px 10px 0;font-weight:bold;color:#1A1A1A;width:44%;vertical-align:top;font-family:\'Segoe UI\',Tahoma,Verdana,sans-serif;border-bottom:1px solid #e8e0d4;">Check-in Date:</td><td style="padding:10px 0 10px 6px;color: #333;;text-align:left;vertical-align:top;font-family:\'Segoe UI\',Tahoma,Verdana,sans-serif;border-bottom:1px solid #e8e0d4;">' . date('F j, Y', strtotime($booking['check_in_date'])) . '</td></tr></table>

            <table role="presentation" width="100%" cellspacing="0" cellpadding="0" style="margin:0;"><tr><td style="padding:10px 10px 10px 0;font-weight:bold;color:#1A1A1A;width:44%;vertical-align:top;font-family:\'Segoe UI\',Tahoma,Verdana,sans-serif;border-bottom:1px solid #e8e0d4;">Check-out Date:</td><td style="padding:10px 0 10px 6px;color: #333;;text-align:left;vertical-align:top;font-family:\'Segoe UI\',Tahoma,Verdana,sans-serif;border-bottom:1px solid #e8e0d4;">' . date('F j, Y', strtotime($booking['check_out_date'])) . '</td></tr></table>

            <table role="presentation" width="100%" cellspacing="0" cellpadding="0" style="margin:0;"><tr><td style="padding:10px 10px 10px 0;font-weight:bold;color:#1A1A1A;width:44%;vertical-align:top;font-family:\'Segoe UI\',Tahoma,Verdana,sans-serif;">Total Amount:</td><td style="padding:10px 0 10px 6px;color: #8B7355; font-weight: bold; font-size: 18px;;text-align:left;vertical-align:top;font-family:\'Segoe UI\',Tahoma,Verdana,sans-serif;">' . getSetting('currency_symbol') . ' ' . number_format($booking['total_amount'], 0) . '</td></tr></table>
        </div>

        <div style="background: #FDF6EC; padding: 15px; border-left: 4px solid #8B7355; border-radius: 5px; margin: 20px 0;">
            <h3 style="color: #5C4A32; margin-top: 0;;text-align:left;">Actions Taken</h3>
            <p style="color: #5C4A32; margin: 0;">
                <strong>✓</strong> Booking status changed to "expired"<br>
                <strong>✓</strong> Room availability restored<br>
                <strong>✓</strong> Expiration email sent to guest<br>
                <strong>✓</strong> Logged to tentative_booking_log
            </p>
        </div>

        <div style="text-align: center; margin-top: 30px;">
            <a href="' . htmlspecialchars($email_site_url) . '/admin/tentative-bookings.php?status=expired"
               style="display: inline-block; background: #8B7355; color: #1A1A1A; padding: 12px 24px; text-decoration: none; border-radius: 5px; font-weight: bold; font-size: 16px;">
                View Expired Bookings
            </a>
        </div>';

        // Send email
        return sendEmail(
            $admin_notification_email,
            'Admin Team',
            'Booking Auto-Expired: ' . $booking['booking_reference'] . ' - ' . $booking['guest_name'],
            $htmlBody
        );
    } catch (Exception $e) {
        error_log("Send Admin Booking Expired Notification Error: " . $e->getMessage());
        return [
            'success' => false,
            'message' => $e->getMessage()
        ];
    }
}

/**
 * Send gym booking confirmation email to customer
 *
 * @param array $data Booking data with keys: name, email, phone, preferred_date, preferred_time, package_choice, guests, goals
 * @return array Result array with success status and message
 */
function sendGymBookingEmail(array $data)
{
    global $email_from_name, $email_from_email, $email_admin_email, $email_site_name, $email_site_url;

    try {
        $site_name = getSetting('site_name');

        // Try template-first rendering for customization support
        $template_data = [
            'name' => $data['name'] ?? '',
            'email' => $data['email'] ?? '',
            'phone' => $data['phone'] ?? '',
            'preferred_date' => $data['preferred_date'] ?? '',
            'preferred_time' => $data['preferred_time'] ?? '',
            'package_choice' => $data['package_choice'] ?? '',
            'guests' => (int)($data['guests'] ?? 1),
            'goals' => $data['goals'] ?? ''
        ];
        $htmlBody = loadEmailTemplate('gym-booking-customer.html', $template_data);

        // Fallback if template missing
        if (empty($htmlBody)) {
            $htmlBody = '
        <h1 style="color: #8B7355; text-align: center;">Gym Booking Request Received</h1>
        <p>Dear ' . htmlspecialchars($data['name']) . ',</p>
        <p>Thank you for your gym booking request with <strong>' . htmlspecialchars($site_name) . '</strong>. We have received your submission and will confirm your booking shortly.</p>

        <div style="background: #FAF6F0; border: 2px solid #C8A45A; padding: 20px; margin: 20px 0; border-radius: 10px;">
            <h2 style="color: #8B7355; margin-top: 0;;text-align:left;">Booking Details</h2>

            <table role="presentation" width="100%" cellspacing="0" cellpadding="0" style="margin:0;"><tr><td style="padding:10px 10px 10px 0;font-weight:bold;color:#1A1A1A;width:44%;vertical-align:top;font-family:\'Segoe UI\',Tahoma,Verdana,sans-serif;border-bottom:1px solid #e8e0d4;">Name:</td><td style="padding:10px 0 10px 6px;color: #333;;text-align:left;vertical-align:top;font-family:\'Segoe UI\',Tahoma,Verdana,sans-serif;border-bottom:1px solid #e8e0d4;">' . htmlspecialchars($data['name']) . '</td></tr></table>

            <table role="presentation" width="100%" cellspacing="0" cellpadding="0" style="margin:0;"><tr><td style="padding:10px 10px 10px 0;font-weight:bold;color:#1A1A1A;width:44%;vertical-align:top;font-family:\'Segoe UI\',Tahoma,Verdana,sans-serif;border-bottom:1px solid #e8e0d4;">Email:</td><td style="padding:10px 0 10px 6px;color: #333;;text-align:left;vertical-align:top;font-family:\'Segoe UI\',Tahoma,Verdana,sans-serif;border-bottom:1px solid #e8e0d4;">' . htmlspecialchars($data['email']) . '</td></tr></table>

            <table role="presentation" width="100%" cellspacing="0" cellpadding="0" style="margin:0;"><tr><td style="padding:10px 10px 10px 0;font-weight:bold;color:#1A1A1A;width:44%;vertical-align:top;font-family:\'Segoe UI\',Tahoma,Verdana,sans-serif;border-bottom:1px solid #e8e0d4;">Phone:</td><td style="padding:10px 0 10px 6px;color: #333;;text-align:left;vertical-align:top;font-family:\'Segoe UI\',Tahoma,Verdana,sans-serif;border-bottom:1px solid #e8e0d4;">' . htmlspecialchars($data['phone']) . '</td></tr></table>

            <table role="presentation" width="100%" cellspacing="0" cellpadding="0" style="margin:0;"><tr><td style="padding:10px 10px 10px 0;font-weight:bold;color:#1A1A1A;width:44%;vertical-align:top;font-family:\'Segoe UI\',Tahoma,Verdana,sans-serif;border-bottom:1px solid #e8e0d4;">Preferred Date:</td><td style="padding:10px 0 10px 6px;color: #333;;text-align:left;vertical-align:top;font-family:\'Segoe UI\',Tahoma,Verdana,sans-serif;border-bottom:1px solid #e8e0d4;">' . htmlspecialchars($data['preferred_date']) . '</td></tr></table>

            <table role="presentation" width="100%" cellspacing="0" cellpadding="0" style="margin:0;"><tr><td style="padding:10px 10px 10px 0;font-weight:bold;color:#1A1A1A;width:44%;vertical-align:top;font-family:\'Segoe UI\',Tahoma,Verdana,sans-serif;border-bottom:1px solid #e8e0d4;">Preferred Time:</td><td style="padding:10px 0 10px 6px;color: #333;;text-align:left;vertical-align:top;font-family:\'Segoe UI\',Tahoma,Verdana,sans-serif;border-bottom:1px solid #e8e0d4;">' . htmlspecialchars($data['preferred_time']) . '</td></tr></table>

            <table role="presentation" width="100%" cellspacing="0" cellpadding="0" style="margin:0;"><tr><td style="padding:10px 10px 10px 0;font-weight:bold;color:#1A1A1A;width:44%;vertical-align:top;font-family:\'Segoe UI\',Tahoma,Verdana,sans-serif;border-bottom:1px solid #e8e0d4;">Package:</td><td style="padding:10px 0 10px 6px;color: #333;;text-align:left;vertical-align:top;font-family:\'Segoe UI\',Tahoma,Verdana,sans-serif;border-bottom:1px solid #e8e0d4;">' . htmlspecialchars($data['package_choice']) . '</td></tr></table>

            ';
            $guestCount1 = (!empty($data['guests']) && $data['guests'] > 1) ? (int)$data['guests'] : 1;
            $htmlBody .= '<table role="presentation" width="100%" cellspacing="0" cellpadding="0" style="margin:0;"><tr><td style="padding:10px 8px 10px 0;font-weight:bold;color:#1A1A1A;width:44%;vertical-align:top;">Number of Guests:</td><td style="padding:10px 0 10px 8px;color:#333;text-align:left;vertical-align:top;">' . $guestCount1 . '</td></tr></table>';
            $htmlBody .= '
        </div>';

            if (!empty($data['goals'])) {
                $htmlBody .= '
            <div style="background: #FDF6EC; padding: 15px; border-left: 4px solid #8B7355; border-radius: 5px; margin: 20px 0;">
                <h3 style="color: #5C4A32; margin-top: 0;;text-align:left;">Fitness Goals / Notes</h3>
                <p style="color: #5C4A32; margin: 0;">' . nl2br(htmlspecialchars($data['goals'])) . '</p>
            </div>';
            }

            $htmlBody .= '
        <div style="background: #FDF6EC; padding: 15px; border-left: 4px solid #8B7355; border-radius: 5px; margin: 20px 0;">
            <h3 style="color: #5C4A32; margin-top: 0;;text-align:left;">What Happens Next?</h3>
            <p style="color: #5C4A32; margin: 0;">
                <strong>Our team will contact you within 24 hours to confirm your booking.</strong><br>
                If you have any questions in the meantime, please contact us.
            </p>
        </div>

        <p>If you have any questions, please contact us at <a href="mailto:' . htmlspecialchars($email_from_email) . '">' . htmlspecialchars($email_from_email) . '</a> or call ' . getSetting('phone_main') . '.</p>

        <p>Thank you for choosing ' . htmlspecialchars($site_name) . '!</p>
        <p style="margin:28px 0 0;font-size:14px;color:#777;text-align:center;font-style:italic;">
            Warm regards &mdash; see you soon.
        </p>';
        }

        // Send email
        return sendEmail(
            $data['email'],
            $data['name'],
            'Gym Booking Request Received - ' . htmlspecialchars($site_name),
            $htmlBody
        );
    } catch (Exception $e) {
        error_log("Send Gym Booking Email Error: " . $e->getMessage());
        return [
            'success' => false,
            'message' => $e->getMessage()
        ];
    }
}

/**
 * Send gym booking notification email to admin
 *
 * @param array $data Booking data with keys: name, email, phone, preferred_date, preferred_time, package_choice, guests, goals
 * @return array Result array with success status and message
 */
function sendGymAdminNotificationEmail(array $data)
{
    global $email_from_name, $email_from_email, $email_admin_email, $email_site_name, $email_site_url;

    try {
        $site_name = getSetting('site_name');

        // Get gym email with fallback to main contact email
        $gym_email = getSetting('gym_email');
        if (empty($gym_email) || !filter_var($gym_email, FILTER_VALIDATE_EMAIL)) {
            $gym_email = getSetting('email_main');
        }
        if (empty($gym_email) || !filter_var($gym_email, FILTER_VALIDATE_EMAIL)) {
            $gym_email = $email_admin_email;
        }

        // Try template-first rendering for customization support
        $template_data = [
            'name' => $data['name'] ?? '',
            'email' => $data['email'] ?? '',
            'phone' => $data['phone'] ?? '',
            'preferred_date' => $data['preferred_date'] ?? '',
            'preferred_time' => $data['preferred_time'] ?? '',
            'package_choice' => $data['package_choice'] ?? '',
            'guests' => (int)($data['guests'] ?? 1),
            'goals' => $data['goals'] ?? '',
            'submission_date' => date('F j, Y g:i A')
        ];
        $htmlBody = loadEmailTemplate('gym-booking-admin.html', $template_data);

        // Fallback if template missing
        if (empty($htmlBody)) {
            $htmlBody = '
        <h1 style="color: #8B7355; text-align: center;">📋 New Gym Booking Request</h1>
        <p>A new gym booking request has been submitted on the website.</p>

        <div style="background: #FAF6F0; border: 2px solid #C8A45A; padding: 20px; margin: 20px 0; border-radius: 10px;">
            <h2 style="color: #8B7355; margin-top: 0;;text-align:left;">Booking Details</h2>

            <table role="presentation" width="100%" cellspacing="0" cellpadding="0" style="margin:0;"><tr><td style="padding:10px 10px 10px 0;font-weight:bold;color:#1A1A1A;width:44%;vertical-align:top;font-family:\'Segoe UI\',Tahoma,Verdana,sans-serif;border-bottom:1px solid #e8e0d4;">Name:</td><td style="padding:10px 0 10px 6px;color: #333;;text-align:left;vertical-align:top;font-family:\'Segoe UI\',Tahoma,Verdana,sans-serif;border-bottom:1px solid #e8e0d4;">' . htmlspecialchars($data['name']) . '</td></tr></table>

            <table role="presentation" width="100%" cellspacing="0" cellpadding="0" style="margin:0;"><tr><td style="padding:10px 10px 10px 0;font-weight:bold;color:#1A1A1A;width:44%;vertical-align:top;font-family:\'Segoe UI\',Tahoma,Verdana,sans-serif;border-bottom:1px solid #e8e0d4;">Email:</td><td style="padding:10px 0 10px 6px;color: #333;;text-align:left;vertical-align:top;font-family:\'Segoe UI\',Tahoma,Verdana,sans-serif;border-bottom:1px solid #e8e0d4;">' . htmlspecialchars($data['email']) . '</td></tr></table>

            <table role="presentation" width="100%" cellspacing="0" cellpadding="0" style="margin:0;"><tr><td style="padding:10px 10px 10px 0;font-weight:bold;color:#1A1A1A;width:44%;vertical-align:top;font-family:\'Segoe UI\',Tahoma,Verdana,sans-serif;border-bottom:1px solid #e8e0d4;">Phone:</td><td style="padding:10px 0 10px 6px;color: #333;;text-align:left;vertical-align:top;font-family:\'Segoe UI\',Tahoma,Verdana,sans-serif;border-bottom:1px solid #e8e0d4;">' . htmlspecialchars($data['phone']) . '</td></tr></table>

            <table role="presentation" width="100%" cellspacing="0" cellpadding="0" style="margin:0;"><tr><td style="padding:10px 10px 10px 0;font-weight:bold;color:#1A1A1A;width:44%;vertical-align:top;font-family:\'Segoe UI\',Tahoma,Verdana,sans-serif;border-bottom:1px solid #e8e0d4;">Preferred Date:</td><td style="padding:10px 0 10px 6px;color: #333;;text-align:left;vertical-align:top;font-family:\'Segoe UI\',Tahoma,Verdana,sans-serif;border-bottom:1px solid #e8e0d4;">' . htmlspecialchars($data['preferred_date']) . '</td></tr></table>

            <table role="presentation" width="100%" cellspacing="0" cellpadding="0" style="margin:0;"><tr><td style="padding:10px 10px 10px 0;font-weight:bold;color:#1A1A1A;width:44%;vertical-align:top;font-family:\'Segoe UI\',Tahoma,Verdana,sans-serif;border-bottom:1px solid #e8e0d4;">Preferred Time:</td><td style="padding:10px 0 10px 6px;color: #333;;text-align:left;vertical-align:top;font-family:\'Segoe UI\',Tahoma,Verdana,sans-serif;border-bottom:1px solid #e8e0d4;">' . htmlspecialchars($data['preferred_time']) . '</td></tr></table>

            <table role="presentation" width="100%" cellspacing="0" cellpadding="0" style="margin:0;"><tr><td style="padding:10px 10px 10px 0;font-weight:bold;color:#1A1A1A;width:44%;vertical-align:top;font-family:\'Segoe UI\',Tahoma,Verdana,sans-serif;border-bottom:1px solid #e8e0d4;">Package:</td><td style="padding:10px 0 10px 6px;color: #333;;text-align:left;vertical-align:top;font-family:\'Segoe UI\',Tahoma,Verdana,sans-serif;border-bottom:1px solid #e8e0d4;">' . htmlspecialchars($data['package_choice']) . '</td></tr></table>

            ';
            $guestCount2 = (!empty($data['guests']) && $data['guests'] > 1) ? (int)$data['guests'] : 1;
            $htmlBody .= '<table role="presentation" width="100%" cellspacing="0" cellpadding="0" style="margin:0;"><tr><td style="padding:10px 8px 10px 0;font-weight:bold;color:#1A1A1A;width:44%;vertical-align:top;">Number of Guests:</td><td style="padding:10px 0 10px 8px;color:#333;text-align:left;vertical-align:top;">' . $guestCount2 . '</td></tr></table>';
            $htmlBody .= '
        </div>';

            if (!empty($data['goals'])) {
                $htmlBody .= '
            <div style="background: #FDF6EC; padding: 15px; border-left: 4px solid #8B7355; border-radius: 5px; margin: 20px 0;">
                <h3 style="color: #5C4A32; margin-top: 0;;text-align:left;">Fitness Goals / Notes</h3>
                <p style="color: #5C4A32; margin: 0;">' . nl2br(htmlspecialchars($data['goals'])) . '</p>
            </div>';
            }

            $htmlBody .= '
        <div style="text-align: center; margin-top: 30px;">
            <a href="mailto:' . htmlspecialchars($data['email']) . '"
               style="display: inline-block; background: #8B7355; color: #1A1A1A; padding: 12px 24px; text-decoration: none; border-radius: 5px; font-weight: bold; font-size: 16px;">
                Reply to Customer
            </a>
        </div>';
        }

        // Send email
        return sendEmail(
            $gym_email,
            'Gym Team',
            'New Gym Booking Request - ' . htmlspecialchars($data['name']),
            $htmlBody
        );
    } catch (Exception $e) {
        error_log("Send Gym Admin Notification Email Error: " . $e->getMessage());
        return [
            'success' => false,
            'message' => $e->getMessage()
        ];
    }
}

/**
 * Get hotel logo URL for emails
 * IMPORTANT: Email clients require absolute URLs for images
 */
function getHotelLogoUrl()
{
    $site_url = trim((string)getSetting('site_url', ''));
    $candidates = [
        (string)getSetting('site_logo', ''),
        (string)getSetting('logo_url', ''),
        (string)getSetting('hotel_logo', ''),
        'images/logo/logo.png',
    ];

    $selected = '';
    foreach ($candidates as $candidate) {
        $candidate = trim($candidate);
        if ($candidate === '') {
            continue;
        }

        if (preg_match('#^https?://#i', $candidate)) {
            return $candidate;
        }

        $relative = ltrim($candidate, '/');
        $localPath = __DIR__ . '/../' . $relative;
        if (is_file($localPath)) {
            $selected = $relative;
            break;
        }

        if ($selected === '') {
            $selected = $relative;
        }
    }

    if ($selected === '') {
        return '';
    }

    $base_url = $site_url !== '' ? $site_url : (defined('BASE_URL') ? (string)BASE_URL : '');
    if ($base_url === '') {
        return $selected;
    }

    return rtrim($base_url, '/') . '/' . ltrim($selected, '/');
}

/**
 * Wrap email content with beautiful hotel branding template
 *
 * @param string $content The email body content
 * @param string $title Optional email title/heading
 * @return string Wrapped HTML email
 */
function wrapEmailTemplate(string $content, string $title = '')
{
    global $email_site_name, $email_site_url, $email_from_email;

    $site_name     = $email_site_name ?: getSetting('site_name', 'Our Hotel');
    $site_url      = $email_site_url  ?: getSetting('site_url', '');
    $contact_email = $email_from_email ?: getSetting('email_main', '');
    $phone         = getSetting('phone_main', '');
    $logo_url      = getHotelLogoUrl();

    $accent = '#8B7355';
    $dark   = '#1A1A1A';

    // Logo block
    $logo_html = '';
    if (!empty($logo_url)) {
        $logo_html = '<img src="' . htmlspecialchars($logo_url) . '" alt="' . htmlspecialchars($site_name) . '"
                          style="max-width:200px;height:auto;display:block;margin:0 auto 14px;">';
    }

    // Contact rows — each item stacks vertically in its own table row so they always centre
    $contact_rows = '';
    if (!empty($phone)) {
        $contact_rows .= '
                                <tr>
                                    <td align="center" style="padding:4px 0;">
                                        <a href="tel:' . htmlspecialchars($phone) . '"
                                           style="color:' . $accent . ';text-decoration:none;font-size:14px;font-family:\'Segoe UI\',Tahoma,Verdana,sans-serif;">'
            . htmlspecialchars($phone) . '</a>
                                    </td>
                                </tr>';
    }
    if (!empty($contact_email)) {
        $contact_rows .= '
                                <tr>
                                    <td align="center" style="padding:4px 0;">
                                        <a href="mailto:' . htmlspecialchars($contact_email) . '"
                                           style="color:' . $accent . ';text-decoration:none;font-size:14px;font-family:\'Segoe UI\',Tahoma,Verdana,sans-serif;">'
            . htmlspecialchars($contact_email) . '</a>
                                    </td>
                                </tr>';
    }
    if (!empty($site_url)) {
        $contact_rows .= '
                                <tr>
                                    <td align="center" style="padding:4px 0;">
                                        <a href="' . htmlspecialchars($site_url) . '"
                                           style="color:' . $accent . ';text-decoration:none;font-size:14px;font-family:\'Segoe UI\',Tahoma,Verdana,sans-serif;">'
            . htmlspecialchars($site_url) . '</a>
                                    </td>
                                </tr>';
    }

    return '<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width,initial-scale=1.0">
    <title>' . htmlspecialchars($title ?: $site_name) . '</title>
    <style>
        @media only screen and (max-width:640px) {
            .ew-card  { width:100% !important; }
            .ew-body  { padding:28px 20px !important; }
            .ew-foot  { padding:22px 20px !important; }
        }
    </style>
</head>
<body style="margin:0;padding:0;background-color:#EDE8DF;font-family:\'Segoe UI\',Tahoma,Geneva,Verdana,sans-serif;">

    <!-- Outer wrapper -->
    <table role="presentation" width="100%" cellspacing="0" cellpadding="0"
           style="background-color:#EDE8DF;padding:28px 0;">
        <tr>
            <td align="center" valign="top">

                <!-- Email card -->
                <table role="presentation" class="ew-card"
                       style="width:100%;max-width:620px;background:#ffffff;border-radius:12px;
                              overflow:hidden;border:1px solid #D6CDBF;"
                       cellspacing="0" cellpadding="0">

                    <!-- ─── HEADER ─── -->
                    <tr>
                        <td align="center"
                            style="background:linear-gradient(150deg,#FAF6F0 0%,#EEE0C8 100%);
                                   padding:36px 40px 28px;border-bottom:2px solid #D6CDBF;">
                            ' . $logo_html . '
                            <p style="margin:0;font-family:Georgia,\'Times New Roman\',serif;
                                       color:' . $dark . ';
                                       font-size:' . (empty($logo_html) ? '24px' : '13px') . ';
                                       font-weight:600;letter-spacing:2.5px;text-transform:uppercase;">
                                ' . htmlspecialchars($site_name) . '
                            </p>
                        </td>
                    </tr>

                    <!-- ─── GOLD ACCENT LINE ─── -->
                    <tr>
                        <td style="height:3px;
                                   background:linear-gradient(90deg,' . $accent . ' 0%,#C8A45A 50%,' . $accent . ' 100%);">
                        </td>
                    </tr>

                    <!-- ─── BODY ─── -->
                    <tr>
                        <td class="ew-body" align="left"
                            style="padding:36px 40px;color:#2d2d2d;font-size:15px;line-height:1.65;text-align:left;">
                            ' . $content . '
                        </td>
                    </tr>

                    <!-- ─── FOOTER ─── -->
                    <tr>
                        <td class="ew-foot"
                            style="background:#F5F0EA;padding:26px 40px;border-top:1px solid #D6CDBF;">
                            <table role="presentation" width="100%" cellspacing="0" cellpadding="0">
                                <!-- Hotel name -->
                                <tr>
                                    <td align="center" style="padding-bottom:10px;">
                                        <span style="font-family:Georgia,\'Times New Roman\',serif;
                                                     font-size:16px;font-weight:700;color:' . $dark . ';
                                                     letter-spacing:1px;text-transform:uppercase;">
                                            ' . htmlspecialchars($site_name) . '
                                        </span>
                                    </td>
                                </tr>
                                <!-- Separator -->
                                <tr>
                                    <td align="center" style="padding-bottom:12px;">
                                        <div style="width:40px;height:2px;background:' . $accent . ';margin:0 auto;"></div>
                                    </td>
                                </tr>
                                ' . $contact_rows . '
                            </table>
                        </td>
                    </tr>

                    <!-- ─── COPYRIGHT BAR ─── -->
                    <tr>
                        <td align="center"
                            style="background-color:' . $dark . ';padding:14px 40px;">
                            <p style="margin:0;color:#C8BFB0;font-size:12px;
                                       font-family:\'Segoe UI\',Tahoma,Verdana,sans-serif;">
                                &copy; ' . date('Y') . ' ' . htmlspecialchars($site_name) . '. All rights reserved.
                            </p>
                        </td>
                    </tr>

                </table><!-- /email card -->

            </td>
        </tr>
    </table><!-- /outer wrapper -->

</body>
</html>';
}

/**
 * Load and populate an email template
 *
 * @param string $template Template filename (without path)
 * @param array $data Data to populate template with
 * @return string Populated HTML content
 */
function loadEmailTemplate(string $template, array $data)
{
    $template_path = __DIR__ . '/../templates/emails/' . $template;

    // Check if template exists
    if (!file_exists($template_path)) {
        error_log("Email template not found: $template_path");
        return '';
    }

    $html = file_get_contents($template_path);

    // Add common data
    $site_name = getSetting('site_name');
    $site_url = getSetting('site_url');
    $contact_email = getSetting('email_main');
    $phone = getSetting('phone_main');
    $currency_symbol = getSetting('currency_symbol');

    $common_data = [
        'site_name' => $site_name,
        'site_url' => $site_url,
        'contact_email' => $contact_email,
        'phone' => $phone,
        'currency_symbol' => $currency_symbol,
        'submission_source' => $_SERVER['HTTP_HOST'] ?? 'localhost',
        'admin_url' => $site_url . '/admin/conference-management.php'
    ];

    // Merge user data with common data
    $data = array_merge($common_data, $data);

    // Replace simple placeholders {{key}}
    $html = preg_replace_callback('/\{\{(\w+)\}\}/', function (array $matches) use ($data) {
        $key = $matches[1];
        return $data[$key] ?? '';
    }, $html);

    // Handle conditional blocks {{#if key}}...{{/if}}
    $html = preg_replace_callback('/\{\{#if\s+(\w+)\}\}(.*?)\{\{\/if\}\}/s', function (array $matches) use ($data) {
        $key = $matches[1];
        $content = $matches[2];
        // Show content if key exists and is not empty
        if (!empty($data[$key])) {
            return $content;
        }
        return '';
    }, $html);

    return $html;
}

/**
 * Send gym inquiry "contacted" email to the guest
 * Called when admin changes inquiry status to 'contacted'
 *
 * @param array $inquiry  Row from gym_inquiries table
 * @return array Result array with success/message keys
 */
function sendGymInquiryContactedEmail(array $inquiry): array
{
    global $email_from_email, $email_site_name, $email_site_url;

    try {
        $site_name = getSetting('site_name', $email_site_name);
        $name = htmlspecialchars($inquiry['name'] ?? 'Guest');
        $ref  = htmlspecialchars($inquiry['reference_number'] ?? '');
        $type = htmlspecialchars($inquiry['membership_type'] ?? 'Membership');
        $date = !empty($inquiry['preferred_date']) ? date('F j, Y', strtotime($inquiry['preferred_date'])) : 'Flexible';
        $time = htmlspecialchars($inquiry['preferred_time'] ?? 'Flexible');
        $contact_email = htmlspecialchars($email_from_email);
        $phone = getSetting('phone_main', '');

        $htmlBody = '
        <h1 style="color:#8B7355; text-align:center;">We\'re In Touch!</h1>
        <p>Dear ' . $name . ',</p>
        <p>Thank you for your interest in our gym facilities at <strong>' . htmlspecialchars($site_name) . '</strong>. Our team has reviewed your inquiry and is reaching out to assist you.</p>

        <div style="background:#FAF6F0; border:2px solid #1A1A1A; padding:20px; margin:20px 0; border-radius:10px;">
            <h2 style="color:#8B7355; margin-top:0;;text-align:left;">Your Inquiry Details</h2>
            ' . (!empty($ref) ? '<table role="presentation" width="100%" cellspacing="0" cellpadding="0" style="margin:0;"><tr><td style="padding:10px 10px 10px 0;font-weight:bold;color:#1A1A1A;width:44%;vertical-align:top;font-family:\'Segoe UI\',Tahoma,Verdana,sans-serif;border-bottom:1px solid #e8e0d4;">Reference:</td><td style="padding:10px 0 10px 6px;color:#8B7355; font-weight:bold;;text-align:left;vertical-align:top;font-family:\'Segoe UI\',Tahoma,Verdana,sans-serif;border-bottom:1px solid #e8e0d4;">' . $ref . '</td></tr></table>' : '') . '
            <table role="presentation" width="100%" cellspacing="0" cellpadding="0" style="margin:0;"><tr><td style="padding:10px 10px 10px 0;font-weight:bold;color:#1A1A1A;width:44%;vertical-align:top;font-family:\'Segoe UI\',Tahoma,Verdana,sans-serif;border-bottom:1px solid #e8e0d4;">Membership Type:</td><td style="padding:10px 0 10px 6px;color:#333;;text-align:left;vertical-align:top;font-family:\'Segoe UI\',Tahoma,Verdana,sans-serif;border-bottom:1px solid #e8e0d4;">' . $type . '</td></tr></table>
            <table role="presentation" width="100%" cellspacing="0" cellpadding="0" style="margin:0;"><tr><td style="padding:10px 10px 10px 0;font-weight:bold;color:#1A1A1A;width:44%;vertical-align:top;font-family:\'Segoe UI\',Tahoma,Verdana,sans-serif;border-bottom:1px solid #e8e0d4;">Preferred Date:</td><td style="padding:10px 0 10px 6px;color:#333;;text-align:left;vertical-align:top;font-family:\'Segoe UI\',Tahoma,Verdana,sans-serif;border-bottom:1px solid #e8e0d4;">' . $date . '</td></tr></table>
            <table role="presentation" width="100%" cellspacing="0" cellpadding="0" style="margin:0;"><tr><td style="padding:10px 10px 10px 0;font-weight:bold;color:#1A1A1A;width:44%;vertical-align:top;font-family:\'Segoe UI\',Tahoma,Verdana,sans-serif;">Preferred Time:</td><td style="padding:10px 0 10px 6px;color:#333;;text-align:left;vertical-align:top;font-family:\'Segoe UI\',Tahoma,Verdana,sans-serif;">' . $time . '</td></tr></table>
        </div>

        <div style="background:#d4edda; padding:15px; border-left:4px solid #28a745; border-radius:5px; margin:20px 0;">
            <h3 style="color:#155724; margin-top:0;;text-align:left;">✅ Status: We\'re Reaching Out</h3>
            <p style="color:#155724; margin:0;">
                A member of our team will contact you shortly to finalise the details of your membership and answer any questions you may have.
            </p>
        </div>

        <p>In the meantime, if you have any questions please contact us directly:</p>
        <ul style="color:#333; line-height:2;">
            <li>Email: <a href="mailto:' . $contact_email . '">' . $contact_email . '</a></li>
            ' . (!empty($phone) ? '<li>Phone: <a href="tel:' . htmlspecialchars($phone) . '">' . htmlspecialchars($phone) . '</a></li>' : '') . '
        </ul>
        <p style="margin:28px 0 0;font-size:14px;color:#777;text-align:center;font-style:italic;">
            Warm regards &mdash; see you soon.
        </p>';

        return sendEmail(
            $inquiry['email'],
            $inquiry['name'],
            'Your Gym Inquiry — We\'re In Touch | ' . $site_name . ($ref ? ' [' . $inquiry['reference_number'] . ']' : ''),
            $htmlBody
        );
    } catch (Exception $e) {
        error_log('sendGymInquiryContactedEmail Error: ' . $e->getMessage());
        return ['success' => false, 'message' => $e->getMessage()];
    }
}

/**
 * Send a no-show notification email to the guest.
 * Called immediately after a booking is marked as no-show.
 *
 * @param array  $booking       Full booking row (guest_email, amount_paid, etc.)
 * @param float  $refund_amount Amount queued for refund (0 = no refund)
 * @param string $refund_ref    Refund reference (empty if none)
 */
function sendNoShowEmail(array $booking, float $refund_amount = 0.0, string $refund_ref = ''): array
{
    global $pdo, $email_from_email, $email_site_name;

    try {
        $stmt = $pdo->prepare("SELECT * FROM rooms WHERE id = ?");
        $stmt->execute([$booking['room_id']]);
        $room = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$room) {
            throw new Exception('Room not found for no-show email');
        }

        $currency  = getSetting('currency_symbol', 'MWK');
        $phone     = getSetting('phone_main', '');
        $guestName = htmlspecialchars($booking['guest_name'] ?? 'Guest');
        $amtPaid   = (float)($booking['amount_paid'] ?? 0);

        // ── Refund / no-refund block ──────────────────────────────────────
        if ($refund_amount > 0 && $refund_ref !== '') {
            $refundBlock = '
            <div style="background:#e7f9f0;border-left:4px solid #28a745;padding:16px 20px;border-radius:6px;margin:20px 0;">
                <h3 style="color:#155724;margin:0 0 8px;text-align:left;">Refund Pending</h3>
                <p style="color:#155724;margin:0 0 6px;">A refund of <strong>' . $currency . ' ' . number_format($refund_amount, 0) . '</strong> has been queued for processing.</p>
                <p style="color:#6c7a7d;font-size:12px;margin:4px 0 0;">Refund reference: <strong>' . htmlspecialchars($refund_ref) . '</strong><br>
                Our team will process this within 3–5 business days. You will be notified once completed.</p>
            </div>';
        } else {
            $refundBlock = '
            <div style="background:#fff3cd;border-left:4px solid #ffc107;padding:16px 20px;border-radius:6px;margin:20px 0;">
                <h3 style="color:#856404;margin:0 0 8px;text-align:left;">No Refund Applicable</h3>
                <p style="color:#856404;margin:0;">As per our no-show policy, no refund will be processed for this booking.
                If you believe this is an error or you need to reschedule, please contact us immediately.</p>
            </div>';
        }

        $htmlBody = '
        <h1 style="color:#8B7355;text-align:center;">We Missed You</h1>
        <p>Dear ' . $guestName . ',</p>
        <p>We noticed that you did not check in for your reservation at <strong>' . htmlspecialchars($email_site_name) . '</strong>.
        Your booking has been marked as a <strong>no-show</strong>.</p>

        <div style="background:#FAF6F0;border:2px solid #e0d5c5;padding:20px;margin:20px 0;border-radius:10px;">
            <h2 style="color:#8B7355;margin-top:0;text-align:left;">Booking Details</h2>
            <table role="presentation" width="100%" cellspacing="0" cellpadding="0" style="margin:0;">
                <tr>
                    <td style="padding:10px 10px 10px 0;font-weight:bold;color:#1A1A1A;width:44%;font-family:\'Segoe UI\',Tahoma,Verdana,sans-serif;border-bottom:1px solid #e8e0d4;">Booking Reference:</td>
                    <td style="padding:10px 0 10px 6px;color:#8B7355;font-weight:bold;font-size:18px;font-family:\'Segoe UI\',Tahoma,Verdana,sans-serif;border-bottom:1px solid #e8e0d4;">' . htmlspecialchars($booking['booking_reference']) . '</td>
                </tr>
                <tr>
                    <td style="padding:10px 10px 10px 0;font-weight:bold;color:#1A1A1A;font-family:\'Segoe UI\',Tahoma,Verdana,sans-serif;border-bottom:1px solid #e8e0d4;">Room:</td>
                    <td style="padding:10px 0 10px 6px;color:#333;font-family:\'Segoe UI\',Tahoma,Verdana,sans-serif;border-bottom:1px solid #e8e0d4;">' . htmlspecialchars($room['name']) . '</td>
                </tr>
                <tr>
                    <td style="padding:10px 10px 10px 0;font-weight:bold;color:#1A1A1A;font-family:\'Segoe UI\',Tahoma,Verdana,sans-serif;border-bottom:1px solid #e8e0d4;">Check-in Date:</td>
                    <td style="padding:10px 0 10px 6px;color:#333;font-family:\'Segoe UI\',Tahoma,Verdana,sans-serif;border-bottom:1px solid #e8e0d4;">' . date('F j, Y', strtotime($booking['check_in_date'])) . '</td>
                </tr>
                <tr>
                    <td style="padding:10px 10px 10px 0;font-weight:bold;color:#1A1A1A;font-family:\'Segoe UI\',Tahoma,Verdana,sans-serif;border-bottom:1px solid #e8e0d4;">Check-out Date:</td>
                    <td style="padding:10px 0 10px 6px;color:#333;font-family:\'Segoe UI\',Tahoma,Verdana,sans-serif;border-bottom:1px solid #e8e0d4;">' . date('F j, Y', strtotime($booking['check_out_date'])) . '</td>
                </tr>
                <tr>
                    <td style="padding:10px 10px 10px 0;font-weight:bold;color:#1A1A1A;font-family:\'Segoe UI\',Tahoma,Verdana,sans-serif;border-bottom:1px solid #e8e0d4;">Nights:</td>
                    <td style="padding:10px 0 10px 6px;color:#333;font-family:\'Segoe UI\',Tahoma,Verdana,sans-serif;border-bottom:1px solid #e8e0d4;">' . (int)($booking['number_of_nights'] ?? 1) . '</td>
                </tr>
                <tr>
                    <td style="padding:10px 10px 10px 0;font-weight:bold;color:#1A1A1A;font-family:\'Segoe UI\',Tahoma,Verdana,sans-serif;">Amount Paid:</td>
                    <td style="padding:10px 0 10px 6px;color:#8B7355;font-weight:bold;font-size:18px;font-family:\'Segoe UI\',Tahoma,Verdana,sans-serif;">' . $currency . ' ' . number_format($amtPaid, 0) . '</td>
                </tr>
            </table>
        </div>

        ' . $refundBlock . '

        <div style="background:#f1f3f5;padding:15px 20px;border-radius:6px;margin:20px 0;">
            <p style="margin:0;font-size:14px;color:#555;">Need to reschedule or have questions? Contact us:<br>
            &#x1F4DE; ' . htmlspecialchars($phone) . '<br>
            &#x2709;&#xFE0F; <a href="mailto:' . htmlspecialchars($email_from_email) . '">' . htmlspecialchars($email_from_email) . '</a>
            </p>
        </div>

        <p style="margin:28px 0 0;font-size:14px;color:#777;text-align:center;font-style:italic;">
            We hope to welcome you soon &mdash; ' . htmlspecialchars($email_site_name) . '
        </p>';

        $ccEmails    = getCCEmails();
        $emailResult = sendEmailWithCC(
            $booking['guest_email'],
            $booking['guest_name'],
            'No-Show Notification — ' . htmlspecialchars($email_site_name) . ' [' . $booking['booking_reference'] . ']',
            $htmlBody,
            '',
            $ccEmails
        );

        return [
            'success' => $emailResult['success'],
            'message' => $emailResult['message'],
        ];
    } catch (Exception $e) {
        error_log('sendNoShowEmail Error: ' . $e->getMessage());
        return ['success' => false, 'message' => $e->getMessage()];
    }
}

/**
 * Send a stay-dates-adjusted email to the guest.
 * Used when an admin extends or shortens a confirmed booking.
 *
 * @param array $booking       Full updated booking row (guest_email, check_in_date, check_out_date, etc.)
 * @param float $amount_delta  Positive = extra charge, negative = credit/refund, 0 = same total
 * @param string $old_checkout Previous checkout date (YYYY-MM-DD)
 */
function sendExtendStayEmail(array $booking, float $amount_delta = 0.0, string $old_checkout = ''): array
{
    global $pdo, $email_from_email, $email_site_name;

    try {
        $stmt = $pdo->prepare("SELECT * FROM rooms WHERE id = ?");
        $stmt->execute([$booking['room_id']]);
        $room = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$room) {
            throw new Exception('Room not found for extend-stay email');
        }

        $currency  = getSetting('currency_symbol', 'MWK');
        $phone     = getSetting('phone_main', '');
        $guestName = htmlspecialchars($booking['guest_name'] ?? 'Guest');

        // ── Delta block ───────────────────────────────────────────────────
        if ($amount_delta > 0.01) {
            $deltaBlock = '
            <div style="background:#fff3cd;border-left:4px solid #ffc107;padding:16px 20px;border-radius:6px;margin:20px 0;">
                <h3 style="color:#856404;margin:0 0 8px;text-align:left;">Additional Amount Due</h3>
                <p style="color:#856404;margin:0;">The date change results in an additional charge of
                <strong>' . $currency . ' ' . number_format($amount_delta, 0) . '</strong>.
                Our team will contact you to arrange payment, or you may settle on arrival.</p>
            </div>';
        } elseif ($amount_delta < -0.01) {
            $deltaBlock = '
            <div style="background:#e7f9f0;border-left:4px solid #28a745;padding:16px 20px;border-radius:6px;margin:20px 0;">
                <h3 style="color:#155724;margin:0 0 8px;text-align:left;">Credit / Refund</h3>
                <p style="color:#155724;margin:0;">The date change results in a credit of
                <strong>' . $currency . ' ' . number_format(abs($amount_delta), 0) . '</strong>.
                This will be applied to your account or refunded — our team will be in touch.</p>
            </div>';
        } else {
            $deltaBlock = '';
        }

        $oldCheckoutLine = $old_checkout
            ? '<tr>
                    <td style="padding:8px 10px 8px 0;font-weight:bold;color:#1A1A1A;width:44%;font-family:\'Segoe UI\',Tahoma,Verdana,sans-serif;border-bottom:1px solid #e8e0d4;">Previous Check-out:</td>
                    <td style="padding:8px 0 8px 6px;color:#a0522d;text-decoration:line-through;font-family:\'Segoe UI\',Tahoma,Verdana,sans-serif;border-bottom:1px solid #e8e0d4;">' . date('F j, Y', strtotime($old_checkout)) . '</td>
               </tr>'
            : '';

        $htmlBody = '
        <h1 style="color:#8B7355;text-align:center;">Your Stay Has Been Updated</h1>
        <p>Dear ' . $guestName . ',</p>
        <p>We have updated the dates for your reservation at <strong>' . htmlspecialchars($email_site_name) . '</strong>.
        Please review the new details below.</p>

        <div style="background:#FAF6F0;border:2px solid #e0d5c5;padding:20px;margin:20px 0;border-radius:10px;">
            <h2 style="color:#8B7355;margin-top:0;text-align:left;">Updated Booking Details</h2>
            <table role="presentation" width="100%" cellspacing="0" cellpadding="0" style="margin:0;">
                <tr>
                    <td style="padding:8px 10px 8px 0;font-weight:bold;color:#1A1A1A;width:44%;font-family:\'Segoe UI\',Tahoma,Verdana,sans-serif;border-bottom:1px solid #e8e0d4;">Booking Reference:</td>
                    <td style="padding:8px 0 8px 6px;color:#8B7355;font-weight:bold;font-size:18px;font-family:\'Segoe UI\',Tahoma,Verdana,sans-serif;border-bottom:1px solid #e8e0d4;">' . htmlspecialchars($booking['booking_reference']) . '</td>
                </tr>
                <tr>
                    <td style="padding:8px 10px 8px 0;font-weight:bold;color:#1A1A1A;font-family:\'Segoe UI\',Tahoma,Verdana,sans-serif;border-bottom:1px solid #e8e0d4;">Room:</td>
                    <td style="padding:8px 0 8px 6px;color:#333;font-family:\'Segoe UI\',Tahoma,Verdana,sans-serif;border-bottom:1px solid #e8e0d4;">' . htmlspecialchars($room['name']) . '</td>
                </tr>
                <tr>
                    <td style="padding:8px 10px 8px 0;font-weight:bold;color:#1A1A1A;font-family:\'Segoe UI\',Tahoma,Verdana,sans-serif;border-bottom:1px solid #e8e0d4;">Check-in Date:</td>
                    <td style="padding:8px 0 8px 6px;color:#333;font-family:\'Segoe UI\',Tahoma,Verdana,sans-serif;border-bottom:1px solid #e8e0d4;">' . date('F j, Y', strtotime($booking['check_in_date'])) . '</td>
                </tr>
                ' . $oldCheckoutLine . '
                <tr>
                    <td style="padding:8px 10px 8px 0;font-weight:bold;color:#1A1A1A;font-family:\'Segoe UI\',Tahoma,Verdana,sans-serif;border-bottom:1px solid #e8e0d4;">New Check-out Date:</td>
                    <td style="padding:8px 0 8px 6px;color:#28a745;font-weight:bold;font-family:\'Segoe UI\',Tahoma,Verdana,sans-serif;border-bottom:1px solid #e8e0d4;">' . date('F j, Y', strtotime($booking['check_out_date'])) . '</td>
                </tr>
                <tr>
                    <td style="padding:8px 10px 8px 0;font-weight:bold;color:#1A1A1A;font-family:\'Segoe UI\',Tahoma,Verdana,sans-serif;">Nights:</td>
                    <td style="padding:8px 0 8px 6px;color:#333;font-family:\'Segoe UI\',Tahoma,Verdana,sans-serif;">' . (int)($booking['number_of_nights'] ?? 1) . '</td>
                </tr>
            </table>
        </div>

        ' . $deltaBlock . '

        <div style="background:#f1f3f5;padding:15px 20px;border-radius:6px;margin:20px 0;">
            <p style="margin:0;font-size:14px;color:#555;">Any questions? Contact us:<br>
            &#x1F4DE; ' . htmlspecialchars($phone) . '<br>
            &#x2709;&#xFE0F; <a href="mailto:' . htmlspecialchars($email_from_email) . '">' . htmlspecialchars($email_from_email) . '</a>
            </p>
        </div>

        <p style="margin:28px 0 0;font-size:14px;color:#777;text-align:center;font-style:italic;">
            We look forward to welcoming you &mdash; ' . htmlspecialchars($email_site_name) . '
        </p>';

        $ccEmails    = getCCEmails();
        $emailResult = sendEmailWithCC(
            $booking['guest_email'],
            $booking['guest_name'],
            'Updated Stay Dates — ' . htmlspecialchars($email_site_name) . ' [' . $booking['booking_reference'] . ']',
            $htmlBody,
            '',
            $ccEmails
        );

        return [
            'success' => $emailResult['success'],
            'message' => $emailResult['message'],
        ];
    } catch (Exception $e) {
        error_log('sendExtendStayEmail Error: ' . $e->getMessage());
        return ['success' => false, 'message' => $e->getMessage()];
    }
}

/**
 * Send gym inquiry "converted / welcome" email to the new member
 * Called when admin changes inquiry status to 'converted'
 *
 * @param array $inquiry  Row from gym_inquiries table
 * @return array Result array with success/message keys
 */
function sendGymInquiryConvertedEmail(array $inquiry): array
{
    global $email_from_email, $email_site_name, $email_site_url;

    try {
        $site_name = getSetting('site_name', $email_site_name);
        $name = htmlspecialchars($inquiry['name'] ?? 'Guest');
        $ref  = htmlspecialchars($inquiry['reference_number'] ?? '');
        $type = htmlspecialchars($inquiry['membership_type'] ?? 'Membership');
        $contact_email = htmlspecialchars($email_from_email);
        $phone = getSetting('phone_main', '');

        $htmlBody = '
        <h1 style="color:#8B7355; text-align:center;">Welcome to the Gym!</h1>
        <p>Dear ' . $name . ',</p>
        <p>Congratulations and welcome to the <strong>' . htmlspecialchars($site_name) . '</strong> gym family! Your membership enquiry has been successfully processed.</p>

        <div style="background:#FAF6F0; border:2px solid #8B7355; padding:20px; margin:20px 0; border-radius:10px;">
            <h2 style="color:#8B7355; margin-top:0;;text-align:left;">Membership Summary</h2>
            ' . (!empty($ref) ? '<table role="presentation" width="100%" cellspacing="0" cellpadding="0" style="margin:0;"><tr><td style="padding:10px 10px 10px 0;font-weight:bold;color:#1A1A1A;width:44%;vertical-align:top;font-family:\'Segoe UI\',Tahoma,Verdana,sans-serif;border-bottom:1px solid #e8e0d4;">Reference:</td><td style="padding:10px 0 10px 6px;color:#8B7355; font-weight:bold;;text-align:left;vertical-align:top;font-family:\'Segoe UI\',Tahoma,Verdana,sans-serif;border-bottom:1px solid #e8e0d4;">' . $ref . '</td></tr></table>' : '') . '
            <table role="presentation" width="100%" cellspacing="0" cellpadding="0" style="margin:0;"><tr><td style="padding:10px 10px 10px 0;font-weight:bold;color:#1A1A1A;width:44%;vertical-align:top;font-family:\'Segoe UI\',Tahoma,Verdana,sans-serif;border-bottom:1px solid #e8e0d4;">Membership Type:</td><td style="padding:10px 0 10px 6px;color:#333;;text-align:left;vertical-align:top;font-family:\'Segoe UI\',Tahoma,Verdana,sans-serif;border-bottom:1px solid #e8e0d4;">' . $type . '</td></tr></table>
            <table role="presentation" width="100%" cellspacing="0" cellpadding="0" style="margin:0;"><tr><td style="padding:10px 10px 10px 0;font-weight:bold;color:#1A1A1A;width:44%;vertical-align:top;font-family:\'Segoe UI\',Tahoma,Verdana,sans-serif;">Status:</td><td style="padding:10px 0 10px 6px;color:#28a745; font-weight:bold;;text-align:left;vertical-align:top;font-family:\'Segoe UI\',Tahoma,Verdana,sans-serif;">Active Member</td></tr></table>
        </div>

        <div style="background:#d4edda; padding:15px; border-left:4px solid #28a745; border-radius:5px; margin:20px 0;">
            <h3 style="color:#155724; margin-top:0;;text-align:left;">🎉 Membership Activated</h3>
            <p style="color:#155724; margin:0;">
                Your membership is now active! Our team will share your full membership details, access schedule, and any onboarding information you need.
            </p>
        </div>

        <div style="background:#FDF6EC; padding:15px; border-left:4px solid #8B7355; border-radius:5px; margin:20px 0;">
            <h3 style="color:#5C4A32; margin-top:0;;text-align:left;">Getting Started</h3>
            <ul style="color:#5C4A32; margin:0; padding-left:20px; line-height:1.9;">
                <li>Present this confirmation at the gym reception on your first visit</li>
                <li>Our trainers will walk you through facilities and equipment</li>
                <li>Your fitness journey starts here — let\'s go!</li>
            </ul>
        </div>

        <p>Questions? We are happy to help:</p>
        <ul style="color:#333; line-height:2;">
            <li>Email: <a href="mailto:' . $contact_email . '">' . $contact_email . '</a></li>
            ' . (!empty($phone) ? '<li>Phone: <a href="tel:' . htmlspecialchars($phone) . '">' . htmlspecialchars($phone) . '</a></li>' : '') . '
        </ul>
        <p style="margin:28px 0 0;font-size:14px;color:#777;text-align:center;font-style:italic;">
            Warm regards &mdash; see you soon.
        </p>';

        return sendEmail(
            $inquiry['email'],
            $inquiry['name'],
            'Welcome to ' . $site_name . ' Gym — Membership Confirmed!' . ($ref ? ' [' . $inquiry['reference_number'] . ']' : ''),
            $htmlBody
        );
    } catch (Exception $e) {
        error_log('sendGymInquiryConvertedEmail Error: ' . $e->getMessage());
        return ['success' => false, 'message' => $e->getMessage()];
    }
}

/**
 * Send admin notification when a new gym inquiry is submitted from the public site
 *
 * @param array $inquiry  Row from gym_inquiries table
 * @return array Result array with success/message keys
 */
function sendNewGymInquiryAdminEmail(array $inquiry): array
{
    global $email_admin_email, $email_site_name, $email_site_url;

    try {
        $site_name = getSetting('site_name', $email_site_name);
        $admin_email = $email_admin_email ?: getSetting('email_admin_email', '');
        if (empty($admin_email) || !filter_var($admin_email, FILTER_VALIDATE_EMAIL)) {
            return ['success' => false, 'message' => 'No admin email configured'];
        }

        $name  = htmlspecialchars($inquiry['name'] ?? '');
        $email = htmlspecialchars($inquiry['email'] ?? '');
        $phone = htmlspecialchars($inquiry['phone'] ?? '');
        $type  = htmlspecialchars($inquiry['membership_type'] ?? '');
        $ref   = htmlspecialchars($inquiry['reference_number'] ?? '');
        $date  = !empty($inquiry['preferred_date']) ? date('F j, Y', strtotime($inquiry['preferred_date'])) : 'Flexible';
        $time  = htmlspecialchars($inquiry['preferred_time'] ?? '');

        $htmlBody = '
        <h1 style="color:#8B7355; text-align:center;">📋 New Gym Inquiry Received</h1>
        <p>A new gym membership inquiry has been submitted on the website.</p>

        <div style="background:#FAF6F0; border:2px solid #1A1A1A; padding:20px; margin:20px 0; border-radius:10px;">
            <h2 style="color:#8B7355; margin-top:0;;text-align:left;">Inquiry Details</h2>
            ' . (!empty($ref) ? '<div style="display:flex; justify-content:space-between; padding:10px 0; border-bottom:1px solid #ddd;"><span style="font-weight:bold;">Reference:</span><span style="color:#8B7355; font-weight:bold;">' . $ref . '</span></div>' : '') . '
            <div style="display:flex; justify-content:space-between; padding:10px 0; border-bottom:1px solid #ddd;"><span style="font-weight:bold;">Name:</span><span>' . $name . '</span></div>
            <div style="display:flex; justify-content:space-between; padding:10px 0; border-bottom:1px solid #ddd;"><span style="font-weight:bold;">Email:</span><span><a href="mailto:' . $email . '">' . $email . '</a></span></div>
            <div style="display:flex; justify-content:space-between; padding:10px 0; border-bottom:1px solid #ddd;"><span style="font-weight:bold;">Phone:</span><span>' . $phone . '</span></div>
            <div style="display:flex; justify-content:space-between; padding:10px 0; border-bottom:1px solid #ddd;"><span style="font-weight:bold;">Membership Type:</span><span>' . $type . '</span></div>
            <div style="display:flex; justify-content:space-between; padding:10px 0; border-bottom:1px solid #ddd;"><span style="font-weight:bold;">Preferred Date:</span><span>' . $date . '</span></div>
            <div style="display:flex; justify-content:space-between; padding:10px 0;"><span style="font-weight:bold;">Preferred Time:</span><span>' . $time . '</span></div>
        </div>

        <div style="text-align:center; margin:20px 0;">
            <a href="' . htmlspecialchars($email_site_url) . '/admin/gym-inquiries.php"
               style="display:inline-block; background:#8B7355; color:#fff; padding:12px 28px; text-decoration:none; border-radius:6px; font-weight:bold;">
                View in Admin Panel
            </a>
        </div>';

        return sendEmail($admin_email, 'Admin', 'New Gym Inquiry — ' . ($name ?: 'Unknown') . ' | ' . $site_name, $htmlBody);
    } catch (Exception $e) {
        error_log('sendNewGymInquiryAdminEmail Error: ' . $e->getMessage());
        return ['success' => false, 'message' => $e->getMessage()];
    }
}

/**
 * Send a direct reply to a public contact inquiry from the hotel email configured in the database.
 */
function sendContactInquiryReplyEmail(array $inquiry, string $replySubject, string $replyMessage, array $adminUser = []): array
{
    global $email_from_name, $email_from_email, $email_admin_email, $email_site_name;
    global $smtp_host, $smtp_port, $smtp_username, $smtp_password, $smtp_secure, $smtp_timeout, $smtp_debug;
    global $email_bcc_admin, $development_mode, $email_log_enabled, $email_preview_enabled;

    $hotelFromEmail = trim((string)$email_from_email);
    if (!filter_var($hotelFromEmail, FILTER_VALIDATE_EMAIL)) {
        $hotelFromEmail = trim((string)getSetting('email_main', ''));
    }

    if (!filter_var($hotelFromEmail, FILTER_VALIDATE_EMAIL)) {
        return ['success' => false, 'message' => 'Hotel sender email is not configured.'];
    }

    $recipientEmail = trim((string)($inquiry['email'] ?? ''));
    if (!filter_var($recipientEmail, FILTER_VALIDATE_EMAIL)) {
        return ['success' => false, 'message' => 'Inquiry recipient email is invalid.'];
    }

    $escape = static function (mixed $value): string {
        return htmlspecialchars((string)$value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    };

    $reference = trim((string)($inquiry['reference_number'] ?? ''));
    $guestName = trim((string)($inquiry['name'] ?? 'Guest'));
    $siteName = trim((string)($email_site_name ?: getSetting('site_name', 'Hotel')));
    $fromName = trim((string)($email_from_name ?: $siteName));
    $phone = trim((string)getSetting('phone_main', ''));
    $adminName = trim((string)($adminUser['full_name'] ?? $adminUser['username'] ?? 'Reservations Team'));
    $emailSubject = trim($replySubject);

    if ($reference !== '' && stripos($emailSubject, $reference) === false) {
        $emailSubject .= ' [' . $reference . ']';
    }

    $htmlBody = '
        <h1 style="color:#8B7355;text-align:center;">Reply from ' . $escape($siteName) . '</h1>
        <p>Dear ' . $escape($guestName) . ',</p>
        <div style="background:#FAF6F0;border:1px solid #e0d5c5;padding:20px;border-radius:10px;margin:20px 0;line-height:1.7;color:#333;">
            ' . nl2br($escape($replyMessage)) . '
        </div>
        <div style="background:#f7f3ee;padding:16px 20px;border-radius:8px;margin:20px 0;">
            <p style="margin:0;color:#5E554D;font-size:14px;"><strong>Original inquiry reference:</strong> ' . $escape($reference ?: 'N/A') . '</p>
            <p style="margin:8px 0 0;color:#5E554D;font-size:14px;"><strong>Original subject:</strong> ' . $escape($inquiry['subject'] ?? '') . '</p>
        </div>
        <p style="margin:24px 0 0;color:#555;">Kind regards,<br><strong>' . $escape($adminName) . '</strong><br>' . $escape($siteName) . '</p>
        <p style="margin-top:18px;color:#777;font-size:13px;">
            Email: <a href="mailto:' . $escape($hotelFromEmail) . '">' . $escape($hotelFromEmail) . '</a>' .
        ($phone !== '' ? '<br>Phone: ' . $escape($phone) : '') . '
        </p>';

    $textBody = $replyMessage . "\n\n---\nOriginal inquiry reference: " . ($reference ?: 'N/A') . "\nOriginal subject: " . (string)($inquiry['subject'] ?? '') . "\n\n" . $siteName . "\n" . $hotelFromEmail;

    if ($development_mode && (empty($smtp_password) || $email_preview_enabled)) {
        return createEmailPreview($recipientEmail, $guestName, $emailSubject, $htmlBody, $textBody);
    }

    try {
        $mail = new PHPMailer(true);
        $smtpSecureNormalized = strtolower(trim((string)$smtp_secure));
        if ($smtpSecureNormalized === '' && (int)$smtp_port === 587) {
            $smtpSecureNormalized = 'tls';
        } elseif ($smtpSecureNormalized === '' && (int)$smtp_port === 465) {
            $smtpSecureNormalized = 'ssl';
        }

        $mail->isSMTP();
        $mail->Host = $smtp_host;
        $mail->SMTPAuth = true;
        $mail->Username = $smtp_username;
        $mail->Password = $smtp_password;
        if ($smtpSecureNormalized !== '') {
            $mail->SMTPSecure = $smtpSecureNormalized;
        }
        $mail->Port = $smtp_port;
        $mail->Timeout = $smtp_timeout;

        if ($smtp_debug > 0) {
            $mail->SMTPDebug = $smtp_debug;
        }

        if (filter_var($smtp_username, FILTER_VALIDATE_EMAIL)) {
            $mail->Sender = $smtp_username;
        }

        $mail->setFrom($hotelFromEmail, $fromName);
        $mail->addAddress($recipientEmail, $guestName);
        $mail->addReplyTo($hotelFromEmail, $fromName);

        if ($email_bcc_admin && filter_var($email_admin_email, FILTER_VALIDATE_EMAIL)) {
            $mail->addBCC($email_admin_email);
        }

        $mail->CharSet = PHPMailer::CHARSET_UTF8;
        $mail->Encoding = PHPMailer::ENCODING_BASE64;
        $mail->isHTML(true);
        $mail->Subject = $emailSubject;
        $mail->Body = wrapEmailTemplate($htmlBody, $emailSubject);
        $mail->AltBody = $textBody;

        $mail->send();

        if ($email_log_enabled) {
            logEmail($recipientEmail, $guestName, $emailSubject, 'sent');
        }

        return ['success' => true, 'message' => 'Reply email sent successfully.'];
    } catch (Exception $e) {
        error_log('sendContactInquiryReplyEmail Error: ' . $e->getMessage());
        if ($email_log_enabled) {
            logEmail($recipientEmail, $guestName, $emailSubject, 'failed', $e->getMessage());
        }
        return ['success' => false, 'message' => $e->getMessage()];
    }
}

/**
 * Send a formal quotation email for a tentative booking.
 *
 * @param array $booking   Full row from the bookings table.
 * @param array $options {
 *   valid_days       int     Days until quotation expires (default 7).
 *   quotation_notes  string  Admin note to include in the email.
 * }
 */
function sendTentativeQuotationEmail(array $booking, array $options = []): array
{
    global $pdo, $email_from_name, $email_from_email, $email_site_name, $email_log_enabled;
    global $smtp_host, $smtp_port, $smtp_username, $smtp_password, $smtp_secure, $smtp_timeout, $smtp_debug;
    global $email_admin_email, $email_bcc_admin, $development_mode, $email_preview_enabled;

    try {
        $site_name      = $email_site_name ?: getSetting('site_name', "Rosalyn's Beach Hotel");
        $currency       = getSetting('currency_symbol', 'MWK ');
        $valid_days     = max(1, (int)($options['valid_days'] ?? 7));
        $notes          = trim((string)($options['quotation_notes'] ?? ''));
        $attach_pdf     = (bool)($options['attach_pdf'] ?? true);
        $send_whatsapp  = (bool)($options['send_whatsapp'] ?? true);
        $valid_until    = (new DateTime())->modify("+{$valid_days} days");
        $quote_ref      = 'QT-' . strtoupper((string)$booking['booking_reference']);
        $vat_enabled    = in_array(getSetting('vat_enabled'), ['1', 1, true, 'true', 'on'], true);
        $check_in_time  = getSetting('check_in_time', '2:00 PM');
        $check_out_time = getSetting('check_out_time', '11:00 AM');
        $contact_phone  = getSetting('phone_main', '');

        // Room details
        $roomStmt = $pdo->prepare("SELECT name, price_per_night, short_description, bed_type, size_sqm, max_guests FROM rooms WHERE id = ?");
        $roomStmt->execute([$booking['room_id']]);
        $room = $roomStmt->fetch(PDO::FETCH_ASSOC);
        if (!$room) {
            throw new Exception('Room not found for booking ' . $booking['booking_reference']);
        }

        $nights      = (int)$booking['number_of_nights'];
        $adults      = (int)($booking['adult_guests'] ?? $booking['number_of_guests'] ?? 1);
        $children    = (int)($booking['child_guests'] ?? 0);
        $total       = (float)$booking['total_amount'];
        $vat_amount  = (float)($booking['vat_amount'] ?? 0);
        $vat_rate    = (float)($booking['vat_rate'] ?? 0);
        $child_supp  = (float)($booking['child_supplement_total'] ?? 0);
        $deposit_req = !empty($booking['deposit_required']);
        $deposit_amt = (float)($booking['deposit_amount'] ?? 0);

        $room_subtotal  = $total - ($vat_enabled ? $vat_amount : 0.0) - $child_supp;
        $rate_per_night = $nights > 0 ? $room_subtotal / $nights : (float)$room['price_per_night'];

        $fmt = static function (float $v) use ($currency): string {
            return htmlspecialchars($currency, ENT_QUOTES, 'UTF-8') . number_format($v, 0);
        };
        $trow = static function (string $label, string $value, bool $accent = false): string {
            $col = $accent ? '#8B7355' : '#1A1A1A';
            $fw  = $accent ? 'font-weight:600;' : '';
            return '<tr>'
                . '<td style="padding:9px 12px 9px 0;color:#555;font-size:14px;font-family:\'Segoe UI\',Tahoma,Verdana,sans-serif;border-bottom:1px solid #EDE8E0;width:55%;">' . $label . '</td>'
                . '<td style="padding:9px 0 9px 6px;color:' . $col . ';font-size:14px;' . $fw . 'font-family:\'Segoe UI\',Tahoma,Verdana,sans-serif;border-bottom:1px solid #EDE8E0;">' . $value . '</td>'
                . '</tr>';
        };

        $guest_label = $adults . ' adult' . ($adults !== 1 ? 's' : '');
        if ($children > 0) {
            $guest_label .= ', ' . $children . ' child' . ($children !== 1 ? 'ren' : '');
        }

        $htmlBody  = '
        <h1 style="color:#8B7355;text-align:center;font-family:Georgia,serif;font-weight:400;letter-spacing:1px;">Hotel Quotation</h1>
        <p style="font-family:\'Segoe UI\',Tahoma,Verdana,sans-serif;font-size:15px;color:#333;">
            Dear ' . htmlspecialchars($booking['guest_name']) . ',
        </p>
        <p style="font-family:\'Segoe UI\',Tahoma,Verdana,sans-serif;font-size:15px;color:#333;">
            Thank you for your enquiry. Please find below our formal quotation for your upcoming stay at
            <strong>' . htmlspecialchars($site_name) . '</strong>.
        </p>';

        // Quote reference banner
        $htmlBody .= '
        <div style="background:#FAF6F0;border:2px solid #C8A45A;border-radius:8px;padding:18px 22px;margin:20px 0;">
            <table role="presentation" width="100%" cellspacing="0" cellpadding="0"><tr>
                <td style="font-family:\'Segoe UI\',Tahoma,Verdana,sans-serif;">
                    <div style="font-size:11px;text-transform:uppercase;letter-spacing:1px;color:#999;margin-bottom:3px;">Quotation Reference</div>
                    <div style="font-size:20px;font-weight:600;color:#8B7355;">' . htmlspecialchars($quote_ref) . '</div>
                </td>
                <td align="right" style="font-family:\'Segoe UI\',Tahoma,Verdana,sans-serif;">
                    <div style="font-size:11px;text-transform:uppercase;letter-spacing:1px;color:#999;margin-bottom:3px;">Valid Until</div>
                    <div style="font-size:16px;font-weight:600;color:#C0392B;">' . $valid_until->format('F j, Y') . '</div>
                </td>
            </tr></table>
        </div>';

        // Room card
        $htmlBody .= '
        <div style="background:#FFF;border:1px solid #DDD;border-radius:8px;padding:18px 22px;margin:20px 0;">
            <h2 style="color:#8B7355;font-family:Georgia,serif;font-weight:400;margin-top:0;font-size:18px;">'
            . htmlspecialchars((string)$room['name']) . '</h2>';
        if (!empty($room['short_description'])) {
            $htmlBody .= '<p style="font-family:\'Segoe UI\',Tahoma,Verdana,sans-serif;font-size:14px;color:#666;margin-top:0;">'
                . htmlspecialchars((string)$room['short_description']) . '</p>';
        }
        $meta = [];
        if (!empty($room['bed_type'])) {
            $meta[] = htmlspecialchars((string)$room['bed_type']);
        }
        if (!empty($room['size_sqm'])) {
            $meta[] = htmlspecialchars((string)$room['size_sqm']) . ' m&sup2;';
        }
        if (!empty($room['max_guests'])) {
            $meta[] = 'Max ' . (int)$room['max_guests'] . ' guests';
        }
        if (!empty($meta)) {
            $htmlBody .= '<p style="font-family:\'Segoe UI\',Tahoma,Verdana,sans-serif;font-size:13px;color:#888;margin:0 0 6px;">'
                . implode(' &nbsp;&middot;&nbsp; ', $meta) . '</p>';
        }
        $htmlBody .= '</div>';

        // Stay details
        $htmlBody .= '
        <div style="background:#FAF6F0;border-radius:8px;padding:18px 22px;margin:20px 0;">
            <h2 style="color:#8B7355;font-family:Georgia,serif;font-weight:400;margin-top:0;font-size:18px;">Stay Details</h2>
            <table role="presentation" width="100%" cellspacing="0" cellpadding="0">'
            . $trow('Check-in', date('l, F j, Y', strtotime($booking['check_in_date'])) . ' &mdash; from ' . htmlspecialchars($check_in_time))
            . $trow('Check-out', date('l, F j, Y', strtotime($booking['check_out_date'])) . ' &mdash; by ' . htmlspecialchars($check_out_time))
            . $trow('Duration', $nights . ' night' . ($nights !== 1 ? 's' : ''))
            . $trow('Guests', $guest_label);
        if (!empty($booking['special_requests'])) {
            $htmlBody .= $trow('Special Requests', htmlspecialchars((string)$booking['special_requests']));
        }
        $htmlBody .= '</table></div>';

        // Pricing breakdown
        $room_line  = htmlspecialchars((string)$room['name']) . ' &times; ' . $nights . ' night' . ($nights !== 1 ? 's' : '') . ' @ ' . $fmt($rate_per_night) . '/night';
        $htmlBody .= '
        <div style="background:#FFF;border:1px solid #DDD;border-radius:8px;padding:18px 22px;margin:20px 0;">
            <h2 style="color:#8B7355;font-family:Georgia,serif;font-weight:400;margin-top:0;font-size:18px;">Pricing Breakdown</h2>
            <table role="presentation" width="100%" cellspacing="0" cellpadding="0">'
            . $trow($room_line, $fmt($room_subtotal));
        if ($children > 0 && $child_supp > 0) {
            $htmlBody .= $trow('Child supplement (' . $children . ' child' . ($children !== 1 ? 'ren' : '') . ')', $fmt($child_supp));
        }
        if ($vat_enabled && $vat_amount > 0) {
            $htmlBody .= $trow('VAT (' . number_format($vat_rate, 0) . '%)', $fmt($vat_amount));
        }
        $htmlBody .= $trow('Total Amount', $fmt($total), true)
            . '</table></div>';

        // Deposit / payment terms
        if ($deposit_req && $deposit_amt > 0) {
            $balance = $total - $deposit_amt;
            $htmlBody .= '
        <div style="background:#FFF8DC;border-left:4px solid #F0A500;border-radius:4px;padding:15px 18px;margin:20px 0;">
            <h3 style="color:#856404;margin-top:0;font-family:\'Segoe UI\',Tahoma,Verdana,sans-serif;font-size:15px;">Deposit Required to Confirm</h3>
            <p style="color:#856404;font-family:\'Segoe UI\',Tahoma,Verdana,sans-serif;font-size:14px;margin:0;">
                A deposit of <strong>' . $fmt($deposit_amt) . '</strong> is required to secure this booking.
                The remaining balance of <strong>' . $fmt($balance) . '</strong> is due on arrival.
            </p>
        </div>';
        } else {
            $policy = (string)(getSetting('payment_policy') ?: 'Full payment is due on arrival. We accept cash and mobile money.');
            $htmlBody .= '
        <div style="background:#FFF8DC;border-left:4px solid #F0A500;border-radius:4px;padding:15px 18px;margin:20px 0;">
            <h3 style="color:#856404;margin-top:0;font-family:\'Segoe UI\',Tahoma,Verdana,sans-serif;font-size:15px;">Payment Terms</h3>
            <p style="color:#856404;font-family:\'Segoe UI\',Tahoma,Verdana,sans-serif;font-size:14px;margin:0;">' . $policy . '</p>
        </div>';
        }

        // Admin note
        if ($notes !== '') {
            $htmlBody .= '
        <div style="background:#F0F7FF;border-left:4px solid #4A90D9;border-radius:4px;padding:15px 18px;margin:20px 0;">
            <h3 style="color:#1A4A8A;margin-top:0;font-family:\'Segoe UI\',Tahoma,Verdana,sans-serif;font-size:15px;">Note from our team</h3>
            <p style="color:#1A4A8A;font-family:\'Segoe UI\',Tahoma,Verdana,sans-serif;font-size:14px;margin:0;">'
                . nl2br(htmlspecialchars($notes)) . '</p>
        </div>';
        }

        // CTA
        $confirm_email  = $email_from_email ?: getSetting('email_main', '');
        $mailto_subject = rawurlencode('Confirm Booking ' . $booking['booking_reference']);
        $htmlBody .= '
        <div style="text-align:center;margin:30px 0 20px;">
            <p style="font-family:\'Segoe UI\',Tahoma,Verdana,sans-serif;font-size:14px;color:#555;margin-bottom:16px;">
                To confirm this reservation, simply reply to this email or use the button below.
            </p>
            <a href="mailto:' . htmlspecialchars($confirm_email) . '?subject=' . $mailto_subject . '"
               style="display:inline-block;background:#8B7355;color:#FFF;padding:13px 30px;text-decoration:none;border-radius:4px;font-family:\'Segoe UI\',Tahoma,Verdana,sans-serif;font-size:15px;font-weight:600;letter-spacing:0.5px;">
                Confirm My Booking
            </a>';
        if (!empty($contact_phone)) {
            $htmlBody .= '<p style="font-family:\'Segoe UI\',Tahoma,Verdana,sans-serif;font-size:13px;color:#888;margin-top:14px;">Or call us: <a href="tel:' . htmlspecialchars($contact_phone) . '" style="color:#8B7355;">' . htmlspecialchars($contact_phone) . '</a></p>';
        }
        $htmlBody .= '
        </div>
        <p style="font-family:\'Segoe UI\',Tahoma,Verdana,sans-serif;font-size:13px;color:#999;text-align:center;">
            This quotation is valid until <strong>' . $valid_until->format('F j, Y') . '</strong>.
            Rates and availability are subject to change after this date.
        </p>
        <p style="margin:28px 0 0;font-size:14px;color:#777;text-align:center;font-style:italic;">
            Warm regards &mdash; we look forward to welcoming you.
        </p>';

        // ── Build subject & body (DB template takes priority) ─────────────────
        $subject  = 'Quotation for Your Stay — ' . $site_name . ' [' . $quote_ref . ']';
        $useDbTpl = false;

        if (function_exists('getBookingEmailTemplateConfig')) {
            $tplConfig = getBookingEmailTemplateConfig('tentative_quotation', []);
            if (!empty($tplConfig['subject']) && !empty($tplConfig['html_body'])) {
                $useDbTpl = true;
                $tagMap = [
                    '{{guest_name}}'               => htmlspecialchars($booking['guest_name']),
                    '{{booking_reference}}'        => htmlspecialchars($booking['booking_reference']),
                    '{{quotation_reference}}'      => htmlspecialchars($quote_ref),
                    '{{quote_reference}}'          => htmlspecialchars($quote_ref),
                    '{{room_name}}'                => htmlspecialchars((string)$room['name']),
                    '{{check_in_date}}'            => date('l, F j, Y', strtotime($booking['check_in_date'])),
                    '{{check_out_date}}'           => date('l, F j, Y', strtotime($booking['check_out_date'])),
                    '{{check_in_date_formatted}}'  => date('F j, Y', strtotime($booking['check_in_date'])),
                    '{{check_out_date_formatted}}' => date('F j, Y', strtotime($booking['check_out_date'])),
                    '{{number_of_nights}}'         => (string)$nights,
                    '{{nights}}'                   => (string)$nights,
                    '{{adult_guests}}'             => (string)$adults,
                    '{{child_guests}}'             => (string)$children,
                    '{{number_of_guests}}'         => (string)($adults + $children),
                    '{{guests}}'                   => $guest_label,
                    '{{total_amount}}'             => $fmt($total),
                    '{{total_amount_formatted}}'   => $fmt($total),
                    '{{currency_symbol}}'          => htmlspecialchars($currency),
                    '{{rate_per_night}}'           => $fmt($rate_per_night),
                    '{{room_subtotal}}'            => $fmt($room_subtotal),
                    '{{vat_amount}}'               => $fmt($vat_amount),
                    '{{vat_rate}}'                 => number_format($vat_rate, 0),
                    '{{child_supplement}}'         => $fmt($child_supp),
                    '{{deposit_amount}}'           => $fmt($deposit_amt),
                    '{{balance_due}}'              => $deposit_amt > 0 ? $fmt($total - $deposit_amt) : $fmt($total),
                    '{{payment_policy}}'           => htmlspecialchars((string)(getSetting('payment_policy') ?: 'Full payment is due on arrival.')),
                    '{{valid_until}}'              => $valid_until->format('F j, Y'),
                    '{{quotation_notes}}'          => nl2br(htmlspecialchars($notes)),
                    '{{site_name}}'                => htmlspecialchars($site_name),
                    '{{check_in_time}}'            => htmlspecialchars($check_in_time),
                    '{{check_out_time}}'           => htmlspecialchars($check_out_time),
                    '{{contact_phone}}'            => htmlspecialchars($contact_phone),
                    '{{phone_main}}'               => htmlspecialchars($contact_phone),
                    '{{contact_email}}'            => htmlspecialchars($email_from_email ?: getSetting('email_main', '')),
                ];
                $subject  = strtr((string)$tplConfig['subject'],  $tagMap);
                $htmlBody = strtr((string)$tplConfig['html_body'], $tagMap);
            }
        }

        // ── PDF attachment ────────────────────────────────────────────────────
        $pdfContent = null;
        if ($attach_pdf) {
            if (!function_exists('generateQuotationPDF')) {
                require_once __DIR__ . '/../includes/quotation-pdf.php';
            }
            $pdfContent = generateQuotationPDF($booking, $room, [
                'valid_days'      => $valid_days,
                'quotation_notes' => $notes,
            ]);
        }

        // ── Send ──────────────────────────────────────────────────────────────
        if ($pdfContent === null) {
            // No attachment — use the standard sendEmail helper
            $result = sendEmail($booking['guest_email'], $booking['guest_name'], $subject, $htmlBody);
        } else {
            // Has attachment — must use PHPMailer directly
            if ($development_mode && (empty($smtp_password) || $email_preview_enabled)) {
                return createEmailPreview($booking['guest_email'], $booking['guest_name'], $subject, $htmlBody, '');
            }
            $mail = new PHPMailer(true);
            $smtpSec = strtolower(trim((string)$smtp_secure));
            if ($smtpSec === '' && (int)$smtp_port === 587) {
                $smtpSec = 'tls';
            } elseif ($smtpSec === '' && (int)$smtp_port === 465) {
                $smtpSec = 'ssl';
            }
            $mail->isSMTP();
            $mail->Host       = $smtp_host;
            $mail->SMTPAuth   = true;
            $mail->Username   = $smtp_username;
            $mail->Password   = $smtp_password;
            if ($smtpSec !== '') {
                $mail->SMTPSecure = $smtpSec;
            }
            $mail->Port       = $smtp_port;
            $mail->Timeout    = $smtp_timeout;
            if ($smtp_debug > 0) {
                $mail->SMTPDebug = $smtp_debug;
            }
            $mail->setFrom($smtp_username, $email_from_name ?: $site_name);
            $mail->addAddress($booking['guest_email'], $booking['guest_name']);
            if (!empty($email_from_email) && filter_var($email_from_email, FILTER_VALIDATE_EMAIL)) {
                $mail->addReplyTo($email_from_email, $email_from_name ?: $site_name);
            }
            if ($email_bcc_admin && !empty($email_admin_email)) {
                $mail->addBCC($email_admin_email);
            }
            $mail->CharSet  = PHPMailer::CHARSET_UTF8;
            $mail->Encoding = PHPMailer::ENCODING_BASE64;
            $mail->isHTML(true);
            $mail->Subject  = $subject;
            $mail->Body     = wrapEmailTemplate($htmlBody, $subject);
            $mail->AltBody  = 'Please see the attached PDF quotation for your stay at ' . $site_name . '.';
            $mail->addStringAttachment($pdfContent, 'Quotation-' . $quote_ref . '.pdf', 'base64', 'application/pdf');
            $mail->send();
            $result = ['success' => true, 'message' => 'Quotation email sent.'];
        }

        if (!empty($result['success']) && $email_log_enabled) {
            logEmail($booking['guest_email'], $booking['guest_name'], $subject, 'sent');
        }

        if (!empty($result['success']) && $send_whatsapp && empty($result['preview_url']) && function_exists('sendRoomQuotationWhatsApp')) {
            $waResult = sendRoomQuotationWhatsApp($booking, $room, [
                'valid_days' => $valid_days,
                'quote_reference' => $quote_ref,
                'quotation_notes' => $notes,
            ]);
            $result['whatsapp'] = $waResult;

            if (!empty($waResult['success'])) {
                $result['message'] .= ' WhatsApp quotation sent.';
            } elseif (!in_array($waResult['message'] ?? '', ['No guest phone', 'WhatsApp disabled'], true)) {
                $result['message'] .= ' WhatsApp not sent: ' . ($waResult['message'] ?? 'Unknown error');
            }
        }

        return $result;
    } catch (Exception $e) {
        error_log('sendTentativeQuotationEmail Error: ' . $e->getMessage());
        return ['success' => false, 'message' => $e->getMessage()];
    }
}

/**
 * Send an HTML email with a binary attachment (typically PDF).
 * Named distinctly to avoid conflict with invoice.php's file-path sendEmailWithAttachment.
 */
function sendEmailWithBinaryAttachment(
    string $toEmail,
    string $toName,
    string $subject,
    string $htmlBody,
    string $attachmentContent,
    string $attachmentName,
    string $attachmentMime = 'application/pdf',
    string $altBody = ''
): array {
    global $email_from_name, $email_from_email, $email_site_name, $email_admin_email, $email_bcc_admin;
    global $smtp_host, $smtp_port, $smtp_username, $smtp_password, $smtp_secure, $smtp_timeout, $smtp_debug;
    global $development_mode, $email_preview_enabled;

    try {
        if ($development_mode && (empty($smtp_password) || $email_preview_enabled)) {
            return createEmailPreview($toEmail, $toName, $subject, $htmlBody, $altBody);
        }

        $mail = new PHPMailer(true);
        $smtpSec = strtolower(trim((string)$smtp_secure));
        if ($smtpSec === '' && (int)$smtp_port === 587) {
            $smtpSec = 'tls';
        } elseif ($smtpSec === '' && (int)$smtp_port === 465) {
            $smtpSec = 'ssl';
        }

        $mail->isSMTP();
        $mail->Host = $smtp_host;
        $mail->SMTPAuth = true;
        $mail->Username = $smtp_username;
        $mail->Password = $smtp_password;
        if ($smtpSec !== '') {
            $mail->SMTPSecure = $smtpSec;
        }
        $mail->Port = $smtp_port;
        $mail->Timeout = $smtp_timeout;
        if ($smtp_debug > 0) {
            $mail->SMTPDebug = $smtp_debug;
        }

        $fromName = $email_from_name ?: ($email_site_name ?: getSetting('site_name', "Rosalyn's Beach Hotel"));
        $mail->setFrom($smtp_username, $fromName);
        $mail->addAddress($toEmail, $toName);

        if (!empty($email_from_email) && filter_var($email_from_email, FILTER_VALIDATE_EMAIL)) {
            $mail->addReplyTo($email_from_email, $fromName);
        }
        if ($email_bcc_admin && !empty($email_admin_email)) {
            $mail->addBCC($email_admin_email);
        }

        $mail->CharSet = PHPMailer::CHARSET_UTF8;
        $mail->Encoding = PHPMailer::ENCODING_BASE64;
        $mail->isHTML(true);
        $mail->Subject = $subject;
        $mail->Body = wrapEmailTemplate($htmlBody, $subject);
        $mail->AltBody = $altBody !== '' ? $altBody : 'Please view this message in an HTML-compatible email client.';
        $mail->addStringAttachment($attachmentContent, $attachmentName, 'base64', $attachmentMime);
        $mail->send();

        return ['success' => true, 'message' => 'Email sent successfully.'];
    } catch (Exception $e) {
        error_log('sendEmailWithBinaryAttachment Error: ' . $e->getMessage());
        return ['success' => false, 'message' => $e->getMessage()];
    }
}

/**
 * Send conference quotation email with optional PDF and WhatsApp message.
 */
function sendConferenceQuotationEmail(array $enquiry, array $options = []): array
{
    global $pdo, $email_log_enabled, $email_from_email, $email_site_name;

    try {
        $recipientEmail = trim((string)($enquiry['email'] ?? ''));
        if ($recipientEmail === '' || !filter_var($recipientEmail, FILTER_VALIDATE_EMAIL)) {
            throw new Exception('Conference enquiry does not have a valid email address.');
        }

        $room = ['name' => (string)($enquiry['room_name'] ?? 'Conference Room')];
        $roomId = (int)($enquiry['conference_room_id'] ?? $enquiry['room_id'] ?? 0);
        if ($roomId > 0) {
            $roomStmt = $pdo->prepare('SELECT * FROM conference_rooms WHERE id = ? LIMIT 1');
            $roomStmt->execute([$roomId]);
            $roomRow = $roomStmt->fetch(PDO::FETCH_ASSOC);
            if ($roomRow) {
                $room = $roomRow;
            }
        }

        $siteName = $email_site_name ?: getSetting('site_name', "Rosalyn's Beach Hotel");
        $currency = (string)getSetting('currency_symbol', 'MWK ');
        $validDays = max(1, (int)($options['valid_days'] ?? 7));
        $notes = trim((string)($options['quotation_notes'] ?? ''));
        $attachPdf = (bool)($options['attach_pdf'] ?? true);
        $sendWhatsapp = (bool)($options['send_whatsapp'] ?? true);
        $validUntil = (new DateTime())->modify('+' . $validDays . ' days');

        $quoteRef = trim((string)($options['quote_reference'] ?? ''));
        if ($quoteRef === '') {
            $quoteRef = 'CQ-' . strtoupper((string)($enquiry['inquiry_reference'] ?? ('CONF-' . (int)($enquiry['id'] ?? 0))));
        }

        $baseAmount = (float)($enquiry['total_amount'] ?? 0);
        $vatAmount = (float)($enquiry['vat_amount'] ?? 0);
        $totalAmount = (float)($enquiry['total_with_vat'] ?? 0);
        if ($totalAmount <= 0) {
            $totalAmount = $baseAmount + $vatAmount;
        }

        $eventDate = !empty($enquiry['event_date']) ? date('l, F j, Y', strtotime((string)$enquiry['event_date'])) : 'To be confirmed';
        $startTime = !empty($enquiry['start_time']) ? date('H:i', strtotime((string)$enquiry['start_time'])) : '';
        $endTime = !empty($enquiry['end_time']) ? date('H:i', strtotime((string)$enquiry['end_time'])) : '';
        $eventTime = trim($startTime . ($endTime !== '' ? ' - ' . $endTime : ''));
        if ($eventTime === '') {
            $eventTime = 'To be confirmed';
        }

        $subject = 'Conference Quotation - ' . $siteName . ' [' . $quoteRef . ']';
        $htmlBody = '<h1 style="color:#8B7355;text-align:center;">Conference Quotation</h1>'
            . '<p>Dear ' . htmlspecialchars((string)($enquiry['contact_person'] ?? 'Guest'), ENT_QUOTES, 'UTF-8') . ',</p>'
            . '<p>Thank you for your conference enquiry. Please find your quotation details below.</p>'
            . '<p><strong>Inquiry Ref:</strong> ' . htmlspecialchars((string)($enquiry['inquiry_reference'] ?? ''), ENT_QUOTES, 'UTF-8') . '<br>'
            . '<strong>Quotation Ref:</strong> ' . htmlspecialchars($quoteRef, ENT_QUOTES, 'UTF-8') . '<br>'
            . '<strong>Company:</strong> ' . htmlspecialchars((string)($enquiry['company_name'] ?? ''), ENT_QUOTES, 'UTF-8') . '<br>'
            . '<strong>Room:</strong> ' . htmlspecialchars((string)($room['name'] ?? 'Conference Room'), ENT_QUOTES, 'UTF-8') . '<br>'
            . '<strong>Date:</strong> ' . htmlspecialchars($eventDate, ENT_QUOTES, 'UTF-8') . '<br>'
            . '<strong>Time:</strong> ' . htmlspecialchars($eventTime, ENT_QUOTES, 'UTF-8') . '<br>'
            . '<strong>Attendees:</strong> ' . (int)($enquiry['number_of_attendees'] ?? 1) . '<br>'
            . '<strong>Total:</strong> ' . htmlspecialchars($currency, ENT_QUOTES, 'UTF-8') . number_format($totalAmount, 0) . '<br>'
            . '<strong>Valid Until:</strong> ' . $validUntil->format('F j, Y') . '</p>';

        if ($notes !== '') {
            $htmlBody .= '<p><strong>Notes:</strong><br>' . nl2br(htmlspecialchars($notes, ENT_QUOTES, 'UTF-8')) . '</p>';
        }

        $htmlBody .= '<p>To confirm this quotation, reply to this email or contact us at '
            . htmlspecialchars((string)getSetting('phone_main', ''), ENT_QUOTES, 'UTF-8') . '.</p>';

        if (function_exists('getBookingEmailTemplateConfig')) {
            $tplConfig = getBookingEmailTemplateConfig('conference_quotation', []);
            if (!empty($tplConfig['subject']) && !empty($tplConfig['html_body'])) {
                $tagMap = [
                    '{{site_name}}' => htmlspecialchars($siteName, ENT_QUOTES, 'UTF-8'),
                    '{{guest_name}}' => htmlspecialchars((string)($enquiry['contact_person'] ?? 'Guest'), ENT_QUOTES, 'UTF-8'),
                    '{{contact_person}}' => htmlspecialchars((string)($enquiry['contact_person'] ?? 'Guest'), ENT_QUOTES, 'UTF-8'),
                    '{{company_name}}' => htmlspecialchars((string)($enquiry['company_name'] ?? ''), ENT_QUOTES, 'UTF-8'),
                    '{{inquiry_reference}}' => htmlspecialchars((string)($enquiry['inquiry_reference'] ?? ''), ENT_QUOTES, 'UTF-8'),
                    '{{quotation_reference}}' => htmlspecialchars($quoteRef, ENT_QUOTES, 'UTF-8'),
                    '{{quote_reference}}' => htmlspecialchars($quoteRef, ENT_QUOTES, 'UTF-8'),
                    '{{conference_room}}' => htmlspecialchars((string)($room['name'] ?? 'Conference Room'), ENT_QUOTES, 'UTF-8'),
                    '{{event_type}}' => htmlspecialchars((string)($enquiry['event_type'] ?? 'Conference Event'), ENT_QUOTES, 'UTF-8'),
                    '{{event_date}}' => htmlspecialchars($eventDate, ENT_QUOTES, 'UTF-8'),
                    '{{event_time}}' => htmlspecialchars($eventTime, ENT_QUOTES, 'UTF-8'),
                    '{{attendees}}' => (string)max(1, (int)($enquiry['number_of_attendees'] ?? 1)),
                    '{{total_amount}}' => htmlspecialchars($currency, ENT_QUOTES, 'UTF-8') . number_format($totalAmount, 0),
                    '{{total_amount_formatted}}' => htmlspecialchars($currency, ENT_QUOTES, 'UTF-8') . number_format($totalAmount, 0),
                    '{{currency_symbol}}' => htmlspecialchars($currency, ENT_QUOTES, 'UTF-8'),
                    '{{valid_until}}' => $validUntil->format('F j, Y'),
                    '{{quotation_notes}}' => nl2br(htmlspecialchars($notes, ENT_QUOTES, 'UTF-8')),
                    '{{contact_phone}}' => htmlspecialchars((string)getSetting('phone_main', ''), ENT_QUOTES, 'UTF-8'),
                    '{{contact_email}}' => htmlspecialchars((string)($email_from_email ?: getSetting('email_main', '')), ENT_QUOTES, 'UTF-8'),
                ];
                $subject = strtr((string)$tplConfig['subject'], $tagMap);
                $htmlBody = strtr((string)$tplConfig['html_body'], $tagMap);
            }
        }

        $result = ['success' => false, 'message' => 'Unable to send quotation email.'];
        if ($attachPdf) {
            if (!function_exists('generateConferenceQuotationPDF')) {
                require_once __DIR__ . '/../includes/quotation-pdf.php';
            }
            $pdfContent = generateConferenceQuotationPDF($enquiry, $room, [
                'valid_days' => $validDays,
                'quotation_notes' => $notes,
                'quote_reference' => $quoteRef,
            ]);
            $result = sendEmailWithBinaryAttachment(
                $recipientEmail,
                (string)($enquiry['contact_person'] ?? 'Guest'),
                $subject,
                $htmlBody,
                $pdfContent,
                'Conference-Quotation-' . $quoteRef . '.pdf',
                'application/pdf',
                'Please review the attached conference quotation PDF.'
            );
        } else {
            $result = sendEmail($recipientEmail, (string)($enquiry['contact_person'] ?? 'Guest'), $subject, $htmlBody);
        }

        if (!empty($result['success']) && $email_log_enabled) {
            logEmail($recipientEmail, (string)($enquiry['contact_person'] ?? ''), $subject, 'sent');
        }

        if (!empty($result['success']) && $sendWhatsapp && empty($result['preview_url']) && function_exists('sendConferenceQuotationWhatsApp')) {
            $waResult = sendConferenceQuotationWhatsApp($enquiry, $room, [
                'valid_days' => $validDays,
                'quote_reference' => $quoteRef,
                'quotation_notes' => $notes,
            ]);
            $result['whatsapp'] = $waResult;
            if (!empty($waResult['success'])) {
                $result['message'] .= ' WhatsApp quotation sent.';
            } elseif (!in_array($waResult['message'] ?? '', ['No contact phone', 'WhatsApp disabled'], true)) {
                $result['message'] .= ' WhatsApp not sent: ' . ($waResult['message'] ?? 'Unknown error');
            }
        }

        return $result;
    } catch (Exception $e) {
        error_log('sendConferenceQuotationEmail Error: ' . $e->getMessage());
        return ['success' => false, 'message' => $e->getMessage()];
    }
}

/**
 * Send event quotation email with optional PDF and WhatsApp message.
 */
function sendEventQuotationEmail(array $event, array $options = []): array
{
    global $email_log_enabled, $email_from_email, $email_site_name;

    try {
        $recipientName = trim((string)($options['recipient_name'] ?? 'Guest'));
        $recipientEmail = trim((string)($options['recipient_email'] ?? ''));
        $recipientPhone = trim((string)($options['recipient_phone'] ?? ''));
        if ($recipientEmail === '' || !filter_var($recipientEmail, FILTER_VALIDATE_EMAIL)) {
            throw new Exception('A valid recipient email is required for event quotations.');
        }

        $siteName = $email_site_name ?: getSetting('site_name', "Rosalyn's Beach Hotel");
        $currency = (string)getSetting('currency_symbol', 'MWK ');
        $attendeeCount = max(1, (int)($options['attendee_count'] ?? 1));
        $validDays = max(1, (int)($options['valid_days'] ?? 7));
        $notes = trim((string)($options['quotation_notes'] ?? ''));
        $attachPdf = (bool)($options['attach_pdf'] ?? true);
        $sendWhatsapp = (bool)($options['send_whatsapp'] ?? true);
        $validUntil = (new DateTime())->modify('+' . $validDays . ' days');

        $quoteRef = trim((string)($options['quote_reference'] ?? ''));
        if ($quoteRef === '') {
            $eventId = (int)($event['id'] ?? 0);
            $quoteRef = 'EQ-' . strtoupper((string)$eventId) . '-' . strtoupper(substr(hash('crc32b', $recipientEmail), 0, 6));
        }

        $eventTitle = (string)($event['title'] ?? 'Event');
        $eventDate = !empty($event['event_date']) ? date('l, F j, Y', strtotime((string)$event['event_date'])) : 'To be confirmed';
        $startTime = !empty($event['start_time']) ? date('H:i', strtotime((string)$event['start_time'])) : '';
        $endTime = !empty($event['end_time']) ? date('H:i', strtotime((string)$event['end_time'])) : '';
        $eventTime = trim($startTime . ($endTime !== '' ? ' - ' . $endTime : ''));
        if ($eventTime === '') {
            $eventTime = 'To be confirmed';
        }
        $eventLocation = (string)($event['location'] ?? 'To be confirmed');
        if ($eventLocation === '') {
            $eventLocation = 'To be confirmed';
        }

        $unitPrice = (float)($event['ticket_price'] ?? 0);
        $totalAmount = $unitPrice * $attendeeCount;

        $subject = 'Event Quotation - ' . $siteName . ' [' . $quoteRef . ']';
        $htmlBody = '<h1 style="color:#8B7355;text-align:center;">Event Quotation</h1>'
            . '<p>Dear ' . htmlspecialchars($recipientName, ENT_QUOTES, 'UTF-8') . ',</p>'
            . '<p>Please find your event quotation details below.</p>'
            . '<p><strong>Quotation Ref:</strong> ' . htmlspecialchars($quoteRef, ENT_QUOTES, 'UTF-8') . '<br>'
            . '<strong>Event:</strong> ' . htmlspecialchars($eventTitle, ENT_QUOTES, 'UTF-8') . '<br>'
            . '<strong>Date:</strong> ' . htmlspecialchars($eventDate, ENT_QUOTES, 'UTF-8') . '<br>'
            . '<strong>Time:</strong> ' . htmlspecialchars($eventTime, ENT_QUOTES, 'UTF-8') . '<br>'
            . '<strong>Location:</strong> ' . htmlspecialchars($eventLocation, ENT_QUOTES, 'UTF-8') . '<br>'
            . '<strong>Attendees:</strong> ' . $attendeeCount . '<br>'
            . '<strong>Total:</strong> ' . htmlspecialchars($currency, ENT_QUOTES, 'UTF-8') . number_format($totalAmount, 0) . '<br>'
            . '<strong>Valid Until:</strong> ' . $validUntil->format('F j, Y') . '</p>';

        if ($notes !== '') {
            $htmlBody .= '<p><strong>Notes:</strong><br>' . nl2br(htmlspecialchars($notes, ENT_QUOTES, 'UTF-8')) . '</p>';
        }

        $htmlBody .= '<p>To confirm this quotation, reply to this email or contact us at '
            . htmlspecialchars((string)getSetting('phone_main', ''), ENT_QUOTES, 'UTF-8') . '.</p>';

        if (function_exists('getBookingEmailTemplateConfig')) {
            $tplConfig = getBookingEmailTemplateConfig('event_quotation', []);
            if (!empty($tplConfig['subject']) && !empty($tplConfig['html_body'])) {
                $tagMap = [
                    '{{site_name}}' => htmlspecialchars($siteName, ENT_QUOTES, 'UTF-8'),
                    '{{recipient_name}}' => htmlspecialchars($recipientName, ENT_QUOTES, 'UTF-8'),
                    '{{quotation_reference}}' => htmlspecialchars($quoteRef, ENT_QUOTES, 'UTF-8'),
                    '{{quote_reference}}' => htmlspecialchars($quoteRef, ENT_QUOTES, 'UTF-8'),
                    '{{event_title}}' => htmlspecialchars($eventTitle, ENT_QUOTES, 'UTF-8'),
                    '{{event_date}}' => htmlspecialchars($eventDate, ENT_QUOTES, 'UTF-8'),
                    '{{event_time}}' => htmlspecialchars($eventTime, ENT_QUOTES, 'UTF-8'),
                    '{{event_location}}' => htmlspecialchars($eventLocation, ENT_QUOTES, 'UTF-8'),
                    '{{attendee_count}}' => (string)$attendeeCount,
                    '{{total_amount}}' => htmlspecialchars($currency, ENT_QUOTES, 'UTF-8') . number_format($totalAmount, 0),
                    '{{total_amount_formatted}}' => htmlspecialchars($currency, ENT_QUOTES, 'UTF-8') . number_format($totalAmount, 0),
                    '{{currency_symbol}}' => htmlspecialchars($currency, ENT_QUOTES, 'UTF-8'),
                    '{{valid_until}}' => $validUntil->format('F j, Y'),
                    '{{quotation_notes}}' => nl2br(htmlspecialchars($notes, ENT_QUOTES, 'UTF-8')),
                    '{{contact_phone}}' => htmlspecialchars((string)getSetting('phone_main', ''), ENT_QUOTES, 'UTF-8'),
                    '{{contact_email}}' => htmlspecialchars((string)($email_from_email ?: getSetting('email_main', '')), ENT_QUOTES, 'UTF-8'),
                ];
                $subject = strtr((string)$tplConfig['subject'], $tagMap);
                $htmlBody = strtr((string)$tplConfig['html_body'], $tagMap);
            }
        }

        $result = ['success' => false, 'message' => 'Unable to send quotation email.'];
        if ($attachPdf) {
            if (!function_exists('generateEventQuotationPDF')) {
                require_once __DIR__ . '/../includes/quotation-pdf.php';
            }
            $pdfContent = generateEventQuotationPDF($event, [
                'name' => $recipientName,
                'email' => $recipientEmail,
                'phone' => $recipientPhone,
            ], [
                'attendee_count' => $attendeeCount,
                'valid_days' => $validDays,
                'quotation_notes' => $notes,
                'quote_reference' => $quoteRef,
            ]);
            $result = sendEmailWithBinaryAttachment(
                $recipientEmail,
                $recipientName,
                $subject,
                $htmlBody,
                $pdfContent,
                'Event-Quotation-' . $quoteRef . '.pdf',
                'application/pdf',
                'Please review the attached event quotation PDF.'
            );
        } else {
            $result = sendEmail($recipientEmail, $recipientName, $subject, $htmlBody);
        }

        if (!empty($result['success']) && $email_log_enabled) {
            logEmail($recipientEmail, $recipientName, $subject, 'sent');
        }

        if (!empty($result['success']) && $sendWhatsapp && empty($result['preview_url']) && function_exists('sendEventQuotationWhatsApp')) {
            $waResult = sendEventQuotationWhatsApp($event, [
                'name' => $recipientName,
                'phone' => $recipientPhone,
            ], [
                'attendee_count' => $attendeeCount,
                'valid_days' => $validDays,
                'quote_reference' => $quoteRef,
                'quotation_notes' => $notes,
            ]);
            $result['whatsapp'] = $waResult;
            if (!empty($waResult['success'])) {
                $result['message'] .= ' WhatsApp quotation sent.';
            } elseif (!in_array($waResult['message'] ?? '', ['No recipient phone', 'WhatsApp disabled'], true)) {
                $result['message'] .= ' WhatsApp not sent: ' . ($waResult['message'] ?? 'Unknown error');
            }
        }

        return $result;
    } catch (Exception $e) {
        error_log('sendEventQuotationEmail Error: ' . $e->getMessage());
        return ['success' => false, 'message' => $e->getMessage()];
    }
}
