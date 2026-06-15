/**
 * SearchBox.tsx
 *
 * A search input with:
 *   - Left-aligned SVG magnifier icon
 *   - Debounced onSearch callback (default 300 ms)
 *   - Clear (×) button that appears when the field has a value
 *   - focus-within ring on the outer wrapper
 *
 * The debounce is implemented with useEffect + setTimeout/clearTimeout so
 * there is no external lodash dependency.
 */

import React, { useState, useEffect, useRef, useCallback } from 'react'

// ---------------------------------------------------------------------------
// Inline SVG icons
// ---------------------------------------------------------------------------

const SearchIcon: React.FC = () => (
  <svg
    viewBox="0 0 20 20"
    fill="none"
    stroke="currentColor"
    strokeWidth={1.75}
    strokeLinecap="round"
    strokeLinejoin="round"
    className="w-4 h-4 text-slate-400 flex-shrink-0"
    aria-hidden="true"
  >
    <circle cx="8.5" cy="8.5" r="5.5" />
    <path d="M15 15l-3-3" />
  </svg>
)

const ClearIcon: React.FC = () => (
  <svg
    viewBox="0 0 20 20"
    fill="currentColor"
    className="w-4 h-4"
    aria-hidden="true"
  >
    <path
      fillRule="evenodd"
      d="M10 18a8 8 0 100-16 8 8 0 000 16zM8.707 7.293a1 1 0 00-1.414 1.414L8.586 10l-1.293 1.293a1 1 0 101.414 1.414L10 11.414l1.293 1.293a1 1 0 001.414-1.414L11.414 10l1.293-1.293a1 1 0 00-1.414-1.414L10 8.586 8.707 7.293z"
      clipRule="evenodd"
    />
  </svg>
)

// ---------------------------------------------------------------------------
// Props
// ---------------------------------------------------------------------------

interface SearchBoxProps {
  placeholder?: string
  onSearch: (query: string) => void
  debounceMs?: number
  className?: string
}

// ---------------------------------------------------------------------------
// Component
// ---------------------------------------------------------------------------

const SearchBox: React.FC<SearchBoxProps> = ({
  placeholder = 'Search folders…',
  onSearch,
  debounceMs = 300,
  className,
}) => {
  const [value, setValue] = useState('')
  const timerRef = useRef<ReturnType<typeof setTimeout> | null>(null)
  const inputRef = useRef<HTMLInputElement>(null)

  // ---- Debounced search callback ------------------------------------------

  useEffect(() => {
    if (timerRef.current !== null) {
      clearTimeout(timerRef.current)
    }

    timerRef.current = setTimeout(() => {
      timerRef.current = null
      onSearch(value)
    }, debounceMs)

    return () => {
      if (timerRef.current !== null) {
        clearTimeout(timerRef.current)
      }
    }
  }, [value, debounceMs, onSearch])

  // ---- Handlers ------------------------------------------------------------

  const handleChange = useCallback((e: React.ChangeEvent<HTMLInputElement>) => {
    setValue(e.target.value)
  }, [])

  const handleClear = useCallback(() => {
    setValue('')
    // Fire onSearch immediately with empty string on clear
    if (timerRef.current !== null) {
      clearTimeout(timerRef.current)
      timerRef.current = null
    }
    onSearch('')
    inputRef.current?.focus()
  }, [onSearch])

  const handleKeyDown = useCallback(
    (e: React.KeyboardEvent<HTMLInputElement>) => {
      if (e.key === 'Escape') {
        handleClear()
      }
    },
    [handleClear],
  )

  // ---- Render --------------------------------------------------------------

  return (
    <div
      className={[
        'flex items-center gap-2 px-3 py-1.5 bg-white border border-slate-200 rounded-mmp text-sm',
        'focus-within:border-blue-400 focus-within:ring-1 focus-within:ring-blue-400',
        className ?? '',
      ]
        .filter(Boolean)
        .join(' ')}
    >
      <SearchIcon />

      <input
        ref={inputRef}
        type="search"
        value={value}
        onChange={handleChange}
        onKeyDown={handleKeyDown}
        placeholder={placeholder}
        className="flex-1 bg-transparent outline-none text-sm text-mediapilot-text placeholder-slate-400 min-w-0"
        aria-label={placeholder}
        autoComplete="off"
        spellCheck={false}
      />

      {value.length > 0 && (
        <button
          type="button"
          onClick={handleClear}
          className="text-slate-400 hover:text-slate-600 flex-shrink-0 cursor-pointer"
          aria-label="Clear search"
          tabIndex={0}
        >
          <ClearIcon />
        </button>
      )}
    </div>
  )
}

export default SearchBox
