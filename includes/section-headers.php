<?php
/**
 * Section Headers Helper Functions
 * Hotel Website - Dynamic Section Headers Management
 */

/**
 * Get section header from database
 * 
 * @param string $section_key Unique section key (e.g., 'home_rooms', 'restaurant_menu')
 * @param string $page Page identifier (e.g., 'index', 'restaurant')
 * @param array $fallback Fallback values if section not found: ['label' => '', 'subtitle' => '', 'title' => '', 'description' => '']
 * @return array Section header data
 */
function getSectionHeader($section_key, $page = 'global', $fallback = []) {
    global $pdo;
    
    // Default fallback structure
    $default_fallback = [
        'label' => '',
        'subtitle' => '',
        'title' => 'Section Title',
        'description' => ''
    ];
    
    $fallback = array_merge($default_fallback, $fallback);
    
    try {
        $stmt = $pdo->prepare("
            SELECT section_label, section_subtitle, section_title, section_description 
            FROM section_headers 
            WHERE section_key = ? AND page = ? AND is_active = 1
            LIMIT 1
        ");
        $stmt->execute([$section_key, $page]);
        $header = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if ($header) {
            return [
                'label' => $header['section_label'] ?? $fallback['label'],
                'subtitle' => $header['section_subtitle'] ?? $fallback['subtitle'],
                'title' => $header['section_title'] ?? $fallback['title'],
                'description' => $header['section_description'] ?? $fallback['description']
            ];
        }
        
        // If not found with specific page, try global
        if ($page !== 'global') {
            $stmt->execute([$section_key, 'global']);
            $header = $stmt->fetch(PDO::FETCH_ASSOC);
            
            if ($header) {
                return [
                    'label' => $header['section_label'] ?? $fallback['label'],
                    'subtitle' => $header['section_subtitle'] ?? $fallback['subtitle'],
                    'title' => $header['section_title'] ?? $fallback['title'],
                    'description' => $header['section_description'] ?? $fallback['description']
                ];
            }
        }
        
        // Return fallback if no header found
        return $fallback;
        
    } catch (PDOException $e) {
        error_log("Error fetching section header: " . $e->getMessage());
        return $fallback;
    }
}

/**
 * Generate semantic ID for section header based on section key and page
 *
 * @param string $section_key Unique section key (e.g., 'home_rooms', 'gym_wellness')
 * @param string $page Page identifier (e.g., 'index', 'gym', 'restaurant')
 * @return string Generated ID attribute value
 */
function generateSectionHeaderId($section_key, $page) {
    // Mapping of section keys to semantic IDs
    $id_mapping = [
        // Index page
        'home_rooms' => 'rooms-header',
        'home_facilities' => 'facilities-header',
        'home_testimonials' => 'testimonials-header',
        'hotel_gallery' => 'gallery-header',
        'hotel_reviews' => 'reviews-header',
        'upcoming_events' => 'upcoming-events-header',
        'booking_widget' => 'booking-header',
        // Events page
        'events_overview' => 'events-header',
        // Gym page
        'gym_wellness' => 'wellness-header',
        'gym_facilities' => 'gym-facilities-header',
        'gym_classes' => 'classes-header',
        'gym_training' => 'training-header',
        'gym_packages' => 'packages-header',
        // Restaurant page
        'restaurant_menu' => 'menu-header',
        'restaurant_gallery' => 'restaurant-gallery-header',
        // Conference page
        'conference_overview' => 'conference-header',
        // Guest services page
        'guest_services_main' => 'guest-services-header',
        // Contact page
        'contact_main' => 'contact-header',
    ];
    
    // Return mapped ID if exists
    if (isset($id_mapping[$section_key])) {
        return $id_mapping[$section_key];
    }
    
    // Fallback: generate ID from section key
    // Remove page prefix if present
    $id = preg_replace('/^' . preg_quote($page, '/') . '_/', '', $section_key);
    // Convert underscores to hyphens
    $id = str_replace('_', '-', $id);
    // Append -header suffix
    $id = $id . '-header';
    
    return $id;
}

/**
 * Render section header HTML
 *
 * @param string $section_key Unique section key
 * @param string $page Page identifier
 * @param array $fallback Fallback values
 * @param string $additional_classes Additional CSS classes for section-header div
 * @return void Outputs HTML directly
 */
function renderSectionHeader($section_key, $page = 'global', $fallback = [], $additional_classes = '') {
    $header = getSectionHeader($section_key, $page, $fallback);
    
    $classes = 'section-header';
    if (!empty($additional_classes)) {
        $classes .= ' ' . $additional_classes;
    }
    
    // Generate semantic ID based on section key
    $id = generateSectionHeaderId($section_key, $page);
    
    echo '<div class="' . htmlspecialchars($classes) . '"' . ($id ? ' id="' . htmlspecialchars($id) . '"' : '') . '>';
    
    if (!empty($header['label'])) {
        echo '<span class="section-header__label">' . htmlspecialchars($header['label']) . '</span>';
    }
    
    if (!empty($header['subtitle'])) {
        echo '<p class="section-header__subtitle">' . htmlspecialchars($header['subtitle']) . '</p>';
    }
    
    echo '<h2 class="section-header__title">' . htmlspecialchars($header['title']) . '</h2>';
    
    if (!empty($header['description'])) {
        echo '<p class="section-header__description">' . htmlspecialchars($header['description']) . '</p>';
    }
    
    echo '</div>';
}

/**
 * Get all section headers for a specific page
 * Useful for page-specific admin management
 * 
 * @param string $page Page identifier
 * @return array Array of section headers
 */
function getPageSectionHeaders($page) {
    global $pdo;
    
    try {
        $stmt = $pdo->prepare("
            SELECT * FROM section_headers 
            WHERE page = ? OR page = 'global'
            ORDER BY display_order ASC, section_title ASC
        ");
        $stmt->execute([$page]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (PDOException $e) {
        error_log("Error fetching page section headers: " . $e->getMessage());
        return [];
    }
}

/**
 * Update section header in database
 * 
 * @param string $section_key Section key
 * @param string $page Page identifier
 * @param array $data Header data to update
 * @return bool Success status
 */
function updateSectionHeader($section_key, $page, $data) {
    global $pdo;
    
    try {
        $stmt = $pdo->prepare("
            UPDATE section_headers 
            SET section_label = ?,
                section_subtitle = ?,
                section_title = ?,
                section_description = ?,
                updated_at = NOW()
            WHERE section_key = ? AND page = ?
        ");
        
        return $stmt->execute([
            $data['label'] ?? '',
            $data['subtitle'] ?? '',
            $data['title'] ?? '',
            $data['description'] ?? '',
            $section_key,
            $page
        ]);
    } catch (PDOException $e) {
        error_log("Error updating section header: " . $e->getMessage());
        return false;
    }
}
