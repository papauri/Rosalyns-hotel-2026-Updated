<?php
/**
 * Claude Code hook (PostToolUse on Edit|Write): run `php -l` on an edited .php file.
 * Silent on success. On a syntax error it exits 2 with the error on stderr, so the agent sees it at once.
 * Never blocks anything else; any problem reading the payload just exits 0.
 */
$in = json_decode((string)stream_get_contents(STDIN), true);
$f = (string)($in['tool_input']['file_path'] ?? ($in['tool_response']['filePath'] ?? ''));
if ($f === '' || strtolower(substr($f, -4)) !== '.php' || !is_file($f)) {
    exit(0);
}
$out = [];
$code = 0;
exec(escapeshellarg(PHP_BINARY) . ' -l ' . escapeshellarg($f) . ' 2>&1', $out, $code);
if ($code !== 0) {
    fwrite(STDERR, "php -l failed for $f:\n" . implode("\n", $out) . "\n");
    exit(2);
}
exit(0);
