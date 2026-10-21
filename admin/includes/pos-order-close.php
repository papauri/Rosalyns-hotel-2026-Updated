<?php

/**
 * Closing a POS / room-service order - the ONE implementation behind api/cancel-order.php,
 * api/void-order.php, admin/stock-orders.php (cancel + void) and the booking cancel / delete hook.
 *
 * Both functions run inside the CALLER's transaction (they never begin / commit / roll back; they
 * throw RuntimeException when none is open) and never send anything themselves: post-commit
 * notices come back in the result for the caller to send with rh_pos_notice_send().
 * Do not call rh_pos_notice_send() inside the transaction (it can run DDL).
 */

require_once __DIR__ . '/restaurant-payment-sync.php';
require_once __DIR__ . '/pos-notices.php';

if (!function_exists('rh_pos_close_has_charge_order_col')) {
    /** booking_charges.stock_order_id exists (legacy schemas may lack it). Cached per request. */
    function rh_pos_close_has_charge_order_col(PDO $pdo): bool
    {
        static $has = null;
        if ($has === null) {
            $st = $pdo->prepare("SELECT COUNT(*) FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = 'booking_charges' AND column_name = 'stock_order_id'");
            $st->execute();
            $has = ((int)$st->fetchColumn() > 0);
        }
        return $has;
    }
}

if (!function_exists('rh_close_pos_order')) {
    /**
     * Cancel (nothing started, nothing paid) or void one order. The caller has already checked who
     * may do it and that the order is in a state it accepts; this function re-locks the order and
     * refuses one that is already cancelled / voided / refunded.
     *
     * One transaction, in this order: stock restored (line still pending) or written off as wastage
     * (food already started), live room folio charges voided (and their stock settled the same way),
     * order marked cancelled / voided with who / when / why, every open line set to kds 'void',
     * the payment reversal (void only; contra rows, originals untouched), ready pings deleted,
     * station notes acknowledged, a "STOP" note to the stations that had started the food, audit and
     * KDS event rows. It does NOT recalculate booking financials: the caller does that for
     * 'booking_ids' (still inside its transaction).
     *
     * @param string $reason  text stored as void_reason / audit details; for the creator notice only the
     *                        part before "\nNotes: " is quoted
     * @param string $mode    'cancel' | 'void'
     * @param array $opts   optional: 'damaged' (bool), 'shop_mode' (bool, test override); see rh_restore_pos_order_stock()
     * @return array{mode:string,order_id:int,reference:string,order_type:string,created_by:int,
     *               status:string,kitchen_status_before:string,lines:int,stock_restored:int,stock_wasted:int,
     *               folio_voided:int,booking_ids:int[],reversal:array,stopped_stations:string[],notices:array}
     */
    function rh_close_pos_order(PDO $pdo, int $orderId, int $userId, string $reason, string $mode, array $opts = []): array
    {
        if (!$pdo->inTransaction()) {
            throw new RuntimeException('rh_close_pos_order must run inside the caller\'s transaction.');
        }
        if (!in_array($mode, ['cancel', 'void'], true)) {
            throw new RuntimeException('Unknown close mode.');
        }
        $isVoid = ($mode === 'void');
        $details = trim($reason);

        $oh = $pdo->prepare("SELECT id, reference, status, order_type, booking_id, created_by, kitchen_status FROM stock_orders WHERE id = ? FOR UPDATE");
        $oh->execute([$orderId]);
        $order = $oh->fetch(PDO::FETCH_ASSOC);
        if (!$order) {
            throw new RuntimeException('Order not found.');
        }
        $ref = (string)$order['reference'];
        if (in_array((string)$order['status'], ['cancelled', 'voided', 'refunded'], true)) {
            throw new RuntimeException('Order ' . $ref . ' is already ' . (string)$order['status'] . '.');
        }

        $actor = ['full_name' => '', 'username' => '', 'role' => ''];
        try {
            $as = $pdo->prepare("SELECT full_name, username, role FROM admin_users WHERE id = ?");
            $as->execute([$userId]);
            $row = $as->fetch(PDO::FETCH_ASSOC);
            if ($row) {
                $actor = $row;
            }
        } catch (Throwable $e) {
            // name is cosmetic
        }
        $actorName = (string)($actor['full_name'] ?: ($actor['username'] ?: 'user'));
        $ip = $_SERVER['REMOTE_ADDR'] ?? null;
        // System closes (guest self-cancel, auto-release) run as user 0: store no actor rather than id 0.
        $actorId = $userId > 0 ? $userId : null;

        // What state the lines are in decides restore vs wastage and who gets a STOP note.
        $ls = $pdo->prepare("SELECT id, station, kds_status, stock_deducted FROM stock_order_items WHERE order_id = ? FOR UPDATE");
        $ls->execute([$orderId]);
        $lines = $ls->fetchAll(PDO::FETCH_ASSOC);
        $stockRestored = 0;
        $stockWasted = 0;
        $stopStations = [];
        foreach ($lines as $ln) {
            $k = (string)$ln['kds_status'];
            if ($k === 'void') {
                continue;
            }
            if ($k === 'pending') {
                if ((int)$ln['stock_deducted'] === 1) {
                    $stockRestored++;
                }
                continue;
            }
            $stockWasted++;
            if (in_array($k, ['preparing', 'in_progress', 'ready', 'collection'], true) && in_array((string)$ln['station'], ['kitchen', 'bar', 'coffee_bar'], true)) {
                $stopStations[(string)$ln['station']] = true;
            }
        }
        $stopStations = array_keys($stopStations);

        // Stock first: the POS restore skips lines whose folio charge is still live, so voiding the
        // charges first would waste their stock twice.
        // Shop / supermarket mode: sold goods go back on the shelf unless the void is flagged "damaged"
        // ($opts['damaged'] or POST 'damaged'); restaurant / bar keeps the kitchen rule (started = wastage).
        $restoreSummary = ['restored' => 0, 'wasted' => 0];
        rh_restore_pos_order_stock($pdo, $orderId, $userId, $isVoid ? 'POS order voided (admin)' : 'POS order cancelled before prep', $opts, $restoreSummary);
        $shopMode = array_key_exists('shop_mode', $opts) ? (bool)$opts['shop_mode'] : rh_pos_shop_mode();
        $damaged = $shopMode && rh_pos_damaged_flag($opts);
        if ($shopMode) {
            $stockRestored = $restoreSummary['restored'];
            $stockWasted = $restoreSummary['wasted'];
        }

        $folioVoided = 0;
        $bookingIds = [];
        if (rh_pos_close_has_charge_order_col($pdo)) {
            $cs = $pdo->prepare("SELECT id, booking_id, charge_type, source_item_id, quantity, stock_tracked FROM booking_charges WHERE stock_order_id = ? AND voided = 0 FOR UPDATE");
            $cs->execute([$orderId]);
            $charges = $cs->fetchAll(PDO::FETCH_ASSOC);
            if ($charges) {
                $chargeReason = $isVoid ? $details : 'Room-service order cancelled before prep: ' . $details;
                $cu = $pdo->prepare("UPDATE booking_charges SET voided = 1, voided_at = NOW(), void_reason = ?, voided_by = ?, updated_at = NOW() WHERE id = ?");
                foreach ($charges as $charge) {
                    $cu->execute([mb_substr($chargeReason, 0, 255), $actorId, (int)$charge['id']]);
                    $outcome = rh_restore_room_service_charge_stock($pdo, $orderId, $charge, ($isVoid ? 'Room service order voided: ' : 'Room service order cancelled: ') . $chargeReason, $userId);
                    if ($outcome === 'restored') {
                        $stockRestored++;
                    } elseif ($outcome === 'wastage') {
                        $stockWasted++;
                    }
                    $bookingIds[(int)$charge['booking_id']] = true;
                    $folioVoided++;
                }
            }
        }

        $newStatus = $isVoid ? 'voided' : 'cancelled';
        $pdo->prepare("UPDATE stock_orders SET status = ?, voided_by = ?, voided_at = NOW(), void_reason = ?, updated_at = NOW(), kitchen_status = 'served', served_at = COALESCE(served_at, NOW()) WHERE id = ?")
            ->execute([$newStatus, $actorId, mb_substr($details, 0, 500), $orderId]);
        $pdo->prepare("UPDATE stock_order_items SET kds_status = 'void', served_at = COALESCE(served_at, NOW()), bumped_by = ? WHERE order_id = ? AND kds_status NOT IN ('served', 'void')")
            ->execute([$userId, $orderId]);

        // Void = reverse the sale with contra rows (nothing to reverse when it was never paid).
        $reversal = ['rows' => 0, 'reversed_gross' => 0.0, 'nothing_to_refund' => true];
        if ($isVoid) {
            $reversal = rh_reverse_restaurant_payments($pdo, $orderId, $ref !== '' ? $ref : ('ORD' . $orderId), 'VOID-', 'cancellation', 'Void: ' . $details, $userId);
        }

        // A closed order must stop asking the floor to collect it, and its station notes are handled.
        try {
            $pdo->prepare("DELETE FROM pos_ready_notifications WHERE order_id = ?")->execute([$orderId]);
            $pdo->prepare("UPDATE station_messages SET pos_acknowledged = 1, pos_acknowledged_at = NOW(), pos_acknowledged_by = ? WHERE order_id = ? AND source = 'station' AND COALESCE(pos_acknowledged, 0) = 0")
                ->execute([$userId, $orderId]);
        } catch (Throwable $e) {
            error_log('rh_close_pos_order notification cleanup: ' . $e->getMessage());
        }

        // Stations that had already started cooking / pouring it are told to stop.
        if ($stopStations) {
            $stopMsg = 'STOP — order ' . $ref . ' ' . ($isVoid ? 'voided' : 'cancelled') . '. Do not serve it.';
            $short = trim(explode("\nNotes: ", $details)[0]);
            if ($short !== '') {
                $stopMsg .= ' Reason: ' . $short;
            }
            $stopMsg = mb_substr($stopMsg, 0, 255);
            foreach ($stopStations as $stn) {
                try {
                    $pdo->prepare("INSERT INTO station_messages (station, message, sent_by, sent_by_name, source, priority, order_id, order_ref, is_acknowledged, acknowledged_at) VALUES (?, ?, ?, ?, 'pos', 'urgent', ?, ?, 0, NULL)")
                        ->execute([$stn, $stopMsg, $actorId, mb_substr($actorName, 0, 120), $orderId, $ref]);
                } catch (Throwable $e) {
                    error_log('rh_close_pos_order STOP note: ' . $e->getMessage());
                }
            }
        }

        $pdo->prepare("INSERT INTO stock_order_audit (order_id, actor_id, actor_name, event, details, ip_address) VALUES (?, ?, ?, ?, ?, ?)")
            ->execute([$orderId, $actorId, $actorName, $isVoid ? 'voided' : 'cancelled_before_prep', $details, $ip]);
        try {
            $pdo->prepare("INSERT INTO stock_kds_events (order_id, event, from_status, to_status, user_id, user_name, ip_address) VALUES (?, ?, ?, 'void', ?, ?, ?)")
                ->execute([$orderId, $isVoid ? 'voided' : 'cancelled', $isVoid ? 'in_progress' : (string)$order['kitchen_status'], $userId, $actorName, $ip]);
        } catch (Throwable $e) {
            // legacy event schema
        }

        // Creator notice (post-commit, caller sends): the cashier who opened it learns it was closed elsewhere.
        $notices = [];
        $creator = (int)($order['created_by'] ?? 0);
        if ($creator > 0 && $creator !== $userId) {
            $short = trim(explode("\nNotes: ", $details)[0]);
            if ($isVoid) {
                $head = 'Order ' . $ref . ' was VOIDED by ' . rh_pos_notice_actor_label((string)$actor['role']) . ' (' . $actorName . ').';
                $money = '';
                if ((float)($reversal['reversed_gross'] ?? 0) > 0) {
                    $money = ' Payment of ' . number_format((float)$reversal['reversed_gross'], 2) . ' (excl. tip) was reversed and counted as paid out on ' . $actorName . "'s shift - hand the guest their money back from that drawer.";
                }
                if (mb_strlen($head . $money) > 235) {
                    $head = 'Order ' . $ref . ' was VOIDED.';
                }
                $room = 255 - mb_strlen($head . $money) - 9; // ' Reason: '
                $rt = $room >= 12 ? (mb_strlen($short) > $room ? mb_substr($short, 0, $room - 1) . '…' : $short) : '';
                $msg = $head . $money . ($rt !== '' ? ' Reason: ' . $rt : '');
                $type = 'order_voided';
            } else {
                $msg = 'Order ' . $ref . ' was CANCELLED by ' . rh_pos_notice_actor_label((string)$actor['role']) . ' (' . $actorName . '): ' . $short;
                $type = 'order_cancelled';
            }
            $notices[] = [
                'station'      => 'kitchen',
                'message'      => $msg,
                'sent_by'      => $actorId,
                'sent_by_name' => $actorName,
                'order_id'     => $orderId,
                'order_ref'    => $ref,
                'to_user_id'   => $creator,
                'priority'     => 'urgent',
                'notice_type'  => $type,
            ];
        }

        return [
            'mode'                  => $mode,
            'order_id'              => $orderId,
            'reference'             => $ref,
            'order_type'            => (string)$order['order_type'],
            'created_by'            => $creator,
            'status'                => $newStatus,
            'kitchen_status_before' => (string)$order['kitchen_status'],
            'lines'                 => count($lines),
            'stock_restored'        => $stockRestored,
            'stock_wasted'          => $stockWasted,
            'shop_mode'             => $shopMode,
            'damaged'               => $damaged,
            'folio_voided'          => $folioVoided,
            'booking_ids'           => array_keys($bookingIds),
            'reversal'              => $reversal,
            'stopped_stations'      => $stopStations,
            'notices'               => $notices,
        ];
    }
}

if (!function_exists('rh_close_order_keep_started')) {
    /**
     * Close an order of a booking that is being cancelled / deleted / no-showed WITHOUT writing off the
     * food the guest already got (owner decision 2026-10-20: consumed room service stays billable).
     *
     *   - lines the kitchen started or served (preparing / ready / collection / served): stay CHARGED on
     *     the booking folio. A room-service line already has its booking_charges row (posted when the order
     *     was placed) - it is simply left live. A started line without one (legacy order) is posted now at
     *     the line's own price, with its stock deducted if the kitchen had not already.
     *   - lines not started (pending): cancelled - kds 'void', their folio charge voided and their stock
     *     restored (same rule as an 86).
     *   - the order is closed (status 'completed', folio_posted_at set) so no tab stays open; its totals
     *     shrink to the kept lines; stations that are already cooking something on this order get a STOP
     *     note naming ONLY the cancelled lines; the cashier who opened it gets a notice (caller sends it
     *     after commit).
     * Does not recalculate the booking (the caller does, still inside its transaction). Throws
     * RuntimeException (caller rolls back) when a charge cannot be posted or stock cannot be restored.
     *
     * @return array{mode:string,order_id:int,reference:string,kept_lines:int,kept_total:float,cancelled_lines:int,
     *               charges_posted:int,stock_restored:int,folio_voided:int,booking_ids:int[],stopped_stations:string[],notices:array}
     */
    function rh_close_order_keep_started(PDO $pdo, int $orderId, int $userId, string $reason): array
    {
        if (!$pdo->inTransaction()) {
            throw new RuntimeException('rh_close_order_keep_started must run inside the caller\'s transaction.');
        }
        $startedStates = ['preparing', 'ready', 'collection', 'served'];
        $details = trim($reason);

        $oh = $pdo->prepare("SELECT id, reference, status, order_type, booking_id, created_by, kitchen_status, subtotal, total_amount, total_cost, tax_amount, discount_amount, service_charge
                               FROM stock_orders WHERE id = ? FOR UPDATE");
        $oh->execute([$orderId]);
        $order = $oh->fetch(PDO::FETCH_ASSOC);
        if (!$order) {
            throw new RuntimeException('Order not found.');
        }
        $ref = (string)$order['reference'];
        if (!in_array((string)$order['status'], ['placed', 'completed'], true)) {
            throw new RuntimeException('Order ' . $ref . ' is already ' . (string)$order['status'] . '.');
        }
        $bookingId = (int)($order['booking_id'] ?? 0);

        $actor = ['full_name' => '', 'username' => '', 'role' => ''];
        try {
            $as = $pdo->prepare("SELECT full_name, username, role FROM admin_users WHERE id = ?");
            $as->execute([$userId]);
            $row = $as->fetch(PDO::FETCH_ASSOC);
            if ($row) {
                $actor = $row;
            }
        } catch (Throwable $e) {
            // name is cosmetic
        }
        $actorName = (string)($actor['full_name'] ?: ($actor['username'] ?: 'user'));
        $ip = $_SERVER['REMOTE_ADDR'] ?? null;
        $actorId = $userId > 0 ? $userId : null;

        $ls = $pdo->prepare("SELECT id, menu_item_id, menu_type, item_name, quantity, unit_price, line_total, station, kds_status, stock_deducted
                               FROM stock_order_items WHERE order_id = ? ORDER BY id ASC FOR UPDATE");
        $ls->execute([$orderId]);
        $lines = $ls->fetchAll(PDO::FETCH_ASSOC);

        $hasChargeCol = rh_pos_close_has_charge_order_col($pdo);
        $charges = [];
        if ($hasChargeCol) {
            $cs = $pdo->prepare("SELECT id, booking_id, charge_type, source_item_id, quantity, line_total, stock_tracked FROM booking_charges WHERE stock_order_id = ? AND voided = 0 ORDER BY id ASC FOR UPDATE");
            $cs->execute([$orderId]);
            $charges = $cs->fetchAll(PDO::FETCH_ASSOC);
        }
        // Pair each live line with its folio charge (same menu item + quantity), each charge used once.
        $used = [];
        $chargeOf = [];
        foreach ($lines as $ln) {
            if ((string)$ln['kds_status'] === 'void') {
                continue;
            }
            foreach ($charges as $ci => $ch) {
                if (isset($used[$ci])) {
                    continue;
                }
                if ((int)$ch['source_item_id'] === (int)$ln['menu_item_id'] && abs((float)$ch['quantity'] - (float)$ln['quantity']) < 0.005) {
                    $used[$ci] = true;
                    $chargeOf[(int)$ln['id']] = $ch;
                    break;
                }
            }
        }
        if (!$bookingId && $charges) {
            $bookingId = (int)$charges[0]['booking_id'];
        }

        $short = trim(explode("\nNotes: ", $details)[0]);
        $lineReason = mb_substr('Booking closed, line not started: ' . ($short !== '' ? $short : 'order cancelled'), 0, 255);
        $bookingIds = [];
        $keptLines = 0;
        $keptNames = [];
        $cancelNames = [];
        $cancelStations = [];
        $startedStations = [];
        $cancelGross = 0.0;
        $posted = 0;
        $restored = 0;
        $folioVoided = 0;
        $costDrop = 0.0;
        $voidLine = $pdo->prepare("UPDATE stock_order_items SET kds_status = 'void', served_at = COALESCE(served_at, NOW()), bumped_by = ?, stock_deducted = 0 WHERE id = ?");
        $voidCharge = $pdo->prepare("UPDATE booking_charges SET voided = 1, voided_at = NOW(), void_reason = ?, voided_by = ?, updated_at = NOW() WHERE id = ?");

        foreach ($lines as $ln) {
            $k = (string)$ln['kds_status'];
            if ($k === 'void') {
                continue;
            }
            $lineId = (int)$ln['id'];
            $qty = (float)$ln['quantity'];
            $itemId = (int)$ln['menu_item_id'];
            $menuType = (string)$ln['menu_type'];
            $charge = $chargeOf[$lineId] ?? null;

            if (in_array($k, $startedStates, true)) {
                // KEEP: the food exists / was consumed - it stays on the guest's bill.
                $keptLines++;
                $keptNames[] = (string)$ln['item_name'];
                $startedStations[(string)$ln['station']] = true;
                if ($charge) {
                    $bookingIds[(int)$charge['booking_id']] = true;
                    continue;
                }
                if ($bookingId <= 0) {
                    throw new RuntimeException('Order ' . $ref . ' has food the kitchen started but is not linked to a booking, so it cannot be charged. Charge or void it in Orders & Tabs first.');
                }
                $unit = round((float)$ln['unit_price'], 2);
                if ($unit <= 0 && $qty > 0) {
                    $unit = round((float)$ln['line_total'] / $qty, 2);
                }
                $type = ((string)$ln['station'] === 'kitchen') ? 'food' : 'drink';
                $res = addBookingCharge($bookingId, $type, (string)$ln['item_name'], $qty, $unit, $itemId > 0 ? $itemId : null, $userId > 0 ? $userId : null);
                if (empty($res['success']) || empty($res['charge_id'])) {
                    throw new RuntimeException('Could not charge ' . (string)$ln['item_name'] . ' (order ' . $ref . ') to the booking folio' . (!empty($res['message']) ? ' (' . $res['message'] . ')' : '') . '. Nothing was changed.');
                }
                $chargeId = (int)$res['charge_id'];
                if ($hasChargeCol) {
                    $pdo->prepare("UPDATE booking_charges SET stock_order_id = ? WHERE id = ?")->execute([$orderId, $chargeId]);
                }
                if ((int)$ln['stock_deducted'] !== 1 && $itemId > 0 && function_exists('deductStockForMenuItem')) {
                    try {
                        $rc = $pdo->prepare("SELECT COUNT(sri.id) FROM stock_recipes sr JOIN stock_recipe_ingredients sri ON sri.recipe_id = sr.id WHERE sr.menu_item_id = ? AND sr.menu_type = ?");
                        $rc->execute([$itemId, $menuType]);
                        $hasRecipe = ((int)$rc->fetchColumn() > 0);
                        if (deductStockForMenuItem($itemId, $menuType, $qty, 'room_service', $chargeId, $userId > 0 ? $userId : null)) {
                            if ($hasRecipe) {
                                $pdo->prepare("UPDATE booking_charges SET stock_tracked = 1 WHERE id = ?")->execute([$chargeId]);
                            }
                            $pdo->prepare("UPDATE stock_order_items SET stock_deducted = 1 WHERE id = ?")->execute([$lineId]);
                        }
                    } catch (Throwable $e) {
                        error_log('rh_close_order_keep_started stock deduction (charge ' . $chargeId . '): ' . $e->getMessage());
                    }
                }
                $posted++;
                $bookingIds[$bookingId] = true;
                continue;
            }

            // CANCEL: not started -> stock back on the shelf, folio charge voided, line void.
            if ($charge) {
                $voidCharge->execute([$lineReason, $actorId, (int)$charge['id']]);
                $folioVoided++;
                $bookingIds[(int)$charge['booking_id']] = true;
                if (!empty($charge['stock_tracked'])) {
                    if (!restoreStockForMenuItem($itemId, $menuType !== '' ? $menuType : (string)$charge['charge_type'], $qty, 'Booking closed: line not started', $userId, (int)$charge['id'], 'room_service')) {
                        throw new RuntimeException('Stock restore failed for ' . (string)$ln['item_name'] . ' - nothing was changed. Please retry or contact a manager.');
                    }
                    $restored++;
                } elseif ((int)$ln['stock_deducted'] === 1) {
                    if (!restoreStockForMenuItem($itemId, $menuType, $qty, 'Booking closed: line not started', $userId, $lineId, 'pos_order')) {
                        throw new RuntimeException('Stock restore failed for ' . (string)$ln['item_name'] . ' - nothing was changed. Please retry or contact a manager.');
                    }
                    $restored++;
                }
            } elseif ((int)$ln['stock_deducted'] === 1) {
                if (!restoreStockForMenuItem($itemId, $menuType, $qty, 'Booking closed: line not started', $userId, $lineId, 'pos_order')) {
                    throw new RuntimeException('Stock restore failed for ' . (string)$ln['item_name'] . ' - nothing was changed. Please retry or contact a manager.');
                }
                $restored++;
            }
            $voidLine->execute([$userId, $lineId]);
            $lg = round((float)$ln['line_total'], 2);
            if ($lg <= 0) {
                $lg = round((float)$ln['unit_price'] * $qty, 2);
            }
            $cancelGross = round($cancelGross + $lg, 2);
            $cancelNames[] = (string)$ln['item_name'];
            $cancelStations[(string)$ln['station']] = true;
            try {
                $cst = $pdo->prepare("SELECT COALESCE(SUM((sri.quantity_per_portion / " . rh_stock_yield_sql('sri.yield_percent') . ") * i.cost_per_unit), 0)
                                        FROM stock_recipes sr INNER JOIN stock_recipe_ingredients sri ON sri.recipe_id = sr.id
                                        INNER JOIN stock_ingredients i ON i.id = sri.ingredient_id WHERE sr.menu_item_id = ? AND sr.menu_type = ?");
                $cst->execute([$itemId, $menuType]);
                $costDrop += round(((float)$cst->fetchColumn()) * $qty, 4);
            } catch (Throwable $e) {
                // cost is informational only
            }
            try {
                $pdo->prepare("INSERT INTO stock_kds_events (order_id, order_item_id, event, from_status, to_status, user_id, user_name, ip_address) VALUES (?, ?, 'voided', ?, 'void', ?, ?, ?)")
                    ->execute([$orderId, $lineId, $k, $userId, $actorName, $ip]);
            } catch (Throwable $e) {
                // legacy event schema
            }
        }

        // Order totals shrink to the kept lines (same scaling as an 86); the order closes.
        $oldSub = (float)($order['subtotal'] ?: $order['total_amount']);
        $oldTot = (float)$order['total_amount'];
        $newSub = max(0.0, round($oldSub - $cancelGross, 2));
        $scale = $oldSub > 0 ? ($newSub / $oldSub) : 1.0;
        $newTot = $cancelGross > 0 ? max(0.0, round($oldTot * $scale, 2)) : $oldTot;
        $live = $pdo->prepare("SELECT kds_status FROM stock_order_items WHERE order_id = ? AND kds_status <> 'void'");
        $live->execute([$orderId]);
        $liveStates = $live->fetchAll(PDO::FETCH_COLUMN);
        $kitchen = (string)$order['kitchen_status'];
        if ($liveStates) {
            if (count(array_diff($liveStates, ['served'])) === 0) {
                $kitchen = 'served';
            } elseif (in_array('preparing', $liveStates, true)) {
                $kitchen = 'in_progress';
            } elseif (in_array('ready', $liveStates, true) || in_array('collection', $liveStates, true)) {
                $kitchen = 'ready';
            }
        }
        $pdo->prepare("UPDATE stock_orders SET status = 'completed', subtotal = ?, total_amount = ?, tax_amount = ?, discount_amount = ?, service_charge = ?, total_cost = ?,
                              kitchen_status = ?, served_at = CASE WHEN ? = 'served' THEN COALESCE(served_at, NOW()) ELSE served_at END,
                              folio_posted_at = COALESCE(folio_posted_at, NOW()), updated_at = NOW() WHERE id = ?")
            ->execute([
                $newSub, $newTot,
                $cancelGross > 0 ? round((float)$order['tax_amount'] * $scale, 2) : (float)$order['tax_amount'],
                $cancelGross > 0 ? round((float)$order['discount_amount'] * $scale, 2) : (float)$order['discount_amount'],
                $cancelGross > 0 ? round((float)$order['service_charge'] * $scale, 2) : (float)$order['service_charge'],
                max(0.0, round((float)$order['total_cost'] - $costDrop, 4)),
                $kitchen, $kitchen, $orderId,
            ]);

        // Stations already cooking something on this order are told which lines NOT to make.
        $stopStations = array_keys(array_intersect_key($cancelStations, $startedStations));
        $stopStations = array_values(array_intersect($stopStations, ['kitchen', 'bar', 'coffee_bar']));
        if ($stopStations && $cancelNames) {
            $stopMsg = mb_substr('STOP - order ' . $ref . ': do NOT make ' . implode(', ', array_slice($cancelNames, 0, 6)) . '. Booking closed; items already started still go out.', 0, 255);
            foreach ($stopStations as $stn) {
                try {
                    $pdo->prepare("INSERT INTO station_messages (station, message, sent_by, sent_by_name, source, priority, order_id, order_ref, is_acknowledged, acknowledged_at) VALUES (?, ?, ?, ?, 'pos', 'urgent', ?, ?, 0, NULL)")
                        ->execute([$stn, $stopMsg, $actorId, mb_substr($actorName, 0, 120), $orderId, $ref]);
                } catch (Throwable $e) {
                    error_log('rh_close_order_keep_started STOP note: ' . $e->getMessage());
                }
            }
        }

        $keptTotal = round($newSub, 2);
        $pdo->prepare("INSERT INTO stock_order_audit (order_id, actor_id, actor_name, event, details, ip_address) VALUES (?, ?, ?, 'closed_kept_started', ?, ?)")
            ->execute([$orderId, $actorId, $actorName,
                mb_substr($details . ' | kept on the booking folio: ' . implode(', ', $keptNames) . ($posted > 0 ? ' (' . $posted . ' charge(s) posted now)' : '')
                    . ($cancelNames ? ' | cancelled before prep: ' . implode(', ', $cancelNames) : ''), 0, 2000), $ip]);

        $notices = [];
        $creator = (int)($order['created_by'] ?? 0);
        if ($creator > 0 && $creator !== $userId) {
            $msg = 'Order ' . $ref . ' was CLOSED by ' . rh_pos_notice_actor_label((string)$actor['role']) . ' (' . $actorName . '): the booking was cancelled. '
                . 'Items already started stay charged to the room bill' . ($cancelNames ? '; ' . count($cancelNames) . ' not started were cancelled' : '') . '.';
            $notices[] = [
                'station'      => 'kitchen',
                'message'      => mb_substr($msg, 0, 255),
                'sent_by'      => $actorId,
                'sent_by_name' => $actorName,
                'order_id'     => $orderId,
                'order_ref'    => $ref,
                'to_user_id'   => $creator,
                'priority'     => 'urgent',
                'notice_type'  => 'order_cancelled',
            ];
        }

        return [
            'mode'             => 'keep',
            'order_id'         => $orderId,
            'reference'        => $ref,
            'kept_lines'       => $keptLines,
            'kept_total'       => $keptTotal,
            'cancelled_lines'  => count($cancelNames),
            'charges_posted'   => $posted,
            'stock_restored'   => $restored,
            'folio_voided'     => $folioVoided,
            'booking_ids'      => array_keys($bookingIds),
            'stopped_stations' => $stopStations,
            'notices'          => $notices,
        ];
    }
}

if (!function_exists('rh_close_orders_for_booking')) {
    /**
     * Close every live order of a booking that is being cancelled / deleted / no-showed, so no tab,
     * kitchen ticket or folio charge keeps running. Runs inside the caller's transaction and BEFORE
     * its recalculateBookingFinancials() / refund allocation (this does not recalculate).
     *
     * Picks this booking's orders (stock_orders.booking_id, or any live folio charge pointing at
     * the order):
     *   - status 'placed'  : unpaid and nothing started -> cancelled. PART-PAID -> RuntimeException
     *                        (staff settle/void it in Orders & Tabs first; no automatic refunds): the caller
     *                        rolls back, so nothing is left half-closed.
     *                        Some lines started -> rh_close_order_keep_started(): started / served lines stay
     *                        charged to the booking folio (owner decision 2026-10-20), the rest is cancelled
     *                        with stock restored, the order is closed ('completed').
     *   - status 'completed' room service whose folio charge is still live: never started -> cancelled;
     *                        started -> consumed lines stay charged, any never-started line is cancelled.
     *   - a fully PAID order is left untouched as a settled sale.
     *
     * @return array{cancelled:int,voided:int,folio_voided:int,stock_restored:int,stock_wasted:int,
     *               refs:string[],left_paid_refs:string[],booking_ids:int[],notices:array,kept:array,kept_total:float}
     */
    function rh_close_orders_for_booking(PDO $pdo, int $bookingId, int $userId, string $reason): array
    {
        if (!$pdo->inTransaction()) {
            throw new RuntimeException('rh_close_orders_for_booking must run inside the caller\'s transaction.');
        }
        $out = ['cancelled' => 0, 'voided' => 0, 'folio_voided' => 0, 'stock_restored' => 0, 'stock_wasted' => 0,
                'refs' => [], 'left_paid_refs' => [], 'booking_ids' => [], 'notices' => [], 'kept' => [], 'kept_total' => 0.0];
        if ($bookingId <= 0) {
            return $out;
        }

        $link = "so.booking_id = ?";
        $params = [$bookingId];
        if (rh_pos_close_has_charge_order_col($pdo)) {
            $link = "(so.booking_id = ? OR so.id IN (SELECT bc.stock_order_id FROM booking_charges bc WHERE bc.booking_id = ? AND bc.stock_order_id IS NOT NULL AND bc.voided = 0))";
            $params = [$bookingId, $bookingId];
        }
        $sel = $pdo->prepare("SELECT so.id, so.reference, so.status, so.order_type, so.kitchen_status, COALESCE(so.split_paid_count, 0) AS split_paid_count
                                FROM stock_orders so
                               WHERE $link AND so.status IN ('placed', 'completed', 'paid')
                            ORDER BY so.id ASC FOR UPDATE");
        $sel->execute($params);
        $orders = $sel->fetchAll(PDO::FETCH_ASSOC);

        $paidChk = $pdo->prepare("SELECT COUNT(*) FROM payments WHERE booking_type = 'restaurant' AND booking_id = ? AND COALESCE(payment_type, '') != 'refund' AND deleted_at IS NULL AND COALESCE(payment_status, 'completed') = 'completed'");
        $startedChk = $pdo->prepare("SELECT COUNT(*) FROM stock_order_items WHERE order_id = ? AND kds_status IN ('preparing', 'in_progress', 'ready', 'collection', 'served')");
        $notStartedChk = $pdo->prepare("SELECT COUNT(*) FROM stock_order_items WHERE order_id = ? AND kds_status NOT IN ('preparing', 'in_progress', 'ready', 'collection', 'served', 'void')");
        $liveCharge = rh_pos_close_has_charge_order_col($pdo)
            ? $pdo->prepare("SELECT COUNT(*) FROM booking_charges WHERE stock_order_id = ? AND voided = 0")
            : null;

        // Decide first, act second: a part-paid order must stop the whole action before anything changes.
        $plan = [];
        foreach ($orders as $o) {
            $oid = (int)$o['id'];
            $status = (string)$o['status'];
            if ($status === 'paid') {
                $out['left_paid_refs'][] = (string)$o['reference'];
                continue;
            }
            // Decided on the LINES: food the kitchen started / served stays charged, the rest is cancelled.
            $startedChk->execute([$oid]);
            $started = ((int)$startedChk->fetchColumn() > 0);
            $notStartedChk->execute([$oid]);
            $hasUnstarted = ((int)$notStartedChk->fetchColumn() > 0);
            if ($status === 'completed') {
                if ((string)$o['order_type'] !== 'room_service' || !$liveCharge) {
                    continue; // delivered / consumed food stays a charge
                }
                $liveCharge->execute([$oid]);
                if ((int)$liveCharge->fetchColumn() === 0) {
                    continue;
                }
                if ($started) {
                    // Consumed food stays a charge; only lines that were never started are cancelled.
                    if ($hasUnstarted) {
                        $plan[] = [$oid, 'keep', (string)$o['reference']];
                    }
                    continue;
                }
                $paidChk->execute([$oid]);
                if ((int)$paidChk->fetchColumn() > 0) {
                    throw new RuntimeException('Order ' . (string)$o['reference'] . ' has a payment on it. Settle or refund it in Orders & Tabs first, then try again.');
                }
                $plan[] = [$oid, 'cancel', (string)$o['reference']];
                continue;
            }
            // placed
            $paidChk->execute([$oid]);
            if ((int)$o['split_paid_count'] > 0 || (int)$paidChk->fetchColumn() > 0) {
                throw new RuntimeException('Order ' . (string)$o['reference'] . ' is part-paid. Settle or refund it in Orders & Tabs first, then try again.');
            }
            // Owner decision 2026-10-20: food the kitchen already started is consumed and stays billable
            // on the booking folio; only the lines never started are cancelled. (Part-paid orders keep the
            // refusal above: no automatic refunds.)
            $plan[] = [$oid, $started ? 'keep' : 'cancel', (string)$o['reference']];
        }

        foreach ($plan as [$oid, $mode, $ref]) {
            if ($mode === 'keep') {
                $r = rh_close_order_keep_started($pdo, $oid, $userId, $reason);
                $out['kept'][] = ['reference' => $ref, 'kept_lines' => (int)$r['kept_lines'], 'kept_total' => (float)$r['kept_total'],
                                  'cancelled_lines' => (int)$r['cancelled_lines'], 'charges_posted' => (int)$r['charges_posted']];
                $out['kept_total'] = round($out['kept_total'] + (float)$r['kept_total'], 2);
                $out['folio_voided'] += (int)$r['folio_voided'];
                $out['stock_restored'] += (int)$r['stock_restored'];
                $out['refs'][] = $ref;
                foreach ($r['booking_ids'] as $bid) {
                    $out['booking_ids'][(int)$bid] = (int)$bid;
                }
                foreach ($r['notices'] as $n) {
                    $out['notices'][] = $n;
                }
                continue;
            }
            $r = rh_close_pos_order($pdo, $oid, $userId, $reason, $mode);
            // A payment that never cleared must not land later on a cancelled order.
            $pdo->prepare("UPDATE payments SET payment_status = 'cancelled', status = 'failed', updated_at = NOW()
                            WHERE booking_type = 'restaurant' AND booking_id = ? AND COALESCE(payment_type, '') != 'refund'
                              AND deleted_at IS NULL AND payment_status = 'pending'")->execute([$oid]);
            if ($mode === 'void') {
                $out['voided']++;
            } else {
                $out['cancelled']++;
            }
            $out['folio_voided'] += (int)$r['folio_voided'];
            $out['stock_restored'] += (int)$r['stock_restored'];
            $out['stock_wasted'] += (int)$r['stock_wasted'];
            $out['refs'][] = $ref;
            foreach ($r['booking_ids'] as $bid) {
                $out['booking_ids'][(int)$bid] = (int)$bid;
            }
            foreach ($r['notices'] as $n) {
                $out['notices'][] = $n;
            }
        }
        $out['booking_ids'] = array_values($out['booking_ids']);
        return $out;
    }
}
