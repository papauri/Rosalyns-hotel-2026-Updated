<?php

/**
 * api/print-cloudprnt.php - Star CloudPRNT endpoint for receipt printers (no computer needed).
 *
 * Called by the PRINTER, not by a person, so there is no session and no CSRF token: there are no cookies for a
 * forged web page to ride on. Instead the printer's own URL carries ?p=<printer id>&t=<long random token>; the token
 * is compared with hash_equals, never logged, and the endpoint answers HTTPS requests only. A printer's poll rate and
 * bad-token attempts per address are rate limited (includes/printing.php: rh_print_device_gate).
 *
 * The address to paste into the printer is shown in admin/printers.php. All logic is in includes/printing.php.
 */

declare(strict_types=1);

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/printing.php';

rh_print_send(rh_print_handle_cloudprnt($pdo, rh_print_request_from_globals()));
