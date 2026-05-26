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
    function showAlert($message, $type = 'info', $options = []) {
        // Default options
        $defaults = [
            'dismissible' => true,
            'icon' => null,
            'timeout' => 0,
            'position' => 'top',
            'id' => null,
            'class' => ''
        ];
        
        $opts = array_merge($defaults, $options);
        
        // Type configurations
        $typeConfig = [
            'success' => [
                'icon' => 'fa-check-circle',
                'bg' => 'linear-gradient(135deg, #d4edda 0%, #c3e6cb 100%)',
                'border' => '#28a745',
                'color' => '#155724'
            ],
            'error' => [
                'icon' => 'fa-exclamation-circle',
                'bg' => 'linear-gradient(135deg, #f8d7da 0%, #f5c6cb 100%)',
                'border' => '#dc3545',
                'color' => '#721c24'
            ],
            'warning' => [
                'icon' => 'fa-exclamation-triangle',
                'bg' => 'linear-gradient(135deg, #fff3cd 0%, #ffeaa7 100%)',
                'border' => '#ffc107',
                'color' => '#856404'
            ],
            'info' => [
                'icon' => 'fa-info-circle',
                'bg' => 'linear-gradient(135deg, #d1ecf1 0%, #bee5eb 100%)',
                'border' => '#17a2b8',
                'color' => '#0c5460'
            ]
        ];
        
        $config = $typeConfig[$type] ?? $typeConfig['info'];
        $icon = $opts['icon'] ?? $config['icon'];
        $alertId = $opts['id'] ?? 'alert-' . uniqid();
        
        // Position classes (BEM modifiers)
        $positionClasses = [
            'top' => 'alert--top',
            'bottom' => 'alert--bottom',
            'top-left' => 'alert--top-left',
            'top-right' => 'alert--top-right',
            'bottom-left' => 'alert--bottom-left',
            'bottom-right' => 'alert--bottom-right'
        ];
        $positionClass = $positionClasses[$opts['position']] ?? 'alert--top';
        ?>
        <!-- Alert: <?php echo htmlspecialchars($alertId); ?> -->
        <div class="alert-wrapper <?php echo $positionClass; ?>">
            <div class="alert alert--<?php echo htmlspecialchars($type); ?> <?php echo htmlspecialchars($opts['class']); ?>"
                 id="<?php echo htmlspecialchars($alertId); ?>"
                 data-alert
                 data-alert-type="<?php echo htmlspecialchars($type); ?>"
                 data-alert-timeout="<?php echo (int)$opts['timeout']; ?>"
                 style="--alert-bg: <?php echo $config['bg']; ?>; --alert-border: <?php echo $config['border']; ?>; --alert-color: <?php echo $config['color']; ?>;"
                 role="alert"
                 aria-live="polite">
                
                <div class="alert__icon">
                    <i class="fas <?php echo htmlspecialchars($icon); ?>" aria-hidden="true"></i>
                </div>
                
                <div class="alert__content">
                    <?php echo $message; ?>
                </div>
                
                <?php if ($opts['dismissible']): ?>
                    <button class="alert__close" data-alert-close aria-label="Close alert">
                        <i class="fas fa-times" aria-hidden="true"></i>
                    </button>
                <?php endif; ?>
            </div>
        </div>
        <?php
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
