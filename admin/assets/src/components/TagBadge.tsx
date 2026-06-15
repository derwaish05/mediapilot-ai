/**
 * TagBadge.tsx
 *
 * A colored pill that displays a single tag name.
 *
 * Props:
 *   tag       — the MediaPilotTag to display
 *   onRemove  — optional callback; when provided renders an × button
 *   size      — 'sm' (compact overlay) | 'md' (default, form rows)
 */

import React from 'react'
import type { MediaPilotTag } from '@/types'

interface TagBadgeProps {
  tag: MediaPilotTag
  onRemove?: () => void
  size?: 'sm' | 'md'
}

export const TagBadge: React.FC<TagBadgeProps> = ({ tag, onRemove, size = 'md' }) => {
  // Derive tinted bg + border from the tag hex color
  const bg     = tag.color + '22' // ~13 % opacity
  const border = tag.color + '66' // ~40 % opacity
  const text   = tag.color

  const sizeClasses =
    size === 'sm'
      ? 'text-[10px] px-1.5 py-0.5 gap-0.5'
      : 'text-xs px-2 py-1 gap-1.5'

  return (
    <span
      className={`inline-flex items-center rounded-full font-medium leading-none shrink-0 ${sizeClasses}`}
      style={{ backgroundColor: bg, border: `1px solid ${border}`, color: text }}
    >
      {/* Solid color dot */}
      <span
        className={size === 'sm' ? 'w-1 h-1 rounded-full shrink-0' : 'w-1.5 h-1.5 rounded-full shrink-0'}
        style={{ backgroundColor: tag.color }}
        aria-hidden="true"
      />

      <span>{tag.name}</span>

      {onRemove && (
        <button
          type="button"
          className="shrink-0 rounded-full hover:bg-black/10 p-px cursor-pointer -mr-0.5"
          onClick={(e) => {
            e.stopPropagation()
            onRemove()
          }}
          aria-label={`Remove tag "${tag.name}"`}
        >
          <svg
            viewBox="0 0 10 10"
            fill="currentColor"
            className="w-2 h-2"
            aria-hidden="true"
          >
            <path d="M6.414 5l2.293-2.293a1 1 0 00-1.414-1.414L5 3.586 2.707 1.293A1 1 0 001.293 2.707L3.586 5 1.293 7.293a1 1 0 001.414 1.414L5 6.414l2.293 2.293a1 1 0 001.414-1.414L6.414 5z" />
          </svg>
        </button>
      )}
    </span>
  )
}

export default TagBadge
