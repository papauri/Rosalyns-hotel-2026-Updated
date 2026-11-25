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
                /* information_schema, not SHOW COLUMNS ... LIKE ?: a placeholder there is a syntax error
                 * under real (non-emulated) prepares, which made the columns look missing. */
                $stmt = $pdo->prepare("SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?");
                $stmt->execute([$table, $column]);
                $cache[$key] = ((int)$stmt->fetchColumn() > 0);
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

if (!function_exists('rh_conf_gross')) {
    /**
     * The VAT-inclusive total the account actually bills: the locked total_with_vat when present, else
     * total_amount (already inclusive in inclusive mode) or total_amount + vat_amount (VAT-on-top mode).
     * Estimates and quotations show this so they never understate what the account will lock.
     */
    function rh_conf_gross(array $row): float
    {
        $gross = (float)($row['total_with_vat'] ?? 0);
        if ($gross > 0) {
            return $gross;
        }
        $base = (float)($row['total_amount'] ?? 0);
        if (function_exists('vat_mode') && vat_mode() === 'inclusive') {
            return $base;
        }
        return $base + (float)($row['vat_amount'] ?? 0);
    }
}

if (!function_exists('rh_conf_gross_sql')) {
    /**
     * SQL twin of rh_conf_gross() for SUM()/list queries: locked total_with_vat when present, else
     * total_amount (inclusive/off mode) or total_amount + vat_amount (VAT-on-top). Same rule, one place.
     */
    function rh_conf_gross_sql(string $alias = ''): string
    {
        $p = $alias !== '' ? $alias . '.' : '';
        $fallback = (function_exists('vat_mode') && vat_mode() === 'inclusive')
            ? "COALESCE({$p}total_amount, 0)"
            : "COALESCE({$p}total_amount, 0) + COALESCE({$p}vat_amount, 0)";
        return "COALESCE(NULLIF({$p}total_with_vat, 0), {$fallback})";
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

/* ── Conference packages (migration 092; owner 2026-11-26, plan .claude/CONF_PACKAGES_PLAN.md) ──────────────────
 * Add-ons for conference rooms, priced per event, per day or per delegate per day. An enquiry's lines are snapshots
 * (name, price type, unit price) in conference_inquiry_packages. total_amount = room hire + package_total, on the same
 * price basis as the room rates (VAT included in inclusive mode, before VAT in exclusive mode); no tourism levy.
 * The room-hire part is never stored: it is total_amount - package_total. */

if (!defined('RH_CONF_MAX_TOTAL')) {
    /** conference_inquiries.total_amount / total_with_vat are DECIMAL(10,2): a bigger total is refused, never cut. */
    define('RH_CONF_MAX_TOTAL', 99999999.99);
}

if (!function_exists('rh_conf_has_packages')) {
    function rh_conf_has_packages(PDO $pdo): bool
    {
        return rh_conf_has_column($pdo, 'conference_packages', 'price_type')
            && rh_conf_has_column($pdo, 'conference_inquiry_packages', 'unit_price')
            && rh_conf_has_column($pdo, 'conference_inquiries', 'package_total')
            && rh_conf_has_column($pdo, 'conference_inquiries', 'booked_daily_rate');
    }
}

if (!function_exists('rh_conf_price_types')) {
    /** price_type => the words staff and guests see. */
    function rh_conf_price_types(): array
    {
        return ['per_event' => 'per event', 'per_day' => 'per day', 'per_delegate_per_day' => 'per delegate per day'];
    }
}

if (!function_exists('rh_conf_delegates')) {
    /** Delegates a package is charged for: number_of_attendees, never less than 1. */
    function rh_conf_delegates($rowOrCount): int
    {
        $n = is_array($rowOrCount) ? (int)($rowOrCount['number_of_attendees'] ?? 0) : (int)$rowOrCount;
        return max(1, $n);
    }
}

if (!function_exists('rh_conf_package_cost')) {
    /** per_event = unit; per_day = unit x days; per_delegate_per_day = unit x delegates x days (rounded to 2). */
    function rh_conf_package_cost(string $priceType, float $unit, int $days, int $delegates): float
    {
        $days = max(1, $days);
        $delegates = max(1, $delegates);
        switch ($priceType) {
            case 'per_day':
                return round($unit * $days, 2);
            case 'per_delegate_per_day':
                return round($unit * $delegates * $days, 2);
            default:
                return round($unit, 2);
        }
    }
}

if (!function_exists('rh_conf_money_words')) {
    /** 15000 => "15,000"; 15000.5 => "15,000.50". */
    function rh_conf_money_words(float $v): string
    {
        return abs($v - round($v)) < 0.005 ? number_format($v, 0) : number_format($v, 2);
    }
}

if (!function_exists('rh_conf_line_text')) {
    /** How a line is charged: "15,000 × 40 delegates × 3 days", "30,000 × 3 days", "50,000 per event". */
    function rh_conf_line_text(array $line): string
    {
        $unit = rh_conf_money_words((float)($line['unit_price'] ?? $line['price_amount'] ?? 0));
        $days = max(1, (int)($line['days'] ?? 1));
        $dele = max(1, (int)($line['delegates'] ?? 1));
        $dayWords = $days . ($days === 1 ? ' day' : ' days');
        switch ((string)($line['price_type'] ?? '')) {
            case 'per_day':
                return $unit . ' × ' . $dayWords;
            case 'per_delegate_per_day':
                return $unit . ' × ' . $dele . ($dele === 1 ? ' delegate' : ' delegates') . ' × ' . $dayWords;
            default:
                return $unit . ' per event';
        }
    }
}

if (!function_exists('rh_conf_package_room_ids')) {
    function rh_conf_package_room_ids(array $pkg): array
    {
        $ids = json_decode((string)($pkg['conference_room_ids'] ?? ''), true);
        return is_array($ids) ? array_values(array_unique(array_map('intval', $ids))) : [];
    }
}

if (!function_exists('rh_conf_package_allowed')) {
    /** Is this catalog package offered for this conference room? */
    function rh_conf_package_allowed(array $pkg, int $roomId): bool
    {
        if ((string)($pkg['applies_to'] ?? 'all') !== 'rooms') {
            return true;
        }
        return in_array($roomId, rh_conf_package_room_ids($pkg), true);
    }
}

if (!function_exists('rh_conf_packages_for_room')) {
    /** Active catalog packages offered for a room (all rooms, or this room chosen), in display order. */
    function rh_conf_packages_for_room(PDO $pdo, int $roomId): array
    {
        if (!rh_conf_has_packages($pdo)) {
            return [];
        }
        $rows = $pdo->query("SELECT * FROM conference_packages WHERE is_active = 1 ORDER BY sort_order ASC, name ASC")->fetchAll(PDO::FETCH_ASSOC);
        return array_values(array_filter($rows, static function ($p) use ($roomId) {
            return rh_conf_package_allowed($p, $roomId);
        }));
    }
}

if (!function_exists('rh_conf_package_lines')) {
    function rh_conf_package_lines(PDO $pdo, int $inquiryId): array
    {
        if ($inquiryId <= 0 || !rh_conf_has_packages($pdo)) {
            return [];
        }
        $st = $pdo->prepare("SELECT * FROM conference_inquiry_packages WHERE inquiry_id = ? ORDER BY id ASC");
        $st->execute([$inquiryId]);
        return $st->fetchAll(PDO::FETCH_ASSOC);
    }
}

if (!function_exists('rh_conf_venue_amount')) {
    /** Room-hire part of an enquiry's total: total_amount - package_total (never below 0). */
    function rh_conf_venue_amount(array $row): float
    {
        return max(0.0, round((float)($row['total_amount'] ?? 0) - (float)($row['package_total'] ?? 0), 2));
    }
}

if (!function_exists('rh_conf_booked_room')) {
    /** The room with the rates saved on the enquiry at booking (when saved), so a later rate change is not used. */
    function rh_conf_booked_room(array $row, array $room): array
    {
        if (isset($row['booked_daily_rate']) && $row['booked_daily_rate'] !== '') {
            $room['daily_rate'] = (float)$row['booked_daily_rate'];
            $room['half_day_rate'] = (isset($row['booked_half_day_rate']) && $row['booked_half_day_rate'] !== '')
                ? (float)$row['booked_half_day_rate'] : null;
        }
        return $room;
    }
}

if (!function_exists('rh_conf_total_fits')) {
    /** False when the total, or its VAT-included total, would not fit the DECIMAL(10,2) columns. */
    function rh_conf_total_fits(float $base): bool
    {
        if ($base < 0 || $base > RH_CONF_MAX_TOTAL) {
            return false;
        }
        $vat = function_exists('vat_components') ? vat_components($base) : ['total' => $base];
        return (float)$vat['total'] <= RH_CONF_MAX_TOTAL;
    }
}

if (!function_exists('rh_conf_too_big_message')) {
    function rh_conf_too_big_message(): string
    {
        return 'This total is too large to save (the most one conference booking can hold is 99,999,999.99). Lower the venue price, the packages or the number of delegates.';
    }
}

if (!function_exists('rh_conf_apply_totals')) {
    /**
     * Reprice every package line of one enquiry from its current dates and delegates, then write package_total,
     * total_amount (= room hire + packages) and the locked VAT figures, and resync the payment snapshot.
     * Must run inside the caller's transaction (the row is locked FOR UPDATE here; the caller locked it first).
     *
     * $newVenue: the room-hire part to use (null = keep the current one).
     * $opts['room_changed'] = true: a line whose catalog package is not offered in the enquiry's (new) room is flagged
     * for staff (still charged); a line whose package is offered there loses an old room flag.
     *
     * Per-event lines keep their price; per-day / per-delegate-per-day lines are unit_price x the new days/delegates.
     * The VAT figures are re-locked only when the total changes (or were never locked).
     *
     * @return array{old_total: float, new_total: float, old_venue: float, new_venue: float, old_packages: float,
     *               new_packages: float, days: int, delegates: int, changes: array}
     */
    function rh_conf_apply_totals(PDO $pdo, int $inquiryId, ?float $newVenue = null, array $opts = []): array
    {
        if (!$pdo->inTransaction()) {
            throw new RuntimeException('rh_conf_apply_totals must run inside a transaction.');
        }
        $st = $pdo->prepare("SELECT * FROM conference_inquiries WHERE id = ? FOR UPDATE");
        $st->execute([$inquiryId]);
        $row = $st->fetch(PDO::FETCH_ASSOC);
        if (!$row) {
            throw new RuntimeException('Enquiry not found.');
        }
        $tol = defined('BALANCE_TOLERANCE') ? (float)BALANCE_TOLERANCE : 0.01;
        $roomId = (int)($row['conference_room_id'] ?? 0);
        $days = rh_conf_days((string)$row['event_date'], $row['end_date'] ?? null);
        $delegates = rh_conf_delegates($row);
        $roomChanged = !empty($opts['room_changed']);

        $roomName = '';
        $catalog = [];
        if ($roomChanged) {
            $rn = $pdo->prepare("SELECT name FROM conference_rooms WHERE id = ?");
            $rn->execute([$roomId]);
            $roomName = (string)$rn->fetchColumn();
            foreach ($pdo->query("SELECT * FROM conference_packages")->fetchAll(PDO::FETCH_ASSOC) as $p) {
                $catalog[(int)$p['id']] = $p;
            }
        }

        $lines = rh_conf_package_lines($pdo, $inquiryId);
        $packageTotal = 0.0;
        $changes = [];
        $upd = $pdo->prepare("UPDATE conference_inquiry_packages SET days = ?, delegates = ?, total_cost = ?, needs_review = ?, review_reason = ? WHERE id = ?");
        foreach ($lines as $line) {
            $type = (string)$line['price_type'];
            $lineDays = $type === 'per_event' ? (int)$line['days'] : $days;
            $lineDelegates = $type === 'per_delegate_per_day' ? $delegates : (int)$line['delegates'];
            $newCost = $type === 'per_event'
                ? round((float)$line['total_cost'], 2)
                : rh_conf_package_cost($type, (float)$line['unit_price'], $days, $delegates);
            $oldFlag = (int)$line['needs_review'];
            $flag = $oldFlag;
            $reason = $line['review_reason'];
            $pkgId = (int)($line['package_id'] ?? 0);
            if ($roomChanged && $pkgId > 0 && isset($catalog[$pkgId])) {
                if (rh_conf_package_allowed($catalog[$pkgId], $roomId)) {
                    $flag = 0;
                    $reason = null;
                } else {
                    $flag = 1;
                    $reason = mb_substr('Not offered in ' . ($roomName !== '' ? $roomName : 'this room'), 0, 255);
                }
            }
            $oldCost = round((float)$line['total_cost'], 2);
            $costChanged = abs($newCost - $oldCost) > $tol;
            if ($costChanged || $lineDays !== (int)$line['days'] || $lineDelegates !== (int)$line['delegates']
                || $flag !== $oldFlag || $reason !== $line['review_reason']) {
                $upd->execute([min(65535, max(1, $lineDays)), min(65535, max(1, $lineDelegates)), $newCost, $flag, $reason, (int)$line['id']]);
            }
            if ($costChanged || $flag !== $oldFlag) {
                $changes[] = ['name' => (string)$line['package_name'], 'old' => $oldCost, 'new' => $newCost, 'flagged' => $flag === 1, 'reason' => (string)$reason];
            }
            $packageTotal += $newCost;
        }
        $packageTotal = round($packageTotal, 2);

        $oldTotal = round((float)($row['total_amount'] ?? 0), 2);
        $oldPackages = round((float)($row['package_total'] ?? 0), 2);
        $oldVenue = rh_conf_venue_amount($row);
        $venue = $newVenue !== null ? round(max(0.0, $newVenue), 2) : $oldVenue;
        $newTotal = round($venue + $packageTotal, 2);
        if (!rh_conf_total_fits($newTotal)) {
            throw new RuntimeException(rh_conf_too_big_message());
        }

        if (abs($newTotal - $oldTotal) > $tol || (float)($row['total_with_vat'] ?? 0) <= 0 || $row['total_amount'] === null) {
            $vat = vat_components($newTotal);
            $pdo->prepare("UPDATE conference_inquiries SET total_amount = ?, package_total = ?, vat_rate = ?, vat_amount = ?, total_with_vat = ?, updated_at = NOW() WHERE id = ?")
                ->execute([$newTotal, $packageTotal, $vat['rate'], $vat['vat'], $vat['total'], $inquiryId]);
        } elseif (abs($packageTotal - $oldPackages) > $tol) {
            $pdo->prepare("UPDATE conference_inquiries SET package_total = ?, updated_at = NOW() WHERE id = ?")->execute([$packageTotal, $inquiryId]);
        }
        if (function_exists('syncConferenceInquiryPaymentSnapshot')) {
            syncConferenceInquiryPaymentSnapshot($pdo, $inquiryId);
        }

        return [
            'old_total' => $oldTotal, 'new_total' => $newTotal,
            'old_venue' => $oldVenue, 'new_venue' => $venue,
            'old_packages' => $oldPackages, 'new_packages' => $packageTotal,
            'days' => $days, 'delegates' => $delegates, 'changes' => $changes,
        ];
    }
}

if (!function_exists('rh_conf_change_log_text')) {
    /** One readable line for System Logs: "CONF-1: event changed; Lunch 1,800,000 → 1,050,000; total 3,290,000 → 2,010,000". */
    function rh_conf_change_log_text(string $ref, string $what, array $res): string
    {
        $out = $ref . ': ' . $what;
        foreach ($res['changes'] ?? [] as $c) {
            $out .= '; ' . $c['name'] . ' ' . rh_conf_money_words((float)$c['old']) . ' → ' . rh_conf_money_words((float)$c['new'])
                . (!empty($c['flagged']) ? ' (flagged: ' . $c['reason'] . ')' : '');
        }
        if (abs((float)($res['old_venue'] ?? 0) - (float)($res['new_venue'] ?? 0)) > 0.005) {
            $out .= '; venue ' . rh_conf_money_words((float)$res['old_venue']) . ' → ' . rh_conf_money_words((float)$res['new_venue']);
        }
        $out .= '; total ' . rh_conf_money_words((float)($res['old_total'] ?? 0)) . ' → ' . rh_conf_money_words((float)($res['new_total'] ?? 0));
        return mb_substr($out, 0, 2000);
    }
}

if (!function_exists('rh_conf_package_gross_sql')) {
    /**
     * Packages share of the VAT-included total, scaled by the row's LOCKED VAT (never the current VAT mode):
     * package_total x total_with_vat / total_amount; package_total when nothing is locked yet.
     */
    function rh_conf_package_gross_sql(string $alias = ''): string
    {
        $p = $alias !== '' ? $alias . '.' : '';
        return "(CASE WHEN COALESCE({$p}total_with_vat, 0) > 0 AND COALESCE({$p}total_amount, 0) > 0"
            . " THEN COALESCE({$p}package_total, 0) * {$p}total_with_vat / {$p}total_amount"
            . " ELSE COALESCE({$p}package_total, 0) END)";
    }
}
