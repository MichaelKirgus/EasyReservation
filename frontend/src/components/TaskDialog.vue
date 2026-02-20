<template>
  <div class="task-dialog">
    <h3>{{ task && task.id ? tr('scheduled_tasks_task_dialog_edit_title') : tr('scheduled_tasks_task_dialog_new_title') }}</h3>
    <form @submit.prevent="submit">
      <div>
        <label>{{ tr('scheduled_tasks_column_type') }}:
          <span class="help-inline">{{ tr('scheduled_tasks_help_inline_absolute') }}</span>
        </label>
        <select v-model="form.type" required>
          <option value="">{{ tr('scheduled_tasks_select_placeholder') }}</option>
          <option value="attendees_email_broadcast">{{ tr('scheduled_tasks_type_attendees_email_broadcast') }}</option>
          <option value="waitlist_email_broadcast">{{ tr('scheduled_tasks_type_waitlist_email_broadcast') }}</option>
          <option value="custom_email_broadcast">{{ tr('scheduled_tasks_type_custom_email_broadcast') }}</option>
          <option value="change_setting">{{ tr('scheduled_tasks_type_change_setting') }}</option>
          <option value="webhook">{{ tr('scheduled_tasks_type_webhook') }}</option>
        </select>
      </div>
      <!-- Scheduling Mode Selection -->
      <div>
        <label>{{ tr('scheduled_tasks_scheduling_mode_absolute') }}:</label>
        <select v-model="schedulingMode">
          <option value="absolute">{{ tr('scheduled_tasks_scheduling_mode_absolute') }}</option>
          <option value="relative">{{ tr('scheduled_tasks_scheduling_mode_relative') }}</option>
          <option value="cron">{{ tr('scheduled_tasks_scheduling_mode_cron') }}</option>
        </select>
      </div>

      <!-- Absolute scheduling -->
      <div v-if="schedulingMode === 'absolute'">
        <label>{{ tr('scheduled_tasks_label_run_at') }}:</label>
        <input v-model="form.run_at" type="datetime-local" />
        <span class="help-inline">{{ tr('scheduled_tasks_help_inline_absolute') }}</span>
      </div>

      <!-- Relative scheduling -->
      <div v-if="schedulingMode === 'relative'">
        <label>{{ tr('scheduled_tasks_label_reference_type') }}:</label>
        <select v-model="form.reference_type">
          <option value="event">{{ tr('scheduled_tasks_reference_type_event') }}</option>
          <option value="reservation">{{ tr('scheduled_tasks_reference_type_reservation') }}</option>
          <option value="user">{{ tr('scheduled_tasks_reference_type_user') }}</option>
        </select>

        <div v-if="referenceObjects.length" style="margin-top:0.5em;">
          <label>{{ tr('scheduled_tasks_label_reference_object') }}:</label>
          <select v-model="form.reference_id">
            <option :value="null">{{ tr('scheduled_tasks_all_objects') }}</option>
            <option v-for="obj in referenceObjects" :key="obj.id" :value="obj.id">
              {{ objDisplay(obj) }}
            </option>
          </select>
        </div>

        <label style="margin-top:0.5em;">{{ tr('scheduled_tasks_label_relative_to') }}:</label>
        <select v-model="form.relative_to">
          <option value="">{{ tr('scheduled_tasks_select_placeholder') }}</option>
          <option v-for="field in relativeFields" :key="field" :value="field">
            {{ field }}
          </option>
        </select>

        <label style="margin-top:0.5em;">{{ tr('scheduled_tasks_label_offset_minutes') }}:</label>
        <input v-model.number="form.relative_offset_minutes" type="number" />
        <span class="help-inline">{{ tr('scheduled_tasks_help_inline_offset') }}</span>
      </div>

      <!-- Cron scheduling -->
      <div v-if="schedulingMode === 'cron'">
        <label>{{ tr('scheduled_tasks_label_cron_expression') }}:</label>
        <input v-model="form.cron_expression" type="text" placeholder="* * * * *" />
        <span class="help-inline">{{ tr('scheduled_tasks_help_inline_cron_syntax') }}</span>
        
        <!-- Cron Examples Dropdown -->
        <div style="margin-top:0.5em;">
          <label>{{ tr('scheduled_tasks_label_cron_examples') }}:</label>
          <select @change="selectCronExample($event)" style="width:100%;">
            <option value="">{{ tr('scheduled_tasks_select_placeholder') }}</option>
            <option v-for="example in cronExamples" :key="example.label" :value="example.value">
              {{ example.label }}
            </option>
          </select>
        </div>
        
        <div v-if="form.cron_expression" style="margin-top:0.5em;">
          <label>{{ tr('scheduled_tasks_label_next_run') }}:</label>
          <input :value="nextCronRunAt" type="text" readonly />
          <button type="button" @click="calculateNextCronRuns" class="secondary">{{ tr('calculate') }}</button>
        </div>
      </div>

      <div v-if="form.type === 'change_setting'">
        <label>{{ tr('admin_settings_key') }}:</label>
        <select v-model="selectedSettingKey" required>
          <option value="">{{ tr('scheduled_tasks_select_placeholder') }}</option>
          <option v-for="key in settingKeys" :key="key" :value="key">{{ key }}</option>
        </select>
        <label>{{ tr('new_value') }}:</label>
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
      <div v-if="form.type === 'attendees_email_broadcast' || form.type === 'waitlist_email_broadcast' || form.type === 'custom_email_broadcast'">
        <label>{{ tr('email_template_name') }}:</label>
        <select v-model.number="selectedTemplateId" required>
          <option value="">{{ tr('scheduled_tasks_select_placeholder') }}</option>
          <option v-for="tpl in emailTemplates" :key="tpl.id" :value="tpl.id">
            {{ tpl.name || tpl.subject || (tr('email_template_name') + ' #' + tpl.id) }} (ID: {{ tpl.id }})
          </option>
        </select>
      </div>
      <div v-if="form.type === 'custom_email_broadcast'">
        <label>{{ tr('scheduled_tasks_label_reference_object') }} (eine Adresse pro Zeile, optional mit Name):</label>
        <textarea v-model="customEmails" placeholder="max@example.com\nAnna <anna@example.com>\n..."></textarea>
      </div>
      <div v-if="form.type === 'webhook'">
        <label>{{ tr('admin_webhook_templates_title') }}:</label>
        <select v-model.number="selectedWebhookTemplateId" required>
          <option value="">{{ tr('scheduled_tasks_select_placeholder') }}</option>
          <option v-for="tpl in webhookTemplates" :key="tpl.id" :value="tpl.id">
            {{ tpl.name || (tr('admin_webhook_templates_title') + ' #' + tpl.id) }} (ID: {{ tpl.id }})
          </option>
        </select>
      </div>
      <div>
        <label>{{ tr('scheduled_tasks_label_executed') }}:</label>
        <input v-model="form.executed" type="checkbox" />
      </div>
      <div>
        <label>{{ tr('scheduled_tasks_label_run_once') }}:
          <span class="help-inline">{{ tr('scheduled_tasks_help_inline_run_once') }}</span>
        </label>
        <input v-model="form.run_once" type="checkbox" />
      </div>
      <div>
        <label>{{ tr('scheduled_tasks_label_skip_if_overdue') }}:
          <span class="help-inline">{{ tr('scheduled_tasks_help_inline_skip_if_overdue') }}</span>
        </label>
        <input v-model="form.skip_if_overdue" type="checkbox" />
      </div>
      <div style="margin-top:1em; display:flex; gap:0.5em; justify-content:flex-end;">
        <IconButton icon="check" :label="tr('admin_setting_submit_button_text')" type="submit" />
        <IconButton icon="close" :label="tr('cancel')" variant="danger" type="button" @click="$emit('close')" />
      </div>
    </form>
  </div>
</template>


<script setup>
import { ref, watch, computed, onMounted } from 'vue'
import IconButton from './IconButton.vue'
import axios from 'axios'
import { settingsFields } from './settingsFields.js'
import { buildAdminHeaders } from '../utils/adminApi'
import { useTranslation } from '../composables/useTranslation'

function apiConfig() {
  return { headers: buildAdminHeaders() };
}
const props = defineProps({ task: Object })
const emit = defineEmits(['save', 'close'])

const { tr } = useTranslation()
const schedulingMode = ref('absolute')
const nextCronRunAt = ref('')
const cronRuns = ref([])

// Watch for scheduling mode changes to update reference_type
watch(schedulingMode, (newMode) => {
  if (newMode === 'cron') {
    form.value.reference_type = 'cron'
  } else if (newMode === 'relative') {
    form.value.reference_type = 'event'
  } else if (newMode === 'absolute') {
    form.value.reference_type = 'fixed'
  }
})

// Cron expression examples
const cronExamples = computed(() => [
  { label: tr('scheduled_tasks_cron_example_minute'), value: '* * * * *' },
  { label: tr('scheduled_tasks_cron_example_hourly'), value: '0 * * * *' },
  { label: tr('scheduled_tasks_cron_example_daily'), value: '0 8 * * *' },
  { label: tr('scheduled_tasks_cron_example_weekly'), value: '30 14 * * 1' },
  { label: tr('scheduled_tasks_cron_example_monthly'), value: '0 0 1 * *' },
])

const form = ref({
  type: '',
  run_at: '',
  cron_expression: '',
  reference_type: 'fixed',
  reference_id: null,
  relative_to: '',
  relative_offset_minutes: null,
  options: {},
  executed: false,
  run_once: false,
  skip_if_overdue: false,
})

const referenceObjects = ref([])
const emailTemplates = ref([])
const customEmails = ref('');
const selectedTemplateId = ref('')
const webhookTemplates = ref([])
const selectedWebhookTemplateId = ref('')
const settingKeys = ref([])
const selectedSettingKey = ref('')
const settingValue = ref('')

// Computed: aktuelles Setting-Field (Typ etc.)
const currentSettingField = computed(() => {
  return settingsFields.find(f => f.key === selectedSettingKey.value) || { type: 'text' }
})

// Hilfsfunktion: Wert fÃ¼r Input je nach Typ konvertieren
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

// Hilfsfunktion fÃ¼r das richtige Datumsformat im Input
function toDatetimeLocal(val) {
  if (!val) return '';
  let iso = val.replace(' ', 'T');
  // Falls kein Z oder Zeitzonen-Offset, als UTC interpretieren
  if (!/Z|[+-]\d{2}:\d{2}$/.test(iso)) iso += 'Z';
  const d = new Date(iso);
  if (isNaN(d)) return '';
  const pad = n => n.toString().padStart(2, '0');
  // Lokale Zeit fÃ¼r das Input-Feld erzeugen
  return `${d.getFullYear()}-${pad(d.getMonth()+1)}-${pad(d.getDate())}T${pad(d.getHours())}:${pad(d.getMinutes())}`;
}

const relativeFieldsMap = {
  event: ['start_at', 'end_at'],
  reservation: ['created_at', 'updated_at'],
  user: ['created_at', 'last_login_at'],
}
const relativeFields = computed(() => relativeFieldsMap[form.value.reference_type] || [])

// Berechne nÃ¤chste Cron-AusfÃ¼hrungszeiten
function calculateNextCronRuns() {
  if (!form.value.cron_expression) {
    nextCronRunAt.value = ''
    return
  }
  
  try {
    // Verwende den Server fÃ¼r die Berechnung (da cron-parser nicht im Browser verfÃ¼gbar ist)
    axios.post('/api/admin/cron/next-run', { expression: form.value.cron_expression }, apiConfig())
      .then(res => {
        if (res.data && res.data.next_runs) {
          cronRuns.value = res.data.next_runs
          nextCronRunAt.value = res.data.next_runs[0] ? toDatetimeLocal(res.data.next_runs[0]) : ''
        }
      })
      .catch(() => {
        // Fallback: Zeige die Cron-Expression an
        nextCronRunAt.value = form.value.cron_expression + tr('scheduled_tasks_calculation_failed_fallback')
      })
  } catch (e) {
    console.error('Error calculating cron runs:', e)
    nextCronRunAt.value = ''
  }
}

function selectCronExample(event) {
  const value = event.target.value
  if (value) {
    form.value.cron_expression = value
    calculateNextCronRuns()
    // Reset the dropdown selection
    event.target.value = ''
  }
}

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
    const whRes = await axios.get('/api/admin/webhook-templates', apiConfig())
    webhookTemplates.value = whRes.data
  } catch {}
  try {
    const keysRes = await axios.get('/api/admin/settings-keys', apiConfig())
    settingKeys.value = keysRes.data
  } catch {}
})



let loadedFromTask = false

// Determine scheduling mode based on task data
function determineSchedulingMode(task) {
  if (task?.cron_expression) return 'cron'
  if (task?.relative_to && task.relative_offset_minutes !== null) return 'relative'
  if (task?.run_at) return 'absolute'
  return 'absolute' // default
}

watch(() => props.task, (task) => {
  if (task) {
    // Erstelle ein Plain-Object, um Vue-Proxy-Probleme zu vermeiden
    const taskData = JSON.parse(JSON.stringify({ ...task, options: task.options || {} }))
    
    // Setze Scheduling Mode basierend auf den Daten
    schedulingMode.value = determineSchedulingMode(task)
    
    // FÃ¼r Cron-basierte Aufgaben: reference_type auf 'cron' setzen, bevor wir die Daten laden
    let finalTaskData = { ...taskData, options: task.options || {} }
    if (schedulingMode.value === 'cron') {
      finalTaskData.reference_type = 'cron'
    } else if (!finalTaskData.reference_type) {
      // Fallback fÃ¼r alte Tasks ohne reference_type
      finalTaskData.reference_type = 'fixed'
    }
    
    form.value = finalTaskData
    
    // Korrigiere das Datumsformat fÃ¼r das Input-Feld
    if (schedulingMode.value === 'absolute') {
      form.value.run_at = toDatetimeLocal(task.run_at || '')
    }
    
    if (
      form.value.type === 'attendees_email_broadcast' ||
      form.value.type === 'waitlist_email_broadcast' ||
      form.value.type === 'custom_email_broadcast'
    ) {
      selectedTemplateId.value = form.value.options?.template_id || ''
    } else {
      selectedTemplateId.value = ''
    }
    
    if (form.value.type === 'custom_email_broadcast') {
      // custom_recipients als Zeilen-String
      customEmails.value = (form.value.options?.custom_recipients || [])
        .map(r => r.name ? `${r.name} <${r.email}>` : r.email)
        .join('\n')
    } else {
      customEmails.value = ''
    }
    
    if (form.value.type === 'webhook') {
      selectedWebhookTemplateId.value = form.value.options?.webhook_template_id || ''
    } else {
      selectedWebhookTemplateId.value = ''
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
    schedulingMode.value = 'absolute'
    form.value = {
      type: '',
      run_at: '',
      cron_expression: '',
      reference_type: 'fixed',
      reference_id: null,
      relative_to: '',
      relative_offset_minutes: null,
      options: {},
      executed: false,
      run_once: false,
      skip_if_overdue: false,
    }
    selectedTemplateId.value = ''
    selectedWebhookTemplateId.value = ''
    selectedSettingKey.value = ''
    settingValue.value = ''
    customEmails.value = ''
    loadedFromTask = false
  }
}, { immediate: true })

// Lade aktuellen Wert aus DB, wenn SchlÃ¼ssel gewÃ¤hlt wird (nur beim Anlegen oder wenn Wert leer)
watch(selectedSettingKey, async (key) => {
  if (!key) return;
  // Nur laden, wenn kein Wert aus Task Ã¼bernommen wurde oder Wert leer
  if (loadedFromTask && settingValue.value !== '') return;
  try {
    const res = await axios.get(`/api/admin/settings/${encodeURIComponent(key)}`, apiConfig())
    // Wert aus DB Ã¼bernehmen, aber Typ beachten
    let val = res.data?.value
    val = convertSettingValue(val, currentSettingField.value.type)
    settingValue.value = val
  } catch {
    // Fehler ignorieren, Wert bleibt leer
  }
})

function parseCustomEmails(str) {
  // Zeilenweise, Format: Name <mail@x.de> oder nur mail@x.de
  return str.split(/\r?\n/)
    .map(line => line.trim())
    .filter(line => line.length > 0)
    .map(line => {
      const match = line.match(/^(.*?)\s*<([^>]+)>$/)
      if (match) {
        return { name: match[1].trim(), email: match[2].trim() }
      } else {
        return { email: line }
      }
    })
}

function toUtcIsoString(localDateTimeStr) {
  if (!localDateTimeStr) return null;
  // localDateTimeStr: "2024-06-18T12:00"
  const d = new Date(localDateTimeStr);
  if (isNaN(d)) return null;
  return d.toISOString().replace('.000', ''); // z.B. "2024-06-18T10:00:00Z"
}

function submit() {
  // Dynamisch options bauen je nach Typ
  const payload = { ...form.value };
  
  // Enforce reference_type based on scheduling mode
  if (schedulingMode.value === 'absolute') {
    payload.reference_type = 'fixed';
  } else if (schedulingMode.value === 'cron') {
    payload.reference_type = 'cron';
  }
  // For 'relative', keep the user-selected reference_type (event/reservation/user)
  
  // Setze run_at basierend auf Scheduling Mode
  if (schedulingMode.value === 'absolute') {
    if (!payload.run_at || payload.run_at === '') {
      payload.run_at = null;
    } else {
      payload.run_at = toUtcIsoString(payload.run_at);
    }
  } else if (schedulingMode.value === 'cron') {
    // Cron Expression direkt Ã¼bertragen
    if (!payload.cron_expression) {
      alert(tr('scheduled_tasks_cron_expression_required'))
      return
    }
  } else {
    // Relative Zeit: run_at ist nicht relevant, aber fÃ¼r KompatibilitÃ¤t setzen
    payload.run_at = null
  }
  
  if ([
    'attendees_email_broadcast',
    'waitlist_email_broadcast',
    'custom_email_broadcast'
  ].includes(form.value.type)) {
    payload.options = { ...payload.options, template_id: selectedTemplateId.value };
  }
  if (form.value.type === 'custom_email_broadcast') {
    payload.options = { ...payload.options, custom_recipients: parseCustomEmails(customEmails.value) };
  }
  if (form.value.type === 'webhook') {
    payload.options = { ...payload.options, webhook_template_id: selectedWebhookTemplateId.value };
  } else if (form.value.type === 'change_setting') {
    let value = settingValue.value;
    // Typkonvertierung fÃ¼r Boolean/Number
    if (currentSettingField.value.type === 'boolean') {
      value = value ? '1' : '0'; // String statt Boolean!
    } else if (currentSettingField.value.type === 'number') {
      value = value === '' ? null : Number(value);
    }
    payload.options = { key: selectedSettingKey.value, value };
  }
  emit('save', payload);
}
</script>

<style scoped>
.task-dialog {
  background: var(--app-card-bg, var(--surface));
  color: var(--text);
  border: 1px solid var(--border-strong);
  padding: 1.25em;
  max-width: 400px;
  border-radius: 12px;
  box-shadow: 0 12px 30px var(--shadow);
}
.task-dialog label {
  display: block;
  margin-top: 0.5em;
}
.task-dialog input, .task-dialog textarea {
  width: 100%;
}
span.help-inline {
  color: var(--text-muted);
  font-size: 0.95em;
  margin-left: 0.5em;
}
</style>
