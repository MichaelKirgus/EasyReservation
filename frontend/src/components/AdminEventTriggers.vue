<template>
  <div>
    <h2 style="display:flex;align-items:center;justify-content:space-between;">
      <span>Ereignis-Trigger</span>
      <IconButton icon="plus" label="Neuer Trigger" class="primary" variant="success" @click="createTrigger" />
    </h2>
    <AdminDataTable
      :columns="columns"
      :rows="triggers"
      :loading="loading"
    >
      <template #cell-event_type="{ value }">
        <span>{{ eventTypeLabel(value) }}</span>
      </template>
      <template #cell-action_type="{ value }">
        <span>{{ value === 'email' ? 'E-Mail' : 'Webhook' }}</span>
      </template>
      <template #cell-template_id="{ value }">
        <span>{{ templateName(value) }}</span>
      </template>
      <template #cell-webhook_url="{ value, row }">
        <span v-if="row.action_type === 'webhook'">{{ value }}</span>
        <span v-else>–</span>
      </template>
      <template #cell-delay_seconds="{ value }">
        <span>{{ value ?? 0 }}</span>
      </template>
      <template #cell-cooldown_seconds="{ value }">
        <span>{{ value ?? 0 }}</span>
      </template>
      <template #cell-active="{ row }">
        <input type="checkbox" :checked="row.active" @change="toggleActive(row)" :disabled="loading" />
      </template>
      <template #row-actions="{ row }">
        <IconButton icon="play" label="Simulieren" class="ghost" @click.stop="simulate(row)" :disabled="loading || !row.active" />
        <IconButton icon="pencil" label="Bearbeiten" class="ghost" @click.stop="editTrigger(row)" :disabled="loading" />
        <IconButton icon="trash" label="Löschen" class="ghost" variant="danger" @click.stop="deleteTrigger(row)" :disabled="loading" />
      </template>
    </AdminDataTable>
    <TriggerDialog
      v-if="showDialog"
      :trigger="selectedTrigger"
      :emailTemplates="emailTemplates"
      :webhookTemplates="webhookTemplates"
      @close="closeDialog"
      @save="saveTrigger"
    />
  </div>
</template>

<script setup>
import { ref, onMounted } from 'vue'
import AdminDataTable from './AdminDataTable.vue'
import IconButton from './IconButton.vue'
import TriggerDialog from './TriggerDialog.vue'
import axios from 'axios'
import { buildAdminHeaders } from '../utils/adminApi'
import { useTranslation } from '../composables/useTranslation'

const { tr } = useTranslation()

const triggers = ref([])
const loading = ref(false)
const showDialog = ref(false)
const selectedTrigger = ref(null)

const emailTemplates = ref([])
const webhookTemplates = ref([])

function templateName(id) {
  if (!id) return '–';
  const tpl = emailTemplates.value.find(t => t.id == id);
  return tpl ? tpl.name : id;
}

const columns = [
  { key: 'id', label: 'ID' },
  { key: 'event_type', label: 'Ereignis' },
  { key: 'action_type', label: 'Aktion' },
  { key: 'template_id', label: 'E-Mail-Vorlage' },
  { key: 'webhook_url', label: 'Webhook-URL' },
  { key: 'delay_seconds', label: 'Verzögerung (Sek.)' },
  { key: 'cooldown_seconds', label: 'Cooldown (Sek.)' },
  { key: 'active', label: 'Aktiv' }
]

const eventTypes = [
  { value: 'reservation_full', label: 'Reservation list full' },
  { value: 'reservation_disabled', label: 'Reservation disabled' },
  { value: 'reservation_enabled', label: 'Reservation enabled' },
  { value: 'reservation_added', label: 'Reservation entry added' },
  { value: 'reservation_removed', label: 'Reservation entry removed' },
  { value: 'waitlist_enabled', label: 'Waitlist enabled' },
  { value: 'waitlist_disabled', label: 'Waitlist disabled' },
  { value: 'waitlist_entry_added', label: 'Waitlist entry added' },
  { value: 'waitlist_entry_removed', label: 'Waitlist entry removed' }
]

function eventTypeLabel(val) {
  const found = eventTypes.find(e => e.value === val)
  return found ? found.label : val
}

function apiConfig() {
  return { headers: buildAdminHeaders() };
}

function fetchTriggers() {
  loading.value = true
  axios.get('/api/admin/event-triggers', apiConfig())
    .then(res => { triggers.value = res.data })
    .finally(() => { loading.value = false })
}


function fetchEmailTemplates() {
  axios.get('/api/admin/email-templates', apiConfig())
    .then(res => { emailTemplates.value = res.data })
}

function fetchWebhookTemplates() {
  axios.get('/api/admin/webhook-templates', apiConfig())
    .then(res => { webhookTemplates.value = res.data })
}

function createTrigger() {
  selectedTrigger.value = null
  showDialog.value = true
}

function editTrigger(trigger) {
  selectedTrigger.value = { ...trigger }
  showDialog.value = true
}

function saveTrigger(trigger) {
  loading.value = true
  const req = trigger.id
    ? axios.put(`/api/admin/event-triggers/${trigger.id}`, trigger, apiConfig())
    : axios.post('/api/admin/event-triggers', trigger, apiConfig())
  req.then(fetchTriggers)
     .finally(() => { loading.value = false; showDialog.value = false })
}

function deleteTrigger(trigger) {
  if (!confirm(tr('really_delete_event_trigger'))) {
    return
  }
  loading.value = true
  axios.delete(`/api/admin/event-triggers/${trigger.id}`, apiConfig())
    .then(fetchTriggers)
    .finally(() => { loading.value = false })
}

function simulate(trigger) {
  loading.value = true
  axios.post(`/api/admin/event-triggers/${trigger.id}/simulate`, {}, apiConfig())
    .finally(() => { loading.value = false })
}

function toggleActive(trigger) {
  // Komplettes Trigger-Objekt übergeben, nur active ändern
  saveTrigger({ ...trigger, active: !trigger.active })
}

function closeDialog() {
  showDialog.value = false
}

onMounted(() => {
  fetchTriggers()
  fetchEmailTemplates()
  fetchWebhookTemplates()
})
</script>
