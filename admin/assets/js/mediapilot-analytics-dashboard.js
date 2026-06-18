/* MediaPilot AI — Analytics dashboard (charts, export, usage rebuild, backfill).
   Requires Chart.js (enqueued as a dependency).
   Localised data: window.MediaPilotAnalytics
     { restBase, exportUrl, backfillUrl, usageScanUrl, nonce, i18n }. */
(function () {
    'use strict';

    var cfg            = window.MediaPilotAnalytics || {};
    var REST_BASE      = cfg.restBase || '';
    var EXPORT_URL     = cfg.exportUrl || '';
    var BACKFILL_URL   = cfg.backfillUrl || '';
    var USAGE_SCAN_URL = cfg.usageScanUrl || '';
    var NONCE          = cfg.nonce || '';
    var i18n           = cfg.i18n || {};

    // Chart instances (kept to allow destroy on refresh).
    var chartUploads = null;
    var chartTypes   = null;
    var chartFolders = null;

    function formatBytes(bytes) {
        if (!bytes || bytes <= 0) return '0 B';
        var units = ['B', 'KB', 'MB', 'GB', 'TB'];
        var i = Math.floor(Math.log(bytes) / Math.log(1024));
        if (i < 0) i = 0;
        if (i >= units.length) i = units.length - 1;
        return (bytes / Math.pow(1024, i)).toFixed(i === 0 ? 0 : 1) + ' ' + units[i];
    }

    function formatNumber(n) {
        return n.toLocaleString();
    }

    function setCard(key, value) {
        var card = document.querySelector('[data-key="' + key + '"] .mediapilot-stat-value');
        if (card) card.textContent = value;
    }

    function loadDashboard() {
        var range = document.getElementById('mediapilot-range-select').value;
        var spinner = document.getElementById('mediapilot-analytics-loading');
        spinner.style.display = 'inline';

        fetch(REST_BASE + '/stats?range=' + encodeURIComponent(range), {
            headers: { 'X-WP-Nonce': NONCE },
        })
        .then(function (r) { return r.json(); })
        .then(function (json) {
            spinner.style.display = 'none';
            if (!json.success || !json.data) return;
            var d = json.data;
            renderSummary(d.summary);
            renderUploadsChart(d.upload_activity);
            renderTypesChart(d.storage_type);
            renderFoldersChart(d.storage_folder);
        })
        .catch(function () {
            spinner.style.display = 'none';
        });
    }

    function renderSummary(s) {
        setCard('total_files',   formatNumber(s.total_files   || 0));
        setCard('total_storage', formatBytes(s.total_bytes    || 0));
        setCard('ev_inserts',    formatNumber((s.events && s.events.insert)   || 0));
        setCard('ev_downloads',  formatNumber((s.events && s.events.download) || 0));
    }

    function renderUploadsChart(rows) {
        var ctx = document.getElementById('mediapilot-chart-uploads').getContext('2d');
        if (chartUploads) chartUploads.destroy();

        var labels = (rows || []).map(function (r) { return r.date; });
        var data   = (rows || []).map(function (r) { return r.count; });

        chartUploads = new Chart(ctx, {
            type: 'line',
            data: {
                labels: labels,
                datasets: [{
                    label: i18n.uploads || 'Uploads',
                    data:            data,
                    borderColor:     '#2271b1',
                    backgroundColor: 'rgba(34,113,177,0.08)',
                    fill:            true,
                    tension:         0.35,
                    pointRadius:     3,
                }],
            },
            options: {
                responsive: true,
                plugins: { legend: { display: false } },
                scales: {
                    x: { grid: { display: false }, ticks: { maxTicksLimit: 10 } },
                    y: { beginAtZero: true, ticks: { stepSize: 1 } },
                },
            },
        });
    }

    var TYPE_COLORS = {
        image:       '#2271b1',
        video:       '#00a32a',
        audio:       '#dba617',
        application: '#8c5fd3',
        text:        '#d63638',
    };

    function renderTypesChart(rows) {
        var ctx = document.getElementById('mediapilot-chart-types').getContext('2d');
        if (chartTypes) chartTypes.destroy();

        var labels = (rows || []).map(function (r) {
            return r.type.charAt(0).toUpperCase() + r.type.slice(1) + ' (' + formatBytes(r.bytes) + ')';
        });
        var data   = (rows || []).map(function (r) { return r.bytes; });
        var colors = (rows || []).map(function (r) { return TYPE_COLORS[r.type] || '#8c8f94'; });

        chartTypes = new Chart(ctx, {
            type: 'doughnut',
            data: {
                labels: labels,
                datasets: [{ data: data, backgroundColor: colors, borderWidth: 2 }],
            },
            options: {
                responsive: true,
                plugins: {
                    legend: { position: 'bottom', labels: { boxWidth: 12, font: { size: 11 } } },
                    tooltip: {
                        callbacks: {
                            label: function (ctx) {
                                return ' ' + formatBytes(ctx.raw);
                            },
                        },
                    },
                },
            },
        });
    }

    function renderFoldersChart(rows) {
        var ctx = document.getElementById('mediapilot-chart-folders').getContext('2d');
        if (chartFolders) chartFolders.destroy();

        var labels = (rows || []).map(function (r) { return r.folder_name; });
        var data   = (rows || []).map(function (r) { return r.bytes; });

        chartFolders = new Chart(ctx, {
            type: 'bar',
            data: {
                labels: labels,
                datasets: [{
                    label: i18n.storage || 'Storage',
                    data:            data,
                    backgroundColor: '#2271b1',
                    borderRadius:    3,
                }],
            },
            options: {
                indexAxis: 'y',
                responsive: true,
                plugins: {
                    legend: { display: false },
                    tooltip: {
                        callbacks: {
                            label: function (ctx) { return ' ' + formatBytes(ctx.raw); },
                        },
                    },
                },
                scales: {
                    x: {
                        beginAtZero: true,
                        ticks: { callback: function (v) { return formatBytes(v); } },
                    },
                },
            },
        });
    }

    // Export CSV.
    document.getElementById('mediapilot-export-btn').addEventListener('click', function () {
        var range = document.getElementById('mediapilot-range-select').value;
        var url   = EXPORT_URL + '?range=' + encodeURIComponent(range) + '&_wpnonce=' + encodeURIComponent(NONCE);
        window.location.href = url;
    });

    // Rebuild usage index (chunked).
    (function () {
        var btn    = document.getElementById('mediapilot-rebuild-usage-btn');
        var status = document.getElementById('mediapilot-usage-scan-status');
        if (!btn) return;

        function runBatch(offset, totalRefs) {
            fetch(USAGE_SCAN_URL, {
                method:  'POST',
                headers: { 'Content-Type': 'application/json', 'X-WP-Nonce': NONCE },
                body:    JSON.stringify({ offset: offset }),
            })
            .then(function (r) { return r.json(); })
            .then(function (json) {
                if (!json || !json.success || !json.data) {
                    throw new Error('bad response');
                }
                var d    = json.data;
                var refs = totalRefs + (d.references || 0);

                if (!d.done) {
                    status.textContent = 'Scanning ' + d.processed + ' / ' + (d.total || '?') + '…';
                    runBatch(d.processed, refs);
                } else {
                    status.textContent = '✓ Done — ' + d.processed + ' posts scanned, ' + refs + ' references indexed.';
                    btn.disabled = false;
                }
            })
            .catch(function () {
                status.textContent = i18n.scanFailed || '';
                btn.disabled = false;
            });
        }

        btn.addEventListener('click', function () {
            btn.disabled = true;
            status.style.display = 'inline';
            status.textContent = i18n.starting || '';
            runBatch(0, 0);
        });
    }());

    // Range selector.
    document.getElementById('mediapilot-range-select').addEventListener('change', loadDashboard);

    // Filesize backfill.
    function backfillSizes(onDone) {
        fetch(BACKFILL_URL, {
            method:  'POST',
            headers: { 'X-WP-Nonce': NONCE },
        })
        .then(function (r) { return r.json(); })
        .then(function (json) {
            if (json && json.success && json.data && json.data.remaining > 0) {
                backfillSizes(onDone); // more batches remain
            } else {
                onDone();
            }
        })
        .catch(function () { onDone(); });
    }

    function boot() {
        loadDashboard();
        backfillSizes(loadDashboard);
    }

    if (document.readyState === 'complete') {
        boot();
    } else {
        window.addEventListener('load', boot);
    }
}());
