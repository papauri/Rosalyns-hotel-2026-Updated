<?php

/**
 * Guest communication lifecycle engine — pre-arrival reminder + post-stay
 * review request. Shared by scripts/guest_lifecycle_emails.php (cron) so the
 * logic is testable without HTTP, mirroring admin/includes/gym-reminders-lib.php.
 *
 * Settings (site_settings):
 *   booking_prearrival_reminder_enabled  '1'|'0'  (default '0' — opt-in)
 *   booking_prearrival_reminder_days     1..14    (default 1)
 *   booking_poststay_review_enabled      '1'|'0'  (default '0' — opt-in)
 *   booking_poststay_review_days         0..14    (default 1)
 *
 * Idempotency: guest_communication_log has UNIQUE(booking_id, stage) — a log
 * row is claimed (INSERT IGNORE) BEFORE the email is sent; a failed send
 * releases the claim (DELETE) so the next run can retry. The table is
 * self-created on first use — no migration-file dependency.
 */

if (!function_exists('guest_lifecycle_log_ensure')) {
    function guest_lifecycle_log_ensure(PDO $pdo): void
    {
        static $ensured = false;
        if ($ensured) {
            return;
        }
        $pdo->exec("
            CREATE TABLE IF NOT EXISTS guest_communication_log (
                id INT AUTO_INCREMENT PRIMARY KEY,
                booking_id INT NOT NULL,
                booking_reference VARCHAR(64) NULL,
                stage VARCHAR(20) NOT NULL,
                sent_to VARCHAR(255) NULL,
                sent_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                UNIQUE KEY uniq_booking_stage (booking_id, stage)
            )
        ");
        $ensured = true;
    }
}

if (!function_exists('guest_lifecycle_settings')) {
    /** @return array{prearrival_enabled:bool,prearrival_days:int,poststay_enabled:bool,poststay_days:int} */
    function guest_lifecycle_settings(): array
    {
        $prearrivalEnabled = getSetting('booking_prearrival_reminder_enabled', '0') === '1';
        $prearrivalDays = (int)getSetting('booking_prearrival_reminder_days', '1');
        if ($prearrivalDays < 1 || $prearrivalDays > 14) {
            $prearrivalDays = 1;
        }

        $poststayEnabled = getSetting('booking_poststay_review_enabled', '0') === '1';
        $poststayDays = (int)getSetting('booking_poststay_review_days', '1');
        if ($poststayDays < 0 || $poststayDays > 14) {
            $poststayDays = 1;
        }

        return [
            'prearrival_enabled' => $prearrivalEnabled,
            'prearrival_days'    => $prearrivalDays,
            'poststay_enabled'   => $poststayEnabled,
            'poststay_days'      => $poststayDays,
        ];
    }
}

if (!function_exists('guest_run_prearrival_reminders')) {
    /**
     * Email guests whose check-in date is exactly N days away (N = the
     * configured window), have not already been sent this stage, and are in
     * an active pre-stay status.
     *
     * @return array{disabled:bool,checked:int,sent:int,skipped:int,errors:array<string>}
     */
    function guest_run_prearrival_reminders(PDO $pdo): array
    {
        $out = ['disabled' => false, 'checked' => 0, 'sent' => 0, 'skipped' => 0, 'errors' => []];

        if (function_exists('moduleEnabled') && !moduleEnabled('bookings')) {
            $out['disabled'] = true;
            return $out;
        }
        $cfg = guest_lifecycle_settings();
        if (!$cfg['prearrival_enabled']) {
            $out['disabled'] = true;
            return $out;
        }

        guest_lifecycle_log_ensure($pdo);

        if (!function_exists('sendPreArrivalReminderEmail')) {
            require_once __DIR__ . '/../../config/email.php';
        }

        try {
            // Exact-day match: fires once, on the single day that is exactly
            // N days before check-in. The idempotency log guarantees a
            // missed cron day never double-sends once it catches up, but an
            // exact match keeps the message accurately timed ("tomorrow").
            $stmt = $pdo->prepare("
                SELECT b.id, b.booking_reference, b.room_id, b.guest_name, b.guest_email,
                       b.check_in_date, b.check_out_date, b.status
                FROM bookings b
                WHERE b.check_in_date = DATE_ADD(CURDATE(), INTERVAL ? DAY)
                  AND b.status IN ('confirmed', 'pending')
                  AND b.guest_email IS NOT NULL AND b.guest_email <> ''
                  AND NOT EXISTS (
                      SELECT 1 FROM guest_communication_log l
                      WHERE l.booking_id = b.id AND l.stage = 'pre_arrival'
                  )
                ORDER BY b.check_in_date ASC
            ");
            $stmt->execute([$cfg['prearrival_days']]);
            $due = $stmt->fetchAll(PDO::FETCH_ASSOC);
        } catch (Throwable $e) {
            $out['errors'][] = 'Query failed: ' . $e->getMessage();
            return $out;
        }

        $out['checked'] = count($due);
        $claim = $pdo->prepare("INSERT IGNORE INTO guest_communication_log (booking_id, booking_reference, stage, sent_to) VALUES (?,?,?,?)");
        $unclaim = $pdo->prepare("DELETE FROM guest_communication_log WHERE booking_id = ? AND stage = ?");

        foreach ($due as $b) {
            if (!filter_var((string)$b['guest_email'], FILTER_VALIDATE_EMAIL)) {
                $out['skipped']++;
                continue;
            }
            // Claim the log row FIRST (unique key) so concurrent runs can't
            // both email the same booking; release the claim if sending fails.
            try {
                $claim->execute([(int)$b['id'], (string)$b['booking_reference'], 'pre_arrival', (string)$b['guest_email']]);
                if ($claim->rowCount() === 0) {
                    $out['skipped']++; // already reminded for this stage
                    continue;
                }
            } catch (Throwable $e) {
                $out['errors'][] = $b['booking_reference'] . ': log claim failed — ' . $e->getMessage();
                continue;
            }

            try {
                $res = sendPreArrivalReminderEmail($b);
                if (!empty($res['success'])) {
                    $out['sent']++;
                } else {
                    $unclaim->execute([(int)$b['id'], 'pre_arrival']);
                    $out['errors'][] = $b['booking_reference'] . ': email failed — ' . (string)($res['message'] ?? 'unknown');
                }
            } catch (Throwable $e) {
                try { $unclaim->execute([(int)$b['id'], 'pre_arrival']); } catch (Throwable $e2) { /* keep first error */ }
                $out['errors'][] = $b['booking_reference'] . ': ' . $e->getMessage();
            }
        }

        return $out;
    }
}

if (!function_exists('guest_run_poststay_review_requests')) {
    /**
     * Email guests whose check-out date was exactly N days ago (N = the
     * configured window) asking for a review, once per booking.
     *
     * @return array{disabled:bool,checked:int,sent:int,skipped:int,errors:array<string>}
     */
    function guest_run_poststay_review_requests(PDO $pdo): array
    {
        $out = ['disabled' => false, 'checked' => 0, 'sent' => 0, 'skipped' => 0, 'errors' => []];

        if (function_exists('moduleEnabled') && !moduleEnabled('bookings')) {
            $out['disabled'] = true;
            return $out;
        }
        $cfg = guest_lifecycle_settings();
        if (!$cfg['poststay_enabled']) {
            $out['disabled'] = true;
            return $out;
        }

        guest_lifecycle_log_ensure($pdo);

        if (!function_exists('sendPostStayReviewRequestEmail')) {
            require_once __DIR__ . '/../../config/email.php';
        }

        try {
            $stmt = $pdo->prepare("
                SELECT b.id, b.booking_reference, b.room_id, b.guest_name, b.guest_email,
                       b.check_in_date, b.check_out_date, b.status
                FROM bookings b
                WHERE b.check_out_date = DATE_SUB(CURDATE(), INTERVAL ? DAY)
                  AND b.status IN ('confirmed', 'pending')
                  AND b.guest_email IS NOT NULL AND b.guest_email <> ''
                  AND NOT EXISTS (
                      SELECT 1 FROM guest_communication_log l
                      WHERE l.booking_id = b.id AND l.stage = 'post_stay'
                  )
                ORDER BY b.check_out_date ASC
            ");
            $stmt->execute([$cfg['poststay_days']]);
            $due = $stmt->fetchAll(PDO::FETCH_ASSOC);
        } catch (Throwable $e) {
            $out['errors'][] = 'Query failed: ' . $e->getMessage();
            return $out;
        }

        $out['checked'] = count($due);
        $claim = $pdo->prepare("INSERT IGNORE INTO guest_communication_log (booking_id, booking_reference, stage, sent_to) VALUES (?,?,?,?)");
        $unclaim = $pdo->prepare("DELETE FROM guest_communication_log WHERE booking_id = ? AND stage = ?");

        foreach ($due as $b) {
            if (!filter_var((string)$b['guest_email'], FILTER_VALIDATE_EMAIL)) {
                $out['skipped']++;
                continue;
            }
            try {
                $claim->execute([(int)$b['id'], (string)$b['booking_reference'], 'post_stay', (string)$b['guest_email']]);
                if ($claim->rowCount() === 0) {
                    $out['skipped']++; // already requested a review for this stage
                    continue;
                }
            } catch (Throwable $e) {
                $out['errors'][] = $b['booking_reference'] . ': log claim failed — ' . $e->getMessage();
                continue;
            }

            try {
                $res = sendPostStayReviewRequestEmail($b);
                if (!empty($res['success'])) {
                    $out['sent']++;
                } else {
                    $unclaim->execute([(int)$b['id'], 'post_stay']);
                    $out['errors'][] = $b['booking_reference'] . ': email failed — ' . (string)($res['message'] ?? 'unknown');
                }
            } catch (Throwable $e) {
                try { $unclaim->execute([(int)$b['id'], 'post_stay']); } catch (Throwable $e2) { /* keep first error */ }
                $out['errors'][] = $b['booking_reference'] . ': ' . $e->getMessage();
            }
        }

        return $out;
    }
}
