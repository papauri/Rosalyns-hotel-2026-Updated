<?php
/**
 * Booking details "Paid" figure: the "i" popup listing WHEN the guest paid and how much (owner 2026-11-25).
 *
 * The rows are exactly the ones getBookingFolioSummary() (config/database.php) adds up for amount_paid:
 * payments with booking_type 'room' for this booking, not deleted -
 *   + money received (payment_status paid / completed / refunded / partially_refunded, not a refund row)
 *   - refunds paid out or being paid out (refund rows, refund_status completed / processing)
 * Rows that do not count (refunds waiting for approval, payments not yet received) are shown as notes, never added.
 * A refund is dated by the day it was paid out (rh_pay_count_day_sql), money received by its payment date.
 * Read only. Output is shown by admin/js/rh-total-info.js (textContent only).
 */
require_once __DIR__ . '/finance-refunds.php';

if (!function_exists('rh_booking_paid_breakdown')) {
    /**
     * @param float $paidShown the Paid figure on the page (folio summary amount_paid): the lines must add up to it.
     * @return array{ok:bool,title:string,note:string,lines:array<int,array{label:string,hint:string,value:string,kind:string}>}
     */
    function rh_booking_paid_breakdown(PDO $pdo, int $bookingId, float $paidShown, string $currency = ''): array
    {
        $out = ['ok' => false, 'title' => 'Payments on this booking', 'note' => '', 'lines' => []];
        $fmt = static function (float $v) use ($currency): string {
            return ($v < 0 ? '-' : '') . $currency . number_format(abs($v), 2);
        };
        $day = static function (string $d): string {
            $t = strtotime($d);
            return $t ? date('D j M Y', $t) : $d;
        };
        $words = static function (string $v): string {
            return $v === '' ? '' : ucfirst(str_replace('_', ' ', $v));
        };
        $typeLabels = ['deposit' => 'Deposit', 'full_payment' => 'Full payment', 'partial_payment' => 'Part payment', 'adjustment' => 'Adjustment'];

        try {
            $st = $pdo->prepare("SELECT id, payment_date, created_at, payment_method, payment_type, payment_status, refund_status,
                    total_amount, payment_reference, receipt_number, " . rh_pay_count_day_sql() . " AS count_day
                FROM payments
                WHERE booking_type = 'room' AND booking_id = ? AND deleted_at IS NULL
                ORDER BY count_day ASC, created_at ASC, id ASC");
            $st->execute([$bookingId]);
            $rows = $st->fetchAll(PDO::FETCH_ASSOC);
        } catch (Throwable $e) {
            error_log('rh_booking_paid_breakdown: ' . $e->getMessage());
            return $out;
        }

        $sum = 0.0;
        $counted = 0;
        foreach ($rows as $r) {
            $amount = (float)$r['total_amount'];
            $isRefund = (string)$r['payment_type'] === 'refund';
            $method = (string)($r['payment_method'] ?? '');
            $ref = trim((string)($r['receipt_number'] ?? '')) !== '' ? trim((string)$r['receipt_number']) : trim((string)($r['payment_reference'] ?? ''));
            $created = (string)($r['created_at'] ?? '');
            $hintParts = [];

            if ($isRefund) {
                $rs = (string)($r['refund_status'] ?? '');
                if ($method === 'credit_note') {
                    $hintParts[] = 'moved to guest credit (credit note)';
                } else {
                    $hintParts[] = 'refund' . ($method !== '' ? ', ' . strtolower($words($method)) : '');
                }
                if ($ref !== '') { $hintParts[] = $ref; }
                if (in_array($rs, ['completed', 'processing'], true)) {
                    if ($rs === 'processing') { $hintParts[] = 'being paid out'; }
                    $sum -= $amount;
                    $counted++;
                    $out['lines'][] = ['label' => $day((string)$r['count_day']), 'hint' => implode(' · ', $hintParts), 'value' => $fmt(-$amount), 'kind' => 'minus'];
                } elseif ($rs === 'pending') {
                    $out['lines'][] = ['label' => $day((string)$r['payment_date']), 'hint' => 'refund waiting for approval, not taken off yet', 'value' => $fmt(-$amount), 'kind' => 'note'];
                }
                continue;
            }

            $status = (string)($r['payment_status'] ?? '');
            $type = $typeLabels[(string)($r['payment_type'] ?? '')] ?? '';
            if ($type !== '') { $hintParts[] = $type; }
            if ($method === 'credit_note') {
                $hintParts[] = 'paid with guest credit';
            } elseif ($method !== '') {
                $hintParts[] = strtolower($words($method));
            }
            if ($ref !== '') { $hintParts[] = $ref; }
            if ($created !== '' && strtotime($created)) {
                $hintParts[] = substr($created, 0, 10) === (string)$r['payment_date']
                    ? 'recorded at ' . date('H:i', strtotime($created))
                    : 'recorded ' . date('j M H:i', strtotime($created));
            }
            if (in_array($status, ['paid', 'completed', 'refunded', 'partially_refunded'], true)) {
                $sum += $amount;
                $counted++;
                $out['lines'][] = ['label' => $day((string)$r['payment_date']), 'hint' => implode(' · ', $hintParts), 'value' => $fmt($amount), 'kind' => 'add'];
            } elseif (in_array($status, ['pending', 'partial'], true)) {
                $out['lines'][] = ['label' => $day((string)$r['payment_date']), 'hint' => implode(' · ', $hintParts) . ' · not received yet, not counted', 'value' => $fmt($amount), 'kind' => 'note'];
            }
            // failed / cancelled rows never counted and are not listed
        }

        $sum = round($sum, 2);
        if ($counted === 0) {
            $out['lines'][] = ['label' => 'No payments received yet', 'hint' => '', 'value' => $fmt(0.0), 'kind' => 'note'];
        }
        if ($sum < -BALANCE_TOLERANCE) {
            // The Paid figure never goes below 0 (getBookingFolioSummary clamps it).
            $out['lines'][] = ['label' => 'More refunded than received', 'hint' => 'the Paid figure stops at 0', 'value' => $fmt(-$sum), 'kind' => 'note'];
        }
        $diff = round($paidShown - max(0.0, $sum), 2);
        if (abs($diff) > BALANCE_TOLERANCE) {
            // Never expected: the figure and the list read the same rows. Shown rather than hidden so it gets noticed.
            $out['lines'][] = ['label' => 'Not matched to a payment', 'hint' => 'difference between the Paid figure and the list', 'value' => $fmt($diff), 'kind' => 'add'];
        }
        $out['lines'][] = ['label' => 'Paid', 'hint' => $counted > 1 ? $counted . ' entries' : '', 'value' => $fmt($paidShown), 'kind' => 'total'];
        $out['note'] = 'Money received is dated by its payment date; a refund by the day it was paid out.';
        $out['ok'] = true;
        return $out;
    }

    /** The "i" button for the Paid figure ('' when there is nothing to show). Same popup as the total's "i". */
    function rh_booking_paid_info_btn(PDO $pdo, int $bookingId, float $paidShown, string $currency = ''): string
    {
        $bd = rh_booking_paid_breakdown($pdo, $bookingId, $paidShown, $currency);
        if (empty($bd['ok'])) {
            return '';
        }
        $json = json_encode($bd, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_INVALID_UTF8_SUBSTITUTE);
        if ($json === false) {
            return '';
        }
        return '<button type="button" class="rh-info-btn" aria-label="When the guest paid: dates and amounts" aria-haspopup="dialog" aria-expanded="false" data-rh-breakdown="' . htmlspecialchars($json, ENT_QUOTES, 'UTF-8') . '"><span aria-hidden="true">i</span></button>';
    }
}
