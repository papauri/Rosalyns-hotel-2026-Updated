<?php
require_once __DIR__ . '/../config/database.php';
$cols = $pdo->query('SHOW COLUMNS FROM bookings')->fetchAll(PDO::FETCH_ASSOC);
foreach ($cols as $c) {
    echo $c['Field'] . ' (' . $c['Type'] . ")\n";
}
$cols2 = $pdo->query('SHOW COLUMNS FROM individual_rooms')->fetchAll(PDO::FETCH_ASSOC);
echo "\n--- individual_rooms ---\n";
foreach ($cols2 as $c) {
    echo $c['Field'] . ' (' . $c['Type'] . ")\n";
}
