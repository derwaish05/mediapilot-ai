/* MediaPilot AI — media-library (upload.php) bridge.
   Positions the React folder sidebar + portal roots inside the media frame,
   keeps the selection store in sync, and bridges folder/sort filters to the
   wp.media backbone collection.
   Localised data: window.MediaPilotMediaBridge.i18n.unusedTitle */
(function () {
    'use strict';

    var i18n = ( window.MediaPilotMediaBridge && window.MediaPilotMediaBridge.i18n ) || {};

    // -------------------------------------------------------------------------
    // 1. MutationObserver bridge — WP selection → mediapilot:selection-change event
    // -------------------------------------------------------------------------

    var observer = null;

    function getSelectedIds() {
        var els = document.querySelectorAll('.attachment.selected');
        var ids = [];
        els.forEach(function (el) {
            var id = parseInt(el.getAttribute('data-id') || '0', 10);
            if (id > 0) ids.push(id);
        });
        return ids;
    }

    function dispatchSelectionChange() {
        var ids = getSelectedIds();
        window.dispatchEvent(new CustomEvent('mediapilot:selection-change', { detail: { ids: ids } }));
    }

    function attachObserver() {
        var grid = document.querySelector('.attachments');
        if (!grid || observer) return;

        observer = new MutationObserver(function (mutations) {
            var relevant = mutations.some(function (m) {
                return m.type === 'attributes' && m.attributeName === 'class';
            });
            if (relevant) {
                dispatchSelectionChange();
            }
        });

        observer.observe(grid, {
            subtree: true,
            attributes: true,
            attributeFilter: ['class'],
        });
    }

    // -------------------------------------------------------------------------
    // 3. Position the folder sidebar inside the WP media frame
    // -------------------------------------------------------------------------

    function positionSidebarPortal() {
        var sidebarPortal = document.getElementById('mediapilot-sidebar-portal');
        if (!sidebarPortal) return;
        if (sidebarPortal.getAttribute('data-mediapilot-placed') === '1') return;

        // In grid mode, .media-frame-content is the flex parent.
        var frame = document.querySelector('.media-frame-content');
        if (!frame) return;

        // Bail if no WP content has rendered yet.
        if (!document.querySelector('.wp-filter') && !document.querySelector('.attachments-browser')) return;

        // --- Turn the frame into a horizontal flex container ---
        frame.style.cssText += ';display:flex!important;flex-direction:row;overflow:hidden;';

        // --- Insert sidebar as first child ---
        frame.insertBefore(sidebarPortal, frame.firstChild);
        sidebarPortal.style.cssText = 'flex-shrink:0;height:100%;overflow:hidden;position:relative;';

        // --- Wrap WP's filter + grid in a flex-1 column container ---
        if (!document.getElementById('mediapilot-media-content-inner')) {
            var inner = document.createElement('div');
            inner.id = 'mediapilot-media-content-inner';
            inner.style.cssText = 'flex:1;display:flex;flex-direction:column;overflow:hidden;min-width:0;';

            // Move every child except the sidebar portal into the inner wrapper.
            var children = Array.prototype.slice.call(frame.children);
            children.forEach(function (child) {
                if (child !== sidebarPortal) {
                    inner.appendChild(child);
                }
            });
            frame.appendChild(inner);
        }

        sidebarPortal.setAttribute('data-mediapilot-placed', '1');
    }

    // -------------------------------------------------------------------------
    // 4. Reposition BreadcrumbBar + MediaToolbar portal roots
    // -------------------------------------------------------------------------

    function positionPortalRoots() {
        var crumbRoot   = document.getElementById('mediapilot-breadcrumb-root');
        var toolbarRoot = document.getElementById('mediapilot-toolbar-root');

        // Breadcrumb bar — place directly before the attachments grid
        if (crumbRoot && crumbRoot.style.display === 'none') {
            var browser = document.querySelector('.attachments-browser');
            if (browser) {
                var grid = browser.querySelector('.attachments') || browser.firstChild;
                if (grid && grid.parentNode) {
                    grid.parentNode.insertBefore(crumbRoot, grid);
                } else {
                    browser.insertBefore(crumbRoot, browser.firstChild);
                }
                crumbRoot.style.display = '';
            }
        }

        // Toolbar — insert right after the WP filter bar
        if (toolbarRoot && toolbarRoot.style.display === 'none') {
            var wpFilter = document.querySelector('.wp-filter');
            if (wpFilter && wpFilter.parentNode) {
                wpFilter.parentNode.insertBefore(toolbarRoot, wpFilter.nextSibling);
                toolbarRoot.style.display = '';
            }
        }
    }

    // -------------------------------------------------------------------------
    // 4. Boot — keep trying until the WP media-library backbone renders the grid
    // -------------------------------------------------------------------------

    function tryBoot() {
        attachObserver();
        positionSidebarPortal();
        positionPortalRoots();
    }

    function fullyPlaced() {
        var sidebarPlaced = document.querySelector('[data-mediapilot-placed="1"]') !== null;
        var crumbRoot     = document.getElementById('mediapilot-breadcrumb-root');
        var toolbarRoot   = document.getElementById('mediapilot-toolbar-root');
        var crumbPlaced   = crumbRoot   && crumbRoot.style.display   !== 'none';
        var toolbarPlaced = toolbarRoot && toolbarRoot.style.display !== 'none';
        return !!observer && sidebarPlaced && crumbPlaced && toolbarPlaced;
    }

    // Run once immediately / on DOM ready.
    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', tryBoot);
    } else {
        tryBoot();
    }

    // Watch for the media frame appearing or being re-rendered.
    var throttleTimer = null;
    var bootObserver = new MutationObserver(function () {
        if (throttleTimer) { return; }
        throttleTimer = setTimeout(function () {
            throttleTimer = null;
            tryBoot();
        }, 50);
    });
    bootObserver.observe(document.body, { childList: true, subtree: true });

    // Belt-and-suspenders poll.
    var pollCount = 0;
    var pollTimer = setInterval(function () {
        pollCount++;
        if (!fullyPlaced()) {
            tryBoot();
        }
        if (fullyPlaced() || pollCount > 120) {
            clearInterval(pollTimer);
            setTimeout(function () { bootObserver.disconnect(); }, 60000);
        }
    }, 250);

    // WP fires 'ready' on the media frame when its views are attached.
    document.addEventListener('DOMContentLoaded', function () {
        if (typeof wp !== 'undefined' && wp.media && wp.media.frame) {
            wp.media.frame.on('ready', function () {
                setTimeout(tryBoot, 100);
            });
        }
    });

    // -------------------------------------------------------------------------
    // 5. React → WP backbone folder filter bridge
    // -------------------------------------------------------------------------

    window.addEventListener('mediapilot:folder-selected', function (e) {
        if (typeof wp === 'undefined' || !wp.media || !wp.media.frame) return;

        var detail     = e.detail || {};
        var folderId   = detail.folderId  != null ? detail.folderId  : null;
        var unusedOnly = detail.unusedOnly ? '1' : null;

        try {
            var state   = wp.media.frame.state();
            var library = state ? state.get('library') : null;
            if (!library) return;

            // Reset both filters first.
            library.props.unset('mdpai_folder_id');
            library.props.unset('mdpai_unused');

            if (unusedOnly) {
                library.props.set({ mdpai_unused: '1' });
            } else if (folderId !== null) {
                library.props.set({ mdpai_folder_id: String(folderId) });
            }

            // Force backbone to re-fetch attachments with the updated props.
            library.props.trigger('change');
        } catch (_) {
            // Media frame not in the expected state — ignore.
        }
    });

    // -------------------------------------------------------------------------
    // 6. React → WP backbone sort bridge (no page reload)
    // -------------------------------------------------------------------------

    window.addEventListener('mediapilot:sort-change', function (e) {
        if (typeof wp === 'undefined' || !wp.media || !wp.media.frame) return;

        var detail  = e.detail || {};
        var orderby = detail.orderby || 'date';
        var order   = detail.order   || 'DESC';

        try {
            var state   = wp.media.frame.state();
            var library = state ? state.get('library') : null;
            if (!library || !library.props) return;

            library.props.set({ orderby: orderby, order: order });
            library.props.trigger('change');
        } catch (_) {
            // Media frame not in the expected state — ignore.
        }
    });

    // -------------------------------------------------------------------------
    // 7. Unused-media badge — patch wp.media.view.Attachment to show a warning
    //    icon when the attachment is not referenced anywhere.
    // -------------------------------------------------------------------------

    (function patchAttachmentView() {
        if (typeof wp === 'undefined' || !wp.media || !wp.media.view) {
            document.addEventListener('DOMContentLoaded', function () {
                setTimeout(patchAttachmentView, 500);
            });
            return;
        }

        var AttachmentView = wp.media.view.Attachment;
        if (!AttachmentView) return;

        var originalRender = AttachmentView.prototype.render;

        AttachmentView.prototype.render = function () {
            originalRender.apply(this, arguments);

            var el = this.el;
            if (!el) return this;

            var existing = el.querySelector('.mediapilot-usage-warning-badge');
            if (existing) existing.remove();

            var usageCount = this.model ? this.model.get('mdpai_usage_count') : undefined;

            if (typeof usageCount !== 'undefined' && Number(usageCount) === 0) {
                var badge = document.createElement('span');
                badge.className = 'mediapilot-usage-warning-badge';
                badge.title     = i18n.unusedTitle || '';
                badge.textContent = '⚠';
                el.appendChild(badge);
                el.classList.add('mediapilot-has-published-usage');
            } else {
                el.classList.remove('mediapilot-has-published-usage');
            }

            return this;
        };
    }());

    // -------------------------------------------------------------------------
    // 8. Badge styles
    // -------------------------------------------------------------------------

    (function injectBadgeStyles() {
        if (document.getElementById('mediapilot-usage-badge-styles')) return;
        var style = document.createElement('style');
        style.id  = 'mediapilot-usage-badge-styles';
        style.textContent = [
            '.mediapilot-usage-warning-badge {',
            '  position: absolute;',
            '  top: 4px;',
            '  right: 4px;',
            '  z-index: 10;',
            '  background: #d63638;',
            '  color: #fff;',
            '  font-size: 11px;',
            '  line-height: 1;',
            '  padding: 2px 4px;',
            '  border-radius: 3px;',
            '  pointer-events: none;',
            '}',
            '.attachment.mediapilot-has-published-usage .thumbnail {',
            '  outline: 2px solid #d63638;',
            '  outline-offset: -2px;',
            '}',
        ].join('\n');
        document.head.appendChild(style);
    }());
}());
