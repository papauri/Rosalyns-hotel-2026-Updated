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
 * Plain look on the existing admin tokens (admin/css/review-panel.css): closed by default, one small "Decisions" tab
 * (with the number waiting) opens a side drawer titled "Decisions". Each card reads top to bottom: the decision and the
 * money involved, one quiet line of facts, why now, what we suggest with the link to the screen that acts, then the
 * other choices and where it comes from (closed; one plain key line per table, a hover title on every column).
 * Updating is automatic (after the page loads, and the daily job); there is no button for it. Nothing changes by itself.
 * Owner 2026-11-06 (no repeated work): when today's figures are older than ai_review_stale_minutes the page asks, after
 * it has loaded, for them to be worked out again (SQL only); a card someone has already dealt with goes from the drawer
 * then, and "Updated at" and the tab's number follow the new figures.
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
        $csrf = function_exists('generateCsrfToken') ? (string)generateCsrfToken() : (string)($_SESSION['csrf_token'] ?? '');
        if ($v === null || empty($v['main'])) { // no worded decision = nothing shown
            // Figures older than ai_review_stale_minutes, or cards not worded yet (model set up, no call tried lately):
            // an empty, hidden hook asks for the update once the page has loaded; the panel appears only if a card
            // comes back worded by a call that worked. Otherwise ''.
            return rh_dec_pending($db, $user, $area) ? rh_review_pending_html($area, $csrf) : '';
        }
        // The same update for a panel already shown: its content is swapped in after the page has loaded (resolved
        // cards go, reworded cards come back), never holding up the page
        return rh_review_panel_html($v, $csrf, rh_dec_pending($db, $user, $area));
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
    return [1 => 'You can do this now', 2 => 'Needs a manager\'s OK first', 3 => 'A manager decides this on the screen'][$tier] ?? '';
}

/** A column header: the plain name, with what it shows as its hover title. */
function rh_review_th(string $label, string $title, string $cls = ''): string
{
    $h = static fn($s): string => htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8');
    return '<th scope="col" class="no-sort' . ($cls !== '' ? ' ' . $h($cls) : '') . '" title="' . $h($title) . '">' . $h($label) . '</th>';
}

/** The panel's CSS and JS links, once per page. */
function rh_review_assets(): string
{
    static $done = false;
    if ($done) {
        return '';
    }
    $done = true;
    $cssV = (int)@filemtime(__DIR__ . '/../css/review-panel.css');
    $jsV = (int)@filemtime(__DIR__ . '/../js/review-panel.js');
    return '<link rel="stylesheet" href="css/review-panel.css?v=' . $cssV . '"><script src="js/review-panel.js?v=' . $jsV . '" defer></script>';
}

/** The hidden hook that asks for the first wording after the page has loaded (no text, no box, nothing visible). */
function rh_review_pending_html(string $area, string $csrf): string
{
    $h = static fn($s): string => htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8');
    return rh_review_assets() . '<div class="rh-dec-pending" data-dec-pending data-area="' . $h($area) . '" data-endpoint="api/decisions.php" data-csrf="' . $h($csrf) . '" hidden></div>';
}

/** The markup for one area as this person sees it ($v from rh_dec_for_viewer). $update = ask for an update after load. */
function rh_review_panel_html(array $v, string $csrf, bool $update = false): string
{
    $h = static fn($s): string => htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8');
    $cur = (string)$v['cur'];
    $money = static fn($x): string => $x === null ? '' : rh_dec_fmt($cur, (float)$x);
    $area = (string)$v['area'];
    $base = (array)$v['base'];
    $days = (int)($base['days'] ?? 30);
    $hist = rh_dec_num('ai_review_history_min_days', 1, 3650);
    $out = rh_review_assets();
    $id = 'rhDec-' . $h($area);
    // Closed by default (owner 2026-11-05): only a small "Decisions" tab shows; the panel opens in a side drawer on click.
    // Every part is position: fixed, so the page itself looks and lays out exactly as without it.
    $nMain = count((array)$v['main']);
    $did = 'rhDecDrawer-' . $h($area);
    $out .= '<div class="rh-dec-wrap" id="rhDecWrap-' . $h($area) . '"' . ($update ? ' data-dec-update' : '') . '>';
    $out .= '<button type="button" class="rh-dec-tab" data-dec-open aria-expanded="false" aria-controls="' . $did . '" aria-label="Decisions, ' . $nMain . ' waiting">Decisions<span class="rh-dec-tab__n" data-dec-count>' . $nMain . '</span></button>';
    $out .= '<div class="rh-dec-backdrop" data-dec-close></div>';
    $out .= '<aside class="rh-dec-drawer" id="' . $did . '" role="dialog" aria-modal="true" aria-labelledby="' . $id . '-title" tabindex="-1">';
    $out .= '<div class="rh-dec-drawer__bar"><button type="button" class="btn btn-secondary btn-sm" data-dec-close>Close</button></div><div class="rh-dec-drawer__body">';
    $out .= '<section class="rh-dec" id="' . $id . '" data-area="' . $h($area) . '" data-endpoint="api/decisions.php" data-csrf="' . $h($csrf) . '" aria-labelledby="' . $id . '-title">';
    // Header (owner 2026-11-05): the title, when it was last updated and how many, one short line. Updating is automatic
    // (after the page loads and the daily job), so there is no button for it; the threshold is explained in the guide.
    $built = strtotime((string)$v['built_at']);
    $metaTxt = ($built ? 'Updated at ' . date('H:i', $built) . ' · ' : '') . $nMain . ' decision' . ($nMain === 1 ? '' : 's');
    $out .= '<header class="rh-dec__head"><div class="rh-dec__headrow"><h2 class="rh-dec__title" id="' . $id . '-title">Decisions</h2>';
    $out .= '<p class="rh-dec__meta">' . $h($metaTxt) . '</p></div>';
    $out .= '<p class="rh-dec__sub">What needs deciding now, the most money first.</p></header>';
    if ((int)$v['checks_failed'] > 0) {
        $out .= '<p class="rh-dec__warn" role="status">Some figures could not be read just now, so this list may be missing something.</p>';
    }
    // The top cards open; any further cards and the smaller items sit under one closed "More decisions (n)".
    $top = array_slice((array)$v['main'], 0, 3);
    $rest = array_slice((array)$v['main'], 3);
    $out .= '<ol class="rh-dec__list">';
    foreach ($top as $c) {
        $out .= rh_review_card_html($c, $money, $h, $days, $hist);
    }
    $out .= '</ol>';
    $nMore = count($rest) + count((array)$v['more']);
    if ($nMore > 0) {
        $out .= '<details class="rh-dec__more"><summary>More decisions (' . $nMore . ')</summary>';
        if ($rest) {
            $out .= '<ol class="rh-dec__list">';
            foreach ($rest as $c) {
                $out .= rh_review_card_html($c, $money, $h, $days, $hist);
            }
            $out .= '</ol>';
        }
        if ($v['more']) {
            $out .= '<ul class="rh-dec__morelist">';
            foreach ($v['more'] as $c) {
                $out .= '<li><span class="rh-dec__moretitle">' . $h($c['decision']) . '</span>';
                $out .= $c['at_stake'] !== null ? '<span class="rh-dec__money">' . $h($money($c['at_stake'])) . '</span>' : '<span class="rh-dec__none">No money figure</span>';
                $out .= !empty($c['link']) ? '<a href="' . $h($c['link']['url']) . '">' . $h($c['link']['label']) . '</a>' : '';
                $out .= '</li>';
            }
            $out .= '</ul>';
        }
        $out .= '</details>';
    }
    $out .= '</section></div></aside></div>';
    return $out;
}

/**
 * One decision card, in a fixed order (owner 2026-11-05): the decision and the money involved; one quiet line of facts;
 * why now; what we suggest with the button to the screen that acts; the other choices and where it comes from (both
 * closed); Noted / Not now.
 */
function rh_review_card_html(array $c, callable $money, callable $h, int $days, int $hist): string
{
    // No minus signs on the card face (owner 2026-11-05): a money figure below zero reads "MWK 150,000.00 short" (or "loss").
    $signed = static fn($x, string $word = 'short'): string => (float)$x <= -BALANCE_TOLERANCE ? $money(abs((float)$x)) . ' ' . $word : $money(max(0.0, (float)$x));
    $measure = trim((string)($c['measure'] ?? ''));
    // A card with its own measure (e.g. the money position) runs short; by default a choice below zero loses money.
    $below = $measure !== '' ? 'short' : 'loss';
    $o = '<li class="rh-dec-card" data-key="' . $h($c['key']) . '">';
    // a. The decision and the money involved
    $o .= '<div class="rh-dec-card__head"><h3 class="rh-dec-card__title">' . $h($c['decision']) . '</h3><p class="rh-dec-card__stake">';
    $o .= $c['at_stake'] !== null
        ? '<span class="rh-dec-card__amt">' . $h($signed($c['at_stake'])) . '</span><span class="rh-dec-card__amtlbl">money involved</span>'
        : '<span class="rh-dec-card__amt rh-dec__none">No money figure</span>';
    $o .= '</p></div>';
    // b. One quiet line of facts
    $o .= '<ul class="rh-dec-card__facts">';
    if ($c['share_pct'] !== null) {
        $o .= '<li title="' . $h('The money involved compared with all the money taken in the last ' . $days . ' days.') . '">'
            . $h(number_format((float)$c['share_pct'], 1) . '% of the last ' . $days . ' days\' takings') . '</li>';
    }
    $o .= '<li title="' . $h('How complete the hotel\'s records behind these figures are.') . '">How sure: ' . $h(rh_review_conf_label((float)$c['confidence'])) . '</li>';
    $tier = rh_review_tier_text((int)$c['tier']);
    if ($tier !== '') {
        $o .= '<li>' . $h($tier) . '</li>';
    }
    $o .= '</ul>';
    // c. Why now
    if (trim((string)$c['why_now']) !== '') {
        $o .= '<p class="rh-dec-card__why">' . $h($c['why_now']) . '</p>';
    }
    // d. What we suggest: the suggested step, its likely result in one line, and the button to the screen that acts
    $opts = (array)($c['options'] ?? []);
    $recI = (int)$c['recommend'];
    $rec = $opts[$recI] ?? null;
    $recText = trim((string)$c['recommendation']) !== '' ? (string)$c['recommendation'] : (string)($rec['label'] ?? '');
    $result = '';
    if ($rec !== null) {
        // The option's own plain result line when it has one; otherwise the likely figure or what the option does
        $result = trim((string)($rec['result'] ?? '')) !== '' ? (string)$rec['result'] : ($rec['expected'] !== null
            ? 'Most likely: ' . $signed($rec['expected'], $below) . ($measure !== '' ? ' (' . $measure . ')' : '')
            : (string)($rec['effect'] ?? ''));
    }
    $o .= '<div class="rh-dec-card__rec"><div class="rh-dec-card__recbody"><p class="rh-dec-card__reclbl">What we suggest</p>';
    $o .= '<p class="rh-dec-card__rectext">' . $h($recText) . '</p>';
    $o .= trim($result) !== '' ? '<p class="rh-dec-card__recres">' . $h($result) . '</p>' : '';
    $o .= '</div>';
    if (!empty($c['link'])) {
        $o .= '<a class="btn btn-primary btn-sm" href="' . $h($c['link']['url']) . '">' . $h($c['link']['label']) . '</a>';
    }
    $o .= '</div>';
    // e. Other choices (closed): one row per choice, the suggested one marked; one short key under the table
    if ($opts) {
        $others = count($opts) - ($rec !== null ? 1 : 0);
        $early = false;
        $o .= '<details class="rh-dec-card__opts"><summary>' . ($others > 0 ? 'Other choices (' . $others . ')' : 'About this choice') . '</summary>';
        $o .= '<div class="rh-dec__scroll"><table class="rh-dec__table no-card-mobile"><thead><tr>';
        $o .= rh_review_th('Choice', 'What you could do.');
        $o .= rh_review_th('If it goes well', 'The best result this choice could give.', 'is-num');
        $o .= rh_review_th('Most likely', 'What usually happens, from the hotel\'s own records.', 'is-num');
        $o .= rh_review_th('If it goes badly', 'The worst result this choice could give. "' . ucfirst($below) . '" means ' . ($below === 'short' ? 'money would run short by that much.' : 'money lost.'), 'is-num');
        $o .= rh_review_th('How sure', 'How far the hotel\'s records back up this result.');
        $o .= '</tr></thead><tbody>';
        foreach ($opts as $i => $op) {
            $isRec = $recI === (int)$i;
            $o .= '<tr' . ($isRec ? ' class="is-recommended"' : '') . '><th scope="row">' . $h($op['label']) . ($isRec ? ' <span class="rh-dec__tag">Suggested</span>' : '')
                . (trim((string)($op['effect'] ?? '')) !== '' ? '<span class="rh-dec__sub">' . $h($op['effect']) . '</span>' : '') . '</th>';
            foreach (['best', 'expected', 'worst'] as $k) {
                if ($op[$k] !== null) {
                    // The amount never breaks; the word may go on the next line so the table fits the drawer
                    $neg = (float)$op[$k] <= -BALANCE_TOLERANCE;
                    $o .= '<td class="is-num"><span class="rh-dec__amt">' . $h($money($neg ? abs((float)$op[$k]) : max(0.0, (float)$op[$k]))) . '</span>' . ($neg ? ' ' . $h($below) : '') . '</td>';
                } else {
                    $early = $early || empty($op['not_valued']);
                    $o .= '<td class="is-num"><span class="rh-dec__none">' . (!empty($op['not_valued']) ? 'No money figure' : 'Too early to tell') . '</span></td>';
                }
            }
            $o .= '<td>' . $h(rh_review_conf_label((float)$op['confidence'])) . '</td></tr>';
        }
        $o .= '</tbody></table></div>';
        $o .= '<p class="rh-dec__legend">' . $h('The figures show ' . ($measure !== '' ? $measure : 'the money each choice could bring in or save')
            . ($early ? '; "Too early to tell" means fewer than ' . $hist . ' days of records so far.' : '.')) . '</p>';
        $o .= '</details>';
    }
    // f. Where this comes from (closed): how the money figure was worked out, then the records behind it
    $ev = (array)($c['evidence'] ?? []);
    $rows = (array)($ev['rows'] ?? []);
    $note = trim((string)($c['at_stake_note'] ?? ''));
    if ($rows || $note !== '') {
        $o .= '<details class="rh-dec-card__ev"><summary>Where this comes from' . ($rows ? ' (' . count($rows) . ')' : '') . '</summary>';
        $o .= $note !== '' ? '<p class="rh-dec__note">' . $h($note) . '</p>' : '';
        if ($rows) {
            $o .= '<div class="rh-dec__scroll"><table class="rh-dec__table no-card-mobile"><thead><tr>';
            $o .= rh_review_th('Item', 'What was found.') . rh_review_th('Reference', 'The record to open.')
                . rh_review_th('Who', 'The guest or staff member on the record.') . rh_review_th('Figure', 'The count or amount, as on its own screen.', 'is-num');
            $o .= '</tr></thead><tbody>';
            foreach ($rows as $r) {
                $who = trim((string)($r['guest_name'] ?? '') . ' ' . (string)($r['staff_name'] ?? ''));
                $fig = isset($r['amount']) && $r['amount'] !== null ? $signed($r['amount']) : (string)($r['value'] ?? '');
                if (isset($r['amount'], $r['value']) && $r['amount'] !== null && (string)$r['value'] !== '') {
                    $fig = (string)$r['value'] . ' · ' . $signed($r['amount']);
                }
                $o .= '<tr><td>' . $h($r['label'] ?? '') . '</td><td>' . $h($r['reference'] ?? '') . '</td><td>' . $h($who) . '</td><td class="is-num">' . $h($fig) . '</td></tr>';
            }
            $o .= '</tbody></table></div>';
            $total = (int)($ev['total'] ?? 0);
            $tl = trim((string)($ev['total_label'] ?? ''));
            $o .= '<p class="rh-dec__legend">' . $h('Showing ' . count($rows) . ($total > count($rows) ? ' of ' . $total . ($tl !== '' ? ' ' . $tl : '') : '') . '.') . '</p>';
        }
        $o .= '</details>';
    }
    // g. Noted / Not now, kept on record with the person's name (said in the buttons' hover title)
    $keep = 'Kept on record with your name, so the follow-up can be checked later.';
    $o .= '<div class="rh-dec-card__btns">';
    $o .= '<button type="button" class="btn btn-secondary btn-sm" data-dec="note" data-key="' . $h($c['key']) . '" data-what="noted" title="' . $h($keep) . '">Noted</button>';
    $o .= '<button type="button" class="btn btn-secondary btn-sm" data-dec="note" data-key="' . $h($c['key']) . '" data-what="dismissed" title="' . $h($keep) . '">Not now</button>';
    $o .= '</div><p class="rh-dec-card__state" role="status"></p>';
    $o .= '</li>';
    return $o;
}
