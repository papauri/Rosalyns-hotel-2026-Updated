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
}());
