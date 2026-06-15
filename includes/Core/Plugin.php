<?php

declare(strict_types=1);

namespace MediaPilotAI\Core;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}


/**
 * Main plugin class — singleton service container and boot loader.
 *
 * Responsibilities:
 *  - Hold registered service instances.
 *  - Load each subsystem in the correct order.
 *  - Provide a central `get()` accessor for other classes.
 */
final class Plugin {

    private static ?Plugin $instance = null;

    /** @var array<string, object> Registered service instances. */
    private array $services = [];

    private bool $booted = false;

    private function __construct() {}

    public static function getInstance(): self {
        if ( null === self::$instance ) {
            self::$instance = new self();
        }

        return self::$instance;
    }

    /**
     * Boot all subsystems. Called once on `plugins_loaded`.
     */
    public function boot(): void {
        if ( $this->booted ) {
            return;
        }

        $this->booted = true;

        $this->runUpgrader();
        $this->ensureCapabilities();
        $this->registerServices();
    }

    /**
     * Retrieve a registered service by key.
     *
     * @template T of object
     * @param  class-string<T> $key
     * @return T
     */
    public function get( string $key ): object {
        if ( ! isset( $this->services[ $key ] ) ) {
            throw new \RuntimeException( "MediaPilot service not registered: {$key}" ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped
        }

        return $this->services[ $key ]; // @phpstan-ignore-line
    }

    /**
     * Register (bind) a service instance.
     */
    public function bind( string $key, object $instance ): void {
        $this->services[ $key ] = $instance;
    }

    // -------------------------------------------------------------------------
    // Private helpers
    // -------------------------------------------------------------------------

    /**
     * Ensures the MediaPilot custom capabilities are assigned to administrator and
     * editor roles on every boot. This is a no-op if they are already set,
     * but it repairs installs where the activation hook ran before the
     * addCapabilities() code existed.
     */
    private function ensureCapabilities(): void {
        $roles = [
            'administrator' => [ 'manage_mdpai_folders', 'manage_mdpai_settings' ],
            'editor'        => [ 'manage_mdpai_folders' ],
        ];

        foreach ( $roles as $roleName => $caps ) {
            $role = get_role( $roleName );
            if ( ! $role ) {
                continue;
            }
            foreach ( $caps as $cap ) {
                if ( ! $role->has_cap( $cap ) ) {
                    $role->add_cap( $cap );
                }
            }
        }
    }

    private function runUpgrader(): void {
        Upgrader::maybeUpgrade();
    }

    /**
     * Instantiate and wire up every subsystem.
     * Services are registered here so they are available via `Plugin::get()`.
     */
    private function registerServices(): void {
        // --- Core repositories & services (Phase 1) ---
        $folderRepo    = new \MediaPilotAI\Folder\FolderRepository();
        $folderService = new \MediaPilotAI\Folder\FolderService( $folderRepo );

        $this->bind( \MediaPilotAI\Folder\FolderRepository::class, $folderRepo );
        $this->bind( \MediaPilotAI\Folder\FolderService::class,    $folderService );

        // --- Media ---
        $mediaRepo    = new \MediaPilotAI\Media\MediaRepository();
        $mediaService = new \MediaPilotAI\Media\MediaService( $mediaRepo );

        $this->bind( \MediaPilotAI\Media\MediaRepository::class, $mediaRepo );
        $this->bind( \MediaPilotAI\Media\MediaService::class,    $mediaService );

        // --- Taxonomy ---
        ( new \MediaPilotAI\Taxonomy\FolderTaxonomy() )->register();

        // --- Folder utilities ---
        $zipService = new \MediaPilotAI\Folder\ZipService( $folderRepo, $mediaRepo );

        $this->bind( \MediaPilotAI\Folder\ZipService::class, $zipService );

        // --- Batch Meta ---
        $batchMetaService = new \MediaPilotAI\Media\BatchMetaService();
        $this->bind( \MediaPilotAI\Media\BatchMetaService::class, $batchMetaService );

        // --- Folder Templates ---
        $folderTemplate = new \MediaPilotAI\Folder\FolderTemplate();
        $this->bind( \MediaPilotAI\Folder\FolderTemplate::class, $folderTemplate );

        add_action( 'rest_api_init', function () use ( $folderTemplate ): void {
            ( new \MediaPilotAI\API\FolderTemplateRestController( $folderTemplate ) )->register();
        } );

        // --- Duplicate Detection ---
        $duplicateDetector = new \MediaPilotAI\Media\DuplicateDetector();
        $duplicateDetector->register();
        $this->bind( \MediaPilotAI\Media\DuplicateDetector::class, $duplicateDetector );

        add_action( 'rest_api_init', function () use ( $duplicateDetector ): void {
            ( new \MediaPilotAI\API\DuplicateRestController( $duplicateDetector ) )->register();
        } );

        // --- Gallery REST (preview for Shortcode Builder) ---
        add_action( 'rest_api_init', function () use ( $folderRepo ): void {
            ( new \MediaPilotAI\API\GalleryRestController( $folderRepo ) )->register();
        } );

        // --- Document Library REST (AJAX browsing for [mdpai_documents]) ---
        add_action( 'rest_api_init', function () use ( $folderRepo ): void {
            ( new \MediaPilotAI\API\DocumentRestController( $folderRepo ) )->register();
        } );

        // --- Smart Tags (must be before AI Auto-Tagging — AiTaggingService depends on TagRepository) ---
        $tagRepo    = new \MediaPilotAI\Tags\TagRepository();
        $tagService = new \MediaPilotAI\Tags\TagService( $tagRepo );
        $tagService->register();

        $this->bind( \MediaPilotAI\Tags\TagRepository::class, $tagRepo );
        $this->bind( \MediaPilotAI\Tags\TagService::class,    $tagService );

        add_action( 'rest_api_init', function () use ( $tagRepo, $tagService ): void {
            ( new \MediaPilotAI\Tags\TagRestController( $tagRepo, $tagService ) )->register();
        } );

        // --- Image Search Service (S58 — colour/orientation indexer) ---
        $imageSearchService = new \MediaPilotAI\Search\ImageSearchService();
        $imageSearchService->register();
        $this->bind( \MediaPilotAI\Search\ImageSearchService::class, $imageSearchService );

        // --- Advanced Search (S44) + AI Smart Search (S48) ---
        add_action( 'rest_api_init', function () use ( $folderRepo, $imageSearchService ): void {
            $searchService = new \MediaPilotAI\Search\AdvancedSearchService( $folderRepo, $imageSearchService );
            $smartSearch   = new \MediaPilotAI\AI\SmartSearchService( $searchService );
            ( new \MediaPilotAI\API\AdvancedSearchRestController( $searchService, $smartSearch ) )->register();
        } );

        // --- AI Auto-Tagging (S47) ---
        $aiTaggingService = new \MediaPilotAI\AI\AiTaggingService( $tagRepo, $folderRepo );
        $aiTaggingService->register();

        $this->bind( \MediaPilotAI\AI\AiTaggingService::class, $aiTaggingService );

        add_action( 'rest_api_init', function () use ( $aiTaggingService ): void {
            ( new \MediaPilotAI\API\AiTaggingRestController( $aiTaggingService ) )->register();
        } );

        // --- OCR (S49) ---
        $ocrService = new \MediaPilotAI\AI\OcrService( $folderRepo );
        $ocrService->register();
        $this->bind( \MediaPilotAI\AI\OcrService::class, $ocrService );

        // --- GraphQL API (WPGraphQL extension, S43) ---
        ( new \MediaPilotAI\API\GraphQLSchema( $folderRepo, $folderService ) )->register();

        // --- REST API ---
        add_action( 'rest_api_init', function () use ( $folderService, $folderRepo, $mediaService, $zipService, $batchMetaService ): void {
            ( new \MediaPilotAI\API\RestController( $folderService, $folderRepo, $mediaService, $zipService, $batchMetaService ) )->register();
        } );

        // --- WooCommerce Gallery Folder Sync ---
        ( new \MediaPilotAI\WooCommerce\ProductGallerySync( $folderRepo ) )->register();

        // --- WooCommerce Media Folder Integration (sidebar in product media modal) ---
        ( new \MediaPilotAI\WooCommerce\WooCommerceIntegration( $folderService ) )->register();

        // --- Gutenberg Gallery Block (registered on init, not admin-only) ---
        ( new \MediaPilotAI\Gallery\GalleryBlock( $folderRepo ) )->register();

        // --- Gallery Shortcode (frontend + content, not admin-only) ---
        ( new \MediaPilotAI\Gallery\GalleryShortcode( $folderRepo ) )->register();

        // --- Document Library Shortcode (frontend, not admin-only) ---
        ( new \MediaPilotAI\Frontend\DocumentLibrary( $folderRepo ) )->register();

        // --- Upload Handler (runs outside is_admin — REST API uploads too) ---
        ( new \MediaPilotAI\Upload\UploadHandler( $mediaRepo, $folderRepo ) )->register();

        // --- Migration Tool ---
        $importManager = new \MediaPilotAI\Migration\ImportManager();
        $importManager->addImporter( 'filebird',   new \MediaPilotAI\Migration\FileBirdImporter( $folderRepo ) );
        $importManager->addImporter( 'rml',        new \MediaPilotAI\Migration\RealMediaLibraryImporter( $folderRepo ) );
        $importManager->addImporter( 'wicked',     new \MediaPilotAI\Migration\WickedFoldersImporter( $folderRepo ) );
        $importManager->addImporter( 'happyfiles', new \MediaPilotAI\Migration\HappyFilesImporter( $folderRepo ) );
        $importManager->register();

        $migrationController = new \MediaPilotAI\Migration\MigrationController( $importManager );
        $migrationController->register();

        $this->bind( \MediaPilotAI\Migration\ImportManager::class,      $importManager );
        $this->bind( \MediaPilotAI\Migration\MigrationController::class, $migrationController );

        // --- CSV Import / Export ---
        $csvController = new \MediaPilotAI\CSV\CsvController( $folderRepo );
        $csvController->register();

        $this->bind( \MediaPilotAI\CSV\CsvController::class, $csvController );

        // --- Permissions System ---
        $permRepo         = new \MediaPilotAI\Folder\PermissionRepository();
        $folderPermission = new \MediaPilotAI\Folder\FolderPermission( $permRepo, $folderRepo );
        $folderPermission->register();

        $this->bind( \MediaPilotAI\Folder\PermissionRepository::class, $permRepo );
        $this->bind( \MediaPilotAI\Folder\FolderPermission::class,     $folderPermission );

        add_action( 'rest_api_init', function () use ( $folderPermission, $permRepo, $folderRepo ): void {
            ( new \MediaPilotAI\API\PermissionRestController( $folderPermission, $permRepo, $folderRepo ) )->register();
        } );

        // --- Multilingual compatibility (S26) ---
        if ( defined( 'ICL_SITEPRESS_VERSION' ) ) {
            ( new \MediaPilotAI\Compat\WpmlIntegration() )->register();
        } elseif ( defined( 'POLYLANG_VERSION' ) ) {
            ( new \MediaPilotAI\Compat\PolylangIntegration() )->register();
        } else {
            // No multilingual plugin: still enqueue RTL stylesheet when needed.
            add_action( 'admin_enqueue_scripts', static function (): void {
                if ( is_rtl() ) {
                    wp_enqueue_style(
                        'mediapilot-rtl',
                        plugin_dir_url( MDPAI_PLUGIN_FILE ) . 'admin/assets/css/rtl.css',
                        [ 'mediapilot-admin' ],
                        MDPAI_VERSION
                    );
                }
            } );
        }

        // --- Page Builder Compatibility (media modal bridge) ---
        ( new \MediaPilotAI\Compat\PageBuilderCompat( $folderRepo, $folderService ) )->register();

        // --- Page Builder Gallery Integrations (S42) ---

        // Elementor widget.
        add_action( 'elementor/widgets/register', function ( $widgetsManager ) use ( $folderRepo ): void {
            $widgetsManager->register( new \MediaPilotAI\Compat\ElementorGalleryWidget( [], null, $folderRepo ) );
        } );

        // Divi module.
        add_action( 'et_builder_ready', function (): void {
            if ( class_exists( 'ET_Builder_Module' ) ) {
                new \MediaPilotAI\Compat\DiviGalleryModule();
            }
        } );

        // Beaver Builder module.
        add_action( 'init', function () use ( $folderRepo ): void {
            if ( class_exists( 'FLBuilder' ) ) {
                \MediaPilotAI\Compat\BeaverBuilderGalleryModule::register( $folderRepo );
            }
        } );

        // WPBakery element.
        add_action( 'vc_before_init', function () use ( $folderRepo ): void {
            ( new \MediaPilotAI\Compat\WPBakeryGalleryElement( $folderRepo ) )->register();
        } );

        // ACF Folder Picker field type.
        add_action( 'acf/include_field_types', function () use ( $folderRepo ): void {
            \MediaPilotAI\Compat\AcfFolderPickerField::register( $folderRepo );
        } );

        // Bricks Builder element.
        add_action( 'init', function () use ( $folderRepo ): void {
            if ( ! class_exists( '\Bricks\Element' ) ) {
                return;
            }
            \MediaPilotAI\Compat\BricksGalleryElement::register( $folderRepo );
        } );

        // --- Image Optimization + CDN + Lazy Loading (S56) ---
        $imageOptimizer = new \MediaPilotAI\Optimization\ImageOptimizer();
        $imageOptimizer->register();
        $this->bind( \MediaPilotAI\Optimization\ImageOptimizer::class, $imageOptimizer );

        $optSettings = $imageOptimizer->getSettings();

        // CDN URL rewriting (active on all requests, not just admin).
        if ( ! empty( $optSettings['cdn_base_url'] ) && $optSettings['cdn_provider'] !== 'none' ) {
            ( new \MediaPilotAI\Optimization\CdnRewriter( (string) $optSettings['cdn_base_url'] ) )->register();
        }

        // Lazy-loader (active on frontend requests).
        if ( ! empty( $optSettings['lazy_load'] ) ) {
            ( new \MediaPilotAI\Optimization\LazyLoader() )->register();
        }

        add_action( 'rest_api_init', function () use ( $imageOptimizer ): void {
            ( new \MediaPilotAI\API\OptimizationRestController( $imageOptimizer ) )->register();
        } );

        // --- Client Sharing Portal (S59) ---
        $shareLinkRepo = new \MediaPilotAI\Frontend\ShareLinkRepository();
        $shareLinkRepo->createTables();

        $clientPortal = new \MediaPilotAI\Frontend\ClientPortal( $shareLinkRepo, $folderRepo );
        $clientPortal->register();

        $this->bind( \MediaPilotAI\Frontend\ShareLinkRepository::class, $shareLinkRepo );
        $this->bind( \MediaPilotAI\Frontend\ClientPortal::class, $clientPortal );

        add_action( 'rest_api_init', function () use ( $shareLinkRepo, $folderRepo ): void {
            ( new \MediaPilotAI\API\ShareRestController( $shareLinkRepo, $folderRepo ) )->register();
        } );

        // --- Real Filesystem Mode (S57) ---
        $fileMover = new \MediaPilotAI\Filesystem\FileMover();
        $fsSync    = new \MediaPilotAI\Filesystem\RealFolderSync( $folderRepo, $fileMover );
        $fsSync->register();

        $this->bind( \MediaPilotAI\Filesystem\FileMover::class,      $fileMover );
        $this->bind( \MediaPilotAI\Filesystem\RealFolderSync::class, $fsSync );

        // --- Media Replacement System (S60) ---
        $mediaReplacer = new \MediaPilotAI\Media\MediaReplacer( $fileMover );
        $mediaReplacer->createTable();
        $mediaReplacer->register();

        $this->bind( \MediaPilotAI\Media\MediaReplacer::class, $mediaReplacer );

        add_action( 'rest_api_init', function () use ( $mediaReplacer ): void {
            ( new \MediaPilotAI\API\ReplaceRestController( $mediaReplacer ) )->register();
        } );

        // --- Media Usage Tracker (S33) ---
        $usageTracker = new \MediaPilotAI\Media\UsageTracker();
        $usageTracker->register();
        $this->bind( \MediaPilotAI\Media\UsageTracker::class, $usageTracker );

        add_action( 'rest_api_init', function () use ( $usageTracker ): void {
            ( new \MediaPilotAI\API\UsageRestController( $usageTracker ) )->register();
        } );

        // --- Media Analytics Dashboard (S35) ---
        $analyticsDashboard = new \MediaPilotAI\Analytics\AnalyticsDashboard();
        $analyticsDashboard->register();
        $this->bind( \MediaPilotAI\Analytics\AnalyticsDashboard::class, $analyticsDashboard );

        add_action( 'rest_api_init', function () use ( $analyticsDashboard ): void {
            ( new \MediaPilotAI\API\AnalyticsRestController( $analyticsDashboard ) )->register();
        } );

        // --- Admin ---
        if ( is_admin() ) {
            ( new \MediaPilotAI\AI\AiSettingsPage() )->register();
            ( new \MediaPilotAI\Optimization\OptimizationSettingsPage( $imageOptimizer ) )->register();
            ( new \MediaPilotAI\Filesystem\FilesystemSettingsPage() )->register();

            $settingsPage = new \MediaPilotAI\Settings\SettingsPage();
            $settingsPage->setImportManager( $importManager );
            $settingsPage->register();

            // --- Portal Settings Page ---
            ( new \MediaPilotAI\Settings\PortalSettingsPage( $shareLinkRepo, $folderRepo ) )->register();

            // --- Duplicates Admin Page ---
            ( new \MediaPilotAI\Media\DuplicatesAdminPage( $folderRepo ) )->register();

            // --- Folder Templates Admin Page ---
            ( new \MediaPilotAI\Folder\TemplatesAdminPage( $folderRepo ) )->register();

            // --- Shortcode Builder Admin Page ---
            ( new \MediaPilotAI\Gallery\ShortcodeBuilderPage( $folderRepo ) )->register();

            // --- Media Library Integration ---
            ( new \MediaPilotAI\Media\MediaLibraryIntegration( $mediaRepo, $folderRepo, $folderService, $usageTracker ) )->register();

            // --- Post Type Folder Integration ---
            ( new \MediaPilotAI\PostType\PostTypeIntegration( $folderRepo, $folderService ) )->register();
        }

        // --- WP-CLI ---
        if ( defined( 'WP_CLI' ) && WP_CLI ) {
            \WP_CLI::add_command( 'mediapilot', \MediaPilotAI\CLI\MediaPilotCommand::class );
        }

        /**
         * Fires after all MediaPilot services have been registered.
         *
         * @param Plugin $plugin The plugin instance.
         */
        do_action( 'mdpai_services_registered', $this );
    }
}
