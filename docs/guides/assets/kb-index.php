<?php

/**
 * docs/guides/assets/kb-index.php
 * Knowledge base endpoint for the staff guides (see includes/guide-knowledge-base.php).
 *
 *   GET kb-index.php?q=<text>   ranked search results: {success, q, partial, results:[...]}
 *   GET kb-index.php?faq=1      every FAQ-style entry (how-tos, problems, curated FAQ) for faq.html
 *
 * Reads only the guide files themselves, which are already public; no database access.
 */

declare(strict_types=1);

require_once __DIR__ . '/../../../includes/guide-knowledge-base.php';

header('Content-Type: application/json; charset=utf-8');
header('X-Content-Type-Options: nosniff');

$flags = JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE;

try {
    if (isset($_GET['q'])) {
        header('Cache-Control: no-store');
        $q = mb_substr(trim((string)$_GET['q']), 0, 120);
        $limit = max(1, min(30, (int)($_GET['limit'] ?? 12)));
        if (mb_strlen($q) < 2) {
            echo json_encode(['success' => true, 'q' => $q, 'partial' => false, 'results' => []], $flags);
            exit;
        }
        $found = rh_kb_search($q, $limit);
        $results = array_map(static function (array $e): array {
            return [
                'type' => $e['type'], 'guide' => $e['guide'], 'file' => $e['file'],
                'title' => $e['title'], 'parent' => $e['parent'] ?? '', 'snippet' => $e['snippet'], 'url' => $e['url'],
                // The full answer, so the best result can be shown on the spot.
                'steps' => $e['steps'] ?? [], 'why' => $e['why'] ?? '', 'fix' => $e['fix'] ?? '',
                'answer' => in_array($e['type'], ['faq', 'hint'], true) ? mb_substr($e['text'], 0, 700)
                    : (in_array($e['type'], ['page', 'permission', 'role', 'module'], true) ? rh_kb_snippet($e, []) : ''),
            ];
        }, $found['results']);
        echo json_encode(['success' => true, 'q' => $q, 'partial' => $found['partial'], 'corrected' => $found['corrected'], 'results' => $results], $flags);
        exit;
    }

    $index = rh_kb_index();
    $etag = '"kb-' . $index['v'] . '"';
    header('ETag: ' . $etag);
    header('Cache-Control: public, max-age=300');
    if (trim((string)($_SERVER['HTTP_IF_NONE_MATCH'] ?? '')) === $etag) {
        http_response_code(304);
        exit;
    }

    // FAQ payload: how-to sections (with their steps), problems and curated questions.
    $faq = [];
    foreach ($index['entries'] as $e) {
        $isHowTo = $e['type'] === 'section' && preg_match('/^(how to|what (happens|to do)|checking)\b/i', $e['title']);
        if (($e['type'] === 'section' && !$isHowTo) || !in_array($e['type'], ['section', 'problem', 'faq'], true)) {
            continue; // the FAQ lists how-tos, problems and curated questions; the system map has the rest
        }
        $faq[] = [
            'type' => $isHowTo ? 'howto' : $e['type'],
            'guide' => $e['guide'], 'file' => $e['file'], 'group' => $e['group'] ?? '',
            'title' => $e['title'], 'parent' => $e['parent'] ?? '',
            'steps' => $e['steps'] ?? [], 'why' => $e['why'] ?? '', 'fix' => $e['fix'] ?? '',
            'text' => $e['type'] === 'faq' ? '' : mb_substr($e['text'], 0, 360),
            'url' => rh_kb_entry_url($e),
        ];
    }
    $guides = array_values(array_filter($index['guides'], static function (array $g): bool {
        return $g['file'] !== 'faq.html';
    }));
    echo json_encode(['success' => true, 'v' => $index['v'], 'guides' => $guides, 'faq' => $faq], $flags);
} catch (Throwable $e) {
    error_log('kb-index: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => 'The guide search is not available right now.'], $flags);
}
