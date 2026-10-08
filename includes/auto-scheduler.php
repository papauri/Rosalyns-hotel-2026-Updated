<?php

/**
 * Web-triggered scheduler — replaces cron on shared hosting.
 *
 * rh_scheduler_maybe_run() is called on every admin page (admin/admin-init.php) and
 * public page (includes/footer.php). The per-request cost is one cached setting read.
 * When the run interval has elapsed it registers a shutdown function that, AFTER the
 * response has been sent to the visitor (fastcgi_finish_request / litespeed_finish_request;
 * a tiny budget otherwise), takes the MySQL lock 'rh_scheduler' (so only one request runs
 * the jobs), then runs every enabled job within a time and email budget. Errors are logged
 * with error_log(), never shown. Cron stays optional: scripts/auto-scheduler-run.php runs
 * the same code.
 *
 * Settings (site_settings; defaults in rh_auto_defaults()):
 *   automated_email_master            '1'   master switch for everything here (emails and the nightly backup)
 *   automated_email_interval_minutes  '15'  minimum minutes between scheduler runs
 *   automated_email_test_recipient    ''    when set, EVERY automated email goes there ([TEST])
 *   scheduler_last_run                unix time of the last run start (cached read)
 * Job toggles/stages live with each job (see rh_scheduler_jobs()); the migrated
 * jobs keep their original setting keys.
 *
 * Idempotency: includes/auto-email-jobs.php claims a row in automated_email_log
 * (UNIQUE job/account_type/account_id/stage) before every send.
 */

if (!function_exists('rh_auto_defaults')) {

    /** @return array<string,string> */
    function rh_auto_defaults(): array
    {
        return [
            'automated_email_master'            => '1',
            'automated_email_interval_minutes'  => '15',
            'automated_email_test_recipient'    => '',
            'automated_email_job_overdue'       => '1',
            'automated_email_job_quotation'     => '1',
            'automated_email_reminder_stages'   => '1,3,7',
            'automated_email_payment_terms_days' => '7',
            'automated_email_lookback_days'     => '30',
            'automated_email_bcc_hotel'         => '1',
            'automated_email_quotation_days'    => '2',
            'automated_email_job_tentative'     => '1',
            'automated_email_job_tentative_expired' => '1',
            'automated_email_job_pending_expired' => '1',
            'automated_email_job_unpaid_reminder' => '1',
            'automated_email_job_unpaid_release' => '1',
            'automated_backup_enabled'          => '1',
            'automated_job_recurring_maintenance' => '1',
            'scheduler_last_run'                => '0',
            'scheduler_last_result'             => '',
        ];
    }

    function rh_auto_setting(string $key): string
    {
        $def = rh_auto_defaults()[$key] ?? '';
        return (string)getSetting($key, $def);
    }

    /** Uncached read (used under the run lock so a stale file cache can never cause a double run). */
    function rh_auto_setting_fresh(PDO $pdo, string $key): string
    {
        try {
            $st = $pdo->prepare('SELECT setting_value FROM site_settings WHERE setting_key = ?');
            $st->execute([$key]);
            $v = $st->fetchColumn();
            return $v === false ? (rh_auto_defaults()[$key] ?? '') : (string)$v;
        } catch (Throwable $e) {
            return rh_auto_defaults()[$key] ?? '';
        }
    }

    /**
     * Job registry. 'toggle' is the site_settings key that switches the job on;
     * 'interval' is the minimum minutes between runs of that job; 'legacy' jobs are the
     * migrated cron senders (they dedupe through their own existing log tables).
     *
     * @return array<string,array<string,mixed>>
     */
    function rh_scheduler_jobs(): array
    {
        return [
            'overdue_payment_reminders' => [
                'label' => 'Overdue payment reminders', 'fn' => 'rh_job_overdue_payment_reminders',
                'toggle' => 'automated_email_job_overdue', 'toggle_default' => '1', 'interval' => 60, 'legacy' => false, 'bcc' => 'setting',
            ],
            'quotation_expiry_reminder' => [
                'label' => 'Quotation expiry reminders', 'fn' => 'rh_job_quotation_expiry_reminder',
                'toggle' => 'automated_email_job_quotation', 'toggle_default' => '1', 'interval' => 360, 'legacy' => false, 'bcc' => 'setting',
            ],
            'tentative_hold_reminder' => [
                'label' => 'Tentative hold reminders', 'fn' => 'rh_job_tentative_hold_reminder',
                'toggle' => 'automated_email_job_tentative', 'toggle_default' => '1', 'interval' => 30, 'legacy' => false, 'bcc' => 'global',
            ],
            'tentative_expired_notice' => [
                'label' => 'Tentative hold expired notices', 'fn' => 'rh_job_tentative_expired_notice',
                'toggle' => 'automated_email_job_tentative_expired', 'toggle_default' => '1', 'interval' => 30, 'legacy' => false, 'bcc' => 'global',
            ],
            'pending_expired_notice' => [
                'label' => 'Pending booking expired notices', 'fn' => 'rh_job_pending_expired_notice',
                'toggle' => 'automated_email_job_pending_expired', 'toggle_default' => '1', 'interval' => 30, 'legacy' => false, 'bcc' => 'global',
            ],
            // Both unpaid-confirmed jobs also need unpaid_confirmed_release_hours > 0 (default 0 = off).
            'unpaid_confirmed_reminder' => [
                'label' => 'Confirmed-but-unpaid payment reminders', 'fn' => 'rh_job_unpaid_confirmed_reminder',
                'toggle' => 'automated_email_job_unpaid_reminder', 'toggle_default' => '1', 'interval' => 30, 'legacy' => false, 'bcc' => 'global',
            ],
            'unpaid_confirmed_release' => [
                'label' => 'Confirmed-but-unpaid room release', 'fn' => 'rh_job_unpaid_confirmed_release',
                'toggle' => 'automated_email_job_unpaid_release', 'toggle_default' => '1', 'interval' => 30, 'legacy' => false, 'bcc' => 'global',
            ],
            'prearrival_reminders' => [
                'label' => 'Pre-arrival reminders', 'fn' => 'rh_job_prearrival_reminders',
                'toggle' => 'booking_prearrival_reminder_enabled', 'toggle_default' => '0', 'interval' => 60, 'legacy' => true, 'bcc' => 'global',
            ],
            'poststay_review_requests' => [
                'label' => 'Post-stay review requests', 'fn' => 'rh_job_poststay_review_requests',
                'toggle' => 'booking_poststay_review_enabled', 'toggle_default' => '0', 'interval' => 60, 'legacy' => true, 'bcc' => 'global',
            ],
            'gym_membership_renewal' => [
                'label' => 'Gym membership renewal reminders', 'fn' => 'rh_job_gym_membership_renewal',
                'toggle' => 'gym_reminder_enabled', 'toggle_default' => '1', 'interval' => 360, 'legacy' => true, 'bcc' => 'global',
            ],
            // Not an email: creates the next occurrence of recurring room-maintenance series (idempotent).
            'recurring_maintenance' => [
                'label' => 'Recurring maintenance generation', 'fn' => 'rh_job_recurring_maintenance',
                'toggle' => 'automated_job_recurring_maintenance', 'toggle_default' => '1', 'interval' => 360, 'legacy' => false, 'bcc' => 'global',
            ],
            // Last on purpose: a backup can take a while, so every email job runs first.
            'nightly_backup' => [
                'label' => 'Nightly database backup', 'fn' => 'rh_job_nightly_backup',
                'toggle' => 'automated_backup_enabled', 'toggle_default' => '1', 'interval' => 60, 'legacy' => false, 'bcc' => 'global',
            ],
        ];
    }

    function rh_auto_job_enabled(string $name): bool
    {
        $job = rh_scheduler_jobs()[$name] ?? null;
        return $job !== null && (string)getSetting($job['toggle'], $job['toggle_default']) === '1';
    }

    function rh_auto_log_table_ready(PDO $pdo): bool
    {
        static $ok = null;
        if ($ok === null) {
            try {
                $ok = $pdo->query("SHOW TABLES LIKE 'automated_email_log'")->rowCount() > 0;
            } catch (Throwable $e) {
                $ok = false;
            }
        }
        return $ok;
    }

    /* ── per-run budgets ─────────────────────────────────────────────── */

    function rh_scheduler_budget_ok(): bool
    {
        $b = $GLOBALS['rh_scheduler_budget'] ?? null;
        if (!is_array($b)) {
            return true;
        }
        return time() < $b['deadline'] && $b['emails_left'] > 0;
    }

    function rh_scheduler_budget_spend(): void
    {
        if (isset($GLOBALS['rh_scheduler_budget']['emails_left'])) {
            $GLOBALS['rh_scheduler_budget']['emails_left']--;
        }
    }

    /** Set the automated-send policy read by rh_automated_email_adjust() in config/email.php. */
    function rh_auto_ctx_begin(bool $bcc): void
    {
        $GLOBALS['rh_automated_ctx'] = [
            'redirect' => trim(rh_auto_setting('automated_email_test_recipient')),
            'bcc'      => $bcc,
        ];
    }

    function rh_auto_ctx_end(): void
    {
        unset($GLOBALS['rh_automated_ctx']);
    }

    /* ── idempotency log ─────────────────────────────────────────────── */

    /** Claim (job, type, id, stage) for sending. True only for the one process that owns the send. */
    function rh_auto_log_claim(PDO $pdo, string $job, string $type, int $id, string $stage, string $recipient, string $subject): bool
    {
        $ins = $pdo->prepare("INSERT IGNORE INTO automated_email_log (job, account_type, account_id, stage, recipient, subject, status) VALUES (?,?,?,?,?,?, 'sending')");
        $ins->execute([$job, $type, $id, $stage, mb_substr($recipient, 0, 255), mb_substr($subject, 0, 255)]);
        if ($ins->rowCount() === 1) {
            return true;
        }
        // Retry a failed send (max 3 attempts, 1h apart) or recover a stale claim (>15 min) — atomically.
        $re = $pdo->prepare(
            "UPDATE automated_email_log
                SET status = 'sending', attempts = attempts + 1, recipient = ?, subject = ?, error = NULL
              WHERE job = ? AND account_type = ? AND account_id = ? AND stage = ?
                AND ((status = 'failed' AND attempts < 3 AND updated_at < (NOW() - INTERVAL 1 HOUR))
                  OR (status = 'sending' AND updated_at < (NOW() - INTERVAL 15 MINUTE)))"
        );
        $re->execute([mb_substr($recipient, 0, 255), mb_substr($subject, 0, 255), $job, $type, $id, $stage]);
        return $re->rowCount() === 1;
    }

    function rh_auto_log_finish(PDO $pdo, string $job, string $type, int $id, string $stage, string $status, string $error = ''): void
    {
        $st = $pdo->prepare('UPDATE automated_email_log SET status = ?, error = ? WHERE job = ? AND account_type = ? AND account_id = ? AND stage = ?');
        $st->execute([$status, $error !== '' ? mb_substr($error, 0, 500) : null, $job, $type, $id, $stage]);
    }

    /** Record that a stage must never fire (e.g. superseded by a later stage). */
    function rh_auto_log_skip(PDO $pdo, string $job, string $type, int $id, string $stage, string $note): void
    {
        $st = $pdo->prepare("INSERT IGNORE INTO automated_email_log (job, account_type, account_id, stage, status, error) VALUES (?,?,?,?, 'skipped', ?)");
        $st->execute([$job, $type, $id, $stage, mb_substr($note, 0, 500)]);
    }

    /** One summary row per run for the migrated jobs (their own tables hold the per-account dedupe). */
    function rh_auto_log_run_summary(PDO $pdo, string $job, array $res): void
    {
        if (!rh_auto_log_table_ready($pdo) || ((int)($res['sent'] ?? 0) === 0 && empty($res['errors']))) {
            return;
        }
        try {
            $st = $pdo->prepare("INSERT IGNORE INTO automated_email_log (job, account_type, account_id, stage, subject, status, error) VALUES (?, 'run', 0, ?, ?, ?, ?)");
            $st->execute([
                $job,
                'run-' . date('YmdHis'),
                'sent ' . (int)($res['sent'] ?? 0) . ', skipped ' . (int)($res['skipped'] ?? 0),
                empty($res['errors']) ? 'sent' : ((int)($res['sent'] ?? 0) > 0 ? 'sent' : 'failed'),
                empty($res['errors']) ? null : mb_substr(implode(' | ', $res['errors']), 0, 500),
            ]);
        } catch (Throwable $e) {
            error_log('rh_auto_log_run_summary: ' . $e->getMessage());
        }
    }

    /* ── scheduler core ──────────────────────────────────────────────── */

    /**
     * Cheap per-request entry point. Never throws, never outputs.
     *
     * @param bool  $force Skip the interval/master checks (Run now, CLI). Toggles and the lock still apply.
     * @param array $opts  time_budget (s), email_budget, detach (bool; defaults to true on web, false on CLI/force)
     * @return array{status:string,reason?:string}
     */
    function rh_scheduler_maybe_run(bool $force = false, array $opts = []): array
    {
        static $handled = false;
        try {
            if (!$force) {
                if ($handled) {
                    return ['status' => 'skipped', 'reason' => 'already_checked'];
                }
                $handled = true;
                if (!isset($GLOBALS['pdo']) || !function_exists('getSetting')) {
                    return ['status' => 'skipped', 'reason' => 'no_db'];
                }
                if (rh_auto_setting('automated_email_master') !== '1') {
                    return ['status' => 'skipped', 'reason' => 'master_off'];
                }
                $interval = max(1, (int)rh_auto_setting('automated_email_interval_minutes'));
                $last = (int)rh_auto_setting('scheduler_last_run');
                if ($last > 0 && (time() - $last) < $interval * 60) {
                    return ['status' => 'skipped', 'reason' => 'not_due'];
                }
            }

            $isCli = PHP_SAPI === 'cli';
            $detach = array_key_exists('detach', $opts) ? (bool)$opts['detach'] : (!$isCli && !$force);
            if ($detach) {
                register_shutdown_function('rh_scheduler_execute', false, $opts, true);
                return ['status' => 'scheduled'];
            }
            return rh_scheduler_execute($force, $opts, false);
        } catch (Throwable $e) {
            error_log('rh_scheduler_maybe_run: ' . $e->getMessage());
            return ['status' => 'error', 'reason' => $e->getMessage()];
        }
    }

    /** Close the client connection so the visitor never waits for the jobs. True when really detached. */
    function rh_scheduler_detach(): bool
    {
        if (session_status() === PHP_SESSION_ACTIVE) {
            @session_write_close(); // the session lock must not be held for the whole run
        }
        if (function_exists('fastcgi_finish_request')) {
            @fastcgi_finish_request();
            return true;
        }
        if (function_exists('litespeed_finish_request')) {
            @litespeed_finish_request();
            return true;
        }
        while (ob_get_level() > 0) {
            @ob_end_flush();
        }
        @flush();
        return false;
    }

    /**
     * Run the due jobs. Called from the shutdown hook ($detach = true), "Run now" and CLI.
     *
     * @return array{status:string,reason?:string,jobs?:array<string,array>}
     */
    function rh_scheduler_execute(bool $force = false, array $opts = [], bool $detach = false): array
    {
        $pdo = $GLOBALS['pdo'] ?? null;
        if (!($pdo instanceof PDO)) {
            return ['status' => 'skipped', 'reason' => 'no_db'];
        }
        $closed = $detach ? rh_scheduler_detach() : true;
        @ignore_user_abort(true);

        $timeBudget  = (int)($opts['time_budget'] ?? ($closed ? 20 : 4));
        $emailBudget = (int)($opts['email_budget'] ?? ($closed ? 25 : 5));
        @set_time_limit($timeBudget + 30);

        $gotLock = false;
        $out = ['status' => 'ran', 'jobs' => []];
        try {
            $gotLock = (int)$pdo->query("SELECT GET_LOCK('rh_scheduler', 0)")->fetchColumn() === 1;
            if (!$gotLock) {
                return ['status' => 'skipped', 'reason' => 'locked'];
            }

            if (!$force) {
                if (rh_auto_setting_fresh($pdo, 'automated_email_master') !== '1') {
                    return ['status' => 'skipped', 'reason' => 'master_off'];
                }
                $interval = max(1, (int)rh_auto_setting_fresh($pdo, 'automated_email_interval_minutes'));
                if ((time() - (int)rh_auto_setting_fresh($pdo, 'scheduler_last_run')) < $interval * 60) {
                    return ['status' => 'skipped', 'reason' => 'not_due'];
                }
            }
            updateSetting('scheduler_last_run', (string)time());

            if (!rh_auto_log_table_ready($pdo)) {
                error_log('auto-scheduler: automated_email_log missing - run admin/migrations/migrate.php --run');
                return ['status' => 'skipped', 'reason' => 'migration_pending'];
            }

            require_once __DIR__ . '/auto-email-jobs.php';
            $GLOBALS['rh_scheduler_budget'] = ['deadline' => time() + $timeBudget, 'emails_left' => $emailBudget];
            $testMode = trim(rh_auto_setting('automated_email_test_recipient')) !== '';

            foreach (rh_scheduler_jobs() as $name => $job) {
                if (!rh_scheduler_budget_ok()) {
                    $out['jobs'][$name] = ['status' => 'deferred', 'reason' => 'budget'];
                    continue;
                }
                if (!rh_auto_job_enabled($name)) {
                    $out['jobs'][$name] = ['status' => 'off'];
                    continue;
                }
                if ($testMode && !empty($job['legacy'])) {
                    // Legacy jobs claim rows in their own logs, which would mark real guests as
                    // contacted while the mail is redirected. Use "Send test to me" instead.
                    $out['jobs'][$name] = ['status' => 'skipped', 'reason' => 'test_mode'];
                    continue;
                }
                $jobKey = 'scheduler_job_last_' . $name;
                if (!$force && (time() - (int)rh_auto_setting_fresh($pdo, $jobKey)) < (int)$job['interval'] * 60) {
                    $out['jobs'][$name] = ['status' => 'not_due'];
                    continue;
                }
                $bcc = $job['bcc'] === 'setting'
                    ? rh_auto_setting('automated_email_bcc_hotel') === '1'
                    : (bool)($GLOBALS['email_bcc_admin'] ?? false);
                try {
                    if (!function_exists($job['fn'])) {
                        throw new RuntimeException('job function missing: ' . $job['fn']);
                    }
                    rh_auto_ctx_begin($bcc);
                    $res = $job['fn']($pdo, ['test_mode' => $testMode]);
                    $out['jobs'][$name] = ['status' => 'ran'] + $res;
                    updateSetting($jobKey, (string)time());
                } catch (Throwable $e) {
                    error_log('auto-scheduler job ' . $name . ': ' . $e->getMessage());
                    $out['jobs'][$name] = ['status' => 'error', 'errors' => [$e->getMessage()]];
                } finally {
                    rh_auto_ctx_end();
                }
            }
            updateSetting('scheduler_last_result', json_encode(['at' => date('Y-m-d H:i:s'), 'jobs' => $out['jobs']]));
        } catch (Throwable $e) {
            error_log('rh_scheduler_execute: ' . $e->getMessage());
            $out = ['status' => 'error', 'reason' => $e->getMessage()];
        } finally {
            unset($GLOBALS['rh_scheduler_budget']);
            if ($gotLock) {
                try {
                    $pdo->query("SELECT RELEASE_LOCK('rh_scheduler')");
                } catch (Throwable $e) {
                    // lock frees itself when the connection closes
                }
            }
        }
        return $out;
    }
}
