/**
 * FolderSidebar.tsx
 *
 * The primary sidebar panel for the MediaPilot plugin. Injected into the WP Media
 * Library DOM via a React portal (see App.tsx / main.tsx).
 *
 * Features:
 *   A. Resizable panel  — drag handle on right edge, width saved to uiStore
 *   B. Collapsible      — chevron toggle, shrinks to 40 px when collapsed
 *   C. Header           — "Folders" label + "New Folder" button
 *   D. Search           — SearchBox with search icon
 *   E. Virtual nodes    — "All Files" (null) + "Uncategorized" (0) always visible
 *   F. Sort toggle      — A→Z / Z→A on top-level folders
 *   G. Folder tree      — scrollable FolderTree with filtered/sorted folders
 *   H. Footer           — total folder count
 *   I. Context menu     — FolderContextMenu always mounted
 *   J. New folder input — inline input inside sidebar
 */

import React, {
  useState,
  useCallback,
  useEffect,
  useMemo,
  memo,
} from 'react'
import FolderTree from '@/components/FolderTree'
import FolderContextMenu from '@/components/FolderContextMenu'
import SearchBox from '@/components/SearchBox'
import { useFolderStore } from '@/store/folderStore'
import { useUiStore } from '@/store/uiStore'
import { useResizable } from '@/hooks/useResizable'
import { useFolderFilter } from '@/hooks/useFolderFilter'
import type { MediaPilotFolder } from '@/types'

// ---------------------------------------------------------------------------
// Design tokens
// ---------------------------------------------------------------------------

const C = {
  blue:      '#2563eb',
  blueHover: '#1d4ed8',
  blueLight: '#eff6ff',
  gray50:    '#f9fafb',
  gray100:   '#f3f4f6',
  gray200:   '#e5e7eb',
  gray400:   '#9ca3af',
  gray500:   '#6b7280',
  gray700:   '#374151',
  gray900:   '#111827',
  white:     '#ffffff',
} as const

// ---------------------------------------------------------------------------
// SVG icons
// ---------------------------------------------------------------------------

const FolderIcon = ({ color = C.gray400 }: { color?: string }) => (
  <svg width="18" height="18" viewBox="0 0 20 20" fill={color} aria-hidden="true">
    <path d="M2 6a2 2 0 012-2h4l2 2h6a2 2 0 012 2v6a2 2 0 01-2 2H4a2 2 0 01-2-2V6z" />
  </svg>
)

const AllFilesIcon = () => (
  <svg width="16" height="16" viewBox="0 0 20 20" fill="currentColor" aria-hidden="true">
    <rect x="2" y="2" width="6" height="6" rx="1" />
    <rect x="12" y="2" width="6" height="6" rx="1" />
    <rect x="2" y="12" width="6" height="6" rx="1" />
    <rect x="12" y="12" width="6" height="6" rx="1" />
  </svg>
)

const UncategorizedIcon = () => (
  <svg width="16" height="16" viewBox="0 0 20 20" fill="currentColor" aria-hidden="true">
    <path d="M2 6a2 2 0 012-2h4l2 2h6a2 2 0 012 2v6a2 2 0 01-2 2H4a2 2 0 01-2-2V6z" opacity="0.45" />
  </svg>
)

const UnusedIcon = () => (
  <svg width="16" height="16" viewBox="0 0 20 20" fill="currentColor" aria-hidden="true">
    <path fillRule="evenodd" d="M9 2a1 1 0 00-.894.553L7.382 4H4a1 1 0 000 2v10a2 2 0 002 2h8a2 2 0 002-2V6a1 1 0 100-2h-3.382l-.724-1.447A1 1 0 0011 2H9zM7 8a1 1 0 012 0v6a1 1 0 11-2 0V8zm5-1a1 1 0 00-1 1v6a1 1 0 102 0V8a1 1 0 00-1-1z" clipRule="evenodd" />
  </svg>
)

/** Sentinel value for the "Unused Media" virtual node */
const UNUSED_FOLDER_ID = -1 as const


const ChevronRightIcon = () => (
  <svg width="14" height="14" viewBox="0 0 16 16" fill="none" stroke="currentColor" strokeWidth="2" strokeLinecap="round" strokeLinejoin="round" aria-hidden="true">
    <path d="M6 4l4 4-4 4" />
  </svg>
)

const ChevronLeftIcon = () => (
  <svg width="14" height="14" viewBox="0 0 16 16" fill="none" stroke="currentColor" strokeWidth="2" strokeLinecap="round" strokeLinejoin="round" aria-hidden="true">
    <path d="M10 4L6 8l4 4" />
  </svg>
)

const NewFolderIcon = () => (
  <svg width="14" height="14" viewBox="0 0 20 20" fill="currentColor" aria-hidden="true">
    <path d="M2 6a2 2 0 012-2h4l2 2h6a2 2 0 012 2v6a2 2 0 01-2 2H4a2 2 0 01-2-2V6z" opacity="0.6" />
    <path d="M10 9v6M7 12h6" stroke="white" strokeWidth="1.5" strokeLinecap="round" />
  </svg>
)

const AdminIcon = () => (
  <svg width="12" height="12" viewBox="0 0 16 16" fill="currentColor" aria-hidden="true">
    <path d="M8 1a3 3 0 100 6 3 3 0 000-6zM3 14a5 5 0 0110 0H3z" />
  </svg>
)

// ---------------------------------------------------------------------------
// Helpers
// ---------------------------------------------------------------------------

function countFolders(folders: MediaPilotFolder[]): number {
  let total = 0
  for (const folder of folders) {
    total += 1
    if (folder.children.length > 0) total += countFolders(folder.children)
  }
  return total
}

// ---------------------------------------------------------------------------
// Count badge
// ---------------------------------------------------------------------------

const Badge = ({ count }: { count: number }) => (
  <span className="mediapilot-badge">{count > 99 ? '99+' : count}</span>
)

// ---------------------------------------------------------------------------
// VirtualNode — "All Files" and "Uncategorized" pinned rows
// ---------------------------------------------------------------------------

interface VirtualNodeProps {
  id: number | null
  label: string
  icon: React.ReactNode
  count?: number
  activeFolder: number | null
  onClick: () => void
}

const VirtualNode = memo(function VirtualNode({
  id,
  label,
  icon,
  count,
  activeFolder,
  onClick,
}: VirtualNodeProps) {
  const isActive = activeFolder === id

  return (
    <div
      role="treeitem"
      aria-selected={isActive}
      tabIndex={0}
      onClick={onClick}
      onKeyDown={(e) => {
        if (e.key === 'Enter' || e.key === ' ') {
          e.preventDefault()
          onClick()
        }
      }}
      className={`mediapilot-virtual-row${isActive ? ' mediapilot-virtual-row--active' : ''}`}
    >
      <span className="flex-shrink-0" style={{ color: isActive ? C.blue : C.gray500 }}>
        {icon}
      </span>
      <span className="flex-1 truncate">{label}</span>
      {count !== undefined && count > 0 && <Badge count={count} />}
    </div>
  )
})

// ---------------------------------------------------------------------------
// FolderSidebar
// ---------------------------------------------------------------------------

export const FolderSidebar: React.FC = () => {
  // ---- Store slices --------------------------------------------------------

  const tree            = useFolderStore((s) => s.tree)
  const flatMap         = useFolderStore((s) => s.flatMap)
  const activeFolder    = useFolderStore((s) => s.activeFolder)
  const setActiveFolder = useFolderStore((s) => s.setActiveFolder)
  const createFolder    = useFolderStore((s) => s.createFolder)
  const deleteFolder    = useFolderStore((s) => s.deleteFolder)
  const clipboard       = useFolderStore((s) => s.clipboard)
  const clearClipboard  = useFolderStore((s) => s.clearClipboard)
  const getFolderById   = useFolderStore((s) => s.getFolderById)

  const sidebarWidth       = useUiStore((s) => s.sidebarWidth)
  const sidebarCollapsed   = useUiStore((s) => s.sidebarCollapsed)
  const setSidebarWidth    = useUiStore((s) => s.setSidebarWidth)
  const toggleSidebar      = useUiStore((s) => s.toggleSidebar)
  const expandFolder       = useUiStore((s) => s.expandFolder)
  const adminFolderOverride    = useUiStore((s) => s.adminFolderOverride)
  const setAdminFolderOverride = useUiStore((s) => s.setAdminFolderOverride)

  const showAdminToggle =
    typeof window !== 'undefined' &&
    window.MediaPilotConfig?.folderMode === 'per_user' &&
    window.MediaPilotConfig?.isAdmin === true

  // ---- Local state ---------------------------------------------------------

  const [searchQuery, setSearchQuery]         = useState<string>('')
  const [sortDir, setSortDir]                 = useState<'none' | 'az' | 'za'>('none')
  const [showNewFolderInput, setShowNewFolderInput] = useState<boolean>(false)
  const [newFolderName, setNewFolderName]     = useState<string>('')
  const [newFolderParentId, setNewFolderParentId] = useState<number>(0)
  const [newFolderError, setNewFolderError]   = useState<string>('')
  const [isCreating, setIsCreating]           = useState<boolean>(false)

  // ---- Resizable -----------------------------------------------------------

  const { width: resizeWidth, isResizing, handleMouseDown } = useResizable({
    initialWidth: sidebarWidth,
    minWidth: 160,
    maxWidth: 480,
    onResize: setSidebarWidth,
  })

  // ---- Filter + sort -------------------------------------------------------

  const filteredTree   = useFolderFilter(tree, searchQuery)
  const displayFolders = useMemo<MediaPilotFolder[]>(() => {
    if (sortDir === 'none') return filteredTree

    const compareFn = (a: MediaPilotFolder, b: MediaPilotFolder) => {
      const cmp = a.name.localeCompare(b.name, undefined, { sensitivity: 'base' })
      return sortDir === 'za' ? -cmp : cmp
    }

    const sortRecursive = (folders: MediaPilotFolder[]): MediaPilotFolder[] =>
      [...folders]
        .sort(compareFn)
        .map((f) => ({ ...f, children: f.children.length > 0 ? sortRecursive(f.children) : f.children }))

    return sortRecursive(filteredTree)
  }, [filteredTree, sortDir])

  const totalFolderCount = Object.keys(flatMap).length

  // ---- New folder ----------------------------------------------------------

  const handleNewFolderClick = useCallback(() => {
    setNewFolderParentId(activeFolder ?? 0)
    setNewFolderName('')
    setShowNewFolderInput(true)
  }, [activeFolder])

  const cancelNewFolder = useCallback(() => {
    setShowNewFolderInput(false)
    setNewFolderName('')
    setNewFolderError('')
  }, [])

  const confirmNewFolder = useCallback(() => {
    const name = newFolderName.trim()
    if (!name) return
    setNewFolderError('')
    setIsCreating(true)
    createFolder(name, newFolderParentId)
      .then(() => {
        setShowNewFolderInput(false)
        setNewFolderName('')
        setNewFolderError('')
        if (newFolderParentId !== 0) expandFolder(newFolderParentId)
      })
      .catch((err: unknown) => {
        const msg = err instanceof Error ? err.message : 'Failed to create folder.'
        setNewFolderError(msg)
      })
      .finally(() => {
        setIsCreating(false)
      })
  }, [newFolderName, newFolderParentId, createFolder, expandFolder])

  const handleNewFolderKeyDown = useCallback(
    (e: React.KeyboardEvent<HTMLInputElement>) => {
      if (e.key === 'Enter') confirmNewFolder()
      else if (e.key === 'Escape') cancelNewFolder()
    },
    [confirmNewFolder, cancelNewFolder],
  )

  // ---- Escape clears clipboard ---------------------------------------------

  useEffect(() => {
    if (!clipboard) return
    const handler = (e: KeyboardEvent) => {
      if (e.key === 'Escape') clearClipboard()
    }
    document.addEventListener('keydown', handler)
    return () => document.removeEventListener('keydown', handler)
  }, [clipboard, clearClipboard])

  // ---- Sync activeFolder → WP backbone media grid -------------------------

  useEffect(() => {
    window.dispatchEvent(
      new CustomEvent('mediapilot:folder-selected', {
        detail: {
          folderId:    activeFolder === UNUSED_FOLDER_ID ? null : activeFolder,
          unusedOnly:  activeFolder === UNUSED_FOLDER_ID,
        },
      })
    )
  }, [activeFolder])

  // ---- Context menu events -------------------------------------------------

  useEffect(() => {
    const handleNewSubfolder = (e: Event) => {
      const detail = (e as CustomEvent<{ folderId: number }>).detail
      setNewFolderParentId(detail?.folderId ?? 0)
      setNewFolderName('')
      setShowNewFolderInput(true)
    }
    window.addEventListener('mediapilot:new-subfolder', handleNewSubfolder)
    return () => window.removeEventListener('mediapilot:new-subfolder', handleNewSubfolder)
  }, [])

  useEffect(() => {
    const handleDeleteFolder = (e: Event) => {
      const { folderId } = (e as CustomEvent<{ folderId: number }>).detail
      if (!window.confirm('Delete this folder and all its subfolders? Images will not be deleted.')) return
      deleteFolder(folderId, true)
        .then(() => { announce('Folder deleted.') })
        .catch(() => {/* error surfaces via store.error */})
    }
    window.addEventListener('mediapilot:delete-folder', handleDeleteFolder)
    return () => window.removeEventListener('mediapilot:delete-folder', handleDeleteFolder)
  }, [deleteFolder])

  // ---- aria-live announcements (screen readers) ----------------------------

  const liveRef = React.useRef<HTMLDivElement>(null)

  const announce = useCallback((message: string) => {
    if (!liveRef.current) return
    liveRef.current.textContent = ''
    // Force a DOM update so the screen reader fires even if text is the same
    requestAnimationFrame(() => {
      if (liveRef.current) liveRef.current.textContent = message
    })
  }, [])

  useEffect(() => {
    const handler = (e: Event) => {
      const msg = (e as CustomEvent<{ message: string }>).detail?.message
      if (msg) announce(msg)
    }
    window.addEventListener('mediapilot:announce', handler)
    return () => window.removeEventListener('mediapilot:announce', handler)
  }, [announce])

  // ---- Virtual node clicks -------------------------------------------------

  const handleSelectAllFiles      = useCallback(() => setActiveFolder(null),          [setActiveFolder])
  const handleSelectUncategorized = useCallback(() => setActiveFolder(0),             [setActiveFolder])
  const handleSelectUnused        = useCallback(() => setActiveFolder(UNUSED_FOLDER_ID), [setActiveFolder])
  const handleSearch              = useCallback((q: string) => setSearchQuery(q), [])

  // ---- Render --------------------------------------------------------------

  return (
    <aside
      id="mediapilot-sidebar"
      role="navigation"
      aria-label="Media folders sidebar"
      style={{
        width:      sidebarCollapsed ? 40 : resizeWidth,
        background: 'transparent',
        marginTop: '13px',
      }}
      className="relative flex h-full overflow-hidden select-none transition-[width] duration-200"
    >
      {/* -------------------------------------------------------------------- */}
      {/* Sidebar content container                                             */}
      {/* -------------------------------------------------------------------- */}
      {!sidebarCollapsed && (
        <div className="flex flex-col flex-1 overflow-hidden" style={{ background: C.white }}>
          {/* ---------------------------------------------------------------- */}
          {/* Header                                                            */}
          {/* ---------------------------------------------------------------- */}
          <div
            className="flex items-center justify-between flex-shrink-0"
            style={{
              padding:      '14px 12px 10px',
              borderBottom: `1px solid ${C.gray200}`,
            }}
          >
            <span
              style={{
                fontWeight: 700,
                fontSize:   16,
                color:      C.gray900,
                letterSpacing: '-0.01em',
              }}
            >
              Folders
            </span>

            <button
              type="button"
              onClick={handleNewFolderClick}
              title="New Folder"
              aria-label="New Folder"
              style={{
                display:      'inline-flex',
                alignItems:   'center',
                gap:          6,
                background:   C.blue,
                color:        C.white,
                border:       'none',
                borderRadius: 6,
                fontWeight:   600,
                fontSize:     13,
                padding:      '6px 12px',
                cursor:       'pointer',
                transition:   'background 0.15s',
                lineHeight:   1.3,
              }}
              onMouseEnter={(e) => { (e.currentTarget as HTMLButtonElement).style.background = C.blueHover }}
              onMouseLeave={(e) => { (e.currentTarget as HTMLButtonElement).style.background = C.blue }}
            >
              <NewFolderIcon />
              New Folder
            </button>
          </div>

          {/* ---------------------------------------------------------------- */}
          {/* First search — above virtual nodes                               */}
          {/* ---------------------------------------------------------------- */}
          <div
            className="flex-shrink-0"
            style={{ padding: '8px 10px', borderBottom: `1px solid ${C.gray200}` }}
          >
            <SearchBox
              onSearch={handleSearch}
              placeholder="Search folders..."
              className="mediapilot-search-input"
            />
          </div>

          {/* ---------------------------------------------------------------- */}
          {/* Virtual nodes — All Files + Uncategorized                        */}
          {/* ---------------------------------------------------------------- */}
          <div
            className="flex-shrink-0"
            style={{ padding: '4px 0', borderBottom: `1px solid ${C.gray200}` }}
          >
            <VirtualNode
              id={null}
              label="All Files"
              icon={<AllFilesIcon />}
              activeFolder={activeFolder}
              onClick={handleSelectAllFiles}
            />
            <VirtualNode
              id={0}
              label="Uncategorized"
              icon={<UncategorizedIcon />}
              activeFolder={activeFolder}
              onClick={handleSelectUncategorized}
            />
            <VirtualNode
              id={UNUSED_FOLDER_ID}
              label="Unused Media"
              icon={<UnusedIcon />}
              activeFolder={activeFolder}
              onClick={handleSelectUnused}
            />
          </div>

          {/* ---------------------------------------------------------------- */}
          {/* Admin toggle — per_user mode only                                */}
          {/* ---------------------------------------------------------------- */}
          {showAdminToggle && (
            <div style={{ padding: '6px 10px 0', flexShrink: 0 }}>
              <button
                type="button"
                onClick={() => setAdminFolderOverride(!adminFolderOverride)}
                style={{
                  display:      'inline-flex',
                  alignItems:   'center',
                  gap:          6,
                  width:        '100%',
                  background:   adminFolderOverride ? C.blueLight : C.gray100,
                  color:        adminFolderOverride ? C.blue : C.gray700,
                  border:       `1px solid ${adminFolderOverride ? C.blue : C.gray200}`,
                  borderRadius: 6,
                  fontWeight:   500,
                  fontSize:     12,
                  padding:      '5px 10px',
                  cursor:       'pointer',
                }}
              >
                <AdminIcon />
                <span className="truncate">
                  {adminFolderOverride ? 'Shared tree (admin view)' : 'My folders'}
                </span>
              </button>
            </div>
          )}

          {/* ---------------------------------------------------------------- */}
          {/* New folder input panel                                            */}
          {/* ---------------------------------------------------------------- */}
          {showNewFolderInput && (
            <div className="mediapilot-new-folder-panel flex-shrink-0">
              <input
                autoFocus
                type="text"
                value={newFolderName}
                onChange={(e) => { setNewFolderName(e.target.value); setNewFolderError('') }}
                onKeyDown={handleNewFolderKeyDown}
                placeholder="Folder name..."
                autoComplete="off"
                spellCheck={false}
                disabled={isCreating}
              />
              {newFolderError && (
                <p style={{ margin: '6px 0 0', fontSize: 11, color: '#dc2626' }}>
                  {newFolderError}
                </p>
              )}
              <div style={{ display: 'flex', gap: 6, justifyContent: 'flex-end', marginTop: 8 }}>
                <button
                  type="button"
                  onClick={cancelNewFolder}
                  disabled={isCreating}
                  style={{
                    fontSize:     12,
                    fontWeight:   500,
                    background:   C.gray100,
                    color:        C.gray700,
                    border:       `1px solid ${C.gray200}`,
                    borderRadius: 6,
                    padding:      '5px 12px',
                    cursor:       isCreating ? 'not-allowed' : 'pointer',
                    opacity:      isCreating ? 0.6 : 1,
                  }}
                >
                  Cancel
                </button>
                <button
                  type="button"
                  onClick={confirmNewFolder}
                  disabled={isCreating || !newFolderName.trim()}
                  style={{
                    fontSize:     12,
                    fontWeight:   600,
                    background:   C.blue,
                    color:        C.white,
                    border:       'none',
                    borderRadius: 6,
                    padding:      '5px 12px',
                    cursor:       (isCreating || !newFolderName.trim()) ? 'not-allowed' : 'pointer',
                    opacity:      (isCreating || !newFolderName.trim()) ? 0.6 : 1,
                  }}
                >
                  {isCreating ? 'Creating…' : 'Create'}
                </button>
              </div>
            </div>
          )}

          {/* ---------------------------------------------------------------- */}
          {/* Cut clipboard banner                                              */}
          {/* ---------------------------------------------------------------- */}
          {clipboard?.mode === 'cut' && (() => {
            const cutFolder = getFolderById(clipboard.folderId)
            return (
              <div className="mediapilot-cut-banner flex-shrink-0">
                <svg viewBox="0 0 20 20" fill="currentColor" style={{ width: 14, height: 14, flexShrink: 0 }} aria-hidden="true">
                  <path fillRule="evenodd" d="M5.5 2a3.5 3.5 0 100 7 3.5 3.5 0 000-7zM3 5.5a2.5 2.5 0 115 0 2.5 2.5 0 01-5 0zM5.5 11a3.5 3.5 0 100 7 3.5 3.5 0 000-7zm-2.5 3.5a2.5 2.5 0 115 0 2.5 2.5 0 01-5 0zM14 4a1 1 0 00-1.447-.894L8 5.764V7.5h.764l5-2.5A1 1 0 0014 4zM8.764 12.5H8v1.736l4.553 2.658A1 1 0 0014 16a1 1 0 00-.447-1.894L8.764 12.5z" clipRule="evenodd" />
                </svg>
                <span className="flex-1 truncate" style={{ fontSize: 12 }}>
                  Cut: <strong>{cutFolder?.name ?? '…'}</strong>
                  <span style={{ display: 'block', fontSize: 11, color: C.gray500, fontWeight: 400 }}>Right-click → Paste</span>
                </span>
                <button
                  type="button"
                  onClick={clearClipboard}
                  aria-label="Cancel cut"
                  style={{ background: 'none', border: 'none', cursor: 'pointer', color: C.gray500, fontSize: 16, lineHeight: 1, padding: 0 }}
                >×</button>
              </div>
            )
          })()}

          {/* ---------------------------------------------------------------- */}
          {/* Sort + folder count bar                                           */}
          {/* ---------------------------------------------------------------- */}
          <div
            className="flex items-center justify-between flex-shrink-0"
            style={{
              padding:    '6px 12px',
              borderBottom: `1px solid ${C.gray200}`,
            }}
          >
            <span style={{ fontSize: 11, color: C.gray500 }}>
              {searchQuery ? `${countFolders(filteredTree)} found` : ''}
            </span>
            <button
              type="button"
              onClick={() => setSortDir((v) => v === 'az' ? 'za' : 'az')}
              className="mediapilot-sort-btn"
              title={sortDir === 'az' ? 'Sorted A→Z — click for Z→A' : sortDir === 'za' ? 'Sorted Z→A — click for A→Z' : 'Sort A→Z'}
            >
              {sortDir === 'za' ? 'Z→A' : 'A→Z'}
            </button>
          </div>

          {/* ---------------------------------------------------------------- */}
          {/* Folder tree                                                       */}
          {/* ---------------------------------------------------------------- */}
          <div className="flex-1 overflow-y-auto min-h-0" style={{ paddingTop: 4 }}>
            {searchQuery && displayFolders.length === 0 ? (
              <div
                style={{
                  textAlign: 'center',
                  padding:   '24px 12px',
                  fontSize:  12,
                  color:     C.gray500,
                }}
              >
                No folders match &ldquo;{searchQuery}&rdquo;
              </div>
            ) : (
              <FolderTree folders={displayFolders} />
            )}
          </div>

          {/* ---------------------------------------------------------------- */}
          {/* Footer                                                            */}
          {/* ---------------------------------------------------------------- */}

          <div
            className="flex-shrink-0"
            style={{
              borderTop:  `1px solid ${C.gray200}`,
              padding:    '6px 12px',
              background: C.gray50,
              fontSize:   11,
              color:      C.gray500,
            }}
          >
            {totalFolderCount} {totalFolderCount !== 1 ? 'folders' : 'folder'}
          </div>
        </div>
      )}
        {/* -------------------------------------------------------------------- */}
        {/* Toggle button container                                               */}
        {/* -------------------------------------------------------------------- */}
        <div className="flex-shrink-0 flex justify-end">
            {/* Resize handle */}
            {!sidebarCollapsed && (
                <div
                    role="separator"
                    aria-orientation="vertical"
                    aria-label="Resize sidebar"
                    onMouseDown={handleMouseDown}
                    className="absolute top-0 bottom-0 w-1 cursor-col-resize"
                    style={{
                        right: '0.5rem',
                        background: isResizing ? C.blue : C.gray200,
                        transition: 'background 0.1s',
                    }}
                />
            )}
            <button
                type="button"
                onClick={toggleSidebar}
                title={sidebarCollapsed ? 'Expand sidebar' : 'Collapse sidebar'}
                aria-label={sidebarCollapsed ? 'Expand sidebar' : 'Collapse sidebar'}
                aria-expanded={!sidebarCollapsed}
                className="mediapilot-collapse-btn"
                style={{ zIndex: 999 }}
            >
                {sidebarCollapsed ? <ChevronRightIcon /> : <ChevronLeftIcon />}
            </button>

        </div>
      {/* Context menu */}
      <FolderContextMenu />

      {/* aria-live region — screen reader announcements for drag-drop/delete */}
      <div
        ref={liveRef}
        role="status"
        aria-live="polite"
        aria-atomic="true"
        style={{
          position: 'absolute',
          width: 1,
          height: 1,
          padding: 0,
          margin: -1,
          overflow: 'hidden',
          clip: 'rect(0,0,0,0)',
          whiteSpace: 'nowrap',
          border: 0,
        }}
      />
    </aside>
  )
}

export default FolderSidebar
