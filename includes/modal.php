<?php

/**
 * Modal Component - Reusable centered modal/popup
 *
 * Usage:
 * 1. Include this file: <?php include 'includes/modal.php'; ?>
 * 2. Call renderModal() function to display a modal
 *
 * @param string $id - Unique modal ID (required)
 * @param string $title - Modal title (required)
 * @param string $content - Modal body content (required)
 * @param array $options - Optional parameters:
 *   - 'size': 'sm' (small), 'md' (medium, default), 'lg' (large), 'xl' (extra large)
 *   - 'show_close': true/false (default: true)
 *   - 'close_on_overlay': true/false (default: true)
 *   - 'close_on_escape': true/false (default: true)
 *   - 'footer': Footer content (optional)
 *   - 'class': Additional CSS classes (optional)
 *   - 'attributes': Additional HTML attributes (optional)
 */

if (!function_exists('renderModal')) {
    function renderModal(string $id, string $title, string $content, array $options = []): void
    {
        // Default options
        $defaults = [
            'size' => 'md',
            'show_close' => true,
            'close_on_overlay' => true,
            'close_on_escape' => true,
            'footer' => null,
            'class' => '',
            'attributes' => ''
        ];

        $opts = array_merge($defaults, $options);

        // Size classes (BEM modifiers)
        $sizeClasses = [
            'sm' => 'modal--sm',
            'md' => 'modal--md',
            'lg' => 'modal--lg',
            'xl' => 'modal--xl'
        ];
        $sizeClass = $sizeClasses[$opts['size']] ?? 'modal--md';

        // Build attributes
        $dataAttrs = '';
        if ($opts['close_on_overlay']) {
            $dataAttrs .= ' data-close-on-overlay="true"';
        }
        if ($opts['close_on_escape']) {
            $dataAttrs .= ' data-close-on-escape="true"';
        }
        if (!empty($opts['attributes'])) {
            $dataAttrs .= ' ' . $opts['attributes'];
        }
?>
        <!-- Modal: <?php echo htmlspecialchars($id); ?> -->
        <div class="modal <?php echo htmlspecialchars($sizeClass); ?> <?php echo htmlspecialchars($opts['class']); ?>"
            id="<?php echo htmlspecialchars($id); ?>"
            data-modal<?php echo $dataAttrs; ?>
            role="dialog"
            aria-modal="true"
            aria-labelledby="<?php echo htmlspecialchars($id); ?>-title">
            <div class="modal__backdrop" data-modal-close></div>
            <div class="modal__wrapper">
                <div class="modal__container">
                    <?php if (!empty($title)): ?>
                        <div class="modal__header">
                            <h3 class="modal__title" id="<?php echo htmlspecialchars($id); ?>-title"><?php echo $title; ?></h3>
                            <?php if ($opts['show_close']): ?>
                                <button class="modal__close" data-modal-close aria-label="Close modal"></button>
                            <?php endif; ?>
                        </div>
                    <?php elseif ($opts['show_close']): ?>
                        <button class="modal__close modal__close--floating" data-modal-close aria-label="Close modal"></button>
                    <?php endif; ?>

                    <div class="modal__body">
                        <?php echo $content; ?>
                    </div>

                    <?php if (!empty($opts['footer'])): ?>
                        <div class="modal__footer">
                            <?php echo $opts['footer']; ?>
                        </div>
                    <?php endif; ?>
                </div>
            </div>
        </div>
<?php
    }
}
?>
