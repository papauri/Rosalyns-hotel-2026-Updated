/**
 * Import (stock lists) - admin/data-import.php.
 * Every piece of text that comes from the uploaded file (sheet names, cells, item names, remarks) is put on the page with
 * textContent, never as HTML. Statuses are never decided here: after every answer the whole summary is fetched again from the server.
 * Loaded once; window.rhDataImport.init(root) is called by the inline script on each (in-place) visit to the page.
 */
(function () {
    'use strict';
    if (window.rhDataImport) { return; }

    var PER_PAGE = 100;
    var FIELD_LABEL = {
        item_name: 'Item name', batch: 'Batch', qty_primary: 'Balance (bar / kitchen)', qty_secondary: 'Balance (stores)', qty_single: 'Balance',
        unit: 'Unit', expiry: 'Expiry date', max_level: 'Par / maximum level', min_level: 'Reorder level', unit_price: 'Unit price',
        remarks: 'Remarks', category: 'Category'
    };
    var DIFF_LABEL = { category: 'Category', cost: 'Cost per unit', par: 'Par level', pack: 'Pack size', quantity: 'Opening stock quantity' };
    var STATUS_LABEL = { 'ready': 'Ready', 'needs-answer': 'Needs answer', 'skipped': 'Skipped', 'blocked': 'Blocked' };
    var STATUS_RANK = { 'blocked': 0, 'needs-answer': 1, 'ready': 2, 'skipped': 3 };
    var JOB_STATUS = { uploaded: 'In progress', analysed: 'In progress', reviewing: 'In progress', confirmed: 'In progress', committed: 'Imported', failed: 'Could not be read' };

    function h(tag, cls, text) {
        var e = document.createElement(tag);
        if (cls) { e.className = cls; }
        if (text !== undefined && text !== null) { e.textContent = String(text); }
        return e;
    }
    function add(parent) {
        for (var i = 1; i < arguments.length; i++) {
            var c = arguments[i];
            if (c === null || c === undefined || c === false) { continue; }
            parent.appendChild(typeof c === 'string' ? document.createTextNode(c) : c);
        }
        return parent;
    }
    function clear(e) { while (e.firstChild) { e.removeChild(e.firstChild); } return e; }
    function num(n, max) {
        if (n === null || n === undefined || n === '') { return '—'; }
        var x = Number(n);
        if (!isFinite(x)) { return String(n); }
        return x.toLocaleString('en-US', { maximumFractionDigits: max === undefined ? 4 : max });
    }
    function src(ref, prefix) {
        if (!ref) { return null; }
        var s = h('span', 'di-src', (prefix || '') + ref);
        s.title = 'Taken from ' + ref + ' in your file';
        return s;
    }
    // Status pills are the shared admin .badge (amber = needs attention, green = good, red = stopped, grey = not used)
    var BADGE = { 'ready': 'success', 'needs-answer': 'pending', 'skipped': 'neutral', 'blocked': 'failed',
        'import': 'success', 'ask': 'pending', 'skip': 'neutral',
        'uploaded': 'pending', 'analysed': 'pending', 'reviewing': 'pending', 'confirmed': 'pending', 'committed': 'success', 'failed': 'failed' };
    function badge(kind, text) { return h('span', 'badge badge-' + (BADGE[kind] || 'neutral'), text); }
    function pill(status) { return badge(status, STATUS_LABEL[status] || status); }
    function helpLine(text) { return h('p', 'di-help', text); }
    function card(label, value, helper, mod) {
        var c = h('div', 'di-card' + (mod ? ' di-card--' + mod : ''));
        add(c, h('span', 'di-card-l', label), h('span', 'di-card-v', value), h('span', 'di-card-h', helper));
        return c;
    }
    function fileOk(name) { return /\.(xlsx|xlsm|csv|tsv)$/i.test(name || ''); }

    function init(root) {
        var csrf = root.getAttribute('data-csrf');
        var page = root.getAttribute('data-page') || 'data-import.php';
        var canReimport = root.getAttribute('data-can-reimport') === '1';
        var maxText = root.getAttribute('data-max-text') || '';
        var msgEl = root.querySelector('#diMsg');
        var steps = root.querySelector('#diSteps');
        var panels = { 1: root.querySelector('#diStep1'), 2: root.querySelector('#diStep2'), 3: root.querySelector('#diStep3'), 4: root.querySelector('#diStep4') };
        var fileInput = root.querySelector('#diFile');
        var uploadBtn = root.querySelector('#diUpload');
        var dropEl = root.querySelector('#diDrop');
        var nameEl = root.querySelector('#diFileName');
        var mineList = root.querySelector('#diMineList');
        var S = { job: 0, sum: null, result: null, step: 1, reach: 1, filter: 'all', pages: {}, busy: false, file: null, chain: Promise.resolve() };

        // ---------- plumbing ----------
        function post(action, data, extra) {
            var fd = new FormData();
            fd.append('csrf_token', csrf);
            fd.append('action', action);
            Object.keys(data || {}).forEach(function (k) {
                var v = data[k];
                if (Array.isArray(v)) { v.forEach(function (x) { fd.append(k + '[]', x); }); } else if (v !== undefined && v !== null) { fd.append(k, v); }
            });
            return fetch(page, { method: 'POST', body: fd, credentials: 'same-origin', headers: { 'X-Requested-With': 'XMLHttpRequest' } })
                .then(function (r) { return r.text().then(function (t) { try { return JSON.parse(t); } catch (e) { return { ok: false, error: 'The server sent an unexpected reply. Reload the page and try again.' }; } }); })
                .catch(function () { return { ok: false, error: 'Could not reach the server. Check your connection and try again.' }; });
        }
        function msg(text, kind) {
            if (!text) { msgEl.hidden = true; clear(msgEl); return; }
            clear(msgEl);
            msgEl.className = 'di-msg di-msg--' + (kind || 'error');
            add(msgEl, h('i', 'fas ' + (kind === 'ok' ? 'fa-check-circle' : 'fa-exclamation-circle')), ' ', h('span', null, text));
            msgEl.hidden = false;
            try { msgEl.scrollIntoView({ block: 'nearest' }); } catch (e) { /* ignore */ }
        }
        function setUrl(job) {
            try {
                var u = new URL(window.location.href);
                if (job) { u.searchParams.set('job', job); } else { u.searchParams.delete('job'); }
                window.history.replaceState(window.history.state, '', u.pathname + u.search);
            } catch (e) { /* ignore */ }
        }
        function scrollTo(el) {
            if (!el) { return; }
            if (window.rhAnchors && window.rhAnchors.scrollToResults) {
                if (window.rhAnchors.scrollToResults(el, { force: true })) { return; }
            }
            el.scrollIntoView({ block: 'start', behavior: 'smooth' });
        }

        // ---------- steps ----------
        function showStep(n) {
            S.step = n;
            S.reach = Math.max(S.reach, n);
            [1, 2, 3, 4].forEach(function (i) { panels[i].hidden = (i !== n); });
            Array.prototype.forEach.call(steps.children, function (li) {
                var i = Number(li.getAttribute('data-step'));
                li.classList.toggle('is-current', i === n);
                li.classList.toggle('is-done', i < n || (n === 4 && S.result && i <= 4));
                var can = (S.sum || S.result) && i <= S.reach && i > 1 && !S.result;
                li.classList.toggle('is-link', !!can || i === 1);
                li.setAttribute('tabindex', (can || i === 1) ? '0' : '-1');
                if (i === n) { li.setAttribute('aria-current', 'step'); } else { li.removeAttribute('aria-current'); }
            });
            msg('');
            if (n === 2) { renderFound(); }
            if (n === 3) { renderReview(); }
            if (n === 4) { if (S.result) { renderResult(); } else { renderConfirm(); } }
            window.scrollTo({ top: 0 });
        }
        function stepClick(e) {
            var li = e.target.closest('li[data-step]');
            if (!li) { return; }
            var i = Number(li.getAttribute('data-step'));
            if (i === 1) { S.result = null; showStep(1); return; }
            if (S.result) { return; }
            if (S.sum && i <= S.reach) { showStep(i); }
        }
        steps.addEventListener('click', stepClick);
        steps.addEventListener('keydown', function (e) { if (e.key === 'Enter' || e.key === ' ') { e.preventDefault(); stepClick(e); } });

        // ---------- step 1: upload ----------
        function chosen(file) {
            S.file = file || null;
            nameEl.textContent = file ? file.name + ' (' + (file.size >= 1048576 ? (file.size / 1048576).toFixed(1) + ' MB' : Math.max(1, Math.round(file.size / 1024)) + ' KB') + ')' : '';
            uploadBtn.disabled = !file;
            if (file && !fileOk(file.name)) {
                var ext = (file.name.split('.').pop() || '').toLowerCase();
                if (ext === 'xls') { msg('This is an old Excel file (.xls). Open it in Excel, use Save As > Excel Workbook (.xlsx), then upload that.'); }
                else if (ext === 'doc' || ext === 'docx' || ext === 'pdf') { msg('Word and PDF files are not supported yet. Please upload the Excel (.xlsx) or CSV version of the list.'); }
                else { msg('This type of file cannot be imported. Please upload an Excel (.xlsx) or CSV file.'); }
            } else { msg(''); }
        }
        fileInput.addEventListener('change', function () { chosen(fileInput.files && fileInput.files[0]); });
        ['dragenter', 'dragover'].forEach(function (ev) { dropEl.addEventListener(ev, function (e) { e.preventDefault(); dropEl.classList.add('is-over'); }); });
        ['dragleave', 'drop'].forEach(function (ev) { dropEl.addEventListener(ev, function (e) { e.preventDefault(); dropEl.classList.remove('is-over'); }); });
        dropEl.addEventListener('drop', function (e) {
            var f = e.dataTransfer && e.dataTransfer.files && e.dataTransfer.files[0];
            if (f) { try { var dt = new DataTransfer(); dt.items.add(f); fileInput.files = dt.files; } catch (x) { /* keep S.file */ } chosen(f); }
        });
        dropEl.addEventListener('keydown', function (e) { if (e.key === 'Enter' || e.key === ' ') { e.preventDefault(); fileInput.click(); } });
        uploadBtn.addEventListener('click', function () {
            if (!S.file || S.busy) { return; }
            if (!fileOk(S.file.name)) { chosen(S.file); return; }
            S.busy = true;
            uploadBtn.disabled = true;
            var old = uploadBtn.innerHTML;
            uploadBtn.textContent = 'Reading your file…';
            var fd = new FormData();
            fd.append('csrf_token', csrf);
            fd.append('action', 'upload');
            fd.append('file', S.file, S.file.name);
            var re = root.querySelector('#diReimport');
            if (canReimport && re && re.checked) { fd.append('allow_reimport', '1'); }
            fetch(page, { method: 'POST', body: fd, credentials: 'same-origin', headers: { 'X-Requested-With': 'XMLHttpRequest' } })
                .then(function (r) { return r.text().then(function (t) { try { return JSON.parse(t); } catch (e) { return { ok: false, error: 'The server sent an unexpected reply. The file may be bigger than the server accepts (' + maxText + ').' }; } }); })
                .catch(function () { return { ok: false, error: 'Could not reach the server. Check your connection and try again.' }; })
                .then(function (r) {
                    S.busy = false;
                    uploadBtn.innerHTML = old;
                    uploadBtn.disabled = !S.file;
                    if (!r.ok) { msg(r.error || 'The file could not be read.'); return; }
                    fileInput.value = '';
                    chosen(null);
                    S.reach = 1;
                    loadJob(r.job, 2);
                });
        });

        // ---------- jobs ----------
        function loadJob(id, goStep) {
            S.job = id;
            S.result = null;
            return post('summary', { job: id }).then(function (r) {
                if (r.state === 'committed') {
                    S.sum = null; S.result = r.result || {}; S.file = null; setUrl(id); S.resultFile = r.file; showStep(4); loadMine(); return;
                }
                if (!r.ok) {
                    S.job = 0; S.sum = null; setUrl(0); showStep(1); msg(r.error || 'That import cannot be opened.'); loadMine(); return;
                }
                S.sum = r;
                setUrl(id);
                if (!goStep) { goStep = r.job && r.job.status === 'reviewing' ? 3 : 2; }
                showStep(goStep);
                loadMine();
            });
        }
        function refresh(fresh) {
            // answers change statuses, counts and even question ids: always take the whole picture from the server again
            // (the answer reply carries it; a separate fetch is only the fallback)
            var y = window.pageYOffset;
            return (fresh && fresh.ok ? Promise.resolve(fresh) : post('summary', { job: S.job })).then(function (r) {
                if (!r.ok) { msg(r.error || 'Could not refresh.'); return; }
                S.sum = r;
                if (S.step === 3) { renderReview(); } else if (S.step === 2) { renderFound(); } else if (S.step === 4) { renderConfirm(); }
                window.scrollTo({ top: y });
            });
        }
        function loadMine() {
            post('list', {}).then(function (r) {
                clear(mineList);
                if (!r.ok || !r.jobs || !r.jobs.length) { add(mineList, h('p', 'di-muted', r.ok ? 'No imports yet.' : (r.error || ''))); return; }
                r.jobs.forEach(function (j) {
                    var row = h('div', 'di-job');
                    var main = h('div', 'di-job-main');
                    add(main, h('strong', 'di-job-name', j.file), h('span', 'di-job-meta', j.created_at + (j.mine ? '' : ' · ' + j.by)));
                    var st = badge(j.status, JOB_STATUS[j.status] || j.status);
                    var det = '';
                    if (j.status === 'committed') { det = (j.created || 0) + ' created, ' + (j.updated || 0) + ' updated'; }
                    if (j.status === 'failed' && j.error) { det = j.error; }
                    var acts = h('div', 'di-job-acts');
                    var open = h('button', 'btn-secondary di-btn', j.status === 'committed' ? 'View result' : 'Open');
                    open.type = 'button';
                    if (j.status !== 'failed') { open.addEventListener('click', function () { loadJob(j.id); }); acts.appendChild(open); }
                    if (j.status !== 'committed' && (j.mine || canReimport)) {
                        var cx = h('button', 'btn-secondary di-btn di-btn--quiet', 'Cancel');
                        cx.type = 'button';
                        cx.addEventListener('click', function () { cancelJob(j.id, false); });
                        acts.appendChild(cx);
                    }
                    add(row, main, st, det ? h('span', 'di-job-det', det) : null, acts);
                    mineList.appendChild(row);
                });
            });
        }
        function cancelJob(id, fromWizard) {
            var ask = 'Nothing has been saved to your stock, and the uploaded copy will be deleted.';
            var go = (window.AdminConfirm && typeof window.AdminConfirm.request === 'function')
                ? window.AdminConfirm.request({ title: 'Cancel this import?', message: ask, confirmText: 'Cancel import', tone: 'danger' })
                : Promise.resolve(window.confirm('Cancel this import? ' + ask));
            go.then(function (yes) { if (yes) { doCancel(id); } });
        }
        function doCancel(id) {
            post('cancel', { job: id }).then(function (r) {
                if (!r.ok) { msg(r.error || 'Could not cancel.'); return; }
                if (id === S.job) { S.job = 0; S.sum = null; S.result = null; S.reach = 1; setUrl(0); showStep(1); }
                msg('The import was cancelled and the uploaded copy deleted.', 'ok');
                loadMine();
            });
        }

        // ---------- questions ----------
        function qWhere(q) {
            var bits = [];
            if (q.sheet) { bits.push(q.sheet); }
            if (q.row) { bits.push('row ' + q.row); }
            return bits.join(' · ');
        }
        function questionCard(q) {
            var answered = q.answer !== null && q.answer !== undefined && q.answer !== '';
            var c = h('div', 'di-q' + (answered ? ' is-answered' : (q.blocking ? ' is-open' : '')) + (q.bad ? ' is-bad' : ''));
            c.id = 'diq-' + q.id;
            var head = h('div', 'di-q-head');
            add(head, h('span', 'di-q-state', answered ? 'Answered' : (q.blocking ? 'Needs your answer' : 'Optional')), qWhere(q) ? h('span', 'di-q-where', qWhere(q)) : null);
            add(c, head, h('p', 'di-q-text', q.text));
            if (q.cells && q.cells.length) {
                var cl = h('p', 'di-q-cells', 'Cells: ');
                q.cells.forEach(function (ref, i) { cl.appendChild(h('span', 'di-src', ref)); if (i < q.cells.length - 1) { cl.appendChild(document.createTextNode(' ')); } });
                c.appendChild(cl);
            }
            if (q.auto_filled && answered) { c.appendChild(h('p', 'di-q-note', 'Remembered from an earlier import. Change it if it is wrong.')); }
            var cv = {};
            (q.conflict_values || []).forEach(function (v) { cv[v.key] = v; });
            var checked = answered ? q.answer : (q.default || null);
            var opts = h('div', 'di-opts');
            var packBox = null;
            if (q.options.length > 8) {
                // a long list (every unit word the hotel knows) is a drop-down, not 100 radio buttons
                var sel = h('select', 'di-select');
                sel.setAttribute('aria-label', 'Choose an answer');
                var ph = h('option', null, 'Choose…'); ph.value = ''; sel.appendChild(ph);
                q.options.forEach(function (o) { var op = h('option', null, o.label); op.value = o.key; if (checked === o.key) { op.selected = true; } sel.appendChild(op); });
                sel.addEventListener('change', function () { if (sel.value !== '') { save(q, sel.value, null, c); } });
                opts.appendChild(sel);
            }
            (q.options.length > 8 ? [] : q.options).forEach(function (o) {
                var lab = h('label', 'di-opt');
                var r = document.createElement('input');
                r.type = 'radio'; r.name = 'q_' + q.id; r.value = o.key;
                if (checked === o.key) { r.checked = true; }
                add(lab, r, h('span', 'di-opt-t', o.label));
                if (cv[o.key]) { lab.appendChild(src(cv[o.key].source, 'from ')); lab.classList.add('di-opt--compare'); }
                opts.appendChild(lab);
                if (o.key === 'per-case' && q.type === 'case-price') {
                    packBox = h('div', 'di-pack');
                    var pi = document.createElement('input');
                    pi.type = 'number'; pi.min = '2'; pi.max = '100000'; pi.step = '1'; pi.inputMode = 'numeric'; pi.className = 'di-pack-in';
                    pi.setAttribute('aria-label', 'Units in the case');
                    add(packBox, h('span', null, 'Units in one case/pack: '), pi, h('button', 'btn-primary di-btn', 'Save'));
                    packBox.hidden = checked !== 'per-case';
                    if (q.pack_size) { pi.value = q.pack_size; }
                    var sv = packBox.querySelector('button');
                    sv.type = 'button';
                    sv.addEventListener('click', function () { save(q, 'per-case', pi.value, c); });
                    pi.addEventListener('keydown', function (e) { if (e.key === 'Enter') { e.preventDefault(); save(q, 'per-case', pi.value, c); } });
                    q._pi = pi;
                }
                r.addEventListener('change', function () {
                    if (q.type === 'case-price') {
                        if (o.key === 'per-case') { packBox.hidden = false; q._pi.focus(); return; }
                        packBox.hidden = true;
                    }
                    // Arrow keys walk through the options and fire a change each time: wait until the choice settles.
                    window.clearTimeout(q._saveTimer);
                    q._saveTimer = window.setTimeout(function () { save(q, o.key, null, c); }, 450);
                });
            });
            c.appendChild(opts);
            if (packBox) { c.appendChild(packBox); }
            c.appendChild(h('span', 'di-q-saved', ''));
            return c;
        }
        function save(q, key, pack, cardEl) {
            if (q.type === 'case-price' && key === 'per-case') {
                if (!/^\d+$/.test(String(pack || '').trim()) || Number(pack) < 2 || Number(pack) > 100000) { msg('Enter how many units are in the case (a whole number from 2 to 100000).'); return; }
            }
            cardEl.classList.add('is-saving');
            Array.prototype.forEach.call(cardEl.querySelectorAll('input,button'), function (x) { x.disabled = true; });
            var d = { job: S.job, question: q.id, answer: key };
            if (pack !== null && pack !== undefined) { d.pack_size = String(pack).trim(); }
            S.chain = S.chain.then(function () { return post('answer', d); }).then(function (r) {
                if (!r.ok) {
                    msg(r.error || 'That answer could not be saved.');
                    cardEl.classList.remove('is-saving');
                    Array.prototype.forEach.call(cardEl.querySelectorAll('input,button'), function (x) { x.disabled = false; });
                    return;
                }
                msg('');
                refresh(r.summary);
            });
        }

        // ---------- step 2: what we found ----------
        function isStructural(q) { return (!q.row_uids || !q.row_uids.length) && (q.row === null || q.row === undefined); }
        function renderFound() {
            var p = clear(panels[2]);
            var s = S.sum, t = s.totals || {};
            add(p, h('h3', 'di-h', 'Step 2 · What we found in your file'));
            add(p, helpLine('This is how the file was read. Nothing has been saved yet. Check each sheet: which columns were understood, which were left out, and which rows are headings or totals rather than stock items.'));
            var line = h('p', 'di-file-line');
            add(line, h('i', 'fas fa-file-excel'), ' ', h('strong', null, s.file && s.file.name ? s.file.name : s.job.file), s.file && s.file.size ? ' · ' + (s.file.size >= 1048576 ? (s.file.size / 1048576).toFixed(1) + ' MB' : Math.max(1, Math.round(s.file.size / 1024)) + ' KB') : '');
            p.appendChild(line);
            (s.warnings || []).forEach(function (w) { p.appendChild(h('p', 'di-warn', typeof w === 'string' ? w : (w.text || ''))); });
            var cards = h('div', 'di-cards');
            add(cards,
                card('Sheets read', num(t.sheets_total, 0), 'Tabs (sheets) in the workbook, including ones we skip.'),
                card('Sheets to import', num(t.sheets_imported, 0), 'Sheets that look like a stock list and will be read.'),
                card('Items found', num(t.items_read, 0), 'Stock item rows read from those sheets, before any checking.'),
                card('Categories', num(t.categories, 0), 'Heading rows (like "BEERS") that group the items below them. They are not items.'),
                card('Total lines left out', num(t.skipped_totals_rows, 0), 'Rows such as "TOTAL" or "GRAND TOTAL". They are never imported.'),
                card('Columns not imported', num(t.ignored_derived_columns, 0), 'Calculated columns (totals, days to expiry, status). We take the real numbers, not these.'));
            p.appendChild(cards);

            var open = {};
            s.questions.forEach(function (q) { if (isStructural(q)) { (open[q.sheet] = open[q.sheet] || []).push(q); } });
            s.sheets.forEach(function (sh) {
                var sc = h('div', 'di-sheet di-sheet--' + sh.action);
                var head = h('div', 'di-sheet-head');
                var label = sh.action === 'import' ? 'Will be read' : (sh.action === 'ask' ? 'Needs your answer' : 'Skipped');
                add(head, h('h4', 'di-sheet-name', sh.name), badge(sh.action, label));
                sc.appendChild(head);
                if (sh.reason) { sc.appendChild(h('p', 'di-sheet-why', (sh.action === 'import' ? 'Note: ' : 'Why: ') + sh.reason)); }
                if (sh.action !== 'import' && !sh.reason) { sc.appendChild(h('p', 'di-sheet-why', sh.hidden ? 'Why: this sheet is hidden in the workbook.' : 'Why: this sheet does not look like a stock list.')); }
                if (sh.action === 'import') {
                    var c = sh.counts || {};
                    var bits = [num(c.items, 0) + ' item' + (c.items === 1 ? '' : 's'), num((sh.categories || []).length, 0) + ' categor' + ((sh.categories || []).length === 1 ? 'y' : 'ies'),
                        num(c.totals, 0) + ' total line' + (c.totals === 1 ? '' : 's') + ' left out'];
                    if (sh.header_row) { bits.push('column headings on row ' + sh.header_row); }
                    sc.appendChild(h('p', 'di-sheet-counts', bits.join(' · ')));
                    if ((sh.categories || []).length) {
                        var cats = h('p', 'di-cats');
                        cats.appendChild(h('span', 'di-cats-l', 'Categories: '));
                        sh.categories.forEach(function (ct) { var chip = h('span', 'di-chipcat', ct.name + ' (' + ct.items + ')'); chip.title = 'Heading in ' + ct.ref; cats.appendChild(chip); });
                        sc.appendChild(cats);
                    }
                    sc.appendChild(mappingTable(sh));
                    if ((sh.non_item_rows || []).length) {
                        var d = h('details', 'di-details');
                        d.appendChild(h('summary', null, 'Rows that are headings or totals, not items (' + sh.non_item_rows.length + ')'));
                        var ul = h('ul', 'di-nonitems');
                        sh.non_item_rows.forEach(function (r) {
                            var li = h('li');
                            add(li, h('span', 'di-src', r.source), ' ', h('strong', null, r.label), ' — ', r.kind === 'total' ? 'Total line: not imported.' : (r.kind === 'section' ? 'Category heading: items below belong to it.' : (r.reason || 'Not imported.')));
                            ul.appendChild(li);
                        });
                        d.appendChild(ul);
                        sc.appendChild(d);
                    }
                }
                (open[sh.name] || []).forEach(function (q) { sc.appendChild(questionCard(q)); });
                p.appendChild(sc);
            });
            var nav = h('div', 'di-actions');
            var cancel = h('button', 'btn-secondary di-btn di-btn--quiet', 'Cancel this import');
            cancel.type = 'button';
            cancel.addEventListener('click', function () { cancelJob(S.job, true); });
            var next = h('button', 'btn-primary di-btn');
            next.type = 'button';
            add(next, 'Continue to review ', h('i', 'fas fa-arrow-right'));
            next.addEventListener('click', function () { showStep(3); });
            add(nav, cancel, next);
            p.appendChild(nav);
        }
        function mappingTable(sh) {
            var wrap = h('div', 'di-tablewrap');
            var tb = h('table', 'di-table di-map');
            var thead = h('thead'), tr = h('tr');
            [['Column', 'The column letter in your sheet.'], ['Heading in your file', 'The words written at the top of that column.'], ['Read as', 'What this column becomes in the stock list.'], ['What happens', 'Whether the column is used or left out, and why.']].forEach(function (c) {
                var th = h('th'); add(th, h('span', null, c[0]), h('small', 'di-th-help', c[1])); tr.appendChild(th);
            });
            thead.appendChild(tr); tb.appendChild(thead);
            var body = h('tbody');
            (sh.columns || []).forEach(function (c) {
                var r = h('tr', c.derived || !c.field ? 'is-grey' : '');
                var what, as;
                if (c.derived) { as = FIELD_LABEL[c.field] || c.field; what = 'Not imported: worked out from other columns.'; }
                else if (!c.field) { as = '—'; what = c.header ? 'Not imported: not recognised as stock data.' : 'Empty column.'; }
                else if (c.state === 'question') { as = FIELD_LABEL[c.field] || c.field; what = 'Not sure: you are asked in the next step.'; }
                else { as = FIELD_LABEL[c.field] || c.field; what = 'Imported' + (c.filled !== null && c.filled !== undefined ? ' (' + c.filled + ' filled)' : '') + '.'; }
                var td1 = h('td', null, c.letter); td1.setAttribute('data-label', 'Column');
                var td2 = h('td'); td2.setAttribute('data-label', 'Heading in your file');
                add(td2, h('span', null, c.header || '(no heading)'), ' ', src(c.ref));
                var td3 = h('td', null, as); td3.setAttribute('data-label', 'Read as');
                var td4 = h('td', null, what); td4.setAttribute('data-label', 'What happens');
                add(r, td1, td2, td3, td4);
                body.appendChild(r);
            });
            tb.appendChild(body);
            wrap.appendChild(tb);
            return wrap;
        }

        // ---------- step 3: review ----------
        function reviewList() {
            // one entry per item (an item may be built from several rows), plus rows that were skipped and so are no item
            var s = S.sum, inItem = {}, list = [], order = 0;
            var qByUid = {};
            s.questions.forEach(function (q) { (q.row_uids || []).forEach(function (u) { (qByUid[u] = qByUid[u] || []).push(q); }); });
            s.items.forEach(function (it) {
                it.uids.forEach(function (u) { inItem[u] = true; });
                var qs = {};
                it.uids.forEach(function (u) { (qByUid[u] || []).forEach(function (q) { qs[q.id] = q; }); });
                (it.open || []).forEach(function (id) { s.questions.forEach(function (q) { if (q.id === id) { qs[id] = q; } }); });
                list.push({ kind: 'item', it: it, status: it.status, sheet: it.sheet, order: order++, qs: Object.keys(qs).map(function (k) { return qs[k]; }) });
            });
            (s.rows || []).forEach(function (r) {
                if (inItem[r.uid]) { return; }
                list.push({ kind: 'row', r: r, status: r.status === 'skipped' ? 'skipped' : r.status, sheet: r.sheet, order: order++, qs: (qByUid[r.uid] || []) });
            });
            return list;
        }
        function counts(list) {
            var c = { all: list.length, 'needs-answer': 0, ready: 0, skipped: 0, blocked: 0 };
            list.forEach(function (e) { c[e.status] = (c[e.status] || 0) + 1; });
            return c;
        }
        function renderReview() {
            var p = clear(panels[3]);
            var s = S.sum;
            var list = reviewList();
            var c = counts(list);
            add(p, h('h3', 'di-h', 'Step 3 · Review and answer'));
            add(p, helpLine('Each line below is one stock item as it would be imported. The small grey tag under every value (like "BAR!C12") is the cell it was read from. Answer the questions; nothing is saved to stock until step 4.'));
            // structural question still open in step 2?
            var structOpen = s.questions.filter(function (q) { return isStructural(q) && (q.answer === null || q.answer === undefined || q.answer === '') && q.blocking; });
            if (structOpen.length) {
                var b = h('p', 'di-warn');
                add(b, structOpen.length + ' question' + (structOpen.length === 1 ? '' : 's') + ' about how a sheet is read ' + (structOpen.length === 1 ? 'is' : 'are') + ' still open in step 2. ');
                var go = h('button', 'btn-secondary di-btn', 'Go to step 2'); go.type = 'button'; go.addEventListener('click', function () { showStep(2); });
                b.appendChild(go); p.appendChild(b);
            }
            // chips
            var chips = h('div', 'di-chips'); chips.setAttribute('role', 'group'); chips.setAttribute('aria-label', 'Show rows');
            [['all', 'All'], ['needs-answer', 'Needs answer'], ['ready', 'Ready'], ['skipped', 'Skipped']].concat(c.blocked ? [['blocked', 'Blocked']] : []).forEach(function (f) {
                var b = h('button', 'di-chip' + (S.filter === f[0] ? ' is-on' : ''));
                b.type = 'button'; b.setAttribute('data-di-filter', f[0]); b.setAttribute('aria-pressed', S.filter === f[0] ? 'true' : 'false');
                add(b, f[1], ' ', h('span', 'di-chip-n', c[f[0]] || 0));
                b.addEventListener('click', function () {
                    S.filter = f[0]; S.pages = {};
                    renderTables(); markChips();
                    var first = tablesBox.querySelector('.di-row');
                    scrollTo(first ? first : tablesBox);
                });
                chips.appendChild(b);
            });
            p.appendChild(chips);
            p.appendChild(helpLine('Tap a filter to show only those rows. "Needs answer" and "Blocked" rows stop you from confirming until they are answered or skipped.'));
            // questions
            var qs = s.questions.filter(function (q) { return !isStructural(q); });
            qs.sort(function (a, b) {
                var ao = (a.answer === null || a.answer === undefined || a.answer === '') && a.blocking ? 0 : 1, bo = (b.answer === null || b.answer === undefined || b.answer === '') && b.blocking ? 0 : 1;
                return ao - bo;
            });
            var openN = qs.filter(function (q) { return q.blocking && (q.answer === null || q.answer === undefined || q.answer === ''); }).length;
            var qh = h('div', 'di-qs');
            add(qh, h('h4', 'di-h4', qs.length ? 'Questions (' + openN + ' still need an answer)' : 'No questions'),
                helpLine(qs.length ? 'Pick one answer for each. The page updates as you go, and your answers are kept if you leave and come back.' : 'Everything in the file was clear. You can check the rows below and continue.'));
            qs.forEach(function (q) { qh.appendChild(questionCard(q)); });
            p.appendChild(qh);
            // tables
            var tablesBox = h('div', 'di-tables'); tablesBox.id = 'diResults'; tablesBox.setAttribute('data-rh-results', '1');
            p.appendChild(tablesBox);
            function markChips() {
                Array.prototype.forEach.call(chips.querySelectorAll('.di-chip'), function (b) {
                    var on = b.getAttribute('data-di-filter') === S.filter;
                    b.classList.toggle('is-on', on); b.setAttribute('aria-pressed', on ? 'true' : 'false');
                });
            }
            function renderTables() {
                clear(tablesBox);
                var sheets = [];
                list.forEach(function (e) { if (sheets.indexOf(e.sheet) < 0) { sheets.push(e.sheet); } });
                if (!sheets.length) { tablesBox.appendChild(h('p', 'di-muted', 'No rows to show.')); }
                sheets.forEach(function (name) {
                    var all = list.filter(function (e) { return e.sheet === name; });
                    var rows = all.filter(function (e) { return S.filter === 'all' || e.status === S.filter; });
                    rows.sort(function (a, b) { return (STATUS_RANK[a.status] - STATUS_RANK[b.status]) || (a.order - b.order); });
                    var pages = Math.max(1, Math.ceil(rows.length / PER_PAGE));
                    var pg = Math.min(S.pages[name] || 0, pages - 1);
                    var box = h('div', 'di-sheetbox');
                    var sc = counts(all);
                    var hd = h('div', 'di-sheet-head');
                    add(hd, h('h4', 'di-sheet-name', name), h('span', 'di-sheet-sub', all.length + ' item' + (all.length === 1 ? '' : 's') + ' · ' + (sc['needs-answer'] + (sc.blocked || 0)) + ' to answer · ' + sc.ready + ' ready · ' + sc.skipped + ' skipped'));
                    box.appendChild(hd);
                    if (!rows.length) { box.appendChild(h('p', 'di-muted', 'No rows match this filter on this sheet.')); tablesBox.appendChild(box); return; }
                    box.appendChild(itemsTable(rows.slice(pg * PER_PAGE, (pg + 1) * PER_PAGE)));
                    if (pages > 1) {
                        var bar = h('div', 'di-pager');
                        var prev = h('button', 'btn-secondary di-btn', 'Previous 100'), nxt = h('button', 'btn-secondary di-btn', 'Next 100');
                        prev.type = 'button'; nxt.type = 'button'; prev.disabled = pg === 0; nxt.disabled = pg >= pages - 1;
                        prev.addEventListener('click', function () { S.pages[name] = pg - 1; renderTables(); scrollTo(box); });
                        nxt.addEventListener('click', function () { S.pages[name] = pg + 1; renderTables(); scrollTo(box); });
                        add(bar, prev, h('span', null, 'Showing ' + (pg * PER_PAGE + 1) + '–' + Math.min((pg + 1) * PER_PAGE, rows.length) + ' of ' + rows.length), nxt);
                        box.appendChild(bar);
                    }
                    tablesBox.appendChild(box);
                });
            }
            renderTables();
            var nav = h('div', 'di-actions');
            var back = h('button', 'btn-secondary di-btn'); back.type = 'button'; add(back, h('i', 'fas fa-arrow-left'), ' Back'); back.addEventListener('click', function () { showStep(2); });
            var dl = h('a', 'btn-secondary di-btn', ' Download what will be imported (CSV)');
            dl.href = page + '?action=csv&job=' + S.job; dl.setAttribute('download', '');
            dl.insertBefore(h('i', 'fas fa-download'), dl.firstChild);
            var next = h('button', 'btn-primary di-btn'); next.type = 'button'; add(next, 'Continue to confirm ', h('i', 'fas fa-arrow-right')); next.addEventListener('click', function () { showStep(4); });
            add(nav, back, dl, next);
            p.appendChild(nav);
            p.appendChild(helpLine('The download lists every item that is ready, with where it came from, so you can check it in Excel before you confirm.'));
        }
        var HEADS = [
            ['Item', 'The stock item name, exactly as written in your file.'],
            ['Category', 'The heading the item sits under, or the sheet name if there was none.'],
            ['Unit', 'How the item is counted (bottle, kg...). Taken from the sheet or the answer you gave.'],
            ['Opening stock', 'The quantity in your file. It goes into an opening stock count that must be approved.'],
            ['Cost per unit', 'The price from your file for one unit, taken as written: VAT is not added or removed. Blank means no price was given.'],
            ['Par level', 'The most you normally hold (the maximum level from your file).'],
            ['What happens', 'Whether a new ingredient is created or an existing one is matched.'],
            ['Status', 'Ready, needs an answer, skipped or blocked. The grey tag under a value is the cell it came from.']
        ];
        function itemsTable(rows) {
            var wrap = h('div', 'di-tablewrap');
            var tb = h('table', 'di-table di-items');
            var thead = h('thead'), tr = h('tr');
            HEADS.forEach(function (c) { var th = h('th'); add(th, h('span', null, c[0]), h('small', 'di-th-help', c[1])); tr.appendChild(th); });
            thead.appendChild(tr); tb.appendChild(thead);
            var body = h('tbody');
            rows.forEach(function (e) {
                var rows2 = e.kind === 'item' ? itemRows(e) : plainRow(e);
                rows2.forEach(function (r) { body.appendChild(r); });
            });
            tb.appendChild(body); wrap.appendChild(tb);
            return wrap;
        }
        function cell(label, cls) { var td = h('td', cls || ''); td.setAttribute('data-label', label); return td; }
        function plainRow(e) {
            var r = h('tr', 'di-row di-row--' + e.status);
            var t1 = cell('Item'); add(t1, h('strong', null, e.r.name || '(no name)'), h('br'), src(e.r.ref));
            var t2 = cell('Category', 'di-mute'); t2.textContent = '—';
            var t3 = cell('Unit', 'di-mute'); t3.textContent = '—';
            var t4 = cell('Opening stock', 'di-mute'); t4.textContent = '—';
            var t5 = cell('Cost per unit', 'di-mute'); t5.textContent = '—';
            var t6 = cell('Par level', 'di-mute'); t6.textContent = '—';
            var t7 = cell('What happens'); t7.textContent = e.r.reason || 'Not imported.';
            var t8 = cell('Status'); add(t8, pill(e.status));
            (e.r.warnings || []).forEach(function (w) { t8.appendChild(h('div', 'di-warnline', w)); });
            add(r, t1, t2, t3, t4, t5, t6, t7, t8);
            return [r];
        }
        function itemRows(e) {
            var it = e.it, out = [];
            var r = h('tr', 'di-row di-row--' + e.status);
            var t1 = cell('Item');
            add(t1, h('strong', null, it.name));
            if (it.match && it.typed_name && it.typed_name !== it.name) { add(t1, h('div', 'di-small', 'Written in file as: ' + it.typed_name)); }
            add(t1, h('div', 'di-capline'), src(it.name_source));
            if ((it.rows || []).length > 1) { t1.appendChild(h('div', 'di-small', 'Built from ' + it.rows.length + ' rows (' + it.rows.join(', ') + ')')); }
            var t2 = cell('Category');
            add(t2, h('span', null, it.category || '—'), h('div', 'di-capline'), it.category_source ? src(it.category_source) : h('span', 'di-small', it.category_how === 'sheet-name' ? 'from the sheet name' : ''));
            var t3 = cell('Unit');
            add(t3, h('span', null, it.unit || '—'), h('div', 'di-capline'), it.unit_source ? src(it.unit_source) : (it.unit ? h('span', 'di-small', it.unit_how || '') : h('span', 'di-small', 'not in the file')));
            var t4 = cell('Opening stock');
            add(t4, h('span', null, it.balance === null || it.balance === undefined ? '—' : num(it.balance)));
            if (it.balance_sources && it.balance_sources.length) { t4.appendChild(h('div', 'di-capline')); it.balance_sources.forEach(function (x) { t4.appendChild(src(x)); t4.appendChild(document.createTextNode(' ')); }); }
            if ((it.batches || []).some(function (b) { return b.expiry; })) {
                var bt = h('div', 'di-small');
                bt.textContent = it.batches.filter(function (b) { return b.expiry; }).map(function (b) { return (b.label ? b.label + ' ' : '') + num(b.qty) + ' exp ' + b.expiry; }).join('; ');
                t4.appendChild(bt);
            }
            var t5 = cell('Cost per unit');
            add(t5, h('span', null, it.cost === null || it.cost === undefined ? '—' : num(it.cost)), h('div', 'di-capline'), it.price_source ? src(it.price_source) : null);
            if (it.cost_how && it.cost_how !== 'as-written' && it.cost !== null) { t5.appendChild(h('div', 'di-small', it.cost_how)); }
            var t6 = cell('Par level');
            add(t6, h('span', null, it.max === null || it.max === undefined ? '—' : num(it.max)), h('div', 'di-capline'), it.max_source ? src(it.max_source) : null);
            var t7 = cell('What happens');
            if (it.match) {
                add(t7, h('span', null, 'Matches existing: '), h('strong', null, it.match.name),
                    h('div', 'di-small', it.match.how === 'chosen' ? 'You chose this match.' : (it.match.how === 'learned' ? 'Remembered from an earlier import.' : 'Same name as an ingredient already in stock.')));
                if (it.match.archived) { t7.appendChild(h('div', 'di-small', 'That ingredient is archived.')); }
            } else {
                t7.appendChild(h('span', null, e.status === 'skipped' ? 'Not imported.' : 'New ingredient will be created.'));
            }
            if (e.status !== 'skipped') { t7.appendChild(h('div', 'di-small', it.in_count ? 'Its stock goes into the opening count.' : (it.balance ? 'Stock is NOT put in the opening count.' : ''))); }
            var t8 = cell('Status');
            t8.appendChild(pill(e.status));
            (it.warnings || []).forEach(function (w) { t8.appendChild(h('div', 'di-warnline', w)); });
            var linked = false;
            (e.qs || []).forEach(function (q) {
                var unanswered = q.answer === null || q.answer === undefined || q.answer === '';
                if (!unanswered || !q.blocking || linked) { return; }
                linked = true;
                var a = h('button', 'di-qlink', 'Answer the question');
                a.type = 'button';
                a.addEventListener('click', function () { var t = document.getElementById('diq-' + q.id); if (t) { scrollTo(t); t.classList.add('is-flash'); window.setTimeout(function () { t.classList.remove('is-flash'); }, 1600); } });
                t8.appendChild(a);
            });
            add(r, t1, t2, t3, t4, t5, t6, t7, t8);
            out.push(r);
            var dk = it.diff && !Array.isArray(it.diff) ? Object.keys(it.diff) : [];
            if (it.match && dk.length && e.status !== 'skipped') { out.push(updateRow(it, dk)); }
            return out;
        }
        function updateRow(it, keys) {
            var tr = h('tr', 'di-row di-row--detail');
            var td = h('td'); td.colSpan = 8;
            var box = h('div', 'di-upd');
            add(box, h('strong', null, 'Existing item: choose what to update'), helpLine('"' + it.match.name + '" is already in stock. Your file has different values for the fields below. Nothing changes unless you tick it — the default is to keep what is there now.'));
            var boxes = [];
            keys.forEach(function (k) {
                var d = it.diff[k];
                var lab = h('label', 'di-opt');
                var cb = document.createElement('input'); cb.type = 'checkbox'; cb.value = k; cb.checked = (it.ticked || []).indexOf(k) >= 0;
                boxes.push(cb);
                var fmt = function (v) { return typeof v === 'number' ? num(v) : (v === null || v === undefined || v === '' ? 'blank' : String(v)); };
                add(lab, cb, h('span', 'di-opt-t', (DIFF_LABEL[k] || k) + ': keep ' + fmt(d[0]) + ' (now) → change to ' + fmt(d[1]) + ' (your file)'));
                box.appendChild(lab);
                if (k === 'cost' && it.cost_stock_warning) { box.appendChild(h('p', 'di-warn', it.cost_stock_warning)); }
                cb.addEventListener('change', function () {
                    boxes.forEach(function (b) { b.disabled = true; });
                    var picked = boxes.filter(function (b) { return b.checked; }).map(function (b) { return b.value; });
                    S.chain = S.chain.then(function () { return post('fields', { job: S.job, key: it.update_key, fields: picked }); }).then(function (r) {
                        if (!r.ok) { msg(r.error || 'That choice could not be saved.'); boxes.forEach(function (b) { b.disabled = false; }); return; }
                        refresh(r.summary);
                    });
                });
            });
            td.appendChild(box); tr.appendChild(td);
            return tr;
        }

        // ---------- step 4: confirm ----------
        function renderConfirm() {
            var p = clear(panels[4]);
            var s = S.sum;
            var items = s.items;
            var ready = items.filter(function (i) { return i.status === 'ready'; });
            var creates = ready.filter(function (i) { return !i.match; });
            var updates = ready.filter(function (i) { return i.match && (i.ticked || []).length; });
            var kept = ready.filter(function (i) { return i.match && !(i.ticked || []).length; });
            var inCount = ready.filter(function (i) { return i.in_count; });
            var noPrice = ready.filter(function (i) { return !i.in_count && i.balance; });
            var list = reviewList(), c = counts(list);
            var toAnswer = (c['needs-answer'] || 0) + (c.blocked || 0);
            add(p, h('h3', 'di-h', 'Step 4 · Confirm'));
            add(p, helpLine('Last check. These are the numbers for the whole file. Importing is one all-or-nothing step: if anything goes wrong, nothing is saved.'));
            var cards = h('div', 'di-cards');
            add(cards,
                card('New ingredients', num(creates.length, 0), 'Items not in your stock yet. They will be created.', 'good'),
                card('Existing items updated', num(updates.length, 0), 'Existing items where you ticked a field to change.'),
                card('Existing items left as they are', num(kept.length, 0), 'Matched to an ingredient already in stock, with nothing ticked.'),
                card('Lines in the opening count', num(inCount.length, 0), 'Items with stock and a price. They go into one opening count that another manager must approve.'),
                card('Rows skipped', num(c.skipped || 0, 0), 'Rows you chose to skip, or that are not items. They are not imported.'),
                card('Still need an answer', num(toAnswer, 0), 'Questions or blocked rows to sort out before you can confirm.', toAnswer ? 'bad' : 'good'));
            p.appendChild(cards);
            var what = h('div', 'di-what');
            add(what, h('h4', 'di-h4', 'What happens when you press Confirm'));
            var ul = h('ul', 'di-facts');
            [
                'New ingredients are created and the changes you ticked are made to existing ones.',
                'The stock quantities are filed as an opening stock count. They are NOT added to your stock yet: a different manager must approve that count on the Stock Count page first.',
                'Nothing is deleted and recipes are not touched. Stock quantities only change once the opening count is approved.',
                'The copy of your file on the server is deleted afterwards.'
            ].forEach(function (t) { ul.appendChild(h('li', null, t)); });
            what.appendChild(ul);
            var costWithStock = ready.filter(function (i) { return i.match && i.cost_stock_warning; });
            if (costWithStock.length) {
                what.appendChild(h('p', 'di-warn', num(costWithStock.length, 0) + ' cost change' + (costWithStock.length === 1 ? ' affects an item that already has' : 's affect items that already have') + ' stock. Their recipe costs and stock value change as soon as you confirm, before the opening count is approved: ' + costWithStock.map(function (i) { return i.match.name; }).join(', ') + '.'));
            }
            if (noPrice.length) { what.appendChild(h('p', 'di-warn', num(noPrice.length, 0) + ' item' + (noPrice.length === 1 ? ' has' : 's have') + ' stock but no price in the file. They are created without a cost and their stock is left out of the opening count. Add the cost later.')); }
            p.appendChild(what);
            var why = h('p', 'di-why');
            var btn = h('button', 'btn-primary di-btn di-confirm');
            btn.type = 'button';
            add(btn, h('i', 'fas fa-check', null), ' Confirm and import');
            if (!s.can_confirm) {
                btn.disabled = true;
                why.textContent = (s.why_not || 'Some questions still need an answer.') + ' (' + toAnswer + ' row' + (toAnswer === 1 ? '' : 's') + ' still to sort out.)';
            } else { why.textContent = 'Everything is answered. You can confirm.'; why.classList.add('is-ok'); }
            btn.addEventListener('click', function () {
                if (btn.disabled || S.busy) { return; }
                S.busy = true; btn.disabled = true; btn.textContent = 'Importing…';
                post('confirm', { job: S.job, confirm: '1' }).then(function (r) {
                    S.busy = false;
                    if (!r.ok) {
                        msg(r.error || 'Nothing was imported.');
                        if (r.violations && r.violations.length) { msg((r.error || '') + ' ' + r.violations.slice(0, 5).join('; ')); }
                        refresh().then(function () { renderConfirm(); });
                        return;
                    }
                    S.result = r.result || {}; S.resultAlready = !!r.already; S.resultFile = s.job.file;
                    showStep(4); loadMine();
                });
            });
            var nav = h('div', 'di-actions');
            var back = h('button', 'btn-secondary di-btn'); back.type = 'button'; add(back, h('i', 'fas fa-arrow-left'), ' Back to review'); back.addEventListener('click', function () { showStep(3); });
            var cancel = h('button', 'btn-secondary di-btn di-btn--quiet', 'Cancel this import'); cancel.type = 'button'; cancel.addEventListener('click', function () { cancelJob(S.job, true); });
            add(nav, back, cancel, btn);
            add(p, why, nav);
        }
        function renderResult() {
            var p = clear(panels[4]);
            var r = S.result || {};
            add(p, h('h3', 'di-h', 'Done · Import result'));
            if (S.resultAlready) { p.appendChild(h('p', 'di-warn', 'This import had already been completed. Nothing was written a second time.')); }
            p.appendChild(helpLine(S.resultFile ? 'Result for "' + S.resultFile + '".' : 'Result of this import.'));
            var cards = h('div', 'di-cards');
            add(cards,
                card('Ingredients created', num(r.created, 0), 'New ingredients added to your stock list.', 'good'),
                card('Ingredients updated', num(r.updated, 0), 'Existing ingredients where you ticked a change.'),
                card('Rows skipped', num(r.skipped_rows, 0), 'Rows that were skipped or are not items.'),
                card('Opening count lines', num(r.count_lines, 0), 'Stock quantities filed for approval. They are not in stock yet.'),
                card('Expiry batches', num(r.expiry_batches, 0), 'Dated batches filed with the count, so expiry is tracked once approved.'));
            p.appendChild(cards);
            if (r.next_step) { p.appendChild(h('p', 'di-note', r.next_step)); }
            if (r.stock_not_counted_no_cost && r.stock_not_counted_no_cost.length) {
                var d = h('details', 'di-details');
                d.appendChild(h('summary', null, r.stock_not_counted_no_cost.length + ' item(s) have stock but no price, so their stock was left out of the count'));
                var ul = h('ul', 'di-nonitems');
                r.stock_not_counted_no_cost.forEach(function (n) { ul.appendChild(h('li', null, n)); });
                d.appendChild(ul); p.appendChild(d);
            }
            if (r.left_out_overlap) { p.appendChild(h('p', 'di-small', num(r.left_out_overlap, 0) + ' stock line(s) were left out of the count because those ingredients are already in another opening count that is waiting for approval.')); }
            var nav = h('div', 'di-actions');
            if (r.opening_count_id) {
                var a = h('a', 'btn-primary di-btn', 'Open the opening count' + (r.opening_count_ref ? ' (' + r.opening_count_ref + ')' : ''));
                a.href = 'stock-count.php?id=' + encodeURIComponent(r.opening_count_id);
                nav.appendChild(a);
            }
            var b = h('a', 'btn-secondary di-btn', 'See the ingredients'); b.href = 'stock-ingredients.php';
            var again = h('button', 'btn-secondary di-btn', 'Import another file'); again.type = 'button';
            again.addEventListener('click', function () { S.job = 0; S.sum = null; S.result = null; S.reach = 1; setUrl(0); showStep(1); });
            add(nav, b, again);
            p.appendChild(nav);
        }

        // ---------- start ----------
        showStep(1);
        loadMine();
        var start = Number(root.getAttribute('data-job')) || 0;
        if (start > 0) { loadJob(start); }
    }

    window.rhDataImport = { init: init };
})();
