<?php
/**
 * Display-only user timezone. Storage and NOW() stay in hotel time (RH_TIMEZONE);
 * the browser timezone arrives in the `rh_tz` cookie and is used only to RENDER timestamps.
 * Never apply to date-only values (check-in/out dates) - those must not shift.
 */
if (!function_exists('rhUserTimezone')) {
    function rhUserTimezone(?string $cookieValue = null): string
    {
        $hotel = defined('RH_TIMEZONE') ? RH_TIMEZONE : date_default_timezone_get();
        $tz = $cookieValue !== null ? $cookieValue : (string)($_COOKIE['rh_tz'] ?? '');
        $tz = trim($tz);
        if ($tz === '' || strlen($tz) > 64 || !in_array($tz, timezone_identifiers_list(), true)) {
            return $hotel;
        }
        return $tz;
    }

    /** Convert a hotel-time MySQL datetime to the user's timezone. Returns '' for empty/invalid input. */
    function rhToUserTime($mysqlDatetime, string $format = 'Y-m-d H:i', ?string $userTz = null): string
    {
        $mysqlDatetime = trim((string)$mysqlDatetime);
        if ($mysqlDatetime === '' || strpos($mysqlDatetime, '0000-00-00') === 0) {
            return '';
        }
        try {
            $hotel = defined('RH_TIMEZONE') ? RH_TIMEZONE : date_default_timezone_get();
            $dt = new DateTime($mysqlDatetime, new DateTimeZone($hotel));
            $dt->setTimezone(new DateTimeZone($userTz !== null ? $userTz : rhUserTimezone()));
            return $dt->format($format);
        } catch (Exception $e) {
            return '';
        }
    }

    /** Short label such as "EDT" or "+02" for the user's timezone. */
    function rhUserTzLabel(?string $userTz = null): string
    {
        try {
            return (new DateTime('now', new DateTimeZone($userTz !== null ? $userTz : rhUserTimezone())))->format('T');
        } catch (Exception $e) {
            return '';
        }
    }

    /** Inline script: sets the rh_tz cookie when the browser timezone changed and exposes JS helpers. */
    function rhUserTzScript(): string
    {
        $offset = (new DateTime('now', new DateTimeZone(defined('RH_TIMEZONE') ? RH_TIMEZONE : date_default_timezone_get())))->format('P');
        return '<script>(function(){try{var tz=Intl.DateTimeFormat().resolvedOptions().timeZone;'
            . 'if(tz&&document.cookie.indexOf("rh_tz="+encodeURIComponent(tz))===-1){'
            . 'document.cookie="rh_tz="+encodeURIComponent(tz)+";path=/;max-age=31536000;SameSite=Lax"+(location.protocol==="https:"?";Secure":"");}}catch(e){}'
            . 'window.RH_HOTEL_OFFSET=' . json_encode($offset) . ';'
            . 'window.rhHotelDate=function(s){if(!s)return new Date(NaN);s=String(s);if(/[zZ]|[+-]\d\d:?\d\d$/.test(s))return new Date(s);'
            . 'return new Date(s.replace(" ","T")+window.RH_HOTEL_OFFSET);};})();</script>';
    }
}
