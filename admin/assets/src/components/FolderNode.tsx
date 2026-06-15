/**
 * FolderNode.tsx
 *
 * A single node in the folder tree. Handles:
 *   - expand/collapse via chevron
 *   - folder selection on click
 *   - inline rename on double-click (Enter/blur to commit, Escape to revert)
 *   - right-click context menu trigger
 *   - keyboard navigation (ArrowRight, ArrowLeft, Enter, F2, Delete)
 *   - drag-and-drop as both source and target
 *   - cut visual (opacity-50)
 *   - file count badge
 *   - action buttons (rename, delete, more) shown on hover
 */

import React, { useState, useRef, useCallback, useEffect } from 'react'
import type { MediaPilotFolder } from '@/types'

// ---------------------------------------------------------------------------
// Design tokens
// ---------------------------------------------------------------------------

const C = {
  blue:      '#2563eb',
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
// Props
// ---------------------------------------------------------------------------

interface FolderNodeProps {
  folder: MediaPilotFolder
  depth: number
  isActive: boolean
  isExpanded: boolean
  isCut: boolean
  isPasteTarget: boolean
  onSelect: (id: number) => void
  onToggleExpand: (id: number) => void
  onContextMenu: (e: React.MouseEvent, id: number) => void
  onRenameCommit: (id: number, newName: string) => void
  onDrop: (draggedId: number, targetId: number) => void
  ariaSetSize?: number
  ariaPosInSet?: number
  activeFolderId: number | null
  expandedFolders: Set<number>
  cutFolderId: number | null
}

// ---------------------------------------------------------------------------
// Icons
// ---------------------------------------------------------------------------

const ChevronIcon: React.FC<{ expanded: boolean }> = ({ expanded }) => (
  <svg
    viewBox="0 0 16 16"
    fill="none"
    stroke="currentColor"
    strokeWidth={2}
    strokeLinecap="round"
    strokeLinejoin="round"
    style={{
      width:     12,
      height:    12,
      flexShrink: 0,
      transition: 'transform 0.15s',
      transform:  expanded ? 'rotate(90deg)' : 'rotate(0deg)',
    }}
    aria-hidden="true"
  >
    <path d="M6 4l4 4-4 4" />
  </svg>
)

const FolderClosedIcon: React.FC<{ color: string }> = ({ color }) => (
  <svg width="18" height="18" viewBox="0 0 20 20" fill={color} aria-hidden="true" style={{ flexShrink: 0 }}>
    <path d="M2 6a2 2 0 012-2h4l2 2h6a2 2 0 012 2v6a2 2 0 01-2 2H4a2 2 0 01-2-2V6z" />
  </svg>
)

const FolderOpenIcon: React.FC<{ color: string }> = ({ color }) => (
  <svg width="18" height="18" viewBox="0 0 20 20" fill={color} aria-hidden="true" style={{ flexShrink: 0 }}>
    <path fillRule="evenodd" d="M2 6a2 2 0 012-2h4l2 2h6a2 2 0 012 2v1H2V6z" clipRule="evenodd" />
    <path d="M1 11a1 1 0 011-1h16a1 1 0 01.97 1.243l-1.5 6A1 1 0 0116.5 18h-13a1 1 0 01-.97-.757l-1.5-6A1 1 0 011 11z" />
  </svg>
)

const PencilIcon = () => (
  <svg width="13" height="13" viewBox="0 0 20 20" fill="currentColor" aria-hidden="true">
    <path d="M13.586 3.586a2 2 0 112.828 2.828l-.793.793-2.828-2.828.793-.793zM11.379 5.793L3 14.172V17h2.828l8.38-8.379-2.83-2.828z" />
  </svg>
)

const TrashIcon = () => (
  <svg width="13" height="13" viewBox="0 0 20 20" fill="currentColor" aria-hidden="true">
    <path fillRule="evenodd" d="M9 2a1 1 0 00-.894.553L7.382 4H4a1 1 0 000 2v10a2 2 0 002 2h8a2 2 0 002-2V6a1 1 0 100-2h-3.382l-.724-1.447A1 1 0 0011 2H9zM7 8a1 1 0 012 0v6a1 1 0 11-2 0V8zm5-1a1 1 0 00-1 1v6a1 1 0 102 0V8a1 1 0 00-1-1z" clipRule="evenodd" />
  </svg>
)

const DotsIcon = () => (
  <svg width="13" height="13" viewBox="0 0 20 20" fill="currentColor" aria-hidden="true">
    <path d="M6 10a2 2 0 11-4 0 2 2 0 014 0zM12 10a2 2 0 11-4 0 2 2 0 014 0zM16 12a2 2 0 100-4 2 2 0 000 4z" />
  </svg>
)

// ---------------------------------------------------------------------------
// Component
// ---------------------------------------------------------------------------

const FolderNode: React.FC<FolderNodeProps> = React.memo(
  ({
    folder,
    depth,
    isActive,
    isExpanded,
    isCut,
    isPasteTarget,
    onSelect,
    onToggleExpand,
    onContextMenu,
    onRenameCommit,
    onDrop,
    ariaSetSize,
    ariaPosInSet,
    activeFolderId,
    expandedFolders,
    cutFolderId,
  }) => {
    const [isRenaming, setIsRenaming] = useState(false)
    const [renameValue, setRenameValue] = useState(folder.name)
    const [isDragOver, setIsDragOver]   = useState(false)
    const [isHovered, setIsHovered]     = useState(false)

    const inputRef = useRef<HTMLInputElement>(null)
    const rowRef   = useRef<HTMLDivElement>(null)

    useEffect(() => {
      if (!isRenaming) setRenameValue(folder.name)
    }, [folder.name, isRenaming])

    useEffect(() => {
      if (isRenaming && inputRef.current) {
        inputRef.current.focus()
        inputRef.current.select()
      }
    }, [isRenaming])

    // ---- Rename ------------------------------------------------------------

    const enterRenameMode = useCallback(() => {
      setRenameValue(folder.name)
      setIsRenaming(true)
    }, [folder.name])

    const commitRename = useCallback(() => {
      const trimmed = renameValue.trim()
      if (trimmed && trimmed !== folder.name) onRenameCommit(folder.id, trimmed)
      setIsRenaming(false)
    }, [renameValue, folder.name, folder.id, onRenameCommit])

    const cancelRename = useCallback(() => {
      setRenameValue(folder.name)
      setIsRenaming(false)
    }, [folder.name])

    const handleRenameKeyDown = useCallback(
      (e: React.KeyboardEvent<HTMLInputElement>) => {
        if (e.key === 'Enter') { e.preventDefault(); commitRename() }
        else if (e.key === 'Escape') { e.preventDefault(); cancelRename() }
      },
      [commitRename, cancelRename],
    )

    // ---- Row events --------------------------------------------------------

    const handleRowClick    = useCallback(() => { if (!isRenaming) onSelect(folder.id) }, [isRenaming, onSelect, folder.id])
    const handleChevronClick = useCallback((e: React.MouseEvent) => { e.stopPropagation(); onToggleExpand(folder.id) }, [onToggleExpand, folder.id])
    const handleDoubleClick  = useCallback((e: React.MouseEvent) => { e.preventDefault(); if (!isRenaming) enterRenameMode() }, [isRenaming, enterRenameMode])
    const handleContextMenu  = useCallback((e: React.MouseEvent) => { e.preventDefault(); onContextMenu(e, folder.id) }, [onContextMenu, folder.id])

    // ---- Keyboard ----------------------------------------------------------

    const handleKeyDown = useCallback(
      (e: React.KeyboardEvent<HTMLDivElement>) => {
        switch (e.key) {
          case 'ArrowRight': e.preventDefault(); if (!isExpanded) onToggleExpand(folder.id); break
          case 'ArrowLeft':  e.preventDefault(); if (isExpanded)  onToggleExpand(folder.id); break
          case 'Enter':      e.preventDefault(); onSelect(folder.id); break
          case 'F2':         e.preventDefault(); enterRenameMode(); break
          case 'Delete':     e.preventDefault(); window.dispatchEvent(new CustomEvent('mediapilot:delete-folder', { detail: { folderId: folder.id } })); break
        }
      },
      [isExpanded, folder.id, onToggleExpand, onSelect, enterRenameMode],
    )

    // ---- Drag & drop -------------------------------------------------------

    const handleDragStart = useCallback((e: React.DragEvent<HTMLDivElement>) => {
      e.dataTransfer.setData('mediapilot-folder-id', String(folder.id))
      e.dataTransfer.effectAllowed = 'move'
      window.dispatchEvent(new CustomEvent('mediapilot:announce', { detail: { message: `Dragging folder ${folder.name}. Drop on a target folder to move it.` } }))
    }, [folder.id, folder.name])

    const handleDragOver = useCallback((e: React.DragEvent<HTMLDivElement>) => {
      e.preventDefault(); e.dataTransfer.dropEffect = 'move'; setIsDragOver(true)
    }, [])

    const handleDragLeave = useCallback(() => setIsDragOver(false), [])

    const handleDrop = useCallback((e: React.DragEvent<HTMLDivElement>) => {
      e.preventDefault(); setIsDragOver(false)
      const draggedId = parseInt(e.dataTransfer.getData('mediapilot-folder-id'), 10)
      if (!isNaN(draggedId) && draggedId !== folder.id) {
        onDrop(draggedId, folder.id)
        window.dispatchEvent(new CustomEvent('mediapilot:announce', { detail: { message: `Folder moved into ${folder.name}.` } }))
      }
    }, [folder.id, folder.name, onDrop])

    // ---- Action button handlers --------------------------------------------

    const handleRenameClick = useCallback((e: React.MouseEvent) => {
      e.stopPropagation(); enterRenameMode()
    }, [enterRenameMode])

    const handleDeleteClick = useCallback((e: React.MouseEvent) => {
      e.stopPropagation()
      window.dispatchEvent(new CustomEvent('mediapilot:delete-folder', { detail: { folderId: folder.id } }))
    }, [folder.id])

    const handleMoreClick = useCallback((e: React.MouseEvent) => {
      e.stopPropagation(); e.preventDefault()
      onContextMenu(e, folder.id)
    }, [onContextMenu, folder.id])

    // ---- Computed ----------------------------------------------------------

    const indentPx = depth * 16 + 4
    const hasChildren = folder.children.length > 0

    const folderIconColor = isActive
      ? C.blue
      : (folder.color || C.gray400)

    const rowBg = isActive
      ? C.blueLight
      : isDragOver
        ? C.blueLight
        : isHovered
          ? C.gray50
          : 'transparent'

    // ---- Render ------------------------------------------------------------

    return (
      <div
        role="treeitem"
        aria-selected={isActive}
        aria-expanded={hasChildren ? isExpanded : undefined}
        aria-setsize={ariaSetSize}
        aria-posinset={ariaPosInSet}
        aria-label={folder.name}
      >
        {/* Clickable row */}
        <div
          ref={rowRef}
          className={`mediapilot-node-row${isDragOver ? ' mediapilot-node-row--drag-over' : ''}${isPasteTarget ? ' mediapilot-paste-target' : ''}`}
          style={{
            paddingLeft: `${indentPx}px`,
            background:  rowBg,
            opacity:     isCut ? 0.4 : 1,
            pointerEvents: isCut ? 'none' : undefined,
          }}
          tabIndex={0}
          onClick={handleRowClick}
          onDoubleClick={handleDoubleClick}
          onContextMenu={handleContextMenu}
          onKeyDown={handleKeyDown}
          onMouseEnter={() => setIsHovered(true)}
          onMouseLeave={() => setIsHovered(false)}
          draggable
          onDragStart={handleDragStart}
          onDragOver={handleDragOver}
          onDragLeave={handleDragLeave}
          onDrop={handleDrop}
        >
          {/* Chevron */}
          <button
            type="button"
            onClick={handleChevronClick}
            tabIndex={-1}
            aria-label={isExpanded ? 'Collapse folder' : 'Expand folder'}
            style={{
              width:      16,
              height:     16,
              display:    'flex',
              alignItems: 'center',
              justifyContent: 'center',
              flexShrink: 0,
              background: 'none',
              border:     'none',
              padding:    0,
              cursor:     hasChildren ? 'pointer' : 'default',
              color:      C.gray400,
              opacity:    hasChildren ? 1 : 0,
            }}
          >
            <ChevronIcon expanded={isExpanded} />
          </button>

          {/* Folder icon */}
          {isActive || isExpanded
            ? <FolderOpenIcon color={folderIconColor} />
            : <FolderClosedIcon color={folderIconColor} />
          }

          {/* Name / sub-text block OR rename input */}
          {isRenaming ? (
            <input
              ref={inputRef}
              type="text"
              className="mediapilot-rename-input"
              style={{ flex: 1, minWidth: 0 }}
              value={renameValue}
              onChange={(e) => setRenameValue(e.target.value)}
              onKeyDown={handleRenameKeyDown}
              onBlur={commitRename}
              onClick={(e) => e.stopPropagation()}
              aria-label="Rename folder"
            />
          ) : (
            <div style={{ flex: 1, minWidth: 0, display: 'flex', alignItems: 'center', gap: 4, overflow: 'hidden' }}>
              <span
                style={{
                  fontSize:    13,
                  fontWeight:  isActive ? 600 : 400,
                  color:       isActive ? C.blue : C.gray900,
                  overflow:    'hidden',
                  textOverflow: 'ellipsis',
                  whiteSpace:  'nowrap',
                  lineHeight:  1.3,
                  flexShrink:  1,
                }}
              >
                {folder.name}
              </span>
              {folder.count > 0 && (
                <span
                  style={{
                    fontSize:   11,
                    color:      isActive ? C.blue : C.gray400,
                    flexShrink: 0,
                    lineHeight: 1.3,
                  }}
                >
                  ({folder.count})
                </span>
              )}
            </div>
          )}

          {/* Action buttons — visible on hover (CSS handles show/hide) */}
          {!isRenaming && (
            <div className="mediapilot-node-actions">
              <button
                type="button"
                className="mediapilot-node-action-btn"
                onClick={handleRenameClick}
                title="Rename"
                aria-label="Rename folder"
                tabIndex={-1}
              >
                <PencilIcon />
              </button>
              <button
                type="button"
                className="mediapilot-node-action-btn mediapilot-node-action-btn--danger"
                onClick={handleDeleteClick}
                title="Delete"
                aria-label="Delete folder"
                tabIndex={-1}
              >
                <TrashIcon />
              </button>
              <button
                type="button"
                className="mediapilot-node-action-btn"
                onClick={handleMoreClick}
                title="More options"
                aria-label="More options"
                tabIndex={-1}
              >
                <DotsIcon />
              </button>
            </div>
          )}

        </div>

        {/* Children */}
        {isExpanded && hasChildren && (
          <div
            role="group"
            className="mediapilot-children-group"
            style={{ marginLeft: `${indentPx + 18}px` }}
          >
            {folder.children.map((child, index) => {
              const childIsCut = cutFolderId === child.id
              return (
                <FolderNode
                  key={child.id}
                  folder={child}
                  depth={0}
                  isActive={activeFolderId === child.id}
                  isExpanded={expandedFolders.has(child.id)}
                  isCut={childIsCut}
                  isPasteTarget={isPasteTarget && !childIsCut}
                  onSelect={onSelect}
                  onToggleExpand={onToggleExpand}
                  onContextMenu={onContextMenu}
                  onRenameCommit={onRenameCommit}
                  onDrop={onDrop}
                  ariaSetSize={folder.children.length}
                  ariaPosInSet={index + 1}
                  activeFolderId={activeFolderId}
                  expandedFolders={expandedFolders}
                  cutFolderId={cutFolderId}
                />
              )
            })}
          </div>
        )}
      </div>
    )
  },
)

FolderNode.displayName = 'FolderNode'

export default FolderNode
