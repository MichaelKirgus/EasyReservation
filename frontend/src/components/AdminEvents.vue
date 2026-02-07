<script setup>
import { ref, reactive, onMounted } from 'vue'
import IconButton from './IconButton.vue'
import AdminDataTable from './AdminDataTable.vue'
import { adminFetch } from '../utils/adminApi'
const apiKey = ref(localStorage.getItem('admin_api_key') || '')
const routePrefix = ref(localStorage.getItem('admin_route_prefix') || 'admin')
const loading = ref(false)
const error = ref('')
const message = ref('')
const events = ref([])
const selectedEvents = ref([])
const lastAutoErrorAt = ref(0)

const eventColumns = [
  { key: 'id', label: 'ID', sortable: true },
  { key: 'title', label: 'Titel', sortable: true },
  { key: 'start_at', label: 'Start', sortable: true },
  { key: 'city', label: 'Ort', sortable: true },
  { key: 'url', label: 'URL', sortable: false },
  { key: 'public_transport_url', label: 'ÖPNV', sortable: false },
  { key: 'active', label: 'Aktiv', sortable: true },
]

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

const form = reactive({
  id: null,
  title: '',
  city: '',
  url: '',
  public_transport_url: '',
  start_at: '',
  end_at: '',
  location: '',
  capacity_override: null,
  active: true,
  notes: '',
})

function setError(msg, opts = {}) {
  if (opts.auto) {
    const now = Date.now()
    if (now - lastAutoErrorAt.value < 30000) return
    lastAutoErrorAt.value = now
  }
  error.value = msg; message.value = ''
}
function setMessage(msg) { message.value = msg; error.value = '' }

async function load(opts = {}) {
  if (!apiKey.value) { setError('API-Key fehlt.', opts); return }
  loading.value = true
  try {
    const res = await adminFetch('events', {}, { apiKeyRef: apiKey, routePrefixRef: routePrefix })
    const data = await res.text()
    if (!res.ok) throw new Error(data)
    events.value = data ? JSON.parse(data) : []
    if (!opts.auto) setMessage('Aktualisiert.')
  } catch (e) { setError(e.message || String(e), opts) } finally { loading.value = false }
}

function resetForm() {
  form.id = null
  form.title = ''
  form.city = ''
  form.url = ''
  form.public_transport_url = ''
  form.start_at = ''
  form.end_at = ''
  form.location = ''
  form.capacity_override = null
  form.active = true
  form.notes = ''
}

function editEvent(ev) {
  form.id = ev.id
  form.title = ev.title
  form.city = ev.city || ''
  form.url = ev.url || ''
  form.public_transport_url = ev.public_transport_url || ''
  function toLocalDatetime(val) {
    if (!val) return ''
    const d = new Date(val)
    if (Number.isNaN(d.getTime())) return ''
    const pad = n => n.toString().padStart(2, '0')
    return `${d.getFullYear()}-${pad(d.getMonth()+1)}-${pad(d.getDate())}T${pad(d.getHours())}:${pad(d.getMinutes())}`
  }
  form.start_at = toLocalDatetime(ev.start_at)
  form.end_at = toLocalDatetime(ev.end_at)
  form.location = ev.location || ''
  form.capacity_override = ev.capacity_override
  form.active = !!ev.active
  form.notes = ev.notes || ''
}

async function save() {
  if (!apiKey.value) { setError('API-Key fehlt.'); return }
  if (!form.title || !form.start_at) { setError('Titel und Startzeit erforderlich.'); return }
  loading.value = true
  try {
    const payload = {
      title: form.title,
      city: form.city || null,
      url: form.url || null,
      public_transport_url: form.public_transport_url || null,
      start_at: form.start_at,
      end_at: form.end_at || null,
      location: form.location || null,
      capacity_override: form.capacity_override === '' ? null : form.capacity_override,
      active: form.active ? 1 : 0,
      notes: form.notes || null,
      auto_close_minutes_before: null,
      auto_email_template_id: null,
      auto_email_offset_minutes_before: null,
      auto_email_sent_at: null,
    }
    if (form.id) {
      const res = await adminFetch(`events/${form.id}`, { method: 'PATCH', body: JSON.stringify(payload) }, { apiKeyRef: apiKey, routePrefixRef: routePrefix })
      const text = await res.text(); if (!res.ok) throw new Error(text)
      setMessage('Termin aktualisiert.')
    } else {
      const res = await adminFetch('events', { method: 'POST', body: JSON.stringify(payload) }, { apiKeyRef: apiKey, routePrefixRef: routePrefix })
      const text = await res.text(); if (!res.ok) throw new Error(text)
      setMessage('Termin angelegt.')
    }
    resetForm()
    await load()
  } catch (e) { setError(e.message || String(e)) } finally { loading.value = false }
}

async function remove(id) {
  if (!apiKey.value) { setError('API-Key fehlt.'); return }
  if (!confirm('Termin wirklich löschen?')) return
  loading.value = true
  try {
    const res = await adminFetch(`events/${id}`, { method: 'DELETE' }, { apiKeyRef: apiKey, routePrefixRef: routePrefix })
    const text = await res.text(); if (!res.ok) throw new Error(text)
    setMessage('Gelöscht.')
    await load()
  } catch (e) { setError(e.message || String(e)) } finally { loading.value = false }
}

async function bulkRemove() {
  if (!selectedEvents.value.length) return
  if (!confirm(`Ausgewählte Termine (${selectedEvents.value.length}) löschen?`)) return
  loading.value = true
  try {
    for (const id of selectedEvents.value) {
      const res = await adminFetch(`events/${id}`, { method: 'DELETE' }, { apiKeyRef: apiKey, routePrefixRef: routePrefix })
      const text = await res.text(); if (!res.ok) throw new Error(text)
    }
    selectedEvents.value = []
    setMessage('Ausgewählte Termine gelöscht.')
    await load()
  } catch (e) { setError(e.message || String(e)) } finally { loading.value = false }
}

onMounted(() => {
  window.addEventListener('api-key-updated', e => { apiKey.value = e.detail || '' })
  if (apiKey.value) load()
})
</script>

<template>
  <div class="stack">
    <div class="controls">
      <IconButton icon="save" label="Speichern" @click="save" :disabled="loading" />
      <IconButton v-if="form.id" icon="x" label="Neu" variant="ghost" @click="resetForm" />
    </div>
    <div v-if="error" class="error">{{ error }}</div>
    <div v-if="message" class="message">{{ message }}</div>

    <div class="form-grid">
      <label> Titel <input v-model="form.title" /></label>
      <label> Stadt <input v-model="form.city" /></label>
      <label> URL (optional) <input v-model="form.url" placeholder="https://..." /></label>
      <label> ÖPNV / Haltestellen (Text) <input v-model="form.public_transport_url" placeholder="z.B. Haltestellen, Linien" /></label>
      <label> Start (Datum/Zeit) <input v-model="form.start_at" type="datetime-local" /></label>
      <label> Ende (optional) <input v-model="form.end_at" type="datetime-local" /></label>
      <label> Ort <input v-model="form.location" /></label>
      <label> Kapazität (optional) <input v-model.number="form.capacity_override" type="number" min="0" /></label>
      <label class="checkbox-row"><input type="checkbox" v-model="form.active" /> Aktiv</label>
      <label> Notizen <textarea v-model="form.notes" rows="3"></textarea></label>
    </div>

    <AdminDataTable
      :columns="eventColumns"
      :rows="events"
      v-model="selectedEvents"
      selectable
      :loading="loading"
      :page-size="20"
      persist-key="admin-events"
      empty-text="Keine Termine vorhanden."
      @refresh="load"
      @auto-refresh="load({ auto: true })"
    >
      <template #actions>
        <IconButton icon="trash" variant="danger" label="Auswahl löschen" @click="bulkRemove" :disabled="loading || !selectedEvents.length" />
      </template>
      <template #cell-start_at="{ value }">{{ formatDateTime(value) }}</template>
      <template #cell-city="{ row }">{{ row.city ? row.city + (row.location ? ' – ' + row.location : '') : row.location }}</template>
      <template #cell-url="{ value }">
        <a v-if="value" :href="value" target="_blank" rel="noopener">Link</a>
        <span v-else>–</span>
      </template>
      <template #cell-public_transport_url="{ value }">{{ value || '–' }}</template>
      <template #cell-active="{ value }">{{ value ? 'Ja' : 'Nein' }}</template>
      <template #row-actions="{ row }">
        <IconButton icon="pencil" label="Bearbeiten" variant="ghost" @click="editEvent(row)" />
        <IconButton icon="trash" label="Löschen" variant="danger" @click="remove(row.id)" />
      </template>
    </AdminDataTable>
  </div>
</template>

<style scoped>
.stack { display: flex; flex-direction: column; gap: 0.75rem; }
.controls { display: flex; gap: 0.5rem; flex-wrap: wrap; }
.form-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(240px, 1fr)); gap: 0.5rem; }
label { display: flex; flex-direction: column; gap: 0.15rem; font-weight: 600; }
.checkbox-row { flex-direction: row; align-items: center; gap: 0.5rem; font-weight: 600; }
input, textarea { font: inherit; padding: 0.5rem; border: 1px solid #d1d5db; border-radius: 6px; }
button { padding: 0.35rem 0.6rem; border: 1px solid #d1d5db; border-radius: 6px; cursor: pointer; background: #2563eb; color: #fff; }
button.ghost { background: #eef2ff; color: #1d4ed8; border-color: #c7d2fe; }
button.danger { background: #dc2626; color: #fff; border-color: #dc2626; }
.error { color: #991b1b; background: #fef2f2; border: 1px solid #fecaca; padding: 0.5rem; border-radius: 6px; }
.message { color: #065f46; background: #ecfdf3; border: 1px solid #a7f3d0; padding: 0.5rem; border-radius: 6px; }
</style>
