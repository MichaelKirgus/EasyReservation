<script setup>

import { ref, reactive, onMounted, computed } from 'vue'
import languageIconSrc from './assets/icons/languageicon.svg'
import flagDe from './assets/icons/flag-de.svg'
import flagUs from './assets/icons/flag-us.svg'
import IconButton from './components/IconButton.vue'
import api from './api'


// Reaktives Objekt für App-Einstellungen
const appSettings = reactive({ clear_localstorage_on_logout: false })

// Lädt App-Einstellungen von der API und speichert sie in appSettings
async function fetchAppSettings() {
  try {
    const { data } = await api.get('/public/config')
    // Übertrage alle Properties in appSettings
    Object.keys(data).forEach(key => {
      appSettings[key] = data[key]
    })
  } catch (e) {
    // Fehler ignorieren, Standardwerte bleiben erhalten
  }
}

const apiBase = import.meta.env.VITE_API_BASE || '/api'
const selectedLang = ref('de')
const langMenuOpen = ref(false)
const showMobileMenu = ref(false)
const globalLoading = ref(false)
const privacyEnabled = ref(false)
const faqEnabled = ref(false)
const privacyLoaded = ref(false)

// Navigation-Konfiguration für Router-Links (defensiv, damit keine undefined-Gruppen entstehen)
const navGroups = computed(() => {
  const safeFaq = typeof faqEnabled === 'object' && faqEnabled !== null && 'value' in faqEnabled ? faqEnabled.value : false;
  const safePrivacy = typeof privacyEnabled === 'object' && privacyEnabled !== null && 'value' in privacyEnabled ? privacyEnabled.value : false;
  const publicTabs = [
    { to: '/', label: 'Reservierung', show: true },
  ];
  if (safeFaq) publicTabs.push({ to: '/faq', label: 'FAQ', show: true });
  if (safePrivacy) publicTabs.push({ to: '/privacy', label: 'Datenschutz', show: true });
  const groups = [
    {
      id: 'public',
      label: 'Öffentlich',
      tabs: publicTabs,
    },
    {
      id: 'moderation',
      label: 'Moderation',
      tabs: [
        { to: '/moderation/reservations', label: 'Reservierungen', show: !!(currentUser && currentUser.role) },
        { to: '/moderation/email', label: 'E-Mail', show: !!(currentUser && currentUser.role) },
        { to: '/moderation/faq', label: 'FAQ', show: !!(currentUser && currentUser.role) },
        { to: '/moderation/events', label: 'Termine', show: !!(currentUser && currentUser.role) },
      ],
    },
    {
      id: 'administration',
      label: 'Administration',
      tabs: [
        { to: '/admin/diagnostics', label: 'Diagnose', show: !!(currentUser && currentUser.role) },
        { to: '/admin/settings', label: 'Einstellungen', show: !!(currentUser && currentUser.role) },
        { to: '/admin/scheduled-tasks', label: 'Geplante Aufgaben', show: !!(currentUser && currentUser.role) },
        { to: '/admin/custom-placeholders', label: 'Platzhalter', show: !!(currentUser && currentUser.role) },
        { to: '/admin/form-fields', label: 'Formularfelder', show: !!(currentUser && currentUser.role) },
        { to: '/admin/users', label: 'Benutzer', show: !!(currentUser && currentUser.role) },
        { to: '/admin/auditlog', label: 'Audit-Log', show: currentUser && currentUser.role === 'superadmin' },
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
    .filter(group => group.id === 'public' || group.tabs.length > 0);
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

import { onBeforeUnmount } from 'vue'
onMounted(() => {
  document.addEventListener('click', closeDropdown)
  window.addEventListener('resize', handleResize)
})
onBeforeUnmount(() => {
  document.removeEventListener('click', closeDropdown)
  window.removeEventListener('resize', handleResize)
})


const loginForm = reactive({ identifier: '', password: '' })
const showOtp = ref(false)
const authMessage = ref('')
const authError = ref('')
const loadingAuth = ref(false)
const showLogin = ref(false)
let storedUser = null;
try {
  storedUser = localStorage.getItem('admin_user') || sessionStorage.getItem('admin_user');
} catch (_) {}
const currentUser = reactive(storedUser ? JSON.parse(storedUser) : {})
const rememberMe = ref(true)

function setAuthMessage(msg) { authMessage.value = msg; authError.value = '' }
function setAuthError(msg) { authError.value = msg; authMessage.value = '' }

async function login() {
  loadingAuth.value = true
  try {
    const body = { ...loginForm };
    if (!showOtp.value) delete body.otp;
    const res = await fetch(`${apiBase}/auth/login`, {
      method: 'POST',
      headers: { 'Content-Type': 'application/json', Accept: 'application/json' },
      body: JSON.stringify(body),
    })
    const text = await res.text()
    if (!res.ok) {
      if (text.includes('two factor') || text.includes('2FA') || text.toLowerCase().includes('otp')) {
        showOtp.value = true;
        setAuthError('Bitte OTP-Code eingeben.');
        return;
      }
      throw new Error(text)
    }
    const data = JSON.parse(text)
    const token = data.api_token
    if (!token) throw new Error('Kein Token erhalten.')
    const storage = rememberMe.value ? localStorage : sessionStorage
    const otherStorage = rememberMe.value ? sessionStorage : localStorage
    storage.setItem('admin_api_key', token)
    otherStorage.removeItem('admin_api_key')
    if (data.user) {
      Object.assign(currentUser, data.user)
      storage.setItem('admin_user', JSON.stringify(data.user))
      otherStorage.removeItem('admin_user')
    }
    window.dispatchEvent(new CustomEvent('api-key-updated', { detail: token }))
    setAuthMessage(`Angemeldet als ${data.user?.name || ''} (${data.user?.role || ''}).`)
    loginForm.password = ''
    showLogin.value = false
    showOtp.value = false
  } catch (e) {
    setAuthError(`Login fehlgeschlagen: ${e}`)
  } finally {
    loadingAuth.value = false
  }
}

function logout() {
  if (appSettings.clear_localstorage_on_logout) {
    localStorage.clear()
  } else {
    localStorage.removeItem('admin_api_key')
    localStorage.removeItem('admin_user')
  }
  sessionStorage.removeItem('admin_api_key')
  sessionStorage.removeItem('admin_user')
  Object.keys(currentUser).forEach(k => delete currentUser[k])
  window.dispatchEvent(new CustomEvent('api-key-updated', { detail: '' }))
  setAuthMessage('Abgemeldet.')
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
  await fetchAppSettings();
  window.addEventListener('loading-start', handleLoadingStart)
  window.addEventListener('loading-end', handleLoadingEnd)
  window.addEventListener('settings-updated', fetchPrivacyEnabled)
  fetchPrivacyEnabled()
})


function switchLang(lang) {
  selectedLang.value = lang
  langMenuOpen.value = false
}

function flagSrc(lang) {
  return lang === 'de' ? flagDe : flagUs
}

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
          <div class="lang-switch">
            <button class="lang-trigger" @click="langMenuOpen = !langMenuOpen" title="Sprache wechseln" aria-label="Sprache wechseln">
              <img class="lang-icon" :src="languageIconSrc" alt="Language" />
              <img class="flag-icon" :src="flagSrc(selectedLang)" :alt="selectedLang" />
            </button>
            <div v-if="langMenuOpen" class="lang-menu">
              <button :class="{ active: selectedLang === 'de' }" @click="switchLang('de')">
                <img :src="flagSrc('de')" alt="Deutsch" />
                <span>Deutsch</span>
              </button>
              <button :class="{ active: selectedLang === 'en' }" @click="switchLang('en')">
                <img :src="flagSrc('en')" alt="English" />
                <span>English</span>
              </button>
            </div>
          </div>
          <div class="auth-block">
            <IconButton icon="login" label="Anmelden" class="ghost" size="sm" @click="showLogin = true" v-if="!currentUser.name" />
            <div v-else class="user-pill">
              <span class="user-name">{{ currentUser.name }} ({{ currentUser.role }})</span>
              <IconButton icon="logout" label="Abmelden" class="ghost" size="sm" @click="logout" />
            </div>
          </div>
        </div>
      </div>
      <div v-if="authMessage" class="message">{{ authMessage }}</div>
      <div v-if="authError" class="error">{{ authError }}</div>
    </header>

    <section class="panel">
      <div v-if="globalLoading || loadingAuth" class="loading-overlay" aria-busy="true" aria-live="polite">
        <div class="loader-spinner" aria-hidden="true"></div>
      </div>
      <router-view :lang-code="selectedLang" />
    </section>

    <div v-if="showLogin" class="modal-backdrop" @click.self="showLogin = false">
      <div class="modal">
        <h3>Anmelden</h3>
        <label class="form-field">Benutzername/E-Mail
          <input v-model="loginForm.identifier" />
        </label>
        <label class="form-field">Passwort
          <input v-model="loginForm.password" type="password" placeholder="••••••" />
        </label>
        <label v-if="showOtp" class="form-field">OTP-Code
          <input v-model="loginForm.otp" placeholder="123456" />
        </label>
        <label class="checkbox">
          <input type="checkbox" v-model="rememberMe" />
          <span>Angemeldet bleiben</span>
        </label>
        <div class="modal-actions">
          <button @click="login" :disabled="loadingAuth">Anmelden</button>
          <button class="ghost" type="button" @click="showLogin = false">Abbrechen</button>
        </div>
        <div v-if="authError" class="error">{{ authError }}</div>
      </div>
    </div>
  </main>
</template>

<style scoped>
.page {
  min-height: 100vh;
  background: transparent;
  color: #0f172a;
  font-family: "Inter", "Segoe UI", system-ui, -apple-system, sans-serif;
  padding: 0;
}

.topbar {
  display: flex;
  flex-direction: column;
  gap: 0.5rem;
  margin-bottom: 0.75rem;
  padding: 0.75rem 1rem;
  background: var(--app-card-bg, rgba(255,255,255,0.9));
  backdrop-filter: blur(4px);
  border-bottom: 1px solid #e5e7eb;
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

.menu-toggle {
  display: none;
  border: 1px solid #d1d5db;
  background: #fff !important;
  color: #0f172a !important;
  padding: 0.45rem 0.55rem;
  border-radius: 6px;
  cursor: pointer;
  gap: 4px;
}

.menu-toggle span {
  display: block;
  width: 18px;
  height: 2px;
  background: #0f172a;
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
  color: #1e293b;
  background: #f1f5f9;
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
    color: #1d4ed8;
    background: #e0e7ff;
    transition: background 0.15s, color 0.15s;
    box-shadow: 0 1px 4px rgba(37,99,235,0.04);
  }
  .tab-group-label.dropdown-trigger:hover,
  .tab-group-label.dropdown-trigger:focus {
    background: #2563eb;
    color: #fff;
    outline: none;
  }
  .tab-group-tabs.dropdown {
    display: none;
    position: absolute;
    left: 0;
    top: calc(100% + 8px);
    min-width: 220px;
    width: auto;
    background: transparent;
    border: 1px solid #e5e7eb;
    border-radius: 10px;
    box-shadow: 0 8px 24px rgba(15,23,42,0.12);
    flex-direction: column;
    gap: 0;
    padding: 0.2rem 0;
    z-index: 22;
    overflow: visible;
  }
  .tab-group-tabs.dropdown .tab {
    background: #fff;
    border-radius: 0;
    position: relative;
    z-index: 1;
  }
  .tab-group-tabs.dropdown .tab {
    background: #fff;
    border-radius: 0;
  }
  .tab-group-tabs.dropdown .tab {
    min-width: 160px;
    white-space: nowrap;
  }
  .tab-group-tabs.dropdown.open {
    display: flex;
  }
  .tab-group-tabs.dropdown .tab {
    width: 100%;
    border-radius: 0;
    border-left: none;
    border-right: none;
    border-top: none;
    border-bottom: 1px solid #e5e7eb;
    background: #fff;
    color: #0f172a;
    text-align: left;
    font-size: 1rem;
    padding: 0.5rem 1.2rem;
    transition: background 0.15s, color 0.15s;
  }
  .tab-group-tabs.dropdown .tab:first-child {
    border-top: none;
  }
  .tab-group-tabs.dropdown .tab:last-child {
    border-bottom: none;
  }
  .tab-group-tabs.dropdown .tab:first-child:hover,
  .tab-group-tabs.dropdown .tab:first-child:focus {
    border-top-left-radius: 10px;
    border-top-right-radius: 10px;
  }
  .tab-group-tabs.dropdown .tab:last-child:hover,
  .tab-group-tabs.dropdown .tab:last-child:focus {
    border-bottom-left-radius: 10px;
    border-bottom-right-radius: 10px;
  }
  .tab-group-tabs.dropdown .tab:hover,
  .tab-group-tabs.dropdown .tab:focus,
  .tab-group-tabs.dropdown .tab.active {
    background: #2563eb;
    color: #fff;
    outline: none;
    z-index: 2;
  }
  .tab-group-tabs.dropdown .tab:first-child:hover,
  .tab-group-tabs.dropdown .tab:first-child:focus,
  .tab-group-tabs.dropdown .tab:first-child.active {
    border-top-left-radius: 10px;
    border-top-right-radius: 10px;
  }
  .tab-group-tabs.dropdown .tab:last-child:hover,
  .tab-group-tabs.dropdown .tab:last-child:focus,
  .tab-group-tabs.dropdown .tab:last-child.active {
    border-bottom-left-radius: 10px;
    border-bottom-right-radius: 10px;
  }
}

.tab {
  border: 1px solid #d1d5db;
  background: #fff;
  padding: 0.35rem 0.7rem;
  border-radius: 6px;
  cursor: pointer;
  transition: all 0.15s ease;
  color: #0f172a;
  text-align: left;
  font-size: 1rem;
  display: block;
  width: auto;
  text-decoration: none;
}

.tab.active {
  background: #2563eb;
  color: #fff;
  border-color: #2563eb;
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
    background: #fff;
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
    background: #f1f5f9;
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
  background: var(--app-card-bg, rgba(255,255,255,0.9));
  border: 1px solid #e5e7eb;
  border-radius: 10px;
  padding: 1.25rem;
  box-shadow: 0 10px 30px rgba(15, 23, 42, 0.06);
  margin: 0 1rem 1rem;
  position: relative;
}

input, button {
  font: inherit;
  padding: 0.5rem 0.65rem;
  border: 1px solid #d1d5db;
  border-radius: 6px;
}

button { background: #2563eb; color: #fff; cursor: pointer; }
button:disabled { opacity: 0.6; cursor: not-allowed; }
button.ghost { background: #eef2ff; color: #1d4ed8; border-color: #c7d2fe; }

.message { color: #065f46; background: #ecfdf3; border: 1px solid #a7f3d0; padding: 0.5rem; border-radius: 6px; }
.error { color: #991b1b; background: #fef2f2; border: 1px solid #fecaca; padding: 0.5rem; border-radius: 6px; }

.modal-backdrop {
  position: fixed;
  inset: 0;
  background: rgba(15, 23, 42, 0.35);
  display: flex;
  align-items: center;
  justify-content: center;
  padding: 1rem;
  z-index: 20;
}

.modal {
  background: #fff;
  border-radius: 12px;
  padding: 1rem;
  width: min(420px, 100%);
  box-shadow: 0 20px 50px rgba(15, 23, 42, 0.2);
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
  border: 1px solid #cbd5e1;
  background: #fff;
  padding: 0.35rem 0.55rem;
  border-radius: 8px;
  cursor: pointer;
  min-height: 34px;
}
.lang-icon { width: 20px; height: 20px; }
.flag-icon { width: 24px; height: 18px; border-radius: 3px; border: 1px solid #e2e8f0; }
.lang-menu {
  position: absolute;
  right: 0;
  top: calc(100% + 6px);
  background: #fff;
  border: 1px solid #e5e7eb;
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
  color: #0f172a;
}
.lang-menu button.active span { font-weight: 700; }
.lang-menu button:hover { background: #f1f5f9; }
.lang-menu img { width: 24px; height: 18px; border-radius: 3px; border: 1px solid #e2e8f0; }

.auth-block { display: flex; align-items: center; gap: 0.5rem; }
.user-pill {
  display: flex;
  align-items: center;
  gap: 0.5rem;
  background: #e0e7ff;
  border: 1px solid #c7d2fe;
  padding: 0.35rem 0.65rem;
  border-radius: 999px;
}
.user-name { font-weight: 600; }

.loading-overlay { position: absolute; inset: 0; background: rgba(255,255,255,0.7); display: flex; align-items: center; justify-content: center; z-index: 15; border-radius: 10px; }
.loader-spinner { width: 48px; height: 48px; border: 4px solid #e5e7eb; border-top-color: #2563eb; border-radius: 50%; animation: spin 1s linear infinite; }
@keyframes spin { from { transform: rotate(0deg); } to { transform: rotate(360deg); } }

@media (max-width: 768px) {
  .brand-row { flex-wrap: nowrap; }
  .left-actions { width: auto; }
  .right-actions { width: auto; justify-content: flex-end; }
  .menu-toggle { display: inline-flex; flex-direction: column; align-items: center; justify-content: center; }
  .tabs { display: none; flex-direction: column; align-items: stretch; }
  .tabs.open { display: flex; }
  .tab-group-block { gap: 0.1rem; }
  .tab-group-label { width: 100%; border-radius: 6px 6px 0 0; background: #f1f5f9; margin-bottom: 0.1rem; padding: 0.22rem 0.7rem 0.12rem 0.7rem; }
  .tab-group-tabs { flex-direction: column; gap: 0.1rem; width: 100%; }
  .tab { width: 100%; border-radius: 0 0 6px 6px; padding: 0.32rem 0.7rem; font-size: 1rem; }
}

:global(.panel) .card {
  background: var(--app-card-bg, rgba(255,255,255,0.9));
  backdrop-filter: blur(6px);
  border: 1px solid rgba(226, 232, 240, 0.9);
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
