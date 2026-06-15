/**
 * store/index.ts
 *
 * Barrel export for all Zustand stores.
 * Import from here in components and utilities so import paths stay short.
 *
 * Usage in a React component:
 *   import { useFolderStore, useUiStore } from '@/store'
 *
 * Usage outside React (e.g. in an event handler or utility):
 *   import { folderStore } from '@/store'
 *   const tree = folderStore.getState().tree
 */

export { useFolderStore, folderStore } from './folderStore'
export { useUiStore, uiStore } from './uiStore'
export { useSelectionStore, selectionStore } from './selectionStore'
export { useUploadStore, uploadStore } from './uploadStore'
