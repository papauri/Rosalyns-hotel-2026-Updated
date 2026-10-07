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
$like = '%' . addcslashes($q, '%_\\') . '%';
$limit = 6;

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
/** Run one source; a failing source (missing table on an older install) never breaks the rest. */
$rows = static function (string $sql, int $n) use ($pdo, $like, $limit): array {
    try {
        $st = $pdo->prepare($sql . ' LIMIT ' . $limit);
        $st->execute(array_fill(0, $n, $like));
        return $st->fetchAll(PDO::FETCH_ASSOC);
    } catch (Throwable $e) {
        error_log('global-search: ' . $e->getMessage());
        return [];
    }
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
        if (stripos($title, $q) !== false || stripos($kw, $q) !== false) {
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
    foreach ($rows("SELECT b.id, b.booking_reference, b.guest_name, b.guest_email, b.guest_phone, b.check_in_date, b.check_out_date, b.status, r.name AS room_name
                    FROM bookings b LEFT JOIN rooms r ON r.id = b.room_id
                    WHERE b.booking_reference LIKE ? OR b.guest_name LIKE ? OR b.guest_email LIKE ? OR b.guest_phone LIKE ?
                    ORDER BY b.id DESC", 4) as $r) {
        $items[] = [
            'title' => $r['booking_reference'] . ' · ' . $r['guest_name'],
            'sub' => trim(($r['room_name'] ?? '') . ' · ' . $fmtDate($r['check_in_date']) . ' – ' . $fmtDate($r['check_out_date']) . ' · ' . ucwords(str_replace('-', ' ', (string)$r['status'])), ' ·'),
            'url' => $can('booking-details.php') ? 'booking-details.php?id=' . (int)$r['id'] : 'bookings.php?search=' . rawurlencode((string)$r['booking_reference']),
        ];
    }
    $add('bookings', 'Bookings', 'fa-calendar-check', $items);
}

// ---- Payments & receipts ----
if ($can('payment-details.php')) {
    $items = [];
    foreach ($rows("SELECT id, payment_reference, receipt_number, booking_reference, total_amount, payment_method, payment_date, payment_status
                    FROM payments
                    WHERE deleted_at IS NULL AND (payment_reference LIKE ? OR receipt_number LIKE ? OR booking_reference LIKE ? OR payment_reference_number LIKE ?)
                    ORDER BY id DESC", 4) as $r) {
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
    foreach ($rows("SELECT id, inquiry_reference, company_name, contact_person, event_date, status
                    FROM conference_inquiries
                    WHERE inquiry_reference LIKE ? OR company_name LIKE ? OR contact_person LIKE ? OR email LIKE ? OR phone LIKE ?
                    ORDER BY id DESC", 5) as $r) {
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
    foreach ($rows("SELECT id, reference_number, name, guests, status, created_at
                    FROM event_inquiries
                    WHERE reference_number LIKE ? OR name LIKE ? OR email LIKE ? OR phone LIKE ?
                    ORDER BY id DESC", 4) as $r) {
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
    foreach ($rows("SELECT id, member_number, full_name, membership_type, expiry_date, status
                    FROM gym_members
                    WHERE member_number LIKE ? OR full_name LIKE ? OR email LIKE ? OR phone LIKE ?
                    ORDER BY id DESC", 4) as $r) {
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
    foreach ($rows("SELECT id, quote_reference, guest_name, room_name, total_amount, status
                    FROM quotations
                    WHERE quote_reference LIKE ? OR booking_reference LIKE ? OR guest_name LIKE ? OR guest_email LIKE ?
                    ORDER BY id DESC", 4) as $r) {
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
    foreach ($rows("SELECT id, credit_note_number, guest_name, balance, status
                    FROM credit_notes
                    WHERE credit_note_number LIKE ? OR booking_reference LIKE ? OR guest_name LIKE ? OR guest_email LIKE ?
                    ORDER BY id DESC", 4) as $r) {
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
    foreach ($rows("SELECT id, name, price_per_night, is_active FROM rooms WHERE name LIKE ? OR slug LIKE ? ORDER BY display_order", 2) as $r) {
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
    foreach ($rows("SELECT ir.id, ir.room_number, ir.room_name, ir.status, ir.housekeeping_status, r.name AS type_name
                    FROM individual_rooms ir LEFT JOIN rooms r ON r.id = ir.room_type_id
                    WHERE ir.room_number LIKE ? OR ir.room_name LIKE ?
                    ORDER BY ir.display_order, ir.room_number", 2) as $r) {
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
        foreach ($rows("SELECT id, item_name, category, price, is_available FROM {$table} WHERE item_name LIKE ? OR category LIKE ? ORDER BY item_name", 2) as $r) {
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
    foreach ($rows("SELECT id, name, category, current_quantity, unit FROM stock_ingredients WHERE is_archived = 0 AND (name LIKE ? OR category LIKE ?) ORDER BY name", 2) as $r) {
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
    foreach ($rows("SELECT id, name, contact_name, phone FROM stock_suppliers WHERE name LIKE ? OR contact_name LIKE ? OR email LIKE ? OR phone LIKE ? ORDER BY name", 4) as $r) {
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
    foreach ($rows("SELECT id, reference_number, name, subject, status FROM contact_inquiries
                    WHERE reference_number LIKE ? OR name LIKE ? OR email LIKE ? OR subject LIKE ?
                    ORDER BY id DESC", 4) as $r) {
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
    foreach ($rows("SELECT id, guest_name, title, rating, status FROM reviews WHERE guest_name LIKE ? OR guest_email LIKE ? OR title LIKE ? ORDER BY id DESC", 3) as $r) {
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
    foreach ($rows("SELECT id, title, event_date FROM events WHERE title LIKE ? OR location LIKE ? ORDER BY event_date DESC", 2) as $r) {
        $items[] = ['title' => $r['title'], 'sub' => 'Event · ' . $fmtDate($r['event_date']), 'url' => $hl('events-management.php', (string)$r['title'])];
    }
    $add('events', 'Events', 'fa-calendar-day', $items);
}
if ($can('conference-management.php')) {
    $items = [];
    foreach ($rows("SELECT id, name, capacity FROM conference_rooms WHERE name LIKE ? OR description LIKE ? ORDER BY display_order", 2) as $r) {
        $items[] = ['title' => $r['name'], 'sub' => 'Conference room · ' . (int)$r['capacity'] . ' people', 'url' => $hl('conference-management.php', (string)$r['name'])];
    }
    $add('conference_rooms', 'Conference rooms', 'fa-chalkboard-user', $items);
}
if ($can('rate-plans.php')) {
    $items = [];
    foreach ($rows("SELECT id, name, is_active FROM rate_plans WHERE name LIKE ? OR description LIKE ? ORDER BY priority DESC, name", 2) as $r) {
        $items[] = ['title' => $r['name'], 'sub' => 'Rate plan' . ((int)$r['is_active'] ? '' : ' · inactive'), 'url' => $hl('rate-plans.php', (string)$r['name'])];
    }
    $add('rate_plans', 'Rate plans', 'fa-tags', $items);
}

// ---- Staff ----
if ($can('user-management.php')) {
    $items = [];
    foreach ($rows("SELECT id, username, full_name, role, is_active FROM admin_users WHERE username LIKE ? OR full_name LIKE ? OR email LIKE ? ORDER BY full_name", 3) as $r) {
        $items[] = [
            'title' => $r['full_name'] . ' (' . $r['username'] . ')',
            'sub' => 'Staff · ' . ucwords(str_replace('_', ' ', (string)$r['role'])) . ((int)$r['is_active'] ? '' : ' · inactive'),
            'url' => $hl('user-management.php', (string)$r['username']),
        ];
    }
    $add('staff', 'Staff', 'fa-users-cog', $items);
}

echo json_encode(['success' => true, 'q' => $q, 'groups' => $groups], JSON_UNESCAPED_UNICODE);
