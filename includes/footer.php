<?php

/**
 * Replace {{tag}} tokens in any footer text with live values from site_settings.
 * Call this BEFORE htmlspecialchars() on any stored footer/policy text.
 *
 * Supported tags:
 *   {{site_name}}     {{year}}          {{phone}}
 *   {{email}}         {{address}}       {{working_hours}}
 *   {{facebook_url}}  {{instagram_url}} {{twitter_url}}  {{linkedin_url}}
 */
if (!function_exists('footer_parse_tags')) {
    function footer_parse_tags(string $text): string
    {
        static $tags = null;
        if ($tags === null) {
            $tags = [
                // Identity
                '{{site_name}}'          => getSetting('site_name', ''),
                '{{site_tagline}}'       => getSetting('site_tagline', ''),
                '{{hotel_star_rating}}'  => getSetting('hotel_star_rating', ''),
                '{{year}}'               => date('Y'),
                // Contact
                '{{phone}}'              => getSetting('phone_main', ''),
                '{{phone_reservations}}' => getSetting('phone_reservations', ''),
                '{{email}}'              => getSetting('email_main', ''),
                '{{email_reservations}}' => getSetting('email_reservations', ''),
                // Location
                '{{address}}'            => getSetting('address_line1', ''),
                '{{address_line2}}'      => getSetting('address_line2', ''),
                '{{address_country}}'    => getSetting('address_country', ''),
                '{{working_hours}}'      => getSetting('working_hours', ''),
                // Booking
                '{{check_in_time}}'      => getSetting('check_in_time', ''),
                '{{check_out_time}}'     => getSetting('check_out_time', ''),
                '{{payment_policy}}'     => getSetting('payment_policy', ''),
                '{{cancellation_policy}}' => getSetting('cancellation_policy', ''),
                // Finance
                '{{currency}}'           => getSetting('currency_code', ''),
                '{{vat_rate}}'           => getSetting('vat_rate', ''),
                // Social
                '{{facebook_url}}'       => getSetting('facebook_url', ''),
                '{{instagram_url}}'      => getSetting('instagram_url', ''),
                '{{twitter_url}}'        => getSetting('twitter_url', ''),
                '{{linkedin_url}}'       => getSetting('linkedin_url', ''),
            ];
        }
        return str_replace(array_keys($tags), array_values($tags), $text);
    }
}
?>
<?php if (!function_exists('isBookingEnabled')) { require_once __DIR__ . '/booking-functions.php'; } ?>
<?php require_once __DIR__ . '/visitor-tracker.php'; ?>
<?php require_once 'modal.php'; ?>
<!-- Footer - Minimalist Professional 2026 with Lazy Load -->
<footer class="footer" id="contact" data-footer-lazy="">
    <div class="footer__lazy-overlay"></div>
    <div class="footer__content">
        <?php
        // Initialize arrays before fetching data
        $footer_links = [];
        $policies = [];

        // Fetch policies from database
        try {
            $stmt = $pdo->query("SELECT slug, title, summary, content FROM policies WHERE is_active = 1 ORDER BY display_order ASC");
            $policies = $stmt->fetchAll(PDO::FETCH_ASSOC);
            if (function_exists('rh_is_policy_hidden')) {
                $policies = array_values(array_filter($policies, function ($policy) {
                    return !rh_is_policy_hidden((string)($policy['slug'] ?? ''));
                }));
            }
        } catch (PDOException $e) {
            // Fallback if table doesn't exist
        }

        // Fetch footer links from database
        $is_index_page = false;
        try {
            // Determine current page for link selection
            $request_uri = $_SERVER['REQUEST_URI'];
            $path = parse_url($request_uri, PHP_URL_PATH);
            $current_page = basename($path, '.php');
            if (empty($current_page) || $current_page === '') {
                $current_page = 'index';
            }
            $is_index_page = ($current_page === 'index');

            // Select appropriate URL based on page
            if ($is_index_page) {
                // On index page, use link_url (hash only)
                $stmt = $pdo->query("SELECT column_name, link_text, link_url FROM footer_links WHERE is_active = 1 ORDER BY column_name, display_order ASC");
            } else {
                // On other pages, use secondary_link_url if available, otherwise link_url
                $stmt = $pdo->query("SELECT column_name, link_text,
                    COALESCE(NULLIF(secondary_link_url, ''), link_url) as link_url
                    FROM footer_links WHERE is_active = 1 ORDER BY column_name, display_order ASC");
            }
            $all_links = $stmt->fetchAll(PDO::FETCH_ASSOC);
            foreach ($all_links as $link) {
                if (function_exists('rh_is_feature_link_hidden') && rh_is_feature_link_hidden((string)($link['link_url'] ?? ''))) {
                    continue;
                }
                $footer_links[$link['column_name']][] = $link;
            }
        } catch (PDOException $e) {
            // Fallback if table doesn't exist
        }

        // Count total sections for dynamic grid class (now footer_links is properly initialized)
        $total_sections = count($footer_links) + 3; // +3 for Policies, Contact, Connect sections
        $grid_class = 'footer__grid';
        if ($total_sections >= 6) {
            $grid_class .= ' footer__grid--6';
        } elseif ($total_sections === 5) {
            $grid_class .= ' footer__grid--5';
        } elseif ($total_sections === 4) {
            $grid_class .= ' footer__grid--4';
        } elseif ($total_sections === 3) {
            $grid_class .= ' footer__grid--3';
        }
        ?>
        <div class="<?php echo $grid_class; ?>">
            <?php foreach ($footer_links as $column_name => $links): ?>
                <div class="footer__section">
                    <h4 class="footer__section-title"><?php echo htmlspecialchars($column_name); ?></h4>
                    <ul class="footer__links">
                        <?php foreach ($links as $link): ?>
                            <?php
                            $rawHref = trim((string)($link['link_url'] ?? ''));

                            // Normalize footer links
                            $rawHref = preg_replace('#^api/#i', '', $rawHref);
                            $normalizedHref = $rawHref;

                            $isAbsolute = preg_match('/^https?:\/\//i', $rawHref);
                            $isRooted = preg_match('/^\//', $rawHref);
                            $startsHash = strpos($rawHref, '#') === 0;
                            $hasHashAnywhere = strpos($rawHref, '#') !== false;
                            $hasPhp = (bool)preg_match('/\.php(\b|#|\?|$)/i', $rawHref);

                            // Normalize special cases
                            if ($rawHref !== '') {
                                $lc = strtolower($rawHref);
                                if ($lc === 'contact' || $lc === '#contact') {
                                    $normalizedHref = $is_index_page ? '#contact' : 'contact-us.php#contact-info';
                                } elseif ($lc === 'contact-us' || $lc === 'contact-us.php') {
                                    $normalizedHref = 'contact-us.php' . ($hasHashAnywhere ? '' : '#contact-info');
                                } elseif (!$startsHash && !$isAbsolute && !$isRooted && !$hasPhp && preg_match('/^[A-Za-z][A-Za-z0-9_\-:.]*$/', $rawHref)) {
                                    $normalizedHref = $is_index_page ? ('#' . $rawHref) : ('index.php#' . $rawHref);
                                } elseif ($normalizedHref === $rawHref && !$hasPhp) {
                                    // If URL has a hash, insert .php before the #, not at the end
                                    if ($hasHashAnywhere) {
                                        $parts = explode('#', $rawHref, 2);
                                        $normalizedHref = $parts[0] . '.php#' . $parts[1];
                                    } else {
                                        $normalizedHref = $rawHref . '.php';
                                    }
                                }
                            }
                            ?>
                            <li><a href="<?php echo htmlspecialchars($normalizedHref); ?>"><?php echo htmlspecialchars($link['link_text']); ?></a></li>
                        <?php endforeach; ?>
                    </ul>
                </div>
            <?php endforeach; ?>

            <div class="footer__section">
                <h4 class="footer__section-title">Policies</h4>
                <ul class="footer__links">
                    <?php if (!empty($policies)): ?>
                        <?php foreach ($policies as $policy): ?>
                            <li><a href="#" class="policy-link" data-policy="<?php echo htmlspecialchars($policy['slug']); ?>"><?php echo htmlspecialchars($policy['title']); ?></a></li>
                        <?php endforeach; ?>
                    <?php else: ?>
                        <?php if (!function_exists('rh_is_policy_hidden') || !rh_is_policy_hidden('booking-policy')): ?>
                        <li><a href="#" class="policy-link" data-policy="booking-policy">Booking Policy</a></li>
                        <?php endif; ?>
                        <?php if (!function_exists('rh_is_policy_hidden') || !rh_is_policy_hidden('cancellation-policy')): ?>
                        <li><a href="#" class="policy-link" data-policy="cancellation-policy">Cancellation</a></li>
                        <?php endif; ?>
                        <?php if (!function_exists('rh_is_policy_hidden') || !rh_is_policy_hidden('dining-policy')): ?>
                        <li><a href="#" class="policy-link" data-policy="dining-policy">Dining Policy</a></li>
                        <?php endif; ?>
                        <li><a href="#" class="policy-link" data-policy="faqs">FAQs</a></li>
                    <?php endif; ?>
                </ul>
            </div>

            <div class="footer__section">
                <h4 class="footer__section-title">Contact Information</h4>
                <ul class="footer__contact">
                    <li class="footer__contact-item">
                        <i class="fas fa-phone footer__contact-icon"></i>
                        <a href="tel:<?php echo htmlspecialchars(preg_replace('/[^0-9+]/', '', getSetting('phone_main', ''))); ?>"><?php echo htmlspecialchars(getSetting('phone_main', '')); ?></a>
                    </li>
                    <li class="footer__contact-item">
                        <i class="fas fa-envelope footer__contact-icon"></i>
                        <a href="mailto:<?php echo htmlspecialchars(getSetting('email_main', '')); ?>"><?php echo htmlspecialchars(getSetting('email_main', '')); ?></a>
                    </li>
                    <li class="footer__contact-item">
                        <i class="fas fa-map-marker-alt footer__contact-icon"></i>
                        <a href="https://www.google.com/maps/search/<?php echo urlencode(getSetting('address_line1', '')); ?>" target="_blank"><?php echo htmlspecialchars(getSetting('address_line1', '')); ?></a>
                    </li>
                    <li class="footer__contact-item">
                        <i class="fas fa-clock footer__contact-icon"></i>
                        <span><?php echo htmlspecialchars(getSetting('working_hours', '')); ?></span>
                    </li>
                </ul>
                <div style="margin-top: 16px;">
                    <a href="<?php echo $is_index_page ? '#contact' : 'contact-us.php'; ?>" class="footer__contact-cta" style="display: inline-flex; align-items: center; gap: 8px; padding: 10px 22px; background: rgba(139, 115, 85, 0.12); color: var(--gold, #8B7355); border-radius: 8px; font-size: 0.9rem; font-weight: 500; text-decoration: none; transition: all 0.3s ease;">
                        <i class="fas fa-paper-plane"></i> Contact Us
                    </a>
                </div>
            </div>

            <div class="footer__section">
                <h4 class="footer__section-title">Connect With Us</h4>
                <div class="footer__social">
                    <?php if (!empty(getSetting('facebook_url', ''))): ?>
                        <a href="<?php echo htmlspecialchars(getSetting('facebook_url', '')); ?>" class="footer__social-link" target="_blank" aria-label="Facebook" title="Follow us on Facebook">
                            <i class="fab fa-facebook-f"></i>
                        </a>
                    <?php endif; ?>

                    <?php if (!empty(getSetting('instagram_url', ''))): ?>
                        <a href="<?php echo htmlspecialchars(getSetting('instagram_url', '')); ?>" class="footer__social-link" target="_blank" aria-label="Instagram" title="Follow us on Instagram">
                            <i class="fab fa-instagram"></i>
                        </a>
                    <?php endif; ?>

                    <?php if (!empty(getSetting('twitter_url', ''))): ?>
                        <a href="<?php echo htmlspecialchars(getSetting('twitter_url', '')); ?>" class="footer__social-link" target="_blank" aria-label="Twitter" title="Follow us on Twitter">
                            <i class="fab fa-twitter"></i>
                        </a>
                    <?php endif; ?>

                    <?php if (!empty(getSetting('linkedin_url', ''))): ?>
                        <a href="<?php echo htmlspecialchars(getSetting('linkedin_url', '')); ?>" class="footer__social-link" target="_blank" aria-label="LinkedIn" title="Connect with us on LinkedIn">
                            <i class="fab fa-linkedin-in"></i>
                        </a>
                    <?php endif; ?>
                </div>
            </div>
        </div>

        <div class="footer__bottom">
            <div class="footer__copyright">
                <p><?php echo htmlspecialchars(footer_parse_tags(getSetting('footer_credits', '© ' . date('Y') . ' ' . getSetting('site_name', '') . '. Powered by ProManaged IT'))); ?></p>
            </div>

            <div class="footer__credits">
                <p><?php echo htmlspecialchars(footer_parse_tags(getSetting('footer_design_credit', 'Designed with ♥ for Luxury Excellence'))); ?></p>
            </div>
        </div>
    </div>
</footer>

<!-- Scroll to Top Button -->
<button id="scrollToTop" class="scroll-to-top" aria-label="Scroll to top" data-scroll-to-top>
    <svg class="scroll-to-top__icon" width="24" height="24" viewBox="0 0 24 24" fill="none" xmlns="http://www.w3.org/2000/svg">
        <path d="M12 19V5M5 12L12 5L19 12" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" />
    </svg>
</button>

<?php
// Always render policy overlay and modals - either from database or fallback defaults
?>
<div class="policy-overlay" data-policy-overlay></div>
<div class="policy-modals">
    <?php if (!empty($policies)): ?>
        <?php foreach ($policies as $policy): ?>
            <?php
            $policyContent = '';
            if (!empty($policy['summary'])) {
                $policyContent = '<p class="policy-summary">' . htmlspecialchars(footer_parse_tags($policy['summary'])) . '</p>';
            }
            $policyContent .= '<p>' . nl2br(htmlspecialchars(footer_parse_tags($policy['content']))) . '</p>';

            renderModal(
                'policy-' . htmlspecialchars($policy['slug']),
                htmlspecialchars($policy['title']),
                $policyContent,
                [
                    'size' => 'md',
                    'show_close' => true
                ]
            );
            ?>
        <?php endforeach; ?>
    <?php else: ?>
        <?php
        // Fallback default policies when database is empty
        $defaultPolicies = [
            [
                'slug' => 'booking-policy',
                'title' => 'Booking Policy',
                'content' => "To make a reservation, we require a valid credit card or advance payment equal to the first night's stay.\n\nCancellations must be made at least 48 hours prior to check-in for a full refund. Late cancellations or no-shows will be charged for the first night.\n\nCheck-in time is 2:00 PM and check-out time is 11:00 AM. Early check-in or late check-out may be available upon request and subject to availability.\n\nGuests must be at least 18 years old to book a room and check in.\n\nWe reserve the right to refuse service to anyone at our discretion."
            ],
            [
                'slug' => 'cancellation-policy',
                'title' => 'Cancellation Policy',
                'content' => "Free cancellation up to 48 hours before check-in.\n\nCancellations made within 48 hours of check-in will be charged for the first night.\n\nNo-shows will be charged for the full reservation.\n\nRefunds will be processed to the original payment method within 5-7 business days.\n\nFor group bookings (5+ rooms) or special events, different cancellation terms may apply. Please contact us directly for details.\n\nIn case of unforeseen circumstances or force majeure events, we may offer flexible cancellation options."
            ],
            [
                'slug' => 'dining-policy',
                'title' => 'Dining Policy',
                'content' => "Our on-site restaurant serves breakfast, lunch, and dinner daily.\n\nBreakfast hours: 6:30 AM - 10:00 AM\nLunch hours: 12:00 PM - 2:30 PM\nDinner hours: 6:30 PM - 10:00 PM\n\nReservations are recommended for dinner, especially on weekends.\n\nDress code: Smart casual for dinner. No beachwear or flip-flops in dining room.\n\nWe accommodate dietary restrictions and food allergies. Please inform us when making your reservation.\n\nRoom service is available from 7:00 AM to 10:00 PM.\n\nAlcoholic beverages will only be served to guests aged 18 and above."
            ],
            [
                'slug' => 'faqs',
                'title' => 'Frequently Asked Questions',
                'content' => "<strong>Q: Is parking available?</strong><br>A: Yes, we offer complimentary secure parking for all guests.\n\n<strong>Q: Do you offer airport transfers?</strong><br>A: Yes, airport transfers can be arranged at an additional cost. Please contact us in advance.\n\n<strong>Q: Is WiFi available?</strong><br>A: Complimentary high-speed WiFi is available throughout the hotel.\n\n<strong>Q: Are pets allowed?</strong><br>A: Unfortunately, we do not allow pets except for registered service animals.\n\n<strong>Q: What payment methods do you accept?</strong><br>A: We accept cash, credit cards (Visa, MasterCard, American Express), and mobile money.\n\n<strong>Q: Do you have a gym or fitness center?</strong><br>A: Yes, our fully equipped fitness center is available 24/7 for all guests.\n\n<strong>Q: Can I host events at the hotel?</strong><br>A: Yes, we have conference and event facilities. Please contact our events team for more information.\n\n<strong>Q: Is the hotel wheelchair accessible?</strong><br>A: Yes, we have wheelchair-accessible rooms and facilities. Please specify your requirements when booking."
            ]
        ];

        foreach ($defaultPolicies as $policy) {
            if (function_exists('rh_is_policy_hidden') && rh_is_policy_hidden((string)($policy['slug'] ?? ''))) {
                continue;
            }
            renderModal(
                'policy-' . $policy['slug'],
                $policy['title'],
                '<p>' . nl2br(htmlspecialchars($policy['content'])) . '</p>',
                [
                    'size' => 'md',
                    'show_close' => true
                ]
            );
        }
        ?>
    <?php endif; ?>
</div>

<!-- Unified Navigation - SPA routing so only content swaps, header never reloads.
     Loaded globally here so every page (including index.php) gets it.
     Singleton guard inside of script prevents double-init when a page also loads it.
 -->
<?php
$_js_v = function ($f) {
    $p = __DIR__ . '/../js/' . $f;
    return file_exists($p) ? filemtime($p) : 1;
};
?>
<script src="js/modal.js?v=<?php echo $_js_v('modal.js'); ?>" defer></script>
<script src="js/navigation-unified.js?v=<?php echo $_js_v('navigation-unified.js'); ?>" defer></script>
<script src="js/scroll-lazy-animations.js?v=<?php echo $_js_v('scroll-lazy-animations.js'); ?>" defer></script>
<script src="js/page-transitions.js?v=<?php echo $_js_v('page-transitions.js'); ?>" defer></script>
<script src="js/pwa-install.js?v=<?php echo $_js_v('pwa-install.js'); ?>" defer></script>

<!-- Share Script -->
<script>
    function sharePage(platform) {
        const shareData = {
            title: document.title || '<?php echo htmlspecialchars(getSetting('site_name')); ?>',
            url: window.location.href,
            text: 'Experience hospitality at <?php echo htmlspecialchars(getSetting('site_name')); ?>. Book your stay today!'
        };

        const shareUrls = {
            facebook: `https://www.facebook.com/sharer/sharer.php?u=${encodeURIComponent(shareData.url)}&t=${encodeURIComponent(shareData.title)}`,
            twitter: `https://twitter.com/intent/tweet?text=${encodeURIComponent(shareData.title)}&url=${encodeURIComponent(shareData.url)}`,
            whatsapp: `https://wa.me/?text=${encodeURIComponent(shareData.text + ' ' + shareData.url)}`
        };

        if (platform === 'email') {
            const subject = encodeURIComponent(shareData.title);
            const body = encodeURIComponent(shareData.text + '\n\n' + shareData.url);
            window.location.href = `mailto:?subject=${subject}&body=${body}`;
        } else {
            const shareUrl = shareUrls[platform];
            window.open(shareUrl, '_blank', 'width=600,height=400');
        }
    }
</script>

<!-- Footer Lazy Load Re-init for SPA Navigation -->
<script>
    (function() {
        'use strict';

        function initFooterLazyLoad() {
            const footer = document.querySelector('.footer[data-footer-lazy]');
            if (!footer) return;

            // Remove and re-add attribute to restart CSS animations
            footer.removeAttribute('data-footer-lazy');

            // Force reflow to ensure browser recognizes removal
            void footer.offsetWidth;

            // Re-add attribute to restart animations
            footer.setAttribute('data-footer-lazy', '');
        }

        // Listen for SPA navigation events
        if (typeof window !== 'undefined') {
            window.addEventListener('spa:contentLoaded', initFooterLazyLoad);
            window.addEventListener('page:navigation:end', initFooterLazyLoad);
            window.addEventListener('page:loaded', initFooterLazyLoad);
        }
    })();
</script>

<!-- Stale service-worker cleanup (public site).
     The public site uses NO service worker. An earlier deploy registered one at a
     broader scope; a stale copy still controls public pages and returns network-error
     responses (the "FetchEvent ... resulted in a network error" console errors on
     index.php). Unregister any SW visible to this public page and reload once so the
     page loads controller-free. The admin SW (scope .../admin/) is explicitly excluded
     and never touched. -->
<script>
    (function () {
        if (!('serviceWorker' in navigator)) return;
        try {
            navigator.serviceWorker.getRegistrations().then(function (regs) {
                var stale = (regs || []).filter(function (r) {
                    return (r.scope || '').indexOf('/admin/') === -1; // never the admin SW
                });
                if (!stale.length) return;
                Promise.all(stale.map(function (r) { return r.unregister().catch(function () { return false; }); }))
                    .then(function () {
                        // Only reload if a stale SW is actually controlling THIS page,
                        // and only once (guard against a reload loop).
                        if (navigator.serviceWorker.controller && !sessionStorage.getItem('rhSwCleaned')) {
                            sessionStorage.setItem('rhSwCleaned', '1');
                            window.location.reload();
                        }
                    });
            }).catch(function () {});
        } catch (e) { /* non-fatal */ }
    })();
</script>

<?php require_once __DIR__ . '/cookie-consent.php'; ?>
