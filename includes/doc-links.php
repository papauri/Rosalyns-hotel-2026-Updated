<?php
/**
 * Private, expiring links to one guest document (used by WhatsApp; reusable elsewhere).
 *
 * The invoices/ and quotations/ folders are denied to the web, so a guest cannot open a static
 * file URL. Instead we hand out invoice-view.php?t=<token>. The token is signed
 * (HMAC-SHA256 over document type, booking id and expiry) so it cannot be guessed, edited, or
 * reused for another booking. No database table: the file path always comes from the booking row.
 *
 *   rh_doc_signed_url('booking_invoice', $bookingId)  -> full URL, or '' when it cannot be made
 *   rh_doc_link_resolve($token)                       -> ['ok'=>bool, 'file'=>abs path, 'name'=>download name]
 */

/** type => [SQL returning the stored relative path, document folder under the site root] */
function rh_doc_link_types(): array
{
    return [
        // A cancelled or deleted booking's link stops working at once (owner 2026-10-22).
        'booking_invoice' => ["SELECT final_invoice_path FROM bookings WHERE id = ? AND status <> 'cancelled' AND deleted_at IS NULL", 'invoices'],
    ];
}

/**
 * Signing secret: its own long random value, generated once per installation and kept in
 * site_settings ('doc_link_secret', never exposed by the settings API). It is deliberately NOT
 * derived from APP_ENCRYPTION_SALT: that value can be a readable phrase and is shared with other
 * encryption, so anyone who guessed it could forge links.
 */
function rh_doc_link_secret(): string
{
    static $secret = null;
    if ($secret !== null) {
        return $secret;
    }
    global $pdo;
    $stored = '';
    try {
        // Read the table directly (not getSetting) so a half-cached value is never trusted.
        $q = $pdo->prepare("SELECT setting_value FROM site_settings WHERE setting_key = 'doc_link_secret'");
        $q->execute();
        $stored = (string)($q->fetchColumn() ?: '');
        if (strlen($stored) < 64) {
            // INSERT IGNORE: if two requests race, the first row wins and both read it back.
            $pdo->prepare("INSERT IGNORE INTO site_settings (setting_key, setting_value) VALUES ('doc_link_secret', ?)")
                ->execute([bin2hex(random_bytes(32))]);
            $q->execute();
            $stored = (string)($q->fetchColumn() ?: '');
        }
    } catch (Throwable $e) {
        error_log('[doc-links] cannot read/create secret: ' . $e->getMessage());
        return '';
    }
    if (strlen($stored) < 64) {
        return '';
    }
    return $secret = hash_hmac('sha256', 'rh-doc-link-v1', $stored);
}

function rh_doc_link_sign(string $type, int $id, int $exp): string
{
    $secret = rh_doc_link_secret();
    if ($secret === '') {
        return '';
    }
    return hash_hmac('sha256', $type . '|' . $id . '|' . $exp, $secret);
}

/** Token = type.id.expiry.signature (type has no dots). Empty string when it cannot be signed. */
function rh_doc_link_token(string $type, int $bookingId, int $days = 30): string
{
    if (!isset(rh_doc_link_types()[$type]) || $bookingId <= 0) {
        return '';
    }
    $exp = time() + max(1, $days) * 86400;
    $sig = rh_doc_link_sign($type, $bookingId, $exp);
    return $sig === '' ? '' : $type . '.' . $bookingId . '.' . $exp . '.' . $sig;
}

/** Full public URL for a document link, or '' when it cannot be built. */
function rh_doc_signed_url(string $type, int $bookingId, int $days = 30): string
{
    $token = rh_doc_link_token($type, $bookingId, $days);
    if ($token === '') {
        return '';
    }
    $base = defined('BASE_URL') ? rtrim((string)BASE_URL, '/') : '';
    return $base . '/invoice-view.php?t=' . rawurlencode($token);
}

/**
 * Check a token and find the file. Never says WHY it failed (no detail leak).
 * @return array ['ok'=>false] or ['ok'=>true,'file'=>absolute path,'name'=>safe file name,'ext'=>'pdf'|'html']
 */
function rh_doc_link_resolve(string $token): array
{
    global $pdo;
    $fail = ['ok' => false];
    if ($token === '' || strlen($token) > 300) {
        return $fail;
    }
    $parts = explode('.', $token);
    if (count($parts) !== 4) {
        return $fail;
    }
    [$type, $idRaw, $expRaw, $sig] = $parts;
    $types = rh_doc_link_types();
    if (!isset($types[$type]) || !ctype_digit($idRaw) || !ctype_digit($expRaw) || !preg_match('/^[0-9a-f]{64}$/', $sig)) {
        return $fail;
    }
    $id  = (int)$idRaw;
    $exp = (int)$expRaw;
    $want = rh_doc_link_sign($type, $id, $exp);
    if ($want === '' || !hash_equals($want, $sig)) {
        return $fail;
    }
    if ($exp < time() || $id <= 0) {
        return $fail;
    }
    [$sql, $folder] = $types[$type];
    try {
        $st = $pdo->prepare($sql);
        $st->execute([$id]);
        $rel = (string)($st->fetchColumn() ?: '');
    } catch (Throwable $e) {
        error_log('[doc-links] lookup failed: ' . $e->getMessage());
        return $fail;
    }
    if ($rel === '') {
        return $fail;
    }
    $siteRoot = dirname(__DIR__);
    $root = realpath($siteRoot . '/' . $folder);
    $file = realpath($siteRoot . '/' . ltrim(str_replace('\\', '/', $rel), '/'));
    if ($root === false || $file === false || strpos($file, $root . DIRECTORY_SEPARATOR) !== 0 || !is_file($file)) {
        return $fail;
    }
    $ext = strtolower(pathinfo($file, PATHINFO_EXTENSION));
    if ($ext !== 'pdf') {
        return $fail; // guests only ever get the PDF
    }
    return [
        'ok'   => true,
        'file' => $file,
        'ext'  => $ext,
        'name' => preg_replace('/[^A-Za-z0-9._-]+/', '_', basename($file)),
    ];
}
