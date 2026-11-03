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
 * AJAX (POST, CSRF, JSON): upload | summary | answer | fields | cancel | confirm | list.  GET ?action=csv&job=ID downloads the preview CSV.
 * All page text built from the file (names, sheets, cells) is set with textContent in the browser - it is never parsed as HTML.
 */
require_once 'admin-init.php';
require_once __DIR__ . '/includes/import-schema.php';
require_once __DIR__ . '/../includes/import/commit-stock.php';

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
    $job = $jobId > 0 ? import_job_get($pdo, $jobId) : null;
    if (!$job || ($job['target'] ?? '') !== 'stock') {
        return null;
    }
    if (!$isAdmin && (int)($job['created_by'] ?? 0) !== $uid) {
        return null;
    }
    return $job;
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
        $s = import_job_review_summary($pdo, $jobId);
        $s['state'] = 'review';
        di_json($s);
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
            $r['summary'] = import_job_review_summary($pdo, $jobId);
            $r['summary']['state'] = 'review';
        }
        di_json($r);
    }

    if ($action === 'fields') {
        $fields = $_POST['fields'] ?? [];
        $fields = is_array($fields) ? array_values(array_map('strval', $fields)) : [];
        $r = import_job_set_update_fields($pdo, $jobId, (string)($_POST['key'] ?? ''), $fields, $uid);
        if (!empty($r['ok'])) {
            $r['summary'] = import_job_review_summary($pdo, $jobId);
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
            <h2 class="page-title"><i class="fas fa-file-import" style="color:var(--color-primary,#766550);"></i> Import</h2>
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
