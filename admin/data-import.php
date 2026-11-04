<?php

/**
 * Import (stock lists) - Phase 1b-UI.
 *
 * One page, four steps: Upload -> What we found -> Review & answer -> Confirm (+ Result).
 * Nothing reaches a live table here: every action below only stages / answers / reads; the one write is
 * import_commit_stock() on an explicit Confirm POST, which re-validates everything on the server and runs in one transaction.
 *
 * Access: admin-init.php (session, data_import permission, stock module) + this file (kill switch data_import_enabled, fail-closed,
 * and stock_management). A job can only be opened by the person who uploaded it, or an admin.
 *
 * AJAX (POST, CSRF, JSON): upload | summary | review_page | bulk | answer | fields | cancel | confirm | list.  GET ?action=csv&job=ID downloads the preview CSV.
 * All page text built from the file (names, sheets, cells) is set with textContent in the browser - it is never parsed as HTML.
 */
require_once 'admin-init.php';
require_once __DIR__ . '/includes/import-schema.php';
require_once __DIR__ . '/../includes/import/commit-stock.php';
require_once __DIR__ . '/../includes/import/memory.php';          // will this file / job fit in this server's memory and time (plain message, never a fatal error)
require_once __DIR__ . '/../includes/import/review-view.php';     // Step 3 paging / filters / bulk answers (server side)

$user = [
    'id'        => $_SESSION['admin_user_id'],
    'username'  => $_SESSION['admin_username'],
    'role'      => $_SESSION['admin_role'],
    'full_name' => $_SESSION['admin_full_name'],
];
$uid = (int)$user['id'];
$isAdmin = ($user['role'] ?? '') === 'admin';
$current_page = basename($_SERVER['PHP_SELF']);

$action = (string)($_POST['action'] ?? $_GET['action'] ?? '');
$isAjax = $action !== '' || (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST');

/** Send JSON and stop. Nothing else is printed before it. */
function di_json(array $data, int $code = 200): void
{
    while (ob_get_level() > 0) {
        @ob_end_clean();
    }
    http_response_code($code);
    header('Content-Type: application/json; charset=utf-8');
    header('X-Content-Type-Options: nosniff');
    header('Cache-Control: no-store');
    echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE | JSON_PARTIAL_OUTPUT_ON_ERROR);
    exit;
}

/** The biggest upload that can really arrive: the smaller of our setting and the server's own limits. */
function di_ini_bytes(string $v): int
{
    $v = trim($v);
    if ($v === '') {
        return 0;
    }
    $n = (float)$v;
    switch (strtolower(substr($v, -1))) {
        case 'g':
            $n *= 1024;
            // no break
        case 'm':
            $n *= 1024;
            // no break
        case 'k':
            $n *= 1024;
    }
    return (int)$n;
}

function di_max_upload_bytes(PDO $pdo): int
{
    $caps = [max(1, (int)rh_import_setting('data_import_max_mb', $pdo)) * 1048576];
    foreach (['upload_max_filesize', 'post_max_size'] as $k) {
        $b = di_ini_bytes((string)ini_get($k));
        if ($b > 0) {
            $caps[] = $b;
        }
    }
    return min($caps);
}

function di_mb_text(int $bytes): string
{
    return $bytes >= 1048576 ? rtrim(rtrim(number_format($bytes / 1048576, 1), '0'), '.') . ' MB' : max(1, (int)round($bytes / 1024)) . ' KB';
}

/** A job the signed-in person may open: their own, or any job for an admin. */
function di_job(PDO $pdo, int $jobId, int $uid, bool $isAdmin): ?array
{
    return import_job_for_user($pdo, $jobId, $uid, $isAdmin);
}

/** The filter values a request carries (validated again in import_rv_params; nothing here is trusted). */
function di_filter_post(): array
{
    $out = [];
    foreach (['q', 'sheet', 'cat', 'status', 'type', 'kind', 'warn', 'cost', 'stock', 'answered', 'sort', 'size', 'page', 'view'] as $k) {
        if (isset($_POST[$k]) && is_scalar($_POST[$k])) {
            $out[$k] = $_POST[$k];
        }
    }
    return $out;
}

// ---- gate: kill switch + stock permission -----------------------------------------------------------------------------
ensureImportSchema($pdo);      // creates the tables and the (OFF) switch row the first time; additive and repeatable
if (!rh_import_enabled($pdo)) {
    if ($isAjax) {
        di_json(['ok' => false, 'error' => 'Import is switched off for this hotel.'], 403);
    }
    rhDenyAndRedirectHome($uid, (string)$user['role'], $current_page, 'module_disabled');
}
if (!rh_import_can_use($uid, $pdo)) {
    if ($isAjax) {
        di_json(['ok' => false, 'error' => 'Your account cannot use Import. It needs the Import and the Ingredients & Recipes permissions.'], 403);
    }
    rhDenyAndRedirectHome($uid, (string)$user['role'], $current_page);
}
if (function_exists('ensureStockTablesExist')) {
    ensureStockTablesExist();
}

// ---- download: what will be imported (CSV) ---------------------------------------------------------------------------
if (($_SERVER['REQUEST_METHOD'] ?? '') === 'GET' && $action === 'csv') {
    $jobId = (int)($_GET['job'] ?? 0);
    $job = di_job($pdo, $jobId, $uid, $isAdmin);
    if (!$job || !in_array($job['status'], ['analysed', 'reviewing', 'confirmed'], true)) {
        http_response_code(404);
        exit('That import is not available.');
    }
    $csv = import_job_csv($pdo, $jobId);
    while (ob_get_level() > 0) {
        @ob_end_clean();
    }
    header('Content-Type: text/csv; charset=utf-8');
    header('X-Content-Type-Options: nosniff');
    header('Content-Disposition: attachment; filename="import-' . $jobId . '-what-will-be-imported.csv"');
    header('Cache-Control: no-store');
    echo "\xEF\xBB\xBF" . $csv;
    exit;
}

// ---- EXPORT (Part B): the current stock as a tabbed Excel workbook -------------------------------------------------------
// POST + CSRF, same gates as the rest of the page (kill switch, data_import AND stock_management were checked above). Read-only:
// nothing is written to stock. Content comes from the live stock tables; see includes/import/export-stock.php.
if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST' && $action === 'export') {
    $exportFail = function (string $msg, int $code = 422): void {
        while (ob_get_level() > 0) {
            @ob_end_clean();
        }
        http_response_code($code);
        header('Content-Type: text/html; charset=utf-8');
        header('Cache-Control: no-store');
        echo '<!DOCTYPE html><html lang="en"><head><meta charset="UTF-8"><title>Export current stock</title></head><body style="font-family:sans-serif;max-width:560px;margin:60px auto;">'
            . '<p>' . htmlspecialchars($msg, ENT_QUOTES) . '</p><p><a href="data-import.php">Back to Import</a></p></body></html>';
        exit;
    };
    if (!validateCsrfToken((string)($_POST['csrf_token'] ?? ''))) {
        $exportFail('Your session has expired. Go back to Import, reload the page and try again.', 403);
    }
    require_once __DIR__ . '/../includes/import/export-stock.php';
    @set_time_limit(180);
    $pick = (($_POST['scope'] ?? 'all') === 'pick');
    $tabsWanted = $pick ? array_values(array_filter(array_map('strval', (array)($_POST['tabs'] ?? [])), 'is_string')) : [];
    if ($pick && !$tabsWanted) {
        $exportFail('Tick at least one tab to export, or choose "All tabs".');
    }
    try {
        $built = import_export_stock_workbook($pdo, ['tabs' => $tabsWanted, 'hotel' => (string)getSetting('site_name', ''), 'currency' => (string)getSetting('currency_symbol', '')]);
    } catch (Throwable $e) {
        error_log('stock export: ' . $e->getMessage());
        $built = ['ok' => false, 'error' => 'The stock workbook could not be built. Please try again.'];
    }
    if (empty($built['ok'])) {
        $exportFail((string)$built['error']);
    }
    if (function_exists('logActivity')) {
        logActivity($uid, 'stock_export', 'Stock workbook downloaded: ' . (int)$built['summary']['items'] . ' items on ' . (int)$built['summary']['tabs'] . ' tab(s).');
    }
    if (session_status() === PHP_SESSION_ACTIVE) {
        session_write_close();
    }
    import_export_send($built);
}

// ---- AJAX actions -------------------------------------------------------------------------------------------------------
if ($isAjax) {
    if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
        di_json(['ok' => false, 'error' => 'Unknown request.'], 405);
    }
    if (empty($_POST) && empty($_FILES) && (int)($_SERVER['CONTENT_LENGTH'] ?? 0) > 0) {
        di_json(['ok' => false, 'error' => 'That file is bigger than this server accepts (' . di_mb_text(di_max_upload_bytes($pdo)) . '). Save a smaller copy and try again.'], 413);
    }
    if (!validateCsrfToken((string)($_POST['csrf_token'] ?? ''))) {
        di_json(['ok' => false, 'error' => 'Your session has expired. Reload the page and try again.'], 403);
    }
    @set_time_limit(180);
    import_job_state_memo(true);          // one answer request resolves the job twice, not three times
    $jobId = (int)($_POST['job'] ?? 0);

    if ($action === 'upload') {
        $f = $_FILES['file'] ?? null;
        if (!$f || !is_array($f) || is_array($f['name'] ?? null)) {
            di_json(['ok' => false, 'error' => 'Choose a file to upload.']);
        }
        $errs = [
            UPLOAD_ERR_INI_SIZE => 'That file is bigger than this server accepts (' . di_mb_text(di_max_upload_bytes($pdo)) . '). Save a smaller copy and try again.',
            UPLOAD_ERR_FORM_SIZE => 'That file is too big.',
            UPLOAD_ERR_PARTIAL => 'The file only half arrived. Please try again.',
            UPLOAD_ERR_NO_FILE => 'Choose a file to upload.',
            UPLOAD_ERR_NO_TMP_DIR => 'The server has no place to keep uploads. Please tell the administrator.',
            UPLOAD_ERR_CANT_WRITE => 'The server could not save the file. Please tell the administrator.',
            UPLOAD_ERR_EXTENSION => 'The server stopped the upload. Please tell the administrator.',
        ];
        if ((int)$f['error'] !== UPLOAD_ERR_OK) {
            di_json(['ok' => false, 'error' => $errs[(int)$f['error']] ?? 'The upload failed. Please try again.']);
        }
        import_purge_staged($pdo);       // rate-limited housekeeping: expired / finished files
        $r = import_job_create($pdo, (string)$f['tmp_name'], (string)$f['name'], $uid, ['allow_reimport' => !empty($_POST['allow_reimport'])]);
        if (empty($r['ok'])) {
            di_json(['ok' => false, 'error' => (string)$r['error'], 'duplicate' => $r['duplicate'] ?? null]);
        }
        $a = import_job_analyse($pdo, (int)$r['job_id']);
        if (empty($a['ok'])) {
            di_json(['ok' => false, 'error' => (string)$a['error']]);
        }
        di_json(['ok' => true, 'job' => (int)$r['job_id'], 'duplicate' => $r['duplicate'] ?? null]);
    }

    if ($action === 'list') {
        import_purge_staged($pdo);
        $sql = "SELECT j.id, j.original_name, j.status, j.created_at, j.committed_at, j.error_text, j.result_json, j.created_by,
                       COALESCE(NULLIF(u.full_name, ''), u.username, '') AS by_name
                FROM import_jobs j LEFT JOIN admin_users u ON u.id = j.created_by
                WHERE j.target = 'stock' AND j.status <> 'cancelled' AND j.created_at > DATE_SUB(NOW(), INTERVAL 30 DAY)"
            . ($isAdmin ? '' : ' AND j.created_by = ?') . ' ORDER BY j.id DESC LIMIT 12';
        $st = $pdo->prepare($sql);
        $st->execute($isAdmin ? [] : [$uid]);
        $out = [];
        foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $j) {
            $res = $j['result_json'] ? json_decode((string)$j['result_json'], true) : null;
            $out[] = [
                'id' => (int)$j['id'], 'file' => (string)$j['original_name'], 'status' => (string)$j['status'], 'created_at' => (string)$j['created_at'],
                'by' => (string)$j['by_name'], 'mine' => (int)$j['created_by'] === $uid, 'error' => (string)$j['error_text'],
                'created' => is_array($res) ? (int)($res['created'] ?? 0) : null, 'updated' => is_array($res) ? (int)($res['updated'] ?? 0) : null,
            ];
        }
        di_json(['ok' => true, 'jobs' => $out]);
    }

    $job = di_job($pdo, $jobId, $uid, $isAdmin);
    if (!$job) {
        di_json(['ok' => false, 'error' => 'That import was not found.'], 404);
    }

    // Opening an analysed job decodes and resolves its stored analysis: where this server's memory_limit cannot hold that (and cannot be raised), say so plainly.
    if (in_array($action, ['summary', 'review_page', 'bulk', 'answer', 'fields'], true) && (int)($job['analysis_bytes'] ?? 0) > 0) {
        $mem = import_mem_check_review((int)$job['analysis_bytes'], import_default_aliases());
        if (!$mem['ok']) {
            di_json(['ok' => false, 'error' => $mem['message'], 'state' => 'toobig']);
        }
    }

    if ($action === 'summary') {
        if ($job['status'] === 'committed') {
            di_json(['ok' => true, 'state' => 'committed', 'file' => $job['original_name'], 'result' => json_decode((string)$job['result_json'], true)]);
        }
        if ($job['status'] === 'cancelled') {
            di_json(['ok' => false, 'error' => 'This import was cancelled.', 'state' => 'cancelled']);
        }
        if ($job['status'] === 'failed') {
            di_json(['ok' => false, 'error' => (string)($job['error_text'] ?: 'This import could not be read.'), 'state' => 'failed']);
        }
        if ($job['analysis_json'] === null || $job['analysis_json'] === '') {
            $a = import_job_analyse($pdo, $jobId);
            if (empty($a['ok'])) {
                di_json(['ok' => false, 'error' => (string)$a['error']]);
            }
        }
        // Lean overview (no item list: that is paged below). With paged=1 the first page comes back in the same reply.
        $s = import_job_review_view($pdo, $jobId, !empty($_POST['paged']) ? di_filter_post() : null, ['facets' => true]);
        $s['state'] = 'review';
        di_json($s);
    }

    if ($action === 'review_page') {
        if (!in_array($job['status'], ['analysed', 'reviewing', 'confirmed'], true)) {
            di_json(['ok' => false, 'error' => 'This import cannot be reviewed any more.']);
        }
        $r = import_job_review_view($pdo, $jobId, di_filter_post(), ['overview' => false, 'facets' => !empty($_POST['facets']), 'entries' => ($_POST['entries'] ?? '1') !== '0']);
        di_json($r);
    }

    if ($action === 'bulk') {
        // Apply one answer to exactly the questions the filters select. The server recomputes that set; dry=1 only describes it.
        $r = import_job_bulk($pdo, $jobId, di_filter_post(), (string)($_POST['btype'] ?? ''), (string)($_POST['sig'] ?? ''), (string)($_POST['role'] ?? ''),
            !empty($_POST['dry']), (string)($_POST['fingerprint'] ?? ''), $uid);
        if (!empty($r['ok']) && empty($r['dry'])) {
            $v = import_job_review_view($pdo, $jobId, di_filter_post(), ['facets' => true]);
            $r['summary'] = $v;
        }
        di_json($r);
    }

    if ($action === 'answer') {
        $qid = (string)($_POST['question'] ?? '');
        $key = (string)($_POST['answer'] ?? '');
        $answer = $key;
        if (isset($_POST['pack_size']) && $_POST['pack_size'] !== '') {
            $answer = ['key' => $key, 'pack_size' => trim((string)$_POST['pack_size'])];
        }
        $r = import_job_answer($pdo, $jobId, $qid, $answer, $uid);
        if (!empty($r['ok'])) {          // the fresh picture comes back with the answer (one round trip, not two)
            $r['summary'] = import_job_review_view($pdo, $jobId, !empty($_POST['paged']) ? di_filter_post() : null, ['facets' => true]);
            $r['summary']['state'] = 'review';
        }
        di_json($r);
    }

    if ($action === 'fields') {
        $fields = $_POST['fields'] ?? [];
        $fields = is_array($fields) ? array_values(array_map('strval', $fields)) : [];
        $r = import_job_set_update_fields($pdo, $jobId, (string)($_POST['key'] ?? ''), $fields, $uid);
        if (!empty($r['ok'])) {
            $r['summary'] = import_job_review_view($pdo, $jobId, !empty($_POST['paged']) ? di_filter_post() : null, ['facets' => true]);
            $r['summary']['state'] = 'review';
        }
        di_json($r);
    }

    if ($action === 'cancel') {
        di_json(import_job_cancel($pdo, $jobId, $uid));
    }

    if ($action === 'confirm') {
        if (($_POST['confirm'] ?? '') !== '1') {
            di_json(['ok' => false, 'error' => 'Please press Confirm to import.']);
        }
        $r = import_commit_stock($pdo, $jobId, $uid);     // re-checks the file, the answers and the permission; one transaction; safe to repeat
        di_json($r);
    }

    di_json(['ok' => false, 'error' => 'Unknown request.'], 400);
}

// ---- the page ---------------------------------------------------------------------------------------------------------
$csrf_token = generateCsrfToken();
$maxBytes = di_max_upload_bytes($pdo);
$canReimport = rh_import_can_reimport($uid, $pdo);
$startJob = (int)($_GET['job'] ?? 0);
$cssV = @filemtime(__DIR__ . '/css/data-import.css');
$jsV = @filemtime(__DIR__ . '/js/data-import.js');
?>
<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <title>Import — Stock Management</title>
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <link href="https://fonts.googleapis.com/css2?family=Cormorant+Garamond:wght@400;500;600&family=Jost:wght@300;400;500;600&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="css/admin-styles.css?v=<?php echo @filemtime(__DIR__ . '/css/admin-styles.css'); ?>">
    <link rel="stylesheet" href="css/admin-components.css?v=<?php echo @filemtime(__DIR__ . '/css/admin-components.css'); ?>">
    <link rel="stylesheet" href="css/data-import.css?v=<?php echo $cssV; ?>">
</head>

<body>
    <?php require_once 'includes/admin-header.php'; ?>

    <div class="content">
        <div class="page-header">
            <h2 class="page-title"><i class="fas fa-file-import di-title-icon" aria-hidden="true"></i> Import</h2>
            <?php /* EXPORT BUTTON (Part B): plain form POST, no script needed. Tabs = stock categories (includes/import/export-stock.php). */
            require_once __DIR__ . '/../includes/import/export-stock.php';
            try { $exportTabs = import_export_stock_tabs($pdo); } catch (Throwable $e) { $exportTabs = []; }
            ?>
            <details class="di-export">
                <summary class="btn-secondary di-btn"><i class="fas fa-file-excel" aria-hidden="true"></i> Export current stock</summary>
                <form method="post" action="data-import.php" class="di-export-form">
                    <input type="hidden" name="action" value="export">
                    <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrf_token, ENT_QUOTES); ?>">
                    <p class="di-help">An Excel workbook of your stock as it is right now (anything you imported and approved is in it), one tab per category. Nothing is changed.</p>
                    <?php if (!$exportTabs): ?>
                        <p class="di-muted">There is no stock to export yet.</p>
                    <?php else: ?>
                        <label class="di-check"><input type="radio" name="scope" value="all" checked> <span>All tabs (<?php echo count($exportTabs); ?>)</span></label>
                        <label class="di-check"><input type="radio" name="scope" value="pick"> <span>Only the tabs I tick below</span></label>
                        <div class="di-export-tabs" role="group" aria-label="Tabs to export">
                            <?php foreach ($exportTabs as $et): ?>
                                <label class="di-check"><input type="checkbox" name="tabs[]" value="<?php echo htmlspecialchars($et['key'], ENT_QUOTES); ?>"> <span><?php echo htmlspecialchars($et['label']); ?> <small>(<?php echo (int)$et['items']; ?> item<?php echo $et['items'] === 1 ? '' : 's'; ?>)</small></span></label>
                            <?php endforeach; ?>
                        </div>
                        <button type="submit" class="btn-primary di-btn"><i class="fas fa-download" aria-hidden="true"></i> Download the stock workbook</button>
                    <?php endif; ?>
                </form>
            </details>
        </div>

        <div id="di-root" class="di no-auto-pagination" data-disable-auto-pagination="1"
             data-csrf="<?php echo htmlspecialchars($csrf_token, ENT_QUOTES); ?>"
             data-job="<?php echo $startJob; ?>"
             data-max="<?php echo (int)$maxBytes; ?>"
             data-max-text="<?php echo htmlspecialchars(di_mb_text($maxBytes), ENT_QUOTES); ?>"
             data-can-reimport="<?php echo $canReimport ? '1' : '0'; ?>"
             data-page="data-import.php">

            <p class="di-intro">Bring a stock list in from Excel or CSV. You will see exactly how each column and row will be read, answer anything that is unclear, and only then confirm. Nothing is saved to your stock until you press Confirm.</p>

            <ol class="di-steps" id="diSteps" aria-label="Import steps">
                <li data-step="1" class="is-current"><span class="di-step-n">1</span><span class="di-step-t">Upload<small>Choose your file</small></span></li>
                <li data-step="2"><span class="di-step-n">2</span><span class="di-step-t">What we found<small>Check how it was read</small></span></li>
                <li data-step="3"><span class="di-step-n">3</span><span class="di-step-t">Review &amp; answer<small>Fix anything unclear</small></span></li>
                <li data-step="4"><span class="di-step-n">4</span><span class="di-step-t">Confirm<small>Import and finish</small></span></li>
            </ol>

            <div id="diMsg" class="di-msg" role="status" aria-live="polite" hidden></div>

            <section class="di-panel" id="diStep1" data-panel="1">
                <h3 class="di-h">Step 1 &middot; Upload your stock list</h3>
                <p class="di-help">Pick the spreadsheet you keep your stock in. It is only read, never changed, and the copy kept on the server is deleted when you finish, cancel, or after a few days.</p>
                <div class="di-drop" id="diDrop" tabindex="0" role="button" aria-label="Choose a file or drop it here">
                    <i class="fas fa-file-import" aria-hidden="true"></i>
                    <strong>Drop your file here</strong>
                    <span>or</span>
                    <label class="btn-primary di-btn" for="diFile">Choose file</label>
                    <input type="file" id="diFile" class="di-file" accept=".xlsx,.xlsm,.csv,.tsv">
                    <span class="di-file-name" id="diFileName" aria-live="polite"></span>
                </div>
                <ul class="di-facts">
                    <li><strong>Accepted:</strong> Excel (.xlsx, .xlsm) and CSV / TSV text files.</li>
                    <li><strong>Not supported yet:</strong> Word and PDF files, and old Excel (.xls) files &mdash; open them in Excel and use Save As &gt; Excel Workbook (.xlsx).</li>
                    <li><strong>Largest file:</strong> <?php echo htmlspecialchars(di_mb_text($maxBytes)); ?>.</li>
                    <li><strong>Macros:</strong> never run. Formulas are read only as the value they last showed.</li>
                </ul>
                <?php if ($canReimport): ?>
                    <label class="di-check"><input type="checkbox" id="diReimport"> <span>Import again &mdash; tick this only if you really mean to bring in a file that was already imported (administrators only).</span></label>
                <?php endif; ?>
                <div class="di-actions">
                    <button type="button" class="btn-primary di-btn" id="diUpload" disabled><i class="fas fa-upload" aria-hidden="true"></i> Upload and read the file</button>
                </div>
            </section>

            <section class="di-panel" id="diStep2" data-panel="2" hidden></section>
            <section class="di-panel" id="diStep3" data-panel="3" hidden></section>
            <section class="di-panel" id="diStep4" data-panel="4" hidden></section>

            <section class="di-panel di-mine" id="diMine" aria-labelledby="diMineH">
                <h3 class="di-h" id="diMineH">My imports</h3>
                <p class="di-help">Imports you started in the last 30 days. Open one to carry on where you stopped, or cancel one you no longer need.</p>
                <div id="diMineList" class="di-list"><p class="di-muted">Loading&hellip;</p></div>
            </section>
        </div>
    </div>

    <?php require_once 'includes/admin-footer.php'; ?>
    <script src="js/data-import.js?v=<?php echo $jsV; ?>"></script>
    <script>
        (function () {
            var root = document.getElementById('di-root');
            if (root && window.rhDataImport) { window.rhDataImport.init(root); }
        })();
    </script>
</body>

</html>
