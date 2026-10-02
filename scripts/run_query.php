<?php
if (PHP_SAPI !== 'cli') { http_response_code(403); exit("CLI only.\n"); } // never runnable over the web
require 'config/database.php';
$sql = $argv[1] ?? 'SELECT 1';
$stmt = $pdo->query($sql);
if ($stmt) {
    while ($r = $stmt->fetch(PDO::FETCH_ASSOC)) {
        foreach ($r as $k => $v) echo "$k=$v  ";
        echo "\n";
    }
}
