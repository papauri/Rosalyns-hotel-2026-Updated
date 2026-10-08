<?php

/**
 * docs/guides/system-map.php
 *
 * The system map, generated from the system's own code (includes/guide-system-knowledge.php):
 * every admin menu page (where it is, permission, who has it by default, module), every
 * permission, role and module. Never edited by hand, so it cannot go out of date.
 *
 *   system-map.php              the whole map
 *   system-map.php?page=x.php   help for one admin page (opened by the admin's Help button):
 *                               where it is, who can open it, and the guide sections about it
 */

declare(strict_types=1);

require_once __DIR__ . '/../../includes/guide-knowledge-base.php';
require_once __DIR__ . '/../../includes/guide-system-knowledge.php';

$e = static function ($v): string {
    return htmlspecialchars((string)$v, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
};

$siteName = 'Rosalyns Beach Hotel';
try {
    require_once __DIR__ . '/../../config/database.php';
    require_once __DIR__ . '/../../config/cache.php';
    $siteName = (string)(getSetting('site_name') ?: $siteName);
} catch (Throwable $ex) {
    // Keep the fallback name; the map itself needs no database.
}

$ok = rh_kb_sys_load();
$menu = $ok ? rh_kb_sys_menu() : [];
$perms = $ok ? getAllPermissions() : [];
$roles = $ok ? getAllRoles() : [];
$modules = rh_kb_sys_modules();
$index = rh_kb_index();

$page = basename((string)($_GET['page'] ?? ''));
if ($page !== '' && !preg_match('/^[\w-]+\.php$/', $page)) {
    $page = '';
}

$menuByPage = [];
foreach ($menu as $p) {
    $menuByPage[$p['page']] = $p;
}
$permByPage = [];
foreach ($perms as $key => $info) {
    if (!empty($info['page']) && !isset($permByPage[$info['page']])) {
        $permByPage[$info['page']] = $key;
    }
}

/** First guide section that explains a page (for the map's "Guide" column). */
$guideFor = static function (string $pg) use ($index): ?array {
    foreach (rh_kb_sys_help_for_page($pg, $index, 6) as $hit) {
        if ($hit['type'] === 'section') {
            return $hit;
        }
    }
    return null;
};

$title = 'System map';
$heading = 'System map';
if ($page !== '') {
    $label = $menuByPage[$page]['label'] ?? ($permByPage[$page] ?? null ? ($perms[$permByPage[$page]]['label'] ?? $page) : $page);
    $title = 'Help: ' . $label;
    $heading = 'Help for ' . $label;
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title><?= $e($title) ?> — <?= $e($siteName) ?></title>
  <link rel="stylesheet" href="assets/guide-theme.css">
  <script src="assets/guide-init.js" defer></script>
</head>
<body>
<div class="wrap">

  <nav class="top">
    <a href="index.html" class="brand"><?= $e($siteName) ?></a>
    <a href="index.html">All guides</a>
    <a href="faq.html">FAQ</a>
    <?php if ($page !== ''): ?><a href="system-map.php">System map</a><?php endif; ?>
  </nav>

  <h1><?= $e($heading) ?></h1>

<?php if (!$ok): ?>
  <div class="warn"><p>The system map could not be read right now. Use the <a href="index.html">guides</a> instead.</p></div>
<?php elseif ($page !== ''):
    $mp = $menuByPage[$page] ?? null;
    $pk = $permByPage[$page] ?? ($mp['perm'] ?? null);
    $help = rh_kb_sys_help_for_page($page, $index, 14);
    $sections = array_values(array_filter($help, static function ($h) { return $h['type'] !== 'problem'; }));
    $problems = array_values(array_filter($help, static function ($h) { return $h['type'] === 'problem'; }));
?>
  <div class="facts">
    <dl>
      <?php if ($mp): ?>
      <dt>Where to find it</dt><dd>Admin menu &rarr; <strong><?= $e($mp['group']) ?></strong> &rarr; <strong><?= $e($mp['label']) ?></strong> (<code>/admin/<?= $e($page) ?></code>)<?= $mp['alias'] !== '' ? ', called <strong>' . $e($mp['alias']) . '</strong> when the restaurant is switched off' : '' ?></dd>
      <?php else: ?>
      <dt>Page</dt><dd><code>/admin/<?= $e($page) ?></code> (opened from another page, not from the menu)</dd>
      <?php endif; ?>
      <?php if ($pk !== null && isset($perms[$pk])): ?>
      <dt>Permission needed</dt><dd><a href="system-map.php#<?= $e(rh_kb_sys_anchor('perm', $pk)) ?>"><strong><?= $e($perms[$pk]['label']) ?></strong></a> — <?= $e($perms[$pk]['description']) ?></dd>
      <dt>Who has it by default</dt><dd><?= $e(implode(', ', rh_kb_sys_roles_with($pk))) ?></dd>
      <?php elseif ($mp): ?>
      <dt>Permission needed</dt><dd>None. Every signed-in member of staff can open it.</dd>
      <?php endif; ?>
      <?php if ($mp && $mp['module_text'] !== ''): ?>
      <dt>Only shown when</dt><dd><strong><?= $e($mp['module_text']) ?></strong> is switched on in Settings &rarr; Modules.</dd>
      <?php endif; ?>
    </dl>
  </div>

  <div data-kb-search="inline"></div>

  <h2 id="guide">How to use it</h2>
  <?php if ($sections): ?>
  <ul class="guide-list">
    <?php foreach ($sections as $h): ?>
    <li><a href="<?= $e(rh_kb_entry_url($h)) ?>"><?= $e($h['title']) ?></a><span><?= $e($h['guide'] . (!empty($h['parent']) ? ' › ' . $h['parent'] : '')) ?><?= $h['type'] === 'faq' ? ' · FAQ' : '' ?></span></li>
    <?php endforeach; ?>
  </ul>
  <?php else: ?>
  <p>No guide covers this page in detail yet. Use the search box above, or the <a href="index.html">guides home page</a>.</p>
  <?php endif; ?>

  <?php if ($problems): ?>
  <h2 id="problems">Problems you may see here</h2>
  <table>
    <thead><tr><th>What you see</th><th>What to do</th></tr></thead>
    <tbody>
      <?php foreach ($problems as $h): ?>
      <tr><td><a href="<?= $e(rh_kb_entry_url($h)) ?>"><?= $e($h['title']) ?></a></td><td><?= $e($h['fix'] ?? '') ?></td></tr>
      <?php endforeach; ?>
    </tbody>
  </table>
  <?php endif; ?>

<?php else: ?>
  <p>Every page in the admin menu, every permission, role and module, read straight from the system, so this page is always up to date. Use it to answer "where is…", "who can…" and "why can't I see…". The menu you see yourself only shows what your permissions and the switched-on modules allow.</p>

  <div class="toc">
    <ol>
      <li><a href="#menu">Every menu page</a></li>
      <li><a href="#permissions">Every permission</a></li>
      <li><a href="#roles">Roles and what they start with</a></li>
      <li><a href="#modules">Modules</a></li>
    </ol>
  </div>

  <h2 id="menu">Every menu page</h2>
  <?php
  $groups = [];
  foreach ($menu as $p) {
      $groups[$p['group']][] = $p;
  }
  foreach ($groups as $group => $items): ?>
  <h3><?= $e($group) ?></h3>
  <table>
    <thead><tr><th>Page</th><th>What it is for</th><th>Permission</th><th>Shown when</th><th>Guide</th></tr></thead>
    <tbody>
      <?php foreach ($items as $p): $g = $guideFor($p['page']); ?>
      <tr id="<?= $e(rh_kb_sys_anchor('page', $p['page'])) ?>">
        <td><strong><?= $e($p['label']) ?></strong><?= $p['alias'] !== '' ? '<br><small>(' . $e($p['alias']) . ' without the restaurant)</small>' : '' ?><br><small><a href="system-map.php?page=<?= $e(rawurlencode($p['page'])) ?>">Help for this page</a></small></td>
        <td><?= $e($p['perm_desc'] !== '' ? $p['perm_desc'] : '—') ?></td>
        <td><?php if ($p['perm'] !== null): ?><a href="#<?= $e(rh_kb_sys_anchor('perm', $p['perm'])) ?>"><?= $e($p['perm_label']) ?></a><?php else: ?>Everyone<?php endif; ?></td>
        <td><?= $e($p['module_text'] !== '' ? $p['module_text'] . ' is on' : 'Always') ?></td>
        <td><?php if ($g): ?><a href="<?= $e(rh_kb_entry_url($g)) ?>"><?= $e($g['guide']) ?></a><?php else: ?>—<?php endif; ?></td>
      </tr>
      <?php endforeach; ?>
    </tbody>
  </table>
  <?php endforeach; ?>

  <h2 id="permissions">Every permission</h2>
  <p>Administrators always have every permission. To change what one person can open: Settings &rarr; Staff &amp; Access &rarr; their <strong>Permissions</strong> (see <a href="18-staff-access.html#perms">Staff &amp; access</a>).</p>
  <?php
  $byCat = [];
  foreach ($perms as $key => $info) {
      $byCat[$info['category'] ?? 'Other'][$key] = $info;
  }
  foreach ($byCat as $cat => $items): ?>
  <h3><?= $e($cat) ?></h3>
  <table>
    <thead><tr><th>Permission</th><th>Allows</th><th>Given by default to</th></tr></thead>
    <tbody>
      <?php foreach ($items as $key => $info): ?>
      <tr id="<?= $e(rh_kb_sys_anchor('perm', $key)) ?>">
        <td><strong><?= $e($info['label']) ?></strong></td>
        <td><?= $e($info['description'] ?? '') ?><?php if (!empty($info['page']) && isset($menuByPage[$info['page']])): ?> (<a href="#<?= $e(rh_kb_sys_anchor('page', $info['page'])) ?>"><?= $e($menuByPage[$info['page']]['label']) ?></a>)<?php endif; ?></td>
        <td><?= $e(implode(', ', rh_kb_sys_roles_with($key))) ?></td>
      </tr>
      <?php endforeach; ?>
    </tbody>
  </table>
  <?php endforeach; ?>

  <h2 id="roles">Roles and what they start with</h2>
  <p>A role is a starting set of permissions. An administrator can then tick or untick single permissions for one person.</p>
  <?php foreach ($roles as $key => $role): ?>
  <h3 id="<?= $e(rh_kb_sys_anchor('role', $key)) ?>"><?= $e($role['label']) ?></h3>
  <p><?= $e($role['description']) ?>.</p>
  <?php if ($role['permissions'] === null): ?>
  <p>Has every permission, always. Administrator permissions cannot be changed.</p>
  <?php else: ?>
  <p><?= implode(', ', array_map(static function ($k) use ($perms, $e) {
      return '<a href="#' . $e(rh_kb_sys_anchor('perm', $k)) . '">' . $e($perms[$k]['label'] ?? $k) . '</a>';
  }, (array)$role['permissions'])) ?></p>
  <?php endif; ?>
  <?php endforeach; ?>

  <h2 id="modules">Modules</h2>
  <p>Modules switch whole parts of the system on and off for everyone (Settings &rarr; Modules, administrators). See <a href="16-business-presets.html">Modules and business presets</a>.</p>
  <table>
    <thead><tr><th>Module</th><th>What it covers</th><th>Menu pages hidden when off</th></tr></thead>
    <tbody>
      <?php foreach ($modules as $key => $mod):
          if (in_array($key, ['events', 'billing', 'advance_booking'], true)) {
              continue;
          }
          $pages = array_values(array_filter($menu, static function ($p) use ($key) {
              return in_array($key, (array)$p['module'], true) || ($key === 'events_page' && in_array('events', (array)$p['module'], true));
          })); ?>
      <tr id="<?= $e(rh_kb_sys_anchor('module', $key)) ?>">
        <td><strong><?= $e($mod['label']) ?></strong></td>
        <td><?= $e($mod['desc']) ?></td>
        <td><?= $pages ? implode(', ', array_map(static function ($p) use ($e) {
            return '<a href="#' . $e(rh_kb_sys_anchor('page', $p['page'])) . '">' . $e($p['label']) . '</a>';
        }, $pages)) : '—' ?></td>
      </tr>
      <?php endforeach; ?>
    </tbody>
  </table>
<?php endif; ?>

  <footer>Generated from the system itself each time it changes.</footer>
</div>
</body>
</html>
