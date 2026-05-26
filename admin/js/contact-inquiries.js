(function () {
    'use strict';

    const modalId = 'contactInquiryReplyModal';

    function getById(id) {
        return document.getElementById(id);
    }

    function buildDefaultReply(inquiry, siteName) {
        const rawName = (inquiry.name || '').trim();
        const firstName = rawName ? rawName.split(/\s+/)[0] : 'there';

        return `Dear ${firstName},\n\nThank you for contacting ${siteName}.\n\n\n\nKind regards,\n${siteName}`;
    }

    function openReplyModal(inquiry) {
        const form = getById('contactInquiryReplyForm');
        if (!form) {
            return;
        }

        const siteName = form.dataset.siteName || 'Rosalyns Beach Hotel';
        const subject = (inquiry.subject || '').trim();
        const replySubject = subject.toLowerCase().startsWith('re:') ? subject : `Re: ${subject || inquiry.reference || 'Contact Inquiry'}`;

        getById('replyInquiryId').value = inquiry.id || '';
        getById('replySubject').value = replySubject;
        getById('replyMessage').value = buildDefaultReply(inquiry, siteName);
        getById('replyRecipientName').textContent = inquiry.name || 'Guest';
        getById('replyRecipientEmail').textContent = inquiry.email || '';
        getById('replyReference').textContent = inquiry.reference ? `Reference: ${inquiry.reference}` : '';
        getById('replyOriginalMessage').textContent = inquiry.message || '';

        if (window.Modal && typeof window.Modal.open === 'function') {
            window.Modal.open(modalId);
            return;
        }

        const modal = getById(modalId);
        if (modal) {
            modal.classList.add('active');
        }
    }

    document.addEventListener('click', function (event) {
        const trigger = event.target.closest('[data-reply-inquiry]');
        if (!trigger) {
            return;
        }

        const payload = trigger.dataset.replyInquiry || '{}';
        let inquiry = {};
        try {
            inquiry = JSON.parse(payload);
        } catch (error) {
            return;
        }

        openReplyModal(inquiry);
    });

    document.addEventListener('submit', function (event) {
        if (!event.target.matches('[data-admin-loading-form]')) {
            return;
        }

        getById('admin-page-loader')?.classList.add('is-visible');
    });
})();