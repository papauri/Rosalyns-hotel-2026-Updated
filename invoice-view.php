<?php
/**
 * Private expiring document link for guests (sent over WhatsApp).
 * GET t = signed token from rh_doc_signed_url(). No login, no session: the signature is the key.
 * Valid -> streams the PDF inline. Anything else -> one plain "link expired" page (no detail).
 */
require_once __DIR__ . '/config/database.php';
require_once __DIR__ . '/includes/public-csrf.php';
require_once __DIR__ . '/includes/doc-links.php';

function rh_doc_link_page(int $code, string $title, string $text): void
{
    http_response_code($code);
    header('Content-Type: text/html; charset=utf-8');
    header('Cache-Control: no-store');
    header('X-Robots-Tag: noindex, nofollow');
    header('X-Content-Type-Options: nosniff');
    $hotel = function_exists('getSetting') ? (string)getSetting('site_name', '') : '';
    $phone = function_exists('getSetting') ? (string)getSetting('phone_main', '') : '';
    $e = static function ($s) { return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); };
    echo '<!DOCTYPE html><html lang="en"><head><meta charset="UTF-8">'
        . '<meta name="viewport" content="width=device-width, initial-scale=1">'
        . '<meta name="robots" content="noindex, nofollow"><title>' . $e($title) . '</title>'
        . '<style>body{margin:0;min-height:100vh;display:flex;align-items:center;justify-content:center;background:#f6f4f0;'
        . 'font-family:system-ui,-apple-system,Segoe UI,Roboto,Arial,sans-serif;color:#2b2b2b}'
        . '.c{max-width:420px;margin:24px;padding:32px 24px;background:#fff;border-radius:12px;text-align:center;'
        . 'box-shadow:0 2px 12px rgba(0,0,0,.08)}h1{font-size:1.25rem;margin:0 0 12px}p{margin:8px 0;line-height:1.5}'
        . '.h{font-size:.9rem;color:#666;margin-top:20px}</style></head><body><div class="c">'
        . '<h1>' . $e($title) . '</h1><p>' . $e($text) . '</p>'
        . ($hotel !== '' ? '<p class="h">' . $e($hotel) . ($phone !== '' ? ' &middot; ' . $e($phone) : '') . '</p>' : '')
        . '</div></body></html>';
    exit;
}

if (!pub_ip_rate_limit('doc_link', 30, 600)) {
    header('Retry-After: 600');
    rh_doc_link_page(429, 'Please try again later', 'Too many attempts. Please wait a few minutes and try again.');
}

$token = isset($_GET['t']) && is_string($_GET['t']) ? $_GET['t'] : '';
$doc = rh_doc_link_resolve($token);
if (empty($doc['ok'])) {
    rh_doc_link_page(410, 'This link has expired', 'Please contact the hotel and we will gladly send you a new one.');
}

header('Content-Type: application/pdf');
header('Content-Disposition: inline; filename="' . $doc['name'] . '"');
header('Content-Length: ' . filesize($doc['file']));
header('Cache-Control: private, no-store');
header('X-Robots-Tag: noindex, nofollow');
header('X-Content-Type-Options: nosniff');
readfile($doc['file']);
exit;
