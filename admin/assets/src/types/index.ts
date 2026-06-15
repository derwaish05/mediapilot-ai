// ---------------------------------------------------------------------------
// Folder node shape — matches FolderRepository::getTree() output
// ---------------------------------------------------------------------------
export interface MediaPilotFolder {
  id: number
  name: string
  slug: string
  parent: number
  color: string       // hex color e.g. '#3b82f6'
  count: number       // direct file count (not including children)
  children: MediaPilotFolder[]
}

// ---------------------------------------------------------------------------
// User preferences — stored in wp_mdpai_user_prefs
// ---------------------------------------------------------------------------
export interface MediaPilotUserPrefs {
  folder_id: number | null  // last active folder (null = All Files)
  sort_files: 'name' | 'date' | 'modified' | 'author' | 'size'
  sort_dir: 'asc' | 'desc'
  sidebar_w: number         // sidebar width in pixels
  ui_theme: 'default' | 'win11' | 'dropbox'
}

// ---------------------------------------------------------------------------
// REST API response wrappers
// ---------------------------------------------------------------------------
export interface MediaPilotApiResponse<T> {
  success: boolean
  data: T
  message?: string
}

// ---------------------------------------------------------------------------
// WP localized data — passed via wp_localize_script as window.MediaPilotConfig
// ---------------------------------------------------------------------------
export interface MediaPilotConfig {
  restUrl: string         // e.g. 'https://site.com/wp-json/mediapilot/v1'
  nonce: string           // WP REST nonce (X-WP-Nonce header)
  userId: number
  isAdmin: boolean        // true when current user has manage_options cap
  folderMode: 'global' | 'per_user'
  postType?: string       // set on CPT list screens (edit.php); absent on upload.php
  initialTree: MediaPilotFolder[]
  userPrefs: MediaPilotUserPrefs
  licenceTier: 'free' | 'pro' | 'business' | 'agency'
}

// ---------------------------------------------------------------------------
// Permissions — per-folder role/user access rules
// ---------------------------------------------------------------------------

export type MediaPilotPermissionEntity = 'role' | 'user'

export type MediaPilotPresetName = 'designer' | 'editor' | 'publisher' | 'viewer' | 'none'

export interface MediaPilotPermissionRule {
  id: number
  folder_id: number
  entity: MediaPilotPermissionEntity
  entity_id: string        // role slug or user ID (string)
  can_read: boolean
  can_write: boolean
  can_delete: boolean
  display_name?: string    // decorated by REST endpoint
}

export interface MediaPilotPresetDefinition {
  can_read: boolean
  can_write: boolean
  can_delete: boolean
}

// ---------------------------------------------------------------------------
// Smart Tags / Labels
// ---------------------------------------------------------------------------

export interface MediaPilotTag {
  id: number
  name: string
  slug: string
  color: string         // hex e.g. '#3b82f6'
  usage_count?: number  // decorated by REST endpoint (GET /tags only)
}

export type MediaPilotSmartConditionType = 'tag' | 'mime' | 'date_after' | 'date_before'

export interface MediaPilotSmartCondition {
  type: MediaPilotSmartConditionType
  tag_id?: number   // present when type === 'tag'
  mime?: string     // present when type === 'mime'
  date?: string     // present when type === 'date_after' | 'date_before'
}

export interface MediaPilotSmartRules {
  mode: 'AND' | 'OR'
  conditions: MediaPilotSmartCondition[]
}

// ---------------------------------------------------------------------------
// Utility / union types
// ---------------------------------------------------------------------------

/** Fields by which media items can be sorted */
export type MediaPilotSortField = 'name' | 'date' | 'modified' | 'author' | 'size'

/** Sort direction */
export type MediaPilotSortDir = 'asc' | 'desc'

/** Bulk actions available on selected media items */
export type MediaPilotBulkAction = 'move' | 'delete' | 'download'

// ---------------------------------------------------------------------------
// Global augmentation — WordPress localizes data onto window.MediaPilotConfig
// ---------------------------------------------------------------------------
declare global {
  interface Window {
    MediaPilotConfig: MediaPilotConfig
  }
}
