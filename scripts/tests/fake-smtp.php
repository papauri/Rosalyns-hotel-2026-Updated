<?php
if (PHP_SAPI !== 'cli') { http_response_code(403); exit("CLI only.\n"); }
/**
 * Minimal capture-only SMTP server for tests (never relays anything).
 * Usage: php scripts/tests/fake-smtp.php <port> <spool-file>
 * Accepts every message, appends one JSON line per message to the spool file:
 *   {"to":[...],"subject":"...","attachments":["x.pdf"],"bytes":123}
 * scripts/tests/documents_flow.php starts it, points the (rolled-back) SMTP settings at it through
 * the page harness, and reads the spool to see exactly which emails a payment path sends.
 */
$port = (int)($argv[1] ?? 0);
$spool = (string)($argv[2] ?? '');
if ($port < 1 || $spool === '') { fwrite(STDERR, "usage: fake-smtp.php <port> <spool>\n"); exit(2); }
$srv = @stream_socket_server('tcp://127.0.0.1:' . $port, $errno, $errstr);
if (!$srv) { fwrite(STDERR, "listen failed: $errstr\n"); exit(1); }
file_put_contents($spool . '.ready', '1');

while (true) {
    $conn = @stream_socket_accept($srv, 3600);
    if (!$conn) { continue; }
    stream_set_timeout($conn, 20);
    fwrite($conn, "220 fake-smtp ready\r\n");
    $rcpt = [];
    while (($line = fgets($conn)) !== false) {
        $cmd = strtoupper(substr(trim($line), 0, 4));
        if ($cmd === 'EHLO' || $cmd === 'HELO') {
            fwrite($conn, "250-fake-smtp\r\n250-AUTH LOGIN PLAIN\r\n250 8BITMIME\r\n");
        } elseif ($cmd === 'AUTH') {
            if (stripos($line, 'LOGIN') !== false && trim($line) === 'AUTH LOGIN') {
                fwrite($conn, "334 VXNlcm5hbWU6\r\n");
                fgets($conn);
                fwrite($conn, "334 UGFzc3dvcmQ6\r\n");
                fgets($conn);
            }
            fwrite($conn, "235 ok\r\n");
        } elseif ($cmd === 'MAIL') {
            $rcpt = [];
            fwrite($conn, "250 ok\r\n");
        } elseif ($cmd === 'RCPT') {
            if (preg_match('/<([^>]+)>/', $line, $m)) { $rcpt[] = $m[1]; }
            fwrite($conn, "250 ok\r\n");
        } elseif ($cmd === 'DATA') {
            fwrite($conn, "354 go\r\n");
            $data = '';
            while (($l = fgets($conn)) !== false) {
                if ($l === ".\r\n" || $l === ".\n") { break; }
                $data .= $l;
            }
            [$head] = explode("\r\n\r\n", $data, 2) + [''];
            $head = preg_replace("/\r\n[ \t]+/", ' ', $head);
            $subject = '';
            if (preg_match('/^Subject:\s*(.*)$/mi', $head, $m)) {
                $subject = trim($m[1]);
                if (function_exists('iconv_mime_decode')) { $subject = iconv_mime_decode($subject, 0, 'UTF-8') ?: $subject; }
            }
            $atts = [];
            if (preg_match_all('/filename="?([^"\r\n;]+)"?/i', $data, $mm)) { $atts = array_values(array_unique(array_map('trim', $mm[1]))); }
            file_put_contents($spool, json_encode(['to' => $rcpt, 'subject' => $subject, 'attachments' => $atts, 'bytes' => strlen($data)]) . "\n", FILE_APPEND | LOCK_EX);
            fwrite($conn, "250 queued\r\n");
        } elseif ($cmd === 'QUIT') {
            fwrite($conn, "221 bye\r\n");
            break;
        } else {
            fwrite($conn, "250 ok\r\n");
        }
    }
    fclose($conn);
}
