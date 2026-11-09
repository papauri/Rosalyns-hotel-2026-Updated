<?php
/**
 * Email identity: one source of truth for who an email is from, where replies go and who gets copies.
 *
 * - From is ALWAYS the SMTP mailbox (smtp_username): the mail server only lets us send as that
 *   address, and the hotel domain's SPF only allows that server. Guests see the sender name next to it.
 * - Reply-To, and the contact email printed on emails and documents, is rh_guest_contact_email():
 *   Email Settings "Reply-to and contact email", else Hotel Details main email. The saved settings decide
 *   (owner 2026-11-09: they stay on the sending mailbox until each hotel has its own mailbox).
 * - Hotel copies of guest emails (invoice copies, the mailbox's own copy, booking CC lists) are
 *   hidden copies (BCC), never a visible CC the guest can read.
 * - The staff copy email (email_admin_email) gets one hidden copy of every email while the
 *   "hidden copy" switch is on, unless it already receives that message.
 * - Backups (owner 2026-11-09, up to 5 each, comma lists): email_reply_to_backup = extra Reply-To
 *   addresses after the main one (only when there is a main one); email_admin_backup = extra hidden
 *   copies under exactly the same conditions as the staff copy. Neither is used anywhere else (not in
 *   the alert fallback chains, not as a printed contact email).
 *
 * The settings screen (admin/booking-settings.php) calls the same recipient resolvers as the
 * sending code, so "Currently goes to" always matches what is really sent.
 */

if (!function_exists('rh_ei_valid_email')) {
    /** Trimmed address when it is a valid email, else ''. */
    function rh_ei_valid_email($value): string
    {
        $value = trim((string)$value);
        return ($value !== '' && filter_var($value, FILTER_VALIDATE_EMAIL)) ? $value : '';
    }
}

if (!function_exists('rh_ei_email_setting')) {
    /** email_settings value (the single source for email keys); site_settings only when the table is missing. */
    function rh_ei_email_setting(string $key): string
    {
        if (function_exists('getEmailSetting')) {
            return trim((string)getEmailSetting($key, ''));
        }
        return function_exists('getSetting') ? trim((string)getSetting($key, '')) : '';
    }
}

if (!function_exists('rh_ei_email_list')) {
    /** A saved comma list of addresses: valid ones only, each once (case-insensitive), at most $max. */
    function rh_ei_email_list(string $raw, int $max = 5): array
    {
        $list = [];
        foreach (preg_split('/[,;\s]+/', $raw) ?: [] as $part) {
            $part = rh_ei_valid_email($part);
            if ($part !== '' && !isset($list[strtolower($part)])) {
                $list[strtolower($part)] = $part;
            }
            if (count($list) >= $max) {
                break;
            }
        }
        return array_values($list);
    }
}

if (!function_exists('rh_guest_contact_email')) {
    /**
     * The address guests may write to: Reply-To header and the contact email printed on emails
     * and documents. Email Settings "Reply-to and contact email" first, then the main contact
     * email from Hotel Details; '' when neither is set.
     */
    function rh_guest_contact_email(): string
    {
        $candidates = [
            rh_ei_email_setting('email_from_email'),
            function_exists('getSetting') ? (string)getSetting('email_main', '') : '',
        ];
        foreach ($candidates as $candidate) {
            $candidate = rh_ei_valid_email($candidate);
            if ($candidate !== '') {
                return $candidate;
            }
        }
        return '';
    }
}

if (!function_exists('rh_mail_reply_to_addresses')) {
    /**
     * Every Reply-To address, in order: rh_guest_contact_email(), then the saved backup reply-to
     * emails (email_reply_to_backup) that differ from it. [] when there is no main address.
     */
    function rh_mail_reply_to_addresses(): array
    {
        $main = rh_guest_contact_email();
        if ($main === '') {
            return [];
        }
        $out = [$main];
        foreach (rh_ei_email_list(rh_ei_email_setting('email_reply_to_backup')) as $backup) {
            if (strcasecmp($backup, $main) !== 0) {
                $out[] = $backup;
            }
        }
        return $out;
    }
}

if (!function_exists('rh_mail_staff_backup_addresses')) {
    /** Saved backup staff copy emails (email_admin_backup), without the staff copy email itself. */
    function rh_mail_staff_backup_addresses(): array
    {
        $staff = rh_ei_valid_email(rh_ei_email_setting('email_admin_email'));
        return array_values(array_filter(
            rh_ei_email_list(rh_ei_email_setting('email_admin_backup')),
            static function (string $a) use ($staff): bool { return $staff === '' || strcasecmp($a, $staff) !== 0; }
        ));
    }
}

if (!function_exists('rh_mail_staff_copy_addresses')) {
    /**
     * Who gets a hidden copy of every email while the switch (email_bcc_admin) is on: the staff copy
     * email, then the backups. The switch itself is not checked here; rh_mail_add_staff_bcc() does.
     */
    function rh_mail_staff_copy_addresses(): array
    {
        $staff = rh_ei_valid_email(rh_ei_email_setting('email_admin_email'));
        return array_merge($staff !== '' ? [$staff] : [], rh_mail_staff_backup_addresses());
    }
}

if (!function_exists('rh_mail_apply_identity')) {
    /**
     * From = the SMTP mailbox with the hotel's sender name, envelope sender = the mailbox,
     * Reply-To = rh_mail_reply_to_addresses(): rh_guest_contact_email() when there is one, then its backups.
     */
    function rh_mail_apply_identity(\PHPMailer\PHPMailer\PHPMailer $mail, string $smtpUser, string $fromName = ''): void
    {
        $smtpUser = trim($smtpUser);
        $fromName = trim($fromName);
        if ($fromName === '') {
            $fromName = rh_ei_email_setting('email_from_name');
        }
        if ($fromName === '' && function_exists('getSetting')) {
            $fromName = trim((string)getSetting('site_name', ''));
        }
        $mail->setFrom($smtpUser, $fromName);
        if (filter_var($smtpUser, FILTER_VALIDATE_EMAIL)) {
            $mail->Sender = $smtpUser;
        }
        foreach (rh_mail_reply_to_addresses() as $replyTo) {
            $mail->addReplyTo($replyTo, $fromName);
        }
    }
}

if (!function_exists('rh_mail_add_hidden_copies')) {
    /**
     * Hotel copies of a guest email as hidden copies (BCC): valid addresses only, never the To
     * address, each address once (PHPMailer also refuses an address already on the message).
     * Returns how many were added.
     */
    function rh_mail_add_hidden_copies(\PHPMailer\PHPMailer\PHPMailer $mail, array $addresses, string $to): int
    {
        $added = 0;
        $seen = [strtolower(trim($to)) => true];
        foreach ($addresses as $address) {
            $address = rh_ei_valid_email($address);
            if ($address === '' || isset($seen[strtolower($address)])) {
                continue;
            }
            $seen[strtolower($address)] = true;
            if ($mail->addBCC($address)) {
                $added++;
            }
        }
        return $added;
    }
}

if (!function_exists('rh_mail_add_staff_bcc')) {
    /**
     * One hidden copy to the staff copy email (email_admin_email) when the switch is on, unless
     * that address already receives this message (it is the To, already on the message, or the
     * caller set $GLOBALS['rh_email_staff_bcc_off'] because it receives a separate copy).
     * Under the same two conditions (switch on, not switched off by the caller) each backup staff copy
     * email also gets one hidden copy unless it is already on the message, even when the staff copy
     * email itself was skipped. Addresses in $GLOBALS['rh_email_staff_bcc_skip'] (set by a caller that
     * sends them their own copy) are treated as already on the message. Returns true only when the
     * staff copy email itself was added.
     */
    function rh_mail_add_staff_bcc(\PHPMailer\PHPMailer\PHPMailer $mail, string $to, bool $enabled): bool
    {
        if (!$enabled || !empty($GLOBALS['rh_email_staff_bcc_off'])) {
            return false;
        }
        $onMessage = [strtolower(trim($to)) => true];
        foreach (array_merge($mail->getToAddresses(), $mail->getCcAddresses(), $mail->getBccAddresses()) as $existing) {
            $onMessage[strtolower(trim((string)($existing[0] ?? '')))] = true;
        }
        foreach ((array)($GLOBALS['rh_email_staff_bcc_skip'] ?? []) as $skip) {
            $onMessage[strtolower(trim((string)$skip))] = true;
        }
        $added = false;
        $staff = rh_ei_valid_email(rh_ei_email_setting('email_admin_email'));
        if ($staff !== '' && !isset($onMessage[strtolower($staff)])) {
            $added = (bool)$mail->addBCC($staff);
        }
        $backups = array_filter(rh_mail_staff_backup_addresses(), static function (string $a) use ($onMessage): bool {
            return !isset($onMessage[strtolower($a)]);
        });
        if ($backups) {
            rh_mail_add_hidden_copies($mail, $backups, $to);
        }
        return $added;
    }
}

if (!function_exists('rh_ei_resolve_chain')) {
    /** First valid address in [[value, source label], ...] → ['email' => ..., 'source' => ...]; ['', ''] when none. */
    function rh_ei_resolve_chain(array $chain): array
    {
        foreach ($chain as $link) {
            $email = rh_ei_valid_email($link[0] ?? '');
            if ($email !== '') {
                return ['email' => $email, 'source' => (string)($link[1] ?? '')];
            }
        }
        return ['email' => '', 'source' => ''];
    }
}

if (!function_exists('rh_booking_alert_recipient')) {
    /** New website booking alerts: own box → Hotel Details staff notification email → staff copy email. */
    function rh_booking_alert_recipient(): array
    {
        return rh_ei_resolve_chain([
            [getSetting('booking_notification_email', ''), 'New website booking alerts go to'],
            [getSetting('admin_notification_email', ''), 'Staff notification email (Hotel Details)'],
            [rh_ei_email_setting('email_admin_email'), 'Staff copy email (Email Settings)'],
        ]);
    }
}

if (!function_exists('rh_conference_alert_recipient')) {
    /** Conference enquiry alerts: own box → main contact email → staff copy email. */
    function rh_conference_alert_recipient(): array
    {
        return rh_ei_resolve_chain([
            [getSetting('conference_email', ''), 'Conference alerts go to'],
            [getSetting('email_main', ''), 'Main contact email (Hotel Details)'],
            [rh_ei_email_setting('email_admin_email'), 'Staff copy email (Email Settings)'],
        ]);
    }
}

if (!function_exists('rh_gym_alert_recipient')) {
    /** Gym booking alerts: own box → main contact email → staff copy email. */
    function rh_gym_alert_recipient(): array
    {
        return rh_ei_resolve_chain([
            [getSetting('gym_email', ''), 'Gym alerts go to'],
            [getSetting('email_main', ''), 'Main contact email (Hotel Details)'],
            [rh_ei_email_setting('email_admin_email'), 'Staff copy email (Email Settings)'],
        ]);
    }
}

if (!function_exists('rh_restaurant_contact_recipient')) {
    /**
     * Restaurant contact email shown on the guest restaurant page (restaurant.php): the saved
     * value; only when it was never saved, the reservations email. Display only, never sent to.
     */
    function rh_restaurant_contact_recipient(): array
    {
        $missing = '__rh_ei_missing__';
        $own = getSetting('email_restaurant', $missing);
        if ($own === $missing) {
            return rh_ei_resolve_chain([[getSetting('email_reservations', ''), 'Reservations email (Hotel Details)']]);
        }
        return rh_ei_resolve_chain([[$own, 'Restaurant contact email']]);
    }
}

if (!function_exists('rh_staff_copy_recipient')) {
    /** Staff copies of admin-created bookings / conference confirmations: staff copy email → sending mailbox. */
    function rh_staff_copy_recipient(): array
    {
        return rh_ei_resolve_chain([
            [rh_ei_email_setting('email_admin_email'), 'Staff copy email (Email Settings)'],
            [rh_ei_email_setting('smtp_username'), 'Sending mailbox (Email Settings)'],
        ]);
    }
}

if (!function_exists('rh_mail_settings_summary')) {
    /**
     * Plain-text summary of the SAVED email settings, as [label, value] pairs, for the Email Settings
     * test button (screen and test email share it). Never includes the SMTP password; the SMTP
     * username appears only as the sending mailbox (it is the From address anyway).
     * $testTo: the test recipient, so a hidden copy that would be skipped for it is explained.
     */
    function rh_mail_settings_summary(string $testTo = ''): array
    {
        $name = rh_ei_email_setting('email_from_name');
        if ($name === '' && function_exists('getSetting')) {
            $name = trim((string)getSetting('site_name', ''));
        }
        $mailbox = rh_ei_email_setting('smtp_username');
        $replyTo = rh_mail_reply_to_addresses();
        $switchOn = (bool)rh_ei_email_setting('email_bcc_admin'); // same reading as sendEmail()
        $copies = rh_mail_staff_copy_addresses();
        if (!$switchOn) {
            $copiesText = 'none: the hidden-copy switch is off';
        } elseif (!$copies) {
            $copiesText = 'none: no staff copy email is set';
        } else {
            $copiesText = implode(', ', $copies);
            foreach ($copies as $copy) {
                if ($testTo !== '' && strcasecmp($copy, trim($testTo)) === 0) {
                    $copiesText .= ' (' . $copy . ' is the test address, so it gets this email itself instead of a copy)';
                }
            }
        }
        $port = rh_ei_email_setting('smtp_port');
        return [
            ['Sender name', $name !== '' ? $name : 'not set'],
            ['Sending mailbox (From)', $mailbox !== '' ? $mailbox : 'not set'],
            ['Replies go to', $replyTo ? implode(', ', $replyTo) : 'no reply-to address (set one, or the main contact email in Hotel Details)'],
            ['Hidden copies', $copiesText],
            ['Mail server', (rh_ei_email_setting('smtp_host') !== '' ? rh_ei_email_setting('smtp_host') : 'not set') . ($port !== '' ? ', port ' . $port : '')],
        ];
    }
}
