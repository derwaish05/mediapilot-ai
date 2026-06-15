/**
 * uploadStore.ts
 *
 * Manages the upload queue and per-item progress state.
 *
 * This store is deliberately limited to STATE MANAGEMENT only — it does not
 * initiate XMLHttpRequest or fetch calls. The actual upload logic lives in
 * UploadHandler (Sprint S12) which reads this store and calls updateItem()
 * as progress events fire.
 */

import { create } from 'zustand'

// ---------------------------------------------------------------------------
// Types
// ---------------------------------------------------------------------------

export interface UploadItem {
  id: string                      // unique key: filename + '-' + timestamp
  file: File
  folderId: number                // target folder (0 = uncategorized)
  status: 'queued' | 'uploading' | 'done' | 'error'
  progress: number                // 0–100
  error: string | null
  attachmentId: number | null     // WP attachment ID populated on success
}

interface UploadState {
  queue: UploadItem[]
  targetFolder: number            // default folder ID for new uploads (0 = uncategorized)

  // Actions
  setTargetFolder: (folderId: number) => void
  addToQueue: (files: File[], folderId?: number) => void
  updateItem: (id: string, patch: Partial<UploadItem>) => void
  removeItem: (id: string) => void
  clearCompleted: () => void

  // Computed getter
  getActiveCount: () => number
}

// ---------------------------------------------------------------------------
// Seed from WP localised config
// ---------------------------------------------------------------------------

const initialTargetFolder: number =
  typeof window !== 'undefined' && window.MediaPilotConfig?.userPrefs?.folder_id != null
    ? (window.MediaPilotConfig.userPrefs.folder_id ?? 0)
    : 0

// ---------------------------------------------------------------------------
// Store
// ---------------------------------------------------------------------------

export const uploadStore = create<UploadState>((set, get) => ({
  // ---- Initial state -------------------------------------------------------
  queue: [],
  targetFolder: initialTargetFolder,

  // ---- Actions -------------------------------------------------------------

  setTargetFolder: (folderId) => {
    set({ targetFolder: folderId })
  },

  addToQueue: (files, folderId) => {
    const resolvedFolder = folderId !== undefined ? folderId : get().targetFolder
    const now = Date.now()

    const newItems: UploadItem[] = files.map((file) => ({
      id: `${file.name}-${now}`,
      file,
      folderId: resolvedFolder,
      status: 'queued',
      progress: 0,
      error: null,
      attachmentId: null,
    }))

    set((state) => ({ queue: [...state.queue, ...newItems] }))
  },

  updateItem: (id, patch) => {
    set((state) => ({
      queue: state.queue.map((item) =>
        item.id === id ? { ...item, ...patch } : item,
      ),
    }))
  },

  removeItem: (id) => {
    set((state) => ({
      queue: state.queue.filter((item) => item.id !== id),
    }))
  },

  clearCompleted: () => {
    set((state) => ({
      queue: state.queue.filter(
        (item) => item.status !== 'done' && item.status !== 'error',
      ),
    }))
  },

  // ---- Computed getter -----------------------------------------------------

  getActiveCount: () => {
    return get().queue.filter(
      (item) => item.status === 'queued' || item.status === 'uploading',
    ).length
  },
}))

/** React hook — use inside components */
export const useUploadStore = uploadStore
