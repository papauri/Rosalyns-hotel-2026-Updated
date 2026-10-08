<?php

/**
 * Business type presets for the Module Settings page.
 * Shared between the admin UI (module-settings.php) and the
 * preset-affected-users API so both work from one source of truth.
 */
if (!function_exists('getBusinessPresets')) {
    function getBusinessPresets(): array
    {
        return [
            'full_hotel' => [
                'label' => 'Full Hotel',
                'icon'  => 'fas fa-hotel',
                'desc'  => 'All modules + all stations active',
                'modules' => [
                    'bookings' => 1, 'housekeeping' => 1, 'pos' => 1, 'stock' => 1,
                    'conference' => 1, 'gym' => 1, 'finance' => 1, 'website_cms' => 1,
                    'station_kds' => 1, 'station_bds' => 1, 'station_cds' => 1, 'station_room_service' => 1,
                ],
                // Guest-facing pages that only apply to some presets and can't be
                // derived from the module flags alone (e.g. POS is "on" for a
                // gym's snack till too, but that doesn't mean show a restaurant page).
                'front_end' => ['restaurant_page' => 1, 'events_page' => 1],
            ],
            'hotel_no_restaurant' => [
                'label' => 'Hotel (No Restaurant)',
                'icon'  => 'fas fa-bed',
                'desc'  => 'Rooms + conference + gym, no POS or stock',
                'modules' => [
                    'bookings' => 1, 'housekeeping' => 1, 'pos' => 0, 'stock' => 0,
                    'conference' => 1, 'gym' => 1, 'finance' => 1, 'website_cms' => 1,
                    'station_kds' => 0, 'station_bds' => 0, 'station_cds' => 0, 'station_room_service' => 0,
                ],
                'front_end' => ['restaurant_page' => 0, 'events_page' => 1],
            ],
            'bar_restaurant' => [
                'label' => 'Bar / Restaurant',
                'icon'  => 'fas fa-martini-glass',
                'desc'  => 'POS + KDS + BDS + stock',
                'modules' => [
                    'bookings' => 0, 'housekeeping' => 0, 'pos' => 1, 'stock' => 1,
                    'conference' => 0, 'gym' => 0, 'finance' => 1, 'website_cms' => 0,
                    'station_kds' => 1, 'station_bds' => 1, 'station_cds' => 0, 'station_room_service' => 0,
                ],
                'front_end' => ['restaurant_page' => 1, 'events_page' => 0],
            ],
            'conference_venue' => [
                'label' => 'Conference Venue',
                'icon'  => 'fas fa-briefcase',
                'desc'  => 'Bookings + conference + website',
                'modules' => [
                    'bookings' => 1, 'housekeeping' => 0, 'pos' => 0, 'stock' => 0,
                    'conference' => 1, 'gym' => 0, 'finance' => 1, 'website_cms' => 1,
                    'station_kds' => 0, 'station_bds' => 0, 'station_cds' => 0, 'station_room_service' => 0,
                ],
                'front_end' => ['restaurant_page' => 0, 'events_page' => 1],
            ],
            'gym_fitness' => [
                'label' => 'Gym / Fitness',
                'icon'  => 'fas fa-dumbbell',
                'desc'  => 'Gym + POS till + website',
                'modules' => [
                    'bookings' => 0, 'housekeeping' => 0, 'pos' => 1, 'stock' => 0,
                    'conference' => 0, 'gym' => 1, 'finance' => 1, 'website_cms' => 1,
                    'station_kds' => 0, 'station_bds' => 0, 'station_cds' => 0, 'station_room_service' => 0,
                ],
                'front_end' => ['restaurant_page' => 0, 'events_page' => 1],
                // POS starter categories seeded on preset apply (additive-only —
                // inserted only when the slug doesn't exist yet). Restaurant-style
                // presets skip this; they already have Food/Drinks.
                'starter_categories' => [
                    ['name' => 'Supplements', 'icon' => 'fa-capsules'],
                    ['name' => 'Beverages', 'icon' => 'fa-bottle-water'],
                    ['name' => 'Merchandise', 'icon' => 'fa-shirt'],
                    ['name' => 'Day Passes', 'icon' => 'fa-ticket'],
                ],
            ],
            'retail_shop' => [
                'label' => 'Retail / Shop',
                'icon'  => 'fas fa-store',
                'desc'  => 'POS + stock + finance only',
                'modules' => [
                    'bookings' => 0, 'housekeeping' => 0, 'pos' => 1, 'stock' => 1,
                    'conference' => 0, 'gym' => 0, 'finance' => 1, 'website_cms' => 0,
                    'station_kds' => 0, 'station_bds' => 0, 'station_cds' => 0, 'station_room_service' => 0,
                ],
                'front_end' => ['restaurant_page' => 0, 'events_page' => 0],
                'starter_categories' => [
                    ['name' => 'General Merchandise', 'icon' => 'fa-box'],
                    ['name' => 'Accessories', 'icon' => 'fa-bag-shopping'],
                ],
            ],
            'supermarket' => [
                'label' => 'Supermarket',
                'icon'  => 'fas fa-cart-shopping',
                'desc'  => 'POS + stock + finance + website',
                'modules' => [
                    'bookings' => 0, 'housekeeping' => 0, 'pos' => 1, 'stock' => 1,
                    'conference' => 0, 'gym' => 0, 'finance' => 1, 'website_cms' => 1,
                    'station_kds' => 0, 'station_bds' => 0, 'station_cds' => 0, 'station_room_service' => 0,
                ],
                'front_end' => ['restaurant_page' => 0, 'events_page' => 0],
                'starter_categories' => [
                    ['name' => 'Groceries', 'icon' => 'fa-basket-shopping'],
                    ['name' => 'Beverages', 'icon' => 'fa-bottle-water'],
                    ['name' => 'Household', 'icon' => 'fa-house'],
                    ['name' => 'Personal Care', 'icon' => 'fa-pump-soap'],
                ],
            ],
        ];
    }
}

/**
 * Work out which business preset the installation is on.
 *
 * $moduleState  module_key => 0/1 (every module + station switch)
 * $frontEndState front-end flag key => 0/1/bool (e.g. events_page)
 * $storedKey    the preset key last applied (site_settings.business_preset), or null
 * $labels       optional key => human label map used for the diff labels
 *
 * Returns ['key' => string|null, 'exact' => bool, 'diffs' => [['module','label','preset','now'], ...]]
 * Rules: exact match wins (stored key preferred among several); else the stored
 * preset (customised); else the preset with the fewest differing switches.
 */
if (!function_exists('rh_detect_active_preset')) {
    function rh_detect_active_preset(array $moduleState, array $frontEndState, ?string $storedKey, array $labels = []): array
    {
        $presets = getBusinessPresets();
        $diffsFor = static function (array $preset) use ($moduleState, $frontEndState, $labels): array {
            $diffs = [];
            foreach ($preset['modules'] as $mk => $val) {
                $now = !empty($moduleState[$mk]) ? 1 : 0;
                if ($now !== (int)!empty($val)) {
                    $diffs[] = ['module' => (string)$mk, 'label' => (string)($labels[$mk] ?? $mk), 'preset' => (int)!empty($val), 'now' => $now];
                }
            }
            foreach (($preset['front_end'] ?? []) as $fk => $val) {
                if (!array_key_exists($fk, $frontEndState)) {
                    continue;
                }
                $now = !empty($frontEndState[$fk]) ? 1 : 0;
                if ($now !== (int)!empty($val)) {
                    $diffs[] = ['module' => (string)$fk, 'label' => (string)($labels[$fk] ?? $fk), 'preset' => (int)!empty($val), 'now' => $now];
                }
            }
            return $diffs;
        };

        $all = [];
        foreach ($presets as $k => $p) {
            $all[$k] = $diffsFor($p);
        }
        $exactKeys = array_keys(array_filter($all, static function ($d) { return !$d; }));
        if ($exactKeys) {
            $key = ($storedKey !== null && in_array($storedKey, $exactKeys, true)) ? $storedKey : $exactKeys[0];
            return ['key' => $key, 'exact' => true, 'diffs' => []];
        }
        if ($storedKey !== null && isset($presets[$storedKey])) {
            return ['key' => $storedKey, 'exact' => false, 'diffs' => $all[$storedKey]];
        }
        $best = null;
        foreach ($all as $k => $d) {
            if ($best === null || count($d) < count($all[$best])) {
                $best = $k;
            }
        }
        return ['key' => $best, 'exact' => false, 'diffs' => $best !== null ? $all[$best] : []];
    }
}
