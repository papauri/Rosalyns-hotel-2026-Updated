<?php
/**
 * Station opening hours and business-day helpers for KDS/BDS/CDS.
 */

if (!function_exists('rh_station_definitions')) {
    function rh_station_definitions(): array
    {
        return [
            'kitchen' => [
                'label' => 'Kitchen',
                'short_label' => 'KDS',
                'icon' => 'fa-utensils',
                'default_open' => '06:00',
                'default_close' => '23:00',
            ],
            'bar' => [
                'label' => 'Bar',
                'short_label' => 'BDS',
                'icon' => 'fa-cocktail',
                'default_open' => '11:00',
                'default_close' => '03:00',
            ],
            'coffee_bar' => [
                'label' => 'Coffee Bar',
                'short_label' => 'CDS',
                'icon' => 'fa-mug-hot',
                'default_open' => '06:00',
                'default_close' => '22:00',
            ],
        ];
    }
}

if (!function_exists('rh_station_setting_key')) {
    function rh_station_setting_key(string $station, string $field): string
    {
        return 'station_' . $station . '_' . $field;
    }
}

if (!function_exists('rh_station_is_valid_time')) {
    function rh_station_is_valid_time(string $time): bool
    {
        return (bool)preg_match('/^(?:[01]\d|2[0-3]):[0-5]\d$/', $time);
    }
}

if (!function_exists('rh_site_timezone')) {
    function rh_site_timezone(): DateTimeZone
    {
        $tz = function_exists('getSetting') ? (string)getSetting('site_timezone', 'UTC') : 'UTC';
        if ($tz === '' || !in_array($tz, DateTimeZone::listIdentifiers(), true)) {
            $tz = 'UTC';
        }
        return new DateTimeZone($tz);
    }
}

if (!function_exists('rh_station_minutes')) {
    function rh_station_minutes(string $time): int
    {
        [$hours, $minutes] = array_map('intval', explode(':', $time));
        return ($hours * 60) + $minutes;
    }
}

if (!function_exists('rh_station_normalize_time')) {
    function rh_station_normalize_time(mixed $time, string $fallback): string
    {
        $value = trim((string)$time);
        if (preg_match('/^(\d{1,2}):(\d{2})(?::\d{2})?$/', $value, $matches)) {
            $value = sprintf('%02d:%02d', (int)$matches[1], (int)$matches[2]);
        }

        return rh_station_is_valid_time($value) ? $value : $fallback;
    }
}

if (!function_exists('rh_station_hours')) {
    function rh_station_hours(string $station): array
    {
        $definitions = rh_station_definitions();
        if (!isset($definitions[$station])) {
            $station = 'kitchen';
        }

        $definition = $definitions[$station];
        $open = rh_station_normalize_time(
            function_exists('getSetting') ? getSetting(rh_station_setting_key($station, 'opens_at'), $definition['default_open']) : $definition['default_open'],
            $definition['default_open']
        );
        $close = rh_station_normalize_time(
            function_exists('getSetting') ? getSetting(rh_station_setting_key($station, 'closes_at'), $definition['default_close']) : $definition['default_close'],
            $definition['default_close']
        );

        return [
            'station' => $station,
            'label' => $definition['label'],
            'short_label' => $definition['short_label'],
            'icon' => $definition['icon'],
            'opens_at' => $open,
            'closes_at' => $close,
            'crosses_midnight' => rh_station_minutes($close) <= rh_station_minutes($open),
        ];
    }
}

if (!function_exists('rh_station_window_for_date')) {
    function rh_station_window_for_date(string $station, string $businessDate): array
    {
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $businessDate)) {
            $businessDate = date('Y-m-d');
        }

        $hours = rh_station_hours($station);
        $tz  = rh_site_timezone();
        $utc = new DateTimeZone('UTC');
        $start = new DateTimeImmutable($businessDate . ' ' . $hours['opens_at'] . ':00', $tz);
        $endDate = $hours['crosses_midnight']
            ? (new DateTimeImmutable($businessDate))->modify('+1 day')->format('Y-m-d')
            : $businessDate;
        $end = new DateTimeImmutable($endDate . ' ' . $hours['closes_at'] . ':00', $tz);

        return [
            'station' => $hours['station'],
            'label' => $hours['label'],
            'short_label' => $hours['short_label'],
            'opens_at' => $hours['opens_at'],
            'closes_at' => $hours['closes_at'],
            'crosses_midnight' => $hours['crosses_midnight'],
            'business_date' => $businessDate,
            'start' => $start,
            'end' => $end,
            'start_sql' => $start->setTimezone($utc)->format('Y-m-d H:i:s'),
            'end_sql'   => $end->setTimezone($utc)->format('Y-m-d H:i:s'),
            'hours_label' => $hours['opens_at'] . ' - ' . $hours['closes_at'] . ($hours['crosses_midnight'] ? ' (+1)' : ''),
            'window_label' => $start->format('M j H:i') . ' - ' . $end->format('M j H:i'),
        ];
    }
}

if (!function_exists('rh_station_business_window')) {
    function rh_station_business_window(string $station, ?DateTimeImmutable $now = null): array
    {
        $now = $now ?: new DateTimeImmutable('now', rh_site_timezone());
        $today = $now->format('Y-m-d');
        $window = rh_station_window_for_date($station, $today);

        if ($window['crosses_midnight'] && $now < $window['start']) {
            $previousDate = (new DateTimeImmutable($today))->modify('-1 day')->format('Y-m-d');
            $previous = rh_station_window_for_date($station, $previousDate);
            if ($now < $previous['end']) {
                $window = $previous;
            }
        }

        $window['is_open_now'] = ($now >= $window['start'] && $now < $window['end']);
        $window['now_sql'] = $now->format('Y-m-d H:i:s');
        return $window;
    }
}

if (!function_exists('rh_station_previous_business_window')) {
    function rh_station_previous_business_window(string $station, ?array $currentWindow = null): array
    {
        $currentWindow = $currentWindow ?: rh_station_business_window($station);
        $date = (new DateTimeImmutable($currentWindow['business_date']))->modify('-1 day')->format('Y-m-d');
        $window = rh_station_window_for_date($station, $date);
        $window['is_open_now'] = false;
        return $window;
    }
}

if (!function_exists('rh_station_union_window_for_date')) {
    function rh_station_union_window_for_date(string $businessDate): array
    {
        $windows = [];
        foreach (array_keys(rh_station_definitions()) as $station) {
            $windows[] = rh_station_window_for_date($station, $businessDate);
        }

        usort($windows, fn(array $a, array $b): int => $a['start'] <=> $b['start']);
        $start = $windows[0]['start'];
        $end = array_reduce($windows, fn(DateTimeImmutable $carry, array $window): DateTimeImmutable => $window['end'] > $carry ? $window['end'] : $carry, $windows[0]['end']);
        $utc = new DateTimeZone('UTC');

        return [
            'station' => 'all',
            'label' => 'All Stations',
            'short_label' => 'All',
            'opens_at' => $start->format('H:i'),
            'closes_at' => $end->format('H:i'),
            'crosses_midnight' => $end->format('Y-m-d') !== $start->format('Y-m-d'),
            'business_date' => $businessDate,
            'start' => $start,
            'end' => $end,
            'start_sql' => $start->setTimezone($utc)->format('Y-m-d H:i:s'),
            'end_sql'   => $end->setTimezone($utc)->format('Y-m-d H:i:s'),
            'hours_label' => $start->format('H:i') . ' - ' . $end->format('H:i') . ($end->format('Y-m-d') !== $start->format('Y-m-d') ? ' (+1)' : ''),
            'window_label' => $start->format('M j H:i') . ' - ' . $end->format('M j H:i'),
            'is_open_now' => false,
        ];
    }
}

if (!function_exists('rh_station_union_business_window')) {
    function rh_station_union_business_window(?DateTimeImmutable $now = null): array
    {
        $now = $now ?: new DateTimeImmutable('now', rh_site_timezone());
        $today = $now->format('Y-m-d');
        $window = rh_station_union_window_for_date($today);

        if ($now < $window['start']) {
            $previousDate = (new DateTimeImmutable($today))->modify('-1 day')->format('Y-m-d');
            $previous = rh_station_union_window_for_date($previousDate);
            if ($now < $previous['end']) {
                $window = $previous;
            }
        }

        $window['is_open_now'] = ($now >= $window['start'] && $now < $window['end']);
        $window['now_sql'] = $now->format('Y-m-d H:i:s');
        return $window;
    }
}
