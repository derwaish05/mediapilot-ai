/**
 * AdvancedSearchPanel.tsx (S44 + S48 + S58)
 *
 * Collapsible panel rendered in the media library sidebar that lets users
 * build multi-criteria searches across all media attachments:
 *
 *   • Text query (title / filename)
 *   • AI Smart Search toggle — semantic synonym-aware search (S48)
 *   • Folder
 *   • File type group (image / pdf / video / audio / document / archive)
 *   • Upload date range
 *   • File size range (bytes)
 *   • Missing ALT text toggle
 *   • Used / Unused status
 *   • EXIF camera model
 *   • EXIF date-taken range
 *   • Dominant colour swatch picker (S58)
 *   • Orientation button group (landscape / portrait / square) (S58)
 *   • EXIF ISO, aperture, focal length (S58)
 *   • Find Similar button on each result row (S58)
 *
 * Results are displayed inline with folder attribution, dominant colour chip,
 * and orientation badge on each row. Searches can be saved by name and
 * re-applied from a dropdown.
 */

import React, { useState, useCallback, useEffect, useRef } from 'react'
import { apiFetch, apiPost, apiDelete } from '@/api/client'
import { useFolderStore } from '@/store/folderStore'
import type { MediaPilotFolder } from '@/types'

// ---------------------------------------------------------------------------
// Types
// ---------------------------------------------------------------------------

interface SearchFilters {
  q: string
  folder: number | ''
  type: string
  date_from: string
  date_to: string
  size_min: string
  size_max: string
  missing_alt: boolean
  used: '' | 'true' | 'false'
  camera: string
  date_taken_from: string
  date_taken_to: string
  color: string
  orientation: '' | 'landscape' | 'portrait' | 'square'
  iso: string
  aperture: string
  focal_length: string
}

interface SearchFile {
  id: number
  title: string
  filename: string
  url: string
  mime_type: string
  size_human: string
  date: string
  alt_text: string
  used: boolean
  folder_id: number
  folder_name: string
  camera: string
  dominant_color?: string
  orientation?: string
}

interface SearchResult {
  files: SearchFile[]
  total: number
  pages: number
  current_page: number
  ai_enhanced?: boolean
  expanded_terms?: string[]
}

interface SavedFilter {
  id: number
  name: string
  params: SearchFilters
}

// ---------------------------------------------------------------------------
// Constants
// ---------------------------------------------------------------------------

const EMPTY_FILTERS: SearchFilters = {
  q: '',
  folder: '',
  type: '',
  date_from: '',
  date_to: '',
  size_min: '',
  size_max: '',
  missing_alt: false,
  used: '',
  camera: '',
  date_taken_from: '',
  date_taken_to: '',
  color: '',
  orientation: '',
  iso: '',
  aperture: '',
  focal_length: '',
}

// Preset colour swatches for the colour picker.
const COLOR_SWATCHES = [
  { hex: 'e74c3c', label: 'Red'    },
  { hex: 'e67e22', label: 'Orange' },
  { hex: 'f1c40f', label: 'Yellow' },
  { hex: '2ecc71', label: 'Green'  },
  { hex: '3498db', label: 'Blue'   },
  { hex: '9b59b6', label: 'Purple' },
  { hex: 'ecf0f1', label: 'White'  },
  { hex: '95a5a6', label: 'Grey'   },
  { hex: '2c3e50', label: 'Black'  },
]

const ORIENTATIONS: { value: '' | 'landscape' | 'portrait' | 'square'; label: string }[] = [
  { value: '',          label: 'Any'       },
  { value: 'landscape', label: 'Landscape' },
  { value: 'portrait',  label: 'Portrait'  },
  { value: 'square',    label: 'Square'    },
]

const FILE_TYPES = [
  { value: 'image',    label: 'Images' },
  { value: 'pdf',      label: 'PDFs' },
  { value: 'video',    label: 'Video' },
  { value: 'audio',    label: 'Audio' },
  { value: 'document', label: 'Documents' },
  { value: 'archive',  label: 'Archives' },
]

// ---------------------------------------------------------------------------
// Flat folder list helper
// ---------------------------------------------------------------------------

function flattenFolders(
  nodes: MediaPilotFolder[],
  depth = 0,
): { id: number; label: string }[] {
  const result: { id: number; label: string }[] = []
  for (const n of nodes) {
    result.push({ id: n.id, label: '— '.repeat(depth) + n.name })
    if (n.children?.length) result.push(...flattenFolders(n.children, depth + 1))
  }
  return result
}

// ---------------------------------------------------------------------------
// Sub-components
// ---------------------------------------------------------------------------

const Label: React.FC<{ children: React.ReactNode }> = ({ children }) => (
  <label className="block text-xs font-semibold text-slate-500 uppercase tracking-wide mb-1">
    {children}
  </label>
)

const Input: React.FC<React.InputHTMLAttributes<HTMLInputElement>> = (props) => (
  <input
    {...props}
    className={[
      'w-full border border-slate-200 rounded px-2 py-1 text-sm bg-white',
      'focus:outline-none focus:border-blue-400 focus:ring-1 focus:ring-blue-400',
      props.className ?? '',
    ]
      .filter(Boolean)
      .join(' ')}
  />
)

const Select: React.FC<React.SelectHTMLAttributes<HTMLSelectElement>> = (props) => (
  <select
    {...props}
    className={[
      'w-full border border-slate-200 rounded px-2 py-1 text-sm bg-white',
      'focus:outline-none focus:border-blue-400 focus:ring-1 focus:ring-blue-400',
      props.className ?? '',
    ]
      .filter(Boolean)
      .join(' ')}
  />
)

// ---------------------------------------------------------------------------
// Main component
// ---------------------------------------------------------------------------

const AdvancedSearchPanel: React.FC = () => {
  const tree = useFolderStore((s) => s.tree)
  const folders = flattenFolders(tree)

  const [open, setOpen] = useState(false)
  const [filters, setFilters] = useState<SearchFilters>(EMPTY_FILTERS)
  const [smartSearch, setSmartSearch] = useState(false)
  const [result, setResult] = useState<SearchResult | null>(null)
  const [loading, setLoading] = useState(false)
  const [error, setError] = useState('')
  const [page, setPage] = useState(1)

  // Saved filters
  const [savedFilters, setSavedFilters] = useState<SavedFilter[]>([])
  const [saveName, setSaveName] = useState('')
  const [showSaveInput, setShowSaveInput] = useState(false)
  const [saveLoading, setSaveLoading] = useState(false)

  // Load saved filters on open
  useEffect(() => {
    if (!open) return
    apiFetch<SavedFilter[]>('search/filters')
      .then(setSavedFilters)
      .catch(() => {})
  }, [open])

  // ---- Helpers --------------------------------------------------------------

  const set = useCallback(
    <K extends keyof SearchFilters>(key: K, value: SearchFilters[K]) => {
      setFilters((prev) => ({ ...prev, [key]: value }))
      setResult(null)
      setPage(1)
    },
    [],
  )

  const buildQueryString = useCallback(
    (f: SearchFilters, p: number, ai: boolean) => {
      const params = new URLSearchParams()
      if (f.q)               params.set('q',               f.q)
      if (f.folder !== '')   params.set('folder',           String(f.folder))
      if (f.type)            params.set('type',             f.type)
      if (f.date_from)       params.set('date_from',        f.date_from)
      if (f.date_to)         params.set('date_to',          f.date_to)
      if (f.size_min)        params.set('size_min',         f.size_min)
      if (f.size_max)        params.set('size_max',         f.size_max)
      if (f.missing_alt)     params.set('missing_alt',      'true')
      if (f.used)            params.set('used',             f.used)
      if (f.camera)          params.set('camera',           f.camera)
      if (f.date_taken_from) params.set('date_taken_from',  f.date_taken_from)
      if (f.date_taken_to)   params.set('date_taken_to',    f.date_taken_to)
      if (f.color)           params.set('color',            f.color)
      if (f.orientation)     params.set('orientation',      f.orientation)
      if (f.iso)             params.set('iso',              f.iso)
      if (f.aperture)        params.set('aperture',         f.aperture)
      if (f.focal_length)    params.set('focal_length',     f.focal_length)
      if (ai)                params.set('ai',               'true')
      params.set('page',     String(p))
      params.set('per_page', '40')
      return `search?${params.toString()}`
    },
    [],
  )

  // ---- Search ---------------------------------------------------------------

  const runSearch = useCallback(
    async (f: SearchFilters, p: number, ai: boolean) => {
      setLoading(true)
      setError('')
      try {
        const data = await apiFetch<SearchResult>(buildQueryString(f, p, ai))
        setResult(data)
        setPage(p)
      } catch (e) {
        setError((e as Error).message)
      } finally {
        setLoading(false)
      }
    },
    [buildQueryString],
  )

  const handleSearch = useCallback(() => {
    runSearch(filters, 1, smartSearch)
  }, [filters, runSearch, smartSearch])

  const handleReset = useCallback(() => {
    setFilters(EMPTY_FILTERS)
    setResult(null)
    setPage(1)
    setError('')
  }, [])

  // ---- Saved filters --------------------------------------------------------

  const handleApplySaved = useCallback(
    (sf: SavedFilter) => {
      setFilters(sf.params)
      setResult(null)
      setPage(1)
      runSearch(sf.params, 1, smartSearch)
    },
    [runSearch, smartSearch],
  )

  const handleSave = useCallback(async () => {
    if (!saveName.trim()) return
    setSaveLoading(true)
    try {
      await apiPost<{ id: number }>('search/filters', { name: saveName.trim(), params: filters })
      const updated = await apiFetch<SavedFilter[]>('search/filters')
      setSavedFilters(updated)
      setSaveName('')
      setShowSaveInput(false)
    } catch {
      // noop — UI is non-critical
    } finally {
      setSaveLoading(false)
    }
  }, [saveName, filters])

  const handleDeleteSaved = useCallback(async (id: number) => {
    try {
      await apiDelete(`search/filters/${id}`)
      setSavedFilters((prev) => prev.filter((f) => f.id !== id))
    } catch {
      // noop
    }
  }, [])

  // ---- Render ---------------------------------------------------------------

  return (
    <div className="border-t border-slate-200 mt-2">
      {/* Toggle button */}
      <button
        type="button"
        onClick={() => setOpen((o) => !o)}
        className="w-full flex items-center justify-between px-3 py-2 text-sm font-medium text-slate-700 hover:bg-slate-50 transition-colors"
        aria-expanded={open}
      >
        <span className="flex items-center gap-1.5">
          {/* Filter icon */}
          <svg viewBox="0 0 20 20" fill="currentColor" className="w-4 h-4 text-slate-400" aria-hidden="true">
            <path fillRule="evenodd" d="M3 3a1 1 0 011-1h12a1 1 0 011 1v3a1 1 0 01-.293.707L13 10.414V15a1 1 0 01-.553.894l-4 2A1 1 0 017 17v-6.586L3.293 6.707A1 1 0 013 6V3z" clipRule="evenodd" />
          </svg>
          Advanced Search
        </span>
        <svg viewBox="0 0 20 20" fill="currentColor" className={`w-4 h-4 text-slate-400 transition-transform ${open ? 'rotate-180' : ''}`} aria-hidden="true">
          <path fillRule="evenodd" d="M5.293 7.293a1 1 0 011.414 0L10 10.586l3.293-3.293a1 1 0 111.414 1.414l-4 4a1 1 0 01-1.414 0l-4-4a1 1 0 010-1.414z" clipRule="evenodd" />
        </svg>
      </button>

      {open && (
        <div className="px-3 pb-3 space-y-3">

          {/* Saved filters */}
          {savedFilters.length > 0 && (
            <div>
              <Label>Saved Filters</Label>
              <div className="space-y-1">
                {savedFilters.map((sf) => (
                  <div key={sf.id} className="flex items-center gap-1">
                    <button
                      type="button"
                      onClick={() => handleApplySaved(sf)}
                      className="flex-1 text-left text-xs text-blue-600 hover:text-blue-800 truncate"
                    >
                      {sf.name}
                    </button>
                    <button
                      type="button"
                      onClick={() => handleDeleteSaved(sf.id)}
                      className="text-slate-400 hover:text-red-500 flex-shrink-0"
                      aria-label={`Delete filter "${sf.name}"`}
                    >
                      <svg viewBox="0 0 20 20" fill="currentColor" className="w-3.5 h-3.5" aria-hidden="true">
                        <path fillRule="evenodd" d="M4.293 4.293a1 1 0 011.414 0L10 8.586l4.293-4.293a1 1 0 111.414 1.414L11.414 10l4.293 4.293a1 1 0 01-1.414 1.414L10 11.414l-4.293 4.293a1 1 0 01-1.414-1.414L8.586 10 4.293 5.707a1 1 0 010-1.414z" clipRule="evenodd" />
                      </svg>
                    </button>
                  </div>
                ))}
              </div>
            </div>
          )}

          {/* Text search + Smart Search toggle */}
          <div>
            <div className="flex items-center justify-between mb-1">
              <Label>Search</Label>
              <button
                type="button"
                onClick={() => { setSmartSearch((s) => !s); setResult(null) }}
                title={smartSearch ? 'Smart Search ON — using AI label index with synonym expansion' : 'Enable Smart Search — find images by concept (e.g. "car" finds vehicle, automobile…)'}
                className={[
                  'flex items-center gap-1 px-1.5 py-0.5 rounded text-[10px] font-semibold border transition-colors',
                  smartSearch
                    ? 'bg-indigo-500 border-indigo-500 text-white'
                    : 'bg-white border-slate-200 text-slate-500 hover:border-indigo-400 hover:text-indigo-600',
                ].join(' ')}
                aria-pressed={smartSearch}
              >
                <svg viewBox="0 0 16 16" fill="currentColor" className="w-3 h-3" aria-hidden="true">
                  <path d="M8 1a7 7 0 100 14A7 7 0 008 1zm0 1.5a5.5 5.5 0 110 11 5.5 5.5 0 010-11zm0 2a1 1 0 100 2 1 1 0 000-2zm-.75 3.25a.75.75 0 011.5 0v3a.75.75 0 01-1.5 0v-3z"/>
                </svg>
                AI Smart Search
              </button>
            </div>
            <Input
              type="text"
              value={filters.q}
              onChange={(e) => set('q', e.target.value)}
              placeholder={smartSearch ? 'Concept search (e.g. car, nature, logo)…' : 'Title or filename…'}
              onKeyDown={(e) => e.key === 'Enter' && handleSearch()}
            />
            {smartSearch && (
              <p className="text-[10px] text-indigo-500 mt-0.5">
                Synonym-aware — "car" also finds vehicle, automobile, transport
              </p>
            )}
          </div>

          {/* Folder */}
          <div>
            <Label>Folder</Label>
            <Select
              value={filters.folder === '' ? '' : String(filters.folder)}
              onChange={(e) => set('folder', e.target.value === '' ? '' : Number(e.target.value))}
            >
              <option value="">All folders</option>
              <option value="0">Uncategorised</option>
              {folders.map((f) => (
                <option key={f.id} value={f.id}>{f.label}</option>
              ))}
            </Select>
          </div>

          {/* File type */}
          <div>
            <Label>File Type</Label>
            <div className="flex flex-wrap gap-1">
              {FILE_TYPES.map(({ value, label }) => {
                const active = filters.type.split(',').map((t) => t.trim()).includes(value)
                return (
                  <button
                    key={value}
                    type="button"
                    onClick={() => {
                      const types = filters.type ? filters.type.split(',').map((t) => t.trim()) : []
                      const next = active ? types.filter((t) => t !== value) : [...types, value]
                      set('type', next.filter(Boolean).join(','))
                    }}
                    className={[
                      'px-2 py-0.5 rounded text-xs border transition-colors',
                      active
                        ? 'bg-blue-500 border-blue-500 text-white'
                        : 'bg-white border-slate-200 text-slate-600 hover:border-blue-400',
                    ].join(' ')}
                  >
                    {label}
                  </button>
                )
              })}
            </div>
          </div>

          {/* Upload date range */}
          <div>
            <Label>Upload Date</Label>
            <div className="flex gap-2">
              <Input type="date" value={filters.date_from} onChange={(e) => set('date_from', e.target.value)} title="From" />
              <Input type="date" value={filters.date_to}   onChange={(e) => set('date_to',   e.target.value)} title="To" />
            </div>
          </div>

          {/* Size range */}
          <div>
            <Label>File Size</Label>
            <div className="flex gap-2 items-center">
              <Input
                type="number"
                value={filters.size_min}
                onChange={(e) => set('size_min', e.target.value)}
                placeholder="Min bytes"
                min={0}
              />
              <span className="text-xs text-slate-400 flex-shrink-0">–</span>
              <Input
                type="number"
                value={filters.size_max}
                onChange={(e) => set('size_max', e.target.value)}
                placeholder="Max bytes"
                min={0}
              />
            </div>
          </div>

          {/* Toggles */}
          <div className="space-y-2">
            <label className="flex items-center gap-2 cursor-pointer">
              <input
                type="checkbox"
                checked={filters.missing_alt}
                onChange={(e) => set('missing_alt', e.target.checked)}
                className="rounded border-slate-300 text-blue-500 focus:ring-blue-400"
              />
              <span className="text-sm text-slate-700">Missing ALT text only</span>
            </label>

            <div className="flex items-center gap-2">
              <span className="text-sm text-slate-700 flex-shrink-0">Usage:</span>
              <Select
                value={filters.used}
                onChange={(e) => set('used', e.target.value as '' | 'true' | 'false')}
                className="flex-1"
              >
                <option value="">Any</option>
                <option value="true">Used</option>
                <option value="false">Unused</option>
              </Select>
            </div>
          </div>

          {/* EXIF filters */}
          <div>
            <Label>EXIF — Camera Model</Label>
            <Input
              type="text"
              value={filters.camera}
              onChange={(e) => set('camera', e.target.value)}
              placeholder="e.g. iPhone 15 Pro"
            />
          </div>

          <div>
            <Label>EXIF — Date Taken</Label>
            <div className="flex gap-2">
              <Input type="date" value={filters.date_taken_from} onChange={(e) => set('date_taken_from', e.target.value)} title="From" />
              <Input type="date" value={filters.date_taken_to}   onChange={(e) => set('date_taken_to',   e.target.value)} title="To" />
            </div>
          </div>

          {/* EXIF — ISO / Aperture / Focal Length */}
          <div>
            <Label>EXIF — ISO / Aperture / Focal Length</Label>
            <div className="flex gap-2">
              <Input
                type="text"
                value={filters.iso}
                onChange={(e) => set('iso', e.target.value)}
                placeholder="ISO"
                className="flex-1"
              />
              <Input
                type="text"
                value={filters.aperture}
                onChange={(e) => set('aperture', e.target.value)}
                placeholder="f/"
                className="flex-1"
              />
              <Input
                type="text"
                value={filters.focal_length}
                onChange={(e) => set('focal_length', e.target.value)}
                placeholder="mm"
                className="flex-1"
              />
            </div>
          </div>

          {/* Dominant colour */}
          <div>
            <Label>Dominant Colour</Label>
            <div className="flex flex-wrap gap-1.5 items-center">
              {COLOR_SWATCHES.map(({ hex, label }) => {
                const active = filters.color === hex
                return (
                  <button
                    key={hex}
                    type="button"
                    title={label}
                    onClick={() => set('color', active ? '' : hex)}
                    style={{ backgroundColor: `#${hex}` }}
                    className={[
                      'w-6 h-6 rounded-full border-2 transition-transform hover:scale-110',
                      active ? 'border-blue-500 scale-110 ring-2 ring-blue-300' : 'border-white shadow',
                    ].join(' ')}
                    aria-pressed={active}
                    aria-label={label}
                  />
                )
              })}
              {/* Custom hex input */}
              <div className="flex items-center gap-1 ml-1">
                <span className="text-xs text-slate-400">#</span>
                <input
                  type="text"
                  maxLength={6}
                  value={filters.color}
                  onChange={(e) => set('color', e.target.value.replace(/[^0-9a-fA-F]/g, '').toLowerCase())}
                  placeholder="custom"
                  className="w-16 border border-slate-200 rounded px-1.5 py-0.5 text-xs font-mono focus:outline-none focus:border-blue-400"
                />
              </div>
            </div>
          </div>

          {/* Orientation */}
          <div>
            <Label>Orientation</Label>
            <div className="flex gap-1">
              {ORIENTATIONS.map(({ value, label }) => (
                <button
                  key={value}
                  type="button"
                  onClick={() => set('orientation', value)}
                  className={[
                    'flex-1 px-2 py-1 rounded text-xs border transition-colors',
                    filters.orientation === value
                      ? 'bg-blue-500 border-blue-500 text-white'
                      : 'bg-white border-slate-200 text-slate-600 hover:border-blue-400',
                  ].join(' ')}
                >
                  {label}
                </button>
              ))}
            </div>
          </div>

          {/* Action buttons */}
          <div className="flex gap-2 pt-1">
            <button
              type="button"
              onClick={handleSearch}
              disabled={loading}
              className="flex-1 bg-blue-500 hover:bg-blue-600 disabled:opacity-50 text-white text-sm font-medium py-1.5 rounded transition-colors"
            >
              {loading ? 'Searching…' : 'Search'}
            </button>
            <button
              type="button"
              onClick={handleReset}
              className="px-3 py-1.5 border border-slate-200 rounded text-sm text-slate-600 hover:bg-slate-50 transition-colors"
            >
              Reset
            </button>
          </div>

          {/* Error */}
          {error && <p className="text-xs text-red-500">{error}</p>}

          {/* Save filter */}
          {result !== null && (
            <div>
              {showSaveInput ? (
                <div className="flex gap-1">
                  <Input
                    type="text"
                    value={saveName}
                    onChange={(e) => setSaveName(e.target.value)}
                    placeholder="Filter name…"
                    onKeyDown={(e) => e.key === 'Enter' && handleSave()}
                    autoFocus
                  />
                  <button
                    type="button"
                    onClick={handleSave}
                    disabled={saveLoading || !saveName.trim()}
                    className="px-2 py-1 bg-blue-500 disabled:opacity-50 text-white text-xs rounded"
                  >
                    Save
                  </button>
                  <button
                    type="button"
                    onClick={() => { setShowSaveInput(false); setSaveName('') }}
                    className="px-2 py-1 border border-slate-200 text-xs rounded"
                  >
                    ✕
                  </button>
                </div>
              ) : (
                <button
                  type="button"
                  onClick={() => setShowSaveInput(true)}
                  className="text-xs text-blue-600 hover:underline"
                >
                  Save this search…
                </button>
              )}
            </div>
          )}

          {/* Results */}
          {result !== null && (
            <div className="mt-2">
              <p className="text-xs text-slate-500 mb-2">
                {result.total === 0
                  ? 'No files found.'
                  : `${result.total} file${result.total !== 1 ? 's' : ''} found`}
                {result.pages > 1 && ` — page ${result.current_page} of ${result.pages}`}
                {result.ai_enhanced && (
                  <span className="ml-1.5 inline-flex items-center gap-0.5 text-[10px] font-semibold text-indigo-600 bg-indigo-50 border border-indigo-200 rounded px-1">
                    AI
                    {result.expanded_terms && result.expanded_terms.length > 1 && (
                      <span className="font-normal text-indigo-400">
                        {' '}(+{result.expanded_terms.length - 1} synonyms)
                      </span>
                    )}
                  </span>
                )}
                {smartSearch && !result.ai_enhanced && result.total > 0 && (
                  <span className="ml-1.5 text-[10px] text-slate-400">(text fallback)</span>
                )}
              </p>

              {result.files.length > 0 && (
                <div className="space-y-1 max-h-64 overflow-y-auto">
                  {result.files.map((file) => (
                    <div
                      key={file.id}
                      className="flex items-start gap-2 p-1.5 rounded hover:bg-slate-50 group"
                    >
                      {/* Colour chip */}
                      {file.dominant_color && (
                        <button
                          type="button"
                          title={`Filter by colour #${file.dominant_color}`}
                          onClick={() => set('color', file.dominant_color!)}
                          style={{ backgroundColor: `#${file.dominant_color}` }}
                          className="w-4 h-4 rounded-full border border-white shadow flex-shrink-0 mt-0.5 hover:scale-125 transition-transform"
                          aria-label={`Filter by colour #${file.dominant_color}`}
                        />
                      )}

                      {/* File info */}
                      <a
                        href={file.url}
                        target="_blank"
                        rel="noopener noreferrer"
                        className="flex-1 min-w-0"
                      >
                        <p className="text-xs font-medium text-slate-800 truncate group-hover:text-blue-600">
                          {file.title || file.filename}
                        </p>
                        <p className="text-xs text-slate-400 truncate">
                          {file.folder_name ? `📁 ${file.folder_name}` : 'Uncategorised'}
                          {' · '}
                          {file.size_human}
                          {file.camera ? ` · ${file.camera}` : ''}
                          {file.orientation ? ` · ${file.orientation}` : ''}
                        </p>
                        {!file.alt_text && file.mime_type.startsWith('image/') && (
                          <span className="inline-block text-[10px] text-amber-600 bg-amber-50 border border-amber-200 rounded px-1">
                            No ALT
                          </span>
                        )}
                      </a>

                      {/* Find Similar button (images only) */}
                      {file.mime_type.startsWith('image/') && (
                        <button
                          type="button"
                          title="Find visually similar images"
                          onClick={async () => {
                            setLoading(true)
                            setError('')
                            try {
                              const data = await apiFetch<{ attachment_id: number; similar: SearchFile[] }>(
                                `files/similar/${file.id}`
                              )
                              setResult({
                                files: data.similar,
                                total: data.similar.length,
                                pages: 1,
                                current_page: 1,
                              })
                            } catch (e) {
                              setError((e as Error).message)
                            } finally {
                              setLoading(false)
                            }
                          }}
                          className="flex-shrink-0 text-slate-400 hover:text-indigo-500 transition-colors"
                          aria-label={`Find images similar to ${file.title || file.filename}`}
                        >
                          <svg viewBox="0 0 20 20" fill="currentColor" className="w-3.5 h-3.5" aria-hidden="true">
                            <path d="M9 6a3 3 0 100 6 3 3 0 000-6zM2 9a7 7 0 1112.452 4.391l3.328 3.329a.75.75 0 11-1.06 1.06l-3.329-3.328A7 7 0 012 9z" />
                          </svg>
                        </button>
                      )}
                    </div>
                  ))}
                </div>
              )}

              {/* Pagination */}
              {result.pages > 1 && (
                <div className="flex justify-between items-center mt-2">
                  <button
                    type="button"
                    disabled={page <= 1 || loading}
                    onClick={() => runSearch(filters, page - 1, smartSearch)}
                    className="text-xs text-blue-600 disabled:opacity-40 hover:underline"
                  >
                    ← Prev
                  </button>
                  <span className="text-xs text-slate-500">{page} / {result.pages}</span>
                  <button
                    type="button"
                    disabled={page >= result.pages || loading}
                    onClick={() => runSearch(filters, page + 1, smartSearch)}
                    className="text-xs text-blue-600 disabled:opacity-40 hover:underline"
                  >
                    Next →
                  </button>
                </div>
              )}
            </div>
          )}
        </div>
      )}
    </div>
  )
}

export default AdvancedSearchPanel
