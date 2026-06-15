/**
 * Root application component.
 *
 * Boots on `plugins_loaded` / `rest_api_init` inside #mediapilot-root.
 *
 * Responsibilities:
 *  1. Fetch the initial folder tree on mount.
 *  2. Listen to the `mediapilot:selection-change` custom event (fired by the
 *     MutationObserver bridge injected via MediaLibraryIntegration) and sync
 *     the native WP media-library selection into selectionStore.
 *  3. Render the FolderSidebar and the BulkActionBar.
 *  4. Mount BreadcrumbBar and MediaToolbar into PHP-injected portal roots
 *     (#mediapilot-breadcrumb-root and #mediapilot-toolbar-root) so they appear in the
 *     correct DOM positions relative to the WP media grid.
 */

import { useEffect, useState } from 'react'
import { createPortal } from 'react-dom'
import { FolderSidebar } from '@/components/FolderSidebar'
import BulkActionBar from '@/components/BulkActionBar'
import BreadcrumbBar from '@/components/BreadcrumbBar'
import MediaToolbar from '@/components/MediaToolbar'
import { useFolderStore } from '@/store/folderStore'
import { useSelectionStore } from '@/store/selectionStore'
import { useUiStore } from '@/store/uiStore'
import PermissionsModal from '@/components/PermissionsModal'
import SmartFolderModal from '@/components/SmartFolderModal'
import AdvancedSearchPanel from '@/components/AdvancedSearchPanel'

// ---------------------------------------------------------------------------
// Custom-event type (dispatched by the PHP-injected bridge script)
// ---------------------------------------------------------------------------

interface MediaPilotSelectionChangeEvent extends CustomEvent {
  detail: { ids: number[] }
}

// ---------------------------------------------------------------------------
// Hook: resolves a portal element once it exists in the DOM.
// The bridge script creates #mediapilot-breadcrumb-root and #mediapilot-toolbar-root
// asynchronously after WP backbone renders, so we watch for them.
// ---------------------------------------------------------------------------

function usePortalElement(id: string): Element | null {
  const [el, setEl] = useState<Element | null>(() => document.getElementById(id))

  useEffect(() => {
    // Already resolved — nothing to do.
    if (el) return

    const existing = document.getElementById(id)
    if (existing) {
      setEl(existing)
      return
    }

    // Watch for the element to be inserted by the bridge script.
    const observer = new MutationObserver(() => {
      const found = document.getElementById(id)
      if (found) {
        setEl(found)
        observer.disconnect()
      }
    })

    observer.observe(document.body, { childList: true, subtree: true })

    return () => observer.disconnect()
  }, [id, el])

  return el
}

// ---------------------------------------------------------------------------
// App
// ---------------------------------------------------------------------------

export default function App() {
  const fetchTree           = useFolderStore((s) => s.fetchTree)
  const selectAll           = useSelectionStore((s) => s.selectAll)
  const clearSelection      = useSelectionStore((s) => s.clearSelection)
  const adminFolderOverride = useUiStore((s) => s.adminFolderOverride)

  // Portal target elements (created by the PHP bridge + bridge JS)
  const breadcrumbRoot = usePortalElement('mediapilot-breadcrumb-root')
  const toolbarRoot    = usePortalElement('mediapilot-toolbar-root')

  // ---- Boot: load folder tree (re-fetch when admin override changes) -----

  useEffect(() => {
    fetchTree(adminFolderOverride)
  }, [fetchTree, adminFolderOverride])

  // ---- Bridge: sync WP native media selection → selectionStore -----------

  useEffect(() => {
    const handleSelectionChange = (e: Event) => {
      const { ids } = (e as MediaPilotSelectionChangeEvent).detail

      if (ids.length === 0) {
        clearSelection()
      } else {
        selectAll(ids)
      }
    }

    window.addEventListener('mediapilot:selection-change', handleSelectionChange)

    return () => {
      window.removeEventListener('mediapilot:selection-change', handleSelectionChange)
    }
  }, [selectAll, clearSelection])

  // Portal target for the sidebar (positioned into WP's media frame by bridge JS)
  const sidebarPortal = usePortalElement('mediapilot-sidebar-portal')

  // ---- Render --------------------------------------------------------------

  return (
    <>
      {/* Folder sidebar — portaled into #mediapilot-sidebar-portal which the bridge JS
          repositions as a flex-row sibling inside .media-frame-content */}
      {sidebarPortal && createPortal(<FolderSidebar />, sidebarPortal)}

      {/* Floating bulk action bar — fixed position, portaled into body so
          the display:none on #mediapilot-root does not hide it */}
      {createPortal(<BulkActionBar />, document.body)}

      {/* Breadcrumb bar — above WP media grid */}
      {breadcrumbRoot && createPortal(<BreadcrumbBar />, breadcrumbRoot)}

      {/* Sort + search toolbar + advanced search panel — between WP filter bar and the grid */}
      {toolbarRoot && createPortal(
        <>
          <MediaToolbar />
          <AdvancedSearchPanel />
        </>,
        toolbarRoot,
      )}

      {/* Permissions modal — portaled into body; opened via mediapilot:open-permissions event */}
      {createPortal(<PermissionsModal />, document.body)}

      {/* Smart Folder modal — portaled into body; opened via mediapilot:open-smart-folder event */}
      <SmartFolderModal />
    </>
  )
}
