<?php
/**
 * Admin Flash — shared session flash renderer.
 * Included by admin-footer.php.
 * Reads any unconsumed session flash keys and queues them as toasts.
 * Pages that already consumed their own keys (e.g. booking-details.php) are unaffected.
 */
$_rhFlashPairs = [
    ['success_message',  'success'],
    ['error_message',    'error'],
    ['warning_message',  'warning'],
    ['info_message',     'info'],
    ['stock_msg',        'success'],
    ['stock_err',        'error'],
    ['flash_success',    'success'],
    ['flash_error',      'error'],
    ['flash_warning',    'warning'],
];

$_rhFlashItems = [];
foreach ($_rhFlashPairs as [$_rhKey, $_rhType]) {
    if (!empty($_SESSION[$_rhKey])) {
        $_rhFlashItems[] = [
            'msg'  => (string)$_SESSION[$_rhKey],
            'type' => $_rhType,
        ];
        unset($_SESSION[$_rhKey]);
    }
}
unset($_rhFlashPairs, $_rhKey, $_rhType);

if (!empty($_rhFlashItems)): ?>
<script>
(function() {
    var _items = <?php echo json_encode($_rhFlashItems, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT); ?>;
    function _flush() {
        if (!window.Alert || typeof window.Alert.show !== 'function') {
            return setTimeout(_flush, 60);
        }
        _items.forEach(function(item) {
            window.Alert.show(item.msg, item.type);
        });
    }
    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', _flush);
    } else {
        _flush();
    }
})();
</script>
<?php endif;
unset($_rhFlashItems);
?>

