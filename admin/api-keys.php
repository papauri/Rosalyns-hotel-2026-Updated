<?php

/**
 * Admin Interface for Managing API Keys
 */

require_once 'admin-init.php';

$csrf_token = $csrf_token ?? generateCsrfToken();

if (($_SESSION['admin_role'] ?? '') !== 'admin') {
    header('Location: dashboard.php');
    exit;
}

if (function_exists('ensureApiKeyRetrievableColumn')) {
    ensureApiKeyRetrievableColumn($pdo);
}

$message = '';
$messageType = 'success';

$availablePermissions = [
    'rooms.read' => 'Read room names, descriptions, capacity and prices',
    'availability.check' => 'Check if rooms are available for selected dates',
    'bookings.create' => 'Create new bookings from an approved integration',
    'bookings.read' => 'Read booking details/status by booking ID or reference',
    'bookings.update' => 'Update booking details from an approved integration',
    'bookings.delete' => 'Cancel/delete bookings from an approved integration',
];

function buildMetaRoomPriceTemplate(PDO $pdo): string
{
    $siteName = getSetting('site_name', 'Hotel');
    $currency = getSetting('currency_symbol', 'EUR');
    $siteUrl = trim((string)getSetting('site_url', ''));
    $bookingUrl = $siteUrl !== '' ? rtrim($siteUrl, '/') . '/booking.php' : (defined('BASE_URL') ? rtrim(BASE_URL, '/') . '/booking.php' : '/booking.php');

    try {
        $stmt = $pdo->query("\n            SELECT name, short_description, price_per_night, price_single_occupancy,\n                   price_double_occupancy, price_triple_occupancy, max_guests, rooms_available\n            FROM rooms\n            WHERE is_active = 1\n            ORDER BY display_order ASC, name ASC\n        ");
        $rooms = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    } catch (Throwable $e) {
        rh_log_event('api_keys', 'warning', 'Could not build room price Meta template', ['error' => $e->getMessage()]);
        $rooms = [];
    }

    $lines = [];
    $lines[] = $siteName . ' - Rooms and Prices';
    $lines[] = '';

    if (!$rooms) {
        $lines[] = 'Room prices are being updated. Please message us with your dates and number of guests for the latest availability.';
    } else {
        foreach ($rooms as $room) {
            $name = trim((string)($room['name'] ?? 'Room'));
            $maxGuests = (int)($room['max_guests'] ?? 0);
            $available = (int)($room['rooms_available'] ?? 0);
            $price = (float)($room['price_per_night'] ?? 0);
            $single = (float)($room['price_single_occupancy'] ?? 0);
            $double = (float)($room['price_double_occupancy'] ?? 0);
            $triple = (float)($room['price_triple_occupancy'] ?? 0);
            $desc = trim((string)($room['short_description'] ?? ''));

            $lines[] = '- ' . $name;
            if ($desc !== '') {
                $lines[] = '  ' . $desc;
            }
            if ($maxGuests > 0) {
                $lines[] = '  Sleeps up to ' . $maxGuests . ' guest' . ($maxGuests === 1 ? '' : 's');
            }
            $lines[] = '  From ' . $currency . number_format($price, 2) . ' per night';
            $occupancy = [];
            if ($single > 0) $occupancy[] = 'single ' . $currency . number_format($single, 2);
            if ($double > 0) $occupancy[] = 'double ' . $currency . number_format($double, 2);
            if ($triple > 0) $occupancy[] = 'triple ' . $currency . number_format($triple, 2);
            if ($occupancy) {
                $lines[] = '  Rates: ' . implode(' | ', $occupancy);
            }
            $lines[] = '  Current rooms showing in system: ' . $available;
            $lines[] = '';
        }
    }

    $lines[] = 'To check exact availability, send your dates or book here:';
    $lines[] = $bookingUrl;
    $lines[] = '';
    $lines[] = 'Prices can change by date, occupancy and availability.';

    return implode("\n", $lines);
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    try {
        if (!isset($_POST['csrf_token']) || !validateCsrfToken($_POST['csrf_token'])) {
            throw new RuntimeException('Invalid security token. Please refresh and try again.');
        }

        switch ($_POST['action']) {
            case 'create_key':
                $clientName = trim((string)($_POST['client_name'] ?? ''));
                $clientWebsite = trim((string)($_POST['client_website'] ?? ''));
                $clientEmail = trim((string)($_POST['client_email'] ?? ''));
                $rateLimit = max(1, (int)($_POST['rate_limit_per_hour'] ?? 100));
                $permissions = array_values(array_intersect(array_keys($availablePermissions), (array)($_POST['permissions'] ?? [])));

                if ($clientName === '' || $clientEmail === '') {
                    throw new RuntimeException('Client name and email are required.');
                }

                $rawApiKey = bin2hex(random_bytes(32));
                $hashedApiKey = password_hash($rawApiKey, PASSWORD_DEFAULT);
                $encryptedApiKey = encryptApiKey($rawApiKey);

                $stmt = $pdo->prepare(
                    'INSERT INTO api_keys (api_key, api_key_plain, client_name, client_website, client_email, permissions, rate_limit_per_hour, is_active)
                     VALUES (?, ?, ?, ?, ?, ?, ?, 1)'
                );
                $stmt->execute([
                    $hashedApiKey,
                    $encryptedApiKey,
                    $clientName,
                    $clientWebsite,
                    $clientEmail,
                    json_encode(array_values((array)$permissions)),
                    $rateLimit,
                ]);

                $safeClientName = htmlspecialchars($clientName, ENT_QUOTES, 'UTF-8');
                $safeRaw = htmlspecialchars($rawApiKey, ENT_QUOTES, 'UTF-8');
                $message = "API key created successfully.<br><br><strong>Client:</strong> {$safeClientName}<br><strong>API Key:</strong> <code>{$safeRaw}</code><br><br><strong>Important:</strong> You can reveal/copy this key anytime from the list below.";
                $messageType = 'success';
                rh_log_event('api_keys', 'info', 'API key created', ['client' => $clientName, 'permissions' => $permissions, 'rate_limit_per_hour' => $rateLimit]);
                break;

            case 'toggle_status':
                $keyId = (int)($_POST['key_id'] ?? 0);
                $isActive = (int)($_POST['is_active'] ?? 0) === 1 ? 1 : 0;

                $stmt = $pdo->prepare('UPDATE api_keys SET is_active = ? WHERE id = ?');
                $stmt->execute([$isActive, $keyId]);

                $message = 'API key status updated successfully.';
                $messageType = 'success';
                rh_log_event('api_keys', 'info', 'API key status changed', ['key_id' => $keyId, 'is_active' => $isActive]);
                break;

            case 'regenerate_key':
                $keyId = (int)($_POST['key_id'] ?? 0);
                $rawApiKey = bin2hex(random_bytes(32));
                $hashedApiKey = password_hash($rawApiKey, PASSWORD_DEFAULT);
                $encryptedApiKey = encryptApiKey($rawApiKey);

                $stmt = $pdo->prepare('UPDATE api_keys SET api_key = ?, api_key_plain = ?, usage_count = 0, last_used_at = NULL WHERE id = ?');
                $stmt->execute([$hashedApiKey, $encryptedApiKey, $keyId]);

                $nameStmt = $pdo->prepare('SELECT client_name FROM api_keys WHERE id = ?');
                $nameStmt->execute([$keyId]);
                $client = $nameStmt->fetch(PDO::FETCH_ASSOC) ?: ['client_name' => 'Client'];

                $safeClientName = htmlspecialchars((string)$client['client_name'], ENT_QUOTES, 'UTF-8');
                $safeRaw = htmlspecialchars($rawApiKey, ENT_QUOTES, 'UTF-8');
                $message = "API key regenerated for <strong>{$safeClientName}</strong>.<br><br><strong>New API Key:</strong> <code>{$safeRaw}</code><br><br><strong>Important:</strong> You can reveal/copy this key anytime from the list below.";
                $messageType = 'success';
                rh_log_event('api_keys', 'warning', 'API key regenerated', ['key_id' => $keyId, 'client' => $client['client_name'] ?? 'Client']);
                break;

            case 'delete_key':
                $keyId = (int)($_POST['key_id'] ?? 0);

                $stmt = $pdo->prepare('DELETE FROM api_keys WHERE id = ?');
                $stmt->execute([$keyId]);

                $message = 'API key deleted successfully.';
                $messageType = 'success';
                rh_log_event('api_keys', 'warning', 'API key deleted', ['key_id' => $keyId]);
                break;
        }
    } catch (Throwable $e) {
        $message = 'Error: ' . htmlspecialchars($e->getMessage(), ENT_QUOTES, 'UTF-8');
        $messageType = 'error';
        rh_log_event('api_keys', 'error', 'API key action failed', ['action' => $_POST['action'] ?? '', 'error' => $e->getMessage()]);
    }
}

$apiKeys = [];
try {
    $stmt = $pdo->query(
        'SELECT ak.*,
                (SELECT COUNT(*) FROM api_usage_logs WHERE api_key_id = ak.id) AS total_calls,
                (SELECT COUNT(*) FROM api_usage_logs WHERE api_key_id = ak.id AND created_at >= DATE_SUB(NOW(), INTERVAL 1 HOUR)) AS calls_last_hour
         FROM api_keys ak
         ORDER BY ak.created_at DESC'
    );
    $apiKeys = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
} catch (Throwable $e) {
    if ($message === '') {
        $message = 'Error loading API keys: ' . htmlspecialchars($e->getMessage(), ENT_QUOTES, 'UTF-8');
        $messageType = 'error';
        rh_log_event('api_keys', 'error', 'Failed loading API keys', ['error' => $e->getMessage()]);
    }
}

$activeKeyCount = 0;
$disabledKeyCount = 0;
foreach ($apiKeys as $apiKeyRow) {
    if ((int)($apiKeyRow['is_active'] ?? 0) === 1) {
        $activeKeyCount++;
    } else {
        $disabledKeyCount++;
    }
}

$roomPriceTemplate = buildMetaRoomPriceTemplate($pdo);
?>
<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>API Keys Management - Admin Panel</title>

    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Cormorant+Garamond:ital,wght@0,300;0,400;0,500;0,600;1,300;1,400;1,500&family=Jost:wght@300;400;500;600&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="css/admin-styles.css?v=<?php echo @filemtime(__DIR__ . '/css/admin-styles.css'); ?>">
    <link rel="stylesheet" href="css/admin-components.css?v=<?php echo @filemtime(__DIR__ . '/css/admin-components.css'); ?>">
    <link rel="stylesheet" href="css/api-keys.css?v=<?php echo @filemtime(__DIR__ . '/css/api-keys.css'); ?>">
</head>

<body>
    <?php
    $current_page = 'api-keys.php';
    require_once 'includes/admin-header.php';
    ?>

    <div class="admin-container api-keys-page">
        <div class="admin-page-hero api-keys-hero">
            <div class="api-keys-hero__content">
                <h1><i class="fas fa-key"></i> API Keys Management</h1>
                <p>Manage external API keys for booking integrations and control exactly what each integration can access.</p>
            </div>
            <div class="api-keys-hero__stats" aria-label="API key status summary">
                <span class="api-keys-stat-pill"><strong><?php echo count($apiKeys); ?></strong> Keys</span>
                <span class="api-keys-stat-pill api-keys-stat-pill--active"><strong><?php echo $activeKeyCount; ?></strong> Active</span>
                <span class="api-keys-stat-pill api-keys-stat-pill--disabled"><strong><?php echo $disabledKeyCount; ?></strong> Disabled</span>
            </div>
        </div>

        <?php if ($message !== ''): ?>
            <div class="alert alert-<?php echo $messageType === 'error' ? 'error' : 'success'; ?> api-keys-alert">
                <?php echo $message; ?>
            </div>
        <?php endif; ?>

        <div class="api-keys-guide-grid">
            <div class="card api-keys-card">
                <div class="card-header">
                    <h3><i class="fas fa-circle-info"></i> Simple API Guide</h3>
                </div>
                <div class="card-body">
                    <ul class="api-keys-guide-list">
                        <li><span class="endpoint-code">rooms.read</span> lets a website or app read room names, descriptions, guest limits and prices.</li>
                        <li><span class="endpoint-code">availability.check</span> checks available rooms for selected dates before a guest books.</li>
                        <li><span class="endpoint-code">bookings.create</span> creates bookings from an approved outside website or Meta flow.</li>
                        <li>Send the key in the <span class="endpoint-code">X-API-Key</span> header. Keep keys private and disable any key you no longer use.</li>
                        <li>For Facebook/Instagram/Meta copy, use the room-price template on the right. It is generated from the live room table.</li>
                    </ul>
                </div>
            </div>

            <div class="card api-keys-card">
                <div class="card-header api-keys-card-header api-keys-card-header--split">
                    <h3><i class="fab fa-meta"></i> Meta Room/Price Template</h3>
                    <button type="button" class="btn btn-sm btn-primary" id="copyMetaTemplate"><i class="fas fa-copy"></i> Copy</button>
                </div>
                <div class="card-body">
                    <textarea id="metaRoomsTemplate" class="api-keys-template" readonly><?php echo htmlspecialchars($roomPriceTemplate, ENT_QUOTES, 'UTF-8'); ?></textarea>
                    <p class="text-muted api-keys-template-help">Paste into Facebook/Instagram replies, Meta ads, or saved replies when you need current rooms and prices.</p>
                </div>
            </div>
        </div>

        <div class="api-keys-layout">
            <div class="api-keys-layout__main">
                <div class="card api-keys-card">
                    <div class="card-header">
                        <h3><i class="fas fa-list"></i> API Keys</h3>
                    </div>
                    <div class="card-body">
                        <?php if (empty($apiKeys)): ?>
                            <div class="alert alert-info">No API keys found. Create one using the form on the right.</div>
                        <?php else: ?>
                            <div class="table-container">
                                <div class="table-responsive">
                                    <table class="table api-keys-table mobile-enhanced">
                                        <thead>
                                            <tr>
                                                <th>Client</th>
                                                <th>API Key</th>
                                                <th>Website</th>
                                                <th>Usage</th>
                                                <th>Rate Limit</th>
                                                <th>Status</th>
                                                <th>Last Used</th>
                                                <th>Actions</th>
                                            </tr>
                                        </thead>
                                        <tbody>
                                            <?php foreach ($apiKeys as $key): ?>
                                                <?php
                                                $decryptedKey = decryptApiKey((string)($key['api_key_plain'] ?? ''));
                                                $hasPlainKey = !empty($decryptedKey);
                                                $masked = str_repeat('•', 32);
                                                ?>
                                                <tr>
                                                    <td>
                                                        <strong><?php echo htmlspecialchars((string)$key['client_name'], ENT_QUOTES, 'UTF-8'); ?></strong><br>
                                                        <small class="text-muted"><?php echo htmlspecialchars((string)$key['client_email'], ENT_QUOTES, 'UTF-8'); ?></small>
                                                    </td>
                                                    <td class="api-keys-key-cell">
                                                        <?php if ($hasPlainKey): ?>
                                                            <div class="api-key-display">
                                                                <code
                                                                    class="api-key-value"
                                                                    id="key_<?php echo (int)$key['id']; ?>"
                                                                    data-key="<?php echo htmlspecialchars((string)$decryptedKey, ENT_QUOTES, 'UTF-8'); ?>"
                                                                    data-masked="<?php echo $masked; ?>"><?php echo $masked; ?></code>
                                                                <button type="button" class="btn-api-key reveal-btn" data-target="key_<?php echo (int)$key['id']; ?>" title="Reveal key">
                                                                    <i class="fas fa-eye"></i>
                                                                </button>
                                                                <button type="button" class="btn-api-key copy-btn" data-target="key_<?php echo (int)$key['id']; ?>" title="Copy key">
                                                                    <i class="fas fa-copy"></i>
                                                                </button>
                                                            </div>
                                                        <?php else: ?>
                                                            <span class="text-muted">Hidden</span>
                                                            <span class="legacy-key-badge">legacy</span>
                                                        <?php endif; ?>
                                                    </td>
                                                    <td>
                                                        <?php
                                                        $website = trim((string)($key['client_website'] ?? ''));
                                                        echo $website !== ''
                                                            ? htmlspecialchars($website, ENT_QUOTES, 'UTF-8')
                                                            : '<span class="text-muted">Not provided</span>';
                                                        ?>
                                                    </td>
                                                    <td>
                                                        <strong><?php echo (int)($key['total_calls'] ?? 0); ?></strong> total<br>
                                                        <small class="text-muted"><?php echo (int)($key['calls_last_hour'] ?? 0); ?> last hour</small>
                                                    </td>
                                                    <td><?php echo (int)($key['rate_limit_per_hour'] ?? 0); ?>/hour</td>
                                                    <td>
                                                        <?php if ((int)($key['is_active'] ?? 0) === 1): ?>
                                                            <span class="badge badge-success">Active</span>
                                                        <?php else: ?>
                                                            <span class="badge badge-danger">Disabled</span>
                                                        <?php endif; ?>
                                                    </td>
                                                    <td>
                                                        <?php echo !empty($key['last_used_at']) ? htmlspecialchars((string)$key['last_used_at'], ENT_QUOTES, 'UTF-8') : '<span class="text-muted">Never</span>'; ?>
                                                    </td>
                                                    <td class="api-keys-actions">
                                                        <form method="post" class="api-keys-actions-form" data-admin-loader-text="Updating API key status..." data-admin-submit-text="Updating...">
                                                            <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrf_token, ENT_QUOTES, 'UTF-8'); ?>">
                                                            <input type="hidden" name="action" value="toggle_status">
                                                            <input type="hidden" name="key_id" value="<?php echo (int)$key['id']; ?>">
                                                            <input type="hidden" name="is_active" value="<?php echo (int)($key['is_active'] ?? 0) === 1 ? 0 : 1; ?>">
                                                            <button type="submit" class="btn btn-sm <?php echo (int)($key['is_active'] ?? 0) === 1 ? 'btn-warning' : 'btn-success'; ?>">
                                                                <?php echo (int)($key['is_active'] ?? 0) === 1 ? 'Disable' : 'Enable'; ?>
                                                            </button>
                                                        </form>

                                                        <form method="post" class="api-keys-actions-form" data-admin-loader-text="Regenerating API key..." data-admin-submit-text="Regenerating...">
                                                            <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrf_token, ENT_QUOTES, 'UTF-8'); ?>">
                                                            <input type="hidden" name="action" value="regenerate_key">
                                                            <input type="hidden" name="key_id" value="<?php echo (int)$key['id']; ?>">
                                                            <button
                                                                type="submit"
                                                                class="btn btn-sm btn-primary"
                                                                data-admin-confirm="Regenerate API key for this client?"
                                                                data-admin-confirm-title="Regenerate API Key"
                                                                data-admin-confirm-ok="Regenerate"
                                                                data-admin-confirm-tone="warning">
                                                                Regenerate
                                                            </button>
                                                        </form>

                                                        <form method="post" class="api-keys-actions-form" data-admin-loader-text="Deleting API key..." data-admin-submit-text="Deleting...">
                                                            <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrf_token, ENT_QUOTES, 'UTF-8'); ?>">
                                                            <input type="hidden" name="action" value="delete_key">
                                                            <input type="hidden" name="key_id" value="<?php echo (int)$key['id']; ?>">
                                                            <button
                                                                type="submit"
                                                                class="btn btn-sm btn-danger"
                                                                data-admin-confirm="Delete API key for this client? This cannot be undone."
                                                                data-admin-confirm-title="Delete API Key"
                                                                data-admin-confirm-ok="Delete"
                                                                data-admin-confirm-tone="danger"
                                                                data-admin-confirm-icon="fa-trash-alt">
                                                                Delete
                                                            </button>
                                                        </form>
                                                    </td>
                                                </tr>
                                            <?php endforeach; ?>
                                        </tbody>
                                    </table>
                                </div>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>
            </div>

            <div class="api-keys-layout__side">
                <div class="card api-keys-card">
                    <div class="card-header">
                        <h3><i class="fas fa-plus-circle"></i> Create API Key</h3>
                    </div>
                    <div class="card-body">
                        <form method="post" class="api-keys-create-form" data-admin-loader-text="Creating API key..." data-admin-submit-text="Creating...">
                            <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrf_token, ENT_QUOTES, 'UTF-8'); ?>">
                            <input type="hidden" name="action" value="create_key">

                            <div class="form-group">
                                <label for="client_name">Client Name</label>
                                <input id="client_name" name="client_name" class="form-control" required>
                            </div>

                            <div class="form-group">
                                <label for="client_email">Client Email</label>
                                <input id="client_email" name="client_email" type="email" class="form-control" required>
                            </div>

                            <div class="form-group">
                                <label for="client_website">Client Website</label>
                                <input id="client_website" name="client_website" class="form-control" placeholder="https://example.com">
                            </div>

                            <div class="form-group">
                                <label for="rate_limit_per_hour">Rate Limit Per Hour</label>
                                <input id="rate_limit_per_hour" name="rate_limit_per_hour" type="number" min="1" value="100" class="form-control" required>
                            </div>

                            <div class="form-group">
                                <label>Permissions</label>
                                <div class="checkbox-group api-keys-permissions">
                                    <?php foreach ($availablePermissions as $permKey => $permLabel): ?>
                                        <label class="checkbox-item api-keys-checkbox-item">
                                            <input type="checkbox" name="permissions[]" value="<?php echo htmlspecialchars($permKey, ENT_QUOTES, 'UTF-8'); ?>">
                                            <span><?php echo htmlspecialchars($permLabel, ENT_QUOTES, 'UTF-8'); ?></span>
                                        </label>
                                    <?php endforeach; ?>
                                </div>
                                <p class="text-muted api-keys-permissions-help">Choose only what this integration needs so compromised keys have minimal impact.</p>
                            </div>

                            <button type="submit" class="btn btn-primary">Create API Key</button>
                        </form>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <script>
        (function() {
            if (window.__apiKeysPageDelegatesBound) return;
            window.__apiKeysPageDelegatesBound = true;

            function getPageRoot() {
                return document.querySelector('.api-keys-page');
            }

            function isApiKeysTarget(node) {
                var root = getPageRoot();
                if (!root || !node) return false;
                return root.contains(node);
            }

            function copyText(value) {
                if (!value) return Promise.reject(new Error('Nothing to copy'));

                if (navigator.clipboard && typeof navigator.clipboard.writeText === 'function') {
                    return navigator.clipboard.writeText(value);
                }

                return new Promise(function(resolve, reject) {
                    try {
                        var temp = document.createElement('textarea');
                        temp.value = value;
                        document.body.appendChild(temp);
                        temp.focus();
                        temp.select();
                        document.execCommand('copy');
                        document.body.removeChild(temp);
                        resolve();
                    } catch (err) {
                        reject(err);
                    }
                });
            }

            function flashButton(button, html, title, timeout) {
                if (!button) return;

                var oldHtml = button.innerHTML;
                var oldTitle = button.getAttribute('title');
                button.innerHTML = html;
                if (title) {
                    button.setAttribute('title', title);
                }

                window.setTimeout(function() {
                    button.innerHTML = oldHtml;
                    if (oldTitle === null) {
                        button.removeAttribute('title');
                    } else {
                        button.setAttribute('title', oldTitle);
                    }
                }, timeout || 1200);
            }

            document.addEventListener('click', function(event) {
                var revealBtn = event.target.closest('.reveal-btn');
                if (revealBtn && isApiKeysTarget(revealBtn)) {
                    var revealTarget = document.getElementById(revealBtn.getAttribute('data-target') || '');
                    if (!revealTarget) return;

                    var isRevealed = revealTarget.classList.contains('revealed');
                    if (isRevealed) {
                        revealTarget.textContent = revealTarget.getAttribute('data-masked') || '********************************';
                        revealTarget.classList.remove('revealed');
                        revealBtn.innerHTML = '<i class="fas fa-eye"></i>';
                        revealBtn.setAttribute('title', 'Reveal key');
                    } else {
                        revealTarget.textContent = revealTarget.getAttribute('data-key') || '';
                        revealTarget.classList.add('revealed');
                        revealBtn.innerHTML = '<i class="fas fa-eye-slash"></i>';
                        revealBtn.setAttribute('title', 'Hide key');
                    }
                    return;
                }

                var copyBtn = event.target.closest('.copy-btn');
                if (copyBtn && isApiKeysTarget(copyBtn)) {
                    var keyTarget = document.getElementById(copyBtn.getAttribute('data-target') || '');
                    if (!keyTarget) return;

                    var key = keyTarget.getAttribute('data-key') || '';
                    if (!key) return;

                    copyText(key)
                        .then(function() {
                            flashButton(copyBtn, '<i class="fas fa-check"></i>', 'Copied key', 1300);
                        })
                        .catch(function() {
                            flashButton(copyBtn, '<i class="fas fa-xmark"></i>', 'Copy failed', 1300);
                        });
                    return;
                }

                var copyMetaTemplateBtn = event.target.closest('#copyMetaTemplate');
                if (copyMetaTemplateBtn && isApiKeysTarget(copyMetaTemplateBtn)) {
                    var template = document.getElementById('metaRoomsTemplate');
                    if (!template) return;

                    copyText(template.value)
                        .then(function() {
                            flashButton(copyMetaTemplateBtn, '<i class="fas fa-check"></i> Copied', 'Copied', 1500);
                        })
                        .catch(function() {
                            flashButton(copyMetaTemplateBtn, '<i class="fas fa-xmark"></i> Copy failed', 'Copy failed', 1500);
                        });
                }
            });

            document.addEventListener('rh:content-updated', function() {
                // Event delegation handles SPA swaps automatically.
            });
        })();
    </script>
    <?php require_once 'includes/admin-footer.php'; ?>
</body>

</html>

