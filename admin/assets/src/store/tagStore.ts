/**
 * tagStore.ts
 *
 * Zustand store for the Smart Tags / Labels system.
 *
 * Responsibilities:
 *  - Cache all site tags in memory (fetched once, updated on mutation).
 *  - Expose actions that proxy to the mediapilot/v1/tags and mediapilot/v1/files REST routes.
 */

import { create } from 'zustand'
import type { MediaPilotTag } from '@/types'
import { apiFetch, apiPost, apiPut, apiDelete } from '@/api/client'

// ---------------------------------------------------------------------------
// Store shape
// ---------------------------------------------------------------------------

interface TagState {
  tags: MediaPilotTag[]
  loading: boolean
  error: string | null

  // Global tag list
  fetchTags: () => Promise<void>
  createTag: (name: string, color: string) => Promise<MediaPilotTag>
  updateTag: (id: number, data: Partial<Pick<MediaPilotTag, 'name' | 'color'>>) => Promise<MediaPilotTag>
  deleteTag: (id: number) => Promise<void>

  // File-level tag management
  getFileTags: (attachmentId: number) => Promise<MediaPilotTag[]>
  setFileTags: (attachmentId: number, tagIds: number[]) => Promise<void>
  removeFileTag: (attachmentId: number, tagId: number) => Promise<void>

  // Bulk assignment
  bulkTagFiles: (
    attachmentIds: number[],
    tagIds: number[],
    mode: 'add' | 'set',
  ) => Promise<void>
}

// ---------------------------------------------------------------------------
// Store
// ---------------------------------------------------------------------------

export const useTagStore = create<TagState>((set, get) => ({
  tags: [],
  loading: false,
  error: null,

  // ---- Global tag list -------------------------------------------------------

  fetchTags: async () => {
    set({ loading: true, error: null })
    try {
      const tags = await apiFetch<MediaPilotTag[]>('tags')
      set({ tags, loading: false })
    } catch (err) {
      set({
        error: err instanceof Error ? err.message : 'Failed to load tags',
        loading: false,
      })
    }
  },

  createTag: async (name, color) => {
    const tag = await apiPost<MediaPilotTag>('tags', { name, color })
    set((s) => ({ tags: [...s.tags, tag] }))
    return tag
  },

  updateTag: async (id, data) => {
    const tag = await apiPut<MediaPilotTag>(`tags/${id}`, data)
    set((s) => ({ tags: s.tags.map((t) => (t.id === id ? tag : t)) }))
    return tag
  },

  deleteTag: async (id) => {
    await apiDelete(`tags/${id}`)
    set((s) => ({ tags: s.tags.filter((t) => t.id !== id) }))
  },

  // ---- File-level operations -------------------------------------------------

  getFileTags: (attachmentId) => {
    return apiFetch<MediaPilotTag[]>(`files/${attachmentId}/tags`)
  },

  setFileTags: async (attachmentId, tagIds) => {
    await apiPost(`files/${attachmentId}/tags`, { tag_ids: tagIds })
  },

  removeFileTag: async (attachmentId, tagId) => {
    await apiDelete(`files/${attachmentId}/tags/${tagId}`)
  },

  // ---- Bulk operations -------------------------------------------------------

  bulkTagFiles: async (attachmentIds, tagIds, mode) => {
    await apiPost('files/tags/bulk', {
      attachment_ids: attachmentIds,
      tag_ids: tagIds,
      mode,
    })
  },
}))
