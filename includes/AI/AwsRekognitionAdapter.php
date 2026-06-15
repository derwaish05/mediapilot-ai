<?php

declare(strict_types=1);

namespace MediaPilotAI\AI;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}


/**
 * AWS Rekognition adapter for AI image labelling (S47).
 *
 * Uses the DetectLabels action with base64-encoded image bytes.
 * Images larger than 5 MB are rejected — this is an AWS API hard limit.
 *
 * Required settings (from mdpai_ai_settings):
 *   aws_access_key  string  IAM access key ID.
 *   aws_secret_key  string  IAM secret access key.
 *   aws_region      string  AWS region, e.g. "us-east-1".
 *
 * @package MediaPilotAI\AI
 * @since   1.0.0
 */
class AwsRekognitionAdapter implements AiTaggingAdapter {

    private const SERVICE   = 'rekognition';
    private const MAX_BYTES = 5_242_880; // 5 MB — Rekognition bytes limit

    private string $accessKey;
    private string $secretKey;
    private string $region;

    /** @param array<string, mixed> $settings */
    public function __construct( array $settings ) {
        $this->accessKey = trim( (string) ( $settings['aws_access_key'] ?? '' ) );
        $this->secretKey = trim( (string) ( $settings['aws_secret_key'] ?? '' ) );
        $this->region    = trim( (string) ( $settings['aws_region']     ?? 'us-east-1' ) );
    }

    public function isConfigured(): bool {
        return '' !== $this->accessKey && '' !== $this->secretKey && '' !== $this->region;
    }

    /**
     * @return array<int, array{label: string, confidence: float}>
     * @throws \RuntimeException
     */
    public function analyzeImage( string $imageUrl, int $attachmentId ): array {
        // Download image bytes.
        $response = wp_remote_get( $imageUrl, [ 'timeout' => 30, 'sslverify' => true ] );

        if ( is_wp_error( $response ) ) {
            throw new \RuntimeException( 'Image download failed: ' . $response->get_error_message() ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped
        }

        $body = (string) wp_remote_retrieve_body( $response );

        if ( strlen( $body ) > self::MAX_BYTES ) {
            throw new \RuntimeException( // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped
                "Attachment {$attachmentId} exceeds the 5 MB Rekognition bytes limit."  // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- exception message, not HTML output
            );
        }

        $payload = (string) wp_json_encode( [
            'Image'         => [ 'Bytes' => base64_encode( $body ) ],
            'MaxLabels'     => 50,
            'MinConfidence' => 0, // filtered client-side by confidence_threshold
        ] );

        $decoded = $this->callRekognition( 'RekognitionService.DetectLabels', $payload );

        $labels = [];
        foreach ( $decoded['Labels'] ?? [] as $item ) {
            $labels[] = [
                'label'      => (string) ( $item['Name']       ?? '' ),
                'confidence' => (float)  ( $item['Confidence'] ?? 0.0 ),
            ];
        }

        return $labels;
    }

    // -------------------------------------------------------------------------
    // Private — AWS Signature V4 request signing
    // -------------------------------------------------------------------------

    /**
     * Send a signed POST request to the Rekognition endpoint and decode the JSON response.
     *
     * @return array<string, mixed>
     * @throws \RuntimeException
     */
    private function callRekognition( string $target, string $payload ): array {
        $host      = self::SERVICE . ".{$this->region}.amazonaws.com";
        $endpoint  = "https://{$host}/";
        $amzDate   = gmdate( 'Ymd\THis\Z' );
        $dateStamp = gmdate( 'Ymd' );

        $headers = [
            'Content-Type'  => 'application/x-amz-json-1.1',
            'Host'          => $host,
            'X-Amz-Date'   => $amzDate,
            'X-Amz-Target' => $target,
        ];

        // Build canonical request.
        ksort( $headers );
        $canonicalHeaders = '';
        $signedHeaderKeys  = [];

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
        $scope        = "{$dateStamp}/{$this->region}/" . self::SERVICE . '/aws4_request';
        $stringToSign = implode( "\n", [
            'AWS4-HMAC-SHA256',
            $amzDate,
            $scope,
            hash( 'sha256', $canonicalRequest ),
        ] );

        // Derive signing key.
        $signingKey = $this->hmacRaw(
            $this->hmacRaw(
                $this->hmacRaw(
                    $this->hmacRaw( 'AWS4' . $this->secretKey, $dateStamp ),
                    $this->region
                ),
                self::SERVICE
            ),
            'aws4_request'
        );

        $signature     = hash_hmac( 'sha256', $stringToSign, $signingKey );
        $authorization = "AWS4-HMAC-SHA256 Credential={$this->accessKey}/{$scope}, SignedHeaders={$signedHeadersStr}, Signature={$signature}";

        $headers['Authorization'] = $authorization;
        unset( $headers['Host'] ); // WP adds Host automatically

        $wpResponse = wp_remote_post( $endpoint, [
            'timeout' => 30,
            'headers' => $headers,
            'body'    => $payload,
        ] );

        if ( is_wp_error( $wpResponse ) ) {
            throw new \RuntimeException( 'Rekognition API error: ' . $wpResponse->get_error_message() ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped
        }

        $code     = (int) wp_remote_retrieve_response_code( $wpResponse );
        $respBody = (string) wp_remote_retrieve_body( $wpResponse );

        if ( $code !== 200 ) {
            throw new \RuntimeException( "Rekognition returned HTTP {$code}: {$respBody}" ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped
        }

        $decoded = json_decode( $respBody, true );

        if ( ! is_array( $decoded ) ) {
            throw new \RuntimeException( 'Rekognition returned invalid JSON.' ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped
        }

        return $decoded;
    }

    /** HMAC-SHA256 returning raw bytes. */
    private function hmacRaw( string $key, string $data ): string {
        return hash_hmac( 'sha256', $data, $key, true );
    }
}
