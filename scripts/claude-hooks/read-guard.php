<?php
/**
 * Claude Code hook (PreToolUse on Read):
 * 1. refuse reads inside folders the project never reads (CLAUDE.md: vendor/, PHPMailer/, logs/, cache/, backups/,
 *    images/, Database/) — they waste tokens or hold generated/private data.
 * 2. (owner 2026-11-17, token rule 4) refuse a WHOLE read of a big text file (over BIG_LINES lines or BIG_BYTES, no offset/limit):
 *    Grep `.claude/CODE_INDEX.md` or the file first, then Read with offset/limit around the lines needed.
 * Only paths inside this repo are checked; everything else passes.
 */
const BIG_LINES = 400;
const BIG_BYTES = 40000; // ~10k tokens: long-line trackers (TASKS.md is 256 lines but 235 KB)

function deny($why) {
    echo json_encode(['hookSpecificOutput' => [
        'hookEventName' => 'PreToolUse',
        'permissionDecision' => 'deny',
        'permissionDecisionReason' => $why,
    ]]);
    exit(0);
}

$in = json_decode((string)stream_get_contents(STDIN), true);
$ti = (array)($in['tool_input'] ?? []);
$f = (string)($ti['file_path'] ?? '');
if ($f === '') {
    exit(0);
}
$root = str_replace('\\', '/', strtolower(dirname(__DIR__, 2))) . '/';
$p = str_replace('\\', '/', strtolower($f));
if (strpos($p, $root) !== 0) {
    exit(0);
}
$rel = substr($p, strlen($root));
if (preg_match('#^(vendor|phpmailer|logs|cache|backups|images|database)/#', $rel)) {
    deny('Project rule (CLAUDE.md): never read vendor/, PHPMailer/, logs/, cache/, backups/, images/, Database/. Use the app code, CODE_INDEX or a targeted query instead.');
}

// 2. Whole read of a big text file? (any offset/limit/pages = a targeted read, allowed)
if (isset($ti['offset']) || isset($ti['limit']) || isset($ti['pages'])) {
    exit(0);
}
if (!preg_match('/\.(php|js|mjs|css|html?|md|txt|json|sql|csv|xml|ya?ml|ini|sh|ps1)$/', $rel) || !is_file($f)) {
    exit(0);
}
$size = @filesize($f);
if ($size === false || $size < 12000) { // too short to pass the line limit: skip the count
    exit(0);
}
$lines = 0;
$h = @fopen($f, 'rb');
if ($h) {
    while (!feof($h)) {
        $lines += substr_count((string)fread($h, 65536), "\n");
    }
    fclose($h);
}
if ($lines > BIG_LINES || $size > BIG_BYTES) {
    deny("Token rule 4 (owner 2026-11-17): $rel has $lines lines / " . round($size / 1024) . " KB; whole reads of files over " . BIG_LINES
        . ' lines or ' . (BIG_BYTES / 1000) . ' KB are blocked. Grep .claude/CODE_INDEX.md or Grep this file for the function or section you need,'
        . ' then Read it with offset and limit (about 150-300 lines). Several targeted reads are fine.');
}
exit(0);
