<?php
/**
 * Email send log (table email_send_log, migration 081; owner 2026-11-12: "full logs of the whos and the whens").
 *
 * Loading this file connects to nothing and includes nothing. The pure helpers (kinds, words, who, copies, the cancel
 * e-mail choice, the history fold) are tested by scripts/tests/email_send_log_test.php. The writers use the app's
 * $GLOBALS['pdo'] only when called, never throw, and record nothing until migration 081 has run.
 *
 * Context: a sender says what an email is about before it sends:
 *     $emailCtx = rh_email_context(['kind' => 'booking_cancelled'] + rh_email_ctx_account('room', $id, $ref));
 * The returned guard must be kept in a variable: when the function returns (any return path, or an exception) the
 * variable goes away and the context with it, so it never leaks into a later, unrelated send. Contexts stack: an inner
 * one merges over the outer (the scheduler's "System (scheduler)" stays, the sender adds kind and account).
 * Keys: kind, account_type, account_id, account_ref, by_label, by_user_id; is_test + test_of for "Send test" (kind 'test').
 */

if (!function_exists('rh_email_kinds')) {

    /** kind => [plain label (no trailing "email")]. Screens add " email" ("Test" reads "Test email"). */
    function rh_email_kinds(): array
    {
        return [
            'booking_received' => ['Booking received'],
            'booking_confirmed' => ['Booking confirmed'],
            'booking_modified' => ['Booking changed'],
            'booking_cancelled' => ['Booking cancelled'],
            'booking_status_update' => ['Booking status update'],
            'booking_reminder' => ['Booking reminder'],
            'booking_room_upgrade' => ['Room upgrade'],
            'booking_no_show' => ['No-show notice'],
            'booking_extend_stay' => ['Stay extended'],
            'booking_quotation' => ['Quotation'],
            'tentative_confirmed' => ['Booking on hold'],
            'tentative_reminder' => ['Hold reminder'],
            'tentative_expired' => ['Hold expired'],
            'tentative_converted' => ['Hold confirmed as a booking'],
            'tentative_quotation' => ['Quotation'],
            'pending_expired' => ['Unconfirmed booking expired'],
            'unpaid_reminder' => ['Unpaid booking reminder'],
            'unpaid_released' => ['Unpaid booking released'],
            'payment_reminder' => ['Payment reminder'],
            'quotation_reminder' => ['Quotation expiry reminder'],
            'pre_arrival' => ['Before-arrival'],
            'post_stay_review' => ['After-stay review request'],
            'invoice' => ['Invoice'],
            'company_statement' => ['Company account statement'],
            'company_payment_advice' => ['Company payment received'],
            'receipt' => ['Receipt'],
            'credit_note' => ['Credit note'],
            'refund' => ['Refund notice'],
            'staff_new_booking_alert' => ['New booking alert to staff'],
            'staff_booking_expired_alert' => ['Booking expired alert to staff'],
            'conference_enquiry' => ['Conference enquiry received'],
            'conference_staff_alert' => ['New conference enquiry alert to staff'],
            'conference_payment' => ['Conference payment'],
            'conference_confirmed' => ['Conference confirmed'],
            'conference_cancelled' => ['Conference cancelled'],
            'conference_quotation' => ['Conference quotation'],
            'gym_booking' => ['Gym booking received'],
            'gym_staff_alert' => ['New gym booking alert to staff'],
            'gym_confirmed' => ['Gym booking confirmed'],
            'gym_cancelled' => ['Gym booking cancelled'],
            'gym_quotation' => ['Gym quotation'],
            'gym_inquiry_contacted' => ['Gym enquiry reply'],
            'gym_inquiry_converted' => ['Gym enquiry converted'],
            'gym_member_card' => ['Gym member card'],
            'gym_class_reminder' => ['Gym class reminder'],
            'gym_renewal_reminder' => ['Gym renewal reminder'],
            'event_confirmed' => ['Event booking confirmed'],
            'event_cancelled' => ['Event booking cancelled'],
            'event_quotation' => ['Event quotation'],
            'contact_reply' => ['Reply to a contact message'],
            'review_ack' => ['Review thank-you'],
            'review_reply' => ['Reply to a review'],
            'staff_welcome' => ['Staff account invitation'],
            'password_code' => ['Password change code'],
            'daily_report' => ['Daily report'],
            'end_of_day_report' => ['End of day report'],
            'station_report' => ['Station report'],
            'stock_order' => ['Stock order'],
            'test' => ['Test'],
            'other' => ['Other'],
        ];
    }

    function rh_email_kind_label(string $kind): string
    {
        if ($kind === 'test_email') {
            $kind = 'test'; // rows written on 2026-11-12 before test sends got their own kind
        }
        $k = rh_email_kinds();
        if (isset($k[$kind])) {
            return $k[$kind][0];
        }
        $kind = trim(str_replace('_', ' ', $kind));
        return $kind !== '' ? ucfirst($kind) : 'Other';
    }

    /** A safe kind key: lower-case letters, digits and _ only, 40 characters at most. */
    function rh_email_kind_clean($kind): string
    {
        $kind = strtolower(trim((string)$kind));
        return preg_match('/^[a-z0-9_]{1,40}$/', $kind) ? $kind : 'other';
    }

    /** The Result column: Sent / Failed: reason / Not sent: reason / Preview only (not sent). */
    function rh_email_result_label(string $status, string $error = ''): string
    {
        $error = trim(preg_replace('/\s+/', ' ', $error));
        if (strlen($error) > 200) {
            $error = substr($error, 0, 197) . '...';
        }
        switch ($status) {
            case 'sent':
                return 'Sent';
            case 'failed':
                return 'Failed' . ($error !== '' ? ': ' . $error : '');
            case 'not_sent':
                return 'Not sent' . ($error !== '' ? ': ' . $error : '');
            case 'preview':
                return 'Preview only (not sent)';
        }
        return 'Unknown';
    }

    /** The plain word for a result filter / pill. */
    function rh_email_result_word(string $status): string
    {
        return ['sent' => 'Sent', 'failed' => 'Failed', 'not_sent' => 'Not sent', 'preview' => 'Preview'][$status] ?? 'Unknown';
    }

    /* ── context ─────────────────────────────────────────────────────── */

    /** Account keys for a context; empty values are left out so an outer context keeps its own. */
    function rh_email_ctx_account(string $type, $id = null, $ref = null): array
    {
        $out = [];
        $type = strtolower(trim($type));
        if ($type !== '' && preg_match('/^[a-z_]{1,20}$/', $type)) {
            $out['account_type'] = $type;
        }
        if ((int)$id > 0) {
            $out['account_id'] = (int)$id;
        }
        $ref = trim((string)$ref);
        if ($ref !== '') {
            $out['account_ref'] = substr($ref, 0, 64);
        }
        return $out;
    }

    /**
     * Push a context (merged over the current one). Keep the returned guard in a local variable; the context ends
     * when the guard goes away. rh_email_context_clear() drops every context (end of a top-level handler).
     */
    function rh_email_context(array $ctx): object
    {
        if (!isset($GLOBALS['rh_email_ctx_stack']) || !is_array($GLOBALS['rh_email_ctx_stack'])) {
            $GLOBALS['rh_email_ctx_stack'] = [];
        }
        $top = rh_email_context_current();
        $clean = [];
        foreach ($ctx as $k => $v) {
            if ($v === null || $v === '') {
                continue;
            }
            $clean[$k] = $v;
        }
        $GLOBALS['rh_email_ctx_seq'] = (int)($GLOBALS['rh_email_ctx_seq'] ?? 0) + 1;
        $id = $GLOBALS['rh_email_ctx_seq'];
        $GLOBALS['rh_email_ctx_stack'][$id] = array_merge($top, $clean);
        return new class ($id) {
            private $id;

            public function __construct(int $id)
            {
                $this->id = $id;
            }

            public function __destruct()
            {
                unset($GLOBALS['rh_email_ctx_stack'][$this->id]);
            }
        };
    }

    function rh_email_context_current(): array
    {
        $stack = $GLOBALS['rh_email_ctx_stack'] ?? [];
        if (!is_array($stack) || !$stack) {
            return [];
        }
        $top = end($stack);
        return is_array($top) ? $top : [];
    }

    function rh_email_context_clear(): void
    {
        $GLOBALS['rh_email_ctx_stack'] = [];
    }

    /** Drop every open context, then push this one: for work that must never inherit (the scheduler). */
    function rh_email_context_fresh(array $ctx): object
    {
        rh_email_context_clear();
        return rh_email_context($ctx);
    }

    /* ── who ─────────────────────────────────────────────────────────── */

    /** "Mercy Kamanga (receptionist)": full name (else username) and the role in words. */
    function rh_email_staff_label(string $fullName, string $username = '', string $role = ''): string
    {
        $name = trim($fullName) !== '' ? trim($fullName) : trim($username);
        if ($name === '') {
            $name = 'Staff member';
        }
        $role = trim(str_replace('_', ' ', $role));
        return $name . ($role !== '' ? ' (' . $role . ')' : '');
    }

    /**
     * Who sent it: [user id or null, label]. Order: the context's by_label (the scheduler sets "System (scheduler)"),
     * a signed-in staff member on an admin page, the command line ("System"), an admin page without a session
     * ("System"), the key API ("Online booking (API)"), otherwise the guest website.
     */
    function rh_email_who(array $ctx, array $session, string $sapi, string $script): array
    {
        if (trim((string)($ctx['by_label'] ?? '')) !== '') {
            $uid = (int)($ctx['by_user_id'] ?? 0);
            return [$uid > 0 ? $uid : null, trim((string)$ctx['by_label'])];
        }
        $script = str_replace('\\', '/', $script);
        $isAdmin = strpos($script, '/admin/') !== false;
        $uid = (int)($session['admin_user_id'] ?? 0);
        if ($uid > 0 && $isAdmin) {
            return [$uid, rh_email_staff_label((string)($session['admin_full_name'] ?? ''), (string)($session['admin_username'] ?? ''), (string)($session['admin_role'] ?? ''))];
        }
        if ($sapi === 'cli' || $isAdmin) {
            return [null, 'System'];
        }
        if (strpos($script, '/api/') !== false) {
            return [null, 'Online booking (API)'];
        }
        return [null, 'Guest website'];
    }

    /* ── recipients ──────────────────────────────────────────────────── */

    /** PHPMailer address lists ([[address, name], ...]) or plain strings -> unique lower-case addresses. */
    function rh_email_addr_list(array $list): array
    {
        $out = [];
        foreach ($list as $a) {
            $addr = is_array($a) ? (string)($a[0] ?? '') : (string)$a;
            $addr = strtolower(trim($addr));
            if ($addr !== '' && !in_array($addr, $out, true)) {
                $out[] = $addr;
            }
        }
        return $out;
    }

    /** "a@x.com, b@y.com (hidden)": visible copies first, hidden copies marked; an address in both shows once. */
    function rh_email_copy_text(array $cc, array $bcc = []): string
    {
        $cc = rh_email_addr_list($cc);
        $parts = $cc;
        foreach (rh_email_addr_list($bcc) as $b) {
            if (!in_array($b, $cc, true)) {
                $parts[] = $b . ' (hidden)';
            }
        }
        return implode(', ', $parts);
    }

    /* ── cancel without emailing (owner 2026-11-12) ──────────────────── */

    /**
     * Whether the "Email the guest" box starts ticked: exactly when the old code emailed. All Bookings list
     * ('list'): confirmed bookings only. Booking page ('details') and tentative holds ('tentative'): always.
     */
    function rh_cancel_email_default_ticked(string $path, string $previousStatus): bool
    {
        if ($path === 'list') {
            return strtolower(trim($previousStatus)) === 'confirmed';
        }
        return true;
    }

    /**
     * The staff choice from the form: a hidden email_guest=0 comes before the checkbox email_guest=1, so ticked = '1',
     * unticked = '0'. Absent (a page loaded before this change) = the old behaviour ($wouldEmail).
     */
    function rh_cancel_email_choice(array $post, bool $wouldEmail): bool
    {
        if (!array_key_exists('email_guest', $post)) {
            return $wouldEmail;
        }
        $v = $post['email_guest'];
        if (is_array($v)) {
            $v = end($v);
        }
        return $wouldEmail && (string)$v === '1';
    }

    /**
     * Check-in "Email the guest a check-in confirmation" box (owner 2026-11-12, ticked by default). Field present:
     * '1' = send, '0' = staff chose not to. Absent (a page loaded before this change, the Assistant's check-in) =
     * $defaultWhenAbsent, today's behaviour for that path (dashboard: send; booking page / Bookings list: no email).
     */
    function rh_checkin_email_choice(array $post, bool $defaultWhenAbsent): bool
    {
        if (!array_key_exists('email_guest', $post)) {
            return $defaultWhenAbsent;
        }
        return rh_cancel_email_choice($post, true);
    }

    /** The reason stored on the log rows when staff untick the box. */
    function rh_cancel_email_skipped_reason(): string
    {
        return 'Not sent: staff chose not to email the guest';
    }

    /* ── wording for screens ─────────────────────────────────────────── */

    /** "Room booking RBH-1234" / "Conference CNF-1" / "" (no account). */
    function rh_email_about_label(?string $type, ?string $ref, $id = null): string
    {
        $type = strtolower(trim((string)$type));
        $ref = trim((string)$ref);
        if ($type === '' && $ref === '') {
            return '';
        }
        $names = ['room' => 'Room booking', 'conference' => 'Conference', 'gym' => 'Gym', 'event' => 'Event', 'pos' => 'Till order',
            'payment' => 'Payment', 'credit_note' => 'Credit note', 'staff' => 'Staff account', 'report' => 'Report', 'review' => 'Review',
            'contact' => 'Contact message', 'stock' => 'Stock order'];
        $label = $names[$type] ?? ($type !== '' ? ucfirst(str_replace('_', ' ', $type)) : '');
        if ($ref === '' && (int)$id > 0) {
            $ref = '#' . (int)$id;
        }
        return trim($label . ' ' . $ref);
    }

    /** "Booking cancelled email" + " to guest@x" (+ " (not sent)" etc.) for the history and the timeline. */
    function rh_email_what(array $row): string
    {
        $kind = rh_email_kind_label((string)($row['email_kind'] ?? 'other'));
        $to = trim((string)($row['to_email'] ?? ''));
        $status = (string)($row['status'] ?? '');
        $base = ($kind === 'Other' ? 'Email' : $kind . ' email');
        if ($status === 'sent') {
            return $base . ' sent' . ($to !== '' ? ' to ' . $to : '');
        }
        if ($status === 'failed') {
            return $base . ' could not be sent' . ($to !== '' ? ' to ' . $to : '');
        }
        if ($status === 'not_sent') {
            return $base . ' not sent' . ($to !== '' ? ' to ' . $to : '') . ' (staff chose not to)';
        }
        if ($status === 'preview') {
            return $base . ' saved as a preview only' . ($to !== '' ? ' for ' . $to : '');
        }
        return $base . ($to !== '' ? ' to ' . $to : '');
    }

    /** One timeline line: "10:42 · Booking cancelled email to guest@x · by Mercy K. (receptionist) · RBH-1 · Sent". */
    function rh_email_timeline_line(array $row): string
    {
        $ts = strtotime((string)($row['sent_at'] ?? ''));
        $parts = [$ts ? date('H:i', $ts) : '--:--'];
        $kind = rh_email_kind_label((string)($row['email_kind'] ?? 'other'));
        $to = trim((string)($row['to_email'] ?? ''));
        $parts[] = ($kind === 'Other' ? 'Email' : $kind . ' email') . ($to !== '' ? ' to ' . $to : '');
        $by = trim((string)($row['sent_by_label'] ?? ''));
        if ($by !== '') {
            $parts[] = 'by ' . $by;
        }
        $ref = trim((string)($row['account_ref'] ?? ''));
        if ($ref !== '') {
            $parts[] = $ref;
        }
        $parts[] = rh_email_result_word((string)($row['status'] ?? ''));
        return implode(' · ', $parts);
    }

    /** Rows (newest first) grouped by day: ['2026-11-12' => [rows...], ...], order kept. */
    function rh_email_group_by_day(array $rows): array
    {
        $out = [];
        foreach ($rows as $r) {
            $ts = strtotime((string)($r['sent_at'] ?? ''));
            $day = $ts ? date('Y-m-d', $ts) : 'unknown';
            $out[$day][] = $r;
        }
        return $out;
    }

    /**
     * System Logs filters (GET, all prefixed em_): from / to (default the last 7 days, at most 92 days), kind, result,
     * search text, view (table | timeline).
     */
    function rh_email_log_filters(array $get, string $today): array
    {
        $isDate = static function ($v): bool {
            return is_string($v) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $v) && strtotime($v) !== false;
        };
        $to = $isDate($get['em_to'] ?? null) ? (string)$get['em_to'] : $today;
        $from = $isDate($get['em_from'] ?? null) ? (string)$get['em_from'] : date('Y-m-d', strtotime($today . ' -6 days'));
        if ($from > $to) {
            [$from, $to] = [$to, $from];
        }
        $clamped = false;
        if ((strtotime($to) - strtotime($from)) / 86400 > 91) {
            $from = date('Y-m-d', strtotime($to . ' -91 days'));
            $clamped = true;
        }
        $kind = is_string($get['em_kind'] ?? null) ? (string)$get['em_kind'] : 'all';
        if ($kind !== 'all' && !isset(rh_email_kinds()[$kind])) {
            $kind = 'all';
        }
        $result = is_string($get['em_result'] ?? null) ? (string)$get['em_result'] : 'all';
        if (!in_array($result, ['all', 'sent', 'failed', 'not_sent', 'preview'], true)) {
            $result = 'all';
        }
        $q = is_string($get['em_q'] ?? null) ? trim((string)$get['em_q']) : '';
        if (function_exists('mb_substr')) {
            $q = mb_substr($q, 0, 100);
        } else {
            $q = substr($q, 0, 100);
        }
        $view = ($get['em_view'] ?? '') === 'timeline' ? 'timeline' : 'table';
        return ['from' => $from, 'to' => $to, 'kind' => $kind, 'result' => $result, 'q' => $q, 'view' => $view, 'clamped' => $clamped];
    }

    /* ── booking history: one step per email, never twice ────────────── */

    /**
     * The fixed map: email kind => the keys of the older history record that is the same email (includes/
     * booking-history-format.php). A key ending in ':' is a prefix (auto:<job>: = any stage of that scheduler job).
     * A kind not listed (booking changed, status update, booking reminder...) has no older record: it shows on its own.
     */
    function rh_email_hist_keys(): array
    {
        $atBooking = 'email:' . md5('booking_created');        // admin/create-booking.php "Emails at booking"
        $quote = ['quote', 'tent:quotation_sent'];             // bookings.last_quotation_sent_at, tentative_booking_log
        return [
            'booking_received' => [$atBooking],
            'booking_confirmed' => [$atBooking],
            'tentative_confirmed' => [$atBooking],
            'booking_quotation' => $quote,
            'tentative_quotation' => $quote,
            'tentative_reminder' => ['auto:tentative_hold_reminder:', 'tent:reminder_sent'],
            'tentative_expired' => ['auto:tentative_expired_notice:'],
            'pending_expired' => ['auto:pending_expired_notice:'],
            'unpaid_reminder' => ['auto:unpaid_confirmed_reminder:', 'email:' . md5('Payment reminder sent')],
            'unpaid_released' => ['auto:unpaid_confirmed_release:'],
            'payment_reminder' => ['auto:overdue_payment_reminders:'],
            'quotation_reminder' => ['auto:quotation_expiry_reminder:'],
            'pre_arrival' => ['comm:pre_arrival'],                // guest_communication_log stages
            'post_stay_review' => ['comm:post_stay'],
            'invoice' => ['email:invoice'],                       // booking audit "Invoice email sent/resent"
            'receipt' => ['receipt:'],
            'credit_note' => ['cnemail:'],
        ];
    }

    /** Is an older history record (its keys) the same email as this kind? Only through the fixed map above. */
    function rh_email_hist_matches(string $kind, array $keys): bool
    {
        $map = rh_email_hist_keys();
        if (!isset($map[$kind])) {
            return false;
        }
        foreach ($keys as $key) {
            $key = (string)$key;
            foreach ($map[$kind] as $m) {
                if (substr($m, -1) === ':' ? (strpos($key, $m) === 0 && strlen($key) > strlen($m)) : $key === $m) {
                    return true;
                }
            }
        }
        return false;
    }

    /**
     * Fold each send-log step into the older record of the same email (an 'email' step whose keys the fixed map
     * gives for this kind, within $window seconds, nearest first). $events are one booking's history, so the match is
     * always the same booking. The older record keeps its wording and gains the send-log line (to, copies, who). A
     * step with no match stays as its own step. $sendEvents carry 'email_kind'. Returns all events.
     */
    function rh_email_hist_fold(array $events, array $sendEvents, int $window = 120): array
    {
        $events = array_values(array_filter($events));
        $used = [];
        foreach ($sendEvents as $s) {
            if (!$s) {
                continue;
            }
            $best = null;
            $bestGap = null;
            foreach ($events as $i => $e) {
                if (isset($used[$i]) || ($e['kind'] ?? '') !== 'email' || !empty($e['sendlog'])) {
                    continue;
                }
                $gap = abs((int)$e['ts'] - (int)$s['ts']);
                if ($gap > $window) {
                    continue;
                }
                if (!rh_email_hist_matches((string)($s['email_kind'] ?? ''), (array)($e['keys'] ?? []))) {
                    continue;
                }
                if ($bestGap === null || $gap < $bestGap) {
                    $best = $i;
                    $bestGap = $gap;
                }
            }
            if ($best === null) {
                $s['sendlog'] = true;
                $events[] = $s;
                continue;
            }
            $used[$best] = true;
            $x = $events[$best];
            $who = trim((string)($x['who'] ?? ''));
            if ($who === '' || $who === 'Not recorded' || $who === 'System') {
                $x['who'] = (string)$s['who'];
            }
            $lines = [];
            foreach ([(string)($x['detail'] ?? ''), (string)$s['what'], (string)($s['detail'] ?? '')] as $d) {
                foreach (preg_split('/\n/', $d) as $ln) {
                    $ln = trim($ln);
                    if ($ln !== '' && !in_array($ln, $lines, true)) {
                        $lines[] = $ln;
                    }
                }
            }
            $x['detail'] = implode("\n", $lines);
            $x['keys'] = array_values(array_unique(array_merge((array)($x['keys'] ?? []), (array)($s['keys'] ?? []))));
            $events[$best] = $x;
        }
        foreach ($events as &$e) {
            unset($e['sendlog'], $e['email_kind']);
        }
        unset($e);
        return $events;
    }

    /* ── database (app $pdo only, never throws) ──────────────────────── */

    function rh_email_send_log_ready($pdo = null): bool
    {
        $pdo = $pdo instanceof PDO ? $pdo : ($GLOBALS['pdo'] ?? null);
        if (!($pdo instanceof PDO)) {
            return false;
        }
        static $ready = [];
        $k = spl_object_id($pdo);
        if (!isset($ready[$k])) {
            try {
                $ready[$k] = $pdo->query("SHOW TABLES LIKE 'email_send_log'")->rowCount() > 0;
            } catch (Throwable $e) {
                $ready[$k] = false;
            }
        }
        return $ready[$k];
    }

    /**
     * A "Send test" (context is_test, set by Automated Emails and the email template test sends): logged as kind
     * 'test' with no account, so it never shows in a booking's History or under a real kind; the email it tested goes
     * in the subject ("[Test: Hold reminder] ..."). Applied last, over the context and the row. Returns [kind, account, subject].
     */
    function rh_email_test_apply(array $ctx, string $kind, array $acct, string $subject): array
    {
        if (empty($ctx['is_test'])) {
            return [$kind, $acct, $subject];
        }
        $of = trim((string)($ctx['test_of'] ?? ''));
        if ($of === '') {
            $of = in_array($kind, ['other', 'test', 'test_email'], true) ? 'email template' : rh_email_kind_label($kind);
        }
        $subject = trim((string)preg_replace('/^\s*\[TEST\]\s*/i', '', $subject));
        return ['test', ['account_type' => null, 'account_id' => null, 'account_ref' => null], '[Test: ' . $of . '] ' . $subject];
    }

    function rh_email_clip(string $s, int $len): string
    {
        return function_exists('mb_substr') ? mb_substr($s, 0, $len) : substr($s, 0, $len);
    }

    /**
     * Write one row. $row: status, to, to_name, cc (text), subject, error, kind (else the context's, else 'other').
     * Account and who come from the context / session (rh_email_who). Never throws; nothing before migration 081.
     */
    function rh_email_send_log_write(array $row): void
    {
        try {
            $pdo = $GLOBALS['pdo'] ?? null;
            if (!($pdo instanceof PDO) || !rh_email_send_log_ready($pdo)) {
                return;
            }
            $ctx = rh_email_context_current();
            $session = (isset($_SESSION) && is_array($_SESSION)) ? $_SESSION : [];
            [$uid, $by] = rh_email_who($ctx, $session, PHP_SAPI, (string)($_SERVER['SCRIPT_NAME'] ?? ''));
            $status = in_array($row['status'] ?? '', ['sent', 'failed', 'preview', 'not_sent'], true) ? $row['status'] : 'failed';
            $kind = rh_email_kind_clean($row['kind'] ?? ($ctx['kind'] ?? 'other'));
            $acct = array_merge(
                ['account_type' => $ctx['account_type'] ?? null, 'account_id' => $ctx['account_id'] ?? null, 'account_ref' => $ctx['account_ref'] ?? null],
                array_intersect_key($row, ['account_type' => 1, 'account_id' => 1, 'account_ref' => 1])
            );
            [$kind, $acct, $subject] = rh_email_test_apply($ctx, $kind, $acct, (string)($row['subject'] ?? ''));
            $err = trim((string)($row['error'] ?? ''));
            $st = $pdo->prepare('INSERT INTO email_send_log (sent_at, status, to_email, to_name, cc, subject, email_kind, account_type, account_id, account_ref,
                    sent_by_user_id, sent_by_label, error) VALUES (NOW(), ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)');
            $st->execute([
                $status,
                rh_email_clip(trim((string)($row['to'] ?? '')), 500),
                trim((string)($row['to_name'] ?? '')) !== '' ? rh_email_clip(trim((string)$row['to_name']), 255) : null,
                trim((string)($row['cc'] ?? '')) !== '' ? rh_email_clip(trim((string)$row['cc']), 4000) : null,
                rh_email_clip(trim($subject), 500),
                $kind,
                ($acct['account_type'] ?? '') !== '' && $acct['account_type'] !== null ? rh_email_clip((string)$acct['account_type'], 20) : null,
                (int)($acct['account_id'] ?? 0) > 0 ? (int)$acct['account_id'] : null,
                trim((string)($acct['account_ref'] ?? '')) !== '' ? rh_email_clip(trim((string)$acct['account_ref']), 64) : null,
                $uid,
                rh_email_clip($by, 255),
                $err !== '' ? rh_email_clip($err, 1000) : null,
            ]);
        } catch (Throwable $e) {
            error_log('email_send_log write: ' . $e->getMessage());
        }
    }

    /**
     * Log a PHPMailer send (or failure) from the real message: To from the message (else $fallbackTo), visible and
     * hidden copies from the message. $mail may be null (failure before the message was built).
     */
    function rh_email_send_log_mail(string $status, $mail, string $subject, string $error = '', string $fallbackTo = '', ?string $fallbackName = null, array $extra = []): void
    {
        try {
            $to = [];
            $toName = $fallbackName;
            $cc = [];
            $bcc = [];
            if (is_object($mail) && method_exists($mail, 'getToAddresses')) {
                $toList = $mail->getToAddresses();
                $to = rh_email_addr_list($toList);
                if ($toName === null && isset($toList[0][1]) && count($toList) === 1) {
                    $toName = (string)$toList[0][1];
                }
                $cc = method_exists($mail, 'getCcAddresses') ? $mail->getCcAddresses() : [];
                $bcc = method_exists($mail, 'getBccAddresses') ? $mail->getBccAddresses() : [];
            }
            if (!$to && trim($fallbackTo) !== '') {
                $to = [trim($fallbackTo)];
            }
            $copyText = rh_email_copy_text($cc, $bcc);
            $tpOrig = function_exists('rh_test_platform_mail_original') ? rh_test_platform_mail_original($mail) : null;
            if ($tpOrig !== null) {
                // Test platform redirect: the copy column records who it would have gone to; subject as sent.
                $copyText = rh_test_platform_log_text($tpOrig);
                if (trim((string)($mail->Subject ?? '')) !== '') {
                    $subject = (string)$mail->Subject;
                }
            }
            rh_email_send_log_write($extra + [
                'status' => $status,
                'to' => implode(', ', $to),
                'to_name' => (string)$toName,
                'cc' => $copyText,
                'subject' => $subject,
                'error' => $error,
            ]);
        } catch (Throwable $e) {
            error_log('email_send_log mail: ' . $e->getMessage());
        }
    }

    /** The staff unticked "Email the guest" on a cancel: record the decision (status not_sent). */
    function rh_email_send_log_not_sent(string $kind, string $to, string $toName, array $account, string $reason): void
    {
        rh_email_send_log_write($account + [
            'status' => 'not_sent',
            'kind' => $kind,
            'to' => $to,
            'to_name' => $toName,
            'subject' => '',
            'error' => $reason,
        ]);
    }

    /** System Logs list: newest first, at most $limit rows. Returns [rows, more] (more = the cap was hit). */
    function rh_email_send_log_list(PDO $pdo, array $f, int $limit = 500): array
    {
        if (!rh_email_send_log_ready($pdo)) {
            return [[], false];
        }
        $where = ['sent_at >= ?', 'sent_at < DATE_ADD(?, INTERVAL 1 DAY)'];
        $args = [$f['from'] . ' 00:00:00', $f['to']];
        if (($f['kind'] ?? 'all') === 'test') {
            $where[] = "email_kind IN ('test', 'test_email')"; // test_email: the first day's test sends
        } elseif (($f['kind'] ?? 'all') !== 'all') {
            $where[] = 'email_kind = ?';
            $args[] = (string)$f['kind'];
        }
        if (($f['result'] ?? 'all') !== 'all') {
            $where[] = 'status = ?';
            $args[] = (string)$f['result'];
        }
        $q = trim((string)($f['q'] ?? ''));
        if ($q !== '') {
            $like = '%' . addcslashes($q, '\\%_') . '%';
            $where[] = '(account_ref LIKE ? OR to_email LIKE ? OR cc LIKE ? OR subject LIKE ? OR sent_by_label LIKE ?)';
            array_push($args, $like, $like, $like, $like, $like);
        }
        $limit = max(1, min(2000, $limit));
        try {
            $st = $pdo->prepare('SELECT * FROM email_send_log WHERE ' . implode(' AND ', $where) . ' ORDER BY sent_at DESC, id DESC LIMIT ' . ($limit + 1));
            $st->execute($args);
            $rows = $st->fetchAll(PDO::FETCH_ASSOC);
        } catch (Throwable $e) {
            error_log('email_send_log list: ' . $e->getMessage());
            return [[], false];
        }
        $more = count($rows) > $limit;
        return [array_slice($rows, 0, $limit), $more];
    }
}
