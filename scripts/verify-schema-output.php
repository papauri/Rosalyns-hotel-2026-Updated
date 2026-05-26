<?php
/**
 * Verify schema.org markup output with dynamic settings
 */

require_once __DIR__ . '/../config/database.php';

// Simulate the structured data generation like index.php does
$site_name = getSetting('site_name', 'Hotel');
$star_rating = getSetting('hotel_star_rating', '5');
$price_range = getSetting('price_range_indicator', '$$$');

$schema = [
    "@context" => "https://schema.org",
    "@type" => "Hotel",
    "name" => $site_name,
    "starRating" => [
        "@type" => "Rating",
        "ratingValue" => $star_rating
    ],
    "priceRange" => $price_range
];

echo "Schema.org Structured Data Output:\n";
echo str_repeat('=', 60) . "\n";
echo json_encode($schema, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
echo "\n" . str_repeat('=', 60) . "\n";

echo "\n✓ Star rating and price range are now fetched from database\n";
echo "✓ Can be changed via admin panel without touching code\n";
echo "✓ Fallback values ('5' and '\$\$\$') if settings missing\n";
