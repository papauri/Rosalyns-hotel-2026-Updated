(function () {
    'use strict';

    const ROOT_ID = 'rh-admin-page';
    const DEFAULT_SUBTITLE = 'Use this page to monitor activity and manage records.';

    const HEADER_SELECTORS = [
        '.rh-admin-page-header',
        '.blocked-dates-header',
        '.page-header',
        '.content-header',
        '.page-header-row',
        '.admin-page-hero',
        '.acct-page-header',
        '.cn-page-header'
    ];

    const TITLE_SELECTORS = [
        '.rh-admin-page-title',
        '.page-title',
        '.section-title',
        '.acct-page-header__title',
        'h1',
        'h2'
    ];

    const INTRO_PARENT_SELECTORS = [
        '.rh-admin-page-intro',
        '.blocked-dates-header__title',
        '.acct-page-header__copy',
        '.cn-page-header__left',
        '.admin-page-hero'
    ];

    const TOP_SKIP_SELECTORS = '.card, .stats-grid, .table-container, .alert, .stat-card';

    let normalizeRaf = 0;
    let isNormalizing = false;

    const FIT_CANDIDATE_SELECTORS = [
        '.table-responsive',
        '.table-container',
        '.admin-table-wrap',
        '.admin-table-container',
        '.bookings-table-wrap',
        '.report-table-wrapper',
        '.users-section',
        '.permissions-panel',
        '.role-overview',
        '.stats-grid',
        '.bookings-toolbar',
        '#booking-results',
        '.bookings-section'
    ];
    const MIN_DYNAMIC_FIT_WIDTH = 1025;

    function cleanText(value) {
        return String(value || '').replace(/\s+/g, ' ').trim();
    }

    function isVisibleElement(element) {
        if (!(element instanceof HTMLElement)) {
            return false;
        }

        const style = window.getComputedStyle(element);
        if (style.display === 'none' || style.visibility === 'hidden') {
            return false;
        }

        const rect = element.getBoundingClientRect();
        return rect.width > 0 && rect.height > 0;
    }

    function sanitizeTitle(value) {
        let title = cleanText(value)
            .replace(/^[^\p{L}\p{N}]+/u, '')
            .replace(/\s+/g, ' ')
            .trim();

        if (/^room\s+calendar$/i.test(title)) {
            return 'Calendar';
        }

        return title;
    }

    function isHeadingElement(element) {
        return element instanceof HTMLElement && /^H[1-6]$/.test(element.tagName);
    }

    function extractTitleText(element) {
        if (!(element instanceof HTMLElement)) {
            return '';
        }

        const clone = element.cloneNode(true);
        clone.querySelectorAll('i, svg, [aria-hidden="true"]').forEach((node) => node.remove());
        return sanitizeTitle(clone.textContent);
    }

    function getNavTitle() {
        const activeLink = document.querySelector('.admin-nav-link.active');
        if (!(activeLink instanceof HTMLAnchorElement)) {
            return '';
        }

        const activePath = activeLink.getAttribute('href') || '';
        const activePage = activePath.split('?')[0].split('/').pop() || '';
        const currentPage = (window.location.pathname.split('/').pop() || '').toLowerCase();

        if (activePage.toLowerCase() !== currentPage) {
            return '';
        }

        const activeLabel = activeLink.querySelector('span');
        if (activeLabel instanceof HTMLElement) {
            return sanitizeTitle(activeLabel.textContent);
        }

        return '';
    }

    function getDocumentTitle() {
        let title = cleanText(document.title);
        if (title === '') {
            return '';
        }

        title = title
            .replace(/\s*[|\-\u2013\u2014]\s*Admin Panel\s*$/i, '')
            .replace(/\s*[|\-\u2013\u2014]\s*Admin\s*$/i, '')
            .replace(/\s*\|\s*[^|]*Admin\s*$/i, '')
            .replace(/\s*[|\-\u2013\u2014]\s*[^|\-\u2013\u2014]*Hotel\s*Admin\s*$/i, '')
            .replace(/\s*[|\-\u2013\u2014]\s*[^|\-\u2013\u2014]*Hotel\s*$/i, '');

        return sanitizeTitle(title);
    }

    function resolveCanonicalTitle(sourceTitleElement) {
        const documentTitle = getDocumentTitle();
        if (documentTitle !== '') {
            return documentTitle;
        }

        const navTitle = getNavTitle();
        if (navTitle !== '') {
            return navTitle;
        }

        const sourceTitle = extractTitleText(sourceTitleElement);
        if (sourceTitle !== '') {
            return sourceTitle;
        }

        return 'Admin Workspace';
    }

    function getContentRoots() {
        const shell = document.getElementById(ROOT_ID);
        if (!(shell instanceof HTMLElement)) {
            return [];
        }

        const commonContainerSelectors = [
            '.content',
            '.admin-content',
            '.admin-container',
            '.user-management-container',
            '.reports-container',
            '.payments-container',
            '.payment-details-container',
            '.payment-add-container',
            '.payment-refund-container',
            '.booking-details-container',
            '.booking-details-page',
            '.invoices-container',
            '.room-dashboard',
            '.tentative-page',
            '.wrap'
        ];
        const selector = commonContainerSelectors.join(', ');

        const directRoots = Array.from(shell.children).filter((node) => {
            return node instanceof HTMLElement && node.matches(selector);
        });

        const roots = directRoots.length > 0
            ? directRoots
            : Array.from(shell.querySelectorAll(':scope > ' + selector));

        const fallbackRoots = roots.length > 0
            ? roots
            : Array.from(shell.children).filter((node) => {
                if (!(node instanceof HTMLElement)) {
                    return false;
                }

                if (node.matches('script, style, noscript, template')) {
                    return false;
                }

                if (node.matches('.admin-modal-overlay, .modal-overlay, .modal, [role="dialog"]')) {
                    return false;
                }

                return true;
            });

        return fallbackRoots.filter((root) => {
            if (!(root instanceof HTMLElement)) {
                return false;
            }

            const elementChildren = Array.from(root.children).filter((node) => node instanceof HTMLElement);
            if (elementChildren.length === 1 && elementChildren[0].matches('a.btn, button.btn, a.btn-secondary, button.btn-secondary')) {
                return false;
            }

            return true;
        });
    }

    function findExplicitHeaderContainer(root) {
        const children = Array.from(root.children).filter((node) => node instanceof HTMLElement);
        for (let i = 0; i < children.length; i += 1) {
            const child = children[i];
            if (HEADER_SELECTORS.some((selector) => child.matches(selector)) && !isHeadingElement(child)) {
                return child;
            }
        }

        return null;
    }

    function findTopTitleCandidate(root, header) {
        const children = Array.from(root.children).filter((node) => node instanceof HTMLElement);
        for (let i = 0; i < Math.min(children.length, 6); i += 1) {
            const child = children[i];
            if (child === header || child.matches(TOP_SKIP_SELECTORS)) {
                continue;
            }

            if (TITLE_SELECTORS.some((selector) => child.matches(selector))) {
                return child;
            }

            for (let j = 0; j < TITLE_SELECTORS.length; j += 1) {
                const nested = child.querySelector(':scope > ' + TITLE_SELECTORS[j]);
                if (nested instanceof HTMLElement) {
                    return nested;
                }
            }
        }

        return null;
    }

    function findTitleElement(container) {
        for (let i = 0; i < TITLE_SELECTORS.length; i += 1) {
            const selector = TITLE_SELECTORS[i];
            const direct = container.querySelector(':scope > ' + selector);
            if (direct instanceof HTMLElement) {
                return direct;
            }
        }

        for (let i = 0; i < TITLE_SELECTORS.length; i += 1) {
            const selector = TITLE_SELECTORS[i];
            const nested = container.querySelector(selector);
            if (nested instanceof HTMLElement) {
                return nested;
            }
        }

        return null;
    }

    function findSubtitleElement(title, header) {
        const titleParent = title.parentElement;
        if (titleParent instanceof HTMLElement) {
            const siblings = Array.from(titleParent.children).filter((node) => node instanceof HTMLElement && node !== title);
            for (let i = 0; i < siblings.length; i += 1) {
                const candidate = siblings[i];
                if (candidate.matches('.rh-admin-page-subtitle, .blocked-dates-header__subtitle, .page-subtitle, .acct-page-header__subtitle, .cn-page-header__sub, [class*="subtitle"], [class*="__sub"], p')) {
                    return candidate;
                }
            }
        }

        const candidates = header.querySelectorAll(':scope > p, :scope > .rh-admin-page-subtitle, .blocked-dates-header__subtitle, .page-subtitle, .acct-page-header__subtitle, .cn-page-header__sub, [class*="subtitle"], [class*="__sub"]');
        for (let i = 0; i < candidates.length; i += 1) {
            const candidate = candidates[i];
            if (candidate instanceof HTMLElement && candidate !== title) {
                return candidate;
            }
        }

        return null;
    }

    function resolveIntroContainer(header, title) {
        const titleParent = title.parentElement;
        if (!(titleParent instanceof HTMLElement)) {
            return null;
        }

        if (INTRO_PARENT_SELECTORS.some((selector) => titleParent.matches(selector))) {
            return titleParent;
        }

        if (titleParent !== header && !isHeadingElement(titleParent) && titleParent.children.length <= 6) {
            return titleParent;
        }

        return null;
    }

    function findActionsNode(header, intro) {
        const children = Array.from(header.children).filter((node) => node instanceof HTMLElement && node !== intro);
        for (let i = 0; i < children.length; i += 1) {
            const child = children[i];
            if (child.matches('.page-actions, .header-actions, [class$="__actions"], .cn-page-header__actions, .acct-page-header__actions, .blocked-dates-header__actions')) {
                return child;
            }
        }

        return null;
    }

    function createHeader(root, titleText, subtitleText) {
        const header = document.createElement('div');
        header.className = 'rh-admin-page-header';

        const intro = document.createElement('div');
        intro.className = 'rh-admin-page-intro';

        const title = document.createElement('h2');
        title.className = 'rh-admin-page-title';
        title.textContent = titleText;

        const subtitle = document.createElement('p');
        subtitle.className = 'rh-admin-page-subtitle rh-admin-page-subtitle--generated';
        subtitle.textContent = subtitleText;

        intro.appendChild(title);
        intro.appendChild(subtitle);
        header.appendChild(intro);

        if (root.firstElementChild) {
            root.insertBefore(header, root.firstElementChild);
        } else {
            root.appendChild(header);
        }

        return header;
    }

    function shouldRemoveOriginalTitle(root, sourceTitle, canonicalTitle) {
        if (!(sourceTitle instanceof HTMLElement)) {
            return false;
        }

        if (sourceTitle.parentElement !== root) {
            return false;
        }

        const sourceText = extractTitleText(sourceTitle).toLowerCase();
        const canonical = cleanText(canonicalTitle).toLowerCase();
        if (sourceText === '') {
            return false;
        }

        return sourceText === canonical
            || sourceText.includes(canonical)
            || canonical.includes(sourceText)
            || sourceTitle.matches('h1, h2, .page-title, .section-title');
    }

    function normalizeRoot(root) {
        if (!(root instanceof HTMLElement)) {
            return;
        }

        if (root.dataset.rhPageHeaderNormalized === '1') {
            return;
        }

        let header = findExplicitHeaderContainer(root);
        const sourceTitle = header ? findTitleElement(header) : findTopTitleCandidate(root, header);
        const canonicalTitle = resolveCanonicalTitle(sourceTitle);

        if (!(header instanceof HTMLElement)) {
            createHeader(root, canonicalTitle, DEFAULT_SUBTITLE);
            if (shouldRemoveOriginalTitle(root, sourceTitle, canonicalTitle)) {
                sourceTitle.remove();
            }
            root.dataset.rhPageHeaderNormalized = '1';
            return;
        }

        if (isHeadingElement(header)) {
            const repairedHeader = createHeader(root, canonicalTitle, DEFAULT_SUBTITLE);
            if (shouldRemoveOriginalTitle(root, header, canonicalTitle)) {
                header.remove();
            }
            repairedHeader.classList.add('rh-admin-page-header');
            root.dataset.rhPageHeaderNormalized = '1';
            return;
        }

        header.classList.add('rh-admin-page-header');

        let title = findTitleElement(header);
        if (!(title instanceof HTMLElement)) {
            title = document.createElement('h2');
            title.className = 'rh-admin-page-title';
            title.textContent = canonicalTitle;
        }

        let subtitle = findSubtitleElement(title, header);

        let intro = resolveIntroContainer(header, title);
        if (!(intro instanceof HTMLElement)) {
            intro = document.createElement('div');
            intro.className = 'rh-admin-page-intro';
            const actionsNode = findActionsNode(header, intro);
            if (actionsNode) {
                header.insertBefore(intro, actionsNode);
            } else if (header.firstElementChild) {
                header.insertBefore(intro, header.firstElementChild);
            } else {
                header.appendChild(intro);
            }
        }

        intro.classList.add('rh-admin-page-intro');
        if (!intro.contains(title)) {
            intro.insertBefore(title, intro.firstElementChild || null);
        }

        if (!(subtitle instanceof HTMLElement)) {
            subtitle = document.createElement('p');
            subtitle.className = 'rh-admin-page-subtitle rh-admin-page-subtitle--generated';
            subtitle.textContent = DEFAULT_SUBTITLE;
        }

        if (!intro.contains(subtitle)) {
            intro.appendChild(subtitle);
        }

        title.classList.remove('section-title', 'page-title');
        title.classList.add('rh-admin-page-title');
        title.textContent = canonicalTitle;

        subtitle.classList.add('rh-admin-page-subtitle');
        if (cleanText(subtitle.textContent) === '') {
            subtitle.textContent = DEFAULT_SUBTITLE;
            subtitle.classList.add('rh-admin-page-subtitle--generated');
        }

        root.dataset.rhPageHeaderNormalized = '1';
    }

    function resetDynamicFit(root) {
        if (!(root instanceof HTMLElement)) {
            return;
        }

        const previouslyApplied = root.querySelectorAll('[data-rh-fit-applied="1"]');
        for (let i = 0; i < previouslyApplied.length; i += 1) {
            const node = previouslyApplied[i];
            if (!(node instanceof HTMLElement)) {
                continue;
            }

            node.style.removeProperty('max-height');
            node.style.removeProperty('overflow-y');
            node.style.removeProperty('overscroll-behavior');
            node.removeAttribute('data-rh-fit-applied');
        }
    }

    function getFitCandidates(root) {
        const selector = FIT_CANDIDATE_SELECTORS.join(', ');
        const picked = new Set();
        const candidates = [];

        const addCandidate = function (element) {
            if (!(element instanceof HTMLElement)) {
                return;
            }

            if (picked.has(element)) {
                return;
            }

            if (!isVisibleElement(element)) {
                return;
            }

            if (element.closest('.modal-overlay, .admin-modal-overlay, .modal, [role="dialog"]')) {
                return;
            }

            if (element.closest('.rh-admin-page-header')) {
                return;
            }

            picked.add(element);
            candidates.push(element);
        };

        const queryCandidates = root.querySelectorAll(selector);
        for (let i = 0; i < queryCandidates.length; i += 1) {
            addCandidate(queryCandidates[i]);
        }

        const fallbackDirectChildren = Array.from(root.children).filter((node) => {
            if (!(node instanceof HTMLElement)) {
                return false;
            }

            if (node.matches('script, style, noscript, template')) {
                return false;
            }

            if (node.matches('.rh-admin-page-header')) {
                return false;
            }

            return true;
        });

        for (let i = 0; i < fallbackDirectChildren.length; i += 1) {
            addCandidate(fallbackDirectChildren[i]);
        }

        return candidates.sort((a, b) => b.getBoundingClientRect().height - a.getBoundingClientRect().height);
    }

    function applyDynamicFit(root) {
        if (!(root instanceof HTMLElement)) {
            return;
        }

        resetDynamicFit(root);

        // Container-level vertical scrolling is disabled globally.
        // Keep widgets fully expanded and rely on page-level scroll only.
    }

    function normalizeAllRoots() {
        if (isNormalizing) {
            return;
        }

        isNormalizing = true;
        try {
            const roots = getContentRoots();
            for (let i = 0; i < roots.length; i += 1) {
                normalizeRoot(roots[i]);
                applyDynamicFit(roots[i]);
            }
        } finally {
            isNormalizing = false;
        }
    }

    function scheduleNormalize() {
        if (normalizeRaf !== 0) {
            return;
        }

        normalizeRaf = window.requestAnimationFrame(function () {
            normalizeRaf = 0;
            normalizeAllRoots();
        });
    }

    function attachObserver() {
        const shell = document.getElementById(ROOT_ID);
        if (!(shell instanceof HTMLElement) || shell.dataset.rhPageHeaderObserver === '1') {
            return;
        }

        const observer = new MutationObserver(function (mutations) {
            for (let i = 0; i < mutations.length; i += 1) {
                const mutation = mutations[i];
                if (mutation.type === 'childList' && (mutation.addedNodes.length > 0 || mutation.removedNodes.length > 0)) {
                    scheduleNormalize();
                    break;
                }
            }
        });

        observer.observe(shell, {
            childList: true,
            subtree: true
        });

        shell.dataset.rhPageHeaderObserver = '1';
    }

    function init() {
        normalizeAllRoots();
        attachObserver();
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', init);
    } else {
        init();
    }

    window.addEventListener('pageshow', scheduleNormalize);
    window.addEventListener('resize', scheduleNormalize, { passive: true });
    window.addEventListener('orientationchange', scheduleNormalize, { passive: true });
})();
