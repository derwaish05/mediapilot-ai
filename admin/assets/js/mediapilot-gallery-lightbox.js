/**
 * MediaPilot Gallery — vanilla JS lightbox.
 *
 * No dependencies. No jQuery. No external libraries.
 *
 * Activates on any element with class `.mediapilot-gallery__item--lightbox` that has:
 *   href              — full-size image URL (on the <a> element)
 *   data-pswp-src     — alias for href (PhotoSwipe-compatible attribute)
 *   data-gallery      — groups items into a single lightbox sequence
 *
 * Features:
 *   - Click to open
 *   - Previous / Next navigation (button + keyboard arrows)
 *   - Close button (button + Escape key)
 *   - Click outside image closes
 *   - Prevents body scroll while open
 *   - Lazy-loads next/prev images for instant transitions
 *   - Accessible: focus trap, aria-labels, role="dialog"
 */
(function () {
    'use strict';

    // -------------------------------------------------------------------------
    // State
    // -------------------------------------------------------------------------

    var overlay     = null;
    var imgEl       = null;
    var spinner     = null;
    var counter     = null;
    var btnClose    = null;
    var btnPrev     = null;
    var btnNext     = null;

    var currentItems  = [];   // Array of <a> elements in the current gallery group
    var currentIndex  = 0;
    var isOpen        = false;

    // -------------------------------------------------------------------------
    // Overlay construction
    // -------------------------------------------------------------------------

    function buildOverlay() {
        overlay = document.createElement('div');
        overlay.id = 'mediapilot-lightbox';
        overlay.setAttribute('role', 'dialog');
        overlay.setAttribute('aria-modal', 'true');
        overlay.setAttribute('aria-label', 'Image lightbox');
        overlay.style.cssText = [
            'display:none',
            'position:fixed',
            'inset:0',
            'z-index:999999',
            'background:rgba(0,0,0,.92)',
            'align-items:center',
            'justify-content:center',
            'flex-direction:column',
        ].join(';');

        // Image wrapper (click-outside target)
        var imgWrap = document.createElement('div');
        imgWrap.style.cssText = 'position:relative;max-width:90vw;max-height:85vh;display:flex;align-items:center;justify-content:center;';

        imgEl = document.createElement('img');
        imgEl.setAttribute('alt', '');
        imgEl.style.cssText = 'max-width:90vw;max-height:85vh;object-fit:contain;border-radius:4px;display:block;transition:opacity .15s;';

        spinner = document.createElement('div');
        spinner.style.cssText = 'position:absolute;inset:0;display:flex;align-items:center;justify-content:center;color:#fff;font-size:14px;';
        spinner.textContent = 'Loading…';

        imgWrap.appendChild(spinner);
        imgWrap.appendChild(imgEl);

        // Counter  e.g. "3 / 12"
        counter = document.createElement('div');
        counter.style.cssText = 'color:rgba(255,255,255,.6);font-size:13px;margin-top:10px;font-family:system-ui,sans-serif;user-select:none;';

        // Close button
        btnClose = makeButton('×', 'Close lightbox', [
            'position:fixed',
            'top:16px',
            'right:20px',
            'font-size:28px',
            'line-height:1',
            'color:#fff',
            'background:none',
            'border:none',
            'cursor:pointer',
            'padding:4px 8px',
            'border-radius:4px',
        ]);
        btnClose.addEventListener('click', close);

        // Prev button
        btnPrev = makeButton('❮', 'Previous image', [
            'position:fixed',
            'left:16px',
            'top:50%',
            'transform:translateY(-50%)',
            'font-size:22px',
            'color:#fff',
            'background:rgba(255,255,255,.15)',
            'border:none',
            'border-radius:50%',
            'width:44px',
            'height:44px',
            'cursor:pointer',
            'display:flex',
            'align-items:center',
            'justify-content:center',
        ]);
        btnPrev.addEventListener('click', function (e) { e.stopPropagation(); prev(); });

        // Next button
        btnNext = makeButton('❯', 'Next image', [
            'position:fixed',
            'right:16px',
            'top:50%',
            'transform:translateY(-50%)',
            'font-size:22px',
            'color:#fff',
            'background:rgba(255,255,255,.15)',
            'border:none',
            'border-radius:50%',
            'width:44px',
            'height:44px',
            'cursor:pointer',
            'display:flex',
            'align-items:center',
            'justify-content:center',
        ]);
        btnNext.addEventListener('click', function (e) { e.stopPropagation(); next(); });

        // Click outside image → close
        overlay.addEventListener('click', function (e) {
            if (e.target === overlay) close();
        });

        overlay.appendChild(btnClose);
        overlay.appendChild(btnPrev);
        overlay.appendChild(imgWrap);
        overlay.appendChild(counter);
        overlay.appendChild(btnNext);

        document.body.appendChild(overlay);
    }

    function makeButton(text, ariaLabel, styles) {
        var btn = document.createElement('button');
        btn.type = 'button';
        btn.textContent = text;
        btn.setAttribute('aria-label', ariaLabel);
        btn.style.cssText = styles.join(';');
        return btn;
    }

    // -------------------------------------------------------------------------
    // Open / close / navigate
    // -------------------------------------------------------------------------

    function open(items, index) {
        currentItems = items;
        currentIndex = index;
        isOpen = true;

        overlay.style.display = 'flex';
        document.body.style.overflow = 'hidden';

        showImage(currentIndex);
        btnClose.focus();

        // Preload adjacent images for instant navigation.
        preload(index - 1);
        preload(index + 1);
    }

    function close() {
        if (!isOpen) return;
        isOpen = false;
        overlay.style.display = 'none';
        document.body.style.overflow = '';
        imgEl.src = '';
    }

    function prev() {
        if (currentIndex > 0) {
            currentIndex--;
            showImage(currentIndex);
            preload(currentIndex - 1);
        }
    }

    function next() {
        if (currentIndex < currentItems.length - 1) {
            currentIndex++;
            showImage(currentIndex);
            preload(currentIndex + 1);
        }
    }

    function showImage(index) {
        var item   = currentItems[index];
        var src    = item.getAttribute('data-pswp-src') || item.getAttribute('href') || '';
        var altTxt = item.getAttribute('data-title') || item.querySelector('img') && item.querySelector('img').getAttribute('alt') || '';

        // Show spinner while loading.
        imgEl.style.opacity = '0';
        spinner.style.display = 'flex';

        imgEl.onload = function () {
            spinner.style.display = 'none';
            imgEl.style.opacity = '1';
        };
        imgEl.onerror = function () {
            spinner.textContent = 'Failed to load image';
        };

        imgEl.src   = src;
        imgEl.alt   = altTxt;

        // Update counter.
        if (currentItems.length > 1) {
            counter.textContent = (index + 1) + ' / ' + currentItems.length;
            counter.style.display = 'block';
        } else {
            counter.style.display = 'none';
        }

        // Update nav button visibility.
        btnPrev.style.opacity = index === 0 ? '0.25' : '1';
        btnPrev.disabled      = index === 0;
        btnNext.style.opacity = index === currentItems.length - 1 ? '0.25' : '1';
        btnNext.disabled      = index === currentItems.length - 1;
    }

    function preload(index) {
        if (index < 0 || index >= currentItems.length) return;
        var item = currentItems[index];
        var src  = item.getAttribute('data-pswp-src') || item.getAttribute('href') || '';
        if (src) {
            var img = new Image();
            img.src = src;
        }
    }

    // -------------------------------------------------------------------------
    // Event delegation
    // -------------------------------------------------------------------------

    function onBodyClick(e) {
        var item = e.target.closest('.mediapilot-gallery__item--lightbox');
        if (!item) return;
        e.preventDefault();

        var galleryId = item.getAttribute('data-gallery') || 'mediapilot-gallery';

        // Collect all items in the same gallery group on the page.
        var allItems = Array.prototype.slice.call(
            document.querySelectorAll('.mediapilot-gallery__item--lightbox[data-gallery="' + galleryId + '"]')
        );

        var index = allItems.indexOf(item);
        if (index === -1) index = 0;

        open(allItems, index);
    }

    function onKeyDown(e) {
        if (!isOpen) return;

        switch (e.key) {
            case 'Escape':
                e.preventDefault();
                close();
                break;
            case 'ArrowLeft':
                e.preventDefault();
                prev();
                break;
            case 'ArrowRight':
                e.preventDefault();
                next();
                break;
        }
    }

    // -------------------------------------------------------------------------
    // Init
    // -------------------------------------------------------------------------

    function init() {
        buildOverlay();
        document.addEventListener('click', onBodyClick);
        document.addEventListener('keydown', onKeyDown);
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', init);
    } else {
        init();
    }
}());
