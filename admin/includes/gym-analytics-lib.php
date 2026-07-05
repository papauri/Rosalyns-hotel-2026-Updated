<?php

/**
 * Gym member analytics — pure derivations over gym_members + gym_attendance.
 *
 * Shared by admin/gym-members.php (per-member table columns, attendance
 * modal profile) and reusable from admin/gym-reports.php. No tables of its
 * own; everything is computed from rows the caller already has, so these
 * functions are unit-testable with plain arrays.
 */

if (!function_exists('gymDurationLabelFromDays')) {
    /**
     * Friendly duration label from a day count, snapping common gym periods
     * to their natural names (monthly / quarterly / yearly) and falling back
     * to "N days / weeks / months" otherwise.
     */
    function gymDurationLabelFromDays(int $days): string
    {
        if ($days <= 0)   return 'Open-ended';
        if ($days === 1)  return '1 Day';
        if ($days === 7)  return '1 Week';
        if ($days === 14) return '2 Weeks';
        if ($days === 30 || $days === 31) return 'Monthly';
        if ($days === 90 || $days === 91) return 'Quarterly';
        if ($days === 180 || $days === 182) return '6 Months';
        if ($days === 365 || $days === 366) return 'Yearly';
        if ($days % 365 === 0) return ($days / 365) . ' Years';
        if ($days % 30 === 0)  return ($days / 30) . ' Months';
        if ($days % 7 === 0)   return ($days / 7) . ' Weeks';
        return $days . ' Days';
    }
}

if (!function_exists('gymComputeExpiry')) {
    /**
     * Compute a membership expiry date from a start date + duration in days.
     * Returns Y-m-d, or null when the package is open-ended (no duration).
     * The expiry is the last day the membership is valid: start + (days - 1)
     * so a 1-day pass bought today expires today.
     */
    function gymComputeExpiry(string $startYmd, ?int $days): ?string
    {
        if ($days === null || $days <= 0) return null;
        $start = DateTime::createFromFormat('Y-m-d', $startYmd);
        if (!$start) return null;
        $start->modify('+' . ($days - 1) . ' days');
        return $start->format('Y-m-d');
    }
}

if (!function_exists('gym_frequency_label')) {
    /**
     * Visit-frequency label from visits in the last 30 days.
     *
     * Bands (documented so marketing copy can rely on them):
     *   Regular    ≥ 12 visits / 30d  (≈ 3+ per week)
     *   Active     ≥ 4  visits / 30d  (≈ weekly)
     *   Occasional ≥ 1  visit  / 30d
     *   New        joined < 14 days ago and no visits yet
     *   Inactive   0 visits / 30d
     *
     * @return array{label:string,color:string}
     */
    function gym_frequency_label(int $visits30d, ?string $startDate): array
    {
        $isNew = $startDate !== null && strtotime($startDate) !== false
            && strtotime($startDate) > strtotime('-14 days');
        if ($visits30d >= 12) {
            return ['label' => 'Regular', 'color' => '#2e7d32'];
        }
        if ($visits30d >= 4) {
            return ['label' => 'Active', 'color' => '#0c8d6c'];
        }
        if ($visits30d >= 1) {
            return ['label' => 'Occasional', 'color' => '#B18247'];
        }
        if ($isNew) {
            return ['label' => 'New', 'color' => '#1565c0'];
        }
        return ['label' => 'Inactive', 'color' => '#9e4040'];
    }
}

if (!function_exists('gym_member_segment')) {
    /**
     * Marketing segment — frequency + recency + membership state combined.
     *
     * Rules, in priority order:
     *   'Win-back — lapsed'      status expired/cancelled, or expiry passed
     *   'On hold'                status suspended
     *   'New member'             joined < 14 days ago
     *   'Loyal regular'          ≥ 12 visits / 30d
     *   'At risk — 14d+ absent'  has visited before, nothing in 14 days
     *   'Active'                 ≥ 4 visits / 30d
     *   'Occasional'             ≥ 1 visit / 30d
     *   'Never visited'          enrolled 14d+, zero attendance ever
     *
     * @param array $member  gym_members row (status, start_date, expiry_date)
     * @return array{segment:string,color:string,hint:string}
     */
    function gym_member_segment(array $member, int $visits30d, int $visitsTotal, ?string $lastVisitAt): array
    {
        $status = (string)($member['status'] ?? 'active');
        $expired = !empty($member['expiry_date']) && strtotime((string)$member['expiry_date']) < strtotime('today');

        if ($status === 'expired' || $status === 'cancelled' || ($status === 'active' && $expired)) {
            return ['segment' => 'Win-back — lapsed', 'color' => '#9e4040', 'hint' => 'Membership over: send a comeback offer.'];
        }
        if ($status === 'suspended') {
            return ['segment' => 'On hold', 'color' => '#6c757d', 'hint' => 'Suspended — check in before marketing.'];
        }
        $isNew = !empty($member['start_date']) && strtotime((string)$member['start_date']) > strtotime('-14 days');
        if ($isNew) {
            return ['segment' => 'New member', 'color' => '#1565c0', 'hint' => 'Onboarding window: welcome tips, induction offer.'];
        }
        if ($visits30d >= 12) {
            return ['segment' => 'Loyal regular', 'color' => '#2e7d32', 'hint' => 'Reward loyalty: referral or upgrade offers.'];
        }
        $absent14 = $visitsTotal > 0 && ($lastVisitAt === null || strtotime($lastVisitAt) < strtotime('-14 days'));
        if ($absent14) {
            return ['segment' => 'At risk — 14d+ absent', 'color' => '#c0392b', 'hint' => 'Re-engagement nudge before they lapse.'];
        }
        if ($visits30d >= 4) {
            return ['segment' => 'Active', 'color' => '#0c8d6c', 'hint' => 'Healthy habit: class invites, add-ons.'];
        }
        if ($visits30d >= 1) {
            return ['segment' => 'Occasional', 'color' => '#B18247', 'hint' => 'Encourage routine: off-peak or buddy offers.'];
        }
        return ['segment' => 'Never visited', 'color' => '#6c757d', 'hint' => 'Activation push: first-visit reminder.'];
    }
}

if (!function_exists('gym_peak_profile')) {
    /**
     * Personal peak-time profile from per-hour / per-weekday check-in counts.
     *
     * @param array<int,int> $hourCounts    hour-of-day (0-23) => check-ins
     * @param array<int,int> $weekdayCounts MySQL WEEKDAY (0=Mon) => check-ins
     * @return array{top_slot:?string,summary:?string,band:?string,top_hours:array,top_weekdays:array}
     */
    function gym_peak_profile(array $hourCounts, array $weekdayCounts): array
    {
        if (empty($hourCounts)) {
            return ['top_slot' => null, 'summary' => null, 'band' => null, 'top_hours' => [], 'top_weekdays' => []];
        }
        arsort($hourCounts);
        arsort($weekdayCounts);
        $topHours = array_slice(array_keys($hourCounts), 0, 2);
        sort($topHours);
        $names = ['Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat', 'Sun'];
        $topDays = array_map(static fn($d) => $names[(int)$d] ?? '?', array_slice(array_keys($weekdayCounts), 0, 2));

        $primary = (int)$topHours[0];
        $band = $primary < 12 ? 'Mornings' : ($primary < 17 ? 'Afternoons' : 'Evenings');
        $slot = sprintf('%02d:00–%02d:00', $primary, ($primary + 1) % 24);
        $slotWide = count($topHours) === 2 && abs($topHours[1] - $topHours[0]) <= 2
            ? sprintf('%02d:00–%02d:00', min($topHours), (max($topHours) + 1) % 24)
            : $slot;

        return [
            'top_slot'     => $slot,
            'band'         => $band,
            'summary'      => $band . ' ' . $slotWide . ' · mostly ' . implode('/', $topDays),
            'top_hours'    => $topHours,
            'top_weekdays' => array_slice(array_keys($weekdayCounts), 0, 2),
        ];
    }
}

if (!function_exists('gym_days_to_expiry')) {
    /**
     * Days-to-expiry pill data. Colour bands: red ≤7, amber ≤max(30, reminder
     * window), green beyond, grey for no expiry / already lapsed states.
     *
     * @return array{days:?int,label:string,color:string,bg:string}
     */
    function gym_days_to_expiry(?string $expiryDate, string $status, int $reminderDays = 3): array
    {
        if (empty($expiryDate) || strtotime($expiryDate) === false) {
            return ['days' => null, 'label' => 'No expiry', 'color' => '#6c757d', 'bg' => '#f0ebe3'];
        }
        $days = (int)floor((strtotime($expiryDate) - strtotime('today')) / 86400);
        if ($status !== 'active') {
            return ['days' => $days, 'label' => ucfirst($status), 'color' => '#6c757d', 'bg' => '#f0ebe3'];
        }
        if ($days < 0) {
            return ['days' => $days, 'label' => 'Lapsed ' . abs($days) . 'd ago', 'color' => '#fff', 'bg' => '#9e4040'];
        }
        if ($days === 0) {
            return ['days' => 0, 'label' => 'Expires today', 'color' => '#fff', 'bg' => '#c0392b'];
        }
        if ($days <= 7) {
            return ['days' => $days, 'label' => $days . 'd left', 'color' => '#fff', 'bg' => '#c0392b'];
        }
        if ($days <= max(30, $reminderDays)) {
            return ['days' => $days, 'label' => $days . 'd left', 'color' => '#7a5f00', 'bg' => '#ffe9a8'];
        }
        return ['days' => $days, 'label' => $days . 'd left', 'color' => '#1b5e20', 'bg' => '#dcecdd'];
    }
}
