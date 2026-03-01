<script setup>
import { ref, reactive, computed, onMounted, onUnmounted, watch } from 'vue'
import IconButton from './IconButton.vue'
import SecretField from './SecretField.vue'
import AdminDataTable from './AdminDataTable.vue'
import MailAccountForm from './MailAccountForm.vue'
import MailGroupForm from './MailGroupForm.vue'
import { getMailAccounts, createMailAccount, updateMailAccount, deleteMailAccount, testMailAccount, getMailGroups, createMailGroup, updateMailGroup, deleteMailGroup, testMailGroup, getMailGroup, addAccountToGroup, removeAccountFromGroup, updateAccountPriority } from '../utils/adminApi'
import { useTranslation } from '../composables/useTranslation'

// Blacklist domain API functions
const getBlacklistDomains = async (config) => {
  const prefix = config.routePrefixRef?.value || 'admin'
  const res = await fetch(`/api/${prefix}/email-blacklist-domains`, {
    headers: config.apiKeyRef.value ? { 'X-Admin-Key': config.apiKeyRef.value } : {},
    credentials: 'include',
  })
  if (!res.ok) throw new Error(await res.text())
  return await res.json()
}

const createBlacklistDomain = async (config, data) => {
  const prefix = config.routePrefixRef?.value || 'admin'
  const res = await fetch(`/api/${prefix}/email-blacklist-domains`, {
    method: 'POST',
    headers: { ...config.apiKeyRef.value ? { 'X-Admin-Key': config.apiKeyRef.value } : {}, 'Content-Type': 'application/json' },
    body: JSON.stringify(data),
    credentials: 'include',
  })
  if (!res.ok) throw new Error(await res.text())
  return await res.json()
}

const updateBlacklistDomain = async (config, id, data) => {
  const prefix = config.routePrefixRef?.value || 'admin'
  const res = await fetch(`/api/${prefix}/email-blacklist-domains/${id}`, {
    method: 'PUT',
    headers: { ...config.apiKeyRef.value ? { 'X-Admin-Key': config.apiKeyRef.value } : {}, 'Content-Type': 'application/json' },
    body: JSON.stringify(data),
    credentials: 'include',
  })
  if (!res.ok) throw new Error(await res.text())
  return await res.json()
}

const deleteBlacklistDomain = async (config, id) => {
  const prefix = config.routePrefixRef?.value || 'admin'
  const res = await fetch(`/api/${prefix}/email-blacklist-domains/${id}`, {
    method: 'DELETE',
    headers: config.apiKeyRef.value ? { 'X-Admin-Key': config.apiKeyRef.value } : {},
    credentials: 'include',
  })
  if (!res.ok) throw new Error(await res.text())
  return await res.json()
}

const props = defineProps({ langCode: { type: String, default: 'de' } })
const { tr } = useTranslation()

const apiKey = ref(localStorage.getItem('admin_auth_session') || sessionStorage.getItem('admin_auth_session') || '')
// Ensure routePrefix is always 'admin' for this component (moderators don't have access to mail transport routes)
let initialRoutePrefix = localStorage.getItem('admin_route_prefix')
if (initialRoutePrefix === 'moderator') {
  initialRoutePrefix = 'admin'
  localStorage.setItem('admin_route_prefix', 'admin')
}
const routePrefix = ref(initialRoutePrefix || 'admin')
const currentUser = ref(JSON.parse(localStorage.getItem('admin_user') || sessionStorage.getItem('admin_user') || 'null'))

// Reusable auth config for admin API calls
const adminConfig = () => ({ routePrefixRef: routePrefix, apiKeyRef: apiKey })

// Normalize id comparison across number/string variants
const sameId = (a, b) => String(a) === String(b)

// State
const accounts = ref([])
const groups = ref([])
const loading = ref(false)
const message = ref('')
const error = ref('')
const editingAccount = ref(null)
const editingGroup = ref(null)
const showAddAccountForm = ref(false)
const showAddGroupForm = ref(false)
const managingGroupAccounts = ref(null) // The group whose accounts are being managed
const groupAccounts = ref([]) // Accounts currently assigned to the managed group
const addAccountId = ref(null) // Selected account id for adding to group
const addAccountPriority = ref(0) // Priority for the new account

// Tabs
const tabs = [
  { id: 'accounts', labelKey: 'admin_mail_transports_tab_accounts', fallback: 'Mail Accounts' },
  { id: 'groups', labelKey: 'admin_mail_transports_tab_groups', fallback: 'Transport Groups' },
  { id: 'blacklist', labelKey: 'admin_mail_transports_tab_blacklist', fallback: 'Blacklist' },
]

const selectedTab = ref('accounts')

// Blacklist domain form state
const blacklistDomainForm = reactive({
  domain: '',
  active: true,
})

// Blacklist domains state
const blacklistDomains = ref([])

// Account form state
const accountForm = reactive({
  name: '',
  host: '',
  port: 587,
  encryption: 'tls',
  username: '',
  password: '',
  authMethod: 'plain',
  ignoreSelfSigned: false,
  timeout: 30,
  rateLimitEnabled: false,
  rateLimitPerMinute: null,
  rateLimitPerHour: null,
  fromAddress: '', // Send as address
  replyToAddress: '', // Reply-to address
  returnPathAddress: '', // Bounce address
  tlsVersion: 'auto', // TLS version: auto, 1.2, 1.3
  usePersistentConnection: false, // Keep-Alive connections
  isActive: true,
})

// Group form state
const groupForm = reactive({
  name: '',
  description: '',
  rateLimitEnabled: false,
  rateLimitPerMinute: null,
  rateLimitPerHour: null,
  failoverStrategy: 'sequential',
  maxRetriesPerAccount: 3,
  isActive: true,
})

// Account table columns
const accountColumns = computed(() => [
  { key: 'id', label: tr('admin_mail_transports_columns_id'), sortable: true },
  { key: 'name', label: tr('admin_mail_transports_columns_name'), sortable: true },
  { key: 'host', label: tr('admin_mail_transports_columns_host'), sortable: true },
  { key: 'port', label: tr('admin_mail_transports_columns_port'), sortable: true },
  { key: 'encryption', label: tr('admin_mail_transports_columns_encryption'), sortable: true },
  { key: 'auth_method', label: tr('admin_mail_transports_columns_auth_method'), sortable: true },
  { key: 'username', label: tr('admin_mail_transports_columns_username'), sortable: true },
  { key: 'timeout', label: tr('admin_mail_transports_columns_timeout'), sortable: true, type: 'number' },
  { key: 'rate_limit_enabled', label: tr('admin_mail_transports_columns_rate_limit'), sortable: true, type: 'boolean' },
  { key: 'rate_limit_per_minute', label: tr('admin_mail_transports_columns_rate_limit_minute'), sortable: true, type: 'number' },
  { key: 'rate_limit_per_hour', label: tr('admin_mail_transports_columns_rate_limit_hour'), sortable: true, type: 'number' },
  { key: 'ignore_self_signed', label: tr('admin_mail_transports_columns_ignore_self_signed'), sortable: true, type: 'boolean' },
  { key: 'tls_version', label: tr('admin_mail_transports_columns_tls_version'), sortable: true },
  { key: 'from_address', label: tr('admin_mail_transports_columns_from_address'), sortable: true },
  { key: 'reply_to_address', label: tr('admin_mail_transports_columns_reply_to_address'), sortable: true },
  { key: 'return_path_address', label: tr('admin_mail_transports_columns_return_path_address'), sortable: true },
  { key: 'is_active', label: tr('admin_mail_transports_columns_active'), sortable: true, type: 'boolean' },
])

// Group table columns
const groupColumns = computed(() => [
  { key: 'id', label: tr('admin_mail_transports_columns_id'), sortable: true },
  { key: 'name', label: tr('admin_mail_transports_columns_name'), sortable: true },
  { key: 'description', label: tr('admin_mail_transports_columns_description'), sortable: false },
  { key: 'failover_strategy', label: tr('admin_mail_transports_columns_failover_strategy'), sortable: true },
  { key: 'rate_limit_enabled', label: tr('admin_mail_transports_columns_rate_limit'), sortable: true, type: 'boolean' },
  { key: 'accounts_count', label: tr('admin_mail_transports_columns_accounts'), sortable: true, type: 'number' },
  { key: 'is_active', label: tr('admin_mail_transports_columns_active'), sortable: true, type: 'boolean' },
])

// Blacklist domain table columns
const blacklistDomainColumns = computed(() => [
  { key: 'id', label: tr('admin_mail_transports_columns_id'), sortable: true, hiddenByDefault: true },
  { key: 'domain', label: tr('admin_mail_transports_columns_domain'), sortable: true },
  { key: 'active', label: tr('admin_mail_transports_columns_active'), sortable: true, type: 'boolean' },
])

// Accounts available to assign (not yet in the managed group)
const availableAccountsForGroup = computed(() => {
  if (!managingGroupAccounts.value) return []
  const assignedIds = new Set(groupAccounts.value.map(ga => String(ga.account_id)))
  return accounts.value.filter(a => !assignedIds.has(String(a.id)))
})

// Computed properties
const visibleAccounts = computed(() => {
  if (selectedTab.value === 'accounts') return accounts.value
  return []
})

const visibleGroups = computed(() => {
  if (selectedTab.value === 'groups') return groups.value
  return []
})

const visibleBlacklistDomains = computed(() => {
  if (selectedTab.value === 'blacklist') return blacklistDomains.value
  return []
})

// Translations helper
function t(key) {
  const translations = {
    // Accounts
    admin_mail_transports_tab_accounts: tr('admin_mail_transports_tab_accounts', 'Mail Accounts'),
    admin_mail_transports_tab_groups: tr('admin_mail_transports_tab_groups', 'Transport Groups'),
    admin_mail_transports_columns_id: tr('admin_mail_transports_columns_id', 'ID'),
    admin_mail_transports_columns_name: tr('admin_mail_transports_columns_name', 'Name'),
    admin_mail_transports_columns_host: tr('admin_mail_transports_columns_host', 'Host'),
    admin_mail_transports_columns_port: tr('admin_mail_transports_columns_port', 'Port'),
    admin_mail_transports_columns_encryption: tr('admin_mail_transports_columns_encryption', 'Encryption'),
    admin_mail_transports_columns_auth_method: tr('admin_mail_transports_columns_auth_method', 'Auth Method'),
    admin_mail_transports_columns_username: tr('admin_mail_transports_columns_username', 'Username'),
    admin_mail_transports_columns_timeout: tr('admin_mail_transports_columns_timeout', 'Timeout (s)'),
    admin_mail_transports_columns_rate_limit: tr('admin_mail_transports_columns_rate_limit', 'Rate Limiting'),
    admin_mail_transports_columns_rate_limit_minute: tr('admin_mail_transports_columns_rate_limit_minute', 'Rate/Min'),
    admin_mail_transports_columns_rate_limit_hour: tr('admin_mail_transports_columns_rate_limit_hour', 'Rate/Hour'),
    admin_mail_transports_columns_ignore_self_signed: tr('admin_mail_transports_columns_ignore_self_signed', 'Ignore Self-Signed'),
    admin_mail_transports_columns_tls_version: tr('admin_mail_transports_columns_tls_version', 'TLS Version'),
    admin_mail_transports_columns_from_address: tr('admin_mail_transports_columns_from_address', 'From Address'),
    admin_mail_transports_columns_reply_to_address: tr('admin_mail_transports_columns_reply_to_address', 'Reply-To'),
    admin_mail_transports_columns_return_path_address: tr('admin_mail_transports_columns_return_path_address', 'Return-Path'),
    admin_mail_transports_columns_active: tr('admin_mail_transports_columns_active', 'Active'),
    admin_mail_transports_columns_description: tr('admin_mail_transports_columns_description', 'Description'),
    admin_mail_transports_columns_failover_strategy: tr('admin_mail_transports_columns_failover_strategy', 'Failover Strategy'),
    admin_mail_transports_columns_accounts: tr('admin_mail_transports_columns_accounts', 'Accounts'),
    
    // Form labels
    admin_mail_transports_account_name: tr('admin_mail_transports_account_name', 'Account Name'),
    admin_mail_transports_account_host: tr('admin_mail_transports_account_host', 'SMTP Host'),
    admin_mail_transports_account_port: tr('admin_mail_transports_account_port', 'Port'),
    admin_mail_transports_account_encryption: tr('admin_mail_transports_account_encryption', 'Encryption'),
    admin_mail_transports_account_username: tr('admin_mail_transports_account_username', 'Username'),
    admin_mail_transports_account_password: tr('admin_mail_transports_account_password', 'Password'),
    admin_mail_transports_account_auth_method: tr('admin_mail_transports_account_auth_method', 'Authentication Method'),
    admin_mail_transports_account_ignore_self_signed: tr('admin_mail_transports_account_ignore_self_signed', 'Ignore Self-Signed Certificates'),
    admin_mail_transports_account_timeout: tr('admin_mail_transports_account_timeout', 'Timeout (seconds)'),
    admin_mail_transports_account_rate_limit_enabled: tr('admin_mail_transports_account_rate_limit_enabled', 'Enable Rate Limiting'),
    admin_mail_transports_account_rate_limit_minute: tr('admin_mail_transports_account_rate_limit_minute', 'Rate Limit per Minute'),
    admin_mail_transports_account_rate_limit_hour: tr('admin_mail_transports_account_rate_limit_hour', 'Rate Limit per Hour'),
    admin_mail_transports_account_from_address: tr('admin_mail_transports_account_from_address', 'Send As Address (From)'),
    admin_mail_transports_account_reply_to_address: tr('admin_mail_transports_account_reply_to_address', 'Reply-To Address'),
    admin_mail_transports_account_return_path_address: tr('admin_mail_transports_account_return_path_address', 'Return-Path (Bounce Address)'),
    admin_mail_transports_account_tls_version: tr('admin_mail_transports_account_tls_version', 'TLS Version'),
    admin_mail_transports_account_persistent_connection: tr('admin_mail_transports_account_persistent_connection', 'Use Persistent Connections (Keep-Alive)'),
    tls_version_auto: tr('tls_version_auto', 'Auto (Negotiate)'),
    admin_mail_transports_account_active: tr('admin_mail_transports_account_active', 'Active'),
    
    // Group labels
    admin_mail_transports_group_name: tr('admin_mail_transports_group_name', 'Group Name'),
    admin_mail_transports_group_description: tr('admin_mail_transports_group_description', 'Description'),
    admin_mail_transports_group_rate_limit_enabled: tr('admin_mail_transports_group_rate_limit_enabled', 'Enable Rate Limiting'),
    admin_mail_transports_group_rate_limit_minute: tr('admin_mail_transports_group_rate_limit_minute', 'Rate Limit per Minute'),
    admin_mail_transports_group_rate_limit_hour: tr('admin_mail_transports_group_rate_limit_hour', 'Rate Limit per Hour'),
    admin_mail_transports_group_failover_strategy: tr('admin_mail_transports_group_failover_strategy', 'Failover Strategy'),
    admin_mail_transports_group_max_retries: tr('admin_mail_transports_group_max_retries', 'Max Retries per Account'),
    admin_mail_transports_group_active: tr('admin_mail_transports_group_active', 'Active'),
    
    // Buttons
    admin_mail_transports_add_account: tr('admin_mail_transports_add_account', 'Add Mail Account'),
    admin_mail_transports_add_group: tr('admin_mail_transports_add_group', 'Add Transport Group'),
    admin_mail_transports_test_connection: tr('admin_mail_transports_test_connection', 'Test Connection'),
    admin_mail_transports_edit: tr('admin_mail_transports_edit', 'Edit'),
    admin_mail_transports_delete: tr('admin_mail_transports_delete', 'Delete'),
    
    // Messages
    admin_mail_transports_account_saved: tr('admin_mail_transports_account_saved', 'Mail account saved successfully'),
    admin_mail_transports_group_saved: tr('admin_mail_transports_group_saved', 'Transport group saved successfully'),
    admin_mail_transports_account_deleted: tr('admin_mail_transports_account_deleted', 'Mail account deleted'),
    admin_mail_transports_group_deleted: tr('admin_mail_transports_group_deleted', 'Transport group deleted'),
    admin_mail_transports_test_success: tr('admin_mail_transports_test_success', 'Connection test successful!'),
    admin_mail_transports_test_failed: tr('admin_mail_transports_test_failed', 'Connection test failed: '),
    admin_mail_transports_no_accounts: tr('admin_mail_transports_no_accounts', 'No mail accounts found'),
    admin_mail_transports_no_groups: tr('admin_mail_transports_no_groups', 'No transport groups found'),
    admin_mail_transports_no_blacklist_domains: tr('admin_mail_transports_no_blacklist_domains', 'No blacklisted domains found'),
    
    // Blacklist domain labels
    admin_mail_transports_tab_blacklist: tr('admin_mail_transports_tab_blacklist', 'Blacklist'),
    admin_mail_transports_columns_domain: tr('admin_mail_transports_columns_domain', 'Domain'),
    admin_mail_transports_add_domain: tr('admin_mail_transports_add_domain', 'Add Domain'),
    admin_mail_transports_edit_domain: tr('admin_mail_transports_edit_domain', 'Edit Domain'),
    admin_mail_transports_delete_domain: tr('admin_mail_transports_delete_domain', 'Delete Domain'),
    admin_mail_transports_domain_saved: tr('admin_mail_transports_domain_saved', 'Domain saved successfully'),
    admin_mail_transports_domain_deleted: tr('admin_mail_transports_domain_deleted', 'Domain deleted from blacklist'),
    admin_mail_transports_confirm_delete_domain: tr('admin_mail_transports_confirm_delete_domain', 'Are you sure you want to delete this domain from the blacklist?'),
    admin_mail_transports_error_loading_blacklist: tr('admin_mail_transports_error_loading_blacklist', 'Error loading blacklist domains'),
    admin_mail_transports_error_saving_domain: tr('admin_mail_transports_error_saving_domain', 'Error saving domain'),
    admin_mail_transports_error_deleting_domain: tr('admin_mail_transports_error_deleting_domain', 'Error deleting domain'),
    
    // Encryption options
    encryption_tls: tr('encryption_tls', 'TLS'),
    encryption_ssl: tr('encryption_ssl', 'SSL'),
    encryption_none: tr('encryption_none', 'None'),
    
    // Auth method options
    auth_method_plain: tr('auth_method_plain', 'Plain (Standard SMTP)'),
    auth_method_login: tr('auth_method_login', 'Login'),
    auth_method_crammd5: tr('auth_method_crammd5', 'CRAM-MD5'),
    auth_method_oauth2_exchange: tr('auth_method_oauth2_exchange', 'OAuth2 - Exchange Online'),
    auth_method_oauth2_google: tr('auth_method_oauth2_google', 'OAuth2 - Google Mail/Workspace'),
    auth_method_api_key_sendgrid: tr('auth_method_api_key_sendgrid', 'API Key - SendGrid'),
    auth_method_api_key_mailgun: tr('auth_method_api_key_mailgun', 'API Key - Mailgun'),
    auth_method_api_key_postmark: tr('auth_method_api_key_postmark', 'API Key - Postmark'),
    
    // Failover strategy options
    failover_sequential: tr('failover_sequential', 'Sequential (Failover)'),
    failover_round_robin: tr('failover_round_robin', 'Round Robin'),
    failover_random: tr('failover_random', 'Random'),
  }
  return translations[key] || key
}

// Load data
async function loadAccounts() {
  if (!apiKey.value) return
  loading.value = true
  try {
    const res = await getMailAccounts(adminConfig())
    // API may return a paginated object { data: { data: [...] } }
    accounts.value = Array.isArray(res)
      ? res
      : res?.data?.data || res?.data || []
  } catch (e) {
    setError(t('admin_mail_transports_error_loading_accounts') + ': ' + e)
  } finally {
    loading.value = false
  }
}

async function loadGroups() {
  if (!apiKey.value) return
  loading.value = true
  try {
    const res = await getMailGroups(adminConfig())
    groups.value = Array.isArray(res)
      ? res
      : res?.data?.data || res?.data || []
  } catch (e) {
    setError(t('admin_mail_transports_error_loading_groups') + ': ' + e)
  } finally {
    loading.value = false
  }
}

async function loadBlacklistDomains() {
  if (!apiKey.value) return
  loading.value = true
  try {
    const res = await getBlacklistDomains(adminConfig())
    blacklistDomains.value = Array.isArray(res.data) ? res.data : (Array.isArray(res) ? res : [])
  } catch (e) {
    setError(t('admin_mail_transports_error_loading_blacklist') + ': ' + e)
  } finally {
    loading.value = false
  }
}

async function loadAll() {
  if (!apiKey.value) { setError(tr('please_login_api_key_missing')); return }
  await Promise.all([loadAccounts(), loadGroups(), loadBlacklistDomains()])
  setMessage(t('admin_mail_transports_data_loaded'))
}

// Account operations
function openAccountForm(account = null) {
  editingAccount.value = account
  showAddAccountForm.value = !account
  if (account) {
    // Edit existing
    accountForm.name = account.name || ''
    accountForm.host = account.host || ''
    accountForm.port = account.port || 587
    accountForm.encryption = account.encryption || 'tls'
    accountForm.username = account.username || ''
    accountForm.password = '' // Don't show password
    accountForm.authMethod = account.auth_method || 'plain'
    accountForm.ignoreSelfSigned = !!account.ignore_self_signed
    accountForm.timeout = account.timeout || 30
    accountForm.rateLimitEnabled = !!account.rate_limit_enabled
    accountForm.rateLimitPerMinute = account.rate_limit_per_minute || null
    accountForm.rateLimitPerHour = account.rate_limit_per_hour || null
    accountForm.fromAddress = account.from_address || ''
    accountForm.replyToAddress = account.reply_to_address || ''
    accountForm.returnPathAddress = account.return_path_address || ''
    accountForm.tlsVersion = account.tls_version || 'auto'
    accountForm.usePersistentConnection = !!account.use_persistent_connection
    accountForm.isActive = !!account.is_active
  } else {
    // New account
    accountForm.name = ''
    accountForm.host = ''
    accountForm.port = 587
    accountForm.encryption = 'tls'
    accountForm.username = ''
    accountForm.password = ''
    accountForm.authMethod = 'plain'
    accountForm.ignoreSelfSigned = false
    accountForm.timeout = 30
    accountForm.rateLimitEnabled = false
    accountForm.rateLimitPerMinute = null
    accountForm.rateLimitPerHour = null
    accountForm.fromAddress = ''
    accountForm.replyToAddress = ''
    accountForm.returnPathAddress = ''
    accountForm.tlsVersion = 'auto'
    accountForm.usePersistentConnection = false
    accountForm.isActive = true
  }
}

async function saveAccount(formData = null) {
  if (!apiKey.value) { setError(tr('api_key_missing')); return }
  
  loading.value = true
  try {
    // Prefer payload from child emit to avoid stale state
    if (formData) Object.assign(accountForm, formData)

    const accountData = {
      name: accountForm.name,
      host: accountForm.host,
      port: accountForm.port,
      encryption: accountForm.encryption,
      username: accountForm.username,
      password: accountForm.password || (editingAccount.value?.password || ''),
      auth_method: accountForm.authMethod,
      ignore_self_signed: accountForm.ignoreSelfSigned ? 1 : 0,
      timeout: accountForm.timeout,
      rate_limit_enabled: accountForm.rateLimitEnabled ? 1 : 0,
      rate_limit_per_minute: accountForm.rateLimitPerMinute || null,
      rate_limit_per_hour: accountForm.rateLimitPerHour || null,
      from_address: accountForm.fromAddress || null,
      reply_to_address: accountForm.replyToAddress || null,
      return_path_address: accountForm.returnPathAddress || null,
      tls_version: accountForm.tlsVersion || 'auto',
      is_active: accountForm.isActive ? 1 : 0,
    }
    
    if (editingAccount.value) {
      const res = await updateMailAccount(editingAccount.value.id, accountData, adminConfig())
      const saved = res?.data?.data || res?.data || res
      accounts.value = accounts.value.map(acc => sameId(acc.id, saved?.id) ? saved : acc)
      setMessage(t('admin_mail_transports_account_saved'))
    } else {
      const res = await createMailAccount(accountData, adminConfig())
      const saved = res?.data?.data || res?.data || res
      if (saved) accounts.value = [saved, ...accounts.value]
      setMessage(t('admin_mail_transports_account_saved'))
    }
    
    editingAccount.value = null
    showAddAccountForm.value = false
    await loadAccounts()
  } catch (e) {
    setError(e.message || t('admin_mail_transports_error_saving_account'))
  } finally {
    loading.value = false
  }
}

function cancelAccountForm() {
  editingAccount.value = null
  showAddAccountForm.value = false
  accountForm.name = ''
  accountForm.host = ''
  accountForm.port = 587
  accountForm.encryption = 'tls'
  accountForm.username = ''
  accountForm.password = ''
  accountForm.authMethod = 'plain'
  accountForm.ignoreSelfSigned = false
  accountForm.timeout = 30
  accountForm.rateLimitEnabled = false
  accountForm.rateLimitPerMinute = null
  accountForm.rateLimitPerHour = null
  accountForm.fromAddress = ''
  accountForm.replyToAddress = ''
  accountForm.returnPathAddress = ''
  accountForm.tlsVersion = 'auto'
  accountForm.usePersistentConnection = false
  accountForm.isActive = true
}

async function deleteAccount(id) {
  if (!apiKey.value) { setError(tr('api_key_missing')); return }
  
  if (!confirm(t('admin_mail_transports_confirm_delete_account'))) return
  
  loading.value = true
  try {
    const res = await deleteMailAccount(id, adminConfig())
    // Optimistically remove from local list to reflect deletion immediately
    accounts.value = accounts.value.filter(acc => !sameId(acc.id, id))
    const deleted = res?.deleted
    if (deleted === false) {
      setError((res?.message || t('admin_mail_transports_error_deleting_account')) + ' (backend could not delete)')
    } else {
      setMessage(t('admin_mail_transports_account_deleted'))
    }
    await loadAccounts()
  } catch (e) {
    setError(e.message || t('admin_mail_transports_error_deleting_account'))
  } finally {
    loading.value = false
  }
}

async function testAccountConnection(id) {
  if (!apiKey.value) { setError(tr('api_key_missing')); return }
  
  loading.value = true
  try {
    const result = await testMailAccount(id, adminConfig())
    setMessage(t('admin_mail_transports_test_success') + ' ' + (result.message || ''))
  } catch (e) {
    setError(t('admin_mail_transports_test_failed') + e.message)
  } finally {
    loading.value = false
  }
}

// Blacklist domain operations
function openBlacklistDomainForm(domain = null) {
  editingBlacklistDomain.value = domain
  showAddBlacklistDomainForm.value = !domain
  if (domain) {
    // Edit existing
    blacklistDomainForm.domain = domain.domain || ''
    blacklistDomainForm.active = !!domain.active
  } else {
    // New domain
    blacklistDomainForm.domain = ''
    blacklistDomainForm.active = true
  }
}

let editingBlacklistDomain = ref(null)
let showAddBlacklistDomainForm = ref(false)

async function saveBlacklistDomain(formData = null) {
  if (!apiKey.value) { setError(tr('api_key_missing')); return }
  
  loading.value = true
  try {
    if (formData) Object.assign(blacklistDomainForm, formData)

    const domainData = {
      domain: blacklistDomainForm.domain,
      active: blacklistDomainForm.active ? 1 : 0,
    }

    if (editingBlacklistDomain.value) {
      const res = await updateBlacklistDomain(adminConfig(), editingBlacklistDomain.value.id, domainData)
      const saved = res?.data || res
      blacklistDomains.value = blacklistDomains.value.map(d => sameId(d.id, saved?.id) ? saved : d)
      setMessage(t('admin_mail_transports_domain_saved'))
    } else {
      const res = await createBlacklistDomain(adminConfig(), domainData)
      const saved = res?.data || res
      if (saved) blacklistDomains.value = [saved, ...blacklistDomains.value]
      setMessage(t('admin_mail_transports_domain_saved'))
    }
    
    editingBlacklistDomain.value = null
    showAddBlacklistDomainForm.value = false
    await loadBlacklistDomains()
  } catch (e) {
    setError(e.message || t('admin_mail_transports_error_saving_domain'))
  } finally {
    loading.value = false
  }
}

async function deleteBlacklistDomainItem(id) {
  if (!apiKey.value) { setError(tr('api_key_missing')); return }
  
  if (!confirm(t('admin_mail_transports_confirm_delete_domain'))) return
  
  loading.value = true
  try {
    await deleteBlacklistDomain(adminConfig(), id)
    blacklistDomains.value = blacklistDomains.value.filter(d => !sameId(d.id, id))
    setMessage(t('admin_mail_transports_domain_deleted'))
  } catch (e) {
    setError(e.message || t('admin_mail_transports_error_deleting_domain'))
  } finally {
    loading.value = false
  }
}

// Group operations
function openGroupForm(group = null) {
  editingGroup.value = group
  showAddGroupForm.value = !group
  if (group) {
    // Edit existing
    groupForm.name = group.name || ''
    groupForm.description = group.description || ''
    groupForm.rateLimitEnabled = !!group.rate_limit_enabled
    groupForm.rateLimitPerMinute = group.rate_limit_per_minute || null
    groupForm.rateLimitPerHour = group.rate_limit_per_hour || null
    groupForm.failoverStrategy = group.failover_strategy || 'sequential'
    groupForm.maxRetriesPerAccount = group.max_retries_per_account || 3
    groupForm.isActive = !!group.is_active
  } else {
    // New group
    groupForm.name = ''
    groupForm.description = ''
    groupForm.rateLimitEnabled = false
    groupForm.rateLimitPerMinute = null
    groupForm.rateLimitPerHour = null
    groupForm.failoverStrategy = 'sequential'
    groupForm.maxRetriesPerAccount = 3
    groupForm.isActive = true
  }
}

async function saveGroup(formData = null) {
  if (!apiKey.value) { setError(tr('api_key_missing')); return }
  
  loading.value = true
  try {
    // Prefer payload from child emit to avoid stale state
    if (formData) Object.assign(groupForm, formData)

    const groupData = {
      name: groupForm.name,
      description: groupForm.description,
      rate_limit_enabled: groupForm.rateLimitEnabled ? 1 : 0,
      rate_limit_per_minute: groupForm.rateLimitPerMinute || null,
      rate_limit_per_hour: groupForm.rateLimitPerHour || null,
      failover_strategy: groupForm.failoverStrategy,
      max_retries_per_account: groupForm.maxRetriesPerAccount,
      is_active: groupForm.isActive ? 1 : 0,
    }
    
    if (editingGroup.value) {
      const res = await updateMailGroup(editingGroup.value.id, groupData, adminConfig())
      const saved = res?.data?.data || res?.data || res
      groups.value = groups.value.map(g => sameId(g.id, saved?.id) ? saved : g)
      setMessage(t('admin_mail_transports_group_saved'))
    } else {
      const res = await createMailGroup(groupData, adminConfig())
      const saved = res?.data?.data || res?.data || res
      if (saved) groups.value = [saved, ...groups.value]
      setMessage(t('admin_mail_transports_group_saved'))
    }
    
    editingGroup.value = null
    showAddGroupForm.value = false
    await loadGroups()
  } catch (e) {
    setError(e.message || t('admin_mail_transports_error_saving_group'))
  } finally {
    loading.value = false
  }
}

function cancelGroupForm() {
  editingGroup.value = null
  showAddGroupForm.value = false
  groupForm.name = ''
  groupForm.description = ''
  groupForm.rateLimitEnabled = false
  groupForm.rateLimitPerMinute = null
  groupForm.rateLimitPerHour = null
  groupForm.failoverStrategy = 'sequential'
  groupForm.maxRetriesPerAccount = 3
  groupForm.isActive = true
}

async function deleteGroup(id) {
  if (!apiKey.value) { setError(tr('api_key_missing')); return }
  
  if (!confirm(t('admin_mail_transports_confirm_delete_group'))) return
  
  loading.value = true
  try {
    await deleteMailGroup(id, adminConfig())
    groups.value = groups.value.filter(g => !sameId(g.id, id))
    setMessage(t('admin_mail_transports_group_deleted'))
    await loadGroups()
  } catch (e) {
    setError(e.message || t('admin_mail_transports_error_deleting_group'))
  } finally {
    loading.value = false
  }
}

async function testGroupConnection(id) {
  if (!apiKey.value) { setError(tr('api_key_missing')); return }
  
  loading.value = true
  try {
    const result = await testMailGroup(id, adminConfig())
    setMessage(t('admin_mail_transports_test_success') + ' ' + (result.message || ''))
  } catch (e) {
    setError(t('admin_mail_transports_test_failed') + e.message)
  } finally {
    loading.value = false
  }
}

// Group account management operations
async function openGroupAccountManager(group) {
  managingGroupAccounts.value = group
  addAccountId.value = null
  addAccountPriority.value = 0
  await loadGroupAccounts(group.id)
}

function closeGroupAccountManager() {
  managingGroupAccounts.value = null
  groupAccounts.value = []
  addAccountId.value = null
  addAccountPriority.value = 0
}

async function loadGroupAccounts(groupId) {
  loading.value = true
  try {
    const res = await getMailGroup(groupId, adminConfig())
    const group = res?.data?.data || res?.data || res
    groupAccounts.value = (group?.accounts || []).sort((a, b) => (a.priority ?? 0) - (b.priority ?? 0))
  } catch (e) {
    setError(e.message || 'Failed to load group accounts')
  } finally {
    loading.value = false
  }
}

async function handleAddAccountToGroup() {
  if (!managingGroupAccounts.value || !addAccountId.value) return
  loading.value = true
  try {
    await addAccountToGroup(managingGroupAccounts.value.id, addAccountId.value, addAccountPriority.value || 0, adminConfig())
    setMessage(t('admin_mail_transports_account_added_to_group'))
    addAccountId.value = null
    addAccountPriority.value = 0
    await loadGroupAccounts(managingGroupAccounts.value.id)
    await loadGroups()
  } catch (e) {
    setError(e.message || 'Failed to add account to group')
  } finally {
    loading.value = false
  }
}

async function handleRemoveAccountFromGroup(accountId) {
  if (!managingGroupAccounts.value) return
  if (!confirm(t('admin_mail_transports_confirm_remove_account_from_group'))) return
  loading.value = true
  try {
    await removeAccountFromGroup(managingGroupAccounts.value.id, accountId, adminConfig())
    setMessage(t('admin_mail_transports_account_removed_from_group'))
    await loadGroupAccounts(managingGroupAccounts.value.id)
    await loadGroups()
  } catch (e) {
    setError(e.message || 'Failed to remove account from group')
  } finally {
    loading.value = false
  }
}

async function handleUpdatePriority(accountId, newPriority) {
  if (!managingGroupAccounts.value) return
  loading.value = true
  try {
    await updateAccountPriority(managingGroupAccounts.value.id, accountId, newPriority, adminConfig())
    setMessage(t('admin_mail_transports_priority_updated'))
    await loadGroupAccounts(managingGroupAccounts.value.id)
  } catch (e) {
    setError(e.message || 'Failed to update priority')
  } finally {
    loading.value = false
  }
}

function getAccountName(accountId) {
  const acc = accounts.value.find(a => sameId(a.id, accountId))
  return acc ? `${acc.name} (${acc.host})` : `Account #${accountId}`
}

// Helper functions
function setMessage(msg) { message.value = msg; error.value = '' }
function setError(msg) { error.value = msg; message.value = '' }

function handleKeyUpdate(e) {
  apiKey.value = e.detail || ''
}

// Lifecycle hooks
onMounted(() => {
  window.addEventListener('api-key-updated', handleKeyUpdate)
  if (apiKey.value) loadAll()
})

onUnmounted(() => {
  window.removeEventListener('api-key-updated', handleKeyUpdate)
})
</script>

<template>
  <div class="stack">
    <!-- Controls -->
    <div class="controls">
      <IconButton icon="refresh" :label="tr('admin_mail_transports_reload', 'Reload')" @click="loadAll" :disabled="loading" />
    </div>
    
    <!-- Messages -->
    <div v-if="error" class="error">{{ error }}</div>
    <div v-if="message" class="message">{{ message }}</div>
    
    <!-- Tabs with Add Button on Right -->
    <div class="tabs-row">
      <div class="admin-tabs">
        <button 
          v-for="tab in tabs" 
          :key="tab.id" 
          :class="['admin-tab-button', { active: selectedTab === tab.id }]" 
          @click="selectedTab = tab.id"
        >
          {{ t(`admin_mail_transports_tab_${tab.id}`) }}
        </button>
      </div>
      <div class="tab-add-btn">
        <IconButton
          v-if="selectedTab === 'accounts'"
          icon="plus"
          :label="t('admin_mail_transports_add_account')"
          @click="openAccountForm()"
        />
        <IconButton
          v-else-if="selectedTab === 'groups'"
          icon="plus"
          :label="t('admin_mail_transports_add_group')"
          @click="openGroupForm()"
        />
        <IconButton
          v-else-if="selectedTab === 'blacklist'"
          icon="plus"
          :label="t('admin_mail_transports_add_domain')"
          @click="openBlacklistDomainForm()"
        />
      </div>
    </div>
    
    <!-- Accounts Tab -->
    <div v-if="selectedTab === 'accounts'" class="content-section">
      
      <!-- Account Form (Inline) -->
      <div v-if="editingAccount || showAddAccountForm" class="form-card">
        <h3>{{ editingAccount ? t('admin_mail_transports_edit_account') : t('admin_mail_transports_add_account') }}</h3>
        
        <MailAccountForm 
          :model-value="accountForm"
          :is-editing="!!editingAccount"
          @update:model-value="(val) => Object.assign(accountForm, val)"
          @save="(payload) => saveAccount(payload)"
          @cancel="cancelAccountForm"
        />
      </div>
      
      <AdminDataTable 
        :columns="accountColumns"
        :rows="visibleAccounts"
        :loading="loading"
        :empty-message="t('admin_mail_transports_no_accounts')"
        :initial-hidden-columns="['id']"
      >
        <!-- Actions column -->
        <template #row-actions="{ row }">
          <div class="action-buttons">
            <IconButton 
              icon="play" 
              :label="t('admin_mail_transports_test_connection')" 
              variant="ghost"
              @click="testAccountConnection(row.id)"
            />
            <IconButton 
              icon="pencil" 
              :label="t('admin_mail_transports_edit')" 
              variant="ghost"
              @click="openAccountForm(row)"
            />
            <IconButton 
              icon="trash" 
              :label="t('admin_mail_transports_delete')" 
              variant="danger"
              @click="deleteAccount(row.id)"
            />
          </div>
        </template>
      </AdminDataTable>
    </div>
    
    <!-- Groups Tab -->
    <div v-if="selectedTab === 'groups'" class="content-section">
      
      <!-- Group Form (Inline) -->
      <div v-if="editingGroup || showAddGroupForm" class="form-card">
        <h3>{{ editingGroup ? t('admin_mail_transports_edit_group') : t('admin_mail_transports_add_group') }}</h3>
        
        <MailGroupForm 
          :model-value="groupForm"
          :is-editing="!!editingGroup"
          @update:model-value="(val) => Object.assign(groupForm, val)"
          @save="(payload) => saveGroup(payload)"
          @cancel="cancelGroupForm"
        />
      </div>
      
      <AdminDataTable 
        :columns="groupColumns"
        :rows="visibleGroups"
        :loading="loading"
        :empty-message="t('admin_mail_transports_no_groups')"
        :initial-hidden-columns="['id']"
      >
        <!-- Actions column -->
        <template #row-actions="{ row }">
          <div class="action-buttons">
            <IconButton 
              icon="list" 
              :label="t('admin_mail_transports_manage_accounts')" 
              variant="ghost"
              @click="openGroupAccountManager(row)"
            />
            <IconButton 
              icon="play" 
              :label="t('admin_mail_transports_test_connection')" 
              variant="ghost"
              @click="testGroupConnection(row.id)"
            />
            <IconButton 
              icon="pencil" 
              :label="t('admin_mail_transports_edit')" 
              variant="ghost"
              @click="openGroupForm(row)"
            />
            <IconButton 
              icon="trash" 
              :label="t('admin_mail_transports_delete')" 
              variant="danger"
              @click="deleteGroup(row.id)"
            />
          </div>
        </template>
      </AdminDataTable>
      
      <!-- Group Account Manager -->
      <div v-if="managingGroupAccounts" class="form-card group-account-manager">
        <div class="manager-header">
          <h3>{{ t('admin_mail_transports_manage_accounts_for') }} "{{ managingGroupAccounts.name }}"</h3>
          <IconButton icon="close" :label="t('admin_mail_transports_close')" variant="ghost" @click="closeGroupAccountManager" />
        </div>
        
        <!-- Add Account Row -->
        <div class="add-account-row">
          <label class="field">
            <span>{{ t('admin_mail_transports_select_account') }}</span>
            <select v-model="addAccountId" :disabled="!availableAccountsForGroup.length">
              <option :value="null" disabled>{{ availableAccountsForGroup.length ? t('admin_mail_transports_choose_account') : t('admin_mail_transports_no_available_accounts') }}</option>
              <option v-for="acc in availableAccountsForGroup" :key="acc.id" :value="acc.id">{{ acc.name }} ({{ acc.host }})</option>
            </select>
          </label>
          <label class="field priority-field">
            <span>{{ t('admin_mail_transports_priority') }}</span>
            <input v-model.number="addAccountPriority" type="number" min="0" max="999" placeholder="0" />
          </label>
          <div class="field add-btn-field">
            <span>&nbsp;</span>
            <IconButton icon="plus" :label="t('admin_mail_transports_add_to_group')" @click="handleAddAccountToGroup" :disabled="!addAccountId" />
          </div>
        </div>
        
        <!-- Assigned Accounts List -->
        <div v-if="groupAccounts.length" class="assigned-accounts">
          <table class="accounts-table">
            <thead>
              <tr>
                <th>{{ t('admin_mail_transports_priority') }}</th>
                <th>{{ t('admin_mail_transports_columns_name') }}</th>
                <th>{{ t('admin_mail_transports_columns_host') }}</th>
                <th>{{ t('admin_mail_transports_columns_active') }}</th>
                <th>{{ t('admin_mail_transports_actions') }}</th>
              </tr>
            </thead>
            <tbody>
              <tr v-for="ga in groupAccounts" :key="ga.id">
                <td>
                  <input 
                    type="number" 
                    :value="ga.priority" 
                    min="0" 
                    max="999" 
                    class="priority-input"
                    @change="handleUpdatePriority(ga.account_id, parseInt($event.target.value) || 0)"
                  />
                </td>
                <td>{{ ga.account?.name || getAccountName(ga.account_id) }}</td>
                <td>{{ ga.account?.host || '—' }}</td>
                <td>
                  <span :class="['status-badge', ga.account?.is_active ? 'active' : 'inactive']">{{ ga.account?.is_active ? '✓' : '✗' }}</span>
                </td>
                <td>
                  <IconButton 
                    icon="trash" 
                    :label="t('admin_mail_transports_remove_from_group')" 
                    variant="danger"
                    @click="handleRemoveAccountFromGroup(ga.account_id)"
                  />
                </td>
              </tr>
            </tbody>
          </table>
        </div>
        <div v-else class="empty-accounts">
          {{ t('admin_mail_transports_no_accounts_in_group') }}
        </div>
      </div>
    </div>
    
    <!-- Blacklist Tab -->
    <div v-if="selectedTab === 'blacklist'" class="content-section">
      
      <!-- Blacklist Domain Form (Inline) -->
      <div v-if="editingBlacklistDomain || showAddBlacklistDomainForm" class="form-card">
        <h3>{{ editingBlacklistDomain ? t('admin_mail_transports_edit_domain') : t('admin_mail_transports_add_domain') }}</h3>
        
        <div class="form-row">
          <label class="field">
            <span>{{ t('admin_mail_transports_columns_domain') }}</span>
            <input
              v-model="blacklistDomainForm.domain"
              type="text"
              placeholder="example.com"
              @keydown.enter.prevent="saveBlacklistDomain"
            />
          </label>
        </div>
        
        <div class="form-row checkbox-row">
          <label class="field checkbox-field">
            <input
              type="checkbox"
              v-model="blacklistDomainForm.active"
            />
            {{ t('admin_mail_transports_columns_active') }}
          </label>
        </div>
        
        <div class="form-actions">
          <button @click="saveBlacklistDomain" class="btn-primary">{{ editingBlacklistDomain ? t('admin_mail_transports_edit_domain') : t('admin_mail_transports_add_domain') }}</button>
          <button @click="editingBlacklistDomain = null; showAddBlacklistDomainForm = false" class="btn-secondary">{{ t('cancel') }}</button>
        </div>
      </div>
      
      <AdminDataTable
        :columns="blacklistDomainColumns"
        :rows="visibleBlacklistDomains"
        :loading="loading"
        :empty-message="t('admin_mail_transports_no_blacklist_domains')"
        :initial-hidden-columns="['id']"
      >
        <!-- Actions column -->
        <template #row-actions="{ row }">
          <div class="action-buttons">
            <IconButton
              icon="pencil"
              :label="t('admin_mail_transports_edit_domain')"
              variant="ghost"
              @click="openBlacklistDomainForm(row)"
            />
            <IconButton
              icon="trash"
              :label="t('admin_mail_transports_delete_domain')"
              variant="danger"
              @click="deleteBlacklistDomainItem(row.id)"
            />
          </div>
        </template>
      </AdminDataTable>
    </div>
  </div>
</template>

<style scoped>
/* Light/Dark Theme Support */
.stack { display: flex; flex-direction: column; gap: 0.75rem; width: 100%; }
.controls { display: flex; gap: 0.75rem; flex-wrap: wrap; align-items: end; }


.content-section { display: flex; flex-direction: column; gap: 1rem; }

.form-card { 
  background: var(--app-card-bg, var(--surface)); 
  padding: 1.5rem; 
  border-radius: 8px; 
  border: 1px solid var(--border);
}
.form-card h3 { margin-top: 0; font-size: 1.25rem; color: var(--text); }

.action-buttons { display: flex; gap: 0.5rem; }
.action-buttons .ghost { background: var(--surface-muted); color: var(--text-primary); border-color: var(--border); }
.action-buttons .danger { background: var(--danger); color: #fff; }

.message { color: #065f46; background: #ecfdf3; border: 1px solid #a7f3d0; padding: 0.75rem; border-radius: 8px; }
.error { color: #991b1b; background: #fef2f2; border: 1px solid #fecaca; padding: 0.75rem; border-radius: 8px; }

/* Responsive adjustments */
@media (max-width: 640px) {
  .form-card { padding: 1rem; }
}

/* Group Account Manager */
.group-account-manager { margin-top: 1rem; }
.manager-header { display: flex; justify-content: space-between; align-items: center; margin-bottom: 1rem; }
.manager-header h3 { margin: 0; }

.add-account-row { display: flex; gap: 1rem; align-items: flex-end; flex-wrap: wrap; margin-bottom: 1.5rem; padding-bottom: 1rem; border-bottom: 1px solid var(--border); }
.add-account-row .field { display: flex; flex-direction: column; gap: 0.35rem; font-weight: 600; color: var(--text); }
.add-account-row .field select,
.add-account-row .field input { padding: 0.5rem; border: 1px solid var(--border); border-radius: 6px; background: var(--surface); color: var(--text); }
.add-account-row .field select { min-width: 250px; }
.priority-field input { width: 80px; }
.add-btn-field { display: flex; flex-direction: column; justify-content: flex-end; }

.assigned-accounts { overflow-x: auto; }
.accounts-table { width: 100%; border-collapse: collapse; }
.accounts-table th,
.accounts-table td { padding: 0.5rem 0.75rem; text-align: left; border-bottom: 1px solid var(--border); color: var(--text); }
.accounts-table th { font-weight: 600; background: var(--surface-muted); font-size: 0.85rem; }
.accounts-table tbody tr:hover { background: var(--surface-muted); }
.priority-input { width: 70px; padding: 0.35rem; border: 1px solid var(--border); border-radius: 4px; text-align: center; background: var(--surface); color: var(--text); }
.status-badge { font-weight: 700; }
.status-badge.active { color: #059669; }
.status-badge.inactive { color: #dc2626; }
.empty-accounts { padding: 1rem; text-align: center; color: var(--text-muted); font-style: italic; }

/* Blacklist Domain Form */
.form-row { display: flex; gap: 1rem; align-items: flex-end; flex-wrap: wrap; margin-bottom: 1rem; }
.form-row .field { display: flex; flex-direction: column; gap: 0.35rem; font-weight: 600; color: var(--text); }
.form-row .field input,
.form-row .field select { padding: 0.5rem; border: 1px solid var(--border); border-radius: 6px; background: var(--surface); color: var(--text); }
.form-row .field input { min-width: 250px; }

.checkbox-row { flex-direction: row; align-items: center; }
.checkbox-field { display: flex; align-items: center; gap: 0.5rem; cursor: pointer; font-weight: normal; }
.checkbox-field input { width: auto; margin: 0; }

.form-actions { display: flex; gap: 1rem; justify-content: flex-end; margin-top: 1rem; padding-top: 1rem; border-top: 1px solid var(--border); }
.btn-primary, .btn-secondary { padding: 0.5rem 1rem; border-radius: 6px; cursor: pointer; font-weight: 600; }
.btn-primary { background: var(--primary); color: #fff; border: none; }
.btn-secondary { background: var(--surface-muted); color: var(--text); border: 1px solid var(--border); }
/* Add styles for new tab row layout */
.tabs-row {
  display: flex;
  justify-content: space-between;
  align-items: flex-start;
  gap: 1rem;
  margin-bottom: 0.5rem;
}
.tab-add-btn {
  display: flex;
  align-items: center;
}
</style>
