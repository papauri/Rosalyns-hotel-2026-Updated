<?php

/**
 * SEO Meta Tags Component
 * Provides comprehensive SEO meta tags and structured data
 *
 * Usage:
 * $seo_data = [
 *     'title' => 'Page Title',
 *     'description' => 'Page description',
 *     'image' => '/path/to/image.jpg',
 *     'type' => 'website', // or 'article', 'hotel', 'event', etc.
 *     'published_time' => '2024-01-01', // for articles/events
 *     'modified_time' => '2024-01-02',
 *     'author' => 'Author Name',
 *     'section' => 'Section Name',
 *     'tags' => 'tag1, tag2, tag3',
 *     'canonical' => 'https://example.com/page',
 *     'noindex' => false,
 *     'structured_data' => [...] // JSON-LD structured data
 * ];
 *
 * require_once 'includes/seo-meta.php';
 */

// Get site settings from database
$site_name = getSetting('site_name');
$site_tagline = getSetting('site_tagline');
$site_logo = getSetting('site_logo');
$site_url = getSetting('site_url');
$default_keywords = getSetting('default_keywords');

// Sanitize site name and tagline to fix apostrophe/quote encoding issues
// Convert curly/smart quotes to standard ASCII using Unicode-aware regex
$__orig_site_name = $site_name;
$__orig_site_tagline = $site_tagline;

$site_name = preg_replace([
    '/[‘’‛`´]/u',   // various single quote marks/backticks/acute
    '/[“”„‟]/u'     // various double quote marks
], [
    "'",
    '"'
], $site_name);

$site_tagline = preg_replace([
    '/[‘’‛`´]/u',
    '/[“”„‟]/u'
], [
    "'",
    '"'
], $site_tagline);

// Debug log only when normalization actually changed values (helps verify once in logs)
if ($__orig_site_name !== $site_name || $__orig_site_tagline !== $site_tagline) {
    error_log('seo-meta: normalized smart quotes in site_name/site_tagline');
}

// Use database URL if available, otherwise construct from host
$base_url = $site_url ?: 'https://' . $_SERVER['HTTP_HOST'];

// Default SEO data
$seo_default = [
    'title' => $site_name,
    'description' => $site_tagline,
    'image' => $site_logo,
    'type' => 'website',
    'published_time' => null,
    'modified_time' => null,
    'author' => $site_name,
    'section' => null,
    'tags' => null,
    'canonical' => null,
    'noindex' => false,
    'structured_data' => null,
    'breadcrumbs' => null
];

// Merge with provided SEO data
$seo = array_merge($seo_default, $seo_data ?? []);

// Build full page title
$page_title = $seo['title'] === $site_name
    ? $site_name . ' - ' . $site_tagline
    : $seo['title'] . ' | ' . $site_name;

// Build absolute image URL
$seo_image = strpos($seo['image'], 'http') === 0
    ? $seo['image']
    : $base_url . $seo['image'];

// Build absolute logo URL
$logo_abs = strpos($site_logo, 'http') === 0
    ? $site_logo
    : $base_url . $site_logo;

// Versioning for SEO assets (favicons, touch icons) to force cache refresh on purge
// Uses a dedicated setting that we can bump from admin tools
$__seo_asset_version = getSetting('seo_asset_version');
// Spec-aligned alias for readability and downstream usage
$asset_ver = isset($__seo_asset_version) ? (string) $__seo_asset_version : '';
if (!function_exists('appendAssetVersion')) {
    function appendAssetVersion(string $url, string $version)
    {
        if (empty($version)) return $url;
        $sep = (strpos($url, '?') !== false) ? '&' : '?';
        return $url . $sep . 'v=' . rawurlencode($version);
    }
}

// Build canonical URL
if ($seo['canonical']) {
    $canonical_url = strpos($seo['canonical'], 'http') === 0
        ? $seo['canonical']
        : $base_url . $seo['canonical'];
} else {
    $canonical_url = $base_url . $_SERVER['REQUEST_URI'];
}

// Get current page path for robots
$current_path = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
$disallowed_paths = ['/admin/', '/private/', '/tmp/', '/cache/', '/sessions/', '/logs/', '/invoices/'];
$should_noindex = $seo['noindex'] || strpos($current_path, '/admin/') === 0;
foreach ($disallowed_paths as $path) {
    if (strpos($current_path, $path) === 0) {
        $should_noindex = true;
        break;
    }
}
?>

<!-- Primary Meta Tags -->
<title><?php echo htmlspecialchars($page_title); ?></title>
<meta name="title" content="<?php echo htmlspecialchars($page_title); ?>">
<meta name="description" content="<?php echo htmlspecialchars($seo['description']); ?>">
<meta name="keywords" content="<?php echo htmlspecialchars($seo['tags'] ?: $default_keywords); ?>">
<meta name="author" content="<?php echo htmlspecialchars($seo['author']); ?>">
<meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">

<?php if ($should_noindex): ?>
    <meta name="robots" content="noindex, nofollow">
<?php else: ?>
    <meta name="robots" content="index, follow">
<?php endif; ?>

<link rel="canonical" href="<?php echo htmlspecialchars($canonical_url); ?>">

<!-- Open Graph / Facebook -->
<meta property="og:type" content="<?php echo htmlspecialchars($seo['type']); ?>">
<meta property="og:url" content="<?php echo htmlspecialchars($canonical_url); ?>">
<meta property="og:title" content="<?php echo htmlspecialchars($page_title); ?>">
<meta property="og:description" content="<?php echo htmlspecialchars($seo['description']); ?>">
<meta property="og:image" content="<?php echo htmlspecialchars($seo_image); ?>">
<meta property="og:site_name" content="<?php echo htmlspecialchars($site_name); ?>">
<?php if ($seo['published_time']): ?>
    <meta property="article:published_time" content="<?php echo htmlspecialchars($seo['published_time']); ?>">
<?php endif; ?>
<?php if ($seo['modified_time']): ?>
    <meta property="article:modified_time" content="<?php echo htmlspecialchars($seo['modified_time']); ?>">
<?php endif; ?>
<?php if ($seo['section']): ?>
    <meta property="article:section" content="<?php echo htmlspecialchars($seo['section']); ?>">
<?php endif; ?>
<?php if ($seo['tags']): ?>
    <meta property="article:tag" content="<?php echo htmlspecialchars($seo['tags']); ?>">
<?php endif; ?>

<!-- Twitter -->
<meta property="twitter:card" content="summary_large_image">
<meta property="twitter:url" content="<?php echo htmlspecialchars($canonical_url); ?>">
<meta property="twitter:title" content="<?php echo htmlspecialchars($page_title); ?>">
<meta property="twitter:description" content="<?php echo htmlspecialchars($seo['description']); ?>">
<meta property="twitter:image" content="<?php echo htmlspecialchars($seo_image); ?>">

<!-- Additional SEO Meta Tags -->
<meta name="theme-color" content="#1A1A1A">
<meta name="msapplication-TileColor" content="#1A1A1A">
<?php if (!empty($site_logo)): ?>
    <!-- Dynamic favicon from database -->
    <link rel="icon" type="image/png" sizes="16x16" href="<?php echo htmlspecialchars(appendAssetVersion($logo_abs, $asset_ver)); ?>">
    <link rel="icon" type="image/png" sizes="32x32" href="<?php echo htmlspecialchars(appendAssetVersion($logo_abs, $asset_ver)); ?>">
    <link rel="apple-touch-icon" sizes="180x180" href="<?php echo htmlspecialchars(appendAssetVersion($logo_abs, $asset_ver)); ?>">
    <link rel="shortcut icon" href="<?php echo htmlspecialchars(appendAssetVersion($logo_abs, $asset_ver)); ?>" type="image/png">
<?php else: ?>
    <!-- Fallback static favicons -->
    <link rel="icon" type="image/png" sizes="32x32" href="<?php echo htmlspecialchars(appendAssetVersion('/images/logo/logo.png', $asset_ver)); ?>">
    <link rel="icon" type="image/png" sizes="16x16" href="<?php echo htmlspecialchars(appendAssetVersion('/images/logo/logo.png', $asset_ver)); ?>">
    <link rel="apple-touch-icon" sizes="180x180" href="<?php echo htmlspecialchars(appendAssetVersion('/images/logo/logo.png', $asset_ver)); ?>">
    <link rel="shortcut icon" href="<?php echo htmlspecialchars(appendAssetVersion('/images/logo/logo.png', $asset_ver)); ?>" type="image/png">
<?php endif; ?>

<?php
// Structured Data (JSON-LD)
if (!empty($seo['structured_data'])):
    if (is_array($seo['structured_data'])):
        $json_ld = json_encode($seo['structured_data'], JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);
?>
        <script type="application/ld+json">
            <?php echo $json_ld; ?>
        </script>
    <?php
    endif;
endif;

// Breadcrumb Schema
if (!empty($seo['breadcrumbs'])):
    ?>
    <script type="application/ld+json">
        {
            "@context": "https://schema.org",
            "@type": "BreadcrumbList",
            "itemListElement": [
                <?php
                $breadcrumb_items = [];
                $position = 1;
                foreach ($seo['breadcrumbs'] as $crumb):
                    $breadcrumb_items[] = sprintf(
                        '{
                "@type": "ListItem",
                "position": %d,
                "name": "%s",
                "item": "%s"
            }',
                        $position++,
                        addslashes($crumb['name']),
                        addslashes($crumb['url'])
                    );
                endforeach;
                echo implode(",\n    ", $breadcrumb_items);
                ?>
            ]
        }
    </script>
<?php endif; ?>
<script>
    window._siteTimezone = <?php echo json_encode((string)getSetting('site_timezone', 'UTC')); ?>;
    window._siteName = <?php echo json_encode((string)($site_name ?: '')); ?>;
</script>
<!-- PWA manifest — enables browser install prompt on desktop + mobile -->
<link rel="manifest" href="<?php echo htmlspecialchars(defined('BASE_URL') ? rtrim(BASE_URL, '/') . '/manifest.php' : 'manifest.php'); ?>">
<meta name="mobile-web-app-capable" content="yes">
<meta name="apple-mobile-web-app-capable" content="yes">
<meta name="apple-mobile-web-app-status-bar-style" content="default">
<meta name="apple-mobile-web-app-title" content="<?php echo htmlspecialchars(getSetting('site_short_name') ?: 'Rosalyns', ENT_QUOTES, 'UTF-8'); ?>">
