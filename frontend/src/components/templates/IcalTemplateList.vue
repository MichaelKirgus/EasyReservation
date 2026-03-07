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
const placeholders = ref([])
const loading = ref(false)
const message = ref('')
const error = ref('')

// Form state
const isEditing = ref(false)
const showCreateForm = ref(false)
const formTemplate = ref({ name: '', content: '' })
const templateToDelete = ref(null)

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

async function loadAll() {
  try {
    await Promise.all([
      fetchTemplates(),
      fetchPlaceholders()
    ])
  } catch (e) {
    console.error('Failed to load data:', e)
  }
}

async function fetchPlaceholders() {
  try {
    const res = await adminFetchJson('placeholders', {}, { apiKeyRef: apiKey, routePrefixRef: routePrefix });
    placeholders.value = Array.isArray(res) ? res : [];
  } catch (error) {
    console.error('Failed to fetch placeholders:', error);
  }
}

async function fetchTemplates() {
  loading.value = true;
  try {
    const res = await adminFetchJson('ical-templates', {}, { apiKeyRef: apiKey, routePrefixRef: routePrefix });
    templates.value = Array.isArray(res) ? res : [];
  } catch (error) {
    console.error('Failed to fetch templates:', error);
    setError(tr('error_loading') + ': ' + error)
  } finally {
    loading.value = false;
  }
}

function openCreateForm() {
  isEditing.value = false;
  formTemplate.value = { name: '', content: '' };
  showCreateForm.value = true;
}

function closeCreateForm() {
  showCreateForm.value = false;
}

async function saveTemplate() {
  try {
    if (isEditing.value && formTemplate.value.id) {
      await adminFetch(`ical-templates/${formTemplate.value.id}`, { method: 'PATCH', body: JSON.stringify(formTemplate.value) }, { apiKeyRef: apiKey, routePrefixRef: routePrefix });
    } else {
      await adminFetch('ical-templates', { method: 'POST', body: JSON.stringify(formTemplate.value) }, { apiKeyRef: apiKey, routePrefixRef: routePrefix });
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
  showCreateForm.value = true;
}

function deleteTemplate(template) {
  templateToDelete.value = template;
}

async function confirmDelete() {
  try {
    await adminFetch(`ical-templates/${templateToDelete.value.id}`, { method: 'DELETE' }, { apiKeyRef: apiKey, routePrefixRef: routePrefix });
    fetchTemplates();
    setMessage(tr('template_deleted'));
  } catch (error) {
    console.error('Failed to delete template:', error);
    setError(tr('deletion_failed') + ': ' + error)
  }
}

function truncateContent(content) {
  if (!content || content.length <= 50) return content || '';
  return content.substring(0, 50) + '...';
}

const columns = ref([
  { key: 'id', label: tr('admin_ical_templates_id_label'), sortable: true },
  { key: 'name', label: tr('admin_ical_templates_name_label'), sortable: true },
  { key: 'content', label: tr('admin_ical_templates_content_label'), sortable: false }
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
          <label>{{ tr('admin_ical_templates_name_label') }}</label>
          <input v-model="formTemplate.name" type="text" required maxlength="255" />
        </div>

        <div class="form-group">
          <label>{{ tr('admin_ical_templates_content_label') }}</label>
          <textarea v-model="formTemplate.content" rows="10" :placeholder="tr('admin_ical_templates_content_placeholder')" />
          <small class="placeholder-info">{{ tr('placeholders_available') }}</small>
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
        <h3>{{ tr('admin_ical_templates_title') }}</h3>
        <IconButton icon="plus" :label="tr('admin_ical_templates_create_button')" @click="openCreateForm" />
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
        persist-key="admin-ical-templates"
        :empty-text="tr('admin_ical_templates_empty_text')"
        :initial-hidden-columns="['id']"
      >
        <template #cell-name="{ row }">
          {{ row.name }}
        </template>
        <template #cell-content="{ row }">
          <div class="content-cell">
            <div class="content-preview">{{ truncateContent(row.content) }}</div>
          </div>
        </template>
        <template #row-actions="{ row }">
          <IconButton icon="pencil" :title="tr('edit')" @click="openEditDialog(row)" />
          <IconButton icon="trash" :title="tr('delete')" variant="danger" @click="deleteTemplate(row)" />
        </template>
      </AdminDataTable>

      <div v-if="templates.length === 0 && !loading" class="empty-state">
        {{ tr('admin_ical_templates_empty_text') }}
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

.empty-state { text-align: center; padding: 3rem; color: var(--text-muted); }

.create-form { margin-bottom: 1rem; border: 2px solid var(--primary); }

.form-group { margin-bottom: 1.5rem; }
.form-group label { display: block; margin-bottom: 0.5rem; font-weight: 600; }
.form-group input, .form-group textarea { width: 100%; padding: 0.75rem; border: 1px solid var(--border); border-radius: 4px; background: var(--surface); color: var(--text); box-sizing: border-box; }
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
</style>
