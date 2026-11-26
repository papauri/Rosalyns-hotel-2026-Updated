<?php
if (PHP_SAPI !== 'cli') { http_response_code(403); exit("CLI only.\n"); } // never runnable over the web
/**
 * Edit an email's wording before it goes (owner 2026-11-26). PURE checks of includes/email-wording.php (no database,
 * config/email.php is never loaded), then one READ-ONLY check of the getBookingEmailTemplateConfig() seam
 * (config/database.php; reads one template row, writes nothing to the database).
 * Usage (repo root): php scripts/tests/email_wording_test.php
 */
$root = dirname(__DIR__, 2);
$pass = 0; $fail = 0;
function ok(string $l): void { global $pass; $pass++; echo "PASS $l\n"; }
function bad(string $l, string $d = ''): void { global $fail; $fail++; echo "FAIL $l" . ($d !== '' ? ": $d" : '') . "\n"; }
function check(bool $c, string $l, string $d = ''): bool { $c ? ok($l) : bad($l, $d); return $c; }

require_once $root . '/includes/email-wording.php';
check(!function_exists('getSetting') && !isset($GLOBALS['pdo']), 'the helpers load without the database or settings');

// A stored template as hotel_premium_email_html() builds it: a full document, {{packages_html}} between table rows.
$head = '<!DOCTYPE html><html lang="en"><head><meta charset="UTF-8"><style>body{background-color:#d5cfc4;}</style></head>'
    . '<body style="margin:0;padding:0;">';
$preheader = 'display:none;font-size:1px;color:#d5cfc4;line-height:1px;max-height:0px;max-width:0px;opacity:0;overflow:hidden;';
$inner = '<div style="' . $preheader . '">Your stay is confirmed.</div>'
    . '<div style="font-family:Arial;color:#3e3930;"><table width="100%" border="0" cellspacing="0" cellpadding="0">'
    . '<tr><td style="padding:0 48px;"><p style="margin:0 0 16px;">Dear {{guest_name}},</p>'
    . '<p style="margin:0;">Your booking at <strong>{{site_name}}</strong> is confirmed.</p>'
    . '<p style="margin:0;">Check-in from <strong>{{check_in_time}}</strong> &middot; Check-out by <strong>{{check_out_time}}</strong>.</p></td></tr>'
    . '<tr><td>Total</td><td>{{currency_symbol}} {{total_amount_formatted}}</td></tr>{{packages_html}}<tr>'
    . '<td style="font-style:italic;">{{payment_policy}}</td></tr>'
    . '<tr><td>{{contact_phone}}&nbsp;|&nbsp;<a href="mailto:{{contact_email}}" style="color:#6d6455;">{{contact_email}}</a> — é</td></tr>'
    . '</table></div>';
$default = $head . $inner . '</body></html>';
$K = 'booking_confirmed';
$v = static function (string $edited, string $key = 'booking_confirmed') use ($default) {
    return rh_email_wording_validate($key, $edited, $default, 'MWK');
};

/* ── keys and money fields ── */
check(rh_email_wording_is_editable_key('booking_confirmed') && rh_email_wording_is_editable_key('tentative_booking_converted'), 'booking emails are editable');
foreach (['payment_invoice', 'invoice', 'payment_receipt', 'refund_notification', 'credit_note', 'conference_confirmed', 'gym_welcome'] as $k) {
    check(!rh_email_wording_is_editable_key($k), 'fixed wording: ' . $k);
}
check(rh_email_wording_key_for_resend_type('tentative_confirmed') === 'tentative_booking_created' && rh_email_wording_key_for_resend_type('invoice') === '', 'Resend type map (invoice has no editable key)');
foreach (['total_amount_formatted', 'currency_symbol', 'packages_html', 'payment_policy', 'booked_rate_block', 'balance_due', 'deposit_amount', 'rate_plan_label', 'cancellation_fee_charged'] as $t) {
    check(rh_email_wording_is_financial_token($t), 'locked field: ' . $t);
}
foreach (['guest_name', 'check_in_date_formatted', 'days_overdue', 'site_name', 'contact_email'] as $t) {
    check(!rh_email_wording_is_financial_token($t), 'free field: ' . $t);
}

/* ── identity: the default goes through unchanged ── */
$r = $v($inner);
check($r['ok'], 'identity: the unchanged default body is accepted', implode(' ', $r['errors']));
check(strpos($r['html'], $head) === 0 && substr($r['html'], -14) === '</body></html>', 'identity: the stored head and end are kept as they are');
$fin = static function (string $h): array { return array_values(array_filter(rh_email_wording_tokens($h), 'rh_email_wording_is_financial_token')); };
check($fin($r['html']) === $fin($default), 'identity: money fields in the same order');
check(rh_email_wording_tokens($r['html']) === rh_email_wording_tokens($default), 'identity: every field kept');
check(strpos($r['html'], 'href="mailto:{{contact_email}}"') !== false, 'a field inside a link survives (not %7B%7B)');
check(strpos($r['html'], '&middot;') !== false && strpos($r['html'], '{{contact_phone}}&nbsp;|&nbsp;') !== false, 'entity spellings kept (the empty-phone cleanup still matches)');
check(strpos($r['html'], '— é') !== false, 'UTF-8 text kept');
check(preg_match('#</tr>\s*\{\{packages_html\}\}\s*<tr#', $r['html']) === 1, '{{packages_html}} stays between its table rows');

/* ── allowed edits ── */
$added = str_replace('<p style="margin:0 0 16px;">Dear {{guest_name}},</p>', '<p style="margin:0 0 16px;">Dear {{guest_name}},</p><p>Your airport pick-up is booked for 14:00. Please bring your ID.</p>', $inner);
$r = $v($added);
check($r['ok'] && strpos($r['html'], 'airport pick-up is booked for 14:00') !== false, 'an added paragraph is accepted', implode(' ', $r['errors']));
$r = $v(str_replace('Your booking at <strong>{{site_name}}</strong> is confirmed.', 'We are delighted to confirm your stay.', $inner));
check($r['ok'], 'a free field may be removed with its sentence', implode(' ', $r['errors']));

/* ── refused edits ── */
$r = $v(str_replace('{{total_amount_formatted}}', '', $inner));
check(!$r['ok'] && $r['html'] === '', 'removing a money field is refused');
$r = $v(str_replace('<td style="font-style:italic;">{{payment_policy}}</td>', '<td style="font-style:italic;">{{payment_policy}} {{payment_policy}}</td>', $inner));
check(!$r['ok'], 'a money field twice is refused');
$r = $v(str_replace(['{{currency_symbol}} {{total_amount_formatted}}', '{{payment_policy}}'], ['{{payment_policy}}', '{{currency_symbol}} {{total_amount_formatted}}'], $inner));
check(!$r['ok'], 'money fields moved out of order are refused');
$r = $v(str_replace('Dear {{guest_name}},', 'Dear {{guest_name}}, {{admin_password}}', $inner));
check(!$r['ok'] && strpos(implode(' ', $r['errors']), 'admin_password') !== false, 'a field not in the default is refused');
$r = $v(str_replace('Dear {{guest_name}},', 'Dear {{ guest_name }},', $inner));
check(!$r['ok'], 'loose curly-bracket text is refused');
foreach (['Your room costs MWK 5,000 a night.', 'Pay {{currency_symbol}} 500 on arrival.', 'Pay {{currency_symbol}}500 on arrival.', 'Only $20 more.', 'Pay K5000 now.', 'Total 12,000 USD.'] as $typed) {
    $r = $v(str_replace('Please', 'Please', str_replace('<p style="margin:0 0 16px;">Dear {{guest_name}},</p>', '<p style="margin:0 0 16px;">Dear {{guest_name}},</p><p>' . $typed . '</p>', $inner)));
    check(!$r['ok'] && strpos(implode(' ', $r['errors']), 'do not type prices') !== false, 'typed amount refused: ' . $typed);
}
$r = $v(str_replace('Dear {{guest_name}},', 'Dear {{guest_name}}, ok 5 minutes from the gate.', $inner));
check($r['ok'], 'plain numbers are not money ("ok 5 minutes")', implode(' ', $r['errors']));
$r = $v($inner, 'payment_invoice');
check(!$r['ok'], 'a fixed-wording key is refused');
$r = $v('   ');
check(!$r['ok'], 'an empty email is refused');
$r = $v(str_repeat('a', 100001));
check(!$r['ok'], 'an over-long email is refused');

/* ── cleaning ── */
$dirty = str_replace('<p style="margin:0 0 16px;">Dear {{guest_name}},</p>',
    '<p style="margin:0 0 16px;" onclick="steal()">Dear {{guest_name}},</p><script>alert(1)</script><style>p{}</style><iframe src="https://x"></iframe>'
    . '<p style="background:url(https://evil/x.png)">Bg</p><a href="javascript:alert(1)">bad link</a><a href="https://ok.example/">good link</a>'
    . '<img src="http://plain/x.png" alt="x"><img src="https://ok.example/i.png" alt="ok"><!-- note --><custom-tag>kept text</custom-tag>'
    . '<a href="{{guest_name}}">token link</a><p class="x" id="y" data-a="1">attrs</p>', $inner);
$r = $v($dirty);
check($r['ok'], 'a dirty edit is cleaned, not refused', implode(' ', $r['errors']));
$h = $r['html'];
check(stripos($h, 'onclick') === false && stripos($h, '<script') === false && stripos($h, 'alert(1)') === false, 'scripts and on* attributes removed');
check(stripos($h, '<iframe') === false && strpos(substr($h, strlen($head)), '<style') === false, 'iframe and style blocks removed');
check(stripos($h, 'url(') === false && strpos($h, '>Bg</p>') !== false, 'a style with url( is dropped, the text stays');
check(stripos($h, 'javascript:') === false && strpos($h, 'href="https://ok.example/"') !== false, 'javascript: link dropped, https link kept');
check(strpos($h, 'http://plain') === false && strpos($h, 'src="https://ok.example/i.png"') !== false, 'only https images');
check(strpos($h, '<!--') === false && strpos($h, 'custom-tag') === false && strpos($h, 'kept text') !== false, 'comments dropped, unknown tags unwrapped');
check(strpos($h, 'href="{{guest_name}}"') === false, 'a field the default never links cannot be a link');
check(strpos($h, 'class="x"') === false && strpos($h, 'data-a') === false && strpos($h, 'id="y"') === false, 'class / id / data-* removed');
check($fin($h) === $fin($default), 'cleaning keeps the money fields');

/* ── QA round 1 probes (2026-11-26) ── */
$dear = '<p style="margin:0 0 16px;">Dear {{guest_name}},</p>';
$totalCell = '<td>{{currency_symbol}} {{total_amount_formatted}}</td>';
$err = static function (array $r): string { return implode(' ', $r['errors']); };
// 1. A field typed into a non-link attribute (plain or percent-encoded) never becomes a field.
$r = $v(str_replace($dear, $dear . '<p style="%7B%7Bpayment_policy%7D%7D">a</p><p style="{{payment_policy}}">b</p>', $inner));
check($r['ok'] && strpos($r['html'], 'style="%7B') === false && substr_count($r['html'], '{{payment_policy}}') === 1, '1: a field in style is dropped (plain and %7B%7B)', $err($r));
$r = $v(str_replace($totalCell, '<td width="%7B%7Bcurrency_symbol%7D%7D" height="%7B%7Btotal_amount_formatted%7D%7D">{{currency_symbol}} {{total_amount_formatted}}</td>', $inner));
check($r['ok'] && preg_match('/(width|height)="[^"]*(\{\{|%7B)/i', $r['html']) === 0, '1: a field in width/height is dropped', $err($r));
$r = $v(str_replace($totalCell, '<td width="%7B%7Bcurrency_symbol%7D%7D" height="%7B%7Btotal_amount_formatted%7D%7D">Total</td>', $inner));
check(!$r['ok'], '1: the total moved into width/height is refused (it is gone from the text)');
// 2. The total moved into an invisible attribute, re-wrapped, or hidden.
$r = $v(str_replace($totalCell, '<td><img src="https://ok.example/i.png" alt="{{currency_symbol}} {{total_amount_formatted}}"></td>', $inner));
check(!$r['ok'], '2: the total moved into image alt text is refused');
$r = $v(str_replace($totalCell, '<td><a href="https://ok.example/{{currency_symbol}}{{total_amount_formatted}}">see</a></td>', $inner));
check(!$r['ok'], '2: the total moved into a link address is refused');
$r = $v(str_replace($totalCell, '<td><div style="' . $preheader . '">{{currency_symbol}} {{total_amount_formatted}}</div></td>', $inner));
check(!$r['ok'], '2: the total wrapped in the default\'s own hidden style is refused', $err($r));
$r = $v(str_replace($totalCell, '<td><strong>{{currency_symbol}} {{total_amount_formatted}}</strong></td>', $inner));
check(!$r['ok'] && strpos($err($r), 'keep their place') !== false, '2: the total re-wrapped is refused');
$r = $v(str_replace([$totalCell, '<tr><td>Total</td>'], ['<td style="display:none">{{currency_symbol}} {{total_amount_formatted}}</td>', '<tr style="font-size:0">' . '<td width="1" bgcolor="#ffffff">Total</td>'], $inner));
check($r['ok'] && stripos($r['html'], 'display:none">{{currency') === false && strpos($r['html'], 'font-size:0') === false
    && strpos($r['html'], 'width="1"') === false && strpos($r['html'], 'bgcolor') === false, '2: new hiding/sizing styles and attributes are dropped (the default\'s look stays)', $err($r));
$r = $v(str_replace($dear, '<p style="margin:0 0 16px;\63olor:#fff">Dear {{guest_name}},</p>', $inner));
check($r['ok'] && strpos($r['html'], '\63') === false, '2: a style with a CSS backslash escape is dropped', $err($r));
check(rh_email_wording_style_hides('display: none') && rh_email_wording_style_hides('font-size:0px') && rh_email_wording_style_hides('opacity:.1')
    && rh_email_wording_style_hides('max-height:0') && !rh_email_wording_style_hides('font-size:9px;color:#9b8f7e;'), '2: hiding styles are recognised');
$r = $v(str_replace(['<table width="100%" border="0" cellspacing="0" cellpadding="0">', '</table>'], ['<table width="100%" border="0" cellspacing="0" cellpadding="0"><tbody>', '</tbody></table>'], $inner));
check($r['ok'], 'the <tbody> a browser adds does not count as moving a field', $err($r));
// 3. Typed amounts in the forms staff actually use.
foreach (['Pay MK 3,000 at the desk.', 'Pay MK3000.', "Only \$\u{200B}5 more.", "Pay MWK\u{00A0}5,000.", 'Pay K 5,000.', 'Pay 3,000 MK.', "Pay \u{2066}K\u{2069}500."] as $typed) {
    $r = $v(str_replace($dear, $dear . '<p>' . $typed . '</p>', $inner));
    check(!$r['ok'] && strpos($err($r), 'do not type prices') !== false, '3: typed amount refused: ' . json_encode($typed));
}
$r = $v(str_replace($dear, $dear . '<p>OK 5 minutes walk; ask for Kim 2 doors down; room 12 on floor 3.</p>', $inner));
check($r['ok'], '3: ordinary numbers are not money', $err($r));

/* ── editor body ── */
$ed = rh_email_wording_editor_html($default);
check(strpos($ed, '<style') === false && strpos($ed, '<body') === false, 'editor body has no head or style');
check(preg_match('#<tr data-ew-block="1"><td>\{\{packages_html\}\}</td></tr>#', $ed) === 1, 'a field between table rows is wrapped for the editor');
$back = preg_replace('#<tr data-ew-block="1"><td>(\{\{[a-z0-9_]+\}\})</td></tr>#', '$1', $ed);
$r = $v($back);
check($r['ok'] && $fin($r['html']) === $fin($default), 'editor body sent back unchanged is accepted', implode(' ', $r['errors']));

/* ── apply / clear ── */
rh_email_wording_apply($K, '<p>x</p>');
check(($GLOBALS['rh_email_wording_override'][$K] ?? null) === '<p>x</p>', 'apply sets the one-off wording');
rh_email_wording_clear($K);
check(!isset($GLOBALS['rh_email_wording_override'][$K]), 'clear removes it');
$got = rh_email_wording_send(['ok' => true, 'key' => $K, 'html' => '<p>y</p>'], static function () use ($K) {
    return ['success' => true, 'seen' => $GLOBALS['rh_email_wording_override'][$K] ?? null];
});
check($got['seen'] === '<p>y</p>' && !isset($GLOBALS['rh_email_wording_override'][$K]), 'send: wording set during the send and cleared after');
try {
    rh_email_wording_send(['ok' => true, 'key' => $K, 'html' => '<p>z</p>'], static function () { throw new RuntimeException('smtp down'); });
} catch (RuntimeException $e) { /* expected */ }
check(!isset($GLOBALS['rh_email_wording_override'][$K]), 'send: cleared even when the send throws');
$got = rh_email_wording_send(null, static function () use ($K) { return ['success' => true, 'seen' => $GLOBALS['rh_email_wording_override'][$K] ?? null]; });
check($got['seen'] === null, 'send without wording: the default goes');
check(rh_email_wording_from_post($K, []) === null, 'nothing posted: null (default wording, as before)');
$fp = rh_email_wording_from_post($K, ['email_wording_key' => 'booking_cancelled', 'email_wording_html' => '<p>x</p>']);
check(is_array($fp) && empty($fp['ok']), 'posted for another email: refused (nothing sent)');

/* ── LATER items (owner 2026-11-27): Booking updated email, locked blocks, bulk, New booking ── */
check(rh_email_wording_is_editable_key('booking_modified'), 'booking_modified (Booking updated email) is editable');
foreach (['changes_html', 'logo_html', 'group_rooms_block', 'packages_html', 'some_new_block', 'other_html'] as $t) {
    check(rh_email_wording_is_financial_token($t), 'locked block/html field: ' . $t);
}
check(!rh_email_wording_is_financial_token('guest_name') && !rh_email_wording_is_financial_token('check_in_date'), 'plain guest fields stay unlocked');
$modDefault = $head . '<div style="font-family:Arial;"><table width="100%"><tr><td><p>Dear {{guest_name}},</p><p>Your booking {{booking_reference}} was updated.</p></td></tr>'
    . '<tr><td style="padding:0 48px 24px;">{{changes_html}}</td></tr><tr><td>Total</td><td>{{currency_symbol}} {{total_amount_formatted}}</td></tr></table></div></body></html>';
$modBack = preg_replace('#<tr data-ew-block="1"><td>(\{\{[a-z0-9_]+\}\})</td></tr>#', '$1', rh_email_wording_editor_html($modDefault));
$r = rh_email_wording_validate('booking_modified', str_replace('was updated.', 'was changed as you asked.', $modBack), $modDefault, 'MWK');
check($r['ok'] && strpos($r['html'], 'as you asked') !== false && strpos($r['html'], '{{changes_html}}') !== false, 'booking_modified: wording change accepted, change list kept', implode(' ', $r['errors']));
$r = rh_email_wording_validate('booking_modified', str_replace('{{changes_html}}', '', $modBack), $modDefault, 'MWK');
check(!$r['ok'], 'booking_modified: removing the change list is refused');
$r = rh_email_wording_validate('booking_modified', str_replace('{{changes_html}}', '{{changes_html}}{{changes_html}}', $modBack), $modDefault, 'MWK');
check(!$r['ok'], 'booking_modified: repeating the change list is refused');

// rh_email_wording_for_key: only the matching, accepted wording is handed to a send.
$wOk = ['ok' => true, 'key' => 'booking_confirmed', 'html' => '<p>w</p>'];
check(rh_email_wording_for_key($wOk, 'booking_confirmed') === $wOk, 'for_key: the matching email gets the wording');
check(rh_email_wording_for_key($wOk, 'tentative_booking_converted') === null, 'for_key: another email gets the default');
check(rh_email_wording_for_key(['ok' => false, 'error' => 'x'], 'booking_confirmed') === null && rh_email_wording_for_key(null, 'booking_confirmed') === null, 'for_key: refused or no wording gives nothing');

// Bulk: which email each booking gets, and the one check made before the batch.
check(rh_email_wording_bulk_allowed_keys('invoice') === [] && rh_email_wording_bulk_allowed_keys('noshow') === [], 'bulk invoice / no-show: fixed wording');
check(rh_email_wording_bulk_key('reminder', ['status' => 'confirmed']) === 'booking_reminder', 'bulk key: reminder');
check(rh_email_wording_bulk_key('confirmation', ['status' => 'pending']) === 'booking_received'
    && rh_email_wording_bulk_key('confirmation', ['status' => 'tentative']) === 'tentative_booking_created'
    && rh_email_wording_bulk_key('confirmation', ['status' => 'confirmed']) === 'booking_confirmed', 'bulk key: resend confirmation follows the status');
check(rh_email_wording_bulk_key('confirm', ['status' => 'tentative', 'is_tentative' => 1]) === 'tentative_booking_converted', 'bulk key: confirm a tentative hold converts');
check(rh_email_wording_bulk_key('confirm', ['status' => 'tentative', 'is_tentative' => 0]) === 'booking_confirmed'
    && rh_email_wording_bulk_key('confirm', ['status' => 'pending']) === 'booking_confirmed', 'bulk key: confirm otherwise sends booking confirmed');
check(rh_email_wording_bulk_key('cancel', ['status' => 'confirmed']) === 'booking_cancelled', 'bulk key: cancel');
check(rh_email_wording_bulk_check('confirm', []) === null, 'bulk check: nothing posted gives the default wording');
$bc = rh_email_wording_bulk_check('reminder', ['email_wording_key' => 'booking_cancelled', 'email_wording_html' => '<p>x</p>']);
check(is_array($bc) && empty($bc['ok']), 'bulk check: wording for an email the action does not send is refused');
$bc = rh_email_wording_bulk_check('invoice', ['email_wording_key' => 'payment_invoice', 'email_wording_html' => '<p>x</p>']);
check(is_array($bc) && empty($bc['ok']), 'bulk check: the invoice keeps fixed wording');
$bc = rh_email_wording_bulk_check('cancel', ['email_wording_key' => 'booking_cancelled', 'email_wording_html' => '<p>x</p>', 'email_guest' => '0']);
check(is_array($bc) && empty($bc['ok']) && strpos($bc['error'], 'Email the guest') !== false, 'bulk check: edited cancellation with email unticked is refused');
// Validated once, then set and cleared around every send of the batch (tokens filled per booking by the sender).
$seen = [];
$leak = false;
foreach ([['id' => 1, 'status' => 'pending'], ['id' => 2, 'status' => 'tentative', 'is_tentative' => 1], ['id' => 3, 'status' => 'pending']] as $bb) {
    $bkey = rh_email_wording_bulk_key('confirm', $bb);
    rh_email_wording_send(rh_email_wording_for_key($wOk, $bkey), static function () use (&$seen, $bb, $bkey) {
        $seen[$bb['id']] = $GLOBALS['rh_email_wording_override'][$bkey] ?? null;
        return ['success' => true];
    });
    $leak = $leak || !empty($GLOBALS['rh_email_wording_override']);
}
check($seen === [1 => '<p>w</p>', 2 => null, 3 => '<p>w</p>'] && !$leak, 'bulk: edited wording only on the bookings that get that email, cleared after each send');
try {
    rh_email_wording_send($wOk, static function () { throw new RuntimeException('smtp down mid-batch'); });
} catch (RuntimeException $e) { /* expected */ }
check(empty($GLOBALS['rh_email_wording_override']), 'bulk: a send that throws mid-batch leaves no wording set');

// New booking: the key follows the chosen status; wording for another email is refused before anything is created.
check(rh_email_wording_create_key('confirmed', false) === 'booking_confirmed' && rh_email_wording_create_key('confirmed', true) === 'booking_confirmed', 'create key: confirmed');
check(rh_email_wording_create_key('pending', false) === 'booking_received' && rh_email_wording_create_key('pending', true) === 'booking_received', 'create key: pending');
check(rh_email_wording_create_key('tentative', false) === 'tentative_booking_created', 'create key: tentative');
check(rh_email_wording_create_key('checked-in', false) === 'booking_received', 'create key: a checked-in walk-in gets the received email');
$cm = rh_email_wording_from_post(rh_email_wording_create_key('tentative', false), ['email_wording_key' => 'booking_confirmed', 'email_wording_html' => '<p>x</p>']);
check(is_array($cm) && empty($cm['ok']) && strpos($cm['error'], 'different email') !== false, 'create: status changed after editing (key mismatch) is refused');
$cbSrc = (string)file_get_contents($root . '/admin/create-booking.php');
$cbPosCheck = strpos($cbSrc, 'rh_email_wording_from_post($cbWordingKey');
$cbPosTx = strpos($cbSrc, '// ── Transaction: create bookings for all lines');
check($cbPosCheck !== false && $cbPosTx !== false && $cbPosCheck < $cbPosTx, 'create: the wording is checked before the booking transaction starts');

/* ── Booking updated: the VAT / levy line is a locked token (owner 2026-11-27) ── */
check(rh_email_wording_is_financial_token('tax_rows_html'), 'tax_rows_html is locked (money)');
$tagsSrc = (string)file_get_contents($root . '/includes/email-template-tags.php');
check(preg_match("/'booking_modified'\s*=>\s*array_merge\(\\\$booking, \[[^\]]*'tax_rows_html'/", $tagsSrc) === 1, 'tax_rows_html is in the Booking updated tag list');

/* ── update_status: wording only with Confirm (pending / tentative) or Cancel ── */
check(rh_email_wording_status_key('confirmed', 'cancelled') === 'booking_cancelled' && rh_email_wording_status_key('pending', 'cancelled') === 'booking_cancelled', 'status key: cancel');
check(rh_email_wording_status_key('pending', 'confirmed') === 'booking_confirmed' && rh_email_wording_status_key('tentative', 'confirmed') === 'booking_confirmed', 'status key: confirm a hold');
foreach ([['confirmed', 'checked-in'], ['checked-in', 'confirmed'], ['checked-in', 'checked-out'], ['confirmed', 'no-show'], ['confirmed', 'confirmed'], ['pending', 'tentative'], ['', 'confirmed']] as [$from, $to]) {
    check(rh_email_wording_status_key($from, $to) === '', "status key: $from -> $to sends no editable email (wording refused)");
}
check(rh_email_wording_posted(['email_wording_key' => 'booking_confirmed']) && rh_email_wording_posted(['email_wording_html' => '<p>x</p>'])
    && !rh_email_wording_posted([]) && !rh_email_wording_posted(['email_wording_key' => ' ', 'email_wording_html' => '']), 'wording posted: key or body');
$bkSrc = (string)file_get_contents($root . '/admin/bookings.php');
$usPos = strpos($bkSrc, "} elseif (\$action === 'update_status') {");
$usRefuse = strpos($bkSrc, 'Edited email wording only goes with Confirm', (int)$usPos);
$usFirstChange = strpos($bkSrc, 'bookings_checkin_gates($pdo', (int)$usPos);
check($usPos !== false && $usRefuse !== false && $usFirstChange !== false && $usRefuse < $usFirstChange, 'update_status: wrong-status wording refused before any branch changes anything');
check(strpos($bkSrc, 'rh_email_wording_status_key(', (int)$usPos) !== false && strpos($bkSrc, 'Nothing was cancelled. You edited the cancellation email but', (int)$usPos) !== false, 'update_status: cancel with the guest email unticked refuses edited wording');
$usKeyRefuse = strpos($bkSrc, 'The edited wording belongs to a different email', (int)$usPos);
$usNoConfirmed = strpos($bkSrc, 'Only a confirmed booking gets a cancellation email', (int)$usPos);
check($usKeyRefuse !== false && $usKeyRefuse < $usFirstChange, 'update_status: wording posted for a different email than the status change is refused before anything changes');
check($usNoConfirmed !== false && $usNoConfirmed < $usFirstChange, 'update_status: cancel wording refused when no room the cancel covers is confirmed (no email would go)');

/* ── "Email the guest about this change" on room moves / nights / checkout date: DEFAULT OFF ── */
check(!rh_change_email_wanted([]) && !rh_change_email_wanted(['change_email_guest' => '0']) && !rh_change_email_wanted(['change_email_guest' => 'on'])
    && rh_change_email_wanted(['change_email_guest' => '1']) && rh_change_email_wanted(['change_email_guest' => ['0', '1']]), 'change email: only an explicit 1 sends (missing = off)');
check(rh_change_email_check([]) === null && rh_change_email_check(['change_email_guest' => '0']) === null, 'change email: unticked = no email, no refusal');
$ceRef = rh_change_email_check(['change_email_guest' => '0', 'email_wording_key' => 'booking_modified', 'email_wording_html' => '<p>x</p>']);
check(is_array($ceRef) && empty($ceRef['ok']) && strpos($ceRef['error'], 'not ticked') !== false, 'change email: edited wording with the tick off is refused');
$ceOn = rh_change_email_check(['change_email_guest' => '1']);
check(is_array($ceOn) && !empty($ceOn['ok']) && $ceOn['wording'] === null, 'change email: ticked, no edit = the template wording');
$ceWrong = rh_change_email_check(['change_email_guest' => '1', 'email_wording_key' => 'booking_confirmed', 'email_wording_html' => '<p>x</p>']);
check(is_array($ceWrong) && empty($ceWrong['ok']) && strpos($ceWrong['error'], 'different email') !== false, 'change email: wording of another email is refused');
$qaPos = strpos($bkSrc, 'async function submitQuickRoomAssign()');
$qaEnd = strpos($bkSrc, 'function openMakeTentativeModal', (int)$qaPos);
$qaJs = $qaPos !== false && $qaEnd !== false ? substr($bkSrc, $qaPos, $qaEnd - $qaPos) : '';
check(strpos($qaJs, "check: { label: 'Email the guest about this change', checked: false") !== false && strpos($qaJs, "key: 'booking_modified'") !== false
    && strpos($qaJs, "formData.append('change_email_guest', assignAnswer.checked ? '1' : '0')") !== false, 'room move dialog: the tick starts off and posts the choice');
check(strpos($bkSrc, "snEl('sn_email_guest').checked = false;") !== false && preg_match('/<input type="checkbox" id="sn_email_guest" value="1">/', $bkSrc) === 1, 'nights dialog: the tick starts off');
check(substr_count($bkSrc, "check: { label: 'Email the guest about this change', checked: false") === 2, 'checkout date dialog: the tick starts off');
foreach (["\$action === 'assign_room_combination'", "\$action === 'assign_individual_room'", "\$action === 'stay_nights_quote' || \$action === 'stay_nights_apply'", "\$action === 'admin_update_checkout_date'"] as $hd) {
    $hp = strpos($bkSrc, $hd);
    $hc = $hp !== false ? strpos($bkSrc, 'rh_change_email_check($_POST)', $hp) : false;
    $hs = $hc !== false ? strpos($bkSrc, 'bookings_change_email_send(', $hc) : false;
    check($hp !== false && $hc !== false && $hs !== false && $hs - $hp < 16000, 'change email wired (checked first, sent after): ' . $hd);
}
$raSrc = (string)file_get_contents($root . '/admin/includes/booking-row-actions.php');
$raFn = substr($raSrc, (int)strpos($raSrc, 'function bookings_change_email_send('));
check(strpos($raFn, 'catch (Throwable $e)') !== false && strpos($raFn, 'rollBack') === false && strpos($raFn, "logBookingAudit(\$bookingId, \$ok ? 'email_sent' : 'email_not_sent'") !== false, 'change email: a failed send never rolls back, the outcome goes to History');

/* ── seam (READ-ONLY database read of one template row) ── */
$dbOk = false;
try {
    require_once $root . '/config/database.php';
    $dbOk = function_exists('getBookingEmailTemplateConfig') && isset($GLOBALS['pdo']) && $GLOBALS['pdo'] instanceof PDO;
} catch (Throwable $e) {
    echo "SKIP seam: no database (" . $e->getMessage() . ")\n";
}
if ($dbOk) {
    $stored = getBookingEmailTemplateConfig($K, []);
    if ((int)($stored['is_active'] ?? 0) === 1 && trim((string)$stored['html_body']) !== '') {
        rh_email_wording_apply($K, '<p>EDITED ONE-OFF</p>');
        $row = getBookingEmailTemplateConfig($K, []);
        check($row['html_body'] === '<p>EDITED ONE-OFF</p>' && $row['text_body'] === '' && $row['subject'] === $stored['subject'], 'seam: override replaces the body only, text made from it');
        rh_email_wording_clear($K);
        $after = getBookingEmailTemplateConfig($K, []);
        check($after['html_body'] === $stored['html_body'] && strpos($after['html_body'], 'EDITED ONE-OFF') === false, 'seam: after clear the stored template is back (override never cached)');
        $tpl = rh_email_wording_default($K);
        $r = rh_email_wording_validate($K, rh_email_wording_split((string)$tpl['html_body'])[1], (string)$tpl['html_body'], 'MWK');
        check($r['ok'] && $fin($r['html']) === $fin((string)$tpl['html_body']), 'stored booking_confirmed template: identity accepted', implode(' ', $r['errors']));
        foreach (rh_email_wording_keys() as $k) {
            $t = rh_email_wording_default($k);
            if ($t === null) { echo "SKIP $k: not active\n"; continue; }
            $back = preg_replace('#<tr data-ew-block="1"><td>(\{\{[a-z0-9_]+\}\})</td></tr>#', '$1', rh_email_wording_editor_html((string)$t['html_body']));
            $r = rh_email_wording_validate($k, $back, (string)$t['html_body'], 'MWK');
            check($r['ok'] && $fin($r['html']) === $fin((string)$t['html_body']), 'stored ' . $k . ': editor body sent back unchanged is accepted', implode(' ', $r['errors']));
        }
        // 4. Edited wording but no usable template (switched off / emptied / removed since the editor opened):
        // the send must stop, never fall back to the coded default. A key with no row takes the same path (read-only).
        $missing = 'rh_ew_test_no_such_template';
        rh_email_wording_apply($missing, '<p>EDITED</p>');
        $threw = false;
        try {
            getBookingEmailTemplateConfig($missing, []);
        } catch (RuntimeException $e) {
            $threw = strpos($e->getMessage(), 'nothing was sent') !== false;
        } finally {
            rh_email_wording_clear($missing);
        }
        check($threw, '4: edited wording with no usable template stops the send (no silent default)');
        $seamSrc = (string)file_get_contents($root . '/config/database.php');
        check(preg_match("/\(int\)\(\\\$row\['is_active'\] \?\? 0\) !== 1[^;]*\)\s*\{\s*throw new RuntimeException/s", $seamSrc) === 1, '4: a template switched off after editing also stops the send');
        $inactive = $GLOBALS['rh_email_wording_override'] ?? [];
        check($inactive === [], 'no wording left set after the checks');
    } else {
        echo "SKIP seam: booking_confirmed template not active\n";
    }
}

/* ── Booking updated VAT / levy line (READ-ONLY: config/email.php pure functions, settings overridden in memory) ── */
if ($dbOk && stripos(str_replace('\\', '/', $root), 'liwonde') !== false && stripos((string)DB_NAME, 'rosalyn') === false) {
    require_once $root . '/config/email.php';
    $emSrc = (string)file_get_contents($root . '/config/email.php');
    $seedAt = strpos($emSrc, "        'booking_modified' => [");
    check($seedAt !== false && strpos(substr($emSrc, $seedAt, 4000), '{{tax_rows_html}}') !== false, 'seeded Booking updated default has {{tax_rows_html}}');
    // A stored row seeded before the token: the line is added after the change list at render (the row is never edited).
    $oldRow = ['subject' => 'Booking updated {{booking_reference}}', 'html_body' => '<html><body><p>Dear {{guest_name}}</p>{{changes_html}}<p>Total {{currency_symbol}} {{total_amount_formatted}}</p></body></html>', 'text_body' => 'Dear {{guest_name}}'];
    $vars = ['guest_name' => 'T', 'booking_reference' => 'LSH1', 'changes_html' => '<table>CHG</table>', 'currency_symbol' => 'MWK', 'total_amount_formatted' => '900,600',
        'tax_rows_html' => '<p style="x">Includes VAT and tourism levy</p>'];
    $out = rh_render_booking_email_template_row('booking_modified', $oldRow, $vars);
    $hb = (string)$out['html_body'];
    check(strpos($hb, '<table>CHG</table><p style="x">Includes VAT and tourism levy</p>') !== false, 'stored row without the token: line appended right after the change list');
    check(strpos($hb, '{{tax_rows_html}}') === false && substr_count($hb, 'Includes VAT and tourism levy') === 1, 'stored row: line filled once, no raw token left');
    check(strpos((string)$out['text_body'], 'Includes VAT and tourism levy') !== false && strpos((string)$out['text_body'], '<p') === false, 'stored row: plain-text part gets the line as plain text');
    $newRow = $oldRow;
    $newRow['html_body'] = '<html><body>{{changes_html}}<div>{{tax_rows_html}}</div></body></html>';
    check(substr_count((string)rh_render_booking_email_template_row('booking_modified', $newRow, $vars)['html_body'], 'Includes VAT and tourism levy') === 1, 'row that places the token: not added twice');
    check(rh_booking_modified_ensure_tax_rows('<html><body><p>x</p></body></html>') === '<html><body><p>x</p>{{tax_rows_html}}</body></html>', 'no change list: line goes before </body>');
    $other = rh_render_booking_email_template_row('booking_confirmed', $oldRow, $vars);
    check(strpos((string)$other['html_body'], 'Includes VAT') === false, 'other emails are not touched');
    // Same figures as the fixed wording (rh_booking_tax_rows_html): the includes note, never the amounts, in both VAT modes.
    $savedSettings = $GLOBALS['_SITE_SETTINGS'] ?? null;
    $bkRow = ['total_amount' => 767600, 'vat_amount' => 133000, 'tourism_levy_amount' => 7600, 'total_with_vat' => 900600];
    foreach (['inclusive', 'exclusive'] as $mode) {
        $GLOBALS['_SITE_SETTINGS'] = array_merge((array)($GLOBALS['_SITE_SETTINGS'] ?? []), ['vat_enabled' => '1', 'vat_rate' => '17.5', 'vat_pricing_mode' => $mode,
            'tourism_levy_enabled' => '1', 'tourism_levy_percent' => '1']);
        $line = rh_booking_tax_rows_html($bkRow);
        check(strpos($line, 'Includes VAT and tourism levy') !== false && strpos($line, '133') === false && strpos($line, '7,600') === false, "$mode VAT: the line says what the total includes, no amounts");
        check(rh_booking_owed($bkRow)['owed'] == 900600.0, "$mode VAT: the total is the guest total the fixed wording prints");
    }
    $GLOBALS['_SITE_SETTINGS'] = array_merge((array)($GLOBALS['_SITE_SETTINGS'] ?? []), ['vat_enabled' => '0', 'tourism_levy_enabled' => '0']);
    check(rh_booking_tax_rows_html($bkRow) === '', 'VAT and levy both off: no line');
    if ($savedSettings === null) { unset($GLOBALS['_SITE_SETTINGS']); } else { $GLOBALS['_SITE_SETTINGS'] = $savedSettings; }
    $modSrc = substr($emSrc, (int)strpos($emSrc, 'function sendBookingModifiedEmail('), 6000);
    check(strpos($modSrc, "\$templateVars['tax_rows_html'] = rh_booking_tax_rows_html(\$booking);") !== false, 'sendBookingModifiedEmail fills the token from the same helper as its fallback');
} else {
    echo "SKIP VAT line: database not loaded or not the test hotel\n";
}

echo "\n$pass passed, $fail failed\n";
exit($fail > 0 ? 1 : 0);
