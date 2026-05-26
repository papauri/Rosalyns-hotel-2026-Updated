<?php
$siteName = '';
if (function_exists('getSetting')) {
    $siteName = getSetting('site_name') ?? '';
}

// Auto-detect current page slug from filename
$page_slug = '';
if (isset($_SERVER['SCRIPT_FILENAME'])) {
    $page_slug = strtolower(pathinfo($_SERVER['SCRIPT_FILENAME'], PATHINFO_FILENAME));
    $page_slug = str_replace('_', '-', $page_slug);
}

// Fetch loader subtext from database
$loaderSubtext = '';
if (function_exists('getPageLoader') && $page_slug) {
    $loaderSubtext = getPageLoader($page_slug);
}

// Build page loader subtext mapping for client-side navigation
// This allows the loader to show the destination page's subtext during navigation
$loaderSubtextMap = [];
if (function_exists('getPageLoader')) {
    // Map of ALL page slugs to their loader subtext
    // Include all possible destinations to prevent fallback to source page subtext
    $commonPages = [
        'index', 'home',
        'rooms-gallery', 'rooms-showcase', 'room',
        'restaurant',
        'events',
        'gym',
        'conference',
        'booking', 'check-availability', 'booking-confirmation', 'booking-lookup',
        'submit-review', 'review-confirmation'
    ];
    foreach ($commonPages as $slug) {
        $subtext = getPageLoader($slug);
        // Include empty strings so destination is always tracked in map
        // This prevents fallback to source page's subtext
        $loaderSubtextMap[$slug] = $subtext !== false ? $subtext : '';
    }
}
?>
<!-- Elegant Page Loader -->
<div id="page-loader" class="loader loader--active">
    <div class="loader__content">
        <div class="loader__spinner">
            <div class="spinner-ring"></div>
            <div class="spinner-ring"></div>
            <div class="spinner-ring"></div>
            <div class="spinner-center"></div>
        </div>
        <div class="loader__title"><?php echo htmlspecialchars($siteName); ?></div>
        <div class="loader__subtitle" data-default-subtext="<?php echo htmlspecialchars($loaderSubtext); ?>"><?php echo htmlspecialchars($loaderSubtext); ?></div>
        <div class="loader__progress">
            <div class="loader__progress-bar"></div>
        </div>
    </div>
</div>

<script>
(function() {
    'use strict';
    
    // Page loader subtext mapping for client-side navigation
    // This allows showing the destination page's subtext during navigation
    window.PAGE_LOADER_SUBTEXTS = <?php echo json_encode($loaderSubtextMap); ?>;
    
    // Normalize page slug for consistent lookup
    function normalizePageSlug(pageSlug) {
        if (!pageSlug) return '';
        // Remove .php extension, trailing slashes, and convert to lowercase
        return pageSlug
            .replace(/\.php$/, '')
            .replace(/\/$/, '')
            .toLowerCase();
    }
    
    // Get subtext for a page slug, with fallback
    function getLoaderSubtext(pageSlug) {
        if (!pageSlug) return null;
        
        const normalizedSlug = normalizePageSlug(pageSlug);
        
        // Direct match
        if (window.PAGE_LOADER_SUBTEXTS && window.PAGE_LOADER_SUBTEXTS[normalizedSlug]) {
            return window.PAGE_LOADER_SUBTEXTS[normalizedSlug];
        }
        
        // Handle home/index variations
        if ((normalizedSlug === '' || normalizedSlug === 'home') && window.PAGE_LOADER_SUBTEXTS && window.PAGE_LOADER_SUBTEXTS['index']) {
            return window.PAGE_LOADER_SUBTEXTS['index'];
        }
        
        return null;
    }
    
    // Update loader subtext to show destination page
    function updateLoaderSubtext(destinationPage) {
        const subtitleEl = document.querySelector('.loader__subtitle');
        if (!subtitleEl) return;
        
        const subtext = getLoaderSubtext(destinationPage);
        if (subtext && subtext !== '') {
            // Use destination page's subtext
            subtitleEl.textContent = subtext;
        } else {
            // Use generic loading message instead of source page's subtext
            // This prevents showing the wrong page's subtext during navigation
            subtitleEl.textContent = 'Loading...';
        }
    }
    
    // Expose functions globally for navigation scripts
    window.getLoaderSubtext = getLoaderSubtext;
    window.updateLoaderSubtext = updateLoaderSubtext;
    
    // Hide loader when page is fully loaded
    function hideLoader() {
        const loader = document.getElementById('page-loader');
        if (loader) {
            // Use proper CSS transition sequence
            loader.classList.add('loader--hiding');
            loader.classList.remove('loader--active');
            
            setTimeout(function() {
                loader.classList.add('loader--hidden');
                loader.classList.remove('loader--hiding');
            }, 500);
        }
    }
    
    // Hide on window load (only if navigation has not started)
    function hideLoaderOnPageLoad() {
        setTimeout(function() {
            if (!window._pageLoaderNavigating) {
                hideLoader();
            }
        }, 100);
    }

    if (document.readyState === 'complete') {
        hideLoaderOnPageLoad();
    } else {
        window.addEventListener('load', hideLoaderOnPageLoad);
    }
    
    // Fallback: hide after 3 seconds, but only if navigation is not in progress
    setTimeout(function() {
        if (!window._pageLoaderNavigating) {
            hideLoader();
        }
    }, 3000);
    
    // Handle browser back/forward (bfcache)
    window.addEventListener('pageshow', function(e) {
        if (e.persisted) {
            hideLoader();
        }
    });
})();
</script>
