<?php

/**
 * Admin Review Scraper API
 *
 * POST action=search  -> search public web snippets for positive/negative hotel feedback
 * POST action=import  -> import one candidate into reviews table as pending/approved
 */

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

header('Content-Type: application/json');

require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../config/security.php';
require_once __DIR__ . '/../../config/cache.php';
require_once __DIR__ . '/../includes/permissions.php';

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
    $currentYear = (int)date('Y');
    if ($year < 2000 || $year > ($currentYear + 1)) {
        return '';
    }

    return date('Y-m-d', $timestamp);
}

function parse_source_date_from_text(string $text): string
{
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

function is_relevant_to_hotel(string $title, string $snippet, string $url, string $hotelName): bool
{
    $terms = extract_hotel_terms($hotelName);
    if (empty($terms)) {
        return true;
    }

    $haystack = normalize_for_match($title . ' ' . $snippet . ' ' . $url);
    if ($haystack === '') {
        return false;
    }

    foreach ($terms as $term) {
        if ($term !== '' && strpos($haystack, $term) !== false) {
            return true;
        }
    }

    return false;
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

    $hintSourceDate = trim((string)($hints['source_date'] ?? ''));
    $sourceDate = normalize_to_iso_date($hintSourceDate);
    if ($sourceDate === '') {
        $sourceDate = parse_source_date_from_text($fullText);
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
        'source_domain' => $sourceDomain,
        'source_platform' => $sourcePlatform,
        'sentiment' => $sentiment,
        '_score' => $score,
    ];
}

function search_duckduckgo_candidates(string $query, string $hotelName, int $limit, string $sentiment): array
{
    $searchUrl = 'https://duckduckgo.com/html/?q=' . rawurlencode($query);
    $html = fetch_url($searchUrl, 15);
    if ($html === null) {
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

function search_bing_rss_candidates(string $query, string $hotelName, int $limit, string $sentiment): array
{
    $rssUrl = 'https://www.bing.com/search?q=' . rawurlencode($query) . '&format=rss';
    $xmlRaw = fetch_url($rssUrl, 15);
    if ($xmlRaw === null) {
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

function search_jina_duckduckgo_candidates(string $query, string $hotelName, int $limit, string $sentiment): array
{
    $proxyUrl = 'https://r.jina.ai/http://duckduckgo.com/html/?q=' . rawurlencode($query);
    $body = fetch_url($proxyUrl, 20);
    if ($body === null) {
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
    $socialKeyword = $sentiment === 'negative' ? 'complaint review' : 'review';
    $experienceKeyword = $sentiment === 'negative' ? 'bad service' : 'guest experience';

    return array_values(array_unique(array_filter([
        trim('site:tiktok.com "' . $hotelName . '" ' . $location . ' ' . $socialKeyword),
        trim('site:facebook.com "' . $hotelName . '" ' . $location . ' ' . $socialKeyword),
        trim('site:instagram.com "' . $hotelName . '" ' . $location . ' ' . $socialKeyword),
        trim('site:x.com "' . $hotelName . '" ' . $location . ' ' . $socialKeyword),
        trim('site:twitter.com "' . $hotelName . '" ' . $location . ' ' . $socialKeyword),
        trim($hotelName . ' ' . $location . ' tiktok ' . $experienceKeyword),
        trim($hotelName . ' ' . $location . ' facebook ' . $experienceKeyword),
        trim($hotelName . ' ' . $location . ' instagram ' . $experienceKeyword),
        trim($hotelName . ' ' . $location . ' x ' . $experienceKeyword),
    ])));
}

function build_feedback_queries(string $hotelName, string $location, string $sentiment): array
{
    $sentiment = normalize_sentiment($sentiment);
    $baseQueries = [
        trim('"' . $hotelName . '" ' . $location . ' reviews'),
        trim('"' . $hotelName . '" review'),
        trim($hotelName . ' ' . $location . ' reviews'),
        trim($hotelName . ' Mangochi reviews'),
        trim($hotelName . ' Malawi review'),
    ];

    $sentimentQueries = $sentiment === 'negative'
        ? [
            trim($hotelName . ' ' . $location . ' negative reviews'),
            trim($hotelName . ' complaints'),
            trim($hotelName . ' bad service review'),
            trim($hotelName . ' disappointing stay'),
            trim($hotelName . ' not recommend review'),
        ]
        : [
            trim($hotelName . ' ' . $location . ' positive feedback'),
            trim($hotelName . ' excellent service review'),
            trim($hotelName . ' highly recommend stay'),
        ];

    $socialQueries = build_social_feedback_queries($hotelName, $location, $sentiment);
    $queries = array_values(array_unique(array_filter(array_merge($socialQueries, $baseQueries, $sentimentQueries))));

    // Keep runtime predictable while still covering social + web-wide sources.
    return array_slice($queries, 0, 16);
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
            'jina_duckduckgo' => 0,
            'bing_rss' => 0,
        ],
        'pool_size' => 0,
        'deduped_size' => 0,
    ];

    foreach ($queries as $query) {
        $ddg = search_duckduckgo_candidates($query, $hotelName, $fetchPerSource, $sentiment);
        $jina = search_jina_duckduckgo_candidates($query, $hotelName, $fetchPerSource, $sentiment);
        $bing = search_bing_rss_candidates($query, $hotelName, $fetchPerSource, $sentiment);

        $pool = array_merge($pool, $ddg, $jina, $bing);

        $meta['source_totals']['duckduckgo'] += count($ddg);
        $meta['source_totals']['jina_duckduckgo'] += count($jina);
        $meta['source_totals']['bing_rss'] += count($bing);
        $meta['queries'][] = [
            'query' => $query,
            'duckduckgo' => count($ddg),
            'jina_duckduckgo' => count($jina),
            'bing_rss' => count($bing),
        ];
    }

    $meta['pool_size'] = count($pool);

    // Deduplicate and keep best score per unique source/snippet key.
    $byKey = [];
    foreach ($pool as $row) {
        $key = md5(($row['source_url'] ?? '') . '|' . ($row['snippet'] ?? ''));
        if (!isset($byKey[$key]) || (($row['_score'] ?? 0) > ($byKey[$key]['_score'] ?? 0))) {
            $byKey[$key] = $row;
        }
    }

    $results = array_values($byKey);
    $meta['deduped_size'] = count($results);

    usort($results, static function (array $a, array $b): int {
        return (int)($b['_score'] ?? 0) <=> (int)($a['_score'] ?? 0);
    });

    $results = array_slice($results, 0, $limit);

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

if ($action === 'search') {
    $hotelName = trim((string)($input['hotel_name'] ?? ''));
    $location = trim((string)($input['location'] ?? ''));
    $limit = (int)($input['limit'] ?? 8);
    $limit = max(3, min(20, $limit));
    $sentiment = normalize_sentiment((string)($input['sentiment'] ?? 'positive'));
    $debug = !empty($input['debug']);

    if ($hotelName === '') {
        json_error('Hotel name is required', 400);
    }

    if ($debug) {
        $result = search_feedback_with_meta($hotelName, $location, $limit, $sentiment);
        json_success([
            'candidates' => (array)($result['candidates'] ?? []),
            'sentiment' => (string)($result['sentiment'] ?? $sentiment),
            'debug' => (array)($result['meta'] ?? []),
        ], 'Search completed');
    }

    $candidates = search_feedback($hotelName, $location, $limit, $sentiment);
    json_success([
        'candidates' => $candidates,
        'sentiment' => $sentiment,
    ], 'Search completed');
}

if ($action === 'import') {
    $candidate = isset($input['candidate']) && is_array($input['candidate']) ? $input['candidate'] : [];
    $username = trim((string)($input['username'] ?? ''));
    $emailInput = trim((string)($input['email'] ?? ''));
    $sourceDateInput = trim((string)($input['source_date'] ?? ''));
    $status = trim((string)($input['status'] ?? 'pending'));
    $rating = (int)($input['rating'] ?? 5);
    $candidateSentiment = normalize_sentiment((string)($candidate['sentiment'] ?? ($input['sentiment'] ?? 'positive')));

    if ($username === '') {
        json_error('Username is required for imported feedback', 400);
    }

    if (!in_array($status, ['pending', 'approved'], true)) {
        json_error('Status must be pending or approved', 400);
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

    $stmt = $pdo->prepare(
        'INSERT INTO reviews (
            booking_id, room_id, review_type, guest_name, guest_email,
            rating, title, comment,
            service_rating, cleanliness_rating, location_rating, value_rating,
            status
        ) VALUES (
            NULL, NULL, "general", ?, ?,
            ?, ?, ?,
            NULL, NULL, NULL, NULL,
            ?
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
    ], 'Feedback imported successfully', 201);
}

json_error('Unsupported action', 400);

