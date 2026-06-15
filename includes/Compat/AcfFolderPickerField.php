<?php

declare(strict_types=1);

namespace MediaPilotAI\Compat;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}


use MediaPilotAI\Folder\FolderRepository;

/**
 * ACF Field Type — Folder Picker (S42).
 *
 * Registers a custom ACF field type "MediaPilot Folder Picker" that renders a
 * hierarchical folder select control. The saved value is the MediaPilot folder
 * term ID (integer).
 *
 * Usage in ACF field group:
 *   Field Type → MediaPilot Folder Picker
 *   Returns the selected folder ID as an integer.
 *
 * Registration: `register()` is called on `init` (after ACF loads its field
 * types) from Plugin::registerServices() — bails silently if ACF is not active.
 *
 * @package MediaPilotAI\Compat
 * @since   1.0.0
 */
class AcfFolderPickerField extends \acf_field {

    // -------------------------------------------------------------------------
    // Field identity
    // -------------------------------------------------------------------------

    public function __construct(
        private readonly FolderRepository $folderRepository,
    ) {
        $this->name     = 'mdpai_folder_picker';
        $this->label    = __( 'MediaPilot Folder Picker', 'mediapilot-ai');
        $this->category = __( 'Choice', 'acf' ); // phpcs:ignore WordPress.WP.I18n.TextDomainMismatch -- ACF-provided string uses 'acf' domain intentionally

        $this->defaults = [
            'allow_none' => 1,
            'return_format' => 'id',
        ];

        parent::__construct();
    }

    // -------------------------------------------------------------------------
    // Field Settings UI
    // -------------------------------------------------------------------------

    /**
     * Renders the field settings that appear on the ACF field group edit screen.
     *
     * @param  array<string, mixed> $field
     */
    public function render_field_settings( $field ): void {
        acf_render_field_setting( $field, [
            'label'        => __( 'Allow None', 'mediapilot-ai'),
            'instructions' => __( 'Allow users to select "None" (saves 0).', 'mediapilot-ai'),
            'type'         => 'true_false',
            'name'         => 'allow_none',
            'ui'           => 1,
        ] );

        acf_render_field_setting( $field, [
            'label'        => __( 'Return Format', 'mediapilot-ai'),
            'instructions' => __( 'Specify the value returned by get_field().', 'mediapilot-ai'),
            'type'         => 'radio',
            'name'         => 'return_format',
            'choices'      => [
                'id'   => __( 'Folder ID (integer)', 'mediapilot-ai'),
                'name' => __( 'Folder Name (string)', 'mediapilot-ai'),
            ],
            'layout'       => 'horizontal',
        ] );
    }

    // -------------------------------------------------------------------------
    // Field Input (edit screen)
    // -------------------------------------------------------------------------

    /**
     * Renders the select control on the post/user/options edit screen.
     *
     * @param  array<string, mixed> $field
     */
    public function render_field( $field ): void {
        $value   = (int) ( $field['value'] ?? 0 );
        $allowNone = ! empty( $field['allow_none'] );

        $name = esc_attr( (string) $field['name'] );
        $id   = esc_attr( (string) $field['id'] );

        echo '<select id="' . $id . '" name="' . $name . '" class="acf-mediapilot-folder-picker">'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- $id and $name are esc_attr() escaped above

        if ( $allowNone ) {
            echo '<option value="0"' . selected( $value, 0, false ) . '>' // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- selected() is a trusted WP function
                . esc_html__( '— None —', 'mediapilot-ai')
                . '</option>';
        }

        $this->renderOptions( $this->folderRepository->getTree( 0 ), $value, 0 );

        echo '</select>';
    }

    // -------------------------------------------------------------------------
    // Value handling
    // -------------------------------------------------------------------------

    /**
     * Sanitize and save the submitted value.
     *
     * @param  mixed                $value
     * @param  int|string           $postId
     * @param  array<string, mixed> $field
     * @return int
     */
    public function update_value( $value, $postId, $field ) {
        return absint( $value );
    }

    /**
     * Format the stored value for `get_field()`.
     *
     * @param  mixed                $value
     * @param  int|string           $postId
     * @param  array<string, mixed> $field
     * @return int|string
     */
    public function format_value( $value, $postId, $field ) {
        $folderId = absint( $value );

        if ( ( $field['return_format'] ?? 'id' ) === 'name' ) {
            $folder = $this->folderRepository->getById( $folderId );
            return $folder ? (string) ( $folder['name'] ?? '' ) : '';
        }

        return $folderId;
    }

    // -------------------------------------------------------------------------
    // Static registration
    // -------------------------------------------------------------------------

    /**
     * Register this field type with ACF.
     * Called on `acf/include_field_types` or `init` after ACF has loaded.
     */
    public static function register( FolderRepository $folderRepository ): void {
        if ( ! class_exists( 'acf_field' ) ) {
            return;
        }

        new self( $folderRepository );
    }

    // -------------------------------------------------------------------------
    // Private helpers
    // -------------------------------------------------------------------------

    /**
     * Recursively output <option> elements for the folder tree.
     *
     * @param array<int, array<string, mixed>> $nodes
     * @param int                              $selected
     * @param int                              $depth
     */
    private function renderOptions( array $nodes, int $selected, int $depth ): void {
        foreach ( $nodes as $node ) {
            $id    = (int)    ( $node['id']   ?? 0 );
            $name  = (string) ( $node['name'] ?? '' );
            $label = str_repeat( '— ', $depth ) . $name;

            echo '<option value="' . esc_attr( (string) $id ) . '"'
                . selected( $selected, $id, false ) . '>'
                . esc_html( $label )
                . '</option>';

            if ( ! empty( $node['children'] ) && is_array( $node['children'] ) ) {
                $this->renderOptions( $node['children'], $selected, $depth + 1 );
            }
        }
    }
}
