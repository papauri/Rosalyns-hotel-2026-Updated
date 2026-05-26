<!-- Cookie Consent Banner - Modern GDPR-style -->
<div id="cookieConsentBanner" class="cookie-banner cookie-banner--hidden" data-cookie-banner style="display:none;">
    <div class="cookie-banner__inner">
        <div class="cookie-banner__content">
            <div class="cookie-banner__icon">
                <svg width="32" height="32" viewBox="0 0 24 24" fill="none"><circle cx="12" cy="12" r="10" stroke="#8B7355" stroke-width="1.5"/><circle cx="8" cy="10" r="1.2" fill="#8B7355"/><circle cx="14" cy="8" r="1" fill="#8B7355"/><circle cx="10" cy="15" r="1.1" fill="#8B7355"/><circle cx="15" cy="13" r="0.9" fill="#8B7355"/><circle cx="6" cy="13" r="0.7" fill="#8B7355"/></svg>
            </div>
            <div class="cookie-banner__text">
                <h4>We Value Your Privacy</h4>
                <p>We use cookies and session tracking to enhance your browsing experience, analyse website traffic, and understand where our visitors come from. Your data helps us improve our services.
                    <a href="privacy-policy.php" class="cookie-banner__link">Read our Privacy Policy</a>
                </p>
            </div>
        </div>
        <div class="cookie-banner__actions">
            <button id="cookieAcceptAll" class="cookie-banner__btn cookie-banner__btn--accept" data-cookie-accept="all">
                <i class="fas fa-check"></i> Accept All
            </button>
            <button id="cookieEssentialOnly" class="cookie-banner__btn cookie-banner__btn--essential" data-cookie-accept="essential">
                Essential Only
            </button>
            <button id="cookieDecline" class="cookie-banner__btn cookie-banner__btn--decline" data-cookie-accept="decline">
                Decline
            </button>
        </div>
    </div>
</div>

<script>
(function() {
    'use strict';

    var COOKIE_NAME = 'cookie_consent';
    var STORAGE_KEY = 'cookie_consent';
    var COOKIE_DAYS = 365;
    var banner = document.getElementById('cookieConsentBanner');

    function getCookie(name) {
        var match = document.cookie.match(new RegExp('(^| )' + name + '=([^;]+)'));
        return match ? decodeURIComponent(match[2]) : null;
    }

    function setCookie(name, value, days) {
        var d = new Date();
        d.setTime(d.getTime() + (days * 24 * 60 * 60 * 1000));
        document.cookie = name + '=' + encodeURIComponent(value) + ';expires=' + d.toUTCString() + ';path=/;SameSite=Lax';
    }

    function getStoredConsent() {
        try {
            var value = localStorage.getItem(STORAGE_KEY);
            if (value === 'all' || value === 'essential' || value === 'declined') {
                return value;
            }
        } catch (e) {
            // localStorage may be unavailable; ignore safely
        }
        return null;
    }

    function setStoredConsent(value) {
        try {
            localStorage.setItem(STORAGE_KEY, value);
        } catch (e) {
            // localStorage may be unavailable; ignore safely
        }
    }

    function isValidConsent(value) {
        return value === 'all' || value === 'essential' || value === 'declined';
    }

    function showBanner() {
        if (!banner) return;
        banner.removeAttribute('aria-hidden');
        banner.style.display = 'block';
        // force reflow before class toggle for smooth transition
        void banner.offsetWidth;
        banner.classList.remove('cookie-banner--hidden');
    }

    function hideBanner() {
        if (banner) {
            banner.classList.add('cookie-banner--hidden');
            setTimeout(function() {
                banner.style.display = 'none';
                banner.setAttribute('aria-hidden', 'true');
            }, 350);
        }
    }

    function logConsent(level) {
        // Fire-and-forget consent log to server
        var xhr = new XMLHttpRequest();
        xhr.open('POST', (typeof siteBaseUrl !== 'undefined' ? siteBaseUrl : '') + 'api/cookie-consent.php', true);
        xhr.setRequestHeader('Content-Type', 'application/x-www-form-urlencoded');
        xhr.send('consent_level=' + encodeURIComponent(level));
    }

    // Check if consent already given (cookie first, then localStorage fallback)
    var existing = getCookie(COOKIE_NAME);
    var stored = getStoredConsent();
    var resolvedConsent = isValidConsent(existing) ? existing : (isValidConsent(stored) ? stored : null);

    if (resolvedConsent) {
        // Ensure both stores are synchronized for idempotent behavior
        setCookie(COOKIE_NAME, resolvedConsent, COOKIE_DAYS);
        setStoredConsent(resolvedConsent);
        hideBanner();
    } else {
        // Show banner after a short delay for better UX
        setTimeout(showBanner, 1500);
    }

    function persistConsent(level) {
        if (!isValidConsent(level)) return;
        setCookie(COOKIE_NAME, level, COOKIE_DAYS);
        setStoredConsent(level);
        logConsent(level);
        hideBanner();
    }

    // Accept All
    document.getElementById('cookieAcceptAll').addEventListener('click', function() {
        persistConsent('all');
    });

    // Essential Only
    document.getElementById('cookieEssentialOnly').addEventListener('click', function() {
        persistConsent('essential');
    });

    // Decline
    document.getElementById('cookieDecline').addEventListener('click', function() {
        persistConsent('declined');
    });

    // Animation is handled by CSS utilities/animations.css
})();
</script>
