<?php
if (PHP_SAPI !== 'cli') { http_response_code(403); exit("CLI only.\n"); } // never runnable over the web

/**
 * One-line live progress for work in this repo (owner 2026-10-30).
 *   php scripts/progress.php                       one line, updated in place every 2 s (Ctrl+C to stop)
 *   php scripts/progress.php --once                print the line once
 *   php scripts/progress.php plan "A" "B" "C"      set the steps of the current job
 *   php scripts/progress.php next                  mark the current step done
 * Runs: .claude/progress/<name>.log is a run's output (or <name>.path holds the full path of a log kept
 * elsewhere). Checks are counted from PASS / FAIL / "OK (n passed" lines; the total and the time left come
 * from the last finished run of the same name (<name>.total), so the first run of a kind shows no total.
 */

$DIR = dirname(__DIR__) . '/.claude/progress';
if (!is_dir($DIR)) {
    @mkdir($DIR, 0775, true);
}
$STEPS = $DIR . '/steps.json';
$cmd = $argv[1] ?? '';

$readSteps = static fn(): array => is_file($STEPS) ? (json_decode((string)file_get_contents($STEPS), true) ?: ['steps' => [], 'done' => 0]) : ['steps' => [], 'done' => 0];
if ($cmd === 'plan') {
    $steps = array_values(array_filter(array_map('trim', array_slice($argv, 2)), 'strlen'));
    file_put_contents($STEPS, json_encode(['steps' => $steps, 'done' => 0]));
    exit(0);
}
if ($cmd === 'next') {
    $s = $readSteps();
    $s['done'] = min(count($s['steps']), (int)$s['done'] + 1);
    file_put_contents($STEPS, json_encode($s));
    exit(0);
}

$tty = function_exists('stream_isatty') && stream_isatty(STDOUT);
$mmss = static fn(int $s): string => $s >= 3600 ? intdiv($s, 3600) . 'h' . str_pad((string)intdiv($s % 3600, 60), 2, '0', STR_PAD_LEFT) . 'm' : ($s >= 60 ? intdiv($s, 60) . 'm' . str_pad((string)($s % 60), 2, '0', STR_PAD_LEFT) . 's' : $s . 's');

/** The newest run: name, checks passed/failed, finished?, total and time left when known. */
$run = static function () use ($DIR, $mmss): string {
    $logs = [];
    foreach (glob($DIR . '/*.log') ?: [] as $f) {
        $logs[basename($f, '.log')] = $f;
    }
    foreach (glob($DIR . '/*.path') ?: [] as $p) {
        $t = trim((string)file_get_contents($p));
        if ($t !== '' && is_file($t)) {
            $logs[basename($p, '.path')] = $t;
        }
    }
    if (!$logs) {
        return 'no run';
    }
    uasort($logs, static fn($a, $b) => filemtime($b) <=> filemtime($a));
    $name = (string)array_key_first($logs);
    $f = $logs[$name];
    $txt = (string)file_get_contents($f);
    $pass = preg_match_all('/^(PASS|OK)\b/m', $txt) + array_sum(array_map('intval', preg_match_all('/\bOK\s+\((\d+) passed/', $txt, $m) ? $m[1] : []));
    $fail = preg_match_all('/^FAIL\b/m', $txt);
    $n = $pass + $fail;
    $finished = preg_match('/(\d+) passed, (\d+) failed|All suites passed|^DONE /m', $txt) === 1;
    $totalFile = $DIR . '/' . $name . '.total';
    if ($finished && $n > 0) {
        file_put_contents($totalFile, (string)$n);
    }
    $total = is_file($totalFile) ? (int)file_get_contents($totalFile) : 0;
    $elapsed = max(1, time() - (int)filectime($f));
    $failTxt = $fail ? ", {$fail} FAILED" : '';
    if ($finished) {
        return "{$name}: finished, {$pass} passed{$failTxt}";
    }
    $quiet = time() - filemtime($f);
    if ($total > $n) {
        $left = (int)round($elapsed / max(1, $n) * ($total - $n));
        return sprintf('%s: %d/%d (%d%%)%s, %d left, %s', $name, $n, $total, intdiv($n * 100, $total), $failTxt, $total - $n,
            $quiet > 60 ? 'on a slow check, no output for ' . $mmss($quiet) : '~' . $mmss($left) . ' to go');
    }
    return "{$name}: {$n} checks{$failTxt}" . ($total ? ' (past last total, finishing)' : '') . ($quiet > 60 ? ', no output for ' . $mmss($quiet) : '') . ', running ' . $mmss($elapsed);
};

$line = static function () use ($run, $readSteps): string {
    $s = $readSteps();
    $steps = $s['steps'];
    $done = (int)$s['done'];
    $stepTxt = '';
    if ($steps) {
        $stepTxt = $done >= count($steps)
            ? ' | all ' . count($steps) . ' steps done'
            : ' | step ' . ($done + 1) . '/' . count($steps) . ': ' . $steps[$done] . (count($steps) > $done + 1 ? ' | left: ' . implode(', ', array_slice($steps, $done + 1)) : '');
    }
    return date('H:i:s') . ' ' . $run() . $stepTxt;
};

if (in_array('--once', $argv, true) || !$tty) {
    echo $line(), "\n";
    exit(0);
}
$cols = (int)(getenv('COLUMNS') ?: 120);
while (true) {
    echo "\r\033[2K", mb_strimwidth($line(), 0, max(40, $cols - 1), '...');
    sleep(2);
}
