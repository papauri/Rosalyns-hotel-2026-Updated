<?php

/**
 * Assistant action cards (.claude/AI_ASSISTANT_PLAN.md Phase 4 "Actions with confirm" + §Map "Actions").
 *
 * The Assistant never changes business data. An action tool only PROPOSES: it works out a read-only preview on
 * the server (same helpers the screens use), stores one ai_actions row (status proposed, 15-minute expiry, fresh
 * client_uuid) and returns a card. A person taps Confirm; admin/api/ai-action.php re-checks the owner, expiry and
 * permissions, re-runs the preview (a changed price or availability gives a new card instead), marks the row
 * confirmed (single use, row lock) and hands back the replay: the browser then posts to the EXISTING screen
 * endpoint with the person's own session, security token and the X-Requested-With header, so every rule, lock,
 * permission, email and timeline entry is exactly the screen's. The browser reports the endpoint's answer back;
 * the outcome is checked against the database (executed / failed) and written to the activity log "via Assistant".
 *
 * Registry entry: key => {tool, label, description, schema, perm (all-of), page (replayed screen: its page
 * permission and module are checked too), gate (optional), preview, verify}.
 * There is no action for stock, recipes, till orders, payments, refunds, invoices (other than sending the
 * existing one), credit notes, shift / end-of-day close, rates, prices or deals (hard limits; tested).
 * Guest names, emails and phones stay on the server: the model sees only privacy tokens.
 */

require_once __DIR__ . '/ai-core.php';
require_once __DIR__ . '/ai-vault.php';
require_once __DIR__ . '/ai-tools.php';

if (!defined('RH_AI_CARD_MINUTES')) {
    define('RH_AI_CARD_MINUTES', 15);       // a card can be confirmed for 15 minutes
}
if (!defined('RH_AI_CARDS_PER_TURN')) {
    define('RH_AI_CARDS_PER_TURN', 3);      // most cards one question may prepare
}

/** Action cards switch (kill switch layer 4): '0' hides every card; chat keeps working. */
function rh_ai_actions_enabled(): bool
{
    return rh_ai_setting('ai_actions_enabled', '1') !== '0';
}

function rh_ai_action_registry(): array
{
    $date = ['type' => 'string', 'description' => 'Date as YYYY-MM-DD', 'format' => 'date'];
    $bk = ['booking_id' => ['type' => 'integer', 'minimum' => 1], 'reference' => ['type' => 'string', 'maxLength' => 40, 'description' => 'Booking reference (a [BK_n] token is fine)']];
    return [
        'create_booking' => [
            'tool' => 'propose_create_booking', 'label' => 'New booking', 'page' => 'create-booking.php', 'perm' => ['create_booking'],
            'description' => 'Prepare a new room booking card (one room type, one or more rooms). Priced exactly like Create Booking. Guest name, email and phone may be tokens or left out: the person fills them in on the card. Set payment_requested when they want to take a payment, other_room_types when they want more than one room type: the card then hands over to the Create Booking screen.',
            'schema' => ['properties' => [
                'room_type' => ['type' => 'string', 'maxLength' => 80], 'check_in' => $date, 'check_out' => $date,
                'adults' => ['type' => 'integer', 'minimum' => 1, 'maximum' => 40], 'children' => ['type' => 'integer', 'minimum' => 0, 'maximum' => 20],
                'rooms' => ['type' => 'integer', 'minimum' => 1, 'maximum' => 10], 'occupancy' => ['type' => 'string', 'enum' => ['single', 'double', 'triple']],
                'status' => ['type' => 'string', 'enum' => ['confirmed', 'pending', 'tentative']],
                'guest_name' => ['type' => 'string', 'maxLength' => 120], 'guest_email' => ['type' => 'string', 'maxLength' => 160], 'guest_phone' => ['type' => 'string', 'maxLength' => 40],
                'guest_country' => ['type' => 'string', 'maxLength' => 60], 'special_requests' => ['type' => 'string', 'maxLength' => 500],
                'payment_requested' => ['type' => 'boolean'], 'other_room_types' => ['type' => 'string', 'maxLength' => 120],
            ], 'required' => ['room_type', 'check_in', 'check_out', 'adults']],
            'preview' => 'rh_ai_pv_create_booking', 'verify' => 'rh_ai_vf_create_booking',
        ],
        'edit_booking' => [
            'tool' => 'propose_edit_booking', 'label' => 'Change booking', 'page' => 'bookings.php', 'perm' => ['edit_booking', 'quick_modify_booking'],
            'description' => 'Prepare a quick change to a booking: guest name, email, phone, country, dates (before arrival), number of adults or special requests. Shows old and new values and the new total.',
            'schema' => ['properties' => $bk + ['guest_name' => ['type' => 'string', 'maxLength' => 120], 'guest_email' => ['type' => 'string', 'maxLength' => 160], 'guest_phone' => ['type' => 'string', 'maxLength' => 40],
                'guest_country' => ['type' => 'string', 'maxLength' => 60], 'check_in' => $date, 'check_out' => $date, 'adults' => ['type' => 'integer', 'minimum' => 1, 'maximum' => 40],
                'special_requests' => ['type' => 'string', 'maxLength' => 500], 'note' => ['type' => 'string', 'maxLength' => 200]]],
            'preview' => 'rh_ai_pv_edit_booking', 'verify' => 'rh_ai_vf_json',
        ],
        'extend_stay' => [
            'tool' => 'propose_extend_stay', 'label' => 'Extend stay', 'page' => 'bookings.php', 'perm' => [],
            'description' => 'Prepare a card to extend an in-house guest\'s stay to a later check-out date (booked nightly rate).',
            'schema' => ['properties' => $bk + ['new_checkout' => $date], 'required' => ['new_checkout']],
            'preview' => 'rh_ai_pv_extend_stay', 'verify' => 'rh_ai_vf_checkout_date',
        ],
        'change_checkout' => [
            'tool' => 'propose_change_checkout', 'label' => 'Correct check-out date', 'page' => 'bookings.php', 'perm' => ['edit_booking'], 'gate' => 'rh_ai_gate_admin_manager',
            'description' => 'Administrators and managers: prepare a correction of an in-house guest\'s check-out date (earlier or later) with a reason.',
            'schema' => ['properties' => $bk + ['new_checkout' => $date, 'reason' => ['type' => 'string', 'maxLength' => 200]], 'required' => ['new_checkout']],
            'preview' => 'rh_ai_pv_change_checkout', 'verify' => 'rh_ai_vf_checkout_date',
        ],
        'room_type_change' => [
            'tool' => 'propose_room_type_change', 'label' => 'Change room type', 'page' => 'bookings.php', 'perm' => ['edit_booking'],
            'description' => 'Prepare a move of a booking to another room type (paid upgrade or downgrade, priced like the Bookings screen).',
            'schema' => ['properties' => $bk + ['new_room_type' => ['type' => 'string', 'maxLength' => 80], 'note' => ['type' => 'string', 'maxLength' => 200], 'email_guest' => ['type' => 'boolean']], 'required' => ['new_room_type']],
            'preview' => 'rh_ai_pv_room_type_change', 'verify' => 'rh_ai_vf_room_type',
        ],
        'cancel_booking' => [
            'tool' => 'propose_cancel_booking', 'label' => 'Cancel booking', 'page' => 'bookings.php', 'perm' => ['cancel_booking'],
            'description' => 'Prepare a cancellation card. It shows what the hotel\'s cancellation rule does with money paid; it never works out a refund (staff handle refunds).',
            'schema' => ['properties' => $bk + ['reason' => ['type' => 'string', 'maxLength' => 200]]],
            'preview' => 'rh_ai_pv_cancel_booking', 'verify' => 'rh_ai_vf_status',
        ],
        'check_in' => [
            'tool' => 'propose_check_in', 'label' => 'Check in', 'page' => 'bookings.php', 'perm' => ['checkin_guest'],
            'description' => 'Prepare a check-in card, optionally assigning a room number first.',
            'schema' => ['properties' => $bk + ['room' => ['type' => 'string', 'maxLength' => 20, 'description' => 'Room number to assign first (optional)']]],
            'preview' => 'rh_ai_pv_check_in', 'verify' => 'rh_ai_vf_status',
        ],
        'assign_room' => [
            'tool' => 'propose_assign_room', 'label' => 'Assign room', 'page' => 'bookings.php', 'perm' => [],
            'description' => 'Prepare a card assigning a room number to a booking (or suggest the best free, clean room of its type).',
            'schema' => ['properties' => $bk + ['room' => ['type' => 'string', 'maxLength' => 20]]],
            'preview' => 'rh_ai_pv_assign_room', 'verify' => 'rh_ai_vf_assign_room',
        ],
        'check_out' => [
            'tool' => 'propose_check_out', 'label' => 'Check out', 'page' => 'bookings.php', 'perm' => ['checkout_guest'],
            'description' => 'Prepare a check-out card. Only when nothing is owed and the guest leaves on the booked day; otherwise the card opens checkout on Bookings.',
            'schema' => ['properties' => $bk],
            'preview' => 'rh_ai_pv_check_out', 'verify' => 'rh_ai_vf_status',
        ],
        'housekeeping_job' => [
            'tool' => 'propose_housekeeping_job', 'label' => 'New housekeeping job', 'page' => 'housekeeping.php', 'perm' => ['housekeeping'],
            'description' => 'Prepare a new housekeeping job for one room.',
            'schema' => ['properties' => ['room' => ['type' => 'string', 'maxLength' => 20], 'job' => ['type' => 'string', 'enum' => ['checkout_cleanup', 'regular_cleaning', 'deep_clean', 'turn_down', 'maintenance']],
                'priority' => ['type' => 'string', 'enum' => ['high', 'medium', 'low']], 'due_date' => $date, 'notes' => ['type' => 'string', 'maxLength' => 300]], 'required' => ['room']],
            'preview' => 'rh_ai_pv_housekeeping_job', 'verify' => 'rh_ai_vf_housekeeping_job',
        ],
        'maintenance_job' => [
            'tool' => 'propose_maintenance_job', 'label' => 'New maintenance job', 'page' => 'room-maintenance.php', 'perm' => ['room_maintenance'],
            'description' => 'Prepare a new maintenance job for one room. block_room takes the room off sale until the job ends.',
            'schema' => ['properties' => ['room' => ['type' => 'string', 'maxLength' => 20], 'title' => ['type' => 'string', 'maxLength' => 120], 'description' => ['type' => 'string', 'maxLength' => 500],
                'type' => ['type' => 'string', 'enum' => ['repair', 'replacement', 'inspection', 'upgrade', 'emergency']], 'priority' => ['type' => 'string', 'enum' => ['low', 'medium', 'high', 'urgent']],
                'due_date' => $date, 'block_room' => ['type' => 'boolean']], 'required' => ['room', 'title']],
            'preview' => 'rh_ai_pv_maintenance_job', 'verify' => 'rh_ai_vf_maintenance_job',
        ],
        'room_status' => [
            'tool' => 'propose_room_status', 'label' => 'Room status', 'page' => 'individual-rooms.php', 'perm' => ['individual_rooms'],
            'description' => 'Prepare a change of one room\'s status (available, cleaning, maintenance, out of order). Maintenance and out of order take it off sale.',
            'schema' => ['properties' => ['room' => ['type' => 'string', 'maxLength' => 20], 'status' => ['type' => 'string', 'enum' => ['available', 'cleaning', 'maintenance', 'out_of_order']], 'reason' => ['type' => 'string', 'maxLength' => 200]], 'required' => ['room', 'status']],
            'preview' => 'rh_ai_pv_room_status', 'verify' => 'rh_ai_vf_room_status',
        ],
        'send_email' => [
            'tool' => 'propose_send_email', 'label' => 'Send guest email', 'page' => 'bookings.php', 'perm' => [],
            'description' => 'Prepare sending one of the booking emails again (received, confirmed, tentative, converted, cancelled, reminder, invoice).',
            'schema' => ['properties' => $bk + ['email_type' => ['type' => 'string', 'enum' => ['booking_received', 'booking_confirmed', 'tentative_confirmed', 'tentative_converted', 'booking_cancelled', 'booking_reminder', 'invoice']]], 'required' => ['email_type']],
            'preview' => 'rh_ai_pv_send_email', 'verify' => 'rh_ai_vf_json',
        ],
        'send_invoice' => [
            'tool' => 'propose_send_invoice', 'label' => 'Send invoice', 'page' => 'bookings.php', 'perm' => [],
            'description' => 'Prepare emailing the booking\'s invoice to the guest.',
            'schema' => ['properties' => $bk],
            'preview' => 'rh_ai_pv_send_invoice', 'verify' => 'rh_ai_vf_json',
        ],
        'whatsapp' => [
            'tool' => 'propose_whatsapp', 'label' => 'WhatsApp the guest', 'page' => 'booking-details.php', 'perm' => ['bookings'],
            'description' => 'WhatsApp messages are sent from the booking screen: this gives a card that opens it.',
            'schema' => ['properties' => $bk],
            'preview' => 'rh_ai_pv_whatsapp', 'verify' => 'rh_ai_vf_json',
        ],
    ];
}

/** Actions a briefing point may prepare with one tap (no model call). */
function rh_ai_tap_actions(): array
{
    return ['check_out', 'check_in', 'assign_room'];
}

function rh_ai_gate_admin_manager(array $user): bool
{
    return in_array((string)($user['role'] ?? ''), ['admin', 'manager'], true);
}

/** May this person prepare / confirm this action right now? '' = yes, otherwise the plain reason. */
function rh_ai_action_problem(array $user, string $key): string
{
    $def = rh_ai_action_registry()[$key] ?? null;
    $uid = (int)($user['id'] ?? 0);
    if ($def === null) {
        return 'There is no such action.';
    }
    if ($uid <= 0 || !function_exists('hasPermission') || !hasPermission($uid, 'ai_assistant_use') || !hasPermission($uid, 'ai_assistant_actions')) {
        return 'Your account cannot confirm Assistant actions.';
    }
    if (!rh_ai_actions_enabled()) {
        return 'Assistant action cards are switched off.';
    }
    $page = (string)$def['page'];
    $need = $def['perm'];
    $pagePerm = function_exists('getPermissionForPage') ? getPermissionForPage($page) : null;
    if ($pagePerm !== null) {
        $need[] = $pagePerm;
    }
    foreach (array_unique($need) as $p) {
        if (!hasPermission($uid, $p)) {
            return "That's outside what your account can do.";
        }
    }
    $mod = function_exists('getModuleForPage') ? getModuleForPage($page) : null;
    if ($mod !== null && function_exists('rh_module_key_enabled') && !rh_module_key_enabled($mod)) {
        return 'That part of the system is switched off.';
    }
    if (!empty($def['gate']) && !call_user_func($def['gate'], $user)) {
        return "That's outside what your account can do.";
    }
    return '';
}

function rh_ai_action_allowed(array $user, string $key): bool
{
    return rh_ai_action_problem($user, $key) === '';
}

/** Action tool specs for the model (only those this person may use), sorted by name. */
function rh_ai_action_specs(array $user): array
{
    $specs = [];
    foreach (rh_ai_action_registry() as $key => $def) {
        if (rh_ai_action_allowed($user, $key)) {
            $specs[$def['tool']] = ['name' => $def['tool'], 'description' => $def['description'], 'schema' => $def['schema']];
        }
    }
    ksort($specs);
    return array_values($specs);
}

/** Action key for a tool name ('' when it is not an action tool). */
function rh_ai_action_key_for_tool(string $tool): string
{
    foreach (rh_ai_action_registry() as $key => $def) {
        if ($def['tool'] === $tool) {
            return $key;
        }
    }
    return '';
}

// ── Small helpers (read only) ────────────────────────────────────────────────

function rh_ai_uuid4(): string
{
    $b = random_bytes(16);
    $b[6] = chr((ord($b[6]) & 0x0f) | 0x40);
    $b[8] = chr((ord($b[8]) & 0x3f) | 0x80);
    return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($b), 4));
}

function rh_ai_fmt_money(float $v): string
{
    $c = rh_ai_currency();
    return ($c !== '' ? $c . ' ' : '') . number_format($v, 2);
}

function rh_ai_fmt_date(string $d): string
{
    $t = strtotime($d);
    return $t ? date('D j M Y', $t) : $d;
}

/** "j***@gmail.com" - the recipient shown on a card. */
function rh_ai_mask_email(string $e): string
{
    $e = trim($e);
    if ($e === '' || strpos($e, '@') === false) {
        return $e === '' ? 'no email on the booking' : $e;
    }
    [$l, $d] = explode('@', $e, 2);
    return mb_substr($l, 0, 1) . '***@' . $d;
}

function rh_ai_mask_phone(string $p): string
{
    $digits = preg_replace('/\D+/', '', $p);
    return $digits === '' ? 'no phone on the booking' : 'number ending ' . substr($digits, -3);
}

/** One booking by id or reference (live rows only). */
function rh_ai_act_booking(PDO $db, array $a): array
{
    if (empty($a['booking_id']) && empty($a['reference'])) {
        return ['error' => 'Say which booking (its reference or id).'];
    }
    $st = $db->prepare("SELECT b.*, r.name AS room_name, ir.room_number FROM bookings b LEFT JOIN rooms r ON r.id = b.room_id
                        LEFT JOIN individual_rooms ir ON ir.id = b.individual_room_id
                        WHERE b.deleted_at IS NULL AND " . (!empty($a['booking_id']) ? 'b.id = ?' : 'b.booking_reference = ?') . " LIMIT 1");
    $st->execute([!empty($a['booking_id']) ? (int)$a['booking_id'] : (string)$a['reference']]);
    $b = $st->fetch(PDO::FETCH_ASSOC);
    return $b ?: ['error' => 'No booking found for that reference.'];
}

/** One active physical room by its number (or id). */
function rh_ai_act_room(PDO $db, string $q): ?array
{
    $q = trim(preg_replace('/^room\s+/i', '', $q));
    if ($q === '') {
        return null;
    }
    $st = $db->prepare("SELECT ir.*, r.name AS type_name FROM individual_rooms ir LEFT JOIN rooms r ON r.id = ir.room_type_id WHERE ir.is_active = 1 AND ir.room_number = ? LIMIT 1");
    $st->execute([$q]);
    $r = $st->fetch(PDO::FETCH_ASSOC);
    if (!$r && ctype_digit($q)) {
        $st = $db->prepare("SELECT ir.*, r.name AS type_name FROM individual_rooms ir LEFT JOIN rooms r ON r.id = ir.room_type_id WHERE ir.is_active = 1 AND ir.id = ? LIMIT 1");
        $st->execute([(int)$q]);
        $r = $st->fetch(PDO::FETCH_ASSOC);
    }
    return $r ?: null;
}

/** Common booking facts for the model (tokenised later) and the card's first rows. */
function rh_ai_bk_rows(array $b): array
{
    return [
        ['label' => 'Booking', 'new' => (string)$b['booking_reference']],
        ['label' => 'Guest', 'new' => (string)$b['guest_name']],
        ['label' => 'Stay', 'new' => rh_ai_fmt_date((string)$b['check_in_date']) . ' to ' . rh_ai_fmt_date((string)$b['check_out_date'])],
        ['label' => 'Room', 'new' => trim((string)($b['room_name'] ?? '') . (!empty($b['room_number']) ? ', room ' . $b['room_number'] : ', no room assigned yet'))],
    ];
}

function rh_ai_bk_facts(array $b): array
{
    return ['booking_id' => (int)$b['id'], 'reference' => (string)$b['booking_reference'], 'guest_name' => (string)$b['guest_name'], 'status' => (string)$b['status'],
        'check_in' => (string)$b['check_in_date'], 'check_out' => (string)$b['check_out_date'], 'room_type' => (string)($b['room_name'] ?? ''), 'room_number' => $b['room_number'] ?? null];
}

/** A card skeleton. */
function rh_ai_card_base(string $title, string $help, array $rows, string $confirm, array $opt = []): array
{
    return array_merge(['title' => $title, 'help' => $help, 'rows' => $rows, 'warn' => [], 'notes' => [], 'confirm' => $confirm,
        'danger' => false, 'handoff' => null, 'fields' => [], 'choices' => []], $opt);
}

/** One replay step: POST to an existing admin screen. $fields = [[name, value], ...]. */
function rh_ai_step(string $url, array $fields, string $guard = ''): array
{
    $s = ['url' => $url, 'fields' => array_map(static fn($f) => [(string)$f[0], (string)$f[1]], $fields)];
    if ($guard !== '') {
        $s['guard'] = $guard;
    }
    return $s;
}

// ── Previews (read only, server-computed) ────────────────────────────────────
// Each returns ['error'] or ['card', 'steps', 'fp' (what must not change before Confirm), 'facts' (for the model)].

function rh_ai_pv_create_booking(PDO $db, array $user, array $a): array
{
    $q = rh_ai_t_quote_stay($db, $user, ['room_type' => $a['room_type'], 'check_in' => $a['check_in'], 'check_out' => $a['check_out'],
        'adults' => $a['adults'], 'children' => $a['children'] ?? 0, 'rooms' => $a['rooms'] ?? 1] + (!empty($a['occupancy']) ? ['occupancy' => $a['occupancy']] : []));
    if (!empty($q['error'])) {
        return ['error' => (string)$q['error']];
    }
    if (empty($q['available'])) {
        return ['error' => 'Not available: ' . implode(' ', (array)$q['notes'])];
    }
    if ($a['check_in'] < date('Y-m-d')) {
        return ['error' => 'The check-in date is in the past.'];
    }
    $status = (string)($a['status'] ?? 'confirmed');
    if ($status === 'tentative' && getSetting('tentative_bookings_enabled', '1') === '0') {
        return ['error' => 'Tentative holds are switched off in this hotel.'];
    }
    $adults = (int)$a['adults'];
    $children = (int)($a['children'] ?? 0);
    $rows = [
        ['label' => 'Room type', 'new' => $q['room_type'] . ((int)$q['rooms'] > 1 ? ' x ' . (int)$q['rooms'] . ' rooms' : '')],
        ['label' => 'Stay', 'new' => rh_ai_fmt_date($q['check_in']) . ' to ' . rh_ai_fmt_date($q['check_out']) . ' (' . (int)$q['nights'] . ' night' . ((int)$q['nights'] === 1 ? '' : 's') . ')'],
        ['label' => 'Guests', 'new' => $adults . ' adult' . ($adults === 1 ? '' : 's') . ($children > 0 ? ', ' . $children . ' child' . ($children === 1 ? '' : 'ren') : '') . ', ' . $q['occupancy'] . ' occupancy'],
        ['label' => 'Price per night', 'new' => rh_ai_fmt_money((float)$q['price_per_night']) . ($q['rate_plan'] ? ' (' . $q['rate_plan'] . ')' : '')],
    ];
    if ((float)$q['child_charge'] > BALANCE_TOLERANCE) {
        $rows[] = ['label' => 'Child charge', 'new' => rh_ai_fmt_money((float)$q['child_charge'])];
    }
    if ((float)$q['vat'] > BALANCE_TOLERANCE) {
        $rows[] = ['label' => 'VAT (in the total)', 'new' => rh_ai_fmt_money((float)$q['vat'])];
    }
    if ((float)$q['levy'] > BALANCE_TOLERANCE) {
        $rows[] = ['label' => 'Tourism levy (in the total)', 'new' => rh_ai_fmt_money((float)$q['levy'])];
    }
    $rows[] = ['label' => 'Total', 'new' => rh_ai_fmt_money((float)$q['total']), 'tone' => 'money'];
    $facts = ['room_type' => $q['room_type'], 'check_in' => $q['check_in'], 'check_out' => $q['check_out'], 'nights' => $q['nights'], 'rooms' => $q['rooms'],
        'adults' => $adults, 'children' => $children, 'total' => $q['total'], 'currency' => $q['currency'], 'status' => $status];
    $prefill = ['guest_name' => (string)($a['guest_name'] ?? ''), 'guest_email' => (string)($a['guest_email'] ?? ''), 'guest_phone' => (string)($a['guest_phone'] ?? ''),
        'guest_country' => (string)($a['guest_country'] ?? ''), 'check_in_date' => $q['check_in'], 'check_out_date' => $q['check_out'],
        'number_of_guests' => (string)($adults + $children), 'child_guests' => (string)$children, 'special_requests' => (string)($a['special_requests'] ?? '')];
    if (!empty($a['payment_requested']) || trim((string)($a['other_room_types'] ?? '')) !== '') {
        $why = !empty($a['payment_requested']) ? 'Taking a payment is done on the Create Booking screen.' : 'A booking with more than one room type is finished on the Create Booking screen.';
        return ['card' => rh_ai_card_base('New booking: finish on Create Booking', $why . ' The guest, dates and party below are filled in for you; pick the rooms there.', $rows, '',
            ['handoff' => ['label' => 'Open in Create Booking', 'url' => 'create-booking.php', 'prefill' => array_filter($prefill, static fn($v) => $v !== '')]]),
            'steps' => [], 'fp' => [round((float)$q['total'], 2)], 'facts' => $facts + ['handoff' => 'create booking screen']];
    }
    $rows[] = ['label' => 'Status', 'new' => ['confirmed' => 'Confirmed', 'pending' => 'Pending', 'tentative' => 'Tentative (hold)'][$status] ?? $status];
    $name = trim((string)($a['guest_name'] ?? ''));
    $email = trim((string)($a['guest_email'] ?? ''));
    $phone = trim((string)($a['guest_phone'] ?? ''));
    $missing = [];
    if ($name === '') {
        $missing[] = 'name';
    }
    if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $missing[] = 'email';
    }
    if ($phone === '') {
        $missing[] = 'phone';
    }
    $card = rh_ai_card_base('New booking', 'Priced the same way as Create Booking. Room numbers are assigned automatically. Check the guest details, then confirm.', $rows, 'Create booking', [
        'fields' => [
            ['name' => 'guest_name', 'label' => 'Guest name', 'type' => 'text', 'value' => $name, 'required' => true],
            ['name' => 'guest_email', 'label' => 'Guest email', 'type' => 'email', 'value' => $email, 'required' => true],
            ['name' => 'guest_phone', 'label' => 'Guest phone', 'type' => 'tel', 'value' => $phone, 'required' => true],
            ['name' => 'special_requests', 'label' => 'Special requests (optional)', 'type' => 'text', 'value' => (string)($a['special_requests'] ?? ''), 'required' => false],
        ],
        'choices' => [['name' => 'email_guest', 'label' => 'Email the guest', 'value' => (string)($a['email_guest'] ?? '1') === '0' ? '0' : '1', 'options' => [['1', 'Yes, send the booking email'], ['0', 'No email']]]],
    ]);
    if ($missing) {
        $card['notes'][] = 'Fill in the guest ' . implode(', ', $missing) . ' before confirming.';
    }
    foreach ((array)$q['notes'] as $n) {
        $card['notes'][] = (string)$n;
    }
    $steps = [];
    if (!$missing) {
        $f = [['create_booking', '1'], ['response', 'json'], ['client_uuid', '{uuid}'], ['guest_name', $name], ['guest_email', $email], ['guest_phone', $phone],
            ['guest_country', (string)($a['guest_country'] ?? '')], ['check_in_date', $q['check_in']], ['check_out_date', $q['check_out']],
            ['number_of_guests', (string)($adults + $children)], ['child_guests', (string)$children], ['booking_status', $status], ['auto_assign_room', '1'],
            ['room_line_room_id[]', (string)$q['room_type_id']], ['room_line_qty[]', (string)(int)$q['rooms']], ['room_line_occupancy[]', (string)$q['occupancy']],
            ['special_requests', (string)($a['special_requests'] ?? '')]];
        if (($a['email_guest'] ?? '1') !== '0') {
            $f[] = ['send_email', '1'];
        }
        $steps[] = rh_ai_step('create-booking.php', $f);
    }
    return ['card' => $card, 'steps' => $steps, 'fp' => [round((float)$q['total'], 2), true], 'facts' => $facts + ['guest_name' => $name, 'contact_missing' => $missing],
        'total' => round((float)$q['total'], 2), 'incomplete' => (bool)$missing];
}

function rh_ai_pv_edit_booking(PDO $db, array $user, array $a): array
{
    $b = rh_ai_act_booking($db, $a);
    if (!empty($b['error'])) {
        return $b;
    }
    if (!in_array((string)$b['status'], ['pending', 'tentative', 'confirmed', 'checked-in'], true)) {
        return ['error' => 'This booking is ' . $b['status'] . ': guest details are corrected on the full edit page.'];
    }
    $map = ['guest_name' => 'guest_name', 'guest_email' => 'guest_email', 'guest_phone' => 'guest_phone', 'guest_country' => 'guest_country',
        'check_in' => 'check_in_date', 'check_out' => 'check_out_date', 'special_requests' => 'special_requests'];
    $labels = ['guest_name' => 'Guest name', 'guest_email' => 'Email', 'guest_phone' => 'Phone', 'guest_country' => 'Country', 'check_in_date' => 'Check-in', 'check_out_date' => 'Check-out', 'special_requests' => 'Special requests'];
    $changes = [];
    foreach ($map as $arg => $col) {
        if (isset($a[$arg]) && trim((string)$a[$arg]) !== trim((string)$b[$col])) {
            $changes[$col] = trim((string)$a[$arg]);
        }
    }
    if (isset($a['adults']) && (int)$a['adults'] !== (int)$b['adult_guests']) {
        $changes['adult_guests'] = (int)$a['adults'];
        $changes['number_of_guests'] = (int)$a['adults'] + (int)$b['child_guests'];
    }
    if (!$changes) {
        return ['error' => 'Nothing to change: those details already match the booking.'];
    }
    if (isset($changes['guest_email']) && !filter_var($changes['guest_email'], FILTER_VALIDATE_EMAIL)) {
        return ['error' => 'That email address does not look right.'];
    }
    $ci = $changes['check_in_date'] ?? (string)$b['check_in_date'];
    $co = $changes['check_out_date'] ?? (string)$b['check_out_date'];
    $datesChanged = isset($changes['check_in_date']) || isset($changes['check_out_date']);
    if ($datesChanged) {
        if ((string)$b['status'] === 'checked-in') {
            return ['error' => 'The guest is in-house: use Extend stay (or a check-out date correction) to change their dates.'];
        }
        if ($co <= $ci) {
            return ['error' => 'Check-out must be after check-in.'];
        }
        if (isset($changes['check_in_date']) && $ci < date('Y-m-d')) {
            return ['error' => 'The check-in date cannot be moved into the past.'];
        }
    }
    $rows = [['label' => 'Booking', 'new' => (string)$b['booking_reference']]];
    foreach ($changes as $col => $v) {
        if ($col === 'number_of_guests') {
            continue;
        }
        $old = $col === 'adult_guests' ? (string)(int)$b['adult_guests'] : (string)$b[$col];
        $rows[] = ['label' => $col === 'adult_guests' ? 'Adults' : $labels[$col], 'old' => in_array($col, ['check_in_date', 'check_out_date'], true) ? rh_ai_fmt_date($old) : $old,
            'new' => in_array($col, ['check_in_date', 'check_out_date'], true) ? rh_ai_fmt_date((string)$v) : (string)$v];
    }
    $fp = [];
    $total = null;
    if ($datesChanged) {
        $av = checkRoomAvailability((int)$b['room_id'], $ci, $co, (int)$b['id'], (int)$b['child_guests']);
        if (empty($av['available'])) {
            return ['error' => 'Not available for the new dates: ' . (string)($av['error'] ?? 'no room of this type is free.')];
        }
        $nights = max(1, (int)round((strtotime($co) - strtotime($ci)) / 86400));
        $rp = rh_reprice_at_booked_rate($b, $nights);
        $total = round((float)$rp['new_gross'], 2);
        $rows[] = ['label' => 'Nights', 'old' => (string)(int)$b['number_of_nights'], 'new' => (string)$nights];
        $rows[] = ['label' => 'Total', 'old' => rh_ai_fmt_money((float)$rp['old_gross']), 'new' => rh_ai_fmt_money($total), 'tone' => 'money'];
        $fp = [$total, true];
    }
    $fields = [['action', 'modify_booking'], ['id', (string)(int)$b['id']]];
    foreach ($changes as $col => $v) {
        $fields[] = [$col, (string)$v];
    }
    $fields[] = ['note', trim('Changed via the Assistant. ' . (string)($a['note'] ?? ''))];
    $card = rh_ai_card_base('Change booking', 'Old value on the left, new on the right. Dates are repriced at the guest\'s booked nightly rate, the same as the Bookings screen.', $rows, 'Save changes');
    $card['notes'][] = 'The guest gets the usual "booking changed" email when dates, guests or contact details change.';
    return ['card' => $card, 'steps' => [rh_ai_step('bookings.php', $fields)], 'fp' => $fp, 'facts' => rh_ai_bk_facts($b) + ['changes' => array_keys($changes), 'new_total' => $total]];
}

/** Shared by extend stay and the check-out date correction. */
function rh_ai_pv_new_checkout(PDO $db, array $user, array $a, bool $correction): array
{
    $b = rh_ai_act_booking($db, $a);
    if (!empty($b['error'])) {
        return $b;
    }
    if ((string)$b['status'] !== 'checked-in') {
        return ['error' => 'Only an in-house (checked-in) guest\'s check-out date can be changed here; this booking is ' . $b['status'] . '.'];
    }
    $new = (string)$a['new_checkout'];
    $old = (string)$b['check_out_date'];
    if ($new <= (string)$b['check_in_date']) {
        return ['error' => 'The new check-out date must be after check-in.'];
    }
    if (!$correction) {
        if ($new <= $old) {
            return ['error' => 'To extend, the new check-out date must be after ' . $old . '.'];
        }
        if ($new < date('Y-m-d')) {
            return ['error' => 'The new check-out date cannot be in the past.'];
        }
    } elseif ($new === $old) {
        return ['error' => 'That is already the check-out date.'];
    }
    if ($new > $old) {
        $av = checkRoomAvailability((int)$b['room_id'], $old, $new, (int)$b['id'], (int)$b['child_guests']);
        if (empty($av['available'])) {
            return ['error' => 'The room type is not free for the extra nights: ' . (string)($av['error'] ?? 'fully booked.')];
        }
    }
    $nights = max(1, (int)(new DateTime((string)$b['check_in_date']))->diff(new DateTime($new))->days);
    $rp = rh_reprice_at_booked_rate($b, $nights);
    $total = round((float)$rp['new_gross'], 2);
    $rows = rh_ai_bk_rows($b);
    $rows[] = ['label' => 'Check-out', 'old' => rh_ai_fmt_date($old), 'new' => rh_ai_fmt_date($new)];
    $rows[] = ['label' => 'Nights', 'old' => (string)(int)$b['number_of_nights'], 'new' => (string)$nights];
    $rows[] = ['label' => 'Total', 'old' => rh_ai_fmt_money((float)$rp['old_gross']), 'new' => rh_ai_fmt_money($total), 'tone' => 'money'];
    if ($correction) {
        $reason = trim((string)($a['reason'] ?? ''));
        $card = rh_ai_card_base('Correct check-out date', 'Administrators and managers: fixes a wrong check-out date. The stay is repriced at the booked nightly rate.', $rows, 'Correct the date',
            ['fields' => [['name' => 'reason', 'label' => 'Reason', 'type' => 'text', 'value' => $reason, 'required' => true]]]);
        $steps = [rh_ai_step('bookings.php', [['action', 'admin_update_checkout_date'], ['booking_id', (string)(int)$b['id']], ['new_checkout', $new], ['reason', $reason !== '' ? $reason . ' (via the Assistant)' : 'Via the Assistant']])];
        if ($reason === '') {
            $card['notes'][] = 'Type the reason before confirming.';
            $steps = [];
        }
    } else {
        $card = rh_ai_card_base('Extend stay', 'Adds nights at the guest\'s booked nightly rate, the same as Extend stay on Bookings.', $rows, 'Extend stay');
        $steps = [rh_ai_step('bookings.php', [['action', 'extend_stay'], ['booking_id', (string)(int)$b['id']], ['new_checkout', $new]])];
    }
    return ['card' => $card, 'steps' => $steps, 'fp' => [$total], 'facts' => rh_ai_bk_facts($b) + ['new_checkout' => $new, 'new_total' => $total], 'incomplete' => !$steps];
}

function rh_ai_pv_extend_stay(PDO $db, array $user, array $a): array
{
    return rh_ai_pv_new_checkout($db, $user, $a, false);
}

function rh_ai_pv_change_checkout(PDO $db, array $user, array $a): array
{
    return rh_ai_pv_new_checkout($db, $user, $a, true);
}

function rh_ai_pv_room_type_change(PDO $db, array $user, array $a): array
{
    $b = rh_ai_act_booking($db, $a);
    if (!empty($b['error'])) {
        return $b;
    }
    $t = rh_ai_find_room_type($db, (string)$a['new_room_type']);
    if (!$t) {
        return ['error' => 'No room type matches "' . $a['new_room_type'] . '".'];
    }
    if (!function_exists('rh_room_change_options')) {
        require_once dirname(__DIR__) . '/booking-room-change.php';
    }
    try {
        $o = rh_room_change_options($db, (int)$b['id']);
    } catch (Throwable $e) {
        return ['error' => $e->getMessage()];
    }
    $opt = null;
    foreach ($o['options'] as $x) {
        if ((int)$x['id'] === (int)$t['id']) {
            $opt = $x;
        }
    }
    if (!$opt) {
        return ['error' => 'The booking is already ' . $t['name'] . '.'];
    }
    if (!$opt['available']) {
        return ['error' => $t['name'] . ': ' . $opt['unavailable_reason']];
    }
    $qt = $opt['quote'];
    $reasons = rh_room_change_reasons((string)$qt['direction']);
    $code = (string)array_key_first($reasons);
    foreach (array_keys($reasons) as $k) {
        if (strpos($k, 'guest_request') !== false) {
            $code = $k;
        }
    }
    $rows = rh_ai_bk_rows($b);
    $rows[] = ['label' => 'Room type', 'old' => (string)$b['room_name'], 'new' => (string)$t['name']];
    $rows[] = ['label' => 'Nights re-rated', 'new' => (string)(int)$qt['affected']];
    $rows[] = ['label' => 'Total', 'old' => rh_ai_fmt_money((float)$qt['current_total']), 'new' => rh_ai_fmt_money((float)$qt['charge_total']), 'tone' => 'money'];
    $dir = ['upgrade' => 'Paid upgrade', 'downgrade' => 'Downgrade (bill reduced)', 'same' => 'Room type change'][$qt['direction']] ?? 'Room type change';
    $card = rh_ai_card_base($dir, 'Priced like Change room type on Bookings: nights still to come move to the new type\'s rate. Keeping the old price (complimentary upgrade) is done on the Bookings screen.', $rows, 'Change room type', [
        'choices' => [['name' => 'reason_code', 'label' => 'Reason', 'value' => $code, 'options' => array_map(null, array_keys($reasons), array_values($reasons))]],
    ]);
    $chosen = isset($a['reason_code']) && isset($reasons[$a['reason_code']]) ? (string)$a['reason_code'] : $code;
    $card['choices'][0]['value'] = $chosen;
    $steps = [rh_ai_step('bookings.php', [['action', 'upgrade_room_type'], ['booking_id', (string)(int)$b['id']], ['new_room_id', (string)(int)$t['id']], ['pricing', 'charge'],
        ['reason_code', $chosen], ['note', trim('Via the Assistant. ' . (string)($a['note'] ?? ''))], ['send_email', !empty($a['email_guest']) ? '1' : '0']])];
    return ['card' => $card, 'steps' => $steps, 'fp' => [round((float)$qt['charge_total'], 2), true], 'facts' => rh_ai_bk_facts($b) + ['new_room_type' => $t['name'], 'new_total' => $qt['charge_total'], 'direction' => $qt['direction']], 'new_room_id' => (int)$t['id']];
}

function rh_ai_pv_cancel_booking(PDO $db, array $user, array $a): array
{
    $b = rh_ai_act_booking($db, $a);
    if (!empty($b['error'])) {
        return $b;
    }
    $tr = validateBookingStatusTransition((string)$b['status'], 'cancelled');
    if (empty($tr['allowed'])) {
        return ['error' => (string)$tr['reason']];
    }
    $f = function_exists('getBookingFolioSummary') ? getBookingFolioSummary((int)$b['id']) : [];
    $paid = round((float)($f['amount_paid'] ?? 0), 2);
    $owed = round((float)($f['balance_due'] ?? 0), 2);
    $group = function_exists('rh_booking_group_members') ? rh_booking_group_members($db, (int)$b['id']) : [];
    $n = max(1, count($group));
    $mode = getCancellationRefundModeLabel();
    $rows = rh_ai_bk_rows($b);
    $rows[] = ['label' => 'Status', 'old' => ucfirst(str_replace('-', ' ', (string)$b['status'])), 'new' => 'Cancelled', 'tone' => 'danger'];
    $rows[] = ['label' => 'Paid so far', 'new' => rh_ai_fmt_money($paid)];
    $rows[] = ['label' => 'Still owed', 'new' => rh_ai_fmt_money($owed)];
    $rows[] = ['label' => 'Money paid', 'new' => $mode];
    $reason = trim((string)($a['reason'] ?? ''));
    $scope = ($a['scope'] ?? 'single') === 'group' && $n > 1 ? 'group' : 'single';
    $card = rh_ai_card_base('Cancel booking', 'The cancellation rule set in Booking Settings decides what happens to money paid. Refunds are recorded by staff on screen; this card never works one out.', $rows, 'Cancel booking', [
        'danger' => true,
        'fields' => [['name' => 'reason', 'label' => 'Reason for cancelling', 'type' => 'text', 'value' => $reason, 'required' => true]],
    ]);
    if ($n > 1) {
        $card['choices'][] = ['name' => 'scope', 'label' => 'Booked together: ' . $n . ' rooms', 'value' => $scope, 'options' => [['single', 'Cancel only this room'], ['group', 'Cancel all ' . $n . ' rooms']]];
    }
    $card['warn'][] = 'Cancelling releases the room' . ($n > 1 && $scope === 'group' ? 's' : '') . ' and sends the guest the cancellation email.';
    $steps = [];
    if ($reason === '') {
        $card['notes'][] = 'Type the reason before confirming.';
    } else {
        $steps[] = rh_ai_step('bookings.php', [['action', 'update_status'], ['id', (string)(int)$b['id']], ['status', 'cancelled'], ['cancellation_reason', $reason], ['scope', $scope]]);
    }
    return ['card' => $card, 'steps' => $steps, 'fp' => [(string)$b['status'], $mode, $paid, $owed, $n], 'facts' => rh_ai_bk_facts($b) + ['paid' => $paid, 'still_owed' => $owed, 'cancellation_rule' => $mode, 'rooms_booked_together' => $n], 'incomplete' => !$steps, 'expect_status' => 'cancelled'];
}

/** A free, clean room of the booking's type (or the named one). Joined-room types return null (assigned as pairs on Bookings). */
function rh_ai_pick_room(PDO $db, array $b, string $want, bool $inHouse): array
{
    if (roomTypeHasActiveCombinations((int)$b['room_id'])) {
        return ['handoff' => true];
    }
    $ci = (string)$b['check_in_date'];
    if ($inHouse && $ci < date('Y-m-d')) {
        $ci = date('Y-m-d');
    }
    $c = rh_room_assignment_candidates((int)$b['room_id'], $ci, (string)$b['check_out_date'], (int)$b['id'], $inHouse);
    $want = trim(preg_replace('/^room\s+/i', '', $want));
    $free = [];
    foreach ($c['items'] as $it) {
        if ($want !== '' && (string)$it['label'] === $want) {
            if (!$it['available']) {
                return ['error' => 'Room ' . $want . ' cannot be used: ' . $it['reason']];
            }
            if ((int)$b['child_guests'] > 0 && (int)$it['children_allowed'] === 0) {
                return ['error' => 'Room ' . $want . ' does not take children; assign it on Bookings with an override note.'];
            }
            return ['room' => $it, 'options' => [$it]];
        }
        if ($it['available'] && !$it['current'] && ((int)$b['child_guests'] === 0 || (int)$it['children_allowed'] === 1)) {
            $free[] = $it;
        }
    }
    if ($want !== '') {
        return ['error' => 'Room ' . $want . ' is not a ' . $b['room_name'] . ' room.'];
    }
    return $free ? ['room' => $free[0], 'options' => array_slice($free, 0, 8)] : ['error' => 'No ' . $b['room_name'] . ' room is free for this stay.'];
}

function rh_ai_pv_assign_room(PDO $db, array $user, array $a): array
{
    $b = rh_ai_act_booking($db, $a);
    if (!empty($b['error'])) {
        return $b;
    }
    $v = validateRoomAssignment($b);
    if (empty($v['allowed'])) {
        return ['error' => function_exists('getBookingActionErrorMessage') ? getBookingActionErrorMessage('assign_room', (string)$v['reason']) : (string)$v['reason']];
    }
    $pick = rh_ai_pick_room($db, $b, (string)($a['room'] ?? ''), (string)$b['status'] === 'checked-in');
    if (!empty($pick['error'])) {
        return ['error' => $pick['error']];
    }
    $rows = rh_ai_bk_rows($b);
    if (!empty($pick['handoff'])) {
        return ['card' => rh_ai_card_base('Assign joined rooms on Bookings', 'Joined (family) rooms are assigned as a pair on the Bookings screen so both rooms stay together.', $rows, '',
            ['handoff' => ['label' => 'Open on Bookings', 'url' => 'bookings.php?action=assign-room&booking_id=' . (int)$b['id']]]), 'steps' => [], 'fp' => [], 'facts' => rh_ai_bk_facts($b) + ['handoff' => 'bookings screen']];
    }
    $chosen = !empty($a['room_id']) ? (int)$a['room_id'] : (int)$pick['room']['id'];
    $ok = false;
    foreach ($pick['options'] as $o) {
        $ok = $ok || (int)$o['id'] === $chosen;
    }
    if (!$ok) {
        $chosen = (int)$pick['room']['id'];
    }
    $label = '';
    foreach ($pick['options'] as $o) {
        if ((int)$o['id'] === $chosen) {
            $label = (string)$o['label'] . ($o['turnover'] !== '' ? ' (' . $o['turnover'] . ')' : '');
        }
    }
    $rows[3] = ['label' => 'Room', 'old' => !empty($b['room_number']) ? 'Room ' . $b['room_number'] : 'None yet', 'new' => 'Room ' . $label];
    $card = rh_ai_card_base('Assign room', 'Only rooms free for the whole stay are offered, the same list as Assign room on Bookings.', $rows, 'Assign room', [
        'choices' => [['name' => 'room_id', 'label' => 'Room', 'value' => (string)$chosen, 'options' => array_map(static fn($o) => [(string)$o['id'], 'Room ' . $o['label'] . ($o['turnover'] !== '' ? ' - ' . $o['turnover'] : '')], $pick['options'])]],
    ]);
    return ['card' => $card, 'steps' => [rh_ai_step('bookings.php', [['action', 'assign_individual_room'], ['booking_id', (string)(int)$b['id']], ['individual_room_id', (string)$chosen]])],
        'fp' => [$chosen], 'facts' => rh_ai_bk_facts($b) + ['room_offered' => $label], 'room_id' => $chosen];
}

function rh_ai_pv_check_in(PDO $db, array $user, array $a): array
{
    $b = rh_ai_act_booking($db, $a);
    if (!empty($b['error'])) {
        return $b;
    }
    $v = validateCheckIn($b);
    if (empty($v['allowed'])) {
        return ['error' => function_exists('getBookingActionErrorMessage') ? getBookingActionErrorMessage('check_in', (string)$v['reason']) : (string)$v['reason']];
    }
    $rows = rh_ai_bk_rows($b);
    $steps = [];
    $roomId = 0;
    if (!empty($a['room'])) {
        $pick = rh_ai_pick_room($db, $b, (string)$a['room'], false);
        if (!empty($pick['error'])) {
            return ['error' => $pick['error']];
        }
        if (!empty($pick['handoff'])) {
            return ['error' => 'Joined (family) rooms are assigned as a pair on Bookings first; then I can check the guest in.'];
        }
        $roomId = (int)$pick['room']['id'];
        $rows[3] = ['label' => 'Room', 'old' => !empty($b['room_number']) ? 'Room ' . $b['room_number'] : 'None yet', 'new' => 'Room ' . $pick['room']['label']];
        $steps[] = rh_ai_step('bookings.php', [['action', 'assign_individual_room'], ['booking_id', (string)(int)$b['id']], ['individual_room_id', (string)$roomId]]);
    }
    $f = rh_ai_folio((int)$b['id']);
    if ($f) {
        $rows[] = ['label' => 'Balance to collect', 'new' => rh_ai_fmt_money((float)$f['balance_due']), 'tone' => (float)$f['balance_due'] > BALANCE_TOLERANCE ? 'money' : ''];
    }
    $rows[] = ['label' => 'Status', 'old' => ucfirst((string)$b['status']), 'new' => 'Checked in'];
    $card = rh_ai_card_base('Check in', 'Uses the same check-in as Bookings (payment and clean-room rules included).', $rows, 'Check in');
    $override = !empty($a['_room_not_ready_ok']);
    $ready = true;
    if ($roomId === 0) {
        $gate = evaluateCheckInRoomReady((int)$b['id'], (int)$user['id'], $override);
        if (!$gate['allowed'] && !$gate['needs_confirm']) {
            return ['error' => (string)$gate['message']];
        }
        $ready = empty($gate['rooms']);
        if (!$ready) {
            $names = implode(', ', array_map(static fn($r) => 'room ' . $r['room_number'], $gate['rooms']));
            $card['warn'][] = $override
                ? 'Not marked clean: ' . $names . '. Confirming checks the guest in anyway; it is recorded in the booking timeline.'
                : (string)$gate['message'] . ' You will be asked to confirm a second time.';
        }
    } elseif (!empty($pick['room']['turnover'])) {
        $ready = false;
        $card['warn'][] = 'Room ' . $pick['room']['label'] . ': ' . $pick['room']['turnover'] . '. If it is not clean you will be asked to confirm again.';
    }
    if ($override) {
        $card['title'] = 'Check in anyway (room not clean)';
        $card['confirm'] = 'Check in anyway';
        $card['danger'] = true;
    }
    $ci = [['action', 'update_status'], ['id', (string)(int)$b['id']], ['status', 'checked-in'], ['checkin_note', 'Checked in via the Assistant']];
    if ($override) {
        $ci[] = ['confirm_checkin_room_not_ready', '1'];
    }
    $steps[] = rh_ai_step('bookings.php', $ci);
    $card['warn'] = array_values(array_filter($card['warn']));
    return ['card' => $card, 'steps' => $steps, 'fp' => [(string)$b['status'], (string)$b['payment_status'], $roomId, $ready], 'facts' => rh_ai_bk_facts($b) + ['room_ready' => $ready], 'expect_status' => 'checked-in'];
}

function rh_ai_pv_check_out(PDO $db, array $user, array $a): array
{
    $b = rh_ai_act_booking($db, $a);
    if (!empty($b['error'])) {
        return $b;
    }
    if ((string)$b['status'] !== 'checked-in') {
        return ['error' => 'Only a checked-in guest can be checked out; this booking is ' . $b['status'] . '.'];
    }
    $rows = rh_ai_bk_rows($b);
    $f = rh_ai_folio((int)$b['id']);
    $owed = round((float)($f['balance_due'] ?? 0), 2);
    $rows[] = ['label' => 'Balance', 'new' => rh_ai_fmt_money($owed), 'tone' => $owed > BALANCE_TOLERANCE ? 'danger' : ''];
    $settle = rh_checkout_settlement_reason($b);
    $link = ['label' => 'Open checkout on Bookings', 'url' => 'bookings.php?action=checkout&booking_id=' . (int)$b['id']];
    if ($settle !== null || $owed > BALANCE_TOLERANCE) {
        $why = $settle ?? ('The guest still owes ' . rh_ai_fmt_money($owed) . '. Take the payment on the booking first, or check out on Bookings.');
        $card = rh_ai_card_base('Check out on Bookings', 'Money is settled on the Bookings screen: extra or unused nights and balances are decided and recorded there.', $rows, '', ['handoff' => $link]);
        $card['warn'][] = $why;
        return ['card' => $card, 'steps' => [], 'fp' => [false], 'facts' => rh_ai_bk_facts($b) + ['balance_due' => $owed, 'handoff' => 'settlement on the bookings screen']];
    }
    $rows[] = ['label' => 'Status', 'old' => 'Checked in', 'new' => 'Checked out'];
    $card = rh_ai_card_base('Check out', 'Nothing is owed and the guest leaves on the booked day. The final invoice or paid statement is made by the checkout, the same as on Bookings.', $rows, 'Check out');
    $steps = [rh_ai_step('bookings.php', [['action', 'checkout_assess'], ['id', (string)(int)$b['id']]], 'assess_clear'),
        rh_ai_step('bookings.php', [['action', 'checkout'], ['id', (string)(int)$b['id']]])];
    return ['card' => $card, 'steps' => $steps, 'fp' => [true], 'facts' => rh_ai_bk_facts($b) + ['balance_due' => $owed], 'expect_status' => 'checked-out', 'handoff_on_fail' => $link];
}

function rh_ai_pv_housekeeping_job(PDO $db, array $user, array $a): array
{
    $r = rh_ai_act_room($db, (string)$a['room']);
    if (!$r) {
        return ['error' => 'No active room numbered "' . $a['room'] . '".'];
    }
    $job = (string)($a['job'] ?? 'regular_cleaning');
    $prio = (string)($a['priority'] ?? 'medium');
    $due = (string)($a['due_date'] ?? date('Y-m-d'));
    if ($due < date('Y-m-d')) {
        return ['error' => 'The due date cannot be in the past.'];
    }
    $notes = trim((string)($a['notes'] ?? ''));
    $st = $db->prepare("SELECT id FROM housekeeping_assignments WHERE individual_room_id = ? AND due_date = ? AND assignment_type = ? AND status IN ('pending','in_progress','blocked') LIMIT 1");
    $st->execute([(int)$r['id'], $due, $job]);
    if ($dup = (int)$st->fetchColumn()) {
        return ['error' => 'Room ' . $r['room_number'] . ' already has an open ' . str_replace('_', ' ', $job) . ' job for ' . $due . ' (task #' . $dup . ').'];
    }
    $rows = [['label' => 'Room', 'new' => 'Room ' . $r['room_number'] . ' (' . $r['type_name'] . ')'], ['label' => 'Job', 'new' => ucfirst(str_replace('_', ' ', $job))],
        ['label' => 'Priority', 'new' => ucfirst($prio)], ['label' => 'Due', 'new' => rh_ai_fmt_date($due)], ['label' => 'Assigned to', 'new' => 'Nobody yet - assign it on Housekeeping']];
    if ($notes !== '') {
        $rows[] = ['label' => 'Notes', 'new' => $notes];
    }
    $card = rh_ai_card_base('New housekeeping job', 'Adds the job to the Housekeeping board, the same as Add job there.', $rows, 'Add job');
    $steps = [rh_ai_step('housekeeping.php', [['action', 'add_assignment'], ['individual_room_id', (string)(int)$r['id']], ['assignment_type', $job], ['priority', $prio],
        ['due_date', $due], ['notes', $notes], ['status', 'pending'], ['estimated_duration', '30']])];
    return ['card' => $card, 'steps' => $steps, 'fp' => [], 'facts' => ['room' => $r['room_number'], 'job' => $job, 'priority' => $prio, 'due_date' => $due], 'room_id' => (int)$r['id']];
}

function rh_ai_pv_maintenance_job(PDO $db, array $user, array $a): array
{
    $r = rh_ai_act_room($db, (string)$a['room']);
    if (!$r) {
        return ['error' => 'No active room numbered "' . $a['room'] . '".'];
    }
    $title = trim((string)$a['title']);
    $type = (string)($a['type'] ?? 'repair');
    $prio = (string)($a['priority'] ?? 'medium');
    $due = (string)($a['due_date'] ?? date('Y-m-d'));
    if ($due < date('Y-m-d')) {
        return ['error' => 'The due date cannot be in the past.'];
    }
    $st = $db->prepare("SELECT id FROM room_maintenance_schedules WHERE individual_room_id = ? AND LOWER(TRIM(title)) = LOWER(TRIM(?)) AND status IN ('planned','in_progress') LIMIT 1");
    $st->execute([(int)$r['id'], $title]);
    if ($dup = (int)$st->fetchColumn()) {
        return ['error' => 'Room ' . $r['room_number'] . ' already has an open maintenance job called "' . $title . '" (request #' . $dup . ').'];
    }
    $block = !empty($a['block_room']);
    $start = (string)($a['_start'] ?? date('Y-m-d H:i:s'));
    $end = max($due . ' 23:59:59', date('Y-m-d H:i:s', strtotime($start) + 3600));
    $rows = [['label' => 'Room', 'new' => 'Room ' . $r['room_number'] . ' (' . $r['type_name'] . ')'], ['label' => 'Job', 'new' => $title], ['label' => 'Type', 'new' => ucfirst($type)],
        ['label' => 'Priority', 'new' => ucfirst($prio)], ['label' => 'Due', 'new' => rh_ai_fmt_date($due)]];
    if (trim((string)($a['description'] ?? '')) !== '') {
        $rows[] = ['label' => 'Details', 'new' => trim((string)$a['description'])];
    }
    $rows[] = ['label' => 'Room on sale', 'new' => $block ? 'No - off sale from ' . date('j M H:i', strtotime($start)) . ' to ' . date('j M H:i', strtotime($end)) : 'Yes, stays on sale', 'tone' => $block ? 'danger' : ''];
    $card = rh_ai_card_base('New maintenance job', 'Adds the job to Room Maintenance, the same as New job there.', $rows, 'Add job', ['danger' => $block]);
    if ($block) {
        $card['warn'][] = 'This takes room ' . $r['room_number'] . ' off sale until the job ends: it cannot be booked or assigned.';
    }
    $f = [['action', 'add_schedule'], ['individual_room_id', (string)(int)$r['id']], ['title', $title], ['description', trim((string)($a['description'] ?? ''))],
        ['maintenance_type', $type], ['priority', $prio], ['due_date', $due], ['start_date', $start], ['end_date', $end], ['status', 'pending'], ['estimated_duration', '60']];
    if ($block) {
        $f[] = ['block_room', '1'];
    }
    return ['card' => $card, 'steps' => [rh_ai_step('room-maintenance.php', $f)], 'fp' => [], 'facts' => ['room' => $r['room_number'], 'title' => $title, 'block_room' => $block, 'due_date' => $due],
        'room_id' => (int)$r['id'], 'args_extra' => ['_start' => $start]];
}

function rh_ai_pv_room_status(PDO $db, array $user, array $a): array
{
    $r = rh_ai_act_room($db, (string)$a['room']);
    if (!$r) {
        return ['error' => 'No active room numbered "' . $a['room'] . '".'];
    }
    $new = (string)$a['status'];
    if ((string)$r['status'] === $new) {
        return ['error' => 'Room ' . $r['room_number'] . ' is already ' . str_replace('_', ' ', $new) . '.'];
    }
    $blocks = rhStatusBlocksRoom($new);
    if ($blocks && !rhCanBlockRooms((int)$user['id'])) {
        return ['error' => rhBlockRoomsDeniedMessage()];
    }
    $label = static fn(string $s) => ucfirst(str_replace('_', ' ', $s));
    $rows = [['label' => 'Room', 'new' => 'Room ' . $r['room_number'] . ' (' . $r['type_name'] . ')'], ['label' => 'Status', 'old' => $label((string)$r['status']), 'new' => $label($new), 'tone' => $blocks ? 'danger' : '']];
    $card = rh_ai_card_base('Room status', 'Changes the room status, the same as Change status on Individual Rooms (written to the room log).', $rows, 'Change status', ['danger' => $blocks]);
    $pair = $db->prepare("SELECT CASE WHEN rc.room_a_id = ? THEN b.room_number ELSE a.room_number END FROM room_combinations rc JOIN individual_rooms a ON a.id = rc.room_a_id JOIN individual_rooms b ON b.id = rc.room_b_id
                          WHERE rc.is_active = 1 AND (rc.room_a_id = ? OR rc.room_b_id = ?) LIMIT 1");
    $pair->execute([(int)$r['id'], (int)$r['id'], (int)$r['id']]);
    if (($other = $pair->fetchColumn()) !== false) {
        $card['notes'][] = 'Joined room: room ' . $other . ' changes with it, as on the screen.';
    }
    if ($blocks) {
        $card['warn'][] = 'Room ' . $r['room_number'] . ' goes off sale: it cannot be booked or assigned until it is set back to available.';
    }
    $guest = $db->prepare("SELECT COUNT(*) FROM bookings WHERE deleted_at IS NULL AND status = 'checked-in' AND individual_room_id = ?");
    $guest->execute([(int)$r['id']]);
    if ((int)$guest->fetchColumn() > 0) {
        $card['warn'][] = 'A guest is checked in to this room.';
    }
    $reason = trim((string)($a['reason'] ?? ''));
    return ['card' => $card, 'steps' => [rh_ai_step('individual-rooms.php', [['action', 'update_status'], ['id', (string)(int)$r['id']], ['new_status', $new], ['reason', trim(($reason !== '' ? $reason . ' ' : '') . '(via the Assistant)')]])],
        'fp' => [(string)$r['status']], 'facts' => ['room' => $r['room_number'], 'from' => $r['status'], 'to' => $new], 'room_id' => (int)$r['id']];
}

function rh_ai_email_labels(): array
{
    return ['booking_received' => 'Booking received', 'booking_confirmed' => 'Booking confirmed', 'tentative_confirmed' => 'Tentative hold confirmed', 'tentative_converted' => 'Tentative booking confirmed',
        'booking_cancelled' => 'Booking cancelled', 'booking_reminder' => 'Arrival reminder', 'invoice' => 'Invoice'];
}

function rh_ai_pv_send_email(PDO $db, array $user, array $a): array
{
    $b = rh_ai_act_booking($db, $a);
    if (!empty($b['error'])) {
        return $b;
    }
    $type = (string)$a['email_type'];
    if ($type === 'booking_cancelled' && !in_array($b['status'], ['cancelled', 'expired', 'no-show'], true)) {
        return ['error' => 'A cancellation email can only be sent for a cancelled, expired or no-show booking.'];
    }
    if ($type === 'booking_reminder' && !in_array($b['status'], ['confirmed', 'pending', 'checked-in'], true)) {
        return ['error' => 'Reminder emails are only for active bookings.'];
    }
    if (!filter_var((string)$b['guest_email'], FILTER_VALIDATE_EMAIL)) {
        return ['error' => 'This booking has no valid guest email.'];
    }
    $rows = [['label' => 'Booking', 'new' => (string)$b['booking_reference']], ['label' => 'Email', 'new' => rh_ai_email_labels()[$type]], ['label' => 'To', 'new' => rh_ai_mask_email((string)$b['guest_email'])]];
    $card = rh_ai_card_base('Send guest email', 'Sends the hotel\'s own email template, the same as Resend email on Bookings.', $rows, 'Send email');
    return ['card' => $card, 'steps' => [rh_ai_step('bookings.php', [['action', 'resend_email'], ['booking_id', (string)(int)$b['id']], ['email_type', $type]])], 'fp' => [], 'facts' => rh_ai_bk_facts($b) + ['email' => $type]];
}

function rh_ai_pv_send_invoice(PDO $db, array $user, array $a): array
{
    $b = rh_ai_act_booking($db, $a);
    if (!empty($b['error'])) {
        return $b;
    }
    if (!filter_var((string)$b['guest_email'], FILTER_VALIDATE_EMAIL)) {
        return ['error' => 'This booking has no valid guest email.'];
    }
    $rows = [['label' => 'Booking', 'new' => (string)$b['booking_reference']], ['label' => 'Email', 'new' => 'Invoice'], ['label' => 'To', 'new' => rh_ai_mask_email((string)$b['guest_email'])]];
    $card = rh_ai_card_base('Send invoice', 'Emails the booking\'s invoice, the same as Send invoice on Bookings. Copies go to the invoice recipients set in Email Settings.', $rows, 'Send invoice');
    return ['card' => $card, 'steps' => [rh_ai_step('bookings.php', [['action', 'send_invoice_email'], ['booking_id', (string)(int)$b['id']]])], 'fp' => [], 'facts' => rh_ai_bk_facts($b)];
}

function rh_ai_pv_whatsapp(PDO $db, array $user, array $a): array
{
    $b = rh_ai_act_booking($db, $a);
    if (!empty($b['error'])) {
        return $b;
    }
    $rows = [['label' => 'Booking', 'new' => (string)$b['booking_reference']], ['label' => 'To', 'new' => rh_ai_mask_phone((string)$b['guest_phone'])]];
    $card = rh_ai_card_base('WhatsApp the guest', 'WhatsApp messages are sent from the booking screen (Invoices section). This opens it.', $rows, '',
        ['handoff' => ['label' => 'Open booking', 'url' => 'booking-details.php?id=' . (int)$b['id']]]);
    return ['card' => $card, 'steps' => [], 'fp' => [], 'facts' => rh_ai_bk_facts($b) + ['handoff' => 'booking screen']];
}

// ── Cards: store, show ───────────────────────────────────────────────────────

/** Run an action's preview with validated args. */
function rh_ai_action_preview(PDO $db, array $user, string $key, array $args): array
{
    $def = rh_ai_action_registry()[$key];
    try {
        return (array)call_user_func($def['preview'], $db, $user, $args);
    } catch (Throwable $e) {
        error_log('Assistant action preview ' . $key . ' failed: ' . $e->getMessage());
        return ['error' => 'That could not be prepared just now.'];
    }
}

/**
 * Propose an action: permission + module re-check, tokens resolved, arguments validated, read-only preview,
 * ONE ai_actions row (proposed, 15 minutes, fresh client_uuid). Nothing else is written.
 * @return array{ok:bool, error:string, card:?array, json:string}
 */
function rh_ai_propose(PDO $db, array $user, int $conv, string $key, array $args, bool $fromModel = true): array
{
    $fail = static function (string $msg) {
        return ['ok' => false, 'error' => $msg, 'card' => null, 'json' => rh_ai_compact_json(['error' => $msg])];
    };
    $reg = rh_ai_action_registry();
    if (!isset($reg[$key])) {
        return $fail('There is no such action.');
    }
    $problem = rh_ai_action_problem($user, $key);
    if ($problem !== '') {
        return $fail($problem);
    }
    if ($fromModel) {
        $args = rh_ai_vault_resolve_args($db, $conv, $args);
    }
    [$ok, $clean] = rh_ai_tool_validate($reg[$key]['schema'], $args);
    if (!$ok) {
        return $fail('Arguments not valid: ' . $clean);
    }
    foreach ($args as $k => $v) { // server-side extras (never from the model: their names start with "_" or are card choices)
        if (!$fromModel && in_array($k, ['room_id', 'scope', 'reason_code', 'email_guest'], true)) {
            $clean[$k] = $v;
        }
    }
    $pv = rh_ai_action_preview($db, $user, $key, $clean);
    if (!empty($pv['error'])) {
        return $fail((string)$pv['error']);
    }
    $clean = array_merge($clean, (array)($pv['args_extra'] ?? []));
    $uuid = rh_ai_uuid4();
    $db->prepare("INSERT INTO ai_actions (conversation_id, user_id, action_key, params_json, preview_json, status, client_uuid, expires_at)
                  VALUES (?, ?, ?, ?, ?, 'proposed', ?, DATE_ADD(NOW(), INTERVAL " . (int)RH_AI_CARD_MINUTES . " MINUTE))")
        ->execute([$conv, (int)$user['id'], $key, json_encode(['args' => $clean], JSON_UNESCAPED_UNICODE), json_encode($pv, JSON_UNESCAPED_UNICODE | JSON_PARTIAL_OUTPUT_ON_ERROR), $uuid]);
    $id = (int)$db->lastInsertId();
    $card = rh_ai_card_public($db, $id);
    $forModel = rh_ai_vault_protect($db, $conv, (array)($pv['facts'] ?? []));
    $forModel = ['card_id' => $id, 'card' => $reg[$key]['label'], 'waiting_for' => !empty($pv['card']['handoff']) ? 'the person to open the screen the card links to' : 'the person to check the card and tap Confirm (nothing has changed yet)'] + $forModel;
    return ['ok' => true, 'error' => '', 'card' => $card, 'json' => rh_ai_compact_json($forModel)];
}

/** One card as the panel shows it (real values: local only, never sent to a provider). */
function rh_ai_card_public(PDO $db, int $id, ?array $row = null): ?array
{
    if ($row === null) {
        $st = $db->prepare("SELECT *, (expires_at < NOW()) AS is_past FROM ai_actions WHERE id = ?");
        $st->execute([$id]);
        $row = $st->fetch(PDO::FETCH_ASSOC) ?: null;
    }
    if (!$row) {
        return null;
    }
    $pv = json_decode((string)$row['preview_json'], true) ?: [];
    $res = json_decode((string)$row['result_json'], true) ?: [];
    $status = (string)$row['status'];
    if ($status === 'proposed' && !empty($row['is_past'])) {
        $status = 'expired';
    }
    $c = (array)($pv['card'] ?? []);
    if (!empty($c['handoff']) && $status === 'proposed') {
        $status = 'handoff';
    }
    return [
        'id' => (int)$row['id'], 'key' => (string)$row['action_key'], 'status' => $status,
        'title' => (string)($c['title'] ?? ''), 'help' => (string)($c['help'] ?? ''), 'rows' => (array)($c['rows'] ?? []), 'warn' => (array)($c['warn'] ?? []),
        'notes' => (array)($c['notes'] ?? []), 'confirm' => (string)($c['confirm'] ?? ''), 'danger' => !empty($c['danger']), 'handoff' => $c['handoff'] ?? ($res['handoff'] ?? null),
        'fields' => (array)($c['fields'] ?? []), 'choices' => (array)($c['choices'] ?? []), 'incomplete' => !empty($pv['incomplete']),
        'message' => (string)($res['message'] ?? ''), 'expires_at' => (string)$row['expires_at'],
    ];
}

/** Cards of one conversation by id (for history), real values restored. */
function rh_ai_cards_for(PDO $db, int $conv, array $ids): array
{
    $ids = array_values(array_filter(array_map('intval', $ids)));
    if (!$ids) {
        return [];
    }
    $st = $db->prepare("SELECT *, (expires_at < NOW()) AS is_past FROM ai_actions WHERE conversation_id = ? AND id IN (" . implode(',', array_fill(0, count($ids), '?')) . ") ORDER BY id");
    $st->execute(array_merge([$conv], $ids));
    $out = [];
    foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) {
        $out[] = rh_ai_card_public($db, (int)$r['id'], $r);
    }
    return $out;
}

/** Load one card row of this person, optionally locked. */
function rh_ai_card_row(PDO $db, int $uid, int $id, bool $lock = false): ?array
{
    $st = $db->prepare("SELECT *, (expires_at < NOW()) AS is_past FROM ai_actions WHERE id = ? AND user_id = ?" . ($lock ? ' FOR UPDATE' : ''));
    $st->execute([$id, $uid]);
    return $st->fetch(PDO::FETCH_ASSOC) ?: null;
}

// ── Confirm, report, cancel ──────────────────────────────────────────────────

/**
 * Confirm a card (single use, row lock): owner, still proposed, not expired, permission still held, preview
 * re-run with the person's edits; a changed price / availability gives a NEW card. Returns the replay steps.
 * @return array{success:bool, error?:string, changed?:bool, card?:array, steps?:array, card_id?:int}
 */
function rh_ai_confirm(PDO $db, array $user, int $id, array $edits = []): array
{
    $uid = (int)$user['id'];
    $own = !$db->inTransaction();
    if ($own) {
        $db->beginTransaction();
    }
    $done = static function (array $r) use ($db, $own): array {
        if ($own && $db->inTransaction()) {
            $db->commit();
        }
        return $r;
    };
    try {
        $row = rh_ai_card_row($db, $uid, $id, true);
        if (!$row) {
            return $done(['success' => false, 'error' => 'That card was not found.']);
        }
        if ((string)$row['status'] !== 'proposed') {
            return $done(['success' => false, 'error' => 'This card has already been used or cancelled.', 'card' => rh_ai_card_public($db, $id)]);
        }
        if (!empty($row['is_past'])) {
            $db->prepare("UPDATE ai_actions SET status = 'expired' WHERE id = ?")->execute([$id]);
            return $done(['success' => false, 'error' => 'This card has expired. Ask again for a fresh one.', 'card' => rh_ai_card_public($db, $id)]);
        }
        $key = (string)$row['action_key'];
        $problem = rh_ai_action_problem($user, $key);
        if ($problem !== '') {
            return $done(['success' => false, 'error' => $problem]);
        }
        $old = json_decode((string)$row['preview_json'], true) ?: [];
        if (!empty($old['card']['handoff'])) {
            return $done(['success' => false, 'error' => 'This one is finished on the screen: use the button on the card.']);
        }
        $args = (array)(json_decode((string)$row['params_json'], true)['args'] ?? []);
        $editable = [];
        foreach (array_merge((array)($old['card']['fields'] ?? []), (array)($old['card']['choices'] ?? [])) as $f) {
            $editable[(string)$f['name']] = $f;
        }
        foreach ($edits as $k => $v) {
            if (isset($editable[$k]) && is_scalar($v)) {
                $v = mb_substr(trim((string)$v), 0, 500);
                if (!empty($editable[$k]['options']) && !in_array($v, array_map(static fn($o) => (string)$o[0], $editable[$k]['options']), true)) {
                    continue;
                }
                $args[$k] = $v;
            }
        }
        $new = rh_ai_action_preview($db, $user, $key, $args);
        if (!empty($new['error'])) {
            $db->prepare("UPDATE ai_actions SET status = 'failed', result_json = ? WHERE id = ?")->execute([json_encode(['message' => 'Not done: ' . $new['error']]), $id]);
            return $done(['success' => false, 'error' => 'Not done: ' . $new['error'], 'card' => rh_ai_card_public($db, $id)]);
        }
        if (!empty($new['incomplete']) || !$new['steps']) {
            return $done(['success' => false, 'error' => trim(implode(' ', (array)($new['card']['notes'] ?? []))) ?: 'Fill in the card first.']);
        }
        if (json_encode($new['fp'] ?? []) !== json_encode($old['fp'] ?? [])) {
            // Price or availability moved since the card was made: never act on the old figures.
            $db->prepare("UPDATE ai_actions SET status = 'expired', result_json = ? WHERE id = ?")->execute([json_encode(['message' => 'Replaced: the price or availability changed.']), $id]);
            $newArgs = array_merge($args, (array)($new['args_extra'] ?? []));
            $uuid = rh_ai_uuid4();
            $db->prepare("INSERT INTO ai_actions (conversation_id, user_id, action_key, params_json, preview_json, status, client_uuid, expires_at) VALUES (?, ?, ?, ?, ?, 'proposed', ?, DATE_ADD(NOW(), INTERVAL " . (int)RH_AI_CARD_MINUTES . " MINUTE))")
                ->execute([(int)$row['conversation_id'], $uid, $key, json_encode(['args' => $newArgs], JSON_UNESCAPED_UNICODE), json_encode($new, JSON_UNESCAPED_UNICODE | JSON_PARTIAL_OUTPUT_ON_ERROR), $uuid]);
            $nid = (int)$db->lastInsertId();
            return $done(['success' => false, 'changed' => true, 'error' => 'The price or availability changed since this card was made. Check the new card before confirming.',
                'card' => rh_ai_card_public($db, $id), 'new_card' => rh_ai_card_public($db, $nid)]);
        }
        $steps = $new['steps'];
        foreach ($steps as &$s) {
            foreach ($s['fields'] as &$f) {
                $f[1] = str_replace('{uuid}', (string)$row['client_uuid'], $f[1]);
            }
            unset($f);
        }
        unset($s);
        $new['args'] = $args;
        $db->prepare("UPDATE ai_actions SET status = 'confirmed', params_json = ?, preview_json = ? WHERE id = ? AND status = 'proposed'")
            ->execute([json_encode(['args' => $args, 'steps' => $steps], JSON_UNESCAPED_UNICODE), json_encode($new, JSON_UNESCAPED_UNICODE | JSON_PARTIAL_OUTPUT_ON_ERROR), $id]);
        return $done(['success' => true, 'card_id' => $id, 'steps' => $steps]);
    } catch (Throwable $e) {
        if ($own && $db->inTransaction()) {
            $db->rollBack();
        }
        error_log('Assistant confirm failed: ' . $e->getMessage());
        return ['success' => false, 'error' => 'That could not be confirmed just now.'];
    }
}

// Verify (after the browser reports): the database decides; the screen's own message is shown.
// Each returns true (done), false (not done) or null (no database trace: the screen's answer decides).

function rh_ai_vf_json(PDO $db, array $row, array $pv): ?bool
{
    return null;
}

function rh_ai_vf_status(PDO $db, array $row, array $pv): ?bool
{
    $args = (array)(json_decode((string)$row['params_json'], true)['args'] ?? []);
    $b = rh_ai_act_booking($db, $args);
    return empty($b['error']) && !empty($pv['expect_status']) ? (string)$b['status'] === (string)$pv['expect_status'] : null;
}

function rh_ai_vf_checkout_date(PDO $db, array $row, array $pv): ?bool
{
    $args = (array)(json_decode((string)$row['params_json'], true)['args'] ?? []);
    $b = rh_ai_act_booking($db, $args);
    return empty($b['error']) ? substr((string)$b['check_out_date'], 0, 10) === (string)$args['new_checkout'] : null;
}

function rh_ai_vf_room_type(PDO $db, array $row, array $pv): ?bool
{
    $args = (array)(json_decode((string)$row['params_json'], true)['args'] ?? []);
    $b = rh_ai_act_booking($db, $args);
    return empty($b['error']) && !empty($pv['new_room_id']) ? (int)$b['room_id'] === (int)$pv['new_room_id'] : null;
}

function rh_ai_vf_assign_room(PDO $db, array $row, array $pv): ?bool
{
    $args = (array)(json_decode((string)$row['params_json'], true)['args'] ?? []);
    $b = rh_ai_act_booking($db, $args);
    if (!empty($b['error']) || empty($pv['room_id'])) {
        return null;
    }
    return (int)$b['individual_room_id'] === (int)$pv['room_id'] || in_array((int)$pv['room_id'], array_map('intval', getBookingRoomIds((int)$b['id'])), true);
}

function rh_ai_vf_housekeeping_job(PDO $db, array $row, array $pv): ?bool
{
    $args = (array)(json_decode((string)$row['params_json'], true)['args'] ?? []);
    $st = $db->prepare("SELECT COUNT(*) FROM housekeeping_assignments WHERE individual_room_id = ? AND due_date = ? AND assignment_type = ? AND created_by = ? AND created_at >= DATE_SUB(?, INTERVAL 5 SECOND)");
    $st->execute([(int)$pv['room_id'], (string)($args['due_date'] ?? date('Y-m-d')), (string)($args['job'] ?? 'regular_cleaning'), (int)$row['user_id'], (string)$row['created_at']]);
    return (int)$st->fetchColumn() > 0;
}

function rh_ai_vf_maintenance_job(PDO $db, array $row, array $pv): ?bool
{
    $args = (array)(json_decode((string)$row['params_json'], true)['args'] ?? []);
    $st = $db->prepare("SELECT COUNT(*) FROM room_maintenance_schedules WHERE individual_room_id = ? AND title = ? AND created_by = ? AND created_at >= DATE_SUB(?, INTERVAL 5 SECOND)");
    $st->execute([(int)$pv['room_id'], trim((string)$args['title']), (int)$row['user_id'], (string)$row['created_at']]);
    return (int)$st->fetchColumn() > 0;
}

function rh_ai_vf_room_status(PDO $db, array $row, array $pv): ?bool
{
    $args = (array)(json_decode((string)$row['params_json'], true)['args'] ?? []);
    $st = $db->prepare("SELECT status FROM individual_rooms WHERE id = ?");
    $st->execute([(int)$pv['room_id']]);
    return (string)$st->fetchColumn() === (string)$args['status'];
}

function rh_ai_vf_create_booking(PDO $db, array $row, array $pv): ?bool
{
    $st = $db->prepare("SELECT COUNT(*) FROM bookings WHERE client_uuid = ?");
    $st->execute([(string)$row['client_uuid']]);
    return (int)$st->fetchColumn() > 0;
}

/**
 * The browser reports what the screen answered. The database decides executed / failed; the activity log
 * gets "... via Assistant"; an AI-made booking's saved total is compared with the card (an incident is
 * recorded when they differ by more than BALANCE_TOLERANCE). Check-in into a room not marked clean gives a
 * NEW card for a second, explicit confirm (never sent on its own).
 * @param array|null $result the endpoint's JSON (null when it answered with a page instead)
 */
function rh_ai_report(PDO $db, array $user, int $id, ?array $result, array $info = []): array
{
    $uid = (int)$user['id'];
    $own = !$db->inTransaction();
    if ($own) {
        $db->beginTransaction();
    }
    try {
        $row = rh_ai_card_row($db, $uid, $id, true);
        if (!$row || (string)$row['status'] !== 'confirmed') {
            if ($own) {
                $db->rollBack();
            }
            return ['success' => false, 'error' => 'That card is not waiting for a result.'];
        }
        $key = (string)$row['action_key'];
        $def = rh_ai_action_registry()[$key];
        $pv = json_decode((string)$row['preview_json'], true) ?: [];
        $msg = trim(strip_tags((string)($result['message'] ?? ($result['error'] ?? ''))));
        $screenOk = is_array($result) && !empty($result['success']) && empty($info['guard_failed']);
        $db_ok = call_user_func($def['verify'], $db, $row, $pv);
        $ok = $db_ok === null ? $screenOk : $db_ok;
        $res = ['message' => '', 'screen' => is_array($result) ? array_intersect_key($result, array_flip(['success', 'message', 'error', 'booking_id', 'reference', 'duplicate', 'needs_confirm_room'])) : ['non_json' => true]];
        $next = null;
        if ($ok) {
            $res['message'] = 'Done. ' . ($msg !== '' ? $msg : $def['label'] . ' saved.');
        } else {
            $res['message'] = 'Not done. ' . ($msg !== '' ? $msg : 'The screen did not confirm it - open it to check.');
            if (!empty($info['guard_failed'])) {
                $res['message'] = 'Not done: something is owed or the departure date changed. Open checkout on Bookings to settle it.';
                $res['handoff'] = $pv['handoff_on_fail'] ?? null;
            }
        }
        if ($key === 'create_booking') {
            $st = $db->prepare("SELECT id, booking_reference FROM bookings WHERE client_uuid = ?");
            $st->execute([(string)$row['client_uuid']]);
            if ($bk = $st->fetch(PDO::FETCH_ASSOC)) {
                $res['booking_id'] = (int)$bk['id'];
                $res['reference'] = (string)$bk['booking_reference'];
                $res['handoff'] = ['label' => 'Open booking', 'url' => 'booking-details.php?id=' . (int)$bk['id']];
                $tt = $db->prepare("SELECT COALESCE(SUM(total_with_vat), 0) FROM bookings WHERE client_uuid = ? OR client_uuid LIKE ?");
                $tt->execute([(string)$row['client_uuid'], (string)$row['client_uuid'] . ':b%']);
                $saved = round((float)$tt->fetchColumn(), 2);
                $card = round((float)($pv['total'] ?? 0), 2);
                if (abs($saved - $card) > BALANCE_TOLERANCE) {
                    $res['incident'] = ['type' => 'price_mismatch', 'card_total' => $card, 'saved_total' => $saved];
                    if (function_exists('rh_log_event')) {
                        rh_log_event('assistant', 'warning', 'Assistant booking total differs from its card', ['action_id' => $id, 'booking_id' => (int)$bk['id'], 'card_total' => $card, 'saved_total' => $saved]);
                    }
                }
            }
        }
        if ($key === 'check_in' && !$ok && !empty($result['needs_confirm_room'])) {
            $args = (array)(json_decode((string)$row['params_json'], true)['args'] ?? []);
            $args['_room_not_ready_ok'] = 1;
            unset($args['room']); // the room was assigned by the first step
            $npv = rh_ai_action_preview($db, $user, 'check_in', $args);
            if (empty($npv['error'])) {
                $db->prepare("INSERT INTO ai_actions (conversation_id, user_id, action_key, params_json, preview_json, status, client_uuid, expires_at) VALUES (?, ?, 'check_in', ?, ?, 'proposed', ?, DATE_ADD(NOW(), INTERVAL " . (int)RH_AI_CARD_MINUTES . " MINUTE))")
                    ->execute([(int)$row['conversation_id'], $uid, json_encode(['args' => $args], JSON_UNESCAPED_UNICODE), json_encode($npv, JSON_UNESCAPED_UNICODE | JSON_PARTIAL_OUTPUT_ON_ERROR), rh_ai_uuid4()]);
                $next = (int)$db->lastInsertId();
                $res['message'] = 'Not done yet: ' . ($msg !== '' ? $msg : 'the room is not marked clean.') . ' Confirm again on the new card to check in anyway.';
            }
        }
        $db->prepare("UPDATE ai_actions SET status = ?, result_json = ?, executed_at = NOW() WHERE id = ?")
            ->execute([$ok ? 'executed' : 'failed', json_encode($res, JSON_UNESCAPED_UNICODE | JSON_PARTIAL_OUTPUT_ON_ERROR), $id]);
        if ($own) {
            $db->commit();
        }
    } catch (Throwable $e) {
        if ($own && $db->inTransaction()) {
            $db->rollBack();
        }
        error_log('Assistant report failed: ' . $e->getMessage());
        return ['success' => false, 'error' => 'The result could not be recorded.'];
    }
    $summary = $def['label'] . ' via Assistant (card ' . $id . ')' . (!empty($res['reference']) ? ': ' . $res['reference'] : '') . ($ok ? '' : ' - not done');
    if (function_exists('logActivity')) {
        logActivity($uid, 'ai_action', mb_substr($summary . '. ' . $res['message'], 0, 480));
    }
    // Phase 4b: learn from it (incidents hold the card type and the screen's words, never names) and pause
    // the Assistant after a burst of failed or cancelled cards (kill switch 6).
    if (!$ok) {
        rh_ai_incident_add($db, 'action_failed', $def['label'] . ' card was not done', ['action' => $key, 'screen_message' => mb_substr($msg, 0, 200), 'guard' => !empty($info['guard_failed'])],
            ['user_id' => $uid, 'conversation_id' => (int)$row['conversation_id'], 'action_id' => $id]);
        rh_ai_check_action_burst($db);
    }
    if (!empty($res['incident'])) {
        rh_ai_incident_add($db, 'price_mismatch', 'A booking made from a card saved a different total from the card', $res['incident'] + ['action' => $key], ['severity' => 'warning', 'user_id' => $uid, 'action_id' => $id]);
        rh_ai_fault_add($db, 'price_mismatch', 'A booking made by the Assistant saved a different total from its card',
            'The card showed ' . $res['incident']['card_total'] . ' but the booking saved ' . $res['incident']['saved_total'] . ' (card ' . $id . '). The card price and the Create Booking price should come from the same rule.', $res['incident'] + ['card_id' => $id]);
    }
    try { // the outcome joins the chat so the Assistant knows what happened (stored with privacy tokens)
        rh_ai_message_store_outcome($db, (int)$row['conversation_id'], $def['label'] . ': ' . $res['message']);
    } catch (Throwable $e) {
    }
    return ['success' => true, 'status' => $ok ? 'executed' : 'failed', 'message' => $res['message'], 'card' => rh_ai_card_public($db, $id), 'next_card' => $next ? rh_ai_card_public($db, $next) : null];
}

/** The outcome line, tokenised, as an assistant message of that conversation. */
function rh_ai_message_store_outcome(PDO $db, int $conv, string $text): void
{
    $db->prepare("INSERT INTO ai_messages (conversation_id, role, agent, content) VALUES (?, 'assistant', 'action', ?)")->execute([$conv, rh_ai_scrub_outbound($db, $conv, mb_substr($text, 0, 600))]);
    $db->prepare("UPDATE ai_conversations SET updated_at = NOW() WHERE id = ?")->execute([$conv]);
}

/** Cancel a card that has not been confirmed. */
function rh_ai_cancel_card(PDO $db, array $user, int $id): array
{
    $st = $db->prepare("UPDATE ai_actions SET status = 'cancelled', result_json = ? WHERE id = ? AND user_id = ? AND status = 'proposed'");
    $st->execute([json_encode(['message' => 'Cancelled. Nothing was changed.']), $id, (int)$user['id']]);
    if ($st->rowCount() === 0) {
        $own = rh_ai_card_row($db, (int)$user['id'], $id); // only the owner ever sees a card's contents
        return $own ? ['success' => false, 'error' => 'That card cannot be cancelled now.', 'card' => rh_ai_card_public($db, $id, $own)] : ['success' => false, 'error' => 'That card was not found.'];
    }
    $card = rh_ai_card_public($db, $id);
    rh_ai_incident_add($db, 'action_cancelled', 'A card was cancelled by the person', ['action' => (string)($card['key'] ?? '')], ['user_id' => (int)$user['id'], 'action_id' => $id]);
    rh_ai_check_action_burst($db);
    return ['success' => true, 'card' => $card];
}
