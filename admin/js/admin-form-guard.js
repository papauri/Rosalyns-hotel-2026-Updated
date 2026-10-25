/**
 * Admin create/edit form helper (RHFormGuard).
 *
 * - watch():       flags a duplicate name / number WHILE TYPING by asking
 *                  api/check-unique.php (read-only, debounced). The server still checks again
 *                  on save - this only gives the message earlier.
 * - guardSubmit(): a form that is already saving ignores a second click, and the Save button
 *                  shows a busy state (for forms that reload the page).
 * - showError() / clearErrors(): the reason a save failed, shown inside the form.
 *
 * Plain script, loaded once (the guard below makes a second load a no-op, which matters for
 * the admin in-place navigation).
 */
(function () {
    'use strict';
    if (window.RHFormGuard) return;

    var ENDPOINT = 'api/check-unique.php';
    var DEBOUNCE_MS = 450;
    var timers = new WeakMap();
    var tokens = new WeakMap();

    function fieldBox(input) {
        var box = input._rhFieldError;
        if (box && box.isConnected) return box;
        box = document.createElement('div');
        box.className = 'rh-field-error';
        box.setAttribute('aria-live', 'polite');
        box.hidden = true;
        input.parentNode.insertBefore(box, input.nextSibling);
        input._rhFieldError = box;
        return box;
    }

    /** Show (or clear, with an empty message) the reason under one field. */
    function setFieldError(input, message) {
        input.setCustomValidity(message || '');
        input.classList.toggle('rh-input-invalid', !!message);
        var box = fieldBox(input);
        box.textContent = message || '';
        box.hidden = !message;
    }

    function formBox(form, create) {
        var box = form.querySelector('.rh-form-error');
        if (box || !create) return box;
        box = document.createElement('div');
        box.className = 'rh-form-error';
        box.setAttribute('role', 'alert');
        box.hidden = true;
        var body = form.querySelector('.modal-body, .mm-modal-body') || form;
        body.insertBefore(box, body.firstChild);
        return box;
    }

    /** The reason a save was refused, at the top of the form (plain text, never HTML). */
    function showError(form, message) {
        if (!form) return;
        var box = formBox(form, !!message);
        if (!box) return;
        box.textContent = message || '';
        box.hidden = !message;
        if (message && box.scrollIntoView) box.scrollIntoView({ block: 'nearest' });
    }

    /** Clear the form-level reason and every field-level duplicate flag. */
    function clearErrors(form) {
        if (!form) return;
        form.querySelectorAll('.rh-input-invalid').forEach(function (input) { setFieldError(input, ''); });
        showError(form, '');
    }

    function check(input, getCfg) {
        var cfg = getCfg();
        var value = input.value.trim();
        input.setCustomValidity('');
        input.classList.remove('rh-input-invalid');
        if (value === '' || !input.validity.valid || !cfg) {
            setFieldError(input, '');
            return;
        }
        if (typeof cfg.local === 'function') {
            var localMessage = cfg.local(input);
            if (localMessage) {
                setFieldError(input, localMessage);
                return;
            }
        }
        var qs = new URLSearchParams();
        qs.set('form', cfg.form);
        qs.set('value', value);
        if (cfg.excludeId) qs.set('exclude_id', String(cfg.excludeId));
        Object.keys(cfg.params || {}).forEach(function (k) { qs.set(k, cfg.params[k]); });
        var token = (tokens.get(input) || 0) + 1;
        tokens.set(input, token);
        fetch(ENDPOINT + '?' + qs.toString(), {
            headers: { 'X-Requested-With': 'XMLHttpRequest' },
            credentials: 'same-origin'
        })
            .then(function (res) { return res.json(); })
            .then(function (data) {
                if (tokens.get(input) !== token) return; // typed on since
                setFieldError(input, data && data.taken ? data.message : '');
            })
            .catch(function () { /* the server checks again on save */ });
    }

    /**
     * Flag a duplicate under `input` while typing.
     * getCfg() is called each time and returns {form, excludeId, params, local} - `form` is a key
     * of the fixed map in api/check-unique.php. Pass `extraTriggers` (elements) that should
     * re-run the check when they change, e.g. the category select.
     */
    function watch(input, getCfg, extraTriggers) {
        if (!input || input._rhWatched) return;
        input._rhWatched = true;
        input.addEventListener('input', function () {
            setFieldError(input, '');
            clearTimeout(timers.get(input));
            timers.set(input, setTimeout(function () { check(input, getCfg); }, DEBOUNCE_MS));
        });
        input.addEventListener('blur', function () {
            clearTimeout(timers.get(input));
            check(input, getCfg);
        });
        (extraTriggers || []).forEach(function (el) {
            if (el) el.addEventListener('change', function () { check(input, getCfg); });
        });
        if (input.form) {
            input.form.addEventListener('reset', function () { setFieldError(input, ''); });
        }
    }

    /**
     * Stop a flagged form from being submitted and, for forms that reload the page, show a busy
     * button and ignore a second click while the first save runs.
     * opts: {busy: true|false, busyText: 'Saving...'}
     */
    function guardSubmit(form, opts) {
        if (!form || form._rhGuarded) return;
        form._rhGuarded = true;
        opts = opts || {};
        var busy = opts.busy !== false;

        // The form's own submit button (form.elements skips buttons that merely sit inside it but
        // belong to another form via the form= attribute).
        function submitButton(e) {
            if (e && e.submitter && e.submitter.form === form) return e.submitter;
            return Array.prototype.find.call(form.elements, function (el) { return el.type === 'submit'; }) || null;
        }

        function release() {
            form._rhSubmitting = false;
            var btn = submitButton();
            if (btn && window.ButtonLoader) window.ButtonLoader.hide(btn);
        }
        // Back button restores the page from cache with the button still busy: free it again.
        window.addEventListener('pageshow', function (e) {
            if (e.persisted && form._rhSubmitting) release();
        });

        form.addEventListener('submit', function (e) {
            var invalid = form.querySelector('.rh-input-invalid');
            if (invalid) {
                e.preventDefault();
                e.stopImmediatePropagation();
                invalid.focus();
                return;
            }
            if (!busy) return;
            if (form._rhSubmitting) {
                e.preventDefault();
                e.stopImmediatePropagation();
                return;
            }
            form._rhSubmitting = true;
            var btn = submitButton(e);
            var text = opts.busyText || form.dataset.busyText || 'Saving...';
            if (btn && window.ButtonLoader) {
                window.ButtonLoader.show(btn, { text: text });
            } else if (btn) {
                btn.disabled = true;
            }
        }, true);
    }

    /** "45000" -> "45000.00" (blank or not a number stays as typed). Display only: the stored value is the same number. */
    function fixed2(value) {
        var s = String(value === null || value === undefined ? '' : value).trim().replace(/,/g, '');
        if (s === '' || isNaN(Number(s))) return String(value === null || value === undefined ? '' : value);
        return Number(s).toFixed(2);
    }

    /** Every [data-money] box in `root` shows 2 decimals when the person leaves it. */
    function moneyInputs(root) {
        (root || document).querySelectorAll('input[data-money]').forEach(function (input) {
            if (input._rhMoney) return;
            input._rhMoney = true;
            input.addEventListener('blur', function () {
                if (input.value !== '') input.value = fixed2(input.value);
            });
        });
    }

    /**
     * Inline checks for a form. rules: [{el, label, required, number, int, min, max, gt, maxLen,
     * lte (another input that must not be exceeded), lteLabel, file:{types:[], maxBytes, label}}].
     * Shown under each field when it is left and again on save (a save with a message is stopped).
     * The server checks the same rules again; this only gives the reason earlier.
     */
    function validateForm(form, rules) {
        if (!form || form._rhRules) return;
        form._rhRules = true;
        form.noValidate = true; // our messages replace the browser bubbles
        function visible(el) { return !!(el.offsetParent || el.getClientRects().length); }
        function messageFor(r) {
            var el = typeof r.el === 'string' ? document.getElementById(r.el) : r.el;
            if (!el) return null;
            var label = r.label || 'This field';
            if (r.file) {
                var f = el.files && el.files.length ? el.files : null;
                if (!f) return null;
                for (var i = 0; i < f.length; i++) {
                    if (r.file.types && r.file.types.indexOf(f[i].type) < 0) return f[i].name + ': only JPG, PNG or WEBP pictures are allowed.';
                    if (r.file.maxBytes && f[i].size > r.file.maxBytes) return f[i].name + ' is too large (' + (f[i].size / 1048576).toFixed(1) + ' MB). The most allowed is ' + (r.file.maxBytes / 1048576).toFixed(0) + ' MB.';
                }
                return null;
            }
            if (!visible(el)) return null;
            var v = String(el.value).trim().replace(/,/g, '');
            if (v === '') return r.required ? label + ' is required.' : null;
            if (r.maxLen && el.value.trim().length > r.maxLen) return label + ' is too long (the most is ' + r.maxLen + ' characters).';
            if (r.number || r.int || r.min !== undefined || r.max !== undefined || r.gt !== undefined) {
                if (isNaN(Number(v))) return label + ' must be a number.';
                var n = Number(v);
                if (r.int && (Math.floor(n) !== n)) return label + ' must be a whole number.';
                if (r.gt !== undefined && !(n > r.gt)) return label + ' must be more than ' + r.gt + '.';
                if (r.min !== undefined && r.max !== undefined && (n < r.min || n > r.max)) return label + ' must be between ' + r.min + ' and ' + r.max + '.';
                if (r.min !== undefined && n < r.min) return label + ' must be ' + r.min + ' or more.';
                if (r.max !== undefined && n > r.max) return label + ' must be ' + r.max + ' or less.';
                if (r.lte) {
                    var other = typeof r.lte === 'string' ? document.getElementById(r.lte) : r.lte;
                    var ov = other ? Number(String(other.value).trim().replace(/,/g, '')) : NaN;
                    if (!isNaN(ov) && n > ov) return label + ' cannot be more than ' + (r.lteLabel || 'the total') + '.';
                }
            }
            return null;
        }
        function run(r, showOnly) {
            var el = typeof r.el === 'string' ? document.getElementById(r.el) : r.el;
            if (!el) return null;
            var msg = messageFor(r);
            if (msg) {
                el._rhRuleMsg = msg;
                setFieldError(el, msg);
            } else if (el._rhRuleMsg) {
                el._rhRuleMsg = '';
                setFieldError(el, '');
            }
            return msg ? el : null;
        }
        rules.forEach(function (r) {
            var el = typeof r.el === 'string' ? document.getElementById(r.el) : r.el;
            if (!el) return;
            el.addEventListener(r.file ? 'change' : 'blur', function () { run(r); });
            if (!r.file) el.addEventListener('input', function () { if (el._rhRuleMsg) run(r); });
            if (r.lte) {
                var other = typeof r.lte === 'string' ? document.getElementById(r.lte) : r.lte;
                if (other) other.addEventListener('blur', function () { if (el.value !== '') run(r); });
            }
        });
        form.addEventListener('submit', function (e) {
            var first = null;
            rules.forEach(function (r) { var bad = run(r); if (bad && !first) first = bad; });
            if (first) {
                e.preventDefault();
                e.stopImmediatePropagation();
                if (first.focus && first.type !== 'file') first.focus();
                if (first.scrollIntoView) first.scrollIntoView({ block: 'center' });
            }
        }, true);
    }

    window.RHFormGuard = {
        watch: watch,
        guardSubmit: guardSubmit,
        showError: showError,
        clearErrors: clearErrors,
        setFieldError: setFieldError,
        validateForm: validateForm,
        moneyInputs: moneyInputs,
        fixed2: fixed2
    };
})();
