/**
 * PermissionsModal.tsx
 *
 * Modal dialog for managing per-folder permissions.
 *
 * Features:
 *   - Lists existing role / user permission rules in a table
 *   - Checkboxes to toggle read / write / delete per rule
 *   - Delete button to remove a rule
 *   - Add-rule form: pick entity type (role | user), enter role slug or user ID
 *   - Agency workflow preset buttons: Designer / Editor / Publisher applied to
 *     a selected role
 *   - Fully keyboard-accessible; closes on Escape or backdrop click
 *
 * Data flow:
 *   Open:  window event 'mediapilot:open-permissions' with { folderId: number }
 *   Close: component calls hideContextMenu() and clears local state
 *   API:   all CRUD calls go to /mediapilot/v1/folders/{id}/permissions via apiFetch
 */

import React, {
  useCallback,
  useEffect,
  useRef,
  useState,
} from 'react'
import { apiFetch, apiPost, apiPut, apiDelete } from '@/api/client'
import type {
  MediaPilotPermissionRule,
  MediaPilotPermissionEntity,
  MediaPilotPresetName,
} from '@/types'

// ---------------------------------------------------------------------------
// Icons
// ---------------------------------------------------------------------------

const IconX: React.FC = () => (
  <svg viewBox="0 0 20 20" fill="currentColor" className="w-4 h-4" aria-hidden="true">
    <path fillRule="evenodd" d="M4.293 4.293a1 1 0 011.414 0L10 8.586l4.293-4.293a1 1 0 111.414 1.414L11.414 10l4.293 4.293a1 1 0 01-1.414 1.414L10 11.414l-4.293 4.293a1 1 0 01-1.414-1.414L8.586 10 4.293 5.707a1 1 0 010-1.414z" clipRule="evenodd" />
  </svg>
)

const IconTrash: React.FC = () => (
  <svg viewBox="0 0 20 20" fill="currentColor" className="w-4 h-4" aria-hidden="true">
    <path fillRule="evenodd" d="M9 2a1 1 0 00-.894.553L7.382 4H4a1 1 0 000 2v10a2 2 0 002 2h8a2 2 0 002-2V6a1 1 0 100-2h-3.382l-.724-1.447A1 1 0 0011 2H9zM7 8a1 1 0 012 0v6a1 1 0 11-2 0V8zm5-1a1 1 0 00-1 1v6a1 1 0 102 0V8a1 1 0 00-1-1z" clipRule="evenodd" />
  </svg>
)

// ---------------------------------------------------------------------------
// Sub-components
// ---------------------------------------------------------------------------

interface PermCheckboxProps {
  checked: boolean
  onChange: ( v: boolean ) => void
  label: string
  disabled?: boolean
}

const PermCheckbox: React.FC<PermCheckboxProps> = ( { checked, onChange, label, disabled } ) => (
  <label className="flex flex-col items-center gap-0.5 cursor-pointer select-none">
    <input
      type="checkbox"
      checked={checked}
      disabled={disabled}
      onChange={( e ) => onChange( e.target.checked )}
      className="w-4 h-4 accent-blue-600"
    />
    <span className="text-[10px] text-slate-500 leading-none">{label}</span>
  </label>
)

// ---------------------------------------------------------------------------
// Main component
// ---------------------------------------------------------------------------

const PRESETS: { name: MediaPilotPresetName; label: string; desc: string }[] = [
  { name: 'designer',  label: 'Designer',  desc: 'Read + Write' },
  { name: 'editor',    label: 'Editor',    desc: 'Read + Write' },
  { name: 'publisher', label: 'Publisher', desc: 'Read + Write + Delete' },
  { name: 'viewer',    label: 'Viewer',    desc: 'Read only' },
]

const PermissionsModal: React.FC = () => {
  const [open, setOpen]           = useState( false )
  const [folderId, setFolderId]   = useState<number | null>( null )
  const [folderName, setFolderName] = useState( '' )
  const [rules, setRules]         = useState<MediaPilotPermissionRule[]>( [] )
  const [loading, setLoading]     = useState( false )
  const [error, setError]         = useState<string | null>( null )

  // Add-rule form state
  const [addEntity, setAddEntity]   = useState<MediaPilotPermissionEntity>( 'role' )
  const [addEntityId, setAddEntityId] = useState( '' )
  const [addRead, setAddRead]       = useState( true )
  const [addWrite, setAddWrite]     = useState( false )
  const [addDelete, setAddDelete]   = useState( false )
  const [addLoading, setAddLoading] = useState( false )

  // Preset applier state
  const [presetRole, setPresetRole] = useState( '' )

  const dialogRef = useRef<HTMLDivElement>( null )

  // ---- Listen for open event -----------------------------------------------

  useEffect( () => {
    const handler = ( e: Event ) => {
      const { folderId: id, folderName: name } = ( e as CustomEvent ).detail
      setFolderId( id )
      setFolderName( name ?? '' )
      setError( null )
      setOpen( true )
    }

    window.addEventListener( 'mediapilot:open-permissions', handler )
    return () => window.removeEventListener( 'mediapilot:open-permissions', handler )
  }, [] )

  // ---- Fetch rules when opened ---------------------------------------------

  useEffect( () => {
    if ( ! open || folderId == null ) return

    setLoading( true )
    apiFetch<MediaPilotPermissionRule[]>( `folders/${folderId}/permissions` )
      .then( ( rules ) => {
        setRules( rules ?? [] )
        setLoading( false )
      } )
      .catch( () => {
        setError( 'Failed to load permissions.' )
        setLoading( false )
      } )
  }, [open, folderId] )

  // ---- Keyboard close -------------------------------------------------------

  useEffect( () => {
    if ( ! open ) return
    const handler = ( e: KeyboardEvent ) => {
      if ( e.key === 'Escape' ) handleClose()
    }
    document.addEventListener( 'keydown', handler )
    return () => document.removeEventListener( 'keydown', handler )
  }, [open] )

  // ---- Handlers ------------------------------------------------------------

  const handleClose = useCallback( () => {
    setOpen( false )
    setRules( [] )
    setAddEntityId( '' )
    setPresetRole( '' )
    setError( null )
  }, [] )

  const handleBackdropClick = useCallback( ( e: React.MouseEvent ) => {
    if ( dialogRef.current && ! dialogRef.current.contains( e.target as Node ) ) {
      handleClose()
    }
  }, [handleClose] )

  const handleToggle = useCallback(
    async ( rule: MediaPilotPermissionRule, bit: 'can_read' | 'can_write' | 'can_delete', val: boolean ) => {
      if ( folderId == null ) return

      const updated = { ...rule, [bit]: val }

      setRules( ( prev ) =>
        prev.map( ( r ) =>
          r.entity === rule.entity && r.entity_id === rule.entity_id ? updated : r
        )
      )

      try {
        await apiPut( `folders/${folderId}/permissions`, {
          entity:     updated.entity,
          entity_id:  updated.entity_id,
          can_read:   updated.can_read,
          can_write:  updated.can_write,
          can_delete: updated.can_delete,
        } )
      } catch {
        setError( 'Failed to update permission.' )
      }
    },
    [folderId]
  )

  const handleDelete = useCallback(
    async ( rule: MediaPilotPermissionRule ) => {
      if ( folderId == null ) return

      setRules( ( prev ) =>
        prev.filter( ( r ) => !( r.entity === rule.entity && r.entity_id === rule.entity_id ) )
      )

      try {
        await apiDelete(
          `folders/${folderId}/permissions?entity=${encodeURIComponent( rule.entity )}&entity_id=${encodeURIComponent( rule.entity_id )}`
        )
      } catch {
        setError( 'Failed to delete permission.' )
      }
    },
    [folderId]
  )

  const handleAddRule = useCallback(
    async ( e: React.FormEvent ) => {
      e.preventDefault()
      if ( folderId == null || ! addEntityId.trim() ) return

      setAddLoading( true )
      setError( null )

      try {
        const saved = await apiPut<MediaPilotPermissionRule>( `folders/${folderId}/permissions`, {
          entity:     addEntity,
          entity_id:  addEntityId.trim(),
          can_read:   addRead,
          can_write:  addWrite,
          can_delete: addDelete,
        } )

        setRules( ( prev ) => {
          const exists = prev.findIndex(
            ( r ) => r.entity === addEntity && r.entity_id === addEntityId.trim()
          )
          if ( exists >= 0 ) {
            const next = [...prev]
            next[exists] = saved
            return next
          }
          return [...prev, saved]
        } )

        setAddEntityId( '' )
        setAddRead( true )
        setAddWrite( false )
        setAddDelete( false )
      } catch {
        setError( 'Failed to add rule. Check that the role / user ID is valid.' )
      } finally {
        setAddLoading( false )
      }
    },
    [folderId, addEntity, addEntityId, addRead, addWrite, addDelete]
  )

  const handleApplyPreset = useCallback(
    async ( presetName: MediaPilotPresetName ) => {
      if ( folderId == null || ! presetRole.trim() ) {
        setError( 'Enter a role slug before applying a preset.' )
        return
      }

      setError( null )

      try {
        const saved = await apiPost<MediaPilotPermissionRule>(
          `folders/${folderId}/permissions/preset`,
          { role: presetRole.trim(), preset: presetName }
        )

        setRules( ( prev ) => {
          const exists = prev.findIndex(
            ( r ) => r.entity === 'role' && r.entity_id === presetRole.trim()
          )
          if ( exists >= 0 ) {
            const next = [...prev]
            next[exists] = saved
            return next
          }
          return [...prev, saved]
        } )

        setPresetRole( '' )
      } catch {
        setError( 'Failed to apply preset.' )
      }
    },
    [folderId, presetRole]
  )

  // ---- Render ---------------------------------------------------------------

  if ( ! open ) return null

  return (
    <div
      className="fixed inset-0 bg-black/40 z-[9999] flex items-center justify-center p-4"
      onClick={handleBackdropClick}
      role="presentation"
    >
      <div
        ref={dialogRef}
        role="dialog"
        aria-modal="true"
        aria-labelledby="mediapilot-perm-title"
        className="bg-white rounded-xl shadow-2xl w-full max-w-2xl max-h-[90vh] flex flex-col overflow-hidden"
      >
        {/* Header */}
        <div className="flex items-center justify-between px-5 py-4 border-b border-slate-200">
          <div>
            <h2 id="mediapilot-perm-title" className="text-base font-semibold text-slate-800">
              Folder Permissions
            </h2>
            {folderName && (
              <p className="text-xs text-slate-500 mt-0.5">
                {folderName}
              </p>
            )}
          </div>
          <button
            type="button"
            onClick={handleClose}
            aria-label="Close permissions dialog"
            className="p-1.5 rounded hover:bg-slate-100 text-slate-500 cursor-pointer"
          >
            <IconX />
          </button>
        </div>

        {/* Body */}
        <div className="flex-1 overflow-y-auto px-5 py-4 space-y-6">
          {error && (
            <div className="text-sm text-red-600 bg-red-50 border border-red-200 rounded px-3 py-2">
              {error}
            </div>
          )}

          {/* Rules table */}
          <section>
            <h3 className="text-xs font-semibold text-slate-500 uppercase tracking-wide mb-2">
              Current Rules
            </h3>

            {loading ? (
              <p className="text-sm text-slate-400">Loading…</p>
            ) : rules.length === 0 ? (
              <p className="text-sm text-slate-400 italic">
                No explicit rules — all users follow WP capability defaults.
              </p>
            ) : (
              <table className="w-full text-sm border-collapse">
                <thead>
                  <tr className="border-b border-slate-200">
                    <th className="text-left pb-1.5 font-medium text-slate-600 w-1/2">
                      Role / User
                    </th>
                    <th className="text-center pb-1.5 font-medium text-slate-600 w-12">Read</th>
                    <th className="text-center pb-1.5 font-medium text-slate-600 w-12">Write</th>
                    <th className="text-center pb-1.5 font-medium text-slate-600 w-12">Delete</th>
                    <th className="w-8" />
                  </tr>
                </thead>
                <tbody>
                  {rules.map( ( rule ) => (
                    <tr
                      key={`${rule.entity}-${rule.entity_id}`}
                      className="border-b border-slate-100 last:border-0"
                    >
                      <td className="py-2 pr-2">
                        <span className="font-medium text-slate-700">
                          {rule.display_name ?? rule.entity_id}
                        </span>
                        <span
                          className={[
                            'ml-2 text-[10px] px-1.5 py-0.5 rounded-full font-medium',
                            rule.entity === 'role'
                              ? 'bg-blue-100 text-blue-700'
                              : 'bg-purple-100 text-purple-700',
                          ].join( ' ' )}
                        >
                          {rule.entity}
                        </span>
                      </td>
                      <td className="text-center py-2">
                        <PermCheckbox
                          checked={rule.can_read}
                          onChange={( v ) => handleToggle( rule, 'can_read', v )}
                          label="R"
                        />
                      </td>
                      <td className="text-center py-2">
                        <PermCheckbox
                          checked={rule.can_write}
                          onChange={( v ) => handleToggle( rule, 'can_write', v )}
                          label="W"
                        />
                      </td>
                      <td className="text-center py-2">
                        <PermCheckbox
                          checked={rule.can_delete}
                          onChange={( v ) => handleToggle( rule, 'can_delete', v )}
                          label="D"
                        />
                      </td>
                      <td className="text-center py-2">
                        <button
                          type="button"
                          onClick={() => handleDelete( rule )}
                          aria-label={`Remove rule for ${rule.display_name ?? rule.entity_id}`}
                          className="p-1 rounded hover:bg-red-50 text-slate-400 hover:text-red-500 cursor-pointer"
                        >
                          <IconTrash />
                        </button>
                      </td>
                    </tr>
                  ) )}
                </tbody>
              </table>
            )}
          </section>

          {/* Add rule form */}
          <section>
            <h3 className="text-xs font-semibold text-slate-500 uppercase tracking-wide mb-2">
              Add Rule
            </h3>
            <form onSubmit={handleAddRule} className="space-y-3">
              <div className="flex gap-2 items-end flex-wrap">
                <div>
                  <label className="block text-xs text-slate-500 mb-1">Type</label>
                  <select
                    value={addEntity}
                    onChange={( e ) => setAddEntity( e.target.value as MediaPilotPermissionEntity )}
                    className="border border-slate-300 rounded px-2 py-1.5 text-sm"
                  >
                    <option value="role">Role</option>
                    <option value="user">User (ID)</option>
                  </select>
                </div>
                <div className="flex-1 min-w-[160px]">
                  <label className="block text-xs text-slate-500 mb-1">
                    {addEntity === 'role' ? 'Role slug' : 'User ID'}
                  </label>
                  <input
                    type="text"
                    value={addEntityId}
                    onChange={( e ) => setAddEntityId( e.target.value )}
                    placeholder={addEntity === 'role' ? 'e.g. editor' : 'e.g. 42'}
                    className="w-full border border-slate-300 rounded px-2 py-1.5 text-sm"
                    required
                  />
                </div>
                <div className="flex gap-3 items-end pb-0.5">
                  <PermCheckbox checked={addRead}   onChange={setAddRead}   label="Read" />
                  <PermCheckbox checked={addWrite}  onChange={setAddWrite}  label="Write" />
                  <PermCheckbox checked={addDelete} onChange={setAddDelete} label="Delete" />
                </div>
                <button
                  type="submit"
                  disabled={addLoading || ! addEntityId.trim()}
                  className="px-4 py-1.5 bg-blue-600 hover:bg-blue-700 disabled:opacity-50 text-white text-sm font-medium rounded cursor-pointer"
                >
                  {addLoading ? 'Adding…' : 'Add'}
                </button>
              </div>
            </form>
          </section>

          {/* Agency presets */}
          <section>
            <h3 className="text-xs font-semibold text-slate-500 uppercase tracking-wide mb-2">
              Apply Agency Workflow Preset
            </h3>
            <p className="text-xs text-slate-400 mb-2">
              Enter a role slug, then click a preset to apply it instantly.
            </p>
            <div className="flex gap-2 flex-wrap items-center">
              <input
                type="text"
                value={presetRole}
                onChange={( e ) => setPresetRole( e.target.value )}
                placeholder="Role slug (e.g. author)"
                className="border border-slate-300 rounded px-2 py-1.5 text-sm flex-1 min-w-[160px]"
              />
              {PRESETS.map( ( p ) => (
                <button
                  key={p.name}
                  type="button"
                  onClick={() => handleApplyPreset( p.name )}
                  disabled={! presetRole.trim()}
                  title={p.desc}
                  className="px-3 py-1.5 text-xs font-medium rounded border border-slate-300 hover:bg-slate-100 disabled:opacity-40 cursor-pointer"
                >
                  {p.label}
                </button>
              ) )}
            </div>
          </section>
        </div>

        {/* Footer */}
        <div className="px-5 py-3 border-t border-slate-200 flex justify-end">
          <button
            type="button"
            onClick={handleClose}
            className="px-4 py-1.5 text-sm font-medium rounded bg-slate-100 hover:bg-slate-200 text-slate-700 cursor-pointer"
          >
            Close
          </button>
        </div>
      </div>
    </div>
  )
}

export default PermissionsModal
