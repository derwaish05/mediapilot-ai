/**
 * BulkMovePicker.tsx
 *
 * A modal overlay that lets the user choose a destination folder when moving
 * selected media items in bulk.
 *
 * Renders a flat, indented list of all folders from folderStore.  The user
 * clicks a folder row to select it, then confirms with "Move Here".
 *
 * Props:
 *   onConfirm(folderId)  — called with the chosen folder term ID (0 = Uncategorized).
 *   onClose()            — called when the user dismisses without confirming.
 *
 * Keyboard: Escape closes the picker.
 * Accessibility: role="dialog", focus trapped inside while open.
 */

import React, { useState, useCallback, useEffect, useRef } from 'react'
import { useFolderStore } from '@/store/folderStore'
import type { MediaPilotFolder } from '@/types'

// ---------------------------------------------------------------------------
// Props
// ---------------------------------------------------------------------------

interface BulkMovePickerProps {
  onConfirm: (folderId: number) => void
  onClose: () => void
}

// ---------------------------------------------------------------------------
// Flat row type (built from the nested tree)
// ---------------------------------------------------------------------------

interface FolderRow {
  id: number
  name: string
  depth: number
}

// ---------------------------------------------------------------------------
// Helpers
// ---------------------------------------------------------------------------

/** Recursively flatten the nested folder tree into a depth-annotated list. */
function flattenTree(nodes: MediaPilotFolder[], depth = 0): FolderRow[] {
  const rows: FolderRow[] = []

  for (const node of nodes) {
    rows.push({ id: node.id, name: node.name, depth })

    if (node.children && node.children.length > 0) {
      rows.push(...flattenTree(node.children, depth + 1))
    }
  }

  return rows
}

// ---------------------------------------------------------------------------
// Component
// ---------------------------------------------------------------------------

const BulkMovePicker: React.FC<BulkMovePickerProps> = ({ onConfirm, onClose }) => {
  const tree   = useFolderStore((s) => s.tree)
  const rows   = flattenTree(tree)

  const [selectedId, setSelectedId] = useState<number | null>(null)
  const [filter, setFilter]         = useState('')

  const dialogRef   = useRef<HTMLDivElement>(null)
  const inputRef    = useRef<HTMLInputElement>(null)

  // ---- Focus input on mount -----------------------------------------------

  useEffect(() => {
    inputRef.current?.focus()
  }, [])

  // ---- Escape closes -------------------------------------------------------

  useEffect(() => {
    const handler = (e: KeyboardEvent) => {
      if (e.key === 'Escape') onClose()
    }
    document.addEventListener('keydown', handler)
    return () => document.removeEventListener('keydown', handler)
  }, [onClose])

  // ---- Filtered rows -------------------------------------------------------

  const lc      = filter.toLowerCase()
  const visible = filter
    ? rows.filter((r) => r.name.toLowerCase().includes(lc))
    : rows

  // ---- Handlers ------------------------------------------------------------

  const handleConfirm = useCallback(() => {
    if (selectedId == null) return
    onConfirm(selectedId)
  }, [selectedId, onConfirm])

  const handleUncategorized = useCallback(() => {
    onConfirm(0)
  }, [onConfirm])

  const handleOverlayClick = useCallback(
    (e: React.MouseEvent) => {
      if (dialogRef.current && !dialogRef.current.contains(e.target as Node)) {
        onClose()
      }
    },
    [onClose],
  )

  // ---- Render --------------------------------------------------------------

  return (
    <div
      role="presentation"
      className="fixed inset-0 z-[9999] flex items-center justify-center p-4"
      style={{ background: 'rgba(0,0,0,0.5)' }}
      onMouseDown={handleOverlayClick}
    >
      <div
        ref={dialogRef}
        role="dialog"
        aria-modal="true"
        aria-label="Move files to folder"
        className="bg-white rounded-xl shadow-2xl w-full max-w-sm flex flex-col overflow-hidden"
        style={{ maxHeight: '70vh' }}
        onMouseDown={(e) => e.stopPropagation()}
      >
        {/* Header */}
        <div className="flex items-center justify-between px-4 py-3 border-b border-slate-100">
          <h2 className="text-sm font-semibold text-slate-800">Move to Folder</h2>
          <button
            type="button"
            aria-label="Close"
            className="text-slate-400 hover:text-slate-600 cursor-pointer text-lg leading-none"
            onClick={onClose}
          >
            ×
          </button>
        </div>

        {/* Filter input */}
        <div className="px-3 pt-3 pb-2">
          <input
            ref={inputRef}
            type="search"
            placeholder="Filter folders…"
            value={filter}
            onChange={(e) => {
              setFilter(e.target.value)
              setSelectedId(null)
            }}
            className="w-full text-sm border border-slate-200 rounded-lg px-3 py-1.5 outline-none focus:ring-2 focus:ring-blue-500 focus:border-transparent"
          />
        </div>

        {/* Folder list */}
        <div className="flex-1 overflow-y-auto px-2 pb-2">
          {/* Uncategorized (top of list) */}
          <button
            type="button"
            onClick={() => setSelectedId(0)}
            className={[
              'w-full text-left flex items-center gap-2 px-3 py-1.5 text-sm rounded-lg cursor-pointer transition-colors',
              selectedId === 0
                ? 'bg-blue-100 text-blue-700 font-medium'
                : 'text-slate-500 hover:bg-slate-50',
            ].join(' ')}
          >
            <span className="opacity-50">📁</span>
            <span className="italic">Uncategorized</span>
            {selectedId === 0 && (
              <svg className="w-4 h-4 ml-auto text-blue-600" viewBox="0 0 20 20" fill="currentColor" aria-hidden="true">
                <path fillRule="evenodd" d="M16.707 5.293a1 1 0 010 1.414l-8 8a1 1 0 01-1.414 0l-4-4a1 1 0 011.414-1.414L8 12.586l7.293-7.293a1 1 0 011.414 0z" clipRule="evenodd" />
              </svg>
            )}
          </button>

          {/* Folder rows */}
          {visible.length === 0 && filter && (
            <p className="text-sm text-slate-400 text-center py-4">No folders match "{filter}"</p>
          )}

          {visible.map((row) => (
            <button
              key={row.id}
              type="button"
              onClick={() => setSelectedId(row.id)}
              className={[
                'w-full text-left flex items-center gap-2 px-3 py-1.5 text-sm rounded-lg cursor-pointer transition-colors',
                selectedId === row.id
                  ? 'bg-blue-100 text-blue-700 font-medium'
                  : 'text-slate-700 hover:bg-slate-50',
              ].join(' ')}
              style={{ paddingLeft: `${12 + row.depth * 16}px` }}
            >
              <span aria-hidden="true" style={{ color: '#94a3b8', fontSize: '0.9em' }}>📁</span>
              <span className="truncate">{row.name}</span>
              {selectedId === row.id && (
                <svg className="w-4 h-4 ml-auto shrink-0 text-blue-600" viewBox="0 0 20 20" fill="currentColor" aria-hidden="true">
                  <path fillRule="evenodd" d="M16.707 5.293a1 1 0 010 1.414l-8 8a1 1 0 01-1.414 0l-4-4a1 1 0 011.414-1.414L8 12.586l7.293-7.293a1 1 0 011.414 0z" clipRule="evenodd" />
                </svg>
              )}
            </button>
          ))}
        </div>

        {/* Footer */}
        <div className="flex items-center gap-2 px-4 py-3 border-t border-slate-100 bg-slate-50">
          <button
            type="button"
            className="flex-1 px-4 py-2 text-sm rounded-lg border border-slate-200 bg-white text-slate-700 hover:bg-slate-50 cursor-pointer transition-colors"
            onClick={onClose}
          >
            Cancel
          </button>
          <button
            type="button"
            className="flex-1 px-4 py-2 text-sm rounded-lg bg-blue-600 hover:bg-blue-700 text-white cursor-pointer transition-colors disabled:opacity-40 disabled:cursor-not-allowed"
            onClick={handleConfirm}
            disabled={selectedId == null}
          >
            Move Here
          </button>
        </div>
      </div>
    </div>
  )
}

export default BulkMovePicker
