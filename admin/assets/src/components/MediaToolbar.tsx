/**
 * MediaToolbar.tsx
 *
 * Sort controls and file search mounted above the WP media grid via a React
 * Portal into #mediapilot-toolbar-root (injected + repositioned by the PHP bridge).
 *
 * Sort / order changes navigate immediately (page reload) so WP's
 * pre_get_posts picks up the new params server-side:
 *   ?mdpai_sort=name&mdpai_order=asc
 *
 * The search input is debounced (600 ms) then navigates with ?mdpai_search=…
 * Pressing Enter navigates immediately; Escape clears and navigates.
 *
 * On mount the component seeds the search field from the current URL so the
 * value persists after a page reload.
 */

import React, { useCallback, useEffect, useState } from 'react'
import { useUiStore } from '@/store/uiStore'
import type { MediaPilotSortField, MediaPilotSortDir } from '@/types'

// ---------------------------------------------------------------------------
// Constants
// ---------------------------------------------------------------------------

const SORT_OPTIONS: { value: MediaPilotSortField; label: string }[] = [
  { value: 'date',     label: 'Date Uploaded' },
  { value: 'modified', label: 'Date Modified' },
  { value: 'name',     label: 'Name' },
  { value: 'author',   label: 'Author' },
  { value: 'size',     label: 'File Size' },
]

// ---------------------------------------------------------------------------
// Backbone sort helper — no page reload
// ---------------------------------------------------------------------------

// Maps MediaPilot sort field names to WP backbone / WP_Query orderby values.
const ORDERBY_MAP: Record<MediaPilotSortField, string> = {
  date:     'date',
  modified: 'modified',
  name:     'title',
  author:   'author',
  size:     'filesize', // stored as _mdpai_filesize meta; handled by filterAjaxBySortSize
}

function dispatchSortChange(field: MediaPilotSortField, dir: MediaPilotSortDir): void {
  window.dispatchEvent(
    new CustomEvent('mediapilot:sort-change', {
      detail: {
        orderby: ORDERBY_MAP[field] ?? 'date',
        order:   dir.toUpperCase(),
      },
    }),
  )
}

// ---------------------------------------------------------------------------
// Inline SVG icons
// ---------------------------------------------------------------------------

const AscIcon: React.FC<{ className?: string }> = ({ className }) => (
  <svg
    viewBox="0 0 16 16"
    fill="none"
    stroke="currentColor"
    strokeWidth={1.8}
    strokeLinecap="round"
    strokeLinejoin="round"
    className={className}
    aria-hidden="true"
  >
    <path d="M4 10V4M4 4L2 6M4 4L6 6" />
    <path d="M8 6h6M8 9h4M8 12h2" />
  </svg>
)

const DescIcon: React.FC<{ className?: string }> = ({ className }) => (
  <svg
    viewBox="0 0 16 16"
    fill="none"
    stroke="currentColor"
    strokeWidth={1.8}
    strokeLinecap="round"
    strokeLinejoin="round"
    className={className}
    aria-hidden="true"
  >
    <path d="M4 6v6M4 12L2 10M4 12L6 10" />
    <path d="M8 6h6M8 9h4M8 12h2" />
  </svg>
)

// ---------------------------------------------------------------------------
// Component
// ---------------------------------------------------------------------------

const MediaToolbar: React.FC = () => {
  const sortField          = useUiStore((s) => s.sortField)
  const sortDir            = useUiStore((s) => s.sortDir)
  const setSortField       = useUiStore((s) => s.setSortField)
  const setSortDir         = useUiStore((s) => s.setSortDir)

  const [allSelected, setAllSelected] = useState(false)

  // ---- Sync allSelected state with external selection changes -------------

  useEffect(() => {
    function onSelectionChange() {
      const all      = document.querySelectorAll('.attachment')
      const selected = document.querySelectorAll('.attachment.selected')
      setAllSelected(all.length > 0 && selected.length === all.length)
    }
    window.addEventListener('mediapilot:selection-change', onSelectionChange)
    return () => window.removeEventListener('mediapilot:selection-change', onSelectionChange)
  }, [])

  // ---- Handlers -----------------------------------------------------------

  const handleSortChange = useCallback(
    (e: React.ChangeEvent<HTMLSelectElement>) => {
      const field = e.target.value as MediaPilotSortField
      setSortField(field)
      dispatchSortChange(field, sortDir)
    },
    [setSortField, sortDir],
  )

  const handleDirToggle = useCallback(() => {
    const newDir: MediaPilotSortDir = sortDir === 'asc' ? 'desc' : 'asc'
    setSortDir(newDir)
    dispatchSortChange(sortField, newDir)
  }, [setSortDir, sortField, sortDir])

  const handleSelectAllToggle = useCallback(() => {
    const all = document.querySelectorAll('.attachment')
    if (allSelected) {
      all.forEach((el) => el.classList.remove('selected'))
    } else {
      all.forEach((el) => el.classList.add('selected'))
    }
    window.dispatchEvent(
      new CustomEvent('mediapilot:selection-change', { detail: { ids: [] } }),
    )
  }, [allSelected])

  // ---- Render -------------------------------------------------------------

  return (
    <div className="flex items-center gap-2 px-3 py-1.5 bg-white border-b border-slate-100 flex-wrap">
      {/* Sort label — hidden visually, available to SR */}
      <span className="text-xs text-slate-500 font-medium select-none whitespace-nowrap">
        Sort:
      </span>

      {/* Sort field dropdown */}
      <label htmlFor="mediapilot-sort-field" className="sr-only">
        Sort by
      </label>
      <select
        id="mediapilot-sort-field"
        value={sortField}
        onChange={handleSortChange}
        className="text-xs border border-slate-200 rounded px-2 py-1 bg-white text-slate-700 cursor-pointer hover:border-slate-300 focus:outline-none focus:ring-1 focus:ring-blue-400"
      >
        {SORT_OPTIONS.map((opt) => (
          <option key={opt.value} value={opt.value}>
            {opt.label}
          </option>
        ))}
      </select>

      {/* Sort direction toggle */}
      <button
        type="button"
        onClick={handleDirToggle}
        className="flex items-center gap-1 text-xs border border-slate-200 rounded px-2 py-1 bg-white text-slate-600 hover:border-slate-300 hover:bg-slate-50 focus:outline-none focus:ring-1 focus:ring-blue-400 transition-colors"
        title={sortDir === 'asc' ? 'Ascending — click to switch to Descending' : 'Descending — click to switch to Ascending'}
        aria-label={`Sort direction: ${sortDir === 'asc' ? 'ascending' : 'descending'}. Click to toggle.`}
        aria-pressed={sortDir === 'desc'}
      >
        {sortDir === 'asc' ? (
          <AscIcon className="w-3.5 h-3.5" />
        ) : (
          <DescIcon className="w-3.5 h-3.5" />
        )}
        <span>{sortDir === 'asc' ? 'Asc' : 'Desc'}</span>
      </button>

      {/* Spacer */}
      <div className="flex-1" />

      {/* Select All / Deselect All toggle */}
      <button
        type="button"
        onClick={handleSelectAllToggle}
        className="text-xs border border-slate-200 rounded px-2 py-1 bg-white text-slate-600 hover:border-slate-300 hover:bg-slate-50 focus:outline-none focus:ring-1 focus:ring-blue-400 transition-colors whitespace-nowrap"
        aria-label={allSelected ? 'Deselect all visible media items' : 'Select all visible media items'}
      >
        {allSelected ? 'Deselect All' : 'Select All'}
      </button>
    </div>
  )
}

export default MediaToolbar
