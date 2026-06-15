/**
 * BreadcrumbBar.tsx
 *
 * Displays the full path to the currently active folder as a clickable
 * breadcrumb trail mounted above the WP media grid.
 *
 * Path format:  All Files  ›  Parent Folder  ›  Current Folder
 *
 * Clicking any crumb:
 *   1. Calls setActiveFolder(id) to sync the sidebar highlight.
 *   2. Navigates the browser URL to include ?mdpai_folder_id=N so WP's
 *      pre_get_posts PHP hook filters the list view on the next render.
 *
 * Clicking "All Files" removes the folder filter from the URL entirely.
 *
 * The component renders null when no folder is active (All Files view)
 * so it takes up zero space in the default state.
 */

import React, { useCallback } from 'react'
import { useFolderStore } from '@/store/folderStore'
import type { MediaPilotFolder } from '@/types'

// ---------------------------------------------------------------------------
// URL navigation helper
// ---------------------------------------------------------------------------

/**
 * Updates the `mdpai_folder_id` URL query param and navigates (page reload).
 * A null folderId removes the param (All Files).
 */
function navigateToFolder(folderId: number | null): void {
  const url = new URL(window.location.href)

  if (folderId === null) {
    url.searchParams.delete('mdpai_folder_id')
  } else {
    url.searchParams.set('mdpai_folder_id', String(folderId))
  }

  window.location.href = url.toString()
}

// ---------------------------------------------------------------------------
// Chevron separator icon
// ---------------------------------------------------------------------------

const ChevronSeparator: React.FC = () => (
  <svg
    viewBox="0 0 16 16"
    fill="none"
    stroke="currentColor"
    strokeWidth={1.5}
    strokeLinecap="round"
    strokeLinejoin="round"
    className="w-3 h-3 text-slate-300 flex-shrink-0"
    aria-hidden="true"
  >
    <path d="M6 4l4 4-4 4" />
  </svg>
)

// ---------------------------------------------------------------------------
// Single crumb button
// ---------------------------------------------------------------------------

interface CrumbProps {
  label: string
  onClick: () => void
  isLast: boolean
}

const Crumb: React.FC<CrumbProps> = ({ label, onClick, isLast }) => (
  <button
    type="button"
    onClick={onClick}
    className={[
      'text-sm transition-colors max-w-[160px] truncate cursor-pointer',
      isLast
        ? 'text-slate-700 font-medium cursor-default pointer-events-none'
        : 'text-blue-600 hover:text-blue-800 hover:underline',
    ].join(' ')}
    aria-current={isLast ? 'page' : undefined}
    title={label}
  >
    {label}
  </button>
)

// ---------------------------------------------------------------------------
// Component
// ---------------------------------------------------------------------------

const BreadcrumbBar: React.FC = () => {
  const activeFolder   = useFolderStore((s) => s.activeFolder)
  const setActiveFolder = useFolderStore((s) => s.setActiveFolder)
  const getAncestors   = useFolderStore((s) => s.getAncestors)
  const getFolderById  = useFolderStore((s) => s.getFolderById)

  // ---- Handlers — must come before any early return (Rules of Hooks) -------

  const handleAllFiles = useCallback(() => {
    setActiveFolder(null)
    navigateToFolder(null)
  }, [setActiveFolder])

  const handleAncestorClick = useCallback(
    (folder: MediaPilotFolder) => {
      setActiveFolder(folder.id)
      navigateToFolder(folder.id)
    },
    [setActiveFolder],
  )

  // ---- Build crumb list ---------------------------------------------------

  // Always null when "All Files" — render nothing.
  if (activeFolder === null) return null

  const currentFolder: MediaPilotFolder | undefined =
    activeFolder > 0 ? getFolderById(activeFolder) : undefined

  // Uncategorized (activeFolder === 0) has no ancestors.
  const ancestors: MediaPilotFolder[] =
    activeFolder > 0 ? getAncestors(activeFolder) : []

  // ---- Render --------------------------------------------------------------

  const crumbs: Array<{ key: string; label: string; onClick: () => void }> = [
    { key: 'all', label: 'All Files', onClick: handleAllFiles },
    ...ancestors.map((f) => ({
      key: String(f.id),
      label: f.name,
      onClick: () => handleAncestorClick(f),
    })),
  ]

  // Final crumb: current folder or "Uncategorized"
  const lastLabel =
    activeFolder === 0
      ? 'Uncategorized'
      : (currentFolder?.name ?? `Folder ${activeFolder}`)

  return (
    <nav
      aria-label="Folder breadcrumb"
      className="flex items-center gap-1.5 px-4 py-2 bg-white border-b border-slate-100 text-sm flex-wrap"
    >
      {crumbs.map((crumb, i) => (
        <React.Fragment key={crumb.key}>
          <Crumb
            label={crumb.label}
            onClick={crumb.onClick}
            isLast={false}
          />
          <ChevronSeparator />
        </React.Fragment>
      ))}

      {/* Current (non-clickable) crumb */}
      <Crumb label={lastLabel} onClick={() => {}} isLast />
    </nav>
  )
}

export default BreadcrumbBar
