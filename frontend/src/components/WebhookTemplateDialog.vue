
<template>
  <div class="webhook-template-form">
    <h3>{{ template && template.id ? tr('webhook_template_dialog_title_edit') : tr('webhook_template_dialog_title_new') }}</h3>
    <form @submit.prevent="submit">
      <div class="form-group">
        <label>{{ tr('webhook_template_dialog_label_name') }}</label>
        <input v-model="form.name" required maxlength="255" />
      </div>
      <div class="form-group">
        <label>{{ tr('webhook_template_dialog_label_description') }}</label>
        <input v-model="form.description" maxlength="255" />
      </div>
      <div class="form-group">
        <label>{{ tr('webhook_template_dialog_label_url') }}</label>
        <input v-model="form.url" required type="url" maxlength="500" placeholder="https://example.com/webhook" />
      </div>
      <div class="form-group">
        <label>{{ tr('webhook_template_dialog_label_payload_template') }} <small>{{ tr('webhook_template_dialog_placeholder_supported') }}</small></label>
        <textarea v-model="form.payload_template" required rows="6" style="font-family:monospace;width:100%"></textarea>
      </div>
      <div class="form-group">
        <label>{{ tr('webhook_template_dialog_label_headers_template') }} <small>{{ tr('webhook_template_dialog_optional_json') }}</small></label>
        <textarea v-model="form.headers_template" rows="3" style="font-family:monospace;width:100%"></textarea>
      </div>
      <div class="form-actions" style="display:flex;gap:0.5em;justify-content:flex-end;">
        <IconButton icon="close" :label="tr('webhook_template_dialog_button_cancel')" variant="danger" type="button" @click="$emit('close')" />
        <IconButton icon="check" :label="tr('webhook_template_dialog_button_save')" type="submit" />
      </div>
    </form>
    <div class="info-box" style="margin-top:1em;">
      <strong>{{ tr('webhook_template_dialog_label_placeholders') }}</strong>
      <span v-if="loadingPlaceholders">{{ tr('webhook_template_dialog_loading_placeholders') }}</span>
      <span v-else-if="placeholders.length === 0">{{ tr('webhook_template_dialog_no_placeholders') }}</span>
      <span v-else class="placeholder-list">
        <template v-for="ph in placeholders" :key="ph">
          <code>{{ ph }}</code>
        </template>
      </span>
    </div>
  </div>
</template>


<script setup>
import { reactive, watch, ref, onMounted } from 'vue'
import IconButton from './IconButton.vue'
import { buildAdminHeaders } from '../utils/adminApi'
import { useTranslation } from '../composables/useTranslation'

const { tr } = useTranslation()
const props = defineProps({
  template: { type: Object, default: null }
})
const emit = defineEmits(['close', 'save'])

const form = reactive({
  name: '',
  description: '',
  url: '',
  payload_template: '',
  headers_template: ''
})

const placeholders = ref([])
const loadingPlaceholders = ref(false)

async function loadPlaceholders() {
  loadingPlaceholders.value = true
  try {
    const res = await fetch('/api/admin/placeholders', { headers: buildAdminHeaders() })
    if (res.ok) {
      placeholders.value = await res.json()
    }
  } finally {
    loadingPlaceholders.value = false
  }
}

onMounted(loadPlaceholders)

watch(() => props.template, (tpl) => {
  if (tpl) {
    form.name = tpl.name || ''
    form.description = tpl.description || ''
    form.url = tpl.url || ''
    form.payload_template = tpl.payload_template || ''
    form.headers_template = tpl.headers_template || ''
  } else {
    form.name = ''
    form.description = ''
    form.url = ''
    form.payload_template = ''
    form.headers_template = ''
  }
}, { immediate: true })

function submit() {
  const data = { ...form }
  if (props.template && props.template.id) data.id = props.template.id
  emit('save', data)
}
</script>

<style scoped>
.webhook-template-form {
  background: var(--app-card-bg, var(--surface));
  color: var(--text);
  border-radius: 12px;
  padding: 2em;
  margin: 1.5em 0;
  border: 1px solid var(--border-strong);
  box-shadow: 0 12px 30px var(--shadow);
  max-width: 600px;
}
.form-group {
  margin-bottom: 1em;
}
.form-actions {
  display: flex;
  justify-content: flex-end;
  gap: 1em;
  margin-top: 1em;
}
.info-box {
  background: var(--surface-muted);
  border-radius: 6px;
  padding: 0.7em 1em;
  font-size: 0.95em;
  color: var(--text);
  border: 1px solid var(--border);
}
</style>
