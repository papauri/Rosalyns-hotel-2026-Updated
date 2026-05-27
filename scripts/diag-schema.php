<?php
define('ROSALYNS_HOTEL_BOOTSTRAP', true);
require_once __DIR__ . '/../config/database.php';

global $pdo;

echo '=== Levy-related site_settings ===' . PHP_EOL;
$stmt = $pdo->query("SELECT setting_key, setting_value FROM site_settings WHERE setting_key LIKE '%levy%' OR setting_key LIKE '%tourism%'");
$rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
if ($rows) {
    foreach ($rows as $r) {
        echo $r['setting_key'] . ': ' . $r['setting_value'] . PHP_EOL;
    }
} else {
    echo 'No levy/tourism settings found' . PHP_EOL;
}

echo PHP_EOL . '=== payments table columns ===' . PHP_EOL;
$cols = $pdo->query('DESCRIBE payments')->fetchAll(PDO::FETCH_COLUMN);
echo implode(', ', $cols) . PHP_EOL;

echo PHP_EOL . '=== bookings table levy/vat columns ===' . PHP_EOL;
$bcols = $pdo->query('DESCRIBE bookings')->fetchAll(PDO::FETCH_COLUMN);
$vatLevy = array_filter($bcols, fn($c) => stripos($c, 'vat') !== false || stripos($c, 'levy') !== false || stripos($c, 'tax') !== false || stripos($c, 'total') !== false);
echo implode(', ', $vatLevy) . PHP_EOL;

echo PHP_EOL . '=== Sample payment vat_amount value ===' . PHP_EOL;
$samplePayment = $pdo->query('SELECT id, vat_amount, total_amount FROM payments ORDER BY id DESC LIMIT 3')->fetchAll(PDO::FETCH_ASSOC);
foreach ($samplePayment as $p) {
    echo 'id=' . $p['id'] . ' vat_amount=' . ($p['vat_amount'] ?? 'NULL') . ' total=' . ($p['total_amount'] ?? 'NULL') . PHP_EOL;
}
