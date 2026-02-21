<script setup>
import { ref, reactive, computed, onMounted, nextTick } from 'vue'
import IconButton from './IconButton.vue'
import AdminDataTable from './AdminDataTable.vue'
import OpenStreetMap from './OpenStreetMap.vue'
import { adminFetch } from '../utils/adminApi'
import { useTranslation } from '../composables/useTranslation'

const { tr } = useTranslation()
const apiKey = ref(localStorage.getItem('admin_auth_session') || sessionStorage.getItem('admin_auth_session') || '')
const routePrefix = ref(localStorage.getItem('admin_route_prefix') || 'admin')
const loading = ref(false)
const error = ref('')
const message = ref('')
const events = ref([])
const selectedEvents = ref([])
const lastAutoErrorAt = ref(0)

// Tab state
const activeTab = ref('events')

// Location list state
const locations = ref([])
const selectedLocations = ref([])

const eventColumns = computed(() => [
  { key: 'id', label: tr('admin_events_columns_id'), sortable: true },
  { key: 'title', label: tr('admin_events_columns_title'), sortable: true },
  { key: 'start_at', label: tr('admin_events_columns_start'), sortable: true },
  { key: 'city', label: tr('admin_events_columns_city'), sortable: true },
  { key: 'url', label: tr('admin_events_columns_url'), sortable: false },
  { key: 'public_transport_url', label: tr('admin_events_columns_public_transport_url'), sortable: false },
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
  { key: 'active', label: tr('admin_locations_columns_active'), sortable: true },
])

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

// Event form state
const eventForm = reactive({
  id: null,
  title: '',
  city: '',
  public_transport_url: '',
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

function setError(msg, opts = {}) {
  if (opts.auto) {
    const now = Date.now()
    if (now - lastAutoErrorAt.value < 30000) return
    lastAutoErrorAt.value = now
  }
  error.value = msg; message.value = ''
}
function setMessage(msg) { message.value = msg; error.value = '' }

// Event functions
async function loadEvents(opts = {}) {
  if (!apiKey.value) { setError(tr('api_key_missing'), opts); return }
  loading.value = true
  try {
    const res = await adminFetch('events', {}, { apiKeyRef: apiKey, routePrefixRef: routePrefix })
    const data = await res.text()
    if (!res.ok) throw new Error(data)
    events.value = data ? JSON.parse(data) : []
    if (!opts.auto) setMessage(tr('updated'))
  } catch (e) { setError(e.message || String(e), opts) } finally { loading.value = false }
}

function resetEventForm() {
  eventForm.id = null
  eventForm.title = ''
  eventForm.city = ''
  eventForm.public_transport_url = ''
  eventForm.start_at = ''
  eventForm.end_at = ''
  eventForm.location_id = null
  eventForm.capacity_override = null
  eventForm.active = true
  eventForm.notes = ''
}

function editEvent(ev) {
  eventForm.id = ev.id
  eventForm.title = ev.title
  eventForm.city = ev.city || ''
  eventForm.public_transport_url = ev.public_transport_url || ''
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
      city: eventForm.city || null,
      public_transport_url: eventForm.public_transport_url || null,
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
    locations.value = data ? JSON.parse(data) : []
    if (!opts.auto && activeTab.value === 'locations') setMessage(tr('updated'))
  } catch (e) { setError(e.message || String(e), opts) } finally { loading.value = false }
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
}

function editLocation(loc) {
  locationForm.id = loc.id
  locationForm.name = loc.name || ''
  locationForm.city = loc.city || ''
  locationForm.address = loc.address || ''
  locationForm.url = loc.url || ''
  locationForm.latitude = loc.latitude
  locationForm.longitude = loc.longitude
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
    <div class="tabs">
      <button 
        :class="{ active: activeTab === 'events' }" 
        @click="activeTab = 'events'"
      >
        {{ tr('admin_events_title') }}
      </button>
      <button 
        :class="{ active: activeTab === 'locations' }" 
        @click="activeTab = 'locations'"
      >
        {{ tr('admin_locations_title') }}
      </button>
    </div>

    <!-- Events Tab -->
    <div v-if="activeTab === 'events'" class="tab-content">
      <div class="controls">
        <IconButton icon="save" :label="tr('admin_events_save_button')" @click="saveEvent" :disabled="loading" />
        <IconButton v-if="eventForm.id" icon="x" :label="tr('admin_events_new_button')" variant="ghost" @click="resetEventForm" />
      </div>
      <div v-if="error" class="error">{{ error }}</div>
      <div v-if="message" class="message">{{ message }}</div>

      <div class="form-grid">
        <label> {{ tr('admin_events_columns_title') }} <input v-model="eventForm.title" /></label>
        <label> {{ tr('admin_events_city_label') }} <input v-model="eventForm.city" /></label>
        <label> {{ tr('admin_events_columns_public_transport_url') }} {{ tr('admin_events_columns_public_transport_url_hint') }} <input v-model="eventForm.public_transport_url" :placeholder="tr('admin_events_public_transport_placeholder')" /></label>
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
        <template #cell-city="{ row }">{{ row.city ? row.city + (row.location_id ? ' – ' + (locations.find(l => l.id === row.location_id)?.name || '') : row.location) : (row.location || '') }}</template>
        <template #cell-url="{ value }">
          <a v-if="value" :href="value" target="_blank" rel="noopener">{{ tr('admin_events_link_text') }}</a>
          <span v-else>{{ tr('admin_events_dash_text') }}</span>
        </template>
        <template #cell-public_transport_url="{ value }">{{ value || '–' }}</template>
        <template #cell-active="{ value }">{{ value ? tr('admin_events_yes_text') : tr('admin_events_no_text') }}</template>
        <template #row-actions="{ row }">
          <IconButton icon="pencil" :label="tr('admin_events_edit_button')" variant="ghost" @click="editEvent(row)" />
          <IconButton icon="trash" :label="tr('admin_events_delete_button')" variant="danger" @click="removeEvent(row.id)" />
        </template>
      </AdminDataTable>
    </div>

    <!-- Locations Tab -->
    <div v-if="activeTab === 'locations'" class="tab-content">
      <div class="controls">
        <IconButton icon="save" :label="tr('admin_events_save_button')" @click="saveLocation" :disabled="loading" />
        <IconButton v-if="locationForm.id" icon="x" :label="tr('admin_events_new_button')" variant="ghost" @click="resetLocationForm" />
      </div>
      <div v-if="error" class="error">{{ error }}</div>
      <div v-if="message" class="message">{{ message }}</div>

      <div class="form-grid">
        <label> {{ tr('admin_locations_columns_name') }} * <input v-model="locationForm.name" /></label>
        <label> {{ tr('admin_locations_columns_city') }} <input v-model="locationForm.city" /></label>
        <label> {{ tr('admin_locations_columns_address') }} <input v-model="locationForm.address" :placeholder="tr('admin_locations_address_placeholder')" /></label>
        <label> {{ tr('admin_locations_url_label') }} <input v-model="locationForm.url" type="url" :placeholder="tr('admin_locations_url_placeholder')" /></label>
        <label> {{ tr('admin_locations_columns_contact_email') }} (optional) <input v-model="locationForm.contact_email" type="email" :placeholder="tr('admin_locations_email_placeholder')" /></label>
        <label> {{ tr('admin_locations_columns_public_transport') }} <input v-model="locationForm.public_transport" :placeholder="tr('admin_events_public_transport_placeholder')" /></label>
        <label> {{ tr('admin_locations_columns_capacity') }} (optional) <input v-model.number="locationForm.capacity_override" type="number" min="0" /></label>
        <label class="checkbox-row"><input type="checkbox" v-model="locationForm.active" /> {{ tr('admin_locations_columns_active') }}</label>
        <label style="grid-column: 1 / -1"> {{ tr('admin_locations_columns_notes') }} <textarea v-model="locationForm.notes" rows="3"></textarea></label>
      </div>

      <!-- Latitude/Longitude read-only fields -->
      <div class="form-grid">
        <label> {{ tr('admin_locations_latitude_readonly') }} <input type="text" :value="locationForm.latitude !== null ? locationForm.latitude.toFixed(6) : ''" readonly /></label>
        <label> {{ tr('admin_locations_longitude_readonly') }} <input type="text" :value="locationForm.longitude !== null ? locationForm.longitude.toFixed(6) : ''" readonly /></label>
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
        :initial-hidden-columns="['id']"
        :empty-text="tr('admin_locations_no_locations_text')"
        @refresh="loadLocations"
        @auto-refresh="loadLocations({ auto: true })"
      >
        <template #actions>
          <IconButton icon="trash" variant="danger" :label="tr('admin_events_delete_selection_button')" @click="bulkRemoveLocations" :disabled="loading || !selectedLocations.length" />
        </template>
        <template #cell-contact_email="{ value }">{{ value || tr('admin_events_dash_text') }}</template>
        <template #cell-active="{ value }">{{ value ? tr('admin_events_yes_text') : tr('admin_events_no_text') }}</template>
        <template #row-actions="{ row }">
          <IconButton icon="pencil" :label="tr('admin_events_edit_button')" variant="ghost" @click="editLocation(row)" />
          <IconButton icon="trash" :label="tr('admin_events_delete_button')" variant="danger" @click="removeLocation(row.id)" />
        </template>
      </AdminDataTable>

      <!-- Map with all locations -->
      <div v-if="activeTab === 'locations'" class="map-section">
        <h3>{{ tr('admin_locations_map_title') }}</h3>
        <OpenStreetMap
          :initial-lat="null"
          :initial-lng="null"
          :locations="locations"
          @update:lat="(val) => locationForm.latitude = val"
          @update:lng="(val) => locationForm.longitude = val"
        />
      </div>
    </div>
  </div>
</template>

<style scoped>
.stack { display: flex; flex-direction: column; gap: 0.75rem; }
.tabs { display: flex; gap: 0.5rem; border-bottom: 2px solid #e5e7eb; padding-bottom: 0.5rem; }
.tabs button {
  padding: 0.5rem 1rem;
  border: none;
  background: transparent;
  color: #6b7280;
  font-weight: 600;
  cursor: pointer;
  border-bottom: 2px solid transparent;
}
.tabs button.active { color: #2563eb; border-bottom-color: #2563eb; }
.tab-content { display: flex; flex-direction: column; gap: 0.75rem; }
.controls { display: flex; gap: 0.5rem; flex-wrap: wrap; }
.form-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(240px, 1fr)); gap: 0.5rem; }
label { display: flex; flex-direction: column; gap: 0.15rem; font-weight: 600; }
label select { padding: 0.5rem; border: 1px solid #d1d5db; border-radius: 6px; font: inherit; }
.checkbox-row { flex-direction: row; align-items: center; gap: 0.5rem; font-weight: 600; }
input, textarea { font: inherit; padding: 0.5rem; border: 1px solid #d1d5db; border-radius: 6px; }
button { padding: 0.35rem 0.6rem; border: 1px solid #d1d5db; border-radius: 6px; cursor: pointer; background: #2563eb; color: #fff; }
button.ghost { background: #eef2ff; color: #1d4ed8; border-color: #c7d2fe; }
button.danger { background: #dc2626; color: #fff; border-color: #dc2626; }
.error { color: #991b1b; background: #fef2f2; border: 1px solid #fecaca; padding: 0.5rem; border-radius: 6px; }
.message { color: #065f46; background: #ecfdf3; border: 1px solid #a7f3d0; padding: 0.5rem; border-radius: 6px; }
</style>
