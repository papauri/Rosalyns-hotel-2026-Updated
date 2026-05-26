<?php
/**
 * Base URL Override
 * 
 * This file sets a manual override for BASE_URL to prevent issues with
 * auto-detection. If your site is at:
 * - https://example.com/ → set to 'https://example.com/'
 * - https://example.com/rosalyns/ → set to 'https://example.com/rosalyns/'
 * 
 * IMPORTANT: Do NOT include /admin/ in the BASE_URL.
 * The BASE_URL should point to the ROOT of your website, not the admin directory.
 */

// Set BASE_URL_OVERRIDE in the server environment when auto-detection is not enough.
// Do not define a placeholder value here because admin assets will load from it.
$baseUrlOverride = getenv('BASE_URL_OVERRIDE');
if (is_string($baseUrlOverride) && trim($baseUrlOverride) !== '') {
	define('BASE_URL_OVERRIDE', rtrim(trim($baseUrlOverride), '/') . '/');
}

// If you need a file-level override, uncomment and set the real root URL:
// define('BASE_URL_OVERRIDE', 'https://example.com/subdirectory/');
