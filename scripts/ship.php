<?php
if (PHP_SAPI !== 'cli') { http_response_code(403); exit("CLI only.\n"); } // never runnable over the web

/**
 * Ship a change to both hotels: lint, test, port to Liwonde, verify, commit, push.
 * Usage: php scripts/ship.php --files="a b c" [--all-changed] [--tests=booking,finance,pos,settings,hotel,timezone|all|none]
 *        [--message-file=path] [--port] [--commit] [--push] [--liwonde=path] [--verbose]
 * Dry run by default. See scripts/README-SHIP.md.
 */

$ROOT = str_replace('\\', '/', dirname(__DIR__));

$SUITES = [
    'booking'  => ['Booking smoke', 'scripts/smoke_test_booking.php'],
    'finance'  => ['Finance smoke', 'scripts/smoke_test_finance.php'],
    'pos'      => ['POS/KDS smoke', 'scripts/smoke_test_pos_kds.php'],
    'settings' => ['Settings pages', 'scripts/tests/settings_pages.php'],
    'hotel'    => ['Hotel details', 'scripts/tests/hotel_details.php'],
    'timezone' => ['User timezone', 'scripts/tests/user_timezone.php'],
];

$FORBIDDEN = [
    '#(^|/)\.env(\.|$)#i'      => '.env',
    '#^(backups|cache|logs|storage)/#i' => 'backups/cache/logs/storage',
    '#^invoices/.*\.pdf$#i'    => 'invoice PDF',
    '#^config/[^/]*local#i'    => 'config local file',
    '#^\.git/#i'               => '.git',
    '#\.(sql|sql\.gz|pem|key|p12|pfx|zip|bak)$#i' => 'dump/key/archive file',
    '#(^|/)error_log$#i'       => 'error_log',
    '#^(uploads|images/uploads)/#i' => 'uploaded files',
];
// Added lines that look like a credential stop the ship (CLAUDE.md: check the diff for secrets).
$SECRET_RE = '#(password|passwd|secret|api[_-]?key|access[_-]?token|private[_-]?key)\s*[\'"]?\s*[:=]>?\s*[\'"][^\'"\s]{8,}[\'"]#i';

// What happened so far, printed on any early exit so a half-done ship is never silent.
$SHIP = ['ported' => [], 'commit' => [], 'push' => [], 'done' => false];
register_shutdown_function(function () {
    global $SHIP;
    if ($SHIP['done']) return;
    if ($SHIP['ported'] && !$SHIP['commit']) {
        echo "NOTE Liwonde working tree MODIFIED, UNCOMMITTED: " . implode(', ', $SHIP['ported']) . " (revert there by hand or re-run after fixing)\n";
    }
    if ($SHIP['commit'] || $SHIP['push']) {
        echo "NOTE state: commit[" . implode('; ', $SHIP['commit']) . "] push[" . implode('; ', $SHIP['push']) . "] - repos may be out of step\n";
    }
});

// ---------- output helpers ----------
$VERBOSE = false;
function say($s) { echo $s . "\n"; }
function step($name, $msg = '') { say(rtrim(sprintf('OK   %-12s %s', $name, $msg))); }
function skip($name, $msg = '') { say(rtrim(sprintf('SKIP %-12s %s', $name, $msg))); }
function fail($name, $why) { say(sprintf('FAIL %-12s %s', $name, $why)); exit(1); }

// ---------- process helpers (stdout/stderr to temp files: no pipe deadlocks) ----------
function run(array $cmd, $cwd = null, $stdin = null) {
    $dir = sys_get_temp_dir();
    $o = tempnam($dir, 'shp'); $e = tempnam($dir, 'shp');
    $nul = DIRECTORY_SEPARATOR === '\\' ? 'NUL' : '/dev/null';
    $spec = [0 => $stdin === null ? ['file', $nul, 'r'] : ['pipe', 'r'], 1 => ['file', $o, 'w'], 2 => ['file', $e, 'w']];
    $p = @proc_open($cmd, $spec, $pipes, $cwd);
    if (!is_resource($p)) { @unlink($o); @unlink($e); return [127, '', 'could not start ' . $cmd[0]]; }
    if ($stdin !== null) { fwrite($pipes[0], $stdin); fclose($pipes[0]); }
    $code = proc_close($p);
    $out = (string)file_get_contents($o); $err = (string)file_get_contents($e);
    @unlink($o); @unlink($e);
    return [$code, $out, $err];
}
function git($repo, array $args) { return run(array_merge(['git', '-C', $repo], $args)); }
function firstLine($s) { $s = trim($s); $p = strpos($s, "\n"); return $p === false ? $s : substr($s, 0, $p); }

function findTool($name) {
    $dirs = explode(PATH_SEPARATOR, (string)getenv('PATH'));
    foreach (['ProgramFiles', 'ProgramW6432'] as $v) {
        if (getenv($v)) { $dirs[] = getenv($v) . '\\Git\\usr\\bin'; $dirs[] = getenv($v) . '\\Git\\cmd'; }
    }
    $dirs[] = 'C:\\Program Files\\Git\\usr\\bin';
    foreach ($dirs as $d) {
        foreach (['', '.exe'] as $x) {
            $f = rtrim($d, '/\\') . DIRECTORY_SEPARATOR . $name . $x;
            if ($d !== '' && is_file($f)) return $f;
        }
    }
    return null;
}

// ---------- content helpers ----------
function norm($s) { return str_replace("\r\n", "\n", $s); }
function isCrlf($raw) {
    $crlf = substr_count($raw, "\r\n"); $lf = substr_count($raw, "\n");
    return $lf > 0 && $crlf * 2 > $lf;
}
function toStyle($normText, $crlf) { return $crlf ? str_replace("\n", "\r\n", $normText) : $normText; }

$TMP = sys_get_temp_dir() . '/ship_' . getmypid();
@mkdir($TMP, 0777, true);
register_shutdown_function(function () use ($TMP) {
    foreach ((array)glob($TMP . '/*') as $f) @unlink($f);
    @rmdir($TMP);
});
$TOOLS = ['diff' => findTool('diff'), 'patch' => findTool('patch'), 'node' => findTool('node')];

/** +/- lines of a zero-context diff (no headers or line numbers): the "shape" of the drift between two texts. */
function diffSig($a, $b) {
    global $TMP, $TOOLS;
    if ($a === $b) return '';
    file_put_contents("$TMP/a.txt", $a); file_put_contents("$TMP/b.txt", $b);
    $cmd = $TOOLS['diff'] ? [$TOOLS['diff'], '-U0', "$TMP/a.txt", "$TMP/b.txt"]
                          : ['git', 'diff', '--no-index', '--no-color', '-U0', "$TMP/a.txt", "$TMP/b.txt"];
    [$c, $out] = run($cmd);
    if ($c > 1) return 'DIFF-ERROR';
    $keep = []; $started = false;
    foreach (explode("\n", $out) as $l) {
        if (strncmp($l, '@@', 2) === 0) { $started = true; continue; }
        if ($started && $l !== '' && ($l[0] === '+' || $l[0] === '-')) $keep[] = $l;
    }
    return implode("\n", $keep);
}

/** Apply the H->W change onto L (all LF-normalised). Returns [result|null, error]. */
function applyChange($H, $W, $L) {
    global $TMP, $TOOLS;
    if ($L === $H) return [$W, ''];
    if ($H === $W) return [$L, ''];
    file_put_contents("$TMP/h.txt", $H); file_put_contents("$TMP/w.txt", $W); file_put_contents("$TMP/l.txt", $L);
    if ($TOOLS['diff'] && $TOOLS['patch']) {
        [$c, $d] = run([$TOOLS['diff'], '-U3', "$TMP/h.txt", "$TMP/w.txt"]);
        if ($c !== 1) return [null, 'diff failed'];
        file_put_contents("$TMP/p.diff", $d);
        @unlink("$TMP/r.txt"); @unlink("$TMP/rej.txt");
        [$c, $out, $err] = run([$TOOLS['patch'], '--binary', '-F0', '--no-backup-if-mismatch', '-r', "$TMP/rej.txt", '-o', "$TMP/r.txt", "$TMP/l.txt", "$TMP/p.diff"]);
        if ($c !== 0 || !is_file("$TMP/r.txt")) return [null, 'patch rejected (' . firstLine($out . $err) . ')'];
        return [norm((string)file_get_contents("$TMP/r.txt")), ''];
    }
    // Fallback: three-way merge with git (current=L, base=H, other=W)
    [$c, $out, $err] = run(['git', 'merge-file', '-p', "$TMP/l.txt", "$TMP/h.txt", "$TMP/w.txt"]);
    if ($c !== 0) return [null, 'merge conflict/failure (git merge-file)'];
    return [norm($out), ''];
}

// ---------- tests ----------
function runSuites($dir, array $keys) {
    global $SUITES;
    $bits = []; $bad = []; $detail = [];
    foreach ($keys as $k) {
        [$name, $file] = $SUITES[$k];
        if (!is_file("$dir/$file")) { $bad[] = $k; $bits[] = "$k:missing"; continue; }
        [$code, $out, $err] = run([PHP_BINARY, $file], $dir);
        $text = $out . "\n" . $err;
        $pass = preg_match_all('/^PASS\b/m', $text); $failn = preg_match_all('/^FAIL\b/m', $text);
        if (preg_match('/(\d+) passed, (\d+) failed/', $text, $m)) { $pass = (int)$m[1]; $failn = (int)$m[2]; }
        elseif (preg_match('/PASS=(\d+) FAIL=(\d+)/', $text, $m)) { $pass = (int)$m[1]; $failn = (int)$m[2]; }
        $ok = $code === 0 && $failn === 0 && $pass > 0;
        $bits[] = "$k " . ($ok ? "$pass ok" : "$pass/$failn FAIL");
        if (!$ok) {
            $bad[] = $k;
            $lines = array_filter(explode("\n", $text), function ($l) { return preg_match('/FAIL|Fatal|Error/', $l); });
            foreach (array_slice($lines, 0, 4) as $l) $detail[] = '       ' . trim($l);
        }
    }
    return [!$bad, implode(', ', $bits), $detail];
}

// ---------- lint ----------
function lintFile($base, $f) {
    global $TOOLS;
    $ext = strtolower(pathinfo($f, PATHINFO_EXTENSION));
    if ($ext === 'php') { $cmd = [PHP_BINARY, '-l', "$base/$f"]; }
    elseif ($ext === 'js' || $ext === 'mjs') {
        if (!$TOOLS['node']) return 'node not found';
        $cmd = [$TOOLS['node'], '--check', "$base/$f"];
    } else return null;
    [$c, $out, $err] = run($cmd);
    return $c === 0 ? null : "$f: " . firstLine($err . "\n" . $out);
}

// ---------- args ----------
$opt = ['files' => '', 'tests' => 'all', 'message-file' => '', 'liwonde' => ''];
$flag = [];
foreach (array_slice($argv, 1) as $a) {
    if (preg_match('/^--([a-z-]+)=(.*)$/s', $a, $m)) $opt[$m[1]] = $m[2];
    elseif (preg_match('/^--([a-z-]+)$/', $a, $m)) $flag[$m[1]] = true;
    else fail('args', "unexpected argument: $a");
}
$unknown = array_diff(array_merge(array_keys($opt), array_keys($flag)),
    ['files', 'tests', 'message-file', 'liwonde', 'all-changed', 'port', 'commit', 'push', 'verbose']);
if ($unknown) fail('args', 'unknown option: --' . implode(', --', $unknown));
$VERBOSE = !empty($flag['verbose']);
$doPush = !empty($flag['push']);
$doCommit = !empty($flag['commit']);
$doPort = !empty($flag['port']) || $doCommit;
if ($doPush && !$doCommit) fail('args', '--push requires --commit');
$LIW = str_replace('\\', '/', $opt['liwonde'] !== '' ? rtrim($opt['liwonde'], '/\\') : dirname($ROOT) . '/Liwonde_Sun_Hotel_2026');
$mode = $doPush ? 'commit+push' : ($doCommit ? 'commit' : ($doPort ? 'port' : 'dry-run'));

// ---------- file list ----------
$files = [];
foreach (preg_split('/[\s,]+/', trim($opt['files'])) as $f) {
    if ($f === '') continue;
    $f = ltrim(str_replace('\\', '/', $f), '/');
    $files[] = preg_replace('#^\./#', '', $f);
}
if (!empty($flag['all-changed'])) {
    [$c, $out] = git($ROOT, ['status', '--porcelain=v1', '-z', '-uall']);
    if ($c !== 0) fail('plan', 'git status failed in Rosalyn\'s');
    $ent = explode("\0", $out);
    for ($i = 0; $i < count($ent); $i++) {
        if (strlen($ent[$i]) < 4) continue;
        $xy = substr($ent[$i], 0, 2); $p = substr($ent[$i], 3);
        if ($xy[0] === 'R' || $xy[0] === 'C') $i++; // skip original name
        if (strpos($xy, 'D') !== false) fail('plan', "deleted file $p: ship deletions by hand");
        $files[] = $p;
    }
}
$files = array_values(array_unique($files));
if (!$files) fail('plan', 'no files (use --files="a b" and/or --all-changed)');
foreach ($files as $f) {
    if (strpos($f, '..') !== false || preg_match('#^[A-Za-z]:#', $f)) fail('plan', "unsafe path: $f");
    foreach ($FORBIDDEN as $re => $why) if (preg_match($re, $f)) fail('plan', "refusing $f ($why)");
    if (!is_file("$ROOT/$f")) fail('plan', "not found in Rosalyn's: $f");
}

// ---------- tests selection ----------
$sel = strtolower(trim($opt['tests']));
if ($sel === 'all' || $sel === '') $testKeys = array_keys($SUITES);
elseif ($sel === 'none') $testKeys = [];
else {
    $testKeys = array_values(array_unique(array_filter(array_map('trim', explode(',', $sel)))));
    foreach ($testKeys as $k) if (!isset($SUITES[$k])) fail('args', "unknown suite '$k' (" . implode(',', array_keys($SUITES)) . ')');
}

// ---------- preflight ----------
if (!is_dir("$LIW/.git")) fail('plan', 'Liwonde repo not found: ' . $LIW);
$msgFile = '';
if ($doCommit) {
    $msgFile = realpath($opt['message-file']);
    if ($opt['message-file'] === '' || !$msgFile || trim((string)file_get_contents($msgFile)) === '') fail('plan', '--commit needs a non-empty --message-file');
}
if ($doPort) {
    foreach (['Rosalyn\'s' => $ROOT, 'Liwonde' => $LIW] as $nm => $repo) {
        [$c, $b] = git($repo, ['rev-parse', '--abbrev-ref', 'HEAD']);
        if (trim($b) !== 'main') fail('plan', "$nm is on '" . trim($b) . "', not main");
    }
    // Liwonde must not already have uncommitted edits to these files (they would be shipped unseen).
    $liwExisting = array_values(array_filter($files, function ($f) use ($LIW) { return is_file("$LIW/$f"); }));
    if ($liwExisting) {
        [$c, $o] = git($LIW, array_merge(['status', '--porcelain', '--'], $liwExisting));
        if (trim($o) !== '') fail('plan', 'Liwonde already has uncommitted changes to: ' . trim(preg_replace('/\s+/', ' ', $o)));
    }
}
// Secret scan of the lines this ship adds.
foreach ($files as $f) {
    [$c, $d] = git($ROOT, ['diff', 'HEAD', '--', $f]);
    $added = $c === 0 && $d !== '' ? $d : (string)@file_get_contents("$ROOT/$f");
    foreach (preg_split('/\R/', $added) as $ln) {
        if (($d === '' || ($ln !== '' && $ln[0] === '+')) && preg_match($SECRET_RE, $ln)) fail('plan', "possible credential in $f: " . substr(trim($ln), 0, 60) . '...');
    }
}
step('plan', count($files) . ' file(s) | mode=' . $mode . ' | tests=' . ($testKeys ? implode(',', $testKeys) : 'none')
    . ($TOOLS['diff'] && $TOOLS['patch'] ? '' : ' | no diff/patch: git merge-file fallback'));

// ---------- 1. lint ----------
$n = 0;
foreach ($files as $f) {
    $r = lintFile($ROOT, $f);
    if ($r !== null) fail('lint', $r);
    $n++;
}
step('lint', "$n file(s)");

// ---------- 2. tests (Rosalyn's) ----------
if ($testKeys) {
    [$ok, $sum, $det] = runSuites($ROOT, $testKeys);
    if (!$ok) { say("FAIL tests        $sum"); foreach ($det as $d) say($d); exit(1); }
    step('tests', $sum);
} else skip('tests', 'none selected');

// ---------- 3. port (plan in memory, write only with --port/--commit) ----------
$plan = []; $counts = [];
foreach ($files as $f) {
    $Wraw = (string)file_get_contents("$ROOT/$f");
    [$c, $Hraw] = git($ROOT, ['show', "HEAD:$f"]);
    $Hraw = $c === 0 ? $Hraw : null;
    $lp = "$LIW/$f";
    $Lraw = is_file($lp) ? (string)file_get_contents($lp) : null;
    $label = ''; $note = ''; $write = null; $expect = null; $crlf = $Lraw !== null && isCrlf($Lraw);
    $bin = strpos($Wraw . (string)$Hraw . (string)$Lraw, "\0") !== false;

    if ($bin) {
        $expect = $Wraw;
        if ($Lraw === null || $Lraw === $Hraw) { $label = 'COPY'; $write = $Wraw; }
        elseif ($Lraw === $Wraw) { $label = 'COPY'; $note = 'already equal'; }
        else { $label = 'FAIL'; $note = 'binary file differs in Liwonde'; }
    } else {
        $W = norm($Wraw); $H = $Hraw === null ? null : norm($Hraw); $L = $Lraw === null ? null : norm($Lraw);
        $expect = $W;
        if ($L === null) { $label = 'COPY'; $note = 'new in Liwonde'; $write = $Wraw; }
        elseif ($L === $W) { $label = 'COPY'; $note = 'already equal'; }
        elseif ($H === null) { $label = 'FAIL'; $note = 'untracked here but different file exists in Liwonde'; }
        elseif ($L === $H) { $label = 'COPY'; $write = $Wraw; }
        else {
            [$R, $err] = applyChange($H, $W, $L);
            if ($R === null) { $label = 'FAIL'; $note = $err; }
            else {
                $expect = $R;
                if ($R === $W) $label = 'PATCH';
                elseif (diffSig($H, $L) === diffSig($W, $R)) { $label = 'DRIFT-OK'; $note = 'pre-existing drift unchanged'; }
                else { $label = 'FAIL'; $note = 'patch would change existing drift'; }
                if ($label !== 'FAIL' && $R !== $L) $write = toStyle($R, $crlf);
                elseif ($label !== 'FAIL') $note .= ($note ? ', ' : '') . 'nothing to write';
            }
        }
    }
    $plan[$f] = compact('label', 'note', 'write', 'expect', 'bin');
    $counts[$label] = ($counts[$label] ?? 0) + 1;
}
$sumParts = [];
foreach ($counts as $l => $c) $sumParts[] = "$l $c";
$lines = [];
foreach ($plan as $f => $p) $lines[] = sprintf('       %-8s %s%s', $p['label'], $f, $p['note'] ? " ({$p['note']})" : '');
$failed = isset($counts['FAIL']);
$cap = ($VERBOSE || $failed) ? PHP_INT_MAX : 6;
$shown = 0;
$portName = $doPort ? 'port' : 'port(dry)';
if ($failed) { say("FAIL $portName      " . implode(', ', $sumParts)); }
else step($portName, implode(', ', $sumParts));
foreach ($lines as $l) {
    if ($failed && strpos($l, ' FAIL ') === false) continue;
    if ($shown++ >= $cap) { say('       ... +' . (count($lines) - $cap) . ' more (--verbose)'); break; }
    say($l);
}
if ($failed) exit(1);

if ($doPort) {
    foreach ($plan as $f => $p) {
        if ($p['write'] === null) continue;
        $lp = "$LIW/$f";
        if (!is_dir(dirname($lp)) && !@mkdir(dirname($lp), 0777, true)) fail('port', "cannot create dir for $f");
        if (file_put_contents($lp, $p['write']) === false) fail('port', "cannot write $f");
        $SHIP['ported'][] = $f;
    }
}

// ---------- 4. verify ----------
$bad = [];
foreach ($plan as $f => $p) {
    if (!$doPort) continue;
    $disk = (string)file_get_contents("$LIW/$f");
    $got = $p['bin'] ? $disk : norm($disk);
    if ($got !== $p['expect']) $bad[] = $f;
}
if ($bad) fail('verify', 'Liwonde differs after write: ' . implode(', ', $bad));
step($doPort ? 'verify' : 'verify(dry)', count($plan) . ' file(s) ' . ($doPort ? 'match Rosalyn\'s (drift unchanged)' : 'would match (in memory)'));

// ---------- 5. Liwonde lint + tests ----------
if ($doPort) {
    foreach (array_keys($plan) as $f) {
        $r = lintFile($LIW, $f);
        if ($r !== null) fail('lint-liwonde', $r);
    }
    step('lint-liwonde', count($plan) . ' file(s)');
    if ($testKeys) {
        [$ok, $sum, $det] = runSuites($LIW, $testKeys);
        if (!$ok) { say("FAIL tests-liwonde $sum"); foreach ($det as $d) say($d); exit(1); }
        step('tests-liwonde', $sum);
    } else skip('tests-liwonde', 'none selected');
} else skip('liwonde', 'dry run: nothing copied, no lint/tests there');

// ---------- 6. commit / push ----------
if ($doCommit) {
    $hashes = [];
    foreach (['Rosalyn\'s' => $ROOT, 'Liwonde' => $LIW] as $nm => $repo) {
        $exist = array_values(array_filter($files, function ($f) use ($repo) { return is_file("$repo/$f"); }));
        [$c, $o, $e] = git($repo, array_merge(['add', '--'], $exist));
        if ($c !== 0) fail('commit', "$nm: git add failed: " . firstLine($e . $o));
        [$c, $o] = git($repo, array_merge(['status', '--porcelain', '--'], $exist));
        if (trim($o) === '') { $hashes[$nm] = 'nothing to commit'; continue; }
        [$c, $o, $e] = git($repo, array_merge(['commit', '-q', '-F', $msgFile, '--'], $exist));
        if ($c !== 0) fail('commit', "$nm: " . firstLine($e . $o));
        [, $h] = git($repo, ['rev-parse', '--short', 'HEAD']);
        $hashes[$nm] = trim($h);
        $SHIP['commit'][] = "$nm " . trim($h);
    }
    step('commit', "Rosalyn's " . $hashes["Rosalyn's"] . ' | Liwonde ' . $hashes['Liwonde']);
    if ($doPush) {
        foreach (['Rosalyn\'s' => $ROOT, 'Liwonde' => $LIW] as $nm => $repo) {
            [$c, $o, $e] = git($repo, ['push', 'origin', 'main']);
            if ($c !== 0) fail('push', "$nm: " . firstLine($e . $o));
            $SHIP['push'][] = "$nm ok";
        }
        step('push', 'both repos pushed to origin/main');
    } else skip('push', 'no --push');
} else skip('commit', 'no --commit');

$SHIP['done'] = true;
say('DONE ' . $mode . ': ' . count($files) . ' file(s)');
exit(0);
