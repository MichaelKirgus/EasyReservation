<script setup>

import { ref, reactive, computed, onMounted, onUnmounted, watch, nextTick } from 'vue'
import { useRouter } from 'vue-router'
import api from '../api'
import { useTranslation } from '../composables/useTranslation'
import { renderMarkdown } from '../utils/markdown'
import { apiBase, fetchJsonWithAuth } from '../utils/publicApi'
import { applySiteBranding } from '../utils/siteBranding'

const props = defineProps({ langCode: { type: String, default: 'de' } })

const mediaBase = import.meta.env.VITE_MEDIA_BASE || (() => {
  if (apiBase.startsWith('http')) return new URL(apiBase).origin
  return window.location.origin
})()

const siteToken = ref(localStorage.getItem('site_token') || '')
const siteTokenChecked = ref(false)
const siteTokenInvalid = ref(false)
const showSiteTokenError = computed(() => {
  // Only show error after nextTick to ensure all reactivity is settled
  let shouldShow = false;
  nextTick(() => {
    shouldShow = siteTokenChecked.value && (!siteToken.value || siteTokenInvalid.value) && !loading.value;
  });
  return siteTokenChecked.value && (!siteToken.value || siteTokenInvalid.value) && !loading.value;
});
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
const theme = ref(document.documentElement?.dataset?.theme || 'light')
let themeObserver = null

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
const waitlistFullHtml = computed(() => waitlistFullText.value ? renderMarkdown(waitlistFullText.value) : '')
const waitlistFullAlign = computed(() => config.settings?.waitlist_full_text_align || 'left')
const reservationLimitAlign = computed(() => config.settings?.reservation_limit_text_align || 'left')
const reservationLimitHtml = computed(() => renderMarkdown(config.settings.reservation_limit_text) || tr('feedback_reservation_limit', 'Reservation limit reached.'))

const publicFields = computed(() => {
  const mapped = (config.form_fields || []).filter(f => f && f.visible_public).map(f => ({ ...f }))
  mapped.sort((a, b) => (a.order ?? 0) - (b.order ?? 0))
  return mapped
})

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
const messageRef = ref(null)
const errorRef = ref(null)
function scrollToFeedback(el) {
  nextTick(() => {
    if (el.value) {
      el.value.scrollIntoView({ behavior: 'smooth', block: 'center' })
    }
  })
}
function setMessage(msg) { message.value = msg; error.value = ''; if (msg) scrollToFeedback(messageRef) }
function setError(msg) { error.value = msg; message.value = ''; if (msg) scrollToFeedback(errorRef) }




async function fetchJson(url, opts = {}) {
  try {
    return await fetchJsonWithAuth(url, opts, { siteToken: siteToken.value, publicApiKey: publicApiKey.value })
  } catch (error) {
    // preserve existing message handling for axios-style errors
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
  // Keep local theme in sync with root data attribute
  theme.value = document.documentElement?.dataset?.theme || theme.value
  themeObserver = new MutationObserver(() => {
    theme.value = document.documentElement?.dataset?.theme || 'light'
  })
  themeObserver.observe(document.documentElement, { attributes: true, attributeFilter: ['data-theme'] })
})

// Watch for language changes from props
watch(() => props.langCode, async (newLang) => {
  if (newLang) {
    lang.value = newLang
    try {
      await fetchTranslations(newLang)
    } catch (err) {
      console.error('Failed to update translations:', err)
    }
  }
}, { immediate: true })

onUnmounted(() => {
  if (themeObserver) themeObserver.disconnect()
})

const cachedLoadingImage = ref('')
try { cachedLoadingImage.value = localStorage.getItem('reservation_loading_image') || '' } catch (_) { cachedLoadingImage.value = '' }


const loadingSvgColor = computed(() => {
  // Use the new settings keys for light/dark SVG color
  const isDark = (theme.value || 'light') === 'dark'
  if (isDark) return config.settings.loading_svg_color_dark || '#fff'
  return config.settings.loading_svg_color_light || '#222'
})

const loadingImageUrl = computed(() => {
  const url = config.settings?.reservation_loading_image || cachedLoadingImage.value
  return url ? mediaUrl(url) : ''
})


async function loadConfig() {
  loading.value = true
  siteTokenChecked.value = false
  siteTokenInvalid.value = false
  try {
    const data = await fetchJson(`${apiBase}/public/config`)
    Object.assign(config.settings, data.settings || {})
    config.form_fields = data.form_fields || []
    config.attendees = data.attendees || []
    config.waitlist = data.waitlist_entries || []
    config.stats = data.stats || { count: 0, max: 0 }
    try {
      const loadingImg = config.settings.reservation_loading_image || ''
      cachedLoadingImage.value = loadingImg
      if (loadingImg) {
        localStorage.setItem('reservation_loading_image', loadingImg)
      } else {
        localStorage.removeItem('reservation_loading_image')
      }
    } catch (_) {}
    localStorage.setItem('site_token', siteToken.value || '')
    // Ensure siteToken.value is in sync with localStorage after config loads
    siteToken.value = localStorage.getItem('site_token') || ''
    applySiteBranding(config.settings, { mediaBase, fallbackTitle: 'Reservierung' })
    // applyBackgroundImage entfernt, da jetzt Composable genutzt wird
    console.debug('Configuration loaded.')
  } catch (e) {
    // If the backend returns 401/403, mark the token as invalid
    if (e.message && (e.message.includes('401') || e.message.includes('403'))) {
      siteTokenInvalid.value = true
    }
    console.error('[DEBUG] Error loading config:', e)
    setError(`Loading failed: ${e.message}`)
  } finally {
    loading.value = false
    siteTokenChecked.value = true
  }
}

const backgroundStyle = computed(() => {
  return { minHeight: '100vh' }
})

const cardStyle = computed(() => ({
  backgroundColor: 'var(--app-card-bg, var(--card))',
  color: 'var(--text)'
}))

function themedValue(lightKey, darkKey, fallbackKey, defaultVal) {
  const s = config.settings || {}
  const isDark = (theme.value || 'light') === 'dark'
  if (isDark) return s[darkKey] ?? s[fallbackKey] ?? defaultVal
  return s[lightKey] ?? s[fallbackKey] ?? defaultVal
}

const reservationButtonStyle = computed(() => ({
  '--btn-color': themedValue('reservation_button_color_light', 'reservation_button_color_dark', 'reservation_button_color', 'white'),
  '--btn-bg': themedValue('reservation_button_backgroundcolor_light', 'reservation_button_backgroundcolor_dark', 'reservation_button_backgroundcolor', '#2563eb'),
  '--btn-border': themedValue('reservation_button_border_color_light', 'reservation_button_border_color_dark', 'reservation_button_border_color', '#2563eb'),
}))

const reservationUndoButtonStyle = computed(() => ({
  '--btn-ghost-color': themedValue('reservation_undo_button_color_light', 'reservation_undo_button_color_dark', 'reservation_undo_button_color', 'white'),
  '--btn-ghost-bg': themedValue('reservation_undo_button_backgroundcolor_light', 'reservation_undo_button_backgroundcolor_dark', 'reservation_undo_button_backgroundcolor', '#2563eb'),
  '--btn-ghost-border': themedValue('reservation_undo_button_border_color_light', 'reservation_undo_button_border_color_dark', 'reservation_undo_button_border_color', '#2563eb'),
}))

const faqButtonStyle = computed(() => ({
  '--btn-ghost-color': themedValue('faq_button_color_light', 'faq_button_color_dark', 'faq_button_color', 'white'),
  '--btn-ghost-bg': themedValue('faq_button_backgroundcolor_light', 'faq_button_backgroundcolor_dark', 'faq_button_backgroundcolor', '#2563eb'),
  '--btn-ghost-border': themedValue('faq_button_border_color_light', 'faq_button_border_color_dark', 'faq_button_border_color', '#2563eb'),
}))

const gdprButtonStyle = computed(() => ({
  '--btn-ghost-color': themedValue('gdpr_button_color_light', 'gdpr_button_color_dark', 'gdpr_button_color', 'white'),
  '--btn-ghost-bg': themedValue('gdpr_button_backgroundcolor_light', 'gdpr_button_backgroundcolor_dark', 'gdpr_button_backgroundcolor', '#2563eb'),
  '--btn-ghost-border': themedValue('gdpr_button_border_color_light', 'gdpr_button_border_color_dark', 'gdpr_button_border_color', '#2563eb'),
}))

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
    const serverMsg = e?.response?.data?.message
    const msg = String(serverMsg || e?.message || e)
    const rateLimitMsg = renderMarkdown(config.settings.email_validation_rate_limit_text) || tr('email_validation_rate_limited', 'Too many requests. Please try again later.')
    const reservationLimitMsg = renderMarkdown(config.settings.reservation_limit_text) || tr('feedback_reservation_limit', 'Reservation limit reached.')
    const waitlistFullMsg = renderMarkdown(config.settings.waitlist_full_text) || tr('feedback_waitlist_full', 'Waitlist is full.')

    if (e?.response?.status === 429 || msg.includes('email_validation_rate_limit')) {
      setError(rateLimitMsg)
    } else if (msg.includes('reservation_limit_reached') || msg.includes('feedback_reservation_limit')) {
      setError(reservationLimitMsg)
    } else if (msg.includes('feedback_waitlist_full')) {
      setError(waitlistFullMsg)
    } else {
      setError(tr('reservation_failed_prefix', 'Reservation failed: ') + msg)
    }
  } finally {
    loading.value = false
  }
}

async function undoReservation() {
  loading.value = true
  try {
    await fetchJsonWithAuth(
      `${apiBase}/reservations/undo`,
      {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({
          name: form.name,
          email: form.email,
        }),
      },
      { siteToken: siteToken.value, publicApiKey: publicApiKey.value },
    )
    setMessage(renderMarkdown(config.settings.reservation_undo_success_text) || tr('feedback_reservation_undo_success', 'Reservation removed.'))
    form.name = ''
    form.email = ''

    form.payload = {}
    await loadConfig()
  } catch (e) {
    const reservationNotFoundMsg = renderMarkdown(config.settings.reservation_undo_not_found_text) || tr('reservation_undo_not_found_text', 'Reservation not found.')
    const waitlistNotFoundMsg = renderMarkdown(config.settings.waitlist_undo_not_found_text) || tr('waitlist_undo_not_found_text', 'Entry not found.')
    const status = e?.response?.status
    const errMsg = String(e?.response?.data?.message || e?.message || e)

    // If no reservation matched, try waitlist undo as a fallback to keep a single button in the UI
    if (status === 404 || errMsg.includes('reservation_not_found')) {
      try {
        await fetchJsonWithAuth(
          `${apiBase}/waitlist/undo`,
          {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({
              name: form.name,
              email: form.email,
            }),
          },
          { siteToken: siteToken.value, publicApiKey: publicApiKey.value },
        )
        setMessage(renderMarkdown(config.settings.waitlist_undo_success_text) || tr('waitlist_undo_success_text', 'Reservation removed.'))
        form.name = ''
        form.email = ''
        form.payload = {}
        await loadConfig()
        return
      } catch (we) {
        const wStatus = we?.response?.status
        const wMsg = String(we?.response?.data?.message || we?.message || we)
        if (wStatus === 404 || wMsg.includes('waitlist_entry_not_found')) {
          setMessage(waitlistNotFoundMsg)
        } else {
          setError(tr('waitlist_undo_failed_prefix', 'Waitlist cancellation failed: ') + wMsg)
        }
        return
      }
    }

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
    const data = await fetchJsonWithAuth(
      `${apiBase}/email-validations/${encodeURIComponent(token)}`,
      {},
      { siteToken: siteToken.value, publicApiKey: publicApiKey.value },
    )
    if (data?.pending_admin) {
      setMessage(renderMarkdown(config.settings.reservation_admin_validation_pending_text) ||  tr('reservation_admin_validation_pending_text', 'Confirmation pending admin approval.'))
    } else if (data?.waitlist) {
      setMessage(renderMarkdown(config.settings.waitlist_success_text) || tr('waitlist_success_text', 'You have been added to the waitlist.'))
    } else {
      setMessage(renderMarkdown(config.settings.reservation_success_text) || tr('reservation_success_text', 'Reservation created successfully.'))
    }
    await loadConfig()
  } catch (e) {
    const msg = String(e?.message || e)
    const reservationLimitMsg = renderMarkdown(config.settings.reservation_limit_text) || tr('feedback_reservation_limit', 'Reservation limit reached.')
    const waitlistFullMsg = renderMarkdown(config.settings.waitlist_full_text) || tr('feedback_waitlist_full', 'Waitlist is full.')

    if (msg.includes('reservation_limit_reached') || msg.includes('feedback_reservation_limit')) {
      setError(reservationLimitMsg)
    } else if (msg.includes('feedback_waitlist_full')) {
      setError(waitlistFullMsg)
    } else {
      setError(tr('email_validation_failed', 'Validierung fehlgeschlagen: ') + msg)
    }
  } finally {
    removeQueryParams(['v'])
  }
}


async function handleUndoTokenIfPresent() {
  const url = new URL(window.location.href)
  const token = url.searchParams.get('u')
  if (!token) return
  try {
    await fetchJsonWithAuth(
      `${apiBase}/reservations/undo-token/${encodeURIComponent(token)}`,
      {},
      { siteToken: siteToken.value, publicApiKey: publicApiKey.value },
    )
    setMessage(renderMarkdown(config.settings.reservation_undo_success_text) || tr('reservation_undo_success_text', 'Reservation removed.'))
    await loadConfig()
  } catch (e) {
    const customNotFound = renderMarkdown(config.settings.reservation_undo_not_found_text) || tr('reservation_undo_not_found_text', 'Reservation not found.')
    if (String(e?.message || '').includes('reservation_not_found')) {
      setMessage(customNotFound)
    } else {
      setError(tr('reservation_undo_failed_prefix', 'Undo failed: ') + (e.message || e))
    }
  } finally {
    removeQueryParams(['u'])
  }
}


async function handleWaitlistUndoTokenIfPresent() {
  const url = new URL(window.location.href)
  const token = url.searchParams.get('wu')
  if (!token) return
  try {
    await fetchJsonWithAuth(
      `${apiBase}/waitlist/undo-token/${encodeURIComponent(token)}`,
      {},
      { siteToken: siteToken.value, publicApiKey: publicApiKey.value },
    )
    setMessage(renderMarkdown(config.settings.waitlist_undo_success_text) || tr('waitlist_undo_success_text', 'Reservation removed.'))
    await loadConfig()
  } catch (e) {
    const customNotFound = renderMarkdown(config.settings.waitlist_undo_not_found_text) || tr('waitlist_undo_not_found_text', 'Entry not found.')
    if (String(e?.message || '').includes('waitlist_entry_not_found')) {
      setMessage(customNotFound)
    } else {
      setError(tr('waitlist_undo_failed_prefix', 'Waitlist cancellation failed: ') + (e.message || e))
    }
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
        <div v-if="showSiteTokenError" class="site-token-message"
          v-html="renderMarkdown(config.settings.site_token_invalid_message || tr('site_token_invalid_message', 'A valid site token is required to access this page.'))">
        </div>
    <div class="backdrop">
      <div v-if="loading" class="loading-overlay" aria-live="polite" aria-busy="true">
        <img v-if="loadingImageUrl" :src="loadingImageUrl" alt="Loading" class="loader-image" />
        <svg v-else class="loader-image" viewBox="0 0 50 50" :style="{ color: loadingSvgColor }" fill="none" xmlns="http://www.w3.org/2000/svg">
          <circle cx="25" cy="25" r="20" stroke="currentColor" stroke-width="5" opacity="0.2" />
          <path d="M45 25c0-11.046-8.954-20-20-20" stroke="currentColor" stroke-width="5" stroke-linecap="round">
            <animateTransform attributeName="transform" type="rotate" from="0 25 25" to="360 25 25" dur="1s" repeatCount="indefinite" />
          </path>
        </svg>
      </div>
      <div v-if="message && !modalMessageEnabled" ref="messageRef" class="message" v-html="message"></div>
      <div v-if="error && !modalErrorEnabled" ref="errorRef" class="error" v-html="error"></div>

      <Teleport to="body">
        <div v-if="(modalMessageEnabled && message) || (modalErrorEnabled && error)" class="reservation-modal-backdrop" @click.self="() => { message = ''; error = '' }">
          <div class="reservation-modal">
            <p class="reservation-modal-text" v-html="message || error"></p>
            <button @click="() => { message = ''; error = '' }">{{ tr('modal_close', 'OK') }}</button>
          </div>
        </div>
      </Teleport>
      <section class="card" :style="cardStyle">
        <div class="button-row">
            <div v-if="Number(config.settings.show_faq_button_landing_enabled) === 1">
              <button type="button" class="ghost" @click="goToFaq" :style="faqButtonStyle">{{ tr('faq_button_text_label', 'FAQ') }}</button>
            </div>
            <div v-if="Number(config.settings.show_gdpr_button_landing_enabled) === 1">
              <button type="button" class="ghost" @click="goToGDPR" :style="gdprButtonStyle">{{ tr('gdpr_button_text_label', 'Privacy') }}</button>
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

          <p v-if="waitlistFullHtml" class="hint" v-html="waitlistFullHtml" :style="{ textAlign: waitlistFullAlign }"></p>
          <p
            v-if="slotsFull && !waitlistEnabled && reservationLimitHtml"
            class="hint"
            v-html="reservationLimitHtml"
            :style="{ textAlign: reservationLimitAlign }"
          ></p>

          <button
            type="submit"
            :disabled="loading || !reservationEnabled"
            :style="reservationButtonStyle"
          >
            {{ submitLabel }}
          </button>
          <button
            v-if="undoEnabled && config.attendees.length > 0"
            type="button"
            class="ghost"
            @click="undoReservation"
            :disabled="loading"
            :style="reservationUndoButtonStyle"
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
        <span v-html="tr('project_footer_text', 'EasyReservation – Open Source Projekt auf GitHub')"></span>
      </footer>
    </div>
  </div>
</template>

<style scoped>
.page { min-height: 100vh; width: 100%; box-sizing: border-box; color: var(--text); }
.backdrop { display: flex; flex-direction: column; gap: 1rem; padding: 0.5rem 1rem 1rem; max-width: 1080px; margin: 0 auto; }
.stack { display: flex; flex-direction: column; gap: 1rem; }
.card { border: 1px solid var(--border-strong); border-radius: 10px; padding: 1rem; box-shadow: 0 4px 14px var(--shadow); background: var(--app-card-bg, var(--card)); color: var(--text); }
.form { display: flex; flex-direction: column; gap: 0.75rem; }
label { display: flex; flex-direction: column; gap: 0.25rem; font-weight: 600; color: var(--text); }
input, select, textarea, button { font: inherit; padding: 0.6rem; border: 1px solid var(--border); border-radius: 6px; width: 100%; box-sizing: border-box; background: var(--surface); color: var(--text); }
button { background: var(--primary); color: var(--primary-contrast); cursor: pointer; width: auto; }
button:disabled { opacity: 0.6; cursor: not-allowed; }
.message { color: var(--success-text); background: var(--success-bg); border: 1px solid var(--success-border); padding: 0.5rem; border-radius: 6px; }
.error { color: var(--error-text); background: var(--error-bg); border: 1px solid var(--error-border); padding: 0.5rem; border-radius: 6px; }
.hint { color: var(--text-muted); }
.field { border-top: 1px solid var(--border-strong); padding-top: 0.5rem; }
.details { margin: 0.5rem 0 1rem; }
.details summary { cursor: pointer; font-weight: 600; }
.details div { padding-top: 0.5rem; text-align: left; }
.top-image { text-align: center; margin-bottom: 0.75rem; min-height: 60px; }
.top-image img { max-width: 100%; max-height: 240px; object-fit: contain; }
.top-row { display: flex; align-items: center; justify-content: space-between; gap: 0.5rem; }
button.ghost { background: var(--surface-strong); color: var(--primary); border-color: var(--border-strong); }
.button-row { display: flex; justify-content: flex-end; gap: 5px; }
.title-row { display: flex; }

.project-footer {
  width: 100%;
  margin-top: 2rem;
  padding: 1rem 0;
  text-align: center;
  color: var(--text-muted);
  font-size: 0.95rem;
  background: none;
}
.title-row.align-left { justify-content: flex-start; }
.title-row.align-center { justify-content: center; text-align: center; }
.title-row.align-right { justify-content: flex-end; text-align: right; }
.next-event { margin: 0.25rem 0; font-weight: 600; color: var(--text); }
.next-event-list { margin: 0.25rem 0 0.75rem; }
.next-event-list ul { margin: 0.25rem 0 0; padding-left: 1.25rem; }
.plain-list { list-style: none; padding-left: 0; margin: 0; }
.loading-overlay { position: fixed; inset: 0; background: rgba(0,0,0,0.25); display: flex; align-items: center; justify-content: center; z-index: 60; }
.loader-image { width: 64px; height: 64px; animation: spin 1s linear infinite; object-fit: contain; }
.loader-spinner { width: 48px; height: 48px; border: 4px solid var(--border-strong); border-top-color: var(--primary); border-radius: 50%; animation: spin 1s linear infinite; }
@keyframes spin { from { transform: rotate(0deg); } to { transform: rotate(360deg); } }

.site-token-message {
  background: var(--maintenance-bg, #fffbe6);
  color: var(--maintenance-text, #b45309);
  border: 1px solid var(--maintenance-border, #fde68a);
  border-radius: 8px;
  padding: 1rem;
  margin-bottom: 1.5rem;
  font-size: 1.1rem;
  font-weight: 500;
  box-shadow: 0 2px 8px var(--maintenance-shadow, rgba(251, 191, 36, 0.08));
  text-align: center;
}
</style>

<style>
/* Teleported reservation modal styles (must be unscoped to apply inside body) */
.reservation-modal-backdrop {
  position: fixed;
  inset: 0;
  background: rgba(15,23,42,0.65);
  display: flex;
  align-items: center;
  justify-content: center;
  z-index: 10000;
  padding: 1rem;
  overflow-y: auto;
}
.reservation-modal-backdrop .reservation-modal {
  background: var(--app-card-bg, var(--surface));
  color: var(--text);
  border-radius: 10px;
  padding: 1rem;
  max-width: 420px;
  width: 100%;
  box-shadow: 0 20px 50px var(--shadow);
  border: 1px solid var(--border-strong);
  display: flex;
  flex-direction: column;
  gap: 0.75rem;
  max-height: min(80vh, calc(100dvh - 2rem));
  overflow-y: auto;
}
.reservation-modal-backdrop .reservation-modal-text { margin: 0; font-size: 1rem; }
.reservation-modal-backdrop .reservation-modal button {
  font: inherit;
  padding: 0.6rem;
  border: 1px solid var(--border);
  border-radius: 6px;
  background: var(--primary);
  color: var(--primary-contrast);
  cursor: pointer;
  width: auto;
}
</style>
