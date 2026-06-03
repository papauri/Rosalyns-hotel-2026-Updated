/**
 * Admin Components JavaScript
 * Modal and Alert functionality for admin pages
 */

(function () {
    'use strict';

    // ============================================
    // MODAL CONTROLLER
    // ============================================

    window.Modal = {
        activeModals: [],

        syncModalTableLabels: function (scope) {
            const root = scope && typeof scope.querySelectorAll === 'function' ? scope : document;
            const tables = root.querySelectorAll('.modal table, .modal-overlay table, .admin-modal table, .modal__body table, .modal-body table');

            tables.forEach((table) => {
                const headers = Array.from(table.querySelectorAll('thead th')).map((cell) => cell.textContent.trim());
                if (!headers.length) {
                    return;
                }

                table.querySelectorAll('tbody tr').forEach((row) => {
                    row.querySelectorAll('td').forEach((cell, index) => {
                        const existingLabel = (cell.getAttribute('data-label') || '').trim();
                        if (existingLabel !== '') {
                            return;
                        }

                        const headerLabel = headers[index] || '';
                        if (headerLabel !== '') {
                            cell.setAttribute('data-label', headerLabel);
                        }
                    });
                });
            });
        },

        // Open a modal by ID
        open: function (modalId) {
            const modal = document.getElementById(modalId);
            const overlay = document.getElementById(modalId + '-overlay');

            if (!modal) {
                return;
            }

            // Add to active modals stack
            this.activeModals.push(modalId);

            // Show modal and overlay
            modal.classList.add('active');
            if (overlay) overlay.classList.add('active');

            // Prevent body scroll
            document.body.classList.add('modal-open');

            // Ensure modal tables have data labels for responsive stacked rendering
            this.syncModalTableLabels(modal);

            // Focus first focusable element
            setTimeout(() => {
                const focusable = modal.querySelector('button, [href], input, select, textarea, [tabindex]:not([tabindex="-1"])');
                if (focusable) focusable.focus();
            }, 100);

            // Trigger custom event
            modal.dispatchEvent(new CustomEvent('modal:open', { detail: { modalId } }));
        },

        // Close a modal by ID
        close: function (modalId) {
            const modal = document.getElementById(modalId);
            const overlay = document.getElementById(modalId + '-overlay');

            if (!modal) return;

            // Remove from active modals
            this.activeModals = this.activeModals.filter(id => id !== modalId);

            // Hide modal and overlay
            modal.classList.remove('active');
            if (overlay) overlay.classList.remove('active');

            // Restore body scroll if no modals are open
            if (this.activeModals.length === 0) {
                document.body.classList.remove('modal-open');
            }

            // Trigger custom event
            modal.dispatchEvent(new CustomEvent('modal:close', { detail: { modalId } }));
        },

        // Close all open modals
        closeAll: function () {
            [...this.activeModals].forEach(id => this.close(id));
        },

        // Initialize modal event listeners
        init: function () {
            this.syncModalTableLabels(document);

            // Close button clicks
            document.querySelectorAll('[data-modal-close]').forEach(btn => {
                btn.addEventListener('click', (e) => {
                    const modal = btn.closest('[data-modal]');
                    if (modal) this.close(modal.id);
                });
            });

            // Overlay clicks
            document.querySelectorAll('[data-modal-overlay]').forEach(overlay => {
                overlay.addEventListener('click', (e) => {
                    if (e.target === overlay) {
                        const modalId = overlay.id.replace('-overlay', '');
                        const modal = document.getElementById(modalId);
                        if (modal && modal.dataset.closeOnOverlay !== 'false') {
                            this.close(modalId);
                        }
                    }
                });
            });

            // Escape key
            document.addEventListener('keydown', (e) => {
                if (e.key === 'Escape' && this.activeModals.length > 0) {
                    const topModalId = this.activeModals[this.activeModals.length - 1];
                    const modal = document.getElementById(topModalId);
                    if (modal && modal.dataset.closeOnEscape !== 'false') {
                        this.close(topModalId);
                    }
                }
            });

            // Open buttons
            document.querySelectorAll('[data-modal-open]').forEach(btn => {
                btn.addEventListener('click', (e) => {
                    e.preventDefault();
                    const modalId = btn.dataset.modalOpen;
                    this.open(modalId);
                });
            });
        }
    };

    // ============================================
    // ALERT CONTROLLER
    // ============================================

    const Alert = {
        alerts: [],

        // Show an alert
        show: function (message, type = 'info', options = {}) {
            const defaults = {
                dismissible: true,
                icon: null,
                timeout: 0,
                position: 'top',
                id: null,
                class: ''
            };

            const opts = { ...defaults, ...options };
            const alertId = opts.id || 'alert-' + Date.now();

            // Create alert element
            const alert = this.createAlert(message, type, opts, alertId);

            // Add to DOM
            this.addAlert(alert, opts.position);

            // Show with animation
            setTimeout(() => alert.classList.add('show'), 10);

            // Auto-dismiss if timeout is set
            if (opts.timeout > 0) {
                setTimeout(() => this.dismiss(alertId), opts.timeout);
            }

            return alertId;
        },

        // Create alert element
        createAlert: function (message, type, options, id) {
            const typeConfig = {
                success: { icon: 'fa-check-circle', bg: 'linear-gradient(135deg, #d4edda 0%, #c3e6cb 100%)', border: '#28a745', color: '#155724' },
                error: { icon: 'fa-exclamation-circle', bg: 'linear-gradient(135deg, #f8d7da 0%, #f5c6cb 100%)', border: '#dc3545', color: '#721c24' },
                warning: { icon: 'fa-exclamation-triangle', bg: 'linear-gradient(135deg, #fff3cd 0%, #ffeaa7 100%)', border: '#ffc107', color: '#856404' },
                info: { icon: 'fa-info-circle', bg: 'linear-gradient(135deg, #d1ecf1 0%, #bee5eb 100%)', border: '#17a2b8', color: '#0c5460' }
            };

            const config = typeConfig[type] || typeConfig.info;
            const icon = options.icon || config.icon;

            const wrapper = document.createElement('div');
            wrapper.className = 'alert-wrapper alert-' + options.position;

            const alert = document.createElement('div');
            alert.className = 'alert alert-' + type + ' ' + options.class;
            alert.id = id;
            alert.dataset.alert = '';
            alert.dataset.alertType = type;
            alert.style.setProperty('--alert-bg', config.bg);
            alert.style.setProperty('--alert-border', config.border);
            alert.style.setProperty('--alert-color', config.color);

            alert.innerHTML = `
                <div class="alert-icon">
                    <i class="fas ${icon}"></i>
                </div>
                <div class="alert-content">${message}</div>
                ${options.dismissible ? '<button class="alert-close" data-alert-close aria-label="Close alert"><i class="fas fa-times"></i></button>' : ''}
            `;

            wrapper.appendChild(alert);
            return wrapper;
        },

        // Add alert to DOM
        addAlert: function (wrapper, position) {
            // Find or create wrapper for position
            let container = document.querySelector('.alert-container-' + position);
            if (!container) {
                container = document.createElement('div');
                container.className = 'alert-container-' + position;
                document.body.appendChild(container);
            }
            container.appendChild(wrapper);
            this.alerts.push(wrapper);
        },

        // Dismiss an alert
        dismiss: function (id) {
            const alert = document.getElementById(id);
            if (alert) {
                alert.classList.remove('show');
                alert.classList.add('hide');
                setTimeout(() => {
                    const wrapper = alert.closest('.alert-wrapper');
                    if (wrapper) {
                        wrapper.remove();
                        this.alerts = this.alerts.filter(a => a !== wrapper);
                    }
                }, 300);
            }
        },

        // Dismiss all alerts
        dismissAll: function () {
            [...this.alerts].forEach(wrapper => {
                const alert = wrapper.querySelector('.alert');
                if (alert) {
                    alert.classList.remove('show');
                    alert.classList.add('hide');
                }
            });
            setTimeout(() => {
                document.querySelectorAll('.alert-wrapper').forEach(w => w.remove());
                this.alerts = [];
            }, 300);
        },

        // Initialize alert event listeners
        init: function () {
            // Close button clicks
            document.addEventListener('click', (e) => {
                const closeBtn = e.target.closest('[data-alert-close]');
                if (closeBtn) {
                    const alert = closeBtn.closest('.alert');
                    if (alert) this.dismiss(alert.id);
                }
            });

            // Initialize existing alerts
            document.querySelectorAll('[data-alert]').forEach(alert => {
                const timeout = parseInt(alert.dataset.alertTimeout) || 0;
                setTimeout(() => alert.classList.add('show'), 10);

                if (timeout > 0) {
                    setTimeout(() => this.dismiss(alert.id), timeout);
                }
            });
        }
    };

    // Expose Alert to global scope
    window.Alert = Alert;

    // ============================================
    // ADMIN CONFIRM / PROMPT DIALOG
    // ============================================
    // Provides window.AdminConfirm.request(options) → Promise<boolean>
    // and window.AdminConfirm.prompt(options) → Promise<string|null>

    (function buildAdminConfirm() {
        let _overlay = null;

        function _getOverlay() {
            if (_overlay) return _overlay;
            const el = document.createElement('div');
            el.id = 'adminConfirmOverlay';
            el.style.cssText = [
                'display:none',
                'position:fixed',
                'inset:0',
                'z-index:99999',
                'background:rgba(15,23,42,0.54)',
                'align-items:center',
                'justify-content:center',
                'padding:1rem',
                'box-sizing:border-box',
                '-webkit-overflow-scrolling:touch'
            ].join(';');
            el.innerHTML = [
                '<div id="adminConfirmBox" role="dialog" aria-modal="true" style="',
                    'background:#fff;border-radius:16px;max-width:400px;width:100%;',
                    'box-shadow:0 20px 48px rgba(15,23,42,.22);overflow:hidden;',
                    'display:flex;flex-direction:column;max-height:90dvh;',
                '">',
                    '<div id="adminConfirmHeader" style="padding:1.1rem 1.3rem 0.7rem;display:flex;align-items:center;gap:0.7rem;">',
                        '<span id="adminConfirmIcon" style="font-size:1.25rem;"></span>',
                        '<strong id="adminConfirmTitle" style="font-size:1rem;flex:1;"></strong>',
                    '</div>',
                    '<div id="adminConfirmBody" style="padding:0 1.3rem 0.6rem;overflow-y:auto;-webkit-overflow-scrolling:touch;flex:1;font-size:0.88rem;color:#374151;"></div>',
                    '<div id="adminConfirmInputWrap" style="display:none;padding:0 1.3rem 0.4rem;">',
                        '<label id="adminConfirmInputLabel" style="font-size:0.8rem;font-weight:600;color:#6b7280;display:block;margin-bottom:0.35rem;text-transform:uppercase;letter-spacing:.04em;"></label>',
                        '<input id="adminConfirmInput" type="text" style="width:100%;border:1px solid #d1d5db;border-radius:8px;padding:0.55rem 0.75rem;font-size:0.88rem;box-sizing:border-box;outline:none;" />',
                    '</div>',
                    '<div id="adminConfirmFooter" style="padding:0.8rem 1.3rem 1rem;display:flex;gap:0.6rem;justify-content:flex-end;flex-wrap:wrap;">',
                        '<button id="adminConfirmCancel" type="button" style="',
                            'padding:0.5rem 1.1rem;border-radius:8px;border:1px solid #d1d5db;',
                            'background:#f9fafb;color:#374151;font-size:0.85rem;font-weight:600;cursor:pointer;',
                        '">Cancel</button>',
                        '<button id="adminConfirmOk" type="button" style="',
                            'padding:0.5rem 1.2rem;border-radius:8px;border:1px solid transparent;',
                            'color:#fff;font-size:0.85rem;font-weight:600;cursor:pointer;background:#1e2938;',
                        '"></button>',
                    '</div>',
                '</div>'
            ].join('');
            document.body.appendChild(el);
            _overlay = el;
            return el;
        }

        function _open(options, hasInput) {
            return new Promise(function(resolve) {
                const overlay = _getOverlay();
                const title   = document.getElementById('adminConfirmTitle');
                const icon    = document.getElementById('adminConfirmIcon');
                const body    = document.getElementById('adminConfirmBody');
                const inputWrap = document.getElementById('adminConfirmInputWrap');
                const inputLabel = document.getElementById('adminConfirmInputLabel');
                const input   = document.getElementById('adminConfirmInput');
                const okBtn   = document.getElementById('adminConfirmOk');
                const cancelBtn = document.getElementById('adminConfirmCancel');

                const tone = options.tone || 'default';
                const toneColors = { danger: '#b91c1c', warning: '#92400e', success: '#166534', default: '#1e2938' };
                const toneBg = { danger: '#fef2f2', warning: '#fffbeb', success: '#f0fdf4', default: '#f0f4ff' };

                title.textContent = options.title || 'Confirm';
                icon.innerHTML = options.icon ? '<i class="fas ' + options.icon + '"></i>' : '';
                icon.style.color = toneColors[tone] || toneColors.default;
                document.getElementById('adminConfirmHeader').style.background = toneBg[tone] || toneBg.default;

                let bodyHtml = '';
                if (options.message) bodyHtml += '<p style="margin:0 0 0.5rem;">' + String(options.message).replace(/</g,'&lt;') + '</p>';
                if (Array.isArray(options.details) && options.details.length) {
                    bodyHtml += '<ul style="margin:0;padding-left:1.1rem;list-style:disc;">';
                    options.details.forEach(function(d) {
                        bodyHtml += '<li style="margin-bottom:0.25rem;">' + String(d).replace(/</g,'&lt;') + '</li>';
                    });
                    bodyHtml += '</ul>';
                }
                body.innerHTML = bodyHtml;

                okBtn.textContent = options.confirmText || 'Confirm';
                okBtn.style.background = toneColors[tone] || toneColors.default;
                okBtn.style.borderColor = toneColors[tone] || toneColors.default;

                if (hasInput) {
                    inputWrap.style.display = 'block';
                    inputLabel.textContent = options.inputLabel || 'Enter value';
                    input.placeholder = options.inputPlaceholder || '';
                    input.value = '';
                    setTimeout(function(){ input.focus(); }, 80);
                } else {
                    inputWrap.style.display = 'none';
                }

                overlay.style.display = 'flex';
                document.getElementById('adminConfirmBox').setAttribute('aria-label', options.title || 'Confirm');

                function cleanup() {
                    overlay.style.display = 'none';
                    okBtn.removeEventListener('click', onOk);
                    cancelBtn.removeEventListener('click', onCancel);
                    overlay.removeEventListener('click', onBackdrop);
                    document.removeEventListener('keydown', onKey);
                }

                function onOk() {
                    cleanup();
                    resolve(hasInput ? (input.value || '') : true);
                }
                function onCancel() {
                    cleanup();
                    resolve(hasInput ? null : false);
                }
                function onBackdrop(e) {
                    if (e.target === overlay) onCancel();
                }
                function onKey(e) {
                    if (e.key === 'Escape') onCancel();
                    if (e.key === 'Enter' && !hasInput) onOk();
                }

                okBtn.addEventListener('click', onOk);
                cancelBtn.addEventListener('click', onCancel);
                overlay.addEventListener('click', onBackdrop);
                document.addEventListener('keydown', onKey);
            });
        }

        window.AdminConfirm = {
            request: function(options) { return _open(options, false); },
            prompt:  function(options) { return _open(options, true);  }
        };
    })();

    // ============================================
    // CALENDAR BOOKING TOOLTIP
    // ============================================

    const CalendarTooltip = {
        // Initialize tooltip functionality
        init: function () {
            // Only proceed if calendar booking tooltips exist on the page
            const triggers = document.querySelectorAll('.calendar-booking-tooltip-trigger');
            if (triggers.length === 0) return;

            // Generate and inject tooltip content for each booking indicator
            triggers.forEach(trigger => {
                this.createTooltipContent(trigger);
            });

            // Set up event delegation for dynamically added elements
            this.setupEventDelegation();
        },

        // Get clipping/placement boundaries for a trigger
        getBoundsRect: function (trigger) {
            const roomCalendar = trigger.closest('.room-calendar');
            if (roomCalendar) {
                return roomCalendar.getBoundingClientRect();
            }

            return {
                top: 0,
                left: 0,
                right: window.innerWidth,
                bottom: window.innerHeight
            };
        },

        // Keep tooltip visible inside the current calendar card bounds
        updateTooltipPlacement: function (trigger) {
            if (!trigger) return;

            const tooltip = trigger.querySelector('.calendar-tooltip-content');
            if (!tooltip) return;

            const edgePadding = 8;
            const boundsRect = this.getBoundsRect(trigger);

            const isHiddenByCss = window.getComputedStyle(tooltip).display === 'none';
            const previousDisplay = tooltip.style.display;
            const previousVisibility = tooltip.style.visibility;
            const previousPointerEvents = tooltip.style.pointerEvents;

            // Temporarily reveal for measurement when hidden by CSS states
            if (isHiddenByCss) {
                tooltip.style.display = 'block';
                tooltip.style.visibility = 'hidden';
                tooltip.style.pointerEvents = 'none';
            }

            const triggerRect = trigger.getBoundingClientRect();
            const tooltipRect = tooltip.getBoundingClientRect();

            const spaceBelow = boundsRect.bottom - triggerRect.bottom;
            const spaceAbove = triggerRect.top - boundsRect.top;
            const shouldFlipUp = spaceBelow < (tooltipRect.height + edgePadding) && spaceAbove > spaceBelow;

            trigger.classList.toggle('tooltip-flip-up', shouldFlipUp);

            // Nudge horizontally so tooltip does not leave the visible calendar card area.
            let offsetLeft = 0;
            const maxRight = boundsRect.right - edgePadding;
            const minLeft = boundsRect.left + edgePadding;

            let projectedLeft = triggerRect.left + offsetLeft;
            let projectedRight = projectedLeft + tooltipRect.width;

            if (projectedRight > maxRight) {
                offsetLeft -= (projectedRight - maxRight);
            }

            projectedLeft = triggerRect.left + offsetLeft;
            if (projectedLeft < minLeft) {
                offsetLeft += (minLeft - projectedLeft);
            }

            tooltip.style.left = `${Math.round(offsetLeft)}px`;

            if (isHiddenByCss) {
                tooltip.style.display = previousDisplay;
                tooltip.style.visibility = previousVisibility;
                tooltip.style.pointerEvents = previousPointerEvents;
            }
        },

        // Reposition currently visible/focused tooltips
        updateVisibleTooltips: function () {
            const activeTriggers = document.querySelectorAll('.calendar-booking-tooltip-trigger.has-js-tooltip');
            activeTriggers.forEach((trigger) => {
                if (trigger.matches(':hover, :focus, :focus-within')) {
                    this.updateTooltipPlacement(trigger);
                }
            });
        },

        // Create tooltip content from data attributes
        createTooltipContent: function (trigger) {
            // Skip if tooltip content already exists
            if (trigger.querySelector('.calendar-tooltip-content')) return;

            // Mark trigger as having JS tooltip (hides CSS-only fallback)
            trigger.classList.add('has-js-tooltip');

            // Get data from attributes
            const data = {
                ref: trigger.dataset.bookingRef || '',
                guestName: trigger.dataset.guestName || '',
                roomName: trigger.dataset.roomName || '',
                roomNumber: trigger.dataset.roomNumber || '',
                roomDisplay: trigger.dataset.roomDisplay || '',
                status: trigger.dataset.status || '',
                checkIn: trigger.dataset.checkIn || '',
                checkOut: trigger.dataset.checkOut || '',
                nights: trigger.dataset.nights || '0',
                paymentStatus: trigger.dataset.paymentStatus || 'Pending',
                amount: trigger.dataset.amount || ''
            };

            // Create tooltip content element
            const tooltip = document.createElement('div');
            tooltip.className = 'calendar-tooltip-content';
            tooltip.setAttribute('aria-hidden', 'true');

            // Build tooltip HTML (all content is already escaped in PHP)
            tooltip.innerHTML = `
                <div class="tooltip-header">
                    <span class="tooltip-ref">${this.escapeHtml(data.ref)}</span>
                    <span class="tooltip-status status-${this.slugify(data.status)}">${this.escapeHtml(data.status)}</span>
                </div>
                <div class="tooltip-row">
                    <span class="tooltip-label">Guest:</span>
                    <span class="tooltip-value">${this.escapeHtml(data.guestName)}</span>
                </div>
                <div class="tooltip-row">
                    <span class="tooltip-label">Room:</span>
                    <span class="tooltip-value">${this.escapeHtml(data.roomDisplay)}</span>
                </div>
                <div class="tooltip-row">
                    <span class="tooltip-label">Check-in:</span>
                    <span class="tooltip-value">${this.escapeHtml(data.checkIn)}</span>
                </div>
                <div class="tooltip-row">
                    <span class="tooltip-label">Check-out:</span>
                    <span class="tooltip-value">${this.escapeHtml(data.checkOut)}</span>
                </div>
                <div class="tooltip-row">
                    <span class="tooltip-label">Nights:</span>
                    <span class="tooltip-value highlight">${this.escapeHtml(data.nights)}</span>
                </div>
                <div class="tooltip-footer">
                    <span class="tooltip-payment ${this.slugify(data.paymentStatus)}">${this.escapeHtml(data.paymentStatus)}</span>
                    <span class="tooltip-amount">${this.escapeHtml(data.amount)}</span>
                </div>
            `;

            // Append tooltip to trigger
            trigger.appendChild(tooltip);
        },

        // Escape HTML for additional safety (double-escape protection)
        escapeHtml: function (text) {
            const div = document.createElement('div');
            div.textContent = text;
            return div.innerHTML;
        },

        // Convert string to slug for CSS classes
        slugify: function (str) {
            return str.toLowerCase().replace(/[^a-z0-9]+/g, '_').replace(/^_+|_+$/g, '');
        },

        // Set up event delegation for dynamic content
        setupEventDelegation: function () {
            // Recalculate placement whenever a tooltip trigger is hovered or focused.
            document.addEventListener('mouseover', (e) => {
                if (!(e.target instanceof Element)) return;
                const trigger = e.target.closest('.calendar-booking-tooltip-trigger');
                if (!trigger) return;

                const related = e.relatedTarget;
                if (related instanceof Element && trigger.contains(related)) {
                    return;
                }

                this.updateTooltipPlacement(trigger);
            });

            document.addEventListener('focusin', (e) => {
                if (!(e.target instanceof Element)) return;
                const trigger = e.target.closest('.calendar-booking-tooltip-trigger');
                if (!trigger) return;
                this.updateTooltipPlacement(trigger);
            });

            // Handle keyboard navigation - show tooltip on Enter/Space
            document.addEventListener('keydown', (e) => {
                if ((e.key === 'Enter' || e.key === ' ') &&
                    e.target.classList.contains('calendar-booking-tooltip-trigger')) {
                    // Prevent default for Space to avoid page scroll
                    if (e.key === ' ') e.preventDefault();
                    this.updateTooltipPlacement(e.target);
                }
            });

            window.addEventListener('resize', () => {
                this.updateVisibleTooltips();
            });

            window.addEventListener('scroll', () => {
                this.updateVisibleTooltips();
            }, true);

            // Hide tooltips when clicking outside
            document.addEventListener('click', (e) => {
                if (!e.target.closest('.calendar-booking-tooltip-trigger')) {
                    // Tooltips are hidden via CSS when focus is lost
                    document.querySelectorAll('.calendar-booking-tooltip-trigger:focus').forEach(trigger => {
                        trigger.blur();
                    });
                }
            });
        }
    };

    // Initialize calendar tooltips
    CalendarTooltip.init();

    // ============================================
    // BUTTON LOADER CONTROLLER
    // ============================================

    const ButtonLoader = {
        // Store original button content
        originalContent: new Map(),

        /**
         * Show loading state on a button
         * @param {HTMLElement|string} button - Button element or selector
         * @param {object} options - Options for the loader
         */
        show: function (button, options = {}) {
            const btn = typeof button === 'string' ? document.querySelector(button) : button;
            if (!btn) return;

            const defaults = {
                text: 'Processing...',
                spinner: true,
                disable: true,
                preserveWidth: true
            };
            const opts = { ...defaults, ...options };

            // Store original content
            this.originalContent.set(btn, {
                html: btn.innerHTML,
                width: btn.offsetWidth,
                disabled: btn.disabled,
                classList: [...btn.classList]
            });

            // Preserve width to prevent layout shift
            if (opts.preserveWidth) {
                btn.style.width = btn.offsetWidth + 'px';
            }

            // Build loading content
            let loadingHtml = '';
            if (opts.spinner) {
                loadingHtml = `<span class="btn-spinner"></span>`;
            }
            loadingHtml += `<span class="btn-loading-text">${this.escapeHtml(opts.text)}</span>`;

            // Update button
            btn.innerHTML = loadingHtml;
            btn.classList.add('btn-loading');
            if (opts.disable) {
                btn.disabled = true;
            }

            // Dispatch custom event
            btn.dispatchEvent(new CustomEvent('loader:show', { detail: { button: btn } }));
        },

        /**
         * Hide loading state and restore button
         * @param {HTMLElement|string} button - Button element or selector
         * @param {object} options - Options for restoration
         */
        hide: function (button, options = {}) {
            const btn = typeof button === 'string' ? document.querySelector(button) : button;
            if (!btn) return;

            const defaults = {
                restoreContent: true,
                enable: true
            };
            const opts = { ...defaults, ...options };

            // Get stored original content
            const original = this.originalContent.get(btn);

            if (opts.restoreContent && original) {
                btn.innerHTML = original.html;
                btn.style.width = '';
                if (opts.enable) {
                    btn.disabled = original.disabled;
                }
            } else {
                // Just remove loading state
                btn.style.width = '';
                if (opts.enable) {
                    btn.disabled = false;
                }
            }

            btn.classList.remove('btn-loading');
            this.originalContent.delete(btn);

            // Dispatch custom event
            btn.dispatchEvent(new CustomEvent('loader:hide', { detail: { button: btn } }));
        },

        /**
         * Show success state briefly before restoring
         * @param {HTMLElement|string} button - Button element or selector
         * @param {string} message - Success message
         * @param {number} duration - How long to show success (ms)
         */
        success: function (button, message = 'Success!', duration = 1500) {
            const btn = typeof button === 'string' ? document.querySelector(button) : button;
            if (!btn) return;

            const original = this.originalContent.get(btn);

            btn.classList.remove('btn-loading');
            btn.classList.add('btn-success');
            btn.innerHTML = `<i class="fas fa-check"></i> ${this.escapeHtml(message)}`;

            setTimeout(() => {
                btn.classList.remove('btn-success');
                if (original) {
                    btn.innerHTML = original.html;
                    btn.style.width = '';
                    btn.disabled = original.disabled;
                }
                this.originalContent.delete(btn);
            }, duration);
        },

        /**
         * Show error state briefly before restoring
         * @param {HTMLElement|string} button - Button element or selector
         * @param {string} message - Error message
         * @param {number} duration - How long to show error (ms)
         */
        error: function (button, message = 'Error!', duration = 2000) {
            const btn = typeof button === 'string' ? document.querySelector(button) : button;
            if (!btn) return;

            const original = this.originalContent.get(btn);

            btn.classList.remove('btn-loading');
            btn.classList.add('btn-error');
            btn.innerHTML = `<i class="fas fa-times"></i> ${this.escapeHtml(message)}`;

            setTimeout(() => {
                btn.classList.remove('btn-error');
                if (original) {
                    btn.innerHTML = original.html;
                    btn.style.width = '';
                    btn.disabled = original.disabled;
                }
                this.originalContent.delete(btn);
            }, duration);
        },

        /**
         * Helper to escape HTML
         */
        escapeHtml: function (text) {
            const div = document.createElement('div');
            div.textContent = text;
            return div.innerHTML;
        },

        /**
         * Initialize - add CSS for spinners if not present
         */
        init: function () {
            // Add spinner styles if not already present
            if (!document.getElementById('btn-loader-styles')) {
                const style = document.createElement('style');
                style.id = 'btn-loader-styles';
                style.textContent = `
                    .btn-loading {
                        position: relative;
                        pointer-events: none;
                        opacity: 0.85;
                    }
                    .btn-spinner {
                        display: inline-block;
                        width: 16px;
                        height: 16px;
                        border: 2px solid currentColor;
                        border-right-color: transparent;
                        border-radius: 50%;
                        animation: btn-spin 0.75s linear infinite;
                        margin-right: 8px;
                        vertical-align: middle;
                    }
                    .btn-loading-text {
                        vertical-align: middle;
                    }
                    .btn-success {
                        background-color: #28a745 !important;
                        border-color: #28a745 !important;
                        color: white !important;
                    }
                    .btn-error {
                        background-color: #dc3545 !important;
                        border-color: #dc3545 !important;
                        color: white !important;
                    }
                    @keyframes btn-spin {
                        to { transform: rotate(360deg); }
                    }
                `;
                document.head.appendChild(style);
            }
        }
    };

    // Expose ButtonLoader to global scope
    window.ButtonLoader = ButtonLoader;

    // ============================================
    // INITIALIZATION
    // ============================================

    // Initialize on DOM ready
    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', () => {
            Modal.init();
            Alert.init();
            CalendarTooltip.init();
            ButtonLoader.init();
        });
    } else {
        Modal.init();
        Alert.init();
        CalendarTooltip.init();
        ButtonLoader.init();
    }
})();
