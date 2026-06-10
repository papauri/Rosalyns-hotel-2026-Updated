<?php
/**
 * Alert Component - Reusable centered alert messages
 * 
 * Usage:
 * 1. Include this file: <?php include 'includes/alert.php'; ?>
 * 2. Call showAlert() function to display an alert
 * 
 * @param string $message - Alert message (required)
 * @param string $type - Alert type: 'success', 'error', 'warning', 'info' (default: 'info')
 * @param array $options - Optional parameters:
 *   - 'dismissible': true/false (default: true)
 *   - 'icon': Custom icon class (default: auto based on type)
 *   - 'timeout': Auto-dismiss after milliseconds (default: 0 = no auto-dismiss)
 *   - 'position': 'top', 'bottom', 'top-left', 'top-right', 'bottom-left', 'bottom-right' (default: 'top')
 *   - 'id': Custom alert ID (optional)
 *   - 'class': Additional CSS classes (optional)
 */

if (!function_exists('showAlert')) {
    /**
     * Show a toast notification via the JS Alert system.
     * Queues the call if Alert hasn't loaded yet (script runs mid-body).
     */
    function showAlert($message, $type = 'info', $options = []) {
        $defaults = [
            'dismissible' => true,
            'icon'        => null,
            'timeout'     => 5000,
            'position'    => 'top-right',
            'id'          => null,
            'class'       => '',
            'title'       => null,
        ];
        $opts = array_merge($defaults, $options);

        $jsOpts = [
            'dismissible' => (bool)$opts['dismissible'],
            'timeout'     => (int)$opts['timeout'],
            'position'    => (string)$opts['position'],
        ];
        if ($opts['class'])  $jsOpts['class']  = (string)$opts['class'];
        if ($opts['icon'])   $jsOpts['icon']   = (string)$opts['icon'];
        if ($opts['title'])  $jsOpts['title']  = (string)$opts['title'];
        if ($opts['id'])     $jsOpts['id']     = (string)$opts['id'];

        $msgJson  = json_encode((string)$message, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT);
        $typeJson = json_encode($type);
        $optsJson = json_encode($jsOpts);

        echo '<script>(window.__rhToastQueue=window.__rhToastQueue||[]).push({msg:' . $msgJson . ',type:' . $typeJson . ',opts:' . $optsJson . '});</script>' . "\n";
    }
}

/**
 * Show alert from session (for flash messages)
 * Usage: <?php showSessionAlert(); ?>
 */
if (!function_exists('showSessionAlert')) {
    function showSessionAlert() {
        if (isset($_SESSION['alert'])) {
            $alert = $_SESSION['alert'];
            unset($_SESSION['alert']);
            showAlert($alert['message'], $alert['type'], $alert['options'] ?? []);
        }
    }
}

/**
 * Set alert in session (for flash messages)
 * Usage: setSessionAlert('Success message', 'success');
 */
if (!function_exists('setSessionAlert')) {
    function setSessionAlert($message, $type = 'info', $options = []) {
        $_SESSION['alert'] = [
            'message' => $message,
            'type' => $type,
            'options' => $options
        ];
    }
}
?>
