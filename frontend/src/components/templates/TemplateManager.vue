<script setup>
import { ref, computed, onMounted } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import IconButton from '../IconButton.vue'
import TemplateTabs from './TemplateTabs.vue'
import EmailBroadcastManager from './EmailBroadcastManager.vue'
import EmailTemplateList from './EmailTemplateList.vue'
import IcalTemplateList from './IcalTemplateList.vue'
import AttachmentTemplateList from './AttachmentTemplateList.vue'
import { adminFetch } from '../../utils/adminApi'
import { useTranslation } from '../../composables/useTranslation'

const route = useRoute()
const router = useRouter()
const { tr } = useTranslation()

// Shared state for all template components
const transportGroups = ref([])
const transportAccounts = ref([])
const placeholders = ref([])
const surveys = ref([])
const loading = ref(false)
const message = ref('')
const error = ref('')

const apiKey = ref(localStorage.getItem('admin_auth_session') || sessionStorage.getItem('admin_auth_session') || '')
const routePrefix = ref(localStorage.getItem('admin_route_prefix') || 'admin')
const currentUser = ref(JSON.parse(localStorage.getItem('admin_user') || sessionStorage.getItem('admin_user') || 'null'))

// Determine active tab from route
const activeTab = computed(() => {
  const path = route.path
  if (path.includes('/email/send')) return 'send'
  if (path.includes('/ical/list')) return 'ical'
  if (path.includes('/attachment/list')) return 'attachments'
  return 'email' // Default to email template list
})

function setMessage(msg) { message.value = msg; error.value = '' }
function setError(msg) { error.value = msg; message.value = '' }

const fetchWithAuth = (relative, opts = {}) => adminFetch(relative, opts, { apiKeyRef: apiKey, routePrefixRef: routePrefix })

async function loadSurveys() {
  try {
    const res = await fetchWithAuth('surveys')
    if (!res.ok) throw new Error(await res.text())
    const data = await res.json()
    surveys.value = Array.isArray(data) ? data : []
  } catch (e) {
    surveys.value = []
  }
}

async function loadPlaceholders() {
  const res = await fetchWithAuth('placeholders')
  if (!res.ok) throw new Error(await res.text())
  placeholders.value = await res.json()
}

async function loadTransportOptions() {
  try {
    const res = await fetchWithAuth('mail-transport-options')
    if (res.ok) {
      const data = await res.json()
      transportGroups.value = data.groups || []
      transportAccounts.value = data.accounts || []
    }
  } catch (e) {
    console.warn('Failed to load transport options:', e)
  }
}

// Transport group options for combobox
const transportGroupOptions = computed(() => {
  const options = []
  transportGroups.value.forEach(group => {
    options.push({
      value: `group_${group.id}`,
      label: `${group.name} (${tr('admin_email_broadcast_transport_group')})`,
      type: 'group',
      id: group.id,
      failoverStrategy: group.failover_strategy,
      rateLimitEnabled: group.rate_limit_enabled,
      rateLimitPerMinute: group.rate_limit_per_minute,
      rateLimitPerHour: group.rate_limit_per_hour,
    })
  })
  transportAccounts.value.forEach(account => {
    options.push({
      value: `account_${account.id}`,
      label: `${account.name} (${tr('admin_email_broadcast_transport_account')})`,
      type: 'account',
      id: account.id,
      rateLimitEnabled: account.rate_limit_enabled,
      rateLimitPerMinute: account.rate_limit_per_minute,
      rateLimitPerHour: account.rate_limit_per_hour,
    })
  })
  return options
})

// User role computed property
const userRole = computed(() => currentUser.value?.role?.toLowerCase?.() || '')

// Check if user is admin or superadmin (can edit transport group selection)
const isAdminOrSuperAdmin = computed(() =>
  routePrefix.value === 'admin' || userRole.value === 'admin' || userRole.value === 'superadmin'
)

// Can manage templates
const canManageTemplates = computed(() =>
  routePrefix.value === 'admin' || userRole.value === 'admin' || userRole.value === 'superadmin'
)

onMounted(() => {
  loadTransportOptions()
  loadPlaceholders()
  loadSurveys()
})
</script>

<template>
  <div class="stack">
    <TemplateTabs v-model:activeTab="activeTab" />
    
    <div v-if="message" class="message">{{ message }}</div>
    <div v-if="error" class="error">{{ error }}</div>
    
    <!-- Email Broadcast Tab (Send emails) -->
    <EmailBroadcastManager
      v-if="activeTab === 'send'"
      :transport-group-options="transportGroupOptions"
      :is-admin-or-super-admin="isAdminOrSuperAdmin"
      :can-manage-templates="canManageTemplates"
      :surveys="surveys"
      @message="setMessage"
      @error="setError"
    />
    
    <!-- Email Template List Tab -->
    <EmailTemplateList
      v-else-if="activeTab === 'email'"
      :transport-group-options="transportGroupOptions"
      :is-admin-or-super-admin="isAdminOrSuperAdmin"
      :can-manage-templates="canManageTemplates"
      :placeholders="placeholders"
      @message="setMessage"
      @error="setError"
    />
    
    <!-- iCal Template Tab -->
    <IcalTemplateList
      v-else-if="activeTab === 'ical'"
      @message="setMessage"
      @error="setError"
    />
    
    <!-- Attachment Template Tab -->
    <AttachmentTemplateList
      v-else-if="activeTab === 'attachments'"
      :placeholders="placeholders"
      @message="setMessage"
      @error="setError"
    />
  </div>
</template>

<style scoped>
.stack { display: flex; flex-direction: column; gap: 0.75rem; }
.message { color: var(--success-text); background: var(--success-bg); border: 1px solid var(--success-border); padding: 0.5rem; border-radius: 6px; }
.error { color: var(--error-text); background: var(--error-bg); border: 1px solid var(--error-border); padding: 0.5rem; border-radius: 6px; }
</style>
