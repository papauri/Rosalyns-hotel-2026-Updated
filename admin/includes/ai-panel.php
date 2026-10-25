<?php
/**
 * The Assistant panel (.claude/AI_ASSISTANT_PLAN.md Phase 2). Required once from admin-header.php,
 * right after the help button and OUTSIDE #rh-admin-page, so the chat survives in-app navigation.
 *
 * Prints nothing at all unless the Assistant is switched on (fail-closed kill switch, migrations 070 +
 * 071 run) and this person holds ai_assistant_use. Plain "Assistant" button with fa-comments; every
 * part has a plain helper line; existing admin design tokens only (admin/css/ai-panel.css).
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
    if (!rh_ai_enabled($db) || !rh_ai_chat_ready($db)) {
        return;
    }
    if (!function_exists('hasPermission')) {
        require_once __DIR__ . '/permissions.php';
    }
    if (!hasPermission($uid, 'ai_assistant_use')) {
        return;
    }
    $csrf = function_exists('generateCsrfToken') ? generateCsrfToken() : (string)($_SESSION['csrf_token'] ?? '');
    $cssV = @filemtime(__DIR__ . '/../css/ai-panel.css');
    $jsV = @filemtime(__DIR__ . '/../js/ai-panel.js');
    ?>
<link rel="stylesheet" href="css/ai-panel.css?v=<?php echo (int)$cssV; ?>">
<button type="button" class="rh-ai-launch" id="rhAiLaunch" aria-controls="rhAiPanel" aria-expanded="false" data-help="Assistant|Ask about bookings, rooms, guests or how to do something. It only sees what your account can see.">
    <i class="fas fa-comments" aria-hidden="true"></i><span>Assistant</span>
</button>
<aside class="rh-ai-panel" id="rhAiPanel" role="dialog" aria-modal="false" aria-labelledby="rhAiTitle" hidden
       data-endpoint="api/ai-chat.php" data-csrf="<?php echo htmlspecialchars($csrf); ?>">
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
            <div class="rh-ai-prefs__btns">
                <button type="submit" class="btn btn-primary">Save</button>
                <button type="button" class="btn btn-secondary" data-ai="back">Cancel</button>
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
        <p class="rh-ai-foot">Guest names and contact details stay on the hotel's server. Check anything important on the screen before acting on it.</p>
    </section>
</aside>
<script src="js/ai-panel.js?v=<?php echo (int)$jsV; ?>" defer></script>
    <?php
})();
