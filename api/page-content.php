<?php
/**
 * SPA Navigation API Endpoint
 * Returns page content as JSON for dynamic loading without full page reload
 */

// Set JSON header first
header('Content-Type: application/json');

// Error handling
error_reporting(E_ALL);
ini_set('display_errors', 0);

try {
    // Load database configuration
    require_once __DIR__ . '/../config/database.php';
    
    // Get the requested page
    $page = $_GET['page'] ?? '';
    
    // Define allowed pages for SPA navigation
    $allowed_pages = [
        'index' => 'index.php',
        'room' => 'room.php',
        'restaurant' => 'restaurant.php',
        'events' => 'events.php',
        'gym' => 'gym.php',
        'conference' => 'conference.php',
        'rooms-showcase' => 'rooms-showcase.php',
        'rooms-gallery' => 'rooms-gallery.php',
        'contact-us' => 'contact-us.php',
        'guest-services' => 'guest-services.php'
    ];
    
    // Validate page parameter
    if (empty($page) || !isset($allowed_pages[$page])) {
        http_response_code(404);
        echo json_encode(['error' => 'Page not found', 'allowed' => array_keys($allowed_pages)]);
        exit;
    }
    
    // Get the file path
    $file_path = __DIR__ . '/../' . $allowed_pages[$page];
    
    // Check if file exists
    if (!file_exists($file_path)) {
        http_response_code(404);
        echo json_encode(['error' => 'File not found']);
        exit;
    }
    
    // Handle room.php with slug parameter
    if ($page === 'room' && isset($_GET['slug'])) {
        $_GET['room'] = $_GET['slug'];
    }
    
    // Define API_REQUEST constant so included pages can detect API calls
    define('API_REQUEST', true);
    
    // Set the current page for hero.php to use during SPA navigation
    $_SERVER['API_CURRENT_PAGE'] = $page;
    
    // Start output buffering to capture the page content
    ob_start();
    
    // Include the page file - this will execute all PHP and output HTML
    include $file_path;
    
    // Get the full HTML content
    $full_html = ob_get_clean();
    
    // Extract the main content from the HTML
    $main_content = extractMainContent($full_html);
    
    // Extract the page title
    $page_title = extractPageTitle($full_html);
    
    // Extract hero data if present
    $hero_data = extractHeroData($full_html);
    
    // Return JSON response
    echo json_encode([
        'success' => true,
        'html' => $main_content,
        'title' => $page_title,
        'hero' => $hero_data,
        'page' => $page
    ]);
    
} catch (Exception $e) {
    error_log('SPA Navigation API Error: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['error' => 'Internal server error', 'message' => $e->getMessage()]);
}

/**
 * Extract the main content area from full HTML.
 *
 * Returns: hero section (if outside <main>) + full <main>…</main> element.
 * Returning the full <main> element (not just its innerHTML) preserves the
 * outer tag so the SPA swap target (#spa-content) gets a proper DOM structure.
 */
function extractMainContent($html) {
    $heroContent = '';
    $mainContent = '';

    // ── 1. Capture anything that sits between </header> and <main> ─────────
    // Matches our reusable hero.php output which uses class="hero hero--passalacqua …"
    // (previous code only matched the old "editorial-hero" class — now matches any .hero)
    if (preg_match('/<\/header>\s*(.*?)\s*<main/is', $html, $heroMatch)) {
        $preMain = trim($heroMatch[1]);
        // Include only if it actually contains a hero section
        if (!empty($preMain) && preg_match('/<section[^>]*\bclass="[^"]*\bhero\b/i', $preMain)) {
            $heroContent = $preMain;
        }
    }

    // ── 2. Capture the full <main> element (including outer tags) ──────────
    // Using the full element (not just innerHTML) so CSS rules that target
    // `main`, `main > *`, etc. continue to work after the SPA swap.
    if (preg_match('/<main[^>]*>.*?<\/main>/is', $html, $matches)) {
        $mainContent = $matches[0]; // full element

        // If <main> already contains a hero, drop the external one to avoid duplication
        if (preg_match('/<section[^>]*\bclass="[^"]*\bhero\b/i', $mainContent)) {
            $heroContent = '';
        }
    }

    // ── 3. Return combined result ──────────────────────────────────────────
    if ($heroContent || $mainContent) {
        return $heroContent . $mainContent;
    }

    // ── 4. Fallback: everything between </header> and <footer ─────────────
    if (preg_match('/<\/header>\s*(.*?)\s*<footer/is', $html, $matches)) {
        return trim($matches[1]);
    }

    // ── 5. Last resort: body minus header and footer ──────────────────────
    if (preg_match('/<body[^>]*>(.*?)<\/body>/is', $html, $matches)) {
        $body = $matches[1];
        $body = preg_replace('/<header[^>]*>.*?<\/header>/is', '', $body);
        $body = preg_replace('/<footer[^>]*>.*?<\/footer>/is', '', $body);
        return $body;
    }

    return $html;
}

/**
 * Extract the page title from HTML
 */
function extractPageTitle($html) {
    if (preg_match('/<title[^>]*>(.*?)<\/title>/is', $html, $matches)) {
        return trim(strip_tags($matches[1]));
    }
    return '';
}

/**
 * Extract hero section data for dynamic hero updates
 */
function extractHeroData($html) {
    $hero = [];
    
    // Try to extract hero section class
    if (preg_match('/<section[^>]*class="[^"]*hero[^"]*"[^>]*>/i', $html, $matches)) {
        $hero['hasHero'] = true;
    } else {
        $hero['hasHero'] = false;
    }
    
    // Try to extract hero title
    if (preg_match('/<h1[^>]*class="[^"]*hero-title[^"]*"[^>]*>(.*?)<\/h1>/is', $html, $matches)) {
        $hero['title'] = trim(strip_tags($matches[1]));
    }
    
    // Try to extract hero subtitle
    if (preg_match('/<p[^>]*class="[^"]*hero-subtitle[^"]*"[^>]*>(.*?)<\/p>/is', $html, $matches)) {
        $hero['subtitle'] = trim(strip_tags($matches[1]));
    }
    
    // Try to extract hero background image
    if (preg_match('/style="[^"]*background-image:\s*url\([\'"]?([^\'"\)]+)[\'"]?\)/i', $html, $matches)) {
        $hero['backgroundImage'] = $matches[1];
    }
    
    return $hero;
}
