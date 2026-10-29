<?php
/**
 * Assistant usage dashboard (.claude/AI_ASSISTANT_PLAN.md Phase 5) - gated by ai_usage_view (page map in
 * admin/includes/permissions.php). Read only: answers, cost per answer, the share answered without a model call
 * (quick lookups, the briefing, repeated questions), and totals by person, day and model. Costs are estimates
 * from the prices on Assistant Settings; the provider's bill is the real figure. Before migration 070 it shows
 * a plain "not set up" line.
 */

require_once 'admin-init.php';
/** @var array $user */
/** @var string $csrf_token */
/** @var PDO $pdo */

require_once __DIR__ . '/../includes/ai/ai-core.php';
require_once __DIR__ . '/../includes/ai/ai-memory.php';
require_once __DIR__ . '/../includes/ai/ai-extras.php';
require_once __DIR__ . '/../includes/ai/ai-router.php';
require_once __DIR__ . '/../includes/ai/ai-claims.php'; // claims corrected this month

$h = static function ($v): string {
    return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8');
};
$isAdmin = (string)($user['role'] ?? '') === 'admin';
// "Forget this" on a learned local answer (administrators only; the route is archived, never used again)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'forget_route') {
    if ($isAdmin && validateCsrfToken($_POST['csrf_token'] ?? '') && rh_ai_schema_ready($pdo)) {
        rh_ai_route_forget($pdo, (int)($_POST['route_id'] ?? 0));
    }
    header('Location: ai-usage.php#ai-usage-routes');
    exit;
}
$okDate = static fn($d) => is_string($d) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $d) && strtotime($d) !== false;
$from = $okDate($_GET['from'] ?? null) ? (string)$_GET['from'] : date('Y-m-01');
$to = $okDate($_GET['to'] ?? null) ? (string)$_GET['to'] : date('Y-m-d');
if ($to < $from) {
    [$from, $to] = [$to, $from];
}
if ((strtotime($to) - strtotime($from)) > 366 * 86400) {
    $from = date('Y-m-d', strtotime($to . ' -366 days'));
}
$ready = rh_ai_schema_ready($pdo);
$u = $ready ? rh_ai_usage_summary($pdo, $from, $to) : null;
$money = static fn($v) => $v === null ? '-' : '$' . number_format((float)$v, (float)$v > 0 && (float)$v < 0.01 ? 4 : 2);
$canSettings = hasPermission((int)$user['id'], 'ai_settings');
$ls = ($ready && $isAdmin) ? rh_ai_local_share($pdo, date('Y-m-01')) : null;
$pcs = ($ls !== null && function_exists('rh_ai_cache_saving_since')) ? rh_ai_cache_saving_since(date('Y-m-01')) : null;
$cs = ($ls !== null && function_exists('rh_ai_claim_stats')) ? rh_ai_claim_stats($pdo, date('Y-m-01')) : null;
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="csrf-token" content="<?php echo $h($csrf_token); ?>">
    <title>Assistant Usage — Admin</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Cormorant+Garamond:ital,wght@0,300;0,400;0,500;0,600;1,300;1,400;1,500&family=Jost:wght@300;400;500;600&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="css/admin-styles.css?v=<?php echo @filemtime(__DIR__ . '/css/admin-styles.css'); ?>">
    <link rel="stylesheet" href="css/admin-components.css?v=<?php echo @filemtime(__DIR__ . '/css/admin-components.css'); ?>">
    <link rel="stylesheet" href="css/ai-settings.css?v=<?php echo @filemtime(__DIR__ . '/css/ai-settings.css'); ?>">
</head>

<body>
    <?php require_once 'includes/admin-header.php'; ?>

    <div class="content ai-settings ai-usage">
        <header class="rh-page-head">
            <div class="rh-page-head__main">
                <div class="rh-page-head__title">
                    <h1>Assistant Usage</h1>
                </div>
            </div>
            <?php if ($canSettings): ?>
            <div class="rh-page-head__actions">
                <a href="ai-settings.php" class="back-link"><i class="fas fa-arrow-left"></i> Assistant Settings</a>
            </div>
            <?php endif; ?>
        </header>
        <p class="help-text ai-intro">How much the Assistant is used and what it costs. Costs are estimates from the model prices on Assistant Settings; your provider's bill is the real figure.</p>

        <?php if (!$ready || $u === null): ?>
            <div class="rh-panel ai-card" id="ai-usage-off"><div class="rh-panel__body"><p class="help-text">The Assistant has not been set up on this system yet, so there is no usage to show.</p></div></div>
        <?php else: ?>

        <form class="ai-usage-range" method="get" action="ai-usage.php">
            <label for="au-from">From</label>
            <input type="date" id="au-from" name="from" class="form-control" value="<?php echo $h($from); ?>">
            <label for="au-to">To</label>
            <input type="date" id="au-to" name="to" class="form-control" value="<?php echo $h($to); ?>">
            <button type="submit" class="btn btn-primary btn-sm">Show</button>
            <p class="help-text">Pick the days to count. Up to one year. The first card always shows this month.</p>
        </form>

        <div class="ai-usage-cards" id="ai-usage-cards">
            <div class="ai-stat">
                <span class="ai-stat__label">This month's estimated cost <i class="help" data-tip="Every Assistant reply since the 1st of this month, priced with the model prices on Assistant Settings, next to the monthly aim. Information only: nothing pauses or warns when the aim is passed.">?</i></span>
                <span class="ai-stat__value"><?php echo $h($money($u['month_cost'])); ?></span>
                <span class="ai-stat__sub"><?php echo $u['budget'] > 0 ? $h('monthly aim ' . $money($u['budget']) . ' (' . number_format((float)$u['budget_pct'], 1) . '% of it)') : 'No monthly aim set'; ?></span>
            </div>
            <div class="ai-stat">
                <span class="ai-stat__label">Answers <i class="help" data-tip="Replies the Assistant gave in the chosen days, including quick lookups and the daily briefing. Heavy answers used more than 8,000 input tokens: they are not stopped, only counted, so you can see if the Assistant is getting less efficient.">?</i></span>
                <span class="ai-stat__value"><?php echo $h(number_format($u['answers'])); ?></span>
                <span class="ai-stat__sub"><?php echo $h(number_format($u['model_answers'])); ?> needed a model; <?php echo $h(number_format((int)$u['heavy'])); ?> heavy (over 8,000 tokens)</span>
            </div>
            <div class="ai-stat">
                <span class="ai-stat__label">Cost per answer <i class="help" data-tip="Estimated cost in the chosen days divided by all answers in those days (answers given without a model cost nothing).">?</i></span>
                <span class="ai-stat__value"><?php echo $h($money($u['cost_per_answer'])); ?></span>
                <span class="ai-stat__sub"><?php echo $h($money($u['cost'])); ?> in total for these days</span>
            </div>
            <div class="ai-stat">
                <span class="ai-stat__label">Answered without a model <i class="help" data-tip="Share of answers given straight from the hotel's own data with no model call: quick lookups, the daily briefing and repeated questions. Higher is cheaper.">?</i></span>
                <span class="ai-stat__value"><?php echo $u['local_pct'] === null ? '-' : $h(number_format((float)$u['local_pct'], 1) . '%'); ?></span>
                <span class="ai-stat__sub"><?php echo $h(number_format($u['local'])); ?> of <?php echo $h(number_format($u['answers'])); ?> answers</span>
            </div>
            <div class="ai-stat">
                <span class="ai-stat__label">Automatic checks a month <i class="help" data-tip="Expected monthly cost of what the Assistant runs on its own: the 5 key questions each day and the full question set once a week (both on the Fast model), plus lessons and model checks. Worked out from what they cost in the last 4 weeks, scaled to 30 days.">?</i></span>
                <span class="ai-stat__value"><?php echo $u['auto_month'] === null ? '-' : $h($money($u['auto_month'])); ?></span>
                <span class="ai-stat__sub"><?php echo $u['auto_month'] === null ? 'No automatic checks have run yet' : $h('based on the last ' . (int)$u['auto_days'] . ' days'); ?></span>
            </div>
        </div>

        <?php if ($ls !== null): ?>
        <div class="rh-panel ai-card" id="ai-usage-routes">
            <div class="rh-panel__head"><h2 class="rh-panel__title">Answered locally this month <i class="help" data-tip="Administrators only. Answers given from the hotel's own data with no model call since the 1st of this month, and the question shapes the Assistant has learned to answer that way.">?</i></h2></div>
            <div class="rh-panel__body">
                <div class="ai-usage-cards">
                    <div class="ai-stat">
                        <span class="ai-stat__label">Local share <i class="help" data-tip="Answers with no model call divided by all answers this month (the automatic checks are left out).">?</i></span>
                        <span class="ai-stat__value"><?php echo $ls['share_pct'] === null ? '-' : $h(number_format((float)$ls['share_pct'], 1) . '%'); ?></span>
                        <span class="ai-stat__sub"><?php echo $h(number_format($ls['local'])); ?> local, <?php echo $h(number_format($ls['model'])); ?> by a model</span>
                    </div>
                    <div class="ai-stat">
                        <span class="ai-stat__label">Tokens saved <i class="help" data-tip="Local answers this month times the average tokens of a model answer this month: about what they would have used.">?</i></span>
                        <span class="ai-stat__value"><?php echo $h(number_format($ls['tokens_saved'])); ?></span>
                        <span class="ai-stat__sub"><?php echo $ls['avg_model_tokens'] > 0 ? $h('about ' . number_format($ls['avg_model_tokens']) . ' tokens a model answer') : 'No model answers yet this month'; ?></span>
                    </div>
                    <?php if ($pcs !== null): ?>
                    <div class="ai-stat">
                        <span class="ai-stat__label">Saved by repeated instructions <i class="help" data-tip="Part of every request (the Assistant's standing instructions) is read back by the provider at a tenth of the price. This is what that saved this month, from the provider's own usage figures.">?</i></span>
                        <span class="ai-stat__value"><?php echo $h('$' . number_format($pcs['usd'], 4)); ?></span>
                        <span class="ai-stat__sub"><?php echo $h(number_format($pcs['read'])); ?> tokens read back at the lower price</span>
                    </div>
                    <?php endif; ?>
                    <?php if ($cs !== null): ?>
                    <div class="ai-stat">
                        <span class="ai-stat__label">Claims corrected this month <i class="help" data-tip="Figures, names and dates in a model's reply that were not in the hotel's data, since the 1st of this month. Each was fixed before the reply showed: a side sentence dropped, the data's own value put in, or the answer rebuilt from the lookups. Asked again counts the few that needed the main model once more.">?</i></span>
                        <span class="ai-stat__value"><?php echo $h(number_format($cs['total'])); ?></span>
                        <span class="ai-stat__sub"><?php echo $cs['total'] > 0 ? $h($cs['a'] . ' dropped, ' . $cs['b'] . ' replaced, ' . $cs['c'] . ' rebuilt, ' . $cs['d'] . ' asked again') : 'None needed this month'; ?></span>
                    </div>
                    <?php endif; ?>
                </div>
                <h3 class="ai-subhead">Learned local answers <i class="help" data-tip="Question shapes the model answered the same way several times, now answered from the same lookup with no model call. A learned answer marked Not right, or failing a check, stops being used. Forget removes one for good.">?</i></h3>
                <?php if (!$ls['routes']): ?>
                    <p class="help-text">None learned yet. They appear once the same kind of question has been answered the same way in a few chats.</p>
                <?php else: ?>
                <div class="table-container"><div class="table-responsive">
                <table class="table ai-table">
                    <thead><tr>
                        <th>Question shape <i class="help" data-tip="An example of the questions it covers (names are never kept).">?</i></th>
                        <th>In use <i class="help" data-tip="Yes: used now. Retired: stopped after a Not right or a failed check.">?</i></th>
                        <th>Used <i class="help" data-tip="Times it answered a question.">?</i></th>
                        <th>Learned <i class="help" data-tip="The day it was learned.">?</i></th>
                        <th></th>
                    </tr></thead>
                    <tbody>
                    <?php foreach ($ls['routes'] as $rt): ?>
                        <tr>
                            <td><?php echo $h($rt['text']); ?></td>
                            <td><?php echo $rt['status'] === 'active' ? 'Yes' : 'Retired'; ?></td>
                            <td><?php echo $h(number_format((int)$rt['uses'])); ?></td>
                            <td><?php echo $h(date('j M Y', strtotime((string)$rt['created_at']))); ?></td>
                            <td>
                                <form method="post" action="ai-usage.php" class="ai-inline-form">
                                    <input type="hidden" name="csrf_token" value="<?php echo $h($csrf_token); ?>">
                                    <input type="hidden" name="action" value="forget_route">
                                    <input type="hidden" name="route_id" value="<?php echo (int)$rt['id']; ?>">
                                    <button type="submit" class="btn btn-secondary btn-sm">Forget this</button>
                                </form>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
                </div></div>
                <?php endif; ?>
            </div>
        </div>
        <?php endif; ?>

        <div class="rh-panel ai-card" id="ai-usage-people">
            <div class="rh-panel__head"><h2 class="rh-panel__title">By person <i class="help" data-tip="Each person's answers and estimated cost in the chosen days. Nightly checks are the Assistant testing itself.">?</i></h2></div>
            <div class="rh-panel__body">
                <?php if (!$u['people']): ?>
                    <p class="help-text">No use in these days.</p>
                <?php else: ?>
                <div class="table-container"><div class="table-responsive">
                <table class="table ai-table">
                    <thead><tr>
                        <th>Person</th>
                        <th>Answers <i class="help" data-tip="Replies this person got.">?</i></th>
                        <th>No model <i class="help" data-tip="Of those, answered from the hotel's own data with no model call.">?</i></th>
                        <th>Model requests <i class="help" data-tip="Calls sent to the provider (one question can need more than one).">?</i></th>
                        <th>Tokens <i class="help" data-tip="Tokens in and out together.">?</i></th>
                        <th>Est. cost <i class="help" data-tip="Tokens times the model prices on Assistant Settings, in US dollars.">?</i></th>
                        <th>Per answer <i class="help" data-tip="Estimated cost divided by this person's answers.">?</i></th>
                    </tr></thead>
                    <tbody>
                    <?php foreach ($u['people'] as $p): ?>
                        <tr>
                            <td><?php echo $h($p['person']); ?></td>
                            <td><?php echo $h(number_format($p['answers'])); ?></td>
                            <td><?php echo $h(number_format($p['local'])); ?></td>
                            <td><?php echo $h(number_format($p['requests'])); ?></td>
                            <td><?php echo $h(number_format($p['tokens'])); ?></td>
                            <td><?php echo $h($money($p['cost'])); ?></td>
                            <td><?php echo $h($money($p['cost_per_answer'])); ?></td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
                </div></div>
                <?php endif; ?>
            </div>
        </div>

        <div class="rh-panel ai-card" id="ai-usage-days">
            <div class="rh-panel__head"><h2 class="rh-panel__title">By day <i class="help" data-tip="Model requests, tokens and estimated cost per day (days with no model use are not listed).">?</i></h2></div>
            <div class="rh-panel__body">
                <?php if (!$u['days']): ?>
                    <p class="help-text">No model use in these days.</p>
                <?php else: ?>
                <div class="table-container"><div class="table-responsive">
                <table class="table ai-table">
                    <thead><tr>
                        <th>Day</th>
                        <th>Model requests <i class="help" data-tip="Calls sent to the provider that day.">?</i></th>
                        <th>Tokens <i class="help" data-tip="Tokens in and out together.">?</i></th>
                        <th>Est. cost <i class="help" data-tip="Estimated cost that day in US dollars.">?</i></th>
                    </tr></thead>
                    <tbody>
                    <?php foreach ($u['days'] as $d): ?>
                        <tr>
                            <td><?php echo $h(date('D j M Y', strtotime((string)$d['day']))); ?></td>
                            <td><?php echo $h(number_format((int)$d['requests'])); ?></td>
                            <td><?php echo $h(number_format((int)$d['tokens'])); ?></td>
                            <td><?php echo $h($money($d['cost'])); ?></td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
                </div></div>
                <?php endif; ?>
            </div>
        </div>

        <div class="rh-panel ai-card" id="ai-usage-models">
            <div class="rh-panel__head"><h2 class="rh-panel__title">By model <i class="help" data-tip="Which models did the work. The Fast model should carry most short answers; the Primary model only the harder questions.">?</i></h2></div>
            <div class="rh-panel__body">
                <?php if (!$u['models']): ?>
                    <p class="help-text">No model use in these days.</p>
                <?php else: ?>
                <div class="table-container"><div class="table-responsive">
                <table class="table ai-table">
                    <thead><tr>
                        <th>Provider</th>
                        <th>Model</th>
                        <th>Requests <i class="help" data-tip="Calls sent to this model.">?</i></th>
                        <th>Tokens in <i class="help" data-tip="Tokens sent: questions plus the hotel facts each answer needed.">?</i></th>
                        <th>Tokens out <i class="help" data-tip="Tokens the model wrote back.">?</i></th>
                        <th>Est. cost <i class="help" data-tip="Tokens times this model's prices, in US dollars.">?</i></th>
                    </tr></thead>
                    <tbody>
                    <?php foreach ($u['models'] as $m): ?>
                        <tr>
                            <td><?php echo $h($m['provider']); ?></td>
                            <td><code class="ai-id"><?php echo $h($m['model_id']); ?></code></td>
                            <td><?php echo $h(number_format((int)$m['requests'])); ?></td>
                            <td><?php echo $h(number_format((int)$m['tokens_in'])); ?></td>
                            <td><?php echo $h(number_format((int)$m['tokens_out'])); ?></td>
                            <td><?php echo $h($money($m['cost'])); ?></td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
                </div></div>
                <?php endif; ?>
            </div>
        </div>
        <?php endif; ?>
    </div>
<?php require_once 'includes/admin-footer.php'; ?>
