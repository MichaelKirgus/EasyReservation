<script setup>
import { ref, reactive, computed, onMounted, onUnmounted, watch } from 'vue'
import IconButton from './IconButton.vue'
import AdminDataTable from './AdminDataTable.vue'
import { adminFetch } from '../utils/adminApi'

const props = defineProps({ defaultSubTab: { type: String, default: 'send' } })
const apiKey = ref(localStorage.getItem('admin_api_key') || '')
const routePrefix = ref(localStorage.getItem('admin_route_prefix') || 'admin')
const currentUser = ref(JSON.parse(localStorage.getItem('admin_user') || sessionStorage.getItem('admin_user') || 'null'))
const templates = ref([])
const reservations = ref([])
const waitlist = ref([])
const placeholders = ref([])
const loading = ref(false)
const message = ref('')
const error = ref('')

const activeSubTab = ref(props.defaultSubTab === 'templates' ? 'templates' : 'send')

const form = reactive({
  templateId: null,
  mode: 'both',
  deduplicate: true,
  selectedReservations: [],
  selectedWaitlist: [],
  customRecipients: [{ name: '', email: '' }],
})

const templateForm = reactive({ name: '', subject: '', body: '', type: 'generic' })
const userRole = computed(() => currentUser.value?.role?.toLowerCase?.() || '')
const canManageTemplates = computed(() =>
  routePrefix.value === 'admin' || userRole.value === 'admin' || userRole.value === 'superadmin'
)

const templateColumns = [
  { key: 'id', label: 'ID', sortable: true },
  { key: 'name', label: 'Bezeichnung', sortable: true },
  { key: 'subject', label: 'Betreff', sortable: true },
  { key: 'body', label: 'Inhalt', sortable: false },
]

const reservationsWithEmail = computed(() => reservations.value.filter(r => !!r.email))
const waitlistWithEmail = computed(() => waitlist.value.filter(w => !!w.email))
const placeholderText = computed(() => placeholders.value.length ? `Platzhalter: ${placeholders.value.join(', ')}` : '')

function setMessage(msg) { message.value = msg; error.value = '' }
function setError(msg) { error.value = msg; message.value = '' }

const fetchWithAuth = (relative, opts = {}) => adminFetch(relative, opts, { apiKeyRef: apiKey, routePrefixRef: routePrefix })

async function loadTemplates() {
  const res = await fetchWithAuth('email-templates')
  if (!res.ok) throw new Error(await res.text())
  templates.value = await res.json()
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

async function loadAll() {
  if (!apiKey.value) { setError('Bitte anmelden, API-Key fehlt.'); return }
  loading.value = true
  try {
    await Promise.all([loadTemplates(), loadRecipients(), loadPlaceholders()])
    setMessage('Daten geladen.')
  } catch (e) {
    setError(`Fehler beim Laden: ${e}`)
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
  if (!apiKey.value) { setError('Bitte anmelden, API-Key fehlt.'); return }
  if (!form.templateId) { setError('Bitte Vorlage auswählen.'); return }

  const payload = {
    template_id: form.templateId,
    scope: form.mode,
    send_to_all: form.mode !== 'selection',
    deduplicate: form.deduplicate,
  }

  if (form.mode === 'selection') {
    payload.send_to_all = false
    payload.reservation_ids = form.selectedReservations.filter(Boolean)
    payload.waitlist_ids = form.selectedWaitlist.filter(Boolean)
    if ((payload.reservation_ids?.length || 0) === 0 && (payload.waitlist_ids?.length || 0) === 0 && customList().length === 0) {
      setError('Bitte mindestens einen Empfänger auswählen.')
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
    setMessage(data.message || 'E-Mails werden gesendet.')
  } catch (e) {
    setError(`Versand fehlgeschlagen: ${e}`)
  } finally {
    loading.value = false
  }
}

async function saveTemplate(tpl) {
  if (!canManageTemplates.value) { setError('Vorlagen können nur als Admin bearbeitet werden.'); return }
  try {
    const res = await fetchWithAuth(`email-templates/${tpl.id}`, {
      method: 'PATCH',
      body: JSON.stringify({ name: tpl.name, subject: tpl.subject, body: tpl.body, type: tpl.type || 'generic' }),
    })
    const text = await res.text()
    if (!res.ok) throw new Error(text)
    setMessage('Vorlage gespeichert.')
  } catch (e) {
    setError(`Speichern fehlgeschlagen: ${e}`)
  }
}

async function deleteTemplate(id) {
  if (!canManageTemplates.value) { setError('Vorlagen können nur als Admin gelöscht werden.'); return }
  if (!confirm('Vorlage löschen?')) return
  try {
    const res = await fetchWithAuth(`email-templates/${id}`, { method: 'DELETE' })
    const text = await res.text()
    if (!res.ok) throw new Error(text)
    templates.value = templates.value.filter(t => t.id !== id)
    setMessage('Vorlage gelöscht.')
  } catch (e) {
    setError(`Löschen fehlgeschlagen: ${e}`)
  }
}

async function createTemplate() {
  if (!canManageTemplates.value) { setError('Vorlagen können nur als Admin erstellt werden.'); return }
  try {
    const res = await fetchWithAuth('email-templates', {
      method: 'POST',
      body: JSON.stringify({ ...templateForm }),
    })
    const text = await res.text()
    if (!res.ok) throw new Error(text)
    setMessage('Vorlage erstellt.')
    templateForm.name = ''
    templateForm.subject = ''
    templateForm.body = ''
    templateForm.type = 'generic'
    await loadTemplates()
  } catch (e) {
    setError(`Erstellen fehlgeschlagen: ${e}`)
  }
}

function customList() {
  return form.customRecipients
    .map(r => ({ name: r.name?.trim() || '', email: r.email?.trim() || '' }))
    .filter(r => r.email)
}

onMounted(() => {
  window.addEventListener('api-key-updated', handleKeyUpdate)
  if ((userRole.value === 'admin' || userRole.value === 'superadmin') && routePrefix.value !== 'admin') {
    // Ensure elevated users use the admin routes so template write actions are allowed
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
        <IconButton icon="refresh" label="Aktualisieren" @click="loadAll" :disabled="loading" />
      </div>
    </div>

    <div class="subtabs">
      <button :class="['subtab', { active: activeSubTab === 'send' }]" @click="activeSubTab = 'send'">E-Mail-Versand</button>
      <button :class="['subtab', { active: activeSubTab === 'templates' }]" @click="activeSubTab = 'templates'">Vorlagenverwaltung</button>
    </div>

    <div v-if="message" class="message">{{ message }}</div>
    <div v-if="error" class="error">{{ error }}</div>

    <template v-if="activeSubTab === 'send'">
      <div class="card">
        <div class="card-header">
          <h3>E-Mail-Versand</h3>
        </div>
        <div class="grid">
          <label>
            Vorlage
            <select v-model.number="form.templateId">
              <option value="" disabled>Bitte auswählen</option>
              <option v-for="tpl in templates" :key="tpl.id" :value="tpl.id">
                {{ tpl.name }} – {{ tpl.subject }}
              </option>
            </select>
          </label>
          <label class="inline">
            <input type="checkbox" v-model="form.deduplicate" /> Duplikate anhand der E-Mail-Adresse vermeiden
          </label>
        </div>
        <div class="modes">
          <label v-for="mode in [
            { value: 'both', label: 'Alle (Reservierungen + Warteliste)' },
            { value: 'reservations', label: 'Alle Reservierungen' },
            { value: 'waitlist', label: 'Alle Warteliste' },
            { value: 'selection', label: 'Auswahl treffen' },
          ]" :key="mode.value" class="mode-option">
            <input type="radio" :value="mode.value" v-model="form.mode" />
            <span>{{ mode.label }}</span>
          </label>
        </div>
      </div>

      <div v-if="form.mode === 'selection'" class="card">
        <div class="card-header">
          <h4>Empfänger auswählen</h4>
        </div>
        <div class="two-col">
          <div>
            <h5>Reservierungen (mit E-Mail)</h5>
            <div v-if="reservationsWithEmail.length" class="list">
              <label v-for="r in reservationsWithEmail" :key="r.id" class="row">
                <input type="checkbox" :value="r.id" v-model="form.selectedReservations" />
                <span>{{ r.display_name }} <{{ r.email }}></span>
              </label>
            </div>
            <p v-else>Keine passenden Reservierungen.</p>
          </div>
          <div>
            <h5>Warteliste (mit E-Mail)</h5>
            <div v-if="waitlistWithEmail.length" class="list">
              <label v-for="w in waitlistWithEmail" :key="w.id" class="row">
                <input type="checkbox" :value="w.id" v-model="form.selectedWaitlist" />
                <span>{{ w.display_name }} <{{ w.email }}></span>
              </label>
            </div>
            <p v-else>Keine passenden Wartelisten-Einträge.</p>
          </div>
        </div>
      </div>

      <div class="card">
        <div class="card-header">
          <h4>Zusätzliche Empfänger (optional)</h4>
          <IconButton class="ghost" variant="success" type="button" icon="plus" label="Empfänger hinzufügen" @click="addCustomRecipient" />
        </div>
        <div class="inline-fields">
          <div v-for="(r, idx) in form.customRecipients" :key="idx" class="inline-row">
            <input v-model="r.name" placeholder="Name (optional)" />
            <input v-model="r.email" placeholder="E-Mail" />
            <IconButton class="ghost" variant="danger" type="button" icon="trash" label="Entfernen" @click="removeCustomRecipient(idx)" />
          </div>
        </div>
      </div>

      <div class="actions">
        <IconButton icon="send" label="Senden" @click="sendBroadcast" :disabled="loading || !form.templateId" />
      </div>

      <div v-if="stats" class="card">
        <h4>Ergebnis</h4>
        <ul class="stats">
          <li>Vorlage: #{{ stats.template_id }}</li>
          <li>Geplante E-Mails: {{ stats.queued }}</li>
          <li>Übersprungen (keine E-Mail): {{ stats.skipped_no_email }}</li>
          <li>Duplikate entfernt: {{ stats.duplicates_removed }}</li>
          <li>Kandidaten gesamt: {{ stats.candidates }}</li>
        </ul>
      </div>
    </template>

    <template v-else>
      <div class="card">
        <div class="card-header">
          <h3>Vorlagen</h3>
          <details v-if="placeholders.length" class="placeholder-info">
            <summary>Platzhalter anzeigen</summary>
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
          empty-text="Keine Vorlagen vorhanden."
          @refresh="loadTemplates"
        >
          <template #cell-name="{ row }">
            <input v-model="row.name" :disabled="!canManageTemplates" @change="saveTemplate(row)" />
          </template>
          <template #cell-subject="{ row }">
            <div class="with-placeholder-icon">
              <input v-model="row.subject" :disabled="!canManageTemplates" @change="saveTemplate(row)" :title="placeholderText || 'Unterstützt Platzhalter'" />
              <span class="placeholder-indicator" :title="placeholderText || 'Unterstützt Platzhalter'" aria-hidden="true">⧉</span>
            </div>
          </template>
          <template #cell-body="{ row }">
            <div class="with-placeholder-icon">
              <textarea v-model="row.body" rows="4" class="body-input" :disabled="!canManageTemplates" @change="saveTemplate(row)" :title="placeholderText || 'Unterstützt Platzhalter'"></textarea>
              <span class="placeholder-indicator" :title="placeholderText || 'Unterstützt Platzhalter'" aria-hidden="true">⧉</span>
            </div>
          </template>
          <template #row-actions="{ row }">
            <IconButton variant="danger" icon="trash" v-if="canManageTemplates" label="Löschen" @click="deleteTemplate(row.id)" />
          </template>
        </AdminDataTable>

        <div v-if="canManageTemplates" class="template-card">
          <h4>Neue Vorlage</h4>
          <label>Name <input v-model="templateForm.name" /></label>
          <label class="with-placeholder-icon">Betreff
            <div class="input-wrap">
              <input v-model="templateForm.subject" :title="placeholderText || 'Unterstützt Platzhalter'" />
              <span class="placeholder-indicator" :title="placeholderText || 'Unterstützt Platzhalter'" aria-hidden="true">⧉</span>
            </div>
          </label>
          <label class="with-placeholder-icon">Inhalt
            <div class="input-wrap">
              <textarea v-model="templateForm.body" rows="4" :title="placeholderText || 'Unterstützt Platzhalter'"></textarea>
              <span class="placeholder-indicator" :title="placeholderText || 'Unterstützt Platzhalter'" aria-hidden="true">⧉</span>
            </div>
          </label>
          <div class="template-actions">
            <IconButton icon="plus" label="Anlegen" @click="createTemplate" />
          </div>
        </div>
        <p v-else class="hint">Vorlagen können nur von Admins bearbeitet oder angelegt werden.</p>
      </div>
    </template>
  </div>
</template>

<style scoped>
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
:deep(.table td input), :deep(.table td textarea) { width: 100%; max-width: 100%; box-sizing: border-box; }
:deep(.table td textarea) { min-height: 120px; }
</style>
