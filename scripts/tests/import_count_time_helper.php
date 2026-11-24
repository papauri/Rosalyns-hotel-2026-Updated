<?php
if (PHP_SAPI !== 'cli') { http_response_code(403); exit("CLI only.\n"); }
/**
 * Shared by the stock-import tests (critical patch 2026-11-25): a stock import needs "When was this counted?" before it can be
 * imported. import_test_commit() gives a job that has none the database's NOW() as its count time (stock at T = the stock now, so
 * the older scenarios keep their meaning), then runs the real import_commit_stock(). A case that sets its own time keeps it.
 */
require_once dirname(__DIR__, 2) . '/includes/import/commit-stock.php';

if (!function_exists('import_test_commit')) {
    function import_test_set_counted_at(PDO $pdo, int $jobId, string $T, int $uid): array
    {
        return import_cont_save($pdo, $jobId, '', function (array &$a) use ($T, $uid) {
            $a['_counted_at'] = ['at' => $T, 'by' => $uid, 'set_at' => $T];
            return [];
        });
    }

    function import_test_commit(PDO $pdo, int $jobId, int $uid, array $opts = []): array
    {
        $st = $pdo->prepare('SELECT answers_json, status FROM import_jobs WHERE id = ?');
        $st->execute([$jobId]);
        $row = $st->fetch(PDO::FETCH_ASSOC);
        if ($row && in_array($row['status'], ['analysed', 'reviewing'], true)) {
            $a = json_decode((string)($row['answers_json'] ?? ''), true);
            if (import_count_time_get(is_array($a) ? $a : []) === null) {
                import_test_set_counted_at($pdo, $jobId, (string)$pdo->query('SELECT NOW()')->fetchColumn(), $uid);
            }
        }
        return import_commit_stock($pdo, $jobId, $uid, $opts);
    }
}
