<?php
/**
 * docs/guides/owner-handover.php
 * Owner / Operator Handover Document — fetches live settings from the database.
 */
declare(strict_types=1);

// Require database config from two levels up
$dbConfig = __DIR__ . '/../../config/database.php';
$dbAvailable = false;
if (file_exists($dbConfig)) {
    require_once $dbConfig;
    $dbAvailable = isset($pdo);
}

// Helper: safe setting fetch
function ho(string $key, string $default = ''): string {
    if (!function_exists('getSetting')) return $default;
    $v = getSetting($key, $default);
    return htmlspecialchars((string)$v, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

// Fetch live settings
$siteName       = ho('site_name', 'Hotel');
$tagline        = ho('site_tagline', 'Where hospitality meets technology');
$hotelAddress   = ho('hotel_address', '');
$hotelPhone     = ho('hotel_phone', '');
$hotelEmail     = ho('hotel_email', '');
$hotelCity      = ho('hotel_city', '');
$currency       = ho('currency_symbol', 'MWK');
$currencyCode   = ho('currency_code', 'MWK');
$refPrefix      = ho('booking_reference_prefix', 'BK');
$invPrefix      = ho('invoice_prefix', 'INV');
$vatEnabled     = getSetting('vat_enabled', '0');
$vatRate        = ho('vat_rate', '16.5');
$levyEnabled    = getSetting('tourism_levy_enabled', '0');
$levyPct        = ho('tourism_levy_percent', '0');
$checkinTime    = ho('checkin_time', '2:00 PM');
$checkoutTime   = ho('checkout_time', '11:00 AM');
$tentHours      = ho('tentative_duration_hours', '48');
$maxAdvance     = ho('max_advance_booking_days', '365');
$whatsapp       = ho('whatsapp_number', '');
$website        = ho('website_url', '');
$adminEmail     = ho('admin_email', '');

// Fetch live DB stats
$stats = [
    'room_types'      => 0,
    'individual_rooms'=> 0,
    'active_staff'    => 0,
    'bookings_total'  => 0,
    'migrations'      => 0,
    'menu_items'      => 0,
];
if ($dbAvailable && isset($pdo)) {
    $q = fn(string $sql) => (int)($pdo->query($sql)->fetchColumn() ?: 0);
    try { $stats['room_types']       = $q("SELECT COUNT(*) FROM rooms WHERE is_active=1"); } catch (Throwable $e) {}
    try { $stats['individual_rooms'] = $q("SELECT COUNT(*) FROM individual_rooms WHERE is_active=1"); } catch (Throwable $e) {}
    try { $stats['active_staff']     = $q("SELECT COUNT(*) FROM admin_users WHERE is_active=1"); } catch (Throwable $e) {}
    try { $stats['bookings_total']   = $q("SELECT COUNT(*) FROM bookings"); } catch (Throwable $e) {}
    try { $stats['migrations'] = count(glob(__DIR__ . '/../../admin/migrations/*.php') ?: []); } catch (Throwable $e) {}
    try { $stats['menu_items']       = $q("SELECT (SELECT COUNT(*) FROM food_menu WHERE is_available=1)+(SELECT COUNT(*) FROM drink_menu WHERE is_available=1)"); } catch (Throwable $e) {}
}

$vatPricingMode = getSetting('vat_pricing_mode', 'exclusive') === 'inclusive'
    ? 'prices include VAT' : 'VAT added on top';
$vatStatus = in_array($vatEnabled, ['1', 1, true, 'true', 'on'], true)
    ? "Enabled @ {$vatRate}% ({$vatPricingMode})" : "Disabled";
$levyStatus = in_array($levyEnabled, ['1', 1, true, 'true', 'on'], true)
    ? "Enabled @ {$levyPct}%" : "Disabled";

$year = date('Y');
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title><?= $siteName ?> — System Handover</title>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Cormorant+Garamond:wght@400;500;600;700&family=Jost:wght@300;400;500;600&display=swap" rel="stylesheet">
<style>
:root {
  --gold: #d4a843;
  --gold-soft: #e8c878;
  --dark: #1a1a1a;
  --brown: #8b7355;
  --cream: #f8f3e9;
  --ink: #2a2a2a;
  --muted: #6c6c6c;
  --line: rgba(212, 168, 67, 0.25);
  --shadow: 0 18px 40px -18px rgba(26, 26, 26, 0.35);
  --success: #2d7a3a;
  --warning: #c97b00;
  --danger: #b53232;
}

* { box-sizing: border-box; margin: 0; padding: 0; }
html { scroll-behavior: smooth; }

body {
  font-family: 'Jost', sans-serif;
  font-size: clamp(15px, 0.55vw + 13px, 18px);
  line-height: 1.7;
  color: var(--ink);
  background: var(--cream);
}

h1,h2,h3,h4 { font-family: 'Cormorant Garamond', serif; font-weight: 500; line-height: 1.2; color: var(--dark); }
h1 { font-size: clamp(2.4rem, 5vw, 4.8rem); letter-spacing: -0.5px; }
h2 { font-size: clamp(1.8rem, 3.4vw, 3rem); margin-bottom: 1.25rem; }
h3 { font-size: clamp(1.2rem, 1.8vw, 1.65rem); margin-bottom: 0.6rem; color: var(--brown); }
h4 { font-size: 1.1rem; margin-bottom: 0.4rem; }

p { margin-bottom: 1rem; max-width: 72ch; }
strong { color: var(--dark); font-weight: 600; }
em { color: var(--brown); font-style: italic; }
code { font-family: 'SF Mono','Consolas','Fira Code',monospace; font-size: 0.88em; background: rgba(212,168,67,0.12); padding: 0.15em 0.45em; border-radius: 4px; color: var(--brown); }

a { color: var(--gold); text-decoration: none; border-bottom: 1px solid var(--line); transition: 0.2s; }
a:hover { color: var(--brown); border-color: var(--brown); }

/* ── Layout ── */
.deck { max-width: 1180px; margin: 0 auto; padding: 0 clamp(1rem, 4vw, 3rem); }

section.slide {
  min-height: 85vh;
  padding: clamp(3rem, 9vw, 7rem) 0 clamp(3rem, 6vw, 5rem);
  display: flex;
  flex-direction: column;
  justify-content: center;
  border-bottom: 1px solid var(--line);
  scroll-snap-align: start;
  scroll-margin-top: 56px;
}

.eyebrow {
  font-family: 'Jost', sans-serif;
  text-transform: uppercase;
  letter-spacing: 0.25em;
  font-size: 0.78rem;
  color: var(--gold);
  font-weight: 500;
  margin-bottom: 1rem;
}

.lead {
  font-family: 'Cormorant Garamond', serif;
  font-size: clamp(1.2rem, 1.8vw, 1.5rem);
  font-style: italic;
  color: var(--brown);
  max-width: 62ch;
  margin-bottom: 1.5rem;
}

/* ── Hero ── */
.hero {
  background: linear-gradient(135deg, var(--dark) 0%, #2a2418 100%);
  color: var(--cream);
  text-align: center;
  position: relative;
  overflow: hidden;
  min-height: 100vh;
}
.hero::before {
  content: '';
  position: absolute;
  inset: 0;
  background: radial-gradient(circle at 30% 30%, rgba(212,168,67,0.15), transparent 60%),
              radial-gradient(circle at 70% 70%, rgba(212,168,67,0.08), transparent 50%);
  pointer-events: none;
}
.hero h1 { color: var(--cream); position: relative; }
.hero .crest {
  font-family: 'Cormorant Garamond', serif;
  font-size: 2.6rem;
  color: var(--gold);
  letter-spacing: 0.4em;
  margin-bottom: 2rem;
  font-style: italic;
}
.hero .lead { color: var(--gold-soft); margin: 1.5rem auto 0; }
.hero .meta {
  margin-top: 3rem;
  font-size: 0.9rem;
  color: rgba(248,243,233,0.6);
  letter-spacing: 0.2em;
  text-transform: uppercase;
}
.hero .badges {
  margin-top: 2rem;
  display: flex;
  gap: 0.75rem;
  justify-content: center;
  flex-wrap: wrap;
}
.hero .badge {
  background: rgba(212,168,67,0.18);
  border: 1px solid rgba(212,168,67,0.4);
  color: var(--gold-soft);
  padding: 0.35rem 1rem;
  border-radius: 20px;
  font-size: 0.82rem;
  letter-spacing: 0.12em;
  font-weight: 500;
}
.divider { width: 60px; height: 1px; background: var(--gold); margin: 1.5rem auto; }

/* ── Cards ── */
.cards {
  display: grid;
  grid-template-columns: repeat(auto-fit, minmax(260px, 1fr));
  gap: 1.25rem;
  margin-top: 2rem;
}
.card {
  background: white;
  border: 1px solid var(--line);
  border-radius: 14px;
  padding: 1.6rem;
  box-shadow: var(--shadow);
  transition: 0.3s;
  text-decoration: none;
  color: inherit;
  display: block;
}
.card:hover { transform: translateY(-3px); border-color: var(--gold); box-shadow: 0 24px 48px -18px rgba(26,26,26,0.45); }
.card .icon {
  width: 44px; height: 44px;
  background: linear-gradient(135deg, var(--gold), var(--gold-soft));
  border-radius: 10px;
  display: inline-flex; align-items: center; justify-content: center;
  font-family: 'Cormorant Garamond', serif;
  font-size: 1.6rem;
  color: var(--dark);
  margin-bottom: 0.9rem;
  font-weight: 600;
  flex-shrink: 0;
}
.card h3 { margin-bottom: 0.4rem; }

/* guide-link cards */
.guide-card {
  display: flex;
  align-items: flex-start;
  gap: 1rem;
  background: white;
  border: 1px solid var(--line);
  border-radius: 12px;
  padding: 1.2rem 1.5rem;
  box-shadow: 0 6px 20px -8px rgba(26,26,26,0.22);
  transition: 0.3s;
  text-decoration: none;
  color: inherit;
}
.guide-card:hover { border-color: var(--gold); transform: translateY(-2px); }
.guide-card .num {
  font-family: 'Cormorant Garamond', serif;
  font-size: 1.8rem;
  color: var(--gold);
  font-weight: 700;
  line-height: 1;
  min-width: 2rem;
}
.guide-card .title { font-weight: 600; color: var(--dark); font-size: 1rem; }
.guide-card .sub { font-size: 0.86rem; color: var(--muted); margin-top: 0.2rem; }
.pill {
  display: inline-block;
  padding: 0.2rem 0.75rem;
  border-radius: 20px;
  font-size: 0.76rem;
  font-weight: 600;
  letter-spacing: 0.06em;
  margin-top: 0.5rem;
}
.pill.gold { background: rgba(212,168,67,0.15); color: var(--brown); }
.pill.green { background: rgba(45,122,58,0.12); color: var(--success); }

/* ── Stat row ── */
.stat-row {
  display: grid;
  grid-template-columns: repeat(auto-fit, minmax(160px, 1fr));
  gap: 1rem;
  margin: 2rem 0;
}
.stat {
  background: white;
  border-left: 3px solid var(--gold);
  padding: 1.2rem 1.4rem;
  border-radius: 6px;
  box-shadow: 0 4px 16px -6px rgba(26,26,26,0.18);
}
.stat .num {
  font-family: 'Cormorant Garamond', serif;
  font-size: 2.4rem;
  color: var(--brown);
  display: block;
  line-height: 1;
}
.stat .lbl {
  font-size: 0.82rem;
  color: var(--muted);
  letter-spacing: 0.1em;
  text-transform: uppercase;
  margin-top: 0.5rem;
  display: block;
}

/* ── Tables ── */
table {
  width: 100%;
  border-collapse: collapse;
  margin: 1.5rem 0;
  background: white;
  border-radius: 10px;
  overflow: hidden;
  box-shadow: var(--shadow);
}
th { background: var(--dark); color: var(--cream); text-align: left; padding: 0.85rem 1rem; font-weight: 500; letter-spacing: 0.05em; font-size: 0.9rem; }
td { padding: 0.85rem 1rem; border-bottom: 1px solid var(--line); vertical-align: top; font-size: 0.95rem; }
tr:last-child td { border-bottom: none; }
tr:nth-child(even) td { background: rgba(248,243,233,0.4); }
.tag-yes { color: var(--success); font-weight: 600; }
.tag-no  { color: var(--muted); }

/* ── Lists ── */
ul.checklist { list-style: none; padding: 0; }
ul.checklist li {
  padding: 0.5rem 0 0.5rem 2rem;
  position: relative;
  border-bottom: 1px dashed var(--line);
}
ul.checklist li::before {
  content: '✓';
  position: absolute;
  left: 0; top: 0.5rem;
  color: var(--gold);
  font-weight: 700;
  font-size: 1.1rem;
}
ul.checklist li:last-child { border-bottom: none; }

ol.steps { padding-left: 0; counter-reset: step; list-style: none; }
ol.steps > li {
  position: relative;
  padding: 0.7rem 0 0.7rem 3rem;
  counter-increment: step;
  border-bottom: 1px dashed var(--line);
}
ol.steps > li:last-child { border-bottom: none; }
ol.steps > li::before {
  content: counter(step);
  position: absolute;
  left: 0; top: 0.5rem;
  width: 32px; height: 32px;
  background: var(--gold);
  color: var(--dark);
  border-radius: 50%;
  display: inline-flex;
  align-items: center; justify-content: center;
  font-weight: 600;
  font-family: 'Cormorant Garamond', serif;
  font-size: 1.1rem;
}

/* ── Roles table ── */
.role-grid {
  display: grid;
  grid-template-columns: repeat(auto-fit, minmax(240px, 1fr));
  gap: 0.75rem;
  margin-top: 1.5rem;
}
.role-item {
  background: white;
  border: 1px solid var(--line);
  border-radius: 8px;
  padding: 0.9rem 1.1rem;
  display: flex;
  align-items: flex-start;
  gap: 0.75rem;
}
.role-dot {
  width: 10px; height: 10px;
  border-radius: 50%;
  background: var(--gold);
  margin-top: 0.55rem;
  flex-shrink: 0;
}
.role-item strong { display: block; color: var(--dark); font-size: 0.96rem; }
.role-item small { color: var(--muted); font-size: 0.82rem; }

/* ── Info box ── */
.infobox {
  background: white;
  border-left: 4px solid var(--gold);
  border-radius: 6px;
  padding: 1rem 1.4rem;
  margin: 1.5rem 0;
  box-shadow: 0 4px 16px -6px rgba(26,26,26,0.14);
}
.infobox.warning { border-color: var(--warning); background: rgba(201,123,0,0.04); }
.infobox.danger  { border-color: var(--danger);  background: rgba(181,50,50,0.04); }
.infobox.success { border-color: var(--success);  background: rgba(45,122,58,0.04); }
.infobox strong  { display: block; margin-bottom: 0.3rem; }

/* ── Quote ── */
blockquote {
  font-family: 'Cormorant Garamond', serif;
  font-style: italic;
  font-size: 1.45rem;
  color: var(--brown);
  border-left: 3px solid var(--gold);
  padding: 1rem 1.5rem;
  margin: 2rem 0;
  background: white;
  border-radius: 6px;
  box-shadow: 0 4px 16px -6px rgba(26,26,26,0.14);
}

/* ── Settings kv ── */
.kv-grid {
  display: grid;
  grid-template-columns: repeat(auto-fit, minmax(300px, 1fr));
  gap: 0.75rem;
  margin-top: 1.25rem;
}
.kv {
  background: white;
  border: 1px solid var(--line);
  border-radius: 8px;
  padding: 0.8rem 1.1rem;
  display: flex;
  gap: 0.75rem;
  align-items: baseline;
}
.kv .k { font-size: 0.82rem; text-transform: uppercase; letter-spacing: 0.08em; color: var(--muted); min-width: 11ch; }
.kv .v { font-weight: 600; color: var(--dark); font-size: 0.97rem; word-break: break-all; }
.kv .v.empty { color: var(--muted); font-weight: 400; font-style: italic; }

/* ── Top nav ── */
nav.top {
  position: fixed;
  top: 0; left: 0; right: 0;
  background: rgba(26,26,26,0.95);
  backdrop-filter: blur(12px);
  z-index: 100;
  padding: 0.7rem 1.2rem;
  display: flex;
  justify-content: space-between;
  align-items: center;
  border-bottom: 1px solid rgba(212,168,67,0.2);
}
nav.top .brand {
  color: var(--gold);
  font-family: 'Cormorant Garamond', serif;
  font-size: 1.15rem;
  font-style: italic;
  letter-spacing: 0.18em;
  text-decoration: none;
  border: none;
}
nav.top .nav-links { display: flex; gap: 0.75rem; align-items: center; }
nav.top .nav-links a {
  color: rgba(248,243,233,0.7);
  font-size: 0.82rem;
  letter-spacing: 0.08em;
  border: none;
  padding: 0.3rem 0.7rem;
  border-radius: 20px;
  transition: 0.2s;
}
nav.top .nav-links a:hover { color: var(--gold); background: rgba(212,168,67,0.1); }
.toc-toggle {
  background: transparent;
  color: var(--cream);
  border: 1px solid rgba(248,243,233,0.25);
  padding: 0.4rem 0.9rem;
  border-radius: 20px;
  cursor: pointer;
  font-family: inherit;
  font-size: 0.82rem;
  letter-spacing: 0.1em;
  transition: 0.2s;
}
.toc-toggle:hover { background: var(--gold); color: var(--dark); border-color: var(--gold); }

/* ── TOC panel ── */
.toc-panel {
  position: fixed;
  top: 56px;
  right: 1rem;
  background: white;
  box-shadow: var(--shadow);
  border-radius: 12px;
  padding: 1.2rem 1.5rem;
  border: 1px solid var(--line);
  display: none;
  z-index: 99;
  width: 320px;
  max-height: 78vh;
  overflow-y: auto;
}
.toc-panel.open { display: block; }
.toc-panel h4 { color: var(--gold); font-family: 'Jost',sans-serif; font-size: 0.78rem; text-transform: uppercase; letter-spacing: 0.2em; margin-bottom: 0.75rem; }
.toc-panel ol { padding-left: 1.3rem; font-size: 0.9rem; }
.toc-panel ol li { padding: 0.25rem 0; }
.toc-panel ol li a { border: none; color: var(--ink); }
.toc-panel ol li a:hover { color: var(--gold); }

/* ── Footer ── */
footer.handover {
  background: var(--dark);
  color: var(--cream);
  padding: 5rem 0 3rem;
  text-align: center;
}
footer.handover .crest { color: var(--gold); font-family: 'Cormorant Garamond', serif; font-style: italic; font-size: 1.4rem; letter-spacing: 0.3em; margin-bottom: 1rem; }
footer.handover p { margin: 0.5rem auto; color: rgba(248,243,233,0.75); max-width: 52ch; }
footer.handover .signature { font-family: 'Cormorant Garamond', serif; font-style: italic; color: var(--gold); font-size: 1.3rem; margin-top: 2rem; }
footer.handover .links { margin-top: 2rem; display: flex; justify-content: center; gap: 1.5rem; flex-wrap: wrap; }
footer.handover .links a { color: rgba(248,243,233,0.55); font-size: 0.88rem; border: none; letter-spacing: 0.08em; }
footer.handover .links a:hover { color: var(--gold); }

/* ── Print ── */
@media print {
  nav.top, .toc-panel { display: none !important; }
  section.slide { page-break-after: always; min-height: auto; padding: 1rem 0; }
  body { background: white; font-size: 11pt; }
  .card, table, blockquote { box-shadow: none; border: 1px solid #ccc; }
  .hero { background: white; color: var(--dark); min-height: auto; }
  .hero h1, .hero .crest, .hero .lead { color: var(--dark); }
  a { color: var(--ink); border: none; }
}

@media (max-width: 640px) {
  section.slide { min-height: auto; padding: 3rem 0; }
  blockquote { font-size: 1.15rem; }
  table { font-size: 0.85rem; }
  th, td { padding: 0.6rem 0.7rem; }
  nav.top .nav-links { display: none; }
}
</style>
</head>
<body>

<nav class="top">
  <a href="index.html" class="brand"><?= strtoupper($siteName) ?></a>
  <div class="nav-links">
    <a href="index.html">All Guides</a>
    <a href="99-admin-dashboard-full-guide.html">Admin Bible</a>
  </div>
  <button class="toc-toggle" onclick="document.getElementById('toc').classList.toggle('open')">CONTENTS</button>
</nav>

<aside class="toc-panel" id="toc">
  <h4>Chapters</h4>
  <ol>
    <li><a href="#welcome">Welcome</a></li>
    <li><a href="#overview">System overview</a></li>
    <li><a href="#config">Live configuration</a></li>
    <li><a href="#booking">Booking engine</a></li>
    <li><a href="#frontdesk">Front desk &amp; check-in/out</a></li>
    <li><a href="#folio">Guest folio &amp; invoicing</a></li>
    <li><a href="#fnb">F&amp;B — POS, KDS, BDS, CDS</a></li>
    <li><a href="#roomservice">Room service</a></li>
    <li><a href="#housekeeping">Housekeeping &amp; maintenance</a></li>
    <li><a href="#conference">Conference &amp; events</a></li>
    <li><a href="#media">Menu, media &amp; content</a></li>
    <li><a href="#stock">Stock &amp; inventory</a></li>
    <li><a href="#accounting">Finance &amp; accounting</a></li>
    <li><a href="#reports">Reports &amp; analytics</a></li>
    <li><a href="#notifications">Email &amp; WhatsApp</a></li>
    <li><a href="#users">User management &amp; RBAC</a></li>
    <li><a href="#security">Security &amp; audit log</a></li>
    <li><a href="#infra">Infrastructure &amp; cron</a></li>
    <li><a href="#routine">Daily / weekly / monthly</a></li>
    <li><a href="#guides">Staff guides</a></li>
    <li><a href="#support">Support &amp; credentials</a></li>
    <li><a href="#close">Closing</a></li>
  </ol>
</aside>

<!-- ═══════════════════════════════════════════════
     HERO
══════════════════════════════════════════════════ -->
<section class="slide hero" id="welcome">
  <div class="deck">
    <div class="crest">— <?= implode(' ', array_map(fn($w) => strtoupper(mb_substr($w,0,1)), array_filter(explode(' ', getSetting('site_name','Hotel'))))) ?> —</div>
    <h1><?= $siteName ?></h1>
    <div class="divider"></div>
    <p class="lead"><?= $tagline ?></p>
    <div class="badges">
      <span class="badge">SYSTEM HANDOVER</span>
      <span class="badge"><?= $year ?> EDITION</span>
      <?php if ($hotelCity): ?><span class="badge"><?= $hotelCity ?></span><?php endif; ?>
    </div>
    <div class="meta" style="margin-top:2rem;">A complete operational handover &middot; from the front desk to the kitchen, from the bar to the boardroom</div>
  </div>
</section>

<!-- ═══════════════════════════════════════════════
     OVERVIEW
══════════════════════════════════════════════════ -->
<section class="slide" id="overview">
  <div class="deck">
    <div class="eyebrow">Chapter 1 &middot; What you have</div>
    <h2>One platform. Every department.</h2>
    <p class="lead">Every reservation, every drink, every plate, every payment — flows through one beautifully connected system.</p>

    <div class="stat-row">
      <div class="stat">
        <span class="num"><?= $stats['room_types'] ?: '—' ?></span>
        <span class="lbl">Room types</span>
      </div>
      <div class="stat">
        <span class="num"><?= $stats['individual_rooms'] ?: '—' ?></span>
        <span class="lbl">Individual rooms</span>
      </div>
      <div class="stat">
        <span class="num"><?= $stats['active_staff'] ?: '—' ?></span>
        <span class="lbl">Active staff</span>
      </div>
      <div class="stat">
        <span class="num"><?= $stats['menu_items'] ?: '—' ?></span>
        <span class="lbl">Menu items</span>
      </div>
      <div class="stat">
        <span class="num">11</span>
        <span class="lbl">Staff roles</span>
      </div>
      <div class="stat">
        <span class="num"><?= $stats['migrations'] ?: '—' ?></span>
        <span class="lbl">Migration scripts</span>
      </div>
    </div>

    <div class="cards">
      <div class="card">
        <div class="icon">B</div>
        <h3>Bookings</h3>
        <p>Public website → front desk. Tentatives, confirmations, check-in, check-out, automated invoicing and emails.</p>
      </div>
      <div class="card">
        <div class="icon">F</div>
        <h3>Food &amp; Beverage</h3>
        <p>Touch-screen POS for the till, KDS for the kitchen, BDS for the bar, CDS for coffee — all in real-time.</p>
      </div>
      <div class="card">
        <div class="icon">R</div>
        <h3>Room Service</h3>
        <p>Charges fly straight to the guest folio. Stock decrements automatically. Voids restore both stock and folio.</p>
      </div>
      <div class="card">
        <div class="icon">H</div>
        <h3>Housekeeping</h3>
        <p>Live room-status board with full audit log. Out-of-service rooms are removed from bookable inventory.</p>
      </div>
      <div class="card">
        <div class="icon">S</div>
        <h3>Stock &amp; Finance</h3>
        <p>Recipe-linked FIFO stock, end-of-day variance, payments, refunds, sequential invoices, accounting dashboard.</p>
      </div>
      <div class="card">
        <div class="icon">C</div>
        <h3>Conference &amp; Events</h3>
        <p>Conference room bookings, event listings with media gallery, public event calendar on the website.</p>
      </div>
      <div class="card">
        <div class="icon">A</div>
        <h3>Analytics</h3>
        <p>Occupancy, RevPAR, ADR, top-sellers, visitor traffic and conversion — plus CSV export for your accountant.</p>
      </div>
      <div class="card">
        <div class="icon">M</div>
        <h3>Media &amp; Content</h3>
        <p>Full CMS for all page content, gallery, room photos, videos and section headers — no code needed.</p>
      </div>
    </div>
  </div>
</section>

<!-- ═══════════════════════════════════════════════
     LIVE CONFIGURATION
══════════════════════════════════════════════════ -->
<section class="slide" id="config">
  <div class="deck">
    <div class="eyebrow">Chapter 2 &middot; Your live settings</div>
    <h2>Configuration at a glance</h2>
    <p class="lead">These values are read live from the database. Change any of them in <strong>Admin → Site Settings</strong> and they take effect instantly.</p>

    <h3>Brand &amp; identity</h3>
    <div class="kv-grid">
      <div class="kv"><span class="k">Hotel name</span><span class="v <?= $siteName ? '' : 'empty' ?>"><?= $siteName ?: 'Not set' ?></span></div>
      <div class="kv"><span class="k">Tagline</span><span class="v <?= $tagline ? '' : 'empty' ?>"><?= $tagline ?: 'Not set' ?></span></div>
      <div class="kv"><span class="k">Address</span><span class="v <?= $hotelAddress ? '' : 'empty' ?>"><?= $hotelAddress ?: 'Not set' ?></span></div>
      <div class="kv"><span class="k">City</span><span class="v <?= $hotelCity ? '' : 'empty' ?>"><?= $hotelCity ?: 'Not set' ?></span></div>
      <div class="kv"><span class="k">Phone</span><span class="v <?= $hotelPhone ? '' : 'empty' ?>"><?= $hotelPhone ?: 'Not set' ?></span></div>
      <div class="kv"><span class="k">Email</span><span class="v <?= $hotelEmail ? '' : 'empty' ?>"><?= $hotelEmail ?: 'Not set' ?></span></div>
      <div class="kv"><span class="k">Website</span><span class="v <?= $website ? '' : 'empty' ?>"><?= $website ?: 'Not set' ?></span></div>
      <div class="kv"><span class="k">WhatsApp</span><span class="v <?= $whatsapp ? '' : 'empty' ?>"><?= $whatsapp ?: 'Not configured' ?></span></div>
    </div>

    <h3 style="margin-top:2rem;">Booking policy</h3>
    <div class="kv-grid">
      <div class="kv"><span class="k">Ref prefix</span><span class="v"><?= $refPrefix ?></span></div>
      <div class="kv"><span class="k">Invoice prefix</span><span class="v"><?= $invPrefix ?></span></div>
      <div class="kv"><span class="k">Check-in time</span><span class="v"><?= $checkinTime ?></span></div>
      <div class="kv"><span class="k">Check-out</span><span class="v"><?= $checkoutTime ?></span></div>
      <div class="kv"><span class="k">Tentative hold</span><span class="v"><?= $tentHours ?> hours</span></div>
      <div class="kv"><span class="k">Max advance</span><span class="v"><?= $maxAdvance ?> days</span></div>
    </div>

    <h3 style="margin-top:2rem;">Taxes &amp; levies</h3>
    <div class="kv-grid">
      <div class="kv"><span class="k">Currency</span><span class="v"><?= $currency ?> (<?= $currencyCode ?>)</span></div>
      <div class="kv"><span class="k">VAT</span><span class="v"><?= $vatStatus ?></span></div>
      <div class="kv"><span class="k">Tourism levy</span><span class="v"><?= $levyStatus ?></span></div>
    </div>

    <div class="infobox" style="margin-top:2rem;">
      <strong>Admin path</strong>
      All settings live at <code>Admin → Site Settings</code>. Changes save to the <code>site_settings</code> database table and propagate in real time — no config files to edit, no deployment needed.
    </div>
  </div>
</section>

<!-- ═══════════════════════════════════════════════
     BOOKING ENGINE
══════════════════════════════════════════════════ -->
<section class="slide" id="booking">
  <div class="deck">
    <div class="eyebrow">Chapter 3 &middot; Reservations</div>
    <h2>The booking engine</h2>
    <p class="lead">A guest's first impression begins on your website. Every booking is captured, confirmed, and converted into revenue automatically.</p>

    <h3>Booking lifecycle</h3>
    <ol class="steps">
      <li><strong>Pending</strong> — the website request lands. A confirmation email fires immediately.</li>
      <li><strong>Tentative</strong> — hold a room for <?= $tentHours ?> hours while the guest decides. The cron script <code>scripts/expire_tentative_bookings.php</code> runs every 15 minutes and flags any hold past its window, but it does <strong>not</strong> auto-cancel or release the room — a staff member must review and manually cancel expired tentatives.</li>
      <li><strong>Confirmed</strong> — room is reserved. Guest gets a full confirmation with room details and policy.</li>
      <li><strong>Checked-in</strong> — front desk opens the folio; live room availability decrements.</li>
      <li><strong>Checked-out</strong> — final invoice emailed automatically with VAT (<em><?= $vatStatus ?></em>) and tourism levy (<em><?= $levyStatus ?></em>).</li>
      <li><strong>Cancelled</strong> — soft-cancel only. Audit trail preserved, room availability restored automatically.</li>
      <li><strong>Expired</strong> — a tentative booking whose <?= $tentHours ?>-hour window passed without action. It stays flagged until a manager manually cancels it; the room is not released automatically.</li>
    </ol>

    <blockquote>"Never delete a booking — always cancel. The system protects your audit trail."</blockquote>

    <h3>Creating bookings</h3>
    <table>
      <thead><tr><th>Route</th><th>Who uses it</th><th>Notes</th></tr></thead>
      <tbody>
        <tr><td>Public website <code>/booking.php</code></td><td>Guests self-serve</td><td>Checks availability atomically; anti-race-condition lock on room row</td></tr>
        <tr><td>Admin → Create Booking</td><td>Receptionist / manager</td><td>Override price, assign specific room number, skip deposit</td></tr>
        <tr><td>API <code>/api/bookings.php</code></td><td>Third-party OTA / channel manager</td><td>Same race-safe availability check, returns JSON</td></tr>
      </tbody>
    </table>

    <h3>Check-availability widget</h3>
    <p>The public <code>/check-availability.php</code> page shows live per-room-type availability for any date range. It reads directly from the <code>bookings</code> table using blocking-status logic — tentative, confirmed, and checked-in bookings all block rooms.</p>

    <h3>Blocked dates</h3>
    <p>Admin → Blocked Dates lets you close specific rooms or the entire hotel for renovations, private events, or low-season closure — without creating fake bookings.</p>
  </div>
</section>

<!-- ═══════════════════════════════════════════════
     FRONT DESK
══════════════════════════════════════════════════ -->
<section class="slide" id="frontdesk">
  <div class="deck">
    <div class="eyebrow">Chapter 4 &middot; Reception</div>
    <h2>Front desk &amp; check-in / check-out</h2>
    <p class="lead">The receptionist's daily workflow, from morning arrivals to evening departures.</p>

    <h3>Morning: arrivals</h3>
    <ol class="steps">
      <li>Open <strong>Admin → Dashboard</strong> — today's arrivals are listed at the top.</li>
      <li>Find the booking, click <strong>Check In</strong>. Assign a specific room number if not pre-assigned.</li>
      <li>Status moves to <em>checked-in</em>; housekeeping sees the room as occupied; the folio opens.</li>
      <li>Any room-service or F&amp;B charges the guest incurs during the stay post automatically to the folio.</li>
    </ol>

    <h3>Evening: departures</h3>
    <ol class="steps">
      <li>Open Admin → Bookings, filter by <em>today's check-outs</em>.</li>
      <li>Click the booking → <strong>Check Out</strong>.</li>
      <li>Review the folio — add any outstanding charges (minibar, parking, extras).</li>
      <li>Confirm payment method; the system generates the final invoice, emails it, and archives it under Admin → Invoices.</li>
      <li>A housekeeping assignment is created at <em>Pending</em> — housekeeping picks it up for cleaning.</li>
    </ol>

    <h3>Walk-ins &amp; same-day bookings</h3>
    <p>Use <strong>Admin → Create Booking</strong>, set status directly to <em>checked-in</em>, and assign a room number on the same screen.</p>

    <h3>Booking lookup</h3>
    <p>Guests can look up their own booking at <code>/booking-lookup.php</code> using the booking reference and email — useful for self-service pre-arrival questions.</p>
  </div>
</section>

<!-- ═══════════════════════════════════════════════
     FOLIO & INVOICING
══════════════════════════════════════════════════ -->
<section class="slide" id="folio">
  <div class="deck">
    <div class="eyebrow">Chapter 5 &middot; Financial</div>
    <h2>Guest folio &amp; invoicing</h2>
    <p class="lead">Every charge, payment, and refund is attached to the booking's folio — the single source of truth for what the guest owes.</p>

    <h3>What's on a folio</h3>
    <table>
      <thead><tr><th>Line type</th><th>Created by</th><th>Notes</th></tr></thead>
      <tbody>
        <tr><td>Room rate</td><td>Booking creation</td><td>Nights × occupancy rate</td></tr>
        <tr><td>Room service</td><td>POS / room-service staff</td><td>Automatically posted; stock deducted same instant</td></tr>
        <tr><td>Food &amp; drink charge</td><td>Manual add from Admin</td><td>Any ad-hoc charge</td></tr>
        <tr><td>VAT</td><td>System (if enabled)</td><td>Currently: <em><?= $vatStatus ?></em></td></tr>
        <tr><td>Tourism levy</td><td>System (if enabled)</td><td>Currently: <em><?= $levyStatus ?></em></td></tr>
        <tr><td>Extras / notes</td><td>Receptionist</td><td>Parking, late check-out, etc.</td></tr>
      </tbody>
    </table>

    <h3>Payments</h3>
    <p>Admin → Payments → Add Payment. Supported methods: Cash, Mobile Money, Credit Card, Bank Transfer, Cheque. Each payment is stamped with method, reference, and recorded-by staff member.</p>

    <h3>Invoices</h3>
    <p>Invoice numbers are sequential: <code><?= $invPrefix ?>-<?= $year ?>-000001</code> and never reused. PDFs are generated server-side, emailed automatically on checkout, and accessible under <strong>Admin → Invoices</strong>.</p>

    <h3>Refunds</h3>
    <p>Admin → Payments → Refund. Refunds are recorded as separate <em>refund</em> payment rows — never negative amendments — so the accounting ledger stays accurate.</p>

    <div class="infobox warning">
      <strong>Partial payments</strong>
      A booking can have multiple payments (deposit + balance). The <em>outstanding balance</em> updates automatically after each payment. The <em>paid in full</em> flag only sets when the total received ≥ total_amount.
    </div>
  </div>
</section>

<!-- ═══════════════════════════════════════════════
     F&B
══════════════════════════════════════════════════ -->
<section class="slide" id="fnb">
  <div class="deck">
    <div class="eyebrow">Chapter 6 &middot; Dining floor</div>
    <h2>The connected dining floor</h2>
    <p class="lead">When a waiter taps an order, four screens update at once — and the stock ledger closes before the plate leaves the pass.</p>

    <table>
      <thead><tr><th>Screen</th><th>Who uses it</th><th>URL</th><th>Stock impact</th></tr></thead>
      <tbody>
        <tr><td><strong>POS Till</strong></td><td>Cashier — takes &amp; pays orders</td><td><code>/admin/pos.php</code></td><td>Fires order; atomic stock deduction</td></tr>
        <tr><td><strong>KDS</strong></td><td>Kitchen — food tickets</td><td><code>/admin/kds.php</code></td><td>Read-only; kitchen-routed items only</td></tr>
        <tr><td><strong>BDS</strong></td><td>Bar — drinks tickets</td><td><code>/admin/bds.php</code></td><td>Pours decrement bottle counts in real time</td></tr>
        <tr><td><strong>CDS</strong></td><td>Coffee station</td><td><code>/admin/cds.php</code></td><td>Milk, beans, syrups auto-tracked via recipe</td></tr>
      </tbody>
    </table>

    <h3>Order flow</h3>
    <ol class="steps">
      <li>Cashier builds the order on the POS, selects payment method (cash / mobile money / card), submits.</li>
      <li>System validates tender amount; stock deducted inside a single atomic transaction with <code>SELECT … FOR UPDATE</code> batch locks.</li>
      <li>Kitchen and bar tickets appear on KDS / BDS / CDS within one second.</li>
      <li>Chef bumps the ticket when the dish is ready; the KDS event is logged with prep time.</li>
      <li>Cashier marks order served; accounting payment row is written at the same moment.</li>
    </ol>

    <h3>Voids (paid orders)</h3>
    <p>Managers and admins only. A void reason ≥ 8 characters is required. Stock is restored to the original FIFO batches, the KDS / BDS / CDS tickets are cleared, and the accounting payment row is cancelled — all in one transaction.</p>

    <h3>Daily shift report</h3>
    <p>Each station has a <strong>Today's Report</strong> button. It shows total tickets, total revenue, top 5 sellers, average prep time, and any unbalanced orders. Use it to close the shift.</p>
  </div>
</section>

<!-- ═══════════════════════════════════════════════
     ROOM SERVICE
══════════════════════════════════════════════════ -->
<section class="slide" id="roomservice">
  <div class="deck">
    <div class="eyebrow">Chapter 7 &middot; In-room dining</div>
    <h2>Room service that pays for itself</h2>
    <p class="lead">Every breakfast tray, every late-night snack, every bottle of wine — billed cleanly to the right room at the right moment.</p>

    <ol class="steps">
      <li>Staff opens <code>Admin → Stock Orders</code>, selects order type <strong>Room Service</strong>, picks the checked-in room.</li>
      <li>Items are routed to the correct station (kitchen / bar / coffee) automatically via KDS/BDS/CDS.</li>
      <li>The guest folio is charged the same instant — VAT and tourism levy applied per booking settings.</li>
      <li>Ingredients are deducted from stock using the same FIFO recipe logic as POS orders.</li>
      <li>Runner marks the order delivered. The KPI timer stops.</li>
      <li>At checkout, the room-service line items appear on the consolidated invoice — no manual entry.</li>
    </ol>

    <div class="infobox">
      <strong>Void a room-service order</strong>
      Go to the order and click <strong>Void</strong>. This simultaneously: cancels the folio charge, restores stock to the original batches, clears the kitchen/bar ticket, and cancels the accounting payment row. One click, zero loose ends.
    </div>
  </div>
</section>

<!-- ═══════════════════════════════════════════════
     HOUSEKEEPING
══════════════════════════════════════════════════ -->
<section class="slide" id="housekeeping">
  <div class="deck">
    <div class="eyebrow">Chapter 8 &middot; Rooms</div>
    <h2>Housekeeping &amp; maintenance</h2>
    <p class="lead">Live room status — every cleaner knows exactly which rooms need attention and which are ready for a new guest.</p>

    <h3>Housekeeping assignment statuses</h3>
    <p>Housekeeping work is tracked per room as <strong>assignments</strong>, not a single room-wide status flag. Each assignment moves through:</p>
    <table>
      <thead><tr><th>Status</th><th>Meaning</th><th>Booking impact</th></tr></thead>
      <tbody>
        <tr><td><strong>Pending</strong></td><td>Task created, not yet started</td><td>Room may still be occupied or vacant</td></tr>
        <tr><td><strong>In Progress</strong></td><td>Housekeeper tapped Start — currently cleaning</td><td>Room may still be occupied or vacant</td></tr>
        <tr><td><strong>Completed</strong></td><td>Housekeeper marked the task done</td><td>Bookable, pending verification</td></tr>
        <tr><td><strong>Verified</strong></td><td>Supervisor signed off — locked, cannot be edited further</td><td>Bookable</td></tr>
        <tr><td><strong>Blocked</strong></td><td>Task can't proceed (e.g. maintenance issue)</td><td>Serious cases move to Room Maintenance, which removes the room from bookable inventory</td></tr>
      </tbody>
    </table>

    <h3>Maintenance scheduling</h3>
    <p>Admin → Room Maintenance lets you schedule preventive tasks (AC service, plumbing checks, linen replacement) for individual rooms. Scheduled maintenance can block a room during the window.</p>

    <h3>Room dashboard</h3>
    <p>The visual floor plan at <code>Admin → Room Dashboard</code> shows every room's live assignment status in a colour-coded grid — amber (pending), blue (in progress), green (completed), purple (verified), red (blocked). Click any room to update status or view the full audit log.</p>

    <h3>Individual rooms vs room types</h3>
    <p>The system has two layers: <em>room types</em> (e.g., "Deluxe Sea View") and <em>individual rooms</em> (e.g., room 204). Both can have their own photos, amenities, and pricing. An individual room can be blocked without affecting its room type's overall availability.</p>
  </div>
</section>

<!-- ═══════════════════════════════════════════════
     CONFERENCE & EVENTS
══════════════════════════════════════════════════ -->
<section class="slide" id="conference">
  <div class="deck">
    <div class="eyebrow">Chapter 9 &middot; Venue hire</div>
    <h2>Conference &amp; events</h2>
    <p class="lead">Your hotel is more than bedrooms — the conference hall and event calendar drive additional revenue streams.</p>

    <h3>Conference management</h3>
    <ul class="checklist">
      <li><strong>Admin → Conference Management</strong> — add, edit, or deactivate conference rooms with photos, capacity, and pricing.</li>
      <li>Conference rooms appear on the public <code>/conference.php</code> page with an enquiry form.</li>
      <li>Enquiries land in the admin inbox for manual follow-up and booking.</li>
      <li>Each conference room can have its own gallery of images uploaded via the admin.</li>
    </ul>

    <h3>Events management</h3>
    <ul class="checklist">
      <li><strong>Admin → Events Management</strong> — create public events (dinners, parties, live music) with date, time, capacity, ticket price, and a hero image.</li>
      <li>Events appear on <code>/events.php</code> — the public calendar page.</li>
      <li>Past events are automatically hidden; upcoming events are sorted by date.</li>
      <li>The gallery for each event is managed separately from the main hotel gallery.</li>
    </ul>

    <div class="infobox">
      <strong>Image uploads (conference &amp; events)</strong>
      All image uploads are validated for file type (JPG, PNG, WebP, GIF), MIME content (via PHP <code>finfo</code>), image integrity (<code>getimagesize</code>), and maximum size (8 MB). Malformed or non-image files are rejected before touching the server.
    </div>
  </div>
</section>

<!-- ═══════════════════════════════════════════════
     MENU / MEDIA / CONTENT
══════════════════════════════════════════════════ -->
<section class="slide" id="media">
  <div class="deck">
    <div class="eyebrow">Chapter 10 &middot; Content</div>
    <h2>Menu, media &amp; content management</h2>
    <p class="lead">Change any text, photo, or video on your website — directly from the admin panel, no code required.</p>

    <h3>Menu management</h3>
    <ul class="checklist">
      <li><strong>Admin → Menu Management</strong> — add, edit, price, and toggle availability of food and drink items.</li>
      <li>Items are split into categories (starters, mains, desserts, cocktails, wines, etc.).</li>
      <li>Marking an item <em>unavailable</em> hides it from the POS, KDS, BDS, and website menu instantly.</li>
      <li>A printable PDF menu is generated automatically at <code>/menu-pdf.php</code> — always up to date.</li>
      <li>Each menu item can be linked to a stock recipe so ingredients deduct on sale.</li>
    </ul>

    <h3>Gallery management</h3>
    <ul class="checklist">
      <li><strong>Admin → Gallery Management</strong> — upload, reorder, and delete photos for rooms, common areas, dining, and the hotel exterior.</li>
      <li>Photos display on the public <code>/rooms-gallery.php</code> and individual room pages.</li>
      <li>Supported formats: JPEG, PNG, WebP, GIF (≤ 8 MB). Content is MIME-verified.</li>
    </ul>

    <h3>Page &amp; section content</h3>
    <ul class="checklist">
      <li><strong>Admin → Page Management</strong> — edit the text content of any public page (home, about, contact, gym, restaurant descriptions) without touching PHP files.</li>
      <li><strong>Admin → Section Headers</strong> — change the headings and intro paragraphs of each homepage section.</li>
      <li>Changes publish instantly — no cache flush needed for text content.</li>
    </ul>

    <h3>Video management</h3>
    <ul class="checklist">
      <li><strong>Admin → Media Management → Videos</strong> — upload background or hero videos used on the homepage.</li>
      <li>Videos are served from the server directly; fallback image is shown on mobile.</li>
    </ul>

    <h3>Room photos</h3>
    <ul class="checklist">
      <li>Individual room photos are managed under <strong>Admin → Room Management → Photos</strong>.</li>
      <li>Photos are ordered by position; the first photo is used as the listing thumbnail.</li>
    </ul>
  </div>
</section>

<!-- ═══════════════════════════════════════════════
     STOCK
══════════════════════════════════════════════════ -->
<section class="slide" id="stock">
  <div class="deck">
    <div class="eyebrow">Chapter 11 &middot; Inventory</div>
    <h2>Stock &amp; inventory</h2>
    <p class="lead">Ingredient by ingredient — from the delivery dock to the guest's glass.</p>

    <div class="cards">
      <div class="card">
        <div class="icon">1</div>
        <h3>Receive stock</h3>
        <p><strong>Admin → Stock Receipt</strong>. Enter the supplier, item, quantity, unit cost, and optional expiry date. Each delivery becomes a numbered <em>batch</em>.</p>
      </div>
      <div class="card">
        <div class="icon">2</div>
        <h3>Recipe linking</h3>
        <p><strong>Admin → Recipes</strong>. Attach ingredients to each menu item with quantities per portion and a yield percentage. The system uses these to deduct stock on every sale.</p>
      </div>
      <div class="card">
        <div class="icon">3</div>
        <h3>FIFO deduction</h3>
        <p>Oldest batches are consumed first. Concurrent orders use row-level <code>FOR UPDATE</code> locks — no two orders can deplete the same batch simultaneously.</p>
      </div>
      <div class="card">
        <div class="icon">4</div>
        <h3>Variance count</h3>
        <p><strong>Admin → Stock Variance</strong>. Walk the bar with a tablet, enter physical counts. The system flags any line with &gt; 5% drift in red.</p>
      </div>
      <div class="card">
        <div class="icon">5</div>
        <h3>Negative stock alert</h3>
        <p>If all batches are depleted and an order still comes in, the ingredient goes negative — the order is allowed (kitchen reality) but flagged on the dashboard for immediate reorder.</p>
      </div>
      <div class="card">
        <div class="icon">6</div>
        <h3>Void restore</h3>
        <p>Every void replays the original batch deductions in reverse — exact quantities go back to the exact batches they came from. No guesswork.</p>
      </div>
    </div>

    <h3 style="margin-top:2rem;">Stock orders workflow</h3>
    <table>
      <thead><tr><th>Action</th><th>Admin page</th><th>Who can do it</th></tr></thead>
      <tbody>
        <tr><td>Receive delivery</td><td>Stock Receipt</td><td>Manager, Admin</td></tr>
        <tr><td>Place reorder</td><td>Stock Orders</td><td>Manager, Admin</td></tr>
        <tr><td>View low-stock alerts</td><td>Stock Dashboard</td><td>All staff with stock permission</td></tr>
        <tr><td>Adjust (wastage, expiry, recall)</td><td>Stock Adjustments</td><td>Manager, Admin</td></tr>
        <tr><td>End-of-day variance</td><td>Stock Variance</td><td>Manager, Admin</td></tr>
      </tbody>
    </table>
  </div>
</section>

<!-- ═══════════════════════════════════════════════
     ACCOUNTING
══════════════════════════════════════════════════ -->
<section class="slide" id="accounting">
  <div class="deck">
    <div class="eyebrow">Chapter 12 &middot; Money</div>
    <h2>Finance &amp; accounting</h2>
    <p class="lead">Gross revenue, net revenue, voids, refunds, VAT, tourism levy — all in one dashboard, all verifiable to the cent.</p>

    <h3>Accounting dashboard</h3>
    <p><strong>Admin → Accounting Dashboard</strong> shows:</p>
    <ul class="checklist">
      <li><strong>Gross revenue</strong> — all completed payments (accommodation + F&amp;B + extras)</li>
      <li><strong>Refunds issued</strong> — all recorded refund rows</li>
      <li><strong>Net revenue</strong> — gross minus refunds</li>
      <li><strong>POS voids</strong> — count and value of voided restaurant orders</li>
      <li><strong>VAT collected</strong> — <?= $vatStatus ?></li>
      <li><strong>Tourism levy</strong> — <?= $levyStatus ?></li>
      <li><strong>Cash position today</strong> — cash + mobile money payments for today</li>
      <li><strong>Pending payments</strong> — bookings that have a balance due</li>
    </ul>

    <h3>Payment methods tracked</h3>
    <table>
      <thead><tr><th>Method</th><th>Required fields</th></tr></thead>
      <tbody>
        <tr><td>Cash</td><td>Amount tendered, change due</td></tr>
        <tr><td>Mobile Money</td><td>Provider (Airtel/TNM), transaction reference</td></tr>
        <tr><td>Credit / debit card</td><td>Last 4 digits, authorisation code</td></tr>
        <tr><td>Bank transfer</td><td>Reference number</td></tr>
        <tr><td>Cheque</td><td>Cheque number, bank</td></tr>
      </tbody>
    </table>

    <h3>Month-end for your accountant</h3>
    <ol class="steps">
      <li>Open <strong>Reports → Accounting</strong>, set the date range to the full month.</li>
      <li>Download the CSV export — it includes per-booking totals, VAT breakdown, and tourism levy column.</li>
      <li>The <strong>Voids &amp; Refunds</strong> tab shows every reversal with reason and staff member for audit.</li>
      <li>Cross-check with Admin → Invoices — every issued invoice has a sequential number and a PDF archive.</li>
    </ol>

    <div class="infobox success">
      <strong>VAT &amp; tourism levy configuration</strong>
      Both are set in <code>Admin → Site Settings → Taxes</code>. Current status: VAT — <em><?= $vatStatus ?></em> &middot; Tourism levy — <em><?= $levyStatus ?></em>. Changes apply to all new bookings immediately. Existing bookings retain the rate at which they were created.
    </div>
  </div>
</section>

<!-- ═══════════════════════════════════════════════
     REPORTS
══════════════════════════════════════════════════ -->
<section class="slide" id="reports">
  <div class="deck">
    <div class="eyebrow">Chapter 13 &middot; Intelligence</div>
    <h2>Reports &amp; analytics</h2>
    <p class="lead">A single dashboard answers the only three questions that matter: who's arriving, what did we earn, what do we owe?</p>

    <table>
      <thead><tr><th>Report</th><th>Admin path</th><th>Use it for</th></tr></thead>
      <tbody>
        <tr><td>Occupancy &amp; RevPAR</td><td>Reports → Occupancy</td><td>Pricing decisions, seasonal patterns</td></tr>
        <tr><td>ADR (Average Daily Rate)</td><td>Reports → Revenue</td><td>Rate strategy benchmarking</td></tr>
        <tr><td>Daily revenue</td><td>Dashboard</td><td>Quick cash control every morning</td></tr>
        <tr><td>Outstanding folios</td><td>Bookings → Unpaid filter</td><td>Front-desk follow-up</td></tr>
        <tr><td>VAT &amp; tourism levy</td><td>Accounting Dashboard</td><td>Monthly tax returns</td></tr>
        <tr><td>Stock variance</td><td>Stock → Variance</td><td>Catch shrinkage, theft, waste</td></tr>
        <tr><td>Top-selling items</td><td>KDS / BDS / CDS daily report</td><td>Menu engineering</td></tr>
        <tr><td>Voids &amp; refunds</td><td>Reports → Voids</td><td>Identify misuse, train staff</td></tr>
        <tr><td>Staff audit log</td><td>Reports → Audit</td><td>Security &amp; compliance review</td></tr>
        <tr><td>Visitor analytics</td><td>Admin → Visitor Analytics</td><td>Website traffic &amp; booking conversion</td></tr>
        <tr><td>Gym enquiries</td><td>Admin → Gym Enquiries</td><td>Membership lead pipeline</td></tr>
        <tr><td>Reviews</td><td>Admin → Reviews</td><td>Reputation management</td></tr>
      </tbody>
    </table>

    <h3>CSV export</h3>
    <p>Every report has a <strong>Download CSV</strong> button. The file is generated server-side with proper UTF-8 encoding — open it directly in Excel, Google Sheets, or your accounting software.</p>
  </div>
</section>

<!-- ═══════════════════════════════════════════════
     NOTIFICATIONS
══════════════════════════════════════════════════ -->
<section class="slide" id="notifications">
  <div class="deck">
    <div class="eyebrow">Chapter 14 &middot; Communications</div>
    <h2>Email &amp; WhatsApp notifications</h2>
    <p class="lead">Guests are kept informed at every step — automatically, without lifting a finger.</p>

    <h3>Automated email triggers</h3>
    <table>
      <thead><tr><th>Trigger</th><th>Email sent</th></tr></thead>
      <tbody>
        <tr><td>Booking created (pending)</td><td>Guest: booking received confirmation</td></tr>
        <tr><td>Booking confirmed</td><td>Guest: full confirmation with room details</td></tr>
        <tr><td>Tentative booking created</td><td>Guest: hold confirmation with expiry time</td></tr>
        <tr><td>Tentative booking expires</td><td>Guest: expiry notice; admin: alert to review and manually cancel (the room is not auto-released)</td></tr>
        <tr><td>Check-in</td><td>Guest: welcome email</td></tr>
        <tr><td>Check-out / invoice</td><td>Guest: invoice PDF as email attachment</td></tr>
        <tr><td>Booking cancelled</td><td>Guest: cancellation confirmation + admin alert</td></tr>
        <tr><td>Pre-arrival reminder <em>(opt-in, off by default)</em></td><td>Guest: reminder sent a configurable number of days before check-in</td></tr>
        <tr><td>Post-stay review request <em>(opt-in, off by default)</em></td><td>Guest: review request sent a configurable number of days after check-out</td></tr>
        <tr><td>New review submitted</td><td>Admin: review notification</td></tr>
        <tr><td>Contact form</td><td>Admin: contact form submission</td></tr>
      </tbody>
    </table>
    <p>The pre-arrival reminder and post-stay review request are toggled independently under <strong>Admin → Booking Settings → Guest communication emails</strong>, where you also set the days-before / days-after timing.</p>

    <h3>SMTP configuration</h3>
    <p>Email is sent via SMTP (PHPMailer). Configure at <strong>Admin → Site Settings → Email</strong>: host, port, username, password, sender name, sender email. All values are stored in the <code>site_settings</code> table — no config files to edit.</p>

    <h3>WhatsApp notifications</h3>
    <p>WhatsApp message sending is configured at <strong>Admin → WhatsApp Settings</strong>. Current number: <em><?= $whatsapp ?: 'Not configured' ?></em>. WhatsApp notifications for new bookings and check-ins can be enabled or disabled per event type.</p>

    <div class="infobox warning">
      <strong>Billing note</strong>
      WhatsApp Business API messages and some email relay services (SendGrid, Mailgun, SES) are billed per message. Review your provider's pricing before enabling high-volume notifications.
    </div>
  </div>
</section>

<!-- ═══════════════════════════════════════════════
     USERS
══════════════════════════════════════════════════ -->
<section class="slide" id="users">
  <div class="deck">
    <div class="eyebrow">Chapter 15 &middot; Access control</div>
    <h2>User management &amp; RBAC</h2>
    <p class="lead">Eleven distinct roles — each seeing only what they need, each unable to reach what they shouldn't.</p>

    <div class="role-grid">
      <div class="role-item"><div class="role-dot"></div><div><strong>admin</strong><small>Full access to everything — owner / developer only</small></div></div>
      <div class="role-item"><div class="role-dot"></div><div><strong>manager</strong><small>All operations; cannot edit other managers or admins</small></div></div>
      <div class="role-item"><div class="role-dot"></div><div><strong>receptionist</strong><small>Bookings, check-in/out, folio, payments</small></div></div>
      <div class="role-item"><div class="role-dot"></div><div><strong>accountant</strong><small>Accounting dashboard, reports, invoices — read-only for operations</small></div></div>
      <div class="role-item"><div class="role-dot"></div><div><strong>housekeeping</strong><small>Housekeeping assignment board only</small></div></div>
      <div class="role-item"><div class="role-dot"></div><div><strong>room_service</strong><small>Place room-service orders; no folio or payment access</small></div></div>
      <div class="role-item"><div class="role-dot"></div><div><strong>restaurant_staff</strong><small>POS till; stock deductions via recipes</small></div></div>
      <div class="role-item"><div class="role-dot"></div><div><strong>chef</strong><small>KDS kitchen display only</small></div></div>
      <div class="role-item"><div class="role-dot"></div><div><strong>bar_staff</strong><small>BDS bar display only</small></div></div>
      <div class="role-item"><div class="role-dot"></div><div><strong>coffee_staff</strong><small>CDS coffee display only</small></div></div>
      <div class="role-item"><div class="role-dot"></div><div><strong>viewer</strong><small>Read-only dashboard — suitable for investors, auditors</small></div></div>
    </div>

    <h3 style="margin-top:2rem;">Per-user permission overrides</h3>
    <p>Beyond roles, every permission can be granted or denied at the individual user level. Open <strong>Admin → User Management → Edit User → Permissions</strong>. Use <em>Reset to Role Defaults</em> to undo custom overrides. Permissions are stored in the <code>user_permissions</code> table.</p>

    <h3>Creating &amp; managing users</h3>
    <ol class="steps">
      <li>Admin → User Management → Add User. Set name, email, role, and a temporary password.</li>
      <li>The new user logs in and is prompted to change their password on first login.</li>
      <li>To deactivate a leaver: Edit User → toggle <em>Active</em> to off. The account is preserved for audit trail.</li>
      <li>Never delete users — deactivate them. Their actions remain linked in the audit log.</li>
    </ol>

    <div class="infobox warning">
      <strong>Admin role is sacred</strong>
      Only the admin account can edit other admins or change the admin role. Do not share the admin password — create manager accounts for day-to-day operations.
    </div>
  </div>
</section>

<!-- ═══════════════════════════════════════════════
     SECURITY
══════════════════════════════════════════════════ -->
<section class="slide" id="security">
  <div class="deck">
    <div class="eyebrow">Chapter 16 &middot; Protection</div>
    <h2>Security &amp; audit log</h2>
    <p class="lead">The system protects your data at every layer — passwords, sessions, uploads, SQL, and human actions.</p>

    <div class="cards">
      <div class="card">
        <h3>Authentication</h3>
        <ul class="checklist" style="margin-top:0.5rem;">
          <li>Passwords stored as <strong>bcrypt</strong> hashes — never in plain text</li>
          <li>5 failed logins → 15-minute account lockout</li>
          <li>10 failed logins from one IP → 1-hour IP block</li>
          <li>Reset tokens are one-use, hashed, expire after 1 hour</li>
        </ul>
      </div>
      <div class="card">
        <h3>Session &amp; CSRF</h3>
        <ul class="checklist" style="margin-top:0.5rem;">
          <li>Sessions regenerated on login &amp; privilege change</li>
          <li>CSRF token on every state-changing form and AJAX endpoint</li>
          <li>Idle session timeout configurable in site settings</li>
        </ul>
      </div>
      <div class="card">
        <h3>File uploads</h3>
        <ul class="checklist" style="margin-top:0.5rem;">
          <li>Extension whitelist enforced</li>
          <li>MIME content verified with PHP <code>finfo</code></li>
          <li><code>getimagesize()</code> confirms image integrity</li>
          <li>8 MB max size cap on all uploads</li>
        </ul>
      </div>
      <div class="card">
        <h3>Database</h3>
        <ul class="checklist" style="margin-top:0.5rem;">
          <li>All queries use PDO prepared statements — no SQL injection possible</li>
          <li>Atomic transactions with rollback on every multi-step operation</li>
          <li><code>FOR UPDATE</code> row locks prevent race conditions on bookings and stock</li>
        </ul>
      </div>
    </div>

    <h3 style="margin-top:2rem;">What is audited</h3>
    <ul class="checklist">
      <li>Every admin login and logout</li>
      <li>Password changes and resets</li>
      <li>Role and permission changes</li>
      <li>Every booking status transition (with old → new value and actor)</li>
      <li>Every POS order placed, voided, or cancelled (with reason)</li>
      <li>Every stock adjustment (deduction, receipt, variance, void restore)</li>
      <li>Every folio charge and void</li>
      <li>Every payment and refund</li>
      <li>Housekeeping room status changes</li>
      <li>Maintenance scheduling changes</li>
    </ul>

    <h3>API keys</h3>
    <p>If you are using the REST API for channel-manager or OTA integration, keys are managed at <strong>Admin → API Keys</strong>. Each key has a name, role, expiry date, and last-used timestamp. Rotate keys quarterly or immediately if a key is compromised.</p>
  </div>
</section>

<!-- ═══════════════════════════════════════════════
     INFRASTRUCTURE
══════════════════════════════════════════════════ -->
<section class="slide" id="infra">
  <div class="deck">
    <div class="eyebrow">Chapter 17 &middot; Infrastructure</div>
    <h2>Database, cache &amp; scheduled tasks</h2>

    <h3>Cache management</h3>
    <p>The system uses a two-layer cache: in-memory (per-request PHP array) and file cache (<code>cache/pages/</code>). Most saves auto-invalidate the relevant cache key. If you see stale content, go to <strong>Admin → Cache Management</strong> and clear the relevant category — or clear all.</p>

    <div class="infobox">
      <strong>Pro tip</strong>
      80% of "the website doesn't show my changes" tickets are solved by clearing the page cache in <code>Admin → Cache Management</code>.
    </div>

    <h3>Database migrations</h3>
    <p>All schema changes are versioned in <code>admin/migrations/</code>. Run them in order via CLI: <code>php admin/migrations/NNN_name.php</code>. Each migration is idempotent — safe to run again if a deployment failed midway. This project ships with <strong><?= $stats['migrations'] ?> migration scripts</strong>.</p>

    <h3>Scheduled cron jobs</h3>
    <p>Add the following to the server's crontab:</p>
    <table>
      <thead><tr><th>Schedule</th><th>Command</th><th>Purpose</th></tr></thead>
      <tbody>
        <tr><td><code>*/15 * * * *</code></td><td><code>php scripts/expire_tentative_bookings.php</code></td><td>Flags overdue tentative holds for staff review (does not auto-cancel)</td></tr>
        <tr><td><code>0 7 * * *</code></td><td><code>php scripts/daily_reports.php</code></td><td>Morning email digest to manager</td></tr>
        <tr><td><code>0 2 * * *</code></td><td><code>php scripts/backup_database.php --quiet</code></td><td>Nightly gzipped database backup with rotation</td></tr>
      </tbody>
    </table>

    <h3>Log files</h3>
    <p>PHP errors and application errors are written to <code>logs/error.log</code>. The most recent entry is always at the bottom. Rotate logs monthly — they grow fast under high traffic.</p>

    <h3>Database backup &amp; restore</h3>
    <p>The system ships with a self-contained backup script that produces gzipped SQL dumps and rotates them automatically. <strong>Backups are written to <code>backups/YYYY/MM/db-YYYYMMDD-HHMMSS.sql.gz</code></strong> (excluded from git, protected by an auto-generated <code>.htaccess</code>).</p>
    <ul class="checklist">
      <li><code>php scripts/backup_database.php</code> — manual run; uses <code>mysqldump</code> if available, otherwise a pure-PHP fallback dumper.</li>
      <li>Verifies gzip integrity before finalising the file (atomic rename).</li>
      <li>Updates <code>last_backup_at</code>, <code>last_backup_size</code> in <code>site_settings</code> so <code>api/health.php</code> and the dashboard can show backup freshness.</li>
      <li>Rotation: keeps the last <strong>14 daily</strong>, <strong>8 weekly</strong>, and <strong>12 monthly</strong> backups; older copies are pruned automatically.</li>
      <li>Logs every run to <code>logs/backup.log</code>.</li>
    </ul>
    <p>To restore (CLI only — never callable over HTTP):</p>
    <table>
      <thead><tr><th>Command</th><th>Effect</th></tr></thead>
      <tbody>
        <tr><td><code>php scripts/restore_database.php --list</code></td><td>List all available backups, newest first.</td></tr>
        <tr><td><code>php scripts/restore_database.php --file=backups/2026/05/db-…sql.gz --confirm</code></td><td>Restore the chosen file. Refuses to run without <code>--confirm</code>.</td></tr>
      </tbody>
    </table>

    <h3>Offline resilience &amp; idempotency</h3>
    <p>Every booking and payment write carries a <code>client_uuid</code>. Duplicate submissions — caused by double-clicks, browser back-button resubmits, flaky 3G, or replays from the offline queue — are detected and short-circuited at the application layer, with a UNIQUE database index as the final guarantor. <strong>It is impossible to create two bookings or two payments from the same submission, regardless of how many times it is retried.</strong></p>
    <ul class="checklist">
      <li>POS, KDS, BDS, CDS, Stock Orders, admin booking, and admin payment forms all queue locally in IndexedDB when the network drops, then auto-replay when connectivity returns.</li>
      <li>The connectivity banner is rendered universally via <code>admin/includes/admin-footer.php</code>; every admin page benefits without per-page wiring.</li>
      <li><code>GET /api/health.php</code> returns the DB ping result, the time of the last backup, the backup file size, and the last tentative-booking sweep time. Suitable for external uptime monitors and the admin dashboard tile.</li>
      <li><code>idempotency_keys</code> table caches API responses for 7 days so repeated calls with the same <code>client_uuid</code> return the original result.</li>
    </ul>

    <h3>Manual off-site copy</h3>
    <ul class="checklist">
      <li>Weekly copy of the latest <code>backups/</code> tree to off-site storage (S3, Dropbox, or USB drive at the front desk).</li>
      <li>Test a restore quarterly on a staging copy of the database — a backup you haven't tested isn't a backup.</li>
    </ul>
  </div>
</section>

<!-- ═══════════════════════════════════════════════
     ROUTINE
══════════════════════════════════════════════════ -->
<section class="slide" id="routine">
  <div class="deck">
    <div class="eyebrow">Chapter 18 &middot; Rhythm</div>
    <h2>Daily, weekly &amp; monthly</h2>
    <p class="lead">A simple rhythm to keep the house in balance — and the numbers you can trust.</p>

    <h3>Every morning</h3>
    <ul class="checklist">
      <li>Front desk opens Dashboard — today's arrivals, departures, and current occupancy.</li>
      <li>Housekeeping reviews the assignment board — Pending / In Progress / Completed / Verified / Blocked.</li>
      <li>Check for new tentative bookings approaching expiry (<?= $tentHours ?>-hour window).</li>
      <li>Verify yesterday's outstanding folios — chase any unpaid balances.</li>
    </ul>

    <h3>End of every shift</h3>
    <ul class="checklist">
      <li>POS cashier runs the <strong>Shift Report</strong> from the POS screen — total orders, total revenue, payment method breakdown.</li>
      <li>Bar staff runs the BDS daily report — pours vs stock snapshot.</li>
      <li>Kitchen: bump all remaining tickets; run the KDS daily report.</li>
      <li>Walk the bar / storage for stock variance — enter physical counts in Admin → Stock Variance.</li>
      <li>Reconcile the cash drawer against the POS cash report.</li>
    </ul>

    <h3>Every week</h3>
    <ul class="checklist">
      <li>Manager reviews outstanding folios — email or call guests with balances &gt; 7 days.</li>
      <li>Check low-stock alerts in Admin → Stock Dashboard — place reorders.</li>
      <li>Approve or respond to guest reviews in Admin → Reviews.</li>
      <li>Review tentative bookings approaching expiry — convert or release.</li>
      <li>Scan the audit log for any unusual voids or permission changes.</li>
    </ul>

    <h3>Every month</h3>
    <ul class="checklist">
      <li>Run Accounting Dashboard → export CSV for VAT and tourism levy figures.</li>
      <li>Print the Stock Variance summary — file it with the month-end accounts.</li>
      <li>Admin → User Management — deactivate any staff who have left.</li>
      <li>Rotate the SMTP email password; update it in Admin → Site Settings → Email.</li>
      <li>Rotate API keys (Admin → API Keys) if third-party integrations are in use.</li>
      <li>Download a database backup to off-site storage.</li>
      <li>Review the error log — identify any recurring issues before they become incidents.</li>
    </ul>
  </div>
</section>

<!-- ═══════════════════════════════════════════════
     STAFF GUIDES
══════════════════════════════════════════════════ -->
<section class="slide" id="guides">
  <div class="deck">
    <div class="eyebrow">Chapter 19 &middot; Team training</div>
    <h2>Staff guides</h2>
    <p class="lead">One guide per station. Pin them to a tablet or print and laminate — each is under 5 minutes to read.</p>

    <div class="cards" style="grid-template-columns: repeat(auto-fit, minmax(340px, 1fr));">

      <a class="guide-card" href="01-pos-till.html">
        <div class="num">1</div>
        <div>
          <div class="title">POS Till — Cashier Guide</div>
          <div class="sub">Take orders, select payment method, void, close shift. For restaurant staff.</div>
          <span class="pill gold">5 min read</span>
        </div>
      </a>

      <a class="guide-card" href="02-kds-kitchen.html">
        <div class="num">2</div>
        <div>
          <div class="title">KDS — Kitchen Display</div>
          <div class="sub">Start cooking, tick items, bump tickets, recall &amp; rush. For chefs.</div>
          <span class="pill gold">4 min read</span>
        </div>
      </a>

      <a class="guide-card" href="03-bds-bar.html">
        <div class="num">3</div>
        <div>
          <div class="title">BDS — Bar Display</div>
          <div class="sub">Pour orders, mark served, stock auto-deducts via recipe. For bar staff.</div>
          <span class="pill gold">4 min read</span>
        </div>
      </a>

      <a class="guide-card" href="04-cds-coffee.html">
        <div class="num">4</div>
        <div>
          <div class="title">CDS — Coffee Station</div>
          <div class="sub">Espresso prep, milk tracking, end-of-shift count. For coffee staff.</div>
          <span class="pill gold">4 min read</span>
        </div>
      </a>

      <a class="guide-card" href="05-room-service.html">
        <div class="num">5</div>
        <div>
          <div class="title">Room Service</div>
          <div class="sub">Place folio-linked orders, route to stations, mark delivered. For room-service staff.</div>
          <span class="pill gold">5 min read</span>
        </div>
      </a>

      <a class="guide-card" href="06-housekeeping.html">
        <div class="num">6</div>
        <div>
          <div class="title">Housekeeping</div>
          <div class="sub">Assignment board, Pending/In Progress/Completed/Verified/Blocked workflow, out-of-service rooms. For housekeeping.</div>
          <span class="pill gold">5 min read</span>
        </div>
      </a>

      <a class="guide-card" href="07-reception-bookings.html">
        <div class="num">7</div>
        <div>
          <div class="title">Reception &amp; Bookings</div>
          <div class="sub">Check-in, check-out, folio management, payments, invoices. For reception.</div>
          <span class="pill gold">8 min read</span>
        </div>
      </a>

      <a class="guide-card" href="08-stock-orders.html">
        <div class="num">8</div>
        <div>
          <div class="title">Stock &amp; Orders</div>
          <div class="sub">Receive deliveries, manage recipes, variance counts, reorder alerts. For managers.</div>
          <span class="pill gold">7 min read</span>
        </div>
      </a>

      <a class="guide-card" href="99-admin-dashboard-full-guide.html">
        <div class="num">✦</div>
        <div>
          <div class="title">Admin Dashboard — Full Guide</div>
          <div class="sub">The complete manager bible. Every admin page, every setting, every report explained.</div>
          <span class="pill green">15 min read</span>
        </div>
      </a>

    </div>
  </div>
</section>

<!-- ═══════════════════════════════════════════════
     SUPPORT
══════════════════════════════════════════════════ -->
<section class="slide" id="support">
  <div class="deck">
    <div class="eyebrow">Chapter 20 &middot; Help</div>
    <h2>Support &amp; credentials</h2>

    <h3>When something looks wrong — in order</h3>
    <ol class="steps">
      <li>Go to <strong>Admin → Cache Management</strong> and clear the page cache. Most visual glitches disappear.</li>
      <li>Check <code>logs/error.log</code> on the server — the most recent PHP error is at the bottom.</li>
      <li>Run <code>php scripts/inspect_schema.php</code> from the server CLI — full database health check.</li>
      <li>Run <code>php scripts/audit_migrations.php</code> — verify all migrations have been applied.</li>
      <li>Contact the developer with: the timestamp, the URL, and the last 50 lines of <code>logs/error.log</code>.</li>
    </ol>

    <h3>Resetting admin credentials</h3>
    <p>The owner password can be reset by the developer via a single CLI command on the server. <strong>Never share credentials by email or messaging app</strong> — always use the built-in password reset flow (<code>/admin/forgot-password.php</code>) or ask the developer for a CLI reset.</p>

    <h3>Key admin URLs</h3>
    <table>
      <thead><tr><th>Purpose</th><th>URL</th></tr></thead>
      <tbody>
        <tr><td>Admin login</td><td><code>/admin/login.php</code></td></tr>
        <tr><td>Dashboard</td><td><code>/admin/dashboard.php</code></td></tr>
        <tr><td>Site settings</td><td><code>/admin/booking-settings.php</code></td></tr>
        <tr><td>Cache management</td><td><code>/admin/cache-management.php</code></td></tr>
        <tr><td>User management</td><td><code>/admin/user-management.php</code></td></tr>
        <tr><td>API keys</td><td><code>/admin/api-keys.php</code></td></tr>
        <tr><td>WhatsApp settings</td><td><code>/admin/whatsapp-settings.php</code></td></tr>
        <tr><td>Migrations</td><td><code>php admin/migrations/NNN.php</code> (CLI only)</td></tr>
      </tbody>
    </table>

    <h3>Useful CLI scripts</h3>
    <table>
      <thead><tr><th>Script</th><th>Does what</th></tr></thead>
      <tbody>
        <tr><td><code>scripts/inspect_schema.php</code></td><td>Checks all expected tables exist</td></tr>
        <tr><td><code>scripts/audit_migrations.php</code></td><td>Lists applied / missing migrations</td></tr>
        <tr><td><code>scripts/daily_reports.php</code></td><td>Sends the morning digest email (run manually to test)</td></tr>
        <tr><td><code>scripts/expire_tentative_bookings.php</code></td><td>Sweeps and flags overdue tentative holds for staff review — does not cancel them automatically</td></tr>
        <tr><td><code>scripts/patch_amount_due_drift.php</code></td><td>Recalculates booking financial totals in bulk</td></tr>
      </tbody>
    </table>
  </div>
</section>

<!-- ═══════════════════════════════════════════════
     CLOSING
══════════════════════════════════════════════════ -->
<footer class="handover" id="close">
  <div class="deck">
    <div class="crest">— <?= implode(' ', array_map(fn($w) => strtoupper(mb_substr($w,0,1)), array_filter(explode(' ', getSetting('site_name','Hotel'))))) ?> —</div>
    <h2 style="color: var(--cream);">A house ready for its guests</h2>
    <div class="divider"></div>
    <p>Every door, every drink, every detail — now connected.</p>
    <p>The technology is here. The data is yours. You hold the keys.</p>
    <?php if ($hotelAddress || $hotelPhone || $hotelEmail): ?>
    <p style="margin-top:1.5rem; font-size:0.9rem; color:rgba(248,243,233,0.5);">
      <?= $hotelAddress ?>
      <?php if ($hotelPhone): ?> &middot; <?= $hotelPhone ?><?php endif; ?>
      <?php if ($hotelEmail): ?> &middot; <?= $hotelEmail ?><?php endif; ?>
    </p>
    <?php endif; ?>
    <p class="signature">With care, for <?= $siteName ?>.</p>
    <div class="links">
      <a href="index.html">All Staff Guides</a>
      <a href="99-admin-dashboard-full-guide.html">Admin Bible</a>
      <a href="07-reception-bookings.html">Reception Guide</a>
      <a href="08-stock-orders.html">Stock Guide</a>
    </div>
  </div>
</footer>

<script>
  // Highlight active section in TOC
  const links = document.querySelectorAll('.toc-panel a');
  const sections = Array.from(document.querySelectorAll('section[id], footer[id]'));

  function updateToc() {
    const y = window.scrollY + 120;
    let active = sections[0]?.id;
    for (const s of sections) { if (s.offsetTop <= y) active = s.id; }
    links.forEach(a => {
      const href = a.getAttribute('href');
      a.style.color = href === '#' + active ? 'var(--gold)' : '';
      a.style.fontWeight = href === '#' + active ? '600' : '';
    });
  }

  window.addEventListener('scroll', updateToc, { passive: true });
  updateToc();

  // Close TOC when a link is clicked
  document.querySelectorAll('.toc-panel a').forEach(a => {
    a.addEventListener('click', () => document.getElementById('toc').classList.remove('open'));
  });

  // Close TOC when clicking outside
  document.addEventListener('click', e => {
    const panel = document.getElementById('toc');
    const toggle = document.querySelector('.toc-toggle');
    if (!panel.contains(e.target) && !toggle.contains(e.target)) {
      panel.classList.remove('open');
    }
  });
</script>
</body>
</html>
