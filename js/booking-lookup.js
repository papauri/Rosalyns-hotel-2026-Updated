/**
 * Booking Lookup — JS
 * Rosalyn's Beach Hotel 2026
 * Intercepts cancel form submit and shows a Modal confirmation instead of window.confirm()
 */

document.addEventListener('DOMContentLoaded', () => {
    const cancelForm = document.getElementById('cancel-form');
    if (!cancelForm) return;

    cancelForm.addEventListener('submit', (e) => {
        e.preventDefault();

        // Use the global Modal object (loaded via modal.js)
        if (typeof Modal === 'undefined') {
            // Fallback: just submit if Modal not available
            cancelForm.submit();
            return;
        }

        Modal.showMessage({
            title: 'Cancel Booking',
            message: `
                <p style="font-size:1rem; color: var(--color-text-primary, #2A2723);">
                    Are you sure you want to cancel this booking?<br>
                    <span style="color:#991B1B; font-weight:500;">This action cannot be undone.</span>
                </p>
            `,
            footerHtml: `
                <button class="btn btn-secondary" data-modal-close="system-message" style="margin-right:12px;">
                    Keep My Booking
                </button>
                <button class="btn btn-primary" id="confirm-cancel-btn" style="background:#DC2626; border-color:#DC2626;">
                    Yes, Cancel It
                </button>
            `,
            size: 'sm'
        });

        // Wire up the confirm button after the modal renders
        requestAnimationFrame(() => {
            const confirmBtn = document.getElementById('confirm-cancel-btn');
            if (confirmBtn) {
                confirmBtn.addEventListener('click', () => {
                    Modal.close('system-message');
                    cancelForm.submit();
                }, { once: true });
            }
        });
    });
});
