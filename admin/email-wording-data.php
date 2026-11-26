<?php
/**
 * GET JSON for the "Edit wording" editor (owner 2026-11-26): the default body of one booking email and, for each
 * {{field}} in it, a label, a short preview for this booking and whether it is a locked money field. Read-only:
 * nothing is saved here. ?key=<template key>&booking_id=<id>
 */
require_once __DIR__ . '/admin-init.php';
require_once __DIR__ . '/../includes/email-wording.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

$ewOut = static function (array $data, int $code = 200): void {
    http_response_code($code);
    echo json_encode($data, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_UNESCAPED_UNICODE);
    exit;
};

// Not a mapped page: the same access as the screens that send these emails, checked here.
$ewUid = (int)$user['id'];
if (!rhHasPagePermission($ewUid, 'bookings.php') && !rhHasPagePermission($ewUid, 'booking-details.php')) {
    $ewOut(['ok' => false, 'error' => 'You do not have access to bookings.'], 403);
}
$ewModuleOn = ($user['role'] ?? '') === 'admin' ? rh_module_licence_allows('bookings') : rh_module_key_enabled('bookings');
if (!$ewModuleOn) {
    $ewOut(['ok' => false, 'error' => 'Bookings are switched off.'], 403);
}

$ewKey = (string)($_GET['key'] ?? '');
$ewBookingId = (int)($_GET['booking_id'] ?? 0);
$ewFixed = 'This email uses fixed wording; change it in Email & PDF templates.';
if (!rh_email_wording_is_editable_key($ewKey)) {
    $ewOut(['ok' => true, 'editable' => false, 'reason' => $ewFixed]);
}
$ewTpl = rh_email_wording_default($ewKey);
if ($ewTpl === null) {
    $ewOut(['ok' => true, 'editable' => false, 'reason' => $ewFixed]);
}

try {
    $ewSt = $pdo->prepare("SELECT b.*, r.name AS room_name FROM bookings b LEFT JOIN rooms r ON b.room_id = r.id WHERE b.id = ?");
    $ewSt->execute([$ewBookingId]);
    $ewBooking = $ewSt->fetch(PDO::FETCH_ASSOC);
    if (!$ewBooking) {
        $ewOut(['ok' => false, 'error' => 'Booking not found.'], 404);
    }

    require_once __DIR__ . '/../config/email.php';
    if (in_array($ewKey, ['booking_received', 'booking_confirmed', 'tentative_booking_created'], true) && function_exists('rh_email_party_booking')) {
        $ewBooking = rh_email_party_booking($pdo, $ewBooking);
    }
    $ewRoomSt = $pdo->prepare("SELECT * FROM rooms WHERE id = ?");
    $ewRoomSt->execute([$ewBooking['room_id'] ?? 0]);
    $ewRoom = $ewRoomSt->fetch(PDO::FETCH_ASSOC) ?: null;
    $ewExtra = [];
    if ($ewKey === 'booking_cancelled') {
        require_once __DIR__ . '/../includes/cancel-reason.php';
        if (function_exists('rh_cancel_guest_email_reason')) {
            $ewExtra['cancellation_reason'] = rh_cancel_guest_email_reason();
        }
    }
    $ewVars = buildBookingEmailVariables($ewBooking, $ewRoom, $ewExtra);
    if (!array_key_exists('payment_policy', $ewVars) && function_exists('rh_email_payment_policy_html')) {
        $ewVars['payment_policy'] = rh_email_payment_policy_html();
    }

    [, $ewInner] = rh_email_wording_split((string)$ewTpl['html_body']);
    $ewTokens = [];
    foreach (array_unique(rh_email_wording_tokens($ewInner)) as $ewName) {
        $ewPreview = 'filled in when sent';
        if (array_key_exists($ewName, $ewVars) && is_scalar($ewVars[$ewName])) {
            $ewPlain = trim((string)preg_replace('/\s+/u', ' ', html_entity_decode(strip_tags((string)$ewVars[$ewName]), ENT_QUOTES | ENT_HTML5, 'UTF-8')));
            $ewPreview = $ewPlain === '' ? '(empty)' : (mb_strlen($ewPlain) > 60 ? mb_substr($ewPlain, 0, 57) . '...' : $ewPlain);
        }
        $ewTokens[$ewName] = [
            'label' => rh_email_wording_token_label($ewName),
            'value' => $ewPreview,
            'financial' => rh_email_wording_is_financial_token($ewName),
        ];
    }

    $ewOut([
        'ok' => true,
        'editable' => true,
        'key' => $ewKey,
        'name' => (string)($ewTpl['template_name'] ?? $ewKey),
        'html' => rh_email_wording_editor_html((string)$ewTpl['html_body']),
        'tokens' => $ewTokens,
    ]);
} catch (Throwable $e) {
    error_log('email-wording-data: ' . $e->getMessage());
    $ewOut(['ok' => false, 'error' => 'Could not load the email wording.'], 500);
}
