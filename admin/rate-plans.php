<?php

/**
 * Dynamic Rate Plans Management
 * Admin Panel — Revenue > Rate Plans
 */
require_once 'admin-init.php';
require_once __DIR__ . '/../includes/form-validation.php';
require_once __DIR__ . '/../includes/price-history.php';
/** @var array $user */
/** @var string $csrf_token */

$message     = '';
$messageType = '';
$savedId     = null;
$isAjax      = !empty($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest';

/* ── Fetch all room types for scope selector ──────────────────────────── */
$allRooms = $pdo->query("SELECT id, name FROM rooms WHERE is_active = 1 ORDER BY name ASC")->fetchAll(PDO::FETCH_ASSOC);

/* ── Step 8h: per-room-type amounts + price basis (migration 086; until it runs the page works exactly as before) ── */
require_once __DIR__ . '/../includes/pricing.php';
$rp086 = rh_rate_plans_086_ready($pdo);
$rpCur = (string)(getSetting('currency_symbol', 'MWK') ?: 'MWK');
$rpFactor = rh_rate_plan_tax_factor();
$rpTax = rh_rate_plan_tax_rates();
$rpBasisMatters = rh_rate_plan_basis_matters($rpFactor);
// Standard price per room type = its price per night (all-in); the lowest price it sells at guards fixed discounts.
$rpRooms = [];
if ($rp086) {
    foreach ($pdo->query("SELECT id, name, price_per_night, price_single_occupancy, price_double_occupancy, price_triple_occupancy FROM rooms WHERE is_active = 1 ORDER BY name ASC")->fetchAll(PDO::FETCH_ASSOC) as $r) {
        $std = (float)$r['price_per_night'];
        $low = $std;
        foreach (['price_single_occupancy', 'price_double_occupancy', 'price_triple_occupancy'] as $pc) {
            if ($r[$pc] !== null && (float)$r[$pc] > 0 && ((float)$r[$pc] < $low || $low <= 0)) {
                $low = (float)$r[$pc];
            }
        }
        $rpRooms[(int)$r['id']] = ['name' => (string)$r['name'], 'standard' => $std, 'price' => $low];
    }
}
$rpTaxNames = array_values(array_filter([$rpTax['vat'] > 0 ? 'VAT' : '', $rpTax['levy'] > 0 ? 'tourism levy' : '']));
$rpTaxLabel = implode(' and ', $rpTaxNames);

/** Step 8h: the plan the form describes (for the preview and the save checks). */
$rpPostedPlan = static function (array $p, ?array $overrides, string $basis): array {
    $appliesTo = in_array($p['applies_to'] ?? 'all', ['all', 'room_types'], true) ? $p['applies_to'] : 'all';
    $ids = $appliesTo === 'room_types' ? array_values(array_unique(array_map('intval', (array)($p['room_type_ids'] ?? [])))) : null;
    return [
        'adjustment_type'  => ($p['adjustment_type'] ?? '') === 'fixed' ? 'fixed' : 'percentage',
        'adjustment_value' => is_numeric($p['adjustment_value'] ?? null) ? round((float)$p['adjustment_value'], 2) : 0.0,
        'applies_to'       => $appliesTo,
        'room_type_ids'    => $ids === null ? null : json_encode($ids),
        'room_adjustments' => $overrides ? json_encode($overrides) : null,
        'price_basis'      => $basis,
    ];
};

/* ── POST handlers ────────────────────────────────────────────────────── */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!isset($_POST['csrf_token']) || !validateCsrfToken($_POST['csrf_token'])) {
        $message = 'Invalid security token. Please refresh and try again.';
        $messageType = 'error';
        if ($isAjax) {
            header('Content-Type: application/json; charset=utf-8');
            echo json_encode(['success' => false, 'message' => $message]);
            exit;
        }
    } else {
        $action = $_POST['action'] ?? '';

        if ($action === 'preview') {
            // Step 8h: read-only breakdown per room type for the modal (the server is the one place the math lives).
            header('Content-Type: application/json; charset=utf-8');
            if (!$rp086) {
                echo json_encode(['success' => false, 'rows' => []]);
                exit;
            }
            $pvAppliesTo = ($_POST['applies_to'] ?? 'all') === 'room_types' ? 'room_types' : 'all';
            $pvCovered = $pvAppliesTo === 'room_types' ? array_values(array_unique(array_map('intval', (array)($_POST['room_type_ids'] ?? [])))) : null;
            $pvMode = ($_POST['adj_mode'] ?? 'same') === 'per_room' ? 'per_room' : 'same';
            $pvBasis = ($rpBasisMatters && ($_POST['price_basis'] ?? 'all_in') === 'base') ? 'base' : 'all_in';
            // Rows with a bad value are left out of the preview (the save explains why).
            $pvOverrides = [];
            if ($pvMode === 'per_room') {
                foreach ((array)($_POST['room_adj'] ?? []) as $rid => $row) {
                    $rid = (int)$rid;
                    if (!isset($rpRooms[$rid]) || !is_array($row) || ($pvCovered !== null && !in_array($rid, $pvCovered, true))) {
                        continue;
                    }
                    $raw = trim((string)($row['value'] ?? ''));
                    if ($raw === '' || !is_numeric($raw) || abs((float)$raw) < 0.005) {
                        continue;
                    }
                    $pvOverrides[$rid] = ['type' => ($row['type'] ?? '') === 'fixed' ? 'fixed' : 'percentage', 'value' => round((float)$raw, 2)];
                }
            }
            $pvPlan = $rpPostedPlan($_POST, $pvOverrides ?: null, $pvBasis);
            $pvRows = [];
            $pvValid = is_numeric($_POST['adjustment_value'] ?? null) && abs((float)$_POST['adjustment_value']) >= 0.005;
            foreach ($rpRooms as $rid => $info) {
                $eff = rh_rate_plan_effective($pvPlan, $rid);
                if ($eff === null || abs($eff['value']) < 0.005 || (!$pvValid && !$eff['override'])) {
                    continue;
                }
                $bd = rh_rate_plan_breakdown($pvPlan, $rid, (float)$info['standard'], $rpFactor, $rpCur);
                if ($bd !== null) {
                    $pvRows[] = ['room_id' => $rid, 'name' => $info['name']] + $bd;
                }
            }
            echo json_encode(['success' => true, 'rows' => $pvRows, 'basis' => $pvBasis, 'currency' => $rpCur]);
            exit;
        }

        if ($action === 'save') {
            /* ── Validate & sanitise ── */
            $id         = !empty($_POST['plan_id']) ? (int)$_POST['plan_id'] : null;
            $name       = rh_clean_text($_POST['name'] ?? '');
            $description = trim($_POST['description'] ?? '');
            $ruleType   = $_POST['rule_type'] ?? 'promotion';
            $validTypes = ['seasonal', 'weekend', 'los_discount', 'last_minute', 'early_bird', 'promotion'];
            if (!in_array($ruleType, $validTypes, true)) {
                $ruleType = 'promotion';
            }

            $isValidYmd = static function ($v): bool {
                $d = is_string($v) ? DateTime::createFromFormat('!Y-m-d', $v) : false;
                return $d !== false && $d->format('Y-m-d') === $v;
            };
            $startDate  = !empty($_POST['start_date']) ? trim((string)$_POST['start_date']) : null;
            $endDate    = !empty($_POST['end_date'])   ? trim((string)$_POST['end_date'])   : null;
            $dayList    = !empty($_POST['days_of_week']) ? array_values(array_unique(array_filter(array_map('intval', (array)$_POST['days_of_week']), static function ($d) { return $d >= 0 && $d <= 6; }))) : [];
            sort($dayList);
            $daysOfWeek = $dayList ? implode(',', $dayList) : null;
            $minNights  = !empty($_POST['min_nights']) ? min(255, max(1, (int)$_POST['min_nights'])) : null;
            $maxNights  = !empty($_POST['max_nights']) ? min(255, max(1, (int)$_POST['max_nights'])) : null;
            // Last-minute and early-bird forms use separate field names (a shared name made the
            // hidden early-bird inputs overwrite the last-minute window on every save).
            $dbPrefix   = $ruleType === 'early_bird' ? 'eb_days_before_' : 'days_before_';
            $daysBeforeMin = isset($_POST[$dbPrefix . 'min']) && $_POST[$dbPrefix . 'min'] !== '' ? min(65535, max(0, (int)$_POST[$dbPrefix . 'min'])) : null;
            $daysBeforeMax = isset($_POST[$dbPrefix . 'max']) && $_POST[$dbPrefix . 'max'] !== '' ? min(65535, max(0, (int)$_POST[$dbPrefix . 'max'])) : null;

            // Only the fields that belong to the chosen rule type are stored.
            if ($ruleType !== 'seasonal') { $startDate = null; $endDate = null; }
            if ($ruleType !== 'weekend') { $daysOfWeek = null; }
            if ($ruleType !== 'los_discount') { $minNights = null; $maxNights = null; }
            if (!in_array($ruleType, ['last_minute', 'early_bird'], true)) { $daysBeforeMin = null; $daysBeforeMax = null; }

            $adjType    = in_array($_POST['adjustment_type'] ?? 'percentage', ['percentage', 'fixed'], true) ? $_POST['adjustment_type'] : 'percentage';
            $adjRaw     = trim((string)($_POST['adjustment_value'] ?? '0'));
            $adjValue   = is_numeric($adjRaw) ? round((float)$adjRaw, 2) : 0.0;
            $appliesTo  = in_array($_POST['applies_to'] ?? 'all', ['all', 'room_types'], true) ? $_POST['applies_to'] : 'all';
            $roomTypeIds = ($appliesTo === 'room_types' && !empty($_POST['room_type_ids']))
                ? json_encode(array_values(array_unique(array_map('intval', (array)$_POST['room_type_ids']))))
                : null;
            $priority   = max(0, min(255, (int)($_POST['priority'] ?? 0)));
            $isStacking = empty($_POST['is_stacking']) ? 0 : 1;
            $isActive   = empty($_POST['is_active'])   ? 0 : 1;

            $validRoomIds = array_map('intval', array_column($allRooms, 'id'));
            $roomIdsPosted = ($appliesTo === 'room_types') ? array_values(array_unique(array_map('intval', (array)($_POST['room_type_ids'] ?? [])))) : [];
            $badRoomIds = array_diff($roomIdsPosted, $validRoomIds);

            // "Different per room type" with the bottom amount left empty (owner 2026-11-16: "adjustment value must
            // be non zero" after filling the room amounts). For a plan on chosen room types where every chosen room
            // type has its own amount, the bottom amount is never used for a price: store the first chosen room
            // type's amount there (keeps the increase/discount checks). A plan on every room type still needs it:
            // a room type added later would use it.
            $perRoomBlankDefault = '';
            if ($rp086 && ($_POST['adj_mode'] ?? 'same') === 'per_room' && (!is_numeric($adjRaw) || $adjValue == 0)) {
                $rpPosted = (array)($_POST['room_adj'] ?? []);
                $rpFilled = static function ($rid) use ($rpPosted): ?array {
                    $row = $rpPosted[$rid] ?? null;
                    $raw = is_array($row) ? trim((string)($row['value'] ?? '')) : '';
                    if ($raw === '' || !is_numeric($raw) || abs(round((float)$raw, 2)) < 0.000001) {
                        return null;
                    }
                    return ['type' => ($row['type'] ?? '') === 'fixed' ? 'fixed' : 'percentage', 'value' => round((float)$raw, 2)];
                };
                $rpCoverIds = $appliesTo === 'room_types' ? $roomIdsPosted : array_map('intval', array_keys($rpRooms));
                $rpEmpty = [];
                $rpFirst = null;
                foreach ($rpCoverIds as $rpCid) {
                    $rpAmt = $rpFilled($rpCid);
                    if ($rpAmt === null) {
                        $rpEmpty[] = (string)($rpRooms[$rpCid]['name'] ?? ('#' . $rpCid));
                    } elseif ($rpFirst === null) {
                        $rpFirst = $rpAmt;
                    }
                }
                if ($appliesTo !== 'room_types') {
                    $perRoomBlankDefault = 'Fill in "Amount for any room type left empty above": this plan covers every room type, so a room type added later uses that amount.'
                        . ($rpEmpty ? ' Room types with no amount of their own: ' . implode(', ', $rpEmpty) . '.' : '');
                } elseif ($rpEmpty) {
                    $perRoomBlankDefault = 'Give these room types their own amount, or fill in "Amount for any room type left empty above": ' . implode(', ', $rpEmpty) . '.';
                } elseif ($rpFirst !== null && !$badRoomIds) {
                    $adjType  = $rpFirst['type'];
                    $adjValue = $rpFirst['value'];
                    $adjRaw   = (string)$adjValue;
                }
            }

            $validationError = '';
            if ($name === '') {
                $validationError = 'Plan name is required.';
            } elseif (mb_strlen($name) > 100) {
                $validationError = 'Plan name cannot exceed 100 characters.';
            } elseif (mb_strlen($description) > 2000) {
                $validationError = 'Description cannot exceed 2000 characters.';
            } elseif ($perRoomBlankDefault !== '') {
                $validationError = $perRoomBlankDefault;
            } elseif (!is_numeric($adjRaw) || $adjValue == 0) {
                $validationError = 'Adjustment value must be a non-zero number.';
            } elseif ($adjType === 'percentage' && ($adjValue <= -100 || $adjValue > 1000)) {
                $validationError = 'A percentage adjustment must be above -100 (a 100% discount makes the room free) and at most 1000.';
            } elseif ($adjType === 'fixed' && abs($adjValue) > 9999999) {
                $validationError = 'A fixed adjustment cannot exceed 9,999,999 either way.';
            } elseif ($ruleType === 'seasonal' && ($startDate === null || $endDate === null)) {
                $validationError = 'A seasonal plan needs both a start and an end date.';
            } elseif (($startDate !== null && !$isValidYmd($startDate)) || ($endDate !== null && !$isValidYmd($endDate))) {
                $validationError = 'Dates must be valid (YYYY-MM-DD).';
            } elseif ($startDate !== null && $endDate !== null && $endDate < $startDate) {
                $validationError = 'The end date cannot be before the start date.';
            } elseif ($ruleType === 'weekend' && $daysOfWeek === null) {
                $validationError = 'Choose at least one day of the week.';
            } elseif ($minNights !== null && $maxNights !== null && $maxNights < $minNights) {
                $validationError = 'Maximum nights cannot be lower than minimum nights.';
            } elseif ($ruleType === 'los_discount' && $minNights === null && $maxNights === null) {
                $validationError = 'A length-of-stay plan needs a minimum or maximum number of nights.';
            } elseif ($daysBeforeMin !== null && $daysBeforeMax !== null && $daysBeforeMax < $daysBeforeMin) {
                $validationError = 'The maximum days before arrival cannot be lower than the minimum.';
            } elseif (in_array($ruleType, ['last_minute', 'early_bird'], true) && $daysBeforeMin === null && $daysBeforeMax === null) {
                $validationError = 'Set the days-before-arrival window for this plan.';
            } elseif ($appliesTo === 'room_types' && (!$roomIdsPosted || $badRoomIds)) {
                $validationError = 'Choose at least one valid room type, or set the plan to apply to all rooms.';
            } elseif ($id) {
                $exists = $pdo->prepare("SELECT COUNT(*) FROM rate_plans WHERE id = ?");
                $exists->execute([$id]);
                if (!(int)$exists->fetchColumn()) {
                    $validationError = 'That rate plan no longer exists.';
                }
            }
            if ($validationError === '' && ($dupPlan = rh_find_duplicate($pdo, 'rate_plans', 'name', $name, [], $id ?: null))) {
                $validationError = rh_duplicate_message('rate plan', (string)$dupPlan['value']);
            }
            // Step 8h: per-room-type amounts + price basis (only once migration 086 ran).
            $rpOverrides = null;
            $rpBasis = 'all_in';
            if ($validationError === '' && $rp086) {
                $rv = rh_rate_plan_validate_rooms($adjType, $adjValue, ($_POST['adj_mode'] ?? 'same') === 'per_room' ? 'per_room' : 'same',
                    (array)($_POST['room_adj'] ?? []), $appliesTo === 'room_types' ? $roomIdsPosted : null, $rpRooms,
                    (string)($_POST['price_basis'] ?? 'all_in'), $rpFactor, $rpCur);
                if ($rv['error'] !== '') {
                    $validationError = $rv['error'];
                } else {
                    $rpOverrides = $rv['overrides'];
                    $rpBasis = $rv['basis'];
                }
            }
            $rpOverridesJson = $rpOverrides ? json_encode($rpOverrides, JSON_FORCE_OBJECT) : null;

            if ($validationError !== '') {
                $message = $validationError;
                $messageType = 'error';
            } else {
                // Price history (room_price_history): the plan's price rules before and after the save.
                $rpHistFields = ['name', 'rule_type', 'start_date', 'end_date', 'days_of_week', 'min_nights', 'max_nights', 'days_before_min',
                    'days_before_max', 'adjustment_type', 'adjustment_value', 'applies_to', 'room_type_ids', 'priority', 'is_stacking', 'is_active'];
                if ($rp086) {
                    // B1: the new columns only exist after migration 086.
                    $rpHistFields[] = 'price_basis';
                    $rpHistFields[] = 'room_adjustments';
                }
                // Step 8h: one History row per room type whose amount or price changed: "Deluxe: +10% (100,000 -> 110,000)".
                $rpRoomHist = static function (?array $before, ?array $after) use ($rpRooms, $rpFactor, $rpCur): array {
                    $rows = [];
                    foreach ($rpRooms as $rid => $info) {
                        $o = $before ? rh_rate_plan_room_hist_text($before, $rid, (float)$info['standard'], $rpFactor, $rpCur) : '';
                        $n = $after ? rh_rate_plan_room_hist_text($after, $rid, (float)$info['standard'], $rpFactor, $rpCur) : '';
                        if ($o !== $n) {
                            $rows['room_adj_' . $rid] = [$o === '' ? null : $info['name'] . ': ' . $o, $n === '' ? null : $info['name'] . ': ' . $n];
                        }
                    }
                    return $rows;
                };
                $rpExtraSet = $rp086 ? ', price_basis=?, room_adjustments=?' : '';
                $rpExtraCols = $rp086 ? ', price_basis, room_adjustments' : '';
                $rpExtraMarks = $rp086 ? ',?,?' : '';
                $rpExtraVals = $rp086 ? [$rpBasis, $rpOverridesJson] : [];
                try {
                    if ($id) {
                        $rpHistBefore = rh_price_history_snapshot($pdo, 'rate_plans', (int)$id, $rpHistFields);
                        $stmt = $pdo->prepare("UPDATE rate_plans SET
                            name=?, description=?, rule_type=?,
                            start_date=?, end_date=?, days_of_week=?,
                            min_nights=?, max_nights=?,
                            days_before_min=?, days_before_max=?,
                            adjustment_type=?, adjustment_value=?,
                            applies_to=?, room_type_ids=?,
                            priority=?, is_stacking=?, is_active=?" . $rpExtraSet . "
                            WHERE id=?");
                        $stmt->execute(array_merge([
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
                        ], $rpExtraVals, [$id]));
                        if ($rpHistBefore) {
                            $rpHistAfter = rh_price_history_snapshot($pdo, 'rate_plans', (int)$id, $rpHistFields);
                            $rpHistChanges = [];
                            foreach ($rpHistFields as $f) {
                                if ($f === 'room_adjustments') {
                                    continue; // logged per room type below (the raw JSON could pass 255 characters)
                                }
                                $rpHistChanges[$f] = [$rpHistBefore[$f] ?? null, $rpHistAfter[$f] ?? null];
                            }
                            if ($rp086) {
                                $rpHistChanges += $rpRoomHist($rpHistBefore, $rpHistAfter);
                            }
                            rh_price_history_log($pdo, 'rate_plan', (int)$id, $rpHistChanges, $user);
                        }
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
                             priority, is_stacking, is_active" . $rpExtraCols . ")
                            VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?" . $rpExtraMarks . ")");
                        $insertParams = array_merge([
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
                        ], $rpExtraVals);
                        // Re-check the name and insert together under a lock (double-click / two staff at once).
                        rh_with_create_lock($pdo, 'rate_plan', function () use ($pdo, $stmt, $insertParams, $name, &$savedId) {
                            $dup = rh_find_duplicate($pdo, 'rate_plans', 'name', $name);
                            if ($dup) {
                                throw new RuntimeException(rh_duplicate_message('rate plan', (string)$dup['value']));
                            }
                            $stmt->execute($insertParams);
                            $savedId = (int)$pdo->lastInsertId();
                        });
                        rh_log_event('rate_plans', 'info', "Rate plan created: {$name}", ['user' => $user['username']]);
                        if ($savedId) {
                            $rpCreated = ['created' => [null, $name . ': ' . $adjType . ' ' . $adjValue]];
                            if ($rp086 && $rpOverrides) {
                                // The room types with their own amount, as they start.
                                $rpNewPlan = ['adjustment_type' => $adjType, 'adjustment_value' => $adjValue, 'applies_to' => $appliesTo,
                                    'room_type_ids' => $roomTypeIds, 'price_basis' => $rpBasis, 'room_adjustments' => $rpOverridesJson];
                                foreach ($rpRoomHist(null, $rpNewPlan) as $hf => $hv) {
                                    if (isset($rpOverrides[(int)substr($hf, 9)])) {
                                        $rpCreated[$hf] = $hv;
                                    }
                                }
                            }
                            rh_price_history_log($pdo, 'rate_plan', (int)$savedId, $rpCreated, $user);
                        }
                        $message = 'Rate plan created successfully.';
                    }
                    $messageType = 'success';
                    // Non-blocking: warn about overlapping active plans of the SAME direction (discount vs discount,
                    // surcharge vs surcharge) on shared room types. Pricing (rh_apply_rate_plans) chooses surcharges and
                    // discounts apart: in each group a non-stacking plan means only the single best one applies.
                    if ($isActive && $adjValue != 0) {
                        $isSurcharge = $adjValue > 0;
                        $ovl = $pdo->prepare("SELECT name, rule_type, start_date, end_date, applies_to, room_type_ids FROM rate_plans WHERE is_active = 1 AND id <> ? AND "
                            . ($isSurcharge ? 'adjustment_value > 0' : 'adjustment_value < 0'));
                        $ovl->execute([(int)$savedId]);
                        $clashes = [];
                        foreach ($ovl->fetchAll(PDO::FETCH_ASSOC) as $o) {
                            // Two seasonal plans whose dates do not overlap never meet.
                            if ($ruleType === 'seasonal' && (string)$o['rule_type'] === 'seasonal' && $startDate && $endDate && $o['start_date'] && $o['end_date']
                                && ($o['end_date'] < $startDate || $o['start_date'] > $endDate)) {
                                continue;
                            }
                            if ($appliesTo === 'room_types' && $o['applies_to'] === 'room_types') {
                                $oIds = json_decode((string)$o['room_type_ids'], true);
                                if (is_array($oIds) && !array_intersect(array_map('intval', $oIds), $roomIdsPosted)) {
                                    continue;
                                }
                            }
                            $clashes[] = $o['name'];
                        }
                        if ($clashes) {
                            $message .= ' Note: it can meet other active ' . ($isSurcharge ? 'surcharge' : 'discount') . ' plan(s) on the same room types: '
                                . implode(', ', array_slice($clashes, 0, 5)) . '. When a stay matches several ' . ($isSurcharge ? 'surcharges' : 'discounts')
                                . ' and any of them is non-stacking, only one applies: the ' . ($isSurcharge ? 'highest price' : 'lowest price')
                                . ' (on a tie, the higher priority plan). If they all stack, they all apply. Surcharges and discounts are worked out separately.';
                        }
                    }
                } catch (RuntimeException $e) {
                    $message = $e->getMessage();
                    $messageType = 'error';
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
            $val = empty($_POST['is_active']) ? 0 : 1;
            if ($id > 0) {
                $rpHistBefore = rh_price_history_snapshot($pdo, 'rate_plans', $id, ['is_active']);
                $pdo->prepare("UPDATE rate_plans SET is_active=? WHERE id=?")->execute([$val, $id]);
                if ($rpHistBefore) {
                    rh_price_history_log($pdo, 'rate_plan', $id, ['is_active' => [$rpHistBefore['is_active'] ?? null, $val]], $user);
                }
                $message = $val ? 'Rate plan activated.' : 'Rate plan deactivated.';
                $messageType = 'success';
            }
        } elseif ($action === 'delete') {
            $id = (int)($_POST['plan_id'] ?? 0);
            if ($id > 0) {
                $rpHistBefore = rh_price_history_snapshot($pdo, 'rate_plans', $id, ['name', 'adjustment_type', 'adjustment_value']);
                $pdo->prepare("DELETE FROM rate_plans WHERE id=?")->execute([$id]);
                if ($rpHistBefore) {
                    rh_price_history_log($pdo, 'rate_plan', $id, ['deleted' => [($rpHistBefore['name'] ?? '') . ': ' . ($rpHistBefore['adjustment_type'] ?? '') . ' ' . ($rpHistBefore['adjustment_value'] ?? ''), null]], $user);
                }
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
            background: var(--color-primary, #7E684B);
            color: #fff;
            border-color: var(--color-primary, #7E684B);
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
            max-width: 700px;
            width: 100%;
            margin: auto;
        }

        .rp-modal__body {
            padding: 20px 24px;
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
            border-color: var(--color-primary, #7E684B);
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

        /* Modal layout fixes (owner 2026-11-15): the generic .form-group rules made the Options toggles a block
           label with a zero-width track (its knob covered the words) and stretched the room-type checkboxes. */
        .form-group label.toggle-switch {
            display: inline-flex;
            margin-bottom: 0;
            min-height: 32px;
        }

        .toggle-track {
            display: inline-block;
            flex: 0 0 40px;
        }

        .form-group input[type="checkbox"],
        .form-group input[type="radio"] {
            width: auto;
            padding: 0;
            flex: 0 0 auto;
        }

        .rp-section-title {
            margin: 0 0 12px;
            font-size: 12px;
            font-weight: 700;
            letter-spacing: .06em;
            text-transform: uppercase;
            color: var(--color-primary, #7E684B);
        }

        .rp-section-title small {
            display: block;
            margin-top: 2px;
            font-size: 12px;
            font-weight: 400;
            letter-spacing: 0;
            text-transform: none;
            color: #6c757d;
        }

        /* Step 8h: amount per room type + breakdown */
        .rp-seg {
            display: flex;
            flex-wrap: wrap;
            gap: 8px;
        }

        .rp-seg label {
            display: flex;
            align-items: center;
            gap: 6px;
            min-height: 44px;
            padding: 6px 12px;
            border: 1px solid #dee2e6;
            border-radius: 4px;
            cursor: pointer;
            font-size: 13px;
            margin: 0;
        }

        .rp-seg label:has(input:checked) {
            border-color: var(--color-primary, #7E684B);
            background: #f8f5f0;
        }

        .form-group .rp-seg input {
            width: auto;
        }

        .rp-room-rows {
            border: 1px solid #e9ecef;
            border-radius: 6px;
            padding: 6px 12px;
            margin-bottom: 14px;
        }

        .rp-room-row {
            display: grid;
            grid-template-columns: minmax(0, 1fr) 150px 130px;
            gap: 8px;
            align-items: center;
            padding: 6px 0;
            border-bottom: 1px solid #f1f3f5;
        }

        .rp-room-row:last-child {
            border-bottom: 0;
        }

        .rp-room-row__name {
            font-size: 13px;
            min-width: 0;
            overflow-wrap: anywhere;
        }

        .rp-room-row__name small {
            display: block;
            color: #6c757d;
        }

        .rp-room-row select,
        .rp-room-row input {
            width: 100%;
            min-height: 44px;
            padding: 8px 10px;
            border: 1px solid #ced4da;
            border-radius: 5px;
            font-size: 14px;
        }

        @media (max-width: 560px) {
            .rp-room-row {
                grid-template-columns: minmax(0, 1fr) minmax(0, 1fr);
            }

            .rp-room-row__name {
                grid-column: 1 / -1;
            }
        }

        .rp-breakdown:empty {
            display: none;
        }

        .rp-breakdown {
            margin-bottom: 14px;
        }

        .rp-bd {
            border: 1px solid #e9ecef;
            border-radius: 6px;
            padding: 10px 12px;
            margin-top: 8px;
            font-size: 13px;
        }

        .rp-bd__head {
            display: flex;
            flex-wrap: wrap;
            justify-content: space-between;
            gap: 6px;
            font-weight: 600;
            margin-bottom: 6px;
        }

        .rp-bd__line {
            display: flex;
            flex-wrap: wrap;
            justify-content: space-between;
            gap: 4px 12px;
            padding: 3px 0;
        }

        .rp-bd__split {
            color: #6c757d;
            font-size: 12px;
        }

        .rp-bd__change {
            margin-top: 4px;
            font-weight: 600;
        }

        .rp-room-prices {
            margin-top: 6px;
            font-size: 13px;
        }

        .rp-room-prices summary {
            cursor: pointer;
            color: #495057;
            min-height: 32px;
            display: list-item;
        }

        .rp-room-prices ul {
            margin: 6px 0 0;
            padding-left: 18px;
        }

        .rp-room-prices li {
            margin: 2px 0;
            overflow-wrap: anywhere;
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
                <strong><i class="fas fa-info-circle" style="color:#7E684B;"></i> How it works:</strong>
                Rate plans are automatically applied to new bookings when the stay conditions match.
                Surcharges and discounts are worked out separately: surcharges are added to the room price first, then discounts are taken off.
                If several discounts match and any of them is not set to <em>stack</em>, only the one giving the lowest price applies; if several surcharges match and any is not set to stack, only the one giving the highest price applies.
                When every matching plan of a kind stacks, they all apply, in priority order. Priority breaks a tie.
                Changing a booking's dates later removes a length-of-stay, weekend or seasonal discount the new stay no longer qualifies for.
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
                        // Step 8h: own amounts per room type, the price basis, and the price each room type ends up at.
                        $rpPlanOverrides = $rp086 ? rh_rate_plan_overrides($plan) : [];
                        if ($rpPlanOverrides) {
                            $adjDisplay .= ' (own amount for ' . count($rpPlanOverrides) . ' room type' . (count($rpPlanOverrides) === 1 ? '' : 's') . ')';
                        }
                        if ($rp086 && ($plan['price_basis'] ?? 'all_in') === 'base') {
                            $adjDisplay .= ' on the base rate';
                        }
                        $rpLines = [];
                        if ($rp086) {
                            foreach ($rpRooms as $rpRid => $rpInfo) {
                                $rpBd = rh_rate_plan_breakdown($plan, $rpRid, (float)$rpInfo['standard'], $rpFactor, $rpCur);
                                if ($rpBd !== null && $rpInfo['standard'] > 0) {
                                    $rpLines[] = [$rpInfo['name'], $rpBd];
                                }
                            }
                        }

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
                                <?php if ($rpLines): ?>
                                    <details class="rp-room-prices">
                                        <summary>Price per night by room type (<?php echo count($rpLines); ?>)</summary>
                                        <ul>
                                            <?php foreach ($rpLines as [$rpName, $rpBd]):
                                                $rpUp = $rpBd['diff'] >= 0; ?>
                                                <li><strong><?php echo htmlspecialchars($rpName); ?></strong>
                                                    <?php echo htmlspecialchars($rpBd['adjustment_text']); ?>:
                                                    <?php echo htmlspecialchars($rpCur . ' ' . rh_rate_plan_money($rpBd['standard']) . ' -> ' . rh_rate_plan_money($rpBd['new'])); ?>
                                                    <span class="<?php echo $rpUp ? 'adj-value--surcharge' : 'adj-value--discount'; ?>">(<?php echo htmlspecialchars(($rpUp ? 'up by ' : 'down by ') . $rpCur . ' ' . rh_rate_plan_money(abs($rpBd['diff'])) . ($rpBd['pct'] !== null ? ', ' . rh_rate_plan_money(abs($rpBd['pct'])) . '%' : '')); ?>)</span>
                                                </li>
                                            <?php endforeach; ?>
                                        </ul>
                                        <p class="form-hint">Standard price = the room type's price per night with VAT and levy included. The second figure is what a stay this plan matches pays per night with this plan alone.</p>
                                    </details>
                                <?php endif; ?>
                            </div>
                            <div class="plan-card__actions">
                                <button class="btn btn-sm btn-outline-secondary" onclick='editPlan(<?php echo htmlspecialchars(json_encode($plan), ENT_QUOTES, 'UTF-8'); ?>)'>
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
                    <button type="button" class="rp-modal__close" aria-label="Close" onclick="closePlanModal()">&times;</button>
                </div>

                <div class="rp-modal__body">

                    <p class="rp-section-title">1. Name and when it applies</p>
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
                                <input type="number" id="fieldDaysBeforeMinEB" name="eb_days_before_min" min="0" placeholder="e.g. 30">
                            </div>
                            <div class="form-group">
                                <label for="fieldDaysBeforeMaxEB">Max Days Before Arrival (optional)</label>
                                <input type="number" id="fieldDaysBeforeMaxEB" name="eb_days_before_max" min="0" placeholder="Leave empty = no max">
                            </div>
                        </div>
                        <p class="form-hint">Example: min 30 = book at least 30 days in advance.</p>
                    </div>

                    <hr style="margin: 18px 0; border-color: #e9ecef;">

                    <p class="rp-section-title">2. Which room types<small>Pick the room types this plan is for. Their prices are set in the next part.</small></p>
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

                    <p class="rp-section-title">3. Price change<small>How much the price goes up or down, for all room types or each one on its own.</small></p>
                    <?php if ($rp086): ?>
                        <!-- Step 8h: amount per room type, price basis, live breakdown -->
                        <div class="form-group">
                            <label>Amount by room type</label>
                            <div class="rp-seg">
                                <label><input type="radio" name="adj_mode" value="same" id="fieldAdjModeSame" checked> Same for all room types</label>
                                <label><input type="radio" name="adj_mode" value="per_room" id="fieldAdjModePerRoom"> Different per room type</label>
                            </div>
                            <p class="form-hint">Different per room type: give a room type its own % or fixed amount. Leave a room type empty to use the amount below. One plan is either all increases or all discounts.</p>
                        </div>
                        <div class="rp-room-rows" id="rpRoomRows" style="display:none;">
                            <?php foreach ($rpRooms as $rpRid => $rpInfo): ?>
                                <div class="rp-room-row" data-room="<?php echo (int)$rpRid; ?>">
                                    <span class="rp-room-row__name"><?php echo htmlspecialchars($rpInfo['name']); ?>
                                        <small title="The room type's price per night with VAT and levy included">Standard <?php echo htmlspecialchars($rpCur . ' ' . rh_rate_plan_money((float)$rpInfo['standard'])); ?></small></span>
                                    <select name="room_adj[<?php echo (int)$rpRid; ?>][type]" aria-label="<?php echo htmlspecialchars($rpInfo['name']); ?> adjustment type">
                                        <option value="percentage">Percentage (%)</option>
                                        <option value="fixed">Fixed (<?php echo htmlspecialchars($rpCur); ?>)</option>
                                    </select>
                                    <input type="number" step="0.01" name="room_adj[<?php echo (int)$rpRid; ?>][value]" placeholder="Plan amount" aria-label="<?php echo htmlspecialchars($rpInfo['name']); ?> adjustment value">
                                </div>
                            <?php endforeach; ?>
                        </div>
                    <?php endif; ?>

                    <!-- Adjustment (plan amount) -->
                    <?php if ($rp086): ?><p class="form-hint rp-default-title" id="rpDefaultTitle" style="display:none; font-weight:600; color:#343a40; margin:4px 0 8px;">Amount for any room type left empty above</p><?php endif; ?>
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
                            <p class="form-hint">Negative = discount &nbsp;|&nbsp; Positive = surcharge<?php if ($rp086): ?>. <span id="rpDefaultHint">Used for every room type.</span><?php endif; ?></p>
                        </div>
                    </div>

                    <?php if ($rp086): ?>
                        <?php if ($rpBasisMatters): ?>
                            <div class="form-group">
                                <label for="fieldPriceBasis">Apply the adjustment to</label>
                                <select id="fieldPriceBasis" name="price_basis">
                                    <option value="all_in">The all-in price (<?php echo htmlspecialchars($rpTaxLabel); ?> included)</option>
                                    <option value="base">The base rate before <?php echo htmlspecialchars($rpTaxLabel); ?></option>
                                </select>
                                <p class="form-hint">A percentage gives the same guest total on either choice, so nothing below changes for a percentage. A fixed amount on the base rate adds <?php echo htmlspecialchars($rpTaxLabel); ?> on top: <?php echo htmlspecialchars($rpCur); ?> 1,000 there costs the guest <?php echo htmlspecialchars($rpCur . ' ' . rh_rate_plan_money(round(1000 * $rpFactor, 2))); ?>.</p>
                            </div>
                        <?php else: ?>
                            <input type="hidden" name="price_basis" id="fieldPriceBasis" value="all_in">
                        <?php endif; ?>
                        <div class="form-group">
                            <label>What each room type will cost</label>
                            <p class="form-hint">Per night, for a stay this plan matches, with this plan alone. Standard price = the room type's price per night with VAT and levy included. Base = the rate before VAT and levy.</p>
                            <div class="rp-breakdown" id="rpBreakdown" aria-live="polite"></div>
                        </div>
                    <?php endif; ?>

                    <hr style="margin: 18px 0; border-color: #e9ecef;">

                    <!-- Control -->
                    <p class="rp-section-title">4. Settings</p>
                    <div class="form-row">
                        <div class="form-group">
                            <label for="fieldPriority">Priority (0–255)</label>
                            <input type="number" id="fieldPriority" name="priority" min="0" max="255" value="0">
                            <p class="form-hint">Higher priority wins a tie and comes first when stacking plans are applied.</p>
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

                    <div id="planModalFeedback" class="admin-modal-feedback"></div>

                </div><!-- /.rp-modal__body -->

                <div class="rp-modal__footer">
                    <button type="button" class="btn btn-secondary" onclick="closePlanModal()">Close</button>
                    <button type="submit" id="planSaveBtn" class="btn btn-primary"><i class="fas fa-save"></i> Save Rate Plan</button>
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
            if (typeof rpFillRoomRows === 'function') {
                if (!plan && typeof setRoomTypeCheckboxes === 'function') setRoomTypeCheckboxes(null);
                rpFillRoomRows(plan || null);
                rpPreview();
            }

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
                    // The message carries admin-typed plan names (overlap note): plain text, never HTML.
                    const rpMsg = String(res.message || '').replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;').replace(/"/g, '&quot;').replace(/'/g, '&#039;');
                    fb.innerHTML = '<i class="fas fa-' + (res.success ? 'check-circle' : 'exclamation-circle') + '"></i> ' + rpMsg;
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

            // Rule fields are hidden with a class, not disabled, so FormData still
            // submits them. Switching a plan from Seasonal to any other rule type would
            // otherwise carry its old date window into the database, where it sits as
            // dead data that _planMatchesStay() ignores for every non-seasonal rule —
            // misleading to read, and a trap if window gating is ever added.
            if (type !== 'seasonal') {
                document.getElementById('fieldStartDate').value = '';
                document.getElementById('fieldEndDate').value = '';
            }

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
                document.getElementById('fieldDaysBeforeMaxLM').value = plan ? (plan.days_before_max !== null ? plan.days_before_max : '') : '';
            } else if (type === 'early_bird') {
                document.getElementById('fields-early_bird').classList.add('visible');
                document.getElementById('fieldDaysBeforeMinEB').value = plan ? (plan.days_before_min !== null ? plan.days_before_min : '') : '';
                document.getElementById('fieldDaysBeforeMaxEB').value = plan ? (plan.days_before_max !== null ? plan.days_before_max : '') : '';
            }
            // promotion: no extra fields
        }

        function onAppliesToChange(val) {
            document.getElementById('roomTypesField').style.display = val === 'room_types' ? 'block' : 'none';
            if (typeof rpSyncRoomRows === 'function') rpSyncRoomRows();
        }

        <?php if ($rp086): ?>
            // ── Step 8h: amount per room type + live breakdown (the numbers come from the server: action=preview) ──
            const rpCur = <?php echo json_encode($rpCur); ?>;

            function rpEsc(s) {
                return String(s == null ? '' : s).replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;').replace(/"/g, '&quot;').replace(/'/g, '&#039;');
            }

            function rpMoney(v) {
                const n = Number(v || 0);
                const dec = Math.abs(n - Math.round(n)) > 0.004 ? 2 : 0;
                return n.toLocaleString('en-US', { minimumFractionDigits: dec, maximumFractionDigits: dec });
            }

            function rpPct(v) {
                return String(Math.round(Math.abs(Number(v || 0)) * 100) / 100);
            }

            function rpMode() {
                const per = document.getElementById('fieldAdjModePerRoom');
                return per && per.checked ? 'per_room' : 'same';
            }

            // Show only the room types the plan covers, and only in "Different per room type".
            function rpSyncRoomRows() {
                const box = document.getElementById('rpRoomRows');
                if (!box) return;
                box.style.display = rpMode() === 'per_room' ? 'block' : 'none';
                // "Same for all": one amount for every room type. "Different": the rows first, then this amount for any row left empty.
                const dt = document.getElementById('rpDefaultTitle');
                if (dt) dt.style.display = rpMode() === 'per_room' ? 'block' : 'none';
                const dh = document.getElementById('rpDefaultHint');
                if (dh) dh.textContent = rpMode() === 'per_room' ? 'Used for every room type left empty above. Can stay empty when the plan is for chosen room types and each has its own amount.' :'Used for every room type.';
                const all = document.getElementById('fieldAppliesTo').value !== 'room_types';
                const chosen = Array.from(document.querySelectorAll('.rp-room-chk:checked')).map(c => c.value);
                box.querySelectorAll('.rp-room-row').forEach(row => {
                    row.style.display = (all || chosen.indexOf(row.dataset.room) !== -1) ? '' : 'none';
                });
            }

            function rpFillRoomRows(plan) {
                let ov = {};
                if (plan && plan.room_adjustments) {
                    try { ov = JSON.parse(plan.room_adjustments) || {}; } catch (e) { ov = {}; }
                }
                document.querySelectorAll('#rpRoomRows .rp-room-row').forEach(row => {
                    const o = ov[String(row.dataset.room)];
                    row.querySelector('select').value = o && o.type === 'fixed' ? 'fixed' : 'percentage';
                    row.querySelector('input').value = o ? o.value : '';
                });
                const per = Object.keys(ov).length > 0;
                document.getElementById('fieldAdjModePerRoom').checked = per;
                document.getElementById('fieldAdjModeSame').checked = !per;
                const basis = document.getElementById('fieldPriceBasis');
                if (basis && basis.tagName === 'SELECT') basis.value = plan && plan.price_basis === 'base' ? 'base' : 'all_in';
                rpSyncRoomRows();
            }

            function rpRenderBreakdown(rows) {
                const box = document.getElementById('rpBreakdown');
                if (!box) return;
                if (!rows || !rows.length) {
                    box.innerHTML = '<p class="form-hint">Enter an adjustment value to see each room type\'s new price.</p>';
                    return;
                }
                box.innerHTML = rows.map(r => {
                    const split = (p) => {
                        const parts = ['Base ' + rpMoney(p.base)];
                        if (Number(r.vat_rate) > 0) parts.push('VAT ' + rpMoney(p.vat));
                        if (Number(r.levy_rate) > 0) parts.push('Levy ' + rpMoney(p.levy));
                        return parts.join(' + ');
                    };
                    const up = Number(r.diff) >= 0;
                    const change = (up ? 'Up by ' : 'Down by ') + rpCur + ' ' + rpMoney(Math.abs(r.diff)) + (r.pct !== null ? ' (' + rpPct(r.pct) + '%)' : '');
                    return '<div class="rp-bd">' +
                        '<div class="rp-bd__head"><span>' + rpEsc(r.name) + '</span><span class="' + (up ? 'adj-value--surcharge' : 'adj-value--discount') + '">' + rpEsc(r.adjustment_text) + (r.adjustment.override ? '' : ' (plan amount)') + '</span></div>' +
                        '<div class="rp-bd__line" title="The room type\'s price per night with VAT and levy included"><span>Standard price</span><span>' + rpCur + ' ' + rpMoney(r.standard) + '</span></div>' +
                        '<div class="rp-bd__line rp-bd__split" title="How the standard price splits: rate before tax, then VAT and levy"><span>Made of</span><span>' + rpEsc(split(r.before)) + '</span></div>' +
                        '<div class="rp-bd__line" title="What the guest pays per night with this plan"><span>New price</span><span><strong>' + rpCur + ' ' + rpMoney(r.new) + '</strong></span></div>' +
                        '<div class="rp-bd__line rp-bd__split" title="How the new price splits: rate before tax, then VAT and levy"><span>Made of</span><span>' + rpEsc(split(r.after)) + '</span></div>' +
                        '<div class="rp-bd__change ' + (up ? 'adj-value--surcharge' : 'adj-value--discount') + '" title="New price minus standard price, per night">' + rpEsc(change) + ' per night</div>' +
                        '</div>';
                }).join('');
            }

            let rpPreviewTimer = null;
            let rpPreviewSeq = 0;

            function rpPreview() {
                clearTimeout(rpPreviewTimer);
                rpPreviewTimer = setTimeout(function() {
                    const fd = new FormData(document.getElementById('planForm'));
                    fd.set('action', 'preview');
                    const seq = ++rpPreviewSeq;
                    fetch(window.location.pathname, { method: 'POST', headers: { 'X-Requested-With': 'XMLHttpRequest' }, body: fd })
                        .then(r => r.json())
                        .then(res => { if (seq === rpPreviewSeq && res && res.success) rpRenderBreakdown(res.rows); })
                        .catch(function() {});
                }, 300);
            }

            document.getElementById('planForm').addEventListener('change', function() { rpSyncRoomRows(); rpPreview(); });
            document.getElementById('planForm').addEventListener('input', function(e) {
                if (e.target && e.target.type === 'number') rpPreview();
            });
        <?php endif; ?>

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
            if (typeof rpSyncRoomRows === 'function') {
                rpSyncRoomRows();
                rpPreview();
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

