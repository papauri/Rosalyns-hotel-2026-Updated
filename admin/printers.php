<?php
/**
 * Printers (Restaurant & Bar) - receipt printers for kitchen / bar tickets and customer receipts.
 *
 * Per printer: name, how it is fed (Off / Cloud printer / Kitchen screen device), the station it serves, paper
 * (58 / 80 mm roll, A6 / A5 / A4 sheet), what it prints, copies. Cloud printers show the exact address to paste into
 * the printer. Shows the print queue with Reprint, and a Test print per printer. Logic: includes/printing.php.
 */
require_once 'admin-init.php';
require_once __DIR__ . '/../includes/alert.php';
require_once __DIR__ . '/../includes/printing.php';

/** @var PDO $pdo */
/** @var array $user */

if (!hasPermission((int)$user['id'], 'stock_management')) {
    rhDenyAndRedirectHome((int)$_SESSION['admin_user_id'], (string)($_SESSION['admin_role'] ?? ''), basename($_SERVER['PHP_SELF']));
    exit;
}

$message = '';
$error = '';
$ready = rh_print_ready($pdo);
$stations = rh_print_stations();
$papers = rh_print_paper_specs();
$site_name = getSetting('site_name', 'Hotel');

/* The address a cloud printer is given: always https. */
$baseUrl = trim((string)getSetting('site_url', '')); // the configured address, never the Host header
if ($baseUrl === '') {
    $baseUrl = function_exists('siteUrl') ? siteUrl('') : '';
}
if ($baseUrl === '') {
    $baseUrl = 'https://' . ($_SERVER['HTTP_HOST'] ?? 'localhost');
}
$baseUrl = preg_replace('#^http://#i', 'https://', rtrim($baseUrl, '/'));

/* ---- Preview (opens the same page the screen device prints) ---- */
if ($ready && isset($_GET['preview'])) {
    $st = $pdo->prepare('SELECT * FROM print_printers WHERE id = ?');
    $st->execute([(int)$_GET['preview']]);
    $pp = $st->fetch(PDO::FETCH_ASSOC);
    if ($pp) {
        $which = ($_GET['kind'] ?? 'ticket') === 'receipt' ? 'receipt' : 'ticket';
        $model = rh_print_model_test($which, (string)$site_name, (string)$pp['name'], rh_print_paper_spec($pp['paper'])['label'], rh_print_cols($pp));
        header('Content-Type: text/html; charset=utf-8');
        echo rh_print_encode_html(rh_print_rows_for($model, rh_print_cols($pp), 1, false), $pp, 'Preview - ' . $pp['name'], !empty($_GET['print']));
        exit;
    }
}

if ($ready && $_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!validateCsrfToken($_POST['csrf_token'] ?? '')) {
        $error = 'Security token invalid. Refresh and try again.';
    } else {
        $action = (string)($_POST['action'] ?? '');
        $id = (int)($_POST['printer_id'] ?? 0);
        try {
            if ($action === 'save') {
                [$f, $err] = rh_print_validate_printer($_POST);
                if ($err !== '') {
                    $error = $err;
                } elseif ($id > 0) {
                    $pdo->prepare('UPDATE print_printers SET name=?, mode=?, protocol=?, star_format=?, station=?, paper=?, cols=?, prints=?, copies=?, is_active=? WHERE id=?')
                        ->execute([$f['name'], $f['mode'], $f['protocol'], $f['star_format'], $f['station'], $f['paper'], $f['cols'], $f['prints'], $f['copies'], $f['is_active'], $id]);
                    rh_log_event('printers', 'info', 'Printer updated: ' . $f['name'], ['printer_id' => $id, 'mode' => $f['mode']]);
                    $message = 'Printer saved.';
                } else {
                    $pdo->prepare('INSERT INTO print_printers (name, mode, protocol, star_format, station, paper, cols, prints, copies, is_active, token) VALUES (?,?,?,?,?,?,?,?,?,?,?)')
                        ->execute([$f['name'], $f['mode'], $f['protocol'], $f['star_format'], $f['station'], $f['paper'], $f['cols'], $f['prints'], $f['copies'], $f['is_active'], rh_print_new_token()]);
                    rh_log_event('printers', 'info', 'Printer added: ' . $f['name'], ['mode' => $f['mode']]);
                    $message = 'Printer added.';
                }
            } elseif ($action === 'delete' && $id > 0) {
                $pdo->beginTransaction();
                $pdo->prepare('DELETE FROM print_job_items WHERE printer_id = ?')->execute([$id]);
                $pdo->prepare('DELETE FROM print_jobs WHERE printer_id = ?')->execute([$id]);
                $pdo->prepare('DELETE FROM print_printers WHERE id = ?')->execute([$id]);
                $pdo->commit();
                rh_log_event('printers', 'info', 'Printer deleted', ['printer_id' => $id]);
                $message = 'Printer deleted, with its waiting tickets.';
            } elseif ($action === 'regen_token' && $id > 0) {
                $pdo->prepare('UPDATE print_printers SET token = ? WHERE id = ?')->execute([rh_print_new_token(), $id]);
                rh_log_event('printers', 'info', 'Printer address renewed', ['printer_id' => $id]);
                $message = 'New address created. Paste it into the printer; the old one no longer works.';
            } elseif ($action === 'test' && $id > 0) {
                $st = $pdo->prepare('SELECT * FROM print_printers WHERE id = ?');
                $st->execute([$id]);
                $pp = $st->fetch(PDO::FETCH_ASSOC);
                if (!$pp) {
                    $error = 'Printer not found.';
                } elseif ($pp['mode'] === 'off' || !(int)$pp['is_active']) {
                    $error = 'This printer is switched off. Turn it on (and save) before a test print.';
                } else {
                    rh_print_enqueue_test($pdo, $pp);
                    $message = $pp['mode'] === 'cloud'
                        ? 'Test ticket and test receipt sent. The printer collects them within a few seconds.'
                        : 'Test ticket and test receipt queued. They print on the station screen that is set to print for this printer.';
                }
            } elseif ($action === 'reprint') {
                $jobId = (int)($_POST['job_id'] ?? 0);
                $st = $pdo->prepare('SELECT status FROM print_jobs WHERE id = ?');
                $st->execute([$jobId]);
                $js = $st->fetchColumn();
                if ($js === false) {
                    $error = 'That ticket no longer exists.';
                } elseif ($js === 'cancelled') {
                    $error = 'That order was cancelled, so its ticket is not printed.';
                } else {
                    rh_print_reprint_job($pdo, $jobId);
                    $message = 'Ticket sent to print again.';
                }
            }
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            error_log('printers.php: ' . $e->getMessage());
            $error = 'Could not save. Please try again.';
        }
    }
}

$printers = [];
$jobs = [];
if ($ready) {
    $printers = $pdo->query('SELECT * FROM print_printers ORDER BY id')->fetchAll(PDO::FETCH_ASSOC);
    $jobs = $pdo->query("SELECT j.*, p.name AS printer_name FROM print_jobs j JOIN print_printers p ON p.id = j.printer_id
        WHERE j.status <> 'printed' OR j.created_at >= DATE_SUB(NOW(), INTERVAL 2 DAY) ORDER BY j.id DESC LIMIT 40")->fetchAll(PDO::FETCH_ASSOC);
}
$csrf_token = generateCsrfToken();
$h = static function ($v): string {
    return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8');
};

/** One printer form (id 0 = the add form). */
$renderForm = static function (array $p) use ($stations, $papers, $csrf_token, $h, $baseUrl): void {
    $id = (int)($p['id'] ?? 0);
    $mode = (string)($p['mode'] ?? 'cloud');
    $uid = 'pf' . $id;
    ?>
    <form method="post" action="printers.php" class="pr-form" data-mode="<?php echo $h($mode); ?>">
        <input type="hidden" name="csrf_token" value="<?php echo $h($csrf_token); ?>">
        <input type="hidden" name="action" value="save">
        <input type="hidden" name="printer_id" value="<?php echo $id; ?>">
        <div class="pr-grid">
            <div class="pr-field">
                <label for="<?php echo $uid; ?>n">Name</label>
                <input type="text" id="<?php echo $uid; ?>n" name="name" maxlength="80" required value="<?php echo $h($p['name'] ?? ''); ?>" placeholder="Kitchen printer">
                <small>Only for you, so you can tell the printers apart.</small>
            </div>
            <div class="pr-field">
                <label for="<?php echo $uid; ?>m">How it prints</label>
                <select id="<?php echo $uid; ?>m" name="mode" onchange="this.form.setAttribute('data-mode',this.value)">
                    <option value="off" <?php echo $mode === 'off' ? 'selected' : ''; ?>>Off</option>
                    <option value="cloud" <?php echo $mode === 'cloud' ? 'selected' : ''; ?>>Cloud printer (no computer)</option>
                    <option value="screen" <?php echo $mode === 'screen' ? 'selected' : ''; ?>>Kitchen screen device (any printer)</option>
                </select>
                <small>Cloud printer needs a Star CloudPRNT or Epson Server Direct Print model. Kitchen screen device works with any printer the device can print to.</small>
            </div>
            <div class="pr-field pr-only-cloud">
                <label for="<?php echo $uid; ?>pr">Printer type</label>
                <select id="<?php echo $uid; ?>pr" name="protocol">
                    <option value="star" <?php echo ($p['protocol'] ?? 'star') === 'star' ? 'selected' : ''; ?>>Star (CloudPRNT)</option>
                    <option value="epson" <?php echo ($p['protocol'] ?? '') === 'epson' ? 'selected' : ''; ?>>Epson (Server Direct Print)</option>
                </select>
                <small>The make of the printer. This decides which address you paste into it.</small>
            </div>
            <div class="pr-field pr-only-cloud">
                <label for="<?php echo $uid; ?>sf">Star print style</label>
                <select id="<?php echo $uid; ?>sf" name="star_format">
                    <option value="markup" <?php echo ($p['star_format'] ?? 'markup') === 'markup' ? 'selected' : ''; ?>>Bold and large (recommended)</option>
                    <option value="text" <?php echo ($p['star_format'] ?? '') === 'text' ? 'selected' : ''; ?>>Plain text (older models)</option>
                </select>
                <small>Star printers only. If the test print comes out with odd symbols, choose Plain text.</small>
            </div>
            <div class="pr-field">
                <label for="<?php echo $uid; ?>s">Station it serves</label>
                <select id="<?php echo $uid; ?>s" name="station">
                    <?php foreach ($stations as $v => $l): ?>
                        <option value="<?php echo $h($v); ?>" <?php echo ($p['station'] ?? 'kitchen') === $v ? 'selected' : ''; ?>><?php echo $h($l); ?></option>
                    <?php endforeach; ?>
                </select>
                <small>Which order lines come out here. All stations prints every line. Cash desk prints receipts only.</small>
            </div>
            <div class="pr-field">
                <label for="<?php echo $uid; ?>w">Paper</label>
                <select id="<?php echo $uid; ?>w" name="paper">
                    <?php foreach ($papers as $v => $spec): ?>
                        <option value="<?php echo $h($v); ?>" <?php echo ($p['paper'] ?? '80') === (string)$v ? 'selected' : ''; ?>><?php echo $h($spec['label']); ?><?php echo $spec['kind'] === 'sheet' ? ' (screen device only)' : ''; ?></option>
                    <?php endforeach; ?>
                </select>
                <small>58 mm = 32 characters a line, 80 mm = 48. A6, A5 and A4 sheets print larger type on any ordinary printer.</small>
            </div>
            <div class="pr-field">
                <label for="<?php echo $uid; ?>pt">What it prints</label>
                <select id="<?php echo $uid; ?>pt" name="prints">
                    <option value="tickets" <?php echo ($p['prints'] ?? 'tickets') === 'tickets' ? 'selected' : ''; ?>>Kitchen / bar tickets</option>
                    <option value="receipts" <?php echo ($p['prints'] ?? '') === 'receipts' ? 'selected' : ''; ?>>Customer receipts</option>
                    <option value="both" <?php echo ($p['prints'] ?? '') === 'both' ? 'selected' : ''; ?>>Both</option>
                </select>
                <small>Customer receipts go to cloud printers. The till prints its own receipts in the browser, at the paper size of a receipt printer.</small>
            </div>
            <div class="pr-field">
                <label for="<?php echo $uid; ?>c">Copies</label>
                <input type="number" id="<?php echo $uid; ?>c" name="copies" min="1" max="5" value="<?php echo (int)($p['copies'] ?? 1); ?>">
                <small>How many of each ticket or receipt.</small>
            </div>
            <div class="pr-field">
                <label for="<?php echo $uid; ?>l">Characters per line</label>
                <input type="number" id="<?php echo $uid; ?>l" name="cols" min="24" max="64" value="<?php echo $h($p['cols'] ?? ''); ?>" placeholder="automatic">
                <small>Leave empty. Only change it if the test print runs off the edge or leaves a wide gap (some 80 mm printers fit 42).</small>
            </div>
            <div class="pr-field pr-check">
                <label><input type="checkbox" name="is_active" value="1" <?php echo (!isset($p['is_active']) || (int)$p['is_active']) ? 'checked' : ''; ?>> Active</label>
                <small>Untick to pause this printer without deleting it.</small>
            </div>
        </div>
        <?php if ($id > 0 && ($p['mode'] ?? '') === 'cloud'): ?>
            <?php $url = rh_print_endpoint_url($p, $baseUrl); ?>
            <div class="pr-url">
                <label for="<?php echo $uid; ?>u">Address to paste into the printer (<?php echo ($p['protocol'] ?? 'star') === 'epson' ? 'Server Direct Print URL' : 'CloudPRNT server URL'; ?>)</label>
                <div class="pr-url-row">
                    <input type="text" id="<?php echo $uid; ?>u" readonly value="<?php echo $h($url); ?>" onclick="this.select()">
                    <button type="button" class="btn btn-secondary" onclick="var i=document.getElementById('<?php echo $uid; ?>u');i.select();(navigator.clipboard?navigator.clipboard.writeText(i.value):Promise.reject()).catch(function(){document.execCommand('copy');});this.textContent='Copied';var b=this;setTimeout(function(){b.textContent='Copy';},1500);">Copy</button>
                </div>
                <small>Treat this address like a password: anyone who has it can read this printer's tickets. If it leaks, use New address under More.</small>
                <p class="pr-contact">
                    <?php if (!empty($p['last_seen_at'])): ?>
                        <span class="badge badge-active">Connected</span>
                        <span>Last contact from the printer: <?php echo $h($p['last_seen_at']); ?><?php echo !empty($p['last_status']) ? ' (' . $h($p['last_status']) . ')' : ''; ?></span>
                    <?php else: ?>
                        <span class="badge badge-pending">Not contacted yet</span>
                        <span>The printer has not contacted us yet.</span>
                    <?php endif; ?>
                </p>
            </div>
        <?php endif; ?>
        <div class="pr-actions">
            <button type="submit" class="btn btn-primary"><?php echo $id > 0 ? 'Save printer' : 'Add printer'; ?></button>
            <?php if ($id > 0): ?>
                <button type="submit" class="btn btn-secondary" name="action" value="test" formnovalidate>Test print</button>
                <div class="actions-more">
                    <button type="button" class="btn btn-secondary actions-more-toggle" title="More actions" aria-label="More actions" aria-haspopup="true" onclick="prToggleMore(this, event)"><i class="fas fa-ellipsis-vertical"></i> More</button>
                    <div class="actions-more-menu">
                        <a target="_blank" rel="noopener" href="printers.php?preview=<?php echo $id; ?>&kind=ticket"><i class="fas fa-eye"></i> Preview ticket</a>
                        <a target="_blank" rel="noopener" href="printers.php?preview=<?php echo $id; ?>&kind=receipt"><i class="fas fa-eye"></i> Preview receipt</a>
                        <?php if (($p['mode'] ?? '') === 'cloud'): ?>
                            <button type="submit" name="action" value="regen_token" formnovalidate onclick="return confirm('Create a new address? The printer must be given the new one.');"><i class="fas fa-key"></i> New address</button>
                        <?php endif; ?>
                        <hr class="menu-divider">
                        <button type="submit" class="text-danger" name="action" value="delete" formnovalidate onclick="return confirm('Delete this printer and its waiting tickets?');"><i class="fas fa-trash"></i> Delete</button>
                    </div>
                </div>
            <?php endif; ?>
        </div>
    </form>
    <?php
};
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Printers - <?php echo $h($site_name); ?></title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Cormorant+Garamond:ital,wght@0,300;0,400;0,500;0,600;1,300;1,400;1,500&family=Jost:wght@300;400;500;600&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="css/admin-styles.css?v=<?php echo @filemtime(__DIR__ . '/css/admin-styles.css'); ?>">
    <link rel="stylesheet" href="css/admin-components.css?v=<?php echo @filemtime(__DIR__ . '/css/admin-components.css'); ?>">
    <link rel="stylesheet" href="css/printers.css?v=<?php echo @filemtime(__DIR__ . '/css/printers.css'); ?>">
</head>
<body>
    <?php require_once 'includes/admin-header.php'; ?>
    <div class="content printers-page">
        <div class="page-header">
            <div>
                <h1 class="page-title"><i class="fas fa-print"></i> Printers</h1>
                <p class="page-subtitle">Print kitchen and bar tickets and customer receipts on receipt printers, even where there is no till.</p>
            </div>
        </div>

        <?php if ($message !== ''): ?><?php showAlert($message, 'success'); ?><?php endif; ?>
        <?php if ($error !== ''): ?><?php showAlert($error, 'error'); ?><?php endif; ?>

        <?php if (!$ready): ?>
            <div class="pr-notice"><strong>Printing needs an update first.</strong> Ask whoever looks after the system to apply update 076 (print queue), then come back to this page.</div>
        <?php else: ?>
            <section class="rh-panel settings-card pr-help">
                <div class="rh-panel__head"><h2 class="rh-panel__title">Which option should I pick?</h2></div>
                <div class="rh-panel__body">
                    <ul>
                        <li><strong>Cloud printer (no computer):</strong> needs a Star CloudPRNT or Epson Server Direct Print model on 58 mm or 80 mm rolls. The printer asks our server for tickets every few seconds over the internet. Paste the address shown below into the printer's own settings page.</li>
                        <li><strong>Kitchen screen device:</strong> works with any printer the device can print to (an office printer or a small thermal printer), on a roll or on A6, A5 or A4 paper. On the station screen that has the printer, tap <em>Printer</em> at the top and tick <em>This screen prints for...</em></li>
                        <li><strong>Silent printing</strong> (no pop-up window) needs Chrome started with <code>--kiosk-printing</code>. On Windows, add it to the end of the Chrome shortcut's Target.</li>
                        <li>Every ticket is printed once. A failed or old ticket stays in the print queue with <em>Reprint</em>. An order that is cancelled or voided before it prints never prints.</li>
                        <li><em>Test print</em> prints a sample ticket and receipt with a ruler line: if the whole ruler fits on the paper, the width is right.</li>
                    </ul>
                </div>
            </section>

            <?php foreach ($printers as $p): ?>
                <section class="rh-panel settings-card">
                    <div class="rh-panel__head">
                        <h2 class="rh-panel__title"><?php echo $h($p['name']); ?></h2>
                        <div class="rh-panel__actions">
                            <span class="badge <?php echo $p['mode'] === 'off' ? 'badge-disabled' : 'badge-active'; ?>"><?php echo $p['mode'] === 'cloud' ? 'Cloud printer' : ($p['mode'] === 'screen' ? 'Kitchen screen device' : 'Off'); ?></span>
                            <?php if (!(int)$p['is_active']): ?><span class="badge badge-pending">Paused</span><?php endif; ?>
                        </div>
                    </div>
                    <div class="rh-panel__body"><?php $renderForm($p); ?></div>
                </section>
            <?php endforeach; ?>

            <section class="rh-panel settings-card">
                <div class="rh-panel__head"><h2 class="rh-panel__title">Add a printer</h2></div>
                <div class="rh-panel__body"><?php $renderForm(['id' => 0, 'mode' => 'cloud']); ?></div>
            </section>

            <section class="rh-panel settings-card">
                <div class="rh-panel__head"><h2 class="rh-panel__title">Print queue</h2></div>
                <div class="rh-panel__body">
                    <p class="pr-note">The latest tickets and receipts. Waiting = not yet collected by the printer; Sent = handed to the printer, not yet confirmed.</p>
                    <?php if (!$jobs): ?>
                        <p class="pr-note">Nothing has been queued yet.</p>
                    <?php else: ?>
                        <?php $jobPill = ['queued' => 'badge-pending', 'sent' => 'badge-sent', 'printed' => 'badge-success', 'failed' => 'badge-failed', 'cancelled' => 'badge-cancelled']; ?>
                        <div class="pr-table-wrap">
                            <table class="pr-table">
                                <thead><tr>
                                    <th>When<span class="pr-th-help">When it was added to the queue</span></th>
                                    <th>Printer<span class="pr-th-help">Which printer it goes to</span></th>
                                    <th>What<span class="pr-th-help">The ticket or receipt it prints</span></th>
                                    <th>Status<span class="pr-th-help">Where it is now, and why if it failed</span></th>
                                    <th>Reprint<span class="pr-th-help">Send it to the printer again</span></th>
                                </tr></thead>
                                <tbody>
                                <?php foreach ($jobs as $j): ?>
                                    <tr>
                                        <td data-label="When"><?php echo $h($j['created_at']); ?></td>
                                        <td data-label="Printer"><?php echo $h($j['printer_name']); ?></td>
                                        <td data-label="What"><?php echo $h($j['title']); ?></td>
                                        <td data-label="Status">
                                            <span class="badge <?php echo $h($jobPill[$j['status']] ?? 'badge-neutral'); ?>"><?php echo $h(['queued' => 'Waiting', 'sent' => 'Sent', 'printed' => 'Printed', 'failed' => 'Failed', 'cancelled' => 'Cancelled'][$j['status']] ?? $j['status']); ?></span>
                                            <?php if (!empty($j['last_error'])): ?><small><?php echo $h($j['last_error']); ?></small><?php endif; ?>
                                        </td>
                                        <td data-label="Reprint">
                                            <?php if ($j['status'] !== 'cancelled'): ?>
                                                <form method="post" action="printers.php">
                                                    <input type="hidden" name="csrf_token" value="<?php echo $h($csrf_token); ?>">
                                                    <input type="hidden" name="action" value="reprint">
                                                    <input type="hidden" name="job_id" value="<?php echo (int)$j['id']; ?>">
                                                    <button type="submit" class="btn btn-secondary btn-sm">Reprint</button>
                                                </form>
                                            <?php endif; ?>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    <?php endif; ?>
                </div>
            </section>
        <?php endif; ?>
        <script>
        /* Shared "More" menu open/close (same pattern as Stock orders); placement by rhPlaceActionMenu. */
        (function () {
            function closeAll() {
                document.querySelectorAll('.printers-page .actions-more.open').forEach(function (el) {
                    el.classList.remove('open');
                    var m = el.querySelector('.actions-more-menu');
                    if (m) { m.removeAttribute('style'); }
                });
            }
            window.prToggleMore = function (btn, e) {
                if (e) { e.stopPropagation(); }
                var wrap = btn.closest('.actions-more');
                var menu = wrap ? wrap.querySelector('.actions-more-menu') : null;
                if (!menu) { return; }
                var wasOpen = wrap.classList.contains('open');
                closeAll();
                if (wasOpen) { return; }
                wrap.classList.add('open');
                if (window.rhPlaceActionMenu) { window.rhPlaceActionMenu(btn, menu); }
            };
            if (window.__prMoreBound) { return; }
            window.__prMoreBound = true;
            document.addEventListener('click', function (e) { if (!e.target.closest('.actions-more-toggle')) { closeAll(); } });
            document.addEventListener('keydown', function (e) { if (e.key === 'Escape') { closeAll(); } });
            document.addEventListener('scroll', function (e) { if (!(e.target && e.target.closest && e.target.closest('.actions-more-menu'))) { closeAll(); } }, true);
        })();
        </script>
    </div>
    <?php require_once 'includes/admin-footer.php'; ?>
</body>
</html>
