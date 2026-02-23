<script setup>
import { ref, reactive, computed, onMounted, onUnmounted, watch } from 'vue'
import IconButton from './IconButton.vue'
import SecretField from './SecretField.vue'
import AdminDataTable from './AdminDataTable.vue'
import MailAccountForm from './MailAccountForm.vue'
import MailGroupForm from './MailGroupForm.vue'
import { getMailAccounts, createMailAccount, updateMailAccount, deleteMailAccount, testMailAccount, getMailGroups, createMailGroup, updateMailGroup, deleteMailGroup, testMailGroup } from '../utils/adminApi'
import { useTranslation } from '../composables/useTranslation'

const props = defineProps({ langCode: { type: String, default: 'de' } })
const { tr } = useTranslation()

const apiKey = ref(localStorage.getItem('admin_auth_session') || sessionStorage.getItem('admin_auth_session') || '')
const routePrefix = ref(localStorage.getItem('admin_route_prefix') || 'admin')
const currentUser = ref(JSON.parse(localStorage.getItem('admin_user') || sessionStorage.getItem('admin_user') || 'null'))

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

// Tabs
const tabs = [
  { id: 'accounts', labelKey: 'admin_mail_transports_tab_accounts', fallback: 'Mail Accounts' },
  { id: 'groups', labelKey: 'admin_mail_transports_tab_groups', fallback: 'Transport Groups' },
]

const selectedTab = ref('accounts')

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
  { key: 'rateLimitEnabled', label: tr('admin_mail_transports_columns_rate_limit'), sortable: true, type: 'boolean' },
  { key: 'isActive', label: tr('admin_mail_transports_columns_active'), sortable: true, type: 'boolean' },
])

// Group table columns
const groupColumns = computed(() => [
  { key: 'id', label: tr('admin_mail_transports_columns_id'), sortable: true },
  { key: 'name', label: tr('admin_mail_transports_columns_name'), sortable: true },
  { key: 'description', label: tr('admin_mail_transports_columns_description'), sortable: false },
  { key: 'failoverStrategy', label: tr('admin_mail_transports_columns_failover_strategy'), sortable: true },
  { key: 'rateLimitEnabled', label: tr('admin_mail_transports_columns_rate_limit'), sortable: true, type: 'boolean' },
  { key: 'accountCount', label: tr('admin_mail_transports_columns_accounts'), sortable: true, type: 'number' },
  { key: 'isActive', label: tr('admin_mail_transports_columns_active'), sortable: true, type: 'boolean' },
])

// Computed properties
const visibleAccounts = computed(() => {
  if (selectedTab.value === 'accounts') return accounts.value
  return []
})

const visibleGroups = computed(() => {
  if (selectedTab.value === 'groups') return groups.value
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
    admin_mail_transports_columns_rate_limit: tr('admin_mail_transports_columns_rate_limit', 'Rate Limiting'),
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
    accounts.value = await getMailAccounts()
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
    groups.value = await getMailGroups()
  } catch (e) {
    setError(t('admin_mail_transports_error_loading_groups') + ': ' + e)
  } finally {
    loading.value = false
  }
}

async function loadAll() {
  if (!apiKey.value) { setError(tr('please_login_api_key_missing')); return }
  await Promise.all([loadAccounts(), loadGroups()])
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
    accountForm.isActive = true
  }
}

async function saveAccount() {
  if (!apiKey.value) { setError(tr('api_key_missing')); return }
  
  loading.value = true
  try {
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
      is_active: accountForm.isActive ? 1 : 0,
    }
    
    if (editingAccount.value) {
      await updateMailAccount(editingAccount.value.id, accountData)
      setMessage(t('admin_mail_transports_account_saved'))
    } else {
      await createMailAccount(accountData)
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
  accountForm.isActive = true
}

async function deleteAccount(id) {
  if (!apiKey.value) { setError(tr('api_key_missing')); return }
  
  if (!confirm(t('admin_mail_transports_confirm_delete_account'))) return
  
  loading.value = true
  try {
    await deleteMailAccount(id)
    setMessage(t('admin_mail_transports_account_deleted'))
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
    const result = await testMailAccount(id)
    setMessage(t('admin_mail_transports_test_success') + ' ' + (result.message || ''))
  } catch (e) {
    setError(t('admin_mail_transports_test_failed') + e.message)
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

async function saveGroup() {
  if (!apiKey.value) { setError(tr('api_key_missing')); return }
  
  loading.value = true
  try {
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
      await updateMailGroup(editingGroup.value.id, groupData)
      setMessage(t('admin_mail_transports_group_saved'))
    } else {
      await createMailGroup(groupData)
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
    await deleteMailGroup(id)
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
    const result = await testMailGroup(id)
    setMessage(t('admin_mail_transports_test_success') + ' ' + (result.message || ''))
  } catch (e) {
    setError(t('admin_mail_transports_test_failed') + e.message)
  } finally {
    loading.value = false
  }
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
    
    <!-- Tabs -->
    <div class="tabs">
      <button 
        v-for="tab in tabs" 
        :key="tab.id" 
        :class="['tab', { active: selectedTab === tab.id }]" 
        @click="selectedTab = tab.id"
      >
        {{ t(`admin_mail_transports_tab_${tab.id}`) }}
      </button>
    </div>
    
    <!-- Accounts Tab -->
    <div v-if="selectedTab === 'accounts'" class="content-section">
      <div class="controls" style="margin-bottom: 1rem;">
        <IconButton icon="plus" :label="t('admin_mail_transports_add_account')" @click="openAccountForm()" />
      </div>
      
      <!-- Account Form (Inline) -->
      <div v-if="editingAccount || showAddAccountForm" class="form-card">
        <h3>{{ editingAccount ? t('admin_mail_transports_edit_account') : t('admin_mail_transports_add_account') }}</h3>
        
        <MailAccountForm 
          v-model="accountForm"
          :is-editing="!!editingAccount"
          @save="saveAccount"
          @cancel="cancelAccountForm"
        />
      </div>
      
      <AdminDataTable 
        :columns="accountColumns"
        :data="visibleAccounts"
        :loading="loading"
        :empty-message="t('admin_mail_transports_no_accounts')"
        @row-action="handleAccountRowAction"
      >
        <!-- Actions column -->
        <template #actions="{ row }">
          <div class="action-buttons">
            <IconButton 
              icon="test" 
              :label="t('admin_mail_transports_test_connection')" 
              variant="ghost"
              @click="testAccountConnection(row.id)"
            />
            <IconButton 
              icon="edit" 
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
      <div class="controls" style="margin-bottom: 1rem;">
        <IconButton icon="plus" :label="t('admin_mail_transports_add_group')" @click="openGroupForm()" />
      </div>
      
      <!-- Group Form (Inline) -->
      <div v-if="editingGroup || showAddGroupForm" class="form-card">
        <h3>{{ editingGroup ? t('admin_mail_transports_edit_group') : t('admin_mail_transports_add_group') }}</h3>
        
        <MailGroupForm 
          v-model="groupForm"
          :is-editing="!!editingGroup"
          @save="saveGroup"
          @cancel="cancelGroupForm"
        />
      </div>
      
      <AdminDataTable 
        :columns="groupColumns"
        :data="visibleGroups"
        :loading="loading"
        :empty-message="t('admin_mail_transports_no_groups')"
        @row-action="handleGroupRowAction"
      >
        <!-- Actions column -->
        <template #actions="{ row }">
          <div class="action-buttons">
            <IconButton 
              icon="test" 
              :label="t('admin_mail_transports_test_connection')" 
              variant="ghost"
              @click="testGroupConnection(row.id)"
            />
            <IconButton 
              icon="edit" 
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
    </div>
  </div>
</template>

<style scoped>
/* Light/Dark Theme Support */
.stack { display: flex; flex-direction: column; gap: 0.75rem; width: 100%; }
.controls { display: flex; gap: 0.75rem; flex-wrap: wrap; align-items: end; }

.tabs { display: flex; gap: 0.5rem; flex-wrap: wrap; }
.tab { padding: 0.5rem 0.75rem; border: 1px solid var(--border); background: var(--surface-muted); border-radius: 8px; cursor: pointer; color: var(--text); }
.tab.active { background: var(--primary); color: #fff; border-color: var(--primary-dark); }

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
</style>
