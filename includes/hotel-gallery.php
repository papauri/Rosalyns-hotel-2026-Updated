<?php
/**
 * Hotel Gallery Section - Editorial Redesign
 * Implements a Passalacqua-inspired masonry layout
 * 
 * Required Variables:
 * - $gallery_images: Array of gallery image data
 * - $site_name: Site name for context
 */

// Load section headers helper
require_once __DIR__ . '/section-headers.php';

// If gallery_images is not available, try to fetch it
if (!isset($gallery_images) || empty($gallery_images)) {
    try {
        require_once __DIR__ . '/../config/database.php';

        if (function_exists('getManagedMediaItems')) {
            $managed_gallery = getManagedMediaItems('index_hotel_gallery', ['limit' => 12]);
            if (!empty($managed_gallery)) {
                $gallery_images = array_map(function ($item) {
                    return [
                        'image_url' => $item['media_url'] ?? null,
                        'video_path' => ($item['media_type'] ?? '') === 'video' ? ($item['media_url'] ?? null) : null,
                        'video_type' => ($item['media_type'] ?? '') === 'video' ? ($item['mime_type'] ?? 'url') : null,
                        'title' => $item['title'] ?? '',
                        'description' => $item['description'] ?? '',
                        'category' => 'managed',
                    ];
                }, $managed_gallery);
            }
        }
        
        if ((empty($gallery_images) || !is_array($gallery_images)) && function_exists('getCachedGalleryImages')) {
            $gallery_images = getCachedGalleryImages();
        } elseif (empty($gallery_images) || !is_array($gallery_images)) {
            // Fallback to direct query
            $stmt = $pdo->query(" 
                SELECT image_url, title, description, category, video_path, video_type 
                FROM hotel_gallery 
                WHERE is_active = 1 
                ORDER BY display_order ASC
            ");
            $gallery_images = $stmt->fetchAll(PDO::FETCH_ASSOC);
        }
    } catch (Exception $e) {
        $gallery_images = [];
        error_log("Error fetching gallery images: " . $e->getMessage());
    }
}

// Helper function to resolve image URLs
if (!function_exists('resolveImageUrl')) {
    function resolveImageUrl($path) {
        if (!$path) return '';
        $trimmed = trim($path);
        if (stripos($trimmed, 'http://') === 0 || stripos($trimmed, 'https://') === 0) {
            return $trimmed;
        }
        return $trimmed;
    }
}
?>

<?php if (!empty($gallery_images)): ?>
<section class="editorial-section editorial-gallery-section landing-section" id="gallery" data-lazy-reveal>
    <div class="editorial-container">
        <!-- Section Header -->
        <div class="scroll-reveal">
            <?php renderSectionHeader('hotel_gallery', 'index', [
                'label' => 'Visual Journey',
                'title' => 'Explore Our World',
                'description' => 'Immerse yourself in the beauty and luxury of our hotel'
            ], 'editorial-header section-header--editorial'); ?>
        </div>

        <!-- Masonry Grid -->
        <div class="editorial-gallery">
            <?php require_once __DIR__ . '/video-display.php'; ?>
            
            <?php
            // Display all gallery images - grid now expands dynamically
            $display_images = $gallery_images;
            
            foreach ($display_images as $index => $image):
            ?>
            <div class="editorial-gallery-item scroll-reveal">
                <?php if (!empty($image['video_path'])): ?>
                    <div class="editorial-gallery-item__video">
                        <?php echo renderVideoEmbed($image['video_path'], $image['video_type'], [
                            'autoplay' => true,
                            'muted' => true,
                            'controls' => false,
                            'loop' => true,
                            'lazy' => true,
                            'preload' => 'metadata',
                            'class' => 'gallery-video-embed',
                            'style' => 'width: 100%; height: 100%; object-fit: cover;'
                        ]); ?>
                    </div>
                <?php else: ?>
                    <img src="<?php echo htmlspecialchars(resolveImageUrl($image['image_url'])); ?>"
                         class="editorial-gallery-item__img"
                         alt="<?php echo htmlspecialchars($image['title']); ?>"
                         width="1200"
                         height="750"
                         loading="lazy"
                          decoding="async">
                <?php endif; ?>
                
                <div class="editorial-gallery-item__overlay">
                    <div class="editorial-gallery-item__content">
                        <?php if (!empty($image['category'])): ?>
                        <span class="editorial-gallery-item__category">
                            <?php echo htmlspecialchars($image['category']); ?>
                        </span>
                        <?php endif; ?>
                        <h4 class="editorial-gallery-item__caption"><?php echo htmlspecialchars($image['title']); ?></h4>
                    </div>
                </div>
            </div>
            <?php endforeach; ?>
        </div>
    </div>
</section>
<?php endif; ?>
