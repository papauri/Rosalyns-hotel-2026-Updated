<?php

/**
 * Admin - Clear Stock Data (owner feature 2026-11-06).
 *
 * Administrator role only. Steps, each its own screen: choose what to clear (counts shown, nothing changes)
 * -> review and confirm (type CLEAR + own password) -> result (with Undo). Past clears can be undone from the list below.
 * The work is done by admin/includes/stock-clear-lib.php: full-database safety backup, then one transaction that snapshots
 * exactly the rows it removes, deletes them and closes the stock residue (alerts, cached review panels, cached numbers).
 * Restaurant orders, payments, bookings and menu items are never touched.
 */

require_once 'admin-init.php';
require_once __DIR__ . '/includes/stock-clear-lib.php';

$user = [
    'id'        => (int)($_SESSION['admin_user_id'] ?? 0),
    'username'  => (string)($_SESSION['admin_username'] ?? ''),
    'role'      => (string)($_SESSION['admin_role'] ?? ''),
    'full_name' => (string)($_SESSION['admin_full_name'] ?? ''),
];
$csrf_token = $csrf_token ?? generateCsrfToken();
$current_page = 'stock-clear.php';

// Administrator role only. data_reset (owner 2026-11-11: super admin only; a plain administrator only when a super admin
// switched it on for them, or while no super admin exists) opens the clear, count wipe and past clears. stock_wipe_all
// (owner 2026-11-16, same tier) opens only the "Wipe all stock and every record" section. Either one opens the page;
// every POST below re-checks its own permission.
$canReset = false;
$canWipeAll = false;
if ($user['role'] === 'admin') {
    [$canReset] = rh_sc_authorize($pdo, $user['id']);
    [$canWipeAll] = rh_sc_authorize_wipe_all($pdo, $user['id']);
}
if (!$canReset && !$canWipeAll) {
    rhDenyAndRedirectHome($user['id'], $user['role'], basename($_SERVER['PHP_SELF']));
    exit;
}

$ROOT   = dirname(__DIR__);
$area   = 'stock';
$A      = rh_sc_area($area);
$allKeys = array_keys($A['groups']);

$message = '';
$error   = '';
$view    = 'form';      // form | confirm | result | undone | cw_preview | cw_confirm | cw_result | wa_preview | wa_confirm | wa_result
$preview = null;
$result  = null;
$undone  = null;
$picked  = [];
$cwPreview = null;      // stock count wipe: preview (read-only)
$cwResult  = null;      // stock count wipe: result
$cwImport  = false;     // stock count wipe: also remove the Import history entries
$waPreview = null;      // wipe all stock: preview (read-only)
$waResult  = null;      // wipe all stock: result

/** Too many wrong passwords in a row from this session: stop for ten minutes. */
function sc_locked_out(): bool
{
    $f = $_SESSION['sc_fail'] ?? ['n' => 0, 't' => 0];
    if (time() - (int)$f['t'] > 600) {
        $_SESSION['sc_fail'] = ['n' => 0, 't' => 0];
        return false;
    }
    return (int)$f['n'] >= 5;
}

function sc_note_failure(): void
{
    $f = $_SESSION['sc_fail'] ?? ['n' => 0, 't' => 0];
    $_SESSION['sc_fail'] = ['n' => (int)$f['n'] + 1, 't' => time()];
}

/** The groups the browser sent, whitelisted against the registry. */
function sc_posted_groups(array $allKeys): array
{
    if (!empty($_POST['everything'])) {
        return $allKeys;
    }
    $out = [];
    foreach ((array)($_POST['groups'] ?? []) as $g) {
        if (is_string($g) && in_array($g, $allKeys, true)) {
            $out[$g] = $g;
        }
    }
    return array_values($out);
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = (string)($_POST['action'] ?? '');
    if (!validateCsrfToken($_POST['_tok'] ?? ($_POST['csrf_token'] ?? ''))) {
        $error = 'Invalid form token. Please refresh the page and try again. Nothing was changed.';
    } elseif (in_array($action, ['preview', 'clear', 'cw_preview', 'cw_confirm', 'cw_wipe'], true) && !$canReset) {
        $error = 'Your account cannot use this part of the page (it needs the Clear Stock Data permission). Nothing was changed.';
    } elseif ($action === 'undo' && !rh_sc_undo_allowed($canReset, $canWipeAll, rh_sc_manifest($ROOT, (string)($_POST['clear_id'] ?? '')))) {
        $error = 'Your account can only undo a full stock wipe. Nothing was changed.';
    } elseif (in_array($action, ['wa_preview', 'wa_confirm', 'wa_wipe'], true) && !$canWipeAll) {
        $error = 'Your account cannot wipe all stock (it needs the "Wipe all stock (full reset)" permission). Nothing was changed.';
    } elseif ($action === 'wa_preview' || $action === 'wa_confirm') {
        // Wipe all stock, steps 1 and 2 (read-only). Nothing to remove never reaches the confirm screen.
        $waPreview = rh_sc_wipe_all_preview($pdo);
        $waEmpty = (int)$waPreview['total'] === 0 && (int)$waPreview['import']['removable'] === 0;
        $view = ($action === 'wa_confirm' && !$waEmpty) ? 'wa_confirm' : 'wa_preview';
    } elseif ($action === 'wa_wipe') {
        if (sc_locked_out()) {
            $error = 'Too many wrong attempts. Nothing was changed. Wait ten minutes and try again.';
            $waPreview = rh_sc_wipe_all_preview($pdo);
            $view = 'wa_preview';
        } else {
            @set_time_limit(900);
            @ignore_user_abort(true);
            $wr = rh_sc_wipe_all_request($pdo, $ROOT, $user, $_POST);
            if ($wr['stage'] === 'confirm') {
                sc_note_failure();
            } elseif (in_array($wr['stage'], ['empty', 'run', 'done'], true)) {
                $_SESSION['sc_fail'] = ['n' => 0, 't' => 0];
            }
            if ($wr['ok']) {
                $waResult = $wr['result'];
                $view = 'wa_result';
            } else {
                $error = $wr['error'];
                $waPreview = rh_sc_wipe_all_preview($pdo);
                $waEmpty = (int)$waPreview['total'] === 0 && (int)$waPreview['import']['removable'] === 0;
                $view = $waEmpty ? 'wa_preview' : 'wa_confirm';
            }
        }
    } elseif ($action === 'preview') {
        $picked = sc_posted_groups($allKeys);
        if (!$picked) {
            $error = 'Tick at least one thing to clear. Nothing was changed.';
        } else {
            $preview = rh_sc_preview($pdo, $area, $picked);
            $picked  = $preview['groups'];
            $view = 'confirm';
        }
    } elseif ($action === 'clear' || $action === 'undo') {
        $word = $action === 'clear' ? 'CLEAR' : 'UNDO';
        $picked = sc_posted_groups($allKeys);
        if (sc_locked_out()) {
            $error = 'Too many wrong attempts. Nothing was changed. Wait ten minutes and try again.';
        } else {
            [$okC, $whyC] = rh_sc_check_confirm($pdo, $user['id'], $word, (string)($_POST['confirm_word'] ?? ''), (string)($_POST['confirm_password'] ?? ''));
            if (!$okC) {
                sc_note_failure();
                $error = $whyC;
                if ($action === 'clear' && $picked) {
                    $preview = rh_sc_preview($pdo, $area, $picked);
                    $picked  = $preview['groups'];
                    $view = 'confirm';
                }
            } else {
                $_SESSION['sc_fail'] = ['n' => 0, 't' => 0];
                @set_time_limit(900);
                @ignore_user_abort(true);
                if ($action === 'clear') {
                    $r = rh_sc_run_clear($pdo, $ROOT, $user, $area, $picked);
                    if ($r['ok']) {
                        $result = $r;
                        $view = 'result';
                    } else {
                        $error = $r['error'];
                        if ($picked) {
                            $preview = rh_sc_preview($pdo, $area, $picked);
                            $picked  = $preview['groups'];
                            $view = 'confirm';
                        }
                    }
                } else {
                    $r = rh_sc_run_undo($pdo, $ROOT, $user, (string)($_POST['clear_id'] ?? ''));
                    if ($r['ok']) {
                        $undone = $r;
                        $view = 'undone';
                    } else {
                        $error = $r['error'];
                    }
                }
            }
        }
    } elseif ($action === 'cw_preview' || $action === 'cw_confirm') {
        // Stock count wipe, steps 1 and 2 (read-only). A blocked preview never reaches the confirm screen.
        $cwPreview = rh_sc_count_wipe_preview($pdo);
        $view = ($action === 'cw_confirm' && !$cwPreview['blocked'] && (int)$cwPreview['totals']['counts'] > 0) ? 'cw_confirm' : 'cw_preview';
    } elseif ($action === 'cw_wipe') {
        $cwImport = !empty($_POST['cw_import']);
        if (sc_locked_out()) {
            $error = 'Too many wrong attempts. Nothing was changed. Wait ten minutes and try again.';
            $cwPreview = rh_sc_count_wipe_preview($pdo);
            $view = 'cw_preview';
        } elseif (empty($_POST['cw_understand'])) {
            $error = 'Nothing was changed. Tick "I understand" to show you have read what this does.';
            $cwPreview = rh_sc_count_wipe_preview($pdo);
            $view = $cwPreview['blocked'] ? 'cw_preview' : 'cw_confirm';
        } else {
            [$okC, $whyC] = rh_sc_check_confirm($pdo, $user['id'], 'WIPE', (string)($_POST['confirm_word'] ?? ''), (string)($_POST['confirm_password'] ?? ''));
            if (!$okC) {
                sc_note_failure();
                $error = $whyC;
                $cwPreview = rh_sc_count_wipe_preview($pdo);
                $view = $cwPreview['blocked'] ? 'cw_preview' : 'cw_confirm';
            } else {
                $_SESSION['sc_fail'] = ['n' => 0, 't' => 0];
                @set_time_limit(900);
                @ignore_user_abort(true);
                $r = rh_sc_run_count_wipe($pdo, $ROOT, $user, ['delete_import_history' => $cwImport]);
                if ($r['ok']) {
                    $cwResult = $r;
                    $view = 'cw_result';
                } else {
                    $error = $r['error'];
                    $cwPreview = rh_sc_count_wipe_preview($pdo);
                    $view = $cwPreview['blocked'] ? 'cw_preview' : 'cw_confirm';
                }
            }
        }
    } else {
        $error = 'That action is not recognised. Nothing was changed.';
    }
}
if ($view === 'form' && $cwPreview === null && $canReset) {
    $cwPreview = rh_sc_count_wipe_preview($pdo); // read-only summary for the section on the first screen
}
if ($view === 'form' && $waPreview === null && $canWipeAll) {
    $waPreview = rh_sc_wipe_all_preview($pdo); // read-only summary for the wipe-all section on the first screen
}
$currency = (string)getSetting('currency_symbol');
$cwStatus = ['draft' => 'Being counted', 'submitted' => 'Waiting for approval', 'approved' => 'Approved', 'rejected' => 'Rejected'];

// What each group would remove right now (read-only), for the first screen.
$groupCounts = [];
$residueNow = ['alerts' => 0, 'review_cache' => 0, 'tool_cache' => 0];
if ($canReset && ($view === 'form' || $view === 'undone' || $view === 'result')) {
    foreach ($A['groups'] as $k => $g) {
        $groupCounts[$k] = rh_sc_counts($pdo, $area, $g['tables']);
    }
}
// Past clears: all of them with data_reset; only the full wipes (which they may undo) with stock_wipe_all alone.
$pastClears = rh_sc_visible_clears(rh_sc_list_clears($ROOT, $area), $canReset);

$e = static fn($v): string => htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8');
?>
<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Clear Stock Data — <?php echo $e(getSetting('site_name')); ?></title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Cormorant+Garamond:ital,wght@0,300;0,400;0,500;0,600;1,300;1,400;1,500&family=Jost:wght@300;400;500;600&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="css/admin-styles.css?v=<?php echo @filemtime(__DIR__ . '/css/admin-styles.css'); ?>">
    <link rel="stylesheet" href="css/admin-components.css?v=<?php echo @filemtime(__DIR__ . '/css/admin-components.css'); ?>">
    <link rel="stylesheet" href="css/stock-clear.css?v=<?php echo @filemtime(__DIR__ . '/css/stock-clear.css'); ?>">
</head>

<body>
    <?php require_once 'includes/admin-header.php'; ?>
    <div class="content" id="sc-page">
        <div class="content-header">
            <h2><i class="fas fa-eraser" style="color:var(--gold)"></i> Clear Stock Data</h2>
            <?php if ($canReset): ?>
                <p class="sc-lead">Empty stock data at this hotel so it can be set up again (for example before a fresh import). A backup is taken first, and every clear can be undone. Wiping stock counts (further down) is the one exception: it has no Undo.</p>
            <?php else: ?>
                <p class="sc-lead">Wipe all stock data and Import history at this hotel so it can be set up again. A full backup is taken first, and the stock part can be undone from this page.</p>
            <?php endif; ?>
        </div>

        <?php if ($message): ?>
            <div class="sc-alert sc-alert--ok" role="status"><i class="fas fa-check-circle"></i> <div><?php echo $e($message); ?></div></div>
        <?php endif; ?>
        <?php if ($error): ?>
            <div class="sc-alert sc-alert--bad" role="alert"><i class="fas fa-exclamation-triangle"></i> <div><?php echo $e($error); ?></div></div>
        <?php endif; ?>

        <?php if ($view === 'result' && $result): ?>
            <!-- ================= RESULT ================= -->
            <div class="sc-card">
                <h3><i class="fas fa-check-circle"></i> Stock data cleared</h3>
                <p>Removed: <strong><?php echo $e($result['sentence']); ?></strong>.</p>
                <p class="sc-small">Everything else is untouched: restaurant orders, payments, bookings and menu items.
                    <?php if ((int)$result['residue']['alerts_resolved'] > 0): ?>
                        <?php echo (int)$result['residue']['alerts_resolved']; ?> open stock alert(s) were closed, and cached stock figures and review panels were cleared so no old numbers remain.
                    <?php else: ?>
                        Cached stock figures and review panels were cleared so no old numbers remain.
                    <?php endif; ?>
                </p>
                <div class="sc-kpis">
                    <div class="sc-kpi">
                        <span class="sc-kpi__label">Snapshot of what was removed</span>
                        <span class="sc-kpi__value sc-kpi__value--file"><?php echo $e($result['snapshot']); ?></span>
                        <span class="sc-kpi__help">Kept on the server in the backups folder. This is what Undo puts back.</span>
                    </div>
                    <div class="sc-kpi">
                        <span class="sc-kpi__label">Full database backup taken first</span>
                        <span class="sc-kpi__value sc-kpi__value--file"><?php echo $e($result['full_backup']); ?></span>
                        <span class="sc-kpi__help">The safety copy of everything, listed on the Backups page.</span>
                    </div>
                </div>
                <?php if (!empty($result['forced'])): ?>
                    <p class="sc-small"><i class="fas fa-link"></i> Also cleared because they depend on what you ticked: <?php echo $e(implode(' ', array_map(static fn($k) => $A['groups'][$k]['label'] . '.', array_keys($result['forced'])))); ?></p>
                <?php endif; ?>
                <form method="POST" class="sc-confirm" autocomplete="off">
                    <input type="hidden" name="_tok" value="<?php echo $e($csrf_token); ?>">
                    <input type="hidden" name="action" value="undo">
                    <input type="hidden" name="clear_id" value="<?php echo $e($result['id']); ?>">
                    <p class="sc-small">Changed your mind? Undo puts back exactly what was removed, as long as no new stock has been added since.</p>
                    <div class="sc-confirm__row">
                        <label>Type <strong>UNDO</strong> <input type="text" name="confirm_word" class="form-control" autocomplete="off" required></label>
                        <label>Your password <input type="password" name="confirm_password" class="form-control" autocomplete="current-password" required></label>
                        <button type="submit" class="sc-btn sc-btn--secondary"><i class="fas fa-rotate-left"></i> Undo this clear</button>
                    </div>
                </form>
                <p><a class="sc-link" href="stock-clear.php">Back to Clear Stock Data</a></p>
            </div>

        <?php elseif ($view === 'undone' && $undone): ?>
            <!-- ================= UNDONE ================= -->
            <div class="sc-card">
                <h3><i class="fas fa-rotate-left"></i> Clear undone</h3>
                <p>Put back: <strong><?php echo $e($undone['sentence']); ?></strong>.</p>
                <p class="sc-small">The stock screens show these again straight away. Alerts that were closed by the clear are not re-opened; they appear again if the problem still exists.</p>
                <p><a class="sc-link" href="stock-clear.php">Back to Clear Stock Data</a></p>
            </div>

        <?php elseif ($view === 'confirm' && $preview): ?>
            <!-- ================= REVIEW + CONFIRM ================= -->
            <div class="sc-card sc-card--danger">
                <h3><i class="fas fa-exclamation-triangle"></i> Check this before you continue</h3>
                <p class="sc-warning"><strong>This removes the ticked stock data at this hotel only.</strong> Restaurant orders, payments, bookings and menu items are not touched.</p>

                <?php if (!empty($preview['forced'])): ?>
                    <div class="sc-alert sc-alert--info" role="status"><i class="fas fa-link"></i>
                        <div><strong>Also included, because it depends on what you ticked:</strong>
                            <ul>
                                <?php foreach ($preview['forced'] as $fk => $why): ?>
                                    <li><?php echo $e($A['groups'][$fk]['label']); ?>: <?php echo $e($why); ?></li>
                                <?php endforeach; ?>
                            </ul>
                        </div>
                    </div>
                <?php endif; ?>
                <?php foreach ($preview['groups'] as $gk): if ($A['groups'][$gk]['note'] !== ''): ?>
                    <p class="sc-small"><i class="fas fa-info-circle"></i> <?php echo $e($A['groups'][$gk]['label']); ?>: <?php echo $e($A['groups'][$gk]['note']); ?></p>
                <?php endif; endforeach; ?>

                <p class="sc-small">What will be removed (counts as of now). Each row says what the records are.</p>
                <div class="sc-tablewrap">
                    <table class="sc-table">
                        <thead>
                            <tr>
                                <th>Records<span class="sc-th-help">What kind of record it is</span></th>
                                <th class="sc-num">To be removed<span class="sc-th-help">How many rows are in it right now</span></th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($preview['counts'] as $t => $n): ?>
                                <tr>
                                    <td><strong><?php echo $e(ucfirst($A['table_labels'][$t][1])); ?></strong><br><span class="sc-small"><?php echo $e($A['table_labels'][$t][2]); ?></span></td>
                                    <td class="sc-num"><?php echo number_format((int)$n); ?></td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
                <p class="sc-small"><i class="fas fa-broom"></i> Also closed as part of every stock clear: <?php echo (int)$preview['residue']['alerts']; ?> open stock alert(s) and <?php echo (int)$preview['residue']['review_cache']; ?> saved stock review panel(s), plus cached stock figures. Nothing in the Assistant's chat history is touched.</p>
                <p class="sc-small"><i class="fas fa-store"></i> If retail products are saved later, stock items come back automatically for them.</p>

                <?php if ((int)$preview['total'] === 0): ?>
                    <div class="sc-alert sc-alert--info" role="status"><i class="fas fa-info-circle"></i> <div>There is nothing to remove in what you ticked, so there is nothing to clear.</div></div>
                    <p><a class="sc-link" href="stock-clear.php">Back</a></p>
                <?php else: ?>
                    <form method="POST" class="sc-confirm" autocomplete="off">
                        <input type="hidden" name="_tok" value="<?php echo $e($csrf_token); ?>">
                        <input type="hidden" name="action" value="clear">
                        <?php foreach ($preview['groups'] as $gk): ?>
                            <input type="hidden" name="groups[]" value="<?php echo $e($gk); ?>">
                        <?php endforeach; ?>
                        <p class="sc-small">Before anything is removed, the system saves a full backup of the whole database and a snapshot of exactly these rows. If either cannot be saved, nothing is changed. This can take up to a minute; keep the page open.</p>
                        <div class="sc-confirm__row">
                            <label>Type <strong>CLEAR</strong> to confirm <input type="text" name="confirm_word" class="form-control" autocomplete="off" required></label>
                            <label>Your own password <input type="password" name="confirm_password" class="form-control" autocomplete="current-password" required></label>
                        </div>
                        <div class="sc-actions">
                            <button type="submit" class="sc-btn sc-btn--danger"><i class="fas fa-eraser"></i> Back up and clear</button>
                            <a class="sc-link" href="stock-clear.php">Cancel, change nothing</a>
                        </div>
                    </form>
                <?php endif; ?>
            </div>

        <?php elseif ($view === 'cw_result' && $cwResult): ?>
            <!-- ================= STOCK COUNT WIPE: RESULT ================= -->
            <div class="sc-card">
                <h3><i class="fas fa-check-circle"></i> Stock counts wiped</h3>
                <p>Removed: <strong><?php echo $e($cwResult['sentence']); ?></strong>.</p>
                <p class="sc-small"><?php echo (int)$cwResult['totals']['items']; ?> stock item(s) went down by what the counts had added (<?php echo (int)$cwResult['totals']['items_to_zero']; ?> of them to 0). Stock value fell by <?php echo $e($currency); ?> <?php echo number_format((float)$cwResult['totals']['value'], 2); ?>.
                    <?php if ((int)$cwResult['totals']['alerts_resolved'] > 0): ?><?php echo (int)$cwResult['totals']['alerts_resolved']; ?> open count-approval alert(s) were closed. <?php endif; ?>
                    Sales, orders, payments and bookings were not touched.</p>
                <div class="sc-kpis">
                    <div class="sc-kpi">
                        <span class="sc-kpi__label">Full database backup taken first</span>
                        <span class="sc-kpi__value sc-kpi__value--file"><?php echo $e($cwResult['full_backup']); ?></span>
                        <span class="sc-kpi__help">The only way to bring the counts back: restore this file on the Backups page.</span>
                    </div>
                    <div class="sc-kpi">
                        <span class="sc-kpi__label">Record of what was removed</span>
                        <span class="sc-kpi__value sc-kpi__value--file"><?php echo $e($cwResult['snapshot']); ?></span>
                        <span class="sc-kpi__help">Every removed row, kept on the server for reference. It cannot be used as an Undo.</span>
                    </div>
                </div>
                <?php if (!empty($cwResult['import'])): ?>
                    <p class="sc-small"><strong>Import history entries:</strong></p>
                    <ul class="sc-list">
                        <?php foreach ($cwResult['import'] as $ir): ?>
                            <li><?php echo $e($ir['name'] !== '' ? $ir['name'] : ('Import #' . $ir['job_id'])); ?>:
                                <?php echo $ir['ok'] ? 'removed' . ($ir['snapshot'] !== '' ? ' (record ' . $e($ir['snapshot']) . ')' : '') . '.' : $e($ir['error'] !== '' ? $ir['error'] : 'not removed.'); ?></li>
                        <?php endforeach; ?>
                    </ul>
                <?php endif; ?>
                <p><a class="sc-link" href="stock-clear.php">Back to Clear Stock Data</a></p>
            </div>

        <?php elseif (($view === 'cw_preview' || $view === 'cw_confirm') && $cwPreview): $cwT = $cwPreview['totals']; ?>
            <!-- ================= STOCK COUNT WIPE: PREVIEW / CONFIRM ================= -->
            <div class="sc-card sc-card--danger">
                <h3><i class="fas fa-clipboard-list"></i> Stock counts and the stock they added<?php echo $view === 'cw_confirm' ? ': confirm' : ''; ?></h3>
                <p class="sc-warning"><strong>This removes every stock count at this hotel, and takes back off stock what their approvals added.</strong> There is no Undo for this.</p>

                <?php if ((int)$cwT['counts'] === 0 && !$cwPreview['blocked']): ?>
                    <div class="sc-alert sc-alert--info" role="status"><i class="fas fa-info-circle"></i> <div>There are no stock counts at this hotel, so there is nothing to wipe.</div></div>
                    <p><a class="sc-link" href="stock-clear.php">Back</a></p>
                <?php else: ?>
                    <div class="sc-kpis">
                        <div class="sc-kpi">
                            <span class="sc-kpi__label">Stock counts</span>
                            <span class="sc-kpi__value sc-kpi__value--file"><?php echo number_format((int)$cwT['counts']); ?></span>
                            <span class="sc-kpi__help">Every count, whatever its status, with its <?php echo number_format((int)$cwT['lines']); ?> counted line(s).</span>
                        </div>
                        <div class="sc-kpi">
                            <span class="sc-kpi__label">Stock items that change</span>
                            <span class="sc-kpi__value sc-kpi__value--file"><?php echo number_format((int)$cwT['items']); ?></span>
                            <span class="sc-kpi__help">Items whose stock on hand goes down; <?php echo number_format((int)$cwT['items_to_zero']); ?> of them go to 0 because the count was their only stock.</span>
                        </div>
                        <div class="sc-kpi">
                            <span class="sc-kpi__label">Stock value removed</span>
                            <span class="sc-kpi__value sc-kpi__value--file"><?php echo $e($currency); ?> <?php echo number_format((float)$cwT['value'], 2); ?></span>
                            <span class="sc-kpi__help">How much the stock valuation drops: each item's stock on hand now minus after, × its unit cost.</span>
                        </div>
                        <div class="sc-kpi">
                            <span class="sc-kpi__label">Batches and stock changes removed</span>
                            <span class="sc-kpi__value sc-kpi__value--file"><?php echo number_format((int)$cwT['batches']); ?> batch(es), <?php echo number_format((int)$cwT['adjustments']); ?> change(s)</span>
                            <span class="sc-kpi__help">The batches the approvals created and their stock change records, plus <?php echo number_format((int)$cwT['opening_batches']); ?> opening batch(es).</span>
                        </div>
                    </div>

                    <?php if ($cwPreview['blocked']): ?>
                        <div class="sc-alert sc-alert--bad" role="alert"><i class="fas fa-ban"></i>
                            <div><strong>This cannot be done exactly, so it is switched off.</strong> Stock has moved since these counts were approved:
                                <ul>
                                    <?php foreach (array_slice($cwPreview['blocked'], 0, 30) as $why): ?>
                                        <li><?php echo $e($why); ?></li>
                                    <?php endforeach; ?>
                                    <?php if (count($cwPreview['blocked']) > 30): ?><li>and <?php echo count($cwPreview['blocked']) - 30; ?> more.</li><?php endif; ?>
                                </ul>
                            </div>
                        </div>
                    <?php endif; ?>

                    <p class="sc-small">The counts that would be removed. Each row says what its approval added.</p>
                    <div class="sc-tablewrap">
                        <table class="sc-table">
                            <thead>
                                <tr>
                                    <th>Count<span class="sc-th-help">Reference and date counted</span></th>
                                    <th>Status<span class="sc-th-help">Where the count is now</span></th>
                                    <th>Opening<span class="sc-th-help">Yes = the first count when stock was set up</span></th>
                                    <th class="sc-num">Lines<span class="sc-th-help">Items counted on it</span></th>
                                    <th class="sc-num">Value added<span class="sc-th-help">What its approval added to stock (<?php echo $e($currency); ?>)</span></th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($cwPreview['counts'] as $cr): ?>
                                    <tr>
                                        <td><strong><?php echo $e($cr['reference']); ?></strong><br><span class="sc-small"><?php echo $e($cr['count_date']); ?></span></td>
                                        <td><?php echo $e($cwStatus[$cr['status']] ?? $cr['status']); ?></td>
                                        <td><?php echo $cr['is_opening'] ? 'Yes' : 'No'; ?></td>
                                        <td class="sc-num"><?php echo number_format((int)$cr['lines']); ?></td>
                                        <td class="sc-num"><?php echo number_format((float)$cr['value'], 2); ?></td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>

                    <?php if ($cwPreview['items']): ?>
                        <details class="sc-undo">
                            <summary class="sc-btn sc-btn--secondary sc-btn--small"><i class="fas fa-list"></i> Show the <?php echo number_format(count($cwPreview['items'])); ?> stock item(s) that change</summary>
                            <div class="sc-tablewrap">
                                <table class="sc-table">
                                    <thead>
                                        <tr>
                                            <th>Stock item<span class="sc-th-help">Name and unit</span></th>
                                            <th class="sc-num">On hand now<span class="sc-th-help">Current stock</span></th>
                                            <th class="sc-num">Taken off<span class="sc-th-help">What the counts added</span></th>
                                            <th class="sc-num">On hand after<span class="sc-th-help">Now minus taken off</span></th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php foreach ($cwPreview['items'] as $it): ?>
                                            <tr>
                                                <td><strong><?php echo $e($it['name']); ?></strong><?php if ($it['unit'] !== ''): ?> <span class="sc-small">(<?php echo $e($it['unit']); ?>)</span><?php endif; ?></td>
                                                <td class="sc-num"><?php echo $e(rh_sc_cw_qty((float)$it['on_hand'])); ?></td>
                                                <td class="sc-num"><?php echo $e(rh_sc_cw_qty((float)$it['remove'])); ?></td>
                                                <td class="sc-num"><?php echo $e(rh_sc_cw_qty((float)$it['after'])); ?></td>
                                            </tr>
                                        <?php endforeach; ?>
                                    </tbody>
                                </table>
                            </div>
                        </details>
                    <?php endif; ?>

                    <?php if ($cwPreview['blocked']): ?>
                        <p><a class="sc-link" href="stock-clear.php">Back to Clear Stock Data</a></p>
                    <?php elseif ($view === 'cw_preview'): ?>
                        <form method="POST" class="sc-actions">
                            <input type="hidden" name="_tok" value="<?php echo $e($csrf_token); ?>">
                            <input type="hidden" name="action" value="cw_confirm">
                            <button type="submit" class="sc-btn sc-btn--primary"><i class="fas fa-arrow-right"></i> Continue to confirm</button>
                            <a class="sc-link" href="stock-clear.php">Cancel, change nothing</a>
                        </form>
                    <?php else: ?>
                        <div class="sc-alert sc-alert--bad" role="note"><i class="fas fa-exclamation-triangle"></i>
                            <div><strong>What this will do:</strong>
                                <ul>
                                    <li>Stock on hand goes down for <?php echo number_format((int)$cwT['items']); ?> item(s), by what the counts added (<?php echo number_format((int)$cwT['items_to_zero']); ?> of them to 0, where the count was the only stock). The list above shows each one.</li>
                                    <li>Stock value falls by <?php echo $e($currency); ?> <?php echo number_format((float)$cwT['value'], 2); ?>, and stock reports and valuations change from now on.</li>
                                    <li>All count history and approvals disappear from Stock Count.</li>
                                    <li>There is no Undo on this page. Only the full backup taken first can bring them back, and restoring it also rolls back everything else done since.</li>
                                    <li>Sales, orders, payments and bookings are not touched.</li>
                                </ul>
                            </div>
                        </div>
                        <form method="POST" autocomplete="off">
                            <input type="hidden" name="_tok" value="<?php echo $e($csrf_token); ?>">
                            <input type="hidden" name="action" value="cw_wipe">
                            <div class="sc-groups">
                                <label class="sc-group">
                                    <input type="checkbox" name="cw_understand" value="1" class="sc-group__box" required>
                                    <span class="sc-group__body">
                                        <span class="sc-group__title">I understand</span>
                                        <span class="sc-group__help">I have read what this does, and I know it can only be reversed by restoring the full backup.</span>
                                    </span>
                                </label>
                                <?php if ((int)$cwT['import_jobs'] > 0): ?>
                                    <label class="sc-group">
                                        <input type="checkbox" name="cw_import" value="1" class="sc-group__box" <?php echo $cwImport ? 'checked' : ''; ?>>
                                        <span class="sc-group__body">
                                            <span class="sc-group__title">Also remove the Import history entries that created these counts</span>
                                            <span class="sc-group__help"><?php echo number_format((int)$cwT['import_jobs']); ?> entr<?php echo (int)$cwT['import_jobs'] === 1 ? 'y' : 'ies'; ?> on the Import page. Optional: done after the wipe, and the wipe still counts if one of them cannot be removed.</span>
                                        </span>
                                    </label>
                                <?php endif; ?>
                            </div>
                            <p class="sc-small">Before anything is removed, the system saves a full backup of the whole database and a record of exactly these rows. If either cannot be saved, nothing is changed. This can take up to a minute; keep the page open.</p>
                            <div class="sc-confirm"><div class="sc-confirm__row">
                                <label>Type <strong>WIPE</strong> to confirm <input type="text" name="confirm_word" class="form-control" autocomplete="off" required></label>
                                <label>Your own password <input type="password" name="confirm_password" class="form-control" autocomplete="current-password" required></label>
                            </div></div>
                            <div class="sc-actions">
                                <button type="submit" class="sc-btn sc-btn--danger"><i class="fas fa-eraser"></i> Back up and wipe the counts</button>
                                <a class="sc-link" href="stock-clear.php">Cancel, change nothing</a>
                            </div>
                        </form>
                    <?php endif; ?>
                <?php endif; ?>
            </div>

        <?php elseif ($view === 'wa_result' && $waResult): ?>
            <!-- ================= WIPE ALL STOCK: RESULT ================= -->
            <div class="sc-card">
                <h3><i class="fas fa-check-circle"></i> All stock and its records wiped</h3>
                <p>Removed: <strong><?php echo $e($waResult['sentence'] !== '' ? $waResult['sentence'] : 'no stock records (there were none)'); ?></strong>.</p>
                <p class="sc-small">Stock value is now 0. Sales, orders, payments, bookings and menu items were not touched. System Logs and the import wizard's learned matches stay.
                    <?php if ((int)($waResult['residue']['alerts_resolved'] ?? 0) > 0): ?><?php echo (int)$waResult['residue']['alerts_resolved']; ?> open stock alert(s) were closed. <?php endif; ?>
                    Cached stock figures and review panels were cleared so no old numbers remain.</p>
                <div class="sc-kpis">
                    <div class="sc-kpi">
                        <span class="sc-kpi__label">Snapshot of what was removed</span>
                        <span class="sc-kpi__value sc-kpi__value--file"><?php echo $e($waResult['snapshot']); ?></span>
                        <span class="sc-kpi__help">Kept on the server in the backups folder. This is what Undo puts back (stock records only).</span>
                    </div>
                    <div class="sc-kpi">
                        <span class="sc-kpi__label">Full database backup taken first</span>
                        <span class="sc-kpi__value sc-kpi__value--file"><?php echo $e($waResult['full_backup']); ?></span>
                        <span class="sc-kpi__help">The safety copy of everything, listed on the Backups page.</span>
                    </div>
                    <div class="sc-kpi">
                        <span class="sc-kpi__label">Import history entries removed</span>
                        <span class="sc-kpi__value sc-kpi__value--file"><?php echo number_format((int)$waResult['import_removed']); ?></span>
                        <span class="sc-kpi__help">Each one has its own backup copy on the server. <?php echo number_format((int)$waResult['import_kept']); ?> kept (listed below).</span>
                    </div>
                </div>
                <?php if (!empty($waResult['import'])): ?>
                    <p class="sc-small"><strong>Import history entries:</strong></p>
                    <ul class="sc-list">
                        <?php foreach ($waResult['import'] as $ir): ?>
                            <li><?php echo $e($ir['name'] !== '' ? $ir['name'] : ('Import #' . $ir['job_id'])); ?>:
                                <?php echo $ir['ok'] ? 'removed' . ($ir['snapshot'] !== '' ? ' (backup copy ' . $e($ir['snapshot']) . ')' : '') . '.' : $e($ir['error'] !== '' ? $ir['error'] : 'kept.'); ?></li>
                        <?php endforeach; ?>
                    </ul>
                <?php endif; ?>
                <form method="POST" class="sc-confirm" autocomplete="off">
                    <input type="hidden" name="_tok" value="<?php echo $e($csrf_token); ?>">
                    <input type="hidden" name="action" value="undo">
                    <input type="hidden" name="clear_id" value="<?php echo $e($waResult['id']); ?>">
                    <p class="sc-small">Changed your mind? Undo puts the stock records back, as long as no new stock has been added since. It does not bring back Import history: only its backup copies hold that.</p>
                    <div class="sc-confirm__row">
                        <label>Type <strong>UNDO</strong> <input type="text" name="confirm_word" class="form-control" autocomplete="off" required></label>
                        <label>Your password <input type="password" name="confirm_password" class="form-control" autocomplete="current-password" required></label>
                        <button type="submit" class="sc-btn sc-btn--secondary"><i class="fas fa-rotate-left"></i> Undo this wipe</button>
                    </div>
                </form>
                <p><a class="sc-link" href="stock-clear.php">Back to Clear Stock Data</a></p>
            </div>

        <?php elseif (($view === 'wa_preview' || $view === 'wa_confirm') && $waPreview): $waI = $waPreview['import']; $waNothing = (int)$waPreview['total'] === 0 && (int)$waI['removable'] === 0; ?>
            <!-- ================= WIPE ALL STOCK: PREVIEW / CONFIRM ================= -->
            <div class="sc-card sc-card--danger">
                <h3><i class="fas fa-triangle-exclamation"></i> Wipe all stock and every record<?php echo $view === 'wa_confirm' ? ': confirm' : ''; ?></h3>
                <p class="sc-warning"><strong>This removes all stock data at this hotel and every Import history entry.</strong> Sales, orders, payments, bookings and menu items are not touched.</p>

                <?php if ($waNothing): ?>
                    <div class="sc-alert sc-alert--info" role="status"><i class="fas fa-info-circle"></i> <div>There is no stock data and no Import history that can be removed, so there is nothing to wipe.</div></div>
                    <p><a class="sc-link" href="stock-clear.php">Back</a></p>
                <?php else: ?>
                    <div class="sc-kpis">
                        <div class="sc-kpi">
                            <span class="sc-kpi__label">Stock records to be removed</span>
                            <span class="sc-kpi__value sc-kpi__value--file"><?php echo number_format((int)$waPreview['total']); ?></span>
                            <span class="sc-kpi__help">All rows in every stock table; the table below splits them by kind.</span>
                        </div>
                        <div class="sc-kpi">
                            <span class="sc-kpi__label">Import history entries to be removed</span>
                            <span class="sc-kpi__value sc-kpi__value--file"><?php echo number_format((int)$waI['removable']); ?></span>
                            <span class="sc-kpi__help">Finished, failed or cancelled entries on the Import page<?php echo $waI['kept'] ? '; ' . number_format(count($waI['kept'])) . ' in progress kept' : ''; ?>.</span>
                        </div>
                    </div>
                    <p class="sc-small">What will be removed (counts as of now). Each row says what the records are.</p>
                    <div class="sc-tablewrap">
                        <table class="sc-table">
                            <thead>
                                <tr>
                                    <th>Records<span class="sc-th-help">What kind of record it is</span></th>
                                    <th class="sc-num">To be removed<span class="sc-th-help">How many rows are in it right now</span></th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($waPreview['counts'] as $t => $n): ?>
                                    <tr>
                                        <td><strong><?php echo $e(ucfirst($A['table_labels'][$t][1])); ?></strong><br><span class="sc-small"><?php echo $e($A['table_labels'][$t][2]); ?></span></td>
                                        <td class="sc-num"><?php echo number_format((int)$n); ?></td>
                                    </tr>
                                <?php endforeach; ?>
                                <tr>
                                    <td><strong>Import history entries</strong><br><span class="sc-small">finished, failed or cancelled imports listed on the Import page</span></td>
                                    <td class="sc-num"><?php echo number_format((int)$waI['removable']); ?></td>
                                </tr>
                            </tbody>
                        </table>
                    </div>
                    <p class="sc-small"><i class="fas fa-broom"></i> Also closed: <?php echo (int)$waPreview['residue']['alerts']; ?> open stock alert(s) and <?php echo (int)$waPreview['residue']['review_cache']; ?> saved stock review panel(s), plus cached stock figures.</p>
                    <?php if ($waI['kept']): ?>
                        <div class="sc-alert sc-alert--info" role="status"><i class="fas fa-info-circle"></i>
                            <div><strong><?php echo number_format(count($waI['kept'])); ?> Import history entr<?php echo count($waI['kept']) === 1 ? 'y is' : 'ies are'; ?> still in progress and will be kept:</strong>
                                <ul>
                                    <?php foreach (array_slice($waI['kept'], 0, 30) as $k): ?>
                                        <li><?php echo $e($k['name'] !== '' ? $k['name'] : ('Import #' . $k['job_id'])); ?>: <?php echo $e($k['reason']); ?></li>
                                    <?php endforeach; ?>
                                    <?php if (count($waI['kept']) > 30): ?><li>and <?php echo count($waI['kept']) - 30; ?> more.</li><?php endif; ?>
                                </ul>
                            </div>
                        </div>
                    <?php endif; ?>

                    <?php if ($view === 'wa_preview'): ?>
                        <form method="POST" class="sc-actions">
                            <input type="hidden" name="_tok" value="<?php echo $e($csrf_token); ?>">
                            <input type="hidden" name="action" value="wa_confirm">
                            <button type="submit" class="sc-btn sc-btn--primary"><i class="fas fa-arrow-right"></i> Continue to confirm</button>
                            <a class="sc-link" href="stock-clear.php">Cancel, change nothing</a>
                        </form>
                    <?php else: ?>
                        <div class="sc-alert sc-alert--bad" role="note"><i class="fas fa-exclamation-triangle"></i>
                            <div><strong>What this will do:</strong>
                                <ul>
                                    <li>Every stock item, batch, count, adjustment, wastage record, receiving entry, barcode, recipe line and recipe, supplier, purchase order and budget is removed.</li>
                                    <li>Stock value goes to 0, and stock reports and valuations change from now on.</li>
                                    <li>Import history is removed (entries still in progress are kept).</li>
                                    <li>Sales, orders, payments, bookings and menu items are not touched.</li>
                                    <li>System Logs and the import wizard's learned matches stay.</li>
                                    <li>The stock part can be undone from this page while the stock tables are still empty. Import history can only come back by restoring its backup copies.</li>
                                </ul>
                            </div>
                        </div>
                        <form method="POST" autocomplete="off">
                            <input type="hidden" name="_tok" value="<?php echo $e($csrf_token); ?>">
                            <input type="hidden" name="action" value="wa_wipe">
                            <div class="sc-groups">
                                <label class="sc-group">
                                    <input type="checkbox" name="wa_understand" value="1" class="sc-group__box" required>
                                    <span class="sc-group__body">
                                        <span class="sc-group__title">I understand</span>
                                        <span class="sc-group__help">I have read what this does. All stock data and Import history at this hotel will be removed.</span>
                                    </span>
                                </label>
                            </div>
                            <p class="sc-small">Before anything is removed, the system saves a full backup of the whole database and a snapshot of exactly these rows. If either cannot be saved, nothing is changed. This can take up to a minute; keep the page open.</p>
                            <div class="sc-confirm"><div class="sc-confirm__row">
                                <label>Type <strong>WIPE ALL</strong> to confirm <input type="text" name="confirm_word" class="form-control" autocomplete="off" required></label>
                                <label>Your own password <input type="password" name="confirm_password" class="form-control" autocomplete="current-password" required></label>
                            </div></div>
                            <div class="sc-actions">
                                <button type="submit" class="sc-btn sc-btn--danger"><i class="fas fa-eraser"></i> Back up and wipe everything</button>
                                <a class="sc-link" href="stock-clear.php">Cancel, change nothing</a>
                            </div>
                        </form>
                    <?php endif; ?>
                <?php endif; ?>
            </div>

        <?php else: ?>
            <?php if ($canWipeAll && $waPreview): $waI = $waPreview['import']; $waNothing = (int)$waPreview['total'] === 0 && (int)$waI['removable'] === 0; ?>
            <!-- ================= WIPE ALL STOCK: SECTION ================= -->
            <form method="POST" class="sc-card sc-card--danger">
                <input type="hidden" name="_tok" value="<?php echo $e($csrf_token); ?>">
                <input type="hidden" name="action" value="wa_preview">
                <h3><i class="fas fa-triangle-exclamation"></i> Wipe all stock and every record</h3>
                <p class="sc-small">One step for a complete fresh start: removes every stock item and everything recorded against it, recipes, suppliers, purchase orders and budgets, and every finished Import history entry. A full backup is taken first. Sales, orders, payments, bookings and menu items are not touched.</p>
                <div class="sc-kpis">
                    <div class="sc-kpi">
                        <span class="sc-kpi__label">Stock records now</span>
                        <span class="sc-kpi__value sc-kpi__value--file"><?php echo number_format((int)$waPreview['total']); ?></span>
                        <span class="sc-kpi__help">Rows across every stock table: items, batches, counts, changes, wastage, receiving, barcodes, recipes, suppliers, purchase orders and budgets.</span>
                    </div>
                    <div class="sc-kpi">
                        <span class="sc-kpi__label">Import history entries</span>
                        <span class="sc-kpi__value sc-kpi__value--file"><?php echo number_format((int)$waI['removable']); ?></span>
                        <span class="sc-kpi__help">Finished entries on the Import page that would be removed<?php echo $waI['kept'] ? '; ' . number_format(count($waI['kept'])) . ' still in progress would be kept' : ''; ?>.</span>
                    </div>
                </div>
                <div class="sc-actions">
                    <button type="submit" class="sc-btn sc-btn--secondary" <?php echo $waNothing ? 'disabled' : ''; ?>><i class="fas fa-eye"></i> Show me what will be removed</button>
                    <?php if ($waNothing): ?><span class="sc-small">There is nothing to wipe.</span><?php endif; ?>
                </div>
            </form>
            <?php endif; ?>

            <?php if ($canReset): ?>
            <!-- ================= CHOOSE ================= -->
            <form method="POST" id="sc-choose" class="sc-card">
                <input type="hidden" name="_tok" value="<?php echo $e($csrf_token); ?>">
                <input type="hidden" name="action" value="preview">
                <h3><i class="fas fa-list-check"></i> 1. Choose what to clear</h3>
                <p class="sc-small">Tick what you want to remove. Nothing changes until you confirm on the next screen. The counts show what is there now.</p>

                <div class="sc-groups">
                    <?php foreach ($A['groups'] as $gk => $g):
                        $gc = $groupCounts[$gk] ?? [];
                        $gt = (int)array_sum($gc); ?>
                        <label class="sc-group" data-group="<?php echo $e($gk); ?>">
                            <input type="checkbox" name="groups[]" value="<?php echo $e($gk); ?>" class="sc-group__box">
                            <span class="sc-group__body">
                                <span class="sc-group__title"><?php echo $e($g['label']); ?></span>
                                <span class="sc-group__help"><?php echo $e($g['helper']); ?></span>
                                <span class="sc-group__count <?php echo $gt === 0 ? 'is-zero' : ''; ?>"><?php echo $gt === 0 ? 'Nothing here to remove right now' : $e(rh_sc_sentence($area, $gc)); ?></span>
                                <?php if (!empty($g['forces'])): ?>
                                    <span class="sc-group__force" data-forces="<?php echo $e(implode(',', array_keys($g['forces']))); ?>">
                                        Ticking this also ticks <?php echo $e(implode(', ', array_map(static fn($k) => $A['groups'][$k]['label'], array_keys($g['forces'])))); ?>, and removes recipe lines.
                                    </span>
                                <?php endif; ?>
                            </span>
                        </label>
                    <?php endforeach; ?>
                    <label class="sc-group sc-group--all">
                        <input type="checkbox" name="everything" value="1" id="sc-everything">
                        <span class="sc-group__body">
                            <span class="sc-group__title">Everything above</span>
                            <span class="sc-group__help">Ticks all five. Use this for a full fresh start before re-importing stock.</span>
                        </span>
                    </label>
                </div>
                <p id="sc-forced-note" class="sc-alert sc-alert--info" role="status" hidden><i class="fas fa-link"></i> <span></span></p>

                <div class="sc-actions">
                    <button type="submit" class="sc-btn sc-btn--primary"><i class="fas fa-eye"></i> Show me what will be removed</button>
                </div>
            </form>

            <div class="sc-card">
                <h3><i class="fas fa-shield-halved"></i> How this is kept safe</h3>
                <ul class="sc-list">
                    <li>A full backup of the whole database is saved first, then a snapshot of exactly the rows being removed.</li>
                    <li>If either backup fails, nothing is removed. If anything goes wrong while removing, everything is rolled back.</li>
                    <li>Restaurant orders, payments, bookings and menu items are never touched.</li>
                    <li>Alerts about stock and saved stock review panels are closed or cleared with it, so no old figures stay behind.</li>
                    <li>You can undo a clear below, as long as no new stock has been added since.</li>
                </ul>
            </div>

            <!-- ================= STOCK COUNT WIPE: SECTION ================= -->
            <?php $cwT = $cwPreview['totals'] ?? ['counts' => 0, 'items' => 0, 'value' => 0]; ?>
            <form method="POST" class="sc-card sc-card--danger">
                <input type="hidden" name="_tok" value="<?php echo $e($csrf_token); ?>">
                <input type="hidden" name="action" value="cw_preview">
                <h3><i class="fas fa-clipboard-list"></i> Stock counts and the stock they added</h3>
                <p class="sc-small">Removes every stock count (counted, waiting, approved or rejected) and takes back off stock what the approved counts added, so stock goes back to what it was before the counts. Use it when counts were entered by mistake. <strong>This has no Undo:</strong> only the full backup taken first can bring them back.</p>
                <div class="sc-kpis">
                    <div class="sc-kpi">
                        <span class="sc-kpi__label">Stock counts now</span>
                        <span class="sc-kpi__value sc-kpi__value--file"><?php echo number_format((int)$cwT['counts']); ?></span>
                        <span class="sc-kpi__help">Every count on Stock Count, whatever its status.</span>
                    </div>
                    <div class="sc-kpi">
                        <span class="sc-kpi__label">Stock value that would be removed</span>
                        <span class="sc-kpi__value sc-kpi__value--file"><?php echo $e($currency); ?> <?php echo number_format((float)$cwT['value'], 2); ?></span>
                        <span class="sc-kpi__help">The drop in stock valuation (on hand now minus after, × unit cost) across <?php echo number_format((int)$cwT['items']); ?> stock item(s).</span>
                    </div>
                </div>
                <?php if (!empty($cwPreview['blocked'])): ?>
                    <p class="sc-alert sc-alert--bad" role="status"><i class="fas fa-ban"></i> <span>This cannot be done exactly right now (<?php echo count($cwPreview['blocked']); ?> reason<?php echo count($cwPreview['blocked']) === 1 ? '' : 's'; ?>). Review the counts to see why.</span></p>
                <?php endif; ?>
                <div class="sc-actions">
                    <button type="submit" class="sc-btn sc-btn--secondary" <?php echo (int)$cwT['counts'] === 0 && empty($cwPreview['blocked']) ? 'disabled' : ''; ?>><i class="fas fa-eye"></i> Review the counts first</button>
                    <?php if ((int)$cwT['counts'] === 0 && empty($cwPreview['blocked'])): ?><span class="sc-small">There are no stock counts to wipe.</span><?php endif; ?>
                </div>
            </form>
            <?php endif; /* $canReset */ ?>

            <!-- ================= PAST CLEARS ================= -->
            <div class="sc-card">
                <h3><i class="fas fa-clock-rotate-left"></i> Past clears</h3>
                <?php if (!$pastClears): ?>
                    <p class="sc-small"><?php echo $canReset ? 'No stock has been cleared with this page yet.' : 'No "Wipe all stock" has been done with this page yet.'; ?></p>
                <?php else: ?>
                    <p class="sc-small">Newest first.<?php echo $canReset ? '' : ' Only wipes made with "Wipe all stock" are listed here.'; ?> Undo puts back exactly what that clear removed, and only if none of those records exist again.</p>
                    <div class="sc-tablewrap">
                        <table class="sc-table">
                            <thead>
                                <tr>
                                    <th>When<span class="sc-th-help">When it was cleared</span></th>
                                    <th>By<span class="sc-th-help">The administrator who did it</span></th>
                                    <th>What was removed<span class="sc-th-help">From the snapshot description</span></th>
                                    <th>Undo<span class="sc-th-help">Put these records back</span></th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($pastClears as $pc): ?>
                                    <tr>
                                        <td><?php echo $e($pc['created_at'] ?? ''); ?></td>
                                        <td><?php echo $e(($pc['by_name'] ?? '') !== '' ? $pc['by_name'] : ($pc['by_username'] ?? '')); ?></td>
                                        <td>
                                            <?php echo rh_sc_manifest_is_count_wipe($pc) ? '<strong>Stock count wipe:</strong> ' : ''; ?><?php echo $e(rh_sc_sentence($area, (array)($pc['tables'] ?? []))); ?><br>
                                            <span class="sc-small"><?php echo $e($pc['snapshot_file'] ?? ''); ?></span>
                                        </td>
                                        <td>
                                            <?php if (rh_sc_manifest_is_count_wipe($pc)): ?>
                                                <span class="sc-pill">No Undo</span><br>
                                                <span class="sc-small"><?php echo $e(rh_sc_count_wipe_restore_text($pc)); ?></span>
                                            <?php elseif (!empty($pc['undone_at'])): ?>
                                                <span class="sc-pill">Undone <?php echo $e($pc['undone_at']); ?></span>
                                            <?php elseif (empty($pc['snapshot_exists'])): ?>
                                                <span class="sc-pill sc-pill--bad">Snapshot file missing</span>
                                            <?php else: ?>
                                                <details class="sc-undo">
                                                    <summary class="sc-btn sc-btn--secondary sc-btn--small"><i class="fas fa-rotate-left"></i> Undo this clear</summary>
                                                    <form method="POST" class="sc-confirm" autocomplete="off">
                                                        <input type="hidden" name="_tok" value="<?php echo $e($csrf_token); ?>">
                                                        <input type="hidden" name="action" value="undo">
                                                        <input type="hidden" name="clear_id" value="<?php echo $e($pc['id']); ?>">
                                                        <label>Type <strong>UNDO</strong> <input type="text" name="confirm_word" class="form-control" autocomplete="off" required></label>
                                                        <label>Your password <input type="password" name="confirm_password" class="form-control" autocomplete="current-password" required></label>
                                                        <button type="submit" class="sc-btn sc-btn--primary sc-btn--small">Put these back</button>
                                                    </form>
                                                </details>
                                            <?php endif; ?>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                <?php endif; ?>
                <p class="sc-small">Looking for a whole-database restore? That is on the <a class="sc-link" href="backup-management.php">Backups</a> page. It also rolls back bookings and payments made since, so Undo here is usually what you want.</p>
            </div>
        <?php endif; ?>
    </div>

    <?php require_once 'includes/admin-footer.php'; ?>

    <script>
        (function () {
            // Ticking boxes: "Everything" ticks all; stock items force purchase orders (the server enforces this too).
            var form = document.getElementById('sc-choose');
            if (!form) { return; }
            var boxes = Array.prototype.slice.call(form.querySelectorAll('.sc-group__box'));
            var all = document.getElementById('sc-everything');
            var note = document.getElementById('sc-forced-note');
            var byKey = {};
            boxes.forEach(function (b) { byKey[b.value] = b; });
            function sync() {
                var forced = '';
                boxes.forEach(function (b) {
                    var f = b.closest('.sc-group').querySelector('.sc-group__force');
                    if (b.checked && f) {
                        f.getAttribute('data-forces').split(',').forEach(function (k) {
                            if (byKey[k]) { byKey[k].checked = true; forced = f.textContent.replace(/\s+/g, ' ').trim(); }
                        });
                    }
                });
                if (note) { note.hidden = forced === ''; note.querySelector('span').textContent = forced; }
                all.checked = boxes.every(function (b) { return b.checked; });
            }
            boxes.forEach(function (b) { b.addEventListener('change', sync); });
            all.addEventListener('change', function () {
                boxes.forEach(function (b) { b.checked = all.checked; });
                sync();
            });
        })();
    </script>
</body>

</html>
