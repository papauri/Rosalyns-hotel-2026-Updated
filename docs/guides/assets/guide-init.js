/**
 * guide-init.js
 * Fetches hotel branding from site-info.php and replaces static placeholders
 * throughout every guide page. Falls back silently if the request fails.
 *
 * Elements handled:
 *   .brand              — nav header (text set to UPPER CASE hotel name)
 *   .g-site-name        — any inline span showing the hotel name (mixed case)
 *   .g-site-name-upper  — any inline span showing the hotel name in UPPER CASE
 *   document.title      — strips "Rosalyn's Hotel" and replaces with actual name
 */
(function () {
    'use strict';

    fetch('assets/site-info.php')
        .then(function (r) { return r.json(); })
        .then(function (d) {
            var name = (d.site_name || '').trim();
            if (!name) return;

            var upper = name.toUpperCase();

            // Nav brand
            document.querySelectorAll('.brand').forEach(function (el) {
                el.textContent = upper;
            });

            // page <title>
            document.title = document.title.replace(/Rosalyn'?s\s+Hotel/gi, name);

            // Inline mixed-case spans
            document.querySelectorAll('.g-site-name').forEach(function (el) {
                el.textContent = name;
            });

            // Inline upper-case spans (footers, banners)
            document.querySelectorAll('.g-site-name-upper').forEach(function (el) {
                el.textContent = upper;
            });
        })
        .catch(function () { /* fail silently — static fallback text stays */ });
}());
