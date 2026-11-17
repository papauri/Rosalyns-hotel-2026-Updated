<?php
/**
 * Payments / Accounting > Add charge (group bookings Ship B2, owner D-R4b 2026-11-17): add an extra CHARGE to a booking
 * (late checkout, damages, extras) with a reason, as preview -> confirm. Used by admin/charge-add.php and
 * scripts/tests/group_charge_add_test.php.
 *
 * Money rules (accounting lead):
 *  - The price is entered VAT-inclusive and posted through addBookingCharge exactly as Booking Details > Add Charge does
 *    (VAT extracted at the hotel's rate, no tourism levy: a fee or an extra is not a room night). An extra NIGHT is not a
 *    charge: it goes through Correct stay after check-out / Extend stay at the booked rate, with levy.
 *  - Who may charge which room (server side, on the preview AND again on the locked row at confirm):
 *      pending / tentative / confirmed / checked-in  -> edit_booking (the page permission);
 *      checked-out                                    -> also correct_stay_after_checkout;
 *      no-show / cancelled / expired / deleted        -> refused (no-show and cancellation fees have their own step).
 *    bookingAllowsAction('add_charge') itself is NOT widened: Booking Details still refuses checked-out rooms.
 *  - A reason is required on every charge; it goes into the booking History with what the room owed before and after.
 *  - The charge counts as folio revenue once, on the day it is posted (End of Day reads booking_charges.posted_at).
 *  - Issued invoices keep their numbers and are rebuilt after the save (rh_stay_correction_refresh_invoices).
 *  - One-time confirm token per preview, held in its own session slot ($_SESSION['rh_gca_nonce']) and bound to every
 *    input, so a Booking Details preview can never be confirmed here (or the other way round) and a resend posts nothing.
 */

require_once __DIR__ . '/booking-lifecycle.php';
require_once __DIR__ . '/group-charge.php';
require_once __DIR__ . '/finance-account-sync.php';
require_once __DIR__ . '/../../includes/booking-timeline.php';

const RH_CHARGE_ADD_PATH = 'charge_add';

if (!function_exists('rh_charge_add_types')) {
    /** The charge types Booking Details > Add Charge offers (value => label). */
    function rh_charge_add_types(): array
    {
        return ['custom' => 'Custom charge', 'service' => 'Service', 'minibar' => 'Minibar', 'laundry' => 'Laundry',
            'room_service' => 'Room service', 'breakfast' => 'Breakfast', 'other' => 'Other'];
    }
}

if (!function_exists('rh_charge_add_input')) {
    /**
     * Read and check the form. Returns ['error' => '' | message, 'in' => clean values].
     */
    function rh_charge_add_input(array $post): array
    {
        $in = [
            'booking_id'  => (int)($post['booking_id'] ?? 0),
            'scope'       => (string)($post['scope'] ?? 'room'),
            'charge_type' => (string)($post['charge_type'] ?? 'custom'),
            'description' => trim((string)($post['description'] ?? '')),
            'quantity'    => round((float)($post['quantity'] ?? 1), 2),
            'unit_price'  => round((float)($post['unit_price'] ?? 0), 2),
            'reason'      => trim((string)($post['reason'] ?? '')),
        ];
        $err = '';
        if ($in['booking_id'] <= 0) {
            $err = 'Choose the booking first.';
        } elseif (!in_array($in['scope'], ['room', 'group_line', 'group_split'], true)) {
            $err = 'Choose who the charge is for.';
        } elseif (!array_key_exists($in['charge_type'], rh_charge_add_types())) {
            $err = 'Choose a charge type.';
        } elseif ($in['description'] === '') {
            $err = 'Write what the charge is for (it prints on the invoice).';
        } elseif (mb_strlen($in['description']) > 255) {
            $err = 'The description is too long (255 characters maximum).';
        } elseif ($in['quantity'] <= 0 || $in['quantity'] > 100000) {
            $err = 'Quantity must be more than 0.';
        } elseif ($in['unit_price'] < 0) {
            $err = 'The price cannot be negative.';
        } elseif (round($in['quantity'] * $in['unit_price'], 2) < 0.01) {
            $err = 'The charge must be at least 0.01.';
        } elseif ($in['reason'] === '') {
            $err = 'A reason is required. It is kept in the booking history.';
        } elseif (mb_strlen($in['reason']) > 500) {
            $err = 'The reason is too long (500 characters maximum).';
        }
        return ['error' => $err, 'in' => $in];
    }
}

if (!function_exists('rh_charge_add_room_gate')) {
    /** May this room take a charge from this screen? $row: bookings row (status, deleted_at). */
    function rh_charge_add_room_gate(array $row, bool $canCorrect): array
    {
        $status = (string)($row['status'] ?? '');
        if (!empty($row['deleted_at'])) {
            return ['ok' => false, 'message' => 'This booking has been deleted. Nothing can be charged to it.'];
        }
        if (in_array($status, BOOKING_TERMINAL_STATUSES, true)) {
            return ['ok' => false, 'message' => 'A charge cannot be added to ' . ($status === 'expired' ? 'an' : 'a') . ' ' . $status . ' booking here. No-show and cancellation fees have their own step on the booking.'];
        }
        if ($status === 'checked-out') {
            return $canCorrect ? ['ok' => true, 'message' => '']
                : ['ok' => false, 'message' => 'This room has checked out. Adding a charge after check-out needs the Correct Stay After Check-out permission. Ask a manager.'];
        }
        $a = bookingAllowsAction($row, 'add_charge');
        return ['ok' => !empty($a['allowed']), 'message' => (string)($a['reason'] ?? '')];
    }
}

if (!function_exists('rh_charge_add_owed_after')) {
    /** What a room owes after a charge of $amount: credit on the room is used up first. */
    function rh_charge_add_owed_after(float $due, float $credit, float $amount): float
    {
        return round(max(0.0, $due - $credit + $amount), 2);
    }
}

if (!function_exists('rh_charge_add_single_sig')) {
    function rh_charge_add_single_sig(int $bookingId, string $status, bool $canCorrect, float $amount): string
    {
        return hash('sha256', RH_CHARGE_ADD_PATH . '|single|' . $bookingId . '|' . $status . '|' . ($canCorrect ? 'co' : 'open') . '|' . number_format($amount, 2, '.', ''));
    }
}

if (!function_exists('rh_charge_add_bind')) {
    /** The confirm token is bound to every input + the preview's sig: change anything and the confirm is refused. */
    function rh_charge_add_bind(array $in, string $sig): string
    {
        return hash('sha256', json_encode([RH_CHARGE_ADD_PATH, $in['booking_id'], $in['scope'], $in['charge_type'], $in['description'],
            number_format((float)$in['quantity'], 2, '.', ''), number_format((float)$in['unit_price'], 2, '.', ''), $in['reason'], $sig]));
    }
}

if (!function_exists('rh_charge_add_nonce_issue')) {
    function rh_charge_add_nonce_issue(array &$store, int $bookingId, string $bind): string
    {
        $nonce = bin2hex(random_bytes(16));
        $store[(string)$bookingId] = ['n' => $nonce, 'b' => $bind, 't' => time()];
        return $nonce;
    }
}

if (!function_exists('rh_charge_add_nonce_take')) {
    /** Used up BEFORE anything is posted, whatever happens next. '' = good, else the refusal. */
    function rh_charge_add_nonce_take(array &$store, int $bookingId, string $nonce, string $bind): string
    {
        $key = (string)$bookingId;
        $held = $store[$key] ?? null;
        unset($store[$key]);
        if (!is_array($held) || $nonce === '' || !hash_equals((string)$held['n'], $nonce)) {
            return 'This charge was already added, or its preview is no longer current. Nothing more was added: check the booking, and preview again if a charge is still needed.';
        }
        if (!hash_equals((string)$held['b'], $bind) || time() - (int)$held['t'] > 1800) {
            return 'The details changed since the preview. Nothing was added: preview the charge again.';
        }
        return '';
    }
}

if (!function_exists('rh_charge_add_booking_row')) {
    function rh_charge_add_booking_row(PDO $pdo, int $bookingId, bool $lock = false): ?array
    {
        $st = $pdo->prepare("SELECT b.id, b.booking_reference, b.status, b.deleted_at, b.amount_due, b.credit_balance, b.payment_status,
                COALESCE(NULLIF(TRIM(b.room_guest_name), ''), b.guest_name) AS guest, COALESCE(r.name, '') AS room_type
            FROM bookings b LEFT JOIN rooms r ON r.id = b.room_id WHERE b.id = ?" . ($lock ? ' FOR UPDATE' : ''));
        $st->execute([$bookingId]);
        $row = $st->fetch(PDO::FETCH_ASSOC);
        return $row ?: null;
    }
}

if (!function_exists('rh_charge_add_preview')) {
    /**
     * The preview (read-only). Returns ['success', 'message', 'scope', 'sig', 'total', 'rows' => [...], 'skipped' => [...]].
     * Each row: booking_id, ref, room, guest, status, checked_out, description, amount, owed_now, owed_after.
     */
    function rh_charge_add_preview(PDO $pdo, array $in, bool $canCorrect): array
    {
        $out = ['success' => false, 'message' => '', 'scope' => $in['scope'], 'sig' => '', 'total' => 0.0, 'rows' => [], 'skipped' => []];
        $row = rh_charge_add_booking_row($pdo, (int)$in['booking_id']);
        if (!$row) {
            $out['message'] = 'Booking not found.';
            return $out;
        }
        if (!empty($row['deleted_at'])) {
            $out['message'] = 'This booking has been deleted. Nothing can be charged to it.';
            return $out;
        }
        if ($in['scope'] === 'room') {
            $gate = rh_charge_add_room_gate($row, $canCorrect);
            if (!$gate['ok']) {
                $out['message'] = $gate['message'];
                return $out;
            }
            $amount = round($in['quantity'] * $in['unit_price'], 2);
            $due = round(max(0.0, (float)$row['amount_due']), 2);
            $id = (int)$row['id'];
            $out['rows'][] = ['booking_id' => $id, 'ref' => (string)$row['booking_reference'],
                'room' => function_exists('getBookingRoomLabel') ? getBookingRoomLabel($id, (string)$row['room_type']) : (string)$row['room_type'],
                'guest' => (string)$row['guest'], 'status' => (string)$row['status'], 'checked_out' => $row['status'] === 'checked-out',
                'description' => $in['description'], 'amount' => $amount, 'owed_now' => $due,
                'owed_after' => rh_charge_add_owed_after($due, (float)$row['credit_balance'], $amount)];
            $out['total'] = $amount;
            $out['sig'] = rh_charge_add_single_sig($id, (string)$row['status'], $canCorrect, $amount);
            $out['success'] = true;
            return $out;
        }
        $group = rh_group_charge_rooms($pdo, (int)$in['booking_id'], false, ['path' => RH_CHARGE_ADD_PATH, 'allow_checked_out' => $canCorrect]);
        $plan = rh_group_charge_plan($group, $in['scope'] === 'group_line' ? 'line' : 'split', $in['description'], (float)$in['quantity'], (float)$in['unit_price']);
        if (empty($plan['ok'])) {
            $out['message'] = (string)$plan['message'];
            return $out;
        }
        $byId = [];
        foreach ($group['rooms'] as $r) {
            $byId[(int)$r['id']] = $r;
        }
        foreach ($plan['lines'] as $l) {
            $r = $byId[(int)$l['booking_id']] ?? [];
            $due = (float)($r['amount_due'] ?? 0);
            $out['rows'][] = ['booking_id' => (int)$l['booking_id'], 'ref' => (string)$l['booking_reference'], 'room' => (string)$l['room'],
                'guest' => (string)$l['guest'], 'status' => (string)($r['status'] ?? ''), 'checked_out' => !empty($r['checked_out']),
                'description' => (string)$l['description'], 'amount' => (float)$l['amount'], 'owed_now' => $due,
                'owed_after' => rh_charge_add_owed_after($due, (float)($r['credit_balance'] ?? 0), (float)$l['amount'])];
        }
        foreach ($plan['skipped'] as $s) {
            $r = $byId[(int)$s['id']] ?? [];
            $out['skipped'][] = ['ref' => (string)$s['booking_reference'], 'room' => (string)$s['room'], 'guest' => (string)$s['guest'],
                'status' => (string)$s['status'], 'reason' => (string)($r['reason'] ?? '')];
        }
        $out['total'] = (float)$plan['total'];
        $out['sig'] = (string)$plan['sig'];
        $out['success'] = true;
        return $out;
    }
}

if (!function_exists('rh_charge_add_post')) {
    /**
     * Post the charge (call only after the confirm token was taken). A caller transaction is joined (savepoint).
     * Issued invoices are rebuilt after the save (outside the charge's own transaction).
     * Returns ['success', 'message', 'charges' => [[booking_id, charge_id, amount]], 'invoice_notice'].
     */
    function rh_charge_add_post(PDO $pdo, array $in, array $user, bool $canCorrect, string $sig): array
    {
        $res = ['success' => false, 'message' => '', 'charges' => [], 'invoice_notice' => ''];
        $cur = (string)getSetting('currency_symbol', 'MWK');
        if ($in['scope'] !== 'room') {
            $g = rh_group_charge_post($pdo, (int)$in['booking_id'], $in['scope'] === 'group_line' ? 'line' : 'split', $in['charge_type'],
                $in['description'], (float)$in['quantity'], (float)$in['unit_price'], $user, $sig,
                ['path' => RH_CHARGE_ADD_PATH, 'allow_checked_out' => $canCorrect, 'reason' => $in['reason']]);
            $res['success'] = !empty($g['success']);
            $res['message'] = (string)$g['message'];
            $res['charges'] = (array)($g['charges'] ?? []);
        } else {
            $ownTx = !$pdo->inTransaction();
            try {
                if ($ownTx) {
                    $pdo->beginTransaction();
                } else {
                    $pdo->exec('SAVEPOINT rh_charge_add');
                }
                $row = rh_charge_add_booking_row($pdo, (int)$in['booking_id'], true);
                if (!$row) {
                    throw new RuntimeException('Booking not found. Nothing was added.');
                }
                $gate = rh_charge_add_room_gate($row, $canCorrect);
                if (!$gate['ok']) {
                    throw new RuntimeException($gate['message'] . ' Nothing was added.');
                }
                $amount = round($in['quantity'] * $in['unit_price'], 2);
                if (!hash_equals(rh_charge_add_single_sig((int)$row['id'], (string)$row['status'], $canCorrect, $amount), $sig)) {
                    throw new RuntimeException('The booking changed since the preview (for example it checked out). Nothing was added: preview the charge again.');
                }
                $id = (int)$row['id'];
                $ref = (string)$row['booking_reference'];
                $owedBefore = rh_account_balance_due($pdo, 'room', $id);
                $userId = (int)($user['id'] ?? 0);
                $r = addBookingCharge($id, $in['charge_type'], $in['description'], (float)$in['quantity'], (float)$in['unit_price'], null, $userId ?: null);
                if (empty($r['success'])) {
                    throw new RuntimeException(($r['message'] ?? 'The charge could not be added.') . ' Nothing was added.');
                }
                $line = (float)$r['line_total'];
                $owedSt = $pdo->prepare('SELECT amount_due FROM bookings WHERE id = ?');
                $owedSt->execute([$id]);
                $owedAfter = (float)$owedSt->fetchColumn();
                $after = $row['status'] === 'checked-out';
                $note = 'Charge "' . $in['description'] . '" ' . $cur . ' ' . number_format($line, 2) . ' added from Payments (Add charge)'
                    . ($after ? ', after check-out' : '') . '. Reason: ' . $in['reason'] . '. Charge #' . (int)$r['charge_id'] . '.';
                $logged = logBookingEvent($id, $ref, 'Charge added', 'update', $note,
                    'Owed ' . $cur . ' ' . number_format($owedBefore, 2), 'Owed ' . $cur . ' ' . number_format($owedAfter, 2),
                    'admin', $userId ?: null, (string)($user['full_name'] ?? ($user['username'] ?? '')),
                    ['charge_id' => (int)$r['charge_id'], 'amount' => $line, 'charge_type' => $in['charge_type'], 'reason' => $in['reason'],
                        'source' => RH_CHARGE_ADD_PATH, 'after_checkout' => $after, 'owed_before' => round($owedBefore, 2), 'owed_after' => round($owedAfter, 2)]);
                if (!$logged) {
                    throw new RuntimeException('The booking history could not be written. Nothing was added.');
                }
                if ($ownTx) {
                    $pdo->commit();
                } else {
                    $pdo->exec('RELEASE SAVEPOINT rh_charge_add');
                }
                $res['success'] = true;
                $res['charges'][] = ['booking_id' => $id, 'charge_id' => (int)$r['charge_id'], 'amount' => $line];
                $res['message'] = 'Charge of ' . $cur . ' ' . number_format($line, 2) . ' added to ' . $ref . '. It now owes ' . $cur . ' ' . number_format($owedAfter, 2) . '.';
            } catch (Throwable $e) {
                if ($ownTx && $pdo->inTransaction()) {
                    $pdo->rollBack();
                } elseif (!$ownTx && $pdo->inTransaction()) {
                    try {
                        $pdo->exec('ROLLBACK TO SAVEPOINT rh_charge_add');
                    } catch (Throwable $e2) {
                        error_log('charge add savepoint rollback: ' . $e2->getMessage());
                    }
                }
                error_log('rh_charge_add_post booking ' . (int)$in['booking_id'] . ': ' . $e->getMessage());
                $res['message'] = $e->getMessage();
                return $res;
            }
        }
        if ($res['success']) { // after the commit: issued invoices of the whole group keep their numbers, new figures
            require_once __DIR__ . '/../../includes/stay-correction.php';
            if (function_exists('rh_stay_correction_refresh_invoices')) {
                $res['invoice_notice'] = trim(rh_stay_correction_refresh_invoices($pdo, (int)$in['booking_id']));
            }
        }
        return $res;
    }
}
