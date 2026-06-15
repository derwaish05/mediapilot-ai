/**
 * uiStore.ts
 *
 * Manages all UI preferences: sidebar dimensions, theme selection, folder
 * expand/collapse state, view mode, and the right-click context menu.
 *
 * Sidebar width and theme changes are debounce-saved to the REST API so the
 * server persists them in wp_mdpai_user_prefs. The debounce timeout ID is kept
 * in a module-level closure — not in Zustand state — to avoid triggering
 * unnecessary re-renders.
 */

import { create } from 'zustand'
import { apiPut } from '@/api/client'
import type { MediaPilotSortField, MediaPilotSortDir } from '@/types'

// ---------------------------------------------------------------------------
// Constants
// ---------------------------------------------------------------------------

const SIDEBAR_MIN = 160
const SIDEBAR_MAX = 480
const PREFS_DEBOUNCE_MS = 500

// ---------------------------------------------------------------------------
// Types
// ---------------------------------------------------------------------------

type ViewMode = 'grid' | 'list'

interface ContextMenuState {
  visible: boolean
  x: number
  y: number
  folderId: number | null
}

interface UiState {
  // Sidebar
  sidebarWidth: number
  sidebarCollapsed: boolean
  expandedFolders: Set<number>

  // View
  viewMode: ViewMode

  // Sort & search (file list)
  sortField: MediaPilotSortField
  sortDir: MediaPilotSortDir
  fileSearchQuery: string

  // Admin folder mode override (per_user mode only)
  adminFolderOverride: boolean

  // Context menu
  contextMenu: ContextMenuState

  // Actions
  setSidebarWidth: (w: number) => void
  toggleSidebar: () => void
  expandFolder: (id: number) => void
  collapseFolder: (id: number) => void
  toggleFolder: (id: number) => void
  expandAll: (ids: number[]) => void
  collapseAll: () => void
  setViewMode: (mode: ViewMode) => void
  setSortField: (field: MediaPilotSortField) => void
  setSortDir: (dir: MediaPilotSortDir) => void
  setFileSearchQuery: (q: string) => void
  setAdminFolderOverride: (val: boolean) => void
  showContextMenu: (x: number, y: number, folderId: number) => void
  hideContextMenu: () => void
}

// ---------------------------------------------------------------------------
// Debounce timer — lives in module scope, not in Zustand state
// ---------------------------------------------------------------------------

let prefsDebounceTimer: ReturnType<typeof setTimeout> | null = null

function schedulePrefsSave(patch: Partial<{ sidebar_w: number; sort_files: MediaPilotSortField; sort_dir: MediaPilotSortDir }>): void {
  if (prefsDebounceTimer !== null) {
    clearTimeout(prefsDebounceTimer)
  }
  prefsDebounceTimer = setTimeout(() => {
    prefsDebounceTimer = null
    apiPut('user-prefs', patch).catch(() => {
      // Silently ignore — prefs save is best-effort
    })
  }, PREFS_DEBOUNCE_MS)
}

// ---------------------------------------------------------------------------
// Seed from WP localised config
// ---------------------------------------------------------------------------

const config =
  typeof window !== 'undefined' && window.MediaPilotConfig ? window.MediaPilotConfig : null

const initialSidebarWidth: number = config?.userPrefs?.sidebar_w ?? 300
const initialSortField: MediaPilotSortField = (config?.userPrefs?.sort_files as MediaPilotSortField) ?? 'date'
const initialSortDir: MediaPilotSortDir     = (config?.userPrefs?.sort_dir as MediaPilotSortDir) ?? 'desc'

// ---------------------------------------------------------------------------
// Store
// ---------------------------------------------------------------------------

export const uiStore = create<UiState>((set, get) => ({
  // ---- Initial state -------------------------------------------------------
  sidebarWidth: initialSidebarWidth,
  sidebarCollapsed: false,
  expandedFolders: new Set<number>(),
  viewMode: 'grid',
  sortField: initialSortField,
  sortDir: initialSortDir,
  fileSearchQuery: '',
  adminFolderOverride: false,
  contextMenu: {
    visible: false,
    x: 0,
    y: 0,
    folderId: null,
  },

  // ---- Sidebar -------------------------------------------------------------

  setSidebarWidth: (w) => {
    const clamped = Math.min(SIDEBAR_MAX, Math.max(SIDEBAR_MIN, w))
    set({ sidebarWidth: clamped })
    schedulePrefsSave({ sidebar_w: clamped })
  },

  toggleSidebar: () => {
    set((state) => ({ sidebarCollapsed: !state.sidebarCollapsed }))
  },

  // ---- Folder expand / collapse --------------------------------------------

  expandFolder: (id) => {
    set((state) => ({
      expandedFolders: new Set([...state.expandedFolders, id]),
    }))
  },

  collapseFolder: (id) => {
    set((state) => {
      const next = new Set(state.expandedFolders)
      next.delete(id)
      return { expandedFolders: next }
    })
  },

  toggleFolder: (id) => {
    const { expandedFolders } = get()
    if (expandedFolders.has(id)) {
      get().collapseFolder(id)
    } else {
      get().expandFolder(id)
    }
  },

  /**
   * Expand all folders. Caller must supply the full list of folder IDs
   * (e.g. from folderStore.getDescendantIds or the flatMap keys).
   */
  expandAll: (ids) => {
    set({ expandedFolders: new Set(ids) })
  },

  collapseAll: () => {
    set({ expandedFolders: new Set<number>() })
  },

  // ---- View mode -----------------------------------------------------------

  setViewMode: (mode) => {
    set({ viewMode: mode })
  },

  // ---- Sort & search -------------------------------------------------------

  setSortField: (field) => {
    set({ sortField: field })
    schedulePrefsSave({ sort_files: field })
  },

  setSortDir: (dir) => {
    set({ sortDir: dir })
    schedulePrefsSave({ sort_dir: dir })
  },

  setFileSearchQuery: (q) => {
    set({ fileSearchQuery: q })
  },

  // ---- Admin folder override -----------------------------------------------

  setAdminFolderOverride: (val) => {
    set({ adminFolderOverride: val })
  },

  // ---- Context menu --------------------------------------------------------

  showContextMenu: (x, y, folderId) => {
    set({ contextMenu: { visible: true, x, y, folderId } })
  },

  hideContextMenu: () => {
    set((state) => ({
      contextMenu: { ...state.contextMenu, visible: false, folderId: null },
    }))
  },
}))

/** React hook — use inside components */
export const useUiStore = uiStore
