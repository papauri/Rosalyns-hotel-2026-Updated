<?php
/**
 * Cancellation & no-show policies (owner-approved 2026-11-13/14, spec .claude/CANCELLATION_POLICY_SPEC.md).
 *
 * One switch, one snapshot, two existing money mechanisms:
 *  - cancellation_policy_enabled (code default '0', never seeded) only decides whether a NEW booking gets a policy
 *    snapshot (bookings.cancellation_policy_snapshot, migration 085). Off = nothing is written = today's rules.
 *  - A booking WITH a snapshot is governed by it for ever (even if the policy is later switched Off or edited); a booking
 *    with NULL follows today's rules for ever.
 *  - Cancellation fee = tier % x whole stay -> bookings.cancellation_retained_amount (replaces keep_first_night).
 *  - No-show fee = no-show % x whole stay: 100% = the booked stay keeps billing (as today); below 100% = ONE folio row
 *    tagged rh_noshow_fee_tag() (replaces group_noshow_charge for that booking).
 *
 * Pre-migration safety: every read uses $b['cancellation_policy_snapshot'] ?? null (a JSON decode failure = NULL =
 * legacy); the only statement that names the column is the guarded stamp UPDATE (rh_cancel_policy_stamp).
 * Plain ASCII in every generated text ("x", "-"): the PDF core fonts have no special characters.
 */

if (!function_exists('rh_cancel_policy_column_ready')) {

    /** Setting keys (code defaults only; written only by the Hotel Settings form when changed). */
    function rh_cancel_policy_defaults(): array
    {
        return [
            'cancellation_policy_enabled'         => '0',
            'cancellation_policy_individual'      => '',
            'cancellation_policy_group'           => '',
            'cancellation_policy_group_min_rooms' => '2',
            'cancellation_policy_note'            => '',
            'cancellation_policy_version'         => '0',
        ];
    }

    /**
     * True once migration 085 added bookings.cancellation_policy_snapshot. One information_schema read per request.
     * Tests can simulate the column being absent with $GLOBALS['rh_cancel_policy_column_override'] = false.
     */
    function rh_cancel_policy_column_ready(PDO $pdo): bool
    {
        if (array_key_exists('rh_cancel_policy_column_override', $GLOBALS) && $GLOBALS['rh_cancel_policy_column_override'] !== null) {
            return (bool)$GLOBALS['rh_cancel_policy_column_override'];
        }
        static $ready = null;
        if ($ready !== null) {
            return $ready;
        }
        try {
            $st = $pdo->prepare("SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'bookings' AND COLUMN_NAME = 'cancellation_policy_snapshot'");
            $st->execute();
            $ready = (int)$st->fetchColumn() > 0;
        } catch (Throwable $e) {
            error_log('rh_cancel_policy_column_ready: ' . $e->getMessage());
            $ready = false;
        }
        return $ready;
    }

    /** "50" / "12.5" (no trailing zeros). Pure. */
    function rh_cancel_policy_pct_label($pct): string
    {
        $s = number_format(round((float)$pct, 2), 2, '.', '');
        return rtrim(rtrim($s, '0'), '.');
    }

    /**
     * Validate one policy. $raw: ['tiers' => [['days' => .., 'pct' => ..], ...], 'noshow_pct' => ..]. Blank rows are
     * dropped; <= 5 tiers; days int 1..365 unique; pct 0..100 (2 dp); noshow_pct 0..100 (blank = 100). Sorted by days
     * desc. Returns the clean policy, or null with $errors filled. Pure.
     */
    function rh_cancel_policy_validate(array $raw, array &$errors): ?array
    {
        $errors = [];
        $tiers = [];
        $seen = [];
        foreach ((array)($raw['tiers'] ?? []) as $t) {
            $d = trim((string)($t['days'] ?? ''));
            $p = trim((string)($t['pct'] ?? ''));
            if ($d === '' && $p === '') {
                continue;
            }
            if ($d === '' || !preg_match('/^\d+$/', $d) || (int)$d < 1 || (int)$d > 365) {
                $errors[] = 'Each row needs a number of days from 1 to 365.';
                continue;
            }
            if ($p === '' || !is_numeric($p) || (float)$p < 0 || (float)$p > 100) {
                $errors[] = 'Each row needs a percentage from 0 to 100.';
                continue;
            }
            $d = (int)$d;
            if (isset($seen[$d])) {
                $errors[] = 'Two rows use ' . $d . ' days. Each row needs its own number of days.';
                continue;
            }
            $seen[$d] = true;
            $tiers[] = ['days' => $d, 'pct' => round((float)$p, 2)];
        }
        if (count($tiers) > 5) {
            $errors[] = 'Use at most 5 rows.';
        }
        $ns = trim((string)($raw['noshow_pct'] ?? ''));
        if ($ns === '') {
            $ns = '100';
        }
        if (!is_numeric($ns) || (float)$ns < 0 || (float)$ns > 100) {
            $errors[] = 'The no-show percentage must be from 0 to 100.';
        }
        if ($errors) {
            $errors = array_values(array_unique($errors));
            return null;
        }
        usort($tiers, static fn ($a, $b) => $b['days'] <=> $a['days']);
        return ['tiers' => $tiers, 'noshow_pct' => round((float)$ns, 2)];
    }

    /** A stored policy JSON -> clean policy, or null (empty / unreadable / invalid). Pure. */
    function rh_cancel_policy_parse($json): ?array
    {
        $json = trim((string)$json);
        if ($json === '') {
            return null;
        }
        $raw = json_decode($json, true);
        if (!is_array($raw)) {
            return null;
        }
        $errors = [];
        return rh_cancel_policy_validate($raw, $errors);
    }

    /** All policy settings, read and validated. */
    function rh_cancel_policy_settings(): array
    {
        $d = rh_cancel_policy_defaults();
        $get = static fn (string $k): string => function_exists('getSetting') ? (string)getSetting($k, $d[$k]) : $d[$k];
        $min = (int)$get('cancellation_policy_group_min_rooms');
        $note = trim($get('cancellation_policy_note'));
        return [
            'enabled'         => $get('cancellation_policy_enabled') === '1',
            'individual'      => rh_cancel_policy_parse($get('cancellation_policy_individual')),
            'group'           => rh_cancel_policy_parse($get('cancellation_policy_group')),
            'group_min_rooms' => max(2, min(50, $min > 0 ? $min : 2)),
            'note'            => function_exists('mb_substr') ? mb_substr($note, 0, 300) : substr($note, 0, 300),
            'version'         => max(0, (int)$get('cancellation_policy_version')),
        ];
    }

    /**
     * The policy for a kind: 'group' = the group policy, else the individual one; 'individual' = the individual policy.
     * null when not set. The result carries the text inputs (kind, has_group, group_min_rooms, note).
     */
    function rh_cancel_policy_for_kind(string $kind, ?array $settings = null): ?array
    {
        $s = $settings ?? rh_cancel_policy_settings();
        $kind = $kind === 'group' ? 'group' : 'individual';
        $p = $kind === 'group' ? ($s['group'] ?? $s['individual']) : $s['individual'];
        if ($p === null) {
            return null;
        }
        return $p + ['kind' => $kind, 'has_group' => $s['group'] !== null, 'group_min_rooms' => (int)$s['group_min_rooms'], 'note' => (string)$s['note']];
    }

    /**
     * THE one generator of the fine print (pure). $policy: tiers, noshow_pct, has_group, group_min_rooms, note.
     * Strict rule (owner 2026-11-14): a tier charges when cancelled FEWER than X days before arrival; arrival day = 0.
     */
    function rh_cancel_policy_text(array $policy, string $kind): string
    {
        $tiers = (array)($policy['tiers'] ?? []);
        usort($tiers, static fn ($a, $b) => (int)$b['days'] <=> (int)$a['days']);
        $min = max(2, (int)($policy['group_min_rooms'] ?? 2));
        if (empty($policy['has_group'])) {
            $head = 'Cancellation policy.';
        } elseif ($kind === 'group') {
            $head = 'Cancellation policy (group bookings of ' . $min . ' rooms or more).';
        } else {
            $head = 'Cancellation policy (individual bookings, fewer than ' . $min . ' rooms).';
        }
        $parts = [$head];
        if (!$tiers) {
            $parts[] = 'Cancelled any time before arrival: no charge.';
        } else {
            $first = true;
            foreach ($tiers as $t) {
                $d = (int)$t['days'];
                $when = $d === 1 ? 'Cancelled on the arrival day' : 'Cancelled less than ' . $d . ' days before arrival';
                $pct = (float)$t['pct'];
                $what = $pct <= 0 ? 'no charge' : rh_cancel_policy_pct_label($pct) . '%' . ($first ? ' of the stay is charged' : '');
                $parts[] = $when . ': ' . $what . '.';
                $first = false;
            }
            $top = (int)$tiers[0]['days'];
            $parts[] = 'Cancelled ' . $top . ' or more days before arrival: no charge.';
        }
        $ns = (float)($policy['noshow_pct'] ?? 100);
        $line1 = implode(' ', $parts);
        $line2 = 'No-show: ' . ($ns <= 0 ? 'no charge.' : rh_cancel_policy_pct_label($ns) . '% of the stay.')
            . ' Percentages are of the whole stay including VAT and tourism levy; extras already used stay payable.';
        $text = $line1 . "\n" . $line2;
        $note = trim((string)($policy['note'] ?? ''));
        if ($note !== '') {
            $text .= "\n" . $note;
        }
        return $text;
    }

    /**
     * A dated example of the strict boundary for the largest tier (settings preview): "Example: arrival Fri 20 Nov 2026.
     * Cancelled on Fri 13 Nov (7 days before): no charge. Cancelled on Sat 14 Nov (6 days before): 50% of the stay." Pure.
     */
    function rh_cancel_policy_example(array $policy, DateTimeImmutable $arrival): string
    {
        $tiers = (array)($policy['tiers'] ?? []);
        if (!$tiers) {
            return '';
        }
        usort($tiers, static fn ($a, $b) => (int)$b['days'] <=> (int)$a['days']);
        $top = $tiers[0];
        $d = (int)$top['days'];
        $free = $arrival->modify('-' . $d . ' days');
        $fee = $arrival->modify('-' . ($d - 1) . ' days');
        $feeWhen = $d === 1 ? 'on the arrival day' : '(' . ($d - 1) . ' day' . ($d - 1 === 1 ? '' : 's') . ' before)';
        return 'Example: arrival ' . $arrival->format('D j M Y') . '. Cancelled on ' . $free->format('D j M') . ' (' . $d . ' day' . ($d === 1 ? '' : 's') . ' before): no charge. '
            . 'Cancelled on ' . $fee->format('D j M') . ' ' . $feeWhen . ': ' . rh_cancel_policy_pct_label((float)$top['pct']) . '% of the stay.';
    }

    /** The fine print for the CURRENT settings (public pages, confirm steps). '' when Off or not set. */
    function rh_current_policy_text(string $kind): string
    {
        $s = rh_cancel_policy_settings();
        if (!$s['enabled']) {
            return '';
        }
        $p = rh_cancel_policy_for_kind($kind, $s);
        return $p === null ? '' : rh_cancel_policy_text($p, $kind);
    }

    /** The current individual text, plus the group text when a separate group policy is set. '' when Off / not set. */
    function rh_current_policy_text_all(): string
    {
        $s = rh_cancel_policy_settings();
        if (!$s['enabled']) {
            return '';
        }
        $out = [];
        foreach (['individual', 'group'] as $kind) {
            if ($kind === 'group' && $s['group'] === null) {
                continue;
            }
            $p = rh_cancel_policy_for_kind($kind, $s);
            if ($p !== null) {
                $out[] = rh_cancel_policy_text($p, $kind);
            }
        }
        return implode("\n\n", $out);
    }

    /** Builds the snapshot array (with the generated text) for a new booking, or null when no policy is set. */
    function rh_cancel_policy_snapshot_build(string $kind, int $rooms, ?array $settings = null): ?array
    {
        $s = $settings ?? rh_cancel_policy_settings();
        $kind = $kind === 'group' ? 'group' : 'individual';
        $p = rh_cancel_policy_for_kind($kind, $s);
        if ($p === null) {
            return null;
        }
        return [
            'v' => 1, 'kind' => $kind, 'group_min_rooms' => (int)$s['group_min_rooms'], 'rooms_at_booking' => max(1, $rooms),
            'tiers' => $p['tiers'], 'noshow_pct' => (float)$p['noshow_pct'], 'policy_version' => (int)$s['version'],
            'taken_at' => date('Y-m-d H:i:s'), 'text' => rh_cancel_policy_text($p, $kind),
        ];
    }

    /**
     * Stamp the policy onto every booking row created together (call ONCE, after group linking, inside the creating
     * transaction). Off: returns at once (no information_schema query). Never overwrites a snapshot, never throws,
     * never refuses a booking: a failure is logged as a warning.
     */
    function rh_cancel_policy_stamp(PDO $pdo, array $ids): void
    {
        if (!function_exists('getSetting') || (string)getSetting('cancellation_policy_enabled', '0') !== '1') {
            return;
        }
        $ids = array_values(array_unique(array_filter(array_map('intval', $ids), static fn ($i) => $i > 0)));
        if (!$ids || !rh_cancel_policy_column_ready($pdo)) {
            return;
        }
        try {
            $s = rh_cancel_policy_settings();
            $kind = count($ids) >= (int)$s['group_min_rooms'] ? 'group' : 'individual';
            $snap = rh_cancel_policy_snapshot_build($kind, count($ids), $s);
            if ($snap === null) {
                return;
            }
            $json = json_encode($snap, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
            if ($json === false) {
                return;
            }
            $up = $pdo->prepare('UPDATE bookings SET cancellation_policy_snapshot = ? WHERE id = ? AND cancellation_policy_snapshot IS NULL');
            foreach ($ids as $id) {
                $up->execute([$json, $id]);
            }
        } catch (Throwable $e) {
            error_log('Warning: cancellation policy not stamped on booking(s) ' . implode(',', $ids) . ': ' . $e->getMessage());
        }
    }

    /** The decoded snapshot of a bookings row, or null (no key, NULL, unreadable) = legacy rules. Pure. */
    function rh_cancel_policy_snapshot(array $b): ?array
    {
        $raw = $b['cancellation_policy_snapshot'] ?? null;
        if ($raw === null || $raw === '') {
            return null;
        }
        $snap = is_array($raw) ? $raw : json_decode((string)$raw, true);
        if (!is_array($snap) || !isset($snap['tiers']) || !is_array($snap['tiers'])) {
            return null;
        }
        $snap['noshow_pct'] = isset($snap['noshow_pct']) && is_numeric($snap['noshow_pct']) ? max(0.0, min(100.0, (float)$snap['noshow_pct'])) : 100.0;
        $snap['kind'] = ($snap['kind'] ?? '') === 'group' ? 'group' : 'individual';
        $snap['policy_version'] = (int)($snap['policy_version'] ?? 0);
        $snap['text'] = (string)($snap['text'] ?? '');
        return $snap;
    }

    /**
     * A bookings row that carries the snapshot key when the column exists: $b as given when it already has the key
     * (or the column is absent), else re-read by id with SELECT *. Read-only.
     */
    function rh_cancel_policy_row(PDO $pdo, array $b): array
    {
        if (array_key_exists('cancellation_policy_snapshot', $b) || (int)($b['id'] ?? 0) <= 0 || !rh_cancel_policy_column_ready($pdo)) {
            return $b;
        }
        try {
            $st = $pdo->prepare('SELECT * FROM bookings WHERE id = ?');
            $st->execute([(int)$b['id']]);
            $row = $st->fetch(PDO::FETCH_ASSOC);
            return $row ? array_merge($b, $row) : $b;
        } catch (Throwable $e) {
            error_log('rh_cancel_policy_row: ' . $e->getMessage());
            return $b;
        }
    }

    /** The stored fine print of a booking (its snapshot text), or ''. Never regenerated. */
    function rh_booking_policy_text(array $b): string
    {
        $pdo = $GLOBALS['pdo'] ?? null;
        if (!array_key_exists('cancellation_policy_snapshot', $b) && $pdo instanceof PDO) {
            $b = rh_cancel_policy_row($pdo, $b);
        }
        $snap = rh_cancel_policy_snapshot($b);
        return $snap === null ? '' : trim($snap['text']);
    }

    /** The stored fine print of a booking's group lead (documents for a group print the lead's once), or ''. */
    function rh_booking_policy_text_by_id(PDO $pdo, int $bookingId, bool $lead = true): string
    {
        if ($bookingId <= 0 || !rh_cancel_policy_column_ready($pdo)) {
            return '';
        }
        try {
            $st = $pdo->prepare('SELECT * FROM bookings WHERE id = ?');
            $st->execute([$bookingId]);
            $b = $st->fetch(PDO::FETCH_ASSOC);
            if ($b && $lead && (int)($b['primary_booking_id'] ?? 0) > 0) {
                $st->execute([(int)$b['primary_booking_id']]);
                $leadRow = $st->fetch(PDO::FETCH_ASSOC);
                if ($leadRow && rh_cancel_policy_snapshot($leadRow) !== null) {
                    $b = $leadRow;
                }
            }
            return $b ? rh_booking_policy_text($b) : '';
        } catch (Throwable $e) {
            error_log('rh_booking_policy_text_by_id: ' . $e->getMessage());
            return '';
        }
    }

    /**
     * Calendar days from today to the arrival date in RH_TIMEZONE (check_in_date - today). Arrival day = 0, negative =
     * the arrival date passed. An unreadable date gives PHP_INT_MAX (= far away: never inside a window, never a fee).
     */
    function rh_days_before_arrival(string $checkInDate, ?DateTimeImmutable $now = null): int
    {
        $tz = new DateTimeZone(defined('RH_TIMEZONE') ? RH_TIMEZONE : 'Africa/Blantyre');
        $d = DateTimeImmutable::createFromFormat('!Y-m-d', substr(trim($checkInDate), 0, 10), $tz);
        if (!$d || $d->format('Y-m-d') !== substr(trim($checkInDate), 0, 10)) {
            return PHP_INT_MAX;
        }
        $today = ($now ?? new DateTimeImmutable('now', $tz))->setTimezone($tz)->setTime(0, 0);
        return (int)$today->diff($d)->format('%r%a');
    }

    /** The tier that applies: the one with the SMALLEST days where $daysBefore < days (strict). null = no charge. Pure. */
    function rh_cancel_policy_tier(array $tiers, int $daysBefore): ?array
    {
        $hit = null;
        foreach ($tiers as $t) {
            $d = (int)($t['days'] ?? 0);
            if ($d >= 1 && $daysBefore < $d && ($hit === null || $d < (int)$hit['days'])) {
                $hit = ['days' => $d, 'pct' => round((float)($t['pct'] ?? 0), 2)];
            }
        }
        return $hit;
    }

    /** The whole stay a % is taken of: stored total_with_vat, else total_amount + vat_amount. Pure. */
    function rh_cancel_policy_base(array $b): float
    {
        $gross = (float)($b['total_with_vat'] ?? 0);
        if ($gross <= 0) {
            $gross = (float)($b['total_amount'] ?? 0) + (float)($b['vat_amount'] ?? 0);
        }
        return max(0.0, round($gross, 2));
    }

    /** "(booked rate MWK 356,000.00 x 3 nights)" for a bookings row, or ''. Plain ASCII. */
    function rh_cancel_policy_basis(array $b, string $cur = ''): string
    {
        if ($cur === '') {
            $cur = function_exists('getSetting') ? trim((string)getSetting('currency_symbol', 'MWK')) : 'MWK';
        }
        if (!function_exists('rh_booked_rate_line') && is_file(__DIR__ . '/booked-rate.php')) {
            require_once __DIR__ . '/booked-rate.php';
        }
        $nights = max(1, (int)($b['number_of_nights'] ?? 1));
        $per = 0.0;
        if (function_exists('rh_booked_rate_line')) {
            $line = rh_booked_rate_line($b, null, ['currency' => $cur]);
            if (!empty($line['ok'])) {
                $per = (float)$line['per_night'];
                $nights = (int)$line['nights'];
            }
        }
        if ($per <= 0) {
            $per = round(rh_cancel_policy_base($b) / $nights, 2);
        }
        $money = function_exists('rh_booked_rate_money') ? rh_booked_rate_money($per, $cur) : $cur . ' ' . number_format($per, 2);
        return '(booked rate ' . $money . ' x ' . $nights . ' night' . ($nights === 1 ? '' : 's') . ')';
    }

    /**
     * Read-only fee quote for a booking. $event 'cancel' (tier by days before arrival) or 'noshow' (snapshot no-show %).
     * source 'legacy' = no snapshot: the caller runs today's code (amount 0 here). source 'policy': the fee only when the
     * status is 'confirmed' (pending / tentative / expired = 0, owner 2026-11-14). A snapshot row NEVER returns legacy.
     * @return array{source:string,applies:bool,pct:float,amount:float,days_before:int,tier_label:string,kind:string,
     *               base:float,version:int,tier_days:int,basis:string,fee_text:string}
     */
    function rh_cancellation_fee_quote(PDO $pdo, array $b, string $event = 'cancel'): array
    {
        $out = ['source' => 'legacy', 'applies' => false, 'pct' => 0.0, 'amount' => 0.0, 'days_before' => 0, 'tier_label' => '',
                'kind' => '', 'base' => 0.0, 'version' => 0, 'tier_days' => 0, 'basis' => '', 'fee_text' => ''];
        $b = rh_cancel_policy_row($pdo, $b);
        $snap = rh_cancel_policy_snapshot($b);
        if ($snap === null) {
            return $out;
        }
        $cur = function_exists('getSetting') ? trim((string)getSetting('currency_symbol', 'MWK')) : 'MWK';
        $money = static fn (float $v): string => function_exists('rh_booked_rate_money') ? rh_booked_rate_money($v, $cur) : $cur . ' ' . number_format($v, 2);
        $base = rh_cancel_policy_base($b);
        $days = rh_days_before_arrival((string)($b['check_in_date'] ?? ''));
        $out['source'] = 'policy';
        $out['kind'] = $snap['kind'];
        $out['version'] = $snap['policy_version'];
        $out['base'] = $base;
        $out['days_before'] = $days === PHP_INT_MAX ? 0 : $days;
        $out['basis'] = rh_cancel_policy_basis($b, $cur);
        $status = strtolower((string)($b['status'] ?? ''));
        $pct = 0.0;
        if ($event === 'noshow') {
            $pct = (float)$snap['noshow_pct'];
            $out['tier_label'] = 'No-show: ' . rh_cancel_policy_pct_label($pct) . '% of the stay';
        } elseif ($status !== 'confirmed') {
            $out['tier_label'] = 'Not confirmed yet (' . ($status !== '' ? $status : 'unknown') . '): no cancellation fee';
        } else {
            $tier = $days === PHP_INT_MAX ? null : rh_cancel_policy_tier($snap['tiers'], $days);
            $when = $days === PHP_INT_MAX ? 'Arrival date unknown' : ($days < 0 ? 'After the arrival date' : ($days === 0 ? 'On the arrival day' : $days . ' day' . ($days === 1 ? '' : 's') . ' before arrival'));
            if ($tier === null) {
                $out['tier_label'] = $when . ': no cancellation fee (outside every fee window)';
            } else {
                $pct = (float)$tier['pct'];
                $out['tier_days'] = (int)$tier['days'];
                $out['tier_label'] = $when . ' (' . ((int)$tier['days'] === 1 ? 'on the arrival day' : 'less than ' . (int)$tier['days'] . ' days') . '): '
                    . rh_cancel_policy_pct_label($pct) . '% of the stay';
            }
        }
        $amount = round($pct / 100 * $base, 2);
        if ($amount < BALANCE_TOLERANCE) {
            $amount = 0.0;
        }
        $out['pct'] = round($pct, 2);
        $out['amount'] = $amount;
        $out['applies'] = $amount > BALANCE_TOLERANCE;
        $out['fee_text'] = $out['applies']
            ? rh_cancel_policy_pct_label($pct) . '% of the stay ' . $money($base) . ' ' . $out['basis'] . ' = ' . $money($amount)
            : 'No fee';
        return $out;
    }

    /**
     * What cancelling now would do to the money (preview only, read-only): the quote plus net paid, extras, refund and
     * credit under the active cancellation mode (void_keep_credit keeps it as credit; the others refund).
     */
    function rh_cancel_policy_preview(PDO $pdo, array $b): array
    {
        $b = rh_cancel_policy_row($pdo, $b);
        $q = rh_cancellation_fee_quote($pdo, $b);
        $paid = round((float)($b['amount_paid'] ?? 0), 2);
        $folio = round((float)($b['folio_charges_total'] ?? 0), 2);
        $left = max(0.0, round($paid - $q['amount'] - $folio, 2));
        $keep = function_exists('getCancellationRefundMode') && getCancellationRefundMode() === 'void_keep_credit';
        return $q + [
            'paid' => $paid, 'folio' => $folio,
            'refund' => $keep ? 0.0 : $left, 'credit' => $keep ? $left : 0.0,
            'owed' => max(0.0, round($q['amount'] + $folio - $paid, 2)),
        ];
    }

    /**
     * No-show charge under a booking's policy snapshot (call right after its status became 'no-show'). Gates ONLY on:
     * status no-show, not deleted, not already tagged, snapshot present; the group setting is never read. At 100% it
     * posts NOTHING (the booked stay keeps billing, as today). Below 100%: one folio row tagged rh_noshow_fee_tag()
     * (a VOIDED zero row at 0%). Savepoint inside a caller's transaction; never throws.
     * @return array{posted:bool,mode:string,amount:float,error:string}
     */
    function rh_policy_noshow_fee_apply(PDO $pdo, int $bookingId, int $userId = 0, string $userName = ''): array
    {
        $out = ['posted' => false, 'mode' => 'policy', 'amount' => 0.0, 'error' => ''];
        if ($bookingId <= 0) {
            return $out;
        }
        $inTx = $pdo->inTransaction();
        $sp = 'rh_policy_noshow_fee';
        try {
            if ($inTx) {
                $pdo->exec('SAVEPOINT ' . $sp);
            } else {
                $pdo->beginTransaction();
            }
            $st = $pdo->prepare('SELECT * FROM bookings WHERE id = ? FOR UPDATE');
            $st->execute([$bookingId]);
            $b = $st->fetch(PDO::FETCH_ASSOC);
            $snap = $b ? rh_cancel_policy_snapshot($b) : null;
            $go = $b && $snap !== null && (string)$b['status'] === 'no-show' && empty($b['deleted_at']) && !rh_noshow_fee_tagged($pdo, $bookingId)
                && (float)$snap['noshow_pct'] < 100;
            if ($go) {
                $pct = (float)$snap['noshow_pct'];
                $base = rh_cancel_policy_base($b);
                $amount = round($pct / 100 * $base, 2);
                $isZero = $amount <= BALANCE_TOLERANCE;
                if ($isZero) {
                    $amount = 0.0;
                }
                $vatPart = $isZero ? 0.0 : rh_room_night_share($b, $amount)['vat'];
                $desc = 'No-show charge - ' . rh_cancel_policy_pct_label($pct) . '% of the stay (policy v' . (int)$snap['policy_version'] . ') ' . rh_noshow_fee_tag($bookingId);
                rh_noshow_fee_insert_row($pdo, $b, $desc, $amount, $vatPart, $userId, 'Cancellation policy: no-show charge ' . rh_cancel_policy_pct_label($pct) . '%');
                if (!recalculateBookingFinancials($bookingId)) {
                    throw new RuntimeException('Could not recalculate the booking balance.');
                }
                if (!function_exists('logBookingEvent') && is_file(__DIR__ . '/booking-timeline.php')) {
                    require_once __DIR__ . '/booking-timeline.php';
                }
                if (function_exists('logBookingEvent')) {
                    logBookingEvent($bookingId, (string)($b['booking_reference'] ?? ''), 'No-show charge posted', 'financial',
                        'Cancellation policy v' . (int)$snap['policy_version'] . ' (' . $snap['kind'] . ' bookings): the room is no longer billed its booked stay ('
                        . number_format($base, 2) . '); charged ' . ($isZero ? 'nothing (0%)' : number_format($amount, 2) . ' (' . rh_cancel_policy_pct_label($pct) . '% of the stay ' . rh_cancel_policy_basis($b) . ')') . '.',
                        'room bill ' . number_format($base, 2), 'charge ' . number_format($amount, 2),
                        $userId > 0 ? 'admin' : 'system', $userId > 0 ? $userId : null, $userId > 0 ? ($userName !== '' ? $userName : null) : 'System',
                        ['policy_version' => (int)$snap['policy_version'], 'kind' => $snap['kind'], 'noshow_pct' => $pct, 'base' => $base, 'amount' => $amount]);
                }
                $out['posted'] = true;
                $out['amount'] = $amount;
            }
            if ($inTx) {
                $pdo->exec('RELEASE SAVEPOINT ' . $sp);
            } else {
                $pdo->commit();
            }
        } catch (Throwable $e) {
            try {
                if ($inTx) {
                    $pdo->exec('ROLLBACK TO SAVEPOINT ' . $sp);
                    $pdo->exec('RELEASE SAVEPOINT ' . $sp);
                } elseif ($pdo->inTransaction()) {
                    $pdo->rollBack();
                }
            } catch (Throwable $e2) {
                error_log('rh_policy_noshow_fee_apply rollback: ' . $e2->getMessage());
            }
            error_log('rh_policy_noshow_fee_apply failed for booking ' . $bookingId . ': ' . $e->getMessage());
            $out['posted'] = false;
            $out['amount'] = 0.0;
            $out['error'] = $e->getMessage();
        }
        return $out;
    }

    /**
     * The fee choice a cancel form posted, or null when it posted no fee_quoted (a booking without a policy: the cancel
     * runs exactly as before). Single: {quoted, override, reason}. Group: {quoted, override_pct, reason}.
     */
    function rh_cancel_fee_choice_from_post(array $post, bool $group): ?array
    {
        $q = trim((string)($post['fee_quoted'] ?? ''));
        if ($q === '') {
            return null;
        }
        $reason = trim((string)($post['fee_override_reason'] ?? ''));
        $choice = ['quoted' => $q, 'reason' => $reason];
        if ($group) {
            $choice['override_pct'] = trim((string)($post['fee_override_pct'] ?? ''));
        } else {
            $choice['override'] = trim((string)($post['fee_override'] ?? ''));
        }
        return $choice;
    }

    /**
     * Read-only fee preview for a cancel dialog: this room, and (when booked together with others) the whole group.
     * policy = false when no room involved has a snapshot (the dialog then shows nothing new).
     */
    function rh_cancel_policy_dialog_quote(PDO $pdo, int $bookingId): array
    {
        $out = ['policy' => false, 'single' => null, 'group' => null];
        $st = $pdo->prepare('SELECT * FROM bookings WHERE id = ? AND deleted_at IS NULL');
        $st->execute([$bookingId]);
        $row = $st->fetch(PDO::FETCH_ASSOC);
        if (!$row) {
            return $out;
        }
        $one = rh_cancel_policy_preview($pdo, $row);
        $out['single'] = $one;
        $out['policy'] = $one['source'] === 'policy';
        $ids = function_exists('rh_booking_group_ids') ? rh_booking_group_ids($pdo, $bookingId) : [];
        if (count($ids) > 1) {
            $g = ['count' => count($ids), 'fee' => 0.0, 'paid' => 0.0, 'refund' => 0.0, 'credit' => 0.0, 'max_pct' => 0.0, 'rooms' => []];
            foreach ($ids as $gid) {
                $st->execute([$gid]);
                $gr = $st->fetch(PDO::FETCH_ASSOC);
                if (!$gr) {
                    continue;
                }
                $p = rh_cancel_policy_preview($pdo, $gr);
                if ($p['source'] === 'policy') {
                    $out['policy'] = true;
                }
                $g['fee'] = round($g['fee'] + $p['amount'], 2);
                $g['paid'] = round($g['paid'] + $p['paid'], 2);
                $g['refund'] = round($g['refund'] + $p['refund'], 2);
                $g['credit'] = round($g['credit'] + $p['credit'], 2);
                $g['max_pct'] = max($g['max_pct'], (float)$p['pct']);
                $g['rooms'][] = ['id' => (int)$gid, 'reference' => (string)$gr['booking_reference'], 'source' => $p['source'], 'fee' => $p['amount'],
                                 'pct' => $p['pct'], 'tier_label' => $p['tier_label'], 'fee_text' => $p['fee_text']];
            }
            $out['group'] = $g;
        }
        return $out;
    }

    /**
     * Guest self-cancel (booking-lookup.php, F2): the whole party the guest may cancel - the rows of the looked-up
     * booking's group (lead + rooms linked to it) carrying the email the guest proved, not deleted, and still
     * pending / tentative / confirmed (a checked-in room is left out, it would refuse the whole group). SELECT * rows,
     * the looked-up row first. Read-only.
     */
    function rh_guest_cancel_party(PDO $pdo, array $row, string $email): array
    {
        $id = (int)($row['id'] ?? 0);
        if ($id <= 0) {
            return [];
        }
        $lead = (int)($row['primary_booking_id'] ?? 0) > 0 ? (int)$row['primary_booking_id'] : $id;
        $st = $pdo->prepare("SELECT * FROM bookings WHERE (id = ? OR primary_booking_id = ?) AND guest_email = ? AND deleted_at IS NULL
                AND status IN ('pending', 'tentative', 'confirmed') ORDER BY id ASC");
        $st->execute([$lead, $lead, $email]);
        $self = null;
        $rest = [];
        foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) {
            if ((int)$r['id'] === $id) {
                $self = $r;
            } else {
                $rest[] = $r;
            }
        }
        return $self === null ? [] : array_merge([$self], $rest);
    }

    /** True when the party's lead (or the looked-up row) is billed to a company account (billing_account_id, 084). */
    function rh_guest_cancel_company_billed(PDO $pdo, array $row): bool
    {
        if (!empty($row['billing_account_id'] ?? null)) {
            return true;
        }
        $lead = (int)($row['primary_booking_id'] ?? 0);
        if ($lead <= 0) {
            return false;
        }
        try {
            $st = $pdo->prepare('SELECT * FROM bookings WHERE id = ?');
            $st->execute([$lead]);
            $l = $st->fetch(PDO::FETCH_ASSOC);
            return $l ? !empty($l['billing_account_id'] ?? null) : false;
        } catch (Throwable $e) {
            error_log('rh_guest_cancel_company_billed: ' . $e->getMessage());
            return false;
        }
    }

    /** Escaped HTML block of a fine-print text (for emails and HTML documents). '' for ''. */
    function rh_cancel_policy_html(string $text, string $style = 'margin:0;font-size:13px;line-height:1.55;color:#5C4A32;'): string
    {
        $text = trim($text);
        return $text === '' ? '' : '<p style="' . $style . '">' . nl2br(htmlspecialchars($text, ENT_QUOTES, 'UTF-8')) . '</p>';
    }
}
