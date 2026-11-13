<?php
/**
 * Test platform mail guard (security patch 2026-11-14).
 *
 * A site marked as the test platform (site setting site_is_test_platform = '1'; code default '0',
 * only the test hotel has the row) must never email a real person. Every outgoing email, scheduled
 * or sent by staff, is redirected to the test recipient (automated_email_test_recipient): all
 * To/CC/BCC addresses are removed, the subject gets "[TEST - <label>]" and the original recipients
 * are listed at the top of the body. With no valid test recipient the message is left with no
 * recipients at all, so the mailer refuses it (fail closed, never the real addresses).
 * Label: site_test_platform_label, else the site name. WhatsApp sends are refused on the test platform.
 *
 * Hooked in two places so no send path can miss it: hotel_embed_logo_cid() (every mailer calls it
 * once the recipients and subject are set) and a call right before every $mail->send().
 * The call is idempotent: a second call keeps the first list and adds any address added since.
 * Site setting missing or '0' (Rosalyn's): nothing is touched.
 */

if (!function_exists('rh_test_platform_decide')) {

    /** Pure: flag value + test recipient -> ['active' => bool, 'to' => valid test address or '']. */
    function rh_test_platform_decide($flag, $recipient): array
    {
        if (trim((string)$flag) !== '1') {
            return ['active' => false, 'to' => ''];
        }
        $to = trim((string)$recipient);
        return ['active' => true, 'to' => filter_var($to, FILTER_VALIDATE_EMAIL) ? $to : ''];
    }

    /** This site's mode from its settings (missing setting = not the test platform). Never throws. */
    function rh_test_platform_mode(): array
    {
        if (!function_exists('getSetting')) {
            return ['active' => false, 'to' => ''];
        }
        try {
            $flag = (string)getSetting('site_is_test_platform', '0');
            if (trim($flag) !== '1') {
                return ['active' => false, 'to' => ''];
            }
            return rh_test_platform_decide($flag, (string)getSetting('automated_email_test_recipient', ''));
        } catch (Throwable $e) {
            error_log('test platform mode: ' . $e->getMessage());
            return ['active' => false, 'to' => ''];
        }
    }

    function rh_test_platform_active(): bool
    {
        return rh_test_platform_mode()['active'];
    }

    /** Label in the subject prefix: site_test_platform_label, else the site name, else 'test platform'. */
    function rh_test_platform_label(): string
    {
        $label = '';
        if (function_exists('getSetting')) {
            try {
                $label = trim((string)getSetting('site_test_platform_label', ''));
                if ($label === '') {
                    $label = trim((string)getSetting('site_name', ''));
                }
            } catch (Throwable $e) {
                $label = '';
            }
        }
        $label = trim((string)preg_replace('/[\r\n\[\]]+/', ' ', $label));
        return $label !== '' ? $label : 'test platform';
    }

    /** Pure: "[TEST - label] subject", once; a scheduler "[TEST] " prefix is folded into it. */
    function rh_test_platform_subject(string $subject, string $label): string
    {
        $prefix = '[TEST - ' . $label . ']';
        if (strpos($subject, $prefix) === 0) {
            return $subject;
        }
        $subject = (string)preg_replace('/^\s*\[TEST\]\s*/i', '', $subject);
        return $prefix . ' ' . ltrim($subject);
    }

    /** Pure: merge address lists (lower-cased dedupe, order kept). */
    function rh_test_platform_merge(array $a, array $b): array
    {
        $seen = [];
        $out = [];
        foreach (array_merge($a, $b) as $addr) {
            $addr = trim((string)$addr);
            $k = strtolower($addr);
            if ($addr === '' || isset($seen[$k])) {
                continue;
            }
            $seen[$k] = true;
            $out[] = $addr;
        }
        return $out;
    }

    /** Pure: the plain-text notice (between markers so it can be replaced). */
    function rh_test_platform_notice_text(array $orig): string
    {
        $line = static function (array $l): string {
            return $l ? implode(', ', $l) : '(none)';
        };
        return "*** TEST PLATFORM - this email was redirected, nobody else received it ***\n"
            . 'Original To: ' . $line($orig['to'] ?? []) . "\n"
            . 'Original CC: ' . $line($orig['cc'] ?? []) . "\n"
            . 'Original BCC: ' . $line($orig['bcc'] ?? []) . "\n"
            . "*** END TEST NOTICE ***\n\n";
    }

    /** Pure: the HTML notice (escaped, between comment markers). */
    function rh_test_platform_notice_html(array $orig): string
    {
        $line = static function (array $l): string {
            return $l ? htmlspecialchars(implode(', ', $l), ENT_QUOTES, 'UTF-8') : '(none)';
        };
        return '<!--rh-test-platform--><div style="border:2px solid #b45309;background:#fff7ed;color:#7c2d12;padding:10px 14px;margin:0 0 12px;font:13px/1.5 Arial,sans-serif;">'
            . '<strong>TEST PLATFORM</strong> - this email was redirected, nobody else received it.<br>'
            . 'Original To: ' . $line($orig['to'] ?? []) . '<br>'
            . 'Original CC: ' . $line($orig['cc'] ?? []) . '<br>'
            . 'Original BCC: ' . $line($orig['bcc'] ?? [])
            . '</div><!--/rh-test-platform-->';
    }

    /** Pure: $html with the notice (any older one replaced), right after <body ...> when there is one. */
    function rh_test_platform_html_with_notice(string $html, array $orig): string
    {
        $html = (string)preg_replace('#<!--rh-test-platform-->.*?<!--/rh-test-platform-->#s', '', $html);
        $notice = rh_test_platform_notice_html($orig);
        if (preg_match('#<body\b[^>]*>#i', $html, $m, PREG_OFFSET_CAPTURE)) {
            $at = $m[0][1] + strlen($m[0][0]);
            return substr($html, 0, $at) . $notice . substr($html, $at);
        }
        return $notice . $html;
    }

    /** Pure: $text with the plain-text notice on top (any older one replaced). */
    function rh_test_platform_text_with_notice(string $text, array $orig): string
    {
        $text = (string)preg_replace('#\*\*\* TEST PLATFORM - .*?\*\*\* END TEST NOTICE \*\*\*\n\n#s', '', $text);
        return rh_test_platform_notice_text($orig) . $text;
    }

    /** Original recipients per message (WeakMap on PHP 8, else a small capped list holding the objects). */
    function &rh_test_platform_registry()
    {
        static $reg = null;
        if ($reg === null) {
            $reg = class_exists('WeakMap') ? new WeakMap() : [];
        }
        return $reg;
    }

    /** The original recipients of a redirected message, or null when it was not redirected. */
    function rh_test_platform_mail_original($mail): ?array
    {
        if (!is_object($mail)) {
            return null;
        }
        $reg = &rh_test_platform_registry();
        if ($reg instanceof WeakMap) {
            return isset($reg[$mail]) ? $reg[$mail] : null;
        }
        foreach ($reg as $e) {
            if ($e['mail'] === $mail) {
                return $e['orig'];
            }
        }
        return null;
    }

    function rh_test_platform_mail_remember($mail, array $orig): void
    {
        $reg = &rh_test_platform_registry();
        if ($reg instanceof WeakMap) {
            $reg[$mail] = $orig;
            return;
        }
        foreach ($reg as $i => $e) {
            if ($e['mail'] === $mail) {
                $reg[$i]['orig'] = $orig;
                return;
            }
        }
        $reg[] = ['mail' => $mail, 'orig' => $orig];
        if (count($reg) > 50) {
            array_shift($reg);
        }
    }

    /**
     * Redirect $mail when this site is the test platform. Returns true when the message is (now)
     * redirected. $mode is for tests (['active' => bool, 'to' => string]); null = this site's settings.
     * $bodyHtml: when given (the logo hook), the notice goes into that HTML instead of $mail->Body.
     */
    function rh_test_platform_mail_apply($mail, ?array $mode = null, ?string &$bodyHtml = null): bool
    {
        if (!is_object($mail) || !method_exists($mail, 'clearAllRecipients')) {
            return false;
        }
        $mode = $mode ?? rh_test_platform_mode();
        if (empty($mode['active'])) {
            return false;
        }
        $test = (string)($mode['to'] ?? '');
        $pick = static function (array $list): array {
            $out = [];
            foreach ($list as $a) {
                $out[] = (string)($a[0] ?? '');
            }
            return $out;
        };
        $to = $pick($mail->getToAddresses());
        $cc = $pick($mail->getCcAddresses());
        $bcc = $pick($mail->getBccAddresses());
        $prev = rh_test_platform_mail_original($mail);
        if ($prev !== null && $test !== '') {
            // Already redirected: the test address in To is ours, not an original recipient.
            $to = array_values(array_filter($to, static function ($a) use ($test) {
                return strcasecmp($a, $test) !== 0;
            }));
        }
        if ($prev === null && !empty($GLOBALS['rh_tp_redirected_from'])) {
            // The scheduler's own test mode already swapped To before the message was built.
            $to = rh_test_platform_merge((array)$GLOBALS['rh_tp_redirected_from'], $to);
            unset($GLOBALS['rh_tp_redirected_from']);
        }
        $orig = [
            'to' => rh_test_platform_merge($prev['to'] ?? [], $to),
            'cc' => rh_test_platform_merge($prev['cc'] ?? [], $cc),
            'bcc' => rh_test_platform_merge($prev['bcc'] ?? [], $bcc),
        ];
        $mail->clearAllRecipients();
        if ($test !== '') {
            $mail->addAddress($test);
        }
        // No valid test address: no recipients at all, so send() fails instead of reaching anyone.
        rh_test_platform_mail_remember($mail, $orig);

        $mail->Subject = rh_test_platform_subject((string)$mail->Subject, rh_test_platform_label());
        if ($bodyHtml !== null) {
            $bodyHtml = rh_test_platform_html_with_notice($bodyHtml, $orig);
        } elseif ((string)$mail->Body !== '') {
            $mail->Body = (stripos((string)$mail->ContentType, 'html') !== false)
                ? rh_test_platform_html_with_notice((string)$mail->Body, $orig)
                : rh_test_platform_text_with_notice((string)$mail->Body, $orig);
        }
        if ((string)$mail->AltBody !== '') {
            $mail->AltBody = rh_test_platform_text_with_notice((string)$mail->AltBody, $orig);
        }
        return true;
    }

    /** email_send_log "cc" text for a redirected message: who it would have gone to. */
    function rh_test_platform_log_text(array $orig): string
    {
        $line = static function (array $l): string {
            return $l ? implode(', ', $l) : '(none)';
        };
        return 'TEST PLATFORM redirect - originally To: ' . $line($orig['to'] ?? [])
            . '; CC: ' . $line($orig['cc'] ?? []) . '; BCC: ' . $line($orig['bcc'] ?? []);
    }
}
