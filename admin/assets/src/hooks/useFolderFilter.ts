/**
 * useFolderFilter.ts
 *
 * Filters a folder tree by a search query, preserving ancestors of any
 * matching folder so the tree path to each result remains navigable.
 *
 * Rules:
 *   - Empty query → return the original `folders` array unchanged (no clone)
 *   - Non-empty query → return a NEW cloned subtree containing only matched
 *     folders and their ancestors (original objects are NOT mutated)
 *   - Matching is case-insensitive substring on `folder.name`
 *   - Result is memoized on [folders, query] to avoid redundant traversals
 */

import { useMemo } from 'react'
import type { MediaPilotFolder } from '@/types'

// ---------------------------------------------------------------------------
// Internal recursive filter
// ---------------------------------------------------------------------------

/**
 * Recursively filter a list of folders.
 *
 * A folder is included in the output if:
 *   a) Its name matches the query, OR
 *   b) At least one of its descendants matches
 *
 * Returns a new array of cloned folder objects (children arrays replaced with
 * the filtered result). The original tree is not mutated.
 */
function filterTree(folders: MediaPilotFolder[], query: string): MediaPilotFolder[] {
  const lower = query.toLowerCase()
  const result: MediaPilotFolder[] = []

  for (const folder of folders) {
    // Recurse into children first to determine if any descendant matches
    const filteredChildren = filterTree(folder.children, query)

    const selfMatches = folder.name.toLowerCase().includes(lower)

    if (selfMatches || filteredChildren.length > 0) {
      // Clone the folder node, replacing children with the filtered subtree
      result.push({
        ...folder,
        // When the folder itself matches we keep all its children visible so
        // the user can navigate into it; when only a descendant matches we
        // show only the filtered children to keep the list concise.
        children: selfMatches ? folder.children : filteredChildren,
      })
    }
  }

  return result
}

// ---------------------------------------------------------------------------
// Hook
// ---------------------------------------------------------------------------

/**
 * Returns a filtered view of the folder tree based on `query`.
 *
 * @param folders  Top-level folders from folderStore (or any subtree)
 * @param query    Current search string from the SearchBox
 * @returns        Filtered (and memoized) folder array
 */
export function useFolderFilter(folders: MediaPilotFolder[], query: string): MediaPilotFolder[] {
  return useMemo(() => {
    if (!query.trim()) {
      return folders
    }
    return filterTree(folders, query.trim())
  }, [folders, query])
}
