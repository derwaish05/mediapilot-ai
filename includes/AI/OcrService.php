<?php

declare(strict_types=1);

namespace MediaPilotAI\AI;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}


use MediaPilotAI\Folder\FolderRepository;
use MediaPilotAI\Taxonomy\FolderTaxonomy;

/**
 * OCR (Text in Images) Service — S49.
 *
 * Extracts text from image attachments using either AWS Textract or
 * Google Cloud Vision (DOCUMENT_TEXT_DETECTION). Extracted text is
 * stored in attachment meta and included in the WordPress search index.
 *
 * Flow on image upload:
 *  1. `onUpload()` fires at priority 25 on `add_attachment` (after AI
 *     tagging which runs at priority 20).
 *  2. A WP Cron single event `mdpai_ocr_attachment` is scheduled so the
 *     upload HTTP response is never delayed by the API call.
 *  3. `runOcr()` calls the configured provider, stores the result in
 *     `mdpai_ocr_text` post meta, and records audit meta keys.
 *
 * Attachment meta keys:
 *   mdpai_ocr_text     string  Extracted OCR text (public, searchable).
 *   _mdpai_ocr_at      string  ISO 8601 timestamp of last OCR run.
 *   _mdpai_ocr_error   string  Last error message; empty on success.
 *   _mdpai_ocr_provider string Provider that ran the OCR ('aws'|'google').
 *
 * Settings (shared with AI tagging via `mdpai_ai_settings` option):
 *   provider         'none'|'aws'|'google'
 *   aws_access_key   string
 *   aws_secret_key   string
 *   aws_region       string  e.g. "us-east-1"
 *   google_api_key   string
 *
 * @package MediaPilotAI\AI
 * @since   1.0.0
 */
class OcrService {

    // -------------------------------------------------------------------------
    // Constants
    // -------------------------------------------------------------------------

    /** Public meta key — used by search filter and REST. */
    public const META_OCR_TEXT  = 'mdpai_ocr_text';

    private const META_OCR_AT   = '_mdpai_ocr_at';
    private const META_OCR_ERR  = '_mdpai_ocr_error';
    private const META_OCR_PROV = '_mdpai_ocr_provider';

    /** AWS Textract service identifier (used in Signature V4 scope). */
    private const AWS_SERVICE = 'textract';

    /** Textract action target header value. */
    private const AWS_TARGET = 'Textract.DetectDocumentText';

    /** AWS Textract maximum image size in bytes (10 MB hard limit). */
    private const TEXTRACT_MAX_BYTES = 10_485_760;

    /** Google Vision OCR endpoint (same host as label detection). */
    private const GOOGLE_ENDPOINT = 'https://vision.googleapis.com/v1/images:annotate';

    // -------------------------------------------------------------------------
    // Constructor
    // -------------------------------------------------------------------------

    public function __construct(
        private readonly FolderRepository $folderRepo,
    ) {}

    // -------------------------------------------------------------------------
    // Registration
    // -------------------------------------------------------------------------

    /**
     * Register all WordPress hooks owned by this service.
     *
     * Called once from Plugin::registerServices(). Hooks are registered
     * unconditionally so cron callbacks fire outside admin context too.
     */
    public function register(): void {
        // Run after AiTaggingService::onUpload() (priority 20).
        add_action( 'add_attachment', [ $this, 'onUpload' ], 25 );

        // Cron handler — called when the scheduled event fires.
        add_action( 'mdpai_ocr_attachment', [ $this, 'runOcr' ] );

        // Extend WP attachment search to cover OCR text.
        add_filter( 'posts_search', [ $this, 'includeOcrInSearch' ], 10, 2 );

        // Attachment details meta box.
        add_action( 'add_meta_boxes', [ $this, 'registerMetaBox' ] );
    }

    // -------------------------------------------------------------------------
    // Upload hook
    // -------------------------------------------------------------------------

    /**
     * Schedule an async OCR job for newly uploaded images.
     *
     * Non-image MIME types and uploads when the provider is 'none' are
     * silently skipped — no error is produced.
     *
     * @param int $attachmentId Newly created attachment post ID.
     */
    public function onUpload( int $attachmentId ): void {
        $settings = $this->getSettings();

        if ( ( $settings['provider'] ?? 'none' ) === 'none' ) {
            return;
        }

        $post = get_post( $attachmentId );
        if ( ! $post instanceof \WP_Post ) {
            return;
        }

        if ( ! str_starts_with( (string) $post->post_mime_type, 'image/' ) ) {
            return;
        }

        // Schedule for immediate execution on the next cron tick.
        wp_schedule_single_event( time(), 'mdpai_ocr_attachment', [ $attachmentId ] );
    }

    // -------------------------------------------------------------------------
    // OCR execution
    // -------------------------------------------------------------------------

    /**
     * Run OCR on a single attachment and persist the result.
     *
     * Also callable directly from the WP-CLI batch command and any future
     * REST endpoint. Throws on provider error; silently returns when provider
     * is 'none' or the attachment is not an image.
     *
     * @param  int    $attachmentId  Attachment post ID.
     * @return string                Extracted OCR text (may be empty string).
     * @throws \RuntimeException     When the provider API call fails.
     */
    public function runOcr( int $attachmentId ): string {
        $settings = $this->getSettings();
        $provider = $settings['provider'] ?? 'none';

        if ( $provider === 'none' ) {
            return '';
        }

        $post = get_post( $attachmentId );
        if ( ! $post instanceof \WP_Post ) {
            return '';
        }

        if ( ! str_starts_with( (string) $post->post_mime_type, 'image/' ) ) {
            return '';
        }

        $imageUrl = (string) wp_get_attachment_url( $attachmentId );
        if ( '' === $imageUrl ) {
            throw new \RuntimeException( "Cannot resolve URL for attachment {$attachmentId}." ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped
        }

        try {
            $text = match ( $provider ) {
                'aws'    => $this->runAwsTextract( $imageUrl, $settings ),
                'google' => $this->runGoogleVisionOcr( $imageUrl, $settings ),
                default  => '',
            };

            update_post_meta( $attachmentId, self::META_OCR_TEXT,  $text );
            update_post_meta( $attachmentId, self::META_OCR_AT,    gmdate( 'c' ) );
            update_post_meta( $attachmentId, self::META_OCR_PROV,  $provider );
            delete_post_meta( $attachmentId, self::META_OCR_ERR );

            return $text;
        } catch ( \RuntimeException $e ) {
            update_post_meta( $attachmentId, self::META_OCR_ERR, $e->getMessage() );
            throw $e;
        }
    }

    // -------------------------------------------------------------------------
    // Search integration
    // -------------------------------------------------------------------------

    /**
     * Extend the native WP attachment search to also match `mdpai_ocr_text` meta.
     *
     * Injected into the `posts_search` SQL fragment only when:
     *  - The search clause is non-empty, and
     *  - The query targets the `attachment` post type.
     *
     * @param  string    $search  The SQL WHERE clause fragment built by WP_Query.
     * @param  \WP_Query $query   The current query object.
     * @return string             Modified SQL WHERE fragment.
     */
    public function includeOcrInSearch( string $search, \WP_Query $query ): string {
        global $wpdb;

        if ( empty( $search ) || $query->get( 'post_type' ) !== 'attachment' ) {
            return $search;
        }

        $s = (string) $query->get( 's' );
        if ( '' === $s ) {
            return $search;
        }

        $like = '%' . $wpdb->esc_like( $s ) . '%';
        $ocrClause = $wpdb->prepare(  // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
            " OR EXISTS (SELECT 1 FROM {$wpdb->postmeta} _ocr WHERE _ocr.post_id = {$wpdb->posts}.ID AND _ocr.meta_key = 'mdpai_ocr_text' AND _ocr.meta_value LIKE %s)",
            $like
        );

        // Insert before the final closing parenthesis of the search clause.
        $trimmed = rtrim( $search );
        if ( str_ends_with( $trimmed, ')' ) ) {
            $search = substr( $trimmed, 0, -1 ) . $ocrClause . ')';
        }

        return $search;
    }

    // -------------------------------------------------------------------------
    // Attachment details meta box
    // -------------------------------------------------------------------------

    /**
     * Register the "OCR Extracted Text" meta box on the attachment edit screen.
     */
    public function registerMetaBox(): void {
        add_meta_box(
            'mediapilot-ocr-text',
            __( 'OCR Extracted Text', 'mediapilot-ai'),
            [ $this, 'renderMetaBox' ],
            'attachment',
            'normal',
            'low'
        );
    }

    /**
     * Render the collapsible OCR text panel inside the attachment edit screen.
     *
     * @param \WP_Post $post The attachment post being edited.
     */
    public function renderMetaBox( \WP_Post $post ): void {
        $text     = (string) get_post_meta( $post->ID, self::META_OCR_TEXT,  true );
        $at       = (string) get_post_meta( $post->ID, self::META_OCR_AT,    true );
        $error    = (string) get_post_meta( $post->ID, self::META_OCR_ERR,   true );
        $provider = (string) get_post_meta( $post->ID, self::META_OCR_PROV,  true );

        echo '<details style="margin:0;">';
        echo '<summary style="cursor:pointer;font-weight:600;padding:4px 0;">';

        if ( '' !== $error ) {
            echo '<span style="color:#d63638;">' . esc_html__( 'OCR error — click to expand', 'mediapilot-ai') . '</span>';
        } elseif ( '' !== $text ) {
            $wordCount = str_word_count( $text );
            /* translators: %d: number of words extracted by OCR */
            echo esc_html( sprintf( __( 'Extracted text (%d words) — click to expand', 'mediapilot-ai'), $wordCount ) );
        } else {
            echo esc_html__( 'No OCR text yet — click to expand', 'mediapilot-ai');
        }

        echo '</summary>';
        echo '<div style="margin-top:8px;">';

        if ( '' !== $error ) {
            echo '<p style="color:#d63638;margin:0 0 8px;">'
                . esc_html__( 'Last error:', 'mediapilot-ai') . ' '
                . esc_html( $error )
                . '</p>';
        }

        if ( '' !== $text ) {
            echo '<textarea readonly rows="10" style="width:100%;font-family:monospace;font-size:12px;resize:vertical;">'
                . esc_textarea( $text )
                . '</textarea>';
        } else {
            echo '<p style="color:#757575;margin:0;">'
                . esc_html__( 'No text has been extracted yet. OCR runs automatically on upload when a provider is configured under Media › MediaPilot AI Settings.', 'mediapilot-ai')
                . '</p>';
        }

        if ( '' !== $at || '' !== $provider ) {
            echo '<p style="color:#757575;font-size:11px;margin:6px 0 0;">';
            if ( '' !== $provider ) {
                /* translators: %s: provider name, e.g. "aws" or "google" */
                echo esc_html( sprintf( __( 'Provider: %s', 'mediapilot-ai'), strtoupper( $provider ) ) );
            }
            if ( '' !== $at ) {
                if ( '' !== $provider ) {
                    echo ' &nbsp;|&nbsp; ';
                }
                /* translators: %s: ISO 8601 timestamp */
                echo esc_html( sprintf( __( 'Last run: %s', 'mediapilot-ai'), $at ) );
            }
            echo '</p>';
        }

        echo '</div>';
        echo '</details>';
    }

    // -------------------------------------------------------------------------
    // Batch OCR (used by WP-CLI)
    // -------------------------------------------------------------------------

    /**
     * Run OCR across all image attachments, optionally scoped to a folder.
     *
     * @param  int $folderId  0 = all folders; positive = restrict to this folder.
     * @return array{processed: int, success: int, errors: int}
     */
    public function ocrAll( int $folderId = 0 ): array {
        $args = [
            'post_type'      => 'attachment',
            'post_status'    => 'inherit',
            'post_mime_type' => 'image',
            'posts_per_page' => -1,
            'fields'         => 'ids',
            'orderby'        => 'ID',
            'order'          => 'ASC',
        ];

        if ( $folderId > 0 ) {
            $args['tax_query'] = [ // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_tax_query
                [
                    'taxonomy' => FolderTaxonomy::TAXONOMY,
                    'field'    => 'term_id',
                    'terms'    => [ $folderId ],
                ],
            ];
        }

        $ids = get_posts( $args );

        $processed = 0;
        $success   = 0;
        $errors    = 0;

        foreach ( $ids as $id ) {
            $processed++;
            try {
                $this->runOcr( (int) $id );
                $success++;
            } catch ( \RuntimeException ) {
                $errors++;
            }
        }

        return compact( 'processed', 'success', 'errors' );
    }

    // -------------------------------------------------------------------------
    // Settings
    // -------------------------------------------------------------------------

    /**
     * Return the merged AI settings array (provider, keys, region, etc.).
     *
     * Delegates to the same option that AiTaggingService uses so there is a
     * single source of truth for provider configuration.
     *
     * @return array<string, mixed>
     */
    public function getSettings(): array {
        $saved = get_option( AiSettingsPage::OPTION_NAME, [] );
        return array_merge( AiSettingsPage::defaults(), is_array( $saved ) ? $saved : [] );
    }

    // -------------------------------------------------------------------------
    // Private — AWS Textract (Signature V4)
    // -------------------------------------------------------------------------

    /**
     * Extract text from an image URL using AWS Textract DetectDocumentText.
     *
     * Downloads the image bytes locally, base64-encodes them, and sends a
     * Signature V4-signed POST to the regional Textract endpoint.
     *
     * @param  string               $imageUrl  Publicly accessible URL of the image.
     * @param  array<string, mixed> $settings  AI settings array (keys, region).
     * @return string                          Extracted text lines joined with newlines.
     * @throws \RuntimeException               On download failure, size limit breach, or API error.
     */
    private function runAwsTextract( string $imageUrl, array $settings ): string {
        $accessKey = trim( (string) ( $settings['aws_access_key'] ?? '' ) );
        $secretKey = trim( (string) ( $settings['aws_secret_key'] ?? '' ) );
        $region    = trim( (string) ( $settings['aws_region']     ?? 'us-east-1' ) );

        if ( '' === $accessKey || '' === $secretKey || '' === $region ) {
            throw new \RuntimeException( 'AWS credentials or region are not configured.' ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped
        }

        // Download image bytes.
        $dlResponse = wp_remote_get( $imageUrl, [ 'timeout' => 30, 'sslverify' => true ] );

        if ( is_wp_error( $dlResponse ) ) {
            throw new \RuntimeException( 'Image download failed: ' . $dlResponse->get_error_message() ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped
        }

        $imageBytes = (string) wp_remote_retrieve_body( $dlResponse );

        if ( strlen( $imageBytes ) > self::TEXTRACT_MAX_BYTES ) {
            throw new \RuntimeException( // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped
                'Image exceeds the 10 MB AWS Textract bytes limit.'
            );
        }

        $payload = (string) wp_json_encode( [
            'Document' => [ 'Bytes' => base64_encode( $imageBytes ) ],
        ] );

        $decoded = $this->callTextract( $payload, $accessKey, $secretKey, $region );

        // Collect LINE blocks only — words and page blocks are redundant.
        $lines = [];
        foreach ( $decoded['Blocks'] ?? [] as $block ) {
            if ( ( $block['BlockType'] ?? '' ) === 'LINE' && isset( $block['Text'] ) ) {
                $lines[] = (string) $block['Text'];
            }
        }

        return implode( "\n", $lines );
    }

    /**
     * Send a Signature V4-signed POST request to AWS Textract and return the
     * decoded JSON response body.
     *
     * The signing logic mirrors AwsRekognitionAdapter::callRekognition() but
     * targets the `textract` service and the `Textract.DetectDocumentText` action.
     *
     * @param  string               $payload    JSON-encoded request body.
     * @param  string               $accessKey  AWS IAM access key ID.
     * @param  string               $secretKey  AWS IAM secret access key.
     * @param  string               $region     AWS region (e.g. "us-east-1").
     * @return array<string, mixed>             Decoded Textract JSON response.
     * @throws \RuntimeException                On HTTP or JSON errors.
     */
    private function callTextract(
        string $payload,
        string $accessKey,
        string $secretKey,
        string $region
    ): array {
        $host      = self::AWS_SERVICE . ".{$region}.amazonaws.com";
        $endpoint  = "https://{$host}/";
        $amzDate   = gmdate( 'Ymd\THis\Z' );
        $dateStamp = gmdate( 'Ymd' );

        $headers = [
            'Content-Type'  => 'application/x-amz-json-1.1',
            'Host'          => $host,
            'X-Amz-Date'   => $amzDate,
            'X-Amz-Target' => self::AWS_TARGET,
        ];

        // Build canonical request.
        ksort( $headers );
        $canonicalHeaders  = '';
        $signedHeaderKeys   = [];

        foreach ( $headers as $key => $value ) {
            $lk                = strtolower( $key );
            $canonicalHeaders .= $lk . ':' . trim( $value ) . "\n";
            $signedHeaderKeys[] = $lk;
        }

        $signedHeadersStr = implode( ';', $signedHeaderKeys );
        $payloadHash      = hash( 'sha256', $payload );

        $canonicalRequest = implode( "\n", [
            'POST',
            '/',
            '', // no query string
            $canonicalHeaders,
            $signedHeadersStr,
            $payloadHash,
        ] );

        // String to sign.
        $scope        = "{$dateStamp}/{$region}/" . self::AWS_SERVICE . '/aws4_request';
        $stringToSign = implode( "\n", [
            'AWS4-HMAC-SHA256',
            $amzDate,
            $scope,
            hash( 'sha256', $canonicalRequest ),
        ] );

        // Derive signing key using the HMAC key-derivation chain.
        $signingKey = $this->hmacRaw(
            $this->hmacRaw(
                $this->hmacRaw(
                    $this->hmacRaw( 'AWS4' . $secretKey, $dateStamp ),
                    $region
                ),
                self::AWS_SERVICE
            ),
            'aws4_request'
        );

        $signature     = hash_hmac( 'sha256', $stringToSign, $signingKey );
        $authorization = "AWS4-HMAC-SHA256 Credential={$accessKey}/{$scope}, SignedHeaders={$signedHeadersStr}, Signature={$signature}";

        $headers['Authorization'] = $authorization;
        unset( $headers['Host'] ); // WP adds Host automatically.

        $wpResponse = wp_remote_post( $endpoint, [
            'timeout' => 30,
            'headers' => $headers,
            'body'    => $payload,
        ] );

        if ( is_wp_error( $wpResponse ) ) {
            throw new \RuntimeException( 'Textract API error: ' . $wpResponse->get_error_message() ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped
        }

        $code     = (int) wp_remote_retrieve_response_code( $wpResponse );
        $respBody = (string) wp_remote_retrieve_body( $wpResponse );

        if ( $code !== 200 ) {
            throw new \RuntimeException( "Textract returned HTTP {$code}: {$respBody}" ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped
        }

        $decoded = json_decode( $respBody, true );

        if ( ! is_array( $decoded ) ) {
            throw new \RuntimeException( 'Textract returned invalid JSON.' ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped
        }

        return $decoded;
    }

    /**
     * Compute HMAC-SHA256 and return the raw binary digest.
     *
     * @param  string $key   The HMAC key (may be raw binary).
     * @param  string $data  The data to sign.
     * @return string        Raw binary HMAC digest.
     */
    private function hmacRaw( string $key, string $data ): string {
        return hash_hmac( 'sha256', $data, $key, true );
    }

    // -------------------------------------------------------------------------
    // Private — Google Cloud Vision OCR
    // -------------------------------------------------------------------------

    /**
     * Extract text from an image URL using Google Cloud Vision
     * DOCUMENT_TEXT_DETECTION.
     *
     * The image is passed by URL (sourceUri) — no download or base64 needed.
     * The full plain-text result is returned from `fullTextAnnotation.text`.
     *
     * @param  string               $imageUrl  Publicly accessible URL of the image.
     * @param  array<string, mixed> $settings  AI settings array (google_api_key).
     * @return string                          Extracted text (may be empty string).
     * @throws \RuntimeException               On missing API key or API error.
     */
    private function runGoogleVisionOcr( string $imageUrl, array $settings ): string {
        $apiKey = trim( (string) ( $settings['google_api_key'] ?? '' ) );

        if ( '' === $apiKey ) {
            throw new \RuntimeException( 'Google API key is not configured.' ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped
        }

        $payload = (string) wp_json_encode( [
            'requests' => [
                [
                    'image'    => [ 'source' => [ 'imageUri' => $imageUrl ] ],
                    'features' => [ [ 'type' => 'DOCUMENT_TEXT_DETECTION' ] ],
                ],
            ],
        ] );

        $url = add_query_arg( 'key', $apiKey, self::GOOGLE_ENDPOINT );

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

        // Surface any API-level error embedded inside the 200 response.
        if ( isset( $decoded['responses'][0]['error']['message'] ) ) {
            throw new \RuntimeException( // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped
                'Google Vision error: ' . (string) $decoded['responses'][0]['error']['message']  // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- exception message, not HTML output
            );
        }

        // fullTextAnnotation.text is a single pre-formatted string.
        return (string) ( $decoded['responses'][0]['fullTextAnnotation']['text'] ?? '' );
    }
}
