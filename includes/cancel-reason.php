<?php
/**
 * Reasons staff and guests give when a booking is cancelled or marked no-show (owner 2026-11-24: "When doing things
 * like cancelling bookings it's mandatory to put in notes ... the timeline, history, logs don't show the whys";
 * same day: a list of ready-made reasons to pick instead of typing, on every cancel prompt).
 *
 *   rh_cancel_reason_presets(string $context): string[]   the pick list: 'room' (staff room cancel), 'noshow',
 *                                                          'guest' (website cancel), 'conference', 'event', 'gym', 'slot' (gym slot);
 *                                                          the last entry is always rh_cancel_reason_other()
 *   rh_cancel_reason_presets_json(string $context): string the same list for data-admin-confirm-input-presets
 *   rh_reason_preset_select(string $context, string $id, string $targetId): string
 *                                                          a <select data-reason-preset-for> for a page's own textarea
 *                                                          (admin/js/admin-main.js fills the box when one is picked)
 *   rh_cancel_reason_check($raw, string $kind = 'cancel'): the staff rule (5 to 500 characters, not "Cancelled by
 *                                                          admin", not "Other" on its own); 'noshow' words it for a note
 *   rh_noshow_note_check($raw)                             = rh_cancel_reason_check($raw, 'noshow')
 *   rh_guest_cancel_reason_from_post(array $post)          the website cancel: a picked reason is required
 *                                                          ("Prefer not to say" counts), the typed text is optional
 *   rh_cancel_reason_history_line(string $reason)          "Reason: ..." exactly as History prints it
 *
 * Staff checks are made by the screens: Booking details (cancel / no-show) and All Bookings (row, bulk and Assistant
 * through admin/includes/booking-row-actions.php bookings_cancel_commit; the no-show action and bulk no-show) - NOT
 * inside cancelRoomBookingSettled(), rh_cancel_booking_group() or rh_mark_booking_no_show(), which keep their optional
 * reason for the hold-expiry job and the guest path. The presets are only text: the server always checks the result.
 */

if (!function_exists('rh_cancel_reason_check')) {

    /** Shortest and longest reason staff may give (same minimum as Delete booking). */
    function rh_cancel_reason_min(): int
    {
        return 5;
    }

    function rh_cancel_reason_max(): int
    {
        return 500;
    }

    /** The last entry of every pick list: choose it, then type the reason. */
    function rh_cancel_reason_other(): string
    {
        return 'Other (type below)';
    }

    /**
     * Ready-made reasons, plain hotel wording. Hardcoded (no settings screen). Unknown context = empty list.
     * @return string[]
     */
    function rh_cancel_reason_presets(string $context): array
    {
        $lists = [
            'room' => [
                'Guest asked to cancel',
                'Guest changed travel dates',
                'Double / duplicate booking',
                'No payment or deposit received',
                'Booked in error',
                'Room not available (maintenance / overbooking)',
                'Guest booked elsewhere',
                'Travel plans cancelled (flight / visa / illness)',
            ],
            'noshow' => [
                'Guest did not arrive, no contact',
                'Guest phoned, not coming',
                'Flight / transport delayed, not coming',
            ],
            'guest' => [
                'My travel plans changed',
                'I need different dates',
                'I booked somewhere else',
                'Flight, visa or illness',
                'I booked by mistake',
                'Prefer not to say',
            ],
            'conference' => [
                'Client cancelled the conference',
                'Conference postponed to new dates',
                'Moved to another venue',
                'No payment or deposit received',
                'Fewer delegates than planned',
                'Double / duplicate booking',
                'Booked in error',
            ],
            'event' => [
                'Client cancelled the event',
                'Event postponed to new dates',
                'Moved to another venue',
                'No payment or deposit received',
                'Double / duplicate booking',
                'Booked in error',
            ],
            'slot' => [
                'Member called to cancel',
                'Member did not turn up',
                'Gym closed or class cancelled',
                'Moved to another time slot',
                'Double / duplicate booking',
                'Booked in error',
            ],
            'gym' => [
                'Member asked to cancel',
                'Member moving away',
                'Health or medical reasons',
                'No payment received',
                'Joined another gym',
                'Booked in error',
            ],
        ];
        if (!isset($lists[$context])) {
            return [];
        }
        $list = $lists[$context];
        $list[] = rh_cancel_reason_other();
        return $list;
    }

    /** The pick list as JSON for an HTML attribute (escape it with htmlspecialchars(..., ENT_QUOTES)). */
    function rh_cancel_reason_presets_json(string $context): string
    {
        return (string)json_encode(rh_cancel_reason_presets($context), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }

    /**
     * A pick list for a page's own reason box: picking a reason fills <textarea id="$targetId"> (admin-main.js);
     * staff may add to it. Not submitted (no name): the textarea's text is what the server checks.
     */
    function rh_reason_preset_select(string $context, string $id, string $targetId, string $label = 'Pick a reason, or type your own below'): string
    {
        $presets = rh_cancel_reason_presets($context);
        if (!$presets) {
            return '';
        }
        $h = static fn(string $s): string => htmlspecialchars($s, ENT_QUOTES, 'UTF-8');
        $out = '<label for="' . $h($id) . '" class="form-label reason-preset-label">' . $h($label) . '</label>'
            . '<select id="' . $h($id) . '" class="form-control reason-preset-select" data-reason-preset-for="' . $h($targetId) . '">'
            . '<option value="">Choose a reason...</option>';
        foreach ($presets as $p) {
            $out .= '<option value="' . ($p === rh_cancel_reason_other() ? '__other' : $h($p)) . '">' . $h($p) . '</option>';
        }
        return $out . '</select>';
    }

    /**
     * Check a staff reason. The reason is trimmed and its runs of spaces / line breaks become one space, so the
     * History line, the audit note and the cancellation log all read the same.
     * Refused: empty, under 5 characters, over 500, the old automatic "Cancelled by admin" (a page loaded before this
     * change still posts it), or "Other" picked with nothing typed.
     * @param mixed $raw
     * @return array{ok:bool,reason:string,error:string}
     */
    function rh_cancel_reason_check($raw, string $kind = 'cancel'): array
    {
        $r = is_scalar($raw) ? (string)$raw : '';
        $collapsed = preg_replace('/\s+/u', ' ', $r);
        if ($collapsed === null) {
            $collapsed = preg_replace('/\s+/', ' ', $r); // not valid UTF-8: plain whitespace collapse
        }
        $r = trim((string)$collapsed);
        $min = rh_cancel_reason_min();
        $max = rh_cancel_reason_max();
        $noshow = $kind === 'noshow';
        $what = $noshow ? 'no-show note' : 'cancellation reason';
        $bare = rtrim($r, '. ');
        if ($r === '' || strcasecmp($bare, 'Cancelled by admin') === 0) {
            return ['ok' => false, 'reason' => '', 'error' => ($noshow
                ? 'Say why this booking is a no-show (at least ' . $min . ' characters).'
                : 'Say why this booking is being cancelled (at least ' . $min . ' characters).') . ' Nothing was changed.'];
        }
        if (strcasecmp($bare, rh_cancel_reason_other()) === 0 || strcasecmp($bare, 'Other') === 0) {
            return ['ok' => false, 'reason' => '', 'error' => 'You picked "Other": type the reason in the box. Nothing was changed.'];
        }
        $len = function_exists('mb_strlen') ? mb_strlen($r, 'UTF-8') : strlen($r);
        if ($len < $min) {
            return ['ok' => false, 'reason' => '', 'error' => 'The ' . $what . ' must be at least ' . $min . ' characters. Nothing was changed.'];
        }
        if ($len > $max) {
            return ['ok' => false, 'reason' => '', 'error' => 'Keep the ' . $what . ' to ' . $max . ' characters or fewer. Nothing was changed.'];
        }
        return ['ok' => true, 'reason' => $r, 'error' => ''];
    }

    /** Mark no-show from staff screens: the note is required, same rule as a cancel reason. */
    function rh_noshow_note_check($raw): array
    {
        return rh_cancel_reason_check($raw, 'noshow');
    }

    /**
     * The guest's own cancel on the website (booking-lookup.php): cancel_reason_choice must be one of the 'guest'
     * presets ("Prefer not to say" counts as an answer); cancel_reason is optional extra text (required only with
     * "Other"). The result is "<choice>" or "<choice> - <text>" (only the text for "Other"), at most 300 characters.
     * @return array{ok:bool,reason:string,error:string}
     */
    function rh_guest_cancel_reason_from_post(array $post): array
    {
        $choice = is_scalar($post['cancel_reason_choice'] ?? null) ? trim((string)$post['cancel_reason_choice']) : '';
        $text = is_scalar($post['cancel_reason'] ?? null) ? (string)$post['cancel_reason'] : '';
        $collapsed = preg_replace('/\s+/u', ' ', $text);
        $text = trim((string)($collapsed ?? preg_replace('/\s+/', ' ', $text)));
        if ($choice === '' || !in_array($choice, rh_cancel_reason_presets('guest'), true)) {
            return ['ok' => false, 'reason' => '', 'error' => 'Please choose a reason for cancelling.'];
        }
        if ($choice === rh_cancel_reason_other()) {
            if ($text === '') {
                return ['ok' => false, 'reason' => '', 'error' => 'You chose "Other": please tell us the reason in the box.'];
            }
            $reason = $text;
        } else {
            $reason = $choice . ($text !== '' ? ' - ' . $text : '');
        }
        $reason = function_exists('mb_substr') ? mb_substr($reason, 0, 300) : substr($reason, 0, 300);
        return ['ok' => true, 'reason' => $reason, 'error' => ''];
    }

    /**
     * "Reason: ..." exactly as the booking History prints a reason (includes/booking-history-format.php rh_hist_clip,
     * 200 characters), so the audit note's line and the timeline's line are the same and History shows it once.
     * $reason must already have passed rh_cancel_reason_check().
     */
    function rh_cancel_reason_history_line(string $reason): string
    {
        $s = trim((string)preg_replace('/\s+/u', ' ', $reason));
        if (function_exists('mb_strlen') && mb_strlen($s) > 200) {
            $s = mb_substr($s, 0, 199) . '…';
        }
        return 'Reason: ' . $s;
    }

    /**
     * The fixed text a staff cancel puts in the guest's cancellation email (owner 2026-11-25: "stay internal. Just send
     * the emails as you always have"). The staff reason never goes to the guest; these are the texts the screens sent
     * before the reason became required. 'hold' = Cancel Hold on Tentative Holds; anything else = a staff room cancel.
     */
    function rh_cancel_guest_email_reason(string $kind = 'room'): string
    {
        return $kind === 'hold' ? 'Tentative hold cancelled by hotel' : 'Cancelled by admin';
    }
}
