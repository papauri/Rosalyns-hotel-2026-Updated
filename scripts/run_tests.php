<?php
if (PHP_SAPI !== 'cli') { http_response_code(403); exit("CLI only.\n"); } // never runnable over the web

/**
 * Run every test suite. Usage (from the project root): php scripts/run_tests.php
 * All suites roll back their writes (smoke tests clean up after themselves).
 */
$root = dirname(__DIR__);
chdir($root);
$suites = [
    'Booking smoke'         => 'scripts/smoke_test_booking.php',
    'Finance smoke'         => 'scripts/smoke_test_finance.php',
    'POS/KDS smoke'         => 'scripts/smoke_test_pos_kds.php',
    'Settings pages'        => 'scripts/tests/settings_pages.php',
    'Hotel details section' => 'scripts/tests/hotel_details.php',
    'User timezone display' => 'scripts/tests/user_timezone.php',
    'Rooms linked data'     => 'scripts/tests/linked_rooms.php',
    'Guest history (one rule; no DB here, --db at Liwonde)' => 'scripts/tests/guest_history.php',
    'Group booking pricing' => 'scripts/tests/group_booking.php',
    'Room guest name'       => 'scripts/tests/room_guest_name.php',
    'Occupancy tier floor'  => 'scripts/tests/occupancy_tier.php',
    'Child charge rules'    => 'scripts/tests/child_pricing.php',
    'Booking full total'    => 'scripts/tests/booking_full_total.php',
    'Booking total breakdown' => 'scripts/tests/booking_total_breakdown.php',
    'Rate plan surcharge display' => 'scripts/tests/rate_plan_surcharge_display.php',
    'Rate plan rules (RP-3)' => 'scripts/tests/rate_plan_rules_rp3.php',
    'Conference full total' => 'scripts/tests/conference_full_total.php',
    'Concurrent bookings'   => 'scripts/tests/concurrent_booking.php',
    'In-house moves + splits' => 'scripts/tests/inhouse_move.php',
    'Money linked data'     => 'scripts/tests/linked_money.php',
    'Refund VAT + POS refunds' => 'scripts/tests/refund_vat_pos.php',
    'Levy after refunds'    => 'scripts/tests/levy_after_refunds.php',
    'Document number prefix' => 'scripts/tests/document_number_prefix_test.php',
    'Assistant document numbers' => 'scripts/tests/ai_doc_numbers_test.php',
    'Gym linked data'       => 'scripts/tests/linked_gym.php',
    'Block Rooms permission' => 'scripts/tests/block_rooms.php',
    'API payment delete'    => 'scripts/tests/api_payment_delete.php',
    'Stock budget'          => 'scripts/tests/stock_budget.php',
    'Stock packs'           => 'scripts/tests/stock_packs.php',
    'Data import engine'    => 'scripts/tests/import_engine_test.php',
    'Data import stock'     => 'scripts/tests/import_stock_test.php',
    'Data import export'    => 'scripts/tests/import_export_test.php',
    'Menu portions + search' => 'scripts/tests/menu_portions.php',
    'Stock receiving'       => 'scripts/tests/stock_receiving.php',
    'Stock count approval'  => 'scripts/tests/stock_count_approval.php',
    'Stock count big form'  => 'scripts/tests/stock_count_bigform.php',
    'Stock count race'      => 'scripts/tests/stock_count_race.php',
    'Stock count delete'    => 'scripts/tests/stock_count_delete.php',
    'Stock count partial opening' => 'scripts/tests/stock_count_partial_opening.php',
    'PO big forms'          => 'scripts/tests/po_bigform.php',
    'Stock sheet import'    => 'scripts/tests/stock_sheet_import.php',
    'Documents flow'        => 'scripts/tests/documents_flow.php',
    'Private document link' => 'scripts/tests/doc_link.php',
    'Security follow-ups (salt, invoice download, void gate)' => 'scripts/tests/security_followups.php',
    'Cache logic'           => 'scripts/tests/cache_logic.php',
    'Error log clear'       => 'scripts/tests/error_log_clear.php',
    'Staff presence'        => 'scripts/tests/admin_presence.php',
    'Login by email'        => 'scripts/tests/login_email.php',
    'Permission tiers (super admin / admin / staff)' => 'scripts/tests/permission_tiers.php',
    'Dashboard money figures (per-card permissions)' => 'scripts/tests/dashboard_figure_perms.php',
    'Dashboard Needs attention (gates, links, business types; no DB)' => 'scripts/tests/dashboard_attention.php',
    'End of Day "The day in brief" (optional Assistant summary; no DB, no model call)' => 'scripts/tests/ai_eod_narrative.php',
    'Station report emails (timer, recipients, permission)' => 'scripts/tests/station_reports_job.php',
    'Licence client (switch-off for non-payment)' => 'scripts/tests/licence_client.php',
    'System logs summary'   => 'scripts/tests/system_logs.php',
    'System logs staff activity (timeline, summary, hook)' => 'scripts/tests/system_logs_activity.php',
    'Payment limits'        => 'scripts/tests/payment_limits.php',
    'Booked rate line'      => 'scripts/tests/booked_rate_line_test.php',
    'Rehearsal fixes'       => 'scripts/tests/rehearsal_fixes.php',
    'Shop returns + wording' => 'scripts/tests/shop_returns.php',
    'Stock loss prevention' => 'scripts/tests/stock_loss.php',
    'Stock late costing'    => 'scripts/tests/backcost.php',
    'Help map (? button)'   => 'scripts/tests/help_map.php',
    'Website content (CMS)' => 'scripts/tests/cms_content.php',
    'Linked data health'    => 'scripts/data-health.php',
];
$failed = 0;
foreach ($suites as $name => $file) {
    $out = [];
    exec(escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($file) . ' 2>&1', $out, $code);
    $text = implode("\n", $out);
    $pass = preg_match_all('/^PASS\b/m', $text);
    $fail = preg_match_all('/^FAIL\b/m', $text);
    if (preg_match('/(\d+) passed, (\d+) failed/', $text, $m)) {
        [$pass, $fail] = [(int)$m[1], (int)$m[2]];
    } elseif (preg_match('/PASS=(\d+) FAIL=(\d+)/', $text, $m)) {
        [$pass, $fail] = [(int)$m[1], (int)$m[2]];
    }
    $ok = $code === 0 && $fail === 0 && $pass > 0;
    $failed += $ok ? 0 : 1;
    printf("%-24s %s  (%d passed, %d failed)\n", $name, $ok ? 'OK  ' : 'FAIL', $pass, $fail);
    if (!$ok) {
        foreach (array_slice(array_filter($out, fn($l) => preg_match('/FAIL|Fatal|Error/', $l)), 0, 5) as $l) {
            echo "    $l\n";
        }
    }
}
echo $failed ? "\n$failed suite(s) failed.\n" : "\nAll suites passed.\n";
exit($failed ? 1 : 0);
