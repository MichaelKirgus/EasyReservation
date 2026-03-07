<script setup>
import { ref, computed, onMounted } from 'vue'
import IconButton from './IconButton.vue'
import Dialog from '../common/Dialog.vue'
import { adminFetch } from '../utils/adminApi'
import { useTranslation } from '../composables/useTranslation'

const { tr } = useTranslation()
const apiKey = ref(localStorage.getItem('admin_auth_session') || sessionStorage.getItem('admin_auth_session') || '')
const routePrefix = ref(localStorage.getItem('admin_route_prefix') || 'admin')

const templates = ref([])
const dialogVisible = ref(false)
const deleteDialogVisible = ref(false)
const isEditing = ref(false)
const dialogData = ref({ name: '', content: '' })
const templateToDelete = ref(null)

onMounted(() => {
  if (apiKey.value) {
    fetchTemplates();
  }
});

async function fetchTemplates() {
  try {
    const res = await adminFetch('admin/ical-templates', {}, { apiKeyRef: apiKey, routePrefixRef: routePrefix });
    templates.value = Array.isArray(res.data) ? res.data : [];
  } catch (error) {
    console.error('Failed to fetch templates:', error);
  }
}

function openCreateDialog() {
  isEditing.value = false;
  dialogData.value = { name: '', content: '' };
  dialogVisible.value = true;
}

function openEditDialog(template) {
  isEditing.value = true;
  dialogData.value = { ...template };
  dialogVisible.value = true;
}

async function saveTemplate() {
  try {
    if (isEditing.value) {
      await adminFetch(`admin/ical-templates/${dialogData.value.id}`, { method: 'PATCH', body: JSON.stringify(dialogData.value) }, { apiKeyRef: apiKey, routePrefixRef: routePrefix });
    } else {
      await adminFetch('admin/ical-templates', { method: 'POST', body: JSON.stringify(dialogData.value) }, { apiKeyRef: apiKey, routePrefixRef: routePrefix });
    }
    dialogVisible.value = false;
    fetchTemplates();
  } catch (error) {
    console.error('Failed to save template:', error);
  }
}

function deleteTemplate(template) {
  templateToDelete.value = template;
  deleteDialogVisible.value = true;
}

async function confirmDelete() {
  try {
    await adminFetch(`admin/ical-templates/${templateToDelete.value.id}`, { method: 'DELETE' }, { apiKeyRef: apiKey, routePrefixRef: routePrefix });
    deleteDialogVisible.value = false;
    fetchTemplates();
  } catch (error) {
    console.error('Failed to delete template:', error);
  }
}

function truncateContent(content) {
  if (!content || content.length <= 50) return content || '';
  return content.substring(0, 50) + '...';
}
</script>

<template>
<div class="admin-ical-templates">
  <div class="header">
    <h2>{{ tr('admin_ical_templates_title') }}</h2>
    <IconButton icon="plus" :label="tr('admin_ical_templates_create_button')" @click="openCreateDialog" />
  </div>

  <table v-if="templates.length > 0" class="data-table">
    <thead>
      <tr>
        <th>{{ tr('admin_ical_templates_name_label') }}</th>
        <th>{{ tr('admin_ical_templates_content_label') }}</th>
        <th class="actions">{{ tr('actions') }}</th>
      </tr>
    </thead>
    <tbody>
      <tr v-for="template in templates" :key="template.id">
        <td>{{ template.name }}</td>
        <td class="content-cell">
          <div class="content-preview">{{ truncateContent(template.content) }}</div>
        </td>
        <td class="actions">
          <IconButton icon="edit" :title="tr('edit')" @click="openEditDialog(template)" />
          <IconButton icon="trash" :title="tr('delete')" variant="danger" @click="deleteTemplate(template)" />
        </td>
      </tr>
    </tbody>
  </table>

  <div v-else class="empty-state">
    {{ tr('admin_ical_templates_empty_text') }}
  </div>

  <Dialog
    v-model:visible="dialogVisible"
    :header="isEditing ? tr('edit') : tr('create')"
    :style="{ width: '70vw' }"
  >
    <form @submit.prevent="saveTemplate">
      <div class="form-group">
        <label>{{ tr('admin_ical_templates_name_label') }}</label>
        <input v-model="dialogData.name" type="text" required maxlength="255" />
      </div>

      <div class="form-group">
        <label>{{ tr('admin_ical_templates_content_label') }}</label>
        <textarea v-model="dialogData.content" rows="10" :placeholder="tr('admin_ical_templates_content_placeholder')" />
        <small class="placeholder-info">{{ tr('placeholders_available') }}: uid, dtstamp, dtstart, dtend, timezone, title, location, notes, url, start_date, start_time, end_date, end_time</small>
      </div>

      <div class="dialog-actions">
        <button type="button" @click="dialogVisible = false" class="btn-secondary">
          {{ tr('cancel') }}
        </button>
        <button type="submit" :disabled="!dialogData.name" class="btn-primary">
          {{ isEditing ? tr('save') : tr('create') }}
        </button>
      </div>
    </form>
  </Dialog>

  <Dialog
    v-model:visible="deleteDialogVisible"
    :header="tr('confirm_delete')"
    :style="{ width: '30vw' }"
  >
    <p>{{ tr('confirm_delete_template_text') }}</p>
    <div class="dialog-actions">
      <button @click="deleteDialogVisible = false" class="btn-secondary">
        {{ tr('cancel') }}
      </button>
      <button @click="confirmDelete" class="btn-danger">
        {{ tr('delete') }}
      </button>
    </div>
  </Dialog>
</div class="admin-ical-templates">
</template>

<style scoped>
admin-ical-templates { padding: 1rem; }

header { display: flex; justify-content: space-between; align-items: center; margin-bottom: 1.5rem; }

data-table { width: 100%; border-collapse: collapse; }

data-table th, td { padding: 0.75rem; border-bottom: 1px solid var(--border); text-align: left; }

data-table .content-cell { max-width: 300px; }

data-table .content-preview { white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }

actions { width: 120px; }

btn-icon { background: none; border: none; cursor: pointer; padding: 0.25rem; margin-right: 0.25rem; }

btn-danger:hover { color: var(--danger); }

empty-state { text-align: center; padding: 3rem; color: var(--text-muted); }

form-group { margin-bottom: 1rem; }

form-group label { display: block; margin-bottom: 0.5rem; font-weight: 600; }

form-group input, textarea { width: 100%; padding: 0.5rem; border: 1px solid var(--border); border-radius: 4px; }

placeholder-info { display: block; margin-top: 0.25rem; font-size: 0.875rem; color: var(--text-muted); }

dialog-actions { display: flex; justify-content: flex-end; gap: 1rem; margin-top: 1.5rem; }
</style>