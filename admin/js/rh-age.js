/*
 * One age formatter for every live timer (KDS ticket timers, POS open-tab ages).
 *   under a minute   "45s"
 *   under an hour    "12m 05s"
 *   an hour or more  "2h 05m"
 * wrapUnit (optional) wraps each unit letter, e.g. in a span the KDS renders smaller.
 */
(function (w) {
    'use strict';

    function pad2(n) {
        return String(n).padStart(2, '0');
    }

    w.rhFmtAge = function (sec, wrapUnit) {
        var u = typeof wrapUnit === 'function' ? wrapUnit : function (x) { return x; };
        sec = Math.max(0, Math.floor(Number(sec) || 0));
        var m = Math.floor(sec / 60);
        var s = sec % 60;
        if (m >= 60) return Math.floor(m / 60) + u('h') + ' ' + pad2(m % 60) + u('m');
        if (m > 0) return m + u('m') + ' ' + pad2(s) + u('s');
        return s + u('s');
    };
})(window);
