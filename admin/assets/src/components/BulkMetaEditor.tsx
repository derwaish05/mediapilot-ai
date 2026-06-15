/**
 * BulkMetaEditor.tsx
 *
 * Full-screen modal for bulk-editing attachment metadata (ALT text, title,
 * caption, description) for multiple files at once.
 *
 * Entry points:
 *   - Triggered from BulkActionBar with the currently-selected attachment IDs.
 *   - User can also switch to "Load folder" mode to replace the table with all
 *     files from a chosen folder.
 *
 * Features:
 *   - Editable table: ALT, title, caption, description per row.
 *   - Thumbnail preview column.
 *   - "Same as filename" shortcut button on every title cell.
 *   - "Copy to all" button — applies the first row's value for a column to
 *     every other row.
 *   - SEO helper: rows with an empty ALT text cell are highlighted in red.
 *   - Batch save via POST /files/batch-meta with a progress indicator.
 *   - Folder picker for loading an entire folder's files into the table.
 */

import React, {
  useState,
  useEffect,
  useCallback,
  useRef,
  ChangeEvent,
} from 'react'
import { apiFetch, apiPost } from '@/api/client'
import { useFolderStore } from '@/store/folderStore'
import type { MediaPilotFolder } from '@/types'

// ---------------------------------------------------------------------------
// Types
// ---------------------------------------------------------------------------

interface MetaItem {
  id: number
  filename: string
  title: string
  alt: string
  caption: string
  description: string
  thumbnail_url: string
  mime_type: string
}

type EditableField = 'alt' | 'title' | 'caption' | 'description'

interface BulkMetaEditorProps {
  /** Initial set of attachment IDs to load. Pass empty array to start blank. */
  initialIds: number[]
  onClose: () => void
}

// ---------------------------------------------------------------------------
// Inline icons
// ---------------------------------------------------------------------------

const IconX: React.FC = () => (
  <svg viewBox="0 0 20 20" fill="currentColor" className="w-5 h-5" aria-hidden="true">
    <path fillRule="evenodd" d="M4.293 4.293a1 1 0 011.414 0L10 8.586l4.293-4.293a1 1 0 111.414 1.414L11.414 10l4.293 4.293a1 1 0 01-1.414 1.414L10 11.414l-4.293 4.293a1 1 0 01-1.414-1.414L8.586 10 4.293 5.707a1 1 0 010-1.414z" clipRule="evenodd" />
  </svg>
)

const IconSave: React.FC = () => (
  <svg viewBox="0 0 20 20" fill="currentColor" className="w-4 h-4" aria-hidden="true">
    <path d="M7.707 10.293a1 1 0 10-1.414 1.414l3 3a1 1 0 001.414 0l3-3a1 1 0 00-1.414-1.414L11 11.586V6h5a2 2 0 012 2v7a2 2 0 01-2 2H4a2 2 0 01-2-2V8a2 2 0 012-2h5v5.586l-1.293-1.293z" />
  </svg>
)

const IconCopy: React.FC = () => (
  <svg viewBox="0 0 20 20" fill="currentColor" className="w-3.5 h-3.5" aria-hidden="true">
    <path d="M8 3a1 1 0 011-1h2a1 1 0 110 2H9a1 1 0 01-1-1z" />
    <path d="M6 3a2 2 0 00-2 2v11a2 2 0 002 2h8a2 2 0 002-2V5a2 2 0 00-2-2 3 3 0 01-3 3H9a3 3 0 01-3-3z" />
  </svg>
)

const Spinner: React.FC<{ className?: string }> = ({ className = 'w-4 h-4' }) => (
  <span className={`${className} border-2 border-white/30 border-t-white rounded-full animate-spin inline-block`} />
)

// ---------------------------------------------------------------------------
// Flat folder list helper
// ---------------------------------------------------------------------------

function flattenFolders(folders: MediaPilotFolder[]): MediaPilotFolder[] {
  const result: MediaPilotFolder[] = []
  const walk = (nodes: MediaPilotFolder[]) => {
    for (const n of nodes) {
      result.push(n)
      if (n.children.length) walk(n.children)
    }
  }
  walk(folders)
  return result
}

// ---------------------------------------------------------------------------
// Main component
// ---------------------------------------------------------------------------

const BulkMetaEditor: React.FC<BulkMetaEditorProps> = ({ initialIds, onClose }) => {
  const folderTree = useFolderStore((s) => s.tree)
  const flatFolders = flattenFolders(folderTree)

  // ---- State ---------------------------------------------------------------

  const [items, setItems] = useState<MetaItem[]>([])
  const [loading, setLoading] = useState(false)
  const [saving, setSaving] = useState(false)
  const [saveProgress, setSaveProgress] = useState(0) // 0–100
  const [toast, setToast] = useState<{ msg: string; ok: boolean } | null>(null)
  const [selectedFolder, setSelectedFolder] = useState<number | ''>('')

  const toastTimer = useRef<ReturnType<typeof setTimeout> | null>(null)

  // ---- Load initial IDs on mount ------------------------------------------

  useEffect(() => {
    if (initialIds.length === 0) return
    void loadByIds(initialIds)
  }, []) // eslint-disable-line react-hooks/exhaustive-deps

  // ---- Helpers -------------------------------------------------------------

  const showToast = useCallback((msg: string, ok = true) => {
    if (toastTimer.current) clearTimeout(toastTimer.current)
    setToast({ msg, ok })
    toastTimer.current = setTimeout(() => setToast(null), 3500)
  }, [])

  async function loadByIds(ids: number[]) {
    if (ids.length === 0) return
    setLoading(true)
    try {
      const res = await apiPost<{ items: MetaItem[] }>('files/meta-list', { ids })
      setItems(res.items)
    } catch {
      showToast('Failed to load file metadata.', false)
    } finally {
      setLoading(false)
    }
  }

  async function loadByFolder(folderId: number) {
    setLoading(true)
    try {
      const res = await apiFetch<{ files: MetaItem[] }>(
        `files/search?folder_id=${folderId}&per_page=100&q=`
      )
      setItems(res.files)
    } catch {
      showToast('Failed to load folder files.', false)
    } finally {
      setLoading(false)
    }
  }

  // ---- Field edit ----------------------------------------------------------

  const updateField = useCallback(
    (id: number, field: EditableField, value: string) => {
      setItems((prev) =>
        prev.map((item) => (item.id === id ? { ...item, [field]: value } : item))
      )
    },
    []
  )

  // ---- "Same as filename" for title ----------------------------------------

  const setTitleFromFilename = useCallback((id: number) => {
    setItems((prev) =>
      prev.map((item) => {
        if (item.id !== id) return item
        // Strip extension and replace hyphens/underscores with spaces.
        const name = item.filename.replace(/\.[^.]+$/, '').replace(/[-_]+/g, ' ')
        return { ...item, title: name }
      })
    )
  }, [])

  // ---- "Copy to all" for a column ------------------------------------------

  const copyFirstToAll = useCallback((field: EditableField) => {
    if (items.length < 2) return
    const firstValue = items[0][field]
    setItems((prev) => prev.map((item) => ({ ...item, [field]: firstValue })))
  }, [items])

  // ---- Save ----------------------------------------------------------------

  const handleSave = useCallback(async () => {
    if (items.length === 0) return
    setSaving(true)
    setSaveProgress(0)

    // Simulate chunked progress for large batches (actual save is one request).
    const progressInterval = setInterval(() => {
      setSaveProgress((p) => Math.min(p + 10, 90))
    }, 80)

    try {
      const payload = items.map(({ id, alt, title, caption, description }) => ({
        id,
        alt,
        title,
        caption,
        description,
      }))

      const res = await apiPost<{ updated: number; failed: number[] }>('files/batch-meta', {
        items: payload,
      })

      clearInterval(progressInterval)
      setSaveProgress(100)

      const { updated, failed } = res
      if (failed.length > 0) {
        showToast(`Saved ${updated} files. ${failed.length} failed.`, false)
      } else {
        showToast(`${updated} file${updated !== 1 ? 's' : ''} saved successfully.`)
      }
    } catch {
      clearInterval(progressInterval)
      showToast('Save failed. Please try again.', false)
    } finally {
      setSaving(false)
      setTimeout(() => setSaveProgress(0), 600)
    }
  }, [items, showToast])

  // ---- Escape key closes ---------------------------------------------------

  useEffect(() => {
    const handler = (e: KeyboardEvent) => {
      if (e.key === 'Escape' && !saving) onClose()
    }
    document.addEventListener('keydown', handler)
    return () => document.removeEventListener('keydown', handler)
  }, [saving, onClose])

  // ---- Folder picker change ------------------------------------------------

  const handleFolderChange = useCallback(
    (e: ChangeEvent<HTMLSelectElement>) => {
      const val = e.target.value
      setSelectedFolder(val === '' ? '' : Number(val))
      if (val !== '') void loadByFolder(Number(val))
    },
    [] // eslint-disable-line react-hooks/exhaustive-deps
  )

  // ---- Missing ALT count (SEO helper) --------------------------------------

  const missingAlt = items.filter((i) => i.alt.trim() === '').length

  // ---- Render --------------------------------------------------------------

  return (
    <div
      role="dialog"
      aria-modal="true"
      aria-label="Bulk Metadata Editor"
      className="fixed inset-0 z-[10000] flex flex-col bg-white dark:bg-slate-900"
      style={{ top: '30px' }}
    >
      {/* ---- Header -------------------------------------------------------- */}
      <div className="flex items-center justify-between gap-4 px-6 py-3 border-b border-slate-200 dark:border-slate-700 bg-white dark:bg-slate-900 shrink-0">
        <div className="flex items-center gap-4">
          <h2 className="text-base font-semibold text-slate-800 dark:text-slate-100">
            Bulk Metadata Editor
          </h2>

          {/* Folder picker */}
          <div className="flex items-center gap-2">
            <label
              htmlFor="bme-folder-picker"
              className="text-xs text-slate-500 dark:text-slate-400 whitespace-nowrap"
            >
              Load folder:
            </label>
            <select
              id="bme-folder-picker"
              value={selectedFolder}
              onChange={handleFolderChange}
              disabled={loading || saving}
              className="text-xs border border-slate-300 dark:border-slate-600 rounded px-2 py-1 bg-white dark:bg-slate-800 text-slate-700 dark:text-slate-200 cursor-pointer"
            >
              <option value="">— select folder —</option>
              {flatFolders.map((f) => (
                <option key={f.id} value={f.id}>
                  {f.name}
                </option>
              ))}
            </select>
          </div>

          {/* SEO warning */}
          {missingAlt > 0 && (
            <span className="text-xs font-medium text-red-600 dark:text-red-400">
              {missingAlt} file{missingAlt !== 1 ? 's' : ''} missing ALT text
            </span>
          )}
        </div>

        <div className="flex items-center gap-2 shrink-0">
          {/* Save button */}
          <button
            type="button"
            onClick={handleSave}
            disabled={saving || loading || items.length === 0}
            className="flex items-center gap-1.5 px-4 py-1.5 text-sm font-medium rounded-md bg-blue-600 hover:bg-blue-700 text-white cursor-pointer transition-colors disabled:opacity-50 disabled:cursor-not-allowed"
          >
            {saving ? <Spinner /> : <IconSave />}
            {saving ? 'Saving…' : 'Save All'}
          </button>

          {/* Close */}
          <button
            type="button"
            onClick={onClose}
            disabled={saving}
            aria-label="Close editor"
            className="p-1.5 rounded-md text-slate-400 hover:text-slate-700 dark:hover:text-slate-200 hover:bg-slate-100 dark:hover:bg-slate-700 cursor-pointer transition-colors disabled:opacity-50"
          >
            <IconX />
          </button>
        </div>
      </div>

      {/* ---- Progress bar -------------------------------------------------- */}
      {saving && (
        <div className="h-1 bg-slate-200 dark:bg-slate-700 shrink-0">
          <div
            className="h-full bg-blue-500 transition-all duration-150"
            style={{ width: `${saveProgress}%` }}
          />
        </div>
      )}

      {/* ---- Toast --------------------------------------------------------- */}
      {toast && (
        <div
          role="status"
          aria-live="polite"
          className={`fixed bottom-6 left-1/2 -translate-x-1/2 px-4 py-2 text-sm text-white rounded-lg shadow-lg z-[10001] ${
            toast.ok ? 'bg-slate-800' : 'bg-red-600'
          }`}
        >
          {toast.msg}
        </div>
      )}

      {/* ---- Table area ---------------------------------------------------- */}
      <div className="flex-1 overflow-auto">
        {loading ? (
          <div className="flex items-center justify-center h-40 gap-3 text-slate-500 dark:text-slate-400">
            <Spinner className="w-6 h-6" />
            <span>Loading files…</span>
          </div>
        ) : items.length === 0 ? (
          <div className="flex items-center justify-center h-40 text-slate-400 dark:text-slate-500 text-sm">
            Select a folder above or open this editor with files selected.
          </div>
        ) : (
          <table className="w-full text-sm border-collapse">
            <thead className="sticky top-0 bg-slate-50 dark:bg-slate-800 z-10">
              <tr className="border-b border-slate-200 dark:border-slate-700">
                <th className="px-3 py-2 text-left font-medium text-slate-600 dark:text-slate-300 w-14">
                  Preview
                </th>
                <th className="px-3 py-2 text-left font-medium text-slate-600 dark:text-slate-300 w-40">
                  Filename
                </th>
                <th className="px-3 py-2 text-left font-medium text-slate-600 dark:text-slate-300">
                  <div className="flex items-center gap-2">
                    ALT Text
                    <CopyAllButton field="alt" onClick={copyFirstToAll} />
                  </div>
                </th>
                <th className="px-3 py-2 text-left font-medium text-slate-600 dark:text-slate-300">
                  <div className="flex items-center gap-2">
                    Title
                    <CopyAllButton field="title" onClick={copyFirstToAll} />
                  </div>
                </th>
                <th className="px-3 py-2 text-left font-medium text-slate-600 dark:text-slate-300">
                  <div className="flex items-center gap-2">
                    Caption
                    <CopyAllButton field="caption" onClick={copyFirstToAll} />
                  </div>
                </th>
                <th className="px-3 py-2 text-left font-medium text-slate-600 dark:text-slate-300">
                  <div className="flex items-center gap-2">
                    Description
                    <CopyAllButton field="description" onClick={copyFirstToAll} />
                  </div>
                </th>
              </tr>
            </thead>
            <tbody>
              {items.map((item, idx) => (
                <MetaRow
                  key={item.id}
                  item={item}
                  isEven={idx % 2 === 0}
                  onUpdate={updateField}
                  onTitleFromFilename={setTitleFromFilename}
                />
              ))}
            </tbody>
          </table>
        )}
      </div>
    </div>
  )
}

// ---------------------------------------------------------------------------
// CopyAllButton
// ---------------------------------------------------------------------------

interface CopyAllButtonProps {
  field: EditableField
  onClick: (field: EditableField) => void
}

const CopyAllButton: React.FC<CopyAllButtonProps> = ({ field, onClick }) => (
  <button
    type="button"
    title={`Copy first row's ${field} to all rows`}
    onClick={() => onClick(field)}
    className="flex items-center gap-0.5 px-1.5 py-0.5 text-[10px] rounded bg-slate-200 dark:bg-slate-700 text-slate-500 dark:text-slate-400 hover:bg-slate-300 dark:hover:bg-slate-600 cursor-pointer transition-colors"
  >
    <IconCopy />
    Copy to all
  </button>
)

// ---------------------------------------------------------------------------
// MetaRow
// ---------------------------------------------------------------------------

interface MetaRowProps {
  item: MetaItem
  isEven: boolean
  onUpdate: (id: number, field: EditableField, value: string) => void
  onTitleFromFilename: (id: number) => void
}

const MetaRow: React.FC<MetaRowProps> = ({ item, isEven, onUpdate, onTitleFromFilename }) => {
  const missingAlt = item.alt.trim() === ''
  const isImage    = item.mime_type.startsWith('image/')

  const rowClass = [
    isEven ? 'bg-white dark:bg-slate-900' : 'bg-slate-50 dark:bg-slate-800/60',
    missingAlt ? 'ring-inset ring-1 ring-red-400/60' : '',
    'border-b border-slate-100 dark:border-slate-700/60',
  ].join(' ')

  return (
    <tr className={rowClass}>
      {/* Thumbnail */}
      <td className="px-3 py-2 align-top">
        <div className="w-10 h-10 rounded overflow-hidden bg-slate-100 dark:bg-slate-700 flex items-center justify-center shrink-0">
          {isImage ? (
            <img
              src={item.thumbnail_url}
              alt=""
              className="w-full h-full object-cover"
              loading="lazy"
            />
          ) : (
            <span className="text-[9px] font-mono text-slate-400 uppercase">
              {item.mime_type.split('/')[1]?.slice(0, 4) ?? 'file'}
            </span>
          )}
        </div>
      </td>

      {/* Filename */}
      <td className="px-3 py-2 align-top">
        <span
          className="block text-xs text-slate-500 dark:text-slate-400 break-all leading-tight"
          title={item.filename}
        >
          {item.filename}
        </span>
      </td>

      {/* ALT */}
      <td className="px-3 py-2 align-top">
        <input
          type="text"
          value={item.alt}
          onChange={(e) => onUpdate(item.id, 'alt', e.target.value)}
          placeholder={missingAlt ? '⚠ Missing ALT' : ''}
          className={[
            'w-full min-w-[140px] text-xs rounded border px-2 py-1 bg-white dark:bg-slate-900 text-slate-700 dark:text-slate-200 focus:outline-none focus:ring-1',
            missingAlt
              ? 'border-red-400 focus:ring-red-400 placeholder-red-400'
              : 'border-slate-300 dark:border-slate-600 focus:ring-blue-500',
          ].join(' ')}
        />
      </td>

      {/* Title */}
      <td className="px-3 py-2 align-top">
        <div className="flex flex-col gap-1">
          <input
            type="text"
            value={item.title}
            onChange={(e) => onUpdate(item.id, 'title', e.target.value)}
            className="w-full min-w-[140px] text-xs rounded border border-slate-300 dark:border-slate-600 px-2 py-1 bg-white dark:bg-slate-900 text-slate-700 dark:text-slate-200 focus:outline-none focus:ring-1 focus:ring-blue-500"
          />
          <button
            type="button"
            onClick={() => onTitleFromFilename(item.id)}
            className="self-start text-[10px] text-blue-600 dark:text-blue-400 hover:underline cursor-pointer"
          >
            ← same as filename
          </button>
        </div>
      </td>

      {/* Caption */}
      <td className="px-3 py-2 align-top">
        <textarea
          value={item.caption}
          rows={2}
          onChange={(e) => onUpdate(item.id, 'caption', e.target.value)}
          className="w-full min-w-[140px] text-xs rounded border border-slate-300 dark:border-slate-600 px-2 py-1 bg-white dark:bg-slate-900 text-slate-700 dark:text-slate-200 resize-y focus:outline-none focus:ring-1 focus:ring-blue-500"
        />
      </td>

      {/* Description */}
      <td className="px-3 py-2 align-top">
        <textarea
          value={item.description}
          rows={2}
          onChange={(e) => onUpdate(item.id, 'description', e.target.value)}
          className="w-full min-w-[140px] text-xs rounded border border-slate-300 dark:border-slate-600 px-2 py-1 bg-white dark:bg-slate-900 text-slate-700 dark:text-slate-200 resize-y focus:outline-none focus:ring-1 focus:ring-blue-500"
        />
      </td>
    </tr>
  )
}

export default BulkMetaEditor
