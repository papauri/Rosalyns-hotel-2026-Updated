<?php
/**
 * Payments / Accounting > Add charge (group bookings Ship B2, owner D-R4b 2026-11-17).
 * Add an extra charge (late checkout fee, damages, extras) to a room booking or its whole group, with a reason:
 * preview (per room: owed now -> owed after) -> confirm. Rules and money: admin/includes/charge-add.php.
 * Page permission: edit_booking (getPermissionForPage). Checked-out rooms also need correct_stay_after_checkout.
 */
require_once 'admin-init.php';
/** @var string $csrf_token */
require_once __DIR__ . '/includes/charge-add.php';

$user = [
    'id' => (int)$_SESSION['admin_user_id'],
    'username' => (string)($_SESSION['admin_username'] ?? ''),
    'role' => (string)($_SESSION['admin_role'] ?? ''),
    'full_name' => (string)($_SESSION['admin_full_name'] ?? ''),
];
$site_name = getSetting('site_name');
$currency_symbol = (string)getSetting('currency_symbol', 'MWK');
$csrf_token = $csrf_token ?? generateCsrfToken();
$gcaCanEdit = hasPermission($user['id'], 'edit_booking');
$gcaCanCorrect = hasPermission($user['id'], 'correct_stay_after_checkout');

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store');
    $gcaJson = static function (array $out, int $code = 200): void {
        http_response_code($code);
        echo json_encode($out);
        exit;
    };
    if (!validateCsrfToken($_POST['csrf_token'] ?? '')) {
        $gcaJson(['success' => false, 'message' => 'Your session expired. Reload the page and try again. Nothing was added.'], 400);
    }
    if (!$gcaCanEdit) {
        $gcaJson(['success' => false, 'message' => 'You do not have permission to add charges (Edit Booking).'], 403);
    }
    if (!(function_exists('moduleEnabled') && moduleEnabled('bookings'))) {
        $gcaJson(['success' => false, 'message' => 'Room bookings are switched off.'], 403);
    }
    $gcaIn = rh_charge_add_input($_POST);
    if ($gcaIn['error'] !== '') {
        $gcaJson(['success' => false, 'message' => $gcaIn['error']]);
    }
    $in = $gcaIn['in'];
    if (!isset($_SESSION['rh_gca_nonce']) || !is_array($_SESSION['rh_gca_nonce'])) {
        $_SESSION['rh_gca_nonce'] = [];
    }
    $action = (string)($_POST['gca_action'] ?? '');
    $money = static fn(float $v): string => $currency_symbol . ' ' . number_format($v, 2);
    try {
        if ($action === 'preview') {
            $p = rh_charge_add_preview($pdo, $in, $gcaCanCorrect);
            if (empty($p['success'])) {
                $gcaJson(['success' => false, 'message' => (string)$p['message']]);
            }
            $rows = [];
            foreach ($p['rows'] as $r) {
                $rows[] = ['ref' => $r['ref'], 'room' => $r['room'], 'guest' => $r['guest'], 'status' => $r['status'], 'checked_out' => !empty($r['checked_out']),
                    'description' => $r['description'], 'amount_text' => $money((float)$r['amount']),
                    'owed_now_text' => $money((float)$r['owed_now']), 'owed_after_text' => $money((float)$r['owed_after'])];
            }
            $gcaJson(['success' => true, 'rows' => $rows, 'skipped' => $p['skipped'], 'total_text' => $money((float)$p['total']),
                'sig' => $p['sig'], 'nonce' => rh_charge_add_nonce_issue($_SESSION['rh_gca_nonce'], (int)$in['booking_id'], rh_charge_add_bind($in, (string)$p['sig']))]);
        }
        if ($action === 'confirm') {
            $sig = trim((string)($_POST['sig'] ?? ''));
            // The token is used up here, before anything is posted, whatever happens next.
            $refuse = $sig === '' ? 'Check the preview first, then confirm. Nothing was added.'
                : rh_charge_add_nonce_take($_SESSION['rh_gca_nonce'], (int)$in['booking_id'], trim((string)($_POST['nonce'] ?? '')), rh_charge_add_bind($in, $sig));
            if ($refuse !== '') {
                $gcaJson(['success' => false, 'message' => $refuse]);
            }
            $res = rh_charge_add_post($pdo, $in, $user, $gcaCanCorrect, $sig);
            $gcaJson(['success' => !empty($res['success']),
                'message' => !empty($res['success']) ? $res['message'] : 'Nothing was added: ' . $res['message'],
                'invoice_notice' => (string)$res['invoice_notice'], 'booking_id' => (int)$in['booking_id']]);
        }
        $gcaJson(['success' => false, 'message' => 'Unknown action.'], 400);
    } catch (Throwable $e) {
        error_log('charge-add.php: ' . $e->getMessage());
        $gcaJson(['success' => false, 'message' => 'Something went wrong. Nothing was added.'], 500);
    }
}

$gcaCanSearch = hasAnyPermission($user['id'], ['payment_add', 'payments', 'edit_booking']);
$gcaTypes = rh_charge_add_types();
?>
<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Add charge | <?php echo htmlspecialchars((string)$site_name); ?> Admin</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Cormorant+Garamond:ital,wght@0,300;0,400;0,500;0,600;1,300;1,400;1,500&family=Jost:wght@300;400;500;600&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="css/admin-styles.css?v=<?php echo @filemtime(__DIR__ . '/css/admin-styles.css'); ?>">
    <link rel="stylesheet" href="css/admin-components.css?v=<?php echo @filemtime(__DIR__ . '/css/admin-components.css'); ?>">
    <link rel="stylesheet" href="css/admin-finance.css?v=<?php echo @filemtime(__DIR__ . '/css/admin-finance.css'); ?>">
    <link rel="stylesheet" href="css/payment-add.css?v=<?php echo @filemtime(__DIR__ . '/css/payment-add.css'); ?>">
</head>

<body>

    <?php require_once 'includes/admin-header.php'; ?>

    <div class="admin-container payment-add-container" id="gca-root"
        data-csrf="<?php echo htmlspecialchars($csrf_token, ENT_QUOTES, 'UTF-8'); ?>"
        data-can-correct="<?php echo $gcaCanCorrect ? '1' : '0'; ?>">
        <style>
            #gca-root .gca-btn { min-height: 44px; min-width: 44px; }
            #gca-root .booking-search-item { min-height: 44px; width: 100%; text-align: left; background: none; border: 0; border-bottom: 1px solid var(--finance-border, #e5e0d8); padding: .6rem .8rem; cursor: pointer; display: block; }
            #gca-root .booking-search-item:hover, #gca-root .booking-search-item:focus-visible { background: var(--finance-surface-alt, #f7f4ef); }
            #gca-root .gca-sub { display: block; font-size: .82rem; color: var(--finance-muted, #6b6156); }
            #gca-root .gca-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); gap: .9rem; }
            #gca-root .gca-field { display: flex; flex-direction: column; gap: .3rem; }
            #gca-root .gca-field .pa-input, #gca-root .gca-field .pa-textarea, #gca-root .gca-field select { min-height: 44px; }
            #gca-root .gca-actions { display: flex; gap: .6rem; flex-wrap: wrap; margin-top: 1rem; }
            #gca-root .gca-co { display: inline-block; font-size: .75rem; padding: .1rem .45rem; border-radius: 999px; background: var(--finance-warning-bg, #fff8e6); color: var(--finance-warning, #8a5a00); border: 1px solid var(--finance-warning-border, #e8c98a); }
            #gca-root .gca-msg { margin-top: 1rem; padding: .8rem 1rem; border-radius: 8px; border: 1px solid var(--finance-border, #e5e0d8); }
            #gca-root .gca-msg--error { background: #fdecec; border-color: #f2b8b5; color: #8a1c14; }
            #gca-root .gca-msg--ok { background: #eaf6ee; border-color: #b7dfc2; color: #1d5f33; }
            #gca-root .acct-table td, #gca-root .acct-table th { vertical-align: top; }
            @media (max-width: 640px) {
                #gca-root .acct-table thead { display: none; }
                #gca-root .acct-table tr { display: block; border-bottom: 1px solid var(--finance-border, #e5e0d8); padding: .4rem 0; }
                #gca-root .acct-table td { display: flex; justify-content: space-between; gap: .8rem; border: 0; padding: .2rem .4rem; }
                #gca-root .acct-table td::before { content: attr(data-label); font-weight: 600; color: var(--finance-muted, #6b6156); }
                #gca-root .gca-actions .gca-btn { flex: 1 1 100%; }
            }
        </style>

        <div class="payment-workspace__hero">
            <div>
                <p class="payment-workspace__subtitle">Add an extra charge to a room booking (late checkout fee, damages, extras). You see what each room will owe before anything is saved.</p>
            </div>
            <div class="payment-workspace__hero-actions">
                <a href="payments.php" class="tbl-btn tbl-btn--view gca-btn"><i class="fas fa-arrow-left"></i> Back to Payments</a>
            </div>
        </div>

        <?php if (!$gcaCanSearch): ?>
            <p class="gca-msg gca-msg--error">You need the Payments or Edit Booking permission to find bookings here.</p>
        <?php endif; ?>

        <div class="payment-console">
            <div class="payment-console__main">

                <!-- Step 1: booking -->
                <div class="payment-panel" id="gca-booking-panel">
                    <div class="payment-panel__header">
                        <div>
                            <span class="payment-panel__kicker">Step 1</span>
                            <h2 class="payment-panel__title"><i class="fas fa-calendar-check"></i> Booking</h2>
                        </div>
                    </div>
                    <div id="gca-picker">
                        <label class="pa-search-label" for="gca-search">
                            <i class="fas fa-search"></i> Find the booking
                            <button type="button" class="wm-help" data-tooltip="Type a booking reference, guest name, phone or email (2 characters or more). Recent bookings and older rooms that still owe are listed until you type." aria-label="Help">?</button>
                        </label>
                        <input type="search" id="gca-search" class="pa-input pa-search-input gca-btn" autocomplete="off" placeholder="Reference, guest name, phone or email">
                        <div id="gca-results" class="booking-search-results" aria-live="polite"></div>
                    </div>
                    <div class="payment-booking-card" id="gca-selected" hidden>
                        <div class="payment-booking-card__summary">
                            <div>
                                <p class="payment-booking-card__type" id="gca-sel-type">Room booking</p>
                                <h3 id="gca-sel-guest"></h3>
                                <p id="gca-sel-line"></p>
                            </div>
                            <div class="payment-booking-card__balance">
                                <span>Owed now <button type="button" class="wm-help" data-tooltip="What this room still owes today: its bill including VAT, levy and extras, minus what has been paid." aria-label="Help">?</button></span>
                                <strong id="gca-sel-owed"></strong>
                            </div>
                        </div>
                        <p class="pa-hint" id="gca-sel-rate"></p>
                        <div class="gca-actions">
                            <button type="button" class="tbl-btn tbl-btn--view gca-btn" id="gca-change"><i class="fas fa-times"></i> Change booking</button>
                        </div>
                    </div>
                </div>

                <!-- Step 2: the charge -->
                <div class="payment-panel" id="gca-charge-panel" hidden>
                    <div class="payment-panel__header">
                        <div>
                            <span class="payment-panel__kicker">Step 2</span>
                            <h2 class="payment-panel__title"><i class="fas fa-file-invoice"></i> Charge</h2>
                        </div>
                    </div>
                    <fieldset class="rh-choices" id="gca-scope" hidden>
                        <legend>Who is this charge for? <button type="button" class="wm-help" data-tooltip="This room only: one line on this room. Whole group, one line: a single line on the group's lead room, shown on the group invoice. Whole group, split: the amount shared evenly across the rooms that can take it (whole cents, any cent left over on the lead)." aria-label="Help">?</button></legend>
                        <label class="rh-choice"><input type="radio" name="gca_scope" value="room" checked> <span>This room only</span></label>
                        <label class="rh-choice"><input type="radio" name="gca_scope" value="group_line"> <span>Whole group: one line on the lead room</span></label>
                        <label class="rh-choice"><input type="radio" name="gca_scope" value="group_split"> <span>Whole group: split across the rooms</span></label>
                    </fieldset>
                    <div class="gca-grid">
                        <div class="gca-field">
                            <label class="pa-label" for="gca-type">Charge type <button type="button" class="wm-help" data-tooltip="The same list as Add Charge on the booking. Use Service or Custom for a late checkout fee or damages." aria-label="Help">?</button></label>
                            <select id="gca-type" class="pa-input">
                                <?php foreach ($gcaTypes as $tv => $tl): ?>
                                    <option value="<?php echo htmlspecialchars($tv, ENT_QUOTES, 'UTF-8'); ?>"><?php echo htmlspecialchars($tl, ENT_QUOTES, 'UTF-8'); ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="gca-field">
                            <label class="pa-label" for="gca-qty">Quantity <button type="button" class="wm-help" data-tooltip="How many. The line total is quantity times the unit price." aria-label="Help">?</button></label>
                            <input type="number" id="gca-qty" class="pa-input" min="0.01" step="0.01" value="1" inputmode="decimal">
                        </div>
                        <div class="gca-field">
                            <label class="pa-label" for="gca-price">Unit price (<?php echo htmlspecialchars($currency_symbol); ?>, VAT included) <button type="button" class="wm-help" data-tooltip="The price the guest pays, VAT included. VAT is worked out from it as on Add Charge. No tourism levy: a fee or an extra is not a room night. For an extra night, use Correct stay after check-out or Extend stay on the booking instead (booked rate, levy included)." aria-label="Help">?</button></label>
                            <input type="number" id="gca-price" class="pa-input" min="0" step="0.01" inputmode="decimal">
                        </div>
                    </div>
                    <div class="gca-field" style="margin-top:.9rem;">
                        <label class="pa-label" for="gca-desc">Description (prints on the invoice) <button type="button" class="wm-help" data-tooltip="What the guest sees on the folio and the invoice, for example: Late checkout 15:00." aria-label="Help">?</button></label>
                        <input type="text" id="gca-desc" class="pa-input" maxlength="255">
                    </div>
                    <div class="gca-field" style="margin-top:.9rem;">
                        <label class="pa-label" for="gca-reason">Reason (kept in the booking history) <button type="button" class="wm-help" data-tooltip="Why the charge is added. Required. It is not printed on the invoice; it is kept in the booking's History with who added it and what the room owed before and after." aria-label="Help">?</button></label>
                        <textarea id="gca-reason" class="pa-textarea pa-input" rows="2" maxlength="500"></textarea>
                    </div>
                    <div class="gca-actions">
                        <button type="button" class="tbl-btn tbl-btn--confirm gca-btn" id="gca-preview-btn"><i class="fas fa-eye"></i> Preview</button>
                    </div>
                    <div id="gca-form-msg" class="gca-msg gca-msg--error" hidden role="alert"></div>
                </div>

                <!-- Step 3: preview + confirm -->
                <div class="payment-panel" id="gca-preview-panel" hidden>
                    <div class="payment-panel__header">
                        <div>
                            <span class="payment-panel__kicker">Step 3</span>
                            <h2 class="payment-panel__title"><i class="fas fa-list-check"></i> Check and confirm</h2>
                        </div>
                    </div>
                    <p>Nothing has been added yet. Total charge: <strong id="gca-total"></strong></p>
                    <div class="acct-table-wrap">
                        <table class="acct-table" id="gca-table">
                            <thead>
                                <tr>
                                    <th>Room <button type="button" class="wm-help" data-tooltip="The room and booking reference the line goes on, with the guest." aria-label="Help">?</button></th>
                                    <th>Status <button type="button" class="wm-help" data-tooltip="The room's booking status now. Checked out means the guest has left; the charge is added after check-out." aria-label="Help">?</button></th>
                                    <th>Charge <button type="button" class="wm-help" data-tooltip="The line that will be added to this room's folio, VAT included." aria-label="Help">?</button></th>
                                    <th>Owed now <button type="button" class="wm-help" data-tooltip="What the room owes before this charge." aria-label="Help">?</button></th>
                                    <th>Owed after <button type="button" class="wm-help" data-tooltip="What the room will owe after this charge. Credit already on the room is used first." aria-label="Help">?</button></th>
                                </tr>
                            </thead>
                            <tbody id="gca-rows"></tbody>
                        </table>
                    </div>
                    <div id="gca-skipped" hidden>
                        <p style="margin:1rem 0 .4rem;font-weight:600;">Rooms not charged <button type="button" class="wm-help" data-tooltip="Rooms in the group that cannot take this charge, with the reason. They are left as they are." aria-label="Help">?</button></p>
                        <ul id="gca-skipped-list" style="margin:0;padding-left:1.2rem;"></ul>
                    </div>
                    <div class="gca-actions">
                        <button type="button" class="tbl-btn tbl-btn--view gca-btn" id="gca-back">Back</button>
                        <button type="button" class="tbl-btn tbl-btn--confirm gca-btn" id="gca-confirm-btn"><i class="fas fa-check"></i> Confirm and add</button>
                    </div>
                </div>

                <div id="gca-result" class="gca-msg" hidden role="status"></div>
            </div>
        </div>
    </div>

    <script>
    (function () {
        var root = document.getElementById('gca-root');
        if (!root) { return; }
        var csrf = root.getAttribute('data-csrf') || '';
        var $ = function (id) { return document.getElementById(id); };
        var state = { booking: null, sig: '', nonce: '', busy: false };
        var money = function (v) { return <?php echo json_encode($currency_symbol . ' '); ?> + Number(v || 0).toLocaleString('en-US', { minimumFractionDigits: 2, maximumFractionDigits: 2 }); };
        var timer = null;

        function el(tag, text, cls) { var e = document.createElement(tag); if (text !== undefined && text !== null) { e.textContent = String(text); } if (cls) { e.className = cls; } return e; }
        function show(id, on) { var e = $(id); if (e) { e.hidden = !on; } }
        function formMsg(text) { var m = $('gca-form-msg'); m.textContent = text || ''; m.hidden = !text; }
        function result(text, ok) { var r = $('gca-result'); r.textContent = ''; r.hidden = !text; r.className = 'gca-msg ' + (ok ? 'gca-msg--ok' : 'gca-msg--error'); if (text) { r.appendChild(el('span', text)); } return r; }

        function search(q) {
            var url = 'api/search-bookings.php?type=room&' + (q.length >= 2 ? 'q=' + encodeURIComponent(q) : 'recent=1');
            var box = $('gca-results');
            box.textContent = '';
            box.appendChild(el('div', 'Searching...', 'booking-search-loading'));
            fetch(url, { credentials: 'same-origin', headers: { 'X-Requested-With': 'XMLHttpRequest' } })
                .then(function (r) { return r.json(); })
                .then(function (d) {
                    box.textContent = '';
                    var list = (d && d.bookings) || [];
                    if (!list.length) { box.appendChild(el('div', q.length >= 2 ? 'No booking found.' : 'No recent bookings.', 'booking-search-no-results')); return; }
                    list.forEach(function (b) {
                        var btn = el('button', null, 'booking-search-item');
                        btn.type = 'button';
                        btn.appendChild(el('strong', (b.booking_reference || '') + ' - ' + (b.guest_name || '')));
                        btn.appendChild(el('span', [(b.room_name || ''), (b.check_in_date || '') + ' to ' + (b.check_out_date || ''), (b.status || '')].filter(Boolean).join(' · '), 'gca-sub'));
                        btn.appendChild(el('span', 'Owes ' + money(b.amount_due) + (b.group_label ? ' · ' + b.group_label : ''), 'gca-sub'));
                        btn.addEventListener('click', function () { pick(b); });
                        box.appendChild(btn);
                    });
                })
                .catch(function () { box.textContent = ''; box.appendChild(el('div', 'Could not search. Try again.', 'booking-search-no-results')); });
        }

        function pick(b) {
            state.booking = b;
            $('gca-sel-guest').textContent = b.guest_name || '';
            $('gca-sel-line').textContent = [(b.booking_reference || ''), (b.room_name || ''), (b.status || ''), (b.group_label || '')].filter(Boolean).join(' · ');
            $('gca-sel-owed').textContent = money(b.amount_due);
            $('gca-sel-rate').textContent = b.booked_rate && b.booked_rate.text ? 'Booked at ' + b.booked_rate.text : '';
            show('gca-picker', false);
            show('gca-selected', true);
            var isGroup = Number(b.group_size || b.group_rooms || 1) > 1;
            show('gca-scope', isGroup);
            if (!isGroup) { var r0 = document.querySelector('#gca-scope input[value="room"]'); if (r0) { r0.checked = true; } }
            show('gca-charge-panel', true);
            show('gca-preview-panel', false);
            result('', true);
            formMsg('');
            if (b.status === 'checked-out' && root.getAttribute('data-can-correct') !== '1') {
                formMsg('This room has checked out. Adding a charge after check-out needs the Correct Stay After Check-out permission. Ask a manager.');
            }
        }

        function fields() {
            var sc = document.querySelector('#gca-scope input[name="gca_scope"]:checked');
            return {
                booking_id: state.booking ? String(state.booking.id) : '',
                scope: (!$('gca-scope').hidden && sc) ? sc.value : 'room',
                charge_type: $('gca-type').value,
                description: $('gca-desc').value.trim(),
                quantity: $('gca-qty').value,
                unit_price: $('gca-price').value,
                reason: $('gca-reason').value.trim()
            };
        }

        function post(action, extra) {
            var f = fields();
            var body = new URLSearchParams();
            body.append('csrf_token', csrf);
            body.append('gca_action', action);
            Object.keys(f).forEach(function (k) { body.append(k, f[k]); });
            Object.keys(extra || {}).forEach(function (k) { body.append(k, extra[k]); });
            return fetch('charge-add.php', { method: 'POST', credentials: 'same-origin', headers: { 'X-Requested-With': 'XMLHttpRequest' }, body: body })
                .then(function (r) { return r.json().catch(function () { return { success: false, message: 'Something went wrong. Nothing was added.' }; }); });
        }

        function lock(on) { state.busy = on; ['gca-preview-btn', 'gca-confirm-btn'].forEach(function (id) { var b = $(id); if (b) { b.disabled = on; } }); }

        function preview() {
            if (state.busy) { return; }
            formMsg('');
            var f = fields();
            if (!f.description) { formMsg('Write what the charge is for (it prints on the invoice).'); return; }
            if (!(Number(f.quantity) > 0)) { formMsg('Quantity must be more than 0.'); return; }
            if (!(Number(f.quantity) * Number(f.unit_price) >= 0.01)) { formMsg('Enter the price.'); return; }
            if (!f.reason) { formMsg('A reason is required. It is kept in the booking history.'); return; }
            lock(true);
            post('preview').then(function (d) {
                lock(false);
                if (!d || !d.success) { formMsg((d && d.message) || 'The preview could not be made.'); return; }
                state.sig = d.sig; state.nonce = d.nonce;
                $('gca-total').textContent = d.total_text || '';
                var tb = $('gca-rows'); tb.textContent = '';
                (d.rows || []).forEach(function (r) {
                    var tr = document.createElement('tr');
                    var c1 = el('td'); c1.setAttribute('data-label', 'Room');
                    c1.appendChild(el('strong', [r.room, r.ref].filter(Boolean).join(' · '))); c1.appendChild(el('span', r.guest, 'gca-sub'));
                    var c2 = el('td'); c2.setAttribute('data-label', 'Status');
                    c2.appendChild(el('span', r.status));
                    if (r.checked_out) { c2.appendChild(document.createTextNode(' ')); c2.appendChild(el('span', 'After check-out', 'gca-co')); }
                    var c3 = el('td'); c3.setAttribute('data-label', 'Charge');
                    c3.appendChild(el('span', r.amount_text)); c3.appendChild(el('span', r.description, 'gca-sub'));
                    var c4 = el('td', r.owed_now_text); c4.setAttribute('data-label', 'Owed now');
                    var c5 = el('td', r.owed_after_text); c5.setAttribute('data-label', 'Owed after');
                    [c1, c2, c3, c4, c5].forEach(function (c) { tr.appendChild(c); });
                    tb.appendChild(tr);
                });
                var sk = d.skipped || [];
                var ul = $('gca-skipped-list'); ul.textContent = '';
                sk.forEach(function (s) { ul.appendChild(el('li', [s.room, s.ref].filter(Boolean).join(' · ') + ' (' + s.status + '): ' + (s.reason || 'cannot take this charge'))); });
                show('gca-skipped', sk.length > 0);
                show('gca-preview-panel', true);
                $('gca-preview-panel').scrollIntoView({ behavior: 'smooth', block: 'start' });
            }).catch(function () { lock(false); formMsg('The preview could not be made. Try again.'); });
        }

        function confirmCharge() {
            if (state.busy || !state.nonce) { return; }
            lock(true);
            post('confirm', { sig: state.sig, nonce: state.nonce }).then(function (d) {
                state.nonce = '';
                lock(false);
                show('gca-preview-panel', false);
                var r = result((d && d.message) || 'Nothing was added.', !!(d && d.success));
                if (d && d.invoice_notice) { r.appendChild(el('span', ' ' + d.invoice_notice)); }
                if (d && d.success && d.booking_id) {
                    var a = el('a', 'Open the booking', 'tbl-btn tbl-btn--view gca-btn');
                    a.href = 'booking-details.php?id=' + encodeURIComponent(d.booking_id) + '#folio';
                    a.style.marginLeft = '.6rem';
                    r.appendChild(a);
                    show('gca-charge-panel', false);
                    $('gca-desc').value = ''; $('gca-price').value = ''; $('gca-qty').value = '1'; $('gca-reason').value = '';
                }
                r.scrollIntoView({ behavior: 'smooth', block: 'start' });
            }).catch(function () { lock(false); state.nonce = ''; result('The result is unknown. Check the booking folio before trying again.', false); });
        }

        $('gca-search').addEventListener('input', function () {
            var q = this.value.trim();
            clearTimeout(timer);
            timer = setTimeout(function () { search(q); }, 300);
        });
        $('gca-change').addEventListener('click', function () {
            state.booking = null; state.nonce = '';
            show('gca-selected', false); show('gca-picker', true); show('gca-charge-panel', false); show('gca-preview-panel', false);
            result('', true);
            $('gca-search').focus();
        });
        $('gca-preview-btn').addEventListener('click', preview);
        $('gca-back').addEventListener('click', function () { state.nonce = ''; show('gca-preview-panel', false); });
        $('gca-confirm-btn').addEventListener('click', confirmCharge);
        ['gca-type', 'gca-qty', 'gca-price', 'gca-desc', 'gca-reason'].forEach(function (id) {
            $(id).addEventListener('input', function () { if (!$('gca-preview-panel').hidden) { state.nonce = ''; show('gca-preview-panel', false); } });
        });
        document.querySelectorAll('#gca-scope input').forEach(function (i) {
            i.addEventListener('change', function () { state.nonce = ''; show('gca-preview-panel', false); });
        });
        <?php if ($gcaCanSearch): ?>search('');<?php endif; ?>
    })();
    </script>

    <?php require_once 'includes/admin-footer.php'; ?>
</body>

</html>
