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

// Load categories and menu items for the item picker — scoped to the active
// preset's catalog context (a hotel's deals pick from Food/Drinks, a
// supermarket's from its retail categories; never each other's).
$dealsCatalogContext = (function_exists('isRestaurantEnabled') && isRestaurantEnabled()) ? 'food_service' : 'retail';
$menuCatsRaw = $pdo->query("
    SELECT id, name, slug FROM menu_categories
    WHERE is_active = 1 AND COALESCE(business_context, 'food_service') = " . $pdo->quote($dealsCatalogContext) . "
    ORDER BY sort_order ASC, name ASC
")->fetchAll(PDO::FETCH_ASSOC);

$menuItemsRaw = $pdo->query("
    SELECT mi.id, mi.item_name AS name, mi.category_id
    FROM menu_items mi
    JOIN menu_categories mc ON mc.id = mi.category_id
    WHERE mc.is_active = 1 AND mi.is_available = 1
      AND COALESCE(mc.business_context, 'food_service') = " . $pdo->quote($dealsCatalogContext) . "
      AND (mi.show_pos = 1 OR mi.show_room_service = 1)
    ORDER BY mi.item_name ASC
")->fetchAll(PDO::FETCH_ASSOC);

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
    <link rel="stylesheet" href="css/admin-styles.css?v=<?php echo @filemtime(__DIR__ . '/css/admin-styles.css'); ?>">
    <link rel="stylesheet" href="css/admin-components.css?v=<?php echo @filemtime(__DIR__ . '/css/admin-components.css'); ?>">
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

        /* ── Modal shell ── */
        .dm-bg  { position:fixed; inset:0; background:rgba(0,0,0,.52); z-index:1000; display:none; align-items:center; justify-content:center; padding:16px; }
        .dm-bg.show { display:flex; }
        .dm-box { background:#fff; border-radius:18px; width:100%; max-width:640px; max-height:94vh; overflow-y:auto; position:relative; }
        .dm-header { padding:22px 24px 0; position:sticky; top:0; background:#fff; z-index:2; border-bottom:1px solid #f3f4f6; padding-bottom:14px; }
        .dm-title  { font-size:19px; font-weight:700; color:#111827; margin:0 32px 4px 0; }
        .dm-subtitle { font-size:12px; color:#9ca3af; }
        .dm-close  { position:absolute; top:16px; right:18px; background:none; border:none; font-size:18px; cursor:pointer; color:#9ca3af; line-height:1; }
        .dm-close:hover { color:#374151; }
        .dm-body   { padding:20px 24px; }
        /* Form primitives */
        .fm-row   { margin-bottom:16px; }
        .fm-row label,.fm-label { display:block; font-size:11px; font-weight:700; color:#374151; margin-bottom:6px; text-transform:uppercase; letter-spacing:.05em; }
        .fm-row input[type=text],.fm-row input[type=number],.fm-row input[type=time],
        .fm-row input[type=date],.fm-row select,.fm-row textarea {
            width:100%; box-sizing:border-box; padding:10px 12px; border:1px solid #d1d5db;
            border-radius:9px; font-size:14px; color:#1f2937; background:#fff; outline:none; transition:border-color .15s;
        }
        .fm-row input:focus,.fm-row select:focus,.fm-row textarea:focus { border-color:#6366f1; box-shadow:0 0 0 3px rgba(99,102,241,.1); }
        .fm-row textarea { resize:vertical; min-height:52px; }
        .fm-hint  { font-size:11px; color:#9ca3af; margin-top:4px; line-height:1.5; }
        .fm-2col  { display:grid; grid-template-columns:1fr 1fr; gap:12px; }
        .fm-section { background:#f9fafb; border:1px solid #e5e7eb; border-radius:12px; padding:16px; margin-bottom:14px; display:none; }
        .fm-section.show { display:block; }
        .fm-sec-head { font-size:12px; font-weight:700; color:#374151; margin-bottom:12px; display:flex; align-items:center; gap:7px; }
        .fm-sec-head i { font-size:13px; }
        .fm-check-row { display:flex; align-items:center; gap:9px; }
        .fm-check-row input[type=checkbox] { width:17px; height:17px; cursor:pointer; accent-color:#6366f1; flex-shrink:0; }
        .fm-check-row label { font-size:14px; font-weight:500; color:#1f2937; margin:0; text-transform:none; letter-spacing:0; cursor:pointer; }
        /* Deal type cards */
        .dm-type-grid { display:grid; grid-template-columns:repeat(3,1fr); gap:8px; margin-bottom:16px; }
        .dm-type-card { border:2px solid #e5e7eb; border-radius:12px; padding:12px 10px; cursor:pointer; transition:all .15s; text-align:center; background:#fff; user-select:none; }
        .dm-type-card:hover { border-color:#a5b4fc; background:#f5f3ff; }
        .dm-type-card.sel { border-color:#6366f1; background:#f0f0ff; }
        .dm-type-card .dtc-icon { font-size:20px; margin-bottom:5px; }
        .dm-type-card .dtc-label { font-size:12px; font-weight:700; color:#374151; line-height:1.2; }
        .dm-type-card .dtc-eg { font-size:10px; color:#9ca3af; margin-top:3px; line-height:1.3; }
        .dm-type-card.sel .dtc-label { color:#4338ca; }
        /* Scope option cards */
        .dm-scope-opts { display:grid; grid-template-columns:repeat(3,1fr); gap:8px; margin-bottom:12px; }
        .dm-scope-card { border:2px solid #e5e7eb; border-radius:10px; padding:10px; cursor:pointer; transition:all .15s; text-align:center; background:#fff; user-select:none; }
        .dm-scope-card:hover { border-color:#a5b4fc; }
        .dm-scope-card.sel { border-color:#6366f1; background:#f0f0ff; }
        .dm-scope-card i { font-size:16px; color:#6b7280; margin-bottom:4px; display:block; }
        .dm-scope-card.sel i { color:#6366f1; }
        .dm-scope-card .dsc-label { font-size:11px; font-weight:700; color:#374151; }
        .dm-scope-card .dsc-sub { font-size:10px; color:#9ca3af; margin-top:2px; }
        /* Day chips */
        .day-chips { display:flex; flex-wrap:wrap; gap:6px; margin-top:6px; }
        .day-chip  { padding:5px 12px; border-radius:20px; border:1.5px solid #d1d5db; background:#f9fafb; font-size:12px; font-weight:600; cursor:pointer; color:#374151; user-select:none; transition:all .12s; }
        .day-chip.sel { background:#6366f1; border-color:#6366f1; color:#fff; }
        /* Category type chips (for "by category" scope) */
        .ct-chips { display:flex; flex-wrap:wrap; gap:7px; margin-top:8px; }
        .ct-chip  { display:inline-flex; align-items:center; gap:5px; padding:5px 13px; border-radius:20px; border:1.5px solid #d1d5db; background:#f9fafb; font-size:12px; font-weight:600; cursor:pointer; color:#374151; user-select:none; transition:all .12s; }
        .ct-chip.sel { background:#6366f1; border-color:#6366f1; color:#fff; }
        .ct-chip i { font-size:10px; }
        /* Multi-buy preview */
        .mb-preview { background:#ede9fe; border-radius:8px; padding:9px 13px; font-size:13px; color:#4c1d95; margin-top:10px; display:none; }
        .mb-preview strong { color:#6d28d9; }
        /* Footer */
        .dm-footer { padding:16px 24px; border-top:1px solid #f3f4f6; display:flex; gap:10px; justify-content:flex-end; position:sticky; bottom:0; background:#fff; }
        .dm-footer .btn-cancel { padding:10px 20px; border-radius:10px; border:1px solid #d1d5db; background:#fff; font-size:14px; cursor:pointer; color:#374151; }
        .dm-footer .btn-save   { padding:10px 24px; border-radius:10px; border:none; background:#6366f1; color:#fff; font-size:14px; font-weight:600; cursor:pointer; }
        .dm-footer .btn-save:hover { background:#4f46e5; }
        .dm-footer .btn-save:disabled { opacity:.55; cursor:not-allowed; }
        @media(max-width:560px) { .dm-type-grid,.dm-scope-opts { grid-template-columns:repeat(2,1fr); } .dm-box { border-radius:14px; } .fm-2col { grid-template-columns:1fr; } }

        /* ── Item Picker ── */
        .ip-row   { display:grid; grid-template-columns:1fr 1fr auto; gap:8px; align-items:flex-end; margin-bottom:10px; }
        .ip-row select { width:100%; box-sizing:border-box; padding:9px 12px; border:1px solid #d1d5db; border-radius:8px; font-size:14px; color:#1f2937; background:#fff; }
        .ip-row select:focus { border-color:#6366f1; box-shadow:0 0 0 3px rgba(99,102,241,.1); outline:none; }
        .ip-add-btn { padding:9px 16px; border-radius:8px; border:none; background:#6366f1; color:#fff; font-size:13px; font-weight:600; cursor:pointer; white-space:nowrap; }
        .ip-add-btn:hover { background:#4f46e5; }
        .ip-add-btn:disabled { opacity:.45; cursor:not-allowed; }
        .ip-chips { display:flex; flex-wrap:wrap; gap:6px; margin-top:4px; }
        .ip-chip  { display:inline-flex; align-items:center; gap:6px; background:#ede9fe; color:#5b21b6; border-radius:20px;
                    padding:4px 10px 4px 12px; font-size:12px; font-weight:600; }
        .ip-chip-x { background:none; border:none; cursor:pointer; color:#7c3aed; font-size:14px; line-height:1; padding:0; }
        .ip-chip-x:hover { color:#dc2626; }
        .ip-empty-hint { font-size:12px; color:#9ca3af; padding:4px 0; }
        @media(max-width:520px) { .ip-row { grid-template-columns:1fr; } }
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

<!-- Add / Edit Modal -->
<div class="dm-bg" id="dmBg">
<div class="dm-box">

    <div class="dm-header">
        <div class="dm-title" id="dmTitle">Add Deal</div>
        <div class="dm-subtitle">Deals apply automatically at the POS — no staff action needed.</div>
        <button class="dm-close" onclick="closeDealModal()"><i class="fas fa-times"></i></button>
    </div>

    <div class="dm-body">

    <!-- 1. Name & description -->
    <div class="fm-row"><label>Deal Name *</label>
        <input type="text" id="dmName" maxlength="100" placeholder="e.g. Happy Hour, Buy 3 Get 1 Free Coke">
    </div>
    <div class="fm-row"><label>Short Description <span style="font-weight:400;text-transform:none;letter-spacing:0;">(optional — shown on receipt)</span></label>
        <textarea id="dmDesc" maxlength="255" rows="2" placeholder="e.g. Half-price drinks every evening 5–8pm"></textarea>
    </div>

    <!-- 2. Deal type visual cards -->
    <div class="fm-label" style="margin-bottom:8px;">What kind of deal is this? *</div>
    <div class="dm-type-grid" id="dmTypeGrid">
        <div class="dm-type-card sel" data-type="happy_hour" onclick="selectDealType('happy_hour')">
            <div class="dtc-icon">⏰</div>
            <div class="dtc-label">Happy Hour</div>
            <div class="dtc-eg">% off during set hours</div>
        </div>
        <div class="dm-type-card" data-type="percent_off" onclick="selectDealType('percent_off')">
            <div class="dtc-icon">%</div>
            <div class="dtc-label">Percentage Off</div>
            <div class="dtc-eg">Always-on % discount</div>
        </div>
        <div class="dm-type-card" data-type="fixed_off" onclick="selectDealType('fixed_off')">
            <div class="dtc-icon">🔖</div>
            <div class="dtc-label">Fixed Amount Off</div>
            <div class="dtc-eg">e.g. <?php echo htmlspecialchars($sym); ?> 500 off</div>
        </div>
        <div class="dm-type-card" data-type="multi_buy" onclick="selectDealType('multi_buy')">
            <div class="dtc-icon">🎁</div>
            <div class="dtc-label">Buy X Get Y Free</div>
            <div class="dtc-eg">e.g. Buy 3, get 1 free</div>
        </div>
        <div class="dm-type-card" data-type="spend_save" onclick="selectDealType('spend_save')">
            <div class="dtc-icon">💰</div>
            <div class="dtc-label">Spend &amp; Save</div>
            <div class="dtc-eg">Reward when cart hits a minimum</div>
        </div>
        <div class="dm-type-card" data-type="combo" onclick="selectDealType('combo')">
            <div class="dtc-icon">🍽</div>
            <div class="dtc-label">Combo Deal</div>
            <div class="dtc-eg">Buy from different categories together</div>
        </div>
    </div>
    <input type="hidden" id="dmType" value="happy_hour">

    <!-- 3. Happy Hour / % off params -->
    <div class="fm-section" id="dmSecPct">
        <div class="fm-sec-head"><i class="fas fa-percent" style="color:#f59e0b;"></i> How much off?</div>
        <div class="fm-row">
            <label>Discount percentage</label>
            <input type="number" id="dmDiscPct" min="0.1" max="100" step="0.1" placeholder="e.g. 20 for 20% off">
        </div>
    </div>

    <!-- 4. Fixed amount off -->
    <div class="fm-section" id="dmSecFixed">
        <div class="fm-sec-head"><i class="fas fa-tag" style="color:#3b82f6;"></i> How much off?</div>
        <div class="fm-row">
            <label>Amount to knock off (<?php echo htmlspecialchars($sym); ?>)</label>
            <input type="number" id="dmDiscFixed" min="0.01" step="0.01" placeholder="e.g. 500.00">
        </div>
    </div>

    <!-- 5. Multi-buy -->
    <div class="fm-section" id="dmSecMb">
        <div class="fm-sec-head"><i class="fas fa-gifts" style="color:#8b5cf6;"></i> Buy X, Pay for Y — set the numbers</div>
        <div class="fm-2col">
            <div class="fm-row">
                <label>Customer buys this many</label>
                <input type="number" id="dmMbQty" min="2" max="20" step="1" value="3" oninput="updateMbPreview()">
                <div class="fm-hint">Total items needed in cart to trigger the deal</div>
            </div>
            <div class="fm-row">
                <label>But only pays for this many</label>
                <input type="number" id="dmMbPay" min="1" max="19" step="1" value="2" oninput="updateMbPreview()">
                <div class="fm-hint">The cheapest remaining items are free</div>
            </div>
        </div>
        <div class="mb-preview" id="mbPreview"></div>
        <div class="fm-row" style="margin-top:12px;">
            <label>Limit — max times this deal fires per order <span style="font-weight:400;text-transform:none;letter-spacing:0;">(optional)</span></label>
            <input type="number" id="dmMaxUses" min="1" max="99" step="1" placeholder="Leave blank for unlimited">
            <div class="fm-hint">e.g. set to 1 so a customer can only get 1 free item even if they buy 9</div>
        </div>
    </div>

    <!-- 6. Spend & Save -->
    <div class="fm-section" id="dmSecSpend">
        <div class="fm-sec-head"><i class="fas fa-coins" style="color:#ec4899;"></i> Spend threshold &amp; reward</div>
        <div class="fm-row">
            <label>Minimum cart total to qualify (<?php echo htmlspecialchars($sym); ?>)</label>
            <input type="number" id="dmSpendThreshold" min="0.01" step="0.01" placeholder="e.g. 10000.00">
        </div>
        <div class="fm-row">
            <label>Reward type</label>
            <select id="dmSpendRewardType" onchange="onSpendRewardChange()">
                <option value="pct">Give a percentage off</option>
                <option value="fixed">Give a fixed amount off</option>
            </select>
        </div>
        <div id="dmSpendPctRow" class="fm-row">
            <label>Percentage off</label>
            <input type="number" id="dmSpendPct" min="0.1" max="100" step="0.1" placeholder="e.g. 10">
        </div>
        <div id="dmSpendFixedRow" class="fm-row" style="display:none;">
            <label>Amount off (<?php echo htmlspecialchars($sym); ?>)</label>
            <input type="number" id="dmSpendFixed" min="0.01" step="0.01" placeholder="e.g. 1000.00">
        </div>
    </div>

    <!-- 7. Combo -->
    <div class="fm-section" id="dmSecCombo">
        <div class="fm-sec-head"><i class="fas fa-object-group" style="color:#0ea5e9;"></i> What must be in the cart together?</div>
        <p style="font-size:13px;color:#6b7280;margin:0 0 12px;">Add groups below — ALL groups must be present in the cart for this deal to fire. For example: 1 Food item + 1 Drink.</p>
        <div id="dmComboGroups"></div>
        <button type="button" onclick="addComboGroup()" style="font-size:12px;padding:6px 14px;border-radius:8px;border:1.5px dashed #d1d5db;background:#f9fafb;cursor:pointer;color:#374151;margin-bottom:14px;"><i class="fas fa-plus"></i> Add another group</button>
        <div class="fm-row">
            <label>Discount % to give when all groups are met</label>
            <input type="number" id="dmComboPct" min="0.1" max="100" step="0.1" placeholder="e.g. 15">
        </div>
    </div>

    <!-- 8. Time window (happy hour) -->
    <div class="fm-section" id="dmSecTime">
        <div class="fm-sec-head"><i class="fas fa-clock" style="color:#f59e0b;"></i> What hours does this run?</div>
        <div class="fm-2col">
            <div class="fm-row"><label>Start time</label><input type="time" id="dmStartTime"></div>
            <div class="fm-row"><label>End time</label><input type="time" id="dmEndTime"></div>
        </div>
        <div class="fm-hint">The deal will only fire between these times. Leave blank for all day.</div>
    </div>

    <!-- 9. Which items does it cover? -->
    <div class="fm-section show" id="dmSecScope">
        <div class="fm-sec-head"><i class="fas fa-bullseye" style="color:#10b981;"></i> Which items does this deal cover?</div>
        <div class="dm-scope-opts">
            <div class="dm-scope-card sel" data-scope="all" onclick="selectScope('all')">
                <i class="fas fa-store"></i>
                <div class="dsc-label">Everything</div>
                <div class="dsc-sub">All menu items</div>
            </div>
            <div class="dm-scope-card" data-scope="item_types" onclick="selectScope('item_types')">
                <i class="fas fa-th-large"></i>
                <div class="dsc-label">By Category</div>
                <div class="dsc-sub">e.g. only Drinks</div>
            </div>
            <div class="dm-scope-card" data-scope="items" onclick="selectScope('items')">
                <i class="fas fa-list-ul"></i>
                <div class="dsc-label">Specific Items</div>
                <div class="dsc-sub">Pick exact products</div>
            </div>
        </div>
        <input type="hidden" id="dmAppliesTo" value="all">

        <!-- By category: chips -->
        <div id="dmItemTypesRow" style="display:none;">
            <div class="fm-hint" style="margin-bottom:8px;">Select one or more categories — the deal will only apply to items in those categories.</div>
            <div class="ct-chips" id="ctChips">
                <?php foreach ($menuCatsRaw as $cat): ?>
                <span class="ct-chip" data-slug="<?php echo htmlspecialchars($cat['slug']); ?>" onclick="ctToggle(this)">
                    <i class="fas fa-tag"></i> <?php echo htmlspecialchars($cat['name']); ?>
                </span>
                <?php endforeach; ?>
            </div>
            <input type="hidden" id="dmItemTypes" value="">
        </div>

        <!-- Specific items: cascading dropdowns -->
        <div id="dmItemIdsRow" style="display:none;">
            <div class="fm-hint" style="margin-bottom:10px;">Choose a category, then pick the specific item to add. You can add as many as you like.</div>
            <div class="ip-row">
                <div>
                    <div style="font-size:11px;color:#6b7280;margin-bottom:4px;">Category</div>
                    <select id="ipCatSelect" onchange="ipOnCatChange()">
                        <option value="">— Pick a category —</option>
                    </select>
                </div>
                <div>
                    <div style="font-size:11px;color:#6b7280;margin-bottom:4px;">Item</div>
                    <select id="ipItemSelect" disabled>
                        <option value="">— Then pick an item —</option>
                    </select>
                </div>
                <button type="button" class="ip-add-btn" id="ipAddBtn" disabled onclick="ipAddSelected()">
                    <i class="fas fa-plus"></i> Add
                </button>
            </div>
            <div class="ip-chips" id="ipChips"><span class="ip-empty-hint">No items added yet</span></div>
            <input type="hidden" id="dmItemIds" value="">
        </div>
    </div>

    <!-- 10. Days active -->
    <div class="fm-section show" id="dmSecDays">
        <div class="fm-sec-head"><i class="fas fa-calendar-week" style="color:#6366f1;"></i> Which days does it run?</div>
        <div class="fm-hint" style="margin-bottom:8px;">Leave all unselected and the deal runs every day.</div>
        <div class="day-chips" id="dmDayChips">
            <?php foreach ([1=>'Mon',2=>'Tue',3=>'Wed',4=>'Thu',5=>'Fri',6=>'Sat',7=>'Sun'] as $n=>$lbl): ?>
            <span class="day-chip" data-day="<?php echo $n; ?>" onclick="toggleDay(this)"><?php echo $lbl; ?></span>
            <?php endforeach; ?>
        </div>
    </div>

    <!-- 11. Date range -->
    <div class="fm-section show" id="dmSecDates">
        <div class="fm-sec-head"><i class="fas fa-calendar-alt" style="color:#6366f1;"></i> Run between these dates <span style="font-weight:400;font-size:11px;text-transform:none;color:#9ca3af;">(optional — leave blank to run indefinitely)</span></div>
        <div class="fm-2col">
            <div class="fm-row"><label>Start date</label><input type="date" id="dmValidFrom"></div>
            <div class="fm-row"><label>End date</label><input type="date" id="dmValidTo"></div>
        </div>
    </div>

    <!-- 12. Options -->
    <div class="fm-section show">
        <div class="fm-sec-head"><i class="fas fa-sliders-h" style="color:#6366f1;"></i> Options</div>
        <div style="display:flex;flex-direction:column;gap:12px;">
            <div class="fm-check-row">
                <input type="checkbox" id="dmIsActive" checked>
                <label for="dmIsActive">This deal is active — it will fire at the POS straight away</label>
            </div>
            <div class="fm-check-row">
                <input type="checkbox" id="dmExclusive">
                <label for="dmExclusive">Exclusive deal — cannot stack with other deals on the same order</label>
            </div>
        </div>
        <div class="fm-row" style="margin-top:14px;margin-bottom:0;">
            <label>Evaluation order <span style="font-weight:400;text-transform:none;letter-spacing:0;">(optional)</span></label>
            <input type="number" id="dmSort" value="0" min="0" step="1" style="max-width:120px;">
            <div class="fm-hint">Lower number = evaluated first. Only matters when you have multiple deals and want to control priority.</div>
        </div>
    </div>

    </div><!-- /.dm-body -->

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

/* ── Deal type card selector ─────────────────────────────────────────────── */
function selectDealType(type) {
    document.getElementById('dmType').value = type;
    document.querySelectorAll('.dm-type-card').forEach(c => c.classList.toggle('sel', c.dataset.type === type));
    _applyTypeVisibility(type);
}

function _applyTypeVisibility(t) {
    const show = id => document.getElementById(id).classList.add('show');
    const hide = id => document.getElementById(id).classList.remove('show');
    ['dmSecPct','dmSecFixed','dmSecMb','dmSecSpend','dmSecTime','dmSecCombo'].forEach(hide);
    if (t === 'happy_hour')  { show('dmSecPct'); show('dmSecTime'); }
    if (t === 'percent_off') { show('dmSecPct'); }
    if (t === 'fixed_off')   { show('dmSecFixed'); }
    if (t === 'multi_buy')   { show('dmSecMb'); updateMbPreview(); }
    if (t === 'spend_save')  { show('dmSecSpend'); }
    if (t === 'combo')       { show('dmSecCombo'); }
}

/* ── Scope card selector ─────────────────────────────────────────────────── */
function selectScope(scope) {
    document.getElementById('dmAppliesTo').value = scope;
    document.querySelectorAll('.dm-scope-card').forEach(c => c.classList.toggle('sel', c.dataset.scope === scope));
    document.getElementById('dmItemTypesRow').style.display = scope === 'item_types' ? '' : 'none';
    document.getElementById('dmItemIdsRow').style.display   = scope === 'items'      ? '' : 'none';
}

/* ── Category type chip toggle ───────────────────────────────────────────── */
function ctToggle(el) {
    el.classList.toggle('sel');
    const selected = [...document.querySelectorAll('.ct-chip.sel')].map(c => c.dataset.slug);
    document.getElementById('dmItemTypes').value = selected.join(',');
}

function _ctRestoreSlugs(slugs) {
    document.querySelectorAll('.ct-chip').forEach(c => c.classList.toggle('sel', slugs.includes(c.dataset.slug)));
    document.getElementById('dmItemTypes').value = slugs.join(',');
}

/* ── Multi-buy live preview ──────────────────────────────────────────────── */
function updateMbPreview() {
    const qty  = parseInt(document.getElementById('dmMbQty').value, 10) || 0;
    const pay  = parseInt(document.getElementById('dmMbPay').value, 10) || 0;
    const prev = document.getElementById('mbPreview');
    if (qty >= 2 && pay >= 1 && pay < qty) {
        const free = qty - pay;
        prev.style.display = '';
        prev.innerHTML = `<strong>How this works:</strong> Customer adds ${qty} qualifying items to their order. They pay for ${pay} — the cheapest ${free} item${free>1?'s are':' is'} free. ✓`;
    } else {
        prev.style.display = 'none';
    }
}

/* ── Spend reward toggle ─────────────────────────────────────────────────── */
function onSpendRewardChange() {
    const v = document.getElementById('dmSpendRewardType').value;
    document.getElementById('dmSpendPctRow').style.display   = v === 'pct'   ? '' : 'none';
    document.getElementById('dmSpendFixedRow').style.display = v === 'fixed' ? '' : 'none';
}

function toggleDay(el) { el.classList.toggle('sel'); }

/* ── Combo group builder (uses category chips) ───────────────────────────── */
function addComboGroup(data) {
    _comboGroupCount++;
    const i = _comboGroupCount;
    // Build category chip options
    const cats = <?php echo json_encode(array_values(array_map(fn($c) => ['slug'=>$c['slug'],'name'=>$c['name']], $menuCatsRaw)), JSON_HEX_TAG|JSON_HEX_AMP); ?>;
    const savedTypes = data && data.item_types ? data.item_types : [];
    const chipHtml = cats.map(c =>
        `<span class="ct-chip${savedTypes.includes(c.slug)?' sel':''}" data-slug="${c.slug}" style="font-size:11px;padding:3px 10px;" onclick="this.classList.toggle('sel')">${c.name}</span>`
    ).join('');
    const div = document.createElement('div');
    div.id = 'cmb-grp-' + i;
    div.style.cssText = 'background:#fff;border:1px solid #e5e7eb;border-radius:10px;padding:12px 14px;margin-bottom:10px;';
    div.innerHTML = `
        <div style="display:flex;align-items:center;gap:8px;margin-bottom:10px;">
            <strong style="font-size:12px;color:#374151;">Group ${i}</strong>
            <button type="button" onclick="this.closest('[id^=cmb-grp]').remove()" style="margin-left:auto;background:none;border:none;color:#9ca3af;cursor:pointer;font-size:15px;line-height:1;" title="Remove group">✕</button>
        </div>
        <div style="font-size:11px;color:#6b7280;margin-bottom:6px;">Which categories count for this group?</div>
        <div class="ct-chips" style="margin-bottom:10px;">${chipHtml}</div>
        <div class="fm-row" style="margin-bottom:0;">
            <label>Minimum quantity from this group</label>
            <input type="number" class="cmb-qty" min="1" value="${data && data.min_qty ? data.min_qty : 1}" style="max-width:100px;">
        </div>`;
    document.getElementById('dmComboGroups').appendChild(div);
}

/* ── Open modal ──────────────────────────────────────────────────────────── */
function openDealModal(id) {
    _dmEditId = id || 0;
    _comboGroupCount = 0;
    document.getElementById('dmTitle').textContent = id ? 'Edit Deal' : 'Add Deal';
    const d = id ? _dealsData.find(x => +x.id === +id) : null;
    const type = d ? d.deal_type : 'happy_hour';

    document.getElementById('dmName').value      = d ? d.name : '';
    document.getElementById('dmDesc').value      = d ? (d.description || '') : '';
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
    document.getElementById('dmIsActive').checked  = d ? !!+d.is_active : true;
    document.getElementById('dmExclusive').checked = d ? !!+d.exclusive  : false;

    // Deal type cards
    selectDealType(type);

    // Scope cards
    const scope = d ? (d.applies_to || 'all') : 'all';
    selectScope(scope);

    // Category type chips
    const savedSlugs = (d && d.item_types)
        ? (Array.isArray(d.item_types) ? d.item_types : JSON.parse(d.item_types))
        : [];
    _ctRestoreSlugs(savedSlugs);

    // Item picker
    _ipItems = [];
    if (d && d.applies_to === 'items' && d.item_ids) {
        const ids = (Array.isArray(d.item_ids) ? d.item_ids : JSON.parse(d.item_ids)).map(Number);
        ids.forEach(itemId => {
            const mi = _menuItems.find(x => x.id === itemId);
            if (mi) _ipItems.push({id: mi.id, name: mi.name, category: mi.catName});
        });
    }
    ipReset();
    ipRender();

    // Days of week
    const dow = (d && d.days_of_week)
        ? (Array.isArray(d.days_of_week) ? d.days_of_week : JSON.parse(d.days_of_week))
        : [];
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
    } else if (!d || type === 'combo') {
        addComboGroup(); addComboGroup();
    }

    document.getElementById('dmBg').classList.add('show');
    setTimeout(() => document.getElementById('dmName').focus(), 60);
}

function closeDealModal() {
    document.getElementById('dmBg').classList.remove('show');
    _dmEditId = 0;
}

function buildComboJson() {
    const groups = [];
    document.querySelectorAll('[id^=cmb-grp-]').forEach(div => {
        // Read selected category chips inside this group
        const types = [...div.querySelectorAll('.ct-chip.sel')].map(c => c.dataset.slug).filter(Boolean);
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

/* ── Item Picker (cascading category → item dropdowns) ───────────────────── */
const _menuCats  = <?php echo json_encode(array_values($menuCatsRaw),  JSON_HEX_TAG | JSON_HEX_AMP); ?>;
const _menuItems = <?php
    // Attach category name to each item for display
    $catNameMap = [];
    foreach ($menuCatsRaw as $c) $catNameMap[$c['id']] = $c['name'];
    $itemsOut = [];
    foreach ($menuItemsRaw as $mi) {
        $itemsOut[] = ['id' => (int)$mi['id'], 'name' => $mi['name'], 'catId' => (int)$mi['category_id'], 'catName' => $catNameMap[$mi['category_id']] ?? ''];
    }
    echo json_encode($itemsOut, JSON_HEX_TAG | JSON_HEX_AMP);
?>;

let _ipItems = []; // [{id, name, category}]

function ipReset() {
    const catSel  = document.getElementById('ipCatSelect');
    const itemSel = document.getElementById('ipItemSelect');
    const addBtn  = document.getElementById('ipAddBtn');
    catSel.innerHTML = '<option value="">— Select category —</option>';
    _menuCats.forEach(c => {
        const o = document.createElement('option');
        o.value = c.id; o.textContent = c.name;
        catSel.appendChild(o);
    });
    itemSel.innerHTML = '<option value="">— Select item —</option>';
    itemSel.disabled = true;
    addBtn.disabled  = true;
}

function ipOnCatChange() {
    const catId   = +document.getElementById('ipCatSelect').value;
    const itemSel = document.getElementById('ipItemSelect');
    const addBtn  = document.getElementById('ipAddBtn');
    itemSel.innerHTML = '<option value="">— Select item —</option>';
    itemSel.disabled  = true;
    addBtn.disabled   = true;
    if (!catId) return;
    const filtered = _menuItems.filter(m => m.catId === catId);
    filtered.forEach(m => {
        const o = document.createElement('option');
        o.value = m.id;
        o.textContent = m.name + (_ipItems.some(x => x.id === m.id) ? ' ✓' : '');
        if (_ipItems.some(x => x.id === m.id)) o.disabled = true;
        itemSel.appendChild(o);
    });
    itemSel.disabled = filtered.length === 0;
    itemSel.onchange = () => { addBtn.disabled = !itemSel.value; };
}

function ipAddSelected() {
    const itemSel = document.getElementById('ipItemSelect');
    const id = +itemSel.value;
    if (!id || _ipItems.some(x => x.id === id)) return;
    const mi = _menuItems.find(m => m.id === id);
    if (!mi) return;
    _ipItems.push({id: mi.id, name: mi.name, category: mi.catName});
    ipRender();
    // Refresh the item dropdown to mark this one added
    ipOnCatChange();
    document.getElementById('ipItemSelect').value = '';
    document.getElementById('ipAddBtn').disabled = true;
}

function ipRemove(id) {
    _ipItems = _ipItems.filter(x => x.id !== +id);
    ipRender();
    ipOnCatChange(); // refresh dropdown marks
}

function ipRender() {
    const chips  = document.getElementById('ipChips');
    document.getElementById('dmItemIds').value = _ipItems.map(x => x.id).join(',');
    if (!_ipItems.length) {
        chips.innerHTML = '<span class="ip-empty-hint">No items added yet — deal will apply to all items</span>';
        return;
    }
    chips.innerHTML = _ipItems.map(x =>
        `<span class="ip-chip" title="${x.category||''}">${x.name.replace(/</g,'&lt;')}
            <button type="button" class="ip-chip-x" onclick="ipRemove(${x.id})" title="Remove">&#215;</button>
        </span>`
    ).join('');
}
</script>
<?php require_once 'includes/admin-footer.php'; ?>
