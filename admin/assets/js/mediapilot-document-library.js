/**
 * MediaPilot Document Library — frontend JS (S41)
 *
 * Provides AJAX-driven subfolder navigation and pagination for the
 * [mdpai_documents] shortcode without full page reloads.
 *
 * Works with vanilla JS only — no React, no jQuery dependency.
 * Each `.mediapilot-doc-library` element on the page is initialised independently,
 * so multiple libraries on the same page work in isolation.
 */
(function () {
    'use strict';

    // -------------------------------------------------------------------------
    // Init — run for every library instance on the page
    // -------------------------------------------------------------------------

    function initLibrary(el) {
        var rootId         = parseInt(el.dataset.rootId, 10);
        var perPage        = parseInt(el.dataset.perPage, 10);
        var fileTypes      = el.dataset.fileTypes || '';
        var showSubfolders = el.dataset.showSubfolders === '1';
        var hasPagination  = el.dataset.pagination   === '1';
        var restUrl        = el.dataset.restUrl;
        var nonce          = el.dataset.nonce;
        var currentFolder  = rootId;

        // ---- Click delegation -----------------------------------------------
        el.addEventListener('click', function (e) {
            // Subfolder button (both the card and breadcrumb crumb buttons)
            var folderBtn = e.target.closest('[data-folder-id]');
            if (folderBtn) {
                e.preventDefault();
                var folderId = parseInt(folderBtn.dataset.folderId, 10);
                if (!isNaN(folderId) && folderId > 0) {
                    navigate(folderId, 1);
                }
                return;
            }

            // Pagination button
            var pageBtn = e.target.closest('[data-page]');
            if (pageBtn) {
                e.preventDefault();
                var page = parseInt(pageBtn.dataset.page, 10);
                if (!isNaN(page) && page > 0) {
                    navigate(currentFolder, page);
                }
            }
        });

        // ---- Navigation -------------------------------------------------------

        function navigate(folderId, page) {
            setLoading(true);

            var params = new URLSearchParams({
                folder_id:       folderId,
                root_id:         rootId,
                page:            page,
                per_page:        perPage,
                file_types:      fileTypes,
                show_subfolders: showSubfolders ? '1' : '0',
            });

            fetch(restUrl + '?' + params.toString(), {
                method:  'GET',
                headers: { 'X-WP-Nonce': nonce },
            })
            .then(function (r) { return r.json(); })
            .then(function (json) {
                if (json && json.success && json.data) {
                    currentFolder = folderId;
                    render(json.data, hasPagination);
                }
                setLoading(false);
            })
            .catch(function () {
                setLoading(false);
            });
        }

        // ---- Rendering --------------------------------------------------------

        function render(data, pagination) {
            // Breadcrumb
            var nav = el.querySelector('.mediapilot-doclib__breadcrumb');
            if (nav) {
                nav.innerHTML = buildBreadcrumb(data.breadcrumb || []);
            }

            // Body: subfolders + table + pagination
            var body = el.querySelector('.mediapilot-doclib__body');
            if (body) {
                var html = '';
                if (showSubfolders && data.subfolders && data.subfolders.length) {
                    html += buildSubfolders(data.subfolders);
                }
                html += buildFileTable(data.files || []);
                if (pagination) {
                    html += buildPagination(data.current_page, data.pages, data.total);
                }
                body.innerHTML = html;
            }
        }

        function setLoading(on) {
            var body = el.querySelector('.mediapilot-doclib__body');
            if (body) {
                if (on) {
                    body.classList.add('mediapilot-doclib__body--loading');
                } else {
                    body.classList.remove('mediapilot-doclib__body--loading');
                }
            }
        }

        // ---- HTML builders ---------------------------------------------------

        function esc(str) {
            return String(str)
                .replace(/&/g, '&amp;')
                .replace(/</g, '&lt;')
                .replace(/>/g, '&gt;')
                .replace(/"/g, '&quot;')
                .replace(/'/g, '&#039;');
        }

        function buildBreadcrumb(crumbs) {
            if (!crumbs.length) return '';
            var html = '<ol class="mediapilot-doclib__crumb-list">';
            crumbs.forEach(function (crumb, i) {
                if (i === crumbs.length - 1) {
                    html += '<li class="mediapilot-doclib__crumb mediapilot-doclib__crumb--current" aria-current="page">'
                        + esc(crumb.name) + '</li>';
                } else {
                    html += '<li class="mediapilot-doclib__crumb">'
                        + '<button type="button" class="mediapilot-doclib__crumb-btn" data-folder-id="' + esc(crumb.id) + '">'
                        + esc(crumb.name) + '</button></li>';
                }
            });
            html += '</ol>';
            return html;
        }

        function buildSubfolders(subfolders) {
            var html = '<div class="mediapilot-doclib__subfolders">';
            subfolders.forEach(function (sf) {
                html += '<button type="button" class="mediapilot-doclib__subfolder" data-folder-id="' + esc(sf.id) + '">'
                    + '<span class="mediapilot-doclib__subfolder-icon" aria-hidden="true">📁</span>'
                    + '<span class="mediapilot-doclib__subfolder-name">' + esc(sf.name) + '</span>'
                    + '<span class="mediapilot-doclib__subfolder-count">' + esc(sf.count) + ' files</span>'
                    + '</button>';
            });
            html += '</div>';
            return html;
        }

        function buildFileTable(files) {
            if (!files.length) {
                return '<p class="mediapilot-doclib__empty">No files in this folder.</p>';
            }

            var html = '<div class="mediapilot-doclib__table-wrap">'
                + '<table class="mediapilot-doclib__table">'
                + '<thead><tr>'
                + '<th class="mediapilot-doclib__col-icon" aria-hidden="true"></th>'
                + '<th class="mediapilot-doclib__col-name">File</th>'
                + '<th class="mediapilot-doclib__col-size">Size</th>'
                + '<th class="mediapilot-doclib__col-date">Date</th>'
                + '<th class="mediapilot-doclib__col-dl"></th>'
                + '</tr></thead><tbody>';

            files.forEach(function (file) {
                var cat  = esc(file.category || 'file');
                var icon = getIcon(file.category || 'file');
                html += '<tr class="mediapilot-doclib__row">'
                    + '<td class="mediapilot-doclib__col-icon">'
                    +     '<span class="mediapilot-doclib__icon mediapilot-doclib__icon--' + cat + '" aria-hidden="true">' + icon + '</span>'
                    + '</td>'
                    + '<td class="mediapilot-doclib__col-name" data-label="File">'
                    +     '<span class="mediapilot-doclib__filename" title="' + esc(file.filename) + '">' + esc(file.name) + '</span>'
                    + '</td>'
                    + '<td class="mediapilot-doclib__col-size" data-label="Size">' + esc(file.size_human) + '</td>'
                    + '<td class="mediapilot-doclib__col-date" data-label="Date">' + esc(file.date) + '</td>'
                    + '<td class="mediapilot-doclib__col-dl">'
                    +     '<a href="' + esc(file.url) + '" class="mediapilot-doclib__download" download '
                    +         'aria-label="Download ' + esc(file.name) + '">Download</a>'
                    + '</td>'
                    + '</tr>';
            });

            html += '</tbody></table></div>';
            return html;
        }

        function buildPagination(current, total, found) {
            if (total <= 1) return '';

            var html = '<nav class="mediapilot-doclib__pagination" aria-label="Page navigation">'
                + '<ul class="mediapilot-doclib__pages">';

            if (current > 1) {
                html += '<li><button type="button" class="mediapilot-doclib__page-btn" data-page="' + (current - 1)
                    + '" aria-label="Previous page">&#8592;</button></li>';
            }

            for (var p = 1; p <= total; p++) {
                if (p === current) {
                    html += '<li><span class="mediapilot-doclib__page-btn mediapilot-doclib__page-btn--current" aria-current="page">'
                        + p + '</span></li>';
                } else if (p === 1 || p === total || Math.abs(p - current) <= 2) {
                    html += '<li><button type="button" class="mediapilot-doclib__page-btn" data-page="' + p + '">'
                        + p + '</button></li>';
                } else if (Math.abs(p - current) === 3) {
                    html += '<li><span class="mediapilot-doclib__page-ellipsis">&hellip;</span></li>';
                }
            }

            if (current < total) {
                html += '<li><button type="button" class="mediapilot-doclib__page-btn" data-page="' + (current + 1)
                    + '" aria-label="Next page">&#8594;</button></li>';
            }

            html += '</ul>'
                + '<p class="mediapilot-doclib__total">' + found + ' files total</p>'
                + '</nav>';
            return html;
        }

        // ---- Inline SVG icons (matches PHP fileIcon() output) ----------------

        function getIcon(category) {
            var icons = {
                image:   '<svg viewBox="0 0 20 20" fill="currentColor" aria-hidden="true"><path d="M2 3a1 1 0 011-1h14a1 1 0 011 1v14a1 1 0 01-1 1H3a1 1 0 01-1-1V3zm2 1v8.586l3-3 3 3 2-2 3 3V4H4zm0 10.414V17h12v-1.586l-3-3-2 2-3-3-4 4z"/></svg>',
                pdf:     '<svg viewBox="0 0 20 20" fill="currentColor" aria-hidden="true"><path d="M4 2a2 2 0 00-2 2v12a2 2 0 002 2h12a2 2 0 002-2V7.414A2 2 0 0017.414 6L13 1.586A2 2 0 0011.586 1H4zm0 2h7v4a1 1 0 001 1h4v9H4V4zm7-1.586L14.586 6H11V2.414zM6 11a1 1 0 000 2h8a1 1 0 000-2H6zm0 3a1 1 0 000 2h5a1 1 0 000-2H6z"/></svg>',
                doc:     '<svg viewBox="0 0 20 20" fill="currentColor" aria-hidden="true"><path d="M4 2a2 2 0 00-2 2v12a2 2 0 002 2h12a2 2 0 002-2V7.414A2 2 0 0017.414 6L13 1.586A2 2 0 0011.586 1H4zm0 2h7v4a1 1 0 001 1h4v9H4V4zm2 7a1 1 0 000 2h8a1 1 0 000-2H6zm0 3a1 1 0 000 2h5a1 1 0 000-2H6z"/></svg>',
                sheet:   '<svg viewBox="0 0 20 20" fill="currentColor" aria-hidden="true"><path d="M4 2a2 2 0 00-2 2v12a2 2 0 002 2h12a2 2 0 002-2V4a2 2 0 00-2-2H4zm1 3h10v1H5V5zm0 3h4v1H5V8zm6 0h4v1h-4V8zm-6 3h4v1H5v-1zm6 0h4v1h-4v-1zm-6 3h4v1H5v-1zm6 0h4v1h-4v-1z"/></svg>',
                ppt:     '<svg viewBox="0 0 20 20" fill="currentColor" aria-hidden="true"><path d="M4 2a2 2 0 00-2 2v12a2 2 0 002 2h12a2 2 0 002-2V4a2 2 0 00-2-2H4zm2 4h8a1 1 0 010 2H6a1 1 0 010-2zm0 4h5a1 1 0 010 2H6a1 1 0 010-2z"/></svg>',
                archive: '<svg viewBox="0 0 20 20" fill="currentColor" aria-hidden="true"><path d="M5 3a1 1 0 000 2c.55 0 1 .45 1 1v1H6a1 1 0 000 2h1v1c0 .55-.45 1-1 1a1 1 0 000 2h8a1 1 0 000-2c-.55 0-1-.45-1-1v-1h1a1 1 0 000-2h-1V6c0-.55.45-1 1-1a1 1 0 000-2H5zm4 9a1 1 0 110-2 1 1 0 010 2z"/></svg>',
                audio:   '<svg viewBox="0 0 20 20" fill="currentColor" aria-hidden="true"><path d="M18 3a1 1 0 00-1.196-.98l-10 2A1 1 0 006 5v9.114A4.369 4.369 0 005 14c-1.657 0-3 .895-3 2s1.343 2 3 2 3-.895 3-2V7.82l8-1.6v5.894A4.37 4.37 0 0015 12c-1.657 0-3 .895-3 2s1.343 2 3 2 3-.895 3-2V3z"/></svg>',
                video:   '<svg viewBox="0 0 20 20" fill="currentColor" aria-hidden="true"><path d="M2 6a2 2 0 012-2h6a2 2 0 012 2v8a2 2 0 01-2 2H4a2 2 0 01-2-2V6zm12.553 1.106A1 1 0 0014 8v4a1 1 0 00.553.894l2 1A1 1 0 0018 13V7a1 1 0 00-1.447-.894l-2 1z"/></svg>',
                text:    '<svg viewBox="0 0 20 20" fill="currentColor" aria-hidden="true"><path d="M4 4a2 2 0 012-2h4.586A2 2 0 0112 2.586L15.414 6A2 2 0 0116 7.414V16a2 2 0 01-2 2H6a2 2 0 01-2-2V4zm6 0v4h4l-4-4zM6 9a1 1 0 000 2h8a1 1 0 000-2H6zm0 3a1 1 0 000 2h5a1 1 0 000-2H6z"/></svg>',
                file:    '<svg viewBox="0 0 20 20" fill="currentColor" aria-hidden="true"><path d="M4 4a2 2 0 012-2h4.586A2 2 0 0112 2.586L15.414 6A2 2 0 0116 7.414V16a2 2 0 01-2 2H6a2 2 0 01-2-2V4zm6 0v4h4l-4-4z"/></svg>',
            };
            return icons[category] || icons.file;
        }
    }

    // -------------------------------------------------------------------------
    // Boot — initialise every library on the page after DOM is ready
    // -------------------------------------------------------------------------

    function boot() {
        document.querySelectorAll('.mediapilot-doc-library').forEach(initLibrary);
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', boot);
    } else {
        boot();
    }
})();
