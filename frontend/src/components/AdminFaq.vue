<script setup>
import { ref, reactive, onMounted, onUnmounted } from 'vue'
import IconButton from './IconButton.vue'

const apiBase = import.meta.env.VITE_API_BASE || '/api'
const apiKey = ref(localStorage.getItem('admin_api_key') || '')
const routePrefix = ref(localStorage.getItem('admin_route_prefix') || 'admin')
const faqs = ref([])
const loading = ref(false)
const message = ref('')
const error = ref('')
const newFaq = reactive({ question: '', answer: '', is_published: true, position: null })

function setMessage(msg) { message.value = msg; error.value = '' }
function setError(msg) { error.value = msg; message.value = '' }

function authHeaders() {
  return { 'Content-Type': 'application/json', Accept: 'application/json', 'X-Api-Key': apiKey.value }
}

function buildUrl(relative) {
  return `${apiBase}/${routePrefix.value}/${relative}`
}

async function apiFetch(relative, opts = {}, triedFallback = false) {
  const res = await fetch(buildUrl(relative), { ...opts, headers: authHeaders() })
  if (res.status === 403 && !triedFallback && routePrefix.value === 'admin') {
    routePrefix.value = 'moderator'
    localStorage.setItem('admin_route_prefix', 'moderator')
    return apiFetch(relative, opts, true)
  }
  return res
}

function sortFaqs(list) {
  return [...list].sort((a, b) => (a.position ?? 0) - (b.position ?? 0) || a.id - b.id)
}

async function loadFaqs() {
  if (!apiKey.value) { setError('Bitte anmelden, API-Key fehlt.'); return }
  loading.value = true
  try {
    const res = await apiFetch('faqs')
    const text = await res.text()
    if (!res.ok) throw new Error(text)
    const data = text ? JSON.parse(text) : []
    faqs.value = sortFaqs(data)
    setMessage('FAQ geladen.')
  } catch (e) {
    setError(`Fehler beim Laden: ${e}`)
  } finally {
    loading.value = false
  }
}

async function createFaq() {
  if (!apiKey.value) { setError('Bitte anmelden, API-Key fehlt.'); return }
  if (!newFaq.question.trim() || !newFaq.answer.trim()) {
    setError('Bitte Frage und Antwort ausfüllen.')
    return
  }
  loading.value = true
  try {
    const res = await apiFetch('faqs', { method: 'POST', body: JSON.stringify(newFaq) })
    const text = await res.text()
    if (!res.ok) throw new Error(text)
    const created = text ? JSON.parse(text) : null
    if (created) {
      faqs.value = sortFaqs([created, ...faqs.value])
    }
    setMessage('FAQ erstellt.')
    newFaq.question = ''
    newFaq.answer = ''
    newFaq.position = null
    newFaq.is_published = true
  } catch (e) {
    setError(`Erstellen fehlgeschlagen: ${e}`)
  } finally {
    loading.value = false
  }
}

async function updateFaq(faq) {
  if (!apiKey.value) { setError('Bitte anmelden, API-Key fehlt.'); return }
  loading.value = true
  try {
    const res = await apiFetch(`faqs/${faq.id}`, {
      method: 'PATCH',
      body: JSON.stringify({
        question: faq.question,
        answer: faq.answer,
        is_published: faq.is_published,
        position: faq.position,
      }),
    })
    const text = await res.text()
    if (!res.ok) throw new Error(text)
    const updated = text ? JSON.parse(text) : faq
    faqs.value = sortFaqs(faqs.value.map(f => f.id === updated.id ? updated : f))
    setMessage('Gespeichert.')
  } catch (e) {
    setError(`Speichern fehlgeschlagen: ${e}`)
  } finally {
    loading.value = false
  }
}

async function deleteFaq(id) {
  if (!apiKey.value) { setError('Bitte anmelden, API-Key fehlt.'); return }
  if (!confirm('Eintrag wirklich löschen?')) return
  loading.value = true
  try {
    const res = await apiFetch(`faqs/${id}`, { method: 'DELETE' })
    const text = await res.text()
    if (!res.ok) throw new Error(text)
    faqs.value = faqs.value.filter(f => f.id !== id)
    setMessage('FAQ gelöscht.')
  } catch (e) {
    setError(`Löschen fehlgeschlagen: ${e}`)
  } finally {
    loading.value = false
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
        <IconButton icon="refresh" label="Aktualisieren" @click="loadFaqs" :disabled="loading" />
      </div>
    </div>

    <div v-if="message" class="message">{{ message }}</div>
    <div v-if="error" class="error">{{ error }}</div>

    <div class="card">
      <div class="card-header">
        <h3>Neue FAQ</h3>
      </div>
      <div class="grid">
        <label>
          Frage
          <input v-model="newFaq.question" placeholder="Frage" />
        </label>
        <label>
          Position (optional)
          <input v-model.number="newFaq.position" type="number" min="0" placeholder="Automatisch" />
        </label>
        <label class="inline">
          <input type="checkbox" v-model="newFaq.is_published" /> Veröffentlicht
        </label>
      </div>
      <label>
        Antwort
        <textarea v-model="newFaq.answer" rows="3" placeholder="Antwort"></textarea>
      </label>
      <div class="actions">
        <IconButton icon="plus" label="Anlegen" variant="success" @click="createFaq" :disabled="loading" />
      </div>
    </div>

    <div class="card">
      <div class="card-header">
        <h3>FAQ-Liste</h3>
      </div>
      <p v-if="!faqs.length">Keine Einträge vorhanden.</p>
      <div v-else class="faq-list">
        <div v-for="faq in faqs" :key="faq.id" class="faq-item">
          <div class="grid">
            <label>
              Frage
              <input v-model="faq.question" @change="updateFaq(faq)" />
            </label>
            <label>
              Position
              <input v-model.number="faq.position" type="number" min="0" @change="updateFaq(faq)" />
            </label>
            <label class="inline">
              <input type="checkbox" v-model="faq.is_published" @change="updateFaq(faq)" /> Veröffentlicht
            </label>
          </div>
          <label>
            Antwort
            <textarea v-model="faq.answer" rows="3" @change="updateFaq(faq)"></textarea>
          </label>
          <div class="actions">
            <IconButton variant="danger" icon="trash" label="Löschen" @click="deleteFaq(faq.id)" :disabled="loading" />
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
.card { border: 1px solid #e5e7eb; border-radius: 8px; padding: 0.75rem; background: #fff; display: flex; flex-direction: column; gap: 0.75rem; }
.card-header { display: flex; align-items: center; justify-content: space-between; gap: 0.5rem; }
.grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(220px, 1fr)); gap: 0.5rem; align-items: center; }
label { display: flex; flex-direction: column; gap: 0.25rem; font-weight: 600; }
label.inline { flex-direction: row; align-items: center; font-weight: 500; }
input, textarea, button { font: inherit; padding: 0.5rem; border: 1px solid #d1d5db; border-radius: 6px; }
textarea { resize: vertical; }
button { background: #2563eb; color: #fff; cursor: pointer; }
button.danger { background: #dc2626; }
button:disabled { opacity: 0.6; cursor: not-allowed; }
.message { color: #065f46; background: #ecfdf3; border: 1px solid #a7f3d0; padding: 0.5rem; border-radius: 6px; }
.error { color: #991b1b; background: #fef2f2; border: 1px solid #fecaca; padding: 0.5rem; border-radius: 6px; }
.faq-list { display: flex; flex-direction: column; gap: 0.75rem; }
.faq-item { border: 1px solid #e5e7eb; border-radius: 8px; padding: 0.65rem; background: #f8fafc; display: flex; flex-direction: column; gap: 0.5rem; }
.actions { display: flex; gap: 0.5rem; justify-content: flex-end; }
</style>
