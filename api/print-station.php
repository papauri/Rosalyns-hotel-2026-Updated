<?php

/**
 * api/print-station.php - the Kitchen-screen-device side of receipt printing (KDS / BDS / CDS pages).
 *
 * Auth: admin session + CSRF, and the station's own view permission (kds_view / bds_view / cds_view).
 * Method: POST only.
 * Actions:
 *   poll     {station}              : the screen-mode printers for this station and their latest jobs (for the panel)
 *   claim    {printer_id}           : hand THIS device the next queued ticket as printable HTML. Atomic: when two
 *                                     screens poll the same printer only one wins, and the claim is stored on the
 *                                     server so a reload never prints it twice.
 *   done     {job_id, ok, error}    : the device reports the print finished (or failed)
 *   reprint  {job_id}               : put a printed / failed ticket back in the queue
 * Printing happens in the browser (window.print into a hidden frame), so any printer the device can use works.
 */

declare(strict_types=1);
header('Content-Type: application/json; charset=utf-8');

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../config/security.php';
require_once __DIR__ . '/../includes/admin-session.php';
rh_admin_session_start();
require_once __DIR__ . '/../includes/licence-guard.php'; rh_licence_guard_request();
require_once __DIR__ . '/../includes/printing.php';

function ps_err(string $m, int $code = 400): void
{
    http_response_code($code);
    echo json_encode(['ok' => false, 'error' => $m]);
    exit;
}
function ps_ok(array $extra = []): void
{
    echo json_encode(array_merge(['ok' => true], $extra));
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') ps_err('POST only', 405);
if (empty($_SESSION['admin_user'])) ps_err('Not authenticated', 401);
$user = $_SESSION['admin_user'];
$csrfToken = (string)($_POST['csrf_token'] ?? ($_SERVER['HTTP_X_CSRF_TOKEN'] ?? ''));
if (!validateCsrfToken($csrfToken)) ps_err('Invalid CSRF token', 403);

require_once __DIR__ . '/../admin/includes/permissions.php';
rhApiModuleGate('pos.php');
if (!rh_print_ready($pdo)) ps_ok(['printers' => [], 'jobs' => []]);

$uid = (int)$user['id'];
$permFor = static function (string $station): string {
    return ['bar' => 'bds_view', 'coffee_bar' => 'cds_view'][$station] ?? 'kds_view';
};
$isPriv = in_array((string)($user['role'] ?? ''), ['admin', 'manager'], true);
$canUse = static function (array $printer) use ($uid, $permFor, $isPriv): bool {
    return hasPermission($uid, $permFor((string)$printer['station'])) || ($isPriv && hasPermission($uid, 'kds_view'));
};
$loadPrinter = static function (int $id) use ($pdo): ?array {
    $st = $pdo->prepare("SELECT * FROM print_printers WHERE id = ? AND mode = 'screen' AND is_active = 1");
    $st->execute([$id]);
    $p = $st->fetch(PDO::FETCH_ASSOC);
    return $p ?: null;
};

try {
    $action = trim((string)($_POST['action'] ?? ''));

    if ($action === 'poll') {
        $station = (string)($_POST['station'] ?? 'kitchen');
        if (!in_array($station, ['kitchen', 'bar', 'coffee_bar'], true)) ps_err('Invalid station');
        // 'All stations' printers are served from the first station screen that is switched on (kitchen, else bar, else coffee).
        $allHome = 'kitchen';
        foreach (rh_print_biz()['stations'] as $sn => $on) {
            if ($on) { $allHome = $sn; break; }
        }
        $st = $pdo->prepare("SELECT id, name, paper, station, prints FROM print_printers
            WHERE mode = 'screen' AND is_active = 1 AND prints IN ('tickets','both') AND (station = ? OR (station = 'all' AND ? = ?)) ORDER BY id");
        $st->execute([$station, $station, $allHome]);
        $printers = array_values(array_filter($st->fetchAll(PDO::FETCH_ASSOC), $canUse));
        $jobs = [];
        if ($printers) {
            $ids = array_map(static function ($p) { return (int)$p['id']; }, $printers);
            $in = implode(',', array_fill(0, count($ids), '?'));
            $js = $pdo->prepare("SELECT id, printer_id, kind, title, status, attempts, last_error, created_at, printed_at FROM print_jobs
                WHERE printer_id IN ($in) AND status <> 'cancelled' AND created_at >= DATE_SUB(NOW(), INTERVAL 1 DAY) ORDER BY id DESC LIMIT 20");
            $js->execute($ids);
            $jobs = $js->fetchAll(PDO::FETCH_ASSOC);
        }
        ps_ok(['printers' => $printers, 'jobs' => $jobs]);
    }

    if ($action === 'claim') {
        $p = $loadPrinter((int)($_POST['printer_id'] ?? 0));
        if (!$p || !$canUse($p)) ps_err('Forbidden', 403);
        $job = rh_print_next_job($pdo, (int)$p['id']);
        if (!$job || !rh_print_claim_job($pdo, (int)$job['id'], (int)$p['id'])) ps_ok(['job' => null]);
        ps_ok(['job' => ['id' => (int)$job['id'], 'title' => (string)$job['title'], 'html' => rh_print_render_job($job, $p, 'html')]]);
    }

    if ($action === 'done' || $action === 'reprint') {
        $jobId = (int)($_POST['job_id'] ?? 0);
        $js = $pdo->prepare("SELECT j.*, p.station AS p_station, p.mode AS p_mode FROM print_jobs j JOIN print_printers p ON p.id = j.printer_id WHERE j.id = ?");
        $js->execute([$jobId]);
        $job = $js->fetch(PDO::FETCH_ASSOC);
        if (!$job || $job['p_mode'] !== 'screen' || !$canUse(['station' => $job['p_station']])) ps_err('Forbidden', 403);
        if ($action === 'done') {
            $ok = !empty($_POST['ok']);
            rh_print_finish_job($pdo, $jobId, (int)$job['printer_id'], $ok, $ok ? '' : mb_substr((string)($_POST['error'] ?? ''), 0, 120));
            ps_ok();
        }
        if ($job['status'] === 'cancelled') ps_err('That order was cancelled - nothing to print.');
        ps_ok(['requeued' => rh_print_reprint_job($pdo, $jobId)]);
    }

    ps_err('Unknown action');
} catch (Throwable $e) {
    error_log('print-station: ' . $e->getMessage());
    ps_err('Could not reach the print queue.', 500);
}
