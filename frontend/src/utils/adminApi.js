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
  // Always use 'admin' for AdminMailTransports.vue - moderators don't have access to mail transport routes
  const stored = localStorage.getItem('admin_route_prefix')
  const resolved = current || (stored === 'moderator' ? defaultRoutePrefix : stored) || defaultRoutePrefix
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

export async function adminFetch(relativeOrAbsolute, opts = {}, { apiKeyRef, routePrefixRef, absolute = false } = {}) {
  const prefix = resolveRoutePrefix(routePrefixRef)
  const url = absolute ? relativeOrAbsolute : `${apiBase}/${prefix}/${relativeOrAbsolute}`
  const usesJson = includeJsonFromOptions(opts)
  const headers = buildAdminHeaders({ apiKeyRef, includeJson: usesJson, extraHeaders: opts.headers || {} })
  // Use include so httpOnly session cookies are sent even across ports/subdomains during development
  const response = await fetch(url, { ...opts, headers, credentials: 'include' })

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

// ============================================================================
// Mail Transport API Functions
// ============================================================================

/**
 * Get all mail transport accounts
 */
export async function getMailAccounts(config = {}) {
  return adminFetchJson('mail-accounts', { method: 'GET' }, config)
}

/**
 * Create a new mail transport account
 */
export async function createMailAccount(data, config = {}) {
  return adminFetchJson('mail-accounts', {
    method: 'POST',
    body: JSON.stringify(data),
  }, config)
}

/**
 * Update an existing mail transport account
 */
export async function updateMailAccount(id, data, config = {}) {
  return adminFetchJson(`mail-accounts/${id}`, {
    method: 'PUT',
    body: JSON.stringify(data),
  }, config)
}

/**
 * Delete a mail transport account
 */
export async function deleteMailAccount(id, config = {}) {
  return adminFetchJson(`mail-accounts/${id}`, { method: 'DELETE' }, config)
}

/**
 * Test a mail transport account connection
 */
export async function testMailAccount(id, config = {}) {
  return adminFetchJson(`mail-accounts/${id}/test`, { method: 'POST' }, config)
}

/**
 * Get all mail transport groups
 */
export async function getMailGroups(config = {}) {
  return adminFetchJson('mail-groups', { method: 'GET' }, config)
}

/**
 * Create a new mail transport group
 */
export async function createMailGroup(data, config = {}) {
  return adminFetchJson('mail-groups', {
    method: 'POST',
    body: JSON.stringify(data),
  }, config)
}

/**
 * Update an existing mail transport group
 */
export async function updateMailGroup(id, data, config = {}) {
  return adminFetchJson(`mail-groups/${id}`, {
    method: 'PUT',
    body: JSON.stringify(data),
  }, config)
}

/**
 * Delete a mail transport group
 */
export async function deleteMailGroup(id, config = {}) {
  return adminFetchJson(`mail-groups/${id}`, { method: 'DELETE' }, config)
}

/**
 * Test a mail transport group (tries all accounts in the group)
 */
export async function testMailGroup(id, config = {}) {
  return adminFetchJson(`mail-groups/${id}/test`, { method: 'POST' }, config)
}

/**
 * Get available mail transport groups for template assignment
 */
export async function getAvailableMailGroups(config = {}) {
  return adminFetchJson('mail-groups/available', { method: 'GET' }, config)
}

/**
 * Get a single mail transport group with its accounts
 */
export async function getMailGroup(id, config = {}) {
  return adminFetchJson(`mail-groups/${id}`, { method: 'GET' }, config)
}

/**
 * Add an account to a transport group
 */
export async function addAccountToGroup(groupId, accountId, priority = 0, config = {}) {
  return adminFetchJson(`mail-groups/${groupId}/add-account`, {
    method: 'POST',
    body: JSON.stringify({ account_id: accountId, priority }),
  }, config)
}

/**
 * Remove an account from a transport group
 */
export async function removeAccountFromGroup(groupId, accountId, config = {}) {
  return adminFetchJson(`mail-groups/${groupId}/remove-account/${accountId}`, { method: 'DELETE' }, config)
}

/**
 * Update the priority of an account within a transport group
 */
export async function updateAccountPriority(groupId, accountId, priority, config = {}) {
  return adminFetchJson(`mail-groups/${groupId}/update-priority/${accountId}`, {
    method: 'PUT',
    body: JSON.stringify({ priority }),
  }, config)
}
