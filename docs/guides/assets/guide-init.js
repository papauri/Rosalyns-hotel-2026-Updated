/**
 * guide-init.js
 * Swaps the hotel name into a guide from the live settings (assets/site-info.php), so the
 * same guide files serve every hotel on this platform. Fails silently: the static name stays.
 *
 *   .brand, .g-site-name  -> hotel name
 *   document.title        -> "Liwonde Sun Hotel" replaced with the hotel name
 */
(function () {
    'use strict';
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
