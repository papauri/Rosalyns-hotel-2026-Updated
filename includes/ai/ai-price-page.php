<?php

/**
 * Model prices read from the provider's own price page (owner 2026-11-20: "should be able to scrape automatically").
 * Google only; other providers keep the built-in list (includes/ai/model-catalog.json).
 *
 *  - Read during the daily model-list refresh (first admin page load of the day, never cron) and on "Check for newer
 *    models", through rh_ai_http (so the kill switch and NO_AI_SPEND block it like any provider call). No key is sent.
 *  - The standard paid-tier input and output price per model id, the lowest prompt tier (the cheaper "prompts up to
 *    200k" line, text rather than audio). A dated price ("until 30 Nov 2026, then ...") is kept with its dates, so the
 *    price in effect is worked out on the day it is used, even when the page cannot be read that day.
 *  - Saved in site_settings (ai_price_page_<provider>: when read + the prices per id). rh_ai_sync_models lays these
 *    over the list it saves (owner 2026-11-21: prices are never typed; a page price replaces any old typed one).
 *  - The page cannot be read or no price is found: the last prices stay, one notice for the Assistant Settings people,
 *    and the page never breaks.
 */

require_once __DIR__ . '/ai-providers.php';

/**
 * The price source read automatically, per provider. Google: its own price page (it also carries dated prices).
 * Every other provider (owner 2026-11-21: "no need for us to set AI prices, you do it automatically"): the public
 * price list kept by the open-source LiteLLM project, keyed by the provider's exact model ids (its Google figures
 * matched Google's own page when this was added). Read the same way: daily, through rh_ai_http, no key sent.
 */
function rh_ai_price_page_url(string $provider): string
{
    if ($provider === 'google') {
        return 'https://ai.google.dev/gemini-api/docs/pricing';
    }
    return isset(rh_ai_price_list_providers()[$provider]) ? rh_ai_price_list_url() : '';
}

/** The public model price list (LiteLLM, JSON, about 5 MB). */
function rh_ai_price_list_url(): string
{
    return 'https://raw.githubusercontent.com/BerriAI/litellm/main/model_prices_and_context_window.json';
}

/**
 * Where each provider's models sit in the public price list: [list provider name, id prefix]. Google is here too: after
 * a good read of its own page, the list fills only the ids the page does not show (the page always wins).
 */
function rh_ai_price_list_providers(): array
{
    return [
        'anthropic' => ['anthropic', ''],
        'openai'    => ['openai', ''],
        'google'    => ['gemini', 'gemini/'],
        'xai'       => ['xai', 'xai/'],
        'deepseek'  => ['deepseek', 'deepseek/'],
    ];
}

/** Plain name of a provider's automatic price source, for the page and notices. */
function rh_ai_price_source_label(string $provider): string
{
    return $provider === 'google' ? 'Google\'s price page (or the public model price list for a model the page does not show)' : 'the public model price list';
}

/**
 * Read the public price list (JSON) for one provider, in the same shape as rh_ai_price_page_parse (one undated price
 * window each). Only chat models with both prices; US$ per token turned into US$ per million, 4 decimals.
 * @return array<string,array{in:list<array>,out:list<array>,label:string}>
 */
function rh_ai_price_list_parse(string $json, string $provider): array
{
    $map = rh_ai_price_list_providers()[$provider] ?? null;
    $j = $map ? json_decode($json, true) : null;
    if (!is_array($j)) {
        return [];
    }
    [$listName, $prefix] = $map;
    $out = [];
    foreach ($j as $key => $v) {
        if (!is_array($v) || (string)($v['litellm_provider'] ?? '') !== $listName) {
            continue;
        }
        $mode = (string)($v['mode'] ?? 'chat');
        if ($mode !== 'chat' && $mode !== 'responses') {
            continue;
        }
        $id = strtolower((string)$key);
        if ($prefix !== '' && strpos($id, $prefix) === 0) {
            $id = substr($id, strlen($prefix));
        }
        if ($id === '' || strpos($id, '/') !== false || !is_numeric($v['input_cost_per_token'] ?? null) || !is_numeric($v['output_cost_per_token'] ?? null)) {
            continue;
        }
        $in = round((float)$v['input_cost_per_token'] * 1000000, 4);
        $o = round((float)$v['output_cost_per_token'] * 1000000, 4);
        // US$0 both ways (a free tier, e.g. Gemma) is not a paid price: left unknown so cost is never under-counted.
        if ($in < 0 || $o < 0 || $in > 1000 || $o > 1000 || ($in == 0 && $o == 0)) {
            continue;
        }
        $out[$id] = ['in' => [[$in, null, null]], 'out' => [[$o, null, null]], 'label' => ''];
    }
    return $out;
}

/** Plain text of a bit of HTML: line breaks kept, tags dropped, entities decoded, spaces tidied. */
function rh_ai_price_page_text(string $html): string
{
    $s = (string)preg_replace('#<br\s*/?>|</p>|</li>|</div>#i', "\n", $html);
    $s = html_entity_decode(strip_tags($s), ENT_QUOTES | ENT_HTML5, 'UTF-8');
    $s = str_replace("\xC2\xA0", ' ', $s);
    $s = (string)preg_replace('/[ \t\r]+/', ' ', $s);
    $s = (string)preg_replace('/ *\n[ \n]*/', "\n", $s);
    return trim($s);
}

/** A date phrase ("30 Nov 2026", "November 30, 2026", "2026-11-30") as Y-m-d, or null. */
function rh_ai_price_page_date(string $s): ?string
{
    $s = trim(str_replace('.', '', $s));
    $ts = strtotime($s);
    return $ts ? date('Y-m-d', $ts) : null;
}

/**
 * The prices in one table cell with the dates they apply to.
 * "$2.00 until 30 Nov 2026, then $4.00" -> [[2.0, null, '2026-11-30'], [4.0, '2026-12-01', null]]
 * @return list<array{0:float,1:?string,2:?string}> amount, valid from, valid until (Y-m-d, inclusive)
 */
function rh_ai_price_page_amounts(string $cellText): array
{
    $date = '(\d{1,2}\s+[A-Za-z]{3,9}\.?,?\s+\d{4}|[A-Za-z]{3,9}\.?\s+\d{1,2},?\s+\d{4}|\d{4}-\d{2}-\d{2})';
    $out = [];
    foreach (preg_split('/\n/', $cellText) ?: [] as $line) {
        if (!preg_match_all('/\$\s?(\d+(?:\.\d+)?)/', $line, $am, PREG_OFFSET_CAPTURE)) {
            continue;
        }
        preg_match_all('/\b(until|through|thru|till|to|before|ends?|ending|from|starting|starts?|effective|after|as of|beginning|begins?)\s+(?:on\s+)?' . $date . '/i', $line, $qm, PREG_OFFSET_CAPTURE | PREG_SET_ORDER);
        $quals = [];
        foreach ($qm as $q) {
            $d = rh_ai_price_page_date($q[2][0]);
            if ($d === null) {
                continue;
            }
            $kw = strtolower($q[1][0]);
            if (in_array($kw, ['before'], true)) {
                $quals[] = ['pos' => $q[0][1], 'until' => date('Y-m-d', strtotime($d . ' -1 day')), 'from' => null];
            } elseif (in_array($kw, ['after'], true)) {
                $quals[] = ['pos' => $q[0][1], 'from' => date('Y-m-d', strtotime($d . ' +1 day')), 'until' => null];
            } elseif (preg_match('/^(until|through|thru|till|to|ends?|ending)$/', $kw)) {
                $quals[] = ['pos' => $q[0][1], 'until' => $d, 'from' => null];
            } else {
                $quals[] = ['pos' => $q[0][1], 'from' => $d, 'until' => null];
            }
        }
        $amts = $am[1];
        $n = count($amts);
        $win = array_fill(0, $n, ['from' => null, 'until' => null]);
        $used = [];
        // A date right after a price belongs to that price ("$2.00 until 30 Nov 2026").
        for ($i = 0; $i < $n; $i++) {
            $start = $amts[$i][1];
            $end = $i + 1 < $n ? $amts[$i + 1][1] : PHP_INT_MAX;
            foreach ($quals as $k => $q) {
                if ($q['pos'] > $start && $q['pos'] < $end) {
                    $win[$i] = ['from' => $q['from'], 'until' => $q['until']];
                    $used[$k] = true;
                    break;
                }
            }
        }
        // A date in front of the first price on the line, not taken by an earlier one ("From 1 Dec 2026: $4.00").
        if ($win[0]['from'] === null && $win[0]['until'] === null) {
            foreach ($quals as $k => $q) {
                if (empty($used[$k]) && $q['pos'] < $amts[0][1]) {
                    $win[0] = ['from' => $q['from'], 'until' => $q['until']];
                }
            }
        }
        // "..., then $4.00": the price after one that ends on a date starts the next day.
        for ($i = 1; $i < $n; $i++) {
            if ($win[$i]['from'] === null && $win[$i]['until'] === null && $win[$i - 1]['until'] !== null) {
                $win[$i]['from'] = date('Y-m-d', strtotime($win[$i - 1]['until'] . ' +1 day'));
            }
        }
        for ($i = 0; $i < $n; $i++) {
            $v = (float)$amts[$i][0];
            if ($v >= 0 && $v <= 1000) {
                $out[] = [$v, $win[$i]['from'], $win[$i]['until']];
            }
        }
    }
    return $out;
}

/**
 * Read the price page HTML.
 * @return array<string,array{in:list<array>,out:list<array>,label:string}> model id => price windows (see rh_ai_price_page_amounts)
 */
function rh_ai_price_page_parse(string $html): array
{
    $out = [];
    // One model per section: from one <h2> to the next. The ids are in <code> before the first price table; the first
    // table with an "Input price" row is the standard tier (Batch and other tiers come after it).
    foreach (preg_split('/<h2[\s>]/i', $html) ?: [] as $i => $sec) {
        if ($i === 0) {
            continue;
        }
        $label = preg_match('#^[^>]*>(.*?)</h2>#is', $sec, $lm) ? rh_ai_price_page_text($lm[1]) : '';
        $tPos = stripos($sec, '<table');
        if ($tPos === false) {
            continue;
        }
        preg_match_all('#<code[^>]*>\s*([a-z][a-z0-9.\-]*\d[a-z0-9.\-]*)\s*</code>#i', substr($sec, 0, $tPos), $cm);
        $ids = array_values(array_unique(array_map('strtolower', $cm[1] ?? [])));
        if (!$ids) {
            continue;
        }
        if (!preg_match_all('#<table\b.*?</table>#is', $sec, $tm)) {
            continue;
        }
        foreach ($tm[0] as $table) {
            if (!preg_match_all('#<tr\b.*?</tr>#is', $table, $rm)) {
                continue;
            }
            $paidCol = -1;
            $in = null;
            $outP = null;
            foreach ($rm[0] as $tr) {
                preg_match_all('#<t[dh]\b[^>]*>(.*?)</t[dh]>#is', $tr, $cells);
                $cells = array_map('rh_ai_price_page_text', $cells[1] ?? []);
                if (!$cells) {
                    continue;
                }
                if ($paidCol < 0) {
                    foreach ($cells as $ci => $c) {
                        if (stripos($c, 'paid') !== false) {
                            $paidCol = $ci;
                        }
                    }
                }
                $head = strtolower($cells[0]);
                $cell = $cells[$paidCol >= 0 && isset($cells[$paidCol]) ? $paidCol : count($cells) - 1];
                if ($in === null && strpos($head, 'input price') === 0) {
                    $in = rh_ai_price_page_amounts($cell);
                } elseif ($outP === null && strpos($head, 'output price') === 0) {
                    $outP = rh_ai_price_page_amounts($cell);
                }
            }
            if ($in && $outP) {
                foreach ($ids as $id) {
                    $out[$id] = ['in' => $in, 'out' => $outP, 'label' => $label];
                }
                break;
            }
        }
    }
    return $out;
}

/** The lowest price in effect on $day among dated windows, or null when none applies. */
function rh_ai_price_page_resolve(array $windows, string $day): ?float
{
    $best = null;
    foreach ($windows as $w) {
        if (!is_array($w) || !isset($w[0])) {
            continue;
        }
        $from = $w[1] ?? null;
        $until = $w[2] ?? null;
        if (($from !== null && $day < $from) || ($until !== null && $day > $until)) {
            continue;
        }
        $best = $best === null ? (float)$w[0] : min($best, (float)$w[0]);
    }
    return $best;
}

/** The saved page read: ['at' => 'Y-m-d H:i:s', 'ids' => [...]] or an empty read. */
function rh_ai_price_page_saved(?PDO $db, string $provider): array
{
    $raw = '';
    if ($db) {
        try {
            $st = $db->prepare("SELECT setting_value FROM site_settings WHERE setting_key = ?");
            $st->execute(['ai_price_page_' . $provider]);
            $raw = (string)$st->fetchColumn();
        } catch (Throwable $e) {
            $raw = '';
        }
    }
    $j = $raw !== '' ? json_decode($raw, true) : null;
    return is_array($j) && isset($j['ids']) && is_array($j['ids']) ? ['at' => (string)($j['at'] ?? ''), 'ids' => $j['ids']] : ['at' => '', 'ids' => []];
}

/**
 * Prices from the saved page read, in effect on $day (today by default).
 * @return array<string,array{0:float,1:float}> model id => [in, out]
 */
function rh_ai_price_page_prices(?PDO $db, string $provider, ?string $day = null): array
{
    $day = $day ?? date('Y-m-d');
    $out = [];
    foreach (rh_ai_price_page_saved($db, $provider)['ids'] as $id => $p) {
        $in = rh_ai_price_page_resolve((array)($p['in'] ?? []), $day);
        $o = rh_ai_price_page_resolve((array)($p['out'] ?? []), $day);
        if ($in !== null && $o !== null) {
            $out[(string)$id] = [$in, $o];
        }
    }
    return $out;
}

/** The page price for one model id (exact id, else the id without a date stamp). */
function rh_ai_price_page_for(array $prices, string $id): ?array
{
    $id = strtolower($id);
    return $prices[$id] ?? (function_exists('rh_ai_model_base_id') ? ($prices[rh_ai_model_base_id($id)] ?? null) : null);
}

/**
 * Read the provider's price page now and save what was found. Never throws.
 * @return array{ok:bool,count:int,message:string}
 */
function rh_ai_price_page_refresh(PDO $db, string $provider): array
{
    $url = rh_ai_price_page_url($provider);
    if ($url === '') {
        return ['ok' => false, 'count' => 0, 'message' => ''];
    }
    $label = rh_ai_provider_list()[$provider]['label'] ?? $provider;
    $found = [];
    $isPage = $provider === 'google';
    $fromList = 0;
    $ua = 'User-Agent: Mozilla/5.0 (compatible; HotelAssistantPrices/1.0)';
    try {
        if ($isPage) {
            $r = rh_ai_http('GET', $url, ['Accept: text/html', $ua], null, 10);
            if ($r['http'] === 200 && $r['body'] !== '' && strlen($r['body']) <= 6000000) {
                $found = rh_ai_price_page_parse($r['body']);
            }
        }
        // The public list: the source for the other providers; for Google only after a good page read (a failed page
        // read keeps the last saved page prices with their dates, never a list-only set).
        if (isset(rh_ai_price_list_providers()[$provider]) && (!$isPage || $found)) {
            $r = rh_ai_http('GET', rh_ai_price_list_url(), ['Accept: application/json', $ua], null, 20);
            if ($r['http'] === 200 && $r['body'] !== '' && strlen($r['body']) <= 15000000) { // about 5 MB
                foreach (rh_ai_price_list_parse($r['body'], $provider) as $id => $p) {
                    if (!isset($found[$id])) {
                        $found[$id] = $p;
                        $fromList++;
                    }
                }
            }
        }
    } catch (Throwable $e) {
        $found = $isPage ? $found : [];
    }
    if (!$found) {
        $saved = rh_ai_price_page_saved($db, $provider);
        $since = $saved['at'] !== '' ? ' (last read ' . date('j M Y', (int)strtotime($saved['at'])) . ')' : '';
        $msg = $isPage
            ? $label . '\'s price page could not be read today, so the last known prices were kept' . $since . '.'
            : 'The ' . $label . ' prices could not be read today from the public model price list, so the last known prices were kept' . $since . '.';
        if (function_exists('rh_ai_notify_settings')) {
            rh_ai_notify_settings($db, 'price_page', $msg . ' Prices are filled in automatically again once it can be read.');
        }
        return ['ok' => false, 'count' => 0, 'message' => $msg];
    }
    $at = date('Y-m-d H:i:s');
    try {
        rh_ai_save_settings(['ai_price_page_' . $provider => json_encode(['at' => $at, 'ids' => $found], JSON_UNESCAPED_SLASHES)], $db);
    } catch (Throwable $e) {
        error_log('Assistant: could not save the price page read: ' . $e->getMessage());
    }
    $n = count($found);
    return ['ok' => true, 'count' => $n, 'message' => 'Prices read from ' . ($isPage ? $label . '\'s price page' : 'the public model price list') . ' on ' . date('j M Y') . ' (' . $n . ' model id' . ($n === 1 ? '' : 's')
        . ($isPage && $fromList > 0 ? ', ' . $fromList . ' of them from the public model price list' : '') . ').'];
}
