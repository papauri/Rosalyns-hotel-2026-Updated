<?php
/**
 * Shared helpers for bulk actions on admin list pages (pairs with admin/js/admin-bulk.js).
 *
 * Server side: adminBulkIds() reads the whitelist of ids, each page loops its OWN single-row logic
 * over them and collects skip reasons, adminBulkOutcome() turns that into "X done, Y skipped (...)".
 * Markup side: adminBulkBarStart/Button/End print the bulk bar, adminBulkAllBox/RowBox the checkboxes.
 */

if (!function_exists('adminBulkIds')) {
    /** Unique positive integer ids from POST bulk_ids[] (anything else is dropped). */
    function adminBulkIds(string $key = 'bulk_ids', int $max = 500): array
    {
        $raw = $_POST[$key] ?? [];
        if (!is_array($raw)) {
            return [];
        }
        $ids = [];
        foreach ($raw as $value) {
            if (is_scalar($value) && preg_match('/^[0-9]{1,18}$/', (string)$value)) {
                $id = (int)$value;
                if ($id > 0) {
                    $ids[$id] = $id;
                }
            }
            if (count($ids) >= $max) {
                break;
            }
        }
        return array_values($ids);
    }

    /** Count a skipped row against its reason. */
    function adminBulkSkip(array &$skipped, string $reason): void
    {
        $skipped[$reason] = ($skipped[$reason] ?? 0) + 1;
    }

    /**
     * "3 marked done, 2 skipped (verified tasks are locked x1, not found x1)".
     * Returns [message, error]: nothing done and something skipped is an error, otherwise a message.
     */
    function adminBulkOutcome(string $verb, int $done, array $skipped): array
    {
        $skipCount = array_sum($skipped);
        $text = $done . ' ' . $verb;
        if ($skipCount > 0) {
            $parts = [];
            foreach ($skipped as $reason => $n) {
                $parts[] = $reason . ' x' . $n;
            }
            $text .= ', ' . $skipCount . ' skipped (' . implode('; ', $parts) . ')';
        }
        if ($done === 0 && $skipCount === 0) {
            return ['', 'Nothing was selected.'];
        }
        return $done === 0 ? ['', $text . '.'] : [$text . '.', ''];
    }

    function adminBulkBarStart(string $action, string $scopeSelector, string $csrf): void
    {
        echo '<form method="POST" class="admin-bulk-bar" data-bulk-bar data-bulk-scope="' . htmlspecialchars($scopeSelector, ENT_QUOTES, 'UTF-8') . '" hidden>';
        echo '<input type="hidden" name="action" value="' . htmlspecialchars($action, ENT_QUOTES, 'UTF-8') . '">';
        echo '<input type="hidden" name="csrf_token" value="' . htmlspecialchars($csrf, ENT_QUOTES, 'UTF-8') . '">';
        echo '<span class="admin-bulk-bar__count"><i class="fas fa-check-square" aria-hidden="true"></i> <strong data-bulk-count>0</strong> selected</span>';
        echo '<div class="admin-bulk-bar__controls">';
    }

    /**
     * Submit button for one bulk operation. $confirm may use {n} (selection size); $requires is a
     * comma list of bar field names that must be non-empty before the button enables.
     */
    function adminBulkButton(string $op, string $label, string $icon, string $confirm, string $class = 'btn-secondary', string $requires = '', string $confirmOk = '', string $tone = 'warning'): void
    {
        echo '<button type="submit" name="bulk_op" value="' . htmlspecialchars($op, ENT_QUOTES, 'UTF-8') . '" class="btn ' . htmlspecialchars($class, ENT_QUOTES, 'UTF-8') . ' btn-sm"'
            . ($requires !== '' ? ' data-bulk-requires="' . htmlspecialchars($requires, ENT_QUOTES, 'UTF-8') . '"' : '')
            . ' data-bulk-confirm="' . htmlspecialchars($confirm, ENT_QUOTES, 'UTF-8') . '"'
            . ' data-admin-confirm="' . htmlspecialchars($confirm, ENT_QUOTES, 'UTF-8') . '"'
            . ' data-admin-confirm-title="' . htmlspecialchars($label, ENT_QUOTES, 'UTF-8') . '"'
            . ' data-admin-confirm-ok="' . htmlspecialchars($confirmOk !== '' ? $confirmOk : $label, ENT_QUOTES, 'UTF-8') . '"'
            . ' data-admin-confirm-tone="' . htmlspecialchars($tone, ENT_QUOTES, 'UTF-8') . '">'
            . '<i class="fas ' . htmlspecialchars($icon, ENT_QUOTES, 'UTF-8') . '" aria-hidden="true"></i> ' . htmlspecialchars($label, ENT_QUOTES, 'UTF-8') . '</button>';
    }

    function adminBulkBarEnd(): void
    {
        echo '</div><button type="button" class="btn btn-secondary btn-sm admin-bulk-bar__clear" data-bulk-clear>Clear</button></form>';
    }

    function adminBulkAllBox(string $label = 'Select all visible rows'): string
    {
        return '<input type="checkbox" class="admin-bulk-check" data-bulk-all aria-label="' . htmlspecialchars($label, ENT_QUOTES, 'UTF-8') . '" title="' . htmlspecialchars($label, ENT_QUOTES, 'UTF-8') . '">';
    }

    function adminBulkRowBox($id, string $label = 'Select row'): string
    {
        return '<input type="checkbox" class="admin-bulk-check" data-bulk-row value="' . (int)$id . '" aria-label="' . htmlspecialchars($label, ENT_QUOTES, 'UTF-8') . '">';
    }
}
