<?php
/**
 * Reports "Payment Status Mix": one line for EVERY status present, so the lines always add up to the mix total.
 * Pure (no database): reports.php runs the query, the test feeds rows straight in.
 *
 * Words come from rh_pay_row_state (the Payments list), so "Received, part refunded" reads the same on the page and
 * in the CSV. Older 'paid' rows share the "Received" line; the pseudo status 'credit_note' (a payment made WITH a
 * credit note: guest credit used, not money received) gets its own "Paid from credit note" line. A status this file
 * does not know yet still gets a line (never skipped), after the known ones.
 */
require_once __DIR__ . '/finance-refunds.php';

if (!function_exists('rh_reports_status_mix')) {
    /** Known statuses, in the order the mix lists them. */
    function rh_reports_status_mix_order(): array
    {
        return ['pending', 'partial', 'completed', 'credit_note', 'partially_refunded', 'refunded', 'failed', 'cancelled'];
    }

    /** Existing pill colour class for a status key ('' = the plain pill). */
    function rh_reports_status_mix_pill(string $key): string
    {
        $map = [
            'completed' => 'completed', 'pending' => 'pending', 'partial' => 'partial',
            'partially_refunded' => 'refunded', 'refunded' => 'refunded', 'failed' => 'danger', 'cancelled' => 'cancelled',
        ];
        return $map[$key] ?? '';
    }

    /** Payments-list words for a status key. */
    function rh_reports_status_mix_label(string $key, float $tol): string
    {
        $row = $key === 'credit_note'
            ? ['payment_status' => 'completed', 'payment_method' => 'credit_note']
            : ['payment_status' => $key];
        $label = trim((string)(rh_pay_row_state($row, $tol)['label'] ?? ''));
        return $label !== '' ? $label : ($key !== '' ? ucfirst(str_replace('_', ' ', $key)) : 'No status');
    }

    /**
     * @param array<int,array{payment_status:mixed,count:mixed,total_amount:mixed}> $rows grouped query rows
     * @return array{lines:array<int,array{key:string,label:string,pill:string,count:int,total:float}>, count:int, total:float, received_total:float}
     *         received_total = the three Received lines (completed/paid, part refunded, refunded): money received.
     */
    function rh_reports_status_mix(array $rows, float $tol): array
    {
        $byKey = [];
        foreach ($rows as $r) {
            $key = strtolower(trim((string)($r['payment_status'] ?? '')));
            if ($key === 'paid') {
                $key = 'completed'; // both read "Received"
            }
            if (!isset($byKey[$key])) {
                $byKey[$key] = ['count' => 0, 'total' => 0.0];
            }
            $byKey[$key]['count'] += (int)($r['count'] ?? 0);
            $byKey[$key]['total'] += (float)($r['total_amount'] ?? 0);
        }
        $keys = array_values(array_filter(rh_reports_status_mix_order(), static function ($k) use ($byKey) { return isset($byKey[$k]); }));
        foreach (array_keys($byKey) as $k) {
            if (!in_array($k, $keys, true)) {
                $keys[] = (string)$k;
            }
        }
        $out = ['lines' => [], 'count' => 0, 'total' => 0.0, 'received_total' => 0.0];
        foreach ($keys as $k) {
            $line = [
                'key' => $k,
                'label' => rh_reports_status_mix_label($k, $tol),
                'pill' => rh_reports_status_mix_pill($k),
                'count' => $byKey[$k]['count'],
                'total' => round($byKey[$k]['total'], 2),
            ];
            $out['lines'][] = $line;
            $out['count'] += $line['count'];
            $out['total'] += $line['total'];
            if (in_array($k, ['completed', 'partially_refunded', 'refunded'], true)) {
                $out['received_total'] += $line['total'];
            }
        }
        $out['total'] = round($out['total'], 2);
        $out['received_total'] = round($out['received_total'], 2);
        return $out;
    }
}
