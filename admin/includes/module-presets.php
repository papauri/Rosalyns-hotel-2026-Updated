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
                'front_end' => ['restaurant_page' => 1],
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
                'front_end' => ['restaurant_page' => 0],
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
                'front_end' => ['restaurant_page' => 1],
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
                'front_end' => ['restaurant_page' => 0],
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
                'front_end' => ['restaurant_page' => 0],
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
                'front_end' => ['restaurant_page' => 0],
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
                'front_end' => ['restaurant_page' => 0],
            ],
        ];
    }
}
