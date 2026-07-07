<?php
/**
 * patch_amount_due_drift.php — idempotent repair for bookings whose stored
 * amount_paid / amount_due snapshot drifted from the payments ledger.
 *
 * Accounting model (single source of truth = recalculateBookingFinancials):
 *   gross      = total_with_vat (locked at invoice time; falls back to
 *                total_amount + vat_amount) + active folio charges (gross)
 *   paid       = SUM(completed non-refund payments.total_amount)
 *                - SUM(completed/processing refunds.total_amount)
 *   amount_due = MAX(0, gross - paid)
 *
 * This script only DETECTS drift with that formula, then delegates the actual
 * write to recalculateBookingFinancials() so there is exactly one code path
 * that ever computes booking balances.
 *
 *   php scripts/patch_amount_due_drift.php           # dry-run
 *   php scripts/patch_amount_due_drift.php --apply   # write changes
 */
declare(strict_types=1);
require __DIR__ . '/../config/database.php';
global $pdo;

$apply = in_array('--apply', $argv, true);
echo $apply ? "APPLY mode — writing changes." . PHP_EOL : "DRY RUN — no writes. Re-run with --apply to commit." . PHP_EOL;

$rows = $pdo->query("
    SELECT b.id, b.booking_reference, b.status, b.payment_status,
           b.total_amount, b.total_with_vat, b.vat_amount, b.amount_paid, b.amount_due,
           COALESCE((SELECT SUM(bc.line_total) FROM booking_charges bc
                      WHERE bc.booking_id = b.id AND bc.voided = 0), 0) AS folio_gross,
           COALESCE((SELECT SUM(CASE
                        WHEN p.payment_status IN ('completed','paid') AND COALESCE(p.payment_type,'') <> 'refund' THEN p.total_amount
                        WHEN p.payment_type = 'refund' AND p.refund_status IN ('completed','processing') THEN -p.total_amount
                        ELSE 0 END)
                     FROM payments p
                     WHERE p.booking_type = 'room' AND p.booking_id = b.id AND p.deleted_at IS NULL), 0) AS ledger_paid
      FROM bookings b
     WHERE b.status NOT IN ('cancelled','expired','no-show')
       AND COALESCE(b.total_with_vat, b.total_amount) > 0
")->fetchAll(PDO::FETCH_ASSOC);

$drifted = [];
foreach ($rows as $r) {
    $baseGross = (float)($r['total_with_vat'] ?? 0);
    if ($baseGross <= 0) {
        $baseGross = (float)$r['total_amount'] + (float)($r['vat_amount'] ?? 0);
    }
    $gross = $baseGross + (float)$r['folio_gross'];
    $paid  = max(0.0, (float)$r['ledger_paid']);
    $due   = max(0.0, round($gross - $paid, 2));
    if (abs($due - (float)$r['amount_due']) > 1 || abs($paid - (float)$r['amount_paid']) > 1) {
        $r['expected_due']  = $due;
        $r['expected_paid'] = $paid;
        $drifted[] = $r;
    }
}

if (empty($drifted)) { echo "No drifted bookings. ✓" . PHP_EOL; exit(0); }

echo count($drifted) . " booking(s) to repair:" . PHP_EOL;
$count = 0;
foreach ($drifted as $r) {
    echo sprintf("  #%d %s [%s]: paid %.2f → %.2f, due %.2f → %.2f" . PHP_EOL,
        $r['id'], $r['booking_reference'], $r['status'],
        (float)$r['amount_paid'], $r['expected_paid'],
        (float)$r['amount_due'], $r['expected_due']);
    if ($apply) {
        if (recalculateBookingFinancials((int)$r['id'])) { $count++; }
        else { echo "    !! recalculateBookingFinancials failed for #{$r['id']}" . PHP_EOL; }
    }
}
echo $apply ? "Recalculated $count booking(s) via canonical function." . PHP_EOL
            : "Dry-run complete — no changes." . PHP_EOL;
