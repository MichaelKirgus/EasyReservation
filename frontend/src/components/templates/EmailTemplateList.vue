<script setup>
import { ref, reactive, computed, onMounted } from 'vue'
import IconButton from '../IconButton.vue'
import AdminDataTable from '../AdminDataTable.vue'
import EmailTemplatePreview from './EmailTemplatePreview.vue'
import { adminFetch } from '../../utils/adminApi'
import { useTranslation } from '../../composables/useTranslation'

const props = defineProps({
  transportGroupOptions: { type: Array, default: () => [] },
  icalTemplateOptions: { type: Array, default: () => [] },
  attachmentTemplateOptions: { type: Array, default: () => [] },
  isAdminOrSuperAdmin: { type: Boolean, default: false },
  canManageTemplates: { type: Boolean, default: false },
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

// Preview state
const previewTemplate = ref(null)
const showPreviewDialog = ref(false)

// Edit form state (inline, similar to create form)
const editTemplate = ref(null)
const showEditForm = ref(false)

// Create form state
const showCreateForm = ref(false)

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

async function loadTemplates() {
  const res = await fetchWithAuth('email-templates')
  if (!res.ok) throw new Error(await res.text())
  const loadedTemplates = await res.json()
  loadedTemplates.forEach(tpl => {
    // Convert transport group to string format for combobox selection
    if (tpl.transport_group_id && tpl.transport_type) {
      tpl.transportGroupId = `${tpl.transport_type}_${tpl.transport_group_id}`;
    } else {
      // Use "__none__" to match the "None" option in comboboxes
      tpl.transportGroupId = '__none__';
    }
    // Store original template IDs for editing
    // Convert null to "__none__" so it matches the "None" option in comboboxes
    tpl.ical_template_id = tpl.ical_template_id === null ? '__none__' : tpl.ical_template_id;
    tpl.attachment_template_id = tpl.attachment_template_id === null ? '__none__' : tpl.attachment_template_id;
  });
  templates.value = loadedTemplates;
}

const templateColumns = computed(() => [
  { key: 'id', label: tr('admin_email_broadcast_columns_id'), sortable: true },
  { key: 'name', label: tr('admin_email_broadcast_columns_name'), sortable: true },
  { key: 'transportGroupId', label: tr('admin_email_broadcast_transport_group_label'), sortable: false },
  { key: 'ical_template_id', label: tr('admin_email_broadcast_ical_template_label'), sortable: false },
  { key: 'attachment_template_id', label: tr('admin_email_broadcast_attachment_template_label'), sortable: false },
  { key: 'subject', label: tr('admin_email_broadcast_columns_subject'), sortable: true },
  { key: 'to', label: tr('admin_email_broadcast_columns_to'), sortable: false },
  { key: 'cc', label: tr('admin_email_broadcast_columns_cc'), sortable: false },
  { key: 'bcc', label: tr('admin_email_broadcast_columns_bcc'), sortable: false },
  { key: 'body', label: tr('admin_email_broadcast_columns_body'), sortable: false }
])

const placeholderText = computed(() => props.placeholders.length ? `${tr('admin_email_broadcast_columns_placeholder')}: ${props.placeholders.join(', ')}` : '')

function openPreview(template) {
  previewTemplate.value = template
  showPreviewDialog.value = true
}

function closePreview() {
  showPreviewDialog.value = false
  previewTemplate.value = null
}

function openEditForm(template) {
  editTemplate.value = { ...template }
  showEditForm.value = true
}

const templateForm = reactive({ name: '', subject: '', to: '{{recipient_email}}', body: '', cc: '', bcc: '', type: 'generic', transportGroupId: '__none__', ical_template_id: null, attachment_template_id: null })

async function saveTemplate(tpl) {
  if (!props.canManageTemplates) { setError(tr('templates_can_only_be_edited_by_admin')); return }
  try {
    let transportGroupId = tpl.transportGroupId;
    let transportType = '';
    // Convert "__none__" to null for transport group
    if (transportGroupId === '__none__') {
      transportGroupId = null;
    } else if (typeof transportGroupId === 'number') {
      // Already a number, keep as is
    } else if (typeof transportGroupId === 'string') {
      if (transportGroupId.startsWith('group_')) {
        transportType = 'group';
        transportGroupId = parseInt(transportGroupId.replace('group_', '')) || null;
      } else if (transportGroupId.startsWith('account_')) {
        transportType = 'account';
        transportGroupId = parseInt(transportGroupId.replace('account_', '')) || null;
      }
    }
    // Convert "__none__" to null for iCal and attachment templates
    const icalTemplateId = tpl.ical_template_id === '__none__' ? null : tpl.ical_template_id;
    const attachmentTemplateId = tpl.attachment_template_id === '__none__' ? null : tpl.attachment_template_id;
    const res = await fetchWithAuth(`email-templates/${tpl.id}`, {
      method: 'PATCH',
      body: JSON.stringify({
        name: tpl.name,
        subject: tpl.subject,
        to: tpl.to || '{{recipient_email}}',
        body: tpl.body,
        cc: tpl.cc || '',
        bcc: tpl.bcc || '',
        type: tpl.type || 'generic',
        transport_group_id: transportGroupId,
        transport_type: transportType,
        ical_template_id: icalTemplateId,
        attachment_template_id: attachmentTemplateId,
      }),
    })
    const text = await res.text()
    if (!res.ok) throw new Error(text)
    setMessage(tr('template_saved'))
  } catch (e) {
    setError(tr('saving_failed') + ': ' + e)
  }
}

async function deleteTemplate(id) {
  if (!props.canManageTemplates) { setError(tr('templates_can_only_be_deleted_by_admin')); return }
  if (!confirm(tr('really_delete_template'))) return
  try {
    const res = await fetchWithAuth(`email-templates/${id}`, { method: 'DELETE' })
    const text = await res.text()
    if (!res.ok) throw new Error(text)
    templates.value = templates.value.filter(t => t.id !== id)
    setMessage(tr('template_deleted'))
  } catch (e) {
    setError(tr('deletion_failed') + ': ' + e)
  }
}

async function cloneTemplate(template) {
  if (!props.canManageTemplates) { setError(tr('templates_can_only_be_cloned_by_admin')); return }
  try {
    const res = await fetchWithAuth(`email-templates/${template.id}/clone`, { 
      method: 'POST',
      body: JSON.stringify({ name: template.name + ' (Copy)' })
    })
    const text = await res.text()
    if (!res.ok) throw new Error(text)
    setMessage(tr('template_cloned'))
    await loadTemplates()
  } catch (e) {
    setError(tr('cloning_failed') + ': ' + e)
  }
}

async function createTemplate() {
  if (!props.canManageTemplates) { setError(tr('templates_can_only_be_created_by_admin')); return }
  try {
    // Convert "__none__" to null for iCal and attachment templates
    const icalTemplateId = templateForm.ical_template_id === '__none__' ? null : templateForm.ical_template_id;
    const attachmentTemplateId = templateForm.attachment_template_id === '__none__' ? null : templateForm.attachment_template_id;
    // Convert "__none__" to null for transport group
    let transportGroupId = templateForm.transportGroupId === '__none__' ? null : templateForm.transportGroupId;
    let transportType = '';
    if (transportGroupId && typeof transportGroupId === 'string') {
      if (transportGroupId.startsWith('group_')) {
        transportType = 'group';
        transportGroupId = parseInt(transportGroupId.replace('group_', '')) || null;
      } else if (transportGroupId.startsWith('account_')) {
        transportType = 'account';
        transportGroupId = parseInt(transportGroupId.replace('account_', '')) || null;
      }
    }
    const res = await fetchWithAuth('email-templates', {
      method: 'POST',
      body: JSON.stringify({
        ...templateForm,
        to: templateForm.to || '{{recipient_email}}',
        transport_group_id: transportGroupId,
        transport_type: transportType,
        ical_template_id: icalTemplateId,
        attachment_template_id: attachmentTemplateId,
      }),
    })
    const text = await res.text()
    if (!res.ok) throw new Error(text)
    setMessage(tr('template_created'))
    showCreateForm.value = false
    templateForm.name = ''
    templateForm.subject = ''
    templateForm.body = ''
    templateForm.cc = ''
    templateForm.bcc = ''
    templateForm.type = 'generic'
    templateForm.transportGroupId = '__none__'
    templateForm.ical_template_id = null
    templateForm.attachment_template_id = null
    await loadTemplates()
  } catch (e) {
    setError(tr('creating_failed') + ': ' + e)
  }
}

onMounted(() => {
  if (apiKey.value) {
    loadTemplates()
  }
})
</script>

<template>
  <div class="stack">
    <!-- Create Form (Inline, toggled by button) -->
    <div v-if="showCreateForm" class="card create-form">
      <div class="card-header">
        <h3>{{ tr('admin_email_broadcast_create_template_title') }}</h3>
        <IconButton icon="close" :label="tr('cancel')" @click="showCreateForm = false" />
      </div>
      
      <form @submit.prevent="createTemplate">
        <div class="grid">
          <label>{{ tr('admin_email_broadcast_name_label') }}
            <input v-model="templateForm.name" required />
          </label>
          
          <label class="with-placeholder-icon">{{ tr('admin_email_broadcast_subject_label') }}
            <div class="input-wrap">
              <input v-model="templateForm.subject" :title="placeholderText || tr('admin_email_broadcast_columns_placeholder')" required />
              <span class="placeholder-indicator" :title="placeholderText || tr('admin_email_broadcast_columns_placeholder')" aria-hidden="true">⧉</span>
            </div>
          </label>
          
          <label>{{ tr('admin_email_broadcast_transport_group_label') }}
            <select v-model.number="templateForm.transportGroupId" :disabled="!isAdminOrSuperAdmin">
              <option value="__none__">{{ tr('none') }}</option>
              <template v-for="opt in transportGroupOptions" :key="opt.value">
                <option :value="opt.value">{{ opt.label }}</option>
              </template>
            </select>
          </label>

          <label>{{ tr('admin_email_broadcast_ical_template_label') }}
            <select v-model.number="templateForm.ical_template_id">
              <option value="__none__">{{ tr('none') }}</option>
              <template v-for="opt in icalTemplateOptions" :key="opt.value">
                <option :value="opt.value">{{ opt.label }}</option>
              </template>
            </select>
          </label>

          <label>{{ tr('admin_email_broadcast_attachment_template_label') }}
            <select v-model.number="templateForm.attachment_template_id">
              <option value="__none__">{{ tr('none') }}</option>
              <template v-for="opt in attachmentTemplateOptions" :key="opt.value">
                <option :value="opt.value">{{ opt.label }}</option>
              </template>
            </select>
          </label>
        </div>

<label class="with-placeholder-icon">{{ tr('admin_email_broadcast_to_label') }}
          <div class="input-wrap">
            <input v-model="templateForm.to" :title="tr('admin_email_broadcast_to_title_hint')" />
            <span class="placeholder-indicator" :title="tr('admin_email_broadcast_to_title_hint')" aria-hidden="true">⧉</span>
          </div>
        </label>

        <label class="with-placeholder-icon">{{ tr('admin_email_broadcast_cc_label') }}
          <div class="input-wrap">
            <input v-model="templateForm.cc" :title="tr('admin_email_broadcast_cc_title_hint')" />
            <span class="placeholder-indicator" :title="tr('admin_email_broadcast_cc_title_hint')" aria-hidden="true">⧉</span>
          </div>
        </label>

        <label class="with-placeholder-icon">{{ tr('admin_email_broadcast_bcc_label') }}
          <div class="input-wrap">
            <input v-model="templateForm.bcc" :title="tr('admin_email_broadcast_bcc_title_hint')" />
            <span class="placeholder-indicator" :title="tr('admin_email_broadcast_bcc_title_hint')" aria-hidden="true">⧉</span>
          </div>
        </label>

        <label class="with-placeholder-icon">{{ tr('admin_email_broadcast_body_label') }}
          <div class="input-wrap">
            <textarea v-model="templateForm.body" rows="6" :title="placeholderText || tr('admin_email_broadcast_columns_placeholder')" required></textarea>
            <span class="placeholder-indicator" :title="placeholderText || tr('admin_email_broadcast_columns_placeholder')" aria-hidden="true">⧉</span>
          </div>
        </label>

        <div class="form-actions">
          <IconButton icon="save" :label="tr('create')" type="submit" variant="success" />
          <IconButton icon="close" :label="tr('cancel')" @click="showCreateForm = false" variant="secondary" />
        </div>
      </form>
    </div>

   <!-- Edit Form (Inline, toggled by button) -->
   <div v-if="showEditForm" class="card create-form">
     <div class="card-header">
       <h3>{{ tr('admin_email_broadcast_edit_template_title') }}</h3>
       <IconButton icon="close" :label="tr('cancel')" @click="showEditForm = false" />
     </div>

     <form @submit.prevent="saveTemplate(editTemplate)">
       <div class="grid">
         <label>{{ tr('admin_email_broadcast_name_label') }}
           <input v-model="editTemplate.name" required />
         </label>
         
         <label class="with-placeholder-icon">{{ tr('admin_email_broadcast_subject_label') }}
           <div class="input-wrap">
             <input v-model="editTemplate.subject" :title="placeholderText || tr('admin_email_broadcast_columns_placeholder')" required />
             <span class="placeholder-indicator" :title="placeholderText || tr('admin_email_broadcast_columns_placeholder')" aria-hidden="true">⧉</span>
           </div>
         </label>

         <label>{{ tr('admin_email_broadcast_transport_group_label') }}
           <select v-model.number="editTemplate.transportGroupId" :disabled="!isAdminOrSuperAdmin">
             <option value="__none__">{{ tr('none') }}</option>
             <template v-for="opt in transportGroupOptions" :key="opt.value">
               <option :value="opt.value">{{ opt.label }}</option>
             </template>
           </select>
         </label>

         <label>{{ tr('admin_email_broadcast_ical_template_label') }}
           <select v-model.number="editTemplate.ical_template_id">
             <option value="__none__">{{ tr('none') }}</option>
             <template v-for="opt in icalTemplateOptions" :key="opt.value">
               <option :value="opt.value">{{ opt.label }}</option>
             </template>
           </select>
         </label>

         <label>{{ tr('admin_email_broadcast_attachment_template_label') }}
           <select v-model.number="editTemplate.attachment_template_id">
             <option value="__none__">{{ tr('none') }}</option>
             <template v-for="opt in attachmentTemplateOptions" :key="opt.value">
               <option :value="opt.value">{{ opt.label }}</option>
             </template>
           </select>
         </label>

         <label class="with-placeholder-icon">{{ tr('admin_email_broadcast_to_label') }}
           <div class="input-wrap">
             <input v-model="editTemplate.to" :title="tr('admin_email_broadcast_to_title_hint')" />
             <span class="placeholder-indicator" :title="tr('admin_email_broadcast_to_title_hint')" aria-hidden="true">⧉</span>
           </div>
         </label>
       </div>

       <label class="with-placeholder-icon">{{ tr('admin_email_broadcast_cc_label') }}
         <div class="input-wrap">
           <input v-model="editTemplate.cc" :title="tr('admin_email_broadcast_cc_title_hint')" />
           <span class="placeholder-indicator" :title="tr('admin_email_broadcast_cc_title_hint')" aria-hidden="true">⧉</span>
         </div>
       </label>

       <label class="with-placeholder-icon">{{ tr('admin_email_broadcast_bcc_label') }}
         <div class="input-wrap">
           <input v-model="editTemplate.bcc" :title="tr('admin_email_broadcast_bcc_title_hint')" />
           <span class="placeholder-indicator" :title="tr('admin_email_broadcast_bcc_title_hint')" aria-hidden="true">⧉</span>
         </div>
       </label>

       <label class="with-placeholder-icon">{{ tr('admin_email_broadcast_body_label') }}
         <div class="input-wrap">
           <textarea v-model="editTemplate.body" rows="6" :title="placeholderText || tr('admin_email_broadcast_columns_placeholder')" required></textarea>
           <span class="placeholder-indicator" :title="placeholderText || tr('admin_email_broadcast_columns_placeholder')" aria-hidden="true">⧉</span>
         </div>
       </label>

       <div class="form-actions">
         <IconButton icon="save" :label="tr('save')" type="submit" variant="success" />
         <IconButton icon="close" :label="tr('cancel')" @click="showEditForm = false" variant="secondary" />
       </div>
     </form>
   </div>

    <!-- Main Card -->
    <div class="card">
      <div class="card-header">
        <h3>{{ tr('admin_email_broadcast_templates_tab') }}</h3>
        <IconButton icon="plus" :label="tr('create')" @click="showCreateForm = true" />
      </div>

      <details v-if="placeholders.length" class="placeholder-info">
        <summary>{{ tr('admin_email_broadcast_placeholder_info_title') }}</summary>
        <div class="placeholder-list">
          <code v-for="token in placeholders" :key="token">{{ token }}</code>
        </div>
      </details>

      <AdminDataTable
        :columns="templateColumns"
        :rows="templates"
        row-key="id"
        :loading="loading"
        enable-search
        :page-size="10"
        persist-key="admin-email-templates"
        :empty-text="tr('admin_email_broadcast_empty_templates_text')"
        :initial-hidden-columns="['id', 'to', 'cc', 'bcc', 'ical_template_id', 'attachment_template_id']"
        @refresh="loadTemplates"
      >
        <template #cell-name="{ row }">
          <input v-model="row.name" :disabled="!canManageTemplates" @change="saveTemplate(row)" />
        </template>
        <template #cell-subject="{ row }">
          <div class="with-placeholder-icon">
            <input v-model="row.subject" :disabled="!canManageTemplates" @change="saveTemplate(row)" :title="placeholderText || tr('admin_email_broadcast_columns_placeholder')" />
            <span class="placeholder-indicator" :title="placeholderText || tr('admin_email_broadcast_columns_placeholder')" aria-hidden="true">⧉</span>
          </div>
        </template>
        <template #cell-to="{ row }">
                  <input v-model="row.to" :disabled="!canManageTemplates" @change="saveTemplate(row)" placeholder="{{recipient_email}}" />
                </template>
                <template #cell-cc="{ row }">
                  <input v-model="row.cc" :disabled="!canManageTemplates" @change="saveTemplate(row)" placeholder="kommagetrennt" />
                </template>
        <template #cell-bcc="{ row }">
          <input v-model="row.bcc" :disabled="!canManageTemplates" @change="saveTemplate(row)" placeholder="kommagetrennt" />
        </template>
        <template #cell-body="{ row }">
          <div class="body-row">
            <div class="with-placeholder-icon">
              <textarea v-model="row.body" rows="6" class="body-input" :disabled="!canManageTemplates" @change="saveTemplate(row)" :title="placeholderText || tr('admin_email_broadcast_columns_placeholder')"></textarea>
              <span class="placeholder-indicator" :title="placeholderText || tr('admin_email_broadcast_columns_placeholder')" aria-hidden="true">⧉</span>
            </div>
          </div>
        </template>
        <template #cell-transportGroupId="{ row }">
          <select
            v-model="row.transportGroupId"
            :disabled="!isAdminOrSuperAdmin"
            @change="saveTemplate(row)"
            :title="isAdminOrSuperAdmin ? '' : tr('admin_email_broadcast_transport_group_moderator_hint')"
          >
            <option value="__none__">{{ tr('none') }}</option>
            <template v-for="opt in transportGroupOptions" :key="opt.value">
              <option :value="opt.value">{{ opt.label }}</option>
            </template>
          </select>
        </template>
        <template #cell-ical_template_id="{ row }">
          <select
            v-model="row.ical_template_id"
            :disabled="!canManageTemplates"
            @change="saveTemplate(row)"
          >
            <option value="__none__">{{ tr('none') }}</option>
            <template v-for="opt in icalTemplateOptions" :key="opt.value">
              <option :value="opt.value">{{ opt.label }}</option>
            </template>
          </select>
        </template>
        <template #cell-attachment_template_id="{ row }">
          <select
            v-model="row.attachment_template_id"
            :disabled="!canManageTemplates"
            @change="saveTemplate(row)"
          >
            <option value="__none__">{{ tr('none') }}</option>
            <template v-for="opt in attachmentTemplateOptions" :key="opt.value">
              <option :value="opt.value">{{ opt.label }}</option>
            </template>
          </select>
        </template>
        <template #row-actions="{ row }">
          <IconButton variant="primary" icon="pencil" v-if="canManageTemplates" label="Edit" @click="openEditForm(row)" />
          <IconButton variant="primary" icon="eye" v-if="canManageTemplates" label="Vorschau" @click="openPreview(row)" />
          <IconButton variant="primary" icon="copy" v-if="canManageTemplates" :label="tr('icon_buttons_clone')" @click="cloneTemplate(row)" />
          <IconButton variant="danger" icon="trash" v-if="canManageTemplates" :label="tr('admin_email_broadcast_columns_delete')" @click="deleteTemplate(row.id)" />
        </template>
      </AdminDataTable>

      <p v-if="!canManageTemplates && templates.length === 0" class="hint">{{ tr('admin_email_broadcast_templates_hint') }}</p>
    </div>

    <EmailTemplatePreview v-model="showPreviewDialog" :template="previewTemplate" @close="closePreview" />
  </div>
</template>

<style scoped>
.stack { display: flex; flex-direction: column; gap: 0.75rem; }
.card { border: 1px solid var(--border-strong); border-radius: 8px; padding: 0.75rem; background: var(--app-card-bg, var(--surface)); color: var(--text); box-shadow: 0 6px 18px var(--shadow); display: flex; flex-direction: column; gap: 0.75rem; }
.card-header { display: flex; justify-content: space-between; align-items: center; gap: 0.5rem; }

/* Body column styles */
.body-row {
  grid-column: 1 / -1;
  margin-top: 0.5rem;
  margin-bottom: 0.5rem;
}

.placeholder-info summary { cursor: pointer; color: var(--primary); font-weight: 600; }
.placeholder-list { display: flex; flex-wrap: wrap; gap: 0.25rem; margin-top: 0.35rem; }

.with-placeholder-icon { position: relative; display: flex; align-items: center; gap: 0.35rem; width: 100%; }
.input-wrap { position: relative; display: flex; align-items: center; gap: 0.35rem; width: 100%; }
.input-wrap input, .input-wrap textarea { flex: 1; width: 100%; }
.placeholder-indicator { color: var(--primary); font-size: 0.9rem; cursor: help; }

.create-form { margin-bottom: 1rem; border: 2px solid var(--primary); }
.form-actions { display: flex; gap: 0.5rem; justify-content: flex-end; margin-top: 1rem; }
.btn-primary { padding: 0.5rem 1rem; background: var(--primary); color: var(--primary-contrast); border: none; border-radius: 4px; cursor: pointer; }
.btn-secondary { padding: 0.5rem 1rem; background: var(--surface-muted); color: var(--text); border: 1px solid var(--border); border-radius: 4px; cursor: pointer; }

/* Input styles */
label { display: flex; flex-direction: column; gap: 0.25rem; font-weight: 600; color: var(--text); text-align: center; }
select, input, textarea { font: inherit; padding: 0.5rem; border: 1px solid var(--border); border-radius: 6px; background: var(--surface); color: var(--text); }
textarea.body-input { width: 100%; min-height: 120px; resize: vertical; }

.grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(220px, 1fr)); gap: 0.5rem; align-items: center; }
.hint { color: #6b7280; font-size: 0.9rem; }
</style>
