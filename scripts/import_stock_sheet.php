<?php
/**
 * Rosalyn's stock sheet import (October 2026).
 *
 *   php scripts/import_stock_sheet.php --csv=sheet.csv --xlsx=<unpacked workbook dir> [--report=out.txt]            (dry run, default)
 *   php scripts/import_stock_sheet.php --csv=sheet.csv --xlsx=<dir> [--overrides=answers.csv] --apply [--user=ID]   (writes, one transaction)
 *
 * --csv        the sheet as CSV: department, section, name, batch, unit, balance, max_level, unit_price, expiry, sheet_row
 * --xlsx       the UNPACKED workbook folder (needs xl/worksheets/sheet2.xml and xl/sharedStrings.xml): the Bar sheet's
 *              purchase quantity is used to work out each bar item's crate size
 * --overrides  optional CSV the owner fills in for flagged items: name,pack_size,cost_per_unit
 *              (pack_size = bottles in a crate; cost_per_unit overrides the price / crate size sum)
 * --report     write the report here as well as printing it
 * --suggest    (dry run) write an overrides file of the flagged bar items with a suggested crate size, for the owner to check
 * --user       admin id recorded as the person who counted (default: first admin). Approval must be a DIFFERENT manager.
 *
 * Dry run is read-only (SELECTs only, no schema changes). --apply:
 *   - matches existing ingredients by case-insensitive trimmed name (updates category / par / reorder / cost / pack / notes),
 *     creates the missing ones; never deletes or archives anything; recipes are not touched;
 *   - creates ONE opening stock count, unit costs filled, left SUBMITTED for a second manager to approve
 *     (stock only appears when it is approved; expiry batches are split out at that moment);
 *   - sets the fixed stock budget lines (control "warn") for the month;
 *   - all inside one transaction; running it again changes nothing that is already done.
 */
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(403); exit("CLI only.\n"); }

chdir(dirname(__DIR__));
$_SERVER['REMOTE_ADDR'] = $_SERVER['REMOTE_ADDR'] ?? '127.0.0.1';

$opt = [];
foreach (array_slice($argv, 1) as $a) {
    if (preg_match('/^--([a-z-]+)(?:=(.*))?$/', $a, $m)) {
        $opt[$m[1]] = $m[2] ?? '1';
    } else {
        fwrite(STDERR, "Unknown argument: $a\n");
        exit(2);
    }
}
$apply = isset($opt['apply']);
if ($apply && isset($opt['dry-run'])) {
    fwrite(STDERR, "Use either --dry-run or --apply, not both.\n");
    exit(2);
}
if (empty($opt['csv'])) {
    fwrite(STDERR, "Usage: php scripts/import_stock_sheet.php --csv=sheet.csv --xlsx=<unpacked workbook dir> [--overrides=file.csv] [--report=out.txt] [--dry-run | --apply [--user=ID]]\n");
    exit(2);
}

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../admin/includes/procurement-schema.php';
require_once __DIR__ . '/../admin/includes/stock-budget-lib.php';
require_once __DIR__ . '/../admin/includes/stock-sheet-import-lib.php';

try {
    $rows = rh_ssi_load_csv($opt['csv']);
    $qty = [];
    if (!empty($opt['xlsx'])) {
        $qty = rh_ssi_load_purchase_qty($opt['xlsx']);
    } else {
        fwrite(STDERR, "Note: no --xlsx given, so no bar crate size can be worked out.\n");
    }
    $overrides = [];
    if (!empty($opt['overrides'])) {
        foreach (rh_ssi_load_csv($opt['overrides']) as $o) {
            $k = rh_ssi_key((string)($o['name'] ?? ''));
            if ($k !== '') {
                $overrides[$k] = ['pack_size' => rh_ssi_num($o['pack_size'] ?? ''), 'cost' => rh_ssi_num($o['cost_per_unit'] ?? '')];
            }
        }
    }

    if (!$apply) {
        $plan = rh_ssi_plan($pdo, $rows, $qty, $overrides);
        $text = rh_ssi_report($plan);
        echo $text;
        if (!empty($opt['report'])) {
            file_put_contents($opt['report'], $text);
            echo "Report saved to {$opt['report']}\n";
        }
        if (!empty($opt['suggest'])) {
            file_put_contents($opt['suggest'], rh_ssi_suggestions_csv($plan));
            echo "Suggested crate sizes saved to {$opt['suggest']}\n";
        }
        exit(0);
    }

    // ── APPLY ───────────────────────────────────────────────────────────────────────
    // Schema first (DDL commits on its own, so it must come before the transaction).
    ensureProcurementSchema($pdo);
    rh_sbud_ensure_schema($pdo);
    rh_ssi_ensure_table($pdo);
    $hasOpening = (int)$pdo->query("SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'stock_counts' AND COLUMN_NAME = 'is_opening'")->fetchColumn() > 0;
    if (!$hasOpening) {
        $pdo->exec("ALTER TABLE stock_counts ADD COLUMN is_opening TINYINT(1) NOT NULL DEFAULT 0");
    }
    $userId = isset($opt['user']) ? (int)$opt['user'] : (int)$pdo->query("SELECT id FROM admin_users WHERE is_active = 1 AND role = 'admin' ORDER BY id LIMIT 1")->fetchColumn();
    $chk = $pdo->prepare("SELECT username FROM admin_users WHERE id = ? AND is_active = 1");
    $chk->execute([$userId]);
    $who = $chk->fetchColumn();
    if (!$who) {
        throw new RuntimeException('No active admin user with id ' . $userId . ' (use --user=ID).');
    }

    $pdo->beginTransaction();
    try {
        $plan = rh_ssi_plan($pdo, $rows, $qty, $overrides);
        $res = rh_ssi_apply($pdo, $plan, $userId);
        $pdo->commit();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) { $pdo->rollBack(); }
        throw $e;
    }
    $text = rh_ssi_report($plan, 'APPLIED') . "\nRESULT\n"
        . '  Ingredients created: ' . $res['created'] . '   updated: ' . $res['updated'] . "\n"
        . '  Opening count: ' . ($res['count_ref'] ?? 'none created (nothing new to count)') . ' - ' . $res['count_lines'] . ' lines, ' . $res['batches'] . " expiry batches waiting, status SUBMITTED, counted by user '" . $who . "'.\n"
        . "  A DIFFERENT manager must approve it on Stock Count before any stock appears.\n"
        . '  Budget lines changed: ' . $res['budget_changed'] . "\n";
    echo $text;
    if (!empty($opt['report'])) {
        file_put_contents($opt['report'], $text);
    }
    exit(0);
} catch (Throwable $e) {
    fwrite(STDERR, 'Import failed: ' . $e->getMessage() . "\n");
    exit(1);
}
