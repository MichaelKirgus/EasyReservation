export const apiBase = import.meta.env.VITE_API_BASE || '/api'

const defaultRoutePrefix = 'admin'

const getRefValue = (maybeRef) => (maybeRef && typeof maybeRef === 'object' && 'value' in maybeRef ? maybeRef.value : maybeRef)

export function resolveAdminApiKey(apiKeyRef) {
  return getRefValue(apiKeyRef) || localStorage.getItem('admin_api_key') || sessionStorage.getItem('admin_api_key') || ''
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
  const apiKey = resolveAdminApiKey(apiKeyRef)
  if (apiKey && !headers['X-Api-Key']) headers['X-Api-Key'] = apiKey
  if (includeJson && !headers['Content-Type']) headers['Content-Type'] = 'application/json'
  return headers
}

export async function adminFetch(relativeOrAbsolute, opts = {}, { apiKeyRef, routePrefixRef, absolute = false, triedFallback = false } = {}) {
  const prefix = resolveRoutePrefix(routePrefixRef)
  const url = absolute ? relativeOrAbsolute : `${apiBase}/${prefix}/${relativeOrAbsolute}`
  const usesJson = includeJsonFromOptions(opts)
  const headers = buildAdminHeaders({ apiKeyRef, includeJson: usesJson, extraHeaders: opts.headers || {} })
  const response = await fetch(url, { ...opts, headers })

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
