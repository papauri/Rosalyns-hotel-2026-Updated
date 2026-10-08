<?php

/**
 * Conference pricing and multi-day helpers.
 *
 * Feature-detects the optional columns from migration 061
 * (`conference_rooms.half_day_rate`, `conference_inquiries.end_date`) so every caller behaves
 * exactly as before until the owner has run that migration.
 */

if (!function_exists('rh_conf_has_column')) {
    /** Cached (once per request) SHOW COLUMNS check. */
    function rh_conf_has_column(PDO $pdo, string $table, string $column): bool
    {
        static $cache = [];
        $key = $table . '.' . $column;
        if (!array_key_exists($key, $cache)) {
            try {
                $stmt = $pdo->prepare("SHOW COLUMNS FROM `" . str_replace('`', '', $table) . "` LIKE ?");
                $stmt->execute([$column]);
                $cache[$key] = $stmt->rowCount() > 0;
            } catch (Throwable $e) {
                $cache[$key] = false;
            }
        }
        return $cache[$key];
    }
}

if (!function_exists('rh_conf_has_end_date')) {
    function rh_conf_has_end_date(PDO $pdo): bool
    {
        return rh_conf_has_column($pdo, 'conference_inquiries', 'end_date');
    }
}

if (!function_exists('rh_conf_has_half_day_rate')) {
    function rh_conf_has_half_day_rate(PDO $pdo): bool
    {
        return rh_conf_has_column($pdo, 'conference_rooms', 'half_day_rate');
    }
}

if (!function_exists('rh_conf_last_date')) {
    /** Last day of an enquiry row: end_date when present and not before event_date, else event_date. */
    function rh_conf_last_date(array $row): string
    {
        $start = (string)($row['event_date'] ?? '');
        $end = (string)($row['end_date'] ?? '');
        return ($end !== '' && $end >= $start) ? $end : $start;
    }
}

if (!function_exists('rh_conf_days')) {
    function rh_conf_days(string $eventDate, ?string $endDate): int
    {
        $s = strtotime($eventDate);
        $e = ($endDate !== null && $endDate !== '') ? strtotime($endDate) : $s;
        if ($s === false || $e === false || $e < $s) {
            return 1;
        }
        return (int)round(($e - $s) / 86400) + 1;
    }
}

if (!function_exists('rh_conf_format_range')) {
    /** "Mon, Jan 5, 2026" or "Mon, Jan 5, 2026 – Wed, Jan 7, 2026". */
    function rh_conf_format_range(?string $eventDate, ?string $endDate, string $fmt = 'D, M j, Y'): string
    {
        if (empty($eventDate)) {
            return '—';
        }
        $out = date($fmt, strtotime($eventDate));
        if (!empty($endDate) && $endDate > $eventDate) {
            $out .= ' – ' . date($fmt, strtotime($endDate));
        }
        return $out;
    }
}

if (!function_exists('rh_conf_price')) {
    /**
     * One pricing rule for guest form and admin.
     * 1 day + half_day_rate > 0 + (end_time - start_time) <= 5h  => half-day rate; else daily_rate x days.
     *
     * @return array{amount: float, label: string, days: int, half_day: bool}
     */
    function rh_conf_price(array $room, string $eventDate, ?string $endDate, string $startTime, string $endTime): array
    {
        $days = rh_conf_days($eventDate, $endDate);
        $daily = (float)($room['daily_rate'] ?? 0);
        $half = isset($room['half_day_rate']) && $room['half_day_rate'] !== null ? (float)$room['half_day_rate'] : 0.0;

        if ($days === 1 && $half > 0) {
            $st = strtotime('1970-01-01 ' . $startTime);
            $et = strtotime('1970-01-01 ' . $endTime);
            if ($st !== false && $et !== false && ($et - $st) <= 5 * 3600) {
                return ['amount' => $half, 'label' => 'Half day', 'days' => 1, 'half_day' => true];
            }
        }
        return [
            'amount' => $daily * $days,
            'label' => $days === 1 ? '1 day' : $days . ' days',
            'days' => $days,
            'half_day' => false,
        ];
    }
}
