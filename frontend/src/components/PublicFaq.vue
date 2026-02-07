<script setup>
import { ref, computed, onMounted, watch } from 'vue'
import { useRouter } from 'vue-router'
import IconButton from './IconButton.vue'
import { useTranslation } from '../composables/useTranslation'
import { renderMarkdown } from '../utils/markdown'
import { apiBase, fetchJsonWithAuth } from '../utils/publicApi'

const props = defineProps({ langCode: { type: String, default: 'de' } })

// Use the global translation system
const { tr, fetchTranslations } = useTranslation()
const siteToken = ref(localStorage.getItem('site_token') || '')
const publicApiKey = ref(localStorage.getItem('public_api_key') || '')
const lang = ref(props.langCode || (navigator.language || 'en').split('-')[0])
const faqs = ref([])
const loading = ref(false)
const error = ref('')

// Watch for language changes from props
watch(() => props.langCode, async (newLang) => {
  if (newLang) {
    lang.value = newLang
    try {
      await fetchTranslations(newLang);
    } catch (err) {
      console.error('Failed to update translations:', err);
    }
  }
}, { immediate: true })

const render = (answer) => renderMarkdown(answer)

async function loadFaqs() {
  loading.value = true
  try {
    const data = await fetchJsonWithAuth(
      `${apiBase}/faqs`,
      {},
      { siteToken: siteToken.value, publicApiKey: publicApiKey.value },
    )
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
  await fetchTranslations(lang.value)
  await loadFaqs()
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
    <div v-else-if="!hasFaqs" class="hint">{{ tr('faq_empty', 'No FAQ entries found.') }}</div>
    <div v-else class="faq-list">
      <details v-for="faq in faqs" :key="faq.id" class="faq-item" open>
        <summary>{{ faq.question }}</summary>
        <div v-html="render(faq.answer)"></div>
      </details>
    </div>
  </div>
</template>

<style scoped>
.faq-page { display: flex; flex-direction: column; gap: 0.5rem; color: var(--text); }
.top-row { display: flex; align-items: center; justify-content: space-between; gap: 0.5rem; }
h2 { margin: 0; color: var(--text); }
.hint { color: var(--text-muted); }
.error { color: var(--error-text); background: var(--error-bg); border: 1px solid var(--error-border); padding: 0.5rem; border-radius: 6px; }
.faq-list { display: flex; flex-direction: column; gap: 0.5rem; }
.faq-item { border: 1px solid var(--border-strong); border-radius: 8px; padding: 0.65rem 0.75rem; background: var(--app-card-bg, var(--card)); color: var(--text); }
summary { font-weight: 700; cursor: pointer; color: var(--text); }
summary::-webkit-details-marker { display: none; }
details[open] summary { margin-bottom: 0.35rem; }
div[v-html] { color: var(--text); }
button.ghost { background: var(--surface-strong); color: var(--primary); border: 1px solid var(--border-strong); padding: 0.35rem 0.6rem; border-radius: 6px; cursor: pointer; }
</style>
