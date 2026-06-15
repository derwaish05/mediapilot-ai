/**
 * DuplicatesPage.tsx
 *
 * Admin page: Media › Duplicates (S37)
 *
 * Mounted by main.tsx into #mediapilot-duplicates-root.
 *
 * Features:
 *   - "Scan for Duplicates" button triggering POST /files/duplicates/scan
 *   - Progress bar polling GET /files/duplicates/status while running
 *   - Results grouped into cards: "exact" (MD5) and "similar" (perceptual)
 *   - Each group shows thumbnails side by side
 *   - Per-group resolve actions:
 *       • Radio to select primary (keep)
 *       • "Keep primary & delete others" button
 *       • "Merge folder assignments to primary" is automatic on resolve
 */

import React, { useState, useEffect, useCallback, useRef } from 'react'
import { apiPost, apiFetch } from '@/api/client'

// ---------------------------------------------------------------------------
// Types
// ---------------------------------------------------------------------------

interface DupFile {
  id: number
  filename: string
  title: string
  date: string
  file_size: number
  mime_type: string
  thumbnail_url: string
  url: string
}

interface DupGroup {
  type: 'exact' | 'similar'
  hash: string
  files: DupFile[]
}

interface ScanStatus {
  status: 'idle' | 'running' | 'done'
  progress: { processed: number; total: number }
  groups: DupGroup[]
}

interface ResolveResult {
  kept: number
  deleted: number[]
  errors: Record<number, string>
}

interface ResolveAllResult {
  groups_resolved: number
  deleted: number
  errors: Record<number, string>
}

// ---------------------------------------------------------------------------
// Helpers
// ---------------------------------------------------------------------------

function formatBytes(bytes: number): string {
  if (bytes === 0) return '0 B'
  const k     = 1024
  const sizes = ['B', 'KB', 'MB', 'GB']
  const i     = Math.floor(Math.log(bytes) / Math.log(k))
  return `${parseFloat((bytes / Math.pow(k, i)).toFixed(1))} ${sizes[i]}`
}

// ---------------------------------------------------------------------------
// Icons
// ---------------------------------------------------------------------------

const IconScan: React.FC = () => (
  <svg viewBox="0 0 20 20" fill="currentColor" className="w-4 h-4" aria-hidden="true">
    <path fillRule="evenodd" d="M8 4a4 4 0 100 8 4 4 0 000-8zM2 8a6 6 0 1110.89 3.476l4.817 4.817a1 1 0 01-1.414 1.414l-4.816-4.816A6 6 0 012 8z" clipRule="evenodd" />
  </svg>
)

const IconTrash: React.FC = () => (
  <svg viewBox="0 0 20 20" fill="currentColor" className="w-4 h-4" aria-hidden="true">
    <path fillRule="evenodd" d="M9 2a1 1 0 00-.894.553L7.382 4H4a1 1 0 000 2v10a2 2 0 002 2h8a2 2 0 002-2V6a1 1 0 100-2h-3.382l-.724-1.447A1 1 0 0011 2H9zM7 8a1 1 0 012 0v6a1 1 0 11-2 0V8zm5-1a1 1 0 00-1 1v6a1 1 0 102 0V8a1 1 0 00-1-1z" clipRule="evenodd" />
  </svg>
)

const IconStop: React.FC = () => (
  <svg viewBox="0 0 20 20" fill="currentColor" className="w-4 h-4" aria-hidden="true">
    <path fillRule="evenodd" d="M10 18a8 8 0 100-16 8 8 0 000 16zM7 7a1 1 0 011-1h4a1 1 0 011 1v6a1 1 0 01-1 1H8a1 1 0 01-1-1V7z" clipRule="evenodd" />
  </svg>
)

const Spinner: React.FC<{ className?: string }> = ({ className = 'w-4 h-4' }) => (
  <span
    className={`${className} border-2 border-slate-300 border-t-blue-500 rounded-full animate-spin inline-block`}
  />
)

// ---------------------------------------------------------------------------
// DupGroupCard
// ---------------------------------------------------------------------------

interface DupGroupCardProps {
  group: DupGroup
  index: number
  onResolved: (groupHash: string, kept: number, deleted: number[]) => void
}

const DupGroupCard: React.FC<DupGroupCardProps> = ({ group, index, onResolved }) => {
  const [primaryId, setPrimaryId]   = useState<number>(group.files[0]?.id ?? 0)
  const [resolving, setResolving]   = useState(false)
  const [resolved, setResolved]     = useState(false)
  const [errMsg, setErrMsg]         = useState<string | null>(null)

  const handleResolve = useCallback(async () => {
    const deleteIds = group.files.map((f) => f.id).filter((id) => id !== primaryId)
    if (deleteIds.length === 0) return

    setResolving(true)
    setErrMsg(null)

    try {
      const res = await apiPost<ResolveResult>('files/duplicates/resolve', {
        primary_id: primaryId,
        delete_ids: deleteIds,
      })

      if (Object.keys(res.errors).length > 0) {
        const msgs = Object.values(res.errors).join(', ')
        setErrMsg(`Partial success — ${res.deleted.length} deleted. Errors: ${msgs}`)
      } else {
        setResolved(true)
        onResolved(group.hash, res.kept, res.deleted)
      }
    } catch {
      setErrMsg('Resolve failed. Please try again.')
    } finally {
      setResolving(false)
    }
  }, [group, primaryId, onResolved])

  if (resolved) {
    return (
      <div className="rounded-lg border border-green-200 bg-green-50 px-4 py-3 text-sm text-green-700">
        ✓ Group {index + 1} resolved — duplicates deleted.
      </div>
    )
  }

  const badge =
    group.type === 'exact'
      ? { label: 'Exact duplicate', cls: 'bg-red-100 text-red-700' }
      : { label: 'Visually similar', cls: 'bg-amber-100 text-amber-700' }

  return (
    <div className="rounded-lg border border-slate-200 bg-white shadow-sm overflow-hidden">
      {/* Card header */}
      <div className="flex items-center gap-3 px-4 py-2.5 border-b border-slate-100 bg-slate-50">
        <span className="text-sm font-medium text-slate-600">Group {index + 1}</span>
        <span className={`text-xs font-medium px-2 py-0.5 rounded-full ${badge.cls}`}>
          {badge.label}
        </span>
        <span className="ml-auto text-xs text-slate-400">{group.files.length} files</span>
      </div>

      {/* File thumbnails */}
      <div className="flex gap-4 p-4 overflow-x-auto">
        {group.files.map((file) => {
          const isImage   = file.mime_type.startsWith('image/')
          const isPrimary = file.id === primaryId

          return (
            <label
              key={file.id}
              className={[
                'flex flex-col gap-2 cursor-pointer shrink-0 w-40 p-2 rounded-lg border-2 transition-colors',
                isPrimary
                  ? 'border-blue-500 bg-blue-50'
                  : 'border-slate-200 hover:border-slate-400',
              ].join(' ')}
            >
              {/* Radio (hidden — whole card is the label) */}
              <input
                type="radio"
                name={`primary-${group.hash}`}
                value={file.id}
                checked={isPrimary}
                onChange={() => setPrimaryId(file.id)}
                className="sr-only"
              />

              {/* Thumbnail */}
              <div className="w-full aspect-square rounded overflow-hidden bg-slate-100 flex items-center justify-center">
                {isImage ? (
                  <img
                    src={file.thumbnail_url}
                    alt={file.title}
                    className="w-full h-full object-cover"
                    loading="lazy"
                  />
                ) : (
                  <span className="text-xs font-mono text-slate-400 uppercase">
                    {file.mime_type.split('/')[1]?.slice(0, 4) ?? 'file'}
                  </span>
                )}
              </div>

              {/* Meta */}
              <div className="space-y-0.5">
                <p
                  className="text-xs font-medium text-slate-700 truncate"
                  title={file.filename}
                >
                  {file.filename}
                </p>
                <p className="text-[10px] text-slate-400">{formatBytes(file.file_size)}</p>
                <p className="text-[10px] text-slate-400">{file.date.slice(0, 10)}</p>
              </div>

              {/* Primary badge */}
              {isPrimary && (
                <span className="text-[10px] font-semibold text-blue-600 uppercase tracking-wide">
                  ★ Keep (primary)
                </span>
              )}
            </label>
          )
        })}
      </div>

      {/* Actions */}
      <div className="flex items-center gap-3 px-4 py-3 border-t border-slate-100 bg-slate-50">
        <p className="text-xs text-slate-500 flex-1">
          Select the file to keep, then click Resolve.
          Folder assignments from deleted files will be merged to the primary.
        </p>

        {errMsg && (
          <span className="text-xs text-red-600">{errMsg}</span>
        )}

        <button
          type="button"
          onClick={handleResolve}
          disabled={resolving}
          className="flex items-center gap-1.5 px-3 py-1.5 text-sm rounded-md bg-red-600 hover:bg-red-700 text-white cursor-pointer transition-colors disabled:opacity-50 disabled:cursor-not-allowed"
        >
          {resolving ? <Spinner className="w-3.5 h-3.5" /> : <IconTrash />}
          {resolving ? 'Resolving…' : `Delete ${group.files.length - 1} duplicate${group.files.length - 1 !== 1 ? 's' : ''}`}
        </button>
      </div>
    </div>
  )
}

// ---------------------------------------------------------------------------
// Main page component
// ---------------------------------------------------------------------------

const DuplicatesPage: React.FC = () => {
  const [scanStatus, setScanStatus]   = useState<ScanStatus>({
    status: 'idle',
    progress: { processed: 0, total: 0 },
    groups: [],
  })
  const [scanning, setScanning]       = useState(false)
  const [cancelling, setCancelling]   = useState(false)
  const [groups, setGroups]           = useState<DupGroup[]>([])
  const [filter, setFilter]           = useState<'all' | 'exact' | 'similar'>('all')
  const [resolvingAll, setResolvingAll] = useState(false)
  const [resolveAllMsg, setResolveAllMsg] = useState<string | null>(null)
  const pollRef                       = useRef<ReturnType<typeof setInterval> | null>(null)

  // ---- Load existing results on mount -------------------------------------

  useEffect(() => {
    void loadStatus()
  }, [])

  // ---- Polling while running -----------------------------------------------

  useEffect(() => {
    if (scanStatus.status === 'running') {
      pollRef.current = setInterval(async () => {
        const s = await fetchStatus()
        setScanStatus(s)
        if (s.status !== 'running') {
          clearInterval(pollRef.current!)
          setScanning(false)
          setGroups(s.groups)
        }
      }, 2000)
    }

    return () => {
      if (pollRef.current) clearInterval(pollRef.current)
    }
  }, [scanStatus.status])

  // ---- Helpers ------------------------------------------------------------

  async function fetchStatus(): Promise<ScanStatus> {
    const res = await apiFetch<ScanStatus>('files/duplicates/status')
    return res
  }

  async function loadStatus() {
    try {
      const s = await fetchStatus()
      setScanStatus(s)
      setGroups(s.groups)
      if (s.status === 'running') setScanning(true)
    } catch {
      // Ignore on initial load.
    }
  }

  const handleScan = useCallback(async () => {
    setScanning(true)
    setGroups([])

    try {
      await apiPost('files/duplicates/scan', {})
      setScanStatus((prev) => ({ ...prev, status: 'running' }))
    } catch (err: any) {
      setScanning(false)
      // 409 = already running — keep polling
      if (err?.message?.includes('already in progress')) {
        setScanStatus((prev) => ({ ...prev, status: 'running' }))
      }
    }
  }, [])

  const handleCancel = useCallback(async () => {
    setCancelling(true)
    try {
      await apiPost('files/duplicates/cancel', {})
      if (pollRef.current) clearInterval(pollRef.current)
      setScanning(false)
      setScanStatus({ status: 'idle', progress: { processed: 0, total: 0 }, groups: [] })
      setGroups([])
    } catch {
      // If cancel fails, re-sync from the server so the UI reflects reality.
      const s = await fetchStatus().catch(() => null)
      if (s) {
        setScanStatus(s)
        setScanning(s.status === 'running')
      }
    } finally {
      setCancelling(false)
    }
  }, [])

  const handleResolved = useCallback((hash: string, _kept: number, deleted: number[]) => {
    setGroups((prev) => prev.filter((g) => g.hash !== hash))
  }, [])

  const totalDuplicateFiles = groups.reduce(
    (sum, g) => sum + Math.max(0, g.files.length - 1),
    0
  )

  const handleResolveAll = useCallback(async () => {
    if (totalDuplicateFiles === 0) return

    const ok = window.confirm(
      `Permanently delete ${totalDuplicateFiles} duplicate file${totalDuplicateFiles !== 1 ? 's' : ''} across ${groups.length} group${groups.length !== 1 ? 's' : ''}?\n\nThe first file in each group will be kept as primary. This cannot be undone.`
    )
    if (!ok) return

    setResolvingAll(true)
    setResolveAllMsg(null)

    try {
      const res = await apiPost<ResolveAllResult>('files/duplicates/resolve-all', {})
      const errCount = Object.keys(res.errors).length

      // Refresh state from server (any unresolved groups remain).
      const s = await fetchStatus()
      setScanStatus(s)
      setGroups(s.groups)

      if (errCount > 0) {
        const sample = Object.values(res.errors).slice(0, 3).join(', ')
        setResolveAllMsg(
          `Deleted ${res.deleted} of ${res.deleted + errCount} files. ${errCount} failed: ${sample}${errCount > 3 ? '…' : ''}`
        )
      } else {
        setResolveAllMsg(`Deleted ${res.deleted} duplicate files across ${res.groups_resolved} groups.`)
      }
    } catch {
      setResolveAllMsg('Bulk resolve failed. Please try again.')
    } finally {
      setResolvingAll(false)
    }
  }, [groups.length, totalDuplicateFiles])

  // ---- Derived values ------------------------------------------------------

  const filteredGroups = groups.filter(
    (g) => filter === 'all' || g.type === filter
  )

  const exactCount   = groups.filter((g) => g.type === 'exact').length
  const similarCount = groups.filter((g) => g.type === 'similar').length

  const progress = scanStatus.progress
  const progressPct =
    progress.total > 0
      ? Math.round((progress.processed / progress.total) * 100)
      : 0

  // ---- Render --------------------------------------------------------------

  return (
    <div className="mediapilot-duplicates-page max-w-5xl mx-auto py-8 px-4 font-sans">
      {/* ---- Header -------------------------------------------------------- */}
      <div className="flex items-center justify-between gap-4 mb-6">
        <div>
          <h1 className="text-2xl font-bold text-slate-800">Duplicate File Detection</h1>
          <p className="text-sm text-slate-500 mt-1">
            Scan your media library for exact and visually-similar duplicate files.
          </p>
        </div>

        <div className="flex items-center gap-2">
          {scanning && (
            <button
              type="button"
              onClick={handleCancel}
              disabled={cancelling}
              className="flex items-center gap-2 px-4 py-2 text-sm font-medium rounded-md border border-slate-300 bg-white text-slate-700 hover:bg-slate-50 cursor-pointer transition-colors disabled:opacity-60 disabled:cursor-not-allowed"
            >
              {cancelling ? <Spinner className="w-4 h-4" /> : <IconStop />}
              {cancelling ? 'Cancelling…' : 'Cancel scan'}
            </button>
          )}

          <button
            type="button"
            onClick={handleScan}
            disabled={scanning}
            className="flex items-center gap-2 px-4 py-2 text-sm font-medium rounded-md bg-blue-600 hover:bg-blue-700 text-white cursor-pointer transition-colors disabled:opacity-60 disabled:cursor-not-allowed"
          >
            {scanning ? <Spinner className="w-4 h-4" /> : <IconScan />}
            {scanning ? 'Scanning…' : 'Scan for Duplicates'}
          </button>
        </div>
      </div>

      {/* ---- Progress bar -------------------------------------------------- */}
      {scanning && (
        <div className="mb-6">
          <div className="flex items-center justify-between text-xs text-slate-500 mb-1">
            <span>Scanning attachments…</span>
            <span>{progress.processed} / {progress.total || '?'}</span>
          </div>
          <div className="h-2 bg-slate-200 rounded-full overflow-hidden">
            <div
              className="h-full bg-blue-500 rounded-full transition-all duration-300"
              style={{ width: progress.total > 0 ? `${progressPct}%` : '10%' }}
            />
          </div>
        </div>
      )}

      {/* ---- Summary stats ------------------------------------------------- */}
      {!scanning && groups.length > 0 && (
        <div className="flex items-center gap-4 mb-6 flex-wrap">
          <div className="flex gap-2">
            {(['all', 'exact', 'similar'] as const).map((f) => {
              const label =
                f === 'all'
                  ? `All (${groups.length})`
                  : f === 'exact'
                  ? `Exact (${exactCount})`
                  : `Similar (${similarCount})`

              return (
                <button
                  key={f}
                  type="button"
                  onClick={() => setFilter(f)}
                  className={[
                    'px-3 py-1 text-sm rounded-full border cursor-pointer transition-colors',
                    filter === f
                      ? 'bg-blue-600 border-blue-600 text-white'
                      : 'border-slate-300 text-slate-600 hover:border-slate-400',
                  ].join(' ')}
                >
                  {label}
                </button>
              )
            })}
          </div>

          <button
            type="button"
            onClick={handleResolveAll}
            disabled={resolvingAll || totalDuplicateFiles === 0}
            className="ml-auto flex items-center gap-1.5 px-3 py-1.5 text-sm font-medium rounded-md bg-red-600 hover:bg-red-700 text-white cursor-pointer transition-colors disabled:opacity-50 disabled:cursor-not-allowed"
          >
            {resolvingAll ? <Spinner className="w-3.5 h-3.5" /> : <IconTrash />}
            {resolvingAll
              ? 'Deleting all duplicates…'
              : `Delete all ${totalDuplicateFiles} duplicate${totalDuplicateFiles !== 1 ? 's' : ''} (keep primary)`}
          </button>
        </div>
      )}

      {resolveAllMsg && (
        <div className="mb-4 rounded-md border border-slate-200 bg-slate-50 px-4 py-2 text-sm text-slate-700">
          {resolveAllMsg}
        </div>
      )}

      {/* ---- Results ------------------------------------------------------- */}
      {!scanning && filteredGroups.length === 0 && scanStatus.status !== 'idle' && (
        <div className="rounded-lg border border-slate-200 bg-slate-50 p-12 text-center text-slate-400 text-sm">
          {scanStatus.status === 'done'
            ? 'No duplicates found. Your media library is clean!'
            : 'Run a scan to detect duplicate files.'}
        </div>
      )}

      {!scanning && scanStatus.status === 'idle' && groups.length === 0 && (
        <div className="rounded-lg border border-dashed border-slate-300 bg-slate-50 p-12 text-center text-slate-400 text-sm">
          Click "Scan for Duplicates" to get started.
        </div>
      )}

      <div className="space-y-4">
        {filteredGroups.map((group, i) => (
          <DupGroupCard
            key={group.hash}
            group={group}
            index={i}
            onResolved={handleResolved}
          />
        ))}
      </div>
    </div>
  )
}

export default DuplicatesPage
