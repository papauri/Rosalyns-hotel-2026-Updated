/**
 * admin-spa.js
 * Lightweight SPA router for the admin panel.
 *
 * - Intercepts clicks on admin links
 * - Fetches target pages via Fetch API (GET, same-origin)
 * - Swaps only the #rh-admin-page content area
 * - Admin header and sidebar always remain visible
 * - Injects missing CSS/JS from fetched <head> before swapping
 * - Re-executes inline scripts in new content
 * - Shows a top loading bar during navigation
 * - Handles browser history (back / forward)
 * - Falls back to full navigation on any error
 */
(function () {
    'use strict';

    /** Pages that always use full-page navigation (no SPA).
     *  module-settings.php is here deliberately: applying a preset changes the
     *  server-rendered nav/module state and reloads anyway, and its controls
     *  must be dependable on first render — a config page gains nothing from
     *  SPA swaps but inherits all their script re-execution edge cases. */
    var FULL_NAV = ['login.php', 'logout.php', 'kds.php', 'bds.php', 'cds.php', 'pos.php', 'module-settings.php'];

    var CONTENT_ID = 'rh-admin-page';
    var PAGINATION_NAV_SELECTOR = '[data-admin-pagination], .bookings-pagination, .pagination, .log-table-pagination, .receipts-pagination, .pagination-bar, .inv-pagination, [data-admin-auto-pagination-nav]';

    // ── Loading bar ──────────────────────────────────────────────────────────
    var _loader = null;
    var _loaderTimer = null;
    var _loaderShownAt = 0;
    var _loaderMinVisibleMs = 320;
    var _spaDocProxy = null; // document shim for re-executed inline scripts

    function _getLoaderBrandName() {
        var brand = document.querySelector('.admin-header-brand h1');
        if (brand && brand.textContent.trim()) return brand.textContent.trim();

        var navBrand = document.querySelector('.admin-nav-title-sub');
        if (navBrand && navBrand.textContent.trim()) return navBrand.textContent.trim();

        var title = document.title || '';
        if (title.indexOf('|') !== -1) {
            return title.split('|').slice(-1)[0].replace(/\s+Admin\s*$/i, '').trim() || 'Hotel Admin';
        }

        return 'Hotel Admin';
    }

    function _ensureOverlayLoader() {
        var loader = document.getElementById('adminPageLoader');
        if (loader) return loader;

        loader = document.createElement('div');
        loader.id = 'adminPageLoader';
        loader.className = 'admin-page-loader';
        loader.setAttribute('aria-live', 'polite');
        loader.setAttribute('aria-hidden', 'true');
        loader.innerHTML = [
            '<div class="admin-page-loader-card">',
            '<div class="admin-page-loader-brand"><i class="fas fa-hotel" aria-hidden="true"></i><span id="adminPageLoaderBrand"></span></div>',
            '<div class="admin-page-loader-spinner" aria-hidden="true">',
            '<span></span><span></span><span></span>',
            '</div>',
            '<div class="admin-page-loader-title">Loading admin workspace</div>',
            '<div class="admin-page-loader-text" id="adminPageLoaderText">Loading...</div>',
            '<div class="admin-page-loader-bar"><span></span></div>',
            '</div>'
        ].join('');
        document.body.appendChild(loader);
        return loader;
    }

    function _showOverlayLoader(message) {
        if (window.AdminPageLoader && typeof window.AdminPageLoader.show === 'function') {
            window.AdminPageLoader.show(message || 'Loading page...');
            return;
        }

        var loader = _ensureOverlayLoader();
        var brand = loader.querySelector('#adminPageLoaderBrand');
        var text = loader.querySelector('#adminPageLoaderText');
        if (brand) brand.textContent = _getLoaderBrandName();
        if (text) text.textContent = message || 'Loading page...';
        loader.classList.add('is-visible');
        loader.setAttribute('aria-hidden', 'false');
        document.body.classList.add('admin-page-loading');
    }

    function _hideOverlayLoader() {
        if (window.AdminPageLoader && typeof window.AdminPageLoader.hide === 'function') {
            window.AdminPageLoader.hide();
            return;
        }

        var loader = document.getElementById('adminPageLoader');
        if (!loader) return;
        loader.classList.remove('is-visible');
        loader.setAttribute('aria-hidden', 'true');
        document.body.classList.remove('admin-page-loading');
    }

    function _ensureLegacyAdminModalHelpers() {
        var MODAL_ROOT_SELECTOR =
            '.pkg-modal-overlay, .admin-modal-overlay, .booking-modal-overlay, .modal-overlay, [class*="-modal-overlay"], .rt-modal-overlay, .mm-modal, [data-modal], .rh-modal, .overlay, .modal';

        function _resolveModalRoot(node) {
            if (!node || typeof node.closest !== 'function') {
                return null;
            }

            return node.closest(MODAL_ROOT_SELECTOR);
        }

        function _isModalVisible(modal) {
            if (!modal) {
                return false;
            }

            if (
                modal.classList.contains('active') ||
                modal.classList.contains('modal--active') ||
                modal.classList.contains('show') ||
                modal.classList.contains('open') ||
                modal.classList.contains('visible')
            ) {
                return modal.style.display !== 'none';
            }

            if (modal.style.display && modal.style.display !== 'none') {
                return true;
            }

            try {
                var computed = window.getComputedStyle(modal);
                return computed.display !== 'none' && computed.visibility !== 'hidden' && parseFloat(computed.opacity || '1') > 0;
            } catch (_err) {
                return false;
            }
        }

        function _hasVisibleModals() {
            var candidates = document.querySelectorAll(MODAL_ROOT_SELECTOR);

            for (var i = 0; i < candidates.length; i++) {
                if (_isModalVisible(candidates[i])) {
                    return true;
                }
            }

            return false;
        }

        function _closeModalFallback(modal) {
            if (!modal) {
                return;
            }

            if (modal.matches('[data-modal]') && modal.id && window.Modal && typeof window.Modal.close === 'function') {
                window.Modal.close(modal.id);
                return;
            }

            modal.classList.remove('modal--active', 'active', 'show', 'open', 'visible');

            if (modal.style.display && modal.style.display !== 'none') {
                modal.style.display = 'none';
            }

            if (modal.hasAttribute('aria-hidden')) {
                modal.setAttribute('aria-hidden', 'true');
            }

            if (modal.id) {
                var overlay = document.getElementById(modal.id + '-overlay');
                if (overlay) {
                    overlay.classList.remove('modal--active', 'active', 'show', 'open', 'visible');
                    if (overlay.style.display && overlay.style.display !== 'none') {
                        overlay.style.display = 'none';
                    }
                    if (overlay.hasAttribute('aria-hidden')) {
                        overlay.setAttribute('aria-hidden', 'true');
                    }
                }
            }

            if (!_hasVisibleModals()) {
                document.body.classList.remove('modal-open');
                document.body.style.overflow = '';
            }
        }

        if (typeof window.openAdminModal !== 'function') {
            window.openAdminModal = function (modalId) {
                var modal = document.getElementById(modalId);
                if (!modal) return;
                modal.classList.add('active');
                document.body.classList.add('modal-open');
            };
        }

        if (typeof window.closeAdminModal !== 'function') {
            window.closeAdminModal = function (modalId) {
                var modal = document.getElementById(modalId);
                if (!modal) return;
                _closeModalFallback(modal);
            };
        }

        if (typeof window.bindAdminModal !== 'function') {
            window.bindAdminModal = function (modalId) {
                var modal = document.getElementById(modalId);
                if (!modal || modal.dataset.bound === '1') return;

                modal.addEventListener('click', function (event) {
                    if (event.target === modal) {
                        window.closeAdminModal(modalId);
                    }
                });

                modal.dataset.bound = '1';
            };
        }

        if (!window.__rhAdminModalEscapeBound) {
            document.addEventListener('keydown', function (event) {
                if (event.key !== 'Escape') return;
                var candidates = document.querySelectorAll(MODAL_ROOT_SELECTOR);
                for (var i = candidates.length - 1; i >= 0; i--) {
                    var active = candidates[i];
                    if (_isModalVisible(active)) {
                        _closeModalFallback(active);
                        break;
                    }
                }
            });
            window.__rhAdminModalEscapeBound = true;
        }

        if (!window.__rhAdminModalCloseFallbackBound) {
            document.addEventListener('click', function (event) {
                var closeTrigger = event.target.closest(
                    'button.modal-close, .modal-close, button.close-modal, .close-modal, .modal__close, .admin-modal__close, .pkg-modal__close, .rh-modal-close, .close, [class*="modal-close"], [data-modal-close], button[aria-label^="Close"]'
                );
                if (!closeTrigger) {
                    return;
                }

                var modal = _resolveModalRoot(closeTrigger);
                if (!modal) {
                    return;
                }

                // Give inline onclick handlers a chance first, then enforce close if still visible.
                setTimeout(function () {
                    if (_isModalVisible(modal)) {
                        _closeModalFallback(modal);
                    }
                }, 0);
            });

            window.__rhAdminModalCloseFallbackBound = true;
        }
    }

    function _collectInlineScriptExports(source) {
        var names = [];
        var patterns = [
            /^\s*(?:async\s+)?function\s+([A-Za-z_$][\w$]*)\s*\(/gm,
            /^\s*class\s+([A-Za-z_$][\w$]*)\b/gm,
            /^\s*(?:var|let|const)\s+([A-Za-z_$][\w$]*)\s*=\s*(?:async\s*)?(?:function\b|class\b|\([^\n=]*\)\s*=>|[A-Za-z_$][\w$]*\s*=>)/gm
        ];

        patterns.forEach(function (pattern) {
            var match;
            while ((match = pattern.exec(source)) !== null) {
                if (names.indexOf(match[1]) === -1) {
                    names.push(match[1]);
                }
            }
        });

        return names;
    }

    /**
     * A `document` shim for re-executed inline scripts. On SPA navigation the
     * document was parsed long ago, so the real `DOMContentLoaded` never fires
     * again — any inline `document.addEventListener('DOMContentLoaded', fn)`
     * would silently no-op, leaving page init (prices, tooltips, allocators…)
     * dead until a full reload. The shim runs such callbacks immediately;
     * everything else passes straight through to the real document.
     */
    function _getSpaDocProxy() {
        if (_spaDocProxy) return _spaDocProxy;
        if (typeof Proxy === 'undefined') return document; // legacy fallback
        _spaDocProxy = new Proxy(document, {
            get: function (target, prop) {
                if (prop === 'addEventListener') {
                    return function (type, listener, options) {
                        if (type === 'DOMContentLoaded' && listener) {
                            Promise.resolve().then(function () {
                                try {
                                    var evt = new Event('DOMContentLoaded');
                                    typeof listener === 'function'
                                        ? listener.call(target, evt)
                                        : listener.handleEvent(evt);
                                } catch (e) { console.error('[SPA] DOMContentLoaded handler failed', e); }
                            });
                            return;
                        }
                        return target.addEventListener(type, listener, options);
                    };
                }
                var val = target[prop];
                return typeof val === 'function' ? val.bind(target) : val;
            }
        });
        return _spaDocProxy;
    }
    // Expose so re-executed inline scripts (own global scope) can reach the shim.
    window.__rhSpaGetDoc = _getSpaDocProxy;

    function _buildInlineScriptSource(source) {
        var exports = _collectInlineScriptExports(source);
        var exportLines = exports.map(function (name) {
            return 'if (typeof ' + name + ' !== "undefined") { window.' + name + ' = ' + name + '; }';
        });

        return [
            '(function(window, document){',
            source,
            exportLines.join('\n'),
            '})(window, (window.__rhSpaGetDoc ? window.__rhSpaGetDoc() : document));'
        ].join('\n');
    }

    function _initLoader() {
        if (_loader) return;
        _loader = document.createElement('div');
        _loader.id = 'rh-spa-loader';
        _loader.setAttribute('aria-hidden', 'true');
        document.body.insertBefore(_loader, document.body.firstChild);
    }

    function _showLoader(message) {
        _initLoader();
        clearTimeout(_loaderTimer);
        _loader.style.transition = 'none';
        _loader.style.width = '0%';
        _loader.style.opacity = '1';
        _loaderShownAt = Date.now();
        _showOverlayLoader(message || 'Opening page...');

        // Start immediately so users can always see loading feedback.
        _loaderTimer = setTimeout(function () {
            _loader.style.transition = 'width 0.45s ease';
            _loader.style.width = '42%';

            _loaderTimer = setTimeout(function () {
                _loader.style.transition = 'width 0.85s ease';
                _loader.style.width = '78%';
            }, 120);
        }, 10);
    }

    function _finishLoader() {
        clearTimeout(_loaderTimer);
        if (!_loader) return;

        var elapsed = _loaderShownAt > 0 ? (Date.now() - _loaderShownAt) : _loaderMinVisibleMs;
        var wait = elapsed >= _loaderMinVisibleMs ? 0 : (_loaderMinVisibleMs - elapsed);

        _loaderTimer = setTimeout(function () {
            _loader.style.transition = 'width 0.2s ease';
            _loader.style.width = '100%';

            _loaderTimer = setTimeout(function () {
                _loader.style.transition = 'opacity 0.22s ease';
                _loader.style.opacity = '0';

                _loaderTimer = setTimeout(function () {
                    _loader.style.transition = 'none';
                    _loader.style.width = '0%';
                    _loaderShownAt = 0;
                    _hideOverlayLoader();
                }, 240);
            }, 120);
        }, wait);
    }

    function _hideLoader() {
        clearTimeout(_loaderTimer);
        if (!_loader) return;
        _loader.style.transition = 'none';
        _loader.style.opacity = '0';
        _loader.style.width = '0%';
        _loaderShownAt = 0;
        _hideOverlayLoader();
    }

    function _showScopedPaginationLoader(scopeElement, message, navEl) {
        if (!scopeElement) return function () { return; };

        if (!window.AdminScopedSectionLoader || typeof window.AdminScopedSectionLoader.show !== 'function') {
            window.AdminScopedSectionLoader = {
                show: function (container, text, options) {
                    if (!container) return function () { return; };

                    var shownAt = Date.now();
                    var minVisibleMs = 320;
                    // Use fixed-position overlay so overflow:hidden on container cannot clip it
                    var rect = container.getBoundingClientRect();
                    var fixedTop = Math.max(0, rect.top);
                    var fixedHeight = Math.min(rect.bottom, window.innerHeight) - fixedTop;
                    if (fixedHeight < 60) { fixedTop = Math.max(0, (window.innerHeight - 180) / 2); fixedHeight = 180; }
                    var loader = document.createElement('div');
                    loader.className = 'admin-section-scoped-loader';
                    if (options && options.placement === 'near-end') {
                        loader.classList.add('admin-section-scoped-loader--near-end');
                    }
                    loader.setAttribute('role', 'status');
                    loader.setAttribute('aria-live', 'polite');
                    loader.setAttribute('aria-label', text || 'Loading section');
                    loader.style.cssText = 'position:fixed !important;left:' + Math.round(rect.left) + 'px;top:' + Math.round(fixedTop) + 'px;width:' + Math.round(rect.width) + 'px;height:' + Math.round(fixedHeight) + 'px;right:auto !important;bottom:auto !important;z-index:9998 !important;';
                    loader.innerHTML = [
                        '<div class="admin-section-scoped-loader__card">',
                        '<div class="admin-section-scoped-loader__brand"><i class="fas fa-hotel" aria-hidden="true"></i><span>' + _getLoaderBrandName() + '</span></div>',
                        '<div class="admin-section-scoped-loader__body">',
                        '<span class="admin-pagination-loader__spinner" aria-hidden="true"></span>',
                        '<span class="admin-pagination-loader__text">' + (text || 'Loading next page...') + '</span>',
                        '</div>',
                        '</div>'
                    ].join('');

                    container.classList.add('admin-section-loading');
                    document.body.appendChild(loader);

                    return function () {
                        var elapsed = Date.now() - shownAt;
                        var wait = elapsed >= minVisibleMs ? 0 : (minVisibleMs - elapsed);
                        window.setTimeout(function () {
                            container.classList.remove('admin-section-loading');
                            if (loader.parentNode) loader.parentNode.removeChild(loader);
                        }, wait);
                    };
                }
            };
        }

        var tableHostSelector = '.table-responsive, .table-wrapper, .table-container, .acct-table-wrap, .invoices-table, .report-table-wrap';
        var targetContainer = null;

        if (navEl && typeof navEl.closest === 'function') {
            targetContainer = navEl.closest(tableHostSelector);
            if (!targetContainer) {
                // Walk back through all preceding siblings
                var prevSibLoader = navEl.previousElementSibling;
                while (prevSibLoader && !targetContainer) {
                    if (prevSibLoader.matches && prevSibLoader.matches(tableHostSelector)) {
                        targetContainer = prevSibLoader;
                    }
                    prevSibLoader = prevSibLoader.previousElementSibling;
                }
            }
            if (!targetContainer) {
                // Walk forward through all following siblings
                var nextSibLoader = navEl.nextElementSibling;
                while (nextSibLoader && !targetContainer) {
                    if (nextSibLoader.matches && nextSibLoader.matches(tableHostSelector)) {
                        targetContainer = nextSibLoader;
                    }
                    nextSibLoader = nextSibLoader.nextElementSibling;
                }
            }
        }

        if (!targetContainer && scopeElement.querySelector) {
            targetContainer = scopeElement.querySelector(tableHostSelector);
        }

        if (!targetContainer) {
            targetContainer = scopeElement;
        }

        return window.AdminScopedSectionLoader.show(targetContainer, message || 'Loading next page...');
    }

    // ── CSS injection ────────────────────────────────────────────────────────
    /**
     * Inject stylesheet links from the fetched page that aren't already loaded.
     * Returns a Promise that resolves when all new sheets have loaded.
     */
    function _injectCSS(doc) {
        // Mark the initial page stylesheet set as managed so we can safely
        // remove stale page-specific CSS on later SPA navigations.
        if (!window.__rhSpaManagedCssBootstrapped) {
            document.querySelectorAll('link[rel="stylesheet"]').forEach(function (l) {
                l.setAttribute('data-rh-spa-managed', '1');
            });
            window.__rhSpaManagedCssBootstrapped = true;
        }

        var targetHrefs = {};
        doc.querySelectorAll('link[rel="stylesheet"]').forEach(function (link) {
            targetHrefs[link.href] = true;
        });

        var linksToRemove = [];
        document.querySelectorAll('link[rel="stylesheet"][data-rh-spa-managed="1"]').forEach(function (link) {
            if (!targetHrefs[link.href]) {
                linksToRemove.push(link);
            }
        });

        var existingByHref = {};
        document.querySelectorAll('link[rel="stylesheet"]').forEach(function (l) {
            existingByHref[l.href] = l;
        });

        var promises = [];
        doc.querySelectorAll('link[rel="stylesheet"]').forEach(function (link) {
            var existing = existingByHref[link.href];
            if (existing) {
                existing.setAttribute('data-rh-spa-managed', '1');
                return;
            }

            var clone = document.createElement('link');
            clone.rel = 'stylesheet';
            clone.href = link.href;
            clone.setAttribute('data-rh-spa-managed', '1');

            var p = new Promise(function (resolve) {
                clone.onload = resolve;
                clone.onerror = resolve; // don't block on CSS errors
            });

            document.head.appendChild(clone);
            promises.push(p);
        });

        return Promise.all(promises).then(function () {
            linksToRemove.forEach(function (link) {
                if (link && link.parentNode) {
                    link.parentNode.removeChild(link);
                }
            });
        });
    }

    // ── External script injection ────────────────────────────────────────────
    /**
     * Inject <script src> tags from the fetched <head> that aren't yet loaded.
     * Returns a Promise that resolves when new scripts have loaded.
     */
    function _injectHeadScripts(doc) {
        var existingSrcs = {};
        document.querySelectorAll('script[src]').forEach(function (s) {
            existingSrcs[s.src] = true;
        });

        var promises = [];
        doc.querySelectorAll('head script[src]').forEach(function (script) {
            if (existingSrcs[script.src]) return;
            // Skip admin-spa.js itself
            if (script.src.indexOf('admin-spa.js') !== -1) return;
            // Skip page-specific scripts not meant for global injection
            if (script.hasAttribute('data-no-spa')) return;
            var s = document.createElement('script');
            s.src = script.src;
            if (script.hasAttribute('defer')) s.defer = true;
            var p = new Promise(function (resolve) {
                s.onload = resolve;
                s.onerror = resolve;
            });
            document.head.appendChild(s);
            promises.push(p);
        });

        return Promise.all(promises);
    }

    // ── Inline <style> management ────────────────────────────────────────────
    function _updateInlineStyles(doc) {
        // Remove styles injected by the previous SPA navigation
        document.querySelectorAll('style[data-rh-spa]').forEach(function (s) { s.remove(); });

        // Copy inline styles from the fetched page's <head>
        doc.querySelectorAll('head > style').forEach(function (style) {
            var clone = document.createElement('style');
            clone.textContent = style.textContent;
            clone.setAttribute('data-rh-spa', '1');
            document.head.appendChild(clone);
        });
    }

    // ── Inline script execution ──────────────────────────────────────────────
    /**
     * Re-execute <script> tags inside `container`.
     * Scripts injected via innerHTML don't auto-run; we must recreate them.
     */
    function _runScripts(container) {
        var scripts = Array.from(container.querySelectorAll('script'));
        return scripts.reduce(function (chain, old) {
            return chain.then(function () {
                var s = document.createElement('script');

                function hasLoadedScript(src) {
                    return Array.from(document.querySelectorAll('script[src]')).some(function (existing) {
                        if (container.contains(existing)) return false;
                        return existing.src === src;
                    });
                }

                // Copy attributes
                Array.from(old.attributes).forEach(function (a) {
                    s.setAttribute(a.name, a.value);
                });

                if (old.src) {
                    // External: only add once; skip page-specific scripts marked data-no-spa
                    if (hasLoadedScript(old.src) || old.hasAttribute('data-no-spa')) {
                        old.parentNode && old.parentNode.removeChild(old);
                        return;
                    }

                    return new Promise(function (resolve) {
                        s.async = false;
                        s.onload = resolve;
                        s.onerror = resolve;
                        document.body.appendChild(s);
                        old.parentNode && old.parentNode.removeChild(old);
                    });
                }

                if (old.textContent.trim()) {
                    // Isolate lexical declarations but re-export top-level page
                    // helpers so later scripts and inline onclick handlers still work.
                    s.textContent = _buildInlineScriptSource(old.textContent);
                    document.body.appendChild(s);
                    document.body.removeChild(s);
                }

                old.parentNode && old.parentNode.removeChild(old);
            });
        }, Promise.resolve());
    }

    // ── Active nav state ─────────────────────────────────────────────────────
    function _updateNavActive(href) {
        // Extract filename + query, e.g. "bookings.php" or "booking-details.php?id=5"
        var urlObj;
        try { urlObj = new URL(href); } catch (e) { return; }
        var filename = urlObj.pathname.split('/').pop();

        document.querySelectorAll('.admin-nav a.admin-nav-link').forEach(function (a) {
            var aHref = a.getAttribute('href') || '';
            var aFile = aHref.split('?')[0].split('/').pop();
            a.classList.toggle('active', aFile === filename);
        });

        // Update is-active-group on nav sections
        document.querySelectorAll('.nav-group').forEach(function (group) {
            var hasActive = group.querySelector('.admin-nav-link.active');
            group.classList.toggle('is-active-group', !!hasActive);
        });
    }

    // ── Page entry animation ─────────────────────────────────────────────────
    function _animateEnter(container) {
        container.classList.remove('rh-page-entering');
        // Trigger reflow to restart animation
        void container.offsetHeight;
        container.classList.add('rh-page-entering');
        container.addEventListener('animationend', function () {
            container.classList.remove('rh-page-entering');
        }, { once: true });
    }

    // ── Pre-navigation cleanup ───────────────────────────────────────────────
    function _cleanup() {
        // Close any open admin modals
        document.querySelectorAll(
            '.admin-modal-overlay, .modal-overlay, [role="dialog"][aria-hidden="false"]'
        ).forEach(function (el) {
            el.classList.remove('active', 'visible', 'open');
            el.setAttribute('aria-hidden', 'true');
        });

        // Remove floating datepicker overlays from the previous page.
        // Flatpickr appends calendars to <body>, so they can leak across SPA swaps.
        document.querySelectorAll('.flatpickr-calendar').forEach(function (el) {
            el.remove();
        });
        document.querySelectorAll('.flatpickr-input.active').forEach(function (el) {
            el.classList.remove('active');
        });

        // Restore body scroll if a modal had locked it
        document.body.style.overflow = '';
    }

    // ── SPA eligibility check ────────────────────────────────────────────────
    function _isSpaUrl(href) {
        try {
            var url = new URL(href);
            if (url.origin !== window.location.origin) return false;
            var path = url.pathname;
            if (path.indexOf('/admin/') === -1) return false;

            var file = path.split('/').pop();
            // Exclude full-nav pages
            for (var i = 0; i < FULL_NAV.length; i++) {
                if (file === FULL_NAV[i]) return false;
            }

            // Only handle .php URLs (not assets, CSV exports, etc.)
            if (file && file.indexOf('.') !== -1 && !file.endsWith('.php')) return false;

            // Skip anchor-only navigation on the same page
            if (url.pathname === window.location.pathname && !url.search && url.hash) return false;

            return true;
        } catch (e) {
            return false;
        }
    }

    // ── Core navigation ──────────────────────────────────────────────────────
    var _navigating = false;
    var _abortController = null;

    function _notifyContentUpdated(url, mode) {
        try {
            document.dispatchEvent(new CustomEvent('rh:content-updated', {
                detail: {
                    url: url || window.location.href,
                    mode: mode || 'navigation'
                }
            }));
        } catch (_err) {
            // non-fatal
        }
    }

    function _scrollToNavigationTarget(url) {
        var targetId = '';

        try {
            if (url && url.hash) {
                targetId = decodeURIComponent(url.hash.replace(/^#/, '')).trim();
            }

            if (!targetId && url && url.searchParams) {
                targetId = (url.searchParams.get('section') || '').trim();
            }
        } catch (_err) {
            targetId = '';
        }

        window.requestAnimationFrame(function () {
            window.requestAnimationFrame(function () {
                if (!targetId) {
                    window.scrollTo({ top: 0, behavior: 'instant' });
                    return;
                }

                var target = document.getElementById(targetId);
                if (!target) {
                    window.scrollTo({ top: 0, behavior: 'instant' });
                    return;
                }

                target.scrollIntoView({ behavior: 'instant', block: 'start' });
            });
        });
    }

    function _isPaginationLink(href, linkEl) {
        try {
            var target = new URL(href);
            var current = new URL(window.location.href);
            if (target.origin !== current.origin) return false;
            if (target.pathname !== current.pathname) return false;

            if (linkEl) {
                if (linkEl.dataset.pageLink !== undefined || linkEl.dataset.page !== undefined) return true;
                var rel = (linkEl.getAttribute('rel') || '').toLowerCase();
                if (rel.indexOf('next') !== -1 || rel.indexOf('prev') !== -1) return true;
                var className = (linkEl.className || '').toLowerCase();
                if (/(\bnext\b|\bprev\b|\bprevious\b|\bpagination\b)/.test(className)) return true;
                var ariaLabel = (linkEl.getAttribute('aria-label') || '').toLowerCase();
                if (/(next|previous|prev|page)/.test(ariaLabel)) return true;
                var text = (linkEl.textContent || '').trim().toLowerCase();
                if (text === 'next' || text === 'prev' || text === 'previous' || text === 'next ›' || text === '‹ prev') return true;
            }

            var hasPaginationParam = false;
            target.searchParams.forEach(function (_value, key) {
                var normalized = String(key || '').toLowerCase();
                if (
                    normalized === 'page' ||
                    normalized === 'p' ||
                    normalized === 'offset' ||
                    normalized === 'start' ||
                    normalized === 'cursor' ||
                    normalized.endsWith('_page') ||
                    normalized.endsWith('_offset') ||
                    normalized.endsWith('_cursor')
                ) {
                    hasPaginationParam = true;
                }
            });

            if (!hasPaginationParam) return false;
            return true;
        } catch (e) {
            return false;
        }
    }

    function _isLikelySectionPaginationAnchor(linkEl) {
        if (!linkEl || typeof linkEl.closest !== 'function') return false;

        var contentRoot = document.getElementById(CONTENT_ID);
        if (!contentRoot || !contentRoot.contains(linkEl)) return false;

        if (linkEl.closest('.admin-nav, .admin-header, .admin-sidebar, .admin-toolbar, .breadcrumbs')) {
            return false;
        }

        if (linkEl.closest('nav, .table-responsive, .table-wrapper, .table-container, .acct-table-wrap, .invoices-table, .report-table-wrap, .content')) {
            return true;
        }

        var text = (linkEl.textContent || '').replace(/\s+/g, ' ').trim().toLowerCase();
        if (/^(next|prev|previous|\d+)$/.test(text)) return true;

        var ariaLabel = (linkEl.getAttribute('aria-label') || '').toLowerCase();
        if (/(next|prev|previous|page)/.test(ariaLabel)) return true;

        return false;
    }

    function _paginationContainerSelector(navEl) {
        if (!navEl) return null;
        if (navEl.matches('[data-admin-pagination]')) return '[data-admin-pagination]';
        if (navEl.matches('[data-admin-auto-pagination-nav]')) return '[data-admin-auto-pagination-nav]';
        if (navEl.classList.contains('bookings-pagination')) return '.bookings-pagination';
        if (navEl.classList.contains('log-table-pagination')) return '.log-table-pagination';
        if (navEl.classList.contains('receipts-pagination')) return '.receipts-pagination';
        if (navEl.classList.contains('pagination-bar')) return '.pagination-bar';
        if (navEl.classList.contains('inv-pagination')) return '.inv-pagination';
        if (navEl.classList.contains('pagination')) return '.pagination';
        return null;
    }

    function _findPaginationScope(root, navEl) {
        if (!root || !navEl) return null;

        var siblingSelectors = ['.table-responsive', '.table-wrapper', '.table-container', '.acct-table-wrap', '.invoices-table', '.report-table-wrap'];
        var siblingTableHost = '.table-responsive, .table-wrapper, .table-container, .acct-table-wrap, .invoices-table, .report-table-wrap';
        // Walk backwards through all preceding siblings
        var prevSib = navEl.previousElementSibling;
        while (prevSib) {
            if (prevSib.nodeType === 1 && prevSib.matches(siblingTableHost)) {
                for (var ss = 0; ss < siblingSelectors.length; ss++) {
                    if (!prevSib.matches(siblingSelectors[ss])) continue;
                    var siblingList = root.querySelectorAll(siblingSelectors[ss]);
                    var siblingIndex = Array.prototype.indexOf.call(siblingList, prevSib);
                    if (siblingIndex >= 0) {
                        return { element: prevSib, selector: siblingSelectors[ss], index: siblingIndex };
                    }
                }
            }
            prevSib = prevSib.previousElementSibling;
        }
        // Walk forwards through all following siblings
        var nextSib = navEl.nextElementSibling;
        while (nextSib) {
            if (nextSib.nodeType === 1 && nextSib.matches(siblingTableHost)) {
                for (var ss2 = 0; ss2 < siblingSelectors.length; ss2++) {
                    if (!nextSib.matches(siblingSelectors[ss2])) continue;
                    var nextSibList = root.querySelectorAll(siblingSelectors[ss2]);
                    var nextSibIndex = Array.prototype.indexOf.call(nextSibList, nextSib);
                    if (nextSibIndex >= 0) {
                        return { element: nextSib, selector: siblingSelectors[ss2], index: nextSibIndex };
                    }
                }
            }
            nextSib = nextSib.nextElementSibling;
        }

        var scoped = navEl.closest('[data-admin-pagination-scope]');
        if (scoped) {
            var scopedList = root.querySelectorAll('[data-admin-pagination-scope]');
            var scopedIndex = Array.prototype.indexOf.call(scopedList, scoped);
            if (scopedIndex >= 0) {
                return {
                    element: scoped,
                    selector: '[data-admin-pagination-scope]',
                    index: scopedIndex
                };
            }
        }

        var candidates = ['.today-checkins-section', '.dashboard-section', '.section-card', '.widget', '.acct-panel', '.table-container', '.invoices-table', '.report-section', '.content'];
        for (var i = 0; i < candidates.length; i++) {
            var selector = candidates[i];
            var element = navEl.closest(selector);
            if (!element) continue;
            var list = root.querySelectorAll(selector);
            var index = Array.prototype.indexOf.call(list, element);
            if (index >= 0) {
                return {
                    element: element,
                    selector: selector,
                    index: index
                };
            }
        }

        return null;
    }

    function _syncPaginationNav(currentContent, newContent, scope, navEl, navSelector, navIndex) {
        if (!currentContent || !newContent || !scope || !scope.element || !navEl) return;

        var currentNav = null;
        if (navEl.matches && navEl.matches('nav')) {
            currentNav = navEl;
        } else if (typeof navEl.closest === 'function') {
            currentNav = navEl.closest('nav');
        }
        if (!currentNav) return;

        // If nav is inside swapped scope, it is already refreshed.
        if (scope.element.contains(currentNav)) return;

        var replacementNav = null;

        if (navSelector && navIndex >= 0) {
            var newNavList = newContent.querySelectorAll(navSelector);
            replacementNav = newNavList[navIndex] || null;
        }

        if (!replacementNav) {
            var newScopes = newContent.querySelectorAll(scope.selector);
            var newScope = newScopes[scope.index] || null;
            if (!newScope) return;

            if (currentNav.previousElementSibling === scope.element) {
                var nextNav = newScope.nextElementSibling;
                if (nextNav && nextNav.matches && nextNav.matches('nav')) {
                    replacementNav = nextNav;
                }
            } else if (currentNav.nextElementSibling === scope.element) {
                var prevNav = newScope.previousElementSibling;
                if (prevNav && prevNav.matches && prevNav.matches('nav')) {
                    replacementNav = prevNav;
                }
            }
        }

        if (!replacementNav) return;

        var replacementClone = replacementNav.cloneNode(true);
        currentNav.replaceWith(replacementClone);
    }

    function _gotoPagination(href, pushState, navEl, loaderMessage) {
        if (typeof pushState === 'undefined') pushState = true;

        var url;
        try { url = new URL(href); } catch (e) {
            _goto(href, pushState, loaderMessage);
            return;
        }
        var fullHref = url.href;

        var currentContent = document.getElementById(CONTENT_ID);
        var navSelector = _paginationContainerSelector(navEl);
        var navIndex = -1;
        var scope = _findPaginationScope(currentContent, navEl);
        if (!currentContent || !scope) {
            _goto(href, pushState, loaderMessage);
            return;
        }

        if (navSelector) {
            var navList = currentContent.querySelectorAll(navSelector);
            navIndex = Array.prototype.indexOf.call(navList, navEl);
            if (navIndex < 0) {
                _goto(href, pushState, loaderMessage);
                return;
            }
        }

        // Abort any in-flight request
        if (_abortController) {
            _abortController.abort();
            _abortController = null;
        }

        _navigating = true;
        _cleanup();
        _hideLoader();
        if (window.AdminPageLoader && typeof window.AdminPageLoader.hide === 'function') {
            window.AdminPageLoader.hide();
        }
        var hideScopedLoader = _showScopedPaginationLoader(scope.element, loaderMessage || 'Loading next page...', navEl);

        var controller = null;
        var signal = null;
        if (typeof AbortController !== 'undefined') {
            controller = new AbortController();
            signal = controller.signal;
            _abortController = controller;
        }

        var fetchOptions = {
            method: 'GET',
            credentials: 'same-origin',
            headers: {
                'X-Requested-With': 'XMLHttpRequest'
            }
        };
        if (signal) fetchOptions.signal = signal;

        fetch(fullHref, fetchOptions)
            .then(function (res) {
                if (res.url && (
                    res.url.indexOf('login.php') !== -1 ||
                    (res.url.indexOf('/admin/') === -1 && res.url.indexOf('admin') === -1)
                )) {
                    hideScopedLoader();
                    window.location.href = res.url;
                    return null;
                }

                if (!res.ok) {
                    hideScopedLoader();
                    _goto(fullHref, pushState, loaderMessage);
                    return null;
                }

                var ct = res.headers.get('content-type') || '';
                if (ct.indexOf('text/html') === -1) {
                    hideScopedLoader();
                    _goto(fullHref, pushState, loaderMessage);
                    return null;
                }

                return res.text();
            })
            .then(function (html) {
                if (html === null || html === undefined) return;

                var doc = new DOMParser().parseFromString(html, 'text/html');
                var newContent = doc.getElementById(CONTENT_ID);
                if (!newContent) {
                    hideScopedLoader();
                    _goto(fullHref, pushState, loaderMessage);
                    return;
                }

                var newScopes = newContent.querySelectorAll(scope.selector);
                var newScope = newScopes[scope.index] || null;
                if (!newScope) {
                    hideScopedLoader();
                    _goto(fullHref, pushState, loaderMessage);
                    return;
                }

                scope.element.innerHTML = newScope.innerHTML;
                _syncPaginationNav(currentContent, newContent, scope, navEl, navSelector, navIndex);

                _runScripts(scope.element)
                    .catch(function () { /* non-fatal script errors are ignored here */ })
                    .then(function () {
                        var newTitle = doc.querySelector('title');
                        if (newTitle) document.title = newTitle.textContent;

                        _updateNavActive(fullHref);
                        if (pushState) {
                            history.pushState({ spa: true, url: fullHref }, '', fullHref);
                        }

                        var y = scope.element.getBoundingClientRect().top + window.scrollY - 80;
                        if (y < 0) y = 0;
                        window.scrollTo({ top: y, behavior: 'smooth' });

                        _notifyContentUpdated(fullHref, 'pagination');

                        hideScopedLoader();
                        _navigating = false;
                        _abortController = null;
                    });
            })
            .catch(function (err) {
                if (err && err.name === 'AbortError') {
                    hideScopedLoader();
                    _navigating = false;
                    return;
                }
                hideScopedLoader();
                _navigating = false;
                _abortController = null;
                _goto(fullHref, pushState, loaderMessage);
            });
    }

    function _goto(href, pushState, loaderMessage) {
        if (typeof pushState === 'undefined') pushState = true;

        var url;
        try { url = new URL(href); } catch (e) {
            window.location.href = href;
            return;
        }

        var fullHref = url.href;

        // Abort any in-flight request
        if (_abortController) {
            _abortController.abort();
            _abortController = null;
        }

        _navigating = true;
        _showLoader(loaderMessage || 'Opening page...');
        _cleanup();

        // AbortController for this request
        var controller = null;
        var signal = null;
        if (typeof AbortController !== 'undefined') {
            controller = new AbortController();
            signal = controller.signal;
            _abortController = controller;
        }

        var fetchOptions = {
            method: 'GET',
            credentials: 'same-origin',
            headers: {
                'X-Requested-With': 'XMLHttpRequest'
            }
        };
        if (signal) fetchOptions.signal = signal;

        fetch(fullHref, fetchOptions)
            .then(function (res) {
                // Detect redirect to login (session expired)
                if (res.url && (
                    res.url.indexOf('login.php') !== -1 ||
                    (res.url.indexOf('/admin/') === -1 && res.url.indexOf('admin') === -1)
                )) {
                    window.location.href = res.url;
                    return null;
                }

                if (!res.ok) {
                    window.location.href = fullHref;
                    return null;
                }

                var ct = res.headers.get('content-type') || '';
                if (ct.indexOf('text/html') === -1) {
                    // Non-HTML response (PDF, CSV, etc.) — let browser handle
                    _hideLoader();
                    _navigating = false;
                    _abortController = null;
                    window.location.href = fullHref;
                    return null;
                }

                return res.text();
            })
            .then(function (html) {
                if (html === null || html === undefined) return;

                var doc = new DOMParser().parseFromString(html, 'text/html');

                // Detect login page in response body (session expired)
                if (doc.querySelector('input[name="admin_password"], form.login-form, #loginForm')) {
                    window.location.href = fullHref;
                    return;
                }

                var newContent = doc.getElementById(CONTENT_ID);
                if (!newContent) {
                    // Page doesn't have SPA wrapper — fall back
                    window.location.href = fullHref;
                    return;
                }

                var currentContent = document.getElementById(CONTENT_ID);
                if (!currentContent) {
                    window.location.href = fullHref;
                    return;
                }

                // Inject missing CSS & head scripts, then swap content
                Promise.all([_injectCSS(doc), _injectHeadScripts(doc)])
                    .then(function () {
                        _updateInlineStyles(doc);

                        // Update page title
                        var newTitle = doc.querySelector('title');
                        if (newTitle) document.title = newTitle.textContent;

                        // Swap content — clear any page-specific tab handler before the new
                        // page's scripts run and (optionally) register a new one.
                        window.__pageTabHandler = null;

                        currentContent.innerHTML = newContent.innerHTML;

                        return _runScripts(currentContent)
                            .catch(function (scriptErr) {
                                console.warn('[Admin SPA] Script execution error (non-fatal):', scriptErr);
                            })
                            .then(function () {
                                // Update nav active state
                                _updateNavActive(fullHref);

                                // Update browser history
                                if (pushState) {
                                    history.pushState({ spa: true, url: fullHref }, '', fullHref);
                                }

                                _scrollToNavigationTarget(url);

                                // Entry animation
                                _animateEnter(currentContent);

                                _notifyContentUpdated(fullHref, 'navigation');

                                _finishLoader();
                                _navigating = false;
                                _abortController = null;
                            });
                    })
                    .catch(function (innerErr) {
                        console.warn('[Admin SPA] Post-fetch error, falling back:', innerErr);
                        _hideLoader();
                        _navigating = false;
                        _abortController = null;
                        window.location.href = fullHref;
                    });
            })
            .catch(function (err) {
                if (err && err.name === 'AbortError') {
                    // Superseded by a newer navigation — ignore
                    _navigating = false;
                    return;
                }
                console.warn('[Admin SPA] Navigation failed, falling back to full load:', err);
                _hideLoader();
                _navigating = false;
                _abortController = null;
                window.location.href = fullHref;
            });
    }

    // ── Click intercept (event delegation on document) ───────────────────────
    document.addEventListener('click', function (e) {
        // Find the closest anchor tag
        var a = e.target.closest('a[href]');
        if (!a) return;

        // Ignore modified clicks (new tab, etc.)
        if (e.ctrlKey || e.metaKey || e.shiftKey || e.altKey) return;

        // Ignore target="_blank" or any explicit external target
        if (a.target && a.target !== '' && a.target !== '_self') return;

        // Ignore download links
        if (a.hasAttribute('download')) return;

        var paginationNode = a.closest(PAGINATION_NAV_SELECTOR);
        var isPaginationNavigation = _isPaginationLink(a.href, a) && (!!paginationNode || _isLikelySectionPaginationAnchor(a));

        // Honour explicit opt-out for regular links, but allow section-scoped
        // pagination links to use mini loaders instead of full page overlays.
        if (a.dataset.noSpa !== undefined && !isPaginationNavigation) return;

        // Confirmation dialogs — let their handler run first
        if (a.dataset.adminConfirm !== undefined) return;

        // Skip non-SPA URLs
        if (!_isSpaUrl(a.href)) return;

        e.preventDefault();
        var loaderMessage = a.dataset.adminLoaderText || ('Opening ' + (a.textContent || 'page').trim() + '...');

        if (isPaginationNavigation) {
            if (typeof e.stopImmediatePropagation === 'function') {
                e.stopImmediatePropagation();
            }
            _gotoPagination(a.href, true, paginationNode || a.closest('nav') || a, 'Loading page results...');
            return;
        }

        _goto(a.href, true, loaderMessage);
    });

    // ── Browser history (back / forward) ────────────────────────────────────
    window.addEventListener('popstate', function (e) {
        if (e.state && e.state.spa && e.state.url) {
            _goto(e.state.url, false);
        } else {
            // No SPA state — do a full reload to be safe
            window.location.reload();
        }
    });

    // ── Mark the initial page in history ────────────────────────────────────
    // Replace the current history entry so pressing Back after navigating works.
    history.replaceState(
        { spa: true, url: window.location.href },
        '',
        window.location.href
    );

    _ensureLegacyAdminModalHelpers();

})();
