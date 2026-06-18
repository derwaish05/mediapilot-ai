<?php
/**
 * Media Analytics Dashboard — admin page view.
 *
 * Data is loaded via REST API calls from the enqueued dashboard script
 * (handle "mediapilot-analytics-dashboard"); see AnalyticsDashboard::enqueueAssets().
 *
 * @package MediaPilotAI\Analytics
 * @since   1.0.0
 */

declare(strict_types=1);

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

if ( ! current_user_can( 'manage_options' ) ) {
    wp_die( esc_html__( 'Insufficient permissions.', 'mediapilot-ai') );
}
?>
<div class="wrap mediapilot-analytics-wrap">

    <h1><?php esc_html_e( 'Media Analytics', 'mediapilot-ai'); ?></h1>

    <!-- ── Date range filter ──────────────────────────────────────────── -->
    <div class="mediapilot-analytics-toolbar" style="display:flex;align-items:center;gap:12px;margin:16px 0 24px;">
        <label for="mediapilot-range-select" style="font-weight:600"><?php esc_html_e( 'Date range:', 'mediapilot-ai'); ?></label>
        <select id="mediapilot-range-select" style="height:32px;padding:0 8px;border-radius:4px;border:1px solid #8c8f94;">
            <option value="7d"><?php esc_html_e( 'Last 7 days', 'mediapilot-ai'); ?></option>
            <option value="30d" selected><?php esc_html_e( 'Last 30 days', 'mediapilot-ai'); ?></option>
            <option value="90d"><?php esc_html_e( 'Last 90 days', 'mediapilot-ai'); ?></option>
            <option value="all"><?php esc_html_e( 'All time', 'mediapilot-ai'); ?></option>
        </select>
        <button id="mediapilot-rebuild-usage-btn" class="button" style="margin-left:auto"
                title="<?php esc_attr_e( 'Scan all posts and pages to rebuild the media usage index. Run this once after install so the “Unused Media” list is accurate.', 'mediapilot-ai'); ?>">
            ↻ <?php esc_html_e( 'Rebuild Usage Index', 'mediapilot-ai'); ?>
        </button>
        <button id="mediapilot-export-btn" class="button">
            ⬇ <?php esc_html_e( 'Export CSV', 'mediapilot-ai'); ?>
        </button>
        <span id="mediapilot-usage-scan-status" style="display:none;color:#646970;font-size:12px"></span>
        <span id="mediapilot-analytics-loading" style="display:none;color:#8c8f94">
            <?php esc_html_e( 'Loading…', 'mediapilot-ai'); ?>
        </span>
    </div>

    <!-- ── Summary cards ─────────────────────────────────────────────── -->
    <div id="mediapilot-summary-cards" style="display:grid;grid-template-columns:repeat(auto-fill,minmax(160px,1fr));gap:16px;margin-bottom:28px;">
        <?php foreach ( [
            'total_files'    => __( 'Total Files', 'mediapilot-ai'),
            'total_storage'  => __( 'Total Storage', 'mediapilot-ai'),
            'ev_inserts'     => __( 'Inserts', 'mediapilot-ai'),
            'ev_downloads'   => __( 'Downloads', 'mediapilot-ai'),
        ] as $mdpai_key => $mdpai_label ) : ?>
            <div class="mediapilot-stat-card" data-key="<?php echo esc_attr( $mdpai_key ); ?>"
                 style="background:#fff;border:1px solid #dcdcde;border-radius:6px;padding:16px 20px;text-align:center;">
                <div class="mediapilot-stat-value" style="font-size:28px;font-weight:700;line-height:1.1;color:#1d2327">—</div>
                <div class="mediapilot-stat-label" style="margin-top:4px;font-size:12px;color:#646970"><?php echo esc_html( $mdpai_label ); ?></div>
            </div>
        <?php endforeach; ?>
    </div>

    <!-- ── Charts row 1: Upload activity + Storage by type ───────────── -->
    <div style="display:grid;grid-template-columns:2fr 1fr;gap:20px;margin-bottom:24px;">
        <div style="background:#fff;border:1px solid #dcdcde;border-radius:6px;padding:20px;">
            <h3 style="margin:0 0 12px"><?php esc_html_e( 'Upload Activity', 'mediapilot-ai'); ?></h3>
            <canvas id="mediapilot-chart-uploads" height="120"></canvas>
        </div>
        <div style="background:#fff;border:1px solid #dcdcde;border-radius:6px;padding:20px;">
            <h3 style="margin:0 0 12px"><?php esc_html_e( 'Storage by File Type', 'mediapilot-ai'); ?></h3>
            <canvas id="mediapilot-chart-types" height="180"></canvas>
        </div>
    </div>

    <!-- ── Charts row 2: Storage by folder ───────────────────────────── -->
    <div style="background:#fff;border:1px solid #dcdcde;border-radius:6px;padding:20px;margin-bottom:24px;">
        <h3 style="margin:0 0 12px"><?php esc_html_e( 'Storage by Folder', 'mediapilot-ai'); ?></h3>
        <canvas id="mediapilot-chart-folders" height="80"></canvas>
    </div>

</div><!-- .mediapilot-analytics-wrap -->
