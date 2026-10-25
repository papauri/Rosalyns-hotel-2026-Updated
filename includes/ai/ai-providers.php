<?php

/**
 * Assistant providers - Phase 1 subset: list a provider's models and send a 5-token "Reply OK"
 * test call. Raw PHP cURL (no Composer packages). The chat adapters come in Phase 2.
 *
 * Safety:
 *  - The key travels only in a request header (never in a URL), is never logged, echoed or returned.
 *    Every message that leaves this file goes through rh_ai_scrub(), which removes the key and
 *    anything that looks like a key, so a provider error that repeats part of it is cleaned too.
 *  - The HTTP layer is injectable (rh_ai_set_transport) so tests run with a fake: no network.
 */

require_once __DIR__ . '/ai-core.php';

/** The five supported providers and what the settings page says about each. */
function rh_ai_provider_list(): array
{
    return [
        'anthropic' => ['label' => 'Anthropic', 'product' => 'Claude', 'key_hint' => 'Starts with sk-ant-. Create one at console.anthropic.com > API keys.', 'note' => ''],
        'openai'    => ['label' => 'OpenAI', 'product' => 'GPT', 'key_hint' => 'Starts with sk-. Create one at platform.openai.com > API keys.', 'note' => ''],
        'google'    => ['label' => 'Google', 'product' => 'Gemini', 'key_hint' => 'Create one at aistudio.google.com > Get API key.', 'note' => ''],
        'xai'       => ['label' => 'xAI', 'product' => 'Grok', 'key_hint' => 'Starts with xai-. Create one at console.x.ai.', 'note' => ''],
        'deepseek'  => ['label' => 'DeepSeek', 'product' => 'DeepSeek', 'key_hint' => 'Starts with sk-. Create one at platform.deepseek.com.', 'note' => 'DeepSeek is hosted in China. Questions sent to it are processed there.'],
    ];
}

function rh_ai_is_provider(string $p): bool
{
    return isset(rh_ai_provider_list()[$p]);
}

// ── HTTP layer (injectable) ───────────────────────────────────────────────────

/**
 * Replace the HTTP transport (tests). The callable receives
 * (string $method, string $url, array $headers, ?string $body, int $timeout) and returns
 * ['http' => int, 'body' => string, 'error' => string]. Pass null to restore cURL.
 */
function rh_ai_set_transport(?callable $fn): void
{
    $GLOBALS['__rh_ai_transport'] = $fn;
}

/**
 * One HTTP request. @return array{http:int,body:string,error:string,ms:int}
 * $headers is a list of "Name: value" lines.
 */
function rh_ai_http(string $method, string $url, array $headers, ?string $body = null, int $timeout = 20): array
{
    $t0 = microtime(true);
    $fake = $GLOBALS['__rh_ai_transport'] ?? null;
    if (is_callable($fake)) {
        $r = (array)$fake($method, $url, $headers, $body, $timeout);
        return ['http' => (int)($r['http'] ?? 0), 'body' => (string)($r['body'] ?? ''), 'error' => (string)($r['error'] ?? ''), 'ms' => (int)round((microtime(true) - $t0) * 1000)];
    }
    if (!function_exists('curl_init')) {
        return ['http' => 0, 'body' => '', 'error' => 'This server cannot make web requests (PHP cURL is missing).', 'ms' => 0];
    }
    if (!function_exists('facebookCaBundle')) {
        require_once dirname(__DIR__) . '/facebook-functions.php';
    }
    $ch = curl_init($url);
    $opts = [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_CUSTOMREQUEST  => $method,
        CURLOPT_HTTPHEADER     => $headers,
        CURLOPT_CONNECTTIMEOUT => 8,
        CURLOPT_TIMEOUT        => max(5, $timeout),
        CURLOPT_SSL_VERIFYPEER => true,
        CURLOPT_SSL_VERIFYHOST => 2,
        CURLOPT_FOLLOWLOCATION => false,
        CURLOPT_USERAGENT      => 'HotelAdmin-Assistant/1.0',
    ];
    $ca = facebookCaBundle();
    if ($ca !== '') {
        $opts[CURLOPT_CAINFO] = $ca;
    }
    if ($body !== null) {
        $opts[CURLOPT_POSTFIELDS] = $body;
    }
    curl_setopt_array($ch, $opts);
    $resp = curl_exec($ch);
    $http = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    $err  = $resp === false ? (string)curl_error($ch) : '';
    curl_close($ch);
    return ['http' => $http, 'body' => $resp === false ? '' : (string)$resp, 'error' => $err, 'ms' => (int)round((microtime(true) - $t0) * 1000)];
}

/** Remove the key, and anything shaped like a key, from text that may be shown or stored. */
function rh_ai_scrub(string $text, string $key = ''): string
{
    if ($key !== '') {
        $text = str_replace($key, '[key]', $text);
        if (strlen($key) > 12) {
            $text = str_replace([substr($key, 0, 12), substr($key, -8)], '[key]', $text);
        }
    }
    // sk-..., sk-ant-..., xai-..., AIza... and long opaque tokens
    $text = (string)preg_replace('/\b(sk-[A-Za-z0-9_\-*]{6,}|xai-[A-Za-z0-9_\-*]{6,}|AIza[0-9A-Za-z_\-]{10,}|[A-Za-z0-9_\-]{40,})/', '[key]', $text);
    return mb_substr(trim($text), 0, 240);
}

/** Request settings per provider (base URL and auth headers). Key stays in headers only. */
function rh_ai_provider_request(string $provider, string $key): array
{
    switch ($provider) {
        case 'anthropic':
            return ['base' => 'https://api.anthropic.com/v1', 'headers' => ['x-api-key: ' . $key, 'anthropic-version: 2023-06-01', 'Content-Type: application/json']];
        case 'openai':
            return ['base' => 'https://api.openai.com/v1', 'headers' => ['Authorization: Bearer ' . $key, 'Content-Type: application/json']];
        case 'google':
            return ['base' => 'https://generativelanguage.googleapis.com/v1beta', 'headers' => ['x-goog-api-key: ' . $key, 'Content-Type: application/json']];
        case 'xai':
            return ['base' => 'https://api.x.ai/v1', 'headers' => ['Authorization: Bearer ' . $key, 'Content-Type: application/json']];
        case 'deepseek':
            return ['base' => 'https://api.deepseek.com', 'headers' => ['Authorization: Bearer ' . $key, 'Content-Type: application/json']];
    }
    throw new InvalidArgumentException('Unknown provider.');
}

/** Plain sentence for an HTTP failure. */
function rh_ai_http_problem(array $r, string $key): string
{
    if ($r['http'] === 0) {
        return 'Could not reach the provider' . ($r['error'] !== '' ? ' (' . rh_ai_scrub($r['error'], $key) . ')' : '') . '. Check the server has internet access.';
    }
    $msg = '';
    $j = json_decode($r['body'], true);
    if (is_array($j)) {
        $e = $j['error'] ?? null;
        if (is_array($e)) {
            $msg = (string)($e['message'] ?? '');
        } elseif (is_string($e)) {
            $msg = $e;
        } elseif (isset($j['message']) && is_string($j['message'])) {
            $msg = $j['message'];
        }
    }
    $lead = [
        400 => 'The provider refused the request',
        401 => 'The key was refused - check it was copied in full',
        403 => 'This key is not allowed to do that',
        404 => 'The provider did not recognise the request',
        429 => 'The provider says the limit or credit for this key is used up',
    ][$r['http']] ?? 'The provider answered with an error';
    return $lead . ' (HTTP ' . $r['http'] . ')' . ($msg !== '' ? ': ' . rh_ai_scrub($msg, $key) : '.');
}

/** Ids of models that are not for chat (embeddings, speech, images, moderation, realtime...). */
function rh_ai_is_chat_model(string $id): bool
{
    return !preg_match('/(embed|tts|whisper|dall-e|gpt-image|imagen|image|audio|moderation|realtime|transcribe|speech|veo|\baqa\b|babbage|davinci|sora)/i', $id);
}

/**
 * Normalise a provider's model-list JSON. Returns a list of
 * ['model_id','label','created' (Y-m-d|null),'context_tokens' (int|null),'max_output' (int|null)],
 * chat models only.
 */
function rh_ai_normalise_models(string $provider, array $json): array
{
    $out = [];
    $day = static function ($v): ?string {
        if ($v === null || $v === '') {
            return null;
        }
        $ts = is_numeric($v) ? (int)$v : strtotime((string)$v);
        return $ts ? gmdate('Y-m-d', $ts) : null;
    };
    if ($provider === 'google') {
        foreach ((array)($json['models'] ?? []) as $m) {
            if (!is_array($m)) {
                continue;
            }
            $methods = (array)($m['supportedGenerationMethods'] ?? []);
            if (!in_array('generateContent', $methods, true)) {
                continue;
            }
            $id = preg_replace('#^models/#', '', (string)($m['name'] ?? ''));
            if ($id === '' || !rh_ai_is_chat_model($id)) {
                continue;
            }
            $out[] = [
                'model_id'       => $id,
                'label'          => (string)($m['displayName'] ?? $id),
                'created'        => null,
                'context_tokens' => isset($m['inputTokenLimit']) ? (int)$m['inputTokenLimit'] : null,
                'max_output'     => isset($m['outputTokenLimit']) ? (int)$m['outputTokenLimit'] : null,
            ];
        }
        return $out;
    }
    foreach ((array)($json['data'] ?? []) as $m) {
        if (!is_array($m)) {
            continue;
        }
        $id = (string)($m['id'] ?? '');
        if ($id === '' || !rh_ai_is_chat_model($id)) {
            continue;
        }
        if ($provider === 'anthropic') {
            $out[] = [
                'model_id'       => $id,
                'label'          => (string)($m['display_name'] ?? $id),
                'created'        => $day($m['created_at'] ?? null),
                'context_tokens' => isset($m['max_input_tokens']) ? (int)$m['max_input_tokens'] : null,
                'max_output'     => isset($m['max_tokens']) ? (int)$m['max_tokens'] : null,
            ];
        } else {
            $out[] = [
                'model_id'       => $id,
                'label'          => $id,
                'created'        => $day($m['created'] ?? null),
                'context_tokens' => isset($m['context_length']) ? (int)$m['context_length'] : null,
                'max_output'     => null,
            ];
        }
    }
    return $out;
}

/**
 * List a provider's chat models with the given key.
 * @return array{ok:bool,models:array,message:string,ms:int}
 */
function rh_ai_list_models(string $provider, string $key): array
{
    if (!rh_ai_is_provider($provider)) {
        return ['ok' => false, 'models' => [], 'message' => 'Unknown provider.', 'ms' => 0];
    }
    if ($key === '') {
        return ['ok' => false, 'models' => [], 'message' => 'No key saved for this provider.', 'ms' => 0];
    }
    $req = rh_ai_provider_request($provider, $key);
    $models = [];
    $ms = 0;
    $cursor = '';
    for ($page = 0; $page < 5; $page++) {
        switch ($provider) {
            case 'anthropic':
                $url = $req['base'] . '/models?limit=1000' . ($cursor !== '' ? '&after_id=' . rawurlencode($cursor) : '');
                break;
            case 'google':
                $url = $req['base'] . '/models?pageSize=1000' . ($cursor !== '' ? '&pageToken=' . rawurlencode($cursor) : '');
                break;
            default:
                $url = $req['base'] . '/models';
        }
        $r = rh_ai_http('GET', $url, $req['headers'], null, 20);
        $ms += $r['ms'];
        if ($r['http'] < 200 || $r['http'] >= 300) {
            return ['ok' => false, 'models' => [], 'message' => rh_ai_http_problem($r, $key), 'ms' => $ms];
        }
        $json = json_decode($r['body'], true);
        if (!is_array($json)) {
            return ['ok' => false, 'models' => [], 'message' => 'The provider sent an answer that could not be read.', 'ms' => $ms];
        }
        $models = array_merge($models, rh_ai_normalise_models($provider, $json));
        $cursor = '';
        if ($provider === 'anthropic' && !empty($json['has_more']) && !empty($json['last_id'])) {
            $cursor = (string)$json['last_id'];
        } elseif ($provider === 'google' && !empty($json['nextPageToken'])) {
            $cursor = (string)$json['nextPageToken'];
        }
        if ($cursor === '') {
            break;
        }
    }
    // One entry per id
    $seen = [];
    $models = array_values(array_filter($models, static function ($m) use (&$seen) {
        if (isset($seen[$m['model_id']])) {
            return false;
        }
        return $seen[$m['model_id']] = true;
    }));
    return ['ok' => true, 'models' => $models, 'message' => '', 'ms' => $ms];
}

/**
 * Send "Reply OK" with a 5-token limit to one model.
 * @return array{ok:bool,ms:int,message:string,reply:string}
 */
function rh_ai_ping(string $provider, string $key, string $modelId): array
{
    $req = rh_ai_provider_request($provider, $key);
    $prompt = 'Reply OK';
    switch ($provider) {
        case 'anthropic':
            $url  = $req['base'] . '/messages';
            $body = ['model' => $modelId, 'max_tokens' => 5, 'messages' => [['role' => 'user', 'content' => $prompt]]];
            break;
        case 'google':
            $url  = $req['base'] . '/models/' . rawurlencode($modelId) . ':generateContent';
            $body = ['contents' => [['role' => 'user', 'parts' => [['text' => $prompt]]]], 'generationConfig' => ['maxOutputTokens' => 5]];
            break;
        case 'openai':
            $url  = $req['base'] . '/chat/completions';
            $body = ['model' => $modelId, 'max_completion_tokens' => 5, 'messages' => [['role' => 'user', 'content' => $prompt]]];
            break;
        default: // xai, deepseek (OpenAI-style chat completions)
            $url  = $req['base'] . '/chat/completions';
            $body = ['model' => $modelId, 'max_tokens' => 5, 'messages' => [['role' => 'user', 'content' => $prompt]]];
    }
    $r = rh_ai_http('POST', $url, $req['headers'], json_encode($body), 30);
    if ($r['http'] < 200 || $r['http'] >= 300) {
        return ['ok' => false, 'ms' => $r['ms'], 'message' => rh_ai_http_problem($r, $key), 'reply' => ''];
    }
    $j = json_decode($r['body'], true);
    $reply = '';
    if (is_array($j)) {
        if ($provider === 'anthropic') {
            $reply = (string)($j['content'][0]['text'] ?? '');
        } elseif ($provider === 'google') {
            $reply = (string)($j['candidates'][0]['content']['parts'][0]['text'] ?? '');
        } else {
            $reply = (string)($j['choices'][0]['message']['content'] ?? '');
        }
    }
    return ['ok' => true, 'ms' => $r['ms'], 'message' => '', 'reply' => rh_ai_scrub($reply, $key)];
}
