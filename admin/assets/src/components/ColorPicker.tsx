/**
 * ColorPicker.tsx
 *
 * An inline colour picker for assigning a hex colour to a folder.
 *
 * Layout:
 *   - 16 preset colour swatches arranged in a 8-column grid
 *   - A custom HEX input row with a live preview dot and Apply button
 *
 * Behaviours:
 *   - Clicking a preset swatch immediately calls onChange + onClose
 *   - The swatch matching the current value receives a ring indicator
 *   - Custom HEX input: validated on Apply click
 *   - Invalid hex shows an error message in red beneath the input
 *   - The preview dot next to the input updates live as the user types
 *   - Pressing Escape anywhere inside the picker calls onClose
 *
 * Wrapped in React.memo — only re-renders when value or callbacks change.
 */

import React, { useState, useCallback, useEffect, useRef, memo } from 'react'

// ---------------------------------------------------------------------------
// Preset colours — 16 swatches
// ---------------------------------------------------------------------------

const PRESET_COLORS: string[] = [
  '#ef4444', // red
  '#f97316', // orange
  '#f59e0b', // amber
  '#eab308', // yellow
  '#84cc16', // lime
  '#22c55e', // green
  '#10b981', // emerald
  '#14b8a6', // teal
  '#06b6d4', // cyan
  '#3b82f6', // blue (default)
  '#6366f1', // indigo
  '#8b5cf6', // violet
  '#a855f7', // purple
  '#ec4899', // pink
  '#94a3b8', // slate (neutral / grey)
  '#1e293b', // dark slate (near-black)
]

// ---------------------------------------------------------------------------
// Helpers
// ---------------------------------------------------------------------------

const HEX_REGEX = /^#([0-9a-fA-F]{3}|[0-9a-fA-F]{6})$/

function isValidHex(value: string): boolean {
  return HEX_REGEX.test(value)
}

/** Normalise a partial hex string to uppercase for the input display. */
function normaliseHex(raw: string): string {
  return raw.toUpperCase()
}

// ---------------------------------------------------------------------------
// Props
// ---------------------------------------------------------------------------

interface ColorPickerProps {
  /** Current hex colour, e.g. '#3b82f6' */
  value: string
  /** Called with the newly selected hex colour */
  onChange: (color: string) => void
  /** Called when the picker should close */
  onClose: () => void
}

// ---------------------------------------------------------------------------
// Component
// ---------------------------------------------------------------------------

const ColorPicker: React.FC<ColorPickerProps> = memo(function ColorPicker({
  value,
  onChange,
  onClose,
}) {
  const [hexInput, setHexInput] = useState<string>(value.toUpperCase())
  const [hexError, setHexError] = useState<string>('')

  const containerRef = useRef<HTMLDivElement>(null)

  // ---- Escape key handler --------------------------------------------------

  useEffect(() => {
    const handleKeyDown = (e: KeyboardEvent) => {
      if (e.key === 'Escape') {
        e.stopPropagation()
        onClose()
      }
    }
    document.addEventListener('keydown', handleKeyDown, true)
    return () => {
      document.removeEventListener('keydown', handleKeyDown, true)
    }
  }, [onClose])

  // ---- Preset swatch click -------------------------------------------------

  const handleSwatchClick = useCallback(
    (color: string) => {
      onChange(color)
      onClose()
    },
    [onChange, onClose],
  )

  // ---- Custom HEX input ----------------------------------------------------

  const handleHexChange = useCallback(
    (e: React.ChangeEvent<HTMLInputElement>) => {
      const raw = e.target.value
      setHexInput(normaliseHex(raw))
      // Clear error as user types
      if (hexError) {
        setHexError('')
      }
    },
    [hexError],
  )

  const handleApply = useCallback(() => {
    const trimmed = hexInput.trim()
    if (!isValidHex(trimmed)) {
      setHexError('Invalid hex color')
      return
    }
    setHexError('')
    onChange(trimmed.toLowerCase())
    onClose()
  }, [hexInput, onChange, onClose])

  const handleInputKeyDown = useCallback(
    (e: React.KeyboardEvent<HTMLInputElement>) => {
      if (e.key === 'Enter') {
        e.preventDefault()
        handleApply()
      }
      // Escape is handled by the document listener above
    },
    [handleApply],
  )

  // ---- Compute live preview colour ----------------------------------------

  const previewColor = isValidHex(hexInput.trim()) ? hexInput.trim() : '#94a3b8'

  // ---- Render --------------------------------------------------------------

  return (
    <div
      ref={containerRef}
      className="bg-white border border-slate-200 rounded-lg shadow-lg p-3 w-56"
      role="dialog"
      aria-label="Choose a folder colour"
      // Prevent clicks inside the picker from bubbling up and closing the
      // context menu via document mousedown listeners.
      onMouseDown={(e) => e.stopPropagation()}
    >
      {/* Swatch grid — 8 per row */}
      <div className="grid grid-cols-8 gap-1.5 mb-3" role="group" aria-label="Preset colours">
        {PRESET_COLORS.map((color) => {
          const isSelected =
            color.toLowerCase() === value.toLowerCase()
          return (
            <button
              key={color}
              type="button"
              title={color}
              aria-label={`Select colour ${color}`}
              aria-pressed={isSelected}
              onClick={() => handleSwatchClick(color)}
              className={[
                'w-6 h-6 rounded-full cursor-pointer transition-transform hover:scale-110 focus:outline-none focus:ring-2 focus:ring-offset-1 focus:ring-slate-400',
                isSelected ? 'ring-2 ring-offset-1 ring-slate-400' : '',
              ]
                .filter(Boolean)
                .join(' ')}
              style={{ backgroundColor: color }}
            />
          )
        })}
      </div>

      {/* Divider */}
      <div className="border-t border-slate-100 pt-2">
        {/* HEX input row */}
        <div className="flex items-center gap-2 mt-2">
          {/* Live preview dot */}
          <span
            className="w-6 h-6 rounded-full flex-shrink-0 border border-slate-200"
            style={{ backgroundColor: previewColor }}
            aria-hidden="true"
          />

          {/* HEX text input */}
          <input
            type="text"
            value={hexInput}
            onChange={handleHexChange}
            onKeyDown={handleInputKeyDown}
            placeholder="#3B82F6"
            maxLength={7}
            aria-label="Custom hex colour"
            aria-invalid={hexError !== ''}
            aria-describedby={hexError ? 'mediapilot-hex-error' : undefined}
            className="flex-1 text-xs font-mono border border-slate-200 rounded px-2 py-1 outline-none focus:border-blue-400 uppercase"
            spellCheck={false}
            autoComplete="off"
          />

          {/* Apply button */}
          <button
            type="button"
            onClick={handleApply}
            className="text-xs bg-blue-600 text-white px-2 py-1 rounded hover:bg-blue-700 cursor-pointer whitespace-nowrap focus:outline-none focus:ring-2 focus:ring-blue-400 focus:ring-offset-1"
          >
            Apply
          </button>
        </div>

        {/* Validation error */}
        {hexError && (
          <p id="mediapilot-hex-error" className="text-xs text-red-500 mt-1" role="alert">
            {hexError}
          </p>
        )}
      </div>
    </div>
  )
})

ColorPicker.displayName = 'ColorPicker'

export default ColorPicker
