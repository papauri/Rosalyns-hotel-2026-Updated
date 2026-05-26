<?php

/**
 * docs/guides/mra-integration-guide.php
 * MRA EIS Integration — Concrete IT Rollout Guide
 *
 * How the hotel PMS connects to Malawi Revenue Authority's
 * Electronic Invoicing System (EIS).
 *
 * Access: browser (http://yoursite/docs/guides/mra-integration-guide.php)
 *         or CLI  (php docs/guides/mra-integration-guide.php)
 */

declare(strict_types=1);

$dbConfig = __DIR__ . '/../../config/database.php';
$dbAvailable = false;
if (file_exists($dbConfig)) {
    require_once $dbConfig;
    /** @var \PDO|null $pdo */
    $dbAvailable = isset($pdo);
}

function mra_g(string $key, string $default = ''): string
{
    if (!function_exists('getSetting')) {
        return htmlspecialchars($default, ENT_QUOTES, 'UTF-8');
    }
    return htmlspecialchars((string) getSetting($key, $default), ENT_QUOTES, 'UTF-8');
}

$siteName    = mra_g('site_name', 'Hotel');
$vatEnabled  = function_exists('getSetting') ? (string) getSetting('vat_enabled', '0') : '0';
$vatRate     = mra_g('vat_rate', '16.5');
$vatNumber   = mra_g('vat_number', '');
$invPrefix   = mra_g('invoice_prefix', 'INV');
$year        = date('Y');
$generated   = date('d M Y, H:i');

$totalPayments = 0;
$totalBookings = 0;
if ($dbAvailable && isset($pdo)) {
    try {
        $totalPayments = (int) $pdo->query("SELECT COUNT(*) FROM payments WHERE deleted_at IS NULL")->fetchColumn();
    } catch (Throwable $e) {
        // non-fatal
    }
    try {
        $totalBookings = (int) $pdo->query("SELECT COUNT(*) FROM bookings")->fetchColumn();
    } catch (Throwable $e) {
        // non-fatal
    }
}
?>
<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>MRA EIS Integration Guide — <?= $siteName ?></title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Cormorant+Garamond:wght@400;500;600;700&family=Jost:wght@300;400;500;600&display=swap" rel="stylesheet">
    <style>
        :root {
            --gold: #d4a843;
            --gold-s: #e8c878;
            --dark: #1a1a1a;
            --brown: #8b7355;
            --cream: #f8f3e9;
            --ink: #2a2a2a;
            --muted: #6c6c6c;
            --line: rgba(212, 168, 67, 0.25);
            --shadow: 0 18px 40px -18px rgba(26, 26, 26, 0.32);
            --green: #2d7a3a;
            --warn: #c97b00;
            --red: #b53232;
            --blue: #1d5fa8;
        }

        *,
        *::before,
        *::after {
            box-sizing: border-box;
            margin: 0;
            padding: 0;
        }

        html {
            scroll-behavior: smooth;
        }

        body {
            font-family: 'Jost', sans-serif;
            font-size: clamp(15px, 0.5vw + 13px, 17px);
            line-height: 1.72;
            color: var(--ink);
            background: var(--cream);
            padding-top: 56px;
        }

        h1,
        h2,
        h3,
        h4 {
            font-family: 'Cormorant Garamond', serif;
            font-weight: 500;
            line-height: 1.22;
            color: var(--dark);
        }

        h1 {
            font-size: clamp(2.4rem, 5vw, 4.2rem);
        }

        h2 {
            font-size: clamp(1.7rem, 3vw, 2.5rem);
            margin-bottom: 1.2rem;
        }

        h3 {
            font-size: clamp(1.1rem, 1.6vw, 1.45rem);
            color: var(--brown);
            margin-bottom: 0.6rem;
        }

        h4 {
            font-size: 1.02rem;
            margin-bottom: 0.35rem;
        }

        p {
            margin-bottom: 1rem;
            max-width: 72ch;
        }

        strong {
            color: var(--dark);
            font-weight: 600;
        }

        code {
            font-family: 'SF Mono', 'Consolas', 'Fira Code', monospace;
            font-size: 0.85em;
            background: rgba(212, 168, 67, 0.13);
            padding: 0.15em 0.45em;
            border-radius: 4px;
            color: var(--brown);
        }

        a {
            color: var(--gold);
            text-decoration: none;
            border-bottom: 1px solid var(--line);
            transition: 0.2s;
        }

        a:hover {
            color: var(--brown);
        }

        /* ── Layout ── */
        .deck {
            max-width: 1160px;
            margin: 0 auto;
            padding: 0 clamp(1rem, 4vw, 3rem);
        }

        section.slide {
            padding: clamp(3rem, 8vw, 6rem) 0 clamp(2.5rem, 5vw, 4.5rem);
            border-bottom: 1px solid var(--line);
        }

        .eyebrow {
            font-family: 'Jost', sans-serif;
            text-transform: uppercase;
            letter-spacing: 0.26em;
            font-size: 0.76rem;
            color: var(--gold);
            font-weight: 500;
            margin-bottom: 0.9rem;
        }

        .lead {
            font-family: 'Cormorant Garamond', serif;
            font-size: clamp(1.1rem, 1.6vw, 1.4rem);
            font-style: italic;
            color: var(--brown);
            max-width: 64ch;
            margin-bottom: 1.5rem;
        }

        .divider {
            width: 56px;
            height: 1px;
            background: var(--gold);
            margin: 1.4rem 0;
        }

        /* ── Hero ── */
        .hero {
            background: linear-gradient(135deg, var(--dark) 0%, #221e14 100%);
            color: var(--cream);
            text-align: center;
            position: relative;
            overflow: hidden;
            padding: clamp(4rem, 10vw, 8rem) 0;
        }

        .hero::before {
            content: '';
            position: absolute;
            inset: 0;
            background:
                radial-gradient(circle at 25% 35%, rgba(212, 168, 67, 0.14), transparent 55%),
                radial-gradient(circle at 75% 65%, rgba(212, 168, 67, 0.08), transparent 50%);
            pointer-events: none;
        }

        .hero h1 {
            color: var(--cream);
            position: relative;
        }

        .hero .crest {
            font-family: 'Cormorant Garamond', serif;
            font-size: 2.1rem;
            color: var(--gold);
            letter-spacing: 0.4em;
            margin-bottom: 1.5rem;
            font-style: italic;
            position: relative;
        }

        .hero .lead {
            color: var(--gold-s);
            margin: 1.5rem auto 0;
        }

        .hero .meta {
            margin-top: 2.5rem;
            font-size: 0.83rem;
            color: rgba(248, 243, 233, 0.5);
            letter-spacing: 0.17em;
            text-transform: uppercase;
            position: relative;
        }

        .hero .badges {
            margin-top: 2rem;
            display: flex;
            gap: 0.65rem;
            justify-content: center;
            flex-wrap: wrap;
            position: relative;
        }

        .hero .badge {
            background: rgba(212, 168, 67, 0.18);
            border: 1px solid rgba(212, 168, 67, 0.4);
            color: var(--gold-s);
            padding: 0.3rem 0.9rem;
            border-radius: 20px;
            font-size: 0.79rem;
            letter-spacing: 0.12em;
            font-weight: 500;
        }

        /* ── Phase block ── */
        .phase {
            background: white;
            border-left: 4px solid var(--gold);
            border-radius: 0 12px 12px 0;
            padding: 1.6rem 1.8rem;
            margin: 1.4rem 0;
            box-shadow: var(--shadow);
        }

        .phase.p2 {
            border-color: #b5a030;
        }

        .phase.p3 {
            border-color: var(--brown);
        }

        .phase.p4 {
            border-color: #6d9b47;
        }

        .phase.p5 {
            border-color: var(--blue);
        }

        .phase.p6 {
            border-color: var(--green);
        }

        .phase.p7 {
            border-color: var(--red);
        }

        .phase.p8 {
            border-color: var(--muted);
        }

        .phase-label {
            display: inline-block;
            font-family: 'Jost', sans-serif;
            text-transform: uppercase;
            letter-spacing: 0.16em;
            font-size: 0.7rem;
            font-weight: 600;
            background: var(--dark);
            color: var(--cream);
            padding: 0.17rem 0.6rem;
            border-radius: 4px;
            margin-bottom: 0.8rem;
        }

        /* ── Cards ── */
        .cards {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(250px, 1fr));
            gap: 1.2rem;
            margin-top: 1.8rem;
        }

        .card {
            background: white;
            border: 1px solid var(--line);
            border-radius: 14px;
            padding: 1.5rem;
            box-shadow: var(--shadow);
        }

        .card .icon {
            width: 42px;
            height: 42px;
            background: linear-gradient(135deg, var(--gold), var(--gold-s));
            border-radius: 10px;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            font-family: 'Cormorant Garamond', serif;
            font-size: 1.5rem;
            color: var(--dark);
            margin-bottom: 0.85rem;
            font-weight: 600;
        }

        /* ── Info boxes ── */
        .box {
            background: white;
            border-left: 4px solid var(--gold);
            border-radius: 6px;
            padding: 1rem 1.4rem;
            margin: 1.4rem 0;
            box-shadow: 0 4px 16px -6px rgba(26, 26, 26, 0.12);
        }

        .box.warn {
            border-color: var(--warn);
            background: rgba(201, 123, 0, 0.04);
        }

        .box.danger {
            border-color: var(--red);
            background: rgba(181, 50, 50, 0.04);
        }

        .box.success {
            border-color: var(--green);
            background: rgba(45, 122, 58, 0.04);
        }

        .box.blue {
            border-color: var(--blue);
            background: rgba(29, 95, 168, 0.04);
        }

        .box strong {
            display: block;
            margin-bottom: 0.3rem;
        }

        /* ── Flow diagram ── */
        .flow {
            display: flex;
            align-items: center;
            gap: 0.5rem;
            flex-wrap: wrap;
            margin: 1.5rem 0;
        }

        .flow-step {
            background: white;
            border: 1px solid var(--line);
            border-radius: 8px;
            padding: 0.55rem 1rem;
            font-size: 0.85rem;
            box-shadow: 0 2px 8px -4px rgba(26, 26, 26, 0.15);
            text-align: center;
            min-width: 110px;
        }

        .flow-step .fs-label {
            font-size: 0.7rem;
            text-transform: uppercase;
            letter-spacing: 0.1em;
            color: var(--muted);
        }

        .flow-step .fs-val {
            font-weight: 600;
            color: var(--dark);
        }

        .flow-arrow {
            color: var(--gold);
            font-size: 1.35rem;
            line-height: 1;
        }

        .flow-step.green {
            border-color: var(--green);
        }

        .flow-step.orange {
            border-color: var(--warn);
        }

        .flow-step.blue {
            border-color: var(--blue);
        }

        /* ── Status pill ── */
        .status {
            display: inline-block;
            padding: 0.18rem 0.7rem;
            border-radius: 20px;
            font-size: 0.76rem;
            font-weight: 600;
            letter-spacing: 0.06em;
        }

        .status.ok {
            background: rgba(45, 122, 58, 0.12);
            color: var(--green);
        }

        .status.pending {
            background: rgba(201, 123, 0, 0.12);
            color: var(--warn);
        }

        .status.fail {
            background: rgba(181, 50, 50, 0.1);
            color: var(--red);
        }

        .status.todo {
            background: rgba(108, 108, 108, 0.1);
            color: var(--muted);
        }

        /* ── Code block ── */
        .codeblock {
            background: #1a1a1a;
            color: #e8c878;
            font-family: 'SF Mono', 'Consolas', 'Fira Code', monospace;
            font-size: 0.83rem;
            line-height: 1.7;
            padding: 1.3rem 1.5rem;
            border-radius: 10px;
            margin: 1.2rem 0;
            overflow-x: auto;
            white-space: pre;
        }

        .codeblock .cm {
            color: #6c9b5e;
        }

        .codeblock .ck {
            color: #76b9d0;
        }

        .codeblock .cv {
            color: #e8c878;
        }

        .codeblock .cs {
            color: #d4826b;
        }

        /* ── Stats row ── */
        .stats {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(150px, 1fr));
            gap: 1rem;
            margin: 1.8rem 0;
        }

        .stat {
            background: white;
            border-left: 3px solid var(--gold);
            padding: 1.1rem 1.3rem;
            border-radius: 6px;
            box-shadow: 0 4px 16px -6px rgba(26, 26, 26, 0.18);
        }

        .stat .n {
            font-family: 'Cormorant Garamond', serif;
            font-size: 2.2rem;
            color: var(--brown);
            display: block;
            line-height: 1;
        }

        .stat .l {
            font-size: 0.77rem;
            color: var(--muted);
            letter-spacing: 0.1em;
            text-transform: uppercase;
            margin-top: 0.4rem;
            display: block;
        }

        /* ── Ordered steps ── */
        ol.steps {
            padding-left: 0;
            counter-reset: step;
            list-style: none;
        }

        ol.steps>li {
            position: relative;
            padding: 0.65rem 0 0.65rem 2.8rem;
            counter-increment: step;
            border-bottom: 1px dashed var(--line);
        }

        ol.steps>li:last-child {
            border-bottom: none;
        }

        ol.steps>li::before {
            content: counter(step);
            position: absolute;
            left: 0;
            top: 0.55rem;
            width: 30px;
            height: 30px;
            background: var(--gold);
            color: var(--dark);
            border-radius: 50%;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            font-weight: 700;
            font-family: 'Cormorant Garamond', serif;
            font-size: 1rem;
        }

        /* ── Checklist ── */
        ul.check {
            list-style: none;
            padding: 0;
        }

        ul.check li {
            padding: 0.45rem 0 0.45rem 2rem;
            position: relative;
            border-bottom: 1px dashed var(--line);
            font-size: 0.95rem;
        }

        ul.check li:last-child {
            border-bottom: none;
        }

        ul.check li::before {
            content: '✓';
            position: absolute;
            left: 0;
            top: 0.45rem;
            color: var(--gold);
            font-weight: 700;
            font-size: 1rem;
        }

        /* ── Table ── */
        table {
            width: 100%;
            border-collapse: collapse;
            margin: 1.5rem 0;
            background: white;
            border-radius: 10px;
            overflow: hidden;
            box-shadow: var(--shadow);
        }

        th {
            background: var(--dark);
            color: var(--cream);
            text-align: left;
            padding: 0.8rem 1rem;
            font-weight: 500;
            font-size: 0.88rem;
            letter-spacing: 0.04em;
        }

        td {
            padding: 0.8rem 1rem;
            border-bottom: 1px solid var(--line);
            vertical-align: top;
            font-size: 0.92rem;
        }

        tr:last-child td {
            border-bottom: none;
        }

        tr:nth-child(even) td {
            background: rgba(248, 243, 233, 0.4);
        }

        /* ── Go-live gate row ── */
        .gate {
            display: flex;
            gap: 1rem;
            align-items: flex-start;
            padding: 0.85rem 1rem;
            background: white;
            border-radius: 8px;
            margin: 0.5rem 0;
            border: 1px solid var(--line);
            box-shadow: 0 2px 8px -4px rgba(26, 26, 26, 0.14);
        }

        .gate .g-num {
            width: 30px;
            height: 30px;
            border-radius: 50%;
            flex-shrink: 0;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            font-weight: 700;
            font-family: 'Cormorant Garamond', serif;
            font-size: 1rem;
            margin-top: 0.1rem;
            background: rgba(212, 168, 67, 0.15);
            color: var(--brown);
        }

        .gate .g-body strong {
            display: block;
            font-size: 0.96rem;
            color: var(--dark);
        }

        .gate .g-body small {
            font-size: 0.82rem;
            color: var(--muted);
        }

        /* ── Phase strip ── */
        .phase-strip {
            display: flex;
            gap: 0.5rem;
            flex-wrap: wrap;
            margin: 2rem 0;
        }

        .phase-chip {
            display: inline-flex;
            align-items: center;
            gap: 0.5rem;
            background: white;
            border: 1px solid var(--line);
            border-radius: 24px;
            padding: 0.38rem 1rem;
            font-size: 0.83rem;
            box-shadow: 0 2px 8px -4px rgba(26, 26, 26, 0.16);
        }

        .phase-chip .num {
            background: var(--gold);
            color: var(--dark);
            width: 22px;
            height: 22px;
            border-radius: 50%;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            font-weight: 700;
            font-size: 0.75rem;
            font-family: 'Cormorant Garamond', serif;
        }

        /* ── Nav ── */
        nav.top {
            position: fixed;
            top: 0;
            left: 0;
            right: 0;
            background: rgba(26, 26, 26, 0.96);
            backdrop-filter: blur(12px);
            z-index: 100;
            padding: 0.65rem 1.2rem;
            display: flex;
            justify-content: space-between;
            align-items: center;
            border-bottom: 1px solid rgba(212, 168, 67, 0.2);
        }

        nav.top .brand {
            color: var(--gold);
            font-family: 'Cormorant Garamond', serif;
            font-size: 1.1rem;
            font-style: italic;
            letter-spacing: 0.16em;
            text-decoration: none;
            border: none;
        }

        nav.top .nav-links {
            display: flex;
            gap: 0.65rem;
            align-items: center;
        }

        nav.top .nav-links a {
            color: rgba(248, 243, 233, 0.65);
            font-size: 0.79rem;
            letter-spacing: 0.07em;
            border: none;
            padding: 0.28rem 0.65rem;
            border-radius: 20px;
            transition: 0.2s;
        }

        nav.top .nav-links a:hover {
            color: var(--gold);
            background: rgba(212, 168, 67, 0.1);
        }

        .toc-btn {
            background: transparent;
            color: var(--cream);
            border: 1px solid rgba(248, 243, 233, 0.25);
            padding: 0.35rem 0.85rem;
            border-radius: 20px;
            cursor: pointer;
            font-family: inherit;
            font-size: 0.79rem;
            letter-spacing: 0.1em;
            transition: 0.2s;
        }

        .toc-btn:hover {
            background: var(--gold);
            color: var(--dark);
            border-color: var(--gold);
        }

        /* ── TOC panel ── */
        .toc-panel {
            position: fixed;
            top: 56px;
            right: 1rem;
            background: white;
            box-shadow: var(--shadow);
            border-radius: 12px;
            padding: 1.1rem 1.4rem;
            border: 1px solid var(--line);
            display: none;
            z-index: 99;
            width: 300px;
            max-height: 78vh;
            overflow-y: auto;
        }

        .toc-panel.open {
            display: block;
        }

        .toc-panel h4 {
            color: var(--gold);
            font-family: 'Jost', sans-serif;
            font-size: 0.74rem;
            text-transform: uppercase;
            letter-spacing: 0.2em;
            margin-bottom: 0.7rem;
        }

        .toc-panel ol {
            padding-left: 1.2rem;
            font-size: 0.88rem;
        }

        .toc-panel ol li {
            padding: 0.22rem 0;
        }

        .toc-panel ol li a {
            border: none;
            color: var(--ink);
        }

        .toc-panel ol li a:hover {
            color: var(--gold);
        }

        /* ── Footer ── */
        footer {
            background: var(--dark);
            color: var(--cream);
            padding: 4rem 0 2.5rem;
            text-align: center;
        }

        footer .crest {
            color: var(--gold);
            font-family: 'Cormorant Garamond', serif;
            font-style: italic;
            font-size: 1.3rem;
            letter-spacing: 0.3em;
            margin-bottom: 0.8rem;
        }

        footer p {
            margin: 0.45rem auto;
            color: rgba(248, 243, 233, 0.6);
            max-width: 52ch;
            font-size: 0.9rem;
        }

        footer .sig {
            font-family: 'Cormorant Garamond', serif;
            font-style: italic;
            color: var(--gold);
            font-size: 1.2rem;
            margin-top: 1.5rem;
        }

        /* ── Print ── */
        @media print {

            nav.top,
            .toc-panel,
            .toc-btn {
                display: none !important;
            }

            body {
                padding-top: 0;
                background: white;
            }

            section.slide {
                padding: 1rem 0;
                border: none;
            }

            .hero {
                background: white;
                color: var(--dark);
                padding: 1.5rem 0;
            }

            .hero h1,
            .hero .crest,
            .hero .lead {
                color: var(--dark);
            }

            .codeblock {
                background: #f5f5f5;
                color: var(--dark);
                border: 1px solid #ddd;
            }
        }

        @media (max-width: 640px) {
            nav.top .nav-links {
                display: none;
            }

            .flow {
                flex-direction: column;
                align-items: flex-start;
            }

            .flow-arrow {
                transform: rotate(90deg);
                margin-left: 1rem;
            }

            table {
                font-size: 0.82rem;
            }

            th,
            td {
                padding: 0.6rem 0.7rem;
            }
        }

        @media (prefers-reduced-motion: reduce) {
            * {
                transition: none !important;
            }
        }
    </style>
</head>

<body>

    <nav class="top">
        <a href="owner-handover.php" class="brand"><?= htmlspecialchars($siteName, ENT_QUOTES, 'UTF-8') ?></a>
        <div class="nav-links">
            <a href="owner-handover.php">← Handover guide</a>
            <a href="../../admin/dashboard.php">Admin panel</a>
        </div>
        <button class="toc-btn" onclick="document.getElementById('toc').classList.toggle('open')">CONTENTS</button>
    </nav>

    <aside class="toc-panel" id="toc">
        <h4>Sections</h4>
        <ol>
            <li><a href="#intro">What is MRA EIS?</a></li>
            <li><a href="#today">How invoices work today</a></li>
            <li><a href="#phase1">Phase 1 — Database</a></li>
            <li><a href="#phase2">Phase 2 — Settings</a></li>
            <li><a href="#phase3">Phase 3 — API client module</a></li>
            <li><a href="#phase4">Phase 4 — Hook into payments</a></li>
            <li><a href="#phase5">Phase 5 — Queue &amp; retry</a></li>
            <li><a href="#phase6">Phase 6 — Receipts &amp; email</a></li>
            <li><a href="#phase7">Phase 7 — POS hardening</a></li>
            <li><a href="#cardreader">Card reader reality</a></li>
            <li><a href="#golive">Go-live gates</a></li>
            <li><a href="#summary">All phases at a glance</a></li>
        </ol>
    </aside>

    <!-- ══════════════════════════════════════════ HERO -->
    <section class="hero">
        <div class="deck">
            <div class="crest">— MRA EIS —</div>
            <h1><?= htmlspecialchars($siteName, ENT_QUOTES, 'UTF-8') ?><br>MRA Integration Guide</h1>
            <div class="divider" style="margin:1.5rem auto;"></div>
            <p class="lead">A step-by-step IT rollout plan for connecting the hotel PMS<br>to Malawi Revenue Authority&#8217;s Electronic Invoicing System.</p>
            <div class="badges">
                <span class="badge">8 PHASES</span>
                <span class="badge">CONCRETE FILE PATHS</span>
                <span class="badge">PLAIN IT ENGLISH</span>
                <span class="badge">Generated <?= $generated ?></span>
            </div>
            <p class="meta" style="position:relative;">Your existing invoice numbers and emails are NOT replaced &#8212; EIS data is added on top of what already works.</p>
        </div>
    </section>

    <!-- ══════════════════════════════════════════ WHAT IS MRA EIS -->
    <section class="slide" id="intro">
        <div class="deck">
            <p class="eyebrow">Background</p>
            <h2>What is MRA EIS?</h2>
            <p class="lead">MRA EIS = Malawi Revenue Authority Electronic Invoicing System. Every time you complete a paid sale, you must notify MRA in real time.</p>

            <div class="box">
                <strong>In plain English</strong>
                Every hotel payment &#8212; room booking, restaurant bill, conference deposit &#8212; must be reported to MRA the moment it is completed. MRA sends back a <strong>fiscal receipt number</strong> and a <strong>QR code</strong>. That QR code must appear on the customer&#8217;s receipt so they can scan it to verify the sale was reported.
            </div>

            <div class="cards">
                <div class="card">
                    <div class="icon">1</div>
                    <h3>What you send MRA</h3>
                    <p>Sale date, line items with descriptions, amounts, VAT breakdown, your TIN, the payment method. Sent as a JSON request over HTTPS.</p>
                </div>
                <div class="card">
                    <div class="icon">2</div>
                    <h3>What MRA sends back</h3>
                    <p>A <strong>fiscal receipt number</strong> (their unique ID for the transaction), a <strong>digital signature</strong>, and a <strong>QR payload</strong> that guests can scan to confirm the receipt is genuine.</p>
                </div>
                <div class="card">
                    <div class="icon">3</div>
                    <h3>What changes for guests</h3>
                    <p>Their receipt now has two numbers: your internal invoice number <em>and</em> the MRA fiscal number, plus a scannable QR code. Everything else stays the same.</p>
                </div>
                <div class="card">
                    <div class="icon">4</div>
                    <h3>What if MRA is offline?</h3>
                    <p>You keep selling. The transaction is queued and submitted automatically when MRA comes back online. The cashier is never blocked or slowed down.</p>
                </div>
            </div>

            <div class="box warn" style="margin-top:1.5rem;">
                <strong>&#9888; Billable / live-system warning</strong>
                MRA production submissions are live tax transactions. All testing must be done on the MRA <strong>sandbox</strong> environment first. We only switch to production after your explicit approval. Do not run production calls during development.
            </div>
        </div>
    </section>

    <!-- ══════════════════════════════════════════ HOW INVOICES WORK TODAY -->
    <section class="slide" id="today">
        <div class="deck">
            <p class="eyebrow">Current state</p>
            <h2>How your invoices work today</h2>
            <p>Before changing anything, here is exactly what the system already does. MRA integration <em>adds fields</em> &#8212; it does not replace this.</p>

            <div class="phase">
                <span class="phase-label">Invoice numbering</span>
                <h3>Format: <code><?= $invPrefix ?>-<?= $year ?>-001234</code></h3>
                <p>Controlled by two <code>site_settings</code> rows: <code>invoice_prefix</code> (currently <strong><?= $invPrefix ?></strong>) and <code>invoice_start_number</code>. The number increments by finding the current MAX in the <code>payments</code> table. Logic lives in <code>config/invoice.php</code> around line&nbsp;127:</p>
                <div class="codeblock"><span class="cm">// config/invoice.php — invoice numbering logic</span>
                    <span class="ck">$invoice_prefix</span> = getSetting(<span class="cs">'invoice_prefix'</span>, <span class="cs">'INV'</span>);
                    <span class="ck">$next_number</span> = max(<span class="ck">$invoice_start</span>, (<span class="ck">$result</span>[<span class="cs">'max_inv'</span>] ?? <span class="cv">0</span>) + <span class="cv">1</span>);
                    <span class="ck">$invoice_number</span> = <span class="ck">$invoice_prefix</span>
                    . <span class="cs">'-'</span> . date(<span class="cs">'Y'</span>)
                    . <span class="cs">'-'</span> . str_pad(<span class="ck">$next_number</span>, <span class="cv">6</span>, <span class="cs">'0'</span>, STR_PAD_LEFT);
                </div>
                <p>Conference invoices use a separate sequence: <code>CONF-<?= $invPrefix ?>-<?= $year ?>-000001</code> (same file, ~line 1361). Restaurant receipts use <code>RST-<?= $year ?>-XXXXXX</code> in <code>admin/stock-receipt.php</code>.</p>
            </div>

            <div class="phase p2">
                <span class="phase-label">Email sending — 4 trigger points</span>
                <h3>When does the invoice email go out?</h3>
                <table>
                    <thead>
                        <tr>
                            <th>Trigger</th>
                            <th>File : line</th>
                            <th>Function called</th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr>
                            <td>Admin clicks &#8220;Send Invoice&#8221; in booking details</td>
                            <td><code>admin/booking-details.php : 174</code></td>
                            <td><code>sendPaymentInvoiceEmailWithCC()</code></td>
                        </tr>
                        <tr>
                            <td>Payment marked complete in bookings list</td>
                            <td><code>admin/bookings.php : 844</code></td>
                            <td><code>sendPaymentInvoiceEmailWithCC()</code></td>
                        </tr>
                        <tr>
                            <td>New payment added via payment-add screen</td>
                            <td><code>admin/payment-add.php : 305</code></td>
                            <td><code>sendPaymentInvoiceEmailWithCC()</code></td>
                        </tr>
                        <tr>
                            <td>Admin sends via WhatsApp</td>
                            <td><code>admin/booking-details.php : 222</code></td>
                            <td><code>sendWhatsAppMessage()</code></td>
                        </tr>
                    </tbody>
                </table>
                <p>All email sends are gated by the <code>send_invoice_emails</code> setting. If it is off, the function silently skips. The function itself is in <code>config/invoice.php</code> around line&nbsp;689.</p>
            </div>

            <div class="box success">
                <strong>&#10003; What this means for integration</strong>
                We do not rebuild invoicing from scratch. We add MRA fields to the database, call the MRA API after each payment, stamp the result back onto the existing record, and the invoice template picks it up when printing or emailing.
            </div>

            <div class="stats">
                <div class="stat"><span class="n"><?= number_format($totalPayments) ?></span><span class="l">Payments on record</span></div>
                <div class="stat"><span class="n"><?= number_format($totalBookings) ?></span><span class="l">Bookings on record</span></div>
                <div class="stat"><span class="n"><?= $vatEnabled === '1' ? $vatRate . '%' : 'Off' ?></span><span class="l">VAT currently</span></div>
                <div class="stat"><span class="n"><?= $vatNumber ?: '&#8212;' ?></span><span class="l">VAT / TIN number</span></div>
            </div>
        </div>
    </section>

    <!-- ══════════════════════════════════════════ PHASE 1 — DATABASE -->
    <section class="slide" id="phase1">
        <div class="deck">
            <p class="eyebrow">Phase 1 of 8</p>
            <h2>Database &#8212; add MRA columns</h2>
            <p class="lead">Safe to do first. Adding columns to an existing table never breaks any live code or data &#8212; the new columns just start as NULL until used.</p>

            <div class="phase">
                <span class="phase-label">Alter table: payments</span>
                <h3>Add 7 new columns to the existing <code>payments</code> table</h3>
                <table>
                    <thead>
                        <tr>
                            <th>Column</th>
                            <th>Type</th>
                            <th>What it stores</th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr>
                            <td><code>mra_status</code></td>
                            <td>ENUM</td>
                            <td>Where the submission stands: <code>not_required</code>, <code>pending</code>, <code>submitted</code>, <code>accepted</code>, <code>rejected</code>, <code>retrying</code></td>
                        </tr>
                        <tr>
                            <td><code>mra_fiscal_no</code></td>
                            <td>VARCHAR(100)</td>
                            <td>The receipt number MRA returns (their ID, separate from your invoice number)</td>
                        </tr>
                        <tr>
                            <td><code>mra_signature</code></td>
                            <td>VARCHAR(500)</td>
                            <td>MRA&#8217;s digital signature for the transaction &#8212; proves the submission is authentic</td>
                        </tr>
                        <tr>
                            <td><code>mra_qr_payload</code></td>
                            <td>TEXT</td>
                            <td>The QR code data &#8212; printed on customer receipts so they can verify it</td>
                        </tr>
                        <tr>
                            <td><code>mra_submitted_at</code></td>
                            <td>DATETIME</td>
                            <td>When we sent the submission to MRA</td>
                        </tr>
                        <tr>
                            <td><code>mra_accepted_at</code></td>
                            <td>DATETIME</td>
                            <td>When MRA confirmed acceptance (may be the same second or after a retry)</td>
                        </tr>
                        <tr>
                            <td><code>mra_last_error</code></td>
                            <td>TEXT</td>
                            <td>Last error message from MRA &#8212; stored for debugging rejections</td>
                        </tr>
                    </tbody>
                </table>
            </div>

            <div class="phase p2">
                <span class="phase-label">New table: mra_submission_queue</span>
                <h3>The queue table &#8212; your protection against MRA downtime</h3>
                <p>Every submission goes into this table first. A background worker reads it and sends to MRA. Even if MRA is unreachable for hours, nothing is lost and no sale is blocked.</p>
                <table>
                    <thead>
                        <tr>
                            <th>Column</th>
                            <th>What it does</th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr>
                            <td><code>payment_id</code></td>
                            <td>Links to the <code>payments</code> row that needs to be reported</td>
                        </tr>
                        <tr>
                            <td><code>payload_json</code></td>
                            <td>The exact data to send to MRA &#8212; stored so retries send the same thing</td>
                        </tr>
                        <tr>
                            <td><code>attempt_count</code></td>
                            <td>How many times we have tried to submit</td>
                        </tr>
                        <tr>
                            <td><code>next_attempt_at</code></td>
                            <td>When to try again &#8212; doubles each attempt: 1 min, 2 min, 4 min, 8 min&#8230;</td>
                        </tr>
                        <tr>
                            <td><code>status</code></td>
                            <td>pending / processing / done / failed</td>
                        </tr>
                        <tr>
                            <td><code>last_response</code></td>
                            <td>Raw response from MRA stored for debugging</td>
                        </tr>
                    </tbody>
                </table>
            </div>

            <div class="box">
                <strong>Migration file to create: <code>admin/migrations/039_mra_eis_integration.php</code></strong>
                Runs <code>ALTER TABLE payments ADD COLUMN IF NOT EXISTS &#8230;</code> (idempotent &#8212; safe to run twice) and <code>CREATE TABLE IF NOT EXISTS mra_submission_queue</code>. Follows the same pattern as <code>admin/migrations/035_dynamic_pricing.php</code>.
            </div>
        </div>
    </section>

    <!-- ══════════════════════════════════════════ PHASE 2 — SETTINGS -->
    <section class="slide" id="phase2">
        <div class="deck">
            <p class="eyebrow">Phase 2 of 8</p>
            <h2>Settings &#8212; MRA credentials and controls</h2>
            <p class="lead">All MRA connection details live in <code>site_settings</code> and <code>.env</code> &#8212; never hardcoded in source files. The admin UI goes in <code>admin/booking-settings.php</code> under a new MRA section.</p>

            <div class="phase p2">
                <span class="phase-label">site_settings keys to add</span>
                <table>
                    <thead>
                        <tr>
                            <th>Key</th>
                            <th>Example value</th>
                            <th>Purpose</th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr>
                            <td><code>mra_eis_enabled</code></td>
                            <td>0</td>
                            <td>Master on/off switch. When off, submissions are skipped entirely and everything else works normally.</td>
                        </tr>
                        <tr>
                            <td><code>mra_eis_mode</code></td>
                            <td>sandbox</td>
                            <td>Controls which endpoint URL is active. <strong>Always start on sandbox.</strong></td>
                        </tr>
                        <tr>
                            <td><code>mra_eis_sandbox_url</code></td>
                            <td>URL from MRA</td>
                            <td>MRA test environment endpoint &#8212; for all development and UAT work</td>
                        </tr>
                        <tr>
                            <td><code>mra_eis_production_url</code></td>
                            <td>URL from MRA</td>
                            <td>MRA live endpoint &#8212; only used when mode is switched to production</td>
                        </tr>
                        <tr>
                            <td><code>mra_eis_tin</code></td>
                            <td>Your TIN</td>
                            <td>Hotel&#8217;s tax identification number &#8212; appears in every submission payload</td>
                        </tr>
                        <tr>
                            <td><code>mra_eis_device_id</code></td>
                            <td>From MRA portal</td>
                            <td>MRA assigns this when you register your point-of-sale device/branch</td>
                        </tr>
                        <tr>
                            <td><code>mra_eis_timeout_seconds</code></td>
                            <td>10</td>
                            <td>How long to wait for MRA to respond before giving up and retrying later</td>
                        </tr>
                        <tr>
                            <td><code>mra_eis_retry_limit</code></td>
                            <td>5</td>
                            <td>Max automatic retries before flagging as permanently failed for manual review</td>
                        </tr>
                    </tbody>
                </table>
            </div>

            <div class="phase p3">
                <span class="phase-label">.env secrets &#8212; never in the database</span>
                <h3>API credentials go in <code>.env</code>, not <code>site_settings</code></h3>
                <p>MRA client ID and secret follow the same pattern as your existing SMTP password and WhatsApp token:</p>
                <div class="codeblock"><span class="cm"># .env — add these two lines</span>
                    <span class="ck">MRA_EIS_CLIENT_ID</span>=<span class="cv">your_client_id_here</span>
                    <span class="ck">MRA_EIS_CLIENT_SECRET</span>=<span class="cv">your_client_secret_here</span>
                </div>
                <div class="box danger" style="margin-top:0;">
                    <strong>Security rule</strong>
                    MRA credentials must NEVER appear in the database or be returned by any API endpoint. They are loaded only in server-side PHP via <code>$_ENV['MRA_EIS_CLIENT_ID']</code>. The <code>site_settings</code> table is explicitly excluded from all public API responses for this reason.
                </div>
            </div>
        </div>
    </section>

    <!-- ══════════════════════════════════════════ PHASE 3 — API CLIENT -->
    <section class="slide" id="phase3">
        <div class="deck">
            <p class="eyebrow">Phase 3 of 8</p>
            <h2>API client module &#8212; one file, three functions</h2>
            <p class="lead">All MRA HTTP communication lives in one new file. No MRA calls scattered across pages. If MRA changes their API, there is one place to update.</p>

            <div class="phase p3">
                <span class="phase-label">New file: config/mra-eis.php</span>
                <h3>Three functions</h3>
                <ol class="steps">
                    <li>
                        <strong><code>mra_authenticate(): string</code></strong>
                        <p>Gets a bearer token from MRA using your client ID and secret from <code>.env</code>. Token is cached in <code>site_settings</code> with an expiry timestamp so we don&#8217;t re-authenticate on every call. Every other function calls this internally first.</p>
                    </li>
                    <li>
                        <strong><code>mra_submit_invoice(array $payload): array</code></strong>
                        <p>Sends one sale to MRA. Returns an array that always has the same shape regardless of success or failure: <code>['success' =&gt; true/false, 'fiscal_no' =&gt; '&#8230;', 'signature' =&gt; '&#8230;', 'qr_payload' =&gt; '&#8230;', 'error' =&gt; '&#8230;']</code>. Never throws an exception &#8212; always returns so the caller can handle errors without try/catch everywhere.</p>
                    </li>
                    <li>
                        <strong><code>mra_build_payload(array $payment, array $booking): array</code></strong>
                        <p>Converts your internal payment data into the exact JSON structure MRA expects. Maps your database column names to MRA&#8217;s field names. Handles line items, VAT amounts, TIN, device ID, and currency formatting. This is the only place that knows about MRA&#8217;s payload format.</p>
                    </li>
                </ol>
            </div>

            <div class="phase p2">
                <span class="phase-label">Logging</span>
                <h3>Every MRA call logged via the existing <code>rh_log_event()</code></h3>
                <p>Uses the same event logger already in this project. All MRA entries use <code>source = 'mra_eis'</code>. Successful submissions log at <code>info</code>, failures at <code>error</code>. Raw credentials and card numbers are never logged.</p>
                <div class="codeblock"><span class="cm">// After a successful fiscal submission</span>
                    rh_log_event(<span class="cs">'mra_eis'</span>, <span class="cs">'info'</span>, <span class="cs">'Fiscal invoice accepted'</span>, [
                    <span class="cs">'payment_id'</span> => <span class="ck">$payment_id</span>,
                    <span class="cs">'fiscal_no'</span> => <span class="ck">$result</span>[<span class="cs">'fiscal_no'</span>],
                    <span class="cs">'amount'</span> => <span class="ck">$total</span>,
                    ]);
                </div>
            </div>
        </div>
    </section>

    <!-- ══════════════════════════════════════════ PHASE 4 — HOOK POINTS -->
    <section class="slide" id="phase4">
        <div class="deck">
            <p class="eyebrow">Phase 4 of 8</p>
            <h2>Hook points &#8212; where EIS plugs into existing code</h2>
            <p class="lead">No existing functions are rewritten. We add one new call after each payment completes. Three entry points cover every payment type in the hotel.</p>

            <div class="phase p4">
                <span class="phase-label">Hook 1 &#8212; room &amp; conference payments</span>
                <h3><code>api/payments.php</code> &#8212; after the database commit</h3>
                <p>When a payment is created via the payments API (line ~575 today), immediately after <code>$pdo-&gt;commit()</code> and before the JSON response goes back, we enqueue the MRA submission:</p>
                <div class="flow">
                    <div class="flow-step">
                        <div class="fs-label">Payment saved</div>
                        <div class="fs-val">DB commit</div>
                    </div>
                    <div class="flow-arrow">&#8594;</div>
                    <div class="flow-step orange">
                        <div class="fs-label">One new line</div>
                        <div class="fs-val">mra_queue_submission()</div>
                    </div>
                    <div class="flow-arrow">&#8594;</div>
                    <div class="flow-step green">
                        <div class="fs-label">API response</div>
                        <div class="fs-val">201 Created</div>
                    </div>
                </div>
                <p>The frontend gets its success response instantly. MRA submission happens in the background &#8212; the cashier never waits for MRA.</p>
            </div>

            <div class="phase p2">
                <span class="phase-label">Hook 2 &#8212; POS restaurant payments</span>
                <h3><code>admin/stock-orders.php</code> &#8212; after order status becomes &#8220;paid&#8221;</h3>
                <p>The POS payment flow completes around line 564 today. After the order is stamped <code>paid</code> and the ledger sync runs (<code>pos_syncPayment()</code> from <code>admin/pos.php</code>), we enqueue the MRA submission:</p>
                <div class="flow">
                    <div class="flow-step">
                        <div class="fs-label">Cashier taps</div>
                        <div class="fs-val">Confirm payment</div>
                    </div>
                    <div class="flow-arrow">&#8594;</div>
                    <div class="flow-step">
                        <div class="fs-label">Ledger sync</div>
                        <div class="fs-val">pos_syncPayment()</div>
                    </div>
                    <div class="flow-arrow">&#8594;</div>
                    <div class="flow-step orange">
                        <div class="fs-label">One new line</div>
                        <div class="fs-val">mra_queue_submission()</div>
                    </div>
                    <div class="flow-arrow">&#8594;</div>
                    <div class="flow-step green">
                        <div class="fs-label">Receipt printed</div>
                        <div class="fs-val">Shows QR when ready</div>
                    </div>
                </div>
            </div>

            <div class="phase p3">
                <span class="phase-label">Hook 3 &#8212; manually recorded payments</span>
                <h3><code>admin/payment-add.php</code> &#8212; front desk cash payments</h3>
                <p>When a manager manually records a payment from the admin panel, the same enqueue call goes in after the payment INSERT. This covers walk-in guests paying cash at the front desk.</p>
            </div>

            <div class="box blue">
                <strong>Room service orders</strong>
                Room service charges are added to the booking folio (<code>booking_charges</code> table), not paid at the time of the food order. The fiscal submission for room service charges happens when the guest <strong>checks out and the final payment is recorded</strong> &#8212; not when the food is ordered. This is how MRA expects hospitality billing to work.
            </div>
        </div>
    </section>

    <!-- ══════════════════════════════════════════ PHASE 5 — QUEUE -->
    <section class="slide" id="phase5">
        <div class="deck">
            <p class="eyebrow">Phase 5 of 8</p>
            <h2>Queue and retry &#8212; MRA downtime never stops the hotel</h2>
            <p class="lead">This is the most important reliability feature. The cashier is never blocked waiting for MRA to respond &#8212; the queue absorbs all the waiting.</p>

            <div class="phase p5">
                <span class="phase-label">How the queue works</span>
                <h3>Write locally first, submit to MRA second</h3>
                <ol class="steps">
                    <li>Payment is completed &#8594; saved to your database &#8594; a row is inserted into <code>mra_submission_queue</code> with status <code>pending</code></li>
                    <li>Cashier gets their success confirmation immediately &#8212; no waiting at all</li>
                    <li>Background worker (cron job, runs every 60 seconds) picks up <code>pending</code> rows</li>
                    <li>Worker calls <code>mra_submit_invoice()</code> for each queued item</li>
                    <li>If MRA <strong>accepts</strong>: update <code>payments.mra_status = 'accepted'</code>, store fiscal number, signature, and QR payload, mark queue row as done</li>
                    <li>If MRA <strong>rejects</strong> (bad data in the payload): update <code>payments.mra_status = 'rejected'</code>, store error message, flag for manager review</li>
                    <li>If MRA is <strong>offline</strong> (timeout or 5xx error): set <code>next_attempt_at = NOW() + 2^attempt_count minutes</code> &#8212; doubles each time. Max <?= mra_g('mra_eis_retry_limit', '5') ?> retries before marking permanently failed.</li>
                </ol>
            </div>

            <div class="phase p2">
                <span class="phase-label">New file: scripts/mra-worker.php</span>
                <h3>The cron script &#8212; runs every minute</h3>
                <p>CLI-only script (exits immediately if called from a browser). Picks up to 50 pending queue items per run, submits them, logs the results. Added to the server&#8217;s crontab:</p>
                <div class="codeblock"><span class="cm"># Server crontab — run the MRA worker every minute</span>
                    <span class="cv">* * * * *</span> php /var/www/html/scripts/mra-worker.php >> /dev/null 2>&amp;1
                </div>
            </div>

            <div class="box warn">
                <strong>Manager alert for permanently failed submissions</strong>
                After the retry limit is hit, the queue item is marked <code>failed</code> and an email alert goes to the configured admin address. These need manual review &#8212; they mean either the submission data is wrong or the payment needs to be voided and re-entered.
            </div>
        </div>
    </section>

    <!-- ══════════════════════════════════════════ PHASE 6 — RECEIPTS -->
    <section class="slide" id="phase6">
        <div class="deck">
            <p class="eyebrow">Phase 6 of 8</p>
            <h2>Receipts and email &#8212; adding fiscal data to output</h2>
            <p class="lead">The invoice template and email function stay the same. We add a new fiscal section at the bottom of the PDF when the MRA data is available.</p>

            <div class="phase p6">
                <span class="phase-label">What changes on the invoice PDF</span>
                <h3>Three scenarios depending on MRA status at print time</h3>
                <table>
                    <thead>
                        <tr>
                            <th>MRA status at print time</th>
                            <th>What appears on the receipt</th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr>
                            <td><span class="status ok">accepted</span></td>
                            <td>Fiscal box: MRA fiscal receipt number, submission date/time, scannable QR code</td>
                        </tr>
                        <tr>
                            <td><span class="status pending">pending / retrying</span></td>
                            <td>Grey note: <em>&#8220;Fiscal validation pending &#8212; a finalised receipt will be emailed once confirmed.&#8221;</em></td>
                        </tr>
                        <tr>
                            <td><span class="status fail">rejected / failed</span></td>
                            <td>Nothing shown to guest. Flagged in admin panel for manager action. Guest receipt is resent once resolved.</td>
                        </tr>
                    </tbody>
                </table>
            </div>

            <div class="phase p2">
                <span class="phase-label">Auto-resend on acceptance</span>
                <h3>Guest gets a final receipt automatically</h3>
                <p>When the background worker moves a submission from <code>pending</code> to <code>accepted</code>, it checks whether the invoice email was already sent while the status was still pending. If it was &#8212; it automatically resends a <strong>finalised version</strong> with the MRA fiscal number and QR code. No manual action needed by the front desk.</p>
                <p>This is handled inside <code>scripts/mra-worker.php</code>, reusing the existing <code>sendPaymentInvoiceEmailWithCC()</code> from <code>config/invoice.php</code>.</p>
            </div>

            <div class="box">
                <strong>Files touched in this phase</strong>
                <ul class="check" style="margin-top:0.5rem;">
                    <li><code>config/invoice.php</code> &#8212; <code>buildInvoiceHTML()</code> gets a new MRA fiscal block at the bottom of the template</li>
                    <li><code>scripts/mra-worker.php</code> &#8212; triggers the resend when status changes to accepted</li>
                    <li><code>admin/stock-receipt.php</code> &#8212; the restaurant printed receipt gets the same fiscal block</li>
                </ul>
            </div>
        </div>
    </section>

    <!-- ══════════════════════════════════════════ PHASE 7 — POS -->
    <section class="slide" id="phase7">
        <div class="deck">
            <p class="eyebrow">Phase 7 of 8</p>
            <h2>POS hardening &#8212; fiscal awareness for the cashier</h2>
            <p class="lead">The POS is already solid. This phase adds fiscal status visibility and a shift-close safety check.</p>

            <div class="phase p7">
                <span class="phase-label">Cashier-visible status badge</span>
                <h3>Each completed order on the orders list shows one badge</h3>
                <table>
                    <thead>
                        <tr>
                            <th>Badge</th>
                            <th>What it means</th>
                            <th>Cashier action</th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr>
                            <td><span class="status ok">Fiscalised</span></td>
                            <td>MRA accepted the submission &#8212; fully compliant</td>
                            <td>Nothing required</td>
                        </tr>
                        <tr>
                            <td><span class="status pending">Pending sync</span></td>
                            <td>Queued, waiting for MRA to respond</td>
                            <td>Nothing &#8212; handled automatically in the background</td>
                        </tr>
                        <tr>
                            <td><span class="status fail">Rejected</span></td>
                            <td>MRA rejected the submission (data issue)</td>
                            <td>Call manager &#8212; do not void the order yet</td>
                        </tr>
                        <tr>
                            <td><span class="status todo">Skipped</span></td>
                            <td>MRA EIS was switched off when this order was placed</td>
                            <td>Manager can manually trigger the submission</td>
                        </tr>
                    </tbody>
                </table>
            </div>

            <div class="phase p2">
                <span class="phase-label">Shift close guard</span>
                <h3>Block shift close if rejected submissions exist</h3>
                <p>The shift close in <code>admin/stock-orders.php</code> gets an extra check: if any orders from the current shift have <code>mra_status = 'rejected'</code>, the close is blocked and a warning is shown. The manager must resolve those submissions before closing the shift.</p>
                <p>This behaviour is configurable via <code>mra_block_shift_close_on_rejection</code> in <code>site_settings</code> (default: 1). Can be disabled if needed.</p>
            </div>

            <div class="phase p3">
                <span class="phase-label">Manual re-submit button</span>
                <h3>Admin can force-submit any payment from the detail screen</h3>
                <p>In <code>admin/payment-details.php</code> and <code>admin/booking-details.php</code>, a new &#8220;Re-submit to MRA&#8221; button appears for payments where <code>mra_status</code> is <code>rejected</code>, <code>failed</code>, or <code>skipped</code>. Clicking it puts the payment back into the queue. The same worker picks it up within the next minute.</p>
            </div>
        </div>
    </section>

    <!-- ══════════════════════════════════════════ CARD READER -->
    <section class="slide" id="cardreader">
        <div class="deck">
            <p class="eyebrow">Frequently asked</p>
            <h2>Card reader &#8212; what MRA integration does and does not do</h2>

            <div class="box danger">
                <strong>MRA EIS does NOT connect a card terminal automatically</strong>
                These are two completely separate integrations. MRA is about fiscal reporting to the tax authority. A card terminal is about processing card payments with a bank. They share data but are built by different systems and require different contracts.
            </div>

            <div class="phase p7">
                <span class="phase-label">Current POS card handling</span>
                <h3>What the code does today</h3>
                <p>In <code>admin/stock-orders.php</code> around line 389, the <code>card_pos</code> payment method throws an intentional error: <em>&#8220;Card POS terminal is not enabled yet &#8212; use Card (manual) for now.&#8221;</em></p>
                <p>The <code>card_manual</code> method is live and works. The cashier types in the <strong>last 4 digits</strong> and the <strong>authorisation code</strong> from the physical terminal slip. This is the current production method.</p>
            </div>

            <div class="cards" style="margin-top:1.5rem;">
                <div class="card">
                    <div class="icon" style="background:linear-gradient(135deg,#2d7a3a,#4a9a5a);">A</div>
                    <h3>MRA EIS (this build)</h3>
                    <p>Reports the completed sale to MRA once payment is confirmed. Gets a fiscal number and QR code back. Adds them to the receipt. Runs on our server after payment is already done.</p>
                    <p style="margin-top:0.5rem;"><span class="status ok">In this build</span></p>
                </div>
                <div class="card">
                    <div class="icon" style="background:linear-gradient(135deg,var(--red),#d45858);">B</div>
                    <h3>Bank card terminal integration</h3>
                    <p>Connects the physical card machine to the POS so the sale amount is sent automatically to the terminal and the authorisation code comes back without typing. Requires a separate contract with the acquiring bank and their developer SDK.</p>
                    <p style="margin-top:0.5rem;"><span class="status todo">Separate workstream</span></p>
                </div>
            </div>

            <div class="phase p2" style="margin-top:1.8rem;">
                <span class="phase-label">Future combined checkout &#8212; when both are built</span>
                <div class="flow">
                    <div class="flow-step">
                        <div class="fs-label">Cashier</div>
                        <div class="fs-val">Taps &#8220;Pay&#8221;</div>
                    </div>
                    <div class="flow-arrow">&#8594;</div>
                    <div class="flow-step blue">
                        <div class="fs-label">Card terminal</div>
                        <div class="fs-val">Bank authorises</div>
                    </div>
                    <div class="flow-arrow">&#8594;</div>
                    <div class="flow-step">
                        <div class="fs-label">POS records</div>
                        <div class="fs-val">Auth code auto-filled</div>
                    </div>
                    <div class="flow-arrow">&#8594;</div>
                    <div class="flow-step orange">
                        <div class="fs-label">MRA EIS</div>
                        <div class="fs-val">Queued for reporting</div>
                    </div>
                    <div class="flow-arrow">&#8594;</div>
                    <div class="flow-step green">
                        <div class="fs-label">Receipt</div>
                        <div class="fs-val">Card auth + MRA QR</div>
                    </div>
                </div>
            </div>
        </div>
    </section>

    <!-- ══════════════════════════════════════════ GO-LIVE GATES -->
    <section class="slide" id="golive">
        <div class="deck">
            <p class="eyebrow">Phase 8 of 8</p>
            <h2>Go-live gates &#8212; in this exact order</h2>
            <p class="lead">Each gate must pass before moving to the next one. Do not skip any step.</p>

            <div class="gate">
                <div class="g-num">1</div>
                <div class="g-body"><strong>Run migration 039 on the live database</strong><small>Adds MRA columns to <code>payments</code> table and creates <code>mra_submission_queue</code>. Verify with <code>SHOW COLUMNS FROM payments LIKE 'mra%'</code>. Should return 7 rows.</small></div>
            </div>
            <div class="gate">
                <div class="g-num">2</div>
                <div class="g-body"><strong>Add MRA settings in Admin &#8594; Booking Settings</strong><small>Set mode to <strong>sandbox</strong>, paste the sandbox URL from MRA, enter your TIN and device ID. Leave <code>mra_eis_enabled = 0</code> for now.</small></div>
            </div>
            <div class="gate">
                <div class="g-num">3</div>
                <div class="g-body"><strong>Add MRA credentials to <code>.env</code> on the server</strong><small>Add <code>MRA_EIS_CLIENT_ID</code> and <code>MRA_EIS_CLIENT_SECRET</code>. Test that <code>mra_authenticate()</code> returns a valid token from the sandbox endpoint.</small></div>
            </div>
            <div class="gate">
                <div class="g-num">4</div>
                <div class="g-body"><strong>Enable EIS and run sandbox tests &#8212; 5 payment types</strong><small>Cash booking, mobile money booking, manual card booking, conference deposit, restaurant POS bill. Verify all 5 return <code>mra_status = accepted</code> within 60 seconds.</small></div>
            </div>
            <div class="gate">
                <div class="g-num">5</div>
                <div class="g-body"><strong>Test the retry queue</strong><small>Temporarily set <code>mra_eis_sandbox_url</code> to a dead URL. Make a payment. Confirm it queues with status <code>pending</code>. Fix the URL. Confirm the worker picks it up and submits successfully. Check <code>system_event_log</code> for <code>source = mra_eis</code> entries.</small></div>
            </div>
            <div class="gate">
                <div class="g-num">6</div>
                <div class="g-body"><strong>Test receipt output for all three MRA states</strong><small>Confirm the invoice PDF shows the fiscal block when accepted, the &#8220;pending&#8221; message when the queue has not yet processed, and nothing on the guest receipt for rejected status.</small></div>
            </div>
            <div class="gate">
                <div class="g-num">7</div>
                <div class="g-body"><strong>Run a full shadow trading day on sandbox</strong><small>Keep <code>mra_eis_mode = sandbox</code> for one full trading day. Every real payment is shadow-submitted to sandbox. At end of day compare the count of <code>mra_status = accepted</code> rows in <code>payments</code> against total payments. They should match.</small></div>
            </div>
            <div class="gate">
                <div class="g-num">8</div>
                <div class="g-body"><strong>Get production approval from MRA</strong><small>Contact the MRA EIS team to confirm your sandbox tests passed, obtain your production device ID, and receive production credentials. This step is with MRA &#8212; not with us. Typically takes a few days.</small></div>
            </div>
            <div class="gate">
                <div class="g-num">9</div>
                <div class="g-body"><strong>Switch to production &#8212; with your explicit go-ahead</strong><small>Change <code>mra_eis_mode</code> from <code>sandbox</code> to <code>production</code> and swap credentials in <code>.env</code>. This is a live tax-reporting action. <strong>Requires your approval before we make this change.</strong></small></div>
            </div>
            <div class="gate">
                <div class="g-num">10</div>
                <div class="g-body"><strong>Monitor for 48 hours after going live</strong><small>Check queue lag (target: under 2 minutes), rejection rate (target: 0%), and resend success. Review <code>system_event_log</code> filtered to <code>source = mra_eis</code> twice daily. Flag any anomaly.</small></div>
            </div>

            <div class="box success" style="margin-top:1.8rem;">
                <strong>&#10003; Rollback plan &#8212; instant and safe</strong>
                Setting <code>mra_eis_enabled = 0</code> in <code>site_settings</code> turns off all MRA submissions immediately, without touching any payment data, invoices, or emails. The POS continues selling normally. No data is lost. Roll back takes under 30 seconds.
            </div>

            <div class="box warn" style="margin-top:1rem;">
                <strong>What you need from MRA before Phase 3 development starts</strong>
                <ul class="check" style="margin-top:0.5rem;">
                    <li>Sandbox API endpoint URL</li>
                    <li>Client ID and Client Secret for sandbox</li>
                    <li>EIS payload specification document (their required JSON field names and formats)</li>
                    <li>Your registered TIN number</li>
                    <li>Your MRA-assigned device or branch ID</li>
                </ul>
            </div>
        </div>
    </section>

    <!-- ══════════════════════════════════════════ SUMMARY -->
    <section class="slide" id="summary">
        <div class="deck">
            <p class="eyebrow">Summary</p>
            <h2>All 8 phases at a glance</h2>

            <table>
                <thead>
                    <tr>
                        <th>Phase</th>
                        <th>What we build</th>
                        <th>Files created / changed</th>
                        <th>Blocks selling?</th>
                    </tr>
                </thead>
                <tbody>
                    <tr>
                        <td><strong>1 &#8212; Database</strong></td>
                        <td>Add MRA columns to <code>payments</code>, create queue table</td>
                        <td><code>admin/migrations/039_mra_eis_integration.php</code> (new)</td>
                        <td>No</td>
                    </tr>
                    <tr>
                        <td><strong>2 &#8212; Settings</strong></td>
                        <td>EIS credentials and controls in site_settings + .env</td>
                        <td><code>admin/booking-settings.php</code></td>
                        <td>No</td>
                    </tr>
                    <tr>
                        <td><strong>3 &#8212; API client</strong></td>
                        <td><code>mra_authenticate()</code>, <code>mra_submit_invoice()</code>, <code>mra_build_payload()</code></td>
                        <td><code>config/mra-eis.php</code> (new)</td>
                        <td>No</td>
                    </tr>
                    <tr>
                        <td><strong>4 &#8212; Hook points</strong></td>
                        <td>One enqueue call after every payment confirmation</td>
                        <td><code>api/payments.php</code>, <code>admin/stock-orders.php</code>, <code>admin/payment-add.php</code></td>
                        <td>No</td>
                    </tr>
                    <tr>
                        <td><strong>5 &#8212; Queue / retry</strong></td>
                        <td>Background worker with exponential backoff retry</td>
                        <td><code>scripts/mra-worker.php</code> (new), server crontab</td>
                        <td>Never</td>
                    </tr>
                    <tr>
                        <td><strong>6 &#8212; Receipts / email</strong></td>
                        <td>Fiscal QR block on invoice PDF, auto-resend on acceptance</td>
                        <td><code>config/invoice.php</code>, <code>admin/stock-receipt.php</code></td>
                        <td>No</td>
                    </tr>
                    <tr>
                        <td><strong>7 &#8212; POS hardening</strong></td>
                        <td>Status badges, shift-close guard, manual re-submit button</td>
                        <td><code>admin/stock-orders.php</code>, <code>admin/pos.php</code>, <code>admin/payment-details.php</code>, <code>admin/booking-details.php</code></td>
                        <td>No</td>
                    </tr>
                    <tr>
                        <td><strong>8 &#8212; Go-live</strong></td>
                        <td>Sandbox shadow day &#8594; MRA approval &#8594; production switch &#8594; 48h monitoring</td>
                        <td>Server crontab, <code>.env</code>, <code>site_settings</code></td>
                        <td>No</td>
                    </tr>
                </tbody>
            </table>

            <div class="phase-strip">
                <div class="phase-chip"><span class="num">1</span> DB Schema</div>
                <div class="phase-chip"><span class="num">2</span> Settings</div>
                <div class="phase-chip"><span class="num">3</span> API Client</div>
                <div class="phase-chip"><span class="num">4</span> Hook Points</div>
                <div class="phase-chip"><span class="num">5</span> Queue &amp; Retry</div>
                <div class="phase-chip"><span class="num">6</span> Receipts</div>
                <div class="phase-chip"><span class="num">7</span> POS Hardening</div>
                <div class="phase-chip"><span class="num">8</span> Go-Live</div>
            </div>
        </div>
    </section>

    <footer>
        <div class="deck">
            <div class="crest">— MRA EIS —</div>
            <p>MRA Integration Rollout Guide &middot; <?= htmlspecialchars($siteName, ENT_QUOTES, 'UTF-8') ?></p>
            <p>Generated <?= $generated ?></p>
            <div class="sig">Built on the existing hotel PMS &#8212; nothing replaced, only extended.</div>
        </div>
    </footer>

    <script defer>
        document.addEventListener('click', function(e) {
            var toc = document.getElementById('toc');
            if (!toc) return;
            if (toc.classList.contains('open') &&
                !toc.contains(e.target) &&
                !e.target.closest('.toc-btn')) {
                toc.classList.remove('open');
            }
        });
    </script>
</body>

</html>
