<?php

/**
 * Assistant tools (.claude/AI_ASSISTANT_PLAN.md Phase 2 + "Owner direction").
 *
 * Registry entries: name => {description, schema, perm (any-of; [] = anyone who may use the
 * Assistant), module (any-of; [] = none), gate (optional extra check), cache (seconds), handler}.
 * READ tools only (Phase 2 + the Phase 3 department reads) - there is no tool that writes anything, and no raw SQL, file, shell,
 * settings, users, permissions or backup tool (hard limits; a test checks the list).
 *
 * Every call re-checks the person's permission and the module state (never trusts the list the
 * model was given), turns privacy tokens in the arguments back into real values, validates the
 * arguments against the schema, runs prepared statements only, caps rows and size, and passes the
 * result through the vault so no real name/email/phone/reference leaves the hotel. Figures come
 * from the same helpers / SQL the screens use (§Map "Read sources").
 */

require_once __DIR__ . '/ai-core.php';
require_once __DIR__ . '/ai-vault.php';
require_once __DIR__ . '/ai-insights.php'; // Phase 3 department reads + the daily briefing (read only)
require_once __DIR__ . '/ai-memory.php';   // Phase 4b: the circuit breaker (a tool that keeps failing is switched off for an hour)
require_once __DIR__ . '/ai-extras.php';   // Phases 4c + 5: password on risky cards, idle lock, audit chain, alerts, extra reads
require_once __DIR__ . '/ai-lookups.php';  // one-record lookups: an order, a gym member or class, a conference booking, a stock item, a room's jobs

if (!defined('RH_AI_TOOL_ROWS')) {
    define('RH_AI_TOOL_ROWS', 20);      // row cap per list (plan: default 20 rows)
}
if (!defined('RH_AI_TEXT_CHARS')) {
    define('RH_AI_TEXT_CHARS', 1000);   // one free-text field (a guest note, a review) the model sees; guide text excepted
}
if (!defined('RH_AI_TOOL_BYTES')) {
    define('RH_AI_TOOL_BYTES', 7000);   // size cap of one tool result kept as evidence
}

// ── Per-answer memo (owner 2026-10-28: "super swift") ─────────────────────────
// One answer checks the same permission keys and tool list dozens of times (about 60 database reads each
// time). Inside rh_ai_turn() they are read once; permissions cannot change in the middle of one answer.
// Outside a turn (confirming a card, the settings page, tests between steps) every check reads the database.

function rh_ai_memo_begin(): void
{
    $GLOBALS['__rh_ai_memo'] = ['perm' => [], 'mod' => [], 'tools' => []];
}

function rh_ai_memo_end(): void
{
    unset($GLOBALS['__rh_ai_memo']);
}

/** hasPermission(), read once per answer inside a turn. */
function rh_ai_has_perm(int $uid, string $key): bool
{
    if (!function_exists('hasPermission')) {
        return false;
    }
    if (!isset($GLOBALS['__rh_ai_memo']['perm'])) {
        return (bool)hasPermission($uid, $key);
    }
    $k = $uid . '|' . $key;
    if (!array_key_exists($k, $GLOBALS['__rh_ai_memo']['perm'])) {
        $GLOBALS['__rh_ai_memo']['perm'][$k] = (bool)hasPermission($uid, $key);
    }
    return $GLOBALS['__rh_ai_memo']['perm'][$k];
}

/** rh_module_key_enabled(), read once per answer inside a turn. */
function rh_ai_module_on(string $m): bool
{
    if (isset($GLOBALS['__rh_ai_module_override'][$m])) {
        return (bool)$GLOBALS['__rh_ai_module_override'][$m]; // tests only: a module seen as switched off / on
    }
    if (!function_exists('rh_module_key_enabled')) {
        return true;
    }
    if (!isset($GLOBALS['__rh_ai_memo']['mod'])) {
        return (bool)rh_module_key_enabled($m);
    }
    if (!array_key_exists($m, $GLOBALS['__rh_ai_memo']['mod'])) {
        $GLOBALS['__rh_ai_memo']['mod'][$m] = (bool)rh_module_key_enabled($m);
    }
    return $GLOBALS['__rh_ai_memo']['mod'][$m];
}

function rh_ai_tool_registry(): array
{
    $date = ['type' => 'string', 'description' => 'Date as YYYY-MM-DD', 'format' => 'date'];
    $reg = [
        'search_bookings' => [
            'description' => 'Find room bookings by guest name, email, phone or booking reference (tokens like [GUEST_1] are fine). Returns up to 10 matches.',
            'schema' => ['properties' => ['query' => ['type' => 'string', 'maxLength' => 80, 'description' => 'Name, email, phone or reference'], 'status' => ['type' => 'string', 'enum' => ['pending', 'confirmed', 'tentative', 'checked-in', 'checked-out', 'cancelled', 'no-show']]], 'required' => ['query']],
            'perm' => ['bookings'], 'module' => ['bookings'], 'cache' => 60, 'handler' => 'rh_ai_t_search_bookings',
        ],
        'get_booking' => [
            'description' => 'One booking in detail: guest, room, dates, party, status, total, paid and balance (same figures as the booking screen). Give booking_id or reference.',
            'schema' => ['properties' => ['booking_id' => ['type' => 'integer', 'minimum' => 1], 'reference' => ['type' => 'string', 'maxLength' => 40]]],
            'perm' => ['bookings'], 'module' => ['bookings'], 'cache' => 30, 'handler' => 'rh_ai_t_get_booking',
        ],
        'check_availability' => [
            'description' => 'Which room types are free for a stay (the same availability check the booking screens use, joined rooms included) and how many rooms of each remain.',
            'schema' => ['properties' => ['check_in' => $date, 'check_out' => $date, 'adults' => ['type' => 'integer', 'minimum' => 1, 'maximum' => 40], 'children' => ['type' => 'integer', 'minimum' => 0, 'maximum' => 20], 'room_type' => ['type' => 'string', 'maxLength' => 80, 'description' => 'Optional room type name or id']], 'required' => ['check_in', 'check_out']],
            'perm' => ['bookings', 'create_booking'], 'module' => ['bookings'], 'cache' => 60, 'handler' => 'rh_ai_t_check_availability',
        ],
        'quote_stay' => [
            'description' => 'Price a stay exactly as Create Booking would (occupancy price, joined-room rate, rate plans, child charge, VAT and levy). Read only - nothing is booked.',
            'schema' => ['properties' => ['room_type' => ['type' => 'string', 'maxLength' => 80, 'description' => 'Room type name or id'], 'check_in' => $date, 'check_out' => $date, 'adults' => ['type' => 'integer', 'minimum' => 1, 'maximum' => 40], 'children' => ['type' => 'integer', 'minimum' => 0, 'maximum' => 20], 'rooms' => ['type' => 'integer', 'minimum' => 1, 'maximum' => 10], 'occupancy' => ['type' => 'string', 'enum' => ['single', 'double', 'triple']]], 'required' => ['room_type', 'check_in', 'check_out', 'adults']],
            'perm' => ['create_booking'], 'module' => ['bookings'], 'cache' => 60, 'handler' => 'rh_ai_t_quote_stay',
        ],
        'arrivals_departures' => [
            'description' => 'Arrivals and departures for a day (default today), as on the Dashboard: who is still to arrive, who has no room assigned, who is due out and what they still owe.',
            'schema' => ['properties' => ['date' => $date]],
            'perm' => ['bookings'], 'module' => ['bookings'], 'cache' => 60, 'handler' => 'rh_ai_t_arrivals_departures',
        ],
        'in_house' => [
            'description' => 'Guests checked in right now, with room, departure date and balance still owed.',
            'schema' => ['properties' => (object)[]],
            'perm' => ['bookings'], 'module' => ['bookings'], 'cache' => 60, 'handler' => 'rh_ai_t_in_house',
        ],
        'room_board' => [
            'description' => 'Every room with its status (available, occupied, cleaning, inspection, maintenance, out of order), housekeeping state, who is in it and whether someone arrives today.',
            'schema' => ['properties' => ['status' => ['type' => 'string', 'enum' => ['available', 'occupied', 'cleaning', 'inspection', 'maintenance', 'out_of_order']]]],
            'perm' => ['room_dashboard', 'housekeeping', 'individual_rooms'], 'module' => ['bookings', 'housekeeping'], 'cache' => 45, 'handler' => 'rh_ai_t_room_board',
        ],
        'guest_history' => [
            'description' => 'Past and future stays of one guest (by name, email or phone; tokens are fine): how many stays and nights, first and last visit.',
            'schema' => ['properties' => ['guest' => ['type' => 'string', 'maxLength' => 120]], 'required' => ['guest']],
            'perm' => ['bookings'], 'module' => ['bookings'], 'cache' => 120, 'handler' => 'rh_ai_t_guest_history',
        ],
        'rate_plans_and_deals' => [
            'description' => 'Active rate plans (seasonal, length-of-stay, early-bird adjustments) and, for till users, active POS deals. Read only.',
            'schema' => ['properties' => (object)[]],
            'perm' => ['rooms', 'create_booking', 'bookings'], 'module' => ['bookings'], 'cache' => 300, 'handler' => 'rh_ai_t_rate_plans_and_deals',
        ],
        'balances_due' => [
            'description' => 'Money still owed on room bookings (folio balance, the same figure as the booking screen), largest first, with the total.',
            'schema' => ['properties' => (object)[]],
            'perm' => ['accounting', 'payments'], 'module' => ['bookings'], 'cache' => 60, 'handler' => 'rh_ai_t_balances_due',
        ],
        'how_to' => [
            'description' => 'Search the staff guides and knowledge base for how to do something in this system. Returns matching sections with links.',
            'schema' => ['properties' => ['question' => ['type' => 'string', 'maxLength' => 200]], 'required' => ['question']],
            'perm' => [], 'module' => [], 'cache' => 600, 'handler' => 'rh_ai_t_how_to',
        ],
        'summarise_guide' => [
            'description' => 'The text of one staff guide (or one section of it) so you can summarise it. Summarise ONLY from the text returned; never add steps.',
            'schema' => ['properties' => ['guide' => ['type' => 'string', 'maxLength' => 80, 'description' => 'Guide file or title, e.g. "housekeeping" or "07-reception-bookings.html"'], 'section' => ['type' => 'string', 'maxLength' => 80]], 'required' => ['guide']],
            'perm' => [], 'module' => [], 'cache' => 600, 'handler' => 'rh_ai_t_summarise_guide',
        ],
        'page_help' => [
            'description' => 'Guide sections that explain one admin screen (e.g. "bookings.php").',
            'schema' => ['properties' => ['page' => ['type' => 'string', 'maxLength' => 60]], 'required' => ['page']],
            'perm' => [], 'module' => [], 'cache' => 600, 'handler' => 'rh_ai_t_page_help',
        ],
        'who_is_online' => [
            'description' => 'Staff signed in and active in the last 30 minutes, and which screen they are on.',
            'schema' => ['properties' => (object)[]],
            'perm' => [], 'module' => [], 'gate' => 'rh_ai_gate_presence', 'cache' => 30, 'handler' => 'rh_ai_t_who_is_online',
        ],
        'my_access' => [
            'description' => 'What the person asking may see and do in this system (their permissions and the modules switched on).',
            'schema' => ['properties' => (object)[]],
            'perm' => [], 'module' => [], 'cache' => 0, 'handler' => 'rh_ai_t_my_access',
        ],
        'open_page' => [
            'description' => 'A link to an admin screen the person may open, optionally filtered (search, status, id, date). The panel shows it as a button.',
            'schema' => ['properties' => ['page' => ['type' => 'string', 'maxLength' => 60, 'description' => 'e.g. bookings.php'], 'search' => ['type' => 'string', 'maxLength' => 60], 'status' => ['type' => 'string', 'maxLength' => 30], 'id' => ['type' => 'integer', 'minimum' => 1], 'date' => $date], 'required' => ['page']],
            'perm' => [], 'module' => [], 'cache' => 0, 'handler' => 'rh_ai_t_open_page',
        ],
        'hotel_info' => [
            'description' => rh_ai_hotel_info_description(),
            'schema' => ['properties' => ['topic' => ['type' => 'string', 'enum' => ['overview', 'hours', 'restaurant_bar', 'menu', 'facilities', 'policies', 'contact', 'gym', 'conference_events'], 'description' => 'Default overview'],
                'item' => ['type' => 'string', 'maxLength' => 60, 'description' => 'Menu: one dish or drink to check, or vegetarian / vegan']]],
            'perm' => [], 'module' => [], 'cache' => 60, 'handler' => 'rh_ai_t_hotel_info', 'step' => 'Reading the ' . rh_ai_business()['noun'] . '\'s details',
        ],
        // ── Phase 3: department reads (includes/ai/ai-insights.php) ──
        'room_performance' => [
            'description' => 'Occupancy %, ADR, RevPAR, room-nights sold, cancellations and no-shows for a date range (default this month) - the same figures as Reports for the same dates.',
            'schema' => ['properties' => ['from' => $date, 'to' => $date]],
            'perm' => ['reports', 'accounting'], 'module' => ['finance'], 'gate' => 'rh_ai_gate_rooms', 'cache' => 120, 'handler' => 'rh_ai_t_room_performance', 'step' => 'Working out occupancy and room revenue',
        ],
        'booking_pace' => [
            'description' => 'Pace for coming nights (default next 30): room-nights on the books vs the same nights last year, pickup in the last 7 days, unsold nights, lead time, where bookings come from.',
            'schema' => ['properties' => ['from' => $date, 'to' => $date]],
            'perm' => ['reports', 'accounting'], 'module' => ['finance'], 'gate' => 'rh_ai_gate_rooms', 'cache' => 120, 'handler' => 'rh_ai_t_booking_pace', 'step' => 'Checking bookings on the books against last year',
        ],
        'money_leaks' => [
            'description' => 'Where money is being lost in a date range (default last 7 days): money still owed after departure, refunds paid (in full), till voids, discounts, till shortages, removed bill charges, wastage, stock missing at count, no-shows, cancellations, stock budget - each with amount, cause and who.',
            'schema' => ['properties' => ['from' => $date, 'to' => $date]],
            'perm' => ['accounting', 'reports'], 'module' => ['finance'], 'cache' => 120, 'handler' => 'rh_ai_t_money_leaks', 'step' => 'Checking money owed, refunds and voids',
        ],
        'restaurant_sales' => [
            'description' => 'Till sales for a date range (default today, at most 31 days): orders, sales, voids, refunds, top sellers and void reasons - the End of Day till figures.',
            'schema' => ['properties' => ['from' => $date, 'to' => $date]],
            'perm' => ['pos_accounting', 'reports', 'accounting'], 'module' => ['pos'], 'cache' => 120, 'handler' => 'rh_ai_t_restaurant_sales', 'step' => 'Adding up till sales and voids',
        ],
        'kitchen_tickets' => [
            'description' => 'Items waiting at the kitchen, bar and coffee stations right now, which ones are slow, and today\'s average order-to-ready time.',
            'schema' => ['properties' => (object)[]],
            'perm' => ['kds_view', 'bds_view', 'cds_view', 'kds_reports', 'pos_till', 'room_service_view'], 'module' => ['pos'], 'cache' => 30, 'handler' => 'rh_ai_t_kitchen_tickets', 'step' => 'Checking tickets at the stations',
        ],
        'inventory_watch' => [
            'description' => 'Stock: one item\'s level (item), one purchase order and what has arrived (purchase_order), or the stock dashboard alerts. Alerts: stock out, low or negative (the stock dashboard rule), batches expiring this week, wastage in a date range (default last 7 days), the last count\'s variance and counts waiting for approval. Read only.',
            'schema' => ['properties' => ['from' => $date, 'to' => $date, 'item' => ['type' => 'string', 'maxLength' => 80, 'description' => 'One stock item: its level, batches and what is on order'],
                'purchase_order' => ['type' => 'string', 'maxLength' => 80, 'description' => 'Purchase order reference or supplier name']]],
            'perm' => ['stock_dashboard', 'stock_management', 'stock_reports', 'stock_count', 'stock_wastage', 'stock_batches'], 'module' => ['stock'], 'cache' => 120, 'handler' => 'rh_ai_t_inventory_watch', 'step' => 'Checking stock levels',
        ],
        'events_pipeline' => [
            'description' => 'Conference and event bookings: one booking by reference, company or name (query), or those coming up with money owed. Coming up (default next 30 days): by status with value and money still owed, enquiries not confirmed, and event enquiries waiting.',
            'schema' => ['properties' => ['days' => ['type' => 'integer', 'minimum' => 1, 'maximum' => 180], 'query' => ['type' => 'string', 'maxLength' => 80, 'description' => 'One booking: reference, company or name']]],
            'perm' => ['conference', 'events_bookings', 'events'], 'module' => ['conference', 'events'], 'cache' => 120, 'handler' => 'rh_ai_t_events_pipeline', 'step' => 'Checking conference and event bookings',
        ],
        'gym_watch' => [
            'description' => 'Gym: one member (name, phone or number), a class or time slot on a date (who is booked, spaces left), or the member counts. One member: package, status, dates, days left, balance, check-ins, classes. With no arguments: the Members screen counts, memberships ending in the next 30 days and gym enquiries waiting.',
            'schema' => ['properties' => ['member' => ['type' => 'string', 'maxLength' => 80, 'description' => 'Member name, phone or member number'], 'class' => ['type' => 'string', 'maxLength' => 60, 'description' => 'Class name'],
                'date' => $date, 'time' => ['type' => 'string', 'maxLength' => 10, 'description' => 'Slot or class time as HH:MM']]],
            'perm' => ['gym'], 'module' => ['gym'], 'cache' => 30, 'handler' => 'rh_ai_t_gym_watch', 'step' => 'Checking gym memberships',
        ],
        'guest_voice' => [
            'description' => 'Reviews waiting for approval or a reply, low ratings, and website / event / gym / conference enquiries waiting for an answer.',
            'schema' => ['properties' => (object)[]],
            'perm' => ['reviews', 'contact', 'events_bookings', 'gym', 'conference'], 'module' => [], 'cache' => 60, 'handler' => 'rh_ai_t_guest_voice', 'step' => 'Checking reviews and enquiries',
        ],
        'work_queues' => [
            'description' => 'Housekeeping and maintenance: one room\'s status and jobs (room), or all open jobs and who has them. Open jobs: overdue, due today, urgent, blocked or keeping a room off sale.',
            'schema' => ['properties' => ['room' => ['type' => 'string', 'maxLength' => 30, 'description' => 'One room number']]],
            'perm' => ['housekeeping', 'room_maintenance'], 'module' => ['housekeeping', 'bookings'], 'cache' => 45, 'handler' => 'rh_ai_t_work_queues', 'step' => 'Checking housekeeping and maintenance jobs',
        ],
        'daily_briefing' => [
            'description' => 'This person\'s ranked list of what needs attention today (misses, money leaks, queues, decisions), worked out from the hotel\'s data.',
            'schema' => ['properties' => (object)[]],
            'perm' => [], 'module' => [], 'cache' => 60, 'handler' => 'rh_ai_t_daily_briefing', 'step' => 'Going through today',
        ],
    ];
    return array_merge($reg, rh_ai_extra_tools(), rh_ai_lookup_tools()); // Phases 4c + 5 extras (read only) + one-record lookups (read only)
}

/** The plain status line shown while a tool runs ("Checking today's departures..."). */
function rh_ai_tool_step(string $name): string
{
    $steps = [
        'search_bookings' => 'Looking up bookings', 'get_booking' => 'Opening the booking', 'check_availability' => 'Checking which rooms are free',
        'quote_stay' => 'Pricing the stay', 'arrivals_departures' => 'Checking today\'s arrivals and departures', 'in_house' => 'Checking who is in house',
        'room_board' => 'Checking room status', 'guest_history' => 'Looking up past stays', 'rate_plans_and_deals' => 'Reading rate plans',
        'balances_due' => 'Checking balances owed', 'how_to' => 'Searching the staff guides', 'summarise_guide' => 'Reading the guide',
        'page_help' => 'Reading the screen guide', 'who_is_online' => 'Checking who is online', 'my_access' => 'Checking your access', 'open_page' => 'Finding the screen',
    ];
    $def = rh_ai_tool_registry()[$name] ?? [];
    return ($steps[$name] ?? (string)($def['step'] ?? 'Looking that up')) . '...';
}

/** Who-is-online is for administrators and managers only (same rule as the dashboard panel). */
function rh_ai_gate_presence(array $user): bool
{
    if (!function_exists('rh_presence_can_view')) {
        require_once dirname(__DIR__, 2) . '/admin/includes/presence-lib.php';
    }
    return rh_presence_can_view((string)($user['role'] ?? ''));
}

/** Room-only reads (occupancy, pace): offered only where room bookings are switched on. */
function rh_ai_gate_rooms(array $user): bool
{
    return rh_ai_module_on('bookings');
}

/**
 * hotel_info's description, worded for the business type (owner 2026-10-28): only the parts offered here are named, so
 * a gym or a shop never hears about rooms or check-in. Built from module switches only (cache-stable).
 */
function rh_ai_hotel_info_description(): string
{
    $b = rh_ai_business();
    $hours = array_keys(array_filter(['check-in/out' => $b['on']['bookings'] === 1, 'restaurant kitchen' => rh_ai_station_on('kitchen'), 'bar' => rh_ai_station_on('bar'),
        'coffee bar' => rh_ai_station_on('coffee_bar'), 'gym' => $b['on']['gym'] === 1]));
    $parts = array_keys(array_filter(['restaurant and bar' => $b['on']['restaurant_page'] === 1, 'menu (or one item: is it available, what else to offer)' => rh_ai_restaurant_on(), 'facilities, policies, contact details' => true,
        'conference rooms' => $b['on']['conference'] === 1, 'upcoming events' => $b['on']['events_page'] === 1]));
    return 'The ' . $b['noun'] . '\'s own facts: opening hours' . ($hours ? ' (' . implode(', ', $hours) . ')' : '') . ' and what is open now, ' . rh_ai_and_list($parts)
        . '. Parts not offered here are left out; a time that is not set is said to be not set.';
}

/** May this person use this tool right now? Permission (any-of) + module (any-of) + gate. */
function rh_ai_tool_allowed(array $user, string $name, ?array $def = null): bool
{
    $def = $def ?? (rh_ai_tool_registry()[$name] ?? null);
    $uid = (int)($user['id'] ?? 0);
    if ($def === null || $uid <= 0 || !function_exists('hasPermission') || !rh_ai_has_perm($uid, 'ai_assistant_use')) {
        return false;
    }
    if ($def['perm']) {
        $ok = false;
        foreach ($def['perm'] as $p) {
            if (rh_ai_has_perm($uid, $p)) {
                $ok = true;
                break;
            }
        }
        if (!$ok) {
            return false;
        }
    }
    if ($def['module']) {
        $ok = false;
        foreach ($def['module'] as $m) {
            if (rh_ai_module_on($m)) {
                $ok = true;
                break;
            }
        }
        if (!$ok) {
            return false;
        }
    }
    if (!empty($def['gate']) && !call_user_func($def['gate'], $user)) {
        return false;
    }
    return true;
}

/** The tools this person may use, sorted by name (deterministic for prompt caching). */
function rh_ai_tools_for_user(array $user): array
{
    $uid = (int)($user['id'] ?? 0);
    if (isset($GLOBALS['__rh_ai_memo']['tools'][$uid])) {
        return $GLOBALS['__rh_ai_memo']['tools'][$uid];
    }
    $out = [];
    foreach (rh_ai_tool_registry() as $name => $def) {
        if (rh_ai_tool_allowed($user, $name, $def)) {
            $out[$name] = $def;
        }
    }
    ksort($out);
    if (isset($GLOBALS['__rh_ai_memo']['tools'])) {
        $GLOBALS['__rh_ai_memo']['tools'][$uid] = $out;
    }
    return $out;
}

/**
 * A tool spec as sent to the model in a chat turn: the first sentence of its description (an action card keeps
 * two) and short argument notes. The rules behind the long wording live once in the system prompt.
 */
function rh_ai_spec_compact(array $sp): array
{
    $d = trim((string)($sp['description'] ?? ''));
    $isCard = strpos((string)($sp['name'] ?? ''), 'propose_') === 0;
    if (preg_match($isCard ? '/^(.{20,}?[.!?](?:\s.{10,}?[.!?])?)(\s|$)/us' : '/^(.{20,}?[.!?])(\s|$)/us', $d, $m)) {
        $d = $m[1];
    }
    $cap = $isCard ? 220 : 130;
    if (mb_strlen($d) > $cap) {
        $d = mb_substr($d, 0, $cap - 3) . '...';
    }
    $sp['description'] = $d;
    $sp['schema'] = rh_ai_schema_compact((array)($sp['schema'] ?? []));
    return $sp;
}

/** Argument notes in a schema cut to 60 characters (types, enums and limits stay). */
function rh_ai_schema_compact(array $s): array
{
    foreach ($s as $k => $v) {
        if ($k === 'description' && is_string($v) && mb_strlen($v) > 60) {
            $s[$k] = mb_substr($v, 0, 57) . '...';
        } elseif (is_array($v)) {
            $s[$k] = rh_ai_schema_compact($v);
        }
    }
    return $s;
}

/**
 * What one tool result sends to the model (owner 2026-10-28: compact facts, nothing the answer needs is cut): empty
 * values (null, "", []) are left out and figures rounded to 2 places; every row and every word stays. The full
 * result also stays local as the evidence the reply-check uses.
 */
function rh_ai_model_view($data, string $tool = ''): string
{
    $trim = static function ($v) use (&$trim) {
        if (is_array($v)) {
            $isList = $v === [] || array_keys($v) === range(0, count($v) - 1);
            $out = [];
            foreach ($v as $k => $x) {
                $x = $trim($x);
                if (!$isList && ($x === null || $x === '' || $x === [])) {
                    continue;
                }
                $out[$k] = $x;
            }
            return $isList ? array_values($out) : $out;
        }
        if (is_float($v)) {
            return round($v, 2);
        }
        return $v;
    };
    return rh_ai_compact_json(is_array($data) ? $trim($data) : $data);
}

/**
 * Owner 2026-10-28: "$5 or less a month regardless of hotel db size". What a lookup sends the model must not grow with
 * the number of bookings, guests, rooms or stock items: every list is the top ai_list_page_rows rows of the page asked for,
 * followed by "and N more" (the model can ask for the next page or a narrower filter), and one free-text field is at
 * most RH_AI_TEXT_CHARS characters. Counts and totals are untouched. Guide text is fixed-size already and is left whole.
 */
function rh_ai_bound_result($data, string $tool = '', int $page = 1)
{
    $guide = in_array($tool, ['summarise_guide', 'how_to', 'page_help'], true);
    $page = max(1, $page);
    $top = rh_ai_num('ai_list_page_rows'); // rows per page (a setting; never a number written here)
    $walk = static function ($v) use (&$walk, $guide, $page, $top) {
        if (is_array($v)) {
            $isList = $v !== [] && array_keys($v) === range(0, count($v) - 1);
            if ($isList && count($v) > $top) {
                $all = count($v);
                $from = ($page - 1) * $top;
                $v = array_slice($v, $from, $top);
                $after = max(0, $all - $from - count($v));
                $v = array_map($walk, $v);
                $v[] = $after > 0 ? 'and ' . $after . ' more (ask for page ' . ($page + 1) . ' or a narrower filter)' : 'end of list (' . $all . ' in all)';
                return $v;
            }
            return array_map($walk, $v);
        }
        if (!$guide && is_string($v) && mb_strlen($v) > RH_AI_TEXT_CHARS) {
            return mb_substr($v, 0, RH_AI_TEXT_CHARS - 3) . '...';
        }
        return $v;
    };
    return $walk($data);
}

/** Lookups whose answer is a list long enough to page through ("page": 2 gives the next ai_list_page_rows rows). */
function rh_ai_paged_tools(): array
{
    return ['search_bookings', 'arrivals_departures', 'in_house', 'room_board', 'balances_due', 'guest_history', 'money_leaks', 'inventory_watch',
        'work_queues', 'events_pipeline', 'guest_voice', 'reply_queue', 'kitchen_tickets', 'restaurant_sales', 'upsell_finder', 'who_is_online', 'check_availability', 'hotel_info',
        'order_status', 'gym_watch'];
}

/** Provider-ready tool list [{name, description, schema}] for this person. */
function rh_ai_tool_specs(array $user): array
{
    $specs = [];
    foreach (rh_ai_tools_for_user($user) as $name => $def) {
        $schema = $def['schema'];
        if (isset($schema['properties']) && is_object($schema['properties'])) {
            $schema['properties'] = [];
        }
        if (in_array($name, rh_ai_paged_tools(), true)) {
            $schema['properties']['page'] = ['type' => 'integer', 'minimum' => 1, 'maximum' => 20, 'description' => 'Next ' . rh_ai_num('ai_list_page_rows') . ' of a long list'];
        }
        $specs[] = ['name' => $name, 'description' => $def['description'], 'schema' => $schema];
    }
    return $specs;
}

/**
 * Validate arguments against a tool schema: unknown keys dropped, types coerced where safe.
 * @return array{0:bool,1:array|string} [ok, args or plain error]
 */
function rh_ai_tool_validate(array $schema, array $args): array
{
    $props = (array)($schema['properties'] ?? []);
    $clean = [];
    foreach ($props as $k => $p) {
        if (!array_key_exists($k, $args) || $args[$k] === null || $args[$k] === '') {
            continue;
        }
        $v = $args[$k];
        $type = (string)($p['type'] ?? 'string');
        if ($type === 'integer') {
            if (!is_numeric($v) || (int)$v != $v) {
                return [false, "$k must be a whole number"];
            }
            $v = (int)$v;
            if (isset($p['minimum']) && $v < $p['minimum']) {
                return [false, "$k is too small"];
            }
            if (isset($p['maximum']) && $v > $p['maximum']) {
                return [false, "$k is too large"];
            }
        } elseif ($type === 'number') {
            if (!is_numeric($v)) {
                return [false, "$k must be a number"];
            }
            $v = (float)$v;
        } elseif ($type === 'boolean') {
            $v = (bool)$v;
        } else {
            if (is_array($v)) {
                return [false, "$k must be text"];
            }
            $v = trim((string)$v);
            if (isset($p['maxLength']) && mb_strlen($v) > (int)$p['maxLength']) {
                $v = mb_substr($v, 0, (int)$p['maxLength']);
            }
            if (isset($p['enum']) && !in_array($v, $p['enum'], true)) {
                return [false, "$k must be one of: " . implode(', ', $p['enum'])];
            }
            if (($p['format'] ?? '') === 'date') {
                $d = DateTime::createFromFormat('!Y-m-d', $v);
                if (!$d || $d->format('Y-m-d') !== $v) {
                    return [false, "$k must be a date like 2026-11-03"];
                }
            }
        }
        $clean[$k] = $v;
    }
    foreach ((array)($schema['required'] ?? []) as $r) {
        if (!array_key_exists($r, $clean)) {
            return [false, "$r is needed"];
        }
    }
    return [true, $clean];
}

/** JSON for the model, within the size cap (long lists are shortened and say so). */
function rh_ai_compact_json($data, int $max = RH_AI_TOOL_BYTES): string
{
    $flags = JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PARTIAL_OUTPUT_ON_ERROR;
    $j = (string)json_encode($data, $flags);
    $guard = 0;
    while (strlen($j) > $max && is_array($data) && $guard++ < 8) {
        $shrunk = false;
        foreach ($data as $k => $v) {
            if (is_array($v) && array_keys($v) === range(0, count($v) - 1) && count($v) > 2) {
                $data[$k] = array_slice($v, 0, max(2, (int)floor(count($v) / 2)));
                $data['shortened'] = true;
                $shrunk = true;
            }
        }
        if (!$shrunk) {
            break;
        }
        $j = (string)json_encode($data, $flags);
    }
    if (strlen($j) > $max) {
        $j = (string)json_encode(['shortened' => true, 'text' => mb_strcut($j, 0, $max - 60)], $flags);
    }
    return $j;
}

/** Cache key per person + permission set (the tool list they hold) + tool + arguments + day. */
function rh_ai_cache_key(array $user, string $scope, $payload): string
{
    $perms = implode(',', array_keys(rh_ai_tools_for_user($user)));
    return hash('sha256', (int)$user['id'] . '|' . hash('sha256', $perms) . '|' . $scope . '|' . json_encode($payload) . '|' . date('Y-m-d'));
}

function rh_ai_cache_get(PDO $db, string $key): ?array
{
    try {
        $st = $db->prepare("SELECT result_json FROM ai_tool_cache WHERE cache_key = ? AND expires_at > NOW()");
        $st->execute([$key]);
        $v = $st->fetchColumn();
        if ($v === false) {
            return null;
        }
        $d = json_decode((string)$v, true);
        return is_array($d) ? $d : null;
    } catch (Throwable $e) {
        return null;
    }
}

function rh_ai_cache_put(PDO $db, string $key, int $userId, array $value, int $ttl): void
{
    if ($ttl <= 0) {
        return;
    }
    try {
        $db->prepare("INSERT INTO ai_tool_cache (cache_key, user_id, result_json, expires_at) VALUES (?, ?, ?, DATE_ADD(NOW(), INTERVAL ? SECOND))
                      ON DUPLICATE KEY UPDATE result_json = VALUES(result_json), expires_at = VALUES(expires_at)")
            ->execute([$key, $userId, json_encode($value, JSON_UNESCAPED_UNICODE | JSON_PARTIAL_OUTPUT_ON_ERROR), $ttl]);
        if (random_int(1, 50) === 1) {
            $db->exec("DELETE FROM ai_tool_cache WHERE expires_at < NOW() LIMIT 200");
        }
    } catch (Throwable $e) {
        // cache is optional
    }
}

/**
 * Run one tool for this person. Returns ['ok', 'result' (de-identified array), 'json' (what the
 * model sees), 'raw' (real values, local use only), 'error', 'cached', 'links'].
 */
function rh_ai_run_tool(PDO $db, array $user, int $conv, string $name, array $args): array
{
    $def = rh_ai_tool_registry()[$name] ?? null;
    $fail = static function (string $msg) use ($db, $conv): array {
        $res = ['error' => $msg];
        return ['ok' => false, 'result' => $res, 'json' => rh_ai_compact_json($res), 'view' => rh_ai_compact_json($res), 'raw' => $res, 'error' => $msg, 'cached' => false, 'links' => []];
    };
    if ($def === null) {
        return $fail('There is no tool called ' . mb_substr($name, 0, 40) . '.');
    }
    if (!rh_ai_tool_allowed($user, $name, $def)) {
        return $fail("That's outside what this account can see.");
    }
    $page = max(1, min(20, (int)($args['page'] ?? 1))); // which 10 rows of a long list the model sees (not part of the lookup)
    unset($args['page']);
    $args = rh_ai_vault_resolve_args($db, $conv, $args);
    [$ok, $clean] = rh_ai_tool_validate($def['schema'], $args);
    if (!$ok) {
        return $fail('Arguments not valid: ' . $clean);
    }
    $cacheKey = rh_ai_cache_key($user, 'tool:' . $name, $clean);
    $raw = ((int)$def['cache'] > 0) ? rh_ai_cache_get($db, $cacheKey) : null;
    $cached = $raw !== null;
    if ($raw === null) {
        // Circuit breaker: after repeated errors this lookup is off for an hour (then it gets one try).
        if (rh_ai_breaker_is_open($db, $name)) {
            return $fail('That information cannot be reached just now (this lookup is paused after repeated errors and the team has been told). Offer the screen instead.');
        }
        $handler = $GLOBALS['__rh_ai_tool_override'][$name] ?? $def['handler']; // tests only: force an error
        try {
            $raw = (array)call_user_func($handler, $db, $user, $clean);
        } catch (Throwable $e) {
            error_log('Assistant tool ' . $name . ' failed: ' . $e->getMessage());
            rh_ai_breaker_fail($db, $name, $e->getMessage(), ['question' => (string)($GLOBALS['__rh_ai_current_q'] ?? '')]);
            return $fail('That information could not be read just now.');
        }
        rh_ai_breaker_ok($db, $name);
        if (empty($raw['error'])) {
            rh_ai_cache_put($db, $cacheKey, (int)$user['id'], $raw, (int)$def['cache']);
        }
    }
    $links = [];
    $forModel = $raw;
    if (!empty($raw['link']) && is_string($raw['link'])) {
        $links[] = ['label' => (string)($raw['link_label'] ?? 'Open'), 'url' => $raw['link']];
        $forModel['link_ready'] = true; // the panel shows the button; the URL (which may hold a search term) stays here
    }
    unset($forModel['link'], $forModel['link_label'], $forModel['url']);
    $safe = rh_ai_vault_protect($db, $conv, $forModel);
    $bounded = rh_ai_bound_result($safe, $name, $page); // fixed size whatever the size of the hotel's data
    return ['ok' => empty($raw['error']), 'result' => $safe, 'json' => rh_ai_compact_json($bounded), 'view' => rh_ai_model_view($bounded, $name), 'raw' => $raw, 'error' => (string)($raw['error'] ?? ''), 'cached' => $cached, 'links' => $links];
}

// ── Helpers shared by handlers ───────────────────────────────────────────────

/** The room library behind the dashboard figures (getRoomDashboardSummary), loaded on demand. */
function rh_ai_room_lib(): bool
{
    if (!function_exists('getRoomDashboardSummary')) {
        require_once dirname(__DIR__) . '/room-management.php';
    }
    return function_exists('getRoomDashboardSummary');
}

function rh_ai_today(): string
{
    return date('Y-m-d');
}

function rh_ai_currency(): string
{
    return function_exists('getSetting') ? (string)getSetting('currency_symbol', getSetting('currency_code', '')) : '';
}

function rh_ai_money(float $v): float
{
    return round($v, 2);
}

/** Folio balance for one booking, the same helper the booking screens use. */
function rh_ai_folio(int $bookingId): array
{
    if (!function_exists('getBookingFolioSummary')) {
        return [];
    }
    $f = getBookingFolioSummary($bookingId);
    if (!empty($f['error'])) {
        return [];
    }
    return ['total' => rh_ai_money((float)($f['grand_total'] ?? 0)), 'paid' => rh_ai_money((float)($f['amount_paid'] ?? 0)), 'balance_due' => rh_ai_money((float)($f['balance_due'] ?? 0))];
}

/** Room type by id or (part of) its name. */
function rh_ai_find_room_type(PDO $db, string $q): ?array
{
    $q = trim($q);
    if ($q === '') {
        return null;
    }
    if (ctype_digit($q)) {
        $st = $db->prepare("SELECT * FROM rooms WHERE id = ? AND is_active = 1");
        $st->execute([(int)$q]);
        $r = $st->fetch(PDO::FETCH_ASSOC);
        return $r ?: null;
    }
    $st = $db->prepare("SELECT * FROM rooms WHERE is_active = 1 AND (name = ? OR slug = ?) LIMIT 1");
    $st->execute([$q, $q]);
    $r = $st->fetch(PDO::FETCH_ASSOC);
    if ($r) {
        return $r;
    }
    $st = $db->prepare("SELECT * FROM rooms WHERE is_active = 1 AND name LIKE ? ORDER BY display_order LIMIT 1");
    $st->execute(['%' . $q . '%']);
    $r = $st->fetch(PDO::FETCH_ASSOC);
    return $r ?: null;
}

function rh_ai_nights(string $ci, string $co): int
{
    return (int)(new DateTime($ci))->diff(new DateTime($co))->days;
}

// ── Handlers (read only) ─────────────────────────────────────────────────────

function rh_ai_t_search_bookings(PDO $db, array $user, array $a): array
{
    $like = '%' . $a['query'] . '%';
    $sql = "SELECT b.id, b.booking_reference, b.guest_name, b.check_in_date, b.check_out_date, b.status, r.name AS room_name
            FROM bookings b LEFT JOIN rooms r ON r.id = b.room_id
            WHERE b.deleted_at IS NULL AND (b.booking_reference LIKE ? OR b.guest_name LIKE ? OR b.room_guest_name LIKE ? OR b.guest_email LIKE ? OR b.guest_phone LIKE ?)";
    $p = [$like, $like, $like, $like, $like];
    if (!empty($a['status'])) {
        $sql .= " AND b.status = ?";
        $p[] = $a['status'];
    }
    $st = $db->prepare($sql . " ORDER BY b.id DESC LIMIT 10");
    $st->execute($p);
    $rows = [];
    foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) {
        $rows[] = ['booking_id' => (int)$r['id'], 'reference' => $r['booking_reference'], 'guest_name' => $r['guest_name'], 'room_type' => $r['room_name'], 'check_in' => $r['check_in_date'], 'check_out' => $r['check_out_date'], 'status' => $r['status']];
    }
    return ['count' => count($rows), 'bookings' => $rows, 'link' => 'bookings.php?search=' . rawurlencode((string)$a['query']), 'link_label' => 'Open on Bookings'];
}

function rh_ai_t_get_booking(PDO $db, array $user, array $a): array
{
    if (empty($a['booking_id']) && empty($a['reference'])) {
        return ['error' => 'Give a booking id or reference.'];
    }
    $st = $db->prepare("SELECT b.*, r.name AS room_name, ir.room_number, ir.room_name AS individual_room_name
                        FROM bookings b LEFT JOIN rooms r ON r.id = b.room_id LEFT JOIN individual_rooms ir ON ir.id = b.individual_room_id
                        WHERE b.deleted_at IS NULL AND " . (!empty($a['booking_id']) ? "b.id = ?" : "b.booking_reference = ?") . " LIMIT 1");
    $st->execute([!empty($a['booking_id']) ? (int)$a['booking_id'] : (string)$a['reference']]);
    $b = $st->fetch(PDO::FETCH_ASSOC);
    if (!$b) {
        return ['error' => 'No booking found.'];
    }
    $out = [
        'booking_id' => (int)$b['id'], 'reference' => $b['booking_reference'], 'guest_name' => $b['guest_name'],
        'guest_email' => $b['guest_email'], 'guest_phone' => $b['guest_phone'], 'guest_country' => $b['guest_country'],
        'room_type' => $b['room_name'], 'room_number' => $b['room_number'] !== null ? (string)$b['room_number'] : null,
        'check_in' => $b['check_in_date'], 'check_out' => $b['check_out_date'], 'nights' => (int)$b['number_of_nights'],
        'adults' => (int)$b['adult_guests'], 'children' => (int)$b['child_guests'], 'status' => $b['status'],
        'occupancy' => $b['occupancy_type'], 'rate_plan' => $b['rate_plan_label'] ?: null,
        'special_requests' => (string)($b['special_requests'] ?? ''),
        'currency' => rh_ai_currency(),
        'money' => rh_ai_folio((int)$b['id']),
        'link' => 'booking-details.php?id=' . (int)$b['id'], 'link_label' => 'Open booking',
    ];
    if ($out['special_requests'] === '') {
        unset($out['special_requests']);
    }
    return $out;
}

function rh_ai_t_check_availability(PDO $db, array $user, array $a): array
{
    if ($a['check_out'] <= $a['check_in']) {
        return ['error' => 'Check-out must be after check-in.'];
    }
    $adults = (int)($a['adults'] ?? 1);
    $children = (int)($a['children'] ?? 0);
    $types = [];
    if (!empty($a['room_type'])) {
        $t = rh_ai_find_room_type($db, (string)$a['room_type']);
        if (!$t) {
            return ['error' => 'No room type matches "' . $a['room_type'] . '".',
                'alternatives' => rh_ai_room_alternatives($db, $user, null, (string)$a['check_in'], (string)$a['check_out'], $adults, $children, 1)];
        }
        $types[] = $t;
    } else {
        $types = $db->query("SELECT * FROM rooms WHERE is_active = 1 ORDER BY display_order, id")->fetchAll(PDO::FETCH_ASSOC);
    }
    $rows = [];
    $anyFree = false;
    foreach ($types as $t) {
        $av = checkRoomAvailability((int)$t['id'], $a['check_in'], $a['check_out'], null, $children, 1); // one room per type checked
        $fits = (int)$t['max_guests'] >= ($adults + $children);
        $anyFree = $anyFree || (!empty($av['available']) && $fits);
        $rows[] = [
            'room_type_id' => (int)$t['id'], 'room_type' => $t['name'],
            'available' => !empty($av['available']),
            'rooms_left' => isset($av['remaining_rooms']) ? (int)$av['remaining_rooms'] : null,
            'max_guests' => (int)$t['max_guests'],
            'fits_party_in_one_room' => $fits,
            'reason' => empty($av['available']) ? (string)($av['error'] ?? 'Not available') : null,
        ];
    }
    $out = ['check_in' => $a['check_in'], 'check_out' => $a['check_out'], 'nights' => rh_ai_nights($a['check_in'], $a['check_out']), 'room_types' => $rows,
        'link' => 'create-booking.php', 'link_label' => 'Open Create Booking'];
    if (!$anyFree && $rows) { // the type asked (or every type) has nothing for this party: what is free instead
        $out['alternatives'] = rh_ai_room_alternatives($db, $user, !empty($a['room_type']) ? (int)$types[0]['id'] : null, (string)$a['check_in'], (string)$a['check_out'], $adults, $children, 1);
    }
    return $out;
}

/**
 * Owner 2026-10-28 ("offer suggestions on what actually is available"): when the room type or dates asked have nothing,
 * what IS free, worked out here with no extra model call - (a) other room types that take the party on the same dates,
 * (b) the nearest dates, ai_alt_days either side, same length of stay, for the type asked (no type asked: the first type
 * that takes the party, per date), (c) joined (family) rooms when the party is too big for one ordinary room.
 * Bounded work, whatever the size of the hotel: candidates are found with one pass per room type over the whole date
 * window (getBookedDatesForRoom - the public calendar's per-night count - and getRoomTypeBlockedDatesOnly, the rules
 * checkRoomAvailability uses); only the options shown are then priced and confirmed by quote_stay itself (the Create
 * Booking helpers: checkRoomAvailability, the party split, joined-room combinations, rate plans, VAT, levy), at most
 * ai_alt_tries of them, so nothing here is new pricing or availability maths. At most ai_alt_max per kind. Prices only for
 * people who may price a stay. Nothing free: one plain line, never an invented option.
 */
function rh_ai_room_alternatives(PDO $db, array $user, ?int $askedTypeId, string $ci, string $co, int $adults, int $children, int $rooms = 1): array
{
    $adults = max(1, $adults);
    $party = $adults + max(0, $children);
    $rooms = max(1, $rooms);
    $nights = max(1, rh_ai_nights($ci, $co));
    $priced = rh_ai_tool_allowed($user, 'quote_stay');
    $max = rh_ai_num('ai_alt_max');
    $days = rh_ai_num('ai_alt_days');
    $tries = rh_ai_num('ai_alt_tries');
    $none = ['none' => 'Nothing is free for this party on these dates or within ' . $days . ' day' . ($days === 1 ? '' : 's') . ' either side.'];
    try {
        $types = $db->query("SELECT r.id, r.name, GREATEST(r.max_guests,
                                 COALESCE((SELECT MAX(ir.max_guests_override) FROM individual_rooms ir WHERE ir.room_type_id = r.id AND ir.is_active = 1), 0),
                                 COALESCE((SELECT MAX(rc.max_guests_combined) FROM room_combinations rc WHERE rc.combined_room_type_id = r.id AND rc.is_active = 1), 0)) AS cap,
                                 (SELECT COUNT(*) FROM room_combinations rc2 WHERE rc2.combined_room_type_id = r.id AND rc2.is_active = 1) AS joined
                             FROM rooms r WHERE r.is_active = 1 ORDER BY r.display_order, r.id")->fetchAll(PDO::FETCH_ASSOC);
    } catch (Throwable $e) {
        return ['none' => 'Other options could not be checked just now.'];
    }
    $maxOrdinary = 0;
    $hasOrdinary = false;
    foreach ($types as $t) {
        if ((int)$t['joined'] === 0) {
            $hasOrdinary = true;
            $maxOrdinary = max($maxOrdinary, (int)$t['cap']);
        }
    }
    // No ordinary room (or rooms) takes this party - or the hotel sells only joined rooms - so joined rooms are offered.
    $tooBig = !$hasOrdinary || $party > $maxOrdinary * $rooms;
    $takes = static fn(array $t): bool => (int)$t['joined'] > 0 ? ($tooBig && (int)$t['cap'] >= $party) : (int)$t['cap'] * $rooms >= $party;

    // One pass per room type over the whole window: the nights it cannot sell (fully booked, or blocked for the type / all rooms).
    $today = rh_ai_today();
    $winFrom = max($today, date('Y-m-d', strtotime($ci . ' -' . $days . ' day')));
    $winTo = date('Y-m-d', strtotime($co . ' +' . $days . ' day'));
    $closed = [];
    $freeFor = static function (array $t, string $from, string $to) use (&$closed, $winFrom, $winTo): bool {
        if ((int)$t['joined'] > 0) {
            return true; // joined pairs span other types' rooms: quote_stay (checkRoomAvailability) decides
        }
        $id = (int)$t['id'];
        if (!isset($closed[$id])) {
            $closed[$id] = [];
            try {
                foreach (getBookedDatesForRoom($id, $winFrom, $winTo) as $n) {
                    $closed[$id][substr((string)$n, 0, 10)] = true;
                }
                foreach (getRoomTypeBlockedDatesOnly($id, $winFrom, $winTo) as $b) {
                    $closed[$id][substr((string)$b['block_date'], 0, 10)] = true;
                }
            } catch (Throwable $e) {
                // the priced check below still decides
            }
        }
        for ($d = $from; $d < $to; $d = date('Y-m-d', strtotime($d . ' +1 day'))) {
            if (isset($closed[$id][$d])) {
                return false;
            }
        }
        return true;
    };

    // (a) + (c): other types on the same dates
    $typeCands = [];
    foreach ($types as $t) {
        if ((int)$t['id'] !== $askedTypeId && $takes($t) && $freeFor($t, $ci, $co)) {
            $typeCands[] = $t;
        }
    }
    // (b): the nearest dates, nearest first (+1, -1, +2, -2 ...); one type per date
    $dateTypes = [];
    foreach ($types as $t) {
        $fitsAsked = (int)$t['cap'] * ((int)$t['joined'] > 0 ? 1 : $rooms) >= $party;
        if ($askedTypeId !== null ? ((int)$t['id'] === $askedTypeId && $fitsAsked) : (!$typeCands && $takes($t))) {
            $dateTypes[] = $t;
        }
    }
    $dateCands = [];
    for ($d = 1; $d <= $days && $dateTypes; $d++) {
        foreach ([$d, -$d] as $shift) {
            $from = date('Y-m-d', strtotime($ci . ' ' . ($shift > 0 ? '+' : '') . $shift . ' day'));
            if ($from < $today) {
                continue;
            }
            $to = date('Y-m-d', strtotime($from . ' +' . $nights . ' day'));
            foreach ($dateTypes as $t) {
                if ($freeFor($t, $from, $to)) {
                    $dateCands[] = [$t, $from, $to];
                    break; // the first type that is free that date
                }
            }
        }
    }
    if (!$typeCands && !$dateCands) {
        return $none;
    }

    // Price and confirm only what will be shown: types and dates in turn, at most ai_alt_tries quote_stay runs.
    $row = static function (array $q, bool $withDates) use ($priced): array {
        $r = ['room_type' => (string)$q['room_type']];
        if ($withDates) {
            $r['check_in'] = (string)$q['check_in'];
            $r['check_out'] = (string)$q['check_out'];
        }
        if ($priced) {
            $r['price_per_night'] = $q['price_per_night'];
            $r['total'] = $q['total'];
            $r['currency'] = $q['currency'];
        }
        return $r;
    };
    $other = [];
    $joined = [];
    $dates = [];
    $used = 0;
    $ti = 0;
    $di = 0;
    while ($used < $tries && ($ti < count($typeCands) || $di < count($dateCands))) {
        $wantType = $ti < count($typeCands) && count($other) + count($joined) < $max;
        $wantDate = $di < count($dateCands) && count($dates) < $max;
        if (!$wantType && !$wantDate) {
            break;
        }
        $isType = $wantType && (!$wantDate || $used % 2 === 0);
        if ($isType) {
            $t = $typeCands[$ti++];
            [$from, $to] = [$ci, $co];
        } else {
            [$t, $from, $to] = $dateCands[$di++];
        }
        $used++;
        $GLOBALS['__rh_ai_alt_quotes'] = ($GLOBALS['__rh_ai_alt_quotes'] ?? 0) + 1; // counted by the tests
        $q = rh_ai_t_quote_stay($db, $user, ['room_type' => (string)$t['id'], 'check_in' => $from, 'check_out' => $to, 'adults' => $adults, 'children' => $children, 'rooms' => $rooms, '_alt' => false]);
        if (!empty($q['error']) || empty($q['available'])) {
            continue;
        }
        if (!$isType) {
            $dates[] = $row($q, true);
        } elseif ((int)$t['joined'] > 0) {
            $joined[] = $row($q, false) + ['note' => 'two joined rooms sold as one family room, up to ' . (int)$t['cap'] . ' guests'];
        } else {
            $other[] = $row($q, false);
        }
    }
    $out = [];
    if ($other) {
        $out['other_room_types'] = $other;
    }
    if ($joined) {
        $out['joined_rooms'] = $joined;
    }
    if ($dates) {
        $out['other_dates'] = $dates;
    }
    return $out ?: $none;
}

/**
 * Price a stay with the same helpers, in the same order, as admin/create-booking.php (one room line):
 * occupancy price (joined-room combination rate when the type is a joined pair) -> applyDynamicPricing
 * -> x nights + rh_child_supplement for the children seated in that room -> rh_stay_totals('price').
 */
function rh_ai_t_quote_stay(PDO $db, array $user, array $a): array
{
    $q = rh_ai_quote_stay_run($db, $user, $a);
    $why = $q['_alt'] ?? null;
    unset($q['_alt']);
    if (($a['_alt'] ?? true) === false) {
        return $q; // an alternative being priced: never a search inside a search
    }
    if ($why === null && empty($q['error']) && isset($q['available']) && !$q['available']) {
        $why = (int)$q['room_type_id'];
    }
    if ($why !== null) {
        $q['alternatives'] = rh_ai_room_alternatives($db, $user, $why > 0 ? $why : null, (string)$a['check_in'], (string)$a['check_out'],
            (int)$a['adults'], (int)($a['children'] ?? 0), max(1, (int)($a['rooms'] ?? 1)));
    }
    return $q;
}

/** quote_stay itself. A result it cannot give for the type asked carries '_alt' => the type id (0 = no such type) so alternatives are looked for. */
function rh_ai_quote_stay_run(PDO $db, array $user, array $a): array
{
    foreach (['/includes/pricing.php', '/includes/guest-allocation.php'] as $f) {
        require_once dirname(__DIR__, 2) . $f;
    }
    $ci = (string)$a['check_in'];
    $co = (string)$a['check_out'];
    if ($co <= $ci) {
        return ['error' => 'Check-out must be after check-in.'];
    }
    $t = rh_ai_find_room_type($db, (string)$a['room_type']);
    if (!$t) {
        return ['error' => 'No room type matches "' . $a['room_type'] . '".', '_alt' => 0];
    }
    $rid = (int)$t['id'];
    $adults = (int)$a['adults'];
    $children = (int)($a['children'] ?? 0);
    $qty = max(1, (int)($a['rooms'] ?? 1));
    $nights = rh_ai_nights($ci, $co);

    $st = $db->prepare("SELECT r.*, GREATEST(r.max_guests,
                            COALESCE((SELECT MAX(ir.max_guests_override) FROM individual_rooms ir WHERE ir.room_type_id = r.id AND ir.is_active = 1), 0),
                            COALESCE((SELECT MAX(rc.max_guests_combined) FROM room_combinations rc WHERE rc.combined_room_type_id = r.id AND rc.is_active = 1), 0)) AS max_guests
                        FROM rooms r WHERE r.id = ? AND r.is_active = 1");
    $st->execute([$rid]);
    $r = $st->fetch(PDO::FETCH_ASSOC);
    if (!$r) {
        return ['error' => 'That room type is not active.'];
    }
    $base_price = (float)$r['price_per_night'];
    $queue = [];
    $notes = [];
    if (roomTypeHasActiveCombinations($rid)) {
        $claimed = [];
        foreach (getAvailableRoomCombinations($rid, $ci, $co) as $cand) {
            if (count($queue) >= $qty) {
                break;
            }
            $ids = [(int)$cand['room_a_id'], (int)$cand['room_b_id']];
            if (array_intersect($ids, $claimed)) {
                continue;
            }
            $claimed = array_merge($claimed, $ids);
            $queue[] = $cand;
        }
        if (count($queue) < $qty) {
            return ['error' => 'Only ' . count($queue) . ' joined room combination(s) are available for "' . $r['name'] . '" on those dates.', 'available' => false, '_alt' => $rid];
        }
        $rate = ($queue[0]['price_override'] !== null && $queue[0]['price_override'] !== '') ? (float)$queue[0]['price_override'] : (float)$r['price_per_night'];
        $r['price_per_night'] = $r['price_single_occupancy'] = $r['price_double_occupancy'] = $r['price_triple_occupancy'] = $rate;
        $r['max_guests'] = max((int)$r['max_guests'], (int)($queue[0]['max_guests_combined'] ?? 0));
        $notes[] = 'Joined room: priced at the combination rate.';
    }
    $policy = resolveOccupancyPolicy($r, null);
    $perRoom = (int)ceil(($adults + $children) / $qty);
    $occ = (string)($a['occupancy'] ?? '');
    if ($occ === '') {
        $order = $perRoom <= 1 ? ['single', 'double', 'triple'] : ($perRoom === 2 ? ['double', 'triple', 'single'] : ['triple', 'double', 'single']);
        foreach ($order as $o) {
            if (!empty($policy[$o . '_enabled'])) {
                $occ = $o;
                break;
            }
        }
        $occ = $occ ?: 'double';
    }
    if (empty($policy[$occ . '_enabled'])) {
        return ['error' => 'Occupancy "' . $occ . '" is not enabled for ' . $r['name'] . '.'];
    }
    $rule = rh_child_rule_for($db, $rid, null);
    $rows = [];
    for ($q = 0; $q < $qty; $q++) {
        $rows[] = ['max_children' => $rule['max'], 'capacity' => max(1, (int)$r['max_guests']), 'children_allowed' => !empty($policy['children_allowed']),
            'name' => (string)$r['name'], 'room_id' => $rid, 'tier' => $occ, 'tier_policy' => $policy, 'tier_exempt' => !empty($queue)];
    }
    $split = ga_distribute_party($rows, $adults, $children);
    if (empty($split['valid'])) {
        return ['error' => (string)$split['message'], '_alt' => $rid]; // the party does not fit: other types, joined rooms
    }
    if (empty($queue)) {
        $labels = [];
        $tiers = [];
        $g = 0;
        foreach ($rows as $i => $row) {
            $labels[] = 'Room ' . ($i + 1);
            $tiers[] = $occ;
            $g += (int)$split['rows'][$i]['guests'];
        }
        $msg = ga_group_tier_message((string)$r['name'], $labels, $tiers, $g, (int)$rows[0]['capacity'], $policy);
        if ($msg !== null) {
            if (!ga_can_under_tier($user)) {
                return ['error' => $msg];
            }
            $notes[] = $msg;
        }
    }
    $childRooms = 0;
    foreach ($split['rows'] as $sr) {
        if ((int)$sr['children'] > 0) {
            $childRooms++;
        }
    }
    $av = checkRoomAvailability($rid, $ci, $co, null, $children, $childRooms);
    $available = !empty($av['available']) && (int)($av['remaining_rooms'] ?? 0) >= $qty;

    $grand = 0.0;
    $vat = 0.0;
    $levy = 0.0;
    $childSup = 0.0;
    $first = null;
    $plan = '';
    $discount = 0.0;
    for ($i = 0; $i < $qty; $i++) {
        $row = $r;
        if (!empty($queue[$i])) {
            $cr = ($queue[$i]['price_override'] !== null && $queue[$i]['price_override'] !== '') ? (float)$queue[$i]['price_override'] : $base_price;
            $row['price_per_night'] = $row['price_single_occupancy'] = $row['price_double_occupancy'] = $row['price_triple_occupancy'] = $cr;
        }
        $price = match ($occ) {
            'single' => !empty($row['price_single_occupancy']) ? (float)$row['price_single_occupancy'] : (float)$row['price_per_night'],
            'double' => !empty($row['price_double_occupancy']) ? (float)$row['price_double_occupancy'] : (float)$row['price_per_night'],
            'triple' => !empty($row['price_triple_occupancy']) ? (float)$row['price_triple_occupancy'] : (float)$row['price_per_night'],
            default  => (float)$row['price_per_night'],
        };
        $dyn = applyDynamicPricing($db, $rid, $ci, $co, $nights, $price);
        $price = $dyn['final_price'];
        $seated = (int)($split['rows'][$i]['children'] ?? 0);
        $sup = rh_child_supplement($rule, $seated, $nights, (float)$price);
        $tt = rh_stay_totals($price * $nights + $sup, 'price');
        $grand += $tt['total_with_vat'];
        $vat += $tt['vat'];
        $levy += $tt['levy'];
        $childSup += round($sup, 2);
        if ($first === null) {
            $first = (float)$price;
            $plan = (string)($dyn['rate_plan_label'] ?? '');
            $discount = (float)($dyn['discount_amount'] ?? 0);
        }
    }
    if (!$available) {
        $notes[] = (string)($av['error'] ?? ('Only ' . (int)($av['remaining_rooms'] ?? 0) . ' room(s) left for those dates.'));
    }
    return [
        'room_type_id' => $rid, 'room_type' => $r['name'], 'check_in' => $ci, 'check_out' => $co, 'nights' => $nights, 'rooms' => $qty,
        'adults' => $adults, 'children' => $children, 'occupancy' => $occ,
        'price_per_night' => rh_ai_money((float)$first), 'rate_plan' => $plan !== '' ? $plan : null, 'rate_plan_discount_per_night' => $discount > 0 ? rh_ai_money($discount) : null,
        'child_charge' => rh_ai_money($childSup), 'vat' => rh_ai_money($vat), 'levy' => rh_ai_money($levy),
        'total' => round($grand, 2), 'currency' => rh_ai_currency(), 'vat_mode' => function_exists('vat_mode') ? vat_mode() : '',
        'available' => $available, 'notes' => $notes,
        'link' => 'create-booking.php', 'link_label' => 'Open Create Booking',
    ];
}

function rh_ai_t_arrivals_departures(PDO $db, array $user, array $a): array
{
    $day = (string)($a['date'] ?? rh_ai_today());
    $st = $db->prepare("SELECT b.id, b.booking_reference, b.guest_name, b.status, b.adult_guests, b.child_guests, b.check_out_date, b.individual_room_id, r.name AS room_name, ir.room_number
                        FROM bookings b JOIN rooms r ON b.room_id = r.id LEFT JOIN individual_rooms ir ON ir.id = b.individual_room_id
                        WHERE b.deleted_at IS NULL AND b.check_in_date = ? AND b.status IN ('confirmed', 'pending', 'checked-in', 'checked-out')
                        ORDER BY FIELD(b.status, 'pending', 'confirmed', 'checked-in', 'checked-out'), b.created_at ASC");
    $st->execute([$day]);
    $arr = $st->fetchAll(PDO::FETCH_ASSOC);
    $toCome = 0;
    $noRoom = 0;
    $arrivals = [];
    foreach ($arr as $r) {
        $pending = in_array($r['status'], ['confirmed', 'pending'], true);
        $toCome += $pending ? 1 : 0;
        $noRoom += ($pending && empty($r['individual_room_id'])) ? 1 : 0;
        if (count($arrivals) < RH_AI_TOOL_ROWS) {
            $arrivals[] = ['booking_id' => (int)$r['id'], 'reference' => $r['booking_reference'], 'guest_name' => $r['guest_name'], 'room_type' => $r['room_name'],
                'room_number' => $r['room_number'] !== null ? (string)$r['room_number'] : null, 'status' => $r['status'], 'adults' => (int)$r['adult_guests'], 'children' => (int)$r['child_guests'], 'leaves' => $r['check_out_date']];
        }
    }
    $st = $db->prepare("SELECT b.id, b.booking_reference, b.guest_name, b.status, r.name AS room_name, ir.room_number
                        FROM bookings b JOIN rooms r ON b.room_id = r.id LEFT JOIN individual_rooms ir ON ir.id = b.individual_room_id
                        WHERE b.deleted_at IS NULL AND b.check_out_date = ? AND b.status IN ('checked-in', 'checked-out')
                        ORDER BY FIELD(b.status, 'checked-in', 'checked-out'), ir.room_number");
    $st->execute([$day]);
    $dep = $st->fetchAll(PDO::FETCH_ASSOC);
    $dueOut = 0;
    $departures = [];
    foreach ($dep as $r) {
        $isDue = $r['status'] === 'checked-in';
        $dueOut += $isDue ? 1 : 0;
        if (count($departures) < RH_AI_TOOL_ROWS) {
            $row = ['booking_id' => (int)$r['id'], 'reference' => $r['booking_reference'], 'guest_name' => $r['guest_name'], 'room_type' => $r['room_name'],
                'room_number' => $r['room_number'] !== null ? (string)$r['room_number'] : null, 'status' => $r['status']];
            if ($isDue) {
                $f = rh_ai_folio((int)$r['id']);
                $row['balance_due'] = $f['balance_due'] ?? null;
            }
            $departures[] = $row;
        }
    }
    $out = ['date' => $day, 'currency' => rh_ai_currency(),
        'arrivals' => ['total' => count($arr), 'still_to_arrive' => $toCome, 'arrived' => count($arr) - $toCome, 'no_room_assigned' => $noRoom, 'list' => $arrivals],
        'departures' => ['total' => count($dep), 'due_out' => $dueOut, 'checked_out' => count($dep) - $dueOut, 'list' => $departures],
        'link' => 'dashboard.php', 'link_label' => 'Open Dashboard'];
    if ($day === rh_ai_today()) {
        $out['overdue_departures'] = (int)$db->query("SELECT COUNT(*) FROM bookings WHERE deleted_at IS NULL AND status = 'checked-in' AND check_out_date < CURDATE()")->fetchColumn();
        $out['overdue_arrivals'] = (int)$db->query("SELECT COUNT(*) FROM bookings WHERE deleted_at IS NULL AND status IN ('confirmed', 'pending') AND check_in_date < CURDATE()")->fetchColumn();
    }
    return $out;
}

function rh_ai_t_in_house(PDO $db, array $user, array $a): array
{
    $rows = $db->query("SELECT b.id, b.booking_reference, b.guest_name, b.check_out_date, r.name AS room_name, ir.room_number
                        FROM bookings b JOIN rooms r ON b.room_id = r.id LEFT JOIN individual_rooms ir ON ir.id = b.individual_room_id
                        WHERE b.deleted_at IS NULL AND b.status = 'checked-in' ORDER BY b.check_out_date, ir.room_number")->fetchAll(PDO::FETCH_ASSOC);
    $list = [];
    foreach (array_slice($rows, 0, RH_AI_TOOL_ROWS) as $r) {
        $f = rh_ai_folio((int)$r['id']);
        $list[] = ['booking_id' => (int)$r['id'], 'reference' => $r['booking_reference'], 'guest_name' => $r['guest_name'], 'room_type' => $r['room_name'],
            'room_number' => $r['room_number'] !== null ? (string)$r['room_number'] : null, 'leaves' => $r['check_out_date'], 'balance_due' => $f['balance_due'] ?? null];
    }
    return ['in_house' => count($rows), 'currency' => rh_ai_currency(), 'list' => $list, 'link' => 'bookings.php?status=checked-in', 'link_label' => 'Open in-house list'];
}

function rh_ai_t_room_board(PDO $db, array $user, array $a): array
{
    $rooms = $db->query("SELECT ir.id, ir.room_number, ir.room_name, ir.status, ir.housekeeping_status, r.name AS room_type
                         FROM individual_rooms ir JOIN rooms r ON r.id = ir.room_type_id
                         WHERE ir.is_active = 1 ORDER BY ir.display_order, ir.room_number")->fetchAll(PDO::FETCH_ASSOC);
    // Who is in each room (same join as the housekeeping board: the booking's room or a booking_rooms row)
    $occ = [];
    foreach ($db->query("SELECT ir.id AS room_id, b.guest_name, b.check_out_date
                         FROM individual_rooms ir
                         INNER JOIN bookings b ON (b.individual_room_id = ir.id OR EXISTS (
                             SELECT 1 FROM booking_rooms br WHERE br.booking_id = b.id AND br.individual_room_id = ir.id AND br.released_at IS NULL))
                         WHERE b.status = 'checked-in' AND b.deleted_at IS NULL AND b.check_in_date <= CURDATE() AND ir.is_active = 1")->fetchAll(PDO::FETCH_ASSOC) as $r) {
        $occ[(int)$r['room_id']] = $r;
    }
    $arriving = [];
    foreach ($db->query("SELECT individual_room_id FROM bookings WHERE deleted_at IS NULL AND check_in_date = CURDATE() AND status IN ('confirmed', 'pending') AND individual_room_id IS NOT NULL")->fetchAll(PDO::FETCH_COLUMN) as $rid) {
        $arriving[(int)$rid] = true;
    }
    $counts = [];
    $list = [];
    foreach ($rooms as $r) {
        $s = (string)$r['status'];
        $counts[$s] = ($counts[$s] ?? 0) + 1;
        if (!empty($a['status']) && $a['status'] !== $s) {
            continue;
        }
        $row = ['room' => (string)$r['room_number'], 'room_type' => $r['room_type'], 'status' => $s, 'housekeeping' => $r['housekeeping_status']];
        if (isset($occ[(int)$r['id']])) {
            $row['guest_name'] = $occ[(int)$r['id']]['guest_name'];
            $row['leaves'] = $occ[(int)$r['id']]['check_out_date'];
        }
        if (isset($arriving[(int)$r['id']])) {
            $row['arrival_today'] = true;
        }
        if (count($list) < 60) {
            $list[] = $row;
        }
    }
    $summary = rh_ai_room_lib() ? getRoomDashboardSummary() : [];
    return ['rooms' => count($rooms), 'by_status' => $counts, 'occupancy_pct' => isset($summary['occupancy_rate']) ? round((float)$summary['occupancy_rate'], 1) : null,
        'list' => $list, 'link' => 'room-dashboard.php', 'link_label' => 'Open Room Board'];
}

function rh_ai_t_guest_history(PDO $db, array $user, array $a): array
{
    $g = (string)$a['guest'];
    $st = $db->prepare("SELECT b.booking_reference, b.guest_name, b.guest_email, b.check_in_date, b.check_out_date, b.number_of_nights, b.status, r.name AS room_name
                        FROM bookings b LEFT JOIN rooms r ON r.id = b.room_id
                        WHERE b.deleted_at IS NULL AND (b.guest_email = ? OR b.guest_name LIKE ? OR b.guest_phone = ?)
                        ORDER BY b.check_in_date DESC LIMIT 200");
    $st->execute([$g, '%' . $g . '%', $g]);
    $rows = $st->fetchAll(PDO::FETCH_ASSOC);
    if (!$rows) {
        return ['stays' => 0, 'message' => 'No bookings found for that guest.'];
    }
    $stays = 0;
    $nights = 0;
    $first = null;
    $last = null;
    $list = [];
    foreach ($rows as $r) {
        if (!in_array($r['status'], ['cancelled', 'no-show', 'expired'], true)) {
            $stays++;
            $nights += (int)$r['number_of_nights'];
            $first = $first === null || $r['check_in_date'] < $first ? $r['check_in_date'] : $first;
            $last = $last === null || $r['check_in_date'] > $last ? $r['check_in_date'] : $last;
        }
        if (count($list) < 10) {
            $list[] = ['reference' => $r['booking_reference'], 'guest_name' => $r['guest_name'], 'check_in' => $r['check_in_date'], 'check_out' => $r['check_out_date'], 'status' => $r['status'], 'room_type' => $r['room_name']];
        }
    }
    return ['bookings_found' => count($rows), 'stays' => $stays, 'nights' => $nights, 'first_stay' => $first, 'last_stay' => $last, 'recent' => $list];
}

function rh_ai_t_rate_plans_and_deals(PDO $db, array $user, array $a): array
{
    $plans = [];
    try {
        foreach ($db->query("SELECT name, description, rule_type, start_date, end_date, days_of_week, min_nights, max_nights, days_before_min, days_before_max, adjustment_type, adjustment_value, applies_to, room_type_ids
                             FROM rate_plans WHERE is_active = 1 ORDER BY priority DESC, name LIMIT 30")->fetchAll(PDO::FETCH_ASSOC) as $p) {
            $plans[] = array_filter($p, static fn($v) => $v !== null && $v !== '');
        }
    } catch (Throwable $e) {
        $plans = [];
    }
    $out = ['rate_plans' => $plans, 'link' => 'rate-plans.php', 'link_label' => 'Open Rate Plans'];
    $uid = (int)$user['id'];
    if ((hasPermission($uid, 'pos_till') || hasPermission($uid, 'menu')) && rh_module_key_enabled('pos')) {
        try {
            $out['pos_deals'] = $db->query("SELECT name, deal_type, discount_percent, discount_fixed, days_of_week, start_time, end_time, valid_from, valid_to
                                            FROM pos_deals WHERE is_active = 1 AND (valid_to IS NULL OR valid_to >= CURDATE()) ORDER BY sort_order, name LIMIT 20")->fetchAll(PDO::FETCH_ASSOC);
        } catch (Throwable $e) {
            // no deals table
        }
    }
    return $out;
}

function rh_ai_t_balances_due(PDO $db, array $user, array $a): array
{
    $cands = $db->query("SELECT b.id, b.booking_reference, b.guest_name, b.status, b.check_out_date
                         FROM bookings b
                         WHERE b.deleted_at IS NULL AND b.status IN ('pending', 'confirmed', 'checked-in', 'checked-out')
                           AND b.check_out_date >= DATE_SUB(CURDATE(), INTERVAL 90 DAY)
                         ORDER BY b.check_out_date LIMIT 300")->fetchAll(PDO::FETCH_ASSOC);
    $owing = [];
    $total = 0.0;
    foreach ($cands as $r) {
        $f = rh_ai_folio((int)$r['id']);
        $bal = (float)($f['balance_due'] ?? 0);
        if ($bal > BALANCE_TOLERANCE) {
            $total += $bal;
            $owing[] = ['booking_id' => (int)$r['id'], 'reference' => $r['booking_reference'], 'guest_name' => $r['guest_name'], 'status' => $r['status'], 'leaves' => $r['check_out_date'], 'balance_due' => rh_ai_money($bal)];
        }
    }
    usort($owing, static fn($x, $y) => $y['balance_due'] <=> $x['balance_due']);
    return ['bookings_owing' => count($owing), 'total_owed' => rh_ai_money($total), 'currency' => rh_ai_currency(), 'scope' => 'Room bookings leaving from 90 days ago onwards',
        'largest' => array_slice($owing, 0, RH_AI_TOOL_ROWS), 'link' => 'bookings.php', 'link_label' => 'Open Bookings'];
}

// ── Guides & knowledge base ──────────────────────────────────────────────────

function rh_ai_kb_load(): void
{
    require_once dirname(__DIR__) . '/guide-knowledge-base.php';
    require_once dirname(__DIR__) . '/guide-system-knowledge.php';
}

/**
 * Which permission(s) open the screen a guide is about (any-of; [] = every signed-in person).
 * A person sees a guide only when its module is on (rh_kb_allowed_guides) AND they hold one of these.
 */
function rh_ai_guide_permissions(): array
{
    return [
        '01-pos-till.html' => ['pos_till'], '02-kds-kitchen.html' => ['kds_view'], '03-bds-bar.html' => ['bds_view'], '04-cds-coffee.html' => ['cds_view'],
        '05-room-service.html' => ['room_service_view', 'room_service_manage'], '06-housekeeping.html' => ['housekeeping', 'room_maintenance'],
        '07-reception-bookings.html' => ['bookings'], '08-stock-orders.html' => ['stock_dashboard', 'stock_management', 'stock_orders'],
        '09-packages-rates.html' => ['rooms', 'bookings'], '10-conference-events.html' => ['conference', 'events'],
        '11-social-facebook-sharing.html' => ['facebook_settings', 'gallery', 'media_management'], '12-email-templates.php' => ['edit_templates'],
        '13-finance-payments.html' => ['payments', 'accounting', 'payment_add', 'receipts'], '14-reports-eod.html' => ['reports', 'accounting'],
        '15-pwa-install-guide.html' => [], '16-business-presets.html' => ['module_settings'], '17-gym-management.html' => ['gym'],
        '18-staff-access.html' => ['user_management', 'user_permissions'], '19-hotel-settings.html' => ['booking_settings', 'finance_settings', 'module_settings'],
        '20-stock-loss-prevention.html' => ['stock_dashboard', 'stock_management', 'stock_reports'], '21-guest-website.html' => ['pages', 'section_headers', 'footer_management', 'gallery'],
        '22-assistant.html' => ['ai_assistant_use', 'ai_settings', 'ai_usage_view'], '99-admin-dashboard-full-guide.html' => ['dashboard'],
        'faq.html' => [], 'index.html' => [], 'system-map.php' => [], 'owner-handover.html' => ['module_settings'], 'owner-handover.php' => ['module_settings'],
    ];
}

/** May this person read this guide? */
function rh_ai_guide_visible(array $user, string $file): bool
{
    rh_ai_kb_load();
    if (!in_array($file, rh_kb_allowed_guides(), true)) {
        return false;
    }
    $map = rh_ai_guide_permissions();
    if (!array_key_exists($file, $map)) {
        return (string)($user['role'] ?? '') === 'admin'; // a guide nobody mapped yet: administrators only
    }
    foreach ($map[$file] as $p) {
        if (hasPermission((int)$user['id'], $p)) {
            return true;
        }
    }
    return $map[$file] === [];
}

function rh_ai_t_how_to(PDO $db, array $user, array $a): array
{
    rh_ai_kb_load();
    $r = rh_kb_search((string)$a['question'], 12);
    $out = [];
    foreach ($r['results'] as $e) {
        if (!rh_ai_guide_visible($user, (string)$e['file'])) {
            continue;
        }
        $out[] = ['title' => $e['title'], 'guide' => $e['guide'], 'type' => $e['type'], 'text' => mb_substr((string)($e['snippet'] ?? $e['text']), 0, 400), 'link' => '../docs/guides/' . $e['file'] . ((string)($e['anchor'] ?? '') !== '' ? '#' . $e['anchor'] : '')]; // no ?kb= (it would carry the question)
        if (count($out) >= 5) {
            break;
        }
    }
    $res = ['matches' => $out, 'partial' => !empty($r['partial'])];
    if ($out) {
        $res['link'] = $out[0]['link'];
        $res['link_label'] = 'Open the guide';
    }
    return $res;
}

/** Find a guide file from a file name or words of its title. */
function rh_ai_find_guide(string $q): ?array
{
    rh_ai_kb_load();
    $idx = rh_kb_index();
    $q = trim(mb_strtolower($q));
    $best = null;
    $bestScore = 0;
    foreach ((array)$idx['guides'] as $g) {
        $file = (string)$g['file'];
        if ($q === mb_strtolower($file) || $q . '.html' === mb_strtolower($file)) {
            return $g;
        }
        $hay = mb_strtolower($file . ' ' . $g['title']);
        $score = 0;
        foreach (preg_split('/[^\p{L}\p{N}]+/u', $q) as $w) {
            if (mb_strlen($w) >= 3 && mb_strpos($hay, $w) !== false) {
                $score += mb_strlen($w);
            }
        }
        if ($score > $bestScore) {
            $bestScore = $score;
            $best = $g;
        }
    }
    return $best;
}

function rh_ai_t_summarise_guide(PDO $db, array $user, array $a): array
{
    $g = rh_ai_find_guide((string)$a['guide']);
    if ($g === null) {
        return ['error' => 'No guide matches "' . $a['guide'] . '".'];
    }
    $file = (string)$g['file'];
    if (!rh_ai_guide_visible($user, $file)) {
        return ['error' => "That guide is outside what this account can see."];
    }
    $idx = rh_kb_index();
    $section = trim(mb_strtolower((string)($a['section'] ?? '')));
    $parts = [];
    $anchor = '';
    foreach ($idx['entries'] as $e) {
        if ($e['file'] !== $file || !in_array($e['type'], ['section', 'faq', 'problem'], true)) {
            continue;
        }
        if ($section !== '') {
            $hay = mb_strtolower($e['anchor'] . ' ' . $e['title'] . ' ' . ($e['parent'] ?? ''));
            $hit = false;
            foreach (preg_split('/[^\p{L}\p{N}]+/u', $section) as $w) {
                if (mb_strlen($w) >= 3 && mb_strpos($hay, $w) !== false) {
                    $hit = true;
                }
            }
            if (!$hit) {
                continue;
            }
            $anchor = $anchor ?: (string)$e['anchor'];
        }
        $parts[] = '## ' . $e['title'] . "\n" . trim((string)$e['text']);
    }
    if (!$parts) {
        return ['error' => 'That guide has no section matching "' . ($a['section'] ?? '') . '".'];
    }
    $text = implode("\n\n", $parts);
    $cut = mb_strlen($text) > 6000;
    return ['guide' => $g['title'], 'file' => $file, 'section' => $section !== '' ? $section : null, 'guide_text' => mb_substr($text, 0, 6000), 'shortened' => $cut,
        'link' => '../docs/guides/' . $file . ($anchor !== '' ? '#' . $anchor : ''), 'link_label' => 'Open the guide'];
}

function rh_ai_t_page_help(PDO $db, array $user, array $a): array
{
    rh_ai_kb_load();
    $page = basename((string)$a['page']);
    if (!preg_match('/^[a-z0-9\-_]+\.php$/i', $page)) {
        $page .= '.php';
    }
    $out = [];
    foreach (rh_kb_sys_help_for_page($page, rh_kb_index(), 8) as $e) {
        if (!rh_ai_guide_visible($user, (string)$e['file'])) {
            continue;
        }
        $out[] = ['title' => $e['title'], 'guide' => $e['guide'], 'text' => mb_substr((string)$e['text'], 0, 400), 'link' => '../docs/guides/' . $e['file'] . ($e['anchor'] !== '' ? '#' . $e['anchor'] : '')];
        if (count($out) >= 4) {
            break;
        }
    }
    return ['page' => $page, 'sections' => $out];
}

function rh_ai_t_who_is_online(PDO $db, array $user, array $a): array
{
    if (!function_exists('rh_presence_rows')) {
        require_once dirname(__DIR__, 2) . '/admin/includes/presence-lib.php';
    }
    $list = [];
    foreach (rh_presence_rows($db, false) as $r) {
        $list[] = ['staff_name' => $r['name'], 'role' => $r['role_label'], 'status' => $r['status_label'], 'screen' => $r['page_title'], 'since' => $r['since']];
    }
    return ['online' => count($list), 'list' => array_slice($list, 0, RH_AI_TOOL_ROWS)];
}

function rh_ai_t_my_access(PDO $db, array $user, array $a): array
{
    $uid = (int)$user['id'];
    $groups = [];
    foreach (getAllPermissions() as $key => $p) {
        if (hasPermission($uid, $key)) {
            $groups[(string)($p['category'] ?? 'Other')][] = (string)$p['label'];
        }
    }
    $roles = function_exists('getAllRoles') ? getAllRoles() : [];
    $modules = [];
    try {
        foreach ($db->query("SELECT module_name FROM enabled_modules WHERE is_enabled = 1 ORDER BY sort_order")->fetchAll(PDO::FETCH_COLUMN) as $m) {
            $modules[] = (string)$m;
        }
    } catch (Throwable $e) {
    }
    return ['role' => (string)($roles[$user['role']]['label'] ?? $user['role']), 'can_see_and_do' => $groups, 'modules_on' => $modules,
        'assistant_can_look_up' => array_keys(rh_ai_tools_for_user($user))];
}

function rh_ai_t_open_page(PDO $db, array $user, array $a): array
{
    $page = basename((string)$a['page']);
    if (!preg_match('/^[a-z0-9\-_]+\.php$/i', $page)) {
        $page .= '.php';
    }
    if (!preg_match('/^[a-z0-9\-_]+\.php$/i', $page) || !is_file(dirname(__DIR__, 2) . '/admin/' . $page) || in_array($page, ['logout.php', 'login.php', 'admin-init.php'], true)) {
        return ['error' => 'There is no screen called ' . $page . '.'];
    }
    if (!rhCanOpenPage((int)$user['id'], $page)) {
        return ['error' => "That screen is outside what this account can open."];
    }
    $q = [];
    foreach (['search', 'status', 'date'] as $k) {
        if (!empty($a[$k])) {
            $q[$k] = (string)$a[$k];
        }
    }
    if (!empty($a['id'])) {
        $q['id'] = (int)$a['id'];
    }
    $url = $page . ($q ? '?' . http_build_query($q) : '');
    $label = '';
    foreach (getAllPermissions() as $p) {
        if (($p['page'] ?? '') === $page) {
            $label = (string)$p['label'];
            break;
        }
    }
    return ['url' => $url, 'link' => $url, 'link_label' => 'Open ' . ($label !== '' ? $label : $page)];
}

// ── Hotel facts (owner 2026-10-28: "How come it doesn't know about the restaurant and bar and opening times etc.") ──
// The hotel's public-facing details every staff member may see. Each part follows its module switch, so a part that is
// switched off is never offered. Times are shown as stored, in plain 12-hour form; a time that is not set is said to be
// not set (never a default or a guess). Lists follow the 10-a-page rule (rh_ai_bound_result), so the size is fixed.

/** Settings read fresh in one query (no file cache): key => trimmed value ('' when the row is missing or blank). */
function rh_ai_hotel_settings(PDO $db, array $keys): array
{
    $out = array_fill_keys($keys, '');
    try {
        $st = $db->prepare('SELECT setting_key, setting_value FROM site_settings WHERE setting_key IN (' . implode(',', array_fill(0, count($keys), '?')) . ')');
        $st->execute(array_values($keys));
        foreach ($st->fetchAll(PDO::FETCH_KEY_PAIR) as $k => $v) {
            $out[(string)$k] = trim((string)$v);
        }
    } catch (Throwable $e) {
        // no settings table: every value reads as not set
    }
    return $out;
}

/** A stored time in plain 12-hour form ("14:00" -> "2:00 PM"); a 12-hour or free-text value ("2:00 PM", "24/7") as stored. */
function rh_ai_clock(string $raw): string
{
    $v = trim($raw);
    if (preg_match('/^(\d{1,2}):(\d{2})(?::\d{2})?$/', $v, $m) && (int)$m[1] < 24 && (int)$m[2] < 60) {
        $h = (int)$m[1];
        return ($h % 12 === 0 ? 12 : $h % 12) . ':' . $m[2] . ($h < 12 ? ' AM' : ' PM');
    }
    return $v;
}

/** One opening window from two stored times. Never fills in a time that is not set ($where: the screen it is set on). */
function rh_ai_hours_line(string $open, string $close, string $where = 'Hotel Settings'): string
{
    $o = trim($open);
    $c = trim($close);
    if ($o === '' && $c === '') {
        return 'not set in ' . $where;
    }
    if ($o === '') {
        return 'closes ' . rh_ai_clock($c) . '; opening time not set in ' . $where;
    }
    if ($c === '') {
        return 'opens ' . rh_ai_clock($o) . '; closing time not set in ' . $where;
    }
    if (rh_ai_clock($o) === rh_ai_clock($c)) {
        return 'open and close times are the same (' . rh_ai_clock($o) . ') — check ' . $where;
    }
    $next = '';
    if (preg_match('/^(\d{1,2}):(\d{2})/', $o, $a) && preg_match('/^(\d{1,2}):(\d{2})/', $c, $b) && !preg_match('/[ap]\.?m/i', $o . $c)
        && ((int)$b[1] * 60 + (int)$b[2]) < ((int)$a[1] * 60 + (int)$a[2])) {
        $next = ' (next day)';
    }
    return rh_ai_clock($o) . ' – ' . rh_ai_clock($c) . $next;
}

/** Where station hours are set (the Station Hours screen, admin/station-settings.php). */
function rh_ai_station_hours_where(): string
{
    return 'Settings → Station Hours';
}

/**
 * A station's hours: only the times saved on Station Hours (owner 2026-10-28, "not hard coded": migration 074 saved each
 * hotel's running hours as settings). A time not saved, or not a valid time, is said to be not set; a default written
 * in code is never quoted.
 */
function rh_ai_station_hours_line(string $station, string $openRaw, string $closeRaw): string
{
    require_once dirname(__DIR__) . '/station-hours.php';
    return rh_ai_hours_line(rh_station_normalize_time($openRaw, ''), rh_station_normalize_time($closeRaw, ''), rh_ai_station_hours_where());
}

/** Station -> its module switch (same map as includes/station-hours.php); a station also needs the till (pos) on. */
function rh_ai_station_on(string $station): bool
{
    $mod = ['kitchen' => 'station_kds', 'bar' => 'station_bds', 'coffee_bar' => 'station_cds'][$station] ?? '';
    return $mod !== '' && rh_ai_module_on('pos') && rh_ai_module_on($mod);
}

/** The restaurant is offered when its public page or the till is on. */
function rh_ai_restaurant_on(): bool
{
    return rh_ai_module_on('restaurant_page') || rh_ai_module_on('pos');
}

/** Gym hours per weekday (gym_hours, 0 = Sunday), grouped: "Mon–Fri 6:00 AM – 9:00 PM; Sat–Sun closed". */
function rh_ai_gym_hours(PDO $db): string
{
    try {
        $rows = $db->query('SELECT day_of_week, is_open, open_time, close_time FROM gym_hours ORDER BY day_of_week')->fetchAll(PDO::FETCH_ASSOC);
    } catch (Throwable $e) {
        $rows = [];
    }
    $names = ['Sun', 'Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat'];
    $per = [];
    foreach ($rows as $r) {
        $d = (int)$r['day_of_week'];
        if ($d >= 0 && $d <= 6) {
            $per[$d] = !empty($r['is_open']) ? rh_ai_hours_line((string)$r['open_time'], (string)$r['close_time']) : 'closed';
        }
    }
    if (!$per) {
        return 'not set in Hotel Settings';
    }
    $groups = [];
    foreach ([1, 2, 3, 4, 5, 6, 0] as $d) {
        $v = $per[$d] ?? 'not set in Hotel Settings';
        $last = count($groups) - 1;
        if ($last >= 0 && $groups[$last]['v'] === $v) {
            $groups[$last]['to'] = $d;
        } else {
            $groups[] = ['from' => $d, 'to' => $d, 'v' => $v];
        }
    }
    if (count($groups) === 1) {
        return 'every day ' . $groups[0]['v'];
    }
    $parts = [];
    foreach ($groups as $g) {
        $parts[] = $names[$g['from']] . ($g['to'] !== $g['from'] ? '–' . $names[$g['to']] : '') . ' ' . $g['v'];
    }
    return implode('; ', $parts);
}

/** Check-in/out and the opening hours of every part that is switched on (keys: check_in, check_out, kitchen, bar, coffee_bar, gym). */
function rh_ai_hotel_hours(PDO $db, array $s): array
{
    $h = [];
    if (rh_ai_module_on('bookings')) {
        $h['check_in'] = $s['check_in_time'] !== '' ? 'from ' . rh_ai_clock($s['check_in_time']) : 'not set in Hotel Settings';
        $h['check_out'] = $s['check_out_time'] !== '' ? 'by ' . rh_ai_clock($s['check_out_time']) : 'not set in Hotel Settings';
    }
    foreach (['kitchen', 'bar', 'coffee_bar'] as $st) {
        if (rh_ai_station_on($st)) {
            $h[$st] = rh_ai_station_hours_line($st, $s['station_' . $st . '_opens_at'], $s['station_' . $st . '_closes_at']);
        }
    }
    if (rh_ai_module_on('gym')) {
        $h['gym'] = rh_ai_gym_hours($db);
    }
    return $h;
}

/** Plain text, cut to $max characters (no markup). */
function rh_ai_plain(string $v, int $max): string
{
    $v = trim((string)preg_replace('/\s+/u', ' ', html_entity_decode(strip_tags($v), ENT_QUOTES, 'UTF-8')));
    return mb_strlen($v) > $max ? mb_substr($v, 0, $max - 3) . '...' : $v;
}

/** Facilities that are switched on: "Name — short description". */
function rh_ai_hotel_facilities(PDO $db): array
{
    try {
        $rows = $db->query('SELECT name, short_description, page_url FROM facilities WHERE is_active = 1 ORDER BY display_order, name LIMIT 100')->fetchAll(PDO::FETCH_ASSOC);
    } catch (Throwable $e) {
        return [];
    }
    $out = [];
    foreach ($rows as $r) {
        $page = strtolower(basename((string)$r['page_url']));
        $name = (string)$r['name'];
        if ((!rh_ai_module_on('gym') && (strpos($page, 'gym') === 0 || preg_match('/\b(gym|fitness)\b/i', $name)))
            || (!rh_ai_module_on('conference') && strpos($page, 'conference') === 0)
            || (!rh_ai_module_on('events') && strpos($page, 'events') === 0)
            || (!rh_ai_restaurant_on() && strpos($page, 'restaurant') === 0)) {
            continue; // a part switched off is never offered
        }
        $sd = rh_ai_plain((string)$r['short_description'], 160);
        $out[] = rh_ai_plain($name, 80) . ($sd !== '' ? ' — ' . $sd : '');
    }
    return $out;
}

/** Active policies: "Title — summary". */
function rh_ai_hotel_policies(PDO $db): array
{
    try {
        $rows = $db->query('SELECT title, summary FROM policies WHERE is_active = 1 ORDER BY display_order, title LIMIT 100')->fetchAll(PDO::FETCH_ASSOC);
    } catch (Throwable $e) {
        return [];
    }
    $out = [];
    foreach ($rows as $r) {
        $sum = rh_ai_plain((string)$r['summary'], 200);
        $out[] = rh_ai_plain((string)$r['title'], 80) . ($sum !== '' ? ' — ' . $sum : '');
    }
    return $out;
}

/** Hotel phone numbers, public mailboxes and address (no staff emails). A number listed under several names is shown once. */
function rh_ai_hotel_contact(array $s): array
{
    $phones = [];
    foreach (['phone_main' => 'Main', 'phone_reception' => 'Reception', 'phone_reservations' => 'Reservations', 'whatsapp_number' => 'WhatsApp'] as $k => $label) {
        if ($s[$k] !== '') {
            $phones[$s[$k]][] = $label;
        }
    }
    $emails = [];
    foreach (['email_main' => 'Main', 'email_reservations' => 'Reservations', 'email_info' => 'Info'] as $k => $label) {
        if ($s[$k] !== '') {
            $emails[$s[$k]][] = $label;
        }
    }
    $out = ['phones' => [], 'emails' => []];
    foreach ($phones as $num => $labels) {
        $out['phones'][implode(' / ', $labels)] = (string)$num;
    }
    foreach ($emails as $em => $labels) {
        $out['emails'][implode(' / ', $labels)] = (string)$em;
    }
    $addr = $s['hotel_address'] !== '' ? $s['hotel_address'] : $s['address'];
    if ($addr === '') {
        $addr = implode(', ', array_filter([$s['address_line1'], $s['address_line2'], $s['address_city'], $s['address_region'], $s['address_country']], static fn($x) => $x !== ''));
    }
    $out['hotel_address'] = $addr !== '' ? rh_ai_plain($addr, 200) : 'not set in Hotel Settings';
    if (!$out['phones']) {
        $out['phones'] = 'not set in Hotel Settings';
    }
    return $out;
}

/** Menu: categories with their available item counts and the available items with price, featured first. */
function rh_ai_hotel_menu(PDO $db): array
{
    $cur = rh_ai_currency();
    $price = static fn($p) => ($cur !== '' ? $cur . ' ' : '') . number_format((float)$p, 2);
    $cats = [];
    $items = [];
    $total = 0;
    try {
        foreach ($db->query('SELECT c.name, COUNT(i.id) AS n FROM menu_categories c JOIN menu_items i ON i.category_id = c.id AND i.is_available = 1
                             WHERE c.is_active = 1 GROUP BY c.id, c.name ORDER BY MIN(c.display_order), c.name')->fetchAll(PDO::FETCH_ASSOC) as $r) {
            $cats[] = rh_ai_plain((string)$r['name'], 60) . ': ' . (int)$r['n'];
            $total += (int)$r['n'];
        }
        foreach ($db->query('SELECT i.item_name, i.price, c.name AS cat FROM menu_items i JOIN menu_categories c ON c.id = i.category_id AND c.is_active = 1
                             WHERE i.is_available = 1 ORDER BY i.is_featured DESC, c.display_order, i.display_order, i.item_name LIMIT 200')->fetchAll(PDO::FETCH_ASSOC) as $r) {
            $items[] = rh_ai_plain((string)$r['item_name'], 60) . ' — ' . $price($r['price']) . ' (' . rh_ai_plain((string)$r['cat'], 40) . ')';
        }
    } catch (Throwable $e) {
        // older menu tables (food_menu / drink_menu, as the public restaurant page reads them)
        $cats = [];
        $items = [];
        $total = 0;
        try {
            $byCat = [];
            foreach (['food_menu', 'drink_menu'] as $t) {
                foreach ($db->query("SELECT item_name, price, category, is_featured FROM $t WHERE is_available = 1 ORDER BY is_featured DESC, display_order, item_name LIMIT 200")->fetchAll(PDO::FETCH_ASSOC) as $r) {
                    $c = rh_ai_plain((string)$r['category'], 40);
                    $byCat[$c] = ($byCat[$c] ?? 0) + 1;
                    $total++;
                    $items[] = ['f' => (int)$r['is_featured'], 't' => rh_ai_plain((string)$r['item_name'], 60) . ' — ' . $price($r['price']) . ' (' . $c . ')'];
                }
            }
            usort($items, static fn($x, $y) => $y['f'] <=> $x['f']);
            $items = array_slice(array_column($items, 't'), 0, 200);
            foreach ($byCat as $c => $n) {
                $cats[] = $c . ': ' . $n;
            }
        } catch (Throwable $e2) {
            return ['menu' => 'no menu found'];
        }
    }
    return ['categories_count' => count($cats), 'items_available' => $total, 'categories' => $cats, 'items' => $items];
}

/** Names of the facilities the hotel offers (parts it does not offer already left out), at most ai_alt_menu_max. */
function rh_ai_facility_names(PDO $db, ?int $n = null): array
{
    return array_slice(array_map(static fn($f) => explode(' — ', $f)[0], rh_ai_hotel_facilities($db)), 0, $n ?? rh_ai_num('ai_alt_menu_max'));
}

/**
 * Is a station open right now (owner 2026-10-28)? The saved Station Hours only, in the site timezone, with the station
 * screens' rule that a close at or before the open runs past midnight (includes/station-hours.php). A time not saved, or
 * the same open and close time: null (the hours line already says so; nothing is claimed).
 * @return array{open:bool,line:string}|null
 */
function rh_ai_station_state(string $station, string $openRaw, string $closeRaw, ?DateTimeImmutable $now = null): ?array
{
    require_once dirname(__DIR__) . '/station-hours.php';
    $o = rh_station_normalize_time($openRaw, '');
    $c = rh_station_normalize_time($closeRaw, '');
    if ($o === '' || $c === '' || $o === $c) {
        return null;
    }
    $tz = rh_site_timezone();
    $now = $now ? $now->setTimezone($tz) : new DateTimeImmutable('now', $tz);
    $cross = rh_station_minutes($c) <= rh_station_minutes($o);
    $next = null;
    foreach ([-1, 0, 1] as $d) {
        $day = $now->modify(($d < 0 ? '' : '+') . $d . ' day')->format('Y-m-d');
        $start = new DateTimeImmutable($day . ' ' . $o . ':00', $tz);
        $end = new DateTimeImmutable(($cross ? date('Y-m-d', strtotime($day . ' +1 day')) : $day) . ' ' . $c . ':00', $tz);
        if ($now >= $start && $now < $end) {
            return ['open' => true, 'line' => 'open now, until ' . rh_ai_clock($c)];
        }
        if ($next === null && $start > $now) {
            $next = $start;
        }
    }
    $when = $next !== null && $next->format('Y-m-d') === $now->format('Y-m-d') ? ' today' : ' tomorrow';
    return ['open' => false, 'line' => 'closed now; opens ' . rh_ai_clock($o) . $when];
}

/** Open or closed right now for each station the hotel runs, and which are open now. */
function rh_ai_station_now(array $s): array
{
    $now = $GLOBALS['__rh_ai_now'] ?? null; // tests only: a fixed moment
    $labels = ['kitchen' => 'Kitchen', 'bar' => 'Bar', 'coffee_bar' => 'Coffee bar'];
    $state = [];
    $open = [];
    foreach ($labels as $st => $label) {
        if (!rh_ai_station_on($st)) {
            continue;
        }
        $x = rh_ai_station_state($st, (string)($s['station_' . $st . '_opens_at'] ?? ''), (string)($s['station_' . $st . '_closes_at'] ?? ''), $now instanceof DateTimeImmutable ? $now : null);
        if ($x !== null) {
            $state[$st] = $x['line'];
            if ($x['open']) {
                $open[] = $label;
            }
        }
    }
    return $state ? ['now' => $state, 'open_now' => $open ?: 'nothing is open right now'] : [];
}

/** Every menu item with price, category and its available / featured / vegetarian / vegan flags (newest table shape first). */
function rh_ai_menu_rows(PDO $db): ?array
{
    $sqls = [
        'SELECT i.item_name AS n, i.price AS p, c.name AS c, i.is_available AS a, i.is_featured AS f, i.is_vegetarian AS veg, i.is_vegan AS vegan, i.tags AS tags
         FROM menu_items i JOIN menu_categories c ON c.id = i.category_id AND c.is_active = 1 ORDER BY i.is_featured DESC, c.display_order, i.display_order, i.item_name LIMIT 500',
        "SELECT i.item_name AS n, i.price AS p, c.name AS c, i.is_available AS a, i.is_featured AS f, 0 AS veg, 0 AS vegan, '' AS tags
         FROM menu_items i JOIN menu_categories c ON c.id = i.category_id AND c.is_active = 1 ORDER BY i.is_featured DESC, c.display_order, i.display_order, i.item_name LIMIT 500",
    ];
    foreach ($sqls as $sql) {
        try {
            return $db->query($sql)->fetchAll(PDO::FETCH_ASSOC);
        } catch (Throwable $e) {
            // try the next shape
        }
    }
    try { // older menu tables (food_menu / drink_menu, as the public restaurant page reads them)
        $rows = [];
        foreach (['food_menu', 'drink_menu'] as $t) {
            $rows = array_merge($rows, $db->query("SELECT item_name AS n, price AS p, category AS c, is_available AS a, is_featured AS f, 0 AS veg, 0 AS vegan, '' AS tags FROM $t ORDER BY is_featured DESC, display_order, item_name LIMIT 300")->fetchAll(PDO::FETCH_ASSOC));
        }
        return $rows;
    } catch (Throwable $e) {
        return null;
    }
}

/**
 * One menu item (owner 2026-10-28): available or not, and then up to ai_alt_menu_max available items from the same category (featured
 * items when there are none, or when nothing matches). "vegetarian" / "vegan" lists the items marked so. Prices as stored.
 */
function rh_ai_menu_item(PDO $db, string $item): array
{
    $rows = rh_ai_menu_rows($db);
    if (!$rows) {
        return ['asked' => $item, 'menu' => 'no menu found'];
    }
    $cur = rh_ai_currency();
    $fmt = static fn(array $r): string => rh_ai_plain((string)$r['n'], 60) . ' — ' . ($cur !== '' ? $cur . ' ' : '') . number_format((float)$r['p'], 2) . ' (' . rh_ai_plain((string)$r['c'], 40) . ')';
    $avail = array_values(array_filter($rows, static fn($r) => !empty($r['a'])));
    $feat = array_values(array_filter($avail, static fn($r) => !empty($r['f'])));
    $n = rh_ai_num('ai_alt_menu_max');
    $fallback = $avail ? ['alternatives' => array_map($fmt, array_slice($feat ?: $avail, 0, $n)), 'alternatives_are' => $feat ? 'featured items' : 'items on the menu']
        : ['alternatives' => 'nothing on the menu is available right now'];
    $q = mb_strtolower(trim($item));
    $out = ['asked' => $item];
    $diet = preg_match('/\bvegan\b/u', $q) ? 'vegan' : (preg_match('/\b(vegetarian|veggie|meat.?free|plant.?based)\b/u', $q) ? 'veg' : '');
    if ($diet !== '') {
        $word = $diet === 'vegan' ? 'vegan' : 'vegetarian';
        $hits = array_values(array_filter($avail, static fn($r) => !empty($r[$diet]) || ($diet === 'veg' && !empty($r['vegan'])) || stripos((string)$r['tags'], $word) !== false));
        if ($hits) {
            return $out + ['status' => count($hits) . ' available item(s) marked ' . $word, 'items' => array_map($fmt, $hits)];
        }
        // None marked: the categories to look through (never a guess at which dishes are meat-free)
        $cats = [];
        foreach ($avail as $r) {
            $c = rh_ai_plain((string)$r['c'], 40);
            $cats[$c] = ($cats[$c] ?? 0) + 1;
        }
        $list = [];
        foreach ($cats as $c => $n) {
            $list[] = $c . ': ' . $n;
        }
        return $out + ['status' => 'No available item on the menu is marked ' . $word . ' (staff can mark items on the menu screen).', 'categories' => $list ?: 'nothing on the menu is available right now'];
    }
    $words = [];
    foreach (preg_split('/[^\p{L}\p{N}]+/u', $q) as $w) {
        if (mb_strlen($w) >= 3) {
            $words[] = mb_strlen($w) > 3 ? (string)preg_replace('/(es|s)$/u', '', $w) : $w; // "chips" finds "chip"
        }
    }
    $match = static function (string $hay) use ($words): bool {
        $hay = mb_strtolower($hay);
        foreach ($words as $w) {
            if (mb_strpos($hay, $w) === false) {
                return false;
            }
        }
        return $words !== [];
    };
    $hits = array_values(array_filter($rows, static fn($r) => $match((string)$r['n'])));
    if (!$hits) {
        $cat = array_values(array_filter($avail, static fn($r) => $match((string)$r['c'])));
        if ($cat) {
            return $out + ['status' => 'a menu category', 'items' => array_map($fmt, $cat)];
        }
        return $out + ['status' => 'Not on the menu.'] + $fallback;
    }
    $ok = array_values(array_filter($hits, static fn($r) => !empty($r['a'])));
    if ($ok) {
        return $out + ['status' => 'available', 'items' => array_map($fmt, array_slice($ok, 0, $n))];
    }
    $names = array_map(static fn($r) => (string)$r['n'], $hits);
    $cats = array_unique(array_map(static fn($r) => (string)$r['c'], $hits));
    $same = array_values(array_filter($avail, static fn($r) => in_array((string)$r['c'], $cats, true) && !in_array((string)$r['n'], $names, true)));
    $out += ['status' => 'Not available right now.', 'unavailable' => array_map(static fn($n) => rh_ai_plain($n, 60), array_slice($names, 0, 3))];
    if ($same) {
        return $out + ['alternatives' => array_map($fmt, array_slice($same, 0, $n)), 'alternatives_are' => 'available items from the same category'];
    }
    return $out + $fallback;
}

function rh_ai_t_hotel_info(PDO $db, array $user, array $a): array
{
    $item = trim((string)($a['item'] ?? ''));
    $topic = (string)($a['topic'] ?? ($item !== '' ? 'menu' : 'overview'));
    $s = rh_ai_hotel_settings($db, ['site_name', 'check_in_time', 'check_out_time',
        'station_kitchen_opens_at', 'station_kitchen_closes_at', 'station_bar_opens_at', 'station_bar_closes_at', 'station_coffee_bar_opens_at', 'station_coffee_bar_closes_at',
        'phone_main', 'phone_reception', 'phone_reservations', 'whatsapp_number', 'email_main', 'email_reservations', 'email_info',
        'hotel_address', 'address', 'address_line1', 'address_line2', 'address_city', 'address_region', 'address_country']);
    $out = ['hotel' => $s['site_name'] !== '' ? $s['site_name'] : 'not set in Hotel Settings', 'topic' => $topic];
    // A part the hotel does not offer: one plain line and what it does offer (the module system is never mentioned).
    $off = static function (string $line) use ($db, &$out): string {
        $out['we_offer'] = $out['we_offer'] ?? (rh_ai_facility_names($db) ?: 'no facilities set up');
        return $line;
    };

    if ($topic === 'hours') {
        $out['hours'] = rh_ai_hotel_hours($db, $s);
        $out += rh_ai_station_now($s);
        if (!rh_ai_module_on('gym') || !rh_ai_restaurant_on()) {
            $out['we_offer'] = rh_ai_facility_names($db) ?: 'no facilities set up';
        }
        return $out;
    }
    if ($topic === 'contact') {
        return $out + rh_ai_hotel_contact($s);
    }
    if ($topic === 'facilities') {
        $out['facilities'] = rh_ai_hotel_facilities($db) ?: 'none set up';
        return $out;
    }
    if ($topic === 'policies') {
        $out['policies'] = rh_ai_hotel_policies($db) ?: 'none set up';
        return $out;
    }
    if ($topic === 'gym') {
        if (!rh_ai_module_on('gym')) {
            $out['gym'] = $off("We don't offer a gym here.");
            return $out;
        }
        $out['gym_hours'] = rh_ai_gym_hours($db);
        foreach (rh_ai_hotel_facilities($db) as $f) {
            if (preg_match('/\b(gym|fitness)\b/i', $f)) {
                $out['gym'] = $f;
                break;
            }
        }
        return $out;
    }
    if ($topic === 'menu') {
        if (!rh_ai_restaurant_on()) {
            $out['menu'] = $off("We don't have a restaurant here.");
            return $out;
        }
        if ($item !== '') {
            return $out + rh_ai_menu_item($db, $item); // one item: available or not, and what else to offer
        }
        return $out + rh_ai_hotel_menu($db);
    }
    if ($topic === 'restaurant_bar') {
        if (!rh_ai_restaurant_on()) {
            $out['restaurant'] = $off("We don't have a restaurant here.");
            return $out;
        }
        $out['hours'] = array_intersect_key(rh_ai_hotel_hours($db, $s), array_flip(['kitchen', 'bar', 'coffee_bar'])) ?: 'no kitchen, bar or coffee bar hours are set up here';
        $out += rh_ai_station_now($s);
        foreach (rh_ai_hotel_facilities($db) as $f) {
            if (preg_match('/\b(restaurant|bar|dining|caf[eé]|coffee)\b/iu', $f)) {
                $out['facility'][] = $f;
            }
        }
        foreach (rh_ai_hotel_policies($db) as $p) {
            if (preg_match('/\b(dining|restaurant|bar)\b/i', $p)) {
                $out['dining_policy'] = $p;
                break;
            }
        }
        $m = rh_ai_hotel_menu($db);
        $out['menu'] = isset($m['items_available']) ? $m['items_available'] . ' items available in ' . $m['categories_count'] . ' categories (topic menu lists them)' : 'no menu found';
        try {
            $t = $db->query('SELECT COUNT(*) AS n, COALESCE(SUM(capacity), 0) AS seats FROM restaurant_tables WHERE is_active = 1')->fetch(PDO::FETCH_ASSOC);
            if ((int)$t['n'] > 0) {
                $out['tables'] = (int)$t['n'] . ' tables, ' . (int)$t['seats'] . ' seats';
            }
        } catch (Throwable $e) {
            // no tables set up
        }
        return $out;
    }
    if ($topic === 'conference_events') {
        $cur = rh_ai_currency();
        $money = static fn($v) => ($cur !== '' ? $cur . ' ' : '') . number_format((float)$v, 2);
        if (rh_ai_module_on('conference')) {
            try {
                foreach ($db->query('SELECT name, capacity, daily_rate, half_day_rate FROM conference_rooms WHERE is_active = 1 ORDER BY display_order, name LIMIT 100')->fetchAll(PDO::FETCH_ASSOC) as $r) {
                    $out['conference_rooms'][] = rh_ai_plain((string)$r['name'], 60) . ' — up to ' . (int)$r['capacity'] . ' people'
                        . ((float)$r['daily_rate'] > BALANCE_TOLERANCE ? ', day ' . $money($r['daily_rate']) : '') . ((float)$r['half_day_rate'] > BALANCE_TOLERANCE ? ', half day ' . $money($r['half_day_rate']) : '');
                }
            } catch (Throwable $e) {
                // no conference rooms table
            }
            $out['conference_rooms'] = $out['conference_rooms'] ?? 'none set up';
        } else {
            $out['conference_rooms'] = $off("We don't offer conference rooms here.");
        }
        if (rh_ai_module_on('events')) {
            try {
                $st = $db->prepare('SELECT title, event_date, start_time, end_time, location, ticket_price FROM events WHERE is_active = 1 AND event_date >= ? ORDER BY event_date, start_time LIMIT 100');
                $st->execute([rh_ai_today()]);
                foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) {
                    $when = (string)$r['event_date'] . ((string)$r['start_time'] !== '' ? ' ' . rh_ai_clock((string)$r['start_time']) : '') . ((string)$r['end_time'] !== '' ? '–' . rh_ai_clock((string)$r['end_time']) : '');
                    $out['upcoming_events'][] = rh_ai_plain((string)$r['title'], 80) . ' — ' . $when . ((string)$r['location'] !== '' ? ', ' . rh_ai_plain((string)$r['location'], 60) : '')
                        . ((float)$r['ticket_price'] > BALANCE_TOLERANCE ? ', ticket ' . $money($r['ticket_price']) : '');
                }
            } catch (Throwable $e) {
                // no events table
            }
            $out['upcoming_events'] = $out['upcoming_events'] ?? 'none coming up';
        } else {
            $out['upcoming_events'] = $off("We don't run events here.");
        }
        return $out;
    }
    // overview (the default)
    try {
        $about = $db->query("SELECT content FROM about_us WHERE is_active = 1 AND section_type = 'main' ORDER BY display_order LIMIT 1")->fetchColumn();
        if ($about !== false && trim((string)$about) !== '') {
            $out['about'] = rh_ai_plain((string)$about, 400);
        }
    } catch (Throwable $e) {
        // no about-us table
    }
    $out['hours'] = rh_ai_hotel_hours($db, $s);
    $c = rh_ai_hotel_contact($s);
    $out['phones'] = $c['phones'];
    $out['hotel_address'] = $c['hotel_address'];
    $out['facilities'] = array_map(static fn($f) => explode(' — ', $f)[0], rh_ai_hotel_facilities($db));
    $parts = [];
    foreach (['restaurant' => rh_ai_restaurant_on(), 'bar' => rh_ai_station_on('bar'), 'coffee bar' => rh_ai_station_on('coffee_bar'), 'gym' => rh_ai_module_on('gym'),
        'conference rooms' => rh_ai_module_on('conference'), 'events' => rh_ai_module_on('events')] as $p => $on) {
        if ($on) {
            $parts[] = $p;
        }
    }
    $out['offered_here'] = $parts;
    $out['more'] = 'topics: hours, restaurant_bar, menu, facilities, policies, contact' . (rh_ai_module_on('gym') ? ', gym' : '') . ', conference_events';
    return $out;
}
