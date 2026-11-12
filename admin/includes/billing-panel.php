<?php
/**
 * Booking Details "Who pays" panel (company pays later / on account, owner 2026-11-13, migration 084).
 * rh_billing_panel_save(): the POST (permission billing_on_account, CSRF checked by the page); rh_billing_panel_render():
 * the panel. Nothing shows until migration 084 has run. Logic lives in includes/billing-accounts.php.
 */
require_once __DIR__ . '/../../includes/billing-accounts.php';

if (!function_exists('rh_billing_panel_save')) {

    /** Handle the "Who pays" form. Sets the flash message; never throws. */
    function rh_billing_panel_save(PDO $pdo, int $bookingId, array $post, array $user): void
    {
        if (!hasPermission((int)($user['id'] ?? 0), 'billing_on_account')) {
            $_SESSION['error_message'] = 'You do not have permission to do that (Put bookings on a company account).';
            return;
        }
        try {
            $res = rh_billing_set_on_booking($pdo, $bookingId, [
                'account_id' => (int)($post['billing_account_id'] ?? 0),
                'reference' => (string)($post['billing_reference'] ?? ''),
                'due_date' => (string)($post['billing_due_date'] ?? ''),
                'covers' => (string)($post['billing_covers'] ?? 'stay'),
            ], $user);
            $_SESSION['success_message'] = empty($res['changed']) ? 'Nothing changed.'
                : ($res['new']['company'] !== 'Guest pays'
                    ? 'This booking is now on ' . $res['new']['company'] . '\'s account. The room stay is billed to the company and is not chased from the guest.'
                    : 'The company account was removed: the guest pays this booking.');
        } catch (Throwable $e) {
            $_SESSION['error_message'] = $e->getMessage();
        }
    }

    /** Print the panel (inside the page container). */
    function rh_billing_panel_render(PDO $pdo, array $booking, array $user, string $csrf): void
    {
        if (!rh_billing_ready($pdo) || empty($booking['id'])) {
            return;
        }
        $e = static fn($v) => htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8');
        $tol = rh_billing_tol();
        $split = rh_booking_bill_split($pdo, (int)$booking['id']);
        $canEdit = hasPermission((int)($user['id'] ?? 0), 'billing_on_account') && empty($booking['deleted_at'])
            && in_array((string)$booking['status'], ['pending', 'tentative', 'confirmed', 'checked-in', 'checked-out'], true);
        $accounts = $canEdit ? rh_billing_accounts($pdo, true) : [];
        $ownId = (int)($booking['billing_account_id'] ?? 0);
        // The booking's own company stays in the list even when it was deactivated (marked "inactive"), so a re-save
        // (e.g. an LPO edit) never drops it.
        if ($canEdit && $ownId > 0 && !in_array($ownId, array_map('intval', array_column($accounts, 'id')), true)) {
            $ownAcc = rh_billing_account($pdo, $ownId);
            if ($ownAcc) {
                $accounts[] = $ownAcc + ['_inactive' => true];
            }
        }
        if (!$split['on_account'] && !$canEdit) {
            return;
        }
        echo '<section class="booking-status-alert booking-status-alert--info bd-billing" id="bd-who-pays" aria-label="Who pays">';
        echo '<i class="fas fa-building" aria-hidden="true"></i><div class="bd-billing__body">';
        if ($split['on_account']) {
            echo '<p class="bd-billing__line"><strong>On account - ' . $e($split['name']) . '</strong>'
                . ($split['reference'] !== '' ? ' &middot; LPO ' . $e($split['reference']) : '')
                . ($split['due_date'] !== '' ? ' &middot; due ' . $e(date('j M Y', strtotime($split['due_date']))) . ($split['days_past_due'] > 0 ? ' (' . (int)$split['days_past_due'] . ' days late)' : '') : '')
                . (!empty($booking['primary_booking_id']) && $ownId <= 0 ? ' &middot; set on the group\'s main booking' : '') . '</p>';
            echo '<p class="bd-billing__line">'
                . 'Company owes <strong>' . $e(rh_billing_money((float)$split['company_due'])) . '</strong> &middot; guest owes <strong>'
                . $e(rh_billing_money((float)$split['guest_due'])) . '</strong> &middot; extras paid by ' . ($split['covers'] === 'everything' ? 'the company' : 'the guest')
                . ' <i class="help" data-tip="Company owes: the room stay' . ($split['covers'] === 'everything' ? ' and the extras' : '') . ' less what the company has paid. Guest owes: the extras the company does not cover, less what the guest paid.">?</i></p>';
            echo '<p class="bd-arrangement__help bd-billing__line">The company share is never chased from the guest and does not block checkout. Invoices and statements go to '
                . ($split['billing_email'] !== '' ? $e($split['billing_email']) : '<strong>no billing email yet - add one on Company accounts</strong>') . '.</p>';
            // The guest's own extras (company pays the stay only): their extras-only document (owner 2026-11-13).
            if ($split['covers'] !== 'everything' && (float)$split['extras'] > $tol) {
                echo '<form method="POST" class="bd-billing__line" target="_blank">'
                    . '<input type="hidden" name="csrf_token" value="' . $e($csrf) . '">'
                    . '<button type="submit" name="invoice_action" value="guest_extras_invoice" class="rh-mini-link"><i class="fas fa-file-invoice" aria-hidden="true"></i> Guest extras invoice</button>'
                    . (trim((string)($booking['guest_email'] ?? '')) !== ''
                        ? ' <button type="submit" name="invoice_action" value="guest_extras_email" class="rh-mini-link" formtarget="_self"><i class="fas fa-envelope" aria-hidden="true"></i> Email it to the guest</button>' : '')
                    . '</form>';
            }
        } else {
            echo '<p class="bd-billing__line"><strong>Who pays:</strong> the guest. Put the booking on a company account when a company pays later (LPO).</p>';
        }
        if ($canEdit && !(!empty($booking['primary_booking_id']) && $ownId <= 0 && $split['on_account'])) {
            echo '<form method="POST" class="bd-arrangement bd-billing__form">';
            echo '<input type="hidden" name="csrf_token" value="' . $e($csrf) . '"><input type="hidden" name="billing_save" value="1">';
            echo '<label class="bd-arrangement__note"><span>Who pays</span><select name="billing_account_id">';
            echo '<option value="0"' . ($ownId <= 0 ? ' selected' : '') . '>The guest</option>';
            foreach ($accounts as $a) {
                echo '<option value="' . (int)$a['id'] . '"' . ((int)$a['id'] === $ownId ? ' selected' : '') . '>' . $e($a['name']) . (!empty($a['_inactive']) ? ' (inactive)' : '') . '</option>';
            }
            echo '</select></label>';
            echo '<label class="bd-arrangement__note"><span>LPO / order no.</span><input type="text" name="billing_reference" maxlength="80" value="' . $e($booking['billing_reference'] ?? '') . '" placeholder="e.g. LPO 12345"></label>';
            echo '<label class="bd-arrangement__note"><span>Due date (empty = company terms)</span><input type="date" name="billing_due_date" value="' . $e($booking['billing_due_date'] ?? '') . '"></label>';
            echo '<label class="bd-arrangement__note"><span>Extras (bar, room service)</span><select name="billing_covers">'
                . '<option value="stay"' . ((string)($booking['billing_covers'] ?? '') !== 'everything' ? ' selected' : '') . '>Guest pays the extras</option>'
                . '<option value="everything"' . ((string)($booking['billing_covers'] ?? '') === 'everything' ? ' selected' : '') . '>Company pays the extras too</option></select></label>';
            echo '<button type="submit" class="action-btn confirm"><i class="fas fa-floppy-disk"></i> Save</button>';
            if (!$accounts) {
                echo '<span class="bd-arrangement__help">No companies yet: add one on <a href="billing-accounts.php">Company accounts</a>.</span>';
            } else {
                echo '<a class="rh-mini-link bd-billing__link" href="billing-accounts.php"><i class="fas fa-building" aria-hidden="true"></i> Company accounts</a>';
            }
            echo '</form>';
        }
        echo '</div></section>';
    }
}
