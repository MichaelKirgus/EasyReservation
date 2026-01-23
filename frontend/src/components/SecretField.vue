<template>
  <div class="secret-field">
    <input
      v-if="showValue"
      type="text"
      v-model="inputValue"
      class="secret-input input"
    />
    <input
      v-else
      type="password"
      v-model="inputValue"
      class="secret-input input"
    />
    <IconButton
      :icon="showValue ? 'eyeOff' : 'eye'"
      :label="showValue ? 'Verbergen' : 'Anzeigen'"
      @click.stop.prevent="toggleShow"
      :title="showValue ? 'Verbergen' : 'Anzeigen'"
      variant="ghost"
      size="sm"
      type="button"
    />
    <IconButton
      icon="copy"
      label="Kopieren"
      @click.stop.prevent="copy"
      title="Kopieren"
      variant="ghost"
      size="sm"
      type="button"
    />
  </div>
</template>

<script setup>
import { ref, computed } from 'vue'
import IconButton from './IconButton.vue'
const props = defineProps({
  modelValue: {},
  show: { type: Boolean, default: undefined },
})
const emit = defineEmits(['update:modelValue', 'update:show'])
const localShow = ref(false)
const showValue = computed({
  get: () => props.show !== undefined ? props.show : localShow.value,
  set: val => {
    if (props.show !== undefined) emit('update:show', val)
    else localShow.value = val
  }
})
const inputValue = computed({
  get: () => props.modelValue,
  set: val => emit('update:modelValue', val)
})
function toggleShow() { showValue.value = !showValue.value }
function copy() {
  if (props.modelValue) {
    navigator.clipboard.writeText(props.modelValue)
  }
}
</script>

<style scoped>
.secret-field { display: flex; align-items: center; }
.secret-input {
  flex: 1;
  font: inherit;
  padding: 0.5rem;
  border: 1px solid #d1d5db;
  border-radius: 6px;
  height: 40px;
  font-size: 1.05rem;
  box-sizing: border-box;
}
.input {
  font: inherit;
  padding: 0.5rem;
  border: 1px solid #d1d5db;
  border-radius: 6px;
  height: 40px;
  font-size: 1.05rem;
  box-sizing: border-box;
}
.icon-btn { margin-left: 4px; background: none; border: none; cursor: pointer; }
</style>
