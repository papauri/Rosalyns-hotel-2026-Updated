/**
 * Page Transitions & Scroll Animations
 * Rosalyn's Hotel 2026
 * Passalacqua-inspired smooth page loading and scroll animations
 * 
 * Features:
 * - Smooth page transitions with fade effects
 * - Scroll-triggered animations with Intersection Observer
 * - Hero reveal animations
 * - Reduced motion support
 * - SPA navigation integration
 * - Performance optimized with requestAnimationFrame
 * - Header scroll effects
 * - Scroll-to-top button
 * - Enhanced smooth scrolling with inertia support
 */

(function() {
    'use strict';

    // ============================================
    // CONFIGURATION
    // ============================================
    
    const CONFIG = {
        // Page transition settings
        pageLoadDelay: 500,               // Minimum visible time for loader (ms)
        pageFadeDuration: 720,            // Page fade-in duration (ms)
        pageTransitionOutDuration: 300,   // Page transition out duration (ms)
        pageTransitionInDuration: 600,    // Page transition in duration (ms)
        pageTransitionEasing: 'cubic-bezier(0.16, 1, 0.3, 1)',
        
        // Scroll animation settings
        scrollThreshold: 0.25,            // Trigger when 25% visible (increased for earlier reveal)
        scrollRootMargin: '0px 0px -40px 0px',  // Reduced offset for earlier trigger
        staggerDelay: 72,                 // Delay between staggered animations (ms)
        mobileStaggerDelay: 36,           // Mobile stagger delay (ms)
        
        // Hero animation settings
        heroRevealDelay: 300,             // Delay before hero reveal (ms)
        heroStaggerDelay: 120,            // Delay between hero elements (ms)
        
        // Performance settings
        enableParallax: true,             // Enable subtle parallax effects
        parallaxSpeed: 0.08,              // Parallax speed factor (~0.08)
        respectReducedMotion: true,       // Respect prefers-reduced-motion
        
        // Header scroll settings
        headerScrollThreshold: 50,        // Pixels scrolled before adding class
        
        // Scroll-to-top settings
        scrollTopThreshold: 300,          // Pixels scrolled before showing button
    };

    // ============================================
    // PAGE LOADER SYSTEM
    // ============================================
    
    const PageLoader = {
        loader: null,
        isLoaded: false,
        
        init() {
            this.loader = document.getElementById('page-loader');
            if (!this.loader) return;
            
            // Hide loader when page is ready
            if (document.readyState === 'complete') {
                this.hide();
            } else {
                window.addEventListener('load', () => this.hide());
            }
            
            // Handle pageshow for bfcache
            window.addEventListener('pageshow', (e) => {
                if (e.persisted) {
                    this.hide();
                }
            });
        },
        
        hide() {
            if (this.isLoaded || !this.loader) return;
            
            setTimeout(() => {
                // Use proper CSS classes for smooth transition
                this.loader.classList.add('loader--hiding');
                this.loader.classList.remove('loader--active');
                
                setTimeout(() => {
                    this.loader.classList.add('loader--hidden');
                    this.loader.classList.remove('loader--hiding');
                }, 500);
                
                this.isLoaded = true;
                
                // Trigger page loaded event
                document.body.classList.add('page-loaded');
                window.dispatchEvent(new CustomEvent('page:loaded'));
            }, CONFIG.pageLoadDelay);
        },
        
        show(destinationPage) {
            if (!this.loader) return;
            this.isLoaded = false;
            // Update loader subtext to show destination page
            if (destinationPage && typeof window.updateLoaderSubtext === 'function') {
                window.updateLoaderSubtext(destinationPage);
            }
            // Use proper CSS classes
            this.loader.classList.remove('loader--hidden', 'loader--hiding');
            this.loader.classList.add('loader--active');
            document.body.classList.remove('page-loaded');
        }
    };

    // ============================================
    // PAGE TRANSITION SYSTEM
    // ============================================
    
    const PageTransitions = {
        isTransitioning: false,
        
        init() {
            // Listen for SPA navigation events
            window.addEventListener('page:navigation:start', () => this.onNavigationStart());
            window.addEventListener('page:navigation:end', () => this.onNavigationEnd());
            
            // Listen for unified navigation events
            window.addEventListener('spa:contentLoaded', () => this.onSPAContentLoaded());
            
            // Add initial page class
            document.body.classList.add('page-transition-enabled');
        },
        
        onNavigationStart() {
            if (this.isTransitioning) return;
            this.isTransitioning = true;
            
            // Fade out current page
            document.body.classList.add('page-transitioning-out', 'page-loading');
            
            // Dispatch event for other systems
            window.dispatchEvent(new CustomEvent('page:transition:start'));
        },
        
        onNavigationEnd() {
            // Fade in new page
            document.body.classList.remove('page-transitioning-out');
            document.body.classList.add('page-transitioning-in');
            
            setTimeout(() => {
                document.body.classList.remove('page-transitioning-in', 'page-loading');
                this.isTransitioning = false;
                
                // Reinitialize scroll animations
                ScrollAnimations.refresh();
                HeaderScroll.refresh();
                
                // Dispatch event for other systems
                window.dispatchEvent(new CustomEvent('page:transition:end'));
            }, CONFIG.pageTransitionInDuration);
        },
        
        onSPAContentLoaded() {
            // Handle SPA content loaded event from unified navigation
            this.isTransitioning = false;
            document.body.classList.remove('page-transitioning-out', 'page-transitioning-in', 'page-loading');
            
            // Refresh animations
            ScrollAnimations.refresh();
            HeaderScroll.refresh();
        }
    };

    // ============================================
    // HERO ANIMATIONS
    // ============================================
    
    const HeroAnimations = {
        heroSection: null,
        revealElements: [],
        
        init() {
            this.heroSection = document.querySelector('.hero');
            if (!this.heroSection) return;
            
            // Get elements to animate
            this.revealElements = Array.from(
                this.heroSection.querySelectorAll('[data-hero-reveal], .hero-content > *')
            );
            
            if (this.revealElements.length === 0) return;
            
            // Set initial state
            this.setInitialState();
            
            // Start reveal animation
            this.startReveal();
        },
        
        setInitialState() {
            // Check for reduced motion
            if (this.prefersReducedMotion()) {
                this.revealElements.forEach(el => {
                    el.style.opacity = '1';
                    el.style.transform = 'translateY(0)';
                });
                return;
            }
            
            // Set initial hidden state
            this.revealElements.forEach(el => {
                el.style.opacity = '0';
                el.style.transform = 'translateY(30px)';
                el.style.transition = `opacity ${CONFIG.pageFadeDuration}ms ${CONFIG.pageTransitionEasing}, transform ${CONFIG.pageFadeDuration}ms ${CONFIG.pageTransitionEasing}`;
            });
        },
        
        startReveal() {
            if (this.prefersReducedMotion()) return;
            
            // Wait for page load
            const startDelay = window.performance.now() < 500 ? CONFIG.heroRevealDelay : 0;
            
            setTimeout(() => {
                this.revealElements.forEach((el, index) => {
                    setTimeout(() => {
                        el.style.opacity = '1';
                        el.style.transform = 'translateY(0)';
                        el.classList.add('hero-revealed');
                    }, index * CONFIG.heroStaggerDelay);
                });
            }, startDelay);
        },
        
        prefersReducedMotion() {
            return CONFIG.respectReducedMotion && 
                   window.matchMedia('(prefers-reduced-motion: reduce)').matches;
        }
    };

    // ============================================
    // SCROLL ANIMATIONS
    // ============================================
    
    const ScrollAnimations = {
        observer: null,
        animatedElements: new WeakSet(),
        parallaxElements: [],
        ticking: false,
        
        init() {
            // Check for reduced motion
            if (this.prefersReducedMotion()) {
                this.showAllImmediately();
                return;
            }
            
            // Create Intersection Observer
            this.createObserver();
            
            // Observe elements
            this.observeElements();
            
            // Setup parallax
            if (CONFIG.enableParallax) {
                this.initParallax();
            }
            
            // Listen for page changes
            window.addEventListener('page:loaded', () => this.refresh());
            window.addEventListener('spa:contentLoaded', () => this.refresh());
        },
        
        prefersReducedMotion() {
            return CONFIG.respectReducedMotion && 
                   window.matchMedia('(prefers-reduced-motion: reduce)').matches;
        },
        
        createObserver() {
            // Disconnect existing observer if any (prevents memory leaks)
            if (this.observer) {
                this.observer.disconnect();
            }
            
            const options = {
                root: null,
                rootMargin: CONFIG.scrollRootMargin,
                threshold: CONFIG.scrollThreshold
            };
            
            this.observer = new IntersectionObserver((entries) => {
                entries.forEach(entry => {
                    if (entry.isIntersecting) {
                        this.animateElement(entry.target);
                    }
                });
            }, options);
        },
        
        observeElements() {
            // Elements to animate - comprehensive list from both files
            const selectors = [
                '.reveal-on-scroll',
                '.scroll-reveal',
                '.fade-in-up',
                '.editorial-room-card',
                '.editorial-facility-card',
                '.editorial-testimonial-card',
                '.editorial-event-card',
                '.editorial-gallery-item',
                '[data-scroll-animation]',
                // From main.js
                '.room-card',
                '.facility-card',
                '.testimonial-card',
                '.editorial-about',
                '.about-section',
                '.rooms-section',
                '.facilities-section',
                '.testimonials-section',
                '.gallery-section',
                '.events-section',
                '.section-header',
                '.room-tile',
                '.hotel-review-card',
                '.about-content',
                '.about-image-wrapper',
                '.booking-cta',
                '.lakeside-reveal'
            ];
            
            selectors.forEach(selector => {
                document.querySelectorAll(selector).forEach(el => {
                    // Skip null elements and ensure observer exists
                    if (!el || !this.observer) return;
                    
                    if (!this.animatedElements.has(el)) {
                        this.setInitialState(el);
                        this.observer.observe(el);
                    }
                });
            });
        },
        
        setInitialState(element) {
            // Skip if already initialized
            if (element.classList.contains('scroll-animate-init')) return;
            
            element.classList.add('scroll-animate-init');
            
            // Set initial styles
            element.style.opacity = '0';
            element.style.transform = 'translateY(15px)';  // Reduced from 30px for gentler animation
            element.style.transition = `opacity ${CONFIG.pageTransitionInDuration}ms ${CONFIG.pageTransitionEasing}, transform ${CONFIG.pageTransitionInDuration}ms ${CONFIG.pageTransitionEasing}`;
        },
        
        animateElement(element) {
            // Skip if already animated
            if (this.animatedElements.has(element)) return;
            
            this.animatedElements.add(element);
            
            // Get delay from data attribute or calculate stagger
            const delay = this.calculateDelay(element);
            
            setTimeout(() => {
                element.style.opacity = '1';
                element.style.transform = 'translateY(0)';
                element.classList.add('scroll-animated', 'revealed', 'lakeside-visible');
                
                // Stop observing (guard against null observer)
                if (this.observer) {
                    this.observer.unobserve(element);
                }
                
                // Add to parallax if has image
                if (CONFIG.enableParallax && element.querySelector('img')) {
                    this.addParallax(element);
                }
            }, delay);
        },
        
        calculateDelay(element) {
            // Check for data attribute
            const dataDelay = element.dataset.scrollDelay;
            if (dataDelay) return parseInt(dataDelay) * 1000;
            
            // Calculate stagger delay based on position
            const isMobile = window.innerWidth < 768;
            const baseDelay = isMobile ? CONFIG.mobileStaggerDelay : CONFIG.staggerDelay;
            
            // Get all siblings with same parent
            const siblings = Array.from(element.parentElement.children)
                .filter(el => el.matches('.reveal-on-scroll, .scroll-reveal, .fade-in-up, .editorial-room-card, .editorial-facility-card, .room-card, .facility-card, .testimonial-card, .room-tile'));
            
            const index = siblings.indexOf(element);
            return index * baseDelay;
        },
        
        initParallax() {
            window.addEventListener('scroll', () => {
                if (!this.ticking) {
                    requestAnimationFrame(() => {
                        this.updateParallax();
                        this.ticking = false;
                    });
                    this.ticking = true;
                }
            }, { passive: true });
        },
        
        addParallax(element) {
            const img = element.querySelector('img');
            if (!img) return;
            
            this.parallaxElements.push({
                element,
                img,
                speed: CONFIG.parallaxSpeed
            });
        },
        
        updateParallax() {
            const scrollTop = window.pageYOffset;
            
            this.parallaxElements.forEach(item => {
                const rect = item.element.getBoundingClientRect();
                const elementTop = rect.top + scrollTop;
                const relativeY = (scrollTop - elementTop) * item.speed;
                
                // Only animate when close to viewport
                if (Math.abs(relativeY) < 100) {
                    item.img.style.transform = `translateY(${relativeY}px) scale(1.05)`;
                }
            });
        },
        
        showAllImmediately() {
            const elements = document.querySelectorAll(
                '.reveal-on-scroll, .scroll-reveal, .fade-in-up, .editorial-room-card, .editorial-facility-card, .room-card, .facility-card, .testimonial-card, .room-tile'
            );
            
            elements.forEach(el => {
                el.style.opacity = '1';
                el.style.transform = 'none';
                el.classList.add('scroll-animated', 'revealed', 'lakeside-visible');
            });
        },
        
        refresh() {
            // Re-observe elements for SPA navigation
            this.animatedElements = new WeakSet();
            this.parallaxElements = [];
            
            // Ensure observer exists before observing elements
            // This guards against null observer after SPA content swap
            if (!this.observer) {
                this.createObserver();
            }
            
            this.observeElements();
        },
        
        destroy() {
            if (this.observer) {
                this.observer.disconnect();
                this.observer = null;
            }
        }
    };

    // ============================================
    // SMOOTH SCROLL
    // ============================================
    
    const SmoothScroll = {
        mutationObserver: null,
        
        init() {
            // Intercept anchor links
            document.addEventListener('click', (e) => this.handleClick(e), true);
            
            // Handle browser back/forward buttons
            window.addEventListener('popstate', () => this.handlePopState());
            
            // Initialize smooth scrolling for existing links
            this.initSmoothScrolling();
            
            // Observe DOM for dynamically added links
            this.setupMutationObserver();
        },
        
        handleClick(e) {
            const link = e.target.closest('a[href*="#"]');
            if (!link) return;
            
            const href = link.getAttribute('href');
            
            // Skip if it's just a hash or empty
            if (href === '#' || href === '') return;
            
            // Extract the hash part from the href
            let targetId = href;
            if (href.includes('#')) {
                targetId = '#' + href.split('#')[1];
            }
            
            // Skip if no hash found
            if (targetId === '#') return;
            
            // Check if target exists on current page
            const targetSection = document.querySelector(targetId);
            if (!targetSection) {
                // If target doesn't exist on this page, let the link work normally
                return;
            }
            
            // Check if this is a link to the current page
            const isCurrentPageLink = href.startsWith(window.location.pathname) ||
                                     href.startsWith('index.php') ||
                                     (href.includes('#') && !href.includes('http'));
            
            if (isCurrentPageLink) {
                // Prevent default for same-page anchors
                e.preventDefault();
                this.scrollTo(targetSection, href);
                
                // Update URL hash without page jump
                if (history.pushState) {
                    history.pushState(null, null, targetId);
                } else {
                    window.location.hash = targetId;
                }
                
                // Close mobile menu if open
                this.closeMobileMenu();
            }
        },
        
        scrollTo(target, href) {
            const headerOffset = 80;
            const elementTop = target.getBoundingClientRect().top;
            const offsetPosition = elementTop + window.pageYOffset - headerOffset;
            
            // Use InertiaScroll if available
            if (window.inertiaScroll) {
                window.inertiaScroll.scrollTo(offsetPosition);
            } else {
                // Fallback to native smooth scroll
                window.scrollTo({
                    top: offsetPosition,
                    behavior: 'smooth'
                });
            }
            
            // Special handling for contact link
            if (href && href.includes('#contact')) {
                this.highlightContact(target);
            }
        },
        
        highlightContact(targetSection) {
            // Add temporary highlight effect to contact information
            const contactInfo = targetSection.querySelector('.minimalist-contact-info') ||
                              targetSection.querySelector('.contact-info') ||
                              targetSection.querySelector('ul') ||
                              targetSection;
            contactInfo.classList.add('contact-highlighted');
            setTimeout(() => {
                contactInfo.classList.remove('contact-highlighted');
            }, 2000);
        },
        
        closeMobileMenu() {
            const mobileMenu = document.querySelector('.header__mobile');
            if (mobileMenu && mobileMenu.classList.contains('header__mobile--active')) {
                mobileMenu.classList.remove('header__mobile--active');
                const toggleBtn = document.querySelector('[data-mobile-toggle]');
                const overlay = document.querySelector('[data-mobile-overlay]');
                if (toggleBtn) {
                    toggleBtn.setAttribute('aria-expanded', 'false');
                    toggleBtn.setAttribute('aria-label', 'Toggle navigation menu');
                }
                if (overlay) overlay.classList.remove('header__overlay--active');
                document.body.style.overflow = '';
            }
        },
        
        handlePopState() {
            // Handle browser back/forward buttons
            setTimeout(() => {
                if (window.location.hash) {
                    const targetElement = document.querySelector(window.location.hash);
                    if (targetElement) {
                        const headerOffset = 80;
                        
                        if (window.inertiaScroll) {
                            const elementTop = targetElement.getBoundingClientRect().top + window.scrollY;
                            window.inertiaScroll.scrollTo(elementTop - headerOffset);
                        } else {
                            const elementPosition = targetElement.getBoundingClientRect().top;
                            const offsetPosition = elementPosition + window.pageYOffset - headerOffset;
                            
                            window.scrollTo({
                                top: offsetPosition,
                                behavior: 'smooth'
                            });
                        }
                    }
                }
            }, 100);
        },
        
        initSmoothScrolling() {
            // Initialize smooth scrolling for all anchor links
            const allLinks = document.querySelectorAll('a[href*="#"]');
            allLinks.forEach(link => {
                link.removeEventListener('click', this.handleClick);
            });
        },
        
        setupMutationObserver() {
            this.mutationObserver = new MutationObserver((mutations) => {
                mutations.forEach((mutation) => {
                    if (mutation.addedNodes.length) {
                        // Re-initialize smooth scrolling for new links
                        this.initSmoothScrolling();
                        // Notify InertiaScroll of potential layout change
                        if (window.inertiaScroll) {
                            window.inertiaScroll.onResize();
                        }
                    }
                });
            });
            
            this.mutationObserver.observe(document.body, { childList: true, subtree: true });
        },
        
        destroy() {
            if (this.mutationObserver) {
                this.mutationObserver.disconnect();
            }
            document.removeEventListener('click', this.handleClick, true);
            window.removeEventListener('popstate', this.handlePopState);
        }
    };

    // ============================================
    // HEADER SCROLL
    // ============================================
    
    const HeaderScroll = {
        header: null,
        
        init() {
            this.header = document.querySelector('.header');
            if (!this.header) return;
            
            // Initial update
            this.update(window.pageYOffset);
            
            // Listen to scroll (with InertiaScroll integration if available)
            if (window.inertiaScroll) {
                window.inertiaScroll.on(state => this.update(state.y));
            } else {
                window.addEventListener('scroll', () => this.update(window.pageYOffset));
            }
        },
        
        update(scrollY) {
            // Guard against DOM swaps (e.g., SPA updates moving/replacing header)
            if (!this.header || !document.body.contains(this.header)) {
                this.header = document.querySelector('.header');
                if (!this.header) {
                    if (!this._warnedMissingHeader) {
                        this._warnedMissingHeader = true;
                    }
                    return;
                }
                // Found header again after DOM change
                this._warnedMissingHeader = false;
            }

            if (scrollY > CONFIG.headerScrollThreshold) {
                this.header.classList.add('header--scrolled');
            } else {
                this.header.classList.remove('header--scrolled');
            }
        },
        
        refresh() {
            // Re-initialize if header element might have changed
            this.header = document.querySelector('.header');
            if (this.header) {
                this.update(window.pageYOffset);
            }
        }
    };

    // ============================================
    // SCROLL TO TOP
    // ============================================

    const ScrollToTop = {
        button: null,

        init() {
            this.button = document.getElementById('scrollToTop');
            if (!this.button) {
                return;
            }

            // Show/hide button based on scroll position
            this.update(window.pageYOffset);

            // Listen to scroll (with InertiaScroll integration if available)
            if (window.inertiaScroll) {
                window.inertiaScroll.on(state => this.update(state.y));
            } else {
                window.addEventListener('scroll', () => this.update(window.pageYOffset));
            }

            // Scroll to top on click
            this.button.addEventListener('click', () => this.scrollToTop());
        },

        update(scrollY) {
            if (!this.button) return;
            if (scrollY > CONFIG.scrollTopThreshold) {
                this.button.classList.add('scroll-to-top--visible');
            } else {
                this.button.classList.remove('scroll-to-top--visible');
            }
        },
        
        scrollToTop() {
            // Add ripple effect
            this.button.classList.add('rippling');
            setTimeout(() => this.button.classList.remove('rippling'), 600);
            
            // Get current scroll position
            const startPos = window.pageYOffset;
            const distance = startPos;
            
            // If already at top, do nothing
            if (distance === 0) return;
            
            // Calculate duration based on distance (max 2 seconds for long scrolls)
            const duration = Math.min(Math.max(distance * 0.6, 600), 2000);
            const startTime = performance.now();
            
            // Smooth easing function (smoothstep)
            const easeInOutQuad = (t) => {
                return t < 0.5 ? 2 * t * t : 1 - Math.pow(-2 * t + 2, 2) / 2;
            };
            
            // Even smoother easing for extra fancy feel
            const easeInOutCubic = (t) => {
                return t < 0.5 ? 4 * t * t * t : 1 - Math.pow(-2 * t + 2, 3) / 2;
            };
            
            // Animation loop
            const animateScroll = (currentTime) => {
                const elapsed = currentTime - startTime;
                const progress = Math.min(elapsed / duration, 1);
                const ease = easeInOutCubic(progress);
                
                // Calculate current position
                const currentPosition = startPos - (distance * ease);
                
                // Scroll to position
                window.scrollTo(0, currentPosition);
                
                // Continue animation if not complete
                if (progress < 1) {
                    requestAnimationFrame(animateScroll);
                } else {
                    // Ensure we end exactly at top
                    window.scrollTo(0, 0);
                }
            };
            
            // Start animation
            requestAnimationFrame(animateScroll);
        },

        refresh() {
            // Re-initialize if button might have changed (e.g., after SPA navigation)
            this.button = document.getElementById('scrollToTop');
            if (this.button) {
                this.update(window.pageYOffset);
                // Remove old listener to prevent duplicates
                const newButton = this.button.cloneNode(true);
                this.button.parentNode.replaceChild(newButton, this.button);
                this.button = newButton;
                this.button.addEventListener('click', () => this.scrollToTop());
            }
        }
    };

    // ============================================
    // CARD HOVER EFFECTS
    // ============================================
    
    const CardHover = {
        init() {
            // Add subtle hover effects to cards
            const cards = document.querySelectorAll('.room-card, .room-tile, .facility-card, .fancy-3d-card');
            
            cards.forEach(card => {
                card.addEventListener('mouseenter', () => {
                    card.style.transform = 'translateY(-4px)';
                    card.style.transition = 'transform 0.4s cubic-bezier(0.25, 0.1, 0.25, 1)';
                });
                
                card.addEventListener('mouseleave', () => {
                    card.style.transform = '';
                });
            });
        }
    };

    // ============================================
    // INITIALIZATION
    // ============================================

    function init() {
        // Initialize systems
        PageLoader.init();
        PageTransitions.init();
        HeroAnimations.init();
        ScrollAnimations.init();
        SmoothScroll.init();
        HeaderScroll.init();
        ScrollToTop.init();
        CardHover.init();

        // Expose to global scope for external access
        window.PageTransitions = {
            refresh: () => {
                ScrollAnimations.refresh();
                HeaderScroll.refresh();
                ScrollToTop.refresh();
            },
            showLoader: (destinationPage) => PageLoader.show(destinationPage),
            hideLoader: () => PageLoader.hide(),
            onNavigationStart: () => PageTransitions.onNavigationStart(),
            onNavigationEnd: () => PageTransitions.onNavigationEnd()
        };
    }
    
    // Auto-initialize on DOM ready
    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', init);
    } else {
        init();
    }

})();
