<?php

/**
 * offline.php — PWA offline fallback page.
 *
 * Served by public-sw.js when a navigation request fails and no cached page
 * exists. Must be completely self-contained: no DB, no external includes,
 * no assets that may not be cached. Inline CSS only.
 */
http_response_code(503);
header('Content-Type: text/html; charset=utf-8');
header('Cache-Control: no-store');
?>
<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
    <meta name="robots" content="noindex, nofollow">
    <title>You're Offline</title>
    <style>
        /* @import MUST be the first statement inside a <style> block.
           Previously this was misplaced after :root{} which caused browsers
           to silently ignore the import and fall back to system fonts. */
        @import url('https://fonts.googleapis.com/css2?family=Cormorant+Garamond:ital,wght@0,400;0,600;1,400&family=Jost:wght@300;400;500;600&display=swap');

        *,
        *::before,
        *::after {
            box-sizing: border-box;
            margin: 0;
            padding: 0;
        }

        :root {
            --color-primary: #8A775F;
            --color-lux-ink: #231F1C;
            --color-clay-50: #F3ECE4;
            --color-background: #F7F3EE;
            --color-text: #2A2723;
            --color-muted: #5E554D;
        }

        html,
        body {
            min-height: 100%;
            background: var(--color-background);
            color: var(--color-text);
            font-family: "Jost", system-ui, -apple-system, sans-serif;
            font-weight: 300;
            -webkit-font-smoothing: antialiased;
        }

        body {
            display: flex;
            flex-direction: column;
            align-items: center;
            justify-content: center;
            min-height: 100dvh;
            padding: clamp(24px, 6vw, 64px) clamp(20px, 5vw, 40px);
            text-align: center;
        }

        .offline-wrap {
            max-width: 480px;
            width: 100%;
        }

        .offline-icon {
            width: clamp(56px, 14vw, 80px);
            height: clamp(56px, 14vw, 80px);
            border-radius: 50%;
            background: var(--color-clay-50);
            display: flex;
            align-items: center;
            justify-content: center;
            margin: 0 auto clamp(24px, 5vw, 36px);
        }

        .offline-icon svg {
            width: clamp(28px, 7vw, 40px);
            height: clamp(28px, 7vw, 40px);
            stroke: var(--color-primary);
            fill: none;
            stroke-width: 1.5;
            stroke-linecap: round;
            stroke-linejoin: round;
        }

        .offline-label {
            font-family: "Jost", system-ui, sans-serif;
            font-size: clamp(10px, 2.2vw, 12px);
            font-weight: 500;
            letter-spacing: 0.14em;
            text-transform: uppercase;
            color: var(--color-primary);
            margin-bottom: 14px;
        }

        .offline-heading {
            font-family: "Cormorant Garamond", Georgia, serif;
            font-size: clamp(2rem, 6vw, 3rem);
            font-weight: 400;
            line-height: 1.15;
            color: var(--color-lux-ink);
            margin-bottom: clamp(14px, 3vw, 20px);
        }

        .offline-body {
            font-size: clamp(14px, 3.5vw, 16px);
            color: var(--color-muted);
            line-height: 1.7;
            margin-bottom: clamp(28px, 6vw, 44px);
        }

        .offline-btn {
            display: inline-flex;
            align-items: center;
            gap: 10px;
            background: var(--color-primary);
            color: #fff;
            border: none;
            border-radius: 4px;
            padding: 14px 32px;
            font-family: "Jost", system-ui, sans-serif;
            font-size: 14px;
            font-weight: 500;
            letter-spacing: 0.06em;
            cursor: pointer;
            text-decoration: none;
            transition: opacity 0.2s;
            min-height: 48px;
        }

        .offline-btn:hover {
            opacity: 0.82;
        }

        .offline-divider {
            width: 40px;
            height: 1px;
            background: var(--color-primary);
            opacity: 0.35;
            margin: clamp(28px, 6vw, 44px) auto 0;
        }

        .offline-tip {
            margin-top: 18px;
            font-size: 12px;
            color: var(--color-muted);
            opacity: 0.7;
        }

        @media (prefers-reduced-motion: no-preference) {
            .offline-wrap {
                animation: fadeUp 0.45s ease both;
            }

            @keyframes fadeUp {
                from {
                    opacity: 0;
                    transform: translateY(18px);
                }

                to {
                    opacity: 1;
                    transform: translateY(0);
                }
            }
        }
    </style>
</head>

<body>
    <div class="offline-wrap">
        <div class="offline-icon" aria-hidden="true">
            <!-- WiFi-off icon (inline SVG — no external dep) -->
            <svg viewBox="0 0 24 24" aria-hidden="true">
                <line x1="1" y1="1" x2="23" y2="23" />
                <path d="M16.72 11.06A10.94 10.94 0 0 1 19 12.55" />
                <path d="M5 12.55a10.94 10.94 0 0 1 5.17-2.39" />
                <path d="M10.71 5.05A16 16 0 0 1 22.56 9" />
                <path d="M1.42 9a15.91 15.91 0 0 1 4.7-2.88" />
                <path d="M8.53 16.11a6 6 0 0 1 6.95 0" />
                <circle cx="12" cy="20" r="1" fill="currentColor" stroke="none"
                    style="fill:var(--color-primary)" />
            </svg>
        </div>

        <p class="offline-label">No Connection</p>

        <h1 class="offline-heading">You&rsquo;re&nbsp;offline</h1>

        <p class="offline-body">
            It looks like you&rsquo;ve lost your internet connection.<br>
            Check your network and try again.
        </p>

        <button class="offline-btn" onclick="window.location.reload()" type="button">
            <svg width="16" height="16" viewBox="0 0 24 24" fill="none"
                stroke="currentColor" stroke-width="2" stroke-linecap="round"
                stroke-linejoin="round" aria-hidden="true">
                <polyline points="23 4 23 10 17 10" />
                <path d="M20.49 15a9 9 0 1 1-2.12-9.36L23 10" />
            </svg>
            Try Again
        </button>

        <div class="offline-divider" aria-hidden="true"></div>
        <p class="offline-tip">Previously visited pages may still be available.</p>
    </div>
</body>

</html>
