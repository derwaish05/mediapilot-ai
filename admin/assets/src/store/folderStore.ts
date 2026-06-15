/**
 * folderStore.ts
 *
 * Manages the folder tree, active folder selection, and all CRUD operations
 * against the MediaPilot REST API. Clipboard (cut/paste) operations are handled
 * entirely client-side until paste is confirmed.
 */

import { create } from 'zustand'
import type { MediaPilotFolder } from '@/types'
import { apiFetch, apiPost, apiPut, apiDelete } from '@/api/client'

// ---------------------------------------------------------------------------
// Types
// ---------------------------------------------------------------------------

interface ClipboardEntry {
  folderId: number
  mode: 'cut'
}

interface FolderState {
  // Data
  tree: MediaPilotFolder[]
  flatMap: Record<number, MediaPilotFolder>
  activeFolder: number | null
  loading: boolean
  error: string | null

  // Clipboard
  clipboard: ClipboardEntry | null

  // Actions
  fetchTree: (viewGlobal?: boolean) => Promise<void>
  setActiveFolder: (id: number | null) => void

  createFolder: (name: string, parentId?: number) => Promise<MediaPilotFolder>
  renameFolder: (id: number, name: string) => Promise<void>
  moveFolder: (id: number, newParentId: number) => Promise<void>
  deleteFolder: (id: number, recursive?: boolean) => Promise<void>
  updateColor: (id: number, color: string) => Promise<void>

  // Clipboard
  cutFolder: (id: number) => void
  pasteFolder: (targetParentId: number) => Promise<void>
  clearClipboard: () => void

  // Helpers
  getFolderById: (id: number) => MediaPilotFolder | undefined
  getAncestors: (id: number) => MediaPilotFolder[]
  getDescendantIds: (id: number) => number[]
}

// ---------------------------------------------------------------------------
// Internal helpers
// ---------------------------------------------------------------------------

/**
 * Recursively walk the folder tree and produce a flat id → MediaPilotFolder map
 * for O(1) lookups without repeated tree traversals.
 */
function buildFlatMap(tree: MediaPilotFolder[]): Record<number, MediaPilotFolder> {
  const map: Record<number, MediaPilotFolder> = {}

  function walk(folders: MediaPilotFolder[]): void {
    for (const folder of folders) {
      map[folder.id] = folder
      if (folder.children.length > 0) {
        walk(folder.children)
      }
    }
  }

  walk(tree)
  return map
}

/**
 * Recursively collect all descendant folder IDs for a given root folder ID.
 * Walks the live tree structure, not the flatMap, to follow children arrays.
 */
function collectDescendantIds(folderId: number, tree: MediaPilotFolder[]): number[] {
  const ids: number[] = []

  function findAndCollect(folders: MediaPilotFolder[]): boolean {
    for (const folder of folders) {
      if (folder.id === folderId) {
        // Found the target — collect all children recursively
        collectAll(folder.children)
        return true
      }
      if (folder.children.length > 0 && findAndCollect(folder.children)) {
        return true
      }
    }
    return false
  }

  function collectAll(folders: MediaPilotFolder[]): void {
    for (const folder of folders) {
      ids.push(folder.id)
      if (folder.children.length > 0) {
        collectAll(folder.children)
      }
    }
  }

  findAndCollect(tree)
  return ids
}

// Seed initial tree from WP localised data when available
const initialTree: MediaPilotFolder[] =
  typeof window !== 'undefined' && window.MediaPilotConfig?.initialTree
    ? window.MediaPilotConfig.initialTree
    : []

// Restore active folder from URL so the sidebar matches what PHP rendered
// on page load. e.g. ?mdpai_folder_id=5 → activeFolder 5
//                     ?mdpai_folder_id=0 → activeFolder 0 (Uncategorized)
//                     (absent)         → activeFolder null (All Files)
function getInitialActiveFolder(): number | null {
  if (typeof window === 'undefined') return null
  const v = new URLSearchParams(window.location.search).get('mdpai_folder_id')
  if (v === null) return null
  const n = parseInt(v, 10)
  return isNaN(n) ? null : n
}

// ---------------------------------------------------------------------------
// Store
// ---------------------------------------------------------------------------

export const folderStore = create<FolderState>((set, get) => ({
  // ---- Initial state -------------------------------------------------------
  tree: initialTree,
  flatMap: buildFlatMap(initialTree),
  activeFolder: getInitialActiveFolder(),
  loading: false,
  error: null,
  clipboard: null,

  // ---- Data fetching -------------------------------------------------------

  fetchTree: async (viewGlobal?: boolean) => {
    set({ loading: true, error: null })
    try {
      const endpoint = viewGlobal ? 'folders?global=1' : 'folders'
      const result = await apiFetch<{ tree: MediaPilotFolder[]; total: number }>(endpoint)
      // Only replace the tree if REST returned folders, OR if the current tree is
      // already empty (no risk of discarding valid initialTree data with an empty response).
      const currentTree = get().tree
      if (result.tree.length > 0 || currentTree.length === 0) {
        set({ tree: result.tree, flatMap: buildFlatMap(result.tree), loading: false })
      } else {
        // REST returned empty but we already have folders from initialTree — keep them.
        set({ loading: false })
      }
    } catch (err) {
      const message = err instanceof Error ? err.message : 'Failed to fetch folders'
      set({ error: message, loading: false })
    }
  },

  setActiveFolder: (id) => {
    set({ activeFolder: id })
    // Keep URL in sync so a page refresh preserves the selected folder.
    // history.replaceState avoids a full page reload.
    if (typeof window !== 'undefined') {
      const url = new URL(window.location.href)
      if (id === null) {
        url.searchParams.delete('mdpai_folder_id')
      } else {
        url.searchParams.set('mdpai_folder_id', String(id))
      }
      history.replaceState(null, '', url.toString())
    }
  },

  // ---- CRUD operations -----------------------------------------------------

  createFolder: async (name, parentId) => {
    set({ loading: true, error: null })
    try {
      const created = await apiPost<MediaPilotFolder>('folders', {
        name,
        parent_id: parentId ?? 0,
      })
      await get().fetchTree()
      return created
    } catch (err) {
      const message = err instanceof Error ? err.message : 'Failed to create folder'
      set({ error: message, loading: false })
      throw err
    }
  },

  renameFolder: async (id, name) => {
    set({ loading: true, error: null })
    try {
      await apiPut<MediaPilotFolder>(`folders/${id}`, { name })
      await get().fetchTree()
    } catch (err) {
      const message = err instanceof Error ? err.message : 'Failed to rename folder'
      set({ error: message, loading: false })
      throw err
    }
  },

  moveFolder: async (id, newParentId) => {
    set({ loading: true, error: null })
    try {
      await apiPost<MediaPilotFolder>(`folders/${id}/move`, { parent_id: newParentId })
      await get().fetchTree()
    } catch (err) {
      const message = err instanceof Error ? err.message : 'Failed to move folder'
      set({ error: message, loading: false })
      throw err
    }
  },

  deleteFolder: async (id, recursive = false) => {
    set({ loading: true, error: null })
    try {
      await apiDelete<void>(`folders/${id}?recursive=${recursive ? 1 : 0}`)
      // If the deleted folder was active, reset to "All Files"
      if (get().activeFolder === id) {
        set({ activeFolder: null })
      }
      await get().fetchTree()
    } catch (err) {
      const message = err instanceof Error ? err.message : 'Failed to delete folder'
      set({ error: message, loading: false })
      throw err
    }
  },

  updateColor: async (id, color) => {
    // Optimistic update — patch tree and flatMap immediately so the UI
    // reflects the new colour without waiting for the round-trip.
    const patchColor = (nodes: MediaPilotFolder[]): MediaPilotFolder[] =>
      nodes.map((n) =>
        n.id === id
          ? { ...n, color }
          : { ...n, children: patchColor(n.children) },
      )

    set((state) => {
      const newTree = patchColor(state.tree)
      return { tree: newTree, flatMap: buildFlatMap(newTree) }
    })

    try {
      await apiPut<MediaPilotFolder>(`folders/${id}`, { color })
    } catch (err) {
      // Roll back on failure and report the error.
      await get().fetchTree()
      const message = err instanceof Error ? err.message : 'Failed to update folder color'
      set({ error: message })
      throw err
    }
  },

  // ---- Clipboard -----------------------------------------------------------

  cutFolder: (id) => {
    set({ clipboard: { folderId: id, mode: 'cut' } })
  },

  pasteFolder: async (targetParentId) => {
    const { clipboard, moveFolder, clearClipboard } = get()
    if (!clipboard) return
    await moveFolder(clipboard.folderId, targetParentId)
    clearClipboard()
  },

  clearClipboard: () => {
    set({ clipboard: null })
  },

  // ---- Helpers -------------------------------------------------------------

  getFolderById: (id) => {
    return get().flatMap[id]
  },

  getAncestors: (id) => {
    const { flatMap } = get()
    const ancestors: MediaPilotFolder[] = []
    let current = flatMap[id]

    while (current && current.parent !== 0) {
      const parent = flatMap[current.parent]
      if (!parent) break
      ancestors.unshift(parent) // prepend so result is root → parent
      current = parent
    }

    return ancestors
  },

  getDescendantIds: (id) => {
    return collectDescendantIds(id, get().tree)
  },
}))

/** React hook — use inside components */
export const useFolderStore = folderStore
