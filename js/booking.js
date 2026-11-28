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

        function ymdLocal(d) {
            return d.getFullYear() + '-' + String(d.getMonth() + 1).padStart(2, '0') + '-' + String(d.getDate()).padStart(2, '0');
        }

        // The night a guest sleeps before checking out on dateObj (blocked/booked dates are NIGHTS).
        function lastNightBefore(dateObj) {
            return ymdLocal(new Date(dateObj.getFullYear(), dateObj.getMonth(), dateObj.getDate() - 1));
        }

        // Check-out is fine ON a blocked date (that night is not slept). A check-out date is closed when the
        // night before it is unavailable, or, once a check-in is picked, when the stay would cross one.
        function isCheckOutClosed(roomId, dateObj) {
            const unavailable = new Set(getAllUnavailableDatesForRoom(roomId));
            if (!unavailable.size) return false;
            if (unavailable.has(lastNightBefore(dateObj))) return true;
            const ci = checkInCalendar && checkInCalendar.selectedDates[0];
            if (ci) {
                const last = new Date(dateObj.getFullYear(), dateObj.getMonth(), dateObj.getDate() - 1);
                for (let n = new Date(ci.getFullYear(), ci.getMonth(), ci.getDate()); n < last; n.setDate(n.getDate() + 1)) {
                    if (unavailable.has(ymdLocal(n))) return true;
                }
            }
            return false;
        }

        function checkOutDisableRule(roomId) {
            return [function(dateObj) { return isCheckOutClosed(roomId, dateObj); }];
        }

        function getSelectedRoomData() {
            return selectedRoomId ? roomsData.find(room => room.id === selectedRoomId) : null;
        }

        // ── Guest allocation: exact mirror of includes/guest-allocation.php ─────────
        // Rooms needed = ceil(guests / capacity); greedy fill; 1+ adult per room;
        // children refused where the type is adults-only; the occupancy tier is
        // picked from the guests in EACH room. check-availability.php and the booking
        // POST run the PHP twin of this and always win; this gives instant feedback.
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

        // Occupancy is a PRICE TIER (single / double / triple = "3 or more guests"),
        // not a head count, so the label spells out which guest counts it covers.
        function getOccupancyGuestRange(occupancyType, room) {
            if (occupancyType === 'single') return '1 guest';
            if (occupancyType === 'double') return '2 guests';
            if (occupancyType === 'triple') {
                const max = room ? Number(room.max_guests || 0) : 0;
                return max > 3 ? `3–${max} guests` : (max === 3 ? '3 guests' : '3+ guests');
            }
            return '';
        }

        function getOccupancyLabel(occupancyType, room) {
            const range = getOccupancyGuestRange(occupancyType, room);
            if (!range) return 'Standard';
            const name = occupancyType === 'triple' && room && Number(room.max_guests || 0) > 3 ?
                'Triple / family' : occupancyType.charAt(0).toUpperCase() + occupancyType.slice(1);
            return `${name} (${range})`;
        }

        // Short tier name for places that already print the guest count next to it.
        function getOccupancyTierName(occupancyType) {
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

        function plural(n, word) {
            return n + ' ' + word + (n === 1 ? '' : 's');
        }

        function planFailure(reason, roomsNeeded, message) {
            return { valid: false, reason: reason, roomsNeeded: roomsNeeded, allocation: [], message: message, summary: '' };
        }

        // Child charge rule of a room type (same fields the server uses: includes/pricing.php rh_child_rule).
        function getChildRule(room) {
            const r = room && room.child_rule ? room.child_rule : null;
            const percent = r ? Number(r.percent) : Number(room && room.child_price_multiplier !== undefined ? room.child_price_multiplier : childPriceMultiplier);
            return {
                mode: r && r.mode === 'fixed' ? 'fixed' : 'percent',
                percent: Math.max(0, Number.isFinite(percent) ? percent : 50),
                amount: r ? Math.max(0, Number(r.amount) || 0) : 0,
                free: r ? Math.max(0, Number(r.free) || 0) : 0,
                max: r && r.max !== null && r.max !== undefined ? Math.max(0, Number(r.max)) : null
            };
        }

        // Charge for ONE night for the children in one room (the first `free` are free).
        function childNightCharge(rule, children, ratePerNight) {
            const charged = Math.max(0, children - rule.free);
            if (charged <= 0) return 0;
            const each = rule.mode === 'fixed' ? rule.amount : ratePerNight * (rule.percent / 100);
            return Math.max(0, each) * charged;
        }

        // Plain sentence for the hint under the children field.
        function childRuleText(rule) {
            let core;
            if (rule.mode === 'fixed') {
                core = rule.amount > 0 ? `${currencySymbol}${rule.amount.toLocaleString()} per child per night` : 'Children stay free';
            } else {
                core = rule.percent > 0 ? `Children stay at ${rule.percent}% of the room rate` : 'Children stay free';
            }
            if (rule.free > 0) core += `, the first ${rule.free} free in each room`;
            if (rule.max !== null) core += `, up to ${rule.max} per room`;
            return core + '.';
        }

        function getPartyPlan(totalGuests, childGuests, room) {
            const total = Math.max(0, Number(totalGuests || 0));
            if (!room || total < 1) {
                return planFailure('invalid_party', 0, 'Select a room and guest count first.');
            }
            const maxPerRoom = Math.max(1, Number(room.max_guests || 1));
            const roomsNeeded = Math.max(1, Math.ceil(total / maxPerRoom));
            const children = Math.max(0, Number(childGuests === null || childGuests === undefined ? getCurrentChildGuestCount() : childGuests) || 0);
            const adults = total - children;
            const name = room.name || 'this room type';

            if (adults < 1) return planFailure('invalid_party', roomsNeeded, 'At least 1 adult is required for every booking.');
            if (children > 0 && Number(room.children_allowed || 0) !== 1) {
                return planFailure('children_not_allowed', roomsNeeded, `${name} is adults only. Children cannot stay in this room type.`);
            }
            const maxChildrenPerRoom = children > 0 ? getChildRule(room).max : null;
            if (adults < roomsNeeded) {
                return planFailure('adults_per_room', roomsNeeded, `${total} guests need ${roomsNeeded} ${name} rooms (up to ${maxPerRoom} guests each) and every room needs at least 1 adult. Add adults or reduce the number of children.`);
            }

            const allocation = [];
            let remainingGuests = total;
            let remainingAdults = adults;
            let remainingChildren = children;

            for (let index = 0; index < roomsNeeded; index++) {
                const laterRooms = Math.max(0, roomsNeeded - index - 1);
                const guestsThisRoom = Math.min(maxPerRoom, Math.max(1, remainingGuests - laterRooms));
                const adultsAvailableThisRoom = remainingAdults - laterRooms;
                if (adultsAvailableThisRoom < 1) return planFailure('adults_per_room', roomsNeeded, 'At least one adult is required in each room.');

                let childrenThisRoom = Math.min(remainingChildren, Math.max(0, guestsThisRoom - 1));
                if (maxChildrenPerRoom !== null) childrenThisRoom = Math.min(childrenThisRoom, maxChildrenPerRoom);
                let adultsThisRoom = guestsThisRoom - childrenThisRoom;
                if (adultsThisRoom > adultsAvailableThisRoom) {
                    adultsThisRoom = adultsAvailableThisRoom;
                    childrenThisRoom = guestsThisRoom - adultsThisRoom;
                }
                if (childrenThisRoom > remainingChildren) {
                    childrenThisRoom = remainingChildren;
                    adultsThisRoom = guestsThisRoom - childrenThisRoom;
                }
                if (adultsThisRoom < 1 || childrenThisRoom < 0) {
                    return planFailure('adults_per_room', roomsNeeded, 'Unable to allocate guests while keeping at least one adult in each room.');
                }
                if (maxChildrenPerRoom !== null && childrenThisRoom > maxChildrenPerRoom) {
                    return planFailure('children_max', roomsNeeded, `${name} takes at most ${plural(maxChildrenPerRoom, 'child').replace('childs', 'children')} per room. Add adults, reduce the children or book another room type.`);
                }

                const occupancyType = pickOccupancyForGuestCount(guestsThisRoom, room);
                if (occupancyType === null) {
                    const tier = guestsThisRoom === 1 ? 'single' : (guestsThisRoom === 2 ? 'double' : 'triple');
                    return planFailure('no_pricing', roomsNeeded, `${name} has no ${tier} rate enabled, so a room with ${plural(guestsThisRoom, 'guest')} cannot be booked.`);
                }

                allocation.push({
                    guests: guestsThisRoom,
                    adults: adultsThisRoom,
                    children: childrenThisRoom,
                    occupancyType: occupancyType
                });
                remainingGuests -= guestsThisRoom;
                remainingAdults -= adultsThisRoom;
                remainingChildren -= childrenThisRoom;
            }

            if (maxChildrenPerRoom !== null && remainingChildren > 0) {
                return planFailure('children_max', roomsNeeded, `${name} takes at most ${plural(maxChildrenPerRoom, 'child').replace('childs', 'children')} per room. Add adults, reduce the children or book another room type.`);
            }
            if (remainingGuests !== 0 || remainingAdults !== 0 || remainingChildren !== 0) {
                return planFailure('capacity', roomsNeeded, 'Unable to allocate the full guest list across the required rooms.');
            }

            const counts = allocation.map(part => part.guests);
            const summary = roomsNeeded === 1
                ? `${plural(total, 'guest')}: 1 × ${name} (${plural(counts[0], 'guest')})`
                : `${plural(total, 'guest')}: ${roomsNeeded} × ${name} (${counts.join(' + ')} guests)`;
            return { valid: true, reason: '', roomsNeeded: roomsNeeded, allocation: allocation, message: '', summary: summary };
        }

        function getGuestAllocation(totalGuests, room, childGuests = null) {
            const plan = getPartyPlan(totalGuests, childGuests, room);
            return plan.valid ? plan.allocation : [];
        }

        function getRoomsNeededForGuests(totalGuests, room) {
            if (!room || totalGuests < 1) return 0;
            return Math.ceil(totalGuests / Math.max(1, Number(room.max_guests || 1)));
        }

        function getAllocationValidationMessage(totalGuests, room, childGuests = null) {
            const plan = getPartyPlan(totalGuests, childGuests, room);
            return plan.valid ? '' : plan.message;
        }

        function hasValidAllocation(totalGuests, room, childGuests = null) {
            return getPartyPlan(totalGuests, childGuests, room).valid;
        }

        // Rooms of each type free for every night of the chosen stay, learned from
        // check-availability.php (missing key = not yet known).
        const roomsFreeByType = {};

        // ── Rooms of different types for one party ───────────────────────────────────────────────────
        // When the chosen room type cannot seat everyone (too few of its rooms are free), the guest adds rooms of other
        // types for the rest. The seating, the choices and the prices all come from check-availability.php?mode=mixed
        // (ga_plan_mixed in includes/guest-allocation.php); this code only shows them and posts extra_room_types[].
        let extraRoomTypes = (typeof postedBooking !== 'undefined' && postedBooking && Array.isArray(postedBooking.extra_room_types)) ?
            postedBooking.extra_room_types.map(Number).filter(n => n > 0) : [];
        let mixedResult = null;
        let mixedSeq = 0;

        function mixedKey() {
            return [selectedRoomId,
                document.getElementById('check_in_date')?.value || '',
                document.getElementById('check_out_date')?.value || '',
                document.getElementById('number_of_guests')?.value || '',
                getCurrentChildGuestCount(),
                extraRoomTypes.join(',')
            ].join('|');
        }

        // The batch answer for a room type: the party is fine for it but too few of its rooms are free.
        // Also: the type is adults only and children are coming, so the children go into an added room that takes them.
        function isMixedCandidate(result) {
            if (!result || result.available !== false || Number(result.rooms_free || 0) < 1) return false;
            if (result.reason === 'children_not_allowed') {
                const adults = parseInt(document.getElementById('number_of_guests')?.value || '0', 10) - getCurrentChildGuestCount();
                return getCurrentChildGuestCount() > 0 && adults >= 1;
            }
            return result.reason === 'not_enough_rooms' && !!result.split_pricing && result.split_pricing.valid === true;
        }

        function selectedIsMixedCandidate() {
            return isMixedCandidate(getServerResultForCurrentParty());
        }

        function mixedInPlay() {
            return selectedIsMixedCandidate() || extraRoomTypes.length > 0;
        }

        // The server's seating for exactly what is on screen now (null while it is still loading or out of date).
        function getMixedForCurrent() {
            return mixedResult && mixedResult.key === mixedKey() && mixedResult.needed ? mixedResult : null;
        }

        function syncExtraInputs() {
            const holder = document.getElementById('extraRoomInputs');
            if (!holder) return;
            holder.innerHTML = extraRoomTypes.map(id => `<input type="hidden" name="extra_room_types[]" value="${Number(id)}">`).join('');
        }

        function clearMixed() {
            mixedSeq++;
            mixedResult = null;
            if (extraRoomTypes.length) {
                extraRoomTypes = [];
                syncExtraInputs();
            }
            renderMixedPanel();
        }

        function loadMixed() {
            const checkIn = document.getElementById('check_in_date')?.value;
            const checkOut = document.getElementById('check_out_date')?.value;
            const total = parseInt(document.getElementById('number_of_guests')?.value || '0', 10);
            const children = getCurrentChildGuestCount();
            if (!selectedRoomId || !checkIn || !checkOut || total < 1) {
                clearMixed();
                return;
            }
            const key = mixedKey();
            const seq = ++mixedSeq;
            const params = new URLSearchParams({
                mode: 'mixed',
                room_id: String(selectedRoomId),
                check_in: checkIn,
                check_out: checkOut,
                number_of_guests: String(total),
                child_guests: String(children),
                extra_room_types: extraRoomTypes.join(',')
            });
            const done = data => {
                if (seq !== mixedSeq) return;
                data.key = key;
                if (data.success === true && !data.needed) {
                    // The chosen room type can seat everyone after all (dates or guests changed): back to the normal flow.
                    mixedResult = null;
                    if (extraRoomTypes.length) {
                        extraRoomTypes = [];
                        syncExtraInputs();
                    }
                } else {
                    mixedResult = data.success === true ? data : {
                        key: key,
                        needed: true,
                        ok: false,
                        message: data.message || 'Unable to check the rooms. Please try again.',
                        rows: [],
                        options: [],
                        left_guests: total,
                        chosen: {}
                    };
                    if (mixedResult.ok) {
                        applyDynamicPricingState(mixedResult.rate_plan || mixedResult.dynamic_pricing || null);
                        renderPackages(mixedResult.packages || [], Number(mixedResult.nights || 0), Math.max(1, total - children));
                    }
                }
                renderMixedPanel();
                renderPartyPlan();
                updateSummary();
                validateFormForSubmit();
            };
            fetch(`check-availability.php?${params.toString()}`)
                .then(response => response.json())
                .then(done)
                .catch(() => done({ success: false, message: 'Unable to check the rooms. Please try again.' }));
        }

        function getExtraRoomsPanel() {
            let panel = document.getElementById('extraRoomsPanel');
            if (!panel) {
                panel = document.createElement('div');
                panel.id = 'extraRoomsPanel';
                panel.className = 'booking-split-notice extra-rooms-panel';
                panel.setAttribute('role', 'region');
                panel.setAttribute('aria-label', 'Rooms for your party');
                panel.setAttribute('aria-live', 'polite');
                panel.addEventListener('click', event => {
                    const addBtn = event.target.closest('[data-add-room]');
                    const removeBtn = event.target.closest('[data-remove-extra]');
                    if (addBtn) {
                        extraRoomTypes.push(Number(addBtn.getAttribute('data-add-room')));
                    } else if (removeBtn) {
                        extraRoomTypes.splice(Number(removeBtn.getAttribute('data-remove-extra')), 1);
                    } else {
                        return;
                    }
                    syncExtraInputs();
                    mixedResult = null;
                    renderMixedPanel();
                    updateSummary();
                    validateFormForSubmit();
                    loadMixed();
                });
            }
            return panel;
        }

        function renderMixedPanel() {
            const panel = getExtraRoomsPanel();
            const m = getMixedForCurrent();
            if (!m && !mixedInPlay()) {
                panel.style.display = 'none';
                return;
            }
            const card = document.querySelector('.room-option.selected') || document.querySelector(`.room-option[data-room-id="${selectedRoomId}"]`);
            if (card && panel.previousElementSibling !== card) card.insertAdjacentElement('afterend', panel);
            panel.style.display = '';
            if (!m) {
                panel.innerHTML = '<p>Checking which rooms can take the rest of your party...</p>';
                return;
            }

            const left = Number(m.left_guests || 0);
            const chosen = m.chosen || {};
            const free = Number(chosen.rooms_free || 0);
            const guests = Number((m.party && m.party.guests) || 0);
            const rowHtml = (name, detail, removeIndex) => `<li class="extra-room-row"><div class="extra-room-row__text"><strong>${escapeHtml(name)}</strong><small>${detail}</small></div>` +
                (removeIndex === null ? '' : `<button type="button" class="btn btn--secondary btn--sm" data-remove-extra="${removeIndex}" aria-label="Remove ${escapeHtml(name)}">Remove</button>`) + '</li>';

            const forChildren = m.why === 'children';
            let html = left > 0 ? `<h4>${forChildren ? 'Add a room for the children' : `Add a room for the other ${plural(left, 'guest')}`}</h4>` : '<h4>Everyone has a room</h4>';
            if (forChildren) {
                html += `<p>${escapeHtml(chosen.name || 'This room type')} is adults only, so children cannot stay in it. The children need a room that takes children, with at least 1 adult. ` +
                    (left > 0 ? `The adults stay in ${escapeHtml(chosen.name || 'this room type')} first; add a room that takes children below.` : 'The children are in the room added below.') + '</p>';
            } else html += `<p>${escapeHtml(chosen.name || 'This room type')} has ${free === 1 ? 'only 1 room' : `only ${free} rooms`} free for your dates and holds up to ${plural(Number(chosen.max_guests || 0), 'guest')} each, ` +
                `but your party of ${plural(guests, 'guest')} needs ${Number(chosen.rooms_needed_alone || 0)}.${left > 0 ? ' Add a different room type for the rest.' : ''}</p>`;

            let rows = m.rows || [];
            if (!rows.length && extraRoomTypes.length) {
                rows = extraRoomTypes.map((id, i) => ({ room_name: (roomsData.find(r => r.id === id) || {}).name || 'Added room', is_extra: true, extra_index: i, guests: 0 }));
            }
            if (rows.length) {
                html += '<p class="extra-rooms-panel__label">Your rooms</p><ul>' + rows.map(row => {
                    const rate = row.rate_per_night !== undefined ? ` &middot; ${currencySymbol}${Number(row.rate_per_night).toLocaleString()}/night` : '';
                    const who = row.guests ? escapeHtml(describeRoomGuests(row)) : '';
                    return rowHtml(row.room_name, who + rate, row.is_extra ? row.extra_index : null);
                }).join('') + '</ul>';
            }
            if (m.message && !m.ok && !(forChildren && (m.options || []).length)) {
                html += `<p style="color:#b02a37;">${escapeHtml(m.message)}</p>`;
            }
            if (left > 0) {
                const options = m.options || [];
                if (options.length) {
                    html += '<p class="extra-rooms-panel__label">Rooms you can add</p><ul>' + options.map(opt => {
                        const take = forChildren ? `seats the children with ${plural(Number(opt.adults_taken || 1), 'adult')}` : (opt.takes_all ? `seats the ${plural(opt.guests_taken, 'guest')} left` : `seats ${plural(opt.guests_taken, 'guest')}`);
                        const detail = `Up to ${plural(Number(opt.max_guests), 'guest')} &middot; ${currencySymbol}${Number(opt.rate_per_night).toLocaleString()}/night &middot; ${take}`;
                        return `<li class="extra-room-row"><div class="extra-room-row__text"><strong>${escapeHtml(opt.name)}</strong><small>${detail}</small></div>` +
                            `<button type="button" class="btn btn--primary btn--sm" data-add-room="${Number(opt.room_id)}" aria-label="Add ${escapeHtml(opt.name)}">Add</button></li>`;
                    }).join('') + '</ul>';
                } else if (!m.message) {
                    html += '<p>No other room type is free for these dates and this party. Try other dates, fewer guests or a different room.</p>';
                }
            }
            panel.innerHTML = html;
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
                checkOutCalendar.set('disable', checkOutDisableRule(roomId));
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

        // ── Graceful scroll (the booking steps scroll back to the step bar) ──
        // Custom RAF easing, cubic ease-in-out; a plain jump when the guest asks for reduced motion.
        function gbReducedMotion() {
            return !!(window.matchMedia && window.matchMedia('(prefers-reduced-motion: reduce)').matches);
        }

        function scrollToSection(el, delay) {
            if (!el) return;
            setTimeout(function () {
                const headerEl = document.querySelector('header') || document.querySelector('.site-header');
                const offset   = (headerEl ? headerEl.offsetHeight : 80) + 16;
                const targetY  = Math.max(0, el.getBoundingClientRect().top + window.pageYOffset - offset);
                const startY   = window.pageYOffset;
                const dist     = targetY - startY;

                if (Math.abs(dist) < 8) return; // already there
                if (gbReducedMotion()) { window.scrollTo(0, targetY); return; }

                // Duration scales with distance; cap between 480ms – 900ms
                const duration = Math.min(900, Math.max(480, Math.abs(dist) * 0.6));
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
            }, delay === undefined ? 0 : delay);
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

                            // If check-out is before new min date, or the stay now crosses an unavailable night, clear it
                            const currentCheckOut = checkOutCalendar.selectedDates[0];
                            if (currentCheckOut && (currentCheckOut < nextDay || isCheckOutClosed(selectedRoomId, currentCheckOut))) {
                                checkOutCalendar.clear();
                            }

                            // Auto-open check-out if it hasn't been set yet
                            if (!checkOutCalendar.selectedDates.length) {
                                setTimeout(() => checkOutCalendar.open(), 180);
                            }
                        }
                    }
                    updateSummary();
                }
            });

            // Check-out calendar
            checkOutCalendar = flatpickr('#check_out_date', {
                minDate: tomorrow,
                maxDate: maxDate,
                dateFormat: 'Y-m-d',
                disable: checkOutDisableRule(selectedRoomId),
                onDayCreate: function(dObj, dStr, fp, dayElem) {
                    // Mark a check-out date by the night slept before it (checking out on a blocked date is fine)
                    const nightStr = lastNightBefore(dayElem.dateObj);
                    const roomBlockedDates = getBlockedDatesForRoom(selectedRoomId);
                    const roomBookedDates = getBookedDatesForRoom(selectedRoomId);

                    if (roomBlockedDates.includes(nightStr)) {
                        dayElem.classList.add('blocked-date');
                        dayElem.innerHTML += '<span class="blocked-indicator"></span>';
                    }
                    else if (roomBookedDates.includes(nightStr)) {
                        dayElem.classList.add('booked-date');
                        dayElem.innerHTML += '<span class="booked-indicator"></span>';
                    }
                },
                onChange: function() {
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

            // Guests from the hero widget / query: set before any room is chosen, so the first availability
            // check already asks for this party.
            if (heroGuests) {
                const guestSelect = document.getElementById('number_of_guests');
                if (guestSelect) {
                    guestSelect.value = String(Math.min(Math.max(1, heroGuests), 20));
                    if (heroChildren) {
                        const childInput = document.getElementById('child_guests');
                        if (childInput) childInput.value = String(Math.min(heroChildren, Math.max(0, heroGuests - 1)));
                    }
                }
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

            // Rejected submission: dates and guests were restored from the POST by the
            // server (hero* values); put the room, add-ons and booking type back too.
            if (typeof postedBooking !== 'undefined' && postedBooking) {
                (postedBooking.package_ids || []).forEach(id => selectedPackageIds.add(Number(id)));
                if (postedBooking.room_id && !preselectedRoomId) {
                    const postedOption = document.querySelector(`.room-option[data-room-id="${Number(postedBooking.room_id)}"]`);
                    if (postedOption) {
                        selectRoom(postedOption, true);
                    }
                }
                if (postedBooking.booking_type === 'tentative') {
                    const tentativeRadio = document.querySelector('input[name="booking_type"][value="tentative"]');
                    if (tentativeRadio) {
                        tentativeRadio.checked = true;
                        selectBookingType('tentative');
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
                }
            }

            // Add booking type change listeners
            const bookingTypeRadios = document.querySelectorAll('input[name="booking_type"]');
            bookingTypeRadios.forEach(radio => {
                radio.addEventListener('change', function() {
                    selectBookingType(this.value);
                });
            });

            // One availability check for what the page opened with (dates from the hero widget / query or a
            // refused submission), unless choosing the room above already started it.
            enforceChildGuestRules();
            checkGuestCapacity();
            updateSummary();
            if (document.getElementById('check_in_date')?.value && document.getElementById('check_out_date')?.value && !availabilityCheckPending) {
                performAvailabilityCheck();
            }
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

            const tripleGuestsText = document.getElementById('tripleGuestsText');
            if (tripleGuestsText) {
                const range = getOccupancyGuestRange('triple', room);
                tripleGuestsText.textContent = range.charAt(0).toUpperCase() + range.slice(1);
            }

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

            // The children question always stays open. An adults-only room never seats a child; a family
            // adds a room that takes children (see the mixed-room panel).
            childInput.disabled = false;
            if (childGroup) {
                childGroup.style.opacity = '1';
                childGroup.style.display = '';
            }
            if (childHint) {
                if (!allowed) {
                    childHint.innerHTML = '<i class="fas fa-ban" style="color: #dc3545;"></i> ' +
                        escapeHtml(room.name) + ' is adults only. Children cannot stay in it. If children are coming, add a room that takes children for them below (with at least 1 adult).';
                    childHint.style.color = '#dc3545';
                } else {
                    childHint.innerHTML = `<i class="fas fa-child"></i> ${escapeHtml(childRuleText(getChildRule(room)))} At least 1 adult required.`;
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

            const roomRadio = label.querySelector('input[name="room_id"]');
            const roomId = parseInt(roomRadio.value);
            const roomName = label.getAttribute('data-room-name');
            const roomPrice = parseFloat(label.getAttribute('data-room-price'));

            roomRadio.checked = true;

            if (selectedRoomId !== roomId) clearMixed(); // rooms added for the previous room type no longer apply
            selectedRoomId = roomId;
            selectedRoomName = roomName;
            selectedRoomMaxGuests = parseInt(label.getAttribute('data-max-guests'));

            // Update guest options based on room capacity
            updateGuestOptions(selectedRoomMaxGuests);

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

        }

        // Update guest dropdown options based on room capacity
        function updateGuestOptions(maxGuests) {
            const guestSelect = document.getElementById('number_of_guests');
            const capacityHint = document.getElementById('guestCapacityHint');
            const room = getSelectedRoomData();
            if (!guestSelect) return;

            // Guests are chosen BEFORE rooms, so the list is fixed (1-20). Once a room type
            // is picked, each head-count says how many of that type it takes.
            Array.from(guestSelect.options).forEach(option => {
                const n = parseInt(option.value, 10);
                if (!n) return;
                let label = plural(n, 'Guest');
                if (room) {
                    const plan = getPartyPlan(n, 0, room);
                    if (plan.valid && plan.roomsNeeded > 1) {
                        label += ` → ${plan.roomsNeeded} rooms`;
                    }
                }
                option.textContent = label;
            });

            if (capacityHint && room && Number(room.children_allowed || 0) !== 1) {
                capacityHint.textContent = `${room.name} is adults only and holds up to ${plural(maxGuests, 'guest')}. Children cannot stay in it; they go in an added room that takes children.`;
            } else if (capacityHint && room) {
                capacityHint.textContent = `Each ${room.name} holds up to ${plural(maxGuests, 'guest')}. Larger parties are booked into several ${room.name} rooms automatically (every room needs at least 1 adult).`;
            }
        }

        function setRoomCardLine(roomOption, text, isProblem) {
            const info = roomOption.querySelector('.room-info');
            if (!info) return;
            let line = info.querySelector('.room-party-plan');
            if (!line) {
                line = document.createElement('p');
                line.className = 'room-party-plan';
                line.style.cssText = 'margin:6px 0 0;font-size:0.85rem;line-height:1.4;font-weight:500;';
                line.setAttribute('role', 'status');
                info.appendChild(line);
            }
            line.textContent = text;
            line.style.color = isProblem ? '#b02a37' : '';
        }

        // Instant per-card verdict from the local mirror while check-availability answers
        // (the server verdict replaces it, same rules). Needs a plan, not dates.
        function refreshRoomCardPlansLocal() {
            const total = parseInt(document.getElementById('number_of_guests')?.value || '0', 10);
            if (!total) return;
            const children = getCurrentChildGuestCount();
            document.querySelectorAll('.room-option[data-room-id]').forEach(card => {
                const room = roomsData.find(r => r.id === Number(card.getAttribute('data-room-id')));
                if (!room) return;
                const plan = getPartyPlan(total, children, room);
                if (!plan.valid && plan.reason === 'children_not_allowed') {
                    // Adults-only type: stays selectable; the children go in an added room that takes them.
                    if (card.dataset.localDisabled === '1') {
                        enableRoomOption(card);
                        delete card.dataset.localDisabled;
                    }
                    setRoomCardLine(card, 'Adults only. Choose it for the adults and add a room that takes children for the children.', false);
                } else if (!plan.valid) {
                    disableRoomOption(card, plan.message, plan.reason);
                    card.dataset.localDisabled = '1';
                    setRoomCardLine(card, plan.message, true);
                } else {
                    if (card.dataset.localDisabled === '1') {
                        enableRoomOption(card);
                        delete card.dataset.localDisabled;
                    }
                    const counts = plan.allocation.map(part => part.guests).join(' + ');
                    setRoomCardLine(card, plan.roomsNeeded === 1 ? `Fits: 1 room for ${plural(total, 'guest')}` : `Fits: ${plan.roomsNeeded} rooms (${counts} guests)`, false);
                }
            });
            if (selectedRoomId) renderPartyPlan();
        }

        // "Your rooms" panel: says plainly how many rooms of the chosen type the party
        // gets, who sleeps where, the rate per room, and why it is NOT possible when it
        // is not. Uses the server's allocation/pricing once check-availability answered
        // (same rules, authoritative) and the local mirror until then.
        function escapeHtml(value) {
            return String(value === null || value === undefined ? '' : value)
                .replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;').replace(/"/g, '&quot;');
        }

        function describeRoomGuests(part) {
            const adultsText = plural(Number(part.adults || 0), 'adult');
            const childrenText = Number(part.children || 0) > 0 ? ` + ${plural(Number(part.children), 'child').replace('childs', 'children')}` : '';
            return adultsText + childrenText;
        }

        // The server's answer for exactly the room, dates and party on screen (null until it is in). Read from the
        // per-party store that also gates Confirm, never from the last reply, which can be for another party.
        function getServerResultForCurrentParty() {
            const checkIn = document.getElementById('check_in_date')?.value;
            const checkOut = document.getElementById('check_out_date')?.value;
            const totalGuests = parseInt(document.getElementById('number_of_guests')?.value || '0', 10);
            if (!selectedRoomId || !checkIn || !checkOut || totalGuests < 1) return null;
            const key = buildAvailabilityStatusKey(selectedRoomId, checkIn, checkOut, getCurrentChildGuestCount(), totalGuests);
            return roomAvailabilityStatus[key] || null;
        }

        function renderPartyPlan() {
            const suggestionBox = document.getElementById('secondRoomSuggestion');
            const optionsContainer = document.getElementById('secondRoomOptions');
            const titleEl = document.getElementById('partyPlanTitle');
            const introEl = document.getElementById('partyPlanIntro');
            const iconEl = document.getElementById('partyPlanIcon');
            if (!suggestionBox || !optionsContainer) return;

            const numGuests = parseInt(document.getElementById('number_of_guests')?.value || '0', 10);
            const room = getSelectedRoomData();
            const childGuests = getCurrentChildGuestCount();

            if (!numGuests || !room) {
                suggestionBox.style.display = 'none';
                validateFormForSubmit();
                return;
            }

            // Party spread over more than one room type: the panel under the selected room explains it.
            if (mixedInPlay()) {
                suggestionBox.style.display = 'none';
                validateFormForSubmit();
                return;
            }

            const plan = getPartyPlan(numGuests, childGuests, room);
            const result = getServerResultForCurrentParty();
            const serverPricing = result && result.split_pricing && result.split_pricing.valid ? result.split_pricing : null;
            const roomName = escapeHtml(room.name);
            suggestionBox.style.display = 'block';

            // Not possible: say exactly why (local rules first, then the server's reason).
            let problem = '';
            if (!plan.valid) {
                problem = plan.message;
            } else if (result && result.available === false) {
                problem = result.message || result.error || 'This room type is not available for your dates.';
            } else if (plan.valid && roomsFreeByType[room.id] !== undefined && plan.roomsNeeded > Number(roomsFreeByType[room.id])) {
                const free = Number(roomsFreeByType[room.id]);
                problem = free <= 0 ?
                    `${room.name} is fully booked for every night of your stay.` :
                    `Your party needs ${plan.roomsNeeded} ${room.name} rooms but only ${free} ${free === 1 ? 'is' : 'are'} free for every night of your stay.`;
            }

            if (problem) {
                if (titleEl) titleEl.textContent = 'This room type cannot take your party';
                if (introEl) introEl.textContent = '';
                if (iconEl) iconEl.style.color = '#dc3545';
                optionsContainer.innerHTML = `<div class="booking-split-notice booking-split-notice--warning"><p>${escapeHtml(problem)}</p><p>Try a different room type, change the number of guests or children, or pick other dates.</p></div>`;
                validateFormForSubmit();
                return;
            }

            if (iconEl) iconEl.style.color = '';
            const nights = Number(serverPricing && result.nights ? result.nights : 0);
            if (titleEl) {
                titleEl.textContent = plan.roomsNeeded === 1 ?
                    `Your booking: 1 × ${room.name}` :
                    `Your booking: ${plan.roomsNeeded} × ${room.name} (${plan.allocation.map(part => part.guests).join(' + ')} guests)`;
            }
            if (introEl) {
                introEl.textContent = plan.roomsNeeded === 1 ?
                    `All ${plural(numGuests, 'guest')} share one room.` :
                    `Each ${room.name} holds up to ${plural(Number(room.max_guests), 'guest')}, so your ${plural(numGuests, 'guest')} are placed in ${plan.roomsNeeded} rooms, one adult at least in each. They are booked together as one group.`;
            }

            const rows = (serverPricing ? serverPricing.allocation : plan.allocation).map((part, index) => {
                const occ = part.occupancy_type || part.occupancyType;
                const rate = part.rate_per_night !== undefined ? Number(part.rate_per_night) : getPriceForOccupancy(room, occ);
                const lineTotal = part.total_with_vat !== undefined ? Number(part.total_with_vat) : null;
                return `<li><strong>Room ${index + 1}:</strong> ${escapeHtml(describeRoomGuests(part))} &middot; ${getOccupancyLabel(occ, room)} rate ${currencySymbol}${rate.toLocaleString()}/night` +
                    (lineTotal !== null && nights > 0 ? ` &middot; ${currencySymbol}${lineTotal.toLocaleString()} for ${plural(nights, 'night')}` : '') + '</li>';
            }).join('');
            let totalLine = '';
            if (serverPricing && nights > 0) {
                totalLine = `<p><strong>Total for ${plural(plan.roomsNeeded, 'room')}, ${plural(nights, 'night')}: ${currencySymbol}${Number(serverPricing.total_with_vat).toLocaleString()}</strong> (VAT and tourism levy included where applicable)</p>`;
            }
            optionsContainer.innerHTML = `<div class="booking-split-notice"><ul style="margin:0;padding-left:18px;">${rows}</ul>${totalLine}</div>`;
            validateFormForSubmit();
        }

        // Kept under its old name: every caller means "re-evaluate the party".
        function checkGuestCapacity() {
            renderPartyPlan();
        }

        // Every caller means "the choices changed": the booking steps work out whether the price check still holds
        // and whether Confirm can be pressed (gbSyncQuoteState, "Booking steps" below).
        function validateFormForSubmit() {
            gbSyncQuoteState();
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
                badge.innerHTML = `<i class="fas fa-tag"></i> <strong>${escapeHtml(pricing.rate_plan_label)}</strong>
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
            // No auto-scroll here: packages load right after a room is picked, and jumping to them
            // dragged guests past Guest Information. The booking steps guide them.
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

                // Server sends the list as `inclusions` (pricing.php); `inclusions_list` kept as a fallback.
                const inclusionItems = Array.isArray(pkg.inclusions) ? pkg.inclusions :
                    (Array.isArray(pkg.inclusions_list) ? pkg.inclusions_list : []);
                const inclusions = inclusionItems.length ?
                    `<ul class="package-option__inclusions">${inclusionItems.map(i => `<li>${escapeHtml(i)}</li>`).join('')}</ul>` :
                    '';
                // Icon lands in class="..."; allow only plain class characters, else the gift icon.
                const iconRaw = String(pkg.icon || '').trim();
                const iconClass = /^[A-Za-z0-9 -]+$/.test(iconRaw) ? iconRaw : 'fas fa-gift';

                const checked = selectedPackageIds.has(Number(pkg.id));
                return `<label class="package-option${checked ? ' selected' : ''}" data-pkg-id="${pkg.id}" data-pkg-cost="${cost}">
                    <input type="checkbox" name="package_ids[]" value="${pkg.id}" form="bookingForm"${checked ? ' checked' : ''}>
                    <div class="package-option__body">
                        <div class="package-option__header">
                            <span class="package-option__icon"><i class="${iconClass}"></i></span>
                            <span class="package-option__name">${escapeHtml(pkg.name)}</span>
                            ${priceHtml}
                        </div>
                        ${pkg.short_description ? `<p class="package-option__desc">${escapeHtml(pkg.short_description)}</p>` : ''}
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

                    // Rooms of different types: price and list every room from the server's seating; nothing to show until
                    // every guest has a room.
                    let mixedNow = null;
                    if (mixedInPlay()) {
                        mixedNow = getMixedForCurrent();
                        if (!mixedNow || !mixedNow.ok || !(mixedNow.rows || []).length) {
                            bookingSummary.style.display = 'none';
                            validateFormForSubmit();
                            return;
                        }
                    }
                    const roomsNeeded = mixedNow ? mixedNow.rows.length : getRoomsNeededForGuests(totalGuests, selectedRoom);
                    const statusKey = buildAvailabilityStatusKey(selectedRoomId, checkIn, checkOut, childGuests, totalGuests);
                    const serverPricing = mixedNow ? {
                        valid: true,
                        allocation: mixedNow.rows,
                        room_rate_total_per_night: mixedNow.rows.reduce((sum, r) => sum + Number(r.rate_per_night || 0), 0),
                        base_total: mixedNow.rows.reduce((sum, r) => sum + Number(r.rate_per_night || 0) * nights, 0),
                        child_supplement_total: mixedNow.rows.reduce((sum, r) => sum + Number(r.child_supplement_total || 0), 0),
                        levy: mixedNow.levy,
                        vat: mixedNow.vat,
                        total_with_vat: mixedNow.total_with_vat,
                        tourism_levy_percent: mixedNow.tourism_levy_percent,
                        vat_rate: mixedNow.vat_rate,
                        vat_mode: mixedNow.vat_mode,
                        vat_shows_amount: mixedNow.vat_shows_amount,
                        rate_plan: mixedNow.rate_plan
                    } : ((roomAvailabilityStatus[statusKey] || {}).split_pricing || null); // the server's price for exactly this stay
                    // No server price for exactly this stay yet: no amount is shown, never one for another party or dates.
                    const priceKnown = !!serverPricing;
                    const noPriceText = roomAvailabilityStatus[statusKey] && !availabilityCheckPending ? '—' : 'Checking price…';
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
                    const roomChildRule = getChildRule(selectedRoom);

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
                            let childrenThisRoom = Math.min(remainingChildren, Math.max(0, part.guests - 1));
                            if (roomChildRule.max !== null) childrenThisRoom = Math.min(childrenThisRoom, roomChildRule.max);
                            remainingChildren -= childrenThisRoom;
                            roomRateTotalPerNight += ratePerNight;
                            baseTotal += ratePerNight * nights;
                            childSupplement += Math.round(childNightCharge(roomChildRule, childrenThisRoom, ratePerNight) * nights * 100) / 100;
                            part.ratePerNight = ratePerNight;
                            part.children = childrenThisRoom;
                            part.adults = Math.max(1, part.guests - childrenThisRoom);
                        });
                    }

                    // Calculate package total from selected packages
                    const pkgTotal = getPackageTotal(nights, adults);

                    // Levy / VAT / total come from the server (rh_stay_totals); the levy sits
                    // outside the VAT base. Packages carry VAT but no levy (added below).
                    const vatModeNow = serverPricing && serverPricing.vat_mode ?
                        serverPricing.vat_mode :
                        (typeof siteVatMode !== 'undefined' ? siteVatMode : 'off');
                    const vatRateNow = serverPricing && serverPricing.vat_rate !== undefined ?
                        Number(serverPricing.vat_rate) :
                        (typeof siteVatRate !== 'undefined' ? Number(siteVatRate) : 0);
                    const showVatAmount = serverPricing && serverPricing.vat_shows_amount !== undefined ?
                        !!serverPricing.vat_shows_amount :
                        vatModeNow === 'exclusive';

                    let tourismLevyAmount = 0;
                    let stayVat = 0;
                    let stayTotalWithVat = 0;
                    if (serverPricing) {
                        tourismLevyAmount = Number(serverPricing.levy ?? serverPricing.tourism_levy_amount ?? 0);
                        stayVat = Number(serverPricing.vat || 0);
                        stayTotalWithVat = Number(serverPricing.total_with_vat ?? serverPricing.total_before_packages ?? 0);
                    } else {
                        // No server price yet: these local figures only decide the "Includes VAT / levy" note; no amount is shown.
                        const rawStay = baseTotal + childSupplement;
                        const vr = vatModeNow === 'off' ? 0 : vatRateNow / 100;
                        const lr = (tourismLevyEnabled && tourismLevyPercent > 0) ? tourismLevyPercent / 100 : 0;
                        if (true) {
                            // All-in price: VAT and levy are both inside it.
                            const netExact = rawStay / (1 + vr + lr);
                            stayVat = netExact * vr;
                            tourismLevyAmount = netExact * lr;
                            stayTotalWithVat = rawStay;
                        } else {
                            stayVat = rawStay * vr;
                            tourismLevyAmount = rawStay * lr;
                            stayTotalWithVat = rawStay + stayVat + tourismLevyAmount;
                        }
                    }
                    // Package VAT is already inside the price.
                    let pkgVat = 0;
                    let pkgWithVat = pkgTotal;
                    if (pkgTotal > 0 && vatRateNow > 0) {
                        if (vatModeNow !== 'off') {
                            // Package price is all-in in every mode (owner 2026-10-24).
                            pkgVat = pkgTotal - pkgTotal / (1 + vatRateNow / 100);
                        }
                    }
                    const vatTotalNow = stayVat + pkgVat;
                    const total = Math.round((stayTotalWithVat + pkgWithVat) * 100) / 100;

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
                    const summaryRoomList = document.getElementById('summaryRoomList');
                    if (mixedNow) {
                        // Every room with its own type name and nightly price.
                        document.getElementById('summaryRoom').textContent = `${roomsNeeded} rooms`;
                        if (summaryRoomList) {
                            summaryRoomList.innerHTML = mixedNow.rows.map(r => `<li><strong>${escapeHtml(r.room_name)}</strong> &middot; ${escapeHtml(describeRoomGuests(r))} &middot; ${currencySymbol}${Number(r.rate_per_night).toLocaleString()}/night</li>`).join('');
                            summaryRoomList.style.display = '';
                        }
                    } else {
                        document.getElementById('summaryRoom').textContent = selectedRoomName;
                        if (summaryRoomList) {
                            summaryRoomList.innerHTML = '';
                            summaryRoomList.style.display = 'none';
                        }
                    }
                    const occupancySummary = roomsNeeded > 1 ?
                        `${totalGuests} Guests (${roomsNeeded} rooms: ${allocation.map((part, index) => `R${index + 1} ${getOccupancyTierName(part.occupancyType)}`).join(', ')})` :
                        `${getOccupancyTierName(allocation[0].occupancyType)} rate (${totalGuests} Guest${totalGuests === 1 ? '' : 's'})`;
                    document.getElementById('summaryOccupancyType').textContent = occupancySummary;
                    document.getElementById('summaryRatePerNight').textContent = !priceKnown ? '—' : roomsNeeded > 1 ?
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
                            childChargeEl.textContent = !priceKnown ? '—'
                                : childSupplement > 0
                                ? currencySymbol + childSupplement.toLocaleString() + ` (${childGuests} child${childGuests === 1 ? '' : 'ren'} across ${nights} night${nights === 1 ? '' : 's'})`
                                : `No charge (${childGuests} child${childGuests === 1 ? '' : 'ren'})`;
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
                    // The server's plan for exactly this stay (mixed rooms: the seating's), never the last reply's: it can be for other dates.
                    const activeRatePlan = serverRatePlan || (mixedNow ? currentDynamicPricing : null);
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
                            const pkgIcon = String(pkg.icon || '').trim();
                            const pkgIconClass = /^[A-Za-z0-9 -]+$/.test(pkgIcon) ? pkgIcon : 'fas fa-check-circle';
                            li.innerHTML = `<i class="${pkgIconClass}" aria-hidden="true"></i>${escapeHtml(pkg.name)}${isComp ? ' <span class="bsum-pkg-comp"><i class="fas fa-gift" aria-hidden="true"></i> Complimentary</span>' : ''}`;
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
                    document.getElementById('summaryTotal').textContent = priceKnown ? currencySymbol + total.toLocaleString() : noPriceText;

                    // Levy + VAT breakdown lines (VAT amount only when VAT display is on)
                    const tourismLevyNote = document.getElementById('summaryTourismLevyNote');
                    const tourismLevyText = document.getElementById('tourismLevyText');
                    const fmtMoney = v => currencySymbol + Number(v).toLocaleString(undefined, {
                        minimumFractionDigits: 2,
                        maximumFractionDigits: 2
                    });
                    const levyPctShown = serverPricing ? Number(serverPricing.tourism_levy_percent || 0) : tourismLevyPercent;
                    // All-in rate (every mode): guests see one plain note, no VAT/levy amounts (owner 2026-10-24).
                    const incVat = vatModeNow !== 'off' && vatRateNow > 0;
                    const incLevy = tourismLevyAmount > 0;
                    if (incVat || incLevy) {
                        tourismLevyNote.style.display = '';
                        tourismLevyText.textContent = 'Includes ' + (incVat && incLevy ? 'VAT and tourism levy' : (incVat ? 'VAT' : 'tourism levy'));
                    } else {
                        tourismLevyNote.style.display = 'none';
                    }
                    let vatNote = document.getElementById('summaryVatNote');
                    if (!vatNote && tourismLevyNote && tourismLevyNote.parentNode) {
                        vatNote = document.createElement('div');
                        vatNote.id = 'summaryVatNote';
                        vatNote.className = 'bsum-total-note';
                        vatNote.style.display = 'none';
                        tourismLevyNote.parentNode.insertBefore(vatNote, tourismLevyNote.nextSibling);
                    }
                    if (vatNote) {
                        vatNote.style.display = 'none'; // one combined "Includes ..." note above; no amounts for guests
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
            let childRuleSentence = childRuleText(getChildRule(null));
            if (selectedRoomId) {
                const selectedRoom = roomsData.find(room => room.id === selectedRoomId);
                if (selectedRoom) {
                    childRuleSentence = childRuleText(getChildRule(selectedRoom));
                }
            }

            if (childHint) {
                let hintHtml = '';
                if (childGuests > 0 && adults >= 1) {
                    // Show breakdown with pricing
                    hintHtml = `<i class="fas fa-users"></i> ${adults} adult${adults === 1 ? '' : 's'} + ${childGuests} child${childGuests === 1 ? '' : 'ren'}`;
                    hintHtml += ` <span style="color: var(--gold);">• ${escapeHtml(childRuleSentence)}</span>`;
                } else if (adults < 1) {
                    hintHtml = `<i class="fas fa-exclamation-triangle" style="color: #dc3545;"></i> At least 1 adult is required. Current: ${adults} adult${adults === 1 ? '' : 's'}`;
                } else {
                    hintHtml = `<i class="fas fa-child"></i> ${escapeHtml(childRuleSentence)} At least 1 adult is required in each room. Max ${maxChildren} children for this selection.`;
                }
                const adultsOnlyRoom = selectedRoomId ? roomsData.find(room => room.id === selectedRoomId) : null;
                if (adultsOnlyRoom && Number(adultsOnlyRoom.children_allowed || 0) !== 1) {
                    hintHtml = '<i class="fas fa-ban" style="color: #dc3545;"></i> ' + escapeHtml(adultsOnlyRoom.name) +
                        ' is adults only. Children cannot stay in it. If children are coming, add a room that takes children for them below (with at least 1 adult).';
                    childHint.style.color = '#dc3545';
                } else {
                    childHint.style.color = '';
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
            const checkedType = document.querySelector('input[name="booking_type"]:checked');
            selectBookingType(checkedType ? checkedType.value : 'standard');
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
                    refreshRoomCardPlansLocal();
                });
            }

            // Add child guests input listener
            if (childGuestsInput) {
                childGuestsInput.addEventListener('input', function() {
                    enforceChildGuestRules();
                    checkGuestCapacity();
                    refreshRoomCardPlansLocal();
                    updateSummary();
                    validateFormForSubmit();
                });
            }
            refreshRoomCardPlansLocal();
        });

        // Availability state tracking
        let availabilityCheckPending = false; // true from the moment the party or dates change until the newest check answers
        let availabilityCheckTimer = null;
        let availabilityCheckSeq = 0; // newest check asked for; an older reply is dropped (its party or dates are gone)
        let roomAvailabilityStatus = {}; // Track availability status per room

        // The party or dates changed: a check is on its way, so Confirm waits from now (debounce included) and any reply
        // still out is for the old party or dates.
        function markAvailabilityCheckPending() {
            availabilityCheckSeq++;
            availabilityCheckPending = true;
            validateFormForSubmit();
        }

        // Initialize immediate availability validation
        function initAvailabilityValidation() {
            const checkInInput = document.getElementById('check_in_date');
            const checkOutInput = document.getElementById('check_out_date');
            const guestsInput = document.getElementById('number_of_guests');
            const childInput = document.getElementById('child_guests');

            // Trigger availability check immediately when both dates are entered
            const triggerImmediateAvailabilityCheck = () => {
                const checkIn = checkInInput ? checkInInput.value : '';
                const checkOut = checkOutInput ? checkOutInput.value : '';

                // If both dates are entered, trigger availability check immediately
                if (checkIn && checkOut) {
                    markAvailabilityCheckPending();
                    clearTimeout(availabilityCheckTimer);
                    availabilityCheckTimer = setTimeout(() => {
                        performAvailabilityCheck();
                    }, 100); // Minimal delay to ensure UI updates first
                }
            };

            // Debounced availability check on date/guest changes
            const scheduleAvailabilityCheck = () => {
                markAvailabilityCheckPending();
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
        }

        // Perform availability check for all rooms or selected room
        function performAvailabilityCheck() {
            const seq = ++availabilityCheckSeq;
            const checkIn = document.getElementById('check_in_date').value;
            const checkOut = document.getElementById('check_out_date').value;
            const guestsInput = document.getElementById('number_of_guests');
            const numGuests = guestsInput ? parseInt(guestsInput.value || '0', 10) : 0;
            const childInput = document.getElementById('child_guests');
            const childGuests = childInput ? parseInt(childInput.value || '0', 10) : 0;

            // Clear previous availability status if dates are incomplete
            if (!checkIn || !checkOut) {
                availabilityCheckPending = false;
                clearAvailabilityMessage();
                enableAllRoomOptions();
                validateFormForSubmit();
                return;
            }

            // Validate date range
            const checkInDate = new Date(checkIn);
            const checkOutDate = new Date(checkOut);
            if (checkOutDate <= checkInDate) {
                availabilityCheckPending = false;
                showAvailabilityMessage('<i class="fas fa-exclamation-triangle"></i> Check-out date must be after check-in date.', 'error');
                validateFormForSubmit();
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
            validateFormForSubmit(); // Confirm waits for this check

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
                    // An older check (the party or dates changed since): its answer is dropped, "pending" stays for the newest.
                    if (seq !== availabilityCheckSeq) return;
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

                        if (result.rooms_free !== undefined && result.rooms_free !== null) {
                            roomsFreeByType[roomId] = Number(result.rooms_free);
                        }
                        updateRoomAvailabilityCount(roomOption, result, childGuests);
                        renderRoomCardPlan(roomOption, result, numGuests > 0);

                        // A room type with a few rooms free but not enough for the whole party stays selectable: the guest adds
                        // rooms of other types for the rest (see loadMixed()).
                        const mixedCandidate = isMixedCandidate(result);
                        if (result.available || mixedCandidate) {
                            availableCount++;
                            enableRoomOption(roomOption);

                            if (roomId === selectedRoomId) {
                                currentAvailabilityResult = result;
                                if (mixedCandidate) {
                                    applyDynamicPricingState(null);
                                    updateSummary(); // hides the one-type summary until the server has seated the party
                                } else {
                                    const nights = Number(result.nights || 0);
                                    applyDynamicPricingState(result.split_pricing?.rate_plan || result.dynamic_pricing || null);
                                    renderPackages(result.packages || [], nights, adultGuests);
                                    updateSummary();
                                }
                            }
                        } else {
                            disableRoomOption(roomOption, result.message || result.error || 'Unavailable', result.reason || '');

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

                    // Free-room counts changed: re-grey impossible head-counts and re-explain the plan.
                    if (selectedRoomId && selectedRoomMaxGuests) {
                        updateGuestOptions(selectedRoomMaxGuests);
                    }
                    renderPartyPlan();

                    // Selected room type cannot seat the whole party alone: ask the server for the seating + what can be added.
                    if (mixedInPlay()) {
                        renderMixedPanel();
                        loadMixed();
                    } else {
                        clearMixed();
                    }

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

                    updateSummary(); // nothing pending now: the figures, or "—" when the answer has no price
                    validateFormForSubmit();
                })
                .catch(() => {
                    if (seq !== availabilityCheckSeq) return;
                    availabilityCheckPending = false;
                    showAvailabilityMessage('<i class="fas fa-exclamation-triangle"></i> Unable to check availability. Please try again.', 'error');
                    updateSummary(); // nothing pending now: clears "Checking price…"
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
        // One plain-language line on each room card: what this party would get in this
        // room type (rooms, split, total) or exactly why it is not offered.
        function renderRoomCardPlan(roomOption, result, partyChosen) {
            if (!partyChosen) {
                setRoomCardLine(roomOption, 'Choose your number of guests to see how many rooms you need.', false);
                return;
            }
            const pricing = result && result.split_pricing && result.split_pricing.valid ? result.split_pricing : null;
            if (isMixedCandidate(result) && result.reason === 'children_not_allowed') {
                setRoomCardLine(roomOption, 'Adults only. Choose it for the adults and add a room that takes children for the children.', false);
                return;
            }
            if (isMixedCandidate(result)) {
                const free = Number(result.rooms_free || 0);
                setRoomCardLine(roomOption, `Only ${free} ${free === 1 ? 'room is' : 'rooms are'} free for your dates and your party needs ${Number(result.rooms_needed || 0)}. Choose it and add another room type for the rest.`, false);
                return;
            }
            if (result && result.available && pricing) {
                const counts = pricing.allocation.map(part => part.guests).join(' + ');
                const rooms = Number(result.rooms_needed || pricing.allocation.length);
                setRoomCardLine(roomOption, (rooms === 1 ? 'Fits: 1 room' : `Fits: ${rooms} rooms (${counts} guests)`) +
                    ` · ${currencySymbol}${Number(pricing.total_with_vat).toLocaleString()} total for ${plural(Number(result.nights || 0), 'night')}`, false);
            } else {
                setRoomCardLine(roomOption, (result && (result.message || result.error)) || 'Not available for your party and dates.', true);
            }
        }

        function disableRoomOption(roomOption, reason, code) {
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
            const badgeText = {
                children_not_allowed: 'Adults only',
                adults_per_room: 'Needs more adults',
                no_pricing: 'Not offered for this party',
                not_enough_rooms: 'Not enough rooms free',
                child_rooms: 'Not enough child-friendly rooms',
                invalid_party: 'Check guest numbers'
            }[code] || 'Unavailable';
            badge.innerHTML = '<i class="fas fa-ban"></i> ' + badgeText;
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

            gbSyncQuoteState();
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

        // Instant field validation for better UX
        function initInstantValidation() {
            const nameInput = document.getElementById('guest_name');
            const emailInput = document.getElementById('guest_email');
            const phoneInput = document.getElementById('guest_phone');

            // Name validation - at least 2 characters, letters/spaces/hyphens only
            if (nameInput) {
                nameInput.addEventListener('input', function() {
                    validateNameField(this);
                });
                nameInput.addEventListener('blur', function() {
                    validateNameField(this);
                });
            }

            // Email validation
            if (emailInput) {
                emailInput.addEventListener('input', function() {
                    validateEmailField(this);
                });
                emailInput.addEventListener('blur', function() {
                    validateEmailField(this);
                });
            }

            // Phone validation
            if (phoneInput) {
                phoneInput.addEventListener('input', function() {
                    validatePhoneField(this);
                });
                phoneInput.addEventListener('blur', function() {
                    validatePhoneField(this);
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
                feedback.innerHTML = `Did you mean <strong>${escapeHtml(value.split('@')[0])}@${suggestions[domain]}</strong>?`;
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

        // ── Booking steps (G-A) ──────────────────────────────────────────────────────────────────────────
        // One step on screen at a time: 1 When, 2 Who, 3 Room, 4 Your details, 5 Review and book.
        // Continue runs that step's checks. Room's Continue and Review ask booking.php for a price check (a dry run of
        // the save, response=wizard); Review shows only the server's quote. Any change in steps 1-4 makes the quote
        // stale and Review asks again. Book stays a normal form POST, enabled only while the quote is fresh.
        // Without JavaScript the whole form shows at once (the steps are hidden only under .js-steps).
        var gbCurrent = 1;
        var gbReached = 1;
        var gbReady = false;
        var gbQuote = null;          // the last price check quote (server figures only)
        var gbQuoteFp = '';          // the form as it was when that quote was asked for
        var gbQuoteUpto = 0;         // 3 = Room's check, 5 = Review's check (only 5 can be booked)
        var gbLastError = '';
        var gbErrorFp = '';
        var gbAutoFp = '';           // Review asks again at most once per version of the form
        var gbCheckSeq = 0;          // newest price check; older replies are dropped
        var gbCheckPending = false;
        var gbCheckCtl = null;
        var gbSubmitting = false;
        var gbBusy = false;

        function gbForm() { return document.getElementById('bookingForm'); }
        function gbStepEl(n) { return document.getElementById('gbStep' + n); }
        function gbVal(id) { const el = document.getElementById(id); return el ? String(el.value || '') : ''; }

        // The form's choices (what the price check prices); the token and the booking's id are not choices.
        function gbFingerprint() {
            const form = gbForm();
            if (!form) return '';
            const parts = [];
            new FormData(form).forEach(function (v, k) {
                if (k === 'csrf_token' || k === 'client_uuid') return;
                parts.push(k + '=' + (typeof v === 'string' ? v : ''));
            });
            return parts.join('&');
        }

        function gbQuoteState() {
            if (gbCheckPending) return 'pending';
            const fp = gbFingerprint();
            if (gbQuote && gbQuoteUpto === 5 && gbQuoteFp === fp) return 'fresh';
            if (gbLastError && gbErrorFp === fp) return 'error';
            return 'stale';
        }

        function gbMoney(v) {
            return currencySymbol + Number(v || 0).toLocaleString();
        }

        // Review's money, from the server's quote only (textContent / DOM, never markup from the reply).
        function gbPaintQuote(state) {
            const summary = document.getElementById('bookingSummary');
            if (!summary) return;
            const el = function (id) { return document.getElementById(id); };
            const setText = function (id, text) { const n = el(id); if (n && n.textContent !== text) n.textContent = text; return n; };
            const showRow = function (id, on) { const n = el(id); if (n) n.style.display = on ? '' : 'none'; return n; };
            const children = getCurrentChildGuestCount();
            const q = state === 'fresh' ? gbQuote : null;

            if (!q) {
                setText('summaryTotal', state === 'pending' ? 'Checking price…' : '—');
                setText('summaryRatePerNight', '—');
                if (children > 0) setText('summaryChildCharge', '—');
                showRow('summaryRatePlanRow', false);
                setText('summaryPackageTotal', '—');
                return;
            }

            const rows = [];
            (q.lines || []).forEach(function (line) {
                (line.rows || []).forEach(function (r) { rows.push({ name: String(line.name || ''), r: r }); });
            });
            const nights = Number(q.nights || 0);
            const totals = q.totals || {};

            // Room(s)
            setText('summaryRoom', rows.length > 1 ? rows.length + ' rooms' : (rows[0] ? rows[0].name : (selectedRoomName || '')));
            const list = el('summaryRoomList');
            if (list) {
                list.textContent = '';
                if (rows.length > 1) {
                    rows.forEach(function (row) {
                        const li = document.createElement('li');
                        const strong = document.createElement('strong');
                        strong.textContent = row.name;
                        const adults = Number(row.r.adults || 0);
                        const kids = Number(row.r.children || 0);
                        const who = plural(adults, 'adult') + (kids > 0 ? ' + ' + kids + (kids === 1 ? ' child' : ' children') : '');
                        li.appendChild(strong);
                        li.appendChild(document.createTextNode(' · ' + who + ' · ' + gbMoney(row.r.room_price) + '/night'));
                        list.appendChild(li);
                    });
                    list.style.display = '';
                } else {
                    list.style.display = 'none';
                }
            }

            // Rate per night (the room rate of every room, summed)
            const perNight = rows.reduce(function (s, row) { return s + Number(row.r.room_price || 0); }, 0);
            setText('summaryRatePerNight', rows.length > 1 ? gbMoney(perNight) + ' across ' + rows.length + ' rooms' : gbMoney(perNight));

            // Child supplement
            const childRow = el('summaryChildChargeRow');
            if (childRow) {
                if (children > 0) {
                    const sup = Number(totals.child_supplement || 0);
                    const kidsWord = children + (children === 1 ? ' child' : ' children');
                    setText('summaryChildCharge', sup > 0
                        ? gbMoney(sup) + ' (' + kidsWord + ' across ' + plural(nights, 'night') + ')'
                        : 'No charge (' + kidsWord + ')');
                    childRow.style.display = '';
                } else {
                    childRow.style.display = 'none';
                }
            }

            // Rate plan (the quote carries the plan's name; its effect is inside the room rate above)
            let planLabel = '';
            rows.some(function (row) { planLabel = String(row.r.rate_plan_label || ''); return planLabel !== ''; });
            if (planLabel) {
                setText('summaryRatePlanLabel', planLabel + ':');
                setText('summaryRatePlanValue', 'Applied');
                showRow('summaryRatePlanRow', true);
            } else {
                showRow('summaryRatePlanRow', false);
            }

            // Packages
            const pkgs = Array.isArray(q.packages) ? q.packages : [];
            const names = el('summaryPackageNames');
            if (names) {
                names.textContent = '';
                pkgs.forEach(function (pk) {
                    const li = document.createElement('li');
                    const icon = document.createElement('i');
                    icon.className = 'fas fa-check-circle';
                    icon.setAttribute('aria-hidden', 'true');
                    li.appendChild(icon);
                    li.appendChild(document.createTextNode(String(pk.name || '')));
                    if (Number(pk.total || 0) === 0) {
                        const comp = document.createElement('span');
                        comp.className = 'bsum-pkg-comp';
                        comp.textContent = ' Complimentary';
                        li.appendChild(comp);
                    }
                    names.appendChild(li);
                });
            }
            const pkgTotal = Number(totals.package_total || 0);
            const pkgEl = el('summaryPackageTotal');
            if (pkgEl) {
                const free = pkgs.filter(function (pk) { return Number(pk.total || 0) === 0; }).length;
                if (pkgTotal > 0) {
                    pkgEl.textContent = gbMoney(pkgTotal) + (free > 0 ? ' (+' + free + ' free)' : '');
                    showRow('summaryPackageTotalRow', true);
                } else if (pkgs.length) {
                    pkgEl.textContent = 'Complimentary';
                    showRow('summaryPackageTotalRow', true);
                } else {
                    showRow('summaryPackageTotalRow', false);
                }
            }

            // Total + the one plain "Includes ..." note (no VAT/levy amounts for guests)
            setText('summaryTotal', gbMoney(totals.total_with_vat));
            const incVat = String(q.vat_mode || '') !== 'off' && Number(q.vat_rate || 0) > 0;
            const incLevy = Number(totals.levy || 0) > 0;
            const levyNote = el('summaryTourismLevyNote');
            if (levyNote) {
                if (incVat || incLevy) {
                    setText('tourismLevyText', 'Includes ' + (incVat && incLevy ? 'VAT and tourism levy' : (incVat ? 'VAT' : 'tourism levy')));
                    levyNote.style.display = '';
                } else {
                    levyNote.style.display = 'none';
                }
            }
            if (summary.style.display !== 'block') summary.style.display = 'block';
        }

        // Book button, Review's money and the status line, for the quote's state now. Safe to call at any time.
        function gbSyncQuoteState() {
            if (!gbReady) return;
            const state = gbQuoteState();
            const summary = document.getElementById('bookingSummary');
            if (summary && summary.getAttribute('data-quote') !== state) summary.setAttribute('data-quote', state);
            gbPaintQuote(state);

            const btn = document.querySelector('.btn-submit');
            if (btn) {
                const canBook = gbCurrent === 5 && state === 'fresh' && !gbSubmitting;
                btn.disabled = !canBook;
                btn.style.opacity = '';
                const html = gbSubmitting
                    ? '<i class="fas fa-spinner fa-spin" aria-hidden="true"></i> Booking…'
                    : state === 'pending'
                    ? '<i class="fas fa-spinner fa-spin" aria-hidden="true"></i> Checking price…'
                    : '<i class="fas fa-check-circle" aria-hidden="true"></i> Confirm Booking';
                if (btn.innerHTML !== html) btn.innerHTML = html;
            }

            const status = document.getElementById('gbQuoteStatus');
            if (status) {
                const text = state === 'pending' ? 'Checking the price with the hotel…'
                    : (state === 'stale' && gbCurrent === 5) ? 'Your choices changed: checking the price again…'
                    : '';
                if (status.textContent !== text) status.textContent = text;
            }

            // Review asks again when the choices changed under it (once per version of the form).
            if (gbCurrent === 5 && state === 'stale' && !gbSubmitting) {
                const fp = gbFingerprint();
                if (gbAutoFp !== fp) {
                    gbAutoFp = fp;
                    setTimeout(gbReviewCheck, 0);
                }
            }
        }

        // booking.php price check (dry run). Resolves to the reply, or null when a newer check replaced it.
        function gbPriceCheck(upto) {
            const form = gbForm();
            if (!form) return Promise.resolve(null);
            if (gbCheckCtl) {
                try { gbCheckCtl.abort(); } catch (e) { /* already finished */ }
            }
            const seq = ++gbCheckSeq;
            const ctl = typeof AbortController !== 'undefined' ? new AbortController() : null;
            gbCheckCtl = ctl;
            gbCheckPending = true;
            const fp = gbFingerprint();
            const fd = new FormData(form);
            fd.append('response', 'wizard');
            fd.append('dry_run', '1');
            fd.append('dry_run_upto', String(upto));
            gbSyncQuoteState();

            const finish = function () {
                gbCheckPending = false;
                gbCheckCtl = null;
            };
            return fetch(form.action, {
                method: 'POST',
                body: fd,
                credentials: 'same-origin',
                headers: { 'Accept': 'application/json' },
                signal: ctl ? ctl.signal : undefined
            }).then(function (res) {
                return res.text();
            }).then(function (text) {
                if (seq !== gbCheckSeq) return null;
                let j = null;
                try { j = JSON.parse(text); } catch (e) { j = null; }
                if (!j || typeof j !== 'object') j = { ok: false, step: 0, message: '' };
                if (typeof j.csrf_token === 'string' && j.csrf_token !== '') {
                    const tokenInput = form.querySelector('input[name="csrf_token"]');
                    if (tokenInput) tokenInput.value = j.csrf_token;
                }
                if (j.duplicate && typeof j.redirect === 'string' && /^booking-confirmation\.php\?ref=[A-Za-z0-9_-]+$/.test(j.redirect)) {
                    window.location.href = j.redirect; // this booking was already made (same booking id)
                    return null;
                }
                finish();
                if (j.ok && j.quote) {
                    gbQuote = j.quote;
                    gbQuoteFp = fp;
                    gbQuoteUpto = upto;
                    gbLastError = '';
                } else if (!j.ok) {
                    gbQuote = null;
                    gbQuoteUpto = 0;
                    gbLastError = String(j.message || '') || 'refused';
                    gbErrorFp = fp;
                }
                gbSyncQuoteState();
                return j;
            }).catch(function () {
                if (seq !== gbCheckSeq) return null; // replaced by a newer check
                finish();
                gbQuote = null;
                gbQuoteUpto = 0;
                gbLastError = 'We could not reach the hotel. Please check your connection and try again.';
                gbErrorFp = fp;
                gbSyncQuoteState();
                return { ok: false, step: 0, message: gbLastError, network: true };
            });
        }

        function gbReviewCheck() {
            if (gbCurrent !== 5 || gbSubmitting) return;
            gbPriceCheck(5).then(function (j) {
                if (j && !j.ok) { gbShowRefusal(j); return; }
                // An accepted check clears an earlier Review message (e.g. a broken reply), even after a script's change.
                const p = j && j.ok ? (gbStepEl(5) || document).querySelector('.gb-step__error') : null;
                if (p) { p.textContent = ''; p.hidden = true; }
            });
        }

        // ── Messages ──
        function gbFocus(node) {
            if (!node) return;
            try { node.focus({ preventScroll: true }); } catch (e) { node.focus(); }
            const group = node.closest ? (node.closest('.form-group') || node) : node;
            scrollToSection(group);
        }

        function gbStepError(step, text) {
            const sec = gbStepEl(step);
            const p = sec ? sec.querySelector('.gb-step__error') : null;
            if (!p) return;
            p.textContent = text;
            p.hidden = false;
        }

        function gbClearErrors() {
            document.querySelectorAll('#bookingForm .gb-field-error').forEach(function (p) { p.remove(); });
            document.querySelectorAll('#bookingForm [data-gb-invalid]').forEach(function (input) {
                input.removeAttribute('aria-invalid');
                const prev = input.getAttribute('data-gb-describedby') || '';
                if (prev) input.setAttribute('aria-describedby', prev); else input.removeAttribute('aria-describedby');
                input.removeAttribute('data-gb-describedby');
                input.removeAttribute('data-gb-invalid');
            });
            document.querySelectorAll('#bookingForm .gb-step__error').forEach(function (p) {
                p.textContent = '';
                p.hidden = true;
            });
        }

        function gbFieldInput(field) {
            const ids = {
                check_in_date: 'check_in_date', check_out_date: 'check_out_date', dates: 'check_in_date',
                number_of_guests: 'number_of_guests', child_guests: 'child_guests',
                guest_name: 'guest_name', guest_email: 'guest_email', guest_phone: 'guest_phone',
                guest_country: 'guest_country', guest_address: 'guest_address', special_requests: 'special_requests'
            };
            return ids[field] ? document.getElementById(ids[field]) : null;
        }

        // Open a step and show a plain message next to its field (or at the top of the room / package choice).
        function gbFail(step, field, msg) {
            step = Math.min(Math.max(parseInt(step, 10) || 1, 1), 5);
            gbShowStep(step, { focus: false, noCheck: true });
            const text = String(msg || '').replace(/<\/?[^>]+(>|$)/g, '').trim() || 'Please check this step.';
            const sec = gbStepEl(step);
            const input = gbFieldInput(field);
            const p = document.createElement('p');
            p.className = 'gb-field-error';
            p.setAttribute('role', 'alert');
            p.textContent = text;

            if (input && sec && sec.contains(input)) {
                p.id = 'gbErr_' + input.id;
                (input.closest('.form-group') || input.parentNode).appendChild(p);
                const prev = input.getAttribute('aria-describedby') || '';
                input.setAttribute('data-gb-describedby', prev);
                input.setAttribute('data-gb-invalid', '1');
                input.setAttribute('aria-invalid', 'true');
                input.setAttribute('aria-describedby', (prev ? prev + ' ' : '') + p.id);
                gbFocus(input);
                return;
            }
            let box = null;
            if (field === 'room_id' && step === 3) box = document.getElementById('roomSectionWrapper') || document.getElementById('preselectedRoomSection');
            if (field === 'package_ids' && step === 3) {
                const pk = document.getElementById('packagesSection');
                box = pk && pk.style.display !== 'none' ? pk : null;
            }
            if (box) {
                p.id = 'gbErr_' + field;
                box.insertBefore(p, box.firstChild);
                const target = field === 'room_id'
                    ? (box.querySelector('input[name="room_id"]:checked') || box.querySelector('input[name="room_id"]:not([disabled])'))
                    : box.querySelector('input:not([disabled])');
                if (target && target.type !== 'hidden') {
                    target.setAttribute('data-gb-describedby', target.getAttribute('aria-describedby') || '');
                    target.setAttribute('data-gb-invalid', '1');
                    target.setAttribute('aria-describedby', p.id);
                    try { target.focus({ preventScroll: true }); } catch (e) { target.focus(); }
                } else if (sec) {
                    try { sec.focus({ preventScroll: true }); } catch (e) { sec.focus(); }
                }
                scrollToSection(p);
                return;
            }
            gbStepError(step, text);
            gbFocus(sec);
        }

        // A refused price check: open its step; a whole-page refusal (step 0) or a broken reply shows where the guest is.
        function gbShowRefusal(j) {
            const step = parseInt(j && j.step, 10);
            const message = j && typeof j.message === 'string' ? j.message.trim() : '';
            if (step >= 1 && step <= 5 && message !== '') {
                gbFail(step, j.field || '', message);
                return;
            }
            let text = message || 'Something went wrong while checking your booking.';
            if (!/refresh|try again/i.test(text)) text += ' Please refresh the page and try again.';
            gbStepError(gbCurrent, text);
            gbFocus(gbStepEl(gbCurrent));
        }

        // ── Step checks ──
        function gbWaitIdle(maxMs) {
            const started = Date.now();
            return new Promise(function (resolve) {
                (function poll() {
                    const mixedLoading = !!selectedRoomId && mixedInPlay() && !getMixedForCurrent();
                    if ((!availabilityCheckPending && !mixedLoading) || Date.now() - started > maxMs) {
                        resolve();
                        return;
                    }
                    setTimeout(poll, 100);
                })();
            });
        }

        function gbStatusKey() {
            const ci = gbVal('check_in_date');
            const co = gbVal('check_out_date');
            const total = parseInt(gbVal('number_of_guests') || '0', 10);
            if (!selectedRoomId || !ci || !co || total < 1) return '';
            return buildAvailabilityStatusKey(selectedRoomId, ci, co, getCurrentChildGuestCount(), total);
        }

        // Why the chosen room cannot be booked as things stand ('' = it can), from the availability answers.
        function gbRoomProblem() {
            const total = parseInt(gbVal('number_of_guests') || '0', 10);
            const children = getCurrentChildGuestCount();
            const key = gbStatusKey();
            const status = key ? roomAvailabilityStatus[key] : null;
            if (!status) return 'We are still checking this room for your dates. Please try again in a moment.';
            if (!status.available && !isMixedCandidate(status)) {
                return String(status.message || status.error || 'This room cannot take your party on your selected dates.').replace(/<\/?[^>]+(>|$)/g, '');
            }
            const mixedActive = mixedInPlay();
            const room = getSelectedRoomData();
            const allocationOk = room ? (hasValidAllocation(total, room, children) || (mixedActive && children > 0 && total - children >= 1)) : false;
            if (!allocationOk) {
                return (room && getAllocationValidationMessage(total, room, children)) || 'This room cannot take your party. Please choose another room.';
            }
            if (mixedActive) {
                const m = getMixedForCurrent();
                if (!m) return 'We are still checking the rooms for your party. Please try again in a moment.';
                if (!m.ok) {
                    const left = Number(m.left_guests || 0);
                    if (left > 0 && m.why === 'children') return 'Add a room for the children, then continue.';
                    if (left > 0) return 'Add a room for the other ' + plural(left, 'guest') + ', then continue.';
                    return String(m.message || 'These rooms cannot take your party. Please choose other rooms.').replace(/<\/?[^>]+(>|$)/g, '');
                }
            }
            return '';
        }

        async function gbCheckStep(n) {
            gbClearErrors();
            if (n === 1) {
                const ci = gbVal('check_in_date');
                const co = gbVal('check_out_date');
                if (!ci) { gbFail(1, 'check_in_date', 'Please choose your check-in date.'); return false; }
                if (!co) { gbFail(1, 'check_out_date', 'Please choose your check-out date.'); return false; }
                if (co <= ci) { gbFail(1, 'check_out_date', 'Your check-out date must be after your check-in date.'); return false; }
                return true;
            }
            if (n === 2) {
                const guests = Number(gbVal('number_of_guests'));
                const rawKids = gbVal('child_guests').trim();
                const kids = rawKids === '' ? 0 : Number(rawKids);
                if (!Number.isInteger(guests) || guests < 1 || guests > 20) {
                    gbFail(2, 'number_of_guests', 'Please choose how many guests are staying.');
                    return false;
                }
                if (!Number.isInteger(kids) || kids < 0) {
                    gbFail(2, 'child_guests', 'Please enter the number of children as a whole number (0 or more).');
                    return false;
                }
                if (kids >= guests) {
                    gbFail(2, 'child_guests', 'At least 1 adult must stay: the number of children must be lower than the number of guests.');
                    return false;
                }
                return true;
            }
            if (n === 3) {
                if (!selectedRoomId) { gbFail(3, 'room_id', 'Please choose a room.'); return false; }
                const key = gbStatusKey();
                if (key && !roomAvailabilityStatus[key] && !availabilityCheckPending) performAvailabilityCheck();
                await gbWaitIdle(20000);
                if (gbCurrent !== 3) return false;
                const problem = gbRoomProblem();
                if (problem) { gbFail(3, 'room_id', problem); return false; }
                const j = await gbPriceCheck(3);
                if (!j) return false;
                if (!j.ok) { gbShowRefusal(j); return false; }
                return true;
            }
            if (n === 4) {
                const sec = gbStepEl(4);
                const needed = [
                    ['guest_name', 'Please enter your full name.'],
                    ['guest_email', 'Please enter your email address.'],
                    ['guest_phone', 'Please enter your phone number.']
                ];
                for (let i = 0; i < needed.length; i++) {
                    const input = document.getElementById(needed[i][0]);
                    if (!input) continue;
                    if (input.value.trim() === '') { gbFail(4, needed[i][0], needed[i][1]); return false; }
                    if (!input.checkValidity()) {
                        gbFail(4, needed[i][0], needed[i][0] === 'guest_email' ? 'Please enter a valid email address.' : (input.validationMessage || needed[i][1]));
                        return false;
                    }
                }
                // Anything else the browser would have refused (lengths, types) under the old required attributes.
                const bad = sec ? Array.from(sec.querySelectorAll('input, select, textarea')).find(function (f) { return !f.disabled && !f.checkValidity(); }) : null;
                if (bad) {
                    gbFail(4, bad.name || bad.id, bad.validationMessage || 'Please check this field.');
                    return false;
                }
                return true;
            }
            return gbQuoteState() === 'fresh';
        }

        // ── Moving between steps ──
        function gbShowStep(n, opts) {
            opts = opts || {};
            n = Math.min(Math.max(parseInt(n, 10) || 1, 1), 5);
            gbCurrent = n;
            if (n > gbReached) gbReached = n;
            for (let i = 1; i <= 5; i++) {
                const sec = gbStepEl(i);
                if (sec) sec.classList.toggle('is-active', i === n);
            }
            document.querySelectorAll('#gbStepBar [data-gb-go]').forEach(function (b) {
                const i = parseInt(b.getAttribute('data-gb-go'), 10);
                if (i === n) b.setAttribute('aria-current', 'step'); else b.removeAttribute('aria-current');
                b.classList.toggle('is-done', i < n);
                b.classList.toggle('is-reached', i > n && i <= gbReached);
                if (i > gbReached) b.setAttribute('aria-disabled', 'true'); else b.removeAttribute('aria-disabled');
            });
            if (opts.focus) {
                const sec = gbStepEl(n);
                if (sec) {
                    try { sec.focus({ preventScroll: true }); } catch (e) { sec.focus(); }
                }
                scrollToSection(document.getElementById('gbStepBar'));
            }
            if (n === 3) {
                refreshRoomCardPlansLocal();
                const key = gbStatusKey();
                if (key && !roomAvailabilityStatus[key] && !availabilityCheckPending) performAvailabilityCheck();
            }
            if (n === 5) {
                // Review asks for its price check here (or not: a refusal shown on Review is not asked again by itself),
                // so the automatic re-ask in gbSyncQuoteState must not fire for this same version of the form.
                gbAutoFp = gbFingerprint();
                updateSummary();
                if (!opts.noCheck) {
                    const state = gbQuoteState();
                    if (state !== 'fresh' && state !== 'pending') gbReviewCheck();
                }
            }
            gbSyncQuoteState();
        }

        function gbNext() {
            if (gbBusy || gbCurrent >= 5) return;
            const from = gbCurrent;
            const sec = gbStepEl(from);
            const btn = sec ? sec.querySelector('[data-gb-next]') : null;
            const label = btn ? btn.innerHTML : null;
            gbBusy = true;
            if (btn && from === 3) {
                btn.disabled = true;
                btn.setAttribute('aria-busy', 'true');
                btn.innerHTML = '<i class="fas fa-spinner fa-spin" aria-hidden="true"></i> Checking…';
            }
            const done = function () {
                gbBusy = false;
                if (btn && from === 3) {
                    btn.disabled = false;
                    btn.removeAttribute('aria-busy');
                    btn.innerHTML = label;
                }
            };
            gbCheckStep(from).then(function (ok) {
                done();
                if (ok && gbCurrent === from) {
                    gbReached = Math.max(gbReached, from + 1);
                    gbShowStep(from + 1, { focus: true });
                }
            }, done);
        }

        // Step bar: back freely; forward only to steps already reached, running every check on the way.
        function gbGo(target) {
            if (gbBusy || target === gbCurrent || target < 1 || target > 5) return;
            if (target < gbCurrent) { gbShowStep(target, { focus: true }); return; }
            if (target > gbReached) return;
            gbBusy = true;
            (async function () {
                const start = gbCurrent;
                for (let s = start; s < target; s++) {
                    const ok = await gbCheckStep(s);
                    if (!ok || gbCurrent !== start) return false;
                }
                return true;
            })().then(function (ok) {
                gbBusy = false;
                if (ok) gbShowStep(target, { focus: true });
            }, function () { gbBusy = false; });
        }

        document.addEventListener('DOMContentLoaded', function () {
            const form = gbForm();
            const bar = document.getElementById('gbStepBar');
            if (!form || !gbStepEl(1)) return;
            form.classList.add('js-steps');
            if (bar) bar.hidden = false;
            gbReady = true;

            form.addEventListener('click', function (e) {
                const next = e.target.closest('[data-gb-next]');
                if (next && form.contains(next)) { e.preventDefault(); gbNext(); return; }
                const back = e.target.closest('[data-gb-back]');
                if (back && form.contains(back)) { e.preventDefault(); if (!gbBusy && gbCurrent > 1) gbShowStep(gbCurrent - 1, { focus: true }); }
            });
            if (bar) {
                bar.addEventListener('click', function (e) {
                    const b = e.target.closest('[data-gb-go]');
                    if (!b) return;
                    e.preventDefault();
                    gbGo(parseInt(b.getAttribute('data-gb-go'), 10));
                });
            }

            // Any change makes the quote stale (the fingerprint differs); a guest's own edit clears the messages.
            ['input', 'change'].forEach(function (type) {
                form.addEventListener(type, function (e) {
                    if (e.isTrusted) gbClearErrors();
                    gbSyncQuoteState();
                });
            });
            const extra = document.getElementById('extraRoomInputs');
            if (extra && typeof MutationObserver !== 'undefined') {
                new MutationObserver(function () { gbSyncQuoteState(); }).observe(extra, { childList: true, subtree: true });
            }
            window.addEventListener('pageshow', function () {
                gbSubmitting = false;
                gbSyncQuoteState();
            });

            // Book: a normal form POST, only from Review with a fresh server quote. Enter elsewhere = Continue.
            form.addEventListener('submit', function (e) {
                if (gbCurrent === 5 && gbQuoteState() === 'fresh' && !gbSubmitting) {
                    gbSubmitting = true;
                    setTimeout(gbSyncQuoteState, 0);
                    return;
                }
                e.preventDefault();
                if (gbCurrent < 5 && !gbBusy) gbNext();
            });

            // Landing step: a refused no-JS submission opens the step of its error; a prefill opens the first
            // incomplete step (never past Room: rate plans and packages are chosen there).
            const posted = typeof postedBooking !== 'undefined' && postedBooking ? postedBooking : null;
            if (posted && typeof posted.error_step === 'number') {
                gbReached = 5;
                if (posted.error_step >= 1 && posted.error_step <= 5) {
                    gbFail(posted.error_step, posted.error_field || '', posted.error_message || '');
                } else {
                    const first = !gbVal('check_in_date') || !gbVal('check_out_date') ? 1 : (!selectedRoomId ? 3 : 4);
                    gbShowStep(first, { focus: false, noCheck: true });
                    gbShowRefusal({ ok: false, step: 0, message: posted.error_message || '' });
                }
                return;
            }
            let landing = 3;
            if (!gbVal('check_in_date') || !gbVal('check_out_date')) landing = 1;
            else if (typeof heroGuests === 'undefined' || heroGuests === null) landing = 2;
            gbReached = landing;
            gbShowStep(landing, { focus: false });
        });
