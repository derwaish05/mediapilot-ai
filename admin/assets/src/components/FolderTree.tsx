/**
 * FolderTree.tsx
 *
 * Renders the complete nested folder hierarchy using FolderNode. Reads all
 * necessary state and actions from folderStore and uiStore via React hooks.
 *
 * Responsibilities:
 *   - Provide role="tree" ARIA container
 *   - Map top-level folders to FolderNode instances
 *   - Pass correct isActive / isExpanded / isCut booleans
 *   - Wrap event handlers with useCallback to keep FolderNode memo effective
 *   - Show a friendly empty state when there are no folders
 */

import React, { useCallback } from 'react'
import type { MediaPilotFolder } from '@/types'
import { useFolderStore } from '@/store/folderStore'
import { useUiStore } from '@/store/uiStore'
import FolderNode from './FolderNode'

// ---------------------------------------------------------------------------
// Empty-state folder icon (standalone so it can be used without import)
// ---------------------------------------------------------------------------

const EmptyFolderIcon: React.FC<{ className?: string }> = ({ className }) => (
  <svg
    viewBox="0 0 24 24"
    fill="none"
    stroke="currentColor"
    strokeWidth={1.5}
    strokeLinecap="round"
    strokeLinejoin="round"
    className={className}
    aria-hidden="true"
  >
    <path d="M3 7a2 2 0 012-2h4l2 2h8a2 2 0 012 2v8a2 2 0 01-2 2H5a2 2 0 01-2-2V7z" />
  </svg>
)

// ---------------------------------------------------------------------------
// Props
// ---------------------------------------------------------------------------

interface FolderTreeProps {
  folders: MediaPilotFolder[]
  className?: string
}

/** Shape returned by resolveNodeProps — avoids inline object type repetition */
interface ResolvedNodeProps {
  isActive: boolean
  isExpanded: boolean
  isCut: boolean
}

// ---------------------------------------------------------------------------
// Component
// ---------------------------------------------------------------------------

const FolderTree: React.FC<FolderTreeProps> = ({ folders, className }) => {
  // ---- Store slices --------------------------------------------------------

  const activeFolder = useFolderStore((s) => s.activeFolder)
  const setActiveFolder = useFolderStore((s) => s.setActiveFolder)
  const moveFolder = useFolderStore((s) => s.moveFolder)
  const renameFolder = useFolderStore((s) => s.renameFolder)
  const clipboard = useFolderStore((s) => s.clipboard)

  const expandedFolders = useUiStore((s) => s.expandedFolders)
  const toggleFolder = useUiStore((s) => s.toggleFolder)
  const expandFolder = useUiStore((s) => s.expandFolder)
  const showContextMenu = useUiStore((s) => s.showContextMenu)

  // ---- Event handler callbacks --------------------------------------------

  /**
   * Select a folder and auto-expand it when it has children.
   */
  const handleSelect = useCallback(
    (id: number) => {
      setActiveFolder(id)
      // Auto-expand on selection if the folder has children
      const allFoldersFlatSearch = (
        nodes: MediaPilotFolder[],
        targetId: number,
      ): MediaPilotFolder | undefined => {
        for (const node of nodes) {
          if (node.id === targetId) return node
          if (node.children.length > 0) {
            const found = allFoldersFlatSearch(node.children, targetId)
            if (found) return found
          }
        }
        return undefined
      }

      const target = allFoldersFlatSearch(folders, id)
      if (target && target.children.length > 0 && !expandedFolders.has(id)) {
        expandFolder(id)
      }
    },
    [setActiveFolder, folders, expandedFolders, expandFolder],
  )

  /**
   * Toggle expand/collapse for a folder id.
   */
  const handleToggleExpand = useCallback(
    (id: number) => {
      toggleFolder(id)
    },
    [toggleFolder],
  )

  /**
   * Show the context menu at the cursor position bound to a folder id.
   */
  const handleContextMenu = useCallback(
    (e: React.MouseEvent, id: number) => {
      showContextMenu(e.clientX, e.clientY, id)
    },
    [showContextMenu],
  )

  /**
   * Commit an inline rename. The folderStore handles the API call and
   * tree refresh; we don't need local loading state here.
   */
  const handleRenameCommit = useCallback(
    (id: number, newName: string) => {
      renameFolder(id, newName).catch(() => {
        // Error is stored in folderStore.error; surface through a toast
        // in a future sprint. Silently ignore here.
      })
    },
    [renameFolder],
  )

  /**
   * Handle a drop — move the dragged folder under the target.
   * Guard against self-drop (draggedId === targetId).
   */
  const handleDrop = useCallback(
    (draggedId: number, targetId: number) => {
      if (draggedId === targetId) return
      moveFolder(draggedId, targetId).catch(() => {
        // Same as above — errors surface via store.error
      })
    },
    [moveFolder],
  )

  // ---- Resolve per-node booleans ------------------------------------------

  const cutFolderId =
    clipboard != null && clipboard.mode === 'cut' ? clipboard.folderId : null

  /** True when there is a staged cut — every folder that is NOT the cut folder
   *  becomes a valid paste target and gets the hover ring indicator. */
  const isPasteMode = cutFolderId != null

  const resolveNodeProps = useCallback(
    (folder: MediaPilotFolder): ResolvedNodeProps => ({
      isActive: activeFolder === folder.id,
      isExpanded: expandedFolders.has(folder.id),
      isCut: cutFolderId === folder.id,
    }),
    [activeFolder, expandedFolders, cutFolderId],
  )

  // ---- Empty state ---------------------------------------------------------

  if (folders.length === 0) {
    return (
      <div
        role="tree"
        aria-label="Media folders"
        className={className}
      >
        <div className="text-center py-8 text-slate-400">
          <EmptyFolderIcon className="w-8 h-8 mx-auto mb-2 opacity-50" />
          <p className="text-sm">No folders yet</p>
        </div>
      </div>
    )
  }

  // ---- Tree render ---------------------------------------------------------

  return (
    <div
      role="tree"
      aria-label="Media folders"
      className={className}
    >
      {folders.map((folder, index) => {
        const { isActive, isExpanded, isCut } = resolveNodeProps(folder)

        return (
          <FolderNode
            key={folder.id}
            folder={folder}
            depth={0}
            isActive={isActive}
            isExpanded={isExpanded}
            isCut={isCut}
            isPasteTarget={isPasteMode && !isCut}
            onSelect={handleSelect}
            onToggleExpand={handleToggleExpand}
            onContextMenu={handleContextMenu}
            onRenameCommit={handleRenameCommit}
            onDrop={handleDrop}
            ariaSetSize={folders.length}
            ariaPosInSet={index + 1}
            activeFolderId={activeFolder}
            expandedFolders={expandedFolders}
            cutFolderId={cutFolderId}
          />
        )
      })}
    </div>
  )
}

export default FolderTree
