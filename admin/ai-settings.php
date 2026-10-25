<?php

/**
 * Assistant Settings (staff chat helper) - gated by the ai_settings permission.
 *
 * Six sections (.claude/AI_ASSISTANT_PLAN.md Phase 1):
 *  1. Master switch + plain status line (toggles the ai_enabled setting AND the ai_assistant module row)
 *  2. Providers: paste a key (stored encrypted, never shown again - only ••••last4), Test connection, Remove
 *  3. Models: the live list after a test, up to 5 chosen with a role, prices per million tokens
 *  4. Budgets and limits, chat retention
 *  5. Who has access (read-only; changed on Staff & Access)
 *  6. Usage per person, day and model (also needs ai_usage_view)
 *
 * Every POST: CSRF check, prepared statements, logActivity, then redirect (a refresh never repeats it).
 * Before migration 070 has run the page only shows a "run the database update" notice.
 */

require_once 'admin-init.php';
/** @var array $user */
/** @var string $csrf_token */
/** @var PDO $pdo */

require_once __DIR__ . '/../includes/ai/ai-catalog.php';

$csrf_token = $csrf_token ?? generateCsrfToken();
$h = static function ($v): string {
    return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8');
};
$uid = (int)$user['id'];
$providers = rh_ai_provider_list();
$schemaReady = rh_ai_schema_ready($pdo);
$canUsage = hasPermission($uid, 'ai_usage_view');

/** Queue a message for the next render and reload the page. */
$aiFlash = static function (string $type, string $msg, string $anchor = ''): void {
    $_SESSION['ai_flash'] = ['type' => $type, 'msg' => $msg];
    header('Location: ai-settings.php' . ($anchor !== '' ? '#' . $anchor : ''));
    exit;
};

// ── POST actions ──────────────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = (string)($_POST['action'] ?? '');
    $prov = (string)($_POST['provider'] ?? '');
    $provLabel = $providers[$prov]['label'] ?? '';
    try {
        if (!isset($_POST['csrf_token']) || !validateCsrfToken((string)$_POST['csrf_token'])) {
            throw new RuntimeException('Invalid security token. Please refresh the page and try again.');
        }
        if (!$schemaReady) {
            throw new RuntimeException('The database update for the Assistant has not been run yet, so nothing can be saved.');
        }

        if ($action === 'master') {
            $on = (string)($_POST['state'] ?? '') === 'on';
            if ($on) {
                $active = rh_ai_active_provider();
                $st = rh_ai_provider_states($pdo)[$active] ?? null;
                $hasPrimary = false;
                foreach (($active !== '' ? rh_ai_models_for($pdo, $active) : []) as $m) {
                    if ((int)$m['is_selected'] === 1 && $m['role'] === 'primary') {
                        $hasPrimary = true;
                    }
                }
                if ($active === '' || !$st || !$st['has_key'] || !$hasPrimary) {
                    throw new RuntimeException('Before switching on: save a provider key, click Test connection, then choose the models (with one Primary) and save them.');
                }
            }
            rh_ai_set_master($on, $pdo);
            logActivity($uid, 'ai_settings', $on ? 'Assistant switched on' : 'Assistant switched off');
            $aiFlash('success', $on ? 'The Assistant is on for the people who have access.' : 'The Assistant is off. Chats, keys and settings are kept.', 'ai-master');
        }

        if (in_array($action, ['save_key', 'test', 'remove_key', 'refresh_models', 'save_models'], true) && $provLabel === '') {
            throw new RuntimeException('Unknown provider.');
        }

        if ($action === 'save_key') {
            $key = trim((string)($_POST['api_key'] ?? ''));
            unset($_POST['api_key']);
            if ($key === '') {
                throw new RuntimeException('Paste the key first.');
            }
            rh_ai_store_key($prov, $key, $uid, $pdo);
            logActivity($uid, 'ai_settings', 'Assistant key saved for ' . $provLabel);
            // Test straight away so the model list loads in the same step.
            $t = rh_ai_test_connection($prov, $key);
            $key = '';
            rh_ai_record_test($prov, $t['ok'], $t['ms'], $t['message'], $pdo);
            if ($t['ranked']) {
                rh_ai_sync_models($pdo, $prov, $t['ranked']);
            }
            $aiFlash($t['ok'] ? 'success' : 'warning', 'Key saved for ' . $provLabel . '. ' . $t['message'], 'ai-prov-' . $prov);
        }

        if ($action === 'test') {
            $key = rh_ai_get_key($prov, $pdo);
            if ($key === '') {
                throw new RuntimeException('No readable key is saved for ' . $provLabel . '. Paste the key again.');
            }
            $t = rh_ai_test_connection($prov, $key);
            $key = '';
            rh_ai_record_test($prov, $t['ok'], $t['ms'], $t['message'], $pdo);
            if ($t['ranked']) {
                rh_ai_sync_models($pdo, $prov, $t['ranked']);
            }
            logActivity($uid, 'ai_settings', 'Assistant connection test for ' . $provLabel . ': ' . ($t['ok'] ? 'ok' : 'failed'));
            $aiFlash($t['ok'] ? 'success' : 'error', $provLabel . ': ' . $t['message'], 'ai-prov-' . $prov);
        }

        if ($action === 'remove_key') {
            rh_ai_remove_key($prov, $pdo);
            logActivity($uid, 'ai_settings', 'Assistant key removed for ' . $provLabel);
            $aiFlash('success', 'The ' . $provLabel . ' key was removed.', 'ai-prov-' . $prov);
        }

        if ($action === 'refresh_models') {
            $r = rh_ai_refresh_provider($pdo, $prov);
            logActivity($uid, 'ai_settings', 'Assistant model list checked for ' . $provLabel);
            $aiFlash($r['ok'] ? 'success' : 'error', $provLabel . ': ' . $r['message'], 'ai-models-' . $prov);
        }

        if ($action === 'save_models') {
            $roles = [];
            foreach ((array)($_POST['pick'] ?? []) as $mid) {
                $mid = (string)$mid;
                $roles[$mid] = (string)($_POST['role'][$mid] ?? '');
            }
            $prices = [];
            foreach ((array)($_POST['price_in'] ?? []) as $mid => $pin) {
                $prices[(string)$mid] = [(string)$pin, (string)($_POST['price_out'][$mid] ?? '')];
            }
            rh_ai_save_selection($pdo, $prov, $roles, $prices);
            logActivity($uid, 'ai_settings', 'Assistant models saved for ' . $provLabel . ': ' . implode(', ', array_map(static fn($k, $v) => $k . ' (' . $v . ')', array_keys($roles), $roles)));
            $aiFlash('success', $roles ? 'Models saved. ' . $provLabel . ' is now the provider in use.' : 'No models chosen: the Assistant has no provider in use.', 'ai-models-' . $prov);
        }

        if ($action === 'save_limits') {
            $cap = trim((string)($_POST['ai_user_daily_token_cap'] ?? ''));
            $budget = trim((string)($_POST['ai_monthly_budget_usd'] ?? ''));
            $rate = trim((string)($_POST['ai_rate_limit_per_10min'] ?? ''));
            $ret = trim((string)($_POST['ai_retention_days'] ?? ''));
            if (!ctype_digit($cap) || (int)$cap < 1000 || (int)$cap > 50000000) {
                throw new RuntimeException('Daily limit per person: a whole number of tokens from 1,000 to 50,000,000.');
            }
            if (!is_numeric($budget) || (float)$budget < 1 || (float)$budget > 100000) {
                throw new RuntimeException('Monthly cost limit: a number of US dollars from 1 to 100,000.');
            }
            if (!ctype_digit($rate) || (int)$rate < 1 || (int)$rate > 500) {
                throw new RuntimeException('Messages per person in 10 minutes: a whole number from 1 to 500.');
            }
            if (!ctype_digit($ret) || ((int)$ret !== 0 && ((int)$ret < 7 || (int)$ret > 3650))) {
                throw new RuntimeException('Keep chats for: 0 (keep) or a number of days from 7 to 3650.');
            }
            $turnCap = trim((string)($_POST['ai_turn_token_cap'] ?? ''));
            if ($turnCap !== '' && (!ctype_digit($turnCap) || (int)$turnCap < 5000 || (int)$turnCap > 1000000)) {
                throw new RuntimeException('Most tokens for one question: a whole number from 5,000 to 1,000,000.');
            }
            $limitPairs = [
                'ai_user_daily_token_cap' => (string)(int)$cap,
                'ai_monthly_budget_usd'   => (string)round((float)$budget, 2),
                'ai_rate_limit_per_10min' => (string)(int)$rate,
                'ai_retention_days'       => (string)(int)$ret,
            ];
            if ($turnCap !== '') {
                $limitPairs['ai_turn_token_cap'] = (string)(int)$turnCap;
            }
            rh_ai_save_settings($limitPairs, $pdo);
            logActivity($uid, 'ai_settings', 'Assistant limits saved: ' . (int)$cap . ' tokens/day, $' . round((float)$budget, 2) . '/month, ' . (int)$rate . ' messages/10 min, keep chats ' . ((int)$ret ?: 'always'));
            $aiFlash('success', 'Limits saved.', 'ai-limits');
        }

        throw new RuntimeException('Unknown request.');
    } catch (RuntimeException $e) {
        $aiFlash('error', $e->getMessage());
    } catch (Throwable $e) {
        error_log('ai-settings: ' . get_class($e) . ' ' . $e->getMessage());
        $aiFlash('error', 'Something went wrong while saving. Nothing secret was shown; please try again.');
    }
}

// ── Page data ─────────────────────────────────────────────────────────────────
$message = $warning = $error = '';
if (!empty($_SESSION['ai_flash']) && is_array($_SESSION['ai_flash'])) {
    $f = $_SESSION['ai_flash'];
    unset($_SESSION['ai_flash']);
    if (($f['type'] ?? '') === 'success') {
        $message = (string)$f['msg'];
    } elseif (($f['type'] ?? '') === 'warning') {
        $warning = (string)$f['msg'];
    } else {
        $error = (string)($f['msg'] ?? '');
    }
}

$saltMissing = rh_ai_salt_is_default();
$settings = $schemaReady ? rh_ai_settings($pdo, true) : rh_ai_setting_defaults();
$isOn = $schemaReady && rh_ai_enabled($pdo);
$paused = trim((string)($settings['ai_paused_reason'] ?? ''));
$active = (string)($settings['ai_provider'] ?? '');
$states = $schemaReady ? rh_ai_provider_states($pdo) : [];
$modelsBy = [];
$primaryLabel = '';
if ($schemaReady) {
    foreach (array_keys($providers) as $p) {
        $modelsBy[$p] = rh_ai_models_for($pdo, $p);
        foreach ($modelsBy[$p] as $m) {
            if ($p === $active && (int)$m['is_selected'] === 1 && $m['role'] === 'primary') {
                $primaryLabel = (string)$m['label'];
            }
        }
    }
}

// Who has access: active staff holding ai_assistant_use
$access = [];
if ($schemaReady) {
    try {
        foreach ($pdo->query("SELECT id, username, full_name, role FROM admin_users WHERE is_active = 1 ORDER BY full_name, username")->fetchAll(PDO::FETCH_ASSOC) as $u) {
            if (hasPermission((int)$u['id'], 'ai_assistant_use')) {
                $u['can_act'] = hasPermission((int)$u['id'], 'ai_assistant_actions');
                $access[] = $u;
            }
        }
    } catch (Throwable $e) {
        $access = [];
    }
}
$roleLabels = array_map(static fn($r) => (string)($r['label'] ?? ''), getAllRoles());

// Usage (last 30 days) and month-to-date estimated cost
$usage = [];
$monthCost = 0.0;
if ($schemaReady && $canUsage) {
    try {
        $usage = $pdo->query(
            "SELECT d.day, d.provider, d.model_id, d.requests, d.tokens_in, d.tokens_out, d.cost_usd,
                    COALESCE(NULLIF(u.full_name, ''), u.username, CONCAT('User #', d.user_id)) AS person
             FROM ai_usage_daily d LEFT JOIN admin_users u ON u.id = d.user_id
             WHERE d.day >= CURDATE() - INTERVAL 30 DAY
             ORDER BY d.day DESC, person ASC, d.model_id ASC
             LIMIT 300"
        )->fetchAll(PDO::FETCH_ASSOC);
        $monthCost = (float)$pdo->query("SELECT COALESCE(SUM(cost_usd), 0) FROM ai_usage_daily WHERE day >= DATE_FORMAT(CURDATE(), '%Y-%m-01')")->fetchColumn();
    } catch (Throwable $e) {
        $usage = [];
    }
}

// Status line
if (!$schemaReady) {
    $statusLine = 'Not set up yet: the database update has not been run.';
} elseif ($isOn) {
    $statusLine = 'On - using ' . ($providers[$active]['label'] ?? 'no provider') . ($primaryLabel !== '' ? ' (' . $primaryLabel . ')' : '') . ', ' . count($access) . ' ' . (count($access) === 1 ? 'person has' : 'people have') . ' access.';
} elseif ($paused !== '' && (string)$settings['ai_enabled'] === '1') {
    $statusLine = 'Paused automatically: ' . $paused;
} else {
    $statusLine = 'Off - nobody sees the Assistant. Switching it off keeps chats, keys and settings.';
}
$fmtCtx = static function ($n): string {
    if ($n === null || $n === '' || (int)$n <= 0) {
        return '—';
    }
    $n = (int)$n;
    return $n >= 1000000 ? rtrim(rtrim(number_format($n / 1000000, 2), '0'), '.') . 'M' : (int)round($n / 1000) . 'K';
};
$fmtPrice = static function ($v): string {
    return $v === null || $v === '' ? '' : rtrim(rtrim(number_format((float)$v, 4, '.', ''), '0'), '.');
};
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="csrf-token" content="<?php echo $h($csrf_token); ?>">
    <title>Assistant Settings — Admin</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Cormorant+Garamond:ital,wght@0,300;0,400;0,500;0,600;1,300;1,400;1,500&family=Jost:wght@300;400;500;600&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="css/admin-styles.css?v=<?php echo @filemtime(__DIR__ . '/css/admin-styles.css'); ?>">
    <link rel="stylesheet" href="css/admin-components.css?v=<?php echo @filemtime(__DIR__ . '/css/admin-components.css'); ?>">
    <link rel="stylesheet" href="css/admin-booking-settings.css?v=<?php echo @filemtime(__DIR__ . '/css/admin-booking-settings.css'); ?>">
    <link rel="stylesheet" href="css/ai-settings.css?v=<?php echo @filemtime(__DIR__ . '/css/ai-settings.css'); ?>">
</head>

<body>
    <?php require_once 'includes/admin-header.php'; ?>

    <div class="content ai-settings">
        <header class="rh-page-head">
            <div class="rh-page-head__main">
                <div class="rh-page-head__title">
                    <h1>Assistant Settings</h1>
                </div>
            </div>
            <div class="rh-page-head__actions">
                <a href="booking-settings.php" class="back-link"><i class="fas fa-arrow-left"></i> Back to Settings</a>
            </div>
        </header>
        <p class="help-text ai-intro">The Assistant is a chat helper for staff. It can only see and do what the person using it can already see and do, and every change it suggests waits for that person to confirm.</p>

        <?php if ($message): ?>
            <div class="alert alert-success"><i class="fas fa-check-circle"></i><div><?php echo $h($message); ?></div></div>
        <?php endif; ?>
        <?php if ($warning): ?>
            <div class="alert alert-warning"><i class="fas fa-exclamation-triangle"></i><div><?php echo $h($warning); ?></div></div>
        <?php endif; ?>
        <?php if ($error): ?>
            <div class="alert alert-error"><i class="fas fa-exclamation-circle"></i><div><?php echo $h($error); ?></div></div>
        <?php endif; ?>

        <?php if (!$schemaReady): ?>
            <div class="rh-panel ai-card" id="ai-db-update">
                <div class="rh-panel__head"><h2 class="rh-panel__title">Database update needed</h2></div>
                <div class="rh-panel__body">
                    <p>The Assistant needs a database update before it can be set up. Ask whoever installs updates on the server to run the database update:</p>
                    <p><code>php admin/migrations/migrate.php --run</code></p>
                    <p class="help-text">Until then the Assistant stays off and nothing else in the system changes.</p>
                </div>
            </div>
        <?php else: ?>

        <?php if ($saltMissing): ?>
            <div class="alert alert-warning"><i class="fas fa-lock"></i><div>Keys cannot be saved yet: the server encryption setting (APP_ENCRYPTION_SALT) is not set. Ask whoever hosts the system to add it, then come back.</div></div>
        <?php endif; ?>

        <!-- 1. Master switch -->
        <div class="rh-panel ai-card" id="ai-master">
            <div class="rh-panel__head"><h2 class="rh-panel__title">On or off <i class="help" data-tip="One switch for everyone. When off, nobody sees the Assistant and nothing is sent to any provider. Chats, keys and settings are kept.">?</i></h2></div>
            <div class="rh-panel__body">
                <div class="ai-status ai-status--<?php echo $isOn ? 'on' : ($paused !== '' && (string)$settings['ai_enabled'] === '1' ? 'paused' : 'off'); ?>">
                    <i class="fas <?php echo $isOn ? 'fa-circle-check' : 'fa-circle-pause'; ?>" aria-hidden="true"></i>
                    <span><?php echo $h($statusLine); ?></span>
                </div>
                <form method="POST" action="ai-settings.php" class="ai-actions">
                    <input type="hidden" name="csrf_token" value="<?php echo $h($csrf_token); ?>">
                    <input type="hidden" name="action" value="master">
                    <?php if ($isOn): ?>
                        <button type="submit" name="state" value="off" class="btn btn-secondary"><i class="fas fa-power-off"></i> Switch off</button>
                    <?php else: ?>
                        <button type="submit" name="state" value="on" class="btn btn-primary"><i class="fas fa-power-off"></i> Switch on</button>
                    <?php endif; ?>
                </form>
                <p class="help-text">To switch on you need a provider key that passed Test connection and saved models with one Primary. Who may use it is set per person on Staff &amp; Access.</p>
            </div>
        </div>

        <!-- 2. Providers -->
        <div class="rh-panel ai-card" id="ai-providers">
            <div class="rh-panel__head"><h2 class="rh-panel__title">Providers <i class="help" data-tip="The companies whose models answer the questions. You need a key from at least one. Keys are stored encrypted and are never shown again, only their last four characters.">?</i></h2></div>
            <div class="rh-panel__body">
                <p class="help-text">Paste a key and click Save and test. Test connection lists the provider's models and sends one short test message (at most 5 tokens, a fraction of a cent).</p>
                <div class="ai-provider-grid">
                <?php foreach ($providers as $p => $meta):
                    $st = $states[$p] ?? null;
                    $hasKey = $st && $st['has_key'];
                    $status = $st['status'] ?? 'none';
                ?>
                    <div class="ai-provider" id="ai-prov-<?php echo $h($p); ?>">
                        <div class="ai-provider__head">
                            <strong><?php echo $h($meta['label']); ?></strong>
                            <span class="ai-provider__product"><?php echo $h($meta['product']); ?></span>
                            <?php if ($p === $active): ?><span class="badge badge-success">In use</span><?php endif; ?>
                        </div>
                        <?php if ($meta['note'] !== ''): ?>
                            <p class="help-text ai-provider__note"><i class="fas fa-info-circle"></i> <?php echo $h($meta['note']); ?></p>
                        <?php endif; ?>
                        <p class="ai-provider__key">
                            Key: <?php echo $hasKey ? '<code>' . $h(rh_ai_mask_last4((string)$st['key_last4'])) . '</code>' : '<span class="ai-muted">none saved</span>'; ?>
                            <i class="help" data-tip="Only the last four characters of the saved key are ever shown. The key itself stays encrypted on the server.">?</i>
                        </p>
                        <?php if ($hasKey): ?>
                            <p class="ai-provider__test ai-provider__test--<?php echo $h($status); ?>">
                                <?php if ($status === 'ok'): ?>
                                    <i class="fas fa-check-circle"></i>
                                <?php elseif ($status === 'error'): ?>
                                    <i class="fas fa-exclamation-triangle"></i>
                                <?php else: ?>
                                    <i class="fas fa-circle-question"></i>
                                <?php endif; ?>
                                <?php
                                if ($st['last_test_at']) {
                                    echo $h('Last test ' . date('j M Y H:i', strtotime((string)$st['last_test_at'])) . ($st['last_test_ms'] !== null ? ' (' . $st['last_test_ms'] . ' ms)' : '') . ': ' . ($st['last_test_message'] !== '' ? $st['last_test_message'] : ($status === 'ok' ? 'OK' : 'failed')));
                                } else {
                                    echo 'Not tested yet.';
                                }
                                ?>
                            </p>
                        <?php endif; ?>
                        <form method="POST" action="ai-settings.php" autocomplete="off" class="ai-key-form">
                            <input type="hidden" name="csrf_token" value="<?php echo $h($csrf_token); ?>">
                            <input type="hidden" name="provider" value="<?php echo $h($p); ?>">
                            <label class="ai-sr" for="ai-key-<?php echo $h($p); ?>"><?php echo $h($meta['label']); ?> key</label>
                            <input type="password" id="ai-key-<?php echo $h($p); ?>" name="api_key" class="form-control" autocomplete="new-password" spellcheck="false"
                                placeholder="<?php echo $hasKey ? 'Paste a new key to replace it' : 'Paste the key'; ?>"<?php echo $saltMissing ? ' disabled' : ''; ?>>
                            <p class="help-text"><?php echo $h($meta['key_hint']); ?></p>
                            <div class="ai-actions">
                                <button type="submit" name="action" value="save_key" class="btn btn-primary btn-sm"<?php echo $saltMissing ? ' disabled' : ''; ?>><i class="fas fa-key"></i> Save and test</button>
                                <?php if ($hasKey): ?>
                                    <button type="submit" name="action" value="test" class="btn btn-secondary btn-sm"><i class="fas fa-plug"></i> Test connection</button>
                                    <button type="submit" name="action" value="remove_key" class="btn btn-secondary btn-sm" onclick="return confirm('Remove the <?php echo $h($meta['label']); ?> key? Its chosen models will be un-chosen.');"><i class="fas fa-trash-alt"></i> Remove key</button>
                                <?php endif; ?>
                            </div>
                        </form>
                    </div>
                <?php endforeach; ?>
                </div>
            </div>
        </div>

        <!-- 3. Models -->
        <div class="rh-panel ai-card" id="ai-models">
            <div class="rh-panel__head"><h2 class="rh-panel__title">Models <i class="help" data-tip="Each provider offers several models. Choose up to 5 from one provider and give each a role. Only the provider whose models you save is used.">?</i></h2></div>
            <div class="rh-panel__body">
            <?php
            $anyModels = false;
            foreach ($modelsBy as $p => $rows) {
                if ($rows) {
                    $anyModels = true;
                }
            }
            ?>
            <?php if (!$anyModels): ?>
                <p class="help-text">No live list yet. After a key passes Test connection, that provider's current models appear here. These are the models known when this version was made:</p>
                <?php foreach ($providers as $p => $meta): $cat = rh_ai_catalog_models($p); if (!$cat) { continue; } ?>
                    <h3 class="ai-subhead"><?php echo $h($meta['label']); ?></h3>
                    <div class="table-container"><div class="table-responsive">
                    <table class="table ai-table">
                        <thead><tr>
                            <th>Model</th>
                            <th>Context <i class="help" data-tip="How much text the model can read at once, in tokens (1 token is about 3/4 of a word). 1M = one million.">?</i></th>
                            <th>Price in <i class="help" data-tip="US dollars per million tokens sent to the model (the question plus the hotel facts it needs).">?</i></th>
                            <th>Price out <i class="help" data-tip="US dollars per million tokens the model writes back.">?</i></th>
                            <th>Notes <i class="help" data-tip="Checked = id and price were checked against the provider's own pages when this list was written. Unchecked ones are confirmed by Test connection; prices stay editable.">?</i></th>
                        </tr></thead>
                        <tbody>
                        <?php foreach ($cat as $m): ?>
                            <tr>
                                <td><strong><?php echo $h($m['label']); ?></strong><br><code class="ai-id"><?php echo $h($m['model_id']); ?></code></td>
                                <td><?php echo $h($fmtCtx($m['context_tokens'])); ?></td>
                                <td><?php echo $m['price_in'] !== null ? '$' . $h($fmtPrice($m['price_in'])) : '—'; ?></td>
                                <td><?php echo $m['price_out'] !== null ? '$' . $h($fmtPrice($m['price_out'])) : '—'; ?></td>
                                <td>
                                    <?php echo $m['verified'] ? '<span class="badge badge-success">Checked</span>' : '<span class="badge badge-muted">Unchecked</span>'; ?>
                                    <?php if ($m['note'] !== ''): ?><span class="ai-muted"><?php echo $h($m['note']); ?></span><?php endif; ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                    </div></div>
                <?php endforeach; ?>
            <?php else: ?>
                <p class="help-text">Ticked rows are the suggestion (the five most capable) until you save your own choice. Primary answers questions; Fast handles short replies; Fallback takes over if Primary fails; Extra is kept ready.</p>
                <?php foreach ($modelsBy as $p => $rows): if (!$rows) { continue; }
                    $hasSel = (bool)array_filter($rows, static fn($r) => (int)$r['is_selected'] === 1);
                    $rankedForSuggest = array_map(static fn($r) => ['model_id' => $r['model_id'], 'rank' => (float)$r['capability_rank'], 'price_in' => $r['price_in_per_mtok'] !== null ? (float)$r['price_in_per_mtok'] : null], $rows);
                    $suggest = $hasSel ? [] : rh_ai_suggest_roles($rankedForSuggest);
                    $newestDate = max(array_map(static fn($r) => (string)($r['released_at'] ?? ''), $rows));
                    $hasKeyP = !empty($states[$p]['has_key']);
                ?>
                    <form method="POST" action="ai-settings.php" class="ai-models-form" id="ai-models-<?php echo $h($p); ?>">
                        <input type="hidden" name="csrf_token" value="<?php echo $h($csrf_token); ?>">
                        <input type="hidden" name="provider" value="<?php echo $h($p); ?>">
                        <h3 class="ai-subhead">
                            <?php echo $h($providers[$p]['label']); ?>
                            <?php if ($p === $active): ?><span class="badge badge-success">In use</span><?php endif; ?>
                            <span class="ai-pick-count" data-ai-count>0 of 5 chosen</span>
                        </h3>
                        <div class="table-container"><div class="table-responsive">
                        <table class="table ai-table">
                            <thead><tr>
                                <th>Use <i class="help" data-tip="Tick up to 5 models. Unticked models are never used.">?</i></th>
                                <th>Model</th>
                                <th>Role <i class="help" data-tip="Primary answers questions and plans. Fast does short replies and routing. Fallback is used when Primary fails. Extra is kept ready.">?</i></th>
                                <th>Context <i class="help" data-tip="How much text the model can read at once, in tokens (1 token is about 3/4 of a word). 1M = one million.">?</i></th>
                                <th>Price in <i class="help" data-tip="US dollars per million tokens sent to the model. Used only to estimate cost on this page and for the monthly limit. Leave blank if unknown.">?</i></th>
                                <th>Price out <i class="help" data-tip="US dollars per million tokens the model writes back. Used only for cost estimates and the monthly limit.">?</i></th>
                                <th>Notes <i class="help" data-tip="Newest = most recently released in this list. New - set price = not in the built-in list; type its price from the provider's price page.">?</i></th>
                            </tr></thead>
                            <tbody>
                            <?php foreach ($rows as $m):
                                $mid = (string)$m['model_id'];
                                $ticked = $hasSel ? (int)$m['is_selected'] === 1 : isset($suggest[$mid]);
                                $role = $hasSel ? (string)($m['role'] ?? '') : (string)($suggest[$mid] ?? '');
                                $stale = $m['last_seen_at'] !== null && strtotime((string)$m['last_seen_at']) < time() - 86400 * 21 && (int)$m['is_selected'] === 1;
                            ?>
                                <tr>
                                    <td><input type="checkbox" name="pick[]" value="<?php echo $h($mid); ?>" data-ai-pick<?php echo $ticked ? ' checked' : ''; ?> aria-label="Use <?php echo $h($m['label']); ?>"></td>
                                    <td><strong><?php echo $h($m['label']); ?></strong><br><code class="ai-id"><?php echo $h($mid); ?></code></td>
                                    <td>
                                        <select name="role[<?php echo $h($mid); ?>]" class="form-control ai-role" aria-label="Role for <?php echo $h($m['label']); ?>">
                                            <option value="">—</option>
                                            <?php foreach (rh_ai_model_roles() as $rk => $rl): ?>
                                                <option value="<?php echo $h($rk); ?>"<?php echo $role === $rk ? ' selected' : ''; ?>><?php echo $h(ucfirst($rk)); ?></option>
                                            <?php endforeach; ?>
                                        </select>
                                    </td>
                                    <td><?php echo $h($fmtCtx($m['context_tokens'])); ?></td>
                                    <td><input type="text" inputmode="decimal" name="price_in[<?php echo $h($mid); ?>]" value="<?php echo $h($fmtPrice($m['price_in_per_mtok'])); ?>" class="form-control ai-price" aria-label="Price in for <?php echo $h($m['label']); ?>" placeholder="$"></td>
                                    <td><input type="text" inputmode="decimal" name="price_out[<?php echo $h($mid); ?>]" value="<?php echo $h($fmtPrice($m['price_out_per_mtok'])); ?>" class="form-control ai-price" aria-label="Price out for <?php echo $h($m['label']); ?>" placeholder="$"></td>
                                    <td>
                                        <?php if ($newestDate !== '' && (string)($m['released_at'] ?? '') === $newestDate): ?><span class="badge badge-info">Newest</span><?php endif; ?>
                                        <?php if ((int)$m['is_new'] === 1): ?><span class="badge badge-warning">New - set price</span><?php endif; ?>
                                        <?php if ($stale): ?><span class="badge badge-muted">Not offered any more</span><?php endif; ?>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                            </tbody>
                        </table>
                        </div></div>
                        <div class="ai-actions">
                            <button type="submit" name="action" value="save_models" class="btn btn-primary btn-sm"><i class="fas fa-save"></i> Save models</button>
                            <?php if ($hasKeyP): ?>
                                <button type="submit" name="action" value="refresh_models" class="btn btn-secondary btn-sm"><i class="fas fa-rotate"></i> Check for newer models</button>
                            <?php endif; ?>
                        </div>
                    </form>
                <?php endforeach; ?>
            <?php endif; ?>
            </div>
        </div>

        <!-- 4. Budgets and limits -->
        <div class="rh-panel ai-card" id="ai-limits">
            <div class="rh-panel__head"><h2 class="rh-panel__title">Budgets and limits <i class="help" data-tip="Caps that stop the Assistant before it costs more than you want. The provider bills your account directly; these are estimates from the prices above.">?</i></h2></div>
            <div class="rh-panel__body">
                <form method="POST" action="ai-settings.php" class="ai-limits-grid">
                    <input type="hidden" name="csrf_token" value="<?php echo $h($csrf_token); ?>">
                    <input type="hidden" name="action" value="save_limits">
                    <div class="form-group">
                        <label for="ai_cap"><strong>Daily limit per person (tokens)</strong></label>
                        <input type="number" min="1000" max="50000000" step="1000" id="ai_cap" name="ai_user_daily_token_cap" class="form-control" value="<?php echo $h((int)$settings['ai_user_daily_token_cap']); ?>">
                        <p class="help-text">Tokens read plus written per person per day. 200,000 is roughly a busy day of questions.</p>
                    </div>
                    <div class="form-group">
                        <label for="ai_budget"><strong>Monthly cost limit (US$)</strong></label>
                        <input type="number" min="1" max="100000" step="0.01" id="ai_budget" name="ai_monthly_budget_usd" class="form-control" value="<?php echo $h($settings['ai_monthly_budget_usd']); ?>">
                        <p class="help-text">When the estimated cost this month reaches this, the Assistant pauses itself and tells the people who manage these settings.</p>
                    </div>
                    <div class="form-group">
                        <label for="ai_rate"><strong>Messages per person in 10 minutes</strong></label>
                        <input type="number" min="1" max="500" step="1" id="ai_rate" name="ai_rate_limit_per_10min" class="form-control" value="<?php echo $h((int)$settings['ai_rate_limit_per_10min']); ?>">
                        <p class="help-text">Stops a stuck page or a mistake from sending hundreds of messages.</p>
                    </div>
                    <div class="form-group">
                        <label for="ai_ret"><strong>Keep chats for (days)</strong></label>
                        <input type="number" min="0" max="3650" step="1" id="ai_ret" name="ai_retention_days" class="form-control" value="<?php echo $h((int)$settings['ai_retention_days']); ?>">
                        <p class="help-text">0 keeps chats until someone deletes them. Otherwise chats older than this are removed each night.</p>
                    </div>
                    <div class="form-group">
                        <label for="ai_turn_cap"><strong>Most tokens for one question</strong></label>
                        <input type="number" min="5000" max="1000000" step="1000" id="ai_turn_cap" name="ai_turn_token_cap" class="form-control" value="<?php echo $h((int)($settings['ai_turn_token_cap'] ?? 60000)); ?>">
                        <p class="help-text">One question may ask up to three department leads. This caps all of their work together; when it is reached the answer is given with what was found so far.</p>
                    </div>
                    <div class="ai-actions ai-limits-save">
                        <button type="submit" class="btn btn-primary btn-sm"><i class="fas fa-save"></i> Save limits</button>
                    </div>
                </form>
            </div>
        </div>

        <!-- 5. Who has access -->
        <div class="rh-panel ai-card" id="ai-access">
            <div class="rh-panel__head">
                <h2 class="rh-panel__title">Who has access <i class="help" data-tip="Active staff whose permissions include Use the Assistant. Administrators always have it; managers have it unless removed.">?</i></h2>
                <div class="rh-panel__actions"><a href="user-management.php" class="btn btn-secondary btn-sm"><i class="fas fa-users-cog"></i> Change on Staff &amp; Access</a></div>
            </div>
            <div class="rh-panel__body">
                <?php if (!$access): ?>
                    <p class="help-text">Nobody has the Use the Assistant permission yet.</p>
                <?php else: ?>
                    <div class="table-container"><div class="table-responsive">
                    <table class="table ai-table">
                        <thead><tr>
                            <th>Name</th>
                            <th>Role</th>
                            <th>Can confirm actions <i class="help" data-tip="Yes = holds Confirm Assistant actions: may confirm the changes the Assistant suggests (each still runs the normal screen action and its checks). No = chat only.">?</i></th>
                        </tr></thead>
                        <tbody>
                        <?php foreach ($access as $u): ?>
                            <tr>
                                <td><?php echo $h($u['full_name'] !== '' ? $u['full_name'] : $u['username']); ?></td>
                                <td><?php echo $h($roleLabels[$u['role']] ?? $u['role']); ?></td>
                                <td><?php echo $u['can_act'] ? 'Yes' : 'No'; ?></td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                    </div></div>
                <?php endif; ?>
            </div>
        </div>

        <!-- 6. Usage -->
        <?php if ($canUsage): ?>
        <div class="rh-panel ai-card" id="ai-usage">
            <div class="rh-panel__head"><h2 class="rh-panel__title">Usage <i class="help" data-tip="Questions asked in the last 30 days, per person, day and model, with the estimated cost from the prices in Models.">?</i></h2></div>
            <div class="rh-panel__body">
                <div class="ai-stat">
                    <span class="ai-stat__label">Estimated cost this month <i class="help" data-tip="Sum of the estimated cost of every Assistant reply since the 1st of this month, from the prices in Models. Your provider's bill is the real figure.">?</i></span>
                    <span class="ai-stat__value">$<?php echo $h(number_format($monthCost, 2)); ?></span>
                    <span class="ai-stat__sub">of $<?php echo $h(number_format((float)$settings['ai_monthly_budget_usd'], 2)); ?> monthly limit</span>
                </div>
                <?php if (!$usage): ?>
                    <p class="help-text">No questions asked in the last 30 days.</p>
                <?php else: ?>
                    <div class="table-container"><div class="table-responsive">
                    <table class="table ai-table">
                        <thead><tr>
                            <th>Day</th>
                            <th>Person</th>
                            <th>Model</th>
                            <th>Questions <i class="help" data-tip="Requests sent to the provider that day (one question can need more than one request).">?</i></th>
                            <th>Tokens in <i class="help" data-tip="Tokens sent to the model: the questions plus the hotel facts each answer needed.">?</i></th>
                            <th>Tokens out <i class="help" data-tip="Tokens the model wrote back.">?</i></th>
                            <th>Est. cost <i class="help" data-tip="Tokens in and out times the model's prices in Models, in US dollars. An estimate; the provider's bill is the real figure.">?</i></th>
                        </tr></thead>
                        <tbody>
                        <?php foreach ($usage as $r): ?>
                            <tr>
                                <td><?php echo $h(date('j M Y', strtotime((string)$r['day']))); ?></td>
                                <td><?php echo $h($r['person']); ?></td>
                                <td><code class="ai-id"><?php echo $h($r['model_id']); ?></code></td>
                                <td><?php echo $h(number_format((int)$r['requests'])); ?></td>
                                <td><?php echo $h(number_format((int)$r['tokens_in'])); ?></td>
                                <td><?php echo $h(number_format((int)$r['tokens_out'])); ?></td>
                                <td>$<?php echo $h(number_format((float)$r['cost_usd'], 4)); ?></td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                    </div></div>
                <?php endif; ?>
            </div>
        </div>
        <?php endif; ?>

        <?php endif; /* schemaReady */ ?>
    </div>

    <script>
    (function () {
        // Up to 5 models per provider: count the ticks and stop a sixth (the server checks again).
        function sync(form) {
            var boxes = form.querySelectorAll('[data-ai-pick]');
            var n = 0;
            boxes.forEach(function (b) { if (b.checked) n++; });
            boxes.forEach(function (b) { b.disabled = !b.checked && n >= 5; });
            var c = form.querySelector('[data-ai-count]');
            if (c) c.textContent = n + ' of 5 chosen';
        }
        document.querySelectorAll('.ai-models-form').forEach(sync);
        if (!window.__rhAiSettingsBound) {
            window.__rhAiSettingsBound = true;
            document.addEventListener('change', function (e) {
                var t = e.target;
                if (t && t.matches && t.matches('[data-ai-pick]')) {
                    var form = t.closest('.ai-models-form');
                    if (form) sync(form);
                }
            });
        }
    })();
    </script>
<?php require_once 'includes/admin-footer.php'; ?>
