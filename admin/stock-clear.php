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

// Administrator role only, and the data_reset permission (owner 2026-11-11: super admin only; a plain administrator only
// when a super admin switched it on for them, or while no super admin exists).
[$authOk, $authWhy] = rh_sc_authorize($pdo, $user['id']);
if ($user['role'] !== 'admin' || !$authOk) {
    rhDenyAndRedirectHome($user['id'], $user['role'], basename($_SERVER['PHP_SELF']));
    exit;
}

$ROOT   = dirname(__DIR__);
$area   = 'stock';
$A      = rh_sc_area($area);
$allKeys = array_keys($A['groups']);

$message = '';
$error   = '';
$view    = 'form';      // form | confirm | result | undone
$preview = null;
$result  = null;
$undone  = null;
$picked  = [];

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
    } else {
        $error = 'That action is not recognised. Nothing was changed.';
    }
}

// What each group would remove right now (read-only), for the first screen.
$groupCounts = [];
$residueNow = ['alerts' => 0, 'review_cache' => 0, 'tool_cache' => 0];
if ($view === 'form' || $view === 'undone' || $view === 'result') {
    foreach ($A['groups'] as $k => $g) {
        $groupCounts[$k] = rh_sc_counts($pdo, $area, $g['tables']);
    }
}
$pastClears = rh_sc_list_clears($ROOT, $area);

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
            <p class="sc-lead">Empty stock data at this hotel so it can be set up again (for example before a fresh import). A backup is taken first, and every clear can be undone.</p>
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

        <?php else: ?>
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

            <!-- ================= PAST CLEARS ================= -->
            <div class="sc-card">
                <h3><i class="fas fa-clock-rotate-left"></i> Past clears</h3>
                <?php if (!$pastClears): ?>
                    <p class="sc-small">No stock has been cleared with this page yet.</p>
                <?php else: ?>
                    <p class="sc-small">Newest first. Undo puts back exactly what that clear removed, and only if none of those records exist again.</p>
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
                                            <?php echo $e(rh_sc_sentence($area, (array)($pc['tables'] ?? []))); ?><br>
                                            <span class="sc-small"><?php echo $e($pc['snapshot_file'] ?? ''); ?></span>
                                        </td>
                                        <td>
                                            <?php if (!empty($pc['undone_at'])): ?>
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
