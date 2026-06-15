<?php

declare(strict_types=1);

namespace MediaPilotAI\Migration;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}


/**
 * Central dispatcher for all plugin migration importers.
 *
 * Responsibilities:
 *   - Register and expose all available PluginImporterInterface instances.
 *   - Trigger an import by scheduling a WP Cron job.
 *   - Execute one cron batch and re-schedule until the import is done.
 *   - Expose progress data for admin-side polling.
 *
 * Cron event:  mdpai_migration_batch
 * Cron arg:    string $importerSlug
 *
 * @package MediaPilotAI\Migration
 * @since   1.0.0
 */
class ImportManager {

    private const CRON_HOOK       = 'mdpai_migration_batch';
    private const BATCH_SIZE      = 50;
    private const RESCHEDULE_SECS = 30; // seconds between batches

    /** @var array<string, PluginImporterInterface> slug → importer */
    private array $importers = [];

    // -------------------------------------------------------------------------
    // Registration
    // -------------------------------------------------------------------------

    /**
     * Register hooks. Call once on plugins_loaded / init.
     */
    public function register(): void {
        add_action( self::CRON_HOOK, [ $this, 'processBatch' ] );
    }

    /**
     * Add an importer.
     *
     * @param string                  $slug      Unique identifier (e.g. 'filebird').
     * @param PluginImporterInterface $importer
     */
    public function addImporter( string $slug, PluginImporterInterface $importer ): void {
        $this->importers[ $slug ] = $importer;
    }

    // -------------------------------------------------------------------------
    // Public API
    // -------------------------------------------------------------------------

    /**
     * Returns all registered importers, keyed by slug.
     *
     * @return array<string, PluginImporterInterface>
     */
    public function getImporters(): array {
        return $this->importers;
    }

    /**
     * Returns a single importer by slug, or null if not registered.
     */
    public function getImporter( string $slug ): ?PluginImporterInterface {
        return $this->importers[ $slug ] ?? null;
    }

    /**
     * Starts a background import for the given importer slug.
     *
     * Resets any existing progress and schedules the first cron batch.
     * Returns false if the slug is unknown or already running.
     */
    public function startImport( string $slug ): bool {
        if ( ! isset( $this->importers[ $slug ] ) ) {
            return false;
        }

        $progress = ImportProgress::load( $slug );

        if ( 'running' === $progress->status ) {
            return false; // already running
        }

        // Reset progress.
        ImportProgress::delete( $slug );
        $progress         = new ImportProgress();
        $progress->status = 'running';
        $progress->save( $slug );

        // Schedule the first batch immediately.
        $this->scheduleBatch( $slug, time() );

        return true;
    }

    /**
     * Cancels a running import by removing the cron event and resetting progress.
     */
    public function cancelImport( string $slug ): void {
        $this->unscheduleBatch( $slug );

        $progress         = ImportProgress::load( $slug );
        $progress->status = 'idle';
        $progress->save( $slug );
    }

    /**
     * Returns the current ImportProgress for a slug.
     */
    public function getProgress( string $slug ): ImportProgress {
        return ImportProgress::load( $slug );
    }

    // -------------------------------------------------------------------------
    // Cron callback
    // -------------------------------------------------------------------------

    /**
     * Cron callback: processes one batch and re-schedules if not done.
     *
     * @internal Called by WordPress cron; do not call directly.
     */
    public function processBatch( string $slug ): void {
        $importer = $this->importers[ $slug ] ?? null;

        if ( null === $importer ) {
            return;
        }

        $progress = ImportProgress::load( $slug );

        if ( 'running' !== $progress->status ) {
            return;
        }

        try {
            $done = $importer->runBatch( $progress, self::BATCH_SIZE );
        } catch ( \Throwable $e ) {
            $progress->status     = 'error';
            $progress->messages[] = 'Fatal: ' . $e->getMessage();
            $progress->save( $slug );
            return;
        }

        if ( $done ) {
            $progress->status = 'done';
            $progress->save( $slug );
            $this->unscheduleBatch( $slug );
        } else {
            $progress->save( $slug );
            $this->scheduleBatch( $slug, time() + self::RESCHEDULE_SECS );
        }
    }

    // -------------------------------------------------------------------------
    // Private helpers
    // -------------------------------------------------------------------------

    private function scheduleBatch( string $slug, int $timestamp ): void {
        if ( ! wp_next_scheduled( self::CRON_HOOK, [ $slug ] ) ) {
            wp_schedule_single_event( $timestamp, self::CRON_HOOK, [ $slug ] );
        }
    }

    private function unscheduleBatch( string $slug ): void {
        $timestamp = wp_next_scheduled( self::CRON_HOOK, [ $slug ] );

        if ( $timestamp ) {
            wp_unschedule_event( $timestamp, self::CRON_HOOK, [ $slug ] );
        }
    }
}
