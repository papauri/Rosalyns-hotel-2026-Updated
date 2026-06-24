/**
 * Modal Component - Front-end Modal Controller
 * Provides reusable modal functionality for front-end pages
 */

(function () {
    'use strict';

    if (window.__rhModalLoaded) return;
    window.__rhModalLoaded = true;

    // ============================================
    // MODAL CONTROLLER
    // ============================================

    const Modal = {
        activeModals: [],

        // Open a modal by ID
        open: function (modalId) {
            const modal = document.getElementById(modalId);
            const backdrop = modal?.querySelector('.modal__backdrop');

            if (!modal) { return; }

            // Add to active modals stack
            this.activeModals.push(modalId);

            // Show modal with BEM class
            modal.classList.add('modal--active');

            // Prevent body scroll
            document.body.classList.add('modal-open');

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

            if (!modal) return;

            // Remove from active modals
            this.activeModals = this.activeModals.filter(id => id !== modalId);

            // Hide modal with BEM class
            modal.classList.remove('modal--active');

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

        // Ensure a generic system message modal exists in DOM
        _ensureSystemModal: function () {
            let modal = document.getElementById('system-message');
            if (modal) return modal;

            const wrapper = document.createElement('div');
            wrapper.innerHTML = `
                <div class="modal modal--md" id="system-message" data-modal data-close-on-overlay="true" data-close-on-escape="true" role="dialog" aria-modal="true" aria-labelledby="system-message-title">
                    <div class="modal__backdrop" data-modal-close></div>
                    <div class="modal__wrapper">
                        <div class="modal__container">
                            <div class="modal__header">
                                <h3 class="modal__title" id="system-message-title"></h3>
                                <button class="modal__close" data-modal-close aria-label="Close modal"></button>
                            </div>
                            <div class="modal__body"></div>
                            <div class="modal__footer" style="display:none"></div>
                        </div>
                    </div>
                </div>`;
            modal = wrapper.firstElementChild;
            document.body.appendChild(modal);

            // Bind close handlers for this dynamically injected modal
            const closeBtn = modal.querySelector('[data-modal-close]');
            const backdrop = modal.querySelector('.modal__backdrop');
            if (closeBtn) closeBtn.addEventListener('click', () => this.close('system-message'));
            if (backdrop) backdrop.addEventListener('click', (e) => { if (e.target === backdrop) this.close('system-message'); });

            return modal;
        },

        // Show a user-friendly system message in a modal
        showMessage: function ({ title = 'Notice', message = '', footerHtml = '', size = 'md' } = {}) {
            const modal = this._ensureSystemModal();
            if (!modal) { alert((title ? title + '\n\n' : '') + (message || '')); return; }

            // Apply size class
            modal.classList.remove('modal--sm', 'modal--md', 'modal--lg', 'modal--xl');
            modal.classList.add(`modal--${size}`);

            const titleEl = modal.querySelector('#system-message-title');
            const bodyEl = modal.querySelector('.modal__body');
            const footEl = modal.querySelector('.modal__footer');

            if (titleEl) titleEl.textContent = title || '';
            if (bodyEl) bodyEl.innerHTML = message || '';
            if (footEl) {
                if (footerHtml) {
                    footEl.style.display = '';
                    footEl.innerHTML = footerHtml;
                } else {
                    footEl.style.display = 'none';
                    footEl.innerHTML = '';
                }
            }

            this.open('system-message');
        },

        // Initialize modal event listeners
        init: function () {
            // Close button clicks
            document.querySelectorAll('[data-modal-close]').forEach(btn => {
                btn.addEventListener('click', (e) => {
                    const modal = btn.closest('[data-modal]');
                    if (modal) this.close(modal.id);
                });
            });

            // Backdrop clicks (using new BEM structure)
            document.querySelectorAll('.modal__backdrop').forEach(backdrop => {
                backdrop.addEventListener('click', (e) => {
                    if (e.target === backdrop) {
                        const modal = backdrop.closest('.modal');
                        if (modal && modal.dataset.closeOnOverlay !== 'false') {
                            this.close(modal.id);
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

            // Policy overlay (special case for footer policy modals)
            const policyOverlay = document.querySelector('[data-policy-overlay]');
            if (policyOverlay) {
                policyOverlay.addEventListener('click', (e) => {
                    if (e.target === policyOverlay) {
                        this.closeAll();
                    }
                });
            }
        }
    };

    // Expose Modal to global scope
    window.Modal = Modal;

    // Initialize on DOM ready
    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', () => Modal.init());
    } else {
        Modal.init();
    }
})();
