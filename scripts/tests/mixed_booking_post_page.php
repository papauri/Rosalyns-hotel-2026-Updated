<?php
if (PHP_SAPI !== 'cli') { http_response_code(403); exit("CLI only.\n"); }
/**
 * Run ONLY by scripts/tests/page_harness.php (via scripts/tests/mixed_room_booking.php): seeds the public booking
 * CSRF token the harness cannot know, then runs the real booking.php POST inside the harness's never-committing
 * transaction. Nothing it writes survives.
 */
$root = dirname(__DIR__, 2);
require_once $root . '/includes/session-boot.php';
rh_session_start();
$_SESSION['_csrf_pub_booking'] = (string)($_POST['csrf_token'] ?? '');
foreach (glob(sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'iprl_booking_form_*.json') ?: [] as $f) { @unlink($f); } // the 10-per-10-minutes limit must not stop repeated test runs
chdir($root);
require $root . '/booking.php';
