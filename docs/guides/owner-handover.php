<?php

/**
 * docs/guides/owner-handover.php
 * Owner handover: how this hotel's system is configured right now (read live), what to check
 * routinely, and where each thing is changed. Signed-in staff only — it shows configuration
 * (notification addresses, sending account, staff counts) that should not be public.
 * Never shows passwords or keys.
 */

declare(strict_types=1);

require_once __DIR__ . '/../../includes/admin-session.php';
rh_admin_session_start();
if (empty($_SESSION['admin_user_id'])) {
    header('Location: ../../admin/login.php');
    exit;
}

require_once __DIR__ . '/../../config/database.php';

$e = static fn($v): string => htmlspecialchars((string)$v, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
$s = static fn(string $k, string $d = ''): string => (string)(getSetting($k, $d) ?? $d);
$em = static fn(string $k, string $d = ''): string => function_exists('getEmailSetting') ? (string)(getEmailSetting($k, $d) ?? $d) : $d;
$show = static fn(string $v, string $empty = 'Not set'): string => $v !== '' ? htmlspecialchars($v, ENT_QUOTES, 'UTF-8') : '<strong>' . $empty . '</strong>';

$siteName = $s('site_name', 'Hotel');

$modules = [];
try {
    $modules = function_exists('getEnabledModules') ? getEnabledModules() : [];
} catch (Throwable $ex) {
    $modules = [];
}

$staff = [];
try {
    $staff = $pdo->query("SELECT role, COUNT(*) AS n, SUM(is_active = 1) AS active FROM admin_users GROUP BY role ORDER BY role")->fetchAll(PDO::FETCH_ASSOC);
} catch (Throwable $ex) {
    $staff = [];
}

$lastBackup = $s('last_backup_at');
$backupAgeH = $lastBackup !== '' ? round((time() - (int)strtotime($lastBackup)) / 3600) : null;

$notify = $s('booking_notification_email') ?: ($s('admin_notification_email') ?: $em('email_admin_email'));
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Owner handover — <?php echo $e($siteName); ?></title>
  <link rel="stylesheet" href="assets/guide-theme.css">
</head>
<body>
<div class="wrap">

  <nav class="top">
    <a href="index.html" class="brand"><?php echo $e($siteName); ?></a>
    <a href="index.html">All guides</a>
    <a href="99-admin-dashboard-full-guide.html">Admin reference</a>
  </nav>

  <h1>Owner handover</h1>
  <p>How the <?php echo $e($siteName); ?> system is set up <strong>right now</strong> (read live from the system when you open this page), what to check routinely, and where to change each item. Anything marked <strong>Not set</strong> needs attention.</p>

  <div class="facts">
    <dl>
      <dt>Who uses it</dt><dd>Owner, General Manager, Administrator</dd>
      <dt>Where to find it</dt><dd>Admin menu &rarr; <strong>Staff Guides</strong> &rarr; <strong>Owner handover</strong></dd>
      <dt>Access</dt><dd>Signed-in staff only. No passwords or keys are ever shown here.</dd>
    </dl>
  </div>

  <div class="toc">
    <ol>
      <li><a href="#identity">Hotel identity &amp; contact</a></li>
      <li><a href="#booking">Booking rules</a></li>
      <li><a href="#money">Money</a></li>
      <li><a href="#email">Email</a></li>
      <li><a href="#modules">Parts of the system switched on</a></li>
      <li><a href="#staff">Staff accounts</a></li>
      <li><a href="#routine">Daily, weekly and monthly checks</a></li>
      <li><a href="#go-live">Before go-live checklist</a></li>
    </ol>
  </div>

  <h2 id="identity">Hotel identity &amp; contact</h2>
  <table>
    <thead><tr><th>Item</th><th>Current value</th><th>Change it in</th></tr></thead>
    <tbody>
      <tr><td>Hotel name</td><td><?php echo $show($s('site_name')); ?></td><td>Website &rarr; Footer Management</td></tr>
      <tr><td>Tagline</td><td><?php echo $show($s('site_tagline')); ?></td><td>Website &rarr; Footer Management</td></tr>
      <tr><td>Main phone</td><td><?php echo $show($s('phone_main')); ?></td><td>Website &rarr; Footer Management</td></tr>
      <tr><td>Public email</td><td><?php echo $show($s('email_main')); ?></td><td>Website &rarr; Footer Management</td></tr>
      <tr><td>WhatsApp number</td><td><?php echo $show($s('whatsapp_number')); ?></td><td>Website &rarr; Footer Management</td></tr>
      <tr><td>Postal address (on documents)</td><td><?php echo $show($s('hotel_address')); ?></td><td>Settings &rarr; Hotel Settings &rarr; Hotel details &amp; policies</td></tr>
      <tr><td>Website address (links in emails, PDFs, password resets)</td><td><?php echo $show($s('site_url')); ?></td><td>Settings &rarr; Hotel Settings &rarr; Hotel details &amp; policies</td></tr>
    </tbody>
  </table>

  <h2 id="booking">Booking rules</h2>
  <table>
    <thead><tr><th>Item</th><th>Current value</th><th>Change it in</th></tr></thead>
    <tbody>
      <tr><td>Online booking</td><td><?php echo $s('booking_system_enabled', '1') === '1' ? 'On' : '<strong>Off</strong>'; ?></td><td>Settings &rarr; Hotel Settings &rarr; Booking System Status</td></tr>
      <tr><td>Check-in from / check-out by</td><td><?php echo $show($s('check_in_time', '2:00 PM')); ?> / <?php echo $show($s('check_out_time', '11:00 AM')); ?></td><td>Settings &rarr; Hotel Settings &rarr; Hotel details &amp; policies</td></tr>
      <tr><td>How far ahead guests can book</td><td><?php echo $e($s('max_advance_booking_days', '365')); ?> days</td><td>Settings &rarr; Hotel Settings &rarr; Advance Booking Configuration</td></tr>
      <tr><td>Tentative (hold) bookings</td><td><?php echo $s('tentative_bookings_enabled', '1') === '1' ? 'On' : 'Off'; ?></td><td>Settings &rarr; Hotel Settings &rarr; Tentative Bookings</td></tr>
      <tr><td>Tourism levy</td><td><?php echo $s('tourism_levy_enabled', '0') === '1' ? 'On' : 'Off'; ?></td><td>Settings &rarr; Hotel Settings &rarr; Tourism Levy / City Tax</td></tr>
      <tr><td>Room prices</td><td>Per room type and occupancy</td><td>Settings &rarr; Rooms; seasonal rules in Settings &rarr; Rate Plans</td></tr>
    </tbody>
  </table>

  <h2 id="money">Money</h2>
  <table>
    <thead><tr><th>Item</th><th>Current value</th><th>Change it in</th></tr></thead>
    <tbody>
      <tr><td>Currency</td><td><?php echo $show($s('currency_symbol', 'MWK')); ?></td><td>Fixed at set-up</td></tr>
      <tr><td>VAT</td><td><?php echo $s('vat_enabled', '0') === '1' ? $e($s('vat_rate', '0')) . '% (prices include VAT)' : 'Off'; ?></td><td>Money &rarr; Accounting &rarr; VAT settings (needs <strong>Change VAT &amp; refund settings</strong>)</td></tr>
      <tr><td>Bank name / account number (printed on invoices)</td><td><?php echo $show($s('bank_name')); ?> / <?php echo $show($s('bank_account_number')); ?></td><td>Settings &rarr; Hotel Settings &rarr; Hotel details &amp; policies &rarr; Payment</td></tr>
    </tbody>
  </table>
  <?php if (stripos($s('bank_name') . $s('bank_account_number'), 'sample') !== false): ?>
  <div class="warn"><p>The bank details are still sample values. They print on every invoice — replace them before sending invoices to guests.</p></div>
  <?php endif; ?>

  <h2 id="email">Email</h2>
  <table>
    <thead><tr><th>Item</th><th>Current value</th><th>Change it in</th></tr></thead>
    <tbody>
      <tr><td>Emails are sent from</td><td><?php echo $show($em('email_from_email')); ?> (name: <?php echo $show($em('email_from_name', $s('email_from_name'))); ?>)</td><td>Settings &rarr; Hotel Settings &rarr; Email Configuration</td></tr>
      <tr><td>Mail server</td><td><?php echo $show($em('smtp_host')); ?></td><td>Settings &rarr; Hotel Settings &rarr; Email Configuration</td></tr>
      <tr><td>New-booking alerts go to</td><td><?php echo $show((string)$notify); ?></td><td>Settings &rarr; Hotel Settings &rarr; Booking Notification Email</td></tr>
      <tr><td>Wording of guest emails and PDFs</td><td>Editable templates</td><td>Settings &rarr; Email Templates</td></tr>
    </tbody>
  </table>

  <h2 id="modules">Parts of the system switched on</h2>
  <?php if ($modules): ?>
  <table>
    <thead><tr><th>Module</th><th>Status</th><th>What it covers</th></tr></thead>
    <tbody>
      <?php foreach ($modules as $m): ?>
      <tr><td><?php echo $e($m['module_name'] ?? $m['module_key'] ?? ''); ?></td><td><?php echo !empty($m['is_enabled']) ? 'On' : 'Off'; ?></td><td><?php echo $e($m['description'] ?? ''); ?></td></tr>
      <?php endforeach; ?>
    </tbody>
  </table>
  <?php else: ?>
  <p>Module list unavailable.</p>
  <?php endif; ?>
  <p>Switch modules on or off in <strong>Settings &rarr; Modules</strong> (see <a href="16-business-presets.html">Modules &amp; business presets</a>). Turning one off hides its menu items and pages for everyone except Administrators.</p>

  <h2 id="staff">Staff accounts</h2>
  <?php if ($staff): ?>
  <table>
    <thead><tr><th>Role</th><th>Accounts</th><th>Active</th></tr></thead>
    <tbody>
      <?php foreach ($staff as $r): ?>
      <tr><td><?php echo $e(ucwords(str_replace('_', ' ', (string)$r['role']))); ?></td><td><?php echo (int)$r['n']; ?></td><td><?php echo (int)$r['active']; ?></td></tr>
      <?php endforeach; ?>
    </tbody>
  </table>
  <?php endif; ?>
  <p>Add, invite, deactivate and set permissions in <strong>Settings &rarr; Staff &amp; Access</strong> (see <a href="18-staff-access.html">Staff &amp; access</a>). Deactivate people who leave rather than deleting them, so their name stays on what they handled.</p>

  <h2 id="routine">Daily, weekly and monthly checks</h2>
  <h3>Every day</h3>
  <ul>
    <li>Run the end-of-day report (Money &rarr; End of Day) and check cash against it — see <a href="14-reports-eod.html">Reports &amp; end of day</a>.</li>
    <li>Look at the <strong>System needs attention</strong> box on the Dashboard and deal with anything listed.</li>
    <li>Check today's arrivals and departures on the Dashboard, and that every departed guest has a zero balance.</li>
  </ul>
  <h3>Every week</h3>
  <ul>
    <li>Confirm a backup has run. Last backup: <?php echo $backupAgeH === null ? '<strong>no backup recorded</strong>' : $e($lastBackup) . ' (' . (int)$backupAgeH . ' hours ago)'; ?>. Backups are in Settings &rarr; Backups.</li>
    <li>Review Staff &amp; Access: deactivate anyone who has left.</li>
    <li>Review outstanding balances (Dashboard &rarr; <strong>Owed to us</strong>).</li>
  </ul>
  <h3>Every month</h3>
  <ul>
    <li>Run the monthly reports (Money &rarr; Reports) and export what the accountant needs.</li>
    <li>Run a stock count if the restaurant is in use — see <a href="08-stock-orders.html">Stock</a>.</li>
    <li>Check room prices and rate plans for the coming season.</li>
  </ul>
  <?php if ($backupAgeH === null || $backupAgeH > 36): ?>
  <div class="warn"><p>No recent backup is recorded. Ask your system administrator to confirm the scheduled backup is running, and take a manual backup from Settings &rarr; Backups now.</p></div>
  <?php endif; ?>

  <h2 id="go-live">Before go-live checklist</h2>
  <ol class="steps">
    <li>Website address set to the real domain (Hotel Settings &rarr; Hotel details &amp; policies).</li>
    <li>Real bank details on invoices (same place).</li>
    <li>Emails sent from a mailbox the hotel owns, and new-booking alerts going to a mailbox someone reads (Hotel Settings &rarr; Email Configuration / Booking Notification Email). Send yourself a test email.</li>
    <li>Correct phone, email, WhatsApp and address in Footer Management.</li>
    <li>Room prices and occupancy prices checked (Settings &rarr; Rooms).</li>
    <li>Every staff member invited with the right role and permissions; test accounts deleted.</li>
    <li>All test bookings and payments removed.</li>
    <li>A backup taken and the scheduled backup confirmed.</li>
  </ol>

  <footer>Updated October 2026. Values on this page are read live each time it opens.</footer>
</div>
</body>
</html>
