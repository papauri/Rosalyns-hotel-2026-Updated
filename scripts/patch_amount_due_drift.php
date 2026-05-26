<?php
/**
 * patch_amount_due_drift.php — idempotent fix for legacy bookings where
 * amount_due drifted away from (total_amount - amount_paid).
 *
 * Strategy: for active bookings (not cancelled/expired/no-show), recompute
 *   amount_due = MAX(0, total_amount - amount_paid)
 * and re-derive payment_status:
 *   amount_paid = 0           → unpaid
 *   amount_paid >= total      → paid
 *   else                      → partial
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
    SELECT id, booking_reference, status, payment_status, total_amount, amount_paid, amount_due
      FROM bookings
     WHERE status NOT IN ('cancelled','expired','no-show')
       AND total_amount > 0
       AND ABS(COALESCE(total_amount,0) - COALESCE(amount_paid,0) - COALESCE(amount_due,0)) > 1
")->fetchAll(PDO::FETCH_ASSOC);

if (empty($rows)) { echo "No drifted bookings. ✓" . PHP_EOL; exit(0); }

echo count($rows) . " booking(s) to repair:" . PHP_EOL;

$pdo->beginTransaction();
$upd = $pdo->prepare("UPDATE bookings SET amount_due=?, payment_status=?, updated_at=NOW() WHERE id=?");
$count = 0;
foreach ($rows as $r) {
    $tot  = (float)$r['total_amount'];
    $paid = (float)$r['amount_paid'];
    $newDue = max(0, $tot - $paid);
    if ($paid <= 0)         $newPS = 'unpaid';
    elseif ($paid >= $tot)  $newPS = 'paid';
    else                    $newPS = 'partial';

    echo sprintf("  #%d %s: due %.2f → %.2f, payment_status %s → %s" . PHP_EOL,
        $r['id'], $r['booking_reference'], (float)$r['amount_due'], $newDue, $r['payment_status'], $newPS);
    if ($apply) { $upd->execute([$newDue, $newPS, $r['id']]); $count++; }
}
if ($apply) { $pdo->commit(); echo "Committed $count update(s)." . PHP_EOL; }
else        { $pdo->rollBack(); echo "Dry-run complete — no changes." . PHP_EOL; }
