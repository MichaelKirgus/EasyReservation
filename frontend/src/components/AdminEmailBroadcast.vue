<script setup>
import { ref, reactive, computed, onMounted, onUnmounted, watch } from 'vue'
import IconButton from './IconButton.vue'
import AdminDataTable from './AdminDataTable.vue'
import EmailTemplatePreview from './EmailTemplatePreview.vue'
import { adminFetch } from '../utils/adminApi'
import { useTranslation } from '../composables/useTranslation'

const props = defineProps({ defaultSubTab: { type: String, default: 'send' } })
const { tr } = useTranslation()
const apiKey = ref(localStorage.getItem('admin_auth_session') || sessionStorage.getItem('admin_auth_session') || '')
const routePrefix = ref(localStorage.getItem('admin_route_prefix') || 'admin')
const currentUser = ref(JSON.parse(localStorage.getItem('admin_user') || sessionStorage.getItem('admin_user') || 'null'))

const templates = ref([])
const reservations = ref([])
const waitlist = ref([])
const placeholders = ref([])
const surveys = ref([]) // List of surveys for dropdown
const loading = ref(false)
const message = ref('')
const error = ref('')

const activeSubTab = ref(props.defaultSubTab === 'templates' ? 'templates' : 'send')

// Transport group options (accounts and groups)
const transportGroups = ref([])
const transportAccounts = ref([])

const form = reactive({
  templateId: null,
  surveyId: null, // Optional survey selection
  mode: 'both',
  deduplicate: true,
  userRoles: [], // New: for internal user roles
  selectedReservations: [],
  selectedWaitlist: [],
  customRecipients: [{ name: '', email: '' }],
  transportGroupId: null, // Transport group/account ID for failover/rate limiting
})
// Load surveys for dropdown
async function loadSurveys() {
  try {
    const res = await fetchWithAuth('surveys')
    if (!res.ok) throw new Error(await res.text())
    const data = await res.json()
    surveys.value = Array.isArray(data) ? data : []
  } catch (e) {
    surveys.value = []
    // Optionally log error
  }
}

const templateForm = reactive({ name: '', subject: '', body: '', cc: '', bcc: '', type: 'generic', transportGroupId: null })

// User role computed property
const userRole = computed(() => currentUser.value?.role?.toLowerCase?.() || '')

// Check if user is admin or superadmin (can edit transport group selection)
const isAdminOrSuperAdmin = computed(() =>
  routePrefix.value === 'admin' || userRole.value === 'admin' || userRole.value === 'superadmin'
)

// Can manage templates - same logic as before
const canManageTemplates = computed(() =>
  routePrefix.value === 'admin' || userRole.value === 'admin' || userRole.value === 'superadmin'
)

// Transport group options for combobox
const transportGroupOptions = computed(() => {
  const options = []
  // Add groups first
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
  // Add standalone accounts (not in any group)
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

// Get current transport group/account info for display
const currentTransportInfo = computed(() => {
  if (!form.transportGroupId) return null
  
  const type = form.transportGroupId.toString().startsWith('group_') ? 'group' : 'account'
  const id = parseInt(form.transportGroupId.toString().replace('group_', '').replace('account_', ''))
  
  if (type === 'group') {
    const group = transportGroups.value.find(g => g.id === id)
    return group ? {
      name: group.name,
      strategy: group.failover_strategy,
      rateLimitEnabled: group.rate_limit_enabled,
      rateLimitPerMinute: group.rate_limit_per_minute,
      rateLimitPerHour: group.rate_limit_per_hour,
    } : null
  } else {
    const account = transportAccounts.value.find(a => a.id === id)
    return account ? {
      name: account.name,
      strategy: 'single_account',
      rateLimitEnabled: account.rate_limit_enabled,
      rateLimitPerMinute: account.rate_limit_per_minute,
      rateLimitPerHour: account.rate_limit_per_hour,
    } : null
  }
})

const templateColumns = computed(() => [
  { key: 'id', label: tr('admin_email_broadcast_columns_id'), sortable: true },
  { key: 'name', label: tr('admin_email_broadcast_columns_name'), sortable: true },
  { key: 'transportGroupId', label: tr('admin_email_broadcast_transport_group_label'), sortable: false },
  { key: 'subject', label: tr('admin_email_broadcast_columns_subject'), sortable: true },
  { key: 'cc', label: tr('admin_email_broadcast_columns_cc'), sortable: false },
  { key: 'bcc', label: tr('admin_email_broadcast_columns_bcc'), sortable: false },
  { key: 'body', label: tr('admin_email_broadcast_columns_body'), sortable: false }
])

const reservationsWithEmail = computed(() => reservations.value.filter(r => !!r.email))
const waitlistWithEmail = computed(() => waitlist.value.filter(w => !!w.email))
const placeholderText = computed(() => placeholders.value.length ? `${tr('admin_email_broadcast_columns_placeholder')}: ${placeholders.value.join(', ')}` : '')

// Preview state
const previewTemplate = ref(null)
const showPreviewDialog = ref(false)

function openPreview(template) {
  previewTemplate.value = template
  showPreviewDialog.value = true
}

function closePreview() {
  showPreviewDialog.value = false
  previewTemplate.value = null
}

function setMessage(msg) { message.value = msg; error.value = '' }
function setError(msg) { error.value = msg; message.value = '' }

const fetchWithAuth = (relative, opts = {}) => adminFetch(relative, opts, { apiKeyRef: apiKey, routePrefixRef: routePrefix })

async function loadTemplates() {
  const res = await fetchWithAuth('email-templates')
  if (!res.ok) throw new Error(await res.text())
  const loadedTemplates = await res.json()
  // Map transport_group_id and transport_type to string value for combobox
  loadedTemplates.forEach(tpl => {
    if (tpl.transport_group_id && tpl.transport_type) {
      tpl.transportGroupId = `${tpl.transport_type}_${tpl.transport_group_id}`;
    } else {
      tpl.transportGroupId = '';
    }
  });
  templates.value = loadedTemplates;
}

async function loadRecipients() {
  const [resReservations, resWaitlist] = await Promise.all([
    fetchWithAuth('reservations'),
    fetchWithAuth('waitlist'),
  ])

  if (!resReservations.ok) throw new Error(await resReservations.text())
  if (!resWaitlist.ok) throw new Error(await resWaitlist.text())

  reservations.value = await resReservations.json()
  waitlist.value = await resWaitlist.json()
}

async function loadPlaceholders() {
  const res = await fetchWithAuth('placeholders')
  if (!res.ok) throw new Error(await res.text())
  placeholders.value = await res.json()
}

async function loadTransportOptions() {
  try {
    // Use moderator endpoint for transport options (read-only access for moderators)
    const res = await fetchWithAuth('mail-transport-options')
    if (res.ok) {
      const data = await res.json()
      transportGroups.value = data.groups || []
      transportAccounts.value = data.accounts || []
    }
  } catch (e) {
    // Silently fail - transport options are optional
    console.warn('Failed to load transport options:', e)
  }
}

async function loadAll() {
  if (!apiKey.value) { setError(tr('please_login_api_key_missing')); return }
  loading.value = true
  try {
    // Load transport options first, then templates, then others
    await loadTransportOptions();
    await loadTemplates();
    await Promise.all([loadRecipients(), loadPlaceholders(), loadSurveys()]);
    setMessage(tr('data_loaded'))
  } catch (e) {
    setError(tr('error_loading') + ': ' + e)
  } finally {
    loading.value = false
  }
}

function addCustomRecipient() {
  form.customRecipients.push({ name: '', email: '' })
}

function removeCustomRecipient(idx) {
  form.customRecipients.splice(idx, 1)
  if (!form.customRecipients.length) {
    form.customRecipients.push({ name: '', email: '' })
  }
}

function handleKeyUpdate(e) {
  apiKey.value = e.detail || ''
}

async function sendBroadcast() {
  if (!apiKey.value) { setError(tr('please_login_api_key_missing')); return }
  if (!form.templateId) { setError(tr('please_select_template')); return }

  const payload = {
    template_id: form.templateId,
    scope: form.mode,
    send_to_all: form.mode !== 'selection',
    deduplicate: form.deduplicate,
  }

  // Add surveyId if selected
  if (form.surveyId) {
    payload.survey_id = form.surveyId
  }

  // Add transport group ID to payload if selected
  if (form.transportGroupId) {
    payload.transport_group_id = parseInt(form.transportGroupId.toString().replace('group_', '').replace('account_', ''))
  }

  // Add user roles if selected
  if (form.userRoles && form.userRoles.length > 0) {
    payload.user_roles = form.userRoles
  }

  if (form.mode === 'selection') {
    payload.send_to_all = false
    payload.reservation_ids = form.selectedReservations.filter(Boolean)
    payload.waitlist_ids = form.selectedWaitlist.filter(Boolean)
    if ((payload.reservation_ids?.length || 0) === 0 && (payload.waitlist_ids?.length || 0) === 0 && customList().length === 0 && !form.userRoles.length) {
      setError(tr('please_select_at_least_one_recipient'))
      return
    }
  } else if (form.mode === 'internal_users') {
    // For internal users, at least one role must be selected
    if (!form.userRoles || form.userRoles.length === 0) {
      setError(tr('please_select_at_least_one_role'))
      return
    }
  }

  const customs = customList()
  if (customs.length) {
    payload.custom_recipients = customs
  }

  loading.value = true
  try {
    const res = await fetchWithAuth('email-broadcast', { method: 'POST', body: JSON.stringify(payload) })
    const text = await res.text()
    if (!res.ok) throw new Error(text)
    const data = JSON.parse(text)
    setMessage(data.message || tr('emails_sending'))
  } catch (e) {
    setError(tr('sending_failed') + ': ' + e)
  } finally {
    loading.value = false
  }
}

async function saveTemplate(tpl) {
  if (!canManageTemplates.value) { setError(tr('templates_can_only_be_edited_by_admin')); return }
  try {
    // Parse transport group/account id to integer for backend
    let transportGroupId = tpl.transportGroupId;
    let transportType = '';
    if (typeof transportGroupId === 'string') {
      if (transportGroupId.startsWith('group_')) {
        transportType = 'group';
      } else if (transportGroupId.startsWith('account_')) {
        transportType = 'account';
      }
      transportGroupId = parseInt(transportGroupId.replace('group_', '').replace('account_', '')) || null;
    }
    const res = await fetchWithAuth(`email-templates/${tpl.id}`, {
      method: 'PATCH',
      body: JSON.stringify({
        name: tpl.name,
        subject: tpl.subject,
        body: tpl.body,
        cc: tpl.cc || '',
        bcc: tpl.bcc || '',
        type: tpl.type || 'generic',
        transport_group_id: transportGroupId,
        transport_type: transportType,
      }),
    })
    const text = await res.text()
    if (!res.ok) throw new Error(text)
    setMessage(tr('template_saved'))
  } catch (e) {
    setError(tr('saving_failed') + ': ' + e)
  }
}

async function deleteTemplate(id) {
  if (!canManageTemplates.value) { setError(tr('templates_can_only_be_deleted_by_admin')); return }
  if (!confirm(tr('really_delete_template'))) return
  try {
    const res = await fetchWithAuth(`email-templates/${id}`, { method: 'DELETE' })
    const text = await res.text()
    if (!res.ok) throw new Error(text)
    templates.value = templates.value.filter(t => t.id !== id)
    setMessage(tr('template_deleted'))
  } catch (e) {
    setError(tr('deletion_failed') + ': ' + e)
  }
}

async function createTemplate() {
  if (!canManageTemplates.value) { setError(tr('templates_can_only_be_created_by_admin')); return }
  try {
    const res = await fetchWithAuth('email-templates', {
      method: 'POST',
      body: JSON.stringify({
        ...templateForm,
        transport_group_id: typeof templateForm.transportGroupId === 'string' ? parseInt(templateForm.transportGroupId.replace('group_', '').replace('account_', '')) : templateForm.transportGroupId || null,
        transport_type: typeof templateForm.transportGroupId === 'string' ? (templateForm.transportGroupId.startsWith('group_') ? 'group' : 'account') : '',
      }),
    })
    const text = await res.text()
    if (!res.ok) throw new Error(text)
    setMessage(tr('template_created'))
    templateForm.name = ''
    templateForm.subject = ''
    templateForm.body = ''
    templateForm.cc = ''
    templateForm.bcc = ''
    templateForm.type = 'generic'
    templateForm.transportGroupId = null
    await loadTemplates()
  } catch (e) {
    setError(tr('creating_failed') + ': ' + e)
  }
}

function customList() {
  return form.customRecipients
    .map(r => ({ name: r.name?.trim() || '', email: r.email?.trim() || '' }))
    .filter(r => r.email)
}

onMounted(() => {
  window.addEventListener('api-key-updated', handleKeyUpdate)
  
  // Set route prefix based on user role
  const storedRoutePrefix = localStorage.getItem('admin_route_prefix') || sessionStorage.getItem('admin_route_prefix')
  if (storedRoutePrefix) {
    routePrefix.value = storedRoutePrefix
  } else if (userRole.value === 'moderator') {
    routePrefix.value = 'moderator'
    localStorage.setItem('admin_route_prefix', 'moderator')
  } else {
    routePrefix.value = 'admin'
    localStorage.setItem('admin_route_prefix', 'admin')
  }
  
  // Ensure elevated users use the admin routes so template write actions are allowed
  if ((userRole.value === 'admin' || userRole.value === 'superadmin') && routePrefix.value !== 'admin') {
    routePrefix.value = 'admin'
    localStorage.setItem('admin_route_prefix', 'admin')
  }
  
  if (apiKey.value) {
    loadAll()
  }
})

onUnmounted(() => {
  window.removeEventListener('api-key-updated', handleKeyUpdate)

})
watch(() => props.defaultSubTab, (val) => {

  if (val === 'templates' || val === 'send') {
    activeSubTab.value = val
  }
})
</script>

<template>
  <div class="stack">
    <div class="top-bar">
      <div class="left-actions">
        <IconButton icon="refresh" :label="tr('admin_email_broadcast_refresh_label')" @click="loadAll" :disabled="loading" />
      </div>
    </div>

    <div class="subtabs">
      <button :class="['subtab', { active: activeSubTab === 'send' }]" @click="activeSubTab = 'send'">{{ tr('admin_email_broadcast_send_tab') }}</button>
      <button :class="['subtab', { active: activeSubTab === 'templates' }]" @click="activeSubTab = 'templates'">{{ tr('admin_email_broadcast_templates_tab') }}</button>
    </div>

    <div v-if="message" class="message">{{ message }}</div>
    <div v-if="error" class="error">{{ error }}</div>

    <template v-if="activeSubTab === 'send'">
      <div class="card">
        <div class="card-header">
          <h3>{{ tr('admin_email_broadcast_send_tab') }}</h3>
        </div>
        <div class="grid">
          <!-- Template selection -->
          <label>
            {{ tr('admin_email_broadcast_columns_name') }}
            <select v-model.number="form.templateId">
              <option value="" disabled>{{ tr('admin_email_broadcast_select_template_placeholder') }}</option>
              <option v-for="tpl in templates" :key="tpl.id" :value="tpl.id">
                {{ tpl.name }} – {{ tpl.subject }}
              </option>
            </select>
          </label>

          <!-- Survey selection (optional) -->
          <label>
            {{ tr('admin_email_broadcast_select_survey_label') }}
            <select v-model.number="form.surveyId">
              <option value="">{{ tr('admin_email_broadcast_select_survey_placeholder') }}</option>
              <option v-for="survey in surveys" :key="survey.id" :value="survey.id">
                {{ survey.title }}
              </option>
            </select>
          </label>

          <!-- Transport group combobox - visible to all, editable only for admins -->
          <label>
            {{ tr('admin_email_broadcast_transport_group_label') }}
            <select
              v-model="form.transportGroupId"
              :disabled="!isAdminOrSuperAdmin"
              :title="isAdminOrSuperAdmin ? '' : tr('admin_email_broadcast_transport_group_moderator_hint')"
            >
              <option value="" disabled>{{ tr('admin_email_broadcast_select_transport_placeholder') }}</option>
              <template v-for="opt in transportGroupOptions" :key="opt.value">
                <option :value="opt.value">{{ opt.label }}</option>
              </template>
            </select>
          </label>

          <!-- Transport info display (read-only) -->
          <div v-if="currentTransportInfo && !isAdminOrSuperAdmin" class="transport-info-display">
            <span>{{ tr('admin_email_broadcast_transport_info') }}:</span>
            <strong>{{ currentTransportInfo.name }}</strong>
            <span v-if="currentTransportInfo.strategy === 'round_robin'"> ({{ tr('admin_email_broadcast_strategy_round_robin') }})</span>
            <span v-else-if="currentTransportInfo.strategy !== 'single_account'"> ({{ tr('admin_email_broadcast_strategy_failover') }})</span>
          </div>

          <!-- Rate limit info for admins -->
          <div v-if="isAdminOrSuperAdmin && currentTransportInfo" class="rate-limit-info">
            <template v-if="currentTransportInfo.rateLimitEnabled">
              <span>{{ tr('admin_email_broadcast_rate_limit_per_minute') }}: {{ currentTransportInfo.rateLimitPerMinute }}</span>
              <span v-if="currentTransportInfo.rateLimitPerHour">{{ tr('admin_email_broadcast_rate_limit_per_hour') }}: {{ currentTransportInfo.rateLimitPerHour }}</span>
            </template>
            <template v-else>
              <em>{{ tr('admin_email_broadcast_rate_limit_disabled') }}</em>
            </template>
          </div>

          <label class="inline">
            <input type="checkbox" v-model="form.deduplicate" /> {{ tr('admin_email_broadcast_deduplicate_label') }}
          </label>
        </div>
        <div class="modes">
          <label v-for="mode in [
            { value: 'both', label: tr('admin_email_broadcast_mode_labels_both') },
            { value: 'reservations', label: tr('admin_email_broadcast_mode_labels_reservations') },
            { value: 'waitlist', label: tr('admin_email_broadcast_mode_labels_waitlist') },
            { value: 'internal_users', label: tr('admin_email_broadcast_mode_labels_internal_users') },
            { value: 'selection', label: tr('admin_email_broadcast_mode_labels_selection') },
          ]" :key="mode.value" class="mode-option">
            <input type="radio" :value="mode.value" v-model="form.mode" />
            <span>{{ mode.label }}</span>
          </label>
        </div>
      </div>

      <div v-if="form.mode === 'selection'" class="card">
        <div class="card-header">
          <h4>{{ tr('admin_email_broadcast_recipients_title') }}</h4>
        </div>
        <div class="two-col">
          <div>
            <h5>{{ tr('admin_email_broadcast_reservations_with_email') }}</h5>
            <div v-if="reservationsWithEmail.length" class="list">
              <label v-for="r in reservationsWithEmail" :key="r.id" class="row">
                <input type="checkbox" :value="r.id" v-model="form.selectedReservations" />
                <span>{{ r.display_name }} <{{ r.email }}></span>
              </label>
            </div>
            <p v-else>{{ tr('admin_email_broadcast_no_matching_reservations') }}</p>
          </div>
          <div>
            <h5>{{ tr('admin_email_broadcast_waitlist_with_email') }}</h5>
            <div v-if="waitlistWithEmail.length" class="list">
              <label v-for="w in waitlistWithEmail" :key="w.id" class="row">
                <input type="checkbox" :value="w.id" v-model="form.selectedWaitlist" />
                <span>{{ w.display_name }} <{{ w.email }}></span>
              </label>
            </div>
            <p v-else>{{ tr('admin_email_broadcast_no_matching_waitlist_entries') }}</p>
          </div>
        </div>
      </div>

      <div v-if="form.mode === 'internal_users'" class="card">
        <div class="card-header">
          <h4>{{ tr('admin_email_broadcast_internal_roles_title') }}</h4>
        </div>
        <div class="modes">
          <label v-for="role in [
            { value: 'admin', label: tr('admin_email_broadcast_role_admins_superadmins_admins') },
            { value: 'moderator', label: tr('admin_email_broadcast_role_moderator') },
            { value: 'user', label: tr('admin_email_broadcast_role_registered_users') },
          ]" :key="role.value" class="mode-option">
            <input type="checkbox" :value="role.value" v-model="form.userRoles" />
            <span>{{ role.label }}</span>
          </label>
        </div>
      </div>

      <div class="card">
        <div class="card-header">
          <h4>{{ tr('admin_email_broadcast_additional_recipients_title') }}</h4>
          <IconButton class="ghost" variant="success" type="button" icon="plus" :label="tr('admin_email_broadcast_add_recipient_button')" @click="addCustomRecipient" />
        </div>
        <div class="inline-fields">
          <div v-for="(r, idx) in form.customRecipients" :key="idx" class="inline-row">
            <input v-model="r.name" :placeholder="tr('admin_email_broadcast_name_placeholder')" />
            <input v-model="r.email" :placeholder="tr('admin_email_broadcast_email_placeholder')" />
            <IconButton class="ghost" variant="danger" type="button" icon="trash" :label="tr('admin_email_broadcast_remove_recipient_button')" @click="removeCustomRecipient(idx)" />
          </div>
        </div>
      </div>

      <div class="actions">
        <IconButton icon="send" :label="tr('admin_email_broadcast_send_button')" @click="sendBroadcast" :disabled="loading || !form.templateId" />
      </div>

      <div v-if="stats" class="card">
        <h4>{{ tr('admin_email_broadcast_result_title') }}</h4>
        <ul class="stats">
          <li>{{ tr('admin_email_broadcast_stats_template') }}</li>
          <li>{{ tr('admin_email_broadcast_stats_queued') }}</li>
          <li>{{ tr('admin_email_broadcast_stats_skipped_no_email') }}</li>
          <li>{{ tr('admin_email_broadcast_stats_duplicates_removed') }}</li>
          <li>{{ tr('admin_email_broadcast_stats_candidates') }}</li>
        </ul>
      </div>
    </template>

    <template v-else>
      <div class="card">
        <div class="card-header">
          <h3>{{ tr('admin_email_broadcast_templates_tab') }}</h3>
          <details v-if="placeholders.length" class="placeholder-info">
            <summary>{{ tr('admin_email_broadcast_placeholder_info_title') }}</summary>
            <div class="placeholder-list">
              <code v-for="token in placeholders" :key="token">{{ token }}</code>
            </div>
          </details>
        </div>

        <AdminDataTable
          :columns="templateColumns"
          :rows="templates"
          row-key="id"
          :loading="loading"
          enable-search
          :page-size="10"
          persist-key="admin-email-templates"
          :empty-text="tr('admin_email_broadcast_empty_templates_text')"
          :initial-hidden-columns="['cc', 'bcc']"
          @refresh="loadTemplates"
        >
          <template #cell-name="{ row }">
            <input v-model="row.name" :disabled="!canManageTemplates" @change="saveTemplate(row)" />
          </template>
          <template #cell-subject="{ row }">
            <div class="with-placeholder-icon">
              <input v-model="row.subject" :disabled="!canManageTemplates" @change="saveTemplate(row)" :title="placeholderText || tr('admin_email_broadcast_columns_placeholder')" />
              <span class="placeholder-indicator" :title="placeholderText || tr('admin_email_broadcast_columns_placeholder')" aria-hidden="true">⧉</span>
            </div>
          </template>
          <template #cell-cc="{ row }">
            <input v-model="row.cc" :disabled="!canManageTemplates" @change="saveTemplate(row)" placeholder="kommagetrennt" />
          </template>
          <template #cell-bcc="{ row }">
            <input v-model="row.bcc" :disabled="!canManageTemplates" @change="saveTemplate(row)" placeholder="kommagetrennt" />
          </template>
          <template #cell-body="{ row }">
            <div class="body-row">
              <div class="with-placeholder-icon">
                <textarea v-model="row.body" rows="6" class="body-input" :disabled="!canManageTemplates" @change="saveTemplate(row)" :title="placeholderText || tr('admin_email_broadcast_columns_placeholder')"></textarea>
                <span class="placeholder-indicator" :title="placeholderText || tr('admin_email_broadcast_columns_placeholder')" aria-hidden="true">⧉</span>
              </div>
            </div>
          </template>
          <template #cell-transportGroupId="{ row }">
            <select
              v-model="row.transportGroupId"
              :disabled="!isAdminOrSuperAdmin"
              @change="saveTemplate(row)"
              :title="isAdminOrSuperAdmin ? '' : tr('admin_email_broadcast_transport_group_moderator_hint')"
            >
              <option value="" disabled>{{ tr('admin_email_broadcast_select_transport_placeholder') }}</option>
              <template v-for="opt in transportGroupOptions" :key="opt.value">
                <option :value="opt.value">{{ opt.label }}</option>
              </template>
            </select>
          </template>
          <template #row-actions="{ row }">
            <IconButton variant="primary" icon="eye" v-if="canManageTemplates" label="Vorschau" @click="openPreview(row)" />
            <IconButton variant="danger" icon="trash" v-if="canManageTemplates" :label="tr('admin_email_broadcast_columns_delete')" @click="deleteTemplate(row.id)" />
          </template>
        </AdminDataTable>

        <div v-if="canManageTemplates" class="template-card">
          <h4>{{ tr('admin_email_broadcast_new_template_section') }}</h4>
          <label>{{ tr('admin_email_broadcast_name_label') }} <input v-model="templateForm.name" /></label>
          <label class="with-placeholder-icon">{{ tr('admin_email_broadcast_subject_label') }}
            <div class="input-wrap">
              <input v-model="templateForm.subject" :title="placeholderText || tr('admin_email_broadcast_columns_placeholder')" />
              <span class="placeholder-indicator" :title="placeholderText || tr('admin_email_broadcast_columns_placeholder')" aria-hidden="true">⧉</span>
            </div>
          </label>
          <label class="with-placeholder-icon">{{ tr('admin_email_broadcast_cc_label') }}
            <div class="input-wrap">
              <input v-model="templateForm.cc" :title="tr('admin_email_broadcast_cc_title_hint')" />
              <span class="placeholder-indicator" :title="tr('admin_email_broadcast_cc_title_hint')" aria-hidden="true">⧉</span>
            </div>
          </label>
          <!-- Transport group combobox for template - visible to all, editable only for admins -->
          <label>
            {{ tr('admin_email_broadcast_transport_group_label') }}
            <select
              v-model.number="templateForm.transportGroupId"
              :disabled="!isAdminOrSuperAdmin"
              :title="isAdminOrSuperAdmin ? '' : tr('admin_email_broadcast_transport_group_moderator_hint')"
            >
              <option value="" disabled>{{ tr('admin_email_broadcast_select_transport_placeholder') }}</option>
              <template v-for="opt in transportGroupOptions" :key="opt.value">
                <option :value="opt.value">{{ opt.label }}</option>
              </template>
            </select>
          </label>

          <label class="with-placeholder-icon">{{ tr('admin_email_broadcast_bcc_label') }}
            <div class="input-wrap">
              <input v-model="templateForm.bcc" :title="tr('admin_email_broadcast_bcc_title_hint')" />
              <span class="placeholder-indicator" :title="tr('admin_email_broadcast_bcc_title_hint')" aria-hidden="true">⧉</span>
            </div>
          </label>
          <label class="with-placeholder-icon">{{ tr('admin_email_broadcast_body_label') }}
            <div class="input-wrap">
              <textarea v-model="templateForm.body" rows="4" :title="placeholderText || tr('admin_email_broadcast_columns_placeholder')"></textarea>
              <span class="placeholder-indicator" :title="placeholderText || tr('admin_email_broadcast_columns_placeholder')" aria-hidden="true">⧉</span>
            </div>
          </label>
          <div class="template-actions">
            <IconButton icon="plus" :label="tr('admin_email_broadcast_create_button')" @click="createTemplate" />
          </div>
        </div>
        <p v-else class="hint">{{ tr('admin_email_broadcast_templates_hint') }}</p>
      </div>
    </template>
    
    <EmailTemplatePreview v-model="showPreviewDialog" :template="previewTemplate" @close="closePreview" />
  </div>
</template>

<style scoped>
/* Make body column span full width and add spacing */
.body-row {
  grid-column: 1 / -1;
  margin-top: 0.5rem;
  margin-bottom: 0.5rem;
}
.stack { display: flex; flex-direction: column; gap: 0.75rem; }
.top-bar { display: flex; justify-content: space-between; align-items: center; }
.left-actions { display: flex; gap: 0.5rem; }
.card { border: 1px solid var(--border-strong); border-radius: 8px; padding: 0.75rem; background: var(--app-card-bg, var(--surface)); color: var(--text); box-shadow: 0 6px 18px var(--shadow); display: flex; flex-direction: column; gap: 0.75rem; }
.card-header { display: flex; justify-content: space-between; align-items: center; gap: 0.5rem; }
.grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(220px, 1fr)); gap: 0.5rem; align-items: center; }
label { display: flex; flex-direction: column; gap: 0.25rem; font-weight: 600; color: var(--text); }
label.inline { flex-direction: row; align-items: center; font-weight: 500; }
select, input, button { font: inherit; padding: 0.5rem; border: 1px solid var(--border); border-radius: 6px; background: var(--surface); color: var(--text); }
button { background: var(--primary); color: var(--primary-contrast); cursor: pointer; }
button.ghost { background: var(--surface-strong); color: var(--primary); border-color: var(--border-strong); }
button:disabled { opacity: 0.6; cursor: not-allowed; }
.message { color: var(--success-text); background: var(--success-bg); border: 1px solid var(--success-border); padding: 0.5rem; border-radius: 6px; }
.error { color: var(--error-text); background: var(--error-bg); border: 1px solid var(--error-border); padding: 0.5rem; border-radius: 6px; }
.modes { display: grid; grid-template-columns: repeat(auto-fit, minmax(240px, 1fr)); gap: 0.35rem; }
.mode-option { flex-direction: row; align-items: center; gap: 0.35rem; font-weight: 500; border: 1px solid var(--border-strong); padding: 0.5rem; border-radius: 6px; background: var(--surface-muted); color: var(--text); }
.two-col { display: grid; grid-template-columns: repeat(auto-fit, minmax(260px, 1fr)); gap: 0.75rem; }
.list { display: flex; flex-direction: column; gap: 0.35rem; max-height: 300px; overflow: auto; padding: 0.25rem; border: 1px solid var(--border-strong); border-radius: 6px; }
.row { display: flex; flex-direction: row; align-items: center; gap: 0.4rem; font-weight: 400; }
.inline-fields { display: flex; flex-direction: column; gap: 0.35rem; }
.inline-row { display: grid; grid-template-columns: 1fr 1fr auto; gap: 0.35rem; }
.actions { display: flex; justify-content: flex-start; gap: 0.5rem; }
.stats { list-style: none; padding: 0; margin: 0; display: grid; gap: 0.25rem; }
.template-actions { display: flex; gap: 0.5rem; justify-content: flex-end; margin-top: 0.25rem; }
.hint { color: #6b7280; font-size: 0.9rem; }
.subtabs { display: flex; gap: 0.35rem; margin: 0.25rem 0; }
.subtab { padding: 0.45rem 0.7rem; border: 1px solid var(--border); background: var(--surface-muted); border-radius: 6px; cursor: pointer; color: var(--text); }
.subtab.active { background: var(--primary); color: var(--primary-contrast); border-color: var(--primary); }
.body-input { width: 100%; min-height: 120px; resize: vertical; }
.placeholder-info { font-size: 0.9rem; color: var(--text-muted); }
.placeholder-info summary { cursor: pointer; color: var(--primary); font-weight: 600; }
.placeholder-list { display: flex; flex-wrap: wrap; gap: 0.25rem; margin-top: 0.35rem; }
.placeholder-list code { background: var(--surface-muted); color: var(--text); padding: 0.2rem 0.35rem; border-radius: 4px; border: 1px solid var(--border); }
.with-placeholder-icon { position: relative; display: flex; align-items: center; gap: 0.35rem; width: 100%; }
.input-wrap { position: relative; display: flex; align-items: center; gap: 0.35rem; width: 100%; }
.input-wrap input, .input-wrap textarea { flex: 1; width: 100%; }
.placeholder-indicator { color: var(--primary); font-size: 0.9rem; cursor: help; }

/* Transport group combobox styles */
.transport-info-display {
  display: flex;
  align-items: center;
  gap: 0.5rem;
  padding: 0.35rem 0.75rem;
  background: var(--surface-muted);
  border-radius: 6px;
  font-size: 0.9rem;
}
.rate-limit-info {
  display: flex;
  flex-direction: column;
  gap: 0.25rem;
  padding: 0.35rem 0.75rem;
  background: var(--surface-muted);
  border-radius: 6px;
  font-size: 0.85rem;
}
.rate-limit-info span {
  display: flex;
  align-items: center;
  gap: 0.25rem;
}
</style>
