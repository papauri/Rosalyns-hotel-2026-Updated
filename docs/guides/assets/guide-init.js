/**
 * guide-init.js
 * Swaps the hotel name into a guide from the live settings (assets/site-info.php), so the
 * same guide files serve every hotel on this platform. Fails silently: the static name stays.
 *
 *   .brand, .g-site-name  -> hotel name
 *   document.title        -> the hotel name written into the page replaced with the live one
 *
 * Also loads kb-search.js, which turns the guides into a searchable knowledge base.
 */
(function () {
    'use strict';
    var kb = document.createElement('script');
    kb.src = 'assets/kb-search.js';
    kb.defer = true;
    document.head.appendChild(kb);

    fetch('assets/site-info.php')
        .then(function (r) { return r.json(); })
        .then(function (d) {
            var name = (d.site_name || '').trim();
            if (!name) return;
            document.querySelectorAll('.brand, .g-site-name').forEach(function (el) { el.textContent = name; });
            document.title = document.title.replace(/Liwonde Sun Hotel|Rosalyns Beach Hotel/gi, name);
        })
        .catch(function () { /* static fallback text stays */ });

    // Show only the guides for what this business runs (modules / business preset). The server says which
    // guides are switched off; their links and cards are hidden, and a guide opened directly gets a banner.
    fetch('assets/kb-index.php?allowed=1', { credentials: 'same-origin' })
        .then(function (r) { return r.json(); })
        .then(function (d) {
            if (!d || !d.success) return;
            var blocked = d.blocked || {};
            hideModuleSections(d.modules, d.flags);
            if (!Object.keys(blocked).length) return;
            function fileOf(href) {
                var p = (href || '').split('#')[0].split('?')[0];
                return p.substring(p.lastIndexOf('/') + 1);
            }
            var hiddenItems = [];
            document.querySelectorAll('a[href]').forEach(function (a) {
                if (!Object.prototype.hasOwnProperty.call(blocked, fileOf(a.getAttribute('href')))) return;
                var li = a.closest('li');
                if (li) {
                    li.hidden = true;
                    li.style.display = 'none';
                    hiddenItems.push(li);
                } else {
                    // A link inside a sentence: keep the words, drop the link.
                    var s = document.createElement('span');
                    s.innerHTML = a.innerHTML;
                    a.parentNode.replaceChild(s, a);
                }
            });
            // A list (and its heading) with nothing left in it.
            hiddenItems.forEach(function (li) {
                var list = li.parentNode;
                if (!list || list.dataset.kbChecked) return;
                list.dataset.kbChecked = '1';
                var left = Array.prototype.filter.call(list.children, function (c) { return !c.hidden; });
                if (left.length) return;
                list.hidden = true;
                list.style.display = 'none';
                var h = list.previousElementSibling;
                while (h && !/^H[1-6]$/.test(h.tagName) && h.tagName !== 'P') h = h.previousElementSibling;
                if (h && /^H[2-6]$/.test(h.tagName)) { h.hidden = true; h.style.display = 'none'; }
            });
            // This page itself is for a switched-off module: say so, keep the content visible below.
            var me = fileOf(location.pathname);
            if (Object.prototype.hasOwnProperty.call(blocked, me)) {
                var wrap = document.querySelector('.wrap') || document.body;
                var b = document.createElement('div');
                b.className = 'warn';
                b.setAttribute('role', 'status');
                var p = document.createElement('p');
                var strong = document.createElement('strong');
                strong.textContent = 'This guide is for ' + blocked[me] + ', which is switched off for this business.';
                p.appendChild(strong);
                p.appendChild(document.createTextNode(' Ask an admin to turn it on in Module Settings.'));
                b.appendChild(p);
                var h1 = wrap.querySelector('h1');
                if (h1 && h1.parentNode) h1.parentNode.insertBefore(b, h1.nextSibling); else wrap.insertBefore(b, wrap.firstChild);
            }
        })
        .catch(function () { /* unknown: every guide stays visible */ });
}());

/**
 * Hide the parts of a guide written for a module that is switched off. An element carries
 * data-module="pos" (or "a|b" = any of; "events" and "restaurant" are the front-end flags).
 * A heading hides its whole section; a row, FAQ item or list item hides itself. The contents
 * link to a hidden section is hidden too. Unknown state (no modules) hides nothing.
 */
function hideModuleSections(modules, flags) {
    'use strict';
    if (!modules) return;
    function keyOn(k) {
        if (k === 'events') return !flags || flags.events !== false;
        if (k === 'restaurant') return !flags || flags.restaurant !== false;
        return modules[k] !== false;
    }
    function on(spec) {
        return spec.split('|').some(function (k) { return k && keyOn(k.trim()); });
    }
    function hide(el) { el.hidden = true; el.style.display = 'none'; }
    document.querySelectorAll('[data-module]').forEach(function (el) {
        if (on(el.getAttribute('data-module') || '')) return;
        var m = /^H([1-6])$/.exec(el.tagName);
        hide(el);
        if (m) {
            var level = parseInt(m[1], 10);
            for (var s = el.nextElementSibling; s; s = s.nextElementSibling) {
                var sm = /^H([1-6])$/.exec(s.tagName);
                if ((sm && parseInt(sm[1], 10) <= level) || s.tagName === 'FOOTER') break;
                hide(s);
            }
            if (el.id) {
                document.querySelectorAll('a[href="#' + el.id + '"]').forEach(function (a) {
                    hide(a.closest('li') || a);
                });
            }
        }
    });
}
