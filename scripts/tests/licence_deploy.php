<?php
if (PHP_SAPI !== 'cli') { http_response_code(403); echo "CLI only.\n"; exit(1); } // never runnable over the web
/**
 * Update from Remote, hotel side (panel Deployments, 2026-11-23; contract msp-control-panel .claude/DEPLOY_HOTEL_SPEC.md).
 * Pure: no database, no network, no command run (the .git fixtures are made in the temp folder and removed).
 * Usage (from the repo root): php scripts/tests/licence_deploy.php
 *  1. --want decisions: new / seen / done / stale answer / lapsed request, the exact edges, missing vs null vs malformed;
 *  2. --report decisions (file after / before the pick-up, empty, 15 minutes), the git pull result (exit=N: up to date,
 *     fast-forward, local changes, diverged, network, no exit line), the older uapi answer (ok, failed, noise, empty), scrub;
 *  3. .git reading: loose, packed, detached, gitdir file, ".." refused, nothing readable;
 *  4. the `deploy` block shape (as the panel cleans it) and the 4096-byte body limit;
 *  5. the files: the CLI guard exits 1, no command is ever run from PHP, the result is stored only from a verified answer.
 */
$root = dirname(__DIR__, 2);
chdir($root);
date_default_timezone_set('Africa/Blantyre');
$pass = 0; $fail = 0;
function check(bool $c, string $l, string $d = ''): void { global $pass, $fail; if ($c) { $pass++; } else { $fail++; echo "FAIL $l" . ($d !== '' ? ": $d" : '') . "\n"; } }
function eq($want, $got, string $l): void { check($want === $got, $l, 'expected ' . var_export($want, true) . ' got ' . var_export($got, true)); }

require $root . '/includes/licence.php';

$N = 1_800_000_000;
$p = static function (array $over = []) use ($N): array {
    return array_merge(['issued_at' => $N - 60, 'deploy_requested' => ['id' => 1_799_990_000, 'at' => $N - 600]], $over);
};
$ID = 1_799_990_000;

// ---- 1. --want
eq($ID, rh_licence_deploy_want($p(), 0, 0, $N), 'want: new request picked up');
eq(0, rh_licence_deploy_want($p(), $ID, 0, $N), 'want: already picked up (id = seen)');
eq(0, rh_licence_deploy_want($p(), 0, $ID, $N), 'want: already done (id = done)');
eq(0, rh_licence_deploy_want($p(), $ID + 5, 0, $N), 'want: a newer one was picked up');
// one in flight (review 2026-11-23): an older pick-up with no result yet blocks a newer request until it reports or lapses
eq(0, rh_licence_deploy_want($p(), $ID - 1, 0, $N, $N - 300), 'want: in flight (picked up 5 min ago, no result), newer id waits');
eq(0, rh_licence_deploy_want($p(), $ID - 1, 0, $N, $N - 899), 'want: in flight 1 second under 15 minutes, still waits');
eq($ID, rh_licence_deploy_want($p(), $ID - 1, 0, $N, $N - 900), 'want: 15 minutes without a result, the newer request goes');
eq($ID, rh_licence_deploy_want($p(), $ID - 1, $ID - 1, $N, $N - 60), 'want: the earlier one finished, the newer request goes at once');
check(strpos((string)file_get_contents(dirname(__DIR__) . '/deploy-check.php'), "(int)\$s['licence_deploy_seen_at']); // never a second pull") !== false,
    'deploy-check.php --want passes the pick-up time');
eq($ID, rh_licence_deploy_want($p(), $ID - 1, $ID - 1, $N), 'want: one newer than seen and done');
eq(0, rh_licence_deploy_want($p(['issued_at' => $N - 7200]), 0, 0, $N), 'want: answer exactly 2 hours old is stale');
eq($ID, rh_licence_deploy_want($p(['issued_at' => $N - 7199]), 0, 0, $N), 'want: answer 1 second under 2 hours');
eq(0, rh_licence_deploy_want($p(['issued_at' => $N - 90000]), 0, 0, $N), 'want: day-old answer is stale');
eq(0, rh_licence_deploy_want($p(['deploy_requested' => ['id' => $ID, 'at' => $N - 86400]]), 0, 0, $N), 'want: request exactly 24 hours old has lapsed');
eq($ID, rh_licence_deploy_want($p(['deploy_requested' => ['id' => $ID, 'at' => $N - 86399]]), 0, 0, $N), 'want: request 1 second under 24 hours');
eq(0, rh_licence_deploy_want(['issued_at' => $N - 60], 0, 0, $N), 'want: field missing (older panel) = nothing');
eq(0, rh_licence_deploy_want($p(['deploy_requested' => null]), 0, 0, $N), 'want: null = nothing');
eq(0, rh_licence_deploy_want(null, 0, 0, $N), 'want: no verified answer = nothing');
eq(0, rh_licence_deploy_want($p(['issued_at' => 0]), 0, 0, $N), 'want: no issued_at = nothing');
eq(0, rh_licence_deploy_want($p(['issued_at' => (string)($N - 60)]), 0, 0, $N), 'want: issued_at not a number = nothing');
foreach ([
    'id as text' => ['id' => (string)$ID, 'at' => $N - 600], 'id 0' => ['id' => 0, 'at' => $N - 600], 'negative id' => ['id' => -4, 'at' => $N - 600],
    'no at' => ['id' => $ID], 'at as float' => ['id' => $ID, 'at' => 1.5e9], 'a list' => [$ID, $N], 'text' => 'yes',
] as $why => $bad) {
    eq(0, rh_licence_deploy_want($p(['deploy_requested' => $bad]), 0, 0, $N), "want: malformed request ($why) = nothing");
    eq(null, rh_licence_deploy_request($bad), "request cleaned to null ($why)");
}
eq(['id' => $ID, 'at' => $N - 600], rh_licence_deploy_request(['id' => $ID, 'at' => $N - 600, 'extra' => 'x']), 'request cleaned to id and at only');

// ---- 2. --report
$S = $N - 120; // picked up 2 minutes ago
eq('none', rh_licence_deploy_report_decide(0, 0, 0, null, 0, $N), 'report: nothing picked up, no file = nothing');
eq('none', rh_licence_deploy_report_decide(5, 5, $S, $N, 300, $N), 'report: already finished = nothing (file left alone)');
eq('result', rh_licence_deploy_report_decide(5, 4, $S, $S, 300, $N), 'report: file written at the pick-up second = result');
eq('result', rh_licence_deploy_report_decide(5, 0, $S, $N - 1, 300, $N), 'report: file written after the pick-up = result');
eq('none', rh_licence_deploy_report_decide(5, 0, $S, $S - 1, 300, $N), 'report: file older than the pick-up is ignored');
eq('none', rh_licence_deploy_report_decide(5, 0, $S, null, 0, $N), 'report: no file yet (2 minutes) = wait');
eq('none', rh_licence_deploy_report_decide(5, 0, $N - 899, null, 0, $N), 'report: no file at 14m59s = wait');
eq('no_result', rh_licence_deploy_report_decide(5, 0, $N - 900, null, 0, $N), 'report: no file at 15 minutes = failure recorded');
eq('no_result', rh_licence_deploy_report_decide(5, 0, $N - 900, $N - 1000, 300, $N), 'report: only an older file at 15 minutes = failure recorded');
eq('none', rh_licence_deploy_report_decide(5, 0, $S, $N, 0, $N), 'report: empty file just after the pick-up (still being written) = wait');
eq('result', rh_licence_deploy_report_decide(5, 0, $N - 900, $N - 800, 0, $N), 'report: empty file at 15 minutes = its (empty) result');
eq('no_result', rh_licence_deploy_report_decide(5, 0, 0, $N, 300, $N), 'report: picked up with no pick-up time = failure, file not trusted');
// A pull outlasting one cron run (1-minute cron): no end yet = wait; at 15 minutes it is read (and fails as unfinished).
eq('none', rh_licence_deploy_report_decide(5, 0, $S, $N, 300, $N, false), 'report: file without its end (pull still running) = wait');
eq('result', rh_licence_deploy_report_decide(5, 0, $N - 900, $N - 10, 300, $N, false), 'report: file without its end at 15 minutes = read');
eq('result', rh_licence_deploy_report_decide(5, 0, $S, $N, 300, $N, true), 'report: finished file = result');
eq(true, rh_licence_deploy_file_complete("Updating a..b\nFast-forward\n 3 files changed\nexit=0\n"), 'complete: git output with exit=0');
eq(true, rh_licence_deploy_file_complete("fatal: Not possible to fast-forward, aborting.\nexit=128"), 'complete: git failure with exit=128');
eq(false, rh_licence_deploy_file_complete("From https://github.com/x/y\n * branch main -> FETCH_HEAD\n"), 'complete: git still running (no exit line)');
eq(false, rh_licence_deploy_file_complete("Updating a..b\nexit=0 left over text"), 'complete: exit= not on the last line');
eq(true, rh_licence_deploy_file_complete("{\n  \"result\": {\"status\": 1}\n}\n"), 'complete: uapi JSON');
eq(false, rh_licence_deploy_file_complete("{\n  \"result\": {\"status\": 1"), 'complete: half-written uapi JSON');
eq(false, rh_licence_deploy_file_complete("   \n"), 'complete: blank');

$okJson = "{\n   \"apiversion\" : 3,\n   \"func\" : \"update\",\n   \"module\" : \"VersionControl\",\n   \"result\" : {\n      \"data\" : {\"branch\" : \"main\"},\n      \"errors\" : null,\n      \"messages\" : null,\n      \"metadata\" : {},\n      \"status\" : 1,\n      \"warnings\" : null\n   }\n}\n";
$r = rh_licence_deploy_uapi_result($okJson);
check($r['ok'] === true && $r['msg'] === 'cPanel accepted Update from Remote.', 'uapi: status 1 = done', json_encode($r));
$r = rh_licence_deploy_uapi_result('{"result":{"status":0,"errors":["The system cannot update the repository: Your local changes would be overwritten by merge."],"data":null}}');
check($r['ok'] === false && strpos($r['msg'], 'cPanel said no: The system cannot update the repository') === 0 && strpos($r['msg'], 'fast-forward') !== false, 'uapi: status 0 = failed, with the reason', $r['msg']);
$r = rh_licence_deploy_uapi_result("Warning: something on stderr\n" . $okJson . "trailing noise\n");
check($r['ok'] === true, 'uapi: stderr text around the JSON is ignored', json_encode($r));
$r = rh_licence_deploy_uapi_result('{"status":1,"data":null,"errors":null}');
check($r['ok'] === true, 'uapi: the flat form (no "result") is read too');
$r = rh_licence_deploy_uapi_result("  \n");
check($r['ok'] === false && strpos($r['msg'], 'wrote nothing') !== false, 'uapi: empty output = failed (wrote nothing)', $r['msg']);
$r = rh_licence_deploy_uapi_result("/bin/sh: /usr/local/cpanel/bin/uapi: No such file or directory\n");
check($r['ok'] === false && strpos($r['msg'], 'did not answer in the expected way') !== false && strpos($r['msg'], 'No such file') !== false, 'uapi: no JSON = failed, the shell text shown', $r['msg']);
$r = rh_licence_deploy_uapi_result('{"result":{"status":0,"errors":null}}');
check($r['ok'] === false && strpos($r['msg'], 'no reason given') !== false, 'uapi: failed with no errors listed', $r['msg']);
$r = rh_licence_deploy_uapi_result('{"result":{"status":0,"errors":["fatal: Authorization: Bearer abc.def ghp_ABCDEFGH123456 github_pat_11AAAA_bbbb\u0007\nnext line ' . str_repeat('x', 900) . '"]}}');
check($r['ok'] === false && strpos($r['msg'], 'ghp_') === false && strpos($r['msg'], 'github_pat_') === false && strpos($r['msg'], 'abc.def') === false
    && strpos($r['msg'], '[token]') !== false && preg_match('/^[\x20-\x7E]*$/', $r['msg']) === 1 && strlen($r['msg']) <= 500, 'uapi: tokens and control characters scrubbed, at most 500', substr($r['msg'], 0, 120));

// git pull --ff-only output ending in "exit=N" (the cron line since 2026-11-23: cPanel's uapi cannot run from cron, CageFS)
$from = "From https://github.com/papauri/Liwonde_Sun_Hotel_2026\n * branch            main       -> FETCH_HEAD\n";
$r = rh_licence_deploy_uapi_result($from . "Already up to date.\nexit=0\n");
eq(['ok' => true, 'msg' => 'git pull: Already up to date.'], $r, 'git: already up to date');
$r = rh_licence_deploy_uapi_result($from . "   1a2b3c4..5d6e7f8  main       -> origin/main\nUpdating 1a2b3c4..5d6e7f8\nFast-forward\n"
    . " includes/licence.php        | 12 +++++++-----\n src/{old => new}/x.php      |  2 +-\n 2 files changed, 8 insertions(+), 6 deletions(-)\nexit=0\n");
eq(['ok' => true, 'msg' => 'git pull: Updating 1a2b3c4..5d6e7f8, Fast-forward, 2 files changed, 8 insertions(+), 6 deletions(-).'], $r, 'git: fast-forward with the file summary (a "{a => b}" rename line is not read as JSON)');
$r = rh_licence_deploy_uapi_result("Updating 1a2b3c4..5d6e7f8\r\nFast-forward\r\n 1 file changed, 1 insertion(+)\r\nexit=0\r\n");
check($r['ok'] === true && $r['msg'] === 'git pull: Updating 1a2b3c4..5d6e7f8, Fast-forward, 1 file changed, 1 insertion(+).', 'git: CRLF line ends, one file', $r['msg']);
$r = rh_licence_deploy_uapi_result("exit=0\n");
eq(['ok' => true, 'msg' => 'git pull: finished.'], $r, 'git: exit 0 with no output = done');
$r = rh_licence_deploy_uapi_result($from . "Updating 1a2b3c4..5d6e7f8\nerror: Your local changes to the following files would be overwritten by merge:\n\tincludes/licence.php\n\tadmin/a.php\n\tadmin/b.php\n\tadmin/c.php\n\tadmin/d.php\nPlease commit your changes or stash them before you merge.\nAborting\nexit=1\n");
check($r['ok'] === false && strpos($r['msg'], 'git pull failed (exit 1): error: Your local changes to the following files would be overwritten by merge: includes/licence.php') === 0
    && strpos($r['msg'], 'Aborting') !== false && strpos($r['msg'], 'Updating') === false && strpos($r['msg'], 'exit=') === false, 'git: local changes would be overwritten (exit 1), from the error line on', $r['msg']);
$r = rh_licence_deploy_uapi_result($from . "hint: Diverging branches can't be fast-forwarded, you need to either:\nhint:\nhint: \tgit merge --no-ff\nfatal: Not possible to fast-forward, aborting.\nexit=128\n");
eq(['ok' => false, 'msg' => 'git pull failed (exit 128): fatal: Not possible to fast-forward, aborting.'], $r, 'git: diverged (exit 128), hint lines left out');
$r = rh_licence_deploy_uapi_result("fatal: unable to access 'https://user:s3cretTok@github.com/papauri/x/': Could not resolve host: github.com\nexit=128\n");
check($r['ok'] === false && $r['msg'] === "git pull failed (exit 128): fatal: unable to access 'https://***@github.com/papauri/x/': Could not resolve host: github.com"
    && strpos($r['msg'], 's3cretTok') === false, 'git: network failure (exit 128), credentials in the address scrubbed', $r['msg']);
$r = rh_licence_deploy_uapi_result("/bin/sh: /usr/bin/git: No such file or directory\nexit=127\n");
eq(['ok' => false, 'msg' => 'git pull failed (exit 127): /bin/sh: /usr/bin/git: No such file or directory'], $r, 'git: no error/fatal line = the last lines (exit 127)');
$r = rh_licence_deploy_uapi_result($from . "Updating 1a2b3c4..5d6e7f8\nFast-forward\n");
check($r['ok'] === false && strpos($r['msg'], 'The update command did not finish (no exit code)') === 0 && strpos($r['msg'], 'Fast-forward') !== false, 'git: output with no exit= line = not finished, failed', $r['msg']);
$r = rh_licence_deploy_uapi_result("Updating 1a2b3c4..5d6e7f8\nexit=0\nmore after\n");
check($r['ok'] === false && strpos($r['msg'], 'did not finish (no exit code)') !== false, 'git: exit= not the last line = not finished', $r['msg']);
$r = rh_licence_deploy_uapi_result("exit=0x\n");
check($r['ok'] === false && strpos($r['msg'], 'did not answer in the expected way') !== false, 'git: a malformed exit line is not a result', $r['msg']);
$r = rh_licence_deploy_uapi_result(str_repeat("error: x\n", 200) . "exit=1\n");
check($r['ok'] === false && strlen($r['msg']) <= 500, 'git: long failure cut to 500');

eq('a b c', rh_licence_deploy_scrub("a\tb\r\n\x00c"), 'scrub: control characters and line breaks become one space');
eq('caf tea', rh_licence_deploy_scrub("caf\u{00e9} tea"), 'scrub: printable ASCII only');
eq(500, strlen(rh_licence_deploy_scrub(str_repeat('y', 2000))), 'scrub: cut to 500');
eq('[token]', rh_licence_deploy_scrub('cpanel user:SECRETTOKEN'), 'scrub: a cPanel token header');
eq('fatal: https://***@github.com/x', rh_licence_deploy_scrub('fatal: https://user:tok@github.com/x'), 'scrub: user:password in an address');
eq("'https://***@github.com' x", rh_licence_deploy_scrub("'https://tok@github.com' x"), 'scrub: a token in an address');
eq('https://***@github.com/x', rh_licence_deploy_scrub('https://user:p@ss@github.com/x'), 'scrub: a password holding "@" is removed whole');
eq('https://***@github.com/x', rh_licence_deploy_scrub('https://ghp_ABCDEFGH123456@github.com/x'), 'scrub: a GitHub token in an address');
eq('https://github.com/papauri/x mail a@b.com', rh_licence_deploy_scrub('https://github.com/papauri/x mail a@b.com'), 'scrub: plain addresses and emails left alone');

// ---- 3. .git reading (fixtures in the temp folder)
$tmp = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'rh_deploy_git_' . bin2hex(random_bytes(4));
$A = str_repeat('a', 40); $B = str_repeat('b', 40); $C = 'c0ffee' . str_repeat('1', 34);
$mk = static function (string $dir, array $files): string {
    foreach ($files as $name => $body) {
        $f = $dir . '/' . $name;
        if (!is_dir(dirname($f))) { mkdir(dirname($f), 0700, true); }
        file_put_contents($f, $body);
    }
    return $dir;
};
$loose = $mk("$tmp/loose/.git", ['HEAD' => "ref: refs/heads/main\n", 'refs/heads/main' => "$A\n", 'packed-refs' => "# pack-refs with: peeled\n$B refs/heads/main\n"]);
eq($A, rh_licence_git_head($loose), 'git: loose ref wins');
$packed = $mk("$tmp/packed/.git", ['HEAD' => "ref: refs/heads/live/2026\n", 'packed-refs' => "# pack-refs with: peeled fully-peeled sorted\n$C refs/heads/dev\n$B refs/heads/live/2026\n^$C\n"]);
eq($B, rh_licence_git_head($packed), 'git: packed ref (branch with a slash)');
$det = $mk("$tmp/detached/.git", ['HEAD' => "$C\n"]);
eq($C, rh_licence_git_head($det), 'git: detached HEAD');
$dots = $mk("$tmp/dots/.git", ['HEAD' => "ref: refs/heads/../../../outside\n", 'outside' => "$A\n"]);
eq(null, rh_licence_git_head($dots), 'git: ".." in the ref is refused');
$odd = $mk("$tmp/odd/.git", ['HEAD' => "ref: refs/tags/v1\n", 'refs/tags/v1' => "$A\n"]);
eq(null, rh_licence_git_head($odd), 'git: only refs/heads/* is followed');
$mk("$tmp/wt", ['.git' => "gitdir: ../real\n"]);
$mk("$tmp/real", ['HEAD' => "ref: refs/heads/main\n", 'refs/heads/main' => "$B\n"]);
eq($B, rh_licence_git_head("$tmp/wt/.git"), 'git: a .git file pointing elsewhere is followed once');
$mk("$tmp/badfile", ['.git' => "not a pointer\n"]);
eq(null, rh_licence_git_head("$tmp/badfile/.git"), 'git: a .git file without gitdir: = unreadable');
eq(null, rh_licence_git_head("$tmp/missing/.git"), 'git: no .git = null');
$short = $mk("$tmp/short/.git", ['HEAD' => "ref: refs/heads/main\n", 'refs/heads/main' => "abc123\n"]);
eq(null, rh_licence_git_head($short), 'git: a ref that is not 40 hex = null');
$rm = static function (string $d) use (&$rm): void {
    foreach (scandir($d) ?: [] as $f) {
        if ($f === '.' || $f === '..') { continue; }
        is_dir("$d/$f") ? $rm("$d/$f") : unlink("$d/$f");
    }
    rmdir($d);
};
$rm($tmp);
check(!is_dir($tmp), 'git fixtures removed');
$live = rh_licence_git_head($root . '/.git');
check($live === null || preg_match('/^[0-9a-f]{40}$/D', $live) === 1, 'git: this checkout reads as 40 hex (or nothing)', (string)$live);

// ---- 4. the deploy block and the body limit
// The panel's own cleaning (msp-control-panel app/deploy.php deploy_clean_report), copied: what it would accept.
$panelAccepts = static function (array $b): bool {
    $id = static fn($v): bool => is_int($v) && $v >= 0 && $v <= 4294967295;
    return array_diff(array_keys($b), ['seen_id', 'done_id', 'ok', 'msg', 'sha']) === []
        && $id($b['seen_id'] ?? null) && $id($b['done_id'] ?? null)
        && (!array_key_exists('ok', $b) || is_bool($b['ok'])) && (!array_key_exists('msg', $b) || (is_string($b['msg']) && strlen($b['msg']) <= 500))
        && (!array_key_exists('sha', $b) || preg_match('/^[0-9a-f]{40}$/D', $b['sha']) === 1)
        && (array_key_exists('ok', $b) === array_key_exists('msg', $b));
};
$b = rh_licence_deploy_block([], null);
eq(['seen_id' => 0, 'done_id' => 0], $b, 'block: nothing yet = ids 0, no ok/msg, no sha');
$b = rh_licence_deploy_block(['licence_deploy_seen_id' => (string)$ID, 'licence_deploy_seen_at' => (string)$S, 'licence_deploy_done_id' => '0', 'licence_deploy_ok' => '', 'licence_deploy_msg' => ''], $A);
eq(['seen_id' => $ID, 'done_id' => 0, 'sha' => $A], $b, 'block: picked up, not finished = no ok/msg yet');
check($panelAccepts($b), 'block: picked up shape accepted by the panel rules');
$b = rh_licence_deploy_block(['licence_deploy_seen_id' => (string)$ID, 'licence_deploy_done_id' => (string)$ID, 'licence_deploy_ok' => '0', 'licence_deploy_msg' => "cPanel said no\x07"], $A);
check($b['ok'] === false && $b['msg'] === 'cPanel said no' && $b['done_id'] === $ID && $panelAccepts($b), 'block: failed result = ok false (a real boolean from "0"), msg scrubbed', json_encode($b));
$b = rh_licence_deploy_block(['licence_deploy_seen_id' => (string)$ID, 'licence_deploy_done_id' => (string)$ID, 'licence_deploy_ok' => '1', 'licence_deploy_msg' => 'cPanel accepted Update from Remote.'], 'NOT-A-SHA');
check($b['ok'] === true && !array_key_exists('sha', $b) && $panelAccepts($b), 'block: ok "1" = true, a bad sha is left out', json_encode($b));
$b = rh_licence_deploy_block(['licence_deploy_seen_id' => '-3', 'licence_deploy_done_id' => 'x'], strtoupper($A));
eq(['seen_id' => 0, 'done_id' => 0], $b, 'block: junk ids = 0, an upper-case sha is left out');

$req = ['tenant' => 't', 'domain' => 'd.example', 'key' => 'k', 'version' => 'pms-20261123', 'maintenance_seen' => 0,
    'deploy' => ['seen_id' => 2, 'done_id' => 2, 'ok' => true, 'msg' => 'cPanel accepted Update from Remote.', 'sha' => $A]];
eq(json_encode($req, JSON_UNESCAPED_SLASHES), rh_licence_request_json($req), 'body: a normal check-in is encoded unchanged');
$worst = [
    'tenant' => str_repeat('t', 64), 'domain' => str_repeat('d', 253), 'key' => str_repeat('k', 128), 'version' => 'pms-20261123',
    'backup' => ['last_at' => $N, 'size' => PHP_INT_MAX, 'error_at' => $N, 'error_code' => str_repeat('e', 30), 'offsite_at' => $N, 'requested_seen' => $N],
    'staff_done_id' => PHP_INT_MAX, 'staff_error' => str_repeat("\u{1F600}", 240), 'maintenance_seen' => PHP_INT_MAX,
    'deploy' => ['seen_id' => 4294967295, 'done_id' => 4294967295, 'ok' => false, 'msg' => str_repeat('"', 500), 'sha' => $A],
];
check(strlen((string)json_encode($worst, JSON_UNESCAPED_SLASHES)) > 4096, 'body: the worst case really is over 4096 before fitting');
$body = rh_licence_request_json($worst);
$back = json_decode($body, true);
check(strlen($body) <= 4096 && is_array($back) && isset($back['deploy']['msg']) && $back['deploy']['msg'] === str_repeat('"', strlen($back['deploy']['msg']))
    && strlen($back['deploy']['msg']) < 500 && $back['deploy']['sha'] === $A && $back['staff_error'] === $worst['staff_error'], 'body: worst case fits 4096 by cutting deploy.msg only', (string)strlen($body));
$huge = $worst; $huge['staff_error'] = str_repeat("\u{1F600}", 400); // beyond any real check-in: the deploy object goes, never the licence fields
$body = rh_licence_request_json($huge);
$back = json_decode($body, true);
check(is_array($back) && !isset($back['deploy']) && $back['tenant'] === $huge['tenant'], 'body: when even an empty msg cannot fit, the deploy object is left out');
$fits = rh_licence_request_json(['tenant' => 't', 'deploy' => ['seen_id' => 1, 'done_id' => 1, 'ok' => false, 'msg' => str_repeat('m', 500)]], 300);
check(strlen($fits) <= 300 && json_decode($fits, true)['deploy']['ok'] === false, 'body: a tight limit keeps ok and cuts the message', (string)strlen($fits));

// ---- 5. the files
$cli = (string)file_get_contents($root . '/scripts/deploy-check.php');
$code = ''; // the script without its comments
foreach (token_get_all($cli) as $t) {
    if (!is_array($t) || !in_array($t[0], [T_COMMENT, T_DOC_COMMENT], true)) { $code .= is_array($t) ? $t[1] : $t; }
}
$first = strtok(substr($cli, strpos($cli, "\n") + 1), "\n");
check(strpos($first, "if (PHP_SAPI !== 'cli') { http_response_code(403); echo") === 0 && strpos($first, 'exit(1); }') !== false, 'deploy-check.php: first line refuses non-CLI and exits 1', $first);
check(preg_match('/\bexit\s*\(\s*["\']/', $code) === 0 && preg_match('/\bdie\s*\(/', $code) === 0, 'deploy-check.php: never exit("text") / die() (exit 0 would run the update)');
check(preg_match('/\b(exec|shell_exec|system|passthru|proc_open|popen|pcntl_exec)\s*\(|`/', $code) === 0, 'deploy-check.php: runs no command (cron runs git, never PHP)');
check(strpos($cli, '&& { GIT_TERMINAL_PROMPT=0 /usr/bin/git pull --ff-only origin main; echo "exit=$?"; } > storage/deploy-last.json 2>&1; /usr/local/bin/php scripts/deploy-check.php --report storage/deploy-last.json') !== false
    && strpos($cli, '/usr/local/cpanel/bin/uapi --output') === false, 'deploy-check.php: the documented cron line pulls with git (uapi fails under CageFS) and writes the exit code');
check(strpos($cli, 'register_shutdown_function') !== false && strpos($cli, "rh_licence_status(true)") !== false && strpos($cli, "\$st['reason'] ?? '') === 'ok'") !== false,
    'deploy-check.php: an unexpected end exits 1; --want decides from the re-verified token');
$lic = (string)file_get_contents($root . '/includes/licence.php');
$fa = strpos($lic, 'function rh_licence_store_accepted(');
$fb = $fa === false ? false : strpos($lic, 'function ', $fa + 10);
check($fa !== false && $fb !== false && strpos(substr($lic, $fa, $fb - $fa), "array_key_exists('deploy_requested', \$payload)") !== false
    && substr_count($lic, "rh_licence_store_write(\$pdo, 'deploy_requested'") === 1, 'licence.php: the request is stored only by rh_licence_store_accepted (a verified answer)');
check(strpos($lic, "['deploy_requested', 'deploy_seen_id', 'deploy_seen_at', 'deploy_done_id', 'deploy_ok', 'deploy_msg'], true)") !== false, 'licence.php: the deploy settings are on the write list');
check(strpos($lic, '$body = rh_licence_request_json($req);') !== false, 'licence.php: the check-in body goes through the 4096-byte fit');
$ht = (string)file_get_contents($root . '/.htaccess');
check(preg_match('#RewriteRule \^\([^)]*\bstorage\b[^)]*\)\(/\|\$\) - \[F#', $ht) === 1 && preg_match('/FilesMatch "[^"]*\bjson\b/', $ht) === 1, '.htaccess: storage/ and .json files are refused (the result file)');

echo "licence_deploy: $pass passed, $fail failed\n";
exit($fail === 0 ? 0 : 1);
