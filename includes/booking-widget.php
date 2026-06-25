<?php
/**
 * Booking Widget Component
 * Standalone booking form with glassmorphism styling
 * 
 * Usage: include 'includes/booking-widget.php';
 * Can be included on any page that needs booking functionality
 */

// Get base URL for form action - use BASE_URL constant which properly detects subdirectory
$base_url = defined('BASE_URL') ? BASE_URL : '';

// Check if booking system is enabled
if (!function_exists('isBookingEnabled')) {
    require_once __DIR__ . '/../includes/booking-functions.php';
}

if (!function_exists('renderSectionHeader')) {
    require_once __DIR__ . '/section-headers.php';
}

// Check if booking is enabled before showing widget
if (!isBookingEnabled()) {
    // Show disabled message
    echo renderBookingDisabledContent('widget');
    return;
}

// Fetch available room types from database
$widget_rooms_stmt = $pdo->query("SELECT id, name, max_guests, price_per_night, rooms_available, children_allowed, child_price_multiplier FROM rooms WHERE is_active = 1 ORDER BY display_order ASC");
$widget_rooms = $widget_rooms_stmt->fetchAll(PDO::FETCH_ASSOC);

// Get child price multiplier setting
$widget_child_multiplier = (float)getSetting('booking_child_price_multiplier', getSetting('child_guest_price_multiplier', 50));

// Calculate max guests across all rooms
$widget_max_guests = 1;
foreach ($widget_rooms as $room) {
    if ((int)$room['max_guests'] > $widget_max_guests) {
        $widget_max_guests = (int)$room['max_guests'];
    }
}
?>

<!-- Booking Section -->
<section class="booking-section landing-section" data-lazy-reveal>
    <div class="booking-widget-container">
        <div class="booking-widget__intro scroll-reveal">
            <?php renderSectionHeader('booking_widget', 'index', [
                'label' => 'Reserve',
                'title' => 'Begin Your Stay',
                'description' => 'Select your dates and preferences for a seamless luxury booking experience.'
            ], 'editorial-header section-header--editorial'); ?>
            <p class="booking-widget__meta">
                <i class="fas fa-shield-alt" aria-hidden="true"></i>
                Secure booking • Instant confirmation
            </p>
        </div>
        <form class="editorial-booking-form" action="<?php echo htmlspecialchars($base_url); ?>/booking.php" method="GET">
            <div class="editorial-booking-field">
                <label class="editorial-booking-label" for="check-in">Check In</label>
                <input type="date" class="editorial-booking-input" id="check-in" name="check_in" required min="<?php echo date('Y-m-d'); ?>">
            </div>
            <div class="editorial-booking-field">
                <label class="editorial-booking-label" for="check-out">Check Out</label>
                <input type="date" class="editorial-booking-input" id="check-out" name="check_out" required min="<?php echo date('Y-m-d', strtotime('+1 day')); ?>">
            </div>
            <div class="editorial-booking-field">
                <label class="editorial-booking-label" for="guests">Guests</label>
                <select class="editorial-booking-input" id="guests" name="guests">
                    <?php for ($i = 1; $i <= $widget_max_guests; $i++): ?>
                        <option value="<?php echo $i; ?>" <?php echo $i === 2 ? 'selected' : ''; ?>><?php echo $i; ?> Guest<?php echo $i > 1 ? 's' : ''; ?></option>
                    <?php endfor; ?>
                </select>
            </div>
            <div class="editorial-booking-field">
                <label class="editorial-booking-label" for="children">Children (under 12)</label>
                <select class="editorial-booking-input" id="children" name="children">
                    <option value="0" selected>0 Children</option>
                    <?php for ($i = 1; $i <= min($widget_max_guests - 1, 4); $i++): ?>
                        <option value="<?php echo $i; ?>"><?php echo $i; ?> Child<?php echo $i > 1 ? 'ren' : ''; ?></option>
                    <?php endfor; ?>
                </select>
            </div>
            <div class="editorial-booking-field">
                <label class="editorial-booking-label" for="room-type">Room Type</label>
                <select class="editorial-booking-input" id="room-type" name="room_type">
                    <option value="">Any Room</option>
                    <?php foreach ($widget_rooms as $room): ?>
                        <option value="<?php echo htmlspecialchars($room['name']); ?>" data-room-id="<?php echo $room['id']; ?>" data-max-guests="<?php echo $room['max_guests']; ?>" data-children-allowed="<?php echo $room['children_allowed']; ?>" data-child-multiplier="<?php echo $room['child_price_multiplier'] ?? $widget_child_multiplier; ?>">
                            <?php echo htmlspecialchars($room['name']); ?> 
                            <?php if ($room['rooms_available'] > 1): ?>(<?php echo $room['rooms_available']; ?> available)<?php endif; ?>
                            - <?php echo getSetting('currency_symbol'); ?><?php echo number_format($room['price_per_night'], 0); ?>/night
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <button type="submit" class="editorial-booking-submit" id="editorialSubmitBtn">
                <span>Check Availability</span>
                <i class="fas fa-arrow-right" aria-hidden="true"></i>
            </button>
            <div class="widget-availability-hint" id="widgetAvailabilityHint" aria-live="polite"></div>
        </form>
    </div>
</section>

<script>
(function() {
    const guestsSelect = document.getElementById('guests');
    const childrenSelect = document.getElementById('children');
    const roomTypeSelect = document.getElementById('room-type');
    const checkInInput = document.getElementById('check-in');
    const checkOutInput = document.getElementById('check-out');
    const availabilityHint = document.getElementById('widgetAvailabilityHint');
    const submitBtn = document.getElementById('editorialSubmitBtn');
    const widgetCurrencySymbol = <?php echo json_encode(getSetting('currency_symbol')); ?>;
    
    // Room data from PHP
    const roomData = <?php echo json_encode(array_map(function($r) use ($widget_child_multiplier) {
        return [
            'id' => (int)$r['id'],
            'name' => $r['name'],
            'max_guests' => (int)$r['max_guests'],
            'price_per_night' => (float)$r['price_per_night'],
            'children_allowed' => (int)$r['children_allowed'],
            'child_multiplier' => (float)($r['child_price_multiplier'] ?? $widget_child_multiplier),
            'rooms_available' => (int)$r['rooms_available']
        ];
    }, $widget_rooms)); ?>;
    
    // Track availability status per room
    let availabilityStatus = {};
    let availabilityCheckTimer = null;
    
    /**
     * Check availability for all rooms when dates are selected
     */
    function checkAllRoomsAvailability() {
        const checkIn = checkInInput?.value;
        const checkOut = checkOutInput?.value;
        const children = parseInt(childrenSelect?.value || '0', 10);
        
        if (!checkIn || !checkOut) {
            resetRoomOptions();
            return;
        }
        
        // Validate dates
        const checkInDate = new Date(checkIn);
        const checkOutDate = new Date(checkOut);
        
        if (checkOutDate <= checkInDate) {
            return;
        }
        
        // Clear previous timer
        if (availabilityCheckTimer) {
            clearTimeout(availabilityCheckTimer);
        }
        
        // Debounce the availability check
        availabilityCheckTimer = setTimeout(() => {
            // Check availability for each room
            roomData.forEach(room => {
                checkSingleRoomAvailability(room.id, checkIn, checkOut, children);
            });
        }, 300);
    }
    
    /**
     * Check availability for a single room via AJAX
     */
    function checkSingleRoomAvailability(roomId, checkIn, checkOut, children) {
        const totalGuests = Math.max(1, parseInt(guestsSelect?.value || '1', 10));
        const adultGuests = Math.max(1, totalGuests - children);
        const url = `check-availability.php?room_id=${roomId}&check_in=${checkIn}&check_out=${checkOut}&number_of_guests=${totalGuests}&adult_guests=${adultGuests}&child_guests=${children}`;
        
        fetch(url)
            .then(response => response.json())
            .then(data => {
                const statusKey = `${roomId}_${checkIn}_${checkOut}_${children}`;
                availabilityStatus[statusKey] = data;
                
                // Update the room option in the dropdown
                updateRoomOptionAvailability(roomId, data);
                
                // Update the hint if a room is selected
                const selectedOption = roomTypeSelect.options[roomTypeSelect.selectedIndex];
                if (selectedOption && parseInt(selectedOption.dataset.roomId) === roomId) {
                    updateAvailabilityHintForSelectedRoom(data);
                }
            })
            .catch(error => {
                console.error('Availability check failed for room', roomId, error);
                showHint('<i class="fas fa-exclamation-circle"></i> Could not check availability. Please try again or <a href="contact.php">contact us</a>.', 'error');
            });
    }
    
    /**
     * Update room option in dropdown based on availability
     */
    function updateRoomOptionAvailability(roomId, availabilityData) {
        const option = roomTypeSelect.querySelector(`option[data-room-id="${roomId}"]`);
        if (!option) return;
        
        const room = roomData.find(item => Number(item.id) === Number(roomId));
        const children = parseInt(childrenSelect?.value || '0', 10);
        const remaining = children > 0
            ? Math.max(0, Number(availabilityData.child_eligible_remaining_rooms ?? availabilityData.remaining_rooms ?? 0))
            : Math.max(0, Number(availabilityData.remaining_rooms || 0));
        const countLabel = children > 0 ? 'child-ready room' : 'room';
        if (room) {
            const price = Number(room.price_per_night || 0).toLocaleString();
            option.textContent = `${room.name} (${remaining} ${countLabel}${remaining === 1 ? '' : 's'} left) - ${widgetCurrencySymbol}${price}/night`;
        }
        
        if (availabilityData.available) {
            // Room is available
            option.disabled = false;
            option.style.color = '';
        } else {
            // Room is unavailable
            option.disabled = true;
            option.style.color = '#999';
            
            // If this room was selected, show warning
            if (option.selected) {
                showHint(`<i class="fas fa-calendar-times"></i> <strong>${option.value}</strong> is not available for the selected dates. Please choose a different room or dates.`, 'warning');
            }
        }
    }
    
    /**
     * Update availability hint for selected room
     */
    function updateAvailabilityHintForSelectedRoom(availabilityData) {
        if (!availabilityData) return;
        
        if (availabilityData.available) {
            // Show success message briefly
            const roomName = roomTypeSelect.options[roomTypeSelect.selectedIndex]?.value || 'Room';
            const children = parseInt(childrenSelect?.value || '0', 10);
            const remaining = children > 0
                ? Math.max(0, Number(availabilityData.child_eligible_remaining_rooms ?? availabilityData.remaining_rooms ?? 0))
                : Math.max(0, Number(availabilityData.remaining_rooms || 0));
            const label = children > 0 ? 'child-ready room' : 'room';
            showHint(`<i class="fas fa-check-circle" style="color: #28a745;"></i> <strong>${roomName}</strong> is available for ${availabilityData.nights || 'your selected'} nights. ${remaining} ${label}${remaining === 1 ? '' : 's'} left.`, 'success');
            
            // Update submit button
            if (submitBtn) {
                submitBtn.disabled = false;
                submitBtn.style.opacity = '1';
            }
        } else {
            showHint(`<i class="fas fa-calendar-times"></i> This room is not available: ${availabilityData.message || 'Fully booked for selected dates'}`, 'warning');
            
            // Disable submit button
            if (submitBtn) {
                submitBtn.disabled = true;
                submitBtn.style.opacity = '0.6';
            }
        }
    }
    
    /**
     * Reset all room options to default state
     */
    function resetRoomOptions() {
        const options = roomTypeSelect.querySelectorAll('option[data-room-id]');
        options.forEach(option => {
            option.disabled = false;
            option.style.color = '';
            const roomId = Number(option.dataset.roomId || 0);
            const room = roomData.find(item => Number(item.id) === roomId);
            if (room) {
                const available = Number(room.rooms_available || 0);
                const price = Number(room.price_per_night || 0).toLocaleString();
                option.textContent = `${room.name}${available > 1 ? ` (${available} available)` : ''} - ${widgetCurrencySymbol}${price}/night`;
            }
        });
    }
    
    /**
     * Show hint with type (success, warning, info)
     */
    function showHint(message, type) {
        if (!availabilityHint) return;
        availabilityHint.innerHTML = message;
        availabilityHint.className = `widget-availability-hint widget-availability-hint--${type || 'info'}`;
    }
    
    function findCompatibleRooms(guests, children) {
        const checkIn = checkInInput?.value;
        const checkOut = checkOutInput?.value;
        return roomData.filter(room => {
            if (room.max_guests < guests) return false;
            if (children > 0 && room.children_allowed !== 1) return false;
            if (checkIn && checkOut) {
                const statusKey = `${room.id}_${checkIn}_${checkOut}_${children}`;
                const status = availabilityStatus[statusKey];
                if (status && !status.available) return false;
            }
            return true;
        });
    }
    
    function hideHint() {
        if (!availabilityHint) return;
        availabilityHint.className = 'widget-availability-hint';
        availabilityHint.innerHTML = '';
    }
    
    function checkCompatibility() {
        const totalGuests = parseInt(guestsSelect.value) || 1;
        const children = parseInt(childrenSelect.value) || 0;
        const selectedRoomOption = roomTypeSelect.options[roomTypeSelect.selectedIndex];
        const selectedRoomName = selectedRoomOption ? selectedRoomOption.value : '';
        
        // If no specific room selected, just check if any room can accommodate
        if (!selectedRoomName) {
            const compatibleRooms = findCompatibleRooms(totalGuests, children);
            if (compatibleRooms.length === 0 && (children > 0 || totalGuests > 3)) {
                const maxCapacity = Math.max(...roomData.map(r => r.max_guests));
                const roomsWithChildren = roomData.filter(r => r.children_allowed === 1);
                
                if (children > 0 && roomsWithChildren.length === 0) {
                    showHint('<i class="fas fa-exclamation-circle"></i> No rooms currently allow children. Please contact us for family arrangements.', 'warning');
                } else if (children > 0 && roomsWithChildren.length > 0) {
                    const roomNames = roomsWithChildren.map(r => r.name).join(', ');
                    showHint(`<i class="fas fa-info-circle"></i> For bookings with children, consider: <strong>${roomNames}</strong>`, 'info');
                } else if (totalGuests > maxCapacity) {
                    showHint(`<i class="fas fa-info-circle"></i> Maximum room capacity is ${maxCapacity} guests. For larger groups, please book multiple rooms.`, 'info');
                } else {
                    hideHint();
                }
            } else {
                hideHint();
            }
            return;
        }
        
        // Find the selected room's data
        const selectedRoom = roomData.find(r => r.name === selectedRoomName);
        if (!selectedRoom) {
            hideHint();
            return;
        }
        
        const issues = [];
        
        // Check capacity
        if (totalGuests > selectedRoom.max_guests) {
            issues.push(`only accommodates ${selectedRoom.max_guests} guest${selectedRoom.max_guests > 1 ? 's' : ''}`);
        }
        
        // Check children policy
        if (children > 0 && selectedRoom.children_allowed === 0) {
            issues.push('does not allow children');
        }
        
        if (issues.length > 0) {
            // Find compatible rooms
            const compatibleRooms = findCompatibleRooms(totalGuests, children);
            
            let hintHtml = `<i class="fas fa-exclamation-circle"></i> <strong>${selectedRoom.name}</strong> ${issues.join(' and ')}.`;
            
            if (compatibleRooms.length > 0) {
                const roomSuggestions = compatibleRooms.map(r => {
                    const features = [];
                    if (r.max_guests >= totalGuests) features.push(`up to ${r.max_guests} guests`);
                    if (children > 0 && r.children_allowed === 1) features.push('children welcome');
                    return `<strong>${r.name}</strong> (${features.join(', ')})`;
                }).join(', ');
                
                hintHtml += `<br><i class="fas fa-lightbulb" style="margin-top: 6px; display: inline-block;"></i> Consider: ${roomSuggestions}`;
            } else {
                hintHtml += '<br><small>No single room matches your requirements. Consider booking multiple rooms or contact us for assistance.</small>';
            }
            
            showHint(hintHtml, 'warning');
        } else {
            hideHint();
        }
    }
    
    function updateChildrenOptions() {
        const totalGuests = parseInt(guestsSelect.value) || 1;
        const selectedRoomOption = roomTypeSelect.options[roomTypeSelect.selectedIndex];
        const childrenAllowed = selectedRoomOption && selectedRoomOption.value !== '' 
            ? parseInt(selectedRoomOption.dataset.childrenAllowed || '1') 
            : 1;
        
        // Get max children (at least 1 adult required)
        const maxChildren = Math.max(0, totalGuests - 1);
        
        // Update children dropdown
        const currentChildren = parseInt(childrenSelect.value) || 0;
        childrenSelect.innerHTML = '<option value="0">0 Children</option>';
        
        if (!childrenAllowed) {
            // Children not allowed for this room
            childrenSelect.value = '0';
            childrenSelect.disabled = true;
        } else {
            childrenSelect.disabled = false;
            
            for (let i = 1; i <= Math.min(maxChildren, 4); i++) {
                const option = document.createElement('option');
                option.value = i;
                option.textContent = i + (i === 1 ? ' Child' : ' Children');
                childrenSelect.appendChild(option);
            }
            
            // Reset if current value exceeds max
            if (currentChildren > maxChildren) {
                childrenSelect.value = '0';
            } else if (currentChildren > 0) {
                childrenSelect.value = currentChildren.toString();
            }
        }
        
        // Check compatibility and show hints
        checkCompatibility();
    }
    
    function updateGuestsForRoom() {
        const selectedRoomOption = roomTypeSelect.options[roomTypeSelect.selectedIndex];
        
        if (selectedRoomOption && selectedRoomOption.value !== '') {
            const maxGuests = parseInt(selectedRoomOption.dataset.maxGuests) || 3;
            const currentGuests = parseInt(guestsSelect.value) || 1;
            
            // Rebuild guests options based on room capacity
            guestsSelect.innerHTML = '';
            for (let i = 1; i <= maxGuests; i++) {
                const option = document.createElement('option');
                option.value = i;
                option.textContent = i + (i === 1 ? ' Guest' : ' Guests');
                if (i === currentGuests || (currentGuests > maxGuests && i === maxGuests)) {
                    option.selected = true;
                }
                guestsSelect.appendChild(option);
            }
        }
        
        updateChildrenOptions();
    }
    
    // Event listeners for dates - trigger availability check
    if (checkInInput) {
        checkInInput.addEventListener('change', function() {
            // Set minimum checkout date to day after checkin
            if (checkOutInput && this.value) {
                const minCheckOut = new Date(this.value);
                minCheckOut.setDate(minCheckOut.getDate() + 1);
                checkOutInput.min = minCheckOut.toISOString().split('T')[0];
                
                // Clear checkout if it's now invalid
                if (checkOutInput.value && new Date(checkOutInput.value) <= new Date(this.value)) {
                    checkOutInput.value = '';
                }
            }
            checkAllRoomsAvailability();
        });
    }
    
    if (checkOutInput) {
        checkOutInput.addEventListener('change', function() {
            checkAllRoomsAvailability();
        });
    }
    
    if (guestsSelect) {
        guestsSelect.addEventListener('change', updateChildrenOptions);
    }
    
    if (childrenSelect) {
        childrenSelect.addEventListener('change', function() {
            checkCompatibility();
            checkAllRoomsAvailability();
        });
    }
    
    if (roomTypeSelect) {
        roomTypeSelect.addEventListener('change', function() {
            updateGuestsForRoom();
            const checkIn = checkInInput?.value;
            const checkOut = checkOutInput?.value;
            const selectedOption = roomTypeSelect.options[roomTypeSelect.selectedIndex];
            if (selectedOption && selectedOption.dataset.roomId) {
                if (checkIn && checkOut) {
                    checkSingleRoomAvailability(parseInt(selectedOption.dataset.roomId), checkIn, checkOut, parseInt(childrenSelect?.value || '0', 10));
                }
            } else {
                // "Any Room" — always allow submission
                if (submitBtn) {
                    submitBtn.disabled = false;
                    submitBtn.style.opacity = '1';
                }
                hideHint();
            }
        });
    }
    
    // Add form validation to block invalid submission
    const editorialForm = document.querySelector('.editorial-booking-form');
    if (editorialForm) {
        editorialForm.addEventListener('submit', function(e) {
            const checkIn = document.getElementById('check-in')?.value;
            const checkOut = document.getElementById('check-out')?.value;
            const guests = parseInt(guestsSelect?.value || '0', 10);
            const children = parseInt(childrenSelect?.value || '0', 10);
            const selectedRoomOption = roomTypeSelect?.options[roomTypeSelect.selectedIndex];
            const selectedRoomName = selectedRoomOption ? selectedRoomOption.value : '';
            
            // Validate dates
            if (!checkIn || !checkOut) {
                e.preventDefault();
                showHint('<i class="fas fa-exclamation-circle"></i> Please select check-in and check-out dates.', 'warning');
                return false;
            }
            
            const checkInDate = new Date(checkIn);
            const checkOutDate = new Date(checkOut);
            const today = new Date();
            today.setHours(0, 0, 0, 0);
            
            // Check if check-in is in the past
            if (checkInDate < today) {
                e.preventDefault();
                showHint('<i class="fas fa-exclamation-circle"></i> Check-in date cannot be in the past.', 'warning');
                return false;
            }
            
            // Check if check-out is before check-in
            if (checkOutDate <= checkInDate) {
                e.preventDefault();
                showHint('<i class="fas fa-exclamation-circle"></i> Check-out date must be after check-in date.', 'warning');
                return false;
            }
            
            // Validate guests
            if (!guests || guests < 1) {
                e.preventDefault();
                showHint('<i class="fas fa-exclamation-circle"></i> Please select at least 1 guest.', 'warning');
                return false;
            }
            
            // Validate at least 1 adult
            const adults = guests - children;
            if (adults < 1) {
                e.preventDefault();
                showHint('<i class="fas fa-exclamation-circle"></i> At least 1 adult is required for every booking.', 'warning');
                return false;
            }
            
            // If specific room is selected, validate compatibility
            if (selectedRoomName) {
                const selectedRoom = roomData.find(r => r.name === selectedRoomName);
                if (selectedRoom) {
                    // Check capacity
                    if (guests > selectedRoom.max_guests) {
                        e.preventDefault();
                        showHint(`<i class="fas fa-exclamation-circle"></i> <strong>${selectedRoom.name}</strong> only accommodates ${selectedRoom.max_guests} guests. Please select a different room or reduce guest count.`, 'warning');
                        return false;
                    }
                    
                    // Check children policy
                    if (children > 0 && selectedRoom.children_allowed === 0) {
                        e.preventDefault();
                        showHint(`<i class="fas fa-exclamation-circle"></i> <strong>${selectedRoom.name}</strong> does not allow children. Please select a different room or remove children from your booking.`, 'warning');
                        return false;
                    }

                    const statusKey = `${selectedRoom.id}_${checkIn}_${checkOut}_${children}`;
                    const roomStatus = availabilityStatus[statusKey];
                    if (roomStatus && !roomStatus.available) {
                        e.preventDefault();
                        showHint(`<i class="fas fa-calendar-times"></i> <strong>${selectedRoom.name}</strong> is not available for those dates. Please choose another room or dates.`, 'warning');
                        return false;
                    }
                }
            }
            
            // All validations passed - allow submission
            return true;
        });
    }
    
    // Initialize on page load
    document.addEventListener('DOMContentLoaded', function() {
        updateChildrenOptions();
    });
    
    // Also run immediately in case DOMContentLoaded already fired
    if (document.readyState === 'complete' || document.readyState === 'interactive') {
        updateChildrenOptions();
    }
})();
</script>
