<?php
/**
 * Video Display Helper
 * Provides functions for displaying videos on public-facing pages
 * This file can be safely included in public pages without admin authentication
 */

/**
 * Extract YouTube video ID from various URL formats
 *
 * @param string $url YouTube URL
 * @return string|null Video ID or null if not a YouTube URL
 */
function extractYouTubeId($url) {
    $patterns = [
        '/youtube\.com\/watch\?v=([a-zA-Z0-9_-]+)/',
        '/youtube\.com\/embed\/([a-zA-Z0-9_-]+)/',
        '/youtube\.com\/v\/([a-zA-Z0-9_-]+)/',
        '/youtu\.be\/([a-zA-Z0-9_-]+)/',
    ];
    
    foreach ($patterns as $pattern) {
        if (preg_match($pattern, $url, $matches)) {
            return $matches[1];
        }
    }
    
    return null;
}

/**
 * Extract Vimeo video ID from URL
 *
 * @param string $url Vimeo URL
 * @return string|null Video ID or null if not a Vimeo URL
 */
function extractVimeoId($url) {
    $patterns = [
        '/vimeo\.com\/(\d+)/',
        '/vimeo\.com\/channels\/[\w-]+\/(\d+)/',
        '/vimeo\.com\/groups\/[\w-]+\/videos\/(\d+)/',
        '/player\.vimeo\.com\/video\/(\d+)/',
    ];
    
    foreach ($patterns as $pattern) {
        if (preg_match($pattern, $url, $matches)) {
            return $matches[1];
        }
    }
    
    return null;
}

/**
 * Extract Dailymotion video ID from URL
 *
 * @param string $url Dailymotion URL
 * @return string|null Video ID or null if not a Dailymotion URL
 */
function extractDailymotionId($url) {
    $patterns = [
        '/dailymotion\.com\/video\/([a-zA-Z0-9]+)/',
        '/dai\.ly\/([a-zA-Z0-9]+)/',
    ];
    
    foreach ($patterns as $pattern) {
        if (preg_match($pattern, $url, $matches)) {
            return $matches[1];
        }
    }
    
    return null;
}

/**
 * Detect video platform and extract video ID
 *
 * @param string $url Video URL
 * @return array|null Platform info with 'platform' and 'id' keys, or null
 */
function detectVideoPlatform($url) {
    // YouTube
    if (strpos($url, 'youtube.com') !== false || strpos($url, 'youtu.be') !== false) {
        $id = extractYouTubeId($url);
        if ($id) {
            return ['platform' => 'youtube', 'id' => $id];
        }
    }
    
    // Vimeo
    if (strpos($url, 'vimeo.com') !== false) {
        $id = extractVimeoId($url);
        if ($id) {
            return ['platform' => 'vimeo', 'id' => $id];
        }
    }
    
    // Dailymotion
    if (strpos($url, 'dailymotion.com') !== false || strpos($url, 'dai.ly') !== false) {
        $id = extractDailymotionId($url);
        if ($id) {
            return ['platform' => 'dailymotion', 'id' => $id];
        }
    }
    
    return null;
}

/**
 * Check if URL is a video platform URL
 *
 * @param string $url URL to check
 * @return bool True if video platform URL
 */
function isVideoPlatformUrl($url) {
    return strpos($url, 'youtube.com') !== false ||
           strpos($url, 'youtu.be') !== false ||
           strpos($url, 'vimeo.com') !== false ||
           strpos($url, 'dailymotion.com') !== false ||
           strpos($url, 'dai.ly') !== false;
}

/**
 * Render video embed for frontend display
 *
 * @param string|null $videoPath Video path or URL
 * @param string|null $videoType Video MIME type
 * @param array $options Display options
 * @return string HTML for video embed
 */
function renderVideoEmbed($videoPath, $videoType = null, $options = []) {
    if (empty($videoPath)) {
        return '';
    }

    $defaults = [
        'autoplay' => true,
        'muted' => true,
        'controls' => true,
        'loop' => true,
        'lazy' => true,
        'preload' => 'metadata',
        'playsinline' => true,
        'class' => 'video-embed',
        'style' => 'width: 100%; height: auto; border-radius: 8px;',
        'quality' => 'hd1080' // Default to HD quality
    ];
    
    $opts = array_merge($defaults, $options);
    
    // Check if this is a video platform URL
    $platformInfo = detectVideoPlatform($videoPath);
    
    if ($platformInfo) {
        $autoplay = $opts['autoplay'] ? '1' : '0';
        $muted = $opts['muted'] ? '1' : '0';
        $controls = $opts['controls'] ? '1' : '0';
        $loop = $opts['loop'] ? '1' : '0';
        $quality = $opts['quality'];
        $loading = $opts['lazy'] ? 'lazy' : 'eager';
        
        ob_start();
        ?>
        <div class="<?php echo htmlspecialchars($opts['class']); ?> video-wrapper">
        <?php
        switch ($platformInfo['platform']) {
            case 'youtube':
                // YouTube HD quality parameters
                $qualityParam = '';
                if ($quality === 'hd1080') {
                    $qualityParam = '&vq=hd1080';
                } elseif ($quality === 'hd720') {
                    $qualityParam = '&vq=hd720';
                }
                ?>
                <iframe
                    src="https://www.youtube.com/embed/<?php echo htmlspecialchars($platformInfo['id']); ?>?autoplay=<?php echo $autoplay; ?>&mute=<?php echo $muted; ?>&controls=<?php echo $controls; ?>&loop=<?php echo $loop; ?>&rel=0<?php echo $qualityParam; ?>"
                    style="position: absolute; top: 0; left: 0; width: 100%; height: 100%; border: 0;"
                    allow="accelerometer; autoplay; clipboard-write; encrypted-media; gyroscope; picture-in-picture"
                    allowfullscreen
                    loading="<?php echo $loading; ?>"
                    title="YouTube video player">
                </iframe>
                <?php
                break;
                
            case 'vimeo':
                // Vimeo HD quality parameters
                $qualityParam = '';
                if ($quality === 'hd1080') {
                    $qualityParam = '&quality=1080p';
                } elseif ($quality === 'hd720') {
                    $qualityParam = '&quality=720p';
                }
                ?>
                <iframe
                    src="https://player.vimeo.com/video/<?php echo htmlspecialchars($platformInfo['id']); ?>?autoplay=<?php echo $autoplay; ?>&muted=<?php echo $muted; ?>&controls=<?php echo $controls; ?>&loop=<?php echo $loop; ?>&background=1<?php echo $qualityParam; ?>"
                    style="position: absolute; top: 0; left: 0; width: 100%; height: 100%; border: 0;"
                    allow="autoplay; fullscreen; picture-in-picture"
                    allowfullscreen
                    loading="<?php echo $loading; ?>"
                    title="Vimeo video player">
                </iframe>
                <?php
                break;
                
            case 'dailymotion':
                // Dailymotion quality parameters
                $qualityParam = '';
                if ($quality === 'hd1080') {
                    $qualityParam = '&quality=1080';
                } elseif ($quality === 'hd720') {
                    $qualityParam = '&quality=720';
                }
                ?>
                <iframe
                    src="https://www.dailymotion.com/embed/video/<?php echo htmlspecialchars($platformInfo['id']); ?>?autoplay=<?php echo $autoplay; ?>&mute=<?php echo $muted; ?>&controls=<?php echo $controls; ?>&loop=<?php echo $loop; ?><?php echo $qualityParam; ?>"
                    style="position: absolute; top: 0; left: 0; width: 100%; height: 100%; border: 0;"
                    allow="autoplay; fullscreen; picture-in-picture"
                    allowfullscreen
                    loading="<?php echo $loading; ?>"
                    title="Dailymotion video player">
                </iframe>
                <?php
                break;
        }
        ?>
        </div>
        <?php
        return ob_get_clean();
    }
    
    // Check if this is an external HTTP(S) URL (not a known platform, not a video file)
    // Try to embed it in an iframe as a universal fallback
    if (strpos($videoPath, 'http://') === 0 || strpos($videoPath, 'https://') === 0) {
        $videoExtensions = ['.mp4', '.webm', '.ogg', '.ogv', '.mov', '.avi', '.flv'];
        $isVideoFile = false;
        foreach ($videoExtensions as $ext) {
            if (stripos($videoPath, $ext) !== false) {
                $isVideoFile = true;
                break;
            }
        }
        
        // If it's not a direct video file and not a known platform, try iframe embed
        if (!$isVideoFile && !isVideoPlatformUrl($videoPath)) {
            ob_start();
            ?>
            <div class="<?php echo htmlspecialchars($opts['class']); ?> video-wrapper">
                <iframe
                    src="<?php echo htmlspecialchars($videoPath); ?>"
                    style="position: absolute; top: 0; left: 0; width: 100%; height: 100%; border: 0;"
                    allow="autoplay; fullscreen; picture-in-picture; encrypted-media"
                    allowfullscreen
                    loading="<?php echo $opts['lazy'] ? 'lazy' : 'eager'; ?>"
                    title="Video embed">
                </iframe>
            </div>
            <?php
            return ob_get_clean();
        }
    }
    
    // Handle local and external video files
    $videoUrl = $videoPath;
    
    // Check if this is an external HTTP(S) URL to a video file
    $isExternalVideo = false;
    if (strpos($videoPath, 'http://') === 0 || strpos($videoPath, 'https://') === 0) {
        // Check if it's a direct video file URL (not a platform)
        $videoExtensions = ['.mp4', '.webm', '.ogg', '.ogv', '.mov', '.avi', '.flv'];
        foreach ($videoExtensions as $ext) {
            if (stripos($videoPath, $ext) !== false) {
                $isExternalVideo = true;
                break;
            }
        }
    }
    
    // Add ../ prefix for local files
    if (!$isExternalVideo && strpos($videoPath, 'http') !== 0 && strpos($videoPath, '../') !== 0) {
        $videoUrl = '../' . $videoPath;
    }
    
    // Determine video type if not provided
    if (empty($videoType)) {
        if ($isExternalVideo) {
            // Try to detect from URL extension
            $extension = strtolower(pathinfo($videoPath, PATHINFO_EXTENSION));
            $mimeTypes = [
                'mp4' => 'video/mp4',
                'webm' => 'video/webm',
                'ogg' => 'video/ogg',
                'ogv' => 'video/ogg',
                'mov' => 'video/quicktime',
                'avi' => 'video/x-msvideo',
                'flv' => 'video/x-flv'
            ];
            $videoType = $mimeTypes[$extension] ?? 'video/mp4';
        } else {
            // Local file - detect from file system
            $fullPath = __DIR__ . '/../' . $videoPath;
            if (file_exists($fullPath)) {
                $videoType = mime_content_type($fullPath);
            }
        }
    }
    
    if (empty($videoType)) {
        $videoType = 'video/mp4'; // Default fallback
    }
    
    $autoplay = $opts['autoplay'] ? 'autoplay' : '';
    $muted = $opts['muted'] ? 'muted' : '';
    $controls = $opts['controls'] ? 'controls' : '';
    $loop = $opts['loop'] ? 'loop' : '';
    $playsinline = $opts['playsinline'] ? 'playsinline' : '';

    $preload = in_array($opts['preload'], ['none', 'metadata', 'auto'], true)
        ? $opts['preload']
        : 'metadata';
    
    ob_start();
    ?>
    <video
        <?php echo $autoplay; ?>
        <?php echo $muted; ?>
        <?php echo $controls; ?>
        <?php echo $loop; ?>
        <?php echo $playsinline; ?>
        class="<?php echo htmlspecialchars($opts['class']); ?>"
        style="<?php echo htmlspecialchars($opts['style']); ?>"
        preload="<?php echo htmlspecialchars($preload); ?>">
        <source src="<?php echo htmlspecialchars($videoUrl); ?>" type="<?php echo htmlspecialchars($videoType); ?>">
        Your browser does not support video playback.
    </video>
    <?php
    return ob_get_clean();
}
