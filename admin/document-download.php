<?php
/**
 * Permission-checked download of generated documents (invoices, receipts, credit notes,
 * quotations, booking final invoices). The invoices/ and quotations/ folders are denied to the
 * web (.htaccess), so this is the only way staff open a stored PDF.
 *
 * GET type = invoice | receipt | credit_note | quotation | booking_invoice, id = row id.
 * The file path always comes from the database row, never from the request, and must resolve
 * inside the document folder.
 */
require_once __DIR__ . '/admin-init.php';

$type = (string)($_GET['type'] ?? '');
$id   = (int)($_GET['id'] ?? 0);

// type => [table SQL, path column, any-of permissions, page used for the module gate, folder]
$map = [
    'invoice'         => ['SELECT invoice_path AS p FROM payments WHERE id = ?', ['invoices', 'bookings'], 'invoices.php', 'invoices'],
    'receipt'         => ['SELECT receipt_path AS p FROM payments WHERE id = ?', ['receipts'], 'receipts.php', 'invoices'],
    'credit_note'     => ['SELECT pdf_path AS p FROM credit_notes WHERE id = ?', ['invoices'], 'credit-notes.php', 'invoices'],
    'quotation'       => ['SELECT pdf_path AS p FROM quotations WHERE id = ?', ['create_booking'], 'quotations.php', 'quotations'],
    'booking_invoice' => ['SELECT final_invoice_path AS p FROM bookings WHERE id = ?', ['bookings'], 'booking-details.php', 'invoices'],
];

function rh_doc_deny(int $code, string $msg): void
{
    http_response_code($code);
    header('Content-Type: text/plain; charset=utf-8');
    header('Cache-Control: no-store');
    echo $msg;
    exit;
}

if (!isset($map[$type]) || $id <= 0) {
    rh_doc_deny(400, 'Invalid request.');
}
[$sql, $perms, $modulePage, $folder] = $map[$type];

if (!hasAnyPermission((int)$user['id'], $perms)) {
    rh_doc_deny(403, 'You do not have permission to open this document.');
}
$module = getModuleForPage($modulePage);
if ($module !== null && ($user['role'] ?? '') !== 'admin') {
    foreach ((is_array($module) ? $module : [$module]) as $mk) {
        if (!rh_module_key_enabled((string)$mk)) {
            rh_doc_deny(403, 'This feature is switched off in Modules.');
        }
    }
}

try {
    $st = $pdo->prepare($sql);
    $st->execute([$id]);
    $rel = (string)($st->fetchColumn() ?: '');
} catch (Throwable $e) {
    error_log('[document-download] ' . $e->getMessage());
    rh_doc_deny(500, 'Could not look up the document.');
}
if ($rel === '') {
    rh_doc_deny(404, 'Document not found.');
}

$root = realpath(dirname(__DIR__) . '/' . $folder);
$file = realpath(dirname(__DIR__) . '/' . ltrim(str_replace('\\', '/', $rel), '/'));
if ($root === false || $file === false || strpos($file, $root . DIRECTORY_SEPARATOR) !== 0 || !is_file($file)) {
    rh_doc_deny(404, 'Document file not found.');
}
$ext = strtolower(pathinfo($file, PATHINFO_EXTENSION));
if ($ext !== 'pdf' && $ext !== 'html') {
    rh_doc_deny(404, 'Document file not found.');
}

$name = preg_replace('/[^A-Za-z0-9._-]+/', '_', basename($file));
header('Content-Type: ' . ($ext === 'pdf' ? 'application/pdf' : 'text/html; charset=utf-8'));
header('Content-Disposition: inline; filename="' . $name . '"');
header('Content-Length: ' . filesize($file));
header('Cache-Control: private, no-store');
header('X-Content-Type-Options: nosniff');
if ($ext === 'html') {
    header("Content-Security-Policy: sandbox");
}
readfile($file);
exit;
