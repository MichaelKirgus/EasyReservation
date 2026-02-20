
<template>
  <div>
    <h2 style="display:flex;align-items:center;justify-content:space-between;">
      <span>{{ tr('webhook_templates_title', 'Webhook Templates') }}</span>
      <IconButton icon="plus" :label="tr('webhook_templates_button_new', 'New template')" class="primary" variant="success" @click="createTemplate" />
    </h2>
    <AdminDataTable
      :columns="columns"
      :rows="templates"
      :loading="loading"
    >
      <template #row-actions="{ row }">
        <IconButton icon="play" :label="tr('webhook_templates_test', 'Test')" class="ghost" @click.stop="testTemplate(row)" :disabled="loading" />
        <IconButton icon="copy" :label="tr('webhook_templates_clone', 'Clone')" class="ghost" @click.stop="cloneTemplate(row)" :disabled="loading" />
        <IconButton icon="pencil" :label="tr('webhook_templates_edit', 'Edit')" class="ghost" @click.stop="editTemplate(row)" :disabled="loading" />
        <IconButton icon="trash" :label="tr('webhook_templates_delete', 'Delete')" class="ghost" variant="danger" @click.stop="deleteTemplate(row)" :disabled="loading" />
      </template>
    </AdminDataTable>
    <div v-if="showDialog">
      <WebhookTemplateDialog
        :template="selectedTemplate"
        @close="closeDialog"
        @save="saveTemplate"
      />
    </div>
  </div>
</template>

<script setup>
import { ref, computed, onMounted } from 'vue'
import AdminDataTable from './AdminDataTable.vue'
import IconButton from './IconButton.vue'
import WebhookTemplateDialog from './WebhookTemplateDialog.vue'
import axios from 'axios'
import { buildAdminHeaders } from '../utils/adminApi'
import { useTranslation } from '../composables/useTranslation'

const { tr } = useTranslation()

function apiConfig() {
  return { headers: buildAdminHeaders() };
}

const templates = ref([])
const loading = ref(false)
const showDialog = ref(false)
const selectedTemplate = ref(null)

// Columns - defined as computed to ensure translations are loaded
const columns = computed(() => [
  { key: 'id', label: tr('admin_webhook_templates_id', 'ID') },
  { key: 'name', label: tr('admin_webhook_templates_name', 'Name') },
  { key: 'url', label: tr('admin_webhook_templates_url', 'Webhook URL') },
  { key: 'description', label: tr('admin_webhook_templates_description', 'Description') }
])

function fetchTemplates() {
  loading.value = true
  axios.get('/api/admin/webhook-templates', apiConfig())
    .then(res => { templates.value = res.data })
    .finally(() => { loading.value = false })
}

function createTemplate() {
  selectedTemplate.value = null
  showDialog.value = true
}

function editTemplate(template) {
  selectedTemplate.value = { ...template }
  showDialog.value = true
}

function deleteTemplate(template) {
  if (!confirm(tr('really_delete_webhook_template'))) {
    return
  }
  loading.value = true
  axios.delete(`/api/admin/webhook-templates/${template.id}`, apiConfig())
    .then(fetchTemplates)
    .finally(() => { loading.value = false })
}

function cloneTemplate(template) {
  const newName = prompt(tr('webhook_templates_enter_clone_name', 'Enter a new name for the copy:'), template.name + ' (Copy)')
  if (!newName) return
  loading.value = true
  axios.post(`/api/admin/webhook-templates/${template.id}/clone`, { name: newName }, apiConfig())
    .then(fetchTemplates)
    .finally(() => { loading.value = false })
}

function testTemplate(row) {
  loading.value = true
  axios.post(`/api/admin/webhook-templates/${row.id}/test`, {}, apiConfig())
    .then(res => {
      alert(res.data.message || tr('webhook_templates_test_success', 'Webhook sent successfully.'))
    })
    .catch(err => {
      alert(err.response?.data?.message || tr('webhook_templates_test_error', 'Error sending webhook.'))
    })
    .finally(() => { loading.value = false })
}

function saveTemplate(template) {
  loading.value = true
  const req = template.id
    ? axios.put(`/api/admin/webhook-templates/${template.id}`, template, apiConfig())
    : axios.post('/api/admin/webhook-templates', template, apiConfig())
  req.then(fetchTemplates)
     .finally(() => { loading.value = false; showDialog.value = false })
}

function closeDialog() {
  showDialog.value = false
}

onMounted(fetchTemplates)
</script>
