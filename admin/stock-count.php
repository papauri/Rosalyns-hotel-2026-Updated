<?php

/**
 * Stock Count & Variance Reconciliation
 *
 * Anti-theft enforcement: every disappearance of physical stock MUST tie to a
 * recorded sale, wastage, expiry, or an explicit variance from a manager-approved
 * stock count. This page is the count + reconciliation workflow.
 *
 * Workflow:
 *   1. Any stock-write user starts a count (full / category / spot scope).
 *   2. They enter the actual physical quantity per ingredient.
 *   3. On submit, the system computes variance vs the running system quantity.
 *      Surpluses are always allowed (good news). Shortages above thresholds
 *      require a reason code AND admin/manager approval.
 *   4. On approval, variance is recomputed against expected stock AT COUNT TIME
 *      (snapshot + stock_adjustments movements up to the line's counted_at), then
 *      stock_adjustments rows of source_type='variance' are written and the LIVE
 *      quantity is adjusted by that delta (so post-count movements are preserved).
 *      Shortages over the thresholds need an approver other than the counter.
 *      Approver name and time are recorded forever.
 */
require_once 'admin-init.php';
require_once '../includes/alert.php';
require_once __DIR__ . '/../includes/form-validation.php';
require_once __DIR__ . '/../includes/stock-loss.php';
require_once __DIR__ . '/includes/stock-count-approval-lib.php';

$user = [
    'id'        => $_SESSION['admin_user_id'],
    'username'  => $_SESSION['admin_username'],
    'role'      => $_SESSION['admin_role'],
    'full_name' => $_SESSION['admin_full_name'],
];
/* Who can approve or reject a submitted count: the "Approve stock counts" permission (admin and manager by
 * default; any user can be given it in User Management). Not the same as who may open the page. */
$canApprove = hasPermission((int)$user['id'], 'stock_count_approve');
/* Count measures (Step U1): set an item measure from a count line = Stock Items or approve permission; correct an
 * APPROVED count that was counted in the smaller measure = both. */
$canMeasure = $canApprove || hasPermission((int)$user['id'], 'stock_management');
$canFix = $canApprove && hasPermission((int)$user['id'], 'stock_management');
$scMeasureReady = rh_stock_measure_ready($pdo); // migration 088 has run
/* Two-person rule for stock counts: ON unless a global admin switched it off in Hotel Settings
 * (then whoever counted can approve or reject their own count). */
$secondRuleOn = rh_sca_second_approver_required();

$message = '';
$error = '';
$currency = getSetting('currency_symbol');
$alertRaw = getSetting('stock_variance_alert_pct', null); // 0 is allowed (alert on any shortage worth the minimum)
$alertPct = ($alertRaw === null || $alertRaw === false || trim((string)$alertRaw) === '') ? 2.0 : (float)$alertRaw;
$fmtPct   = static fn(float $p): string => rtrim(rtrim(number_format($p, 2), '0'), '.');
$blockPct = (float)(getSetting('stock_variance_block_pct') ?: 10);
$minCost  = (float)(getSetting('stock_variance_min_cost') ?: 5000);

if (!ensureStockTablesExist()) {
    $error = 'Stock counts can’t be used yet because the stock tables have not been created. Ask your administrator to run the stock set-up (admin/migrations/015_stock_management.php), then reload this page.';
}

/* "Differences from the uploaded file" of an opening count filed by Import (owner 2026-11-23): the download. Whoever can open this page can
 * open the count, so the same people can download its list (frozen at the import, stored with the import: includes/import/diff-report.php). */
if (($_SERVER['REQUEST_METHOD'] ?? '') === 'GET' && isset($_GET['diff_export'], $_GET['id'])) {
    require_once __DIR__ . '/../includes/import/diff-report.php';
    $dxSt = $pdo->prepare('SELECT * FROM stock_counts WHERE id = ?');
    $dxSt->execute([(int)$_GET['id']]);
    $dxCount = $dxSt->fetch(PDO::FETCH_ASSOC) ?: null;
    $dx = $dxCount ? import_diff_for_count($pdo, $dxCount) : null;
    if (!$dx) {
        while (ob_get_level() > 0) { @ob_end_clean(); }
        http_response_code(404);
        header('Content-Type: text/plain; charset=utf-8');
        exit('This count has no list of differences from an uploaded file.');
    }
    $dxFile = import_diff_export($dx['report'], ((string)$_GET['diff_export'] === 'csv') ? 'csv' : 'xlsx');
    if (empty($dxFile['ok'])) {
        http_response_code(422);
        exit((string)$dxFile['error']);
    }
    logActivity((int)$user['id'], 'import_diff_export', 'Differences from the uploaded file downloaded from count ' . displayCountReference((string)$dxCount['reference']) . ' (#' . (int)$dxCount['id'] . ', import #' . (int)$dx['job']['id'] . ').');
    import_diff_send($dxFile);
}

/* Helper: generate a count reference */
function generateCountReference(): string
{
    return date('Ymd') . '-' . substr(strtoupper(bin2hex(random_bytes(2))), 0, 4);
}

function displayCountReference(?string $reference): string
{
    $ref = trim((string)$reference);
    if ($ref === '') {
        return '—';
    }
    // Keep legacy records readable but remove the old SC- prefix in UI.
    return (string)preg_replace('/^SC-/i', '', $ref);
}

/* Plain label for a count status. */
function scStatusLabel(string $status): string
{
    $labels = ['draft' => 'Being counted', 'submitted' => 'Waiting for approval', 'approved' => 'Approved', 'rejected' => 'Rejected'];
    return $labels[$status] ?? $status;
}

/* What was counted, in plain words: "Everything", "Category: Meat", "3 chosen items". */
function scScopeLabel(array $c): string
{
    $scope = (string)($c['scope'] ?? '');
    $val = trim((string)($c['scope_value'] ?? ''));
    if ($scope === 'full') return 'Everything';
    if ($scope === 'category') return $val !== '' ? 'Category: ' . $val : 'One category';
    $n = $val === '' ? 0 : count(array_filter(explode(',', $val), 'strlen'));
    return $n > 0 ? $n . ' chosen item' . ($n === 1 ? '' : 's') : 'Chosen items';
}

/* The same, as a phrase in a sentence: "everything", "the category Meat", "3 chosen items". */
function scScopePhrase(array $c): string
{
    $scope = (string)($c['scope'] ?? '');
    $val = trim((string)($c['scope_value'] ?? ''));
    if ($scope === 'full') return 'everything';
    if ($scope === 'category' && $val !== '') return 'the category ' . $val;
    return mb_strtolower(scScopeLabel($c), 'UTF-8');
}

/* Who may delete a count (it never changed stock either way: only approval writes stock movements):
 *  - a DRAFT (still being counted): the person who started it (a count begun by mistake), an administrator,
 *    or an approver;
 *  - a REJECTED count: an administrator, or an approver who did not count it (so nobody can quietly remove
 *    their own rejected count);
 *  - a count waiting for approval or approved: nobody. */
function scMayDeleteCount(array $c, array $user, bool $canApprove): bool
{
    $status = (string)($c['status'] ?? 'rejected');
    if (!in_array($status, ['rejected', 'draft'], true)) return false;
    if (($user['role'] ?? '') === 'admin') return true;
    if ($status === 'draft' && (int)$c['counted_by'] === (int)$user['id']) return true;
    return $canApprove && (int)$c['counted_by'] !== (int)$user['id'];
}

/* Feature-detect stock_count_lines.counted_at / counted_by (migration 062). Cached per request. */
function scHasLineCol(PDO $pdo, string $col): bool
{
    static $cache = [];
    if (!array_key_exists($col, $cache)) {
        /* information_schema, not SHOW COLUMNS ... LIKE ?: a placeholder there is a syntax error under
         * real (non-emulated) prepares, which would fatal every count page. */
        $q = $pdo->prepare("SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?");
        $q->execute(["stock_count_lines", $col]);
        $cache[$col] = ((int)$q->fetchColumn() > 0);
    }
    return $cache[$col];
}

/* stock_counts.is_opening: marks the one-off OPENING STOCK count (additive column, added on first use).
 * Opening counts need unit costs on every counted line and a different approver than the counter. */
function scHasOpeningCol(PDO $pdo): bool
{
    static $has = null;
    if ($has === null) {
        $q = $pdo->prepare("SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'stock_counts' AND COLUMN_NAME = 'is_opening'");
        $q->execute();
        $has = ((int)$q->fetchColumn() > 0);
        if (!$has) {
            try {
                $pdo->exec("ALTER TABLE stock_counts ADD COLUMN is_opening TINYINT(1) NOT NULL DEFAULT 0");
                $q->execute();
                $has = ((int)$q->fetchColumn() > 0);
            } catch (Throwable $e) {
                error_log('stock-count: could not add is_opening column: ' . $e->getMessage());
            }
        }
    }
    return $has;
}

/* stock_counts.snapshot_at (migration 062) cached per request; falls back to created_at when absent. */
function scHasSnapshotCol(PDO $pdo): bool
{
    static $has = null;
    if ($has === null) {
        $has = $pdo->query("SHOW COLUMNS FROM stock_counts LIKE 'snapshot_at'")->rowCount() > 0;
    }
    return $has;
}

function scSnapshotStart(array $count): string
{
    return (string)(!empty($count['snapshot_at']) ? $count['snapshot_at'] : $count['created_at']);
}

/* A line is "counted" when it has counted_at (after migration). Before the migration an untouched
 * line is stored as actual 0 / variance 0 against a non-zero snapshot, so that shape = not counted. */
function scLineIsCounted(array $ln, bool $hasCol): bool
{
    if ($hasCol) {
        return !empty($ln['counted_at']);
    }
    return abs((float)$ln['actual_quantity']) >= 0.00005
        || abs((float)$ln['variance']) >= 0.00005
        || abs((float)$ln['system_quantity']) < 0.00005;
}

/* SQL fragment (static) selecting lines not yet counted. */
function scUncountedSql(bool $hasCol): string
{
    return $hasCol
        ? 'scl.counted_at IS NULL'
        : '(ABS(scl.actual_quantity) < 0.00005 AND ABS(scl.variance) < 0.00005 AND ABS(scl.system_quantity) >= 0.00005)';
}

/* @return array{0:int,1:string[]} number of uncounted lines + first few ingredient names */
function scUncountedLines(PDO $pdo, int $countId, bool $hasCol): array
{
    $q = $pdo->prepare("SELECT i.name FROM stock_count_lines scl INNER JOIN stock_ingredients i ON i.id = scl.ingredient_id WHERE scl.count_id = ? AND " . scUncountedSql($hasCol) . " ORDER BY i.name");
    $q->execute([$countId]);
    $names = $q->fetchAll(PDO::FETCH_COLUMN);
    return [count($names), array_slice($names, 0, 6)];
}

/* Every line of a count with no reading yet (ingredient names, A to Z). These are the lines a submit leaves out. */
function scUncountedAllNames(PDO $pdo, int $countId, bool $hasCol): array
{
    $q = $pdo->prepare("SELECT i.name FROM stock_count_lines scl INNER JOIN stock_ingredients i ON i.id = scl.ingredient_id WHERE scl.count_id = ? AND " . scUncountedSql($hasCol) . " ORDER BY i.name");
    $q->execute([$countId]);
    return array_map('strval', $q->fetchAll(PDO::FETCH_COLUMN));
}

/* "7 items had no reading and were left out of this count: A, B, … and 3 more. They can be counted ..." Plain text; '' when nothing was left out. */
function scLeftOutSentence(array $names): string
{
    $n = count($names);
    if ($n === 0) return '';
    $shown = array_slice($names, 0, 8);
    $more = $n - count($shown);
    return $n . ' item' . ($n === 1 ? ' had' : 's had') . ' no reading and ' . ($n === 1 ? 'was' : 'were') . ' left out of this count: ' . implode(', ', $shown) . ($more > 0 ? ' and ' . $more . ' more' : '')
        . '. ' . ($n === 1 ? 'It' : 'They') . ' can be counted in a later count once you have ' . ($n === 1 ? 'it' : 'them') . '.';
}

/* Heading of the result banner. Errors are named after the action that failed; a success is named from its own message
 * (it arrives through the session after a redirect, so the action is no longer known). Plain text. */
function scBannerTitle(string $text, bool $isError, string $action): string
{
    if ($isError) {
        $byAction = [
            'submit_count' => 'Can’t submit yet', 'approve_count' => 'Not approved', 'reject_count' => 'Can’t send it back',
            'reopen_count' => 'Can’t count again', 'delete_count' => 'Can’t delete', 'start_count' => 'Can’t start the count',
            'save_lines' => 'Readings not saved', 'remind_count' => 'Reminder not sent',
        ];
        return $byAction[$action] ?? 'Something needs fixing';
    }
    if (strpos($text, 'Not approved') === 0) return 'Not approved';
    if (strpos($text, 'submitted and approved') !== false) return 'Count submitted and approved';
    if (strpos($text, ' submitted') !== false) return 'Count submitted';
    if (strpos($text, 'Count readings saved') === 0) return 'Readings saved';
    if (strpos($text, ' approved.') !== false) return 'Count approved';
    if (strpos($text, 'rejected and sent back') !== false) return 'Count sent back';
    if (strpos($text, 'reopened') !== false) return 'Count reopened';
    if (strpos($text, ' deleted.') !== false) return 'Count deleted';
    if (strpos($text, 'already started') !== false) return 'Count opened';
    return 'Done';
}

/* Wording used by the refusals below. Plain text only: no markup. */
const SC_PERM_NAME = '“Approve stock counts”';
const SC_ASK_PERM = 'an administrator can give it to you in User Management';
const SC_RULE_WHERE = 'Hotel Settings → Restaurant & stock';

/* The status a count has right now ('' when it does not exist) - only used to say WHY a refusal happened. */
function scCountStatusNow(PDO $pdo, int $countId): string
{
    try {
        $q = $pdo->prepare('SELECT status FROM stock_counts WHERE id = ?');
        $q->execute([$countId]);
        $s = $q->fetchColumn();
        return $s === false ? '' : (string)$s;
    } catch (Throwable $e) {
        return '';
    }
}

/* Where a count is now, in a few plain words, for "this count can't be ..." refusals. */
function scStatusNowPhrase(string $status): string
{
    switch ($status) {
        case 'draft':     return 'it is still being counted';
        case 'submitted': return 'it is waiting for approval';
        case 'approved':  return 'it has already been approved';
        case 'rejected':  return 'it was rejected';
        default:          return 'it was not found (it may have been deleted)';
    }
}

/* $for: 'submit' (the counter can fix it) or 'approve' (an approver can only send it back). */
function scUncountedMessage(int $n, array $names, string $for = 'submit'): string
{
    $more = $n - count($names);
    $list = implode(', ', $names) . ($more > 0 ? ' and ' . $more . ' more' : '');
    $what = $n . ' line' . ($n === 1 ? ' is' : 's are') . ' not counted yet (' . $list . ')';
    if ($for === 'approve') {
        return 'This count can’t be approved: ' . $what . '. Press “Reject count” so the counter can finish those lines, then submit it again.';
    }
    return 'This count can’t be submitted yet: ' . $what . '. Type a reading on each (0 if there is none), and use the “Not counted only” filter to find them.';
}

/* Net stock movement for an ingredient inside (from, to] - sales, receipts, wastage, other counts. */
function scMovementsBetween(PDO $pdo, int $ingredientId, string $from, string $to): float
{
    static $q = null;
    if ($q === null) {
        $q = $pdo->prepare("SELECT COALESCE(SUM(quantity_change), 0) FROM stock_adjustments WHERE ingredient_id = ? AND created_at > ? AND created_at <= ?");
    }
    $q->execute([$ingredientId, $from, $to]);
    return (float)$q->fetchColumn();
}

/* A shortage that needs a second pair of eyes: cost AND percentage over the configured thresholds. */
function scOverThreshold(float $variance, float $varCost, float $base, float $blockPct, float $minCost): bool
{
    if ($variance >= 0 || abs($varCost) < $minCost) {
        return false;
    }
    $pct = $base > 0 ? abs($variance) / $base * 100 : 100;
    return $pct >= $blockPct;
}

if (!$error) {
    scHasOpeningCol($pdo);
}

/* Counted lines of an opening count that would add stock but have no unit cost (> 0). Names, first few. */
function scOpeningUncostedLines(PDO $pdo, int $countId): array
{
    $q = $pdo->prepare("SELECT i.name FROM stock_count_lines scl INNER JOIN stock_ingredients i ON i.id = scl.ingredient_id
                        WHERE scl.count_id = ? AND scl.actual_quantity > 0.00005 AND scl.cost_per_unit <= 0.00001 ORDER BY i.name");
    $q->execute([$countId]);
    return $q->fetchAll(PDO::FETCH_COLUMN);
}

/* Items of $ingredientIds that are already in ANOTHER opening count that is still open (being counted or
 * waiting for approval). An item may be in only one open opening count at a time, or approving both would add
 * its opening stock twice. @return array<int,array{name:string,reference:string}> */
function scOpeningOverlap(PDO $pdo, array $ingredientIds, int $excludeCountId = 0): array
{
    $ingredientIds = array_values(array_unique(array_map('intval', $ingredientIds)));
    $found = [];
    foreach (array_chunk($ingredientIds, 400) as $chunk) {
        $ph = implode(',', array_fill(0, count($chunk), '?'));
        $q = $pdo->prepare("SELECT DISTINCT i.name, sc.reference
                            FROM stock_count_lines l
                            INNER JOIN stock_counts sc ON sc.id = l.count_id
                            INNER JOIN stock_ingredients i ON i.id = l.ingredient_id
                            WHERE sc.is_opening = 1 AND sc.status IN ('draft','submitted') AND sc.id <> ? AND l.ingredient_id IN ($ph)
                            ORDER BY i.name");
        $q->execute(array_merge([$excludeCountId], $chunk));
        foreach ($q->fetchAll(PDO::FETCH_ASSOC) as $r) {
            $found[] = ['name' => (string)$r['name'], 'reference' => (string)$r['reference']];
        }
    }
    return $found;
}

/* Plain refusal text naming the items and the count(s) they are already in. */
function scOpeningOverlapMessage(array $found, string $did): string
{
    $names = array_values(array_unique(array_column($found, 'name')));
    $refs = array_values(array_unique(array_map('displayCountReference', array_column($found, 'reference'))));
    $what = 'The opening count was not ' . $did . ': ' . count($names) . ' of these items ' . (count($names) === 1 ? 'is' : 'are') . ' already in another opening stock count that is still open ('
        . implode(', ', array_slice($names, 0, 6)) . (count($names) > 6 ? ' and ' . (count($names) - 6) . ' more' : '')
        . '; count ' . implode(', ', array_slice($refs, 0, 3)) . (count($refs) > 3 ? ' and others' : '')
        . '). An item can be in only one open opening count at a time. ';
    return $what . ($did === 'reopened'
        ? 'Finish or delete that other count first, or delete this rejected count and start a new one without those items.'
        : 'Finish or delete that other count first, or leave those items out by choosing “Only the items I choose”.');
}

/* One opening-count start / reopen at a time, so two people cannot both pass the overlap check. */
function scTakeOpeningLock(PDO $pdo): string
{
    $name = substr('rh_stock_count_opening_' . (defined('DB_NAME') ? DB_NAME : 'db'), 0, 64);
    if ((int)$pdo->query('SELECT GET_LOCK(' . $pdo->quote($name) . ', 10)')->fetchColumn() !== 1) {
        throw new RuntimeException('Another opening count is being started or reopened at this moment, so nothing was saved. Wait a few seconds and try again.');
    }
    return $name;
}

/* Opening-stock batches waiting for an opening count to be approved (stock_opening_batches, created by
 * scripts/import_stock_sheet.php). Each carries its own expiry date; approval turns them into real batches.
 * Returns [] when the table does not exist or the ingredient has none. Earliest expiry first (FIFO). */
function scPendingOpeningBatches(PDO $pdo, int $countId, int $ingredientId): array
{
    static $has = null;
    if ($has === null) {
        $has = (int)$pdo->query("SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'stock_opening_batches'")->fetchColumn() > 0;
    }
    if (!$has) {
        return [];
    }
    $q = $pdo->prepare("SELECT id, batch_label, quantity, expiry_date FROM stock_opening_batches
                        WHERE count_id = ? AND ingredient_id = ? AND applied_batch_id IS NULL
                        ORDER BY (expiry_date IS NULL), expiry_date, id");
    $q->execute([$countId, $ingredientId]);
    return $q->fetchAll(PDO::FETCH_ASSOC);
}

/* Number of leaf values in a posted array (what PHP's max_input_vars counts). */
function scPostedVarCount(array $post): int
{
    $n = 0;
    array_walk_recursive($post, static function () use (&$n) { $n++; });
    return $n;
}

/* True when the request looks cut off by PHP's max_input_vars (PHP silently drops the rest, which would
 * save only some readings). The page packs readings into a handful of fields, so a normal post is far below. */
function scPostTruncated(array $post): bool
{
    $max = (int)ini_get('max_input_vars');
    return $max > 0 && scPostedVarCount($post) >= $max;
}

/* The posted count readings, from the packed fields lines_json[] (the page packs every changed line into a few
 * JSON chunks, because a 400-line count posted as line[ID][field] is ~2,000 form fields and gets cut off by PHP's
 * max_input_vars or refused by the host firewall) or, for older pages/clients, from line[ID][...].
 * @param int[] $ownIds ids of the lines that belong to this count
 * @return array<int,array<string,string>> line id => [actual, touched, cost, reason, notes] (only keys that were sent)
 * @throws RuntimeException on anything malformed or on an id that is not a line of this count (packed form only) */
function scReadPostedLines(array $post, array $ownIds): array
{
    if (!isset($post['lines_json'])) {
        $out = [];
        foreach ((array)($post['line'] ?? []) as $lineId => $payload) {
            $out[(int)$lineId] = is_array($payload) ? $payload : [];
        }
        return $out;
    }
    $chunks = is_array($post['lines_json']) ? $post['lines_json'] : [$post['lines_json']];
    if (count($chunks) > 200) {
        throw new RuntimeException('The readings were sent in a form the server does not accept, so nothing was saved. Reload the page, re-enter your changes and save again.');
    }
    $own = array_flip($ownIds);
    $out = [];
    $total = 0;
    foreach ($chunks as $chunk) {
        if (!is_string($chunk) || strlen($chunk) > 400000) {
            throw new RuntimeException('The readings were sent in a form the server does not accept, so nothing was saved. Reload the page, re-enter your changes and save again.');
        }
        if (trim($chunk) === '') {
            continue;
        }
        $rows = json_decode($chunk, true);
        if (!is_array($rows)) {
            throw new RuntimeException('Your readings could not be read - they were cut off or damaged on the way, so nothing was saved. Reload the page and save again; if it keeps happening, tell your administrator.');
        }
        foreach ($rows as $row) {
            if (!is_array($row) || !isset($row['id']) || !is_numeric($row['id']) || (int)$row['id'] <= 0) {
                throw new RuntimeException('One reading had no valid line number, so nothing was saved. Reload the page and try again; if it keeps happening, tell your administrator.');
            }
            $id = (int)$row['id'];
            if (!isset($own[$id])) {
                throw new RuntimeException('A reading belongs to a line that is not part of this count (the page may be out of date or open on another count), so nothing was saved. Reload this count and save again.');
            }
            if (++$total > 5000) {
                throw new RuntimeException('That is too many lines for one save (the limit is 5,000), so nothing was saved. Start smaller counts, one category or a few chosen items at a time.');
            }
            $payload = [];
            foreach (['actual', 'cost', 'reason', 'notes', 'touched', 'mode', 'big', 'small'] as $k) { // mode/big/small: count measures (Step U1)
                if (!array_key_exists($k, $row) || $row[$k] === null) {
                    continue;
                }
                if (!is_scalar($row[$k])) {
                    throw new RuntimeException('One reading was sent in a form the server does not accept, so nothing was saved. Reload the page and try again; if it keeps happening, tell your administrator.');
                }
                $payload[$k] = is_bool($row[$k]) ? ($row[$k] ? '1' : '') : (string)$row[$k];
            }
            $out[$id] = $payload; // no 'actual' key (or null) = the box was cleared
        }
    }
    return $out;
}

/* Save the posted count readings (reasons, notes, opening unit costs) on a DRAFT count, in one transaction: all or none.
 * Used by Save readings and by Submit (which saves what is typed first, then submits the saved data). Throws RuntimeException
 * with a plain message on any refusal; on success the transaction is committed. */
function scSaveCountLines(PDO $pdo, int $countId, array $post, array $user, bool $canApprove): void
{
    $hdr = $pdo->prepare("SELECT * FROM stock_counts WHERE id = ? AND status = 'draft'");
    $hdr->execute([$countId]);
    $count = $hdr->fetch(PDO::FETCH_ASSOC);
    if (!$count) throw new RuntimeException('These readings could not be saved because this count is no longer being counted (' . scStatusNowPhrase(scCountStatusNow($pdo, $countId)) . '). Open the Stock Counts list to see where it is now.');
    if ((int)$count['counted_by'] !== (int)$user['id'] && !$canApprove) {
        throw new RuntimeException('You can’t save readings on this count because someone else started it. Only the person who started it, or someone with the ' . SC_PERM_NAME . ' permission, can - ' . SC_ASK_PERM . '.');
    }

    $hasCol = scHasLineCol($pdo, 'counted_at');
    $hasBy  = scHasLineCol($pdo, 'counted_by');
    $nowTs  = (string)$pdo->query("SELECT NOW()")->fetchColumn();
    $ownQ = $pdo->prepare("SELECT id FROM stock_count_lines WHERE count_id = ?");
    $ownQ->execute([$countId]);
    $lines = scReadPostedLines($post, array_map('intval', $ownQ->fetchAll(PDO::FETCH_COLUMN)));
    $pdo->beginTransaction(); // all readings or none: a bad value part-way must not leave half a save
    /* The system quantity and cost are the SNAPSHOT taken when the count started (stored on the
     * line). They are read back from the database, never from the posted form: a tampered or
     * stale hidden field would otherwise set the variance - and, once approved, the stock
     * adjustment - to any figure. */
    /* Count measures (Step U1): an item with a measure can be typed in the stock unit, the other measure, or both
     * ("2 bottles + 7 shots"). The reading saved is always worked out HERE from what was typed (never the page's own
     * figure) at 6 dp, and what was typed, how, and N are kept on the line. */
    $mReady = rh_stock_measure_ready($pdo);
    $snapSel = $pdo->prepare("SELECT scl.ingredient_id, scl.system_quantity, scl.cost_per_unit, scl.actual_quantity, scl.variance" . ($hasCol ? ', scl.counted_at' : '')
        . ($mReady ? ', i.measure_big_name, i.measure_small_name, i.measure_per_big, i.measure_stock_side' : '')
        . " FROM stock_count_lines scl" . ($mReady ? ' INNER JOIN stock_ingredients i ON i.id = scl.ingredient_id' : '') . " WHERE scl.id = ? AND scl.count_id = ?");
    foreach ($lines as $lineId => $payload) {
        $lineId = (int)$lineId;
        $actualRaw = trim((string)($payload['actual'] ?? ''));
        if ($actualRaw !== '' && !is_numeric($actualRaw)) {
            throw new RuntimeException('A reading is not a number, so nothing was saved. Type digits only (for example 12 or 2.5), or 0 if there is none, then save again.');
        }
        $isBlank = ($actualRaw === '');
        $touched = !empty($payload['touched']);
        $actual = (float)$actualRaw;
        if ($actual > 99999999) {
            throw new RuntimeException('A reading is too large (the limit is 99,999,999), so nothing was saved. Check it for an extra digit and save again.');
        }
        $snapSel->execute([$lineId, $countId]);
        $snap = $snapSel->fetch(PDO::FETCH_ASSOC);
        if (!$snap) {
            continue;
        }
        $measure = $mReady ? rh_stock_measure_get($snap) : null;
        $entrySet = false; // true = write the entry_* fields below
        $entry = null;     // [mode, big, small, n] or null = clear them
        if ($mReady && array_key_exists('mode', $payload)) {
            $pMode = (string)$payload['mode'];
            if (!in_array($pMode, ['stock', 'big', 'small', 'both'], true)) {
                throw new RuntimeException('A reading was sent with a measure the server does not know, so nothing was saved. Reload the page and save again.');
            }
            if ($measure === null) {
                $pMode = 'stock';
            }
            if ($pMode !== 'stock') {
                $num = static function (string $k) use ($payload): ?float {
                    $raw = trim((string)($payload[$k] ?? ''));
                    if ($raw === '') {
                        return null;
                    }
                    if (!is_numeric($raw)) {
                        throw new RuntimeException('A reading is not a number, so nothing was saved. Type digits only (for example 12 or 2.5), or 0 if there is none, then save again.');
                    }
                    if ((float)$raw < 0) {
                        throw new RuntimeException('A reading is negative, so nothing was saved. A reading is what is physically there, so it can’t be below 0 - type 0 if there is none.');
                    }
                    if ((float)$raw > 99999999) {
                        throw new RuntimeException('A reading is too large (the limit is 99,999,999), so nothing was saved. Check it for an extra digit and save again.');
                    }
                    return (float)$raw;
                };
                $b = $num('big');
                $sm = $num('small');
                if ($pMode === $measure['side']) {
                    // Typed in the measure that IS the stock unit: that is a plain stock-unit reading.
                    $v = $pMode === 'big' ? $b : $sm;
                    $isBlank = ($v === null);
                    $actual = $v ?? 0.0;
                    $pMode = 'stock';
                } else {
                    $isBlank = $pMode === 'big' ? $b === null : ($pMode === 'small' ? $sm === null : ($b === null && $sm === null));
                    $actual = 0.0;
                    if (!$isBlank) {
                        $actual = (float)rh_stock_measure_to_stock($pMode, $b ?? 0.0, $sm ?? 0.0, (float)$measure['n'], $measure['side']);
                        $entry = [$pMode, $pMode === 'small' ? null : ($b ?? 0.0), $pMode === 'big' ? null : ($sm ?? 0.0), $measure['n']];
                    }
                }
                if ($actual > 99999999) {
                    throw new RuntimeException('A reading is too large (the limit is 99,999,999), so nothing was saved. Check it for an extra digit and save again.');
                }
            }
            if ($pMode === 'stock' && !$isBlank && $measure !== null) {
                $entry = ['stock', null, null, $measure['n']];
            }
            $entrySet = true;
        } elseif ($mReady && ($isBlank || !scLineIsCounted($snap, $hasCol) || abs($actual - (float)$snap['actual_quantity']) > 0.00005)) {
            // Older page (no mode sent) changed the reading: it was typed in the stock unit.
            $entry = ($isBlank || $measure === null) ? null : ['stock', null, null, $measure['n']];
            $entrySet = true;
        }
        $sys    = (float)$snap['system_quantity'];
        $cost   = (float)$snap['cost_per_unit'];
        /* Opening count only: the counter types the unit cost the stock was bought at. */
        $costSet = null;
        if (!empty($count['is_opening']) && isset($payload['cost']) && trim((string)$payload['cost']) !== '') {
            $costRaw = trim((string)$payload['cost']);
            if (!is_numeric($costRaw) || (float)$costRaw < 0 || (float)$costRaw > 99999999) {
                throw new RuntimeException('A unit cost is not valid, so nothing was saved. Type the cost of one unit as a number of 0 or more (for example 1500) and save again.');
            }
            $costSet = round((float)$costRaw, 4);
            $cost = $costSet;
        }
        $reason = (string)($payload['reason'] ?? '');
        $notes  = trim((string)($payload['notes'] ?? ''));
        if (mb_strlen($notes) > 500) {
            throw new RuntimeException('A line note is too long (500 characters at most), so nothing was saved. Shorten the note and save again.');
        }
        if ($actual < 0) {
            throw new RuntimeException('A reading is negative, so nothing was saved. A reading is what is physically there, so it can’t be below 0 - type 0 if there is none.');
        }
        if (!in_array($reason, ['', 'spillage', 'expired', 'staff_meal', 'sampling', 'prep_waste', 'theft_suspected', 'correction', 'other'], true)) {
            $reason = '';
        }

        $sql = "UPDATE stock_count_lines SET reason_code = ?, reason_notes = ?";
        $params = [$reason, $notes ?: null];
        if ($costSet !== null) {
            // variance_cost follows the new cost; a changed reading below overrides it with the same maths.
            $sql .= ", cost_per_unit = ?, variance_cost = ROUND(variance * ?, 4)";
            array_push($params, $costSet, $costSet);
        }
        $wasCounted = scLineIsCounted($snap, $hasCol);
        if ($entrySet) {
            $sql .= ", entry_mode = ?, entry_big = ?, entry_small = ?, entry_per_big = ?";
            array_push($params, ...($entry ?? [null, null, null, null]));
        }

        if ($isBlank) {
            /* Blank = "not counted". With the counted_at column that is always recorded; without
             * it a blank is only acted on if the user actually cleared the box (touched flag),
             * otherwise the stored reading is left alone - never silently stored as 0. */
            if ($hasCol || $touched) {
                $sql .= ", actual_quantity = 0, variance = 0, variance_cost = 0";
                if ($hasCol) {
                    $sql .= ", counted_at = NULL";
                    if ($hasBy) $sql .= ", counted_by = NULL";
                }
            }
        } elseif (!$wasCounted || abs($actual - (float)$snap['actual_quantity']) > 0.00005) {
            // New or changed reading: variance is against what stock SHOULD have been at this moment
            // (snapshot + sales/receipts since the count started), not the stale snapshot alone.
            $expected = $sys + scMovementsBetween($pdo, (int)$snap['ingredient_id'], scSnapshotStart($count), $nowTs);
            $variance = round($actual - $expected, 6);
            $varCost  = round($variance * $cost, 4);
            $sql .= ", actual_quantity = ?, variance = ?, variance_cost = ?";
            array_push($params, $actual, $variance, $varCost);
            if ($hasCol) {
                $sql .= ", counted_at = ?";
                $params[] = $nowTs;
                if ($hasBy) {
                    $sql .= ", counted_by = ?";
                    $params[] = (int)$user['id'];
                }
            }
        }
        $sql .= " WHERE id = ? AND count_id = ?";
        array_push($params, $lineId, $countId);
        $pdo->prepare($sql)->execute($params);
    }

    $pdo->commit();
}

/* ============== Count measures (Step U1, owner 2026-11-18: bar stock counted in shots or glasses) ==============
 * The item measure itself lives on stock_ingredients (see rh_stock_measure_* in config/database.php). */

/* Recompute a SUBMITTED count's totals from its lines (after a line was re-worked before approval). */
function scRefreshSubmittedTotals(PDO $pdo, int $countId): void
{
    $t = $pdo->prepare("SELECT COALESCE(SUM(variance_cost),0) AS t, COALESCE(SUM(CASE WHEN variance_cost<0 THEN variance_cost ELSE 0 END),0) AS s, COALESCE(SUM(CASE WHEN variance_cost>0 THEN variance_cost ELSE 0 END),0) AS p FROM stock_count_lines WHERE count_id = ?");
    $t->execute([$countId]);
    $r = $t->fetch(PDO::FETCH_ASSOC);
    $pdo->prepare("UPDATE stock_counts SET total_variance_cost = ?, shortage_cost = ?, surplus_cost = ?, updated_at = NOW() WHERE id = ? AND status = 'submitted'")
        ->execute([(float)$r['t'], (float)$r['s'], (float)$r['p'], $countId]);
}

/* What the counter typed on a line, in words ("602 bottles", "7 shots", "2 bottles + 7 shots"). */
function scLineTypedLabel(array $ln, ?array $m, string $unit): string
{
    $mode = (string)($ln['entry_mode'] ?? '');
    if ($m !== null && in_array($mode, ['big', 'small', 'both'], true)) {
        return rh_stock_measure_label($mode, $ln['entry_big'], $ln['entry_small'], $m);
    }
    $q = (float)$ln['actual_quantity'];
    return rh_stock_qty_fmt($q, 0) . ' ' . ($m !== null ? rh_stock_measure_plural($m['side'] === 'big' ? $m['big'] : $m['small'], $q) : $unit);
}

/* Change how a line on a SUBMITTED count was typed (an approver, before approval): the stock unit <-> the other single
 * measure. The number typed keeps its value ("602" bottles -> "602" shots); the reading is worked out again and the
 * difference moves by exactly the change (what should be there is unchanged). A line typed in both measures keeps its
 * mode (only N can change for it). Runs inside the caller's transaction. Returns a plain sentence, '' if nothing changed. */
function scSetLineMode(PDO $pdo, int $countId, int $lineId, string $newMode, int $userId): string
{
    $q = $pdo->prepare("SELECT scl.*, sc.status, sc.reference, i.name AS ingredient_name, i.unit, i.measure_big_name, i.measure_small_name, i.measure_per_big, i.measure_stock_side
        FROM stock_count_lines scl INNER JOIN stock_counts sc ON sc.id = scl.count_id INNER JOIN stock_ingredients i ON i.id = scl.ingredient_id
        WHERE scl.id = ? AND scl.count_id = ? FOR UPDATE");
    $q->execute([$lineId, $countId]);
    $ln = $q->fetch(PDO::FETCH_ASSOC);
    if (!$ln) {
        throw new RuntimeException('That line is not part of this count. Reload the count and try again.');
    }
    if ((string)$ln['status'] !== 'submitted') {
        throw new RuntimeException('How a line was typed can only be changed on a count waiting for approval. On a count still being counted, change it on the line itself.');
    }
    $m = rh_stock_measure_get($ln);
    if ($m === null) {
        throw new RuntimeException($ln['ingredient_name'] . ' has no count measure, so there is nothing to switch to.');
    }
    if (!scLineIsCounted($ln, scHasLineCol($pdo, 'counted_at'))) {
        throw new RuntimeException('That line has no reading, so how it was typed can’t be changed.');
    }
    $old = (string)($ln['entry_mode'] ?? '');
    if ($old === '' || $old === $m['side']) {
        $old = 'stock';
    }
    if ($old === 'both') {
        throw new RuntimeException('This line was typed in both measures (' . scLineTypedLabel($ln, $m, (string)$ln['unit']) . '), so only how many ' . rh_stock_measure_plural($m['small'], 2) . ' make one ' . $m['big'] . ' can be changed for it.');
    }
    $other = $m['side'] === 'big' ? 'small' : 'big';
    if ($newMode !== 'stock' && $newMode !== $other) {
        throw new RuntimeException('Choose how the line was typed: in ' . rh_stock_measure_plural($m[$m['side']], 2) . ' or in ' . rh_stock_measure_plural($m[$other], 2) . '.');
    }
    if ($newMode === $old) {
        return '';
    }
    $typed = $old === 'stock' ? (float)$ln['actual_quantity'] : (float)($old === 'big' ? $ln['entry_big'] : $ln['entry_small']);
    $newAct = $newMode === 'stock' ? round($typed, 6) : rh_stock_measure_to_stock($newMode, $typed, $typed, (float)$m['n'], $m['side']);
    if ($newAct === null) {
        throw new RuntimeException('The reading could not be worked out with this measure. Nothing was changed.');
    }
    $oldLabel = scLineTypedLabel($ln, $m, (string)$ln['unit']);
    $oldAct = (float)$ln['actual_quantity'];
    $var = round((float)$ln['variance'] + ($newAct - $oldAct), 6);
    $vc = round($var * (float)$ln['cost_per_unit'], 4);
    $reason = (string)($ln['reason_code'] ?? '');
    if ($var < -0.0001 && $reason === '') {
        $reason = 'correction'; // a shortage needs a reason before approval; this one comes from the measure change
    }
    $pdo->prepare("UPDATE stock_count_lines SET actual_quantity = ?, variance = ?, variance_cost = ?, reason_code = ?, entry_mode = ?, entry_big = ?, entry_small = ?, entry_per_big = ? WHERE id = ?")
        ->execute([$newAct, $var, $vc, $reason, $newMode, $newMode === 'big' ? $typed : null, $newMode === 'small' ? $typed : null, $m['n'], $lineId]);
    scRefreshSubmittedTotals($pdo, $countId);
    $ln2 = array_merge($ln, ['actual_quantity' => $newAct, 'entry_mode' => $newMode, 'entry_big' => $newMode === 'big' ? $typed : null, 'entry_small' => $newMode === 'small' ? $typed : null]);
    $newLabel = scLineTypedLabel($ln2, $m, (string)$ln['unit']);
    $unit = (string)$ln['unit'];
    logActivity($userId, 'stock_count_line_measure_changed', 'Count ' . displayCountReference((string)$ln['reference']) . ' (#' . $countId . '), ' . $ln['ingredient_name'] . ' (#' . (int)$ln['ingredient_id'] . '): reading changed before approval from "' . $oldLabel . '" (' . rh_stock_qty_fmt($oldAct, 0, '') . ' ' . $unit . ') to "' . $newLabel . '" (' . rh_stock_qty_fmt($newAct, 0, '') . ' ' . $unit . '); difference ' . rh_stock_qty_fmt((float)$ln['variance'], 0, '') . ' -> ' . rh_stock_qty_fmt($var, 0, '') . ' ' . $unit);
    return $ln['ingredient_name'] . ': reading changed from ' . $oldLabel . ' to ' . $newLabel . ' (' . rh_stock_qty_fmt($newAct, 2) . ' ' . $unit . ').';
}

/* "These were counted in the smaller measure" for an APPROVED count (owner 2026-11-18): the counter typed shots in a
 * line kept in bottles, so the count added N times too much. Plan only (no writes); $lock = FOR UPDATE inside a transaction.
 * Maths: counted A -> A' = A / N; the count added R = A - A' too much; difference V -> V' = V - R; value falls by R x cost.
 * The count's own surplus batches lose R in proportion to what each received (rescaled); cost per unit is unchanged.
 * Refused (with the reason) when it can't be put right exactly: the count did not add stock; V' would be a shortage;
 * a later approved count of the item already reset it; the item is already counted on an open count; or sales since
 * the count used more than A' from those batches. Sales and other movements since the count stay as recorded.
 * @throws RuntimeException */
function scMeasureFixPlan(PDO $pdo, int $countId, int $lineId, bool $lock): array
{
    if (!rh_stock_measure_ready($pdo)) {
        throw new RuntimeException('Count measures are not set up yet (database update 088 has not run).');
    }
    $fu = $lock ? ' FOR UPDATE' : '';
    $cq = $pdo->prepare("SELECT * FROM stock_counts WHERE id = ?" . $fu);
    $cq->execute([$countId]);
    $c = $cq->fetch(PDO::FETCH_ASSOC);
    if (!$c || (string)$c['status'] !== 'approved') {
        throw new RuntimeException('Only an approved count can be corrected this way. While a count is open, change how the line was typed on the count itself.');
    }
    $lq = $pdo->prepare("SELECT * FROM stock_count_lines WHERE id = ? AND count_id = ?" . $fu);
    $lq->execute([$lineId, $countId]);
    $l = $lq->fetch(PDO::FETCH_ASSOC);
    if (!$l) {
        throw new RuntimeException('That line is not part of this count. Reload the count and try again.');
    }
    $iq = $pdo->prepare("SELECT id, name, unit, current_quantity, cost_per_unit, measure_big_name, measure_small_name, measure_per_big, measure_stock_side FROM stock_ingredients WHERE id = ?" . $fu);
    $iq->execute([(int)$l['ingredient_id']]);
    $ing = $iq->fetch(PDO::FETCH_ASSOC);
    if (!$ing) {
        throw new RuntimeException('That stock item no longer exists.');
    }
    $nm = (string)$ing['name'];
    $m = rh_stock_measure_get($ing);
    if ($m === null) {
        throw new RuntimeException($nm . ' has no count measure. Set one first (for example bottle and shot, 30 shots in a bottle) with Set measure or on Stock Items.');
    }
    if ($m['side'] !== 'big' || (float)$m['n'] <= 1.00005) {
        throw new RuntimeException('This correction is for items kept in the bigger measure (' . $m['big'] . ') with more than one ' . $m['small'] . ' in it. Check the measure of ' . $nm . ' first.');
    }
    $mode = (string)($l['entry_mode'] ?? '');
    if ($mode === 'small') {
        throw new RuntimeException('This line of ' . $nm . ' is already in ' . rh_stock_measure_plural($m['small'], 2) . ' (corrected before, or typed that way), so there is nothing to correct.');
    }
    if ($mode !== '' && $mode !== 'stock' && $mode !== $m['side']) {
        throw new RuntimeException('This line of ' . $nm . ' was typed as ' . scLineTypedLabel($l, $m, (string)$ing['unit']) . ', so it was already worked out from that measure.');
    }
    $n = (float)$m['n'];
    $cost = (float)$l['cost_per_unit'];
    $A = (float)$l['actual_quantity'];
    $V = (float)$l['variance'];
    if ($V <= 0.0001 || empty($l['adjustment_id'])) {
        throw new RuntimeException('When this count was approved, the line of ' . $nm . ' did not add stock, so there is nothing to take back. Do a new count of the item instead.');
    }
    if ($cost <= 0.0) { // no unit cost on the line: use the cost the surplus was posted at
        $acq = $pdo->prepare("SELECT cost_at_time FROM stock_adjustments WHERE id = ?");
        $acq->execute([(int)$l['adjustment_id']]);
        $cost = max(0.0, (float)($acq->fetchColumn() ?: 0));
    }
    $A2 = round($A / $n, 6);
    $R = round($A - $A2, 6);
    $V2 = round($V - $R, 6);
    if ($V2 < -0.000001) {
        throw new RuntimeException('Read as ' . rh_stock_measure_plural($m['small'], 2) . ', ' . rh_stock_qty_fmt($A, 0) . ' is ' . rh_stock_qty_fmt($A2, 2) . ' ' . $ing['unit'] . ', less than the ' . rh_stock_qty_fmt($A - $V, 2) . ' that should have been there, so this count found a shortage, not extra stock. That can’t be put right exactly from here: do a new count of ' . $nm . ' instead.');
    }
    $later = $pdo->prepare("SELECT sc.reference FROM stock_count_lines scl INNER JOIN stock_counts sc ON sc.id = scl.count_id
        WHERE scl.ingredient_id = ? AND sc.status = 'approved' AND sc.id <> ? AND (sc.approved_at > ? OR (sc.approved_at = ? AND sc.id > ?)) ORDER BY sc.approved_at LIMIT 1");
    $later->execute([(int)$ing['id'], $countId, (string)$c['approved_at'], (string)$c['approved_at'], $countId]);
    $laterRef = $later->fetchColumn();
    if ($laterRef !== false) {
        throw new RuntimeException($nm . ' was counted again in count ' . displayCountReference((string)$laterRef) . ', approved later, which already set its stock. Correct it with a new count instead.');
    }
    $hasCountedAt = scHasLineCol($pdo, 'counted_at');
    $open = $pdo->prepare("SELECT sc.reference FROM stock_count_lines scl INNER JOIN stock_counts sc ON sc.id = scl.count_id
        WHERE scl.ingredient_id = ? AND sc.status IN ('draft','submitted') AND NOT " . scUncountedSql($hasCountedAt) . " LIMIT 1");
    $open->execute([(int)$ing['id']]);
    $openRef = $open->fetchColumn();
    if ($openRef !== false) {
        throw new RuntimeException($nm . ' already has a reading on open count ' . displayCountReference((string)$openRef) . ', taken against the stock as it is now. Finish or delete that count first, or clear that reading, then correct this one.');
    }
    $base = 'Stock count surplus ' . (string)$c['reference'];
    $bq = $pdo->prepare("SELECT id, batch_number, quantity_received, quantity_remaining, status FROM stock_batches WHERE ingredient_id = ? AND (notes = ? OR notes LIKE ?) ORDER BY id" . $fu);
    $bq->execute([(int)$ing['id'], $base, addcslashes($base, '%_\\') . ' %']);
    $batches = $bq->fetchAll(PDO::FETCH_ASSOC);
    $recv = 0.0;
    foreach ($batches as $b) {
        $recv += (float)$b['quantity_received'];
    }
    if (!$batches || $recv < $R - 0.000001) {
        throw new RuntimeException('The stock batches this count added for ' . $nm . ' hold ' . rh_stock_qty_fmt($recv, 2) . ' ' . $ing['unit'] . ', less than the ' . rh_stock_qty_fmt($R, 2) . ' to take back, so they can’t be matched exactly. Do a new count of the item instead.');
    }
    $plan = [];
    $left = $R;
    $k = count($batches);
    foreach ($batches as $i => $b) {
        $rec = (float)$b['quantity_received'];
        $x = $i === $k - 1 ? round($left, 6) : round($R * $rec / $recv, 6);
        $left = round($left - $x, 6);
        $rem = (float)$b['quantity_remaining'];
        if ($rem - $x < -0.000001 || $rec - $x < -0.000001) {
            throw new RuntimeException('Since the count, sales and other stock movements took ' . rh_stock_qty_fmt($rec - $rem, 2) . ' ' . $ing['unit'] . ' from batch ' . $b['batch_number'] . ' of ' . $nm . ', more than the corrected count leaves in it. That can’t be put right exactly from here: do a new count of the item instead.');
        }
        $plan[] = ['id' => (int)$b['id'], 'number' => (string)$b['batch_number'], 'received' => $rec, 'remaining' => $rem, 'take' => $x,
            'new_received' => round($rec - $x, 6), 'new_remaining' => max(0.0, round($rem - $x, 6)), 'status' => (string)$b['status']];
    }
    $cur = (float)$ing['current_quantity'];
    if ($cur - $R < -0.000001) {
        throw new RuntimeException($nm . ' has only ' . rh_stock_qty_fmt($cur, 2) . ' ' . $ing['unit'] . ' in stock now, less than the ' . rh_stock_qty_fmt($R, 2) . ' to take back. Do a new count of the item instead.');
    }
    // Stock is valued at quantity x average cost, so take the R back out of the average at the cost it came in at.
    $avgOld = (float)$ing['cost_per_unit'];
    $rest = round($cur - $R, 6);
    $avgNew = $avgOld;
    if ($rest > 0.000001) {
        $avgNew = round(max(0.0, ($cur * $avgOld - $R * $cost) / $rest), 4);
    }
    $mv = $pdo->prepare("SELECT COALESCE(SUM(CASE WHEN quantity_change < 0 THEN quantity_change ELSE 0 END),0) AS out_q, COALESCE(SUM(CASE WHEN quantity_change > 0 THEN quantity_change ELSE 0 END),0) AS in_q, COUNT(*) AS n
        FROM stock_adjustments WHERE ingredient_id = ? AND created_at > ? AND NOT (source_type = 'variance' AND source_id = ?)");
    $mv->execute([(int)$ing['id'], (string)$c['approved_at'], $countId]);
    $moves = $mv->fetch(PDO::FETCH_ASSOC);
    return [
        'count' => $c, 'line' => $l, 'ing' => $ing, 'measure' => $m, 'n' => $n, 'cost' => $cost,
        'A' => $A, 'A2' => $A2, 'R' => $R, 'V' => $V, 'V2' => $V2,
        'old_value' => round($A * $cost, 2), 'new_value' => round($A2 * $cost, 2), 'value_change' => round(-$R * $cost, 2),
        'old_var_cost' => (float)$l['variance_cost'], 'new_var_cost' => round($V2 * $cost, 4),
        'stock_now' => $cur, 'stock_after' => round($cur - $R, 6), 'batches' => $plan, 'avg_old' => $avgOld, 'avg_new' => $avgNew,
        'moves_out' => (float)$moves['out_q'], 'moves_in' => (float)$moves['in_q'], 'moves_n' => (int)$moves['n'],
    ];
}

/* Apply the correction planned above, in one transaction, after re-checking everything under row locks. Running it a
 * second time is refused (the line is then in the smaller measure). Writes one 'manual' stock adjustment of -R (a
 * correction of the count, not a loss), the rescaled batches, the line (now typed in the smaller measure) and the count
 * totals; logs old -> new ('stock_count_measure_correction'). */
function scMeasureFixApply(PDO $pdo, int $countId, int $lineId, int $userId): array
{
    $pdo->beginTransaction();
    try {
        $p = scMeasureFixPlan($pdo, $countId, $lineId, true);
        $ing = $p['ing'];
        $m = $p['measure'];
        $ref = displayCountReference((string)$p['count']['reference']);
        $bu = $pdo->prepare("UPDATE stock_batches SET status = CASE WHEN status = 'active' AND quantity_remaining - ? < 0.0000005 THEN 'depleted' ELSE status END,
            quantity_received = quantity_received - ?, quantity_remaining = GREATEST(0, quantity_remaining - ?) WHERE id = ?");
        foreach ($p['batches'] as $b) {
            $bu->execute([$b['take'], $b['take'], $b['take'], $b['id']]);
        }
        $pdo->prepare("UPDATE stock_ingredients SET current_quantity = current_quantity - ?, cost_per_unit = ?, updated_at = NOW() WHERE id = ?")->execute([$p['R'], $p['avg_new'], (int)$ing['id']]);
        $reason = mb_substr('Stock count ' . $ref . ' correction: counted in ' . rh_stock_measure_plural($m['small'], 2) . ', not ' . rh_stock_measure_plural($m['big'], 2)
            . ' (' . rh_stock_qty_fmt($p['A'], 0, '') . ' ' . rh_stock_measure_plural($m['small'], $p['A']) . ' = ' . rh_stock_qty_fmt($p['A2'], 0, '') . ' ' . $ing['unit'] . ')'
            . '; average cost ' . number_format($p['avg_old'], 4, '.', '') . ' -> ' . number_format($p['avg_new'], 4, '.', ''), 0, 255);
        // 'manual' (never shrinkage) with source_id = the count: rh_stock_measure_fix_sql() keeps it out of the loss lists.
        $pdo->prepare("INSERT INTO stock_adjustments (ingredient_id, quantity_change, reason, source_type, source_id, cost_at_time, adjusted_by) VALUES (?, ?, ?, 'manual', ?, ?, ?)")
            ->execute([(int)$ing['id'], -$p['R'], $reason, $countId, $p['cost'], $userId]);
        $adjId = (int)$pdo->lastInsertId();
        $pdo->prepare("UPDATE stock_count_lines SET actual_quantity = ?, variance = ?, variance_cost = ?, entry_mode = 'small', entry_big = NULL, entry_small = ?, entry_per_big = ? WHERE id = ? AND count_id = ?")
            ->execute([$p['A2'], $p['V2'], $p['new_var_cost'], $p['A'], $p['n'], $lineId, $countId]);
        $dv = round($p['old_var_cost'] - $p['new_var_cost'], 4);
        $pdo->prepare("UPDATE stock_counts SET total_variance_cost = total_variance_cost - ?, surplus_cost = surplus_cost - ? WHERE id = ?")->execute([$dv, $dv, $countId]);
        $pdo->commit();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $e;
    }
    $u = (string)$ing['unit'];
    logActivity($userId, 'stock_count_measure_correction', 'Count ' . $ref . ' (#' . $countId . '), ' . $ing['name'] . ' (#' . (int)$ing['id'] . '): counted in ' . rh_stock_measure_plural($m['small'], 2) . ' (' . rh_stock_qty_fmt($p['n'], 0, '') . ' in one ' . $m['big'] . '). Counted '
        . rh_stock_qty_fmt($p['A'], 0, '') . ' -> ' . rh_stock_qty_fmt($p['A2'], 0, '') . ' ' . $u . '; difference ' . rh_stock_qty_fmt($p['V'], 0, '') . ' -> ' . rh_stock_qty_fmt($p['V2'], 0, '') . ' ' . $u
        . '; stock ' . rh_stock_qty_fmt($p['stock_now'], 0, '') . ' -> ' . rh_stock_qty_fmt($p['stock_after'], 0, '') . ' ' . $u
        . '; average cost ' . number_format($p['avg_old'], 4, '.', '') . ' -> ' . number_format($p['avg_new'], 4, '.', '') . '; value ' . number_format($p['old_value'], 2, '.', '') . ' -> ' . number_format($p['new_value'], 2, '.', '')
        . '; batches ' . implode(', ', array_map(static fn($b) => $b['number'] . ' ' . rh_stock_qty_fmt($b['received'], 0, '') . ' -> ' . rh_stock_qty_fmt($b['new_received'], 0, ''), $p['batches'])) . '; adjustment #' . $adjId);
    if (function_exists('rh_clear_stock_dashboard_cache')) {
        rh_clear_stock_dashboard_cache();
    }
    return $p;
}

/* Release the "one approval at a time" lock (see approve_count). Safe to call twice or with an empty name. */
function scReleaseApproveLock(PDO $pdo, string &$name): void
{
    if ($name === '') {
        return;
    }
    try {
        $pdo->query('SELECT RELEASE_LOCK(' . $pdo->quote($name) . ')');
    } catch (Throwable $e) {
        /* the connection closing releases it anyway */
    }
    $name = '';
}

/* ============== POST handlers ============== */
if (!$error && $_SERVER['REQUEST_METHOD'] === 'POST' && scPostTruncated($_POST)) {
    /* Refuse rather than save a part: PHP keeps only the first max_input_vars fields of a larger post. */
    $error = 'The page sent too many fields at once for the server to take in, so nothing was saved. Reload the page and try again; if it keeps happening, tell your administrator.';
} elseif (!$error && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $token = $_POST['csrf_token'] ?? '';
    if (!validateCsrfToken($token)) {
        $error = 'This page has expired (you may have been signed out or left it open a long time), so nothing was saved. Reload the page, sign in again if asked, and try again.';
    } else {
        $action = $_POST['action'] ?? '';
        try {
            if ($action === 'start_count') {
                $isOpeningStart = !empty($_POST['opening']);
                if ($isOpeningStart && !scHasOpeningCol($pdo)) {
                    throw new RuntimeException('Opening stock counts can’t be started yet: the stock counts table needs one extra column (is_opening) that could not be added automatically. Ask your administrator to add it, then try again.');
                }
                // An opening count can cover everything, one category or chosen items, like any other count.
                $scope = (string)($_POST['scope'] ?? 'spot');
                if (!in_array($scope, ['full', 'category', 'spot'], true)) $scope = 'spot';
                $scopeValue = $scope === 'full' ? '' : rh_clean_text($_POST['scope_value'] ?? '');
                $shift = rh_clean_text($_POST['shift'] ?? '');
                $notes = trim((string)($_POST['notes'] ?? ''));
                if (mb_strlen($scopeValue) > 255 || mb_strlen($shift) > 50 || mb_strlen($notes) > 1000) {
                    throw new RuntimeException('The count was not started because something typed is too long. The label can be up to 50 characters, the notes up to 1,000, and the chosen items up to 255 characters in all - shorten them, or count a whole category instead.');
                }
                if ($scope === 'category' && $scopeValue === '') {
                    throw new RuntimeException('A category count needs a category. Pick one in the Category box and press Start count again.');
                }
                if ($scope === 'spot' && !preg_match('/^\s*\d+(\s*,\s*\d+)*\s*$/', $scopeValue)) {
                    throw new RuntimeException('Choose at least one item to count. Tick the items in the list, or choose One category or Everything.');
                }

                // Double-submit guard: the same user starting the identical draft count within 30 seconds
                // is a double click - open the count that already exists.
                // (An opening count and a regular count with the same scope are different counts: never merged.)
                $recentCount = $pdo->prepare("SELECT id FROM stock_counts WHERE status = 'draft' AND counted_by = ? AND scope = ? AND scope_value <=> ? AND shift <=> ? AND created_at >= (NOW() - INTERVAL 30 SECOND)"
                    . (scHasOpeningCol($pdo) ? ' AND is_opening = ' . ($isOpeningStart ? '1' : '0') : '') . " ORDER BY id DESC LIMIT 1");
                $recentCount->execute([$user['id'], $scope, $scopeValue ?: null, $shift ?: null]);
                if ($existingCountId = (int)$recentCount->fetchColumn()) {
                    $_SESSION['stock_msg'] = 'That count was already started - opened it.';
                    header('Location: stock-count.php?id=' . $existingCountId);
                    exit;
                }

                // The items in scope (current quantity + cost are the snapshot the count is measured against).
                $sql = "SELECT id, current_quantity, cost_per_unit FROM stock_ingredients WHERE is_archived = 0";
                $params = [];
                if ($scope === 'category') {
                    $sql .= " AND category = ?";
                    $params[] = $scopeValue;
                } elseif ($scope === 'spot') {
                    $ids = array_filter(array_map('intval', explode(',', $scopeValue)));
                    if (empty($ids)) throw new RuntimeException('Choose at least one item to count. Tick the items in the list, or choose One category or Everything.');
                    $ph = implode(',', array_fill(0, count($ids), '?'));
                    $sql .= " AND id IN ($ph)";
                    $params = array_merge($params, $ids);
                }

                $scStartLock = '';
                if ($isOpeningStart) {
                    // An item may be in only one open opening count: refuse (naming them) rather than leave some out silently.
                    $scStartLock = scTakeOpeningLock($pdo);
                }
                $pdo->beginTransaction();
                $stmt = $pdo->prepare($sql);
                $stmt->execute($params);
                $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
                if (!$rows) throw new RuntimeException('There are no stock items to count for that choice (archived items are left out). Pick a different category or Everything, or add the items in Stock Ingredients first.');
                if ($isOpeningStart) {
                    $overlap = scOpeningOverlap($pdo, array_map(static fn($r) => (int)$r['id'], $rows));
                    if ($overlap) throw new RuntimeException(scOpeningOverlapMessage($overlap, 'started'));
                }

                $ref = generateCountReference();
                $ins = $pdo->prepare("INSERT INTO stock_counts (reference, count_date, shift, scope, scope_value, status, counted_by, notes" . (scHasSnapshotCol($pdo) ? ', snapshot_at' : '') . ") VALUES (?, CURDATE(), ?, ?, ?, 'draft', ?, ?" . (scHasSnapshotCol($pdo) ? ', NOW()' : '') . ")");
                $ins->execute([$ref, $shift ?: null, $scope, $scopeValue ?: null, $user['id'], $notes ?: null]);
                $countId = (int)$pdo->lastInsertId();
                if ($isOpeningStart) {
                    $pdo->prepare("UPDATE stock_counts SET is_opening = 1 WHERE id = ?")->execute([$countId]);
                }

                $lineIns = $pdo->prepare("INSERT INTO stock_count_lines (count_id, ingredient_id, system_quantity, actual_quantity, variance, cost_per_unit, variance_cost) VALUES (?, ?, ?, 0, 0, ?, 0)");
                foreach ($rows as $r) {
                    $lineIns->execute([$countId, (int)$r['id'], (float)$r['current_quantity'], (float)$r['cost_per_unit']]);
                }
                $pdo->commit();
                scReleaseApproveLock($pdo, $scStartLock);

                $_SESSION['stock_msg'] = $isOpeningStart
                    ? "Opening stock count {$ref} started for " . scScopePhrase(['scope' => $scope, 'scope_value' => $scopeValue]) . ' (' . count($rows) . ' item' . (count($rows) === 1 ? '' : 's') . "). Enter the quantity AND the unit cost for every item you have; someone other than you must approve it. Items that are not in this count are not changed."
                    : "Count {$ref} started. Enter your physical readings.";
                header('Location: stock-count.php?id=' . $countId);
                exit;
            }

            if ($action === 'save_lines') {
                scSaveCountLines($pdo, (int)($_POST['count_id'] ?? 0), $_POST, $user, $canApprove);
                $_SESSION['stock_msg'] = 'Count readings saved.';
                header('Location: stock-count.php?id=' . $countId);
                exit;
            }

            if ($action === 'submit_count') {
                $countId = (int)($_POST['count_id'] ?? 0);
                /* Readings typed but not yet saved travel with the submit: save them first (the same code as Save readings),
                 * then submit the saved data. If the save is refused, nothing is submitted. */
                if (isset($_POST['lines_json']) || !empty($_POST['line'])) {
                    try {
                        scSaveCountLines($pdo, $countId, $_POST, $user, $canApprove);
                    } catch (RuntimeException $saveEx) {
                        throw new RuntimeException(rtrim($saveEx->getMessage()) . ' The count was not submitted.');
                    }
                }
                $hdr = $pdo->prepare("SELECT * FROM stock_counts WHERE id = ? AND status = 'draft' FOR UPDATE");
                $pdo->beginTransaction();
                $hdr->execute([$countId]);
                $count = $hdr->fetch(PDO::FETCH_ASSOC);
                if (!$count) throw new RuntimeException('This count can’t be submitted because it is no longer being counted (' . scStatusNowPhrase(scCountStatusNow($pdo, $countId)) . '). Open the Stock Counts list to see where it is now.');
                if ((int)$count['counted_by'] !== (int)$user['id'] && !$canApprove) {
                    throw new RuntimeException('You can’t submit this count because someone else started it. Only the person who started it, or someone with the ' . SC_PERM_NAME . ' permission, can - ' . SC_ASK_PERM . '.');
                }

                /* Only lines with a reading are submitted. Lines with no reading are left out of this count (deleted from
                 * this draft below, after every check has passed) - a blank is never turned into 0; a typed 0 is a reading. */
                $hasColSub = scHasLineCol($pdo, 'counted_at');
                $leftOutNames = scUncountedAllNames($pdo, $countId, $hasColSub);
                $lineTotalQ = $pdo->prepare('SELECT COUNT(*) FROM stock_count_lines WHERE count_id = ?');
                $lineTotalQ->execute([$countId]);
                $readingLines = (int)$lineTotalQ->fetchColumn() - count($leftOutNames);
                if ($readingLines < 1) {
                    throw new RuntimeException('Nothing to submit: no item has a reading yet. Type what you counted (0 if there is none) on at least one item, then submit again.');
                }

                // Opening stock: every line that adds stock needs a unit cost, or COGS and valuation start at zero.
                if (!empty($count['is_opening'])) {
                    $uncosted = scOpeningUncostedLines($pdo, $countId);
                    if ($uncosted) {
                        throw new RuntimeException('This opening count can’t be submitted yet: ' . count($uncosted) . ' counted line' . (count($uncosted) === 1 ? ' has' : 's have') . ' no unit cost (' . implode(', ', array_slice($uncosted, 0, 6)) . (count($uncosted) > 6 ? ' and ' . (count($uncosted) - 6) . ' more' : '') . '). Type the cost of one unit (more than 0) on each of those lines, then submit again.');
                    }
                }

                // Validate every shortage line has a reason code
                $missing = $pdo->prepare("SELECT COUNT(*) FROM stock_count_lines WHERE count_id = ? AND variance < 0 AND (reason_code = '' OR reason_code IS NULL)");
                $missing->execute([$countId]);
                if ((int)$missing->fetchColumn() > 0) {
                    throw new RuntimeException('This count can’t be submitted yet: every line that is short needs a reason. Pick a reason on each shortage line (use the Reason filter, “No reason”, to find them), then submit again.');
                }

                // Checks passed: drop the blank lines (nothing was posted for them), so the count holds only what was read.
                if ($leftOutNames) {
                    $pdo->prepare('DELETE scl FROM stock_count_lines scl WHERE scl.count_id = ? AND ' . scUncountedSql($hasColSub))->execute([$countId]);
                }

                // Compute totals
                $tots = $pdo->prepare("SELECT COALESCE(SUM(variance_cost),0) AS total, COALESCE(SUM(CASE WHEN variance_cost<0 THEN variance_cost ELSE 0 END),0) AS shortage, COALESCE(SUM(CASE WHEN variance_cost>0 THEN variance_cost ELSE 0 END),0) AS surplus FROM stock_count_lines WHERE count_id = ?");
                $tots->execute([$countId]);
                $t = $tots->fetch(PDO::FETCH_ASSOC);

                $pdo->prepare("UPDATE stock_counts SET status='submitted', rejection_reason=NULL, total_variance_cost=?, shortage_cost=?, surplus_cost=?, updated_at=NOW() WHERE id=?")
                    ->execute([(float)$t['total'], (float)$t['shortage'], (float)$t['surplus'], $countId]);
                $pdo->commit();

                $scLeftOutMsg = scLeftOutSentence($leftOutNames);
                if ($leftOutNames) {
                    logActivity((int)$user['id'], 'stock_count_lines_left_out', 'Count ' . displayCountReference((string)$count['reference']) . ' (#' . $countId . ') submitted with ' . $readingLines . ' item' . ($readingLines === 1 ? '' : 's') . '; ' . count($leftOutNames) . ' with no reading left out: ' . mb_substr(implode(', ', $leftOutNames), 0, 800));
                }
                $scSubmitHead = 'Count ' . displayCountReference((string)$count['reference']) . ' submitted' . ($leftOutNames ? ' with ' . $readingLines . ' item' . ($readingLines === 1 ? '' : 's') : ' for approval') . '.';

                /* Two-person rule off and this user may decide: one press submits AND approves. The submit above is
                 * already committed; control falls straight into the approve_count block below (all its checks run
                 * unchanged). If approval fails the count simply stays submitted and the user can press Approve. */
                if (!rh_sca_second_approver_required() && rh_sca_may_decide((int)$user['id'], (int)$count['counted_by'], $canApprove, false)) {
                    $_SESSION['stock_msg'] = $scSubmitHead . ($scLeftOutMsg !== '' ? ' ' . $scLeftOutMsg : '');
                    $scSubmitAndApprove = true;
                    $action = 'approve_count';
                }
            }

            if ($action === 'submit_count') {
                $_SESSION['stock_msg'] = $scSubmitHead . ($scLeftOutMsg !== '' ? ' ' . $scLeftOutMsg : '') . ($canApprove ? ' Shortage cost: ' . $currency . ' ' . number_format(abs((float)$t['shortage']), 2) . '.' : '');
                // Tell the approvers (tickbox, default on). The count is already submitted: an email problem only changes the message.
                if (!empty($_POST['email_approvers'])) {
                    $em = rh_sca_email_approvers($pdo, $countId, (int)$user['id'], 'submit');
                    $_SESSION['stock_msg'] .= ' ' . $em['message'];
                } else {
                    $_SESSION['stock_msg'] .= ' Approvers were not emailed.';
                }
                header('Location: stock-count.php?id=' . $countId);
                exit;
            }

            if ($action === 'remind_count') {
                $countId = (int)($_POST['count_id'] ?? 0);
                // Only the counter and people with "Approve stock counts" (also enforced inside the helper).
                $remCounter = $pdo->prepare("SELECT counted_by FROM stock_counts WHERE id = ?");
                $remCounter->execute([$countId]);
                if (!rh_sca_may_remind((int)$user['id'], (int)$remCounter->fetchColumn(), $canApprove)) {
                    throw new RuntimeException('You can’t remind the approvers about this count. Only the person who counted it, or someone with the ' . SC_PERM_NAME . ' permission, can - ' . SC_ASK_PERM . '.');
                }
                $em = rh_sca_email_approvers($pdo, $countId, (int)$user['id'], 'remind');
                if ($em['ok']) {
                    $_SESSION['stock_msg'] = $em['message'];
                } else {
                    $_SESSION['stock_err'] = $em['message'];
                }
                header('Location: stock-count.php?id=' . $countId);
                exit;
            }

            if ($action === 'approve_count') {
                $countId = (int)($_POST['count_id'] ?? 0);

                /* One approval at a time (all counts). The stale-count guard below reads the OTHER counts' approval
                 * state; without this, two counts sharing items approved at the same instant could both pass it and
                 * post the same stock twice. The lock is taken BEFORE the transaction starts so the transaction's
                 * read snapshot is made after any earlier approval has committed; the waiting approval then re-runs
                 * the guard and is refused with the usual message. Named lock = no row locks, so no deadlock risk. */
                $scApproveLock = substr('rh_stock_count_approve_' . (defined('DB_NAME') ? DB_NAME : 'db'), 0, 64);
                if ((int)$pdo->query('SELECT GET_LOCK(' . $pdo->quote($scApproveLock) . ', 30)')->fetchColumn() !== 1) {
                    $scApproveLock = '';
                    throw new RuntimeException('Another count is being approved at this moment, so nothing was posted. Wait a few seconds and press Approve again.');
                }
                register_shutdown_function(static function () use ($pdo, $scApproveLock) {
                    try { $pdo->query('SELECT RELEASE_LOCK(' . $pdo->quote($scApproveLock) . ')'); } catch (Throwable $e) { /* connection closing releases it anyway */ }
                });

                $pdo->beginTransaction();
                $hdr = $pdo->prepare("SELECT * FROM stock_counts WHERE id = ? FOR UPDATE");
                $hdr->execute([$countId]);
                $count = $hdr->fetch(PDO::FETCH_ASSOC);
                if (!$count) throw new RuntimeException('This count was not found (it may have been deleted), so nothing was posted. Open the Stock Counts list to check.');
                // The permission, or (only while an administrator has the two-person rule switched off) the counter.
                $secondRuleOn = rh_sca_second_approver_required();
                if (!rh_sca_may_decide((int)$user['id'], (int)$count['counted_by'], $canApprove, $secondRuleOn)) {
                    throw new RuntimeException('You can’t approve this count because you don’t have the ' . SC_PERM_NAME . ' permission. Ask someone who has it (managers and administrators do by default) to approve it, or ask an administrator to give you the permission in User Management.');
                }
                if ($count['status'] !== 'submitted') throw new RuntimeException('This count can’t be approved because ' . scStatusNowPhrase((string)$count['status']) . '. Only a count that has been submitted can be approved' . ($count['status'] === 'draft' ? ' - the counter must finish counting and submit it first.' : ($count['status'] === 'rejected' ? ' - it must be counted again and resubmitted first.' : '.')));

                // Apply variance deltas for each line while keeping batch ledger in sync.
                $lineSel = $pdo->prepare("SELECT * FROM stock_count_lines WHERE count_id = ?");
                $lineSel->execute([$countId]);
                $lines = $lineSel->fetchAll(PDO::FETCH_ASSOC);
                $hasCol = scHasLineCol($pdo, 'counted_at');

                // Nothing is approved while any line is uncounted - blanks are never read as zero.
                [$uncN, $uncNames] = scUncountedLines($pdo, $countId, $hasCol);
                if ($uncN > 0) {
                    throw new RuntimeException(scUncountedMessage($uncN, $uncNames, 'approve'));
                }

                /* Stale-count guard. Variance is worked out against the stock snapshot of THIS count plus movements up
                 * to the moment each line was counted. If another count that covers the same items was approved AFTER
                 * a line here was counted, its posted variance is not in that window, so approving this one as well
                 * would add the same physical stock a second time (two opening counts of the same shelves would double
                 * the stock and its value). Refuse; the counter re-counts from a fresh snapshot (Reject, then Count again). */
                $staleQ = $pdo->prepare("SELECT sc2.reference, COUNT(DISTINCT l1.ingredient_id) AS n
                    FROM stock_counts sc2
                    INNER JOIN stock_count_lines l2 ON l2.count_id = sc2.id AND l2.adjustment_id IS NOT NULL
                    INNER JOIN stock_count_lines l1 ON l1.ingredient_id = l2.ingredient_id AND l1.count_id = ?
                    WHERE sc2.id <> ? AND sc2.status = 'approved' AND sc2.approved_at > " . ($hasCol ? 'COALESCE(l1.counted_at, ?)' : '?') . "
                    GROUP BY sc2.id, sc2.reference ORDER BY sc2.approved_at LIMIT 3");
                $staleQ->execute([$countId, $countId, scSnapshotStart($count)]);
                $staleRows = $staleQ->fetchAll(PDO::FETCH_ASSOC);
                if ($staleRows) {
                    $parts = [];
                    foreach ($staleRows as $sr) {
                        $parts[] = displayCountReference((string)$sr['reference']) . ' (' . (int)$sr['n'] . ' of the same items)';
                    }
                    throw new RuntimeException('This count is out of date: ' . implode(', ', $parts) . ' was approved after these items were counted here, and approving this one too would add the same stock twice. Press “Reject count”, then “Count again”, to start from fresh stock figures.');
                }

                if (!function_exists('deductStockBatchFIFO') || !function_exists('ensureStockBatchCoverageForDeduction')) {
                    throw new RuntimeException('Stock can’t be updated right now because part of the stock system did not load, so nothing was posted. Reload the page and try again; if it keeps happening, tell your administrator.');
                }

                // Opening stock: never self-approved, and every counted line must carry a unit cost.
                if (!empty($count['is_opening'])) {
                    if (rh_sca_self_approve_blocked((int)$user['id'], (int)$count['counted_by'], true, $secondRuleOn)) {
                        throw new RuntimeException('You can’t approve this opening count because you counted it. An opening count must be approved by a different person with the ' . SC_PERM_NAME . ' permission, so ask another manager or administrator (an administrator can change this rule in ' . SC_RULE_WHERE . ').');
                    }
                    $uncosted = scOpeningUncostedLines($pdo, $countId);
                    if ($uncosted) {
                        throw new RuntimeException('This opening count can’t be approved: ' . count($uncosted) . ' counted line' . (count($uncosted) === 1 ? ' has' : 's have') . ' no unit cost (' . implode(', ', array_slice($uncosted, 0, 6)) . (count($uncosted) > 6 ? ' and ' . (count($uncosted) - 6) . ' more' : '') . '). Press “Reject count” so the counter can type the costs and submit it again.');
                    }
                } elseif (!empty($count['import_job_id'])) {
                    // A regular count filed by Import (critical patch 2026-11-25, ASSUMPTION in BUILD_PLAN.md): like an opening count, the person
                    // who imported it never approves it while the two-person rule is on.
                    if (rh_sca_self_approve_blocked((int)$user['id'], (int)$count['counted_by'], true, $secondRuleOn)) {
                        throw new RuntimeException('You can’t approve this stock count because you imported it. A count filed by Import must be approved by a different person with the ' . SC_PERM_NAME . ' permission, so ask another manager or administrator (an administrator can change this rule in ' . SC_RULE_WHERE . ').');
                    }
                }

                $liveSel = $pdo->prepare("SELECT current_quantity, cost_per_unit FROM stock_ingredients WHERE id = ? FOR UPDATE");
                $ingAdd = $pdo->prepare("UPDATE stock_ingredients SET current_quantity = current_quantity + ?, updated_at = NOW() WHERE id = ?");
                $batchIns = $pdo->prepare("\n                    INSERT INTO stock_batches (\n                        ingredient_id, batch_number, quantity_received, quantity_remaining, cost_per_unit,\n                        supplier_name, supplier_contact, received_date, expiry_date, expiry_alert_days, status, notes, created_by\n                    ) VALUES (?, ?, ?, ?, ?, NULL, NULL, CURDATE(), NULL, 7, 'active', ?, ?)\n                ");
                $batchNumUpd = $pdo->prepare('UPDATE stock_batches SET batch_number = ? WHERE id = ?');
                $adjInsPositive = $pdo->prepare("INSERT INTO stock_adjustments (ingredient_id, quantity_change, reason, source_type, source_id, cost_at_time, adjusted_by) VALUES (?, ?, ?, 'variance', ?, ?, ?)");
                $adjReasonUpd = $pdo->prepare('UPDATE stock_adjustments SET reason = ?, cost_at_time = ? WHERE id = ?');
                $lineUpd = $pdo->prepare("UPDATE stock_count_lines SET adjustment_id = ? WHERE id = ?");
                $lineVarUpd = $pdo->prepare("UPDATE stock_count_lines SET variance = ?, variance_cost = ? WHERE id = ?");
                $ingCostUpd = $pdo->prepare("UPDATE stock_ingredients SET cost_per_unit = ? WHERE id = ?");

                /* PHASE 1 - movement-aware variance. The line's system_quantity is a snapshot from when the
                 * count started; sales/receipts/wastage between then and the moment the line was counted are
                 * already reflected in the physical reading. expected_at_count = snapshot + those movements,
                 * variance = actual - expected_at_count, and THAT delta is what is posted to live stock, so
                 * final stock = physical reading + movements after the count (nothing double-counted).
                 * Lines without counted_at (migration 062 not run) use the approval moment as the count time. */
                $approvalTs = (string)$pdo->query("SELECT NOW()")->fetchColumn();
                $needsOtherApprover = false;
                $plan = [];
                $zeroCost = [];
                $needReason = [];
                $totShort = 0.0;
                $totSurplus = 0.0;
                // Lock order (every stock write path): all counted ingredient rows first, by ascending id,
                // before any batch is touched below.
                $countIngIds = array_values(array_unique(array_filter(array_map(static fn($l) => (int)$l['ingredient_id'], $lines))));
                sort($countIngIds);
                if ($countIngIds) {
                    $pdo->prepare("SELECT id FROM stock_ingredients WHERE id IN (" . implode(',', array_fill(0, count($countIngIds), '?')) . ") ORDER BY id FOR UPDATE")->execute($countIngIds);
                }
                foreach ($lines as $ln) {
                    $ingredientId = (int)$ln['ingredient_id'];
                    $liveSel->execute([$ingredientId]);
                    $live = $liveSel->fetch(PDO::FETCH_ASSOC);
                    if (!$live) {
                        throw new RuntimeException('A stock item on this count could not be found (it may have been deleted), so nothing was posted. Reject the count and start a new one, or tell your administrator.');
                    }
                    $costAtTime = (float)$ln['cost_per_unit'];
                    if ($costAtTime <= 0) {
                        $costAtTime = (float)$live['cost_per_unit'];
                    }
                    $countedAt = ($hasCol && !empty($ln['counted_at'])) ? (string)$ln['counted_at'] : $approvalTs;
                    if ($countedAt > $approvalTs) $countedAt = $approvalTs;
                    $expected = (float)$ln['system_quantity'] + scMovementsBetween($pdo, $ingredientId, scSnapshotStart($count), $countedAt);
                    $variance = round((float)$ln['actual_quantity'] - $expected, 6);
                    $varCost = round($variance * $costAtTime, 4);
                    $lineVarUpd->execute([$variance, $varCost, (int)$ln['id']]);
                    if ($varCost < 0) $totShort += $varCost; else $totSurplus += $varCost;
                    if (scOverThreshold($variance, $varCost, $expected, $blockPct, $minCost)) {
                        $needsOtherApprover = true;
                    }
                    if ($variance < -0.0001 && trim((string)($ln['reason_code'] ?? '')) === '') {
                        $nm = $pdo->prepare("SELECT name FROM stock_ingredients WHERE id = ?");
                        $nm->execute([$ingredientId]);
                        $needReason[] = (string)$nm->fetchColumn();
                    }
                    if ($variance > 0.0001 && $costAtTime <= 0.00001) {
                        $zn = $pdo->prepare("SELECT name FROM stock_ingredients WHERE id = ?");
                        $zn->execute([$ingredientId]);
                        $zeroCost[] = (string)$zn->fetchColumn();
                    }
                    $plan[] = ['ln' => $ln, 'variance' => $variance, 'live' => $live, 'cost' => $costAtTime];
                }

                /* A surplus creates a stock batch. With no unit cost on the line AND no average cost on the
                 * ingredient it would be booked at zero (COGS and valuation wrong), so it is not approvable. */
                if (!empty($zeroCost)) {
                    throw new RuntimeException('This count can’t be approved yet: ' . count($zeroCost) . ' line' . (count($zeroCost) === 1 ? ' adds' : 's add') . ' stock for items that have no unit cost (' . implode(', ', array_slice($zeroCost, 0, 6)) . (count($zeroCost) > 6 ? ' and ' . (count($zeroCost) - 6) . ' more' : '') . '). Set a cost on each item in Stock Ingredients and approve again, or reject the count so the counter can type the costs.');
                }

                /* Movement-aware variance can turn a line into a shortage that had no reason when it was
                 * submitted. Post nothing: keep the recomputed variances, send the count back to the counter. */
                if (!empty($needReason)) {
                    $list = mb_substr(implode(', ', $needReason), 0, 380);
                    $pdo->prepare("UPDATE stock_counts SET status='draft', rejection_reason=?, total_variance_cost=?, shortage_cost=?, surplus_cost=?, updated_at=NOW() WHERE id=? AND status='submitted'")
                        ->execute(['Recounted against stock movements during the count: these lines are now shortages and need a reason: ' . $list, round($totShort + $totSurplus, 4), round($totShort, 4), round($totSurplus, 4), $countId]);
                    $pdo->commit();
                    scReleaseApproveLock($pdo, $scApproveLock);
                    $_SESSION['stock_msg'] = 'Not approved, and nothing was posted to stock: ' . count($needReason) . ' line' . (count($needReason) === 1 ? ' turned' : 's turned') . ' out to be short once sales and other stock movements during the count were allowed for, and each needs a reason (' . $list . '). The count is back to being counted with your readings kept - add the reasons and submit it again.';
                    header('Location: stock-count.php?id=' . $countId);
                    exit;
                }

                // Shortages over the configured thresholds need an approver who is not the counter.
                if (rh_sca_self_approve_blocked((int)$user['id'], (int)$count['counted_by'], $needsOtherApprover, $secondRuleOn)) {
                    throw new RuntimeException('This count has shortages over the approval level (' . $currency . ' ' . number_format($minCost, 2) . ' and ' . rtrim(rtrim(number_format($blockPct, 2), '0'), '.') . '%), so the person who counted it can’t approve it. Ask another manager or administrator with the ' . SC_PERM_NAME . ' permission (an administrator can change this rule in ' . SC_RULE_WHERE . ').');
                }

                $pdo->prepare("UPDATE stock_counts SET total_variance_cost=?, shortage_cost=?, surplus_cost=? WHERE id = ?")
                    ->execute([round($totShort + $totSurplus, 4), round($totShort, 4), round($totSurplus, 4), $countId]);

                // PHASE 2 - post each line's variance to live stock.
                foreach ($plan as $item) {
                    $ln = $item['ln'];
                    $variance = $item['variance'];
                    $live = $item['live'];
                    $costAtTime = $item['cost'];
                    if (abs($variance) < 0.0001) continue;

                    $ingredientId = (int)$ln['ingredient_id'];
                    $lineId = (int)$ln['id'];
                    $reason = 'Stock count ' . $count['reference'] . ' (' . ($ln['reason_code'] ?: 'no_code') . ')';
                    $reasonShort = mb_substr($reason, 0, 255);

                    $liveSel->execute([$ingredientId]);
                    $live = $liveSel->fetch(PDO::FETCH_ASSOC);
                    $liveQty = (float)$live['current_quantity'];

                    if ($variance > 0) {
                        /* Count surplus becomes real active batches so FIFO stays correct. Normally ONE batch
                         * (no expiry). An opening count from the stock-sheet import can carry batches with their
                         * own expiry dates: the surplus is split across them (earliest expiry first), any rest
                         * goes into one plain batch. */
                        $slices = [[$variance, null, '', 0]];
                        if (!empty($count['is_opening'])) {
                            $pending = scPendingOpeningBatches($pdo, $countId, $ingredientId);
                            if ($pending) {
                                $slices = [];
                                $left = $variance;
                                foreach ($pending as $pb) {
                                    if ($left <= 0.0001) break;
                                    $take = min((float)$pb['quantity'], $left);
                                    $slices[] = [$take, $pb['expiry_date'], (string)($pb['batch_label'] ?? ''), (int)$pb['id']];
                                    $left = round($left - $take, 6);
                                }
                                if ($left > 0.0001) $slices[] = [$left, null, '', 0];
                            }
                        }
                        $avgNow = (float)$live['cost_per_unit'];
                        foreach ($slices as [$sliceQty, $sliceExpiry, $sliceLabel, $pendingId]) {
                            $tmpBatchNumber = 'TMP-C-' . date('YmdHis') . '-' . strtoupper(substr(str_replace('.', '', uniqid('', true)), -8));
                            /* Sales beyond the batches left the ingredient total negative with no batch behind it;
                             * settle that deficit first so the batch sum stays equal to current_quantity. */
                            $settle = rh_stock_receipt_settlement($pdo, $ingredientId, $liveQty, $sliceQty);
                            if ($settle['settled'] > 0 && $costAtTime > 0) {
                                // Late cost: sales made at zero cost for this deficit get costed at the count line's unit cost (no cost = left at 0).
                                rh_stock_backcost_settled_deficit($pdo, $ingredientId, (float)$settle['deficit'], (float)$settle['settled'], (float)$costAtTime, (int)($user['id'] ?? 0) ?: null, 'stock count ' . ($count['reference'] ?? ''));
                            }
                            $batchIns->execute([
                                $ingredientId,
                                $tmpBatchNumber,
                                $sliceQty,
                                $settle['remaining'],
                                max(0, $costAtTime),
                                mb_substr('Stock count surplus ' . $count['reference'] . ($sliceLabel !== '' ? ' batch ' . $sliceLabel : '') . ($settle['settled'] > 0 ? ' (settled ' . rtrim(rtrim(number_format($settle['settled'], 6, '.', ''), '0'), '.') . ' unallocated deficit)' : ''), 0, 500),
                                $user['id'],
                            ]);
                            $batchId = (int)$pdo->lastInsertId();
                            if ($sliceExpiry) {
                                $pdo->prepare("UPDATE stock_batches SET expiry_date = ? WHERE id = ?")->execute([$sliceExpiry, $batchId]);
                            }
                            if ($pendingId > 0) {
                                $pdo->prepare("UPDATE stock_opening_batches SET applied_batch_id = ? WHERE id = ?")->execute([$batchId, $pendingId]);
                            }
                            if ($settle['remaining'] < 0.0001) {
                                $pdo->prepare("UPDATE stock_batches SET status = 'depleted' WHERE id = ?")->execute([$batchId]);
                            }
                            $batchNumber = 'C' . date('Ymd') . '-' . str_pad((string)$batchId, 6, '0', STR_PAD_LEFT);
                            $batchNumUpd->execute([$batchNumber, $batchId]);

                            // Keep the ingredient's weighted-average cost in step with the new surplus batch.
                            if ($costAtTime > 0 && function_exists('calculateWeightedAvgCost')) {
                                $avgNow = calculateWeightedAvgCost($liveQty, $avgNow, $sliceQty, $costAtTime);
                                $ingCostUpd->execute([round($avgNow, 4), $ingredientId]);
                            }
                            $ingAdd->execute([$sliceQty, $ingredientId]);
                            $liveQty += $sliceQty;
                        }
                        $adjInsPositive->execute([$ingredientId, $variance, $reasonShort, $countId, $costAtTime, $user['id']]);
                        $adjId = (int)$pdo->lastInsertId();
                    } else {
                        $deductQty = abs($variance);
                        if ($deductQty > $liveQty + 0.0001) {
                            // Never fail the whole count: write off what is there and record the remainder.
                            $uncovered = round($deductQty - max(0.0, $liveQty), 6);
                            $deductQty = max(0.0, $liveQty);
                            $reasonShort = mb_substr($reason . ' [uncovered shortage ' . $uncovered . ' - live stock was only ' . round(max(0.0, $liveQty), 6) . ']', 0, 255);
                        }
                        if ($deductQty < 0.0001) {
                            // Nothing left to deduct: keep an audit row so the shortfall is still on record.
                            $adjInsPositive->execute([$ingredientId, 0, $reasonShort, $countId, $costAtTime, $user['id']]);
                            $lineUpd->execute([(int)$pdo->lastInsertId(), $lineId]);
                            continue;
                        }

                        ensureStockBatchCoverageForDeduction(
                            $ingredientId,
                            $deductQty,
                            $costAtTime,
                            $user['id'],
                            'Auto batch sync before count shortage ' . $count['reference']
                        );

                        $adjId = (int)(deductStockBatchFIFO($ingredientId, $deductQty, 'variance', $countId, $user['id']) ?? 0);
                        if ($adjId <= 0) {
                            throw new RuntimeException('The shortage on stock item #' . $ingredientId . ' could not be taken off stock, so nothing was posted. Try approving again; if it keeps happening, tell your administrator.');
                        }
                        $adjReasonUpd->execute([$reasonShort, $costAtTime, $adjId]);
                    }

                    $lineUpd->execute([$adjId, $lineId]);
                }

                $pdo->prepare("UPDATE stock_counts SET status='approved', approved_by=?, approved_at=NOW(), updated_at=NOW() WHERE id = ?")
                    ->execute([$user['id'], $countId]);
                $pdo->commit();
                scReleaseApproveLock($pdo, $scApproveLock);

                if (!$secondRuleOn && (int)$count['counted_by'] === (int)$user['id']) {
                    logActivity((int)$user['id'], 'stock_count_self_approved', 'Count ' . displayCountReference((string)$count['reference']) . ' (#' . $countId . '): approved own count - second approver switched off');
                }
                if (function_exists('deleteCache')) rh_clear_stock_dashboard_cache();
                $_SESSION['stock_msg'] = "Count " . displayCountReference((string)$count['reference']) . (!empty($scSubmitAndApprove) ? " submitted and approved." : " approved.") . " Variances applied to stock." . (!empty($scSubmitAndApprove) && !empty($scLeftOutMsg) ? ' ' . $scLeftOutMsg : '');
                header('Location: stock-count.php?id=' . $countId);
                exit;
            }

            if ($action === 'reject_count') {
                $countId = (int)($_POST['count_id'] ?? 0);
                $rejCounter = $pdo->prepare("SELECT counted_by FROM stock_counts WHERE id = ?");
                $rejCounter->execute([$countId]);
                if (!rh_sca_may_decide((int)$user['id'], (int)$rejCounter->fetchColumn(), $canApprove)) {
                    throw new RuntimeException('You can’t reject this count because you don’t have the ' . SC_PERM_NAME . ' permission. Ask someone who has it (managers and administrators do by default), or ask an administrator to give you the permission in User Management.');
                }
                $reason = trim($_POST['rejection_reason'] ?? '');
                if (mb_strlen($reason) < 8) throw new RuntimeException('The count was not rejected because the reason is too short. Give a reason of at least 8 characters so the counter knows what to fix, then press Reject count again.');
                $pdo->prepare("UPDATE stock_counts SET status='rejected', rejection_reason=?, updated_at=NOW() WHERE id=? AND status='submitted'")
                    ->execute([mb_substr($reason, 0, 500), $countId]);
                $_SESSION['stock_msg'] = 'Count rejected and sent back for re-counting.';
                header('Location: stock-count.php?id=' . $countId);
                exit;
            }

            /* Set an item's count measure from a count line (Stock Items or "Approve stock counts" permission). Re-works that
             * item's typed lines on open counts; an approver may also change how this line was typed (submitted count). */
            if ($action === 'set_measure') {
                if (!$canMeasure) {
                    throw new RuntimeException('You can’t change count measures. That needs the Stock Items permission or the ' . SC_PERM_NAME . ' permission - ' . SC_ASK_PERM . '.');
                }
                if (!rh_stock_measure_ready($pdo)) {
                    throw new RuntimeException('Count measures are not set up yet (database update 088 has not run).');
                }
                $countId = (int)($_POST['count_id'] ?? 0);
                $lineId = (int)($_POST['line_id'] ?? 0);
                [$mNew, $mErr] = rh_stock_measure_from_input($_POST);
                if ($mErr !== null) {
                    throw new RuntimeException($mErr);
                }
                $pdo->beginTransaction();
                $cq = $pdo->prepare("SELECT sc.id, sc.reference, sc.status, scl.ingredient_id FROM stock_counts sc INNER JOIN stock_count_lines scl ON scl.count_id = sc.id WHERE sc.id = ? AND scl.id = ? FOR UPDATE");
                $cq->execute([$countId, $lineId]);
                $cRow = $cq->fetch(PDO::FETCH_ASSOC);
                if (!$cRow) {
                    throw new RuntimeException('That line is not part of this count. Reload the count and try again.');
                }
                if (!in_array((string)$cRow['status'], ['draft', 'submitted'], true)) {
                    throw new RuntimeException('This count is ' . scStatusLabel((string)$cRow['status']) . ', so its lines are not re-worked. Change the measure on Stock Items' . ((string)$cRow['status'] === 'approved' ? ', or use “Counted in the smaller measure?” on the line' : '') . '.');
                }
                $mRes = rh_stock_measure_save($pdo, (int)$cRow['ingredient_id'], $mNew, (int)$user['id'], 'stock count ' . displayCountReference((string)$cRow['reference']));
                $lineMsg = '';
                $lineMode = (string)($_POST['line_mode'] ?? '');
                if ($lineMode !== '' && $lineMode !== 'keep') {
                    if (!$canApprove || (string)$cRow['status'] !== 'submitted') {
                        throw new RuntimeException('Only someone with the ' . SC_PERM_NAME . ' permission can change how a line was typed, and only while the count waits for approval.');
                    }
                    $lineMsg = scSetLineMode($pdo, $countId, $lineId, $lineMode, (int)$user['id']);
                }
                $pdo->commit();
                $msg = $mRes['changed'] ? ('Count measure ' . ($mNew === null ? 'removed.' : 'saved: ' . rh_stock_measure_describe($mNew) . '.')) : '';
                if ($mRes['lines'] > 0) {
                    $msg .= ' ' . $mRes['lines'] . ' counted line' . ($mRes['lines'] === 1 ? '' : 's') . ' on ' . $mRes['counts'] . ' open count' . ($mRes['counts'] === 1 ? ' was' : 's were') . ' worked out again with it.';
                }
                $msg = trim($msg . ' ' . $lineMsg);
                $_SESSION['stock_msg'] = $msg !== '' ? $msg : 'Nothing changed.';
                header('Location: stock-count.php?id=' . $countId);
                exit;
            }

            /* "These were counted in the smaller measure" on an APPROVED count: confirm after the preview. */
            if ($action === 'measure_fix') {
                if (!$canFix) {
                    throw new RuntimeException('You can’t correct an approved count. That needs both the Stock Items permission and the ' . SC_PERM_NAME . ' permission - ' . SC_ASK_PERM . '.');
                }
                $countId = (int)($_POST['count_id'] ?? 0);
                $lineId = (int)($_POST['line_id'] ?? 0);
                $fx = scMeasureFixApply($pdo, $countId, $lineId, (int)$user['id']);
                $_SESSION['stock_msg'] = $fx['ing']['name'] . ' corrected: counted ' . rh_stock_qty_fmt($fx['A'], 0) . ' ' . rh_stock_measure_plural($fx['measure']['small'], $fx['A'])
                    . ' = ' . rh_stock_qty_fmt($fx['A2'], 2) . ' ' . $fx['ing']['unit'] . '. Stock went from ' . rh_stock_qty_fmt($fx['stock_now'], 2) . ' to ' . rh_stock_qty_fmt($fx['stock_after'], 2)
                    . ' ' . $fx['ing']['unit'] . ' (value ' . $currency . ' ' . number_format($fx['value_change'], 2) . ').';
                header('Location: stock-count.php?id=' . $countId);
                exit;
            }

            if ($action === 'reopen_count') {
                $countId = (int)($_POST['count_id'] ?? 0);
                $pdo->beginTransaction();
                $hdr = $pdo->prepare("SELECT * FROM stock_counts WHERE id = ? AND status = 'rejected' FOR UPDATE");
                $hdr->execute([$countId]);
                $count = $hdr->fetch(PDO::FETCH_ASSOC);
                if (!$count) {
                    $nowSt = scCountStatusNow($pdo, $countId);
                    throw new RuntimeException('This count can’t be counted again because ' . scStatusNowPhrase($nowSt) . '. Only a rejected count can be reopened' . ($nowSt === 'submitted' ? ' - ask someone who approves counts to reject it first.' : '.'));
                }
                if ((int)$count['counted_by'] !== (int)$user['id'] && !$canApprove) {
                    throw new RuntimeException('You can’t count this again because someone else started it. Only the person who started it, or someone with the ' . SC_PERM_NAME . ' permission, can - ' . SC_ASK_PERM . '.');
                }
                if (!empty($count['is_opening'])) {
                    // Since it was rejected, another opening count may have taken some of its items: reopening would put them in two.
                    $scStartLock = scTakeOpeningLock($pdo);
                    $ownIds = $pdo->prepare("SELECT ingredient_id FROM stock_count_lines WHERE count_id = ?");
                    $ownIds->execute([$countId]);
                    $overlap = scOpeningOverlap($pdo, array_map('intval', $ownIds->fetchAll(PDO::FETCH_COLUMN)), $countId);
                    if ($overlap) throw new RuntimeException(scOpeningOverlapMessage($overlap, 'reopened'));
                }

                /* Re-snapshot: every line takes the CURRENT live quantity/cost and goes back to "not counted",
                 * so stale readings cannot be re-submitted against old stock. The snapshot time of a count is
                 * stock_counts.snapshot_at (start of the movement window at approval), set to now on reopen;
                 * created_at keeps the true start. Without the column the fallback is to move created_at. */
                // An opening count keeps the unit costs the counter typed; every other count re-reads the live cost.
                $costKeep = !empty($count['is_opening']) ? "CASE WHEN scl.cost_per_unit > 0 THEN scl.cost_per_unit ELSE i.cost_per_unit END" : "i.cost_per_unit";
                $reset = "UPDATE stock_count_lines scl INNER JOIN stock_ingredients i ON i.id = scl.ingredient_id
                          SET scl.system_quantity = i.current_quantity, scl.cost_per_unit = {$costKeep},
                              scl.actual_quantity = 0, scl.variance = 0, scl.variance_cost = 0,
                              scl.reason_code = ''";
                if (scHasLineCol($pdo, 'counted_at')) {
                    $reset .= ", scl.counted_at = NULL";
                    if (scHasLineCol($pdo, 'counted_by')) $reset .= ", scl.counted_by = NULL";
                }
                $pdo->prepare($reset . " WHERE scl.count_id = ?")->execute([$countId]);
                $pdo->prepare("UPDATE stock_counts SET status='draft', rejection_reason=NULL, " . (scHasSnapshotCol($pdo) ? 'snapshot_at=NOW()' : 'created_at=NOW()') . ", updated_at=NOW(), total_variance_cost=0, shortage_cost=0, surplus_cost=0 WHERE id=? AND status='rejected'")
                    ->execute([$countId]);
                $pdo->commit();
                if (!empty($scStartLock)) scReleaseApproveLock($pdo, $scStartLock);
                $_SESSION['stock_msg'] = 'Count reopened with a fresh stock snapshot — re-enter every reading and resubmit.';
                header('Location: stock-count.php?id=' . $countId);
                exit;
            }

            if ($action === 'delete_count') {
                /* Only a REJECTED count or a DRAFT (still being counted) can be deleted: neither ever changed stock (only
                 * approval writes adjustments). Its lines and any opening batches typed with it go too; the deletion is
                 * kept in the activity log. */
                $countId = (int)($_POST['count_id'] ?? 0);
                $pdo->beginTransaction();
                $hdr = $pdo->prepare("SELECT id, reference, counted_by, status, rejection_reason FROM stock_counts WHERE id = ? AND status IN ('rejected','draft') FOR UPDATE");
                $hdr->execute([$countId]);
                $count = $hdr->fetch(PDO::FETCH_ASSOC);
                if (!$count) {
                    $nowSt = scCountStatusNow($pdo, $countId);
                    throw new RuntimeException('This count can’t be deleted because ' . scStatusNowPhrase($nowSt) . '. Only a count that is being counted, or was rejected, can be deleted' . ($nowSt === 'submitted' ? ' - ask someone who approves counts to reject it first, then it can be deleted.' : ($nowSt === 'approved' ? '; an approved count stays on record.' : '.')));
                }
                if (!scMayDeleteCount($count, $user, $canApprove)) {
                    throw new RuntimeException($count['status'] === 'draft'
                        ? 'You can’t delete this count because someone else started it. Only the person who started it, an administrator, or someone with the ' . SC_PERM_NAME . ' permission can - ' . SC_ASK_PERM . '.'
                        : 'You can’t delete this rejected count. Only an administrator, or someone with the ' . SC_PERM_NAME . ' permission who did not count it, can - ask one of them' . ((int)$count['counted_by'] === (int)$user['id'] ? ' (you counted it yourself, so someone else has to do it).' : '.'));
                }
                $wasDraft = $count['status'] === 'draft';
                $pdo->prepare("DELETE FROM stock_count_lines WHERE count_id = ?")->execute([$countId]);
                $hasOb = $pdo->query("SHOW TABLES LIKE 'stock_opening_batches'")->rowCount() > 0;
                if ($hasOb) $pdo->prepare("DELETE FROM stock_opening_batches WHERE count_id = ?")->execute([$countId]);
                $pdo->prepare("DELETE FROM stock_counts WHERE id = ? AND status IN ('rejected','draft')")->execute([$countId]);
                $pdo->commit();
                logActivity((int)$user['id'], 'stock_count_deleted', ($wasDraft ? 'Deleted unfinished stock count ' : 'Deleted rejected stock count ') . displayCountReference((string)$count['reference']) . ($wasDraft ? ' (it was still being counted)' : ' (reason it was rejected: ' . mb_substr((string)$count['rejection_reason'], 0, 200) . ')'));
                $_SESSION['stock_msg'] = ($wasDraft ? 'Unfinished count ' : 'Rejected count ') . displayCountReference((string)$count['reference']) . ' deleted. Stock was not changed.';
                header('Location: stock-count.php');
                exit;
            }
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            if (!empty($scApproveLock)) scReleaseApproveLock($pdo, $scApproveLock);
            if (!empty($scStartLock)) scReleaseApproveLock($pdo, $scStartLock);
            if ($e instanceof RuntimeException) {
                $error = $e->getMessage();
            } else {
                error_log('stock-count: ' . get_class($e) . ': ' . $e->getMessage());
                $error = 'Something went wrong on our side, so this may not have finished. Reload the page to see where the count is now, then try again; if it keeps happening, tell your administrator.';
            }
        }
    }
}

if (!empty($_SESSION['stock_msg'])) {
    $message = $_SESSION['stock_msg'];
    unset($_SESSION['stock_msg']);
}
if (!empty($_SESSION['stock_err'])) {
    $error   = $_SESSION['stock_err'];
    unset($_SESSION['stock_err']);
}

/* ============== Page state ============== */
$activeId = isset($_GET['id']) ? (int)$_GET['id'] : 0;
$activeCount = null;
$activeLines = [];

if ($activeId) {
    $stmt = $pdo->prepare("
        SELECT sc.*, cu.full_name AS counted_by_name, au.full_name AS approved_by_name
        FROM stock_counts sc
        LEFT JOIN admin_users cu ON cu.id = sc.counted_by
        LEFT JOIN admin_users au ON au.id = sc.approved_by
        WHERE sc.id = ?
    ");
    $stmt->execute([$activeId]);
    $activeCount = $stmt->fetch(PDO::FETCH_ASSOC) ?: null;

    if ($activeCount) {
        $mCols = $scMeasureReady ? ', i.measure_big_name, i.measure_small_name, i.measure_per_big, i.measure_stock_side' : '';
        $lstmt = $pdo->prepare("
            SELECT scl.*, i.name AS ingredient_name, i.unit, i.category{$mCols}
            FROM stock_count_lines scl
            INNER JOIN stock_ingredients i ON i.id = scl.ingredient_id
            WHERE scl.count_id = ?
            ORDER BY i.category, i.name
        ");
        $lstmt->execute([$activeId]);
        $activeLines = $lstmt->fetchAll(PDO::FETCH_ASSOC);
    }
}

$counts = $pdo->query("
    SELECT sc.*, cu.full_name AS counted_by_name, au.full_name AS approved_by_name,
           (SELECT COUNT(*) FROM stock_count_lines WHERE count_id = sc.id) AS line_count
    FROM stock_counts sc
    LEFT JOIN admin_users cu ON cu.id = sc.counted_by
    LEFT JOIN admin_users au ON au.id = sc.approved_by
    ORDER BY sc.created_at DESC
    LIMIT 50
")->fetchAll(PDO::FETCH_ASSOC);

/* Everyone who can approve counts (active, with the permission); each count then excludes its own counter. */
$allApprovers = rh_sca_approvers($pdo, 0);
$approversFor = static function (int $counterId) use ($allApprovers): array {
    return array_values(array_filter($allApprovers, static fn($a) => (int)$a['id'] !== $counterId));
};

$categories = $pdo->query("SELECT DISTINCT category FROM stock_ingredients WHERE is_archived = 0 ORDER BY category")->fetchAll(PDO::FETCH_COLUMN);
$ingredientList = $pdo->query("SELECT id, name, category, unit FROM stock_ingredients WHERE is_archived = 0 ORDER BY category, name")->fetchAll(PDO::FETCH_ASSOC);

/* ============== Theft / unaccounted summary (last 30 days) ==============
 * For each ingredient, classify total ABS movement by source. Anything that
 * drains stock and is NOT traced to a source_type IS the theft signal — but
 * by design every drain has a source_type, so we instead surface ingredients
 * whose sum of negative 'manual' or 'variance' adjustments is large compared
 * to sales — that is the audit lens. */
$theftRadar = $pdo->query("
    SELECT i.id, i.name, i.unit, i.cost_per_unit,
           COALESCE(SUM(CASE WHEN sa.source_type IN ('pos_order','room_service') THEN ABS(sa.quantity_change) WHEN sa.source_type = 'void_restore' THEN -ABS(sa.quantity_change) ELSE 0 END), 0) AS sold,
           COALESCE(SUM(CASE WHEN sa.source_type = 'wastage' THEN ABS(sa.quantity_change) ELSE 0 END), 0) AS wasted,
           COALESCE(SUM(CASE WHEN sa.source_type = 'variance' AND sa.quantity_change < 0 THEN ABS(sa.quantity_change) ELSE 0 END), 0) AS shortage,
           COALESCE(SUM(CASE WHEN sa.source_type = 'manual' AND sa.quantity_change < 0 AND NOT " . rh_stock_measure_fix_sql('sa') . " THEN ABS(sa.quantity_change) ELSE 0 END), 0) AS manual_out
    FROM stock_ingredients i
    LEFT JOIN stock_adjustments sa ON sa.ingredient_id = i.id AND sa.created_at >= DATE_SUB(NOW(), INTERVAL 30 DAY)
    WHERE i.is_archived = 0
    GROUP BY i.id, i.name, i.unit, i.cost_per_unit
    HAVING shortage > 0 OR manual_out > 0
    ORDER BY (shortage * i.cost_per_unit + manual_out * i.cost_per_unit) DESC
    LIMIT 20
")->fetchAll(PDO::FETCH_ASSOC);

/* Manual stock ADDITIONS (last 30 days, top 20 by cost): a manual add can hide an earlier loss. */
$manualAdds = [];
$countReminder = null;
if (!$error && !$activeCount) {
    try {
        $manualAdds = rh_stock_manual_additions($pdo, 30, 20);
    } catch (Throwable $e) {
        error_log('stock-count manual additions: ' . $e->getMessage());
    }
    $countReminder = rh_stock_count_reminder($pdo);
}

$csrf_token = generateCsrfToken();
?>
<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <title>Stock Counts</title>
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <link href="https://fonts.googleapis.com/css2?family=Cormorant+Garamond:wght@400;500;600&family=Jost:wght@300;400;500;600&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="css/admin-styles.css?v=<?php echo @filemtime(__DIR__ . '/css/admin-styles.css'); ?>">
    <link rel="stylesheet" href="css/admin-components.css?v=<?php echo @filemtime(__DIR__ . '/css/admin-components.css'); ?>">
    <link rel="stylesheet" href="css/stock-count.css?v=<?php echo @filemtime(__DIR__ . '/css/stock-count.css'); ?>">
</head>

<body>
    <?php require_once 'includes/admin-header.php'; ?>

    <div class="content stock-count-page">
        <div class="page-header">
            <h2 class="page-title">Stock Counts</h2>
            <p class="page-subtitle">Count what is really on the shelf. The system compares it with what it expects, and once someone approves the count, stock is corrected to match.</p>
        </div>


        <?php if ($activeCount): ?>
            <!-- ============= COUNT DETAIL ============= -->
            <?php
            $totalLines = count($activeLines);
            $pendingShort = 0;
            $pendingSurplus = 0;
            $hasCountedAt = scHasLineCol($pdo, 'counted_at');
            $uncountedTotal = 0;
            $anyOverThreshold = false;
            $overAlertLines = 0;
            $pageNow = (string)$pdo->query("SELECT NOW()")->fetchColumn();
            /* BLIND COUNT: while a count is being taken the counter must commit to a physical reading
             * before seeing what the system expects - otherwise the anti-theft control is void (type
             * whatever matches, or read the expected figure to hide a shortage). System / expected /
             * variance / cost impact are therefore withheld (and never put in the page) from the counter
             * of a draft, and from non-approvers while a count is submitted or returned. Approvers see
             * them once the count is submitted, and everyone sees an approved count. */
            $blindCount = ($activeCount['status'] === 'draft' && ((int)$activeCount['counted_by'] === (int)$user['id'] || !$canApprove))
                || (!$canApprove && in_array($activeCount['status'], ['submitted', 'rejected'], true)
                    && !($activeCount['status'] === 'submitted' && rh_sca_may_decide((int)$user['id'], (int)$activeCount['counted_by'], false, $secondRuleOn)));
            foreach ($activeLines as $i => $ln) {
                $vc = (float)$ln['variance_cost'];
                if ($vc < 0) $pendingShort += $vc;
                else $pendingSurplus += $vc;
                $isCounted = scLineIsCounted($ln, $hasCountedAt);
                $activeLines[$i]['_counted'] = $isCounted;
                if (!$isCounted) $uncountedTotal++;
                if ($blindCount) {
                    $activeLines[$i]['_preview'] = 0.0;
                    $activeLines[$i]['_expected'] = null;
                    continue;
                }
                // Expected stock at the moment of counting = snapshot + movements since the count started.
                $activeLines[$i]['_preview'] = (float)$ln['system_quantity'];
                if (!$isCounted) {
                    $activeLines[$i]['_expected'] = null;
                    if ($activeCount['status'] === 'draft') {
                        $activeLines[$i]['_preview'] += scMovementsBetween($pdo, (int)$ln['ingredient_id'], scSnapshotStart($activeCount), $pageNow);
                    }
                } elseif ($activeCount['status'] === 'approved') {
                    $activeLines[$i]['_expected'] = (float)$ln['actual_quantity'] - (float)$ln['variance'];
                } else {
                    $to = ($hasCountedAt && !empty($ln['counted_at'])) ? (string)$ln['counted_at'] : $pageNow;
                    $activeLines[$i]['_expected'] = (float)$ln['system_quantity'] + scMovementsBetween($pdo, (int)$ln['ingredient_id'], scSnapshotStart($activeCount), $to);
                }
                $activeLines[$i]['_level'] = 'none';
                if ($isCounted) {
                    $lvl = rh_stock_alert_level((float)$ln['variance'], $vc, (float)($activeLines[$i]['_expected'] ?? $ln['system_quantity']), $alertPct, $blockPct, $minCost);
                    $activeLines[$i]['_level'] = $lvl;
                    if ($lvl !== 'none') $overAlertLines++;
                    if ($lvl === 'block') $anyOverThreshold = true;
                }
            }
            $editable = $activeCount['status'] === 'draft';
            $isOpeningCount = !empty($activeCount['is_opening']);
            $isImportCount = !empty($activeCount['import_job_id']);        // filed by Import: always a second person (opening or regular)
            $isSubmitted = $activeCount['status'] === 'submitted';
            $isOwnCount = (int)$activeCount['counted_by'] === (int)$user['id'];
            $canWorkCount = $isOwnCount || $canApprove;
            $blockSelfApprove = $isSubmitted && ($anyOverThreshold || $isOpeningCount || $isImportCount) && $isOwnCount && $secondRuleOn;
            $canDecide = rh_sca_may_decide((int)$user['id'], (int)$activeCount['counted_by'], $canApprove, $secondRuleOn);
            ?>
            <div class="count-header">
                <a href="stock-count.php" class="btn-secondary"><i class="fas fa-arrow-left"></i> Back to all counts</a>
                <span class="count-header__meta">
                    Count <strong><?php echo htmlspecialchars(displayCountReference((string)$activeCount['reference'])); ?></strong>
                    <span class="pill st-<?php echo htmlspecialchars($activeCount['status']); ?>"><?php echo htmlspecialchars(scStatusLabel((string)$activeCount['status'])); ?></span>
                    <?php if (!empty($activeCount['is_opening'])): ?><span class="pill" style="background:#eff6ff;color:#1e3a8a;border:1px solid #bfdbfe;">Opening stock</span><?php endif; ?>
                    ·
                    Counted by <strong><?php echo htmlspecialchars($activeCount['counted_by_name'] ?: '—'); ?></strong>
                    on <?php echo htmlspecialchars(date('Y-m-d H:i', strtotime($activeCount['created_at']))); ?>
                </span>
            </div>

            <div class="summary-grid">
                <div class="sum-card">
                    <div class="lbl">Items <i class="help" tabindex="0" role="button" aria-label="About items in this count" data-tip="How many ingredients are on this count.">?</i></div>
                    <div class="val"><?php echo $totalLines; ?></div>
                </div>
                <?php if (!$blindCount): ?>
                <div class="sum-card<?php echo $pendingShort < 0 ? ' bad' : ''; ?>">
                    <div class="lbl">Shortage <i class="help" tabindex="0" role="button" aria-label="About shortage" data-tip="Worth of missing stock on the lines counted so far: how much short, times cost per unit.">?</i></div>
                    <div class="val"><?php echo $currency . ' ' . number_format(abs($pendingShort), 2); ?></div>
                </div>
                <div class="sum-card<?php echo $pendingSurplus > 0 ? ' good' : ''; ?>">
                    <div class="lbl">Surplus <i class="help" tabindex="0" role="button" aria-label="About surplus" data-tip="Worth of extra stock found on the lines counted so far: how much over, times cost per unit.">?</i></div>
                    <div class="val"><?php echo $currency . ' ' . number_format($pendingSurplus, 2); ?></div>
                </div>
                <div class="sum-card">
                    <div class="lbl">Net <i class="help" tabindex="0" role="button" aria-label="About net difference" data-tip="Surplus minus shortage. A minus figure means more stock is missing than was found.">?</i></div>
                    <div class="val"><?php echo $currency . ' ' . number_format($pendingSurplus + $pendingShort, 2); ?></div>
                </div>
                <?php endif; ?>
                <div class="sum-card<?php echo ($uncountedTotal > 0 && $activeCount['status'] === 'draft') ? ' bad' : ''; ?>">
                    <div class="lbl">Not counted <i class="help" tabindex="0" role="button" aria-label="About items not counted" data-tip="Items on this count that have no counted figure entered yet. When you submit, these are left out of the count and can be counted in a later one.">?</i></div>
                    <div class="val"><?php echo (int)$uncountedTotal; ?></div>
                </div>
            </div>
            <?php if ($blindCount): ?>
                <p style="font-size:12px;color:#6c757d;margin:-4px 0 12px;"><i class="fas fa-eye-slash"></i> <strong>Blind count.</strong> Enter what you physically count. What the system expects is hidden while you count, and the approver sees it after you submit.</p>
            <?php else: ?>
                <p style="font-size:12px;color:#6c757d;margin:-4px 0 12px;">The difference is measured against the stock the system expects <strong>at the moment you counted</strong> (stock at the start, plus any sales, deliveries or wastage recorded while the count was open).</p>
                <?php if ($overAlertLines > 0): ?>
                    <div style="background:#fff7e6; border:1px solid #ffe0a3; color:#7a5200; padding:10px 14px; border-radius:8px; margin-bottom:12px;">
                        <strong><?php echo (int)$overAlertLines; ?> line<?php echo $overAlertLines === 1 ? '' : 's'; ?> over the alert level.</strong>
                        They are shaded below.
                    </div>
                <?php endif; ?>
                <p style="font-size:12px;color:#6c757d;margin:-4px 0 12px;">
                    <strong>How lines are shaded:</strong> a shortage worth at least <?php echo htmlspecialchars($currency . ' ' . number_format($minCost, 2)); ?> is shaded
                    <span style="background:#fff7e6;padding:0 4px;">orange</span> when it is <?php echo htmlspecialchars($fmtPct($alertPct)); ?>% or more of the expected stock (the alert level), and
                    <span style="background:#fde7e9;padding:0 4px;">red</span> when it is <?php echo htmlspecialchars($fmtPct($blockPct)); ?>% or more (the approval level<?php echo $secondRuleOn ? ', which needs a second approver' : ''; ?>). Both levels are set in Hotel Settings.
                </p>
            <?php endif; ?>

            <?php if ($isSubmitted):
                $countApprovers = $approversFor((int)$activeCount['counted_by']);
                $counterNm = (string)($activeCount['counted_by_name'] ?: 'the person who counted it');
                $needsSecondKnown = $secondRuleOn && ($isOpeningCount || $isImportCount || ($canApprove && $anyOverThreshold));
                $remindWait = rh_sca_remind_wait($pdo, (int)$activeCount['id']);
                $mayRemind = rh_sca_may_remind((int)$user['id'], (int)$activeCount['counted_by'], $canApprove);
            ?>
                <div class="approval-box">
                    <div><strong><i class="fas fa-hourglass-half"></i> Waiting for approval.</strong>
                        <?php if (!$secondRuleOn): ?>
                            The person who counted it (<?php echo htmlspecialchars($counterNm); ?>) can approve it too.
                        <?php elseif ($needsSecondKnown): ?>
                            <strong>Needs a second person to approve &mdash; not <?php echo htmlspecialchars($counterNm); ?>.</strong>
                        <?php else: ?>
                            If any shortage is over the approval level (<?php echo htmlspecialchars($currency . ' ' . number_format($minCost, 2) . ' and ' . $fmtPct($blockPct) . '%'); ?>), a second person (not <?php echo htmlspecialchars($counterNm); ?>) must approve it.
                        <?php endif; ?>
                    </div>
                    <div>
                        <strong>Who can approve:</strong>
                        <?php if ($countApprovers): ?>
                            <?php echo htmlspecialchars(rh_sca_approver_names($countApprovers)); ?>
                        <?php else: ?>
                            <span style="color:#842029;">nobody else right now. Ask an administrator to give someone the &ldquo;Approve stock counts&rdquo; permission in User Management.</span>
                        <?php endif; ?>
                    </div>
                    <?php if ($isOwnCount && $canApprove && $needsSecondKnown): ?>
                        <div><i class="fas fa-user-lock"></i> You counted this, so you can&rsquo;t approve it yourself. Ask one of the people above.</div>
                    <?php elseif ($isOwnCount && !$canDecide): ?>
                        <div><i class="fas fa-user-lock"></i> You counted this and you don&rsquo;t have the approval permission, so one of the people above must approve it.</div>
                    <?php elseif (!$canDecide): ?>
                        <div><i class="fas fa-user-lock"></i> You can&rsquo;t approve counts. Ask one of the people above.</div>
                    <?php endif; ?>
                    <?php if ($mayRemind): ?>
                    <form method="POST" class="sc-remind">
                        <input type="hidden" name="csrf_token" value="<?php echo $csrf_token; ?>">
                        <input type="hidden" name="action" value="remind_count">
                        <input type="hidden" name="count_id" value="<?php echo (int)$activeCount['id']; ?>">
                        <?php if ($remindWait > 0): ?>
                            <button type="button" class="btn-secondary" disabled><i class="fas fa-envelope"></i> Approvers emailed &mdash; remind again in <?php echo (int)ceil($remindWait / 60); ?> min</button>
                        <?php else: ?>
                            <button type="submit" class="btn-secondary"><i class="fas fa-envelope"></i> Remind approvers</button>
                            <span class="sc-remind__note">Sends them an email with a link to this count.</span>
                        <?php endif; ?>
                    </form>
                    <?php endif; ?>
                </div>
            <?php endif; ?>

            <?php if ($activeCount['status'] === 'draft' && trim((string)($activeCount['rejection_reason'] ?? '')) !== ''): ?>
                <div style="background:#fff3cd; border:1px solid #ffe69c; padding:10px 14px; border-radius:8px; margin-bottom:14px;">
                    <strong>Sent back. Reason:</strong> <?php echo htmlspecialchars($activeCount['rejection_reason']); ?>
                </div>
            <?php endif; ?>

            <?php
            /* "Differences from the uploaded file" (owner 2026-11-23): an opening count filed by Import carries a link to its job
             * (stock_counts.import_job_id); the job holds the list, frozen at the import. Older counts have no link: nothing is shown. */
            $scDiff = null;
            if (!empty($activeCount['import_job_id'])) {        // opening or regular count filed by Import (critical patch 2026-11-25)
                require_once __DIR__ . '/../includes/import/diff-report.php';
                $scDiff = import_diff_for_count($pdo, $activeCount);
            }
            if ($scDiff):
                $dRep = $scDiff['report'];
                $dKinds = import_diff_kinds((string)($dRep['term'] ?? 'opening count'));
                $dTotal = (int)($dRep['total'] ?? 0);
                $dWant = isset($_GET['diff']) && isset($dKinds[(string)$_GET['diff']]) ? (string)$_GET['diff'] : '';
                $dOpen = $isSubmitted || $dWant !== '' || isset($_GET['diff']);
                $dUrl = 'stock-count.php?id=' . (int)$activeCount['id'] . '&diff_export=';
                $dTip = static fn(string $label, string $tip): string => '<i class="help" tabindex="0" role="button" aria-label="About ' . htmlspecialchars($label, ENT_QUOTES) . '" data-tip="' . htmlspecialchars($tip, ENT_QUOTES) . '">?</i>';
            ?>
                <details class="sc-diff" id="scDiff" data-want="<?php echo htmlspecialchars($dWant, ENT_QUOTES); ?>"<?php echo $dOpen ? ' open' : ''; ?>>
                    <summary class="sc-diff__sum">
                        <i class="fas fa-chevron-right sc-diff__chev" aria-hidden="true"></i>
                        <span class="sc-diff__title">Differences from the uploaded file (<?php echo htmlspecialchars((string)$scDiff['job']['file']); ?>)</span>
                        <span class="sc-diff__count"><?php echo $dTotal === 0 ? 'none' : number_format($dTotal) . ' difference' . ($dTotal === 1 ? '' : 's'); ?></span>
                    </summary>
                    <div class="sc-diff__body">
                        <p class="sc-diff__help">This count was filed by Import #<?php echo (int)$scDiff['job']['id']; ?>. Listed: every place where what was imported is not exactly what the file says, and why. Kept as it was at the import (<?php echo htmlspecialchars((string)($dRep['built_at'] ?? '')); ?>): later changes to items do not change it. An item imported exactly as the file says is not listed.</p>
                        <?php if ($dTotal === 0): ?>
                            <p class="sc-diff__none">None: every item in this count was imported exactly as the file says.</p>
                        <?php else: ?>
                            <div class="sc-diff__chips" role="group" aria-label="Show one kind of difference">
                                <button type="button" class="sc-diff__chip is-on" data-k="" aria-pressed="true">All <span class="sc-diff__n"><?php echo number_format($dTotal); ?></span></button>
                                <?php foreach ($dKinds as $dk => $dl): if (empty($dRep['kinds'][$dk])) continue; ?>
                                    <button type="button" class="sc-diff__chip" data-k="<?php echo htmlspecialchars($dk, ENT_QUOTES); ?>" aria-pressed="false" title="<?php echo htmlspecialchars($dl[1], ENT_QUOTES); ?>"><?php echo htmlspecialchars($dl[0]); ?> <span class="sc-diff__n"><?php echo number_format((int)$dRep['kinds'][$dk]); ?></span></button>
                                <?php endforeach; ?>
                            </div>
                        <?php endif; ?>
                        <?php if (!empty($dRep['value'])): $dv = $dRep['value']; ?>
                            <p class="sc-diff__note"><strong>Value check:</strong> the value column (<?php echo htmlspecialchars(implode(', ', (array)$dv['headers'])); ?>) in the file adds up to <?php echo htmlspecialchars($currency . ' ' . number_format((float)$dv['file_total'], 2)); ?>; count &times; unit cost as imported for the same <?php echo number_format((int)$dv['compared']); ?> row<?php echo (int)$dv['compared'] === 1 ? '' : 's'; ?> is <?php echo htmlspecialchars($currency . ' ' . number_format((float)$dv['imported_total'], 2)); ?><?php echo (int)$dv['differ'] > 0 ? ' (' . number_format((int)$dv['differ']) . ' row' . ((int)$dv['differ'] === 1 ? '' : 's') . ' differ)' : ''; ?>.
                                <?php echo $dTip('the value check', 'The file\'s own value column (Stock Value / Total) added up over the rows that have a value and a count, next to the same rows valued at the count and unit cost this import put in the count. Skipped rows and stock left out of the count are valued at 0.'); ?></p>
                        <?php endif; ?>
                        <p class="sc-diff__note">This count as imported: <?php echo number_format((int)($dRep['count_lines'] ?? 0)); ?> line<?php echo (int)($dRep['count_lines'] ?? 0) === 1 ? '' : 's'; ?> worth <?php echo htmlspecialchars($currency . ' ' . number_format((float)($dRep['count_value'] ?? 0), 2)); ?> (count &times; unit cost).</p>
                        <?php if ((int)($dRep['stored'] ?? 0) < $dTotal): ?>
                            <p class="sc-diff__note">Only the first <?php echo number_format((int)$dRep['stored']); ?> of <?php echo number_format($dTotal); ?> differences are listed; the counts above are exact.</p>
                        <?php endif; ?>
                        <?php if ($dTotal > 0): ?>
                            <div class="sc-diff__tools">
                                <a class="btn-secondary" href="<?php echo htmlspecialchars($dUrl . 'xlsx', ENT_QUOTES); ?>"><i class="fas fa-download" aria-hidden="true"></i> Download (Excel)</a>
                                <a class="btn-secondary" href="<?php echo htmlspecialchars($dUrl . 'csv', ENT_QUOTES); ?>"><i class="fas fa-download" aria-hidden="true"></i> Download (CSV)</a>
                            </div>
                            <div class="card sc-diff__card">
                                <table class="lines-table sc-diff__table">
                                    <thead>
                                        <tr>
                                            <th>What <?php echo $dTip('what', 'The kind of difference. Click a chip above to see one kind only.'); ?></th>
                                            <th>Item <?php echo $dTip('item', 'The stock item as it is named in stock (or the row\'s name when the row was not imported).'); ?></th>
                                            <th>Cell <?php echo $dTip('the cell', 'Where in the uploaded file this comes from: sheet name ! cell (for example Sheet1!C5 = column C, row 5).'); ?></th>
                                            <th>In your file <?php echo $dTip('the file value', 'What the uploaded file says in that cell (for an answer: the question, in short).'); ?></th>
                                            <th>Imported <?php echo $dTip('the imported value', 'What this import put in stock, the count or the menu instead (for an answer: the choice made).'); ?></th>
                                            <th>Why <?php echo $dTip('why', 'Why the two differ, in plain words.'); ?></th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php foreach ((array)($dRep['rows'] ?? []) as $dr): ?>
                                            <tr data-k="<?php echo htmlspecialchars((string)$dr['k'], ENT_QUOTES); ?>">
                                                <td data-label="What"><span class="sc-diff__kind"><?php echo htmlspecialchars($dKinds[$dr['k']][0] ?? (string)$dr['k']); ?></span></td>
                                                <td data-label="Item"><?php echo htmlspecialchars((string)$dr['item']); ?></td>
                                                <td data-label="Cell" class="sc-diff__cell"><?php echo htmlspecialchars((string)$dr['cell'] !== '' ? (string)$dr['cell'] : '—'); ?></td>
                                                <td data-label="In your file"><?php echo htmlspecialchars((string)$dr['file'] !== '' ? (string)$dr['file'] : '—'); ?></td>
                                                <td data-label="Imported"><?php echo htmlspecialchars((string)$dr['used'] !== '' ? (string)$dr['used'] : '—'); ?></td>
                                                <td data-label="Why" class="sc-diff__why"><?php echo htmlspecialchars((string)$dr['why']); ?></td>
                                            </tr>
                                        <?php endforeach; ?>
                                    </tbody>
                                </table>
                            </div>
                        <?php endif; ?>
                    </div>
                </details>
                <script>
                (function () {
                    var box = document.getElementById('scDiff');
                    if (!box || box.getAttribute('data-ready') === '1') { return; }
                    box.setAttribute('data-ready', '1');
                    var table = box.querySelector('.sc-diff__table');
                    var chips = Array.prototype.slice.call(box.querySelectorAll('.sc-diff__chip'));
                    function refresh(reset) { if (table && typeof table.rhPagerRefresh === 'function') { table.rhPagerRefresh(reset); } }
                    function show(k) {
                        chips.forEach(function (c) { var on = c.getAttribute('data-k') === k; c.classList.toggle('is-on', on); c.setAttribute('aria-pressed', on ? 'true' : 'false'); });
                        if (!table) { return; }
                        Array.prototype.forEach.call(table.tBodies[0].rows, function (tr) {
                            var out = k !== '' && tr.getAttribute('data-k') !== k;
                            if (out) { tr.setAttribute('data-filter-hidden', '1'); tr.hidden = true; } else { tr.removeAttribute('data-filter-hidden'); tr.hidden = false; }
                        });
                        refresh(true);
                    }
                    chips.forEach(function (c) { c.addEventListener('click', function () { show(c.getAttribute('data-k') || ''); }); });
                    box.addEventListener('toggle', function () { if (box.open) { refresh(false); } });
                    var want = box.getAttribute('data-want') || '';
                    if (want !== '') { show(want); setTimeout(function () { show(want); }, 120); }
                    if (location.hash === '#scDiff' || want !== '') { setTimeout(function () { box.scrollIntoView({ block: 'start' }); }, 150); }
                })();
                </script>
            <?php endif; ?>
            <?php $mayDeleteActive = scMayDeleteCount($activeCount, $user, $canApprove); ?>
            <?php if (!empty($activeCount['is_opening']) && (string)($activeCount['scope'] ?? '') !== 'full'): ?>
                <p class="sc-info-note"><strong>This opening count covers only <?php echo htmlspecialchars(scScopePhrase($activeCount)); ?>.</strong> Every other item keeps the stock it has now. Count the rest later in another opening count.</p>
            <?php endif; ?>
            <?php if ($activeCount['status'] === 'draft' && $mayDeleteActive): ?>
                <div class="sc-draftbox">
                    <div class="sc-rejected__text"><strong>Started this count by mistake?</strong> You can delete it. Nothing in it has changed stock.</div>
                    <div class="sc-actions">
                        <form method="POST">
                            <input type="hidden" name="csrf_token" value="<?php echo $csrf_token; ?>">
                            <input type="hidden" name="count_id" value="<?php echo (int)$activeCount['id']; ?>">
                            <button type="button" class="btn-danger" onclick="scConfirm(this.form,'delete_count','Delete this count? Everything typed into it is removed for good. Stock is not changed.','Delete count')">Delete this count</button>
                        </form>
                    </div>
                </div>
            <?php endif; ?>
            <?php if ($activeCount['status'] === 'rejected' && ($canWorkCount || $mayDeleteActive)): ?>
                <div class="sc-rejected">
                    <div class="sc-rejected__text"><strong>Rejected.</strong> Reason: <?php echo htmlspecialchars($activeCount['rejection_reason'] ?? ''); ?></div>
                    <div class="sc-actions">
                        <?php if ($canWorkCount): ?>
                        <form method="POST">
                            <input type="hidden" name="csrf_token" value="<?php echo $csrf_token; ?>">
                            <input type="hidden" name="action" value="reopen_count">
                            <input type="hidden" name="count_id" value="<?php echo (int)$activeCount['id']; ?>">
                            <button class="btn-secondary">Count again</button>
                        </form>
                        <?php endif; ?>
                        <?php if ($mayDeleteActive): ?>
                        <form method="POST">
                            <input type="hidden" name="csrf_token" value="<?php echo $csrf_token; ?>">
                            <input type="hidden" name="count_id" value="<?php echo (int)$activeCount['id']; ?>">
                            <button type="button" class="btn-danger" onclick="scConfirm(this.form,'delete_count','Delete this rejected count? It is removed for good. Stock is not changed.','Delete count')">Delete count</button>
                        </form>
                        <?php endif; ?>
                    </div>
                </div>
            <?php endif; ?>

            <?php if ($canFix && $scMeasureReady && $activeCount['status'] === 'approved' && isset($_GET['fix'])):
                /* Preview of "these were counted in the smaller measure" (Step U1). Nothing is saved until Confirm. */
                $fixPlan = null;
                $fixErr = '';
                try {
                    $fixPlan = scMeasureFixPlan($pdo, (int)$activeCount['id'], (int)$_GET['fix'], false);
                } catch (RuntimeException $fe) {
                    $fixErr = $fe->getMessage();
                } catch (Throwable $fe) {
                    error_log('stock-count measure fix preview: ' . $fe->getMessage());
                    $fixErr = 'The correction could not be worked out. Nothing was changed. Reload the page and try again.';
                }
            ?>
                <div class="sc-info-note sc-fix" id="sc-fix">
                    <h3 class="sc-fix__title">Correct a reading typed in the smaller measure</h3>
                    <?php if ($fixPlan === null): ?>
                        <p class="sc-fix__err" role="alert"><?php echo htmlspecialchars($fixErr); ?></p>
                        <div class="sc-actions"><a class="btn-secondary" href="stock-count.php?id=<?php echo (int)$activeCount['id']; ?>">Back to the count</a></div>
                    <?php else:
                        $fp = $fixPlan;
                        $fu = (string)$fp['ing']['unit'];
                        $fSmalls = rh_stock_measure_plural($fp['measure']['small'], 2);
                        $fBigs = rh_stock_measure_plural($fp['measure']['big'], 2);
                        $fq = static fn(float $q): string => rh_stock_measure_plural($fp['measure']['big'], $q); // stock unit = the bigger measure here
                    ?>
                        <p><strong><?php echo htmlspecialchars((string)$fp['ing']['name']); ?></strong> was counted as <?php echo htmlspecialchars(rh_stock_qty_fmt($fp['A'], 0)); ?> <?php echo htmlspecialchars($fq($fp['A'])); ?>. If those were <?php echo htmlspecialchars($fSmalls); ?> (<?php echo htmlspecialchars(rh_stock_qty_fmt($fp['n'], 0)); ?> in one <?php echo htmlspecialchars($fp['measure']['big']); ?>), the count really found <?php echo htmlspecialchars(rh_stock_qty_fmt($fp['A2'], 2)); ?> <?php echo htmlspecialchars($fq($fp['A2'])); ?>. This is what correcting it changes:</p>
                        <dl class="sc-fix__grid">
                            <dt>Counted <span class="sc-fix__help">the reading on this count, in <?php echo htmlspecialchars($fu); ?></span></dt>
                            <dd><?php echo htmlspecialchars(rh_stock_qty_fmt($fp['A'], 2)); ?> → <strong><?php echo htmlspecialchars(rh_stock_qty_fmt($fp['A2'], 2)); ?></strong></dd>
                            <dt>Extra stock the count added <span class="sc-fix__help">counted minus what should have been there</span></dt>
                            <dd><?php echo htmlspecialchars(rh_stock_qty_fmt($fp['V'], 2)); ?> → <strong><?php echo htmlspecialchars(rh_stock_qty_fmt($fp['V2'], 2)); ?></strong></dd>
                            <dt>Stock now <span class="sc-fix__help">on hand today; <?php echo htmlspecialchars(rh_stock_qty_fmt($fp['R'], 2)); ?> <?php echo htmlspecialchars($fq($fp['R'])); ?> come off</span></dt>
                            <dd><?php echo htmlspecialchars(rh_stock_qty_fmt($fp['stock_now'], 2)); ?> → <strong><?php echo htmlspecialchars(rh_stock_qty_fmt($fp['stock_after'], 2)); ?></strong></dd>
                            <dt>Average cost <span class="sc-fix__help">what one <?php echo htmlspecialchars($fu); ?> in stock is valued at; the <?php echo htmlspecialchars(rh_stock_qty_fmt($fp['R'], 2)); ?> taken back came in at <?php echo htmlspecialchars($currency); ?> <?php echo number_format($fp['cost'], 2); ?> each</span></dt>
                            <dd><?php echo htmlspecialchars($currency); ?> <?php echo number_format($fp['avg_old'], 4); ?> → <strong><?php echo htmlspecialchars($currency); ?> <?php echo number_format($fp['avg_new'], 4); ?></strong></dd>
                            <dt>Value counted <span class="sc-fix__help">counted × <?php echo htmlspecialchars($currency); ?> <?php echo number_format($fp['cost'], 2); ?> per <?php echo htmlspecialchars($fu); ?>, the cost on this count</span></dt>
                            <dd><?php echo htmlspecialchars($currency); ?> <?php echo number_format($fp['old_value'], 2); ?> → <strong><?php echo htmlspecialchars($currency); ?> <?php echo number_format($fp['new_value'], 2); ?></strong> (<?php echo number_format($fp['value_change'], 2); ?>)</dd>
                            <dt>Count surplus value <span class="sc-fix__help">the extra stock this count added, in money</span></dt>
                            <dd><?php echo htmlspecialchars($currency); ?> <?php echo number_format($fp['old_var_cost'], 2); ?> → <strong><?php echo htmlspecialchars($currency); ?> <?php echo number_format($fp['new_var_cost'], 2); ?></strong></dd>
                        </dl>
                        <p class="sc-fix__sub">Batches this count created <span class="sc-fix__help">(received / still left, before → after; first in, first out stays in order)</span></p>
                        <ul class="sc-fix__list">
                            <?php foreach ($fp['batches'] as $fb): ?>
                                <li><?php echo htmlspecialchars($fb['number']); ?>: received <?php echo htmlspecialchars(rh_stock_qty_fmt($fb['received'], 2)); ?> → <?php echo htmlspecialchars(rh_stock_qty_fmt($fb['new_received'], 2)); ?>, left <?php echo htmlspecialchars(rh_stock_qty_fmt($fb['remaining'], 2)); ?> → <?php echo htmlspecialchars(rh_stock_qty_fmt($fb['new_remaining'], 2)); ?></li>
                            <?php endforeach; ?>
                        </ul>
                        <p class="sc-fix__sub"><?php if ($fp['moves_n'] > 0): ?><?php echo (int)$fp['moves_n']; ?> stock movement<?php echo $fp['moves_n'] === 1 ? '' : 's'; ?> since the count was approved (<?php echo htmlspecialchars(rh_stock_qty_fmt(-$fp['moves_out'], 2)); ?> out, <?php echo htmlspecialchars(rh_stock_qty_fmt($fp['moves_in'], 2)); ?> in) stay as recorded.<?php else: ?>No stock has moved since the count was approved.<?php endif; ?> The correction is saved as one stock adjustment and shows in the item's history and the activity log.</p>
                        <form method="POST" class="sc-actions">
                            <input type="hidden" name="csrf_token" value="<?php echo $csrf_token; ?>">
                            <input type="hidden" name="count_id" value="<?php echo (int)$activeCount['id']; ?>">
                            <input type="hidden" name="line_id" value="<?php echo (int)$fp['line']['id']; ?>">
                            <button type="button" class="btn-primary" onclick="scConfirm(this.form, 'measure_fix', <?php echo htmlspecialchars(json_encode('Correct ' . $fp['ing']['name'] . ' on this count to ' . rh_stock_qty_fmt($fp['A2'], 2) . ' ' . $fq($fp['A2']) . ' (' . rh_stock_qty_fmt($fp['A'], 0) . ' ' . $fSmalls . ')? ' . rh_stock_qty_fmt($fp['R'], 2) . ' ' . $fq($fp['R']) . ' come off stock. This cannot be undone here.', JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_TAG | JSON_HEX_AMP), ENT_QUOTES, 'UTF-8'); ?>, 'Correct the count')">Correct the count</button>
                            <a class="btn-secondary" href="stock-count.php?id=<?php echo (int)$activeCount['id']; ?>">Cancel</a>
                        </form>
                    <?php endif; ?>
                </div>
            <?php endif; ?>

            <form method="POST" id="countForm">
                <input type="hidden" name="csrf_token" value="<?php echo $csrf_token; ?>">
                <input type="hidden" name="count_id" value="<?php echo (int)$activeCount['id']; ?>">

                <div class="sc-filter-row">
                    <label class="sc-filter-field">
                        <span class="sc-filter-label">Search ingredient</span>
                        <input type="text" id="scFilterSearch" placeholder="Name, category, unit...">
                    </label>
                    <label class="sc-filter-field">
                        <span class="sc-filter-label"><?php echo $blindCount ? 'Status' : 'Difference'; ?></span>
                        <select id="scFilterVariance">
                            <option value="all">All rows</option>
                            <?php if (!$blindCount): ?>
                                <option value="shortage">Shortage only</option>
                                <option value="surplus">Surplus only</option>
                                <option value="balanced">Balanced only</option>
                            <?php endif; ?>
                            <option value="counted">Counted only</option>
                            <option value="uncounted">Not counted only</option>
                        </select>
                    </label>
                    <label class="sc-filter-field">
                        <span class="sc-filter-label">Reason</span>
                        <select id="scFilterReason">
                            <option value="all">All reasons</option>
                            <option value="none">No reason</option>
                            <?php foreach (['spillage', 'expired', 'staff_meal', 'sampling', 'prep_waste', 'theft_suspected', 'correction', 'other'] as $rc): ?>
                                <option value="<?php echo htmlspecialchars($rc); ?>"><?php echo htmlspecialchars(ucfirst(str_replace('_', ' ', $rc))); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </label>
                    <button type="button" class="btn-secondary" id="scFilterReset"><i class="fas fa-rotate-left"></i> Reset</button>
                    <span id="scFilterCount" class="sc-filter-count"></span>
                </div>

                <div class="card sc-lines-card">
                    <table class="lines-table no-card-mobile">
                        <thead>
                            <tr>
                                <th>Ingredient</th>
                                <?php if (!$blindCount): ?>
                                <th class="col-optional-md" style="text-align:right;">In stock at start <i class="help" tabindex="0" role="button" aria-label="About stock at the start" data-tip="What the system showed on hand for this item when the count was started.">?</i></th>
                                <th class="col-optional-md" style="text-align:right;">Should be there <i class="help" tabindex="0" role="button" aria-label="About stock that should be there" data-tip="The start figure plus sales, deliveries and wastage recorded since the count started. Blank until the item is counted.">?</i></th>
                                <?php endif; ?>
                                <th style="text-align:right;">Counted <i class="help" tabindex="0" role="button" aria-label="About counted" data-tip="What you physically counted, in the ingredient&#x27;s own unit. An item with a count measure (for example bottle and shot) can be typed in either measure or both, and is worked out into its own unit.">?</i></th>
                                <?php if ($isOpeningCount): ?><th style="text-align:right;">Unit cost (<?php echo htmlspecialchars($currency); ?>) <i class="help" tabindex="0" role="button" aria-label="About unit cost" data-tip="What one unit of this item cost you. Needed for every item on an opening count so the stock can be valued.">?</i></th><?php endif; ?>
                                <?php if (!$blindCount): ?>
                                <th style="text-align:right;">Difference <i class="help" tabindex="0" role="button" aria-label="About difference" data-tip="Counted minus what should be there. A minus figure means stock is missing.">?</i></th>
                                <th class="col-optional-md" style="text-align:right;">Value <i class="help" tabindex="0" role="button" aria-label="About value of the difference" data-tip="The difference times the cost per unit, in money.">?</i></th>
                                <?php endif; ?>
                                <th>Reason</th>
                                <th>Notes</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($activeLines as $ln):
                                $sys = (float)$ln['system_quantity'];
                                $act = (float)$ln['actual_quantity'];
                                $var = (float)$ln['variance'];
                                $varCost = (float)$ln['variance_cost'];
                                $lineCounted = !empty($ln['_counted']);
                                $expectedAt = $ln['_expected'];
                                $rowClass = '';
                                if (!$blindCount && $lineCounted) {
                                    $rowClass = ($ln['_level'] ?? 'none') === 'block' ? 'row-block' : (($ln['_level'] ?? 'none') === 'alert' ? 'row-flag' : '');
                                }
                                $varClass = (!$lineCounted || abs($var) < 0.0001) ? 'var-zero' : ($var > 0 ? 'var-pos' : 'var-neg');
                                /* Count measure (Step U1): how this line can be / was typed. */
                                $lm = $scMeasureReady ? rh_stock_measure_get($ln) : null;
                                $lmMode = (string)($ln['entry_mode'] ?? '');
                                if ($lm === null || !in_array($lmMode, ['big', 'small', 'both'], true) || $lmMode === $lm['side']) {
                                    $lmMode = 'stock';
                                }
                                $lmOther = $lm ? ($lm['side'] === 'big' ? 'small' : 'big') : '';
                                $lmFix = $lm && $canFix && $activeCount['status'] === 'approved' && $lm['side'] === 'big' && $lm['n'] > 1.00005
                                    && $lmMode === 'stock' && $var > 0.0001 && !empty($ln['adjustment_id']);
                                $lmVal = static fn($v): string => $v === null ? '' : rh_stock_qty_fmt((float)$v, 0, '');
                            ?>
                                <?php
                                $reasonCode = trim((string)($ln['reason_code'] ?? ''));
                                $varianceBand = !$lineCounted ? 'uncounted' : ($blindCount ? 'counted' : (abs($var) < 0.0001 ? 'balanced' : ($var > 0 ? 'surplus' : 'shortage')));
                                $searchBlob = mb_strtolower(trim((string)$ln['ingredient_name'] . ' ' . (string)$ln['category'] . ' ' . (string)$ln['unit']));
                                ?>
                                <tr class="<?php echo $rowClass; ?>"
                                    data-filter-search="<?php echo htmlspecialchars($searchBlob, ENT_QUOTES, 'UTF-8'); ?>"
                                    data-filter-variance="<?php echo htmlspecialchars($varianceBand, ENT_QUOTES, 'UTF-8'); ?>"
                                    data-filter-reason="<?php echo htmlspecialchars($reasonCode !== '' ? $reasonCode : 'none', ENT_QUOTES, 'UTF-8'); ?>">
                                    <td data-label="Ingredient">
                                        <div style="font-weight:600;"><?php echo htmlspecialchars($ln['ingredient_name']); ?></div>
                                        <div style="font-size:10px; color:#6c757d;"><?php echo htmlspecialchars($ln['category']); ?> · <?php echo htmlspecialchars($ln['unit']); ?> · <?php echo $currency; ?> <?php echo number_format((float)$ln['cost_per_unit'], 2); ?>/<?php echo htmlspecialchars($ln['unit']); ?></div>
                                        <?php if ($lm): ?><div class="sc-mnote" title="This item's count measure: it can be counted in either measure or both."><?php echo htmlspecialchars(rh_stock_qty_fmt($lm['n'], 0) . ' ' . rh_stock_measure_plural($lm['small'], $lm['n']) . ' in one ' . $lm['big']); ?></div><?php endif; ?>
                                        <?php if ($canMeasure && $scMeasureReady && in_array((string)$activeCount['status'], ['draft', 'submitted'], true)): ?>
                                            <button type="button" class="sc-mset" onclick="scMeasureOpen(this)"
                                                data-line="<?php echo (int)$ln['id']; ?>"
                                                data-name="<?php echo htmlspecialchars((string)$ln['ingredient_name'], ENT_QUOTES, 'UTF-8'); ?>"
                                                data-unit="<?php echo htmlspecialchars((string)$ln['unit'], ENT_QUOTES, 'UTF-8'); ?>"
                                                data-big="<?php echo htmlspecialchars($lm['big'] ?? '', ENT_QUOTES, 'UTF-8'); ?>"
                                                data-small="<?php echo htmlspecialchars($lm['small'] ?? '', ENT_QUOTES, 'UTF-8'); ?>"
                                                data-n="<?php echo $lm ? htmlspecialchars(rh_stock_qty_fmt($lm['n'], 0, ''), ENT_QUOTES, 'UTF-8') : ''; ?>"
                                                data-side="<?php echo htmlspecialchars($lm['side'] ?? '', ENT_QUOTES, 'UTF-8'); ?>"
                                                data-mode="<?php echo htmlspecialchars((string)($ln['entry_mode'] ?? '') === 'both' ? 'both' : $lmMode, ENT_QUOTES, 'UTF-8'); ?>"
                                                data-counted="<?php echo $lineCounted ? '1' : '0'; ?>"><i class="fas fa-ruler" aria-hidden="true"></i> <?php echo $lm ? 'Change measure' : 'Set measure'; ?></button>
                                        <?php endif; ?>
                                        <?php if ($lmFix): ?>
                                            <a class="sc-mset" href="stock-count.php?id=<?php echo (int)$activeCount['id']; ?>&amp;fix=<?php echo (int)$ln['id']; ?>#sc-fix" title="If this reading was really in <?php echo htmlspecialchars(rh_stock_measure_plural($lm['small'], 2)); ?>, see what correcting it would change before anything is saved."><i class="fas fa-wrench" aria-hidden="true"></i> Counted in <?php echo htmlspecialchars(rh_stock_measure_plural($lm['small'], 2)); ?>?</a>
                                        <?php endif; ?>
                                    </td>
                                    <?php if (!$blindCount): ?>
                                    <td class="col-optional-md" data-label="In stock at start" style="text-align:right;"<?php echo rh_stock_qty_sort_attr($sys); ?>><?php echo rh_stock_qty_html($sys, $ln, (string)$ln['unit']); ?></td>
                                    <td class="col-optional-md" data-label="Should be there" style="text-align:right;"<?php echo $expectedAt === null ? '' : rh_stock_qty_sort_attr((float)$expectedAt); ?>><?php echo $expectedAt === null ? '—' : rh_stock_qty_html((float)$expectedAt, $ln, (string)$ln['unit']); ?></td>
                                    <?php endif; ?>
                                    <td data-label="Counted" style="text-align:right;"<?php echo $lineCounted ? rh_stock_qty_sort_attr($act) : ''; ?>>
                                        <?php if ($editable): ?>
                                            <?php if ($lm): ?>
                                            <div class="sc-measure" data-mode="<?php echo htmlspecialchars($lmMode); ?>" data-n="<?php echo htmlspecialchars(rh_stock_qty_fmt($lm['n'], 0, '')); ?>" data-side="<?php echo htmlspecialchars($lm['side']); ?>"
                                                data-big="<?php echo htmlspecialchars($lm['big'], ENT_QUOTES, 'UTF-8'); ?>" data-small="<?php echo htmlspecialchars($lm['small'], ENT_QUOTES, 'UTF-8'); ?>"
                                                data-bigs="<?php echo htmlspecialchars(rh_stock_measure_plural($lm['big'], 2), ENT_QUOTES, 'UTF-8'); ?>" data-smalls="<?php echo htmlspecialchars(rh_stock_measure_plural($lm['small'], 2), ENT_QUOTES, 'UTF-8'); ?>">
                                                <select class="sc-mmode" name="line[<?php echo (int)$ln['id']; ?>][mode]" aria-label="Counted in" title="Which measure you typed this count in" onchange="scMeasureMode(this)">
                                                    <option value="stock"<?php echo $lmMode === 'stock' ? ' selected' : ''; ?>>In <?php echo htmlspecialchars(rh_stock_measure_plural($lm[$lm['side']], 2)); ?></option>
                                                    <option value="<?php echo $lmOther; ?>"<?php echo $lmMode === $lmOther ? ' selected' : ''; ?>>In <?php echo htmlspecialchars(rh_stock_measure_plural($lm[$lmOther], 2)); ?></option>
                                                    <option value="both"<?php echo $lmMode === 'both' ? ' selected' : ''; ?>>In <?php echo htmlspecialchars(rh_stock_measure_plural($lm['big'], 2) . ' + ' . rh_stock_measure_plural($lm['small'], 2)); ?></option>
                                                </select>
                                            <?php endif; ?>
                                            <input class="qty" type="number" step="0.000001" min="0"<?php echo ($lm && $lmMode !== 'stock') ? ' hidden' : ''; ?>
                                                name="line[<?php echo (int)$ln['id']; ?>][actual]"
                                                value="<?php echo $lineCounted ? number_format($act, 6, '.', '') : ''; ?>"
                                                placeholder="not counted"
                                                <?php if (!$blindCount): ?>data-system="<?php echo number_format((float)($expectedAt ?? $ln['_preview']), 6, '.', ''); ?>"<?php endif; ?>

                                                data-cost="<?php echo number_format((float)$ln['cost_per_unit'], 4, '.', ''); ?>"
                                                oninput="recalcRow(this)<?php echo $lm ? '; scMeasureConv(this)' : ''; ?>">
                                            <input type="hidden" class="touched" name="line[<?php echo (int)$ln['id']; ?>][touched]" value="0">
                                            <?php if ($lm): ?>
                                                <?php foreach (['big', 'small'] as $lmPart): $lmShow = $lmMode === 'both' || $lmMode === $lmPart; ?>
                                                <span class="sc-mfield" data-part="<?php echo $lmPart; ?>"<?php echo $lmShow ? '' : ' hidden'; ?>>
                                                    <input class="qty sc-m<?php echo $lmPart; ?>" type="number" step="any" min="0" name="line[<?php echo (int)$ln['id']; ?>][<?php echo $lmPart; ?>]"
                                                        value="<?php echo $lmShow ? htmlspecialchars($lmVal($ln['entry_' . $lmPart] ?? null)) : ''; ?>" placeholder="0"
                                                        aria-label="<?php echo htmlspecialchars(rh_stock_measure_plural($lm[$lmPart], 2)); ?> counted"
                                                        oninput="scMeasureInput(this)" onchange="applyStockLineFilters()">
                                                    <span class="sc-munit"><?php echo htmlspecialchars(rh_stock_measure_plural($lm[$lmPart], 2)); ?></span>
                                                </span>
                                                <?php endforeach; ?>
                                                <div class="sc-mconv" aria-live="polite" title="What you typed, worked out in the other measure"></div>
                                                <div class="sc-mcost" title="Cost of one <?php echo htmlspecialchars($lm['small']); ?>: the unit cost divided by how many are in one <?php echo htmlspecialchars($lm['big']); ?>"></div>
                                            </div>
                                            <?php endif; ?>
                                        <?php else: ?>
                                            <?php echo $lineCounted ? rh_stock_qty_html($act, $ln, (string)$ln['unit']) : '<span style="color:#6c757d;">not counted</span>'; ?>
                                            <?php if ($lineCounted && $lm && $lmMode !== 'stock'): ?><div class="sc-mnote" title="What the counter typed; the figure above is that worked out in <?php echo htmlspecialchars((string)$ln['unit']); ?>">counted as <?php echo htmlspecialchars(scLineTypedLabel($ln, $lm, (string)$ln['unit'])); ?></div><?php endif; ?>
                                        <?php endif; ?>
                                    </td>
                                    <?php if ($isOpeningCount): ?>
                                    <td data-label="Unit cost (<?php echo htmlspecialchars($currency); ?>)" style="text-align:right;">
                                        <?php if ($editable): ?>
                                            <input class="qty qty--cost" type="number" step="0.0001" min="0" name="line[<?php echo (int)$ln['id']; ?>][cost]" value="<?php echo (float)$ln['cost_per_unit'] > 0 ? number_format((float)$ln['cost_per_unit'], 4, '.', '') : ''; ?>" placeholder="required"<?php echo $lm ? ' oninput="scMeasureConv(this)"' : ''; ?>>
                                            <?php if ($lm): ?><div class="sc-mnote" title="The unit cost is for one of the item's own unit, not for one <?php echo htmlspecialchars($lm[$lmOther]); ?>">per <?php echo htmlspecialchars($lm[$lm['side']]); ?></div><?php endif; ?>
                                        <?php else: ?>
                                            <?php echo number_format((float)$ln['cost_per_unit'], 4); ?>
                                        <?php endif; ?>
                                    </td>
                                    <?php endif; ?>
                                    <?php if (!$blindCount): ?>
                                    <td data-label="Difference" style="text-align:right;" class="var <?php echo $varClass; ?>" data-qty-unit="<?php echo htmlspecialchars((string)$ln['unit'], ENT_QUOTES, 'UTF-8'); ?>"<?php if ($lm): ?> data-m-big="<?php echo htmlspecialchars($lm['big'], ENT_QUOTES, 'UTF-8'); ?>" data-m-small="<?php echo htmlspecialchars($lm['small'], ENT_QUOTES, 'UTF-8'); ?>" data-m-n="<?php echo htmlspecialchars(rh_stock_qty_fmt((float)$lm['n'], 0, ''), ENT_QUOTES, 'UTF-8'); ?>" data-m-side="<?php echo htmlspecialchars($lm['side'], ENT_QUOTES, 'UTF-8'); ?>"<?php endif; ?><?php echo $lineCounted ? rh_stock_qty_sort_attr($var) : ''; ?>><?php echo $lineCounted ? rh_stock_qty_html($var, $ln, (string)$ln['unit'], true) : '—'; ?></td>
                                    <td data-label="Value" style="text-align:right;" class="col-optional-md varCost <?php echo $varClass; ?>"><?php echo $lineCounted ? $currency . ' ' . number_format($varCost, 2) : '—'; ?></td>
                                    <?php endif; ?>
                                    <td data-label="Reason">
                                        <?php if ($editable): ?>
                                            <select class="reason" name="line[<?php echo (int)$ln['id']; ?>][reason]">
                                                <option value="" <?php echo $ln['reason_code'] === '' ? ' selected' : ''; ?>><?php echo $blindCount ? 'Reason, if short' : '—'; ?></option>
                                                <?php foreach (['spillage', 'expired', 'staff_meal', 'sampling', 'prep_waste', 'theft_suspected', 'correction', 'other'] as $rc): ?>
                                                    <option value="<?php echo $rc; ?>" <?php echo $ln['reason_code'] === $rc ? ' selected' : ''; ?>><?php echo ucfirst(str_replace('_', ' ', $rc)); ?></option>
                                                <?php endforeach; ?>
                                            </select>
                                        <?php else: ?>
                                            <?php echo htmlspecialchars($ln['reason_code'] ?: '—'); ?>
                                        <?php endif; ?>
                                    </td>
                                    <td data-label="Notes">
                                        <?php if ($editable): ?>
                                            <input class="notes" type="text" maxlength="500" name="line[<?php echo (int)$ln['id']; ?>][notes]" value="<?php echo htmlspecialchars($ln['reason_notes'] ?? ''); ?>">
                                        <?php else: ?>
                                            <?php echo htmlspecialchars($ln['reason_notes'] ?? ''); ?>
                                        <?php endif; ?>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>

                <div class="sc-actionbar">
                    <?php if ($editable && $canWorkCount): ?>
                        <button type="submit" name="action" value="save_lines" class="btn-secondary"><i class="fas fa-save"></i> Save readings</button>
                        <?php $oneStepSubmit = !$secondRuleOn && rh_sca_may_decide((int)$user['id'], (int)$activeCount['counted_by'], $canApprove, false); ?>
                        <?php if ($oneStepSubmit): ?>
                        <button type="button" class="btn-primary" onclick="scSubmitConfirm(this.form, true)"><i class="fas fa-check"></i> Submit &amp; correct stock</button>
                        <?php else: ?>
                        <button type="button" class="btn-primary" onclick="scSubmitConfirm(this.form, false)"><i class="fas fa-paper-plane"></i> Submit for approval</button>
                        <label class="sc-emailbox">
                            <input type="checkbox" name="email_approvers" value="1" checked>
                            Email the approvers
                            <?php $draftApprovers = $approversFor((int)$activeCount['counted_by']); ?>
                            <span class="sc-emailbox__who">(<?php echo $draftApprovers ? htmlspecialchars(rh_sca_approver_names($draftApprovers)) : 'nobody else can approve yet'; ?>)</span>
                        </label>
                        <?php endif; ?>
                    <?php elseif ($isSubmitted && $canDecide && $blockSelfApprove): ?>
                        <span class="sc-lockednote"><i class="fas fa-user-lock"></i> <?php echo $isOpeningCount ? 'An opening stock count' : ($isImportCount ? 'A stock count filed by Import' : 'This count has shortages over the approval threshold, so it'); ?> must be approved by someone other than the person who counted it.</span>
                        <button type="button" class="btn-danger" onclick="rejectPrompt(this.form)"><i class="fas fa-times"></i> Reject count</button>
                        <input type="hidden" name="rejection_reason" id="rejection_reason" value="">
                        <span id="reject_err" style="color:#c82333;font-size:13px;display:none;"></span>
                    <?php elseif ($isSubmitted && $canDecide): ?>
                        <button type="button" class="btn-success" onclick="scConfirm(this.form,'approve_count','Approve this count? Stock will be corrected to match what was counted. This cannot be undone.')"><i class="fas fa-check"></i> Approve &amp; correct stock</button>
                        <button type="button" class="btn-danger" onclick="rejectPrompt(this.form)"><i class="fas fa-times"></i> Reject count</button>
                        <input type="hidden" name="rejection_reason" id="rejection_reason" value="">
                        <span id="reject_err" style="color:#c82333;font-size:13px;display:none;"></span>
                    <?php elseif ($activeCount['status'] === 'approved'): ?>
                        <span style="color:#155724;"><i class="fas fa-check-circle"></i> Approved by <?php echo htmlspecialchars($activeCount['approved_by_name'] ?: '?'); ?> on <?php echo $activeCount['approved_at'] ? date('Y-m-d H:i', strtotime($activeCount['approved_at'])) : '?'; ?>. Variances posted to stock.</span>
                    <?php endif; ?>
                </div>
            </form>

            <script>
                const currency = <?php echo json_encode($currency); ?>;

                function applyStockLineFilters() {
                    const qEl = document.getElementById('scFilterSearch');
                    const varianceEl = document.getElementById('scFilterVariance');
                    const reasonEl = document.getElementById('scFilterReason');
                    const countEl = document.getElementById('scFilterCount');
                    const rows = Array.from(document.querySelectorAll('#countForm .lines-table tbody tr'));
                    if (!qEl || !varianceEl || !reasonEl || !countEl || !rows.length) return;

                    const q = (qEl.value || '').trim().toLowerCase();
                    const varianceFilter = varianceEl.value || 'all';
                    const reasonFilter = reasonEl.value || 'all';
                    let visible = 0;

                    rows.forEach((row) => {
                        const hay = (row.dataset.filterSearch || '').toLowerCase();
                        const rowVariance = row.dataset.filterVariance || 'balanced';
                        const rowReason = row.dataset.filterReason || 'none';

                        const matchSearch = q === '' || hay.includes(q);
                        // "Counted only" = any row with a reading (typed or saved), whatever its difference band.
                        const matchVariance = varianceFilter === 'all'
                            || (varianceFilter === 'counted' ? rowVariance !== 'uncounted' : rowVariance === varianceFilter);
                        const matchReason = reasonFilter === 'all' || rowReason === reasonFilter;
                        const show = matchSearch && matchVariance && matchReason;
                        row.style.display = show ? '' : 'none';
                        if (show) visible += 1;
                    });

                    countEl.textContent = `${visible} of ${rows.length} rows`;
                }

                function syncRowFilterStateFromInputs(input) {
                    const tr = input.closest('tr');
                    if (!tr) return;
                    if (input.dataset.system === undefined) { // blind count: no expected figure on the page
                        tr.dataset.filterVariance = input.value.trim() === '' ? 'uncounted' : 'counted';
                        return;
                    }
                    const sys = parseFloat(input.dataset.system || '0') || 0;
                    if (input.value.trim() === '') {
                        tr.dataset.filterVariance = 'uncounted';
                        return;
                    }
                    const act = parseFloat(input.value) || 0;
                    const variance = act - sys;
                    tr.dataset.filterVariance = Math.abs(variance) < 0.0001 ? 'balanced' : (variance > 0 ? 'surplus' : 'shortage');
                }

                /* Same rules as rh_stock_qty_words() in config/database.php (display only): an item with a count measure shows whole
                 * bigs + the rest in smalls (small part 1 dp, a full big rolls up), "over by" / "short by" for a difference; no measure =
                 * the number at most 2 dp + the unit. d = the Difference cell's dataset (qtyUnit, mBig, mSmall, mN, mSide). */
                function scQtyWords(qty, d, signed) {
                    const fmt = function (v, dp) { return Number(v).toLocaleString('en-US', { maximumFractionDigits: dp }); };
                    const plural = function (name, q) { return (Math.abs(q - 1) < 0.0000005 || name === '') ? name : name + (/(s|x|z|ch|sh)$/i.test(name) ? 'es' : 's'); };
                    const n = parseFloat(d.mN || '');
                    if (!d.mBig || !d.mSmall || !(n >= 1) || (d.mSide !== 'big' && d.mSide !== 'small')) {
                        let r = Math.round(qty * 100) / 100;
                        if (Math.abs(r) < 0.005) r = 0;
                        const num = (signed && r > 0 ? '+' : '') + fmt(r, 2);
                        return d.qtyUnit ? num + ' ' + d.qtyUnit : num;
                    }
                    const smalls = Math.round(Math.abs(d.mSide === 'big' ? qty * n : qty) * 10) / 10;
                    let bigs = Math.floor(smalls / n + 1e-9);
                    let rest = Math.round((smalls - bigs * n) * 10) / 10;
                    if (rest < 0.05) { rest = 0; } else if (rest >= Math.round(n * 10) / 10 - 1e-9) { bigs++; rest = 0; }
                    const part = function (q, name) { return fmt(q, 6) + ' ' + plural(name, q); };
                    if (bigs === 0 && rest <= 0) return part(0, d.mSide === 'big' ? d.mBig : d.mSmall);
                    const parts = [];
                    if (bigs > 0) parts.push(part(bigs, d.mBig));
                    if (rest > 0) parts.push(part(rest, d.mSmall));
                    let text = parts.join(' + ');
                    if (bigs > 0) text += ' (' + part(Math.round((bigs * n + rest) * 10) / 10, d.mSmall) + ')'; // total in smalls, as rh_stock_qty_words()
                    if (qty < 0) return 'short by ' + text;
                    return signed ? 'over by ' + text : text;
                }
                function recalcRow(input) {
                    const tr = input.closest('tr');
                    const touchedEl = tr.querySelector('.touched');
                    if (touchedEl) touchedEl.value = '1';
                    if (input.dataset.system === undefined) { // blind count: nothing to compute or reveal
                        tr.dataset.filterVariance = input.value.trim() === '' ? 'uncounted' : 'counted';
                        return;
                    }
                    const sys = parseFloat(input.dataset.system) || 0;
                    const cost = parseFloat(input.dataset.cost) || 0;
                    const cells = tr.querySelectorAll('.var, .varCost');
                    if (input.value.trim() === '') {
                        cells[0].textContent = '—';
                        cells[0].dataset.sortValue = '';
                        cells[0].title = '';
                        cells[1].textContent = '—';
                        cells[0].className = 'var var-zero';
                        cells[1].className = 'varCost var-zero';
                        tr.dataset.filterVariance = 'uncounted';
                        return;
                    }
                    const act = parseFloat(input.value) || 0;
                    const variance = act - sys;
                    const varCost = variance * cost;
                    cells[0].textContent = scQtyWords(variance, cells[0].dataset, true);
                    cells[0].dataset.sortValue = variance.toFixed(6);
                    cells[0].title = 'Exact: ' + (+variance.toFixed(6)) + (cells[0].dataset.qtyUnit ? ' ' + cells[0].dataset.qtyUnit : '');
                    cells[1].textContent = currency + ' ' + Number(varCost || 0).toLocaleString('en-US', {
                        minimumFractionDigits: 2,
                        maximumFractionDigits: 2
                    });
                    const cls = Math.abs(variance) < 0.0001 ? 'var-zero' : (variance > 0 ? 'var-pos' : 'var-neg');
                    cells[0].className = 'var ' + cls;
                    cells[1].className = 'varCost ' + cls;
                    tr.dataset.filterVariance = Math.abs(variance) < 0.0001 ? 'balanced' : (variance > 0 ? 'surplus' : 'shortage');
                }

                document.addEventListener('DOMContentLoaded', function() {
                    const searchEl = document.getElementById('scFilterSearch');
                    const varianceEl = document.getElementById('scFilterVariance');
                    const reasonEl = document.getElementById('scFilterReason');
                    const resetEl = document.getElementById('scFilterReset');

                    if (searchEl) searchEl.addEventListener('input', applyStockLineFilters);
                    if (varianceEl) varianceEl.addEventListener('change', applyStockLineFilters);
                    if (reasonEl) reasonEl.addEventListener('change', applyStockLineFilters);

                    document.querySelectorAll('#countForm .lines-table .reason').forEach((sel) => {
                        sel.addEventListener('change', function() {
                            const tr = this.closest('tr');
                            if (tr) {
                                tr.dataset.filterReason = this.value && this.value !== '' ? this.value : 'none';
                            }
                            applyStockLineFilters();
                        });
                    });

                    // Only the reading boxes decide counted / not counted (an opening count's unit-cost box must not).
                    document.querySelectorAll('#countForm .lines-table .qty[name$="][actual]"]').forEach((input) => {
                        syncRowFilterStateFromInputs(input);
                        // Re-filter when the box is left, not on every keystroke, so a row does not vanish while it is being typed.
                        input.addEventListener('change', applyStockLineFilters);
                    });

                    if (resetEl) {
                        resetEl.addEventListener('click', function() {
                            if (searchEl) searchEl.value = '';
                            if (varianceEl) varianceEl.value = 'all';
                            if (reasonEl) reasonEl.value = 'all';
                            applyStockLineFilters();
                        });
                    }

                    applyStockLineFilters();
                });

                /* Count measures (Step U1). The reading box ([actual]) always holds the figure in the item's own unit:
                 * typing in the other measure (or both) works it out into that box, so the difference, the filters and
                 * Submit see one figure. The server works it out again from what was typed; the page figure is a preview. */
                function scMRound(n) { return Math.round(n * 1e6) / 1e6; }
                function scMFmt(n, dp) { const t = scMRound(n).toFixed(dp); return t.indexOf('.') < 0 ? t : t.replace(/0+$/, '').replace(/\.$/, ''); }
                function scMeasureParts(box) {
                    return {
                        n: parseFloat(box.dataset.n) || 1,
                        side: box.dataset.side === 'small' ? 'small' : 'big',
                        big: box.querySelector('.sc-mbig'),
                        small: box.querySelector('.sc-msmall'),
                        mode: box.querySelector('.sc-mmode')
                    };
                }
                function scMeasureConv(el) {
                    const tr = el.closest('tr');
                    const box = tr ? tr.querySelector('.sc-measure') : null;
                    if (!box) return;
                    const p = scMeasureParts(box);
                    const act = tr.querySelector('input[name$="][actual]"]');
                    const conv = box.querySelector('.sc-mconv');
                    const costEl = box.querySelector('.sc-mcost');
                    const mode = p.mode.value;
                    const v = act && act.value.trim() !== '' ? parseFloat(act.value) : null;
                    if (v === null || isNaN(v)) {
                        conv.textContent = '';
                    } else if (mode === 'stock') {
                        // Typed in the item's own unit: show it in the other measure too.
                        const other = p.side === 'big' ? v * p.n : v / p.n;
                        conv.textContent = '= ' + scMFmt(other, 2) + ' ' + (p.side === 'big' ? box.dataset.smalls : box.dataset.bigs);
                    } else {
                        conv.textContent = '= ' + scMFmt(v, 4) + ' ' + (p.side === 'big' ? box.dataset.bigs : box.dataset.smalls);
                    }
                    const costIn = tr.querySelector('input[name$="][cost]"]');
                    const cost = costIn ? parseFloat(costIn.value) : parseFloat(act ? act.dataset.cost : '');
                    if (costEl) {
                        costEl.textContent = cost > 0 ? '1 ' + box.dataset.small + ' = ' + currency + ' ' + (p.side === 'big' ? cost / p.n : cost).toLocaleString('en-US', { minimumFractionDigits: 2, maximumFractionDigits: 2 }) : '';
                    }
                }
                function scMeasureInput(el) {
                    const tr = el.closest('tr');
                    const box = tr ? tr.querySelector('.sc-measure') : null;
                    if (!box) return;
                    const p = scMeasureParts(box);
                    const act = tr.querySelector('input[name$="][actual]"]');
                    const mode = p.mode.value;
                    if (mode !== 'stock' && act) {
                        const bv = mode === 'small' ? '' : p.big.value.trim();
                        const sv = mode === 'big' ? '' : p.small.value.trim();
                        if (bv === '' && sv === '') {
                            act.value = '';
                        } else {
                            const b = parseFloat(bv) || 0;
                            const s = parseFloat(sv) || 0;
                            act.value = String(scMRound(p.side === 'big' ? b + s / p.n : b * p.n + s));
                        }
                        recalcRow(act);
                    }
                    scMeasureConv(el);
                }
                /* Switch how a line is typed. One number typed alone carries over as typed ("602" bottles -> "602"
                 * shots: the usual fix when shots were typed as bottles); leaving "both" keeps the amount it meant. */
                function scMeasureMode(sel) {
                    const tr = sel.closest('tr');
                    const box = sel.closest('.sc-measure');
                    const p = scMeasureParts(box);
                    const act = tr.querySelector('input[name$="][actual]"]');
                    const other = p.side === 'big' ? 'small' : 'big';
                    const sideEl = p.side === 'big' ? p.big : p.small;
                    const otherEl = p.side === 'big' ? p.small : p.big;
                    const prev = box.dataset.mode || 'stock';
                    const next = sel.value;
                    if (prev === 'both') {
                        if (next === other) {
                            const v = act.value.trim();
                            otherEl.value = v === '' ? '' : String(scMRound(p.side === 'big' ? parseFloat(v) * p.n : parseFloat(v) / p.n));
                        }
                    } else {
                        const single = prev === 'stock' ? act.value : otherEl.value;
                        if (next === 'stock') {
                            act.value = single;
                        } else if (next === other) {
                            otherEl.value = single;
                        } else { // both
                            sideEl.value = prev === 'stock' ? single : '';
                            otherEl.value = prev === other ? single : '';
                        }
                    }
                    if (next !== 'both') {
                        if (next !== other) otherEl.value = '';
                        sideEl.value = '';
                    }
                    box.dataset.mode = next;
                    act.hidden = next !== 'stock';
                    box.querySelectorAll('.sc-mfield').forEach(function (f) {
                        f.hidden = !(next === 'both' || next === f.dataset.part);
                    });
                    if (next === 'stock') {
                        recalcRow(act);
                        scMeasureConv(act);
                    } else {
                        scMeasureInput(sel);
                    }
                    applyStockLineFilters();
                }
                document.addEventListener('DOMContentLoaded', function () {
                    document.querySelectorAll('#countForm .sc-measure').forEach(function (box) { scMeasureConv(box); });
                });

                function rejectPrompt(form) {
                    const r = prompt('Reject count — reason (min 8 characters):');
                    if (!r || r.trim().length < 8) {
                        const el = document.getElementById('reject_err');
                        if (el) {
                            el.textContent = 'The count was not rejected: give a reason of at least 8 characters so the counter knows what to fix.';
                            el.style.display = '';
                        }
                        return;
                    }
                    document.getElementById('rejection_reason').value = r.trim();
                    const a = document.createElement('input');
                    a.type = 'hidden';
                    a.name = 'action';
                    a.value = 'reject_count';
                    form.appendChild(a);
                    scPackLines(form, false);
                    form.submit();
                }

                /* A 400-line count is ~2,000 form fields (5 per line). PHP drops everything past max_input_vars (1000)
                 * and the host firewall answers "406 Not Acceptable" long before that. So before any send, the
                 * individual line inputs are switched off and, when saving, the CHANGED lines travel in a few
                 * JSON fields (lines_json[]: 50 lines each). The server reads those first, line[...] as a fallback. */
                function scPackLines(form, pack) {
                    if (!form) return;
                    form.querySelectorAll('input[data-sc-packed]').forEach(function (el) { el.remove(); });
                    const fields = Array.from(form.querySelectorAll('[name^="line["]'));
                    if (!fields.length) return;
                    if (pack) {
                        const byId = {};
                        fields.forEach(function (el) {
                            const m = /^line\[(\d+)\]\[(\w+)\]$/.exec(el.name);
                            if (!m) return;
                            (byId[m[1]] = byId[m[1]] || {})[m[2]] = el;
                        });
                        const dirty = [];
                        Object.keys(byId).forEach(function (id) {
                            const f = byId[id];
                            let changed = !!(f.touched && f.touched.value === '1');
                            ['actual', 'cost', 'notes', 'big', 'small'].forEach(function (k) {
                                if (f[k] && f[k].value !== f[k].defaultValue) changed = true;
                            });
                            if (f.reason && Array.from(f.reason.options).some(function (o) { return o.selected !== o.defaultSelected; })) changed = true;
                            if (f.mode && Array.from(f.mode.options).some(function (o) { return o.selected !== o.defaultSelected; })) changed = true;
                            if (!changed) return;
                            const row = { id: parseInt(id, 10), actual: f.actual ? f.actual.value : '', touched: f.touched && f.touched.value === '1' ? '1' : '0' };
                            if (f.cost) row.cost = f.cost.value;
                            if (f.reason) row.reason = f.reason.value;
                            if (f.notes) row.notes = f.notes.value;
                            if (f.mode) { // count measure: the server works the reading out from these (Step U1)
                                row.mode = f.mode.value;
                                row.big = f.big ? f.big.value : '';
                                row.small = f.small ? f.small.value : '';
                            }
                            dirty.push(row);
                        });
                        for (let i = 0; i < dirty.length; i += 50) {
                            const h = document.createElement('input');
                            h.type = 'hidden';
                            h.name = 'lines_json[]';
                            h.value = JSON.stringify(dirty.slice(i, i + 50));
                            h.setAttribute('data-sc-packed', '1');
                            form.appendChild(h);
                        }
                    }
                    /* Switched off only while the browser builds the request (it does that before this timer fires), so a
                     * send that is cancelled, or a page restored from history, leaves the inputs usable. */
                    fields.forEach(function (el) { el.disabled = true; });
                    setTimeout(function () {
                        fields.forEach(function (el) { el.disabled = false; });
                        form.querySelectorAll('input[data-sc-packed]').forEach(function (el) { el.remove(); });
                    }, 0);
                }

                /* Submit sends only the items that have a reading; items with no reading are left out of the count.
                 * Before the confirm, say how many that is, counting what is typed right now. Typed readings that are not
                 * saved yet travel with the submit (scDoConfirm packs them) and the server saves them first. */
                function scSubmitConfirm(form, oneStep) {
                    if (!form) return;
                    const inputs = Array.from(form.querySelectorAll('input[name$="][actual]"]'));
                    const blank = inputs.filter(function (el) { return el.value.trim() === ''; }).length;
                    if (inputs.length > 0 && blank === inputs.length) {
                        scShowBanner('Nothing to submit', 'No item has a reading yet. Type what you counted (0 if there is none) on at least one item, then submit.', true);
                        return;
                    }
                    const base = oneStep
                        ? 'Submit this count and correct stock now? Stock will be changed to match what was counted. This cannot be undone.'
                        : 'Submit count for approval? Once submitted you cannot edit it.';
                    const left = blank > 0
                        ? ' ' + blank + (blank === 1 ? ' item has' : ' items have') + ' no reading and will be left out of this count, so nothing is changed for ' + (blank === 1 ? 'it' : 'them') + '. ' + (blank === 1 ? 'It' : 'They') + ' can be counted in a later count once you have ' + (blank === 1 ? 'it' : 'them') + '.'
                        : '';
                    scConfirm(form, 'submit_count', base + left);
                }

                (function () {
                    const f = document.getElementById('countForm');
                    if (!f) return;
                    f.addEventListener('submit', function (e) {
                        if (e.defaultPrevented) return;
                        const s = e.submitter;
                        scPackLines(f, !!(s && s.value === 'save_lines'));
                    });
                })();
            </script>

        <?php else: ?>
            <!-- ============= LIST + START ============= -->
            <?php echo rh_stock_count_reminder_html($countReminder, '#start-count'); ?>
            <div class="grid-2">
                <div class="count-side">
                    <div class="card" id="start-count">
                        <h3>Start a count</h3>
                        <p class="sc-card-help">Count everything, one category, or just a few items. Surprise counts catch missing stock early.</p>
                        <form method="POST">
                            <input type="hidden" name="csrf_token" value="<?php echo $csrf_token; ?>">
                            <input type="hidden" name="action" value="start_count">
                            <div class="form-field">
                                <label>What to count</label>
                                <select name="scope" id="scope" onchange="onScopeChange()">
                                    <option value="full">Everything</option>
                                    <option value="category" selected>One category</option>
                                    <option value="spot">Only the items I choose</option>
                                </select>
                            </div>
                            <div class="form-field" id="scope-category">
                                <label>Category</label>
                                <select name="scope_value" id="scope_value_cat">
                                    <?php foreach ($categories as $c): ?>
                                        <option value="<?php echo htmlspecialchars($c); ?>"><?php echo htmlspecialchars($c); ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div class="form-field" id="scope-spot" style="display:none;">
                                <label for="scSpotSearch">Items to count</label>
                                <div class="sc-picker">
                                    <input type="search" id="scSpotSearch" class="sc-picker__search" placeholder="Search by name or category" autocomplete="off" aria-controls="scSpotList">
                                    <div class="sc-picker__list" id="scSpotList" role="group" aria-label="Items to count">
                                        <?php foreach ($ingredientList as $ing): ?>
                                            <label class="sc-picker__row" data-search="<?php echo htmlspecialchars(function_exists('mb_strtolower') ? mb_strtolower($ing['category'] . ' ' . $ing['name']) : strtolower($ing['category'] . ' ' . $ing['name']), ENT_QUOTES, 'UTF-8'); ?>">
                                                <input type="checkbox" value="<?php echo (int)$ing['id']; ?>">
                                                <span class="sc-picker__name"><?php echo htmlspecialchars($ing['name']); ?></span>
                                                <span class="sc-picker__cat"><?php echo htmlspecialchars($ing['category']); ?></span>
                                            </label>
                                        <?php endforeach; ?>
                                        <p class="sc-picker__none" id="scSpotNone" hidden>No items match your search.</p>
                                    </div>
                                    <div class="sc-picker__foot">
                                        <span id="scSpotCount" aria-live="polite">0 selected</span>
                                        <button type="button" class="sc-picker__clear" id="scSpotClear">Clear</button>
                                    </div>
                                </div>
                                <input type="hidden" id="scope_value_spot">
                            </div>
                            <div class="form-field">
                                <label>Label (optional)</label>
                                <input type="text" name="shift" placeholder="e.g. Morning, Closing, Surprise">
                            </div>
                            <div class="form-field">
                                <label>Notes (optional)</label>
                                <textarea name="notes" rows="2" placeholder="Why this count?"></textarea>
                            </div>
                            <div class="sc-opening-row">
                                <label class="sc-opening">
                                    <input type="checkbox" name="opening" value="1" id="opening_cb" <?php echo !empty($_GET['opening']) ? 'checked' : ''; ?> onchange="onOpeningChange()">
                                    <span>Opening stock count (first set-up)</span>
                                </label>
                                <i class="help" tabindex="0" role="button" aria-label="About opening stock counts" data-tip="Every item you count needs a unit cost, and someone other than you must approve it. You can count some items now and the rest later. An item can be in only one open opening count at a time.">?</i>
                            </div>
                            <button type="submit" class="btn-primary">Start count</button>
                        </form>
                    </div>

                    <div class="card">
                        <h3>Missing stock (last 30 days)</h3>
                        <p class="sc-card-help">Stock that went down with no sale or wastage behind it: shortages found in counts, and stock taken off by hand. Biggest 20 by cost.</p>
                        <?php if (empty($theftRadar)): ?>
                            <div class="sc-empty">Nothing missing. Every drop in stock matches a sale or a wastage record.</div>
                        <?php else: ?>
                            <?php foreach ($theftRadar as $r):
                                $cost = ((float)$r['shortage'] + (float)$r['manual_out']) * (float)$r['cost_per_unit'];
                                $fmtQ = static fn($q): string => rtrim(rtrim(number_format((float)$q, 6, '.', ''), '0'), '.') ?: '0';
                            ?>
                                <div class="sc-item">
                                    <div class="sc-item__main">
                                        <?php echo htmlspecialchars($r['name']); ?> <span class="sc-item__unit"><?php echo htmlspecialchars($r['unit']); ?></span>
                                        <div class="sc-item__sub">Short in counts <?php echo $fmtQ($r['shortage']); ?> &middot; Taken off by hand <?php echo $fmtQ($r['manual_out']); ?></div>
                                    </div>
                                    <div class="sc-item__cost sc-item__cost--bad"><?php echo htmlspecialchars($currency . ' ' . number_format($cost, 2)); ?></div>
                                </div>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </div>

                    <div class="card">
                        <h3>Stock added by hand (last 30 days)</h3>
                        <p class="sc-card-help">Stock added without a supplier delivery or a count. Adding stock by hand can hide a loss, so check these. Biggest 20 by cost.</p>
                        <?php if (empty($manualAdds)): ?>
                            <div class="sc-empty">Nothing added by hand in the last 30 days.</div>
                        <?php else: ?>
                            <?php foreach ($manualAdds as $m): ?>
                                <div class="sc-item">
                                    <div class="sc-item__main">
                                        <?php echo htmlspecialchars($m['ingredient_name']); ?>
                                        <strong class="sc-item__qty">+<?php echo rtrim(rtrim(number_format((float)$m['qty'], 6, '.', ''), '0'), '.'); ?> <?php echo htmlspecialchars($m['unit']); ?></strong>
                                        <div class="sc-item__sub"><?php echo htmlspecialchars(($m['who'] ?: 'Unknown') . ' · ' . date('Y-m-d H:i', strtotime((string)$m['created_at']))); ?></div>
                                        <div class="sc-item__sub sc-item__sub--wrap"><?php echo htmlspecialchars((string)$m['reason']); ?></div>
                                    </div>
                                    <div class="sc-item__cost"><?php echo htmlspecialchars($currency . ' ' . number_format((float)$m['total_cost'], 2)); ?></div>
                                </div>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </div>
                </div>

                <div>
                    <div class="card">
                        <h3>Counts</h3>
                        <p class="sc-card-help">Open a count to carry on counting, approve it or see the result. Shortage and surplus are what the missing or extra stock is worth.</p>
                        <div class="count-list-wrap">
                        <table class="count-list no-card-mobile">
                            <thead>
                                <tr>
                                    <th>Reference</th>
                                    <th class="col-optional">Date</th>
                                    <th class="col-optional">What was counted</th>
                                    <th class="col-optional">Items <i class="help" tabindex="0" role="button" aria-label="About items" data-tip="How many ingredients are on this count.">?</i></th>
                                    <th class="sc-num">Shortage <i class="help" tabindex="0" role="button" aria-label="About shortage" data-tip="Worth of missing stock found by this count. Shows a dash for draft counts, and for counts not yet approved if you cannot approve counts.">?</i></th>
                                    <th class="sc-num col-optional">Surplus <i class="help" tabindex="0" role="button" aria-label="About surplus" data-tip="Worth of extra stock found by this count. Shows a dash for draft counts, and for counts not yet approved if you cannot approve counts.">?</i></th>
                                    <th class="col-optional">Counted by</th>
                                    <th>Status</th>
                                    <th></th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($counts as $c): ?>
                                    <tr class="sc-row" onclick="window.location='stock-count.php?id=<?php echo (int)$c['id']; ?>';">
                                        <td data-label="Reference"><strong><?php echo htmlspecialchars(displayCountReference((string)$c['reference'])); ?></strong></td>
                                        <td class="col-optional" data-label="Date"><?php echo htmlspecialchars($c['count_date']); ?></td>
                                        <td class="col-optional" data-label="What was counted">
                                            <?php echo htmlspecialchars(scScopeLabel($c)); ?>
                                            <?php if (!empty($c['is_opening'])): ?><div class="count-note">Opening stock</div><?php endif; ?>
                                        </td>
                                        <td class="col-optional" data-label="Items"><?php echo (int)$c['line_count']; ?></td>
                                        <?php
                                        $hideCountFigures = ($c['status'] === 'draft') || (!$canApprove && $c['status'] !== 'approved');
                                        $shortAmt = abs((float)$c['shortage_cost']);
                                        $extraAmt = (float)$c['surplus_cost'];
                                        ?>
                                        <td data-label="Shortage" class="sc-num"><?php
                                            if ($hideCountFigures) echo '—';
                                            elseif ($shortAmt < BALANCE_TOLERANCE) echo '<span class="sc-none">None</span>';
                                            else echo '<span class="sc-money sc-money--short">' . htmlspecialchars($currency . ' ' . number_format($shortAmt, 2)) . '</span>'; ?></td>
                                        <td data-label="Surplus" class="sc-num col-optional"><?php
                                            if ($hideCountFigures) echo '—';
                                            elseif ($extraAmt < BALANCE_TOLERANCE) echo '<span class="sc-none">None</span>';
                                            else echo '<span class="sc-money sc-money--extra">' . htmlspecialchars($currency . ' ' . number_format($extraAmt, 2)) . '</span>'; ?></td>
                                        <td class="col-optional" data-label="Counted by"><?php echo htmlspecialchars($c['counted_by_name'] ?: '—'); ?></td>
                                        <td data-label="Status">
                                            <span class="pill st-<?php echo htmlspecialchars($c['status']); ?>"><?php echo htmlspecialchars(scStatusLabel((string)$c['status'])); ?></span>
                                            <?php if ($c['status'] === 'submitted'):
                                                $rowApprovers = $approversFor((int)$c['counted_by']);
                                                $rowSecond = $secondRuleOn && (!empty($c['is_opening']) || !empty($c['import_job_id']) || ($canApprove && rh_sca_needs_second_person($pdo, $c, $blockPct, $minCost)));
                                            ?>
                                                <?php if ($rowSecond): ?><div class="count-note count-note--second">Needs a second person to approve &mdash; not <?php echo htmlspecialchars((string)($c['counted_by_name'] ?: 'the counter')); ?></div><?php endif; ?>
                                                <div class="count-note">Can approve: <?php echo $rowApprovers ? htmlspecialchars(rh_sca_approver_names($rowApprovers)) : '<span class="count-note--warn">nobody else yet</span>'; ?></div>
                                            <?php endif; ?>
                                        </td>
                                        <td data-label="" class="sc-row-actions"><?php if (scMayDeleteCount($c, $user, $canApprove)): ?><form method="POST" onclick="event.stopPropagation();">
                                                <input type="hidden" name="csrf_token" value="<?php echo $csrf_token; ?>">
                                                <input type="hidden" name="count_id" value="<?php echo (int)$c['id']; ?>">
                                                <button type="button" class="btn-danger btn-sm" data-ref="<?php echo htmlspecialchars(displayCountReference((string)$c['reference'])); ?>" data-what="<?php echo $c['status'] === 'draft' ? 'unfinished' : 'rejected'; ?>" onclick="scConfirm(this.form,'delete_count','Delete ' + this.dataset.what + ' count ' + this.dataset.ref + '? It is removed for good. Stock is not changed.','Delete count')">Delete</button>
                                            </form><?php endif; ?></td>
                                    </tr>
                                <?php endforeach; ?>
                                <?php if (empty($counts)): ?>
                                    <tr>
                                        <td colspan="9" class="sc-empty-row">No counts yet. Start your first one with &ldquo;Start a count&rdquo;.</td>
                                    </tr>
                                <?php endif; ?>
                            </tbody>
                        </table>
                        </div>
                    </div>
                </div>
            </div>

            <script>
                function onOpeningChange() {
                    onScopeChange(); // an opening count can cover everything, one category or chosen items
                }
                function onScopeChange() {
                    const v = document.getElementById('scope').value;
                    document.getElementById('scope-category').style.display = (v === 'category') ? 'block' : 'none';
                    document.getElementById('scope-spot').style.display = (v === 'spot') ? 'block' : 'none';
                    document.getElementById('scope_value_cat').name = (v === 'category') ? 'scope_value' : '';
                    if (v === 'full') {
                        // ensure neither spot nor category sends scope_value
                        document.getElementById('scope_value_cat').name = '';
                    }
                    // Only the chosen-items list sends scope_value (a comma list of item ids) when that scope is picked.
                    document.getElementById('scope_value_spot').name = (v === 'spot') ? 'scope_value' : '';
                }
                // Tick-box list for "Only the items I choose": search narrows the list, ticks stay when the search changes.
                (function () {
                    const list = document.getElementById('scSpotList');
                    const search = document.getElementById('scSpotSearch');
                    const hidden = document.getElementById('scope_value_spot');
                    const countEl = document.getElementById('scSpotCount');
                    const none = document.getElementById('scSpotNone');
                    const rows = Array.from(list.querySelectorAll('.sc-picker__row'));
                    function sync() {
                        const ids = rows.map(r => r.querySelector('input')).filter(i => i.checked).map(i => i.value);
                        hidden.value = ids.join(',');
                        countEl.textContent = ids.length + ' selected';
                    }
                    function filter() {
                        const q = search.value.trim().toLowerCase();
                        let shown = 0;
                        rows.forEach(r => {
                            const hit = q === '' || (r.dataset.search || '').indexOf(q) !== -1;
                            r.hidden = !hit;
                            if (hit) shown++;
                        });
                        none.hidden = shown !== 0;
                    }
                    list.addEventListener('change', sync);
                    search.addEventListener('input', filter);
                    // Enter in the search box must not submit the form.
                    search.addEventListener('keydown', e => { if (e.key === 'Enter') e.preventDefault(); });
                    document.getElementById('scSpotClear').addEventListener('click', () => {
                        rows.forEach(r => { r.querySelector('input').checked = false; });
                        sync();
                    });
                    sync();
                })();
                onOpeningChange();
            </script>
        <?php endif; ?>
    </div>

    <?php /* Result banner: centred, stays until OK / Esc (no timer). Printed with the confirm modal, before the footer, so the
       admin SPA keeps it. Messages carry item names, category names and count references typed by users: escaped here,
       once, as text (nothing escapes them earlier); the script below only ever writes text (textContent). Always
       present (hidden) so the page can also use it for its own "save first" notice. */ ?>
    <?php
    $scBannerShow = (bool)($message || $error);
    $scBannerErr = (bool)$error;
    $scBannerTitle = $scBannerShow ? scBannerTitle((string)($scBannerErr ? $error : $message), $scBannerErr, (string)($action ?? '')) : '';
    ?>
    <div id="scBanner" class="modal-overlay<?php echo $scBannerShow ? ' active' : ''; ?> sc-banner sc-banner--<?php echo $scBannerErr ? 'error' : 'success'; ?>" data-modal role="alertdialog" aria-modal="true" aria-labelledby="scBannerTitle" aria-describedby="scBannerMsg">
        <div class="modal-content">
            <div class="modal-header">
                <h3 class="modal-title" id="scBannerTitle"><?php echo htmlspecialchars($scBannerTitle, ENT_QUOTES, 'UTF-8'); ?></h3>
            </div>
            <div class="modal-body">
                <?php if ($message && $scBannerErr): ?><p class="sc-banner-msg" style="margin-bottom:10px;"><?php echo htmlspecialchars((string)$message, ENT_QUOTES, 'UTF-8'); ?></p><?php endif; ?>
                <p class="sc-banner-msg" id="scBannerMsg"><?php echo htmlspecialchars((string)($scBannerErr ? $error : $message), ENT_QUOTES, 'UTF-8'); ?></p>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-primary" id="scBannerOk" onclick="scCloseBanner()">OK</button>
            </div>
        </div>
    </div>
    <script>
        function scBannerKey(e) {
            if (e.key === 'Escape' || e.key === 'Esc') { e.preventDefault(); scCloseBanner(); }
        }
        function scCloseBanner() {
            var b = document.getElementById('scBanner');
            if (b) b.classList.remove('active');
            document.removeEventListener('keydown', scBannerKey, true);
            if (!document.querySelector('.modal-overlay.active')) document.body.classList.remove('modal-open');
        }
        function scShowBanner(title, message, isError) {
            var b = document.getElementById('scBanner');
            if (!b) return;
            document.getElementById('scBannerTitle').textContent = title;
            var m = document.getElementById('scBannerMsg');
            if (m) {
                /* drop the extra first paragraph (the success part of a two-part result) if one was printed */
                var extra = m.previousElementSibling;
                if (extra && extra.classList.contains('sc-banner-msg')) extra.remove();
                m.textContent = message;
            }
            b.classList.toggle('sc-banner--error', !!isError);
            b.classList.toggle('sc-banner--success', !isError);
            b.classList.add('active');
            scBannerOpened();
        }
        function scBannerOpened() {
            document.body.classList.add('modal-open');
            document.addEventListener('keydown', scBannerKey, true);
            var ok = document.getElementById('scBannerOk');
            if (ok) ok.focus();
        }
        <?php if ($scBannerShow): ?>
        scBannerOpened();
        <?php endif; ?>
    </script>

    <div id="scConfirmModal" class="modal-overlay" data-modal role="dialog" aria-modal="true" aria-labelledby="scConfirmTitle">
        <div class="modal-content" style="max-width:min(96vw,25rem); width:min(96vw,25rem);">
            <div class="modal-header">
                <h3 class="modal-title" id="scConfirmTitle">Confirm Action</h3>
                <button type="button" class="modal-close" aria-label="Close modal" onclick="scCloseConfirm()">&times;</button>
            </div>
            <div class="modal-body">
                <p style="margin:0;font-size:14px;color:#374151;" id="scConfirmMessage">Are you sure?</p>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" onclick="scCloseConfirm()">Cancel</button>
                <button type="button" class="btn btn-primary" id="scConfirmBtn" onclick="scDoConfirm()">Confirm</button>
            </div>
        </div>
    </div>
    <script>
        /* Confirm-modal handlers live outside the active-count branch so the modal below always has them. */
        var _scConfirmForm = null,
            _scConfirmAction = null;

        function scConfirm(form, actionValue, message, okLabel) {
            _scConfirmForm = form;
            _scConfirmAction = actionValue;
            document.getElementById('scConfirmMessage').textContent = message;
            /* A delete says what it does on the button and turns it red; every other confirm stays "Confirm". */
            var okBtn = document.getElementById('scConfirmBtn');
            if (okBtn) {
                okBtn.textContent = okLabel || 'Confirm';
                okBtn.classList.toggle('btn-danger', actionValue === 'delete_count');
                okBtn.classList.toggle('btn-primary', actionValue !== 'delete_count');
            }
            var modal = document.getElementById('scConfirmModal');
            if (modal) {
                modal.classList.add('active');
                document.body.classList.add('modal-open');
            }
        }

        function scCloseConfirm(resetState = true) {
            var modal = document.getElementById('scConfirmModal');
            if (modal) {
                modal.classList.remove('active');
            }
            if (!document.querySelector('.modal-overlay.active')) {
                document.body.classList.remove('modal-open');
            }
            if (resetState) {
                _scConfirmForm = null;
                _scConfirmAction = null;
            }
        }

        function scDoConfirm() {
            var form = _scConfirmForm;
            var actionValue = _scConfirmAction;
            scCloseConfirm(false);
            _scConfirmForm = null;
            _scConfirmAction = null;
            if (!form || !actionValue) return;
            const a = document.createElement('input');
            a.type = 'hidden';
            a.name = 'action';
            a.value = actionValue;
            form.appendChild(a);
            // Draft count form: approve/reject/delete send no per-line fields; a submit sends the changed lines so they are saved with it.
            if (typeof scPackLines === 'function') scPackLines(form, actionValue === 'submit_count');
            form.submit();
        }
    </script>
    <?php if (!empty($activeCount) && $canMeasure && $scMeasureReady && in_array((string)$activeCount['status'], ['draft', 'submitted'], true)):
        $scmSizes = rh_stock_measure_sizes();
        $scmLineMode = $canApprove && (string)$activeCount['status'] === 'submitted';
    ?>
    <div id="scMeasureModal" class="modal-overlay" data-modal role="dialog" aria-modal="true" aria-labelledby="scMeasureTitle">
        <div class="modal-content sc-mmodal">
            <form method="POST" id="scMeasureForm" onsubmit="return scMeasureSubmit(this)">
                <div class="modal-header">
                    <h3 class="modal-title" id="scMeasureTitle">Count measure</h3>
                    <button type="button" class="modal-close" aria-label="Close" onclick="scMeasureClose()">&times;</button>
                </div>
                <div class="modal-body">
                    <input type="hidden" name="csrf_token" value="<?php echo $csrf_token; ?>">
                    <input type="hidden" name="action" value="set_measure">
                    <input type="hidden" name="count_id" value="<?php echo (int)$activeCount['id']; ?>">
                    <input type="hidden" name="line_id" id="scm_line" value="">
                    <p class="sc-mhelp" id="scm_intro"></p>
                    <div class="sc-mgrid">
                        <label>Bigger measure <span class="sc-mhelp">the whole container, e.g. bottle</span>
                            <input type="text" name="measure_big_name" id="scm_big" maxlength="30" placeholder="e.g. bottle" oninput="scMeasureModalSync()"></label>
                        <label>Smaller measure <span class="sc-mhelp">what is poured, e.g. shot or glass</span>
                            <input type="text" name="measure_small_name" id="scm_small" maxlength="30" placeholder="e.g. shot" oninput="scMeasureModalSync()"></label>
                        <label>How many smaller in one bigger <span class="sc-mhelp">1 to <?php echo (int)RH_STOCK_MEASURE_MAX_N; ?></span>
                            <input type="number" name="measure_per_big" id="scm_n" step="any" min="1" max="<?php echo (int)RH_STOCK_MEASURE_MAX_N; ?>" placeholder="e.g. 30"></label>
                        <label>Which one is the stock unit <span class="sc-mhelp">the item's own unit; normally the bigger</span>
                            <select name="measure_stock_side" id="scm_side" onchange="scMeasureModalSync()">
                                <option value="big">The bigger measure</option>
                                <option value="small">The smaller measure</option>
                            </select></label>
                    </div>
                    <div class="sc-msuggest" id="scm_suggest" hidden>
                        <p class="sc-mhelp" id="scm_suggest_txt"></p>
                        <button type="button" class="btn-secondary" id="scm_use_shot" onclick="scMeasureUse('shot')">Use shots</button>
                        <button type="button" class="btn-secondary" id="scm_use_glass" onclick="scMeasureUse('glass')">Use glasses</button>
                    </div>
                    <?php if ($scmLineMode): ?>
                    <label class="sc-mline" id="scm_mode_wrap">How this line was typed <span class="sc-mhelp">switching keeps the number typed and works the reading and difference out again</span>
                        <select name="line_mode" id="scm_mode">
                            <option value="keep">Keep as it is</option>
                            <option value="stock" id="scm_mode_stock">In the stock unit</option>
                            <option value="small" id="scm_mode_other">In the other measure</option>
                        </select></label>
                    <?php endif; ?>
                    <p class="sc-mhelp">Saving re-works this item's readings on counts not yet approved. Approved counts are never changed. Clear the names and the number to remove the measure.</p>
                    <p class="sc-merr" id="scm_err" role="alert" hidden></p>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" onclick="scMeasureClose()">Cancel</button>
                    <button type="submit" class="btn btn-primary">Save measure</button>
                </div>
            </form>
        </div>
    </div>
    <script>
        /* Set measure from a count line (Step U1). Suggestions come from a size in the item name and the shot / glass
         * sizes in Settings; they only fill the fields when Use is pressed. */
        var SCM_ML = <?php echo json_encode(['shot' => $scmSizes['shot_ml'], 'glass' => $scmSizes['glass_ml']]); ?>;
        var scmData = null;
        function scmNameMl(name) {
            var m = /(\d+(?:[.,]\d+)?)\s*(ml|cl|l|ltr|litres?|liters?)(?![a-z])/i.exec(name || '');
            if (!m) return null;
            var v = parseFloat(m[1].replace(',', '.'));
            var u = m[2].toLowerCase();
            var ml = u === 'ml' ? v : (u === 'cl' ? v * 10 : v * 1000);
            return ml > 0 && ml <= 100000 ? ml : null;
        }
        function scmPlural(n) { return /(s|x|z|ch|sh)$/i.test(n) ? n + 'es' : n + 's'; }
        function scMeasureOpen(btn) {
            var form = document.getElementById('countForm');
            var dirty = form && Array.from(form.querySelectorAll('[name^="line["]')).some(function (el) {
                if (el.classList.contains('touched')) return el.value === '1';
                if (el.tagName === 'SELECT') return Array.from(el.options).some(function (o) { return o.selected !== o.defaultSelected; });
                return el.value !== el.defaultValue;
            });
            if (dirty) {
                scShowBanner('Save your readings first', 'Some readings on this page are not saved yet. Press Save readings, then set the measure, so nothing typed is lost.', true);
                return;
            }
            scmData = btn.dataset;
            document.getElementById('scm_line').value = btn.dataset.line;
            document.getElementById('scm_big').value = btn.dataset.big || '';
            document.getElementById('scm_small').value = btn.dataset.small || '';
            document.getElementById('scm_n').value = btn.dataset.n || '';
            document.getElementById('scm_side').value = btn.dataset.side === 'small' ? 'small' : 'big';
            document.getElementById('scm_intro').textContent = 'Stock, prices and sales for ' + btn.dataset.name + ' stay in its own unit (' + btn.dataset.unit + '). A count can then be typed in either measure or both.';
            var err = document.getElementById('scm_err');
            err.hidden = true;
            var wrap = document.getElementById('scm_mode_wrap');
            if (wrap) {
                document.getElementById('scm_mode').value = 'keep';
                wrap.hidden = btn.dataset.counted !== '1' || btn.dataset.mode === 'both';
            }
            scMeasureModalSync();
            var modal = document.getElementById('scMeasureModal');
            modal.classList.add('active');
            document.body.classList.add('modal-open');
            document.getElementById('scm_big').focus();
        }
        function scMeasureClose() {
            document.getElementById('scMeasureModal').classList.remove('active');
            if (!document.querySelector('.modal-overlay.active')) document.body.classList.remove('modal-open');
        }
        function scMeasureModalSync() {
            if (!scmData) return;
            var big = document.getElementById('scm_big').value.trim() || 'bigger measure';
            var small = document.getElementById('scm_small').value.trim() || 'smaller measure';
            var side = document.getElementById('scm_side').value;
            var ml = scmNameMl(scmData.name);
            var box = document.getElementById('scm_suggest');
            if (ml && (SCM_ML.shot > 0 || SCM_ML.glass > 0)) {
                var parts = [];
                if (SCM_ML.shot > 0) parts.push(Math.round(ml / SCM_ML.shot * 10000) / 10000 + ' shots of ' + SCM_ML.shot + ' ml');
                if (SCM_ML.glass > 0) parts.push(Math.round(ml / SCM_ML.glass * 10000) / 10000 + ' glasses of ' + SCM_ML.glass + ' ml');
                document.getElementById('scm_suggest_txt').textContent = 'The name says ' + ml + ' ml: that is ' + parts.join(' or ') + '. Check it before saving.';
                document.getElementById('scm_use_shot').hidden = !(SCM_ML.shot > 0);
                document.getElementById('scm_use_glass').hidden = !(SCM_ML.glass > 0);
                box.hidden = false;
            } else {
                box.hidden = true;
            }
            var st = document.getElementById('scm_mode_stock');
            var ot = document.getElementById('scm_mode_other');
            if (st && ot) {
                st.textContent = 'In ' + scmPlural(side === 'big' ? big : small) + ' (the stock unit)';
                ot.value = side === 'big' ? 'small' : 'big';
                ot.textContent = 'In ' + scmPlural(side === 'big' ? small : big);
            }
        }
        function scMeasureUse(kind) {
            var ml = scmNameMl(scmData ? scmData.name : '');
            if (!ml || !(SCM_ML[kind] > 0)) return;
            if (document.getElementById('scm_big').value.trim() === '') document.getElementById('scm_big').value = 'bottle';
            document.getElementById('scm_small').value = kind;
            document.getElementById('scm_n').value = Math.round(ml / SCM_ML[kind] * 10000) / 10000;
            document.getElementById('scm_side').value = 'big';
            scMeasureModalSync();
        }
        function scMeasureSubmit(form) {
            var big = document.getElementById('scm_big').value.trim();
            var small = document.getElementById('scm_small').value.trim();
            var nRaw = document.getElementById('scm_n').value.trim();
            var n = parseFloat(nRaw);
            var msg = '';
            if (big === '' && small === '' && nRaw === '') {
                msg = '';
            } else if (big === '' || small === '') {
                msg = 'Type both measure names (for example bottle and shot), or clear all the fields to remove the measure.';
            } else if (big.toLowerCase() === small.toLowerCase()) {
                msg = 'The bigger and the smaller measure need different names.';
            } else if (isNaN(n) || n < 1 || n > <?php echo (int)RH_STOCK_MEASURE_MAX_N; ?>) {
                msg = 'How many ' + scmPlural(small) + ' make one ' + big + ' must be a number from 1 to <?php echo (int)RH_STOCK_MEASURE_MAX_N; ?>.';
            }
            var err = document.getElementById('scm_err');
            err.textContent = msg;
            err.hidden = msg === '';
            return msg === '';
        }
    </script>
    <?php endif; ?>
    <?php /* Modals sit before the footer: admin-footer.php closes #rh-admin-page, and the admin SPA
       only swaps that container, so markup printed after it is lost on in-place navigation. */ ?>
    <?php require_once 'includes/admin-footer.php'; ?>
</body>

</html>

