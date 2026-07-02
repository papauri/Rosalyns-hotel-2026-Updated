<?php

/**
 * Gym Members — enrolled membership register.
 *
 * The operational heart of the Gym/Fitness preset: who is enrolled, on what
 * package, and when their membership lapses. Distinct from gym-inquiries.php
 * (sales leads); an inquiry that converts becomes a row here.
 *
 * Backed by the gym_members table (migration
 * admin/migrations/2026_07_03_create_gym_members.sql) — until that runs,
 * the page shows a "pending migration" notice instead of failing.
 */
require_once 'admin-init.php';
require_once '../includes/alert.php';

/** @var PDO $pdo */
/** @var array $user */
/** @var string $csrf_token */

$isAjax = !empty($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest';

$gm_json = static function (bool $ok, string $msg): void {
    header('Content-Type: application/json');
    echo json_encode(['success' => $ok, 'message' => $msg]);
    exit;
};

$gm_statuses = ['active', 'expired', 'suspended', 'cancelled'];

// ── POST actions ─────────────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['gm_action'])) {
    if (!validateCsrfToken($_POST['csrf_token'] ?? '')) {
        $gm_json(false, 'Security token invalid — refresh the page.');
    }
    $action = (string)$_POST['gm_action'];

    try {
        if ($action === 'member_save') {
            $memberId = (int)($_POST['id'] ?? 0);
            $name = trim((string)($_POST['full_name'] ?? ''));
            $email = trim((string)($_POST['email'] ?? ''));
            $phone = trim((string)($_POST['phone'] ?? ''));
            $type = trim((string)($_POST['membership_type'] ?? ''));
            $start = trim((string)($_POST['start_date'] ?? ''));
            $expiry = trim((string)($_POST['expiry_date'] ?? ''));
            $fee = $_POST['monthly_fee'] !== '' ? (float)($_POST['monthly_fee'] ?? 0) : null;
            $status = in_array($_POST['status'] ?? '', $gm_statuses, true) ? (string)$_POST['status'] : 'active';
            $notes = trim((string)($_POST['notes'] ?? ''));

            if ($name === '' || mb_strlen($name) > 255) {
                $gm_json(false, 'Member name is required (max 255 characters).');
            }
            if ($email !== '' && !filter_var($email, FILTER_VALIDATE_EMAIL)) {
                $gm_json(false, 'Enter a valid email address or leave it empty.');
            }
            $startDt = DateTime::createFromFormat('Y-m-d', $start);
            if (!$startDt) {
                $gm_json(false, 'A valid start date is required.');
            }
            if ($expiry !== '' && !DateTime::createFromFormat('Y-m-d', $expiry)) {
                $gm_json(false, 'Expiry date must be a valid date or left empty.');
            }
            if ($fee !== null && ($fee < 0 || $fee > 99999999)) {
                $gm_json(false, 'Monthly fee must be zero or a positive amount.');
            }

            if ($memberId > 0) {
                $stmt = $pdo->prepare("UPDATE gym_members SET full_name=?, email=?, phone=?, membership_type=?, start_date=?, expiry_date=?, monthly_fee=?, status=?, notes=? WHERE id=?");
                $stmt->execute([$name, $email ?: null, $phone ?: null, $type ?: null, $start, $expiry ?: null, $fee, $status, $notes ?: null, $memberId]);
                $gm_json(true, 'Member updated.');
            }
            do {
                $memberNumber = 'GM-' . strtoupper(substr(uniqid(), -6));
                $chk = $pdo->prepare("SELECT COUNT(*) FROM gym_members WHERE member_number = ?");
                $chk->execute([$memberNumber]);
            } while ((int)$chk->fetchColumn() > 0);
            $stmt = $pdo->prepare("INSERT INTO gym_members (member_number, full_name, email, phone, membership_type, start_date, expiry_date, monthly_fee, status, notes, created_by) VALUES (?,?,?,?,?,?,?,?,?,?,?)");
            $stmt->execute([$memberNumber, $name, $email ?: null, $phone ?: null, $type ?: null, $start, $expiry ?: null, $fee, $status, $notes ?: null, (int)($user['id'] ?? 0)]);
            $gm_json(true, 'Member enrolled — ' . $memberNumber . '.');
        }

        if ($action === 'member_status') {
            $memberId = (int)($_POST['id'] ?? 0);
            $status = in_array($_POST['status'] ?? '', $gm_statuses, true) ? (string)$_POST['status'] : '';
            if ($status === '') {
                $gm_json(false, 'Invalid status.');
            }
            $pdo->prepare("UPDATE gym_members SET status=? WHERE id=?")->execute([$status, $memberId]);
            $gm_json(true, 'Member marked ' . $status . '.');
        }

        if ($action === 'member_delete') {
            $memberId = (int)($_POST['id'] ?? 0);
            $pdo->prepare("DELETE FROM gym_members WHERE id=?")->execute([$memberId]);
            $gm_json(true, 'Member deleted.');
        }

        $gm_json(false, 'Unknown action.');
    } catch (PDOException $e) {
        error_log('gym-members: ' . $e->getMessage());
        $gm_json(false, 'Database error — has the gym_members migration been run?');
    }
}

// ── Data ─────────────────────────────────────────────────────────────────────
$gm_table_missing = false;
$gm_members = [];
$gm_counts = ['active' => 0, 'expiring' => 0, 'expired' => 0, 'all' => 0];
$gm_filter = (string)($_GET['filter'] ?? 'all');
try {
    $gm_counts['all']      = (int)$pdo->query("SELECT COUNT(*) FROM gym_members")->fetchColumn();
    $gm_counts['active']   = (int)$pdo->query("SELECT COUNT(*) FROM gym_members WHERE status='active'")->fetchColumn();
    $gm_counts['expiring'] = (int)$pdo->query("SELECT COUNT(*) FROM gym_members WHERE status='active' AND expiry_date IS NOT NULL AND expiry_date BETWEEN CURDATE() AND DATE_ADD(CURDATE(), INTERVAL 30 DAY)")->fetchColumn();
    $gm_counts['expired']  = (int)$pdo->query("SELECT COUNT(*) FROM gym_members WHERE status='expired' OR (expiry_date IS NOT NULL AND expiry_date < CURDATE())")->fetchColumn();

    $where = '1=1';
    if ($gm_filter === 'active')   { $where = "status='active'"; }
    if ($gm_filter === 'expiring') { $where = "status='active' AND expiry_date IS NOT NULL AND expiry_date BETWEEN CURDATE() AND DATE_ADD(CURDATE(), INTERVAL 30 DAY)"; }
    if ($gm_filter === 'expired')  { $where = "(status='expired' OR (expiry_date IS NOT NULL AND expiry_date < CURDATE()))"; }
    $gm_members = $pdo->query("SELECT * FROM gym_members WHERE $where ORDER BY status='active' DESC, expiry_date IS NULL ASC, expiry_date ASC, full_name ASC LIMIT 500")->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    $gm_table_missing = true;
}

// Package names for the membership-type datalist (best effort)
$gm_packages = [];
try {
    $gm_packages = $pdo->query("SELECT name FROM gym_packages WHERE is_active=1 ORDER BY display_order ASC, name ASC")->fetchAll(PDO::FETCH_COLUMN);
} catch (PDOException $e) { /* optional */ }

$gm_currency = (string)getSetting('currency_symbol', 'K');
?>
<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <script>
        (function() {
            var _t = '<?= htmlspecialchars($csrf_token, ENT_QUOTES) ?>';
            var _f = window.fetch;
            window.fetch = function(u, o) {
                if (o && o.body instanceof FormData && !o.body.has('csrf_token')) o.body.append('csrf_token', _t);
                return _f.apply(this, arguments);
            };
        })();
    </script>
    <title>Gym Members - Admin Panel</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Cormorant+Garamond:ital,wght@0,300;0,400;0,500;0,600;1,300;1,400;1,500&family=Jost:wght@300;400;500;600&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="css/admin-styles.css">
    <link rel="stylesheet" href="css/admin-components.css">
    <link rel="stylesheet" href="css/menu-management.css">
</head>

<body>
    <?php require_once 'includes/admin-header.php'; ?>

    <div class="content">
        <div class="page-header">
            <h2 class="page-title">Gym Members</h2>
            <?php if (!$gm_table_missing): ?>
            <button class="btn-add" onclick="gmOpenModal()">
                <i class="fas fa-user-plus"></i> Enrol Member
            </button>
            <?php endif; ?>
        </div>

        <?php if ($gm_table_missing): ?>
            <?php showAlert('The membership register table (gym_members) has not been created yet — run the migration in admin/migrations/2026_07_03_create_gym_members.sql, then reload this page.', 'error'); ?>
        <?php else: ?>

        <div class="menu-type-tabs" style="margin-bottom:18px;">
            <?php foreach (['all' => 'All', 'active' => 'Active', 'expiring' => 'Expiring ≤30d', 'expired' => 'Expired'] as $fk => $fl): ?>
                <a class="menu-type-tab <?php echo $gm_filter === $fk ? 'active' : ''; ?>" href="?filter=<?php echo $fk; ?>" style="text-decoration:none;">
                    <?php echo $fl; ?> <span class="cat-count" style="margin-left:6px;"><?php echo (int)$gm_counts[$fk]; ?></span>
                </a>
            <?php endforeach; ?>
        </div>

        <?php if (empty($gm_members)): ?>
            <div class="empty-state">
                <i class="fas fa-id-card"></i>
                <p><?php echo $gm_filter === 'all' ? 'No members enrolled yet. Enrol your first member to start the register.' : 'No members match this filter.'; ?></p>
            </div>
        <?php else: ?>
            <table class="menu-table">
                <thead>
                    <tr>
                        <th style="width:110px;">Member #</th>
                        <th>Name</th>
                        <th>Contact</th>
                        <th>Package</th>
                        <th style="width:100px;">Started</th>
                        <th style="width:100px;">Expires</th>
                        <th style="width:110px;">Fee (<?php echo htmlspecialchars($gm_currency); ?>/mo)</th>
                        <th style="width:100px;">Status</th>
                        <th style="width:130px;">Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($gm_members as $m):
                        $expSoon = $m['status'] === 'active' && $m['expiry_date'] && strtotime($m['expiry_date']) <= strtotime('+30 days');
                        $statusColor = ['active' => '#2e7d32', 'expired' => '#9e4040', 'suspended' => '#B18247', 'cancelled' => '#6c757d'][$m['status']] ?? '#6c757d';
                    ?>
                        <tr>
                            <td><strong><?php echo htmlspecialchars($m['member_number']); ?></strong></td>
                            <td><?php echo htmlspecialchars($m['full_name']); ?></td>
                            <td style="font-size:.85rem;color:#7a6f63;">
                                <?php echo htmlspecialchars($m['email'] ?? ''); ?><?php echo ($m['email'] && $m['phone']) ? '<br>' : ''; ?><?php echo htmlspecialchars($m['phone'] ?? ''); ?>
                            </td>
                            <td><?php echo htmlspecialchars($m['membership_type'] ?? '—'); ?></td>
                            <td><?php echo htmlspecialchars(date('M j, Y', strtotime($m['start_date']))); ?></td>
                            <td style="<?php echo $expSoon ? 'color:#c0392b;font-weight:600;' : ''; ?>">
                                <?php echo $m['expiry_date'] ? htmlspecialchars(date('M j, Y', strtotime($m['expiry_date']))) : '—'; ?>
                            </td>
                            <td><?php echo $m['monthly_fee'] !== null ? number_format((float)$m['monthly_fee'], 2) : '—'; ?></td>
                            <td><span style="font-weight:600;color:<?php echo $statusColor; ?>;"><?php echo ucfirst($m['status']); ?></span></td>
                            <td class="actions-cell">
                                <div class="action-buttons">
                                    <button class="btn-action" title="Edit" onclick="gmOpenModal(<?php echo htmlspecialchars(json_encode($m), ENT_QUOTES); ?>)"><i class="fas fa-pen"></i></button>
                                    <?php if ($m['status'] === 'active'): ?>
                                        <button class="btn-action" title="Suspend" onclick="gmStatus(<?php echo (int)$m['id']; ?>, 'suspended')"><i class="fas fa-pause"></i></button>
                                    <?php else: ?>
                                        <button class="btn-action btn-toggle active" title="Reactivate" onclick="gmStatus(<?php echo (int)$m['id']; ?>, 'active')"><i class="fas fa-play"></i></button>
                                    <?php endif; ?>
                                    <button class="btn-action btn-delete" title="Delete"
                                        onclick="gmConfirm('Delete member &quot;<?php echo htmlspecialchars($m['full_name'], ENT_QUOTES); ?>&quot;? This cannot be undone.', function(){ gmDelete(<?php echo (int)$m['id']; ?>); })"><i class="fas fa-trash"></i></button>
                                </div>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        <?php endif; ?>
        <?php endif; ?>
    </div>

    <!-- Member modal -->
    <div class="mm-modal" id="gmModal">
        <div class="mm-modal-card sm">
            <div class="mm-modal-head">
                <h3 id="gmModalTitle">Enrol Member</h3>
                <button type="button" class="mm-modal-close" onclick="gmClose('gmModal')" aria-label="Close">&times;</button>
            </div>
            <div class="mm-modal-body">
                <input type="hidden" id="gmId" value="0">
                <label style="display:block;font-weight:600;margin-bottom:4px;">Full name</label>
                <input type="text" id="gmName" maxlength="255" style="width:100%;padding:9px;border:1px solid #d3cbc0;border-radius:4px;margin-bottom:12px;">
                <div style="display:flex;gap:12px;margin-bottom:12px;">
                    <div style="flex:1;">
                        <label style="display:block;font-weight:600;margin-bottom:4px;">Email <span style="font-weight:400;color:#9a8f82;">(optional)</span></label>
                        <input type="email" id="gmEmail" maxlength="255" style="width:100%;padding:9px;border:1px solid #d3cbc0;border-radius:4px;">
                    </div>
                    <div style="flex:1;">
                        <label style="display:block;font-weight:600;margin-bottom:4px;">Phone <span style="font-weight:400;color:#9a8f82;">(optional)</span></label>
                        <input type="text" id="gmPhone" maxlength="50" style="width:100%;padding:9px;border:1px solid #d3cbc0;border-radius:4px;">
                    </div>
                </div>
                <label style="display:block;font-weight:600;margin-bottom:4px;">Membership package</label>
                <input type="text" id="gmType" maxlength="100" list="gmPackages" style="width:100%;padding:9px;border:1px solid #d3cbc0;border-radius:4px;margin-bottom:12px;" placeholder="e.g. Monthly Unlimited">
                <datalist id="gmPackages">
                    <?php foreach ($gm_packages as $p): ?><option value="<?php echo htmlspecialchars($p); ?>"></option><?php endforeach; ?>
                </datalist>
                <div style="display:flex;gap:12px;margin-bottom:12px;">
                    <div style="flex:1;">
                        <label style="display:block;font-weight:600;margin-bottom:4px;">Start date</label>
                        <input type="date" id="gmStart" style="width:100%;padding:9px;border:1px solid #d3cbc0;border-radius:4px;">
                    </div>
                    <div style="flex:1;">
                        <label style="display:block;font-weight:600;margin-bottom:4px;">Expiry <span style="font-weight:400;color:#9a8f82;">(optional)</span></label>
                        <input type="date" id="gmExpiry" style="width:100%;padding:9px;border:1px solid #d3cbc0;border-radius:4px;">
                    </div>
                </div>
                <div style="display:flex;gap:12px;margin-bottom:12px;">
                    <div style="flex:1;">
                        <label style="display:block;font-weight:600;margin-bottom:4px;">Monthly fee (<?php echo htmlspecialchars($gm_currency); ?>)</label>
                        <input type="number" id="gmFee" min="0" step="0.01" style="width:100%;padding:9px;border:1px solid #d3cbc0;border-radius:4px;">
                    </div>
                    <div style="flex:1;">
                        <label style="display:block;font-weight:600;margin-bottom:4px;">Status</label>
                        <select id="gmStatus" style="width:100%;padding:9px;border:1px solid #d3cbc0;border-radius:4px;">
                            <?php foreach ($gm_statuses as $s): ?><option value="<?php echo $s; ?>"><?php echo ucfirst($s); ?></option><?php endforeach; ?>
                        </select>
                    </div>
                </div>
                <label style="display:block;font-weight:600;margin-bottom:4px;">Notes <span style="font-weight:400;color:#9a8f82;">(optional)</span></label>
                <textarea id="gmNotes" rows="2" style="width:100%;padding:9px;border:1px solid #d3cbc0;border-radius:4px;"></textarea>
            </div>
            <div class="mm-modal-foot" style="display:flex;justify-content:flex-end;gap:10px;padding:14px 18px;">
                <button class="mm-btn mm-btn-ghost" onclick="gmClose('gmModal')">Cancel</button>
                <button class="mm-btn mm-btn-primary" onclick="gmSave()">Save Member</button>
            </div>
        </div>
    </div>

    <!-- Confirm modal -->
    <div class="mm-modal" id="gmConfirmModal">
        <div class="mm-modal-card sm">
            <div class="mm-modal-head">
                <h3><i class="fas fa-triangle-exclamation" style="color:#f59e0b;"></i> Are you sure?</h3>
                <button type="button" class="mm-modal-close" onclick="gmClose('gmConfirmModal')" aria-label="Close">&times;</button>
            </div>
            <div class="mm-modal-body"><p id="gmConfirmText" style="margin:0;"></p></div>
            <div class="mm-modal-foot" style="display:flex;justify-content:flex-end;gap:10px;padding:14px 18px;">
                <button class="mm-btn mm-btn-ghost" onclick="gmClose('gmConfirmModal')">Cancel</button>
                <button class="mm-btn mm-btn-primary" id="gmConfirmYes" style="background:#c0392b;border-color:#c0392b;">Yes, continue</button>
            </div>
        </div>
    </div>

    <script>
        function gmOpen(id) { document.getElementById(id).classList.add('open'); }
        function gmClose(id) { document.getElementById(id).classList.remove('open'); }
        function gmToast(msg, ok) { if (typeof Alert !== 'undefined' && Alert.show) { Alert.show(msg, ok ? 'success' : 'error'); } }

        function gmPost(fields) {
            var fd = new FormData();
            Object.keys(fields).forEach(function (k) { fd.append(k, fields[k] == null ? '' : fields[k]); });
            return fetch(window.location.pathname + window.location.search, { method: 'POST', body: fd, credentials: 'same-origin', headers: { 'X-Requested-With': 'XMLHttpRequest' } })
                .then(function (r) { return r.json(); })
                .then(function (d) {
                    gmToast(d.message || (d.success ? 'Saved.' : 'Failed.'), !!d.success);
                    if (d.success) { setTimeout(function () { window.location.reload(); }, 700); }
                    return d;
                })
                .catch(function () { gmToast('Network error — please try again.', false); });
        }

        function gmOpenModal(m) {
            document.getElementById('gmModalTitle').textContent = m ? 'Edit Member' : 'Enrol Member';
            document.getElementById('gmId').value = m ? m.id : 0;
            document.getElementById('gmName').value = m ? (m.full_name || '') : '';
            document.getElementById('gmEmail').value = m ? (m.email || '') : '';
            document.getElementById('gmPhone').value = m ? (m.phone || '') : '';
            document.getElementById('gmType').value = m ? (m.membership_type || '') : '';
            document.getElementById('gmStart').value = m ? (m.start_date || '') : new Date().toISOString().slice(0, 10);
            document.getElementById('gmExpiry').value = m ? (m.expiry_date || '') : '';
            document.getElementById('gmFee').value = m && m.monthly_fee != null ? m.monthly_fee : '';
            document.getElementById('gmStatus').value = m ? (m.status || 'active') : 'active';
            document.getElementById('gmNotes').value = m ? (m.notes || '') : '';
            gmOpen('gmModal');
            setTimeout(function () { document.getElementById('gmName').focus(); }, 60);
        }

        function gmSave() {
            var name = document.getElementById('gmName').value.trim();
            var start = document.getElementById('gmStart').value;
            if (!name) { gmToast('Member name is required.', false); return; }
            if (!start) { gmToast('Start date is required.', false); return; }
            gmPost({
                gm_action: 'member_save',
                id: document.getElementById('gmId').value,
                full_name: name,
                email: document.getElementById('gmEmail').value.trim(),
                phone: document.getElementById('gmPhone').value.trim(),
                membership_type: document.getElementById('gmType').value.trim(),
                start_date: start,
                expiry_date: document.getElementById('gmExpiry').value,
                monthly_fee: document.getElementById('gmFee').value,
                status: document.getElementById('gmStatus').value,
                notes: document.getElementById('gmNotes').value.trim()
            });
        }

        function gmStatus(id, status) { gmPost({ gm_action: 'member_status', id: id, status: status }); }
        function gmDelete(id) { gmPost({ gm_action: 'member_delete', id: id }); }

        var gmConfirmCb = null;
        function gmConfirm(text, cb) {
            document.getElementById('gmConfirmText').textContent = text;
            gmConfirmCb = cb;
            gmOpen('gmConfirmModal');
        }
        document.getElementById('gmConfirmYes').addEventListener('click', function () {
            gmClose('gmConfirmModal');
            if (gmConfirmCb) { gmConfirmCb(); gmConfirmCb = null; }
        });
        ['gmModal', 'gmConfirmModal'].forEach(function (id) {
            var el = document.getElementById(id);
            el.addEventListener('click', function (e) { if (e.target === el) { gmClose(id); } });
        });
    </script>

    <?php require_once 'includes/admin-footer.php'; ?>
</body>

</html>
