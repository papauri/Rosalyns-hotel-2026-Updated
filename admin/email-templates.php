<?php

/**
 * Email & PDF templates — their own page and menu item, so a person can be given the
 * template editor ("Edit message templates") without the rest of Hotel Settings.
 *
 * Renders booking-settings.php in templates-only mode: only the template editor is shown,
 * and only template POSTs (save, reset, preview, test send) are accepted. Anyone with
 * Hotel Settings access can still open it read-only, as before; saving needs
 * edit_templates (enforced in booking-settings.php).
 */
define('RH_TEMPLATES_PAGE', true);

require_once __DIR__ . '/admin-init.php';

if (!hasPermission((int)$user['id'], 'edit_templates') && !hasPermission((int)$user['id'], 'booking_settings')) {
    rhDenyAndRedirectHome((int)$user['id'], (string)($user['role'] ?? ''), 'email-templates.php');
}

require __DIR__ . '/booking-settings.php';
