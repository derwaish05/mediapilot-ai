/**
 * FolderContextMenu.tsx
 *
 * A floating context menu that appears on right-click of any FolderNode.
 *
 * Reads visibility, position, and target folder from uiStore.contextMenu.
 * Reads folder data and clipboard from folderStore.
 *
 * Behaviours:
 *   - Fixed-position at contextMenu.x / contextMenu.y
 *   - Auto-repositions when the menu would overflow the right or bottom edge
 *   - Closes on click-outside (mousedown on document) or Escape key
 *   - "Delete" item triggers an inline confirm sub-state before calling store
 *   - "Paste" is only shown when clipboard has a cut folder
 *   - "Download ZIP" navigates to the REST endpoint for the folder's ZIP
 */

import React, { useEffect, useRef, useState, useCallback } from 'react'
import { useUiStore } from '@/store/uiStore'
import { useFolderStore } from '@/store/folderStore'
import ColorPicker from '@/components/ColorPicker'

// ---------------------------------------------------------------------------
// Small icon components (inline SVG, no library)
// ---------------------------------------------------------------------------

const IconFolderPlus: React.FC = () => (
  <svg
    viewBox="0 0 20 20"
    fill="currentColor"
    className="w-4 h-4"
    aria-hidden="true"
  >
    <path d="M2 6a2 2 0 012-2h4l2 2h6a2 2 0 012 2v6a2 2 0 01-2 2H4a2 2 0 01-2-2V6z" />
    <path
      fillRule="evenodd"
      d="M10 8a1 1 0 011 1v1h1a1 1 0 110 2h-1v1a1 1 0 11-2 0v-1H8a1 1 0 110-2h1V9a1 1 0 011-1z"
      clipRule="evenodd"
    />
  </svg>
)

const IconPencil: React.FC = () => (
  <svg
    viewBox="0 0 20 20"
    fill="currentColor"
    className="w-4 h-4"
    aria-hidden="true"
  >
    <path d="M13.586 3.586a2 2 0 112.828 2.828l-.793.793-2.828-2.828.793-.793zM11.379 5.793L3 14.172V17h2.828l8.38-8.379-2.83-2.828z" />
  </svg>
)

const IconScissors: React.FC = () => (
  <svg
    viewBox="0 0 20 20"
    fill="currentColor"
    className="w-4 h-4"
    aria-hidden="true"
  >
    <path
      fillRule="evenodd"
      d="M5.5 2a3.5 3.5 0 100 7 3.5 3.5 0 000-7zM3 5.5a2.5 2.5 0 115 0 2.5 2.5 0 01-5 0zM5.5 11a3.5 3.5 0 100 7 3.5 3.5 0 000-7zm-2.5 3.5a2.5 2.5 0 115 0 2.5 2.5 0 01-5 0z"
      clipRule="evenodd"
    />
    <path d="M14 4a1 1 0 00-1.447-.894L8 5.764V7.5h.764l5-2.5A1 1 0 0014 4zM8.764 12.5H8v1.736l4.553 2.658A1 1 0 0014 16a1 1 0 00-.447-1.894L8.764 12.5z" />
  </svg>
)

const IconClipboard: React.FC = () => (
  <svg
    viewBox="0 0 20 20"
    fill="currentColor"
    className="w-4 h-4"
    aria-hidden="true"
  >
    <path d="M8 3a1 1 0 011-1h2a1 1 0 110 2H9a1 1 0 01-1-1z" />
    <path d="M6 3a2 2 0 00-2 2v11a2 2 0 002 2h8a2 2 0 002-2V5a2 2 0 00-2-2 3 3 0 01-3 3H9a3 3 0 01-3-3z" />
  </svg>
)

const IconDownload: React.FC = () => (
  <svg
    viewBox="0 0 20 20"
    fill="currentColor"
    className="w-4 h-4"
    aria-hidden="true"
  >
    <path
      fillRule="evenodd"
      d="M3 17a1 1 0 011-1h12a1 1 0 110 2H4a1 1 0 01-1-1zm3.293-7.707a1 1 0 011.414 0L9 10.586V3a1 1 0 112 0v7.586l1.293-1.293a1 1 0 111.414 1.414l-3 3a1 1 0 01-1.414 0l-3-3a1 1 0 010-1.414z"
      clipRule="evenodd"
    />
  </svg>
)

const IconTrash: React.FC = () => (
  <svg
    viewBox="0 0 20 20"
    fill="currentColor"
    className="w-4 h-4"
    aria-hidden="true"
  >
    <path
      fillRule="evenodd"
      d="M9 2a1 1 0 00-.894.553L7.382 4H4a1 1 0 000 2v10a2 2 0 002 2h8a2 2 0 002-2V6a1 1 0 100-2h-3.382l-.724-1.447A1 1 0 0011 2H9zM7 8a1 1 0 012 0v6a1 1 0 11-2 0V8zm5-1a1 1 0 00-1 1v6a1 1 0 102 0V8a1 1 0 00-1-1z"
      clipRule="evenodd"
    />
  </svg>
)

const IconLightningBolt: React.FC = () => (
  <svg
    viewBox="0 0 20 20"
    fill="currentColor"
    className="w-4 h-4"
    aria-hidden="true"
  >
    <path
      fillRule="evenodd"
      d="M11.3 1.046A1 1 0 0112 2v5h4a1 1 0 01.82 1.573l-7 10A1 1 0 018 18v-5H4a1 1 0 01-.82-1.573l7-10a1 1 0 011.12-.38z"
      clipRule="evenodd"
    />
  </svg>
)

const IconShield: React.FC = () => (
  <svg
    viewBox="0 0 20 20"
    fill="currentColor"
    className="w-4 h-4"
    aria-hidden="true"
  >
    <path
      fillRule="evenodd"
      d="M10 1l8 3v5c0 4.418-3.582 8-8 9C5.582 17 2 13.418 2 9V4l8-3zm0 2.236L4 5.618V9c0 3.314 2.686 6 6 7 3.314-1 6-3.686 6-7V5.618L10 3.236z"
      clipRule="evenodd"
    />
  </svg>
)

const IconPalette: React.FC = () => (
  <svg
    viewBox="0 0 20 20"
    fill="currentColor"
    className="w-4 h-4"
    aria-hidden="true"
  >
    <path
      fillRule="evenodd"
      d="M4 2a2 2 0 00-2 2v11a3 3 0 106 0V4a2 2 0 00-2-2H4zm1 14a1 1 0 100-2 1 1 0 000 2zm5-1.757l4.9-4.9a2 2 0 000-2.828L13.485 5.1a2 2 0 00-2.828 0L10 5.757v8.486zM16 18H9.071l6-6H16a2 2 0 012 2v2a2 2 0 01-2 2z"
      clipRule="evenodd"
    />
  </svg>
)

const IconChevronRight: React.FC = () => (
  <svg
    viewBox="0 0 16 16"
    fill="none"
    stroke="currentColor"
    strokeWidth={2}
    strokeLinecap="round"
    strokeLinejoin="round"
    className="w-3 h-3 ml-auto"
    aria-hidden="true"
  >
    <path d="M6 4l4 4-4 4" />
  </svg>
)

// ---------------------------------------------------------------------------
// Menu item
// ---------------------------------------------------------------------------

interface MenuItemProps {
  icon: React.ReactNode
  label: string
  onClick: () => void
  variant?: 'default' | 'danger'
  disabled?: boolean
}

const MenuItem: React.FC<MenuItemProps> = ({
  icon,
  label,
  onClick,
  variant = 'default',
  disabled = false,
}) => {
  const colorClasses =
    variant === 'danger'
      ? 'text-red-600 hover:bg-red-50'
      : 'text-mediapilot-text hover:bg-slate-100'

  const disabledClasses = disabled ? 'opacity-40 cursor-not-allowed' : 'cursor-pointer'

  return (
    <div
      role="menuitem"
      tabIndex={disabled ? -1 : 0}
      className={[
        'flex items-center gap-2 px-3 py-1.5 text-sm',
        colorClasses,
        disabledClasses,
      ].join(' ')}
      onClick={disabled ? undefined : onClick}
      onKeyDown={(e) => {
        if (!disabled && (e.key === 'Enter' || e.key === ' ')) {
          e.preventDefault()
          onClick()
        }
      }}
    >
      {icon}
      <span>{label}</span>
    </div>
  )
}

const Separator: React.FC = () => (
  <div role="separator" className="border-t border-slate-100 my-1" />
)

// ---------------------------------------------------------------------------
// Main component
// ---------------------------------------------------------------------------

const FolderContextMenu: React.FC = () => {
  // ---- Store slices --------------------------------------------------------

  const contextMenu = useUiStore((s) => s.contextMenu)
  const hideContextMenu = useUiStore((s) => s.hideContextMenu)

  const deleteFolder = useFolderStore((s) => s.deleteFolder)
  const cutFolder = useFolderStore((s) => s.cutFolder)
  const pasteFolder = useFolderStore((s) => s.pasteFolder)
  const updateColor = useFolderStore((s) => s.updateColor)
  const clipboard = useFolderStore((s) => s.clipboard)
  const getFolderById = useFolderStore((s) => s.getFolderById)

  // ---- Local state ---------------------------------------------------------

  const [showDeleteConfirm, setShowDeleteConfirm] = useState(false)
  const [showColorPicker, setShowColorPicker] = useState(false)
  const [adjustedPos, setAdjustedPos] = useState({ x: 0, y: 0 })

  const menuRef = useRef<HTMLDivElement>(null)

  const { visible, x, y, folderId } = contextMenu
  const folder = folderId != null ? getFolderById(folderId) : undefined

  // ---- Reset confirm state when menu opens/closes -------------------------

  useEffect(() => {
    if (!visible) {
      setShowDeleteConfirm(false)
      setShowColorPicker(false)
    }
  }, [visible])

  // ---- Auto-reposition to avoid viewport overflow -------------------------

  useEffect(() => {
    if (!visible || !menuRef.current) return

    const rect = menuRef.current.getBoundingClientRect()
    const vw = window.innerWidth
    const vh = window.innerHeight

    let nx = x
    let ny = y

    if (x + rect.width > vw) {
      nx = x - rect.width
    }
    if (y + rect.height > vh) {
      ny = y - rect.height
    }

    // Clamp to viewport
    nx = Math.max(4, nx)
    ny = Math.max(4, ny)

    setAdjustedPos({ x: nx, y: ny })
  }, [visible, x, y])

  // Set initial position immediately when first shown (before reposition
  // effect runs, so the menu is visible at the rough location first)
  useEffect(() => {
    if (visible) {
      setAdjustedPos({ x, y })
    }
  }, [visible, x, y])

  // ---- Close on click-outside or Escape -----------------------------------

  useEffect(() => {
    if (!visible) return

    const handleMouseDown = (e: MouseEvent) => {
      if (menuRef.current && !menuRef.current.contains(e.target as Node)) {
        hideContextMenu()
      }
    }

    const handleKeyDown = (e: KeyboardEvent) => {
      if (e.key === 'Escape') {
        hideContextMenu()
      }
    }

    document.addEventListener('mousedown', handleMouseDown)
    document.addEventListener('keydown', handleKeyDown)

    return () => {
      document.removeEventListener('mousedown', handleMouseDown)
      document.removeEventListener('keydown', handleKeyDown)
    }
  }, [visible, hideContextMenu])

  // ---- Menu action handlers -----------------------------------------------

  const close = useCallback(() => {
    hideContextMenu()
  }, [hideContextMenu])

  const handleNewSubfolder = useCallback(() => {
    if (folderId == null) return
    window.dispatchEvent(
      new CustomEvent('mediapilot:new-subfolder', { detail: { folderId } }),
    )
    close()
  }, [folderId, close])

  const handleRename = useCallback(() => {
    if (folderId == null) return
    window.dispatchEvent(
      new CustomEvent('mediapilot:rename-folder', { detail: { folderId } }),
    )
    close()
  }, [folderId, close])

  const handleCut = useCallback(() => {
    if (folderId == null) return
    cutFolder(folderId)
    close()
  }, [folderId, cutFolder, close])

  const handlePaste = useCallback(() => {
    if (folderId == null) return
    pasteFolder(folderId).catch(() => {
      // Error surfaces through folderStore.error
    })
    close()
  }, [folderId, pasteFolder, close])

  const handleOpenPermissions = useCallback(() => {
    if (folderId == null) return
    window.dispatchEvent(
      new CustomEvent('mediapilot:open-permissions', {
        detail: { folderId, folderName: folder?.name ?? '' },
      }),
    )
    close()
  }, [folderId, folder, close])

  const handleOpenSmartFolder = useCallback(() => {
    if (folderId == null) return
    window.dispatchEvent(
      new CustomEvent('mediapilot:open-smart-folder', {
        detail: { folderId, folderName: folder?.name ?? '' },
      }),
    )
    close()
  }, [folderId, folder, close])

  const handleDownloadZip = useCallback(() => {
    if (folderId == null) return
    const restUrl = window.MediaPilotConfig?.restUrl?.replace(/\/$/, '') ?? ''
    window.location.href = `${restUrl}/folders/${folderId}/zip`
    close()
  }, [folderId, close])

  const handleChangeColorClick = useCallback(() => {
    setShowColorPicker((prev) => !prev)
  }, [])

  const handleColorChange = useCallback(
    (color: string) => {
      if (folderId == null) return
      updateColor(folderId, color).catch(() => {
        // Error surfaces through folderStore.error
      })
    },
    [folderId, updateColor],
  )

  const handleColorPickerClose = useCallback(() => {
    setShowColorPicker(false)
  }, [])

  const handleDeleteRequest = useCallback(() => {
    setShowDeleteConfirm(true)
  }, [])

  const handleDeleteCancel = useCallback(() => {
    setShowDeleteConfirm(false)
  }, [])

  const handleDeleteConfirm = useCallback(() => {
    if (folderId == null) return
    deleteFolder(folderId, false).catch(() => {
      // Error surfaces through folderStore.error
    })
    close()
  }, [folderId, deleteFolder, close])

  // ---- Bail early if not visible ------------------------------------------

  if (!visible || folderId == null) return null

  const hasPasteable = clipboard != null && clipboard.mode === 'cut'

  // ---- Render --------------------------------------------------------------

  return (
    <div
      ref={menuRef}
      role="menu"
      aria-label={folder ? `Folder options: ${folder.name}` : 'Folder options'}
      className="fixed bg-white shadow-lg border border-slate-200 rounded-mmp py-1 min-w-[160px] z-50"
      style={{ left: adjustedPos.x, top: adjustedPos.y }}
    >
      {showDeleteConfirm ? (
        // ---- Inline delete confirmation ------------------------------------
        <div className="px-3 py-2">
          <p className="text-sm text-slate-700 mb-2">Delete folder?</p>
          <div className="flex gap-2">
            <button
              type="button"
              className="flex-1 text-xs px-2 py-1 rounded bg-slate-100 hover:bg-slate-200 text-slate-700 cursor-pointer"
              onClick={handleDeleteCancel}
            >
              Cancel
            </button>
            <button
              type="button"
              className="flex-1 text-xs px-2 py-1 rounded bg-red-600 hover:bg-red-700 text-white cursor-pointer"
              onClick={handleDeleteConfirm}
            >
              Delete
            </button>
          </div>
        </div>
      ) : (
        // ---- Normal menu ---------------------------------------------------
        <>
          <MenuItem
            icon={<IconFolderPlus />}
            label="New Subfolder"
            onClick={handleNewSubfolder}
          />
          <MenuItem
            icon={<IconPencil />}
            label="Rename"
            onClick={handleRename}
          />

          <Separator />

          <MenuItem
            icon={<IconScissors />}
            label="Cut"
            onClick={handleCut}
          />
          {hasPasteable && (
            <MenuItem
              icon={<IconClipboard />}
              label="Paste"
              onClick={handlePaste}
            />
          )}

          <Separator />

          <MenuItem
            icon={<IconDownload />}
            label="Download ZIP"
            onClick={handleDownloadZip}
          />
          <MenuItem
            icon={<IconShield />}
            label="Permissions"
            onClick={handleOpenPermissions}
          />
          <MenuItem
            icon={<IconLightningBolt />}
            label="Smart Folder Rules…"
            onClick={handleOpenSmartFolder}
          />

          <Separator />

          {/* Change Color — opens inline ColorPicker below the item */}
          <div
            role="menuitem"
            tabIndex={0}
            className="flex items-center gap-2 px-3 py-1.5 text-sm text-mediapilot-text hover:bg-slate-100 cursor-pointer"
            onClick={handleChangeColorClick}
            onKeyDown={(e) => {
              if (e.key === 'Enter' || e.key === ' ') {
                e.preventDefault()
                handleChangeColorClick()
              }
            }}
          >
            <IconPalette />
            <span>Change Color</span>
            <IconChevronRight />
          </div>

          {/* Inline ColorPicker popover */}
          {showColorPicker && folder && (
            <div className="px-2 py-1">
              <ColorPicker
                value={folder.color || '#3b82f6'}
                onChange={handleColorChange}
                onClose={handleColorPickerClose}
              />
            </div>
          )}

          <Separator />

          <MenuItem
            icon={<IconTrash />}
            label="Delete"
            onClick={handleDeleteRequest}
            variant="danger"
          />
        </>
      )}
    </div>
  )
}

export default FolderContextMenu
