<?php
if (PHP_SAPI !== 'cli') { http_response_code(403); exit("CLI only.\n"); } // never runnable over the web

/**
 * Port work ALREADY COMMITTED at Rosalyn's to Liwonde, without an agent (owner 2026-11-17, token cost).
 * `scripts/ship.php` ports uncommitted work at ship time; this is for a commit (or range) that was
 * committed at Rosalyn's first. Same decision table as ship.php:
 *   COPY      Liwonde's file equals Rosalyn's file before the change (or is missing) -> take Rosalyn's new file
 *   SAME      Liwonde already has the new content
 *   PATCH     Liwonde's own drift is kept, Rosalyn's change applied on top (diff/patch, else git merge-file)
 *   DRIFT-OK  as PATCH, and the existing Liwonde-only drift is unchanged
 *   SKIP      not ported by design (.claude/, STATUS.md, forbidden paths) - nothing to do
 *   DELETE    Rosalyn's deleted the file: never deleted here, do it by hand if wanted
 *   FAIL      cannot be applied safely -> only these files need a person/agent (Sonnet per cost rule 2)
 *
 * Usage: php scripts/port-commit.php <commit> | <from>..<to>
 *            [--apply] [--partial] [--commit] [--push] [--liwonde=path] [--verbose]
 *   dry run by default (writes nothing). --apply writes; any FAIL stops everything unless --partial
 *   (then the clean files are written and the FAIL files are listed for the agent).
 *   --commit commits only the written files at Liwonde ("<subject> (port of Rosalyn's <hash>)").
 *   --push pushes Liwonde only if it was not already ahead of origin (never pushes others' commits).
 * Never runs tests (owner 2026-10-30: tests run at Rosalyn's only); lints every written PHP/JS file.
 */

$ROOT = str_replace('\\', '/', dirname(__DIR__));

$FORBIDDEN = [
    '#^\.claude/#i'            => 'per-hotel agent docs',
    '#^STATUS\.md$#i'          => 'per-hotel status',
    '#(^|/)\.env(\.|$)#i'      => '.env',
    '#^(backups|cache|logs|storage)/#i' => 'backups/cache/logs/storage',
    '#^invoices/#i'            => 'invoices',
    '#^config/[^/]*local#i'    => 'config local file',
    '#\.(sql|sql\.gz|pem|key|p12|pfx|zip|bak)$#i' => 'dump/key/archive file',
    '#(^|/)error_log$#i'       => 'error_log',
    '#^(uploads|images/uploads)/#i' => 'uploaded files',
];

function say($s) { echo $s . "\n"; }
function fail($why) { say('FAIL ' . $why); exit(1); }

function run(array $cmd, $cwd = null) {
    $dir = sys_get_temp_dir();
    $o = tempnam($dir, 'prt'); $e = tempnam($dir, 'prt');
    $nul = DIRECTORY_SEPARATOR === '\\' ? 'NUL' : '/dev/null';
    $p = @proc_open($cmd, [0 => ['file', $nul, 'r'], 1 => ['file', $o, 'w'], 2 => ['file', $e, 'w']], $pipes, $cwd);
    if (!is_resource($p)) { @unlink($o); @unlink($e); return [127, '', 'could not start ' . $cmd[0]]; }
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
    foreach ($dirs as $d) foreach (['', '.exe'] as $x) {
        $f = rtrim($d, '/\\') . DIRECTORY_SEPARATOR . $name . $x;
        if ($d !== '' && is_file($f)) return $f;
    }
    return null;
}
function norm($s) { return str_replace("\r\n", "\n", $s); }
function isCrlf($raw) { $crlf = substr_count($raw, "\r\n"); $lf = substr_count($raw, "\n"); return $lf > 0 && $crlf * 2 > $lf; }
function toStyle($t, $crlf) { return $crlf ? str_replace("\n", "\r\n", $t) : $t; }

$TMP = sys_get_temp_dir() . '/port_' . getmypid();
@mkdir($TMP, 0777, true);
register_shutdown_function(function () use ($TMP) { foreach ((array)glob($TMP . '/*') as $f) @unlink($f); @rmdir($TMP); });
$TOOLS = ['diff' => findTool('diff'), 'patch' => findTool('patch'), 'node' => findTool('node')];

/** +/- lines of a zero-context diff: the "shape" of the drift between two texts (same as ship.php). */
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
/** Apply the H->W change onto L (all LF-normalised). Returns [result|null, error] (same as ship.php). */
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
    [$c, $out] = run(['git', 'merge-file', '-p', "$TMP/l.txt", "$TMP/h.txt", "$TMP/w.txt"]);
    if ($c !== 0) return [null, 'merge conflict (git merge-file)'];
    return [norm($out), ''];
}
function lintFile($base, $f) {
    global $TOOLS;
    $ext = strtolower(pathinfo($f, PATHINFO_EXTENSION));
    if ($ext === 'php') $cmd = [PHP_BINARY, '-l', "$base/$f"];
    elseif ($ext === 'js' || $ext === 'mjs') { if (!$TOOLS['node']) return null; $cmd = [$TOOLS['node'], '--check', "$base/$f"]; }
    else return null;
    [$c, $out, $err] = run($cmd);
    return $c === 0 ? null : "$f: " . firstLine($err . "\n" . $out);
}
/** File content at a revision, or null if absent. */
function showAt($repo, $rev, $f) { [$c, $out] = git($repo, ['show', "$rev:$f"]); return $c === 0 ? $out : null; }

// ---------- args ----------
$range = ''; $opt = ['liwonde' => '']; $flag = [];
foreach (array_slice($argv, 1) as $a) {
    if (preg_match('/^--([a-z-]+)=(.*)$/s', $a, $m)) $opt[$m[1]] = $m[2];
    elseif (preg_match('/^--([a-z-]+)$/', $a, $m)) $flag[$m[1]] = true;
    elseif ($range === '') $range = $a;
    else fail("unexpected argument: $a");
}
$unknown = array_diff(array_merge(array_keys($opt), array_keys($flag)), ['liwonde', 'apply', 'partial', 'commit', 'push', 'verbose']);
if ($unknown) fail('unknown option: --' . implode(', --', $unknown));
if ($range === '') fail('usage: php scripts/port-commit.php <commit>|<from>..<to> [--apply] [--partial] [--commit] [--push] [--liwonde=path]');
$doCommit = !empty($flag['commit']); $doPush = !empty($flag['push']);
$doApply = !empty($flag['apply']) || $doCommit;
if ($doPush && !$doCommit) fail('--push requires --commit');
$LIW = str_replace('\\', '/', $opt['liwonde'] !== '' ? rtrim($opt['liwonde'], '/\\') : dirname($ROOT) . '/Liwonde_Sun_Hotel_2026');
if (!is_dir("$LIW/.git") && !is_file("$LIW/.git")) fail("Liwonde repo not found: $LIW");

if (strpos($range, '..') !== false) { [$from, $to] = explode('..', $range, 2); }
else { $to = $range; $from = "$range^"; }
foreach (['from' => $from, 'to' => $to] as $k => $rev) {
    [$c, $h] = git($ROOT, ['rev-parse', '--verify', '--quiet', "$rev^{commit}"]);
    if ($c !== 0) fail("unknown Rosalyn's revision: $rev");
    ${$k . 'Hash'} = trim($h);
}
[, $toShort] = git($ROOT, ['rev-parse', '--short', $toHash]);
$toShort = trim($toShort);
[, $subject] = git($ROOT, ['log', '-1', '--format=%s', $toHash]);
$subject = trim($subject);

if ($doApply) {
    [, $b] = git($LIW, ['rev-parse', '--abbrev-ref', 'HEAD']);
    if (trim($b) !== 'main') fail("Liwonde is on '" . trim($b) . "', not main");
}

// ---------- plan ----------
[$c, $out] = git($ROOT, ['diff', '--name-status', '--no-renames', '-z', $fromHash, $toHash]);
if ($c !== 0) fail('git diff failed at Rosalyn\'s');
$ent = array_values(array_filter(explode("\0", $out), 'strlen'));
$plan = []; $counts = [];
for ($i = 0; $i + 1 < count($ent); $i += 2) {
    $st = $ent[$i][0]; $f = $ent[$i + 1];
    $label = ''; $note = ''; $write = null; $expect = null; $bin = false;
    foreach ($FORBIDDEN as $re => $why) if (preg_match($re, $f)) { $label = 'SKIP'; $note = $why; break; }
    if ($label === '' && $st === 'D') { $label = 'DELETE'; $note = 'deleted at Rosalyn\'s - never deleted here, do it by hand'; }
    if ($label === '') {
        $lp = "$LIW/$f";
        if (is_file($lp)) {
            [, $dirty] = git($LIW, ['status', '--porcelain', '--', $f]);
            if (trim($dirty) !== '') { $label = 'FAIL'; $note = 'Liwonde has uncommitted changes to this file (someone is working on it)'; }
        }
    }
    if ($label === '') {
        $Hraw = $st === 'A' ? null : showAt($ROOT, $fromHash, $f);
        $Wraw = (string)showAt($ROOT, $toHash, $f);
        $Lraw = is_file($lp) ? (string)file_get_contents($lp) : null;
        $crlf = $Lraw !== null && isCrlf($Lraw);
        $bin = strpos($Wraw . (string)$Hraw . (string)$Lraw, "\0") !== false;
        if ($bin) {
            $expect = $Wraw;
            if ($Lraw === $Wraw) { $label = 'SAME'; }
            elseif ($Lraw === null || $Lraw === $Hraw) { $label = 'COPY'; $write = $Wraw; }
            else { $label = 'FAIL'; $note = 'binary file differs in Liwonde'; }
        } else {
            $W = norm($Wraw); $H = $Hraw === null ? null : norm($Hraw); $L = $Lraw === null ? null : norm($Lraw);
            $expect = $W;
            if ($L === $W) { $label = 'SAME'; }
            elseif ($L === null) { $label = 'COPY'; $note = 'new in Liwonde'; $write = toStyle($W, isCrlf($Wraw)); }
            elseif ($H === null) { $label = 'FAIL'; $note = 'added at Rosalyn\'s but a different file already exists in Liwonde'; }
            elseif ($L === $H) { $label = 'COPY'; $write = toStyle($W, $crlf); }
            else {
                [$R, $err] = applyChange($H, $W, $L);
                if ($R === null) {
                    // Already ported? Undo the change on Liwonde's file and redo it: if that gives Liwonde's file back, it is in.
                    [$R0] = applyChange($W, $H, $L);
                    $back = $R0 === null ? null : applyChange($H, $W, $R0)[0];
                    if ($back !== null && $back === $L) { $label = 'SAME'; $note = 'already ported (Liwonde-only lines kept)'; }
                    else { $label = 'FAIL'; $note = $err; }
                }
                else {
                    $expect = $R;
                    if ($R === $W) $label = 'PATCH';
                    elseif (diffSig($H, $L) === diffSig($W, $R)) { $label = 'DRIFT-OK'; $note = 'Liwonde-only lines kept'; }
                    else { $label = 'FAIL'; $note = 'patch would change Liwonde-only lines'; }
                    if ($label !== 'FAIL') { if ($R !== $L) $write = toStyle($R, $crlf); else $label = 'SAME'; }
                }
            }
        }
    }
    $plan[$f] = compact('label', 'note', 'write', 'expect', 'bin');
    $counts[$label] = ($counts[$label] ?? 0) + 1;
}
if (!$plan) fail("no file changes in $range");

$failed = isset($counts['FAIL']);
$parts = []; foreach ($counts as $l => $n) $parts[] = "$l $n";
say(($failed ? 'FAIL' : 'OK  ') . " plan  $range -> Liwonde: " . implode(', ', $parts) . ($doApply ? '' : ' (dry run)'));
$verbose = !empty($flag['verbose']) || $failed || count($plan) <= 12;
foreach ($plan as $f => $p) {
    if (!$verbose && in_array($p['label'], ['SAME', 'SKIP', 'COPY'], true)) continue;
    say(sprintf('     %-8s %s%s', $p['label'], $f, $p['note'] ? " ({$p['note']})" : ''));
}
if ($failed && empty($flag['partial'])) {
    say('NOTHING WRITTEN: fix or hand the FAIL files to an agent, or re-run with --partial to write the clean ones.');
    exit(1);
}
if (!$doApply) { say('DRY RUN: add --apply to write.'); exit($failed ? 1 : 0); }

// ---------- write + verify + lint ----------
$written = [];
foreach ($plan as $f => $p) {
    if ($p['write'] === null || $p['label'] === 'FAIL') continue;
    $lp = "$LIW/$f";
    if (!is_dir(dirname($lp)) && !@mkdir(dirname($lp), 0777, true)) fail("cannot create dir for $f");
    if (file_put_contents($lp, $p['write']) === false) fail("cannot write $f");
    $disk = (string)file_get_contents($lp);
    if (($p['bin'] ? $disk : norm($disk)) !== $p['expect']) fail("verify: Liwonde $f differs after write");
    $written[] = $f;
}
foreach ($written as $f) { $r = lintFile($LIW, $f); if ($r !== null) fail("lint (Liwonde): $r"); }
say('OK   write ' . count($written) . ' file(s) written, verified, linted' . ($failed ? ' | LEFT FOR AGENT: ' . implode(', ', array_keys(array_filter($plan, function ($p) { return $p['label'] === 'FAIL'; }))) : ''));

// ---------- commit / push (Liwonde only, written files only) ----------
if ($doCommit && $written) {
    [, $ahead] = git($LIW, ['rev-list', '--count', 'origin/main..HEAD']);
    $aheadBefore = (int)trim($ahead);
    $msg = "$subject (port of Rosalyn's $toShort" . ($failed ? ', partial' : '') . ')';
    [$c, $o, $e] = git($LIW, array_merge(['add', '--'], $written));
    if ($c !== 0) fail('git add: ' . firstLine($e . $o));
    [$c, $o, $e] = git($LIW, array_merge(['commit', '-q', '-m', $msg, '--'], $written));
    if ($c !== 0) fail('commit: ' . firstLine($e . $o));
    [, $h] = git($LIW, ['rev-parse', '--short', 'HEAD']);
    say('OK   commit Liwonde ' . trim($h) . " \"$msg\"");
    if ($doPush) {
        if ($aheadBefore > 0) say("SKIP push: Liwonde was already $aheadBefore commit(s) ahead of origin before this port - push those deliberately");
        else {
            [$c, $o, $e] = git($LIW, ['push', 'origin', 'main']);
            if ($c !== 0) fail('push: ' . firstLine($e . $o));
            say('OK   push Liwonde origin/main');
        }
    }
} elseif ($doCommit) say('SKIP commit: nothing written');
exit($failed ? 1 : 0);
