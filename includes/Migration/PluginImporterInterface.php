<?php

declare(strict_types=1);

namespace MediaPilotAI\Migration;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}


/**
 * Contract that every plugin importer must satisfy.
 *
 * An importer reads one third-party plugin's folder/category taxonomy,
 * maps it to mdpai_folder terms, and reassigns attachments.
 *
 * @package MediaPilotAI\Migration
 * @since   1.0.0
 */
interface PluginImporterInterface {

    /**
     * Human-readable name shown in the admin UI.
     */
    public function getLabel(): string;

    /**
     * The source taxonomy (or other data source) slug this importer reads from.
     */
    public function getSourceTaxonomy(): string;

    /**
     * Returns true when the source plugin's data is detected in the current site.
     *
     * Used to decide whether to offer the importer in the admin UI.
     */
    public function isAvailable(): bool;

    /**
     * Executes one batch of the import.
     *
     * @param  ImportProgress $progress  Mutable progress object; update it as you go.
     * @param  int            $batchSize Maximum number of source terms to process per call.
     * @return bool  True when the import is fully complete, false if more batches remain.
     */
    public function runBatch( ImportProgress $progress, int $batchSize = 50 ): bool;
}
