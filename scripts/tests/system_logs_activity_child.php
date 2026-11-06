<?php
if (PHP_SAPI !== 'cli') { http_response_code(403); exit("CLI only.\n"); }
/**
 * Child of scripts/tests/system_logs_activity.php: plays one web request through the staff-activity hook
 * (admin/includes/activity-hook.php) in its own process, so the real shutdown function runs.
 * Usage: php system_logs_activity_child.php <base64 json {method,script,post,get,uid,status,refused,fatal}>
 * Rows are written with the marker address 203.0.113.77 so the parent can find and remove them.
 */
$root = dirname(__DIR__, 2);
chdir($root);
$cfg = json_decode(base64_decode($argv[1]), true);
define('RH_SA_HOOK_IN_CLI', true);
require $root . '/config/database.php';
require $root . '/includes/licence-guard.php';
require $root . '/admin/includes/activity-hook.php';
$_SESSION = ['admin_user_id' => (int)$cfg['uid'], 'admin_username' => 'zz_sa_test'];
$_SERVER['REQUEST_METHOD'] = $cfg['method'];
$_SERVER['SCRIPT_NAME'] = $cfg['script'];
$_SERVER['REMOTE_ADDR'] = '203.0.113.77';
$_POST = $cfg['post'] ?? [];
$_GET = $cfg['get'] ?? [];
if (!empty($cfg['slow'])) { register_shutdown_function(function () { sleep(3); }); } // like the web scheduler's job run after the answer
rh_sa_hook_register();
rh_sa_hook_register(); // a second call must not register twice
if (!empty($cfg['refused'])) { $GLOBALS['rh_sa_refused'] = true; }
if (!empty($cfg['status'])) { http_response_code((int)$cfg['status']); }
echo 'start=' . date('Y-m-d H:i:s') . "\n";
echo "done\n";
