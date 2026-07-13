        function showAvailabilityModal(message) {
            const modal = document.getElementById('availabilityModal');
            const msgEl = document.getElementById('availabilityModalMessage');
            if (msgEl) msgEl.innerHTML = message;
            if (modal) {
                modal.classList.add('modal--active');
                document.body.classList.add('modal-open');
            }
        }

        function closeAvailabilityModal() {
            const modal = document.getElementById('availabilityModal');
            if (modal) {
                modal.classList.remove('modal--active');
                document.body.classList.remove('modal-open');
            }
        }

        // Close modal when clicking outside
        window.addEventListener('click', function(event) {
            const modal = document.getElementById('availabilityModal');
            if (event.target === modal) {
                closeAvailabilityModal();
            }
        });
        function getBlockedDatesForRoom(roomId) {
            const roomKey = roomId !== null && roomId !== undefined ? String(roomId) : null;
            const roomDates = roomKey && blockedDatesByRoom[roomKey] ? blockedDatesByRoom[roomKey] : [];
            return Array.from(new Set([...(globalBlockedDates || []), ...(roomDates || [])]));
        }

        function getBookedDatesForRoom(roomId) {
            const roomKey = roomId !== null && roomId !== undefined ? String(roomId) : null;
            return roomKey && bookedDatesByRoom[roomKey] ? bookedDatesByRoom[roomKey] : [];
        }

        function getAllUnavailableDatesForRoom(roomId) {
            const blockedDates = getBlockedDatesForRoom(roomId);
            const bookedDates = getBookedDatesForRoom(roomId);
            return Array.from(new Set([...blockedDates, ...bookedDates]));
        }

        function getSelectedRoomData() {
            return selectedRoomId ? roomsData.find(room => room.id === selectedRoomId) : null;
        }

        function pickOccupancyForGuestCount(guestCount, room) {
            if (!room || guestCount < 1) return null;
            if (guestCount === 1 && Number(room.single_enabled || 0) === 1) return 'single';
            if (guestCount === 2 && Number(room.double_enabled || 0) === 1) return 'double';
            if (guestCount === 3 && Number(room.triple_enabled || 0) === 1) return 'triple';
            if (guestCount > 3) {
                if (Number(room.triple_enabled || 0) === 1) return 'triple';
                if (Number(room.double_enabled || 0) === 1) return 'double';
                if (Number(room.single_enabled || 0) === 1) return 'single';
            }
            return null;
        }

        function getOccupancyLabel(occupancyType) {
            if (occupancyType === 'single') return 'Single';
            if (occupancyType === 'double') return 'Double';
            if (occupancyType === 'triple') return 'Triple';
            return 'Standard';
        }

        function getPriceForOccupancy(room, occupancyType) {
            if (!room) return 0;
            if (occupancyType === 'single') return Number(room.price_single_occupancy || room.price_per_night || 0);
            if (occupancyType === 'double') return Number(room.price_double_occupancy || room.price_per_night || 0);
            if (occupancyType === 'triple') return Number(room.price_triple_occupancy || room.price_per_night || 0);
            return Number(room.price_per_night || 0);
        }

        function getCurrentChildGuestCount() {
            const childInput = document.getElementById('child_guests');
            return Math.max(0, parseInt(childInput?.value || '0', 10) || 0);
        }

        function getGuestAllocation(totalGuests, room, childGuests = null) {
            const normalizedGuests = Math.max(0, Number(totalGuests || 0));
            if (!room || normalizedGuests < 1) return [];
            const maxGuestsPerRoom = Math.max(1, Number(room.max_guests || 1));
            const roomsNeeded = Math.ceil(normalizedGuests / maxGuestsPerRoom);
            const childCount = Math.min(
                Math.max(0, Number(childGuests === null ? getCurrentChildGuestCount() : childGuests) || 0),
                normalizedGuests
            );
            const adultGuests = normalizedGuests - childCount;

            if (adultGuests < roomsNeeded) return [];

            const allocation = [];
            let remainingGuests = normalizedGuests;
            let remainingAdults = adultGuests;
            let remainingChildren = childCount;

            for (let index = 0; index < roomsNeeded; index++) {
                const roomsLeft = roomsNeeded - index;
                const minForOthers = Math.max(0, roomsLeft - 1);
                const guestsThisRoom = Math.min(maxGuestsPerRoom, Math.max(1, remainingGuests - minForOthers));
                const adultReserveForLaterRooms = Math.max(0, roomsLeft - 1);
                const adultsAvailableThisRoom = remainingAdults - adultReserveForLaterRooms;

                if (adultsAvailableThisRoom < 1) return [];

                let childrenThisRoom = Math.min(remainingChildren, Math.max(0, guestsThisRoom - 1));
                let adultsThisRoom = guestsThisRoom - childrenThisRoom;

                if (adultsThisRoom > adultsAvailableThisRoom) {
                    adultsThisRoom = adultsAvailableThisRoom;
                    childrenThisRoom = guestsThisRoom - adultsThisRoom;
                }

                if (childrenThisRoom > remainingChildren) {
                    childrenThisRoom = remainingChildren;
                    adultsThisRoom = guestsThisRoom - childrenThisRoom;
                }

                if (adultsThisRoom < 1 || childrenThisRoom < 0) return [];

                const occupancyType = pickOccupancyForGuestCount(guestsThisRoom, room);
                allocation.push({
                    guests: guestsThisRoom,
                    adults: adultsThisRoom,
                    children: childrenThisRoom,
                    occupancyType
                });
                remainingGuests -= guestsThisRoom;
                remainingAdults -= adultsThisRoom;
                remainingChildren -= childrenThisRoom;
            }

            return remainingGuests === 0 && remainingAdults === 0 && remainingChildren === 0 ? allocation : [];
        }

        function getRoomsNeededForGuests(totalGuests, room) {
            if (!room || totalGuests < 1) return 0;
            return Math.ceil(totalGuests / Math.max(1, Number(room.max_guests || 1)));
        }

        function getAllocationValidationMessage(totalGuests, room, childGuests = null) {
            const normalizedGuests = Math.max(0, Number(totalGuests || 0));
            if (!room || normalizedGuests < 1) return 'Select a room and guest count first.';
            const childCount = Math.min(
                Math.max(0, Number(childGuests === null ? getCurrentChildGuestCount() : childGuests) || 0),
                normalizedGuests
            );
            const adultGuests = normalizedGuests - childCount;
            const roomsNeeded = getRoomsNeededForGuests(normalizedGuests, room);

            if (adultGuests < 1) {
                return 'At least 1 adult is required for every booking.';
            }

            if (adultGuests < roomsNeeded) {
                return `This group needs ${roomsNeeded} room${roomsNeeded === 1 ? '' : 's'}, so it needs at least ${roomsNeeded} adult${roomsNeeded === 1 ? '' : 's'}.`;
            }

            const allocation = getGuestAllocation(normalizedGuests, room, childCount);
            if (!allocation.length || allocation.some(part => part.occupancyType === null)) {
                return 'The selected room type does not have pricing enabled for this guest combination.';
            }

            return '';
        }

        function hasValidAllocation(totalGuests, room, childGuests = null) {
            const allocation = getGuestAllocation(totalGuests, room, childGuests);
            return allocation.length > 0 && allocation.every(part => part.occupancyType !== null);
        }

        function buildAvailabilityStatusKey(roomId, checkIn, checkOut, childGuests, totalGuests) {
            const room = roomsData.find(item => item.id === Number(roomId));
            const roomsNeeded = getRoomsNeededForGuests(Number(totalGuests || 0), room);
            return `${roomId}_${checkIn}_${checkOut}_${childGuests}_${totalGuests}_${roomsNeeded}`;
        }

        function applyBlockedDatesToCalendars(roomId) {
            const allUnavailableDates = getAllUnavailableDatesForRoom(roomId);

            if (checkInCalendar) {
                checkInCalendar.set('disable', allUnavailableDates);
            }

            if (checkOutCalendar) {
                checkOutCalendar.set('disable', allUnavailableDates);
            }
        }

        // Generate date range between two dates
        function getDateRange(startDate, endDate) {
            const dates = [];
            let currentDate = new Date(startDate);
            const end = new Date(endDate);

            while (currentDate < end) {
                dates.push(currentDate.toISOString().split('T')[0]);
                currentDate.setDate(currentDate.getDate() + 1);
            }
            return dates;
        }

        // ── Graceful section-to-section scroll ────────────────────────────
        // Custom RAF easing — cubic ease-in-out, ~900ms — far smoother than
        // the browser's default behavior:'smooth' which often feels snappy.
        function scrollToSection(el, delay) {
            if (!el) return;
            setTimeout(function () {
                const headerEl = document.querySelector('header') || document.querySelector('.site-header');
                const offset   = (headerEl ? headerEl.offsetHeight : 80) + 32;
                const targetY  = Math.max(0, el.getBoundingClientRect().top + window.pageYOffset - offset);
                const startY   = window.pageYOffset;
                const dist     = targetY - startY;

                if (Math.abs(dist) < 8) return; // already there

                // Duration scales with distance; cap between 640ms – 1100ms
                const duration = Math.min(1100, Math.max(640, Math.abs(dist) * 0.75));
                let startTime  = null;

                function easeInOutCubic(t) {
                    return t < 0.5 ? 4 * t * t * t : 1 - Math.pow(-2 * t + 2, 3) / 2;
                }

                function tick(now) {
                    if (!startTime) startTime = now;
                    const elapsed  = now - startTime;
                    const progress = Math.min(elapsed / duration, 1);
                    window.scrollTo(0, startY + dist * easeInOutCubic(progress));
                    if (progress < 1) requestAnimationFrame(tick);
                }

                requestAnimationFrame(tick);
            }, delay || 280);
        }

        /**
         * Booking step progression system.
         * Steps: 1 = When Are You Staying?, 2 = Select Your Room,
         *        3 = Guest Information, 4 = Guest Details,
         *        5 = Add-On Packages + Summary
         */
        function getStepSection(stepNumber) {
            switch (stepNumber) {
                case 1: return document.getElementById('bookingDetailsSection');
                case 2: return document.getElementById('roomSectionWrapper');
                case 3: return document.querySelector('.form-section-title .fa-user')?.closest('.form-section');
                case 4: return document.getElementById('guestDetailsSection');
                case 5: return document.getElementById('packagesSection');
                default: return null;
            }
        }

        function isStepComplete(stepNumber) {
            switch (stepNumber) {
                case 1: // Dates set?
                    var ci = document.getElementById('check_in_date');
                    var co = document.getElementById('check_out_date');
                    return ci && co && ci.value && co.value && co.value > ci.value;
                case 2: // Room selected?
                    return !!document.querySelector('input[name="room_id"]:checked');
                case 3: // Guest Information (name, email, phone filled AND validated green)?
                    var n = document.getElementById('guest_name');
                    var e = document.getElementById('guest_email');
                    var p = document.getElementById('guest_phone');
                    return n && e && p
                        && n.value.trim() && e.value.trim() && p.value.trim()
                        && n.classList.contains('is-valid')
                        && e.classList.contains('is-valid')
                        && p.classList.contains('is-valid');
                case 4: // Guest Details (number of guests selected)?
                    var g = document.getElementById('number_of_guests');
                    return g && g.value && parseInt(g.value) > 0;
                case 5: // Packages — always reachable (optional)
                    return true;
                default:
                    return false;
            }
        }

        /** Advance to a step, ensuring all previous steps are complete.
         *  Falls back to the earliest incomplete step if validation fails. */
        function advanceToStep(targetStep) {
            // Find the first incomplete step before target
            for (var s = 1; s < targetStep; s++) {
                if (!isStepComplete(s)) {
                    var fallback = getStepSection(s);
                    if (fallback) scrollToSection(fallback, 350);
                    // Highlight incomplete fields on step 3
                    if (s === 3) {
                        ['guest_name','guest_email','guest_phone'].forEach(function(id) {
                            var f = document.getElementById(id);
                            if (f && !f.value.trim()) {
                                f.style.borderColor = '#dc3545';
                                var clr = function(){ this.style.borderColor = ''; };
                                f.addEventListener('change', clr, {once:true});
                                f.addEventListener('input', clr, {once:true});
                            }
                        });
                    }
                    return;
                }
            }
            // All prior steps complete — scroll to target
            var section = getStepSection(targetStep);
            if (section) scrollToSection(section, 350);
        }

        // Reveal room selection section once both dates are set
        function revealRoomSection() {
            const ci = document.getElementById('check_in_date');
            const co = document.getElementById('check_out_date');
            const wrapper = document.getElementById('roomSectionWrapper');
            const datesGood = ci && co && ci.value && co.value && co.value > ci.value;

            if (datesGood) {
                if (wrapper) {
                    wrapper.style.display = '';
                    scrollToSection(wrapper, 350);
                } else if (preselectedRoomId) {
                    // Pre-selected room with dates set — advance to step 3 (Guest Information)
                    advanceToStep(3);
                }
            } else {
                if (wrapper) wrapper.style.display = 'none';
            }
        }

        // Initialize calendars
        function initCalendars() {
            const today = new Date();
            const tomorrow = new Date(today.getFullYear(), today.getMonth(), today.getDate() + 1);
            const maxDate = new Date();
            maxDate.setDate(maxDate.getDate() + maxAdvanceDays);

            // Check-in calendar
            checkInCalendar = flatpickr('#check_in_date', {
                minDate: 'today',
                maxDate: maxDate,
                dateFormat: 'Y-m-d',
                disable: getAllUnavailableDatesForRoom(selectedRoomId),
                onDayCreate: function(dObj, dStr, fp, dayElem) {
                    // Add custom class for blocked and booked dates
                    const dateStr = fp.formatDate(dayElem.dateObj, 'Y-m-d');
                    const roomBlockedDates = getBlockedDatesForRoom(selectedRoomId);
                    const roomBookedDates = getBookedDatesForRoom(selectedRoomId);

                    // Check if date is blocked (manually blocked from admin)
                    if (roomBlockedDates.includes(dateStr)) {
                        dayElem.classList.add('blocked-date');
                        dayElem.innerHTML += '<span class="blocked-indicator"></span>';
                    }
                    // Check if date is booked (fully booked from availability check)
                    else if (roomBookedDates.includes(dateStr)) {
                        dayElem.classList.add('booked-date');
                        dayElem.innerHTML += '<span class="booked-indicator"></span>';
                    }
                },
                onChange: function(selectedDates, dateStr, instance) {
                    if (selectedDates.length > 0) {
                        // Update check-out calendar min date
                        const nextDay = new Date(selectedDates[0]);
                        nextDay.setDate(nextDay.getDate() + 1);

                        if (checkOutCalendar) {
                            checkOutCalendar.set('minDate', nextDay);

                            // If check-out is before new min date, clear it
                            const currentCheckOut = checkOutCalendar.selectedDates[0];
                            if (currentCheckOut && currentCheckOut < nextDay) {
                                checkOutCalendar.clear();
                            }

                            // Auto-open check-out if it hasn't been set yet
                            if (!checkOutCalendar.selectedDates.length) {
                                setTimeout(() => checkOutCalendar.open(), 180);
                            }
                        }
                    }
                    revealRoomSection();
                    updateSummary();
                }
            });

            // Check-out calendar
            checkOutCalendar = flatpickr('#check_out_date', {
                minDate: tomorrow,
                maxDate: maxDate,
                dateFormat: 'Y-m-d',
                disable: getAllUnavailableDatesForRoom(selectedRoomId),
                onDayCreate: function(dObj, dStr, fp, dayElem) {
                    // Add custom class for blocked and booked dates
                    const dateStr = fp.formatDate(dayElem.dateObj, 'Y-m-d');
                    const roomBlockedDates = getBlockedDatesForRoom(selectedRoomId);
                    const roomBookedDates = getBookedDatesForRoom(selectedRoomId);

                    // Check if date is blocked (manually blocked from admin)
                    if (roomBlockedDates.includes(dateStr)) {
                        dayElem.classList.add('blocked-date');
                        dayElem.innerHTML += '<span class="blocked-indicator"></span>';
                    }
                    // Check if date is booked (fully booked from availability check)
                    else if (roomBookedDates.includes(dateStr)) {
                        dayElem.classList.add('booked-date');
                        dayElem.innerHTML += '<span class="booked-indicator"></span>';
                    }
                },
                onChange: function() {
                    revealRoomSection();
                    updateSummary();
                }
            });
        }

        // Initialize calendars on page load
        document.addEventListener('DOMContentLoaded', function() {
            initCalendars();

            // Handle hero widget parameters - pre-fill form
            if (heroCheckIn && checkInCalendar) {
                checkInCalendar.setDate(heroCheckIn);
            }

            if (heroCheckOut && checkOutCalendar) {
                checkOutCalendar.setDate(heroCheckOut);
            }

            // Handle room type from hero widget
            if (heroRoomType && !preselectedRoomId) {
                // Map room type to room name and find matching room
                const roomTypeMapping = {
                    'standard': 'Standard Room',
                    'deluxe': 'Deluxe Room',
                    'suite': 'Suite',
                    'family': 'Family Room'
                };

                const targetRoomName = roomTypeMapping[heroRoomType];
                if (targetRoomName) {
                    const matchingRoom = roomsData.find(room => room.name === targetRoomName);
                    if (matchingRoom) {
                        // Select the matching room
                        const roomOption = document.querySelector(`.room-option[data-room-id="${matchingRoom.id}"]`);
                        if (roomOption) {
                            selectRoom(roomOption);
                        }
                    }
                }
            }

            // Handle guests from hero widget
            if (heroGuests) {
                const guestSelect = document.getElementById('number_of_guests');
                if (guestSelect) {
                    // Set guests value after room is selected
                    setTimeout(() => {
                        const maxSelectableGuests = selectedRoomMaxGuests ? Math.min(20, Math.max(selectedRoomMaxGuests * 4, selectedRoomMaxGuests)) : 20;
                        guestSelect.value = Math.min(heroGuests, maxSelectableGuests);

                        // Handle children from hero widget
                        if (heroChildren) {
                            const childInput = document.getElementById('child_guests');
                            if (childInput) {
                                const maxChildren = Math.max(0, heroGuests - 1);
                                const childCount = Math.min(heroChildren, maxChildren);
                                childInput.value = childCount;
                            }
                        }

                        enforceChildGuestRules();
                        checkGuestCapacity();
                        updateSummary();
                    }, 100);
                }
            }

            // Handle room type from hero widget - match by room name
            if (heroRoomType && !preselectedRoomId) {
                // Find room by exact name match from roomsData
                const matchingRoom = roomsData.find(room => room.name === heroRoomType);
                if (matchingRoom) {
                    // Select the matching room (suppress scroll — page-load auto-select)
                    const roomOption = document.querySelector(`.room-option[data-room-id="${matchingRoom.id}"]`);
                    if (roomOption) {
                        selectRoom(roomOption, true);
                    }
                }
            }

            // If room is pre-selected, initialize with that room
            if (preselectedRoomId) {
                // Find the pre-selected room data from roomsData
                const preselectedRoom = roomsData.find(room => room.id === preselectedRoomId);

                if (preselectedRoom) {
                    // Create a synthetic room option to call selectRoom
                    // This ensures all room-specific settings are properly initialized
                    const syntheticRoomOption = {
                        querySelector: function(selector) {
                            if (selector === 'input[type="radio"]' || selector === 'input[name="room_id"]') {
                                return {
                                    value: preselectedRoom.id,
                                    checked: true
                                };
                            }
                            if (selector === 'h4') {
                                return {
                                    textContent: preselectedRoom.name
                                };
                            }
                            if (selector === '.room-price-amount') {
                                return {
                                    textContent: currencySymbol + preselectedRoom.price_per_night.toLocaleString()
                                };
                            }
                            if (selector === '.room-price-period') {
                                return {
                                    textContent: 'per night'
                                };
                            }
                            return null;
                        },
                        getAttribute: function(attr) {
                            if (attr === 'data-room-id') return preselectedRoom.id;
                            if (attr === 'data-room-name') return preselectedRoom.name;
                            if (attr === 'data-room-price') return preselectedRoom.price_per_night;
                            if (attr === 'data-max-guests') return preselectedRoom.max_guests;
                            return null;
                        },
                        classList: {
                            add: function() {},
                            contains: function() {
                                return false;
                            }
                        },
                        closest: function() {
                            return this;
                        }
                    };

                    // Call selectRoom to ensure all room-specific settings are applied (suppress scroll on page load)
                    selectRoom(syntheticRoomOption, true);

                    // Trigger availability check for pre-selected room if dates are provided
                    if (heroCheckIn && heroCheckOut) {
                        setTimeout(() => {
                            performAvailabilityCheck();
                        }, 200);
                    }
                }
            }

            // Add booking type change listeners
            const bookingTypeRadios = document.querySelectorAll('input[name="booking_type"]');
            bookingTypeRadios.forEach(radio => {
                radio.addEventListener('change', function() {
                    selectBookingType(this.value);
                    // Scroll to packages if visible, otherwise go straight to summary
                    const pkgSection = document.getElementById('packagesSection');
                    if (pkgSection && pkgSection.style.display !== 'none') {
                        scrollToSection(pkgSection, 400);
                    } else {
                        const bottomSection = document.querySelector('.booking-bottom-section')
                                           || document.querySelector('.booking-action-bar');
                        scrollToSection(bottomSection, 520);
                    }
                });
            });

            // Reveal room section if dates are already set (e.g. hero widget pre-fill)
            revealRoomSection();
        });

        // Helper function to update occupancy visual selection based on guest count
        function updateOccupancyVisualSelection(guestCount) {
            const room = getSelectedRoomData();
            const perRoomGuests = room ? Math.min(guestCount, Math.max(1, Number(room.max_guests || 1))) : guestCount;
            const selectedType = pickOccupancyForGuestCount(perRoomGuests, room);

            ['single', 'double', 'triple'].forEach(type => {
                const label = document.getElementById(type + 'OccupancyLabel');
                if (label) {
                    label.classList.toggle('selected', selectedType === type);
                }
            });
        }

        // Update price displays based on guest count (occupancy is auto-determined)
        function updatePriceBasedOnGuestCount() {
            if (!selectedRoomId) return;

            const guestSelect = document.getElementById('number_of_guests');
            const guestCount = parseInt(guestSelect?.value || '0', 10);

            // Find the selected room from roomsData
            const selectedRoom = roomsData.find(room => room.id === selectedRoomId);
            if (!selectedRoom) return;

            const perRoomGuests = Math.min(guestCount || 1, Math.max(1, Number(selectedRoom.max_guests || 1)));
            const occupancyType = pickOccupancyForGuestCount(perRoomGuests, selectedRoom);
            const newPrice = getPriceForOccupancy(selectedRoom, occupancyType);

            selectedRoomPrice = newPrice;

            const selectedCardPrice = document.querySelector('.room-option.selected .room-price-amount');
            if (selectedCardPrice) {
                selectedCardPrice.textContent = currencySymbol + Number(newPrice || 0).toLocaleString();
            }

            // Update visual selection based on guest count
            updateOccupancyVisualSelection(guestCount);

            // Update summary after price change to reflect new pricing
            updateSummary();
        }

        function applyOccupancyAvailability(room) {
            const occupancyHint = document.getElementById('occupancyHint');
            const mapping = [{
                    key: 'single_enabled',
                    labelId: 'singleOccupancyLabel',
                    value: 'single'
                },
                {
                    key: 'double_enabled',
                    labelId: 'doubleOccupancyLabel',
                    value: 'double'
                },
                {
                    key: 'triple_enabled',
                    labelId: 'tripleOccupancyLabel',
                    value: 'triple'
                }
            ];

            let enabledCount = 0;
            mapping.forEach(item => {
                const enabled = Number(room[item.key] || 0) === 1;
                if (enabled) {
                    enabledCount++;
                }
                const label = document.getElementById(item.labelId);
                if (label) {
                    label.classList.toggle('occupancy-tier--disabled', !enabled);
                    label.setAttribute('aria-disabled', enabled ? 'false' : 'true');
                    label.classList.remove('selected');
                }
            });

            // Update hint based on available options
            if (occupancyHint) {
                if (enabledCount === 1) {
                    const enabledType = mapping.find(item => Number(room[item.key] || 0) === 1);
                    if (enabledType) {
                        const typeName = enabledType.key.replace('_enabled', '');
                        occupancyHint.innerHTML = `<i class="fas fa-info-circle"></i> Only ${typeName} occupancy available for this room`;
                    }
                } else {
                    occupancyHint.innerHTML = '<i class="fas fa-info-circle"></i> Occupancy type is automatically determined based on your guest count';
                }
            }

            // Update price and visual selection after applying availability
            updatePriceBasedOnGuestCount();
        }

        function applyChildrenPolicy(room) {
            const childInput = document.getElementById('child_guests');
            const childHint = document.getElementById('childGuestHint');
            const childGroup = childInput ? childInput.closest('.form-group') : null;
            const allowed = Number(room.children_allowed || 0) === 1;
            if (!childInput) return;

            childInput.disabled = !allowed;

            // Visual indication for disabled state
            if (childGroup) {
                childGroup.style.opacity = allowed ? '1' : '0.5';
            }

            if (!allowed) {
                childInput.value = '0';
                if (childHint) {
                    childHint.innerHTML = '<i class="fas fa-ban" style="color: #dc3545;"></i> Children are not allowed for this room type.';
                    childHint.style.color = '#dc3545';
                }
            } else {
                // Update hint with pricing info
                const childMultiplier = Number(room.child_price_multiplier || childPriceMultiplier || 50);
                if (childHint) {
                    childHint.innerHTML = `<i class="fas fa-child"></i> Children under 12 stay at ${childMultiplier}% of adult rate. At least 1 adult required.`;
                    childHint.style.color = '#666';
                }
            }

            // Update summary after applying policy
            updateSummary();
        }

        // Update occupancy price displays when room is selected
        function updateOccupancyPrices(roomId) {
            const room = roomsData.find(r => r.id === roomId);
            if (!room) return;

            const singlePrice = document.getElementById('singlePriceDisplay');
            const doublePrice = document.getElementById('doublePriceDisplay');
            const triplePrice = document.getElementById('triplePriceDisplay');

            if (singlePrice) {
                singlePrice.textContent = currencySymbol + room.price_single_occupancy.toLocaleString();
            }
            if (doublePrice) {
                doublePrice.textContent = currencySymbol + room.price_double_occupancy.toLocaleString();
            }
            if (triplePrice) {
                triplePrice.textContent = currencySymbol + room.price_triple_occupancy.toLocaleString();
            }
        }

        function updateSummaryWithDates(selection) {
            const roomRadio = document.querySelector('input[name="room_id"]:checked');
            if (!roomRadio) return;

            const roomOption = roomRadio.closest('.room-option');
            const roomName = roomOption.querySelector('h4').textContent;
            const roomPrice = parseFloat(roomOption.querySelector('.room-price-amount').textContent.replace(/[^0-9.]/g, ''));

            const checkInDate = new Date(selection.checkIn + 'T12:00:00');
            const checkOutDate = new Date(selection.checkOut + 'T12:00:00');

            document.getElementById('summaryRoom').textContent = roomName;
            document.getElementById('summaryCheckIn').textContent = checkInDate.toLocaleDateString('en-US', {
                month: 'short',
                day: 'numeric',
                year: 'numeric'
            });
            document.getElementById('summaryCheckOut').textContent = checkOutDate.toLocaleDateString('en-US', {
                month: 'short',
                day: 'numeric',
                year: 'numeric'
            });
            document.getElementById('summaryNights').textContent = selection.nights + (selection.nights === 1 ? ' night' : ' nights');
            document.getElementById('summaryTotal').textContent = currencySymbol + (roomPrice * selection.nights).toLocaleString();
            document.getElementById('bookingSummary').style.display = 'block';

            // Enable submit button
            const submitBtn = document.querySelector('.btn-submit');
            submitBtn.disabled = false;
            submitBtn.innerHTML = '<i class="fas fa-check-circle"></i> Confirm Booking';
            submitBtn.style.opacity = '1';
        }

        function selectRoom(label, skipScroll) {
            document.querySelectorAll('.room-option').forEach(opt => opt.classList.remove('selected'));
            label.classList.add('selected');

            const previousGuests = parseInt(document.getElementById('number_of_guests')?.value || heroGuests || '0', 10);
            const roomRadio = label.querySelector('input[name="room_id"]');
            const roomId = parseInt(roomRadio.value);
            const roomName = label.getAttribute('data-room-name');
            const roomPrice = parseFloat(label.getAttribute('data-room-price'));

            roomRadio.checked = true;

            selectedRoomId = roomId;
            selectedRoomName = roomName;
            selectedRoomMaxGuests = parseInt(label.getAttribute('data-max-guests'));

            // Update guest options based on room capacity
            updateGuestOptions(selectedRoomMaxGuests);

            const guestSelect = document.getElementById('number_of_guests');
            const selectedRoom = roomsData.find(room => room.id === roomId);
            const maxSelectableGuests = Math.min(20, Math.max(selectedRoomMaxGuests * 4, selectedRoomMaxGuests));
            const fallbackGuests = selectedRoomMaxGuests > 1 ? 2 : 1;
            const desiredGuests = previousGuests > 0 ? previousGuests : fallbackGuests;
            const normalizedGuests = Math.min(maxSelectableGuests, Math.max(1, desiredGuests));
            guestSelect.value = hasValidAllocation(normalizedGuests, selectedRoom) ? String(normalizedGuests) : '1';

            // Update occupancy prices for this room
            updateOccupancyPrices(roomId);

            // Find the room data from roomsData array
            const room = roomsData.find(r => r.id === roomId);
            if (room) {
                applyOccupancyAvailability(room);
                applyChildrenPolicy(room);
            }

            // Update child-friendly indicators across all room cards
            const currentChildGuests = getCurrentChildGuestCount();
            refreshChildFriendlyRoomIndicators(currentChildGuests);

            // Update price based on guest count (occupancy is auto-determined)
            updatePriceBasedOnGuestCount();
            checkGuestCapacity();

            // Update calendars with selected room blocked dates (global + room-specific)
            applyBlockedDatesToCalendars(roomId);

            if (document.getElementById('check_in_date')?.value && document.getElementById('check_out_date')?.value) {
                performAvailabilityCheck();
            }

            // Scroll to Step 3: Guest Information after a room is picked (skip on page-load pre-selection)
            if (!skipScroll) {
                advanceToStep(3);
            }
        }

        // Update guest dropdown options based on room capacity
        function updateGuestOptions(maxGuests) {
            const guestSelect = document.getElementById('number_of_guests');
            const capacityHint = document.getElementById('guestCapacityHint');
            const room = getSelectedRoomData();
            const currentValue = parseInt(guestSelect.value || '0', 10);
            const maxSelectableGuests = Math.min(20, Math.max(maxGuests * 4, maxGuests));

            // Clear existing options
            guestSelect.innerHTML = '<option value="">Select number of guests...</option>';

            for (let i = 1; i <= maxSelectableGuests; i++) {
                const option = document.createElement('option');
                option.value = i;
                const roomsNeeded = Math.ceil(i / Math.max(1, maxGuests));
                option.textContent = i + (i === 1 ? ' Guest' : ' Guests') + (roomsNeeded > 1 ? ` (${roomsNeeded} rooms)` : '');
                if (room && !hasValidAllocation(i, room)) {
                    option.disabled = true;
                    option.textContent += ' - pricing unavailable';
                }
                guestSelect.appendChild(option);
            }

            // Update capacity hint
            capacityHint.textContent = `This room accommodates up to ${maxGuests} guest${maxGuests > 1 ? 's' : ''} per room. Larger groups are split across multiple rooms automatically.`;
            capacityHint.style.display = 'block';

            if (currentValue && currentValue <= maxSelectableGuests) {
                guestSelect.value = String(currentValue);
            }

            // Hide second room suggestion
            document.getElementById('secondRoomSuggestion').style.display = 'none';
        }

        // Check if guests exceed capacity and show second room suggestion
        function checkGuestCapacity() {
            const guestSelect = document.getElementById('number_of_guests');
            const numGuests = parseInt(guestSelect.value);
            const suggestionBox = document.getElementById('secondRoomSuggestion');
            const optionsContainer = document.getElementById('secondRoomOptions');
            const room = getSelectedRoomData();
            const childGuests = getCurrentChildGuestCount();

            if (!numGuests || !selectedRoomMaxGuests || !room) {
                suggestionBox.style.display = 'none';
                return;
            }

            const allocationMessage = getAllocationValidationMessage(numGuests, room, childGuests);
            if (allocationMessage) {
                suggestionBox.style.display = 'block';
                optionsContainer.innerHTML = `
                    <div class="booking-split-notice booking-split-notice--warning">
                        <strong>Guest allocation needs attention</strong>
                        <p>${allocationMessage}</p>
                    </div>
                `;
                validateFormForSubmit();
                return;
            }

            // Check if guests exceed room capacity
            if (numGuests > selectedRoomMaxGuests) {
                suggestionBox.style.display = 'block';

                // Calculate how many rooms needed
                const roomsNeeded = Math.ceil(numGuests / selectedRoomMaxGuests);
                const allocation = getGuestAllocation(numGuests, room, childGuests);
                const allocationText = allocation.map((part, index) => `Room ${index + 1}: ${part.adults} adult${part.adults === 1 ? '' : 's'}${part.children > 0 ? ` + ${part.children} child${part.children === 1 ? '' : 'ren'}` : ''} (${getOccupancyLabel(part.occupancyType)})`).join(' • ');

                // Build suggestion message
                let html = `
                    <div class="booking-split-notice">
                        <strong>${roomsNeeded} ${selectedRoomName} room${roomsNeeded > 1 ? 's' : ''} will be reserved</strong>
                        <p>Each room accommodates up to ${selectedRoomMaxGuests} guest${selectedRoomMaxGuests > 1 ? 's' : ''}. Your booking will be split automatically under one group request.</p>
                        <p>${allocationText}</p>
                    </div>
                `;

                optionsContainer.innerHTML = html;
                validateFormForSubmit();
            } else {
                suggestionBox.style.display = 'none';

                // Enable submit button if all validations pass
                validateFormForSubmit();
            }
        }

        // Validate form for submit
        function validateFormForSubmit() {
            const checkIn = document.getElementById('check_in_date').value;
            const checkOut = document.getElementById('check_out_date').value;
            const numGuests = document.getElementById('number_of_guests').value;
            const childGuests = parseInt(document.getElementById('child_guests').value || '0', 10);
            const submitBtn = document.querySelector('.btn-submit');

            const totalGuestsInt = parseInt(numGuests || '0', 10);
            const adultsInt = totalGuestsInt - childGuests;
            const childValid = childGuests >= 0 && childGuests < totalGuestsInt;
            const selectedRoom = getSelectedRoomData();
            const allocationValid = selectedRoom ? hasValidAllocation(totalGuestsInt, selectedRoom, childGuests) : false;

            if (selectedRoomId && checkIn && checkOut && numGuests && childValid && adultsInt >= 1 && allocationValid) {
                submitBtn.disabled = false;
                submitBtn.innerHTML = '<i class="fas fa-check-circle"></i> Confirm Booking';
                submitBtn.style.opacity = '1';
            } else {
                submitBtn.disabled = true;
                submitBtn.innerHTML = allocationValid || !selectedRoomId ?
                    '<i class="fas fa-calendar-check"></i> Complete All Fields (1+ adult required)' :
                    '<i class="fas fa-exclamation-triangle"></i> Choose a Supported Guest Count';
                submitBtn.style.opacity = '0.6';
            }
        }

        function updateBlockedDatesForRoom(roomId) {
            // Local parity with admin blocking logic; no auth-protected API call needed.
            applyBlockedDatesToCalendars(roomId);
        }

        function checkRoomAvailability(roomId, checkIn, checkOut, childGuests, callback) {
            const totalGuests = parseInt(document.getElementById('number_of_guests')?.value || '1', 10);
            const childCount = parseInt(childGuests || '0', 10);
            const adultGuests = Math.max(1, totalGuests - childCount);
            const room = roomsData.find(item => item.id === Number(roomId));
            const roomsNeeded = getRoomsNeededForGuests(totalGuests, room);
            const url = `check-availability.php?room_id=${roomId}&check_in=${checkIn}&check_out=${checkOut}&child_guests=${childCount}&adult_guests=${adultGuests}&number_of_guests=${totalGuests}&rooms_needed=${roomsNeeded}`;

            fetch(url)
                .then(response => response.json())
                .then(callback)
                .catch(() => {
                    callback({
                        available: false,
                        message: 'Unable to check availability'
                    });
                });
        }


        // ── Dynamic pricing + packages helpers ────────────────────────────
        function applyDynamicPricingState(pricing) {
            currentDynamicPricing = pricing || null;
            const section = document.getElementById('ratePlanSection');
            const badge = document.getElementById('ratePlanBadge');
            if (!section || !badge) return;

            if (pricing && pricing.rate_plan_id) {
                // discount_amount > 0 means a genuine discount (price reduced); < 0 means a surcharge
                const adjustment = Number(pricing.discount_amount_per_night_total ?? pricing.discount_amount ?? 0);
                const sign = adjustment > 0 ? '-' : '+';
                const abs = Math.abs(adjustment);
                badge.innerHTML = `<i class="fas fa-tag"></i> <strong>${pricing.rate_plan_label}</strong>
                    <span class="rate-plan-badge__amount">${sign}${currencySymbol}${abs.toLocaleString()}/night</span>`;
                badge.className = adjustment > 0 ?
                    'rate-plan-badge rate-plan-badge--discount' :
                    'rate-plan-badge rate-plan-badge--surcharge';
                section.style.display = '';
            } else {
                section.style.display = 'none';
            }
        }

        function renderPackages(packages, nights, adultGuests) {
            currentPackages = packages || [];
            const section = document.getElementById('packagesSection');
            const list = document.getElementById('packagesList');
            if (!section || !list) return;

            if (!currentPackages.length) {
                section.style.display = 'none';
                return;
            }

            section.style.display = '';
            // Guide the user's eye to the newly-revealed packages section
            scrollToSection(section, 450);
            list.innerHTML = currentPackages.map(pkg => {
                const isComplimentary = parseFloat(pkg.price_amount) === 0;
                let cost = 0;
                if (!isComplimentary) {
                    if (pkg.price_type === 'per_night') {
                        cost = pkg.price_amount * nights;
                    } else if (pkg.price_type === 'per_stay') {
                        cost = pkg.price_amount;
                    } else {
                        cost = pkg.price_amount * adultGuests * nights;
                    }
                }

                const priceHtml = isComplimentary ?
                    `<span class="package-option__price package-option__price--free"><i class="fas fa-gift"></i> Complimentary</span>` :
                    `<span class="package-option__price">${currencySymbol}${cost.toLocaleString()}</span>`;

                const inclusions = Array.isArray(pkg.inclusions_list) && pkg.inclusions_list.length ?
                    `<ul class="package-option__inclusions">${pkg.inclusions_list.map(i => `<li>${i}</li>`).join('')}</ul>` :
                    '';

                const checked = selectedPackageIds.has(Number(pkg.id));
                return `<label class="package-option${checked ? ' selected' : ''}" data-pkg-id="${pkg.id}" data-pkg-cost="${cost}">
                    <input type="checkbox" name="package_ids[]" value="${pkg.id}" form="bookingForm"${checked ? ' checked' : ''}>
                    <div class="package-option__body">
                        <div class="package-option__header">
                            <span class="package-option__icon"><i class="${pkg.icon || 'fas fa-gift'}"></i></span>
                            <span class="package-option__name">${pkg.name}</span>
                            ${priceHtml}
                        </div>
                        ${pkg.short_description ? `<p class="package-option__desc">${pkg.short_description}</p>` : ''}
                        ${inclusions}
                    </div>
                </label>`;
            }).join('');

            // Attach change handlers
            list.querySelectorAll('.package-option').forEach(label => {
                const cb = label.querySelector('input[type="checkbox"]');
                cb.addEventListener('change', () => {
                    const id = Number(label.dataset.pkgId);
                    if (cb.checked) {
                        selectedPackageIds.add(id);
                        label.classList.add('selected');
                    } else {
                        selectedPackageIds.delete(id);
                        label.classList.remove('selected');
                    }
                    updateSummary();
                });
            });
        }

        function getPackageTotal(nights, adultGuests) {
            if (!currentPackages.length || !selectedPackageIds.size) return 0;
            let total = 0;
            currentPackages.forEach(pkg => {
                if (!selectedPackageIds.has(Number(pkg.id))) return;
                if (pkg.price_type === 'per_night') {
                    total += pkg.price_amount * nights;
                } else if (pkg.price_type === 'per_stay') {
                    total += pkg.price_amount;
                } else {
                    total += pkg.price_amount * adultGuests * nights;
                }
            });
            return total;
        }
        // ─────────────────────────────────────────────────────────────────

        function updateSummary() {
            const checkIn = document.getElementById('check_in_date').value;
            const checkOut = document.getElementById('check_out_date').value;
            const totalGuests = parseInt(document.getElementById('number_of_guests').value || '0', 10);
            const childGuests = parseInt(document.getElementById('child_guests').value || '0', 10);
            const adults = Math.max(0, totalGuests - childGuests);
            const bookingSummary = document.getElementById('bookingSummary');
            const childChargeRow = document.getElementById('summaryChildChargeRow');
            const childChargeEl = document.getElementById('summaryChildCharge');
            const summaryGuests = document.getElementById('summaryGuests');
            const bookingTypeBadge = document.getElementById('summaryBookingTypeBadge');
            const bookingTypeText = document.getElementById('summaryBookingType');

            if (!bookingSummary) return;

            if (!selectedRoomId || !checkIn || !checkOut || totalGuests < 1) {
                bookingSummary.style.display = 'none';
                validateFormForSubmit();
                return;
            }

            if (selectedRoomId && checkIn && checkOut) {
                const checkInDate = new Date(checkIn + 'T12:00:00');
                const checkOutDate = new Date(checkOut + 'T12:00:00');
                const nights = Math.ceil((checkOutDate - checkInDate) / (1000 * 60 * 60 * 24));

                if (nights > 0) {
                    // Find the selected room from roomsData
                    const selectedRoom = roomsData.find(room => room.id === selectedRoomId);
                    if (!selectedRoom) return;

                    const roomsNeeded = getRoomsNeededForGuests(totalGuests, selectedRoom);
                    const statusKey = buildAvailabilityStatusKey(selectedRoomId, checkIn, checkOut, childGuests, totalGuests);
                    const serverPricing = currentAvailabilityResult && currentAvailabilityResult.status_key === statusKey ?
                        currentAvailabilityResult.split_pricing :
                        null;
                    const allocation = serverPricing && Array.isArray(serverPricing.allocation) ?
                        serverPricing.allocation.map(part => ({
                            guests: Number(part.guests || 0),
                            adults: Number(part.adults || 0),
                            children: Number(part.children || 0),
                            occupancyType: part.occupancy_type,
                            ratePerNight: Number(part.rate_per_night || 0)
                        })) :
                        getGuestAllocation(totalGuests, selectedRoom, childGuests);

                    if (!allocation.length || allocation.some(part => !part.occupancyType)) {
                        bookingSummary.style.display = 'none';
                        validateFormForSubmit();
                        return;
                    }

                    let roomRateTotalPerNight = 0;
                    let baseTotal = 0;
                    let childSupplement = 0;
                    let remainingChildren = childGuests;
                    const roomChildMultiplier = selectedRoom.child_price_multiplier !== undefined ?
                        Number(selectedRoom.child_price_multiplier) :
                        Number(childPriceMultiplier);

                    if (serverPricing) {
                        roomRateTotalPerNight = Number(serverPricing.room_rate_total_per_night || 0);
                        baseTotal = Number(serverPricing.base_total || 0);
                        childSupplement = Number(serverPricing.child_supplement_total || 0);
                    } else {
                        allocation.forEach(part => {
                            let ratePerNight = getPriceForOccupancy(selectedRoom, part.occupancyType);
                            if (currentDynamicPricing && currentDynamicPricing.rate_plan_id && allocation.length === 1) {
                                ratePerNight = Number(currentDynamicPricing.final_price || ratePerNight);
                            }
                            const childrenThisRoom = Math.min(remainingChildren, Math.max(0, part.guests - 1));
                            remainingChildren -= childrenThisRoom;
                            roomRateTotalPerNight += ratePerNight;
                            baseTotal += ratePerNight * nights;
                            childSupplement += childrenThisRoom > 0 ?
                                ratePerNight * (Math.max(0, roomChildMultiplier || 0) / 100) * childrenThisRoom * nights :
                                0;
                            part.ratePerNight = ratePerNight;
                            part.children = childrenThisRoom;
                            part.adults = Math.max(1, part.guests - childrenThisRoom);
                        });
                    }

                    // Calculate package total from selected packages
                    const pkgTotal = getPackageTotal(nights, adults);

                    // Calculate tourism levy if enabled
                    let tourismLevyAmount = serverPricing ? Number(serverPricing.tourism_levy_amount || 0) : 0;
                    if (!serverPricing && tourismLevyEnabled && tourismLevyPercent > 0) {
                        tourismLevyAmount = (baseTotal + childSupplement) * (tourismLevyPercent / 100);
                    }

                    const total = baseTotal + childSupplement + tourismLevyAmount + pkgTotal;

                    // Update booking type badge
                    const selectedBookingType = document.querySelector('input[name="booking_type"]:checked');
                    if (selectedBookingType && bookingTypeBadge && bookingTypeText) {
                        const isTentative = selectedBookingType.value === 'tentative';
                        bookingTypeBadge.className = 'summary-badge ' + (isTentative ? 'badge-tentative' : 'badge-standard');
                        bookingTypeText.textContent = isTentative ? 'Tentative Booking' : 'Standard Booking';
                        bookingTypeBadge.innerHTML = isTentative ?
                            '<i class="fas fa-clock"></i> <span id="summaryBookingType">Tentative Booking</span>' :
                            '<i class="fas fa-check-circle"></i> <span id="summaryBookingType">Standard Booking</span>';
                    }

                    // Update room details section
                    document.getElementById('summaryRoom').textContent = selectedRoomName;
                    const occupancySummary = roomsNeeded > 1 ?
                        `${totalGuests} Guests (${roomsNeeded} rooms: ${allocation.map((part, index) => `R${index + 1} ${getOccupancyLabel(part.occupancyType)}`).join(', ')})` :
                        `${getOccupancyLabel(allocation[0].occupancyType)} (${totalGuests} Guest${totalGuests === 1 ? '' : 's'})`;
                    document.getElementById('summaryOccupancyType').textContent = occupancySummary;
                    document.getElementById('summaryRatePerNight').textContent = roomsNeeded > 1 ?
                        `${currencySymbol}${roomRateTotalPerNight.toLocaleString()} across ${roomsNeeded} rooms` :
                        `${currencySymbol}${roomRateTotalPerNight.toLocaleString()}`;

                    // Update stay details section
                    document.getElementById('summaryCheckIn').textContent = checkInDate.toLocaleDateString('en-US', {
                        weekday: 'short',
                        month: 'short',
                        day: 'numeric',
                        year: 'numeric'
                    });
                    document.getElementById('summaryCheckOut').textContent = checkOutDate.toLocaleDateString('en-US', {
                        weekday: 'short',
                        month: 'short',
                        day: 'numeric',
                        year: 'numeric'
                    });
                    document.getElementById('summaryNights').textContent = nights;
                    const nightsLbl = document.querySelector('.bsum-nights-lbl');
                    if (nightsLbl) nightsLbl.textContent = nights === 1 ? 'night' : 'nights';

                    // Update guest details section
                    if (summaryGuests) {
                        summaryGuests.textContent = `${adults} adult${adults === 1 ? '' : 's'}${childGuests > 0 ? ` + ${childGuests} child${childGuests === 1 ? '' : 'ren'}` : ''}`;
                    }

                    // Update child supplement
                    if (childChargeRow && childChargeEl) {
                        if (childGuests > 0) {
                            childChargeRow.style.display = '';
                            childChargeEl.textContent = currencySymbol + childSupplement.toLocaleString() + ` (${childGuests} child${childGuests === 1 ? '' : 'ren'} across ${nights} night${nights === 1 ? '' : 's'})`;
                        } else {
                            childChargeRow.style.display = 'none';
                            childChargeEl.textContent = '-';
                        }
                    }

                    // Update rate plan summary row
                    const ratePlanRow = document.getElementById('summaryRatePlanRow');
                    const ratePlanLabel = document.getElementById('summaryRatePlanLabel');
                    const ratePlanValue = document.getElementById('summaryRatePlanValue');
                    const serverRatePlan = serverPricing && serverPricing.rate_plan ? serverPricing.rate_plan : null;
                    const activeRatePlan = serverRatePlan || currentDynamicPricing;
                    if (ratePlanRow && activeRatePlan && activeRatePlan.rate_plan_id) {
                        const adj = Number(activeRatePlan.discount_amount_per_night_total ?? activeRatePlan.discount_amount ?? 0);
                        // adj > 0 = genuine discount (price reduced); adj < 0 = surcharge
                        const sign = adj > 0 ? '-' : '+';
                        ratePlanLabel.textContent = (activeRatePlan.rate_plan_label || 'Special Rate') + ':';
                        ratePlanValue.textContent = sign + currencySymbol + Math.abs(adj).toLocaleString() + '/night';
                        ratePlanRow.style.display = '';
                    } else if (ratePlanRow) {
                        ratePlanRow.style.display = 'none';
                    }

                    // Update package total row + individual package names
                    const pkgTotalRow = document.getElementById('summaryPackageTotalRow');
                    const pkgTotalEl = document.getElementById('summaryPackageTotal');
                    const pkgNamesList = document.getElementById('summaryPackageNames');
                    const compCount = currentPackages.filter(p => selectedPackageIds.has(Number(p.id)) && parseFloat(p.price_amount) === 0).length;
                    const selectedPkgs = currentPackages.filter(p => selectedPackageIds.has(Number(p.id)));

                    // Populate individual package names
                    if (pkgNamesList) {
                        pkgNamesList.innerHTML = '';
                        selectedPkgs.forEach(pkg => {
                            const isComp = parseFloat(pkg.price_amount) === 0;
                            const li = document.createElement('li');
                            li.innerHTML = `<i class="${pkg.icon || 'fas fa-check-circle'}" aria-hidden="true"></i>${pkg.name}${isComp ? ' <span class="bsum-pkg-comp"><i class="fas fa-gift" aria-hidden="true"></i> Complimentary</span>' : ''}`;
                            pkgNamesList.appendChild(li);
                        });
                    }

                    if (pkgTotalRow) {
                        if (pkgTotal > 0 && compCount > 0) {
                            pkgTotalEl.innerHTML = `${currencySymbol}${pkgTotal.toLocaleString()} <span class="bsum-pkg-comp">+${compCount} free</span>`;
                            pkgTotalRow.style.display = '';
                        } else if (pkgTotal > 0) {
                            pkgTotalEl.textContent = currencySymbol + pkgTotal.toLocaleString();
                            pkgTotalRow.style.display = '';
                        } else if (compCount > 0) {
                            pkgTotalEl.innerHTML = `<span class="bsum-pkg-comp"><i class="fas fa-gift" aria-hidden="true"></i> Complimentary</span>`;
                            pkgTotalRow.style.display = '';
                        } else {
                            pkgTotalRow.style.display = 'none';
                        }
                    }

                    // Update total
                    document.getElementById('summaryTotal').textContent = currencySymbol + total.toLocaleString();

                    // Update tourism levy hint
                    const tourismLevyNote = document.getElementById('summaryTourismLevyNote');
                    const tourismLevyText = document.getElementById('tourismLevyText');
                    if (tourismLevyEnabled && tourismLevyPercent > 0 && tourismLevyAmount > 0) {
                        tourismLevyNote.style.display = '';
                        tourismLevyText.textContent = `Includes ${tourismLevyPercent}% Tourism Levy`;
                    } else {
                        tourismLevyNote.style.display = 'none';
                    }

                    bookingSummary.style.display = 'block';
                    validateFormForSubmit();
                } else {
                    bookingSummary.style.display = 'none';
                    validateFormForSubmit();
                }
            }
        }

        // Event listeners will be added inside DOMContentLoaded
        // These are defined here but attached after DOM is ready

        function enforceChildGuestRules() {
            const totalGuests = parseInt(document.getElementById('number_of_guests').value || '0', 10);
            const childInput = document.getElementById('child_guests');
            const childHint = document.getElementById('childGuestHint');
            if (!childInput) return;

            if (childInput.disabled) {
                childInput.value = '0';
                if (childHint) {
                    childHint.innerHTML = '<i class="fas fa-ban" style="color: #dc3545;"></i> Children are not allowed for this room type.';
                }
                return;
            }

            const selectedRoom = getSelectedRoomData();
            const roomsNeeded = selectedRoom ? getRoomsNeededForGuests(totalGuests, selectedRoom) : 1;
            const maxChildren = Math.max(0, totalGuests - roomsNeeded);
            childInput.max = String(maxChildren);

            let childGuests = parseInt(childInput.value || '0', 10);
            if (Number.isNaN(childGuests) || childGuests < 0) childGuests = 0;
            if (childGuests > maxChildren) {
                childGuests = maxChildren;
                childInput.value = String(childGuests);
            }

            const adults = Math.max(0, totalGuests - childGuests);

            // Get room-specific child price multiplier
            let effectiveMultiplier = childPriceMultiplier;
            if (selectedRoomId) {
                const selectedRoom = roomsData.find(room => room.id === selectedRoomId);
                if (selectedRoom && selectedRoom.child_price_multiplier !== undefined) {
                    effectiveMultiplier = Number(selectedRoom.child_price_multiplier);
                }
            }

            if (childHint) {
                let hintHtml = '';
                if (childGuests > 0 && adults >= 1) {
                    // Show breakdown with pricing
                    hintHtml = `<i class="fas fa-users"></i> ${adults} adult${adults === 1 ? '' : 's'} + ${childGuests} child${childGuests === 1 ? '' : 'ren'}`;
                    hintHtml += ` <span style="color: var(--gold);">• Child rate: ${effectiveMultiplier}% of adult price</span>`;
                } else if (adults < 1) {
                    hintHtml = `<i class="fas fa-exclamation-triangle" style="color: #dc3545;"></i> At least 1 adult is required. Current: ${adults} adult${adults === 1 ? '' : 's'}`;
                } else {
                    hintHtml = `<i class="fas fa-child"></i> Children under 12 stay at ${effectiveMultiplier}% of adult rate. At least 1 adult is required in each room. Max ${maxChildren} children for this selection.`;
                }
                childHint.innerHTML = hintHtml;
            }

            refreshChildFriendlyRoomIndicators(childGuests);
        }

        // Event listener for child_guests will be added inside DOMContentLoaded

        // Booking type selection function
        function selectBookingType(type) {
            document.querySelectorAll('.booking-type-option').forEach(opt => {
                opt.classList.remove('selected');
            });

            const selectedOption = document.querySelector(`input[name="booking_type"][value="${type}"]`);
            if (selectedOption) {
                selectedOption.closest('.booking-type-option').classList.add('selected');
            }

            // Update summary to reflect booking type change
            updateSummary();
        }

        // Room Category Filter Tabs for booking page
        (function() {
            const filterTabs = document.querySelectorAll('#roomsFilterTabs .chip');
            const roomOptions = document.querySelectorAll('.room-option[data-filter]');

            if (filterTabs.length === 0 || roomOptions.length === 0) return;

            filterTabs.forEach(tab => {
                tab.addEventListener('click', function() {
                    const filterValue = this.getAttribute('data-filter');
                    const badgeFilter = this.getAttribute('data-badge-filter');

                    // Update active tab
                    filterTabs.forEach(t => t.classList.remove('active'));
                    this.classList.add('active');

                    // Filter room options - respect availability hiding
                    roomOptions.forEach(option => {
                        const optionFilter = option.getAttribute('data-filter');

                        // Check if this room is currently disabled due to availability
                        const isAvailabilityDisabled = option.classList.contains('room-option-disabled');
                        const radio = option.querySelector('input[type="radio"]');
                        const isRadioDisabled = radio && radio.disabled;

                        // Apply filter
                        if (filterValue === 'all' || (optionFilter && optionFilter.includes(filterValue))) {
                            // Show this room option
                            option.style.display = '';

                            // If it was availability-disabled, keep it disabled but visible
                            if (isAvailabilityDisabled || isRadioDisabled) {
                                option.style.opacity = '0.5';
                                option.style.pointerEvents = 'none';
                                if (radio) radio.disabled = true;
                            } else {
                                option.style.opacity = '';
                                option.style.pointerEvents = '';
                                if (radio) radio.disabled = false;
                            }
                        } else {
                            // Hide this room option
                            option.style.display = 'none';
                        }
                    });

                    // If the currently selected room is hidden, clear selection
                    if (selectedRoomId) {
                        const selectedOption = document.querySelector(`.room-option[data-room-id="${selectedRoomId}"]`);
                        if (selectedOption && selectedOption.style.display === 'none') {
                            clearRoomSelection();
                        }
                    }
                });
            });
        })();

        // Initialize booking type selection on page load
        document.addEventListener('DOMContentLoaded', function() {
            selectBookingType('standard');
            enforceChildGuestRules();
            validateFormForSubmit();
            initInstantValidation();
            initAvailabilityValidation();

            // Add event listeners for date inputs (must be after DOM is ready)
            const checkInInput = document.getElementById('check_in_date');
            const checkOutInput = document.getElementById('check_out_date');
            const guestsInput = document.getElementById('number_of_guests');
            const childGuestsInput = document.getElementById('child_guests');

            if (checkInInput) {
                checkInInput.addEventListener('change', function() {
                    const checkIn = new Date(this.value);
                    const nextDay = new Date(checkIn);
                    nextDay.setDate(checkIn.getDate() + 1);
                    if (checkOutInput) {
                        checkOutInput.min = nextDay.toISOString().split('T')[0];
                    }
                    updateSummary();
                    validateFormForSubmit();
                });

                // Also trigger availability check on check-in date change
                checkInInput.addEventListener('input', function() {
                    updateSummary();
                    validateFormForSubmit();
                });
            }

            if (checkOutInput) {
                checkOutInput.addEventListener('change', function() {
                    updateSummary();
                    validateFormForSubmit();
                });

                // Also trigger availability check on check-out date change
                checkOutInput.addEventListener('input', function() {
                    updateSummary();
                    validateFormForSubmit();
                });
            }

            // Add guest count change listener - occupancy is auto-determined
            if (guestsInput) {
                guestsInput.addEventListener('change', function() {
                    checkGuestCapacity();
                    enforceChildGuestRules();
                    updatePriceBasedOnGuestCount();
                    updateSummary();
                    validateFormForSubmit();
                    // Scroll to booking type section (right column of form-sections-row)
                    const bookingTypeSection = document.querySelector('.booking-type-selection')?.closest('.form-section');
                    scrollToSection(bookingTypeSection, 350);
                });
            }

            // Add child guests input listener
            if (childGuestsInput) {
                childGuestsInput.addEventListener('input', function() {
                    enforceChildGuestRules();
                    checkGuestCapacity();
                    updateSummary();
                    validateFormForSubmit();
                });
            }
        });

        // Availability state tracking
        let availabilityCheckPending = false;
        let availabilityCheckTimer = null;
        let roomAvailabilityStatus = {}; // Track availability status per room

        // Initialize immediate availability validation
        function initAvailabilityValidation() {
            const checkInInput = document.getElementById('check_in_date');
            const checkOutInput = document.getElementById('check_out_date');
            const guestsInput = document.getElementById('number_of_guests');
            const childInput = document.getElementById('child_guests');
            const bookingForm = document.getElementById('bookingForm');

            // Trigger availability check immediately when both dates are entered
            const triggerImmediateAvailabilityCheck = () => {
                const checkIn = checkInInput ? checkInInput.value : '';
                const checkOut = checkOutInput ? checkOutInput.value : '';

                // If both dates are entered, trigger availability check immediately
                if (checkIn && checkOut) {
                    clearTimeout(availabilityCheckTimer);
                    availabilityCheckTimer = setTimeout(() => {
                        performAvailabilityCheck();
                    }, 100); // Minimal delay to ensure UI updates first
                }
            };

            // Debounced availability check on date/guest changes
            const scheduleAvailabilityCheck = () => {
                clearTimeout(availabilityCheckTimer);
                availabilityCheckTimer = setTimeout(() => {
                    performAvailabilityCheck();
                }, 300); // Reduced from 500ms to 300ms for faster response
            };

            // Add event listeners for immediate validation
            // Use 'input' event for immediate response when user selects dates
            if (checkInInput) {
                checkInInput.addEventListener('input', triggerImmediateAvailabilityCheck);
                checkInInput.addEventListener('change', scheduleAvailabilityCheck);
            }
            if (checkOutInput) {
                checkOutInput.addEventListener('input', triggerImmediateAvailabilityCheck);
                checkOutInput.addEventListener('change', scheduleAvailabilityCheck);
            }
            if (guestsInput) {
                guestsInput.addEventListener('change', scheduleAvailabilityCheck);
            }
            if (childInput) {
                childInput.addEventListener('change', scheduleAvailabilityCheck);
            }

            // Prevent form submission if availability check is pending or failed
            if (bookingForm) {
                bookingForm.addEventListener('submit', function(e) {
                    if (availabilityCheckPending) {
                        e.preventDefault();
                        showAvailabilityMessage('<i class="fas fa-spinner fa-spin"></i> Checking availability... Please wait.', 'warning');
                        return false;
                    }

                    if (selectedRoomId) {
                        const checkIn = checkInInput ? checkInInput.value : '';
                        const checkOut = checkOutInput ? checkOutInput.value : '';
                        const childGuests = childInput ? parseInt(childInput.value || '0', 10) : 0;
                        const totalGuests = parseInt(guestsInput?.value || '1', 10);

                        if (checkIn && checkOut) {
                            const statusKey = buildAvailabilityStatusKey(selectedRoomId, checkIn, checkOut, childGuests, totalGuests);
                            const roomStatus = roomAvailabilityStatus[statusKey];

                            if (roomStatus && !roomStatus.available) {
                                e.preventDefault();

                                // Clean up any raw HTML that might be in the error message
                                let reasonText = roomStatus.error || "This room is fully booked for your selected dates.";
                                // Strip HTML tags
                                reasonText = reasonText.replace(/<\/?[^>]+(>|$)/g, "");

                                showAvailabilityModal(`<strong>Room Unavailable:</strong><br>${reasonText}`);
                                return false;
                            }
                        }
                    }
                });
            }
        }

        // Perform availability check for all rooms or selected room
        function performAvailabilityCheck() {
            const checkIn = document.getElementById('check_in_date').value;
            const checkOut = document.getElementById('check_out_date').value;
            const guestsInput = document.getElementById('number_of_guests');
            const numGuests = guestsInput ? parseInt(guestsInput.value || '0', 10) : 0;
            const childInput = document.getElementById('child_guests');
            const childGuests = childInput ? parseInt(childInput.value || '0', 10) : 0;

            // Clear previous availability status if dates are incomplete
            if (!checkIn || !checkOut) {
                clearAvailabilityMessage();
                enableAllRoomOptions();
                return;
            }

            // Validate date range
            const checkInDate = new Date(checkIn);
            const checkOutDate = new Date(checkOut);
            if (checkOutDate <= checkInDate) {
                showAvailabilityMessage('<i class="fas fa-exclamation-triangle"></i> Check-out date must be after check-in date.', 'error');
                return;
            }

            availabilityCheckPending = true;

            const roomOptions = document.querySelectorAll('.room-option[data-room-id]');
            const roomIds = Array.from(roomOptions)
                .map(roomOption => parseInt(roomOption.getAttribute('data-room-id'), 10))
                .filter(roomId => Number.isInteger(roomId));

            if (!roomIds.length) {
                availabilityCheckPending = false;
                validateFormForSubmit();
                return;
            }

            const totalGuests = Math.max(1, numGuests || 1);
            const adultGuests = Math.max(1, totalGuests - childGuests);
            const params = new URLSearchParams({
                room_ids: roomIds.join(','),
                check_in: checkIn,
                check_out: checkOut,
                child_guests: String(childGuests),
                adult_guests: String(adultGuests),
                number_of_guests: String(totalGuests)
            });

            fetch(`check-availability.php?${params.toString()}`)
                .then(response => response.json())
                .then(payload => {
                    const results = payload.rooms || {};
                    let availableCount = 0;

                    roomOptions.forEach(roomOption => {
                        const roomId = parseInt(roomOption.getAttribute('data-room-id'), 10);
                        const result = results[String(roomId)] || results[roomId] || {
                            available: false,
                            message: 'Unable to check availability'
                        };
                        const statusKey = buildAvailabilityStatusKey(roomId, checkIn, checkOut, childGuests, totalGuests);
                        result.status_key = statusKey;
                        roomAvailabilityStatus[statusKey] = result;

                        updateRoomAvailabilityCount(roomOption, result, childGuests);

                        if (result.available) {
                            availableCount++;
                            enableRoomOption(roomOption);

                            if (roomId === selectedRoomId) {
                                currentAvailabilityResult = result;
                                const nights = Number(result.nights || 0);
                                applyDynamicPricingState(result.split_pricing?.rate_plan || result.dynamic_pricing || null);
                                renderPackages(result.packages || [], nights, adultGuests);
                                updateSummary();
                            }
                        } else {
                            disableRoomOption(roomOption, result.message || result.error || 'Unavailable');

                            const shouldCacheAsBooked = Number(result.remaining_rooms || 0) <= 0 &&
                                result.children_required !== true &&
                                !(result.split_pricing && result.split_pricing.valid === false);

                            if (shouldCacheAsBooked) {
                                const roomKey = String(roomId);
                                if (!bookedDatesByRoom[roomKey]) {
                                    bookedDatesByRoom[roomKey] = [];
                                }

                                const dateRange = getDateRange(checkIn, checkOut);
                                dateRange.forEach(date => {
                                    if (!bookedDatesByRoom[roomKey].includes(date)) {
                                        bookedDatesByRoom[roomKey].push(date);
                                    }
                                });
                            }

                            if (shouldCacheAsBooked && (selectedRoomId === roomId || selectedRoomId === null)) {
                                applyBlockedDatesToCalendars(selectedRoomId);
                            }

                            if (selectedRoomId === roomId) {
                                currentAvailabilityResult = result;
                                applyDynamicPricingState(null);
                                showAvailabilityModal(`<strong>Room Unavailable:</strong><br>${result.message || 'This room cannot accommodate the selected stay.'}`);
                            }
                        }
                    });

                    availabilityCheckPending = false;

                    if (availableCount === 0) {
                        showAvailabilityMessage(
                            '<div style="line-height: 1.8;">' +
                            '<i class="fas fa-calendar-times"></i> ' +
                            '<strong>Sorry, all rooms are fully booked or cannot fit your group for the selected dates.</strong><br>' +
                            '<div style="margin-top: 10px; padding: 10px; background: rgba(255, 193, 7, 0.15); border-radius: 4px; border-left: 3px solid #ffc107;">' +
                            '<i class="fas fa-lightbulb" style="color: #ffc107; margin-right: 6px;"></i>' +
                            '<strong>Tip:</strong> Try adjusting your dates, guest count, or room type.' +
                            '</div></div>',
                            'error'
                        );
                    } else if (availableCount < roomOptions.length) {
                        const unavailableCount = roomOptions.length - availableCount;
                        showAvailabilityMessage(
                            '<div style="line-height: 1.8;">' +
                            `<i class="fas fa-info-circle"></i> ` +
                            `<strong>${unavailableCount} room type${unavailableCount > 1 ? 's are' : ' is'} unavailable</strong> for your selected dates or guest count.<br>` +
                            '<div style="margin-top: 8px; padding: 8px; background: rgba(255, 193, 7, 0.1); border-radius: 4px; border-left: 3px solid #ffc107;">' +
                            '<i class="fas fa-lightbulb" style="color: #ffc107; margin-right: 6px;"></i>' +
                            '<strong>Tip:</strong> Select from the available rooms highlighted above or try different dates.' +
                            '</div></div>',
                            'warning'
                        );
                    } else {
                        clearAvailabilityMessage();
                    }

                    validateFormForSubmit();
                })
                .catch(() => {
                    availabilityCheckPending = false;
                    showAvailabilityMessage('<i class="fas fa-exclamation-triangle"></i> Unable to check availability. Please try again.', 'error');
                    validateFormForSubmit();
                });
        }

        function updateRoomAvailabilityCount(roomOption, result, childGuests) {
            const countEl = roomOption.querySelector('.room-availability-count');
            if (!countEl) return;

            if (!result) {
                countEl.textContent = countEl.dataset.defaultText || '';
                return;
            }

            const remaining = childGuests > 0 ?
                Math.max(0, Number(result.child_eligible_remaining_rooms ?? result.remaining_rooms ?? 0)) :
                Math.max(0, Number(result.remaining_rooms || 0));
            const label = childGuests > 0 ? 'child-ready room' : 'room';
            countEl.textContent = `(${remaining} ${label}${remaining === 1 ? '' : 's'} left)`;
        }

        /**
         * Visually mark rooms that do not allow children when the guest has entered
         * children. Adds/removes the `room-option--children-warn` modifier class so
         * CSS can apply a distinct visual treatment without disabling the card.
         */
        function refreshChildFriendlyRoomIndicators(childCount) {
            const roomOptions = document.querySelectorAll('.room-option[data-children-allowed]');
            roomOptions.forEach(option => {
                const allows = option.getAttribute('data-children-allowed') === '1';
                if (childCount > 0 && !allows) {
                    option.classList.add('room-option--children-warn');
                } else {
                    option.classList.remove('room-option--children-warn');
                }
            });
        }

        // Disable a room option with visual feedback (composes with filter tabs)
        function disableRoomOption(roomOption, reason) {
            roomOption.classList.add('room-option-disabled');
            const radio = roomOption.querySelector('input[type="radio"]');
            if (radio) {
                radio.disabled = true;
            }

            // Add unavailable badge
            let badge = roomOption.querySelector('.unavailable-badge');
            if (!badge) {
                badge = document.createElement('div');
                badge.className = 'unavailable-badge';
                roomOption.appendChild(badge);
            }
            badge.innerHTML = '<i class="fas fa-ban"></i> Unavailable';
            badge.title = reason;
        }

        // Enable a room option (composes with filter tabs)
        function enableRoomOption(roomOption) {
            roomOption.classList.remove('room-option-disabled');
            const radio = roomOption.querySelector('input[type="radio"]');
            if (radio) {
                radio.disabled = false;
            }

            // Remove unavailable badge
            const badge = roomOption.querySelector('.unavailable-badge');
            if (badge) {
                badge.remove();
            }

            // Restore opacity - filter controls visibility, this controls availability state
            roomOption.style.opacity = '';
            roomOption.style.pointerEvents = '';
        }

        // Enable all room options (respects current filter state)
        function enableAllRoomOptions() {
            const roomOptions = document.querySelectorAll('.room-option');
            roomOptions.forEach(option => {
                enableRoomOption(option);
                const countEl = option.querySelector('.room-availability-count');
                if (countEl) {
                    countEl.textContent = countEl.dataset.defaultText || '';
                }
            });
        }

        // Clear room selection
        function clearRoomSelection() {
            selectedRoomId = null;
            selectedRoomName = null;
            selectedRoomPrice = null;
            selectedRoomMaxGuests = null;
            currentDynamicPricing = null;
            currentPackages = [];
            selectedPackageIds.clear();
            applyDynamicPricingState(null);
            const pkgSection = document.getElementById('packagesSection');
            if (pkgSection) pkgSection.style.display = 'none';

            const roomRadios = document.querySelectorAll('input[name="room_id"]');
            roomRadios.forEach(radio => {
                radio.checked = false;
            });

            const roomOptions = document.querySelectorAll('.room-option');
            roomOptions.forEach(option => {
                option.classList.remove('selected');
            });

            // Clear summary
            const summary = document.getElementById('bookingSummary');
            if (summary) {
                summary.style.display = 'none';
            }

            // Disable submit button
            const submitBtn = document.querySelector('.btn-submit');
            if (submitBtn) {
                submitBtn.disabled = true;
                submitBtn.innerHTML = '<i class="fas fa-bed"></i> Select a Room';
                submitBtn.style.opacity = '0.6';
            }
        }

        // Show availability message
        function showAvailabilityMessage(message, type) {
            const el = document.getElementById('roomAvailabilityMessage');
            if (!el) return;

            const icons = {
                error: 'fa-circle-exclamation',
                warning: 'fa-triangle-exclamation',
                success: 'fa-circle-check'
            };
            const t = ['error', 'warning', 'success'].includes(type) ? type : 'success';
            el.innerHTML =
                '<span class="availability-message__icon"><i class="fas ' + icons[t] + '"></i></span>' +
                '<span>' + message + '</span>';
            el.className = 'availability-message availability-message--' + t;
            el.style.display = 'flex';
        }

        // Clear availability message
        function clearAvailabilityMessage() {
            const el = document.getElementById('roomAvailabilityMessage');
            if (el) {
                el.style.display = 'none';
                el.innerHTML = '';
                el.className = 'availability-message';
            }
        }

        // ── Guest info completion → scroll to Guest Details step 4 ──────
        var _guestScrollFired = false;
        function checkGuestInfoComplete() {
            const n = document.getElementById('guest_name');
            const e = document.getElementById('guest_email');
            const p = document.getElementById('guest_phone');
            const allValid = n && e && p
                && n.classList.contains('is-valid')
                && e.classList.contains('is-valid')
                && p.classList.contains('is-valid');

            if (allValid && !_guestScrollFired) {
                _guestScrollFired = true;
                // Advance to step 4 (Guest Details) which validates steps 1-3 first
                advanceToStep(4);
            } else if (!allValid) {
                _guestScrollFired = false;
            }
        }

        // Instant field validation for better UX
        function initInstantValidation() {
            const nameInput = document.getElementById('guest_name');
            const emailInput = document.getElementById('guest_email');
            const phoneInput = document.getElementById('guest_phone');

            // Name validation - at least 2 characters, letters/spaces/hyphens only
            if (nameInput) {
                nameInput.addEventListener('input', function() {
                    validateNameField(this);
                    checkGuestInfoComplete();
                });
                nameInput.addEventListener('blur', function() {
                    validateNameField(this);
                    checkGuestInfoComplete();
                });
            }

            // Email validation
            if (emailInput) {
                emailInput.addEventListener('input', function() {
                    validateEmailField(this);
                    checkGuestInfoComplete();
                });
                emailInput.addEventListener('blur', function() {
                    validateEmailField(this);
                    checkGuestInfoComplete();
                });
            }

            // Phone validation
            if (phoneInput) {
                phoneInput.addEventListener('input', function() {
                    validatePhoneField(this);
                    checkGuestInfoComplete();
                });
                phoneInput.addEventListener('blur', function() {
                    validatePhoneField(this);
                    checkGuestInfoComplete();
                });
            }
        }

        function validateNameField(input) {
            const value = input.value.trim();
            const feedback = getOrCreateFeedback(input, 'name-feedback');

            // Remove previous state
            input.classList.remove('is-valid', 'is-invalid');

            if (value === '') {
                feedback.textContent = '';
                feedback.className = 'field-feedback';
                return false;
            }

            // Check minimum length
            if (value.length < 2) {
                input.classList.add('is-invalid');
                feedback.textContent = 'Name must be at least 2 characters';
                feedback.className = 'field-feedback text-danger';
                return false;
            }

            // Check for valid characters (letters, spaces, hyphens, apostrophes, periods)
            const namePattern = /^[a-zA-Z\s\-'.\u00C0-\u017F\u0400-\u04FF]+$/;
            if (!namePattern.test(value)) {
                input.classList.add('is-invalid');
                feedback.textContent = 'Name can only contain letters, spaces, hyphens, and apostrophes';
                feedback.className = 'field-feedback text-danger';
                return false;
            }

            // Valid
            input.classList.add('is-valid');
            feedback.textContent = '✓ Looks good';
            feedback.className = 'field-feedback text-success';
            return true;
        }

        function validateEmailField(input) {
            const value = input.value.trim();
            const feedback = getOrCreateFeedback(input, 'email-feedback');

            // Remove previous state
            input.classList.remove('is-valid', 'is-invalid');

            if (value === '') {
                feedback.textContent = '';
                feedback.className = 'field-feedback';
                return false;
            }

            // Comprehensive email regex
            const emailPattern = /^[a-zA-Z0-9.!#$%&'*+/=?^_`{|}~-]+@[a-zA-Z0-9](?:[a-zA-Z0-9-]{0,61}[a-zA-Z0-9])?(?:\.[a-zA-Z0-9](?:[a-zA-Z0-9-]{0,61}[a-zA-Z0-9])?)*$/;

            if (!emailPattern.test(value)) {
                input.classList.add('is-invalid');
                feedback.textContent = 'Please enter a valid email address';
                feedback.className = 'field-feedback text-danger';
                return false;
            }

            // Check for common typos
            const commonDomains = ['gmail.com', 'yahoo.com', 'hotmail.com', 'outlook.com', 'icloud.com', 'aol.com'];
            const domain = value.split('@')[1]?.toLowerCase();
            const suggestions = {
                'gmial.com': 'gmail.com',
                'gmal.com': 'gmail.com',
                'gmal.com': 'gmail.com',
                'gmali.com': 'gmail.com',
                'yaho.com': 'yahoo.com',
                'yahooo.com': 'yahoo.com',
                'hotmal.com': 'hotmail.com',
                'hotmil.com': 'hotmail.com',
                'outlok.com': 'outlook.com',
                'iclod.com': 'icloud.com',
                'icluod.com': 'icloud.com'
            };

            if (suggestions[domain]) {
                input.classList.add('is-invalid');
                feedback.innerHTML = `Did you mean <strong>${value.split('@')[0]}@${suggestions[domain]}</strong>?`;
                feedback.className = 'field-feedback text-warning';
                return false;
            }

            // Valid
            input.classList.add('is-valid');
            feedback.textContent = '✓ Valid email';
            feedback.className = 'field-feedback text-success';
            return true;
        }

        function validatePhoneField(input) {
            const value = input.value.trim();
            const feedback = getOrCreateFeedback(input, 'phone-feedback');

            // Remove previous state
            input.classList.remove('is-valid', 'is-invalid');

            if (value === '') {
                feedback.textContent = '';
                feedback.className = 'field-feedback';
                return false;
            }

            // Remove all non-digit and non-plus characters for validation
            const cleanNumber = value.replace(/[\s\-\(\)\.]/g, '');

            // Check for valid phone format
            // Allows: +265999123456, 265999123456, 0999123456, +1-234-567-8900
            const phonePattern = /^\+?[0-9]{8,15}$/;

            if (!phonePattern.test(cleanNumber)) {
                input.classList.add('is-invalid');
                if (cleanNumber.length < 8) {
                    feedback.textContent = 'Phone number is too short (min 8 digits)';
                } else if (cleanNumber.length > 15) {
                    feedback.textContent = 'Phone number is too long (max 15 digits)';
                } else {
                    feedback.textContent = 'Please enter a valid phone number';
                }
                feedback.className = 'field-feedback text-danger';
                return false;
            }

            // Check for obviously invalid patterns
            if (/^0+$/.test(cleanNumber) || /^1+$/.test(cleanNumber) || /^(\d)\1+$/.test(cleanNumber.replace('+', ''))) {
                input.classList.add('is-invalid');
                feedback.textContent = 'Please enter a real phone number';
                feedback.className = 'field-feedback text-danger';
                return false;
            }

            // Valid - format display
            input.classList.add('is-valid');
            feedback.textContent = '✓ Valid phone number';
            feedback.className = 'field-feedback text-success';
            return true;
        }

        function getOrCreateFeedback(input, id) {
            let feedback = document.getElementById(id);
            if (!feedback) {
                feedback = document.createElement('small');
                feedback.id = id;
                feedback.className = 'field-feedback';
                input.parentNode.appendChild(feedback);
            }
            return feedback;
        }

        // Override validateFormForSubmit to include instant validation and availability check
        const originalValidateFormForSubmit = validateFormForSubmit;
        validateFormForSubmit = function() {
            const checkIn = document.getElementById('check_in_date').value;
            const checkOut = document.getElementById('check_out_date').value;
            const numGuests = document.getElementById('number_of_guests').value;
            const childGuests = parseInt(document.getElementById('child_guests').value || '0', 10);
            const submitBtn = document.querySelector('.btn-submit');

            const totalGuestsInt = parseInt(numGuests || '0', 10);
            const adultsInt = totalGuestsInt - childGuests;
            const childValid = childGuests >= 0 && childGuests < totalGuestsInt;

            // Check availability status for selected room
            let availabilityValid = true;
            let availabilityKnown = true;
            if (selectedRoomId && checkIn && checkOut) {
                const statusKey = buildAvailabilityStatusKey(selectedRoomId, checkIn, checkOut, childGuests, totalGuestsInt);
                const roomStatus = roomAvailabilityStatus[statusKey];
                availabilityKnown = !!roomStatus;
                if (roomStatus && !roomStatus.available) {
                    availabilityValid = false;
                }
            }

            const selectedRoom = getSelectedRoomData();
            const allocationValid = selectedRoom ? hasValidAllocation(totalGuestsInt, selectedRoom, childGuests) : false;

            // Determine button state based on all validations — guide guest step by step
            let btnText = '<i class="fas fa-calendar-check"></i> Complete All Fields';
            let btnDisabled = true;

            if (!checkIn || !checkOut) {
                btnText = '<i class="fas fa-calendar-alt"></i> Select Your Dates First';
                btnDisabled = true;
            } else if (!selectedRoomId) {
                btnText = '<i class="fas fa-bed"></i> Select a Room to Continue';
                btnDisabled = true;
            } else if (availabilityCheckPending || !availabilityKnown) {
                btnText = '<i class="fas fa-spinner fa-spin"></i> Checking Availability…';
                btnDisabled = true;
            } else if (!availabilityValid) {
                btnText = '<i class="fas fa-calendar-times"></i> Room Fully Booked — Try Different Dates';
                btnDisabled = true;
            } else if (!numGuests) {
                btnText = '<i class="fas fa-users"></i> Select Number of Guests';
                btnDisabled = true;
            } else if (!childValid || adultsInt < 1) {
                btnText = '<i class="fas fa-exclamation-triangle"></i> At Least 1 Adult Required';
                btnDisabled = true;
            } else if (!allocationValid) {
                btnText = '<i class="fas fa-exclamation-triangle"></i> Guest Count Not Supported for This Room';
                btnDisabled = true;
            } else {
                // All required fields are filled — browser validation handles contact fields on submit
                btnText = '<i class="fas fa-check-circle"></i> Confirm Booking';
                btnDisabled = false;
            }

            submitBtn.disabled = btnDisabled;
            submitBtn.innerHTML = btnText;
            submitBtn.style.opacity = btnDisabled ? '0.6' : '1';
        };

        // ── Sidebar empty state toggle ─────────────────────────────────────────
        // Watch the summary div for JS-driven display changes and toggle the
        // empty state placeholder accordingly. Also trigger fade-in animation.
        (function () {
            const summaryEl   = document.getElementById('bookingSummary');
            const emptyEl     = document.getElementById('bookingSidebarEmpty');
            if (!summaryEl || !emptyEl) return;

            function syncState() {
                const visible = summaryEl.style.display === 'block';
                emptyEl.style.display  = visible ? 'none' : 'block';
                if (visible) {
                    summaryEl.classList.remove('is-visible');
                    // Trigger reflow so the animation restarts cleanly
                    void summaryEl.offsetWidth;
                    summaryEl.classList.add('is-visible');
                } else {
                    summaryEl.classList.remove('is-visible');
                }
            }

            new MutationObserver(syncState)
                .observe(summaryEl, { attributes: true, attributeFilter: ['style'] });

            // Run once on load in case JS already set display before observer attaches
            syncState();
        })();
