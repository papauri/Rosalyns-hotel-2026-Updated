<?php

declare(strict_types=1);

// Compatibility route for legacy /login.php links.
$queryString = (string)($_SERVER['QUERY_STRING'] ?? '');
$target = 'admin/login.php';
if ($queryString !== '') {
    $target .= '?' . $queryString;
}

header('Location: ' . $target, true, 302);
exit;
