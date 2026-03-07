<script setup>
import { computed } from 'vue'

const props = defineProps({
  visible: { type: Boolean, default: false },
  header: { type: String, default: '' },
  style: { type: Object, default: () => ({}) }
})

const emit = defineEmits(['update:visible'])

function close() {
  emit('update:visible', false)
}

const showDialog = computed({
  get: () => props.visible,
  set: (value) => emit('update:visible', value)
})
</script>

<template>
  <div v-if="visible" class="dialog-overlay">
    <div class="dialog" :style="style">
      <div class="dialog-header">
        <h3>{{ header }}</h3>
        <button class="close-btn" @click="close">&times;</button>
      </div>
      <div class="dialog-content">
        <slot></slot>
      </div>
    </div>
  </div>
</template>

<style scoped>
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

.dialog-header h3 {
  margin: 0;
  font-size: 1.25rem;
  color: var(--text);
}

.close-btn {
  background: none;
  border: none;
  font-size: 1.5rem;
  cursor: pointer;
  color: var(--text-muted);
  padding: 0.25rem 0.5rem;
}

.dialog-content {
  padding: 1rem;
}
</style>
