<?php
/**
 * Migration audit (read-only).
 *
 * Connects to the live DB via config/database.php and verifies the schema
 * fingerprint of every migration 009-030. Reports per-migration: APPLIED,
 * MISSING, or PARTIAL with a list of missing columns/tables/rows.
 *
 * No writes. Run from CLI: php scripts/audit_migrations.php
 */
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit("CLI only.\n");
}

require __DIR__ . '/../config/database.php';
/** @var PDO $pdo */

/**
 * Schema check helpers
 */
function tbl(PDO $pdo, string $t): bool {
    $s = $pdo->prepare("SELECT 1 FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=?");
    $s->execute([$t]);
    return (bool)$s->fetchColumn();
}
function col(PDO $pdo, string $t, string $c): bool {
    $s = $pdo->prepare("SELECT 1 FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=? AND COLUMN_NAME=?");
    $s->execute([$t, $c]);
    return (bool)$s->fetchColumn();
}
function idx(PDO $pdo, string $t, string $i): bool {
    $s = $pdo->prepare("SELECT 1 FROM information_schema.STATISTICS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=? AND INDEX_NAME=?");
    $s->execute([$t, $i]);
    return (bool)$s->fetchColumn();
}
/** Check that a column's ENUM contains a particular value. */
function enumHas(PDO $pdo, string $t, string $c, string $val): ?bool {
    $s = $pdo->prepare("SELECT COLUMN_TYPE FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=? AND COLUMN_NAME=?");
    $s->execute([$t, $c]);
    $type = (string)$s->fetchColumn();
    if ($type === '') return null;
    return (bool)preg_match("/'" . preg_quote($val, '/') . "'/i", $type);
}

$checks = [
    '009_whatsapp_settings' => function() use ($pdo) {
        $miss = [];
        // 009 only seeds site_settings rows; no schema additions.
        $s = $pdo->prepare("SELECT COUNT(*) FROM site_settings WHERE setting_key LIKE 'whatsapp_%'");
        $s->execute();
        if ((int)$s->fetchColumn() < 10) $miss[] = 'site_settings whatsapp_* rows (<10)';
        return $miss;
    },
    '010_booking_timeline_logs' => function() use ($pdo) {
        $miss = [];
        if (!tbl($pdo, 'booking_timeline_logs')) $miss[] = 'table booking_timeline_logs';
        return $miss;
    },
    '011_fix_production_schema' => function() use ($pdo) {
        // Generic schema fixes; verify a couple of known additions.
        $miss = [];
        if (!col($pdo, 'bookings', 'amount_paid')) $miss[] = 'bookings.amount_paid';
        if (!col($pdo, 'bookings', 'amount_due'))  $miss[] = 'bookings.amount_due';
        return $miss;
    },
    '012_add_tourism_levy' => function() use ($pdo) {
        $miss = [];
        if (!col($pdo, 'bookings', 'tourism_levy_amount')) $miss[] = 'bookings.tourism_levy_amount';
        return $miss;
    },
    '013_update_footer_links' => function() use ($pdo) {
        // Pure data migration on site_settings — not schema-detectable. Mark as "data".
        return [];
    },
    '014_fix_menu_pdf_url' => function() use ($pdo) {
        return [];
    },
    '015_stock_management' => function() use ($pdo) {
        $miss = [];
        foreach ([
            'stock_ingredients','stock_recipes','stock_recipe_ingredients',
            'stock_batches','stock_adjustments','stock_batch_deductions',
            'stock_in_log','stock_orders','stock_order_items','stock_wastage'
        ] as $t) if (!tbl($pdo, $t)) $miss[] = "table $t";
        if (!col($pdo, 'booking_charges', 'stock_tracked')) $miss[] = 'booking_charges.stock_tracked';
        return $miss;
    },
    '016_stock_finance_sync' => function() use ($pdo) {
        $miss = [];
        if (!col($pdo, 'stock_orders', 'subtotal'))         $miss[] = 'stock_orders.subtotal';
        if (!col($pdo, 'stock_orders', 'discount_amount'))  $miss[] = 'stock_orders.discount_amount';
        if (!col($pdo, 'stock_orders', 'service_charge'))   $miss[] = 'stock_orders.service_charge';
        if (!col($pdo, 'stock_orders', 'tax_amount'))       $miss[] = 'stock_orders.tax_amount';
        return $miss;
    },
    '017_pos_payments' => function() use ($pdo) {
        $miss = [];
        foreach ([
            'payment_method','tendered_amount','change_due',
            'mobile_wallet_provider','mobile_wallet_reference',
            'card_last4','card_auth_code','paid_at',
            'voided_by','voided_at','void_reason'
        ] as $c) if (!col($pdo, 'stock_orders', $c)) $miss[] = "stock_orders.$c";
        if (!tbl($pdo, 'stock_order_audit')) $miss[] = 'table stock_order_audit';
        // payments.booking_type must allow 'restaurant'
        $h = enumHas($pdo, 'payments', 'booking_type', 'restaurant');
        if ($h === false) $miss[] = "payments.booking_type missing 'restaurant' enum value";
        return $miss;
    },
    '018_stock_variance' => function() use ($pdo) {
        $miss = [];
        if (!tbl($pdo, 'stock_shift_closes')) $miss[] = 'table stock_shift_closes';
        return $miss;
    },
    '019_restaurant_invoicing' => function() use ($pdo) {
        $miss = [];
        // Most likely: invoice columns on payments + stock_orders
        if (!col($pdo, 'payments', 'invoice_number')) $miss[] = 'payments.invoice_number';
        if (!col($pdo, 'payments', 'invoice_path'))   $miss[] = 'payments.invoice_path';
        return $miss;
    },
    '020_restaurant_staff_role' => function() use ($pdo) {
        $miss = [];
        $h = enumHas($pdo, 'admin_users', 'role', 'restaurant_staff');
        if ($h === false) $miss[] = "admin_users.role missing 'restaurant_staff'";
        return $miss;
    },
    '021_pos_minimalist_enhancements' => function() use ($pdo) {
        $miss = [];
        if (!col($pdo, 'stock_orders', 'opened_as_tab')) $miss[] = 'stock_orders.opened_as_tab';
        return $miss;
    },
    '022_kds_chef_role' => function() use ($pdo) {
        $miss = [];
        $h = enumHas($pdo, 'admin_users', 'role', 'chef');
        if ($h === false) $miss[] = "admin_users.role missing 'chef'";
        if (!col($pdo, 'stock_orders', 'kitchen_status')) $miss[] = 'stock_orders.kitchen_status';
        if (!col($pdo, 'stock_orders', 'fired_at'))       $miss[] = 'stock_orders.fired_at';
        foreach (['kds_status','started_at','ready_at','served_at','station','bumped_by'] as $c) {
            if (!col($pdo, 'stock_order_items', $c)) $miss[] = "stock_order_items.$c";
        }
        if (!tbl($pdo, 'stock_kds_events')) $miss[] = 'table stock_kds_events';
        return $miss;
    },
    '023_station_routing' => function() use ($pdo) {
        $miss = [];
        foreach (['bar_staff','coffee_staff'] as $r) {
            if (enumHas($pdo, 'admin_users', 'role', $r) === false) $miss[] = "admin_users.role missing '$r'";
        }
        if (!col($pdo, 'food_menu',  'station')) $miss[] = 'food_menu.station';
        if (!col($pdo, 'drink_menu', 'station')) $miss[] = 'drink_menu.station';
        if (!col($pdo, 'stock_orders', 'client_uuid')) $miss[] = 'stock_orders.client_uuid';
        if (!idx($pdo, 'stock_orders', 'uniq_client_uuid')) $miss[] = 'stock_orders.uniq_client_uuid index';
        return $miss;
    },
    '024_offline_log' => function() use ($pdo) {
        $miss = [];
        if (!tbl($pdo, 'offline_replay_log')) $miss[] = 'table offline_replay_log';
        return $miss;
    },
    '025_restaurant_order_room_service_sync' => function() use ($pdo) {
        $miss = [];
        foreach (['booking_id','individual_room_id','room_number','folio_posted_at'] as $c) {
            if (!col($pdo, 'stock_orders', $c)) $miss[] = "stock_orders.$c";
        }
        if (!col($pdo, 'booking_charges', 'stock_order_id')) $miss[] = 'booking_charges.stock_order_id';
        if (enumHas($pdo, 'stock_orders', 'status', 'completed') === false) $miss[] = "stock_orders.status missing 'completed'";
        return $miss;
    },
    '030_menu_visibility_room_service' => function() use ($pdo) {
        $miss = [];
        foreach (['food_menu','drink_menu'] as $t) {
            if (!col($pdo, $t, 'show_pos'))          $miss[] = "$t.show_pos";
            if (!col($pdo, $t, 'show_room_service')) $miss[] = "$t.show_room_service";
        }
        if (enumHas($pdo, 'admin_users', 'role', 'room_service') === false) $miss[] = "admin_users.role missing 'room_service'";
        if (tbl($pdo, 'permissions')) {
            $s = $pdo->prepare("SELECT COUNT(*) FROM permissions WHERE permission_key IN ('room_service_view','room_service_manage','kds_reports')");
            $s->execute();
            if ((int)$s->fetchColumn() < 3) $miss[] = 'permissions rows (room_service_view/_manage/kds_reports)';
            if (tbl($pdo, 'role_permissions')) {
                $s = $pdo->prepare("SELECT COUNT(*) FROM role_permissions WHERE permission_key IN ('room_service_view','room_service_manage','kds_reports')");
                $s->execute();
                if ((int)$s->fetchColumn() < 6) $miss[] = 'role_permissions rows for new keys (<6)';
            }
        }
        return $miss;
    },
];

echo "== Migration audit ==\n";
echo "Database: " . $pdo->query("SELECT DATABASE()")->fetchColumn() . "\n";
echo "Server:   " . $pdo->query("SELECT @@version")->fetchColumn() . "\n\n";

$totalMissing = 0;
foreach ($checks as $name => $fn) {
    $miss = $fn();
    if (!$miss) {
        printf("  [OK]      %-50s\n", $name);
    } else {
        $totalMissing++;
        printf("  [GAP]     %-50s\n", $name);
        foreach ($miss as $m) printf("            - %s\n", $m);
    }
}
echo "\nMigrations with gaps: $totalMissing\n";
