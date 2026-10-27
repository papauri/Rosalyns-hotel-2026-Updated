<?php

/**
 * 074 - station opening hours become real settings.
 *
 * Owner 2026-10-28: "Make sure such things are not hard coded." The kitchen, bar and coffee bar opening and closing
 * times are read from site_settings (station_<station>_opens_at / _closes_at, edited on Station Hours,
 * admin/station-settings.php). Until a hotel saved them, the station screens ran on the defaults written in
 * includes/station-hours.php and the Assistant had nothing saved to quote. This seeds each key that is MISSING with
 * that default, once, so the screens keep the hours they already run on and the value is now visible and editable.
 *
 * Additive and idempotent: INSERT IGNORE on the unique setting_key, so a time a hotel already saved (even an odd one)
 * is never touched, and re-running changes nothing.
 */

return [
    'name' => 'station_hours_settings',

    'check' => function (PDO $pdo): bool {
        require_once dirname(__DIR__, 2) . '/includes/station-hours.php';
        $keys = [];
        foreach (array_keys(rh_station_definitions()) as $station) {
            $keys[] = 'station_' . $station . '_opens_at';
            $keys[] = 'station_' . $station . '_closes_at';
        }
        $st = $pdo->prepare('SELECT COUNT(*) FROM site_settings WHERE setting_key IN (' . implode(',', array_fill(0, count($keys), '?')) . ')');
        $st->execute($keys);
        return (int)$st->fetchColumn() === count($keys);
    },

    'up' => function (PDO $pdo): void {
        require_once dirname(__DIR__, 2) . '/includes/station-hours.php';
        $ins = $pdo->prepare('INSERT IGNORE INTO site_settings (setting_key, setting_value) VALUES (?, ?)');
        foreach (rh_station_definitions() as $station => $def) {
            $ins->execute(['station_' . $station . '_opens_at', (string)$def['default_open']]);
            $ins->execute(['station_' . $station . '_closes_at', (string)$def['default_close']]);
        }
    },
];
