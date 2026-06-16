<?php
/**
 * Deals & Promotions Management
 */
require_once 'admin-init.php';
require_once __DIR__ . '/../includes/alert.php';

/** @var PDO $pdo */
/** @var array $user */

if (!hasPermission((int)$user['id'], 'stock_management')) {
    header('Location: dashboard.php?error=access_denied');
    exit;
}

$VALID_TYPES = ['happy_hour', 'percent_off', 'fixed_off', 'multi_buy', 'spend_save', 'combo'];

// ── GET: item search / fetch by IDs ──────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'GET' && isset($_GET['aj'])) {
    header('Content-Type: application/json');
    $q   = trim($_GET['q']   ?? '');
    $ids = trim($_GET['ids'] ?? '');
    if ($ids !== '') {
        $idArr = array_values(array_filter(array_map('intval', explode(',', $ids))));
        if ($idArr) {
            $ph   = implode(',', array_fill(0, count($idArr), '?'));
            $stmt = $pdo->prepare("SELECT mi.id, mi.item_name AS name, mc.name AS category
                                   FROM menu_items mi JOIN menu_categories mc ON mc.id = mi.category_id
                                   WHERE mi.id IN ($ph) ORDER BY mi.item_name");
            $stmt->execute($idArr);
            echo json_encode(array_values($stmt->fetchAll(PDO::FETCH_ASSOC)));
        } else { echo json_encode([]); }
    } elseif ($q !== '') {
        $stmt = $pdo->prepare("SELECT mi.id, mi.item_name AS name, mc.name AS category
                               FROM menu_items mi JOIN menu_categories mc ON mc.id = mi.category_id
                               WHERE mi.is_available = 1 AND (mi.show_pos = 1 OR mi.show_room_service = 1)
                               AND mi.item_name LIKE ? ORDER BY mi.item_name LIMIT 15");
        $stmt->execute(['%' . $q . '%']);
        echo json_encode(array_values($stmt->fetchAll(PDO::FETCH_ASSOC)));
    } else { echo json_encode([]); }
    exit;
}

// ── AJAX handlers ─────────────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['ajax_action'])) {
    header('Content-Type: application/json');
    if (!validateCsrfToken($_POST['csrf_token'] ?? '')) {
        echo json_encode(['ok' => false, 'error' => 'Security token invalid.']); exit;
    }
    $action = $_POST['ajax_action'];
    try {
        if ($action === 'save') {
            $id   = (int)($_POST['id'] ?? 0);
            $name = mb_substr(trim($_POST['name'] ?? ''), 0, 100);
            $desc = mb_substr(trim($_POST['description'] ?? ''), 0, 255);
            $type = $_POST['deal_type'] ?? '';
            if (!in_array($type, $VALID_TYPES, true)) throw new InvalidArgumentException('Invalid deal type.');
            if ($name === '') throw new InvalidArgumentException('Name is required.');

            // Time / date
            $dow       = trim($_POST['days_of_week'] ?? '');
            $startTime = trim($_POST['start_time'] ?? '') ?: null;
            $endTime   = trim($_POST['end_time']   ?? '') ?: null;
            $validFrom = trim($_POST['valid_from'] ?? '') ?: null;
            $validTo   = trim($_POST['valid_to']   ?? '') ?: null;

            // Scope
            $appliesTo = $_POST['applies_to'] ?? 'all';
            if (!in_array($appliesTo, ['all','item_types','items'], true)) $appliesTo = 'all';

            $itemTypes = null;
            if ($appliesTo === 'item_types') {
                $arr = array_values(array_filter(array_map('trim', explode(',', trim($_POST['item_types'] ?? '')))));
                if ($arr) $itemTypes = json_encode($arr);
            }
            $itemIds = null;
            if ($appliesTo === 'items') {
                $arr = array_values(array_filter(array_map('intval', explode(',', trim($_POST['item_ids'] ?? '')))));
                if ($arr) $itemIds = json_encode($arr);
            }

            // Discount params
            $discPct   = max(0.0, min(100.0, (float)($_POST['discount_percent'] ?? 0)));
            $discFixed = max(0.0, (float)($_POST['discount_fixed'] ?? 0));

            // Multi-buy
            $mbQty = null; $mbPay = null;
            if ($type === 'multi_buy') {
                $mbQty = max(2, (int)($_POST['multi_buy_qty'] ?? 3));
                $mbPay = max(1, (int)($_POST['multi_buy_pay'] ?? 2));
                if ($mbPay >= $mbQty) throw new InvalidArgumentException('"Pay for" must be less than "Buy" qty.');
            }

            // Spend threshold
            $spendThreshold = null;
            if ($type === 'spend_save') {
                $spendThreshold = max(0.01, (float)($_POST['spend_threshold'] ?? 0));
            }

            // Combo requirements: [{item_types:[], min_qty:N}, ...]
            $comboRequires = null;
            if ($type === 'combo') {
                $comboJson = trim($_POST['combo_requires'] ?? '');
                if ($comboJson !== '') {
                    $decoded = json_decode($comboJson, true);
                    if (is_array($decoded) && count($decoded) >= 2) {
                        $comboRequires = json_encode($decoded);
                    } else {
                        throw new InvalidArgumentException('Combo requires at least 2 groups in valid JSON.');
                    }
                }
            }

            // Days of week
            $dowArr = null;
            if ($dow !== '') {
                $arr = array_values(array_filter(array_map('intval', explode(',', $dow)), fn($d) => $d >= 1 && $d <= 7));
                if ($arr) $dowArr = json_encode(array_values(array_unique($arr)));
            }

            // Flags
            $isActive       = isset($_POST['is_active']) ? 1 : 0;
            $exclusive      = isset($_POST['exclusive'])  ? 1 : 0;
            $maxUses        = trim($_POST['max_uses_per_order'] ?? '') !== '' ? max(1, (int)$_POST['max_uses_per_order']) : null;
            $sort           = (int)($_POST['sort_order'] ?? 0);

            $params = [$name, $desc ?: null, $type, $dowArr, $startTime, $endTime,
                       $validFrom, $validTo, $appliesTo, $itemTypes, $itemIds,
                       $discPct, $discFixed, $mbQty, $mbPay,
                       $spendThreshold, $comboRequires, $maxUses, $exclusive,
                       $isActive, $sort];

            if ($id > 0) {
                $params[] = $id;
                $pdo->prepare("UPDATE pos_deals SET
                    name=?, description=?, deal_type=?, days_of_week=?,
                    start_time=?, end_time=?, valid_from=?, valid_to=?,
                    applies_to=?, item_types=?, item_ids=?,
                    discount_percent=?, discount_fixed=?, multi_buy_qty=?, multi_buy_pay=?,
                    spend_threshold=?, combo_requires=?, max_uses_per_order=?, exclusive=?,
                    is_active=?, sort_order=?
                    WHERE id=?")->execute($params);
                echo json_encode(['ok' => true, 'msg' => 'Deal updated.', 'id' => $id]);
            } else {
                $params[] = (int)$user['id'];
                $pdo->prepare("INSERT INTO pos_deals
                    (name, description, deal_type, days_of_week, start_time, end_time,
                     valid_from, valid_to, applies_to, item_types, item_ids,
                     discount_percent, discount_fixed, multi_buy_qty, multi_buy_pay,
                     spend_threshold, combo_requires, max_uses_per_order, exclusive,
                     is_active, sort_order, created_by)
                    VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)")->execute($params);
                echo json_encode(['ok' => true, 'msg' => 'Deal created.', 'id' => (int)$pdo->lastInsertId()]);
            }

        } elseif ($action === 'toggle') {
            $id = (int)($_POST['id'] ?? 0);
            if ($id < 1) throw new InvalidArgumentException('Invalid ID.');
            $pdo->prepare("UPDATE pos_deals SET is_active = 1 - is_active WHERE id=?")->execute([$id]);
            echo json_encode(['ok' => true, 'active' => (int)$pdo->query("SELECT is_active FROM pos_deals WHERE id=$id")->fetchColumn()]);

        } elseif ($action === 'delete') {
            $id = (int)($_POST['id'] ?? 0);
            if ($id < 1) throw new InvalidArgumentException('Invalid ID.');
            $pdo->prepare("DELETE FROM pos_deals WHERE id=?")->execute([$id]);
            echo json_encode(['ok' => true]);

        } else {
            echo json_encode(['ok' => false, 'error' => 'Unknown action.']);
        }
    } catch (Exception $e) {
        echo json_encode(['ok' => false, 'error' => $e->getMessage()]);
    }
    exit;
}

// ── Load deals ────────────────────────────────────────────────────────────────
$deals = $pdo->query("SELECT * FROM pos_deals ORDER BY sort_order ASC, id ASC")->fetchAll(PDO::FETCH_ASSOC);

$nowTime = date('H:i:s');
$nowDow  = (int)date('N');

function deal_active_now(array $d, string $nt, int $nd): bool {
    if (!$d['is_active']) return false;
    if ($d['valid_from'] && date('Y-m-d') < $d['valid_from']) return false;
    if ($d['valid_to']   && date('Y-m-d') > $d['valid_to'])   return false;
    if ($d['days_of_week']) {
        $days = json_decode($d['days_of_week'], true) ?: [];
        if (!in_array($nd, $days)) return false;
    }
    if ($d['start_time'] && $d['end_time'])
        if ($nt < $d['start_time'] || $nt > $d['end_time']) return false;
    return true;
}

$csrf_token = generateCsrfToken();
$sym        = getSetting('currency_symbol', 'MWK');
$site_name  = getSetting('site_name', 'Hotel');

$TYPE_META = [
    'happy_hour'  => ['label' => 'Happy Hour',    'icon' => 'fa-sun',          'color' => '#f59e0b'],
    'percent_off' => ['label' => '% Discount',    'icon' => 'fa-percent',      'color' => '#10b981'],
    'fixed_off'   => ['label' => 'Fixed Amount',  'icon' => 'fa-tag',          'color' => '#3b82f6'],
    'multi_buy'   => ['label' => 'Multi-Buy',     'icon' => 'fa-layer-group',  'color' => '#8b5cf6'],
    'spend_save'  => ['label' => 'Spend & Save',  'icon' => 'fa-coins',        'color' => '#ec4899'],
    'combo'       => ['label' => 'Combo Deal',    'icon' => 'fa-object-group', 'color' => '#0ea5e9'],
];
$DAY_NAMES = ['','Mon','Tue','Wed','Thu','Fri','Sat','Sun'];
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Deals &amp; Promotions — <?php echo htmlspecialchars($site_name); ?></title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Cormorant+Garamond:ital,wght@0,300;0,400;0,500;0,600;1,300;1,400;1,500&family=Jost:wght@300;400;500;600&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="css/admin-styles.css">
    <link rel="stylesheet" href="css/admin-components.css">
    <style>
        .deals-page { max-width: 1100px; margin: 0 auto; padding: 24px 20px 80px; }
        .deals-head { display:flex; align-items:center; justify-content:space-between; margin-bottom:28px; gap:16px; flex-wrap:wrap; }
        .deals-head h1 { font-size:24px; font-weight:600; margin:0; display:flex; align-items:center; gap:10px; }
        .deals-head p  { color:#666; margin:4px 0 0; font-size:14px; }

        .deals-legend { display:flex; flex-wrap:wrap; gap:8px; margin-bottom:22px; }
        .dl-chip { display:inline-flex; align-items:center; gap:5px; font-size:11px; font-weight:700; padding:4px 11px; border-radius:20px; background:#f3f4f6; color:#374151; letter-spacing:.03em; }
        .dl-chip i { font-size:10px; }

        .deals-grid { display:grid; grid-template-columns:repeat(auto-fill,minmax(310px,1fr)); gap:16px; }
        .deal-card  { background:#fff; border:1.5px solid #e5e7eb; border-radius:14px; padding:18px 20px; position:relative; transition:box-shadow .2s; }
        .deal-card:hover { box-shadow:0 4px 18px rgba(0,0,0,.09); }
        .deal-card.is-inactive { opacity:.5; }
        .deal-card.is-live  { border-color:#10b981; box-shadow:0 0 0 2px rgba(16,185,129,.2); }
        .deal-card.exclusive-card { border-style:dashed; }

        .dc-badge { display:inline-flex; align-items:center; gap:5px; font-size:11px; font-weight:700; letter-spacing:.04em; padding:3px 10px; border-radius:20px; color:#fff; margin-bottom:12px; text-transform:uppercase; }
        .dc-live-dot { width:7px; height:7px; border-radius:50%; background:#10b981; display:inline-block; animation:livePulse 1.4s ease-in-out infinite; margin-left:4px; }
        @keyframes livePulse { 0%,100%{opacity:1;transform:scale(1)} 50%{opacity:.4;transform:scale(.8)} }
        .dc-excl-tag { font-size:9px; background:#fef3c7; color:#92400e; border-radius:4px; padding:2px 5px; font-weight:700; margin-left:4px; vertical-align:middle; }

        .dc-name  { font-size:17px; font-weight:600; color:#1f2937; margin-bottom:4px; }
        .dc-desc  { font-size:13px; color:#6b7280; margin-bottom:10px; min-height:0; }
        .dc-detail { font-size:12px; color:#374151; line-height:1.75; }
        .dc-detail i { width:14px; color:#9ca3af; }
        .dc-actions { display:flex; gap:8px; margin-top:14px; padding-top:12px; border-top:1px solid #f3f4f6; }
        .dc-btn { font-size:12px; font-weight:600; padding:5px 12px; border-radius:7px; border:1px solid #e5e7eb; background:#f9fafb; cursor:pointer; color:#374151; transition:background .15s; }
        .dc-btn.edit  { color:#2563eb; border-color:#bfdbfe; background:#eff6ff; }
        .dc-btn.edit:hover { background:#dbeafe; }
        .dc-btn.del   { color:#dc2626; border-color:#fecaca; background:#fef2f2; }
        .dc-btn.del:hover  { background:#fee2e2; }
        .dc-btn.tog   { min-width:62px; }
        .dc-btn.tog.is-on  { color:#059669; border-color:#a7f3d0; background:#ecfdf5; }
        .dc-btn.tog.is-off { color:#dc2626; border-color:#fecaca; background:#fef2f2; }

        .deals-empty { text-align:center; padding:60px 20px; color:#9ca3af; }
        .deals-empty i { font-size:40px; margin-bottom:12px; display:block; }

        /* ── Modal ── */
        .dm-bg { position:fixed; inset:0; background:rgba(0,0,0,.52); z-index:1000; display:none; align-items:center; justify-content:center; padding:16px; }
        .dm-bg.show { display:flex; }
        .dm-box { background:#fff; border-radius:16px; padding:28px; width:100%; max-width:600px; max-height:92vh; overflow-y:auto; position:relative; }
        .dm-title { font-size:20px; font-weight:600; margin-bottom:20px; }
        .dm-close { position:absolute; top:16px; right:18px; background:none; border:none; font-size:20px; cursor:pointer; color:#6b7280; line-height:1; }
        .fm-row   { margin-bottom:15px; }
        .fm-row label { display:block; font-size:11px; font-weight:700; color:#374151; margin-bottom:5px; text-transform:uppercase; letter-spacing:.04em; }
        .fm-row input[type=text],.fm-row input[type=number],.fm-row input[type=time],
        .fm-row input[type=date],.fm-row select,.fm-row textarea {
            width:100%; box-sizing:border-box; padding:9px 12px; border:1px solid #d1d5db;
            border-radius:8px; font-size:14px; color:#1f2937; background:#fff; outline:none;
            transition:border-color .15s;
        }
        .fm-row input:focus,.fm-row select:focus,.fm-row textarea:focus { border-color:#6366f1; box-shadow:0 0 0 3px rgba(99,102,241,.1); }
        .fm-row textarea { resize:vertical; min-height:56px; }
        .fm-hint { font-size:11px; color:#9ca3af; margin-top:4px; line-height:1.4; }
        .fm-2col { display:grid; grid-template-columns:1fr 1fr; gap:12px; }
        .fm-3col { display:grid; grid-template-columns:1fr 1fr 1fr; gap:10px; }
        .fm-check-row { display:flex; align-items:center; gap:8px; }
        .fm-check-row input[type=checkbox] { width:16px; height:16px; cursor:pointer; accent-color:#6366f1; }
        .fm-check-row label { font-size:14px; font-weight:500; color:#1f2937; margin:0; text-transform:none; letter-spacing:0; }
        .fm-section { background:#f9fafb; border:1px solid #e5e7eb; border-radius:10px; padding:14px; margin-bottom:14px; display:none; }
        .fm-section.show { display:block; }
        .fm-section-title { font-size:11px; font-weight:700; text-transform:uppercase; letter-spacing:.05em; color:#6b7280; margin-bottom:12px; }
        .day-chips { display:flex; flex-wrap:wrap; gap:6px; margin-top:6px; }
        .day-chip  { padding:4px 11px; border-radius:20px; border:1px solid #d1d5db; background:#f9fafb; font-size:12px; font-weight:600; cursor:pointer; color:#374151; user-select:none; }
        .day-chip.sel { background:#6366f1; border-color:#6366f1; color:#fff; }
        .dm-footer { display:flex; gap:10px; justify-content:flex-end; margin-top:22px; padding-top:18px; border-top:1px solid #f3f4f6; }
        .dm-footer .btn-cancel { padding:9px 20px; border-radius:9px; border:1px solid #d1d5db; background:#fff; font-size:14px; cursor:pointer; color:#374151; }
        .dm-footer .btn-save   { padding:9px 24px; border-radius:9px; border:none; background:#6366f1; color:#fff; font-size:14px; font-weight:600; cursor:pointer; }
        .dm-footer .btn-save:hover { background:#4f46e5; }
        .dm-footer .btn-save:disabled { opacity:.6; cursor:not-allowed; }
        @media(max-width:520px) { .fm-2col,.fm-3col { grid-template-columns:1fr; } .dm-box { padding:18px 14px; } }

        /* ── Item Picker ── */
        .ip-wrap  { position:relative; }
        .ip-wrap input { width:100%; box-sizing:border-box; }
        .ip-drop  { position:absolute; top:calc(100% + 4px); left:0; right:0; background:#fff; border:1px solid #d1d5db;
                    border-radius:10px; box-shadow:0 8px 24px rgba(0,0,0,.12); z-index:200; display:none; max-height:220px; overflow-y:auto; }
        .ip-drop.open { display:block; }
        .ip-result { padding:9px 14px; cursor:pointer; display:flex; align-items:center; justify-content:space-between; font-size:13px; color:#1f2937; border-bottom:1px solid #f3f4f6; }
        .ip-result:last-child { border-bottom:none; }
        .ip-result:hover,.ip-result.focused { background:#f0f0ff; }
        .ip-result-cat { font-size:11px; color:#9ca3af; }
        .ip-result-tick { color:#6366f1; font-size:12px; opacity:0; }
        .ip-result.already .ip-result-tick { opacity:1; }
        .ip-result.already { color:#9ca3af; cursor:default; }
        .ip-no-result { padding:10px 14px; font-size:13px; color:#9ca3af; }
        .ip-chips { display:flex; flex-wrap:wrap; gap:6px; margin-top:8px; min-height:0; }
        .ip-chip  { display:inline-flex; align-items:center; gap:6px; background:#ede9fe; color:#5b21b6; border-radius:20px;
                    padding:4px 10px 4px 12px; font-size:12px; font-weight:600; }
        .ip-chip-x { background:none; border:none; cursor:pointer; color:#7c3aed; font-size:13px; line-height:1; padding:0; }
        .ip-chip-x:hover { color:#dc2626; }
        .ip-empty-hint { font-size:12px; color:#9ca3af; padding:4px 0; }
    </style>
</head>
<body>
<?php require_once 'includes/admin-header.php'; ?>
<div class="content deals-page">

    <div class="deals-head">
        <div>
            <h1><i class="fas fa-tags"></i> Deals &amp; Promotions</h1>
            <p>Auto-apply discounts at the POS — no staff action needed. Deals evaluate live every time the cart changes.</p>
        </div>
        <button class="btn btn-primary" onclick="openDealModal()"><i class="fas fa-plus"></i> Add Deal</button>
    </div>

    <div class="deals-legend">
        <?php foreach ($TYPE_META as $k => $m): ?>
        <span class="dl-chip"><i class="fas <?php echo $m['icon']; ?>" style="color:<?php echo $m['color']; ?>"></i> <?php echo $m['label']; ?></span>
        <?php endforeach; ?>
        <span class="dl-chip" style="border:1px dashed #d1d5db;background:none;"><i class="fas fa-lock" style="color:#f59e0b"></i> Exclusive = cannot stack</span>
    </div>

    <?php if (empty($deals)): ?>
    <div class="deals-empty">
        <i class="fas fa-tags"></i>
        <p>No deals yet. Add your first one — happy hour, 2-for-1 drinks, spend &amp; save, combo meals…</p>
    </div>
    <?php else: ?>
    <div class="deals-grid" id="dealsGrid">
        <?php foreach ($deals as $d):
            $tm   = $TYPE_META[$d['deal_type']] ?? ['label'=>$d['deal_type'],'icon'=>'fa-tag','color'=>'#6366f1'];
            $live = deal_active_now($d, $nowTime, $nowDow);
            $on   = (bool)$d['is_active'];
            $excl = (bool)($d['exclusive'] ?? 0);

            // Build detail lines
            $det = [];
            switch ($d['deal_type']) {
                case 'happy_hour':
                case 'percent_off':
                    $det[] = '<i class="fas fa-percent"></i> ' . number_format($d['discount_percent'],0) . '% off';
                    break;
                case 'fixed_off':
                    $det[] = '<i class="fas fa-tag"></i> ' . $sym . ' ' . number_format($d['discount_fixed'],2) . ' off';
                    break;
                case 'multi_buy':
                    $free = (int)$d['multi_buy_qty'] - (int)$d['multi_buy_pay'];
                    $det[] = '<i class="fas fa-layer-group"></i> Buy ' . $d['multi_buy_qty'] . ', pay for ' . $d['multi_buy_pay'] . ' (' . $free . ' free per group)';
                    break;
                case 'spend_save':
                    $thresh = $d['spend_threshold'] ? ($sym . ' ' . number_format($d['spend_threshold'],2) . '+') : 'any spend';
                    if ($d['discount_percent'] > 0) $det[] = '<i class="fas fa-coins"></i> Spend ' . $thresh . ' → ' . number_format($d['discount_percent'],0) . '% off';
                    else $det[] = '<i class="fas fa-coins"></i> Spend ' . $thresh . ' → ' . $sym . ' ' . number_format($d['discount_fixed'],2) . ' off';
                    break;
                case 'combo':
                    $grps = $d['combo_requires'] ? json_decode($d['combo_requires'], true) : [];
                    $gLabels = array_map(fn($g) => implode('+', (array)($g['item_types'] ?? [])) . ' ×' . ($g['min_qty']??1), $grps ?: []);
                    $det[] = '<i class="fas fa-object-group"></i> ' . implode(' &amp; ', $gLabels) . ' → ' . number_format($d['discount_percent'],0) . '% off';
                    break;
            }
            if ($d['start_time'] && $d['end_time'])
                $det[] = '<i class="fas fa-clock"></i> ' . substr($d['start_time'],0,5) . ' – ' . substr($d['end_time'],0,5);
            if ($d['days_of_week']) {
                $days = json_decode($d['days_of_week'], true) ?: []; sort($days);
                $det[] = '<i class="fas fa-calendar-week"></i> ' . implode(', ', array_map(fn($n) => $DAY_NAMES[$n] ?? "D$n", $days));
            }
            if ($d['valid_from'] || $d['valid_to']) {
                $vf = $d['valid_from'] ? date('d M Y', strtotime($d['valid_from'])) : '';
                $vt = $d['valid_to']   ? date('d M Y', strtotime($d['valid_to']))   : '';
                $det[] = '<i class="fas fa-calendar-alt"></i> ' . ($vf && $vt ? "$vf – $vt" : ($vf ? "From $vf" : "Until $vt"));
            }
            if ($d['applies_to'] === 'item_types' && $d['item_types'])
                $det[] = '<i class="fas fa-filter"></i> ' . htmlspecialchars(implode(', ', json_decode($d['item_types'],true) ?: []));
            elseif ($d['applies_to'] === 'items' && $d['item_ids'])
                $det[] = '<i class="fas fa-list"></i> ' . count(json_decode($d['item_ids'],true) ?: []) . ' specific item(s)';
            else
                $det[] = '<i class="fas fa-store"></i> All items';
            if ($d['max_uses_per_order'])
                $det[] = '<i class="fas fa-redo"></i> Max ' . $d['max_uses_per_order'] . ' use(s) per order';
        ?>
        <div class="deal-card <?php echo $on?'':'is-inactive'; ?> <?php echo $live?'is-live':''; ?> <?php echo $excl?'exclusive-card':''; ?>"
             id="deal-card-<?php echo $d['id']; ?>">
            <div>
                <span class="dc-badge" style="background:<?php echo $tm['color']; ?>;">
                    <i class="fas <?php echo $tm['icon']; ?>"></i> <?php echo $tm['label']; ?>
                    <?php if ($live): ?><span class="dc-live-dot" title="Active right now"></span><?php endif; ?>
                </span>
                <?php if ($excl): ?><span class="dc-excl-tag">EXCLUSIVE</span><?php endif; ?>
            </div>
            <div class="dc-name"><?php echo htmlspecialchars($d['name']); ?></div>
            <?php if ($d['description']): ?><div class="dc-desc"><?php echo htmlspecialchars($d['description']); ?></div><?php endif; ?>
            <div class="dc-detail"><?php echo implode('<br>', $det); ?></div>
            <div class="dc-actions">
                <button class="dc-btn edit" onclick="openDealModal(<?php echo $d['id']; ?>)"><i class="fas fa-edit"></i> Edit</button>
                <button class="dc-btn tog <?php echo $on?'is-on':'is-off'; ?>" onclick="toggleDeal(<?php echo $d['id']; ?>, this)">
                    <?php echo $on?'ON':'OFF'; ?>
                </button>
                <button class="dc-btn del" onclick="deleteDeal(<?php echo $d['id']; ?>)"><i class="fas fa-trash"></i></button>
            </div>
        </div>
        <?php endforeach; ?>
    </div>
    <?php endif; ?>
</div>

<!-- Add / Edit Modal -->
<div class="dm-bg" id="dmBg">
<div class="dm-box">
    <div class="dm-title" id="dmTitle">Add Deal</div>
    <button class="dm-close" onclick="closeDealModal()"><i class="fas fa-times"></i></button>

    <!-- Core -->
    <div class="fm-row"><label>Deal Name *</label>
        <input type="text" id="dmName" maxlength="100" placeholder="e.g. Happy Hour Drinks, 3-for-2 Lagers">
    </div>
    <div class="fm-row"><label>Description (optional)</label>
        <textarea id="dmDesc" maxlength="255" placeholder="Shown on receipts and staff screen"></textarea>
    </div>
    <div class="fm-2col">
        <div class="fm-row"><label>Deal Type *</label>
            <select id="dmType" onchange="onTypeChange()">
                <option value="happy_hour">Happy Hour — timed % off</option>
                <option value="percent_off">% Discount — always-on % off</option>
                <option value="fixed_off">Fixed Amount Off</option>
                <option value="multi_buy">Multi-Buy (buy X pay Y)</option>
                <option value="spend_save">Spend &amp; Save — min spend threshold</option>
                <option value="combo">Combo Deal — mix categories</option>
            </select>
        </div>
        <div class="fm-row"><label>Sort Priority</label>
            <input type="number" id="dmSort" value="0" min="0" step="1">
            <div class="fm-hint">Lower = evaluated first</div>
        </div>
    </div>

    <!-- % / fixed discount params -->
    <div class="fm-section" id="dmSecPct">
        <div class="fm-section-title">Discount Amount</div>
        <div class="fm-row"><label>Discount %</label>
            <input type="number" id="dmDiscPct" min="0.1" max="100" step="0.1" placeholder="20">
        </div>
    </div>
    <div class="fm-section" id="dmSecFixed">
        <div class="fm-section-title">Fixed Discount Amount</div>
        <div class="fm-row"><label>Amount off (<?php echo htmlspecialchars($sym); ?>)</label>
            <input type="number" id="dmDiscFixed" min="0.01" step="0.01" placeholder="500.00">
        </div>
    </div>

    <!-- Multi-buy -->
    <div class="fm-section" id="dmSecMb">
        <div class="fm-section-title">Multi-Buy Settings</div>
        <div class="fm-2col">
            <div class="fm-row"><label>Customer Buys (qty)</label>
                <input type="number" id="dmMbQty" min="2" max="20" step="1" value="3">
                <div class="fm-hint">Minimum items in cart to trigger</div>
            </div>
            <div class="fm-row"><label>Customer Pays For</label>
                <input type="number" id="dmMbPay" min="1" max="19" step="1" value="2">
                <div class="fm-hint">Cheapest items above this are free</div>
            </div>
        </div>
        <div class="fm-row"><label>Max groups per order (optional)</label>
            <input type="number" id="dmMaxUses" min="1" max="99" step="1" placeholder="Leave blank = unlimited">
            <div class="fm-hint">e.g. "3 for 2 — max 1 free item per transaction"</div>
        </div>
    </div>

    <!-- Spend & Save -->
    <div class="fm-section" id="dmSecSpend">
        <div class="fm-section-title">Spend &amp; Save Settings</div>
        <div class="fm-row"><label>Minimum cart total (<?php echo htmlspecialchars($sym); ?>)</label>
            <input type="number" id="dmSpendThreshold" min="0.01" step="0.01" placeholder="10000.00">
        </div>
        <div class="fm-row"><label>Reward type</label>
            <select id="dmSpendRewardType" onchange="onSpendRewardChange()">
                <option value="pct">Percentage off</option>
                <option value="fixed">Fixed amount off</option>
            </select>
        </div>
        <div id="dmSpendPctRow" class="fm-row"><label>Discount %</label>
            <input type="number" id="dmSpendPct" min="0.1" max="100" step="0.1" placeholder="10">
        </div>
        <div id="dmSpendFixedRow" class="fm-row" style="display:none;"><label>Amount off (<?php echo htmlspecialchars($sym); ?>)</label>
            <input type="number" id="dmSpendFixed" min="0.01" step="0.01" placeholder="1000.00">
        </div>
    </div>

    <!-- Combo -->
    <div class="fm-section" id="dmSecCombo">
        <div class="fm-section-title">Combo Requirements</div>
        <p style="font-size:13px;color:#6b7280;margin:0 0 12px;">Define the groups of items that must ALL be in the cart to trigger this deal.</p>
        <div id="dmComboGroups"></div>
        <button type="button" onclick="addComboGroup()" style="font-size:12px;padding:5px 12px;border-radius:7px;border:1px dashed #d1d5db;background:#f9fafb;cursor:pointer;color:#374151;margin-bottom:12px;"><i class="fas fa-plus"></i> Add Group</button>
        <div class="fm-row"><label>Discount %</label>
            <input type="number" id="dmComboPct" min="0.1" max="100" step="0.1" placeholder="15">
        </div>
    </div>

    <!-- Time window -->
    <div class="fm-section" id="dmSecTime">
        <div class="fm-section-title">Time Window</div>
        <div class="fm-2col">
            <div class="fm-row"><label>Start Time</label>
                <input type="time" id="dmStartTime">
            </div>
            <div class="fm-row"><label>End Time</label>
                <input type="time" id="dmEndTime">
            </div>
        </div>
    </div>

    <!-- Scope -->
    <div class="fm-section show" id="dmSecScope">
        <div class="fm-section-title">Applies To</div>
        <div class="fm-row"><label>Scope</label>
            <select id="dmAppliesTo" onchange="onScopeChange()">
                <option value="all">All menu items</option>
                <option value="item_types">Specific item types</option>
                <option value="items">Specific items (by menu ID)</option>
            </select>
        </div>
        <div id="dmItemTypesRow" class="fm-row" style="display:none;"><label>Item Types (comma-separated)</label>
            <input type="text" id="dmItemTypes" placeholder="food, drink">
            <div class="fm-hint">Type exactly as they appear in the menu category slug: <code>food</code>, <code>drink</code></div>
        </div>
        <div id="dmItemIdsRow" style="display:none;">
            <div class="fm-row" style="margin-bottom:8px;">
                <label>Search &amp; Add Items</label>
                <div class="ip-wrap">
                    <input type="text" id="dmItemSearch" placeholder="Type item name e.g. Coca Cola…" autocomplete="off"
                           oninput="ipSearch(this.value)" onkeydown="ipKeyNav(event)">
                    <div class="ip-drop" id="ipDrop"></div>
                </div>
            </div>
            <div class="ip-chips" id="ipChips"><span class="ip-empty-hint">No items selected — all items qualify</span></div>
            <input type="hidden" id="dmItemIds" value="">
        </div>
    </div>

    <!-- Days of week -->
    <div class="fm-section show" id="dmSecDays">
        <div class="fm-section-title">Days Active</div>
        <div class="fm-hint" style="margin-bottom:8px;">Leave all unselected = every day</div>
        <div class="day-chips" id="dmDayChips">
            <?php foreach ([1=>'Mon',2=>'Tue',3=>'Wed',4=>'Thu',5=>'Fri',6=>'Sat',7=>'Sun'] as $n=>$lbl): ?>
            <span class="day-chip" data-day="<?php echo $n; ?>" onclick="toggleDay(this)"><?php echo $lbl; ?></span>
            <?php endforeach; ?>
        </div>
    </div>

    <!-- Date range -->
    <div class="fm-section show" id="dmSecDates">
        <div class="fm-section-title">Valid Date Range (optional)</div>
        <div class="fm-2col">
            <div class="fm-row"><label>Valid From</label><input type="date" id="dmValidFrom"></div>
            <div class="fm-row"><label>Valid To</label><input type="date" id="dmValidTo"></div>
        </div>
    </div>

    <!-- Flags -->
    <div class="fm-section show">
        <div class="fm-section-title">Options</div>
        <div style="display:flex;flex-wrap:wrap;gap:20px;">
            <div class="fm-check-row">
                <input type="checkbox" id="dmIsActive" checked>
                <label for="dmIsActive">Deal is active</label>
            </div>
            <div class="fm-check-row">
                <input type="checkbox" id="dmExclusive">
                <label for="dmExclusive">Exclusive (cannot stack with other deals)</label>
            </div>
        </div>
    </div>

    <div class="dm-footer">
        <button class="btn-cancel" onclick="closeDealModal()">Cancel</button>
        <button class="btn-save" id="dmSaveBtn" onclick="saveDeal()"><i class="fas fa-save"></i> Save Deal</button>
    </div>
</div>
</div>

<script>
const _dealsData = <?php echo json_encode(array_values($deals), JSON_HEX_TAG | JSON_HEX_AMP); ?>;
const _dealsCsrf = <?php echo json_encode($csrf_token); ?>;
let _dmEditId = 0;
let _comboGroupCount = 0;

function openDealModal(id) {
    _dmEditId = id || 0;
    _comboGroupCount = 0;
    document.getElementById('dmTitle').textContent = id ? 'Edit Deal' : 'Add Deal';
    const d = id ? _dealsData.find(x => +x.id === +id) : null;

    document.getElementById('dmName').value      = d ? d.name : '';
    document.getElementById('dmDesc').value      = d ? (d.description || '') : '';
    document.getElementById('dmType').value      = d ? d.deal_type : 'happy_hour';
    document.getElementById('dmSort').value      = d ? (d.sort_order || 0) : 0;
    document.getElementById('dmDiscPct').value   = d ? (d.discount_percent || '') : '';
    document.getElementById('dmDiscFixed').value = d ? (d.discount_fixed || '') : '';
    document.getElementById('dmMbQty').value     = d ? (d.multi_buy_qty || 3) : 3;
    document.getElementById('dmMbPay').value     = d ? (d.multi_buy_pay || 2) : 2;
    document.getElementById('dmMaxUses').value   = d ? (d.max_uses_per_order || '') : '';
    document.getElementById('dmSpendThreshold').value = d ? (d.spend_threshold || '') : '';
    document.getElementById('dmComboPct').value  = d ? (d.discount_percent || '') : '';
    document.getElementById('dmStartTime').value = d ? (d.start_time ? d.start_time.slice(0,5) : '') : '';
    document.getElementById('dmEndTime').value   = d ? (d.end_time   ? d.end_time.slice(0,5)   : '') : '';
    document.getElementById('dmValidFrom').value = d ? (d.valid_from || '') : '';
    document.getElementById('dmValidTo').value   = d ? (d.valid_to   || '') : '';
    document.getElementById('dmAppliesTo').value = d ? (d.applies_to || 'all') : 'all';
    document.getElementById('dmItemTypes').value = (d && d.item_types) ? (Array.isArray(d.item_types)?d.item_types:JSON.parse(d.item_types)).join(', ') : '';

    // Item picker — load names for saved IDs
    _ipItems = [];
    ipRender();
    document.getElementById('dmItemSearch').value = '';
    ipCloseDrop();
    if (d && d.applies_to === 'items' && d.item_ids) {
        const ids = Array.isArray(d.item_ids) ? d.item_ids : JSON.parse(d.item_ids);
        if (ids.length) {
            fetch('deals.php?aj=items&ids=' + ids.join(','))
                .then(r => r.json()).then(rows => { _ipItems = rows.map(r => ({id:+r.id, name:r.name, category:r.category})); ipRender(); });
        }
    }
    document.getElementById('dmIsActive').checked  = d ? !!+d.is_active : true;
    document.getElementById('dmExclusive').checked = d ? !!+d.exclusive  : false;

    // Days
    const dow = (d && d.days_of_week) ? (Array.isArray(d.days_of_week) ? d.days_of_week : JSON.parse(d.days_of_week)) : [];
    document.querySelectorAll('.day-chip').forEach(c => c.classList.toggle('sel', dow.map(Number).includes(+c.dataset.day)));

    // Spend reward type
    if (d && d.deal_type === 'spend_save') {
        const isFix = (parseFloat(d.discount_fixed)||0) > 0 && (parseFloat(d.discount_percent)||0) === 0;
        document.getElementById('dmSpendRewardType').value = isFix ? 'fixed' : 'pct';
        document.getElementById('dmSpendPct').value   = d.discount_percent || '';
        document.getElementById('dmSpendFixed').value = d.discount_fixed || '';
    } else {
        document.getElementById('dmSpendRewardType').value = 'pct';
    }
    onSpendRewardChange();

    // Combo groups
    document.getElementById('dmComboGroups').innerHTML = '';
    _comboGroupCount = 0;
    if (d && d.combo_requires) {
        const grps = Array.isArray(d.combo_requires) ? d.combo_requires : JSON.parse(d.combo_requires);
        grps.forEach(g => addComboGroup(g));
    } else if (!d || d.deal_type === 'combo') {
        addComboGroup(); addComboGroup(); // default 2 groups
    }

    onTypeChange();
    onScopeChange();
    document.getElementById('dmBg').classList.add('show');
    setTimeout(() => document.getElementById('dmName').focus(), 50);
}

function closeDealModal() {
    document.getElementById('dmBg').classList.remove('show');
    _dmEditId = 0;
}

function onTypeChange() {
    const t = document.getElementById('dmType').value;
    const show = id => document.getElementById(id).classList.toggle('show', true);
    const hide = id => document.getElementById(id).classList.remove('show');
    // Hide all type-specific sections
    ['dmSecPct','dmSecFixed','dmSecMb','dmSecSpend','dmSecTime','dmSecCombo'].forEach(hide);
    if (t === 'happy_hour')  { show('dmSecPct');   show('dmSecTime'); }
    if (t === 'percent_off') { show('dmSecPct'); }
    if (t === 'fixed_off')   { show('dmSecFixed'); }
    if (t === 'multi_buy')   { show('dmSecMb'); }
    if (t === 'spend_save')  { show('dmSecSpend'); }
    if (t === 'combo')       { show('dmSecCombo'); }
}

function onScopeChange() {
    const v = document.getElementById('dmAppliesTo').value;
    document.getElementById('dmItemTypesRow').style.display = v === 'item_types' ? '' : 'none';
    document.getElementById('dmItemIdsRow').style.display   = v === 'items'      ? '' : 'none';
}

function onSpendRewardChange() {
    const v = document.getElementById('dmSpendRewardType').value;
    document.getElementById('dmSpendPctRow').style.display   = v === 'pct'   ? '' : 'none';
    document.getElementById('dmSpendFixedRow').style.display = v === 'fixed' ? '' : 'none';
}

function toggleDay(el) { el.classList.toggle('sel'); }

function addComboGroup(data) {
    _comboGroupCount++;
    const i   = _comboGroupCount;
    const div = document.createElement('div');
    div.id = 'cmb-grp-' + i;
    div.style.cssText = 'background:#fff;border:1px solid #e5e7eb;border-radius:8px;padding:10px 12px;margin-bottom:10px;position:relative;';
    div.innerHTML = `
        <div style="display:flex;align-items:center;gap:8px;margin-bottom:8px;">
            <strong style="font-size:12px;color:#374151;">Group ${i}</strong>
            <button type="button" onclick="this.closest('[id^=cmb-grp]').remove()" style="margin-left:auto;background:none;border:none;color:#9ca3af;cursor:pointer;font-size:14px;line-height:1;">✕</button>
        </div>
        <div class="fm-2col">
            <div class="fm-row"><label>Item Types (comma-sep)</label>
                <input type="text" class="cmb-types" placeholder="food" value="${data && data.item_types ? data.item_types.join(', ') : ''}">
            </div>
            <div class="fm-row"><label>Min Qty</label>
                <input type="number" class="cmb-qty" min="1" value="${data && data.min_qty ? data.min_qty : 1}">
            </div>
        </div>`;
    document.getElementById('dmComboGroups').appendChild(div);
}

function buildComboJson() {
    const groups = [];
    document.querySelectorAll('[id^=cmb-grp-]').forEach(div => {
        const types = div.querySelector('.cmb-types').value.split(',').map(s=>s.trim()).filter(Boolean);
        const qty   = parseInt(div.querySelector('.cmb-qty').value, 10) || 1;
        if (types.length) groups.push({ item_types: types, min_qty: qty });
    });
    return JSON.stringify(groups);
}

async function saveDeal() {
    const name = document.getElementById('dmName').value.trim();
    if (!name) { alert('Please enter a deal name.'); return; }
    const type = document.getElementById('dmType').value;

    const data = new FormData();
    data.append('ajax_action', 'save');
    data.append('csrf_token', _dealsCsrf);
    data.append('id', _dmEditId);
    data.append('name', name);
    data.append('description', document.getElementById('dmDesc').value.trim());
    data.append('deal_type', type);
    data.append('sort_order', document.getElementById('dmSort').value || 0);

    // Discount params by type
    if (type === 'happy_hour' || type === 'percent_off') {
        data.append('discount_percent', document.getElementById('dmDiscPct').value || 0);
        data.append('discount_fixed', 0);
    } else if (type === 'fixed_off') {
        data.append('discount_percent', 0);
        data.append('discount_fixed', document.getElementById('dmDiscFixed').value || 0);
    } else if (type === 'multi_buy') {
        data.append('discount_percent', 0); data.append('discount_fixed', 0);
        data.append('multi_buy_qty', document.getElementById('dmMbQty').value);
        data.append('multi_buy_pay', document.getElementById('dmMbPay').value);
        data.append('max_uses_per_order', document.getElementById('dmMaxUses').value);
    } else if (type === 'spend_save') {
        data.append('spend_threshold', document.getElementById('dmSpendThreshold').value || 0);
        const rt = document.getElementById('dmSpendRewardType').value;
        data.append('discount_percent', rt === 'pct'   ? (document.getElementById('dmSpendPct').value || 0) : 0);
        data.append('discount_fixed',   rt === 'fixed' ? (document.getElementById('dmSpendFixed').value || 0) : 0);
    } else if (type === 'combo') {
        data.append('combo_requires', buildComboJson());
        data.append('discount_percent', document.getElementById('dmComboPct').value || 0);
        data.append('discount_fixed', 0);
    } else {
        data.append('discount_percent', 0); data.append('discount_fixed', 0);
    }

    // Time
    data.append('start_time', document.getElementById('dmStartTime').value);
    data.append('end_time',   document.getElementById('dmEndTime').value);
    data.append('valid_from', document.getElementById('dmValidFrom').value);
    data.append('valid_to',   document.getElementById('dmValidTo').value);

    // Scope
    data.append('applies_to', document.getElementById('dmAppliesTo').value);
    data.append('item_types', document.getElementById('dmItemTypes').value);
    data.append('item_ids',   document.getElementById('dmItemIds').value);

    // Days
    const selDays = [...document.querySelectorAll('.day-chip.sel')].map(c => c.dataset.day).join(',');
    data.append('days_of_week', selDays);

    // Flags
    if (document.getElementById('dmIsActive').checked)  data.append('is_active', '1');
    if (document.getElementById('dmExclusive').checked) data.append('exclusive', '1');

    const btn = document.getElementById('dmSaveBtn');
    btn.disabled = true; btn.textContent = 'Saving…';
    try {
        const res  = await fetch('deals.php', { method: 'POST', body: data });
        const json = await res.json();
        if (json.ok) { closeDealModal(); location.reload(); }
        else { alert(json.error || 'Save failed.'); }
    } catch(e) { alert('Network error.'); }
    btn.disabled = false; btn.innerHTML = '<i class="fas fa-save"></i> Save Deal';
}

async function toggleDeal(id, btn) {
    const fd = new FormData();
    fd.append('ajax_action', 'toggle');
    fd.append('csrf_token', _dealsCsrf);
    fd.append('id', id);
    btn.disabled = true;
    try {
        const r = await fetch('deals.php', { method: 'POST', body: fd }).then(r=>r.json());
        if (r.ok) {
            const on = r.active === 1;
            btn.textContent = on ? 'ON' : 'OFF';
            btn.classList.toggle('is-on', on); btn.classList.toggle('is-off', !on);
            document.getElementById('deal-card-' + id).classList.toggle('is-inactive', !on);
        }
    } catch(e) {}
    btn.disabled = false;
}

async function deleteDeal(id) {
    if (!confirm('Delete this deal? It will stop applying immediately on the POS.')) return;
    const fd = new FormData();
    fd.append('ajax_action', 'delete');
    fd.append('csrf_token', _dealsCsrf);
    fd.append('id', id);
    try {
        const r = await fetch('deals.php', { method: 'POST', body: fd }).then(r=>r.json());
        if (r.ok) { const c = document.getElementById('deal-card-' + id); if (c) c.remove(); }
        else alert(r.error || 'Delete failed.');
    } catch(e) { alert('Network error.'); }
}

document.getElementById('dmBg').addEventListener('click', e => { if (e.target === document.getElementById('dmBg')) closeDealModal(); });

/* ── Item Picker ──────────────────────────────────────────────────────────── */
let _ipItems  = [];   // [{id, name, category}]
let _ipTimer  = null;
let _ipFocIdx = -1;
let _ipResults = [];

function ipSearch(q) {
    clearTimeout(_ipTimer);
    q = q.trim();
    if (!q) { ipCloseDrop(); return; }
    _ipTimer = setTimeout(() => ipDoSearch(q), 220);
}

async function ipDoSearch(q) {
    try {
        const rows = await fetch('deals.php?aj=items&q=' + encodeURIComponent(q)).then(r => r.json());
        _ipResults = rows;
        _ipFocIdx  = -1;
        const drop = document.getElementById('ipDrop');
        if (!rows.length) {
            drop.innerHTML = '<div class="ip-no-result">No items found for "' + q.replace(/</g,'&lt;') + '"</div>';
        } else {
            drop.innerHTML = rows.map((r, i) => {
                const already = _ipItems.some(x => x.id === +r.id);
                return `<div class="ip-result${already?' already':''}" data-idx="${i}" onclick="ipAdd(${r.id},${JSON.stringify(r.name)},${JSON.stringify(r.category||'')})">
                    <span>${r.name.replace(/</g,'&lt;')}<br><span class="ip-result-cat">${(r.category||'').replace(/</g,'&lt;')}</span></span>
                    <span class="ip-result-tick">&#10003; added</span>
                </div>`;
            }).join('');
        }
        drop.classList.add('open');
    } catch(e) {}
}

function ipAdd(id, name, category) {
    if (_ipItems.some(x => x.id === +id)) return;
    _ipItems.push({id: +id, name, category});
    ipRender();
    ipCloseDrop();
    document.getElementById('dmItemSearch').value = '';
    document.getElementById('dmItemSearch').focus();
}

function ipRemove(id) {
    _ipItems = _ipItems.filter(x => x.id !== +id);
    ipRender();
}

function ipRender() {
    const chips  = document.getElementById('ipChips');
    const hidden = document.getElementById('dmItemIds');
    hidden.value = _ipItems.map(x => x.id).join(',');
    if (!_ipItems.length) {
        chips.innerHTML = '<span class="ip-empty-hint">No items selected — deal applies to all items</span>';
        return;
    }
    chips.innerHTML = _ipItems.map(x =>
        `<span class="ip-chip" title="${x.category||''}">${x.name.replace(/</g,'&lt;')}
            <button type="button" class="ip-chip-x" onclick="ipRemove(${x.id})" title="Remove">&#215;</button>
        </span>`
    ).join('');
}

function ipCloseDrop() {
    document.getElementById('ipDrop').classList.remove('open');
    document.getElementById('ipDrop').innerHTML = '';
    _ipResults = []; _ipFocIdx = -1;
}

function ipKeyNav(e) {
    const drop = document.getElementById('ipDrop');
    const items = drop.querySelectorAll('.ip-result:not(.already)');
    if (!items.length) return;
    if (e.key === 'ArrowDown') {
        e.preventDefault();
        _ipFocIdx = Math.min(_ipFocIdx + 1, items.length - 1);
    } else if (e.key === 'ArrowUp') {
        e.preventDefault();
        _ipFocIdx = Math.max(_ipFocIdx - 1, 0);
    } else if (e.key === 'Enter' && _ipFocIdx >= 0) {
        e.preventDefault();
        items[_ipFocIdx].click();
        return;
    } else if (e.key === 'Escape') {
        ipCloseDrop(); return;
    } else { return; }
    items.forEach((el, i) => el.classList.toggle('focused', i === _ipFocIdx));
    items[_ipFocIdx]?.scrollIntoView({block:'nearest'});
}

document.addEventListener('click', e => {
    if (!e.target.closest('#dmItemIdsRow')) ipCloseDrop();
});
</script>
<?php require_once 'includes/admin-flash.php'; ?>
</body>
</html>
