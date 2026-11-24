<?php
/**
 * Settings forms save only what the person changed (concurrency guardrails task 12, owner FEATURE 2026-11-25).
 *
 * When a settings page is opened it renders a hidden "_settings_base" field: JSON {setting_key: md5(normalised value)}
 * of every setting the form shows, read straight from site_settings (never the getSetting cache). On save, each key
 * goes through rh_settings_base_decide() inside the page's transaction:
 *   skip     - the person left it as it was opened, or typed what is stored now (nothing to write);
 *   write    - only the person changed it (the stored value is still what the page was opened with);
 *   conflict - the person AND someone else changed it, to different values: not written, with a plain message
 *              naming who and when, taken only from a real 'setting_change' row (else "someone else");
 *   no_base  - the key is not in the opened-with map: the caller refuses the whole save with a reload message.
 * A missing or invalid "_settings_base" (rh_settings_base_parse() === null) also refuses the whole save.
 *
 * Secrets (rh_settings_log_secret_key(), and any encrypted 'rk1:'/'wa1:'/'fb1:' value) are never put in the map, not
 * even as a hash; pages keep writing them only when a new one is typed and never pass them to decide.
 *
 * A setting with no row shows the page default (or an older key's value): $spec gives that per key, either the
 * default string or ['default' => '..', 'fallback' => 'older_key']. The same spec is used when the page is rendered
 * and when it is saved, so "no row" reads the same both times.
 *
 * Numbers are compared in plain form ("16.50" = "16.5", the rh_settings_log_same_number rule); leading zeros are kept
 * (a phone number "0999" is not "999"). Text is trimmed and CRLF compared as LF. A spec entry may add 'norm' =>
 * callable(string): string for a value stored in more than one spelling (e.g. JSON re-encoded on save).
 *
 * rh_settings_base_decide_all() / rh_settings_base_save() decide a whole form (task 19a: Hotel details and Review
 * sources) and write only the 'write' keys, in one transaction.
 * Reads go through the app $pdo. Tested by scripts/tests/settings_changed_only.php.
 */

require_once __DIR__ . '/settings-change-log.php';

if (!function_exists('rh_settings_base_norm')) {

    /** One value in comparable form: trimmed, CRLF as LF, a decimal number in plain form (16.50 -> 16.5, 16.0 -> 16). */
    function rh_settings_base_norm($v): string
    {
        $s = is_bool($v) ? ($v ? '1' : '0') : (is_scalar($v) ? (string)$v : '');
        $s = trim(str_replace("\r\n", "\n", $s));
        if (preg_match('/^(-?)(\d{1,15})\.(\d{1,10})$/', $s, $m)) {
            $frac = rtrim($m[3], '0');
            $s = $m[1] . $m[2] . ($frac !== '' ? '.' . $frac : '');
            if (preg_match('/^-0+$/', $s)) {
                $s = substr($s, 1);
            }
        }
        return $s;
    }

    function rh_settings_base_hash($v): string
    {
        return md5(rh_settings_base_norm($v));
    }

    /** A secret never goes in the map (by its key name, or because the stored value is encrypted). */
    function rh_settings_base_is_secret(string $key, $value = null): bool
    {
        return rh_settings_log_secret_key($key) || ($value !== null && rh_settings_log_secret_value($value));
    }

    /** ['default' => string, 'fallback' => string, 'norm' => ?callable] from a spec entry (a default string or an array). */
    function rh_settings_base_entry($entry): array
    {
        if (is_array($entry)) {
            return ['default' => (string)($entry['default'] ?? ''), 'fallback' => (string)($entry['fallback'] ?? ''),
                'norm' => isset($entry['norm']) && is_callable($entry['norm']) ? $entry['norm'] : null];
        }
        return ['default' => is_scalar($entry) ? (string)$entry : '', 'fallback' => '', 'norm' => null];
    }

    /** The comparable form of a value for this entry (its own 'norm' first, then the shared rule). */
    function rh_settings_base_entry_norm($v, array $e): string
    {
        if ($e['norm'] !== null) {
            $v = (string)($e['norm'])(is_scalar($v) ? (string)$v : '');
        }
        return rh_settings_base_norm($v);
    }

    /**
     * Raw stored rows for the keys (and their fallback keys): [key => value]; a key with no row is absent.
     * $forUpdate: lock the rows (call inside the page's transaction).
     */
    function rh_settings_base_rows(PDO $pdo, array $keys, bool $forUpdate = false): array
    {
        $keys = array_values(array_unique(array_filter(array_map('strval', $keys), static function ($k) {
            return $k !== '';
        })));
        if (!$keys) {
            return [];
        }
        $st = $pdo->prepare('SELECT setting_key, setting_value FROM site_settings WHERE setting_key IN ('
            . implode(',', array_fill(0, count($keys), '?')) . ')' . ($forUpdate ? ' FOR UPDATE' : ''));
        $st->execute($keys);
        $rows = [];
        foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) {
            $rows[(string)$r['setting_key']] = $r['setting_value'] === null ? '' : (string)$r['setting_value'];
        }
        return $rows;
    }

    /** What the page shows for a key: its row, else the fallback key's row, else the default. */
    function rh_settings_base_effective(array $rows, string $key, $entry): string
    {
        $e = rh_settings_base_entry($entry);
        if (array_key_exists($key, $rows)) {
            return $rows[$key];
        }
        if ($e['fallback'] !== '' && array_key_exists($e['fallback'], $rows)) {
            return $rows[$e['fallback']];
        }
        return $e['default'];
    }

    /**
     * The map {key: md5(normalised value)} for the keys in $spec (key => default or ['default','fallback']; a plain
     * list of keys means default ''). Secrets are left out.
     */
    function rh_settings_base_map(PDO $pdo, array $spec): array
    {
        $spec = rh_settings_base_spec($spec);
        $want = [];
        foreach ($spec as $key => $entry) {
            if (rh_settings_base_is_secret($key)) {
                continue;
            }
            $want[] = $key;
            $fb = rh_settings_base_entry($entry)['fallback'];
            if ($fb !== '') {
                $want[] = $fb;
            }
        }
        $rows = rh_settings_base_rows($pdo, $want);
        $map = [];
        foreach ($spec as $key => $entry) {
            if (rh_settings_base_is_secret($key)) {
                continue;
            }
            $value = rh_settings_base_effective($rows, $key, $entry);
            if (rh_settings_base_is_secret($key, $value)) {
                continue;
            }
            $map[$key] = md5(rh_settings_base_entry_norm($value, rh_settings_base_entry($entry)));
        }
        return $map;
    }

    /** A plain list of keys -> [key => '']; key => entry stays as it is. Keys must look like setting keys. */
    function rh_settings_base_spec(array $spec): array
    {
        $out = [];
        foreach ($spec as $k => $v) {
            if (is_int($k)) {
                $k = (string)$v;
                $v = '';
            }
            $k = (string)$k;
            if (preg_match('/^[A-Za-z0-9_.-]{1,100}$/', $k)) {
                $out[$k] = $v;
            }
        }
        return $out;
    }

    /** The hidden form field (escaped HTML). Render it inside the form, after any save handler has run. */
    function rh_settings_base_field(PDO $pdo, array $spec): string
    {
        try {
            $json = json_encode(rh_settings_base_map($pdo, $spec), JSON_UNESCAPED_SLASHES);
        } catch (Throwable $e) {
            error_log('settings base field: ' . $e->getMessage());
            $json = ''; // no base: the save is refused with a reload message, never written blind
        }
        return '<input type="hidden" name="_settings_base" value="' . htmlspecialchars((string)$json, ENT_QUOTES, 'UTF-8') . '">';
    }

    /** The posted map, or null when it is missing or not a valid map (the caller refuses the whole save). */
    function rh_settings_base_parse($raw): ?array
    {
        if (!is_string($raw) || $raw === '' || strlen($raw) > 65535) {
            return null;
        }
        $map = json_decode($raw, true);
        if (!is_array($map) || !$map) {
            return null;
        }
        foreach ($map as $k => $h) {
            if (!is_string($k) || !preg_match('/^[A-Za-z0-9_.-]{1,100}$/', $k) || !is_string($h) || !preg_match('/^[a-f0-9]{32}$/', $h)) {
                return null;
            }
        }
        return $map;
    }

    /** The whole-save refusal when the opened-with copy is missing or invalid. */
    function rh_settings_base_reload_message(): string
    {
        return 'This page was opened before a change to how saving works. Reload it and try again. Nothing was saved.';
    }

    /**
     * Who changed the key last and when, from the latest real 'setting_change' row, but only when that row's new
     * value is what is stored now (so a change made some other way is never pinned on an older row).
     * ['name' => string, 'at' => 'd M Y H:i'] or null.
     */
    function rh_settings_base_last_change(PDO $pdo, string $key, string $current): ?array
    {
        try {
            $like = str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $key);
            $st = $pdo->prepare("SELECT user_id, username, details, created_at FROM admin_activity_log
                WHERE action = 'setting_change' AND (details LIKE ? OR details LIKE ?) ORDER BY id DESC LIMIT 1");
            $st->execute(['key=' . $like . '; %', 'page=%; key=' . $like . '; %']);
            $row = $st->fetch(PDO::FETCH_ASSOC);
            if (!$row) {
                return null;
            }
            $p = rh_settings_log_parse((string)$row['details']);
            if ($p === null || $p['key'] !== $key || $p['hidden']) {
                return null;
            }
            $cur = rh_settings_log_clip($current);
            if ($p['new'] !== $cur && rh_settings_base_norm($p['new']) !== rh_settings_base_norm($cur)) {
                return null;
            }
            $name = trim((string)$row['username']);
            if ((int)$row['user_id'] > 0) {
                $u = $pdo->prepare('SELECT full_name FROM admin_users WHERE id = ? LIMIT 1');
                $u->execute([(int)$row['user_id']]);
                $full = trim((string)$u->fetchColumn());
                if ($full !== '') {
                    $name = $full;
                }
            }
            $ts = strtotime((string)$row['created_at']);
            if ($name === '' || $ts === false) {
                return null;
            }
            return ['name' => $name, 'at' => date('d M Y H:i', $ts)];
        } catch (Throwable $e) {
            error_log('settings base last change: ' . $e->getMessage());
            return null;
        }
    }

    /**
     * Decide one key (call inside the page's transaction: the stored row is read FOR UPDATE).
     * $new: the value the save would write (after the page's own cleaning). $opts: the key's spec entry (default
     * string or ['default', 'fallback']) plus optional 'raw' => the posted text before cleaning (a field left as it
     * was opened is a skip even if cleaning would reformat it).
     * Also 'label' (the name used in a conflict message).
     * Returns ['outcome' => skip|write|conflict|no_base, 'current' => stored-now value (null for no_base),
     *          'message' => '' or the plain refusal for a conflict / no_base].
     */
    function rh_settings_base_decide(PDO $pdo, ?array $base, string $key, $new, $opts = []): array
    {
        if ($base === null || !isset($base[$key]) || rh_settings_base_is_secret($key)) {
            return ['outcome' => 'no_base', 'current' => null, 'message' => rh_settings_base_reload_message()];
        }
        $entry = is_array($opts) ? $opts : ['default' => $opts];
        $e = rh_settings_base_entry($entry);
        $loaded = (string)$base[$key];
        $want = [$key];
        if ($e['fallback'] !== '') {
            $want[] = $e['fallback'];
        }
        $current = rh_settings_base_effective(rh_settings_base_rows($pdo, $want, true), $key, $e);
        if (rh_settings_base_is_secret($key, $current)) {
            return ['outcome' => 'no_base', 'current' => null, 'message' => rh_settings_base_reload_message()];
        }
        $newN = rh_settings_base_entry_norm($new, $e);
        $raw = is_array($opts) && array_key_exists('raw', $opts) && $opts['raw'] !== null ? $opts['raw'] : null;
        if (hash_equals($loaded, md5($newN)) || ($raw !== null && hash_equals($loaded, md5(rh_settings_base_entry_norm($raw, $e))))) {
            return ['outcome' => 'skip', 'current' => $current, 'message' => '']; // left as it was opened
        }
        $curN = rh_settings_base_entry_norm($current, $e);
        if ($newN === $curN) {
            return ['outcome' => 'skip', 'current' => $current, 'message' => '']; // already stored
        }
        if (hash_equals($loaded, md5($curN))) {
            return ['outcome' => 'write', 'current' => $current, 'message' => ''];
        }
        $label = isset($entry['label']) && is_string($entry['label']) && $entry['label'] !== '' ? $entry['label'] : rh_settings_log_label($key);
        $who = rh_settings_base_last_change($pdo, $key, $current);
        $message = $who !== null
            ? 'Not saved: ' . $label . ' was changed by ' . $who['name'] . ' at ' . $who['at'] . ' since you opened this page. Reload to see it.'
            : 'Not saved: ' . $label . ' was changed by someone else since you opened this page. Reload to see it.';
        return ['outcome' => 'conflict', 'current' => $current, 'message' => $message];
    }

    /**
     * A value that must stay paired with another one written in the same save (e.g. a Facebook Page ID and the pasted
     * token for that Page). $decision: rh_settings_base_decide() for $key; $paired: the value the pair needs stored.
     * null = fine (it is written now, or what is stored now already equals it); otherwise the plain refusal for the
     * whole save, naming who and when only from a real 'setting_change' row.
     */
    function rh_settings_base_pair_refusal(PDO $pdo, string $key, array $decision, string $paired): ?string
    {
        $outcome = (string)($decision['outcome'] ?? '');
        $current = isset($decision['current']) ? (string)$decision['current'] : null;
        if ($outcome === 'write' || ($outcome !== 'no_base' && $current !== null && rh_settings_base_norm($current) === rh_settings_base_norm($paired))) {
            return null;
        }
        $who = $current !== null ? rh_settings_base_last_change($pdo, $key, $current) : null;
        return 'Not saved: ' . rh_settings_log_label($key) . ' was changed by ' . ($who !== null ? $who['name'] . ' at ' . $who['at'] : 'someone else')
            . ' since you opened this page, so the pasted token may belong to another Page. Reload the page and try again. Nothing was saved.';
    }

    /**
     * Decide every key of one form without writing. $new: [key => value to write] (keys not posted are left out);
     * $spec: the form's spec (same as rendered); $raw: [key => posted text] for fields shown as stored; $opts:
     * 'labels' => [key => name]. Returns ['error' => '' or the whole-save refusal, 'write' => [key => value],
     * 'conflicts' => [plain messages], 'decisions' => [key => decision]]. Call inside a transaction.
     */
    function rh_settings_base_decide_all(PDO $pdo, ?array $base, array $new, array $spec, array $raw = [], array $opts = []): array
    {
        $out = ['error' => '', 'write' => [], 'conflicts' => [], 'decisions' => []];
        if ($base === null) {
            $out['error'] = rh_settings_base_reload_message();
            return $out;
        }
        $spec = rh_settings_base_spec($spec);
        foreach ($new as $key => $value) {
            $key = (string)$key;
            $entry = $spec[$key] ?? '';
            $o = is_array($entry) ? $entry : ['default' => $entry];
            if (isset($opts['labels'][$key])) {
                $o['label'] = (string)$opts['labels'][$key];
            }
            if (array_key_exists($key, $raw)) {
                $o['raw'] = $raw[$key];
            }
            $d = rh_settings_base_decide($pdo, $base, $key, $value, $o);
            $out['decisions'][$key] = $d;
            if ($d['outcome'] === 'no_base') {
                $out['error'] = $d['message'];
                $out['write'] = [];
                return $out;
            }
            if ($d['outcome'] === 'conflict') {
                $out['conflicts'][] = $d['message'];
            } elseif ($d['outcome'] === 'write') {
                $out['write'][$key] = $value;
            }
        }
        return $out;
    }

    /**
     * Decide a whole form and write only the changed keys, in one transaction (its own one, unless the caller already
     * opened one; then the caller rolls back on an error). $opts as rh_settings_base_decide_all(). Writes through
     * updateSetting() (false = failed: nothing of this save is kept). Never throws.
     * Returns ['error' => '' or plain text, 'written' => [key => value], 'conflicts' => [plain messages],
     *          'decisions' => [key => decision]].
     */
    function rh_settings_base_save(PDO $pdo, ?array $base, array $new, array $spec, array $raw = [], array $opts = []): array
    {
        $res = ['error' => '', 'written' => [], 'conflicts' => [], 'decisions' => []];
        if ($base === null) {
            $res['error'] = rh_settings_base_reload_message();
            return $res;
        }
        $own = !$pdo->inTransaction();
        try {
            if ($own) {
                $pdo->beginTransaction();
            }
            $d = rh_settings_base_decide_all($pdo, $base, $new, $spec, $raw, $opts);
            $res['decisions'] = $d['decisions'];
            if ($d['error'] !== '') {
                if ($own) {
                    $pdo->rollBack();
                }
                $res['error'] = $d['error'];
                return $res;
            }
            foreach ($d['write'] as $k => $v) {
                if (updateSetting((string)$k, $v) === false) {
                    throw new RuntimeException('Could not save ' . rh_settings_log_label((string)$k) . '.');
                }
                $res['written'][$k] = $v;
            }
            if ($own) {
                $pdo->commit();
            }
            $res['conflicts'] = $d['conflicts'];
        } catch (Throwable $e) {
            if ($own && $pdo->inTransaction()) {
                $pdo->rollBack();
            }
            error_log('settings base save: ' . $e->getMessage());
            $res['written'] = [];
            $res['conflicts'] = [];
            $res['error'] = $e instanceof RuntimeException && strpos($e->getMessage(), 'Could not save') === 0
                ? $e->getMessage() . ' Nothing was saved.' : 'The settings could not be saved. Nothing was saved.';
        }
        return $res;
    }
}
