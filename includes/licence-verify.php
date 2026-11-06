<?php
/**
 * COPIED UNCHANGED (logic) from the licence panel: msp-control-panel/client/licence-verify.php (2026-11-07).
 * Do not edit here; change the panel copy and re-copy, so both stay identical. Pure functions lv_*, no DB.
 */
/**
 * Reference licence verifier for the hotel PMS. Self-contained: no DB, no includes, PHP 7.4+.
 *
 * Intended use in the PMS:
 *   - Hold the control panel's PUBLIC key as a code constant (never a setting, never the DB).
 *   - Once a day POST {tenant, domain, key, version} to the panel's api/licence.php and store the
 *     last GOOD response ({payload, sig}) plus the issued_at that was accepted.
 *   - On each request (cache the answer for a few minutes) call lv_evaluate() with the stored token.
 *     Persist $result['issued_at'] as the new "last accepted issued_at" when it is not null and larger.
 *   - Enforce $result['status']: active = normal; grace = show a banner; read_only = view, check-out
 *     and export only; suspended = lock the admin. The guest website is never affected.
 *   - $fallback is what to use when there is no valid token at all ('active' for 'unconfigured').
 *
 * Nothing here throws to the caller.
 */

const LV_STATUSES = ['active', 'grace', 'read_only', 'suspended'];

function lv_rank(string $s): int
{
    $i = array_search($s, LV_STATUSES, true);
    return $i === false ? 3 : $i;
}

function lv_b64url_decode($s)
{
    if (!is_string($s) || $s === '' || !preg_match('/^[A-Za-z0-9_-]+$/', $s) || strlen($s) % 4 === 1) {
        return false;
    }
    $pad = (4 - strlen($s) % 4) % 4;
    return base64_decode(strtr($s, '-_', '+/') . str_repeat('=', $pad), true);
}

function lv_normalise_domain(string $d): string
{
    $d = strtolower(trim($d));
    $d = preg_replace('#^[a-z][a-z0-9+.\-]*://#', '', $d);
    $d = preg_split('#[/?\\#]#', $d, 2)[0];
    $at = strrpos($d, '@');
    if ($at !== false) {
        $d = substr($d, $at + 1);
    }
    $d = preg_replace('#:\d+$#', '', $d);
    $d = rtrim($d, '.');
    if (strpos($d, 'www.') === 0) {
        $d = substr($d, 4);
    }
    return $d;
}

function lv_valid_date($d): bool
{
    return is_string($d) && preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $d, $m) && checkdate((int)$m[2], (int)$m[3], (int)$m[1]);
}

function lv_add_days(string $date, int $days): string
{
    $d = new DateTimeImmutable($date, new DateTimeZone('UTC'));
    return $d->modify(($days >= 0 ? '+' : '') . $days . ' days')->format('Y-m-d');
}

// Same rule as the panel: today <= paid_until active; then grace; then read_only; then suspended.
function lv_schedule_status(string $paidUntil, int $graceDays, int $readonlyDays, string $today): string
{
    $graceEnd = lv_add_days($paidUntil, $graceDays);
    $roEnd = lv_add_days($graceEnd, $readonlyDays);
    if ($today <= $paidUntil) {
        return 'active';
    }
    if ($today <= $graceEnd) {
        return 'grace';
    }
    if ($today <= $roEnd) {
        return 'read_only';
    }
    return 'suspended';
}

/**
 * Verify signature and bind the token to this site. Returns
 * ['ok' => bool, 'reason' => string, 'payload' => array|null].
 */
function lv_check_token($token, string $publicKeyPem, string $siteTenant, string $siteDomain, int $lastIssuedAt, int $now): array
{
    $fail = function (string $why) {
        return ['ok' => false, 'reason' => $why, 'payload' => null];
    };
    try {
        if (is_string($token)) {
            $token = json_decode($token, true);
        }
        if (!is_array($token) || !isset($token['payload'], $token['sig']) || !is_string($token['payload']) || !is_string($token['sig'])) {
            return $fail('malformed');
        }
        $json = lv_b64url_decode($token['payload']);
        $sig = lv_b64url_decode($token['sig']);
        if ($json === false || $sig === false) {
            return $fail('malformed');
        }
        $pub = @openssl_pkey_get_public($publicKeyPem);
        if ($pub === false) {
            return $fail('bad_public_key');
        }
        if (@openssl_verify($json, $sig, $pub, OPENSSL_ALGO_SHA256) !== 1) {
            return $fail('bad_signature');
        }
        $p = json_decode($json, true);
        if (!is_array($p)
            || ($p['v'] ?? null) !== 1
            || !isset($p['tenant'], $p['domain'], $p['status'], $p['paid_until'])
            || !is_string($p['tenant']) || !is_string($p['domain'])
            || !is_string($p['status']) || !in_array($p['status'], LV_STATUSES, true)
            || !lv_valid_date($p['paid_until'])
            || !isset($p['grace_days'], $p['readonly_days'], $p['issued_at'], $p['valid_until'])
            || !is_int($p['grace_days']) || !is_int($p['readonly_days'])
            || !is_int($p['issued_at']) || !is_int($p['valid_until'])
            || $p['grace_days'] < 0 || $p['grace_days'] > 3650
            || $p['readonly_days'] < 0 || $p['readonly_days'] > 3650
            || $p['valid_until'] <= $p['issued_at']) {
            return $fail('bad_payload');
        }
        if (strtolower($p['tenant']) !== strtolower($siteTenant)) {
            return $fail('tenant_mismatch');
        }
        if (!hash_equals(lv_normalise_domain($p['domain']), lv_normalise_domain($siteDomain))) {
            return $fail('domain_mismatch');
        }
        if ($p['issued_at'] < $lastIssuedAt) {
            return $fail('replayed');
        }
        $p['override'] = !empty($p['override']);
        // Additive fields: older panels may not send them.
        $p['business_type'] = isset($p['business_type']) && is_string($p['business_type']) ? $p['business_type'] : null;
        $mods = [];
        if (isset($p['modules']) && is_array($p['modules'])) {
            foreach ($p['modules'] as $m) {
                if (is_string($m) && preg_match('/^[a-z0-9_]{1,40}$/', $m) && !in_array($m, $mods, true)) {
                    $mods[] = $m;
                }
            }
        }
        $p['modules'] = isset($p['modules']) && is_array($p['modules']) ? $mods : null;
        return ['ok' => true, 'reason' => 'ok', 'payload' => $p];
    } catch (Throwable $e) {
        return $fail('error');
    }
}

/**
 * The one call the PMS makes. Returns:
 *   status    active|grace|read_only|suspended (or $fallback when no valid token)
 *   source    'server' | 'server_override' | 'schedule' | 'fallback'
 *   reason    short machine-readable reason
 *   issued_at accepted issued_at (int) or null
 *   payload   verified payload or null
 *   business_type  licensed business type key, or null when the token carries none (older panel)
 *   modules   list of licensed module keys, or null when the token carries none. null means "not stated":
 *             the PMS should then keep its own settings rather than switch everything off.
 *
 * $token: the stored {payload, sig} array or its JSON string (or null).
 * $lastIssuedAt: highest issued_at ever accepted (0 if none) - anti-replay.
 * $now: unix time (pass time()).
 */
function lv_evaluate($token, string $publicKeyPem, string $siteTenant, string $siteDomain, int $lastIssuedAt, int $now, string $fallback = 'active'): array
{
    $fb = ['status' => $fallback, 'source' => 'fallback', 'reason' => 'no_token', 'issued_at' => null, 'payload' => null, 'business_type' => null, 'modules' => null];
    try {
        if ($token === null || $token === '' || $token === []) {
            return $fb;
        }
        $c = lv_check_token($token, $publicKeyPem, $siteTenant, $siteDomain, $lastIssuedAt, $now);
        if (!$c['ok']) {
            $fb['reason'] = $c['reason'];
            return $fb;
        }
        $p = $c['payload'];
        // A clock set back can never loosen the status: time never runs earlier than a token already seen.
        $now = max($now, $lastIssuedAt, $p['issued_at']);
        $today = date('Y-m-d', $now);
        $schedule = lv_schedule_status($p['paid_until'], $p['grace_days'], $p['readonly_days'], $today);
        $stricter = lv_rank($schedule) > lv_rank($p['status']) ? $schedule : $p['status'];

        if ($now <= $p['valid_until']) {
            if ($p['override']) {
                // Owner forced this status by hand; the date schedule does not apply.
                $status = $p['status'];
                $source = 'server_override';
            } else {
                $status = $stricter;
                $source = 'server';
            }
        } elseif ($p['override']) {
            // Token aged out. A forced restriction (e.g. suspended) must not lapse to a friendlier
            // date schedule; a forced "active" does lapse to the schedule.
            $status = $stricter;
            $source = 'schedule';
        } else {
            // Site offline or blocked: the schedule alone keeps stepping down.
            $status = $schedule;
            $source = 'schedule';
        }
        return ['status' => $status, 'source' => $source, 'reason' => 'ok', 'issued_at' => $p['issued_at'], 'payload' => $p,
            'business_type' => $p['business_type'], 'modules' => $p['modules']];
    } catch (Throwable $e) {
        $fb['reason'] = 'error';
        return $fb;
    }
}
