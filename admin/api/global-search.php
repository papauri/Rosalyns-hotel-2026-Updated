<?php

/**
 * Universal admin search (navbar search icon / Ctrl+K).
 *
 * GET /admin/api/global-search.php?q=<text>
 * Returns {success, q, groups:[{key,label,icon,items:[{title,sub,url}]}]}.
 *
 * Every source is gated by rhCanLinkTo() on the page its results open, so a person only
 * ever sees records they could already reach from their own menu (same permission and
 * module rules as the sidebar). Menu pages themselves are matched client-side from the
 * rendered sidebar; this endpoint adds Hotel Settings sections and database records.
 * Read-only, prepared statements, a few rows per source.
 */
require_once __DIR__ . '/api-init.php';
/** @var PDO $pdo */
/** @var array $user */

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

$q = trim((string)($_GET['q'] ?? ''));
if (mb_strlen($q) < 2) {
    echo json_encode(['success' => true, 'q' => $q, 'groups' => []]);
    exit;
}
$q = mb_substr($q, 0, 80);
$uid = (int)$user['id'];
$limit = 6;
require_once __DIR__ . '/../../includes/smart-search.php';
$terms = rh_search_terms($q);

$can = static fn(string $page): bool => rhCanLinkTo($uid, $page);
$hl = static fn(string $page, string $text): string => $page . (strpos($page, '?') === false ? '?' : '&') . 'rh_hl=' . rawurlencode($text);
$fmtDate = static function ($d): string {
    $t = $d ? strtotime((string)$d) : false;
    return $t ? date('j M Y', $t) : '';
};
$currency = (string)getSetting('currency_symbol', 'MWK');
$money = static fn($v): string => $currency . ' ' . number_format((float)$v, 0);

$groups = [];
$add = static function (string $key, string $label, string $icon, array $items) use (&$groups): void {
    if ($items) {
        $groups[] = ['key' => $key, 'label' => $label, 'icon' => $icon, 'items' => array_values($items)];
    }
};
/**
 * Run one source; a failing source (missing table on an older install) never breaks the rest.
 * $s: select, from, cols (searched), phones (also matched by digits), where (fixed extra filter), order, preds
 * (EXISTS-style predicates, every ? = the word), rank (the title column: an exact match, then a "starts with"
 * match, comes before the usual order). Every word typed must match one of the columns (includes/smart-search.php).
 */
$find = static function (array $s) use ($pdo, $q, $terms, $limit): array {
    $w = rh_search_where($q, $s['cols'], $s['phones'] ?? [], $s['preds'] ?? []);
    if ($w['sql'] === '') {
        return [];
    }
    $params = $w['params'];
    $order = [];
    if (!empty($s['rank'])) {
        $order[] = "({$s['rank']} = ?) DESC";
        $params[] = $q;
        $order[] = "({$s['rank']} LIKE ? ESCAPE '!') DESC";
        $params[] = rh_search_prefix($terms[0]);
    }
    if (!empty($s['order'])) {
        $order[] = $s['order'];
    }
    $sql = 'SELECT ' . $s['select'] . ' FROM ' . $s['from']
        . ' WHERE ' . (!empty($s['where']) ? '(' . $s['where'] . ') AND ' : '') . $w['sql']
        . ($order ? ' ORDER BY ' . implode(', ', $order) : '')
        . ' LIMIT ' . (int)($s['limit'] ?? $limit);
    try {
        $st = $pdo->prepare($sql);
        $st->execute($params);
        return $st->fetchAll(PDO::FETCH_ASSOC);
    } catch (Throwable $e) {
        error_log('global-search: ' . $e->getMessage());
        return [];
    }
};
/** Every typed word appears in $text (case-insensitive): Hotel Settings sections and other fixed lists. */
$allWordsIn = static function (string $text) use ($terms): bool {
    foreach ($terms as $t) {
        if (mb_stripos($text, $t) === false) {
            return false;
        }
    }
    return (bool)$terms;
};

// ---- Hotel Settings sections (anchors on booking-settings.php) ----
if ($can('booking-settings.php')) {
    $sections = [
        ['maintenance', 'Frontend maintenance mode', 'maintenance offline website down'],
        ['booking-status', 'Booking system on/off', 'booking enabled disabled online booking redirect'],
        ['advance-booking', 'Advance booking window', 'advance days max booking ahead'],
        ['cancellation', 'Refunds & cancellations', 'refund cancel cancellation policy credit note'],
        ['hotel-details', 'Hotel details & policies', 'hotel name address phone check-in check-out time bank details policy vat'],
        ['booking-references', 'Booking reference prefix', 'reference prefix booking number'],
        ['document-branding', 'Document branding', 'invoice pdf logo branding colours receipt'],
        ['tentative', 'Tentative bookings', 'tentative hold provisional expiry'],
        ['tourism-levy', 'Tourism levy / city tax', 'tourism levy city tax'],
        ['email-config', 'Email server (SMTP)', 'email smtp mail server password sender'],
        ['notification-email', 'Booking notification emails', 'notification email alerts pre-arrival post-stay review'],
        ['service-modules', 'Service modules & notification emails', 'conference gym restaurant notification module'],
        ['pwa-settings', 'Install app banner (PWA)', 'pwa install app banner'],
    ];
    $items = [];
    foreach ($sections as [$anchor, $title, $kw]) {
        if ($allWordsIn($title . ' ' . $kw)) {
            $items[] = ['title' => $title, 'sub' => 'Hotel Settings', 'url' => 'booking-settings.php#' . $anchor];
        }
    }
    $add('settings', 'Settings', 'fa-cog', $items);
}
if ($can('email-templates.php') && preg_match('/templ|email|pdf|wording|invoice|quotation|receipt/i', $q)) {
    $add('templates', 'Email templates', 'fa-envelope-open-text', [
        ['title' => 'Email & PDF templates', 'sub' => 'Booking, invoice, quotation and receipt wording', 'url' => 'email-templates.php'],
    ]);
}

// ---- Room bookings ----
if ($can('booking-details.php') || $can('bookings.php')) {
    $items = [];
    // Upcoming and in-house stays come before old ones; within each, the newest booking first.
    foreach ($find([
        'select' => 'b.id, b.booking_reference, b.guest_name, b.guest_email, b.guest_phone, b.room_guest_name, b.check_in_date, b.check_out_date, b.status, r.name AS room_name',
        'from' => 'bookings b LEFT JOIN rooms r ON r.id = b.room_id',
        'where' => 'b.deleted_at IS NULL',
        'cols' => ['b.booking_reference', 'b.guest_name', 'b.room_guest_name', 'b.guest_email', 'b.guest_phone', 'r.name'],
        'phones' => ['b.guest_phone'],
        'rank' => 'b.booking_reference',
        'order' => '(b.check_out_date >= CURDATE()) DESC, b.id DESC',
    ]) as $r) {
        $items[] = [
            'title' => $r['booking_reference'] . ' · ' . $r['guest_name'],
            'sub' => trim(($r['room_name'] ?? '') . (trim((string)($r['room_guest_name'] ?? '')) !== '' ? ' (' . $r['room_guest_name'] . ')' : '') . ' · ' . $fmtDate($r['check_in_date']) . ' – ' . $fmtDate($r['check_out_date']) . ' · ' . ucwords(str_replace('-', ' ', (string)$r['status'])), ' ·'),
            'url' => $can('booking-details.php') ? 'booking-details.php?id=' . (int)$r['id'] : 'bookings.php?search=' . rawurlencode((string)$r['booking_reference']),
        ];
    }
    $add('bookings', 'Bookings', 'fa-calendar-check', $items);
}

// ---- Payments & receipts ----
if ($can('payment-details.php')) {
    $items = [];
    foreach ($find([
        'select' => 'id, payment_reference, receipt_number, booking_reference, total_amount, payment_method, payment_date, payment_status',
        'from' => 'payments',
        'where' => 'deleted_at IS NULL',
        'cols' => ['payment_reference', 'receipt_number', 'booking_reference', 'payment_reference_number'],
        'rank' => 'receipt_number',
        'order' => 'id DESC',
    ]) as $r) {
        $items[] = [
            'title' => ($r['receipt_number'] ?: $r['payment_reference']) . ' · ' . $money($r['total_amount']),
            'sub' => trim(($r['booking_reference'] ?? '') . ' · ' . ucwords(str_replace('_', ' ', (string)$r['payment_method'])) . ' · ' . $fmtDate($r['payment_date']) . ' · ' . ucwords(str_replace('_', ' ', (string)$r['payment_status'])), ' ·'),
            'url' => 'payment-details.php?id=' . (int)$r['id'],
        ];
    }
    $add('payments', 'Payments & receipts', 'fa-money-bill-wave', $items);
}

// ---- Conference enquiries ----
if ($can('conference-management.php')) {
    $items = [];
    foreach ($find([
        'select' => 'id, inquiry_reference, company_name, contact_person, event_date, status',
        'from' => 'conference_inquiries',
        'cols' => ['inquiry_reference', 'company_name', 'contact_person', 'email', 'phone'],
        'phones' => ['phone'],
        'rank' => 'inquiry_reference',
        'order' => 'id DESC',
    ]) as $r) {
        $items[] = [
            'title' => $r['inquiry_reference'] . ' · ' . ($r['company_name'] ?: $r['contact_person']),
            'sub' => trim($fmtDate($r['event_date']) . ' · ' . ucfirst((string)$r['status']), ' ·'),
            'url' => 'conference-management.php#enquiry-' . (int)$r['id'],
        ];
    }
    $add('conference', 'Conference enquiries', 'fa-briefcase', $items);
}

// ---- Event enquiries ----
if ($can('events-inquiries.php')) {
    $items = [];
    foreach ($find([
        'select' => 'id, reference_number, name, guests, status, created_at',
        'from' => 'event_inquiries',
        'cols' => ['reference_number', 'name', 'email', 'phone'],
        'phones' => ['phone'],
        'rank' => 'reference_number',
        'order' => 'id DESC',
    ]) as $r) {
        $items[] = [
            'title' => $r['reference_number'] . ' · ' . $r['name'],
            'sub' => trim((int)$r['guests'] . ' guests · ' . ucfirst((string)$r['status']), ' ·'),
            'url' => 'events-inquiries.php?search=' . rawurlencode((string)$r['reference_number']),
        ];
    }
    $add('event_inquiries', 'Event enquiries', 'fa-champagne-glasses', $items);
}

// ---- Gym members ----
if ($can('gym-members.php')) {
    $items = [];
    foreach ($find([
        'select' => 'id, member_number, full_name, membership_type, expiry_date, status',
        'from' => 'gym_members',
        'cols' => ['member_number', 'full_name', 'email', 'phone'],
        'phones' => ['phone'],
        'rank' => 'member_number',
        'order' => 'id DESC',
    ]) as $r) {
        $items[] = [
            'title' => $r['full_name'] . ' · ' . $r['member_number'],
            'sub' => trim(ucfirst((string)$r['membership_type']) . ' · expires ' . $fmtDate($r['expiry_date']) . ' · ' . ucfirst((string)$r['status']), ' ·'),
            'url' => $hl('gym-members.php', (string)$r['member_number']),
        ];
    }
    $add('gym', 'Gym members', 'fa-dumbbell', $items);
}

// ---- Quotations ----
if ($can('quotations.php')) {
    $items = [];
    foreach ($find([
        'select' => 'id, quote_reference, guest_name, room_name, total_amount, status',
        'from' => 'quotations',
        'cols' => ['quote_reference', 'booking_reference', 'guest_name', 'guest_email'],
        'rank' => 'quote_reference',
        'order' => 'id DESC',
    ]) as $r) {
        $items[] = [
            'title' => $r['quote_reference'] . ' · ' . $r['guest_name'],
            'sub' => trim(($r['room_name'] ?? '') . ' · ' . $money($r['total_amount']) . ' · ' . ucfirst((string)$r['status']), ' ·'),
            'url' => 'quotations.php?search=' . rawurlencode((string)$r['quote_reference']),
        ];
    }
    $add('quotations', 'Quotations', 'fa-file-contract', $items);
}

// ---- Credit notes ----
if ($can('credit-notes.php')) {
    $items = [];
    foreach ($find([
        'select' => 'id, credit_note_number, guest_name, balance, status',
        'from' => 'credit_notes',
        'cols' => ['credit_note_number', 'booking_reference', 'guest_name', 'guest_email'],
        'rank' => 'credit_note_number',
        'order' => 'id DESC',
    ]) as $r) {
        $items[] = [
            'title' => $r['credit_note_number'] . ' · ' . $r['guest_name'],
            'sub' => 'Balance ' . $money($r['balance']) . ' · ' . ucfirst((string)$r['status']),
            'url' => 'credit-notes.php?search=' . rawurlencode((string)$r['credit_note_number']),
        ];
    }
    $add('credit_notes', 'Credit notes', 'fa-file-invoice', $items);
}

// ---- Rooms: types and individual rooms ----
if ($can('room-management.php')) {
    $items = [];
    foreach ($find(['select' => 'id, name, price_per_night, is_active', 'from' => 'rooms', 'cols' => ['name', 'slug'], 'rank' => 'name', 'order' => 'display_order']) as $r) {
        $items[] = [
            'title' => $r['name'],
            'sub' => 'Room type · ' . $money($r['price_per_night']) . '/night' . ((int)$r['is_active'] ? '' : ' · inactive'),
            'url' => $hl('room-management.php', (string)$r['name']),
        ];
    }
    $add('room_types', 'Room types', 'fa-bed', $items);
}
if ($can('individual-rooms.php')) {
    $items = [];
    // "room 12" finds room 12: the word "room" also matches the room type name, so allow it on its own.
    foreach ($find([
        'select' => 'ir.id, ir.room_number, ir.room_name, ir.status, ir.housekeeping_status, r.name AS type_name',
        'from' => 'individual_rooms ir LEFT JOIN rooms r ON r.id = ir.room_type_id',
        'cols' => ['ir.room_number', 'ir.room_name', "COALESCE(r.name, '')", "CONCAT('room ', ir.room_number)"],
        'rank' => 'ir.room_number',
        'order' => 'ir.display_order, ir.room_number',
    ]) as $r) {
        $items[] = [
            'title' => 'Room ' . $r['room_number'] . ($r['room_name'] ? ' · ' . $r['room_name'] : ''),
            'sub' => trim(($r['type_name'] ?? '') . ' · ' . ucfirst((string)$r['status']) . ' · ' . ucfirst((string)$r['housekeeping_status']), ' ·'),
            'url' => $hl('individual-rooms.php', (string)$r['room_number']),
        ];
    }
    $add('rooms', 'Rooms', 'fa-door-open', $items);
}

// ---- Menu (food & drinks) ----
if ($can('menu-management.php')) {
    $items = [];
    foreach ([['food_menu', 'food', 'Food'], ['drink_menu', 'drinks', 'Drink']] as [$table, $tab, $kind]) {
        foreach ($find(['select' => 'id, item_name, category, price, is_available', 'from' => $table, 'cols' => ['item_name', 'category'], 'rank' => 'item_name', 'order' => 'item_name']) as $r) {
            $items[] = [
                'title' => $r['item_name'],
                'sub' => $kind . ' · ' . $r['category'] . ' · ' . $money($r['price']) . ((int)$r['is_available'] ? '' : ' · unavailable'),
                'url' => $hl('menu-management.php?tab=' . $tab, (string)$r['item_name']),
            ];
        }
    }
    $add('menu', 'Menu items', 'fa-utensils', array_slice($items, 0, $limit));
}

// ---- Stock ----
if ($can('stock-ingredients.php')) {
    $items = [];
    foreach ($find(['select' => 'id, name, category, current_quantity, unit', 'from' => 'stock_ingredients', 'where' => 'is_archived = 0', 'cols' => ['name', 'category'], 'rank' => 'name', 'order' => 'name']) as $r) {
        $items[] = [
            'title' => $r['name'],
            'sub' => trim(($r['category'] ?? '') . ' · ' . rtrim(rtrim(number_format((float)$r['current_quantity'], 2), '0'), '.') . ' ' . $r['unit'] . ' in stock', ' ·'),
            'url' => $hl('stock-ingredients.php', (string)$r['name']),
        ];
    }
    $add('stock', 'Stock items', 'fa-boxes-stacked', $items);
}
if ($can('stock-suppliers.php')) {
    $items = [];
    foreach ($find(['select' => 'id, name, contact_name, phone', 'from' => 'stock_suppliers', 'cols' => ['name', 'contact_name', 'email', 'phone'], 'phones' => ['phone'], 'rank' => 'name', 'order' => 'name']) as $r) {
        $items[] = [
            'title' => $r['name'],
            'sub' => trim('Supplier · ' . ($r['contact_name'] ?? '') . ' · ' . ($r['phone'] ?? ''), ' ·'),
            'url' => $hl('stock-suppliers.php', (string)$r['name']),
        ];
    }
    $add('suppliers', 'Suppliers', 'fa-truck-field', $items);
}

// ---- Website: contact messages, reviews, events, pages ----
if ($can('contact-inquiries.php')) {
    $items = [];
    foreach ($find([
        'select' => 'id, reference_number, name, subject, status',
        'from' => 'contact_inquiries',
        'cols' => ['reference_number', 'name', 'email', 'subject'],
        'rank' => 'reference_number',
        'order' => 'id DESC',
    ]) as $r) {
        $items[] = [
            'title' => ($r['reference_number'] ? $r['reference_number'] . ' · ' : '') . $r['name'],
            'sub' => trim(($r['subject'] ?? '') . ' · ' . ucfirst((string)$r['status']), ' ·'),
            'url' => 'contact-inquiries.php?search=' . rawurlencode((string)($r['reference_number'] ?: $r['name'])),
        ];
    }
    $add('contact', 'Contact messages', 'fa-envelope', $items);
}
if ($can('reviews.php')) {
    $items = [];
    foreach ($find(['select' => 'id, guest_name, title, rating, status', 'from' => 'reviews', 'cols' => ['guest_name', 'guest_email', 'title'], 'order' => 'id DESC']) as $r) {
        $items[] = [
            'title' => $r['guest_name'] . ' · ' . str_repeat('★', max(0, min(5, (int)$r['rating']))),
            'sub' => trim(($r['title'] ?? '') . ' · ' . ucfirst((string)$r['status']), ' ·'),
            'url' => 'reviews.php?search=' . rawurlencode((string)$r['guest_name']),
        ];
    }
    $add('reviews', 'Reviews', 'fa-star', $items);
}
if ($can('events-management.php')) {
    $items = [];
    foreach ($find(['select' => 'id, title, event_date', 'from' => 'events', 'cols' => ['title', 'location'], 'rank' => 'title', 'order' => 'event_date DESC']) as $r) {
        $items[] = ['title' => $r['title'], 'sub' => 'Event · ' . $fmtDate($r['event_date']), 'url' => $hl('events-management.php', (string)$r['title'])];
    }
    $add('events', 'Events', 'fa-calendar-day', $items);
}
if ($can('conference-management.php')) {
    $items = [];
    foreach ($find(['select' => 'id, name, capacity', 'from' => 'conference_rooms', 'cols' => ['name', 'description'], 'rank' => 'name', 'order' => 'display_order']) as $r) {
        $items[] = ['title' => $r['name'], 'sub' => 'Conference room · ' . (int)$r['capacity'] . ' people', 'url' => $hl('conference-management.php', (string)$r['name'])];
    }
    $add('conference_rooms', 'Conference rooms', 'fa-chalkboard-user', $items);
}
if ($can('rate-plans.php')) {
    $items = [];
    foreach ($find(['select' => 'id, name, is_active', 'from' => 'rate_plans', 'cols' => ['name', 'description'], 'rank' => 'name', 'order' => 'priority DESC, name']) as $r) {
        $items[] = ['title' => $r['name'], 'sub' => 'Rate plan' . ((int)$r['is_active'] ? '' : ' · inactive'), 'url' => $hl('rate-plans.php', (string)$r['name'])];
    }
    $add('rate_plans', 'Rate plans', 'fa-tags', $items);
}

// ---- Staff ----
if ($can('user-management.php')) {
    $items = [];
    foreach ($find(['select' => 'id, username, full_name, role, is_active', 'from' => 'admin_users', 'cols' => ['username', 'full_name', 'email'], 'rank' => 'full_name', 'order' => 'full_name']) as $r) {
        $items[] = [
            'title' => $r['full_name'] . ' (' . $r['username'] . ')',
            'sub' => 'Staff · ' . ucwords(str_replace('_', ' ', (string)$r['role'])) . ((int)$r['is_active'] ? '' : ' · inactive'),
            'url' => $hl('user-management.php', (string)$r['username']),
        ];
    }
    $add('staff', 'Staff', 'fa-users-cog', $items);
}

// ---- Help & guides: the staff guides knowledge base (open to every signed-in user) ----
try {
    require_once __DIR__ . '/../../includes/guide-knowledge-base.php';
    $kb = rh_kb_search($q, 5);
    $items = [];
    $kbLabel = ['problem' => 'Problem & fix', 'faq' => 'FAQ', 'section' => 'Guide', 'page' => 'Where to find', 'permission' => 'Permission', 'role' => 'Role', 'module' => 'Module', 'hint' => 'On-screen help'];
    $kbIcon = ['problem' => 'fas fa-triangle-exclamation', 'faq' => 'fas fa-circle-question', 'page' => 'fas fa-map-signs', 'permission' => 'fas fa-user-lock', 'role' => 'fas fa-id-badge', 'module' => 'fas fa-puzzle-piece', 'hint' => 'fas fa-hand-pointer'];
    foreach ($kb['partial'] ? [] : $kb['results'] as $e) {
        $items[] = [
            'title' => $e['type'] === 'problem' ? '“' . mb_strimwidth($e['title'], 0, 90, '…') . '”' : $e['title'],
            'sub' => ($kbLabel[$e['type']] ?? 'Guide') . ' · ' . $e['guide'] . (!empty($e['parent']) ? ' › ' . $e['parent'] : ''),
            'url' => '../docs/guides/' . $e['url'],
            'icon' => $kbIcon[$e['type']] ?? 'fas fa-book-open',
            'newTab' => true,
        ];
    }
    if ($items) {
        $items[] = [
            'title' => 'Search all guides for “' . $q . '”',
            'sub' => 'Staff guides & FAQ',
            'url' => '../docs/guides/index.html?q=' . rawurlencode($q),
            'icon' => 'fas fa-magnifying-glass',
            'newTab' => true,
        ];
    }
    $add('help', 'Help & guides', 'fa-life-ring', $items);
} catch (Throwable $e) {
    error_log('global-search guides: ' . $e->getMessage());
}

echo json_encode(['success' => true, 'q' => $q, 'groups' => $groups], JSON_UNESCAPED_UNICODE);
