<script setup>

import { ref, reactive, computed, onMounted, onUnmounted, watch } from 'vue'
import { useBackgroundImage } from '../composables/useBackgroundImage'
import { useRouter } from 'vue-router'
import { marked } from 'marked'
import DOMPurify from 'dompurify'
import api from '../api'
import { useTranslation } from '../composables/useTranslation'

const props = defineProps({ langCode: { type: String, default: 'de' } })

const apiBase = import.meta.env.VITE_API_BASE || '/api'
const mediaBase = import.meta.env.VITE_MEDIA_BASE || (() => {
  if (apiBase.startsWith('http')) return new URL(apiBase).origin
  return window.location.origin
})()

const siteToken = ref(localStorage.getItem('site_token') || '')
// Aktualisiere siteToken, falls es sich ändert (z.B. nach App-Start)
window.addEventListener('storage', (e) => {
  if (e.key === 'site_token') {
    siteToken.value = e.newValue || ''
  }
})
const publicApiKey = ref(localStorage.getItem('public_api_key') || '')
const lang = ref(props.langCode || (navigator.language || 'en').split('-')[0])
const loading = ref(false)
const message = ref('')
const error = ref('')
const config = reactive({ settings: {}, form_fields: [], attendees: [], waitlist: [], stats: { count: 0, max: 0 } })
const currentUser = ref(null)

// Use the global translation system
const { tr, fetchTranslations } = useTranslation()

const form = reactive({ name: '', email: '', payload: {} })

const reservationEnabled = computed(() => Number(config.settings?.reservation_enabled || 0) === 1)
const undoEnabled = computed(() => Number(config.settings?.reservation_undo_enabled || 0) === 1)
const showAttendees = computed(() => Number(config.settings?.reservation_show_attendees_enabled || 0) === 1)
const showLimit = computed(() => Number(config.settings?.reservation_show_reservation_limit_enabled || 0) === 1)
const waitlistEnabled = computed(() => Number(config.settings?.waitlist_enabled || 0) === 1)
const showNextEvent = computed(() => Number(config.settings?.reservation_show_next_event || 0) === 1)
const nextEventText = computed(() => config.settings?.reservation_next_event || '')
const upcomingEvents = computed(() => Array.isArray(config.settings?.reservation_upcoming_events_array) ? config.settings.reservation_upcoming_events_array : [])
const upcomingEventsList = computed(() => config.settings?.reservation_upcoming_events_list || '')
const slotsFull = computed(() => {
  const max = Number(config.stats?.max || 0)
  const count = Number(config.stats?.count || 0)
  return max > 0 && count >= max
})
const submitLabel = computed(() => {
  if (slotsFull.value && waitlistEnabled.value) {
    return config.settings.waitlist_join_button_text || tr('waitlist_join_button_text', 'Join waitlist')
  }
  return tr('button_submit_reservation', 'Send reservation')
})
const waitlistFullText = computed(() => {
  if (slotsFull.value && waitlistEnabled.value) {
    return config.settings.waitlist_full_text || tr('waitlist_full_text', 'Currently full. Join the waitlist.')
  }
  if (slotsFull.value && !waitlistEnabled.value) {
    return config.settings.waitlist_disabled_text || tr('waitlist_disabled_text', 'Full. Waitlist is disabled.')
  }
  return ''
})

const publicFields = computed(() => {
  const mapped = (config.form_fields || []).filter(f => f && f.visible_public).map(f => ({ ...f }))
  mapped.sort((a, b) => (a.order ?? 0) - (b.order ?? 0))
  return mapped
})

marked.setOptions({ gfm: true, breaks: true })

function renderMarkdown(raw) {
  if (!raw) return ''
  const html = marked.parse(String(raw))
  return DOMPurify.sanitize(html)
}

const renderedAdditionalInfo = computed(() => renderMarkdown(config.settings?.reservation_additional_info || ''))
const renderedDetails = computed(() => renderMarkdown(config.settings?.reservation_details || ''))
const detailsSummaryLabel = computed(() => config.settings?.reservation_details_summary_label || tr('summary_text', 'Details'))
const headerAlign = computed(() => config.settings?.reservation_header_align || 'left')
const topImageStyle = computed(() => {
  const maxWidth = config.settings?.reservation_top_image_max_width || '100%'
  const maxHeight = config.settings?.reservation_top_image_max_height || '240px'
  return { maxWidth, maxHeight, objectFit: 'contain', width: '100%', height: 'auto' }
})
const renderFieldLabel = (field) => renderMarkdown(field.label || field.key)
const renderFieldHelp = (field) => renderMarkdown(field.help_text || '')
function setMessage(msg) { message.value = msg; error.value = '' }
function setError(msg) { error.value = msg; message.value = '' }




async function fetchJson(url, opts = {}) {
  try {
    const response = await api({ url, ...opts })
    return response.data
  } catch (error) {
    if (error.response) {
      const msg = error.response.data?.message || error.response.statusText || error.message
      throw new Error(msg)
    }
    throw error
  }
}

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
    try {
      await fetchTranslations(newLang);
    } catch (err) {
      console.error('Failed to update translations:', err);
    }
  }
}, { immediate: true })


async function loadConfig() {
  loading.value = true
  try {
    const data = await fetchJson(`/public/config`)
    Object.assign(config.settings, data.settings || {})
    config.form_fields = data.form_fields || []
    config.attendees = data.attendees || []
    config.waitlist = data.waitlist_entries || []
    config.stats = data.stats || { count: 0, max: 0 }
    localStorage.setItem('site_token', siteToken.value || '')
    updateFavicon(config.settings?.reservation_page_favicon)
    updateTitle(config.settings?.reservation_page_title || config.settings?.reservation_name || 'Reservierung')
    // applyBackgroundImage entfernt, da jetzt Composable genutzt wird
    console.debug('Configuration loaded.')
  } catch (e) {
    setError(`Loading failed: ${e.message}`)
  } finally {
    loading.value = false
  }
}

function updateFavicon(val) {
  if (typeof document === 'undefined') return
  const link = document.querySelector("link[rel*='icon']") || document.createElement('link')
  link.rel = 'icon'
  link.href = mediaUrl(val || '/favicon.ico')
  if (!link.parentNode) document.head.appendChild(link)
}

function updateTitle(val) {
  if (typeof document === 'undefined') return
  document.title = val || 'Reservierung'
}

const backgroundStyle = computed(() => {
  return { minHeight: '100vh' }
})

// Hintergrundbild-Logik auslagern
useBackgroundImage(config.settings, mediaBase)

const cardStyle = computed(() => {
  const opacity = Math.min(100, Math.max(0, Number(config.settings?.reservation_card_opacity ?? 90)))
  const alpha = opacity / 100
  return {
    backgroundColor: `rgba(255,255,255,${alpha})`
  }
})

const loadingImageUrl = computed(() => {
  const url = config.settings?.reservation_loading_image
  return url ? mediaUrl(url) : ''
})

function mediaUrl(val) {
  if (!val) return ''
  if (val.startsWith('http://') || val.startsWith('https://')) return val
  return `${mediaBase}${val.startsWith('/') ? '' : '/'}${val}`
}

function validateRequiredFields() {
  const missing = []
  ;(publicFields.value || []).forEach(field => {
    if (!field.required) return
    const value = fieldValue(field)
    if (field.type === 'checkbox') {
      if (!value) missing.push(field.label || field.key)
    } else if (value === undefined || value === null || String(value).trim() === '') {
      missing.push(field.label || field.key)
    }
  })
  return missing
}


async function submitReservation() {
  const missingRequired = validateRequiredFields()
  if (missingRequired.length) {
    setError(tr('please_confirm', 'Please confirm: ') + missingRequired.join(', '))
    return
  }

  loading.value = true
  try {
    const payload = { ...form.payload }
    const site_token = localStorage.getItem('site_token') || ''
    let url = ''
    let body = {
      name: form.name,
      email: form.email,
      payload,
      site_token,
    }
    if (slotsFull.value && waitlistEnabled.value) {
      url = `/waitlist`
    } else {
      url = `/reservations`
    }
    const { data } = await api.post(url, body)

    if (data?.validation_pending) {
      if (data?.pending_admin) {
        setMessage(renderMarkdown(config.settings.reservation_admin_validation_pending_text) ||  tr('reservation_admin_validation_pending_text', 'Confirmation pending admin approval.'))
      } else {
        setMessage(renderMarkdown(config.settings.email_validation_pending_text) || tr('email_validation_pending_text', 'Please confirm your e-mail.'))
      }
    } else if (data?.waitlist) {
      setMessage(renderMarkdown(config.settings.waitlist_success_text) || tr('waitlist_success_text', 'You have been added to the waitlist.'))
    } else {
      setMessage(renderMarkdown(config.settings.reservation_success_text) || tr('reservation_success_text', 'Reservation created successfully.'))
    }
    form.name = ''
    form.email = ''
    form.payload = {}
    await loadConfig()
  } catch (e) {
    setError(tr('reservation_failed_prefix', 'Reservation failed: ') + (e.message || e))
  } finally {
    loading.value = false
  }
}

async function undoReservation() {
  loading.value = true
  try {
    const res = await fetch(`${apiBase}/reservations/undo`, {
      method: 'POST',
      headers: headers(true),
      body: JSON.stringify({
        name: form.name,
        email: form.email,
      }),
    })
    const text = await res.text()
    if (!res.ok) throw new Error(text)
    setMessage(tr('feedback_reservation_undo_success', 'Reservation removed.'))
    form.name = ''
    form.email = ''

    form.payload = {}
    await loadConfig()
  } catch (e) {
    setError(tr('reservation_undo_failed_prefix', 'Undo failed: ') + (e.message || e))
  } finally {
    loading.value = false
  }
}

function fieldValue(field) {
  if (field.key === 'name') return form.name
  if (field.key === 'email') return form.email
  return form.payload[field.key]
}

function setFieldValue(field, value) {
  if (field.key === 'name') {
    form.name = value
    return
  }
  if (field.key === 'email') {
    form.email = value
    return
  }
  form.payload[field.key] = value
}

function fieldInputComponent(field) {
  switch (field.type) {
    case 'select':
      return 'select'
    case 'textarea':
      return 'textarea'
    default:
      return 'input'
  }
}

const hideIdentityFields = computed(() => currentUser.value?.role === 'user')
const modalMessageEnabled = computed(() => Number(config.settings?.reservation_message_modal_enabled || 0) === 1)
const modalErrorEnabled = computed(() => Number(config.settings?.reservation_error_modal_enabled || 0) === 1)
const attendeesAlign = computed(() => config.settings?.reservation_attendees_align || 'left')
const waitlistPublicEnabled = computed(() => Number(config.settings?.waitlist_show_public || 0) === 1)
const waitlistAlign = computed(() => config.settings?.waitlist_public_align || 'left')
const waitlistEntries = computed(() => Array.isArray(config.waitlist) ? config.waitlist : [])


async function verifyTokenIfPresent() {
  const url = new URL(window.location.href)
  const token = url.searchParams.get('v')
  if (!token) return
  try {
    const { data } = await api.get(`/email-validations/${encodeURIComponent(token)}`)
    if (data?.pending_admin) {
      setMessage(renderMarkdown(config.settings.reservation_admin_validation_pending_text) ||  tr('reservation_admin_validation_pending_text', 'Confirmation pending admin approval.'))
    } else if (data?.waitlist) {
      setMessage(renderMarkdown(config.settings.waitlist_success_text) || tr('waitlist_success_text', 'You have been added to the waitlist.'))
    } else {
      setMessage(renderMarkdown(config.settings.reservation_success_text) || tr('reservation_success_text', 'Reservation created successfully.'))
    }
    await loadConfig()
  } catch (e) {
    setError(tr('email_validation_failed', 'Validierung fehlgeschlagen: ') + (e.message || e))
  } finally {
    removeQueryParams(['v'])
  }
}


async function handleUndoTokenIfPresent() {
  const url = new URL(window.location.href)
  const token = url.searchParams.get('u')
  if (!token) return
  try {
    await api.get(`/reservations/undo-token/${encodeURIComponent(token)}`)
    setMessage(renderMarkdown(config.settings.reservation_undo_success_text) || tr('reservation_undo_success_text', 'Reservation removed.'))
    await loadConfig()
  } catch (e) {
    setError(tr('reservation_undo_failed_prefix', 'Undo failed: ') + (e.message || e))
  } finally {
    removeQueryParams(['u'])
  }
}


async function handleWaitlistUndoTokenIfPresent() {
  const url = new URL(window.location.href)
  const token = url.searchParams.get('wu')
  if (!token) return
  try {
    await api.get(`/waitlist/undo-token/${encodeURIComponent(token)}`)
    setMessage(renderMarkdown(config.settings.waitlist_undo_success_text) || tr('waitlist_undo_success_text', 'Reservation removed.'))
    await loadConfig()
  } catch (e) {
    setError(tr('waitlist_undo_failed_prefix', 'Waitlist cancellation failed: ') + (e.message || e))
  } finally {
    removeQueryParams(['wu'])
  }
}

function removeQueryParams(keys) {
  if (typeof window === 'undefined') return
  const url = new URL(window.location.href)
  keys.forEach(k => url.searchParams.delete(k))
  const newQuery = url.searchParams.toString()
  const newUrl = url.pathname + (newQuery ? `?${newQuery}` : '') + url.hash
  window.history.replaceState({}, '', newUrl)
}

onMounted(async () => {
  syncCurrentUser()
  window.addEventListener('api-key-updated', onApiKeyUpdated)
  await fetchTranslations(lang.value)
  await loadConfig()
  applyCustomCss(config.settings.reservation_custom_css)
  await verifyTokenIfPresent()
  await handleUndoTokenIfPresent()
  await handleWaitlistUndoTokenIfPresent()
})

onUnmounted(() => {
  window.removeEventListener('api-key-updated', onApiKeyUpdated)
  window.dispatchEvent(new CustomEvent('loading-end'))
  if (customStyleEl.value) {
    customStyleEl.value.remove()
    customStyleEl.value = null
  }
})

function onApiKeyUpdated(e) {
  // Admin login updates admin key and user; keep public key untouched
  if (e?.detail) {
    // nothing else required here
  }
  syncCurrentUser()
}

function syncCurrentUser() {
  const storedUser = localStorage.getItem('admin_user')
  if (storedUser) {
    try { currentUser.value = JSON.parse(storedUser) } catch (_) { currentUser.value = null }
  }
  if (currentUser.value?.role === 'user') {
    form.name = currentUser.value.name || ''
    form.email = currentUser.value.email || ''
  }
}

watch(() => props.langCode, async (newVal) => {
  if (newVal && newVal !== lang.value) {
    lang.value = newVal
    await fetchTranslations()
  }
})

const customStyleEl = ref(null)

function applyCustomCss(css) {
  if (typeof document === 'undefined') return
  if (css && css.trim()) {
    if (!customStyleEl.value) {
      customStyleEl.value = document.createElement('style')
      customStyleEl.value.setAttribute('data-reservation-custom-css', '1')
      document.head.appendChild(customStyleEl.value)
    }
    customStyleEl.value.textContent = css
  } else if (customStyleEl.value) {
    customStyleEl.value.remove()
    customStyleEl.value = null
  }
}

const router = useRouter()
function goToFaq() {
  router.push('/faq')
}
function goToGDPR() {
  router.push('/privacy')
}
</script>

<template>
  <div class="page" :style="backgroundStyle">
    <div class="backdrop">
      <div v-if="loading" class="loading-overlay" aria-live="polite" aria-busy="true">
        <img v-if="loadingImageUrl.value" :src="loadingImageUrl.value" alt="Loading" class="loader-image" />
        <div v-else class="loader-spinner" aria-hidden="true"></div>
      </div>
      <div v-if="message" class="message" v-html="message"></div>
      <div v-if="error" class="error">{{ error }}</div>

      <div v-if="(modalMessageEnabled && message) || (modalErrorEnabled && error)" class="modal-backdrop" @click.self="() => { message = ''; error = '' }">
        <div class="modal">
          <p class="modal-text" v-html="message || error"></p>
          <button @click="() => { message = ''; error = '' }">{{ tr('modal_close', 'OK') }}</button>
        </div>
      </div>
    <div>{{ loadingImageUrl.value }}</div>
      <section class="card" :style="cardStyle">
        <div class="button-row">
            <div v-if="Number(config.settings.show_faq_button_landing_enabled) === 1">
               <button type="button" class="ghost" @click="goToFaq" :style="{ color: config.settings.faq_button_color || 'white', backgroundColor: config.settings.faq_button_backgroundcolor || '#2563eb', borderColor: config.settings.faq_button_border_color || '#2563eb' }">{{ tr('faq_button_text_label', 'FAQ') }}</button>
            </div>
            <div v-if="Number(config.settings.show_gdpr_button_landing_enabled) === 1">
               <button type="button" class="ghost" @click="goToGDPR" :style="{ color: config.settings.gdpr_button_color || 'white', backgroundColor: config.settings.gdpr_button_backgroundcolor || '#2563eb', borderColor: config.settings.gdpr_button_border_color || '#2563eb' }">{{ tr('gdpr_button_text_label', 'Privacy') }}</button>
            </div>
        </div>
        <div v-if="config.settings.reservation_top_image" class="top-image">
          <img
            :src="mediaUrl(config.settings.reservation_top_image)"
            :alt="config.settings.reservation_top_image_alt_description || 'Top image'"
            :style="topImageStyle"
          />
        </div>
        <div class="title-row" :class="['align-' + (headerAlign || 'left')]"><h2 :style="{ textAlign: headerAlign }">{{ config.settings.reservation_name || tr('title_reservation_form', 'Reservierung') }}</h2></div>
        <p v-if="renderedAdditionalInfo" v-html="renderedAdditionalInfo" :style="{ textAlign: headerAlign }"></p>
        <p v-if="showNextEvent && nextEventText" class="next-event" :style="{ textAlign: headerAlign }">
          <strong>{{ tr('next_event_label', 'Next event') }}:</strong> {{ nextEventText }}
        </p>
        <div v-if="showNextEvent && upcomingEvents.length" class="next-event-list" :style="{ textAlign: headerAlign }">
          <strong>{{ tr('upcoming_events_label', 'Upcoming dates') }}:</strong>
          <ul>
            <li v-for="(evt, idx) in upcomingEvents" :key="idx">{{ evt }}</li>
          </ul>
        </div>
         <p v-if="Number(config.settings.reservation_show_additional_info_link || 0) === 1 && config.settings.reservation_additional_info_link" :style="{ textAlign: headerAlign }">
          <a :href="config.settings.reservation_additional_info_link" target="_blank" rel="noopener noreferrer" :style="{ display: 'inline-block' }">
            {{ config.settings.reservation_additional_info_link_text || config.settings.reservation_additional_info_link }}
          </a>
         </p>
        <details v-if="renderedDetails" class="details">
          <summary :style="{ textAlign: headerAlign }">{{ detailsSummaryLabel }}</summary>
          <div v-html="renderedDetails" :style="{ textAlign: headerAlign }"></div>
          <p v-if="Number(config.settings.reservation_show_details_info_link || 0) === 1 && config.settings.reservation_details_info_link" :style="{ textAlign: headerAlign }">
            <a :href="config.settings.reservation_details_info_link" target="_blank" rel="noopener noreferrer">
              {{ config.settings.reservation_details_info_link_text || config.settings.reservation_details_info_link }}
            </a>
          </p>
        </details>
        <form class="form" @submit.prevent="submitReservation">
          <div v-for="field in publicFields" :key="field.id" class="field">
            <label :style="{ textAlign: field.text_align || 'left' }">
              <span v-html="renderFieldLabel(field)"></span>
            </label>
            <template v-if="field.type === 'checkbox'">
              <input type="checkbox" :checked="!!fieldValue(field)" :required="field.required" @change="setFieldValue(field, $event.target.checked)" :style="{ textAlign: field.text_align || 'left' }" />
            </template>
            <template v-else>
              <component
                :is="fieldInputComponent(field)"
                :value="fieldValue(field)"
                @input="setFieldValue(field, $event.target?.value ?? $event)"
                :required="field.required"
                :placeholder="field.placeholder"
                :type="field.type === 'email' ? 'email' : 'text'"
                :disabled="hideIdentityFields && (field.key === 'name' || field.key === 'email')"
                :style="{ textAlign: field.text_align || 'left' }"
              >
                <option v-for="opt in field.options || []" :key="opt" :value="opt">{{ opt }}</option>
              </component>
            </template>
            <small v-if="field.help_text" v-html="renderFieldHelp(field)" :style="{ textAlign: field.text_align || 'left' }"></small>
          </div>

          <p v-if="waitlistFullText" class="hint">{{ waitlistFullText }}</p>

          <button
            type="submit"
            :disabled="loading || !reservationEnabled"
            :style="{ color: config.settings.reservation_button_color || 'white', backgroundColor: config.settings.reservation_button_backgroundcolor || '#2563eb', borderColor: config.settings.reservation_button_border_color || '#2563eb' }"
          >
            {{ submitLabel }}
          </button>
          <button
            v-if="undoEnabled && config.attendees.length > 0"
            type="button"
            class="ghost"
            @click="undoReservation"
            :disabled="loading"
            :style="{ color: config.settings.reservation_undo_button_color || 'white', backgroundColor: config.settings.reservation_undo_button_backgroundcolor || '#2563eb', borderColor: config.settings.reservation_undo_button_border_color || '#2563eb' }"
          >
            {{ tr('button_remove_reservation', 'Remove reservation') }}
          </button>
          <p v-if="!reservationEnabled" class="hint">{{ tr('feedback_reservation_disabled', 'Reservations are disabled.') }}</p>
        </form>
      </section>

      <section class="card" :style="cardStyle" v-if="showAttendees">
        <h3 :style="{ textAlign: attendeesAlign }">{{ tr('title_attendees_form', 'Teilnehmer') }}</h3>
        <ul v-if="config.attendees.length" class="plain-list" :style="{ textAlign: attendeesAlign }">
          <li v-for="a in config.attendees" :key="a.display_name">{{ a.display_name }}</li>
        </ul>
        <p v-else :style="{ textAlign: attendeesAlign }">{{ tr('no_reservation_found', 'No reservations found.') }}</p>
      </section>

      <section class="card" :style="cardStyle" v-if="waitlistPublicEnabled && waitlistEntries.length">
        <h3 :style="{ textAlign: waitlistAlign }">{{ tr('waitlist_public_title', 'Warteliste') }}</h3>
        <ul class="plain-list" :style="{ textAlign: waitlistAlign }">
          <li v-for="w in waitlistEntries" :key="w.display_name + String(w.date_added || '')">{{ w.display_name }}</li>
        </ul>
      </section>

      <section class="card" :style="cardStyle" v-if="showLimit">
        <p :style="{ textAlign: attendeesAlign }">
          {{ config.stats.count }} {{ tr('reservation_counter_part1', 'of') }} {{ config.stats.max }} {{ tr('reservation_counter_part2', 'places booked') }}
        </p>
      </section>

      <footer v-if="Number(config.settings.show_project_footer) === 1" class="project-footer">
        <span>EasyReservation – Open Source Projekt auf <a href="https://github.com/MichaelKirgus/EasyReservation" target="_blank" rel="noopener">GitHub</a></span>
      </footer>
    </div>
  </div>
</template>

<style scoped>
.page { min-height: 100vh; width: 100%; box-sizing: border-box; }
.backdrop { display: flex; flex-direction: column; gap: 1rem; padding: 0.5rem 1rem 1rem; max-width: 1080px; margin: 0 auto; }
.stack { display: flex; flex-direction: column; gap: 1rem; }
.card { border: 1px solid #e5e7eb; border-radius: 10px; padding: 1rem; box-shadow: 0 4px 14px rgba(15,23,42,0.05); }
.form { display: flex; flex-direction: column; gap: 0.75rem; }
label { display: flex; flex-direction: column; gap: 0.25rem; font-weight: 600; color: #0f172a; }
input, select, textarea, button { font: inherit; padding: 0.6rem; border: 1px solid #d1d5db; border-radius: 6px; width: 100%; box-sizing: border-box; }
button { background: #2563eb; color: #fff; cursor: pointer; width: auto; }
button:disabled { opacity: 0.6; cursor: not-allowed; }
.message { color: #065f46; background: #ecfdf3; border: 1px solid #a7f3d0; padding: 0.5rem; border-radius: 6px; }
.error { color: #991b1b; background: #fef2f2; border: 1px solid #fecaca; padding: 0.5rem; border-radius: 6px; }
.hint { color: #6b7280; }
.field { border-top: 1px solid #e5e7eb; padding-top: 0.5rem; }
.details { margin: 0.5rem 0 1rem; }
.details summary { cursor: pointer; font-weight: 600; }
.details div { padding-top: 0.5rem; text-align: left; }
.top-image { text-align: center; margin-bottom: 0.75rem; }
.top-image img { max-width: 100%; max-height: 240px; object-fit: contain; }
.modal-backdrop { position: fixed; inset: 0; background: rgba(15,23,42,0.5); display: flex; align-items: center; justify-content: center; z-index: 50; padding: 1rem; }
.modal {
  background: #fff;
  border-radius: 10px;
  padding: 1rem;
  max-width: 420px;
  width: 100%;
  box-shadow: 0 20px 50px rgba(15,23,42,0.2);
  display: flex;
  flex-direction: column;
  gap: 0.75rem;
  max-height: 80vh;
  overflow: auto;
}
.modal-text { margin: 0; font-size: 1rem; }
.top-row { display: flex; align-items: center; justify-content: space-between; gap: 0.5rem; }
button.ghost { background: #eef2ff; color: #1d4ed8; border-color: #c7d2fe; }
.button-row { display: flex; justify-content: flex-end; gap: 5px; }
.title-row { display: flex; }

.project-footer {
  width: 100%;
  margin-top: 2rem;
  padding: 1rem 0;
  text-align: center;
  color: #6b7280;
  font-size: 0.95rem;
  background: none;
}
.title-row.align-left { justify-content: flex-start; }
.title-row.align-center { justify-content: center; text-align: center; }
.title-row.align-right { justify-content: flex-end; text-align: right; }
.next-event { margin: 0.25rem 0; font-weight: 600; color: #0f172a; }
.next-event-list { margin: 0.25rem 0 0.75rem; }
.next-event-list ul { margin: 0.25rem 0 0; padding-left: 1.25rem; }
.plain-list { list-style: none; padding-left: 0; margin: 0; }
.loading-overlay { position: fixed; inset: 0; background: rgba(255,255,255,0.75); display: flex; align-items: center; justify-content: center; z-index: 60; }
.loader-image { width: 64px; height: 64px; animation: spin 1s linear infinite; object-fit: contain; }
.loader-spinner { width: 48px; height: 48px; border: 4px solid #e5e7eb; border-top-color: #2563eb; border-radius: 50%; animation: spin 1s linear infinite; }
@keyframes spin { from { transform: rotate(0deg); } to { transform: rotate(360deg); } }
</style>
