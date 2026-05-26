(function () {
    'use strict';

    function initReceiptTemplateAutocomplete() {
        var form = document.getElementById('receiptTemplateForm');
        if (!form) {
            return;
        }

        var tokens = [];
        try {
            tokens = JSON.parse(form.dataset.receiptPlaceholderTokens || '[]') || [];
        } catch (error) {
            tokens = [];
        }
        if (!Array.isArray(tokens) || !tokens.length) {
            return;
        }

        var state = {
            field: null,
            tokenStart: -1,
            filtered: [],
            activeIndex: 0
        };
        var menu = document.createElement('div');
        menu.className = 'receipts-autocomplete';
        menu.style.display = 'none';
        document.body.appendChild(menu);

        function hideMenu() {
            menu.style.display = 'none';
            menu.innerHTML = '';
            state.field = null;
            state.tokenStart = -1;
            state.filtered = [];
            state.activeIndex = 0;
        }

        function positionMenu(field) {
            var rect = field.getBoundingClientRect();
            menu.style.left = (window.scrollX + rect.left) + 'px';
            menu.style.top = (window.scrollY + rect.bottom + 4) + 'px';
            menu.style.width = Math.min(rect.width, 420) + 'px';
        }

        function updateActiveItem() {
            var items = menu.querySelectorAll('.receipts-autocomplete__item');
            items.forEach(function (item, index) {
                item.classList.toggle('is-active', index === state.activeIndex);
            });
        }

        function applySelection(index) {
            var field = state.field;
            if (!field) {
                hideMenu();
                return;
            }

            var token = state.filtered[index] || state.filtered[0];
            if (!token) {
                hideMenu();
                return;
            }

            var caret = field.selectionStart;
            var before = field.value.substring(0, state.tokenStart);
            var after = field.value.substring(caret);
            field.value = before + token + after;
            var newPos = before.length + token.length;
            field.selectionStart = newPos;
            field.selectionEnd = newPos;
            field.focus();
            field.dispatchEvent(new Event('input', { bubbles: true }));
            hideMenu();
        }

        function renderMenu(field, filtered, tokenStart) {
            state.field = field;
            state.filtered = filtered;
            state.tokenStart = tokenStart;
            state.activeIndex = 0;
            menu.innerHTML = '';

            filtered.forEach(function (token, index) {
                var btn = document.createElement('button');
                btn.type = 'button';
                btn.className = 'receipts-autocomplete__item';
                if (index === 0) {
                    btn.classList.add('is-active');
                }
                btn.textContent = token;
                btn.addEventListener('mousedown', function (event) {
                    event.preventDefault();
                    applySelection(index);
                });
                menu.appendChild(btn);
            });

            positionMenu(field);
            menu.style.display = 'block';
        }

        function refreshMenu(field) {
            if (!field || typeof field.selectionStart !== 'number') {
                hideMenu();
                return;
            }

            var caret = field.selectionStart;
            var beforeCaret = field.value.slice(0, caret);
            var match = beforeCaret.match(/\{\{[a-zA-Z0-9_]*$/);
            if (!match) {
                hideMenu();
                return;
            }

            var partial = match[0];
            var query = partial.slice(2).toLowerCase();
            var tokenStart = caret - partial.length;
            var filtered = tokens.filter(function (token) {
                return token.slice(2, -2).toLowerCase().indexOf(query) !== -1;
            }).slice(0, 10);

            if (!filtered.length) {
                hideMenu();
                return;
            }

            renderMenu(field, filtered, tokenStart);
        }

        function bindField(field) {
            field.addEventListener('input', function () {
                refreshMenu(field);
            });
            field.addEventListener('click', function () {
                refreshMenu(field);
            });
            field.addEventListener('keyup', function (event) {
                if (event.key === 'ArrowDown' || event.key === 'ArrowUp' || event.key === 'Enter' || event.key === 'Tab' || event.key === 'Escape') {
                    return;
                }
                refreshMenu(field);
            });
            field.addEventListener('keydown', function (event) {
                if (menu.style.display !== 'block' || state.field !== field) {
                    return;
                }
                if (event.key === 'ArrowDown') {
                    event.preventDefault();
                    state.activeIndex = (state.activeIndex + 1) % state.filtered.length;
                    updateActiveItem();
                    return;
                }
                if (event.key === 'ArrowUp') {
                    event.preventDefault();
                    state.activeIndex = (state.activeIndex - 1 + state.filtered.length) % state.filtered.length;
                    updateActiveItem();
                    return;
                }
                if (event.key === 'Enter' || event.key === 'Tab') {
                    event.preventDefault();
                    applySelection(state.activeIndex);
                    return;
                }
                if (event.key === 'Escape') {
                    event.preventDefault();
                    hideMenu();
                }
            });
            field.addEventListener('blur', function () {
                setTimeout(hideMenu, 120);
            });
        }

        [
            document.getElementById('receiptEmailSubject'),
            document.getElementById('receiptEmailTemplate'),
            document.getElementById('receiptWhatsappTemplate')
        ].filter(Boolean).forEach(bindField);

        window.addEventListener('resize', function () {
            if (state.field) {
                positionMenu(state.field);
            }
        });
        window.addEventListener('scroll', function () {
            if (state.field) {
                positionMenu(state.field);
            }
        }, true);
    }

    function setUrlPage(page) {
        var url = new URL(window.location.href);
        url.searchParams.set('page', String(page));
        history.replaceState(history.state, '', url.toString());
    }

    function initReceiptClientPagination() {
        var scope = document.querySelector('[data-receipts-pagination-scope]');
        if (!scope) {
            return;
        }

        var rows = Array.prototype.slice.call(scope.querySelectorAll('[data-receipts-row]'));
        var nav = scope.querySelector('.receipts-pagination');
        var pageSize = parseInt(scope.dataset.pageSize || '10', 10);
        var totalPages = parseInt(scope.dataset.totalPages || '1', 10);
        var currentPage = parseInt(scope.dataset.currentPage || '1', 10);

        if (!rows.length || !nav || totalPages <= 1 || !pageSize) {
            return;
        }

        function buildHref(page) {
            var url = new URL(window.location.href);
            url.searchParams.set('page', String(page));
            return url.pathname + url.search;
        }

        function windowRange(page) {
            var start = Math.max(1, page - 2);
            var end = Math.min(totalPages, start + 4);
            if ((end - start) < 4) {
                start = Math.max(1, end - 4);
            }
            return { start: start, end: end };
        }

        function renderRows(page) {
            rows.forEach(function (row) {
                var rowPage = parseInt(row.dataset.pageIndex || '1', 10);
                row.hidden = rowPage !== page;
            });
        }

        function renderNav(page) {
            var range = windowRange(page);
            var html = [];

            if (page > 1) {
                html.push('<a class="acct-btn acct-btn--ghost receipts-pagination__link" data-page-link="' + (page - 1) + '" href="' + buildHref(page - 1) + '"><i class="fas fa-chevron-left"></i> Prev</a>');
            } else {
                html.push('<span class="acct-btn acct-btn--ghost receipts-pagination__link is-disabled"><i class="fas fa-chevron-left"></i> Prev</span>');
            }

            for (var i = range.start; i <= range.end; i++) {
                html.push('<a class="acct-btn receipts-pagination__link ' + (i === page ? 'acct-btn--primary' : 'acct-btn--ghost') + '" data-page-link="' + i + '" href="' + buildHref(i) + '">' + i + '</a>');
            }

            if (page < totalPages) {
                html.push('<a class="acct-btn acct-btn--ghost receipts-pagination__link" data-page-link="' + (page + 1) + '" href="' + buildHref(page + 1) + '">Next <i class="fas fa-chevron-right"></i></a>');
            } else {
                html.push('<span class="acct-btn acct-btn--ghost receipts-pagination__link is-disabled">Next <i class="fas fa-chevron-right"></i></span>');
            }

            nav.innerHTML = html.join('');
        }

        function goToPage(page) {
            var safePage = Math.min(Math.max(page, 1), totalPages);
            currentPage = safePage;
            scope.dataset.currentPage = String(safePage);
            renderRows(safePage);
            renderNav(safePage);
            setUrlPage(safePage);
        }

        nav.addEventListener('click', function (event) {
            var link = event.target.closest('[data-page-link]');
            if (!link) {
                return;
            }
            event.preventDefault();
            goToPage(parseInt(link.dataset.pageLink || '1', 10));
        });

        goToPage(currentPage);
    }

    function replaceTokens(template, tokenMap) {
        var output = template || '';
        Object.keys(tokenMap).forEach(function (key) {
            output = output.split(key).join(String(tokenMap[key] || ''));
        });
        return output;
    }

    function initReceiptTemplatePreview() {
        var panel = document.getElementById('receiptsTemplatePreview');
        var toggle = document.getElementById('receiptsPreviewToggle');
        var subjectInput = document.getElementById('receiptEmailSubject');
        var emailInput = document.getElementById('receiptEmailTemplate');
        var whatsappInput = document.getElementById('receiptWhatsappTemplate');
        var previewSubject = document.getElementById('receiptPreviewSubject');
        var previewFrame = document.getElementById('receiptPreviewFrame');
        var previewWhatsapp = document.getElementById('receiptPreviewWhatsapp');

        if (!panel || !toggle || !subjectInput || !emailInput || !whatsappInput || !previewSubject || !previewFrame || !previewWhatsapp) {
            return;
        }

        var tokenMap = {};
        try {
            tokenMap = JSON.parse(panel.dataset.previewMap || '{}') || {};
        } catch (error) {
            tokenMap = {};
        }

        function renderPreview() {
            var subject = replaceTokens(subjectInput.value, tokenMap);
            var email = replaceTokens(emailInput.value, tokenMap);
            var whatsapp = replaceTokens(whatsappInput.value, tokenMap);
            previewSubject.textContent = subject;
            previewWhatsapp.textContent = whatsapp;
            previewFrame.srcdoc = email;
        }

        function setPanelState(isOpen) {
            panel.hidden = !isOpen;
            toggle.setAttribute('aria-expanded', isOpen ? 'true' : 'false');
            toggle.innerHTML = isOpen
                ? '<i class="fas fa-eye-slash"></i> Hide Preview'
                : '<i class="fas fa-eye"></i> Preview';
            if (isOpen) {
                renderPreview();
            }
        }

        toggle.addEventListener('click', function () {
            setPanelState(panel.hidden);
        });

        [subjectInput, emailInput, whatsappInput].forEach(function (field) {
            field.addEventListener('input', function () {
                if (!panel.hidden) {
                    renderPreview();
                }
            });
        });

        setPanelState(false);
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', function () {
            initReceiptClientPagination();
            initReceiptTemplateAutocomplete();
            initReceiptTemplatePreview();
        });
    } else {
        initReceiptClientPagination();
        initReceiptTemplateAutocomplete();
        initReceiptTemplatePreview();
    }
})();
