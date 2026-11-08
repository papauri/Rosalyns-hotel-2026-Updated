<?php
/**
 * Claude Code hook (PreToolUse on Read): refuse reads inside folders the project never reads
 * (CLAUDE.md: vendor/, PHPMailer/, logs/, cache/, backups/, images/, Database/) — they waste tokens or hold
 * generated/private data. Only paths inside this repo are checked; everything else passes.
 */
$in = json_decode((string)stream_get_contents(STDIN), true);
$f = (string)($in['tool_input']['file_path'] ?? '');
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
    echo json_encode(['hookSpecificOutput' => [
        'hookEventName' => 'PreToolUse',
        'permissionDecision' => 'deny',
        'permissionDecisionReason' => 'Project rule (CLAUDE.md): never read vendor/, PHPMailer/, logs/, cache/, backups/, images/, Database/. Use the app code, CODE_INDEX or a targeted query instead.',
    ]]);
}
exit(0);
