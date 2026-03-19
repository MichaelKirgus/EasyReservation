<script setup>
import { ref, reactive, computed, onMounted } from 'vue'
import AdminDataTable from './AdminDataTable.vue'
import IconButton from './IconButton.vue'
import api from '../api'
import { useTranslation } from '../composables/useTranslation'

const { tr } = useTranslation()

// ---- Notifications ----
const message = ref('')
const error = ref('')
function setMessage(msg) { message.value = msg; error.value = '' }
function setError(msg) { error.value = msg; message.value = '' }

// ---- Config ----
const config = ref({ enabled: true, mode: 'blacklist' })

async function loadConfig() {
  try {
    const res = await api.get('/admin/validation-rules/config')
    config.value = res.data
  } catch (e) {
    setError(tr('admin_validation_rules_error_load_config', 'Failed to load config: {{message}}', {
      message: e.response?.data?.message || e.message,
    }))
  }
}

async function saveConfig() {
  try {
    await api.patch('/admin/validation-rules/config', config.value)
  } catch (e) {
    setError(tr('admin_validation_rules_error_save_config', 'Failed to save config: {{message}}', {
      message: e.response?.data?.message || e.message,
    }))
  }
}

// ---- Rules ----
const rules = ref([])
const loading = ref(false)

const tableColumns = computed(() => [
  { key: 'active', label: tr('admin_validation_rules_column_active', 'Active'), sortable: true },
  { key: 'name', label: tr('admin_validation_rules_column_name', 'Rule Name'), sortable: true },
  { key: 'field_key', label: tr('admin_validation_rules_column_field', 'Field'), sortable: true },
  { key: 'condition_display', label: tr('admin_validation_rules_column_condition', 'Condition'), sortable: false },
  { key: 'webhook_template_name', label: tr('admin_validation_rules_column_webhook', 'Webhook'), sortable: false },
])

const tableRows = computed(() =>
  rules.value.map(r => ({
    ...r,
    condition_display: [formatConditionType(r.condition_type), r.condition_operator, r.condition_value]
      .filter(Boolean).join(' '),
  }))
)

async function loadRules() {
  loading.value = true
  try {
    const res = await api.get('/admin/validation-rules')
    rules.value = res.data
  } catch (e) {
    setError(tr('admin_validation_rules_error_load_rules', 'Failed to load rules: {{message}}', {
      message: e.response?.data?.message || e.message,
    }))
  } finally {
    loading.value = false
  }
}

async function toggleActive(row) {
  const rule = rules.value.find(r => r.id === row.id)
  if (!rule) return
  const active = !rule.active
  try {
    await api.put(`/admin/validation-rules/${rule.id}`, { ...rule, active })
    rule.active = active
  } catch (e) {
    setError(tr('admin_validation_rules_error_update_rule', 'Failed to update rule: {{message}}', {
      message: e.response?.data?.message || e.message,
    }))
    await loadRules()
  }
}

async function deleteRule(id) {
  if (!confirm(tr('admin_validation_rules_confirm_delete', 'Are you sure you want to delete this rule?'))) return
  try {
    await api.delete(`/admin/validation-rules/${id}`)
    rules.value = rules.value.filter(r => r.id !== id)
    setMessage(tr('admin_validation_rules_message_deleted', 'Rule deleted.'))
  } catch (e) {
    setError(tr('admin_validation_rules_error_delete_rule', 'Failed to delete rule: {{message}}', {
      message: e.response?.data?.message || e.message,
    }))
  }
}

async function cloneRule(row) {
  try {
    const payload = {
      name: `${row.name} (${tr('admin_validation_rules_copy_suffix', 'Copy')})`,
      description: row.description || '',
      active: row.active !== false,
      field_key: row.field_key,
      condition_type: row.condition_type,
      condition_operator: row.condition_operator,
      condition_value: row.condition_value != null ? String(row.condition_value) : '',
      error_message: row.error_message || '',
      webhook_template_id: row.webhook_template_id ?? null,
    }

    const res = await api.post('/admin/validation-rules', payload)
    rules.value.push(res.data)
    setMessage(tr('admin_validation_rules_message_cloned', 'Rule cloned.'))
  } catch (e) {
    setError(tr('admin_validation_rules_error_clone_rule', 'Failed to clone rule: {{message}}', {
      message: e.response?.data?.message || e.message,
    }))
  }
}

// ---- Form ----
const showForm = ref(false)
const editingId = ref(null)
const regexError = ref('')
const customFields = ref([])
const webhookTemplates = ref([])
const availablePlaceholders = ref([])
const loadingPlaceholders = ref(false)

const form = reactive({
  name: '',
  description: '',
  active: true,
  field_key: '',
  condition_type: '',
  condition_operator: '',
  condition_value: '',
  error_message: '',
  webhook_template_id: null,
})

function openCreateForm() {
  editingId.value = null
  resetForm()
  showForm.value = true
}

function openEditForm(rule) {
  editingId.value = rule.id
  form.name = rule.name || ''
  form.description = rule.description || ''
  form.active = rule.active !== false
  form.field_key = rule.field_key || ''
  form.condition_type = rule.condition_type || ''
  form.condition_operator = rule.condition_operator || ''
  form.condition_value = rule.condition_value != null ? String(rule.condition_value) : ''
  form.error_message = rule.error_message || ''
  form.webhook_template_id = rule.webhook_template_id ?? null
  regexError.value = ''
  if (form.condition_type === 'contains') loadPlaceholders()
  showForm.value = true
}

function cancelForm() {
  showForm.value = false
  editingId.value = null
  resetForm()
}

function resetForm() {
  form.name = ''
  form.description = ''
  form.active = true
  form.field_key = ''
  form.condition_type = ''
  form.condition_operator = ''
  form.condition_value = ''
  form.error_message = ''
  form.webhook_template_id = null
  regexError.value = ''
}

function onConditionTypeChange() {
  form.condition_operator = ''
  form.condition_value = ''
  regexError.value = ''
  if (form.condition_type === 'contains') loadPlaceholders()
}

function insertPlaceholder(ph) {
  form.condition_value = (form.condition_value || '') + ph
}

function validateRegex() {
  if (form.condition_type !== 'regex') return true
  try {
    new RegExp(form.condition_value)
    regexError.value = ''
    return true
  } catch (e) {
    regexError.value = tr('admin_validation_rules_invalid_regex', 'Invalid regex: {{message}}', { message: e.message })
    return false
  }
}

async function submitForm() {
  if (!validateRegex()) return
  try {
    const data = { ...form }
    if (editingId.value) {
      const res = await api.put(`/admin/validation-rules/${editingId.value}`, data)
      const idx = rules.value.findIndex(r => r.id === editingId.value)
      if (idx !== -1) rules.value[idx] = res.data
    } else {
      const res = await api.post('/admin/validation-rules', data)
      rules.value.push(res.data)
    }
    const wasEditing = !!editingId.value
    cancelForm()
    setMessage(wasEditing
      ? tr('admin_validation_rules_message_updated', 'Rule updated.')
      : tr('admin_validation_rules_message_created', 'Rule created.'))
  } catch (e) {
    setError(e.response?.data?.message || e.message || tr('admin_validation_rules_error_save_rule', 'Failed to save rule'))
  }
}

// ---- Supporting data ----
async function loadAvailableFields() {
  try {
    const res = await api.get('/admin/validation-rules/available-fields')
    customFields.value = res.data.filter(f => f.key !== 'name' && f.key !== 'email')
  } catch { /* non-critical */ }
}

async function loadWebhookTemplates() {
  try {
    const res = await api.get('/admin/validation-rules/webhook-templates')
    webhookTemplates.value = res.data
  } catch { /* non-critical */ }
}

async function loadPlaceholders() {
  loadingPlaceholders.value = true
  try {
    const res = await api.get('/admin/placeholders')
    availablePlaceholders.value = res.data || []
  } catch {
    availablePlaceholders.value = []
  } finally {
    loadingPlaceholders.value = false
  }
}

// ---- Helpers ----
const commonUnicodeCategories = computed(() => [
  { code: 'Emoji', label: tr('admin_validation_rules_unicode_emoji', 'Emoji') },
  { code: 'So', label: tr('admin_validation_rules_unicode_so', 'Symbol, Other') },
  { code: 'Sm', label: tr('admin_validation_rules_unicode_sm', 'Symbol, Math') },
  { code: 'Sc', label: tr('admin_validation_rules_unicode_sc', 'Symbol, Currency') },
  { code: 'Sk', label: tr('admin_validation_rules_unicode_sk', 'Symbol, Modifier') },
  { code: 'P', label: tr('admin_validation_rules_unicode_p', 'Punctuation') },
  { code: 'Z', label: tr('admin_validation_rules_unicode_z', 'Separator') },
  { code: 'C', label: tr('admin_validation_rules_unicode_c', 'Control/Format') },
  { code: 'L', label: tr('admin_validation_rules_unicode_l', 'Letter') },
  { code: 'N', label: tr('admin_validation_rules_unicode_n', 'Number') },
  { code: 'M', label: tr('admin_validation_rules_unicode_m', 'Mark') },
])

function formatConditionType(type) {
  return {
    length: tr('admin_validation_rules_type_length', 'Length'),
    regex: tr('admin_validation_rules_type_regex', 'Regex'),
    unicode_category: tr('admin_validation_rules_type_unicode', 'Unicode'),
    contains: tr('admin_validation_rules_type_contains', 'Contains'),
      email_format: tr('admin_validation_rules_type_email_format', 'Email Format'),
  }[type] || type
}

onMounted(() => {
  loadConfig()
  loadRules()
  loadAvailableFields()
  loadWebhookTemplates()
})
</script>

<template>
  <div class="stack">
    <!-- Notifications -->
    <div v-if="message" class="notice notice-success">{{ message }}</div>
    <div v-if="error" class="notice notice-error">{{ error }}</div>

    <!-- Config Card -->
    <div class="card">
      <div class="card-header">
        <h3>{{ tr('admin_validation_rules_config_title', 'Configuration') }}</h3>
      </div>
      <label class="toggle-row">
        <input v-model="config.enabled" type="checkbox" @change="saveConfig" />
        <span>{{ tr('admin_validation_rules_enable_engine', 'Enable Validation Rules Engine') }}</span>
      </label>
      <p class="hint">{{ tr('admin_validation_rules_enable_hint', 'When disabled, all validation requests bypass the rules engine') }}</p>
      <template v-if="config.enabled">
        <div class="mode-label">{{ tr('admin_validation_rules_mode_title', 'Validation Mode') }}</div>
        <div class="mode-options">
          <label class="toggle-row">
            <input v-model="config.mode" type="radio" value="blacklist" @change="saveConfig" />
            <span>
              <strong>{{ tr('admin_validation_rules_mode_blacklist', 'Blacklist') }}</strong>
              <span class="hint">{{ tr('admin_validation_rules_mode_blacklist_hint', 'If any rule fails, validation fails (fail-fast)') }}</span>
            </span>
          </label>
          <label class="toggle-row">
            <input v-model="config.mode" type="radio" value="whitelist" @change="saveConfig" />
            <span>
              <strong>{{ tr('admin_validation_rules_mode_whitelist', 'Whitelist') }}</strong>
              <span class="hint">{{ tr('admin_validation_rules_mode_whitelist_hint', 'All rules must pass for validation to succeed') }}</span>
            </span>
          </label>
        </div>
      </template>
    </div>

    <!-- Inline Form Card -->
    <div v-if="showForm" class="card form-card">
      <div class="card-header">
        <h3>{{ editingId ? tr('admin_validation_rules_form_title_edit', 'Edit Rule') : tr('admin_validation_rules_form_title_new', 'New Rule') }}</h3>
        <IconButton icon="close" :label="tr('admin_validation_rules_cancel', 'Cancel')" variant="ghost" size="sm" @click="cancelForm" />
      </div>
      <form @submit.prevent="submitForm" class="rule-form">

        <!-- Name + Active -->
        <div class="form-grid">
          <label class="field">
            {{ tr('admin_validation_rules_field_rule_name', 'Rule Name') }} *
            <input v-model="form.name" required maxlength="255" :placeholder="tr('admin_validation_rules_placeholder_rule_name', 'e.g., Forbidden Words')" />
          </label>
          <label class="toggle-row field-active">
            <input v-model="form.active" type="checkbox" />
            <span>{{ tr('admin_validation_rules_field_active', 'Active') }}</span>
          </label>
        </div>

        <!-- Description -->
        <label class="field">
          {{ tr('admin_validation_rules_field_description', 'Description') }}
          <textarea v-model="form.description" maxlength="500" rows="2" :placeholder="tr('admin_validation_rules_placeholder_description', 'Optional description')"></textarea>
        </label>

        <!-- Field + Condition Type -->
        <div class="form-grid">
          <label class="field">
            {{ tr('admin_validation_rules_field_to_validate', 'Field to Validate') }} *
            <select v-model="form.field_key" required>
              <option value="">{{ tr('admin_validation_rules_select_field', '-- Select Field --') }}</option>
              <option value="name">{{ tr('admin_validation_rules_builtin_name', 'Name') }}</option>
              <option value="email">{{ tr('admin_validation_rules_builtin_email', 'Email') }}</option>
              <option v-for="f in customFields" :key="f.key" :value="f.key">{{ f.label }}</option>
            </select>
          </label>
          <label class="field">
            {{ tr('admin_validation_rules_condition_type', 'Condition Type') }} *
            <select v-model="form.condition_type" required @change="onConditionTypeChange">
              <option value="">{{ tr('admin_validation_rules_select_type', '-- Select Type --') }}</option>
              <option value="length">{{ tr('admin_validation_rules_type_length_chars', 'Length (Character Count)') }}</option>
              <option value="regex">{{ tr('admin_validation_rules_type_regex_full', 'Regular Expression') }}</option>
              <option value="unicode_category">{{ tr('admin_validation_rules_type_unicode_full', 'Unicode Category (Emoji, etc.)') }}</option>
              <option value="contains">{{ tr('admin_validation_rules_type_contains_full', 'Contains Text') }}</option>
              <option value="email_format">{{ tr('admin_validation_rules_type_email_format_full', 'Valid Email Format') }}</option>
            </select>
          </label>
        </div>

        <!-- Length condition -->
        <template v-if="form.condition_type === 'length'">
          <div class="form-grid">
            <label class="field">
              {{ tr('admin_validation_rules_operator', 'Operator') }} *
              <select v-model="form.condition_operator" required>
                <option value="">{{ tr('admin_validation_rules_select_operator', '-- Select --') }}</option>
                <option value="<">{{ tr('admin_validation_rules_operator_lt', '< (Less than)') }}</option>
                <option value=">">{{ tr('admin_validation_rules_operator_gt', '> (Greater than)') }}</option>
                <option value="=">{{ tr('admin_validation_rules_operator_eq', '= (Equals)') }}</option>
                <option value="<=">{{ tr('admin_validation_rules_operator_lte', '<= (Less or equal)') }}</option>
                <option value=">=">{{ tr('admin_validation_rules_operator_gte', '>= (Greater or equal)') }}</option>
              </select>
            </label>
            <label class="field">
              {{ tr('admin_validation_rules_character_count', 'Character Count') }} *
                <input v-model="form.condition_value" type="number" required min="0" />
            </label>
          </div>
        </template>

        <!-- Regex condition -->
        <template v-else-if="form.condition_type === 'regex'">
          <div class="form-grid">
            <label class="field">
              {{ tr('admin_validation_rules_operator', 'Operator') }} *
              <select v-model="form.condition_operator" required>
                <option value="">{{ tr('admin_validation_rules_select_operator', '-- Select --') }}</option>
                <option value="match">{{ tr('admin_validation_rules_operator_match', 'Matches Regex') }}</option>
                <option value="not_match">{{ tr('admin_validation_rules_operator_not_match', 'Does NOT Match Regex') }}</option>
              </select>
            </label>
            <label class="field">
              {{ tr('admin_validation_rules_regex_pattern', 'Regex Pattern') }} *
              <input v-model="form.condition_value" required :placeholder="tr('admin_validation_rules_placeholder_regex', 'e.g., /^[a-z]+@example\\.com$/')" @blur="validateRegex" />
              <small>{{ tr('admin_validation_rules_regex_help', 'Pattern with delimiters (e.g., /pattern/flags)') }}</small>
            </label>
          </div>
          <div v-if="regexError" class="notice notice-error small">{{ regexError }}</div>
        </template>

        <!-- Unicode condition -->
        <template v-else-if="form.condition_type === 'unicode_category'">
          <div class="form-grid">
            <label class="field">
              {{ tr('admin_validation_rules_operator', 'Operator') }} *
              <select v-model="form.condition_operator" required>
                <option value="">{{ tr('admin_validation_rules_select_operator', '-- Select --') }}</option>
                <option value="contains">{{ tr('admin_validation_rules_operator_contains', 'Contains') }}</option>
                <option value="not_contains">{{ tr('admin_validation_rules_operator_not_contains', 'Does NOT Contain') }}</option>
              </select>
            </label>
            <label class="field">
              {{ tr('admin_validation_rules_unicode_category', 'Unicode Category') }} *
              <input v-model="form.condition_value" :placeholder="tr('admin_validation_rules_placeholder_unicode', 'e.g., Emoji, So, P')" />
            </label>
          </div>
          <div class="category-chips">
            <button
              v-for="cat in commonUnicodeCategories"
              :key="cat.code"
              type="button"
              class="chip"
              :class="{ active: form.condition_value === cat.code }"
              @click="form.condition_value = cat.code"
            >{{ cat.label }} ({{ cat.code }})</button>
          </div>
        </template>

        <!-- Contains condition -->
        <template v-else-if="form.condition_type === 'contains'">
          <div class="form-grid">
            <label class="field">
              {{ tr('admin_validation_rules_operator', 'Operator') }} *
              <select v-model="form.condition_operator" required>
                <option value="">{{ tr('admin_validation_rules_select_operator', '-- Select --') }}</option>
                <option value="contains">{{ tr('admin_validation_rules_operator_contains_ci', 'Contains (case-insensitive)') }}</option>
                <option value="not_contains">{{ tr('admin_validation_rules_operator_not_contains_ci', 'Does NOT Contain (case-insensitive)') }}</option>
              </select>
            </label>
            <label class="field">
              {{ tr('admin_validation_rules_text_to_find', 'Text to Find') }} *
              <input v-model="form.condition_value" required :placeholder="tr('admin_validation_rules_placeholder_contains', 'e.g., badword')" />
              <small>{{ tr('admin_validation_rules_contains_help') }}</small>
            </label>
          </div>
          <div v-if="loadingPlaceholders" class="placeholder-box">{{ tr('admin_validation_rules_loading_placeholders', 'Loading placeholders...') }}</div>
          <div v-else-if="availablePlaceholders.length" class="placeholder-box">
            <span class="placeholder-label">{{ tr('admin_validation_rules_available_placeholders', 'Available placeholders (click to insert):') }}</span>
            <div class="placeholder-chips">
              <code
                v-for="ph in availablePlaceholders"
                :key="ph"
                class="ph-chip"
                @click="insertPlaceholder(ph)"
              >{{ ph }}</code>
            </div>
          </div>
        </template>

        <!-- Error message + Webhook -->
        <!-- Email format condition -->
        <template v-else-if="form.condition_type === 'email_format'">
          <label class="field">
            {{ tr('admin_validation_rules_operator', 'Operator') }} *
            <select v-model="form.condition_operator" required>
              <option value="">{{ tr('admin_validation_rules_select_operator', '-- Select --') }}</option>
              <option value="valid">{{ tr('admin_validation_rules_operator_email_valid', 'Is a valid email address') }}</option>
              <option value="invalid">{{ tr('admin_validation_rules_operator_email_invalid', 'Is NOT a valid email address') }}</option>
            </select>
          </label>
          <p class="hint">{{ tr('admin_validation_rules_email_format_hint') }}</p>
        </template>

        <div class="form-grid">
          <label class="field">
            {{ tr('admin_validation_rules_custom_error_message', 'Custom Error Message') }}
            <textarea v-model="form.error_message" maxlength="500" rows="2" :placeholder="tr('admin_validation_rules_placeholder_error_message', 'Leave empty for default message')"></textarea>
          </label>
          <label class="field">
            {{ tr('admin_validation_rules_webhook_to_trigger', 'Webhook to Trigger') }} <small>({{ tr('admin_validation_rules_optional', 'optional') }})</small>
            <select v-model.number="form.webhook_template_id">
              <option :value="null">{{ tr('admin_validation_rules_no_webhook', '-- No Webhook --') }}</option>
              <option v-for="wh in webhookTemplates" :key="wh.id" :value="wh.id">{{ wh.name }}</option>
            </select>
          </label>
        </div>

        <!-- Form actions -->
        <div class="form-actions">
          <IconButton icon="check" :label="editingId ? tr('admin_validation_rules_update_rule', 'Update Rule') : tr('admin_validation_rules_create_rule', 'Create Rule')" variant="success" type="submit" />
          <IconButton icon="close" :label="tr('admin_validation_rules_cancel', 'Cancel')" variant="ghost" type="button" @click="cancelForm" />
        </div>
      </form>
    </div>

    <!-- Rules Table -->
    <AdminDataTable
      :columns="tableColumns"
      :rows="tableRows"
      :loading="loading"
      persist-key="validation-rules"
      @refresh="loadRules"
      @auto-refresh="loadRules"
    >
      <template #title>
        <span class="table-title">{{ tr('admin_validation_rules_title', 'Validation Rules') }}</span>
      </template>
      <template #actions>
        <IconButton icon="plus" :label="tr('admin_validation_rules_add_rule', 'Add Rule')" variant="success" size="sm" @click="openCreateForm" />
      </template>

      <!-- Active: interactive toggle -->
      <template #cell-active="{ row }">
        <input type="checkbox" :checked="row.active" @change="toggleActive(row)" />
      </template>

      <!-- Name with optional description / error sub-lines -->
      <template #cell-name="{ row }">
        <div class="cell-name">{{ row.name }}</div>
        <div v-if="row.description" class="cell-sub">{{ row.description }}</div>
        <div v-if="row.error_message" class="cell-err">{{ row.error_message }}</div>
      </template>

      <!-- Condition badges -->
      <template #cell-condition_display="{ row }">
        <div class="cond-cell">
          <span class="badge badge-type">{{ formatConditionType(row.condition_type) }}</span>
          <span class="badge badge-op">{{ row.condition_operator }}</span>
          <code class="cond-val">{{ row.condition_value?.length > 30 ? row.condition_value.substring(0, 30) + '…' : row.condition_value }}</code>
        </div>
      </template>

      <!-- Webhook badge -->
      <template #cell-webhook_template_name="{ row }">
        <span v-if="row.webhook_template_name" class="badge badge-webhook">{{ row.webhook_template_name }}</span>
        <span v-else class="cell-empty">—</span>
      </template>

      <!-- Row actions -->
      <template #row-actions="{ row }">
        <IconButton icon="pencil" :label="tr('admin_validation_rules_edit', 'Edit')" size="sm" variant="ghost" @click="openEditForm(row)" />
        <IconButton icon="copy" :label="tr('admin_validation_rules_clone', 'Clone')" size="sm" variant="ghost" @click="cloneRule(row)" />
        <IconButton icon="trash" :label="tr('admin_validation_rules_delete', 'Delete')" size="sm" variant="ghost" @click="deleteRule(row.id)" />
      </template>
    </AdminDataTable>
  </div>
</template>

<style scoped>
.stack { display: flex; flex-direction: column; gap: 0.75rem; }

/* Card */
.card {
  border: 1px solid var(--border-strong);
  border-radius: 8px;
  padding: 1rem;
  background: var(--app-card-bg, var(--card));
  color: var(--text);
  display: flex;
  flex-direction: column;
  gap: 0.75rem;
}
.card-header { display: flex; align-items: center; justify-content: space-between; gap: 0.5rem; }
.card-header h3 { margin: 0; font-size: 1.05rem; font-weight: 600; }

/* Notices */
.notice { padding: 0.5rem 0.75rem; border-radius: 6px; font-size: 0.9rem; }
.notice-success { color: var(--success-text); background: var(--success-bg); border: 1px solid var(--success-border); }
.notice-error { color: var(--error-text); background: var(--error-bg); border: 1px solid var(--error-border); }
.notice.small { padding: 0.35rem 0.5rem; font-size: 0.82rem; }

/* Config */
.toggle-row { display: flex; flex-direction: row; align-items: center; gap: 0.5rem; cursor: pointer; color: var(--text); }
.hint { margin: 0; font-size: 0.85rem; color: var(--text-muted); }
.mode-label { font-weight: 600; color: var(--text); font-size: 0.95rem; }
.mode-options { display: flex; flex-direction: column; gap: 0.5rem; padding-left: 0.25rem; }
.mode-options .toggle-row { align-items: flex-start; }
.mode-options .toggle-row span { display: flex; flex-direction: column; gap: 0.15rem; }

/* Form */
.form-card { box-shadow: 0 6px 18px var(--shadow); }
.rule-form { display: flex; flex-direction: column; gap: 0.75rem; }
.form-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(240px, 1fr)); gap: 0.75rem; align-items: start; }
.field { display: flex; flex-direction: column; gap: 0.3rem; font-weight: 600; font-size: 0.9rem; color: var(--text); }
.field-active { justify-content: flex-end; padding-bottom: 0.3rem; }

.rule-form input:not([type="checkbox"]):not([type="radio"]),
.rule-form select,
.rule-form textarea {
  font: inherit;
  padding: 0.45rem 0.6rem;
  border: 1px solid var(--border);
  border-radius: 6px;
  background: var(--surface);
  color: var(--text);
  width: 100%;
  box-sizing: border-box;
}
.rule-form input:not([type="checkbox"]):not([type="radio"]):focus,
.rule-form select:focus,
.rule-form textarea:focus {
  outline: none;
  border-color: var(--primary, #2563eb);
  box-shadow: 0 0 0 2px rgba(37, 99, 235, 0.15);
}
.field small { font-size: 0.8rem; color: var(--text-muted); font-weight: 400; margin-top: 0.1rem; }

.form-actions {
  display: flex;
  gap: 0.5rem;
  justify-content: flex-end;
  padding-top: 0.5rem;
  border-top: 1px solid var(--border-strong);
}

/* Unicode category chips */
.category-chips { display: flex; flex-wrap: wrap; gap: 0.4rem; }
.chip {
  padding: 0.3rem 0.6rem;
  border: 1px solid var(--border-strong);
  border-radius: 4px;
  background: var(--surface);
  color: var(--text);
  cursor: pointer;
  font-size: 0.82rem;
  transition: all 0.15s;
}
.chip:hover { border-color: var(--primary, #2563eb); background: var(--surface-muted); }
.chip.active { background: var(--primary, #2563eb); color: var(--primary-contrast, #fff); border-color: var(--primary, #2563eb); }

/* Placeholder chips */
.placeholder-box {
  padding: 0.6rem 0.75rem;
  background: var(--surface-muted);
  border: 1px solid var(--border-strong);
  border-radius: 6px;
  display: flex;
  flex-direction: column;
  gap: 0.4rem;
  font-size: 0.85rem;
  color: var(--text);
}
.placeholder-label { font-weight: 600; }
.placeholder-chips { display: flex; flex-wrap: wrap; gap: 0.35rem; }
.ph-chip {
  display: inline-block;
  padding: 0.25rem 0.5rem;
  background: var(--surface);
  border: 1px solid var(--border-strong);
  border-radius: 4px;
  font-family: monospace;
  font-size: 0.8rem;
  cursor: pointer;
  color: var(--text);
  transition: all 0.15s;
  user-select: none;
}
.ph-chip:hover { background: var(--surface-muted); border-color: var(--primary, #2563eb); color: var(--primary, #2563eb); }

/* Table cells */
.table-title { font-weight: 600; color: var(--text); }
.cell-name { font-weight: 500; }
.cell-sub { font-size: 0.82rem; color: var(--text-muted); margin-top: 0.15rem; }
.cell-err { font-size: 0.8rem; color: var(--error-text, #dc2626); margin-top: 0.15rem; }
.cell-empty { color: var(--text-muted); }
.cond-cell { display: flex; flex-direction: column; gap: 0.2rem; }
.badge {
  display: inline-block;
  padding: 0.15rem 0.45rem;
  border-radius: 4px;
  font-size: 0.78rem;
  font-weight: 500;
}
.badge-type { background: var(--surface-muted); color: var(--text); border: 1px solid var(--border-strong); }
.badge-op { background: var(--surface-muted); color: var(--text-muted); border: 1px solid var(--border); }
.badge-webhook { background: var(--surface-muted); color: var(--primary, #2563eb); border: 1px solid var(--border-strong); }
.cond-val {
  font-family: monospace;
  background: var(--surface-muted);
  padding: 0.15rem 0.35rem;
  border-radius: 4px;
  font-size: 0.8rem;
  word-break: break-all;
  color: var(--text);
}

@media (max-width: 640px) {
  .form-grid { grid-template-columns: 1fr; }
}
</style>
