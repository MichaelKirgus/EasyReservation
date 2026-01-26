<script setup>
import { ref, computed, onMounted, watch } from 'vue'
import { useRouter } from 'vue-router'
import { useBackgroundImage } from '../composables/useBackgroundImage'
import IconButton from './IconButton.vue'
import { marked } from 'marked'
import DOMPurify from 'dompurify'

const props = defineProps({ langCode: { type: String, default: 'de' } })

const apiBase = import.meta.env.VITE_API_BASE || '/api'
const siteToken = ref(localStorage.getItem('site_token') || '')
const publicApiKey = ref(localStorage.getItem('public_api_key') || '')
const lang = ref(props.langCode || (navigator.language || 'en').split('-')[0])
const t = ref({})
const faqs = ref([])
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

function render(answer) {
  const html = marked.parse(String(answer || ''))
  return DOMPurify.sanitize(html)
}

async function loadFaqs() {
  loading.value = true
  try {
    const data = await fetchJson(`${apiBase}/faqs`, { headers: headers() })
    faqs.value = Array.isArray(data) ? data : []
    error.value = ''
  } catch (e) {
    error.value = `FAQ konnten nicht geladen werden: ${e.message || e}`
  } finally {
    loading.value = false
  }
}

function syncTokensFromUrl() {
  const url = new URL(window.location.href)
  let tParam = url.searchParams.get('t')
  if (!tParam) {
    const path = url.pathname || ''
    const hash = url.hash || ''
    const fromPath = path.startsWith('/t=') ? path.slice(3) : null
    const fromHash = hash.startsWith('#t=') ? hash.slice(3) : null
    tParam = fromPath || fromHash || ''
  }
  if (tParam) {
    siteToken.value = tParam
    publicApiKey.value = tParam
    localStorage.setItem('site_token', tParam)
    localStorage.setItem('public_api_key', tParam)
  }
}

onMounted(async () => {
  syncTokensFromUrl()
  await fetchTranslations()
  await loadFaqs()
})

watch(() => props.langCode, async (val) => {
  if (val && val !== lang.value) {
    lang.value = val
    await fetchTranslations()
  }
})

const hasFaqs = computed(() => (faqs.value?.length || 0) > 0)
const router = useRouter()
function goBack() {
  router.push('/')
}
</script>

<template>
  <div class="faq-page">
    <div class="top-row">
      <h2>{{ tr('faq_title', 'FAQ') }}</h2>
      <IconButton icon="chevronLeft" label="Zurück" variant="ghost" @click="goBack" />
    </div>
    <p class="hint">{{ tr('faq_intro', 'Häufige Fragen zur Reservierung.') }}</p>

    <div v-if="loading">FAQ werden geladen...</div>
    <div v-else-if="error" class="error">{{ error }}</div>
    <div v-else-if="!hasFaqs" class="hint">{{ tr('faq_empty', 'Keine FAQ-Einträge vorhanden.') }}</div>
    <div v-else class="faq-list">
      <details v-for="faq in faqs" :key="faq.id" class="faq-item" open>
        <summary>{{ faq.question }}</summary>
        <div v-html="render(faq.answer)"></div>
      </details>
    </div>
  </div>
</template>

<style scoped>
.faq-page { display: flex; flex-direction: column; gap: 0.5rem; }
.top-row { display: flex; align-items: center; justify-content: space-between; gap: 0.5rem; }
h2 { margin: 0; }
.hint { color: #6b7280; }
.error { color: #991b1b; background: #fef2f2; border: 1px solid #fecaca; padding: 0.5rem; border-radius: 6px; }
.faq-list { display: flex; flex-direction: column; gap: 0.5rem; }
.faq-item { border: 1px solid #e5e7eb; border-radius: 8px; padding: 0.65rem 0.75rem; background: #fff; }
summary { font-weight: 700; cursor: pointer; }
summary::-webkit-details-marker { display: none; }
details[open] summary { margin-bottom: 0.35rem; }
div[v-html] { color: #0f172a; }
button.ghost { background: #eef2ff; color: #1d4ed8; border: 1px solid #c7d2fe; padding: 0.35rem 0.6rem; border-radius: 6px; cursor: pointer; }
</style>
