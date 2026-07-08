<?php
/**
 * Room Management Dashboard
 * Comprehensive room status and housekeeping overview
 */
require_once 'admin-init.php';
// Bootstrap fallback guards (admin-init.php sets these; guards satisfy static analysis)
$user       = $user       ?? ['id' => 0, 'username' => '', 'role' => 'guest', 'full_name' => ''];
$csrf_token = $csrf_token ?? generateCsrfToken();
$site_name  = $site_name  ?? getSetting('site_name', 'Hotel');

require_once '../includes/room-management.php';

$message = '';
$error = '';

// Handle quick actions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!validateCsrfToken($_POST['csrf_token'] ?? '')) {
        if (!empty($_SERVER['HTTP_X_REQUESTED_WITH'])) { header('Content-Type: application/json'); echo json_encode(['success' => false, 'message' => 'Security token invalid.']); exit; }
        header('Location: ' . basename($_SERVER['PHP_SELF'])); exit;
    }
    $action = $_POST['action'] ?? '';

    try {
        switch ($action) {
            case 'mark_clean':
                $room_id = (int)$_POST['room_id'];
                $result = markRoomClean($room_id, $user['id'], ['notes' => $_POST['notes'] ?? '']);
                if ($result['success']) {
                    $message = $result['message'];
                } else {
                    $error = $result['message'];
                }
                break;

            case 'pass_inspection':
                $room_id = (int)$_POST['room_id'];
                $result = passRoomInspection($room_id, $user['id'], ['notes' => $_POST['notes'] ?? '']);
                if ($result['success']) {
                    $message = $result['message'];
                } else {
                    $error = $result['message'];
                }
                break;

            case 'fail_inspection':
                $room_id = (int)$_POST['room_id'];
                $result = failRoomInspection($room_id, $user['id'], $_POST['reason'] ?? 'Failed inspection');
                if ($result['success']) {
                    $message = $result['message'];
                } else {
                    $error = $result['message'];
                }
                break;

            case 'update_status':
                $room_id = (int)$_POST['room_id'];
                $new_status = $_POST['new_status'];
                $result = updateRoomStatus($room_id, $new_status, $_POST['reason'] ?? 'Manual update', $user['id']);
                if ($result['success']) {
                    $message = $result['message'];
                } else {
                    $error = $result['message'];
                }
                break;
        }
    } catch (Exception $e) {
        $error = 'Error: ' . $e->getMessage();
    }
}

// Get dashboard data
$summary = getRoomDashboardSummary();
$cleaningQueue = getRoomsRequiringHousekeeping('cleaning');
$inspectionQueue = getRoomsRequiringInspection();
$allRooms = getRoomsRequiringHousekeeping('all');

// Get room statuses for display
$roomStatuses = getRoomStatuses();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="csrf-token" content="<?= htmlspecialchars($csrf_token, ENT_QUOTES) ?>">
    <script>(function(){var _t='<?= htmlspecialchars($csrf_token,ENT_QUOTES)?>';var _f=window.fetch;window.fetch=function(u,o){if(o&&o.body instanceof FormData&&!o.body.has('csrf_token'))o.body.append('csrf_token',_t);return _f.apply(this,arguments);};})();</script>
    <title>Room Management Dashboard - <?php echo htmlspecialchars(getSetting('site_name')); ?></title>

    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Cormorant+Garamond:ital,wght@0,300;0,400;0,500;0,600;1,300;1,400;1,500&family=Jost:wght@300;400;500;600&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="css/admin-styles.css?v=<?php echo @filemtime(__DIR__ . '/css/admin-styles.css'); ?>">
    <link rel="stylesheet" href="css/admin-components.css?v=<?php echo @filemtime(__DIR__ . '/css/admin-components.css'); ?>">
    <link rel="stylesheet" href="css/room-dashboard.css?v=<?php echo @filemtime(__DIR__ . '/css/room-dashboard.css'); ?>">
</head>
<body>
<?php require_once 'includes/admin-header.php'; ?>

<div class="room-dashboard">
    <div class="dashboard-header">
        <h1><i class="fas fa-door-open"></i> Room Management Dashboard</h1>
        <p>Real-time room status and housekeeping management</p>
    </div>

    <?php if ($message): ?>
    <div class="alert alert-success" style="background: #d4edda; color: #155724; padding: 12px 20px; border-radius: 8px; margin-bottom: 20px;">
        <i class="fas fa-check-circle"></i> <?php echo htmlspecialchars($message); ?>
    </div>
    <?php endif; ?>

    <?php if ($error): ?>
    <div class="alert alert-danger" style="background: #f8d7da; color: #721c24; padding: 12px 20px; border-radius: 8px; margin-bottom: 20px;">
        <i class="fas fa-exclamation-circle"></i> <?php echo htmlspecialchars($error); ?>
    </div>
    <?php endif; ?>

    <?php if (($summary['no_show_candidates'] ?? 0) > 0): ?>
    <div class="alert alert-warning" style="background: #fff3cd; color: #856404; padding: 12px 20px; border-radius: 8px; margin-bottom: 20px; display:flex; justify-content:space-between; align-items:center; flex-wrap:wrap; gap:10px;">
        <span>
            <i class="fas fa-user-clock"></i>
            <strong><?php echo (int)$summary['no_show_candidates']; ?></strong>
            confirmed booking<?php echo (int)$summary['no_show_candidates'] === 1 ? '' : 's'; ?>
            passed the arrival date without checking in — review and mark no-show to free the room(s).
        </span>
        <a href="bookings.php?arrival=overdue" style="background:#856404;color:#fff;padding:6px 14px;border-radius:4px;text-decoration:none;font-size:.85rem;white-space:nowrap;">Review arrivals</a>
    </div>
    <?php endif; ?>

    <!-- Today's Stats -->
    <div class="today-stats">
        <div class="today-stat">
            <div class="value"><?php echo $summary['checkouts_today'] ?? 0; ?></div>
            <div class="label"><i class="fas fa-sign-out-alt"></i> Check-outs Today</div>
        </div>
        <div class="today-stat">
            <div class="value"><?php echo $summary['checkins_today'] ?? 0; ?></div>
            <div class="label"><i class="fas fa-sign-in-alt"></i> Check-ins Today</div>
        </div>
        <div class="today-stat">
            <div class="value"><?php echo $summary['cleaning_queue'] ?? 0; ?></div>
            <div class="label"><i class="fas fa-broom"></i> Rooms to Clean</div>
        </div>
        <div class="today-stat">
            <div class="value"><?php echo $summary['available_now'] ?? 0; ?></div>
            <div class="label"><i class="fas fa-check-circle"></i> Available Now</div>
        </div>
    </div>

    <!-- Room Status Overview -->
    <div class="status-overview">
        <?php foreach ($roomStatuses as $status => $info): ?>
        <?php $count = $summary['status_counts'][$status] ?? 0; ?>
        <div class="status-card <?php echo $status; ?>">
            <div class="icon">
                <i class="fas <?php echo $info['icon']; ?>"></i>
            </div>
            <div class="info">
                <h3><?php echo $count; ?></h3>
                <span><?php echo $info['label']; ?></span>
            </div>
        </div>
        <?php endforeach; ?>
    </div>

    <!-- Occupancy Bar -->
    <div class="occupancy-section">
        <h3 style="margin: 0 0 16px 0; font-family: 'Cormorant Garamond', serif; font-size: 20px;">
            Room Occupancy: <?php echo $summary['occupancy_rate'] ?? 0; ?>%
        </h3>
        <div class="occupancy-bar">
            <?php
            $total = array_sum($summary['status_counts'] ?? []);
            if ($total > 0):
                $colors = [
                    'occupied' => '#dc3545',
                    'available' => '#28a745',
                    'cleaning' => '#ffc107',
                    'inspection' => '#17a2b8',
                    'maintenance' => '#fd7e14',
                    'out_of_order' => '#6c757d'
                ];
                foreach ($summary['status_counts'] ?? [] as $status => $count):
                    $percent = ($count / $total) * 100;
            ?>
            <div class="occupancy-segment" style="width: <?php echo $percent; ?>%; background: <?php echo $colors[$status] ?? '#ccc'; ?>;"></div>
            <?php
                endforeach;
            endif;
            ?>
        </div>
        <div class="occupancy-legend">
            <?php foreach ($roomStatuses as $status => $info): ?>
            <div class="legend-item">
                <span class="legend-dot" style="background: <?php echo $info['color']; ?>;"></span>
                <span><?php echo $info['label']; ?>: <?php echo $summary['status_counts'][$status] ?? 0; ?></span>
            </div>
            <?php endforeach; ?>
        </div>
    </div>

    <!-- Cleaning Queue -->
    <div class="queue-section">
        <div class="queue-header">
            <h2>
                <i class="fas fa-broom" style="color: var(--gold);"></i>
                Cleaning Queue
                <span class="badge"><?php echo count($cleaningQueue); ?></span>
            </h2>
        </div>
        <div class="queue-body">
            <?php if (empty($cleaningQueue)): ?>
            <div class="empty-queue">
                <i class="fas fa-check-circle"></i>
                <p>All rooms are clean!</p>
            </div>
            <?php else: ?>
            <?php foreach ($cleaningQueue as $room): ?>
            <div class="room-queue-item">
                <div class="priority-indicator <?php echo $room['priority'] ?? 'normal'; ?>"></div>
                <div class="room-number-badge">
                    <div class="number"><?php echo htmlspecialchars($room['room_number']); ?></div>
                    <div class="type"><?php echo htmlspecialchars($room['room_type'] ?? ''); ?></div>
                </div>
                <div class="room-info">
                    <div class="guest">
                        <?php if ($room['last_guest']): ?>
                            Last: <?php echo htmlspecialchars($room['last_guest']); ?>
                        <?php else: ?>
                            No recent guest
                        <?php endif; ?>
                    </div>
                    <div class="details">
                        <?php if ($room['last_checkout']): ?>
                            Checked out: <?php echo date('M j, g:i A', strtotime($room['last_checkout'])); ?>
                        <?php endif; ?>
                        <?php if ($room['hk_notes']): ?>
                            <br><em><?php echo htmlspecialchars($room['hk_notes']); ?></em>
                        <?php endif; ?>
                    </div>
                </div>
                <?php if ($room['hk_status']): ?>
                <span class="badge badge-<?php echo $room['hk_status']; ?>">
                    <?php echo ucfirst(str_replace('_', ' ', $room['hk_status'])); ?>
                </span>
                <?php else: ?>
                <span class="badge badge-cleaning">Needs Cleaning</span>
                <?php endif; ?>
                <?php if ($room['assigned_to_name']): ?>
                <div style="font-size: 12px; color: #666;">
                    <i class="fas fa-user"></i> <?php echo htmlspecialchars($room['assigned_to_name']); ?>
                </div>
                <?php endif; ?>
                <div class="room-actions">
                    <form method="POST" style="display: inline;">
                        <input type="hidden" name="action" value="mark_clean">
                        <input type="hidden" name="room_id" value="<?php echo $room['id']; ?>">
                        <button type="submit" class="btn-success" onclick="return confirm('Mark this room as clean?')">
                            <i class="fas fa-check"></i> Mark Clean
                        </button>
                    </form>
                </div>
            </div>
            <?php endforeach; ?>
            <?php endif; ?>
        </div>
    </div>

    <!-- Inspection Queue -->
    <div class="queue-section">
        <div class="queue-header">
            <h2>
                <i class="fas fa-clipboard-check" style="color: #17a2b8;"></i>
                Inspection Queue
                <span class="badge" style="background: #17a2b8;"><?php echo count($inspectionQueue); ?></span>
            </h2>
        </div>
        <div class="queue-body">
            <?php if (empty($inspectionQueue)): ?>
            <div class="empty-queue">
                <i class="fas fa-clipboard-check"></i>
                <p>No rooms awaiting inspection</p>
            </div>
            <?php else: ?>
            <?php foreach ($inspectionQueue as $room): ?>
            <div class="room-queue-item">
                <div class="room-number-badge">
                    <div class="number"><?php echo htmlspecialchars($room['room_number']); ?></div>
                    <div class="type"><?php echo htmlspecialchars($room['room_type'] ?? ''); ?></div>
                </div>
                <div class="room-info">
                    <div class="guest">Awaiting Inspection</div>
                    <div class="details">
                        <?php if ($room['cleaning_completed']): ?>
                            Cleaned: <?php echo date('M j, g:i A', strtotime($room['cleaning_completed'])); ?>
                        <?php endif; ?>
                    </div>
                </div>
                <span class="badge badge-inspection">Inspection Pending</span>
                <div class="room-actions">
                    <form method="POST" style="display: inline;" onsubmit="return confirmPass(this)">
                        <input type="hidden" name="action" value="pass_inspection">
                        <input type="hidden" name="room_id" value="<?php echo $room['id']; ?>">
                        <input type="hidden" name="notes" value="">
                        <button type="submit" class="btn-success">
                            <i class="fas fa-check"></i> Pass
                        </button>
                    </form>
                    <button type="button" class="btn-danger" onclick="openFailModal(<?php echo $room['id']; ?>, '<?php echo htmlspecialchars($room['room_number']); ?>')">
                        <i class="fas fa-times"></i> Fail
                    </button>
                </div>
            </div>
            <?php endforeach; ?>
            <?php endif; ?>
        </div>
    </div>
</div>

<!-- Fail Inspection Modal -->
<div class="modal-overlay" id="failModal">
    <div class="modal-content">
        <div class="modal-header">
            <h3><i class="fas fa-times-circle" style="color: #dc3545;"></i> Fail Inspection</h3>
            <button type="button" onclick="closeFailModal()" style="background:none; border:none; font-size:20px; cursor:pointer; color:#6c757d; line-height:1; padding:0 4px;" aria-label="Close">&times;</button>
        </div>
        <form method="POST">
            <input type="hidden" name="action" value="fail_inspection">
            <input type="hidden" name="room_id" id="failRoomId">
            <p>Room: <strong id="failRoomNumber"></strong></p>
            <div class="form-group">
                <label>Reason for Failure *</label>
                <textarea name="reason" rows="3" required placeholder="e.g., Bathroom not properly cleaned, stains on carpet..."></textarea>
            </div>
            <div class="modal-actions">
                <button type="button" class="btn-cancel" onclick="closeFailModal()">Cancel</button>
                <button type="submit" class="btn-submit" style="background: #dc3545;">Fail & Send to Reclean</button>
            </div>
        </form>
    </div>
</div>

<script>
function openFailModal(roomId, roomNumber) {
    document.getElementById('failRoomId').value = roomId;
    document.getElementById('failRoomNumber').textContent = roomNumber;
    document.getElementById('failModal').classList.add('active');
}

function closeFailModal() {
    document.getElementById('failModal').classList.remove('active');
}

function confirmPass(form) {
    return confirm('Pass inspection and make this room available?');
}

// Close modal on outside click
document.getElementById('failModal').addEventListener('click', function(e) {
    if (e.target === this) {
        closeFailModal();
    }
});
</script>

<?php require_once 'includes/admin-footer.php'; ?>
</body>
</html>

