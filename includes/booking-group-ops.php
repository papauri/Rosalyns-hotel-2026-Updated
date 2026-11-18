<?php
/**
 * Group booking operations (group bookings plan R3 / R5 / R6, rule A6; owner answer 4, 2026-11-17).
 *
 * A group = N bookings rows: the root has primary_booking_id NULL, every other room points at the root.
 * These helpers change ONLY which row is the root and who belongs to the group. No money moves: no payment, charge,
 * credit note or amount on any row is touched. Every room keeps its own cancellation snapshot.
 *
 *   rh_group_repoint()  R3 "Make primary room" (and the first step of removing the root room).
 *   rh_group_make()     R5 "Make a group" from bookings ticked in the list.
 *   rh_group_remove()   R6 "Remove from group": the room becomes an individual booking.
 *
 * Each takes $opts['dry_run'] (preview: validate + figures, nothing written) and $opts['expect_state'] (the 'state'
 * token the preview returned: the confirm refuses if the group changed in between). A caller transaction is joined
 * (the caller then commits / rolls back); otherwise each opens and commits its own.
 *
 * What moves with the root (A6): members (UPDATE ... WHERE primary_booking_id = old, every status incl. deleted),
 * the billing account (4 columns copied to the new root when it has none; a different payer refuses), the overdue
 * chase stages (automated_email_log rows copied to the new key with INSERT IGNORE, old rows kept), and the group
 * invoice number (it never flips: the old number is written onto the new root's final_invoice_number when needed;
 * the number a room holds on its own payment rows is never touched).
 */

if (!function_exists('rh_group_repoint')) {

    /** Root of a booking's group (COALESCE(NULLIF(primary_booking_id, 0), id)); 0 = no such booking. */
    function rh_group_ops_root(PDO $pdo, int $bookingId): int
    {
        $st = $pdo->prepare('SELECT COALESCE(NULLIF(primary_booking_id, 0), id) FROM bookings WHERE id = ?');
        $st->execute([$bookingId]);
        return (int)$st->fetchColumn();
    }

    /** Every row of a group (root first), ALL statuses incl. deleted; FOR UPDATE when $lock. Keyed by id. */
    function rh_group_ops_rows(PDO $pdo, int $root, bool $lock = false): array
    {
        // Pay-later arrangement (migration 083) only named once its columns exist.
        $arr = function_exists('rh_checkin_arrangement_columns_present') && rh_checkin_arrangement_columns_present($pdo)
            ? ' b.checkin_without_payment, b.checkin_arrangement_note,' : '';
        $st = $pdo->prepare('SELECT b.id, b.booking_reference, b.guest_name, b.room_guest_name, b.status, b.deleted_at, b.primary_booking_id,' . $arr . '
                b.billing_account_id, b.billing_reference, b.billing_due_date, b.billing_covers, b.final_invoice_number,
                b.check_in_date, b.check_out_date, COALESCE((SELECT r.name FROM rooms r WHERE r.id = b.room_id), \'\') AS room_name
            FROM bookings b
            WHERE b.id = ? OR b.primary_booking_id = ? ORDER BY (b.id = ?) DESC, b.id ASC' . ($lock ? ' FOR UPDATE' : ''));
        $st->execute([$root, $root, $root]);
        $out = [];
        foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) {
            $out[(int)$r['id']] = $r;
        }
        return $out;
    }

    /** The 4 billing columns of a row when it holds its own company account, else null (guest pays / inherits). */
    function rh_group_ops_billing(array $row): ?array
    {
        if ((int)($row['billing_account_id'] ?? 0) <= 0) {
            return null;
        }
        return [
            'billing_account_id' => (int)$row['billing_account_id'], 'billing_reference' => $row['billing_reference'] ?? null,
            'billing_due_date' => $row['billing_due_date'] ?? null, 'billing_covers' => $row['billing_covers'] ?? null,
        ];
    }

    /** Company name for a billing account id ('' when unknown). */
    function rh_group_ops_company(PDO $pdo, int $accountId): string
    {
        if ($accountId <= 0) {
            return '';
        }
        try {
            $st = $pdo->prepare('SELECT name FROM billing_accounts WHERE id = ?');
            $st->execute([$accountId]);
            $n = trim((string)$st->fetchColumn());
            return $n !== '' ? $n : ('Account #' . $accountId);
        } catch (Throwable $e) {
            return 'Account #' . $accountId;
        }
    }

    /** Whether the billing columns exist (includes/billing-accounts.php); false = billing is not checked or copied. */
    function rh_group_ops_billing_ready(PDO $pdo): bool
    {
        if (!function_exists('rh_billing_ready')) {
            require_once __DIR__ . '/billing-accounts.php';
        }
        return function_exists('rh_billing_ready') && rh_billing_ready($pdo);
    }

    /** The group invoice number for the rooms in $ids under $root (config/invoice.php rh_invoice_group_number). */
    function rh_group_ops_invoice_number(PDO $pdo, array $ids, int $root): string
    {
        if (!function_exists('rh_invoice_group_number')) {
            require_once __DIR__ . '/../config/invoice.php';
        }
        return rh_invoice_group_number($pdo, $ids, $root);
    }

    /** Ids of the rows that are not deleted (the rooms a group document covers). */
    function rh_group_ops_live_ids(array $rows): array
    {
        $ids = [];
        foreach ($rows as $id => $r) {
            if (empty($r['deleted_at'])) {
                $ids[] = (int)$id;
            }
        }
        return $ids;
    }

    /**
     * Copy the overdue-chase stages already sent under key $fromId to key $toId (INSERT IGNORE: a stage the new key
     * already has is kept as it is; the old rows stay). Covers the guest key 'room' and the company-group guest key
     * 'room_g:<id>'. Returns the number of rows added.
     */
    function rh_group_copy_chase(PDO $pdo, int $fromId, int $toId): int
    {
        if ($fromId <= 0 || $toId <= 0 || $fromId === $toId) {
            return 0;
        }
        try {
            $st = $pdo->prepare("INSERT IGNORE INTO automated_email_log (job, account_type, account_id, stage, recipient, subject, status, attempts, error, sent_at)
                SELECT job, account_type, ?, stage, recipient, subject, status, attempts, error, sent_at FROM automated_email_log
                WHERE job = 'overdue_payment_reminders' AND account_id = ? AND status = 'sent'
                  AND (account_type = 'room' OR account_type LIKE 'room\\_g:%')");
            $st->execute([$toId, $fromId]);
            return $st->rowCount();
        } catch (PDOException $e) {
            if (stripos($e->getMessage(), 'automated_email_log') !== false && stripos($e->getMessage(), "doesn't exist") !== false) {
                return 0; // table not migrated yet: nothing was ever chased
            }
            throw $e;
        }
    }

    /** One History line (booking_timeline_logs; action_type 'update', the action name is in `action`). */
    function rh_group_ops_log(array $row, string $action, string $description, ?string $old, ?string $new, array $opts, array $meta = []): void
    {
        if (!function_exists('logBookingEvent')) {
            require_once __DIR__ . '/booking-timeline.php';
        }
        $uid = (int)($opts['user_id'] ?? 0);
        logBookingEvent((int)$row['id'], (string)($row['booking_reference'] ?? ''), $action, 'update', $description, $old, $new,
            $uid > 0 ? 'admin' : 'system', $uid > 0 ? $uid : null, ($opts['user_name'] ?? '') !== '' ? (string)$opts['user_name'] : null, $meta ?: null);
    }

    /** "LSH-123 (Deluxe, Jane Doe)" for messages and History. */
    function rh_group_ops_label(array $row): string
    {
        $bits = array_filter([trim((string)($row['room_name'] ?? '')), trim((string)($row['room_guest_name'] ?? '')) !== '' ? trim((string)$row['room_guest_name']) : trim((string)($row['guest_name'] ?? ''))]);
        return (string)($row['booking_reference'] ?? ('#' . (int)($row['id'] ?? 0))) . ($bits ? ' (' . implode(', ', $bits) . ')' : '');
    }

    /** Token of a group's shape (root + every row id): a preview and its confirm must see the same group. */
    function rh_group_ops_state(int $root, array $rows, array $extra = []): string
    {
        $ids = array_map('intval', array_keys($rows));
        sort($ids);
        return sha1($root . '|' . implode(',', $ids) . '|' . implode(',', array_map('strval', $extra)));
    }

    /** Each room's own figures (getBookingFolioSummary: the same Billed / Paid / Owed its own page shows). */
    function rh_group_ops_money(int $bookingId): array
    {
        $fs = function_exists('getBookingFolioSummary') ? getBookingFolioSummary($bookingId) : [];
        if (!is_array($fs) || isset($fs['error'])) {
            $fs = [];
        }
        return ['billed' => round((float)($fs['grand_total'] ?? 0), 2), 'paid' => round((float)($fs['amount_paid'] ?? 0), 2),
            'owed' => round((float)($fs['balance_due'] ?? 0), 2)];
    }

    /**
     * G4 (owner 2026-11-18): may this booking be put in a group? The ONE rule for the All Bookings ticks, the Make a group
     * review and its confirm. A live booking (any status but the ones below) and a no-show may always join. A cancelled
     * booking may join only while it still owes a kept fee (amount_due above BALANCE_TOLERANCE, the "Cancelled - fee owed"
     * rule). Expired and deleted bookings never join. A no-show or cancelled booking can never be the primary room.
     * It joins as it is: its own balance, payments and cancellation snapshot stay on it.
     *
     * $row: status, deleted_at, amount_due (+ booking_reference / room_name / guest names for the message).
     * $asLead: the row is the chosen primary room.
     * @return array{ok:bool,reason:string,can_lead:bool} reason: a plain sentence when ok is false, else ''.
     *   can_lead: whether it could be the primary room (false for no-show / cancelled, and whenever ok is false).
     */
    function rh_group_can_join(array $row, bool $asLead = false): array
    {
        $status = strtolower(trim((string)($row['status'] ?? '')));
        $label = rh_group_ops_label($row);
        $owes = function_exists('rh_cancelled_fee_owed_label')
            ? rh_cancelled_fee_owed_label($status, $row['amount_due'] ?? 0) !== ''
            : ($status === 'cancelled' && round((float)($row['amount_due'] ?? 0), 2) > (float)BALANCE_TOLERANCE);
        $no = static fn(string $why): array => ['ok' => false, 'reason' => $why, 'can_lead' => false];
        if (!empty($row['deleted_at'])) {
            return $no($label . ' is deleted. Restore it first or untick it.');
        }
        if ($status === 'expired') {
            return $no($label . ' is expired. An expired booking cannot be put in a group.');
        }
        if ($status === 'cancelled' && !$owes) {
            return $no($label . ' is cancelled and owes nothing. Only a cancelled booking that still owes its kept fee can be put in a group.');
        }
        $closed = in_array($status, ['cancelled', 'no-show'], true);
        if ($asLead && $closed) {
            return $no($label . ' is ' . ($status === 'no-show' ? 'a no-show' : 'cancelled') . ', so it cannot be the primary room. Choose a live booking as the primary room.');
        }
        return ['ok' => true, 'reason' => '', 'can_lead' => !$closed];
    }

    /** Run $fn inside the caller's transaction, or a new one that is committed / rolled back here. */
    function rh_group_ops_tx(PDO $pdo, callable $fn): array
    {
        $own = !$pdo->inTransaction();
        if ($own) {
            $pdo->beginTransaction();
        }
        try {
            $res = $fn();
            if (empty($res['success'])) { // a refusal returns before any write
                if ($own && $pdo->inTransaction()) {
                    $pdo->rollBack();
                }
                return $res;
            }
            if ($own) {
                $pdo->commit();
            }
            return $res;
        } catch (Throwable $e) {
            if ($own && $pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $e;
        }
    }

    /**
     * R3 / A6: make $newRoot the primary (root) room of the group rooted at $oldRoot. One transaction, every row of the
     * group locked (all statuses incl. cancelled / checked-out / no-show / deleted). No money moves.
     *
     * $opts: user_id (int), user_name (string), dry_run (bool: validate + preview only, nothing written or locked),
     *        expect_state (string: the preview's 'state'; a different group refuses), log (bool, default true),
     *        allow_closed_root (bool, rh_group_remove only: no live room is left, so a no-show / cancelled room may take over).
     *        G4: otherwise a no-show, cancelled or expired $newRoot refuses with a plain message.
     *
     * @return array{success:bool,error:string,state:string,old_root:int,new_root:int,ids:int[],invoice_number:string,
     *   invoice_copied:bool,billing:string,company:string,chase_copied:int,old_label:string,new_label:string}
     *   billing: 'none' (guest pays) | 'same' (both rooms on the same company) | 'copied' (the old root's company copied).
     *   ids: every row now pointing at $newRoot (the old root included). On a refusal success=false and error says why.
     *   arrangement_copied: the old root's "Check in before payment" (+ note) is / would be copied onto the new root.
     */
    function rh_group_repoint(PDO $pdo, int $oldRoot, int $newRoot, array $opts = []): array
    {
        $out = ['success' => false, 'error' => '', 'state' => '', 'old_root' => $oldRoot, 'new_root' => $newRoot, 'ids' => [],
            'invoice_number' => '', 'invoice_copied' => false, 'billing' => 'none', 'company' => '', 'chase_copied' => 0,
            'arrangement_copied' => false, 'old_label' => '', 'new_label' => ''];
        $dry = !empty($opts['dry_run']);
        $work = static function () use ($pdo, $oldRoot, $newRoot, $opts, $dry, $out): array {
            if ($oldRoot <= 0 || $newRoot <= 0 || $oldRoot === $newRoot) {
                $out['error'] = 'Pick another room of the group to make primary.';
                return $out;
            }
            $rows = rh_group_ops_rows($pdo, $oldRoot, !$dry);
            $old = $rows[$oldRoot] ?? null;
            if ($old === null || (int)($old['primary_booking_id'] ?? 0) > 0) {
                $out['error'] = 'That booking is not the primary room of a group.';
                return $out;
            }
            $new = $rows[$newRoot] ?? null;
            if ($new === null || (int)$new['primary_booking_id'] !== $oldRoot) {
                $out['error'] = 'That room is not in this group.';
                return $out;
            }
            if (!empty($new['deleted_at'])) {
                $out['error'] = 'A deleted room cannot be the primary room. Restore it first.';
                return $out;
            }
            // G4: a no-show / cancelled / expired room is never made the primary room (rh_group_can_join's lead rule).
            // allow_closed_root: only rh_group_remove when no live room is left to hand the group to.
            if (empty($opts['allow_closed_root']) && !rh_group_can_join(['deleted_at' => null] + $new, true)['ok']) {
                $nSt = strtolower(trim((string)$new['status']));
                $out['error'] = rh_group_ops_label($new) . ' is ' . ($nSt === 'no-show' ? 'a no-show' : str_replace('-', ' ', $nSt))
                    . ', so it cannot be the primary room. Choose a live room of the group.';
                return $out;
            }
            $out['state'] = rh_group_ops_state($oldRoot, $rows, [$newRoot]);
            if (($opts['expect_state'] ?? '') !== '' && !hash_equals((string)$opts['expect_state'], $out['state'])) {
                $out['error'] = 'The group changed since you reviewed it. Close this and review again.';
                return $out;
            }
            $out['old_label'] = rh_group_ops_label($old);
            $out['new_label'] = rh_group_ops_label($new);

            // Billing account (A6): members with no company of their own follow the root, so the payer must not change.
            if (rh_group_ops_billing_ready($pdo)) {
                $ob = rh_group_ops_billing($old);
                $nb = rh_group_ops_billing($new);
                if ($ob && $nb && $ob['billing_account_id'] !== $nb['billing_account_id']) {
                    $out['error'] = 'Not changed: ' . $out['new_label'] . ' is billed to ' . rh_group_ops_company($pdo, $nb['billing_account_id'])
                        . ' but the group is billed to ' . rh_group_ops_company($pdo, $ob['billing_account_id']) . '. Change who pays on one of them first.';
                    return $out;
                }
                if (!$ob && $nb) {
                    $out['error'] = 'Not changed: ' . $out['new_label'] . ' is billed to ' . rh_group_ops_company($pdo, $nb['billing_account_id'])
                        . ' but the rest of the group is paid by the guest. The other rooms would move to that company. Change who pays first.';
                    return $out;
                }
                if ($ob) {
                    $out['billing'] = $nb ? 'same' : 'copied';
                    $out['company'] = rh_group_ops_company($pdo, $ob['billing_account_id']);
                }
            }

            // Pay-later arrangement: set on the primary only and the members inherit it (rh_checkin_arrangement_inherited),
            // so the new primary takes the old root's switch + note. The old root keeps its own values; a new root that
            // already has the switch keeps its own note.
            $out['arrangement_copied'] = !empty($old['checkin_without_payment']) && empty($new['checkin_without_payment']);

            $live = rh_group_ops_live_ids($rows);
            $before = rh_group_ops_invoice_number($pdo, $live, $oldRoot);
            $out['invoice_number'] = $before;
            $out['ids'] = array_values(array_diff(array_map('intval', array_keys($rows)), [$newRoot]));
            if ($dry) {
                $out['success'] = true;
                return $out;
            }

            // A6: members old -> new, the new root NULL, the old root -> new.
            $pdo->prepare('UPDATE bookings SET primary_booking_id = ?, updated_at = NOW() WHERE primary_booking_id = ? AND id <> ?')
                ->execute([$newRoot, $oldRoot, $newRoot]);
            $pdo->prepare('UPDATE bookings SET primary_booking_id = NULL, updated_at = NOW() WHERE id = ?')->execute([$newRoot]);
            $pdo->prepare('UPDATE bookings SET primary_booking_id = ?, updated_at = NOW() WHERE id = ?')->execute([$newRoot, $oldRoot]);
            if ($out['billing'] === 'copied') {
                $ob = rh_group_ops_billing($old);
                $pdo->prepare('UPDATE bookings SET billing_account_id = ?, billing_reference = ?, billing_due_date = ?, billing_covers = ? WHERE id = ?')
                    ->execute([$ob['billing_account_id'], $ob['billing_reference'], $ob['billing_due_date'], $ob['billing_covers'], $newRoot]);
            }
            if ($out['arrangement_copied']) {
                $pdo->prepare('UPDATE bookings SET checkin_without_payment = 1, checkin_arrangement_note = ? WHERE id = ?')
                    ->execute([$old['checkin_arrangement_note'] ?? null, $newRoot]);
            }
            // Owner answer 4: the group keeps its old invoice number (written onto the new root only when it would flip).
            if ($before !== '' && rh_group_ops_invoice_number($pdo, $live, $newRoot) !== $before) {
                $pdo->prepare('UPDATE bookings SET final_invoice_number = ? WHERE id = ?')->execute([$before, $newRoot]);
                $out['invoice_copied'] = true;
            }
            $out['chase_copied'] = rh_group_copy_chase($pdo, $oldRoot, $newRoot);

            if (($opts['log'] ?? true) !== false) {
                $meta = ['old_root' => $oldRoot, 'new_root' => $newRoot, 'invoice_number' => $before, 'billing' => $out['billing'], 'chase_copied' => $out['chase_copied'],
                    'arrangement_copied' => $out['arrangement_copied']];
                $inv = $before !== '' ? ' Group invoice number ' . $before . ' kept.' : '';
                $bill = $out['billing'] === 'copied' ? ' Company account ' . $out['company'] . ' copied onto the new primary room.' : '';
                rh_group_ops_log($new, 'Made the primary room of the group', 'This room is now the group\'s primary room (contact, documents and folio). No money moved.' . $inv . $bill,
                    (string)$old['booking_reference'], (string)$new['booking_reference'], $opts, $meta);
                rh_group_ops_log($old, 'Primary room of the group moved', 'The group\'s primary room moved to ' . $out['new_label'] . '. No money moved.' . $inv,
                    (string)$old['booking_reference'], (string)$new['booking_reference'], $opts, $meta);
                if ($out['arrangement_copied']) {
                    $arrNote = trim((string)($old['checkin_arrangement_note'] ?? ''));
                    rh_group_ops_log($new, 'Check in before payment copied', 'The group\'s pay-later arrangement ("Check in before payment") was copied from '
                        . $out['old_label'] . ' onto this room, the new primary room, so every room of the group keeps it.'
                        . ($arrNote !== '' ? ' Note: ' . $arrNote : ''), 'Off', 'On', $opts, $meta);
                }
                if ($out['invoice_copied']) {
                    rh_group_ops_log($new, 'Group invoice number kept', 'The group invoice number was written onto this room so it stays the same.',
                        trim((string)($new['final_invoice_number'] ?? '')) !== '' ? (string)$new['final_invoice_number'] : '(none)', $before, $opts, $meta);
                }
            }
            $out['success'] = true;
            return $out;
        };
        if ($dry) {
            return $work();
        }
        return rh_group_ops_tx($pdo, $work);
    }

    /**
     * R5: make one group of $ids with $leadId as its primary room. $ids may include the lead and rooms already in the
     * lead's own group (left as they are). Refused (rh_group_can_join, G4): a deleted or expired booking, a cancelled booking
     * that owes nothing, a no-show or cancelled booking as the lead; also a booking in another group; fewer than 2 bookings;
     * a joining booking not on the lead's company account when the lead is on one. A no-show, and a cancelled booking still
     * owing its kept fee, may join. Each booking keeps its own payments, balance and cancellation snapshot. When the lead is a non-primary room of its
     * group, the group is first re-pointed to it (rh_group_repoint). Chase stages: each single's key -> the group key.
     *
     * $opts: as rh_group_repoint (user_id, user_name, dry_run, expect_state).
     * @return array{success:bool,error:string,state:string,lead:int,joined:int[],repointed:bool,rooms:array,totals:array,invoice_number:string,company:string}
     */
    function rh_group_make(PDO $pdo, int $leadId, array $ids, array $opts = []): array
    {
        $out = ['success' => false, 'error' => '', 'state' => '', 'lead' => $leadId, 'joined' => [], 'repointed' => false,
            'rooms' => [], 'totals' => ['billed' => 0.0, 'paid' => 0.0, 'owed' => 0.0], 'invoice_number' => '', 'company' => ''];
        $ids = array_values(array_unique(array_filter(array_map('intval', $ids), static fn($i) => $i > 0)));
        if (!in_array($leadId, $ids, true)) {
            $ids[] = $leadId;
        }
        $dry = !empty($opts['dry_run']);
        $work = static function () use ($pdo, $leadId, $ids, $opts, $dry, $out): array {
            if ($leadId <= 0 || count($ids) < 2) {
                $out['error'] = 'Tick at least two bookings to make a group.';
                return $out;
            }
            $ph = implode(',', array_fill(0, count($ids), '?'));
            $st = $pdo->prepare("SELECT b.id, b.booking_reference, b.guest_name, b.room_guest_name, b.status, b.deleted_at, b.primary_booking_id,
                    b.billing_account_id, b.billing_reference, b.billing_due_date, b.billing_covers, b.final_invoice_number,
                    b.check_in_date, b.check_out_date, b.amount_due, COALESCE((SELECT r.name FROM rooms r WHERE r.id = b.room_id), '') AS room_name,
                    (SELECT COUNT(*) FROM bookings m WHERE m.primary_booking_id = b.id) AS member_count
                FROM bookings b WHERE b.id IN ($ph) ORDER BY b.id" . ($dry ? '' : ' FOR UPDATE'));
            $st->execute($ids);
            $rows = [];
            foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) {
                $rows[(int)$r['id']] = $r;
            }
            if (count($rows) !== count($ids)) {
                $out['error'] = 'One of the bookings no longer exists. Reload the list.';
                return $out;
            }
            $lead = $rows[$leadId];
            $groupRoot = (int)($lead['primary_booking_id'] ?? 0) > 0 ? (int)$lead['primary_booking_id'] : $leadId;
            $groupRows = rh_group_ops_rows($pdo, $groupRoot, !$dry); // the lead's own group (or just the lead)
            foreach ($rows as $id => $r) {
                $label = rh_group_ops_label($r);
                $can = rh_group_can_join($r, $id === $leadId); // G4: the same rule as the list's ticks
                if (!$can['ok']) {
                    $out['error'] = $can['reason'];
                    return $out;
                }
                $inLeadGroup = isset($groupRows[$id]);
                $inOther = !$inLeadGroup && ((int)($r['primary_booking_id'] ?? 0) > 0 || (int)$r['member_count'] > 0);
                if ($inOther) {
                    $out['error'] = $label . ' is already in another group. Remove it from that group first.';
                    return $out;
                }
            }
            $joining = array_values(array_filter(array_keys($rows), static fn($id) => !isset($groupRows[$id])));
            if (!$joining) {
                $out['error'] = 'These bookings are already one group.';
                return $out;
            }
            // Payer (A6): the lead on a company account takes only rooms on that same account.
            $leadBill = null;
            if (rh_group_ops_billing_ready($pdo)) {
                $leadBill = rh_group_ops_billing($lead) ?? (isset($groupRows[$groupRoot]) ? rh_group_ops_billing($groupRows[$groupRoot]) : null);
                if ($leadBill) {
                    $out['company'] = rh_group_ops_company($pdo, $leadBill['billing_account_id']);
                    foreach ($joining as $id) {
                        $jb = rh_group_ops_billing($rows[$id]);
                        if (!$jb || $jb['billing_account_id'] !== $leadBill['billing_account_id']) {
                            $out['error'] = 'Not grouped: the primary room is billed to ' . $out['company'] . ' but ' . rh_group_ops_label($rows[$id])
                                . ($jb ? ' is billed to ' . rh_group_ops_company($pdo, $jb['billing_account_id']) : ' is paid by the guest') . '. Different payers cannot share a group.';
                            return $out;
                        }
                    }
                }
            }
            $out['state'] = rh_group_ops_state($groupRoot, $groupRows, array_merge([$leadId], $joining));
            if (($opts['expect_state'] ?? '') !== '' && !hash_equals((string)$opts['expect_state'], $out['state'])) {
                $out['error'] = 'The bookings changed since you reviewed them. Close this and review again.';
                return $out;
            }
            $allIds = array_values(array_unique(array_merge(rh_group_ops_live_ids($groupRows), $joining)));
            $before = rh_group_ops_invoice_number($pdo, rh_group_ops_live_ids($groupRows), $groupRoot);
            $out['invoice_number'] = $before;
            // Re-point first when the chosen lead is not its group's primary room.
            if ($groupRoot !== $leadId) {
                // The make's own reviewed state (checked above) covers this group; never pass it on as the repoint's state.
                $rp = rh_group_repoint($pdo, $groupRoot, $leadId, ['dry_run' => $dry, 'expect_state' => ''] + $opts);
                if (empty($rp['success'])) {
                    $out['error'] = $rp['error'];
                    return $out;
                }
                $out['repointed'] = true;
            }
            foreach (array_merge([$leadId], array_values(array_diff($allIds, [$leadId]))) as $id) {
                $r = $rows[$id] ?? $groupRows[$id];
                $m = rh_group_ops_money($id);
                $out['rooms'][] = ['id' => $id, 'label' => rh_group_ops_label($r), 'status' => (string)$r['status'],
                    'check_in' => substr((string)$r['check_in_date'], 0, 10), 'check_out' => substr((string)$r['check_out_date'], 0, 10),
                    'joining' => in_array($id, $joining, true), 'lead' => $id === $leadId] + $m;
                foreach (['billed', 'paid', 'owed'] as $k) {
                    $out['totals'][$k] = round($out['totals'][$k] + $m[$k], 2);
                }
            }
            $out['joined'] = $joining;
            if ($dry) {
                $out['success'] = true;
                return $out;
            }
            $up = $pdo->prepare('UPDATE bookings SET primary_booking_id = ?, updated_at = NOW() WHERE id = ?');
            $leadRow = $rows[$leadId];
            $chase = 0;
            foreach ($joining as $id) {
                $up->execute([$leadId, $id]);
                $chase += rh_group_copy_chase($pdo, $id, $leadId);
                $jSt = strtolower((string)$rows[$id]['status']);
                $jNote = $jSt === 'no-show' ? ' Joined as a no-show: a group payment pays it only what it still owes.'
                    : ($jSt === 'cancelled' ? ' Joined as a cancelled booking: only its kept fee counts in the group.' : '');
                rh_group_ops_log($rows[$id], 'Added to a group', 'Joined the group led by ' . rh_group_ops_label($leadRow) . '. Its payments, balance and cancellation policy stay on this room.' . $jNote,
                    '(individual booking)', (string)$leadRow['booking_reference'], $opts, ['lead' => $leadId]);
            }
            if ($before !== '' && rh_group_ops_invoice_number($pdo, $allIds, $leadId) !== $before) {
                $pdo->prepare('UPDATE bookings SET final_invoice_number = ? WHERE id = ?')->execute([$before, $leadId]);
            }
            $refs = array_map(static fn($id) => (string)$rows[$id]['booking_reference'], $joining);
            rh_group_ops_log($leadRow, 'Rooms added to the group', 'Added to this group: ' . implode(', ', $refs) . '. No money moved.',
                null, implode(', ', $refs), $opts, ['joined' => $joining, 'chase_copied' => $chase]);
            $out['success'] = true;
            return $out;
        };
        if ($dry) {
            return $work();
        }
        return rh_group_ops_tx($pdo, $work);
    }

    /**
     * R6: take $bookingId out of its group; it becomes an individual booking with its own payments, balance and invoice
     * and keeps its (group) cancellation snapshot. A room that inherited the group's company account gets the 4 billing
     * columns copied, so its payer does not change. Chase stages: the group key -> the room's own key. Removing the
     * PRIMARY room re-points the group first (rh_group_repoint) to $opts['new_root'] or, when not given, the first live
     * room (lowest id; else the lowest id). If the room held the group's invoice number on its own row, that number stays
     * with the group and the room gets a new one when its own invoice is made.
     *
     * $opts: user_id, user_name, dry_run, expect_state, new_root (int),
     *        pay_later ('keep'|'drop', Ship Q1 owner 2026-11-18): needed only when the room INHERITS "check in now, pay
     *        later" from its lead (lead checkin_without_payment = 1, the room's own = 0; pay_later_needed in the result).
     *        keep = the lead's switch + note are copied onto the room; drop = nothing copied, the room pays on its own.
     *        A missing / unknown choice when one is needed is refused (never defaulted); ignored when not needed.
     *        after_detach (callable(PDO $pdo, array $result): void) - runs inside the same transaction after the room is
     *        detached (C2 hook: the accounting lead's optional "move part of a lump sum to the leaving room" step;
     *        throwing rolls the whole removal back).
     * @return array{success:bool,error:string,state:string,booking_id:int,old_root:int,new_root:int,repointed:bool,
     *   billing:string,company:string,invoice_number:string,invoice_cleared:bool,chase_copied:int,label:string,new_label:string,room:array,remaining:int}
     *   billing: 'none' (guest pays) | 'own' (the room has its own company account, unchanged) | 'copied' (inherited account copied on).
     */
    function rh_group_remove(PDO $pdo, int $bookingId, array $opts = []): array
    {
        $out = ['success' => false, 'error' => '', 'state' => '', 'booking_id' => $bookingId, 'old_root' => 0, 'new_root' => 0,
            'repointed' => false, 'billing' => 'none', 'company' => '', 'invoice_number' => '', 'invoice_cleared' => false,
            'chase_copied' => 0, 'label' => '', 'new_label' => '', 'room' => [], 'remaining' => 0,
            'pay_later_needed' => false, 'pay_later_note' => '', 'pay_later' => ''];
        $dry = !empty($opts['dry_run']);
        $work = static function () use ($pdo, $bookingId, $opts, $dry, $out): array {
            $root = $bookingId > 0 ? rh_group_ops_root($pdo, $bookingId) : 0;
            $rows = $root > 0 ? rh_group_ops_rows($pdo, $root, !$dry) : [];
            if (!isset($rows[$bookingId]) || count($rows) < 2) {
                $out['error'] = 'This booking is not in a group.';
                return $out;
            }
            $row = $rows[$bookingId];
            $out['label'] = rh_group_ops_label($row);
            if (!empty($row['deleted_at'])) {
                $out['error'] = 'This booking is deleted. Restore it before taking it out of the group.';
                return $out;
            }
            $out['old_root'] = $root;
            $newRoot = $root;
            $closedRoot = false; // G4: true only when no live room is left and the group goes to a no-show / cancelled one
            if ($bookingId === $root) {
                $newRoot = (int)($opts['new_root'] ?? 0);
                if ($newRoot <= 0 || !isset($rows[$newRoot]) || $newRoot === $root) {
                    $newRoot = 0;
                    foreach ($rows as $id => $r) { // first live room, else the first not deleted
                        if ($id !== $root && empty($r['deleted_at']) && !in_array(strtolower((string)$r['status']), ['cancelled', 'expired', 'no-show'], true)) {
                            $newRoot = $id;
                            break;
                        }
                    }
                    if ($newRoot <= 0) {
                        foreach ($rows as $id => $r) {
                            if ($id !== $root && empty($r['deleted_at'])) {
                                $newRoot = $id;
                                $closedRoot = true;
                                break;
                            }
                        }
                    }
                }
                if ($newRoot <= 0) {
                    $out['error'] = 'Every other room of this group is deleted. Nothing to hand the group to.';
                    return $out;
                }
            }
            $out['new_root'] = $newRoot;
            $out['new_label'] = rh_group_ops_label($rows[$newRoot]);
            // Ship Q1: a member that inherits the lead's pay-later arrangement (read from the locked rows) needs FOH's
            // keep / drop choice. The root itself always holds its own switch, so it never needs one.
            $payLaterNeeded = $bookingId !== $root && !empty($rows[$root]['checkin_without_payment']) && empty($row['checkin_without_payment']);
            $out['pay_later_needed'] = $payLaterNeeded;
            $out['pay_later_note'] = $payLaterNeeded ? trim((string)($rows[$root]['checkin_arrangement_note'] ?? '')) : '';
            $out['state'] = rh_group_ops_state($root, $rows, [$bookingId, $newRoot, $payLaterNeeded ? 'pl' : '']);
            if (($opts['expect_state'] ?? '') !== '' && !hash_equals((string)$opts['expect_state'], $out['state'])) {
                $out['error'] = 'The group changed since you reviewed it. Close this and review again.';
                return $out;
            }
            $live = rh_group_ops_live_ids($rows);
            $before = rh_group_ops_invoice_number($pdo, $live, $root);
            $out['invoice_number'] = $before;
            $out['room'] = rh_group_ops_money($bookingId);
            $out['remaining'] = count(array_diff($live, [$bookingId])); // live rooms left in the group

            if ($newRoot !== $root) {
                $rp = rh_group_repoint($pdo, $root, $newRoot, ['dry_run' => $dry, 'expect_state' => '', 'allow_closed_root' => $closedRoot] + $opts);
                if (empty($rp['success'])) {
                    $out['error'] = $rp['error'];
                    return $out;
                }
                $out['repointed'] = true;
            }
            // The payer the room has today: its own company, else the one it inherits from the (old) root.
            $inherit = null;
            if (rh_group_ops_billing_ready($pdo) && !rh_group_ops_billing($row)) {
                $inherit = rh_group_ops_billing($rows[$root]);
                if ($inherit) {
                    $out['billing'] = 'copied';
                    $out['company'] = rh_group_ops_company($pdo, $inherit['billing_account_id']);
                }
            } elseif (rh_group_ops_billing($row)) {
                $out['billing'] = 'own';
                $out['company'] = rh_group_ops_company($pdo, (int)$row['billing_account_id']);
            }
            $holdsGroupNo = $before !== '' && trim((string)($row['final_invoice_number'] ?? '')) === $before;
            $out['invoice_cleared'] = $holdsGroupNo;
            $payLater = '';
            if ($payLaterNeeded) {
                $payLater = in_array($opts['pay_later'] ?? '', ['keep', 'drop'], true) ? (string)$opts['pay_later'] : '';
                if ($payLater === '' && !$dry) {
                    $out['error'] = 'This room checks in now and pays later because its group does. Choose whether it keeps pay later or pays on its own.';
                    return $out;
                }
                $out['pay_later'] = $payLater;
            }
            if ($dry) {
                $out['success'] = true;
                return $out;
            }

            $pdo->prepare('UPDATE bookings SET primary_booking_id = NULL, updated_at = NOW() WHERE id = ?')->execute([$bookingId]);
            if ($inherit) {
                $pdo->prepare('UPDATE bookings SET billing_account_id = ?, billing_reference = ?, billing_due_date = ?, billing_covers = ? WHERE id = ?')
                    ->execute([$inherit['billing_account_id'], $inherit['billing_reference'], $inherit['billing_due_date'], $inherit['billing_covers'], $bookingId]);
            }
            // The group keeps its number (written on its root when it would flip); the leaving room never prints it as its own.
            $rest = array_values(array_diff($live, [$bookingId]));
            // A group of 2 leaves ONE room: the number goes to that room (the new root), so an issued number is never lost
            // (QA 2026-11-18). Same rule as a primary switch (owner answer 4).
            if ($before !== '' && count($rest) >= 1) {
                $restNo = count($rest) >= 2
                    ? rh_group_ops_invoice_number($pdo, $rest, $newRoot)
                    : trim((string)($rows[$newRoot]['final_invoice_number'] ?? ''));
                if ($restNo !== $before) {
                    $pdo->prepare('UPDATE bookings SET final_invoice_number = ? WHERE id = ?')->execute([$before, $newRoot]);
                }
            }
            if ($holdsGroupNo) {
                // The cached group PDF goes with the number: otherwise checkout's "already generated" path would hand the
                // leaving room the old group PDF (config/invoice.php final_invoice_generated check), and Generate Invoice
                // would mint a number it never saves. Its next invoice is built fresh with its own number.
                $pdo->prepare('UPDATE bookings SET final_invoice_number = NULL, final_invoice_path = NULL, final_invoice_generated = 0 WHERE id = ?')->execute([$bookingId]);
            }
            $out['chase_copied'] = rh_group_copy_chase($pdo, $root, $bookingId);
            if ($payLater === 'keep') { // same copy as rh_group_repoint's arrangement copy
                $pdo->prepare('UPDATE bookings SET checkin_without_payment = 1, checkin_arrangement_note = ? WHERE id = ?')
                    ->execute([$rows[$root]['checkin_arrangement_note'] ?? null, $bookingId]);
            }

            $newRow = $rows[$newRoot];
            $meta = ['group_root' => $newRoot, 'old_root' => $root, 'billing' => $out['billing'], 'invoice_cleared' => $holdsGroupNo, 'chase_copied' => $out['chase_copied'],
                'pay_later' => $payLater];
            $bill = $out['billing'] === 'copied' ? ' Still billed to ' . $out['company'] . ' (copied from the group).' : '';
            rh_group_ops_log($row, 'Removed from the group', 'Taken out of the group led by ' . rh_group_ops_label($newRow)
                . '. It is now an individual booking with its own payments, balance and invoice; its cancellation policy is kept.' . $bill,
                (string)$newRow['booking_reference'], '(individual booking)', $opts, $meta);
            if ($payLater === 'keep') {
                rh_group_ops_log($row, 'Kept pay-later from group', 'This room had "check in now, pay later" from its group. It keeps it now that it is an individual booking.'
                    . ($out['pay_later_note'] !== '' ? ' Note: ' . $out['pay_later_note'] : ''), 'On (from the group)', 'On (own)', $opts, $meta);
            } elseif ($payLater === 'drop') {
                rh_group_ops_log($row, 'Dropped pay-later from group', 'This room had "check in now, pay later" from its group. It was dropped: this room pays on its own.',
                    'On (from the group)', 'Off', $opts, $meta);
            }
            if ($holdsGroupNo) {
                rh_group_ops_log($row, 'Group invoice number left with the group', 'This room held the group invoice number; it stays with the group and this room gets its own number when its invoice is made.',
                    $before, '(none yet)', $opts, $meta);
            }
            rh_group_ops_log($newRow, 'Room removed from the group', rh_group_ops_label($row) . ' was taken out of this group and is now an individual booking.'
                . (isset($opts['after_detach']) ? '' : ' No money moved.'),
                (string)$row['booking_reference'], null, $opts, $meta);

            // C2 hook (group bookings plan D-R6, accounting lead): optionally move part of a group lump-sum payment to the
            // leaving room, in this same transaction (includes/group-credit-move.php rh_group_lump_apply).
            if (isset($opts['after_detach']) && is_callable($opts['after_detach'])) {
                ($opts['after_detach'])($pdo, $out);
            }
            $out['success'] = true;
            return $out;
        };
        if ($dry) {
            return $work();
        }
        return rh_group_ops_tx($pdo, $work);
    }
}
