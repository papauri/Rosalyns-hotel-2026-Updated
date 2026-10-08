<?php

/**
 * admin/includes/admin-nav-items.php
 * The admin sidebar menu: ordered groups, each a list of items.
 * Each item: [page, icon, label, permission, inline style, module(s)]
 *   permission null = always visible; module null = not gated by a module.
 * Every page sits in the group for the part of the hotel it belongs to (no catch-all group).
 *
 * Used by admin-header.php (the sidebar) and by includes/guide-system-knowledge.php
 * (the staff guides knowledge base: "where do I find…" answers), so both read one list.
 */

function rh_admin_nav_groups(): array
{
    return [
        'Front Desk' => [
            ['dashboard.php',             'fas fa-tachometer-alt', 'Dashboard',        'dashboard',        '', null],
            ['bookings.php',              'fas fa-calendar-check', 'Bookings',         'bookings',         '', 'bookings'],
            ['calendar.php',              'fas fa-calendar',       'Calendar',         'calendar',         '', 'bookings'],
            ['individual-rooms.php',      'fas fa-door-open',      'Individual Rooms', 'rooms',            '', 'bookings'],
            ['blocked-dates.php',         'fas fa-ban',            'Blocked Dates',    'blocked_dates',    '', 'bookings'],
            ['housekeeping.php',          'fas fa-broom',          'Housekeeping',     'housekeeping',     '', 'housekeeping'],
            ['room-maintenance.php',      'fas fa-tools',          'Room Maintenance', 'room_maintenance', '', 'bookings'],
        ],
        'Conference & Events' => [
            ['conference-management.php', 'fas fa-briefcase',      'Conference',       'conference',       '', 'conference'],
            ['events-inquiries.php',      'fas fa-calendar-check', 'Event Bookings',   'events_bookings',  '', ['website_cms', 'events']],
            ['events-management.php',     'fas fa-calendar-alt',   'Events',           'events',           '', ['website_cms', 'events']],
        ],
        'Restaurant & Bar' => [
            ['pos.php',                    'fas fa-cash-register',  'POS Till',          'pos_till',          'color:#7E684B;', 'pos'],
            ['kds.php',                    'fas fa-utensils',       'Kitchen (KDS)',     'kds_view',          'color:#c82333;', ['pos', 'station_kds']],
            ['bds.php',                    'fas fa-cocktail',       'Bar Display (BDS)', 'bds_view',          'color:#5e35b1;', ['pos', 'station_bds']],
            ['cds.php',                    'fas fa-mug-hot',        'Coffee Bar (CDS)',  'cds_view',          'color:#6f4e37;', ['pos', 'station_cds']],
            ['room-service-dashboard.php', 'fas fa-bell-concierge', 'Room Service',      'room_service_view', 'color:#0c8d6c;', ['pos', 'station_room_service']],
            ['menu-management.php',        (function_exists('isRestaurantEnabled') && isRestaurantEnabled()) ? 'fas fa-utensils' : 'fas fa-box-open', (function_exists('isRestaurantEnabled') && isRestaurantEnabled()) ? 'Menu' : 'Products', 'menu', '', 'pos'],
            ['stock-orders.php',           'fas fa-receipt',        (function_exists('isRestaurantEnabled') && isRestaurantEnabled()) ? 'Orders & Tabs' : 'Orders', 'stock_orders', '', 'stock'],
            ['restaurant-tables.php',      'fas fa-chair',          'Tables',            'stock_management',  '', ['stock', 'restaurant_page']],
            ['deals.php',                  'fas fa-tags',           'Deals & Promos',    'stock_management',  '', 'pos'],
            ['kds-report.php',             'fas fa-file-invoice',   'Station Reports',   'kds_reports',       '', ['pos', 'restaurant_page']],
            ['station-settings.php',       'fas fa-clock',          'Station Hours',     'stock_management',  '', ['pos', 'restaurant_page']],
            ['offline-log.php',            'fas fa-cloud-arrow-up', 'Offline Log',       'offline_log_view',  '', 'pos'],
        ],
        'Stock' => [
            ['stock-dashboard.php',       'fas fa-boxes',           'Stock Overview',  'stock_dashboard',  '', 'stock'],
            ['stock-ingredients.php',     (function_exists('isRestaurantEnabled') && isRestaurantEnabled()) ? 'fas fa-carrot' : 'fas fa-boxes-stacked', (function_exists('isRestaurantEnabled') && isRestaurantEnabled()) ? 'Ingredients' : 'Stock Items', 'stock_management', '', 'stock'],
            ['stock-recipes.php',         'fas fa-book-open',       'Recipes',         'stock_management', '', ['stock', 'restaurant_page']],
            ['stock-barcode-receive.php', 'fas fa-barcode',         'Receive Stock',   'stock_management', '', 'stock'],
            ['stock-reorder.php',         'fas fa-cart-flatbed',    'Buying',          'stock_management', '', 'stock'],
            ['purchase-orders.php',       'fas fa-file-invoice',    'Purchase Orders', 'stock_management', '', 'stock'],
            ['stock-suppliers.php',       'fas fa-truck-field',     'Suppliers',       'stock_management', '', 'stock'],
            ['stock-count.php',           'fas fa-clipboard-check', 'Stock Count',     'stock_count',      '', 'stock'],
            ['stock-wastage.php',         'fas fa-trash-alt',       'Wastage',         'stock_wastage',    '', 'stock'],
            ['stock-batches.php',         'fas fa-layer-group',     'Batch Tracker',   'stock_batches',    '', 'stock'],
            ['stock-reports.php',         'fas fa-chart-area',      'Stock Reports',   'stock_reports',    '', 'stock'],
        ],
        'Money' => [
            ['accounting-dashboard.php', 'fas fa-calculator',          'Accounting',     'accounting',     '',               'finance'],
            ['payments.php',             'fas fa-money-bill-wave',     'Payments',       'payments',       '',               'finance'],
            ['payment-add.php',          'fas fa-plus-circle',         'Add Payment',    'payment_add',    '',               'finance'],
            ['receipts.php',             'fas fa-receipt',             'Receipts',       'receipts',       '',               'finance'],
            ['invoices.php',             'fas fa-file-invoice-dollar', 'Invoices',       'invoices',       '',               ['finance', 'billing']],
            ['quotations.php',           'fas fa-file-contract',       'Quotations',     'invoices',       '',               ['finance', 'billing']],
            ['credit-notes.php',         'fas fa-file-invoice',        'Credit Notes',   'invoices',       '',               ['finance', 'advance_booking']],
            ['pos-accounting.php',       'fas fa-cash-register',       'POS Accounting', 'pos_accounting', 'color:#7E684B;', ['finance', 'pos']],
            ['end-of-day-report.php',    'fas fa-sun',                 'End of Day',     'reports',        'color:#8F6A35;', 'finance'],
            ['reports.php',              'fas fa-chart-bar',           'Reports',        'reports',        '',               'finance'],
        ],
        'Gym' => [
            ['gym-members.php',    'fas fa-id-card',      'Members',   'gym',          '', 'gym'],
            ['gym-checkin.php',    'fas fa-barcode',      'Check-In',  'gym_checkin',  '', 'gym'],
            ['gym-inquiries.php',  'fas fa-inbox',        'Inquiries', 'gym',          '', 'gym'],
            ['gym-schedule.php',   'fas fa-calendar-day', 'Schedule',  'gym',          '', 'gym'],
            ['gym-classes.php',    'fas fa-people-group', 'Classes',   'gym',          '', 'gym'],
            ['gym-management.php', 'fas fa-dumbbell',     'Packages',  'gym_packages', '', 'gym'],
            ['gym-reports.php',    'fas fa-chart-line',   'Gym Reports', 'gym_reports',  '', 'gym'],
        ],
        'Website' => [
            ['reviews.php',                    'fas fa-star',        'Reviews',           'reviews',           '', 'website_cms'],
            ['contact-inquiries.php',          'fas fa-envelope',    'Contact Inquiries', 'contact',           '', 'website_cms'],
            ['gallery-management.php',         'fas fa-images',      'Gallery',           'gallery',           '', 'website_cms'],
            ['media-management.php',           'fas fa-photo-video', 'Media Portal',      'media_management',  '', 'website_cms'],
            ['page-management.php',            'fas fa-file-alt',    'Pages',             'pages',             '', 'website_cms'],
            ['section-headers-management.php', 'fas fa-heading',     'Section Headers',   'section_headers',   '', 'website_cms'],
            ['footer-management.php',          'fas fa-layer-group', 'Footer',            'footer_management', '', 'website_cms'],
            ['facebook-settings.php',          'fab fa-facebook-f',  'Facebook Settings', 'facebook_settings', 'color:#1877F2;', null],
            ['visitor-analytics.php',          'fas fa-chart-line',  'Visitor Analytics', 'visitor_analytics', '', null],
        ],
        'Settings' => [
            ['booking-settings.php',  'fas fa-cog',                'Hotel Settings',    'booking_settings',  '', null],
            ['room-management.php',   'fas fa-bed',                'Rooms',             'rooms',             '', 'bookings'],
            ['rate-plans.php',        'fas fa-tags',               'Rate Plans',        'booking_settings',  '', 'bookings'],
            ['packages.php',          'fas fa-gift',               'Room Packages',         'booking_settings',  '', 'bookings'],
            ['email-templates.php',   'fas fa-envelope-open-text', 'Email Templates',   'edit_templates',    '', null],
            ['automated-emails.php',  'fas fa-paper-plane',        'Automated Emails',  'booking_settings',  '', null],
            ['whatsapp-settings.php', 'fab fa-whatsapp',           'WhatsApp Settings', 'whatsapp_settings', 'color:#25D366;', null],
            ['user-management.php',   'fas fa-users-cog',          'Staff & Access',    'user_management',   '', null],
            ['module-settings.php',   'fas fa-puzzle-piece',       'Modules',           'module_settings',   '', null],
            ['backup-management.php', 'fas fa-database',           'Backups',           'backup_management', '', null],
            ['api-keys.php',          'fas fa-key',                'API Keys',          'api_keys',          '', null],
            ['system-logs.php',       'fas fa-clipboard-list',     'System Logs',       'system_logs',       '', null],
            ['cache-management.php',  'fas fa-bolt',               'Cache',             'cache',             '', null],
        ],
        'Help' => [
            ['../docs/guides/index.html',                         'fas fa-book-open',          'Staff Guides',        null, '', null],
            ['../docs/guides/99-admin-dashboard-full-guide.html', 'fas fa-scroll',             'Admin Reference',     null, '', null],
            ['../docs/guides/12-email-templates.php',             'fas fa-envelope-open-text', 'Email Template Tags', null, '', null],
        ],
    ];
}
