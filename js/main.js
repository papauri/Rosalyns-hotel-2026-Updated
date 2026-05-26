/**
 * Hotel Website - Main JavaScript
 * Premium Interactions & Animations
 * 
 * NOTE: Page transitions, scroll animations, smooth scrolling, header effects,
 * scroll-to-top, and intersection observer animations are now handled by
 * js/page-transitions.js. This file contains only non-transition site utilities.
 */

// NOTE: Navigation is now handled by js/navigation-unified.js
// This prevents conflicts between multiple navigation systems
// The unified system handles both SPA and traditional navigation with proper loader visibility

document.addEventListener('DOMContentLoaded', function() {
    // Room Featured Image AJAX Upload
    const imageUploadForm = document.getElementById('imageUploadForm');
    const currentImage = document.getElementById('currentImage');
    const currentImageContainer = document.getElementById('currentImageContainer');
    if (imageUploadForm) {
        imageUploadForm.addEventListener('submit', function(e) {
            e.preventDefault();
            const formData = new FormData(imageUploadForm);
            fetch('room-management.php', {
                method: 'POST',
                body: formData,
                headers: {
                    'X-Requested-With': 'XMLHttpRequest'
                }
            })
            .then(async res => {
                let text = await res.text();
                try {
                    let data = JSON.parse(text);
                    if (data.success && data.image_url) {
                        if (currentImage) {
                            currentImage.src = '../' + data.image_url;
                            currentImageContainer.style.display = 'block';
                        }
                        // Success: no noisy alerts in production
                    } else {
                        if (window.Modal && typeof Modal.showMessage === 'function') {
                            Modal.showMessage({
                                title: 'Upload failed',
                                message: data.message ? `<p>${data.message}</p>` : '<p>There was a problem uploading the image. Please try again.</p>'
                            });
                        } else {
                            alert(data.message || 'Upload failed.');
                        }
                    }
                } catch (err) {
                    if (window.Modal && typeof Modal.showMessage === 'function') {
                        Modal.showMessage({
                            title: 'Server error',
                            message: '<p>The server returned an unexpected response. Please try again in a moment.</p>'
                        });
                    } else {
                        alert('Upload failed. Server error or invalid response.');
                    }
                }
            })
            .catch((err) => {
                if (window.Modal && typeof Modal.showMessage === 'function') {
                    Modal.showMessage({
                        title: 'Network error',
                        message: '<p>We could not reach the server. Please check your connection and try again.</p>'
                    });
                } else {
                    alert('Upload failed. Network or server error.');
                }
            });
        });
    }

    // Time and Temperature Widget
    function updateTimeAndTemp() {
        const timeParts = new Intl.DateTimeFormat('en-GB', {
            timeZone: window._siteTimezone || 'UTC',
            hour12: true,
            hour: '2-digit',
            minute: '2-digit'
        }).formatToParts(new Date());

        const hours = timeParts.find(p => p.type === 'hour')?.value || '--';
        const minutes = timeParts.find(p => p.type === 'minute')?.value || '--';
        const ampm = (timeParts.find(p => p.type === 'dayPeriod')?.value || 'AM').toUpperCase();
        
        // Desktop widget
        const timeDisplay = document.getElementById('heroTime');
        const ampmDisplay = document.getElementById('heroAmpm');
        
        if (timeDisplay) timeDisplay.textContent = `${hours}:${minutes}`;
        if (ampmDisplay) ampmDisplay.textContent = ampm;
        
        // Mobile widget
        const timeDisplayMobile = document.getElementById('heroTimeMobile');
        const ampmDisplayMobile = document.getElementById('heroAmpmMobile');
        
        if (timeDisplayMobile) timeDisplayMobile.textContent = `${hours}:${minutes}`;
        if (ampmDisplayMobile) ampmDisplayMobile.textContent = ampm;
        
        // Update Temperature (simulated with random value for demo)
        const tempDisplay = document.getElementById('heroTemp');
        const tempDisplayMobile = document.getElementById('heroTempMobile');
        
        if (tempDisplay || tempDisplayMobile) {
            const temp = Math.round(22 + Math.random() * 8); // 22-30°C range
            if (tempDisplay) tempDisplay.textContent = `${temp}°C`;
            if (tempDisplayMobile) tempDisplayMobile.textContent = `${temp}°C`;
        }
    }
    
    // Initial update
    updateTimeAndTemp();
    
    // Update every minute
    setInterval(updateTimeAndTemp, 60000);

    // Mobile menu functionality is handled by js/navigation-unified.js
    // This prevents conflicts between multiple navigation systems
    
    // Hero carousel
    const heroSlides = document.querySelectorAll('.hero-slide');
    const heroIndicators = document.querySelectorAll('.hero-indicator');
    const prevBtns = document.querySelectorAll('.hero-prev');
    const nextBtns = document.querySelectorAll('.hero-next');
    let heroIndex = 0;
    let heroTimer;

    function setHeroSlide(index) {
        if (!heroSlides.length) return;
        heroIndex = (index + heroSlides.length) % heroSlides.length;
        heroSlides.forEach((slide, i) => {
            slide.classList.toggle('active', i === heroIndex);
        });
        heroIndicators.forEach((dot, i) => {
            dot.classList.toggle('active', i === heroIndex);
        });
        // Lazy-load: apply background-image from data-bg for active + next slide
        loadHeroBg(heroIndex);
        loadHeroBg((heroIndex + 1) % heroSlides.length);
    }

    // Load a hero slide's background image from data-bg attribute
    function loadHeroBg(idx) {
        var slide = heroSlides[idx];
        if (!slide) return;
        var imgDiv = slide.querySelector('.hero-slide-image[data-bg]');
        if (imgDiv) {
            imgDiv.style.backgroundImage = "url('" + imgDiv.getAttribute('data-bg') + "')";
            imgDiv.removeAttribute('data-bg');
        }
    }

    function nextHeroSlide() {
        setHeroSlide(heroIndex + 1);
    }

    function prevHeroSlideFn() {
        setHeroSlide(heroIndex - 1);
    }

    function startHeroAuto() {
        if (heroTimer) clearInterval(heroTimer);
        heroTimer = setInterval(nextHeroSlide, 6000);
    }

    if (heroSlides.length) {
        startHeroAuto();
        setHeroSlide(0);

        heroIndicators.forEach(dot => {
            dot.addEventListener('click', () => {
                const index = parseInt(dot.dataset.index, 10);
                setHeroSlide(index);
                startHeroAuto();
            });
        });

        nextBtns.forEach(btn => {
            btn.addEventListener('click', () => {
                nextHeroSlide();
                startHeroAuto();
            });
        });

        prevBtns.forEach(btn => {
            btn.addEventListener('click', () => {
                prevHeroSlideFn();
                startHeroAuto();
            });
        });

        // Pause on hover (desktop)
        const heroSection = document.querySelector('.hero');
        if (heroSection) {
            heroSection.addEventListener('mouseenter', () => heroTimer && clearInterval(heroTimer));
            heroSection.addEventListener('mouseleave', startHeroAuto);
        }
    }
    
    // Unified media lazy-loading strategy
    const prefersReducedMotion = window.matchMedia && window.matchMedia('(prefers-reduced-motion: reduce)').matches;
    const supportsIntersectionObserver = 'IntersectionObserver' in window;

    const mediaInHero = (el) => Boolean(el && el.closest('.hero, .rooms-hero, .header__logo'));
    const isMobileViewport = () => (window.innerWidth || document.documentElement.clientWidth || 0) <= 1023;
    const isLikelyAboveFold = (el) => {
        if (!el || !el.getBoundingClientRect) return false;
        const rect = el.getBoundingClientRect();
        const vh = window.innerHeight || document.documentElement.clientHeight || 0;
        const viewportBuffer = isMobileViewport() ? 1.7 : 1.1;
        const topCutoff = isMobileViewport() ? -220 : -120;
        return rect.top < (vh * viewportBuffer) && rect.bottom > topCutoff;
    };

    const applyLazyLoadingDefaults = (root = document) => {
        const lcpCandidate = root.querySelector('.hero__image, .hero picture img, .hero img');
        const images = root.querySelectorAll('img');
        images.forEach((img) => {
            if (img.hasAttribute('data-no-lazy')) return;

            const isLcp = lcpCandidate && img === lcpCandidate;
            const forceEager = isLcp || mediaInHero(img) || isLikelyAboveFold(img);
            const loadingValue = forceEager ? 'eager' : 'lazy';

            if (!img.hasAttribute('loading') || (forceEager && img.getAttribute('loading') === 'lazy')) {
                img.setAttribute('loading', loadingValue);
            }

            if (!img.hasAttribute('decoding')) {
                img.setAttribute('decoding', 'async');
            }

            if (isLcp) {
                img.setAttribute('fetchpriority', 'high');
            } else if (img.getAttribute('fetchpriority') === 'high') {
                img.setAttribute('fetchpriority', 'auto');
            }
        });

        const iframes = root.querySelectorAll('iframe');
        iframes.forEach((frame) => {
            if (frame.hasAttribute('data-no-lazy')) return;
            const forceEager = mediaInHero(frame) || isLikelyAboveFold(frame);
            if (!frame.hasAttribute('loading') || (forceEager && frame.getAttribute('loading') === 'lazy')) {
                frame.setAttribute('loading', forceEager ? 'eager' : 'lazy');
            }
        });

        const videos = root.querySelectorAll('video');
        videos.forEach((video) => {
            if (video.hasAttribute('data-no-lazy')) return;
            const forceEager = mediaInHero(video) || isLikelyAboveFold(video);
            if (!video.hasAttribute('preload')) {
                video.setAttribute('preload', forceEager ? 'auto' : 'metadata');
            }
        });
    };

    applyLazyLoadingDefaults(document);
    
    // JS lazy sources/backgrounds for progressively-loaded sections
    const hydrateLazyTarget = (target) => {
        if (!target) return;

        if (target.dataset.src) {
            if (target.tagName === 'SOURCE') {
                target.srcset = target.dataset.src;
            } else {
                target.src = target.dataset.src;
            }
            target.removeAttribute('data-src');
        }

        const bgSource = target.dataset.bg || target.dataset.lazyBg;
        if (bgSource) {
            target.style.backgroundImage = `url('${bgSource}')`;
            target.removeAttribute('data-bg');
            target.removeAttribute('data-lazy-bg');
        }
    };

    let sharedLazyObserver = null;
    if (supportsIntersectionObserver) {
        sharedLazyObserver = new IntersectionObserver((entries, observer) => {
            entries.forEach((entry) => {
                if (!entry.isIntersecting) return;
                hydrateLazyTarget(entry.target);
                observer.unobserve(entry.target);
            });
        }, {
            root: null,
            rootMargin: isMobileViewport() ? '650px 0px' : '420px 0px',
            threshold: 0.01
        });
    }

    let sharedRevealObserver = null;
    if (supportsIntersectionObserver && !prefersReducedMotion && !isMobileViewport()) {
        sharedRevealObserver = new IntersectionObserver((entries, observer) => {
            entries.forEach((entry) => {
                if (!entry.isIntersecting) return;
                entry.target.classList.add('is-revealed');
                observer.unobserve(entry.target);
            });
        }, {
            root: null,
            rootMargin: '120px 0px',
            threshold: 0.1
        });
    }

    const initLazyObservers = (root = document) => {
        // JS lazy sources/backgrounds for progressively-loaded sections
        const lazyTargets = root.querySelectorAll('[data-src], [data-bg], [data-lazy-bg]');
        if (supportsIntersectionObserver && sharedLazyObserver) {
            lazyTargets.forEach((target) => sharedLazyObserver.observe(target));
        } else {
            lazyTargets.forEach(hydrateLazyTarget);
        }

        // Reduced-motion-safe reveal fallback for data-lazy-reveal hooks
        const lazyRevealItems = root.querySelectorAll('[data-lazy-reveal]');
        if (lazyRevealItems.length) {
            if (prefersReducedMotion || !supportsIntersectionObserver || isMobileViewport()) {
                lazyRevealItems.forEach((el) => el.classList.add('is-revealed'));
            } else if (sharedRevealObserver) {
                lazyRevealItems.forEach((el) => sharedRevealObserver.observe(el));
            }
        }
    };

    initLazyObservers(document);

    window.addEventListener('spa:contentLoaded', () => {
        applyLazyLoadingDefaults(document);
        initLazyObservers(document);
    });

    // Policy modals - Using new Modal component
    const policyLinks = document.querySelectorAll('.policy-link');
    const policyOverlay = document.querySelector('[data-policy-overlay]');
    
        function openPolicy(slug) {
            const modalId = 'policy-' + slug;
            const modal = document.getElementById(modalId);
            
        	if (typeof Modal !== 'undefined' && Modal.open) {
                Modal.open(modalId);
            } else if (modal) {
                // Graceful fallback: activate modal classes
                modal.classList.add('modal--active');
                document.body.classList.add('modal-open');
            } else if (window.Modal && typeof Modal.showMessage === 'function') {
                Modal.showMessage({ title: 'Notice', message: '<p>Content is not available right now. Please try again later.</p>' });
            } else {
                alert('Content is not available right now. Please try again later.');
            }
        }
    
    policyLinks.forEach(link => {
        link.addEventListener('click', function(e) {
            e.preventDefault();
            e.stopPropagation();
            const slug = this.dataset.policy;
            openPolicy(slug);
        });
    });
    
    if (policyOverlay) {
        policyOverlay.addEventListener('click', function() {
            if (typeof Modal !== 'undefined' && Modal.closeAll) {
                Modal.closeAll();
            }
        });
    }

    // Room Filter Chips
    const filterChips = document.querySelectorAll('.chip');
    const roomTiles = document.querySelectorAll('.room-tile');

    if (filterChips.length > 0 && roomTiles.length > 0) {
        filterChips.forEach(chip => {
            chip.addEventListener('click', function() {
                const filterValue = this.getAttribute('data-filter');
                
                // Update active chip
                filterChips.forEach(c => c.classList.remove('active'));
                this.classList.add('active');

                // Filter rooms
                roomTiles.forEach(tile => {
                    const tileFilter = tile.getAttribute('data-filter');
                    
                    // Show/hide based on filter
                    if (filterValue === 'all-rooms' || tileFilter.includes(filterValue)) {
                        tile.style.display = '';
                        // Trigger animation
                        setTimeout(() => {
                            tile.style.opacity = '1';
                            tile.style.transform = 'translateY(0)';
                        }, 10);
                    } else {
                        tile.style.opacity = '0';
                        tile.style.transform = 'translateY(10px)';
                        setTimeout(() => {
                            tile.style.display = 'none';
                        }, 300);
                    }
                });
            });
        });

        // Add transition styles to room tiles
        roomTiles.forEach(tile => {
            tile.style.transition = 'opacity 0.3s ease, transform 0.3s ease';
            tile.style.opacity = '1';
            tile.style.transform = 'translateY(0)';
        });
    }
    
    // Hotel Gallery Carousel
    const galleryCarousel = document.querySelector('.gallery-carousel-track');
    const galleryItems = document.querySelectorAll('.gallery-carousel-item');
    const galleryPrevBtn = document.querySelector('.gallery-nav-prev');
    const galleryNextBtn = document.querySelector('.gallery-nav-next');
    const galleryDots = document.querySelectorAll('.gallery-dot');
    
    if (galleryCarousel && galleryItems.length > 0) {
        let currentGalleryIndex = 0;
        let itemsToShow = getItemsToShow();
        let cachedItemWidth = galleryItems[0].offsetWidth; // Cache to avoid forced reflow
        
        function getItemsToShow() {
            if (window.innerWidth <= 768) return 1;
            if (window.innerWidth <= 1024) return 3;
            return 4;
        }
        
        // Update cached width on resize
        const updateCachedWidth = () => {
            cachedItemWidth = galleryItems[0].offsetWidth;
        };
        
        // Debounced resize handler
        let resizeTimeout;
        window.addEventListener('resize', () => {
            clearTimeout(resizeTimeout);
            resizeTimeout = setTimeout(() => {
                itemsToShow = getItemsToShow();
                updateCachedWidth();
                updateCarousel(false);
            }, 100);
        }, { passive: true });
        
        function updateCarousel(smooth = true) {
            const itemWidth = cachedItemWidth; // Use cached value
            const gap = 20;
            const offset = currentGalleryIndex * (itemWidth + gap);
            
            galleryCarousel.style.transition = smooth ? 'transform 0.6s cubic-bezier(0.25, 0.46, 0.45, 0.94)' : 'none';
            galleryCarousel.style.transform = `translateX(-${offset}px)`;
            
            // Update active dot
            galleryDots.forEach((dot, index) => {
                dot.classList.toggle('active', index === currentGalleryIndex);
            });
        }
        
        function nextSlide() {
            const maxIndex = Math.max(0, galleryItems.length - itemsToShow);
            currentGalleryIndex = Math.min(currentGalleryIndex + 1, maxIndex);
            updateCarousel();
        }
        
        function prevSlide() {
            currentGalleryIndex = Math.max(currentGalleryIndex - 1, 0);
            updateCarousel();
        }
        
        // Navigation buttons
        if (galleryNextBtn) {
            galleryNextBtn.addEventListener('click', nextSlide);
        }
        
        if (galleryPrevBtn) {
            galleryPrevBtn.addEventListener('click', prevSlide);
        }
        
        // Dot navigation
        galleryDots.forEach((dot, index) => {
            dot.addEventListener('click', () => {
                currentGalleryIndex = index;
                updateCarousel();
            });
        });
        
        // Auto-play carousel
        let autoplayInterval = setInterval(nextSlide, 4000);
        
        // Pause on hover
        const carouselWrapper = document.querySelector('.gallery-carousel-wrapper');
        if (carouselWrapper) {
            carouselWrapper.addEventListener('mouseenter', () => {
                clearInterval(autoplayInterval);
            });
            
            carouselWrapper.addEventListener('mouseleave', () => {
                autoplayInterval = setInterval(nextSlide, 4000);
            });
        }
        
        // Responsive resize handler
        let resizeTimer;
        window.addEventListener('resize', () => {
            clearTimeout(resizeTimer);
            resizeTimer = setTimeout(() => {
                const newItemsToShow = getItemsToShow();
                if (newItemsToShow !== itemsToShow) {
                    itemsToShow = newItemsToShow;
                    currentGalleryIndex = Math.min(currentGalleryIndex, Math.max(0, galleryItems.length - itemsToShow));
                    updateCarousel(false);
                }
            }, 250);
        });
        
        // Touch/swipe support
        let touchStartX = 0;
        let touchEndX = 0;
        
        if (carouselWrapper) {
            carouselWrapper.addEventListener('touchstart', (e) => {
                touchStartX = e.touches[0].clientX;
            }, { passive: true });
            
            carouselWrapper.addEventListener('touchend', (e) => {
                touchEndX = e.changedTouches[0].clientX;
                const swipeDistance = touchStartX - touchEndX;
                
                if (Math.abs(swipeDistance) > 50) {
                    if (swipeDistance > 0) {
                        nextSlide();
                    } else {
                        prevSlide();
                    }
                }
            }, { passive: true });
        }
        
        // Initialize
        updateCarousel(false);
    }
});
