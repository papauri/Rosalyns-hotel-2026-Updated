<?php

/**
 * Staff presence ("Who's online") - table admin_presence, created by migration 064.
 *
 * - rh_presence_heartbeat():  one cheap upsert of the signed-in person's row. Called from
 *   admin-init.php on each real page load and from admin/api/presence.php (ping) while a tab is
 *   being used. Throttled per session, failures silent, never blocks a page.
 * - rh_presence_clear():      sign-out removes the row.
 * - rh_presence_rows():       who is online now (seen within 30 min) for the panel / JSON.
 * - rh_presence_bucket():     active (<= 2 min) / idle (<= 30 min) / offline.
 *
 * current_page is kept per user (basename only) so a later "someone else is booking" banner can
 * read what each person has open.
 */

if (!defined('RH_PRESENCE_ACTIVE_SECONDS')) {
    define('RH_PRESENCE_ACTIVE_SECONDS', 120);
}
if (!defined('RH_PRESENCE_IDLE_SECONDS')) {
    define('RH_PRESENCE_IDLE_SECONDS', 1800);
}
if (!defined('RH_PRESENCE_THROTTLE_SECONDS')) {
    define('RH_PRESENCE_THROTTLE_SECONDS', 60);
}

if (!function_exists('rh_presence_can_view')) {

    /** Only administrators and managers may see who is online. */
    function rh_presence_can_view(?string $role): bool
    {
        return in_array((string)$role, ['admin', 'manager'], true);
    }

    /** Only administrators see IP addresses. */
    function rh_presence_can_see_ip(?string $role): bool
    {
        return (string)$role === 'admin';
    }

    /** 'active' (seen within 2 min), 'idle' (within 30 min) or 'offline'. */
    function rh_presence_bucket(int $secondsSinceSeen): string
    {
        if ($secondsSinceSeen < 0) {
            $secondsSinceSeen = 0;
        }
        if ($secondsSinceSeen <= RH_PRESENCE_ACTIVE_SECONDS) {
            return 'active';
        }
        if ($secondsSinceSeen <= RH_PRESENCE_IDLE_SECONDS) {
            return 'idle';
        }
        return 'offline';
    }

    /** A page file name safe to store: "bookings.php" yes, anything with a path or query no. */
    function rh_presence_clean_page(string $page): string
    {
        $page = basename((string)strtok($page, '?#'));
        if (!preg_match('/^[A-Za-z0-9_-]{1,100}\.php$/', $page)) {
            return '';
        }
        return $page;
    }

    /** Friendly screen name: the admin menu label when there is one, otherwise the file name tidied. */
    function rh_presence_page_title(string $page): string
    {
        static $map = null;
        if ($map === null) {
            $map = [];
            try {
                if (!function_exists('rh_admin_nav_groups')) {
                    require_once __DIR__ . '/admin-nav-items.php';
                }
                foreach (rh_admin_nav_groups() as $items) {
                    foreach ($items as $item) {
                        if (!empty($item[0]) && !empty($item[2])) {
                            $map[(string)$item[0]] = (string)$item[2];
                        }
                    }
                }
            } catch (Throwable $e) {
                $map = [];
            }
        }
        if ($page === '') {
            return 'Admin';
        }
        if (isset($map[$page])) {
            return $map[$page];
        }
        $name = ucfirst(str_replace(['-', '_'], ' ', (string)preg_replace('/\.php$/i', '', $page)));
        return $name !== '' ? $name : 'Admin';
    }

    /**
     * Record that this person is here. Cheap by design: at most one write per 60 s per session
     * (a change of page is written straight away so the screen shown is current). Never throws.
     */
    function rh_presence_heartbeat(PDO $pdo, int $userId, string $page): void
    {
        if ($userId <= 0 || session_status() !== PHP_SESSION_ACTIVE) {
            return;
        }
        $page = rh_presence_clean_page($page);
        $now = time();
        $lastTs = (int)($_SESSION['rh_presence_ts'] ?? 0);
        $lastPage = (string)($_SESSION['rh_presence_page'] ?? '');
        $newSession = empty($_SESSION['rh_presence_started']);
        if (!$newSession && ($now - $lastTs) < RH_PRESENCE_THROTTLE_SECONDS && ($page === '' || $page === $lastPage)) {
            return;
        }
        // Set first so a missing table or a slow database is not retried on every page.
        $_SESSION['rh_presence_ts'] = $now;
        if ($page !== '') {
            $_SESSION['rh_presence_page'] = $page;
        }
        try {
            $ip = substr((string)($_SERVER['REMOTE_ADDR'] ?? ''), 0, 45);
            $ua = substr((string)($_SERVER['HTTP_USER_AGENT'] ?? ''), 0, 120);
            $pageValue = $page !== '' ? $page : null;
            if ($newSession) {
                // First sign of this sign-in: it (re)starts "signed in since".
                $stmt = $pdo->prepare(
                    "INSERT INTO admin_presence (user_id, last_seen_at, current_page, session_started_at, ip, user_agent_short)
                     VALUES (?, NOW(), ?, NOW(), ?, ?)
                     ON DUPLICATE KEY UPDATE last_seen_at = NOW(), current_page = VALUES(current_page),
                         session_started_at = NOW(), ip = VALUES(ip), user_agent_short = VALUES(user_agent_short)"
                );
                $stmt->execute([$userId, $pageValue, $ip !== '' ? $ip : null, $ua !== '' ? $ua : null]);
                $_SESSION['rh_presence_started'] = $now;
                return;
            }
            // A ping with no page keeps the page already stored.
            $stmt = $pdo->prepare(
                "INSERT INTO admin_presence (user_id, last_seen_at, current_page, session_started_at, ip, user_agent_short)
                 VALUES (?, NOW(), ?, FROM_UNIXTIME(?), ?, ?)
                 ON DUPLICATE KEY UPDATE last_seen_at = NOW(), current_page = COALESCE(VALUES(current_page), current_page),
                     ip = VALUES(ip), user_agent_short = VALUES(user_agent_short)"
            );
            $stmt->execute([$userId, $pageValue, (int)$_SESSION['rh_presence_started'], $ip !== '' ? $ip : null, $ua !== '' ? $ua : null]);
        } catch (Throwable $e) {
            // Presence is a convenience: never let it break a page. Mark the sign-in as started so a
            // missing table (before migration 064) is only retried once a minute, not on every page.
            if (empty($_SESSION['rh_presence_started'])) {
                $_SESSION['rh_presence_started'] = $now;
            }
        }
    }

    /** Sign-out: the person is no longer online. */
    function rh_presence_clear(PDO $pdo, int $userId): void
    {
        if ($userId <= 0) {
            return;
        }
        try {
            $pdo->prepare("DELETE FROM admin_presence WHERE user_id = ?")->execute([$userId]);
        } catch (Throwable $e) {
            // ignore
        }
    }

    /**
     * People online now (seen within 30 min), most recently seen first. IPs only when $withIp.
     *
     * @return array<int,array<string,mixed>>
     */
    function rh_presence_rows(PDO $pdo, bool $withIp = false): array
    {
        $roles = function_exists('getAllRoles') ? getAllRoles() : [];
        try {
            $stmt = $pdo->prepare(
                "SELECT p.user_id, p.current_page, p.session_started_at, p.ip,
                        TIMESTAMPDIFF(SECOND, p.last_seen_at, NOW()) AS secs,
                        DATE(p.session_started_at) = CURDATE() AS started_today,
                        u.full_name, u.username, u.role
                 FROM admin_presence p
                 JOIN admin_users u ON u.id = p.user_id
                 WHERE u.is_active = 1 AND p.last_seen_at >= DATE_SUB(NOW(), INTERVAL ? SECOND)
                 ORDER BY p.last_seen_at DESC"
            );
            $stmt->execute([RH_PRESENCE_IDLE_SECONDS]);
            $found = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
        } catch (Throwable $e) {
            return [];
        }
        $out = [];
        foreach ($found as $r) {
            $secs = (int)$r['secs'];
            $bucket = rh_presence_bucket($secs);
            if ($bucket === 'offline') {
                continue;
            }
            $mins = (int)floor($secs / 60);
            $startedTs = strtotime((string)$r['session_started_at']);
            $row = [
                'user_id' => (int)$r['user_id'],
                'name' => (string)($r['full_name'] !== '' ? $r['full_name'] : $r['username']),
                'role' => (string)$r['role'],
                'role_label' => (string)($roles[$r['role']]['label'] ?? ucfirst((string)$r['role'])),
                'status' => $bucket,
                'status_label' => $bucket === 'active' ? 'Active now' : 'Idle ' . max(1, $mins) . ' min',
                'page' => (string)$r['current_page'],
                'page_title' => rh_presence_page_title((string)$r['current_page']),
                'since' => $startedTs ? ((int)$r['started_today'] === 1 ? date('H:i', $startedTs) : date('j M, H:i', $startedTs)) : '',
            ];
            if ($withIp) {
                $row['ip'] = (string)$r['ip'];
            }
            $out[] = $row;
        }
        return $out;
    }

    /**
     * The "Who's online" panel (admin and manager only; prints nothing for anyone else).
     * Lives inside #rh-admin-page; its script re-inits after in-app navigation.
     */
    function rh_presence_render_panel(PDO $pdo, string $role, int $viewerId): void
    {
        if (!rh_presence_can_view($role)) {
            return;
        }
        $withIp = rh_presence_can_see_ip($role);
        $rows = rh_presence_rows($pdo, $withIp);
        $n = count($rows);
        ?>
<section class="presence-panel" id="rh-presence-panel" data-endpoint="api/presence.php" data-viewer="<?php echo (int)$viewerId; ?>" data-show-ip="<?php echo $withIp ? '1' : '0'; ?>" aria-labelledby="rh-presence-title">
    <div class="presence-head">
        <h3 id="rh-presence-title">Who's online</h3>
        <span class="presence-count" id="rh-presence-count" aria-live="polite"><?php echo $n; ?> online</span>
    </div>
    <p class="presence-note">People signed in and using the system in the last 30 minutes. Updates every minute.</p>
    <div class="presence-list" id="rh-presence-list">
        <?php foreach ($rows as $r): ?>
        <div class="presence-row">
            <span class="presence-dot presence-dot--<?php echo htmlspecialchars($r['status']); ?>" aria-hidden="true"></span>
            <div class="presence-who">
                <strong><?php echo htmlspecialchars($r['name']); ?><?php echo $r['user_id'] === $viewerId ? ' <em>(you)</em>' : ''; ?></strong>
                <span class="presence-role"><?php echo htmlspecialchars($r['role_label']); ?></span>
            </div>
            <span class="presence-state presence-state--<?php echo htmlspecialchars($r['status']); ?>"><?php echo htmlspecialchars($r['status_label']); ?></span>
            <span class="presence-screen"><?php echo htmlspecialchars($r['page_title']); ?></span>
            <span class="presence-since"><?php echo $r['since'] !== '' ? 'Signed in ' . htmlspecialchars($r['since']) : ''; ?></span>
            <?php if ($withIp): ?><span class="presence-ip"><?php echo htmlspecialchars((string)($r['ip'] ?? '')); ?></span><?php endif; ?>
        </div>
        <?php endforeach; ?>
        <?php if (!$rows): ?><p class="presence-empty">Nobody is signed in right now.</p><?php endif; ?>
    </div>
</section>
<script>
(function () {
    // Re-runs after every in-app page change: stop the old timer, then start one for this panel.
    if (window.__rhPresenceTimer) { clearInterval(window.__rhPresenceTimer); window.__rhPresenceTimer = null; }
    var panel = document.getElementById('rh-presence-panel');
    if (!panel) { return; }
    var list = document.getElementById('rh-presence-list');
    var count = document.getElementById('rh-presence-count');
    var viewer = parseInt(panel.getAttribute('data-viewer') || '0', 10);
    var showIp = panel.getAttribute('data-show-ip') === '1';

    function el(tag, cls, text) {
        var e = document.createElement(tag);
        if (cls) { e.className = cls; }
        if (text !== undefined && text !== null) { e.textContent = text; }
        return e;
    }
    function render(rows) {
        count.textContent = rows.length + ' online';
        list.textContent = '';
        if (!rows.length) { list.appendChild(el('p', 'presence-empty', 'Nobody is signed in right now.')); return; }
        rows.forEach(function (r) {
            var kind = r.status === 'active' ? 'active' : 'idle';
            var row = el('div', 'presence-row');
            row.appendChild(el('span', 'presence-dot presence-dot--' + kind));
            var who = el('div', 'presence-who');
            var name = el('strong', '', r.name);
            if (r.user_id === viewer) { name.appendChild(document.createTextNode(' ')); name.appendChild(el('em', '', '(you)')); }
            who.appendChild(name);
            who.appendChild(el('span', 'presence-role', r.role_label));
            row.appendChild(who);
            row.appendChild(el('span', 'presence-state presence-state--' + kind, r.status_label));
            row.appendChild(el('span', 'presence-screen', r.page_title));
            row.appendChild(el('span', 'presence-since', r.since ? 'Signed in ' + r.since : ''));
            if (showIp) { row.appendChild(el('span', 'presence-ip', r.ip || '')); }
            list.appendChild(row);
        });
    }
    function refresh() {
        if (!document.getElementById('rh-presence-panel')) { clearInterval(window.__rhPresenceTimer); return; }
        if (document.hidden) { return; }
        fetch(panel.getAttribute('data-endpoint'), { credentials: 'same-origin', headers: { 'X-Requested-With': 'XMLHttpRequest' } })
            .then(function (r) { return r.ok ? r.json() : null; })
            .then(function (j) { if (j && j.success && j.data && Array.isArray(j.data.online)) { render(j.data.online); } })
            .catch(function () { /* keep the list as it is */ });
    }
    window.__rhPresenceTimer = setInterval(refresh, 60000);
})();
</script>
        <?php
    }
}
