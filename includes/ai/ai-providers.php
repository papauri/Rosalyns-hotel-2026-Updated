<?php

/**
 * Assistant providers: list a provider's models, send a 5-token "Reply OK" test call, and the chat
 * adapters (rh_ai_chat): Anthropic Messages, one OpenAI-compatible adapter for OpenAI / xAI / DeepSeek
 * (Chat Completions, Responses API when a model refuses Chat Completions) and Google Gemini - all
 * normalised to one shape. Raw PHP cURL (no Composer packages).
 *
 * Anthropic rules (2026-10): never send temperature / top_p / top_k; never send thinking "disabled"
 * or budget_tokens; tool_choice is "auto" only; effort goes in output_config.effort; stop_reason is
 * checked (refusal, max_tokens) before content is read; prompt caching via cache_control on the
 * frozen system block and the last tool (tools are sorted, so the cached prefix is stable).
 *
 * Test seam: RH_AI_FAKE_PROVIDER (constant, or the environment variable on the command line only)
 * names a script in scripts/tests/fixtures/ai/ - every request is answered from it and nothing
 * leaves the machine.
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

/**
 * Kill switch layer 4 "Cloud providers" (Phase 4b). All five supported providers are cloud services (there is
 * no on-site model yet), so off means no model call leaves the hotel: only the quick local answers work.
 */
function rh_ai_cloud_on(): bool
{
    return rh_ai_setting('ai_cloud_enabled', '1') !== '0';
}

function rh_ai_cloud_off_message(): string
{
    return 'Cloud providers are switched off in Assistant Settings, so only quick local answers work.';
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
    if (!rh_ai_cloud_on()) { // kill switch layer 4: nothing is sent to a cloud provider
        return ['http' => 0, 'body' => '', 'error' => rh_ai_cloud_off_message(), 'ms' => 0];
    }
    $t0 = microtime(true);
    $fake = $GLOBALS['__rh_ai_transport'] ?? null;
    if (is_callable($fake)) {
        $r = (array)$fake($method, $url, $headers, $body, $timeout);
        return ['http' => (int)($r['http'] ?? 0), 'body' => (string)($r['body'] ?? ''), 'error' => (string)($r['error'] ?? ''), 'ms' => (int)round((microtime(true) - $t0) * 1000)];
    }
    if (rh_ai_fake_name() !== '') {
        $r = rh_ai_fake_transport($method, $url, $headers, $body);
        return $r + ['ms' => (int)round((microtime(true) - $t0) * 1000)];
    }
    if (!function_exists('curl_init')) {
        return ['http' => 0, 'body' => '', 'error' => 'This server cannot make web requests (PHP cURL is missing).', 'ms' => 0];
    }
    $ch = curl_init($url);
    curl_setopt_array($ch, rh_ai_curl_opts($method, $headers, $body, $timeout));
    $resp = curl_exec($ch);
    $http = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    $err  = $resp === false ? (string)curl_error($ch) : '';
    curl_close($ch);
    return ['http' => $http, 'body' => $resp === false ? '' : (string)$resp, 'error' => $err, 'ms' => (int)round((microtime(true) - $t0) * 1000)];
}

/** cURL options for one provider request (shared by the single and the parallel call). */
function rh_ai_curl_opts(string $method, array $headers, ?string $body, int $timeout): array
{
    if (!function_exists('facebookCaBundle')) {
        require_once dirname(__DIR__) . '/facebook-functions.php';
    }
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
    return $opts;
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

/** Ids of models that are not for chat (embeddings, speech, images, music, moderation, realtime/Live, robotics...). */
function rh_ai_is_chat_model(string $id): bool
{
    return !preg_match('/(embed|tts|whisper|dall-e|gpt-image|imagen|image|audio|moderation|realtime|transcribe|speech|veo|\baqa\b|babbage|davinci|sora|\blive\b|robotics|lyria|nano-banana|customtools|\bomni\b)/i', $id);
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

// ══ Chat (Phase 2) ═════════════════════════════════════════════════════════════

/** Which adapter a provider uses. */
function rh_ai_adapter(string $provider): string
{
    if ($provider === 'anthropic') {
        return 'anthropic';
    }
    if ($provider === 'google') {
        return 'gemini';
    }
    return 'openai_compatible'; // openai, xai, deepseek
}

/** Name of the fake-provider script in use ('' = real providers). The env form works on the command line only. */
function rh_ai_fake_name(): string
{
    $n = '';
    if (defined('RH_AI_FAKE_PROVIDER')) {
        $n = (string)constant('RH_AI_FAKE_PROVIDER');
    } elseif (PHP_SAPI === 'cli') {
        $e = getenv('RH_AI_FAKE_PROVIDER');
        $n = is_string($e) ? $e : '';
    }
    return (string)preg_replace('/[^a-z0-9_\-]/i', '', $n);
}

/** State file holding the fake provider's position in its script. */
function rh_ai_fake_state_file(): string
{
    $cap = getenv('RH_AI_FAKE_CAPTURE');
    return sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'rh_ai_fake_' . md5(rh_ai_fake_name() . '|' . (is_string($cap) ? $cap : '')) . '.pos';
}

/**
 * The fake provider: answers from scripts/tests/fixtures/ai/<name>.json ({"replies":[normalised...]}),
 * one reply per call in order (the position survives across processes via a small state file),
 * shaped like the real provider's answer. Every request is appended to RH_AI_FAKE_CAPTURE (if set)
 * so tests can check exactly what would have left the hotel. Nothing goes on the network.
 */
function rh_ai_fake_transport(string $method, string $url, array $headers, ?string $body): array
{
    $name = rh_ai_fake_name();
    $cap = getenv('RH_AI_FAKE_CAPTURE');
    if (is_string($cap) && $cap !== '') {
        @file_put_contents($cap, json_encode(['method' => $method, 'url' => $url, 'headers' => $headers, 'body' => (string)$body]) . "\n", FILE_APPEND);
    }
    $provider = 'openai';
    foreach (['api.anthropic.com' => 'anthropic', 'generativelanguage.googleapis.com' => 'google', 'api.x.ai' => 'xai', 'api.deepseek.com' => 'deepseek'] as $host => $p) {
        if (strpos($url, $host) !== false) {
            $provider = $p;
        }
    }
    $file = dirname(__DIR__, 2) . '/scripts/tests/fixtures/ai/' . $name . '.json';
    $script = is_file($file) ? json_decode((string)file_get_contents($file), true) : null;
    $replies = is_array($script) ? (array)($script['replies'] ?? []) : [];
    $stateFile = rh_ai_fake_state_file();
    $pos = is_file($stateFile) ? (int)file_get_contents($stateFile) : 0;
    @file_put_contents($stateFile, (string)($pos + 1));
    $reply = $replies[$pos] ?? ['text' => 'OK'];
    if (!empty($reply['http']) && (int)$reply['http'] !== 200) {
        return ['http' => (int)$reply['http'], 'body' => json_encode(['error' => ['message' => (string)($reply['error'] ?? 'fake error')]]), 'error' => ''];
    }
    $req = json_decode((string)$body, true);
    $model = is_array($req) ? (string)($req['model'] ?? '') : '';
    return ['http' => 200, 'body' => json_encode(rh_ai_fake_body($provider, $reply, $model, strpos($url, '/responses') !== false)), 'error' => ''];
}

/** Start the fake provider's script from the top (tests). */
function rh_ai_fake_reset(): void
{
    @unlink(rh_ai_fake_state_file());
}

/** A normalised reply {text, tool_calls[{id,name,args}], stop, usage{in,out}} in a provider's own format. */
function rh_ai_fake_body(string $provider, array $r, string $model = '', bool $responsesApi = false): array
{
    $text = (string)($r['text'] ?? '');
    $calls = (array)($r['tool_calls'] ?? []);
    $in = (int)($r['usage']['in'] ?? 120);
    $out = (int)($r['usage']['out'] ?? 20);
    $stop = (string)($r['stop'] ?? ($calls ? 'tool' : 'end'));
    $adapter = rh_ai_adapter($provider);
    if ($adapter === 'anthropic') {
        $content = [];
        if ($text !== '') {
            $content[] = ['type' => 'text', 'text' => $text];
        }
        foreach ($calls as $i => $c) {
            $content[] = ['type' => 'tool_use', 'id' => (string)($c['id'] ?? ('toolu_' . $i)), 'name' => (string)$c['name'], 'input' => (object)($c['args'] ?? [])];
        }
        $sr = ['end' => 'end_turn', 'tool' => 'tool_use', 'max_tokens' => 'max_tokens', 'refusal' => 'refusal'][$stop] ?? 'end_turn';
        return ['id' => 'msg_fake', 'type' => 'message', 'role' => 'assistant', 'model' => $model, 'content' => $content, 'stop_reason' => $sr,
            'usage' => ['input_tokens' => $in, 'output_tokens' => $out, 'cache_read_input_tokens' => (int)($r['usage']['cache_read'] ?? 0), 'cache_creation_input_tokens' => 0]];
    }
    if ($adapter === 'gemini') {
        $parts = [];
        if ($text !== '') {
            $parts[] = ['text' => $text];
        }
        foreach ($calls as $c) {
            $fc = ['functionCall' => ['name' => (string)$c['name'], 'args' => (object)($c['args'] ?? [])]];
            if (!empty($c['sig'])) { // Gemini 3: the thought signature rides on the (first) functionCall part
                $fc['thoughtSignature'] = (string)$c['sig'];
            }
            $parts[] = $fc;
        }
        $fr = ['end' => 'STOP', 'tool' => 'STOP', 'max_tokens' => 'MAX_TOKENS', 'refusal' => 'SAFETY'][$stop] ?? 'STOP';
        return ['candidates' => [['content' => ['role' => 'model', 'parts' => $parts], 'finishReason' => $fr]], 'usageMetadata' => ['promptTokenCount' => $in, 'candidatesTokenCount' => $out]];
    }
    if ($responsesApi) {
        $output = [];
        if ($text !== '') {
            $output[] = ['type' => 'message', 'role' => 'assistant', 'content' => [['type' => 'output_text', 'text' => $text]]];
        }
        foreach ($calls as $i => $c) {
            $output[] = ['type' => 'function_call', 'call_id' => (string)($c['id'] ?? ('call_' . $i)), 'name' => (string)$c['name'], 'arguments' => json_encode((object)($c['args'] ?? []))];
        }
        return ['status' => $stop === 'max_tokens' ? 'incomplete' : 'completed', 'output' => $output, 'usage' => ['input_tokens' => $in, 'output_tokens' => $out]];
    }
    $msg = ['role' => 'assistant', 'content' => $text !== '' ? $text : null];
    if ($calls) {
        $msg['tool_calls'] = [];
        foreach ($calls as $i => $c) {
            $msg['tool_calls'][] = ['id' => (string)($c['id'] ?? ('call_' . $i)), 'type' => 'function', 'function' => ['name' => (string)$c['name'], 'arguments' => json_encode((object)($c['args'] ?? []))]];
        }
    }
    $fr = ['end' => 'stop', 'tool' => 'tool_calls', 'max_tokens' => 'length', 'refusal' => 'content_filter'][$stop] ?? 'stop';
    return ['choices' => [['index' => 0, 'message' => $msg, 'finish_reason' => $fr]], 'usage' => ['prompt_tokens' => $in, 'completion_tokens' => $out]];
}

/** JSON object for tool arguments / schema properties: an empty PHP array must still encode as {}. */
function rh_ai_obj($v)
{
    if (is_array($v) && $v === []) {
        return (object)[];
    }
    return $v;
}

/** A tool schema as a JSON object schema (properties {} when empty). */
function rh_ai_tool_schema_obj(array $s): array
{
    $s['type'] = 'object';
    $s['properties'] = rh_ai_obj((array)($s['properties'] ?? []));
    if (isset($s['required']) && !$s['required']) {
        unset($s['required']);
    }
    return $s;
}

/** Gemini accepts an OpenAPI subset: drop keys it rejects, recursively. */
function rh_ai_gemini_schema(array $s): array
{
    $out = [];
    foreach ($s as $k => $v) {
        if (in_array($k, ['additionalProperties', '$schema', 'default', 'pattern', 'examples', 'minLength', 'maxLength'], true)) {
            continue;
        }
        if ($k === 'properties' && is_array($v)) {
            $props = [];
            foreach ($v as $pk => $pv) {
                $props[$pk] = is_array($pv) ? rh_ai_gemini_schema($pv) : $pv;
            }
            $out[$k] = rh_ai_obj($props);
            continue;
        }
        $out[$k] = (is_array($v) && $k === 'items') ? rh_ai_gemini_schema($v) : $v;
    }
    return $out;
}

/**
 * Build the provider request. $req (normalised):
 *   provider, model, key, system_frozen, system_user (cached too), system_volatile, messages[], tools[],
 *   max_tokens, effort (low|medium|high), drop_optional (leave out effort settings after a 400).
 * messages: {role:user, content} | {role:assistant, content, tool_calls[{id,name,args}]} |
 *           {role:tool, tool_call_id, name, content (JSON string)}
 * Nothing here ever adds temperature/top_p/top_k, a "thinking" block, budget_tokens or a forced tool_choice.
 * @return array{url:string, headers:array, body:array}
 */
function rh_ai_build_request(array $req, bool $responsesApi = false): array
{
    $provider = (string)$req['provider'];
    $model = (string)$req['model'];
    $conn = rh_ai_provider_request($provider, (string)($req['key'] ?? ''));
    $frozen = (string)($req['system_frozen'] ?? '');
    $userBlock = (string)($req['system_user'] ?? '');
    $volatile = (string)($req['system_volatile'] ?? '');
    $tools = array_values((array)($req['tools'] ?? []));
    usort($tools, static fn($a, $b) => strcmp((string)$a['name'], (string)$b['name'])); // deterministic = cacheable
    $max = max(16, (int)($req['max_tokens'] ?? 1024));
    $effort = in_array(($req['effort'] ?? ''), ['low', 'medium', 'high'], true) ? (string)$req['effort'] : 'low';
    $optional = empty($req['drop_optional']);
    $msgs = (array)($req['messages'] ?? []);
    $adapter = rh_ai_adapter($provider);

    if ($adapter === 'anthropic') {
        $system = [];
        if ($frozen !== '') {
            $system[] = ['type' => 'text', 'text' => $frozen, 'cache_control' => ['type' => 'ephemeral']];
        }
        if ($userBlock !== '') {
            $system[] = ['type' => 'text', 'text' => $userBlock, 'cache_control' => ['type' => 'ephemeral']];
        }
        if ($volatile !== '') {
            $system[] = ['type' => 'text', 'text' => $volatile]; // after the cache breakpoints
        }
        $out = [];
        foreach ($msgs as $m) {
            $role = (string)$m['role'];
            if ($role === 'tool') {
                $block = ['type' => 'tool_result', 'tool_use_id' => (string)$m['tool_call_id'], 'content' => (string)$m['content']];
                $last = count($out) - 1;
                if ($last >= 0 && $out[$last]['role'] === 'user' && is_array($out[$last]['content']) && ($out[$last]['content'][0]['type'] ?? '') === 'tool_result') {
                    $out[$last]['content'][] = $block;
                } else {
                    $out[] = ['role' => 'user', 'content' => [$block]];
                }
                continue;
            }
            if ($role === 'assistant') {
                $content = [];
                if ((string)($m['content'] ?? '') !== '') {
                    $content[] = ['type' => 'text', 'text' => (string)$m['content']];
                }
                foreach ((array)($m['tool_calls'] ?? []) as $c) {
                    $content[] = ['type' => 'tool_use', 'id' => (string)$c['id'], 'name' => (string)$c['name'], 'input' => rh_ai_obj((array)($c['args'] ?? []))];
                }
                if ($content) {
                    $out[] = ['role' => 'assistant', 'content' => $content];
                }
                continue;
            }
            $last = count($out) - 1;
            if ($last >= 0 && $out[$last]['role'] === 'user' && is_string($out[$last]['content'])) {
                $out[$last]['content'] .= "\n\n" . (string)$m['content'];
            } else {
                $out[] = ['role' => 'user', 'content' => (string)$m['content']];
            }
        }
        $body = ['model' => $model, 'max_tokens' => $max, 'system' => $system, 'messages' => $out];
        if ($tools) {
            $t = [];
            foreach ($tools as $tool) {
                $t[] = ['name' => (string)$tool['name'], 'description' => (string)$tool['description'], 'input_schema' => rh_ai_tool_schema_obj((array)$tool['schema'])];
            }
            $t[count($t) - 1]['cache_control'] = ['type' => 'ephemeral'];
            $body['tools'] = $t;
            $body['tool_choice'] = ['type' => 'auto'];
        }
        if ($optional) {
            $body['output_config'] = ['effort' => $effort];
        }
        return ['url' => $conn['base'] . '/messages', 'headers' => $conn['headers'], 'body' => $body];
    }

    if ($adapter === 'gemini') {
        $sys = [];
        foreach ([$frozen, $userBlock, $volatile] as $s) {
            if ($s !== '') {
                $sys[] = ['text' => $s];
            }
        }
        $contents = [];
        foreach ($msgs as $m) {
            $role = (string)$m['role'];
            if ($role === 'tool') {
                $resp = json_decode((string)$m['content'], true);
                $isMap = is_array($resp) && $resp !== [] && array_keys($resp) !== range(0, count($resp) - 1);
                $part = ['functionResponse' => ['name' => (string)$m['name'], 'response' => $isMap ? $resp : ['result' => $resp ?? (string)$m['content']]]];
                $last = count($contents) - 1;
                if ($last >= 0 && $contents[$last]['role'] === 'user' && isset($contents[$last]['parts'][0]['functionResponse'])) {
                    $contents[$last]['parts'][] = $part;
                } else {
                    $contents[] = ['role' => 'user', 'parts' => [$part]];
                }
                continue;
            }
            if ($role === 'assistant') {
                $parts = [];
                if ((string)($m['content'] ?? '') !== '') {
                    $parts[] = ['text' => (string)$m['content']];
                }
                foreach ((array)($m['tool_calls'] ?? []) as $c) {
                    $fc = ['functionCall' => ['name' => (string)$c['name'], 'args' => rh_ai_obj((array)($c['args'] ?? []))]];
                    if (!empty($c['sig'])) { // Gemini 3 rejects a replayed call without its thought signature (HTTP 400)
                        $fc['thoughtSignature'] = (string)$c['sig'];
                    }
                    $parts[] = $fc;
                }
                if ($parts) {
                    $contents[] = ['role' => 'model', 'parts' => $parts];
                }
                continue;
            }
            $contents[] = ['role' => 'user', 'parts' => [['text' => (string)$m['content']]]];
        }
        $body = ['contents' => $contents, 'generationConfig' => ['maxOutputTokens' => $max]];
        if ($sys) {
            $body['systemInstruction'] = ['parts' => $sys];
        }
        if ($tools) {
            $decl = [];
            foreach ($tools as $tool) {
                $d = ['name' => (string)$tool['name'], 'description' => (string)$tool['description']];
                $schema = (array)$tool['schema'];
                if (!empty($schema['properties'])) {
                    $d['parameters'] = rh_ai_gemini_schema(rh_ai_tool_schema_obj($schema));
                }
                $decl[] = $d;
            }
            $body['tools'] = [['functionDeclarations' => $decl]];
        }
        return ['url' => $conn['base'] . '/models/' . rawurlencode($model) . ':generateContent', 'headers' => $conn['headers'], 'body' => $body];
    }

    // OpenAI-compatible: Responses API (OpenAI models that refuse Chat Completions)
    if ($responsesApi) {
        $input = [];
        foreach ($msgs as $m) {
            $role = (string)$m['role'];
            if ($role === 'tool') {
                $input[] = ['type' => 'function_call_output', 'call_id' => (string)$m['tool_call_id'], 'output' => (string)$m['content']];
            } elseif ($role === 'assistant') {
                if ((string)($m['content'] ?? '') !== '') {
                    $input[] = ['role' => 'assistant', 'content' => (string)$m['content']];
                }
                foreach ((array)($m['tool_calls'] ?? []) as $c) {
                    $input[] = ['type' => 'function_call', 'call_id' => (string)$c['id'], 'name' => (string)$c['name'], 'arguments' => json_encode(rh_ai_obj((array)($c['args'] ?? [])))];
                }
            } else {
                $input[] = ['role' => 'user', 'content' => (string)$m['content']];
            }
        }
        $body = ['model' => $model, 'instructions' => trim($frozen . "\n\n" . $userBlock . "\n\n" . $volatile), 'input' => $input, 'max_output_tokens' => $max];
        if ($tools) {
            $body['tools'] = [];
            foreach ($tools as $tool) {
                $body['tools'][] = ['type' => 'function', 'name' => (string)$tool['name'], 'description' => (string)$tool['description'], 'parameters' => rh_ai_tool_schema_obj((array)$tool['schema'])];
            }
        }
        if ($optional) {
            $body['reasoning'] = ['effort' => $effort];
        }
        return ['url' => $conn['base'] . '/responses', 'headers' => $conn['headers'], 'body' => $body];
    }

    // OpenAI-compatible: Chat Completions (OpenAI, xAI, DeepSeek)
    $out = [];
    foreach ([$frozen, $userBlock, $volatile] as $s) {
        if ($s !== '') {
            $out[] = ['role' => 'system', 'content' => $s]; // stable text first: automatic prefix caching
        }
    }
    foreach ($msgs as $m) {
        $role = (string)$m['role'];
        if ($role === 'tool') {
            $out[] = ['role' => 'tool', 'tool_call_id' => (string)$m['tool_call_id'], 'content' => (string)$m['content']];
        } elseif ($role === 'assistant') {
            $a = ['role' => 'assistant', 'content' => (string)($m['content'] ?? '')];
            if (!empty($m['tool_calls'])) {
                $a['tool_calls'] = [];
                foreach ((array)$m['tool_calls'] as $c) {
                    $a['tool_calls'][] = ['id' => (string)$c['id'], 'type' => 'function', 'function' => ['name' => (string)$c['name'], 'arguments' => json_encode(rh_ai_obj((array)($c['args'] ?? [])))]];
                }
            }
            $out[] = $a;
        } else {
            $out[] = ['role' => 'user', 'content' => (string)$m['content']];
        }
    }
    $body = ['model' => $model, 'messages' => $out];
    $body[$provider === 'openai' ? 'max_completion_tokens' : 'max_tokens'] = $max;
    if ($tools) {
        $body['tools'] = [];
        foreach ($tools as $tool) {
            $body['tools'][] = ['type' => 'function', 'function' => ['name' => (string)$tool['name'], 'description' => (string)$tool['description'], 'parameters' => rh_ai_tool_schema_obj((array)$tool['schema'])]];
        }
        $body['tool_choice'] = 'auto';
    }
    if ($optional && $provider === 'openai' && preg_match('/^(gpt-5|o\d)/', $model)) {
        $body['reasoning_effort'] = $effort;
    }
    return ['url' => $conn['base'] . '/chat/completions', 'headers' => $conn['headers'], 'body' => $body];
}

/**
 * Normalise a provider's answer.
 * @return array{text:string, tool_calls:array, usage:array{in:int,out:int,cache_read:int,cache_write:int}, stop:string, error:string}
 * stop: end | tool | max_tokens | refusal
 */
function rh_ai_parse_response(string $provider, array $j, bool $responsesApi = false): array
{
    $res = ['text' => '', 'tool_calls' => [], 'usage' => ['in' => 0, 'out' => 0, 'cache_read' => 0, 'cache_write' => 0], 'stop' => 'end', 'error' => ''];
    $adapter = rh_ai_adapter($provider);
    if ($adapter === 'anthropic') {
        $u = (array)($j['usage'] ?? []);
        $res['usage'] = ['in' => (int)($u['input_tokens'] ?? 0), 'out' => (int)($u['output_tokens'] ?? 0), 'cache_read' => (int)($u['cache_read_input_tokens'] ?? 0), 'cache_write' => (int)($u['cache_creation_input_tokens'] ?? 0)];
        $sr = (string)($j['stop_reason'] ?? '');
        if ($sr === 'refusal') { // checked before any content is read
            $res['stop'] = 'refusal';
            return $res;
        }
        $res['stop'] = $sr === 'max_tokens' ? 'max_tokens' : ($sr === 'tool_use' ? 'tool' : 'end');
        foreach ((array)($j['content'] ?? []) as $b) {
            if (!is_array($b)) {
                continue;
            }
            if (($b['type'] ?? '') === 'text') {
                $res['text'] .= (string)$b['text'];
            } elseif (($b['type'] ?? '') === 'tool_use') {
                $args = $b['input'] ?? [];
                if (is_string($args)) {
                    $args = json_decode($args, true);
                }
                $res['tool_calls'][] = ['id' => (string)($b['id'] ?? ''), 'name' => (string)($b['name'] ?? ''), 'args' => is_array($args) ? $args : []];
            }
        }
        if ($res['stop'] === 'end' && $res['tool_calls']) {
            $res['stop'] = 'tool';
        }
    } elseif ($adapter === 'gemini') {
        $u = (array)($j['usageMetadata'] ?? []);
        $cached = (int)($u['cachedContentTokenCount'] ?? 0); // included in promptTokenCount
        $res['usage'] = ['in' => max(0, (int)($u['promptTokenCount'] ?? 0) - $cached), 'out' => (int)($u['candidatesTokenCount'] ?? 0) + (int)($u['thoughtsTokenCount'] ?? 0), 'cache_read' => $cached, 'cache_write' => 0];
        if (!empty($j['promptFeedback']['blockReason'])) {
            $res['stop'] = 'refusal';
            return $res;
        }
        $c = (array)($j['candidates'][0] ?? []);
        $fr = (string)($c['finishReason'] ?? 'STOP');
        if (in_array($fr, ['SAFETY', 'RECITATION', 'PROHIBITED_CONTENT', 'BLOCKLIST', 'SPII'], true)) {
            $res['stop'] = 'refusal';
            return $res;
        }
        foreach ((array)($c['content']['parts'] ?? []) as $i => $p) {
            if (isset($p['text']) && empty($p['thought'])) {
                $res['text'] .= (string)$p['text'];
            } elseif (isset($p['functionCall'])) {
                $args = $p['functionCall']['args'] ?? [];
                $call = ['id' => 'g' . $i, 'name' => (string)($p['functionCall']['name'] ?? ''), 'args' => is_array($args) ? $args : []];
                if (isset($p['thoughtSignature']) && is_string($p['thoughtSignature']) && $p['thoughtSignature'] !== '') {
                    $call['sig'] = $p['thoughtSignature']; // sent back unchanged on the same part (parallel calls: only the first has one)
                }
                $res['tool_calls'][] = $call;
            }
        }
        $res['stop'] = $fr === 'MAX_TOKENS' ? 'max_tokens' : ($res['tool_calls'] ? 'tool' : 'end');
    } elseif ($responsesApi) {
        $u = (array)($j['usage'] ?? []);
        $cached = (int)($u['input_tokens_details']['cached_tokens'] ?? 0); // included in input_tokens
        $res['usage'] = ['in' => max(0, (int)($u['input_tokens'] ?? 0) - $cached), 'out' => (int)($u['output_tokens'] ?? 0), 'cache_read' => $cached, 'cache_write' => 0];
        foreach ((array)($j['output'] ?? []) as $item) {
            $type = (string)($item['type'] ?? '');
            if ($type === 'message') {
                foreach ((array)($item['content'] ?? []) as $c) {
                    if (($c['type'] ?? '') === 'output_text') {
                        $res['text'] .= (string)$c['text'];
                    } elseif (($c['type'] ?? '') === 'refusal') {
                        $res['stop'] = 'refusal';
                        $res['text'] = '';
                        return $res;
                    }
                }
            } elseif ($type === 'function_call') {
                $args = json_decode((string)($item['arguments'] ?? '{}'), true);
                $res['tool_calls'][] = ['id' => (string)($item['call_id'] ?? ''), 'name' => (string)($item['name'] ?? ''), 'args' => is_array($args) ? $args : []];
            }
        }
        $res['stop'] = ($j['status'] ?? '') === 'incomplete' ? 'max_tokens' : ($res['tool_calls'] ? 'tool' : 'end');
    } else {
        $u = (array)($j['usage'] ?? []);
        $cached = (int)($u['prompt_tokens_details']['cached_tokens'] ?? ($u['prompt_cache_hit_tokens'] ?? 0));
        $res['usage'] = ['in' => max(0, (int)($u['prompt_tokens'] ?? 0) - $cached), 'out' => (int)($u['completion_tokens'] ?? 0), 'cache_read' => $cached, 'cache_write' => 0]; // cached is part of prompt_tokens
        $c = (array)($j['choices'][0] ?? []);
        $fr = (string)($c['finish_reason'] ?? 'stop');
        $msg = (array)($c['message'] ?? []);
        if ($fr === 'content_filter' || !empty($msg['refusal'])) {
            $res['stop'] = 'refusal';
            return $res;
        }
        $res['text'] = (string)($msg['content'] ?? '');
        foreach ((array)($msg['tool_calls'] ?? []) as $tc) {
            $args = json_decode((string)($tc['function']['arguments'] ?? '{}'), true);
            $res['tool_calls'][] = ['id' => (string)($tc['id'] ?? ''), 'name' => (string)($tc['function']['name'] ?? ''), 'args' => is_array($args) ? $args : []];
        }
        $res['stop'] = $fr === 'length' ? 'max_tokens' : ($res['tool_calls'] ? 'tool' : 'end');
    }
    return $res;
}

/**
 * One chat call, normalised. Never throws; the key never appears in the result.
 * @return array{text:string, tool_calls:array, usage:array, stop:string, error:string, http:int, retryable:bool, model_missing:bool, ms:int, provider:string, model:string}
 */
function rh_ai_chat(array $req): array
{
    $provider = (string)($req['provider'] ?? '');
    $model = (string)($req['model'] ?? '');
    $base = ['text' => '', 'tool_calls' => [], 'usage' => ['in' => 0, 'out' => 0, 'cache_read' => 0, 'cache_write' => 0], 'stop' => 'error', 'error' => '', 'http' => 0, 'retryable' => false, 'model_missing' => false, 'ms' => 0, 'provider' => $provider, 'model' => $model];
    if (!rh_ai_is_provider($provider) || $model === '') {
        return ['error' => 'No model is chosen for the Assistant yet. Ask an administrator to finish Assistant Settings.'] + $base;
    }
    if (!rh_ai_cloud_on()) {
        return ['error' => rh_ai_cloud_off_message()] + $base;
    }
    $key = (string)($req['key'] ?? '');
    if ($key === '') {
        $key = rh_ai_get_key($provider);
    }
    if ($key === '' && rh_ai_fake_name() !== '') {
        $key = 'fake-key-for-tests-only-0000';
    }
    if ($key === '') {
        return ['error' => 'No key is saved for ' . rh_ai_provider_list()[$provider]['label'] . '. Ask an administrator to check Assistant Settings.'] + $base;
    }
    $req['key'] = $key;
    $timeout = max(10, min(60, (int)($req['timeout'] ?? 60)));
    $responsesApi = !empty($req['responses_api']);
    $ms = 0;
    for ($attempt = 0; $attempt < 3; $attempt++) {
        $built = rh_ai_build_request($req, $responsesApi);
        $r = rh_ai_http('POST', $built['url'], $built['headers'], json_encode($built['body']), $timeout);
        $ms += $r['ms'];
        if ($r['http'] >= 200 && $r['http'] < 300) {
            $j = json_decode($r['body'], true);
            if (!is_array($j)) {
                return ['error' => 'The provider sent an answer that could not be read.', 'http' => $r['http'], 'retryable' => true, 'ms' => $ms] + $base;
            }
            $p = rh_ai_parse_response($provider, $j, $responsesApi);
            return ['http' => $r['http'], 'ms' => $ms, 'retryable' => false, 'model_missing' => false, 'provider' => $provider, 'model' => $model] + $p;
        }
        $lower = strtolower($r['body']);
        // A 400 about an optional setting (effort): send once more without it.
        if ($r['http'] === 400 && empty($req['drop_optional']) && preg_match('/output_config|effort|reasoning/', $lower)) {
            $req['drop_optional'] = true;
            continue;
        }
        // OpenAI models that only speak the Responses API.
        if ($provider === 'openai' && !$responsesApi && in_array($r['http'], [400, 404], true) && preg_match('/v1\/responses|responses api/', $lower)) {
            $responsesApi = true;
            continue;
        }
        $missing = $r['http'] === 404 || ($r['http'] === 400 && preg_match('/model.{0,40}(not.{0,10}found|does not exist|not available)/', $lower));
        return [
            'error' => rh_ai_http_problem($r, $key),
            'http' => $r['http'],
            'retryable' => $r['http'] === 0 || $r['http'] === 408 || $r['http'] === 429 || $r['http'] >= 500,
            'model_missing' => (bool)$missing,
            'ms' => $ms,
        ] + $base;
    }
    return ['error' => 'The provider refused the request.', 'ms' => $ms] + $base;
}

/**
 * rh_ai_chat with the self-healing rule: on a timeout / rate limit / 5xx retry once, then use the
 * Fallback model (also when the model has been retired). 'used_fallback' tells the panel to say so.
 */
function rh_ai_chat_resilient(array $req, ?string $fallbackModel = null): array
{
    $r = rh_ai_chat($req);
    if ($r['error'] === '') {
        return $r + ['used_fallback' => false];
    }
    if ($r['retryable']) {
        $r = rh_ai_chat($req);
        if ($r['error'] === '') {
            return $r + ['used_fallback' => false];
        }
    }
    if ($fallbackModel !== null && $fallbackModel !== '' && $fallbackModel !== ($req['model'] ?? '') && ($r['retryable'] || $r['model_missing'])) {
        $missing = !empty($r['model_missing']);
        $req['model'] = $fallbackModel;
        return rh_ai_chat($req) + ['used_fallback' => true, 'first_model_missing' => $missing]; // a retired model is replaced afterwards (self-healing)
    }
    return $r + ['used_fallback' => false];
}

/**
 * Several chat calls at once (the swarm's specialists), one cURL multi handle, so three specialists cost
 * the time of the slowest one, not of all three. Results come back keyed like $reqs. A call that does not
 * succeed first time is re-run on its own through rh_ai_chat_resilient (effort retry, Responses API,
 * retry once, Fallback model). With a test transport or the fake provider the calls run one after another
 * through the same seam, so nothing ever goes on the network in tests.
 */
function rh_ai_chat_multi(array $reqs, ?string $fallbackModel = null): array
{
    $out = [];
    $sequential = count($reqs) < 2 || is_callable($GLOBALS['__rh_ai_transport'] ?? null) || rh_ai_fake_name() !== ''
        || !function_exists('curl_multi_init') || !rh_ai_cloud_on();
    if ($sequential) {
        foreach ($reqs as $k => $req) {
            $out[$k] = rh_ai_chat_resilient($req, $fallbackModel);
        }
        return $out;
    }
    $mh = curl_multi_init();
    $handles = [];
    foreach ($reqs as $k => $req) {
        $provider = (string)($req['provider'] ?? '');
        $key = (string)($req['key'] ?? '');
        if ($key === '' && rh_ai_is_provider($provider)) {
            $key = rh_ai_get_key($provider);
        }
        if (!rh_ai_is_provider($provider) || (string)($req['model'] ?? '') === '' || $key === '') {
            continue; // handled one by one below (plain error)
        }
        $req['key'] = $key;
        $built = rh_ai_build_request($req, false);
        $timeout = max(10, min(60, (int)($req['timeout'] ?? 60)));
        $ch = curl_init($built['url']);
        curl_setopt_array($ch, rh_ai_curl_opts('POST', $built['headers'], json_encode($built['body']), $timeout));
        curl_multi_add_handle($mh, $ch);
        $handles[$k] = [$ch, $req];
    }
    $t0 = microtime(true);
    do {
        $st = curl_multi_exec($mh, $running);
        if ($running) {
            curl_multi_select($mh, 1.0);
        }
    } while ($running && $st === CURLM_OK);
    $ms = (int)round((microtime(true) - $t0) * 1000);
    foreach ($handles as $k => [$ch, $req]) {
        $http = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        $body = (string)curl_multi_getcontent($ch);
        curl_multi_remove_handle($mh, $ch);
        curl_close($ch);
        $j = ($http >= 200 && $http < 300) ? json_decode($body, true) : null;
        if (is_array($j)) {
            $out[$k] = ['http' => $http, 'ms' => $ms, 'retryable' => false, 'model_missing' => false, 'provider' => (string)$req['provider'], 'model' => (string)$req['model'], 'used_fallback' => false]
                + rh_ai_parse_response((string)$req['provider'], $j, false);
        }
    }
    curl_multi_close($mh);
    $ordered = [];
    foreach ($reqs as $k => $req) {
        $ordered[$k] = $out[$k] ?? rh_ai_chat_resilient($req, $fallbackModel);
    }
    return $ordered;
}

/** Estimated cost in US dollars (cache reads at 10%, cache writes at 125% of the input price). */
function rh_ai_cost_usd(?float $priceIn, ?float $priceOut, array $usage): float
{
    $pi = (float)($priceIn ?? 0);
    $po = (float)($priceOut ?? 0);
    $in = (int)($usage['in'] ?? 0);
    $cr = (int)($usage['cache_read'] ?? 0);
    $cw = (int)($usage['cache_write'] ?? 0);
    return round(($in * $pi + $cr * $pi * 0.1 + $cw * $pi * 1.25 + (int)($usage['out'] ?? 0) * $po) / 1000000, 6);
}
