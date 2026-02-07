<script setup>
import { ref, onMounted, watch } from 'vue'
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
const privacy = ref('')
const enabled = ref(false)
const loading = ref(false)
const error = ref('')

const render = (text) => renderMarkdown(text)

// Initialize translations when component mounts
onMounted(async () => {
  try {
    await fetchTranslations(lang.value);
  } catch (err) {
    console.error('Failed to initialize translations:', err);
  }
})

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

async function loadPrivacy() {
  loading.value = true
  try {
    const data = await fetchJsonWithAuth(
      `${apiBase}/privacy-policy`,
      {},
      { siteToken: siteToken.value, publicApiKey: publicApiKey.value },
    )
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
  await fetchTranslations(lang.value);
  loading.value = true;
  try {
    // Erst /public/config laden
    const siteToken = localStorage.getItem('site_token') || '';
    const data = await fetchJsonWithAuth(
      `${apiBase}/public/config`,
      {},
      { siteToken, publicApiKey: publicApiKey.value },
    );
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
.privacy-page { display: flex; flex-direction: column; gap: 0.5rem; color: var(--text); }
.top-row { display: flex; align-items: center; justify-content: space-between; gap: 0.5rem; }
h2 { margin: 0; color: var(--text); }
.hint { color: var(--text-muted); }
.error { color: var(--error-text); background: var(--error-bg); border: 1px solid var(--error-border); padding: 0.5rem; border-radius: 6px; }
.privacy-content { display: flex; flex-direction: column; gap: 0.5rem; }
div[v-html] { color: var(--text); }
button.ghost { background: var(--surface-strong); color: var(--primary); border: 1px solid var(--border-strong); padding: 0.35rem 0.6rem; border-radius: 6px; cursor: pointer; }
</style>
