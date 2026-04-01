<script setup>
import { ref, computed, watch } from 'vue'
import IconButton from '../IconButton.vue'
import { useTranslation } from '../../composables/useTranslation'
import { adminFetch } from '../../utils/adminApi'

const { tr } = useTranslation()

const props = defineProps({
  modelValue: {
    type: Boolean,
    default: false
  },
  template: {
    type: Object,
    default: null
  }
})

const emit = defineEmits(['update:modelValue', 'close'])

const show = ref(false)
const previewData = ref(null)
const routePrefix = ref(localStorage.getItem('admin_route_prefix') || 'admin')

watch(() => props.modelValue, (val) => {
  show.value = val
  if (val && props.template) {
    loadPreview()
  }
})

watch(show, (val) => {
  emit('update:modelValue', val)
})

async function loadPreview() {
  try {
    const res = await adminFetch(`ical-templates/${props.template.id}/preview`, {
      headers: {
        'Content-Type': 'application/json',
        Accept: 'application/json'
      },
    }, { routePrefixRef: routePrefix })
    
    if (!res.ok) throw new Error(await res.text())
    
    const data = await res.json()
    previewData.value = data
  } catch (e) {
    console.error('Failed to load preview:', e)
    alert(tr('ical_template_preview_error_loading') + ': ' + e.message)
  }
}

function close() {
  show.value = false
  emit('close')
}
</script>

<template>
  <div v-if="show" class="modal-overlay">
    <div class="modal-content">
      <div class="modal-header">
        <h3>{{ tr('ical_template_preview_title') }}: {{ template?.name }}</h3>
        <IconButton icon="cancel" :label="tr('email_template_preview_close')" variant="danger" class="close-btn" @click="close" />
      </div>
      
      <div class="modal-body">
        <div v-if="previewData">
          <div class="preview-section">
            <label>{{ tr('ical_template_preview_content_label') }}</label>
            <pre class="preview-value">{{ previewData.content }}</pre>
          </div>
          
        </div>
        
        <div v-else class="loading">{{ tr('ical_template_preview_loading') }}</div>
      </div>
    </div>
  </div>
</template>

<style scoped>
.modal-overlay {
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

.modal-content {
  background: var(--app-card-bg, var(--surface));
  border-radius: 8px;
  box-shadow: 0 6px 18px var(--shadow);
  max-width: 700px;
  width: 90%;
  max-height: 90vh;
  display: flex;
  flex-direction: column;
  position: relative;
}

.modal-header {
  padding: 1rem;
  border-bottom: 1px solid var(--border);
  display: flex;
  justify-content: space-between;
  align-items: center;
  gap: 1rem;
}

.modal-header h3 {
  margin: 0;
  font-size: 1.25rem;
  color: var(--text);
  flex: 1;
}

.close-btn {
  position: static;
}

.modal-body {
  padding: 1rem;
  overflow-y: auto;
  flex: 1;
}

.preview-section {
  margin-bottom: 1.5rem;
}

.preview-section label {
  display: block;
  font-weight: 600;
  color: var(--text);
  margin-bottom: 0.5rem;
}

.preview-value {
  background: var(--surface-muted);
  padding: 0.75rem;
  border-radius: 6px;
  border: 1px solid var(--border);
  font-family: monospace;
  white-space: pre-wrap;
  word-break: break-all;
  font-size: 0.9rem;
}

.placeholders-used {
  margin-top: 1rem;
  color: var(--text-muted);
}
</style>
