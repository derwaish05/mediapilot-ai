/**
 * TagPicker.tsx
 *
 * A typeahead multi-select for tags, with optional inline tag creation.
 *
 * Pattern:
 *   - Shows selected tags as removable TagBadge pills inside the input wrapper.
 *   - Typing opens a dropdown filtered by query.
 *   - Clicking a tag in the dropdown toggles it.
 *   - When `allowCreate` and there's no exact match, a "Create '<query>'" row
 *     appears at the bottom with a color-picker swatch.
 *   - Backspace on an empty input removes the last selected tag.
 *   - Clicking outside or pressing Escape closes the dropdown.
 *
 * The component is controlled: it receives `selectedTagIds` and calls
 * `onChange` — the parent owns the selection state.
 */

import React, {
  useState,
  useRef,
  useEffect,
  useCallback,
  useMemo,
} from 'react'
import type { MediaPilotTag } from '@/types'
import { TagBadge } from '@/components/TagBadge'
import { useTagStore } from '@/store/tagStore'

// ---------------------------------------------------------------------------
// Props
// ---------------------------------------------------------------------------

interface TagPickerProps {
  selectedTagIds: number[]
  onChange: (tagIds: number[]) => void
  placeholder?: string
  allowCreate?: boolean
}

// ---------------------------------------------------------------------------
// Component
// ---------------------------------------------------------------------------

export const TagPicker: React.FC<TagPickerProps> = ({
  selectedTagIds,
  onChange,
  placeholder = 'Search or create tags…',
  allowCreate = true,
}) => {
  const tags       = useTagStore((s) => s.tags)
  const createTag  = useTagStore((s) => s.createTag)

  const [query,    setQuery]    = useState('')
  const [open,     setOpen]     = useState(false)
  const [creating, setCreating] = useState(false)
  const [newColor, setNewColor] = useState('#3b82f6')

  const inputRef     = useRef<HTMLInputElement>(null)
  const containerRef = useRef<HTMLDivElement>(null)

  // ---- Derived values -------------------------------------------------------

  const selectedTags = useMemo(
    () => tags.filter((t) => selectedTagIds.includes(t.id)),
    [tags, selectedTagIds],
  )

  const filteredTags = useMemo(
    () =>
      tags.filter(
        (t) =>
          !selectedTagIds.includes(t.id) &&
          t.name.toLowerCase().includes(query.toLowerCase()),
      ),
    [tags, selectedTagIds, query],
  )

  const exactMatch = useMemo(
    () => tags.find((t) => t.name.toLowerCase() === query.trim().toLowerCase()),
    [tags, query],
  )

  const canCreate = allowCreate && query.trim() !== '' && !exactMatch

  // ---- Outside-click closes dropdown ----------------------------------------

  useEffect(() => {
    const handler = (e: MouseEvent) => {
      if (containerRef.current && !containerRef.current.contains(e.target as Node)) {
        setOpen(false)
        setQuery('')
      }
    }
    document.addEventListener('mousedown', handler)
    return () => document.removeEventListener('mousedown', handler)
  }, [])

  // ---- Callbacks ------------------------------------------------------------

  const toggleTag = useCallback(
    (tagId: number) => {
      onChange(
        selectedTagIds.includes(tagId)
          ? selectedTagIds.filter((id) => id !== tagId)
          : [...selectedTagIds, tagId],
      )
    },
    [selectedTagIds, onChange],
  )

  const removeTag = useCallback(
    (tagId: number) => {
      onChange(selectedTagIds.filter((id) => id !== tagId))
    },
    [selectedTagIds, onChange],
  )

  const handleCreate = useCallback(async () => {
    const name = query.trim()
    if (!name || creating) return

    setCreating(true)
    try {
      const tag = await createTag(name, newColor)
      onChange([...selectedTagIds, tag.id])
      setQuery('')
      setOpen(false)
    } finally {
      setCreating(false)
    }
  }, [query, newColor, creating, createTag, selectedTagIds, onChange])

  const handleKeyDown = useCallback(
    (e: React.KeyboardEvent<HTMLInputElement>) => {
      if (e.key === 'Enter' && canCreate) {
        e.preventDefault()
        handleCreate()
        return
      }
      if (e.key === 'Escape') {
        setOpen(false)
        setQuery('')
        return
      }
      if (e.key === 'Backspace' && query === '' && selectedTagIds.length > 0) {
        removeTag(selectedTagIds[selectedTagIds.length - 1])
      }
    },
    [canCreate, handleCreate, query, selectedTagIds, removeTag],
  )

  // ---- Render ---------------------------------------------------------------

  const showDropdown = open && (filteredTags.length > 0 || canCreate)

  return (
    <div ref={containerRef} className="relative w-full">
      {/* ---- Tag pills + text input ---------------------------------------- */}
      <div
        className="flex flex-wrap gap-1.5 p-2 min-h-[38px] border border-slate-300 rounded-md bg-white cursor-text focus-within:ring-2 focus-within:ring-blue-500 focus-within:border-blue-500 transition-shadow"
        onClick={() => {
          inputRef.current?.focus()
          setOpen(true)
        }}
      >
        {selectedTags.map((tag) => (
          <TagBadge key={tag.id} tag={tag} onRemove={() => removeTag(tag.id)} />
        ))}

        <input
          ref={inputRef}
          type="text"
          value={query}
          className="flex-1 min-w-[100px] outline-none text-sm text-slate-700 bg-transparent placeholder:text-slate-400"
          placeholder={selectedTags.length === 0 ? placeholder : ''}
          onChange={(e) => {
            setQuery(e.target.value)
            setOpen(true)
          }}
          onFocus={() => setOpen(true)}
          onKeyDown={handleKeyDown}
          role="combobox"
          aria-expanded={showDropdown}
          aria-autocomplete="list"
          aria-haspopup="listbox"
        />
      </div>

      {/* ---- Dropdown -------------------------------------------------------- */}
      {showDropdown && (
        <div className="absolute z-50 top-full mt-1 w-full bg-white border border-slate-200 rounded-md shadow-lg overflow-hidden">
          {filteredTags.length > 0 && (
            <ul
              className="max-h-48 overflow-y-auto py-1"
              role="listbox"
              aria-label="Available tags"
            >
              {filteredTags.map((tag) => (
                <li
                  key={tag.id}
                  role="option"
                  aria-selected={false}
                  className="flex items-center gap-2 px-3 py-1.5 cursor-pointer hover:bg-slate-50 text-sm select-none"
                  onMouseDown={(e) => {
                    e.preventDefault() // don't blur input
                    toggleTag(tag.id)
                  }}
                >
                  <span
                    className="w-3 h-3 rounded-full shrink-0"
                    style={{ backgroundColor: tag.color }}
                    aria-hidden="true"
                  />
                  <span className="text-slate-700 flex-1">{tag.name}</span>
                  {typeof tag.usage_count === 'number' && (
                    <span className="text-xs text-slate-400">{tag.usage_count}</span>
                  )}
                </li>
              ))}
            </ul>
          )}

          {/* ---- Create new tag row ----------------------------------------- */}
          {canCreate && (
            <div className="border-t border-slate-100 px-3 py-2">
              <div className="flex items-center gap-2">
                {/* Color swatch */}
                <input
                  type="color"
                  value={newColor}
                  onChange={(e) => setNewColor(e.target.value)}
                  className="w-6 h-6 rounded cursor-pointer border border-slate-200 p-0 shrink-0"
                  title="Tag color"
                  onMouseDown={(e) => e.stopPropagation()}
                />
                <button
                  type="button"
                  className="flex-1 text-left text-sm text-blue-600 hover:text-blue-800 disabled:opacity-50 transition-colors"
                  onMouseDown={(e) => {
                    e.preventDefault()
                    handleCreate()
                  }}
                  disabled={creating}
                >
                  {creating ? 'Creating…' : `Create "${query.trim()}"`}
                </button>
              </div>
            </div>
          )}
        </div>
      )}
    </div>
  )
}

export default TagPicker
