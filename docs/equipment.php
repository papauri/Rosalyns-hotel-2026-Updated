<?php
/**
 * docs/equipment.php
 * Equipment Procurement & Specifications Guide
 * Device recommendations for all system terminals (POS, KDS, BDS, CDS, etc.)
 */
declare(strict_types=1);

$dbConfig = __DIR__ . '/../config/database.php';
$dbAvailable = false;
if (file_exists($dbConfig)) {
    require_once $dbConfig;
    $dbAvailable = isset($pdo);
}

function ho(string $key, string $default = ''): string {
    if (!function_exists('getSetting')) return $default;
    $v = getSetting($key, $default);
    return htmlspecialchars((string)$v, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

$siteName = ho('site_name', 'Hotel');
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Equipment Procurement Guide - <?php echo $siteName; ?></title>
    <style>
        body { font-family: Arial, sans-serif; line-height: 1.6; color: #333; max-width: 1000px; margin: 40px auto; padding: 20px; }
        h1 { border-bottom: 3px solid #8A775F; padding-bottom: 10px; }
        h2 { color: #8A775F; margin-top: 30px; }
        h3 { color: #666; margin-top: 20px; font-weight: bold; }
        .device-card { border: 1px solid #ddd; border-radius: 6px; padding: 15px; margin: 15px 0; background: #f9f9f9; }
        .device-card.recommended { border-left: 5px solid #228B22; background: #f0fff0; }
        .device-card.budget { border-left: 5px solid #FF8C00; background: #fffaf0; }
        .spec { margin: 8px 0; font-size: 0.95em; }
        .spec strong { color: #666; }
        table { width: 100%; border-collapse: collapse; margin: 15px 0; }
        th, td { border: 1px solid #ddd; padding: 12px; text-align: left; }
        th { background: #8A775F; color: white; }
        .comparison-table tr:nth-child(even) { background: #f9f9f9; }
        .badge { display: inline-block; padding: 4px 8px; border-radius: 3px; font-size: 0.85em; font-weight: bold; margin: 2px; }
        .badge-recommended { background: #228B22; color: white; }
        .badge-budget { background: #FF8C00; color: white; }
        .badge-alt { background: #666; color: white; }
        .alert { padding: 12px; margin: 15px 0; border-radius: 4px; border-left: 4px solid; }
        .alert-info { background: #e3f2fd; border-color: #2196F3; }
        .alert-warning { background: #fff3e0; border-color: #FF9800; }
        footer { margin-top: 40px; padding-top: 20px; border-top: 1px solid #ddd; font-size: 0.9em; color: #666; }
    </style>
</head>
<body>
    <h1>Equipment Procurement & Specifications Guide</h1>
    <p style="font-size: 1.1em; color: #666;">Recommended devices for optimal performance of the hospitality management system.</p>

    <h2>Quick Comparison Table</h2>
    <table class="comparison-table">
        <tr>
            <th>Terminal Type</th>
            <th>Recommended Device</th>
            <th>Screen Size</th>
            <th>Budget Alternative</th>
            <th>Est. Cost (USD)</th>
        </tr>
        <tr>
            <td><strong>POS Till</strong></td>
            <td>Samsung Galaxy Tab A9+</td>
            <td>10.9"</td>
            <td>Galaxy Tab A9</td>
            <td>$250–280</td>
        </tr>
        <tr>
            <td><strong>Kitchen Display (KDS)</strong></td>
            <td>iPad (10th gen)</td>
            <td>10.9"</td>
            <td>Galaxy Tab A9+</td>
            <td>$349–450</td>
        </tr>
        <tr>
            <td><strong>Bar Display (BDS)</strong></td>
            <td>iPad (10th gen)</td>
            <td>10.9"</td>
            <td>Galaxy Tab A9+</td>
            <td>$349–450</td>
        </tr>
        <tr>
            <td><strong>Coffee Display (CDS)</strong></td>
            <td>iPad (10th gen)</td>
            <td>10.9"</td>
            <td>Galaxy Tab A9+</td>
            <td>$349–450</td>
        </tr>
        <tr>
            <td><strong>Admin Dashboard</strong></td>
            <td>iPad Pro 11" or laptop</td>
            <td>11"</td>
            <td>Galaxy Tab S6 Lite</td>
            <td>$350–$2000</td>
        </tr>
    </table>

    <h2>1. Point-of-Sale (POS) Terminal</h2>
    
    <div class="device-card recommended">
        <h3><span class="badge badge-recommended">RECOMMENDED</span> Samsung Galaxy Tab A9+ (4GB RAM, 64GB Storage)</h3>
        <div class="spec"><strong>Screen:</strong> 10.9" IPS LCD, 90Hz refresh, 500 nits brightness</div>
        <div class="spec"><strong>Processor:</strong> MediaTek Helio G99 (8-core)</div>
        <div class="spec"><strong>Battery:</strong> 7040 mAh, ~12 hours continuous use</div>
        <div class="spec"><strong>OS Support:</strong> Android 12+ (upgradeable to 13+), 3 years of updates</div>
        <div class="spec"><strong>Price:</strong> ~$250–280 USD</div>
        <div class="spec"><strong>Why This:</strong> Best balance of cost, responsiveness, and screen real estate for till transactions. Bright enough for retail environment, handles payment processing smoothly.</div>
    </div>

    <div class="device-card budget">
        <h3><span class="badge badge-budget">BUDGET OPTION</span> Samsung Galaxy Tab A9 (4GB RAM, 64GB Storage)</h3>
        <div class="spec"><strong>Screen:</strong> 8.7" IPS LCD, 90Hz refresh, 400 nits brightness</div>
        <div class="spec"><strong>Processor:</strong> MediaTek Helio G99 (8-core)</div>
        <div class="spec"><strong>Battery:</strong> 5100 mAh, ~9 hours continuous use</div>
        <div class="spec"><strong>OS Support:</strong> Android 12+ (upgradeable to 13+), 3 years of updates</div>
        <div class="spec"><strong>Price:</strong> ~$150–170 USD</div>
        <div class="spec"><strong>When to Use:</strong> Multiple POS units for cost efficiency. Still meets all till requirements but with smaller screen.</div>
    </div>

    <div class="alert alert-info">
        <strong>ℹ Tip:</strong> Mount POS terminals at 15–30° angle on adjustable stands for ergonomic staff use during long shifts.
    </div>

    <h2>2. Kitchen Display System (KDS) Terminal</h2>
    
    <div class="device-card recommended">
        <h3><span class="badge badge-recommended">RECOMMENDED</span> iPad (10th Generation, WiFi + Cellular)</h3>
        <div class="spec"><strong>Screen:</strong> 10.9" Liquid Retina, 500 nits brightness (crucial for kitchen ambient light)</div>
        <div class="spec"><strong>Processor:</strong> A14 Bionic (6-core CPU, 4-core GPU)</div>
        <div class="spec"><strong>Refresh Rate:</strong> 60 Hz but very responsive touch</div>
        <div class="spec"><strong>Battery:</strong> ~10 hours per full charge</div>
        <div class="spec"><strong>OS Support:</strong> iPadOS 16+, 5+ years of security updates</div>
        <div class="spec"><strong>Price:</strong> ~$349 (WiFi only) / ~$479 (WiFi + Cellular) USD</div>
        <div class="spec"><strong>Why This:</strong> Kitchen environments demand bright, lag-free displays. iPad's superior build quality handles heat, humidity, and occasional spills. LTE backup ensures orders don't lag if WiFi drops.</div>
    </div>

    <div class="device-card budget">
        <h3><span class="badge badge-budget">BUDGET OPTION</span> Samsung Galaxy Tab A9+ (4GB RAM, 64GB Storage)</h3>
        <div class="spec"><strong>Screen:</strong> 10.9" IPS LCD, 90Hz, but only 500 nits (less bright than iPad)</div>
        <div class="spec"><strong>Processor:</strong> MediaTek Helio G99</div>
        <div class="spec"><strong>Battery:</strong> 7040 mAh, ~12 hours</div>
        <div class="spec"><strong>OS Support:</strong> Android 12+ (3 years)</div>
        <div class="spec"><strong>Price:</strong> ~$250–280 USD</div>
        <div class="spec"><strong>When to Use:</strong> Lower kitchen volume, controlled lighting. Recommend anti-glare screen protector.</div>
    </div>

    <div class="alert alert-warning">
        <strong>⚠ Important:</strong> KDS terminals should be <strong>wall-mounted</strong> or placed on industrial stands (not hand-held). Ensure sufficient ventilation around device. Consider a protective case or industrial enclosure.
    </div>

    <h2>3. Bar Display System (BDS) Terminal</h2>
    
    <div class="device-card recommended">
        <h3><span class="badge badge-recommended">RECOMMENDED</span> iPad (10th Generation)</h3>
        <div class="spec">Same specs as KDS (see above).</div>
        <div class="spec"><strong>Why This:</strong> Bar environment = bright, fast-paced. iPad's brightness and responsiveness are critical for cocktail/beverage order management.</div>
    </div>

    <div class="device-card budget">
        <h3><span class="badge badge-budget">BUDGET OPTION</span> Samsung Galaxy Tab A9+ or used iPad (7th–9th gen)</h3>
        <div class="spec"><strong>Alternative:</strong> Refurbished iPad (9th gen) if available — often cheaper than new, same 500 nits brightness, solid performance.</div>
    </div>

    <h2>4. Coffee Display System (CDS) Terminal</h2>
    
    <div class="device-card recommended">
        <h3><span class="badge badge-recommended">RECOMMENDED</span> iPad (10th Generation)</h3>
        <div class="spec">Same specs as KDS/BDS.</div>
        <div class="spec"><strong>Why This:</strong> High order volume, need for rapid visual scanning. iPad's bright screen and responsive touch are ideal.</div>
    </div>

    <div class="device-card budget">
        <h3><span class="badge badge-budget">BUDGET OPTION</span> Samsung Galaxy Tab A9+ or Tab S6 Lite</h3>
        <div class="spec">Slightly lower priority than KDS/BDS, so older Galaxy Tab models acceptable if budget is tight.</div>
    </div>

    <h2>5. Admin Dashboard (Manager/Owner)</h2>
    
    <div class="device-card recommended">
        <h3><span class="badge badge-recommended">RECOMMENDED</span> iPad Pro 11" (WiFi, M2 chip)</h3>
        <div class="spec"><strong>Screen:</strong> 11" Liquid Retina XDR, 600 nits, excellent for spreadsheets and charts</div>
        <div class="spec"><strong>Processor:</strong> M2 (8-core CPU, 10-core GPU) — handles complex reports, analytics, multitasking</div>
        <div class="spec"><strong>Battery:</strong> ~10 hours</div>
        <div class="spec"><strong>OS Support:</strong> iPadOS 16+, 5+ years updates</div>
        <div class="spec"><strong>Price:</strong> ~$999–1099 USD</div>
        <div class="spec"><strong>Why This:</strong> Managers need fast performance for large data exports, chart rendering, and video calls. Pro model recommended for long-term reliability.</div>
    </div>

    <div class="device-card budget">
        <h3><span class="badge badge-alt">ALTERNATIVE</span> Laptop (MacBook Air M2 or Windows equivalent)</h3>
        <div class="spec"><strong>Why:</strong> Some managers prefer desktop-like experience. Laptop with 15"+ screen ideal for reports, invoicing, reconciliation.</div>
        <div class="spec"><strong>Price:</strong> $999–$1500 USD</div>
    </div>

    <h2>6. Provisioning & Setup Checklist</h2>
    <table>
        <tr>
            <th>Step</th>
            <th>Device Type</th>
            <th>Action</th>
        </tr>
        <tr>
            <td>1</td>
            <td>All</td>
            <td>Update to latest OS (iOS 17+ or Android 14+)</td>
        </tr>
        <tr>
            <td>2</td>
            <td>All</td>
            <td>Install security patches and app store updates</td>
        </tr>
        <tr>
            <td>3</td>
            <td>All</td>
            <td>Download system app (POS, KDS, BDS, CDS, etc.)</td>
        </tr>
        <tr>
            <td>4</td>
            <td>All</td>
            <td>Configure WiFi + password, add backup networks if available</td>
        </tr>
        <tr>
            <td>5</td>
            <td>KDS, BDS, CDS</td>
            <td>Test offline mode — verify order queueing and sync-on-reconnect</td>
        </tr>
        <tr>
            <td>6</td>
            <td>KDS, BDS, CDS</td>
            <td>Install on wall mounts with proper angle adjustment (15–30°)</td>
        </tr>
        <tr>
            <td>7</td>
            <td>POS</td>
            <td>Test with payment terminal integration and test transactions</td>
        </tr>
        <tr>
            <td>8</td>
            <td>All</td>
            <td>Assign device to staff member, document serial number</td>
        </tr>
        <tr>
            <td>9</td>
            <td>All</td>
            <td>Enable remote lock/wipe capability in admin panel</td>
        </tr>
        <tr>
            <td>10</td>
            <td>All</td>
            <td>Train staff on usage, troubleshooting, and support escalation</td>
        </tr>
    </table>

    <h2>7. Maintenance & Lifecycle</h2>
    <div class="alert alert-info">
        <strong>Support & Warranty:</strong>
        <ul>
            <li>1-year manufacturer warranty (extend to 2–3 years for critical KDS/BDS/CDS units)</li>
            <li>Replace devices after 4 years or when OS updates cease</li>
            <li>Screen protectors recommended (tempered glass, anti-glare)</li>
            <li>Monthly security patch review; apply within 30 days</li>
            <li>Quarterly performance audits for responsiveness and battery health</li>
        </ul>
    </div>

    <h2>8. Total System Cost Estimate</h2>
    <table>
        <tr>
            <th>Terminal</th>
            <th>Unit Count</th>
            <th>Device</th>
            <th>Unit Cost</th>
            <th>Subtotal</th>
        </tr>
        <tr>
            <td>POS Till</td>
            <td>2</td>
            <td>Galaxy Tab A9+</td>
            <td>$265</td>
            <td>$530</td>
        </tr>
        <tr>
            <td>KDS</td>
            <td>1</td>
            <td>iPad (10th gen, WiFi+LTE)</td>
            <td>$479</td>
            <td>$479</td>
        </tr>
        <tr>
            <td>BDS</td>
            <td>1</td>
            <td>iPad (10th gen, WiFi)</td>
            <td>$349</td>
            <td>$349</td>
        </tr>
        <tr>
            <td>CDS</td>
            <td>1</td>
            <td>iPad (10th gen, WiFi)</td>
            <td>$349</td>
            <td>$349</td>
        </tr>
        <tr>
            <td>Admin</td>
            <td>1</td>
            <td>iPad Pro 11" M2</td>
            <td>$1,099</td>
            <td>$1,099</td>
        </tr>
        <tr style="font-weight: bold; background: #f0f0f0;">
            <td colspan="4"><strong>TOTAL (Estimated):</strong></td>
            <td><strong>$2,806</strong></td>
        </tr>
    </table>
    <p style="font-size: 0.9em; color: #666;"><em>Prices as of May 2026. Add 15–20% for cases, screen protectors, wall mounts, and setup labor.</em></p>

    <footer>
        <p><strong>Document Version:</strong> 1.0 | <strong>Last Updated:</strong> May 19, 2026</p>
        <p>Questions? Contact your system administrator or support team.</p>
    </footer>

</body>
</html>
