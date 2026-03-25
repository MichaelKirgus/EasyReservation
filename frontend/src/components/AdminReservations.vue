<script setup>
import { ref, computed, onMounted, onUnmounted, watch } from 'vue'
import IconButton from './IconButton.vue'
import AdminDataTable from './AdminDataTable.vue'
import { adminFetch } from '../utils/adminApi'
import { useTranslation } from '../composables/useTranslation'

const apiBase = import.meta.env.VITE_API_BASE || '/api'
const { tr } = useTranslation()
const apiKey = ref(localStorage.getItem('admin_auth_session') || sessionStorage.getItem('admin_auth_session') || '')
const routePrefix = ref(localStorage.getItem('admin_route_prefix') || 'admin')
const data = ref([])
const waitlist = ref([])
const validations = ref([])
const loading = ref(false)
const waitlistLoading = ref(false)
const validationLoading = ref(false)
const message = ref('')
const error = ref('')
const lastAutoErrorAt = ref(0)

const notifyOnChange = ref(localStorage.getItem('admin_notify_on_change') === '1')

const newReservation = ref({ name: '', email: '', payloadJson: '' })
const newWaitlist = ref({ name: '', email: '', payloadJson: '' })

const selectedReservations = ref([])
const selectedWaitlist = ref([])
const selectedValidations = ref([])

const rateLimits = ref([])
const rateLimitLoading = ref(false)
const selectedRateLimits = ref([])

const reservationColumns = computed(() => [
  { key: 'id', label: tr('admin_reservations_column_id'), sortable: true },
  { key: 'display_name', label: tr('admin_reservations_column_name'), sortable: true },
  { key: 'email', label: tr('admin_reservations_column_email'), sortable: true },
  { key: 'date_added', label: tr('admin_reservations_column_date'), sortable: true },
  { key: 'payload', label: tr('admin_reservations_column_payload'), sortable: false },
  { key: 'site_token', label: tr('admin_reservations_column_token'), sortable: false },
])

const waitlistColumns = computed(() => [
  { key: 'id', label: tr('admin_reservations_column_id'), sortable: true },
  { key: 'display_name', label: tr('admin_reservations_column_name'), sortable: true },
  { key: 'email', label: tr('admin_reservations_column_email'), sortable: true },
  { key: 'date_added', label: tr('admin_reservations_column_date'), sortable: true },
  { key: 'status', label: tr('admin_reservations_column_status'), sortable: true },
  { key: 'site_token', label: tr('admin_reservations_column_token'), sortable: false },
])

const validationColumns = computed(() => [
  { key: 'id', label: tr('admin_reservations_column_id'), sortable: true },
  { key: 'type', label: tr('admin_reservations_column_type'), sortable: true },
  { key: 'display_name', label: tr('admin_reservations_column_name'), sortable: true },
  { key: 'email', label: tr('admin_reservations_column_email'), sortable: true },
  { key: 'status', label: tr('admin_reservations_column_status'), sortable: true },
])

const rateLimitColumns = computed(() => [
  { key: 'ip', label: tr('admin_reservations_column_ip_address'), sortable: true },
  { key: 'count', label: tr('admin_reservations_column_attempts'), sortable: true },
  { key: 'hour', label: tr('admin_reservations_column_time_window'), sortable: true },
])

function formatRateLimitHour(val) {
  if (!val || val.length !== 10) return val
  // val = YYYYMMDDhh from the server-side rate-limit bucket key.
  // Parse as a local wall-clock hour bucket to avoid unintended timezone shifts.
  const y = val.substring(0, 4)
  const m = val.substring(4, 6)
  const d = val.substring(6, 8)
  const h = val.substring(8, 10)
  const date = new Date(Number(y), Number(m) - 1, Number(d), Number(h), 0, 0)
  if (Number.isNaN(date.getTime())) return val
  const fmt = (dt) => new Intl.DateTimeFormat(navigator.language, { day: '2-digit', month: '2-digit', year: 'numeric', hour: '2-digit', minute: '2-digit' }).format(dt)
  const fmtTime = (dt) => new Intl.DateTimeFormat(navigator.language, { hour: '2-digit', minute: '2-digit' }).format(dt)
  const end = new Date(date.getTime() + 3600000)
  return fmt(date) + ' – ' + fmtTime(end)
}

function formatDateTime(val) {
  if (!val) return ''
  const d = new Date(val)
  if (Number.isNaN(d.getTime())) return val
  return new Intl.DateTimeFormat('de-DE', { day: '2-digit', month: '2-digit', year: 'numeric', hour: '2-digit', minute: '2-digit', second: '2-digit' }).format(d)
}

function setMessage(msg) { message.value = msg; error.value = '' }
function setError(msg, opts = {}) {
  if (opts.auto) {
    const now = Date.now()
    if (now - lastAutoErrorAt.value < 30000) return
    lastAutoErrorAt.value = now
  }
  error.value = msg
  message.value = ''
}

const fetchWithAuth = (relative, opts = {}) => adminFetch(relative, opts, { apiKeyRef: apiKey, routePrefixRef: routePrefix })

function notifyQuery() {
  return `notify=${notifyOnChange.value ? 1 : 0}`
}

async function loadNotifyDefaults() {
  if (!apiKey.value) return
  try {
    const res = await fetchWithAuth('notification-defaults')
    if (!res.ok) throw new Error(await res.text())
    const json = await res.json()
    notifyOnChange.value = !!json.notify_default
  } catch (_) {
    // fallback: keep local value
  }
}

function parsePayload(json) {
  if (!json || !json.trim()) return undefined
  try {
    const parsed = JSON.parse(json)
    if (typeof parsed === 'object' && parsed !== null) return parsed
    throw new Error(tr('admin_reservations_payload_must_be_object'))
  } catch (e) {
    throw new Error(`UngÃ¼ltiges JSON: ${e.message}`)
  }
}

async function load(opts = {}) {
  if (!apiKey.value) { setError(tr('please_login_api_key_missing'), opts); return }
  loading.value = true
  try {
    const res = await fetchWithAuth('reservations')
    if (!res.ok) throw new Error(await res.text())
    data.value = await res.json()
    localStorage.setItem('admin_notify_on_change', notifyOnChange.value ? '1' : '0')
    if (!opts.auto) setMessage(tr(''))
  } catch (e) {
    setError(tr('error_loading') + ': ' + e, opts)
  } finally { loading.value = false }
}

async function loadWaitlist(opts = {}) {
  if (!apiKey.value) { setError(tr('please_login_api_key_missing'), opts); return }
  waitlistLoading.value = true
  try {
    const res = await fetchWithAuth('waitlist')
    if (!res.ok) throw new Error(await res.text())
    waitlist.value = await res.json()
  } catch (e) {
    setError(tr('error_loading_waitlist') + ': ' + e, opts)
  } finally {
    waitlistLoading.value = false
  }
}

async function loadValidations(opts = {}) {
  if (!apiKey.value) { setError(tr('please_login_api_key_missing'), opts); return }
  validationLoading.value = true
  try {
    const res = await fetchWithAuth('email-validations?status=pending')
    if (!res.ok) throw new Error(await res.text())
    validations.value = await res.json()
  } catch (e) {
    setError(tr('error_loading_validations') + ': ' + e, opts)
  } finally {
    validationLoading.value = false
  }
}

async function reloadAll(opts = {}) {
  await Promise.all([load(opts), loadWaitlist(opts), loadValidations(opts), loadRateLimits()])
}

async function removeItem(id) {
  if (window.__reservationDeleteInProgress) return;
  window.__reservationDeleteInProgress = true;
  if (!confirm(tr('entry_deleted_confirm'))) {
    window.__reservationDeleteInProgress = false;
    return;
  }
  loading.value = true;
  try {
    const res = await fetchWithAuth(`reservations/${id}?${notifyQuery()}`, { method: 'DELETE' });
    const text = await res.text();
    if (!res.ok) throw new Error(text);
    data.value = data.value.filter(r => r.id !== id);
    setMessage(tr('admin_reservations_entry_deleted'));
    await reloadAll();
  } catch (e) {
    setError(`LÃ¶schen fehlgeschlagen: ${e}`);
  } finally {
    loading.value = false;
    window.__reservationDeleteInProgress = false;
  }
}

async function bulkDeleteReservations() {
  if (!selectedReservations.value.length) return
  if (!confirm(tr('fields_delete_confirm', { count: selectedReservations.value.length }))) return
  loading.value = true
  try {
    for (const id of selectedReservations.value) {
      const res = await fetchWithAuth(`reservations/${id}?${notifyQuery()}`, { method: 'DELETE' })
      const text = await res.text()
      if (!res.ok) throw new Error(text)
    }
    selectedReservations.value = []
    setMessage(tr('reservations_deleted'))
    await reloadAll()
  } catch (e) {
    setError(tr('deletion_failed') + ': ' + e)
  } finally { loading.value = false }
}

async function clearReservations() {
  if (!data.value.length) return
  if (!confirm(tr('fields_delete_confirm', { count: data.value.length }))) return
  loading.value = true
  try {
    for (const r of data.value) {
      await fetchWithAuth(`reservations/${r.id}?${notifyQuery()}`, { method: 'DELETE' })
    }
    selectedReservations.value = []
    setMessage(tr('participants_cleared'))
    await reloadAll()
  } catch (e) {
    setError(tr('clear_failed') + ': ' + e)
  } finally { loading.value = false }
}

function exportCsv() {
  if (!apiKey.value) { setError(tr('api_key_missing')); return }
  const url = `${apiBase}/${routePrefix.value}/export?api_key=${encodeURIComponent(apiKey.value)}`
  window.open(url, '_blank')
}

function exportWaitlistCsv() {
  if (!apiKey.value) { setError(tr('api_key_missing')); return }
  const url = `${apiBase}/${routePrefix.value}/waitlist/export?api_key=${encodeURIComponent(apiKey.value)}`
  window.open(url, '_blank')
}

function handleKeyUpdate(e) {
  apiKey.value = e.detail || ''
}

async function saveReservation(r) {
  if (!apiKey.value) { setError(tr('api_key_missing')); return }
  loading.value = true
  try {
    const res = await fetchWithAuth(`reservations/${r.id}?${notifyQuery()}`, {
      method: 'PATCH',
      body: JSON.stringify({ name: r.display_name, email: r.email || '' }),
    })
    const text = await res.text()
    if (!res.ok) throw new Error(text)
    setMessage(tr('reservation_updated'))
  } catch (e) {
    setError(tr('error_saving') + ': ' + e)
  } finally { loading.value = false }
}

async function saveEmail(r) {
  return saveReservation(r)
}

async function removeWaitlistEntry(id) {
  if (window.__waitlistDeleteInProgress) return;
  window.__waitlistDeleteInProgress = true;
  if (!confirm(tr('entry_deleted_confirm'))) {
    window.__waitlistDeleteInProgress = false;
    return;
  }
  waitlistLoading.value = true;
  try {
    const res = await fetchWithAuth(`waitlist/${id}`, { method: 'DELETE' });
    const text = await res.text();
    if (!res.ok) throw new Error(text);
    waitlist.value = waitlist.value.filter(w => w.id !== id);
    setMessage(tr('admin_reservations_waitlist_entry_deleted'));
  } catch (e) {
    setError(`LÃ¶schen fehlgeschlagen: ${e}`);
  } finally {
    waitlistLoading.value = false;
    window.__waitlistDeleteInProgress = false;
  }
}

async function bulkDeleteWaitlist() {
  if (!selectedWaitlist.value.length) return
  if (!confirm(tr('fields_delete_confirm', { count: selectedWaitlist.value.length }))) return
  waitlistLoading.value = true
  try {
    for (const id of selectedWaitlist.value) {
      const res = await fetchWithAuth(`waitlist/${id}`, { method: 'DELETE' })
      const text = await res.text()
      if (!res.ok) throw new Error(text)
    }
    selectedWaitlist.value = []
    setMessage(tr('waitlist_entries_deleted'))
    await reloadAll()
  } catch (e) {
    setError(tr('deletion_failed') + ': ' + e)
  } finally { waitlistLoading.value = false }
}

async function clearWaitlist() {
  if (!waitlist.value.length) return
  if (!confirm(tr('fields_delete_confirm', { count: waitlist.value.length }))) return
  waitlistLoading.value = true
  try {
    for (const w of waitlist.value) {
      await fetchWithAuth(`waitlist/${w.id}`, { method: 'DELETE' })
    }
    selectedWaitlist.value = []
    setMessage(tr('waitlist_cleared'))
    await reloadAll()
  } catch (e) {
    setError(tr('clear_failed') + ': ' + e)
  } finally { waitlistLoading.value = false }
}

async function approveValidation(id) {
  if (!apiKey.value) { setError(tr('please_login_api_key_missing')); return }
  validationLoading.value = true
  try {
    const res = await fetchWithAuth(`email-validations/${id}/approve`, { method: 'POST' })
    const text = await res.text()
    if (!res.ok) throw new Error(text)
    setMessage(tr('validation_approved'))
    await reloadAll()
  } catch (e) {
    setError(tr('approval_failed') + ': ' + e)
  } finally {
    validationLoading.value = false
  }
}

async function bulkApproveValidations() {
  if (!selectedValidations.value.length) return
  if (!confirm(tr('bulk_approve_validations_confirm', { count: selectedValidations.value.length }))) return
  validationLoading.value = true
  try {
    for (const id of selectedValidations.value) {
      const res = await fetchWithAuth(`email-validations/${id}/approve`, { method: 'POST' })
      const text = await res.text()
      if (!res.ok) throw new Error(text)
    }
    selectedValidations.value = []
    setMessage(tr('validations_approved'))
    await reloadAll()
  } catch (e) {
    setError(tr('approval_failed') + ': ' + e)
  } finally {
    validationLoading.value = false
  }
}

async function discardValidation(id) {
  if (!confirm(tr('discard_validation'))) return
  validationLoading.value = true
  try {
    const res = await fetchWithAuth(`email-validations/${id}`, { method: 'DELETE' })
    const text = await res.text()
    if (!res.ok) throw new Error(text)
    setMessage(tr('validation_discarded'))
    validations.value = validations.value.filter(v => v.id !== id)
  } catch (e) {
    setError(tr('discard_failed') + ': ' + e)
  } finally {
    validationLoading.value = false
  }
}

async function bulkDiscardValidations() {
  if (!selectedValidations.value.length) return
  if (!confirm(tr('bulk_discard_validations_confirm', { count: selectedValidations.value.length }))) return
  validationLoading.value = true
  try {
    for (const id of selectedValidations.value) {
      const res = await fetchWithAuth(`email-validations/${id}`, { method: 'DELETE' })
      const text = await res.text()
      if (!res.ok) throw new Error(text)
    }
    selectedValidations.value = []
    setMessage(tr('validations_discarded'))
    await reloadAll()
  } catch (e) {
    setError(tr('discard_failed') + ': ' + e)
  } finally {
    validationLoading.value = false
  }
}

async function clearValidations() {
  if (!validations.value.length) return
  if (!confirm(tr('clear_all_open_validations_confirm'))) return
  validationLoading.value = true
  try {
    for (const v of validations.value) {
      await fetchWithAuth(`email-validations/${v.id}`, { method: 'DELETE' })
    }
    selectedValidations.value = []
    setMessage(tr('validations_cleared'))
    await reloadAll()
  } catch (e) {
    setError(tr('clear_failed') + ': ' + e)
  } finally { validationLoading.value = false }
}

function statusLabel(status) {
  const labels = {
    email_pending: tr('status_email_pending'),
    waiting_admin: tr('status_waiting_admin'),
    ready: tr('status_ready'),
    completed: tr('status_completed'),
    expired: tr('status_expired'),
    failed: tr('status_failed'),
    cancelled: tr('status_cancelled'),
  }
  return labels[status] || status
}

async function resendValidation(id) {
  if (!apiKey.value) { setError(tr('please_login_api_key_missing')); return }
  validationLoading.value = true
  try {
    const res = await fetchWithAuth(`email-validations/${id}/resend`, { method: 'POST' })
    const text = await res.text()
    if (!res.ok) throw new Error(text)
    setMessage(tr('validation_email_resent'))
  } catch (e) {
    setError(tr('resend_failed') + ': ' + e)
  } finally {
    validationLoading.value = false
  }
}

watch(notifyOnChange, (val) => localStorage.setItem('admin_notify_on_change', val ? '1' : '0'))

onMounted(() => {
  window.addEventListener('api-key-updated', handleKeyUpdate)
  // site_token aus erster Reservierung oder Warteliste Ã¼bernehmen, falls nicht gesetzt
  if (!localStorage.getItem('site_token')) {
    if (data.value.length > 0 && data.value[0].site_token) {
      localStorage.setItem('site_token', data.value[0].site_token)
    } else if (waitlist.value.length > 0 && waitlist.value[0].site_token) {
      localStorage.setItem('site_token', waitlist.value[0].site_token)
    }
  }
  if (apiKey.value) {
    loadNotifyDefaults()
    reloadAll()
  }
})

onUnmounted(() => {
  window.removeEventListener('api-key-updated', handleKeyUpdate)
})

async function createReservation() {
  if (window.__reservationCreateInProgress) return;
  window.__reservationCreateInProgress = true;
  if (!apiKey.value) { setError(tr('api_key_missing')); window.__reservationCreateInProgress = false; return }
  const payload = (() => {
    try { return parsePayload(newReservation.value.payloadJson) } catch (e) { setError(e.message); return null }
  })()
  if (payload === null) { window.__reservationCreateInProgress = false; return }
  loading.value = true
  try {
    // site_token aus localStorage holen
    const site_token = localStorage.getItem('site_token') || ''
    const body = { name: newReservation.value.name, email: newReservation.value.email, site_token }
    if (payload !== undefined) body.payload = payload
    const res = await fetchWithAuth(`reservations?${notifyQuery()}`, { method: 'POST', body: JSON.stringify(body) })
    const text = await res.text()
    if (!res.ok) {
      if (res.status === 409) throw new Error(tr('admin_reservations_reservation_exists_or_conflict'))
      throw new Error(text)
    }
    newReservation.value = { name: '', email: '', payloadJson: '' }
    setMessage(tr('reservation_created'))
    await load()
  } catch (e) {
    setError(tr('creation_failed') + ': ' + (e.message || e))
  } finally {
    loading.value = false;
    window.__reservationCreateInProgress = false;
  }
}

async function createWaitlistEntry() {
  if (window.__waitlistCreateInProgress) return;
  window.__waitlistCreateInProgress = true;
  if (!apiKey.value) { setError(tr('api_key_missing')); window.__waitlistCreateInProgress = false; return }
  const payload = (() => {
    try { return parsePayload(newWaitlist.value.payloadJson) } catch (e) { setError(e.message); return null }
  })()
  if (payload === null) { window.__waitlistCreateInProgress = false; return }
  waitlistLoading.value = true
  try {
    // site_token aus localStorage holen
    const site_token = localStorage.getItem('site_token') || ''
    const body = { name: newWaitlist.value.name, email: newWaitlist.value.email, site_token }
    if (payload !== undefined) body.payload = payload
    const res = await fetchWithAuth('waitlist', { method: 'POST', body: JSON.stringify(body) })
    const text = await res.text()
    if (!res.ok) {
      if (res.status === 409) throw new Error(tr('admin_reservations_waitlist_entry_exists_or_conflict'))
      throw new Error(text)
    }
    newWaitlist.value = { name: '', email: '', payloadJson: '' }
    setMessage(tr('added_to_waitlist'))
    await loadWaitlist()
  } catch (e) {
    setError(tr('creation_failed') + ': ' + (e.message || e))
  } finally {
    waitlistLoading.value = false;
    window.__waitlistCreateInProgress = false;
  }
}

async function updateWaitlistEntry(entry) {
  if (!apiKey.value) { setError(tr('please_login_api_key_missing')); return }
  waitlistLoading.value = true
  try {
    const res = await fetchWithAuth(`waitlist/${entry.id}`, { method: 'PATCH', body: JSON.stringify({ name: entry.display_name, email: entry.email || '' }) })
    const text = await res.text()
    if (!res.ok) throw new Error(text)
    setMessage(tr('waitlist_entry_updated'))
  } catch (e) {
    setError(tr('update_failed') + ': ' + e)
  } finally {
    waitlistLoading.value = false
  }
}

async function promoteWaitlistEntry(id) {
  if (window.__promoteInProgress) return;
  window.__promoteInProgress = true;
  if (!apiKey.value) { setError(tr('api_key_missing')); window.__promoteInProgress = false; return }
  waitlistLoading.value = true
  try {
    const res = await fetchWithAuth(`waitlist/${id}/promote`, { method: 'POST' })
    const text = await res.text()
    if (!res.ok) throw new Error(text)
    setMessage(tr('waitlist_entry_promoted'))
    await reloadAll()
  } catch (e) {
    setError(tr('promotion_failed') + ': ' + e)
  } finally {
    waitlistLoading.value = false;
    window.__promoteInProgress = false;
  }
}

async function loadRateLimits() {
  if (!apiKey.value) { setError(tr('please_login_api_key_missing')); return }
  rateLimitLoading.value = true
  try {
    const res = await fetchWithAuth('email-validation-rate-limits')
    if (!res.ok) throw new Error(await res.text())
    rateLimits.value = await res.json()
  } catch (e) {
    setError(tr('admin_reservations_error_loading_rate_limits') + ': ' + e)
  } finally {
    rateLimitLoading.value = false
  }
}

async function resetRateLimit(ip) {
  if (!confirm(tr('reset_rate_limit_confirm', { ip: ip }))) return
  rateLimitLoading.value = true
  try {
    const res = await fetchWithAuth(`email-validation-rate-limits/${encodeURIComponent(ip)}`, { method: 'DELETE' })
    if (!res.ok) throw new Error(await res.text())
    setMessage(tr('rate_limit_reset'))
    await loadRateLimits()
  } catch (e) {
    setError(tr('reset_failed') + ': ' + e)
  } finally {
    rateLimitLoading.value = false
  }
}

async function clearAllRateLimits() {
  if (!confirm(tr('delete_all_rate_limit_data'))) return
  rateLimitLoading.value = true
  try {
    const res = await fetchWithAuth('email-validation-rate-limits', { method: 'DELETE' })
    if (!res.ok) throw new Error(await res.text())
    setMessage(tr('all_rate_limits_deleted'))
    await loadRateLimits()
  } catch (e) {
    setError(tr('deletion_failed') + ': ' + e)
  } finally {
    rateLimitLoading.value = false
  }
    }

async function purgeAllData() {
  if (!apiKey.value) { setError(tr('api_key_missing')); return }
  if (!confirm(tr('purge_all_data_confirm'))) return
  loading.value = true
  try {
    const res = await fetchWithAuth('purge-all', { method: 'POST' })
    if (!res.ok) throw new Error(await res.text())
    setMessage(tr('all_data_deleted'))
    await reloadAll()
  } catch (e) {
    setError(tr('data_deletion_failed') + ': ' + e)
  } finally {
    loading.value = false
  }
}
</script>

<template>
  <div class="stack">
    <div class="top-bar">
      <div class="left-actions">
        <label class="inline"><input type="checkbox" v-model="notifyOnChange" /> {{ tr('admin_reservations_send_email_to_participants') }}</label>
      </div>
      <div class="right-actions actions">
        <IconButton
          v-if="routePrefix === 'admin'"
          icon="trash2"
          variant="danger"
          label="admin_reservations_clear_all_lists"
          :disabled="loading || waitlistLoading || validationLoading"
          @click="purgeAllData"
        />
        <IconButton icon="refresh" :label="tr('admin_reservations_refresh')" @click="reloadAll" :disabled="loading || waitlistLoading || validationLoading" />
      </div>
    </div>
    <div v-if="message" class="message">{{ message }}</div>
    <div v-if="error" class="error">{{ error }}</div>

    <div class="card">
      <div class="card-header">
        <h3>{{ tr('admin_reservations_participants_list') }}</h3>
      </div>
      <div class="inline-fields">
        <input v-model="newReservation.name" :placeholder="tr('admin_reservations_placeholder_name')" />
        <input v-model="newReservation.email" :placeholder="tr('admin_reservations_placeholder_email')" />
        <input v-model="newReservation.payloadJson" :placeholder="tr('admin_reservations_placeholder_payload')" />
        <IconButton type="button" icon="plus" :label="tr('admin_reservations_add_reservation')" variant="success" @click.stop="createReservation" :disabled="loading" />
      </div>
    </div>

    <AdminDataTable
      :columns="reservationColumns"
      :rows="data"
      v-model="selectedReservations"
      selectable
      :loading="loading"
      :page-size="20"
      :initial-hidden-columns="['id', 'payload']"
      persist-key="admin-reservations"
      @refresh="reloadAll"
      @auto-refresh="reloadAll({ auto: true })"
      :empty-text="tr('admin_reservations_no_reservations')"
    >
      <template #actions>
        <IconButton icon="download" :label="tr('admin_reservations_export_participants_csv')" @click="exportCsv" :disabled="loading" />
        <IconButton icon="trash" variant="danger" :label="tr('admin_reservations_delete_selection')" @click="bulkDeleteReservations" :disabled="loading || !selectedReservations.length" />
        <IconButton variant="danger" icon="trash2" :label="tr('admin_reservations_delete_all')" @click="clearReservations" :disabled="loading || !data.length" />
      </template>
      <template #cell-display_name="{ row }">
        <input v-model="row.display_name" @change="saveReservation(row)" />
      </template>
      <template #cell-email="{ row }">
        <input v-model="row.email" @change="saveEmail(row)" />
      </template>
      <template #cell-date_added="{ value }">{{ formatDateTime(value) }}</template>
      <template #cell-payload="{ row }">
        <pre class="payload" v-if="row.payload">{{ JSON.stringify(row.payload, null, 2) }}</pre><span v-else>â€“</span>
      </template>
      <template #cell-site_token="{ row }">
        <span v-if="row.site_token">{{ row.site_token }}</span><span v-else>â€“</span>
      </template>
      <template #row-actions="{ row }">
        <IconButton class="danger" variant="danger" icon="trash" :label="tr('admin_reservations_delete')" @click.stop="removeItem(row.id)" />
      </template>
    </AdminDataTable>

    <div class="waitlist">
      <div class="card">
        <div class="card-header">
          <h3>{{ tr('admin_reservations_waitlist') }}</h3>
        </div>
        <div class="inline-fields">
          <input v-model="newWaitlist.name" :placeholder="tr('admin_reservations_placeholder_name')" />
          <input v-model="newWaitlist.email" :placeholder="tr('admin_reservations_placeholder_email')" />
          <input v-model="newWaitlist.payloadJson" :placeholder="tr('admin_reservations_placeholder_payload')" />
          <IconButton type="button" icon="plus" :label="tr('admin_reservations_add_to_waitlist')" variant="success" @click.stop="createWaitlistEntry" :disabled="waitlistLoading" />
        </div>
      </div>
      <AdminDataTable
        :columns="waitlistColumns"
        :rows="waitlist"
        v-model="selectedWaitlist"
        selectable
        :loading="waitlistLoading"
        :page-size="20"
        :initial-hidden-columns="['id']"
        persist-key="admin-waitlist"
        @refresh="loadWaitlist"
        @auto-refresh="loadWaitlist({ auto: true })"
        :empty-text="tr('admin_reservations_no_waitlist_entries')"
      >
        <template #actions>
          <IconButton icon="download" :label="tr('admin_reservations_export_waitlist_csv')" @click="exportWaitlistCsv" :disabled="waitlistLoading" />
          <IconButton icon="trash" variant="danger" :label="tr('admin_reservations_delete_selection')" @click="bulkDeleteWaitlist" :disabled="waitlistLoading || !selectedWaitlist.length" />
          <IconButton variant="danger" icon="trash2" :label="tr('admin_reservations_delete_all')" @click="clearWaitlist" :disabled="waitlistLoading || !waitlist.length" />
        </template>
        <template #cell-display_name="{ row }">
          <input v-model="row.display_name" @change="updateWaitlistEntry(row)" />
        </template>
        <template #cell-email="{ row }">
          <input v-model="row.email" @change="updateWaitlistEntry(row)" />
        </template>
        <template #cell-date_added="{ value }">{{ formatDateTime(value) }}</template>
        <template #cell-status="{ value }">{{ value }}</template>
        <template #cell-site_token="{ row }">
          <span v-if="row.site_token">{{ row.site_token }}</span><span v-else>â€“</span>
        </template>
        <template #row-actions="{ row }">
          <IconButton icon="arrowUp" :label="tr('admin_reservations_promote')" @click.stop="promoteWaitlistEntry(row.id)" :disabled="waitlistLoading || row.status !== 'pending'" />
          <IconButton variant="danger" icon="trash" :label="tr('admin_reservations_delete')" @click.stop="removeWaitlistEntry(row.id)" :disabled="waitlistLoading" />
        </template>
      </AdminDataTable>
    </div>

    <div class="card">
      <div class="card-header">
        <h3>Validierung (E-Mail / Admin)</h3>
      </div>
      <p v-if="validationLoading">{{ tr('admin_reservations_loading_validations') }}...</p>
      <AdminDataTable
        v-else
        :columns="validationColumns"
        :rows="validations"
        v-model="selectedValidations"
        selectable
        :loading="validationLoading"
        :page-size="20"
        :initial-hidden-columns="['id']"
        persist-key="admin-validations"
        @refresh="loadValidations"
        @auto-refresh="loadValidations({ auto: true })"
        :empty-text="tr('admin_reservations_no_open_validations')"
      >
        <template #actions>
          <IconButton icon="refresh" :label="tr('admin_reservations_refresh')" @click="loadValidations" :disabled="validationLoading" />
          <IconButton icon="trash" variant="danger" :label="tr('admin_reservations_discard_selection')" @click="bulkDiscardValidations" :disabled="validationLoading || !selectedValidations.length" />
          <IconButton icon="check" :label="tr('admin_reservations_approve_selection')" @click="bulkApproveValidations" :disabled="validationLoading || !selectedValidations.length" />
          <IconButton variant="danger" icon="trash2" :label="tr('admin_reservations_delete_all')" @click="clearValidations" :disabled="validationLoading || !validations.length" />
        </template>
        <template #cell-status="{ value }">{{ statusLabel(value) }}</template>
        <template #row-actions="{ row }">
          <IconButton icon="check" :label="tr('admin_reservations_approve')" @click="approveValidation(row.id)" :disabled="validationLoading" />
          <IconButton icon="mail" :label="tr('admin_reservations_resend_email')" @click="resendValidation(row.id)" :disabled="validationLoading" />
          <IconButton variant="danger" icon="trash" :label="tr('admin_reservations_discard')" @click="discardValidation(row.id)" :disabled="validationLoading" />
        </template>
      </AdminDataTable>
    </div>

    <div class="card">
      <div class="card-header">
        <h3>Rate-Limits E-Mail-Validierung</h3>
      </div>
      <AdminDataTable
        :columns="rateLimitColumns"
        :rows="rateLimits"
        v-model="selectedRateLimits"
        selectable
        :loading="rateLimitLoading"
        :page-size="20"
        persist-key="admin-rate-limits"
        @refresh="loadRateLimits"
        :empty-text="tr('admin_reservations_no_rate_limit_data')"
      >
        <template #actions>
          <IconButton icon="trash2" variant="danger" :label="tr('admin_reservations_delete_all_rate_limits')" @click="clearAllRateLimits" :disabled="rateLimitLoading || !rateLimits.length" />
        </template>
        <template #cell-count="{ value }">
          <span>{{ value }}</span>
        </template>
        <template #cell-hour="{ value }">
          <span>{{ formatRateLimitHour(value) }}</span>
        </template>
        <template #row-actions="{ row }">
          <IconButton icon="trash" variant="danger" :label="tr('admin_reservations_reset')" @click="resetRateLimit(row.ip)" :disabled="rateLimitLoading" />
        </template>
      </AdminDataTable>
    </div>
  </div>
</template>

<style scoped>
.stack { display: flex; flex-direction: column; gap: 0.75rem; }
.top-bar { display: flex; align-items: center; justify-content: space-between; gap: 0.75rem; }
.left-actions { display: flex; gap: 0.75rem; align-items: center; flex-wrap: wrap; }
.right-actions { margin-left: auto; }
.controls { display: flex; gap: 0.75rem; flex-wrap: wrap; align-items: center; }
.card { border: 1px solid #e5e7eb; border-radius: 8px; padding: 0.75rem; background: #fff; display: flex; flex-direction: column; gap: 0.5rem; }
.card-header { display: flex; align-items: center; justify-content: space-between; gap: 0.75rem; }
.card-actions { display: flex; gap: 0.35rem; flex-wrap: wrap; align-items: center; }
.inline-fields { display: grid; grid-template-columns: repeat(auto-fit, minmax(180px, 1fr)); gap: 0.5rem; align-items: center; }
label { display: flex; flex-direction: column; gap: 0.25rem; font-weight: 600; }
input, button { font: inherit; padding: 0.5rem; border: 1px solid #d1d5db; border-radius: 6px; }
button { background: #2563eb; color: #fff; cursor: pointer; }
button.danger { background: #dc2626; }
button:disabled { opacity: 0.6; cursor: not-allowed; }
.message { color: #065f46; background: #ecfdf3; border: 1px solid #a7f3d0; padding: 0.5rem; border-radius: 6px; }
.error { color: #991b1b; background: #fef2f2; border: 1px solid #fecaca; padding: 0.5rem; border-radius: 6px; }
.payload { background:#f8fafc; border:1px solid #e5e7eb; border-radius:6px; padding:0.5rem; max-width:320px; white-space:pre-wrap; word-break:break-word; font-family: "SFMono-Regular", Consolas, monospace; font-size: 12px; }
.label.inline { display:flex; align-items:center; gap:0.35rem; }
.waitlist { margin-top: 1rem; display: flex; flex-direction: column; gap: 0.5rem; }
.actions { display: flex; gap: 0.5rem; flex-wrap: wrap; }
.table-wrapper { overflow-x: auto; }
</style>
