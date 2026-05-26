<?php
/**
 * docs/contract.php
 * Service Agreement Terms & Equipment Specifications for Rosalyn's Hotel
 * Fetches live settings from database and displays contract information.
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

$siteName       = ho('site_name', 'Rosalyn\'s Hotel');
$hotelAddress   = ho('hotel_address', '');
$hotelPhone     = ho('hotel_phone', '');
$hotelEmail     = ho('hotel_email', '');
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Service Agreement & Equipment Specifications - <?php echo $siteName; ?></title>
    <style>
        body { font-family: Arial, sans-serif; line-height: 1.6; color: #333; max-width: 900px; margin: 40px auto; padding: 20px; }
        h1 { border-bottom: 3px solid #8A775F; padding-bottom: 10px; }
        h2 { color: #8A775F; margin-top: 30px; }
        h3 { color: #666; margin-top: 20px; }
        .section { background: #f9f9f9; padding: 15px; margin: 15px 0; border-left: 4px solid #8A775F; }
        .equipment { background: #f0f0f0; padding: 12px; margin: 10px 0; border-radius: 4px; }
        .recommended { font-weight: bold; color: #228B22; }
        .fallback { color: #FF8C00; font-style: italic; }
        table { width: 100%; border-collapse: collapse; margin: 10px 0; }
        th, td { border: 1px solid #ddd; padding: 10px; text-align: left; }
        th { background: #8A775F; color: white; }
        footer { margin-top: 40px; padding-top: 20px; border-top: 1px solid #ddd; font-size: 0.9em; color: #666; }
    </style>
</head>
<body>
    <h1><?php echo $siteName; ?> — Service Agreement & Equipment Specifications</h1>
    
    <div class="section">
        <strong>Venue:</strong> <?php echo $siteName; ?><br>
        <strong>Address:</strong> <?php echo $hotelAddress; ?><br>
        <strong>Contact:</strong> <?php echo $hotelPhone; ?> | <?php echo $hotelEmail; ?>
    </div>

    <h2>1. Service Scope</h2>
    <div class="section">
        <p>This agreement covers the deployment and operation of a comprehensive hospitality management system including:</p>
        <ul>
            <li>Point-of-Sale (POS) Till System</li>
            <li>Kitchen Display System (KDS)</li>
            <li>Bar Display System (BDS)</li>
            <li>Coffee Display System (CDS)</li>
            <li>Room Service Management</li>
            <li>Housekeeping Task Management</li>
            <li>Stock & Inventory Management</li>
            <li>Booking & Revenue Management</li>
            <li>Admin Dashboard & Reporting</li>
        </ul>
    </div>

    <h2>2. Recommended Equipment Specifications</h2>
    
    <h3>2.1 POS Till Terminal (Staff)</h3>
    <div class="equipment">
        <p><span class="recommended">Recommended:</span> Samsung Galaxy Tab A9+ (4GB RAM, 64GB Storage)</p>
        <ul>
            <li>10.9" display — optimal for till transactions</li>
            <li>Responsive touch performance for payment processing</li>
            <li>Long battery life for continuous operation</li>
            <li>Regular security updates (3+ years)</li>
            <li>Good app compatibility with payment gateways</li>
        </ul>
        <p><span class="fallback">Budget Alternative:</span> Samsung Galaxy Tab A9 (4GB RAM, 64GB Storage)</p>
        <ul>
            <li>Smaller 8.7" screen but acceptable for till operations</li>
            <li>Meets minimum performance requirements</li>
            <li>Lower cost option for multi-device deployments</li>
        </ul>
    </div>

    <h3>2.2 Kitchen Display System (KDS) Terminal</h3>
    <div class="equipment">
        <p><span class="recommended">Recommended:</span> iPad (10th generation, WiFi + LTE)</p>
        <ul>
            <li>10.9" full-laminated display with bright output (required for kitchen ambient light)</li>
            <li>A14 Bionic processor — handles real-time order updates smoothly</li>
            <li>Solid build quality — resistant to kitchen humidity and spills</li>
            <li>5+ years of OS support and security updates</li>
            <li>Faster refresh rate and responsive touch for rapid order management</li>
            <li>Can be wall-mounted or placed on stand (industrial frame recommended)</li>
        </ul>
        <p><span class="fallback">Budget Alternative:</span> Samsung Galaxy Tab A9+ (4GB RAM, 64GB Storage)</p>
        <ul>
            <li>Works for smaller kitchens with lower order volume</li>
            <li>Touch-responsive enough for kitchen use</li>
            <li>Screen is less bright than iPad — may struggle in high-light kitchens</li>
            <li>Consider anti-glare screen protector</li>
        </ul>
    </div>

    <h3>2.3 Bar Display System (BDS) & Coffee Display System (CDS)</h3>
    <div class="equipment">
        <p><span class="recommended">Recommended:</span> iPad (10th generation) — same as KDS</p>
        <ul>
            <li>Bright, responsive display for bar/coffee station visibility</li>
            <li>Handles concurrent orders without lag</li>
            <li>Wall-mountable with industrial case</li>
        </ul>
        <p><span class="fallback">Minimum Viable:</span> Samsung Galaxy Tab A9+ OR any modern tablet with 10"+ screen</p>
        <ul>
            <li>If budget is critical, re-purpose used iPad (7th–9th gen) or Galaxy Tab A8</li>
            <li>Still provide adequate visibility for order tickets</li>
        </ul>
    </div>

    <h2>3. Network & Connectivity</h2>
    <div class="section">
        <ul>
            <li>All terminals must be on stable WiFi (2.4GHz + 5GHz bands supported)</li>
            <li>Minimum bandwidth: 10 Mbps per terminal for real-time updates</li>
            <li>LTE fallback recommended for KDS/BDS/CDS (optional but advised for critical service areas)</li>
            <li>Offline mode supported: system queues actions and syncs when connection restored</li>
        </ul>
    </div>

    <h2>4. Software & Operating System Support</h2>
    <div class="section">
        <table>
            <tr>
                <th>Device Type</th>
                <th>Minimum OS Version</th>
                <th>Support Period</th>
            </tr>
            <tr>
                <td>iPad (10th gen)</td>
                <td>iPadOS 16+</td>
                <td>5+ years (Apple)</td>
            </tr>
            <tr>
                <td>Samsung Galaxy Tab A9+</td>
                <td>Android 13+</td>
                <td>3 years (Samsung)</td>
            </tr>
            <tr>
                <td>Samsung Galaxy Tab A9</td>
                <td>Android 12+</td>
                <td>3 years (Samsung)</td>
            </tr>
        </table>
    </div>

    <h2>5. Maintenance & Warranty</h2>
    <div class="section">
        <ul>
            <li><strong>Hardware Warranty:</strong> Minimum 1 year manufacturer coverage (extended warranty recommended for KDS/BDS/CDS units)</li>
            <li><strong>Software Updates:</strong> Critical security patches deployed monthly; feature updates quarterly</li>
            <li><strong>Device Replacement Policy:</strong> Devices should be replaced after 4 years or when OS support ends</li>
            <li><strong>Screen Damage:</strong> Tempered glass protectors recommended for all devices</li>
            <li><strong>Cooling & Environment:</strong> KDS/BDS units should operate in 15–25°C range; keep away from direct heat sources</li>
        </ul>
    </div>

    <h2>6. Data Security & Compliance</h2>
    <div class="section">
        <ul>
            <li>All devices use encrypted communication (HTTPS/TLS)</li>
            <li>Session data stored locally is encrypted at rest</li>
            <li>Remote wipe capability available through admin panel if device is lost</li>
            <li>Payment card data never stored on devices (PCI-DSS compliant)</li>
            <li>Regular security audits and penetration testing performed quarterly</li>
        </ul>
    </div>

    <h2>7. Deployment Checklist</h2>
    <div class="section">
        <ul>
            <li>✓ Provision all devices with latest OS and security patches</li>
            <li>✓ Install system app and configure WiFi credentials</li>
            <li>✓ Test offline sync and real-time update performance</li>
            <li>✓ Mount KDS/BDS/CDS in secure, wall-accessible locations</li>
            <li>✓ Configure backup power (UPS) for critical terminals</li>
            <li>✓ Train staff on device usage, basic troubleshooting, and reporting issues</li>
            <li>✓ Document device serial numbers and assign to staff</li>
            <li>✓ Set up device management console for remote monitoring</li>
        </ul>
    </div>

    <footer>
        <p><strong>Document Version:</strong> 1.0 | <strong>Date:</strong> May 19, 2026</p>
        <p>For questions or equipment specifications, contact: <?php echo $hotelEmail; ?></p>
    </footer>

</body>
</html>
