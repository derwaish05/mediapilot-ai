/* MediaPilot AI — custom-post-type list-table sidebar positioning bridge.
   Wraps the posts-filter form next to the React folder sidebar. No data needed. */
(function () {
    'use strict';

    var placed = false;

    function positionSidebar() {
        if (placed) return;

        var sidebarPortal = document.getElementById('mediapilot-sidebar-portal');
        if (!sidebarPortal) return;

        var postsFilter = document.getElementById('posts-filter');
        if (!postsFilter) return;

        var wrap = postsFilter.parentNode;
        if (!wrap) return;

        // Already wired up.
        if (document.getElementById('mediapilot-cpt-layout')) {
            sidebarPortal.style.cssText = 'display:block;flex-shrink:0;width:220px;min-height:500px;border-right:1px solid #e2e8f0;overflow:hidden;position:relative;background:#fff;';
            placed = true;
            return;
        }

        // Build flex layout wrapper.
        var layout = document.createElement('div');
        layout.id  = 'mediapilot-cpt-layout';
        layout.style.cssText = 'display:flex;flex-direction:row;align-items:flex-start;';

        // Content column (holds the existing posts-filter form).
        var content = document.createElement('div');
        content.id  = 'mediapilot-cpt-content';
        content.style.cssText = 'flex:1;min-width:0;overflow-x:auto;';

        // Style the sidebar portal.
        sidebarPortal.style.cssText = 'display:block;flex-shrink:0;width:220px;min-height:500px;border-right:1px solid #e2e8f0;overflow:hidden;position:relative;background:#fff;';

        // Insert the layout wrapper where postsFilter currently is.
        wrap.insertBefore(layout, postsFilter);

        // Move postsFilter inside the content column.
        content.appendChild(postsFilter);

        // Assemble: sidebar | content.
        layout.appendChild(sidebarPortal);
        layout.appendChild(content);

        placed = true;
    }

    function tryBoot() {
        positionSidebar();
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', tryBoot);
    } else {
        tryBoot();
    }

    // Watch for the list table rendering or being re-rendered. Uses a setTimeout
    // throttle (NOT requestAnimationFrame, which is paused in background/hidden
    // tabs) so placement happens reliably regardless of tab visibility. A bounded
    // timer poll backs up the observer in case the table rendered before this ran.
    var throttleTimer = null;
    var bootObserver = new MutationObserver(function () {
        if (throttleTimer) { return; }
        throttleTimer = setTimeout(function () {
            throttleTimer = null;
            tryBoot();
        }, 50);
    });
    bootObserver.observe(document.body, { childList: true, subtree: true });

    var pollCount = 0;
    var pollTimer = setInterval(function () {
        pollCount++;
        if (!placed) { tryBoot(); }
        if (placed || pollCount > 120) {
            clearInterval(pollTimer);
            setTimeout(function () { bootObserver.disconnect(); }, 60000);
        }
    }, 250);
}());
