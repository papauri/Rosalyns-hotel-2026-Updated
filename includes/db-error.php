<?php
// Try to get site name from environment or use generic fallback
$siteName = getenv('SITE_NAME') ?: "Hotel Management System";
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=5.0, user-scalable=yes, viewport-fit=cover">
    <title>We'll Be Right Back — <?php echo htmlspecialchars($siteName); ?></title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Cormorant+Garamond:ital,wght@0,400;0,600;1,400&family=Jost:wght@300;400;500&display=swap" rel="stylesheet">
    <style>
        *, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }

        :root {
            --color-background: #F7F3EE;
            --color-primary: #8A775F;
            --color-lux-gold: #B18247;
            --color-lux-ink: #231F1C;
            --color-clay-50: #F3ECE4;
            --color-text-primary: #2A2723;
            --color-text-secondary: #5E554D;
            --color-border: #DDD5C8;
        }

        html, body {
            height: 100%;
        }

        body {
            background-color: var(--color-background);
            font-family: 'Jost', sans-serif;
            color: var(--color-text-primary);
            display: flex;
            align-items: center;
            justify-content: center;
            min-height: 100vh;
            padding: 2rem;
        }

        .dbe-wrap {
            max-width: 560px;
            width: 100%;
            text-align: center;
            animation: dbe-fade-up 0.8s ease both;
        }

        @keyframes dbe-fade-up {
            from { opacity: 0; transform: translateY(24px); }
            to   { opacity: 1; transform: translateY(0); }
        }

        /* ── Ornament line ── */
        .dbe-ornament {
            display: flex;
            align-items: center;
            gap: 1rem;
            margin-bottom: 2.5rem;
        }
        .dbe-ornament::before,
        .dbe-ornament::after {
            content: '';
            flex: 1;
            height: 1px;
            background: var(--color-border);
        }
        .dbe-ornament-diamond {
            width: 8px;
            height: 8px;
            background: var(--color-lux-gold);
            transform: rotate(45deg);
            flex-shrink: 0;
        }

        /* ── Bear illustration ── */
        .dbe-bear-wrap {
            position: relative;
            display: inline-block;
            margin-bottom: 2rem;
        }

        .dbe-bear {
            width: clamp(100px, 20vw, 140px);
            height: auto;
            filter: drop-shadow(0 8px 24px rgba(138,119,95,0.18));
        }

        .dbe-zzz {
            position: absolute;
            top: -8px;
            right: -20px;
            font-family: 'Cormorant Garamond', serif;
            font-style: italic;
            font-size: clamp(1rem, 2.5vw, 1.3rem);
            color: var(--color-lux-gold);
            letter-spacing: 0.05em;
            animation: dbe-float 2.8s ease-in-out infinite;
        }

        @keyframes dbe-float {
            0%, 100% { transform: translateY(0) rotate(-5deg); opacity: 0.7; }
            50%       { transform: translateY(-8px) rotate(5deg); opacity: 1; }
        }

        /* ── Typography ── */
        .dbe-label {
            font-family: 'Jost', sans-serif;
            font-size: clamp(0.65rem, 1.5vw, 0.75rem);
            font-weight: 500;
            letter-spacing: 0.18em;
            text-transform: uppercase;
            color: var(--color-lux-gold);
            margin-bottom: 0.75rem;
        }

        .dbe-title {
            font-family: 'Cormorant Garamond', serif;
            font-size: clamp(2rem, 5vw, 2.8rem);
            font-weight: 400;
            line-height: 1.2;
            letter-spacing: 0.02em;
            color: var(--color-lux-ink);
            margin-bottom: 1.25rem;
        }

        .dbe-desc {
            font-family: 'Jost', sans-serif;
            font-size: clamp(0.875rem, 2vw, 1rem);
            font-weight: 300;
            line-height: 1.7;
            color: var(--color-text-secondary);
            margin-bottom: 2.5rem;
        }

        /* ── Divider ── */
        .dbe-divider {
            width: 40px;
            height: 1px;
            background: var(--color-lux-gold);
            margin: 0 auto 2.5rem;
        }

        /* ── Retry button ── */
        .dbe-btn {
            display: inline-flex;
            align-items: center;
            gap: 0.5rem;
            font-family: 'Jost', sans-serif;
            font-size: 0.8rem;
            font-weight: 500;
            letter-spacing: 0.14em;
            text-transform: uppercase;
            color: var(--color-primary);
            text-decoration: none;
            border: 1px solid var(--color-border);
            background: transparent;
            padding: 0.75rem 2rem;
            cursor: pointer;
            transition: background 0.25s, border-color 0.25s, color 0.25s;
            margin-bottom: 2rem;
        }
        .dbe-btn:hover {
            background: var(--color-clay-50);
            border-color: var(--color-primary);
            color: var(--color-lux-ink);
        }

        /* ── Countdown ── */
        .dbe-countdown {
            font-family: 'Jost', sans-serif;
            font-size: 0.78rem;
            font-weight: 300;
            color: var(--color-text-secondary);
            letter-spacing: 0.04em;
        }
        .dbe-countdown strong {
            font-weight: 500;
            color: var(--color-primary);
        }

        /* ── Footer note ── */
        .dbe-footer {
            margin-top: 3rem;
            padding-top: 1.5rem;
            border-top: 1px solid var(--color-border);
            font-family: 'Jost', sans-serif;
            font-size: 0.78rem;
            font-weight: 300;
            color: var(--color-text-secondary);
            letter-spacing: 0.03em;
        }
    </style>
</head>
<body>
    <div class="dbe-wrap">

        <div class="dbe-ornament"><div class="dbe-ornament-diamond"></div></div>

        <div class="dbe-bear-wrap">
            <svg class="dbe-bear" viewBox="0 0 140 100" fill="none" xmlns="http://www.w3.org/2000/svg">
                <!-- shadow -->
                <ellipse cx="70" cy="94" rx="42" ry="6" fill="#DDD5C8" opacity="0.7"/>
                <!-- body -->
                <ellipse cx="70" cy="62" rx="36" ry="30" fill="#C4B8A8"/>
                <!-- left ear -->
                <ellipse cx="40" cy="36" rx="11" ry="10" fill="#B5A898"/>
                <ellipse cx="40" cy="36" rx="7" ry="6.5" fill="#D4C5B8"/>
                <!-- right ear -->
                <ellipse cx="100" cy="36" rx="11" ry="10" fill="#B5A898"/>
                <ellipse cx="100" cy="36" rx="7" ry="6.5" fill="#D4C5B8"/>
                <!-- snout -->
                <ellipse cx="70" cy="74" rx="18" ry="12" fill="#E8E0D6"/>
                <!-- nose -->
                <ellipse cx="70" cy="67" rx="5" ry="3.5" fill="#7A6A5A"/>
                <!-- nostrils -->
                <ellipse cx="67.5" cy="68.5" rx="1.5" ry="1" fill="#5A4A3A"/>
                <ellipse cx="72.5" cy="68.5" rx="1.5" ry="1" fill="#5A4A3A"/>
                <!-- closed left eye (sleeping arc) -->
                <path d="M55 52 Q58 48 61 52" stroke="#7A6A5A" stroke-width="2" stroke-linecap="round" fill="none"/>
                <!-- closed right eye (sleeping arc) -->
                <path d="M79 52 Q82 48 85 52" stroke="#7A6A5A" stroke-width="2" stroke-linecap="round" fill="none"/>
                <!-- smile -->
                <path d="M63 77 Q70 82 77 77" stroke="#9A8A7A" stroke-width="1.5" stroke-linecap="round" fill="none"/>
                <!-- left paw -->
                <ellipse cx="38" cy="86" rx="14" ry="8" fill="#B5A898"/>
                <!-- right paw -->
                <ellipse cx="102" cy="86" rx="14" ry="8" fill="#B5A898"/>
            </svg>
            <span class="dbe-zzz">Zzz…</span>
        </div>

        <p class="dbe-label">Service Interruption</p>
        <h1 class="dbe-title">We'll Be Right Back</h1>
        <div class="dbe-divider"></div>
        <p class="dbe-desc">
            We're performing some maintenance on our end.<br>
            We'll be back shortly — thank you for your patience.
        </p>

        <button class="dbe-btn" onclick="location.reload()">
            <svg width="13" height="13" viewBox="0 0 16 16" fill="currentColor" xmlns="http://www.w3.org/2000/svg">
                <path d="M13.65 2.35A8 8 0 1 0 15 8h-2a6 6 0 1 1-1.05-3.41L10 6h5V1l-1.35 1.35z"/>
            </svg>
            Try Again
        </button>

        <p class="dbe-countdown">Auto-refreshing in <strong id="dbe-timer">30</strong>s</p>

        <div class="dbe-footer">
            <?php echo htmlspecialchars($siteName); ?> &nbsp;·&nbsp; For urgent assistance call the front desk
        </div>

    </div>

    <script>
        <?php if (!empty($dbDebug) && isset($errorMsg)): ?>
        console.error('DB Error: <?php echo addslashes($errorMsg); ?>');
        <?php endif; ?>

        (function () {
            var secs = 30;
            var el = document.getElementById('dbe-timer');
            var iv = setInterval(function () {
                secs--;
                if (el) el.textContent = secs;
                if (secs <= 0) { clearInterval(iv); location.reload(); }
            }, 1000);
        })();
    </script>
</body>
</html>
