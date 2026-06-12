<?php
/**
 * Email-friendly station report (included by admin/kds-report.php).
 * Variables in scope: $reqStation, $reqDate, $STATION_OPTIONS, $STATION_LABEL,
 * $totalItems, $totalQty, $totalRevenue, $totalVoid, $totalVoidValue,
 * $avgPrep, $minPrep, $maxPrep, $byStation, $topItems, $rows,
 * $currency_symbol, $site_name. Plus rh_fmt_dur(), rh_fmt_dt().
 */
// Defensive defaults — file may be loaded by static analyzers without parent scope.
$site_name        = $site_name        ?? 'Hotel';
$currency_symbol  = $currency_symbol  ?? '$';
$reqStation       = $reqStation       ?? 'all';
$reqDate          = $reqDate          ?? date('Y-m-d');
$STATION_OPTIONS  = $STATION_OPTIONS  ?? ['all'=>'All Stations','kitchen'=>'Kitchen','bar'=>'Bar','coffee_bar'=>'Coffee Bar'];
$STATION_LABEL    = $STATION_LABEL    ?? ['kitchen'=>'Kitchen','bar'=>'Bar','coffee_bar'=>'Coffee Bar'];
$totalItems       = $totalItems       ?? 0;
$totalQty         = $totalQty         ?? 0;
$totalRevenue     = $totalRevenue     ?? 0.0;
$totalVoid        = $totalVoid        ?? 0;
$totalVoidValue   = $totalVoidValue   ?? 0.0;
$avgPrep          = $avgPrep          ?? 0;
$minPrep          = $minPrep          ?? 0;
$maxPrep          = $maxPrep          ?? 0;
$byStation        = $byStation        ?? [];
$topItems         = $topItems         ?? [];
$rows             = $rows             ?? [];
?>
<div style="font-family:Arial,sans-serif; max-width:780px; margin:0 auto; color:#212529;">
    <h2 style="color:#8B7355; margin:0 0 6px;"><?php echo htmlspecialchars($site_name); ?> — Station Report</h2>
    <p style="margin:0 0 18px; color:#6c757d; font-size:14px;">
        <strong><?php echo htmlspecialchars($STATION_OPTIONS[$reqStation]); ?></strong>
        · <?php echo htmlspecialchars(date('l, d M Y', strtotime($reqDate))); ?>
    </p>

    <table style="width:100%; border-collapse:collapse; margin-bottom:18px;">
        <tr>
            <td style="padding:12px; background:#f8f9fa; border:1px solid #eaecef; border-radius:6px; width:33%;">
                <div style="font-size:11px; color:#6c757d; text-transform:uppercase;">Items served</div>
                <div style="font-size:22px; font-weight:600;"><?php echo number_format($totalItems); ?></div>
            </td>
            <td style="padding:12px; background:#f8f9fa; border:1px solid #eaecef; width:33%;">
                <div style="font-size:11px; color:#6c757d; text-transform:uppercase;">Revenue</div>
                <div style="font-size:22px; font-weight:600; color:#0c8d6c;"><?php echo $currency_symbol.' '.number_format($totalRevenue, 2); ?></div>
            </td>
            <td style="padding:12px; background:#f8f9fa; border:1px solid #eaecef; width:33%;">
                <div style="font-size:11px; color:#6c757d; text-transform:uppercase;">Avg prep</div>
                <div style="font-size:22px; font-weight:600;"><?php echo rh_fmt_dur($avgPrep); ?></div>
            </td>
        </tr>
    </table>

    <p style="font-size:13px; color:#6c757d;">
        Voided / cancelled: <strong style="color:#c82333;"><?php echo $totalVoid; ?></strong>
        (<?php echo $currency_symbol.' '.number_format($totalVoidValue, 2); ?>)
        · Min prep: <?php echo rh_fmt_dur($minPrep); ?>
        · Max prep: <?php echo rh_fmt_dur($maxPrep); ?>
    </p>

    <?php if ($reqStation === 'all' && $byStation): ?>
    <h3 style="font-size:15px; margin:18px 0 8px;">By station</h3>
    <table style="width:100%; border-collapse:collapse; font-size:13px;">
        <thead>
            <tr style="background:#f8f9fa;">
                <th align="left" style="padding:8px; border-bottom:2px solid #eaecef;">Station</th>
                <th align="right" style="padding:8px; border-bottom:2px solid #eaecef;">Items</th>
                <th align="right" style="padding:8px; border-bottom:2px solid #eaecef;">Revenue</th>
                <th align="right" style="padding:8px; border-bottom:2px solid #eaecef;">Avg prep</th>
                <th align="right" style="padding:8px; border-bottom:2px solid #eaecef;">Voids</th>
            </tr>
        </thead>
        <tbody>
        <?php foreach ($byStation as $s => $d): ?>
            <tr>
                <td style="padding:8px; border-bottom:1px solid #eaecef;"><?php echo htmlspecialchars($STATION_LABEL[$s] ?? ucfirst($s)); ?></td>
                <td align="right" style="padding:8px; border-bottom:1px solid #eaecef;"><?php echo number_format($d['items']); ?></td>
                <td align="right" style="padding:8px; border-bottom:1px solid #eaecef;"><?php echo $currency_symbol.' '.number_format($d['revenue'], 2); ?></td>
                <td align="right" style="padding:8px; border-bottom:1px solid #eaecef;"><?php echo rh_fmt_dur((int)$d['avg_seconds']); ?></td>
                <td align="right" style="padding:8px; border-bottom:1px solid #eaecef;"><?php echo $d['voids']; ?></td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
    <?php endif; ?>

    <?php if ($topItems): ?>
    <h3 style="font-size:15px; margin:18px 0 8px;">Top items by revenue</h3>
    <table style="width:100%; border-collapse:collapse; font-size:13px;">
        <thead>
            <tr style="background:#f8f9fa;">
                <th align="left" style="padding:8px; border-bottom:2px solid #eaecef;">Item</th>
                <th align="left" style="padding:8px; border-bottom:2px solid #eaecef;">Station</th>
                <th align="right" style="padding:8px; border-bottom:2px solid #eaecef;">Qty</th>
                <th align="right" style="padding:8px; border-bottom:2px solid #eaecef;">Revenue</th>
            </tr>
        </thead>
        <tbody>
        <?php foreach (array_slice($topItems, 0, 15, true) as $it): ?>
            <tr>
                <td style="padding:8px; border-bottom:1px solid #eaecef;"><?php echo htmlspecialchars($it['name']); ?></td>
                <td style="padding:8px; border-bottom:1px solid #eaecef;"><?php echo htmlspecialchars($STATION_LABEL[$it['station']] ?? '—'); ?></td>
                <td align="right" style="padding:8px; border-bottom:1px solid #eaecef;"><?php echo number_format((float)$it['qty'], 2); ?></td>
                <td align="right" style="padding:8px; border-bottom:1px solid #eaecef;"><?php echo $currency_symbol.' '.number_format((float)$it['revenue'], 2); ?></td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
    <?php endif; ?>

    <h3 style="font-size:15px; margin:18px 0 8px;">All tickets (<?php echo count($rows); ?>)</h3>
    <table style="width:100%; border-collapse:collapse; font-size:12px;">
        <thead>
            <tr style="background:#f8f9fa;">
                <th align="left" style="padding:6px; border-bottom:2px solid #eaecef;">Ref</th>
                <th align="left" style="padding:6px; border-bottom:2px solid #eaecef;">Item</th>
                <th align="right" style="padding:6px; border-bottom:2px solid #eaecef;">Qty</th>
                <th align="right" style="padding:6px; border-bottom:2px solid #eaecef;">Total</th>
                <th align="left" style="padding:6px; border-bottom:2px solid #eaecef;">Fired</th>
                <th align="left" style="padding:6px; border-bottom:2px solid #eaecef;">Served</th>
                <th align="right" style="padding:6px; border-bottom:2px solid #eaecef;">Prep</th>
            </tr>
        </thead>
        <tbody>
        <?php foreach (array_slice($rows, 0, 200) as $r):
            $prepSec = ($r['fired_at'] && $r['served_at']) ? max(0,(int)(strtotime($r['served_at'])-strtotime($r['fired_at']))) : 0;
        ?>
            <tr>
                <td style="padding:6px; border-bottom:1px solid #f1f3f5;"><?php echo htmlspecialchars($r['reference']); ?></td>
                <td style="padding:6px; border-bottom:1px solid #f1f3f5;"><?php echo htmlspecialchars($r['item_name']); ?></td>
                <td align="right" style="padding:6px; border-bottom:1px solid #f1f3f5;"><?php echo rtrim(rtrim(number_format((float)$r['quantity'], 2),'0'),'.'); ?></td>
                <td align="right" style="padding:6px; border-bottom:1px solid #f1f3f5;"><?php echo $currency_symbol.' '.number_format((float)$r['line_total'], 2); ?></td>
                <td style="padding:6px; border-bottom:1px solid #f1f3f5;"><?php echo rh_fmt_dt($r['fired_at']); ?></td>
                <td style="padding:6px; border-bottom:1px solid #f1f3f5;"><?php echo rh_fmt_dt($r['served_at']); ?></td>
                <td align="right" style="padding:6px; border-bottom:1px solid #f1f3f5; font-variant-numeric:tabular-nums;"><?php echo $prepSec ? rh_fmt_dur($prepSec) : '—'; ?></td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>

    <?php if (count($rows) > 200): ?>
    <p style="font-size:12px; color:#6c757d; margin-top:8px;">Showing first 200 of <?php echo count($rows); ?> rows. Open the dashboard to see all entries or download CSV.</p>
    <?php endif; ?>

    <p style="font-size:12px; color:#6c757d; margin-top:24px; padding-top:14px; border-top:1px solid #eaecef;">
        Auto-generated by <?php echo htmlspecialchars($site_name); ?> on <?php echo date('Y-m-d H:i'); ?>.
    </p>
</div>

