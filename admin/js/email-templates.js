/*
 * Email & PDF templates editor (admin/email-templates.php -> booking-settings.php, templates mode).
 *
 * Data comes from <script type="application/json" id="tpl-config">:
 *   defaults : built-in subject + html per template key (for "Load Default")
 *   tokens   : every tag, for the {{ autocomplete
 *   self     : URL the preview / test-send requests post to
 *   csrf     : fallback security token
 *
 * Picker: a grouped list (.tpl-tab buttons) on wide screens, a <select> on small ones. Both
 * only choose which .tpl-panel is visible; every panel stays inside the one form, so Save All
 * still posts every template.
 */
(function () {
    'use strict';

    var cfgEl = document.getElementById('tpl-config');
    if (!cfgEl) { return; }
    var cfg = {};
    try { cfg = JSON.parse(cfgEl.textContent || '{}') || {}; } catch (e) { cfg = {}; }

    var tplDefaults = cfg.defaults || {};
    var placeholderTokens = cfg.tokens || [];
    var selfUrl = cfg.self || window.location.pathname;
    var reduceMotion = !!(window.matchMedia && window.matchMedia('(prefers-reduced-motion: reduce)').matches);

    // ---- Jump to the card when the page is opened with #email-templates ----
    var previewSectionTarget = document.getElementById('email-templates');
    if (previewSectionTarget) {
        var previewSectionParams = new URLSearchParams(window.location.search);
        if (window.location.hash === '#email-templates' || previewSectionParams.get('section') === 'email-templates') {
            window.requestAnimationFrame(function () {
                previewSectionTarget.scrollIntoView({
                    behavior: reduceMotion ? 'auto' : 'smooth',
                    block: 'start'
                });
            });
        }
    }

    // ---- {{ autocomplete ----
    var autocompleteState = {
        field: null,
        tokenStart: -1,
        filtered: [],
        activeIndex: 0
    };
    var autocompleteMenu = document.createElement('div');
    autocompleteMenu.className = 'tpl-autocomplete';
    autocompleteMenu.style.display = 'none';
    document.body.appendChild(autocompleteMenu);

    function hideAutocomplete() {
        autocompleteMenu.style.display = 'none';
        autocompleteMenu.innerHTML = '';
        autocompleteState.field = null;
        autocompleteState.tokenStart = -1;
        autocompleteState.filtered = [];
        autocompleteState.activeIndex = 0;
    }

    function positionAutocomplete(field) {
        var rect = field.getBoundingClientRect();
        autocompleteMenu.style.left = (window.scrollX + rect.left) + 'px';
        autocompleteMenu.style.top = (window.scrollY + rect.bottom + 4) + 'px';
        autocompleteMenu.style.width = Math.min(rect.width, 420) + 'px';
    }

    function updateAutocompleteActiveItem() {
        var items = autocompleteMenu.querySelectorAll('.tpl-autocomplete__item');
        items.forEach(function (item, index) {
            if (index === autocompleteState.activeIndex) {
                item.classList.add('is-active');
            } else {
                item.classList.remove('is-active');
            }
        });
    }

    function applyAutocompleteSelection(index) {
        var field = autocompleteState.field;
        if (!field) {
            hideAutocomplete();
            return;
        }

        var token = autocompleteState.filtered[index] || autocompleteState.filtered[0];
        if (!token) {
            hideAutocomplete();
            return;
        }

        var caret = field.selectionStart;
        var before = field.value.substring(0, autocompleteState.tokenStart);
        var after = field.value.substring(caret);
        field.value = before + token + after;
        var newPos = before.length + token.length;
        field.selectionStart = newPos;
        field.selectionEnd = newPos;
        field.focus();
        hideAutocomplete();
    }

    function renderAutocomplete(field, filtered, tokenStart) {
        autocompleteState.field = field;
        autocompleteState.filtered = filtered;
        autocompleteState.tokenStart = tokenStart;
        autocompleteState.activeIndex = 0;

        autocompleteMenu.innerHTML = '';
        filtered.forEach(function (token, index) {
            var btn = document.createElement('button');
            btn.type = 'button';
            btn.className = 'tpl-autocomplete__item';
            if (index === 0) {
                btn.classList.add('is-active');
            }
            btn.textContent = token;
            btn.addEventListener('mousedown', function (event) {
                event.preventDefault();
                applyAutocompleteSelection(index);
            });
            autocompleteMenu.appendChild(btn);
        });

        positionAutocomplete(field);
        autocompleteMenu.style.display = 'block';
    }

    function refreshAutocomplete(field) {
        if (!field || typeof field.selectionStart !== 'number') {
            hideAutocomplete();
            return;
        }

        var caret = field.selectionStart;
        var beforeCaret = field.value.slice(0, caret);
        var match = beforeCaret.match(/\{\{[a-zA-Z0-9_]*$/);
        if (!match) {
            hideAutocomplete();
            return;
        }

        var partialToken = match[0];
        var query = partialToken.slice(2).toLowerCase();
        var tokenStart = caret - partialToken.length;
        var filtered = placeholderTokens.filter(function (token) {
            var cleanToken = token.slice(2, -2).toLowerCase();
            return cleanToken.indexOf(query) !== -1;
        }).slice(0, 10);

        if (!filtered.length) {
            hideAutocomplete();
            return;
        }

        renderAutocomplete(field, filtered, tokenStart);
    }

    function bindAutocomplete(field) {
        field.addEventListener('input', function () {
            refreshAutocomplete(field);
        });
        field.addEventListener('click', function () {
            refreshAutocomplete(field);
        });
        field.addEventListener('keyup', function (event) {
            if (event.key === 'ArrowDown' || event.key === 'ArrowUp' || event.key === 'Enter' || event.key === 'Tab' || event.key === 'Escape') {
                return;
            }
            refreshAutocomplete(field);
        });
        field.addEventListener('keydown', function (event) {
            if (autocompleteMenu.style.display !== 'block' || autocompleteState.field !== field) {
                return;
            }

            if (event.key === 'ArrowDown') {
                event.preventDefault();
                autocompleteState.activeIndex = (autocompleteState.activeIndex + 1) % autocompleteState.filtered.length;
                updateAutocompleteActiveItem();
                return;
            }
            if (event.key === 'ArrowUp') {
                event.preventDefault();
                autocompleteState.activeIndex = (autocompleteState.activeIndex - 1 + autocompleteState.filtered.length) % autocompleteState.filtered.length;
                updateAutocompleteActiveItem();
                return;
            }
            if (event.key === 'Enter' || event.key === 'Tab') {
                event.preventDefault();
                applyAutocompleteSelection(autocompleteState.activeIndex);
                return;
            }
            if (event.key === 'Escape') {
                event.preventDefault();
                hideAutocomplete();
            }
        });
        field.addEventListener('blur', function () {
            setTimeout(hideAutocomplete, 120);
        });
    }

    var editFields = document.querySelectorAll('input[id$="_subject"], textarea[id$="_html_body"], textarea[id$="_text_body"]');
    editFields.forEach(function (field) {
        bindAutocomplete(field);
    });
    window.addEventListener('resize', function () {
        if (autocompleteState.field) {
            positionAutocomplete(autocompleteState.field);
        }
    });
    window.addEventListener('scroll', function () {
        if (autocompleteState.field) {
            positionAutocomplete(autocompleteState.field);
        }
    }, true);

    // ---- Preview request plumbing (declared before the picker uses it) ----
    var previewTimers = {};
    var previewInFlight = {};
    var previewPending = {};

    function scheduleTemplatePreview(key) {
        if (previewTimers[key]) {
            clearTimeout(previewTimers[key]);
        }
        previewTimers[key] = setTimeout(function () {
            requestTemplatePreview(key, null);
        }, 320);
    }

    // ---- Status pills (Active / Off / Not set up), kept live while editing ----
    var STATUS_LABEL = { active: 'Active', off: 'Off', unset: 'Not set up' };

    function refreshStatus(key) {
        var html = document.getElementById(key + '_html_body');
        var toggle = document.querySelector('input[name="' + key + '_is_active"]');
        if (!html && !toggle) { return; } // read-only view: the pill is already right
        var state;
        if (html && !html.value.trim()) {
            state = 'unset';
        } else if (toggle && !toggle.checked) {
            state = 'off';
        } else {
            state = 'active';
        }
        document.querySelectorAll('[data-tpl-status="' + key + '"]').forEach(function (pill) {
            pill.classList.remove('tpl-pill--active', 'tpl-pill--off', 'tpl-pill--unset');
            pill.classList.add('tpl-pill--' + state);
            pill.textContent = STATUS_LABEL[state];
        });
        var opt = document.querySelector('#tpl-picker-select option[value="' + key + '"]');
        if (opt) { opt.textContent = (opt.getAttribute('data-name') || key) + ' (' + STATUS_LABEL[state] + ')'; }
    }

    document.querySelectorAll('.tpl-panel').forEach(function (panel) {
        var key = (panel.id || '').replace('panel-', '');
        if (!key) { return; }
        var toggle = panel.querySelector('input[name="' + key + '_is_active"]');
        if (toggle) { toggle.addEventListener('change', function () { refreshStatus(key); }); }
        var html = document.getElementById(key + '_html_body');
        if (html) { html.addEventListener('input', function () { refreshStatus(key); }); }
    });

    // ---- Picker: list, <select>, search, remembered choice ----
    var rows = document.querySelectorAll('.tpl-tab');
    var panels = document.querySelectorAll('.tpl-panel');
    var pickSelect = document.getElementById('tpl-picker-select');
    var searchBox = document.getElementById('tpl-search');
    var mainForm = document.getElementById('tpl-main-form');
    var formBaseAction = mainForm ? String(mainForm.getAttribute('action') || '').split('#')[0] : '';
    var STORE_KEY = 'rhTplOpen';
    var currentKey = '';

    function selectTemplate(key, remember) {
        var panel = document.getElementById('panel-' + key);
        if (!panel) { return false; }
        currentKey = key;
        rows.forEach(function (row) {
            var on = row.getAttribute('data-tpl') === key;
            row.classList.toggle('active', on);
            if (on) { row.setAttribute('aria-current', 'true'); } else { row.removeAttribute('aria-current'); }
        });
        panels.forEach(function (p) { p.hidden = (p !== panel); });
        if (pickSelect && pickSelect.value !== key) { pickSelect.value = key; }
        if (remember !== false) {
            // Remember the open template: the address, the form (so a save comes back here) and this browser tab.
            try { history.replaceState(null, '', '#tpl-' + key); } catch (e) { /* ignore */ }
            if (mainForm) { mainForm.setAttribute('action', formBaseAction + '#tpl-' + key); }
            try { sessionStorage.setItem(STORE_KEY, key); } catch (e) { /* ignore */ }
        }
        scheduleTemplatePreview(key);
        return true;
    }

    rows.forEach(function (row) {
        row.addEventListener('click', function () {
            selectTemplate(this.getAttribute('data-tpl'));
        });
    });
    if (pickSelect) {
        pickSelect.addEventListener('change', function () {
            selectTemplate(this.value);
        });
    }

    if (searchBox) {
        var emptyNote = document.getElementById('tpl-picker-empty');
        var applySearch = function () {
            var q = searchBox.value.trim().toLowerCase();
            var shown = 0;
            rows.forEach(function (row) {
                var hit = q === '' || (row.getAttribute('data-name') || '').indexOf(q) !== -1;
                row.hidden = !hit;
                if (hit) { shown++; }
            });
            document.querySelectorAll('.tpl-picker__group').forEach(function (group) {
                group.hidden = !group.querySelector('.tpl-tab:not([hidden])');
            });
            if (emptyNote) { emptyNote.hidden = shown !== 0; }
        };
        searchBox.addEventListener('input', applySearch);
    }

    function keyFromHash() {
        var m = /^#tpl-(.+)$/.exec(window.location.hash || '');
        return m ? decodeURIComponent(m[1]) : '';
    }
    window.addEventListener('hashchange', function () {
        var k = keyFromHash();
        if (k && k !== currentKey) { selectTemplate(k, false); }
    });

    // ---- Tag chips: insert at the cursor of the field last used (HTML body by default) ----
    var lastField = {};
    editFields.forEach(function (field) {
        field.addEventListener('focus', function () {
            lastField[field.id.replace(/_(subject|html_body|text_body)$/, '')] = field;
        });
    });

    document.querySelectorAll('.tpl-chip').forEach(function (chip) {
        chip.title = 'Click to insert at cursor';
        chip.addEventListener('keydown', function (ev) {
            if (ev.key === 'Enter' || ev.key === ' ') {
                ev.preventDefault();
                this.click();
            }
        });
        chip.addEventListener('click', function () {
            var key = this.dataset.tpl;
            var ta = lastField[key] || document.getElementById(key + '_html_body');
            if (ta) {
                var s = ta.selectionStart,
                    e = ta.selectionEnd,
                    txt = this.textContent;
                if (typeof s !== 'number') { s = e = ta.value.length; }
                ta.value = ta.value.substring(0, s) + txt + ta.value.substring(e);
                ta.selectionStart = ta.selectionEnd = s + txt.length;
                ta.focus();
                ta.dispatchEvent(new Event('input', { bubbles: true }));
            }
            chip.classList.add('is-inserted');
            setTimeout(function () {
                chip.classList.remove('is-inserted');
            }, 600);
        });
    });

    // ---- Build FormData for the current template ----
    function buildPreviewFd(key) {
        var fd = new FormData();
        fd.set('booking_email_template_preview', key);
        fd.set('_ajax_preview', '1');
        // Include all template fields so the server has full context
        document.querySelectorAll('.tpl-panel').forEach(function (panel) {
            panel.querySelectorAll('input, textarea, select').forEach(function (el) {
                if (el.name) {
                    if (el.type === 'checkbox') {
                        if (el.checked) fd.set(el.name, el.value);
                    } else fd.set(el.name, el.value);
                }
            });
        });
        return fd;
    }

    function parseJsonResponse(response) {
        return response.text().then(function (text) {
            var normalized = String(text || '').replace(/^﻿/, '').trim();
            var firstBrace = normalized.search(/[\[{]/);
            if (firstBrace > 0) {
                normalized = normalized.slice(firstBrace);
            }
            return JSON.parse(normalized);
        });
    }

    // ---- AJAX Preview (manual + live typing refresh) ----
    function renderPreviewResult(key, data) {
        var wrapper = document.getElementById('preview-wrapper-' + key);
        var subjectLbl = document.getElementById('preview-subject-' + key);
        var sendArea = document.getElementById('send-test-area-' + key);
        var feedback = document.getElementById('send-feedback-' + key);
        if (!wrapper) return;

        if (data.success) {
            wrapper.innerHTML = '';

            // Show warning banner if preview contains unsaved changes
            if (data.has_unsaved_changes) {
                var warningBanner = document.createElement('div');
                warningBanner.className = 'tpl-preview-warning';
                warningBanner.innerHTML = '<i class="fas fa-exclamation-triangle" aria-hidden="true"></i> <span><strong>Unsaved changes:</strong> this preview shows your current edits. Click <strong>Save All Templates</strong> to apply them to the emails and PDFs sent to guests.</span>';
                wrapper.appendChild(warningBanner);
            }

            var iframe = document.createElement('iframe');
            iframe.className = 'tpl-preview-frame';
            iframe.setAttribute('frameborder', '0');
            iframe.setAttribute('title', 'Email Preview');
            wrapper.appendChild(iframe);
            iframe.contentDocument.open();
            iframe.contentDocument.write(data.full_html);
            iframe.contentDocument.close();

            if (subjectLbl) subjectLbl.textContent = data.subject;
            if (sendArea) {
                sendArea.hidden = false;
                sendArea._previewHtml = data.html_body || '';
                sendArea._previewSubject = data.subject;
                sendArea._previewTextBody = data.text_body || '';
                sendArea._previewIsDocument = !!data.is_document;
                if (feedback) feedback.hidden = true;
            }
            // Plain text part: the typed one, or the one made from the HTML when none is typed.
            var plainBox = document.getElementById('plain-preview-' + key);
            var plainText = document.getElementById('plain-preview-text-' + key);
            if (plainBox && plainText) {
                plainText.textContent = data.text_body || '';
                plainBox.hidden = !!data.is_document || !(data.text_body || '');
            }
        } else {
            wrapper.innerHTML = '';
            var err = document.createElement('p');
            err.className = 'tpl-preview-error';
            err.innerHTML = '<i class="fas fa-exclamation-triangle" aria-hidden="true"></i> ';
            err.appendChild(document.createTextNode(data.error || 'Preview failed'));
            wrapper.appendChild(err);
        }
    }

    function requestTemplatePreview(key, triggerBtn) {
        var btn = triggerBtn || document.querySelector('.btn-ajax-preview[data-tpl="' + key + '"]');
        if (previewInFlight[key]) {
            previewPending[key] = true;
            return;
        }

        previewInFlight[key] = true;
        if (btn) {
            btn.disabled = true;
            btn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Loading…';
        }

        fetch(selfUrl, {
                method: 'POST',
                body: buildPreviewFd(key)
            })
            .then(function (r) {
                return parseJsonResponse(r);
            })
            .then(function (data) {
                renderPreviewResult(key, data || {
                    success: false,
                    error: 'Preview failed'
                });
            })
            .catch(function () {
                renderPreviewResult(key, {
                    success: false,
                    error: 'Request failed — check your connection.'
                });
            })
            .finally(function () {
                previewInFlight[key] = false;
                if (btn) {
                    btn.disabled = false;
                    btn.innerHTML = '<i class="fas fa-eye"></i> Preview';
                }

                if (previewPending[key]) {
                    previewPending[key] = false;
                    requestTemplatePreview(key, null);
                }
            });
    }

    document.querySelectorAll('.btn-ajax-preview').forEach(function (btn) {
        btn.addEventListener('click', function () {
            requestTemplatePreview(this.dataset.tpl, this);
        });
    });

    document.querySelectorAll('.tpl-panel').forEach(function (panel) {
        var key = (panel.id || '').replace('panel-', '');
        if (!key) return;
        panel.querySelectorAll('input[id$="_subject"], textarea[id$="_html_body"], textarea[id$="_text_body"]').forEach(function (field) {
            field.addEventListener('input', function () {
                if (!panel.hidden) {
                    scheduleTemplatePreview(key);
                }
            });
        });
    });

    // ---- Send Test Email ----
    function showFeedback(el, text, ok) {
        if (!el) return;
        el.textContent = text;
        el.className = 'tpl-send-feedback ' + (ok ? 'tpl-feedback-ok' : 'tpl-feedback-err');
        el.hidden = false;
    }

    document.querySelectorAll('.btn-send-test').forEach(function (btn) {
        btn.addEventListener('click', function () {
            var key = this.dataset.tpl;
            var sendArea = document.getElementById('send-test-area-' + key);
            var emailIn = document.getElementById('test-email-addr-' + key);
            var feedback = document.getElementById('send-feedback-' + key);
            var csrfField = document.querySelector('#tpl-main-form [name="csrf_token"]');
            var csrfToken = String(window._bsCsrf || (csrfField ? csrfField.value : '') || cfg.csrf || '').trim();
            var email = emailIn ? emailIn.value.trim() : '';

            if (!email || !/^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(email)) {
                showFeedback(feedback, 'Enter a valid email address.', false);
                return;
            }
            if (!csrfToken) {
                showFeedback(feedback, 'Security token missing. Refresh the page and try again.', false);
                return;
            }
            var html = sendArea ? sendArea._previewHtml : null;
            var subject = sendArea ? sendArea._previewSubject : 'Test Email';
            var textBody = sendArea ? (sendArea._previewTextBody || '') : '';
            var isDocument = sendArea ? !!sendArea._previewIsDocument : false;
            if (!html) {
                showFeedback(feedback, 'Generate a preview first.', false);
                return;
            }

            var fd = new FormData();
            fd.set('send_test_email', '1');
            fd.set('_ajax_send_test', '1');
            fd.set('csrf_token', csrfToken);
            fd.set('test_email_address', email);
            fd.set('test_subject', subject);
            fd.set('test_html', html);
            fd.set('test_text_body', textBody);
            fd.set('test_template_key', key);
            fd.set('test_is_document', isDocument ? '1' : '0');

            btn.disabled = true;
            btn.innerHTML = '<i class="fas fa-spinner fa-spin"></i>';

            fetch(selfUrl, {
                    method: 'POST',
                    body: fd
                })
                .then(function (r) {
                    return parseJsonResponse(r);
                })
                .then(function (data) {
                    showFeedback(feedback, data.success ? ('Sent to ' + email + '!') : ('Error: ' + (data.error || 'failed')), !!data.success);
                })
                .catch(function () {
                    showFeedback(feedback, 'Network error.', false);
                })
                .finally(function () {
                    btn.disabled = false;
                    btn.innerHTML = '<i class="fas fa-paper-plane"></i> Send';
                });
        });
    });

    // ---- Load Default ----
    document.querySelectorAll('.btn-load-default').forEach(function (btn) {
        btn.addEventListener('click', function () {
            var key = this.dataset.tpl;
            var def = tplDefaults[key];
            if (!def) return;
            if (!confirm('Reset "' + key.replace(/_/g, ' ') + '" to the built-in default? Current content will be replaced.')) return;
            var subjectEl = document.getElementById(key + '_subject');
            var htmlBodyEl = document.getElementById(key + '_html_body');
            if (subjectEl) subjectEl.value = def.subject;
            if (htmlBodyEl) htmlBodyEl.value = def.html;
            refreshStatus(key);
            scheduleTemplatePreview(key);
        });
    });

    // ---- Basic HTML format (wrap long lines at tags) ----
    document.querySelectorAll('.btn-format-html').forEach(function (btn) {
        btn.addEventListener('click', function () {
            var key = this.dataset.tpl;
            var ta = document.getElementById(key + '_html_body');
            if (!ta || !ta.value.trim()) return;
            // Simple: add newline after each closing tag
            var formatted = ta.value
                .replace(/>\s*</g, '>\n<')
                .replace(/\n{3,}/g, '\n\n')
                .trim();
            ta.value = formatted;
            scheduleTemplatePreview(key);
        });
    });

    // ---- Suggest wording (only when the page printed the button: the Assistant is connected and this person may use it) ----
    // Up to 3 new wordings for one email. The layout and tags are kept by the server; nothing changes in the editor until
    // "Use this", and nothing is saved until Save. Load Default is not touched.
    var assistCfg = cfg.assist || null;
    var assistUndo = {};
    var ASSIST_NONE = 'No suggestions this time. Your template is unchanged.';

    function assistCsrf() {
        if (window._bsCsrf) { return String(window._bsCsrf); }
        var f = document.querySelector('input[name="csrf_token"]');
        return f ? f.value : String(cfg.csrf || '');
    }

    function assistEl(tag, cls, text) {
        var el = document.createElement(tag);
        if (cls) { el.className = cls; }
        if (text !== undefined && text !== null) { el.textContent = text; }
        return el;
    }

    function assistButton(label, icon, cls) {
        var b = assistEl('button', 'tpl-assist__action' + (cls ? ' ' + cls : ''));
        b.type = 'button';
        if (icon) {
            var i = assistEl('i', 'fas ' + icon);
            i.setAttribute('aria-hidden', 'true');
            b.appendChild(i);
            b.appendChild(document.createTextNode(' '));
        }
        b.appendChild(document.createTextNode(label));
        return b;
    }

    function assistOut(key, mount) {
        var out = document.getElementById('tpl-assist-out-' + key);
        if (out) { return out; }
        out = assistEl('div', 'tpl-assist-out');
        out.id = 'tpl-assist-out-' + key;
        out.setAttribute('aria-live', 'polite');
        var bar = mount.closest('.tpl-toolbar') || mount;
        bar.insertAdjacentElement('afterend', out);
        return out;
    }

    function assistSetField(id, value) {
        var el = document.getElementById(id);
        if (!el) { return; }
        el.value = value;
        el.dispatchEvent(new Event('input', { bubbles: true }));
    }

    function assistPlainNote(key, out) {
        var old = out.querySelector('.tpl-assist-out__plain');
        if (old) { old.remove(); }
        if (!assistUndo[key]) { return; } // only once a suggestion is in the editor
        var tb = document.getElementById(key + '_text_body');
        if (!tb || !tb.value.trim()) { return; }
        var note = assistEl('div', 'tpl-assist-out__plain');
        note.appendChild(assistEl('span', null, 'Your plain text version still has the old wording, so it no longer matches this email. Clear it and it is made from the new wording automatically.'));
        var clr = assistButton('Clear plain text', 'fa-eraser');
        clr.addEventListener('click', function () {
            assistSetField(key + '_text_body', '');
            note.remove();
        });
        note.appendChild(clr);
        var cards = out.querySelector('.tpl-assist-out__cards');
        out.insertBefore(note, cards || null);
    }

    function assistShowBack(key, out) {
        var bar = out.querySelector('.tpl-assist-out__undo');
        if (!assistUndo[key]) {
            if (bar) { bar.remove(); }
            return;
        }
        if (bar) { return; }
        bar = assistEl('div', 'tpl-assist-out__undo');
        bar.appendChild(assistEl('span', null, 'A suggestion is in the editor. Nothing is saved until you press Save.'));
        var back = assistButton('Back to what I had', 'fa-rotate-left');
        back.addEventListener('click', function () {
            var u = assistUndo[key];
            if (!u) { return; }
            assistSetField(key + '_subject', u.subject);
            assistSetField(key + '_html_body', u.html);
            assistSetField(key + '_text_body', u.text);
            delete assistUndo[key];
            refreshStatus(key);
            var p = out.querySelector('.tpl-assist-out__plain');
            if (p) { p.remove(); }
            assistShowBack(key, out);
        });
        bar.appendChild(back);
        var head = out.querySelector('.tpl-assist-out__status');
        out.insertBefore(bar, head ? head.nextSibling : out.firstChild);
    }

    function assistPreview(key, v, btn, out) {
        var fd = buildPreviewFd(key);
        fd.set(key + '_subject', v.subject);
        fd.set(key + '_html_body', v.html);
        btn.disabled = true;
        fetch(selfUrl, { method: 'POST', body: fd, credentials: 'same-origin' })
            .then(function (r) { return parseJsonResponse(r); })
            .then(function (data) {
                renderPreviewResult(key, data || { success: false, error: 'Preview failed' });
                var st = out.querySelector('.tpl-assist-out__status');
                if (st) { st.textContent = 'The preview shows this suggestion. Your editor is unchanged until you press Use this.'; }
            })
            .catch(function () { renderPreviewResult(key, { success: false, error: 'Request failed — check your connection.' }); })
            .finally(function () { btn.disabled = false; });
    }

    function assistUse(key, v, out) {
        var s = document.getElementById(key + '_subject');
        var h = document.getElementById(key + '_html_body');
        var t = document.getElementById(key + '_text_body');
        if (!h) { return; }
        if (!assistUndo[key]) {
            assistUndo[key] = { subject: s ? s.value : '', html: h.value, text: t ? t.value : '' };
        }
        assistSetField(key + '_subject', v.subject);
        assistSetField(key + '_html_body', v.html);
        refreshStatus(key);
        var st = out.querySelector('.tpl-assist-out__status');
        if (st) { st.textContent = ''; }
        assistShowBack(key, out);
        assistPlainNote(key, out);
    }

    function assistRender(key, out, data) {
        out.innerHTML = '';
        var head = assistEl('div', 'tpl-assist-out__head');
        head.appendChild(assistEl('strong', null, 'Ideas from the Assistant'));
        var help = assistEl('i', 'help', '?');
        help.setAttribute('data-tip', 'Up to 3 new wordings for this email, with the same layout and tags. Only the wording, the subject and the tag names were sent; never a guest, a booking or a link. Preview shows one without changing your editor; Use this puts it in the editor; nothing is saved until you press Save.');
        head.appendChild(help);
        var hide = assistButton('Hide', 'fa-xmark', 'tpl-assist__action--quiet');
        hide.addEventListener('click', function () { out.hidden = true; });
        head.appendChild(hide);
        out.appendChild(head);
        out.appendChild(assistEl('p', 'tpl-assist-out__status'));
        assistShowBack(key, out);
        var versions = (data && Array.isArray(data.versions)) ? data.versions : [];
        if (!versions.length) {
            out.appendChild(assistEl('p', 'tpl-assist-out__empty', (data && data.message) || ASSIST_NONE));
            out.hidden = false;
            return;
        }
        var cards = assistEl('div', 'tpl-assist-out__cards');
        versions.slice(0, 3).forEach(function (v, i) {
            if (!v || typeof v.html !== 'string' || typeof v.subject !== 'string') { return; }
            var card = assistEl('div', 'tpl-assist-card');
            card.appendChild(assistEl('div', 'tpl-assist-card__label', 'Suggestion ' + (i + 1)));
            card.appendChild(assistEl('div', 'tpl-assist-card__subject', v.subject));
            card.appendChild(assistEl('p', 'tpl-assist-card__text', v.preview_text || ''));
            if (Array.isArray(v.missing_tags) && v.missing_tags.length) {
                card.appendChild(assistEl('p', 'tpl-assist-card__warn', 'Leaves out: ' + v.missing_tags.join(', ')));
            }
            var acts = assistEl('div', 'tpl-assist-card__actions');
            var pv = assistButton('Preview', 'fa-eye');
            pv.addEventListener('click', function () { assistPreview(key, v, pv, out); });
            var use = assistButton('Use this', 'fa-check', 'tpl-assist__action--main');
            use.addEventListener('click', function () { assistUse(key, v, out); });
            acts.appendChild(pv);
            acts.appendChild(use);
            card.appendChild(acts);
            cards.appendChild(card);
        });
        out.appendChild(cards);
        out.hidden = false;
        assistPlainNote(key, out);
    }

    if (assistCfg && assistCfg.url) {
        document.querySelectorAll('[data-tpl-suggest]').forEach(function (btn) {
            var key = btn.getAttribute('data-tpl-suggest');
            var label = btn.innerHTML;
            btn.addEventListener('click', function () {
                var subj = document.getElementById(key + '_subject');
                var html = document.getElementById(key + '_html_body');
                if (!html) { return; }
                var out = assistOut(key, btn.closest('.tpl-assist') || btn);
                if (!html.value.trim()) {
                    assistRender(key, out, { versions: [], message: 'There is no wording yet. Press Load Default first, then ask for suggestions.' });
                    return;
                }
                var fd = new FormData();
                fd.set('action', 'get_suggestions');
                fd.set('csrf_token', assistCsrf());
                fd.set('template', key);
                fd.set('subject', subj ? subj.value : '');
                fd.set('html_body', html.value);
                btn.disabled = true;
                btn.setAttribute('aria-busy', 'true');
                btn.innerHTML = '<i class="fas fa-spinner fa-spin" aria-hidden="true"></i> Getting ideas…';
                fetch(assistCfg.url, {
                        method: 'POST',
                        body: fd,
                        credentials: 'same-origin',
                        headers: { 'X-Requested-With': 'XMLHttpRequest' }
                    })
                    .then(function (r) {
                        if (!r.ok) { return { versions: [], message: r.status === 403 ? 'Your session has changed. Reload the page.' : ASSIST_NONE }; }
                        return parseJsonResponse(r);
                    })
                    .then(function (data) { assistRender(key, out, data); })
                    .catch(function () { assistRender(key, out, { versions: [], message: ASSIST_NONE }); })
                    .finally(function () {
                        btn.disabled = false;
                        btn.removeAttribute('aria-busy');
                        btn.innerHTML = label;
                    });
            });
        });
    }

    // ---- Open the remembered template (address, then this browser tab), else the first ----
    var startKey = keyFromHash();
    if (!startKey || !document.getElementById('panel-' + startKey)) {
        try { startKey = sessionStorage.getItem(STORE_KEY) || ''; } catch (e) { startKey = ''; }
    }
    if (!startKey || !document.getElementById('panel-' + startKey)) {
        startKey = rows.length ? rows[0].getAttribute('data-tpl') : '';
    }
    if (startKey) {
        // Only write the address when the person chose one: a plain first load keeps the URL clean.
        selectTemplate(startKey, false);
        if (mainForm && keyFromHash() === startKey) {
            mainForm.setAttribute('action', formBaseAction + '#tpl-' + startKey);
        }
    }
})();
