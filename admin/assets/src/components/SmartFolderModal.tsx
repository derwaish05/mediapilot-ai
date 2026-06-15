/**
 * SmartFolderModal.tsx
 *
 * Rule-builder modal for converting a folder into a Smart Folder.
 *
 * Opened via the `mediapilot:open-smart-folder` custom event dispatched by
 * FolderContextMenu → "Smart Folder Rules…".
 *
 * Persistence: rules are stored as JSON in the `mdpai_smart_rules` term-meta
 * key on the folder taxonomy term, managed via:
 *   GET    /mediapilot/v1/folders/{id}/smart-rules
 *   PUT    /mediapilot/v1/folders/{id}/smart-rules
 *   DELETE /mediapilot/v1/folders/{id}/smart-rules
 *
 * Condition types supported:
 *   tag        — attachment must be tagged with this tag
 *   mime       — attachment MIME type or family matches
 *   date_after — upload date is on or after this date
 *   date_before— upload date is on or before this date
 *
 * Rule mode (AND / OR) controls whether ALL conditions or ANY condition
 * must be satisfied for an attachment to appear in the folder.
 */

import React, {
  useState,
  useEffect,
  useCallback,
  useRef,
} from 'react'
import { createPortal } from 'react-dom'
import type { MediaPilotSmartCondition, MediaPilotSmartRules } from '@/types'
import { TagPicker } from '@/components/TagPicker'
import { useTagStore } from '@/store/tagStore'
import { apiFetch, apiPut, apiDelete } from '@/api/client'

// ---------------------------------------------------------------------------
// Constants
// ---------------------------------------------------------------------------

const MIME_OPTIONS = [
  { label: 'Images (all)',  value: 'image' },
  { label: 'JPEGs',         value: 'image/jpeg' },
  { label: 'PNGs',          value: 'image/png' },
  { label: 'GIFs',          value: 'image/gif' },
  { label: 'WebP',          value: 'image/webp' },
  { label: 'SVGs',          value: 'image/svg+xml' },
  { label: 'Videos (all)',  value: 'video' },
  { label: 'Audio (all)',   value: 'audio' },
  { label: 'PDFs',          value: 'application/pdf' },
  { label: 'Word docs',     value: 'application/msword' },
  { label: 'ZIP archives',  value: 'application/zip' },
]

// ---------------------------------------------------------------------------
// Event types
// ---------------------------------------------------------------------------

interface MediaPilotOpenSmartFolderEvent extends CustomEvent {
  detail: { folderId: number; folderName: string }
}

// ---------------------------------------------------------------------------
// Hook: listen for mediapilot:open-smart-folder event
// ---------------------------------------------------------------------------

function useSmartFolderEvent() {
  const [state, setState] = useState<{
    folderId: number
    folderName: string
  } | null>(null)

  useEffect(() => {
    const handler = (e: Event) => {
      const { folderId, folderName } = (e as MediaPilotOpenSmartFolderEvent).detail
      setState({ folderId, folderName })
    }

    window.addEventListener('mediapilot:open-smart-folder', handler)
    return () => window.removeEventListener('mediapilot:open-smart-folder', handler)
  }, [])

  return { state, close: () => setState(null) }
}

// ---------------------------------------------------------------------------
// Main component (always mounted, shows when state != null)
// ---------------------------------------------------------------------------

const SmartFolderModal: React.FC = () => {
  const { state, close } = useSmartFolderEvent()
  const fetchTags = useTagStore((s) => s.fetchTags)

  const [rules,   setRules]   = useState<MediaPilotSmartRules>({ mode: 'AND', conditions: [] })
  const [loading, setLoading] = useState(false)
  const [saving,  setSaving]  = useState(false)

  // Load existing rules whenever a folder is opened
  useEffect(() => {
    if (!state) return

    fetchTags()
    setLoading(true)

    apiFetch<{ rules: MediaPilotSmartRules | null }>(`folders/${state.folderId}/smart-rules`)
      .then(({ rules: r }) => {
        setRules(r ?? { mode: 'AND', conditions: [] })
      })
      .catch(() => {
        setRules({ mode: 'AND', conditions: [] })
      })
      .finally(() => setLoading(false))
  }, [state, fetchTags])

  // ---- Tag condition IDs (driven by tag-type conditions) --------------------

  const tagConditionIds = rules.conditions
    .filter((c): c is MediaPilotSmartCondition & { tag_id: number } =>
      c.type === 'tag' && c.tag_id != null,
    )
    .map((c) => c.tag_id)

  const handleTagChange = useCallback(
    (ids: number[]) => {
      const nonTag: MediaPilotSmartCondition[] = rules.conditions.filter((c) => c.type !== 'tag')
      const tagConds: MediaPilotSmartCondition[] = ids.map((id) => ({ type: 'tag', tag_id: id }))
      setRules((r) => ({ ...r, conditions: [...nonTag, ...tagConds] }))
    },
    [rules.conditions],
  )

  // ---- Non-tag conditions ---------------------------------------------------

  const addMimeCondition = useCallback(() => {
    setRules((r) => ({
      ...r,
      conditions: [...r.conditions, { type: 'mime', mime: 'image' }],
    }))
  }, [])

  const addDateCondition = useCallback(
    (type: 'date_after' | 'date_before') => {
      setRules((r) => ({
        ...r,
        conditions: [...r.conditions, { type, date: '' }],
      }))
    },
    [],
  )

  const updateCondition = useCallback(
    (index: number, patch: Partial<MediaPilotSmartCondition>) => {
      setRules((r) => {
        const next = [...r.conditions]
        next[index] = { ...next[index], ...patch } as MediaPilotSmartCondition
        return { ...r, conditions: next }
      })
    },
    [],
  )

  const removeCondition = useCallback((index: number) => {
    setRules((r) => ({
      ...r,
      conditions: r.conditions.filter((_, i) => i !== index),
    }))
  }, [])

  // ---- Persistence ---------------------------------------------------------

  const handleSave = useCallback(async () => {
    if (!state) return
    setSaving(true)
    try {
      await apiPut(`folders/${state.folderId}/smart-rules`, { rules })
      close()
    } finally {
      setSaving(false)
    }
  }, [state, rules, close])

  const handleRemoveSmart = useCallback(async () => {
    if (!state) return
    setSaving(true)
    try {
      await apiDelete(`folders/${state.folderId}/smart-rules`)
      close()
    } finally {
      setSaving(false)
    }
  }, [state, close])

  // ---- Don't render when closed ---------------------------------------------

  if (!state) return null

  const nonTagConditions = rules.conditions
    .map((c, i) => ({ cond: c, idx: i }))
    .filter(({ cond }) => cond.type !== 'tag')

  const hasConditions = rules.conditions.length > 0

  return createPortal(
    <div
      className="fixed inset-0 z-[10001] flex items-center justify-center"
      role="dialog"
      aria-modal="true"
      aria-label="Smart Folder Rules"
    >
      {/* Backdrop */}
      <div className="absolute inset-0 bg-black/50" onClick={close} />

      {/* Panel */}
      <div className="relative bg-white rounded-xl shadow-2xl w-full max-w-lg mx-4 p-6 max-h-[90vh] flex flex-col">
        {/* Header */}
        <div className="mb-5 shrink-0">
          <h2 className="text-base font-semibold text-slate-900">Smart Folder Rules</h2>
          <p className="text-sm text-slate-500 mt-0.5">
            <span className="font-medium text-slate-700">"{state.folderName}"</span> will
            auto-populate with files matching these rules.
          </p>
        </div>

        {/* Body — scrollable */}
        <div className="flex-1 overflow-y-auto min-h-0">
          {loading ? (
            <div className="py-10 text-center text-slate-400 text-sm">Loading…</div>
          ) : (
            <>
              {/* Match mode */}
              <div className="flex items-center gap-4 mb-5">
                <span className="text-sm font-medium text-slate-600 shrink-0">Match</span>
                {(['AND', 'OR'] as const).map((m) => (
                  <label
                    key={m}
                    className="flex items-center gap-1.5 text-sm text-slate-600 cursor-pointer select-none"
                  >
                    <input
                      type="radio"
                      name="smart-mode"
                      value={m}
                      checked={rules.mode === m}
                      onChange={() => setRules((r) => ({ ...r, mode: m }))}
                      className="accent-blue-500"
                    />
                    <strong>{m === 'AND' ? 'all' : 'any'}</strong> conditions
                  </label>
                ))}
              </div>

              {/* Tag conditions */}
              <div className="mb-5">
                <label className="block text-xs font-semibold text-slate-400 uppercase tracking-wider mb-1.5">
                  Tags
                </label>
                <TagPicker
                  selectedTagIds={tagConditionIds}
                  onChange={handleTagChange}
                  allowCreate
                  placeholder="Add tag conditions…"
                />
              </div>

              {/* Non-tag conditions */}
              {nonTagConditions.length > 0 && (
                <div className="mb-3 space-y-2">
                  <label className="block text-xs font-semibold text-slate-400 uppercase tracking-wider mb-1.5">
                    Additional conditions
                  </label>
                  {nonTagConditions.map(({ cond, idx }) => (
                    <div key={idx} className="flex items-center gap-2">
                      <span className="text-sm text-slate-500 w-24 shrink-0">
                        {cond.type === 'mime'
                          ? 'File type'
                          : cond.type === 'date_after'
                          ? 'Uploaded after'
                          : 'Uploaded before'}
                      </span>

                      {cond.type === 'mime' ? (
                        <select
                          className="flex-1 text-sm border border-slate-300 rounded-md px-2 py-1 focus:outline-none focus:ring-1 focus:ring-blue-500"
                          value={cond.mime ?? 'image'}
                          onChange={(e) =>
                            updateCondition(idx, { mime: e.target.value })
                          }
                        >
                          {MIME_OPTIONS.map((o) => (
                            <option key={o.value} value={o.value}>
                              {o.label}
                            </option>
                          ))}
                        </select>
                      ) : (
                        <input
                          type="date"
                          className="flex-1 text-sm border border-slate-300 rounded-md px-2 py-1 focus:outline-none focus:ring-1 focus:ring-blue-500"
                          value={cond.date ?? ''}
                          onChange={(e) =>
                            updateCondition(idx, { date: e.target.value })
                          }
                        />
                      )}

                      <button
                        type="button"
                        className="shrink-0 text-slate-400 hover:text-red-500 cursor-pointer p-1 rounded transition-colors"
                        onClick={() => removeCondition(idx)}
                        aria-label="Remove condition"
                      >
                        <svg
                          viewBox="0 0 16 16"
                          fill="none"
                          stroke="currentColor"
                          strokeWidth="1.5"
                          strokeLinecap="round"
                          className="w-4 h-4"
                          aria-hidden="true"
                        >
                          <path d="M4 4l8 8M12 4l-8 8" />
                        </svg>
                      </button>
                    </div>
                  ))}
                </div>
              )}

              {/* Add condition buttons */}
              <div className="flex flex-wrap gap-2 mt-4">
                <button
                  type="button"
                  className="text-xs text-blue-600 hover:text-blue-700 border border-blue-200 hover:border-blue-400 rounded-full px-2.5 py-1 cursor-pointer transition-colors"
                  onClick={addMimeCondition}
                >
                  + File type
                </button>
                <button
                  type="button"
                  className="text-xs text-blue-600 hover:text-blue-700 border border-blue-200 hover:border-blue-400 rounded-full px-2.5 py-1 cursor-pointer transition-colors"
                  onClick={() => addDateCondition('date_after')}
                >
                  + After date
                </button>
                <button
                  type="button"
                  className="text-xs text-blue-600 hover:text-blue-700 border border-blue-200 hover:border-blue-400 rounded-full px-2.5 py-1 cursor-pointer transition-colors"
                  onClick={() => addDateCondition('date_before')}
                >
                  + Before date
                </button>
              </div>
            </>
          )}
        </div>

        {/* Footer */}
        <div className="flex justify-between items-center pt-5 mt-auto shrink-0 border-t border-slate-100">
          <button
            type="button"
            className="text-sm text-red-500 hover:text-red-700 cursor-pointer disabled:opacity-40 transition-colors"
            onClick={handleRemoveSmart}
            disabled={saving || loading}
          >
            Remove smart rules
          </button>

          <div className="flex gap-2">
            <button
              type="button"
              className="px-4 py-2 text-sm rounded-md text-slate-600 hover:bg-slate-100 cursor-pointer transition-colors"
              onClick={close}
            >
              Cancel
            </button>
            <button
              type="button"
              className="px-4 py-2 text-sm rounded-md bg-blue-600 text-white hover:bg-blue-700 disabled:opacity-50 cursor-pointer transition-colors flex items-center gap-2"
              onClick={handleSave}
              disabled={saving || loading || !hasConditions}
            >
              {saving && (
                <span className="w-3.5 h-3.5 border-2 border-white/40 border-t-white rounded-full animate-spin" />
              )}
              {saving ? 'Saving…' : 'Save Rules'}
            </button>
          </div>
        </div>
      </div>
    </div>,
    document.body,
  )
}

export default SmartFolderModal
