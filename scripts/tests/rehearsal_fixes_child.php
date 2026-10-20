<?php
if (PHP_SAPI !== 'cli') { http_response_code(403); exit("CLI only.\n"); }
/** In-process scenarios for scripts/tests/rehearsal_fixes.php (run by page_harness.php, rolled back). */
$sc = (string)($_POST['scenario'] ?? '');
require_once $root . '/config/email.php';
$out = ['scenario' => $sc];
switch ($sc) {
    case 'cc':
        $set = function (string $v) use ($pdo) {
            $pdo->prepare("DELETE FROM email_settings WHERE setting_key = 'cc_emails'")->execute();
            $pdo->prepare("INSERT INTO email_settings (setting_key, setting_value, is_encrypted) VALUES ('cc_emails', ?, 0)")->execute([$v]);
        };
        $pdo->prepare("DELETE FROM email_settings WHERE setting_key = 'cc_emails'")->execute();
        $out['none'] = getCCEmails();
        $set(json_encode(['a@example.com', 'not-an-email', 'b@example.com']));
        $out['json'] = getCCEmails();
        $set('c@example.com, d@example.com');
        $out['csv'] = getCCEmails();
        // A broken lookup must never throw: point the lookup at a connection that fails every query
        $keep = $GLOBALS['pdo'];
        $GLOBALS['pdo'] = new class extends PDO {
            public function __construct() {}
            #[\ReturnTypeWillChange]
            public function prepare($query, $options = []) { throw new PDOException('boom'); }
        };
        try { $out['broken'] = getCCEmails(); } catch (Throwable $e) { $out['broken'] = 'THREW ' . $e->getMessage(); }
        $GLOBALS['pdo'] = $keep;
        break;
}
echo json_encode($out);
