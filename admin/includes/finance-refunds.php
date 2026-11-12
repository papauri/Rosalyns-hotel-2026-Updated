<?php
/**
 * One refund rule for every money screen (Payments, Accounting, Reports, End of Day, Invoices), plus the plain
 * wording of a Payments row. Pure: SQL fragments and labels only, no database, no settings.
 * Unit tested by scripts/tests/finance_refunds.php.
 *
 * Refund definition (owner rule, Oct 2026: a refund is the full amount paid back and is shown in full in every report):
 *   - a refund is a payments row with payment_type = 'refund';
 *   - its amount is total_amount: the GROSS money paid back (VAT and tourism levy included). It is the column the
 *     canonical net-paid rule subtracts (recalculateBookingFinancials, rh_sum_account_paid), so every refund total
 *     agrees with the balances. Every refund writer stores refund_amount = total_amount; total_amount is used so a
 *     row can never count in a balance and be missing from a refund total;
 *   - it counts as paid out once refund_status is 'completed' or 'processing' (the canonical net-paid rule);
 *   - 'pending' = waiting for approval: shown on its own, never inside the refunds total;
 *   - 'failed' and 'rejected' never count;
 *   - totals show it as a positive "Refunds" figure taken off the money collected; a list row shows it with a minus.
 */

if (!function_exists('rh_refund_settled_statuses')) {
    /** refund_status values that mean the money has gone (or is going) back out. */
    function rh_refund_settled_statuses(): array
    {
        return ['completed', 'processing'];
    }

    /** "alias." for a trusted table alias (letters, digits, underscore), '' for none or anything else. */
    function rh_refund_alias(string $alias): string
    {
        return preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/', $alias) ? $alias . '.' : '';
    }

    /** SQL condition: a refund row that counts as paid out (completed or processing). */
    function rh_refund_settled_sql(string $alias = ''): string
    {
        $a = rh_refund_alias($alias);
        return "{$a}payment_type = 'refund' AND {$a}refund_status IN ('completed','processing')";
    }

    /** SQL condition: a refund row still waiting for approval. */
    function rh_refund_pending_sql(string $alias = ''): string
    {
        $a = rh_refund_alias($alias);
        return "{$a}payment_type = 'refund' AND {$a}refund_status = 'pending'";
    }

    /** SQL expression: the amount a refund row pays back (gross, positive). */
    function rh_refund_amount_sql(string $alias = ''): string
    {
        return rh_refund_alias($alias) . 'total_amount';
    }

    /** SQL aggregate: total refunds paid out (completed + processing), gross, positive. */
    function rh_refund_settled_sum_sql(string $alias = ''): string
    {
        return 'COALESCE(SUM(CASE WHEN ' . rh_refund_settled_sql($alias) . ' THEN ' . rh_refund_amount_sql($alias) . ' ELSE 0 END), 0)';
    }

    /** SQL aggregate: how many refund rows are paid out (completed + processing). */
    function rh_refund_settled_count_sql(string $alias = ''): string
    {
        return 'COALESCE(SUM(CASE WHEN ' . rh_refund_settled_sql($alias) . ' THEN 1 ELSE 0 END), 0)';
    }

    /** SQL aggregate: refunds waiting for approval, gross, positive. */
    function rh_refund_pending_sum_sql(string $alias = ''): string
    {
        return 'COALESCE(SUM(CASE WHEN ' . rh_refund_pending_sql($alias) . ' THEN ' . rh_refund_amount_sql($alias) . ' ELSE 0 END), 0)';
    }

    /** SQL aggregate: how many refund rows are waiting for approval. */
    function rh_refund_pending_count_sql(string $alias = ''): string
    {
        return 'COALESCE(SUM(CASE WHEN ' . rh_refund_pending_sql($alias) . ' THEN 1 ELSE 0 END), 0)';
    }

    /**
     * SQL condition: a paid-out refund whose money went INTO a credit note (payment_method 'credit_note': an
     * overpayment moved to a note, or a store-credit refund). No cash left the business: the guest holds the credit
     * on the note. It stays in the refunds total (the money is not revenue any more), but every screen shows it apart
     * from refunds paid back in cash / bank, so staff never read it as cash paid out.
     */
    function rh_refund_to_credit_sql(string $alias = ''): string
    {
        $a = rh_refund_alias($alias);
        return rh_refund_settled_sql($alias) . " AND {$a}payment_method = 'credit_note'";
    }

    /** SQL aggregate: paid-out refunds moved into credit notes (no cash out), gross, positive. */
    function rh_refund_to_credit_sum_sql(string $alias = ''): string
    {
        return 'COALESCE(SUM(CASE WHEN ' . rh_refund_to_credit_sql($alias) . ' THEN ' . rh_refund_amount_sql($alias) . ' ELSE 0 END), 0)';
    }

    /**
     * Plain split line for a refunds total: "Paid back in cash / bank: MWK x · Moved to credit notes (guest credit,
     * no cash out): MWK y". '' when nothing went to a credit note (the total is then all cash / bank).
     */
    function rh_refund_split_text(float $total, float $toCredit, string $currency = '', int $decimals = 0): string
    {
        $toCredit = round(max(0.0, $toCredit), 2);
        if ($toCredit < 0.005) {
            return '';
        }
        $cash = round(max(0.0, $total - $toCredit), 2);
        $c = $currency !== '' ? $currency . ' ' : '';
        return 'Paid back in cash / bank: ' . $c . number_format($cash, $decimals)
            . ' · Moved to credit notes (guest credit, no cash out): ' . $c . number_format($toCredit, $decimals);
    }

    /** Plain words for a refund's lifecycle step. */
    function rh_refund_status_label(string $refundStatus): string
    {
        switch (strtolower(trim($refundStatus))) {
            case 'pending': return 'Awaiting approval';
            case 'processing': return 'Being paid';
            case 'completed': return 'Paid out';
            case 'failed': return 'Failed';
            case 'rejected': return 'Rejected';
        }
        return 'Unknown';
    }
}

if (!function_exists('rh_pay_row_state')) {
    /**
     * What a Payments list row says. A payment row's status is the TRANSACTION's status: 'completed' means the
     * money was received, never that the bill is settled, so the bill's own position is a separate note read from
     * the parent account (already joined in the list query, no query per row).
     *
     * $row keys: payment_type, payment_status, refund_status, account_amount_due (null = no bill on this page, such as
     * a till order), account_status, account_in_rule (1 when the shared receivables rule counts the account as owed),
     * account_unconfirmed (1 when rh_unconfirmed_status_sql says not confirmed yet), payment_method (refund legs).
     * Money is compared with $tol (BALANCE_TOLERANCE).
     *
     * @return array{label:string, badge:string, note:string, tone:string, amount_sign:string}
     *         tone: 'owe' | 'ok' | 'warn' | 'muted' | '' ; amount_sign '-' for refunds, '' otherwise
     */
    function rh_pay_row_state(array $row, float $tol, string $currency = ''): array
    {
        $type = strtolower(trim((string)($row['payment_type'] ?? '')));
        $ps = strtolower(trim((string)($row['payment_status'] ?? '')));
        if ($type === 'refund') {
            $rs = strtolower(trim((string)($row['refund_status'] ?? '')));
            $badge = ['pending' => 'warning', 'processing' => 'info', 'completed' => 'refunded', 'failed' => 'failed', 'rejected' => 'rejected'][$rs] ?? 'neutral';
            $counts = in_array($rs, rh_refund_settled_statuses(), true);
            $toCredit = strtolower(trim((string)($row['payment_method'] ?? ''))) === 'credit_note';
            return [
                'label' => ($toCredit && $counts) ? 'Refund: Moved to credit note' : 'Refund: ' . rh_refund_status_label($rs),
                'badge' => $badge,
                'note' => $counts ? ($toCredit ? 'Guest credit, no cash out' : 'Counted in Refunds') : ($rs === 'pending' ? 'Not in Refunds until approved' : 'Not counted'),
                'tone' => 'muted',
                'amount_sign' => '-',
            ];
        }

        $received = in_array($ps, ['completed', 'paid', 'refunded', 'partially_refunded'], true);
        $labels = [
            'completed' => 'Received', 'paid' => 'Received', 'refunded' => 'Received, refunded',
            'partially_refunded' => 'Received, part refunded', 'pending' => 'Pending (not received)',
            'partial' => 'Partial (not received)', 'failed' => 'Failed', 'cancelled' => 'Cancelled',
        ];
        $badges = ['completed' => 'completed', 'paid' => 'completed', 'refunded' => 'refunded', 'partially_refunded' => 'refunded'];
        $out = [
            'label' => $labels[$ps] ?? ucfirst(str_replace('_', ' ', $ps)),
            'badge' => $badges[$ps] ?? $ps,
            'note' => '',
            'tone' => '',
            'amount_sign' => '',
        ];
        if (!$received && !in_array($ps, ['pending', 'partial'], true)) {
            return $out; // failed / cancelled: the transaction did not happen; no bill note
        }
        $due = $row['account_amount_due'] ?? null;
        if ($due === null || $due === '') {
            return $out; // no bill on this page (till orders settle at the till)
        }
        $due = round((float)$due, 2);
        $acctStatus = strtolower(trim((string)($row['account_status'] ?? '')));
        $inRule = !empty($row['account_in_rule']);
        $money = ($currency !== '' ? $currency . ' ' : '') . number_format($due, 0);
        if ($acctStatus === 'deleted') {
            $out['note'] = 'Booking deleted';
            $out['tone'] = 'muted';
        } elseif ($due > $tol && $inRule) {
            $out['note'] = 'Booking still owes ' . $money;
            $out['tone'] = 'owe';
        } elseif ($due > $tol) {
            // Open balance on an account the receivables rule does not count: not confirmed yet (account_unconfirmed,
            // from rh_unconfirmed_status_sql: the same rule as the "Deposits taken, not yet confirmed" line), or a
            // no-show / expired booking. Named plainly so it never reads as owed or as settled.
            $out['note'] = !empty($row['account_unconfirmed'])
                ? 'Not confirmed yet: ' . $money . ' open'
                : ucfirst(str_replace(['_', '-'], ' ', $acctStatus !== '' ? $acctStatus : 'unknown')) . ': ' . $money . ', not counted as owed';
            $out['tone'] = 'warn';
        } elseif ($acctStatus === 'cancelled') {
            $out['note'] = 'Booking cancelled';
            $out['tone'] = 'muted';
        } else {
            $out['note'] = 'Bill settled';
            $out['tone'] = 'ok';
        }
        return $out;
    }
}
