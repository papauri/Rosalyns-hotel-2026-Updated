<?php
/**
 * The in-page "Decisions" panel (.claude/AI_REVIEW_PANELS_PARKED.md, Phase 1: the shared render helper).
 *
 *   echo rh_review_panel('stock');   // or 'accounting' / 'bookings'
 *
 * Returns '' - no markup, no CSS or JS link, no request, no cache read - unless ALL of these hold, checked in this
 * order: the Assistant master switch is on (rh_ai_enabled(), fail-closed, the one query made when it is off), this
 * person holds ai_assistant_use, they hold one of the area's permissions, one of the area's modules is on, and
 * migration 075 has run. Same fail-closed pattern as admin/includes/ai-panel.php. Any error also gives ''. After the
 * gate it is also '' (owner 2026-11-05) when no model is set up or reachable, or today's figures have no good review
 * (worded by a call that worked): never templated cards, never a note.
 * Including this file prints nothing.
 *
 * Plain look on the existing admin tokens (admin/css/review-panel.css): a normal card titled "Decisions", every
 * figure and column with a plain helper line, links to the screen that acts. Nothing changes by itself.
 */

/** The areas, their permissions (any one) and modules (any one). Shared with the engine (includes/ai/ai-decisions.php). */
function rh_review_areas(): array
{
    return [
        'bookings'   => ['label' => 'Bookings', 'perm' => ['bookings'], 'module' => ['bookings']],
        'accounting' => ['label' => 'Accounting', 'perm' => ['accounting', 'reports'], 'module' => ['finance']],
        'stock'      => ['label' => 'Stock', 'perm' => ['stock_dashboard', 'stock_management'], 'module' => ['stock']],
    ];
}

/** A module switch, read without loading the Assistant library (tests may force one through the shared override). */
function rh_review_module_on(string $m): bool
{
    if (isset($GLOBALS['__rh_ai_module_override'][$m])) {
        return (bool)$GLOBALS['__rh_ai_module_override'][$m];
    }
    return function_exists('rh_module_key_enabled') && rh_module_key_enabled($m);
}

/** The gate alone (true = the panel may be built for this person). Cheapest check first; never throws. */
function rh_review_gate(string $area, ?PDO $db = null, int $uid = 0): bool
{
    try {
        $db = $db ?? ($GLOBALS['pdo'] ?? null);
        $uid = $uid > 0 ? $uid : (int)($_SESSION['admin_user_id'] ?? 0);
        $a = rh_review_areas()[$area] ?? null;
        if (!$db instanceof PDO || $uid <= 0 || $a === null) {
            return false;
        }
        require_once __DIR__ . '/../../includes/ai/ai-core.php';
        if (!rh_ai_enabled($db)) {
            return false;
        }
        if (!function_exists('hasPermission')) {
            require_once __DIR__ . '/permissions.php';
        }
        if (!hasPermission($uid, 'ai_assistant_use')) {
            return false;
        }
        $ok = false;
        foreach ($a['perm'] as $p) {
            if (hasPermission($uid, $p)) {
                $ok = true;
                break;
            }
        }
        if (!$ok) {
            return false;
        }
        $on = false;
        foreach ($a['module'] as $m) {
            if (rh_review_module_on($m)) {
                $on = true;
                break;
            }
        }
        return $on;
    } catch (Throwable $e) {
        return false;
    }
}

/** The panel for this person, or '' (see the file header). */
function rh_review_panel(string $area): string
{
    $db = $GLOBALS['pdo'] ?? null;
    $uid = (int)($_SESSION['admin_user_id'] ?? 0);
    if (!rh_review_gate($area, $db, $uid)) {
        return '';
    }
    try {
        require_once __DIR__ . '/../../includes/ai/ai-decisions.php';
        if (!rh_dec_ready($db)) {
            return '';
        }
        $user = ['id' => $uid, 'role' => (string)($_SESSION['admin_role'] ?? ''), 'username' => (string)($_SESSION['admin_username'] ?? ''), 'full_name' => ''];
        $v = rh_dec_for_viewer($db, $user, $area);
        if ($v === null || empty($v['main'])) { // no worded decision = nothing printed
            return '';
        }
        $csrf = function_exists('generateCsrfToken') ? (string)generateCsrfToken() : (string)($_SESSION['csrf_token'] ?? '');
        return rh_review_panel_html($v, $csrf);
    } catch (Throwable $e) {
        error_log('Decisions panel skipped: ' . $e->getMessage());
        return '';
    }
}

function rh_review_conf_label(float $c): string
{
    return $c >= 0.75 ? 'High' : ($c >= 0.5 ? 'Medium' : 'Low');
}

function rh_review_tier_text(int $tier): string
{
    return [1 => 'You can do this straight away.', 2 => 'Needs one approval, with an exact preview first.', 3 => 'A manager decides on the screen; never done automatically.'][$tier] ?? '';
}

/** The markup for one area as this person sees it ($v from rh_dec_for_viewer). */
function rh_review_panel_html(array $v, string $csrf): string
{
    static $assets = false;
    $h = static fn($s): string => htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8');
    $cur = (string)$v['cur'];
    $money = static fn($x): string => $x === null ? '' : rh_dec_fmt($cur, (float)$x);
    $area = (string)$v['area'];
    $base = (array)$v['base'];
    $days = (int)($base['days'] ?? 30);
    $hist = rh_dec_num('ai_review_history_min_days', 1, 3650);
    $checks = (array)$v['checks'];
    $nChecks = count($checks);
    $out = '';
    if (!$assets) {
        $assets = true;
        $cssV = (int)@filemtime(__DIR__ . '/../css/review-panel.css');
        $jsV = (int)@filemtime(__DIR__ . '/../js/review-panel.js');
        $out .= '<link rel="stylesheet" href="css/review-panel.css?v=' . $cssV . '">';
        $out .= '<script src="js/review-panel.js?v=' . $jsV . '" defer></script>';
    }
    $id = 'rhDec-' . $h($area);
    $out .= '<section class="rh-dec" id="' . $id . '" data-area="' . $h($area) . '" data-endpoint="api/decisions.php" data-csrf="' . $h($csrf) . '" aria-labelledby="' . $id . '-title">';
    $out .= '<header class="rh-dec__head"><div><h2 class="rh-dec__title" id="' . $id . '-title">Decisions</h2>';
    $out .= '<p class="rh-dec__help">The few choices with the most money at stake right now, worked out from today\'s figures. Each one links to the screen where you act; nothing changes by itself.</p></div>';
    $out .= '<div class="rh-dec__tools"><button type="button" class="btn btn-secondary btn-sm" data-dec="refresh">Refresh</button>';
    $out .= '<p class="rh-dec__help">Works the figures out again now (at most once every ' . (int)rh_dec_num('ai_review_refresh_minutes', 1, 1440) . ' minutes).</p></div></header>';
    $built = strtotime((string)$v['built_at']);
    $baseTxt = !empty($base['ok']) && (float)($base['amount'] ?? 0) > BALANCE_TOLERANCE
        ? $money($base['amount']) . ' collected in the last ' . $days . ' days'
        : 'no money collected in the last ' . $days . ' days to compare with';
    $out .= '<p class="rh-dec__meta">Worked out ' . ($built ? 'at ' . $h(date('H:i', $built)) . ' ' : '') . 'from ' . $nChecks . ' check' . ($nChecks === 1 ? '' : 's')
        . '. A decision shows here when its money at stake is at least ' . $h(rtrim(rtrim(number_format((float)$v['threshold_pct'], 2), '0'), '.')) . '% of the money collected (' . $h($baseTxt) . ').</p>';
    if ((int)$v['checks_failed'] > 0) {
        $out .= '<p class="rh-dec__warn" role="status">' . (int)$v['checks_failed'] . ' of ' . $nChecks . ' checks could not be read in full just now, so this list may be incomplete.</p>';
    }
    $out .= '<ol class="rh-dec__list">';
    foreach ($v['main'] as $c) {
        $out .= rh_review_card_html($c, $money, $h, $days, $hist);
    }
    $out .= '</ol>';
    if ($v['more']) {
        $out .= '<details class="rh-dec__more"><summary>More (' . count($v['more']) . ' below the threshold)</summary>';
        $out .= '<p class="rh-dec__help">Smaller items found by the same checks, largest first.</p><ul class="rh-dec__morelist">';
        foreach ($v['more'] as $c) {
            $out .= '<li><span class="rh-dec__moretitle">' . $h($c['decision']) . '</span>';
            $out .= $c['at_stake'] !== null ? ' <span class="rh-dec__money">' . $h($money($c['at_stake'])) . '</span>' : '';
            $out .= !empty($c['link']) ? ' <a href="' . $h($c['link']['url']) . '">' . $h($c['link']['label']) . '</a>' : '';
            $out .= '</li>';
        }
        $out .= '</ul></details>';
    }
    $out .= '</section>';
    return $out;
}

/** One decision card. */
function rh_review_card_html(array $c, callable $money, callable $h, int $days, int $hist): string
{
    $o = '<li class="rh-dec-card" data-key="' . $h($c['key']) . '">';
    $o .= '<h3 class="rh-dec-card__title">' . $h($c['decision']) . '</h3>';
    $o .= '<p class="rh-dec-card__why">' . $h($c['why_now']) . '</p>';
    $o .= '<dl class="rh-dec-card__figs">';
    $o .= '<div><dt>At stake</dt><dd class="rh-dec__money">' . ($c['at_stake'] !== null ? $h($money($c['at_stake'])) : 'Not valued') . '</dd><dd class="rh-dec__help">' . $h($c['at_stake_note']) . '</dd></div>';
    $o .= '<div><dt>Share of takings</dt><dd>' . ($c['share_pct'] !== null ? $h(number_format((float)$c['share_pct'], 1)) . '%' : 'Not worked out') . '</dd><dd class="rh-dec__help">'
        . ($c['share_pct'] !== null ? 'At stake as a share of the money collected in the last ' . $days . ' days.' : 'There is no money collected in the last ' . $days . ' days to compare with.') . '</dd></div>';
    $o .= '<div><dt>Confidence</dt><dd>' . rh_review_conf_label((float)$c['confidence']) . '</dd><dd class="rh-dec__help">How sure the figures are, from how complete the data is'
        . ((int)$c['history_days'] > 0 ? ' and ' . (int)$c['history_days'] . ' days of history' : '') . '.</dd></div>';
    $o .= '</dl>';
    if (!empty($c['options'])) {
        $o .= '<div class="rh-dec-card__opts"><table class="rh-dec__table"><thead><tr>';
        $o .= '<th scope="col">Option<span class="rh-dec__help">What you could do.</span></th>';
        $o .= '<th scope="col">Best<span class="rh-dec__help">The most it could bring in or save.</span></th>';
        $o .= '<th scope="col">Expected<span class="rh-dec__help">The likely result from the hotel\'s own history; blank until there are ' . $hist . ' days of it.</span></th>';
        $o .= '<th scope="col">Worst<span class="rh-dec__help">The least it could bring (below zero is a loss).</span></th>';
        $o .= '<th scope="col">Confidence<span class="rh-dec__help">How sure, from the history used.</span></th>';
        $o .= '</tr></thead><tbody>';
        foreach ($c['options'] as $i => $op) {
            $rec = (int)$c['recommend'] === (int)$i;
            $o .= '<tr' . ($rec ? ' class="is-recommended"' : '') . '><th scope="row">' . $h($op['label']) . ($rec ? ' <span class="rh-dec__tag">Recommended</span>' : '')
                . '<span class="rh-dec__help">' . $h($op['effect']) . '</span></th>';
            foreach (['best', 'expected', 'worst'] as $k) {
                $o .= '<td>' . ($op[$k] !== null ? $h($money($op[$k])) : '<span class="rh-dec__none">Not enough history</span>') . '</td>';
            }
            $o .= '<td>' . rh_review_conf_label((float)$op['confidence']) . '</td></tr>';
        }
        $o .= '</tbody></table></div>';
    }
    $o .= '<p class="rh-dec-card__rec"><strong>Recommended:</strong> ' . $h($c['recommendation']) . '</p>';
    $o .= '<p class="rh-dec__help">' . $h(rh_review_tier_text((int)$c['tier'])) . '</p>';
    $ev = (array)($c['evidence'] ?? []);
    $rows = (array)($ev['rows'] ?? []);
    if ($rows) {
        $o .= '<details class="rh-dec-card__ev"><summary>Evidence (' . count($rows) . ' row' . (count($rows) === 1 ? '' : 's') . '; ' . (int)($ev['total'] ?? 0) . ' ' . $h($ev['total_label'] ?? 'checked') . ')</summary>';
        $o .= '<table class="rh-dec__table"><thead><tr><th scope="col">Item<span class="rh-dec__help">What was found.</span></th><th scope="col">Reference<span class="rh-dec__help">The record to open.</span></th>'
            . '<th scope="col">Who<span class="rh-dec__help">Guest or staff on the record.</span></th><th scope="col">Figure<span class="rh-dec__help">Count or amount, as on the screen.</span></th></tr></thead><tbody>';
        foreach ($rows as $r) {
            $who = trim((string)($r['guest_name'] ?? '') . ' ' . (string)($r['staff_name'] ?? ''));
            $fig = isset($r['amount']) && $r['amount'] !== null ? $money($r['amount']) : (string)($r['value'] ?? '');
            if (isset($r['amount'], $r['value']) && $r['amount'] !== null && (string)$r['value'] !== '') {
                $fig = (string)$r['value'] . ' · ' . $money($r['amount']);
            }
            $o .= '<tr><td>' . $h($r['label'] ?? '') . '</td><td>' . $h($r['reference'] ?? '') . '</td><td>' . $h($who) . '</td><td>' . $h($fig) . '</td></tr>';
        }
        $o .= '</tbody></table></details>';
    }
    $o .= '<div class="rh-dec-card__btns">';
    if (!empty($c['link'])) {
        $o .= '<a class="btn btn-primary btn-sm" href="' . $h($c['link']['url']) . '">' . $h($c['link']['label']) . '</a>';
    }
    $o .= '<button type="button" class="btn btn-secondary btn-sm" data-dec="note" data-key="' . $h($c['key']) . '" data-what="noted">Noted</button>';
    $o .= '<button type="button" class="btn btn-secondary btn-sm" data-dec="note" data-key="' . $h($c['key']) . '" data-what="dismissed">Not now</button>';
    $o .= '<span class="rh-dec-card__state" role="status"></span></div>';
    $o .= '<p class="rh-dec__help">Noted and Not now are kept on record with your name, so the follow-up can be checked later.</p>';
    $o .= '</li>';
    return $o;
}
