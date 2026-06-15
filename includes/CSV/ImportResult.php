<?php

declare(strict_types=1);

namespace MediaPilotAI\CSV;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}


/**
 * Value object returned by CsvImporter methods.
 *
 * Carries three counters and an optional messages array so callers can
 * display a human-readable summary without coupling to the importer itself.
 *
 * @package MediaPilotAI\CSV
 * @since   1.0.0
 */
final class ImportResult {

    /**
     * @param int      $success   Number of rows processed successfully.
     * @param int      $skipped   Number of rows skipped (non-fatal).
     * @param int      $errors    Number of rows that caused errors.
     * @param string[] $messages  Human-readable messages (warnings / errors).
     */
    public function __construct(
        public readonly int   $success,
        public readonly int   $skipped,
        public readonly int   $errors,
        public readonly array $messages = [],
    ) {}

    /**
     * Returns true when no errors occurred.
     */
    public function isOk(): bool {
        return 0 === $this->errors;
    }

    /**
     * Serialises the result to an associative array suitable for JSON output.
     *
     * @return array<string, mixed>
     */
    public function toArray(): array {
        return [
            'success'  => $this->success,
            'skipped'  => $this->skipped,
            'errors'   => $this->errors,
            'messages' => $this->messages,
        ];
    }
}
