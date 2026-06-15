/**
 * MediaPilot Gallery — carousel driver.
 *
 * No dependencies. No jQuery. No external libraries.
 *
 * Activates on every element matching [data-mediapilot-carousel] that contains:
 *   .mediapilot-carousel__track   — flex row of slide items
 *   .mediapilot-carousel__btn--prev / --next — navigation buttons
 *   .mediapilot-carousel__dot     — page-indicator buttons (optional)
 *
 * Behaviour:
 *   - Slides N items per "page" (reads --mediapilot-columns CSS custom property).
 *   - Smooth CSS-transform scroll between pages.
 *   - Touch / pointer swipe support.
 *   - Keyboard left/right arrow when the carousel is focused.
 *   - Updates prev/next disabled state and active dot on every page change.
 *   - Re-initialises on window resize (debounced).
 */
(function () {
    'use strict';

    // -------------------------------------------------------------------------
    // Per-carousel state factory
    // -------------------------------------------------------------------------

    function initCarousel(wrapper) {
        var viewport  = wrapper.querySelector('.mediapilot-carousel__viewport');
        var track     = wrapper.querySelector('.mediapilot-carousel__track');
        var btnPrev   = wrapper.querySelector('.mediapilot-carousel__btn--prev');
        var btnNext   = wrapper.querySelector('.mediapilot-carousel__btn--next');
        var dots      = wrapper.querySelectorAll('.mediapilot-carousel__dot');

        if (!viewport || !track) return;

        var items     = Array.prototype.slice.call(track.children);
        var pageIndex = 0;

        // ------------------------------------------------------------------
        // Helpers
        // ------------------------------------------------------------------

        function getColumns() {
            var raw = getComputedStyle(wrapper).getPropertyValue('--mediapilot-columns');
            var n   = parseInt(raw, 10);
            return (isNaN(n) || n < 1) ? 1 : n;
        }

        function totalPages() {
            return Math.ceil(items.length / getColumns());
        }

        function goTo(index) {
            var pages = totalPages();
            // Wrap around for infinite loop.
            pageIndex = ((index % pages) + pages) % pages;

            var cols          = getColumns();
            var viewportW     = viewport.offsetWidth;
            var gapPx         = parseFloat(getComputedStyle(track).gap) || 0;
            var slideW        = (viewportW - (cols - 1) * gapPx) / cols;
            var offsetPerItem = slideW + gapPx;
            var translateX    = -(pageIndex * cols * offsetPerItem);

            track.style.transform = 'translateX(' + translateX + 'px)';

            // Buttons always enabled (infinite loop — no dead ends).
            if (btnPrev) btnPrev.disabled = false;
            if (btnNext) btnNext.disabled = false;

            // Update dots.
            dots.forEach(function (dot, i) {
                dot.classList.toggle('mediapilot-carousel__dot--active', i === pageIndex);
            });
        }

        // ------------------------------------------------------------------
        // Button clicks
        // ------------------------------------------------------------------

        if (btnPrev) {
            btnPrev.addEventListener('click', function (e) {
                e.stopPropagation();
                goTo(pageIndex - 1);
            });
        }

        if (btnNext) {
            btnNext.addEventListener('click', function (e) {
                e.stopPropagation();
                goTo(pageIndex + 1);
            });
        }

        // Dot clicks.
        dots.forEach(function (dot, i) {
            dot.addEventListener('click', function () { goTo(i); });
        });

        // ------------------------------------------------------------------
        // Keyboard
        // ------------------------------------------------------------------

        wrapper.setAttribute('tabindex', '0');
        wrapper.addEventListener('keydown', function (e) {
            if (e.key === 'ArrowLeft')  { e.preventDefault(); goTo(pageIndex - 1); }
            if (e.key === 'ArrowRight') { e.preventDefault(); goTo(pageIndex + 1); }
        });

        // ------------------------------------------------------------------
        // Touch / pointer swipe
        // ------------------------------------------------------------------

        var touchStartX = 0;
        var touchStartY = 0;
        var swiping     = false;

        wrapper.addEventListener('pointerdown', function (e) {
            touchStartX = e.clientX;
            touchStartY = e.clientY;
            swiping     = true;
        });

        wrapper.addEventListener('pointerup', function (e) {
            if (!swiping) return;
            swiping = false;

            var dx = e.clientX - touchStartX;
            var dy = e.clientY - touchStartY;

            // Only act when horizontal movement dominates.
            if (Math.abs(dx) < 30 || Math.abs(dx) < Math.abs(dy)) return;

            if (dx < 0) {
                goTo(pageIndex + 1);
            } else {
                goTo(pageIndex - 1);
            }
        });

        wrapper.addEventListener('pointercancel', function () { swiping = false; });

        // Prevent click-through on drag (lightbox intercept).
        wrapper.addEventListener('click', function (e) {
            var dx = Math.abs(e.clientX - touchStartX);
            if (dx > 10) e.stopPropagation();
        }, true);

        // ------------------------------------------------------------------
        // Resize: recalculate offset without changing page
        // ------------------------------------------------------------------

        function onResize() { goTo(pageIndex); }

        var resizeTimer;
        window.addEventListener('resize', function () {
            clearTimeout(resizeTimer);
            resizeTimer = setTimeout(onResize, 120);
        });

        // ------------------------------------------------------------------
        // Initial render
        // ------------------------------------------------------------------

        goTo(0);
    }

    // -------------------------------------------------------------------------
    // Boot: init all carousels on the page
    // -------------------------------------------------------------------------

    function init() {
        var carousels = document.querySelectorAll('[data-mediapilot-carousel]');
        carousels.forEach(function (wrapper) {
            // Avoid double-init.
            if (wrapper.getAttribute('data-mediapilot-carousel-init')) return;
            wrapper.setAttribute('data-mediapilot-carousel-init', '1');
            initCarousel(wrapper);
        });
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', init);
    } else {
        init();
    }

    // Expose for external callers (e.g. admin preview via React).
    window.MediaPilotCarousel = { init: init, initCarousel: initCarousel };
}());
