<?php
/**
 * Footer link targets. Staff type links in Footer Management (a page name, #anchor, site path,
 * web address, mailto: or tel:); this turns them into the href the public footer prints.
 */
if (!function_exists('footer_normalize_href')) {
    function footer_normalize_href(string $rawHref, bool $isIndexPage): string
    {
        $rawHref = trim(preg_replace('#^api/#i', '', trim($rawHref)));
        if ($rawHref === '') {
            return '';
        }

        // Protocol-relative (//host) and backslash-led values are never a footer link.
        if (strpos($rawHref, '//') === 0 || strpos($rawHref, '\\') === 0) {
            return '';
        }
        // Only web addresses, mailto: and tel: are used exactly as typed; any other scheme
        // (javascript:, data:, vbscript:, file: ...) is dropped so a typed link can never run code.
        if (preg_match('/^([a-z][a-z0-9+.\-]*):/i', $rawHref, $sm)) {
            return in_array(strtolower($sm[1]), ['http', 'https', 'mailto', 'tel'], true) ? $rawHref : '';
        }

        $isRooted = strpos($rawHref, '/') === 0;
        $startsHash = strpos($rawHref, '#') === 0;
        $hasHashAnywhere = strpos($rawHref, '#') !== false;
        $hasPhp = (bool)preg_match('/\.php(\b|#|\?|$)/i', $rawHref);
        // A file such as images/menu.pdf already names what it is.
        $hasFileExt = (bool)preg_match('#\.[A-Za-z0-9]{2,5}($|[?\#])#', $rawHref);

        $lc = strtolower($rawHref);
        if ($lc === 'contact' || $lc === '#contact') {
            return $isIndexPage ? '#contact' : 'contact-us.php#contact-info';
        }
        if ($lc === 'contact-us' || $lc === 'contact-us.php') {
            return 'contact-us.php' . ($hasHashAnywhere ? '' : '#contact-info');
        }
        // A bare word is a section of the home page.
        if (!$startsHash && !$isRooted && !$hasPhp && preg_match('/^[A-Za-z][A-Za-z0-9_\-:.]*$/', $rawHref) && !$hasFileExt) {
            return $isIndexPage ? ('#' . $rawHref) : ('index.php#' . $rawHref);
        }
        if (!$hasPhp && !$startsHash && !$hasFileExt) {
            if ($hasHashAnywhere) {
                $parts = explode('#', $rawHref, 2);
                return $parts[0] . '.php#' . $parts[1];
            }
            return $rawHref . '.php';
        }
        return $rawHref;
    }
}
