<?php
/**
 * Dashboard "Needs attention" queue (owner 2026-11-12: "accurate at 100% ... people can only see the information
 * that they have access to. Nothing more or less ... it could be for any business module").
 *
 * Pure: no database, no session. The caller passes three closures:
 *   $perm(string ...$keys): bool  the user holds ANY of these permission keys (hasPermission)
 *   $mod(string $key): bool       this module is switched on (rh_module_key_enabled: 'events' = events page on)
 *   $can(string $page): bool      the user may open that admin page (rhCanLinkTo: permission + module + portal)
 *
 * Rules, one per item (rh_dash_attention_defs):
 * - The item shows only when its modules are on AND the user holds the permission of the list it counts, so a
 *   gym, shop or bar never sees room items and nobody is told a queue they cannot see is clear.
 * - The count is exactly what its link lists (same statuses, date rule and deleted rows): the link filters are
 *   noted per item and checked by scripts/tests/dashboard_attention.php.
 * - The link shows only when the user can open that page; otherwise the item is plain text.
 * - Every item carries a plain hint saying what it counts.
 */

require_once __DIR__ . '/front-desk-counts.php'; // rh_fd_room_service_open_href (pure)

if (!function_exists('rh_dash_attention_defs')) {
    /**
     * Every Needs-attention item, in panel order. 'when' = alternatives (any one is enough), each with the modules
     * that must all be on and permission groups that must all match (a group = any of its keys).
     * 'insight_needs_link': the popup is only offered to users who can open the item's page.
     */
    function rh_dash_attention_defs(bool $restaurant = false): array
    {
        $bk = ['modules' => ['bookings'], 'perms' => [['bookings']]];
        return [
            // bookings.php ?arrival=overdue: confirmed/pending, arrival before today (rh_bk_arrival_clause).
            'overdue_arrivals' => ['label' => 'Overdue arrivals (no-show?)', 'icon' => 'fa-user-clock', 'tone' => 'red',
                'href' => 'bookings.php?arrival=overdue', 'when' => [$bk], 'insight' => null,
                'hint' => 'Arrival date passed, never checked in'],
            // bookings.php ?departure=overdue: checked in, check-out before today (rh_bk_departure_clause).
            'overdue_departures' => ['label' => 'Overdue departures', 'icon' => 'fa-person-walking-luggage', 'tone' => 'red',
                'href' => 'bookings.php?departure=overdue', 'when' => [$bk], 'insight' => null,
                'hint' => 'Checkout date passed, still checked in'],
            // bookings.php ?status=pending (alias of filter_status).
            'pending_bookings' => ['label' => 'Bookings awaiting confirmation', 'icon' => 'fa-hourglass-half', 'tone' => 'amber',
                'href' => 'bookings.php?status=pending', 'when' => [$bk], 'insight' => 'pending_bookings',
                'hint' => 'Status Pending, not confirmed yet'],
            // bookings.php Today's Check-outs + ?balance=owing (amount due above BALANCE_TOLERANCE).
            'departures_owing' => ['label' => 'Departures with a balance', 'icon' => 'fa-sack-dollar', 'tone' => 'red',
                'href' => 'bookings.php?filter=checkout_today&balance=owing', 'when' => [$bk], 'insight' => null,
                'hint' => 'Due out today, balance to collect before they leave'],
            // payments.php ?refund_status=pending,processing (not module-scoped, like the count). Refund approvers
            // without Payments see the number as plain text (owner 2026-11-11: stays on payments / refund_payment).
            'refunds_pending' => ['label' => 'Refunds to approve', 'icon' => 'fa-rotate-left', 'tone' => 'red',
                'href' => 'payments.php?refund_status=pending,processing',
                'when' => [['modules' => ['finance'], 'perms' => [['accounting', 'payments', 'reports'], ['payments', 'refund_payment']]]],
                'insight' => 'refunds_pending', 'insight_needs_link' => true,
                'hint' => 'Refunds pending or being paid out'],
            // conference-management.php ?status=pending (rh_conf_list_filter); the page needs 'conference'.
            'pending_conference' => ['label' => 'Conference enquiries to answer', 'icon' => 'fa-users-gear', 'tone' => 'amber',
                'href' => 'conference-management.php?status=pending',
                'when' => [['modules' => ['conference'], 'perms' => [['conference']]]], 'insight' => 'pending_conference',
                'hint' => 'Enquiries still Pending'],
            'unread_contact' => ['label' => 'Unread contact messages', 'icon' => 'fa-envelope', 'tone' => 'red',
                'href' => 'contact-inquiries.php?status=new',
                'when' => [['modules' => ['website_cms'], 'perms' => [['contact']]]], 'insight' => null,
                'hint' => 'Website messages still New'],
            'pending_reviews' => ['label' => 'Reviews to moderate', 'icon' => 'fa-star', 'tone' => 'amber',
                'href' => 'reviews.php?status=pending',
                'when' => [['modules' => ['website_cms'], 'perms' => [['reviews']]]], 'insight' => null,
                'hint' => 'Reviews waiting to be approved or rejected'],
            'pending_events' => ['label' => 'Event bookings pending', 'icon' => 'fa-calendar-check', 'tone' => 'amber',
                'href' => 'events-inquiries.php?status=pending',
                'when' => [['modules' => ['website_cms', 'events'], 'perms' => [['events_bookings']]]], 'insight' => null,
                'hint' => 'Event bookings still Pending'],
            'pending_gym' => ['label' => 'Gym enquiries to answer', 'icon' => 'fa-dumbbell', 'tone' => 'amber',
                'href' => 'gym-inquiries.php?status=new',
                'when' => [['modules' => ['gym'], 'perms' => [['gym']]]], 'insight' => null,
                'hint' => 'Enquiries still New, not contacted yet'],
            // gym-members.php ?filter=expiring (rh_gym_member_filter_where 'expiring').
            'gym_expiring' => ['label' => 'Gym memberships expiring (30d)', 'icon' => 'fa-id-card', 'tone' => 'amber',
                'href' => 'gym-members.php?filter=expiring',
                'when' => [['modules' => ['gym'], 'perms' => [['gym']]]], 'insight' => null,
                'hint' => 'Active memberships ending in the next 30 days'],
            // room-maintenance.php belongs to the bookings module (getModuleForPage), not housekeeping.
            'rooms_out_of_service' => ['label' => 'Rooms out of service', 'icon' => 'fa-screwdriver-wrench', 'tone' => 'red',
                'href' => 'room-maintenance.php?status=out_of_service',
                'when' => [['modules' => ['bookings'], 'perms' => [['room_maintenance']]]], 'insight' => null,
                'hint' => 'In maintenance or out of order; a joined room counts once'],
            'housekeeping_due' => ['label' => 'Housekeeping tasks due', 'icon' => 'fa-broom', 'tone' => 'amber',
                'href' => 'housekeeping.php?due=now',
                'when' => [['modules' => ['housekeeping'], 'perms' => [['housekeeping']]]], 'insight' => null,
                'hint' => 'Pending or in progress, due today or earlier'],
            // Orders & Tabs (room-service open statuses, all dates). Room-service staff see the same orders in the
            // Dashboard's Room service panel, so they see the item too (as plain text without Orders & Tabs).
            'room_service_open' => ['label' => 'Room-service orders open', 'icon' => 'fa-bell-concierge', 'tone' => 'amber',
                'href' => rh_fd_room_service_open_href(),
                'when' => [
                    ['modules' => ['pos', 'bookings'], 'perms' => [['room_service_view']]],
                    ['modules' => ['pos', 'bookings', 'stock'], 'perms' => [['stock_orders']]],
                ],
                'insight' => 'room_service_pending',
                'hint' => 'Room-service orders placed, pending or confirmed'],
            // The list is the popup (occupied rooms with no order served today); the link opens the room-service screen.
            'room_service_reminders' => ['label' => 'Room-service reminders due', 'icon' => 'fa-bell', 'tone' => 'amber',
                'href' => 'room-service-dashboard.php',
                'when' => [['modules' => ['pos', 'bookings'], 'perms' => [['room_service_view']]]],
                'insight' => 'room_service_reminders_due',
                'hint' => 'Rooms with no order served today'],
            // stock-orders.php ?status=placed (no date window, like the count). Any business with a till and stock.
            'open_tabs' => ['label' => $restaurant ? 'Open tabs awaiting payment' : 'Orders awaiting payment', 'icon' => 'fa-receipt', 'tone' => 'amber',
                'href' => 'stock-orders.php?status=placed',
                'when' => [['modules' => ['pos', 'stock'], 'perms' => [['stock_orders']]]], 'insight' => 'open_tabs',
                'hint' => $restaurant ? 'Tabs placed and not paid yet' : 'Orders placed and not paid yet'],
            // stock-batches.php ?filter=expired (rh_stock_batch_expired_sql).
            'expired_batches' => ['label' => 'Expired stock batches', 'icon' => 'fa-skull-crossbones', 'tone' => 'red',
                'href' => 'stock-batches.php?filter=expired',
                'when' => [['modules' => ['stock'], 'perms' => [['stock_batches']]]], 'insight' => null,
                'hint' => 'Past their expiry date with stock left'],
            // stock-ingredients.php ?filter=low (rh_stock_alert_summary 'attention' = out + low).
            'low_stock' => ['label' => 'Items below minimum stock', 'icon' => 'fa-box-open', 'tone' => 'amber',
                'href' => 'stock-ingredients.php?filter=low',
                'when' => [['modules' => ['stock'], 'perms' => [['stock_management']]]], 'insight' => null,
                'hint' => 'Out of stock, or at or below the reorder level'],
        ];
    }

    /** One item's rule: any alternative whose modules are all on and whose permission groups all match. */
    function rh_dash_attention_allowed(array $def, callable $perm, callable $mod): bool
    {
        foreach ((array)($def['when'] ?? []) as $alt) {
            $ok = true;
            foreach ((array)($alt['modules'] ?? []) as $m) {
                if (!$mod((string)$m)) { $ok = false; break; }
            }
            if ($ok) {
                foreach ((array)($alt['perms'] ?? []) as $anyOf) {
                    if (!$perm(...array_map('strval', (array)$anyOf))) { $ok = false; break; }
                }
            }
            if ($ok) {
                return true;
            }
        }
        return false;
    }

    /** key => bool for every item: the panel, the counts and the popups all use these. */
    function rh_dash_attention_gates(callable $perm, callable $mod): array
    {
        $gates = [];
        foreach (rh_dash_attention_defs() as $key => $def) {
            $gates[$key] = rh_dash_attention_allowed($def, $perm, $mod);
        }
        return $gates;
    }

    /** Admin page a link opens ('bookings.php?x=1' / 'conference-management.php#enquiry-3' -> file name). */
    function rh_dash_link_page(string $href): string
    {
        $path = (string)parse_url($href, PHP_URL_PATH);
        return basename($path);
    }

    /**
     * The items this user sees, in panel order: count, label, icon, tone, href ('' = plain text), insight (popup
     * card key or null), hint, key. $counts: key => int.
     */
    function rh_dash_attention_items(array $gates, array $counts, callable $can, bool $restaurant): array
    {
        $out = [];
        foreach (rh_dash_attention_defs($restaurant) as $key => $def) {
            if (empty($gates[$key])) {
                continue;
            }
            $canOpen = $can(rh_dash_link_page((string)$def['href']));
            $insight = $def['insight'] ?? null;
            if ($insight !== null && !empty($def['insight_needs_link']) && !$canOpen) {
                $insight = null;
            }
            $out[] = [
                'key'     => $key,
                'count'   => (int)($counts[$key] ?? 0),
                'label'   => (string)$def['label'],
                'icon'    => (string)$def['icon'],
                'tone'    => (string)$def['tone'],
                'href'    => $canOpen ? (string)$def['href'] : '',
                'insight' => $insight,
                'hint'    => (string)$def['hint'],
            ];
        }
        return $out;
    }

    /** [open items (red first, then the biggest), clear items, total of the open counts]. */
    function rh_dash_attention_split(array $items): array
    {
        $open = array_values(array_filter($items, static fn($a) => (int)$a['count'] > 0));
        $clear = array_values(array_filter($items, static fn($a) => (int)$a['count'] <= 0));
        usort($open, static fn($a, $b) => [$a['tone'] === 'red' ? 0 : 1, -$a['count']] <=> [$b['tone'] === 'red' ? 0 : 1, -$b['count']]);
        return [$open, $clear, (int)array_sum(array_column($open, 'count'))];
    }

    /** Guest services popup: shown when at least one of its rows is (reviews, contact, gym, events, front desk). */
    function rh_dash_guest_services_gate(array $gates, bool $frontDesk): bool
    {
        return !empty($gates['pending_reviews']) || !empty($gates['unread_contact']) || !empty($gates['pending_gym'])
            || !empty($gates['pending_events']) || $frontDesk;
    }

    /** Operations popup: shown when at least one of its rows is (rooms, housekeeping, room service, open tabs, owed). */
    function rh_dash_operations_gate(array $gates, bool $owed): bool
    {
        return !empty($gates['rooms_out_of_service']) || !empty($gates['housekeeping_due']) || !empty($gates['room_service_open'])
            || !empty($gates['room_service_reminders']) || !empty($gates['open_tabs']) || $owed;
    }

    /**
     * Popup links the user cannot open are taken out (the card's buttons hide, a row's action becomes blank, a linked
     * cell becomes its plain label), so a popup never offers a page the user would be refused.
     */
    function rh_dash_insight_strip_links(array $payload, callable $can): array
    {
        $ok = static function ($link) use ($can): bool {
            return is_array($link) && isset($link['href']) && $can(rh_dash_link_page((string)$link['href']));
        };
        foreach (['link', 'link2'] as $k) {
            if (isset($payload[$k]) && is_array($payload[$k]) && !$ok($payload[$k])) {
                $payload[$k] = null;
            }
        }
        if (isset($payload['rows']) && is_array($payload['rows'])) {
            foreach ($payload['rows'] as &$row) {
                if (!is_array($row)) {
                    continue;
                }
                foreach ($row as $col => $cell) {
                    if (!is_array($cell) || !isset($cell['href'])) {
                        continue;
                    }
                    if (!$ok($cell)) {
                        $row[$col] = $col === 'action' ? '' : (string)($cell['label'] ?? '');
                    }
                }
            }
            unset($row);
        }
        return $payload;
    }
}
