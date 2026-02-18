export const apiBase = import.meta.env.VITE_API_BASE || '/api'

export function buildAuthHeaders(tokens = {}) {
  const headers = { Accept: 'application/json' }
  const siteToken = tokens.siteToken ?? (localStorage.getItem('site_token') || '')
  if (siteToken) headers['X-Site-Token'] = siteToken
  // Admin auth is handled via httpOnly cookie — no X-Api-Key header needed
  // Public API key (guest token) can still be sent explicitly for public routes
  const publicApiKey = tokens.publicApiKey ?? (localStorage.getItem('public_api_key') || '')
  if (publicApiKey) headers['X-Api-Key'] = publicApiKey
  return headers
}

export async function fetchJsonWithAuth(url, opts = {}, tokens = {}) {
  const mergedHeaders = { ...buildAuthHeaders(tokens), ...(opts.headers || {}) }
  const response = await fetch(url, { ...opts, headers: mergedHeaders, credentials: 'same-origin' })
  const text = await response.text()

  if (!response.ok) {
    const snippet = text ? ` ${text.slice(0, 120)}` : ''
    throw new Error(`HTTP ${response.status} ${response.statusText}${snippet}`)
  }

  const contentType = response.headers.get('content-type') || ''
  if (!contentType.includes('application/json')) {
    const snippet = text ? ` (${contentType}): ${text.slice(0, 120)}` : ` (${contentType})`
    throw new Error(`Unexpected format${snippet}`)
  }

  return text ? JSON.parse(text) : null
}
