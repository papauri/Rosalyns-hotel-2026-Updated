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

    window.RHFormGuard = {
        watch: watch,
        guardSubmit: guardSubmit,
        showError: showError,
        clearErrors: clearErrors,
        setFieldError: setFieldError
    };
})();
