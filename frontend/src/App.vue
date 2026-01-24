<script setup>
import { ref, reactive, onMounted, computed, watch, onUnmounted } from 'vue'
import PublicReservation from './components/PublicReservation.vue'
import PublicFaq from './components/PublicFaq.vue'
import AdminReservations from './components/AdminReservations.vue'
import AdminSettings from './components/AdminSettings.vue'
import FormFieldManager from './components/FormFieldManager.vue'
import AdminUsers from './components/AdminUsers.vue'
import AdminEmailBroadcast from './components/AdminEmailBroadcast.vue'
import AdminFaq from './components/AdminFaq.vue'
import AdminEvents from './components/AdminEvents.vue'
import AdminScheduledTasks from './components/AdminScheduledTasks.vue'
import AdminDiagnostics from './components/AdminDiagnostics.vue'
import AdminCustomPlaceholders from './components/AdminCustomPlaceholders.vue'
import AdminAuditLog from './components/AdminAuditLog.vue'
import languageIconSrc from './assets/icons/languageicon.svg'
import flagDe from './assets/icons/flag-de.svg'
import flagUs from './assets/icons/flag-us.svg'
import IconButton from './components/IconButton.vue'
import PublicPrivacy from './components/PublicPrivacy.vue'

const apiBase = import.meta.env.VITE_API_BASE || '/api'
const active = ref('public')
const selectedLang = ref('de')
const langMenuOpen = ref(false)
const showMobileMenu = ref(false)
const openGroups = ref({})
const globalLoading = ref(false)
const privacyEnabled = ref(false)

const tabGroups = computed(() => [
  {
    id: 'public',
    label: 'Öffentlich',
    roles: ['guest', 'admin','superadmin', 'moderator', 'user'],
    tabs: [
      { key: 'public', label: 'Reservierung', roles: ['guest', 'admin', 'superadmin', 'moderator', 'user'] },
      { key: 'public-faq', label: 'FAQ', roles: ['guest', 'admin', 'superadmin', 'moderator', 'user'] },
      ...(privacyEnabled.value ? [{ key: 'public-privacy', label: 'Datenschutz', roles: ['guest', 'admin', 'superadmin', 'moderator', 'user'] }] : []),
    ],
  },
  {
    id: 'moderation',
    label: 'Moderation',
    roles: ['superadmin', 'admin', 'moderator'],
    tabs: [
      { key: 'admin-reservations', label: 'Reservierungen', roles: ['superadmin', 'admin', 'moderator'] },
      { key: 'admin-email', label: 'E-Mail', roles: ['superadmin', 'admin', 'moderator'] },
      { key: 'admin-faq', label: 'FAQ', roles: ['superadmin', 'admin', 'moderator'] },
      { key: 'admin-events', label: 'Termine', roles: ['superadmin', 'admin', 'moderator'] },
    ],
  },
  {
    id: 'administration',
    label: 'Administration',
    roles: ['superadmin', 'admin'],
    tabs: [
      { key: 'admin-diagnostics', label: 'Diagnose', roles: ['superadmin', 'admin'] },
      { key: 'admin-settings', label: 'Einstellungen', roles: ['superadmin', 'admin'] },
      { key: 'admin-scheduled-tasks', label: 'Geplante Aufgaben', roles: ['superadmin', 'admin'] },
      { key: 'admin-custom-placeholders', label: 'Platzhalter', roles: ['superadmin', 'admin'] },
      { key: 'admin-form-fields', label: 'Formularfelder', roles: ['superadmin', 'admin'] },
      { key: 'admin-users', label: 'Benutzer', roles: ['superadmin', 'admin'] },
      { key: 'admin-auditlog', label: 'Audit-Log', roles: ['superadmin'] },
    ],
  },
])

const loginForm = reactive({ identifier: '', password: '' })
const showOtp = ref(false)
const authMessage = ref('')
const authError = ref('')
const loadingAuth = ref(false)
const showLogin = ref(false)
const currentUser = ref(null)
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
      currentUser.value = data.user
      storage.setItem('admin_user', JSON.stringify(data.user))
      otherStorage.removeItem('admin_user')
    }
    window.dispatchEvent(new CustomEvent('api-key-updated', { detail: token }))
    setAuthMessage(`Angemeldet als ${data.user?.name || ''} (${data.user?.role || ''}).`)
    loginForm.password = ''
    showLogin.value = false
    showOtp.value = false
    ensureActiveTab()
  } catch (e) {
    setAuthError(`Login fehlgeschlagen: ${e}`)
  } finally {
    loadingAuth.value = false
  }
}

function logout() {
  localStorage.removeItem('admin_api_key')
  localStorage.removeItem('admin_user')
  sessionStorage.removeItem('admin_api_key')
  sessionStorage.removeItem('admin_user')
  currentUser.value = null
  window.dispatchEvent(new CustomEvent('api-key-updated', { detail: '' }))
  setAuthMessage('Abgemeldet.')
  ensureActiveTab()
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

const role = computed(() => currentUser.value?.role || 'guest')

const visibleGroups = computed(() => {
  return tabGroups.value
    .filter(g => g.roles.includes(role.value))
    .map(g => ({
      ...g,
      tabs: g.tabs.filter(t => t.roles.includes(role.value)),
    }))
    .filter(g => g.tabs.length > 0)
})

const visibleTabKeys = computed(() => visibleGroups.value.flatMap(g => g.tabs.map(t => t.key)))

function firstVisibleTab() {
  return visibleTabKeys.value[0] || 'public'
}

function tabKeyFromHash() {
  const hash = window.location.hash.replace(/^#/, '') || ''
  if (!hash) return null
  if (hash === 'faq') return 'public-faq'
  return hash
}

function hashFromTabKey(key) {
  if (!key || key === 'public') return ''
  if (key === 'public-faq') return 'faq'
  return key
}

function updateHashFromActive() {
  const slug = hashFromTabKey(active.value)
  const newHash = slug ? `#${slug}` : ''
  const target = `${location.pathname}${location.search}${newHash}`
  const current = `${location.pathname}${location.search}${location.hash}`
  if (target !== current) {
    history.replaceState(null, '', target)
  }
}

function ensureActiveTab() {
  if (!visibleTabKeys.value.includes(active.value)) {
    active.value = firstVisibleTab()
  }
  // leave groups closed by default
}

function syncActiveFromHash() {
  const target = tabKeyFromHash()
  if (target && visibleTabKeys.value.includes(target)) {
    active.value = target
  } else {
    ensureActiveTab()
  }
}

function isGroupOpen(id) {
  return openGroups.value[id] === true;
}

function closeAllGroups() {
  openGroups.value = {};
}

function toggleGroup(id) {
  const willOpen = !isGroupOpen(id);
  const nextState = {};
  visibleGroups.value.forEach(g => {
    nextState[g.id] = willOpen && g.id === id;
  });
  openGroups.value = nextState;
}

function openGroupForActive() {
  const targetGroup = visibleGroups.value.find(g => g.tabs.some(t => t.key === active.value))
  const nextState = {}
  visibleGroups.value.forEach(g => {
    nextState[g.id] = targetGroup ? g.id === targetGroup.id : false
  })
  openGroups.value = nextState
}

watch(visibleTabKeys, ensureActiveTab)
watch(active, () => {
  // close dropdowns after tab change
  closeAllGroups()
  updateHashFromActive()
})

function handleHashChange() {
  syncActiveFromHash()
}

onMounted(async () => {
  const storedUser = localStorage.getItem('admin_user') || sessionStorage.getItem('admin_user')
  if (storedUser) {
    try { currentUser.value = JSON.parse(storedUser) } catch (_) { /* ignore */ }
  }
  window.addEventListener('switch-tab', handleSwitchTab)
  window.addEventListener('loading-start', handleLoadingStart)
  window.addEventListener('loading-end', handleLoadingEnd)
  window.addEventListener('hashchange', handleHashChange)
  window.addEventListener('settings-updated', fetchPrivacyEnabled)
  syncActiveFromHash()
  ensureActiveTab()
  fetchPrivacyEnabled()
})

onUnmounted(() => {
  window.removeEventListener('switch-tab', handleSwitchTab)
  window.removeEventListener('loading-start', handleLoadingStart)
  window.removeEventListener('loading-end', handleLoadingEnd)
  window.removeEventListener('hashchange', handleHashChange)
  window.removeEventListener('settings-updated', fetchPrivacyEnabled)
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
    const siteToken = localStorage.getItem('site_token') || '';
    const headers = { 'Accept': 'application/json' };
    if (siteToken) headers['X-Site-Token'] = siteToken;
    const res = await fetch(`${apiBase}/privacy-policy`, { headers });
    privacyEnabled.value = res.ok
  } catch { privacyEnabled.value = false }
}
</script>

<template>
  <main class="page">
    <header class="topbar">
      <div class="brand-row">
        <div class="left-actions">
          <button
            class="menu-toggle"
            @click="() => { showMobileMenu = !showMobileMenu; if (showMobileMenu) { openGroupForActive(); } else { closeAllGroups(); } }"
            aria-label="Menü umschalten"
          >
            <span></span><span></span><span></span>
          </button>
          <nav class="tabs" :class="{ open: showMobileMenu }">
            <div v-for="group in visibleGroups" :key="group.id" class="tab-group" :class="{ open: isGroupOpen(group.id) }">
              <button class="tab-group-label" @click="toggleGroup(group.id)">{{ group.label }}</button>
              <div v-if="isGroupOpen(group.id)" class="tab-group-tabs">
                <button
                  v-for="tab in group.tabs"
                  :key="tab.key"
                  :class="['tab', { active: active === tab.key } ]"
                  @click="() => { active = tab.key; showMobileMenu = false; closeAllGroups(); }"
                >
                  {{ tab.label }}
                </button>
              </div>
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
            <IconButton icon="login" label="Anmelden" class="ghost" size="sm" @click="showLogin = true" v-if="!currentUser" />
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
      <PublicReservation v-if="active === 'public'" :lang-code="selectedLang" />
      <PublicFaq v-else-if="active === 'public-faq'" :lang-code="selectedLang" />
      <PublicPrivacy v-else-if="active === 'public-privacy'" :lang-code="selectedLang" />
      <AdminReservations v-else-if="active === 'admin-reservations'" />
      <AdminEmailBroadcast v-else-if="active === 'admin-email'" />
      <AdminFaq v-else-if="active === 'admin-faq'" />
      <AdminEvents v-else-if="active === 'admin-events'" />
      <AdminDiagnostics v-else-if="active === 'admin-diagnostics'" />
      <AdminSettings v-else-if="active === 'admin-settings'" :lang-code="selectedLang" />
      <AdminScheduledTasks v-else-if="active === 'admin-scheduled-tasks'" />
      <FormFieldManager v-else-if="active === 'admin-form-fields'" :lang-code="selectedLang" />
      <AdminUsers v-else-if="active === 'admin-users'" />
      <AdminCustomPlaceholders v-else-if="active === 'admin-custom-placeholders'" />
      <AdminAuditLog v-else-if="active === 'admin-auditlog'" />
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
  flex-wrap: wrap;
  gap: 0.4rem;
  align-items: center;
}

.tab-group {
  position: relative;
  display: flex;
  flex-direction: column;
  align-items: flex-start;
}

.tab-group-label {
  font-weight: 700;
  color: #0f172a;
  padding: 0.35rem 0.55rem;
  border: 1px solid #d1d5db;
  background: #f8fafc;
  border-radius: 6px;
  text-align: left;
  cursor: pointer;
  min-width: 140px;
}

.tab-group.open .tab-group-label { background: #e0e7ff; border-color: #c7d2fe; }

.tab-group-tabs {
  position: absolute;
  top: calc(100% + 4px);
  left: 0;
  min-width: 180px;
  background: #fff;
  border: 1px solid #d1d5db;
  border-radius: 8px;
  box-shadow: 0 6px 18px rgba(15, 23, 42, 0.12);
  padding: 0.4rem;
  display: flex;
  flex-direction: column;
  gap: 0.25rem;
  z-index: 40;
}

.tab {
  border: 1px solid #d1d5db;
  background: #fff;
  padding: 0.35rem 0.55rem;
  border-radius: 6px;
  cursor: pointer;
  transition: all 0.15s ease;
  color: #0f172a;
  text-align: left;
  width: 100%;
}

.tab.active {
  background: #2563eb;
  color: #fff;
  border-color: #2563eb;
  box-shadow: 0 2px 6px rgba(37, 99, 235, 0.25);
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
  .tab-group { width: 100%; }
  .tab-group-label { width: 100%; }
  .tab-group-tabs {
    position: static;
    width: 100%;
    box-shadow: none;
    border: 1px solid #d1d5db;
    margin-top: 0.25rem;
  }
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
