<?php
if (PHP_SAPI !== 'cli') { http_response_code(403); exit("CLI only.\n"); } // never runnable over the web
/**
 * Bookings list query plans (plan .claude/BOOKINGS_LIST_PLAN.md T2). READ-ONLY (EXPLAIN only, nothing runs or writes).
 * Liwonde (the test platform) only: refuses another repo, a DB listed in .claude/LIVE_TARGETS, or a site not named Liwonde.
 * Prints the plan of the list query with the Source filter, the Made-by filter and the Balance due sort, plus the
 * indexes on booking_audit_log(booking_id, action) / booking_timeline_logs(booking_id, action_type) /
 * booking_charges(booking_id), then "no full scan" or the migration 092 proposal (index only).
 *   php scripts/tests/bookings_list_explain.php
 */
$root = dirname(__DIR__, 2);
chdir($root);
if (stripos($root, 'Liwonde') === false) { fwrite(STDERR, "Refused: runs in the Liwonde repo only.\n"); exit(1); }
require $root . '/config/database.php';
$db = (string)$pdo->query('SELECT DATABASE()')->fetchColumn();
$live = (string)@file_get_contents($root . '/.claude/LIVE_TARGETS');
if ($db === '' || stripos($live, $db) !== false) { fwrite(STDERR, "Refused: $db is a live target.\n"); exit(1); }
if (stripos((string)getSetting('site_name', ''), 'liwonde') === false) { fwrite(STDERR, "Refused: site is not Liwonde.\n"); exit(1); }
require_once $root . '/admin/includes/bookings-list-filters.php';

$hasCombos = bookingAvailabilityTableExists('room_combinations');
$creator = (int)$pdo->query("SELECT performed_by FROM booking_audit_log WHERE action = 'created' AND performed_by > 0 ORDER BY id LIMIT 1")->fetchColumn();
$cases = [
    'source = online' => [['source' => 'online'], null],
    'made by staff #' . $creator => [['created_by' => (string)$creator], null],
    'sort balance desc' => [[], rh_bk_balance_due_sql('b') . ' DESC'],
    'source + made by + sort balance' => [['source' => 'staff', 'created_by' => (string)$creator], rh_bk_balance_due_sql('b') . ' ASC'],
];
// Tables reached through a booking id: a full scan of any of them (type ALL) runs once per booking row.
$perBooking = ['bkau' => 'booking_audit_log', 'bktl' => 'booking_timeline_logs', 'bkbdc' => 'booking_charges'];
$scans = [];
foreach ($cases as $label => [$get, $order]) {
    $f = rh_bk_filters_from_get($get);
    $scope = rh_bk_list_scope($f, $hasCombos);
    $where = $scope['clauses'] ? 'WHERE ' . implode(' AND ', $scope['clauses']) : '';
    $orderBy = ($order ?? rh_bk_default_order($f['tab'], $f['status'])) . ', b.id DESC';
    $sql = "EXPLAIN SELECT b.id FROM bookings b LEFT JOIN rooms r ON b.room_id = r.id
        LEFT JOIN individual_rooms ir ON b.individual_room_id = ir.id {$where} ORDER BY {$orderBy} LIMIT 10";
    $st = $pdo->prepare($sql);
    $st->execute($scope['params']);
    echo "== $label\n";
    foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) {
        printf("  %-20s %-8s type=%-6s key=%-32s rows=%s\n", (string)$r['select_type'], (string)$r['table'], (string)$r['type'], (string)($r['key'] ?? '-'), (string)$r['rows']);
        if (isset($perBooking[$r['table']]) && strtoupper((string)$r['type']) === 'ALL') {
            $scans[$perBooking[$r['table']]] = true;
        }
    }
}
echo "== indexes\n";
foreach (['booking_audit_log' => ['booking_id', 'action'], 'booking_timeline_logs' => ['booking_id', 'action_type'], 'booking_charges' => ['booking_id']] as $t => $cols) {
    $idx = [];
    foreach ($pdo->query("SHOW INDEX FROM {$t}")->fetchAll(PDO::FETCH_ASSOC) as $i) {
        $idx[$i['Key_name']][(int)$i['Seq_in_index']] = $i['Column_name'];
    }
    $named = [];
    foreach ($idx as $k => $c) {
        ksort($c);
        if (array_intersect($c, $cols)) { $named[] = $k . '(' . implode(', ', $c) . ')'; }
    }
    echo "  $t: " . ($named ? implode(', ', $named) : 'none on ' . implode(', ', $cols)) . "\n";
}
if (!$scans) {
    echo "RESULT: no full scan of booking_audit_log / booking_timeline_logs / booking_charges (each is reached by its booking_id index). No migration needed.\n";
    exit(0);
}
echo 'RESULT: full scan of ' . implode(', ', array_keys($scans)) . " per booking row. Proposal: migration 092_booking_log_indexes.php"
    . " (index only): booking_audit_log(booking_id, action, id), booking_timeline_logs(booking_id, action_type, id).\n";
exit(2);
