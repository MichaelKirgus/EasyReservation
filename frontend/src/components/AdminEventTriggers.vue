<template>
  <div>
    <h2 style="display:flex;align-items:center;justify-content:space-between;">
      <span>{{ tr('admin_event_triggers_title') }}</span>
      <IconButton icon="plus" :label="tr('admin_event_triggers_button_new_trigger')" class="primary" variant="success" @click="createTrigger" />
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
        <span>{{ value === 'email' ? tr('trigger_dialog_option_email') : tr('trigger_dialog_option_webhook') }}</span>
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
        <IconButton :icon="'play'" :label="tr('admin_event_triggers_button_simulate')" class="ghost" @click.stop="simulate(row)" :disabled="loading || !row.active" />
        <IconButton :icon="'pencil'" :label="tr('admin_event_triggers_button_edit')" class="ghost" @click.stop="editTrigger(row)" :disabled="loading" />
        <IconButton :icon="'trash'" :label="tr('admin_event_triggers_button_delete')" class="ghost" variant="danger" @click.stop="deleteTrigger(row)" :disabled="loading" />
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
  { key: 'id', label: tr('admin_event_triggers_column_id') },
  { key: 'event_type', label: tr('admin_event_triggers_column_event_type') },
  { key: 'action_type', label: tr('admin_event_triggers_column_action_type') },
  { key: 'template_id', label: tr('admin_event_triggers_column_template_id') },
  { key: 'webhook_url', label: tr('admin_event_triggers_column_webhook_url') },
  { key: 'delay_seconds', label: tr('admin_event_triggers_column_delay_seconds') },
  { key: 'cooldown_seconds', label: tr('admin_event_triggers_column_cooldown_seconds') },
  { key: 'active', label: tr('admin_event_triggers_column_active') }
]

const eventTypes = [
  { value: 'reservation_full', label: tr('event_type_reservation_full') },
  { value: 'reservation_disabled', label: tr('event_type_reservation_disabled') },
  { value: 'reservation_enabled', label: tr('event_type_reservation_enabled') },
  { value: 'reservation_added', label: tr('event_type_reservation_added') },
  { value: 'reservation_removed', label: tr('event_type_reservation_removed') },
  { value: 'reservation_canceled', label: tr('event_type_reservation_canceled') },
  { value: 'waitlist_enabled', label: tr('event_type_waitlist_enabled') },
  { value: 'waitlist_disabled', label: tr('event_type_waitlist_disabled') },
  { value: 'waitlist_entry_added', label: tr('event_type_waitlist_entry_added') },
  { value: 'waitlist_entry_removed', label: tr('event_type_waitlist_entry_removed') },
  { value: 'application_error', label: tr('event_type_application_error') }
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
  if (!confirm(tr('admin_event_triggers_really_delete'))) {
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
