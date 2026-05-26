(function () {
    'use strict';

    if (window.__roomReviewsScriptLoaded) {
        if (typeof window.__initRoomReviews === 'function') {
            window.__initRoomReviews();
        }
        return;
    }

    window.__roomReviewsScriptLoaded = true;

    function initRoomReviews() {
        const reviewsSection = document.getElementById('reviews');
        const reviewsList = document.getElementById('reviewsList');
        const reviewsEmpty = document.getElementById('reviewsEmpty');
        const reviewsPagination = document.getElementById('reviewsPagination');
        const currentPageEl = document.getElementById('currentPage');
        const totalPagesEl = document.getElementById('totalPages');

        if (!reviewsSection || !reviewsList) {
            return;
        }

        if (reviewsSection.dataset.reviewsInitialized === '1') {
            return;
        }
        reviewsSection.dataset.reviewsInitialized = '1';

        const prevBtn = reviewsPagination ? reviewsPagination.querySelector('.editorial-testimonials-pagination-btn--prev') : null;
        const nextBtn = reviewsPagination ? reviewsPagination.querySelector('.editorial-testimonials-pagination-btn--next') : null;

        const roomId = reviewsSection.getAttribute('data-room-id');
        if (!roomId) {
            reviewsList.style.display = 'none';
            if (reviewsPagination) {
                reviewsPagination.style.display = 'none';
            }
            if (reviewsEmpty) {
                reviewsEmpty.style.display = 'flex';
            }
            return;
        }

        let allReviews = [];
        let currentPage = 1;
        let totalPages = 1;
        const reviewsPerPage = 3;

        function escapeHtml(value) {
            return String(value ?? '')
                .replace(/&/g, '&amp;')
                .replace(/</g, '&lt;')
                .replace(/>/g, '&gt;')
                .replace(/"/g, '&quot;')
                .replace(/'/g, '&#039;');
        }

        function renderRatingStars(ratingValue) {
            const rating = Number(ratingValue || 0);
            const fullStars = Math.max(0, Math.floor(rating));
            const hasHalfStar = (rating - fullStars) >= 0.5;
            const emptyStars = Math.max(0, 5 - fullStars - (hasHalfStar ? 1 : 0));

            return '<i class="fas fa-star"></i>'.repeat(fullStars) +
                (hasHalfStar ? '<i class="fas fa-star-half-alt"></i>' : '') +
                '<i class="far fa-star"></i>'.repeat(emptyStars);
        }

        function displayReviews(reviews) {
            if (!reviews || reviews.length === 0) {
                reviewsList.style.display = 'none';
                if (reviewsPagination) {
                    reviewsPagination.style.display = 'none';
                }
                if (reviewsEmpty) {
                    reviewsEmpty.style.display = 'flex';
                }
                return;
            }

            if (reviewsEmpty) {
                reviewsEmpty.style.display = 'none';
            }
            reviewsList.style.display = 'grid';

            totalPages = Math.max(1, Math.ceil(reviews.length / reviewsPerPage));
            const start = (currentPage - 1) * reviewsPerPage;
            const end = start + reviewsPerPage;
            const pageReviews = reviews.slice(start, end);

            reviewsList.innerHTML = pageReviews.map(review => `
                <div class="editorial-testimonial-card">
                    <div class="editorial-testimonial-quote">"</div>
                    <p class="editorial-testimonial-text">${escapeHtml(review.comment || 'A wonderful experience!')}</p>
                    <div class="editorial-testimonial-footer">
                        <div class="editorial-testimonial-author">
                            <span class="editorial-testimonial-author-name">${escapeHtml(review.guest_name || 'Valued Guest')}</span>
                        </div>
                        <div class="editorial-testimonial-rating">
                            ${renderRatingStars(review.rating)}
                        </div>
                    </div>
                </div>
            `).join('');

            if (currentPageEl) {
                currentPageEl.textContent = String(currentPage);
            }
            if (totalPagesEl) {
                totalPagesEl.textContent = String(totalPages);
            }

            if (prevBtn) {
                prevBtn.disabled = currentPage === 1;
            }
            if (nextBtn) {
                nextBtn.disabled = currentPage === totalPages;
                nextBtn.style.display = totalPages > 1 ? 'inline-flex' : 'none';
            }

            if (reviewsPagination) {
                reviewsPagination.style.display = totalPages > 1 ? 'flex' : 'none';
            }
        }

        function fetchReviews() {
            reviewsList.innerHTML = `
                <div class="editorial-testimonial-card editorial-testimonial-loading">
                    <div class="editorial-testimonial-quote">"</div>
                    <p class="editorial-testimonial-text"><i class="fas fa-spinner fa-spin"></i> Loading reviews...</p>
                </div>
            `;

            if (typeof window.fetch !== 'function') {
                displayReviews([]);
                return;
            }

            const reviewsUrl = `api/reviews.php?room_id=${encodeURIComponent(roomId)}&status=approved&limit=100`;
            const controller = typeof AbortController !== 'undefined' ? new AbortController() : null;
            let timeoutHandle = null;

            if (controller) {
                timeoutHandle = window.setTimeout(() => {
                    controller.abort();
                }, 12000);
            }

            fetch(reviewsUrl, controller ? { signal: controller.signal } : undefined)
                .then(response => {
                    if (!response.ok) {
                        throw new Error('HTTP ' + response.status);
                    }
                    return response.json();
                })
                .then(data => {
                    let reviews = [];
                    if (data && Array.isArray(data.reviews)) {
                        reviews = data.reviews;
                    } else if (data && data.data && Array.isArray(data.data.reviews)) {
                        reviews = data.data.reviews;
                    }

                    if (data && data.success) {
                        allReviews = reviews;
                        currentPage = 1;
                        displayReviews(allReviews);
                    } else {
                        displayReviews([]);
                    }
                })
                .catch(() => {
                    displayReviews([]);
                })
                .finally(() => {
                    if (timeoutHandle) {
                        window.clearTimeout(timeoutHandle);
                    }
                });
        }

        if (prevBtn) {
            prevBtn.addEventListener('click', () => {
                if (currentPage > 1) {
                    currentPage--;
                    displayReviews(allReviews);
                    reviewsSection.scrollIntoView({ behavior: 'smooth', block: 'start' });
                }
            });
        }

        if (nextBtn) {
            nextBtn.addEventListener('click', () => {
                if (currentPage < totalPages) {
                    currentPage++;
                    displayReviews(allReviews);
                    reviewsSection.scrollIntoView({ behavior: 'smooth', block: 'start' });
                }
            });
        }

        fetchReviews();
    }

    window.__initRoomReviews = initRoomReviews;

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', initRoomReviews);
    } else {
        initRoomReviews();
    }

    window.addEventListener('spa:contentLoaded', initRoomReviews);
    window.addEventListener('page:navigation:end', initRoomReviews);
})();
