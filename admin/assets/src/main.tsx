/**
 * MediaPilot React entry point.
 *
 * Mounts the <App /> component into #mediapilot-root when:
 *   1. window.MediaPilotConfig is present (plugin is active on this page)
 *   2. A #mediapilot-root element exists in the DOM (injected by the PHP admin class)
 *
 * Using React 18 createRoot API for concurrent-mode rendering.
 */

import './index.css'

import React from 'react'
import { createRoot } from 'react-dom/client'
import App from './App'
import DuplicatesPage from './components/DuplicatesPage'
import FolderTemplatesPage from './components/FolderTemplatesPage'
import ShortcodeBuilderPage from './components/ShortcodeBuilderPage'

function mount() {
  // Guard — MediaPilotConfig must be localized by PHP before this script runs
  if (!window.MediaPilotConfig) {
    return
  }

  // Primary mount: media library sidebar + bulk action bar
  const rootEl = document.getElementById('mediapilot-root')
  if (rootEl) {
    createRoot(rootEl).render(
      <React.StrictMode>
        <App />
      </React.StrictMode>,
    )
  }

  // Duplicates page mount
  const dupRootEl = document.getElementById('mediapilot-duplicates-root')
  if (dupRootEl) {
    createRoot(dupRootEl).render(
      <React.StrictMode>
        <DuplicatesPage />
      </React.StrictMode>,
    )
  }

  // Folder templates page mount
  const tplRootEl = document.getElementById('mediapilot-templates-root')
  if (tplRootEl) {
    createRoot(tplRootEl).render(
      <React.StrictMode>
        <FolderTemplatesPage />
      </React.StrictMode>,
    )
  }

  // Shortcode builder page mount
  const builderRootEl = document.getElementById('mediapilot-builder-root')
  if (builderRootEl) {
    createRoot(builderRootEl).render(
      <React.StrictMode>
        <ShortcodeBuilderPage />
      </React.StrictMode>,
    )
  }
}

// Run after DOM is ready; wp_enqueue_script defers to footer by default
if (document.readyState === 'loading') {
  document.addEventListener('DOMContentLoaded', mount)
} else {
  mount()
}
