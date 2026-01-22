
<template>
  <div class="webhook-template-form">
    <h3>{{ template && template.id ? 'Webhook-Vorlage bearbeiten' : 'Neue Webhook-Vorlage' }}</h3>
    <form @submit.prevent="submit">
      <div class="form-group">
        <label>Name</label>
        <input v-model="form.name" required maxlength="255" />
      </div>
      <div class="form-group">
        <label>Beschreibung</label>
        <input v-model="form.description" maxlength="255" />
      </div>
      <div class="form-group">
        <label>Webhook-URL</label>
        <input v-model="form.url" required type="url" maxlength="500" placeholder="https://example.com/webhook" />
      </div>
      <div class="form-group">
        <label>Payload-Template <small>(Platzhalter unterstützt)</small></label>
        <textarea v-model="form.payload_template" required rows="6" style="font-family:monospace;width:100%"></textarea>
      </div>
      <div class="form-group">
        <label>Header-Template <small>(optional, JSON, Platzhalter unterstützt)</small></label>
        <textarea v-model="form.headers_template" rows="3" style="font-family:monospace;width:100%"></textarea>
      </div>
      <div class="form-actions" style="display:flex;gap:0.5em;justify-content:flex-end;">
        <IconButton icon="x" label="Abbrechen" variant="ghost" type="button" @click="$emit('close')" />
        <IconButton icon="check" label="Speichern" type="submit" />
      </div>
    </form>
    <div class="info-box" style="margin-top:1em;">
      <strong>Platzhalter:</strong>
      <span v-if="loadingPlaceholders">Lade Platzhalter ...</span>
      <span v-else-if="placeholders.length === 0">Keine Platzhalter verfügbar.</span>
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
    const apiKey = localStorage.getItem('admin_api_key') || sessionStorage.getItem('admin_api_key') || ''
    const res = await fetch('/api/admin/placeholders', { headers: { 'X-Api-Key': apiKey } })
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
  background: #fff;
  border-radius: 10px;
  padding: 2em;
  margin: 1.5em 0;
  box-shadow: 0 2px 8px #0001;
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
  background: #f1f5f9;
  border-radius: 6px;
  padding: 0.7em 1em;
  font-size: 0.95em;
  color: #334155;
}
</style>
