<script setup>
import { ref, reactive, onMounted, onUnmounted, computed } from 'vue'
import IconButton from './IconButton.vue'
import { adminFetch } from '../utils/adminApi'
import { useTranslation } from '../composables/useTranslation'

const apiKey = ref(localStorage.getItem('admin_auth_session') || sessionStorage.getItem('admin_auth_session') || '')
const { tr } = useTranslation()
const routePrefix = ref(localStorage.getItem('admin_route_prefix') || 'admin')
const faqs = ref([])
const loading = ref(false)
const messageKey = ref('')
const error = ref('')
const newFaq = reactive({ question: '', answer: '', is_published: true, position: null })

// Recompute the translated message whenever translations load to avoid stale keys on first render
const message = computed(() => messageKey.value ? tr(messageKey.value) : '')

function setMessage(key) { messageKey.value = key; error.value = '' }
function setError(msg) { error.value = msg; messageKey.value = '' }

function sortFaqs(list) {
  return [...list].sort((a, b) => (a.position ?? 0) - (b.position ?? 0) || a.id - b.id)
}

async function loadFaqs() {
  if (!apiKey.value) { setError(tr('please_login_api_key_missing')); return }
  loading.value = true
  try {
    const res = await adminFetch('faqs', {}, { apiKeyRef: apiKey, routePrefixRef: routePrefix })
    const text = await res.text()
    if (!res.ok) throw new Error(text)
    const data = text ? JSON.parse(text) : []
    faqs.value = sortFaqs(data)
    setMessage('faq_loaded')
  } catch (e) {
    setError(tr('error_loading') + ': ' + e)
  } finally {
    loading.value = false
  }
}

async function createFaq() {
  if (window.__faqCreateInProgress) return;
  window.__faqCreateInProgress = true;
  if (!apiKey.value) { setError(tr('please_login_api_key_missing')); window.__faqCreateInProgress = false; return }
  if (!newFaq.question.trim() || !newFaq.answer.trim()) {
    setError(tr('please_fill_in_question_and_answer')); window.__faqCreateInProgress = false; return
  }
  loading.value = true
  try {
    const res = await adminFetch('faqs', { method: 'POST', body: JSON.stringify(newFaq) }, { apiKeyRef: apiKey, routePrefixRef: routePrefix })
    const text = await res.text()
    if (!res.ok) throw new Error(text)
    const created = text ? JSON.parse(text) : null
    if (created) {
      faqs.value = sortFaqs([created, ...faqs.value])
    }
    setMessage('faq_created')
    newFaq.question = ''
    newFaq.answer = ''
    newFaq.position = null
    newFaq.is_published = true
  } catch (e) {
    setError(tr('creation_failed') + ': ' + e)
  } finally {
    loading.value = false;
    window.__faqCreateInProgress = false;
  }
}

async function updateFaq(faq) {
  if (!apiKey.value) { setError(tr('please_login_api_key_missing')); return }
  loading.value = true
  try {
    const res = await adminFetch(`faqs/${faq.id}`, {
      method: 'PATCH',
      body: JSON.stringify({
        question: faq.question,
        answer: faq.answer,
        is_published: faq.is_published,
        position: faq.position,
      }),
    }, { apiKeyRef: apiKey, routePrefixRef: routePrefix })
    const text = await res.text()
    if (!res.ok) throw new Error(text)
    const updated = text ? JSON.parse(text) : faq
    faqs.value = sortFaqs(faqs.value.map(f => f.id === updated.id ? updated : f))
    setMessage('faq_saved')
  } catch (e) {
    setError(tr('saving_failed') + ': ' + e)
  } finally {
    loading.value = false
  }
}

async function deleteFaq(id) {
  if (window.__faqDeleteInProgress) return;
  window.__faqDeleteInProgress = true;
  if (!apiKey.value) { setError(tr('please_login_api_key_missing')); window.__faqDeleteInProgress = false; return }
  if (!confirm(tr('entry_deleted_confirm'))) { window.__faqDeleteInProgress = false; return }
  loading.value = true
  try {
    const res = await adminFetch(`faqs/${id}`, { method: 'DELETE' }, { apiKeyRef: apiKey, routePrefixRef: routePrefix })
    const text = await res.text()
    if (!res.ok) throw new Error(text)
    faqs.value = faqs.value.filter(f => f.id !== id)
    setMessage('faq_deleted')
  } catch (e) {
    setError(tr('deletion_failed') + ': ' + e)
  } finally {
    loading.value = false;
    window.__faqDeleteInProgress = false;
  }
}

function handleKeyUpdate(e) {
  apiKey.value = e.detail || ''
}

onMounted(() => {
  window.addEventListener('api-key-updated', handleKeyUpdate)
  if (apiKey.value) {
    loadFaqs()
  }
})

onUnmounted(() => {
  window.removeEventListener('api-key-updated', handleKeyUpdate)
})
</script>

<template>
  <div class="stack">
    <div class="top-bar">
      <div class="left-actions">
        <IconButton icon="refresh" :label="tr('faq_refresh', 'Refresh')" @click="loadFaqs" :disabled="loading" />
      </div>
    </div>

    <div v-if="message" class="message">{{ message }}</div>
    <div v-if="error" class="error">{{ error }}</div>

    <div class="card">
      <div class="card-header">
        <h3>{{ tr('faq_new_title', 'New FAQ') }}</h3>
      </div>
      <div class="grid">
        <label>
          {{ tr('faq_question_label', 'Question') }}
          <input v-model="newFaq.question" :placeholder="tr('faq_question_label', 'Question')" />
        </label>
        <label>
          {{ tr('faq_position_label', 'Position') }} ({{ tr('faq_position_auto', 'Automatic') }})
          <input v-model.number="newFaq.position" type="number" min="0" :placeholder="tr('faq_position_auto', 'Automatic')" />
        </label>
        <label class="inline">
          <input type="checkbox" v-model="newFaq.is_published" /> {{ tr('faq_published_label', 'Published') }}
        </label>
      </div>
      <label>
        {{ tr('faq_answer_label', 'Answer') }}
        <textarea v-model="newFaq.answer" rows="3" :placeholder="tr('faq_answer_label', 'Answer')"></textarea>
      </label>
      <div class="actions">
        <IconButton icon="plus" :label="tr('faq_create_button', 'Create')" variant="success" @click="createFaq" :disabled="loading" />
      </div>
    </div>

    <div class="card">
      <div class="card-header">
        <h3>{{ tr('faq_list_title', 'FAQ List') }}</h3>
      </div>
      <p v-if="!faqs.length">{{ tr('faq_no_entries', 'No entries available.') }}</p>
      <div v-else class="faq-list">
        <div v-for="faq in faqs" :key="faq.id" class="faq-item">
          <div class="grid">
            <label>
              {{ tr('faq_question_label', 'Question') }}
              <input v-model="faq.question" @change="updateFaq(faq)" />
            </label>
            <label>
              {{ tr('faq_position_label', 'Position') }}
              <input v-model.number="faq.position" type="number" min="0" @change="updateFaq(faq)" />
            </label>
            <label class="inline">
              <input type="checkbox" v-model="faq.is_published" @change="updateFaq(faq)" /> {{ tr('faq_published_label', 'Published') }}
            </label>
          </div>
          <label>
            {{ tr('faq_answer_label', 'Answer') }}
            <textarea v-model="faq.answer" rows="3" @change="updateFaq(faq)"></textarea>
          </label>
          <div class="actions">
            <IconButton variant="danger" icon="trash" :label="tr('faq_delete_button', 'Delete')" @click="deleteFaq(faq.id)" :disabled="loading" />
          </div>
        </div>
      </div>
    </div>
  </div>
</template>

<style scoped>
.stack { display: flex; flex-direction: column; gap: 0.75rem; }
.top-bar { display: flex; justify-content: space-between; align-items: center; }
.left-actions { display: flex; gap: 0.5rem; }
.card { border: 1px solid var(--border-strong); border-radius: 8px; padding: 0.75rem; background: var(--app-card-bg, var(--card)); color: var(--text); display: flex; flex-direction: column; gap: 0.75rem; }
.card-header { display: flex; align-items: center; justify-content: space-between; gap: 0.5rem; }
.grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(220px, 1fr)); gap: 0.5rem; align-items: center; }
label { display: flex; flex-direction: column; gap: 0.25rem; font-weight: 600; color: var(--text); }
label.inline { flex-direction: row; align-items: center; font-weight: 500; }
input, textarea { font: inherit; padding: 0.5rem; border: 1px solid var(--border); border-radius: 6px; background: var(--surface); color: var(--text); }
button { font: inherit; cursor: pointer; }
button.danger { background: #dc2626; color: #fff; }
button:disabled { opacity: 0.6; cursor: not-allowed; }
.message { color: var(--success-text); background: var(--success-bg); border: 1px solid var(--success-border); padding: 0.5rem; border-radius: 6px; }
.error { color: var(--error-text); background: var(--error-bg); border: 1px solid var(--error-border); padding: 0.5rem; border-radius: 6px; }
.faq-list { display: flex; flex-direction: column; gap: 0.75rem; }
.faq-item { border: 1px solid var(--border-strong); border-radius: 8px; padding: 0.65rem; background: var(--surface-muted); color: var(--text); display: flex; flex-direction: column; gap: 0.5rem; }
.actions { display: flex; gap: 0.5rem; justify-content: flex-end; }
</style>
