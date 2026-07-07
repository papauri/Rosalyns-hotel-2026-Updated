<?php
/**
 * WhatsApp Settings Admin Page
 * Manages WhatsApp Business API configuration and notification settings
 */

require_once 'admin-init.php';
require_once '../config/email.php';

$csrf_token = $csrf_token ?? generateCsrfToken();

// Get current settings
$whatsapp_enabled = getSetting('whatsapp_enabled', getSetting('whatsapp_notifications_enabled', '0'));
$whatsapp_api_token = getSetting('whatsapp_api_token', getSetting('whatsapp_meta_access_token', ''));
$whatsapp_phone_id = getSetting('whatsapp_phone_id', getSetting('whatsapp_meta_phone_number_id', ''));
$whatsapp_business_id = getSetting('whatsapp_business_id', getSetting('whatsapp_meta_business_account_id', ''));
$whatsapp_number = getSetting('whatsapp_number', getSetting('whatsapp_hotel_number', ''));

// Notification settings
$whatsapp_notify_on_booking = getSetting('whatsapp_notify_on_booking', '1');
$whatsapp_notify_on_confirmation = getSetting('whatsapp_notify_on_confirmation', '1');
$whatsapp_notify_on_cancellation = getSetting('whatsapp_notify_on_cancellation', '1');
$whatsapp_notify_on_checkin = getSetting('whatsapp_notify_on_checkin', '1');
$whatsapp_notify_on_checkout = getSetting('whatsapp_notify_on_checkout', '1');

// Recipients
$whatsapp_guest_notifications = getSetting('whatsapp_guest_notifications', '1');
$whatsapp_hotel_notifications = getSetting('whatsapp_hotel_notifications', '1');
$whatsapp_admin_numbers = getSetting('whatsapp_admin_numbers', '');

// Message templates
$whatsapp_confirmed_template = getSetting('whatsapp_confirmed_template', 'booking_confirmed');
$whatsapp_cancelled_template = getSetting('whatsapp_cancelled_template', 'booking_cancelled');

// Handle form submission
$message = '';
$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && !isset($_POST['test_whatsapp'])) {
    try {
        if (!isset($_POST['csrf_token']) || !validateCsrfToken($_POST['csrf_token'])) {
            throw new RuntimeException('Invalid security token. Please refresh and try again.');
        }

        $pdo->beginTransaction();
        
        // API Settings
        $settings = [
            'whatsapp_enabled' => isset($_POST['whatsapp_enabled']) ? '1' : '0',
            'whatsapp_notifications_enabled' => isset($_POST['whatsapp_enabled']) ? '1' : '0',
            'whatsapp_provider' => 'meta',
            'whatsapp_api_token' => trim($_POST['whatsapp_api_token'] ?? ''),
            'whatsapp_meta_access_token' => trim($_POST['whatsapp_api_token'] ?? ''),
            'whatsapp_phone_id' => trim($_POST['whatsapp_phone_id'] ?? ''),
            'whatsapp_meta_phone_number_id' => trim($_POST['whatsapp_phone_id'] ?? ''),
            'whatsapp_business_id' => trim($_POST['whatsapp_business_id'] ?? ''),
            'whatsapp_meta_business_account_id' => trim($_POST['whatsapp_business_id'] ?? ''),
            'whatsapp_number' => trim($_POST['whatsapp_number'] ?? ''),
            'whatsapp_hotel_number' => trim($_POST['whatsapp_number'] ?? ''),
            
            // Notification triggers
            'whatsapp_notify_on_booking' => isset($_POST['whatsapp_notify_on_booking']) ? '1' : '0',
            'whatsapp_notify_on_confirmation' => isset($_POST['whatsapp_notify_on_confirmation']) ? '1' : '0',
            'whatsapp_notify_on_cancellation' => isset($_POST['whatsapp_notify_on_cancellation']) ? '1' : '0',
            'whatsapp_notify_on_checkin' => isset($_POST['whatsapp_notify_on_checkin']) ? '1' : '0',
            'whatsapp_notify_on_checkout' => isset($_POST['whatsapp_notify_on_checkout']) ? '1' : '0',
            
            // Recipients
            'whatsapp_guest_notifications' => isset($_POST['whatsapp_guest_notifications']) ? '1' : '0',
            'whatsapp_hotel_notifications' => isset($_POST['whatsapp_hotel_notifications']) ? '1' : '0',
            'whatsapp_admin_numbers' => trim($_POST['whatsapp_admin_numbers'] ?? ''),
            
            // Templates
            'whatsapp_confirmed_template' => trim($_POST['whatsapp_confirmed_template'] ?? 'booking_confirmed'),
            'whatsapp_cancelled_template' => trim($_POST['whatsapp_cancelled_template'] ?? 'booking_cancelled'),
        ];
        
        // Update each setting
        $stmt = $pdo->prepare("
            INSERT INTO site_settings (setting_key, setting_value) 
            VALUES (?, ?) 
            ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)
        ");
        
        foreach ($settings as $key => $value) {
            $stmt->execute([$key, $value]);
        }
        
        $pdo->commit();
        
        // Refresh settings
        $whatsapp_enabled = $settings['whatsapp_enabled'];
        $whatsapp_api_token = $settings['whatsapp_api_token'];
        $whatsapp_phone_id = $settings['whatsapp_phone_id'];
        $whatsapp_business_id = $settings['whatsapp_business_id'];
        $whatsapp_number = $settings['whatsapp_number'];
        $whatsapp_notify_on_booking = $settings['whatsapp_notify_on_booking'];
        $whatsapp_notify_on_confirmation = $settings['whatsapp_notify_on_confirmation'];
        $whatsapp_notify_on_cancellation = $settings['whatsapp_notify_on_cancellation'];
        $whatsapp_notify_on_checkin = $settings['whatsapp_notify_on_checkin'];
        $whatsapp_notify_on_checkout = $settings['whatsapp_notify_on_checkout'];
        $whatsapp_guest_notifications = $settings['whatsapp_guest_notifications'];
        $whatsapp_hotel_notifications = $settings['whatsapp_hotel_notifications'];
        $whatsapp_admin_numbers = $settings['whatsapp_admin_numbers'];
        $whatsapp_confirmed_template = $settings['whatsapp_confirmed_template'];
        $whatsapp_cancelled_template = $settings['whatsapp_cancelled_template'];
        
        $message = 'WhatsApp settings saved successfully!';
        if (function_exists('clearSettingsCache')) {
            clearSettingsCache();
        }
        rh_log_event('whatsapp_settings', 'info', 'WhatsApp settings saved', [
            'enabled' => $whatsapp_enabled,
            'has_number' => $whatsapp_number !== '',
            'has_phone_id' => $whatsapp_phone_id !== '',
            'has_business_id' => $whatsapp_business_id !== '',
            'has_token' => $whatsapp_api_token !== '',
        ]);
        
    } catch (Exception $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        $error = 'Error saving settings: ' . $e->getMessage();
        rh_log_event('whatsapp_settings', 'error', 'Failed saving WhatsApp settings', ['error' => $e->getMessage()]);
    }
}

// Test WhatsApp if requested
$testResult = null;
if (isset($_POST['test_whatsapp']) && !empty($_POST['test_number'])) {
    try {
        if (!isset($_POST['csrf_token']) || !validateCsrfToken($_POST['csrf_token'])) {
            throw new RuntimeException('Invalid security token. Please refresh and try again.');
        }
        $testNumber = trim($_POST['test_number']);
    
        if (function_exists('sendWhatsAppTestMessage')) {
            $testResult = sendWhatsAppTestMessage($testNumber);
        } elseif (function_exists('sendWhatsAppMessage')) {
            $testResult = sendWhatsAppMessage(
                $testNumber,
                "Test message from " . getSetting('site_name') . " WhatsApp integration. Sent at: " . date('Y-m-d H:i:s')
            );
        } else {
            $testResult = ['success' => false, 'message' => 'WhatsApp functions not loaded'];
        }
        rh_log_event('whatsapp_settings', !empty($testResult['success']) ? 'info' : 'warning', 'WhatsApp test attempted', [
            'success' => !empty($testResult['success']),
            'message' => $testResult['message'] ?? '',
        ]);
    } catch (Throwable $e) {
        $testResult = ['success' => false, 'message' => $e->getMessage()];
        rh_log_event('whatsapp_settings', 'error', 'WhatsApp test failed before send', ['error' => $e->getMessage()]);
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>WhatsApp Settings - Admin Panel</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Cormorant+Garamond:ital,wght@0,300;0,400;0,500;0,600;1,300;1,400;1,500&family=Jost:wght@300;400;500;600&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="css/admin-styles.css?v=<?php echo @filemtime(__DIR__ . '/css/admin-styles.css'); ?>">
    <link rel="stylesheet" href="css/admin-components.css?v=<?php echo @filemtime(__DIR__ . '/css/admin-components.css'); ?>">
    <link rel="stylesheet" href="css/admin-booking-settings.css?v=<?php echo @filemtime(__DIR__ . '/css/admin-booking-settings.css'); ?>">
    <link rel="stylesheet" href="css/whatsapp-settings.css?v=<?php echo @filemtime(__DIR__ . '/css/whatsapp-settings.css'); ?>">
</head>
<body>
    <?php require_once 'includes/admin-header.php'; ?>

    <div class="content">
        <a href="booking-settings.php" class="back-link">
            <i class="fas fa-arrow-left"></i> Back to Booking Settings
        </a>

        <div class="page-header">
            <h1 class="page-title">
                <i class="fab fa-whatsapp whatsapp-icon" style="margin-right: 10px;"></i>
                WhatsApp Settings
            </h1>
        </div>

        <?php if ($message): ?>
            <div class="alert alert-success">
                <i class="fas fa-check-circle"></i>
                <div><?php echo htmlspecialchars($message); ?></div>
            </div>
        <?php endif; ?>

        <?php if ($error): ?>
            <div class="alert alert-error">
                <i class="fas fa-exclamation-circle"></i>
                <div><?php echo htmlspecialchars($error); ?></div>
            </div>
        <?php endif; ?>

        <?php if ($testResult): ?>
            <div class="alert alert-<?php echo $testResult['success'] ? 'success' : 'error'; ?>">
                <i class="fas fa-<?php echo $testResult['success'] ? 'check-circle' : 'exclamation-circle'; ?>"></i>
                <div><strong>Test Result:</strong> <?php echo htmlspecialchars($testResult['message']); ?></div>
            </div>
        <?php endif; ?>

        <form method="POST" action="">
            <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrf_token, ENT_QUOTES, 'UTF-8'); ?>">
            <!-- API Configuration -->
            <div class="settings-card whatsapp-card">
                <h2><i class="fas fa-cog" style="color: #8B7355;"></i> API Configuration</h2>
                
                <div class="form-group">
                    <label style="display: flex; align-items: center; gap: 10px; cursor: pointer;">
                        <input type="checkbox" 
                               id="whatsapp_enabled" 
                               name="whatsapp_enabled" 
                               value="1" 
                               <?php echo $whatsapp_enabled === '1' ? 'checked' : ''; ?>>
                        <span style="font-weight: 600; color: #1A1A1A;">Enable WhatsApp Notifications</span>
                    </label>
                    <p class="help-text">
                        <i class="fas fa-info-circle"></i>
                        Turn on/off all WhatsApp notifications. You must configure API credentials below before enabling.
                    </p>
                </div>

                <hr style="margin: 25px 0; border-top: 2px solid #eee;">

                <div class="form-group">
                    <label for="whatsapp_number"><strong>Hotel WhatsApp Number</strong></label>
                    <input type="text" 
                           id="whatsapp_number" 
                           name="whatsapp_number" 
                           class="form-control" 
                           value="<?php echo htmlspecialchars($whatsapp_number); ?>" 
                           placeholder="+353860081635">
                    <p class="help-text">
                        <i class="fas fa-info-circle"></i>
                        Your hotel's WhatsApp Business number with country code (e.g., +353860081635)
                    </p>
                </div>

                <div class="form-group">
                    <label for="whatsapp_phone_id"><strong>WhatsApp Phone Number ID</strong></label>
                    <input type="text" 
                           id="whatsapp_phone_id" 
                           name="whatsapp_phone_id" 
                           class="form-control" 
                           value="<?php echo htmlspecialchars($whatsapp_phone_id); ?>" 
                           placeholder="123456789012345">
                    <p class="help-text">
                        <i class="fas fa-info-circle"></i>
                        From Meta Business Suite > WhatsApp > Phone Numbers
                    </p>
                </div>

                <div class="form-group">
                    <label for="whatsapp_business_id"><strong>WhatsApp Business Account ID</strong></label>
                    <input type="text" 
                           id="whatsapp_business_id" 
                           name="whatsapp_business_id" 
                           class="form-control" 
                           value="<?php echo htmlspecialchars($whatsapp_business_id); ?>" 
                           placeholder="123456789012345">
                    <p class="help-text">
                        <i class="fas fa-info-circle"></i>
                        From Meta Business Suite > Business Settings
                    </p>
                </div>

                <div class="form-group">
                    <label for="whatsapp_api_token"><strong>Permanent Access Token</strong></label>
                    <input type="password" 
                           id="whatsapp_api_token" 
                           name="whatsapp_api_token" 
                           class="form-control" 
                           value="<?php echo htmlspecialchars($whatsapp_api_token); ?>" 
                           placeholder="EAAxxxxxxxxxxxxxxxxxxxxxxxxxxxx">
                    <p class="help-text">
                        <i class="fas fa-info-circle"></i>
                        From Meta Business Suite > System Users. Create a System User with WhatsApp permissions.
                    </p>
                </div>

                <div class="info-box" style="background: #e7f3ff; border-left-color: #0d6efd;">
                    <h4><i class="fas fa-info-circle"></i> Setup Guide</h4>
                    <ol style="margin: 10px 0 0 20px; padding: 0;">
                        <li>Go to <a href="https://business.facebook.com" target="_blank">Meta Business Suite</a></li>
                        <li>Navigate to WhatsApp > Overview</li>
                        <li>Copy your Phone Number ID and Business Account ID</li>
                        <li>Create a System User and generate a Permanent Access Token</li>
                        <li>Create message templates in WhatsApp Manager (must be approved)</li>
                    </ol>
                    <p style="margin: 12px 0 0; color:#0c5460;">
                        This page saves both the admin display fields and the sender fields used by the notification code, so once the WhatsApp number, Phone Number ID and token are entered, booking notifications read them from the database automatically.
                    </p>
                </div>
            </div>

            <!-- Notification Triggers -->
            <div class="settings-card">
                <h2><i class="fas fa-bell" style="color: #8B7355;"></i> Notification Triggers</h2>
                <p class="help-text" style="margin-bottom: 20px;">
                    <i class="fas fa-info-circle"></i>
                    Select which events should trigger WhatsApp notifications.
                </p>

                <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(250px, 1fr)); gap: 15px;">
                    <div class="form-group">
                        <label style="display: flex; align-items: center; gap: 10px;">
                            <input type="checkbox" name="whatsapp_notify_on_booking" value="1" <?php echo $whatsapp_notify_on_booking === '1' ? 'checked' : ''; ?>>
                            <span>New Booking Created</span>
                        </label>
                    </div>

                    <div class="form-group">
                        <label style="display: flex; align-items: center; gap: 10px;">
                            <input type="checkbox" name="whatsapp_notify_on_confirmation" value="1" <?php echo $whatsapp_notify_on_confirmation === '1' ? 'checked' : ''; ?>>
                            <span>Booking Confirmed</span>
                        </label>
                    </div>

                    <div class="form-group">
                        <label style="display: flex; align-items: center; gap: 10px;">
                            <input type="checkbox" name="whatsapp_notify_on_cancellation" value="1" <?php echo $whatsapp_notify_on_cancellation === '1' ? 'checked' : ''; ?>>
                            <span>Booking Cancelled</span>
                        </label>
                    </div>

                    <div class="form-group">
                        <label style="display: flex; align-items: center; gap: 10px;">
                            <input type="checkbox" name="whatsapp_notify_on_checkin" value="1" <?php echo $whatsapp_notify_on_checkin === '1' ? 'checked' : ''; ?>>
                            <span>Guest Check-in</span>
                        </label>
                    </div>

                    <div class="form-group">
                        <label style="display: flex; align-items: center; gap: 10px;">
                            <input type="checkbox" name="whatsapp_notify_on_checkout" value="1" <?php echo $whatsapp_notify_on_checkout === '1' ? 'checked' : ''; ?>>
                            <span>Guest Check-out</span>
                        </label>
                    </div>
                </div>
            </div>

            <!-- Recipients -->
            <div class="settings-card">
                <h2><i class="fas fa-users" style="color: #8B7355;"></i> Notification Recipients</h2>

                <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(250px, 1fr)); gap: 15px; margin-bottom: 20px;">
                    <div class="form-group">
                        <label style="display: flex; align-items: center; gap: 10px;">
                            <input type="checkbox" name="whatsapp_guest_notifications" value="1" <?php echo $whatsapp_guest_notifications === '1' ? 'checked' : ''; ?>>
                            <span>Send to Guest</span>
                        </label>
                        <p class="help-text">Uses guest phone number from booking</p>
                    </div>

                    <div class="form-group">
                        <label style="display: flex; align-items: center; gap: 10px;">
                            <input type="checkbox" name="whatsapp_hotel_notifications" value="1" <?php echo $whatsapp_hotel_notifications === '1' ? 'checked' : ''; ?>>
                            <span>Send to Hotel/Admin</span>
                        </label>
                        <p class="help-text">Sends to admin numbers below</p>
                    </div>
                </div>

                <div class="form-group">
                    <label for="whatsapp_admin_numbers"><strong>Admin WhatsApp Numbers</strong></label>
                    <textarea id="whatsapp_admin_numbers" 
                              name="whatsapp_admin_numbers" 
                              class="form-control" 
                              rows="2" 
                              placeholder="+353861234567, +353867654321"><?php echo htmlspecialchars($whatsapp_admin_numbers); ?></textarea>
                    <p class="help-text">
                        <i class="fas fa-info-circle"></i>
                        Comma-separated list of admin numbers to receive notifications
                    </p>
                </div>
            </div>

            <!-- Message Templates -->
            <div class="settings-card">
                <h2><i class="fas fa-file-alt" style="color: #8B7355;"></i> Message Template Names</h2>

                <div class="info-box" style="background: #fff3cd; border-left-color: #ffc107;">
                    <h4><i class="fas fa-exclamation-triangle"></i> Important</h4>
                    <p style="color: #856404; margin: 0;">
                        Template names must match <strong>exactly</strong> what you created in WhatsApp Manager. 
                        Templates must be approved by WhatsApp before they can be used.
                    </p>
                </div>

                <div class="template-box">
                    <h4><i class="fas fa-check-circle" style="color: #28a745;"></i> Booking Confirmed Template</h4>
                    <div class="form-group" style="margin-bottom: 0;">
                        <input type="text" 
                               name="whatsapp_confirmed_template" 
                               class="form-control" 
                               value="<?php echo htmlspecialchars($whatsapp_confirmed_template); ?>" 
                               placeholder="booking_confirmed">
                        <p class="help-text">Template name sent when a booking is confirmed</p>
                    </div>
                </div>

                <div class="template-box">
                    <h4><i class="fas fa-times-circle" style="color: #dc3545;"></i> Booking Cancelled Template</h4>
                    <div class="form-group" style="margin-bottom: 0;">
                        <input type="text" 
                               name="whatsapp_cancelled_template" 
                               class="form-control" 
                               value="<?php echo htmlspecialchars($whatsapp_cancelled_template); ?>" 
                               placeholder="booking_cancelled">
                        <p class="help-text">Template name sent when a booking is cancelled</p>
                    </div>
                </div>
            </div>

            <!-- Test WhatsApp -->
            <div class="settings-card">
                <h2><i class="fas fa-vial" style="color: #8B7355;"></i> Test WhatsApp Integration</h2>

                <div class="form-group">
                    <label for="test_number"><strong>Test Phone Number</strong></label>
                    <input type="text" 
                           id="test_number" 
                           name="test_number" 
                           class="form-control" 
                           placeholder="+353861234567"
                           style="max-width: 300px;">
                    <p class="help-text">
                        <i class="fas fa-info-circle"></i>
                        Enter a phone number to send a test message (requires API credentials to be configured)
                    </p>
                </div>

                <button type="submit" name="test_whatsapp" class="btn-submit" style="background: #25D366;" onclick="return confirm('This sends a live WhatsApp message through the configured provider and may incur Meta/WhatsApp charges. Continue?');">
                    <i class="fab fa-whatsapp"></i> Send Test Message
                </button>
            </div>

            <!-- Save Button -->
            <div style="display: flex; justify-content: flex-end; gap: 15px; margin-top: 20px;">
                <a href="booking-settings.php" class="btn-submit" style="background: #6c757d; text-decoration: none;">
                    <i class="fas fa-arrow-left"></i> Back to Settings
                </a>
                <button type="submit" class="btn-submit">
                    <i class="fas fa-save"></i> Save Settings
                </button>
            </div>
        </form>
    </div>

    <?php require_once 'includes/admin-footer.php'; ?>
</body>
</html>

