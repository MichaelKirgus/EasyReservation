<script setup>
import { ref, reactive, watch } from 'vue'
import IconButton from './IconButton.vue'
import { useTranslation } from '../composables/useTranslation'

const props = defineProps({
  modelValue: { type: Boolean, default: false },
  loading: { type: Boolean, default: false },
  error: { type: String, default: '' },
  showOtp: { type: Boolean, default: false },
})
const emit = defineEmits(['update:modelValue', 'login'])

// Use the global translation system
const { tr } = useTranslation()

const loginForm = reactive({ identifier: '', password: '', otp: '' })
const rememberMe = ref(true)

watch(() => props.modelValue, (val) => {
  if (!val) {
    loginForm.identifier = ''
    loginForm.password = ''
    loginForm.otp = ''
    rememberMe.value = true
  }
})

function close() {
  emit('update:modelValue', false)
}
function handleLogin() {
  emit('login', { ...loginForm, rememberMe: rememberMe.value })
}
</script>

<template>
  <div v-if="modelValue" class="modal-backdrop" @click.self="close">
    <div class="modal">
      <IconButton icon="cancel" :label="tr('cancel', 'Cancel')" variant="danger" class="close-btn" size="sm" @click="close" />
      <h3>{{ tr('login', 'Login') }}</h3>
      <label class="form-field">{{ tr('username_or_email', 'Username or Email') }}
        <input v-model="loginForm.identifier" class="input-lg" />
      </label>
      <label class="form-field">{{ tr('password', 'Password') }}
        <input v-model="loginForm.password" type="password" :placeholder="tr('password_placeholder', '••••••')" class="input-lg" />
      </label>
      <label v-if="showOtp" class="form-field">{{ tr('otp_code', 'OTP Code') }}
        <input v-model="loginForm.otp" :placeholder="tr('otp_placeholder', '123456')" class="input-lg" />
      </label>
      <label class="checkbox">
        <input type="checkbox" v-model="rememberMe" />
        <span>{{ tr('remember_me', 'Remember me') }}</span>
      </label>
      <div class="modal-actions">
        <IconButton icon="login" :label="tr('login', 'Login')" :disabled="loading" @click="handleLogin" class="btn-block" />
      </div>
      <div v-if="error" class="error">{{ error }}</div>
    </div>
  </div>
</template>

<style scoped>
.modal-backdrop {
  position: fixed;
  inset: 0;
  background: rgba(15, 23, 42, 0.35);
  display: flex;
  align-items: center;
  justify-content: center;
  padding: 1rem;
  z-index: 20;
}
.modal {
  background: #fff;
  border-radius: 12px;
  padding: 1rem;
  width: min(420px, 100%);
  box-shadow: 0 20px 50px rgba(15, 23, 42, 0.2);
  display: flex;
  flex-direction: column;
  gap: 0.75rem;
  position: relative;
}
.modal .form-field {
  display: flex;
  flex-direction: column;
  gap: 0.35rem;
  font-weight: 600;
  width: 100%;
}
.modal .form-field input {
  width: 100%;
}
.input-lg {
  width: 100%;
  font-size: 1.08rem;
  padding: 0.65rem 0.75rem;
  border-radius: 8px;
  border: 1px solid #d1d5db;
  box-sizing: border-box;
}
.modal .checkbox {
  display: flex;
  align-items: center;
  gap: 0.5rem;
  font-weight: 600;
}
.modal .checkbox input {
  width: auto;
}
.modal-actions {
  display: flex;
  margin-top: 0.5rem;
}
.btn-block {
  width: 100%;
  justify-content: center;
}
.close-btn {
  position: absolute;
  top: 0.5rem;
  right: 0.5rem;
  z-index: 2;
}
.error {
  color: #991b1b;
  background: #fef2f2;
  border: 1px solid #fecaca;
  padding: 0.5rem;
  border-radius: 6px;
}
</style>
