<?php

/**
 * User Permissions Helper
 * Comprehensive Role-based Access Control (RBAC) for the admin panel
 *
 * Features:
 * - Multiple roles with customizable permissions
 * - Granular permission control
 * - Permission groups for easier management
 * - Activity logging
 * - Role templates
 *
 * @version 2.0.0
 */

// ============================================
// ROLE DEFINITIONS
// ============================================

/**
 * Get all available roles with their metadata
 */
function getAllRoles()
{
    return [
        'admin' => [
            'label' => 'Administrator',
            'description' => 'Full access to all features and settings',
            'icon' => 'fa-crown',
            'color' => '#d4a843',
            'level' => 100,
            'is_system' => true, // Cannot be deleted
            'permissions' => null // null = all permissions
        ],
        'manager' => [
            'label' => 'Manager',
            'description' => 'Manage operations, bookings, and staff activities',
            'icon' => 'fa-user-tie',
            'color' => '#17a2b8',
            'level' => 75,
            'is_system' => true,
            'permissions' => [
                'dashboard',
                'bookings',
                'calendar',
                'blocked_dates',
                'rooms',
                'room_maintenance',
                'housekeeping',
                'gallery',
                'media_management',
                'media_create',
                'media_edit',
                'conference',
                'conference_rooms',
                'conference_financials',
                'gym',
                'gym_packages',
                'gym_financials',
                'gym_checkin',
                'gym_reports',
                'gym_logs',
                'menu',
                'events',
                'events_bookings',
                'events_financials',
                'reviews',
                'contact',
                'accounting',
                'payments',
                'receipts',
                'invoices',
                'payment_add',
                'reports',
                'booking_settings',
                'visitor_analytics',
                'backup_management',
                'footer_management',
                'create_booking',
                'edit_booking',
                'cancel_booking',
                'checkin_guest',
                'checkout_guest',
                'room_dashboard',
                'individual_rooms',
                'block_rooms',
                'stock_dashboard',
                'stock_management',
                'stock_batches',
                'stock_orders',
                'pos_till',
                'pos_float',
                'pos_discount',
                'pos_86',
                'pos_refund',
                'pos_force_serve',
                'restaurant_table_settle',
                'kds_view',
                'stock_count',
                'stock_wastage',
                'stock_reports',
                'bds_view',
                'cds_view',
                'offline_log_view',
                'room_service_view',
                'room_service_manage',
                'kds_reports'
            ]
        ],
        'receptionist' => [
            'label' => 'Receptionist',
            'description' => 'Handle front desk operations and check-ins',
            'icon' => 'fa-concierge-bell',
            'color' => '#28a745',
            'level' => 50,
            'is_system' => true,
            'permissions' => [
                'dashboard',
                'bookings',
                'calendar',
                'blocked_dates',
                'rooms',
                'room_maintenance',
                'housekeeping',
                'reviews',
                'gym',
                'create_booking',
                'checkin_guest',
                'checkout_guest',
                'room_dashboard',
                'individual_rooms',
                'room_service_view',
                'room_service_manage'
            ]
        ],
        'housekeeping' => [
            'label' => 'Housekeeping',
            'description' => 'Manage room cleaning and maintenance tasks',
            'icon' => 'fa-broom',
            'color' => '#6c757d',
            'level' => 25,
            'is_system' => true,
            'permissions' => [
                'dashboard',
                'housekeeping',
                'room_maintenance',
                'room_dashboard',
                'individual_rooms'
            ]
        ],
        'accountant' => [
            'label' => 'Accountant',
            'description' => 'Manage financial records and invoices',
            'icon' => 'fa-calculator',
            'color' => '#fd7e14',
            'level' => 50,
            'is_system' => true,
            'permissions' => [
                'dashboard',
                'accounting',
                'pos_accounting',
                'payments',
                'receipts',
                'invoices',
                'payment_add',
                'reports',
                'bookings'
            ]
        ],
        'viewer' => [
            'label' => 'Viewer',
            'description' => 'Read-only access to view bookings and reports',
            'icon' => 'fa-eye',
            'color' => '#6c757d',
            'level' => 10,
            'is_system' => true,
            'permissions' => [
                'dashboard',
                'bookings',
                'calendar',
                'rooms',
                'reports'
            ]
        ],
        'restaurant_staff' => [
            'label' => 'Restaurant Staff',
            'description' => 'POS till operator — can only place orders, take payments and print/email receipts',
            'icon' => 'fa-cash-register',
            'color' => '#8B7355',
            'level' => 20,
            'is_system' => true,
            'permissions' => [
                'pos_till',
                'pos_float',
                'pos_discount',
                'stock_orders',
                'room_service_view',
                'room_service_manage'
            ]
        ],
        'chef' => [
            'label' => 'Chef / Kitchen',
            'description' => 'Kitchen Display System operator — view fired tickets and bump items as ready/served',
            'icon' => 'fa-utensils',
            'color' => '#c82333',
            'level' => 20,
            'is_system' => true,
            'permissions' => [
                'kds_view',
                'stock_orders',
                'kds_reports'
            ]
        ],
        'bar_staff' => [
            'label' => 'Bar Staff',
            'description' => 'Bar Display System operator — view fired drink tickets, mark as ready/served',
            'icon' => 'fa-cocktail',
            'color' => '#5e35b1',
            'level' => 20,
            'is_system' => true,
            'permissions' => [
                'bds_view',
                'stock_orders',
                'kds_reports'
            ]
        ],
        'coffee_staff' => [
            'label' => 'Coffee Bar Staff',
            'description' => 'Coffee Bar Display System operator — view fired coffee/tea tickets, mark as ready/served',
            'icon' => 'fa-mug-hot',
            'color' => '#6f4e37',
            'level' => 20,
            'is_system' => true,
            'permissions' => [
                'cds_view',
                'stock_orders',
                'kds_reports'
            ]
        ],
        'room_service' => [
            'label' => 'Room Service',
            'description' => 'In-room dining operator — place room service orders, charge to folio, and confirm delivery',
            'icon' => 'fa-bell-concierge',
            'color' => '#0c8d6c',
            'level' => 20,
            'is_system' => true,
            'permissions' => [
                'room_service_view',
                'room_service_manage',
                'pos_till',
                'stock_orders'
            ]
        ],
        'gym_staff' => [
            'label' => 'Gym Staff',
            'description' => 'Front-desk gym operator — view and respond to membership inquiries, cannot edit package pricing',
            'icon' => 'fa-dumbbell',
            'color' => '#c62828',
            'level' => 20,
            'is_system' => true,
            'permissions' => [
                'dashboard',
                'gym',
                'gym_checkin',
                'gym_reports'
            ]
        ],
        'conference_staff' => [
            'label' => 'Conference Staff',
            'description' => 'Handles conference bookings — confirm, cancel and complete enquiries, cannot edit rooms or invoicing',
            'icon' => 'fa-briefcase',
            'color' => '#5e35b1',
            'level' => 20,
            'is_system' => true,
            'permissions' => [
                'dashboard',
                'conference'
            ]
        ]
    ];
}

// ============================================
// PERMISSION DEFINITIONS
// ============================================

/**
 * Get all available permissions with metadata
 * Organized by category for UI display
 */
function getAllPermissions()
{
    return [
        // Core
        'dashboard' => [
            'label' => 'Dashboard',
            'description' => 'View the main dashboard',
            'icon' => 'fa-tachometer-alt',
            'category' => 'Core',
            'page' => 'dashboard.php',
            'group' => 'core'
        ],

        // Reservations
        'bookings' => [
            'label' => 'View Bookings',
            'description' => 'View booking list and details',
            'icon' => 'fa-calendar-check',
            'category' => 'Reservations',
            'page' => 'bookings.php',
            'group' => 'bookings_read'
        ],
        'create_booking' => [
            'label' => 'Create Bookings',
            'description' => 'Create new bookings manually',
            'icon' => 'fa-plus-circle',
            'category' => 'Reservations',
            'page' => 'create-booking.php',
            'group' => 'bookings_write'
        ],
        'edit_booking' => [
            'label' => 'Edit Bookings',
            'description' => 'Modify existing bookings',
            'icon' => 'fa-edit',
            'category' => 'Reservations',
            'page' => 'edit-booking.php',
            'group' => 'bookings_write'
        ],
        'quick_modify_booking' => [
            'label' => 'Quick Modify Bookings',
            'description' => 'Use quick modify actions from the bookings list',
            'icon' => 'fa-sliders-h',
            'category' => 'Reservations',
            'page' => 'bookings.php',
            'group' => 'bookings_write'
        ],
        'edit_booking_financials' => [
            'label' => 'Edit Booking Financials',
            'description' => 'Change booking amounts in quick modify and full edit screens',
            'icon' => 'fa-file-invoice-dollar',
            'category' => 'Reservations',
            'page' => 'edit-booking.php',
            'group' => 'bookings_write'
        ],
        'cancel_booking' => [
            'label' => 'Cancel Bookings',
            'description' => 'Cancel confirmed bookings',
            'icon' => 'fa-times-circle',
            'category' => 'Reservations',
            'page' => 'bookings.php',
            'group' => 'bookings_write'
        ],
        'calendar' => [
            'label' => 'Calendar View',
            'description' => 'View booking calendar',
            'icon' => 'fa-calendar',
            'category' => 'Reservations',
            'page' => 'calendar.php',
            'group' => 'bookings_read'
        ],
        'blocked_dates' => [
            'label' => 'Blocked Dates',
            'description' => 'Manage blocked/unavailable dates',
            'icon' => 'fa-ban',
            'category' => 'Reservations',
            'page' => 'blocked-dates.php',
            'group' => 'bookings_write'
        ],
        'block_rooms' => [
            'label' => 'Block Rooms',
            'description' => 'Block individual rooms for maintenance or hold',
            'icon' => 'fa-lock',
            'category' => 'Reservations',
            'page' => 'individual-rooms.php',
            'group' => 'bookings_write'
        ],

        // Guest Management
        'checkin_guest' => [
            'label' => 'Check-in Guests',
            'description' => 'Process guest check-ins',
            'icon' => 'fa-sign-in-alt',
            'category' => 'Guest Services',
            'page' => 'bookings.php',
            'group' => 'guest_services'
        ],
        'checkout_guest' => [
            'label' => 'Check-out Guests',
            'description' => 'Process guest check-outs',
            'icon' => 'fa-sign-out-alt',
            'category' => 'Guest Services',
            'page' => 'bookings.php',
            'group' => 'guest_services'
        ],

        // Property Management
        'rooms' => [
            'label' => 'Room Management',
            'description' => 'Manage room types, prices, and facilities',
            'icon' => 'fa-bed',
            'category' => 'Property',
            'page' => 'room-management.php',
            'group' => 'rooms_read'
        ],
        'individual_rooms' => [
            'label' => 'Individual Rooms',
            'description' => 'Manage individual room assignments',
            'icon' => 'fa-door-open',
            'category' => 'Property',
            'page' => 'individual-rooms.php',
            'group' => 'rooms_read'
        ],
        'room_dashboard' => [
            'label' => 'Room Dashboard',
            'description' => 'View room status and housekeeping dashboard',
            'icon' => 'fa-th-large',
            'category' => 'Property',
            'page' => 'room-dashboard.php',
            'group' => 'rooms_read'
        ],
        'room_maintenance' => [
            'label' => 'Room Maintenance',
            'description' => 'Manage room maintenance schedules',
            'icon' => 'fa-tools',
            'category' => 'Property',
            'page' => 'room-maintenance.php',
            'group' => 'rooms_write'
        ],
        'housekeeping' => [
            'label' => 'Housekeeping',
            'description' => 'Manage housekeeping assignments',
            'icon' => 'fa-broom',
            'category' => 'Property',
            'page' => 'housekeeping.php',
            'group' => 'housekeeping'
        ],
        'gallery' => [
            'label' => 'Gallery',
            'description' => 'Manage hotel gallery images',
            'icon' => 'fa-images',
            'category' => 'Property',
            'page' => 'gallery-management.php',
            'group' => 'media'
        ],
        'media_management' => [
            'label' => 'Media Portal',
            'description' => 'Access the centralized media portal',
            'icon' => 'fa-photo-video',
            'category' => 'Property',
            'page' => 'media-management.php',
            'group' => 'media'
        ],
        'media_create' => [
            'label' => 'Create Media',
            'description' => 'Upload and create media items',
            'icon' => 'fa-upload',
            'category' => 'Property',
            'page' => 'media-management.php',
            'group' => 'media_write'
        ],
        'media_edit' => [
            'label' => 'Edit Media',
            'description' => 'Edit media items',
            'icon' => 'fa-edit',
            'category' => 'Property',
            'page' => 'media-management.php',
            'group' => 'media_write'
        ],
        'media_delete' => [
            'label' => 'Delete Media',
            'description' => 'Delete media items',
            'icon' => 'fa-trash-alt',
            'category' => 'Property',
            'page' => 'media-management.php',
            'group' => 'media_write'
        ],
        'conference' => [
            'label' => 'Conference Bookings',
            'description' => 'View conference room page and handle day-to-day booking status (confirm, cancel, complete, notes)',
            'icon' => 'fa-briefcase',
            'category' => 'Property',
            'page' => 'conference-management.php',
            'group' => 'conference'
        ],
        'conference_rooms' => [
            'label' => 'Manage Conference Rooms',
            'description' => 'Add, edit, delete and toggle conference room facilities',
            'icon' => 'fa-door-open',
            'category' => 'Property',
            'page' => 'conference-management.php',
            'group' => 'conference'
        ],
        'conference_financials' => [
            'label' => 'Conference Invoicing & Pricing',
            'description' => 'Send conference invoices/quotations and edit booking amounts',
            'icon' => 'fa-file-invoice-dollar',
            'category' => 'Property',
            'page' => 'conference-management.php',
            'group' => 'conference'
        ],
        'gym' => [
            'label' => 'Gym Inquiries',
            'description' => 'View gym membership inquiries',
            'icon' => 'fa-dumbbell',
            'category' => 'Property',
            'page' => 'gym-inquiries.php',
            'group' => 'gym'
        ],
        'gym_packages' => [
            'label' => 'Manage Gym Packages',
            'description' => 'Add, edit and price gym membership packages',
            'icon' => 'fa-tags',
            'category' => 'Property',
            'page' => 'gym-management.php',
            'group' => 'gym'
        ],
        'gym_financials' => [
            'label' => 'Gym Invoicing & Pricing',
            'description' => 'Send gym membership invoices/quotations and edit booking amounts',
            'icon' => 'fa-file-invoice-dollar',
            'category' => 'Property',
            'page' => 'gym-inquiries.php',
            'group' => 'gym'
        ],
        'gym_checkin' => [
            'label' => 'Gym Check-In Scanner',
            'description' => 'Scan member barcodes to check members in and out of the gym',
            'icon' => 'fa-barcode',
            'category' => 'Property',
            'page' => 'gym-checkin.php',
            'group' => 'gym'
        ],
        'gym_reports' => [
            'label' => 'Gym Reports',
            'description' => 'Membership, attendance and outstanding-balance analytics for the gym',
            'icon' => 'fa-chart-line',
            'category' => 'Property',
            'page' => 'gym-reports.php',
            'group' => 'gym'
        ],
        'gym_logs' => [
            'label' => 'Gym Member History',
            'description' => 'View the full change/audit log for gym membership records (identity, pricing, status)',
            'icon' => 'fa-clock-rotate-left',
            'category' => 'Property',
            'page' => 'gym-members.php',
            'group' => 'gym'
        ],

        // Content Management
        'menu' => [
            'label' => 'Menu',
            'description' => 'Manage restaurant menu',
            'icon' => 'fa-utensils',
            'category' => 'Content',
            'page' => 'menu-management.php',
            'group' => 'content'
        ],

        // Restaurant & Stock Management
        'stock_dashboard' => [
            'label' => 'Stock Dashboard',
            'description' => 'View stock levels, low-stock and expiry alerts',
            'icon' => 'fa-boxes',
            'category' => 'Restaurant & Stock',
            'page' => 'stock-dashboard.php',
            'group' => 'stock_read'
        ],
        'stock_management' => [
            'label' => 'Ingredients & Recipes',
            'description' => 'Manage ingredients and recipes for menu items',
            'icon' => 'fa-carrot',
            'category' => 'Restaurant & Stock',
            'page' => 'stock-ingredients.php',
            'group' => 'stock_write'
        ],
        'stock_batches' => [
            'label' => 'Batch Tracker',
            'description' => 'Manage stock batches, expiry and supplier info',
            'icon' => 'fa-layer-group',
            'category' => 'Restaurant & Stock',
            'page' => 'stock-batches.php',
            'group' => 'stock_write'
        ],
        'stock_orders' => [
            'label' => 'Restaurant Orders (POS)',
            'description' => 'Place restaurant orders and deduct stock',
            'icon' => 'fa-receipt',
            'category' => 'Restaurant & Stock',
            'page' => 'stock-orders.php',
            'group' => 'stock_write'
        ],
        'pos_till' => [
            'label' => 'POS Till (Touch)',
            'description' => 'Modern touchscreen till for restaurant staff',
            'icon' => 'fa-cash-register',
            'category' => 'Stations',
            'page' => 'pos.php',
            'group' => 'stations'
        ],
        'pos_float' => [
            'label' => 'POS Opening Float',
            'description' => 'Declare an opening cash float at the start of a shift',
            'icon' => 'fa-coins',
            'category' => 'Stations',
            'page' => 'pos.php',
            'group' => 'stations'
        ],
        'pos_discount' => [
            'label' => 'POS Discounts',
            'description' => 'Apply a discount (% preset or custom amount) to POS orders and tab settlements',
            'icon' => 'fa-tag',
            'category' => 'Stations',
            'page' => 'pos.php',
            'group' => 'stations'
        ],
        'pos_86' => [
            'label' => 'POS Quick-86 Items',
            'description' => 'Toggle menu item availability (86\'d) directly from the POS till',
            'icon' => 'fa-ban',
            'category' => 'Stations',
            'page' => 'pos.php',
            'group' => 'stations'
        ],
        'pos_refund' => [
            'label' => 'POS Refunds',
            'description' => 'Process refunds on paid POS orders from the Recent panel',
            'icon' => 'fa-rotate-left',
            'category' => 'Stations',
            'page' => 'pos.php',
            'group' => 'stations'
        ],
        'pos_force_serve' => [
            'label' => 'POS Force-Serve Tab',
            'description' => 'Settle a tab whose kitchen items were never bumped (e.g. stranded from an earlier shift), forcing them to served so the tab can be paid',
            'icon' => 'fa-triangle-exclamation',
            'category' => 'Stations',
            'page' => 'pos.php',
            'group' => 'stations'
        ],
        'restaurant_table_settle' => [
            'label' => 'Settle Restaurant Tables',
            'description' => 'Take payment and close active dine-in tables directly from the restaurant tables page',
            'icon' => 'fa-hand-holding-dollar',
            'category' => 'Stations',
            'page' => 'restaurant-tables.php',
            'group' => 'stations'
        ],
        'kds_view' => [
            'label' => 'Kitchen Display (KDS)',
            'description' => 'Touchscreen kitchen display for chefs to bump items as ready/served',
            'icon' => 'fa-utensils',
            'category' => 'Stations',
            'page' => 'kds.php',
            'group' => 'stations'
        ],
        'bds_view' => [
            'label' => 'Bar Display (BDS)',
            'description' => 'Touchscreen bar display for bartenders to bump drink tickets as ready/served',
            'icon' => 'fa-cocktail',
            'category' => 'Stations',
            'page' => 'bds.php',
            'group' => 'stations'
        ],
        'cds_view' => [
            'label' => 'Coffee Bar Display (CDS)',
            'description' => 'Touchscreen display for the coffee bar — espresso, tea, hot drinks',
            'icon' => 'fa-mug-hot',
            'category' => 'Stations',
            'page' => 'cds.php',
            'group' => 'stations'
        ],
        'kds_reports' => [
            'label' => 'Station Reports (KDS/BDS/CDS)',
            'description' => 'View daily KDS/BDS/CDS production reports, export CSV, email end-of-day summary',
            'icon' => 'fa-file-chart-line',
            'category' => 'Stations',
            'page' => 'kds-report.php',
            'group' => 'stations'
        ],
        'room_service_view' => [
            'label' => 'Room Service Dashboard',
            'description' => 'View live in-room dining orders, delivery queue and folio sync status',
            'icon' => 'fa-bell-concierge',
            'category' => 'Stations',
            'page' => 'room-service-dashboard.php',
            'group' => 'stations'
        ],
        'room_service_manage' => [
            'label' => 'Manage Room Service',
            'description' => 'Place in-room dining orders, mark delivered, post charges to booking folios',
            'icon' => 'fa-utensils',
            'category' => 'Stations',
            'page' => 'room-service-dashboard.php',
            'group' => 'stations'
        ],
        'stock_count' => [
            'label' => 'Stock Count & Variance',
            'description' => 'Physical stock counts and theft / variance reconciliation',
            'icon' => 'fa-clipboard-check',
            'category' => 'Restaurant & Stock',
            'page' => 'stock-count.php',
            'group' => 'stock_write'
        ],
        'stock_wastage' => [
            'label' => 'Wastage Log',
            'description' => 'Record and review ingredient wastage',
            'icon' => 'fa-trash-alt',
            'category' => 'Restaurant & Stock',
            'page' => 'stock-wastage.php',
            'group' => 'stock_write'
        ],
        'stock_reports' => [
            'label' => 'Stock Reports',
            'description' => 'Inventory, usage, wastage, yield and expiry reports',
            'icon' => 'fa-chart-area',
            'category' => 'Restaurant & Stock',
            'page' => 'stock-reports.php',
            'group' => 'stock_read'
        ],
        'events' => [
            'label' => 'Events',
            'description' => 'Manage hotel events',
            'icon' => 'fa-calendar-alt',
            'category' => 'Content',
            'page' => 'events-management.php',
            'group' => 'content'
        ],
        'events_bookings' => [
            'label' => 'Event Bookings',
            'description' => 'View event RSVPs/bookings and handle day-to-day status (confirm, cancel, complete, notes)',
            'icon' => 'fa-calendar-check',
            'category' => 'Content',
            'page' => 'events-inquiries.php',
            'group' => 'content'
        ],
        'events_financials' => [
            'label' => 'Event Invoicing & Pricing',
            'description' => 'Send event booking invoices/quotations and edit booking amounts',
            'icon' => 'fa-file-invoice-dollar',
            'category' => 'Content',
            'page' => 'events-inquiries.php',
            'group' => 'content'
        ],
        'reviews' => [
            'label' => 'Reviews',
            'description' => 'Manage guest reviews',
            'icon' => 'fa-star',
            'category' => 'Content',
            'page' => 'reviews.php',
            'group' => 'content'
        ],
        'contact' => [
            'label' => 'Contact Inquiries',
            'description' => 'View and manage contact form submissions',
            'icon' => 'fa-envelope',
            'category' => 'Content',
            'page' => 'contact-inquiries.php',
            'group' => 'content'
        ],

        // Finance
        'accounting' => [
            'label' => 'Accounting',
            'description' => 'View accounting dashboard',
            'icon' => 'fa-calculator',
            'category' => 'Finance',
            'page' => 'accounting-dashboard.php',
            'group' => 'accounting_read'
        ],
        'pos_accounting' => [
            'label' => 'POS Accounting',
            'description' => 'Review POS sales and close cashier shifts from accounting',
            'icon' => 'fa-cash-register',
            'category' => 'Finance',
            'page' => 'pos-accounting.php',
            'group' => 'accounting_write'
        ],
        'payments' => [
            'label' => 'View Payments',
            'description' => 'View payment records',
            'icon' => 'fa-money-bill-wave',
            'category' => 'Finance',
            'page' => 'payments.php',
            'group' => 'payments_read'
        ],
        'receipts' => [
            'label' => 'Receipts',
            'description' => 'Generate, send, export and audit payment receipts',
            'icon' => 'fa-receipt',
            'category' => 'Finance',
            'page' => 'receipts.php',
            'group' => 'payments_read'
        ],
        'payment_add' => [
            'label' => 'Add Payments',
            'description' => 'Add new payment records',
            'icon' => 'fa-plus-circle',
            'category' => 'Finance',
            'page' => 'payment-add.php',
            'group' => 'payments_write'
        ],
        'invoices' => [
            'label' => 'Invoices',
            'description' => 'View and manage invoices',
            'icon' => 'fa-file-invoice-dollar',
            'category' => 'Finance',
            'page' => 'invoices.php',
            'group' => 'invoices'
        ],
        'reports' => [
            'label' => 'Reports',
            'description' => 'View financial and booking reports',
            'icon' => 'fa-chart-bar',
            'category' => 'Finance',
            'page' => 'reports.php',
            'group' => 'reports'
        ],
        'offline_log_view' => [
            'label' => 'Offline Activity Log',
            'description' => 'View offline-queued POS/KDS actions and when they synced back online',
            'icon' => 'fa-cloud-arrow-up',
            'category' => 'Stations',
            'page' => 'offline-log.php',
            'group' => 'stations'
        ],
        'system_logs' => [
            'label' => 'System Logs',
            'description' => 'View live operational, API, backup, cache, WhatsApp and error logs',
            'icon' => 'fa-clipboard-list',
            'category' => 'System',
            'page' => 'system-logs.php',
            'group' => 'system'
        ],
        'visitor_analytics' => [
            'label' => 'Visitor Analytics',
            'description' => 'View website visitor statistics',
            'icon' => 'fa-chart-line',
            'category' => 'Finance',
            'page' => 'visitor-analytics.php',
            'group' => 'analytics'
        ],

        // Settings
        'section_headers' => [
            'label' => 'Section Headers',
            'description' => 'Manage page section headers',
            'icon' => 'fa-heading',
            'category' => 'Settings',
            'page' => 'section-headers-management.php',
            'group' => 'settings_advanced'
        ],
        'footer_management' => [
            'label' => 'Footer Management',
            'description' => 'Manage footer links, policies, contact and social info',
            'icon' => 'fa-layer-group',
            'category' => 'Content',
            'page' => 'footer-management.php',
            'group' => 'content'
        ],
        'booking_settings' => [
            'label' => 'Booking Settings',
            'description' => 'Configure booking system settings',
            'icon' => 'fa-cog',
            'category' => 'Settings',
            'page' => 'booking-settings.php',
            'group' => 'settings'
        ],
        'module_settings' => [
            'label' => 'Module Settings',
            'description' => 'Enable/disable modules and apply business presets for the whole installation',
            'icon' => 'fa-puzzle-piece',
            'category' => 'Settings',
            'page' => 'module-settings.php',
            'group' => 'settings_advanced'
        ],
        'pages' => [
            'label' => 'Page Management',
            'description' => 'Enable, disable and reorder website pages',
            'icon' => 'fa-file-alt',
            'category' => 'Settings',
            'page' => 'page-management.php',
            'group' => 'settings_advanced'
        ],
        'cache' => [
            'label' => 'Cache Management',
            'description' => 'Manage website cache',
            'icon' => 'fa-bolt',
            'category' => 'Settings',
            'page' => 'cache-management.php',
            'group' => 'settings_advanced'
        ],
        'backup_management' => [
            'label' => 'Backup Management',
            'description' => 'Run, download and restore database backups',
            'icon' => 'fa-database',
            'category' => 'Settings',
            'page' => 'backup-management.php',
            'group' => 'settings_advanced'
        ],
        'api_keys' => [
            'label' => 'API Keys',
            'description' => 'Manage API access keys',
            'icon' => 'fa-key',
            'category' => 'Settings',
            'page' => 'api-keys.php',
            'group' => 'settings_advanced'
        ],
        'whatsapp_settings' => [
            'label' => 'WhatsApp Settings',
            'description' => 'Configure WhatsApp integration',
            'icon' => 'fa-whatsapp',
            'category' => 'Settings',
            'page' => 'whatsapp-settings.php',
            'group' => 'settings'
        ],
        'facebook_settings' => [
            'label' => 'Facebook Settings',
            'description' => 'Configure Facebook Page posting',
            'icon' => 'fa-facebook-f',
            'category' => 'Settings',
            'page' => 'facebook-settings.php',
            'group' => 'settings'
        ],

        // User Management
        'user_management' => [
            'label' => 'User Management',
            'description' => 'Access user management page',
            'icon' => 'fa-users-cog',
            'category' => 'Administration',
            'page' => 'user-management.php',
            'group' => 'users_management'
        ],
        'user_create' => [
            'label' => 'Create Users',
            'description' => 'Create new admin users',
            'icon' => 'fa-user-plus',
            'category' => 'Administration',
            'page' => 'user-management.php',
            'group' => 'users_management'
        ],
        'user_edit' => [
            'label' => 'Edit Users',
            'description' => 'Edit existing admin users',
            'icon' => 'fa-user-edit',
            'category' => 'Administration',
            'page' => 'user-management.php',
            'group' => 'users_management'
        ],
        'user_delete' => [
            'label' => 'Delete Users',
            'description' => 'Delete admin users',
            'icon' => 'fa-user-minus',
            'category' => 'Administration',
            'page' => 'user-management.php',
            'group' => 'users_management'
        ],
        'user_permissions' => [
            'label' => 'Assign Permissions',
            'description' => 'Grant/revoke permissions for users',
            'icon' => 'fa-shield-alt',
            'category' => 'Administration',
            'page' => 'user-management.php',
            'group' => 'users_management'
        ]
    ];
}

/**
 * Get permission groups for organizing permissions in UI
 */
function getPermissionGroups()
{
    return [
        'core' => ['label' => 'Core Access', 'description' => 'Basic system access'],
        'bookings_read' => ['label' => 'View Bookings', 'description' => 'Read-only booking access'],
        'bookings_write' => ['label' => 'Manage Bookings', 'description' => 'Create, edit, cancel bookings'],
        'guest_services' => ['label' => 'Guest Services', 'description' => 'Check-in and check-out'],
        'rooms_read' => ['label' => 'View Rooms', 'description' => 'View room information'],
        'rooms_write' => ['label' => 'Manage Rooms', 'description' => 'Edit room settings'],
        'housekeeping' => ['label' => 'Housekeeping', 'description' => 'Cleaning and maintenance'],
        'media' => ['label' => 'View Media', 'description' => 'Access media library'],
        'media_write' => ['label' => 'Manage Media', 'description' => 'Upload, edit, delete media'],
        'content' => ['label' => 'Content Management', 'description' => 'Menu, events, reviews'],
        'accounting_read' => ['label' => 'View Accounting', 'description' => 'Read-only financial access'],
        'payments_read' => ['label' => 'View Payments', 'description' => 'View payment records'],
        'payments_write' => ['label' => 'Manage Payments', 'description' => 'Add and edit payments'],
        'invoices' => ['label' => 'Invoices', 'description' => 'Invoice management'],
        'reports' => ['label' => 'Reports', 'description' => 'Financial and analytics reports'],
        'analytics' => ['label' => 'Analytics', 'description' => 'Visitor statistics'],
        'system' => ['label' => 'System Logs', 'description' => 'Operational and sync logs'],
        'stations' => ['label' => 'Stations', 'description' => 'POS, KDS, BDS, CDS, Room Service station access'],
        'settings' => ['label' => 'Basic Settings', 'description' => 'Common configuration'],
        'settings_advanced' => ['label' => 'Advanced Settings', 'description' => 'System configuration'],
        'users_management' => ['label' => 'User Management', 'description' => 'Create, edit, delete users and manage permissions'],
        'conference' => ['label' => 'Conference', 'description' => 'Conference room management'],
        'gym' => ['label' => 'Gym', 'description' => 'Gym inquiries']
    ];
}

// ============================================
// PERMISSION CHECKING FUNCTIONS
// ============================================

/**
 * Get the default permissions for a specific role
 */
function getDefaultPermissionsForRole(string $role)
{
    $roles = getAllRoles();

    if (!isset($roles[$role])) {
        return ['dashboard']; // Minimal access for unknown roles
    }

    $roleData = $roles[$role];

    // null means all permissions (admin)
    if ($roleData['permissions'] === null) {
        return array_keys(getAllPermissions());
    }

    return $roleData['permissions'];
}

/**
 * Check if a user has a specific permission
 * Admin role always has all permissions
 */
function hasPermission(int $user_id, string $permission_key)
{
    global $pdo;

    // Validate permission key exists
    $all_permissions = getAllPermissions();
    if (!isset($all_permissions[$permission_key])) {
        error_log("Invalid permission key: {$permission_key}");
        return false;
    }

    try {
        // Get user role and status
        $stmt = $pdo->prepare("SELECT role, is_active FROM admin_users WHERE id = ?");
        $stmt->execute([$user_id]);
        $user = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$user || !$user['is_active']) {
            return false;
        }

        $role = $user['role'];

        // Admin always has all permissions
        if ($role === 'admin') {
            return true;
        }

        // Check user_permissions table for explicit grant/deny
        try {
            $stmt = $pdo->prepare("
                SELECT is_granted FROM user_permissions
                WHERE user_id = ? AND permission_key = ?
            ");
            $stmt->execute([$user_id, $permission_key]);
            $result = $stmt->fetch(PDO::FETCH_ASSOC);

            if ($result !== false) {
                return (bool)$result['is_granted'];
            }
        } catch (PDOException $e) {
            // Table doesn't exist yet - fall through to role defaults
        }

        // No explicit permission set - use role defaults
        $defaults = getDefaultPermissionsForRole($role);
        return in_array($permission_key, $defaults);
    } catch (PDOException $e) {
        error_log("Permission check error: " . $e->getMessage());
        return false;
    }
}

/**
 * Check if user has ANY of the specified permissions
 */
function hasAnyPermission(int $user_id, array $permission_keys)
{
    foreach ($permission_keys as $perm) {
        if (hasPermission($user_id, $perm)) {
            return true;
        }
    }
    return false;
}

/**
 * Check if user has ALL of the specified permissions
 */
function hasAllPermissions(int $user_id, array $permission_keys)
{
    foreach ($permission_keys as $perm) {
        if (!hasPermission($user_id, $perm)) {
            return false;
        }
    }
    return true;
}

/**
 * Get all permissions for a specific user
 * Returns array of permission_key => is_granted
 */
function getUserPermissions(int $user_id)
{
    global $pdo;
    $all_permissions = getAllPermissions();
    $result = [];

    try {
        // Get user role
        $stmt = $pdo->prepare("SELECT role, is_active FROM admin_users WHERE id = ?");
        $stmt->execute([$user_id]);
        $user = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$user || !$user['is_active']) {
            // Return empty permissions for inactive/non-existent users
            foreach ($all_permissions as $key => $info) {
                $result[$key] = false;
            }
            return $result;
        }

        $role = $user['role'];

        // Admin always has everything
        if ($role === 'admin') {
            foreach ($all_permissions as $key => $info) {
                $result[$key] = true;
            }
            return $result;
        }

        // Get explicit permissions (table may not exist yet)
        $explicit = [];
        try {
            $stmt = $pdo->prepare("SELECT permission_key, is_granted FROM user_permissions WHERE user_id = ?");
            $stmt->execute([$user_id]);
            $explicit = $stmt->fetchAll(PDO::FETCH_KEY_PAIR);
        } catch (PDOException $e) {
            // Table doesn't exist yet - use role defaults only
        }

        // Get role defaults
        $defaults = getDefaultPermissionsForRole($role);

        // Merge: explicit overrides defaults
        foreach ($all_permissions as $key => $info) {
            if (isset($explicit[$key])) {
                $result[$key] = (bool)$explicit[$key];
            } else {
                $result[$key] = in_array($key, $defaults);
            }
        }
    } catch (PDOException $e) {
        error_log("Get permissions error: " . $e->getMessage());
        $defaults = getDefaultPermissionsForRole('viewer');
        foreach ($all_permissions as $key => $info) {
            $result[$key] = in_array($key, $defaults);
        }
    }

    return $result;
}

// ============================================
// PERMISSION MANAGEMENT FUNCTIONS
// ============================================

/**
 * Set permissions for a user
 * $permissions = ['bookings' => true, 'accounting' => false, ...]
 */
function setUserPermissions(int $user_id, array $permissions, ?int $granted_by = null)
{
    global $pdo;

    try {
        // Don't allow modifying admin permissions
        $stmt = $pdo->prepare("SELECT role FROM admin_users WHERE id = ?");
        $stmt->execute([$user_id]);
        $role = $stmt->fetchColumn();

        if ($role === 'admin') {
            return false; // Admin permissions cannot be changed
        }

        $stmt = $pdo->prepare("
            INSERT INTO user_permissions (user_id, permission_key, is_granted, granted_by)
            VALUES (?, ?, ?, ?)
            ON DUPLICATE KEY UPDATE is_granted = VALUES(is_granted), granted_by = VALUES(granted_by), updated_at = NOW()
        ");

        foreach ($permissions as $key => $granted) {
            $stmt->execute([$user_id, $key, $granted ? 1 : 0, $granted_by]);
        }

        // Log the permission change
        logPermissionChange($user_id, $permissions, $granted_by);

        return true;
    } catch (PDOException $e) {
        error_log("Set permissions error: " . $e->getMessage());
        return false;
    }
}

/**
 * Reset user permissions to role defaults
 */
function resetUserPermissionsToDefault(int $user_id, ?int $reset_by = null)
{
    global $pdo;

    try {
        // Get user role
        $stmt = $pdo->prepare("SELECT role FROM admin_users WHERE id = ?");
        $stmt->execute([$user_id]);
        $role = $stmt->fetchColumn();

        if ($role === 'admin') {
            return false;
        }

        // Delete all explicit permissions
        $stmt = $pdo->prepare("DELETE FROM user_permissions WHERE user_id = ?");
        $stmt->execute([$user_id]);

        // Log the reset
        if ($reset_by) {
            logActivity($reset_by, 'permissions_reset', "Reset permissions for user ID {$user_id} to role defaults");
        }

        return true;
    } catch (PDOException $e) {
        error_log("Reset permissions error: " . $e->getMessage());
        return false;
    }
}

/**
 * Log permission changes for audit trail
 */
function logPermissionChange(int $user_id, array $permissions, ?int $changed_by)
{
    if (!$changed_by) return;

    $granted = array_keys(array_filter($permissions));
    $revoked = array_keys(array_filter($permissions, function (mixed $v) {
        return !$v;
    }));

    $details = [];
    if (!empty($granted)) $details[] = "Granted: " . implode(', ', $granted);
    if (!empty($revoked)) $details[] = "Revoked: " . implode(', ', $revoked);

    logActivity($changed_by, 'permissions_changed', "Updated permissions for user ID {$user_id}: " . implode('; ', $details));
}

/**
 * Log activity to admin_activity_log
 */
function logActivity(int $user_id, string $action, string $details = '')
{
    global $pdo;

    try {
        $stmt = $pdo->prepare("
            INSERT INTO admin_activity_log (user_id, action, details, ip_address, created_at)
            VALUES (?, ?, ?, ?, NOW())
        ");
        $stmt->execute([
            $user_id,
            $action,
            $details,
            $_SERVER['REMOTE_ADDR'] ?? ''
        ]);
    } catch (PDOException $e) {
        error_log("Activity log error: " . $e->getMessage());
    }
}

// ============================================
// ACCESS CONTROL FUNCTIONS
// ============================================

/**
 * Check if current page requires permission and redirect if not allowed
 */
function requirePermission(string $permission_key)
{
    if (!isset($_SESSION['admin_user_id'])) {
        header('Location: login.php');
        exit;
    }

    if (!hasPermission($_SESSION['admin_user_id'], $permission_key)) {
        header('Location: dashboard.php?error=access_denied');
        exit;
    }
}

/**
 * Map a page filename to its permission key
 */
function getPermissionForPage(string $page)
{
    $map = [
        'dashboard.php' => 'dashboard',
        'bookings.php' => 'bookings',
        'booking-details.php' => 'bookings',
        'create-booking.php' => 'create_booking',
        'tentative-bookings.php' => 'bookings',
        'calendar.php' => 'calendar',
        'blocked-dates.php' => 'blocked_dates',
        'room-management.php' => 'rooms',
        'individual-rooms.php' => 'individual_rooms',
        'room-dashboard.php' => 'room_dashboard',
        'room-maintenance.php' => 'room_maintenance',
        'housekeeping.php' => 'housekeeping',
        'gallery-management.php' => 'gallery',
        'media-management.php' => 'media_management',
        'conference-management.php' => 'conference',
        'gym-packages.php' => 'gym_packages',
        'gym-inquiries.php' => 'gym',
        'gym-management.php' => 'gym_packages',
        'menu-management.php' => 'menu',
        'stock-dashboard.php' => 'stock_dashboard',
        'stock-ingredients.php' => 'stock_management',
        'stock-recipes.php' => 'stock_management',
        'stock-batches.php' => 'stock_batches',
        'stock-orders.php' => 'stock_orders',
        'stock-receipt.php' => 'stock_orders',
        'order-lifecycle.php' => 'stock_orders',
        'station-settings.php' => 'stock_management',
        'restaurant-tables.php' => 'stock_management',
        'deals.php' => 'stock_management',
        'pos.php' => 'pos_till',
        'kds.php' => 'kds_view',
        'bds.php' => 'bds_view',
        'cds.php' => 'cds_view',
        'kds-report.php' => 'kds_reports',
        'room-service-dashboard.php' => 'room_service_view',
        'stock-count.php' => 'stock_count',
        'stock-wastage.php' => 'stock_wastage',
        'stock-reports.php' => 'stock_reports',
        'events-management.php' => 'events',
        'events-inquiries.php' => 'events_bookings',
        'reviews.php' => 'reviews',
        'contact-inquiries.php' => 'contact',
        'accounting-dashboard.php' => 'accounting',
        'payments.php' => 'payments',
        'payment-details.php' => 'payments',
        'receipts.php' => 'receipts',
        'invoices.php' => 'invoices',
        'credit-notes.php' => 'invoices',
        'payment-add.php' => 'payment_add',
        'payment-refund.php' => 'payment_add',
        'edit-booking.php' => 'edit_booking',
        'reports.php' => 'reports',
        'end-of-day-report.php' => 'reports',
        'offline-log.php' => 'offline_log_view',
        'system-logs.php' => 'system_logs',
        'visitor-analytics.php' => 'visitor_analytics',
        'pos-accounting.php' => 'pos_accounting',
        'shift-close-report.php' => 'pos_accounting',
        'pos-drift-report.php' => 'pos_accounting',
        'quotations.php' => 'create_booking',
        'gym-members.php' => 'gym',
        'gym-checkin.php' => 'gym_checkin',
        'gym-schedule.php' => 'gym',
        'gym-reports.php' => 'gym_reports',
        'section-headers-management.php' => 'section_headers',
        'footer-management.php'         => 'footer_management',
        'booking-settings.php' => 'booking_settings',
        'module-settings.php'  => 'module_settings',
        'rate-plans.php'       => 'booking_settings',
        'packages.php'         => 'booking_settings',
        'stock-barcode-receive.php' => 'stock_management',
        'page-management.php' => 'pages',
        'cache-management.php' => 'cache',
        'backup-management.php' => 'backup_management',
        'api-keys.php' => 'api_keys',
        'whatsapp-settings.php' => 'whatsapp_settings',
        'facebook-settings.php' => 'facebook_settings',
        'user-management.php' => 'user_management',
        'process-checkin.php' => 'bookings',
    ];

    return $map[$page] ?? null;
}

/**
 * Map a page filename to the module key(s) that must be enabled to access it.
 * Returns null when the page isn't gated by any module (always available).
 * Returns a string for a single required module, or an array when more than
 * one module key must all be enabled (e.g. a station page needs 'pos' AND
 * its own station sub-module).
 * Kept in sync with the moduleKey column in admin-header.php's $_nav_groups.
 */
function getModuleForPage(string $page)
{
    $map = [
        'bookings.php' => 'bookings',
        'booking-details.php' => 'bookings',
        'create-booking.php' => 'bookings',
        'edit-booking.php' => 'bookings',
        'tentative-bookings.php' => 'bookings',
        'process-checkin.php' => 'bookings',
        'calendar.php' => 'bookings',
        'blocked-dates.php' => 'bookings',
        'rate-plans.php' => 'bookings',
        'packages.php' => 'bookings',
        'room-management.php' => 'bookings',
        'individual-rooms.php' => 'bookings',
        'room-maintenance.php' => 'bookings',
        'room-dashboard.php' => 'bookings',
        'housekeeping.php' => 'housekeeping',
        'pos.php' => 'pos',
        'menu-management.php' => 'pos',
        'kds-report.php' => ['pos', 'restaurant_page'],
        'station-settings.php' => ['pos', 'restaurant_page'],
        'deals.php' => 'pos',
        'offline-log.php' => 'pos',
        'kds.php' => ['pos', 'station_kds'],
        'bds.php' => ['pos', 'station_bds'],
        'cds.php' => ['pos', 'station_cds'],
        'room-service-dashboard.php' => ['pos', 'station_room_service'],
        'stock-dashboard.php' => 'stock',
        'stock-ingredients.php' => 'stock',
        'stock-recipes.php' => ['stock', 'restaurant_page'],
        'stock-batches.php' => 'stock',
        'stock-orders.php' => 'stock',
        'stock-receipt.php' => 'stock',
        'order-lifecycle.php' => 'stock',
        'restaurant-tables.php' => ['stock', 'restaurant_page'],
        'stock-barcode-receive.php' => 'stock',
        'stock-count.php' => 'stock',
        'stock-wastage.php' => 'stock',
        'stock-reports.php' => 'stock',
        'conference-management.php' => 'conference',
        'gym-management.php' => 'gym',
        'gym-packages.php' => 'gym',
        'gym-inquiries.php' => 'gym',
        'gallery-management.php' => 'website_cms',
        'media-management.php' => 'website_cms',
        'events-management.php' => ['website_cms', 'events'],
        'events-inquiries.php' => ['website_cms', 'events'],
        'reviews.php' => 'website_cms',
        'contact-inquiries.php' => 'website_cms',
        'footer-management.php' => 'website_cms',
        'page-management.php' => 'website_cms',
        'section-headers-management.php' => 'website_cms',
        'accounting-dashboard.php' => 'finance',
        'pos-accounting.php' => ['finance', 'pos'],
        'shift-close-report.php' => ['finance', 'pos'],
        'pos-drift-report.php' => ['finance', 'pos'],
        'payments.php' => 'finance',
        'payment-details.php' => 'finance',
        'payment-refund.php' => 'finance',
        'payment-add.php' => 'finance',
        'receipts.php' => 'finance',
        'invoices.php' => ['finance', 'billing'],
        'credit-notes.php' => ['finance', 'advance_booking'],
        'quotations.php' => ['finance', 'billing'],
        'gym-members.php' => 'gym',
        'gym-checkin.php' => 'gym',
        'gym-schedule.php' => 'gym',
        'gym-reports.php' => 'gym',
        'reports.php' => 'finance',
        'end-of-day-report.php' => 'finance',

        // ── Gaps closed 2026-09-02 ────────────────────────────────────────
        // These pages belong to a gated module but were absent from the map,
        // so they stayed reachable by direct URL when that module was off —
        // the nav link disappeared but the page did not. Every other page of
        // each module was already mapped; these were simply missed.
        'gym-classes.php' => 'gym',
        'purchase-orders.php' => 'stock',
        'stock-reorder.php' => 'stock',
        'stock-suppliers.php' => 'stock',
        // enabled_modules describes website_cms as "Gallery, pages, deals,
        // reviews, social settings" — the social integrations and site
        // analytics belong to it on the module's own definition.
        'facebook-settings.php' => 'website_cms',
        'whatsapp-settings.php' => 'website_cms',
        'visitor-analytics.php' => 'website_cms',

        // DELIBERATELY NOT MAPPED — do not "complete" this list without a decision:
        //   booking-settings.php — looks like a 'bookings' page, but it is also the
        //     ONLY page that edits SMTP/email settings (updateEmailSetting is called
        //     nowhere else). Gating it behind 'bookings' would lock an operator out
        //     of all email configuration whenever bookings is disabled.
        //   dashboard, login, logout, index, admin-init, manifest, change/forgot/
        //     reset-password, module-settings, system-logs, api-keys, user-management,
        //     backup-management, cache-management, ajax-receipt, video-upload-handler
        //     — platform pages that must stay reachable regardless of module state
        //     (module-settings especially: it is how a disabled module gets re-enabled).
    ];

    return $map[$page] ?? null;
}

/**
 * The 'pos' module is on for any till-based business (retail, gym snack bar,
 * supermarket checkout) — but some pages are meaningful only when there's an
 * actual food/beverage service (dine-in tables, recipes/food-cost, station
 * hours, station production reports). Those pages use this synthetic
 * "restaurant_page" key instead of/alongside 'pos' — checked here rather
 * than via moduleEnabled() since it's driven by isRestaurantEnabled(),
 * not the enabled_modules table.
 */
function rh_module_key_enabled(string $key): bool
{
    if ($key === 'restaurant_page') {
        return function_exists('isRestaurantEnabled') && isRestaurantEnabled();
    }
    if ($key === 'events') {
        // Events is preset-scoped via the events_page front_end flag
        // (events_system_enabled setting): hotels/conference/gym run events,
        // supermarket/retail/bar do not.
        return function_exists('isEventsEnabled') && isEventsEnabled();
    }
    if ($key === 'advance_booking') {
        // Accounts-receivable businesses only (rooms, conferences): deposits,
        // balances due, credit notes against future stays/events. A gym,
        // supermarket, retail shop or bar refunds at source (POS refund)
        // instead of issuing credit notes.
        if (!function_exists('moduleEnabled')) {
            return true;
        }
        return moduleEnabled('bookings') || moduleEnabled('conference');
    }
    if ($key === 'billing') {
        // "Billing businesses" — anyone who invoices/quotes a named client in
        // advance: rooms, conferences, gym memberships. Pure till businesses
        // (supermarket, retail, bar/restaurant) sell at the point of sale:
        // receipts + POS refunds, no invoice/quotation register. Events is
        // deliberately excluded: the events feature is preset-independent
        // (rides the legacy setting, on for every preset), and its per-booking
        // invoice/quotation actions live inside events-inquiries.php itself —
        // counting it here would put the invoice register back on every
        // supermarket.
        if (!function_exists('moduleEnabled')) {
            return true;
        }
        return moduleEnabled('bookings') || moduleEnabled('conference') || moduleEnabled('gym');
    }
    return function_exists('moduleEnabled') && moduleEnabled($key);
}

/**
 * Get all permission keys whose pages belong to the given module.
 * Inverse of getModuleForPage() — used to work out which permissions
 * (and therefore which users) are affected when a module is disabled.
 */
function getPermissionsForModule(string $module): array
{
    $matches = [];
    foreach (getAllPermissions() as $permKey => $info) {
        $page = $info['page'] ?? '';
        if ($page === '') {
            continue;
        }
        $requiredModule = getModuleForPage($page);
        if ($requiredModule === null) {
            continue;
        }
        $requiredModuleKeys = is_array($requiredModule) ? $requiredModule : [$requiredModule];
        if (in_array($module, $requiredModuleKeys, true)) {
            $matches[] = $permKey;
        }
    }
    return $matches;
}

// ============================================
// NAVIGATION HELPERS
// ============================================

/**
 * Get the allowed navigation items for a user
 * Returns filtered array of nav items the user can access
 */
function getNavItemsForUser(int $user_id)
{
    $permissions = getUserPermissions($user_id);
    $all_permissions = getAllPermissions();
    $nav_items = [];

    foreach ($all_permissions as $key => $info) {
        if (isset($permissions[$key]) && $permissions[$key]) {
            $nav_items[] = [
                'key' => $key,
                'label' => $info['label'],
                'icon' => $info['icon'],
                'page' => $info['page'],
                'category' => $info['category']
            ];
        }
    }

    return $nav_items;
}

/**
 * Get navigation categories for organizing menu
 */
function getNavCategories()
{
    return [
        'Core' => ['order' => 1, 'icon' => 'fa-home'],
        'Reservations' => ['order' => 2, 'icon' => 'fa-calendar-check'],
        'Guest Services' => ['order' => 3, 'icon' => 'fa-concierge-bell'],
        'Property' => ['order' => 4, 'icon' => 'fa-building'],
        'Content' => ['order' => 5, 'icon' => 'fa-file-alt'],
        'Restaurant & Stock' => ['order' => 6, 'icon' => 'fa-boxes'],
        'Stations' => ['order' => 7, 'icon' => 'fa-display'],
        'Finance' => ['order' => 8, 'icon' => 'fa-dollar-sign'],
        'System' => ['order' => 9, 'icon' => 'fa-server'],
        'Settings' => ['order' => 10, 'icon' => 'fa-cog'],
        'Administration' => ['order' => 11, 'icon' => 'fa-shield-alt']
    ];
}

// ============================================
// USER ROLE HELPERS
// ============================================

/**
 * Check if a user's role level is at least a certain level
 */
function hasRoleLevel(int $user_id, int $minimum_level)
{
    global $pdo;

    try {
        $stmt = $pdo->prepare("SELECT role FROM admin_users WHERE id = ?");
        $stmt->execute([$user_id]);
        $role = $stmt->fetchColumn();

        $roles = getAllRoles();
        if (!isset($roles[$role])) {
            return false;
        }

        return $roles[$role]['level'] >= $minimum_level;
    } catch (PDOException $e) {
        return false;
    }
}

/**
 * Get user's role information
 */
function getUserRole(int $user_id)
{
    global $pdo;

    try {
        $stmt = $pdo->prepare("SELECT role FROM admin_users WHERE id = ?");
        $stmt->execute([$user_id]);
        $role = $stmt->fetchColumn();

        $roles = getAllRoles();
        return $roles[$role] ?? null;
    } catch (PDOException $e) {
        return null;
    }
}

/**
 * Check if current user can manage target user
 * (based on role levels)
 */
function canManageUser(int $manager_id, int $target_id)
{
    global $pdo;

    // Can't manage yourself
    if ($manager_id == $target_id) {
        return false;
    }

    try {
        $stmt = $pdo->prepare("SELECT role FROM admin_users WHERE id = ?");
        $stmt->execute([$manager_id]);
        $manager_role = $stmt->fetchColumn();

        $stmt->execute([$target_id]);
        $target_role = $stmt->fetchColumn();

        $roles = getAllRoles();

        // Admins can manage everyone
        if ($manager_role === 'admin') {
            return true;
        }

        // Check role levels
        $manager_level = $roles[$manager_role]['level'] ?? 0;
        $target_level = $roles[$target_role]['level'] ?? 0;

        return $manager_level > $target_level;
    } catch (PDOException $e) {
        return false;
    }
}

/**
 * Get count of users by role
 */
function getUserCountByRole()
{
    global $pdo;

    try {
        $stmt = $pdo->query("
            SELECT role, COUNT(*) as count
            FROM admin_users
            WHERE is_active = 1
            GROUP BY role
        ");
        return $stmt->fetchAll(PDO::FETCH_KEY_PAIR);
    } catch (PDOException $e) {
        return [];
    }
}

