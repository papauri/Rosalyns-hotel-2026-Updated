<?php
if (PHP_SAPI !== 'cli') { http_response_code(403); exit("CLI only.\n"); }
// Display timezone helpers. Run from a repo folder: php scripts/tests/user_timezone.php
require 'config/database.php';
$ok = 0; $bad = 0;
$t = function (bool $c, string $n) use (&$ok, &$bad) { echo ($c ? 'PASS ' : 'FAIL ') . $n . "\n"; $c ? $ok++ : $bad++; };

$t(RH_TIMEZONE === date_default_timezone_get(), 'storage timezone untouched');
$t(rhToUserTime('2026-10-06 14:30:00', 'Y-m-d H:i', 'America/New_York') === '2026-10-06 08:30', 'Blantyre 14:30 -> New York 08:30 (EDT)');
$t(rhToUserTime('2026-01-15 23:30:00', 'Y-m-d H:i', 'America/New_York') === '2026-01-15 16:30', 'winter offset handled (EST)');
$t(rhToUserTime('2026-10-06 14:30:00', 'Y-m-d H:i', RH_TIMEZONE) === '2026-10-06 14:30', 'same timezone is unchanged');
$t(rhUserTimezone('Not/AZone') === RH_TIMEZONE, 'invalid cookie falls back to hotel tz');
$t(rhUserTimezone("<script>") === RH_TIMEZONE && rhUserTimezone('') === RH_TIMEZONE, 'junk/empty cookie falls back');
$t(rhUserTimezone('Europe/London') === 'Europe/London', 'valid cookie accepted');
$_COOKIE['rh_tz'] = 'America/New_York';
$t(rhToUserTime('2026-10-06 14:30:00') === '2026-10-06 08:30', 'cookie drives default conversion');
$_COOKIE['rh_tz'] = 'bogus';
$t(rhToUserTime('2026-10-06 14:30:00') === '2026-10-06 14:30', 'bogus cookie shows hotel time');
$t(rhToUserTime('') === '' && rhToUserTime('0000-00-00 00:00:00') === '' && rhToUserTime('garbage') === '', 'empty/invalid input yields empty string');
$t(rhUserTzLabel('America/New_York') !== '', 'tz label available');
echo "\n$ok passed, $bad failed\n";
exit($bad ? 1 : 0);
