/**
 * TagsModal.tsx
 *
 * Modal for bulk-assigning tags to the currently selected media items.
 *
 * Opened by BulkActionBar when the user clicks "Tag".
 *
 * Features:
 *   - TagPicker for selecting (or creating) tags
 *   - "Add to existing" vs "Replace all tags" mode radio
 *   - Calls POST /files/tags/bulk then shows a brief success state
 */

import React, { useState, useEffect, useCallback } from 'react'
import { useSelectionStore } from '@/store/selectionStore'
import { useTagStore } from '@/store/tagStore'
import { TagPicker } from '@/components/TagPicker'

interface TagsModalProps {
  open: boolean
  onClose: () => void
}

const TagsModal: React.FC<TagsModalProps> = ({ open, onClose }) => {
  const selectedIds   = useSelectionStore((s) => s.selectedIds)
  const bulkTagFiles  = useTagStore((s) => s.bulkTagFiles)
  const fetchTags     = useTagStore((s) => s.fetchTags)

  const [tagIds,   setTagIds]   = useState<number[]>([])
  const [mode,     setMode]     = useState<'add' | 'set'>('add')
  const [loading,  setLoading]  = useState(false)
  const [done,     setDone]     = useState(false)

  // Refresh tag list and reset form on open
  useEffect(() => {
    if (open) {
      fetchTags()
      setTagIds([])
      setDone(false)
    }
  }, [open, fetchTags])

  const handleApply = useCallback(async () => {
    if (tagIds.length === 0 || loading) return

    setLoading(true)
    try {
      await bulkTagFiles(Array.from(selectedIds), tagIds, mode)
      setDone(true)
      window.setTimeout(onClose, 900)
    } finally {
      setLoading(false)
    }
  }, [tagIds, mode, loading, selectedIds, bulkTagFiles, onClose])

  if (!open) return null

  const count = selectedIds.size

  return (
    <div
      className="fixed inset-0 z-[10000] flex items-center justify-center"
      role="dialog"
      aria-modal="true"
      aria-label="Bulk tag assignment"
    >
      {/* Backdrop */}
      <div className="absolute inset-0 bg-black/50" onClick={onClose} />

      {/* Panel */}
      <div className="relative bg-white rounded-xl shadow-2xl w-full max-w-md mx-4 p-6">
        {/* Header */}
        <h2 className="text-base font-semibold text-slate-900 mb-1">
          Tag {count} file{count !== 1 ? 's' : ''}
        </h2>
        <p className="text-sm text-slate-500 mb-4">
          Select or create tags to apply to the selected files.
        </p>

        {/* Tag picker */}
        <TagPicker selectedTagIds={tagIds} onChange={setTagIds} allowCreate />

        {/* Mode selector */}
        <div className="flex gap-4 mt-4">
          {(['add', 'set'] as const).map((m) => (
            <label
              key={m}
              className="flex items-center gap-1.5 text-sm text-slate-600 cursor-pointer select-none"
            >
              <input
                type="radio"
                name="tags-mode"
                value={m}
                checked={mode === m}
                onChange={() => setMode(m)}
                className="accent-blue-500"
              />
              {m === 'add' ? 'Add to existing tags' : 'Replace all tags'}
            </label>
          ))}
        </div>

        {/* Actions */}
        <div className="flex justify-end gap-2 mt-6">
          <button
            type="button"
            className="px-4 py-2 text-sm rounded-md text-slate-600 hover:bg-slate-100 cursor-pointer transition-colors"
            onClick={onClose}
          >
            Cancel
          </button>
          <button
            type="button"
            className="px-4 py-2 text-sm rounded-md bg-blue-600 text-white hover:bg-blue-700 disabled:opacity-50 cursor-pointer transition-colors flex items-center gap-2"
            onClick={handleApply}
            disabled={tagIds.length === 0 || loading}
          >
            {loading && (
              <span className="w-3.5 h-3.5 border-2 border-white/40 border-t-white rounded-full animate-spin" />
            )}
            {done ? 'Done ✓' : loading ? 'Applying…' : 'Apply Tags'}
          </button>
        </div>
      </div>
    </div>
  )
}

export default TagsModal
