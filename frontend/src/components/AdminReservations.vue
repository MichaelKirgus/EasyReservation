<script setup>
import { ref, onMounted, onUnmounted, watch } from 'vue'
import IconButton from './IconButton.vue'
import AdminDataTable from './AdminDataTable.vue'

const apiBase = import.meta.env.VITE_API_BASE || '/api'
const apiKey = ref(localStorage.getItem('admin_api_key') || '')
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

const reservationColumns = [
  { key: 'id', label: 'ID', sortable: true },
  { key: 'display_name', label: 'Name', sortable: true },
  { key: 'email', label: 'E-Mail', sortable: true },
  { key: 'date_added', label: 'Datum', sortable: true },
  { key: 'payload', label: 'Zusatzfelder', sortable: false },
]

const waitlistColumns = [
  { key: 'id', label: 'ID', sortable: true },
  { key: 'display_name', label: 'Name', sortable: true },
  { key: 'email', label: 'E-Mail', sortable: true },
  { key: 'date_added', label: 'Datum', sortable: true },
  { key: 'status', label: 'Status', sortable: true },
]

const validationColumns = [
  { key: 'id', label: 'ID', sortable: true },
  { key: 'type', label: 'Typ', sortable: true },
  { key: 'display_name', label: 'Name', sortable: true },
  { key: 'email', label: 'E-Mail', sortable: true },
  { key: 'status', label: 'Status', sortable: true },
]

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

function authHeaders() {
  return { 'Content-Type': 'application/json', 'Accept': 'application/json', 'X-Api-Key': apiKey.value }
}

function buildUrl(relative) {
  return `${apiBase}/${routePrefix.value}/${relative}`
}

async function apiFetch(relative, opts = {}, triedFallback = false) {
  const res = await fetch(buildUrl(relative), { ...opts, headers: authHeaders() })
  if (res.status === 403 && !triedFallback && routePrefix.value === 'admin') {
    routePrefix.value = 'moderator'
    localStorage.setItem('admin_route_prefix', 'moderator')
    return apiFetch(relative, opts, true)
  }
  return res
}

function notifyQuery() {
  return `notify=${notifyOnChange.value ? 1 : 0}`
}

async function loadNotifyDefaults() {
  if (!apiKey.value) return
  try {
    const res = await apiFetch('notification-defaults')
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
    throw new Error('Payload muss ein Objekt sein')
  } catch (e) {
    throw new Error(`Ungültiges JSON: ${e.message}`)
  }
}

async function load(opts = {}) {
  if (!apiKey.value) { setError('Bitte anmelden, API-Key fehlt.', opts); return }
  loading.value = true
  try {
    const res = await apiFetch('reservations')
    if (!res.ok) throw new Error(await res.text())
    data.value = await res.json()
    localStorage.setItem('admin_api_key', apiKey.value)
    localStorage.setItem('admin_notify_on_change', notifyOnChange.value ? '1' : '0')
    if (!opts.auto) setMessage('')
  } catch (e) {
    setError(`Fehler beim Laden: ${e}`, opts)
  } finally { loading.value = false }
}

async function loadWaitlist(opts = {}) {
  if (!apiKey.value) { setError('Bitte anmelden, API-Key fehlt.', opts); return }
  waitlistLoading.value = true
  try {
    const res = await apiFetch('waitlist')
    if (!res.ok) throw new Error(await res.text())
    waitlist.value = await res.json()
  } catch (e) {
    setError(`Fehler beim Laden der Warteliste: ${e}`, opts)
  } finally {
    waitlistLoading.value = false
  }
}

async function loadValidations(opts = {}) {
  if (!apiKey.value) { setError('Bitte anmelden, API-Key fehlt.', opts); return }
  validationLoading.value = true
  try {
    const res = await apiFetch('email-validations?status=pending')
    if (!res.ok) throw new Error(await res.text())
    validations.value = await res.json()
  } catch (e) {
    setError(`Fehler beim Laden der Validierungen: ${e}`, opts)
  } finally {
    validationLoading.value = false
  }
}

async function reloadAll(opts = {}) {
  await Promise.all([load(opts), loadWaitlist(opts), loadValidations(opts)])
}

async function removeItem(id) {
  if (!confirm('Eintrag löschen?')) return
  loading.value = true
  try {
    const res = await apiFetch(`reservations/${id}?${notifyQuery()}`, { method: 'DELETE' })
    const text = await res.text()
    if (!res.ok) throw new Error(text)
    data.value = data.value.filter(r => r.id !== id)
    setMessage('Eintrag gelöscht.')
    await reloadAll()
  } catch (e) {
    setError(`Löschen fehlgeschlagen: ${e}`)
  } finally { loading.value = false }
}

async function bulkDeleteReservations() {
  if (!selectedReservations.value.length) return
  if (!confirm(`Ausgewählte Reservierungen (${selectedReservations.value.length}) löschen?`)) return
  loading.value = true
  try {
    for (const id of selectedReservations.value) {
      const res = await apiFetch(`reservations/${id}?${notifyQuery()}`, { method: 'DELETE' })
      const text = await res.text()
      if (!res.ok) throw new Error(text)
    }
    selectedReservations.value = []
    setMessage('Ausgewählte Reservierungen gelöscht.')
    await reloadAll()
  } catch (e) {
    setError(`Löschen fehlgeschlagen: ${e}`)
  } finally { loading.value = false }
}

async function clearReservations() {
  if (!data.value.length) return
  if (!confirm('Alle Teilnehmer-Einträge löschen?')) return
  loading.value = true
  try {
    for (const r of data.value) {
      await apiFetch(`reservations/${r.id}?${notifyQuery()}`, { method: 'DELETE' })
    }
    selectedReservations.value = []
    setMessage('Teilnehmerliste geleert.')
    await reloadAll()
  } catch (e) {
    setError(`Leeren fehlgeschlagen: ${e}`)
  } finally { loading.value = false }
}

function exportCsv() {
  if (!apiKey.value) { setError('Bitte anmelden, API-Key fehlt.'); return }
  const url = `${apiBase}/${routePrefix.value}/export?api_key=${encodeURIComponent(apiKey.value)}`
  window.open(url, '_blank')
}

function exportWaitlistCsv() {
  if (!apiKey.value) { setError('Bitte anmelden, API-Key fehlt.'); return }
  const url = `${apiBase}/${routePrefix.value}/waitlist/export?api_key=${encodeURIComponent(apiKey.value)}`
  window.open(url, '_blank')
}

function handleKeyUpdate(e) {
  apiKey.value = e.detail || ''
}

async function saveReservation(r) {
  if (!apiKey.value) { setError('Bitte anmelden, API-Key fehlt.'); return }
  loading.value = true
  try {
    const res = await apiFetch(`reservations/${r.id}?${notifyQuery()}`, {
      method: 'PATCH',
      body: JSON.stringify({ name: r.display_name, email: r.email || '' }),
    })
    const text = await res.text()
    if (!res.ok) throw new Error(text)
    setMessage('Reservierung aktualisiert.')
  } catch (e) {
    setError(`Fehler beim Speichern: ${e}`)
  } finally { loading.value = false }
}

async function saveEmail(r) {
  return saveReservation(r)
}

async function removeWaitlistEntry(id) {
  if (!confirm('Wartelisten-Eintrag löschen?')) return
  waitlistLoading.value = true
  try {
    const res = await apiFetch(`waitlist/${id}`, { method: 'DELETE' })
    const text = await res.text()
    if (!res.ok) throw new Error(text)
    waitlist.value = waitlist.value.filter(w => w.id !== id)
    setMessage('Wartelisten-Eintrag gelöscht.')
  } catch (e) {
    setError(`Löschen fehlgeschlagen: ${e}`)
  } finally {
    waitlistLoading.value = false
  }
}

async function bulkDeleteWaitlist() {
  if (!selectedWaitlist.value.length) return
  if (!confirm(`Ausgewählte Wartelisten-Einträge (${selectedWaitlist.value.length}) löschen?`)) return
  waitlistLoading.value = true
  try {
    for (const id of selectedWaitlist.value) {
      const res = await apiFetch(`waitlist/${id}`, { method: 'DELETE' })
      const text = await res.text()
      if (!res.ok) throw new Error(text)
    }
    selectedWaitlist.value = []
    setMessage('Ausgewählte Wartelisten-Einträge gelöscht.')
    await reloadAll()
  } catch (e) {
    setError(`Löschen fehlgeschlagen: ${e}`)
  } finally { waitlistLoading.value = false }
}

async function clearWaitlist() {
  if (!waitlist.value.length) return
  if (!confirm('Alle Wartelisten-Einträge löschen?')) return
  waitlistLoading.value = true
  try {
    for (const w of waitlist.value) {
      await apiFetch(`waitlist/${w.id}`, { method: 'DELETE' })
    }
    selectedWaitlist.value = []
    setMessage('Warteliste geleert.')
    await reloadAll()
  } catch (e) {
    setError(`Leeren fehlgeschlagen: ${e}`)
  } finally { waitlistLoading.value = false }
}

async function approveValidation(id) {
  if (!apiKey.value) { setError('Bitte anmelden, API-Key fehlt.'); return }
  validationLoading.value = true
  try {
    const res = await apiFetch(`email-validations/${id}/approve`, { method: 'POST' })
    const text = await res.text()
    if (!res.ok) throw new Error(text)
    setMessage('Validierung freigegeben.')
    await reloadAll()
  } catch (e) {
    setError(`Freigabe fehlgeschlagen: ${e}`)
  } finally {
    validationLoading.value = false
  }
}

async function bulkApproveValidations() {
  if (!selectedValidations.value.length) return
  if (!confirm(`Ausgewählte Validierungen (${selectedValidations.value.length}) freigeben?`)) return
  validationLoading.value = true
  try {
    for (const id of selectedValidations.value) {
      const res = await apiFetch(`email-validations/${id}/approve`, { method: 'POST' })
      const text = await res.text()
      if (!res.ok) throw new Error(text)
    }
    selectedValidations.value = []
    setMessage('Ausgewählte Validierungen freigegeben.')
    await reloadAll()
  } catch (e) {
    setError(`Freigabe fehlgeschlagen: ${e}`)
  } finally {
    validationLoading.value = false
  }
}

async function discardValidation(id) {
  if (!confirm('Validierung verwerfen?')) return
  validationLoading.value = true
  try {
    const res = await apiFetch(`email-validations/${id}`, { method: 'DELETE' })
    const text = await res.text()
    if (!res.ok) throw new Error(text)
    setMessage('Validierung verworfen.')
    validations.value = validations.value.filter(v => v.id !== id)
  } catch (e) {
    setError(`Verwerfen fehlgeschlagen: ${e}`)
  } finally {
    validationLoading.value = false
  }
}

async function bulkDiscardValidations() {
  if (!selectedValidations.value.length) return
  if (!confirm(`Ausgewählte Validierungen (${selectedValidations.value.length}) verwerfen?`)) return
  validationLoading.value = true
  try {
    for (const id of selectedValidations.value) {
      const res = await apiFetch(`email-validations/${id}`, { method: 'DELETE' })
      const text = await res.text()
      if (!res.ok) throw new Error(text)
    }
    selectedValidations.value = []
    setMessage('Ausgewählte Validierungen verworfen.')
    await reloadAll()
  } catch (e) {
    setError(`Verwerfen fehlgeschlagen: ${e}`)
  } finally {
    validationLoading.value = false
  }
}

async function clearValidations() {
  if (!validations.value.length) return
  if (!confirm('Alle offenen Validierungen löschen?')) return
  validationLoading.value = true
  try {
    for (const v of validations.value) {
      await apiFetch(`email-validations/${v.id}`, { method: 'DELETE' })
    }
    selectedValidations.value = []
    setMessage('Validierungen geleert.')
    await reloadAll()
  } catch (e) {
    setError(`Leeren fehlgeschlagen: ${e}`)
  } finally { validationLoading.value = false }
}

const statusLabels = {
  email_pending: 'E-Mail versendet – wartet auf Klick',
  waiting_admin: 'Vom Nutzer bestätigt – wartet auf Freigabe',
  ready: 'Bereit für Verarbeitung',
  completed: 'Abgeschlossen',
  expired: 'Abgelaufen',
  failed: 'Fehlgeschlagen',
  cancelled: 'Abgebrochen',
}

function statusLabel(status) {
  return statusLabels[status] || status
}

async function resendValidation(id) {
  if (!apiKey.value) { setError('Bitte anmelden, API-Key fehlt.'); return }
  validationLoading.value = true
  try {
    const res = await apiFetch(`email-validations/${id}/resend`, { method: 'POST' })
    const text = await res.text()
    if (!res.ok) throw new Error(text)
    setMessage('Validierungs-E-Mail erneut gesendet.')
  } catch (e) {
    setError(`Erneut senden fehlgeschlagen: ${e}`)
  } finally {
    validationLoading.value = false
  }
}

watch(notifyOnChange, (val) => localStorage.setItem('admin_notify_on_change', val ? '1' : '0'))

onMounted(() => {
  window.addEventListener('api-key-updated', handleKeyUpdate)
  if (apiKey.value) {
    loadNotifyDefaults()
    reloadAll()
  }
})

onUnmounted(() => {
  window.removeEventListener('api-key-updated', handleKeyUpdate)
})

async function createReservation() {
  if (!apiKey.value) { setError('Bitte anmelden, API-Key fehlt.'); return }
  const payload = (() => {
    try { return parsePayload(newReservation.value.payloadJson) } catch (e) { setError(e.message); return null }
  })()
  if (payload === null) return
  loading.value = true
  try {
    const body = { name: newReservation.value.name, email: newReservation.value.email }
    if (payload !== undefined) body.payload = payload
    const res = await apiFetch(`reservations?${notifyQuery()}`, { method: 'POST', body: JSON.stringify(body) })
    const text = await res.text()
    if (!res.ok) throw new Error(text)
    const created = JSON.parse(text)
    data.value = [created, ...data.value]
    newReservation.value = { name: '', email: '', payloadJson: '' }
    setMessage('Reservierung angelegt.')
  } catch (e) {
    setError(`Anlegen fehlgeschlagen: ${e}`)
  } finally { loading.value = false }
}

async function createWaitlistEntry() {
  if (!apiKey.value) { setError('Bitte anmelden, API-Key fehlt.'); return }
  const payload = (() => {
    try { return parsePayload(newWaitlist.value.payloadJson) } catch (e) { setError(e.message); return null }
  })()
  if (payload === null) return
  waitlistLoading.value = true
  try {
    const body = { name: newWaitlist.value.name, email: newWaitlist.value.email }
    if (payload !== undefined) body.payload = payload
    const res = await apiFetch('waitlist', { method: 'POST', body: JSON.stringify(body) })
    const text = await res.text()
    if (!res.ok) throw new Error(text)
    const created = JSON.parse(text)
    waitlist.value = [...waitlist.value, created]
    newWaitlist.value = { name: '', email: '', payloadJson: '' }
    setMessage('Auf Warteliste gesetzt.')
  } catch (e) {
    setError(`Anlegen fehlgeschlagen: ${e}`)
  } finally { waitlistLoading.value = false }
}

async function updateWaitlistEntry(entry) {
  if (!apiKey.value) { setError('Bitte anmelden, API-Key fehlt.'); return }
  waitlistLoading.value = true
  try {
    const res = await apiFetch(`waitlist/${entry.id}`, { method: 'PATCH', body: JSON.stringify({ name: entry.display_name, email: entry.email || '' }) })
    const text = await res.text()
    if (!res.ok) throw new Error(text)
    setMessage('Wartelisten-Eintrag aktualisiert.')
  } catch (e) {
    setError(`Aktualisieren fehlgeschlagen: ${e}`)
  } finally {
    waitlistLoading.value = false
  }
}

async function promoteWaitlistEntry(id) {
  if (!apiKey.value) { setError('Bitte anmelden, API-Key fehlt.'); return }
  waitlistLoading.value = true
  try {
    const res = await apiFetch(`waitlist/${id}/promote`, { method: 'POST' })
    const text = await res.text()
    if (!res.ok) throw new Error(text)
    setMessage('Wartelisten-Eintrag befördert.')
    await reloadAll()
  } catch (e) {
    setError(`Befördern fehlgeschlagen: ${e}`)
  } finally { waitlistLoading.value = false }
}
</script>

<template>
  <div class="stack">
    <div class="top-bar">
      <div class="left-actions">
        <label class="inline"><input type="checkbox" v-model="notifyOnChange" /> E-Mail an Teilnehmer senden</label>
      </div>
      <div class="right-actions">
        <IconButton icon="refresh" label="Aktualisieren" @click="reloadAll" :disabled="loading || waitlistLoading || validationLoading" />
      </div>
    </div>
    <div v-if="message" class="message">{{ message }}</div>
    <div v-if="error" class="error">{{ error }}</div>

    <div class="card">
      <div class="card-header">
        <h3>Teilnehmerliste</h3>
      </div>
      <div class="inline-fields">
        <input v-model="newReservation.name" placeholder="Name" />
        <input v-model="newReservation.email" placeholder="E-Mail" />
        <input v-model="newReservation.payloadJson" placeholder="Payload (JSON, optional)" />
        <IconButton icon="plus" label="Reservierung hinzufügen" @click="createReservation" :disabled="loading" />
      </div>
    </div>

    <AdminDataTable
      :columns="reservationColumns"
      :rows="data"
      v-model="selectedReservations"
      selectable
      :loading="loading"
      :page-size="20"
      :initial-hidden-columns="['payload']"
      persist-key="admin-reservations"
      @refresh="reloadAll"
      @auto-refresh="reloadAll({ auto: true })"
      empty-text="Keine Reservierungen."
    >
      <template #actions>
        <IconButton icon="download" label="Teilnehmer exportieren (CSV)" @click="exportCsv" :disabled="loading" />
        <IconButton icon="trash" variant="danger" label="Auswahl löschen" @click="bulkDeleteReservations" :disabled="loading || !selectedReservations.length" />
        <IconButton variant="danger" icon="trash2" label="Alle löschen" @click="clearReservations" :disabled="loading || !data.length" />
      </template>
      <template #cell-display_name="{ row }">
        <input v-model="row.display_name" @change="saveReservation(row)" />
      </template>
      <template #cell-email="{ row }">
        <input v-model="row.email" @change="saveEmail(row)" />
      </template>
      <template #cell-date_added="{ value }">{{ formatDateTime(value) }}</template>
      <template #cell-payload="{ row }">
        <pre class="payload" v-if="row.payload">{{ JSON.stringify(row.payload, null, 2) }}</pre><span v-else>–</span>
      </template>
      <template #row-actions="{ row }">
        <IconButton class="danger" variant="danger" icon="trash" label="Löschen" @click="removeItem(row.id)" />
      </template>
    </AdminDataTable>

    <div class="waitlist">
      <div class="card">
        <div class="card-header">
          <h3>Warteliste</h3>
        </div>
        <div class="inline-fields">
          <input v-model="newWaitlist.name" placeholder="Name" />
          <input v-model="newWaitlist.email" placeholder="E-Mail" />
          <input v-model="newWaitlist.payloadJson" placeholder="Payload (JSON, optional)" />
          <IconButton icon="plus" label="Auf Warteliste setzen" @click="createWaitlistEntry" :disabled="waitlistLoading" />
        </div>
      </div>
      <AdminDataTable
        :columns="waitlistColumns"
        :rows="waitlist"
        v-model="selectedWaitlist"
        selectable
        :loading="waitlistLoading"
        :page-size="20"
        persist-key="admin-waitlist"
        @refresh="loadWaitlist"
        @auto-refresh="loadWaitlist({ auto: true })"
        empty-text="Keine Einträge in der Warteliste."
      >
        <template #actions>
          <IconButton icon="download" label="Warteliste exportieren (CSV)" @click="exportWaitlistCsv" :disabled="waitlistLoading" />
          <IconButton icon="trash" variant="danger" label="Auswahl löschen" @click="bulkDeleteWaitlist" :disabled="waitlistLoading || !selectedWaitlist.length" />
          <IconButton variant="danger" icon="trash2" label="Alle löschen" @click="clearWaitlist" :disabled="waitlistLoading || !waitlist.length" />
        </template>
        <template #cell-display_name="{ row }">
          <input v-model="row.display_name" @change="updateWaitlistEntry(row)" />
        </template>
        <template #cell-email="{ row }">
          <input v-model="row.email" @change="updateWaitlistEntry(row)" />
        </template>
        <template #cell-date_added="{ value }">{{ formatDateTime(value) }}</template>
        <template #cell-status="{ value }">{{ value }}</template>
        <template #row-actions="{ row }">
          <IconButton icon="arrowUp" label="Befördern" @click="promoteWaitlistEntry(row.id)" :disabled="waitlistLoading || row.status !== 'pending'" />
          <IconButton variant="danger" icon="trash" label="Löschen" @click="removeWaitlistEntry(row.id)" :disabled="waitlistLoading" />
        </template>
      </AdminDataTable>
    </div>

    <div class="card">
      <div class="card-header">
        <h3>Validierung (E-Mail / Admin)</h3>
      </div>
      <p v-if="validationLoading">Lade Validierungen...</p>
      <AdminDataTable
        v-else
        :columns="validationColumns"
        :rows="validations"
        v-model="selectedValidations"
        selectable
        :loading="validationLoading"
        :page-size="20"
        persist-key="admin-validations"
        @refresh="loadValidations"
        @auto-refresh="loadValidations({ auto: true })"
        empty-text="Keine offenen Validierungen."
      >
        <template #actions>
          <IconButton icon="refresh" label="Aktualisieren" @click="loadValidations" :disabled="validationLoading" />
          <IconButton icon="trash" variant="danger" label="Auswahl verwerfen" @click="bulkDiscardValidations" :disabled="validationLoading || !selectedValidations.length" />
          <IconButton icon="check" label="Auswahl freigeben" @click="bulkApproveValidations" :disabled="validationLoading || !selectedValidations.length" />
          <IconButton variant="danger" icon="trash2" label="Alle löschen" @click="clearValidations" :disabled="validationLoading || !validations.length" />
        </template>
        <template #cell-status="{ value }">{{ statusLabel(value) }}</template>
        <template #row-actions="{ row }">
          <IconButton icon="check" label="Freigeben" @click="approveValidation(row.id)" :disabled="validationLoading" />
          <IconButton icon="mail" label="E-Mail erneut senden" @click="resendValidation(row.id)" :disabled="validationLoading" />
          <IconButton variant="danger" icon="trash" label="Verwerfen" @click="discardValidation(row.id)" :disabled="validationLoading" />
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
