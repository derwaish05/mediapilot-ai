/**
 * selectionStore.ts
 *
 * Manages multi-file selection state for bulk operations in the media library.
 * All derived values (isSelected, count, id list) are computed from the
 * live Set — nothing extra is stored in Zustand state.
 *
 * When the selectedIds set drains to zero the store automatically resets
 * isSelecting to false so the bulk action bar disappears cleanly.
 */

import { create } from 'zustand'

// ---------------------------------------------------------------------------
// Types
// ---------------------------------------------------------------------------

interface SelectionState {
  selectedIds: Set<number>
  isSelecting: boolean

  // Actions
  toggleSelection: (id: number) => void
  selectAll: (ids: number[]) => void
  clearSelection: () => void
  setSelecting: (active: boolean) => void

  // Computed getters (derived — not stored)
  isSelected: (id: number) => boolean
  getSelectedCount: () => number
  getSelectedIds: () => number[]
}

// ---------------------------------------------------------------------------
// Store
// ---------------------------------------------------------------------------

export const selectionStore = create<SelectionState>((set, get) => ({
  // ---- Initial state -------------------------------------------------------
  selectedIds: new Set<number>(),
  isSelecting: false,

  // ---- Actions -------------------------------------------------------------

  toggleSelection: (id) => {
    set((state) => {
      const next = new Set(state.selectedIds)
      if (next.has(id)) {
        next.delete(id)
      } else {
        next.add(id)
        // Entering selection mode on first pick
      }
      return {
        selectedIds: next,
        // Auto-enable selecting when IDs are added; auto-disable when all removed
        isSelecting: next.size > 0 ? true : false,
      }
    })
  },

  selectAll: (ids) => {
    set({
      selectedIds: new Set(ids),
      isSelecting: ids.length > 0,
    })
  },

  clearSelection: () => {
    set({ selectedIds: new Set<number>(), isSelecting: false })
  },

  setSelecting: (active) => {
    set((state) => {
      // When disabling selection mode also clear any existing selection
      if (!active) {
        return { isSelecting: false, selectedIds: new Set<number>() }
      }
      return { isSelecting: true }
    })
  },

  // ---- Computed getters ----------------------------------------------------

  isSelected: (id) => {
    return get().selectedIds.has(id)
  },

  getSelectedCount: () => {
    return get().selectedIds.size
  },

  getSelectedIds: () => {
    return Array.from(get().selectedIds)
  },
}))

/** React hook — use inside components */
export const useSelectionStore = selectionStore
