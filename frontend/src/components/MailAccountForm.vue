<script setup>
import { reactive, watch } from 'vue'
import SecretField from './SecretField.vue'
import IconButton from './IconButton.vue'
import { useTranslation } from '../composables/useTranslation'

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
      retryCount: 3,
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
const { tr } = useTranslation()

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
        <span>{{ tr('admin_mail_transports_account_name') }}</span>
        <input
          v-model="form.name"
          type="text"
          placeholder="My Mail Account"
        />
      </label>
      
      <!-- Host (only for SMTP methods) -->
      <label class="field" v-if="!form.authMethod || !form.authMethod.startsWith('api_key')">
        <span>{{ tr('admin_mail_transports_account_host') }}</span>
        <input
          v-model="form.host"
          type="text"
          placeholder="smtp.example.com"
        />
      </label>
      
      <!-- Port (only for SMTP methods) -->
      <label class="field" v-if="!form.authMethod || !form.authMethod.startsWith('api_key')">
        <span>{{ tr('admin_mail_transports_account_port') }}</span>
        <input
          v-model.number="form.port"
          type="number"
          min="1"
          max="65535"
        />
      </label>
      
      <!-- Encryption (only for SMTP methods) -->
      <label class="field" v-if="!form.authMethod || !form.authMethod.startsWith('api_key')">
        <span>{{ tr('admin_mail_transports_account_encryption') }}</span>
        <select
          v-model="form.encryption"
        >
          <option value="tls">{{ tr('encryption_tls') }}</option>
          <option value="ssl">{{ tr('encryption_ssl') }}</option>
          <option value="none">{{ tr('encryption_none') }}</option>
        </select>
      </label>
      
      <!-- Auth Method -->
      <label class="field">
        <span>{{ tr('admin_mail_transports_account_auth_method') }}</span>
        <select
          v-model="form.authMethod"
        >
          <option value="plain">{{ tr('auth_method_plain') }}</option>
          <option value="login">{{ tr('auth_method_login') }}</option>
          <option value="crammd5">{{ tr('auth_method_crammd5') }}</option>
          <option value="oauth2_exchange">{{ tr('auth_method_oauth2_exchange') }}</option>
          <option value="oauth2_google">{{ tr('auth_method_oauth2_google') }}</option>
          <option value="api_key_sendgrid">{{ tr('auth_method_api_key_sendgrid') }}</option>
          <option value="api_key_mailgun">{{ tr('auth_method_api_key_mailgun') }}</option>
          <option value="api_key_postmark">{{ tr('auth_method_api_key_postmark') }}</option>
        </select>
      </label>
      
      <!-- Username / API Key -->
      <label class="field">
        <span v-if="form.authMethod && form.authMethod.startsWith('api_key')">{{ getApiKeyLabel() }}</span>
        <span v-else>{{ tr('admin_mail_transports_account_username') }}</span>
        <input
          v-model="form.username"
          type="text"
          :placeholder="form.authMethod && form.authMethod.startsWith('api_key') ? 'API Key' : ''"
        />
      </label>
      
      <!-- Password / Secret (only for non-API methods) -->
      <label class="field" v-if="form.authMethod && !form.authMethod.startsWith('api_key')">
        <span>{{ tr('admin_mail_transports_account_password') }}</span>
        <SecretField
          v-model="form.password"
          :placeholder="isEditing ? '••••••••' : ''"
        />
      </label>
      
      <!-- API Key Secret (only for API key methods) -->
      <label class="field" v-if="form.authMethod && form.authMethod.startsWith('api_key')">
        <span>{{ tr('admin_mail_transports_account_password') }}</span>
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
        <span>{{ tr('admin_mail_transports_account_ignore_self_signed') }}</span>
      </label>
      
      <!-- Timeout (only for SMTP methods) -->
      <label class="field" v-if="!form.authMethod || !form.authMethod.startsWith('api_key')">
        <span>{{ tr('admin_mail_transports_account_timeout') }}</span>
        <input
          v-model.number="form.timeout"
          type="number"
          min="1"
          max="300"
        />
      </label>

      <!-- Retry Count -->
      <label class="field">
        <span>{{ tr('admin_mail_transports_columns_retry_count') }}</span>
        <input
          v-model.number="form.retryCount"
          type="number"
          min="1"
          max="10"
        />
      </label>
      
      <!-- Rate Limit Toggle -->
      <label class="field checkbox-field">
        <input
          type="checkbox"
          v-model="form.rateLimitEnabled"
        />
        <span>{{ tr('admin_mail_transports_account_rate_limit_enabled') }}</span>
      </label>
      
      <!-- Rate Limit Per Minute (only shown when enabled) -->
      <label class="field" v-if="form.rateLimitEnabled">
        <span>{{ tr('admin_mail_transports_account_rate_limit_minute') }}</span>
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
        <span>{{ tr('admin_mail_transports_account_rate_limit_hour') }}</span>
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
        <span>{{ tr('admin_mail_transports_account_active') }}</span>
      </label>
      
      <!-- Send As Address (From) -->
      <label class="field">
        <span>{{ tr('admin_mail_transports_account_from_address') }}</span>
        <input
          v-model="form.fromAddress"
          type="email"
          placeholder="sender@example.com"
        />
      </label>
      
      <!-- Reply-To Address -->
      <label class="field">
        <span>{{ tr('admin_mail_transports_account_reply_to_address') }}</span>
        <input
          v-model="form.replyToAddress"
          type="email"
          placeholder="replyto@example.com"
        />
      </label>
      
      <!-- Return-Path (Bounce) Address -->
      <label class="field">
        <span>{{ tr('admin_mail_transports_account_return_path_address') }}</span>
        <input
          v-model="form.returnPathAddress"
          type="email"
          placeholder="bounces@example.com"
        />
      </label>
      
      <!-- TLS Version -->
      <label class="field">
        <span>{{ tr('admin_mail_transports_account_tls_version') }}</span>
        <select
          v-model="form.tlsVersion"
        >
          <option value="auto">{{ tr('tls_version_auto') }}</option>
          <option value="1.2">TLS 1.2</option>
          <option value="1.3">TLS 1.3</option>
        </select>
      </label>
    </div>
    
    <div class="form-actions">
      <IconButton icon="close" :label="tr('cancel')" variant="secondary" @click="handleCancel" />
      <IconButton icon="save" :label="isEditing ? tr('save') : tr('create')" variant="success" :disabled="!form.name || (!form.host && !form.authMethod?.startsWith('api_key')) || !form.port" @click="handleSave" />
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
