<?php

/**
 * Dynamic Rate Plans Management
 * Admin Panel — Revenue > Rate Plans
 */
require_once 'admin-init.php';
/** @var array $user */
/** @var string $csrf_token */

$message     = '';
$messageType = '';
$savedId     = null;
$isAjax      = !empty($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest';

/* ── Fetch all room types for scope selector ──────────────────────────── */
$allRooms = $pdo->query("SELECT id, name FROM rooms WHERE is_active = 1 ORDER BY name ASC")->fetchAll(PDO::FETCH_ASSOC);

/* ── POST handlers ────────────────────────────────────────────────────── */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!isset($_POST['csrf_token']) || !validateCsrfToken($_POST['csrf_token'])) {
        $message = 'Invalid security token. Please refresh and try again.';
        $messageType = 'error';
    } else {
        $action = $_POST['action'] ?? '';

        if ($action === 'save') {
            /* ── Validate & sanitise ── */
            $id         = !empty($_POST['plan_id']) ? (int)$_POST['plan_id'] : null;
            $name       = trim($_POST['name'] ?? '');
            $description = trim($_POST['description'] ?? '');
            $ruleType   = $_POST['rule_type'] ?? 'promotion';
            $validTypes = ['seasonal', 'weekend', 'los_discount', 'last_minute', 'early_bird', 'promotion'];
            if (!in_array($ruleType, $validTypes, true)) {
                $ruleType = 'promotion';
            }

            $startDate  = !empty($_POST['start_date'])      ? $_POST['start_date']      : null;
            $endDate    = !empty($_POST['end_date'])         ? $_POST['end_date']        : null;
            $daysOfWeek = !empty($_POST['days_of_week'])     ? implode(',', array_map('intval', (array)$_POST['days_of_week'])) : null;
            $minNights  = !empty($_POST['min_nights'])       ? max(1, (int)$_POST['min_nights'])  : null;
            $maxNights  = !empty($_POST['max_nights'])       ? max(1, (int)$_POST['max_nights'])  : null;
            $daysBeforeMin = isset($_POST['days_before_min']) && $_POST['days_before_min'] !== '' ? max(0, (int)$_POST['days_before_min']) : null;
            $daysBeforeMax = !empty($_POST['days_before_max']) ? max(0, (int)$_POST['days_before_max']) : null;

            $adjType    = in_array($_POST['adjustment_type'] ?? 'percentage', ['percentage', 'fixed']) ? $_POST['adjustment_type'] : 'percentage';
            $adjValue   = (float)($_POST['adjustment_value'] ?? 0);
            $appliesTo  = in_array($_POST['applies_to'] ?? 'all', ['all', 'room_types']) ? $_POST['applies_to'] : 'all';
            $roomTypeIds = ($appliesTo === 'room_types' && !empty($_POST['room_type_ids']))
                ? json_encode(array_values(array_unique(array_map('intval', (array)$_POST['room_type_ids']))))
                : null;
            $priority   = max(0, min(255, (int)($_POST['priority'] ?? 0)));
            $isStacking = empty($_POST['is_stacking']) ? 0 : 1;
            $isActive   = empty($_POST['is_active'])   ? 0 : 1;

            if (empty($name)) {
                $message = 'Plan name is required.';
                $messageType = 'error';
            } elseif ($adjValue == 0) {
                $message = 'Adjustment value cannot be zero.';
                $messageType = 'error';
            } else {
                try {
                    if ($id) {
                        $stmt = $pdo->prepare("UPDATE rate_plans SET
                            name=?, description=?, rule_type=?,
                            start_date=?, end_date=?, days_of_week=?,
                            min_nights=?, max_nights=?,
                            days_before_min=?, days_before_max=?,
                            adjustment_type=?, adjustment_value=?,
                            applies_to=?, room_type_ids=?,
                            priority=?, is_stacking=?, is_active=?
                            WHERE id=?");
                        $stmt->execute([
                            $name,
                            $description ?: null,
                            $ruleType,
                            $startDate,
                            $endDate,
                            $daysOfWeek,
                            $minNights,
                            $maxNights,
                            $daysBeforeMin,
                            $daysBeforeMax,
                            $adjType,
                            $adjValue,
                            $appliesTo,
                            $roomTypeIds,
                            $priority,
                            $isStacking,
                            $isActive,
                            $id
                        ]);
                        rh_log_event('rate_plans', 'info', "Rate plan updated: {$name}", ['id' => $id, 'user' => $user['username']]);
                        $message = 'Rate plan updated successfully.';
                        $savedId  = $id;
                    } else {
                        $stmt = $pdo->prepare("INSERT INTO rate_plans
                            (name, description, rule_type,
                             start_date, end_date, days_of_week,
                             min_nights, max_nights,
                             days_before_min, days_before_max,
                             adjustment_type, adjustment_value,
                             applies_to, room_type_ids,
                             priority, is_stacking, is_active)
                            VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)");
                        $stmt->execute([
                            $name,
                            $description ?: null,
                            $ruleType,
                            $startDate,
                            $endDate,
                            $daysOfWeek,
                            $minNights,
                            $maxNights,
                            $daysBeforeMin,
                            $daysBeforeMax,
                            $adjType,
                            $adjValue,
                            $appliesTo,
                            $roomTypeIds,
                            $priority,
                            $isStacking,
                            $isActive,
                        ]);
                        $savedId  = (int)$pdo->lastInsertId();
                        rh_log_event('rate_plans', 'info', "Rate plan created: {$name}", ['user' => $user['username']]);
                        $message = 'Rate plan created successfully.';
                    }
                    $messageType = 'success';
                } catch (PDOException $e) {
                    error_log('rate_plans save error: ' . $e->getMessage());
                    $message = 'Database error saving rate plan.';
                    $messageType = 'error';
                }
            }
            if ($isAjax) {
                header('Content-Type: application/json; charset=utf-8');
                echo json_encode(['success' => $messageType === 'success', 'message' => $message, 'saved_id' => $savedId]);
                exit;
            }
        } elseif ($action === 'toggle') {
            $id  = (int)($_POST['plan_id'] ?? 0);
            $val = (int)($_POST['is_active'] ?? 0);
            if ($id > 0) {
                $pdo->prepare("UPDATE rate_plans SET is_active=? WHERE id=?")->execute([$val, $id]);
                $message = $val ? 'Rate plan activated.' : 'Rate plan deactivated.';
                $messageType = 'success';
            }
        } elseif ($action === 'delete') {
            $id = (int)($_POST['plan_id'] ?? 0);
            if ($id > 0) {
                $pdo->prepare("DELETE FROM rate_plans WHERE id=?")->execute([$id]);
                rh_log_event('rate_plans', 'info', "Rate plan deleted", ['id' => $id, 'user' => $user['username']]);
                $message = 'Rate plan deleted.';
                $messageType = 'success';
            }
        }
    }
}

/* ── Fetch all plans ─────────────────────────────────────────────────── */
$plans = $pdo->query("SELECT * FROM rate_plans ORDER BY priority DESC, name ASC")->fetchAll(PDO::FETCH_ASSOC);

/* ── Rule type labels ────────────────────────────────────────────────── */
$ruleLabels = [
    'seasonal'    => 'Seasonal',
    'weekend'     => 'Weekend',
    'los_discount' => 'Length of Stay',
    'last_minute' => 'Last-Minute',
    'early_bird'  => 'Early Bird',
    'promotion'   => 'Promotion',
];
$ruleIcons = [
    'seasonal'    => 'fa-sun',
    'weekend'     => 'fa-calendar-week',
    'los_discount' => 'fa-moon',
    'last_minute' => 'fa-bolt',
    'early_bird'  => 'fa-clock',
    'promotion'   => 'fa-tag',
];
$dayNames = ['Sun', 'Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat'];
?>
<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Rate Plans — Admin</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Cormorant+Garamond:ital,wght@0,300;0,400;0,500;0,600;1,300;1,400;1,500&family=Jost:wght@300;400;500;600&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="css/admin-styles.css?v=<?php echo @filemtime(__DIR__ . '/css/admin-styles.css'); ?>">
    <link rel="stylesheet" href="css/admin-components.css?v=<?php echo @filemtime(__DIR__ . '/css/admin-components.css'); ?>">
    <style>
        .rp-badge {
            display: inline-flex;
            align-items: center;
            gap: 5px;
            padding: 3px 10px;
            border-radius: 20px;
            font-size: 12px;
            font-weight: 600;
        }

        .rp-badge--seasonal {
            background: #fff3cd;
            color: #856404;
        }

        .rp-badge--weekend {
            background: #d1ecf1;
            color: #0c5460;
        }

        .rp-badge--los_discount {
            background: #d4edda;
            color: #155724;
        }

        .rp-badge--last_minute {
            background: #f8d7da;
            color: #721c24;
        }

        .rp-badge--early_bird {
            background: #cce5ff;
            color: #004085;
        }

        .rp-badge--promotion {
            background: #e2d9f3;
            color: #432874;
        }

        .adj-value--discount {
            color: #28a745;
            font-weight: 700;
        }

        .adj-value--surcharge {
            color: #dc3545;
            font-weight: 700;
        }

        .plan-card {
            background: #fff;
            border: 1px solid #e9ecef;
            border-radius: 8px;
            padding: 16px 20px;
            margin-bottom: 12px;
            display: flex;
            align-items: flex-start;
            gap: 16px;
        }

        .plan-card__icon {
            width: 44px;
            height: 44px;
            border-radius: 50%;
            background: #f8f9fa;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 18px;
            color: #6c757d;
            flex-shrink: 0;
        }

        .plan-card__body {
            flex: 1;
            min-width: 0;
        }

        .plan-card__title {
            font-weight: 600;
            font-size: 15px;
            margin: 0 0 4px;
        }

        .plan-card__meta {
            font-size: 13px;
            color: #6c757d;
            margin: 0 0 6px;
        }

        .plan-card__actions {
            display: flex;
            gap: 8px;
            flex-shrink: 0;
        }

        .inactive-overlay {
            opacity: 0.5;
        }

        /* Conditional field sections */
        .rule-fields {
            display: none;
        }

        .rule-fields.visible {
            display: block;
        }

        .day-checkbox-row {
            display: flex;
            flex-wrap: wrap;
            gap: 8px;
            margin-top: 6px;
        }

        .day-checkbox-row label {
            display: flex;
            align-items: center;
            gap: 5px;
            padding: 4px 10px;
            border: 1px solid #dee2e6;
            border-radius: 4px;
            cursor: pointer;
            font-size: 13px;
            user-select: none;
        }

        .day-checkbox-row label:has(input:checked) {
            background: var(--color-primary, #8B7355);
            color: #fff;
            border-color: var(--color-primary, #8B7355);
        }

        /* Modal */
        .rp-modal-overlay {
            display: none;
            position: fixed;
            inset: 0;
            background: rgba(0, 0, 0, .55);
            z-index: 1000;
            overflow-y: auto;
            padding: 20px;
        }

        .rp-modal-overlay.active {
            display: flex;
            align-items: flex-start;
            justify-content: center;
        }

        .rp-modal {
            background: #fff;
            border-radius: 10px;
            max-width: 700px;
            width: 100%;
            margin: auto;
            box-shadow: 0 10px 40px rgba(0, 0, 0, .25);
        }

        .rp-modal__header {
            padding: 20px 24px 16px;
            border-bottom: 1px solid #e9ecef;
            display: flex;
            align-items: center;
            justify-content: space-between;
        }

        .rp-modal__header h3 {
            margin: 0;
            font-size: 17px;
        }

        .rp-modal__body {
            padding: 20px 24px;
        }

        .rp-modal__footer {
            padding: 16px 24px;
            border-top: 1px solid #e9ecef;
            display: flex;
            justify-content: flex-end;
            gap: 10px;
        }

        .form-row {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 14px;
        }

        @media (max-width: 560px) {
            .form-row {
                grid-template-columns: 1fr;
            }
        }

        .form-group {
            margin-bottom: 14px;
        }

        .form-group label {
            display: block;
            font-size: 13px;
            font-weight: 500;
            margin-bottom: 5px;
            color: #343a40;
        }

        .form-group input,
        .form-group select,
        .form-group textarea {
            width: 100%;
            padding: 8px 10px;
            border: 1px solid #ced4da;
            border-radius: 5px;
            font-size: 14px;
            transition: border-color .2s;
        }

        .form-group input:focus,
        .form-group select:focus {
            border-color: var(--color-primary, #8B7355);
            outline: none;
        }

        .form-hint {
            font-size: 12px;
            color: #6c757d;
            margin-top: 4px;
        }

        .toggle-switch {
            display: inline-flex;
            align-items: center;
            gap: 8px;
            cursor: pointer;
        }

        .toggle-switch input {
            display: none;
        }

        .toggle-track {
            width: 40px;
            height: 22px;
            background: #ccc;
            border-radius: 11px;
            position: relative;
            transition: background .2s;
        }

        .toggle-track::after {
            content: '';
            position: absolute;
            top: 3px;
            left: 3px;
            width: 16px;
            height: 16px;
            background: #fff;
            border-radius: 50%;
            transition: left .2s;
        }

        .toggle-switch input:checked+.toggle-track {
            background: #28a745;
        }

        .toggle-switch input:checked+.toggle-track::after {
            left: 21px;
        }
    </style>
</head>

<body>
    <?php require_once 'includes/admin-header.php'; ?>

    <div class="admin-content">
        <div class="page-header">
            <div>
                <h1 class="page-title"><i class="fas fa-tags"></i> Rate Plans</h1>
                <p>Dynamic pricing rules — seasonal rates, weekend surcharges, early-bird discounts, and more.</p>
            </div>
            <div style="display:flex;flex-wrap:wrap;gap:10px;justify-content:flex-end;">
                <button class="btn btn-primary" onclick="openPlanModal()">
                    <i class="fas fa-plus"></i> New Rate Plan
                </button>
            </div>
        </div>

        <div class="admin-container">

            <?php if ($message): ?>
                <div class="alert alert-<?php echo $messageType === 'success' ? 'success' : 'danger'; ?>" style="margin-bottom:16px;">
                    <i class="fas fa-<?php echo $messageType === 'success' ? 'check-circle' : 'exclamation-circle'; ?>"></i>
                    <?php echo htmlspecialchars($message); ?>
                </div>
            <?php endif; ?>

            <!-- Explainer -->
            <div style="background:#f8f9fa; border:1px solid #e9ecef; border-radius:8px; padding:16px; margin-bottom:24px; font-size:14px; color:#343a40;">
                <strong><i class="fas fa-info-circle" style="color:#8B7355;"></i> How it works:</strong>
                Rate plans are automatically applied to bookings when the stay conditions match.
                Higher priority plans are evaluated first. Use <em>stacking</em> to apply multiple plans to the same stay.
                <strong>Negative adjustment</strong> = discount &nbsp;|&nbsp; <strong>Positive adjustment</strong> = surcharge.
            </div>

            <?php if (empty($plans)): ?>
                <div style="text-align:center; padding:60px 20px; color:#6c757d;">
                    <i class="fas fa-tags" style="font-size:48px; opacity:.3; display:block; margin-bottom:12px;"></i>
                    <p>No rate plans yet. Create your first one to start applying dynamic pricing.</p>
                </div>
            <?php else: ?>

                <div id="plansContainer">
                    <?php foreach ($plans as $plan):
                        $inactive = !$plan['is_active'];
                        $adjVal   = (float)$plan['adjustment_value'];
                        $adjClass = $adjVal < 0 ? 'adj-value--discount' : 'adj-value--surcharge';
                        $adjSign  = $adjVal > 0 ? '+' : '';
                        $adjDisplay = $adjSign . $adjVal . ($plan['adjustment_type'] === 'percentage' ? '%' : ' MWK');

                        $ruleType = $plan['rule_type'];
                        $ruleLabel = $ruleLabels[$ruleType] ?? $ruleType;
                        $ruleIcon  = $ruleIcons[$ruleType] ?? 'fa-tag';

                        // Build condition summary
                        $condParts = [];
                        if ($ruleType === 'seasonal' && $plan['start_date'] && $plan['end_date']) {
                            $condParts[] = date('d M', strtotime($plan['start_date'])) . ' – ' . date('d M Y', strtotime($plan['end_date']));
                        } elseif ($ruleType === 'weekend' && $plan['days_of_week']) {
                            $days = array_map(fn($d) => $dayNames[(int)$d] ?? '', explode(',', $plan['days_of_week']));
                            $condParts[] = implode(', ', $days);
                        } elseif ($ruleType === 'los_discount') {
                            $condParts[] = ($plan['min_nights'] ?? 1) . '+ nights';
                            if ($plan['max_nights']) {
                                $condParts[0] .= ' (max ' . $plan['max_nights'] . ')';
                            }
                        } elseif (in_array($ruleType, ['last_minute', 'early_bird'])) {
                            if ($plan['days_before_min'] !== null) {
                                $condParts[] = 'min ' . $plan['days_before_min'] . ' days before';
                            }
                            if ($plan['days_before_max'] !== null) {
                                $condParts[] = 'max ' . $plan['days_before_max'] . ' days before';
                            }
                        }
                        $condSummary = $condParts ? implode(' | ', $condParts) : 'Always active';
                        $scopeSummary = $plan['applies_to'] === 'all' ? 'All room types' : 'Selected rooms';
                    ?>
                        <div class="plan-card <?php echo $inactive ? 'inactive-overlay' : ''; ?>">
                            <div class="plan-card__icon">
                                <i class="fas <?php echo htmlspecialchars($ruleIcon); ?>"></i>
                            </div>
                            <div class="plan-card__body">
                                <div class="plan-card__title"><?php echo htmlspecialchars($plan['name']); ?></div>
                                <div class="plan-card__meta">
                                    <span class="rp-badge rp-badge--<?php echo htmlspecialchars($ruleType); ?>">
                                        <i class="fas <?php echo htmlspecialchars($ruleIcon); ?>"></i> <?php echo htmlspecialchars($ruleLabel); ?>
                                    </span>
                                    &nbsp;
                                    <span class="<?php echo $adjClass; ?>"><?php echo htmlspecialchars($adjDisplay); ?></span>
                                    &nbsp;·&nbsp; <?php echo htmlspecialchars($condSummary); ?>
                                    &nbsp;·&nbsp; <?php echo htmlspecialchars($scopeSummary); ?>
                                    &nbsp;·&nbsp; Priority <?php echo (int)$plan['priority']; ?>
                                    <?php if ($plan['is_stacking']): ?> &nbsp;·&nbsp; <span style="color:#6c757d; font-size:12px;">Stacking</span><?php endif; ?>
                                </div>
                                <?php if ($plan['description']): ?>
                                    <div style="font-size:13px; color:#6c757d;"><?php echo htmlspecialchars($plan['description']); ?></div>
                                <?php endif; ?>
                            </div>
                            <div class="plan-card__actions">
                                <button class="btn btn-sm btn-outline-secondary" onclick='editPlan(<?php echo json_encode($plan); ?>)'>
                                    <i class="fas fa-edit"></i>
                                </button>
                                <form method="POST" style="display:inline;">
                                    <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrf_token); ?>">
                                    <input type="hidden" name="action" value="toggle">
                                    <input type="hidden" name="plan_id" value="<?php echo (int)$plan['id']; ?>">
                                    <input type="hidden" name="is_active" value="<?php echo $inactive ? 1 : 0; ?>">
                                    <button type="submit" class="btn btn-sm <?php echo $inactive ? 'btn-outline-success' : 'btn-outline-secondary'; ?>"
                                        title="<?php echo $inactive ? 'Activate' : 'Deactivate'; ?>">
                                        <i class="fas <?php echo $inactive ? 'fa-toggle-off' : 'fa-toggle-on'; ?>"></i>
                                    </button>
                                </form>
                                <form method="POST" style="display:inline;" class="delete-form">
                                    <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrf_token); ?>">
                                    <input type="hidden" name="action" value="delete">
                                    <input type="hidden" name="plan_id" value="<?php echo (int)$plan['id']; ?>">
                                    <button type="button" class="btn btn-sm btn-outline-danger" onclick="confirmDelete(this, 'Delete rate plan &ldquo;<?php echo htmlspecialchars($plan['name'], ENT_QUOTES); ?>&rdquo;? This cannot be undone.')"><i class="fas fa-trash"></i></button>
                                </form>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>

            <?php endif; ?>
        </div><!-- /.admin-container -->
    </div><!-- /.admin-content -->

    <!-- ─── Create / Edit Modal ─────────────────────────────────────────── -->
    <div class="rp-modal-overlay" id="planModalOverlay">
        <div class="rp-modal">
            <form method="POST" id="planForm">
                <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrf_token); ?>">
                <input type="hidden" name="action" value="save">
                <input type="hidden" name="plan_id" id="fieldPlanId" value="">

                <div class="rp-modal__header">
                    <h3 id="modalTitle"><i class="fas fa-tags"></i> New Rate Plan</h3>
                    <button type="button" onclick="closePlanModal()" style="background:none; border:none; font-size:20px; cursor:pointer; color:#6c757d;">&times;</button>
                </div>

                <div class="rp-modal__body">

                    <div class="form-row">
                        <div class="form-group">
                            <label for="fieldName">Plan Name <span style="color:red">*</span></label>
                            <input type="text" id="fieldName" name="name" required placeholder="e.g. Festive Season Surcharge">
                        </div>
                        <div class="form-group">
                            <label for="fieldRuleType">Rule Type <span style="color:red">*</span></label>
                            <select id="fieldRuleType" name="rule_type" onchange="onRuleTypeChange(this.value)">
                                <option value="seasonal">🌤 Seasonal (date range)</option>
                                <option value="weekend">📅 Weekend (day of week)</option>
                                <option value="los_discount">🌙 Length of Stay</option>
                                <option value="last_minute">⚡ Last-Minute</option>
                                <option value="early_bird">🕐 Early Bird</option>
                                <option value="promotion">🏷 Promotion (always)</option>
                            </select>
                        </div>
                    </div>

                    <div class="form-group">
                        <label for="fieldDescription">Description (optional)</label>
                        <textarea id="fieldDescription" name="description" rows="2" placeholder="Brief note visible only in admin"></textarea>
                    </div>

                    <!-- SEASONAL fields -->
                    <div class="rule-fields" id="fields-seasonal">
                        <div class="form-row">
                            <div class="form-group">
                                <label for="fieldStartDate">Start Date</label>
                                <input type="date" id="fieldStartDate" name="start_date">
                            </div>
                            <div class="form-group">
                                <label for="fieldEndDate">End Date</label>
                                <input type="date" id="fieldEndDate" name="end_date">
                            </div>
                        </div>
                        <p class="form-hint">Plan applies when check-in falls within this range.</p>
                    </div>

                    <!-- WEEKEND fields -->
                    <div class="rule-fields" id="fields-weekend">
                        <div class="form-group">
                            <label>Days of Week</label>
                            <div class="day-checkbox-row" id="daysOfWeekRow">
                                <?php foreach ([0 => 'Sun', 1 => 'Mon', 2 => 'Tue', 3 => 'Wed', 4 => 'Thu', 5 => 'Fri', 6 => 'Sat'] as $d => $dn): ?>
                                    <label>
                                        <input type="checkbox" name="days_of_week[]" value="<?php echo $d; ?>"> <?php echo $dn; ?>
                                    </label>
                                <?php endforeach; ?>
                            </div>
                            <p class="form-hint">Plan applies if any night of the stay falls on a checked day.</p>
                        </div>
                    </div>

                    <!-- LOS fields -->
                    <div class="rule-fields" id="fields-los_discount">
                        <div class="form-row">
                            <div class="form-group">
                                <label for="fieldMinNights">Minimum Nights</label>
                                <input type="number" id="fieldMinNights" name="min_nights" min="1" placeholder="e.g. 3">
                            </div>
                            <div class="form-group">
                                <label for="fieldMaxNights">Maximum Nights (optional)</label>
                                <input type="number" id="fieldMaxNights" name="max_nights" min="1" placeholder="Leave empty = no max">
                            </div>
                        </div>
                    </div>

                    <!-- LAST-MINUTE / EARLY-BIRD fields -->
                    <div class="rule-fields" id="fields-last_minute">
                        <div class="form-row">
                            <div class="form-group">
                                <label for="fieldDaysBeforeMinLM">Min Days Before Arrival</label>
                                <input type="number" id="fieldDaysBeforeMinLM" name="days_before_min" min="0" placeholder="e.g. 0">
                            </div>
                            <div class="form-group">
                                <label for="fieldDaysBeforeMaxLM">Max Days Before Arrival</label>
                                <input type="number" id="fieldDaysBeforeMaxLM" name="days_before_max" min="0" placeholder="e.g. 3">
                            </div>
                        </div>
                        <p class="form-hint">Example: min 0, max 3 = book within 3 days of arrival.</p>
                    </div>
                    <div class="rule-fields" id="fields-early_bird">
                        <div class="form-row">
                            <div class="form-group">
                                <label for="fieldDaysBeforeMinEB">Min Days Before Arrival</label>
                                <input type="number" id="fieldDaysBeforeMinEB" name="days_before_min" min="0" placeholder="e.g. 30">
                            </div>
                            <div class="form-group">
                                <label for="fieldDaysBeforeMaxEB">Max Days Before Arrival (optional)</label>
                                <input type="number" id="fieldDaysBeforeMaxEB" name="days_before_max" min="0" placeholder="Leave empty = no max">
                            </div>
                        </div>
                        <p class="form-hint">Example: min 30 = book at least 30 days in advance.</p>
                    </div>

                    <hr style="margin: 18px 0; border-color: #e9ecef;">

                    <!-- Adjustment -->
                    <div class="form-row">
                        <div class="form-group">
                            <label for="fieldAdjType">Adjustment Type</label>
                            <select id="fieldAdjType" name="adjustment_type">
                                <option value="percentage">Percentage (%)</option>
                                <option value="fixed">Fixed Amount (MWK)</option>
                            </select>
                        </div>
                        <div class="form-group">
                            <label for="fieldAdjValue">Adjustment Value <span style="color:red">*</span></label>
                            <input type="number" id="fieldAdjValue" name="adjustment_value" step="0.01" placeholder="-10 = 10% off, +20 = 20% surcharge">
                            <p class="form-hint">Negative = discount &nbsp;|&nbsp; Positive = surcharge</p>
                        </div>
                    </div>

                    <hr style="margin: 18px 0; border-color: #e9ecef;">

                    <!-- Scope -->
                    <div class="form-group">
                        <label for="fieldAppliesTo">Applies To</label>
                        <select id="fieldAppliesTo" name="applies_to" onchange="onAppliesToChange(this.value)">
                            <option value="all">All Room Types</option>
                            <option value="room_types">Selected Room Types Only</option>
                        </select>
                    </div>

                    <div class="form-group" id="roomTypesField" style="display:none;">
                        <label>Select Room Types</label>
                        <div style="display:flex; flex-wrap:wrap; gap:8px;">
                            <?php foreach ($allRooms as $r): ?>
                                <label style="display:flex; align-items:center; gap:5px; padding:5px 10px; border:1px solid #dee2e6; border-radius:4px; cursor:pointer; font-size:13px;">
                                    <input type="checkbox" name="room_type_ids[]" class="rp-room-chk" value="<?php echo (int)$r['id']; ?>">
                                    <?php echo htmlspecialchars($r['name']); ?>
                                </label>
                            <?php endforeach; ?>
                        </div>
                    </div>

                    <hr style="margin: 18px 0; border-color: #e9ecef;">

                    <!-- Control -->
                    <div class="form-row">
                        <div class="form-group">
                            <label for="fieldPriority">Priority (0–255)</label>
                            <input type="number" id="fieldPriority" name="priority" min="0" max="255" value="0">
                            <p class="form-hint">Higher priority = evaluated first.</p>
                        </div>
                        <div class="form-group">
                            <label style="margin-bottom:10px;">Options</label>
                            <div style="display:flex; flex-direction:column; gap:10px;">
                                <label class="toggle-switch">
                                    <input type="checkbox" id="fieldIsStacking" name="is_stacking" value="1">
                                    <span class="toggle-track"></span>
                                    <span style="font-size:13px;">Stack with other plans</span>
                                </label>
                                <label class="toggle-switch">
                                    <input type="checkbox" id="fieldIsActive" name="is_active" value="1" checked>
                                    <span class="toggle-track"></span>
                                    <span style="font-size:13px;">Active</span>
                                </label>
                            </div>
                        </div>
                    </div>

                </div><!-- /.rp-modal__body -->

                <div class="rp-modal__footer" style="flex-direction:column; align-items:stretch; gap:0;">
                    <div id="planModalFeedback" class="admin-modal-feedback"></div>
                    <div style="display:flex; justify-content:flex-end; gap:10px;">
                        <button type="button" class="btn btn-secondary" onclick="closePlanModal()">Close</button>
                        <button type="submit" id="planSaveBtn" class="btn btn-primary"><i class="fas fa-save"></i> Save Rate Plan</button>
                    </div>
                </div>

            </form>
        </div>
    </div><!-- /.rp-modal-overlay -->

    <!-- Delete Confirmation Modal -->
    <div id="deleteConfirmModal" style="display:none; position:fixed; inset:0; background:rgba(0,0,0,.55); z-index:9000; align-items:center; justify-content:center; padding:20px;">
        <div style="background:#fff; border-radius:10px; max-width:420px; width:100%; padding:28px; box-shadow:0 10px 40px rgba(0,0,0,.3);">
            <h4 style="margin:0 0 12px; font-size:16px;"><i class="fas fa-exclamation-triangle" style="color:#dc3545;"></i> Confirm Delete</h4>
            <p id="deleteConfirmMessage" style="margin:0 0 22px; font-size:14px; color:#343a40; line-height:1.5;"></p>
            <div style="display:flex; justify-content:flex-end; gap:10px;">
                <button type="button" onclick="document.getElementById('deleteConfirmModal').style.display='none'" class="btn btn-secondary">Cancel</button>
                <button type="button" id="deleteConfirmYes" class="btn btn-danger"><i class="fas fa-trash"></i> Yes, Delete</button>
            </div>
        </div>
    </div>

    <script src="js/admin-components.js" defer></script>
    <script>
        const overlay = document.getElementById('planModalOverlay');

        function openPlanModal(plan) {
            document.getElementById('modalTitle').innerHTML = plan ?
                '<i class="fas fa-edit"></i> Edit Rate Plan' :
                '<i class="fas fa-plus"></i> New Rate Plan';
            document.getElementById('fieldPlanId').value = plan ? plan.id : '';
            document.getElementById('fieldName').value = plan ? plan.name : '';
            document.getElementById('fieldDescription').value = plan ? (plan.description || '') : '';
            document.getElementById('fieldPriority').value = plan ? plan.priority : 0;
            document.getElementById('fieldIsStacking').checked = plan ? !!+plan.is_stacking : false;
            document.getElementById('fieldIsActive').checked = plan ? !!+plan.is_active : true;
            document.getElementById('fieldAdjType').value = plan ? plan.adjustment_type : 'percentage';
            document.getElementById('fieldAdjValue').value = plan ? plan.adjustment_value : '';
            document.getElementById('fieldAppliesTo').value = plan ? plan.applies_to : 'all';
            onAppliesToChange(plan ? plan.applies_to : 'all');

            const rt = plan ? plan.rule_type : 'seasonal';
            document.getElementById('fieldRuleType').value = rt;
            onRuleTypeChange(rt, plan);

            overlay.classList.add('active');
        }

        function editPlan(plan) {
            openPlanModal(plan);
        }

        function closePlanModal() {
            overlay.classList.remove('active');
            const fb = document.getElementById('planModalFeedback');
            if (fb) {
                fb.className = 'admin-modal-feedback';
                fb.innerHTML = '';
            }
        }

        overlay.addEventListener('click', function(e) {
            if (e.target === overlay) closePlanModal();
        });

        // ── AJAX save — keep modal open ─────────────────────────────
        document.getElementById('planForm').addEventListener('submit', function(e) {
            e.preventDefault();
            const saveBtn = document.getElementById('planSaveBtn');
            const fb = document.getElementById('planModalFeedback');
            saveBtn.disabled = true;
            saveBtn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Saving…';
            fb.className = 'admin-modal-feedback';
            fb.innerHTML = '';
            fetch(window.location.pathname, {
                    method: 'POST',
                    headers: {
                        'X-Requested-With': 'XMLHttpRequest'
                    },
                    body: new FormData(this)
                })
                .then(function(r) {
                    if (!r.ok) throw new Error('HTTP ' + r.status);
                    return r.json();
                })
                .then(function(res) {
                    saveBtn.disabled = false;
                    saveBtn.innerHTML = '<i class="fas fa-save"></i> Save Rate Plan';
                    fb.className = 'admin-modal-feedback ' + (res.success ? 'admin-modal-feedback--success' : 'admin-modal-feedback--error') + ' visible';
                    fb.innerHTML = '<i class="fas fa-' + (res.success ? 'check-circle' : 'exclamation-circle') + '"></i> ' + res.message;
                    if (res.success) {
                        if (res.saved_id) {
                            document.getElementById('fieldPlanId').value = res.saved_id;
                            document.getElementById('modalTitle').innerHTML = '<i class="fas fa-edit"></i> Edit Rate Plan';
                        }
                        refreshPlanList();
                    }
                })
                .catch(function() {
                    saveBtn.disabled = false;
                    saveBtn.innerHTML = '<i class="fas fa-save"></i> Save Rate Plan';
                    fb.className = 'admin-modal-feedback admin-modal-feedback--error visible';
                    fb.innerHTML = '<i class="fas fa-exclamation-circle"></i> Network error — please try again.';
                });
        });

        function refreshPlanList() {
            fetch(window.location.href)
                .then(function(r) {
                    return r.text();
                })
                .then(function(html) {
                    const doc = new DOMParser().parseFromString(html, 'text/html');
                    const next = doc.getElementById('plansContainer');
                    const cur = document.getElementById('plansContainer');
                    if (next && cur) cur.innerHTML = next.innerHTML;
                }).catch(function() {});
        }

        function onRuleTypeChange(type, plan) {
            document.querySelectorAll('.rule-fields').forEach(el => el.classList.remove('visible'));

            if (type === 'seasonal') {
                document.getElementById('fields-seasonal').classList.add('visible');
                if (plan) {
                    document.getElementById('fieldStartDate').value = plan.start_date || '';
                    document.getElementById('fieldEndDate').value = plan.end_date || '';
                } else {
                    document.getElementById('fieldStartDate').value = '';
                    document.getElementById('fieldEndDate').value = '';
                }
            } else if (type === 'weekend') {
                document.getElementById('fields-weekend').classList.add('visible');
                const activeDays = plan && plan.days_of_week ? plan.days_of_week.split(',').map(Number) : [];
                document.querySelectorAll('[name="days_of_week[]"]').forEach(chk => {
                    chk.checked = activeDays.includes(+chk.value);
                });
            } else if (type === 'los_discount') {
                document.getElementById('fields-los_discount').classList.add('visible');
                document.getElementById('fieldMinNights').value = plan ? (plan.min_nights || '') : '';
                document.getElementById('fieldMaxNights').value = plan ? (plan.max_nights || '') : '';
            } else if (type === 'last_minute') {
                document.getElementById('fields-last_minute').classList.add('visible');
                document.getElementById('fieldDaysBeforeMinLM').value = plan ? (plan.days_before_min !== null ? plan.days_before_min : '') : '';
                document.getElementById('fieldDaysBeforeMaxLM').value = plan ? (plan.days_before_max || '') : '';
            } else if (type === 'early_bird') {
                document.getElementById('fields-early_bird').classList.add('visible');
                document.getElementById('fieldDaysBeforeMinEB').value = plan ? (plan.days_before_min !== null ? plan.days_before_min : '') : '';
                document.getElementById('fieldDaysBeforeMaxEB').value = plan ? (plan.days_before_max || '') : '';
            }
            // promotion: no extra fields
        }

        function onAppliesToChange(val) {
            document.getElementById('roomTypesField').style.display = val === 'room_types' ? 'block' : 'none';
        }

        <?php if (!empty($allRooms)): ?>

            function setRoomTypeCheckboxes(idsJson) {
                const ids = idsJson ? JSON.parse(idsJson) : [];
                document.querySelectorAll('.rp-room-chk').forEach(chk => {
                    chk.checked = ids.includes(+chk.value);
                });
            }
        <?php endif; ?>

        // Patch editPlan to also set room type checkboxes
        const _origEdit = editPlan;
        window.editPlan = function(plan) {
            _origEdit(plan);
            if (typeof setRoomTypeCheckboxes === 'function') {
                setRoomTypeCheckboxes(plan ? plan.room_type_ids : null);
            }
        };

        function confirmDelete(btn, message) {
            const modal = document.getElementById('deleteConfirmModal');
            document.getElementById('deleteConfirmMessage').innerHTML = message;
            const form = btn.closest('form');
            document.getElementById('deleteConfirmYes').onclick = function() {
                modal.style.display = 'none';
                form.submit();
            };
            modal.style.display = 'flex';
        }
        document.getElementById('deleteConfirmModal').addEventListener('click', function(e) {
            if (e.target === this) this.style.display = 'none';
        });
    </script>
<?php require_once 'includes/admin-footer.php'; ?>
</body>

</html>

