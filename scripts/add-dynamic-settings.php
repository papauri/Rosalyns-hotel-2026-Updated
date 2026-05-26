<?php
/**
 * Add dynamic hotel settings for star rating and price range
 */

require_once __DIR__ . '/../config/database.php';

try {
    $pdo->beginTransaction();
    
    // Add hotel_star_rating setting
    $stmt = $pdo->prepare("
        INSERT INTO site_settings (setting_key, setting_value, setting_group) 
        VALUES (?, ?, ?)
        ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)
    ");
    
    $stmt->execute([
        'hotel_star_rating',
        '5',
        'general'
    ]);
    
    // Add price_range_indicator setting
    $stmt->execute([
        'price_range_indicator',
        '$$$',
        'general'
    ]);
    
    $pdo->commit();
    
    echo "✓ Successfully added dynamic settings:\n";
    echo "  - hotel_star_rating: 5\n";
    echo "  - price_range_indicator: $$$\n";
    echo "\nThese settings are now configurable via admin panel.\n";
    
} catch (PDOException $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    echo "✗ Error: " . $e->getMessage() . "\n";
    exit(1);
}
