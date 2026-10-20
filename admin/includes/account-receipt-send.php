<?php

/**
 * "Send receipt" action for a room / conference / gym / event account (owner rule, 2026-10-20: a fully paid
 * account is offered its RECEIPT, never an invoice). Print the script once per page, inside #rh-admin-page,
 * then call rhSendAccountReceipt('room', 12, 'RH-2026-0012') from a button.
 *
 * One payment: asks to re-send that receipt. Several payments: a short list, latest first and pre-selected.
 * The send itself is admin/ajax-receipt.php (action email_guest), which re-sends to the address on the account.
 */
if (!function_exists('rh_render_send_receipt_script')) {
    function rh_render_send_receipt_script(string $csrfToken): void
    {
        ?>
    <script>
        (function () {
            var csrf = <?php echo json_encode($csrfToken); ?>;
            function esc(s) { var d = document.createElement('div'); d.textContent = String(s == null ? '' : s); return d.innerHTML; }
            function say(msg, type) {
                if (window.Alert && typeof window.Alert.show === 'function') { window.Alert.show(msg, type); } else { window.alert(msg); }
            }
            function post(params) {
                var fd = new FormData();
                fd.append('csrf_token', csrf);
                Object.keys(params).forEach(function (k) { fd.append(k, params[k]); });
                return fetch('ajax-receipt.php', { method: 'POST', body: fd, credentials: 'same-origin' }).then(function (r) { return r.json(); });
            }
            function closeBox() { var m = document.getElementById('rhSendReceiptBox'); if (m) { m.remove(); } }
            window.rhSendAccountReceipt = function (type, id, ref) {
                return post({ action: 'account_receipts', booking_type: type, booking_id: id }).then(function (j) {
                    if (!j.ok) { say(j.error || 'Could not load the receipts.', 'error'); return; }
                    var list = j.payments || [];
                    if (!list.length) { say('No payment has been received on this booking yet, so there is no receipt to send.', 'error'); return; }
                    closeBox();
                    var rows = list.map(function (p, i) {
                        return '<label style="display:flex;gap:8px;align-items:flex-start;padding:8px 0;border-bottom:1px solid #eee;cursor:pointer;">' +
                            '<input type="radio" name="rhRcpt" value="' + esc(p.id) + '"' + (i === 0 ? ' checked' : '') + ' style="margin-top:3px;"> ' +
                            '<span><strong>' + esc(p.receipt_number || p.payment_reference) + '</strong> &middot; ' + esc(Number(p.total_amount).toLocaleString(undefined, { minimumFractionDigits: 2, maximumFractionDigits: 2 })) +
                            ' &middot; ' + esc(p.payment_date) + ' &middot; ' + esc(String(p.payment_method || '').replace(/_/g, ' ')) +
                            (p.receipt_emailed_at ? '<br><small style="color:#6b6258;">Already emailed ' + esc(p.receipt_emailed_at) + '</small>' : '') + '</span></label>';
                    }).join('');
                    var box = document.createElement('div');
                    box.id = 'rhSendReceiptBox';
                    box.className = 'modal show';
                    box.setAttribute('role', 'dialog');
                    box.innerHTML = '<div class="modal-content" style="max-width:min(96vw,32rem);width:min(96vw,32rem);">' +
                        '<div class="modal-header"><h3><i class="fas fa-receipt"></i> Send receipt</h3><button type="button" class="modal-close" aria-label="Close" id="rhSrClose">&times;</button></div>' +
                        '<div class="modal-body"><p style="margin:0 0 8px;">' + (list.length > 1 ? 'Choose the receipt to email for ' : 'Email the receipt for ') + '<strong>' + esc(ref) + '</strong> to the guest.</p>' + rows + '</div>' +
                        '<div class="modal-footer"><button type="button" class="btn btn-secondary" id="rhSrCancel">Cancel</button> <button type="button" class="btn btn-primary" id="rhSrSend"><i class="fas fa-paper-plane"></i> Send receipt</button></div></div>';
                    (document.getElementById('rh-admin-page') || document.body).appendChild(box);
                    document.getElementById('rhSrClose').onclick = closeBox;
                    document.getElementById('rhSrCancel').onclick = closeBox;
                    document.getElementById('rhSrSend').onclick = function () {
                        var pick = box.querySelector('input[name="rhRcpt"]:checked');
                        if (!pick) { return; }
                        this.disabled = true;
                        post({ action: 'email_guest', payment_id: pick.value }).then(function (r) {
                            closeBox();
                            say(r.ok ? (r.message || 'Receipt emailed.') : (r.error || 'The receipt could not be sent.'), r.ok ? 'success' : 'error');
                        }).catch(function () { closeBox(); say('The receipt could not be sent. Please try again.', 'error'); });
                    };
                }).catch(function () { say('Could not load the receipts. Please try again.', 'error'); });
            };
        })();
    </script>
        <?php
    }
}
