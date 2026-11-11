<?php
/**
 * Front-desk count rules shared by the Dashboard, the Calendar and the End of Day report/PDF/e-mail, so each number
 * counts exactly what its link opens. Pure (no database, no settings). Unit tested by scripts/tests/dashboard_counts.php.
 *
 * - Still to arrive (= Bookings "Today's Check-ins"): arrival date today, status confirmed or pending.
 * - Expected arrivals (End of Day, owner ASSUMPTION 2026-11-11): confirmed + pending + already checked in; tentative
 *   holds are NOT counted and are shown on their own line ("Tentative holds (not counted)") when there are any.
 * - Missed arrival (Calendar, Room Dashboard no-show count): confirmed or pending whose arrival date has passed.
 * - Departures with a balance (Dashboard chip -> Bookings "Today's Check-outs"): due out today, still in, owing.
 *   Overdue guests have their own "Overdue departures" chip.
 */

if (!function_exists('rh_fd_still_to_arrive_statuses')) {
    function rh_fd_still_to_arrive_statuses(): array
    {
        return ['confirmed', 'pending'];
    }

    function rh_fd_is_still_to_arrive(array $b, string $today): bool
    {
        return (string)($b['check_in_date'] ?? '') === $today
            && in_array(strtolower((string)($b['status'] ?? '')), rh_fd_still_to_arrive_statuses(), true);
    }

    /** How many rows are still to arrive today (rows: check_in_date, status). */
    function rh_fd_count_still_to_arrive(array $rows, string $today): int
    {
        $n = 0;
        foreach ($rows as $r) {
            if (rh_fd_is_still_to_arrive($r, $today)) $n++;
        }
        return $n;
    }

    /** A confirmed/pending booking whose arrival date has passed without a check-in. */
    function rh_fd_is_missed_arrival(string $status, string $checkInDate, string $today): bool
    {
        return in_array(strtolower($status), rh_fd_still_to_arrive_statuses(), true) && $checkInDate !== '' && $checkInDate < $today;
    }

    /**
     * Departures with a balance, matching Bookings "Today's Check-outs": departure_state 'due' (checked in, due out
     * today) and amount_due above the balance tolerance. Overdue and departed rows are not counted here.
     */
    function rh_fd_departures_owing(array $departureList): int
    {
        $tol = defined('BALANCE_TOLERANCE') ? (float)BALANCE_TOLERANCE : 0.01;
        $n = 0;
        foreach ($departureList as $d) {
            if ((string)($d['departure_state'] ?? '') === 'due' && (float)($d['amount_due'] ?? 0) > $tol) $n++;
        }
        return $n;
    }

    /** End of Day: '' or "Tentative holds (not counted): N". */
    function rh_fd_tentative_note(array $ops): string
    {
        $n = (int)($ops['tentative_arrivals'] ?? 0);
        return $n > 0 ? 'Tentative holds (not counted): ' . $n : '';
    }
}
