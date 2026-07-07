<?php
/**
 * Station Hours Settings
 */
require_once 'admin-init.php';
require_once __DIR__ . '/../includes/alert.php';
require_once __DIR__ . '/../includes/station-hours.php';

/** @var PDO $pdo */
/** @var array $user */

if (!hasPermission((int)$user['id'], 'stock_management')) {
    header('Location: dashboard.php?error=access_denied');
    exit;
}

$message = '';
$error = '';
// Only manage hours for stations this preset actually runs (Kitchen/Bar/Coffee
// follow station_kds/bds/cds). Historical labels still resolve via the full map.
$stations = rh_enabled_station_definitions();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!validateCsrfToken($_POST['csrf_token'] ?? '')) {
        $error = 'Security token invalid. Refresh and try again.';
    } else {
        $postedStations = $_POST['stations'] ?? [];
        $updates = [];

        foreach ($stations as $stationKey => $definition) {
            $open = rh_station_normalize_time($postedStations[$stationKey]['opens_at'] ?? '', '');
            $close = rh_station_normalize_time($postedStations[$stationKey]['closes_at'] ?? '', '');

            if (!rh_station_is_valid_time($open) || !rh_station_is_valid_time($close)) {
                $error = 'Use 24-hour HH:MM format for every station.';
                break;
            }

            $updates[$stationKey] = ['opens_at' => $open, 'closes_at' => $close];
        }

        if ($error === '') {
            foreach ($updates as $stationKey => $hours) {
                updateSetting(rh_station_setting_key($stationKey, 'opens_at'), $hours['opens_at']);
                updateSetting(rh_station_setting_key($stationKey, 'closes_at'), $hours['closes_at']);
            }

            rh_log_event('station_settings', 'info', 'Station hours updated', ['stations' => $updates]);
            $message = 'Station hours updated.';
        }
    }
}

$stationCards = [];
foreach (array_keys($stations) as $stationKey) {
    $hours = rh_station_hours($stationKey);
    $current = rh_station_business_window($stationKey);
    $previous = rh_station_previous_business_window($stationKey, $current);
    $stationCards[$stationKey] = ['hours' => $hours, 'current' => $current, 'previous' => $previous];
}

$csrf_token = generateCsrfToken();
$site_name = getSetting('site_name', 'Hotel');
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Station Hours - <?php echo htmlspecialchars($site_name); ?></title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Cormorant+Garamond:ital,wght@0,300;0,400;0,500;0,600;1,300;1,400;1,500&family=Jost:wght@300;400;500;600&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="css/admin-styles.css?v=<?php echo @filemtime(__DIR__ . '/css/admin-styles.css'); ?>">
    <link rel="stylesheet" href="css/admin-components.css?v=<?php echo @filemtime(__DIR__ . '/css/admin-components.css'); ?>">
    <link rel="stylesheet" href="css/station-settings.css?v=<?php echo @filemtime(__DIR__ . '/css/station-settings.css'); ?>">
</head>
<body>
    <?php require_once 'includes/admin-header.php'; ?>
    <div class="content station-hours-page">
        <div class="station-hours-head">
            <div>
                <h1><i class="fas fa-clock"></i> Station Hours</h1>
                <p>Business-day windows for station tickets, summaries, and reports.</p>
            </div>
            <a href="kds-report.php" class="btn btn-secondary"><i class="fas fa-file-invoice"></i> Station Reports</a>
        </div>

        <?php if ($message !== ''): ?><?php showAlert($message, 'success'); ?><?php endif; ?>
        <?php if ($error !== ''): ?><?php showAlert($error, 'error'); ?><?php endif; ?>

        <form method="post" action="station-settings.php">
            <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrf_token); ?>">
            <div class="station-hours-grid">
                <?php foreach ($stationCards as $stationKey => $card): ?>
                    <?php $hours = $card['hours']; $current = $card['current']; $previous = $card['previous']; ?>
                    <section class="station-card">
                        <div class="station-card-head">
                            <span class="station-icon"><i class="fas <?php echo htmlspecialchars($hours['icon']); ?>"></i></span>
                            <div>
                                <h2><?php echo htmlspecialchars($hours['label']); ?></h2>
                                <div class="code"><?php echo htmlspecialchars($hours['short_label']); ?></div>
                            </div>
                        </div>
                        <div class="time-row">
                            <div>
                                <label for="<?php echo htmlspecialchars($stationKey); ?>_opens_at">Opens</label>
                                <input type="time" id="<?php echo htmlspecialchars($stationKey); ?>_opens_at" name="stations[<?php echo htmlspecialchars($stationKey); ?>][opens_at]" value="<?php echo htmlspecialchars($hours['opens_at']); ?>" required>
                            </div>
                            <div>
                                <label for="<?php echo htmlspecialchars($stationKey); ?>_closes_at">Closes</label>
                                <input type="time" id="<?php echo htmlspecialchars($stationKey); ?>_closes_at" name="stations[<?php echo htmlspecialchars($stationKey); ?>][closes_at]" value="<?php echo htmlspecialchars($hours['closes_at']); ?>" required>
                            </div>
                        </div>
                        <div class="station-window">
                            <span class="station-status <?php echo $current['is_open_now'] ? 'open' : 'closed'; ?>">
                                <i class="fas <?php echo $current['is_open_now'] ? 'fa-circle-check' : 'fa-circle-minus'; ?>"></i>
                                <?php echo $current['is_open_now'] ? 'Open now' : 'Closed now'; ?>
                            </span>
                            <div><strong>Current:</strong> <?php echo htmlspecialchars($current['window_label']); ?></div>
                            <div><strong>Yesterday:</strong> <?php echo htmlspecialchars($previous['window_label']); ?></div>
                            <?php if ($hours['crosses_midnight']): ?>
                                <div><strong>Close day:</strong> Next calendar day</div>
                            <?php endif; ?>
                        </div>
                    </section>
                <?php endforeach; ?>
            </div>
            <div class="actions-bar">
                <button type="submit" class="btn-station-save"><i class="fas fa-save"></i> Save Station Hours</button>
            </div>
        </form>
    </div>
    <?php require_once 'includes/admin-footer.php'; ?>
</body>
</html>

