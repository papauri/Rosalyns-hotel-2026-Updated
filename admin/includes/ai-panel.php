<?php
/**
 * The Assistant panel (.claude/AI_ASSISTANT_PLAN.md Phases 2-4b: chat, the daily briefing, the status line, action cards,
 * Helpful / Not right with corrections). Required once from admin-header.php, right after the help button and OUTSIDE
 * #rh-admin-page, so the chat survives in-app navigation.
 *
 * Three parts, each wrapped so nothing here can break or slow an admin page:
 *  1. Notices for the people who manage Assistant Settings (it paused itself, a lookup was switched off, a model changed):
 *     a small plain card, shown even while the Assistant is off or paused. Costs one settings read for everyone.
 *  2. The daily checks (no cron needed): on the first admin page load of the day by ANY signed-in account, when the
 *     Assistant is on, they are scheduled to run after the page has been sent (includes/ai/ai-selfheal.php).
 *  3. The panel itself: prints nothing unless the Assistant is switched on (fail-closed kill switch, migrations 070 + 071
 *     run) and this person holds ai_assistant_use. Plain "Assistant" button with fa-comments; every part has a plain
 *     helper line; existing admin design tokens only (admin/css/ai-panel.css).
 */
if (defined('RH_AI_PANEL_RENDERED')) {
    return;
}
define('RH_AI_PANEL_RENDERED', true);

(static function (): void {
    $db = $GLOBALS['pdo'] ?? null;
    $uid = (int)($_SESSION['admin_user_id'] ?? 0);
    if (!$db instanceof PDO || $uid <= 0) {
        return;
    }
    require_once __DIR__ . '/../../includes/ai/ai-core.php';
    $cssV = @filemtime(__DIR__ . '/../css/ai-panel.css');
    $cssPrinted = false;

    // 1. Notices for the people who manage Assistant Settings (never shown to anyone else)
    try {
        $raw = rh_ai_settings($db)['ai_notices_json'] ?? '';
        if ($raw !== '' && basename((string)($_SERVER['SCRIPT_NAME'] ?? '')) !== 'ai-settings.php') {
            if (!function_exists('hasPermission')) {
                require_once __DIR__ . '/permissions.php';
            }
            if (hasPermission($uid, 'ai_settings')) {
                require_once __DIR__ . '/../../includes/ai/ai-memory.php';
                $unseen = rh_ai_notices_unseen($db, $uid);
                if ($unseen) {
                    $n = $unseen[0];
                    $cssPrinted = true;
                    ?>
<link rel="stylesheet" href="css/ai-panel.css?v=<?php echo (int)$cssV; ?>">
<div class="rh-ai-notice-card" id="rhAiNotice" role="status" data-notice="<?php echo (int)$n['id']; ?>" hidden>
    <p class="rh-ai-notice-card__title"><i class="fas fa-circle-info" aria-hidden="true"></i> Assistant</p>
    <p class="rh-ai-notice-card__text"><?php echo htmlspecialchars((string)$n['text']); ?></p>
    <p class="rh-ai-notice-card__help"><?php echo htmlspecialchars((string)$n['at']); ?><?php echo count($unseen) > 1 ? ' · ' . (count($unseen) - 1) . ' more' : ''; ?> · only people who manage Assistant Settings see this.</p>
    <div class="rh-ai-notice-card__btns">
        <a class="btn btn-primary btn-sm" href="ai-settings.php">Open Assistant Settings</a>
        <button type="button" class="btn btn-secondary btn-sm" data-ai-notice-hide>Hide</button>
    </div>
</div>
<script>
(function () {
    var c = document.getElementById('rhAiNotice');
    if (!c) { return; }
    var k = 'rh_ai_notice_hidden';
    var hidden = null;
    try { hidden = sessionStorage.getItem(k); } catch (e) { hidden = null; }
    if (hidden !== c.getAttribute('data-notice')) { c.hidden = false; }
    c.querySelector('[data-ai-notice-hide]').addEventListener('click', function () {
        c.hidden = true;
        try { sessionStorage.setItem(k, c.getAttribute('data-notice')); } catch (e) { /* private mode */ }
    });
})();
</script>
                    <?php
                }
            }
        }
    } catch (Throwable $e) {
        error_log('Assistant notice card skipped: ' . $e->getMessage());
    }

    if (!rh_ai_enabled($db) || !rh_ai_chat_ready($db)) {
        return;
    }

    // 2. The daily checks (first admin page load of the day, any account): scheduled only, run after the page is sent
    try {
        if (rh_ai_setting('ai_daily_ran_on', '') !== date('Y-m-d')) { // the heavy library is loaded only when today's checks are due
            if (!function_exists('hasPermission')) {
                require_once __DIR__ . '/permissions.php';
            }
            require_once __DIR__ . '/../../includes/ai/ai-selfheal.php';
            rh_ai_daily_maybe($db);
        }
    } catch (Throwable $e) {
        error_log('Assistant daily checks not scheduled: ' . $e->getMessage());
    }

    // 3. The panel
    if (!function_exists('hasPermission')) {
        require_once __DIR__ . '/permissions.php';
    }
    if (!hasPermission($uid, 'ai_assistant_use')) {
        return;
    }
    $csrf = function_exists('generateCsrfToken') ? generateCsrfToken() : (string)($_SESSION['csrf_token'] ?? '');
    $jsV = @filemtime(__DIR__ . '/../js/ai-panel.js');
    require_once __DIR__ . '/../../includes/ai/ai-memory.php';
    $feedback = rh_ai_memory_ready($db); // Helpful / Not right buttons only once migration 072 has run
    $tolerance = defined('BALANCE_TOLERANCE') ? (float)BALANCE_TOLERANCE : 0.01;
    ?>
<?php if (!$cssPrinted): ?><link rel="stylesheet" href="css/ai-panel.css?v=<?php echo (int)$cssV; ?>"><?php endif; ?>
<button type="button" class="rh-ai-launch" id="rhAiLaunch" aria-controls="rhAiPanel" aria-expanded="false" data-help="Assistant|Ask about bookings, rooms, guests or how to do something. It only sees what your account can see.">
    <i class="fas fa-comments" aria-hidden="true"></i><span>Assistant</span>
</button>
<aside class="rh-ai-panel" id="rhAiPanel" role="dialog" aria-modal="false" aria-labelledby="rhAiTitle" hidden
       data-endpoint="api/ai-chat.php" data-action-endpoint="api/ai-action.php" data-csrf="<?php echo htmlspecialchars($csrf); ?>"
       data-feedback="<?php echo $feedback ? '1' : '0'; ?>" data-tolerance="<?php echo htmlspecialchars((string)$tolerance); ?>">
    <header class="rh-ai-head">
        <div class="rh-ai-head__text">
            <h2 id="rhAiTitle">Assistant</h2>
            <p class="rh-ai-help">Ask about bookings, rooms, guests or how to do something. It only sees what your account can see.</p>
        </div>
        <div class="rh-ai-head__btns">
            <button type="button" class="rh-ai-icon" data-ai="chats" aria-label="Your chats" title="Your chats"><i class="fas fa-list" aria-hidden="true"></i></button>
            <button type="button" class="rh-ai-icon" data-ai="new" aria-label="New chat" title="New chat"><i class="fas fa-plus" aria-hidden="true"></i></button>
            <button type="button" class="rh-ai-icon" data-ai="prefs" aria-label="Reply settings" title="Reply settings"><i class="fas fa-sliders" aria-hidden="true"></i></button>
            <button type="button" class="rh-ai-icon" data-ai="close" aria-label="Close the Assistant" title="Close"><i class="fas fa-xmark" aria-hidden="true"></i></button>
        </div>
    </header>

    <section class="rh-ai-view" data-view="chats" hidden aria-label="Your chats">
        <p class="rh-ai-help">Your recent chats. Only you can see them. Tap one to carry on.</p>
        <ul class="rh-ai-chatlist" id="rhAiChatList"></ul>
        <button type="button" class="btn btn-secondary rh-ai-back" data-ai="back"><i class="fas fa-arrow-left" aria-hidden="true"></i> Back to the chat</button>
    </section>

    <section class="rh-ai-view" data-view="prefs" hidden aria-label="Reply settings">
        <p class="rh-ai-help">How the Assistant answers you. Only affects your own replies.</p>
        <form class="rh-ai-prefs" id="rhAiPrefs">
            <label for="rhAiPrefLength">Reply length</label>
            <select id="rhAiPrefLength" name="length">
                <option value="short">Short</option>
                <option value="normal">Normal</option>
                <option value="detailed">Detailed</option>
            </select>
            <p class="rh-ai-help">Short gives the answer in a line or two.</p>
            <label for="rhAiPrefTone">Tone</label>
            <select id="rhAiPrefTone" name="tone">
                <option value="plain">Plain</option>
                <option value="friendly">Friendly</option>
            </select>
            <label for="rhAiPrefLang">Language</label>
            <input type="text" id="rhAiPrefLang" name="language" maxlength="30" placeholder="English">
            <p class="rh-ai-help">Replies come back in this language.</p>
            <label for="rhAiPrefBrief">Daily briefing</label>
            <select id="rhAiPrefBrief" name="briefing">
                <option value="on">On - show it the first time I open the Assistant each day</option>
                <option value="off">Off - only when I ask</option>
            </select>
            <p class="rh-ai-help">A short list of what needs you today, worked out from what your account can see. Ask "give me the tea" any time.</p>
            <div class="rh-ai-prefs__btns">
                <button type="submit" class="btn btn-primary">Save</button>
                <button type="button" class="btn btn-secondary" data-ai="back">Cancel</button>
            </div>
            <div class="rh-ai-prefs__reset">
                <button type="button" class="rh-ai-card__btn" data-ai="prefs-reset"><i class="fas fa-rotate-left" aria-hidden="true"></i> Reset my reply settings</button>
                <p class="rh-ai-help">Puts length, tone and language back to normal and forgets the reply style it learned from your corrections.</p>
            </div>
        </form>
    </section>

    <section class="rh-ai-view" data-view="chat" aria-label="Chat">
        <div class="rh-ai-stream" id="rhAiStream" aria-live="polite"></div>
        <div class="rh-ai-chips" id="rhAiChips" aria-label="Suggested questions"></div>
        <form class="rh-ai-compose" id="rhAiForm">
            <label class="rh-ai-sr" for="rhAiInput">Your question</label>
            <textarea id="rhAiInput" rows="1" maxlength="2000" placeholder="Ask a question" autocomplete="off"></textarea>
            <button type="submit" class="btn btn-primary rh-ai-send" aria-label="Send"><i class="fas fa-paper-plane" aria-hidden="true"></i><span>Send</span></button>
            <button type="button" class="btn btn-secondary rh-ai-stop" data-ai="stop" hidden aria-label="Stop"><i class="fas fa-stop" aria-hidden="true"></i><span>Stop</span></button>
        </form>
        <p class="rh-ai-foot">Guest names and contact details stay on the hotel's server. A card changes nothing until you tap Confirm.</p>
    </section>
</aside>
<script src="js/ai-panel.js?v=<?php echo (int)$jsV; ?>" defer></script>
    <?php
})();
