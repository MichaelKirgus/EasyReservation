<script setup>

import { ref, reactive, onMounted, computed, watch } from 'vue'
import { useRouter } from 'vue-router'
import LoginDialog from './components/LoginDialog.vue'
import languageIconSrc from './assets/icons/languageicon.svg'
import IconButton from './components/IconButton.vue'
import api from './api'
import { useTranslation } from './composables/useTranslation'
import { renderMarkdown } from './utils/markdown'
import { applySiteBranding, getMediaBaseFallback } from './utils/siteBranding'

// Use the global translation system
const { tr, fetchTranslations } = useTranslation()


// Reaktives Objekt für App-Einstellungen
const appSettings = reactive({ clear_localstorage_on_logout: false })

function mediaUrl(val) {
  if (!val) return ''
  if (val.startsWith('http://') || val.startsWith('https://')) return val
  return `${mediaBase}${val.startsWith('/') ? '' : '/'}${val}`
}

// Lädt App-Einstellungen von der API und speichert sie in appSettings
async function fetchAppSettings() {
  try {
    const { data } = await api.get('/public/config')
    Object.keys(data).forEach(key => {
      appSettings[key] = data[key]
    })
    applySiteBranding(data.settings || data, { mediaBase, fallbackTitle: 'EasyReservation' })
    try {
      const loadingImg = (data.settings || data)?.reservation_loading_image || ''
      cachedLoadingImage.value = loadingImg
      if (loadingImg) {
        localStorage.setItem('reservation_loading_image', loadingImg)
      } else {
        localStorage.removeItem('reservation_loading_image')
      }
    } catch (_) {}
  } catch (e) {
    // Fehler ignorieren, Standardwerte bleiben erhalten
  }
}

const apiBase = import.meta.env.VITE_API_BASE || '/api'
const mediaBase = getMediaBaseFallback()
const router = useRouter()
const selectedLang = ref('de')
const langMenuOpen = ref(false)
const showMobileMenu = ref(false)
const globalLoading = ref(false)
const privacyEnabled = ref(false)
const faqEnabled = ref(false)
const maintenanceEnabled = computed(() => Number((appSettings.settings || appSettings)?.maintenance_enabled || 0) === 1)
// Default to true so the top bar renders even before config fetch completes
const privacyLoaded = ref(true)
const cachedLoadingImage = ref('')
try { cachedLoadingImage.value = localStorage.getItem('reservation_loading_image') || '' } catch (_) { cachedLoadingImage.value = '' }

const loadingImageUrl = computed(() => {
  const url = (appSettings.settings || appSettings)?.reservation_loading_image || cachedLoadingImage.value
  return url ? mediaUrl(url) : ''
})

// Theme handling with forced mode support
const storedTheme = (() => {
  try { return localStorage.getItem('theme') || '' } catch (_) { return '' }
})()
const theme = ref(storedTheme || '')
const hasExplicitTheme = ref(!!storedTheme)
let prefersDarkQuery = null
let handlePrefersChange = null

const forcedThemeMode = computed(() => {
  // theme_mode can be in appSettings.settings or appSettings
  return (appSettings.settings && appSettings.settings.theme_mode) || appSettings.theme_mode || 'auto'
})

function deriveSystemTheme() {
  return window.matchMedia && window.matchMedia('(prefers-color-scheme: dark)').matches ? 'dark' : 'light'
}

function applyTheme(val, persist = false) {
  if (!val) return
  const root = document.documentElement
  root.dataset.theme = val
  if (persist) {
    try { localStorage.setItem('theme', val) } catch (_) {}
  }
}

function toggleTheme() {
  hasExplicitTheme.value = true
  theme.value = theme.value === 'dark' ? 'light' : 'dark'
}

// Watch forcedThemeMode and apply forced theme if set
watch(forcedThemeMode, (mode) => {
  if (mode === 'dark' || mode === 'light') {
    theme.value = mode
    applyTheme(mode, true)
    hasExplicitTheme.value = false
  } else if (mode === 'auto') {
    // Use system or stored theme
    const sysTheme = deriveSystemTheme()
    theme.value = storedTheme || sysTheme
    applyTheme(theme.value, !!storedTheme)
  }
})

// Navigation-Konfiguration für Router-Links (defensiv, damit keine undefined-Gruppen entstehen)
const navGroups = computed(() => {
  const safeFaq = typeof faqEnabled === 'object' && faqEnabled !== null && 'value' in faqEnabled ? faqEnabled.value : false;
  const safePrivacy = typeof privacyEnabled === 'object' && privacyEnabled !== null && 'value' in privacyEnabled ? privacyEnabled.value : false;
  const isMaintenance = typeof maintenanceEnabled === 'object' && maintenanceEnabled !== null && 'value' in maintenanceEnabled ? maintenanceEnabled.value : false;
  const publicTabs = [
    { to: '/', label: tr('nav_tab_reservation', 'Reservation'), show: true },
  ];
  if (safeFaq && !isMaintenance) publicTabs.push({ to: '/faq', label: tr('nav_tab_faq', 'FAQ'), show: true });
  if (safePrivacy && !isMaintenance) publicTabs.push({ to: '/privacy', label: tr('nav_tab_privacy', 'Privacy'), show: true });
  const groups = [
    {
      id: 'public',
      label: tr('nav_public_label', 'Public'),
      tabs: publicTabs,
    },
    {
      id: 'moderation',
      label: tr('nav_moderation_label', 'Moderation'),
      tabs: [
        { to: '/moderation/dashboard', label: tr('nav_tab_dashboard', 'Dashboard'), show: !!(currentUser && currentUser.role) },
        { to: '/moderation/reservations', label: tr('nav_tab_reservations', 'Reservations'), show: !!(currentUser && currentUser.role) },
        { to: '/moderation/templates', label: tr('nav_tab_templates', 'Templates'), show: !!(currentUser && currentUser.role) },
        { to: '/moderation/faq', label: tr('nav_tab_faq', 'FAQ'), show: !!(currentUser && currentUser.role) },
        { to: '/moderation/events', label: tr('nav_tab_events', 'Events'), show: !!(currentUser && currentUser.role) },
        { to: '/moderation/surveys', label: tr('nav_tab_surveys', 'Surveys'), show: !!(currentUser && currentUser.role) },
        { to: '/moderation/placeholders', label: tr('nav_tab_placeholders', 'Placeholders'), show: !!(currentUser && currentUser.role) },
      ],
    },
    {
      id: 'administration',
      label: tr('nav_administration_label', 'Administration'),
      tabs: [
        { to: '/admin/diagnostics', label: tr('nav_tab_diagnostics', 'Diagnostics'), show: !!(currentUser && currentUser.role) },
        { to: '/admin/settings', label: tr('nav_tab_settings', 'Settings'), show: !!(currentUser && currentUser.role) },
        { to: '/admin/mail-transports', label: tr('nav_tab_mail_transports', 'Email Transport'), show: !!(currentUser && currentUser.role) },
        { to: '/admin/scheduled-tasks', label: tr('nav_tab_scheduled_tasks', 'Scheduled Tasks'), show: !!(currentUser && currentUser.role) },
        { to: '/admin/custom-placeholders', label: tr('nav_tab_placeholders', 'Placeholders'), show: !!(currentUser && currentUser.role) },
        { to: '/admin/form-fields', label: tr('nav_tab_form_fields', 'Form Fields'), show: !!(currentUser && currentUser.role) },
        { to: '/admin/validation-rules', label: tr('nav_tab_validation_rules', 'Validation Rules'), show: !!(currentUser && currentUser.role) },
        { to: '/admin/users', label: tr('nav_tab_users', 'Users'), show: !!(currentUser && currentUser.role) },
        { to: '/admin/archives', label: tr('nav_tab_archives', 'Archives'), show: !!(currentUser && currentUser.role) },
        { to: '/admin/auditlog', label: tr('nav_tab_audit_log', 'Audit Log'), show: currentUser && currentUser.role === 'superadmin' },
      ],
    },
  ];
  // Filtere Tabs und Gruppen, die keine sichtbaren Tabs haben (außer public)
  return groups
    .map(group => ({
      ...group,
      tabs: group.id === 'public'
        ? group.tabs // Keine Filterung für 'public'
        : group.tabs.filter(tab => {
            const show = tab.show;
            if (show === true) return true;
            if (show === false || show == null) return false;
            if (typeof show === 'object' && show !== null && 'value' in show) return !!show.value;
            return !!show;
          }),
    }))
    .filter(group => {
      if (group.id === 'public') {
        return !isMaintenance;
      }
      return group.tabs.length > 0;
    });
});

const openDropdown = ref(null)
const windowWidth = ref(window.innerWidth)

function handleResize() {
  windowWidth.value = window.innerWidth
}

function toggleDropdown(groupId) {
  if (windowWidth.value > 768) {
    openDropdown.value = openDropdown.value === groupId ? null : groupId
  }
}

function closeDropdown(e) {
  // Schließe Dropdown, wenn außerhalb geklickt wird (nur Desktop)
  if (windowWidth.value > 768) {
    openDropdown.value = null
  }
}

function closeLangMenu(e) {
  // Schließe Sprachmenü, wenn außerhalb geklickt wird
  if (langMenuOpen.value) {
    const langMenu = document.querySelector('.lang-menu');
    const langTrigger = document.querySelector('.lang-trigger');
    
    // Nur schließen, wenn der Klick nicht auf dem Menü oder Trigger war
    if (langMenu && !langMenu.contains(e.target) &&
        langTrigger && !langTrigger.contains(e.target)) {
      langMenuOpen.value = false;
    }
  }
}

import { onBeforeUnmount } from 'vue'
onMounted(() => {
  document.addEventListener('click', closeDropdown)
  document.addEventListener('click', closeLangMenu)
  window.addEventListener('resize', handleResize)
})
onBeforeUnmount(() => {
  document.removeEventListener('click', closeDropdown)
  document.removeEventListener('click', closeLangMenu)
  window.removeEventListener('resize', handleResize)
  if (prefersDarkQuery && handlePrefersChange) {
    prefersDarkQuery.removeEventListener('change', handlePrefersChange)
  }
})


const loginForm = reactive({ identifier: '', password: '', otp: '' })
const showOtp = ref(false)
const authMessage = ref('')
const authError = ref('')
const authInfo = ref('')
let authMessageTimeout = null
const loadingAuth = ref(false)
const showLogin = ref(false)
let storedUser = null;
try {
  storedUser = localStorage.getItem('admin_user') || sessionStorage.getItem('admin_user');
} catch (_) {}
const currentUser = reactive(storedUser ? JSON.parse(storedUser) : {})
const rememberMe = ref(true)

// Method to get flag URL for a specific language
function getFlagUrl(lang) {
  // Return the URL for the flag from the backend API
  return `${apiBase}/flags/${lang}.svg`;
}

// Load available languages dynamically from backend
async function loadAvailableLanguages() {
  try {
    const response = await api.get('/languages');
    if (response.data && Array.isArray(response.data)) {
      // Return the actual available languages from backend
      return response.data;
    }
  } catch (error) {
    console.warn('Could not load languages from backend:', error);
    // Fallback to default languages if API fails
    return ['de', 'en'];
  }
}

// Load language names and translations dynamically from backend
async function loadLanguageNames() {
  try {
    const response = await api.get('/language-names');
    if (response.data && typeof response.data === 'object') {
      return response.data;
    }
  } catch (error) {
    console.warn('Could not load language names from backend:', error);
    // Fallback to default language names if API fails
    return {
      de: 'Deutsch',
      en: 'English'
    };
  }
}

// Initialize with default languages
const availableLanguages = ref(['de', 'en']);
const languageNames = ref({});

function setAuthMessage(msg) {
  authMessage.value = msg;
  authError.value = '';
  if (authMessageTimeout) clearTimeout(authMessageTimeout);
  if (msg) {
    authMessageTimeout = setTimeout(() => {
      authMessage.value = '';
      authMessageTimeout = null;
    }, 3000);
  }
}
function setAuthError(msg) { authError.value = msg; authMessage.value = ''; authInfo.value = '' }
function setAuthInfo(msg) { authInfo.value = msg; authError.value = ''; authMessage.value = '' }

async function login() {
  loadingAuth.value = true
  try {
    const body = { ...loginForm, remember: rememberMe.value };
    if (!showOtp.value) delete body.otp;
    // Include the selected language in the request
    const res = await fetch(`${apiBase}/auth/login?lang=${selectedLang.value}`, {
      method: 'POST',
      headers: { 'Content-Type': 'application/json', Accept: 'application/json' },
      credentials: 'same-origin',
      body: JSON.stringify(body),
    })
    const text = await res.text()
    if (!res.ok) {
      let errorData = null;
      try { errorData = JSON.parse(text); } catch (_) {}
      if (errorData?.two_factor || text.includes('two_factor') || text.includes('2FA') || text.toLowerCase().includes('otp')) {
        const wasAlreadyShowingOtp = showOtp.value;
        showOtp.value = true;
        if (wasAlreadyShowingOtp) {
          // Invalid OTP code — show as error
          setAuthError(errorData?.message || tr('two_factor_invalid_code', 'Invalid code.'));
        } else {
          // First prompt for OTP — show as info
          setAuthInfo(errorData?.message || tr('two_factor_required', 'Please enter the OTP code from your authenticator app.'));
        }
        return;
      }
      throw new Error(errorData?.message || text)
    }
    const data = JSON.parse(text)
    if (!data.api_token) throw new Error('Kein Token erhalten.')
    // Store a non-sensitive session marker — the actual API key is in an httpOnly cookie
    const sessionMarker = '1'
    const storage = rememberMe.value ? localStorage : sessionStorage
    const otherStorage = rememberMe.value ? sessionStorage : localStorage
    storage.setItem('admin_auth_session', sessionMarker)
    otherStorage.removeItem('admin_auth_session')
    // Clean up any legacy plain-text API key from localStorage
    localStorage.removeItem('admin_api_key')
    sessionStorage.removeItem('admin_api_key')
    if (data.user) {
      Object.assign(currentUser, data.user)
      storage.setItem('admin_user', JSON.stringify(data.user))
      // Set route prefix based on user role
      const userRole = data.user.role?.toLowerCase() || 'user'
      const routePrefix = userRole === 'moderator' ? 'moderator' : 'admin'
      storage.setItem('admin_route_prefix', routePrefix)
      otherStorage.removeItem('admin_user')
    }
    window.dispatchEvent(new CustomEvent('api-key-updated', { detail: sessionMarker }))
    
    // Fetch translations again with the new user's whitelist
    try {
      await fetchTranslations(selectedLang.value)
    } catch (err) {
      console.error('Failed to refresh translations after login:', err)
    }
    
    loginForm.identifier = ''
    loginForm.password = ''
    loginForm.otp = ''
    showLogin.value = false
    showOtp.value = false
    
    // Redirect to post-login URL based on appSettings
    try {
      const redirectUrl = (appSettings.settings || appSettings)?.post_login_redirect_url || '/moderation/dashboard'
      if (redirectUrl && redirectUrl !== '/') {
        router.push(redirectUrl)
      }
    } catch (_) {}
  } catch (e) {
    setAuthError(`Login fehlgeschlagen: ${e}`)
  } finally {
    loadingAuth.value = false
  }
}

async function logout() {
  // Clear the httpOnly auth cookie via backend
  try {
    await fetch(`${apiBase}/auth/logout`, {
      method: 'POST',
      headers: { Accept: 'application/json' },
      credentials: 'same-origin',
    })
  } catch (_) { /* best-effort */ }
  if (appSettings.clear_localstorage_on_logout) {
    localStorage.clear()
  } else {
    localStorage.removeItem('admin_auth_session')
    localStorage.removeItem('admin_api_key')
    localStorage.removeItem('admin_user')
  }
  sessionStorage.removeItem('admin_auth_session')
  sessionStorage.removeItem('admin_api_key')
  sessionStorage.removeItem('admin_user')
  Object.keys(currentUser).forEach(k => delete currentUser[k])
  window.dispatchEvent(new CustomEvent('api-key-updated', { detail: '' }))
  setAuthMessage('Abgemeldet.')
  router.push('/')
}

function handleSwitchTab(e) {
  const target = e?.detail
  if (target && visibleTabKeys.value.includes(target)) {
    active.value = target
    closeAllGroups()
    showMobileMenu.value = false
  }
}

function handleLoadingStart() { globalLoading.value = true }
function handleLoadingEnd() { globalLoading.value = false }


onMounted(async () => {
  prefersDarkQuery = window.matchMedia('(prefers-color-scheme: dark)')
  const initialTheme = theme.value || deriveSystemTheme()
  theme.value = initialTheme
  applyTheme(initialTheme, hasExplicitTheme.value)
  handlePrefersChange = (event) => {
    if (!hasExplicitTheme.value) {
      theme.value = event.matches ? 'dark' : 'light'
      applyTheme(theme.value, false)
    }
  }
  prefersDarkQuery.addEventListener('change', handlePrefersChange)

  await fetchAppSettings();
  // Load available languages and language names from backend
  try {
    const languages = await loadAvailableLanguages();
    availableLanguages.value = languages;
    
    const names = await loadLanguageNames();
    languageNames.value = names;
  } catch (error) {
    console.error('Failed to load dynamic languages:', error);
  }
  
  // Initialize translations for the selected language
  try {
    if (selectedLang.value) {
      await fetchTranslations(selectedLang.value);
    }
  } catch (err) {
    console.error('Failed to initialize translations:', err);
  }
  
  window.addEventListener('loading-start', handleLoadingStart)
  window.addEventListener('loading-end', handleLoadingEnd)
  window.addEventListener('settings-updated', fetchPrivacyEnabled)
  fetchPrivacyEnabled()
})

watch(theme, (val) => {
  if (!val) return
  applyTheme(val, hasExplicitTheme.value)
})


function switchLang(lang) {
  console.log('Switching language to:', lang); // Debug log
  selectedLang.value = lang
  langMenuOpen.value = false
  console.log('Current selectedLang after switch:', selectedLang.value); // Debug log
  
  // Update translations when language changes
  if (lang) {
    fetchTranslations(lang);
  }
}

// This function is now replaced with a dynamic approach that fetches from backend
// The actual implementation will be handled in the template using async/await where needed

async function fetchPrivacyEnabled() {
  try {
    const { data } = await api.get('/public/config')
    privacyEnabled.value = !!(data.privacy_policy_enabled);
    faqEnabled.value = !!(data.faq_enabled);
    privacyLoaded.value = true;
  } catch {
    privacyEnabled.value = false;
    faqEnabled.value = false;
    privacyLoaded.value = true;
  }
}
</script>

<template>
  <main class="page">
    <header class="topbar" v-if="privacyLoaded">
      <div class="brand-row">
        <div class="left-actions">
          <button
            class="menu-toggle"
            @click="() => { showMobileMenu = !showMobileMenu }"
            aria-label="Menü umschalten"
          >
            <span></span><span></span><span></span>
          </button>
          <nav class="tabs" :class="{ open: showMobileMenu }" v-if="privacyLoaded">
            <div
              class="tab-group-block"
              v-for="group in navGroups"
              :key="group.id"
            >
              <!-- Desktop: Dropdown-Trigger und Menü -->
              <template v-if="windowWidth > 768">
                <span
                  class="tab-group-label dropdown-trigger"
                  @click.stop="toggleDropdown(group.id)"
                  :aria-expanded="openDropdown === group.id"
                >
                  {{ group.label }}
                </span>
                <div
                  v-if="group.tabs && group.tabs.length"
                  class="tab-group-tabs dropdown"
                  :class="{ open: openDropdown === group.id }"
                >
                  <router-link
                    v-for="tab in group.tabs"
                    :key="tab.to"
                    class="tab"
                    :to="tab.to"
                    active-class="active"
                    @click="openDropdown = null"
                  >
                    {{ tab.label }}
                  </router-link>
                </div>
              </template>
              <!-- Mobil: Inline-Tabs -->
              <template v-else>
                <span class="tab-group-label">{{ group.label }}</span>
                <div
                  v-if="group.tabs && group.tabs.length"
                  class="tab-group-tabs"
                  v-show="showMobileMenu"
                >
                  <router-link
                    v-for="tab in group.tabs"
                    :key="tab.to"
                    class="tab"
                    :to="tab.to"
                    active-class="active"
                    @click="showMobileMenu = false"
                  >
                    {{ tab.label }}
                  </router-link>
                </div>
              </template>
            </div>
          </nav>
        </div>
        <div class="right-actions">
          <button
            v-if="forcedThemeMode === 'auto'"
            class="theme-toggle"
            @click="toggleTheme"
            :aria-label="theme === 'dark' ? 'Switch to light mode' : 'Switch to dark mode'"
          >
            <span class="theme-icon" aria-hidden="true">{{ theme === 'dark' ? '🌙' : '☀️' }}</span>
          </button>
          <div class="lang-switch">
            <button class="lang-trigger" @click="langMenuOpen = !langMenuOpen" title="Sprache wechseln" aria-label="Sprache wechseln">
              <img class="lang-icon" :src="languageIconSrc" alt="Language" />
              <img class="flag-icon" :src="getFlagUrl(selectedLang)" :alt="selectedLang" />
            </button>
            <div v-if="langMenuOpen" class="lang-menu">
              <button
                v-for="lang in availableLanguages"
                :key="lang"
                :class="{ active: selectedLang === lang }"
                @click="switchLang(lang)"
              >
                <img :src="getFlagUrl(lang)" :alt="languageNames[lang] || lang" />
                <span>{{ languageNames[lang] || lang }}</span>
              </button>
            </div>
          </div>
          <div class="auth-block">
            <IconButton icon="login" label="Anmelden" class="ghost" size="sm" @click="showLogin = true" v-if="!currentUser.name" />
            <div v-else class="user-pill">
              <span class="user-name">{{ currentUser.name }} ({{ currentUser.role }})</span>
              <router-link to="/user/2fa" class="user-menu-item">
                <IconButton icon="shield" :label="tr('two_factor_settings_tooltip', 'Two-Factor Authentication Settings')" class="ghost" size="sm" />
              </router-link>
              <IconButton icon="logout" :label="tr('logout_tooltip', 'Logout')" class="ghost" size="sm" @click="logout" />
            </div>
          </div>
        </div>
      </div>
      <div v-if="authMessage" class="message">{{ authMessage }}</div>
      <div v-if="authError" class="error">{{ authError }}</div>
    </header>

    <section class="panel">
      <div v-if="globalLoading || loadingAuth" class="loading-overlay" aria-busy="true" aria-live="polite">
        <img v-if="loadingImageUrl" :src="loadingImageUrl" alt="Loading" class="loader-image" />
        <div v-else class="loader-spinner" aria-hidden="true"></div>
      </div>
      <router-view :lang-code="selectedLang" />
    </section>

    <LoginDialog
      v-model="showLogin"
      :loading="loadingAuth"
      :error="authError"
      :info="authInfo"
      :showOtp="showOtp"
      @login="(form) => { Object.assign(loginForm, form); login(); }"
    />
  </main>
</template>

<style scoped>
.page {
  min-height: 100vh;
  background: transparent;
  color: var(--text);
  font-family: "Inter", "Segoe UI", system-ui, -apple-system, sans-serif;
  padding: 0;
}

.topbar {
  display: flex;
  flex-direction: column;
  gap: 0.5rem;
  margin-bottom: 0.75rem;
  padding: 0.75rem 1rem;
  background: var(--app-card-bg, var(--card));
  backdrop-filter: blur(4px);
  border-bottom: 1px solid var(--border-strong);
  position: relative;
  z-index: 30;
}

.brand-row {
  display: flex;
  justify-content: space-between;
  align-items: center;
  gap: 0.5rem;
  flex-wrap: nowrap;
}

.left-actions { flex: 1; display: flex; align-items: center; gap: 0.5rem; }
.right-actions { display: flex; align-items: center; gap: 0.5rem; }

.theme-toggle {
  display: inline-flex;
  align-items: center;
  gap: 0.35rem;
  border: 1px solid var(--border-strong);
  background: var(--surface-strong);
  color: var(--text);
  padding: 0 0.6rem;
  border-radius: 8px;
  height: 34px;
  box-sizing: border-box;
  cursor: pointer;
  transition: color 0.15s, border-color 0.15s, background 0.15s;
}
.theme-toggle:hover,
.theme-toggle:focus-visible {
  border-color: var(--primary);
  color: var(--primary);
  outline: none;
}
.theme-icon { font-size: 1rem; line-height: 1; }

.menu-toggle {
  display: none;
  border: 1px solid var(--border);
  background: var(--surface) !important;
  color: var(--text) !important;
  padding: 0.45rem 0.55rem;
  border-radius: 6px;
  cursor: pointer;
  gap: 4px;
}

.menu-toggle span {
  display: block;
  width: 18px;
  height: 2px;
  background: var(--text);
  border-radius: 2px;
}


.tabs {
  display: flex;
  flex-direction: row;
  gap: 1.5rem;
  align-items: flex-end;
  position: relative;
  z-index: 20;
}


.tab-group-block {
  display: flex;
  flex-direction: column;
  align-items: flex-start;
  gap: 0.2rem;
  width: 100%;
}

.tab-group-label {
  font-weight: 700;
  color: var(--text);
  background: var(--surface-muted);
  border-radius: 6px 6px 0 0;
  padding: 0.25rem 0.7rem 0.15rem 0.7rem;
  font-size: 1rem;
  margin-bottom: 0.1rem;
  border: none;
}


.tab-group-tabs {
  display: flex;
  flex-direction: row;
  gap: 0.3rem;
  width: 100%;
}

@media (min-width: 769px) {
  .tab-group-block {
    position: relative;
    width: auto;
    min-width: 0;
    display: block;
  }
  .tab-group-label.dropdown-trigger {
    cursor: pointer;
    user-select: none;
    position: relative;
    z-index: 21;
    color: var(--primary);
    background: var(--surface-strong);
    transition: background 0.15s, color 0.15s;
    box-shadow: 0 1px 4px rgba(37,99,235,0.04);
  }
  .tab-group-label.dropdown-trigger:hover,
  .tab-group-label.dropdown-trigger:focus {
    background: var(--primary);
    color: var(--primary-contrast);
    outline: none;
  }
  .tab-group-tabs.dropdown {
    display: none;
    position: absolute;
    left: 0;
    top: calc(100% + 8px);
    min-width: 220px;
    width: auto;
    background: var(--surface);
    border: 1px solid var(--border-strong);
    border-radius: 10px;
    box-shadow: 0 8px 24px rgba(15,23,42,0.12);
    flex-direction: column;
    gap: 0;
    padding: 0;
    z-index: 22;
    overflow: hidden;
  }
  .tab-group-tabs.dropdown .tab {
    width: 100%;
    min-width: 160px;
    white-space: nowrap;
    border-radius: 0;
    border: none;
    border-bottom: 1px solid var(--border-strong);
    background: var(--surface);
    color: var(--text);
    text-align: left;
    font-size: 1rem;
    padding: 0.5rem 1.2rem;
    transition: background 0.15s, color 0.15s;
  }
  .tab-group-tabs.dropdown .tab:first-child {
    border-top: none;
    border-top-left-radius: 10px;
    border-top-right-radius: 10px;
  }
  .tab-group-tabs.dropdown .tab:last-child {
    border-bottom: none;
    border-bottom-left-radius: 10px;
    border-bottom-right-radius: 10px;
  }
  .tab-group-tabs.dropdown.open {
    display: flex;
  }
  .tab-group-tabs.dropdown .tab:hover,
  .tab-group-tabs.dropdown .tab:focus,
  .tab-group-tabs.dropdown .tab.active {
    background: var(--primary);
    color: var(--primary-contrast);
    outline: none;
    z-index: 2;
  }
}

.tab {
  border: 1px solid var(--border);
  background: var(--surface);
  padding: 0.35rem 0.7rem;
  border-radius: 6px;
  cursor: pointer;
  transition: all 0.15s ease;
  color: var(--text);
  text-align: left;
  font-size: 1rem;
  display: block;
  width: auto;
  text-decoration: none;
}

.tab.active {
  background: var(--primary);
  color: var(--primary-contrast);
  border-color: var(--primary);
  box-shadow: 0 2px 6px rgba(37, 99, 235, 0.25);
}
.right-actions {
  display: flex;
  align-items: center;
  gap: 0.5rem;
  margin-left: auto;
}

@media (max-width: 900px) {
  .tabs {
    gap: 0.7rem;
  }
  .tab-group-label {
    font-size: 0.98rem;
    padding: 0.18rem 0.5rem 0.12rem 0.5rem;
  }
  .tab {
    font-size: 0.98rem;
    padding: 0.28rem 0.5rem;
  }
}


@media (max-width: 768px) {
  .tabs {
    flex-direction: column;
    align-items: stretch;
    gap: 0.7rem;
    display: none;
    position: absolute;
    left: 0;
    right: 0;
    top: 100%;
    background: var(--surface);
    box-shadow: 0 8px 24px rgba(15,23,42,0.12);
    border-bottom-left-radius: 10px;
    border-bottom-right-radius: 10px;
    z-index: 100;
    padding: 1rem 0.5rem 1rem 0.5rem;
  }
  .tabs.open {
    display: flex;
  }
  .tab-group-block {
    gap: 0.1rem;
    width: 100%;
  }
  .tab-group-label {
    width: 100%;
    border-radius: 6px 6px 0 0;
    background: var(--surface-muted);
    margin-bottom: 0.1rem;
    padding: 0.22rem 0.7rem 0.12rem 0.7rem;
  }
  .tab-group-tabs {
    flex-direction: column;
    gap: 0.1rem;
    width: 100%;
  }
  .tab {
    width: 100%;
    border-radius: 0 0 6px 6px;
    padding: 0.32rem 0.7rem;
    font-size: 1rem;
    display: block;
  }
}

.panel {
  background: var(--app-card-bg, var(--card));
  border: 1px solid var(--border-strong);
  border-radius: 10px;
  padding: 1.25rem;
  box-shadow: 0 10px 30px var(--shadow);
  margin: 0 1rem 1rem;
  position: relative;
}

input, button {
  font: inherit;
  padding: 0.5rem 0.65rem;
  border: 1px solid var(--border);
  border-radius: 6px;
}

button { background: var(--primary); color: var(--primary-contrast); cursor: pointer; }
button:disabled { opacity: 0.6; cursor: not-allowed; }
button.ghost { background: var(--surface-strong); color: var(--primary); border-color: var(--border-strong); }

.message { color: var(--success-text); background: var(--success-bg); border: 1px solid var(--success-border); padding: 0.5rem; border-radius: 6px; }
.error { color: var(--error-text); background: var(--error-bg); border: 1px solid var(--error-border); padding: 0.5rem; border-radius: 6px; }

.modal-backdrop {
  position: fixed;
  inset: 0;
  background: rgba(15, 23, 42, 0.6);
  display: flex;
  align-items: center;
  justify-content: center;
  padding: 1rem;
  z-index: 20;
}

.modal {
  background: var(--app-card-bg, var(--surface));
  color: var(--text);
  border-radius: 12px;
  padding: 1rem;
  width: min(420px, 100%);
  box-shadow: 0 20px 50px var(--shadow);
  border: 1px solid var(--border-strong);
  display: flex;
  flex-direction: column;
  gap: 0.75rem;
}

.modal input {
  box-sizing: border-box;
}

.modal .form-field {
  display: flex;
  flex-direction: column;
  gap: 0.35rem;
  font-weight: 600;
  width: 100%;
}

.modal .form-field input {
  width: 100%;
}

.modal .checkbox {
  display: flex;
  align-items: center;
  gap: 0.5rem;
  font-weight: 600;
}

.modal .checkbox input {
  width: auto;
}

.lang-switch { position: relative; }
.lang-trigger {
  display: inline-flex;
  align-items: center;
  gap: 0.35rem;
  border: 1px solid var(--border-strong);
  background: var(--surface-strong);
  padding: 0 0.55rem;
  border-radius: 8px;
  cursor: pointer;
  height: 34px;
  box-sizing: border-box;
}
.lang-icon { width: 20px; height: 20px; }
.lang-icon { filter: var(--icon-filter); }
.flag-icon { width: 24px; height: 18px; border-radius: 3px; border: 1px solid var(--border-strong); }
.lang-menu {
  position: absolute;
  right: 0;
  top: calc(100% + 6px);
  background: var(--surface);
  border: 1px solid var(--border-strong);
  border-radius: 8px;
  box-shadow: 0 10px 20px rgba(15,23,42,0.12);
  padding: 0.35rem;
  display: flex;
  flex-direction: column;
  gap: 0.25rem;
  z-index: 10;
}
.lang-menu button {
  display: flex;
  align-items: center;
  gap: 0.5rem;
  border: none;
  background: transparent;
  padding: 0.35rem 0.5rem;
  border-radius: 6px;
  cursor: pointer;
  color: var(--text);
}
.lang-menu button.active span { font-weight: 700; }
.lang-menu button:hover { background: var(--surface-muted); }
.lang-menu img { width: 24px; height: 18px; border-radius: 3px; border: 1px solid var(--border-strong); }

.auth-block { display: flex; align-items: center; gap: 0.5rem; }
.user-pill {
  display: flex;
  align-items: center;
  gap: 0.35rem;
  background: var(--surface-strong);
  border: 1px solid var(--border-strong);
  padding: 0 0.55rem;
  border-radius: 8px;
  height: 34px;
  box-sizing: border-box;
}
.user-name { font-weight: 600; color: var(--text); }
.user-menu-item {
  color: var(--primary);
  text-decoration: none;
  font-weight: 600;
  padding: 0.25rem 0.5rem;
  border-radius: 6px;
}
.loading-overlay { position: absolute; inset: 0; background: rgba(0,0,0,0.25); display: flex; align-items: center; justify-content: center; z-index: 15; border-radius: 10px; }
.loader-image { width: 64px; height: 64px; animation: spin 1s linear infinite; object-fit: contain; }
.loader-spinner { width: 48px; height: 48px; border: 4px solid var(--border-strong); border-top-color: var(--primary); border-radius: 50%; animation: spin 1s linear infinite; }
@keyframes spin { from { transform: rotate(0deg); } to { transform: rotate(360deg); } }

@media (max-width: 768px) {
  .brand-row { flex-wrap: nowrap; }
  .left-actions { width: auto; }
  .right-actions { width: auto; justify-content: flex-end; }
  .menu-toggle { display: inline-flex; flex-direction: column; align-items: center; justify-content: center; }
  .tabs { display: none; flex-direction: column; align-items: stretch; }
  .tabs.open { display: flex; }
  .tab-group-block { gap: 0.1rem; }
  .tab-group-label { width: 100%; border-radius: 6px 6px 0 0; background: var(--surface-muted); margin-bottom: 0.1rem; padding: 0.22rem 0.7rem 0.12rem 0.7rem; }
  .tab-group-tabs { flex-direction: column; gap: 0.1rem; width: 100%; }
  .tab { width: 100%; border-radius: 0 0 6px 6px; padding: 0.32rem 0.7rem; font-size: 1rem; }
}

:global(.panel) .card {
  background: var(--app-card-bg, rgba(255,255,255,0.9));
  backdrop-filter: blur(6px);
  border: 1px solid var(--border-strong);
  box-shadow: 0 8px 24px rgba(15, 23, 42, 0.08);
}

@media (max-width: 420px) {
  .topbar { padding: 0.45rem 0.5rem; gap: 0.3rem; }
  .brand-row { gap: 0.25rem; }
  .panel { margin: 0 0.45rem 0.7rem; padding: 0.75rem; }
  .tab-group-label { padding: 0.28rem 0.4rem; min-width: 0; }
  .tab { padding: 0.28rem 0.4rem; }
  input, button { padding: 0.42rem 0.5rem; }
  .modal { padding: 0.75rem; }
}
</style>

<style scoped>
.maintenance-message {
  background: var(--maintenance-bg, #fffbe6);
  color: var(--maintenance-text, #b45309);
  border: 1px solid var(--maintenance-border, #fde68a);
  border-radius: 8px;
  padding: 1rem;
  margin-bottom: 1.5rem;
  font-size: 1.1rem;
  font-weight: 500;
  box-shadow: 0 2px 8px var(--maintenance-shadow, rgba(251, 191, 36, 0.08));
}

:root {
  --maintenance-bg: #fffbe6;
  --maintenance-text: #b45309;
  --maintenance-border: #fde68a;
  --maintenance-shadow: rgba(251, 191, 36, 0.08);
}

[data-theme="dark"] .maintenance-message {
  --maintenance-bg: #2d2612;
  --maintenance-text: #facc15;
  --maintenance-border: #a16207;
  --maintenance-shadow: rgba(250, 204, 21, 0.13);
}
</style>

<!-- Global (unscoped) maintenance variables to ensure both top banner and reservation view share the same theme-aware colors -->
<style>
:root {
  --maintenance-bg: #fffbe6;
  --maintenance-text: #b45309;
  --maintenance-border: #fde68a;
  --maintenance-shadow: rgba(251, 191, 36, 0.08);
}

[data-theme="dark"] .maintenance-message,
[data-theme="dark"] .site-token-message {
  --maintenance-bg: #2d2612;
  --maintenance-text: #facc15;
  --maintenance-border: #a16207;
  --maintenance-shadow: rgba(250, 204, 21, 0.13);
}
</style>
