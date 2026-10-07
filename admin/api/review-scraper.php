<?php

/**
 * Admin Review Scraper API
 *
 * POST action=search     -> search public web snippets for positive/negative hotel feedback
 * POST action=import     -> import one candidate into the reviews table (always pending)
 * POST action=fetch_date -> look up the original post date for an already-imported review
 *
 * All outbound requests run in parallel (curl_multi) with hard time caps, so a
 * search finishes in well under a minute instead of running every query and
 * engine back-to-back. Each result's date is read from the source page itself
 * (publish-time metadata) where possible, then from the snippet, and only as a
 * last resort from the search engine's index date. `date_source` says which.
 */

require_once __DIR__ . '/../../includes/admin-session.php';
rh_admin_session_start(); // 8h idle sign-out

header('Content-Type: application/json');

require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../config/security.php';
require_once __DIR__ . '/../../config/cache.php';
require_once __DIR__ . '/../includes/permissions.php';
require_once __DIR__ . '/../../includes/reviews-display.php'; // rh_review_source_meta(), rh_clear_review_caches(), rh_review_platform_key()
require_once __DIR__ . '/../../includes/review-sources.php';   // key storage, http_json(), Google place lookup

function json_success(array $data = [], string $message = 'OK', int $code = 200): never
{
    http_response_code($code);
    echo json_encode([
        'success' => true,
        'message' => $message,
        'data' => $data,
    ]);
    exit;
}

function json_error(string $error, int $code = 400): never
{
    http_response_code($code);
    echo json_encode([
        'success' => false,
        'error' => $error,
        'code' => $code,
    ]);
    exit;
}

function read_json_input(): array
{
    $raw = file_get_contents('php://input');
    if (!is_string($raw) || trim($raw) === '') {
        return [];
    }

    $decoded = json_decode($raw, true);
    return is_array($decoded) ? $decoded : [];
}

function fetch_url(string $url, int $timeout = 12): ?string
{
    if (!preg_match('/^https?:\/\//i', $url)) {
        return null;
    }

    $baseOptions = [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_MAXREDIRS => 4,
        CURLOPT_CONNECTTIMEOUT => 6,
        CURLOPT_TIMEOUT => $timeout,
        CURLOPT_USERAGENT => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/124.0 Safari/537.36',
        CURLOPT_HTTPHEADER => [
            'Accept: text/html,application/xhtml+xml,application/xml;q=0.9,*/*;q=0.8',
            'Accept-Language: en-US,en;q=0.9'
        ],
        CURLOPT_ENCODING => '',
    ];

    $runRequest = static function (array $options) use ($url): array {
        $ch = curl_init($url);
        if ($ch === false) {
            return ['body' => null, 'status' => 0, 'errno' => 0];
        }

        curl_setopt_array($ch, $options);
        $body = curl_exec($ch);
        $status = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $errno = curl_errno($ch);
        curl_close($ch);

        return [
            'body' => is_string($body) ? $body : null,
            'status' => $status,
            'errno' => $errno,
        ];
    };

    $response = $runRequest($baseOptions);

    $host = strtolower((string)(parse_url($url, PHP_URL_HOST) ?? ''));
    $allowInsecureHosts = [
        'duckduckgo.com',
        'www.duckduckgo.com',
        'html.duckduckgo.com',
        'bing.com',
        'www.bing.com',
        'r.jina.ai',
    ];
    $allowInsecureFallback = in_array($host, $allowInsecureHosts, true);

    // Some local PHP/cURL installs miss a CA chain; retry once for known public search hosts.
    if (($response['body'] === null || $response['status'] < 200 || $response['status'] >= 400)
        && (int)($response['errno'] ?? 0) === 60
        && $allowInsecureFallback
        && stripos($url, 'https://') === 0
    ) {
        $insecureOptions = $baseOptions;
        $insecureOptions[CURLOPT_SSL_VERIFYPEER] = false;
        $insecureOptions[CURLOPT_SSL_VERIFYHOST] = 0;
        $response = $runRequest($insecureOptions);
    }

    $body = $response['body'];
    $status = (int)($response['status'] ?? 0);

    if (!is_string($body) || $body === '' || $status < 200 || $status >= 400) {
        return null;
    }

    return $body;
}

/** Hosts where a missing local CA bundle may be bypassed (public search/reader endpoints only). */
function scraper_insecure_ok(string $url): bool
{
    $host = strtolower((string)(parse_url($url, PHP_URL_HOST) ?? ''));
    return in_array($host, ['duckduckgo.com', 'www.duckduckgo.com', 'html.duckduckgo.com', 'bing.com', 'www.bing.com', 'r.jina.ai'], true);
}

/**
 * Fetch many URLs concurrently. Returns [key => body|null] in input order.
 * $timeout is per request; because they run in parallel the whole batch also
 * finishes in roughly $timeout seconds.
 */
function fetch_many(array $urls, int $timeout = 12, array $extraHeaders = []): array
{
    $results = [];
    foreach ($urls as $k => $_) {
        $results[$k] = null;
    }
    if (!function_exists('curl_multi_init') || empty($urls)) {
        foreach ($urls as $k => $u) {
            $results[$k] = fetch_url((string)$u, $timeout);
        }
        return $results;
    }

    $run = static function (array $batch, bool $insecure) use ($timeout, $extraHeaders): array {
        $mh = curl_multi_init();
        $handles = [];
        foreach ($batch as $k => $url) {
            if (!preg_match('/^https?:\/\//i', (string)$url)) {
                continue;
            }
            $ch = curl_init((string)$url);
            curl_setopt_array($ch, [
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_FOLLOWLOCATION => true,
                CURLOPT_MAXREDIRS => 4,
                CURLOPT_CONNECTTIMEOUT => 6,
                CURLOPT_TIMEOUT => $timeout,
                CURLOPT_USERAGENT => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/124.0 Safari/537.36',
                CURLOPT_HTTPHEADER => array_merge([
                    'Accept: text/html,application/xhtml+xml,application/xml;q=0.9,*/*;q=0.8',
                    'Accept-Language: en-US,en;q=0.9',
                ], $extraHeaders),
                CURLOPT_ENCODING => '',
                CURLOPT_SSL_VERIFYPEER => !$insecure,
                CURLOPT_SSL_VERIFYHOST => $insecure ? 0 : 2,
            ]);
            curl_multi_add_handle($mh, $ch);
            $handles[(int)$ch] = [$k, $ch];
        }

        $out = [];
        $errnos = [];
        $running = 0;
        do {
            $status = curl_multi_exec($mh, $running);
            while ($info = curl_multi_info_read($mh)) {
                $errnos[(int)$info['handle']] = (int)$info['result'];
            }
            if ($running > 0) {
                curl_multi_select($mh, 1.0);
            }
        } while ($running > 0 && $status === CURLM_OK);

        foreach ($handles as $hid => [$k, $ch]) {
            $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
            $body = curl_multi_getcontent($ch);
            $out[$k] = [
                'body' => (is_string($body) && $body !== '' && $code >= 200 && $code < 400) ? $body : null,
                'errno' => $errnos[$hid] ?? 0,
            ];
            curl_multi_remove_handle($mh, $ch);
            curl_close($ch);
        }
        curl_multi_close($mh);
        return $out;
    };

    $first = $run($urls, false);
    $retry = [];
    foreach ($first as $k => $r) {
        $results[$k] = $r['body'];
        // errno 60 = local CA bundle missing; retry known public search hosts once.
        if ($r['body'] === null && $r['errno'] === 60 && scraper_insecure_ok((string)$urls[$k])) {
            $retry[$k] = $urls[$k];
        }
    }
    if ($retry) {
        foreach ($run($retry, true) as $k => $r) {
            $results[$k] = $r['body'];
        }
    }
    return $results;
}

function strip_text(string $html): string
{
    $text = html_entity_decode(strip_tags($html), ENT_QUOTES | ENT_HTML5, 'UTF-8');
    $text = preg_replace('/\s+/', ' ', $text);
    return trim((string)$text);
}

function strip_markdown_text(string $text): string
{
    $text = html_entity_decode($text, ENT_QUOTES | ENT_HTML5, 'UTF-8');
    $text = preg_replace('/\[([^\]]+)\]\(([^\)]+)\)/', '$1', $text);
    $text = str_replace(['**', '__', '`'], '', (string)$text);
    $text = preg_replace('/\s+/', ' ', (string)$text);
    return trim((string)$text);
}

function parse_handle_from_text(string $text): string
{
    if (preg_match('/@([a-zA-Z0-9._-]{3,60})/', $text, $m)) {
        return $m[1];
    }
    return '';
}

function parse_handle_from_url(string $url): string
{
    $parts = parse_url($url);
    if (!is_array($parts)) {
        return '';
    }

    $host = strtolower((string)($parts['host'] ?? ''));
    $path = trim((string)($parts['path'] ?? ''), '/');
    if ($path === '') {
        return '';
    }

    $segments = explode('/', $path);
    $firstSegment = trim((string)($segments[0] ?? ''));
    if ($firstSegment === '') {
        return '';
    }

    if (strpos($host, 'tiktok.com') !== false && str_starts_with($firstSegment, '@')) {
        $candidate = substr($firstSegment, 1);
        return preg_match('/^[a-zA-Z0-9._-]{3,60}$/', $candidate) ? $candidate : '';
    }

    if (
        strpos($host, 'instagram.com') !== false ||
        strpos($host, 'twitter.com') !== false ||
        strpos($host, 'x.com') !== false
    ) {
        $candidate = ltrim($firstSegment, '@');
        return preg_match('/^[a-zA-Z0-9._-]{3,60}$/', $candidate) ? $candidate : '';
    }

    return '';
}

function parse_email_from_text(string $text): string
{
    if (preg_match('/([a-zA-Z0-9._%+\-]+@[a-zA-Z0-9.\-]+\.[a-zA-Z]{2,})/', $text, $m)) {
        return strtolower((string)$m[1]);
    }
    return '';
}

function normalize_to_iso_date(string $rawDate): string
{
    $rawDate = trim($rawDate);
    if ($rawDate === '') {
        return '';
    }

    $timestamp = strtotime($rawDate);
    if ($timestamp === false) {
        return '';
    }

    $year = (int)date('Y', $timestamp);
    // A review can't be dated in the future; allow one day of timezone slack.
    if ($year < 2000 || $timestamp > time() + 86400) {
        return '';
    }

    return date('Y-m-d', $timestamp);
}

/** Unix seconds (or milliseconds) -> Y-m-d, or '' when implausible. */
function unix_to_iso_date($value): string
{
    if (!is_numeric($value)) {
        return '';
    }
    $ts = (int)$value;
    if ($ts > 100000000000) {
        $ts = (int)floor($ts / 1000);
    }
    return ($ts > 946684800 && $ts <= time() + 86400) ? date('Y-m-d', $ts) : '';
}

/**
 * Read a publish date out of a fetched source page (HTML) or an r.jina.ai
 * reader rendition of it. Only structured signals are trusted — a page can
 * mention many unrelated dates in its body text.
 */
function extract_date_from_page(string $body): string
{
    if ($body === '') {
        return '';
    }
    $head = substr($body, 0, 400000);

    $stringPatterns = [
        '/^Published Time:\s*(.+)$/mi',                                                                     // r.jina.ai header
        '/<meta[^>]+(?:property|name|itemprop)=["\'](?:article:published_time|og:published_time|datePublished|date|pubdate|publish[-_]date|dc\.date|sailthru\.date|parsely-pub-date)["\'][^>]*content=["\']([^"\']+)["\']/i',
        '/<meta[^>]+content=["\']([^"\']+)["\'][^>]*(?:property|name|itemprop)=["\'](?:article:published_time|og:published_time|datePublished|date|pubdate|publish[-_]date)["\']/i',
        '/"(?:datePublished|dateCreated|uploadDate)"\s*:\s*"([^"]+)"/i',                                       // JSON-LD
        '/<time[^>]+datetime=["\']([^"\']+)["\']/i',
        '/<meta[^>]+(?:property|name)=["\'](?:article:modified_time|og:updated_time)["\'][^>]*content=["\']([^"\']+)["\']/i',
    ];
    foreach ($stringPatterns as $pattern) {
        if (preg_match($pattern, $head, $m)) {
            $iso = normalize_to_iso_date(html_entity_decode((string)$m[1], ENT_QUOTES | ENT_HTML5, 'UTF-8'));
            if ($iso !== '') {
                return $iso;
            }
        }
    }

    // Social platforms embed post times as unix stamps in their page JSON.
    $unixPatterns = [
        '/"(?:publish_time|creation_time|created_time|createTime|taken_at_timestamp|taken_at|upload_date)"\s*:\s*"?(\d{10,13})"?/i',
        '/data-utime=["\'](\d{10})["\']/i',
    ];
    foreach ($unixPatterns as $pattern) {
        if (preg_match($pattern, $head, $m)) {
            $iso = unix_to_iso_date($m[1]);
            if ($iso !== '') {
                return $iso;
            }
        }
    }

    return '';
}

function parse_source_date_from_text(string $text): string
{
    // Relative ages as search engines print them at the start of a snippet:
    // "3 days ago", "a week ago", "2 months ago".
    if (preg_match('/\b(\d{1,3}|an?|one)\s+(minute|hour|day|week|month|year)s?\s+ago\b/i', $text, $m)) {
        $n = is_numeric($m[1]) ? (int)$m[1] : 1;
        $ts = strtotime('-' . $n . ' ' . strtolower($m[2]) . ($n === 1 ? '' : 's'));
        if ($ts !== false) {
            return date('Y-m-d', $ts);
        }
    }
    if (preg_match('/\byesterday\b/i', $text)) {
        return date('Y-m-d', strtotime('-1 day'));
    }

    $patterns = [
        '/\b(20\d{2}-\d{2}-\d{2})\b/',
        '/\b(\d{1,2}[\/\-]\d{1,2}[\/\-]20\d{2})\b/',
        '/\b((?:Jan(?:uary)?|Feb(?:ruary)?|Mar(?:ch)?|Apr(?:il)?|May|Jun(?:e)?|Jul(?:y)?|Aug(?:ust)?|Sep(?:t(?:ember)?)?|Oct(?:ober)?|Nov(?:ember)?|Dec(?:ember)?)\s+\d{1,2},\s*20\d{2})\b/i',
        '/\b(\d{1,2}\s+(?:Jan(?:uary)?|Feb(?:ruary)?|Mar(?:ch)?|Apr(?:il)?|May|Jun(?:e)?|Jul(?:y)?|Aug(?:ust)?|Sep(?:t(?:ember)?)?|Oct(?:ober)?|Nov(?:ember)?|Dec(?:ember)?)\s+20\d{2})\b/i',
    ];

    foreach ($patterns as $pattern) {
        if (!preg_match($pattern, $text, $m)) {
            continue;
        }

        $normalized = normalize_to_iso_date((string)($m[1] ?? ''));
        if ($normalized !== '') {
            return $normalized;
        }
    }

    // Month + year only when it is clearly a stay/review date ("Date of stay: March 2024").
    if (preg_match('/\b(?:date of (?:stay|visit|experience)|stayed(?: in)?|visited(?: in)?|reviewed|written)\s*:?\s*((?:Jan(?:uary)?|Feb(?:ruary)?|Mar(?:ch)?|Apr(?:il)?|May|Jun(?:e)?|Jul(?:y)?|Aug(?:ust)?|Sep(?:t(?:ember)?)?|Oct(?:ober)?|Nov(?:ember)?|Dec(?:ember)?)\s+20\d{2})\b/i', $text, $m)) {
        $normalized = normalize_to_iso_date('1 ' . $m[1]);
        if ($normalized !== '') {
            return $normalized;
        }
    }

    return '';
}

function extract_url_domain(string $url): string
{
    $host = parse_url($url, PHP_URL_HOST);
    return is_string($host) ? strtolower($host) : '';
}

function detect_source_platform(string $domain): string
{
    $domain = strtolower(trim($domain));
    if ($domain === '') {
        return '';
    }

    $platformDomains = [
        'tiktok.com' => 'TikTok',
        'facebook.com' => 'Facebook',
        'instagram.com' => 'Instagram',
        'x.com' => 'X',
        'twitter.com' => 'X',
    ];

    foreach ($platformDomains as $suffix => $label) {
        if ($domain === $suffix || str_ends_with($domain, '.' . $suffix)) {
            return $label;
        }
    }

    return '';
}

function normalize_for_match(string $text): string
{
    $text = mb_strtolower($text, 'UTF-8');
    $text = str_replace(["'", '’', '`'], '', $text);
    $text = preg_replace('/[^\p{L}\p{N}]+/u', ' ', $text);
    $text = preg_replace('/\s+/', ' ', (string)$text);
    return trim((string)$text);
}

function extract_hotel_terms(string $hotelName): array
{
    $normalized = normalize_for_match($hotelName);
    if ($normalized === '') {
        return [];
    }

    $stopTerms = ['hotel', 'beach', 'resort', 'lodge', 'inn'];
    $parts = preg_split('/\s+/', $normalized) ?: [];
    $terms = [];

    foreach ($parts as $part) {
        if ($part === '' || mb_strlen($part, 'UTF-8') < 4 || in_array($part, $stopTerms, true)) {
            continue;
        }
        $terms[] = $part;
    }

    if (empty($terms)) {
        $terms = array_values(array_filter($parts, static fn(string $part): bool => $part !== ''));
    }

    return array_values(array_unique($terms));
}

/**
 * The distinctive part of the hotel's name: normalised, with generic trailing
 * words dropped ("Liwonde Sun Hotel" -> "liwonde sun", "Rosalyn's Beach Hotel"
 * -> "rosalyns").
 */
function hotel_core_phrase(string $hotelName): string
{
    $words = preg_split('/\s+/', normalize_for_match($hotelName)) ?: [];
    $generic = ['hotel', 'hotels', 'beach', 'resort', 'lodge', 'inn', 'and', 'the', 'spa', 'suites', 'malawi'];
    while (count($words) > 1 && in_array(end($words), $generic, true)) {
        array_pop($words);
    }
    while (count($words) > 1 && in_array(reset($words), ['the'], true)) {
        array_shift($words);
    }
    return trim(implode(' ', $words));
}

function is_relevant_to_hotel(string $title, string $snippet, string $url, string $hotelName): bool
{
    // The result must name THIS hotel. Matching any single word of the name let
    // through every page about the town ("Best hotels in Liwonde") and other
    // hotels that share a word ("Sun Village Hotel").
    $core = hotel_core_phrase($hotelName);
    if ($core === '') {
        return true;
    }

    // Find the name in the original text (apostrophes optional: "Rosalyn's" =
    // "Rosalyns"). A match that runs straight into another capitalised word is
    // part of a different name ("Liwonde Sun Village") and doesn't count.
    $wordPatterns = [];
    foreach (explode(' ', $core) as $w) {
        $wordPatterns[] = implode("['’`]?", array_map(static fn($ch) => preg_quote($ch, '/'), mb_str_split($w)));
    }
    $pattern = '/(?<![\p{L}\p{N}])' . implode("[\\s'’`-]+", $wordPatterns) . '(?![\p{L}\p{N}])(?:\s+((?-i)\p{Lu}[\p{L}]*))?/iu';
    $allowedNext = array_merge(
        ['malawi', 'and', 'the', 'is', 'was', 'in', 'at', 'on', 'review', 'reviews', 'facebook', 'instagram', 'tiktok'],
        explode(' ', normalize_for_match($hotelName))
    );
    if (preg_match_all($pattern, $title . ' ' . $snippet, $matches, PREG_SET_ORDER)) {
        foreach ($matches as $m) {
            $next = isset($m[1]) ? mb_strtolower($m[1], 'UTF-8') : '';
            if ($next === '' || in_array($next, $allowedNext, true)) {
                return true;
            }
        }
    }

    // Page/handle URLs often run the name together: facebook.com/liwondesunhotel
    $compact = str_replace(' ', '', $core);
    $urlCompact = preg_replace('/[^a-z0-9]+/', '', strtolower(rawurldecode($url)));
    return mb_strlen($compact) >= 6 && strpos((string)$urlCompact, $compact) !== false;
}

function build_title_from_snippet(string $snippet, string $sentiment = 'positive'): string
{
    $snippet = trim($snippet);
    $defaultTitle = $sentiment === 'negative' ? 'Guest Service Concern' : 'Positive Guest Feedback';

    if ($snippet === '') {
        return $defaultTitle;
    }

    $title = mb_substr($snippet, 0, 70, 'UTF-8');
    return rtrim($title, " .,!?:;\n\r\t") ?: $defaultTitle;
}

function normalize_search_result_url(string $url): string
{
    $url = trim(html_entity_decode($url, ENT_QUOTES | ENT_HTML5, 'UTF-8'));
    if ($url === '') {
        return '';
    }

    if (strpos($url, '//') === 0) {
        $url = 'https:' . $url;
    }

    // DuckDuckGo redirect links usually store the target URL in `uddg`
    if (strpos($url, 'duckduckgo.com/l/?') !== false || strpos($url, '/l/?') === 0) {
        $query = parse_url($url, PHP_URL_QUERY);
        if (is_string($query) && $query !== '') {
            $params = [];
            parse_str($query, $params);
            if (!empty($params['uddg']) && is_string($params['uddg'])) {
                $candidate = urldecode($params['uddg']);
                if (preg_match('/^https?:\/\//i', $candidate)) {
                    return $candidate;
                }
            }
        }
    }

    return preg_match('/^https?:\/\//i', $url) ? $url : '';
}

function normalize_sentiment(string $sentiment): string
{
    return $sentiment === 'negative' ? 'negative' : 'positive';
}

function score_sentiment_text(string $text, string $sentiment): int
{
    $sentiment = normalize_sentiment($sentiment);
    $text = mb_strtolower($text, 'UTF-8');
    $positiveWords = [
        'great',
        'excellent',
        'amazing',
        'beautiful',
        'friendly',
        'clean',
        'peaceful',
        'breathtaking',
        'luxury',
        'well-appointed',
        'stunning',
        'good value',
        'kind staff',
        'comfortable',
        'wonderful',
        'nice place',
        'highly recommend',
        'perfect',
        'spacious',
        'hospitality',
        'top-notch',
        'memorable'
    ];
    $negativeWords = [
        'bad',
        'worst',
        'dirty',
        'poor',
        'complaint',
        'terrible',
        'awful',
        'disappoint',
        'not recommend',
        'noisy',
        'rude',
        'unsafe',
        'refund',
        'delay',
        'slow service',
        'unhappy',
        'issue',
        'problem',
        'frustrating',
        'late check',
        'check in issue'
    ];

    $score = 0;
    foreach ($positiveWords as $word) {
        if (strpos($text, $word) !== false) {
            $score += 2;
        }
    }
    foreach ($negativeWords as $word) {
        if (strpos($text, $word) !== false) {
            $score -= 3;
        }
    }

    if ($sentiment === 'negative') {
        // In negative mode, invert score so stronger complaints rank higher.
        $score *= -1;
    }

    return $score;
}

function build_candidate(string $title, string $snippet, string $url, string $hotelName, array $hints = [], string $sentiment = 'positive'): ?array
{
    $sentiment = normalize_sentiment($sentiment);
    $normalizedUrl = normalize_search_result_url($url);
    $title = trim($title);
    $snippet = trim($snippet);

    if ($normalizedUrl === '' || $snippet === '') {
        return null;
    }

    // Ad clicks and engine-internal links are never feedback.
    $host = strtolower((string)parse_url($normalizedUrl, PHP_URL_HOST));
    if (preg_match('/(^|\.)(duckduckgo\.com|bing\.com|microsoft\.com|msn\.com)$/', $host)) {
        return null;
    }

    if (!is_relevant_to_hotel($title, $snippet, $normalizedUrl, $hotelName)) {
        return null;
    }

    $fullText = trim($title . ' ' . $snippet);
    $score = score_sentiment_text($fullText, $sentiment);

    $hotelNeedle = normalize_for_match(trim($hotelName));
    $normalizedFullText = normalize_for_match($fullText);
    $containsHotel = ($hotelNeedle !== '' && $normalizedFullText !== '' && strpos($normalizedFullText, $hotelNeedle) !== false);

    if ($containsHotel) {
        $score += $sentiment === 'negative' ? 1 : 2;
    }

    if ($sentiment === 'negative') {
        // Negative mode should return only complaint-like content.
        if ($score < 2) {
            return null;
        }
    } else {
        // Accept if there's either a weak positive sentiment or an explicit hotel mention.
        if ($score < 1 && !$containsHotel) {
            $score = 1;
        }

        if ($score < -1) {
            return null;
        }
    }

    $hintUsername = trim((string)($hints['username'] ?? ''));
    $username = $hintUsername !== '' ? $hintUsername : parse_handle_from_text($fullText);
    if ($username === '') {
        $username = parse_handle_from_url($normalizedUrl);
    }

    $hintEmail = trim((string)($hints['email'] ?? ''));
    $email = $hintEmail !== '' ? strtolower($hintEmail) : parse_email_from_text($fullText);

    // A date printed in the snippet is the post's own; a search feed's pubDate is
    // usually when the engine indexed it, so it only fills in when nothing else does.
    $sourceDate = parse_source_date_from_text($fullText);
    $dateSource = $sourceDate !== '' ? 'snippet' : '';
    if ($sourceDate === '') {
        $sourceDate = normalize_to_iso_date(trim((string)($hints['source_date'] ?? '')));
        $dateSource = $sourceDate !== '' ? 'search index' : '';
    }

    $sourceDomain = extract_url_domain($normalizedUrl);
    $sourcePlatform = detect_source_platform($sourceDomain);

    return [
        'title' => $title !== '' ? $title : build_title_from_snippet($snippet, $sentiment),
        'snippet' => $snippet,
        'source_url' => $normalizedUrl,
        'username' => $username,
        'email' => $email,
        'source_date' => $sourceDate,
        'date_source' => $dateSource,
        'source_domain' => $sourceDomain,
        'source_platform' => $sourcePlatform,
        'sentiment' => $sentiment,
        '_score' => $score,
    ];
}

function parse_duckduckgo_html(?string $html, string $hotelName, int $limit, string $sentiment): array
{
    if ($html === null || $html === '') {
        return [];
    }

    libxml_use_internal_errors(true);
    $doc = new DOMDocument();
    if (!@$doc->loadHTML($html)) {
        return [];
    }

    $xpath = new DOMXPath($doc);
    $nodes = $xpath->query('//div[contains(@class,"result")]');
    if ($nodes === false) {
        return [];
    }

    $results = [];
    foreach ($nodes as $node) {
        $aNode = $xpath->query('.//a[contains(@class,"result__a")]', $node);
        $sNode = $xpath->query('.//*[contains(@class,"result__snippet")]', $node);

        $url = '';
        $title = '';
        $snippet = '';

        if ($aNode !== false && $aNode->length > 0) {
            $link = $aNode->item(0);
            if ($link instanceof DOMElement) {
                $url = trim((string)$link->getAttribute('href'));
                $title = strip_text((string)($link->textContent ?? ''));
            }
        }

        if ($sNode !== false && $sNode->length > 0) {
            $snippetNode = $sNode->item(0);
            if ($snippetNode instanceof DOMNode) {
                $snippet = strip_text((string)($snippetNode->textContent ?? ''));
            }
        }

        // DuckDuckGo prints a result timestamp in the extras row when it has one.
        $hints = [];
        $tNode = $xpath->query('.//*[contains(@class,"result__timestamp")]', $node);
        if ($tNode !== false && $tNode->length > 0) {
            $stamp = strip_text((string)($tNode->item(0)->textContent ?? ''));
            if ($stamp !== '') {
                $snippet = $stamp . ' — ' . $snippet;
            }
        }

        $candidate = build_candidate($title, $snippet, $url, $hotelName, $hints, $sentiment);
        if ($candidate === null) {
            continue;
        }

        $results[] = $candidate;
        if (count($results) >= $limit) {
            break;
        }
    }

    return $results;
}

/** Bing wraps result links as /ck/a?...&u=a1<base64url(target)>; return the real target. */
function decode_bing_redirect(string $href): string
{
    $href = html_entity_decode($href, ENT_QUOTES | ENT_HTML5, 'UTF-8');
    if (stripos($href, 'bing.com/ck/a') === false) {
        return $href;
    }
    $query = (string)parse_url($href, PHP_URL_QUERY);
    $params = [];
    parse_str($query, $params);
    $u = (string)($params['u'] ?? '');
    if (strncmp($u, 'a1', 2) === 0) {
        $b64 = strtr(substr($u, 2), '-_', '+/');
        $decoded = base64_decode($b64 . str_repeat('=', (4 - strlen($b64) % 4) % 4), true);
        if (is_string($decoded) && preg_match('#^https?://#i', $decoded)) {
            return $decoded;
        }
    }
    return '';
}

/** Bing's normal results page — the most reliable engine for server-side requests. */
function parse_bing_html(?string $html, string $hotelName, int $limit, string $sentiment): array
{
    if ($html === null || $html === '' || stripos($html, 'b_algo') === false) {
        return [];
    }

    libxml_use_internal_errors(true);
    $doc = new DOMDocument();
    if (!@$doc->loadHTML('<?xml encoding="utf-8"?>' . $html)) {
        return [];
    }
    $xpath = new DOMXPath($doc);
    $nodes = $xpath->query('//li[contains(concat(" ", normalize-space(@class), " "), " b_algo ")]');
    if ($nodes === false) {
        return [];
    }

    $results = [];
    foreach ($nodes as $node) {
        $a = $xpath->query('.//h2//a', $node);
        if ($a === false || $a->length === 0 || !($a->item(0) instanceof DOMElement)) {
            continue;
        }
        $link = $a->item(0);
        $url = decode_bing_redirect(trim($link->getAttribute('href')));
        $title = strip_text((string)$link->textContent);

        $snippet = '';
        $p = $xpath->query('.//div[contains(@class,"b_caption")]//p | .//p[contains(@class,"b_lineclamp")] | .//div[contains(@class,"b_snippet")]', $node);
        if ($p !== false && $p->length > 0) {
            $snippet = strip_text((string)$p->item(0)->textContent);
        }

        // Bing shows the page/post date as a "news_dt" chip when it knows it;
        // that's the page's own date, so it counts as a snippet date.
        $d = $xpath->query('.//span[contains(@class,"news_dt")]', $node);
        if ($d !== false && $d->length > 0) {
            $stamp = strip_text((string)$d->item(0)->textContent);
            if ($stamp !== '' && stripos($snippet, $stamp) === false) {
                $snippet = $stamp . ' — ' . $snippet;
            }
        }

        $candidate = build_candidate($title, $snippet, $url, $hotelName, [], $sentiment);
        if ($candidate === null) {
            continue;
        }
        $results[] = $candidate;
        if (count($results) >= $limit) {
            break;
        }
    }
    return $results;
}

function parse_bing_rss(?string $xmlRaw, string $hotelName, int $limit, string $sentiment): array
{
    if ($xmlRaw === null || $xmlRaw === '') {
        return [];
    }

    libxml_use_internal_errors(true);
    $xml = simplexml_load_string($xmlRaw);
    if (!($xml instanceof SimpleXMLElement) || !isset($xml->channel->item)) {
        return [];
    }

    $results = [];
    foreach ($xml->channel->item as $item) {
        $title = strip_text((string)($item->title ?? ''));
        $snippet = strip_text((string)($item->description ?? ''));
        $url = trim((string)($item->link ?? ''));
        $pubDate = trim((string)($item->pubDate ?? ''));

        $hints = [];
        if ($pubDate !== '') {
            $hints['source_date'] = $pubDate;
        }

        $candidate = build_candidate($title, $snippet, $url, $hotelName, $hints, $sentiment);
        if ($candidate === null) {
            continue;
        }

        $results[] = $candidate;
        if (count($results) >= $limit) {
            break;
        }
    }

    return $results;
}

function parse_jina_duckduckgo(?string $body, string $hotelName, int $limit, string $sentiment): array
{
    if ($body === null || $body === '') {
        return [];
    }

    $lines = preg_split('/\R/', $body) ?: [];
    $results = [];

    for ($i = 0; $i < count($lines); $i++) {
        $line = trim((string)$lines[$i]);
        if (!preg_match('/^##\s+\[(.+?)\]\((https?:\/\/[^\)]+)\)$/u', $line, $matches)) {
            continue;
        }

        $title = strip_markdown_text((string)$matches[1]);
        $url = trim((string)$matches[2]);

        if ($title === '' || $url === '') {
            continue;
        }

        if (strpos($url, 'duckduckgo.com/y.js') !== false || stripos($title, ' ad') !== false) {
            continue;
        }

        $snippet = '';
        for ($j = $i + 1; $j < min($i + 12, count($lines)); $j++) {
            $candidateLine = trim((string)$lines[$j]);
            if ($candidateLine === '' || strpos($candidateLine, '## ') === 0) {
                continue;
            }

            if (preg_match('/^\!\[.*\]\(https?:\/\//u', $candidateLine)) {
                continue;
            }

            if (preg_match('/^\[(.+)\]\((https?:\/\/[^\)]+)\)$/u', $candidateLine, $lineMatch)) {
                $snippetText = strip_markdown_text((string)$lineMatch[1]);
                if ($snippetText !== '') {
                    // Skip plain host/path lines; prefer sentence-like snippet content.
                    if (preg_match('/^[a-z0-9.-]+\.[a-z]{2,}(?:\/[^\s]*)?$/i', $snippetText)) {
                        continue;
                    }

                    $snippet = $snippetText;
                    $wordCount = preg_match_all('/[\p{L}\p{N}]+/u', $snippetText, $tmp);
                    if ($wordCount >= 7 || score_sentiment_text($snippetText, $sentiment) > 0) {
                        break;
                    }
                }
            }
        }

        if ($snippet === '') {
            $snippet = $title;
        }

        $candidate = build_candidate($title, $snippet, $url, $hotelName, [], $sentiment);
        if ($candidate === null) {
            continue;
        }

        $results[] = $candidate;
        if (count($results) >= $limit) {
            break;
        }
    }

    return $results;
}

function build_social_feedback_queries(string $hotelName, string $location, string $sentiment): array
{
    $sentiment = normalize_sentiment($sentiment);
    $socialKeyword = $sentiment === 'negative' ? 'complaint' : 'review';

    return array_values(array_unique(array_filter([
        trim('site:facebook.com "' . $hotelName . '" ' . $socialKeyword),
        trim('site:tiktok.com "' . $hotelName . '"'),
        trim('site:instagram.com "' . $hotelName . '"'),
        trim('(site:x.com OR site:twitter.com) "' . $hotelName . '"'),
    ])));
}

function build_feedback_queries(string $hotelName, string $location, string $sentiment): array
{
    $sentiment = normalize_sentiment($sentiment);
    // Location comes from the form (prefilled from Hotel Settings). It used to be
    // followed by a hard-coded "Mangochi" query, which is the wrong town for one
    // of the two hotels this code serves.
    $baseQueries = [
        trim('"' . $hotelName . '" ' . $location . ' reviews'),
        trim('"' . $hotelName . '" tripadvisor OR booking.com OR google reviews'),
    ];

    $sentimentQueries = $sentiment === 'negative'
        ? [
            trim('"' . $hotelName . '" complaint OR disappointing OR "bad service"'),
            trim('"' . $hotelName . '" "not recommend" OR terrible OR dirty'),
        ]
        : [
            trim('"' . $hotelName . '" "highly recommend" OR excellent OR wonderful'),
            trim('"' . $hotelName . '" ' . $location . ' stay experience'),
        ];

    $queries = array_values(array_unique(array_filter(array_merge(
        build_social_feedback_queries($hotelName, $location, $sentiment),
        $baseQueries,
        $sentimentQueries
    ))));

    // 8 queries x 3 engines = 24 requests, all fetched in parallel.
    return array_slice($queries, 0, 8);
}

/**
 * Fill in / upgrade each candidate's date from the source page itself: the page
 * HTML and the r.jina.ai reader version are fetched in parallel, and any
 * publish-time metadata wins over a snippet or search-index date.
 */
function enrich_candidate_dates(array $candidates, int $timeout = 12): array
{
    $urls = [];
    foreach ($candidates as $idx => $c) {
        $src = (string)($c['source_url'] ?? '');
        if (!preg_match('#^https?://#i', $src)) {
            continue;
        }
        $urls[$idx . ':page'] = $src;
        // Backup rendition for pages that need JavaScript; harmless if blocked.
        $urls[$idx . ':reader'] = 'https://r.jina.ai/' . $src;
    }
    if (!$urls) {
        return $candidates;
    }

    $bodies = fetch_many($urls, $timeout);
    foreach ($candidates as $idx => &$c) {
        $date = extract_date_from_page((string)($bodies[$idx . ':page'] ?? ''));
        if ($date === '') {
            $date = extract_date_from_page((string)($bodies[$idx . ':reader'] ?? ''));
        }
        if ($date !== '') {
            $c['source_date'] = $date;
            $c['date_source'] = 'source page';
        }
    }
    unset($c);
    return $candidates;
}

function search_feedback_with_meta(string $hotelName, string $location, int $limit, string $sentiment = 'positive'): array
{
    $sentiment = normalize_sentiment($sentiment);
    $queries = build_feedback_queries($hotelName, $location, $sentiment);

    $pool = [];
    $fetchPerSource = max(6, $limit * 2);
    $meta = [
        'queries' => [],
        'source_totals' => [
            'duckduckgo' => 0,
            'bing_web' => 0,
            'bing_rss' => 0,
        ],
        'pool_size' => 0,
        'deduped_size' => 0,
    ];

    $urls = [];
    foreach ($queries as $qi => $query) {
        $urls[$qi . ':ddg'] = 'https://html.duckduckgo.com/html/?q=' . rawurlencode($query);
        $urls[$qi . ':bingweb'] = 'https://www.bing.com/search?q=' . rawurlencode($query) . '&setlang=en&count=30';
        $urls[$qi . ':bing'] = 'https://www.bing.com/search?q=' . rawurlencode($query) . '&format=rss&setlang=en';
    }
    $bodies = fetch_many($urls, 15);

    $ddgBlocked = 0;
    foreach ($queries as $qi => $query) {
        $ddgBody = $bodies[$qi . ':ddg'] ?? null;
        // DuckDuckGo answers some server requests with a "prove you're human" page.
        if (is_string($ddgBody) && stripos($ddgBody, 'bots use DuckDuckGo') !== false) {
            $ddgBody = null;
            $ddgBlocked++;
        }
        $ddg = parse_duckduckgo_html($ddgBody, $hotelName, $fetchPerSource, $sentiment);
        $bingWeb = parse_bing_html($bodies[$qi . ':bingweb'] ?? null, $hotelName, $fetchPerSource, $sentiment);
        $bing = parse_bing_rss($bodies[$qi . ':bing'] ?? null, $hotelName, $fetchPerSource, $sentiment);

        $pool = array_merge($pool, $bingWeb, $ddg, $bing);

        $meta['source_totals']['duckduckgo'] += count($ddg);
        $meta['source_totals']['bing_web'] += count($bingWeb);
        $meta['source_totals']['bing_rss'] += count($bing);
        $meta['queries'][] = [
            'query' => $query,
            'duckduckgo' => count($ddg),
            'bing_web' => count($bingWeb),
            'bing_rss' => count($bing),
        ];
    }
    $meta['duckduckgo_blocked'] = $ddgBlocked;

    $meta['pool_size'] = count($pool);

    // Deduplicate and keep best score per unique source/snippet key.
    $byKey = [];
    foreach ($pool as $row) {
        $key = md5(strtolower(rtrim((string)preg_replace('/[?#].*$/', '', (string)($row['source_url'] ?? '')), '/')));
        if (!isset($byKey[$key])) {
            $byKey[$key] = $row;
            continue;
        }
        $keep = (($row['_score'] ?? 0) > ($byKey[$key]['_score'] ?? 0)) ? $row : $byKey[$key];
        // Never lose a date another engine found for the same post.
        if (($keep['source_date'] ?? '') === '') {
            foreach ([$row, $byKey[$key]] as $other) {
                if (($other['source_date'] ?? '') !== '') {
                    $keep['source_date'] = $other['source_date'];
                    $keep['date_source'] = $other['date_source'] ?? '';
                    break;
                }
            }
        }
        $byKey[$key] = $keep;
    }

    $results = array_values($byKey);
    $meta['deduped_size'] = count($results);

    usort($results, static function (array $a, array $b): int {
        return (int)($b['_score'] ?? 0) <=> (int)($a['_score'] ?? 0);
    });

    $results = array_slice($results, 0, $limit);
    $results = enrich_candidate_dates($results);

    // Newest first within the shortlist once dates are known; undated last.
    usort($results, static function (array $a, array $b): int {
        return strcmp((string)($b['source_date'] ?? ''), (string)($a['source_date'] ?? ''));
    });

    // Hide internal score before response.
    foreach ($results as &$row) {
        unset($row['_score']);
    }

    return [
        'candidates' => $results,
        'sentiment' => $sentiment,
        'meta' => $meta,
    ];
}

function search_feedback(string $hotelName, string $location, int $limit, string $sentiment = 'positive'): array
{
    $result = search_feedback_with_meta($hotelName, $location, $limit, $sentiment);
    return is_array($result['candidates'] ?? null) ? $result['candidates'] : [];
}


// ───────────────────────────────────────────────────────────────────────────
// Official sources (optional API keys, saved encrypted in site settings).
// Search engines throttle and scramble server-side scraping within minutes, so
// the free web search is best-effort only; these APIs return real reviews and
// mentions with exact dates every time.
// ───────────────────────────────────────────────────────────────────────────

/**
 * Google reviews for the hotel via the Places API (New): finds the place (IDs
 * only = free, or a saved Place ID = no request), then reads its reviews (Google
 * exposes up to 5, "most relevant") with author, stars, text and exact post time.
 *
 * The one billable call (Place Details with reviews) is cached for 6 hours and
 * tallied per month so the admin can see usage against the free allowance.
 */
function google_places_reviews(string $apiKey, string $hotelName, string $location, string $sentiment, string $placeIdOverride = ''): array
{
    $place = review_google_find_place($apiKey, $hotelName, $location, $placeIdOverride, false);

    $cacheKey = 'google_place_details_' . md5($place['id']);
    $details = getCache($cacheKey, null);
    if (!is_array($details)) {
        [$st, $details, $err] = http_json(
            'https://places.googleapis.com/v1/places/' . rawurlencode($place['id']) . '?languageCode=en',
            ['X-Goog-Api-Key: ' . $apiKey, 'X-Goog-FieldMask: displayName,googleMapsUri,rating,userRatingCount,reviews']
        );
        if ($st !== 200 || !is_array($details)) {
            throw new RuntimeException('Google: ' . ($details['error']['message'] ?? ($err ?: ('HTTP ' . $st))));
        }
        review_google_count_call();
        setCache($cacheKey, $details, 6 * 3600);
    }

    $placeName = (string)($details['displayName']['text'] ?? $hotelName);
    $placeUri = (string)($details['googleMapsUri'] ?? '');
    $out = [];
    foreach ((array)($details['reviews'] ?? []) as $rv) {
        $rating = (int)($rv['rating'] ?? 0);
        if ($sentiment === 'negative' ? $rating > 3 : $rating < 4) {
            continue;
        }
        $text = trim((string)($rv['originalText']['text'] ?? ($rv['text']['text'] ?? '')));
        if ($text === '') {
            continue;
        }
        $author = trim((string)($rv['authorAttribution']['displayName'] ?? ''));
        $date = normalize_to_iso_date((string)($rv['publishTime'] ?? ''));
        // Each review gets a stable, unique source link so re-imports are caught.
        $uri = (string)($rv['googleMapsUri'] ?? '');
        if ($uri === '') {
            $uri = $placeUri . (strpos($placeUri, '#') === false ? '#' : '&') . 'review-' . substr(md5($author . '|' . ($rv['publishTime'] ?? '') . '|' . $text), 0, 12);
        }
        $out[] = [
            'title' => build_title_from_snippet((string)preg_replace('/([.!?]).*$/s', '$1', $text), $sentiment),
            'snippet' => $text,
            'source_url' => $uri,
            'username' => $author,
            'email' => '',
            'source_date' => $date,
            'date_source' => $date !== '' ? 'Google' : '',
            'source_domain' => 'google.com',
            'source_platform' => 'Google reviews',
            'sentiment' => $sentiment,
            'rating' => max(1, min(5, $rating)),
            'place' => $placeName,
        ];
    }
    return $out;
}

/** Web + social mentions via the Brave Search API, with each page's own date. */
function brave_search_mentions(string $apiKey, string $hotelName, string $location, string $sentiment, int $limit): array
{
    $queries = $sentiment === 'negative'
        ? ['"' . $hotelName . '" review complaint OR disappointing OR "bad service"', '"' . $hotelName . '" ' . $location . ' review']
        : ['"' . $hotelName . '" review', '"' . $hotelName . '" ' . $location . ' stay'];

    $out = [];
    foreach ($queries as $q) {
        [$st, $json, $err] = http_json(
            'https://api.search.brave.com/res/v1/web/search?count=20&q=' . rawurlencode($q),
            ['X-Subscription-Token: ' . $apiKey]
        );
        if ($st !== 200 || !is_array($json)) {
            throw new RuntimeException('Brave Search: ' . ($json['error']['detail'] ?? ($err ?: ('HTTP ' . $st))));
        }
        foreach ((array)($json['web']['results'] ?? []) as $r) {
            $snippet = strip_text((string)($r['description'] ?? ''));
            $c = build_candidate(strip_text((string)($r['title'] ?? '')), $snippet, (string)($r['url'] ?? ''), $hotelName, [], $sentiment);
            if ($c === null) {
                continue;
            }
            // page_age is the page's own publish date (ISO); "age" is human text.
            $date = normalize_to_iso_date((string)($r['page_age'] ?? ''));
            if ($date === '') {
                $date = parse_source_date_from_text((string)($r['age'] ?? ''));
            }
            if ($date !== '') {
                $c['source_date'] = $date;
                $c['date_source'] = 'source page';
            }
            $out[] = $c;
        }
    }
    return array_slice($out, 0, $limit * 2);
}

/** Record an imported review's original post date: metadata line + created_at. */
function stamp_review_source_date(PDO $pdo, int $reviewId, string $comment, string $date): void
{
    if (preg_match('/^Source Date\s*:.*$/mi', $comment)) {
        $comment = (string)preg_replace('/^Source Date\s*:.*$/mi', 'Source Date: ' . $date, $comment, 1);
    } else {
        $comment = (string)preg_replace('/^(Source\s*:\s*\S+)\s*$/mi', '$1' . "\n" . 'Source Date: ' . $date, $comment, 1);
    }
    $pdo->prepare('UPDATE reviews SET comment = ?, created_at = ?, updated_at = CURRENT_TIMESTAMP WHERE id = ?')
        ->execute([$comment, $date . ' 12:00:00', $reviewId]);
    rh_clear_review_caches();
}

/** Key for "is this the same post": URL without query/fragment noise, except Google review anchors. */
function candidate_url_key(string $url): string
{
    $url = strtolower(trim($url));
    if (strpos($url, '#review-') === false) {
        $url = (string)preg_replace('/[?#].*$/', '', $url);
    }
    return md5(rtrim($url, '/'));
}

// Allow the functions above to be loaded as a library (CLI checks) without
// running the endpoint.
if (defined('RH_SCRAPER_LIBRARY_ONLY')) {
    return;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    json_error('Method not allowed', 405);
}

if (!isset($_SESSION['admin_user_id'])) {
    json_error('Authentication required', 401);
}

if (!hasPermission((int)$_SESSION['admin_user_id'], 'reviews')) {
    json_error('Access denied', 403);
}

$input = read_json_input();
$action = (string)($input['action'] ?? '');
$csrf = (string)($input['_csrf'] ?? '');

if (!validateCsrfToken($csrf)) {
    json_error('Invalid CSRF token', 403);
}

// A search makes dozens of outbound requests. Release the session lock so the
// admin can keep using other pages meanwhile, and give the run enough time.
session_write_close();
@set_time_limit(90);

if ($action === 'search') {
    $hotelName = trim((string)($input['hotel_name'] ?? ''));
    $location = trim((string)($input['location'] ?? ''));
    $limit = (int)($input['limit'] ?? 8);
    $limit = max(3, min(20, $limit));
    $sentiment = normalize_sentiment((string)($input['sentiment'] ?? 'positive'));

    if ($hotelName === '') {
        json_error('Hotel name is required', 400);
    }

    // Which sources to use: everything, only Google reviews, or only web & social.
    $scope = (string)($input['sources'] ?? 'all');
    $scope = in_array($scope, ['all', 'google', 'web'], true) ? $scope : 'all';
    $useGoogle = $scope !== 'web';
    $useWeb = $scope !== 'google';

    $sources = [];
    $pool = [];

    $googleKey = $useGoogle ? review_source_secret('reviews_google_places_key') : '';
    if (!$useGoogle) {
        // not requested
    } elseif ($googleKey !== '') {
        try {
            $g = google_places_reviews($googleKey, $hotelName, $location, $sentiment, (string)getSetting('reviews_google_place_id', ''));
            $pool = array_merge($pool, $g);
            $sources[] = ['name' => 'Google reviews', 'status' => 'ok', 'count' => count($g)];
        } catch (Throwable $e) {
            $sources[] = ['name' => 'Google reviews', 'status' => 'error', 'count' => 0, 'detail' => $e->getMessage()];
        }
    } else {
        $sources[] = ['name' => 'Google reviews', 'status' => 'off', 'count' => 0, 'detail' => 'Add a Google Places API key in Hotel Settings → Review sources to import real Google reviews with dates.'];
    }

    $braveKey = $useWeb ? review_source_secret('reviews_brave_search_key') : '';
    if (!$useWeb) {
        // not requested
    } elseif ($braveKey !== '') {
        try {
            $b = brave_search_mentions($braveKey, $hotelName, $location, $sentiment, $limit);
            $pool = array_merge($pool, $b);
            $sources[] = ['name' => 'Brave web search', 'status' => 'ok', 'count' => count($b)];
        } catch (Throwable $e) {
            $sources[] = ['name' => 'Brave web search', 'status' => 'error', 'count' => 0, 'detail' => $e->getMessage()];
        }
    } else {
        $sources[] = ['name' => 'Brave web search', 'status' => 'off', 'count' => 0, 'detail' => 'Optional: a Brave Search API key (Hotel Settings → Review sources) gives reliable web & social results.'];
    }

    // Free search-engine scraping: best effort, often throttled.
    if ($useWeb) {
        $web = search_feedback_with_meta($hotelName, $location, $limit, $sentiment);
        $webCands = (array)($web['candidates'] ?? []);
        $pool = array_merge($pool, $webCands);
        $webMeta = (array)($web['meta'] ?? []);
        $sources[] = [
            'name' => 'Free web search',
            'status' => count($webCands) > 0 ? 'ok' : 'limited',
            'count' => count($webCands),
        // Engines rarely say they are throttling — they just return unrelated
        // pages — so an empty result can mean either. Say so honestly.
            'detail' => count($webCands) > 0 ? '' : ((int)($webMeta['duckduckgo_blocked'] ?? 0) > 0
                ? 'Search engines are blocking automated searches right now. Try again later, or connect Google reviews / Brave in Hotel Settings → Review sources for dependable results.'
                : 'Nothing matching the hotel name came back. Search engines also limit automated searches, so try again later or connect an API source in Hotel Settings → Review sources.'),
        ];
    }

    // Merge: one entry per post, keeping any date/rating another source had.
    $byKey = [];
    foreach ($pool as $c) {
        $k = candidate_url_key((string)($c['source_url'] ?? ''));
        if (!isset($byKey[$k])) {
            $byKey[$k] = $c;
            continue;
        }
        foreach (['source_date', 'date_source', 'rating', 'username'] as $f) {
            if (empty($byKey[$k][$f]) && !empty($c[$f])) {
                $byKey[$k][$f] = $c[$f];
            }
        }
    }
    $candidates = array_values($byKey);
    usort($candidates, static fn($a, $b) => strcmp((string)($b['source_date'] ?? ''), (string)($a['source_date'] ?? '')));
    $candidates = array_slice($candidates, 0, $limit + 5);

    // Flag ones already imported so the admin doesn't import twice.
    $chk = $pdo->prepare('SELECT id, status FROM reviews WHERE comment LIKE ? LIMIT 1');
    foreach ($candidates as &$c) {
        unset($c['_score']);
        $c['platform_key'] = rh_review_platform_key((string)($c['source_url'] ?? ''));
        $c['platform_label'] = rh_review_platform_label($c['platform_key']);
        $chk->execute(['%' . addcslashes('Source: ' . $c['source_url'], '%_\\') . '%']);
        if ($row = $chk->fetch(PDO::FETCH_ASSOC)) {
            $c['already_imported'] = ['id' => (int)$row['id'], 'status' => (string)$row['status']];
        }
    }
    unset($c);

    json_success([
        'candidates' => $candidates,
        'sentiment' => $sentiment,
        'dated' => count(array_filter($candidates, static fn($c) => ($c['source_date'] ?? '') !== '')),
        'sources' => $sources,
        'google_calls' => review_google_calls_this_month(),
        'google_free' => RH_GOOGLE_FREE_LOOKUPS_PER_MONTH,
    ], 'Search completed');
}

if ($action === 'import') {
    $candidate = isset($input['candidate']) && is_array($input['candidate']) ? $input['candidate'] : [];
    $username = trim((string)($input['username'] ?? ''));
    $emailInput = trim((string)($input['email'] ?? ''));
    $sourceDateInput = trim((string)($input['source_date'] ?? ''));
    // Imported feedback ALWAYS lands as 'pending' — the caller cannot request
    // 'approved'. This scraper imports **web-search snippets**, not verified
    // reviews: it queries DuckDuckGo/Bing/r.jina.ai, so a candidate is whatever
    // text a search engine returned. In practice that has meant Facebook page
    // posts and even a news item stored as a 5-star guest review, with
    // the rating chosen at import rather than derived from anything.
    // Nothing scraped reaches the public site until a human approves it in
    // admin/reviews.php. Owner decision, 2026-09-02.
    $status = 'pending';
    $rating = (int)($input['rating'] ?? ($candidate['rating'] ?? 5));
    $candidateSentiment = normalize_sentiment((string)($candidate['sentiment'] ?? ($input['sentiment'] ?? 'positive')));

    // Username is optional (owner decision, 2026-09-02). A search snippet often has
    // no attributable author — a page post, a listing, a news item — and forcing the
    // admin to invent one produced worse data than leaving it unattributed.
    // `reviews.guest_name` is NOT NULL, so fall back to a neutral label rather than
    // writing an empty string that renders as a blank byline.
    if ($username === '') {
        $username = 'Guest';
    }

    $rating = max(1, min(5, $rating));

    $snippet = trim((string)($candidate['snippet'] ?? ''));
    $sourceUrl = trim((string)($candidate['source_url'] ?? ''));
    $rawTitle = trim((string)($candidate['title'] ?? ''));
    $candidateEmail = trim((string)($candidate['email'] ?? ''));
    $candidateSourceDate = trim((string)($candidate['source_date'] ?? ''));

    if ($snippet === '' || $sourceUrl === '') {
        json_error('Candidate snippet and source_url are required', 400);
    }

    if (!preg_match('#^https?://#i', $sourceUrl)) {
        json_error('Candidate source_url must be an http(s) link', 400);
    }

    // Re-running a search returns the same snippets; without this check every
    // re-import stacked another copy into the moderation queue.
    $dupe = $pdo->prepare('SELECT id, status FROM reviews WHERE comment LIKE ? LIMIT 1');
    $dupe->execute(['%' . addcslashes('Source: ' . $sourceUrl, '%_\\') . '%']);
    if ($existing = $dupe->fetch(PDO::FETCH_ASSOC)) {
        json_error('Already imported (review #' . (int)$existing['id'] . ', ' . $existing['status'] . ')', 409);
    }

    $guestEmail = $emailInput !== '' ? strtolower($emailInput) : strtolower($candidateEmail);
    if ($guestEmail !== '' && !filter_var($guestEmail, FILTER_VALIDATE_EMAIL)) {
        json_error('Invalid guest email format', 400);
    }

    $sourceDateRaw = $sourceDateInput !== '' ? $sourceDateInput : $candidateSourceDate;
    $sourceDate = normalize_to_iso_date($sourceDateRaw);

    $title = $rawTitle !== '' ? $rawTitle : build_title_from_snippet($snippet, $candidateSentiment);
    if (mb_strlen($title, 'UTF-8') > 255) {
        $title = mb_substr($title, 0, 255, 'UTF-8');
    }

    $metaLines = ['Source: ' . $sourceUrl];
    if ($sourceDate !== '') {
        $metaLines[] = 'Source Date: ' . $sourceDate;
    }
    if ($guestEmail !== '') {
        $metaLines[] = 'User Email: ' . $guestEmail;
    }
    $comment = $snippet . "\n\n" . implode("\n", $metaLines);

    // The review is dated when it was originally posted (if known), so the site
    // and the moderation list show its real age rather than the import day.
    $createdAt = $sourceDate !== '' ? $sourceDate . ' 12:00:00' : date('Y-m-d H:i:s');

    $stmt = $pdo->prepare(
        'INSERT INTO reviews (
            booking_id, room_id, review_type, guest_name, guest_email,
            rating, title, comment,
            service_rating, cleanliness_rating, location_rating, value_rating,
            status, created_at
        ) VALUES (
            NULL, NULL, "general", ?, ?,
            ?, ?, ?,
            NULL, NULL, NULL, NULL,
            ?, ?
        )'
    );

    try {
        $stmt->execute([
            $username,
            $guestEmail,
            $rating,
            $title,
            $comment,
            $status,
            $createdAt,
        ]);
    } catch (PDOException $e) {
        error_log('Review scraper import failed: ' . $e->getMessage());
        json_error('Failed to import feedback', 500);
    }

    if (function_exists('clearCacheByPattern')) {
        clearCacheByPattern('hotel_reviews_*');
        clearCacheByPattern('testimonials_*');
    }

    json_success([
        'review_id' => (int)$pdo->lastInsertId(),
        'status' => $status,
        'email_saved' => $guestEmail !== '',
        'source_date_saved' => $sourceDate !== '',
        'source_date' => $sourceDate,
    ], 'Feedback imported successfully', 201);
}

if ($action === 'fetch_date') {
    // Look up the original post date for a review imported before dates were
    // captured, then stamp it on the review (comment metadata + created_at).
    $reviewId = (int)($input['review_id'] ?? 0);
    $stmt = $pdo->prepare('SELECT id, comment FROM reviews WHERE id = ?');
    $stmt->execute([$reviewId]);
    $review = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$review) {
        json_error('Review not found', 404);
    }

    $meta = rh_review_source_meta($review['comment'] ?? '');
    if ($meta['url'] === '') {
        json_error('This review was not imported from the web, so there is no source to date it from.', 400);
    }

    $found = enrich_candidate_dates([['source_url' => $meta['url']]], 15);
    $date = (string)($found[0]['source_date'] ?? '');
    if ($date === '') {
        json_error('No post date could be read automatically (the page may need a login, e.g. Facebook).', 422);
    }

    stamp_review_source_date($pdo, $reviewId, (string)$review['comment'], $date);
    json_success(['review_id' => $reviewId, 'source_date' => $date], 'Date found: ' . $date);
}

if ($action === 'set_date') {
    // Manual fallback when the source hides its date (e.g. Facebook needs a login).
    $reviewId = (int)($input['review_id'] ?? 0);
    $date = normalize_to_iso_date((string)($input['date'] ?? ''));
    if ($date === '' || !preg_match('/^\d{4}-\d{2}-\d{2}$/', trim((string)($input['date'] ?? '')))) {
        json_error('Enter the date as YYYY-MM-DD (not in the future).', 400);
    }
    $stmt = $pdo->prepare('SELECT id, comment FROM reviews WHERE id = ?');
    $stmt->execute([$reviewId]);
    $review = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$review) {
        json_error('Review not found', 404);
    }
    if (rh_review_source_meta($review['comment'] ?? '')['url'] === '') {
        json_error('Only imported reviews can be re-dated here.', 400);
    }
    stamp_review_source_date($pdo, $reviewId, (string)$review['comment'], $date);
    json_success(['review_id' => $reviewId, 'source_date' => $date], 'Review dated ' . $date);
}

json_error('Unsupported action', 400);

