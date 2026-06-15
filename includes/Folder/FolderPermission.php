<?php

declare(strict_types=1);

namespace MediaPilotAI\Folder;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}


/**
 * Role-based and user-based permission system for MediaPilot folders.
 *
 * Permission resolution order (highest priority first):
 *   1. Administrator — always passes all checks.
 *   2. Explicit user rule  — row with entity='user', entity_id=<user_id>.
 *   3. Explicit role rule  — row with entity='role', entity_id=<role_slug>.
 *      (If the user has multiple roles, the most permissive rule wins.)
 *   4. Parent inheritance  — same resolution applied to the parent folder.
 *   5. WP capability fallback:
 *        canRead   → upload_files
 *        canWrite  → manage_mdpai_folders
 *        canDelete → manage_mdpai_folders
 *
 * Client isolation:
 *   When a folder has at least one explicit permission row, non-admin users
 *   must have an explicit read grant to see it in the tree. Folders with no
 *   rows at all are visible to everyone (open-by-default).
 *
 * Agency workflow presets:
 *   designer  — read=1, write=1, delete=0
 *   editor    — read=1, write=1, delete=0  (semantic alias)
 *   publisher — read=1, write=1, delete=1
 *
 * @package MediaPilotAI\Folder
 * @since   1.0.0
 */
class FolderPermission {

    private const PRESETS = [
        'designer'  => [ 'can_read' => true,  'can_write' => true,  'can_delete' => false ],
        'editor'    => [ 'can_read' => true,  'can_write' => true,  'can_delete' => false ],
        'publisher' => [ 'can_read' => true,  'can_write' => true,  'can_delete' => true  ],
        'viewer'    => [ 'can_read' => true,  'can_write' => false, 'can_delete' => false ],
        'none'      => [ 'can_read' => false, 'can_write' => false, 'can_delete' => false ],
    ];

    /** Depth limit for parent-chain traversal (guards against bad DB data). */
    private const MAX_DEPTH = 20;

    public function __construct(
        private readonly PermissionRepository $permRepo,
        private readonly FolderRepository     $folderRepo,
    ) {}

    // -------------------------------------------------------------------------
    // Registration
    // -------------------------------------------------------------------------

    /**
     * Hook into mdpai_folder_tree to prune unreadable folders before they reach
     * any caller (REST, React, etc.).
     */
    public function register(): void {
        add_filter( 'mdpai_folder_tree', [ $this, 'filterTree' ], 20, 2 );
        // Clean up permission rows when a folder is deleted.
        add_action( 'mdpai_after_folder_delete', [ $this, 'onFolderDelete' ] );
    }

    // -------------------------------------------------------------------------
    // Core permission checks
    // -------------------------------------------------------------------------

    public function canRead( int $folderId, int $userId ): bool {
        return $this->check( 'can_read', $folderId, $userId );
    }

    public function canWrite( int $folderId, int $userId ): bool {
        return $this->check( 'can_write', $folderId, $userId );
    }

    public function canDelete( int $folderId, int $userId ): bool {
        return $this->check( 'can_delete', $folderId, $userId );
    }

    // -------------------------------------------------------------------------
    // Tree filter — called via mdpai_folder_tree hook
    // -------------------------------------------------------------------------

    /**
     * Recursively removes folders the current user cannot read.
     * Called via the `mdpai_folder_tree` filter.
     *
     * @param  array<int, array<string, mixed>> $tree
     * @param  int                              $userId
     * @return array<int, array<string, mixed>>
     */
    public function filterTree( array $tree, int $userId ): array {
        // Admins see everything.
        if ( $this->isAdmin( $userId ) ) {
            return $tree;
        }

        return $this->pruneTree( $tree, $userId );
    }

    // -------------------------------------------------------------------------
    // Preset helpers
    // -------------------------------------------------------------------------

    /**
     * Returns the permission bitmask for a named preset, or null if unknown.
     *
     * @return array{can_read: bool, can_write: bool, can_delete: bool}|null
     */
    public function getPreset( string $preset ): ?array {
        return self::PRESETS[ $preset ] ?? null;
    }

    /**
     * Returns all available preset names.
     *
     * @return string[]
     */
    public function getPresetNames(): array {
        return array_keys( self::PRESETS );
    }

    /**
     * Applies a named preset to a given role on a given folder.
     *
     * @throws \InvalidArgumentException On unknown preset.
     */
    public function applyPreset( int $folderId, string $roleName, string $preset ): bool {
        $p = $this->getPreset( $preset );

        if ( null === $p ) {
            throw new \InvalidArgumentException( "Unknown MediaPilot permission preset: {$preset}" ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped
        }

        return $this->permRepo->upsert(
            $folderId,
            'role',
            $roleName,
            $p['can_read'],
            $p['can_write'],
            $p['can_delete']
        );
    }

    // -------------------------------------------------------------------------
    // Hook callbacks
    // -------------------------------------------------------------------------

    /**
     * Deletes all permission rows for a folder when it is deleted.
     */
    public function onFolderDelete( int $termId ): void {
        $this->permRepo->deleteAllForFolder( $termId );
    }

    // -------------------------------------------------------------------------
    // Private — resolution engine
    // -------------------------------------------------------------------------

    /**
     * Resolves a single permission bit for a user on a folder.
     *
     * @param  'can_read'|'can_write'|'can_delete' $bit
     */
    private function check( string $bit, int $folderId, int $userId, int $depth = 0 ): bool {
        // 1. Admin bypass.
        if ( $this->isAdmin( $userId ) ) {
            return true;
        }

        /**
         * Filters the result of an MediaPilot folder permission check.
         *
         * Return a boolean to short-circuit the built-in rule resolution.
         * Return null to let MediaPilot resolve the permission normally.
         *
         * @param bool|null $result   null = use default logic; true/false = override.
         * @param string    $bit      Permission bit: 'can_read', 'can_write', or 'can_delete'.
         * @param int       $folderId Folder term ID being checked.
         * @param int       $userId   User ID being checked.
         */
        $override = apply_filters( 'mdpai_permission_check', null, $bit, $folderId, $userId );

        if ( null !== $override ) {
            return (bool) $override;
        }

        // 2. Explicit user rule.
        $userRule = $this->permRepo->get( $folderId, 'user', (string) $userId );
        if ( null !== $userRule ) {
            return (bool) $userRule[ $bit ];
        }

        // 3. Role rules — collect user's roles and return most permissive result.
        $userRoles = $this->getUserRoles( $userId );
        foreach ( $userRoles as $role ) {
            $roleRule = $this->permRepo->get( $folderId, 'role', $role );
            if ( null !== $roleRule && (bool) $roleRule[ $bit ] ) {
                return true;
            }
        }
        // If any role rule explicitly denies, check if ALL matching role rules deny.
        $hasAnyRoleRule = false;
        foreach ( $userRoles as $role ) {
            if ( null !== $this->permRepo->get( $folderId, 'role', $role ) ) {
                $hasAnyRoleRule = true;
                break;
            }
        }
        if ( $hasAnyRoleRule ) {
            // A role rule exists but none grant this bit → denied at this level.
            // Still try parent inheritance before giving up.
        }

        // 4. Parent inheritance.
        if ( $depth < self::MAX_DEPTH ) {
            $folder = $this->folderRepo->getById( $folderId );
            if ( null !== $folder && (int) $folder['parent'] > 0 ) {
                return $this->check( $bit, (int) $folder['parent'], $userId, $depth + 1 );
            }
        }

        // 5. WP capability fallback — only applied when no explicit rules exist
        //    anywhere in the ancestor chain.
        return $this->wpCapFallback( $bit, $userId );
    }

    /**
     * WP capability fallback when no explicit permission rules exist.
     *
     * @param  'can_read'|'can_write'|'can_delete' $bit
     */
    private function wpCapFallback( string $bit, int $userId ): bool {
        $user = get_userdata( $userId );

        if ( ! $user instanceof \WP_User ) {
            return false;
        }

        return match ( $bit ) {
            'can_read'   => user_can( $user, 'upload_files' ),
            'can_write'  => user_can( $user, 'manage_mdpai_folders' ),
            'can_delete' => user_can( $user, 'manage_mdpai_folders' ),
            default      => false,
        };
    }

    /**
     * Recursively removes folders from the tree that the user cannot read,
     * while keeping children of readable parent folders even if parent
     * explicitly grants/denies differently.
     *
     * @param  array<int, array<string, mixed>> $tree
     * @return array<int, array<string, mixed>>
     */
    private function pruneTree( array $tree, int $userId ): array {
        $result = [];

        foreach ( $tree as $node ) {
            $folderId = (int) $node['id'];

            if ( ! $this->canRead( $folderId, $userId ) ) {
                // Hidden folder — also hide all children.
                continue;
            }

            // Prune children recursively.
            $node['children'] = $this->pruneTree( $node['children'] ?? [], $userId );
            $result[]         = $node;
        }

        return $result;
    }

    /**
     * Returns true when the user is a WordPress administrator.
     * Admins bypass all per-folder rules.
     */
    private function isAdmin( int $userId ): bool {
        $user = get_userdata( $userId );
        return $user instanceof \WP_User && $user->has_cap( 'administrator' );
    }

    /**
     * Returns the WP role slugs for a user.
     *
     * @return string[]
     */
    private function getUserRoles( int $userId ): array {
        $user = get_userdata( $userId );
        return ( $user instanceof \WP_User ) ? (array) $user->roles : [];
    }
}
