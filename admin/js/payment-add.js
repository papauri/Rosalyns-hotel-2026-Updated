(function () {
    'use strict';

    function formatMoney(amount, currencySymbol) {
        return currencySymbol + Number(amount || 0).toLocaleString('en-US', {
            minimumFractionDigits: 2,
            maximumFractionDigits: 2
        });
    }

    function moneyMarkup(amount, currencySymbol) {
        return '<span class="finance-money"><span class="finance-money__currency">' + currencySymbol + '</span><span class="finance-money__amount">' + Number(amount || 0).toLocaleString('en-US', {
            minimumFractionDigits: 2,
            maximumFractionDigits: 2
        }) + '</span></span>';
    }

    function humanLabel(value) {
        return String(value || '').replace(/_/g, ' ').replace(/\b\w/g, function (char) {
            return char.toUpperCase();
        });
    }

    function showMessage(title, message) {
        if (window.Modal && typeof window.Modal.showMessage === 'function') {
            window.Modal.showMessage({ title: title, message: '<p>' + message + '</p>' });
        }
    }

    document.addEventListener('DOMContentLoaded', function () {
        // Fix SPA scroll restoration: always start at top on this page
        window.scrollTo({ top: 0, behavior: 'instant' });

        var form = document.querySelector('[data-payment-form]');
        if (!form) return;

        var currencySymbol = form.dataset.currency || 'MWK';
        var vatRate = parseFloat(form.dataset.vatRate || '0') || 0;
        var vatEnabled = form.dataset.vatEnabled === '1';
        var paymentAmount = document.getElementById('payment_amount');
        var subtotalDisplay = document.getElementById('subtotal-display');
        var vatDisplay = document.getElementById('vat-display');
        var totalDisplay = document.getElementById('total-display');
        var projectedBalance = document.querySelector('[data-projected-balance]');
        var outstandingMeter = document.querySelector('[data-outstanding]');
        var outstandingAmount = outstandingMeter ? parseFloat(outstandingMeter.dataset.outstanding || '0') || 0 : null;
        var paymentTypeSelect = document.getElementById('payment_type');
        var paymentStatusSelect = document.getElementById('payment_status');
        var paymentMethodSelect = document.getElementById('payment_method');
        var cnLookupGroup = document.getElementById('cn-lookup-group');
        var cnNumberInput = document.getElementById('cn_number');
        var cnLookupStatus = document.getElementById('cn-lookup-status');
        var bookingTypeSelect = document.getElementById('booking_type');
        var bookingIdInput = document.getElementById('booking_id');
        var searchBtn = document.getElementById('search_booking_btn');
        var searchResults = document.getElementById('booking_search_results');
        var dynamicInfo = document.getElementById('dynamic_booking_info');
        var infoContent = document.getElementById('booking_info_content');
        var infoTitle = document.getElementById('booking_info_title');
        var infoType = document.getElementById('booking_info_type');
        var clearBookingBtn = document.getElementById('clear_booking_btn');
        var quotationOptions = document.querySelector('[data-quotation-options]');
        var searchTimeout = null;
        // Picker modal elements
        var pickerModal = document.getElementById('booking-picker-modal');
        var pickerTypeSelect = document.getElementById('picker-booking-type');
        var pickerSearchInput = document.getElementById('picker-search-input');
        var pickerSearchBtn = document.getElementById('picker-search-btn');
        var pickerResults = document.getElementById('booking-picker-results');
        var pickerSearchTimeout = null;

        function updateCalculation() {
            var amount = parseFloat(paymentAmount && paymentAmount.value ? paymentAmount.value : '0') || 0;
            var vatAmount = vatEnabled ? amount * (vatRate / 100) : 0;
            var total = amount + vatAmount;
            var isRefund = paymentTypeSelect && paymentTypeSelect.value === 'refund';

            if (subtotalDisplay) subtotalDisplay.textContent = formatMoney(amount, currencySymbol);
            if (vatDisplay) vatDisplay.textContent = formatMoney(vatAmount, currencySymbol);
            if (totalDisplay) totalDisplay.textContent = formatMoney(total, currencySymbol);
            if (projectedBalance && outstandingAmount !== null) {
                var projected = isRefund ? outstandingAmount + total : Math.max(0, outstandingAmount - total);
                projectedBalance.innerHTML = moneyMarkup(projected, currencySymbol);
            }
        }

        function updateMethodCards() {
            var selected = paymentMethodSelect ? paymentMethodSelect.value : '';
            document.querySelectorAll('[data-method-value]').forEach(function (card) {
                card.classList.toggle('is-active', card.dataset.methodValue === selected);
            });
        }

        function syncCreditNoteVisibility() {
            var isCreditNote = paymentMethodSelect && paymentMethodSelect.value === 'credit_note';
            if (cnLookupGroup) cnLookupGroup.hidden = !isCreditNote;
            if (cnNumberInput) cnNumberInput.required = isCreditNote;
        }

        function syncPaymentTypeStatus() {
            if (!paymentTypeSelect || !paymentStatusSelect) return;
            if (paymentTypeSelect.value === 'refund') {
                paymentStatusSelect.value = 'refunded';
            } else if (paymentStatusSelect.value === 'refunded') {
                paymentStatusSelect.value = 'completed';
            }
            updateCalculation();
        }

        function syncQuotationOptions() {
            var issueQuote = document.querySelector('input[name="document_actions[]"][value="issue_quotation"]');
            if (quotationOptions && issueQuote) {
                quotationOptions.hidden = !issueQuote.checked;
            }
        }

        function displaySearchResults(data, isRecent) {
            if (!searchResults) return;
            var bookings = data && Array.isArray(data.bookings) ? data.bookings : [];
            if (bookings.length === 0) {
                searchResults.innerHTML = '<div class="booking-search-no-results">' + (isRecent ? 'No recent bookings found' : 'No bookings found matching your search') + '</div>';
                searchResults.hidden = false;
                return;
            }

            searchResults.innerHTML = bookings.map(function (booking) {
                var dueClass = Number(booking.amount_due || 0) > 0 ? 'is-due' : 'is-settled';
                if (bookingTypeSelect && bookingTypeSelect.value === 'room') {
                    return '<button type="button" class="booking-search-item" data-id="' + booking.id + '"><strong>' + booking.booking_reference + ' - ' + booking.guest_name + '</strong><small>ID: ' + booking.id + ' | Room: ' + (booking.room_name || 'N/A') + ' | ' + booking.check_in_date + ' to ' + booking.check_out_date + '</small><small class="' + dueClass + '">Due: ' + formatMoney(booking.amount_due || 0, currencySymbol) + '</small></button>';
                }
                return '<button type="button" class="booking-search-item" data-id="' + booking.id + '"><strong>' + booking.enquiry_reference + ' - ' + (booking.organization_name || booking.contact_name || 'Client') + '</strong><small>ID: ' + booking.id + ' | Event: ' + booking.start_date + ' to ' + booking.end_date + '</small><small class="' + dueClass + '">Due: ' + formatMoney(booking.amount_due || 0, currencySymbol) + '</small></button>';
            }).join('');
            searchResults.hidden = false;
        }

        function loadRecentBookings(bookingType) {
            if (!searchResults || !bookingType) return;
            searchResults.innerHTML = '<div class="booking-search-loading"><i class="fas fa-spinner fa-spin"></i> Loading recent bookings...</div>';
            searchResults.hidden = false;
            fetch('api/search-bookings.php?type=' + encodeURIComponent(bookingType) + '&recent=1', {
                headers: { 'X-Requested-With': 'XMLHttpRequest' }
            })
                .then(function (response) { return response.json(); })
                .then(function (data) { displaySearchResults(data, true); })
                .catch(function () {
                    searchResults.innerHTML = '<div class="booking-search-no-results">Error loading recent bookings</div>';
                });
        }

        function searchBookings() {
            if (!bookingTypeSelect || !bookingIdInput || !searchResults) return;
            var bookingType = bookingTypeSelect.value;
            var searchTerm = bookingIdInput.value.trim();

            if (!bookingType) {
                searchResults.innerHTML = '<div class="booking-search-no-results">Please select a booking type first</div>';
                searchResults.hidden = false;
                return;
            }

            if (searchTerm.length < 1) {
                loadRecentBookings(bookingType);
                return;
            }

            searchResults.innerHTML = '<div class="booking-search-loading"><i class="fas fa-spinner fa-spin"></i> Searching...</div>';
            searchResults.hidden = false;
            if (searchTimeout) window.clearTimeout(searchTimeout);
            searchTimeout = window.setTimeout(function () {
                fetch('api/search-bookings.php?type=' + encodeURIComponent(bookingType) + '&q=' + encodeURIComponent(searchTerm), {
                    headers: { 'X-Requested-With': 'XMLHttpRequest' }
                })
                    .then(function (response) { return response.json(); })
                    .then(function (data) { displaySearchResults(data, false); })
                    .catch(function () {
                        searchResults.innerHTML = '<div class="booking-search-no-results">Error searching bookings</div>';
                    });
            }, 250);
        }

        function renderBookingDetails(bookingType, booking) {
            if (!dynamicInfo || !infoContent || !infoTitle) return;
            var due = Number(booking.amount_due || 0);
            var settled = due <= 0;
            dynamicInfo.hidden = false;
            dynamicInfo.classList.toggle('is-settled', settled);
            if (infoType) infoType.textContent = humanLabel(bookingType) + ' booking';

            if (bookingType === 'room') {
                infoTitle.innerHTML = booking.booking_reference + (settled ? ' <span class="payment-chip payment-chip--success">Settled</span>' : '');
                infoContent.innerHTML = '<div><dt>Guest</dt><dd>' + booking.guest_name + '</dd></div><div><dt>Email</dt><dd>' + (booking.guest_email || 'N/A') + '</dd></div><div><dt>Room</dt><dd>' + (booking.room_name || 'N/A') + '</dd></div><div><dt>Dates</dt><dd>' + booking.check_in_date + ' - ' + booking.check_out_date + '</dd></div><div><dt>Total</dt><dd>' + moneyMarkup(booking.total_amount || 0, currencySymbol) + '</dd></div><div><dt>Due</dt><dd>' + moneyMarkup(due, currencySymbol) + '</dd></div>';
            } else {
                infoTitle.innerHTML = booking.enquiry_reference + (settled ? ' <span class="payment-chip payment-chip--success">Settled</span>' : '');
                infoContent.innerHTML = '<div><dt>Client</dt><dd>' + (booking.organization_name || booking.contact_name || 'Client') + '</dd></div><div><dt>Email</dt><dd>' + (booking.contact_email || 'N/A') + '</dd></div><div><dt>Dates</dt><dd>' + booking.start_date + ' - ' + booking.end_date + '</dd></div><div><dt>Total</dt><dd>' + moneyMarkup(booking.total_amount || 0, currencySymbol) + '</dd></div><div><dt>Due</dt><dd>' + moneyMarkup(due, currencySymbol) + '</dd></div>';
            }

            if (paymentAmount) {
                paymentAmount.disabled = false;
                paymentAmount.value = due > 0 ? due : '';
            }
            if (paymentStatusSelect && (!paymentStatusSelect.value || paymentStatusSelect.value === 'pending')) {
                paymentStatusSelect.value = 'completed';
            }
            if (paymentMethodSelect && !paymentMethodSelect.value) {
                paymentMethodSelect.value = localStorage.getItem('lastPaymentMethod') || 'cash';
            }
            updateMethodCards();
            syncCreditNoteVisibility();
            updateCalculation();
        }

        function openBookingPickerModal() {
            if (!pickerModal) return;
            // Sync type from main form
            if (pickerTypeSelect && bookingTypeSelect && bookingTypeSelect.value) {
                pickerTypeSelect.value = bookingTypeSelect.value;
            }
            // Clear previous state
            if (pickerSearchInput) pickerSearchInput.value = '';
            if (pickerResults) pickerResults.innerHTML = '<p class="payment-empty-state">Start typing or click Search to find a booking.</p>';
            pickerModal.classList.add('active');
            // Load recent bookings immediately
            if (pickerTypeSelect && pickerTypeSelect.value) {
                searchPickerBookings(true);
            }
            // Focus search input
            setTimeout(function () {
                if (pickerSearchInput) pickerSearchInput.focus();
            }, 100);
        }

        function renderPickerResults(data, isRecent) {
            if (!pickerResults) return;
            var bookings = data && Array.isArray(data.bookings) ? data.bookings : [];
            if (bookings.length === 0) {
                pickerResults.innerHTML = '<p class="payment-empty-state">' + (isRecent ? 'No recent bookings found.' : 'No bookings matched your search.') + '</p>';
                return;
            }
            var type = pickerTypeSelect ? pickerTypeSelect.value : 'room';
            pickerResults.innerHTML = '<table class="picker-modal__table"><thead><tr>' +
                (type === 'room'
                    ? '<th>Ref</th><th>Guest</th><th>Phone</th><th>Room</th><th>Check-in</th><th>Due</th><th></th>'
                    : '<th>Ref</th><th>Client</th><th>Phone</th><th>Event dates</th><th>Due</th><th></th>') +
                '</tr></thead><tbody>' +
                bookings.map(function (b) {
                    var due = Number(b.amount_due || 0);
                    var dueClass = due > 0 ? 'is-due' : 'is-settled';
                    if (type === 'room') {
                        return '<tr class="picker-modal__row" data-id="' + b.id + '" tabindex="0">' +
                            '<td><strong>' + (b.booking_reference || '#' + b.id) + '</strong></td>' +
                            '<td>' + (b.guest_name || '') + '</td>' +
                            '<td>' + (b.guest_phone || '—') + '</td>' +
                            '<td>' + (b.room_name || '—') + '</td>' +
                            '<td>' + (b.check_in_date || '') + '</td>' +
                            '<td class="' + dueClass + '">' + formatMoney(due, currencySymbol) + '</td>' +
                            '<td><button type="button" class="btn btn-secondary picker-modal__select-btn" data-id="' + b.id + '">Select</button></td>' +
                            '</tr>';
                    }
                    return '<tr class="picker-modal__row" data-id="' + b.id + '" tabindex="0">' +
                        '<td><strong>' + (b.enquiry_reference || '#' + b.id) + '</strong></td>' +
                        '<td>' + (b.organization_name || b.contact_name || '—') + '</td>' +
                        '<td>' + (b.contact_phone || '—') + '</td>' +
                        '<td>' + (b.start_date || '') + (b.end_date ? ' – ' + b.end_date : '') + '</td>' +
                        '<td class="' + dueClass + '">' + formatMoney(due, currencySymbol) + '</td>' +
                        '<td><button type="button" class="btn btn-secondary picker-modal__select-btn" data-id="' + b.id + '">Select</button></td>' +
                        '</tr>';
                }).join('') +
                '</tbody></table>';
        }

        function searchPickerBookings(isRecent) {
            if (!pickerTypeSelect || !pickerResults) return;
            var type = pickerTypeSelect.value;
            if (!type) return;
            var term = pickerSearchInput ? pickerSearchInput.value.trim() : '';
            pickerResults.innerHTML = '<div class="booking-search-loading"><i class="fas fa-spinner fa-spin"></i> Searching...</div>';
            var url = 'api/search-bookings.php?type=' + encodeURIComponent(type);
            if (isRecent || term.length === 0) {
                url += '&recent=1';
            } else {
                url += '&q=' + encodeURIComponent(term);
            }
            fetch(url, { headers: { 'X-Requested-With': 'XMLHttpRequest' } })
                .then(function (r) { return r.json(); })
                .then(function (data) { renderPickerResults(data, isRecent || term.length === 0); })
                .catch(function () {
                    pickerResults.innerHTML = '<p class="payment-empty-state">Error loading bookings. Please try again.</p>';
                });
        }

        function selectPickerBooking(bookingId) {
            if (!pickerModal || !bookingTypeSelect || !bookingIdInput) return;
            var type = pickerTypeSelect ? pickerTypeSelect.value : bookingTypeSelect.value;
            if (bookingTypeSelect.value !== type) {
                bookingTypeSelect.value = type;
            }
            bookingIdInput.value = bookingId;
            pickerModal.classList.remove('active');
            fetchBookingDetails(type, bookingId);
        }

        function fetchBookingDetails(bookingType, bookingId) {
            if (!bookingType || !bookingId || !dynamicInfo || !infoContent || !infoTitle) return;
            if (infoType) infoType.textContent = 'Selected account';
            infoTitle.textContent = 'Loading...';
            infoContent.innerHTML = '<div class="booking-search-loading"><i class="fas fa-spinner fa-spin"></i> Loading booking details...</div>';
            fetch('api/search-bookings.php?type=' + encodeURIComponent(bookingType) + '&q=' + encodeURIComponent(bookingId), {
                headers: { 'X-Requested-With': 'XMLHttpRequest' }
            })
                .then(function (response) { return response.json(); })
                .then(function (data) {
                    var bookings = data && Array.isArray(data.bookings) ? data.bookings : [];
                    if (bookings.length === 0) {
                        infoTitle.textContent = 'Booking not found';
                        infoContent.innerHTML = '<p class="payment-empty-state">Try another booking ID or search term.</p>';
                        return;
                    }
                    renderBookingDetails(bookingType, bookings[0]);
                })
                .catch(function () {
                    infoTitle.textContent = 'Could not load booking';
                    infoContent.innerHTML = '<p class="payment-empty-state">Please try again.</p>';
                });
        }

        if (paymentAmount) paymentAmount.addEventListener('input', updateCalculation);
        if (paymentTypeSelect) paymentTypeSelect.addEventListener('change', syncPaymentTypeStatus);
        if (paymentMethodSelect) {
            paymentMethodSelect.addEventListener('change', function () {
                if (paymentMethodSelect.value) localStorage.setItem('lastPaymentMethod', paymentMethodSelect.value);
                updateMethodCards();
                syncCreditNoteVisibility();
            });
        }

        document.addEventListener('click', function (event) {
            var methodCard = event.target.closest('[data-method-value]');
            if (methodCard && paymentMethodSelect) {
                paymentMethodSelect.value = methodCard.dataset.methodValue || '';
                paymentMethodSelect.dispatchEvent(new Event('change'));
            }

            var resultItem = event.target.closest('.booking-search-item');
            if (resultItem && bookingIdInput && bookingTypeSelect) {
                bookingIdInput.value = resultItem.dataset.id || '';
                if (searchResults) searchResults.hidden = true;
                fetchBookingDetails(bookingTypeSelect.value, bookingIdInput.value);
            }

            if (searchResults && !event.target.closest('.payment-booking-picker__search')) {
                searchResults.hidden = true;
            }
        });

        document.querySelectorAll('input[name="document_actions[]"]').forEach(function (input) {
            input.addEventListener('change', syncQuotationOptions);
        });

        if (searchBtn) searchBtn.addEventListener('click', openBookingPickerModal);

        // Picker modal: type change → re-search
        if (pickerTypeSelect) {
            pickerTypeSelect.addEventListener('change', function () {
                if (pickerResults) pickerResults.innerHTML = '<p class="payment-empty-state">Start typing or click Search to find a booking.</p>';
                searchPickerBookings(true);
            });
        }

        // Picker modal: search-as-you-type
        if (pickerSearchInput) {
            pickerSearchInput.addEventListener('input', function () {
                if (pickerSearchTimeout) window.clearTimeout(pickerSearchTimeout);
                pickerSearchTimeout = window.setTimeout(function () {
                    searchPickerBookings(false);
                }, 300);
            });
        }

        // Picker modal: search button
        if (pickerSearchBtn) {
            pickerSearchBtn.addEventListener('click', function () {
                searchPickerBookings(false);
            });
        }

        // Picker modal: select booking via row click or Select button
        if (pickerResults) {
            pickerResults.addEventListener('click', function (event) {
                var selectBtn = event.target.closest('.picker-modal__select-btn');
                var row = event.target.closest('.picker-modal__row');
                var id = (selectBtn || row) ? (selectBtn || row).dataset.id : null;
                if (id) selectPickerBooking(id);
            });
            pickerResults.addEventListener('keydown', function (event) {
                if (event.key === 'Enter' || event.key === ' ') {
                    var row = event.target.closest('.picker-modal__row');
                    if (row && row.dataset.id) {
                        event.preventDefault();
                        selectPickerBooking(row.dataset.id);
                    }
                }
            });
        }

        // Picker modal: close on backdrop / data-modal-close
        if (pickerModal) {
            pickerModal.addEventListener('click', function (event) {
                if (event.target === pickerModal || event.target.closest('[data-modal-close]')) {
                    pickerModal.classList.remove('active');
                }
            });
            document.addEventListener('keydown', function (event) {
                if (event.key === 'Escape' && pickerModal.classList.contains('active')) {
                    pickerModal.classList.remove('active');
                }
            });
        }
        if (bookingIdInput) {
            bookingIdInput.addEventListener('focus', function () {
                if (bookingTypeSelect && bookingTypeSelect.value) loadRecentBookings(bookingTypeSelect.value);
            });
            bookingIdInput.addEventListener('input', searchBookings);
        }
        if (bookingTypeSelect) {
            bookingTypeSelect.addEventListener('change', function () {
                if (searchResults) searchResults.hidden = true;
                if (bookingIdInput) bookingIdInput.value = '';
                if (dynamicInfo) dynamicInfo.hidden = true;
                if (paymentAmount) paymentAmount.value = '';
                updateCalculation();
            });
        }
        if (clearBookingBtn) {
            clearBookingBtn.addEventListener('click', function () {
                if (dynamicInfo) dynamicInfo.hidden = true;
                if (bookingIdInput) bookingIdInput.value = '';
                if (paymentAmount) paymentAmount.value = '';
                updateCalculation();
            });
        }

        if (cnNumberInput && cnLookupStatus) {
            cnNumberInput.addEventListener('blur', function () {
                var number = cnNumberInput.value.trim();
                if (!number) {
                    cnLookupStatus.textContent = '';
                    return;
                }
                cnLookupStatus.textContent = 'Checking...';
                fetch('api/credit-notes.php?action=lookup&cn_number=' + encodeURIComponent(number), {
                    headers: { 'X-Requested-With': 'XMLHttpRequest' }
                })
                    .then(function (response) { return response.json(); })
                    .then(function (data) {
                        if (data.success && data.data) {
                            cnLookupStatus.innerHTML = '<span class="payment-lookup payment-lookup--success"><i class="fas fa-check-circle"></i> Available - Balance: ' + formatMoney(data.data.balance || 0, currencySymbol) + ' - Expires: ' + (data.data.expires_at || 'Never') + '</span>';
                        } else {
                            cnLookupStatus.innerHTML = '<span class="payment-lookup payment-lookup--danger"><i class="fas fa-times-circle"></i> ' + (data.error || 'Credit note not found or already used.') + '</span>';
                        }
                    })
                    .catch(function () {
                        cnLookupStatus.textContent = 'Could not verify credit note.';
                    });
            });
        }

        syncPaymentTypeStatus();
        syncCreditNoteVisibility();
        syncQuotationOptions();
        updateMethodCards();
        updateCalculation();

        form.addEventListener('submit', function () {
            if (paymentTypeSelect && paymentTypeSelect.value === 'refund' && paymentStatusSelect && paymentStatusSelect.value !== 'refunded') {
                paymentStatusSelect.value = 'refunded';
            }
            if (paymentMethodSelect && paymentMethodSelect.value === 'credit_note' && cnNumberInput && !cnNumberInput.value.trim()) {
                showMessage('Credit Note Required', 'Enter a credit note number before saving.');
            }
        });
    });
}());
