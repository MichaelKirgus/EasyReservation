<script setup>
import { reactive, watch } from 'vue'
import { useTranslation } from '../composables/useTranslation'

const props = defineProps({
  modelValue: {
    type: Object,
    default: () => ({
      name: '',
      description: '',
      rateLimitEnabled: false,
      rateLimitPerMinute: null,
      rateLimitPerHour: null,
      failoverStrategy: 'sequential',
      maxRetriesPerAccount: 3,
      isActive: true,
    }),
  },
  isEditing: {
    type: Boolean,
    default: false,
  },
})

const emit = defineEmits(['update:modelValue', 'save', 'cancel'])
const { tr } = useTranslation()

const form = reactive({ ...props.modelValue })

// Keep local form synced with parent updates
watch(() => props.modelValue, (next) => {
  Object.assign(form, next || {})
}, { deep: true })

// Emit updates whenever fields change so parent model stays current
watch(form, (next) => {
  emit('update:modelValue', { ...next })
}, { deep: true })

function handleSave() {
  emit('save', { ...form })
}

function handleCancel() {
  emit('cancel')
}

// Get failover strategy options
const failoverStrategyOptions = [
  { value: 'sequential', label: 'Sequential (Failover)' },
  { value: 'round_robin', label: 'Round Robin' },
  { value: 'random', label: 'Random' },
]
</script>

<template>
  <div class="mail-group-form">
    <div class="form-grid">
      <!-- Name -->
      <label class="field">
        <span>Name</span>
        <input 
          v-model="form.name" 
          type="text" 
          placeholder="My Transport Group"
        />
      </label>
      
      <!-- Description -->
      <label class="field">
        <span>Description</span>
        <textarea 
          v-model="form.description" 
          rows="2"
          placeholder="Optional description..."
        ></textarea>
      </label>
      
      <!-- Rate Limit Toggle -->
      <label class="field checkbox-field">
        <input 
          type="checkbox" 
          v-model="form.rateLimitEnabled"
        />
        <span>Enable Rate Limiting</span>
      </label>
      
      <!-- Rate Limit Per Minute (only shown when enabled) -->
      <label class="field" v-if="form.rateLimitEnabled">
        <span>Rate Limit per Minute</span>
        <input 
          v-model.number="form.rateLimitPerMinute" 
          type="number" 
          min="1" 
          max="9999"
          placeholder="Unlimited (default)"
        />
      </label>
      
      <!-- Rate Limit Per Hour (only shown when enabled) -->
      <label class="field" v-if="form.rateLimitEnabled">
        <span>Rate Limit per Hour</span>
        <input
          v-model.number="form.rateLimitPerHour"
          type="number"
          min="1"
          max="99999"
          placeholder="Unlimited (default)"
        />
      </label>
      
      <!-- Failover Strategy -->
      <label class="field">
        <span>Failover Strategy</span>
        <select 
          v-model="form.failoverStrategy"
        >
          <option value="sequential">Sequential (Failover)</option>
          <option value="round_robin">Round Robin</option>
          <option value="random">Random</option>
        </select>
      </label>
      
      <!-- Max Retries -->
      <label class="field">
        <span>Max Retries per Account</span>
        <input
          v-model.number="form.maxRetriesPerAccount"
          type="number"
          min="1"
          max="10"
        />
      </label>
      
      <!-- Active -->
      <label class="field checkbox-field">
        <input 
          type="checkbox" 
          v-model="form.isActive"
        />
        <span>Active</span>
      </label>
    </div>
    
    <div class="form-actions">
      <button type="button" @click="handleCancel">{{ tr('cancel') }}</button>
      <button type="button" @click="handleSave" :disabled="!form.name">
        {{ isEditing ? tr('save') : tr('create') }}
      </button>
    </div>
  </div>
</template>

<style scoped>
/* Light/Dark Theme Support */
.mail-group-form { display: flex; flex-direction: column; gap: 1rem; }

.form-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(280px, 1fr)); gap: 1rem; }
.field { display: flex; flex-direction: column; gap: 0.35rem; font-weight: 600; color: var(--text); }
.field input, .field select, .field textarea { padding: 0.5rem; border: 1px solid var(--border); border-radius: 6px; width: 100%; box-sizing: border-box; background: var(--surface); color: var(--text); }
.field input[type="checkbox"] { width: auto; }

.form-actions { display: flex; justify-content: flex-end; gap: 0.75rem; padding-top: 1rem; border-top: 1px solid var(--border); }
button { padding: 0.5rem 1rem; border-radius: 6px; cursor: pointer; font: inherit; }
button[type="button"] { background: var(--surface-muted); color: var(--text-primary); border: 1px solid var(--border); }
button[type="submit"] { background: var(--primary); color: var(--primary-contrast); border: 1px solid var(--primary); }
button:disabled { opacity: 0.6; cursor: not-allowed; }

.checkbox-field { flex-direction: row; align-items: center; gap: 0.5rem; }
</style>
