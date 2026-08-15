<template>
  <div>
    <h2 style="display:flex;align-items:center;justify-content:space-between;">
      <span>{{ tr('admin_event_triggers_title') }}</span>
      <IconButton icon="plus" :label="tr('admin_event_triggers_button_new')" class="primary" variant="success" @click="createTrigger" />
    </h2>
    <AdminDataTable
      :columns="columns"
      :rows="triggers"
      :loading="loading"
    >
      <template #cell-active="{ row }">
        <input type="checkbox" :checked="row.active" @change="toggleActive(row)" :disabled="loading" />
      </template>
      <template #row-actions="{ row }">
        <IconButton icon="play" :label="tr('admin_event_triggers_button_simulate')" class="ghost" @click.stop="simulate(row)" :disabled="loading" />
        <IconButton icon="copy" :label="tr('icon_buttons_clone')" class="ghost" @click.stop="cloneTrigger(row)" :disabled="loading" />
        <IconButton icon="pencil" :label="tr('admin_event_triggers_button_edit')" class="ghost" @click.stop="editTrigger(row)" :disabled="loading" />
        <IconButton icon="trash" :label="tr('admin_event_triggers_button_delete')" class="ghost" variant="danger" @click.stop="deleteTrigger(row)" :disabled="loading" />
      </template>
    </AdminDataTable>
    <TriggerDialog
      v-if="showDialog"
      :trigger="selectedTrigger"
      :action-lists="actionLists"
      @close="closeDialog"
      @save="saveTrigger"
    />
  </div>
</template>

<script setup>
import { ref, computed, onMounted } from 'vue'
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

const actionLists = ref([])

function apiConfig() {
  return { headers: buildAdminHeaders() };
}

function fetchTriggers() {
  loading.value = true
  axios.get('/api/admin/event-triggers', apiConfig())
    .then(res => { triggers.value = res.data })
    .finally(() => { loading.value = false })
}

function fetchActionLists() {
  axios.get('/api/admin/action-lists', apiConfig())
    .then(res => { actionLists.value = res.data.action_lists || [] })
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

function cloneTrigger(trigger) {
  loading.value = true
  axios.post(`/api/admin/event-triggers/${trigger.id}/clone`, {}, apiConfig())
    .then(fetchTriggers)
    .finally(() => { loading.value = false })
}

function toggleActive(trigger) {
  loading.value = true
  axios.patch(`/api/admin/event-triggers/${trigger.id}/toggle-active`, {}, apiConfig())
    .then(fetchTriggers)
    .finally(() => { loading.value = false })
}

function simulate(trigger) {
  loading.value = true
  axios.post(`/api/admin/event-triggers/${trigger.id}/simulate`, {}, apiConfig())
    .then(res => {
      if (res.data.simulated) {
        alert(tr('admin_event_triggers_simulate_success'))
      } else {
        alert(tr('admin_event_triggers_simulate_error') + ': ' + res.data.error)
      }
    })
    .finally(() => { loading.value = false })
}

function closeDialog() {
  showDialog.value = false
}

// Columns - defined as computed to ensure translations are loaded
const columns = computed(() => [
  { key: 'id', label: tr('admin_event_triggers_column_id') },
  { key: 'event_type', label: tr('admin_event_triggers_column_event_type') },
  { key: 'action_list_name', label: tr('admin_event_triggers_column_action_list') },
  { key: 'delay_seconds', label: tr('admin_event_triggers_column_delay_seconds') },
  { key: 'cooldown_seconds', label: tr('admin_event_triggers_column_cooldown_seconds') },
  { key: 'active', label: tr('admin_event_triggers_column_active') }
])

// Event types - defined as computed to ensure translations are loaded
const eventTypes = computed(() => [
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
  { value: 'application_error', label: tr('event_type_application_error') },
  { value: 'setting_changed', label: tr('event_type_setting_changed') },
  { value: 'login_succeeded', label: tr('event_type_login_succeeded') },
  { value: 'login_failed', label: tr('event_type_login_failed') },
  { value: 'logout', label: tr('event_type_logout') }
])

function eventTypeLabel(val) {
  const found = eventTypes.value.find(e => e.value === val)
  return found ? found.label : val
}

onMounted(() => {
  fetchTriggers()
  fetchActionLists()
})
</script>

<style scoped>
.admin-event-triggers {
  background: var(--app-card-bg, var(--surface));
  color: var(--text);
  border-radius: 12px;
  padding: 1.5em;
  margin: 1.5em 0;
  border: 1px solid var(--border-strong);
  box-shadow: 0 12px 30px var(--shadow);
}
</style>
