<?php

declare(strict_types=1);

namespace MediaPilotAI\Folder;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}


/**
 * Folder Templates / Presets (S38).
 *
 * Captures a folder subtree as a reusable template, applies templates to
 * create new folder structures, and ships four built-in presets.
 *
 * Storage
 * -------
 * All user-created templates are stored as a JSON array in the wp_options key
 * `mdpai_folder_templates`. Each record:
 *
 *   {
 *     "id":          int,
 *     "name":        string,
 *     "description": string,
 *     "structure":   [ FolderNode, … ],
 *     "created_at":  string (ISO-8601),
 *     "is_preset":   false
 *   }
 *
 * Preset templates are defined in-code and are merged at read time; they
 * carry `"is_preset": true` and cannot be deleted.
 *
 * FolderNode shape
 * ----------------
 *   { "name": string, "color": string, "children": [ FolderNode, … ] }
 *
 * @package MediaPilotAI\Folder
 * @since   1.0.0
 */
class FolderTemplate {

    private const OPTION_KEY  = 'mdpai_folder_templates';

    // -------------------------------------------------------------------------
    // Built-in presets
    // -------------------------------------------------------------------------

    /**
     * Returns the four built-in preset templates.
     *
     * @return array<int, array<string, mixed>>
     */
    public function getPresets(): array {
        return [
            [
                'id'          => -1,
                'name'        => 'Blog',
                'description' => 'Classic blog structure: posts, pages, authors and assets.',
                'is_preset'   => true,
                'created_at'  => '',
                'structure'   => [
                    [ 'name' => 'Posts',   'color' => '#3b82f6', 'children' => [
                        [ 'name' => 'Images',    'color' => '#60a5fa', 'children' => [] ],
                        [ 'name' => 'Videos',    'color' => '#818cf8', 'children' => [] ],
                        [ 'name' => 'Documents', 'color' => '#a78bfa', 'children' => [] ],
                    ]],
                    [ 'name' => 'Pages',   'color' => '#10b981', 'children' => [
                        [ 'name' => 'Hero Images', 'color' => '#34d399', 'children' => [] ],
                    ]],
                    [ 'name' => 'Authors', 'color' => '#f59e0b', 'children' => [
                        [ 'name' => 'Avatars', 'color' => '#fbbf24', 'children' => [] ],
                    ]],
                    [ 'name' => 'Site Assets', 'color' => '#94a3b8', 'children' => [
                        [ 'name' => 'Logos',  'color' => '#cbd5e1', 'children' => [] ],
                        [ 'name' => 'Icons',  'color' => '#cbd5e1', 'children' => [] ],
                        [ 'name' => 'Banners','color' => '#cbd5e1', 'children' => [] ],
                    ]],
                ],
            ],
            [
                'id'          => -2,
                'name'        => 'E-commerce',
                'description' => 'Product images, banners, brand assets and seasonal campaigns.',
                'is_preset'   => true,
                'created_at'  => '',
                'structure'   => [
                    [ 'name' => 'Products', 'color' => '#ef4444', 'children' => [
                        [ 'name' => 'Main Images',    'color' => '#f87171', 'children' => [] ],
                        [ 'name' => 'Gallery Images', 'color' => '#f87171', 'children' => [] ],
                        [ 'name' => 'Thumbnails',     'color' => '#f87171', 'children' => [] ],
                    ]],
                    [ 'name' => 'Categories', 'color' => '#f59e0b', 'children' => [
                        [ 'name' => 'Banners', 'color' => '#fbbf24', 'children' => [] ],
                        [ 'name' => 'Icons',   'color' => '#fbbf24', 'children' => [] ],
                    ]],
                    [ 'name' => 'Campaigns', 'color' => '#8b5cf6', 'children' => [
                        [ 'name' => 'Black Friday', 'color' => '#a78bfa', 'children' => [] ],
                        [ 'name' => 'Summer Sale',  'color' => '#a78bfa', 'children' => [] ],
                    ]],
                    [ 'name' => 'Brand Assets', 'color' => '#0ea5e9', 'children' => [
                        [ 'name' => 'Logos',       'color' => '#38bdf8', 'children' => [] ],
                        [ 'name' => 'Style Guide', 'color' => '#38bdf8', 'children' => [] ],
                    ]],
                ],
            ],
            [
                'id'          => -3,
                'name'        => 'Agency Client',
                'description' => 'Per-client folder isolation with deliverables and source files.',
                'is_preset'   => true,
                'created_at'  => '',
                'structure'   => [
                    [ 'name' => 'Client Name', 'color' => '#06b6d4', 'children' => [
                        [ 'name' => 'Brand',        'color' => '#22d3ee', 'children' => [
                            [ 'name' => 'Logos',    'color' => '#67e8f9', 'children' => [] ],
                            [ 'name' => 'Fonts',    'color' => '#67e8f9', 'children' => [] ],
                            [ 'name' => 'Palettes', 'color' => '#67e8f9', 'children' => [] ],
                        ]],
                        [ 'name' => 'Deliverables', 'color' => '#22d3ee', 'children' => [
                            [ 'name' => 'Social',  'color' => '#67e8f9', 'children' => [] ],
                            [ 'name' => 'Print',   'color' => '#67e8f9', 'children' => [] ],
                            [ 'name' => 'Web',     'color' => '#67e8f9', 'children' => [] ],
                        ]],
                        [ 'name' => 'Source Files', 'color' => '#22d3ee', 'children' => [] ],
                        [ 'name' => 'Feedback',     'color' => '#22d3ee', 'children' => [] ],
                    ]],
                ],
            ],
            [
                'id'          => -4,
                'name'        => 'Portfolio',
                'description' => 'Creative portfolio: projects, case studies and press kit.',
                'is_preset'   => true,
                'created_at'  => '',
                'structure'   => [
                    [ 'name' => 'Projects', 'color' => '#10b981', 'children' => [
                        [ 'name' => 'Photography', 'color' => '#34d399', 'children' => [] ],
                        [ 'name' => 'Design',      'color' => '#34d399', 'children' => [] ],
                        [ 'name' => 'Video',       'color' => '#34d399', 'children' => [] ],
                        [ 'name' => 'Web',         'color' => '#34d399', 'children' => [] ],
                    ]],
                    [ 'name' => 'Case Studies', 'color' => '#8b5cf6', 'children' => [
                        [ 'name' => 'Before & After', 'color' => '#a78bfa', 'children' => [] ],
                        [ 'name' => 'Process',        'color' => '#a78bfa', 'children' => [] ],
                    ]],
                    [ 'name' => 'Press Kit', 'color' => '#f59e0b', 'children' => [
                        [ 'name' => 'Headshots', 'color' => '#fbbf24', 'children' => [] ],
                        [ 'name' => 'Logos',     'color' => '#fbbf24', 'children' => [] ],
                        [ 'name' => 'Press',     'color' => '#fbbf24', 'children' => [] ],
                    ]],
                    [ 'name' => 'About', 'color' => '#94a3b8', 'children' => [] ],
                ],
            ],
        ];
    }

    // -------------------------------------------------------------------------
    // Public API — List & Read
    // -------------------------------------------------------------------------

    /**
     * Returns all templates: presets first, then user-created (newest first).
     *
     * @return list<array<string, mixed>>
     */
    public function listAll(): array {
        return array_merge( $this->getPresets(), $this->loadStored() );
    }

    /**
     * Find a single template by ID. Returns null when not found.
     *
     * Negative IDs refer to presets (-1 → -4).
     *
     * @param  int $id
     * @return array<string, mixed>|null
     */
    public function findById( int $id ): ?array {
        foreach ( $this->listAll() as $tpl ) {
            if ( (int) $tpl['id'] === $id ) {
                return $tpl;
            }
        }
        return null;
    }

    // -------------------------------------------------------------------------
    // Public API — Create (capture from folder)
    // -------------------------------------------------------------------------

    /**
     * Capture the subtree of an existing folder as a new template.
     *
     * @param  int    $folderId     Source folder term ID.
     * @param  string $name         Template name.
     * @param  string $description  Optional description.
     * @return int  New template ID.
     * @throws \InvalidArgumentException When the folder is not found.
     */
    public function captureFromFolder( int $folderId, string $name, string $description = '' ): int {
        $name        = sanitize_text_field( $name );
        $description = sanitize_textarea_field( $description );

        if ( '' === $name ) {
            throw new \InvalidArgumentException( __( 'Template name must not be empty.', 'mediapilot-ai') ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped
        }

        // Build structure from the folder's subtree.
        $structure = $this->captureSubtree( $folderId );

        return $this->saveTemplate( $name, $description, $structure );
    }

    // -------------------------------------------------------------------------
    // Public API — Apply
    // -------------------------------------------------------------------------

    /**
     * Apply a template: recreate its folder structure under $targetFolderId.
     *
     * If $targetFolderId is 0 the folders are created at the root level.
     *
     * @param  int $templateId     Template ID (positive = stored, negative = preset).
     * @param  int $targetFolderId Existing folder to nest under (0 = root).
     * @param  int $userId         0 = global.
     * @return array{ created: int, folders: list<string> }
     * @throws \InvalidArgumentException When the template is not found.
     */
    public function applyToFolder(
        int $templateId,
        int $targetFolderId,
        int $userId = 0
    ): array {
        $template = $this->findById( $templateId );

        if ( null === $template ) {
            throw new \InvalidArgumentException( "Template #{$templateId} not found." ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped
        }

        $created = 0;
        $folders = [];

        $this->createStructure(
            (array) $template['structure'],
            $targetFolderId,
            $userId,
            $created,
            $folders
        );

        return [ 'created' => $created, 'folders' => $folders ];
    }

    // -------------------------------------------------------------------------
    // Public API — Delete
    // -------------------------------------------------------------------------

    /**
     * Delete a user-created template by ID.
     * Presets (negative IDs) cannot be deleted.
     *
     * @param  int $id
     * @return bool  True on success, false when not found or is a preset.
     */
    public function delete( int $id ): bool {
        if ( $id <= 0 ) {
            return false; // Presets are read-only.
        }

        $stored  = $this->loadStored();
        $updated = array_values( array_filter( $stored, fn( $t ) => (int) $t['id'] !== $id ) );

        if ( count( $updated ) === count( $stored ) ) {
            return false; // Not found.
        }

        update_option( self::OPTION_KEY, $updated, false );
        return true;
    }

    // -------------------------------------------------------------------------
    // Public API — Import / Export
    // -------------------------------------------------------------------------

    /**
     * Export a template as a JSON string.
     *
     * The export payload strips the internal ID so it can be imported on
     * another site without conflict.
     *
     * @param  int $id  Template ID.
     * @return string  JSON string.
     * @throws \InvalidArgumentException When not found.
     */
    public function exportJson( int $id ): string {
        $template = $this->findById( $id );

        if ( null === $template ) {
            throw new \InvalidArgumentException( "Template #{$id} not found." ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped
        }

        $payload = [
            'mdpai_template_version' => '1.0',
            'name'                 => $template['name'],
            'description'          => $template['description'],
            'structure'            => $template['structure'],
        ];

        return (string) wp_json_encode( $payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE );
    }

    /**
     * Import a template from a JSON string.
     *
     * @param  string $json
     * @return int  New template ID.
     * @throws \InvalidArgumentException On invalid JSON or missing keys.
     */
    public function importJson( string $json ): int {
        $data = json_decode( $json, true );

        if ( ! is_array( $data ) ) {
            throw new \InvalidArgumentException( __( 'Invalid JSON payload.', 'mediapilot-ai') ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped
        }

        if ( empty( $data['name'] ) || ! isset( $data['structure'] ) ) {
            throw new \InvalidArgumentException( __( 'JSON must contain "name" and "structure" keys.', 'mediapilot-ai') ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped
        }

        $name        = sanitize_text_field( (string) $data['name'] );
        $description = sanitize_textarea_field( (string) ( $data['description'] ?? '' ) );
        $structure   = $this->sanitizeStructure( (array) $data['structure'] );

        return $this->saveTemplate( $name, $description, $structure );
    }

    // -------------------------------------------------------------------------
    // Private — storage helpers
    // -------------------------------------------------------------------------

    /**
     * @return list<array<string, mixed>>
     */
    private function loadStored(): array {
        $raw = get_option( self::OPTION_KEY, [] );
        return is_array( $raw ) ? array_values( $raw ) : [];
    }

    /**
     * Persist a new template and return its assigned ID.
     *
     * @param  string                    $name
     * @param  string                    $description
     * @param  list<array<string,mixed>> $structure
     * @return int
     */
    private function saveTemplate( string $name, string $description, array $structure ): int {
        $stored = $this->loadStored();

        // Generate next ID (max of existing + 1, minimum 1).
        $maxId = 0;
        foreach ( $stored as $t ) {
            if ( (int) $t['id'] > $maxId ) {
                $maxId = (int) $t['id'];
            }
        }
        $newId = $maxId + 1;

        $stored[] = [
            'id'          => $newId,
            'name'        => $name,
            'description' => $description,
            'structure'   => $structure,
            'created_at'  => gmdate( 'c' ),
            'is_preset'   => false,
        ];

        update_option( self::OPTION_KEY, $stored, false );

        return $newId;
    }

    // -------------------------------------------------------------------------
    // Private — subtree capture
    // -------------------------------------------------------------------------

    /**
     * Walk the folder taxonomy to capture the subtree of $folderId.
     *
     * @param  int $folderId  Root of the subtree (term ID).
     * @return list<array<string, mixed>>  FolderNode array.
     */
    private function captureSubtree( int $folderId ): array {
        $term = get_term( $folderId, \MediaPilotAI\Taxonomy\FolderTaxonomy::TAXONOMY );

        if ( is_wp_error( $term ) || null === $term ) {
            throw new \InvalidArgumentException( "Folder #{$folderId} not found." ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped
        }

        return $this->buildNodeChildren( $folderId );
    }

    /**
     * Recursively build child nodes for a given parent term ID.
     *
     * @param  int $parentId
     * @return list<array<string, mixed>>
     */
    private function buildNodeChildren( int $parentId ): array {
        $children = get_terms( [
            'taxonomy'   => \MediaPilotAI\Taxonomy\FolderTaxonomy::TAXONOMY,
            'parent'     => $parentId,
            'hide_empty' => false,
            'number'     => 0,
        ] );

        if ( is_wp_error( $children ) || empty( $children ) ) {
            return [];
        }

        $nodes = [];

        foreach ( $children as $child ) {
            $color  = (string) get_term_meta( $child->term_id, 'mdpai_folder_color', true );
            $nodes[] = [
                'name'     => (string) $child->name,
                'color'    => $color ?: '#94a3b8',
                'children' => $this->buildNodeChildren( (int) $child->term_id ),
            ];
        }

        return $nodes;
    }

    // -------------------------------------------------------------------------
    // Private — structure application
    // -------------------------------------------------------------------------

    /**
     * Recursively create folders from a structure array.
     *
     * @param  list<array<string,mixed>> $nodes
     * @param  int                       $parentId  0 = root.
     * @param  int                       $userId    0 = global.
     * @param  int                       &$created  Counter incremented per folder.
     * @param  list<string>              &$folderNames  Names collected for return.
     */
    private function createStructure(
        array $nodes,
        int $parentId,
        int $userId,
        int &$created,
        array &$folderNames
    ): void {
        foreach ( $nodes as $node ) {
            $name     = sanitize_text_field( (string) ( $node['name'] ?? '' ) );
            $color    = sanitize_text_field( (string) ( $node['color'] ?? '#94a3b8' ) );
            $children = isset( $node['children'] ) ? (array) $node['children'] : [];

            if ( '' === $name ) {
                continue;
            }

            // Insert the term directly to avoid FolderService duplicate-name resolution
            // overwriting intentional names from the template.
            $result = wp_insert_term(
                $name,
                \MediaPilotAI\Taxonomy\FolderTaxonomy::TAXONOMY,
                [ 'parent' => $parentId ]
            );

            if ( is_wp_error( $result ) ) {
                // Term with this name may already exist under this parent — fetch it.
                $existing = get_term_by( 'name', $name, \MediaPilotAI\Taxonomy\FolderTaxonomy::TAXONOMY );
                if ( $existing && ! is_wp_error( $existing ) && (int) $existing->parent === $parentId ) {
                    $newId = (int) $existing->term_id;
                } else {
                    continue;
                }
            } else {
                $newId = (int) $result['term_id'];
            }

            // Store color and (optionally) user meta.
            if ( '' !== $color ) {
                update_term_meta( $newId, 'mdpai_folder_color', $color );
            }
            if ( $userId > 0 ) {
                update_term_meta( $newId, 'mdpai_folder_user_id', $userId );
            }

            $created++;
            $folderNames[] = $name;

            if ( ! empty( $children ) ) {
                $this->createStructure( $children, $newId, $userId, $created, $folderNames );
            }
        }
    }

    // -------------------------------------------------------------------------
    // Private — structure sanitizer (for import)
    // -------------------------------------------------------------------------

    /**
     * Recursively sanitize a structure array from untrusted JSON.
     *
     * @param  array<mixed> $nodes
     * @return list<array<string, mixed>>
     */
    private function sanitizeStructure( array $nodes ): array {
        $result = [];

        foreach ( $nodes as $node ) {
            if ( ! is_array( $node ) || empty( $node['name'] ) ) {
                continue;
            }

            $result[] = [
                'name'     => sanitize_text_field( (string) $node['name'] ),
                'color'    => sanitize_text_field( (string) ( $node['color'] ?? '#94a3b8' ) ),
                'children' => isset( $node['children'] ) && is_array( $node['children'] )
                    ? $this->sanitizeStructure( $node['children'] )
                    : [],
            ];
        }

        return $result;
    }
}
