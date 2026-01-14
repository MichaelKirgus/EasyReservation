<script setup>
import { ref, reactive, onMounted, onUnmounted, watch, computed } from 'vue'
import IconButton from './IconButton.vue'

const props = defineProps({ langCode: { type: String, default: 'de' } })

const apiBase = import.meta.env.VITE_API_BASE || '/api'
const mediaBase = import.meta.env.VITE_MEDIA_BASE || (() => {
  if (apiBase.startsWith('http')) return new URL(apiBase).origin
  return window.location.origin
})()
const apiKey = ref(localStorage.getItem('admin_api_key') || '')
const settings = reactive({})

const loading = ref(false)
const message = ref('')
const error = ref('')

const translations = ref({})
const templates = ref([])
const placeholders = ref([])

const imageFields = {
  reservation_page_background_image: 'background',
  reservation_top_image: 'top',
  reservation_page_favicon: 'favicon',
  reservation_loading_image: 'loading',
}

const imageOptions = reactive({ background: [], top: [], favicon: [] })
const picker = reactive({ open: false, field: '', category: '', loading: false })

const fields = [
  { key: 'reservation_name', type: 'text' },
  { key: 'reservation_additional_info', type: 'text', component: 'textarea', hintKey: 'admin_setting_markdown_hint', placeholders: true },
  { key: 'reservation_show_additional_info_link', type: 'boolean' },
  { key: 'reservation_additional_info_link_text', type: 'text', placeholders: true },
  { key: 'reservation_additional_info_link', type: 'text' },
  { key: 'reservation_details', type: 'text', component: 'textarea', hintKey: 'admin_setting_markdown_hint', placeholders: true },
  { key: 'reservation_details_summary_label', type: 'text' },
  { key: 'reservation_show_details_info_link', type: 'boolean' },
  { key: 'reservation_details_info_link_text', type: 'text', placeholders: true },
  { key: 'reservation_details_info_link', type: 'text' },
  { key: 'reservation_name_maxchar', type: 'number' },
  { key: 'reservation_name_minchar', type: 'number' },
  { key: 'reservation_max', type: 'number' },
  { key: 'waitlist_enabled', type: 'boolean' },
  { key: 'waitlist_auto_promote_enabled', type: 'boolean' },
  { key: 'waitlist_limit', type: 'number' },
  { key: 'waitlist_undo_enabled', type: 'boolean' },
  { key: 'waitlist_show_public', type: 'boolean', default: 0 },
  { key: 'waitlist_public_align', type: 'text', default: 'left' },
  { key: 'waitlist_overflow_enabled', type: 'boolean', default: 1 },
  { key: 'reservation_attendees_align', type: 'text', default: 'left' },
  { key: 'waitlist_full_text', type: 'text', placeholders: true },
  { key: 'waitlist_join_button_text', type: 'text', placeholders: true },
  { key: 'waitlist_success_text', type: 'text', component: 'textarea', hintKey: 'admin_setting_markdown_hint', placeholders: true },
  { key: 'waitlist_undo_success_text', type: 'text', component: 'textarea', hintKey: 'admin_setting_markdown_hint', placeholders: true },
  { key: 'waitlist_disabled_text', type: 'text', placeholders: true },
  { key: 'reservation_enabled', type: 'boolean' },
  { key: 'reservation_undo_enabled', type: 'boolean' },
  { key: 'reservation_show_attendees_enabled', type: 'boolean' },
  { key: 'reservation_show_reservation_limit_enabled', type: 'boolean' },
  { key: 'reservation_name_blacklist_enable', type: 'boolean' },
  { key: 'reservation_name_blacklist', type: 'text' },
  { key: 'reservation_name_blacklist_unicode_enable', type: 'boolean' },
  { key: 'reservation_name_blacklist_unicode_base64', type: 'text' },
  { key: 'reservation_name_whitelist_regex_enable', type: 'boolean' },
  { key: 'reservation_name_whitelist_regex', type: 'text' },
  { key: 'reservation_email_whitelist_enable', type: 'boolean' },
  { key: 'reservation_email_whitelist', type: 'text' },
  { key: 'reservation_email_whitelist_regex_enable', type: 'boolean' },
  { key: 'reservation_email_whitelist_regex', type: 'text' },
  { key: 'reservation_top_image', type: 'text' },
  { key: 'reservation_top_image_alt_description', type: 'text' },
  { key: 'reservation_top_image_max_width', type: 'text' },
  { key: 'reservation_top_image_max_height', type: 'text' },
  { key: 'reservation_page_background_image', type: 'text' },
  { key: 'reservation_background_opacity', type: 'number', default: 60 },
  { key: 'reservation_card_opacity', type: 'number', default: 90 },
  { key: 'reservation_page_favicon', type: 'text' },
  { key: 'reservation_page_title', type: 'text', placeholders: true },
  { key: 'reservation_loading_image', type: 'text' },
  { key: 'reservation_button_color', type: 'color', default: '#2563eb' },
  { key: 'reservation_custom_css', type: 'text', component: 'textarea', hintKey: 'admin_setting_custom_css_hint' },
  { key: 'reservation_header_align', type: 'text', default: 'left' },
  { key: 'reservation_message_modal_enabled', type: 'boolean', default: 0 },
  { key: 'reservation_error_modal_enabled', type: 'boolean', default: 0 },
  { key: 'email_validation_enabled', type: 'boolean', default: 0 },
  { key: 'email_validation_admin_enabled', type: 'boolean', default: 0 },
  { key: 'email_validation_template_id', type: 'number' },
  { key: 'email_validation_ttl_minutes', type: 'number', default: 1440 },
  { key: 'email_validation_base_url', type: 'text' },
  { key: 'email_reservation_success_template_id', type: 'number' },
  { key: 'email_reservation_cancel_template_id', type: 'number' },
  { key: 'email_waitlist_promoted_template_id', type: 'number' },
  { key: 'email_waitlist_validation_success_template_id', type: 'number' },
  { key: 'email_waitlist_cancel_template_id', type: 'number' },
  { key: 'admin_reservation_notify_default', type: 'boolean', default: 0 },
  { key: 'moderator_reservation_notify_default', type: 'boolean', default: 0 },
  { key: 'reservation_show_next_event', type: 'boolean', default: 1 },
  { key: 'event_date_format', type: 'text', default: 'Y-m-d' },
  { key: 'event_time_format', type: 'text', default: 'H:i' },
  { key: 'mail_host', type: 'text' },
  { key: 'mail_port', type: 'number' },
  { key: 'mail_username', type: 'text' },
  { key: 'mail_password', type: 'text' },
  { key: 'mail_encryption', type: 'text' },
  { key: 'mail_from_address', type: 'text' },
  { key: 'mail_from_name', type: 'text' },
  { key: 'privacy_policy_enabled', type: 'boolean' },
  { key: 'privacy_policy_text', type: 'text', component: 'textarea', hintKey: 'admin_setting_markdown_hint', placeholders: true },
  { key: 'email_validation_pending_text', type: 'text', component: 'textarea', hintKey: 'admin_setting_markdown_hint', placeholders: true },
  { key: 'reservation_success_text', type: 'text', component: 'textarea', hintKey: 'admin_setting_markdown_hint', placeholders: true },
  { key: 'reservation_undo_success_text', type: 'text', component: 'textarea', hintKey: 'admin_setting_markdown_hint', placeholders: true },
  { key: 'reservation_admin_validation_pending_text', type: 'text', component: 'textarea', hintKey: 'admin_setting_markdown_hint', placeholders: true },
]

const placeholderHint = computed(() => placeholders.value.length ? `Platzhalter: ${placeholders.value.join(', ')}` : '')
const markdownHintText = computed(() => hint('admin_setting_markdown_hint') || 'Unterstützt Markdown')

const tabs = [
  { id: 'general', labelKey: 'admin_settings_tab_general', fallback: 'Allgemein' },
  { id: 'text', labelKey: 'admin_settings_tab_text', fallback: 'Texte' },
  { id: 'design', labelKey: 'admin_settings_tab_design', fallback: 'Design / CSS' },
  { id: 'email', labelKey: 'admin_settings_tab_email', fallback: 'E-Mail' },
]

const tabFieldMap = {
  general: new Set([
    'privacy_policy_enabled',
    'reservation_enabled',
    'reservation_undo_enabled',
    'reservation_show_attendees_enabled',
    'reservation_show_reservation_limit_enabled',
    'reservation_name_maxchar',
    'reservation_name_minchar',
    'reservation_max',
    'waitlist_enabled',
    'waitlist_auto_promote_enabled',
    'waitlist_limit',
    'waitlist_undo_enabled',
    'waitlist_overflow_enabled',
    'reservation_name_blacklist_enable',
    'reservation_name_blacklist',
    'reservation_name_blacklist_unicode_enable',
    'reservation_name_blacklist_unicode_base64',
    'reservation_name_whitelist_regex_enable',
    'reservation_name_whitelist_regex',
    'reservation_email_whitelist_enable',
    'reservation_email_whitelist',
    'reservation_email_whitelist_regex_enable',
    'reservation_email_whitelist_regex',
    'reservation_message_modal_enabled',
    'reservation_error_modal_enabled',
    'reservation_show_next_event',
    'event_date_format',
    'event_time_format',
  ]),
  text: new Set([
    'reservation_name',
    'reservation_additional_info',
    'reservation_show_additional_info_link',
    'reservation_additional_info_link_text',
    'reservation_additional_info_link',
    'reservation_details',
    'reservation_details_summary_label',
    'reservation_show_details_info_link',
    'reservation_details_info_link_text',
    'reservation_details_info_link',
    'waitlist_full_text',
    'waitlist_join_button_text',
    'waitlist_success_text',
    'waitlist_undo_success_text',
    'email_validation_pending_text',
    'reservation_admin_validation_pending_text',
    'reservation_success_text',
    'reservation_undo_success_text',
    'waitlist_disabled_text',
    'reservation_page_title',
    'privacy_policy_text',
  ]),
  design: new Set([
    'reservation_top_image',
    'reservation_top_image_alt_description',
    'reservation_top_image_max_width',
    'reservation_top_image_max_height',
    'reservation_page_background_image',
    'reservation_background_opacity',
    'reservation_card_opacity',
    'reservation_page_favicon',
    'reservation_loading_image',
    'reservation_button_color',
    'reservation_custom_css',
    'reservation_header_align',
    'waitlist_public_align',
    'reservation_attendees_align',
  ]),
  email: new Set([
    'email_validation_enabled',
    'email_validation_admin_enabled',
    'email_validation_template_id',
    'email_validation_ttl_minutes',
    'email_validation_base_url',
    'email_reservation_success_template_id',
    'email_reservation_cancel_template_id',
    'email_waitlist_promoted_template_id',
    'email_waitlist_validation_success_template_id',
    'email_waitlist_cancel_template_id',
    'admin_reservation_notify_default',
    'moderator_reservation_notify_default',
    'mail_host',
    'mail_port',
    'mail_username',
    'mail_password',
    'mail_encryption',
    'mail_from_address',
    'mail_from_name',
  ]),
}

const templateFieldKeys = new Set([
  'email_validation_template_id',
  'email_reservation_success_template_id',
  'email_reservation_cancel_template_id',
  'email_waitlist_promoted_template_id',
  'email_waitlist_validation_success_template_id',
  'email_waitlist_cancel_template_id',
])

const selectedTab = ref('general')

const visibleFields = computed(() => {
  const allowed = tabFieldMap[selectedTab.value] || new Set()
  return fields.filter(f => allowed.has(f.key))
})

function tabLabel(tab) {
  return translations.value[tab.labelKey] || tab.fallback
}

function ensureDefaults(obj) {
  fields.forEach(f => {
    if (obj[f.key] === undefined || obj[f.key] === null) {
      obj[f.key] = f.type === 'boolean' ? false : (f.default !== undefined ? f.default : '')
    }
  })
}

function setMessage(msg) { message.value = msg; error.value = '' }
function setError(msg) { error.value = msg; message.value = '' }

function authHeaders() {
  return { 'Content-Type': 'application/json', 'Accept': 'application/json', 'X-Api-Key': apiKey.value }
}

async function fetchTranslations() {
  try {
    const res = await fetch(`${apiBase}/translations/${props.langCode}`, { headers: { Accept: 'application/json' } })
    translations.value = await res.json()
  } catch (_) {
    translations.value = {}
  }
}

function t(key) {
  return translations.value[`admin_setting_${key}`] || translations.value[`admin_${key}`] || key
}

function hint(key) {
  return translations.value[key] || key
}

function isMarkdown(field) {
  return field.hintKey === 'admin_setting_markdown_hint'
}

async function loadImages(category) {
  picker.loading = true
  try {
    const res = await fetch(`${apiBase}/admin/media/images?category=${encodeURIComponent(category)}`, { headers: authHeaders() })
    if (!res.ok) throw new Error(await res.text())
    imageOptions[category] = await res.json()
  } catch (e) {
    setError(`Bilder konnten nicht geladen werden: ${e}`)
  } finally {
    picker.loading = false
  }
}

function openPicker(fieldKey) {
  const category = imageFields[fieldKey]
  if (!category) return
  picker.field = fieldKey
  picker.category = category
  picker.open = true
  if (!imageOptions[category] || imageOptions[category].length === 0) {
    loadImages(category)
  }
}

function mediaUrl(val) {
  if (!val) return ''
  if (val.startsWith('http://') || val.startsWith('https://')) return val
  return `${mediaBase}${val.startsWith('/') ? '' : '/'}${val}`
}

function chooseImage(item) {
  if (!picker.field) return
  settings[picker.field] = item?.path || ''
  picker.open = false
}

async function loadTemplates() {
  try {
    const res = await fetch(`${apiBase}/admin/email-templates`, { headers: authHeaders() })
    if (!res.ok) throw new Error(await res.text())
    templates.value = await res.json()
  } catch (e) {
    setError(`Vorlagen konnten nicht geladen werden: ${e}`)
  }
}

async function loadPlaceholders() {
  const res = await fetch(`${apiBase}/admin/placeholders`, { headers: authHeaders() })
  if (!res.ok) throw new Error(await res.text())
  placeholders.value = await res.json()
}

async function load() {
  if (!apiKey.value) { setError('Bitte anmelden, API-Key fehlt.'); return }
  loading.value = true
  try {
    const [resSettings, resTemplates] = await Promise.all([
      fetch(`${apiBase}/admin/settings`, { headers: authHeaders() }),
      fetch(`${apiBase}/admin/email-templates`, { headers: authHeaders() }),
    ])
    if (!resSettings.ok) throw new Error(await resSettings.text())
    if (!resTemplates.ok) throw new Error(await resTemplates.text())
    await loadPlaceholders()
    const data = await resSettings.json()
    templates.value = await resTemplates.json()
    Object.keys(settings).forEach(k => delete settings[k])
    Object.assign(settings, data)
    ensureDefaults(settings)
    fields.forEach(f => {
      if (f.type === 'boolean') settings[f.key] = Number(settings[f.key]) === 1
      if (f.type === 'number' && settings[f.key] !== '' && settings[f.key] !== null) settings[f.key] = Number(settings[f.key])
      if (f.type === 'text' && settings[f.key] === null) settings[f.key] = ''
    })
    templateFieldKeys.forEach(key => {
      if (settings[key] === '') settings[key] = null
    })
    localStorage.setItem('admin_api_key', apiKey.value)
    console.debug('Einstellungen geladen.')
  } catch (e) { setError(`Fehler beim Laden: ${e}`) } finally { loading.value = false }
}

async function save() {
  if (!apiKey.value) { setError('Bitte anmelden, API-Key fehlt.'); return }
  loading.value = true
  try {
    const payload = {}
    fields.forEach(f => {
      if (f.type === 'boolean') payload[f.key] = settings[f.key] ? 1 : 0
      else if (f.type === 'number') payload[f.key] = settings[f.key] === null || settings[f.key] === '' ? null : Number(settings[f.key])
      else payload[f.key] = settings[f.key]
    })
    const res = await fetch(`${apiBase}/admin/settings`, {
      method: 'POST',
      headers: authHeaders(),
      body: JSON.stringify({ settings: payload }),
    })
    const text = await res.text()
    if (!res.ok) throw new Error(text)
    window.dispatchEvent(new CustomEvent('settings-updated'))
    console.debug('Einstellungen gespeichert.')
  } catch (e) { setError(`Speichern fehlgeschlagen: ${e}`) } finally { loading.value = false }
}

function handleKeyUpdate(e) { apiKey.value = e.detail || '' }

onMounted(() => {
  window.addEventListener('api-key-updated', handleKeyUpdate)
  fetchTranslations()
  if (apiKey.value) load()
  else ensureDefaults(settings)
})

onUnmounted(() => {
  window.removeEventListener('api-key-updated', handleKeyUpdate)
})

watch(() => props.langCode, () => fetchTranslations())
</script>

<template>
  <div class="stack">
    <div class="controls">
      <IconButton icon="refresh" label="Laden" @click="load" :disabled="loading" />
      <IconButton icon="save" label="Speichern" @click="save" :disabled="loading" />
    </div>
    <div v-if="error" class="error">{{ error }}</div>
    <div v-if="message" class="message">{{ message }}</div>

    <div class="tabs">
      <button v-for="tab in tabs" :key="tab.id" :class="['tab', { active: selectedTab === tab.id }]" @click="selectedTab = tab.id">
        {{ tabLabel(tab) }}
      </button>
    </div>

    <div class="groups">
      <div class="grid">
        <label v-for="field in visibleFields" :key="field.key" class="field">
          <div class="field-header">
            <span>{{ t(field.key) }}</span>
            <span v-if="isMarkdown(field)" class="markdown-indicator" :title="markdownHintText" aria-hidden="true">MD</span>
            <span v-if="field.placeholders" class="placeholder-indicator" :title="placeholderHint || 'Unterstützt Platzhalter'" aria-hidden="true">⧉</span>
          </div>
          <template v-if="field.type === 'boolean'">
            <input type="checkbox" v-model="settings[field.key]" />
          </template>
          <template v-else-if="templateFieldKeys.has(field.key)">
            <select v-model="settings[field.key]">
              <option :value="null">Kein Template</option>
              <option v-for="tpl in templates" :key="tpl.id" :value="tpl.id">{{ tpl.name }} (ID: {{ tpl.id }})</option>
            </select>
          </template>
          <template v-else-if="field.type === 'number'">
            <input v-model.number="settings[field.key]" type="number" />
          </template>
          <template v-else-if="field.type === 'color'">
            <div class="color-input">
              <input v-model="settings[field.key]" type="color" />
              <span class="swatch" :style="{ backgroundColor: settings[field.key] || '#000' }"></span>
              <span class="swatch-text">{{ settings[field.key] }}</span>
            </div>
          </template>
          <template v-else-if="imageFields[field.key]">
            <div class="image-input">
              <div class="preview" v-if="settings[field.key]">
                <img :src="mediaUrl(settings[field.key])" :alt="field.key" />
                <span class="filename">{{ settings[field.key].split('/').pop() }}</span>
              </div>
              <div class="image-actions">
                <IconButton type="button" icon="image" label="Bild wählen" @click="openPicker(field.key)" />
                <IconButton type="button" variant="ghost" icon="trash" label="Entfernen" @click="settings[field.key] = ''" />
              </div>
            </div>
          </template>
          <template v-else-if="field.component === 'textarea'">
            <textarea v-model="settings[field.key]" rows="4"></textarea>
          </template>
          <template v-else>
            <input v-model="settings[field.key]" />
          </template>
          <small v-if="field.hintKey && !isMarkdown(field)" class="hint">{{ hint(field.hintKey) }}</small>
        </label>
      </div>
    </div>

    <details v-if="placeholders.length" class="group info placeholder-info" aria-live="polite">
      <summary>Platzhalter anzeigen</summary>
      <div class="placeholder-list">
        <code v-for="token in placeholders" :key="token">{{ token }}</code>
      </div>
    </details>

    <div v-if="selectedTab === 'email'" class="group">
      <h3>{{ t('email_templates_title') }}</h3>
      <p class="hint">E-Mail-Vorlagen findest du jetzt im Tab "Moderation > E-Mail > Vorlagenverwaltung".</p>
    </div>

    <div v-if="picker.open" class="modal-backdrop" @click.self="picker.open = false">
      <div class="modal">
        <h3>Bild auswählen</h3>
        <div v-if="picker.loading">Lade Bilder...</div>
        <div v-else class="thumb-grid">
          <button v-for="item in imageOptions[picker.category]" :key="item.path" class="thumb" @click="chooseImage(item)">
            <img :src="mediaUrl(item.path || item.url)" :alt="item.filename" />
            <span>{{ item.filename }}</span>
          </button>
          <button class="thumb" @click="chooseImage({ path: '' })">
            <div class="no-image">Kein Bild</div>
            <span>Kein Bild</span>
          </button>
        </div>
        <div class="modal-actions">
          <IconButton class="ghost" variant="ghost" @click="picker.open = false" icon="close" label="Abbrechen" />
        </div>
      </div>
    </div>
  </div>
</template>

<style scoped>
.stack { display: flex; flex-direction: column; gap: 0.75rem; width: 100%; }
.controls { display: flex; gap: 0.75rem; flex-wrap: wrap; align-items: end; }
.tabs { display: flex; gap: 0.5rem; flex-wrap: wrap; }
.tab { padding: 0.5rem 0.75rem; border: 1px solid #d1d5db; background: #f8fafc; border-radius: 8px; cursor: pointer; color: #0f172a; }
.tab.active { background: #2563eb; color: #fff; border-color: #1d4ed8; }
.groups { display: flex; flex-direction: column; gap: 1rem; }
.group { border: 1px solid #e5e7eb; border-radius: 10px; padding: 1rem; background: #fff; box-shadow: 0 4px 14px rgba(15,23,42,0.05); display: flex; flex-direction: column; gap: 0.5rem; }
.group h3 { margin: 0 0 0.25rem; font-size: 1.05rem; color: #0f172a; }
label.field { display: flex; flex-direction: column; gap: 0.25rem; font-weight: 600; }
.field-header { display: flex; align-items: center; gap: 0.35rem; }
.placeholder-indicator { color: #2563eb; font-size: 0.9rem; cursor: help; }
.markdown-indicator { color: #0ea5e9; font-size: 0.85rem; font-weight: 700; cursor: help; padding: 0.05rem 0.3rem; border: 1px solid #bae6fd; border-radius: 4px; background: #e0f2fe; }
input, textarea, button, select { font: inherit; padding: 0.5rem; border: 1px solid #d1d5db; border-radius: 6px; width: 100%; box-sizing: border-box; }
button { background: #2563eb; color: #fff; cursor: pointer; width: auto; }
button.ghost { background: #eef2ff; color: #1d4ed8; border-color: #c7d2fe; }
button.danger { background: #dc2626; color: #fff; }
button:disabled { opacity: 0.6; cursor: not-allowed; }
.message { color: #065f46; background: #ecfdf3; border: 1px solid #a7f3d0; padding: 0.5rem; border-radius: 6px; }
.error { color: #991b1b; background: #fef2f2; border: 1px solid #fecaca; padding: 0.5rem; border-radius: 6px; }
.grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(320px, 1fr)); gap: 0.75rem; width: 100%; }
.color-input { display: flex; align-items: center; gap: 0.5rem; }
.color-input .swatch { width: 24px; height: 24px; border-radius: 6px; border: 1px solid #d1d5db; }
.color-input .swatch-text { font-size: 0.9rem; color: #4b5563; }
hint { font-size: 0.9rem; color: #6b7280; font-weight: 400; }
.image-input { display: flex; flex-direction: column; gap: 0.35rem; }
.image-actions { display: flex; gap: 0.5rem; flex-wrap: wrap; }
.image-input img { max-width: 100%; max-height: 120px; object-fit: contain; border: 1px solid #e5e7eb; border-radius: 6px; background: #f8fafc; }
.image-input .filename { font-size: 0.9rem; color: #4b5563; }
.modal-backdrop { position: fixed; inset: 0; background: rgba(15,23,42,0.35); display: flex; align-items: center; justify-content: center; padding: 1rem; z-index: 40; }
.modal { background: #fff; border-radius: 12px; padding: 1rem; width: min(720px, 100%); box-shadow: 0 20px 50px rgba(15, 23, 42, 0.2); display: flex; flex-direction: column; gap: 0.75rem; }
.thumb-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(140px, 1fr)); gap: 0.75rem; }
.thumb { border: 1px solid #e5e7eb; background: #fff; border-radius: 8px; padding: 0.5rem; display: flex; flex-direction: column; align-items: center; gap: 0.35rem; cursor: pointer; }
.thumb img { width: 100%; height: 100px; object-fit: contain; background: #f8fafc; border-radius: 6px; }
.thumb:hover { border-color: #2563eb; box-shadow: 0 4px 12px rgba(37, 99, 235, 0.15); }
.no-image { width: 100%; height: 100px; display: flex; align-items: center; justify-content: center; background: #f8fafc; border-radius: 6px; color: #64748b; }
.modal-actions { display: flex; justify-content: flex-end; gap: 0.5rem; }
.placeholder-info { cursor: pointer; }
.placeholder-list { display: flex; flex-wrap: wrap; gap: 0.35rem; margin-top: 0.35rem; }
.placeholder-list code { background: #f3f4f6; padding: 0.2rem 0.35rem; border-radius: 4px; }
</style>
