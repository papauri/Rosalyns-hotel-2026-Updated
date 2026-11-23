<?php
/**
 * Dashboard first-login wording (plan .claude/DASHBOARD_FIRSTLOGIN_PLAN.md, tasks A2-A4, B2, C2-C4). Pure: no database, no
 * settings, no session. The readers that feed it are in admin/includes/dashboard-since-data.php.
 * Tested by scripts/tests/dashboard_firstlogin.php.
 */

require_once __DIR__ . '/login-bundles.php'; // rh_login_browser_label (pure)

if (!function_exists('rh_dash_device_words')) {
    /** "Chrome on Windows" / "Chrome" / "Unknown browser on Android" / "Unknown browser" (never blank). */
    function rh_dash_device_words(string $ua): string
    {
        $label = rh_login_browser_label($ua); // "Chrome / Windows", "Unknown", "Unknown / Linux"
        $parts = explode(' / ', $label, 2);
        $browser = ($parts[0] === '' || $parts[0] === 'Unknown') ? 'Unknown browser' : $parts[0];
        return isset($parts[1]) && $parts[1] !== '' ? $browser . ' on ' . $parts[1] : $browser;
    }
}

if (!function_exists('rh_dash_last_signin_text')) {
    /**
     * $prev: rh_dash_prev_signin() result (['at' => 'Y-m-d H:i:s', 'ua' => user agent]); null or no 'at' = no earlier
     * sign-in. $today: 'Y-m-d'. -> "Last signed in Mon 23 Nov at 18:40, Chrome on Windows." ("today" / "yesterday"
     * for those days, the year added when it is not this year) or "First sign-in on this account."
     */
    function rh_dash_last_signin_text(?array $prev, string $today): string
    {
        $at = $prev['at'] ?? null;
        $ts = is_string($at) && $at !== '' ? strtotime($at) : false;
        $todayTs = strtotime($today . ' 00:00:00');
        if ($ts === false || $todayTs === false) {
            return 'First sign-in on this account.';
        }
        $day = date('Y-m-d', $ts);
        if ($day === $today) {
            $when = 'today';
        } elseif ($day === date('Y-m-d', strtotime('-1 day', $todayTs))) {
            $when = 'yesterday';
        } elseif (date('Y', $ts) === date('Y', $todayTs)) {
            $when = date('D j M', $ts);
        } else {
            $when = date('D j M Y', $ts);
        }
        return 'Last signed in ' . $when . ' at ' . date('H:i', $ts) . ', ' . rh_dash_device_words((string)($prev['ua'] ?? '')) . '.';
    }
}

if (!function_exists('rh_dash_eod_chip')) {
    /**
     * The day status chip next to the date. $status: rh_dash_eod_status() result (day, sent_at, test).
     * $today: 'Y-m-d'. -> ['state' => 'ok'|'warn', 'text' => plain text, 'href' => ?string]
     * Sent: "Yesterday closed · End of Day sent 07:12" (+ " (test)"). Not sent: "Yesterday's End of Day not run",
     * linking to that day's End of Day report. A day other than yesterday is named ("Sat 21 Nov closed").
     */
    function rh_dash_eod_chip(array $status, string $today): array
    {
        $day = (string)($status['day'] ?? '');
        $dayTs = strtotime($day . ' 00:00:00');
        $todayTs = strtotime($today . ' 00:00:00');
        if ($dayTs === false || $todayTs === false) {
            $name = 'Last trading day';
        } elseif ($day === date('Y-m-d', strtotime('-1 day', $todayTs))) {
            $name = 'Yesterday';
        } elseif ($day === $today) {
            $name = 'Today';
        } else {
            $name = date('D j M', $dayTs);
        }
        $sentAt = $status['sent_at'] ?? null;
        $sentTs = is_string($sentAt) && $sentAt !== '' ? strtotime($sentAt) : false;
        if ($sentTs !== false) {
            return [
                'state' => 'ok',
                'text' => $name . ' closed · End of Day sent ' . date('H:i', $sentTs) . (!empty($status['test']) ? ' (test)' : ''),
                'href' => null,
            ];
        }
        return [
            'state' => 'warn',
            'text' => $name . "'s End of Day not run",
            'href' => $dayTs !== false ? 'end-of-day-report.php?date=' . rawurlencode($day) : 'end-of-day-report.php',
        ];
    }
}

if (!function_exists('rh_dash_since_line')) {
    /**
     * The "Since you were last here" lede (plan B2), as HTML. $counts: rh_dash_since_counts() result (null item =
     * gated off). $since: the window start ('Y-m-d H:i:s', the previous sign-in), used in every link so each count
     * opens exactly its rows. $moneyHtml: the dashboard's $money (returns trusted HTML, never escaped again).
     * $can(page): link only when the user may open that page, else plain text. $tail: the existing plain-text tail
     * (" — 4 things need you."). Every other part is escaped here, one by one.
     * -> "Since you were last here: 3 new bookings · 1 cancelled · MWK 420,000.00 received · 2 room moves — ..." with
     * zero items left out; all zero -> "Nothing new since you were last here — ..."; '' when no item is shown to this
     * user (the caller keeps today's lede).
     */
    function rh_dash_since_line(array $counts, string $since, callable $moneyHtml, callable $can, string $tail): string
    {
        $e = static fn(string $s): string => htmlspecialchars($s, ENT_QUOTES, 'UTF-8');
        $bk = static fn(string $kind): string => 'bookings.php?' . http_build_query(['since' => $since, 'since_kind' => $kind]);
        $defs = [
            'booked' => ['bookings.php', static fn(int $n): string => $e($n . ' new booking' . ($n === 1 ? '' : 's')), $bk('booked')],
            'cancelled' => ['bookings.php', static fn(int $n): string => $e($n . ' cancelled'), $bk('cancelled')],
            'payments' => ['payments.php', static fn(int $n): string => $moneyHtml($counts['received'] ?? '0') . ' ' . $e('received'),
                'payments.php?' . http_build_query(['status' => 'received', 'since' => $since])],
            'moved' => ['bookings.php', static fn(int $n): string => $e($n . ' room move' . ($n === 1 ? '' : 's')), $bk('moved')],
        ];
        $shown = false;
        $parts = [];
        foreach ($defs as $key => [$page, $label, $href]) {
            if (!isset($counts[$key]) || $counts[$key] === null) {
                continue;
            }
            $shown = true;
            $n = (int)$counts[$key];
            if ($n <= 0) {
                continue;
            }
            $parts[] = $can($page) ? '<a class="ck-hero__since-link" href="' . $e($href) . '">' . $label($n) . '</a>' : $label($n);
        }
        if (!$shown) {
            return '';
        }
        $head = $parts ? $e('Since you were last here: ') . implode(' &middot; ', $parts) : $e('Nothing new since you were last here');
        return $head . $e($tail);
    }
}

if (!function_exists('rh_dash_handover_clean_body')) {
    /**
     * A handover note's text as it is stored (plan C2): line ends made "\n", control characters removed (tabs and line
     * ends kept), trimmed. null = refused: not valid UTF-8, empty, or over 500 characters after the trim.
     */
    function rh_dash_handover_clean_body(string $raw): ?string
    {
        if (!mb_check_encoding($raw, 'UTF-8')) {
            return null;
        }
        $body = str_replace(["\r\n", "\r"], "\n", $raw);
        $body = trim((string)preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/u', '', $body));
        $len = mb_strlen($body, 'UTF-8');
        return ($len >= 1 && $len <= 500) ? $body : null;
    }
}

if (!function_exists('rh_dash_handover_from_text')) {
    /**
     * "From Grace Banda, 22:10" (today), "From Grace Banda, yesterday 22:10", "From Grace Banda, Sun 22 Nov 22:10" (older;
     * notes live 48 hours). $more: other unread notes -> " · and 1 more". Plain text: the caller escapes it.
     */
    function rh_dash_handover_from_text(string $name, string $at, string $today, int $more = 0): string
    {
        $name = trim($name) !== '' ? trim($name) : 'a colleague';
        $ts = $at !== '' ? strtotime($at) : false;
        $todayTs = strtotime($today . ' 00:00:00');
        $when = '';
        if ($ts !== false && $todayTs !== false) {
            $day = date('Y-m-d', $ts);
            if ($day === $today) {
                $when = date('H:i', $ts);
            } elseif ($day === date('Y-m-d', strtotime('-1 day', $todayTs))) {
                $when = 'yesterday ' . date('H:i', $ts);
            } else {
                $when = date('D j M H:i', $ts);
            }
        }
        return 'From ' . $name . ($when !== '' ? ', ' . $when : '') . ($more > 0 ? ' · and ' . $more . ' more' : '');
    }
}

if (!function_exists('rh_dash_welcome_pages')) {
    /**
     * The welcome card's main pages (plan C4): the role's own ordered list, only pages $can(page) allows, at most 3.
     * -> [['page' => 'bookings.php', 'label' => 'Bookings', 'icon' => 'fa-calendar-check'], ...]; [] = none to offer.
     * A role not listed (a custom role) gets the general list, still filtered by $can.
     */
    function rh_dash_welcome_pages(string $role, callable $can): array
    {
        $pages = [
            'bookings.php' => ['Bookings', 'fa-calendar-check'],
            'calendar.php' => ['Calendar', 'fa-calendar-days'],
            'room-dashboard.php' => ['Rooms', 'fa-bed'],
            'housekeeping.php' => ['Housekeeping', 'fa-broom'],
            'payments.php' => ['Payments', 'fa-money-bill-wave'],
            'accounting-dashboard.php' => ['Accounting', 'fa-scale-balanced'],
            'end-of-day-report.php' => ['Day report', 'fa-file-invoice-dollar'],
            'reports.php' => ['Reports', 'fa-chart-column'],
            'stock-dashboard.php' => ['Stock', 'fa-boxes-stacked'],
            'conference-management.php' => ['Conferences', 'fa-users'],
            'gym-checkin.php' => ['Member check-in', 'fa-id-badge'],
            'user-management.php' => ['Staff accounts', 'fa-user-gear'],
        ];
        $byRole = [
            'admin' => ['bookings.php', 'accounting-dashboard.php', 'user-management.php', 'end-of-day-report.php', 'reports.php'],
            'manager' => ['bookings.php', 'calendar.php', 'room-dashboard.php', 'reports.php', 'end-of-day-report.php'],
            'receptionist' => ['bookings.php', 'calendar.php', 'room-dashboard.php', 'housekeeping.php', 'payments.php'],
            'housekeeping' => ['housekeeping.php', 'room-dashboard.php'],
            'accountant' => ['payments.php', 'accounting-dashboard.php', 'end-of-day-report.php', 'reports.php'],
            'viewer' => ['bookings.php', 'calendar.php', 'reports.php'],
            'gym_staff' => ['gym-checkin.php'],
            'conference_staff' => ['conference-management.php', 'calendar.php'],
            // Station roles land on their station (admin/login.php), not here: nothing to point them at.
            'restaurant_staff' => [], 'chef' => [], 'bar_staff' => [], 'coffee_staff' => [], 'room_service' => [],
        ];
        $list = $byRole[$role] ?? ['bookings.php', 'calendar.php', 'room-dashboard.php', 'payments.php', 'reports.php',
            'stock-dashboard.php', 'conference-management.php', 'gym-checkin.php'];
        $out = [];
        foreach ($list as $page) {
            if (count($out) >= 3) {
                break;
            }
            if (isset($pages[$page]) && $can($page)) {
                $out[] = ['page' => $page, 'label' => $pages[$page][0], 'icon' => $pages[$page][1]];
            }
        }
        return $out;
    }
}

if (!function_exists('rh_dash_guide_links')) {
    /**
     * The guide links, one list for the hero "Guides" menu and the welcome card (plan C4), so both use the same
     * conditions. $on: which modules / permissions are on (bookings, pos, kds, bds, cds, room_service, housekeeping,
     * stock, finance, reports). -> [['href', 'icon', 'label'], ...] in menu order; labels are plain text.
     */
    function rh_dash_guide_links(array $on): array
    {
        $g = '../docs/guides/';
        $all = [
            [$g . 'index.html', 'fa-book-open', 'All guides', true],
            [$g . '99-admin-dashboard-full-guide.html', 'fa-scroll', 'Admin reference', true],
            [$g . '07-reception-bookings.html', 'fa-calendar-check', 'Reception', !empty($on['bookings'])],
            [$g . '01-pos-till.html', 'fa-cash-register', 'POS till', !empty($on['pos'])],
            [$g . '02-kds-kitchen.html', 'fa-utensils', 'Kitchen (KDS)', !empty($on['kds'])],
            [$g . '03-bds-bar.html', 'fa-martini-glass', 'Bar (BDS)', !empty($on['bds'])],
            [$g . '04-cds-coffee.html', 'fa-mug-hot', 'Coffee (CDS)', !empty($on['cds'])],
            [$g . '05-room-service.html', 'fa-bell-concierge', 'Room service', !empty($on['room_service'])],
            [$g . '06-housekeeping.html', 'fa-broom', 'Housekeeping', !empty($on['housekeeping'])],
            [$g . '08-stock-orders.html', 'fa-boxes-stacked', 'Stock', !empty($on['stock'])],
            [$g . '13-finance-payments.html', 'fa-money-bill-wave', 'Finance', !empty($on['finance'])],
            [$g . '14-reports-eod.html', 'fa-chart-column', 'Reports & EOD', !empty($on['reports'])],
        ];
        $out = [];
        foreach ($all as [$href, $icon, $label, $show]) {
            if ($show) {
                $out[] = ['href' => $href, 'icon' => $icon, 'label' => $label];
            }
        }
        return $out;
    }
}
