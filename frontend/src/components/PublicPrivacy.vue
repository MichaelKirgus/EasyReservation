<script setup>
import { ref, computed, onMounted, watch } from 'vue'
import { useRouter } from 'vue-router'
import { useBackgroundImage } from '../composables/useBackgroundImage'
import { marked } from 'marked'
import DOMPurify from 'dompurify'
import IconButton from './IconButton.vue'

const props = defineProps({ langCode: { type: String, default: 'de' } })

const apiBase = import.meta.env.VITE_API_BASE || '/api'
const siteToken = ref(localStorage.getItem('site_token') || '')
const publicApiKey = ref(localStorage.getItem('public_api_key') || '')
const lang = ref(props.langCode || (navigator.language || 'en').split('-')[0])
const t = ref({})
const privacy = ref('')
const enabled = ref(false)
const loading = ref(false)
const error = ref('')

marked.setOptions({ gfm: true, breaks: true })

function headers() {
  const h = { Accept: 'application/json' }
  if (siteToken.value) h['X-Site-Token'] = siteToken.value
  const adminKey = localStorage.getItem('admin_api_key') || ''
  const apiKey = adminKey || publicApiKey.value
  if (apiKey) h['X-Api-Key'] = apiKey
  return h
}

async function fetchJson(url, opts = {}) {
  const res = await fetch(url, { ...opts })
  const text = await res.text()
  if (!res.ok) {
    const snippet = text ? ` ${text.slice(0, 120)}` : ''
    throw new Error(`HTTP ${res.status} ${res.statusText}${snippet}`)
  }
  const contentType = res.headers.get('content-type') || ''
  if (!contentType.includes('application/json')) {
    const snippet = text ? ` (${contentType}): ${text.slice(0, 120)}` : ` (${contentType})`
    throw new Error(`Unerwartetes Format${snippet}`)
  }
  return text ? JSON.parse(text) : null
}

async function fetchTranslations() {
  try {
    t.value = await fetchJson(`${apiBase}/translations/${lang.value}`, { headers: headers() })
  } catch (_) {
    t.value = {}
  }
}

function tr(key, fallback = '') {
  return t.value[key] || fallback || key
}

function render(text) {
  const html = marked.parse(String(text || ''))
  return DOMPurify.sanitize(html)
}

async function loadPrivacy() {
  loading.value = true
  try {
    const data = await fetchJson(`${apiBase}/privacy-policy`, { headers: headers() })
    privacy.value = data.text || ''
    enabled.value = true
    error.value = ''
  } catch (e) {
    privacy.value = ''
    enabled.value = false
    error.value = `Datenschutzerklärung konnte nicht geladen werden: ${e.message || e}`
  } finally {
    loading.value = false
  }
}


async function checkAndLoadPrivacy() {
  await fetchTranslations();
  loading.value = true;
  try {
    // Erst /public/config laden
    const siteToken = localStorage.getItem('site_token') || '';
    const headersConfig = { 'Accept': 'application/json' };
    if (siteToken) headersConfig['X-Site-Token'] = siteToken;
    const res = await fetch(`${apiBase}/public/config`, { headers: headersConfig });
    if (!res.ok) throw new Error('Config konnte nicht geladen werden');
    const data = await res.json();
    if (!data.privacy_policy_enabled) {
      enabled.value = false;
      privacy.value = '';
      error.value = '';
      loading.value = false;
      return;
    }
    enabled.value = true;
    await loadPrivacy();
  } catch (e) {
    enabled.value = false;
    privacy.value = '';
    error.value = `Datenschutzerklärung konnte nicht geladen werden: ${e.message || e}`;
    loading.value = false;
  }
}

onMounted(async () => {
  await checkAndLoadPrivacy();
})

watch(() => props.langCode, async (val) => {
  if (val && val !== lang.value) {
    lang.value = val
    await fetchTranslations()
  }
})

const router = useRouter()
function goBack() {
  router.push('/')
}
</script>

<template>
  <div class="privacy-page">
    <div class="top-row">
      <h2>{{ tr('privacy_title', 'Datenschutzerklärung') }}</h2>
      <IconButton icon="chevronLeft" label="Zurück" variant="ghost" @click="goBack" />
    </div>
    <p class="hint">{{ tr('privacy_intro', 'Informationen zum Datenschutz.') }}</p>

    <div v-if="loading">Datenschutzerklärung wird geladen...</div>
    <div v-else-if="error" class="error">{{ error }}</div>
    <div v-else-if="!enabled">{{ tr('privacy_disabled', 'No privacy policy available.') }}</div>
    <div v-else class="privacy-content">
      <div v-html="render(privacy)"></div>
    </div>
  </div>
</template>

<style scoped>
.privacy-page { display: flex; flex-direction: column; gap: 0.5rem; }
.top-row { display: flex; align-items: center; justify-content: space-between; gap: 0.5rem; }
h2 { margin: 0; }
.hint { color: #6b7280; }
.error { color: #991b1b; background: #fef2f2; border: 1px solid #fecaca; padding: 0.5rem; border-radius: 6px; }
.privacy-content { display: flex; flex-direction: column; gap: 0.5rem; }
div[v-html] { color: #0f172a; }
button.ghost { background: #eef2ff; color: #1d4ed8; border: 1px solid #c7d2fe; padding: 0.35rem 0.6rem; border-radius: 6px; cursor: pointer; }
</style>
