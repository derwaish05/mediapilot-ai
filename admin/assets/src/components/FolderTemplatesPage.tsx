/**
 * FolderTemplatesPage.tsx
 *
 * Admin page: Media › Folder Templates (S38)
 *
 * Mounted by main.tsx into #mediapilot-templates-root.
 *
 * Sections:
 *   1. Built-in presets grid — 4 preset cards (Blog, E-commerce, Agency Client, Portfolio)
 *   2. Saved templates list  — user-created templates with Apply / Export / Delete
 *   3. Save from folder form — capture an existing folder subtree as a template
 *   4. Import from JSON      — paste or upload JSON
 */

import React, {
  useState,
  useEffect,
  useCallback,
  useRef,
  ChangeEvent,
} from 'react'
import { apiFetch, apiPost, apiDelete } from '@/api/client'
import { useFolderStore } from '@/store/folderStore'
import type { MediaPilotFolder } from '@/types'

// ---------------------------------------------------------------------------
// Types
// ---------------------------------------------------------------------------

interface FolderNode {
  name: string
  color: string
  children: FolderNode[]
}

interface Template {
  id: number
  name: string
  description: string
  structure: FolderNode[]
  created_at: string
  is_preset: boolean
}

interface ApplyResult {
  created: number
  folders: string[]
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

function StructurePreview({ nodes, depth = 0 }: { nodes: FolderNode[]; depth?: number }) {
  if (!nodes.length) return null
  return (
    <ul className={`space-y-0.5 ${depth > 0 ? 'ml-3 mt-0.5' : ''}`}>
      {nodes.map((n, i) => (
        <li key={i}>
          <span className="flex items-center gap-1 text-xs text-slate-600">
            <span className="inline-block w-2 h-2 rounded-sm shrink-0" style={{ background: n.color }} />
            {n.name}
          </span>
          {n.children.length > 0 && <StructurePreview nodes={n.children} depth={depth + 1} />}
        </li>
      ))}
    </ul>
  )
}

// ---------------------------------------------------------------------------
// Icons
// ---------------------------------------------------------------------------

const IconApply: React.FC = () => (
  <svg viewBox="0 0 20 20" fill="currentColor" className="w-4 h-4" aria-hidden="true">
    <path fillRule="evenodd" d="M10 18a8 8 0 100-16 8 8 0 000 16zm3.707-9.293a1 1 0 00-1.414-1.414L9 10.586 7.707 9.293a1 1 0 00-1.414 1.414l2 2a1 1 0 001.414 0l4-4z" clipRule="evenodd" />
  </svg>
)

const IconExport: React.FC = () => (
  <svg viewBox="0 0 20 20" fill="currentColor" className="w-4 h-4" aria-hidden="true">
    <path fillRule="evenodd" d="M3 17a1 1 0 011-1h12a1 1 0 110 2H4a1 1 0 01-1-1zm3.293-7.707a1 1 0 011.414 0L9 10.586V3a1 1 0 112 0v7.586l1.293-1.293a1 1 0 111.414 1.414l-3 3a1 1 0 01-1.414 0l-3-3a1 1 0 010-1.414z" clipRule="evenodd" />
  </svg>
)

const IconTrash: React.FC = () => (
  <svg viewBox="0 0 20 20" fill="currentColor" className="w-4 h-4" aria-hidden="true">
    <path fillRule="evenodd" d="M9 2a1 1 0 00-.894.553L7.382 4H4a1 1 0 000 2v10a2 2 0 002 2h8a2 2 0 002-2V6a1 1 0 100-2h-3.382l-.724-1.447A1 1 0 0011 2H9zM7 8a1 1 0 012 0v6a1 1 0 11-2 0V8zm5-1a1 1 0 00-1 1v6a1 1 0 102 0V8a1 1 0 00-1-1z" clipRule="evenodd" />
  </svg>
)

const IconUpload: React.FC = () => (
  <svg viewBox="0 0 20 20" fill="currentColor" className="w-4 h-4" aria-hidden="true">
    <path fillRule="evenodd" d="M3 17a1 1 0 011-1h12a1 1 0 110 2H4a1 1 0 01-1-1zM6.293 6.707a1 1 0 010-1.414l3-3a1 1 0 011.414 0l3 3a1 1 0 01-1.414 1.414L11 5.414V13a1 1 0 11-2 0V5.414L7.707 6.707a1 1 0 01-1.414 0z" clipRule="evenodd" />
  </svg>
)

const Spinner: React.FC<{ className?: string }> = ({ className = 'w-4 h-4' }) => (
  <span className={`${className} border-2 border-slate-300 border-t-blue-500 rounded-full animate-spin inline-block`} />
)

// ---------------------------------------------------------------------------
// Apply Modal
// ---------------------------------------------------------------------------

interface ApplyModalProps {
  template: Template
  folders: MediaPilotFolder[]
  onClose: () => void
  onApplied: (result: ApplyResult) => void
}

const ApplyModal: React.FC<ApplyModalProps> = ({ template, folders, onClose, onApplied }) => {
  const [targetId, setTargetId]   = useState<number>(0)
  const [applying, setApplying]   = useState(false)
  const [error, setError]         = useState<string | null>(null)
  const flatFolders                = flattenFolders(folders)

  const handleApply = useCallback(async () => {
    setApplying(true)
    setError(null)
    try {
      const res = await apiPost<ApplyResult>(
        `folder-templates/${template.id}/apply`,
        { target_folder_id: targetId }
      )
      onApplied(res)
    } catch (e: any) {
      setError(e?.message ?? 'Apply failed.')
    } finally {
      setApplying(false)
    }
  }, [template.id, targetId, onApplied])

  return (
    <div className="fixed inset-0 z-[10001] flex items-center justify-center bg-black/50 p-4">
      <div className="bg-white rounded-xl shadow-2xl w-full max-w-md p-6 space-y-4">
        <h3 className="text-base font-semibold text-slate-800">
          Apply "{template.name}"
        </h3>

        <div>
          <label className="block text-sm text-slate-600 mb-1">Create folders under:</label>
          <select
            value={targetId}
            onChange={(e) => setTargetId(Number(e.target.value))}
            className="w-full border border-slate-300 rounded px-3 py-1.5 text-sm text-slate-700 bg-white"
          >
            <option value={0}>— Root level —</option>
            {flatFolders.map((f) => (
              <option key={f.id} value={f.id}>{f.name}</option>
            ))}
          </select>
        </div>

        <div className="bg-slate-50 rounded p-3 max-h-48 overflow-y-auto">
          <p className="text-xs text-slate-500 mb-2 font-medium">Folders to be created:</p>
          <StructurePreview nodes={template.structure} />
        </div>

        {error && <p className="text-sm text-red-600">{error}</p>}

        <div className="flex justify-end gap-2 pt-1">
          <button
            type="button"
            onClick={onClose}
            disabled={applying}
            className="px-4 py-1.5 text-sm rounded-md border border-slate-300 text-slate-600 hover:bg-slate-50 cursor-pointer"
          >
            Cancel
          </button>
          <button
            type="button"
            onClick={handleApply}
            disabled={applying}
            className="flex items-center gap-1.5 px-4 py-1.5 text-sm rounded-md bg-blue-600 hover:bg-blue-700 text-white cursor-pointer disabled:opacity-50"
          >
            {applying ? <Spinner className="w-3.5 h-3.5" /> : <IconApply />}
            {applying ? 'Creating…' : 'Apply Template'}
          </button>
        </div>
      </div>
    </div>
  )
}

// ---------------------------------------------------------------------------
// Template card
// ---------------------------------------------------------------------------

interface TemplateCardProps {
  template: Template
  onApply: (t: Template) => void
  onDelete: (id: number) => void
  onExport: (id: number) => void
}

const TemplateCard: React.FC<TemplateCardProps> = ({ template, onApply, onDelete, onExport }) => (
  <div className="rounded-lg border border-slate-200 bg-white shadow-sm overflow-hidden flex flex-col">
    <div className="px-4 py-3 border-b border-slate-100 flex items-start justify-between gap-2">
      <div>
        <div className="flex items-center gap-2">
          <h3 className="text-sm font-semibold text-slate-800">{template.name}</h3>
          {template.is_preset && (
            <span className="text-[10px] font-medium px-1.5 py-0.5 rounded-full bg-blue-100 text-blue-700">
              Built-in
            </span>
          )}
        </div>
        {template.description && (
          <p className="text-xs text-slate-500 mt-0.5">{template.description}</p>
        )}
      </div>
    </div>

    <div className="px-4 py-3 flex-1 overflow-y-auto max-h-44">
      <StructurePreview nodes={template.structure} />
    </div>

    <div className="px-4 py-2.5 border-t border-slate-100 bg-slate-50 flex items-center gap-2">
      <button
        type="button"
        onClick={() => onApply(template)}
        className="flex items-center gap-1 px-3 py-1 text-xs rounded-md bg-blue-600 hover:bg-blue-700 text-white cursor-pointer transition-colors"
      >
        <IconApply />
        Apply
      </button>
      <button
        type="button"
        onClick={() => onExport(template.id)}
        className="flex items-center gap-1 px-3 py-1 text-xs rounded-md border border-slate-300 text-slate-600 hover:bg-slate-100 cursor-pointer transition-colors"
      >
        <IconExport />
        Export
      </button>
      {!template.is_preset && (
        <button
          type="button"
          onClick={() => onDelete(template.id)}
          className="flex items-center gap-1 px-3 py-1 text-xs rounded-md border border-red-200 text-red-600 hover:bg-red-50 cursor-pointer transition-colors ml-auto"
        >
          <IconTrash />
          Delete
        </button>
      )}
    </div>
  </div>
)

// ---------------------------------------------------------------------------
// Main page
// ---------------------------------------------------------------------------

const FolderTemplatesPage: React.FC = () => {
  const folderTree              = useFolderStore((s) => s.tree)
  const fetchTree               = useFolderStore((s) => s.fetchTree)

  const [templates, setTemplates]         = useState<Template[]>([])
  const [loading, setLoading]             = useState(true)
  const [applyTarget, setApplyTarget]     = useState<Template | null>(null)
  const [toast, setToast]                 = useState<{ msg: string; ok: boolean } | null>(null)
  const toastTimer                        = useRef<ReturnType<typeof setTimeout> | null>(null)

  // Save-from-folder form
  const [saveFolderId, setSaveFolderId]   = useState<number | ''>('')
  const [saveName, setSaveName]           = useState('')
  const [saveDesc, setSaveDesc]           = useState('')
  const [saving, setSaving]               = useState(false)

  // Import
  const [importJson, setImportJson]       = useState('')
  const [importing, setImporting]         = useState(false)
  const fileInputRef                      = useRef<HTMLInputElement>(null)

  const flatFolders = flattenFolders(folderTree)

  // ---- Boot ----------------------------------------------------------------

  useEffect(() => {
    fetchTree(null)
    void loadTemplates()
  }, []) // eslint-disable-line react-hooks/exhaustive-deps

  // ---- Helpers -------------------------------------------------------------

  const showToast = useCallback((msg: string, ok = true) => {
    if (toastTimer.current) clearTimeout(toastTimer.current)
    setToast({ msg, ok })
    toastTimer.current = setTimeout(() => setToast(null), 3500)
  }, [])

  async function loadTemplates() {
    setLoading(true)
    try {
      const res = await apiFetch<{ templates: Template[] }>('folder-templates')
      setTemplates(res.templates)
    } catch {
      showToast('Failed to load templates.', false)
    } finally {
      setLoading(false)
    }
  }

  // ---- Save from folder ----------------------------------------------------

  const handleSave = useCallback(async () => {
    if (!saveName.trim() || saveFolderId === '') return
    setSaving(true)
    try {
      await apiPost('folder-templates', {
        folder_id:   saveFolderId,
        name:        saveName.trim(),
        description: saveDesc.trim(),
      })
      showToast('Template saved.')
      setSaveFolderId('')
      setSaveName('')
      setSaveDesc('')
      await loadTemplates()
    } catch (e: any) {
      showToast(e?.message ?? 'Save failed.', false)
    } finally {
      setSaving(false)
    }
  }, [saveFolderId, saveName, saveDesc, showToast]) // eslint-disable-line react-hooks/exhaustive-deps

  // ---- Delete --------------------------------------------------------------

  const handleDelete = useCallback(async (id: number) => {
    if (!confirm('Delete this template? This cannot be undone.')) return
    try {
      await apiDelete(`folder-templates/${id}`)
      setTemplates((prev) => prev.filter((t) => t.id !== id))
      showToast('Template deleted.')
    } catch {
      showToast('Delete failed.', false)
    }
  }, [showToast])

  // ---- Export --------------------------------------------------------------

  const handleExport = useCallback(async (id: number) => {
    try {
      const res  = await apiFetch<{ json: string }>(`folder-templates/${id}/export`)
      const blob = new Blob([res.json], { type: 'application/json' })
      const url  = URL.createObjectURL(blob)
      const a    = document.createElement('a')
      a.href     = url
      a.download = `mediapilot-template-${id}.json`
      document.body.appendChild(a)
      a.click()
      document.body.removeChild(a)
      URL.revokeObjectURL(url)
    } catch {
      showToast('Export failed.', false)
    }
  }, [showToast])

  // ---- Apply ---------------------------------------------------------------

  const handleApplied = useCallback((result: ApplyResult) => {
    setApplyTarget(null)
    showToast(`${result.created} folder${result.created !== 1 ? 's' : ''} created.`)
    fetchTree(null)
  }, [showToast, fetchTree])

  // ---- Import from JSON ----------------------------------------------------

  const handleImport = useCallback(async () => {
    if (!importJson.trim()) return
    setImporting(true)
    try {
      await apiPost('folder-templates/import', { json: importJson.trim() })
      showToast('Template imported.')
      setImportJson('')
      await loadTemplates()
    } catch (e: any) {
      showToast(e?.message ?? 'Import failed.', false)
    } finally {
      setImporting(false)
    }
  }, [importJson, showToast]) // eslint-disable-line react-hooks/exhaustive-deps

  const handleFileRead = useCallback((e: ChangeEvent<HTMLInputElement>) => {
    const file = e.target.files?.[0]
    if (!file) return
    const reader = new FileReader()
    reader.onload = (ev) => {
      const text = ev.target?.result
      if (typeof text === 'string') setImportJson(text)
    }
    reader.readAsText(file)
    e.target.value = ''
  }, [])

  // ---- Derived -------------------------------------------------------------

  const presets = templates.filter((t) => t.is_preset)
  const custom  = templates.filter((t) => !t.is_preset)

  // ---- Render --------------------------------------------------------------

  return (
    <div className="mediapilot-templates-page max-w-5xl mx-auto py-8 px-4 font-sans space-y-10">
      {/* Toast */}
      {toast && (
        <div
          role="status"
          aria-live="polite"
          className={`fixed bottom-6 left-1/2 -translate-x-1/2 px-4 py-2 text-sm text-white rounded-lg shadow-lg z-[10001] ${
            toast.ok ? 'bg-slate-800' : 'bg-red-600'
          }`}
        >
          {toast.msg}
        </div>
      )}

      {/* Apply modal */}
      {applyTarget && (
        <ApplyModal
          template={applyTarget}
          folders={folderTree}
          onClose={() => setApplyTarget(null)}
          onApplied={handleApplied}
        />
      )}

      {/* ---- Header -------------------------------------------------------- */}
      <div>
        <h1 className="text-2xl font-bold text-slate-800">Folder Templates</h1>
        <p className="text-sm text-slate-500 mt-1">
          Apply a preset structure or save your own folder layout as a reusable template.
        </p>
      </div>

      {/* ---- Built-in presets --------------------------------------------- */}
      <section>
        <h2 className="text-base font-semibold text-slate-700 mb-3">Built-in Presets</h2>
        {loading ? (
          <div className="flex items-center gap-3 text-slate-400 text-sm"><Spinner /> Loading…</div>
        ) : (
          <div className="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-4">
            {presets.map((t) => (
              <TemplateCard
                key={t.id}
                template={t}
                onApply={setApplyTarget}
                onDelete={handleDelete}
                onExport={handleExport}
              />
            ))}
          </div>
        )}
      </section>

      {/* ---- Saved templates ---------------------------------------------- */}
      <section>
        <h2 className="text-base font-semibold text-slate-700 mb-3">
          My Templates
          {custom.length > 0 && (
            <span className="ml-2 text-xs font-normal text-slate-400">({custom.length})</span>
          )}
        </h2>

        {!loading && custom.length === 0 && (
          <div className="rounded-lg border border-dashed border-slate-300 bg-slate-50 p-8 text-center text-slate-400 text-sm">
            No saved templates yet. Capture a folder structure below.
          </div>
        )}

        {!loading && custom.length > 0 && (
          <div className="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 gap-4">
            {custom.map((t) => (
              <TemplateCard
                key={t.id}
                template={t}
                onApply={setApplyTarget}
                onDelete={handleDelete}
                onExport={handleExport}
              />
            ))}
          </div>
        )}
      </section>

      {/* ---- Save from folder --------------------------------------------- */}
      <section className="rounded-xl border border-slate-200 bg-white shadow-sm p-6">
        <h2 className="text-base font-semibold text-slate-700 mb-4">Save Folder as Template</h2>
        <div className="grid grid-cols-1 sm:grid-cols-3 gap-4 items-end">
          <div>
            <label className="block text-xs font-medium text-slate-600 mb-1">Source Folder</label>
            <select
              value={saveFolderId}
              onChange={(e) => setSaveFolderId(e.target.value === '' ? '' : Number(e.target.value))}
              className="w-full border border-slate-300 rounded px-3 py-1.5 text-sm text-slate-700 bg-white"
            >
              <option value="">— select folder —</option>
              {flatFolders.map((f) => (
                <option key={f.id} value={f.id}>{f.name}</option>
              ))}
            </select>
          </div>

          <div>
            <label className="block text-xs font-medium text-slate-600 mb-1">Template Name</label>
            <input
              type="text"
              value={saveName}
              onChange={(e) => setSaveName(e.target.value)}
              placeholder="e.g. Client Website"
              className="w-full border border-slate-300 rounded px-3 py-1.5 text-sm text-slate-700"
            />
          </div>

          <div>
            <label className="block text-xs font-medium text-slate-600 mb-1">Description (optional)</label>
            <input
              type="text"
              value={saveDesc}
              onChange={(e) => setSaveDesc(e.target.value)}
              placeholder="Short description…"
              className="w-full border border-slate-300 rounded px-3 py-1.5 text-sm text-slate-700"
            />
          </div>
        </div>

        <button
          type="button"
          onClick={handleSave}
          disabled={saving || !saveName.trim() || saveFolderId === ''}
          className="mt-4 flex items-center gap-1.5 px-4 py-2 text-sm font-medium rounded-md bg-blue-600 hover:bg-blue-700 text-white cursor-pointer transition-colors disabled:opacity-50 disabled:cursor-not-allowed"
        >
          {saving ? <Spinner className="w-4 h-4" /> : null}
          {saving ? 'Saving…' : 'Save as Template'}
        </button>
      </section>

      {/* ---- Import from JSON --------------------------------------------- */}
      <section className="rounded-xl border border-slate-200 bg-white shadow-sm p-6">
        <h2 className="text-base font-semibold text-slate-700 mb-4">Import Template from JSON</h2>
        <div className="space-y-3">
          <div className="flex items-center gap-3">
            <button
              type="button"
              onClick={() => fileInputRef.current?.click()}
              className="flex items-center gap-1.5 px-3 py-1.5 text-sm border border-slate-300 rounded-md text-slate-600 hover:bg-slate-50 cursor-pointer"
            >
              <IconUpload />
              Load JSON file
            </button>
            <span className="text-xs text-slate-400">or paste below</span>
            <input
              ref={fileInputRef}
              type="file"
              accept=".json,application/json"
              onChange={handleFileRead}
              className="sr-only"
            />
          </div>

          <textarea
            value={importJson}
            onChange={(e) => setImportJson(e.target.value)}
            rows={6}
            placeholder='{ "name": "My Template", "structure": [ ... ] }'
            className="w-full border border-slate-300 rounded px-3 py-2 text-xs font-mono text-slate-700 resize-y focus:outline-none focus:ring-1 focus:ring-blue-500"
          />

          <button
            type="button"
            onClick={handleImport}
            disabled={importing || !importJson.trim()}
            className="flex items-center gap-1.5 px-4 py-2 text-sm font-medium rounded-md bg-slate-700 hover:bg-slate-800 text-white cursor-pointer transition-colors disabled:opacity-50 disabled:cursor-not-allowed"
          >
            {importing ? <Spinner className="w-4 h-4" /> : <IconUpload />}
            {importing ? 'Importing…' : 'Import Template'}
          </button>
        </div>
      </section>
    </div>
  )
}

export default FolderTemplatesPage
