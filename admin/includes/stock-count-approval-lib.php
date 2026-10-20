<?php

/**
 * Stock count approval helpers (who can approve, who must be a second person, emailing approvers).
 *
 * Approver = an active user who holds the 'stock_count_approve' permission (admin and manager roles by default,
 * grantable to anyone in User Management) AND can open the Stock Count page ('stock_count'). The person who
 * counted is never listed as an approver of their own count here; the server still enforces that an opening
 * count, and a count with shortages over the approval threshold, is approved by someone other than the counter.
 *
 * Emails: one short plain staff email per approver (not the counter, with a valid address). Sending never
 * blocks the caller. The "once per 10 minutes per count" reminder limit is read from admin_activity_log (no
 * new table). Tests can capture instead of sending: set $GLOBALS['rh_test_email_capture'] = [] (CLI only).
 */

// Top level on purpose: email.php sets up globals that must stay global.
require_once __DIR__ . '/../../config/email.php';

if (!defined('RH_SCA_REMIND_SECONDS')) {
    define('RH_SCA_REMIND_SECONDS', 600);
}

if (!function_exists('rh_sca_approvers')) {

    /**
     * Active users who can approve counts, excluding $excludeUserId (the counter).
     * @return array<int,array{id:int,full_name:string,email:string,role:string}>
     */
    function rh_sca_approvers(PDO $pdo, int $excludeUserId = 0): array
    {
        $out = [];
        try {
            $rows = $pdo->query("SELECT id, full_name, username, email, role FROM admin_users WHERE is_active = 1 ORDER BY full_name, id")->fetchAll(PDO::FETCH_ASSOC);
        } catch (Throwable $e) {
            return [];
        }
        foreach ($rows as $r) {
            $id = (int)$r['id'];
            if ($id === $excludeUserId) {
                continue;
            }
            if (!hasPermission($id, 'stock_count_approve') || !hasPermission($id, 'stock_count')) {
                continue;
            }
            $out[] = [
                'id' => $id,
                'full_name' => trim((string)$r['full_name']) !== '' ? (string)$r['full_name'] : (string)$r['username'],
                'email' => trim((string)$r['email']),
                'role' => (string)$r['role'],
            ];
        }
        return $out;
    }

    /** Approver names for display, e.g. "Mary Banda, Joe Phiri". */
    function rh_sca_approver_names(array $approvers): string
    {
        return implode(', ', array_map(static fn($a) => (string)$a['full_name'], $approvers));
    }

    /**
     * Does this submitted count need a second person (not the counter) to approve it?
     * Opening counts always do; any other count does when a line is a shortage over both approval thresholds.
     * Uses the stored line figures (the real approval re-checks against stock movements at count time).
     */
    function rh_sca_needs_second_person(PDO $pdo, array $count, float $blockPct, float $minCost): bool
    {
        if (!empty($count['is_opening'])) {
            return true;
        }
        $q = $pdo->prepare("SELECT system_quantity, variance, variance_cost FROM stock_count_lines WHERE count_id = ? AND variance < 0");
        $q->execute([(int)$count['id']]);
        foreach ($q->fetchAll(PDO::FETCH_ASSOC) as $ln) {
            $variance = (float)$ln['variance'];
            $varCost = (float)$ln['variance_cost'];
            if ($variance >= 0 || abs($varCost) < $minCost) {
                continue;
            }
            $base = (float)$ln['system_quantity'];
            $pct = $base > 0 ? abs($variance) / $base * 100 : 100;
            if ($pct >= $blockPct) {
                return true;
            }
        }
        return false;
    }

    /** Seconds until another reminder may be sent for this count (0 = may send now). */
    function rh_sca_remind_wait(PDO $pdo, int $countId, ?int $now = null): int
    {
        try {
            $q = $pdo->prepare("SELECT MAX(UNIX_TIMESTAMP(created_at)) FROM admin_activity_log
                                WHERE action IN ('stock_count_approvers_emailed','stock_count_approvers_reminded')
                                  AND details LIKE ?");
            $q->execute(['%(#' . $countId . ')%']);
            $last = (int)$q->fetchColumn();
            if ($last <= 0) {
                return 0;
            }
            $now = $now ?? (int)$pdo->query("SELECT UNIX_TIMESTAMP()")->fetchColumn();
            return max(0, RH_SCA_REMIND_SECONDS - ($now - $last));
        } catch (Throwable $e) {
            return 0;
        }
    }

    /** Base URL for links in emails: the site_url setting the other admin emails use. */
    function rh_sca_base_url(): string
    {
        $base = trim((string)getSetting('site_url', ''));
        if ($base === '' && defined('BASE_URL')) {
            $base = (string)BASE_URL;
        }
        return rtrim($base, '/');
    }

    /** Short plain staff email for one approver. Returns [subject, innerHtml, text]. */
    function rh_sca_email_content(array $count, array $info, string $recipientName, string $currency, string $siteName, string $link): array
    {
        $e = static fn($v): string => htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8');
        $ref = (string)preg_replace('/^SC-/i', '', trim((string)$count['reference']));
        $kind = !empty($count['is_opening']) ? 'Opening stock count' : 'Stock count';
        $counter = (string)$info['counter_name'];
        $lines = (int)$info['lines'];
        $short = $currency . ' ' . number_format(abs((float)$info['shortage']), 2);
        $surplus = $currency . ' ' . number_format((float)$info['surplus'], 2);
        $second = !empty($info['needs_second'])
            ? 'Yes. A second person must approve it, not ' . $counter . '.'
            : 'No. Any approver can approve it.';
        $reminder = !empty($info['is_reminder']);
        $subject = ($reminder ? 'Reminder: ' : '') . $kind . ' ' . $ref . ' is waiting for your approval';

        $row = static fn(string $k, string $v): string =>
            '<tr><td style="padding:6px 10px 6px 0;font-weight:bold;color:#1A1A1A;vertical-align:top;white-space:nowrap;">' . $k . '</td><td style="padding:6px 0;color:#333;">' . $v . '</td></tr>';
        $html = '<p>Dear ' . $e($recipientName) . ',</p>'
            . '<p>' . ($reminder ? 'This is a reminder that a' : 'A') . ' ' . $e(strtolower($kind)) . ' is waiting for your approval at <strong>' . $e($siteName) . '</strong>.</p>'
            . '<table role="presentation" cellspacing="0" cellpadding="0" style="margin:12px 0 18px;">'
            . $row('Count:', $e($ref))
            . $row('Type:', !empty($count['is_opening']) ? 'Opening stock count' : 'Regular count')
            . $row('Counted by:', $e($counter))
            . $row('Lines:', (string)$lines)
            . $row('Shortage:', $e($short))
            . $row('Surplus:', $e($surplus))
            . $row('Second person needed:', $e($second))
            . '</table>'
            . '<p style="text-align:center;margin:22px 0;"><a href="' . $e($link) . '" style="display:inline-block;background:#7E684B;color:#ffffff;padding:12px 28px;text-decoration:none;border-radius:4px;">Open the count</a></p>'
            . '<p style="font-size:12px;color:#777;">Button not working? Copy this link into your browser: ' . $e($link) . '</p>';
        $text = "Dear {$recipientName},\n\n" . ($reminder ? 'Reminder: ' : '') . "{$kind} {$ref} is waiting for your approval.\n"
            . "Counted by: {$counter}\nLines: {$lines}\nShortage: {$short}\nSurplus: {$surplus}\nSecond person needed: {$second}\n\nOpen the count: {$link}\n";
        return [$subject, $html, $text];
    }

    /**
     * Email the approvers about a submitted count. Never throws.
     *
     * @param string $kind 'submit' (just submitted) or 'remind' (Remind approvers button)
     * @return array{ok:bool,emailed:int,failed:int,skipped_no_email:int,approvers:int,rate_limited:bool,wait:int,message:string}
     */
    function rh_sca_email_approvers(PDO $pdo, int $countId, int $byUserId, string $kind = 'submit'): array
    {
        $res = ['ok' => false, 'emailed' => 0, 'failed' => 0, 'skipped_no_email' => 0, 'approvers' => 0, 'rate_limited' => false, 'wait' => 0, 'message' => ''];
        try {
            $q = $pdo->prepare("SELECT sc.*, cu.full_name AS counter_name FROM stock_counts sc LEFT JOIN admin_users cu ON cu.id = sc.counted_by WHERE sc.id = ?");
            $q->execute([$countId]);
            $count = $q->fetch(PDO::FETCH_ASSOC);
            if (!$count) {
                $res['message'] = 'Count not found.';
                return $res;
            }
            if ($count['status'] !== 'submitted') {
                $res['message'] = 'Only a count that is waiting for approval can be emailed.';
                return $res;
            }
            if ($kind === 'remind') {
                $wait = rh_sca_remind_wait($pdo, $countId);
                if ($wait > 0) {
                    $res['rate_limited'] = true;
                    $res['wait'] = $wait;
                    $res['message'] = 'Approvers were emailed a moment ago. You can remind them again in ' . (int)ceil($wait / 60) . ' minute' . (ceil($wait / 60) == 1 ? '' : 's') . '.';
                    return $res;
                }
            }

            $blockPct = (float)(getSetting('stock_variance_block_pct') ?: 10);
            $minCost = (float)(getSetting('stock_variance_min_cost') ?: 5000);
            $tot = $pdo->prepare("SELECT COUNT(*) AS n, COALESCE(SUM(CASE WHEN variance_cost<0 THEN variance_cost ELSE 0 END),0) AS shortage, COALESCE(SUM(CASE WHEN variance_cost>0 THEN variance_cost ELSE 0 END),0) AS surplus FROM stock_count_lines WHERE count_id = ?");
            $tot->execute([$countId]);
            $t = $tot->fetch(PDO::FETCH_ASSOC);
            $info = [
                'counter_name' => (string)($count['counter_name'] ?: 'a staff member'),
                'lines' => (int)$t['n'],
                'shortage' => (float)$t['shortage'],
                'surplus' => (float)$t['surplus'],
                'needs_second' => rh_sca_needs_second_person($pdo, $count, $blockPct, $minCost),
                'is_reminder' => $kind === 'remind',
            ];

            $approvers = rh_sca_approvers($pdo, (int)$count['counted_by']);
            $res['approvers'] = count($approvers);
            $currency = (string)getSetting('currency_symbol');
            $siteName = (string)(getSetting('site_name') ?: 'the hotel');
            $link = rh_sca_base_url() . '/admin/stock-count.php?id=' . $countId;

            $capture = (PHP_SAPI === 'cli' && isset($GLOBALS['rh_test_email_capture']) && is_array($GLOBALS['rh_test_email_capture']));
            global $smtp_timeout;
            $prevTimeout = $smtp_timeout ?? null;
            $prevFast = $GLOBALS['rh_email_fast_fail'] ?? null;
            if (!$capture) {
                // A person is waiting on this page: one attempt, short timeout, so a dead mail server cannot hang it.
                $smtp_timeout = min((int)($smtp_timeout ?: 15), 15);
                $GLOBALS['rh_email_fast_fail'] = true;
                @set_time_limit(120);
            }
            try {
                foreach ($approvers as $a) {
                    if (!filter_var($a['email'], FILTER_VALIDATE_EMAIL)) {
                        $res['skipped_no_email']++;
                        continue;
                    }
                    [$subject, $html, $text] = rh_sca_email_content($count, $info, $a['full_name'], $currency, $siteName, $link);
                    try {
                        if ($capture) {
                            $GLOBALS['rh_test_email_capture'][] = ['to' => $a['email'], 'user_id' => $a['id'], 'subject' => $subject, 'html' => $html, 'text' => $text];
                            $r = ['success' => true];
                        } else {
                            $r = sendEmail($a['email'], $a['full_name'], $subject, $html, $text);
                        }
                    } catch (Throwable $e) {
                        error_log('Stock count approver email failed: ' . $e->getMessage());
                        $r = ['success' => false];
                    }
                    if (!empty($r['success'])) {
                        $res['emailed']++;
                    } else {
                        $res['failed']++;
                    }
                }
            } finally {
                if (!$capture) {
                    $smtp_timeout = $prevTimeout;
                    if ($prevFast === null) { unset($GLOBALS['rh_email_fast_fail']); } else { $GLOBALS['rh_email_fast_fail'] = $prevFast; }
                }
            }

            $ref = (string)preg_replace('/^SC-/i', '', trim((string)$count['reference']));
            if (function_exists('logActivity') && $byUserId > 0) {
                logActivity(
                    $byUserId,
                    $kind === 'remind' ? 'stock_count_approvers_reminded' : 'stock_count_approvers_emailed',
                    'Count ' . $ref . ' (#' . $countId . '): ' . $res['emailed'] . ' of ' . count($approvers) . ' approver(s) emailed'
                        . ($res['failed'] ? ', ' . $res['failed'] . ' failed' : '')
                        . ($res['skipped_no_email'] ? ', ' . $res['skipped_no_email'] . ' have no email address' : '')
                );
            }
            $res['ok'] = $res['emailed'] > 0;
            $res['message'] = $res['ok']
                ? 'Approvers emailed: ' . $res['emailed'] . '.'
                : (empty($approvers) ? 'Could not email approvers: there is nobody else who can approve this count.' : 'Could not email approvers.');
        } catch (Throwable $e) {
            error_log('Stock count approver email error: ' . $e->getMessage());
            $res['message'] = 'Could not email approvers.';
        }
        return $res;
    }
}
