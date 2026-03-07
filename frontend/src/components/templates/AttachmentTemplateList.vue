<script setup>
import { ref, onMounted } from 'vue'
import IconButton from '../IconButton.vue'
import AdminDataTable from '../AdminDataTable.vue'
import { adminFetch, adminFetchJson } from '../../utils/adminApi'
import { useTranslation } from '../../composables/useTranslation'

const props = defineProps({
  placeholders: { type: Array, default: () => [] }
})

const emit = defineEmits(['message', 'error'])

const { tr } = useTranslation()

const apiKey = ref(localStorage.getItem('admin_auth_session') || sessionStorage.getItem('admin_auth_session') || '')
const routePrefix = ref(localStorage.getItem('admin_route_prefix') || 'admin')

const templates = ref([])
const loading = ref(false)
const message = ref('')
const error = ref('')

// Form state
const isEditing = ref(false)
const showCreateForm = ref(false)
const formTemplate = ref({ name: '', description: '' })
const templateToDelete = ref(null)

// File upload state
const fileToUpload = ref(null)

function setMessage(msg) { 
  message.value = msg; 
  error.value = ''; 
  emit('message', msg);
}
function setError(msg) { 
  error.value = msg; 
  message.value = ''; 
  emit('error', msg);
}

const fetchWithAuth = (relative, opts = {}) => adminFetch(relative, opts, { apiKeyRef: apiKey, routePrefixRef: routePrefix })

onMounted(() => {
  if (apiKey.value) {
    loadAll();
  }
});

function formatFileSize(bytes) {
  if (!bytes && bytes !== 0) return '-'
  const sizes = ['B', 'KB', 'MB', 'GB']
  if (bytes === 0) return '0 B'
  const i = Math.floor(Math.log(bytes) / Math.log(1024))
  return `${Math.round(bytes / Math.pow(1024, i) * 100) / 100} ${sizes[i]}`
}

async function loadAll() {
  try {
    await Promise.all([
      fetchTemplates()
    ])
  } catch (e) {
    console.error('Failed to load data:', e)
  }
}

async function fetchTemplates() {
  loading.value = true;
  try {
    const res = await adminFetchJson('attachment-templates', {}, { apiKeyRef: apiKey, routePrefixRef: routePrefix });
    templates.value = Array.isArray(res) ? res : [];
    
    // Load attachments for each template
    for (const template of templates.value) {
      try {
        const attRes = await adminFetchJson(`attachment-templates/${template.id}/attachments`, {}, { apiKeyRef: apiKey, routePrefixRef: routePrefix });
        template.attachments = Array.isArray(attRes) ? attRes : [];
      } catch (err) {
        console.error(`Failed to fetch attachments for template ${template.id}:`, err);
        template.attachments = [];
      }
    }
  } catch (error) {
    console.error('Failed to fetch templates:', error);
    setError(tr('error_loading') + ': ' + error)
  } finally {
    loading.value = false;
  }
}

function openCreateForm() {
  isEditing.value = false;
  formTemplate.value = { name: '', description: '' };
  fileToUpload.value = null;
  showCreateForm.value = true;
}

function closeCreateForm() {
  showCreateForm.value = false;
}

async function saveTemplate() {
  try {
    if (isEditing.value && formTemplate.value.id) {
      await adminFetch(`attachment-templates/${formTemplate.value.id}`, { method: 'PATCH', body: JSON.stringify(formTemplate.value) }, { apiKeyRef: apiKey, routePrefixRef: routePrefix });
    } else {
      await adminFetch('attachment-templates', { method: 'POST', body: JSON.stringify(formTemplate.value) }, { apiKeyRef: apiKey, routePrefixRef: routePrefix });
    }
    showCreateForm.value = false;
    fetchTemplates();
    setMessage(tr('template_saved'));
  } catch (error) {
    console.error('Failed to save template:', error);
    setError(tr('saving_failed') + ': ' + error)
  }
}

function openEditDialog(template) {
  isEditing.value = true;
  formTemplate.value = { ...template };
  fileToUpload.value = null;
  showCreateForm.value = true;
}

function deleteTemplate(template) {
  templateToDelete.value = template;
}

async function confirmDelete() {
  try {
    await adminFetch(`attachment-templates/${templateToDelete.value.id}`, { method: 'DELETE' }, { apiKeyRef: apiKey, routePrefixRef: routePrefix });
    fetchTemplates();
    setMessage(tr('template_deleted'));
  } catch (error) {
    console.error('Failed to delete template:', error);
    setError(tr('deletion_failed') + ': ' + error)
  }
}

// File upload handling
function handleFileSelect(event) {
  const file = event.target.files[0];
  if (file) {
    fileToUpload.value = file;
  }
}

async function uploadAndAttach() {
  if (!formTemplate.value.id || !fileToUpload.value) {
    setError(tr('select_file_first'));
    return;
  }

  try {
    const formData = new FormData();
    formData.append('file', fileToUpload.value);

    await adminFetch(`attachment-templates/${formTemplate.value.id}/upload`, { 
      method: 'POST', 
      body: formData 
    }, { apiKeyRef: apiKey, routePrefixRef: routePrefix });

    // Reset upload state
    fileToUpload.value = null;
    
    // Refresh templates to get updated attachment count
    fetchTemplates();
    setMessage(tr('file_uploaded'));
  } catch (error) {
    console.error('Failed to upload file:', error);
    setError(tr('file_upload_failed') + ': ' + error)
  }
}

async function deleteAttachment(templateId, attachmentId) {
  if (!confirm(tr('confirm_delete_attachment'))) return;
  
  try {
    await adminFetch(`attachment-templates/${templateId}/attachments/${attachmentId}`, {
      method: 'DELETE'
    }, { apiKeyRef: apiKey, routePrefixRef: routePrefix });

    // Refresh templates
    fetchTemplates();
    
    // If editing a template, update the form with new data
    if (isEditing.value && formTemplate.value.id === templateId) {
      const updatedTemplate = templates.value.find(t => t.id === templateId);
      if (updatedTemplate) {
        formTemplate.value.attachments = updatedTemplate.attachments || [];
      }
    }
    
    setMessage(tr('file_deleted'));
  } catch (error) {
    console.error('Failed to delete file:', error);
    setError(tr('file_deletion_failed') + ': ' + error)
  }
}

const columns = ref([
  { key: 'id', label: tr('admin_attachment_templates_id_label'), sortable: true },
  { key: 'name', label: tr('admin_attachment_templates_name_label'), sortable: true },
  { key: 'description', label: tr('admin_attachment_templates_description_label'), sortable: false }
])

const hiddenColumns = ref(new Set(['id']))
</script>

<template>
  <div class="stack">
    <!-- Create/Edit Form (Inline) -->
    <div v-if="showCreateForm" class="card create-form">
      <div class="card-header">
        <h3>{{ isEditing ? tr('edit') : tr('create') }}</h3>
        <IconButton icon="close" :label="tr('cancel')" @click="closeCreateForm" />
      </div>
      <form @submit.prevent="saveTemplate">
        <div class="form-group">
          <label>{{ tr('admin_attachment_templates_name_label') }}</label>
          <input v-model="formTemplate.name" type="text" required maxlength="255" />
        </div>

        <div class="form-group">
          <label>{{ tr('admin_attachment_templates_description_label') }}</label>
          <textarea v-model="formTemplate.description" rows="3" :placeholder="tr('admin_attachment_templates_description_placeholder')" />
        </div>

        <!-- File Upload Section -->
        <div class="form-group attachments-section">
          <label>{{ tr('attachments') }}</label>
          
          <div v-if="isEditing && formTemplate.id" class="existing-attachments">
            <p class="section-subtitle">{{ tr('current_attachments') }}</p>
            
            <div v-if="templates.length > 0" class="attachments-list">
              <table class="data-table">
                <thead>
                  <tr>
                    <th>{{ tr('filename') }}</th>
                    <th>{{ tr('mime_type') }}</th>
                    <th>{{ tr('file_size') }}</th>
                    <th class="actions">{{ tr('actions') }}</th>
                  </tr>
                </thead>
                <tbody>
                  <!-- Find current template's attachments -->
                  <tr v-for="attachment in (templates.find(t => t.id === formTemplate.id)?.attachments || [])" :key="attachment.id">
                    <td>{{ attachment.original_filename }}</td>
                    <td><code>{{ attachment.mime_type }}</code></td>
                    <td>{{ formatFileSize(attachment.file_size) }}</td>
                    <td class="actions">
                      <IconButton 
                        icon="download" 
                        :title="tr('download')" 
                        variant="ghost"
                        @click="window.open(`/storage/${attachment.storage_path}`, '_blank')"
                      />
                      <IconButton 
                        icon="trash" 
                        :title="tr('delete')" 
                        variant="danger" 
                        @click="deleteAttachment(formTemplate.id, attachment.id)" 
                      />
                    </td>
                  </tr>
                </tbody>
              </table>
            </div>
            
            <p v-else class="empty-state">
              {{ tr('no_attachments') }}
            </p>
          </div>

          <div class="upload-section">
            <input 
              type="file" 
              @change="handleFileSelect" 
              accept=".pdf,.doc,.docx,.xls,.xlsx,.png,.jpg,.jpeg,.gif,.webp,.txt,.html"
            />
            
            <div v-if="fileToUpload" class="file-info">
              <strong>{{ tr('selected_file') }}:</strong> {{ fileToUpload.name }}
            </div>

            <IconButton 
              icon="upload" 
              :label="tr('add_attachment')" 
              @click="uploadAndAttach" 
              :disabled="!formTemplate.id || !fileToUpload"
              variant="success"
            />
          </div>
        </div>

        <div class="form-actions">
          <IconButton icon="save" :label="isEditing ? tr('save') : tr('create')" type="submit" :disabled="!formTemplate.name" variant="success" />
          <IconButton icon="close" :label="tr('cancel')" @click="closeCreateForm" variant="secondary" />
        </div>
      </form>
    </div>

    <!-- Card with Header and Table -->
    <div class="card">
      <div class="card-header">
        <h3>{{ tr('admin_attachment_templates_title') }}</h3>
        <IconButton icon="plus" :label="tr('admin_attachment_templates_create_button')" @click="openCreateForm" />
      </div>

      <details v-if="placeholders.length" class="placeholder-info">
        <summary>{{ tr('admin_email_broadcast_placeholder_info_title') }}</summary>
        <div class="placeholder-list">
          <code v-for="token in placeholders" :key="token">{{ token }}</code>
        </div>
      </details>

      <AdminDataTable
        :columns="columns"
        :rows="templates"
        row-key="id"
        :loading="loading"
        enable-search
        :page-size="10"
        persist-key="admin-attachment-templates"
        :empty-text="tr('admin_attachment_templates_empty_text')"
        :initial-hidden-columns="['id']"
      >
        <template #cell-name="{ row }">
          {{ row.name }}
        </template>
        <template #cell-description="{ row }">
          <div class="content-preview">{{ row.description || '-' }}</div>
        </template>
        <template #row-actions="{ row }">
          <IconButton icon="pencil" :title="tr('edit')" @click="openEditDialog(row)" />
          <IconButton icon="trash" :title="tr('delete')" variant="danger" @click="deleteTemplate(row)" />
        </template>
      </AdminDataTable>

      <div v-if="templates.length === 0 && !loading" class="empty-state">
        {{ tr('admin_attachment_templates_empty_text') }}
      </div>
    </div>

    <!-- Delete Confirmation Dialog -->
    <div v-if="templateToDelete" class="dialog-overlay">
      <div class="dialog" style="width: 30vw;">
        <div class="dialog-header">
          <h3>{{ tr('confirm_delete') }}</h3>
          <button class="close-btn" @click="templateToDelete = null">&times;</button>
        </div>
        <div class="dialog-content">
          <p>{{ tr('confirm_delete_template_text') }}</p>
          <div class="dialog-actions">
            <button @click="templateToDelete = null" class="btn-secondary">
              {{ tr('cancel') }}
            </button>
            <button @click="confirmDelete" class="btn-danger">
              {{ tr('delete') }}
            </button>
          </div>
        </div>
      </div>
    </div>
  </div>
</template>

<style scoped>
.stack { display: flex; flex-direction: column; gap: 0.75rem; }
.card { border: 1px solid var(--border-strong); border-radius: 8px; padding: 0.75rem; background: var(--app-card-bg, var(--surface)); color: var(--text); box-shadow: 0 6px 18px var(--shadow); display: flex; flex-direction: column; gap: 0.75rem; }
.card-header { display: flex; justify-content: space-between; align-items: center; gap: 0.5rem; }

.data-table { width: 100%; border-collapse: collapse; margin-top: 1rem; }
.data-table th, .data-table td { padding: 0.75rem; border-bottom: 1px solid var(--border); text-align: left; }
.data-table .content-cell { max-width: 300px; }
.data-table .content-preview { white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
.data-table .actions { width: 120px; }

.empty-state { text-align: center; padding: 1rem; color: var(--text-muted); font-style: italic; }

.create-form { margin-bottom: 1rem; border: 2px solid var(--primary); }

.form-group { margin-bottom: 1.5rem; }
.form-group label { display: block; margin-bottom: 0.5rem; font-weight: 600; }
.form-group input, .form-group textarea { width: 100%; padding: 0.75rem; border: 1px solid var(--border); border-radius: 4px; background: var(--surface); color: var(--text); box-sizing: border-box; }

.attachments-section { margin-top: 1rem; padding-top: 1rem; border-top: 1px solid var(--border); }
.section-subtitle { font-weight: 600; margin-bottom: 0.5rem; }
.upload-section { display: flex; align-items: center; gap: 0.75rem; flex-wrap: wrap; }

.form-actions, .dialog-actions { display: flex; justify-content: flex-end; gap: 1rem; margin-top: 1.5rem; }

/* Dialog styles */
.dialog-overlay {
  position: fixed;
  top: 0;
  left: 0;
  right: 0;
  bottom: 0;
  background: rgba(0, 0, 0, 0.5);
  display: flex;
  align-items: center;
  justify-content: center;
  z-index: 1000;
}
.dialog {
  background: var(--app-card-bg, var(--surface));
  border-radius: 8px;
  box-shadow: 0 6px 18px var(--shadow);
  max-width: 90vw;
  max-height: 90vh;
  overflow: auto;
  display: flex;
  flex-direction: column;
}
.dialog-header {
  display: flex;
  justify-content: space-between;
  align-items: center;
  padding: 1rem;
  border-bottom: 1px solid var(--border);
}
.dialog-header h3 { margin: 0; font-size: 1.25rem; color: var(--text); }
.close-btn {
  background: none;
  border: none;
  font-size: 1.5rem;
  cursor: pointer;
  color: var(--text-muted);
  padding: 0.25rem 0.5rem;
}
.dialog-content { padding: 1rem; }

.file-info { margin-top: 0.75rem; padding: 0.75rem; background: var(--surface); border-radius: 4px; }
</style>
