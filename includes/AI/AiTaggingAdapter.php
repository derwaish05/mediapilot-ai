<?php

declare(strict_types=1);

namespace MediaPilotAI\AI;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}


/**
 * Contract that every AI image-analysis adapter must fulfil.
 *
 * @package MediaPilotAI\AI
 * @since   1.0.0
 */
interface AiTaggingAdapter {

    /**
     * True when all required credentials are present in settings.
     */
    public function isConfigured(): bool;

    /**
     * Analyse the image at $imageUrl and return detected labels with confidence.
     *
     * @param  string $imageUrl     Publicly accessible URL of the image.
     * @param  int    $attachmentId WordPress attachment post ID (used for logging).
     * @return array<int, array{label: string, confidence: float}>
     *   Each element: 'label' (string), 'confidence' (0.0–100.0).
     *
     * @throws \RuntimeException On API / network error.
     */
    public function analyzeImage( string $imageUrl, int $attachmentId ): array;
}
