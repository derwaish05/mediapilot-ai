/**
 * FolderModal.tsx
 *
 * Generic reusable modal dialog for confirmations and simple input prompts.
 *
 * Features:
 *   - Semi-transparent overlay (click to close)
 *   - Escape key closes the modal
 *   - Optional custom footer (defaults to a plain close button)
 *   - Focus trap: modal receives focus when it opens
 */

import React, { useEffect, useRef } from 'react'

// ---------------------------------------------------------------------------
// Props
// ---------------------------------------------------------------------------

interface FolderModalProps {
  isOpen: boolean
  title: string
  onClose: () => void
  children: React.ReactNode
  footer?: React.ReactNode
}

// ---------------------------------------------------------------------------
// Close icon
// ---------------------------------------------------------------------------

const CloseIcon: React.FC = () => (
  <svg
    viewBox="0 0 20 20"
    fill="currentColor"
    className="w-4 h-4"
    aria-hidden="true"
  >
    <path
      fillRule="evenodd"
      d="M4.293 4.293a1 1 0 011.414 0L10 8.586l4.293-4.293a1 1 0 111.414 1.414L11.414 10l4.293 4.293a1 1 0 01-1.414 1.414L10 11.414l-4.293 4.293a1 1 0 01-1.414-1.414L8.586 10 4.293 5.707a1 1 0 010-1.414z"
      clipRule="evenodd"
    />
  </svg>
)

// ---------------------------------------------------------------------------
// Component
// ---------------------------------------------------------------------------

export const FolderModal: React.FC<FolderModalProps> = ({
  isOpen,
  title,
  onClose,
  children,
  footer,
}) => {
  const modalRef = useRef<HTMLDivElement>(null)

  // ---- Escape key handler -------------------------------------------------

  useEffect(() => {
    if (!isOpen) return

    const handleKeyDown = (e: KeyboardEvent) => {
      if (e.key === 'Escape') {
        onClose()
      }
    }

    document.addEventListener('keydown', handleKeyDown)
    return () => {
      document.removeEventListener('keydown', handleKeyDown)
    }
  }, [isOpen, onClose])

  // ---- Focus the modal panel when it opens --------------------------------

  useEffect(() => {
    if (isOpen && modalRef.current) {
      modalRef.current.focus()
    }
  }, [isOpen])

  if (!isOpen) return null

  // ---- Overlay click handler — close only if click is on the overlay ------

  const handleOverlayClick = (e: React.MouseEvent<HTMLDivElement>) => {
    if (e.target === e.currentTarget) {
      onClose()
    }
  }

  return (
    <div
      role="dialog"
      aria-modal="true"
      aria-labelledby="mediapilot-modal-title"
      className="fixed inset-0 bg-black/40 z-40 flex items-center justify-center"
      onClick={handleOverlayClick}
    >
      <div
        ref={modalRef}
        tabIndex={-1}
        className="bg-white rounded-lg shadow-xl w-80 max-w-full mx-4 z-50 outline-none"
      >
        {/* Header */}
        <div className="flex items-center justify-between px-4 py-3 border-b border-slate-200">
          <h2
            id="mediapilot-modal-title"
            className="text-sm font-semibold text-slate-700"
          >
            {title}
          </h2>
          <button
            type="button"
            onClick={onClose}
            className="text-slate-400 hover:text-slate-600 cursor-pointer rounded p-0.5 hover:bg-slate-100 transition-colors"
            aria-label="Close dialog"
          >
            <CloseIcon />
          </button>
        </div>

        {/* Body */}
        <div className="px-4 py-4">{children}</div>

        {/* Footer */}
        {footer !== undefined ? (
          <div className="px-4 py-3 border-t border-slate-200 flex justify-end gap-2">
            {footer}
          </div>
        ) : (
          <div className="px-4 py-3 border-t border-slate-200 flex justify-end gap-2">
            <button
              type="button"
              onClick={onClose}
              className="text-xs px-3 py-1.5 rounded bg-slate-100 hover:bg-slate-200 text-slate-700 cursor-pointer transition-colors"
            >
              Close
            </button>
          </div>
        )}
      </div>
    </div>
  )
}

export default FolderModal
