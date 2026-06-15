/**
 * BulkActionBar.tsx
 *
 * A fixed-position floating action bar that appears at the bottom of the
 * screen whenever one or more media items are selected in the WP media library.
 *
 * Selection is tracked in selectionStore, which is populated by a
 * MutationObserver bridge that watches for WP's native .attachment.selected
 * class changes (injected via MediaLibraryIntegration::injectSidebarMount).
 *
 * Actions:
 *   Move      → opens BulkMovePicker modal to choose target folder
 *   Delete    → inline confirm, then calls wp.media delete for each ID
 *   Download  → POST /files/zip → fetch blob → browser download
 *   × Clear   → calls selectionStore.clearSelection + WP deselect
 *
 * Keyboard: Escape clears selection when the bar is focused.
 */

import React, { useState, useCallback, useEffect, useRef } from 'react'
import { useSelectionStore } from '@/store/selectionStore'
import BulkMovePicker from '@/components/BulkMovePicker'
import BulkMetaEditor from '@/components/BulkMetaEditor'
import TagsModal from '@/components/TagsModal'

// ---------------------------------------------------------------------------
// Inline icons
// ---------------------------------------------------------------------------

const IconMove: React.FC = () => (
  <svg viewBox="0 0 20 20" fill="currentColor" className="w-4 h-4" aria-hidden="true">
    <path d="M10 3a1 1 0 011 1v5h5a1 1 0 110 2h-5v5a1 1 0 11-2 0v-5H4a1 1 0 110-2h5V4a1 1 0 011-1z" />
  </svg>
)

const IconTrash: React.FC = () => (
  <svg viewBox="0 0 20 20" fill="currentColor" className="w-4 h-4" aria-hidden="true">
    <path
      fillRule="evenodd"
      d="M9 2a1 1 0 00-.894.553L7.382 4H4a1 1 0 000 2v10a2 2 0 002 2h8a2 2 0 002-2V6a1 1 0 100-2h-3.382l-.724-1.447A1 1 0 0011 2H9zM7 8a1 1 0 012 0v6a1 1 0 11-2 0V8zm5-1a1 1 0 00-1 1v6a1 1 0 102 0V8a1 1 0 00-1-1z"
      clipRule="evenodd"
    />
  </svg>
)

const IconDownload: React.FC = () => (
  <svg viewBox="0 0 20 20" fill="currentColor" className="w-4 h-4" aria-hidden="true">
    <path
      fillRule="evenodd"
      d="M3 17a1 1 0 011-1h12a1 1 0 110 2H4a1 1 0 01-1-1zm3.293-7.707a1 1 0 011.414 0L9 10.586V3a1 1 0 112 0v7.586l1.293-1.293a1 1 0 111.414 1.414l-3 3a1 1 0 01-1.414 0l-3-3a1 1 0 010-1.414z"
      clipRule="evenodd"
    />
  </svg>
)

const IconX: React.FC = () => (
  <svg viewBox="0 0 20 20" fill="currentColor" className="w-4 h-4" aria-hidden="true">
    <path
      fillRule="evenodd"
      d="M4.293 4.293a1 1 0 011.414 0L10 8.586l4.293-4.293a1 1 0 111.414 1.414L11.414 10l4.293 4.293a1 1 0 01-1.414 1.414L10 11.414l-4.293 4.293a1 1 0 01-1.414-1.414L8.586 10 4.293 5.707a1 1 0 010-1.414z"
      clipRule="evenodd"
    />
  </svg>
)

const IconTag: React.FC = () => (
  <svg viewBox="0 0 20 20" fill="currentColor" className="w-4 h-4" aria-hidden="true">
    <path
      fillRule="evenodd"
      d="M17.707 9.293l-7-7A1 1 0 0010 2H4a2 2 0 00-2 2v6a1 1 0 00.293.707l7 7a1 1 0 001.414 0l7-7a1 1 0 000-1.414zM6 7a1 1 0 110-2 1 1 0 010 2z"
      clipRule="evenodd"
    />
  </svg>
)

const IconPencil: React.FC = () => (
  <svg viewBox="0 0 20 20" fill="currentColor" className="w-4 h-4" aria-hidden="true">
    <path d="M13.586 3.586a2 2 0 112.828 2.828l-.793.793-2.828-2.828.793-.793zM11.379 5.793L3 14.172V17h2.828l8.38-8.379-2.83-2.828z" />
  </svg>
)

const IconCheck: React.FC = () => (
  <svg viewBox="0 0 20 20" fill="currentColor" className="w-4 h-4" aria-hidden="true">
    <path
      fillRule="evenodd"
      d="M16.707 5.293a1 1 0 010 1.414l-8 8a1 1 0 01-1.414 0l-4-4a1 1 0 011.414-1.414L8 12.586l7.293-7.293a1 1 0 011.414 0z"
      clipRule="evenodd"
    />
  </svg>
)

// ---------------------------------------------------------------------------
// Delete confirmation sub-component
// ---------------------------------------------------------------------------

interface DeleteConfirmProps {
  count: number
  onConfirm: () => void
  onCancel: () => void
  loading: boolean
}

const DeleteConfirm: React.FC<DeleteConfirmProps> = ({ count, onConfirm, onCancel, loading }) => (
  <div className="flex items-center gap-3">
    <span className="text-sm text-white">
      Delete {count} file{count !== 1 ? 's' : ''}? This cannot be undone.
    </span>
    <button
      type="button"
      className="px-3 py-1.5 text-xs rounded bg-white/20 hover:bg-white/30 text-white cursor-pointer transition-colors"
      onClick={onCancel}
      disabled={loading}
    >
      Cancel
    </button>
    <button
      type="button"
      className="px-3 py-1.5 text-xs rounded bg-red-500 hover:bg-red-400 text-white cursor-pointer transition-colors flex items-center gap-1.5"
      onClick={onConfirm}
      disabled={loading}
    >
      {loading ? (
        <span className="w-3 h-3 border-2 border-white/40 border-t-white rounded-full animate-spin inline-block" />
      ) : (
        <IconTrash />
      )}
      Delete
    </button>
  </div>
)

// ---------------------------------------------------------------------------
// Main component
// ---------------------------------------------------------------------------

const BulkActionBar: React.FC = () => {
  // ---- Store ---------------------------------------------------------------

  const selectedIds    = useSelectionStore((s) => s.selectedIds)
  const isSelecting    = useSelectionStore((s) => s.isSelecting)
  const clearSelection = useSelectionStore((s) => s.clearSelection)
  const count          = selectedIds.size

  // ---- Local state ---------------------------------------------------------

  const [showMovePicker, setShowMovePicker]       = useState(false)
  const [showTagsModal, setShowTagsModal]         = useState(false)
  const [showMetaEditor, setShowMetaEditor]       = useState(false)
  const [showDeleteConfirm, setShowDeleteConfirm] = useState(false)
  const [deleteLoading, setDeleteLoading]         = useState(false)
  const [downloadLoading, setDownloadLoading]     = useState(false)
  const [toast, setToast]                         = useState<string | null>(null)

  const barRef = useRef<HTMLDivElement>(null)

  // ---- Toast helper --------------------------------------------------------

  const showToast = useCallback((msg: string) => {
    setToast(msg)
    const t = window.setTimeout(() => setToast(null), 3000)
    return () => window.clearTimeout(t)
  }, [])

  // ---- Escape key clears selection ----------------------------------------

  useEffect(() => {
    if (!isSelecting) return

    const handler = (e: KeyboardEvent) => {
      if (e.key === 'Escape' && !showMovePicker && !showDeleteConfirm) {
        handleClear()
      }
    }

    document.addEventListener('keydown', handler)
    return () => document.removeEventListener('keydown', handler)
  }, [isSelecting, showMovePicker, showDeleteConfirm])

  // ---- Clear selection — also tells WP to deselect ------------------------

  const handleClear = useCallback(() => {
    clearSelection()
    setShowDeleteConfirm(false)
    setShowMovePicker(false)

    // Tell WP backbone to deselect all items in the media grid.
    try {
      const frame = (window as any).wp?.media?.frame
      if (frame) {
        const sel = frame.state()?.get('selection')
        sel?.reset()
      }
    } catch {
      // Not in WP media frame context — ignore.
    }

    // Also remove .selected class from any DOM items that were programmatically
    // selected via our MutationObserver bridge.
    document.querySelectorAll<HTMLElement>('.attachment.selected').forEach((el) => {
      el.classList.remove('selected')
    })
  }, [clearSelection])

  // ---- Move action ---------------------------------------------------------

  const handleMoveClick = useCallback(() => {
    setShowDeleteConfirm(false)
    setShowTagsModal(false)
    setShowMovePicker(true)
  }, [])

  const handleTagClick = useCallback(() => {
    setShowDeleteConfirm(false)
    setShowMovePicker(false)
    setShowTagsModal(true)
  }, [])

  const handleMetaEditClick = useCallback(() => {
    setShowDeleteConfirm(false)
    setShowMovePicker(false)
    setShowTagsModal(false)
    setShowMetaEditor(true)
  }, [])

  const handleMoveConfirm = useCallback(
    async (folderId: number) => {
      const ids = Array.from(selectedIds)
      const { restUrl, nonce } = window.MediaPilotConfig

      try {
        const res = await fetch(`${restUrl.replace(/\/$/, '')}/files/move`, {
          method: 'POST',
          headers: { 'Content-Type': 'application/json', 'X-WP-Nonce': nonce },
          credentials: 'same-origin',
          body: JSON.stringify({ attachment_ids: ids, folder_id: folderId }),
        })

        if (!res.ok) throw new Error('Move failed')

        showToast(`${ids.length} file${ids.length !== 1 ? 's' : ''} moved.`)
        handleClear()

        // Reload the media library grid so the moved files disappear/reappear correctly.
        try {
          const frame = (window as any).wp?.media?.frame
          frame?.content?.get()?.collection?.props?.trigger('change')
        } catch {
          // Optional refresh — ignore errors.
        }
      } catch {
        showToast('Move failed. Please try again.')
      } finally {
        setShowMovePicker(false)
      }
    },
    [selectedIds, handleClear, showToast],
  )

  // ---- Delete action -------------------------------------------------------

  const handleDeleteClick = useCallback(() => {
    setShowMovePicker(false)
    setShowDeleteConfirm(true)
  }, [])

  const handleDeleteConfirm = useCallback(async () => {
    const ids = Array.from(selectedIds)
    setDeleteLoading(true)

    const results = await Promise.allSettled(
      ids.map((id) =>
        fetch(`${window.location.origin}/wp-json/wp/v2/media/${id}`, {
          method: 'DELETE',
          headers: {
            'X-WP-Nonce': window.MediaPilotConfig.nonce,
            'Content-Type': 'application/json',
          },
          credentials: 'same-origin',
          body: JSON.stringify({ force: true }),
        }),
      ),
    )

    const succeeded = results.filter((r) => r.status === 'fulfilled').length
    setDeleteLoading(false)
    showToast(`${succeeded} file${succeeded !== 1 ? 's' : ''} deleted.`)
    handleClear()

    // Reload WP media library grid.
    try {
      ;(window as any).wp?.media?.frame?.content?.get()?.collection?.props?.trigger('change')
    } catch {
      // Ignore.
    }
  }, [selectedIds, handleClear, showToast])

  // ---- Download ZIP action -------------------------------------------------

  const handleDownload = useCallback(async () => {
    const ids = Array.from(selectedIds)
    const { restUrl, nonce } = window.MediaPilotConfig
    setDownloadLoading(true)

    try {
      const res = await fetch(`${restUrl.replace(/\/$/, '')}/files/zip`, {
        method: 'POST',
        headers: { 'Content-Type': 'application/json', 'X-WP-Nonce': nonce },
        credentials: 'same-origin',
        body: JSON.stringify({ attachment_ids: ids }),
      })

      if (!res.ok) throw new Error('ZIP failed')

      const blob = await res.blob()
      const url  = URL.createObjectURL(blob)
      const a    = document.createElement('a')
      a.href     = url
      a.download = 'media-files.zip'
      document.body.appendChild(a)
      a.click()
      document.body.removeChild(a)
      URL.revokeObjectURL(url)
    } catch {
      showToast('Download failed. Please try again.')
    } finally {
      setDownloadLoading(false)
    }
  }, [selectedIds, showToast])

  // ---- Don't render when nothing is selected ------------------------------

  if (!isSelecting || count === 0) return null

  // ---- Render --------------------------------------------------------------

  return (
    <>
      {/* Toast notification */}
      {toast && (
        <div
          role="status"
          aria-live="polite"
          className="fixed bottom-24 left-1/2 -translate-x-1/2 bg-slate-800 text-white text-sm px-4 py-2 rounded-lg shadow-lg z-[9999] flex items-center gap-2 animate-fade-in"
        >
          <IconCheck />
          {toast}
        </div>
      )}

      {/* BulkMovePicker modal */}
      {showMovePicker && (
        <BulkMovePicker
          onConfirm={handleMoveConfirm}
          onClose={() => setShowMovePicker(false)}
        />
      )}

      {/* Tags modal */}
      <TagsModal open={showTagsModal} onClose={() => setShowTagsModal(false)} />

      {/* Bulk Meta Editor modal */}
      {showMetaEditor && (
        <BulkMetaEditor
          initialIds={Array.from(selectedIds)}
          onClose={() => setShowMetaEditor(false)}
        />
      )}

      {/* The floating bar */}
      <div
        ref={barRef}
        role="toolbar"
        aria-label={`${count} items selected — bulk actions`}
        className="fixed bottom-0 left-0 right-0 z-[9998] flex items-center justify-between gap-4 px-6 py-3 bg-slate-800 shadow-xl border-t border-slate-700 text-white"
        style={{ minHeight: 56 }}
      >
        {/* Left: selection count */}
        <div className="flex items-center gap-3 shrink-0">
          <span className="inline-flex items-center justify-center w-6 h-6 rounded-full bg-blue-500 text-white text-xs font-bold">
            {count}
          </span>
          <span className="text-sm font-medium text-slate-200">
            {count} item{count !== 1 ? 's' : ''} selected
          </span>
        </div>

        {/* Centre: actions OR delete confirm */}
        {showDeleteConfirm ? (
          <DeleteConfirm
            count={count}
            onConfirm={handleDeleteConfirm}
            onCancel={() => setShowDeleteConfirm(false)}
            loading={deleteLoading}
          />
        ) : (
          <div className="flex items-center gap-2">
            {/* Move */}
            <button
              type="button"
              className="flex items-center gap-1.5 px-3 py-1.5 text-sm rounded-md bg-white/10 hover:bg-white/20 text-white cursor-pointer transition-colors"
              onClick={handleMoveClick}
            >
              <IconMove />
              Move to Folder
            </button>

            {/* Tag */}
            <button
              type="button"
              className="flex items-center gap-1.5 px-3 py-1.5 text-sm rounded-md bg-white/10 hover:bg-white/20 text-white cursor-pointer transition-colors"
              onClick={handleTagClick}
            >
              <IconTag />
              Tag
            </button>

            {/* Edit Metadata */}
            <button
              type="button"
              className="flex items-center gap-1.5 px-3 py-1.5 text-sm rounded-md bg-white/10 hover:bg-white/20 text-white cursor-pointer transition-colors"
              onClick={handleMetaEditClick}
            >
              <IconPencil />
              Edit Metadata
            </button>

            {/* Delete */}
            <button
              type="button"
              className="flex items-center gap-1.5 px-3 py-1.5 text-sm rounded-md bg-white/10 hover:bg-red-500/80 text-white cursor-pointer transition-colors"
              onClick={handleDeleteClick}
            >
              <IconTrash />
              Delete
            </button>

            {/* Download ZIP */}
            <button
              type="button"
              className="flex items-center gap-1.5 px-3 py-1.5 text-sm rounded-md bg-white/10 hover:bg-white/20 text-white cursor-pointer transition-colors disabled:opacity-50"
              onClick={handleDownload}
              disabled={downloadLoading}
            >
              {downloadLoading ? (
                <span className="w-4 h-4 border-2 border-white/40 border-t-white rounded-full animate-spin" />
              ) : (
                <IconDownload />
              )}
              Download ZIP
            </button>
          </div>
        )}

        {/* Right: clear / deselect */}
        <button
          type="button"
          aria-label="Clear selection"
          className="shrink-0 flex items-center gap-1.5 px-2 py-1.5 text-sm rounded-md text-slate-400 hover:text-white hover:bg-white/10 cursor-pointer transition-colors"
          onClick={handleClear}
        >
          <IconX />
          <span className="sr-only">Clear selection</span>
        </button>
      </div>
    </>
  )
}

export default BulkActionBar
