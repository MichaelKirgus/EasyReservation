
<template>
  <div>
    <h2 style="display:flex;align-items:center;justify-content:space-between;">
      <span>Webhook-Vorlagen</span>
      <IconButton icon="plus" label="Neue Vorlage" class="primary" @click="createTemplate" />
    </h2>
    <AdminDataTable
      :columns="columns"
      :rows="templates"
      :loading="loading"
    >
      <template #row-actions="{ row }">
        <IconButton icon="play" label="Testen" class="ghost" @click="testTemplate(row)" :disabled="loading" />
        <IconButton icon="pencil" label="Bearbeiten" class="ghost" @click="editTemplate(row)" :disabled="loading" />
        <IconButton icon="trash" label="Löschen" class="ghost" @click="deleteTemplate(row)" :disabled="loading" />
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
import { ref, onMounted } from 'vue'
import AdminDataTable from './AdminDataTable.vue'
import IconButton from './IconButton.vue'
import WebhookTemplateDialog from './WebhookTemplateDialog.vue'
import axios from 'axios'

function apiConfig() {
  const apiKey = localStorage.getItem('admin_api_key') || sessionStorage.getItem('admin_api_key') || '';
  return { headers: { 'X-Api-Key': apiKey } };
}

const templates = ref([])
const loading = ref(false)
const showDialog = ref(false)
const selectedTemplate = ref(null)

const columns = [
  { key: 'id', label: 'ID' },
  { key: 'name', label: 'Name' },
  { key: 'url', label: 'Webhook-URL' },
  { key: 'description', label: 'Beschreibung' }
]

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
  if (confirm('Wirklich löschen?')) {
    loading.value = true
    axios.delete(`/api/admin/webhook-templates/${template.id}`, apiConfig())
      .then(fetchTemplates)
      .finally(() => { loading.value = false })
  }
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
