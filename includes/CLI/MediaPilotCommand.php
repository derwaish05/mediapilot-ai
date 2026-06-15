<?php

declare(strict_types=1);

namespace MediaPilotAI\CLI;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}


use MediaPilotAI\Media\DuplicateDetector;
use MediaPilotAI\AI\AiTaggingService;
use MediaPilotAI\AI\OcrService;
use MediaPilotAI\Tags\TagRepository;
use MediaPilotAI\Folder\FolderRepository;
use MediaPilotAI\Folder\FolderService;
use MediaPilotAI\Migration\ImportManager;
use MediaPilotAI\Migration\ImportProgress;
use MediaPilotAI\Migration\FileBirdImporter;
use MediaPilotAI\Migration\HappyFilesImporter;
use MediaPilotAI\Migration\RealMediaLibraryImporter;
use MediaPilotAI\Migration\WickedFoldersImporter;
use MediaPilotAI\Optimization\ImageOptimizer;
use MediaPilotAI\Filesystem\RealFolderSync;
use MediaPilotAI\Filesystem\FileMover;

/**
 * WP-CLI command group: `wp mediapilot`
 *
 * Commands:
 *   wp mediapilot folder list [--format=<table|csv|json|ids>]
 *   wp mediapilot folder create <name> [--parent=<id>]
 *   wp mediapilot folder move <id> --parent=<id>
 *   wp mediapilot folder delete <id> [--force]
 *   wp mediapilot media assign <attachment_id> --folder=<id>
 *   wp mediapilot import --from=<filebird|happyfiles|realmedia|wicked>
 *   wp mediapilot export --format=<csv>
 *   wp mediapilot duplicates scan [--type=<exact|similar|all>]
 *   wp mediapilot ai tag-all [--folder=<id>] [--limit=<n>]
 *   wp mediapilot ai ocr-all [--folder=<id>]
 *   wp mediapilot optimize [--folder=<id>] [--format=<webp|avif|auto>] [--limit=<n>]
 *   wp mediapilot filesystem sync [--dry-run]
 *
 * @package MediaPilotAI\CLI
 * @since   1.0.0
 */
class MediaPilotCommand {

    // -------------------------------------------------------------------------
    // Sub-commands
    // -------------------------------------------------------------------------

    /**
     * Scan all attachments for duplicate files.
     *
     * Runs synchronously in the CLI process (no WP Cron involved) and
     * prints a summary of each duplicate group.
     *
     * ## OPTIONS
     *
     * [--type=<type>]
     * : Which duplicate type to report.
     *   - exact   : Files with identical MD5 hash.
     *   - similar : Images with similar perceptual hash (dHash ≤ 10).
     *   - all     : Both types. (default)
     *
     * ## EXAMPLES
     *
     *     wp mediapilot duplicates scan
     *     wp mediapilot duplicates scan --type=exact
     *     wp mediapilot duplicates scan --type=similar
     *
     * @subcommand duplicates scan
     * @when       after_wp_load
     *
     * @param  string[] $args        Positional arguments (unused).
     * @param  array<string, string> $assocArgs  Named arguments.
     */
    public function duplicates_scan( array $args, array $assocArgs ): void {
        $type = $assocArgs['type'] ?? 'all';

        if ( ! in_array( $type, [ 'exact', 'similar', 'all' ], true ) ) {
            \WP_CLI::error( "Invalid --type value '{$type}'. Use: exact, similar, all." );
        }

        \WP_CLI::log( 'Starting duplicate scan…' );

        $detector = new DuplicateDetector();
        $groups   = $detector->runFullScanSync();

        if ( empty( $groups ) ) {
            \WP_CLI::success( 'No duplicates found.' );
            return;
        }

        $filtered = array_filter(
            $groups,
            static function ( array $g ) use ( $type ): bool {
                return $type === 'all'
                    || ( $type === 'exact'   && $g['type'] === 'exact'   )
                    || ( $type === 'similar' && $g['type'] === 'similar' );
            }
        );

        if ( empty( $filtered ) ) {
            \WP_CLI::success( "No '{$type}' duplicates found." );
            return;
        }

        $totalGroups = count( $filtered );
        $totalFiles  = array_sum( array_map( fn( $g ) => count( $g['files'] ), $filtered ) );

        \WP_CLI::log( "Found {$totalGroups} duplicate group(s) affecting {$totalFiles} file(s).\n" );

        foreach ( array_values( $filtered ) as $i => $group ) {
            $groupNum = $i + 1;
            $gType    = strtoupper( (string) $group['type'] );

            \WP_CLI::log( "── Group {$groupNum} [{$gType}]" );

            foreach ( $group['files'] as $file ) {
                $size = size_format( (int) $file['file_size'], 1 );
                \WP_CLI::log(
                    sprintf(
                        '   ID %-6d  %-40s  %s  %s',
                        $file['id'],
                        $file['filename'],
                        $size,
                        $file['date']
                    )
                );
            }

            \WP_CLI::log( '' );
        }

        \WP_CLI::success( "Scan complete. {$totalGroups} group(s) found." );
    }

    // -------------------------------------------------------------------------
    // ai tag-all
    // -------------------------------------------------------------------------

    /**
     * Run AI auto-tagging for all image attachments (or a folder subset).
     *
     * Sends each eligible image to the configured AI provider (AWS Rekognition
     * or Google Cloud Vision), creates MediaPilot tags for every label above the
     * confidence threshold, and optionally assigns the file to a matching folder.
     *
     * The AI provider and its settings must first be configured under
     * Media › MediaPilot AI Settings in the WordPress admin.
     *
     * ## OPTIONS
     *
     * [--folder=<id>]
     * : Restrict tagging to images inside this folder (term ID).
     *
     * [--limit=<n>]
     * : Maximum number of images to process. Default: no limit.
     *
     * ## EXAMPLES
     *
     *     wp mediapilot ai tag-all
     *     wp mediapilot ai tag-all --folder=12
     *     wp mediapilot ai tag-all --limit=100
     *     wp mediapilot ai tag-all --folder=12 --limit=50
     *
     * @subcommand ai tag-all
     * @when       after_wp_load
     *
     * @param  string[]              $args       Positional arguments (unused).
     * @param  array<string, string> $assocArgs  Named arguments.
     */
    public function ai_tag_all( array $args, array $assocArgs ): void {
        $folderId = absint( $assocArgs['folder'] ?? 0 );
        $limit    = absint( $assocArgs['limit']  ?? 0 );

        $aiService = new AiTaggingService( new TagRepository(), new FolderRepository() );
        $settings  = $aiService->getSettings();

        if ( ( $settings['provider'] ?? 'none' ) === 'none' ) {
            \WP_CLI::error( 'No AI provider is configured. Go to Media › MediaPilot AI Settings first.' );
        }

        $scope = $folderId > 0 ? "folder {$folderId}" : 'all folders';
        $cap   = $limit > 0 ? " (limit {$limit})" : '';
        \WP_CLI::log( "Starting AI tagging for {$scope}{$cap}…" );

        $result = $aiService->tagAll( $folderId, $limit );

        \WP_CLI::log(
            sprintf(
                'Processed: %d  |  Tagged: %d  |  Errors: %d',
                $result['processed'],
                $result['tagged'],
                $result['errors']
            )
        );

        if ( $result['errors'] > 0 ) {
            \WP_CLI::warning( "{$result['errors']} image(s) could not be tagged. Check _mdpai_ai_error post meta for details." );
        }

        \WP_CLI::success( 'AI tagging complete.' );
    }

    // -------------------------------------------------------------------------
    // ai ocr-all
    // -------------------------------------------------------------------------

    /**
     * Run OCR text extraction for all image attachments (or a folder subset).
     *
     * Sends each eligible image to the configured AI provider (AWS Textract
     * or Google Cloud Vision DOCUMENT_TEXT_DETECTION) and stores the
     * extracted text in the `mdpai_ocr_text` post meta field, which is also
     * indexed by the WordPress attachment search.
     *
     * The AI provider must be configured under Media › MediaPilot AI Settings.
     *
     * ## OPTIONS
     *
     * [--folder=<id>]
     * : Restrict OCR to images inside this folder (term ID).
     *
     * ## EXAMPLES
     *
     *     wp mediapilot ai ocr-all
     *     wp mediapilot ai ocr-all --folder=12
     *
     * @subcommand ai ocr-all
     * @when       after_wp_load
     *
     * @param  string[]              $args       Positional arguments (unused).
     * @param  array<string, string> $assocArgs  Named arguments.
     */
    public function ai_ocr_all( array $args, array $assocArgs ): void {
        $folderId = absint( $assocArgs['folder'] ?? 0 );

        $ocrService = new OcrService( new FolderRepository() );
        $settings   = $ocrService->getSettings();

        if ( ( $settings['provider'] ?? 'none' ) === 'none' ) {
            \WP_CLI::error( 'No AI provider is configured. Go to Media › MediaPilot AI Settings first.' );
        }

        $scope = $folderId > 0 ? "folder {$folderId}" : 'all folders';
        \WP_CLI::log( "Starting OCR for {$scope}..." );

        $result = $ocrService->ocrAll( $folderId );

        \WP_CLI::log(
            sprintf(
                'Processed: %d  |  Success: %d  |  Errors: %d',
                $result['processed'],
                $result['success'],
                $result['errors']
            )
        );

        if ( $result['errors'] > 0 ) {
            \WP_CLI::warning( "{$result['errors']} image(s) could not be OCR'd. Check _mdpai_ocr_error post meta for details." );
        }

        \WP_CLI::success( 'OCR complete.' );
    }

    // -------------------------------------------------------------------------
    // optimize
    // -------------------------------------------------------------------------

    /**
     * Convert images to WebP or AVIF and display byte savings.
     *
     * Runs synchronously — for large libraries consider running in a screen /
     * tmux session. Progress is printed after every image.
     *
     * ## OPTIONS
     *
     * [--folder=<id>]
     * : Restrict to images inside this folder (term ID). Default: all.
     *
     * [--format=<format>]
     * : Output format. One of: webp, avif, auto (uses plugin settings). Default: auto.
     *
     * [--limit=<n>]
     * : Maximum number of images to process. Default: no limit.
     *
     * ## EXAMPLES
     *
     *     wp mediapilot optimize
     *     wp mediapilot optimize --format=webp
     *     wp mediapilot optimize --folder=12 --format=avif
     *     wp mediapilot optimize --limit=100 --format=webp
     *
     * @subcommand optimize
     * @when       after_wp_load
     *
     * @param  string[]              $args       Positional arguments (unused).
     * @param  array<string, string> $assocArgs  Named arguments.
     */
    public function optimize( array $args, array $assocArgs ): void {
        $folderId = absint( $assocArgs['folder'] ?? 0 );
        $format   = strtolower( (string) ( $assocArgs['format'] ?? 'auto' ) );
        $limit    = absint( $assocArgs['limit'] ?? 0 );

        if ( ! in_array( $format, [ 'webp', 'avif', 'auto' ], true ) ) {
            \WP_CLI::error( "Invalid --format value '{$format}'. Use: webp, avif, auto." );
        }

        $scope = $folderId > 0 ? "folder {$folderId}" : 'all folders';
        $cap   = $limit > 0 ? " (limit {$limit})" : '';
        \WP_CLI::log( "Starting image optimisation for {$scope}{$cap}…" );

        $optimizer = new ImageOptimizer();
        $result    = $optimizer->optimizeAll( $folderId, $format, $limit );

        \WP_CLI::log(
            sprintf(
                'Processed: %d  |  Converted: %d  |  Saved: %s  |  Errors: %d',
                $result['processed'],
                $result['converted'],
                size_format( $result['saved_bytes'], 1 ),
                $result['errors']
            )
        );

        if ( $result['errors'] > 0 ) {
            \WP_CLI::warning( "{$result['errors']} image(s) could not be converted. Check {$format} support (GD / Imagick)." );
        }

        \WP_CLI::success( 'Optimisation complete.' );
    }

    // -------------------------------------------------------------------------
    // folder list
    // -------------------------------------------------------------------------

    /**
     * List all MediaPilot folders.
     *
     * ## OPTIONS
     *
     * [--format=<format>]
     * : Output format. One of: table, csv, json, ids. Default: table.
     *
     * ## EXAMPLES
     *
     *     wp mediapilot folder list
     *     wp mediapilot folder list --format=json
     *     wp mediapilot folder list --format=csv
     *
     * @subcommand folder list
     * @when       after_wp_load
     *
     * @param  string[]              $args
     * @param  array<string, string> $assocArgs
     */
    public function folder_list( array $args, array $assocArgs ): void {
        $format = $assocArgs['format'] ?? 'table';

        $repo = new FolderRepository();
        $tree = $repo->getTree( 0 );
        $flat = $this->flattenFolderTree( $tree );

        if ( empty( $flat ) ) {
            \WP_CLI::log( 'No folders found.' );
            return;
        }

        if ( 'ids' === $format ) {
            echo implode( "\n", array_column( $flat, 'id' ) ) . "\n"; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- WP-CLI output, not HTML
            return;
        }

        \WP_CLI\Utils\format_items( $format, $flat, [ 'id', 'name', 'parent_id', 'depth', 'file_count' ] );
    }

    // -------------------------------------------------------------------------
    // folder create
    // -------------------------------------------------------------------------

    /**
     * Create a new folder.
     *
     * ## OPTIONS
     *
     * <name>
     * : Folder name.
     *
     * [--parent=<id>]
     * : Parent folder term ID. Default: 0 (top-level).
     *
     * ## EXAMPLES
     *
     *     wp mediapilot folder create "My Photos"
     *     wp mediapilot folder create "Sub Album" --parent=5
     *
     * @subcommand folder create
     * @when       after_wp_load
     *
     * @param  string[]              $args
     * @param  array<string, string> $assocArgs
     */
    public function folder_create( array $args, array $assocArgs ): void {
        if ( empty( $args[0] ) ) {
            \WP_CLI::error( 'Please provide a folder name as the first argument.' );
        }

        $name     = (string) $args[0];
        $parentId = absint( $assocArgs['parent'] ?? 0 );

        $service = new FolderService( new FolderRepository() );

        if ( $parentId > 0 ) {
            $parent = ( new FolderRepository() )->getById( $parentId );
            if ( null === $parent ) {
                \WP_CLI::error( "Parent folder with ID {$parentId} not found." );
            }
        }

        try {
            $newId = $service->createFolder( $name, $parentId );
            \WP_CLI::success( "Folder '{$name}' created with ID {$newId}." );
        } catch ( \Exception $e ) {
            \WP_CLI::error( 'Failed to create folder: ' . $e->getMessage() );
        }
    }

    // -------------------------------------------------------------------------
    // folder move
    // -------------------------------------------------------------------------

    /**
     * Move a folder to a new parent.
     *
     * ## OPTIONS
     *
     * <id>
     * : Folder term ID to move.
     *
     * --parent=<id>
     * : Target parent folder term ID. Use 0 to move to top level.
     *
     * ## EXAMPLES
     *
     *     wp mediapilot folder move 7 --parent=2
     *     wp mediapilot folder move 7 --parent=0
     *
     * @subcommand folder move
     * @when       after_wp_load
     *
     * @param  string[]              $args
     * @param  array<string, string> $assocArgs
     */
    public function folder_move( array $args, array $assocArgs ): void {
        if ( empty( $args[0] ) ) {
            \WP_CLI::error( 'Please provide the folder ID as the first argument.' );
        }

        $folderId = absint( $args[0] );
        $parentId = absint( $assocArgs['parent'] ?? 0 );

        $repo    = new FolderRepository();
        $service = new FolderService( $repo );

        if ( null === $repo->getById( $folderId ) ) {
            \WP_CLI::error( "Folder with ID {$folderId} not found." );
        }

        if ( $parentId > 0 && null === $repo->getById( $parentId ) ) {
            \WP_CLI::error( "Parent folder with ID {$parentId} not found." );
        }

        if ( $service->isCircularMove( $folderId, $parentId ) ) {
            \WP_CLI::error( 'Cannot move a folder into one of its own descendants.' );
        }

        $ok = $service->moveFolder( $folderId, $parentId );

        if ( $ok ) {
            $target = $parentId === 0 ? 'top level' : "folder {$parentId}";
            \WP_CLI::success( "Folder {$folderId} moved to {$target}." );
        } else {
            \WP_CLI::error( "Failed to move folder {$folderId}." );
        }
    }

    // -------------------------------------------------------------------------
    // folder delete
    // -------------------------------------------------------------------------

    /**
     * Delete a folder.
     *
     * ## OPTIONS
     *
     * <id>
     * : Folder term ID to delete.
     *
     * [--force]
     * : Also delete all sub-folders recursively. Without this flag, direct
     *   children are promoted to the deleted folder's parent.
     *
     * ## EXAMPLES
     *
     *     wp mediapilot folder delete 7
     *     wp mediapilot folder delete 7 --force
     *
     * @subcommand folder delete
     * @when       after_wp_load
     *
     * @param  string[]              $args
     * @param  array<string, string> $assocArgs
     */
    public function folder_delete( array $args, array $assocArgs ): void {
        if ( empty( $args[0] ) ) {
            \WP_CLI::error( 'Please provide the folder ID as the first argument.' );
        }

        $folderId  = absint( $args[0] );
        $recursive = isset( $assocArgs['force'] );

        $repo    = new FolderRepository();
        $service = new FolderService( $repo );

        $folder = $repo->getById( $folderId );
        if ( null === $folder ) {
            \WP_CLI::error( "Folder with ID {$folderId} not found." );
        }

        $name = (string) ( $folder['name'] ?? $folderId );

        if ( $recursive ) {
            \WP_CLI::confirm( "Delete folder '{$name}' (ID {$folderId}) AND all its sub-folders recursively?" );
        } else {
            \WP_CLI::confirm( "Delete folder '{$name}' (ID {$folderId})? Child folders will be promoted to its parent." );
        }

        $ok = $service->deleteFolder( $folderId, $recursive );

        if ( $ok ) {
            \WP_CLI::success( "Folder '{$name}' (ID {$folderId}) deleted." );
        } else {
            \WP_CLI::error( "Failed to delete folder {$folderId}." );
        }
    }

    // -------------------------------------------------------------------------
    // media assign
    // -------------------------------------------------------------------------

    /**
     * Assign a media attachment to a folder.
     *
     * ## OPTIONS
     *
     * <attachment_id>
     * : WordPress attachment post ID.
     *
     * --folder=<id>
     * : Folder term ID to assign the attachment to. Use 0 to un-assign.
     *
     * ## EXAMPLES
     *
     *     wp mediapilot media assign 42 --folder=5
     *     wp mediapilot media assign 42 --folder=0
     *
     * @subcommand media assign
     * @when       after_wp_load
     *
     * @param  string[]              $args
     * @param  array<string, string> $assocArgs
     */
    public function media_assign( array $args, array $assocArgs ): void {
        if ( empty( $args[0] ) ) {
            \WP_CLI::error( 'Please provide the attachment ID as the first argument.' );
        }

        if ( ! isset( $assocArgs['folder'] ) ) {
            \WP_CLI::error( 'Please provide --folder=<id>.' );
        }

        $attachmentId = absint( $args[0] );
        $folderId     = absint( $assocArgs['folder'] );

        // Verify the attachment exists.
        $post = get_post( $attachmentId );
        if ( ! $post || 'attachment' !== $post->post_type ) {
            \WP_CLI::error( "Attachment with ID {$attachmentId} not found." );
        }

        // Verify the folder exists (skip check for ID 0 = un-assign).
        $repo = new FolderRepository();
        if ( $folderId > 0 && null === $repo->getById( $folderId ) ) {
            \WP_CLI::error( "Folder with ID {$folderId} not found." );
        }

        $service = new FolderService( $repo );
        $ok      = $service->assignFile( $attachmentId, $folderId );

        if ( $ok ) {
            $dest = $folderId === 0 ? 'no folder (un-assigned)' : "folder {$folderId}";
            \WP_CLI::success( "Attachment {$attachmentId} assigned to {$dest}." );
        } else {
            \WP_CLI::error( "Failed to assign attachment {$attachmentId}." );
        }
    }

    // -------------------------------------------------------------------------
    // import
    // -------------------------------------------------------------------------

    /**
     * Import folders from another plugin.
     *
     * Runs the full import synchronously (all batches in the CLI process).
     * Progress is printed after every batch.
     *
     * ## OPTIONS
     *
     * --from=<plugin>
     * : Source plugin slug. One of: filebird, happyfiles, realmedia, wicked.
     *
     * ## EXAMPLES
     *
     *     wp mediapilot import --from=filebird
     *     wp mediapilot import --from=happyfiles
     *
     * @subcommand import
     * @when       after_wp_load
     *
     * @param  string[]              $args
     * @param  array<string, string> $assocArgs
     */
    public function import( array $args, array $assocArgs ): void {
        $from = strtolower( (string) ( $assocArgs['from'] ?? '' ) );

        $repo      = new FolderRepository();
        $importers = [
            'filebird'   => new FileBirdImporter( $repo ),
            'happyfiles' => new HappyFilesImporter( $repo ),
            'realmedia'  => new RealMediaLibraryImporter( $repo ),
            'wicked'     => new WickedFoldersImporter( $repo ),
        ];

        if ( ! isset( $importers[ $from ] ) ) {
            $valid = implode( ', ', array_keys( $importers ) );
            \WP_CLI::error( "Unknown plugin slug '{$from}'. Valid values: {$valid}." );
        }

        $importer = $importers[ $from ];

        if ( ! $importer->isAvailable() ) {
            \WP_CLI::error( "'{$from}' is not installed or its data tables are missing. Install the plugin and migrate its data first." );
        }

        \WP_CLI::log( "Starting import from {$importer->getLabel()}…" );

        $progress = ImportProgress::load( $from );

        // Reset any stale state.
        if ( $progress->status === 'running' || $progress->status === 'done' ) {
            ImportProgress::delete( $from );
            $progress = new ImportProgress();
        }

        $progress->status = 'running';
        $progress->save( $from );

        $batchSize = 100;
        $batches   = 0;

        do {
            $hasMore = $importer->runBatch( $progress, $batchSize );
            $progress->save( $from );
            $batches++;

            \WP_CLI::log(
                sprintf(
                    'Batch %d — created: %d  skipped: %d  errors: %d',
                    $batches,
                    $progress->created,
                    $progress->skipped,
                    $progress->errors
                )
            );
        } while ( $hasMore );

        $progress->status = 'done';
        $progress->save( $from );

        \WP_CLI::success(
            sprintf(
                'Import complete. Created: %d  |  Skipped: %d  |  Errors: %d',
                $progress->created,
                $progress->skipped,
                $progress->errors
            )
        );
    }

    // -------------------------------------------------------------------------
    // export
    // -------------------------------------------------------------------------

    /**
     * Export folder structure and file assignments to a CSV file.
     *
     * ## OPTIONS
     *
     * --format=<format>
     * : Export format. Currently only `csv` is supported.
     *
     * [--output=<path>]
     * : Output file path. Default: mediapilot-export-<date>.csv in the current directory.
     *
     * [--type=<type>]
     * : What to export. One of: folders, files, all. Default: all.
     *
     * ## EXAMPLES
     *
     *     wp mediapilot export --format=csv
     *     wp mediapilot export --format=csv --type=folders
     *     wp mediapilot export --format=csv --output=/tmp/mediapilot-ai.csv
     *
     * @subcommand export
     * @when       after_wp_load
     *
     * @param  string[]              $args
     * @param  array<string, string> $assocArgs
     */
    /**
     * Resolve a safe, contained CSV export path.
     *
     * Exports are always written inside an "mediapilot-ai" subdirectory of the
     * WordPress uploads folder (multisite-safe). Any --output value is reduced
     * to a sanitised basename so it cannot escape that directory or land in the
     * site root / an arbitrary location.
     */
    private function resolveExportPath( string $output, string $defaultName ): string {
        $uploads = wp_get_upload_dir();
        $dir     = trailingslashit( (string) $uploads['basedir'] ) . 'mediapilot-ai';

        if ( ! wp_mkdir_p( $dir ) ) {
            \WP_CLI::error( "Cannot create export directory: {$dir}" );
        }

        $name = '' !== $output ? sanitize_file_name( basename( $output ) ) : $defaultName;

        if ( '' === $name || ! str_ends_with( strtolower( $name ), '.csv' ) ) {
            $name = $defaultName;
        }

        return trailingslashit( $dir ) . $name;
    }

    public function export( array $args, array $assocArgs ): void {
        $format = strtolower( (string) ( $assocArgs['format'] ?? 'csv' ) );
        $type   = strtolower( (string) ( $assocArgs['type']   ?? 'all' ) );
        $output = (string) ( $assocArgs['output'] ?? '' );

        if ( $format !== 'csv' ) {
            \WP_CLI::error( "Unsupported format '{$format}'. Only 'csv' is supported." );
        }

        if ( ! in_array( $type, [ 'folders', 'files', 'all' ], true ) ) {
            \WP_CLI::error( "Invalid --type value '{$type}'. Use: folders, files, all." );
        }

        $repo = new FolderRepository();
        $date = gmdate( 'Y-m-d' );

        // ---- Export folders ------------------------------------------------

        if ( in_array( $type, [ 'folders', 'all' ], true ) ) {
            $foldersFile = $this->resolveExportPath( $output, "mediapilot-folders-{$date}.csv" );
            $tree        = $repo->getTree( 0 );
            $flat        = $this->flattenFolderTree( $tree );

            $fh = fopen( $foldersFile, 'w' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen
            if ( false === $fh ) {
                \WP_CLI::error( "Cannot open '{$foldersFile}' for writing." );
            }

            // BOM for Excel UTF-8 compatibility.
            fwrite( $fh, "\xEF\xBB\xBF" ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fwrite
            fputcsv( $fh, [ 'id', 'name', 'parent_id', 'depth', 'file_count' ] );

            foreach ( $flat as $folder ) {
                fputcsv( $fh, [
                    $folder['id'],
                    $folder['name'],
                    $folder['parent_id'],
                    $folder['depth'],
                    $folder['file_count'],
                ] );
            }

            fclose( $fh ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose
            \WP_CLI::log( "Folders exported to: {$foldersFile}" );
        }

        // ---- Export file assignments ----------------------------------------

        if ( in_array( $type, [ 'files', 'all' ], true ) ) {
            // When exporting both, files always get their own distinct name.
            $filesFile = $this->resolveExportPath(
                $type === 'all' ? '' : $output,
                "mediapilot-file-assignments-{$date}.csv"
            );

            $query = new \WP_Query( [
                'post_type'      => 'attachment',
                'post_status'    => 'inherit',
                'posts_per_page' => -1,
                'no_found_rows'  => true,
                'fields'         => 'ids',
            ] );

            $fh = fopen( $filesFile, 'w' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen
            if ( false === $fh ) {
                \WP_CLI::error( "Cannot open '{$filesFile}' for writing." );
            }

            fwrite( $fh, "\xEF\xBB\xBF" ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fwrite
            fputcsv( $fh, [ 'attachment_id', 'filename', 'folder_id', 'folder_name' ] );

            foreach ( $query->posts as $attachmentId ) {
                $filePath  = get_attached_file( (int) $attachmentId );
                $filename  = $filePath ? basename( $filePath ) : '';
                $folderId  = $repo->getFileFolder( (int) $attachmentId );
                $folderRow = $folderId > 0 ? $repo->getById( $folderId ) : null;
                $folderName = $folderRow ? (string) $folderRow['name'] : '';

                fputcsv( $fh, [ $attachmentId, $filename, $folderId > 0 ? $folderId : '', $folderName ] );
            }

            fclose( $fh ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose
            \WP_CLI::log( "File assignments exported to: {$filesFile}" );
        }

        \WP_CLI::success( 'Export complete.' );
    }

    // -------------------------------------------------------------------------
    // filesystem sync
    // -------------------------------------------------------------------------

    /**
     * Reconcile virtual MediaPilot folders with physical filesystem directories.
     *
     * Scans every folder in the virtual tree, ensures each has a matching
     * directory under {uploads}/mediapilot-ai/, and moves any misplaced files into the
     * correct directory. Runs synchronously — use a screen/tmux session for
     * large libraries.
     *
     * ## OPTIONS
     *
     * [--dry-run]
     * : Preview what would change without moving any files.
     *
     * ## EXAMPLES
     *
     *     wp mediapilot filesystem sync
     *     wp mediapilot filesystem sync --dry-run
     *
     * @subcommand filesystem sync
     * @when       after_wp_load
     *
     * @param  string[]              $args
     * @param  array<string, string> $assocArgs
     */
    public function filesystem_sync( array $args, array $assocArgs ): void {
        $dryRun = isset( $assocArgs['dry-run'] );

        $sync = new RealFolderSync(
            new FolderRepository(),
            new FileMover()
        );

        if ( ! $sync->isEnabled() ) {
            \WP_CLI::error( 'Real Filesystem Mode is not enabled. Go to Media › Filesystem to enable it.' );
        }

        if ( $dryRun ) {
            \WP_CLI::log( '[DRY RUN] No files will be moved.' );
        }

        \WP_CLI::log( 'Starting filesystem sync…' );

        $result = $sync->syncAll();

        \WP_CLI::log(
            sprintf(
                'Scanned: %d  |  Moved: %d  |  Dirs created: %d  |  Errors: %d',
                $result['scanned'],
                $dryRun ? 0 : $result['moved'],
                $result['dirs_created'],
                $result['errors']
            )
        );

        \WP_CLI::success( 'Sync complete.' );
    }

    // -------------------------------------------------------------------------
    // versions prune
    // -------------------------------------------------------------------------

    /**
     * Prune old version archives, keeping only the N most recent per attachment.
     *
     * Deletes both the archived files from disk and the corresponding rows in
     * wp_mdpai_versions. The N most recent versions per attachment are preserved.
     *
     * ## OPTIONS
     *
     * [--keep=<n>]
     * : Number of versions to keep per attachment. Default: 5.
     *
     * [--attachment=<id>]
     * : Only prune versions for this single attachment ID.
     *
     * ## EXAMPLES
     *
     *     wp mediapilot versions prune
     *     wp mediapilot versions prune --keep=3
     *     wp mediapilot versions prune --keep=1 --attachment=42
     *
     * @subcommand versions prune
     * @when       after_wp_load
     *
     * @param  string[]              $args
     * @param  array<string, string> $assocArgs
     */
    public function versions_prune( array $args, array $assocArgs ): void {
        $keep         = max( 1, (int) ( $assocArgs['keep'] ?? \MediaPilotAI\Media\VersionControl::DEFAULT_KEEP ) );
        $attachmentId = isset( $assocArgs['attachment'] ) ? absint( $assocArgs['attachment'] ) : 0;

        $vc = new \MediaPilotAI\Media\VersionControl();

        if ( $attachmentId > 0 ) {
            $deleted = $vc->pruneVersions( $attachmentId, $keep );
            \WP_CLI::success(
                sprintf( 'Pruned %d version record(s) for attachment %d (kept last %d).', $deleted, $attachmentId, $keep )
            );
            return;
        }

        $stats = $vc->pruneAll( $keep );

        \WP_CLI::success(
            sprintf(
                'Pruned %d record(s) across %d attachment(s) (kept last %d per attachment).',
                $stats['records_deleted'],
                $stats['attachments'],
                $keep
            )
        );
    }

    // -------------------------------------------------------------------------
    // usage scan
    // -------------------------------------------------------------------------

    /**
     * Rebuild the media usage index for the entire site.
     *
     * Scans every published (and draft) post for attachment references in post
     * content, custom fields, featured images, Gutenberg blocks, and gallery
     * shortcodes. Also scans all widget instances.
     *
     * This is a full rebuild — the wp_mdpai_usage table is truncated first.
     *
     * ## EXAMPLES
     *
     *     wp mediapilot usage scan
     *
     * @subcommand usage scan
     * @when       after_wp_load
     *
     * @param  string[]              $args
     * @param  array<string, string> $assocArgs
     */
    public function usage_scan( array $args, array $assocArgs ): void {
        $tracker = new \MediaPilotAI\Media\UsageTracker();

        $total    = (int) wp_count_posts( 'post' )->publish;
        $progress = \WP_CLI\Utils\make_progress_bar( 'Scanning posts', max( 1, $total ) );

        $stats = $tracker->scanAll(
            function ( int $done, int $scanTotal ) use ( $progress ): void {
                $progress->tick();
            }
        );

        $progress->finish();

        \WP_CLI::success(
            sprintf(
                'Usage scan complete. Posts scanned: %d | References indexed: %d',
                $stats['scanned'],
                $stats['references']
            )
        );
    }

    // -------------------------------------------------------------------------
    // Private helpers
    // -------------------------------------------------------------------------

    /**
     * Recursively flattens a nested folder tree into a flat array for CLI output.
     *
     * @param  array<int, array<string, mixed>> $tree   Nested folder tree.
     * @param  int                              $depth  Current depth level (for display).
     * @return array<int, array<string, mixed>>
     */
    private function flattenFolderTree( array $tree, int $depth = 0 ): array {
        $flat = [];
        foreach ( $tree as $folder ) {
            $flat[] = [
                'id'         => $folder['id'],
                'name'       => str_repeat( '  ', $depth ) . $folder['name'],
                'parent_id'  => $folder['parent'] ?? 0,
                'depth'      => $depth,
                'file_count' => $folder['count'] ?? 0,
            ];
            if ( ! empty( $folder['children'] ) ) {
                $flat = array_merge( $flat, $this->flattenFolderTree( $folder['children'], $depth + 1 ) );
            }
        }
        return $flat;
    }
}
