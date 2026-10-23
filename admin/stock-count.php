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
    return 'This count can’t be submitted yet: ' . $what . '. Type a reading on each (0 if there is none), save, and use the “Not counted only” filter to find them.';
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
            foreach (['actual', 'cost', 'reason', 'notes', 'touched'] as $k) {
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
                    throw new RuntimeException('Choose at least one item to count. Pick them in the Items to count list (hold Ctrl for more), or choose One category or Everything.');
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
                    if (empty($ids)) throw new RuntimeException('Choose at least one item to count. Pick them in the Items to count list (hold Ctrl for more), or choose One category or Everything.');
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
                $countId = (int)($_POST['count_id'] ?? 0);
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
                $lines = scReadPostedLines($_POST, array_map('intval', $ownQ->fetchAll(PDO::FETCH_COLUMN)));
                $pdo->beginTransaction(); // all readings or none: a bad value part-way must not leave half a save
                /* The system quantity and cost are the SNAPSHOT taken when the count started (stored on the
                 * line). They are read back from the database, never from the posted form: a tampered or
                 * stale hidden field would otherwise set the variance - and, once approved, the stock
                 * adjustment - to any figure. */
                $snapSel = $pdo->prepare("SELECT ingredient_id, system_quantity, cost_per_unit, actual_quantity, variance" . ($hasCol ? ', counted_at' : '') . " FROM stock_count_lines WHERE id = ? AND count_id = ?");
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
                $_SESSION['stock_msg'] = 'Count readings saved.';
                header('Location: stock-count.php?id=' . $countId);
                exit;
            }

            if ($action === 'submit_count') {
                $countId = (int)($_POST['count_id'] ?? 0);
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
                    throw new RuntimeException('Nothing to submit: no item has a reading yet. Type what you counted (0 if there is none) on at least one item, save readings, then submit again.');
                }

                // Opening stock: every line that adds stock needs a unit cost, or COGS and valuation start at zero.
                if (!empty($count['is_opening'])) {
                    $uncosted = scOpeningUncostedLines($pdo, $countId);
                    if ($uncosted) {
                        throw new RuntimeException('This opening count can’t be submitted yet: ' . count($uncosted) . ' counted line' . (count($uncosted) === 1 ? ' has' : 's have') . ' no unit cost (' . implode(', ', array_slice($uncosted, 0, 6)) . (count($uncosted) > 6 ? ' and ' . (count($uncosted) - 6) . ' more' : '') . '). Type the cost of one unit (more than 0) on each of those lines, save readings, then submit again.');
                    }
                }

                // Validate every shortage line has a reason code
                $missing = $pdo->prepare("SELECT COUNT(*) FROM stock_count_lines WHERE count_id = ? AND variance < 0 AND (reason_code = '' OR reason_code IS NULL)");
                $missing->execute([$countId]);
                if ((int)$missing->fetchColumn() > 0) {
                    throw new RuntimeException('This count can’t be submitted yet: every line that is short needs a reason. Pick a reason on each shortage line (use the Reason filter, “No reason”, to find them), save readings, then submit again.');
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
        $lstmt = $pdo->prepare("
            SELECT scl.*, i.name AS ingredient_name, i.unit, i.category
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
           COALESCE(SUM(CASE WHEN sa.source_type = 'manual' AND sa.quantity_change < 0 THEN ABS(sa.quantity_change) ELSE 0 END), 0) AS manual_out
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
            $isSubmitted = $activeCount['status'] === 'submitted';
            $isOwnCount = (int)$activeCount['counted_by'] === (int)$user['id'];
            $canWorkCount = $isOwnCount || $canApprove;
            $blockSelfApprove = $isSubmitted && ($anyOverThreshold || $isOpeningCount) && $isOwnCount && $secondRuleOn;
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
                $needsSecondKnown = $secondRuleOn && ($isOpeningCount || ($canApprove && $anyOverThreshold));
                $remindWait = rh_sca_remind_wait($pdo, (int)$activeCount['id']);
                $mayRemind = rh_sca_may_remind((int)$user['id'], (int)$activeCount['counted_by'], $canApprove);
            ?>
                <div class="approval-box" style="background:#eef6ff;border:1px solid #b6d4fe;color:#1e3a5f;padding:12px 14px;border-radius:8px;margin-bottom:14px;font-size:13px;line-height:1.6;">
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
                    <form method="POST" style="margin-top:8px;">
                        <input type="hidden" name="csrf_token" value="<?php echo $csrf_token; ?>">
                        <input type="hidden" name="action" value="remind_count">
                        <input type="hidden" name="count_id" value="<?php echo (int)$activeCount['id']; ?>">
                        <?php if ($remindWait > 0): ?>
                            <button type="button" class="btn-secondary" disabled><i class="fas fa-envelope"></i> Approvers emailed &mdash; remind again in <?php echo (int)ceil($remindWait / 60); ?> min</button>
                        <?php else: ?>
                            <button type="submit" class="btn-secondary"><i class="fas fa-envelope"></i> Remind approvers</button>
                            <span style="font-size:12px;color:#556;">Sends them an email with a link to this count.</span>
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

            <form method="POST" id="countForm">
                <input type="hidden" name="csrf_token" value="<?php echo $csrf_token; ?>">
                <input type="hidden" name="count_id" value="<?php echo (int)$activeCount['id']; ?>">

                <div style="display:grid;grid-template-columns:minmax(12rem,1.6fr) repeat(2,minmax(8.8rem,1fr)) auto auto;gap:0.5rem;align-items:end;margin-bottom:0.7rem;">
                    <label style="display:flex;flex-direction:column;gap:0.28rem;">
                        <span style="font-size:11px;color:#6c757d;font-weight:600;text-transform:uppercase;letter-spacing:.04em;">Search ingredient</span>
                        <input type="text" id="scFilterSearch" placeholder="Name, category, unit..." style="min-height:38px;">
                    </label>
                    <label style="display:flex;flex-direction:column;gap:0.28rem;">
                        <span style="font-size:11px;color:#6c757d;font-weight:600;text-transform:uppercase;letter-spacing:.04em;"><?php echo $blindCount ? 'Status' : 'Difference'; ?></span>
                        <select id="scFilterVariance" style="min-height:38px;">
                            <option value="all">All rows</option>
                            <?php if (!$blindCount): ?>
                                <option value="shortage">Shortage only</option>
                                <option value="surplus">Surplus only</option>
                                <option value="balanced">Balanced only</option>
                            <?php endif; ?>
                            <option value="uncounted">Not counted only</option>
                        </select>
                    </label>
                    <label style="display:flex;flex-direction:column;gap:0.28rem;">
                        <span style="font-size:11px;color:#6c757d;font-weight:600;text-transform:uppercase;letter-spacing:.04em;">Reason</span>
                        <select id="scFilterReason" style="min-height:38px;">
                            <option value="all">All reasons</option>
                            <option value="none">No reason</option>
                            <?php foreach (['spillage', 'expired', 'staff_meal', 'sampling', 'prep_waste', 'theft_suspected', 'correction', 'other'] as $rc): ?>
                                <option value="<?php echo htmlspecialchars($rc); ?>"><?php echo htmlspecialchars(ucfirst(str_replace('_', ' ', $rc))); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </label>
                    <button type="button" class="btn-secondary" id="scFilterReset" style="min-height:38px;"><i class="fas fa-rotate-left"></i> Reset</button>
                    <span id="scFilterCount" style="font-size:12px;color:#6c757d;justify-self:end;white-space:nowrap;"></span>
                </div>

                <div class="card" style="overflow:auto; max-height:65vh; padding:0;">
                    <table class="lines-table no-card-mobile">
                        <thead>
                            <tr>
                                <th>Ingredient</th>
                                <?php if (!$blindCount): ?>
                                <th style="text-align:right;">In stock at start <i class="help" tabindex="0" role="button" aria-label="About stock at the start" data-tip="What the system showed on hand for this item when the count was started.">?</i></th>
                                <th style="text-align:right;">Should be there <i class="help" tabindex="0" role="button" aria-label="About stock that should be there" data-tip="The start figure plus sales, deliveries and wastage recorded since the count started. Blank until the item is counted.">?</i></th>
                                <?php endif; ?>
                                <th style="text-align:right;">Counted <i class="help" tabindex="0" role="button" aria-label="About counted" data-tip="What you physically counted, in the ingredient&#x27;s own unit.">?</i></th>
                                <?php if ($isOpeningCount): ?><th style="text-align:right;">Unit cost (<?php echo htmlspecialchars($currency); ?>) <i class="help" tabindex="0" role="button" aria-label="About unit cost" data-tip="What one unit of this item cost you. Needed for every item on an opening count so the stock can be valued.">?</i></th><?php endif; ?>
                                <?php if (!$blindCount): ?>
                                <th style="text-align:right;">Difference <i class="help" tabindex="0" role="button" aria-label="About difference" data-tip="Counted minus what should be there. A minus figure means stock is missing.">?</i></th>
                                <th style="text-align:right;">Value <i class="help" tabindex="0" role="button" aria-label="About value of the difference" data-tip="The difference times the cost per unit, in money.">?</i></th>
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
                                    </td>
                                    <?php if (!$blindCount): ?>
                                    <td data-label="In stock at start" style="text-align:right;"><?php echo rh_stock_qty_fmt($sys, 4); ?></td>
                                    <td data-label="Should be there" style="text-align:right;"><?php echo $expectedAt === null ? '—' : rh_stock_qty_fmt($expectedAt, 4); ?></td>
                                    <?php endif; ?>
                                    <td data-label="Counted" style="text-align:right;">
                                        <?php if ($editable): ?>
                                            <input class="qty" type="number" step="0.000001" min="0"
                                                name="line[<?php echo (int)$ln['id']; ?>][actual]"
                                                value="<?php echo $lineCounted ? number_format($act, 6, '.', '') : ''; ?>"
                                                placeholder="not counted"
                                                <?php if (!$blindCount): ?>data-system="<?php echo number_format((float)($expectedAt ?? $ln['_preview']), 6, '.', ''); ?>"<?php endif; ?>

                                                data-cost="<?php echo number_format((float)$ln['cost_per_unit'], 4, '.', ''); ?>"
                                                oninput="recalcRow(this)">
                                            <input type="hidden" class="touched" name="line[<?php echo (int)$ln['id']; ?>][touched]" value="0">
                                        <?php else: ?>
                                            <?php echo $lineCounted ? rh_stock_qty_fmt($act, 4) : '<span style="color:#6c757d;">not counted</span>'; ?>
                                        <?php endif; ?>
                                    </td>
                                    <?php if ($isOpeningCount): ?>
                                    <td data-label="Unit cost (<?php echo htmlspecialchars($currency); ?>)" style="text-align:right;">
                                        <?php if ($editable): ?>
                                            <input class="qty" type="number" step="0.0001" min="0" style="width:96px;" name="line[<?php echo (int)$ln['id']; ?>][cost]" value="<?php echo (float)$ln['cost_per_unit'] > 0 ? number_format((float)$ln['cost_per_unit'], 4, '.', '') : ''; ?>" placeholder="required">
                                        <?php else: ?>
                                            <?php echo number_format((float)$ln['cost_per_unit'], 4); ?>
                                        <?php endif; ?>
                                    </td>
                                    <?php endif; ?>
                                    <?php if (!$blindCount): ?>
                                    <td data-label="Difference" style="text-align:right;" class="var <?php echo $varClass; ?>"><?php echo $lineCounted ? rh_stock_qty_fmt($var, 4) : '—'; ?></td>
                                    <td data-label="Value" style="text-align:right;" class="varCost <?php echo $varClass; ?>"><?php echo $lineCounted ? $currency . ' ' . number_format($varCost, 2) : '—'; ?></td>
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

                <div style="margin-top:14px; display:flex; gap:10px; flex-wrap:wrap;">
                    <?php if ($editable && $canWorkCount): ?>
                        <button type="submit" name="action" value="save_lines" class="btn-secondary"><i class="fas fa-save"></i> Save readings</button>
                        <?php $oneStepSubmit = !$secondRuleOn && rh_sca_may_decide((int)$user['id'], (int)$activeCount['counted_by'], $canApprove, false); ?>
                        <?php if ($oneStepSubmit): ?>
                        <button type="button" class="btn-primary" onclick="scSubmitConfirm(this.form, true)"><i class="fas fa-check"></i> Submit &amp; correct stock</button>
                        <?php else: ?>
                        <button type="button" class="btn-primary" onclick="scSubmitConfirm(this.form, false)"><i class="fas fa-paper-plane"></i> Submit for approval</button>
                        <label style="display:inline-flex;align-items:center;gap:6px;font-size:13px;color:#495057;">
                            <input type="checkbox" name="email_approvers" value="1" checked>
                            Email the approvers
                            <?php $draftApprovers = $approversFor((int)$activeCount['counted_by']); ?>
                            <span style="color:#6c757d;font-size:12px;">(<?php echo $draftApprovers ? htmlspecialchars(rh_sca_approver_names($draftApprovers)) : 'nobody else can approve yet'; ?>)</span>
                        </label>
                        <?php endif; ?>
                    <?php elseif ($isSubmitted && $canDecide && $blockSelfApprove): ?>
                        <span style="color:#856404;background:#fff3cd;border:1px solid #ffe69c;padding:8px 12px;border-radius:8px;"><i class="fas fa-user-lock"></i> <?php echo $isOpeningCount ? 'An opening stock count' : 'This count has shortages over the approval threshold, so it'; ?> must be approved by someone other than the person who counted it.</span>
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
                        const matchVariance = varianceFilter === 'all' || rowVariance === varianceFilter;
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

                function recalcRow(input) {
                    const tr = input.closest('tr');
                    const touchedEl = tr.querySelector('.touched');
                    if (touchedEl) touchedEl.value = '1';
                    if (input.dataset.system === undefined) { // blind count: nothing to compute or reveal
                        tr.dataset.filterVariance = input.value.trim() === '' ? 'uncounted' : 'counted';
                        applyStockLineFilters();
                        return;
                    }
                    const sys = parseFloat(input.dataset.system) || 0;
                    const cost = parseFloat(input.dataset.cost) || 0;
                    const cells = tr.querySelectorAll('.var, .varCost');
                    if (input.value.trim() === '') {
                        cells[0].textContent = '—';
                        cells[1].textContent = '—';
                        cells[0].className = 'var var-zero';
                        cells[1].className = 'varCost var-zero';
                        tr.dataset.filterVariance = 'uncounted';
                        applyStockLineFilters();
                        return;
                    }
                    const act = parseFloat(input.value) || 0;
                    const variance = act - sys;
                    const varCost = variance * cost;
                    cells[0].textContent = (function (n) { var t = n.toFixed(6).replace(/0+$/, ''); var d = t.length - t.indexOf('.') - 1; return d < 4 ? n.toFixed(4) : t; })(variance);
                    cells[1].textContent = currency + ' ' + Number(varCost || 0).toLocaleString('en-US', {
                        minimumFractionDigits: 2,
                        maximumFractionDigits: 2
                    });
                    const cls = Math.abs(variance) < 0.0001 ? 'var-zero' : (variance > 0 ? 'var-pos' : 'var-neg');
                    cells[0].className = 'var ' + cls;
                    cells[1].className = 'varCost ' + cls;
                    tr.dataset.filterVariance = Math.abs(variance) < 0.0001 ? 'balanced' : (variance > 0 ? 'surplus' : 'shortage');
                    applyStockLineFilters();
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

                    document.querySelectorAll('#countForm .lines-table .qty').forEach((input) => {
                        syncRowFilterStateFromInputs(input);
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
                            ['actual', 'cost', 'notes'].forEach(function (k) {
                                if (f[k] && f[k].value !== f[k].defaultValue) changed = true;
                            });
                            if (f.reason && Array.from(f.reason.options).some(function (o) { return o.selected !== o.defaultSelected; })) changed = true;
                            if (!changed) return;
                            const row = { id: parseInt(id, 10), actual: f.actual ? f.actual.value : '', touched: f.touched && f.touched.value === '1' ? '1' : '0' };
                            if (f.cost) row.cost = f.cost.value;
                            if (f.reason) row.reason = f.reason.value;
                            if (f.notes) row.notes = f.notes.value;
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
                 * Before the confirm, say how many that is. Unsaved typed readings are not sent by a submit, so those
                 * are saved first (the blank count below is then exactly what the server will see). */
                function scSubmitConfirm(form, oneStep) {
                    if (!form) return;
                    const inputs = Array.from(form.querySelectorAll('input[name$="][actual]"]'));
                    if (inputs.some(function (el) { return el.value !== el.defaultValue; })) {
                        scShowBanner('Save your readings first', 'You have typed readings that are not saved yet. Press “Save readings” first so they are included, then submit.', true);
                        return;
                    }
                    const blank = inputs.filter(function (el) { return el.value.trim() === ''; }).length;
                    if (inputs.length > 0 && blank === inputs.length) {
                        scShowBanner('Nothing to submit', 'No item has a reading yet. Type what you counted (0 if there is none) on at least one item, press “Save readings”, then submit.', true);
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
                                <label>Items to count (hold Ctrl to pick more than one)</label>
                                <select id="scope_spot_select" multiple size="8" style="width:100%;">
                                    <?php foreach ($ingredientList as $ing): ?>
                                        <option value="<?php echo (int)$ing['id']; ?>"><?php echo htmlspecialchars($ing['category'] . ' · ' . $ing['name']); ?></option>
                                    <?php endforeach; ?>
                                </select>
                                <input type="hidden" name="scope_value" id="scope_value_spot">
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
                                    <th>Date</th>
                                    <th>What was counted</th>
                                    <th>Items <i class="help" tabindex="0" role="button" aria-label="About items" data-tip="How many ingredients are on this count.">?</i></th>
                                    <th class="sc-num">Shortage <i class="help" tabindex="0" role="button" aria-label="About shortage" data-tip="Worth of missing stock found by this count. Shows a dash for draft counts, and for counts not yet approved if you cannot approve counts.">?</i></th>
                                    <th class="sc-num">Surplus <i class="help" tabindex="0" role="button" aria-label="About surplus" data-tip="Worth of extra stock found by this count. Shows a dash for draft counts, and for counts not yet approved if you cannot approve counts.">?</i></th>
                                    <th>Counted by</th>
                                    <th>Status</th>
                                    <th></th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($counts as $c): ?>
                                    <tr class="sc-row" onclick="window.location='stock-count.php?id=<?php echo (int)$c['id']; ?>';">
                                        <td data-label="Reference"><strong><?php echo htmlspecialchars(displayCountReference((string)$c['reference'])); ?></strong></td>
                                        <td data-label="Date"><?php echo htmlspecialchars($c['count_date']); ?></td>
                                        <td data-label="What was counted">
                                            <?php echo htmlspecialchars(scScopeLabel($c)); ?>
                                            <?php if (!empty($c['is_opening'])): ?><div class="count-note">Opening stock</div><?php endif; ?>
                                        </td>
                                        <td data-label="Items"><?php echo (int)$c['line_count']; ?></td>
                                        <?php
                                        $hideCountFigures = ($c['status'] === 'draft') || (!$canApprove && $c['status'] !== 'approved');
                                        $shortAmt = abs((float)$c['shortage_cost']);
                                        $extraAmt = (float)$c['surplus_cost'];
                                        ?>
                                        <td data-label="Shortage" class="sc-num"><?php
                                            if ($hideCountFigures) echo '—';
                                            elseif ($shortAmt < BALANCE_TOLERANCE) echo '<span class="sc-none">None</span>';
                                            else echo '<span class="sc-money sc-money--short">' . htmlspecialchars($currency . ' ' . number_format($shortAmt, 2)) . '</span>'; ?></td>
                                        <td data-label="Surplus" class="sc-num"><?php
                                            if ($hideCountFigures) echo '—';
                                            elseif ($extraAmt < BALANCE_TOLERANCE) echo '<span class="sc-none">None</span>';
                                            else echo '<span class="sc-money sc-money--extra">' . htmlspecialchars($currency . ' ' . number_format($extraAmt, 2)) . '</span>'; ?></td>
                                        <td data-label="Counted by"><?php echo htmlspecialchars($c['counted_by_name'] ?: '—'); ?></td>
                                        <td data-label="Status">
                                            <span class="pill st-<?php echo htmlspecialchars($c['status']); ?>"><?php echo htmlspecialchars(scStatusLabel((string)$c['status'])); ?></span>
                                            <?php if ($c['status'] === 'submitted'):
                                                $rowApprovers = $approversFor((int)$c['counted_by']);
                                                $rowSecond = $secondRuleOn && (!empty($c['is_opening']) || ($canApprove && rh_sca_needs_second_person($pdo, $c, $blockPct, $minCost)));
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
                }
                document.getElementById('scope_spot_select').addEventListener('change', e => {
                    const ids = [...e.target.selectedOptions].map(o => o.value).join(',');
                    document.getElementById('scope_value_spot').value = ids;
                    document.getElementById('scope_value_spot').name = 'scope_value';
                });
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
            if (typeof scPackLines === 'function') scPackLines(form, false); // draft count form: send no per-line fields
            form.submit();
        }
    </script>
    <?php /* Modals sit before the footer: admin-footer.php closes #rh-admin-page, and the admin SPA
       only swaps that container, so markup printed after it is lost on in-place navigation. */ ?>
    <?php require_once 'includes/admin-footer.php'; ?>
</body>

</html>

