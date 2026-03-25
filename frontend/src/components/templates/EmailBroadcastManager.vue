<script setup>
import { ref, reactive, computed, onMounted } from 'vue'
import IconButton from '../IconButton.vue'
import { adminFetch } from '../../utils/adminApi'
import { useTranslation } from '../../composables/useTranslation'

const props = defineProps({
  transportGroupOptions: { type: Array, default: () => [] },
  currentTransportInfo: { type: Object, default: null },
  isAdminOrSuperAdmin: { type: Boolean, default: false },
  canManageTemplates: { type: Boolean, default: false },
  surveys: { type: Array, default: () => [] }
})

const emit = defineEmits(['message', 'error'])

const { tr } = useTranslation()

const apiKey = ref(localStorage.getItem('admin_auth_session') || sessionStorage.getItem('admin_auth_session') || '')
const routePrefix = ref(localStorage.getItem('admin_route_prefix') || 'admin')

const templates = ref([])
const reservations = ref([])
const waitlist = ref([])
const loading = ref(false)
const message = ref('')
const error = ref('')

const form = reactive({
  templateId: null,
  surveyId: null,
  mode: 'both',
  deduplicate: true,
  userRoles: [],
  selectedReservations: [],
  selectedWaitlist: [],
  customRecipients: [{ name: '', email: '' }],
  transportGroupId: '__none__',
})

const formTemplate = reactive({ name: '', subject: '', body: '', cc: '', bcc: '', type: 'generic', transportGroupId: '__none__' })

function setMessage(msg) { 
  message.value = msg; 
  error.value = ''; 
  emit('message', msg);
}
function setError(msg) { 
  error.value = msg; 
  message.value = ''; 
  emit('error', msg);
}

const fetchWithAuth = (relative, opts = {}) => adminFetch(relative, opts, { apiKeyRef: apiKey, routePrefixRef: routePrefix })

async function loadTemplates() {
  const res = await fetchWithAuth('email-templates')
  if (!res.ok) throw new Error(await res.text())
  const loadedTemplates = await res.json()
  loadedTemplates.forEach(tpl => {
    // Convert transport group to string format for combobox selection
    if (tpl.transport_group_id && tpl.transport_type) {
      tpl.transportGroupId = `${tpl.transport_type}_${tpl.transport_group_id}`;
    } else {
      // Use "__none__" to match the "None" option in comboboxes
      tpl.transportGroupId = '__none__';
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

async function loadAll() {
  if (!apiKey.value) { setError(tr('please_login_api_key_missing')); return }
  loading.value = true
  try {
    await Promise.all([loadTemplates(), loadRecipients()])
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

const reservationsWithEmail = computed(() => reservations.value.filter(r => !!r.email))
const waitlistWithEmail = computed(() => waitlist.value.filter(w => !!w.email))

async function sendBroadcast() {
  if (!apiKey.value) { setError(tr('please_login_api_key_missing')); return }
  if (!form.templateId) { setError(tr('please_select_template')); return }

  const payload = {
    template_id: form.templateId,
    scope: form.mode,
    send_to_all: form.mode !== 'selection',
    deduplicate: form.deduplicate,
  }

  if (form.surveyId) {
    payload.survey_id = form.surveyId
  }

  // Convert "__none__" to null for transport group
  if (form.transportGroupId && form.transportGroupId !== '__none__') {
    let transportType = '';
    if (typeof form.transportGroupId === 'string') {
      if (form.transportGroupId.startsWith('group_')) {
        transportType = 'group';
      } else if (form.transportGroupId.startsWith('account_')) {
        transportType = 'account';
      }
      payload.transport_group_id = parseInt(form.transportGroupId.replace('group_', '').replace('account_', '')) || null;
    } else {
      payload.transport_group_id = form.transportGroupId;
    }
  }

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
    const messageKey = data?.message_key
    setMessage(messageKey ? tr(messageKey) : (data.message || tr('emails_sending')))
  } catch (e) {
    setError(tr('sending_failed') + ': ' + e)
  } finally {
    loading.value = false
  }
}

function customList() {
  return form.customRecipients
    .map(r => ({ name: r.name?.trim() || '', email: r.email?.trim() || '' }))
    .filter(r => r.email)
}

onMounted(() => {
  loadAll()
})
</script>

<template>
  <div class="stack">
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

        <!-- Transport group combobox -->
        <label>
          {{ tr('admin_email_broadcast_transport_group_label') }}
          <select
            v-model="form.transportGroupId"
            :disabled="!isAdminOrSuperAdmin"
            :title="isAdminOrSuperAdmin ? '' : tr('admin_email_broadcast_transport_group_moderator_hint')"
          >
            <option value="__none__">{{ tr('none') }}</option>
            <template v-for="opt in transportGroupOptions" :key="opt.value">
              <option :value="opt.value">{{ opt.label }}</option>
            </template>
          </select>
        </label>

        <!-- Transport info display -->
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
    </div>
  </div>
</template>

<style scoped>
.stack { display: flex; flex-direction: column; gap: 0.75rem; }
.card { border: 1px solid var(--border-strong); border-radius: 8px; padding: 0.75rem; background: var(--app-card-bg, var(--surface)); color: var(--text); box-shadow: 0 6px 18px var(--shadow); display: flex; flex-direction: column; gap: 0.75rem; }
.card-header { display: flex; justify-content: space-between; align-items: center; gap: 0.5rem; }
.grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(220px, 1fr)); gap: 0.5rem; align-items: center; }
label { display: flex; flex-direction: column; gap: 0.25rem; font-weight: 600; color: var(--text); text-align: center; }
label.inline { flex-direction: row; align-items: center; font-weight: 500; }
select, input, button { font: inherit; padding: 0.5rem; border: 1px solid var(--border); border-radius: 6px; background: var(--surface); color: var(--text); }
button { background: var(--primary); color: var(--primary-contrast); cursor: pointer; }
button.ghost { background: var(--surface-strong); color: var(--primary); border-color: var(--border-strong); }
button:disabled { opacity: 0.6; cursor: not-allowed; }
.modes { display: grid; grid-template-columns: repeat(auto-fit, minmax(240px, 1fr)); gap: 0.35rem; }
.mode-option { flex-direction: row; align-items: center; gap: 0.35rem; font-weight: 500; border: 1px solid var(--border-strong); padding: 0.5rem; border-radius: 6px; background: var(--surface-muted); color: var(--text); }
.two-col { display: grid; grid-template-columns: repeat(auto-fit, minmax(260px, 1fr)); gap: 0.75rem; }
.list { display: flex; flex-direction: column; gap: 0.35rem; max-height: 300px; overflow: auto; padding: 0.25rem; border: 1px solid var(--border-strong); border-radius: 6px; }
.row { display: flex; flex-direction: row; align-items: center; gap: 0.4rem; font-weight: 400; }
.inline-fields { display: flex; flex-direction: column; gap: 0.35rem; }
.inline-row { display: grid; grid-template-columns: 1fr 1fr auto; gap: 0.35rem; }
.actions { display: flex; justify-content: flex-start; gap: 0.5rem; }

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
