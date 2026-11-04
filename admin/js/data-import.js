/**
 * Import (stock lists) - admin/data-import.php.
 * Every piece of text that comes from the uploaded file (sheet names, cells, item names, remarks) is put on the page with
 * textContent, never as HTML. Statuses are never decided here: after every answer the whole summary is fetched again from the server.
 * Loaded once; window.rhDataImport.init(root) is called by the inline script on each (in-place) visit to the page.
 */
(function () {
    'use strict';
    if (window.rhDataImport) { return; }

    var FIELD_LABEL = {
        item_name: 'Item name', batch: 'Batch', qty_primary: 'Balance (bar / kitchen)', qty_secondary: 'Balance (stores)', qty_single: 'Balance',
        unit: 'Unit', expiry: 'Expiry date', max_level: 'Par / maximum level', min_level: 'Reorder level', unit_price: 'Unit price',
        remarks: 'Remarks', category: 'Category'
    };
    var DIFF_LABEL = { category: 'Category', cost: 'Cost per unit', par: 'Par level', pack: 'Pack size', quantity: 'Opening stock quantity' };
    var STATUS_LABEL = { 'ready': 'Ready', 'needs-answer': 'Needs answer', 'skipped': 'Skipped', 'blocked': 'Blocked' };
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
        var S = { job: 0, sum: null, result: null, step: 1, reach: 1, busy: false, file: null, chain: Promise.resolve(), f: null, R: null, page: null, facets: null, statusEff: [], seq: 0, openCats: {}, bulkSel: null };
        S.f = { q: '', sheet: '', cat: '', status: null, type: '', kind: '', warn: 0, cost: 0, stock: 0, answered: 0, sort: 'order', size: 50, page: 1, view: 'list' };

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
                if (job) { u.searchParams.set('job', job); } else { u.searchParams.delete('job'); ['q', 'sheet', 'cat', 'status', 'type', 'kind', 'warn', 'cost', 'stock', 'answered', 'sort', 'size', 'page', 'view'].forEach(function (k) { u.searchParams.delete(k); }); }
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
        function loadJob(id, goStep, fromUrl) {
            S.job = id;
            S.result = null;
            S.f = fromUrl ? urlFilters() : copyDef();      // a reload keeps the filters in the address; opening a job starts clean
            S.openCats = {}; S.page = null; S.facets = null; S.statusEff = []; S.bulkSel = null;
            return post('summary', { job: id }).then(function (r) {
                if (r.state === 'committed') {
                    S.sum = null; S.result = r.result || {}; S.file = null; setUrl(id); S.resultFile = r.file; showStep(4); loadMine(); return;
                }
                if (!r.ok) {
                    S.job = 0; S.sum = null; setUrl(0); showStep(1); msg(r.error || 'That import cannot be opened.'); loadMine(); return;
                }
                S.sum = r;
                setUrl(id);
                if (!goStep) { goStep = (r.job && r.job.status === 'reviewing') || (fromUrl && urlHasFilters()) ? 3 : 2; }
                showStep(goStep);
                loadMine();
            });
        }
        function refresh(fresh) {
            // answers change statuses, counts and even question ids: always take the whole picture from the server again
            // (the answer reply carries it; a separate fetch is only the fallback)
            var y = window.pageYOffset;
            return (fresh && fresh.ok ? Promise.resolve(fresh) : post('summary', Object.assign({ job: S.job }, pagedExtra()))).then(function (r) {
                if (!r.ok) { msg(r.error || 'Could not refresh.'); return; }
                S.sum = r;
                if (S.step === 3) {
                    if (r.page && S.R && S.R.results && S.f.view === 'list') { absorb(r); delete S.sum.page; paintAll({}); } else if (S.R && S.R.results) { delete S.sum.page; paintProgress(); reload({}); }
                    else { renderReview(); }
                } else if (S.step === 2) { renderFound(); } else if (S.step === 4) { renderConfirm(); }
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
            var d = Object.assign({ job: S.job, question: q.id, answer: key }, pagedExtra());
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

        // ---------- step 3: review (the server pages, filters and counts; the browser only ever holds one page) ----------
        var FKEYS = ['q', 'sheet', 'cat', 'status', 'type', 'kind', 'warn', 'cost', 'stock', 'answered', 'sort', 'size', 'page', 'view'];
        var DEF = { q: '', sheet: '', cat: '', status: null, type: '', kind: '', warn: 0, cost: 0, stock: 0, answered: 0, sort: 'order', size: 50, page: 1, view: 'list' };
        var STATUS_ORDER = ['needs-answer', 'blocked', 'ready', 'skipped'];
        var TYPE_LABEL = {
            'possible-typo': 'Did you mean (possible typo)', 'duplicate-no-batch': 'Same name twice, no batch', 'duplicate-batch-clash': 'Same name twice, same batch',
            'conflict': 'Two values for one item', 'merge-conflict': 'Rows of one item disagree', 'case-price': 'Case price or price per unit',
            'price-check': 'Price looks unusual', 'same-item-two-sheets': 'Same item on two sheets', 'unit-clash': 'Unit differs from stock',
            'archived-match': 'Matches an archived item', 'ambiguous-match': 'Several possible matches', 'cost-too-large': 'Price too large to store',
            'value-too-long': 'Text too long to store', 'bad-value': 'Value is not a number', 'formula-name': 'Name looks like a formula', 'section-or-item': 'Heading or item?'
        };
        var SORTS = [['order', 'Order in your file'], ['name', 'Name A to Z'], ['status', 'Needs answer first'], ['category', 'Category'], ['price', 'Price, lowest first'], ['price_desc', 'Price, highest first']];
        var SIZES = [25, 50, 100, 200];
        function copyDef() { var o = {}; Object.keys(DEF).forEach(function (k) { o[k] = DEF[k]; }); return o; }
        function urlFilters() {
            var f = copyDef();
            try {
                var sp = new URL(window.location.href).searchParams;
                FKEYS.forEach(function (k) {
                    if (!sp.has(k)) { return; }
                    var v = String(sp.get(k)).slice(0, 120);
                    if (k === 'status') { f.status = v === '' ? null : v; }
                    else if (k === 'warn' || k === 'cost' || k === 'stock' || k === 'answered') { f[k] = v === '1' ? 1 : 0; }
                    else if (k === 'size' || k === 'page') { f[k] = Math.max(1, parseInt(v, 10) || DEF[k]); }
                    else { f[k] = v; }
                });
            } catch (e) { /* keep defaults */ }
            return f;
        }
        function urlHasFilters() {
            try { var sp = new URL(window.location.href).searchParams; return FKEYS.some(function (k) { return sp.has(k); }); } catch (e) { return false; }
        }
        function fpayload(extra) {
            var f = S.f, d = {};
            ['q', 'sheet', 'cat', 'type', 'kind', 'sort', 'view'].forEach(function (k) { if (f[k] !== '' && f[k] !== DEF[k]) { d[k] = f[k]; } });
            if (f.status !== null) { d.status = f.status; }
            ['warn', 'cost', 'stock', 'answered'].forEach(function (k) { if (f[k]) { d[k] = '1'; } });
            d.size = f.size; d.page = f.page;
            Object.keys(extra || {}).forEach(function (k) { d[k] = extra[k]; });
            return d;
        }
        function syncUrl() {
            try {
                var u = new URL(window.location.href);
                FKEYS.forEach(function (k) { u.searchParams.delete(k); });
                if (S.job && S.step === 3) {
                    var d = fpayload();
                    FKEYS.forEach(function (k) { if (d[k] !== undefined && String(d[k]) !== String(DEF[k] === null ? '' : DEF[k])) { u.searchParams.set(k, d[k]); } });
                }
                window.history.replaceState(window.history.state, '', u.pathname + u.search);
            } catch (e) { /* ignore */ }
        }
        function statusList() {
            if (S.f.status === null) { return S.statusEff || []; }
            if (S.f.status === 'all') { return []; }
            return String(S.f.status).split(',').filter(function (x) { return STATUS_ORDER.indexOf(x) >= 0; });
        }
        function isOpenQ(q) { return q.answer === null || q.answer === undefined || q.answer === ''; }
        function debounce(fn, ms) { var t = null; return function () { var a = arguments; window.clearTimeout(t); t = window.setTimeout(function () { fn.apply(null, a); }, ms); }; }
        function headerBottom() { var hd = document.querySelector('header.admin-header'); return hd ? Math.max(0, hd.getBoundingClientRect().bottom) : 0; }
        // #rh-admin-page is an overflow container that never scrolls (the window does), so position:sticky cannot pin the bar: it is pinned with
        // position:fixed under the admin header while it would scroll out of view, and a spacer keeps the page from jumping.
        function stickyOffset() {
            var b = S.R && S.R.bar;
            if (!b) { return headerBottom(); }
            // while pinned the real height; before that the height it had the last time it was pinned (the bar is shorter pinned: no helper lines)
            return headerBottom() + (b.classList.contains('is-stuck') ? b.offsetHeight : (S.R.stuckH || Math.min(b.offsetHeight, 140)));
        }
        function scrollToEl(el, focusInside) {
            if (!el) { return; }
            var y = el.getBoundingClientRect().top + window.pageYOffset - stickyOffset() - 10;
            window.scrollTo({ top: Math.max(0, y), behavior: 'smooth' });
            if (focusInside) {
                var f = el.nextElementSibling && el.nextElementSibling.querySelector ? el.nextElementSibling.querySelector('input:not([disabled]),select,button') : null;
                if (f) { try { f.focus({ preventScroll: true }); } catch (e) { f.focus(); } }
            }
        }
        function ctl(label, node, help) {
            var w = h('div', 'di-f');
            var l = h('label', 'di-f-l', label);
            if (node.id) { l.setAttribute('for', node.id); }
            add(w, l, node, h('small', 'di-fhelp', help));
            return w;
        }
        function mkSelect(id, aria) {
            var s = h('select', 'di-select di-fsel'); s.id = id; s.setAttribute('aria-label', aria); return s;
        }
        function fillSelect(sel, opts, current) {
            clear(sel);
            opts.forEach(function (o) { var op = h('option', null, o[1]); op.value = o[0]; if (String(o[0]) === String(current)) { op.selected = true; } sel.appendChild(op); });
        }
        function sumN(o) { return Object.keys(o || {}).reduce(function (a, k) { return a + (Number(o[k]) || 0); }, 0); }

        function renderReview() {
            var p = clear(panels[3]);
            var s = S.sum;
            S.R = {};
            add(p, h('h3', 'di-h', 'Step 3 · Review and answer'));
            add(p, helpLine('Each line below is one stock item as it would be imported. The small grey tag under every value (like "BAR!C12") is the cell it was read from. Answer the questions that sit under an item; nothing is saved to stock until step 4.'));
            var structOpen = s.questions.filter(function (q) { return isStructural(q) && isOpenQ(q) && q.blocking; });
            if (structOpen.length) {
                var b = h('p', 'di-warn');
                add(b, structOpen.length + ' question' + (structOpen.length === 1 ? '' : 's') + ' about how a sheet is read ' + (structOpen.length === 1 ? 'is' : 'are') + ' still open in step 2. ');
                var go = h('button', 'btn-secondary di-btn', 'Go to step 2'); go.type = 'button'; go.addEventListener('click', function () { showStep(2); });
                b.appendChild(go); p.appendChild(b);
            }
            // progress + jump
            var prog = h('div', 'di-progress');
            S.R.progText = h('strong', 'di-progress-t'); S.R.progText.setAttribute('role', 'status'); S.R.progText.setAttribute('aria-live', 'polite');
            var track = h('div', 'di-progress-track'); track.setAttribute('role', 'progressbar'); track.setAttribute('aria-valuemin', '0');
            S.R.progBar = h('span', 'di-progress-bar'); track.appendChild(S.R.progBar); S.R.progTrack = track;
            S.R.jump = h('button', 'btn-primary di-btn di-jump', 'Jump to the next one that needs an answer');
            S.R.jump.type = 'button'; S.R.jump.setAttribute('aria-keyshortcuts', 'n');
            S.R.jump.addEventListener('click', jumpNext);
            add(prog, S.R.progText, track, S.R.jump,
                h('small', 'di-fhelp', 'The bar counts questions you must answer before you can confirm; the button counts the items they belong to. The N key does the same as the button when you are not typing.'));
            p.appendChild(prog);
            // questions that belong to a whole sheet (one answer for all its items)
            S.R.sheetQs = h('div', 'di-qs di-sheetqs'); S.R.sheetQs.hidden = true; p.appendChild(S.R.sheetQs);
            // the filter bar
            var bar = h('div', 'di-fbar'); S.R.bar = bar; S.R.barWrap = h('div', 'di-fwrap'); S.R.barWrap.appendChild(bar);
            var top = h('div', 'di-fbar-top');
            var si = document.createElement('input');
            si.type = 'search'; si.id = 'diQ'; si.className = 'di-fin'; si.maxLength = 100; si.value = S.f.q; si.setAttribute('autocomplete', 'off'); si.setAttribute('aria-label', 'Search items'); si.placeholder = 'Search items';
            var go1 = debounce(function () { var v = si.value.trim(); if (v !== S.f.q) { S.f.q = v; S.f.page = 1; reload(); } }, 300);
            si.addEventListener('input', go1);
            si.addEventListener('keydown', function (e) { if (e.key === 'Enter') { e.preventDefault(); var v = si.value.trim(); if (v !== S.f.q) { S.f.q = v; S.f.page = 1; reload(); } } });
            S.R.q = si;
            var searchBox = ctl('Search', si, 'Finds an item name, a category, or a cell from your file such as C12.');
            searchBox.classList.add('di-f--grow');
            S.R.chips = h('div', 'di-chips di-chips--status'); S.R.chips.setAttribute('role', 'group'); S.R.chips.setAttribute('aria-label', 'Show items with this status');
            var chipBox = h('div', 'di-f di-f--chips');
            add(chipBox, h('span', 'di-f-l', 'Status'), S.R.chips, h('small', 'di-fhelp', 'Needs answer and Blocked must be answered or skipped before you can confirm. Each number follows your other filters.'));
            S.R.viewBox = h('div', 'di-seg'); S.R.viewBox.setAttribute('role', 'group'); S.R.viewBox.setAttribute('aria-label', 'How to show the items');
            [['list', 'List'], ['cat', 'By category']].forEach(function (v) {
                var bt = h('button', 'di-segbtn', v[1]); bt.type = 'button'; bt.setAttribute('data-view', v[0]);
                bt.addEventListener('click', function () { if (S.f.view !== v[0]) { S.f.view = v[0]; S.f.page = 1; reload(); } });
                S.R.viewBox.appendChild(bt);
            });
            var viewCtl = h('div', 'di-f di-f--view');
            add(viewCtl, h('span', 'di-f-l', 'Show as'), S.R.viewBox, h('small', 'di-fhelp', 'One list, or grouped by category.'));
            S.R.more = h('button', 'btn-secondary di-btn di-more'); S.R.more.type = 'button'; S.R.more.setAttribute('aria-expanded', 'false'); S.R.more.setAttribute('aria-controls', 'diDrawer');
            S.R.moreN = h('span', 'di-chip-n', '0');
            add(S.R.more, h('i', 'fas fa-sliders-h'), ' Filters ', S.R.moreN);
            var moreCtl = h('div', 'di-f di-f--more');
            S.R.more.setAttribute('aria-label', 'Filters: more ways to narrow the list'); add(moreCtl, S.R.more, h('small', 'di-fhelp', 'More ways to narrow it.'));
            add(top, searchBox, chipBox, viewCtl, moreCtl);
            bar.appendChild(top);
            var drawer = h('div', 'di-fdrawer'); drawer.id = 'diDrawer'; drawer.hidden = true; S.R.drawer = drawer;
            drawer.setAttribute('role', 'group'); drawer.setAttribute('aria-labelledby', 'diDrawerT');
            var dTitle = h('strong', null, 'Filters'); dTitle.id = 'diDrawerT';
            var dClose = h('button', 'btn-secondary di-btn', 'Done'); dClose.type = 'button';
            add(drawer, add(h('div', 'di-fdrawer-head'), dTitle, dClose));
            S.R.selSheet = mkSelect('diSheet', 'Sheet'); S.R.selCat = mkSelect('diCat', 'Category'); S.R.selType = mkSelect('diType', 'Kind of question');
            S.R.selKind = mkSelect('diKind', 'New or existing'); S.R.selSort = mkSelect('diSort', 'Sort'); S.R.selSize = mkSelect('diSize', 'Items per page');
            fillSelect(S.R.selSort, SORTS, S.f.sort);
            fillSelect(S.R.selSize, SIZES.map(function (n) { return [n, n + ' items']; }), S.f.size);
            fillSelect(S.R.selKind, [['', 'New and existing'], ['new', 'New items'], ['matched', 'Existing items']], S.f.kind);
            [['selSheet', 'sheet'], ['selCat', 'cat'], ['selType', 'type'], ['selKind', 'kind'], ['selSort', 'sort']].forEach(function (pr) {
                S.R[pr[0]].addEventListener('change', function () { S.f[pr[1]] = S.R[pr[0]].value; S.f.page = 1; reload(); });
            });
            S.R.selSize.addEventListener('change', function () { S.f.size = Number(S.R.selSize.value) || 50; S.f.page = 1; reload(); });
            var grid = h('div', 'di-fgrid');
            add(grid,
                ctl('Sheet', S.R.selSheet, 'Only items from one sheet (tab) of your file.'),
                ctl('Category', S.R.selCat, 'Only items under one heading of your file.'),
                ctl('Kind of question', S.R.selType, 'Only items that still have an unanswered question of this kind.'),
                ctl('New or existing', S.R.selKind, 'New items will be created. Existing items match something already in your stock.'),
                ctl('Sort by', S.R.selSort, 'The order of the list. Items that tie stay in the order of your file.'),
                ctl('Items per page', S.R.selSize, 'How many items to load at a time.'));
            drawer.appendChild(grid);
            S.R.flags = {};
            var fl = h('div', 'di-fflags');
            [['warn', 'Has a warning', 'Items with a note under them, such as a missing price or stock that is not counted.'],
                ['cost', 'Cost change ticked', 'Existing items where you ticked the box to change their cost.'],
                ['stock', 'Already has stock', 'Existing items that have stock on hand right now.'],
                ['answered', 'I answered a question', 'Items where you have already given an answer.']].forEach(function (f) {
                var lab = h('label', 'di-check di-fflag'); var cb = document.createElement('input'); cb.type = 'checkbox'; cb.id = 'diFlag_' + f[0];
                S.R.flags[f[0]] = cb;
                cb.addEventListener('change', function () { S.f[f[0]] = cb.checked ? 1 : 0; S.f.page = 1; reload(); });
                var tx = h('span'); add(tx, h('strong', null, f[1] + ' '), h('span', 'di-fnum'), h('small', 'di-fhelp', f[2]));
                S.R.flags[f[0] + '_n'] = tx.querySelector('.di-fnum');
                add(lab, cb, tx); fl.appendChild(lab);
            });
            drawer.appendChild(fl);
            var clr = h('button', 'btn-secondary di-btn di-clear', 'Clear filters'); clr.type = 'button';
            clr.addEventListener('click', clearFilters);
            S.R.done = h('button', 'btn-primary di-btn', 'Show items'); S.R.done.type = 'button';
            add(drawer, add(h('div', 'di-fdrawer-foot'), clr, S.R.done, h('small', 'di-fhelp', 'Clear removes every filter. Done closes this panel and keeps your filters.')));
            bar.appendChild(drawer);
            S.R.active = h('div', 'di-active'); S.R.active.hidden = true; bar.appendChild(S.R.active);
            function setDrawer(open, refocus) {
                drawer.hidden = !open; S.R.more.setAttribute('aria-expanded', open ? 'true' : 'false'); bar.classList.toggle('has-drawer', open);
                if (!open && refocus) { S.R.more.focus(); }
            }
            S.R.more.addEventListener('click', function () { setDrawer(drawer.hidden, false); });
            dClose.addEventListener('click', function () { setDrawer(false, true); });
            S.R.done.addEventListener('click', function () { setDrawer(false, true); });
            bar.addEventListener('keydown', function (e) { if (e.key === 'Escape' && !drawer.hidden) { e.stopPropagation(); setDrawer(false, true); } });
            p.appendChild(S.R.barWrap);
            watchBar();
            S.R.bulk = h('div', 'di-bulk'); S.R.bulk.hidden = true; p.appendChild(S.R.bulk);
            // only this one line is announced when the list changes (a screen reader would otherwise re-read every row)
            S.R.info = h('p', 'di-help di-resinfo'); S.R.info.setAttribute('role', 'status'); S.R.info.setAttribute('aria-live', 'polite'); p.appendChild(S.R.info);
            S.R.results = h('div', 'di-tables'); S.R.results.id = 'diResults'; S.R.results.setAttribute('data-rh-results', '1');
            S.R.results.appendChild(h('p', 'di-muted di-loading', 'Loading the items…'));
            p.appendChild(S.R.results);
            var nav = h('div', 'di-actions');
            var back = h('button', 'btn-secondary di-btn'); back.type = 'button'; add(back, h('i', 'fas fa-arrow-left'), ' Back'); back.addEventListener('click', function () { showStep(2); });
            var dl = h('a', 'btn-secondary di-btn', ' Download what will be imported (CSV)');
            dl.href = page + '?action=csv&job=' + S.job; dl.setAttribute('download', '');
            dl.insertBefore(h('i', 'fas fa-download'), dl.firstChild);
            var next = h('button', 'btn-primary di-btn'); next.type = 'button'; add(next, 'Continue to confirm ', h('i', 'fas fa-arrow-right')); next.addEventListener('click', function () { showStep(4); });
            add(nav, back, dl, next);
            p.appendChild(nav);
            p.appendChild(helpLine('The download lists every item that is ready, with where it came from, so you can check it in Excel before you confirm.'));
            S.page = null;
            paintProgress();
            reload();
        }
        function clearFilters() {
            var keep = { sort: S.f.sort, size: S.f.size, view: S.f.view };
            S.f = copyDef(); S.f.status = 'all'; S.f.sort = keep.sort; S.f.size = keep.size; S.f.view = keep.view;
            S.R.q.value = '';
            reload();
        }
        function reload(o) { return loadView(o || {}); }

        function loadView(o) {
            var seq = ++S.seq;
            var res = S.R.results;
            res.classList.add('is-loading'); res.setAttribute('aria-busy', 'true');
            if (S.R.info && !S.R.info.textContent) { S.R.info.textContent = 'Loading…'; }
            var d = fpayload({ job: S.job, facets: o.facets === false ? '' : '1' });
            if (S.f.view === 'cat') { d.entries = '0'; }
            return post('review_page', d).then(function (r) {
                if (seq !== S.seq) { return; }
                if (!r.ok) { res.classList.remove('is-loading'); res.removeAttribute('aria-busy'); if (S.R.info.textContent === 'Loading…') { S.R.info.textContent = ''; } msg(r.error || 'The list could not be loaded.'); return; }
                absorb(r);
                return paintAll(o);
            });
        }
        function absorb(r) {
            // r = a page reply (lite) or an answer/bulk/fields reply's summary (overview + page)
            ['can_confirm', 'why_not', 'entry_counts', 'progress', 'confirm', 'counts', 'questions', 'sheets', 'totals', 'warnings', 'file', 'job'].forEach(function (k) { if (r[k] !== undefined) { S.sum[k] = r[k]; } });
            var pg = r.page;
            if (!pg) { return; }
            if (pg.facets) { S.facets = pg.facets; } else if (S.page && S.page.facets) { pg.facets = S.page.facets; }
            S.page = pg;
            var fl = pg.filters || {};
            S.statusEff = fl.status || [];
            S.f.page = fl.page || 1; S.f.sort = fl.sort || S.f.sort; S.f.size = fl.size || S.f.size; S.f.type = fl.type !== undefined ? fl.type : S.f.type; S.f.kind = fl.kind !== undefined ? fl.kind : S.f.kind;
        }
        function paintAll(o) {
            var res = S.R.results;
            res.classList.remove('is-loading'); res.removeAttribute('aria-busy');
            paintProgress();
            paintFilters();
            paintSheetQs();
            paintBulk();
            if (S.f.view === 'cat') { paintCats(o); } else { paintList(o); }
            syncUrl();
            if (o && typeof o.then === 'function') { o.then(); }
        }

        function paintProgress() {
            var pr = S.sum.progress || { answered: 0, total: 0, open: 0 }, ec = S.sum.entry_counts || {};
            var pct = pr.total > 0 ? Math.round(pr.answered / pr.total * 100) : 100;
            S.R.progText.textContent = num(pr.answered, 0) + ' of ' + num(pr.total, 0) + ' questions answered';
            S.R.progBar.style.width = pct + '%';
            S.R.progTrack.setAttribute('aria-valuenow', String(pct)); S.R.progTrack.setAttribute('aria-valuetext', pr.answered + ' of ' + pr.total + ' questions answered');
            var todo = (ec['needs-answer'] || 0) + (ec.blocked || 0);
            S.R.jump.disabled = todo === 0;
            S.R.jump.textContent = todo === 0 ? 'Nothing is waiting for an answer' : 'Jump to the next one that needs an answer (' + num(todo, 0) + ' item' + (todo === 1 ? '' : 's') + ' left)';
        }
        function paintFilters() {
            var fc = (S.page && S.page.facets) || S.facets;
            var st = statusList();
            if (S.page) { S.R.done.textContent = 'Done · ' + num(S.page.total, 0) + ' item' + (S.page.total === 1 ? '' : 's'); }
            clear(S.R.chips);
            var all = h('button', 'di-chip' + (st.length === 0 ? ' is-on' : '')); all.type = 'button'; all.setAttribute('aria-pressed', st.length === 0 ? 'true' : 'false');
            add(all, 'All ', h('span', 'di-chip-n', fc ? num(sumN(fc.status), 0) : '…'));
            all.addEventListener('click', function () { S.f.status = 'all'; S.f.page = 1; reload(); });
            S.R.chips.appendChild(all);
            [['needs-answer', 'Needs answer'], ['blocked', 'Blocked'], ['ready', 'Ready'], ['skipped', 'Skipped']].forEach(function (c) {
                var on = st.indexOf(c[0]) >= 0;
                var b = h('button', 'di-chip' + (on ? ' is-on' : '')); b.type = 'button'; b.setAttribute('data-di-filter', c[0]); b.setAttribute('aria-pressed', on ? 'true' : 'false');
                add(b, c[1] + ' ', h('span', 'di-chip-n', fc ? num(fc.status[c[0]] || 0, 0) : '…'));
                b.addEventListener('click', function () {
                    var cur = statusList().slice(), ix = cur.indexOf(c[0]);
                    if (ix >= 0) { cur.splice(ix, 1); } else { cur.push(c[0]); }
                    S.f.status = (cur.length === 0 || cur.length === STATUS_ORDER.length) ? 'all' : STATUS_ORDER.filter(function (x) { return cur.indexOf(x) >= 0; }).join(',');
                    S.f.page = 1; reload();
                });
                S.R.chips.appendChild(b);
            });
            if (!fc) { return; }
            var sheetOpts = [['', 'All sheets']].concat((fc.sheet_list || []).map(function (x) { return [x.name, x.name + ' (' + num(x.n, 0) + ')']; }));
            fillSelect(S.R.selSheet, sheetOpts, S.f.sheet);
            var catOpts = [['', 'All categories' + (fc.cat_total ? ' (' + num(fc.cat_total, 0) + ')' : '')]].concat((fc.cat || []).map(function (x) { return [x.name, (x.name || '(none)') + ' (' + num(x.match, 0) + ')']; }));
            if (S.f.cat && !(fc.cat || []).some(function (x) { return x.name === S.f.cat; })) { catOpts.push([S.f.cat, S.f.cat + ' (0)']); }
            fillSelect(S.R.selCat, catOpts, S.f.cat);
            fillSelect(S.R.selType, [['', 'Any kind']].concat((fc.type_list || []).map(function (x) { return [x.code, x.label + ' (' + num(x.n, 0) + ')']; })), S.f.type);
            fillSelect(S.R.selKind, [['', 'New and existing'], ['new', 'New items (' + num(fc.kind.new, 0) + ')'], ['matched', 'Existing items (' + num(fc.kind.matched, 0) + ')']], S.f.kind);
            fillSelect(S.R.selSort, SORTS, S.f.sort);
            fillSelect(S.R.selSize, SIZES.map(function (n) { return [n, n + ' items']; }), S.f.size);
            ['warn', 'cost', 'stock', 'answered'].forEach(function (k) { S.R.flags[k].checked = !!S.f[k]; S.R.flags[k + '_n'].textContent = '(' + num(fc[k] || 0, 0) + ')'; });
            Array.prototype.forEach.call(S.R.viewBox.children, function (b) { var on = b.getAttribute('data-view') === S.f.view; b.classList.toggle('is-on', on); b.setAttribute('aria-pressed', on ? 'true' : 'false'); });
            // active filters, each with the number of items it leaves
            clear(S.R.active);
            var act = [];
            var total = S.page ? S.page.total : 0;
            if (S.f.q) { act.push(['Search', '"' + S.f.q + '"', total, function () { S.f.q = ''; S.R.q.value = ''; }]); }
            if (S.f.sheet) { var sx = (fc.sheet_list || []).filter(function (x) { return x.name === S.f.sheet; })[0]; act.push(['Sheet', S.f.sheet, sx ? sx.n : 0, function () { S.f.sheet = ''; }]); }
            if (S.f.cat) { var cx = (fc.cat || []).filter(function (x) { return x.name === S.f.cat; })[0]; act.push(['Category', S.f.cat, cx ? cx.match : 0, function () { S.f.cat = ''; }]); }
            if (S.f.type) { var tx = (fc.type_list || []).filter(function (x) { return x.code === S.f.type; })[0]; act.push(['Question', tx ? tx.label : S.f.type, tx ? tx.n : 0, function () { S.f.type = ''; }]); }
            if (S.f.kind) { act.push(['Items', S.f.kind === 'new' ? 'new' : 'existing', fc.kind[S.f.kind], function () { S.f.kind = ''; }]); }
            [['warn', 'Has a warning'], ['cost', 'Cost change ticked'], ['stock', 'Already has stock'], ['answered', 'I answered a question']].forEach(function (f) {
                if (S.f[f[0]]) { act.push([f[1], '', fc[f[0]], function () { S.f[f[0]] = 0; }]); }
            });
            if (!S.page || !S.page.filters || !S.page.filters.status_default) {
                if (st.length) { act.push(['Status', st.map(function (x) { return STATUS_LABEL[x]; }).join(' + '), st.reduce(function (a, x) { return a + (fc.status[x] || 0); }, 0), function () { S.f.status = 'all'; }]); }
            }
            act.forEach(function (a) {
                var b = h('button', 'di-fchip'); b.type = 'button';
                b.setAttribute('aria-label', 'Remove filter ' + a[0] + (a[1] ? ' ' + a[1] : ''));
                add(b, a[0] + (a[1] ? ': ' + a[1] : ''), ' ', h('span', 'di-chip-n', num(a[2], 0)), ' ', h('span', 'di-x', '×'));
                b.addEventListener('click', function () { a[3](); S.f.page = 1; reload(); });
                S.R.active.appendChild(b);
            });
            S.R.active.hidden = act.length === 0;
            var n = act.filter(function (a) { return a[0] !== 'Search' && a[0] !== 'Status'; }).length;
            S.R.moreN.textContent = String(n);
            S.R.more.classList.toggle('has-filters', n > 0);
        }
        function paintSheetQs() {
            var box = clear(S.R.sheetQs);
            var qs = (S.page && S.page.sheet_questions) || [];
            box.hidden = !qs.length;
            if (!qs.length) { return; }
            add(box, h('h4', 'di-h4', 'Questions for a whole sheet'), helpLine('One answer here applies to every item on that sheet (for example the unit its new items are counted in).'));
            var shown = {};
            qs.forEach(function (q) { if (!shown[q.id]) { shown[q.id] = true; box.appendChild(questionCard(q)); } });
        }

        // ---------- the list ----------
        function pagerEl(pg, goTo) {
            var bar = h('div', 'di-pager');
            var from = pg.total === 0 ? 0 : (pg.page - 1) * pg.size + 1, to = Math.min(pg.page * pg.size, pg.total);
            var prev = h('button', 'btn-secondary di-btn', 'Previous'), nxt = h('button', 'btn-secondary di-btn', 'Next');
            prev.type = 'button'; nxt.type = 'button'; prev.disabled = pg.page <= 1; nxt.disabled = pg.page >= pg.pages;
            prev.addEventListener('click', function () { goTo(pg.page - 1); });
            nxt.addEventListener('click', function () { goTo(pg.page + 1); });
            var mid = h('span', 'di-pager-mid');
            var inp = document.createElement('input'); inp.type = 'number'; inp.min = '1'; inp.max = String(pg.pages); inp.value = String(pg.page); inp.className = 'di-pack-in di-pager-in'; inp.setAttribute('aria-label', 'Go to page');
            inp.addEventListener('keydown', function (e) { if (e.key === 'Enter') { e.preventDefault(); var v = Math.max(1, Math.min(pg.pages, parseInt(inp.value, 10) || 1)); if (v !== pg.page) { goTo(v); } } });
            add(mid, 'Showing ' + num(from, 0) + '–' + num(to, 0) + ' of ' + num(pg.total, 0) + ' · page ', inp, ' of ' + num(pg.pages, 0));
            add(bar, prev, mid, nxt);
            return bar;
        }
        function emptyState() {
            var box = h('div', 'di-empty');
            var ec = S.sum.entry_counts || {};
            var todo = (ec['needs-answer'] || 0) + (ec.blocked || 0);
            if (S.f.status === null && todo === 0 && !S.f.q && !S.f.sheet) {
                box.appendChild(h('p', null, 'Nothing is left to answer here.'));
            } else if (todo === 0 && statusList().length && ['needs-answer', 'blocked'].some(function (x) { return statusList().indexOf(x) >= 0; })) {
                box.appendChild(h('p', null, 'Nothing is waiting for an answer any more. You can check the other items, or continue to confirm.'));
                var sb = h('button', 'btn-secondary di-btn', 'Show all items'); sb.type = 'button';
                sb.addEventListener('click', function () { S.f.status = 'all'; S.f.page = 1; reload(); });
                box.appendChild(sb);
                return box;
            } else {
                box.appendChild(h('p', null, 'No rows match these filters.'));
            }
            var cb = h('button', 'btn-secondary di-btn', 'Clear filters'); cb.type = 'button'; cb.addEventListener('click', clearFilters);
            box.appendChild(cb);
            return box;
        }
        function paintList(o) {
            var box = clear(S.R.results), pg = S.page;
            clear(S.R.info);
            S.R.info.textContent = pg.total === 0 ? '' : 'Showing ' + num(Math.min(pg.size, pg.entries.length), 0) + ' of ' + num(pg.total, 0) + ' items. They load one page at a time, so big files stay fast.';
            if (!pg.entries.length) { box.appendChild(emptyState()); return; }
            box.appendChild(entriesTable(pg.entries, {}));
            box.appendChild(pagerEl(pg, function (n) { S.f.page = n; reload({ facets: false, then: function () { scrollToEl(S.R.results); } }); }));
            if (o && o.scrollTo !== undefined) { var t = box.querySelector('tr.di-row[data-i="' + o.scrollTo + '"]'); if (t) { scrollToEl(t, true); } }
        }

        // ---------- by category ----------
        function paintCats(o) {
            var box = clear(S.R.results), fc = (S.page && S.page.facets) || S.facets;
            clear(S.R.info);
            if (!fc) { return; }
            var cats = (fc.cat || []).filter(function (c) { return c.match > 0 && (!S.f.cat || c.name === S.f.cat); });
            S.R.info.textContent = cats.length ? num(cats.length, 0) + ' categor' + (cats.length === 1 ? 'y' : 'ies') + ' with items that match. Open one to see its items.' + (fc.cat_total > (fc.cat || []).length ? ' Only the first ' + (fc.cat || []).length + ' categories are listed: use Search or the Category filter for the others.' : '') : '';
            if (!cats.length) { box.appendChild(emptyState()); return; }
            if (!S.openCats) { S.openCats = {}; }
            if (!Object.keys(S.openCats).length) {
                var first = cats.filter(function (c) { return c.needs + c.blocked > 0; })[0] || cats[0];
                S.openCats[first.name] = { page: 1 };
            }
            var pending = [];
            cats.forEach(function (c) {
                var cb = h('div', 'di-catbox');
                var isOpen = !!S.openCats[c.name];
                var hd = h('button', 'di-cathead'); hd.type = 'button'; hd.setAttribute('aria-expanded', isOpen ? 'true' : 'false');
                var done = c.items ? Math.round((c.ready + c.skipped) / c.items * 100) : 100;
                var bits = [num(c.items, 0) + ' item' + (c.items === 1 ? '' : 's'), num(c.needs + c.blocked, 0) + ' need an answer', num(c.skipped, 0) + ' skipped'];
                add(hd, h('i', 'fas ' + (isOpen ? 'fa-chevron-down' : 'fa-chevron-right')), ' ', (function () { var cn = h('strong', 'di-catname', c.name || '(no category)'); cn.title = c.name || '(no category)'; return cn; })(), ' ',h('span', 'di-small', bits.join(' · ')));
                var mini = h('span', 'di-mini'); var mb = h('span', 'di-mini-bar'); mb.style.width = done + '%'; mini.appendChild(mb); hd.appendChild(mini);
                var body = h('div', 'di-catbody'); body.hidden = !isOpen; body.setAttribute('data-cat', c.name);
                hd.addEventListener('click', function () {
                    if (S.openCats[c.name]) { delete S.openCats[c.name]; body.hidden = true; hd.setAttribute('aria-expanded', 'false'); clear(body); hd.firstChild.className = 'fas fa-chevron-right'; }
                    else {
                        var keys = Object.keys(S.openCats); if (keys.length >= 8) { delete S.openCats[keys[0]]; }
                        S.openCats[c.name] = { page: 1 }; paintCats();
                    }
                });
                add(cb, hd, body);
                box.appendChild(cb);
                if (isOpen) { pending.push([c, body]); }
            });
            pending.forEach(function (x, ix) { loadCat(x[0], x[1], ix === 0 && o ? o.scrollTo : undefined); });
        }
        function loadCat(c, body, scrollTo) {
            var st = S.openCats[c.name] || { page: 1 };
            body.appendChild(h('p', 'di-muted', 'Loading…'));
            var d = fpayload({ job: S.job, cat: c.name, page: st.page, facets: '' });
            post('review_page', d).then(function (r) {
                if (!r.ok || !r.page) { clear(body); body.appendChild(h('p', 'di-warn', r.error || 'Could not load this category.')); return; }
                if (!S.openCats[c.name]) { return; }
                st.page = r.page.page;
                clear(body);
                if (!r.page.entries.length) { body.appendChild(h('p', 'di-muted', 'No items in this category match the filters.')); return; }
                body.appendChild(entriesTable(r.page.entries, {}));
                if (r.page.pages > 1) { body.appendChild(pagerEl(r.page, function (n) { S.openCats[c.name].page = n; clear(body); loadCat(c, body); })); }
                if (scrollTo !== undefined) { var t = body.querySelector('tr.di-row[data-i="' + scrollTo + '"]'); if (t) { scrollToEl(t, true); } }
            });
        }

        // ---------- jump to the next question ----------
        function jumpNext() {
            if (!S.R || !S.R.results) { return; }
            var rows = Array.prototype.slice.call(S.R.results.querySelectorAll('tr.di-row[data-open="1"]'));
            var off = stickyOffset() + 40;
            var nxt = null;
            for (var i = 0; i < rows.length; i++) { if (rows[i].getBoundingClientRect().top > off) { nxt = rows[i]; break; } }
            if (nxt) { scrollToEl(nxt, true); return; }
            var st = statusList(), ec = S.sum.entry_counts || {};
            var todo = (ec['needs-answer'] || 0) + (ec.blocked || 0);
            if (!todo) { msg('Nothing is waiting for an answer.', 'ok'); return; }
            var wantsTodo = st.length === 0 || st.indexOf('needs-answer') >= 0 || st.indexOf('blocked') >= 0;
            if (!wantsTodo) { S.f.status = null; S.f.page = 1; msg('Showing only the items that need an answer.', 'ok'); reload({ then: function () { jumpNext(); } }); return; }
            if (S.f.view === 'cat') {
                var fc = (S.page && S.page.facets) || S.facets, cats = (fc && fc.cat) || [];
                var cand = cats.filter(function (c) { return c.needs + c.blocked > 0 && !S.openCats[c.name]; })[0];
                if (cand) { S.openCats[cand.name] = { page: 1 }; paintCats({ scrollTo: undefined }); window.setTimeout(function () { jumpNext(); }, 700); return; }
                msg('There are no more items that need an answer in the categories shown.', 'ok');
                return;
            }
            var no = S.page && S.page.next_open;
            if (no) { S.f.page = no.page; reload({ facets: false, scrollTo: no.i }); return; }
            if (S.page && S.page.page > 1) { S.f.page = 1; msg('That was the last one on the list. Starting again from the top.', 'ok'); reload({ facets: false, then: function () { var f = S.R.results.querySelector('tr.di-row[data-open="1"]'); if (f) { scrollToEl(f, true); } } }); return; }
            msg('There are no more items that need an answer below this point.', 'ok');
        }
        if (window.__diKeys) { document.removeEventListener('keydown', window.__diKeys); }
        window.__diKeys = function (e) {
            if (e.defaultPrevented || e.ctrlKey || e.metaKey || e.altKey || (e.key !== 'n' && e.key !== 'N')) { return; }
            var t = e.target, tag = t && t.tagName ? t.tagName.toUpperCase() : '';
            if (tag === 'INPUT' || tag === 'TEXTAREA' || tag === 'SELECT' || (t && t.isContentEditable)) { return; }
            if (S.step !== 3 || !panels[3] || panels[3].hidden || !document.body.contains(panels[3])) { return; }
            if (document.body.classList.contains('modal-open')) { return; }       // a confirm box is open: the key is not for the list
            e.preventDefault(); jumpNext();
        };
        document.addEventListener('keydown', window.__diKeys);
        // while the bar is pinned to the top it drops its helper lines, so it does not cover the list (they show again at rest)
        if (window.__diScroll) { window.removeEventListener('scroll', window.__diScroll); }
        window.__diScroll = function () {
            var b = S.R && S.R.bar, w = S.R && S.R.barWrap;
            if (!b || !w || !document.body.contains(w) || panels[3].hidden) { return; }
            var off = headerBottom(), wr = w.getBoundingClientRect();
            var stick = wr.top <= off + 2 && S.R.results.getBoundingClientRect().bottom > off + 220;
            if (stick && !b.classList.contains('is-stuck')) { w.style.height = b.offsetHeight + 'px'; b.classList.add('is-stuck'); w.style.height = b.offsetHeight + 'px'; }
            if (!stick && b.classList.contains('is-stuck')) { b.classList.remove('is-stuck'); w.style.height = ''; b.style.top = ''; b.style.left = ''; b.style.width = ''; b.style.maxHeight = ''; }
            if (stick) { b.style.top = off + 'px'; b.style.left = wr.left + 'px'; b.style.width = wr.width + 'px'; b.style.maxHeight = Math.max(160, window.innerHeight - off - 16) + 'px'; S.R.stuckH = b.offsetHeight; }
        };
        window.addEventListener('scroll', window.__diScroll, { passive: true });
        window.addEventListener('resize', window.__diScroll);
        // the admin sidebar collapsing / opening moves the page sideways without a scroll or resize event: follow the bar's own place holder
        function watchBar() {
            if (window.__diRO) { window.__diRO.disconnect(); window.__diRO = null; }
            if (window.ResizeObserver && S.R && S.R.barWrap) {
                window.__diRO = new window.ResizeObserver(function () { if (window.__diScroll) { window.__diScroll(); } });
                window.__diRO.observe(S.R.barWrap);
            }
        }

        // ---------- bulk answers ----------
        function paintBulk() {
            var box = clear(S.R.bulk), groups = (S.page && S.page.bulk) || [];
            box.hidden = !groups.length;
            if (!groups.length) { S.bulkSel = null; return; }
            var sel = S.bulkSel && groups.filter(function (g) { return g.type + '|' + g.sig === S.bulkSel.gk; })[0] ? S.bulkSel : { gk: groups[0].type + '|' + groups[0].sig, role: '' };
            S.bulkSel = sel;
            var g = groups.filter(function (x) { return x.type + '|' + x.sig === sel.gk; })[0];
            add(box, h('h4', 'di-h4', 'Answer many at once'),
                helpLine('Give one answer to every question of the same kind in the list you are looking at (all pages). You see how many, and some names, before anything is saved.'));
            var row = h('div', 'di-bulk-row');
            var gs = mkSelect('diBulkG', 'Which questions');
            fillSelect(gs, groups.map(function (x) { return [x.type + '|' + x.sig, (TYPE_LABEL[x.type] || x.type) + ' (' + num(x.n, 0) + ' questions)']; }), sel.gk);
            gs.addEventListener('change', function () { S.bulkSel = { gk: gs.value, role: '' }; paintBulk(); });
            var as = mkSelect('diBulkA', 'Answer for all of them');
            fillSelect(as, [['', 'Choose an answer…']].concat(g.answers.map(function (a) { return [a.role, a.label]; })), sel.role);
            as.addEventListener('change', function () { S.bulkSel.role = as.value; go.disabled = !as.value; });
            var go = h('button', 'btn-primary di-btn', 'Check and apply…'); go.type = 'button'; go.disabled = !sel.role;
            go.addEventListener('click', function () { bulkPreview(g, S.bulkSel.role, box, go); });
            add(row, ctl('Which questions', gs, 'The kind of question to answer. ' + num(g.n, 0) + ' are waiting in this list.'), ctl('Answer for all of them', as, 'Only answers that every one of these questions accepts are offered.'), go);
            box.appendChild(row);
        }
        // What the confirm button says it will do (client wording only; the answer itself is still the server's role key).
        var BULK_VERB = { 'skip': 'Skip', 'use-suggestion': 'Use the suggested match for', 'file': 'Keep the file price for', 'current': 'Keep the current price for', 'keep': 'Keep as written for' };
        function bulkVerb(role, n) { return (BULK_VERB[role] || 'Apply to') + ' ' + num(n, 0) + ' row' + (n === 1 ? '' : 's'); }
        function shortName(t) { t = String(t); return t.length > 60 ? t.slice(0, 59) + '…' : t; }
        function bulkReasons(o) { return Object.keys(o || {}).map(function (k) { return k + ' (' + o[k] + ')'; }).join('; '); }
        function bulkPreview(g, role, box, trigger) {
            trigger.disabled = true;
            post('bulk', Object.assign(fpayload({ job: S.job }), { btype: g.type, sig: g.sig, role: role, dry: '1' })).then(function (r) {
                trigger.disabled = !S.bulkSel || !S.bulkSel.role;
                if (!r.ok) { msg(r.error || 'Could not check the list.'); return; }
                if (!(r.would_apply > 0)) {
                    msg('None of the ' + num(r.n, 0) + ' questions can take “' + r.label + '”' + (r.would_refuse > 0 ? ': ' + bulkReasons(r.reasons) : '') + '. Nothing was changed.');
                    return;
                }
                var samples = (r.samples || []).slice(0, 5).map(shortName);
                var details = samples.slice();
                if (r.n > samples.length) { details.push('… and ' + num(r.n - samples.length, 0) + ' more'); }
                if (r.would_refuse > 0) { details.push(num(r.would_refuse, 0) + ' of them cannot take this answer and stay as they are: ' + bulkReasons(r.reasons)); }
                var count = num(r.would_apply, 0) + ' question' + (r.would_apply === 1 ? '' : 's');
                var text = 'Apply “' + r.label + '” to ' + count + ' in the list you are looking at, on every page. It cannot be undone in one step: to change your mind, change the answers one at a time.';
                var confirmText = bulkVerb(role, r.would_apply);
                var ask;
                if (window.AdminConfirm && typeof window.AdminConfirm.request === 'function') {
                    ask = window.AdminConfirm.request({ title: 'Answer ' + count + ' at once?', message: text, details: details, confirmText: confirmText, tone: 'warning', icon: 'fa-clipboard-check' });
                    // this cannot be undone in one step, so the safe button gets the focus (the box itself starts on the confirm button)
                    window.setTimeout(function () { var c = document.querySelector('#adminConfirmModal .admin-confirm-actions [data-admin-confirm-cancel]'); if (c) { c.focus(); } }, 120);
                } else {
                    ask = Promise.resolve(window.confirm(confirmText + '?\n\n' + text + '\n\n' + (r.samples || []).slice(0, 5).join('\n')));
                }
                ask.then(function (yes) {
                    if (!yes) { if (document.body.contains(trigger)) { trigger.focus(); } return; }
                    trigger.disabled = true; trigger.textContent = 'Applying…';
                    var y = window.pageYOffset;
                    post('bulk', Object.assign(fpayload({ job: S.job }), { btype: g.type, sig: g.sig, role: role, fingerprint: r.fingerprint })).then(function (a) {
                        if (!a.ok) { trigger.disabled = false; trigger.textContent = 'Check and apply…'; msg(a.error || 'Nothing was applied.'); if (document.body.contains(trigger)) { trigger.focus(); } return; }
                        S.bulkSel = null;
                        msg('Applied ' + num(a.applied, 0) + ', refused ' + num(a.refused, 0) + (a.refused ? ': ' + bulkReasons(a.reasons) : '') + '.', a.refused ? 'error' : 'ok');
                        if (a.summary && a.summary.ok) { S.sum = Object.assign(S.sum, a.summary); delete S.sum.page; absorb(a.summary); paintAll({}); window.scrollTo({ top: y }); }
                        else { reload(); }
                        if (S.f.view === 'cat') { reload(); }
                        var nx = document.getElementById('diBulkG') || S.R.jump;
                        if (nx && !nx.disabled) { try { nx.focus({ preventScroll: true }); } catch (e) { /* ignore */ } }
                    });
                });
            });
        }

        // ---------- rows ----------
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
        function entriesTable(entries, shown) {
            var wrap = h('div', 'di-tablewrap');
            var tb = h('table', 'di-table di-items');
            var thead = h('thead'), tr = h('tr');
            HEADS.forEach(function (c) { var th = h('th'); add(th, h('span', null, c[0]), h('small', 'di-th-help', c[1])); tr.appendChild(th); });
            thead.appendChild(tr); tb.appendChild(thead);
            var body = h('tbody');
            entries.forEach(function (e) {
                var rows2 = e.kind === 'item' ? itemRows(e) : plainRow(e);
                rows2.forEach(function (r) { body.appendChild(r); });
                var q = qRow(e, shown);
                if (q) { body.appendChild(q); }
            });
            tb.appendChild(body); wrap.appendChild(tb);
            return wrap;
        }
        /** An item name: two lines at most, the whole name in the tooltip. */
        function itemNameEl(text) { var s = h('strong', 'di-name', text); s.title = text; return s; }
        function cell(label, cls) { var td = h('td', cls || ''); td.setAttribute('data-label', label); return td; }
        function rowAttrs(r, e) {
            r.setAttribute('data-i', e.i);
            if (e.status === 'needs-answer' || e.status === 'blocked') { r.setAttribute('data-open', '1'); }
        }
        function qRow(e, shown) {
            var qs = e.qs || [];
            if (!qs.length && !e.wait_sheet) { return null; }
            var tr = h('tr', 'di-row di-row--detail di-row--q');
            var td = h('td'); td.colSpan = 8;
            var open = qs.filter(isOpenQ), done = qs.filter(function (q) { return !isOpenQ(q); });
            open.forEach(function (q) {
                if (shown[q.id]) { td.appendChild(h('p', 'di-q-note', 'The question "' + q.text.slice(0, 90) + (q.text.length > 90 ? '…' : '') + '" covers this item too: it is answered once, above.')); return; }
                shown[q.id] = true; td.appendChild(questionCard(q));
            });
            if (e.wait_sheet && !open.length) { td.appendChild(h('p', 'di-q-note', 'This item is waiting for the question about the whole sheet (see "Questions for a whole sheet" above the list).')); }
            if (done.length) {
                var d = h('details', 'di-details');
                d.appendChild(h('summary', null, 'Your answers on this item (' + done.length + ')'));
                done.forEach(function (q) { if (!shown[q.id]) { shown[q.id] = true; d.appendChild(questionCard(q)); } });
                td.appendChild(d);
            }
            tr.appendChild(td);
            return tr;
        }
        function plainRow(e) {
            var r = h('tr', 'di-row di-row--' + e.status);
            rowAttrs(r, e);
            var t1 = cell('Item'); add(t1, itemNameEl(e.r.name || '(no name)'), src(e.r.ref));
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
            rowAttrs(r, e);
            var t1 = cell('Item');
            add(t1, itemNameEl(it.name));
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
                add(t7, h('span', null, 'Matches existing: '), itemNameEl(it.match.name),
                    h('div', 'di-small', it.match.how === 'chosen' ? 'You chose this match.' : (it.match.how === 'learned' ? 'Remembered from an earlier import.' : 'Same name as an ingredient already in stock.')));
                if (it.match.archived) { t7.appendChild(h('div', 'di-small', 'That ingredient is archived.')); }
            } else {
                t7.appendChild(h('span', null, e.status === 'skipped' ? 'Not imported.' : 'New ingredient will be created.'));
            }
            if (e.status !== 'skipped') { t7.appendChild(h('div', 'di-small', it.in_count ? 'Its stock goes into the opening count.' : (it.balance ? 'Stock is NOT put in the opening count.' : ''))); }
            var t8 = cell('Status');
            t8.appendChild(pill(e.status));
            (it.warnings || []).forEach(function (w) { t8.appendChild(h('div', 'di-warnline', w)); });
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
                    S.chain = S.chain.then(function () { return post('fields', Object.assign({ job: S.job, key: it.update_key, fields: picked }, pagedExtra())); }).then(function (r) {
                        if (!r.ok) { msg(r.error || 'That choice could not be saved.'); boxes.forEach(function (b) { b.disabled = false; }); return; }
                        refresh(r.summary);
                    });
                });
            });
            td.appendChild(box); tr.appendChild(td);
            return tr;
        }
        /** A change on the list (an answer, a ticked field) comes back with the refreshed page of a list view; the category view reloads itself. */
        function pagedExtra() {
            return (S.step === 3 && S.f.view === 'list' && S.R && S.R.results) ? fpayload({ paged: '1', facets: '1' }) : {};
        }

        // ---------- step 4: confirm ----------
        function renderConfirm() {
            var p = clear(panels[4]);
            var s = S.sum;
            var cf = s.confirm || {};          // worked out on the server over the whole file (the browser never holds every item)
            var c = { skipped: cf.skipped || 0 };
            var toAnswer = cf.to_answer || 0;
            add(p, h('h3', 'di-h', 'Step 4 · Confirm'));
            add(p, helpLine('Last check. These are the numbers for the whole file. Importing is one all-or-nothing step: if anything goes wrong, nothing is saved.'));
            var cards = h('div', 'di-cards');
            add(cards,
                card('New ingredients', num(cf.creates || 0, 0), 'Items not in your stock yet. They will be created.', 'good'),
                card('Existing items updated', num(cf.updates || 0, 0), 'Existing items where you ticked a field to change.'),
                card('Existing items left as they are', num(cf.kept || 0, 0), 'Matched to an ingredient already in stock, with nothing ticked.'),
                card('Lines in the opening count', num(cf.in_count || 0, 0), 'Items with stock and a price. They go into one opening count that another manager must approve.'),
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
            var cws = cf.cost_with_stock || 0, cwsNames = cf.cost_with_stock_names || [];
            if (cws) {
                what.appendChild(h('p', 'di-warn', num(cws, 0) + ' cost change' + (cws === 1 ? ' affects an item that already has' : 's affect items that already have') + ' stock. Their recipe costs and stock value change as soon as you confirm, before the opening count is approved: ' + cwsNames.join(', ') + (cws > cwsNames.length ? ' and ' + num(cws - cwsNames.length, 0) + ' more' : '') + '.'));
            }
            var noPriceN = cf.no_price || 0;
            if (noPriceN) { what.appendChild(h('p', 'di-warn', num(noPriceN, 0) + ' item' + (noPriceN === 1 ? ' has' : 's have') + ' stock but no price in the file. They are created without a cost and their stock is left out of the opening count. Add the cost later.')); }
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
            var nav = h('div', 'di-actions di-actions--grid');
            /* EXPORT-BUTTON-SPOT: Part B adds its one export button to this row (Result step). Part A (Step 3 review) leaves this row alone. */
            var xb = h('button', 'btn-secondary di-btn', 'Download the updated stock workbook'); xb.type = 'button';
            xb.title = 'An Excel workbook of the stock as it is right now, one tab per category. Stock from this import appears once its opening count is approved.';
            xb.addEventListener('click', function () {
                var f = document.createElement('form'); f.method = 'post'; f.action = page;
                [['action', 'export'], ['scope', 'all'], ['csrf_token', csrf]].forEach(function (kv) { var i = document.createElement('input'); i.type = 'hidden'; i.name = kv[0]; i.value = kv[1]; f.appendChild(i); });
                document.body.appendChild(f); f.submit(); document.body.removeChild(f);
            });
            nav.appendChild(xb);
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
            p.appendChild(helpLine('The workbook button gives you an Excel file of your stock as it is right now, one tab per category. Stock from this import shows in it once its opening count is approved.'));
        }

        // ---------- start ----------
        showStep(1);
        loadMine();
        var start = Number(root.getAttribute('data-job')) || 0;
        if (start > 0) { loadJob(start, 0, true); }
    }

    window.rhDataImport = { init: init };
})();
