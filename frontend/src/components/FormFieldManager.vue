<script setup>
import { ref, reactive, onMounted, onUnmounted } from 'vue'
import IconButton from './IconButton.vue'
import AdminDataTable from './AdminDataTable.vue'
import { buildAdminHeaders } from '../utils/adminApi'
import { useTranslation } from '../composables/useTranslation'

const placeholders = ref([])
const loadingPlaceholders = ref(false)

async function loadPlaceholders() {
  loadingPlaceholders.value = true
  try {
    const res = await fetch(`${import.meta.env.VITE_API_BASE || '/api'}/admin/placeholders`, { headers: buildAdminHeaders(), credentials: 'same-origin' })
    if (res.ok) {
      placeholders.value = await res.json()
    }
  } finally {
    loadingPlaceholders.value = false
  }
}

const props = defineProps({ langCode: { type: String, default: 'de' } })
const { tr } = useTranslation()

const apiBase = import.meta.env.VITE_API_BASE || '/api'
const apiKey = ref(localStorage.getItem('admin_auth_session') || sessionStorage.getItem('admin_auth_session') || '')
const fields = ref([])
const loading = ref(false)
const message = ref('')
const error = ref('')
const lastAutoErrorAt = ref(0)

const protectedKeys = ['name', 'email']
const selectedFields = ref([])

const fieldColumns = [
  { key: 'id', label: 'ID', sortable: true },
  { key: 'key', label: 'Key', sortable: true },
  { key: 'label', label: 'Label', sortable: true },
  { key: 'placeholder', label: 'Placeholder', sortable: true },
  { key: 'help_text', label: 'Hilfetext', sortable: true },
  { key: 'text_align', label: 'Ausrichtung', sortable: true },
  { key: 'type', label: 'Typ', sortable: true },
  { key: 'required', label: 'Pflicht', sortable: true },
  { key: 'visible_public', label: 'Public', sortable: true },
  { key: 'visible_admin', label: 'Admin', sortable: true },
  { key: 'active', label: 'Aktiv', sortable: true },
  { key: 'options', label: 'Optionen', sortable: false },
  { key: 'order', label: 'Reihenfolge', sortable: true },
]

const form = reactive({
  key: '',
  label: '',
  type: 'text',
  required: false,
  options: [],
  placeholder: '',
  help_text: '',
  text_align: 'left',
  min_length: null,
  max_length: null,
  pattern: '',
  order: 0,
  active: true,
  visible_public: true,
  visible_admin: true,
  is_email: false,
})

function setMessage(msg) { message.value = msg; error.value = '' }
function setError(msg, opts = {}) {
  if (opts.auto) {
    const now = Date.now()
    if (now - lastAutoErrorAt.value < 30000) return
    lastAutoErrorAt.value = now
  }
  error.value = msg; message.value = ''
}

const authHeaders = () => buildAdminHeaders({ apiKeyRef: apiKey, includeJson: true })

async function load(opts = {}) {
  if (!apiKey.value) { setError(tr('api_key_required'), opts); return }
  loading.value = true
  try {
    const res = await fetch(`${apiBase}/admin/form-fields`, { headers: authHeaders() })
    if (!res.ok) throw new Error(await res.text())
    fields.value = await res.json()
    if (!opts.auto) setMessage(tr('fields_loaded'))
  } catch (e) { setError(tr('error_loading_preview') + ': ' + e, opts) } finally { loading.value = false }
}

async function save() {
  if (!apiKey.value) { setError(tr('api_key_required')); return }
  loading.value = true
  try {
    if (protectedKeys.includes(form.key)) throw new Error('Schlüssel ist reserviert.')
    const payload = { ...form, options: form.options.filter(Boolean) }
    const res = await fetch(`${apiBase}/admin/form-fields`, { method: 'POST', headers: authHeaders(), body: JSON.stringify(payload) })
    const text = await res.text()
    if (!res.ok) throw new Error(text)
    Object.assign(form, { key: '', label: '', type: 'text', required: false, options: [], placeholder: '', help_text: '', text_align: 'left', min_length: null, max_length: null, pattern: '', order: 0, active: true, visible_public: true, visible_admin: true, is_email: false })
    setMessage(tr('field_saved'))
    await load()
  } catch (e) { setError(`Speichern fehlgeschlagen: ${e}`) } finally { loading.value = false }
}

async function updateField(field) {
  loading.value = true
  try {
    const payload = { ...field, options: (field.options || []).filter(Boolean) }
    const res = await fetch(`${apiBase}/admin/form-fields/${field.id}`, { method: 'PATCH', headers: authHeaders(), body: JSON.stringify(payload) })
    const text = await res.text()
    if (!res.ok) throw new Error(text)
    setMessage(tr('field_updated'))
  } catch (e) { setError(`Speichern fehlgeschlagen: ${e}`) } finally { loading.value = false }
}

async function removeField(id) {
  const target = fields.value.find(f => f.id === id)
  if (target && protectedKeys.includes(target.key)) { setError(tr('field_delete_confirm')); return }
  if (!confirm(tr('field_delete_confirm'))) return
  loading.value = true
  try {
    const res = await fetch(`${apiBase}/admin/form-fields/${id}`, { method: 'DELETE', headers: authHeaders() })
    const text = await res.text()
    if (!res.ok) throw new Error(text)
    fields.value = fields.value.filter(f => f.id !== id)
    selectedFields.value = selectedFields.value.filter(sel => sel !== id)
    setMessage(tr('field_deleted'))
  } catch (e) { setError(`Löschen fehlgeschlagen: ${e}`) } finally { loading.value = false }
}

async function bulkRemoveFields() {
  const deletable = selectedFields.value.filter(id => {
    const target = fields.value.find(f => f.id === id)
    return target && !protectedKeys.includes(target.key)
  })
  if (!deletable.length) { setError(tr('no_fields_selected_for_deletion')); return }
  if (!confirm(tr('fields_delete_confirm', { count: deletable.length }))) return
  loading.value = true
  try {
    for (const id of deletable) {
      const res = await fetch(`${apiBase}/admin/form-fields/${id}`, { method: 'DELETE', headers: authHeaders() })
      const text = await res.text()
      if (!res.ok) throw new Error(text)
    }
    fields.value = fields.value.filter(f => !deletable.includes(f.id))
    selectedFields.value = []
    setMessage(tr('fields_deleted'))
  } catch (e) { setError(`Löschen fehlgeschlagen: ${e}`) } finally { loading.value = false }
}

async function reorderFields(ids) {
  loading.value = true
  try {
    const reordered = ids
      .map(id => fields.value.find(f => f.id === id))
      .filter(Boolean)
      .map((f, idx) => ({ ...f, order: idx }))
    fields.value = reordered
    for (const f of reordered) {
      const res = await fetch(`${apiBase}/admin/form-fields/${f.id}`, { method: 'PATCH', headers: authHeaders(), body: JSON.stringify({ order: f.order }) })
      const text = await res.text()
      if (!res.ok) throw new Error(text)
    }
    setMessage(tr('order_updated'))
  } catch (e) {
    setError(tr('order_save_failed') + ': ' + e)
  } finally {
    loading.value = false
  }
}

function splitOptions(str, field) {
  if (field) {
    field.options = str.split(',').map(o => o.trim()).filter(Boolean)
  } else {
    form.options = str.split(',').map(o => o.trim()).filter(Boolean)
  }
}

function handleKeyUpdate(e) { apiKey.value = e.detail || '' }

onMounted(() => {
  window.addEventListener('api-key-updated', handleKeyUpdate)
  if (apiKey.value) {
    load()
    loadPlaceholders()
  }
})

onUnmounted(() => {
  window.removeEventListener('api-key-updated', handleKeyUpdate)
})
</script>

<template>
  <div class="stack">
    <div v-if="message" class="message">{{ message }}</div>
    <div v-if="error" class="error">{{ error }}</div>

    <details v-if="placeholders.length" class="placeholder-info" aria-live="polite">
      <summary>Platzhalter anzeigen</summary>
      <p class="placeholder-hint-text">Verwendbar in <strong>Label</strong>, <strong>Placeholder</strong> und <strong>Hilfetext</strong>:</p>
      <div class="placeholder-list">
        <code v-for="token in placeholders" :key="token">{{ token }}</code>
      </div>
    </details>

    <section class="card">
      <h3>Neues Feld</h3>
      <div class="grid">
        <label>Key<input v-model="form.key" /></label>
        <label>Label<input v-model="form.label" /></label>
        <label>Typ
          <select v-model="form.type">
            <option value="text">Text</option>
            <option value="textarea">Textarea</option>
            <option value="select">Select</option>
            <option value="email">E-Mail</option>
            <option value="checkbox">Checkbox</option>
          </select>
        </label>
        <label>Options (kommagetrennt)<input :value="form.options.join(', ')" @input="splitOptions($event.target.value)" /></label>
        <label>Placeholder<input v-model="form.placeholder" /></label>
        <label>Hilfetext<input v-model="form.help_text" /></label>
        <label>Textausrichtung
          <select v-model="form.text_align">
            <option value="left">Links</option>
            <option value="center">Zentriert</option>
            <option value="right">Rechts</option>
          </select>
        </label>
        <label>Min Länge<input v-model.number="form.min_length" type="number" min="0" /></label>
        <label>Max Länge<input v-model.number="form.max_length" type="number" min="0" /></label>
        <label>Pattern<input v-model="form.pattern" /></label>
        <label>Reihenfolge<input v-model.number="form.order" type="number" min="0" /></label>
        <label>Aktiv<input type="checkbox" v-model="form.active" /></label>
        <label>Public sichtbar<input type="checkbox" v-model="form.visible_public" /></label>
        <label>Admin sichtbar<input type="checkbox" v-model="form.visible_admin" /></label>
        <label>Erforderlich<input type="checkbox" v-model="form.required" /></label>
        <label>Ist E-Mail<input type="checkbox" v-model="form.is_email" /></label>
      </div>
      <IconButton icon="save" label="Speichern" @click="save" :disabled="loading" />
    </section>

    <section class="card">
      <h3>Felder</h3>
      <AdminDataTable
        :columns="fieldColumns"
        :rows="fields"
        v-model="selectedFields"
        selectable
        :loading="loading"
        :page-size="50"
        persist-key="admin-form-fields"
        empty-text="Keine Felder vorhanden."
        @refresh="load"
        @auto-refresh="load({ auto: true })"
        row-draggable
        @reorder="reorderFields"
      >
        <template #actions>
          <IconButton icon="trash" variant="danger" label="Auswahl löschen" @click="bulkRemoveFields" :disabled="loading || !selectedFields.length" />
        </template>
        <template #cell-label="{ row }">
          <input v-model="row.label" @change="updateField(row)" />
        </template>
        <template #cell-placeholder="{ row }">
          <input v-model="row.placeholder" @change="updateField(row)" />
        </template>
        <template #cell-help_text="{ row }">
          <input v-model="row.help_text" @change="updateField(row)" />
        </template>
        <template #cell-text_align="{ row }">
          <select v-model="row.text_align" @change="updateField(row)">
            <option value="left">Links</option>
            <option value="center">Zentriert</option>
            <option value="right">Rechts</option>
          </select>
        </template>
        <template #cell-type="{ row }">
          <select v-model="row.type" @change="updateField(row)" :disabled="protectedKeys.includes(row.key)">
            <option value="text">Text</option>
            <option value="textarea">Textarea</option>
            <option value="select">Select</option>
            <option value="email">E-Mail</option>
            <option value="checkbox">Checkbox</option>
          </select>
        </template>
        <template #cell-required="{ row }"><input type="checkbox" v-model="row.required" @change="updateField(row)" /></template>
        <template #cell-visible_public="{ row }"><input type="checkbox" v-model="row.visible_public" @change="updateField(row)" /></template>
        <template #cell-visible_admin="{ row }"><input type="checkbox" v-model="row.visible_admin" @change="updateField(row)" /></template>
        <template #cell-active="{ row }"><input type="checkbox" v-model="row.active" @change="updateField(row)" /></template>
        <template #cell-options="{ row }">
          <input :value="(row.options || []).join(', ')" @input="splitOptions($event.target.value, row); updateField(row)" />
        </template>
        <template #cell-order="{ row }"><input v-model.number="row.order" type="number" min="0" @change="updateField(row)" /></template>
        <template #row-actions="{ row }">
          <IconButton variant="danger" icon="trash" label="Löschen" @click="removeField(row.id)" :disabled="protectedKeys.includes(row.key)" />
        </template>
      </AdminDataTable>
    </section>
  </div>
</template>

<style scoped>
.stack { display: flex; flex-direction: column; gap: 0.75rem; }
.controls { display: flex; gap: 0.75rem; flex-wrap: wrap; align-items: end; }
.card { border: 1px solid #e5e7eb; border-radius: 8px; padding: 1rem; background: #fff; }
.grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(220px, 1fr)); gap: 0.75rem; }
label { display: flex; flex-direction: column; gap: 0.25rem; font-weight: 600; }
input, select, button { font: inherit; padding: 0.5rem; border: 1px solid #d1d5db; border-radius: 6px; }
button { background: #2563eb; color: #fff; cursor: pointer; }
button.danger { background: #dc2626; }
button:disabled { opacity: 0.6; cursor: not-allowed; }
.message { color: #065f46; background: #ecfdf3; border: 1px solid #a7f3d0; padding: 0.5rem; border-radius: 6px; }
.error { color: #991b1b; background: #fef2f2; border: 1px solid #fecaca; padding: 0.5rem; border-radius: 6px; }
.placeholder-info { font-size: 0.9rem; color: var(--text-muted); }
.placeholder-info summary { cursor: pointer; color: var(--primary); font-weight: 600; }
.placeholder-hint-text { margin: 0.35rem 0 0.5rem; font-size: 0.9rem; color: var(--text-muted); }
.placeholder-list { display: flex; flex-wrap: wrap; gap: 0.25rem; margin-top: 0.35rem; }
.placeholder-list code { background: var(--surface-muted); color: var(--text); padding: 0.2rem 0.35rem; border-radius: 4px; border: 1px solid var(--border); }
</style>
