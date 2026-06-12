<?php
// Backward-compatible route alias for older links/bookmarks.
$queryString = isset($_SERVER['QUERY_STRING']) && $_SERVER['QUERY_STRING'] !== ''
    ? ('?' . $_SERVER['QUERY_STRING'])
    : '';

header('Location: gym-management.php' . $queryString, true, 302);
exit;

