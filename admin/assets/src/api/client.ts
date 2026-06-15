/**
 * Typed fetch wrapper for all REST calls to /wp-json/mediapilot/v1/
 *
 * Reads restUrl and nonce from window.MediaPilotConfig (injected via wp_localize_script).
 * All requests attach the WP REST nonce so WordPress accepts them as authenticated.
 *
 * Response envelope:
 *   Success: { success: true,  data: T }
 *   Error:   { success: false, code: string, message: string }
 *
 * The core `request()` function unwraps the envelope and returns `data` directly,
 * or throws an Error with the server's `message` field on failure.
 */

import type { MediaPilotApiResponse } from '@/types'

// ---------------------------------------------------------------------------
// Internal helpers
// ---------------------------------------------------------------------------

function getConfig() {
  return window.MediaPilotConfig
}

/**
 * Core request function. Generic over the expected `data` payload type T.
 *
 * Unwraps the MediaPilotApiResponse envelope so callers receive T directly.
 * Throws an Error on non-2xx HTTP responses or when success === false.
 */
async function request<T>(
  method: 'GET' | 'POST' | 'PUT' | 'DELETE',
  endpoint: string,
  body?: unknown,
): Promise<T> {
  const { restUrl, nonce } = getConfig()

  // Strip leading slash from endpoint to avoid double-slash
  const url = `${restUrl.replace(/\/$/, '')}/${endpoint.replace(/^\//, '')}`

  const headers: HeadersInit = {
    'Content-Type': 'application/json',
    'X-WP-Nonce': nonce,
  }

  const init: RequestInit = {
    method,
    headers,
    credentials: 'same-origin',
  }

  if (body !== undefined && method !== 'GET' && method !== 'DELETE') {
    init.body = JSON.stringify(body)
  }

  const response = await fetch(url, init)

  // Parse body first — needed for both error and success paths
  let json: MediaPilotApiResponse<T>
  try {
    json = (await response.json()) as MediaPilotApiResponse<T>
  } catch {
    throw new Error(`MediaPilot: unexpected non-JSON response (HTTP ${response.status})`)
  }

  if (!response.ok || !json.success) {
    throw new Error(json.message ?? response.statusText ?? 'API error')
  }

  // Unwrap the envelope — callers receive data directly
  return json.data as T
}

// ---------------------------------------------------------------------------
// Public convenience functions
// ---------------------------------------------------------------------------

/**
 * Perform a GET request.
 * @param endpoint  Path relative to restUrl, e.g. 'folders' or 'folders/42'
 */
export function apiFetch<T>(endpoint: string): Promise<T> {
  return request<T>('GET', endpoint)
}

/**
 * Perform a POST request with a JSON body.
 * @param endpoint  Path relative to restUrl
 * @param body      Request payload — will be JSON-serialised
 */
export function apiPost<T>(endpoint: string, body: unknown): Promise<T> {
  return request<T>('POST', endpoint, body)
}

/**
 * Perform a PUT request with a JSON body.
 * @param endpoint  Path relative to restUrl
 * @param body      Request payload — will be JSON-serialised
 */
export function apiPut<T>(endpoint: string, body: unknown): Promise<T> {
  return request<T>('PUT', endpoint, body)
}

/**
 * Perform a DELETE request.
 * @param endpoint  Path relative to restUrl, e.g. 'folders/42'
 */
export function apiDelete<T>(endpoint: string): Promise<T> {
  return request<T>('DELETE', endpoint)
}
