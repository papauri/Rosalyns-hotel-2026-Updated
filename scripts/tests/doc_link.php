<?php
if (PHP_SAPI !== 'cli') { http_response_code(403); exit("CLI only.\n"); } // never runnable over the web
/**
 * Private expiring document link test. Usage (from the repo root): php scripts/tests/doc_link.php
 * Covers includes/doc-links.php and invoice-view.php: a valid token resolves to the right file;
 * edited type/id/expiry/signature, an expired token, another booking's id and a stored path that
 * escapes invoices/ are all refused. Throwaway bookings are made inside one transaction that is
 * rolled back; nothing is left behind (the signing secret comes from APP_ENCRYPTION_SALT when set).
 */
$root = dirname(__DIR__, 2);
chdir($root);
$pass = 0; $fail = 0;
function ok(string $l): void { global $pass; $pass++; echo "PASS $l\n"; }
function bad(string $l, string $d = ''): void { global $fail; $fail++; echo "FAIL $l" . ($d !== '' ? ": $d" : '') . "\n"; }
function check(bool $c, string $l, string $d = ''): bool { $c ? ok($l) : bad($l, $d); return $c; }

require_once $root . '/config/database.php';
require_once $root . '/config/base-url.php';
require_once $root . '/includes/doc-links.php';

$salt = (string)($_ENV['APP_ENCRYPTION_SALT'] ?? getenv('APP_ENCRYPTION_SALT') ?: '');
if ($salt === '') {
    // No salt: a secret would be created in site_settings. Do it once, outside the rollback, and say so.
    echo "NOTE APP_ENCRYPTION_SALT not set: signing secret is kept in site_settings (doc_link_secret)\n";
}
check(rh_doc_link_secret() !== '', 'a signing secret is available');

$roomId = (int)$pdo->query("SELECT id FROM rooms ORDER BY id LIMIT 1")->fetchColumn();
$pdfs = glob($root . '/invoices/*.pdf') ?: [];
if ($roomId <= 0 || !$pdfs) { bad('Fixture: a room and one invoice PDF exist'); echo "\n$pass passed, $fail failed\n"; exit(1); }
$pdfRel = 'invoices/' . basename($pdfs[0]);
$pdfReal = realpath($pdfs[0]);

$insBooking = static function (string $ref) use ($pdo, $roomId): int {
    $pdo->prepare("INSERT INTO bookings (booking_reference, room_id, guest_name, guest_email, guest_phone, check_in_date, check_out_date, number_of_nights, total_amount)
                   VALUES (?, ?, 'Doc Link Test', 'doclink@example.invalid', '000', CURDATE(), CURDATE() + INTERVAL 1 DAY, 1, 100)")->execute([$ref, $roomId]);
    return (int)$pdo->lastInsertId();
};
$setPath = static function (int $id, ?string $path) use ($pdo): void {
    $pdo->prepare("UPDATE bookings SET final_invoice_path = ? WHERE id = ?")->execute([$path, $id]);
};

$pdo->beginTransaction();
try {
    $b1 = $insBooking('DLT' . random_int(100000, 999999));
    $b2 = $insBooking('DLT' . random_int(100000, 999999));
    $setPath($b1, $pdfRel);
    $setPath($b2, $pdfRel);

    // ---- valid token ----
    $url = rh_doc_signed_url('booking_invoice', $b1, 30);
    check($url !== '' && strpos($url, '/invoice-view.php?t=') !== false, 'signed url is built', $url);
    $tok = preg_match('/[?&]t=([^&]+)$/', $url, $m) ? rawurldecode($m[1]) : ''; // CLI has no real host, so no parse_url
    $r = rh_doc_link_resolve($tok);
    check(!empty($r['ok']) && $r['file'] === $pdfReal, 'valid token resolves to the right file', json_encode($r));

    // ---- tampering ----
    [$type, $id, $exp, $sig] = explode('.', $tok);
    check(empty(rh_doc_link_resolve("receipt.$id.$exp.$sig")['ok']), 'edited type refused');
    check(empty(rh_doc_link_resolve("booking_invoice.$b2.$exp.$sig")['ok']), "another booking's id with the first token refused");
    check(empty(rh_doc_link_resolve("booking_invoice.$id." . ($exp + 86400 * 365) . ".$sig")['ok']), 'extended expiry refused');
    check(empty(rh_doc_link_resolve("booking_invoice.$id.$exp." . strrev($sig))['ok']), 'edited signature refused');
    check(empty(rh_doc_link_resolve("booking_invoice.$id.$exp." . substr($sig, 0, 63))['ok']), 'short signature refused');
    check(empty(rh_doc_link_resolve($tok . '.x')['ok']) && empty(rh_doc_link_resolve('')['ok']) && empty(rh_doc_link_resolve('garbage')['ok']), 'malformed tokens refused');

    // ---- expiry ----
    $past = time() - 60;
    $oldTok = "booking_invoice.$b1.$past." . rh_doc_link_sign('booking_invoice', $b1, $past);
    check(empty(rh_doc_link_resolve($oldTok)['ok']), 'expired token refused');
    $fut = time() + 29 * 86400;
    $futTok = "booking_invoice.$b1.$fut." . rh_doc_link_sign('booking_invoice', $b1, $fut);
    check(!empty(rh_doc_link_resolve($futTok)['ok']), 'a token inside 30 days still works');
    $days = (int)round(((int)$exp - time()) / 86400);
    check($days === 30, 'default expiry is 30 days', (string)$days);

    // ---- stored path outside invoices/ or not a PDF ----
    foreach (['../config/database.php', 'invoices/../config/database.php', 'invoices/../.env', $pdfReal, 'invoices/invoice-japandi-template.html', 'invoices/missing.pdf', ''] as $badPath) {
        $setPath($b1, $badPath);
        $label = $badPath === '' ? '(empty)' : (strlen($badPath) > 40 ? '(absolute path)' : $badPath);
        check(empty(rh_doc_link_resolve($tok)['ok']), 'stored path refused: ' . $label);
    }
    // ---- cancelled / deleted booking: the link stops working at once (owner 2026-10-22) ----
    $setPath($b1, $pdfRel);
    check(!empty(rh_doc_link_resolve($tok)['ok']), 'link works again once the path is restored');
    $pdo->prepare("UPDATE bookings SET status = 'cancelled' WHERE id = ?")->execute([$b1]);
    check(empty(rh_doc_link_resolve($tok)['ok']), 'cancelled booking: link refused');
    $pdo->prepare("UPDATE bookings SET status = 'confirmed', deleted_at = NOW() WHERE id = ?")->execute([$b1]);
    check(empty(rh_doc_link_resolve($tok)['ok']), 'deleted booking: link refused');
    $pdo->prepare("UPDATE bookings SET deleted_at = NULL WHERE id = ?")->execute([$b1]);
    $setPath($b1, null);
    check(empty(rh_doc_link_resolve($tok)['ok']), 'booking without an invoice refused');
    check(rh_doc_signed_url('receipt', $b1) === '' && rh_doc_signed_url('booking_invoice', 0) === '', 'unknown type / bad id gives no url');
} finally {
    $pdo->rollBack();
}

// ---- the real endpoint (child process; code goes through a temp file so quoting is portable) ----
$runPhp = static function (string $code): string {
    $f = tempnam(sys_get_temp_dir(), 'dlt');
    file_put_contents($f, "<?php\n" . $code);
    // proc_open pipes are binary-safe (shell_exec stops at a Ctrl-Z byte on Windows, which a PDF contains)
    $p = proc_open([PHP_BINARY, $f], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
    $o = '';
    if (is_resource($p)) {
        $o = (string)stream_get_contents($pipes[1]) . (string)stream_get_contents($pipes[2]);
        fclose($pipes[1]); fclose($pipes[2]); proc_close($p);
    }
    @unlink($f);
    return $o;
};
$run = static function (string $token) use ($root, $runPhp): string {
    $code = '$_GET["t"] = ' . var_export($token, true) . '; $_SERVER["REMOTE_ADDR"] = "10.254.254." . random_int(1, 250); require ' . var_export($root . '/invoice-view.php', true) . ';';
    return $runPhp($code);
};
$out = $run($oldTok);
check(stripos($out, 'This link has expired') !== false && stripos($out, 'contact the hotel') !== false, 'endpoint shows the friendly expired page');
check(stripos($out, 'SELECT') === false && stripos($out, 'signature') === false && stripos($out, 'Fatal') === false, 'expired page leaks no detail');
$out = $run('booking_invoice.1.9999999999.' . str_repeat('a', 64));
check(stripos($out, 'This link has expired') !== false, 'endpoint refuses a forged token');

// Valid link streamed by the real endpoint: the child makes its own throwaway booking in a transaction it
// rolls back at shutdown (invoice-view.php exits), so nothing is left behind.
$pdfBytes = (string)file_get_contents($pdfReal);
$code = 'require ' . var_export($root . '/config/database.php', true) . '; $pdo->beginTransaction(); '
    . 'register_shutdown_function(function () use ($pdo) { if ($pdo->inTransaction()) { $pdo->rollBack(); } }); '
    . '$pdo->prepare("INSERT INTO bookings (booking_reference, room_id, guest_name, guest_email, guest_phone, check_in_date, check_out_date, number_of_nights, total_amount, final_invoice_path) VALUES (?, ?, \'T\', \'t@example.invalid\', \'0\', CURDATE(), CURDATE() + INTERVAL 1 DAY, 1, 1, ?)")'
    . '->execute([\'DLS\' . random_int(100000, 999999), ' . $roomId . ', ' . var_export($pdfRel, true) . ']); '
    . 'require ' . var_export($root . '/includes/doc-links.php', true) . '; '
    . '$_GET["t"] = rh_doc_link_token("booking_invoice", (int)$pdo->lastInsertId(), 1); $_SERVER["REMOTE_ADDR"] = "10.253.253." . random_int(1, 250); '
    . 'require ' . var_export($root . '/invoice-view.php', true) . ';';
$out = $runPhp($code);
check($out === $pdfBytes && strncmp($out, '%PDF', 4) === 0, 'endpoint streams the stored PDF byte for byte', substr($out, 0, 160));
$left = (int)$pdo->query("SELECT COUNT(*) FROM bookings WHERE booking_reference LIKE 'DLT%' OR booking_reference LIKE 'DLS%'")->fetchColumn();
check($left === 0, 'no test bookings left behind', (string)$left);

echo "\n$pass passed, $fail failed\n";
exit($fail ? 1 : 0);
