<script setup>
import { ref, reactive, computed, onMounted, nextTick, watch } from 'vue'
import IconButton from './IconButton.vue'
import AdminDataTable from './AdminDataTable.vue'
import OpenStreetMap from './OpenStreetMap.vue'
import { adminFetch } from '../utils/adminApi'
import { useTranslation } from '../composables/useTranslation'

const { tr, hasTranslation, translations } = useTranslation()
const apiKey = ref(localStorage.getItem('admin_auth_session') || sessionStorage.getItem('admin_auth_session') || '')
const routePrefix = ref(localStorage.getItem('admin_route_prefix') || 'admin')
const loading = ref(false)
const error = ref('')
const message = ref('')
const events = ref([])
const selectedEvents = ref([])
const lastAutoErrorAt = ref(0)

// Calendar state
const calendarCursor = ref(new Date())

// Tab state

const activeTab = ref('events')
// Show add form toggles
const showAddEventForm = ref(false)
const showAddLocationForm = ref(false)

// Location list state
const locations = ref([])
const selectedLocations = ref([])

const eventColumns = computed(() => [
  { key: 'id', label: tr('admin_events_columns_id'), sortable: true },
  { key: 'title', label: tr('admin_events_columns_title'), sortable: true },
  { key: 'start_at', label: tr('admin_events_columns_start'), sortable: true },
  { key: 'location.city', label: tr('admin_events_columns_city'), sortable: false },
  { key: 'location.name', label: tr('admin_events_columns_location'), sortable: false },
  { key: 'location.address', label: tr('admin_events_columns_address'), sortable: false },
  { key: 'url', label: tr('admin_events_columns_url'), sortable: false },
  { key: 'capacity', label: tr('admin_events_columns_capacity'), sortable: true },
  { key: 'active', label: tr('admin_events_columns_active'), sortable: true },
])

const locationColumns = computed(() => [
  { key: 'id', label: tr('admin_locations_columns_id'), sortable: true },
  { key: 'name', label: tr('admin_locations_columns_name'), sortable: true },
  { key: 'city', label: tr('admin_locations_columns_city'), sortable: true },
  { key: 'address', label: tr('admin_locations_columns_address'), sortable: false },
  { key: 'contact_email', label: tr('admin_locations_columns_contact_email'), sortable: false },
  { key: 'public_transport', label: tr('admin_locations_columns_public_transport'), sortable: false },
  { key: 'capacity_override', label: tr('admin_locations_columns_capacity'), sortable: true },
  { key: 'url', label: tr('admin_locations_columns_url'), sortable: false },
  { key: 'notes', label: tr('admin_locations_columns_comment'), sortable: false },
  { key: 'active', label: tr('admin_locations_columns_active'), sortable: true },
])

const eventExportFields = computed(() => [
  { key: 'id', label: tr('admin_events_columns_id') },
  { key: 'title', label: tr('admin_events_columns_title') },
  { key: 'start_at', label: tr('admin_events_columns_start') },
  { key: 'end_at', label: tr('admin_events_end_label', 'End') },
  { key: 'location_id', label: tr('admin_events_location_label') },
  { label: tr('admin_locations_columns_name'), value: row => row.location?.name || '' },
  { label: tr('admin_locations_columns_city'), value: row => row.location?.city || '' },
  { label: tr('admin_locations_columns_address'), value: row => row.location?.address || '' },
  { key: 'url', label: tr('admin_events_columns_url') },
  { label: tr('admin_locations_columns_url'), value: row => row.location?.url || '' },
  { key: 'capacity_override', label: tr('admin_events_columns_capacity_override', 'Capacity Override') },
  { key: 'active', label: tr('admin_events_columns_active') },
  { key: 'notes', label: tr('admin_events_notes_label', 'Notes') },
])

const locationExportFields = computed(() => [
  { key: 'id', label: tr('admin_locations_columns_id') },
  { key: 'name', label: tr('admin_locations_columns_name') },
  { key: 'city', label: tr('admin_locations_columns_city') },
  { key: 'address', label: tr('admin_locations_columns_address') },
  { key: 'url', label: tr('admin_locations_columns_url') },
  { key: 'contact_email', label: tr('admin_locations_columns_contact_email') },
  { key: 'public_transport', label: tr('admin_locations_columns_public_transport') },
  { key: 'capacity_override', label: tr('admin_locations_columns_capacity') },
  { key: 'active', label: tr('admin_locations_columns_active') },
  { key: 'notes', label: tr('admin_locations_columns_comment') },
  { key: 'latitude', label: tr('admin_locations_latitude_readonly') },
  { key: 'longitude', label: tr('admin_locations_longitude_readonly') },
])

function formatCoord(val) {
  const n = Number(val)
  return Number.isFinite(n) ? n.toFixed(6) : ''
}

function toNumberOrNull(val) {
  const n = Number(val)
  return Number.isFinite(n) ? n : null
}

function formatDateTime(val) {
  if (!val) return ''
  let d
  // "YYYY-MM-DDTHH:mm" (local)
  if (/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}$/.test(val)) {
    const [date, time] = val.split('T')
    const [year, month, day] = date.split('-').map(Number)
    const [hour, minute] = time.split(':').map(Number)
    d = new Date(year, month - 1, day, hour, minute)
  } else {
    d = new Date(val)
  }
  if (Number.isNaN(d.getTime())) return val
  // Dynamisch nach Sprache und Zeitzone des Browsers formatieren
  return d.toLocaleString(navigator.language, {
    year: 'numeric', month: '2-digit', day: '2-digit',
    hour: '2-digit', minute: '2-digit', second: '2-digit',
    timeZoneName: 'short'
  })
}

function extractField(row, field) {
  if (!field) return ''
  if (typeof field.value === 'function') return field.value(row)
  if (!field.key) return ''
  return field.key.split('.').reduce((acc, key) => (acc && acc[key] !== undefined ? acc[key] : undefined), row)
}

function escapeCsv(val) {
  if (val === null || val === undefined) return ''
  const str = String(val)
  const clean = str.replace(/\r?\n|\r/g, ' ')
  if (/[",\n]/.test(clean)) return `"${clean.replace(/"/g, '""')}"`
  return clean
}

function toCsv(rows, fields) {
  const header = fields.map(f => escapeCsv(f.label || f.key || '')).join(',')
  const body = rows.map(row => fields.map(f => {
    const raw = extractField(row, f)
    if (raw === null || raw === undefined) return ''
    if (typeof raw === 'boolean') return raw ? 'true' : 'false'
    return raw
  }).map(escapeCsv).join(',')).join('\r\n')
  return `${header}${rows.length ? '\r\n' : ''}${body}`
}

function downloadBlob(content, filename, mime) {
  const blob = new Blob([content], { type: mime })
  const url = URL.createObjectURL(blob)
  const a = document.createElement('a')
  a.href = url
  a.download = filename
  a.style.display = 'none'
  document.body.appendChild(a)
  a.click()
  a.remove()
  URL.revokeObjectURL(url)
}

function makeFilename(prefix, ext) {
  const stamp = new Date().toISOString().replace(/[:.]/g, '-')
  return `${prefix}-${stamp}.${ext}`
}

function exportJson(data, prefix) {
  downloadBlob(JSON.stringify(data, null, 2), makeFilename(prefix, 'json'), 'application/json')
}

function exportCsv(rows, fields, prefix) {
  downloadBlob(toCsv(rows, fields), makeFilename(prefix, 'csv'), 'text/csv')
}

function formatDateKey(date) {
  const pad = n => n.toString().padStart(2, '0')
  return `${date.getFullYear()}-${pad(date.getMonth() + 1)}-${pad(date.getDate())}`
}

const eventsByDate = computed(() => {
  const map = {}
  for (const ev of events.value) {
    if (!ev?.start_at) continue
    const d = new Date(ev.start_at)
    if (Number.isNaN(d.getTime())) continue
    const key = formatDateKey(d)
    if (!map[key]) map[key] = []
    map[key].push(ev)
  }
  return map
})

const dayNames = computed(() => {
  const base = new Date(Date.UTC(2024, 0, 1)) // Monday
  return Array.from({ length: 7 }, (_, idx) => {
    const d = new Date(base)
    d.setUTCDate(base.getUTCDate() + idx)
    return d.toLocaleDateString(navigator.language, { weekday: 'short' })
  })
})

const calendarMonthLabel = computed(() => calendarCursor.value.toLocaleDateString(navigator.language, { month: 'long', year: 'numeric' }))

const calendarDays = computed(() => {
  const cursor = calendarCursor.value
  const monthStart = new Date(cursor.getFullYear(), cursor.getMonth(), 1)
  const offset = (monthStart.getDay() + 6) % 7 // start week on Monday
  const gridStart = new Date(monthStart)
  gridStart.setDate(monthStart.getDate() - offset)

  return Array.from({ length: 42 }, (_, idx) => {
    const d = new Date(gridStart)
    d.setDate(gridStart.getDate() + idx)
    const key = formatDateKey(d)
    return {
      date: d,
      key,
      isCurrentMonth: d.getMonth() === cursor.getMonth(),
      isToday: key === formatDateKey(new Date()),
      events: eventsByDate.value[key] || [],
    }
  })
})

function isoWeekNumber(date) {
  const d = new Date(Date.UTC(date.getFullYear(), date.getMonth(), date.getDate()))
  const dayNum = d.getUTCDay() || 7
  d.setUTCDate(d.getUTCDate() + 4 - dayNum)
  const yearStart = new Date(Date.UTC(d.getUTCFullYear(), 0, 1))
  return Math.ceil((((d - yearStart) / 86400000) + 1) / 7)
}

const calendarWeeks = computed(() => {
  const weeks = []
  for (let i = 0; i < calendarDays.value.length; i += 7) {
    const days = calendarDays.value.slice(i, i + 7)
    weeks.push({ week: isoWeekNumber(days[0].date), days })
  }
  return weeks
})

function shiftMonth(amount) {
  const next = new Date(calendarCursor.value)
  next.setMonth(calendarCursor.value.getMonth() + amount)
  calendarCursor.value = next
}

function startNewEventOnDate(dayKey) {
  resetEventForm()
  eventForm.start_at = `${dayKey}T09:00`
  showAddEventForm.value = true
  nextTick(() => {
    if (eventTitleInputRef.value) eventTitleInputRef.value.focus()
  })
}

function openEventFromCalendar(ev) {
  editEvent(ev)
  showAddEventForm.value = true
  nextTick(() => {
    if (eventTitleInputRef.value) eventTitleInputRef.value.focus()
  })
}

// Event form state
const eventForm = reactive({
  id: null,
  title: '',
  start_at: '',
  end_at: '',
  location_id: null,
  capacity_override: null,
  active: true,
  notes: '',
})

// Location form state
const locationForm = reactive({
  id: null,
  name: '',
  city: '',
  address: '',
  url: '',
  latitude: null,
  longitude: null,
  contact_email: '',
  active: true,
  public_transport: '',
  notes: '',
  capacity_override: null,
})

// Map reference for OpenStreetMap component
const mapRef = ref(null)
const eventTitleInputRef = ref(null)

function setError(msg, opts = {}) {
  if (opts.auto) {
    const now = Date.now()
    if (now - lastAutoErrorAt.value < 30000) return
    lastAutoErrorAt.value = now
  }
  error.value = msg; message.value = ''
}
function setMessage(msg) { message.value = msg; error.value = '' }

// Re-translate any stored message once translations arrive (fixes timing where keys show briefly)
watch(translations, () => {
  if (message.value && hasTranslation(message.value)) {
    message.value = tr(message.value)
  }
})

// Event functions
async function loadEvents(opts = {}) {
  if (!apiKey.value) { setError(tr('api_key_missing'), opts); return }
  loading.value = true
  try {
    const res = await adminFetch('events', {}, { apiKeyRef: apiKey, routePrefixRef: routePrefix })
    const data = await res.text()
    if (!res.ok) throw new Error(data)
    events.value = data ? JSON.parse(data) : []
    if (!opts.auto) setMessage(tr('common_messages_updated'))
  } catch (e) { setError(e.message || String(e), opts) } finally { loading.value = false }
}

function exportEvents(format) {
  if (format === 'csv') {
    exportCsv(events.value, eventExportFields.value, 'events')
  } else {
    exportJson(events.value, 'events')
  }
}


function resetEventForm() {
  eventForm.id = null
  eventForm.title = ''
  eventForm.start_at = ''
  eventForm.end_at = ''
  eventForm.location_id = null
  eventForm.capacity_override = null
  eventForm.active = true
  eventForm.notes = ''
  showAddEventForm.value = false
}

function editEvent(ev) {
  eventForm.id = ev.id
  eventForm.title = ev.title
  function toLocalDatetime(val) {
    if (!val) return ''
    const d = new Date(val)
    if (Number.isNaN(d.getTime())) return ''
    const pad = n => n.toString().padStart(2, '0')
    return `${d.getFullYear()}-${pad(d.getMonth()+1)}-${pad(d.getDate())}T${pad(d.getHours())}:${pad(d.getMinutes())}`
  }
  eventForm.start_at = toLocalDatetime(ev.start_at)
  eventForm.end_at = toLocalDatetime(ev.end_at)
  eventForm.location_id = ev.location_id
  eventForm.capacity_override = ev.capacity_override
  eventForm.active = !!ev.active
  eventForm.notes = ev.notes || ''
}

async function saveEvent() {
  if (!apiKey.value) { setError(tr('api_key_missing')); return }
  if (!eventForm.title || !eventForm.start_at) { setError(tr('title_and_start_time_required')); return }
  loading.value = true
  try {
    const payload = {
      title: eventForm.title,
      start_at: eventForm.start_at,
      end_at: eventForm.end_at || null,
      location_id: eventForm.location_id || null,
      capacity_override: eventForm.capacity_override === '' ? null : eventForm.capacity_override,
      active: eventForm.active ? 1 : 0,
      notes: eventForm.notes || null,
      auto_close_minutes_before: null,
      auto_email_template_id: null,
      auto_email_offset_minutes_before: null,
      auto_email_sent_at: null,
    }
    if (eventForm.id) {
      const res = await adminFetch(`events/${eventForm.id}`, { method: 'PATCH', body: JSON.stringify(payload) }, { apiKeyRef: apiKey, routePrefixRef: routePrefix })
      const text = await res.text(); if (!res.ok) throw new Error(text)
      setMessage(tr('event_updated'))
    } else {
      const res = await adminFetch('events', { method: 'POST', body: JSON.stringify(payload) }, { apiKeyRef: apiKey, routePrefixRef: routePrefix })
      const text = await res.text(); if (!res.ok) throw new Error(text)
      setMessage(tr('event_created'))
    }
    resetEventForm()
    await loadEvents()
  } catch (e) { setError(e.message || String(e)) } finally { loading.value = false }
}

async function removeEvent(id) {
  if (!apiKey.value) { setError(tr('api_key_missing')); return }
  if (!confirm(tr('event_deleted_confirm'))) return
  loading.value = true
  try {
    const res = await adminFetch(`events/${id}`, { method: 'DELETE' }, { apiKeyRef: apiKey, routePrefixRef: routePrefix })
    const text = await res.text(); if (!res.ok) throw new Error(text)
    setMessage(tr('deleted'))
    await loadEvents()
  } catch (e) { setError(e.message || String(e)) } finally { loading.value = false }
}

async function cloneEvent(event) {
  if (!apiKey.value) { setError(tr('api_key_missing')); return }
  loading.value = true
  try {
    const res = await adminFetch(`events/${event.id}/clone`, { method: 'POST' }, { apiKeyRef: apiKey, routePrefixRef: routePrefix })
    const text = await res.text(); if (!res.ok) throw new Error(text)
    setMessage(tr('event_cloned'))
    await loadEvents()
  } catch (e) { setError(e.message || String(e)) } finally { loading.value = false }
}

async function bulkRemoveEvents() {
  if (!selectedEvents.value.length) return
  if (!confirm(tr('bulk_delete_events_confirm', { count: selectedEvents.value.length }))) return
  loading.value = true
  try {
    for (const id of selectedEvents.value) {
      const res = await adminFetch(`events/${id}`, { method: 'DELETE' }, { apiKeyRef: apiKey, routePrefixRef: routePrefix })
      const text = await res.text(); if (!res.ok) throw new Error(text)
    }
    selectedEvents.value = []
    setMessage(tr('selected_events_deleted'))
    await loadEvents()
  } catch (e) { setError(e.message || String(e)) } finally { loading.value = false }
}

// Location functions
async function loadLocations(opts = {}) {
  if (!apiKey.value) { setError(tr('api_key_missing'), opts); return }
  loading.value = true
  try {
    const res = await adminFetch('locations', {}, { apiKeyRef: apiKey, routePrefixRef: routePrefix })
    const data = await res.text()
    if (!res.ok) throw new Error(data)
    locations.value = data
      ? JSON.parse(data).map(loc => ({
          ...loc,
          latitude: toNumberOrNull(loc.latitude),
          longitude: toNumberOrNull(loc.longitude),
        }))
      : []
    if (!opts.auto && activeTab.value === 'locations') setMessage(tr('common_messages_updated'))
  } catch (e) { setError(e.message || String(e), opts) } finally { loading.value = false }
}

function exportLocations(format) {
  if (format === 'csv') {
    exportCsv(locations.value, locationExportFields.value, 'locations')
  } else {
    exportJson(locations.value, 'locations')
  }
}


function resetLocationForm() {
  locationForm.id = null
  locationForm.name = ''
  locationForm.city = ''
  locationForm.address = ''
  locationForm.url = ''
  locationForm.latitude = null
  locationForm.longitude = null
  locationForm.contact_email = ''
  locationForm.active = true
  locationForm.public_transport = ''
  locationForm.notes = ''
  locationForm.capacity_override = null
  showAddLocationForm.value = false
}

function editLocation(loc) {
  locationForm.id = loc.id
  locationForm.name = loc.name || ''
  locationForm.city = loc.city || ''
  locationForm.address = loc.address || ''
  locationForm.url = loc.url || ''
  // Normalize to numbers for toFixed usage; fall back to null when not provided
  locationForm.latitude = toNumberOrNull(loc.latitude)
  locationForm.longitude = toNumberOrNull(loc.longitude)
  locationForm.contact_email = loc.contact_email || ''
  locationForm.active = !!loc.active
  locationForm.public_transport = loc.public_transport || ''
  locationForm.notes = loc.notes || ''
  locationForm.capacity_override = loc.capacity_override
}

async function saveLocation() {
  if (!apiKey.value) { setError(tr('api_key_missing')); return }
  if (!locationForm.name) { setError(tr('location_name_required')); return }
  loading.value = true
  try {
    const payload = {
      name: locationForm.name,
      city: locationForm.city || null,
      address: locationForm.address || null,
      url: locationForm.url || null,
      latitude: locationForm.latitude,
      longitude: locationForm.longitude,
      contact_email: locationForm.contact_email || null,
      active: locationForm.active ? 1 : 0,
      public_transport: locationForm.public_transport || null,
      notes: locationForm.notes || null,
      capacity_override: locationForm.capacity_override === '' ? null : locationForm.capacity_override,
    }
    if (locationForm.id) {
      const res = await adminFetch(`locations/${locationForm.id}`, { method: 'PATCH', body: JSON.stringify(payload) }, { apiKeyRef: apiKey, routePrefixRef: routePrefix })
      const text = await res.text(); if (!res.ok) throw new Error(text)
      setMessage(tr('location_updated'))
    } else {
      const res = await adminFetch('locations', { method: 'POST', body: JSON.stringify(payload) }, { apiKeyRef: apiKey, routePrefixRef: routePrefix })
      const text = await res.text(); if (!res.ok) throw new Error(text)
      setMessage(tr('location_created'))
    }
    resetLocationForm()
    await loadLocations()
  } catch (e) { setError(e.message || String(e)) } finally { loading.value = false }
}

async function removeLocation(id) {
  if (!apiKey.value) { setError(tr('api_key_missing')); return }
  if (!confirm(tr('location_deleted_confirm'))) return
  loading.value = true
  try {
    const res = await adminFetch(`locations/${id}`, { method: 'DELETE' }, { apiKeyRef: apiKey, routePrefixRef: routePrefix })
    const text = await res.text(); if (!res.ok) throw new Error(text)
    setMessage(tr('deleted'))
    await loadLocations()
  } catch (e) { setError(e.message || String(e)) } finally { loading.value = false }
}

async function cloneLocation(location) {
  if (!apiKey.value) { setError(tr('api_key_missing')); return }
  loading.value = true
  try {
    const res = await adminFetch(`locations/${location.id}/clone`, { method: 'POST' }, { apiKeyRef: apiKey, routePrefixRef: routePrefix })
    const text = await res.text(); if (!res.ok) throw new Error(text)
    setMessage(tr('location_cloned'))
    await loadLocations()
  } catch (e) { setError(e.message || String(e)) } finally { loading.value = false }
}

async function bulkRemoveLocations() {
  if (!selectedLocations.value.length) return
  if (!confirm(tr('bulk_delete_locations_confirm', { count: selectedLocations.value.length }))) return
  loading.value = true
  try {
    for (const id of selectedLocations.value) {
      const res = await adminFetch(`locations/${id}`, { method: 'DELETE' }, { apiKeyRef: apiKey, routePrefixRef: routePrefix })
      const text = await res.text(); if (!res.ok) throw new Error(text)
    }
    selectedLocations.value = []
    setMessage(tr('selected_locations_deleted'))
    await loadLocations()
  } catch (e) { setError(e.message || String(e)) } finally { loading.value = false }
}

onMounted(() => {
  window.addEventListener('api-key-updated', e => { apiKey.value = e.detail || '' })
  if (apiKey.value) {
    loadEvents()
    loadLocations()
  }
})
</script>

<template>
  <div class="stack">
    <!-- Tabs -->
    <div class="admin-tabs">
      <button 
        class="admin-tab-button"
        :class="{ active: activeTab === 'events' }" 
        @click="activeTab = 'events'"
      >
        {{ tr('admin_events_title') }}
      </button>
      <button 
        class="admin-tab-button"
        :class="{ active: activeTab === 'locations' }" 
        @click="activeTab = 'locations'"
      >
        {{ tr('admin_locations_title') }}
      </button>
    </div>


    <!-- Events Tab -->
    <div v-if="activeTab === 'events'" class="tab-content">
      <div class="tab-header-row">
        <div class="tab-actions">
          <IconButton icon="download" :label="tr('admin_export_json_button')" size="sm" variant="ghost" @click="exportEvents('json')" />
          <IconButton icon="download" :label="tr('admin_export_csv_button')" size="sm" variant="ghost" @click="exportEvents('csv')" />
          <IconButton icon="plus" :label="tr('admin_events_new_button')" class="add-btn" @click="showAddEventForm = !showAddEventForm" />
        </div>
      </div>

      <div v-if="error" class="error">{{ error }}</div>
      <div v-if="message" class="message">{{ message }}</div>

      <div v-if="showAddEventForm || eventForm.id" class="form-grid form-with-actions">
        <label> {{ tr('admin_events_columns_title') }} <input v-model="eventForm.title" ref="eventTitleInputRef" /></label>
        <label> {{ tr('admin_events_start_label') }} <input v-model="eventForm.start_at" type="datetime-local" /></label>
        <label> {{ tr('admin_events_end_label') }} <input v-model="eventForm.end_at" type="datetime-local" /></label>
        <label> {{ tr('admin_events_location_label') }}
          <select v-model.number="eventForm.location_id">
            <option :value="null">{{ tr('admin_events_location_new_option') }}</option>
            <option v-for="loc in locations" :key="loc.id" :value="loc.id">
              {{ loc.name }}{{ loc.city ? ' (' + loc.city + ')' : '' }}
            </option>
          </select>
        </label>
        <label> {{ tr('admin_events_columns_capacity_override') }} (optional) <input v-model.number="eventForm.capacity_override" type="number" min="0" /></label>
        <label class="checkbox-row"><input type="checkbox" v-model="eventForm.active" /> {{ tr('admin_events_columns_active') }}</label>
        <label> {{ tr('admin_events_notes_label') }} <textarea v-model="eventForm.notes" rows="3"></textarea></label>
        <div class="form-actions">
          <IconButton icon="save" :label="tr('admin_events_save_button')" @click="saveEvent" :disabled="loading" />
          <IconButton v-if="eventForm.id || showAddEventForm" icon="close" :label="tr('admin_events_cancel_button')" variant="ghost" @click="resetEventForm" />
        </div>
      </div>

      <AdminDataTable
        :columns="eventColumns"
        :rows="events"
        v-model="selectedEvents"
        selectable
        :loading="loading"
        :page-size="20"
        persist-key="admin-events"
        :initial-hidden-columns="['id']"
        :empty-text="tr('admin_events_no_events_text')"
        @refresh="loadEvents"
        @auto-refresh="loadEvents({ auto: true })"
      >
        <template #actions>
          <IconButton icon="trash" variant="danger" :label="tr('admin_events_delete_selection_button')" @click="bulkRemoveEvents" :disabled="loading || !selectedEvents.length" />
        </template>
        <template #cell-start_at="{ value }">{{ formatDateTime(value) }}</template>
        <template #cell-location.city="{ row }">{{ row.location?.city || tr('admin_events_dash_text') }}</template>
        <template #cell-location.name="{ row }">{{ row.location?.name || tr('admin_events_dash_text') }}</template>
        <template #cell-location.address="{ row }">{{ row.location?.address || tr('admin_events_dash_text') }}</template>
        <template #cell-url="{ row, value }">
          <!-- Use location.url if available from relationship, otherwise fall back to event.url -->
          <a v-if="row.location?.url" :href="row.location.url" target="_blank" rel="noopener">{{ tr('admin_events_link_text') }}</a>
          <span v-else-if="value" :href="value" target="_blank" rel="noopener">{{ value }}</span>
          <span v-else>{{ tr('admin_events_dash_text') }}</span>
        </template>
        <template #cell-capacity="{ row }">
          {{ row.capacity_override ?? row.location?.capacity_override ?? tr('admin_events_dash_text') }}
        </template>
        <template #cell-active="{ value }">{{ value ? tr('admin_events_yes_text') : tr('admin_events_no_text') }}</template>
        <template #row-actions="{ row }">
          <IconButton icon="pencil" :label="tr('admin_events_edit_button')" variant="ghost" @click="editEvent(row); showAddEventForm = true" />
          <IconButton icon="copy" :label="tr('icon_buttons_clone')" variant="ghost" @click="cloneEvent(row)" />
          <IconButton icon="trash" :label="tr('admin_events_delete_button')" variant="danger" @click="removeEvent(row.id)" />
        </template>
      </AdminDataTable>

      <div class="calendar-card">
        <div class="calendar-header">
          <IconButton icon="chevronLeft" :label="tr('admin_events_calendar_prev', 'Previous month')" variant="ghost" size="sm" @click="shiftMonth(-1)" />
          <div class="calendar-title">{{ calendarMonthLabel }}</div>
          <IconButton icon="chevronRight" :label="tr('admin_events_calendar_next', 'Next month')" variant="ghost" size="sm" @click="shiftMonth(1)" />
        </div>
        <div class="calendar-grid">
          <div class="calendar-week-label">{{ tr('admin_events_calendar_week_label', 'CW') }}</div>
          <div v-for="name in dayNames" :key="name" class="calendar-day-name">{{ name }}</div>

          <template v-for="week in calendarWeeks" :key="week.week">
            <div class="calendar-week-number">{{ week.week }}</div>
            <button
              v-for="day in week.days"
              :key="day.key"
              class="calendar-cell"
              :class="{ 'is-outside': !day.isCurrentMonth, 'is-today': day.isToday }"
              type="button"
              @click="startNewEventOnDate(day.key)"
            >
              <div class="calendar-date">{{ day.date.getDate() }}</div>
              <div class="calendar-events">
                <button
                  v-for="ev in day.events"
                  :key="ev.id"
                  class="calendar-pill"
                  type="button"
                  @click.stop="openEventFromCalendar(ev)"
                >
                  {{ ev.title || tr('admin_events_columns_title') }}
                </button>
              </div>
            </button>
          </template>
        </div>
        <div class="calendar-hint">{{ tr('admin_events_calendar_hint', 'Click a day to start a new event') }}</div>
      </div>
    </div>


    <!-- Locations Tab -->
    <div v-if="activeTab === 'locations'" class="tab-content">
      <div class="tab-header-row">
        <div class="tab-actions">
          <IconButton icon="download" :label="tr('admin_export_json_button')" size="sm" variant="ghost" @click="exportLocations('json')" />
          <IconButton icon="download" :label="tr('admin_export_csv_button')" size="sm" variant="ghost" @click="exportLocations('csv')" />
          <IconButton icon="plus" :label="tr('admin_locations_new_button')" class="add-btn" @click="showAddLocationForm = !showAddLocationForm" />
        </div>
      </div>

      <div v-if="error" class="error">{{ error }}</div>
      <div v-if="message" class="message">{{ message }}</div>

      <div v-if="showAddLocationForm || locationForm.id" class="form-grid form-with-actions">
        <label> {{ tr('admin_locations_columns_name') }} * <input v-model="locationForm.name" /></label>
        <label> {{ tr('admin_locations_columns_city') }} <input v-model="locationForm.city" /></label>
        <label> {{ tr('admin_locations_columns_address') }} <input v-model="locationForm.address" :placeholder="tr('admin_locations_address_placeholder')" /></label>
        <label> {{ tr('admin_locations_url_label') }} <input v-model="locationForm.url" type="url" :placeholder="tr('admin_locations_url_placeholder')" /></label>
        <label> {{ tr('admin_locations_columns_contact_email') }} (optional) <input v-model="locationForm.contact_email" type="email" :placeholder="tr('admin_locations_email_placeholder')" /></label>
        <label> {{ tr('admin_locations_columns_public_transport') }} <input v-model="locationForm.public_transport" :placeholder="tr('admin_events_public_transport_placeholder')" /></label>
        <label> {{ tr('admin_locations_columns_capacity') }} (optional) <input v-model.number="locationForm.capacity_override" type="number" min="0" /></label>
        <label class="checkbox-row"><input type="checkbox" v-model="locationForm.active" /> {{ tr('admin_locations_columns_active') }}</label>
        <label style="grid-column: 1 / -1"> {{ tr('admin_locations_columns_notes') }} <textarea v-model="locationForm.notes" rows="3"></textarea></label>
        <div class="form-actions">
          <IconButton icon="save" :label="tr('admin_events_save_button')" @click="saveLocation" :disabled="loading" />
          <IconButton v-if="locationForm.id || showAddLocationForm" icon="close" :label="tr('admin_events_cancel_button')" variant="ghost" @click="resetLocationForm" />
        </div>
      </div>

      <!-- Latitude/Longitude read-only fields -->
      <div v-if="showAddLocationForm || locationForm.id" class="form-grid">
        <label> {{ tr('admin_locations_latitude_readonly') }} <input type="text" :value="formatCoord(locationForm.latitude)" readonly /></label>
        <label> {{ tr('admin_locations_longitude_readonly') }} <input type="text" :value="formatCoord(locationForm.longitude)" readonly /></label>
      </div>

      <!-- Table with all locations -->
      <AdminDataTable
        :columns="locationColumns"
        :rows="locations"
        v-model="selectedLocations"
        selectable
        :loading="loading"
        :page-size="20"
        persist-key="admin-locations"
        :initial-hidden-columns="['id', 'notes']"
        :empty-text="tr('admin_locations_no_locations_text')"
        @refresh="loadLocations"
        @auto-refresh="loadLocations({ auto: true })"
      >
        <template #actions>
          <IconButton icon="trash" variant="danger" :label="tr('admin_events_delete_selection_button')" @click="bulkRemoveLocations" :disabled="loading || !selectedLocations.length" />
        </template>
        <template #cell-contact_email="{ value }">{{ value || tr('admin_events_dash_text') }}</template>
        <template #cell-url="{ value }">
          <a v-if="value" :href="value" target="_blank" rel="noopener">{{ value }}</a>
          <span v-else>{{ tr('admin_events_dash_text') }}</span>
        </template>
        <template #cell-notes="{ value }">{{ value || tr('admin_events_dash_text') }}</template>
        <template #cell-active="{ value }">{{ value ? tr('admin_events_yes_text') : tr('admin_events_no_text') }}</template>
        <template #row-actions="{ row }">
          <IconButton icon="pencil" :label="tr('admin_events_edit_button')" variant="ghost" @click="editLocation(row); showAddLocationForm = true" />
          <IconButton icon="copy" :label="tr('icon_buttons_clone')" variant="ghost" @click="cloneLocation(row)" />
          <IconButton icon="trash" :label="tr('admin_events_delete_button')" variant="danger" @click="removeLocation(row.id)" />
        </template>
      </AdminDataTable>

      <!-- Map with all locations -->
      <div v-if="activeTab === 'locations'" class="map-section">
        <h3>{{ tr('admin_locations_map_title') }}</h3>
        <OpenStreetMap
          :initial-lat="locationForm.latitude"
          :initial-lng="locationForm.longitude"
          :locations="locations"
          :show-all-locations="!(showAddLocationForm || locationForm.id)"
          @update:lat="(val) => locationForm.latitude = toNumberOrNull(val)"
          @update:lng="(val) => locationForm.longitude = toNumberOrNull(val)"
        />
      </div>
    </div>
  </div>
</template>

<style scoped>
.stack { display: flex; flex-direction: column; gap: 0.75rem; }
.tab-content { display: flex; flex-direction: column; gap: 0.75rem; }
.controls { display: flex; gap: 0.5rem; flex-wrap: wrap; }
.tab-header-row { display: flex; align-items: center; justify-content: space-between; margin-bottom: 0.5rem; }
.tab-actions { display: flex; align-items: center; gap: 0.35rem; margin-left: auto; }
.add-btn { margin-left: auto; }
.tab-actions .add-btn { margin-left: 0; }
.form-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(240px, 1fr)); gap: 0.5rem; }
.form-with-actions { position: relative; }
.form-actions {
  grid-column: 1 / -1;
  display: flex;
  justify-content: flex-end;
  gap: 0.5rem;
  margin-top: 0.5rem;
}
label { display: flex; flex-direction: column; gap: 0.15rem; font-weight: 600; }
label select { padding: 0.5rem; border: 1px solid #d1d5db; border-radius: 6px; font: inherit; }
.checkbox-row { flex-direction: row; align-items: center; gap: 0.5rem; font-weight: 600; }
input, textarea { font: inherit; padding: 0.5rem; border: 1px solid #d1d5db; border-radius: 6px; }
button { padding: 0.35rem 0.6rem; border: 1px solid #d1d5db; border-radius: 6px; cursor: pointer; background: #2563eb; color: #fff; }
button.ghost { background: #eef2ff; color: #1d4ed8; border-color: #c7d2fe; }
button.danger { background: #dc2626; color: #fff; border-color: #dc2626; }
.error { color: #991b1b; background: #fef2f2; border: 1px solid #fecaca; padding: 0.5rem; border-radius: 6px; }
.message { color: #065f46; background: #ecfdf3; border: 1px solid #a7f3d0; padding: 0.5rem; border-radius: 6px; }
.calendar-card { border: 1px solid var(--border-strong); border-radius: 10px; padding: 0.75rem; background: var(--surface); box-shadow: 0 1px 2px var(--shadow); display: flex; flex-direction: column; gap: 0.5rem; color: var(--text); }
.calendar-header { display: flex; align-items: center; justify-content: space-between; gap: 0.5rem; }
.calendar-title { font-weight: 700; text-transform: capitalize; color: var(--text); }
.calendar-grid { display: grid; grid-template-columns: 48px repeat(7, minmax(0, 1fr)); gap: 0.35rem; align-items: stretch; }
.calendar-week-label, .calendar-week-number { display: flex; align-items: center; justify-content: center; font-weight: 700; color: var(--text-muted); background: var(--surface-muted); border: 1px solid var(--border-strong); border-radius: 8px; min-height: 48px; }
.calendar-week-number { color: var(--text); }
.calendar-day-name { text-align: center; font-weight: 700; color: var(--text); font-size: 0.95rem; }
.calendar-cell { border: 1px solid var(--border-strong); border-radius: 8px; padding: 0.5rem; min-height: 96px; text-align: left; background: var(--surface-muted); color: var(--text); cursor: pointer; display: flex; flex-direction: column; gap: 0.35rem; transition: border-color 0.15s ease, background-color 0.15s ease, box-shadow 0.15s ease; }
.calendar-cell:hover { border-color: var(--primary); background: var(--surface-strong); }
.calendar-cell.is-outside { opacity: 0.55; }
.calendar-cell.is-today { border-color: var(--primary); box-shadow: 0 0 0 2px var(--focus); }
.calendar-date { font-weight: 800; color: var(--text); font-size: 1.05rem; letter-spacing: -0.01em; }
.calendar-events { display: flex; flex-direction: column; gap: 0.25rem; }
.calendar-pill { display: inline-block; padding: 0.15rem 0.4rem; border-radius: 999px; background: var(--surface-strong); color: var(--text); font-size: 0.85rem; line-height: 1.2; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; border: 1px solid var(--border-strong); cursor: pointer; text-align: left; }
.calendar-pill:hover { border-color: var(--primary); background: var(--surface); box-shadow: 0 0 0 1px var(--primary); }
.calendar-empty { color: var(--text-muted); font-size: 0.85rem; }
.calendar-hint { color: var(--text-muted); font-size: 0.9rem; }
</style>
