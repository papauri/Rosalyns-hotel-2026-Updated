<?php
/**
 * Friendly "you don't have access" card.
 *
 * rhDenyAndRedirectHome() sends someone who opens a page they may not use (typed URL, old
 * bookmark, shared link) to their own main page with ?no_access=<page>[&why=module]. This
 * card then tells them, in plain words, which page it was and what to do, on the page they
 * landed on. Self-contained (inline styles) so it works on the dashboard (inside
 * #rh-admin-page, so in-place navigation shows it too) and on the station screens that do not
 * use the admin header (POS, KDS/BDS/CDS). Included once per page.
 */
if (defined('RH_ACCESS_NOTICE_DONE')) {
    return;
}
define('RH_ACCESS_NOTICE_DONE', true);

$_rhNaPage = strtolower((string)($_GET['no_access'] ?? ''));
if ($_rhNaPage === '' || !preg_match('/^[a-z0-9_-]{1,80}\.php$/', $_rhNaPage)) {
    unset($_rhNaPage);
    return;
}
$_rhNaModule = (string)($_GET['why'] ?? '') === 'module';

// The page's menu name ("User Management", "Backups"); a page that is not in the menu reads "that page".
$_rhNaLabel = '';
if (!function_exists('rh_admin_nav_groups')) {
    @include_once __DIR__ . '/admin-nav-items.php';
}
if (function_exists('rh_admin_nav_groups')) {
    foreach (rh_admin_nav_groups() as $_rhNaItems) {
        foreach ($_rhNaItems as $_rhNaIt) {
            if (strtolower((string)($_rhNaIt[0] ?? '')) === $_rhNaPage) {
                $_rhNaLabel = (string)($_rhNaIt[2] ?? '');
                break 2;
            }
        }
    }
}
$_rhNaWhat = $_rhNaLabel !== '' ? '“' . $_rhNaLabel . '”' : 'that page';
$_rhNaTitle = $_rhNaModule ? 'That part of the system is switched off' : 'You don’t have access to ' . $_rhNaWhat;
$_rhNaBody = $_rhNaModule
    ? ucfirst($_rhNaWhat) . ' belongs to a feature that is turned off for this hotel, so it can’t be opened right now. If you think it should be on, ask your administrator.'
    : 'Your account isn’t set up to use ' . $_rhNaWhat . '. Nothing is wrong with your account — it just isn’t part of what your role can open. If you need it for your work, ask your administrator to give you access.';
?>
<div id="rhAccessNotice" role="alertdialog" aria-modal="true" aria-labelledby="rhAccessNoticeTitle" aria-describedby="rhAccessNoticeBody"
     style="position:fixed;inset:0;z-index:100000;display:flex;align-items:center;justify-content:center;padding:16px;background:rgba(20,17,14,.55);font-family:inherit;">
    <div style="background:#fff;border-radius:14px;max-width:440px;width:100%;padding:28px 26px 22px;box-shadow:0 18px 48px rgba(0,0,0,.25);text-align:center;color:#3e3930;">
        <div aria-hidden="true" style="width:56px;height:56px;border-radius:50%;margin:0 auto 14px;display:flex;align-items:center;justify-content:center;background:#f5efe4;color:#7E684B;font-size:24px;">
            <i class="fas <?php echo $_rhNaModule ? 'fa-puzzle-piece' : 'fa-lock'; ?>"></i>
        </div>
        <h2 id="rhAccessNoticeTitle" style="font-size:1.2rem;line-height:1.35;margin:0 0 10px;color:#2b2722;"><?php echo htmlspecialchars($_rhNaTitle); ?></h2>
        <p id="rhAccessNoticeBody" style="font-size:.95rem;line-height:1.6;margin:0 0 8px;color:#5f564b;"><?php echo htmlspecialchars($_rhNaBody); ?></p>
        <p style="font-size:.9rem;line-height:1.5;margin:0 0 20px;color:#7a6f63;">We’ve brought you back to your main page instead.</p>
        <button type="button" id="rhAccessNoticeOk"
                style="min-height:44px;min-width:180px;padding:10px 22px;border:0;border-radius:8px;background:#7E684B;color:#fff;font-size:.95rem;font-weight:600;cursor:pointer;">
            OK, continue
        </button>
    </div>
</div>
<script>
(function () {
    var box = document.getElementById('rhAccessNotice');
    if (!box) return;
    // Refreshing the page or going back must not show the card again.
    try {
        var u = new URL(window.location.href);
        u.searchParams.delete('no_access');
        u.searchParams.delete('why');
        window.history.replaceState(window.history.state, '', u.pathname + (u.search || '') + u.hash);
    } catch (e) { /* old browser: harmless */ }
    function close() {
        box.remove();
        document.removeEventListener('keydown', onKey);
    }
    function onKey(e) { if (e.key === 'Escape' || e.key === 'Enter') { e.preventDefault(); close(); } }
    document.getElementById('rhAccessNoticeOk').addEventListener('click', close);
    box.addEventListener('click', function (e) { if (e.target === box) close(); });
    document.addEventListener('keydown', onKey);
    setTimeout(function () { var b = document.getElementById('rhAccessNoticeOk'); if (b) b.focus(); }, 50);
})();
</script>
<?php
unset($_rhNaPage, $_rhNaModule, $_rhNaLabel, $_rhNaItems, $_rhNaIt, $_rhNaWhat, $_rhNaTitle, $_rhNaBody);
