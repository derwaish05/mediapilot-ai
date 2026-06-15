<?php

declare(strict_types=1);

namespace MediaPilotAI\Migration;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}


/**
 * Mutable value object that tracks the progress of a running migration.
 *
 * Persisted to wp_options under the key `mdpai_migration_progress_{slug}` so
 * that admin-side polling can read the current state without a cron callback.
 *
 * Status values:
 *   idle       — not started or reset
 *   running    — cron job is actively processing batches
 *   done       — import completed successfully
 *   error      — import stopped with a fatal error
 *
 * @package MediaPilotAI\Migration
 * @since   1.0.0
 */
class ImportProgress {

    public string $status    = 'idle';
    public int    $total     = 0;   // total source terms detected
    public int    $processed = 0;   // source terms processed so far
    public int    $created   = 0;   // mdpai_folder terms created
    public int    $skipped   = 0;   // terms skipped (e.g. already exist)
    public int    $errors    = 0;   // non-fatal errors
    public int    $offset    = 0;   // current batch offset (for paged queries)

    /** @var string[] */
    public array  $messages  = [];

    // -------------------------------------------------------------------------
    // Factory / persistence
    // -------------------------------------------------------------------------

    /**
     * Loads progress from wp_options, or returns a fresh idle instance.
     */
    public static function load( string $importerSlug ): self {
        $data = get_option( self::optionKey( $importerSlug ), null );

        if ( ! is_array( $data ) ) {
            return new self();
        }

        $p            = new self();
        $p->status    = (string)  ( $data['status']    ?? 'idle' );
        $p->total     = (int)     ( $data['total']     ?? 0 );
        $p->processed = (int)     ( $data['processed'] ?? 0 );
        $p->created   = (int)     ( $data['created']   ?? 0 );
        $p->skipped   = (int)     ( $data['skipped']   ?? 0 );
        $p->errors    = (int)     ( $data['errors']    ?? 0 );
        $p->offset    = (int)     ( $data['offset']    ?? 0 );
        $p->messages  = (array)   ( $data['messages']  ?? [] );

        return $p;
    }

    /**
     * Saves the current progress to wp_options.
     */
    public function save( string $importerSlug ): void {
        update_option( self::optionKey( $importerSlug ), $this->toArray(), false );
    }

    /**
     * Removes the progress entry from wp_options (reset).
     */
    public static function delete( string $importerSlug ): void {
        delete_option( self::optionKey( $importerSlug ) );
    }

    // -------------------------------------------------------------------------
    // Helpers
    // -------------------------------------------------------------------------

    /** @return array<string, mixed> */
    public function toArray(): array {
        return [
            'status'    => $this->status,
            'total'     => $this->total,
            'processed' => $this->processed,
            'created'   => $this->created,
            'skipped'   => $this->skipped,
            'errors'    => $this->errors,
            'offset'    => $this->offset,
            'messages'  => array_slice( $this->messages, -50 ), // keep last 50
        ];
    }

    public function percent(): int {
        if ( $this->total <= 0 ) {
            return $this->status === 'done' ? 100 : 0;
        }

        return (int) min( 100, round( ( $this->processed / $this->total ) * 100 ) );
    }

    private static function optionKey( string $importerSlug ): string {
        return 'mdpai_migration_progress_' . sanitize_key( $importerSlug );
    }
}
