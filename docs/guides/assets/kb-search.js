/**
 * kb-search.js — the staff guides as a searchable knowledge base.
 * Loaded on every guide page by guide-init.js. Search runs server-side (assets/kb-index.php,
 * built from the guides themselves by includes/guide-knowledge-base.php) so the admin Ctrl+K
 * search and this box always agree.
 *
 *   - Search box in the top bar of every guide (press "/" to jump to it), or the large box on
 *     the guides home page ([data-kb-search="inline"]). Arrows + Enter, Esc to close.
 *   - Arriving from a result (?kb=<words>&kbh=<heading>#section): scrolls to the exact
 *     sub-heading or problem row, highlights the words, and offers "Clear highlights".
 *   - faq.html: filter box over the curated questions plus every how-to and error message
 *     from all guides (kb-index.php?faq=1).
 */
(function () {
    'use strict';
    if (window.__rhKb) return;
    window.__rhKb = true;

    var base = (function () {
        var s = document.currentScript && document.currentScript.src;
        return s ? s.replace(/[^/]*$/, '') : 'assets/';
    }());
    var API = base + 'kb-index.php';
    var STOP = ' a an the to do i how what is are can my of for in on and or does why when where it me you with be we our this that at from there should need want ';
    var TYPE_LABEL = { section: 'Guide', problem: 'Error / problem', faq: 'FAQ', howto: 'How to', page: 'Where to find', permission: 'Permission', role: 'Role', module: 'Module', hint: 'On screen' };

    function esc(s) {
        return String(s == null ? '' : s).replace(/[&<>"']/g, function (c) {
            return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c];
        });
    }
    function norm(s) {
        // Same rules as rh_kb_normalize() in includes/guide-knowledge-base.php.
        return ' ' + String(s || '').toLowerCase()
            .replace(/\bcheck((?:\s+(?:a|an|the|your|this|that|guests?|members?|him|her|them|someone|people))+)\s+(in|out)\b/g, 'check$2$1')
            .replace(/\b(check)[\s-]?(in|out)s?\b/g, '$1$2')
            .replace(/\b(log|sign)[\s-]?(in|on)\b/g, 'signin')
            .replace(/\b(log|sign)[\s-]?(out|off)\b/g, 'signout')
            .replace(/\b(e-?mail)s?\b/g, 'email')
            .replace(/\bset-?up\b/g, 'set up')
            .replace(/\bwalk[\s-]in(s?)\b/g, 'walkin$1')
            .replace(/\bno[\s-]shows?\b/g, 'noshow')
            .replace(/\bz[\s-]?reports?\b/g, 'zreport')
            .replace(/[^a-z0-9À-￿]+/g, ' ').replace(/\s+/g, ' ').trim() + ' ';
    }
    function stem(w) {
        if (w.length <= 4) return w;
        // Same as rh_kb_stem(): "batches" -> "batch", "recipes" -> "recipe".
        var s = w.replace(/ies$/, 'y');
        if (s === w) s = /(ss|x|z|ch|sh)es$/.test(w) ? w.slice(0, -2) : w.replace(/(ing|ed|([^s])s)$/, '$2');
        return s.length >= 3 ? s : w;
    }
    function terms(q) {
        var words = norm(q).trim().split(' ').filter(Boolean);
        var kept = words.filter(function (w) { return STOP.indexOf(' ' + w + ' ') < 0; });
        return (kept.length ? kept : words).map(stem).slice(0, 8);
    }
    /** Regex source for a term as it appears in the page text ("checkin" -> check-in / check in). */
    function reEscape(s) {
        return s.replace(/[.*+?^${}()|[\]\\]/g, '\\$&').replace(/^(check|sign)(in|out)$/, '$1[\\s-]?$2');
    }
    /** Escaped text with the query words wrapped in <mark>. */
    function markup(text, ts) {
        var t = esc(text);
        var useful = (ts || []).filter(function (x) { return x.length >= 3; });
        if (!useful.length) return t;
        var re = new RegExp('(^|[^a-z0-9])(' + useful.map(reEscape).join('|') + ')', 'gi');
        return t.replace(re, '$1<mark>$2</mark>');
    }
    function reducedMotion() {
        return window.matchMedia && window.matchMedia('(prefers-reduced-motion: reduce)').matches;
    }

    // -----------------------------------------------------------------------------------------
    // Search box (top bar on every guide, or the large inline box on the home page)
    // -----------------------------------------------------------------------------------------
    function mountSearch(host, inline) {
        var id = 'kb-q-' + Math.random().toString(36).slice(2, 8);
        host.classList.add(inline ? 'kb-search--inline' : 'kb-search--nav');
        host.classList.add('kb-search');
        host.setAttribute('role', 'search');
        host.innerHTML =
            '<label for="' + id + '" class="kb-sr">Search the guides</label>'
            + '<input type="search" id="' + id + '" class="kb-input" autocomplete="off" spellcheck="false" role="combobox"'
            + ' aria-expanded="false" aria-controls="' + id + '-list" aria-autocomplete="list"'
            + ' placeholder="' + (inline ? 'Search the guides — a question, a task or an error message…' : 'Search guides…  ( / )') + '">'
            + '<div class="kb-results" id="' + id + '-list" role="listbox" hidden></div>'
            + '<div class="kb-sr" aria-live="polite"></div>';
        var input = host.querySelector('.kb-input');
        var box = host.querySelector('.kb-results');
        var live = host.querySelector('[aria-live]');
        var items = [], active = -1, timer = null, ctrl = null, last = '';

        function show(on) {
            box.hidden = !on;
            input.setAttribute('aria-expanded', on ? 'true' : 'false');
        }
        function paint() {
            box.querySelectorAll('.kb-result').forEach(function (el, i) {
                var on = i === active;
                el.classList.toggle('is-active', on);
                el.setAttribute('aria-selected', on ? 'true' : 'false');
                if (on) { input.setAttribute('aria-activedescendant', el.id); el.scrollIntoView({ block: 'nearest' }); }
            });
            if (active < 0) input.removeAttribute('aria-activedescendant');
        }
        /** The full answer for the best result: its steps, or why + what to do, or the answer text. */
        function answer(r, ts, maxSteps) {
            var h = '';
            if (r.type === 'problem' && (r.why || r.fix)) {
                if (r.why) h += '<span class="kb-answer__line"><strong>Why:</strong> ' + markup(r.why, ts) + '</span>';
                if (r.fix) h += '<span class="kb-answer__line"><strong>What to do:</strong> ' + markup(r.fix, ts) + '</span>';
            } else if (r.steps && r.steps.length) {
                h += '<span class="kb-answer__steps">' + r.steps.slice(0, maxSteps).map(function (s, i) {
                    return '<span class="kb-answer__step"><b>' + (i + 1) + '.</b> ' + markup(s, ts) + '</span>';
                }).join('') + (r.steps.length > maxSteps ? '<span class="kb-answer__more">…more steps in the guide</span>' : '') + '</span>';
            } else if (r.answer) {
                h += '<span class="kb-answer__line">' + markup(r.answer, ts) + '</span>';
            } else {
                return '';
            }
            return '<span class="kb-answer">' + h + '<span class="kb-answer__open">Open the full answer →</span></span>';
        }
        function render(q, data) {
            var ts = terms(q);
            items = (data && data.results) || [];
            active = items.length ? 0 : -1;
            var html = '';
            var fixed = data && data.corrected ? Object.keys(data.corrected) : [];
            if (fixed.length && items.length) {
                html += '<p class="kb-results__note">Also searched for: ' + fixed.map(function (w) {
                    return '<strong>' + esc(data.corrected[w].join(' / ')) + '</strong> (for “' + esc(w) + '”)';
                }).join(', ') + '</p>';
            }
            if (data && data.partial && items.length) {
                html += '<p class="kb-results__note">No exact match. Closest answers:</p>';
            }
            items.forEach(function (r, i) {
                var where = r.guide + (r.parent ? ' › ' + r.parent : '');
                var best = i === 0 && !(data && data.partial) ? answer(r, ts, inline ? 12 : 5) : '';
                html += '<a class="kb-result' + (best ? ' kb-result--best' : '') + '" role="option" id="' + box.id + '-' + i + '" href="' + esc(r.url) + '" aria-selected="false">'
                    + (best ? '<span class="kb-best-label">Best answer</span>' : '')
                    + '<span class="kb-badge kb-badge--' + esc(r.type) + '">' + esc(TYPE_LABEL[r.type] || 'Guide') + '</span>'
                    + '<span class="kb-result__title">' + markup(r.title, ts) + '</span>'
                    + '<span class="kb-result__where">' + esc(where) + '</span>'
                    + (best || (!r.snippet) ? best : '<span class="kb-result__snip">' + markup(r.snippet, ts) + '</span>')
                    + '</a>';
            });
            if (!items.length) {
                html += '<p class="kb-results__note">Nothing found for “' + esc(q) + '”. Try other words, or browse the <a href="' + esc(base + '../faq.html') + '">FAQ</a>.</p>';
            } else {
                html += '<a class="kb-results__more" href="' + esc(base + '../faq.html?q=' + encodeURIComponent(q)) + '">Search the FAQ for “' + esc(q) + '” →</a>';
            }
            box.innerHTML = html;
            live.textContent = items.length ? items.length + ' results' : 'No results';
            show(true);
            paint();
        }
        function run(q) {
            last = q;
            if (q.length < 2) { show(false); box.innerHTML = ''; items = []; return; }
            if (ctrl && ctrl.abort) ctrl.abort();
            ctrl = window.AbortController ? new AbortController() : null;
            fetch(API + '?limit=' + (inline ? 15 : 8) + '&q=' + encodeURIComponent(q), { signal: ctrl ? ctrl.signal : undefined })
                .then(function (r) { return r.json(); })
                .then(function (d) {
                    if (q !== last) return;
                    if (!d || !d.success) throw new Error('kb');
                    render(q, d);
                })
                .catch(function (e) {
                    if (e && e.name === 'AbortError') return;
                    box.innerHTML = '<p class="kb-results__note">Search is not available right now. Use the guide list or the FAQ instead.</p>';
                    show(true);
                });
        }

        input.addEventListener('input', function () {
            clearTimeout(timer);
            var q = input.value.trim();
            timer = setTimeout(function () { run(q); }, 160);
        });
        input.addEventListener('focus', function () { if (items.length || box.innerHTML) show(true); });
        input.addEventListener('keydown', function (e) {
            if (e.key === 'ArrowDown') { e.preventDefault(); if (items.length) { active = (active + 1) % items.length; paint(); } }
            else if (e.key === 'ArrowUp') { e.preventDefault(); if (items.length) { active = (active - 1 + items.length) % items.length; paint(); } }
            else if (e.key === 'Enter') {
                e.preventDefault();
                var el = box.querySelectorAll('.kb-result')[active];
                if (el) window.location.href = el.href;
                else if (input.value.trim().length >= 2) { clearTimeout(timer); run(input.value.trim()); }
            } else if (e.key === 'Escape') {
                if (!box.hidden) { e.preventDefault(); show(false); } else { input.value = ''; }
            }
        });
        document.addEventListener('mousedown', function (e) { if (!host.contains(e.target)) show(false); });

        var params = new URLSearchParams(window.location.search);
        if (inline && params.get('q')) { input.value = params.get('q'); run(input.value.trim()); }
        return input;
    }

    // -----------------------------------------------------------------------------------------
    // Landing from a result: scroll to the exact spot and highlight the words
    // -----------------------------------------------------------------------------------------
    function sectionNodes(start) {
        // The nodes from a heading (or details) up to the next h2.
        if (start.tagName === 'DETAILS' || start.tagName === 'TR') return [start];
        var stopAt = start.tagName === 'H3' ? /^H[23]$/ : /^H2$/;
        var out = [start], n = start.nextElementSibling;
        while (n && !stopAt.test(n.tagName)) { out.push(n); n = n.nextElementSibling; }
        return out;
    }
    function highlight(nodes, ts) {
        var useful = ts.filter(function (x) { return x.length >= 3; });
        if (!useful.length) return 0;
        var re = new RegExp('(^|[^a-z0-9])(' + useful.map(reEscape).join('|') + ')', 'gi');
        var count = 0;
        nodes.forEach(function (root) {
            var walker = document.createTreeWalker(root, NodeFilter.SHOW_TEXT, {
                acceptNode: function (t) {
                    var p = t.parentNode;
                    if (!t.nodeValue.trim() || !p || /^(SCRIPT|STYLE|MARK|INPUT|TEXTAREA)$/.test(p.nodeName)) return NodeFilter.FILTER_REJECT;
                    re.lastIndex = 0;
                    return re.test(t.nodeValue) ? NodeFilter.FILTER_ACCEPT : NodeFilter.FILTER_SKIP;
                }
            });
            var list = [];
            while (walker.nextNode()) list.push(walker.currentNode);
            list.forEach(function (t) {
                var frag = document.createDocumentFragment(), s = t.nodeValue, last = 0, m;
                re.lastIndex = 0;
                while ((m = re.exec(s))) {
                    var at = m.index + m[1].length;
                    frag.appendChild(document.createTextNode(s.slice(last, at)));
                    var mk = document.createElement('mark');
                    mk.className = 'kb-hit';
                    mk.textContent = m[2];
                    frag.appendChild(mk);
                    last = at + m[2].length;
                    count++;
                }
                frag.appendChild(document.createTextNode(s.slice(last)));
                t.parentNode.replaceChild(frag, t);
            });
        });
        return count;
    }
    function clearHighlights() {
        document.querySelectorAll('mark.kb-hit').forEach(function (m) {
            m.replaceWith(document.createTextNode(m.textContent));
        });
        document.querySelectorAll('.kb-flash').forEach(function (el) { el.classList.remove('kb-flash'); });
        var bar = document.querySelector('.kb-bar');
        if (bar) bar.remove();
        document.body.normalize();
    }
    function land() {
        var params = new URLSearchParams(window.location.search);
        var q = (params.get('kb') || '').trim();
        var heading = (params.get('kbh') || '').trim();
        var id = decodeURIComponent((window.location.hash || '').slice(1));
        if (!q && !heading) return;
        var anchor = id ? document.getElementById(id) : null;
        var target = anchor;

        if (anchor && anchor.tagName === 'DETAILS') anchor.open = true;
        if (anchor && heading) {
            var want = norm(heading).trim().slice(0, 60);
            sectionNodes(anchor).some(function (el) {
                var cands = el.matches && el.matches('h3, tr') ? [el] : Array.prototype.slice.call(el.querySelectorAll ? el.querySelectorAll('h3, tbody tr') : []);
                return cands.some(function (c) {
                    var txt = norm(c.textContent).trim();
                    if (want && txt.indexOf(want) === 0) { target = c; return true; }
                    return false;
                });
            });
        }
        var ts = terms(q);
        var hits = anchor ? highlight(sectionNodes(anchor), ts) : 0;
        if (target) {
            target.classList.add('kb-flash');
            setTimeout(function () { target.scrollIntoView({ block: target.tagName === 'TR' ? 'center' : 'start', behavior: reducedMotion() ? 'auto' : 'smooth' }); }, 60);
        }

        var bar = document.createElement('div');
        bar.className = 'kb-bar';
        bar.setAttribute('role', 'status');
        bar.innerHTML = '<span>' + (q ? 'Showing <strong>“' + esc(q) + '”</strong>' + (hits ? ' · ' + hits + ' highlighted' : '') : 'Found it') + '</span>'
            + '<a href="index.html?q=' + encodeURIComponent(q || heading) + '">Back to results</a>'
            + '<button type="button">Clear highlights</button>';
        bar.querySelector('button').addEventListener('click', clearHighlights);
        document.body.appendChild(bar);

        params.delete('kb');
        params.delete('kbh');
        var qs = params.toString();
        try { history.replaceState(history.state, '', window.location.pathname + (qs ? '?' + qs : '') + window.location.hash); } catch (e) { /* ignore */ }
    }

    // -----------------------------------------------------------------------------------------
    // FAQ page
    // -----------------------------------------------------------------------------------------
    function initFaq() {
        var filter = document.getElementById('kb-faq-filter');
        if (!filter) return;
        var list = document.getElementById('kb-faq-list');
        var chips = document.getElementById('kb-faq-guides');
        var all = document.getElementById('kb-all');
        var count = document.getElementById('kb-faq-count');
        var none = document.getElementById('kb-faq-none');
        var curated = Array.prototype.slice.call(document.querySelectorAll('#kb-curated details.faq'));
        var dynamic = [];
        var guideFilter = '';

        function question(f) {
            if (f.type === 'problem') return '“' + f.title + '”';
            if (/^how to /i.test(f.title)) return 'How do I ' + f.title.replace(/^how to /i, '') + '?';
            return f.title;
        }
        function body(f) {
            var h = '';
            if (f.type === 'problem') {
                if (f.why) h += '<p><strong>Why:</strong> ' + esc(f.why) + '</p>';
                if (f.fix) h += '<p><strong>What to do:</strong> ' + esc(f.fix) + '</p>';
            } else if (f.steps && f.steps.length) {
                h += '<ol class="steps">' + f.steps.map(function (s) { return '<li>' + esc(s) + '</li>'; }).join('') + '</ol>';
            } else if (f.text) {
                h += '<p>' + esc(f.text) + (f.text.length >= 360 ? '…' : '') + '</p>';
            }
            h += '<p class="faq__src"><a href="' + esc(f.url) + '">Read this in ' + esc(f.guide) + (f.parent ? ' › ' + esc(f.parent) : '') + ' →</a></p>';
            return h;
        }

        function apply() {
            var ts = terms(filter.value.trim());
            var active = filter.value.trim().length >= 2;
            var shown = 0;
            function test(el, hay, guide) {
                var okGuide = !guideFilter || guide === guideFilter;
                var okText = !active || ts.every(function (t) { return hay.indexOf(' ' + t) >= 0; });
                el.hidden = !(okGuide && okText);
                if (!el.hidden) shown++;
                return !el.hidden;
            }
            curated.forEach(function (el) { test(el, el.__hay || (el.__hay = norm(el.textContent)), guideFilter ? '__curated' : ''); });
            document.querySelectorAll('#kb-curated h2').forEach(function (h) {
                var any = false, n = h.nextElementSibling;
                while (n && n.tagName !== 'H2') { if (n.tagName === 'DETAILS' && !n.hidden) any = true; n = n.nextElementSibling; }
                h.hidden = !any;
            });
            dynamic.forEach(function (d) { test(d.el, d.hay, d.file); });
            list.querySelectorAll('.kb-faq-group').forEach(function (g) {
                g.hidden = !g.querySelector('details:not([hidden])');
            });
            all.hidden = !dynamic.length;
            none.hidden = shown > 0;
            count.textContent = active || guideFilter ? shown + ' matching question' + (shown === 1 ? '' : 's') : (curated.length + dynamic.length) + ' questions';
            if (active && shown <= 6) {
                curated.concat(dynamic.map(function (d) { return d.el; })).forEach(function (el) { if (!el.hidden) el.open = true; });
            }
        }

        var t = null;
        filter.addEventListener('input', function () { clearTimeout(t); t = setTimeout(apply, 120); });
        var params = new URLSearchParams(window.location.search);
        if (params.get('q')) filter.value = params.get('q');

        fetch(API + '?faq=1').then(function (r) { return r.json(); }).then(function (d) {
            if (!d || !d.success) throw new Error('kb');
            var byGuide = {}, order = [];
            d.faq.forEach(function (f) {
                if (f.type === 'faq') return; // already on the page
                if (!byGuide[f.file]) { byGuide[f.file] = []; order.push(f.file); }
                byGuide[f.file].push(f);
            });
            var names = {};
            d.guides.forEach(function (g) { names[g.file] = g.title; });
            var chipHtml = '<button type="button" class="kb-chip is-on" data-file="">All guides</button>';
            var html = '';
            order.forEach(function (file) {
                var gid = 'faq-' + file.replace(/\.[a-z]+$/, '');
                chipHtml += '<button type="button" class="kb-chip" data-file="' + esc(file) + '">' + esc(names[file] || file) + '</button>';
                html += '<section class="kb-faq-group" id="' + esc(gid) + '"><h3>' + esc(names[file] || file) + '</h3>';
                byGuide[file].forEach(function (f, i) {
                    html += '<details class="faq faq--' + esc(f.type) + '" data-i="' + i + '" data-file="' + esc(file) + '">'
                        + '<summary><span class="kb-badge kb-badge--' + esc(f.type) + '">' + esc(TYPE_LABEL[f.type] || '') + '</span> ' + esc(question(f)) + '</summary>'
                        + body(f) + '</details>';
                });
                html += '</section>';
            });
            chips.innerHTML = chipHtml;
            list.innerHTML = html;
            list.querySelectorAll('details').forEach(function (el) {
                var f = byGuide[el.getAttribute('data-file')][Number(el.getAttribute('data-i'))];
                dynamic.push({ el: el, file: f.file, hay: norm([f.title, f.parent, f.why, f.fix, f.text, (f.steps || []).join(' '), f.guide].join(' ')) });
            });
            chips.addEventListener('click', function (e) {
                var b = e.target.closest('.kb-chip');
                if (!b) return;
                guideFilter = b.getAttribute('data-file');
                chips.querySelectorAll('.kb-chip').forEach(function (c) { c.classList.toggle('is-on', c === b); });
                apply();
            });
            apply();
        }).catch(function () {
            count.textContent = 'Showing the common questions only — the full list could not be loaded.';
            apply();
        });
        apply();
    }

    // -----------------------------------------------------------------------------------------
    function init() {
        var inlineHost = document.querySelector('[data-kb-search="inline"]');
        var input;
        if (inlineHost) {
            input = mountSearch(inlineHost, true);
        } else {
            var nav = document.querySelector('nav.top');
            if (nav) {
                if (!nav.querySelector('a[href="faq.html"]')) {
                    var faqLink = document.createElement('a');
                    faqLink.href = 'faq.html';
                    faqLink.textContent = 'FAQ';
                    nav.appendChild(faqLink);
                }
                var host = document.createElement('div');
                nav.appendChild(host);
                input = mountSearch(host, false);
            }
        }
        if (input) {
            document.addEventListener('keydown', function (e) {
                var tag = (e.target && e.target.tagName) || '';
                if (e.key === '/' && !/^(INPUT|TEXTAREA|SELECT)$/.test(tag) && !(e.target && e.target.isContentEditable) && !e.ctrlKey && !e.metaKey && !e.altKey) {
                    e.preventDefault();
                    input.focus();
                }
            });
        }
        initFaq();
        land();
    }
    if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', init);
    else init();
}());
