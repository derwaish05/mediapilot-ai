<?php

declare(strict_types=1);

namespace MediaPilotAI\Settings;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}


use MediaPilotAI\Frontend\ShareLinkRepository;
use MediaPilotAI\Frontend\ClientPortal;
use MediaPilotAI\Folder\FolderRepository;

/**
 * Admin settings page — Portal tab (S59).
 *
 * Registered under Media › Portal.
 *
 * Shows a table of all active share links with:
 *   - Folder name
 *   - Portal URL (copy-to-clipboard)
 *   - Password-protected badge
 *   - Expiry date / "Never"
 *   - Download count
 *   - Revoke button (POST to REST API via JS)
 *
 * Also allows creating a new share link from the admin directly.
 *
 * @package MediaPilotAI\Settings
 * @since   1.0.0
 */
class PortalSettingsPage {

    private const PAGE_SLUG  = 'mediapilot-portal';
    private const CAPABILITY = 'manage_mdpai_settings';

    public function __construct(
        private readonly ShareLinkRepository $linkRepo,
        private readonly FolderRepository    $folderRepo,
    ) {}

    // -------------------------------------------------------------------------
    // Boot
    // -------------------------------------------------------------------------

    public function register(): void {
        add_action( 'admin_menu', [ $this, 'addMenuPage' ] );
        add_action( 'admin_notices', [ $this, 'maybeShowNotice' ] );
    }

    // -------------------------------------------------------------------------
    // Menu
    // -------------------------------------------------------------------------

    public function addMenuPage(): void {
        add_submenu_page(
            'upload.php',
            __( 'MediaPilot Portal', 'mediapilot-ai'),
            __( 'Portal', 'mediapilot-ai'),
            self::CAPABILITY,
            self::PAGE_SLUG,
            [ $this, 'renderPage' ]
        );
    }

    // -------------------------------------------------------------------------
    // Admin notice
    // -------------------------------------------------------------------------

    public function maybeShowNotice(): void {
        $screen = get_current_screen();

        if ( ! $screen || $screen->id !== 'media_page_' . self::PAGE_SLUG ) {
            return;
        }

        // Handled by renderPage directly.
    }

    // -------------------------------------------------------------------------
    // Page render
    // -------------------------------------------------------------------------

    public function renderPage(): void {
        if ( ! current_user_can( self::CAPABILITY ) ) {
            wp_die( esc_html__( 'Insufficient permissions.', 'mediapilot-ai') );
        }

        $links   = $this->linkRepo->getAll();
        $folders = $this->flatFolders( $this->folderRepo->getTree( 0 ) );
        $apiRoot = esc_js( rest_url( 'mediapilot/v1' ) );
        $nonce   = wp_create_nonce( 'wp_rest' );

        ?>
        <div class="wrap" id="mediapilot-portal-page">
            <h1><?php esc_html_e( 'Client Sharing Portal', 'mediapilot-ai'); ?></h1>
            <p class="description" style="margin-bottom:16px">
                <?php esc_html_e( 'Create shareable links to folders. Clients can browse, preview, and download files without a WordPress login.', 'mediapilot-ai'); ?>
            </p>

            <!-- Create share link form -->
            <div style="background:#fff;border:1px solid #e0e0e0;border-radius:6px;padding:20px 24px;margin-bottom:24px;max-width:640px">
                <h2 style="font-size:15px;margin-bottom:16px"><?php esc_html_e( 'Create Share Link', 'mediapilot-ai'); ?></h2>
                <table class="form-table" style="margin:0">
                    <tr>
                        <th style="padding:6px 0;width:130px"><?php esc_html_e( 'Folder', 'mediapilot-ai'); ?></th>
                        <td>
                            <select id="mediapilot-share-folder" style="width:280px">
                                <option value=""><?php esc_html_e( '— select folder —', 'mediapilot-ai'); ?></option>
                                <?php foreach ( $folders as $f ) : ?>
                                    <option value="<?php echo esc_attr( (string) $f['id'] ); ?>">
                                        <?php echo esc_html( $f['label'] ); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </td>
                    </tr>
                    <tr>
                        <th style="padding:6px 0"><?php esc_html_e( 'Password', 'mediapilot-ai'); ?></th>
                        <td>
                            <input type="password" id="mediapilot-share-password" placeholder="<?php esc_attr_e( 'Leave blank for no password', 'mediapilot-ai'); ?>" style="width:280px">
                        </td>
                    </tr>
                    <tr>
                        <th style="padding:6px 0"><?php esc_html_e( 'Expires', 'mediapilot-ai'); ?></th>
                        <td>
                            <input type="date" id="mediapilot-share-expires" style="width:200px">
                            <span class="description"><?php esc_html_e( 'Leave blank for no expiry', 'mediapilot-ai'); ?></span>
                        </td>
                    </tr>
                    <tr>
                        <th style="padding:6px 0"><?php esc_html_e( 'Header Colour', 'mediapilot-ai'); ?></th>
                        <td>
                            <input type="color" id="mediapilot-share-color" value="#2563eb">
                        </td>
                    </tr>
                    <tr>
                        <th style="padding:6px 0"><?php esc_html_e( 'Logo URL', 'mediapilot-ai'); ?></th>
                        <td>
                            <input type="url" id="mediapilot-share-logo" placeholder="https://…" style="width:280px">
                        </td>
                    </tr>
                </table>
                <p id="mediapilot-share-error" style="color:#ef4444;margin-top:8px;display:none"></p>
                <p style="margin-top:16px">
                    <button type="button" id="mediapilot-share-create" class="button button-primary">
                        <?php esc_html_e( 'Generate Share Link', 'mediapilot-ai'); ?>
                    </button>
                </p>
            </div>

            <!-- Active links table -->
            <h2 style="font-size:15px;margin-bottom:12px"><?php esc_html_e( 'Active Share Links', 'mediapilot-ai'); ?></h2>

            <?php if ( empty( $links ) ) : ?>
                <p class="description"><?php esc_html_e( 'No share links yet.', 'mediapilot-ai'); ?></p>
            <?php else : ?>
                <table class="wp-list-table widefat fixed striped" id="mediapilot-links-table">
                    <thead>
                        <tr>
                            <th><?php esc_html_e( 'Folder', 'mediapilot-ai'); ?></th>
                            <th><?php esc_html_e( 'Portal URL', 'mediapilot-ai'); ?></th>
                            <th><?php esc_html_e( 'Password', 'mediapilot-ai'); ?></th>
                            <th><?php esc_html_e( 'Expires', 'mediapilot-ai'); ?></th>
                            <th><?php esc_html_e( 'Downloads', 'mediapilot-ai'); ?></th>
                            <th><?php esc_html_e( 'Created', 'mediapilot-ai'); ?></th>
                            <th><?php esc_html_e( 'Actions', 'mediapilot-ai'); ?></th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ( $links as $link ) :
                            $folderId   = (int) $link['folder_id'];
                            $folder     = $this->folderRepo->getById( $folderId );
                            $folderName = $folder ? esc_html( (string) $folder['name'] ) : '<em>Deleted</em>';
                            $portalUrl  = esc_url( ClientPortal::portalUrl( (string) $link['token'] ) );
                            $isExpired  = ! $this->linkRepo->isValid( $link );
                            $dlCount    = $this->linkRepo->downloadCount( (int) $link['id'] );
                        ?>
                            <tr data-id="<?php echo esc_attr( (string) $link['id'] ); ?>" class="<?php echo $isExpired ? 'mediapilot-expired-row' : ''; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- ternary outputs a hardcoded safe CSS class string or empty string ?>">
                                <td><?php echo $folderName; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- $folderName is pre-escaped via esc_html() or a hardcoded '<em>Deleted</em>' string on line 175 ?></td>
                                <td>
                                    <input type="text" value="<?php echo $portalUrl; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- $portalUrl is pre-escaped via esc_url() on line 176 ?>" readonly
                                        style="width:100%;max-width:320px;font-size:11px"
                                        onclick="this.select()"
                                        title="<?php esc_attr_e( 'Click to select', 'mediapilot-ai'); ?>">
                                </td>
                                <td>
                                    <?php echo ! empty( $link['password_hash'] )
                                        ? '<span style="color:#f59e0b">🔒 ' . esc_html__( 'Yes', 'mediapilot-ai') . '</span>'
                                        : '—'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- both branches are hardcoded safe HTML with esc_html__() translation ?>
                                </td>
                                <td>
                                    <?php if ( ! empty( $link['expires_at'] ) ) {
                                        $label = date_i18n( get_option( 'date_format' ), strtotime( (string) $link['expires_at'] ) );
                                        echo esc_html( $label );
                                        if ( $isExpired ) {
                                            echo ' <span style="color:#ef4444">(' . esc_html__( 'expired', 'mediapilot-ai') . ')</span>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- hardcoded safe HTML wrapper with esc_html__() translated string
                                        }
                                    } else {
                                        echo '—'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- hardcoded safe literal
                                    } ?>
                                </td>
                                <td><?php echo esc_html( (string) $dlCount ); ?></td>
                                <td><?php echo esc_html( (string) $link['created_at'] ); ?></td>
                                <td>
                                    <button type="button"
                                        class="button button-small mediapilot-revoke-btn"
                                        data-id="<?php echo esc_attr( (string) $link['id'] ); ?>"
                                        data-confirm="<?php esc_attr_e( 'Revoke this share link? Clients will immediately lose access.', 'mediapilot-ai'); ?>">
                                        <?php esc_html_e( 'Revoke', 'mediapilot-ai'); ?>
                                    </button>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            <?php endif; ?>
        </div>

        <?php
        wp_register_style( 'mediapilot-portal-settings', false );
        wp_enqueue_style( 'mediapilot-portal-settings' );
        wp_add_inline_style( 'mediapilot-portal-settings', '.mediapilot-expired-row { opacity: .6; }' );

        wp_register_script( 'mediapilot-portal-settings-js', false, [], MDPAI_VERSION, true );
        wp_enqueue_script( 'mediapilot-portal-settings-js' );
        ob_start();
        ?>
        (function () {
            const API_ROOT = '<?php echo $apiRoot; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- $apiRoot is pre-escaped via esc_js( rest_url() ) on line 92 ?>';
            const NONCE    = '<?php echo esc_js( $nonce ); ?>';

            function apiRequest(method, path, body) {
                return fetch(API_ROOT + path, {
                    method,
                    headers: {
                        'Content-Type': 'application/json',
                        'X-WP-Nonce': NONCE,
                    },
                    body: body ? JSON.stringify(body) : undefined,
                }).then(r => r.json());
            }

            // Create share link.
            document.getElementById('mediapilot-share-create').addEventListener('click', async function () {
                const folderId = document.getElementById('mediapilot-share-folder').value;
                if (!folderId) { alert('<?php esc_js( esc_html__( 'Please select a folder.', 'mediapilot-ai') ); ?>'); return; }

                const btn = this;
                btn.disabled = true;
                btn.textContent = '<?php esc_js( esc_html__( 'Creating…', 'mediapilot-ai') ); ?>';

                const errEl = document.getElementById('mediapilot-share-error');
                errEl.style.display = 'none';

                const body = {
                    password:     document.getElementById('mediapilot-share-password').value,
                    expires_at:   document.getElementById('mediapilot-share-expires').value || null,
                    header_color: document.getElementById('mediapilot-share-color').value,
                    logo_url:     document.getElementById('mediapilot-share-logo').value,
                };

                try {
                    const res = await apiRequest('POST', '/folders/' + folderId + '/share', body);
                    if (res.success) {
                        window.location.reload();
                    } else {
                        errEl.textContent = res.message || '<?php esc_js( esc_html__( 'Error creating link.', 'mediapilot-ai') ); ?>';
                        errEl.style.display = 'block';
                    }
                } catch (e) {
                    errEl.textContent = e.message;
                    errEl.style.display = 'block';
                } finally {
                    btn.disabled = false;
                    btn.textContent = '<?php esc_js( esc_html__( 'Generate Share Link', 'mediapilot-ai') ); ?>';
                }
            });

            // Revoke links.
            document.querySelectorAll('.mediapilot-revoke-btn').forEach(function (btn) {
                btn.addEventListener('click', async function () {
                    if (!confirm(this.dataset.confirm)) return;
                    const id = this.dataset.id;
                    try {
                        const res = await apiRequest('DELETE', '/shares/' + id);
                        if (res.success) {
                            const row = document.querySelector('tr[data-id="' + id + '"]');
                            if (row) row.remove();
                        }
                    } catch (e) {
                        alert(e.message);
                    }
                });
            });
        })();
        <?php
        wp_add_inline_script( 'mediapilot-portal-settings-js', (string) ob_get_clean() );
    }

    // -------------------------------------------------------------------------
    // Helpers
    // -------------------------------------------------------------------------

    /**
     * @param  array<int, array<string, mixed>> $tree
     * @return array<int, array{id: int, label: string}>
     */
    private function flatFolders( array $tree, int $depth = 0 ): array {
        $result = [];
        foreach ( $tree as $node ) {
            $result[] = [
                'id'    => (int) $node['id'],
                'label' => str_repeat( '— ', $depth ) . (string) $node['name'],
            ];
            if ( ! empty( $node['children'] ) ) {
                $result = array_merge( $result, $this->flatFolders( (array) $node['children'], $depth + 1 ) );
            }
        }
        return $result;
    }
}
