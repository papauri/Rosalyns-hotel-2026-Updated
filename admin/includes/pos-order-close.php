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
     * @return array{mode:string,order_id:int,reference:string,order_type:string,created_by:int,
     *               status:string,kitchen_status_before:string,lines:int,stock_restored:int,stock_wasted:int,
     *               folio_voided:int,booking_ids:int[],reversal:array,stopped_stations:string[],notices:array}
     */
    function rh_close_pos_order(PDO $pdo, int $orderId, int $userId, string $reason, string $mode): array
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
        rh_restore_pos_order_stock($pdo, $orderId, $userId, $isVoid ? 'POS order voided (admin)' : 'POS order cancelled before prep');

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
                    $cu->execute([mb_substr($chargeReason, 0, 255), $userId, (int)$charge['id']]);
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
            ->execute([$newStatus, $userId, mb_substr($details, 0, 500), $orderId]);
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
                        ->execute([$stn, $stopMsg, $userId, mb_substr($actorName, 0, 120), $orderId, $ref]);
                } catch (Throwable $e) {
                    error_log('rh_close_pos_order STOP note: ' . $e->getMessage());
                }
            }
        }

        $pdo->prepare("INSERT INTO stock_order_audit (order_id, actor_id, actor_name, event, details, ip_address) VALUES (?, ?, ?, ?, ?, ?)")
            ->execute([$orderId, $userId, $actorName, $isVoid ? 'voided' : 'cancelled_before_prep', $details, $ip]);
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
                'sent_by'      => $userId,
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
            'folio_voided'          => $folioVoided,
            'booking_ids'           => array_keys($bookingIds),
            'reversal'              => $reversal,
            'stopped_stations'      => $stopStations,
            'notices'               => $notices,
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
     *   - status 'placed'  : unpaid and nothing started -> cancelled. Kitchen already started, or
     *                        PART-PAID -> RuntimeException (staff settle/void it in Orders & Tabs first):
     *                        the caller rolls back, so nothing is left half-closed.
     *   - status 'completed' room service whose folio charge is still live and whose kitchen never
     *                        started -> cancelled. Consumed / served food stays a charge (owner decision pending).
     *   - a fully PAID order is left untouched as a settled sale.
     *
     * @return array{cancelled:int,voided:int,folio_voided:int,stock_restored:int,stock_wasted:int,
     *               refs:string[],left_paid_refs:string[],booking_ids:int[],notices:array}
     */
    function rh_close_orders_for_booking(PDO $pdo, int $bookingId, int $userId, string $reason): array
    {
        if (!$pdo->inTransaction()) {
            throw new RuntimeException('rh_close_orders_for_booking must run inside the caller\'s transaction.');
        }
        $out = ['cancelled' => 0, 'voided' => 0, 'folio_voided' => 0, 'stock_restored' => 0, 'stock_wasted' => 0,
                'refs' => [], 'left_paid_refs' => [], 'booking_ids' => [], 'notices' => []];
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
            $started = false;
            $startedChk->execute([$oid]);
            if ((int)$startedChk->fetchColumn() > 0 || in_array((string)$o['kitchen_status'], ['in_progress', 'ready', 'served'], true)) {
                $started = true;
            }
            if ($status === 'completed') {
                if ((string)$o['order_type'] !== 'room_service' || !$liveCharge || $started) {
                    continue; // delivered / consumed food stays a charge
                }
                $liveCharge->execute([$oid]);
                if ((int)$liveCharge->fetchColumn() === 0) {
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
            if ($started) {
                // Food the kitchen already started is money the guest may owe: staff decide (charge or
                // void it in Orders & Tabs) rather than the booking action writing it off silently.
                throw new RuntimeException('Order ' . (string)$o['reference'] . ' has food the kitchen already started. Charge it or void it in Orders & Tabs first, then try again.');
            }
            $plan[] = [$oid, 'cancel', (string)$o['reference']];
        }

        foreach ($plan as [$oid, $mode, $ref]) {
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
