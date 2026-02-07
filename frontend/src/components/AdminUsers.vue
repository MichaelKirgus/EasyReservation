<script setup>
import { ref, reactive, computed, onMounted, onUnmounted } from 'vue'
import IconButton from './IconButton.vue'
import AdminDataTable from './AdminDataTable.vue'
import SecretField from './SecretField.vue'
import { buildAdminHeaders } from '../utils/adminApi'

import ResetPasswordDialog from './ResetPasswordDialog.vue'

const apiBase = import.meta.env.VITE_API_BASE || '/api'
const apiKey = ref(localStorage.getItem('admin_api_key') || '')
const users = ref([])
const loading = ref(false)
const message = ref('')
const error = ref('')
const currentUser = ref(JSON.parse(localStorage.getItem('admin_user') || 'null'))
const selectedUsers = ref([])
const lastAutoErrorAt = ref(0)
const showToken = reactive({})

const showResetPassword = ref(false)
const resetUser = ref(null)
const resetLoading = ref(false)

const userColumns = [
  { key: 'id', label: 'ID', sortable: true },
  { key: 'name', label: 'Name', sortable: true },
  { key: 'email', label: 'Email', sortable: true },
  { key: 'role', label: 'Rolle', sortable: true },
  { key: 'active', label: 'Aktiv', sortable: true },
  { key: 'api_token', label: 'Token', sortable: false },
]

const form = reactive({
  name: '',
  email: '',
  role: 'admin',
  active: true,
  password: '',
  api_token: '',
  api_token_is_hashed: false,
})

const isAdmin = computed(() => {
  const role = currentUser.value?.role?.toLowerCase?.() || '';
  return role === 'admin' || role === 'superadmin';
})

function setMessage(msg) {
  message.value = msg
  error.value = ''
}

function closeResetPasswordDialog() {
  showResetPassword.value = false;
  resetUser.value = null;
}

function setError(msg, opts = {}) {
  if (opts.auto) {
    const now = Date.now()
    if (now - lastAutoErrorAt.value < 30000) return
    lastAutoErrorAt.value = now
  }
  error.value = msg
  message.value = ''
}

const authHeaders = () => buildAdminHeaders({ apiKeyRef: apiKey, includeJson: true })

async function resetPassword(user, password) {
  resetLoading.value = true
  try {
    const res = await fetch(`${apiBase}/admin/users/${user.id}/reset-password`, {
      method: 'POST',
      headers: authHeaders(),
      body: JSON.stringify({ password })
    })
    if (!res.ok) throw new Error(await res.text())
    setMessage('Kennwort wurde geändert.')
    showResetPassword.value = false
    resetUser.value = null
  } catch (e) {
    setError(`Kennwort-Reset fehlgeschlagen: ${e}`)
  } finally {
    resetLoading.value = false
  }
}

function openResetPasswordDialog(row) {
  resetUser.value = { ...row };
  showResetPassword.value = true;
}

async function parseJsonSafe(res) {
  const text = await res.text()
  try {
    return JSON.parse(text)
  } catch {
    throw new Error(text || 'Ungültige Antwort vom Server')
  }
}

async function fetchUsers(opts = {}) {
  if (!apiKey.value) {
    setError('Bitte API-Key eintragen.', opts)
    return
  }
  loading.value = true
  try {
    const res = await fetch(`${apiBase}/admin/users`, { headers: authHeaders() })
    if (!res.ok) throw new Error(await res.text())
    users.value = await parseJsonSafe(res)
    localStorage.setItem('admin_api_key', apiKey.value)
    if (!opts.auto) setMessage('Benutzer geladen.')
  } catch (e) {
    setError(`Fehler beim Laden: ${e}`, opts)
  } finally {
    loading.value = false
  }
}

async function createUser() {
  try {
    const payload = { ...form }
    if (!payload.password) delete payload.password
    if (!payload.api_token) delete payload.api_token
    const res = await fetch(`${apiBase}/admin/users`, {
      method: 'POST',
      headers: authHeaders(),
      body: JSON.stringify(payload),
    })
    if (!res.ok) throw new Error(await res.text())
    const data = await parseJsonSafe(res)
    const newUser = { ...data.user }
    if (!newUser.api_token_is_hashed) {
      newUser.api_token = data.api_token
    }
    users.value.push(newUser)
    setMessage('Benutzer angelegt.')
    Object.assign(form, { name: '', email: '', role: 'admin', active: true, password: '', api_token: '', api_token_is_hashed: false })
  } catch (e) {
    setError(`Fehler beim Anlegen: ${e}`)
  }
}

async function updateUser(user) {
  try {
    const payload = { name: user.name, email: user.email, role: user.role, active: user.active }
    if (user.api_token !== undefined) {
      payload.api_token = user.api_token
      payload.api_token_is_hashed = false
    }
    const res = await fetch(`${apiBase}/admin/users/${user.id}`, {
      method: 'PATCH',
      headers: authHeaders(),
      body: JSON.stringify(payload),
    })
    if (!res.ok) throw new Error(await res.text())
    await parseJsonSafe(res)
    setMessage('Benutzer aktualisiert.')
  } catch (e) {
    setError(`Fehler beim Speichern: ${e}`)
  }
}

async function rotateToken(user, hash = false) {
  try {
    const res = await fetch(`${apiBase}/admin/users/${user.id}/rotate-token`, {
      method: 'POST',
      headers: authHeaders(),
      body: JSON.stringify({ hash_token: hash }),
    })
    if (!res.ok) throw new Error(await res.text())
    const data = await parseJsonSafe(res)
    if (!hash) {
      user.api_token = data.api_token
      user.api_token_is_hashed = false
    } else {
      user.api_token = undefined
      user.api_token_is_hashed = true
    }
    setMessage('Token neu generiert.')
  } catch (e) {
    setError(`Fehler beim Token-Reset: ${e}`)
  }
}

async function deleteUser(user) {
  if (!confirm(`Benutzer "${user.name}" löschen?`)) return
  try {
    const res = await fetch(`${apiBase}/admin/users/${user.id}`, {
      method: 'DELETE',
      headers: authHeaders(),
    })
    const text = await res.text()
    if (!res.ok) throw new Error(text)
    users.value = users.value.filter((u) => u.id !== user.id)
    selectedUsers.value = selectedUsers.value.filter(id => id !== user.id)
    setMessage('Benutzer gelöscht.')
  } catch (e) {
    setError(`Löschen fehlgeschlagen: ${e}`)
  }
}

async function bulkDeleteUsers() {
  if (!selectedUsers.value.length) return
  if (!confirm(`Ausgewählte Benutzer (${selectedUsers.value.length}) löschen?`)) return
  try {
    for (const id of selectedUsers.value) {
      const user = users.value.find(u => u.id === id)
      if (!user) continue
      const res = await fetch(`${apiBase}/admin/users/${id}`, { method: 'DELETE', headers: authHeaders() })
      const text = await res.text()
      if (!res.ok) throw new Error(text)
    }
    users.value = users.value.filter(u => !selectedUsers.value.includes(u.id))
    selectedUsers.value = []
    setMessage('Ausgewählte Benutzer gelöscht.')
  } catch (e) {
    setError(`Löschen fehlgeschlagen: ${e}`)
  }
}

function handleKeyUpdate(e) {
  apiKey.value = e.detail || ''
  const storedUser = localStorage.getItem('admin_user')
  if (storedUser) {
    try { currentUser.value = JSON.parse(storedUser) } catch (_) { currentUser.value = null }
  }
  if (apiKey.value) {
    fetchUsers()
  }
}

async function enable2FA(user) {
  if (!confirm(`2FA für ${user.name} aktivieren?`)) return;
  loading.value = true;
  try {
    const res = await fetch(`${apiBase}/admin/users/${user.id}/2fa/enable`, { method: 'POST', headers: authHeaders() });
    if (!res.ok) throw new Error(await res.text());
    setMessage('2FA aktiviert.');
    await fetchUsers();
  } catch (e) {
    setError(`2FA-Aktivierung fehlgeschlagen: ${e}`);
  } finally {
    loading.value = false;
  }
}

async function disable2FA(user) {
  if (!confirm(`2FA für ${user.name} deaktivieren?`)) return;
  loading.value = true;
  try {
    const res = await fetch(`${apiBase}/admin/users/${user.id}/2fa/disable`, { method: 'POST', headers: authHeaders() });
    if (!res.ok) throw new Error(await res.text());
    setMessage('2FA deaktiviert.');
    await fetchUsers();
  } catch (e) {
    setError(`2FA-Deaktivierung fehlgeschlagen: ${e}`);
  } finally {
    loading.value = false;
  }
}

async function reset2FA(user) {
  if (!confirm(`2FA für ${user.name} zurücksetzen?`)) return;
  loading.value = true;
  try {
    const res = await fetch(`${apiBase}/admin/users/${user.id}/2fa/reset`, { method: 'POST', headers: authHeaders() });
    if (!res.ok) throw new Error(await res.text());
    setMessage('2FA zurückgesetzt.');
    await fetchUsers();
  } catch (e) {
    setError(`2FA-Reset fehlgeschlagen: ${e}`);
  } finally {
    loading.value = false;
  }
}


// 2FA Self-Management: Routen ohne /api, mit credentials: 'include'
async function fetchSelf2FAStatus() {
  try {
    const res = await fetch(`${apiBase}/self-2fa/status`, { headers: authHeaders(), credentials: 'include' })
    if (!res.ok) return
    const data = await parseJsonSafe(res)
    self2FA.value.enabled = !!data.two_factor_enabled
  } catch {}
}

async function enableSelf2FA() {
  self2FA.value.error = ''
  self2FA.value.message = ''
  try {
    const res = await fetch(`${apiBase}/self-2fa/enable`, { method: 'POST', headers: authHeaders(), credentials: 'include' })
    if (!res.ok) throw new Error(await res.text())
    await fetchSelf2FAStatus()
    await fetchSelf2FAQr()
    self2FA.value.showQr = true
    self2FA.value.message = 'Bitte QR-Code scannen und OTP eingeben.'
  } catch (e) {
    self2FA.value.error = `Aktivierung fehlgeschlagen: ${e}`
  }
}

async function disableSelf2FA() {
  self2FA.value.error = ''
  self2FA.value.message = ''
  try {
    const res = await fetch(`${apiBase}/self-2fa/disable`, { method: 'DELETE', headers: authHeaders(), credentials: 'include' })
    if (!res.ok) throw new Error(await res.text())
    await fetchSelf2FAStatus()
    self2FA.value.qr = ''
    self2FA.value.recovery = []
    self2FA.value.showQr = false
    self2FA.value.showRecovery = false
    self2FA.value.message = '2FA deaktiviert.'
  } catch (e) {
    self2FA.value.error = `Deaktivierung fehlgeschlagen: ${e}`
  }
}

async function fetchSelf2FAQr() {
  try {
    const res = await fetch(`${apiBase}/self-2fa/qr`, { headers: authHeaders(), credentials: 'include' })
    if (!res.ok) throw new Error(await res.text())
    const data = await parseJsonSafe(res)
    self2FA.value.qr = data.svg || ''
    self2FA.value.secret = data.secret || ''
  } catch (e) {
    self2FA.value.error = `QR-Code konnte nicht geladen werden: ${e}`
  }
}

async function fetchSelf2FARecovery() {
  try {
    const res = await fetch(`${apiBase}/self-2fa/recovery`, { headers: authHeaders(), credentials: 'include' })
    if (!res.ok) throw new Error(await res.text())
    const data = await parseJsonSafe(res)
    self2FA.value.recovery = data
    self2FA.value.showRecovery = true
  } catch (e) {
    self2FA.value.error = `Recovery-Codes konnten nicht geladen werden: ${e}`
  }
}

async function confirmSelf2FA() {
  self2FA.value.error = ''
  self2FA.value.message = ''
  try {
    const res = await fetch(`${apiBase}/self-2fa/confirm`, {
      method: 'POST',
      headers: authHeaders(),
      credentials: 'include',
      body: JSON.stringify({ code: self2FA.value.otp })
    })
    if (!res.ok) throw new Error(await res.text())
    self2FA.value.message = 'OTP bestätigt. 2FA ist jetzt aktiv.'
    self2FA.value.otp = ''
    await fetchSelf2FAStatus()
    await fetchSelf2FARecovery()
  } catch (e) {
    self2FA.value.error = `OTP-Überprüfung fehlgeschlagen: ${e}`
  }
}

onMounted(() => {
  if (apiKey.value) {
      fetchUsers()
      fetchSelf2FAStatus()
  }
  window.addEventListener('api-key-updated', handleKeyUpdate)
})

onUnmounted(() => {
  window.removeEventListener('api-key-updated', handleKeyUpdate)
})
</script>

<template>
  <div class="stack">
    <div v-if="message" class="message">{{ message }}</div>
    <div v-if="error" class="error">{{ error }}</div>


    <section class="card">
      <h3>Neuen Benutzer anlegen</h3>
      <div class="grid">
        <label>Name<input v-model="form.name" /></label>
        <label>E-Mail<input v-model="form.email" /></label>
        <label>Rolle
          <select v-model="form.role">
            <option value="superadmin">SuperAdmin</option>
            <option value="admin">Admin</option>
            <option value="moderator">Moderator</option>
            <option value="user">User</option>
            <option value="guest">Gast</option>
          </select>
        </label>
        <label class="inline">Aktiv<input type="checkbox" v-model="form.active" /></label>
        <label class="full">Passwort (optional)<input v-model="form.password" type="password" /></label>
        <label class="full">API-Token (optional)
          <SecretField v-model="form.api_token" />
        </label>
      </div>
      <IconButton icon="plus" label="Anlegen" @click="createUser" :disabled="loading" />
    </section>

    <section class="card">
      <h3>Benutzer</h3>
      <AdminDataTable
        :columns="userColumns"
        :rows="users"
        v-model="selectedUsers"
        selectable
        :loading="loading"
        :page-size="20"
        persist-key="admin-users"
        empty-text="Keine Benutzer."
        @refresh="fetchUsers"
        @auto-refresh="fetchUsers({ auto: true })"
      >
        <template #actions>
          <IconButton icon="trash" variant="danger" label="Auswahl löschen" @click="bulkDeleteUsers" :disabled="loading || !selectedUsers.length" />
        </template>
        <template #cell-name="{ row }">
          <input v-model="row.name" @change="updateUser(row)" />
        </template>
        <template #cell-email="{ row }">
          <input v-model="row.email" @change="updateUser(row)" />
        </template>
        <template #cell-role="{ row }">
          <select v-model="row.role" @change="updateUser(row)">
            <option value="superadmin">SuperAdmin</option>
            <option value="admin">Admin</option>
            <option value="moderator">Moderator</option>
            <option value="user">User</option>
            <option value="guest">Gast</option>
          </select>
        </template>
        <template #cell-active="{ row }">
          <input type="checkbox" v-model="row.active" @change="updateUser(row)" />
        </template>
        <template #cell-api_token="{ row }">
          <div class="token-cell">
            <SecretField
              v-if="isAdmin && row.api_token && !row.api_token_is_hashed"
              v-model="row.api_token"
              :show="row.id ? showToken[row.id] : undefined"
              @update:show="val => { if (row.id) showToken[row.id] = val }"
              @change="updateUser(row)"
              placeholder="Token"
            />
            <span v-else>{{ row.api_token_is_hashed ? 'gehasht' : '–' }}</span>
          </div>
        </template>
        <template #row-actions="{ row }">
          <IconButton icon="key" label="Token neu" @click="rotateToken(row, false)" :disabled="loading" />
          <IconButton icon="key" label="Token neu (gehasht)" @click="rotateToken(row, true)" :disabled="loading" />
          <IconButton icon="shield" label="2FA aktivieren" @click="enable2FA(row)" :disabled="loading || row.two_factor_secret || row.role === 'guest'" />
          <IconButton icon="lock" label="Kennwort setzen" @click="openResetPasswordDialog(row)" :disabled="loading || row.role === 'guest'" />
          <IconButton icon="close" label="2FA deaktivieren" @click="disable2FA(row)" :disabled="loading || !row.two_factor_secret || row.role === 'guest'" />
          <IconButton icon="refresh" label="2FA zurücksetzen" @click="reset2FA(row)" :disabled="loading || !row.two_factor_secret || row.role === 'guest'" />
          <IconButton variant="danger" icon="trash" label="Löschen" @click="deleteUser(row)" :disabled="loading" />
        </template>
      </AdminDataTable>

      <ResetPasswordDialog
        v-if="showResetPassword && resetUser"
        :user="resetUser"
        :loading="resetLoading"
        @submit="pwd => resetPassword(resetUser, pwd)"
        @close="closeResetPasswordDialog"
      />
    </section>
  </div>
</template>

<style scoped>
.stack { display: flex; flex-direction: column; gap: 0.75rem; }
.controls { display: flex; gap: 0.75rem; flex-wrap: wrap; align-items: end; }
.card { border: 1px solid #e5e7eb; border-radius: 8px; padding: 1rem; background: #fff; }
.grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); gap: 0.75rem; }
label { display: flex; flex-direction: column; gap: 0.25rem; font-weight: 600; }
label.inline { flex-direction: row; align-items: center; gap: 0.5rem; }
label.full { grid-column: 1 / -1; }
input, select, button { font: inherit; padding: 0.5rem; border: 1px solid #d1d5db; border-radius: 6px; }
button { background: #2563eb; color: #fff; cursor: pointer; }
button.danger { background: #dc2626; }
button:disabled { opacity: 0.6; cursor: not-allowed; }
.message { color: #065f46; background: #ecfdf3; border: 1px solid #a7f3d0; padding: 0.5rem; border-radius: 6px; }
.error { color: #991b1b; background: #fef2f2; border: 1px solid #fecaca; padding: 0.5rem; border-radius: 6px; }
.actions { display: flex; flex-wrap: wrap; gap: 0.25rem; }
.token-cell { display: flex; gap: 0.35rem; align-items: center; }
.table-wrapper { overflow-x: auto; }
</style>
