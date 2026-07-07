<?php

/**
 * Gym Classes — schedule + enrolment.
 *
 * gym_classes drives the marketing schedule on the public gym page and the
 * admin schedule day-view. This page is where staff actually manage those
 * classes (add / edit / retire), enrol members into each one, see the roster,
 * and email a reminder to everyone enrolled.
 *
 * View: 'gym'. Editing classes + enrolment: 'gym_packages' (same gate the
 * schedule editor uses). Backed by gym_classes + gym_class_enrollments; both
 * tables are created lazily by gymClassesEnsureTables().
 */
require_once 'admin-init.php';
require_once '../includes/alert.php';
require_once __DIR__ . '/includes/gym-classes-lib.php';

/** @var PDO $pdo */
/** @var array $user */
/** @var string $csrf_token */

if (!hasPermission((int)$user['id'], 'gym')) {
    header('Location: dashboard.php?error=access_denied');
    exit;
}
$gc_can_edit = hasPermission((int)$user['id'], 'gym_packages');
$adminId = (int)($user['id'] ?? 0);

$gc_json = static function (bool $ok, string $msg, array $extra = []): void {
    header('Content-Type: application/json');
    echo json_encode(array_merge(['success' => $ok, 'message' => $msg], $extra));
    exit;
};

// ── POST actions ─────────────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['gc_action'])) {
    if (!validateCsrfToken($_POST['csrf_token'] ?? '')) {
        $gc_json(false, 'Security token invalid — refresh the page.');
    }
    $action = (string)$_POST['gc_action'];

    // Roster is read-only → allowed for any gym viewer.
    if ($action === 'roster') {
        $classId = (int)($_POST['class_id'] ?? 0);
        $class = gymClassGet($pdo, $classId);
        if (!$class) { $gc_json(false, 'Class not found.'); }
        $gc_json(true, '', ['class' => $class, 'roster' => gymClassRoster($pdo, $classId)]);
    }

    // Everything below mutates → requires the edit permission.
    if (!$gc_can_edit) {
        $gc_json(false, 'You do not have permission to manage classes.');
    }

    if ($action === 'class_save') {
        $res = gymClassSave($pdo, [
            'title'         => $_POST['title'] ?? '',
            'description'   => $_POST['description'] ?? '',
            'day_label'     => $_POST['day_label'] ?? '',
            'time_label'    => $_POST['time_label'] ?? '',
            'level_label'   => $_POST['level_label'] ?? '',
            'display_order' => $_POST['display_order'] ?? 0,
            'is_active'     => $_POST['is_active'] ?? 0,
        ], (int)($_POST['id'] ?? 0));
        $gc_json((bool)$res['success'], $res['message']);
    }

    if ($action === 'class_delete') {
        $res = gymClassDelete($pdo, (int)($_POST['id'] ?? 0));
        $gc_json((bool)$res['success'], $res['message']);
    }

    if ($action === 'enrol') {
        $res = gymClassEnrolMember($pdo, (int)($_POST['class_id'] ?? 0), (int)($_POST['member_id'] ?? 0), $adminId);
        $roster = gymClassRoster($pdo, (int)($_POST['class_id'] ?? 0));
        $gc_json((bool)$res['success'], $res['message'], ['roster' => $roster]);
    }

    if ($action === 'remove_enrollment') {
        $res = gymClassRemoveMember($pdo, (int)($_POST['enrollment_id'] ?? 0));
        $roster = gymClassRoster($pdo, (int)($_POST['class_id'] ?? 0));
        $gc_json((bool)$res['success'], $res['message'], ['roster' => $roster]);
    }

    if ($action === 'send_reminders') {
        $res = gymClassSendReminders($pdo, (int)($_POST['class_id'] ?? 0), $adminId);
        $gc_json((bool)$res['success'], $res['message']);
    }

    $gc_json(false, 'Unknown action.');
}

// ── Page data ────────────────────────────────────────────────────────────────
$gc_tables_ok = gymClassesEnsureTables($pdo);
$gc_classes = $gc_tables_ok ? gymClassesList($pdo) : [];
$gc_total_enrolled = 0;
foreach ($gc_classes as $c) { $gc_total_enrolled += (int)$c['enrolled_count']; }

// Active members for the enrol picker (kept small — the roster modal filters
// out those already enrolled client-side).
$gc_active_members = [];
try {
    $gc_active_members = $pdo->query("SELECT id, member_number, full_name, email FROM gym_members WHERE status = 'active' ORDER BY full_name ASC LIMIT 1000")->fetchAll(PDO::FETCH_ASSOC);
} catch (Throwable $e) { /* register optional */ }
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Gym Classes - Admin Panel</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Cormorant+Garamond:ital,wght@0,400;0,500;0,600&family=Jost:wght@300;400;500;600&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="css/admin-styles.css?v=<?php echo @filemtime(__DIR__ . '/css/admin-styles.css'); ?>">
    <link rel="stylesheet" href="css/admin-components.css?v=<?php echo @filemtime(__DIR__ . '/css/admin-components.css'); ?>">
    <link rel="stylesheet" href="css/menu-management.css?v=<?php echo @filemtime(__DIR__ . '/css/menu-management.css'); ?>">
    <style>
        .gcl-grid{display:grid;grid-template-columns:repeat(auto-fill,minmax(300px,1fr));gap:16px;margin-top:6px}
        .gcl-card{background:#fff;border:1px solid #d5cfc4;border-radius:6px;padding:16px 18px;display:flex;flex-direction:column;gap:10px}
        .gcl-card.inactive{opacity:.62}
        .gcl-card__head{display:flex;align-items:flex-start;justify-content:space-between;gap:10px}
        .gcl-card__title{font-family:'Cormorant Garamond',serif;font-size:1.35rem;font-weight:600;color:#3e3930;line-height:1.2;margin:0}
        .gcl-card__desc{font-size:.86rem;color:#6f665b;line-height:1.5;margin:0}
        .gcl-meta{display:flex;flex-wrap:wrap;gap:8px 14px;font-size:.82rem;color:#5f574c}
        .gcl-meta i{color:#8B7355;margin-right:5px}
        .gcl-enrolled{display:inline-flex;align-items:center;gap:7px;background:#f4f1ea;border:1px solid #e0d9cc;border-radius:999px;padding:5px 12px;font-size:.82rem;font-weight:600;color:#6b5f4d;cursor:pointer;white-space:nowrap}
        .gcl-enrolled:hover{background:#ece6da}
        .gcl-actions{display:flex;flex-wrap:wrap;gap:8px;margin-top:2px}
        .gcl-empty{text-align:center;padding:48px 20px;color:#9a8f82}
        .gcl-empty i{font-size:40px;opacity:.35;display:block;margin-bottom:14px;color:#8B7355}
        .gcl-roster-item{display:flex;align-items:center;gap:12px;padding:10px 12px;border:1px solid #eee4d6;border-radius:6px;margin-bottom:8px;background:#fdfcfa}
        .gcl-roster-item .gr-avatar{width:34px;height:34px;border-radius:50%;background:#f0ebe2;color:#8B7355;display:flex;align-items:center;justify-content:center;font-size:13px;flex-shrink:0}
        .gcl-roster-item .gr-name{flex:1;font-weight:600;font-size:.92rem;color:#3e3930;min-width:0}
        .gcl-roster-item .gr-sub{font-size:.76rem;color:#9a8f82;font-weight:400;margin-top:2px}
        .gcl-roster-item .gr-noemail{color:#b45309;font-size:.72rem}
        .gcl-add-row{display:flex;gap:8px;margin-bottom:14px;flex-wrap:wrap}
        .gcl-add-row select{flex:1;min-width:180px;padding:9px;border:1px solid #d3cbc0;border-radius:4px;font-family:inherit}
    </style>
</head>

<body>
    <?php require_once 'includes/admin-header.php'; ?>

    <div class="content">
        <div class="page-header">
            <h2 class="page-title">Gym Classes</h2>
            <div style="display:flex;gap:10px;align-items:center;flex-wrap:wrap;">
                <span class="cat-count" title="Active classes"><i class="fas fa-dumbbell"></i> <?php echo count($gc_classes); ?> classes</span>
                <span class="cat-count" title="Total active enrolments"><i class="fas fa-users"></i> <?php echo $gc_total_enrolled; ?> enrolled</span>
                <?php if ($gc_can_edit): ?>
                <button class="btn-add" onclick="gcOpenClassModal()"><i class="fas fa-plus"></i> Add Class</button>
                <?php endif; ?>
            </div>
        </div>

        <p style="color:#6f665b;font-size:.88rem;margin:-6px 0 18px;max-width:70ch;">
            These classes appear on the public gym schedule and the check-in day-view. Enrol members into a class and send them a reminder email before it runs.
        </p>

        <?php if (empty($gc_classes)): ?>
            <div class="gcl-empty">
                <i class="fas fa-calendar-day"></i>
                <p>No classes yet.<?php echo $gc_can_edit ? ' Click <strong>Add Class</strong> to create the first one.' : ''; ?></p>
            </div>
        <?php else: ?>
            <div class="gcl-grid">
                <?php foreach ($gc_classes as $c): ?>
                    <div class="gcl-card<?php echo (int)$c['is_active'] ? '' : ' inactive'; ?>">
                        <div class="gcl-card__head">
                            <h3 class="gcl-card__title"><?php echo htmlspecialchars($c['title']); ?></h3>
                            <?php if (!(int)$c['is_active']): ?><span class="badge badge-inactive">Hidden</span><?php endif; ?>
                        </div>
                        <?php if (!empty($c['description'])): ?>
                            <p class="gcl-card__desc"><?php echo htmlspecialchars($c['description']); ?></p>
                        <?php endif; ?>
                        <div class="gcl-meta">
                            <span><i class="fas fa-calendar-week"></i><?php echo htmlspecialchars($c['day_label']); ?></span>
                            <span><i class="fas fa-clock"></i><?php echo htmlspecialchars($c['time_label']); ?></span>
                            <?php if (!empty($c['level_label'])): ?><span><i class="fas fa-signal"></i><?php echo htmlspecialchars($c['level_label']); ?></span><?php endif; ?>
                        </div>
                        <div>
                            <span class="gcl-enrolled" onclick='gcOpenRoster(<?php echo (int)$c['id']; ?>, <?php echo json_encode($c['title']); ?>)' title="View &amp; manage the roster">
                                <i class="fas fa-users"></i> <span data-enrolled-count="<?php echo (int)$c['id']; ?>"><?php echo (int)$c['enrolled_count']; ?></span> enrolled
                            </span>
                        </div>
                        <div class="gcl-actions">
                            <button class="mm-btn mm-btn-sm" onclick='gcOpenRoster(<?php echo (int)$c['id']; ?>, <?php echo json_encode($c['title']); ?>)'><i class="fas fa-user-group"></i> Roster</button>
                            <?php if ($gc_can_edit): ?>
                            <button class="mm-btn mm-btn-sm" style="background:#8B7355;color:#fff;" onclick='gcConfirm("Send a reminder email to everyone enrolled in &quot;" + <?php echo json_encode(htmlspecialchars($c['title'], ENT_QUOTES)); ?> + "&quot;?", function(){ gcSendReminders(<?php echo (int)$c['id']; ?>); })' title="Email all enrolled members"><i class="fas fa-bell"></i> Remind</button>
                            <button class="mm-btn mm-btn-sm mm-btn-ghost" onclick='gcOpenClassModal(<?php echo htmlspecialchars(json_encode($c), ENT_QUOTES); ?>)'><i class="fas fa-pen"></i> Edit</button>
                            <button class="mm-btn mm-btn-sm mm-btn-ghost" style="color:#c0392b;" onclick='gcConfirm("Delete class &quot;" + <?php echo json_encode(htmlspecialchars($c['title'], ENT_QUOTES)); ?> + "&quot;? Enrolments are removed too. This cannot be undone.", function(){ gcDeleteClass(<?php echo (int)$c['id']; ?>); })'><i class="fas fa-trash"></i></button>
                            <?php endif; ?>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
    </div>

    <!-- Class add/edit modal -->
    <div class="mm-modal" id="gcClassModal">
        <div class="mm-modal-card sm">
            <div class="mm-modal-head">
                <h3 id="gcClassModalTitle">Add Class</h3>
                <button type="button" class="mm-modal-close" onclick="gcClose('gcClassModal')" aria-label="Close">&times;</button>
            </div>
            <div class="mm-modal-body">
                <input type="hidden" id="gcClassId" value="0">
                <label style="display:block;font-weight:600;margin-bottom:4px;">Class title</label>
                <input type="text" id="gcTitle" maxlength="150" placeholder="e.g. HIIT Bootcamp" style="width:100%;padding:9px;border:1px solid #d3cbc0;border-radius:4px;margin-bottom:12px;">
                <label style="display:block;font-weight:600;margin-bottom:4px;">Description <span style="font-weight:400;color:#9a8f82;">(optional)</span></label>
                <textarea id="gcDesc" rows="2" maxlength="500" placeholder="Short blurb shown to members" style="width:100%;padding:9px;border:1px solid #d3cbc0;border-radius:4px;margin-bottom:12px;"></textarea>
                <div style="display:flex;gap:12px;margin-bottom:12px;">
                    <div style="flex:1;">
                        <label style="display:block;font-weight:600;margin-bottom:4px;">Days</label>
                        <input type="text" id="gcDay" maxlength="120" placeholder="e.g. Tuesday &amp; Thursday" style="width:100%;padding:9px;border:1px solid #d3cbc0;border-radius:4px;">
                    </div>
                    <div style="flex:1;">
                        <label style="display:block;font-weight:600;margin-bottom:4px;">Time</label>
                        <input type="text" id="gcTime" maxlength="50" placeholder="e.g. 7:00 AM" style="width:100%;padding:9px;border:1px solid #d3cbc0;border-radius:4px;">
                    </div>
                </div>
                <div style="display:flex;gap:12px;margin-bottom:12px;">
                    <div style="flex:1;">
                        <label style="display:block;font-weight:600;margin-bottom:4px;">Level</label>
                        <input type="text" id="gcLevel" maxlength="80" placeholder="All Levels" list="gcLevelPresets" style="width:100%;padding:9px;border:1px solid #d3cbc0;border-radius:4px;">
                        <datalist id="gcLevelPresets"><option value="All Levels"><option value="Beginner"><option value="Intermediate"><option value="Advanced"></datalist>
                    </div>
                    <div style="width:110px;">
                        <label style="display:block;font-weight:600;margin-bottom:4px;">Order</label>
                        <input type="number" id="gcOrder" min="0" step="1" value="0" style="width:100%;padding:9px;border:1px solid #d3cbc0;border-radius:4px;">
                    </div>
                </div>
                <label style="display:flex;align-items:center;gap:8px;cursor:pointer;font-weight:600;">
                    <input type="checkbox" id="gcActive" checked> <span>Active — show on the public schedule</span>
                </label>
            </div>
            <div class="mm-modal-foot" style="display:flex;justify-content:flex-end;gap:10px;padding:14px 18px;">
                <button class="mm-btn mm-btn-ghost" onclick="gcClose('gcClassModal')">Cancel</button>
                <button class="mm-btn mm-btn-primary" onclick="gcSaveClass()">Save Class</button>
            </div>
        </div>
    </div>

    <!-- Roster modal -->
    <div class="mm-modal" id="gcRosterModal">
        <div class="mm-modal-card sm">
            <div class="mm-modal-head">
                <h3 id="gcRosterTitle">Roster</h3>
                <button type="button" class="mm-modal-close" onclick="gcClose('gcRosterModal')" aria-label="Close">&times;</button>
            </div>
            <div class="mm-modal-body" style="max-height:64vh;overflow-y:auto;">
                <?php if ($gc_can_edit): ?>
                <div class="gcl-add-row">
                    <select id="gcMemberPicker"><option value="0">— Add an active member —</option></select>
                    <button class="mm-btn mm-btn-primary" onclick="gcEnrol()"><i class="fas fa-user-plus"></i> Enrol</button>
                </div>
                <?php endif; ?>
                <div id="gcRosterBody"><p style="color:#9a8f82;">Loading…</p></div>
            </div>
            <div class="mm-modal-foot" style="display:flex;justify-content:space-between;gap:10px;padding:14px 18px;align-items:center;">
                <?php if ($gc_can_edit): ?>
                <button class="mm-btn" style="background:#8B7355;color:#fff;" id="gcRosterRemindBtn" onclick="gcRemindFromRoster()"><i class="fas fa-bell"></i> Send reminder to all</button>
                <?php else: ?><span></span><?php endif; ?>
                <button class="mm-btn mm-btn-ghost" onclick="gcClose('gcRosterModal')">Close</button>
            </div>
        </div>
    </div>

    <!-- Confirm modal -->
    <div class="mm-modal" id="gcConfirmModal">
        <div class="mm-modal-card sm">
            <div class="mm-modal-head">
                <h3><i class="fas fa-circle-question" style="color:#8B7355;"></i> Please confirm</h3>
                <button type="button" class="mm-modal-close" onclick="gcClose('gcConfirmModal')" aria-label="Close">&times;</button>
            </div>
            <div class="mm-modal-body"><p id="gcConfirmText" style="margin:0;"></p></div>
            <div class="mm-modal-foot" style="display:flex;justify-content:flex-end;gap:10px;padding:14px 18px;">
                <button class="mm-btn mm-btn-ghost" onclick="gcClose('gcConfirmModal')">Cancel</button>
                <button class="mm-btn mm-btn-primary" id="gcConfirmYes">Yes, continue</button>
            </div>
        </div>
    </div>

    <script>
        var GC_CSRF = <?php echo json_encode($csrf_token); ?>;
        var GC_CAN_EDIT = <?php echo $gc_can_edit ? 'true' : 'false'; ?>;
        var GC_MEMBERS = <?php echo json_encode(array_map(static function ($m) {
            return ['id' => (int)$m['id'], 'member_number' => (string)$m['member_number'], 'full_name' => (string)$m['full_name'], 'email' => (string)($m['email'] ?? '')];
        }, $gc_active_members)); ?>;
        var gcCurrentClassId = 0;
        var gcCurrentRoster = [];

        function gcOpen(id) { document.getElementById(id).classList.add('open'); }
        function gcClose(id) { document.getElementById(id).classList.remove('open'); }
        function gcToast(msg, ok) { if (typeof Alert !== 'undefined' && Alert.show) { Alert.show(msg, ok ? 'success' : 'error'); } else { alert(msg); } }
        function gcEsc(s) { var d = document.createElement('div'); d.textContent = String(s == null ? '' : s); return d.innerHTML; }

        function gcPost(fields) {
            var fd = new FormData();
            fd.append('csrf_token', GC_CSRF);
            Object.keys(fields).forEach(function (k) { fd.append(k, fields[k] == null ? '' : fields[k]); });
            return fetch(window.location.pathname, { method: 'POST', body: fd, credentials: 'same-origin', headers: { 'X-Requested-With': 'XMLHttpRequest' } })
                .then(function (r) { return r.json(); });
        }

        // ── Confirm ───────────────────────────────────────────────────────────
        function gcConfirm(text, onYes) {
            document.getElementById('gcConfirmText').textContent = text;
            var yes = document.getElementById('gcConfirmYes');
            var clone = yes.cloneNode(true); yes.parentNode.replaceChild(clone, yes);
            clone.addEventListener('click', function () { gcClose('gcConfirmModal'); onYes(); });
            gcOpen('gcConfirmModal');
        }

        // ── Class add / edit ──────────────────────────────────────────────────
        function gcOpenClassModal(c) {
            document.getElementById('gcClassModalTitle').textContent = c ? 'Edit Class' : 'Add Class';
            document.getElementById('gcClassId').value = c ? c.id : 0;
            document.getElementById('gcTitle').value = c ? (c.title || '') : '';
            document.getElementById('gcDesc').value = c ? (c.description || '') : '';
            document.getElementById('gcDay').value = c ? (c.day_label || '') : '';
            document.getElementById('gcTime').value = c ? (c.time_label || '') : '';
            document.getElementById('gcLevel').value = c ? (c.level_label || 'All Levels') : 'All Levels';
            document.getElementById('gcOrder').value = c ? (c.display_order || 0) : 0;
            document.getElementById('gcActive').checked = c ? (parseInt(c.is_active, 10) === 1) : true;
            gcOpen('gcClassModal');
        }
        function gcSaveClass() {
            gcPost({
                gc_action: 'class_save',
                id: document.getElementById('gcClassId').value,
                title: document.getElementById('gcTitle').value,
                description: document.getElementById('gcDesc').value,
                day_label: document.getElementById('gcDay').value,
                time_label: document.getElementById('gcTime').value,
                level_label: document.getElementById('gcLevel').value,
                display_order: document.getElementById('gcOrder').value,
                is_active: document.getElementById('gcActive').checked ? 1 : 0
            }).then(function (d) {
                gcToast(d.message, d.success);
                if (d.success) { setTimeout(function () { location.reload(); }, 650); }
            });
        }
        function gcDeleteClass(id) {
            gcPost({ gc_action: 'class_delete', id: id }).then(function (d) {
                gcToast(d.message, d.success);
                if (d.success) { setTimeout(function () { location.reload(); }, 650); }
            });
        }

        // ── Roster ────────────────────────────────────────────────────────────
        function gcOpenRoster(classId, title) {
            gcCurrentClassId = classId;
            document.getElementById('gcRosterTitle').textContent = 'Roster · ' + title;
            document.getElementById('gcRosterBody').innerHTML = '<p style="color:#9a8f82;">Loading…</p>';
            gcOpen('gcRosterModal');
            gcPost({ gc_action: 'roster', class_id: classId }).then(function (d) {
                if (!d.success) { document.getElementById('gcRosterBody').innerHTML = '<p style="color:#c0392b;">' + gcEsc(d.message) + '</p>'; return; }
                gcRenderRoster(d.roster || []);
            });
        }
        function gcRenderRoster(roster) {
            gcCurrentRoster = roster;
            var body = document.getElementById('gcRosterBody');
            if (!roster.length) {
                body.innerHTML = '<div style="text-align:center;padding:26px 12px;color:#9a8f82;"><i class="fas fa-user-slash" style="font-size:26px;opacity:.4;display:block;margin-bottom:10px;"></i>No members enrolled yet.</div>';
            } else {
                body.innerHTML = roster.map(function (r) {
                    var noEmail = !r.email;
                    var expired = r.member_status !== 'active';
                    return '<div class="gcl-roster-item">' +
                        '<div class="gr-avatar"><i class="fas fa-user"></i></div>' +
                        '<div class="gr-name" style="overflow:hidden;text-overflow:ellipsis;">' + gcEsc(r.full_name) +
                        '<div class="gr-sub">' + gcEsc(r.member_number) + (r.membership_type ? ' · ' + gcEsc(r.membership_type) : '') +
                        (noEmail ? ' · <span class="gr-noemail">no email</span>' : '') +
                        (expired ? ' · <span class="gr-noemail">' + gcEsc(r.member_status) + '</span>' : '') + '</div></div>' +
                        (GC_CAN_EDIT ? '<button class="btn-action btn-delete" title="Remove from class" onclick="gcRemove(' + parseInt(r.enrollment_id, 10) + ')"><i class="fas fa-user-minus"></i></button>' : '') +
                        '</div>';
                }).join('');
            }
            // refresh the card's enrolled count
            var badge = document.querySelector('[data-enrolled-count="' + gcCurrentClassId + '"]');
            if (badge) { badge.textContent = roster.length; }
            gcRefreshPicker();
        }
        function gcRefreshPicker() {
            var sel = document.getElementById('gcMemberPicker');
            if (!sel) { return; }
            var enrolledIds = {};
            gcCurrentRoster.forEach(function (r) { enrolledIds[r.member_id] = true; });
            var opts = ['<option value="0">— Add an active member —</option>'];
            GC_MEMBERS.forEach(function (m) {
                if (enrolledIds[m.id]) { return; }
                opts.push('<option value="' + m.id + '">' + gcEsc(m.full_name) + ' (' + gcEsc(m.member_number) + ')' + (m.email ? '' : ' — no email') + '</option>');
            });
            sel.innerHTML = opts.join('');
        }
        function gcEnrol() {
            var sel = document.getElementById('gcMemberPicker');
            var memberId = parseInt(sel.value, 10);
            if (!memberId) { gcToast('Pick a member to enrol.', false); return; }
            gcPost({ gc_action: 'enrol', class_id: gcCurrentClassId, member_id: memberId }).then(function (d) {
                gcToast(d.message, d.success);
                if (d.roster) { gcRenderRoster(d.roster); }
            });
        }
        function gcRemove(enrollmentId) {
            gcPost({ gc_action: 'remove_enrollment', enrollment_id: enrollmentId, class_id: gcCurrentClassId }).then(function (d) {
                gcToast(d.message, d.success);
                if (d.roster) { gcRenderRoster(d.roster); }
            });
        }
        function gcRemindFromRoster() {
            var title = document.getElementById('gcRosterTitle').textContent.replace('Roster · ', '');
            gcConfirm('Send a reminder email to everyone enrolled in "' + title + '"?', function () { gcSendReminders(gcCurrentClassId); });
        }

        // ── Reminders ─────────────────────────────────────────────────────────
        function gcSendReminders(classId) {
            gcToast('Sending reminders…', true);
            gcPost({ gc_action: 'send_reminders', class_id: classId }).then(function (d) {
                gcToast(d.message, d.success);
            });
        }
    </script>
    <?php require_once 'includes/admin-footer.php'; ?>
</body>
</html>
