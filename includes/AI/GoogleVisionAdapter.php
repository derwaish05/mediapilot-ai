<?php

declare(strict_types=1);

namespace MediaPilotAI\AI;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}


/**
 * Google Cloud Vision adapter for AI image labelling (S47).
 *
 * Uses the LABEL_DETECTION feature of the Cloud Vision REST API.
 * Passes the image by public URL (sourceUri) — no download or base64 needed.
 *
 * Required settings (from mdpai_ai_settings):
 *   google_api_key  string  Cloud Vision API key (server key, never exposed client-side).
 *
 * @package MediaPilotAI\AI
 * @since   1.0.0
 */
class GoogleVisionAdapter implements AiTaggingAdapter {

    private const ENDPOINT = 'https://vision.googleapis.com/v1/images:annotate';

    private string $apiKey;

    /** @param array<string, mixed> $settings */
    public function __construct( array $settings ) {
        $this->apiKey = trim( (string) ( $settings['google_api_key'] ?? '' ) );
    }

    public function isConfigured(): bool {
        return '' !== $this->apiKey;
    }

    /**
     * @return array<int, array{label: string, confidence: float}>
     * @throws \RuntimeException
     */
    public function analyzeImage( string $imageUrl, int $attachmentId ): array {
        $payload = (string) wp_json_encode( [
            'requests' => [
                [
                    'image'    => [ 'source' => [ 'imageUri' => $imageUrl ] ],
                    'features' => [ [ 'type' => 'LABEL_DETECTION', 'maxResults' => 50 ] ],
                ],
            ],
        ] );

        $url = add_query_arg( 'key', $this->apiKey, self::ENDPOINT );

        $response = wp_remote_post( $url, [
            'timeout' => 30,
            'headers' => [ 'Content-Type' => 'application/json' ],
            'body'    => $payload,
        ] );

        if ( is_wp_error( $response ) ) {
            throw new \RuntimeException( 'Google Vision API error: ' . $response->get_error_message() ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped
        }

        $code = (int) wp_remote_retrieve_response_code( $response );
        $body = (string) wp_remote_retrieve_body( $response );

        if ( $code !== 200 ) {
            throw new \RuntimeException( "Google Vision returned HTTP {$code}: {$body}" ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped
        }

        $decoded = json_decode( $body, true );

        if ( ! is_array( $decoded ) ) {
            throw new \RuntimeException( 'Google Vision returned invalid JSON.' ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped
        }

        // Surface any API-level error reported inside the 200 response.
        if ( isset( $decoded['responses'][0]['error']['message'] ) ) {
            throw new \RuntimeException( // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped
                'Google Vision error: ' . (string) $decoded['responses'][0]['error']['message']  // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- exception message, not HTML output
            );
        }

        $labels = [];
        foreach ( $decoded['responses'][0]['labelAnnotations'] ?? [] as $item ) {
            $labels[] = [
                'label'      => (string) ( $item['description'] ?? '' ),
                // Vision API returns score as 0.0–1.0; convert to 0.0–100.0.
                'confidence' => (float) round( ( (float) ( $item['score'] ?? 0.0 ) ) * 100.0, 2 ),
            ];
        }

        return $labels;
    }
}
