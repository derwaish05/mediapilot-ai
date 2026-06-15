/**
 * useResizable.ts
 *
 * Reusable hook that manages a drag-to-resize interaction.
 *
 * Attach the returned `handleMouseDown` to a resize handle element's
 * `onMouseDown` prop. The hook tracks the drag on `document` so the
 * resize keeps working if the pointer drifts outside the handle.
 *
 * Listeners are cleaned up both when dragging ends and on unmount.
 */

import { useState, useRef, useCallback, useEffect } from 'react'

// ---------------------------------------------------------------------------
// Types
// ---------------------------------------------------------------------------

export interface UseResizableOptions {
  initialWidth: number
  minWidth?: number
  maxWidth?: number
  onResize?: (width: number) => void
}

export interface UseResizableResult {
  width: number
  isResizing: boolean
  handleMouseDown: (e: React.MouseEvent) => void
}

// ---------------------------------------------------------------------------
// Hook
// ---------------------------------------------------------------------------

export function useResizable({
  initialWidth,
  minWidth = 160,
  maxWidth = 480,
  onResize,
}: UseResizableOptions): UseResizableResult {
  const [width, setWidth] = useState<number>(initialWidth)
  const [isResizing, setIsResizing] = useState<boolean>(false)

  // Keep mutable refs so the document-level listeners always see fresh values
  // without being re-registered on every render.
  const isResizingRef = useRef<boolean>(false)
  const startXRef = useRef<number>(0)
  const startWidthRef = useRef<number>(initialWidth)

  // Sync width ref whenever the width changes so mousemove can compute deltas
  // relative to where the drag started rather than the current width.
  const widthRef = useRef<number>(initialWidth)
  widthRef.current = width

  // ---- Document listeners -------------------------------------------------

  const handleMouseMove = useCallback(
    (e: MouseEvent) => {
      if (!isResizingRef.current) return

      const delta = e.clientX - startXRef.current
      const next = startWidthRef.current + delta
      const clamped = Math.min(maxWidth, Math.max(minWidth, next))

      setWidth(clamped)
      onResize?.(clamped)
    },
    [minWidth, maxWidth, onResize],
  )

  const handleMouseUp = useCallback(() => {
    if (!isResizingRef.current) return

    isResizingRef.current = false
    setIsResizing(false)

    document.removeEventListener('mousemove', handleMouseMove)
    document.removeEventListener('mouseup', handleMouseUp)

    // Restore text selection that was suppressed during resize
    document.body.style.userSelect = ''
    document.body.style.cursor = ''
  }, [handleMouseMove])

  // ---- Expose mousedown handler -------------------------------------------

  const handleMouseDown = useCallback(
    (e: React.MouseEvent) => {
      // Only respond to left-button drag
      if (e.button !== 0) return

      e.preventDefault()

      isResizingRef.current = true
      startXRef.current = e.clientX
      startWidthRef.current = widthRef.current

      setIsResizing(true)

      // Suppress text selection while dragging
      document.body.style.userSelect = 'none'
      document.body.style.cursor = 'col-resize'

      document.addEventListener('mousemove', handleMouseMove)
      document.addEventListener('mouseup', handleMouseUp)
    },
    [handleMouseMove, handleMouseUp],
  )

  // ---- Cleanup on unmount -------------------------------------------------

  useEffect(() => {
    return () => {
      document.removeEventListener('mousemove', handleMouseMove)
      document.removeEventListener('mouseup', handleMouseUp)
      document.body.style.userSelect = ''
      document.body.style.cursor = ''
    }
  }, [handleMouseMove, handleMouseUp])

  // ---- Allow external updates to width (e.g. store hydration) -------------

  useEffect(() => {
    setWidth(initialWidth)
    widthRef.current = initialWidth
  }, [initialWidth])

  return { width, isResizing, handleMouseDown }
}
