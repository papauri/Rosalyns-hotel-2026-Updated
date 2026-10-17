<?php

/**
 * Shared "Record payment" for conference / event / gym accounts (the three receivable
 * inquiry tables). Replaces the three copies of the old "Paid / Record Payment & Send Invoice"
 * code that always booked the whole balance as CASH.
 *
 * Rules (owner-approved, Oct 2026):
 *   - staff choose the method and the amount; the amount defaults to the balance;
 *   - an amount above the balance is refused unless staff tick "deliberate overpayment" - the
 *     excess then becomes a credit note through rh_auto_credit_overpayment() (existing rule);
 *   - the paid-to-date is re-read from the ledger INSIDE the transaction while the account row
 *     (and its payment rows) are locked FOR UPDATE, so two clicks / two staff can never both
 *     collect the same balance; an optional client_uuid makes a retried partial payment safe too;
 *   - VAT is locked on the account only when it was never locked (a historical invoice keeps its
 *     rate), and the payment's VAT is split pro rata to the account's own VAT / gross ratio.
 */

require_once __DIR__ . '/finance-account-sync.php';
require_once __DIR__ . '/finance-schema.php';
require_once __DIR__ . '/../../includes/finance-sequences.php';
require_once __DIR__ . '/../../includes/idempotency.php';
require_once __DIR__ . '/../../config/credit-notes.php';

if (!function_exists('rh_account_payment_methods')) {
    /** Payment methods offered when recording a payment (same list as the Payments module). */
    function rh_account_payment_methods(): array
    {
        return [
            'cash'          => 'Cash',
            'mobile_money'  => 'Mobile Money',
            'credit_card'   => 'Credit Card',
            'debit_card'    => 'Debit Card',
            'bank_transfer' => 'Bank Transfer',
            'cheque'        => 'Cheque',
            'other'         => 'Other',
        ];
    }
}

if (!function_exists('rh_record_account_payment')) {
    /**
     * Record one payment against a conference / event / gym account.
     *
     * @param string $type  'conference' | 'event' | 'gym'
     * @param array  $in    amount (gross, '' = the balance), method, transaction_reference, notes,
     *                      confirm_overpay (bool), client_uuid
     * @return array{payment_id:int,payment_reference:string,amount:float,balance_before:float,balance_after:float,
     *               excess:float,credit_note_id:int,credit_note_number:string,email:string,duplicate:bool}
     * @throws Exception with a staff-readable message (nothing is saved)
     */
    function rh_record_account_payment(PDO $pdo, string $type, int $id, array $in, int $userId, string $userName): array
    {
        $tables = ['conference' => 'conference_inquiries', 'event' => 'event_inquiries', 'gym' => 'gym_inquiries'];
        if (!isset($tables[$type]) || $id <= 0) {
            throw new Exception('Invalid account selected.');
        }
        $table = $tables[$type];
        $tol = defined('BALANCE_TOLERANCE') ? (float)BALANCE_TOLERANCE : 0.01;

        $method = (string)($in['method'] ?? '');
        if (!isset(rh_account_payment_methods()[$method])) {
            throw new Exception('Choose how the payment was made (cash, card, mobile money...).');
        }
        $amountRaw = trim((string)($in['amount'] ?? ''));
        $explicitAmount = $amountRaw !== '';
        if ($explicitAmount && (!is_numeric($amountRaw) || (float)$amountRaw <= 0 || (float)$amountRaw > 99999999)) {
            throw new Exception('Payment amount must be a number greater than zero.');
        }
        $txnRef = mb_substr(trim((string)($in['transaction_reference'] ?? '')), 0, 100);
        $notes = trim((string)($in['notes'] ?? ''));
        $confirmOverpay = !empty($in['confirm_overpay']);
        $clientUuid = idem_normalize_uuid($in['client_uuid'] ?? null);
        $txnColumn = finance_payment_transaction_column($pdo);

        $ownTx = !$pdo->inTransaction();
        if ($ownTx) {
            $pdo->beginTransaction();
        }
        try {
            // Lock the account, then its payment rows: a concurrent record-payment waits here and then
            // sees this payment in the paid total.
            $sel = $pdo->prepare("SELECT * FROM {$table} WHERE id = ? FOR UPDATE");
            $sel->execute([$id]);
            $row = $sel->fetch(PDO::FETCH_ASSOC);
            if (!$row) {
                throw new Exception('Account not found.');
            }
            $pdo->prepare("SELECT id FROM payments WHERE booking_type = ? AND booking_id = ? AND deleted_at IS NULL FOR UPDATE")
                ->execute([$type, $id]);

            if ($clientUuid !== null && ($existing = idem_find_existing_payment($pdo, $clientUuid))) {
                if ($ownTx && $pdo->inTransaction()) {
                    $pdo->rollBack();
                }
                $dupGross = $pdo->prepare("SELECT total_amount FROM payments WHERE id = ?");
                $dupGross->execute([(int)$existing['id']]);
                return [
                    'payment_id' => (int)$existing['id'], 'payment_reference' => (string)$existing['payment_reference'],
                    'amount' => (float)$dupGross->fetchColumn(), 'balance_before' => 0.0, 'balance_after' => 0.0,
                    'excess' => 0.0, 'credit_note_id' => 0, 'credit_note_number' => '', 'email' => '', 'duplicate' => true,
                ];
            }

            $status = (string)($row['status'] ?? '');
            if ($type === 'conference' && !in_array($status, ['confirmed', 'completed'], true)) {
                throw new Exception('Payment can only be recorded for confirmed or completed bookings.');
            }
            if ($type === 'event' && in_array($status, ['cancelled', 'waitlisted'], true)) {
                throw new Exception('Payment cannot be recorded for a ' . $status . ' booking.');
            }
            if ($type === 'gym' && $status === 'cancelled') {
                throw new Exception('Payment cannot be recorded for a cancelled booking.');
            }
            if ((float)($row['total_amount'] ?? 0) <= $tol) {
                throw new Exception('Set a total amount before recording payment.');
            }

            // Lock the invoiced VAT figures only when they were never locked.
            if ((float)($row['total_with_vat'] ?? 0) <= 0.001) {
                $vp = vat_components((float)$row['total_amount']);
                $pdo->prepare("UPDATE {$table} SET vat_rate = ?, vat_amount = ?, total_with_vat = ? WHERE id = ?")
                    ->execute([$vp['rate'], $vp['vat'], $vp['total'], $id]);
                $row['vat_rate'] = $vp['rate'];
                $row['vat_amount'] = $vp['vat'];
                $row['total_with_vat'] = $vp['total'];
            }
            $gross = rh_account_gross_total($row);
            $paidBefore = rh_sum_account_paid($pdo, $type, $id);   // re-read under the lock
            $balance = max(0.0, round($gross - $paidBefore, 2));

            if (!$explicitAmount && $balance <= $tol) {
                throw new Exception('Nothing is outstanding on this account - it is already paid in full.');
            }
            $amount = $explicitAmount ? round((float)$amountRaw, 2) : $balance;
            $excess = round($amount - $balance, 2);
            if ($excess > $tol && !$confirmOverpay) {
                throw new Exception('That amount is ' . number_format($excess, 2) . ' more than the balance of ' . number_format($balance, 2)
                    . '. Tick "Deliberate overpayment" to record it - the excess becomes a credit note.');
            }

            $split = rh_account_vat_split($pdo, $type, $id, $amount, (float)($row['vat_rate'] ?? 0));

            do {
                $paymentRef = 'PAY' . date('Ym') . strtoupper(substr(uniqid(), -6));
                $refChk = $pdo->prepare('SELECT COUNT(*) FROM payments WHERE payment_reference = ? LIMIT 1');
                $refChk->execute([$paymentRef]);
            } while ((int)$refChk->fetchColumn() > 0);
            $receiptNumber = finance_next_receipt_number($pdo, date('Y-m-d'));

            $bookingRef = (string)($row['reference_number'] ?? $row['inquiry_reference'] ?? '');
            $ins = $pdo->prepare("
                INSERT INTO payments (
                    payment_reference, booking_type, booking_id, booking_reference,
                    payment_date, payment_amount, vat_rate, vat_amount, total_amount,
                    payment_method, payment_type, payment_status, invoice_generated,
                    receipt_number, status, recorded_by, processed_by, notes, {$txnColumn}, client_uuid
                ) VALUES (?, ?, ?, ?, CURDATE(), ?, ?, ?, ?, ?, ?, 'completed', 0, ?, 'completed', ?, ?, ?, ?, ?)
            ");
            $ins->execute([
                $paymentRef, $type, $id, $bookingRef,
                $split['net'], $split['rate'], $split['vat'], $amount,
                $method, ($amount + $tol >= $balance ? 'full_payment' : 'partial_payment'),
                $receiptNumber, $userId > 0 ? $userId : null, $userName !== '' ? $userName : null,
                $notes !== '' ? $notes : null, $txnRef !== '' ? $txnRef : null, $clientUuid,
            ]);
            $paymentId = (int)$pdo->lastInsertId();

            if (!rh_sync_account_payments($pdo, $type, $id)) {
                throw new Exception('Could not recalculate the account balance.');
            }

            // Deliberate overpayment: the excess lives only in a credit note (existing rule).
            $cn = null;
            if ($excess > $tol) {
                $cn = rh_auto_credit_overpayment($pdo, $type, $id, $excess, $paymentRef, $userId);
            }

            $after = rh_sum_account_paid($pdo, $type, $id);
            if ($ownTx) {
                $pdo->commit();
            }
            return [
                'payment_id' => $paymentId, 'payment_reference' => $paymentRef, 'amount' => $amount,
                'balance_before' => $balance, 'balance_after' => max(0.0, round($gross - $after, 2)),
                'excess' => $cn ? (float)$cn['excess'] : 0.0,
                'credit_note_id' => $cn ? (int)$cn['credit_note_id'] : 0,
                'credit_note_number' => $cn ? (string)$cn['credit_note_number'] : '',
                'email' => (string)($row['email'] ?? ''), 'duplicate' => false,
            ];
        } catch (Throwable $e) {
            if ($ownTx && $pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw ($e instanceof Exception) ? $e : new Exception($e->getMessage(), 0, $e);
        }
    }
}

if (!function_exists('rh_account_payment_input_from_post')) {
    /** Map the shared modal's POST fields onto rh_record_account_payment()'s input array. */
    function rh_account_payment_input_from_post(array $post): array
    {
        return [
            'method'                => (string)($post['payment_method'] ?? ''),
            'amount'                => (string)($post['payment_amount'] ?? ''),
            'transaction_reference' => (string)($post['transaction_reference'] ?? ''),
            'notes'                 => (string)($post['payment_notes'] ?? ''),
            'confirm_overpay'       => !empty($post['confirm_overpay']),
            'client_uuid'           => $post['client_uuid'] ?? null,
        ];
    }
}

if (!function_exists('rh_account_after_payment_messages')) {
    /**
     * After a successful rh_record_account_payment(): send the receipt (best effort), email the
     * invoice only when asked AND there is an address, and build a message that never claims an
     * email that was not actually sent. $invoiceSender is the page's sendXInvoiceEmail callable.
     */
    function rh_account_after_payment_messages(PDO $pdo, array $res, bool $wantInvoice, callable $invoiceSender, int $accountId, array $user, string $currency): string
    {
        if (!empty($res['duplicate'])) {
            return 'This payment was already recorded (' . $res['payment_reference'] . '). Duplicate submission ignored.';
        }
        $msg = 'Payment ' . $res['payment_reference'] . ' of ' . $currency . number_format($res['amount'], 2) . ' recorded.';
        if ($res['balance_after'] > BALANCE_TOLERANCE) {
            $msg .= ' Balance still due: ' . $currency . number_format($res['balance_after'], 2) . '.';
        } else {
            $msg .= ' The account is paid in full.';
        }
        if ($res['credit_note_number'] !== '') {
            $msg .= ' Overpayment of ' . $currency . number_format($res['excess'], 2) . ' issued as credit note ' . $res['credit_note_number'] . ' (see Credit Notes).';
            if ($res['email'] !== '' && $res['credit_note_id'] > 0) {
                try {
                    sendCreditNoteEmail($pdo, (int)$res['credit_note_id']);
                } catch (Throwable $e) {
                    error_log('Overpayment credit note email failed: ' . $e->getMessage());
                }
            }
        }
        try {
            require_once __DIR__ . '/../../config/receipts.php';
            receipt_auto_send($pdo, (int)$res['payment_id'], $user);
        } catch (Throwable $e) {
            error_log('Receipt email failed for payment ' . (int)$res['payment_id'] . ': ' . $e->getMessage());
        }
        if ($wantInvoice) {
            if ($res['email'] === '' || !filter_var($res['email'], FILTER_VALIDATE_EMAIL)) {
                $msg .= ' No valid email address is on file, so no invoice was sent.';
            } else {
                $inv = $invoiceSender($accountId);
                if (!empty($inv['success'])) {
                    $isPreview = stripos((string)($inv['message'] ?? ''), 'preview') !== false;
                    $msg .= $isPreview
                        ? ' Invoice generated (development mode: preview only, no live email sent).'
                        : ' Invoice emailed to ' . htmlspecialchars($res['email']) . '.';
                } else {
                    $msg .= ' The invoice email could NOT be sent: ' . htmlspecialchars((string)($inv['message'] ?? 'unknown error')) . '.';
                }
            }
        }
        return $msg;
    }
}

if (!function_exists('rh_render_record_payment_modal')) {
    /**
     * Print the shared "Record payment" modal and its opener script. Call it inside #rh-admin-page,
     * before includes/admin-footer.php. The page's form posts $actionField=record_payment and
     * $idField=<account id>. Open it with rhOpenRecordPayment({id, ref, name, email, balance, paid, total}).
     */
    function rh_render_record_payment_modal(string $csrfToken, string $actionField, string $idField, string $currencySymbol): void
    {
        $e = static function ($v) { return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8'); };
        ?>
    <div id="rhRecordPaymentModal" class="modal" role="dialog" aria-modal="true" aria-label="Record payment">
        <div class="modal-content" style="max-width:min(96vw,34rem);width:min(96vw,34rem);">
            <div class="modal-header">
                <h3><i class="fas fa-cash-register"></i> Record payment</h3>
                <button type="button" class="modal-close" onclick="rhCloseRecordPayment()" aria-label="Close">&times;</button>
            </div>
            <form method="POST" id="rhRecordPaymentForm">
                <div class="modal-body">
                    <input type="hidden" name="csrf_token" value="<?php echo $e($csrfToken); ?>">
                    <input type="hidden" name="<?php echo $e($actionField); ?>" value="record_payment">
                    <input type="hidden" name="<?php echo $e($idField); ?>" id="rhRpId" value="">
                    <input type="hidden" name="client_uuid" id="rhRpUuid" value="">
                    <p id="rhRpSummary" style="margin:0 0 12px;font-size:.92rem;line-height:1.5;"></p>
                    <div class="form-group" style="margin-bottom:12px;">
                        <label for="rhRpMethod" style="display:block;font-weight:600;margin-bottom:4px;">Payment method *</label>
                        <select name="payment_method" id="rhRpMethod" class="form-control" required>
                            <option value="">Choose method...</option>
                            <?php foreach (rh_account_payment_methods() as $mv => $ml): ?>
                                <option value="<?php echo $e($mv); ?>"><?php echo $e($ml); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="form-group" style="margin-bottom:12px;">
                        <label for="rhRpAmount" style="display:block;font-weight:600;margin-bottom:4px;">Amount received (<?php echo $e($currencySymbol); ?>, gross) *</label>
                        <input type="number" step="0.01" min="0.01" name="payment_amount" id="rhRpAmount" class="form-control" required>
                        <small style="color:#6b6258;">Defaults to the balance. Enter less for a part-payment.</small>
                    </div>
                    <div class="form-group" id="rhRpOverWrap" style="margin-bottom:12px;display:none;">
                        <label style="display:flex;gap:8px;align-items:flex-start;cursor:pointer;color:#8a4b00;">
                            <input type="checkbox" name="confirm_overpay" value="1" id="rhRpOver" style="margin-top:3px;">
                            <span>Deliberate overpayment: this is more than the balance. The excess is issued as a credit note.</span>
                        </label>
                    </div>
                    <div class="form-group" style="margin-bottom:12px;">
                        <label for="rhRpTxn" style="display:block;font-weight:600;margin-bottom:4px;">Transaction reference</label>
                        <input type="text" name="transaction_reference" id="rhRpTxn" class="form-control" maxlength="100" placeholder="Bank ref, cheque no., mobile money transaction ID...">
                    </div>
                    <div class="form-group" style="margin-bottom:12px;">
                        <label for="rhRpNotes" style="display:block;font-weight:600;margin-bottom:4px;">Notes</label>
                        <textarea name="payment_notes" id="rhRpNotes" class="form-control" rows="2" maxlength="500"></textarea>
                    </div>
                    <label style="display:flex;gap:8px;align-items:center;cursor:pointer;">
                        <input type="checkbox" name="email_invoice" value="1" id="rhRpInvoice" checked>
                        <span id="rhRpInvoiceLabel">Also email the invoice to the customer</span>
                    </label>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" onclick="rhCloseRecordPayment()">Cancel</button>
                    <button type="submit" class="btn btn-primary" id="rhRpSubmit"><i class="fas fa-check"></i> Record payment</button>
                </div>
            </form>
        </div>
    </div>
    <script>
        (function () {
            var cur = <?php echo json_encode($currencySymbol); ?>;
            var balance = 0;
            function fmt(n) { return cur + ' ' + Number(n || 0).toLocaleString(undefined, { minimumFractionDigits: 2, maximumFractionDigits: 2 }); }
            function esc(s) { var d = document.createElement('div'); d.textContent = String(s == null ? '' : s); return d.innerHTML; }
            function newUuid() {
                if (window.crypto && crypto.randomUUID) { return crypto.randomUUID(); }
                return 'rp-' + Date.now().toString(36) + '-' + Math.random().toString(36).slice(2, 12);
            }
            function syncOver() {
                var amt = parseFloat(document.getElementById('rhRpAmount').value || '0');
                var over = amt > balance + 0.009;
                document.getElementById('rhRpOverWrap').style.display = over ? '' : 'none';
                if (!over) { document.getElementById('rhRpOver').checked = false; }
            }
            window.rhOpenRecordPayment = function (a) {
                balance = Number(a.balance || 0);
                document.getElementById('rhRpId').value = a.id;
                document.getElementById('rhRpUuid').value = newUuid();
                document.getElementById('rhRpSummary').innerHTML = '<strong>' + esc(a.ref) + '</strong> &middot; ' + esc(a.name) +
                    '<br>Total ' + fmt(a.total) + ' &middot; Paid ' + fmt(a.paid) + ' &middot; <strong>Balance ' + fmt(balance) + '</strong>';
                var amt = document.getElementById('rhRpAmount');
                amt.value = balance > 0.009 ? balance.toFixed(2) : '';
                document.getElementById('rhRpMethod').value = '';
                document.getElementById('rhRpTxn').value = '';
                document.getElementById('rhRpNotes').value = '';
                var hasMail = !!(a.email && String(a.email).indexOf('@') > 0);
                var inv = document.getElementById('rhRpInvoice');
                inv.checked = hasMail;
                inv.disabled = !hasMail;
                document.getElementById('rhRpInvoiceLabel').textContent = hasMail ? 'Also email the invoice to ' + a.email : 'No email address on file - no invoice can be sent';
                document.getElementById('rhRpSubmit').disabled = false;
                syncOver();
                document.getElementById('rhRecordPaymentModal').classList.add('show');
                setTimeout(function () { document.getElementById('rhRpMethod').focus(); }, 60);
            };
            window.rhCloseRecordPayment = function () { document.getElementById('rhRecordPaymentModal').classList.remove('show'); };
            document.getElementById('rhRpAmount').addEventListener('input', syncOver);
            document.getElementById('rhRecordPaymentForm').addEventListener('submit', function () {
                document.getElementById('rhRpSubmit').disabled = true; // one click, one payment
            });
            document.getElementById('rhRecordPaymentModal').addEventListener('click', function (ev) { if (ev.target === this) { window.rhCloseRecordPayment(); } });
            document.addEventListener('keydown', function (ev) { if (ev.key === 'Escape') { window.rhCloseRecordPayment(); } });
        })();
    </script>
        <?php
    }
}
