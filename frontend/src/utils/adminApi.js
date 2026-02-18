export const apiBase = import.meta.env.VITE_API_BASE || '/api'

const defaultRoutePrefix = 'admin'

const getRefValue = (maybeRef) => (maybeRef && typeof maybeRef === 'object' && 'value' in maybeRef ? maybeRef.value : maybeRef)

/**
 * Check if the admin is authenticated (session marker present).
 * The actual API key is stored in an httpOnly cookie and not accessible to JS.
 */
export function isAdminAuthenticated() {
  return !!(localStorage.getItem('admin_auth_session') || sessionStorage.getItem('admin_auth_session'))
}

/**
 * Resolve a truthy auth indicator for UI state checks. Does NOT return the actual API key.
 * Authentication is handled via httpOnly cookie — no API key in JavaScript.
 */
export function resolveAdminApiKey(apiKeyRef) {
  return getRefValue(apiKeyRef) || localStorage.getItem('admin_auth_session') || sessionStorage.getItem('admin_auth_session') || ''
}

export function resolveRoutePrefix(routePrefixRef) {
  const current = getRefValue(routePrefixRef)
  const stored = localStorage.getItem('admin_route_prefix')
  const resolved = current || stored || defaultRoutePrefix
  if (routePrefixRef && typeof routePrefixRef === 'object' && 'value' in routePrefixRef) {
    routePrefixRef.value = resolved
  }
  return resolved
}

export function buildAdminHeaders({ apiKeyRef, includeJson = false, extraHeaders = {} } = {}) {
  const headers = { Accept: 'application/json', ...extraHeaders }
  // Auth is handled via httpOnly cookie — no X-Api-Key header needed
  if (includeJson && !headers['Content-Type']) headers['Content-Type'] = 'application/json'
  return headers
}

export async function adminFetch(relativeOrAbsolute, opts = {}, { apiKeyRef, routePrefixRef, absolute = false, triedFallback = false } = {}) {
  const prefix = resolveRoutePrefix(routePrefixRef)
  const url = absolute ? relativeOrAbsolute : `${apiBase}/${prefix}/${relativeOrAbsolute}`
  const usesJson = includeJsonFromOptions(opts)
  const headers = buildAdminHeaders({ apiKeyRef, includeJson: usesJson, extraHeaders: opts.headers || {} })
  const response = await fetch(url, { ...opts, headers, credentials: 'same-origin' })

  if (!absolute && response.status === 403 && !triedFallback && prefix === 'admin') {
    if (routePrefixRef && typeof routePrefixRef === 'object' && 'value' in routePrefixRef) {
      routePrefixRef.value = 'moderator'
    }
    localStorage.setItem('admin_route_prefix', 'moderator')
    return adminFetch(relativeOrAbsolute, opts, { apiKeyRef, routePrefixRef, absolute, triedFallback: true })
  }

  return response
}

function includeJsonFromOptions(opts) {
  if (opts.headers && opts.headers['Content-Type']) return opts.headers['Content-Type'].includes('application/json')
  if (!opts.body) return false
  return !(opts.body instanceof FormData)
}

export async function adminFetchJson(relativeOrAbsolute, opts = {}, config = {}) {
  const response = await adminFetch(relativeOrAbsolute, opts, config)
  const text = await response.text()
  if (!response.ok) throw new Error(text || response.statusText)
  const contentType = response.headers.get('content-type') || ''
  if (contentType.includes('application/json')) {
    return text ? JSON.parse(text) : null
  }
  return text
}
