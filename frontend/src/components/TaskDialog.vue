<template>
  <div class="task-dialog">
    <h3>{{ task && task.id ? 'Aufgabe bearbeiten' : 'Neue Aufgabe' }}</h3>
    <form @submit.prevent="submit">
      <div>
        <label>Typ:
          <span class="help-inline">Wählen Sie die Art der geplanten Aufgabe.</span>
        </label>
        <select v-model="form.type" required>
          <option value="">Bitte wählen…</option>
          <option value="email_broadcast">E-Mail an Teilnehmer</option>
          <option value="change_setting">Einstellung ändern</option>
          <!-- Weitere Typen hier ergänzen -->
        </select>
      </div>
      <div>
        <label>Ausführungszeit (run_at):</label>
        <input v-model="form.run_at" type="datetime-local" />
      </div>
      <div>
        <label>Referenz-Typ:</label>
        <select v-model="form.reference_type">
          <option value="event">Event</option>
          <option value="reservation">Reservierung</option>
          <option value="user">Benutzer</option>
        </select>
      </div>
      <div v-if="referenceObjects.length">
        <label>Referenz-Objekt:</label>
        <select v-model="form.reference_id">
          <option :value="null">Alle Objekte</option>
          <option v-for="obj in referenceObjects" :key="obj.id" :value="obj.id">
            {{ objDisplay(obj) }}
          </option>
        </select>
      </div>
      <div>
        <label>Relativ zu:
          <span class="help-inline">Optional: Feld des Referenzobjekts.</span>
        </label>
        <select v-model="form.relative_to">
          <option value="">Bitte wählen…</option>
          <option v-for="field in relativeFields" :key="field" :value="field">
            {{ field }}
          </option>
        </select>
      </div>
      <div>
        <label>Offset (Minuten):
          <span class="help-inline">Optional: Zeitverschiebung in Minuten (z.B. <code>-180</code> für 3 Stunden vor dem Ereignis).</span>
        </label>
        <input v-model.number="form.relative_offset_minutes" type="number" />
      </div>

      <div v-if="form.type === 'change_setting'">
        <label>Einstellungsschlüssel:</label>
        <select v-model="selectedSettingKey" required>
          <option value="">Bitte wählen…</option>
          <option v-for="key in settingKeys" :key="key" :value="key">{{ key }}</option>
        </select>
        <label>Neuer Wert:</label>
        <template v-if="currentSettingField.type === 'boolean'">
          <input v-model="settingValue" type="checkbox" :true-value="true" :false-value="false" />
        </template>
        <template v-else-if="currentSettingField.type === 'number'">
          <input v-model.number="settingValue" type="number" required />
        </template>
        <template v-else-if="currentSettingField.type === 'color'">
          <input v-model="settingValue" type="color" required />
        </template>
        <template v-else-if="currentSettingField.type === 'date'">
          <input v-model="settingValue" type="date" required />
        </template>
        <template v-else-if="currentSettingField.type === 'datetime-local'">
          <input v-model="settingValue" type="datetime-local" required />
        </template>
        <template v-else-if="currentSettingField.component === 'textarea'">
          <textarea v-model="settingValue" required></textarea>
        </template>
        <template v-else>
          <input v-model="settingValue" type="text" required />
        </template>
      </div>
      <div v-if="form.type === 'email_broadcast'">
        <label>E-Mail-Vorlage:</label>
        <select v-model.number="selectedTemplateId" required>
          <option value="">Bitte wählen…</option>
          <option v-for="tpl in emailTemplates" :key="tpl.id" :value="tpl.id">
            {{ tpl.name || tpl.subject || ('Vorlage #' + tpl.id) }} (ID: {{ tpl.id }})
          </option>
        </select>
      </div>
      <div>
        <label>Bereits ausgeführt:</label>
        <input v-model="form.executed" type="checkbox" />
      </div>
      <div style="margin-top:1em; display:flex; gap:0.5em; justify-content:flex-end;">
        <IconButton icon="save" label="Speichern" class="primary" type="submit" />
        <IconButton icon="close" label="Abbrechen" class="ghost" type="button" @click="$emit('close')" />
      </div>
    </form>
  </div>
</template>


<script setup>
import { ref, watch, computed, onMounted } from 'vue'
import IconButton from './IconButton.vue'
import axios from 'axios'
import { settingsFields } from './settingsFields.js'

function apiConfig() {
  const apiKey = localStorage.getItem('admin_api_key') || sessionStorage.getItem('admin_api_key') || '';
  return { headers: { 'X-Api-Key': apiKey } };
}
const props = defineProps({ task: Object })
const emit = defineEmits(['save', 'close'])

const form = ref({
  type: '',
  run_at: '',
  reference_type: 'event',
  reference_id: null,
  relative_to: '',
  relative_offset_minutes: null,
  options: {},
  executed: false,
})

const referenceObjects = ref([])
const emailTemplates = ref([])
const selectedTemplateId = ref('')
const settingKeys = ref([])
const selectedSettingKey = ref('')
const settingValue = ref('')

// Computed: aktuelles Setting-Field (Typ etc.)
const currentSettingField = computed(() => {
  return settingsFields.find(f => f.key === selectedSettingKey.value) || { type: 'text' }
})

// Hilfsfunktion: Wert für Input je nach Typ konvertieren
function convertSettingValue(val, type) {
  if (type === 'boolean') {
    return val === true || val === '1' || val === 1 || val === 'true';
  } else if (type === 'number') {
    return val === null || val === '' ? null : Number(val);
  } else if (type === 'color') {
    // Hex-Farben sicherstellen
    if (typeof val === 'string' && val.startsWith('#')) return val;
    return '#000000';
  } else if (type === 'date') {
    // Nur Datumsteil
    if (!val) return '';
    return String(val).slice(0, 10);
  } else if (type === 'datetime-local') {
    // ISO-String ohne Sekunden und Zeitzone
    if (!val) return '';
    const d = new Date(val);
    if (isNaN(d)) return '';
    const pad = n => n.toString().padStart(2, '0');
    return `${d.getFullYear()}-${pad(d.getMonth()+1)}-${pad(d.getDate())}T${pad(d.getHours())}:${pad(d.getMinutes())}`;
  }
  return val ?? '';
}

const relativeFieldsMap = {
  event: ['start_at', 'end_at'],
  reservation: ['created_at', 'updated_at'],
  user: ['created_at', 'last_login_at'],
}
const relativeFields = computed(() => relativeFieldsMap[form.value.reference_type] || [])

function objDisplay(obj) {
  if (form.value.reference_type === 'event') return `${obj.title} (ID: ${obj.id})`
  if (form.value.reference_type === 'reservation') return `${obj.name || obj.title || 'Reservierung'} (ID: ${obj.id})`
  if (form.value.reference_type === 'user') return `${obj.email || obj.name || 'Benutzer'} (ID: ${obj.id})`
  return `Objekt #${obj.id}`
}

onMounted(async () => {
  // Initial: Events laden, falls Standardtyp
  if (form.value.reference_type === 'event') {
    try {
      const res = await axios.get('/api/admin/events', apiConfig())
      referenceObjects.value = res.data
    } catch {}
  }
  try {
    const tplRes = await axios.get('/api/admin/email-templates', apiConfig())
    emailTemplates.value = tplRes.data
  } catch {}
  try {
    const keysRes = await axios.get('/api/admin/settings-keys', apiConfig())
    settingKeys.value = keysRes.data
  } catch {}
})



let loadedFromTask = false
watch(() => props.task, (task) => {
  if (task) {
    form.value = { ...task, options: task.options || {}, reference_type: task.reference_type || 'event' }
    if (form.value.type === 'email_broadcast') {
      selectedTemplateId.value = form.value.options?.template_id || ''
    } else {
      selectedTemplateId.value = ''
    }
    if (form.value.type === 'change_setting') {
      selectedSettingKey.value = form.value.options?.key || ''
      settingValue.value = form.value.options?.value ?? ''
      loadedFromTask = true
    } else {
      selectedSettingKey.value = ''
      settingValue.value = ''
      loadedFromTask = false
    }
  } else {
    form.value = {
      type: '',
      run_at: '',
      reference_type: 'event',
      reference_id: null,
      relative_to: '',
      relative_offset_minutes: null,
      options: {},
      executed: false,
    }
    selectedTemplateId.value = ''
    selectedSettingKey.value = ''
    settingValue.value = ''
    loadedFromTask = false
  }
}, { immediate: true })

// Lade aktuellen Wert aus DB, wenn Schlüssel gewählt wird (nur beim Anlegen oder wenn Wert leer)
watch(selectedSettingKey, async (key) => {
  if (!key) return;
  // Nur laden, wenn kein Wert aus Task übernommen wurde oder Wert leer
  if (loadedFromTask && settingValue.value !== '') return;
  try {
    const res = await axios.get(`/api/admin/settings/${encodeURIComponent(key)}`, apiConfig())
    // Wert aus DB übernehmen, aber Typ beachten
    let val = res.data?.value
    val = convertSettingValue(val, currentSettingField.value.type)
    settingValue.value = val
  } catch {
    // Fehler ignorieren, Wert bleibt leer
  }
})

function submit() {
  // Dynamisch options bauen je nach Typ
  const payload = { ...form.value }
  // run_at auf null setzen, wenn leer
  if (!payload.run_at || payload.run_at === '') {
    payload.run_at = null;
  }
  if (form.value.type === 'email_broadcast') {
    payload.options = { ...payload.options, template_id: selectedTemplateId.value }
  } else if (form.value.type === 'change_setting') {
    let value = settingValue.value
    // Typkonvertierung für Boolean/Number
    if (currentSettingField.value.type === 'boolean') {
      value = value ? '1' : '0'; // String statt Boolean!
    } else if (currentSettingField.value.type === 'number') {
      value = value === '' ? null : Number(value)
    }
    payload.options = { key: selectedSettingKey.value, value }
  }
  emit('save', payload)
}
</script>

<style scoped>
.task-dialog {
  background: #fff;
  border: 1px solid #ccc;
  padding: 1em;
  max-width: 400px;
}
.task-dialog label {
  display: block;
  margin-top: 0.5em;
}
.task-dialog input, .task-dialog textarea {
  width: 100%;
}
button.primary {
  background: #2563eb;
  color: #fff;
  border: 1px solid #2563eb;
  border-radius: 6px;
  padding: 0.5em 1.1em;
  font-weight: 600;
  cursor: pointer;
  transition: background 0.15s;
}
button.primary:hover {
  background: #1d4ed8;
}
button.ghost {
  background: #eef2ff;
  color: #1d4ed8;
  border: 1px solid #c7d2fe;
  border-radius: 6px;
  padding: 0.5em 1.1em;
  font-weight: 600;
  cursor: pointer;
}
span.help-inline {
  color: #64748b;
  font-size: 0.95em;
  margin-left: 0.5em;
}
</style>
