<template>
  <div class="modal-backdrop" @click.self="$emit('close')">
    <div class="modal">
      <h3>Neues Kennwort für {{ user?.name || user?.email }}</h3>
      <form @submit.prevent="submit">
        <label>Neues Kennwort:
          <input v-model="password" type="password" required autocomplete="new-password" />
        </label>
        <label>Wiederholen:
          <input v-model="passwordRepeat" type="password" required autocomplete="new-password" />
        </label>
        <div v-if="error" class="error">{{ error }}</div>
        <div class="actions">
          <IconButton icon="check" label="Speichern" type="submit" />
          <IconButton icon="x" label="Abbrechen" variant="ghost" type="button" @click="$emit('close')" />
        </div>
      </form>
    </div>
  </div>
</template>

<script setup>
import { ref, watch } from 'vue'
import IconButton from './IconButton.vue'
const props = defineProps({
  user: { type: Object, required: true },
  loading: Boolean
})
const emit = defineEmits(['submit', 'close'])
const password = ref('')
const passwordRepeat = ref('')
const error = ref('')

function submit() {
  error.value = ''
  if (!password.value || password.value.length < 6) {
    error.value = 'Kennwort zu kurz.'
    return
  }
  if (password.value !== passwordRepeat.value) {
    error.value = 'Kennwörter stimmen nicht überein.'
    return
  }
  emit('submit', password.value)
  password.value = ''
  passwordRepeat.value = ''
}

watch(() => props.loading, l => {
  if (!l) {
    password.value = ''
    passwordRepeat.value = ''
    error.value = ''
  }
})
</script>

<style scoped>
.modal-backdrop { position: fixed; inset: 0; background: rgba(15,23,42,0.35); display: flex; align-items: center; justify-content: center; z-index: 100; }
.modal { background: #fff; border-radius: 12px; padding: 1.5rem; min-width: 320px; max-width: 95vw; box-shadow: 0 20px 50px rgba(15, 23, 42, 0.2); }
.actions { display: flex; gap: 0.5rem; justify-content: flex-end; margin-top: 1rem; }
.error { color: #991b1b; background: #fef2f2; border: 1px solid #fecaca; padding: 0.5rem; border-radius: 6px; margin-top: 0.5rem; }
</style>
