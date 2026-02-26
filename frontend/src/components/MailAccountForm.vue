<script setup>
import { reactive, watch } from 'vue'
import SecretField from './SecretField.vue'

const props = defineProps({
  modelValue: {
    type: Object,
    default: () => ({
      name: '',
      host: '',
      port: 587,
      encryption: 'tls',
      username: '',
      password: '',
      authMethod: 'plain',
      ignoreSelfSigned: false,
      timeout: 30,
      rateLimitEnabled: false,
      rateLimitPerMinute: null,
      rateLimitPerHour: null,
      fromAddress: '', // Send as address
      replyToAddress: '', // Reply-to address
      returnPathAddress: '', // Bounce address
      tlsVersion: 'auto', // TLS version: auto, 1.2, 1.3
      isActive: true,
    }),
  },
  isEditing: {
    type: Boolean,
    default: false,
  },
})

const emit = defineEmits(['update:modelValue', 'save', 'cancel'])

// Local reactive copy for two-way binding
const form = reactive({ ...props.modelValue })

// Keep local form in sync when parent model changes
watch(() => props.modelValue, (next) => {
  Object.assign(form, next || {})
}, { deep: true })

// Emit updates so parent state stays current
watch(form, (next) => {
  emit('update:modelValue', { ...next })
}, { deep: true })

function handleSave() {
  emit('save', { ...form })
}

function handleCancel() {
  emit('cancel')
}

// Get encryption options
const encryptionOptions = [
  { value: 'tls', label: 'TLS' },
  { value: 'ssl', label: 'SSL' },
  { value: 'none', label: 'None' },
]

// Get TLS version options
const tlsVersionOptions = [
  { value: 'auto', label: 'Auto (Negotiate)' },
  { value: '1.2', label: 'TLS 1.2' },
  { value: '1.3', label: 'TLS 1.3' },
]

// Get auth method options - including modern auth for Exchange Online and Google Mail
const authMethodOptions = [
  { value: 'plain', label: 'Plain (Standard SMTP)' },
  { value: 'login', label: 'Login' },
  { value: 'crammd5', label: 'CRAM-MD5' },
  { value: 'oauth2_exchange', label: 'OAuth2 - Exchange Online' },
  { value: 'oauth2_google', label: 'OAuth2 - Google Mail/Workspace' },
  { value: 'api_key_sendgrid', label: 'API Key - SendGrid' },
  { value: 'api_key_mailgun', label: 'API Key - Mailgun' },
  { value: 'api_key_postmark', label: 'API Key - Postmark' },
]

// Get API key provider label based on auth method
function getApiKeyLabel() {
  if (form.authMethod === 'api_key_sendgrid') return 'SendGrid API Key'
  if (form.authMethod === 'api_key_mailgun') return 'Mailgun API Key'
  if (form.authMethod === 'api_key_postmark') return 'Postmark API Key'
  return 'API Key'
}
</script>

<template>
  <div class="mail-account-form">
    <div class="form-grid">
      <!-- Name -->
      <label class="field">
        <span>Name</span>
        <input
          v-model="form.name"
          type="text"
          placeholder="My Mail Account"
        />
      </label>
      
      <!-- Host (only for SMTP methods) -->
      <label class="field" v-if="!form.authMethod || !form.authMethod.startsWith('api_key')">
        <span>SMTP Host</span>
        <input
          v-model="form.host"
          type="text"
          placeholder="smtp.example.com"
        />
      </label>
      
      <!-- Port (only for SMTP methods) -->
      <label class="field" v-if="!form.authMethod || !form.authMethod.startsWith('api_key')">
        <span>Port</span>
        <input
          v-model.number="form.port"
          type="number"
          min="1"
          max="65535"
        />
      </label>
      
      <!-- Encryption (only for SMTP methods) -->
      <label class="field" v-if="!form.authMethod || !form.authMethod.startsWith('api_key')">
        <span>Encryption</span>
        <select
          v-model="form.encryption"
        >
          <option value="tls">TLS (Recommended)</option>
          <option value="ssl">SSL</option>
          <option value="none">None</option>
        </select>
      </label>
      
      <!-- Auth Method -->
      <label class="field">
        <span>Authentication Method</span>
        <select
          v-model="form.authMethod"
        >
          <option value="plain">Plain (Standard SMTP)</option>
          <option value="login">Login</option>
          <option value="crammd5">CRAM-MD5</option>
          <option value="oauth2_exchange">OAuth2 - Exchange Online</option>
          <option value="oauth2_google">OAuth2 - Google Mail/Workspace</option>
          <option value="api_key_sendgrid">API Key - SendGrid</option>
          <option value="api_key_mailgun">API Key - Mailgun</option>
          <option value="api_key_postmark">API Key - Postmark</option>
        </select>
      </label>
      
      <!-- Username / API Key -->
      <label class="field">
        <span v-if="form.authMethod && form.authMethod.startsWith('api_key')">{{ getApiKeyLabel() }}</span>
        <span v-else>Username / Email</span>
        <input
          v-model="form.username"
          type="text"
          :placeholder="form.authMethod && form.authMethod.startsWith('api_key') ? 'API Key' : ''"
        />
      </label>
      
      <!-- Password / Secret (only for non-API methods) -->
      <label class="field" v-if="form.authMethod && !form.authMethod.startsWith('api_key')">
        <span>Password / App Password</span>
        <SecretField
          v-model="form.password"
          :placeholder="isEditing ? '••••••••' : ''"
        />
      </label>
      
      <!-- API Key Secret (only for API key methods) -->
      <label class="field" v-if="form.authMethod && form.authMethod.startsWith('api_key')">
        <span>Secret / Password</span>
        <SecretField
          v-model="form.password"
          :placeholder="isEditing ? '••••••••' : ''"
        />
      </label>
      
      <!-- Ignore Self-Signed -->
      <label class="field checkbox-field">
        <input
          type="checkbox"
          v-model="form.ignoreSelfSigned"
        />
        <span>Ignore Self-Signed Certificates</span>
      </label>
      
      <!-- Timeout (only for SMTP methods) -->
      <label class="field" v-if="!form.authMethod || !form.authMethod.startsWith('api_key')">
        <span>Timeout (seconds)</span>
        <input
          v-model.number="form.timeout"
          type="number"
          min="1"
          max="300"
        />
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
      
      <!-- Active -->
      <label class="field checkbox-field">
        <input
          type="checkbox"
          v-model="form.isActive"
        />
        <span>Active</span>
      </label>
      
      <!-- Send As Address (From) -->
      <label class="field">
        <span>Send As Address (From)</span>
        <input
          v-model="form.fromAddress"
          type="email"
          placeholder="sender@example.com"
        />
      </label>
      
      <!-- Reply-To Address -->
      <label class="field">
        <span>Reply-To Address</span>
        <input
          v-model="form.replyToAddress"
          type="email"
          placeholder="replyto@example.com"
        />
      </label>
      
      <!-- Return-Path (Bounce) Address -->
      <label class="field">
        <span>Return-Path (Bounce Address)</span>
        <input
          v-model="form.returnPathAddress"
          type="email"
          placeholder="bounces@example.com"
        />
      </label>
      
      <!-- TLS Version -->
      <label class="field">
        <span>TLS Version</span>
        <select
          v-model="form.tlsVersion"
        >
          <option value="auto">Auto (Negotiate)</option>
          <option value="1.2">TLS 1.2</option>
          <option value="1.3">TLS 1.3</option>
        </select>
      </label>
    </div>
    
    <div class="form-actions">
      <button type="button" @click="handleCancel">Cancel</button>
      <button type="button" @click="handleSave" :disabled="!form.name || (!form.host && !form.authMethod?.startsWith('api_key')) || !form.port">
        {{ isEditing ? 'Update Account' : 'Create Account' }}
      </button>
    </div>
  </div>
</template>

<style scoped>
/* Light/Dark Theme Support */
.mail-account-form { display: flex; flex-direction: column; gap: 1rem; }

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
