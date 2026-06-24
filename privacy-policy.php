<?php

/**
 * Privacy Policy & Cookie Policy Page
 * Dynamically pulls site name from settings.
 */
if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}

require_once 'config/database.php';
require_once 'includes/page-guard.php';

$site_name = getSetting('site_name', 'Our Hotel');
$site_email = getSetting('email_main', 'info@example.com');
$site_address = getSetting('address_line1', '');
$current_page = 'privacy-policy';
$page_title = 'Privacy & Cookie Policy';

$seo_data = [
    'title' => "Privacy & Cookie Policy - $site_name",
    'description' => "Learn how $site_name collects, uses, and protects your personal data. Read our privacy policy and cookie usage information.",
    'type' => 'website',
];
?>
<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <?php require_once 'includes/seo-meta.php'; ?>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Cormorant+Garamond:ital,wght@0,300;0,400;0,500;0,600;1,300;1,400;1,500&family=Jost:wght@300;400;500;600&display=swap" rel="stylesheet">

    <!-- Main CSS - Loads all stylesheets in correct order -->
    <link rel="stylesheet" href="css/base/critical.css">
    <link rel="stylesheet" href="css/main.css">
</head>

<body>
    <?php require_once 'includes/loader.php'; ?>
    <?php require_once 'includes/header.php'; ?>

    <main class="privacy-page" id="main-content">
        <div class="privacy-container">

            <div class="privacy-header">
                <h1><i class="fas fa-shield-alt text-old"></i> Privacy & Cookie Policy</h1>
                <p class="subtitle"><?php echo htmlspecialchars($site_name); ?> is committed to protecting your privacy</p>
                <span class="last-updated"><i class="fas fa-calendar-alt"></i> Last Updated: <?php echo htmlspecialchars(getSetting('privacy_policy_updated', 'June 24, 2026')); ?></span>
            </div>

            <!-- Table of Contents -->
            <div class="privacy-nav">
                <h3>Contents</h3>
                <ul>
                    <li><a href="#info-collect"><i class="fas fa-chevron-right"></i> Information We Collect</a></li>
                    <li><a href="#how-use"><i class="fas fa-chevron-right"></i> How We Use Your Data</a></li>
                    <li><a href="#cookies"><i class="fas fa-chevron-right"></i> Cookies & Tracking</a></li>
                    <li><a href="#session-logging"><i class="fas fa-chevron-right"></i> Session Logging</a></li>
                    <li><a href="#data-sharing"><i class="fas fa-chevron-right"></i> Data Sharing</a></li>
                    <li><a href="#data-retention"><i class="fas fa-chevron-right"></i> Data Retention</a></li>
                    <li><a href="#your-rights"><i class="fas fa-chevron-right"></i> Your Rights</a></li>
                    <li><a href="#contact-us"><i class="fas fa-chevron-right"></i> Contact Us</a></li>
                </ul>
            </div>

            <!-- Section 1: Info Collection -->
            <section class="policy-section" id="info-collect">
                <h2><i class="fas fa-database"></i> Information We Collect</h2>
                <p>When you visit or interact with our website, we may collect the following types of information:</p>

                <h3>Information You Provide</h3>
                <ul>
                    <li><strong>Booking details:</strong> Name, email, phone number, check-in/check-out dates, number of guests</li>
                    <li><strong>Contact form submissions:</strong> Name, email, and message content</li>
                    <li><strong>Reviews:</strong> Name, email, and review content that you voluntarily submit</li>
                    <li><strong>Event/conference enquiries:</strong> Contact and event details</li>
                </ul>

                <h3>Information Collected Automatically</h3>
                <ul>
                    <li><strong>Device information:</strong> Device type (desktop, mobile, tablet), operating system, browser type</li>
                    <li><strong>Usage data:</strong> Pages visited, time spent, referring website</li>
                    <li><strong>Technical data:</strong> IP address, session identifier, timestamps</li>
                </ul>
            </section>

            <!-- Section 2: How We Use -->
            <section class="policy-section" id="how-use">
                <h2><i class="fas fa-cogs"></i> How We Use Your Data</h2>
                <p>We use your personal data for the following purposes:</p>
                <ul>
                    <li>Processing and managing your hotel reservations</li>
                    <li>Communicating booking confirmations, modifications, and cancellations</li>
                    <li>Responding to your enquiries and requests</li>
                    <li>Improving our website and services based on usage patterns</li>
                    <li>Analysing visitor traffic to understand peak times and popular content</li>
                    <li>Ensuring the security and proper functioning of our website</li>
                    <li>Complying with legal obligations</li>
                </ul>
                <p>We do <strong>not</strong> use your data for automated decision-making or profiling, and we do <strong>not</strong> sell your personal data to third parties.</p>
            </section>

            <!-- Section 3: Cookies -->
            <section class="policy-section" id="cookies">
                <h2><i class="fas fa-cookie-bite"></i> Cookies & Tracking Technologies</h2>
                <p>Our website uses cookies — small text files stored on your device — to enhance your experience. Here are the cookies we use:</p>

                <table class="data-table">
                    <thead>
                        <tr>
                            <th>Cookie Name</th>
                            <th>Type</th>
                            <th>Purpose</th>
                            <th>Duration</th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr>
                            <td><code>PHPSESSID</code></td>
                            <td><span class="cookie-type-badge badge-essential">Essential</span></td>
                            <td>Maintains your session while browsing (e.g., booking form state)</td>
                            <td>Session</td>
                        </tr>
                        <tr>
                            <td><code>cookie_consent</code></td>
                            <td><span class="cookie-type-badge badge-essential">Essential</span></td>
                            <td>Remembers your cookie consent preference</td>
                            <td>1 year</td>
                        </tr>
                        <tr>
                            <td><code>csrf_token</code></td>
                            <td><span class="cookie-type-badge badge-essential">Essential</span></td>
                            <td>Security token to prevent cross-site request forgery attacks</td>
                            <td>Session</td>
                        </tr>
                        <tr>
                            <td><code>visitor_session</code></td>
                            <td><span class="cookie-type-badge badge-analytics">Analytics</span></td>
                            <td>Tracks anonymous browsing session for traffic reports</td>
                            <td>Session</td>
                        </tr>
                    </tbody>
                </table>

                <h3>Managing Cookies</h3>
                <p>When you first visit our website, a cookie banner will ask for your consent. You can choose to:</p>
                <ul>
                    <li><strong>Accept All:</strong> Enables all cookies including analytics tracking</li>
                    <li><strong>Essential Only:</strong> Only cookies required for the website to function</li>
                    <li><strong>Decline:</strong> No cookies are set (some features may be limited)</li>
                </ul>
                <p>You can also clear cookies at any time through your browser settings. For instructions, visit your browser's help page.</p>
            </section>

            <!-- Section 4: Session Logging -->
            <section class="policy-section" id="session-logging">
                <h2><i class="fas fa-chart-bar"></i> Session Logging & Analytics</h2>
                <p>To improve our services and understand our visitors better, we log anonymous session data when you browse our website. This is done through our own internal tracking system — we do <strong>not</strong> use third-party analytics services like Google Analytics.</p>

                <h3>What We Log</h3>
                <table class="data-table">
                    <thead>
                        <tr>
                            <th>Data Point</th>
                            <th>Example</th>
                            <th>Purpose</th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr>
                            <td>Device Type</td>
                            <td>Desktop, Mobile, Tablet</td>
                            <td>Optimise website layout for different devices</td>
                        </tr>
                        <tr>
                            <td>Browser</td>
                            <td>Chrome, Safari, Firefox</td>
                            <td>Ensure compatibility across browsers</td>
                        </tr>
                        <tr>
                            <td>Operating System</td>
                            <td>Windows, macOS, Android, iOS</td>
                            <td>Technical support and compatibility</td>
                        </tr>
                        <tr>
                            <td>Pages Visited</td>
                            <td>/rooms-gallery.php, /restaurant.php</td>
                            <td>Understand popular content and user journeys</td>
                        </tr>
                        <tr>
                            <td>Visit Time</td>
                            <td>2026-02-09 14:30:00</td>
                            <td>Identify peak traffic times</td>
                        </tr>
                        <tr>
                            <td>Referring Website</td>
                            <td>google.com, facebook.com</td>
                            <td>Understand how visitors find us</td>
                        </tr>
                        <tr>
                            <td>IP Address</td>
                            <td>Partially anonymised</td>
                            <td>Geographic region identification, security</td>
                        </tr>
                    </tbody>
                </table>

                <p>Session logs are stored in our secure database and in server log files. This data is used exclusively for internal analytics and is never shared with third parties.</p>
            </section>

            <!-- Section 5: Data Sharing -->
            <section class="policy-section" id="data-sharing">
                <h2><i class="fas fa-share-alt"></i> Data Sharing & Third Parties</h2>
                <p>We do <strong>not</strong> sell, trade, or rent your personal information to third parties. Your booking and personal data may only be shared with:</p>
                <ul>
                    <li><strong>Our hotel staff:</strong> To process reservations and provide services during your stay</li>
                    <li><strong>Email service providers:</strong> To send booking confirmations and communications (your data is not used for their own marketing)</li>
                    <li><strong>Law enforcement:</strong> Only if required by law or legal process</li>
                </ul>
                <p>We do not integrate any third-party payment gateways or external tracking services on this website.</p>
            </section>

            <!-- Section 6: Data Retention -->
            <section class="policy-section" id="data-retention">
                <h2><i class="fas fa-clock"></i> Data Retention</h2>
                <p>We retain your data for the following periods:</p>
                <ul>
                    <li><strong>Booking data:</strong> Retained for 3 years after checkout for accounting and legal requirements</li>
                    <li><strong>Session/visitor logs:</strong> Automatically purged after 90 days</li>
                    <li><strong>Log files:</strong> Rotated and deleted after 30 days</li>
                    <li><strong>Cookie consent records:</strong> Retained for 1 year</li>
                    <li><strong>Reviews:</strong> Retained for as long as they are published, or until you request removal</li>
                </ul>
            </section>

            <!-- Section 7: Your Rights -->
            <section class="policy-section" id="your-rights">
                <h2><i class="fas fa-user-shield"></i> Your Rights</h2>
                <p>You have the following rights regarding your personal data:</p>
                <div class="rights-grid">
                    <div class="right-card">
                        <h4><i class="fas fa-eye"></i> Right to Access</h4>
                        <p>Request a copy of the personal data we hold about you</p>
                    </div>
                    <div class="right-card">
                        <h4><i class="fas fa-edit"></i> Right to Rectification</h4>
                        <p>Request correction of any inaccurate or incomplete data</p>
                    </div>
                    <div class="right-card">
                        <h4><i class="fas fa-trash-alt"></i> Right to Erasure</h4>
                        <p>Request deletion of your personal data where applicable</p>
                    </div>
                    <div class="right-card">
                        <h4><i class="fas fa-hand-paper"></i> Right to Object</h4>
                        <p>Object to the processing of your data for specific purposes</p>
                    </div>
                    <div class="right-card">
                        <h4><i class="fas fa-download"></i> Right to Portability</h4>
                        <p>Receive your data in a structured, machine-readable format</p>
                    </div>
                    <div class="right-card">
                        <h4><i class="fas fa-ban"></i> Right to Withdraw Consent</h4>
                        <p>Withdraw cookie consent at any time by clearing your browser cookies</p>
                    </div>
                </div>
                <p class="mt-30">To exercise any of these rights, please contact us using the details below.</p>
            </section>

            <!-- Section 8: Contact -->
            <section class="policy-section" id="contact-us">
                <h2><i class="fas fa-envelope"></i> Contact Us</h2>
                <p>If you have any questions about this Privacy Policy, or wish to exercise your data rights, please contact us:</p>
                <div class="contact-card">
                    <p><i class="fas fa-building"></i> <strong><?php echo htmlspecialchars($site_name); ?></strong></p>
                    <?php if (!empty($site_address)): ?>
                        <p><i class="fas fa-map-marker-alt"></i> <?php echo htmlspecialchars($site_address); ?></p>
                    <?php endif; ?>
                    <p><i class="fas fa-envelope"></i> <a href="mailto:<?php echo htmlspecialchars($site_email); ?>"><?php echo htmlspecialchars($site_email); ?></a></p>
                    <p><i class="fas fa-phone"></i> <a href="tel:<?php echo htmlspecialchars(preg_replace('/[^0-9+]/', '', getSetting('phone_main', ''))); ?>"><?php echo htmlspecialchars(getSetting('phone_main', '')); ?></a></p>
                </div>
            </section>

        </div>
    </main>

    <script src="js/main.js" defer></script>

    <?php require_once 'includes/footer.php'; ?>
</body>

</html>
