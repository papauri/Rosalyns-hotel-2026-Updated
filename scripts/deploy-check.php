<?php
if (PHP_SAPI !== 'cli') { http_response_code(403); echo "CLI only.\n"; exit(1); } // never over the web; exit 1 (never exit("text"), which exits 0) so `&&` never runs the update
// "Update from Remote" asked for on the licence panel's Deployments page (contract: msp-control-panel
// .claude/DEPLOY_HOTEL_SPEC.md). No cPanel credentials anywhere: the account's own cron runs the pull with git itself.
// cPanel > Cron Jobs, every 5 minutes, one line (USER, SITE and the branch cPanel has checked out):
//
//   */5 * * * * cd /home/USER/SITE && /usr/local/bin/php scripts/deploy-check.php --want && { GIT_TERMINAL_PROMPT=0 /usr/bin/git pull --ff-only origin main; echo "exit=$?"; } > storage/deploy-last.json 2>&1; /usr/local/bin/php scripts/deploy-check.php --report storage/deploy-last.json
//
// Why git and not cPanel's uapi: cron runs inside CageFS on this host, where uapi fails ("Failed to execute
// /usr/local/cpanel/cpanel"). The site runs straight from the repository folder (no .cpanel.yml), so a fast-forward-only
// pull of the branch already checked out IS cPanel's Update from Remote. The remote is public HTTPS: no credentials, and
// GIT_TERMINAL_PROMPT=0 makes git fail instead of waiting for a password. Local changes on the server stop the pull.
// Why /usr/local/bin/php: cron's plain `php` may be php-cgi (not the command line); this script then refuses (exit 1), so
// nothing is pulled and nothing recorded. Use the command-line PHP path your host gives.
//
//   --want         exit 0 when the last VERIFIED licence answer (under 2 hours old) carries a request (under 24 hours old)
//                  newer than the one picked up and the one finished here; marks it picked up. Otherwise exit 1, so the
//                  git pull runs only when asked (always name the branch already checked out; never another branch).
//   --report FILE  always runs (after the `;`): when a request was picked up, records the result in FILE (git's output and
//                  "exit=N", written after the pick-up; an older or missing file is ignored, and 15 minutes without one is
//                  recorded as a failure), removes FILE, then checks in with the panel so it hears now. The live commit
//                  (read from .git) goes with every check-in.
// storage/ is refused by the web server (.htaccess there and RewriteRule in the root .htaccess) and is not in git.
// Exit codes: --want 0 = run the update, 1 = nothing to do (or a fault); --report 0 = fine, 1 = a fault.

$GLOBALS['rh_deploy_exit_set'] = false;
// config/database.php ends with die("text") (exit status 0) when .env is unusable: any end that is not one of ours exits 1,
// so cron's `&&` never runs the update without an explicit yes from --want.
register_shutdown_function(static function (): void {
    if (empty($GLOBALS['rh_deploy_exit_set'])) {
        exit(1);
    }
});

function rh_deploy_exit(int $code): void
{
    $GLOBALS['rh_deploy_exit_set'] = true;
    exit($code);
}

$mode = (string)($argv[1] ?? '');
$file = (string)($argv[2] ?? '');
if ($mode !== '--want' && $mode !== '--report') {
    fwrite(STDERR, "Usage: php scripts/deploy-check.php --want | --report FILE\n");
    rh_deploy_exit(1);
}

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/licence.php';

$pdo = $GLOBALS['pdo'] ?? null;
if (!rh_licence_enabled() || !($pdo instanceof PDO)) {
    rh_deploy_exit($mode === '--want' ? 1 : 0); // licensing off here: never asked, nothing to report
}

$lock = rh_licence_lock_name($pdo, 'rh_licence_deploy');
$locked = false;
$code = 1;
$recorded = null;
try {
    // One step at a time (an overlapping cron run): --want never waits, --report waits a few seconds.
    $locked = (int)$pdo->query('SELECT GET_LOCK(' . $pdo->quote($lock) . ', ' . ($mode === '--want' ? 0 : 5) . ')')->fetchColumn() === 1;
    $now = time();
    if (!$locked) {
        $code = $mode === '--want' ? 1 : 0; // the other run handles it
    } elseif ($mode === '--want') {
        $st = rh_licence_status(true); // re-verifies the stored token (signature, tenant, domain)
        $payload = is_array($st['payload'] ?? null) && ($st['reason'] ?? '') === 'ok' ? $st['payload'] : null;
        $s = rh_licence_settings_fresh($pdo, rh_licence_deploy_keys());
        $id = rh_licence_deploy_want($payload, (int)$s['licence_deploy_seen_id'], (int)$s['licence_deploy_done_id'], $now,
            (int)$s['licence_deploy_seen_at']); // never a second pull while one is still running
        if ($id > 0) {
            if (rh_licence_store_write($pdo, 'deploy_seen_at', (string)$now) && rh_licence_store_write($pdo, 'deploy_seen_id', (string)$id)) {
                $dir = dirname(__DIR__) . '/storage'; // the cron line writes its result there (made at first sign-in; made here if not yet)
                if (!is_dir($dir)) {
                    @mkdir($dir, 0700, true);
                }
                echo date('Y-m-d H:i:s') . ' deploy: request ' . $id . " picked up, running Update from Remote\n";
                $code = 0;
            } else {
                fwrite(STDERR, "deploy-check: the pick-up could not be saved, so the update does not run.\n");
            }
        }
    } else {
        $s = rh_licence_settings_fresh($pdo, rh_licence_deploy_keys());
        $seen = (int)$s['licence_deploy_seen_id'];
        clearstatcache();
        $has = $file !== '' && is_file($file);
        $mtime = $has ? (int)@filemtime($file) : null;
        $size = $has ? (int)@filesize($file) : 0;
        $complete = $size > 0 && rh_licence_deploy_file_complete((string)@file_get_contents($file, false, null, max(0, $size - 512), 512));
        $d = rh_licence_deploy_report_decide($seen, (int)$s['licence_deploy_done_id'], (int)$s['licence_deploy_seen_at'], $mtime, $size, $now,
            $complete); // a pull still running (no end yet) is waited for, not recorded as failed
        $code = 0;
        if ($d !== 'none') {
            $res = $d === 'result'
                ? rh_licence_deploy_uapi_result((string)@file_get_contents($file, false, null, 0, 1048576))
                : ['ok' => false, 'msg' => 'Picked up, but the update command left no result. Check the cron line (scripts/deploy-check.php).'];
            $pdo->beginTransaction();
            $ok = rh_licence_store_write($pdo, 'deploy_ok', $res['ok'] ? '1' : '0')
                && rh_licence_store_write($pdo, 'deploy_msg', rh_licence_deploy_scrub($res['msg']))
                && rh_licence_store_write($pdo, 'deploy_done_id', (string)$seen); // last: a failed write leaves it to retry
            if ($ok) {
                $pdo->commit();
                if ($d === 'result') {
                    @unlink($file);
                }
                $recorded = $res;
                echo date('Y-m-d H:i:s') . ' deploy: request ' . $seen . ' ' . ($res['ok'] ? 'done' : 'FAILED') . ': ' . rh_licence_deploy_scrub($res['msg']) . "\n";
            } else {
                $pdo->rollBack();
                fwrite(STDERR, "deploy-check: the result could not be saved (tried again on the next run).\n");
                $code = 1;
            }
        }
    }
} catch (Throwable $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    fwrite(STDERR, 'deploy-check: ' . get_class($e) . ': ' . rh_licence_deploy_scrub($e->getMessage(), 240) . "\n");
    $code = 1;
}
if ($locked) {
    try { $pdo->query('SELECT RELEASE_LOCK(' . $pdo->quote($lock) . ')')->fetchColumn(); } catch (Throwable $e) { /* released at disconnect */ }
}

// The panel hears the result now (it also goes with every later check-in). Short timeout: cron never hangs on it.
if ($recorded !== null) {
    $r = rh_licence_check_now(false, 5);
    echo date('Y-m-d H:i:s') . ' deploy: check-in ' . ($r['ok'] ? 'sent' : 'not sent (' . rh_licence_deploy_scrub((string)$r['error'], 200) . '); it goes with the next one') . "\n";
}
rh_deploy_exit($code);
