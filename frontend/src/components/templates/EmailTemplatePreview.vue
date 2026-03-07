<script setup>
import { ref, computed, watch } from 'vue'
import IconButton from '../IconButton.vue'
import { renderMarkdown } from '../../utils/markdown'
import { useTranslation } from '../../composables/useTranslation'

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
    const res = await fetch(`/api/admin/email-templates/${props.template.id}/preview`, {
      headers: {
        'Content-Type': 'application/json',
        Accept: 'application/json'
      },
      credentials: 'same-origin'
    })
    
    if (!res.ok) throw new Error(await res.text())
    
    const data = await res.json()
    previewData.value = data
  } catch (e) {
    console.error('Failed to load preview:', e)
    alert(tr('error_loading_preview') + ': ' + e.message)
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
      <IconButton icon="cancel" :label="tr('email_template_preview_close')" variant="danger" class="close-btn" @click="close" />
      
      <div class="modal-body">
        <h3>{{ tr('email_template_preview_title') }}: {{ template?.name }}</h3>
        
        <div v-if="previewData">
          <div class="preview-section">
            <label>{{ tr('email_template_preview_subject') }}</label>
            <div class="preview-value">{{ previewData.subject }}</div>
          </div>
          
          <div class="preview-section">
            <label>{{ tr('email_template_preview_body') }}</label>
            <div class="preview-body" v-html="renderMarkdown(previewData.body)"></div>
          </div>
        </div>
        
        <div v-else class="loading">{{ tr('email_template_preview_loading') }}</div>
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

.close-btn {
  position: absolute;
  top: 0.5rem;
  right: 0.5rem;
  z-index: 2;
}

.modal-body {
  padding: 1rem;
  overflow-y: auto;
  flex: 1;
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
}

.preview-body {
  background: var(--surface-muted);
  padding: 0.75rem;
  border-radius: 6px;
  border: 1px solid var(--border);
  min-height: 100px;
}

.placeholder-list {
  list-style: none;
  padding: 0;
  margin: 0;
  display: flex;
  flex-wrap: wrap;
  gap: 0.25rem;
}

.placeholder-list li {
  background: var(--surface-muted);
  color: var(--text);
  padding: 0.25rem 0.5rem;
  border-radius: 4px;
  font-family: monospace;
  font-size: 0.9rem;
  border: 1px solid var(--border);
}

.modal-footer {
  padding: 1rem;
  border-top: 1px solid var(--border-strong);
  display: flex;
  justify-content: flex-end;
}

.modal-footer button {
  background: var(--primary);
  color: var(--primary-contrast);
  border: none;
  padding: 0.5rem 1rem;
  border-radius: 6px;
  cursor: pointer;
  font: inherit;
}

.modal-footer button:hover {
  opacity: 0.9;
}

.loading {
  padding: 2rem;
  text-align: center;
  color: var(--text-muted);
}
</style>
