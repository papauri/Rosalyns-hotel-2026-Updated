/**
 * Unified Navigation System
 * Rosalyn's Hotel 2026
 *
 * Full SPA routing: clicks on internal nav links swap only
 * content between <header> and <footer> — The header
 * never reloads, transitions are seamless.
 *
 * Key behaviours:
 *  • Wraps content between header and footer in #spa-content
 *  • Intercepts clicks on SPA-allowed pages, fetches via api/page-content.php
 *  • Fades old content out, swaps innerHTML, fades new content in
 *  • Re-runs inline <script> tags found in new content
 *  • Updates active nav state in both desktop and mobile menus
 *  • Handles browser back/forward via popstate
 *  • Singleton guard — safe when loaded from both page footer AND individual pages
 *  • Global Click Delegation for Mobile Menu Toggle — Ensures consistent
 *     behavior across all pages regardless of DOM structure changes.
 */

(function () {
    'use strict';

    // ── Singleton guard ──────────────────────────────────────────────────────
    if (window._unifiedNavLoaded) return;
    window._unifiedNavLoaded = true;

    // ── Constants ────────────────────────────────────────────────────────────
    const SPA_PAGES = [
        'index',
        'rooms-gallery',
        'rooms-showcase',
        'room',
        'restaurant',
        'events',
        'gym',
        'conference',
    ];

    const EXCLUDED_PAGES = [
        'admin',
        'booking',
        'check-availability',
        'booking-confirmation',
        'booking-lookup',
        'submit-review',
        'review-confirmation',
        'privacy-policy',
        'menu-pdf',
        'generate-sitemap',
        'robots',
    ];

    // ── Base path (supports subdirectory installs, e.g. /rosalyns-hotel/) ───
    // Derives the path prefix from this script's absolute src URL so that API
    // calls and relative-path fixes work whether the site is at / or /subdir/.
    const _BASE_PATH = (() => {
        const el = document.querySelector('script[src*="navigation-unified"]');
        if (!el || !el.src) return '/';
        const m = el.src.match(/^https?:\/\/[^/]+(\/.*?)(?:\/js\/navigation-unified)/);
        return m ? m[1] + '/' : '/';
    })();

    // ── Class ────────────────────────────────────────────────────────────────
    class UnifiedNavigation {
        constructor() {
            this.spaWrapper = null;   // <div id="spa-content"> between header & footer
            this.isLoading = false;
            this.currentPage = this._pageName(window.location.pathname);
            this._init();
        }

        /* ================================================================
           Bootstrap
        ================================================================ */

        _init() {
            if (document.readyState === 'loading') {
                document.addEventListener('DOMContentLoaded', () => this._setup());
            } else {
                this._setup();
            }
        }

        _setup() {
            this._buildSPAWrapper();
            this._setupMobileMenu();
            this._setupGlobalMobileMenu();
            this._attachLinkListeners();

            window.addEventListener('popstate', e => this._onPopState(e));
            window.addEventListener('pageshow', e => { if (e.persisted) this._hideLoader(); });

            history.replaceState({ page: this.currentPage }, '', window.location.href);
        }

        /* ================================================================
           SPA wrapper
           Wraps all nodes between <header.header> and <footer.footer>
           so that swapping only those nodes leaves the header untouched.
        ================================================================ */

        _buildSPAWrapper() {
            // Already exists (e.g. double-include)
            const existing = document.getElementById('spa-content');
            if (existing) {
                const header = document.querySelector('header.header');
                const footer = document.querySelector('footer.footer');

                // Ensure mobile menu overlay/panel are not inside SPA-swapped content.
                const mobileOverlay = existing.querySelector('[data-mobile-overlay]');
                const mobilePanel = existing.querySelector('#mobile-menu');
                if (header && mobileOverlay) {
                    header.after(mobileOverlay);
                }
                if (header && mobilePanel) {
                    const anchor = document.querySelector('[data-mobile-overlay]') || header;
                    anchor.after(mobilePanel);
                }

                // Keep wrapper after header + mobile menu elements
                if (header) {
                    const wrapperAnchor = document.getElementById('mobile-menu') || document.querySelector('[data-mobile-overlay]') || header;
                    wrapperAnchor.after(existing);
                }

                // Re-ensure footer remains after SPA wrapper
                if (footer && existing.contains(footer)) {
                    existing.after(footer);
                }

                this.spaWrapper = existing;
                return true;
            }

            const header = document.querySelector('header.header');
            const footer = document.querySelector('footer.footer');
            if (!header || !footer) {
                return false;
            }

            // Gather sibling nodes that sit between header and footer
            const nodes = [];
            let node = header.nextSibling;
            while (node && node !== footer) {
                const next = node.nextSibling;

                // Keep mobile menu shell outside SPA-swapped content so it persists.
                if (node.nodeType === Node.ELEMENT_NODE) {
                    const element = node;
                    if (element.matches('[data-mobile-overlay]') || element.id === 'mobile-menu') {
                        node = next;
                        continue;
                    }
                }

                nodes.push(node);
                node = next;
            }

            // Create wrapper after header/mobile menu shell, then move content nodes into it
            const wrapper = document.createElement('div');
            wrapper.id = 'spa-content';
            const wrapperAnchor = document.getElementById('mobile-menu') || document.querySelector('[data-mobile-overlay]') || header;
            wrapperAnchor.after(wrapper);
            nodes.forEach(n => wrapper.appendChild(n));

            // CRITICAL: Ensure footer is placed AFTER of wrapper
            // This prevents the footer from being lost during navigation
            if (footer.parentNode !== document.body) {
                document.body.appendChild(footer);
            }
            wrapper.after(footer);

            // Make the wrapper layout-transparent so CSS that targets
            // body > main, body > section, etc. keeps working
            const style = document.createElement('style');
            style.textContent = '#spa-content { display: contents; }';
            document.head.appendChild(style);

            this.spaWrapper = wrapper;
            return true;
        }

        /* ================================================================
           Mobile menu
        ================================================================ */

        _setupMobileMenu() {
            // Global Escape key (only bind once)
            if (!this._escapeKeyBound) {
                this._escapeKeyBound = true;
                document.addEventListener('keydown', e => {
                    if (e.key === 'Escape') this._closeMobileMenu();
                });
            }

            // Bind toggle button and dynamic elements
            // Note: We also use global event delegation (see _setupGlobalMobileMenu) to ensure
            // the toggle works even if this re-binding fails.
            this._bindMobileMenuDynamicElements();
        }

        _bindMobileMenuDynamicElements() {
            const closeBtn = document.querySelector('[data-mobile-close]');
            const overlay = document.querySelector('[data-mobile-overlay]');

            // Bind close button (in mobile panel, which IS inside SPA wrapper - needs re-binding)
            if (closeBtn) {
                closeBtn.onclick = (e) => {
                    e.preventDefault();
                    e.stopPropagation();
                    this._closeMobileMenu();
                };
            }

            // Bind overlay (in mobile panel, which IS inside SPA wrapper - needs re-binding)
            if (overlay) {
                overlay.onclick = (e) => {
                    e.preventDefault();
                    e.stopPropagation();
                    this._closeMobileMenu();
                };
            }
        }

        /* ================================================================
           Global Mobile Menu Handling
           Uses event delegation to ensure the mobile menu toggle works
           consistently across ALL pages, even after SPA content swaps.
           This is the most robust solution.
        ================================================================ */

        _setupGlobalMobileMenu() {
            if (this._globalMobileMenuBound) return;
            this._globalMobileMenuBound = true;

            document.addEventListener('click', function (e) {
                // Mobile Toggle Button
                if (e.target.closest('[data-mobile-toggle]')) {
                    e.preventDefault();
                    e.stopPropagation();
                    const btn = e.target.closest('[data-mobile-toggle]');
                    const isExpanded = btn.getAttribute('aria-expanded') === 'true';
                    if (isExpanded) {
                        window.unifiedNav._closeMobileMenu();
                    } else {
                        window.unifiedNav._openMobileMenu();
                    }
                }

                // Mobile Overlay - Close menu when clicking overlay
                if (e.target.closest('[data-mobile-overlay]')) {
                    e.preventDefault();
                    e.stopPropagation();
                    window.unifiedNav._closeMobileMenu();
                }

                // Mobile Close Button
                if (e.target.closest('[data-mobile-close]')) {
                    e.preventDefault();
                    e.stopPropagation();
                    window.unifiedNav._closeMobileMenu();
                }
            }, true); // Use capture phase to ensure we intercept before specific element handlers
        }

        _openMobileMenu() {
            const panel = document.getElementById('mobile-menu');
            const overlay = document.querySelector('[data-mobile-overlay]');
            const toggleBtn = document.querySelector('[data-mobile-toggle]');

            if (panel) panel.classList.add('header__mobile--active');
            if (overlay) overlay.classList.add('header__overlay--active');
            if (toggleBtn) toggleBtn.setAttribute('aria-expanded', 'true');
            document.body.style.overflow = 'hidden';
        }

        _closeMobileMenu() {
            const panel = document.getElementById('mobile-menu');
            const overlay = document.querySelector('[data-mobile-overlay]');
            const toggleBtn = document.querySelector('[data-mobile-toggle]');

            if (panel) panel.classList.remove('header__mobile--active');
            if (overlay) overlay.classList.remove('header__overlay--active');
            if (toggleBtn) toggleBtn.setAttribute('aria-expanded', 'false');
            document.body.style.overflow = '';
        }

        /* ================================================================
           Link interception
        ================================================================ */

        _attachLinkListeners() {
            // Capture phase so we get the click before any other handler
            document.addEventListener('click', e => this._onLinkClick(e), true);

            // Add non-SPA loader trigger for internal links
            document.addEventListener('click', e => this._onNonSPALinkClick(e), true);
        }

        _onLinkClick(e) {
            const link = e.target.closest('a');
            if (!link) return;

            const href = link.getAttribute('href');

            // Skip non-navigation hrefs
            if (!href || href === '' ||
                href.startsWith('#') ||
                href.startsWith('javascript:') ||
                href.startsWith('mailto:') ||
                href.startsWith('tel:')) return;

            // Skip external / new-tab / download
            if (this._isExternal(href)) return;
            if (link.target === '_blank' || link.target === '_parent') return;
            if (link.hasAttribute('download')) return;
            if (link.hasAttribute('data-no-spa')) return;

            // Skip admin links
            if (href.includes('/admin') || href.includes('admin/')) return;

            // Only intercept SPA-allowed pages
            const pageName = this._pageName(href);
            if (!this._isSPA(pageName)) return;

            // Build absolute URL and normalize (strip any /api/ prefix)
            let url;
            try {
                // Resolve relative hrefs against the current page URL (not just origin)
                // so subdirectory installs like /rosalyns-hotel/ are preserved.
                url = new URL(href, window.location.href).href;
                // Strip /api/ prefix if accidentally present (e.g., /api/events.php → /events.php)
                url = url.replace(/\/api\/([a-z0-9_-]+\.php)/i, `${_BASE_PATH}$1`);
            }
            catch { url = href; }

            // Don't re-navigate to the same page (without params)
            const samePath = window.location.href.split('?')[0] === url.split('?')[0];
            if (samePath && !href.includes('?')) return;

            e.preventDefault();
            e.stopPropagation();

            // Close mobile menu if open
            this._closeMobileMenu();

            this._navigate(url, pageName);
        }

        /* ================================================================
           Non-SPA link loader trigger
           Shows loader for internal links that navigate to excluded pages
           (booking, admin, etc.) where SPA doesn't handle navigation
        ================================================================ */

        _onNonSPALinkClick(e) {
            // Skip if already handled by SPA
            if (e.defaultPrevented) return;

            const link = e.target.closest('a');
            if (!link) return;

            const href = link.getAttribute('href');

            // Skip non-navigation hrefs
            if (!href || href === '' ||
                href.startsWith('#') ||
                href.startsWith('javascript:') ||
                href.startsWith('mailto:') ||
                href.startsWith('tel:')) return;

            // Skip external / new-tab / download
            if (this._isExternal(href)) return;
            if (link.target === '_blank' || link.target === '_parent') return;
            if (link.hasAttribute('download')) return;
            if (link.hasAttribute('data-no-loader')) return;

            // Check if this is an internal link to an excluded page
            const pageName = this._pageName(href);
            if (this._isSPA(pageName)) return; // SPA handles these

            // Check if it's a same-origin internal link
            try {
                const url = new URL(href, window.location.origin);
                if (url.hostname === window.location.hostname) {
                    // This is an internal link to a non-SPA page
                    // Show loader before navigation with destination page subtext
                    this._showLoader(pageName);
                }
            } catch {
                // Invalid URL, skip
            }
        }

        /* ================================================================
           SPA navigation
        ================================================================ */

        async _navigate(url, pageName) {
            if (this.isLoading) return;
            this.isLoading = true;

            window.dispatchEvent(new CustomEvent('page:navigation:start', {
                detail: { url, page: pageName, spa: true }
            }));
            document.body.classList.add('page-loading');
            this._showLoader(pageName); // Pass destination page for correct subtext

            try {
                // Build API URL - use _BASE_PATH to support subdirectory installs
                let apiUrl = `${_BASE_PATH}api/page-content.php?page=${encodeURIComponent(pageName)}`;
                if (pageName === 'room') {
                    const slug = new URL(url, window.location.origin).searchParams.get('room');
                    if (slug) apiUrl += `&slug=${encodeURIComponent(slug)}`;
                }

                const res = await fetch(apiUrl);
                if (!res.ok) throw new Error(`HTTP ${res.status}`);

                const data = await res.json();
                if (data.error || !data.html) throw new Error(data.error || 'Empty response');

                // Swap content
                await this._swap(data);

                // Update browser history
                history.pushState({ page: pageName }, data.title || '', url);
                this.currentPage = pageName;

                this._updateActiveNav(url);
                this._reinit();

                window.dispatchEvent(new CustomEvent('page:navigation:end', {
                    detail: { url, page: pageName, spa: true }
                }));

                // Scroll to hash anchor if present, otherwise scroll to top
                const hash = new URL(url, window.location.origin).hash;
                if (hash) {
                    // Small delay so reinit/animations have settled before scrolling
                    setTimeout(() => {
                        const target = document.querySelector(hash);
                        if (target) {
                            const headerOffset = 80;
                            const top = target.getBoundingClientRect().top + window.pageYOffset - headerOffset;
                            window.scrollTo({ top, behavior: 'smooth' });
                        }
                    }, 100);
                } else {
                    window.scrollTo({ top: 0, behavior: 'smooth' });
                }

            } catch (err) {
                this._hideLoader();
                window.dispatchEvent(new CustomEvent('page:navigation:end', {
                    detail: { url, page: pageName, error: true }
                }));
                setTimeout(() => { window.location.href = url; }, 80);

            } finally {
                this.isLoading = false;
                setTimeout(() => document.body.classList.remove('page-loading'), 500);
            }
        }

        /* ================================================================
           Content swap with fade transition
           Preloads hero image before fading in so that the hero never
           appears after the rest of the content.
        ================================================================ */

        async _swap(data) {
            const target = this.spaWrapper || document.querySelector('main');
            if (!target) return;

            // ── 1. Fade out ───────────────────────────────────────
            target.style.transition = 'opacity 0.22s cubic-bezier(0.4, 0, 0.2, 1)';
            target.style.opacity = '0';
            await new Promise(r => setTimeout(r, 230));

            // ── 2. Fix relative paths in HTML before injecting ───────────
            // Convert relative paths to absolute to avoid /api/ prefix issues
            const fixedHtml = this._fixRelativePaths(data.html);

            // Inject fixed markup
            target.innerHTML = fixedHtml;
            if (data.title) document.title = data.title;

            // Re-execute inline <script> tags
            // (innerHTML assignment does NOT run scripts automatically)
            target.querySelectorAll('script').forEach(old => {
                const src = old.getAttribute('src');

                // Skip scripts without src and without content (empty scripts)
                if (!src && !old.textContent.trim()) {
                    return;
                }

                const fresh = document.createElement('script');

                // Copy all attributes
                Array.from(old.attributes).forEach(a => fresh.setAttribute(a.name, a.value));

                // For inline scripts (no src), wrap in try-catch to avoid breaking on re-declaration
                if (!src) {
                    fresh.textContent = `(function() { try { ${old.textContent} } catch(e) { /* Silently ignore re-declaration errors */ } })();`;
                }

                old.parentNode.replaceChild(fresh, old);
            });

            // ── 3. Preload hero image BEFORE revealing content ─────────────
            // This ensures that the hero image is ready so it never pops in late.
            await this._preloadHeroImage(target);

            // ── 4. Fade in ────────────────────────────────────────────────
            target.style.transition = 'opacity 0.32s cubic-bezier(0.16, 1, 0.3, 1)';
            target.style.opacity = '1';
            await new Promise(r => setTimeout(r, 340));

            this._hideLoader();
        }

        /* ================================================================
           Preload the hero image found in the newly injected content.
           Sets loading="eager" + fetchpriority="high" and waits for
           the image to decode before the caller fades content in.
           Has a 2.5 s safety timeout so slow networks never hang the UI.
        ================================================================ */

        _preloadHeroImage(container) {
            // Find the hero <img> — hero.php always puts it inside .hero__media
            const heroImg = container.querySelector('.hero__media img, img.hero__image');
            if (!heroImg) return Promise.resolve();

            // Force browser to treat it as a high-priority resource
            heroImg.loading = 'eager';
            try { heroImg.fetchPriority = 'high'; } catch (_) { /* Safari <16 */ }

            // If the browser has it cached it's already complete
            if (heroImg.complete && heroImg.naturalWidth > 0) return Promise.resolve();

            // Kick off loading of best available source from the <picture> srcset
            const src = heroImg.currentSrc || heroImg.getAttribute('src');
            if (!src) return Promise.resolve();

            return new Promise(resolve => {
                const done = () => { clearTimeout(timer); resolve(); };
                const timer = setTimeout(resolve, 2500); // safety net
                heroImg.addEventListener('load', done, { once: true });
                heroImg.addEventListener('error', done, { once: true });
                // Trigger load if not already started
                if (!heroImg.src) heroImg.src = src;
            });
        }

        /* ================================================================
           Active nav state
        ================================================================ */

        _updateActiveNav(url) {
            const pageName = this._pageName(url);

            // Desktop links
            document.querySelectorAll('.header__menu-link').forEach(link => {
                const lp = this._pageName(link.getAttribute('href') || '');
                const active = lp === pageName || (pageName === 'room' && lp === 'rooms-gallery');
                link.classList.toggle('header__menu-link--active', active);
            });

            // Mobile links (correct class: header__mobile-link, not header__mobile-menu-link)
            document.querySelectorAll('.header__mobile-link:not(.header__mobile-link--cta)').forEach(link => {
                const lp = this._pageName(link.getAttribute('href') || '');
                const active = lp === pageName || (pageName === 'room' && lp === 'rooms-gallery');
                link.classList.toggle('header__mobile-link--active', active);
            });
        }

        /* ================================================================
           Re-initialise JS enhancements after content swap
        ================================================================ */

        _reinit() {
            // Fire event that page-transitions.js, scroll-reveal.js, etc. listen to
            window.dispatchEvent(new CustomEvent('spa:contentLoaded', {
                detail: { page: this.currentPage }
            }));

            // Re-bind mobile menu (toggle, panel, overlay, close btn)
            // Note: We also rely on the new global event delegation added in boot(), so this
            // re-binding is secondary safety, but the primary logic is global.
            this._setupMobileMenu();

            // Explicit re-init for known modules
            // Use try/catch to prevent any module errors from breaking SPA navigation
            try {
                window.Modal?.init?.();
                window.Enhancements?.init?.();
                window.ScrollLazyAnimations?.reinit?.();
                window.PageTransitions?.refresh?.();
            } catch (reinitErr) {
                // Silently ignore - SPA navigation has already succeeded
            }
        }

        /* ================================================================
           Browser back/forward
        ================================================================ */

        _onPopState() {
            const url = window.location.href;
            const pageName = this._pageName(url);
            if (this._isSPA(pageName)) {
                this._navigate(url, pageName);
            } else {
                // For non-SPA pages on back/forward, show loader with destination subtext
                this._showLoader(pageName);
                setTimeout(() => location.reload(), 50);
            }
        }

        /* ================================================================
           Loader helpers
        ================================================================ */

        _showLoader(destinationPage) {
            // Signal that navigation is in progress — prevents loader.php fallback timer from hiding it
            window._pageLoaderNavigating = true;
            const l = document.getElementById('page-loader');
            if (l) {
                // Update loader subtext to show destination page
                if (destinationPage && typeof window.updateLoaderSubtext === 'function') {
                    window.updateLoaderSubtext(destinationPage);
                }
                // Remove hidden state first
                l.classList.remove('loader--hidden', 'loader--hiding');
                // Add active state to trigger proper CSS transitions
                l.classList.add('loader--active');
                // Clear any inline styles that might interfere
                l.style.opacity = '';
                l.style.visibility = '';
            }
        }

        _hideLoader() {
            // Clear navigation flag so that page's fallback timer knows navigation ended
            window._pageLoaderNavigating = false;
            const l = document.getElementById('page-loader');
            if (l) {
                // First add hiding state for smooth transition
                l.classList.add('loader--hiding');
                l.classList.remove('loader--active');
                // Then add hidden after transition completes
                setTimeout(() => {
                    l.classList.add('loader--hidden');
                    l.classList.remove('loader--hiding');
                }, 500);
            }
        }

        /* ================================================================
           Helpers
        ================================================================ */

        /**
         * Fix relative paths in fetched HTML content.
         * Converts relative paths to absolute paths to avoid issues when
         * content is fetched from /api/ but assets are in root directories.
         * Also strips any accidental /api/ prefix from page links.
         */
        _fixRelativePaths(html) {
            // Create a temporary DOM element to parse HTML
            const temp = document.createElement('div');
            temp.innerHTML = html;

            // Fix src attributes (images, scripts, iframes, videos, sources)
            temp.querySelectorAll('[src]').forEach(el => {
                const src = el.getAttribute('src');
                if (src && !src.startsWith('/') && !src.startsWith('http') && !src.startsWith('data:') && !src.startsWith('blob:')) {
                    el.setAttribute('src', _BASE_PATH + src);
                }
            });

            // Fix href attributes on <a> and <link> tags
            temp.querySelectorAll('a[href], link[href]').forEach(el => {
                const href = el.getAttribute('href');
                let fixedHref = href;

                // Strip any accidental /api/ prefix from page links (e.g., /api/events.php → /events.php)
                if (fixedHref && fixedHref.match(/^\/api\/[a-z0-9_-]+\.php/i)) {
                    fixedHref = fixedHref.replace(/^\/api\//i, '/');
                    el.setAttribute('href', fixedHref);
                }

                // Only fix relative paths, skip absolute, protocol-relative, anchors, mailto, tel, javascript
                if (fixedHref && !fixedHref.startsWith('/') && !fixedHref.startsWith('http') &&
                    !fixedHref.startsWith('#') && !fixedHref.startsWith('mailto:') &&
                    !fixedHref.startsWith('tel:') && !fixedHref.startsWith('javascript:')) {
                    el.setAttribute('href', _BASE_PATH + fixedHref);
                }
            });

            // Fix srcset attributes on <img> and <source> tags
            temp.querySelectorAll('[srcset]').forEach(el => {
                const srcset = el.getAttribute('srcset');
                if (srcset && !srcset.startsWith('http')) {
                    // Parse srcset and fix each URL
                    const fixedSrcset = srcset.split(',').map(part => {
                        const [url, descriptor] = part.trim().split(/\s+/);
                        const fixedUrl = url.startsWith('/') || url.startsWith('http') ? url : _BASE_PATH + url;
                        return descriptor ? `${fixedUrl} ${descriptor}` : fixedUrl;
                    }).join(', ');
                    el.setAttribute('srcset', fixedSrcset);
                }
            });

            // Fix background-image in style attributes (for inline styles)
            temp.querySelectorAll('[style*="background-image"]').forEach(el => {
                const style = el.getAttribute('style');
                if (style) {
                    const fixedStyle = style.replace(
                        /background-image:\s*url\(['"]?(?!['"]?\/)(?!['"]?http)(?!['"]?data:)([^'")\s]+)['"]?\)/gi,
                        (_, p1) => `background-image: url(${_BASE_PATH}${p1})`
                    );
                    el.setAttribute('style', fixedStyle);
                }
            });

            return temp.innerHTML;
        }

        _pageName(url) {
            try {
                const obj = new URL(url, window.location.origin);
                let path = obj.pathname
                    .replace(/^\//, '')      // Remove leading slash
                    .replace(/\.php$/, '')   // Remove .php extension
                    .replace(/\/$/, '');      // Remove trailing slash

                // Handle room.php special case (with or without query params)
                if (url.includes('room.php') || path === 'room') return 'room';

                // Handle empty path or index as 'index'
                if (path === '' || path === 'index') return 'index';

                // Get the last path segment (handles paths like /some/page)
                return path.split('/').pop() || 'index';
            } catch {
                return 'index';
            }
        }

        _isSPA(pageName) {
            for (const ex of EXCLUDED_PAGES) { if (pageName.includes(ex)) return false; }
            return SPA_PAGES.includes(pageName);
        }

        _isExternal(href) {
            try { return new URL(href, window.location.origin).hostname !== window.location.hostname; }
            catch { return false; }
        }
    }

    // ── Boot ──────────────────────────────────────────────────────────────────
    function boot() {
        window.unifiedNav = new UnifiedNavigation();
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', boot);
    } else {
        boot();
    }

    window.UnifiedNavigation = UnifiedNavigation;

})();
