/**
 * ShortcodeBuilderPage.tsx
 *
 * Admin page: Media › Shortcode Builder (S39)
 *
 * Mounted by main.tsx into #mediapilot-builder-root.
 *
 * 5-step wizard:
 *   Step 1 — Pick source folder from the folder tree
 *   Step 2 — Choose layout (grid / masonry / flex / carousel)
 *   Step 3 — Configure options (columns, gap, image size, lightbox, caption)
 *   Step 4 — Live preview via GET /gallery/preview (rendered server-side HTML)
 *   Step 5 — Copy shortcode to clipboard + Gutenberg block snippet
 */

import React, { useState, useCallback, useEffect, useRef } from 'react'
import { useFolderStore } from '@/store/folderStore'
import type { MediaPilotFolder } from '@/types'

// ---------------------------------------------------------------------------
// Types
// ---------------------------------------------------------------------------

type Layout = 'grid' | 'masonry' | 'flex' | 'carousel'
type ImageSize = 'thumbnail' | 'medium' | 'large' | 'full'

interface BuilderOptions {
  folderId: number
  folderName: string
  layout: Layout
  columns: number
  gap: number
  imageSize: ImageSize
  lightbox: boolean
  caption: boolean
}

interface PreviewData {
  html: string
  shortcode: string
}

// ---------------------------------------------------------------------------
// Helpers
// ---------------------------------------------------------------------------

function flattenFolders(folders: MediaPilotFolder[]): MediaPilotFolder[] {
  const out: MediaPilotFolder[] = []
  const walk = (nodes: MediaPilotFolder[]) => {
    for (const n of nodes) { out.push(n); if (n.children.length) walk(n.children) }
  }
  walk(folders)
  return out
}

function buildShortcode(opts: BuilderOptions): string {
  return (
    `[mdpai_gallery folder="${opts.folderId}" layout="${opts.layout}" ` +
    `columns="${opts.columns}" gap="${opts.gap}" ` +
    `lightbox="${opts.lightbox ? 'true' : 'false'}" ` +
    `caption="${opts.caption ? 'true' : 'false'}" ` +
    `image_size="${opts.imageSize}"]`
  )
}

function buildBlockSnippet(opts: BuilderOptions): string {
  return JSON.stringify(
    {
      name: 'mediapilot/gallery',
      attributes: {
        folderId:   opts.folderId,
        folderName: opts.folderName,
        layout:     opts.layout,
        columns:    opts.columns,
        gap:        opts.gap,
        lightbox:   opts.lightbox,
        caption:    opts.caption,
        imageSize:  opts.imageSize,
      },
    },
    null,
    2
  )
}

// ---------------------------------------------------------------------------
// Icons
// ---------------------------------------------------------------------------

const IconCheck: React.FC = () => (
  <svg viewBox="0 0 20 20" fill="currentColor" className="w-4 h-4" aria-hidden="true">
    <path fillRule="evenodd" d="M16.707 5.293a1 1 0 010 1.414l-8 8a1 1 0 01-1.414 0l-4-4a1 1 0 011.414-1.414L8 12.586l7.293-7.293a1 1 0 011.414 0z" clipRule="evenodd" />
  </svg>
)

const IconCopy: React.FC = () => (
  <svg viewBox="0 0 20 20" fill="currentColor" className="w-4 h-4" aria-hidden="true">
    <path d="M8 3a1 1 0 011-1h2a1 1 0 110 2H9a1 1 0 01-1-1z" />
    <path d="M6 3a2 2 0 00-2 2v11a2 2 0 002 2h8a2 2 0 002-2V5a2 2 0 00-2-2 3 3 0 01-3 3H9a3 3 0 01-3-3z" />
  </svg>
)

const Spinner: React.FC<{ className?: string }> = ({ className = 'w-5 h-5' }) => (
  <span className={`${className} border-2 border-slate-300 border-t-blue-500 rounded-full animate-spin inline-block`} />
)

// ---------------------------------------------------------------------------
// Step indicator
// ---------------------------------------------------------------------------

const STEPS = ['Folder', 'Layout', 'Options', 'Preview', 'Copy']

const StepBar: React.FC<{ current: number }> = ({ current }) => (
  <div className="flex items-center gap-0 mb-8">
    {STEPS.map((label, i) => {
      const done    = i < current
      const active  = i === current
      const last    = i === STEPS.length - 1

      return (
        <React.Fragment key={i}>
          <div className="flex flex-col items-center">
            <div
              className={[
                'w-8 h-8 rounded-full flex items-center justify-center text-sm font-semibold transition-colors',
                done   ? 'bg-blue-600 text-white' : '',
                active ? 'bg-blue-600 text-white ring-4 ring-blue-100' : '',
                !done && !active ? 'bg-slate-200 text-slate-500' : '',
              ].join(' ')}
            >
              {done ? <IconCheck /> : i + 1}
            </div>
            <span className={`mt-1 text-[10px] font-medium ${active ? 'text-blue-600' : 'text-slate-400'}`}>
              {label}
            </span>
          </div>
          {!last && (
            <div className={`h-0.5 flex-1 mx-1 mb-4 ${i < current ? 'bg-blue-600' : 'bg-slate-200'}`} />
          )}
        </React.Fragment>
      )
    })}
  </div>
)

// ---------------------------------------------------------------------------
// Nav buttons
// ---------------------------------------------------------------------------

const NavRow: React.FC<{
  step: number
  totalSteps: number
  canNext: boolean
  loading?: boolean
  onBack: () => void
  onNext: () => void
  nextLabel?: string
}> = ({ step, totalSteps, canNext, loading, onBack, onNext, nextLabel = 'Next →' }) => (
  <div className="flex items-center justify-between mt-8 pt-6 border-t border-slate-100">
    <button
      type="button"
      onClick={onBack}
      disabled={step === 0}
      className="px-4 py-2 text-sm rounded-md border border-slate-300 text-slate-600 hover:bg-slate-50 cursor-pointer disabled:opacity-40 disabled:cursor-not-allowed"
    >
      ← Back
    </button>
    <button
      type="button"
      onClick={onNext}
      disabled={!canNext || !!loading}
      className="flex items-center gap-2 px-5 py-2 text-sm font-medium rounded-md bg-blue-600 hover:bg-blue-700 text-white cursor-pointer disabled:opacity-40 disabled:cursor-not-allowed"
    >
      {loading && <Spinner className="w-4 h-4" />}
      {nextLabel}
    </button>
  </div>
)

// ---------------------------------------------------------------------------
// Layout card
// ---------------------------------------------------------------------------

const LAYOUT_META: Record<Layout, { label: string; desc: string; icon: string }> = {
  grid:     { label: 'Grid',     desc: 'Equal-size cells in a CSS grid',          icon: '▦' },
  masonry:  { label: 'Masonry',  desc: 'Pinterest-style column layout',           icon: '⬛' },
  flex:     { label: 'Flex',     desc: 'Flowing row of images with wrap',         icon: '⬜' },
  carousel: { label: 'Carousel', desc: 'Horizontal scroll-snap strip',            icon: '▷' },
}

const LayoutCard: React.FC<{
  layout: Layout
  selected: boolean
  onSelect: (l: Layout) => void
}> = ({ layout, selected, onSelect }) => {
  const { label, desc, icon } = LAYOUT_META[layout]
  return (
    <button
      type="button"
      onClick={() => onSelect(layout)}
      className={[
        'flex flex-col items-center gap-2 p-4 rounded-xl border-2 cursor-pointer transition-all text-center',
        selected
          ? 'border-blue-500 bg-blue-50 shadow-sm'
          : 'border-slate-200 hover:border-slate-400 bg-white',
      ].join(' ')}
    >
      <span className="text-3xl">{icon}</span>
      <span className="text-sm font-semibold text-slate-700">{label}</span>
      <span className="text-xs text-slate-400">{desc}</span>
    </button>
  )
}

// ---------------------------------------------------------------------------
// Main component
// ---------------------------------------------------------------------------

const DEFAULT_OPTIONS: BuilderOptions = {
  folderId:   0,
  folderName: '',
  layout:     'grid',
  columns:    3,
  gap:        16,
  imageSize:  'medium',
  lightbox:   true,
  caption:    false,
}

const ShortcodeBuilderPage: React.FC = () => {
  const folderTree = useFolderStore((s) => s.tree)
  const fetchTree  = useFolderStore((s) => s.fetchTree)
  const flatFolders = flattenFolders(folderTree)

  const [step, setStep]         = useState(0)
  const [opts, setOpts]         = useState<BuilderOptions>(DEFAULT_OPTIONS)
  const [preview, setPreview]   = useState<PreviewData | null>(null)
  const [previewLoading, setPreviewLoading] = useState(false)
  const [previewError, setPreviewError]     = useState<string | null>(null)
  const [copied, setCopied]     = useState<'shortcode' | 'block' | null>(null)
  const copiedTimer             = useRef<ReturnType<typeof setTimeout> | null>(null)
  const previewRef              = useRef<HTMLDivElement>(null)

  useEffect(() => { fetchTree(null) }, []) // eslint-disable-line react-hooks/exhaustive-deps

  // Initialize carousel after preview HTML is injected into the DOM.
  useEffect(() => {
    if (!preview || opts.layout !== 'carousel') return
    const container = previewRef.current
    if (!container) return
    const w = window as any
    if (w.MediaPilotCarousel?.initCarousel) {
      container.querySelectorAll<HTMLElement>('[data-mediapilot-carousel]:not([data-mediapilot-carousel-init])').forEach((el) => {
        w.MediaPilotCarousel.initCarousel(el)
      })
    }
  }, [preview, opts.layout])

  // ---- Copy helper ---------------------------------------------------------

  const fallbackCopy = (text: string, onSuccess: () => void) => {
    const ta = document.createElement('textarea')
    ta.value = text
    ta.style.cssText = 'position:fixed;top:-9999px;left:-9999px;opacity:0;'
    document.body.appendChild(ta)
    ta.focus()
    ta.select()
    try {
      document.execCommand('copy')
      onSuccess()
    } catch (_) {
      // silently ignore — nothing better we can do
    }
    document.body.removeChild(ta)
  }

  const copy = useCallback((text: string, which: 'shortcode' | 'block') => {
    const onSuccess = () => {
      setCopied(which)
      if (copiedTimer.current) clearTimeout(copiedTimer.current)
      copiedTimer.current = setTimeout(() => setCopied(null), 2000)
    }

    if (navigator.clipboard && navigator.clipboard.writeText) {
      navigator.clipboard.writeText(text).then(onSuccess).catch(() => fallbackCopy(text, onSuccess))
    } else {
      fallbackCopy(text, onSuccess)
    }
  }, [])

  // ---- Load preview --------------------------------------------------------

  const loadPreview = useCallback(async () => {
    setPreviewLoading(true)
    setPreviewError(null)
    try {
      const { restUrl, nonce } = window.MediaPilotConfig
      const base = restUrl.replace(/\/$/, '')
      const url  = new URL(`${base}/gallery/preview`)
      url.searchParams.set('folder_id',  String(opts.folderId))
      url.searchParams.set('layout',     opts.layout)
      url.searchParams.set('columns',    String(opts.columns))
      url.searchParams.set('gap',        String(opts.gap))
      url.searchParams.set('lightbox',   opts.lightbox ? '1' : '0')
      url.searchParams.set('caption',    opts.caption  ? '1' : '0')
      url.searchParams.set('image_size', opts.imageSize)

      const res  = await fetch(url.toString(), {
        headers: { 'X-WP-Nonce': nonce },
        credentials: 'same-origin',
      })
      const json = await res.json()
      if (!json.success) throw new Error(json.message ?? 'Preview failed')
      setPreview(json.data)
    } catch (e: any) {
      setPreviewError(e?.message ?? 'Preview failed.')
    } finally {
      setPreviewLoading(false)
    }
  }, [opts])

  // ---- Step navigation -----------------------------------------------------

  const goNext = useCallback(async () => {
    if (step === 3) {
      // Going from preview → copy: preview already loaded
      setStep(4)
      return
    }
    if (step === 2) {
      // Load preview before advancing
      await loadPreview()
      setStep(3)
      return
    }
    setStep((s) => s + 1)
  }, [step, loadPreview])

  const goBack = useCallback(() => setStep((s) => Math.max(0, s - 1)), [])

  // ---- Can advance? --------------------------------------------------------

  const canNext = step === 0
    ? opts.folderId > 0
    : step === 1
    ? true
    : step === 2
    ? true
    : step === 3
    ? !previewLoading && !!preview && !previewError
    : false

  // ---- Shortcode & block strings -------------------------------------------

  const shortcode    = buildShortcode(opts)
  const blockSnippet = buildBlockSnippet(opts)

  // ---- Render --------------------------------------------------------------

  return (
    <div className="mediapilot-builder-page max-w-3xl mx-auto py-8 px-4 font-sans">
      <div className="mb-6">
        <h1 className="text-2xl font-bold text-slate-800">Shortcode Builder</h1>
        <p className="text-sm text-slate-500 mt-1">
          Build a gallery shortcode in five steps with a live preview.
        </p>
      </div>

      <StepBar current={step} />

      <div className="bg-white rounded-xl border border-slate-200 shadow-sm p-6">

        {/* ---- Step 1: Folder -------------------------------------------- */}
        {step === 0 && (
          <div className="space-y-4">
            <h2 className="text-base font-semibold text-slate-700">Step 1 — Pick a Folder</h2>
            <p className="text-sm text-slate-500">Choose the folder whose images will appear in the gallery.</p>

            <div className="max-h-72 overflow-y-auto border border-slate-200 rounded-lg divide-y divide-slate-100">
              {flatFolders.length === 0 && (
                <p className="text-sm text-slate-400 p-4">No folders found.</p>
              )}
              {flatFolders.map((f) => (
                <label
                  key={f.id}
                  className={[
                    'flex items-center gap-3 px-4 py-2.5 cursor-pointer hover:bg-slate-50 transition-colors',
                    opts.folderId === f.id ? 'bg-blue-50' : '',
                  ].join(' ')}
                >
                  <input
                    type="radio"
                    name="folder"
                    value={f.id}
                    checked={opts.folderId === f.id}
                    onChange={() => setOpts((o) => ({ ...o, folderId: f.id, folderName: f.name }))}
                    className="accent-blue-600"
                  />
                  <span
                    className="w-2.5 h-2.5 rounded-sm shrink-0"
                    style={{ background: f.color }}
                  />
                  <span className="text-sm text-slate-700">{f.name}</span>
                  <span className="ml-auto text-xs text-slate-400">{f.count} files</span>
                </label>
              ))}
            </div>

            {opts.folderId > 0 && (
              <p className="text-xs text-blue-600 font-medium">
                Selected: {opts.folderName} (ID {opts.folderId})
              </p>
            )}
          </div>
        )}

        {/* ---- Step 2: Layout -------------------------------------------- */}
        {step === 1 && (
          <div className="space-y-4">
            <h2 className="text-base font-semibold text-slate-700">Step 2 — Choose a Layout</h2>
            <div className="grid grid-cols-2 sm:grid-cols-4 gap-3">
              {(['grid', 'masonry', 'flex', 'carousel'] as Layout[]).map((l) => (
                <LayoutCard
                  key={l}
                  layout={l}
                  selected={opts.layout === l}
                  onSelect={(l) => setOpts((o) => ({ ...o, layout: l }))}
                />
              ))}
            </div>
          </div>
        )}

        {/* ---- Step 3: Options ------------------------------------------- */}
        {step === 2 && (
          <div className="space-y-6">
            <h2 className="text-base font-semibold text-slate-700">Step 3 — Configure Options</h2>

            <div className="grid grid-cols-1 sm:grid-cols-2 gap-6">
              {/* Columns */}
              <div>
                <label className="block text-xs font-medium text-slate-600 mb-1">
                  Columns: <strong>{opts.columns}</strong>
                </label>
                <input
                  type="range"
                  min={1} max={8}
                  value={opts.columns}
                  onChange={(e) => setOpts((o) => ({ ...o, columns: Number(e.target.value) }))}
                  className="w-full accent-blue-600"
                />
                <div className="flex justify-between text-[10px] text-slate-400 mt-0.5">
                  <span>1</span><span>8</span>
                </div>
              </div>

              {/* Gap */}
              <div>
                <label className="block text-xs font-medium text-slate-600 mb-1">
                  Gap: <strong>{opts.gap}px</strong>
                </label>
                <input
                  type="range"
                  min={0} max={64} step={4}
                  value={opts.gap}
                  onChange={(e) => setOpts((o) => ({ ...o, gap: Number(e.target.value) }))}
                  className="w-full accent-blue-600"
                />
                <div className="flex justify-between text-[10px] text-slate-400 mt-0.5">
                  <span>0</span><span>64</span>
                </div>
              </div>

              {/* Image Size */}
              <div>
                <label className="block text-xs font-medium text-slate-600 mb-1">Image Size</label>
                <select
                  value={opts.imageSize}
                  onChange={(e) => setOpts((o) => ({ ...o, imageSize: e.target.value as ImageSize }))}
                  className="w-full border border-slate-300 rounded px-3 py-1.5 text-sm text-slate-700 bg-white"
                >
                  {(['thumbnail', 'medium', 'large', 'full'] as ImageSize[]).map((s) => (
                    <option key={s} value={s}>{s.charAt(0).toUpperCase() + s.slice(1)}</option>
                  ))}
                </select>
              </div>

              {/* Toggles */}
              <div className="space-y-3">
                <label className="flex items-center gap-3 cursor-pointer">
                  <input
                    type="checkbox"
                    checked={opts.lightbox}
                    onChange={(e) => setOpts((o) => ({ ...o, lightbox: e.target.checked }))}
                    className="w-4 h-4 rounded accent-blue-600"
                  />
                  <span className="text-sm text-slate-700">Enable Lightbox</span>
                </label>
                <label className="flex items-center gap-3 cursor-pointer">
                  <input
                    type="checkbox"
                    checked={opts.caption}
                    onChange={(e) => setOpts((o) => ({ ...o, caption: e.target.checked }))}
                    className="w-4 h-4 rounded accent-blue-600"
                  />
                  <span className="text-sm text-slate-700">Show Captions</span>
                </label>
              </div>
            </div>

            {/* Live shortcode preview */}
            <div className="mt-2 p-3 bg-slate-50 rounded-lg border border-slate-200">
              <p className="text-[10px] text-slate-400 mb-1 font-medium uppercase tracking-wide">Shortcode preview</p>
              <code className="text-xs text-slate-700 break-all">{buildShortcode(opts)}</code>
            </div>
          </div>
        )}

        {/* ---- Step 4: Preview ------------------------------------------- */}
        {step === 3 && (
          <div className="space-y-4">
            <div className="flex items-center justify-between">
              <h2 className="text-base font-semibold text-slate-700">Step 4 — Live Preview</h2>
              <button
                type="button"
                onClick={loadPreview}
                disabled={previewLoading}
                className="text-xs text-blue-600 hover:underline cursor-pointer disabled:opacity-50"
              >
                ↺ Refresh
              </button>
            </div>

            {previewLoading && (
              <div className="flex items-center justify-center h-40 gap-3 text-slate-400">
                <Spinner /> Loading preview…
              </div>
            )}

            {previewError && (
              <div className="rounded-lg bg-red-50 border border-red-200 p-4 text-sm text-red-600">
                {previewError}
              </div>
            )}

            {!previewLoading && preview && (
              <div
                ref={previewRef}
                className="rounded-lg border border-slate-200 overflow-auto p-4 bg-white max-h-[520px]"
                /* dangerouslySetInnerHTML is safe here: HTML is rendered by our own
                   PHP GalleryRenderer using wp_kses / esc_* functions server-side. */
                dangerouslySetInnerHTML={{ __html: preview.html }}
              />
            )}
          </div>
        )}

        {/* ---- Step 5: Copy --------------------------------------------- */}
        {step === 4 && (
          <div className="space-y-6">
            <h2 className="text-base font-semibold text-slate-700">Step 5 — Copy &amp; Use</h2>

            {/* Shortcode */}
            <div className="space-y-2">
              <p className="text-sm font-medium text-slate-700">Shortcode</p>
              <p className="text-xs text-slate-500">Paste into any post, page, or widget.</p>
              <div className="flex gap-2">
                <code className="flex-1 bg-slate-50 border border-slate-200 rounded-lg p-3 text-xs text-slate-700 break-all">
                  {shortcode}
                </code>
                <button
                  type="button"
                  onClick={() => copy(shortcode, 'shortcode')}
                  className={[
                    'shrink-0 flex items-center gap-1.5 px-3 py-2 text-xs rounded-lg border cursor-pointer transition-colors',
                    copied === 'shortcode'
                      ? 'bg-green-600 border-green-600 text-white'
                      : 'border-slate-300 text-slate-600 hover:bg-slate-50',
                  ].join(' ')}
                >
                  {copied === 'shortcode' ? <IconCheck /> : <IconCopy />}
                  {copied === 'shortcode' ? 'Copied!' : 'Copy'}
                </button>
              </div>
            </div>

            {/* Gutenberg block snippet */}
            <div className="space-y-2">
              <p className="text-sm font-medium text-slate-700">Gutenberg Block Attributes</p>
              <p className="text-xs text-slate-500">
                These are the exact attribute values used by the <code>mediapilot/gallery</code> block.
                Use them to pre-configure the block programmatically or via block patterns.
              </p>
              <div className="flex gap-2 items-start">
                <pre className="flex-1 bg-slate-50 border border-slate-200 rounded-lg p-3 text-xs text-slate-700 overflow-x-auto">
                  {blockSnippet}
                </pre>
                <button
                  type="button"
                  onClick={() => copy(blockSnippet, 'block')}
                  className={[
                    'shrink-0 flex items-center gap-1.5 px-3 py-2 text-xs rounded-lg border cursor-pointer transition-colors',
                    copied === 'block'
                      ? 'bg-green-600 border-green-600 text-white'
                      : 'border-slate-300 text-slate-600 hover:bg-slate-50',
                  ].join(' ')}
                >
                  {copied === 'block' ? <IconCheck /> : <IconCopy />}
                  {copied === 'block' ? 'Copied!' : 'Copy'}
                </button>
              </div>
            </div>

            {/* Start over */}
            <div className="pt-2 border-t border-slate-100">
              <button
                type="button"
                onClick={() => { setStep(0); setOpts(DEFAULT_OPTIONS); setPreview(null) }}
                className="text-sm text-blue-600 hover:underline cursor-pointer"
              >
                ← Build another shortcode
              </button>
            </div>
          </div>
        )}

        {/* ---- Navigation ------------------------------------------------ */}
        {step < 4 && (
          <NavRow
            step={step}
            totalSteps={STEPS.length}
            canNext={canNext}
            loading={previewLoading}
            onBack={goBack}
            onNext={goNext}
            nextLabel={step === 2 ? 'Generate Preview →' : step === 3 ? 'Continue →' : 'Next →'}
          />
        )}
      </div>
    </div>
  )
}

export default ShortcodeBuilderPage
