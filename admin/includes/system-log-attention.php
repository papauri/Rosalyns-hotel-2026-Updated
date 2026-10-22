<?php
/**
 * System Logs helpers (admin/system-logs.php): the "Needs attention" summary and the grouping of
 * repeated events. Pure functions work on plain row arrays (so they can be tested without
 * touching the database); rh_log_attention_load() reads the rows (read-only, last 7 days, every
 * query bounded by a LIMIT and filtered on an indexed time column).
 *
 * What counts as "needs attention":
 *  - real errors/critical events (NOT refused typing: a form saying "must be numeric" is a warning),
 *  - repeated failed sign-ins (3 or more from one address inside an hour is highlighted),
 *  - failed emails, negative stock / oversell, offline sync failures, API key failures.
 */

if (!function_exists('rh_log_normalize_message')) {

    /** Collapse the parts of a message that change between repeats (numbers, ids, emails). */
    function rh_log_normalize_message(string $message): string
    {
        $m = preg_replace('/[A-Za-z0-9._%+\-]+@[A-Za-z0-9.\-]+/', '<email>', $message) ?? $message;
        $m = preg_replace('/\d+(?:\.\d+)?/', 'N', $m) ?? $m;
        return trim($m);
    }

    /**
     * An error-level row that is really a refused entry (typing/validation), not a fault.
     * Older rows were logged as errors before forms switched to warnings; they are recognised here.
     */
    function rh_log_is_validation_refusal(string $source, string $level, string $message): bool
    {
        if (!in_array($level, ['error', 'critical'], true)) {
            return false;
        }
        if (in_array($source, ['whatsapp_settings', 'facebook_settings'], true)
            && preg_match('/^Failed saving (WhatsApp|Facebook) settings$/', $message)) {
            return true;
        }
        if ($source === 'create-booking' && strpos($message, 'Booking creation failed:') === 0
            && !preg_match('/SQLSTATE|Call to |Undefined|Uncaught|PDO|Deadlock|Lock wait|Duplicate entry|syntax|Table .* doesn/i', $message)) {
            return true;
        }
        return false;
    }

    /** Which "Needs attention" line an event belongs to, or null when it is not one. */
    function rh_log_classify_event(string $source, string $level, string $message): ?string
    {
        $level = strtolower($level);
        if (!in_array($level, ['warning', 'error', 'critical'], true)) {
            return null;
        }
        if ($source === 'api' && $message === 'API authentication failed') {
            return 'api_auth';
        }
        if ($source === 'stock' && preg_match('/went negative|^Oversell/i', $message)) {
            return 'stock_negative';
        }
        if (preg_match('/email failed|email send failed|SMTP Error|could not send (the )?email/i', $message)) {
            return 'email_failed';
        }
        if (in_array($level, ['error', 'critical'], true) && !rh_log_is_validation_refusal($source, $level, $message)) {
            return 'error';
        }
        return null;
    }

    /** "18 Oct 15:00" from a database timestamp. */
    function rh_log_short_time(string $ts): string
    {
        $t = strtotime($ts);
        return $t ? date('j M H:i', $t) : $ts;
    }

    /** True when $times (unix seconds) holds $count or more inside any $window-second span. */
    function rh_log_burst(array $times, int $count = 3, int $window = 3600): bool
    {
        sort($times);
        $n = count($times);
        for ($i = 0; $i + $count - 1 < $n; $i++) {
            if ($times[$i + $count - 1] - $times[$i] <= $window) {
                return true;
            }
        }
        return false;
    }

    /**
     * Build the "Needs attention" items from plain rows.
     *   $events   rows of [source, level, message, created_at]
     *   $logins   rows of [username, action, details, ip_address, created_at] (failed/blocked only)
     *   $offline  rows of [endpoint, action, response_status, response_summary, replayed_at] (non-2xx)
     *   $emails   rows of [recipient, error, at] (automated emails that failed)
     * Returns items, most serious first. Each: key, level (danger|warning), title, count, last_seen,
     * meaning, examples[], highlight, link [page, label, permission, module|null] or null.
     */
    function rh_log_attention_build(array $events, array $logins, array $offline, array $emails): array
    {
        $items = [];
        $bucket = [];   // key => [count, last, groups[normalised => [count, sample]], ids[]]
        foreach ($events as $e) {
            $source = (string)($e['source'] ?? '');
            $level = (string)($e['level'] ?? '');
            $message = (string)($e['message'] ?? '');
            $key = rh_log_classify_event($source, $level, $message);
            if ($key === null) {
                continue;
            }
            $at = (string)($e['created_at'] ?? '');
            $b = $bucket[$key] ?? ['count' => 0, 'last' => '', 'groups' => [], 'ids' => []];
            $b['count']++;
            if ($at > $b['last']) {
                $b['last'] = $at;
            }
            $norm = rh_log_normalize_message($message);
            if (!isset($b['groups'][$norm])) {
                $b['groups'][$norm] = ['count' => 0, 'sample' => $message];
            }
            $b['groups'][$norm]['count']++;
            if ($key === 'stock_negative' && preg_match('/Ingredient #(\d+)/', $message, $mm)) {
                $b['ids'][$mm[1]] = true;
            }
            $bucket[$key] = $b;
        }
        $examples = function (array $groups, int $max = 3): array {
            uasort($groups, function ($a, $b) { return $b['count'] <=> $a['count']; });
            $out = [];
            foreach (array_slice($groups, 0, $max) as $g) {
                $out[] = mb_substr($g['sample'], 0, 140) . ($g['count'] > 1 ? ' (x' . $g['count'] . ')' : '');
            }
            return $out;
        };

        if (isset($bucket['error'])) {
            $b = $bucket['error'];
            $items[] = [
                'key' => 'error', 'level' => 'danger', 'title' => 'Errors the system logged',
                'count' => $b['count'], 'last_seen' => $b['last'], 'highlight' => false,
                'meaning' => 'Something failed while the system was working. This is not a typing mistake. If the same message keeps coming back, send it to support.',
                'examples' => $examples($b['groups']),
                'link' => ['page' => 'system-logs.php?level=error#log-events', 'label' => 'Show the errors', 'permission' => null, 'module' => null],
            ];
        }

        // Failed sign-ins.
        if ($logins) {
            $byIp = [];
            $unknown = 0;
            $wrong = 0;
            $blocked = 0;
            $last = '';
            foreach ($logins as $l) {
                $ip = trim((string)($l['ip_address'] ?? ''));
                $at = (string)($l['created_at'] ?? '');
                $ts = strtotime($at) ?: 0;
                $byIp[$ip === '' ? 'unknown address' : $ip][] = $ts;
                $action = (string)($l['action'] ?? '');
                $details = (string)($l['details'] ?? '');
                if ($action === 'login_blocked') {
                    $blocked++;
                } elseif (stripos($details, 'Unknown username') === 0) {
                    $unknown++;
                } else {
                    $wrong++;
                }
                if ($at > $last) {
                    $last = $at;
                }
            }
            $repeaters = [];
            foreach ($byIp as $ip => $times) {
                if (rh_log_burst($times, 3, 3600)) {
                    $repeaters[$ip] = count($times);
                }
            }
            arsort($repeaters);
            $ex = [];
            if ($repeaters) {
                foreach (array_slice($repeaters, 0, 3, true) as $ip => $n) {
                    $ex[] = $ip . ': ' . $n . ' failed tries, 3 or more inside one hour';
                }
            } else {
                $perIp = array_map('count', $byIp);
                arsort($perIp);
                $topIp = (string)array_key_first($perIp);
                $ex[] = 'Most from ' . $topIp . ' (' . ($perIp[$topIp] ?? 0) . ' tries)';
            }
            $parts = [];
            if ($wrong) { $parts[] = $wrong . ' wrong password'; }
            if ($unknown) { $parts[] = $unknown . ' unknown username'; }
            if ($blocked) { $parts[] = $blocked . ' blocked'; }
            array_unshift($ex, implode(', ', $parts));
            $items[] = [
                'key' => 'failed_login', 'level' => $repeaters ? 'danger' : 'warning',
                'title' => $repeaters ? 'Repeated failed sign-ins from one address' : 'Failed sign-ins',
                'count' => count($logins), 'last_seen' => $last, 'highlight' => (bool)$repeaters,
                'meaning' => $repeaters
                    ? 'Three or more failed tries from the same address within an hour can mean someone is guessing passwords. If the address is not one of your staff, change that user\'s password.'
                    : 'Someone typed a wrong username or password. A few are normal (a forgotten password).',
                'examples' => $ex,
                'link' => ['page' => 'system-logs.php#log-logins', 'label' => 'See the sign-ins', 'permission' => null, 'module' => null],
            ];
        }

        // Failed emails: event log + automated email log.
        $emailCount = ($bucket['email_failed']['count'] ?? 0) + count($emails);
        if ($emailCount > 0) {
            $last = $bucket['email_failed']['last'] ?? '';
            $groups = $bucket['email_failed']['groups'] ?? [];
            foreach ($emails as $m) {
                $at = (string)($m['at'] ?? '');
                if ($at > $last) { $last = $at; }
                $msg = 'Automated email to ' . (string)($m['recipient'] ?? '?') . ' failed: ' . (string)($m['error'] ?? '');
                $norm = rh_log_normalize_message($msg);
                if (!isset($groups[$norm])) { $groups[$norm] = ['count' => 0, 'sample' => $msg]; }
                $groups[$norm]['count']++;
            }
            $items[] = [
                'key' => 'email_failed', 'level' => 'warning', 'title' => 'Emails that did not send',
                'count' => $emailCount, 'last_seen' => $last, 'highlight' => false,
                'meaning' => 'The mail server refused the message, usually a wrong or made-up guest address. Check the address on the booking, or the email settings if it happens for everyone.',
                'examples' => $examples($groups),
                'link' => ['page' => 'automated-emails.php', 'label' => 'Automated Emails', 'permission' => 'booking_settings', 'module' => null],
            ];
        }

        if (isset($bucket['stock_negative'])) {
            $b = $bucket['stock_negative'];
            $n = count($b['ids']);
            $items[] = [
                'key' => 'stock_negative', 'level' => 'warning', 'title' => 'Stock went below zero',
                'count' => $b['count'], 'last_seen' => $b['last'], 'highlight' => false,
                'meaning' => 'Items were sold or used before their delivery or count was recorded' . ($n ? ' (' . $n . ' ingredient' . ($n === 1 ? '' : 's') . ' affected)' : '') . '. Receive the stock or do a Stock Count so the numbers are right.',
                'examples' => $examples($b['groups']),
                'link' => ['page' => 'stock-count.php', 'label' => 'Stock Count', 'permission' => 'stock_count', 'module' => 'stock'],
            ];
        }

        if ($offline) {
            $groups = [];
            $last = '';
            foreach ($offline as $o) {
                $at = (string)($o['replayed_at'] ?? '');
                if ($at > $last) { $last = $at; }
                $msg = (string)($o['action'] ?? 'action') . ' (HTTP ' . (int)($o['response_status'] ?? 0) . '): ' . (string)($o['response_summary'] ?? '');
                $norm = rh_log_normalize_message($msg);
                if (!isset($groups[$norm])) { $groups[$norm] = ['count' => 0, 'sample' => $msg]; }
                $groups[$norm]['count']++;
            }
            $items[] = [
                'key' => 'offline_fail', 'level' => 'danger', 'title' => 'Offline work that did not sync',
                'count' => count($offline), 'last_seen' => $last, 'highlight' => false,
                'meaning' => 'A sale or action done while a device was offline was refused when it reconnected, so it was not saved. Open the Offline Log and enter it again by hand.',
                'examples' => $examples($groups),
                'link' => ['page' => 'offline-log.php', 'label' => 'Offline Log', 'permission' => 'offline_log_view', 'module' => 'pos'],
            ];
        }

        if (isset($bucket['api_auth'])) {
            $b = $bucket['api_auth'];
            $items[] = [
                'key' => 'api_auth', 'level' => 'warning', 'title' => 'API key refused',
                'count' => $b['count'], 'last_seen' => $b['last'], 'highlight' => false,
                'meaning' => 'Someone called the hotel\'s API with a wrong or missing key. If a partner site is meant to connect, give them a correct key. Random probes can be ignored.',
                'examples' => [],
                'link' => ['page' => 'api-keys.php', 'label' => 'API Keys', 'permission' => 'api_keys', 'module' => null],
            ];
        }

        $rank = ['danger' => 0, 'warning' => 1];
        usort($items, function ($a, $b) use ($rank) {
            return [$rank[$a['level']], -$a['count']] <=> [$rank[$b['level']], -$b['count']];
        });
        return $items;
    }

    /** Read the rows (read-only) and build the items. Any missing table just contributes nothing. */
    function rh_log_attention_load(PDO $pdo, int $days = 7): array
    {
        $days = max(1, min(30, $days));
        $events = $logins = $offline = $emails = [];
        try {
            $st = $pdo->query("SELECT source, level, message, created_at FROM system_event_log
                WHERE created_at >= DATE_SUB(NOW(), INTERVAL {$days} DAY) AND level IN ('warning','error','critical')
                ORDER BY created_at DESC LIMIT 3000");
            $events = $st ? $st->fetchAll(PDO::FETCH_ASSOC) : [];
        } catch (Throwable $e) {
        }
        try {
            $st = $pdo->query("SELECT username, action, details, ip_address, created_at FROM admin_activity_log
                WHERE created_at >= DATE_SUB(NOW(), INTERVAL {$days} DAY) AND action LIKE 'login%' AND action <> 'login_success'
                ORDER BY created_at DESC LIMIT 1000");
            $logins = $st ? $st->fetchAll(PDO::FETCH_ASSOC) : [];
        } catch (Throwable $e) {
        }
        try {
            $st = $pdo->query("SELECT endpoint, action, response_status, response_summary, replayed_at FROM offline_replay_log
                WHERE replayed_at >= DATE_SUB(NOW(), INTERVAL {$days} DAY)
                  AND response_status IS NOT NULL AND (response_status < 200 OR response_status >= 300)
                ORDER BY replayed_at DESC LIMIT 500");
            $offline = $st ? $st->fetchAll(PDO::FETCH_ASSOC) : [];
        } catch (Throwable $e) {
        }
        try {
            $st = $pdo->query("SELECT recipient, error, updated_at AS at FROM automated_email_log
                WHERE status = 'failed' AND updated_at >= DATE_SUB(NOW(), INTERVAL {$days} DAY)
                ORDER BY updated_at DESC LIMIT 200");
            $emails = $st ? $st->fetchAll(PDO::FETCH_ASSOC) : [];
        } catch (Throwable $e) {
        }
        return rh_log_attention_build($events, $logins, $offline, $emails);
    }

    /**
     * Group repeated events (same source, level and message once numbers and addresses are
     * ignored). Input is newest first; output keeps that order, one entry per group:
     * the newest row plus repeat_count, first_seen, last_seen.
     */
    function rh_log_group_events(array $rows): array
    {
        $groups = [];
        foreach ($rows as $r) {
            $k = ($r['source'] ?? '') . '|' . ($r['level'] ?? '') . '|' . rh_log_normalize_message((string)($r['message'] ?? ''));
            $at = (string)($r['created_at'] ?? '');
            if (!isset($groups[$k])) {
                $r['repeat_count'] = 1;
                $r['first_seen'] = $at;
                $r['last_seen'] = $at;
                $groups[$k] = $r;
                continue;
            }
            $groups[$k]['repeat_count']++;
            if ($at !== '' && $at < $groups[$k]['first_seen']) {
                $groups[$k]['first_seen'] = $at;
            }
            if ($at > $groups[$k]['last_seen']) {
                $groups[$k]['last_seen'] = $at;
            }
        }
        return array_values($groups);
    }
}
