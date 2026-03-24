<template>
  <div>
    <h2 style="display:flex;align-items:center;justify-content:space-between;">
      <span>{{ tr('admin_action_lists_title') }}</span>
      <IconButton icon="plus" :label="tr('admin_action_lists_button_new')" class="primary" variant="success" @click="createActionList" />
    </h2>
    <AdminDataTable
      :columns="columns"
      :rows="actionLists"
      :loading="loading"
      :initial-hidden-columns="['id', 'created_at']"
      persist-key="admin_action_lists"
    >
      <template #cell-active="{ row }">
        <input type="checkbox" :checked="row.active" @change="toggleActive(row)" :disabled="loading" />
      </template>
      <template #row-actions="{ row }">
        <IconButton icon="play" :label="tr('admin_action_lists_button_execute')" class="ghost" @click.stop="execute(row)" :disabled="loading" />
        <IconButton icon="pencil" :label="tr('admin_action_lists_button_edit')" class="ghost" @click.stop="editActionList(row)" :disabled="loading" />
        <IconButton icon="trash" :label="tr('admin_action_lists_button_delete')" class="ghost" variant="danger" @click.stop="deleteActionList(row)" :disabled="loading" />
      </template>
    </AdminDataTable>
    <ActionListDialog
      v-if="showDialog"
      :action-list="selectedActionList"
      :email-templates="emailTemplates"
      :webhook-templates="webhookTemplates"
      @close="closeDialog"
      @save="saveActionList"
    />
  </div>
</template>

<script setup>
import { ref, computed, onMounted } from 'vue'
import AdminDataTable from './AdminDataTable.vue'
import IconButton from './IconButton.vue'
import ActionListDialog from './ActionListDialog.vue'
import axios from 'axios'
import { buildAdminHeaders } from '../utils/adminApi'
import { useTranslation } from '../composables/useTranslation'

const { tr } = useTranslation()

const actionLists = ref([])
const loading = ref(false)
const showDialog = ref(false)
const selectedActionList = ref(null)

const emailTemplates = ref([])
const webhookTemplates = ref([])

function apiConfig() {
  return { headers: buildAdminHeaders() }
}

function fetchActionLists() {
  loading.value = true
  axios.get('/api/admin/action-lists', apiConfig())
    .then(res => { actionLists.value = res.data.action_lists })
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

function createActionList() {
  selectedActionList.value = null
  showDialog.value = true
}

function editActionList(actionList) {
  selectedActionList.value = { ...actionList }
  showDialog.value = true
}

function saveActionList(actionList) {
  loading.value = true
  const req = actionList.id
    ? axios.put(`/api/admin/action-lists/${actionList.id}`, actionList, apiConfig())
    : axios.post('/api/admin/action-lists', actionList, apiConfig())
  req.then(fetchActionLists)
     .finally(() => { loading.value = false; showDialog.value = false })
}

function deleteActionList(actionList) {
  if (!confirm(tr('admin_action_lists_really_delete'))) {
    return
  }
  loading.value = true
  axios.delete(`/api/admin/action-lists/${actionList.id}`, apiConfig())
    .then(fetchActionLists)
    .finally(() => { loading.value = false })
}

function execute(actionList) {
  loading.value = true
  axios.post(`/api/admin/action-lists/${actionList.id}/execute`, {}, apiConfig())
    .finally(() => { loading.value = false })
}

function toggleActive(actionList) {
  saveActionList({ ...actionList, active: !actionList.active })
}

function closeDialog() {
  showDialog.value = false
}

const columns = computed(() => [
  { key: 'id', label: tr('admin_action_lists_column_id') },
  { key: 'name', label: tr('admin_action_lists_column_name') },
  { key: 'description', label: tr('admin_action_lists_column_description') },
  { key: 'actions_count', label: tr('admin_action_lists_column_actions_count') },
  { key: 'active', label: tr('admin_action_lists_column_active') },
  { key: 'created_at', label: tr('admin_action_lists_column_created_at') }
])

onMounted(() => {
  fetchActionLists()
  fetchEmailTemplates()
  fetchWebhookTemplates()
})
</script>

<style scoped>
</style>
