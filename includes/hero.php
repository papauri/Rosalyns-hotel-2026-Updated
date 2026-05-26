<?php
/**
 * Reusable Hero Component
 * Displays hero content from the page_heroes database table
 *
 * Usage: include 'includes/hero.php';
 * The component automatically detects the current page slug from the filename
 */

// Ensure database connection is available
if (!function_exists('getPageHero')) {
    require_once __DIR__ . '/../config/database.php';
}

// Include video display helper
require_once __DIR__ . '/video-display.php';
require_once __DIR__ . '/image-proxy-helper.php';

/**
 * Build a hero image URL safely.
 *
 * - Keeps external/signed URLs unchanged (prevents breaking canonical media URLs)
 * - Adds variant query params only for local file paths
 */
function buildHeroImageSrc(string $path, string $variantQuery = '', string $cacheToken = ''): string
{
    $trimmedPath = trim($path);
    if ($trimmedPath === '') {
        return '';
    }

    $isExternal = preg_match('/^https?:\/\//i', $trimmedPath) === 1;
    $resolvedPath = proxyImageUrl($trimmedPath);

    $queryParts = [];

    // Keep canonical external URLs untouched except for safe cache-busting tokens.
    if (!$isExternal && $variantQuery !== '') {
        $queryParts[] = ltrim($variantQuery, '?&');
    }

    // Cache-busting tied to row update timestamp so DB media changes propagate reliably.
    if (!$isExternal && $cacheToken !== '') {
        $queryParts[] = 'v=' . rawurlencode($cacheToken);
    }

    if (empty($queryParts)) {
        return $resolvedPath;
    }

    $separator = (strpos($resolvedPath, '?') !== false) ? '&' : '?';
    return $resolvedPath . $separator . implode('&', $queryParts);
}

// Get current page slug from the filename
// For SPA navigation via API, use the API_CURRENT_PAGE if set
if (isset($_SERVER['API_CURRENT_PAGE'])) {
    $page_slug = $_SERVER['API_CURRENT_PAGE'];
} else {
    $page_slug = strtolower(pathinfo($_SERVER['SCRIPT_FILENAME'], PATHINFO_FILENAME));
    $page_slug = str_replace('_', '-', $page_slug);
}

// Fetch hero data from database
$pageHero = getPageHero($page_slug);

// Fallback by exact URL match (still from page_heroes table)
if (!$pageHero && function_exists('getPageHeroByUrl')) {
    $scriptUrl = $_SERVER['SCRIPT_NAME'] ?? ('/' . basename((string)($_SERVER['SCRIPT_FILENAME'] ?? '')));
    if (!empty($scriptUrl)) {
        $pageHero = getPageHeroByUrl($scriptUrl);
    }
}

$heroVideoPath = $pageHero['hero_video_path'] ?? null;
$heroVideoType = $pageHero['hero_video_type'] ?? null;
$heroImagePath = $pageHero['hero_image_path'] ?? null;

$heroMediaCacheToken = '';
if (!empty($pageHero['updated_at'])) {
    $heroUpdatedAt = strtotime((string)$pageHero['updated_at']);
    if ($heroUpdatedAt !== false) {
        $heroMediaCacheToken = (string)$heroUpdatedAt;
    }
}
if ($heroMediaCacheToken === '' && !empty($pageHero['id'])) {
    $heroMediaCacheToken = (string)$pageHero['id'];
}

if ($page_slug === 'index' && $pageHero && empty($heroVideoPath) && empty($heroImagePath)) {
    error_log('Index hero is active in page_heroes but has no hero_image_path or hero_video_path.');
}

$heroImageDesktop = buildHeroImageSrc((string)$heroImagePath, 'width=3840&format=webp&quality=92', $heroMediaCacheToken);
$heroImageDesktop2x = buildHeroImageSrc((string)$heroImagePath, 'width=5120&format=webp&quality=92', $heroMediaCacheToken);
$heroImageTablet = buildHeroImageSrc((string)$heroImagePath, 'width=2560&format=webp&quality=90', $heroMediaCacheToken);
$heroImageTablet2x = buildHeroImageSrc((string)$heroImagePath, 'width=3200&format=webp&quality=90', $heroMediaCacheToken);
$heroImageMobile = buildHeroImageSrc((string)$heroImagePath, 'width=1536&format=webp&quality=88', $heroMediaCacheToken);
$heroImageMobile2x = buildHeroImageSrc((string)$heroImagePath, 'width=1920&format=webp&quality=88', $heroMediaCacheToken);

$heroHasMedia = !empty($heroVideoPath) || !empty($heroImagePath);

// Only render if hero data exists
if ($pageHero):
?>
<!-- Hero Section with BEM Classes -->
<section class="hero hero--passalacqua<?php echo $heroHasMedia ? ' hero--image hero--fit-standard' : ''; ?><?php echo !empty($heroVideoPath) ? ' hero--has-video' : ''; ?><?php echo $page_slug === 'index' ? ' hero--home-lux' : ''; ?>">
        <?php if (!empty($heroVideoPath)): ?>
        <!-- Display video if available -->
        <div class="hero__media">
            <?php echo renderVideoEmbed($heroVideoPath, $heroVideoType, [
                'autoplay' => true,
                'muted' => true,
                'controls' => false,
                'loop' => true,
                'lazy' => false,
                'preload' => 'auto',
                'playsinline' => true,
                'class' => 'hero__video',
                'quality' => 'hd1080' // Ensure HD quality for hero videos
            ]); ?>
        </div>
        <?php elseif (!empty($heroImagePath)): ?>
        <!-- Display image background if no video -->
        <div class="hero__media">
            <picture class="hero__picture">
                <source srcset="<?php echo htmlspecialchars($heroImageDesktop); ?> 1x, <?php echo htmlspecialchars($heroImageDesktop2x); ?> 2x" media="(min-width: 1280px)" sizes="100vw">
                <source srcset="<?php echo htmlspecialchars($heroImageTablet); ?> 1x, <?php echo htmlspecialchars($heroImageTablet2x); ?> 2x" media="(min-width: 768px)" sizes="100vw">
                <img src="<?php echo htmlspecialchars($heroImageMobile); ?>" srcset="<?php echo htmlspecialchars($heroImageMobile); ?> 1x, <?php echo htmlspecialchars($heroImageMobile2x); ?> 2x" alt="<?php echo htmlspecialchars($pageHero['hero_title']); ?>" class="hero__image" width="1920" height="1080" loading="eager" decoding="async" fetchpriority="high">
            </picture>
        </div>
    <?php endif; ?>
    
    <div class="hero__content"<?php echo $page_slug === 'index' ? ' data-hero-reveal="true"' : ''; ?>>
        <h1 class="hero__title">
            <?php echo htmlspecialchars($pageHero['hero_title']); ?>
            <?php if (!empty($pageHero['hero_subtitle'])): ?>
            <br/>
            <span class="hero__subtitle">
                <?php echo htmlspecialchars($pageHero['hero_subtitle']); ?>
            </span>
            <?php endif; ?>
        </h1>
        
        <?php if (!empty($pageHero['hero_description'])): ?>
        <p class="hero__description">
            <?php echo htmlspecialchars($pageHero['hero_description']); ?>
        </p>
        <?php endif; ?>
        
        <?php if (!empty($pageHero['primary_cta_text']) && !empty($pageHero['primary_cta_link'])): ?>
        <div class="hero__actions">
            <?php 
            // Determine if this is an "Explore Rooms" button
            $primary_cta_text_lower = strtolower($pageHero['primary_cta_text']);
            $is_explore_rooms = (strpos($primary_cta_text_lower, 'room') !== false || 
                                 strpos($primary_cta_text_lower, 'explore') !== false ||
                                 strpos($primary_cta_text_lower, 'accommodation') !== false);
            $primary_btn_class = $is_explore_rooms ? 'btn btn--explore-rooms' : 'btn btn--outline-on-light';
            ?>
            <a href="<?php echo htmlspecialchars($pageHero['primary_cta_link']); ?>" class="<?php echo $primary_btn_class; ?>">
                <?php echo htmlspecialchars($pageHero['primary_cta_text']); ?>
                <?php if ($is_explore_rooms): ?>
                <i class="fas fa-arrow-right"></i>
                <?php endif; ?>
            </a>
            <?php if (!empty($pageHero['secondary_cta_text']) && !empty($pageHero['secondary_cta_link'])): ?>
            <a href="<?php echo htmlspecialchars($pageHero['secondary_cta_link']); ?>" class="btn btn--outline-on-light">
                <?php echo htmlspecialchars($pageHero['secondary_cta_text']); ?>
            </a>
            <?php endif; ?>
        </div>
        <?php endif; ?>
    </div>
</section>
<?php endif; ?>
