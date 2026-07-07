<?php
/**
 * Order lifecycle viewer.
 *
 * Consolidates EVERY event for a single restaurant order into one timeline:
 *   - placement (cashier creates the order)               [stock_orders + audit]
 *   - kitchen events (fired/started/ready/served/recall)  [stock_kds_events]
 *   - payment events (placed_paid, paid_from_tab, void)   [stock_order_audit]
 *   - stock movements (deductions + restorations)         [stock_adjustments]
 *   - linked finance row                                  [payments]
 *
 * Read-only. Permission: stock_orders (view) + same self-only restriction
 * applied to restaurant_staff (they only see their own tabs).
 *
 * Designed as a drawer page that can be opened from POS, KDS, tabs tray,
 * stock-orders.php and from booking detail. URL: ?id=<order_id>.
 */
require_once 'admin-init.php';

$user = [
    'id'        => $_SESSION['admin_user_id'],
    'role'      => $_SESSION['admin_role'],
    'full_name' => $_SESSION['admin_full_name'],
];
if (!hasPermission((int)$user['id'], 'stock_orders')) { http_response_code(403); exit('Forbidden.'); }

$orderId = (int)($_GET['id'] ?? 0);
if ($orderId <= 0) { http_response_code(400); exit('Missing id.'); }
$currency_symbol = getSetting('currency_symbol');

function lifecycleOrderLocationLabel(array $order): string {
    if (($order['order_type'] ?? '') === 'room_service') {
        $room = trim((string)($order['room_number'] ?? ''));
        if ($room === '') {
            $room = trim((string)($order['table_number'] ?? ''));
        }
        if ($room === '') {
            return 'Room service';
        }
        return stripos($room, 'room') === 0 ? $room : 'Room ' . $room;
    }
    $table = trim((string)($order['table_number'] ?? ''));
    return $table !== '' ? 'Table ' . $table : '';
}

$st = $pdo->prepare("
    SELECT o.*, u.full_name AS created_by_name, b.booking_reference AS linked_booking_reference,
           v.full_name AS voided_by_name
      FROM stock_orders o
 LEFT JOIN admin_users u ON u.id = o.created_by
 LEFT JOIN admin_users v ON v.id = o.voided_by
 LEFT JOIN bookings b ON b.id = o.booking_id
     WHERE o.id = ?
");
$st->execute([$orderId]);
$order = $st->fetch(PDO::FETCH_ASSOC);
if (!$order) { http_response_code(404); exit('Order not found.'); }

if (($user['role'] ?? '') === 'restaurant_staff' && (int)$order['created_by'] !== (int)$user['id']) {
    http_response_code(403); exit('You can only view your own tabs.');
}
$isRoomService = ($order['order_type'] ?? '') === 'room_service';
$locationLabel = lifecycleOrderLocationLabel($order);
$isVoided     = in_array($order['status'] ?? '', ['voided', 'cancelled'], true);
$canCancel    = in_array($user['role'] ?? '', ['admin', 'manager'], true)
             && in_array($order['status'] ?? '', ['placed', 'new'], true)
             && !in_array($order['kitchen_status'] ?? '', ['in_progress', 'ready', 'served'], true);
$canVoid      = ($user['role'] ?? '') === 'admin'
             && in_array($order['status'] ?? '', ['paid', 'completed'], true);

// Items
$items = $pdo->prepare("SELECT * FROM stock_order_items WHERE order_id=? ORDER BY id");
$items->execute([$orderId]);
$items = $items->fetchAll(PDO::FETCH_ASSOC);

$folioCharges = [];
if ($isRoomService) {
    try {
        $folioStmt = $pdo->prepare("SELECT id, booking_id, description, quantity, line_total, posted_at, voided, voided_at, void_reason FROM booking_charges WHERE stock_order_id = ? ORDER BY posted_at, id");
        $folioStmt->execute([$orderId]);
        $folioCharges = $folioStmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (Throwable $e) {
        $folioCharges = [];
    }
}
$folioChargeIds = array_map(static fn(array $charge): int => (int)$charge['id'], $folioCharges);

// Audit + KDS events + stock adjustments + payments — fold into one timeline
$timeline = [];

// 1. Order placed (synthetic event)
$timeline[] = [
    'when'  => $order['created_at'],
    'who'   => $order['created_by_name'] ?: 'Unknown',
    'icon'  => 'fas fa-cash-register',
    'color' => '#8B7355',
    'event' => 'Order created',
    'note'  => 'Reference ' . $order['reference'] . ' · ' . count($items) . ' line(s) · ' . $currency_symbol . ' ' . number_format((float)$order['total_amount'], 2)
              . ($locationLabel !== '' ? ' · ' . $locationLabel : '')
              . ($isRoomService && !empty($order['linked_booking_reference']) ? ' · Folio ' . $order['linked_booking_reference'] : '')
              . ($order['customer_name']  ? ' · ' . $order['customer_name'] : '')
              . ($order['notes']          ? ' · Note: ' . $order['notes'] : ''),
];

// 2. POS audit
$audit = $pdo->prepare("SELECT actor_name, event, details, ip_address, created_at FROM stock_order_audit WHERE order_id=? ORDER BY created_at, id");
$audit->execute([$orderId]);
foreach ($audit->fetchAll(PDO::FETCH_ASSOC) as $a) {
    $det = $a['details'] ? json_decode($a['details'], true) : null;
    $note = '';
    if (is_array($det)) {
        $bits = [];
        if (isset($det['method'])) $bits[] = 'method: ' . $det['method'];
        if (isset($det['total']))  $bits[] = $currency_symbol . ' ' . number_format((float)$det['total'], 2);
        if (!empty($det['tendered'])) $bits[] = 'tendered ' . number_format((float)$det['tendered'], 2);
        if (!empty($det['change']))   $bits[] = 'change ' . number_format((float)$det['change'], 2);
        if (isset($det['table']) && $det['table'] !== '') $bits[] = $isRoomService ? 'room ' . $det['table'] : 'table ' . $det['table'];
        if (isset($det['lines']))  $bits[] = $det['lines'] . ' line(s)';
        $note = implode(' · ', $bits);
    }
    $timeline[] = [
        'when'  => $a['created_at'],
        'who'   => $a['actor_name'] ?: 'Unknown',
        'icon'  => $a['event'] === 'shift_closed' ? 'fas fa-cash-register'
                : ($a['event'] === 'parked_open_tab' ? 'fas fa-utensils'
                : ($a['event'] === 'voided' ? 'fas fa-ban' : 'fas fa-credit-card')),
        'color' => $a['event'] === 'voided' ? '#c82333' : ($a['event'] === 'parked_open_tab' ? '#d4a843' : '#28a745'),
        'event' => ucfirst(str_replace('_',' ', $a['event'])),
        'note'  => $note ?: '—',
        'ip'    => $a['ip_address'],
    ];
}

// 3. KDS events (kitchen)
try {
    $kds = $pdo->prepare("SELECT k.*, oi.item_name FROM stock_kds_events k LEFT JOIN stock_order_items oi ON oi.id = k.order_item_id WHERE k.order_id=? ORDER BY k.created_at, k.id");
    $kds->execute([$orderId]);
    foreach ($kds->fetchAll(PDO::FETCH_ASSOC) as $k) {
        $iconMap = ['fired'=>'fas fa-fire','started'=>'fas fa-fire-alt','ready'=>'fas fa-bell','collected'=>'fas fa-hand-holding','served'=>'fas fa-check-double','recalled'=>'fas fa-undo','voided'=>'fas fa-ban','cancelled'=>'fas fa-circle-xmark','cancelled_before_prep'=>'fas fa-circle-xmark'];
        $colorMap = ['fired'=>'#d4a843','started'=>'#d4a843','ready'=>'#17a2b8','collected'=>'#fd7e14','served'=>'#28a745','recalled'=>'#c82333','voided'=>'#c82333','cancelled'=>'#c82333','cancelled_before_prep'=>'#c82333'];
        $timeline[] = [
            'when'  => $k['created_at'],
            'who'   => $k['user_name'] ?: 'Kitchen',
            'icon'  => $iconMap[$k['event']] ?? 'fas fa-utensils',
            'color' => $colorMap[$k['event']] ?? '#9aa3af',
            'event' => 'Kitchen: ' . $k['event'] . ($k['item_name'] ? ' — ' . $k['item_name'] : ''),
            'note'  => trim(($k['from_status'] ? $k['from_status'] . ' → ' : '') . ($k['to_status'] ?? '')),
            'ip'    => $k['ip_address'],
        ];
    }
} catch (Throwable $e) { /* kds tables may not exist on legacy installs */ }

// 3b. Room-service folio charges
foreach ($folioCharges as $charge) {
    $timeline[] = [
        'when'  => $charge['posted_at'] ?: $order['created_at'],
        'who'   => 'Room folio',
        'icon'  => 'fas fa-file-invoice-dollar',
        'color' => '#17a2b8',
        'event' => 'Folio charge posted',
        'note'  => ($charge['description'] ?? 'Room service') . ' · ' . rtrim(rtrim(number_format((float)$charge['quantity'], 2), '0'), '.') . ' item(s) · ' . $currency_symbol . ' ' . number_format((float)$charge['line_total'], 2),
    ];

    if (!empty($charge['voided'])) {
        $timeline[] = [
            'when'  => $charge['voided_at'] ?: $charge['posted_at'],
            'who'   => 'Room folio',
            'icon'  => 'fas fa-ban',
            'color' => '#c82333',
            'event' => 'Folio charge voided',
            'note'  => trim((string)($charge['void_reason'] ?? 'Room service order voided')),
        ];
    }
}

// 4. Stock adjustments (auto stock deduction)
try {
    $stockWhere = "(sa.source_type IN ('pos_order','void_restore','recall') AND sa.source_id = ?)";
    $stockParams = [$orderId];
    if ($folioChargeIds) {
        $placeholders = implode(',', array_fill(0, count($folioChargeIds), '?'));
        $stockWhere .= " OR (sa.source_type IN ('room_service','void_restore') AND sa.source_id IN ({$placeholders}))";
        $stockParams = array_merge($stockParams, $folioChargeIds);
    }

    $sa = $pdo->prepare("
        SELECT sa.id, sa.quantity_change, sa.reason, sa.source_type, sa.cost_at_time, sa.created_at,
               i.name AS ingredient, i.unit, u.full_name AS adjusted_by_name
          FROM stock_adjustments sa
     LEFT JOIN stock_ingredients i ON i.id = sa.ingredient_id
     LEFT JOIN admin_users u ON u.id = sa.adjusted_by
         WHERE {$stockWhere}
      ORDER BY sa.created_at, sa.id
    ");
    $sa->execute($stockParams);
    foreach ($sa->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $sign = (float)$row['quantity_change'] >= 0 ? '+' : '';
        $cost = (float)$row['cost_at_time'] * abs((float)$row['quantity_change']);
        $timeline[] = [
            'when'  => $row['created_at'],
            'who'   => $row['adjusted_by_name'] ?: 'System',
            'icon'  => (float)$row['quantity_change'] < 0 ? 'fas fa-minus-circle' : 'fas fa-plus-circle',
            'color' => (float)$row['quantity_change'] < 0 ? '#c82333' : '#28a745',
            'event' => 'Stock ' . ((float)$row['quantity_change'] < 0 ? 'deducted' : 'restored') . ' — ' . ($row['ingredient'] ?? 'item'),
            'note'  => $sign . number_format((float)$row['quantity_change'], 4) . ' ' . ($row['unit'] ?? '')
                       . ' · ' . ($row['reason'] ?? $row['source_type'])
                       . ' · cost ' . $currency_symbol . ' ' . number_format($cost, 2),
        ];
    }
} catch (Throwable $e) { /* legacy */ }

// 5. Linked payment row
$pay = $pdo->prepare("SELECT id, payment_reference, payment_amount, total_amount, payment_method, payment_status, recorded_by, created_at FROM payments WHERE booking_type='restaurant' AND booking_id=? AND deleted_at IS NULL ORDER BY created_at LIMIT 1");
$pay->execute([$orderId]);
$payRow = $pay->fetch(PDO::FETCH_ASSOC);

// Sort timeline chronologically
usort($timeline, fn($a, $b) => strtotime($a['when']) <=> strtotime($b['when']));

// Cycle metrics
$placed = strtotime($order['created_at']);
$fired  = $order['fired_at'] ? strtotime($order['fired_at']) : null;
$ready  = null; $served = $order['served_at'] ? strtotime($order['served_at']) : null;
$paid   = $order['paid_at'] ? strtotime($order['paid_at']) : null;
foreach ($timeline as $e) {
    if (str_starts_with($e['event'], 'Kitchen: ready') && !$ready) $ready = strtotime($e['when']);
}
function fmt_dur(?int $from, ?int $to) { if (!$from || !$to) return '—'; $s = max(0, $to - $from); $m = floor($s/60); return $m . 'm ' . ($s%60) . 's'; }
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Order Lifecycle — <?php echo htmlspecialchars($order['reference']); ?></title>
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <link href="https://fonts.googleapis.com/css2?family=Jost:wght@400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.7.2/css/all.min.css">
    <link rel="stylesheet" href="css/order-lifecycle.css?v=<?php echo @filemtime(__DIR__ . '/css/order-lifecycle.css'); ?>">
</head>
<body>
<div class="wrap">
    <div class="head">
        <div>
            <h1><i class="fas fa-stream" style="color:var(--color-primary,#8A775F);"></i> Order lifecycle</h1>
            <div class="ref">
                <strong><?php echo htmlspecialchars($order['reference']); ?></strong>
                <?php $status = $order['status']; ?>
                <span class="pill pill-<?php echo htmlspecialchars($status); ?>"><?php echo htmlspecialchars($status); ?></span>
                · <?php echo htmlspecialchars(str_replace('_',' ', $order['order_type'])); ?>
                <?php if ($locationLabel !== ''): ?> · <?php echo htmlspecialchars($locationLabel); ?><?php endif; ?>
                <?php if ($isRoomService && !empty($order['linked_booking_reference'])): ?> · Folio <?php echo htmlspecialchars($order['linked_booking_reference']); ?><?php endif; ?>
                <?php if ($order['customer_name']): ?> · <?php echo htmlspecialchars($order['customer_name']); ?><?php endif; ?>
            </div>
        </div>
        <div style="text-align:right;">
            <div style="font-size:24px; font-weight:700; color:var(--color-primary,#8A775F);"><?php echo $currency_symbol . ' ' . number_format((float)$order['total_amount'], 2); ?></div>
            <?php if ($order['payment_method']): ?><div style="font-size:12px; color:#6c757d;"><?php echo htmlspecialchars(str_replace('_',' ', $order['payment_method'])); ?></div><?php endif; ?>
        </div>
    </div>

    <?php if ($isVoided): ?>
    <div class="void-banner <?php echo $order['status'] === 'cancelled' ? 'cancelled' : ''; ?>">
        <strong><i class="fas fa-ban"></i> <?php echo $order['status'] === 'cancelled' ? 'Order Cancelled' : 'Order Voided'; ?></strong>
        <?php if (!empty($order['void_reason'])): ?>Reason: <?php echo htmlspecialchars($order['void_reason']); ?><br><?php endif; ?>
        <?php if (!empty($order['voided_at'])): ?>
            At: <?php echo htmlspecialchars(date('Y-m-d H:i:s', strtotime($order['voided_at']))); ?>
            <?php if (!empty($order['voided_by_name'])): ?> · By: <?php echo htmlspecialchars($order['voided_by_name']); ?><?php endif; ?>
        <?php endif; ?>
    </div>
    <?php endif; ?>

    <div class="metrics">
        <div class="metric"><div class="lbl">Placed</div><div class="val"><?php echo $placed ? date('H:i:s', $placed) : '—'; ?></div></div>
        <div class="metric"><div class="lbl">→ Fired (kitchen)</div><div class="val"><?php echo fmt_dur($placed, $fired); ?></div></div>
        <div class="metric <?php echo ($fired && $ready && ($ready - $fired) > 600) ? 'hot' : 'ok'; ?>"><div class="lbl">→ Ready</div><div class="val"><?php echo fmt_dur($fired, $ready); ?></div></div>
        <div class="metric"><div class="lbl">→ Served</div><div class="val"><?php echo fmt_dur($ready, $served); ?></div></div>
        <div class="metric"><div class="lbl">→ Paid</div><div class="val"><?php echo fmt_dur($placed, $paid); ?></div></div>
        <div class="metric"><div class="lbl">Total cycle</div><div class="val"><?php echo fmt_dur($placed, $paid ?: $served); ?></div></div>
    </div>

    <div class="items">
        <h2><i class="fas fa-list"></i> Items (<?php echo count($items); ?>)</h2>
        <table>
            <thead><tr><th>Item</th><th>Qty</th><th>Unit</th><th>Total</th><th>Type</th><th>Kitchen</th><th>Note</th></tr></thead>
            <tbody>
            <?php foreach ($items as $it): ?>
                <tr>
                    <td><?php echo htmlspecialchars($it['item_name']); ?></td>
                    <td><?php echo rtrim(rtrim(number_format((float)$it['quantity'], 2),'0'),'.'); ?></td>
                    <td><?php echo $currency_symbol . ' ' . number_format((float)$it['unit_price'], 2); ?></td>
                    <td><strong><?php echo $currency_symbol . ' ' . number_format((float)$it['line_total'], 2); ?></strong></td>
                    <td><?php echo htmlspecialchars($it['menu_type']); ?></td>
                    <td><span class="bdg b-<?php echo htmlspecialchars($it['kds_status'] ?? 'pending'); ?>"><?php echo htmlspecialchars($it['kds_status'] ?? '—'); ?></span></td>
                    <td style="color:var(--color-primary,#8A775F); font-style:italic;"><?php echo htmlspecialchars($it['notes'] ?? ''); ?></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>

    <div class="timeline">
        <h2><i class="fas fa-history"></i> Activity timeline (<?php echo count($timeline); ?> events)</h2>
        <?php foreach ($timeline as $e): ?>
            <div class="ev">
                <div class="ic" style="background:<?php echo $e['color']; ?>"><i class="<?php echo $e['icon']; ?>"></i></div>
                <div class="body">
                    <strong><?php echo htmlspecialchars($e['event']); ?></strong>
                    <div class="nt"><?php echo htmlspecialchars($e['note'] ?? ''); ?></div>
                </div>
                <div class="meta">
                    <div class="who"><?php echo htmlspecialchars($e['who']); ?></div>
                    <div><?php echo htmlspecialchars(date('Y-m-d H:i:s', strtotime($e['when']))); ?></div>
                    <?php if (!empty($e['ip'])): ?><div style="font-family:monospace; font-size:10px;"><?php echo htmlspecialchars($e['ip']); ?></div><?php endif; ?>
                </div>
            </div>
        <?php endforeach; ?>
    </div>

    <?php if ($payRow): ?>
    <div class="items" style="margin-top:18px;">
        <h2><i class="fas fa-receipt"></i> Linked payment</h2>
        <p style="margin:0; font-size:13px; color:#495057;">
            <strong><?php echo htmlspecialchars($payRow['payment_reference']); ?></strong>
            · <?php echo htmlspecialchars($payRow['payment_status']); ?>
            · <?php echo htmlspecialchars(str_replace('_',' ', $payRow['payment_method'])); ?>
            · <?php echo $currency_symbol . ' ' . number_format((float)$payRow['total_amount'], 2); ?>
            · recorded <?php echo htmlspecialchars(date('Y-m-d H:i:s', strtotime($payRow['created_at']))); ?>
        </p>
    </div>
    <?php endif; ?>

    <?php if ($folioCharges): ?>
    <?php
        $activeFolioCharges = array_filter($folioCharges, static fn(array $charge): bool => empty($charge['voided']));
        $folioActiveTotal = array_sum(array_map(static fn(array $charge): float => (float)$charge['line_total'], $activeFolioCharges));
    ?>
    <div class="items" style="margin-top:18px;">
        <h2><i class="fas fa-file-invoice-dollar"></i> Linked room folio</h2>
        <p style="margin:0; font-size:13px; color:#495057;">
            <strong><?php echo htmlspecialchars($order['linked_booking_reference'] ?: 'Room service folio'); ?></strong>
            · <?php echo count($activeFolioCharges); ?> active charge(s)
            · <?php echo count($folioCharges) - count($activeFolioCharges); ?> voided
            · active total <?php echo $currency_symbol . ' ' . number_format((float)$folioActiveTotal, 2); ?>
        </p>
    </div>
    <?php endif; ?>

    <div class="actions">
        <a class="a-back" href="javascript:history.back()"><i class="fas fa-arrow-left"></i> Back</a>
        <a class="a-print" href="stock-receipt.php?id=<?php echo $orderId; ?>&print=1" target="_blank"><i class="fas fa-print"></i> Receipt</a>
        <a class="a-print" href="stock-receipt.php?id=<?php echo $orderId; ?>&print=1&kot=1" target="_blank"><i class="fas fa-print"></i> KOT</a>
        <?php if (in_array($user['role'], ['admin','manager'], true)): ?>
            <a class="a-receipt" href="stock-orders.php"><i class="fas fa-list"></i> All orders</a>
        <?php endif; ?>
        <?php if ($canCancel): ?>
            <button class="a-danger" id="btnCancel" onclick="lifecycleCancel()"><i class="fas fa-xmark-circle"></i> Cancel order</button>
        <?php endif; ?>
        <?php if ($canVoid): ?>
            <button class="a-warn" id="btnVoid" onclick="lifecycleVoid()"><i class="fas fa-ban"></i> Void order</button>
        <?php endif; ?>
    </div>
</div>
<script>
(function(){
    'use strict';
    const CSRF = <?php echo json_encode($_SESSION['csrf_token'] ?? ''); ?>;
    const ORDER_ID = <?php echo $orderId; ?>;

    function setLoading(btn, on) {
        if (on) { btn.disabled = true; btn.classList.add('btn-loading'); }
        else { btn.disabled = false; btn.classList.remove('btn-loading'); }
    }

    window.lifecycleCancel = function() {
        const btn = document.getElementById('btnCancel');
        const reason = prompt('Cancel reason (required):');
        if (!reason || !reason.trim()) return;
        setLoading(btn, true);
        fetch('../api/cancel-order.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/x-www-form-urlencoded', 'X-Requested-With': 'XMLHttpRequest' },
            body: new URLSearchParams({ csrf_token: CSRF, order_id: ORDER_ID, cancel_reason: reason.trim() })
        })
        .then(r => r.json())
        .then(d => {
            if (d.ok || d.success) { location.reload(); }
            else { alert('Cancel failed: ' + (d.error || 'Unknown error')); setLoading(btn, false); }
        })
        .catch(() => { alert('Network error — please try again.'); setLoading(btn, false); });
    };

    window.lifecycleVoid = function() {
        const btn = document.getElementById('btnVoid');
        const reason = prompt('Void reason (minimum 8 characters, required):');
        if (!reason || reason.trim().length < 8) { if (reason !== null) alert('Reason must be at least 8 characters.'); return; }
        setLoading(btn, true);
        fetch('../api/void-order.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/x-www-form-urlencoded', 'X-Requested-With': 'XMLHttpRequest' },
            body: new URLSearchParams({ csrf_token: CSRF, order_id: ORDER_ID, void_reason: reason.trim() })
        })
        .then(r => r.json())
        .then(d => {
            if (d.success || d.ok) { location.reload(); }
            else { alert('Void failed: ' + (d.error || 'Unknown error')); setLoading(btn, false); }
        })
        .catch(() => { alert('Network error — please try again.'); setLoading(btn, false); });
    };
})();
</script>
</body>
</html>

