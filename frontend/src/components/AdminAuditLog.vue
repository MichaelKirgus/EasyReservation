<script setup>
import { ref, computed, onMounted } from 'vue'
import AdminDataTable from './AdminDataTable.vue'
import IconButton from './IconButton.vue'
import { buildAdminHeaders } from '../utils/adminApi'
import { useTranslation } from '../composables/useTranslation'

const { tr } = useTranslation()

const apiBase = import.meta.env.VITE_API_BASE || '/api'
const apiKey = ref(localStorage.getItem('admin_auth_session') || sessionStorage.getItem('admin_auth_session') || '')
const currentUser = ref(JSON.parse(localStorage.getItem('admin_user') || 'null'))
const logs = ref([])
const loading = ref(false)
const error = ref('')
const message = ref('')

const isSuperAdmin = computed(() => currentUser.value?.role === 'superadmin')

// Columns - defined as computed to ensure translations are loaded
const columns = computed(() => [
  { key: 'id', label: tr('admin_audit_log_column_id'), sortable: true },
  { key: 'user_id', label: tr('admin_audit_log_column_user_id'), sortable: true },
  { key: 'route', label: tr('admin_audit_log_column_route'), sortable: true },
  { key: 'method', label: tr('admin_audit_log_column_method'), sortable: true },
  { key: 'payload', label: tr('admin_audit_log_column_payload'), sortable: false },
  { key: 'created_at', label: tr('admin_audit_log_column_timestamp'), sortable: true },
])

const authHeaders = () => buildAdminHeaders({ apiKeyRef: apiKey, includeJson: true })

async function fetchLogs() {
  if (!isSuperAdmin.value) return
  loading.value = true
  error.value = ''
  try {
    const res = await fetch(`${apiBase}/audit-logs`, { headers: authHeaders() })
    if (!res.ok) throw new Error(await res.text())
    logs.value = await res.json()
    message.value = tr('admin_audit_log_loaded')
  } catch (e) {
    error.value = tr('admin_audit_log_load_error') + ': ' + e
  } finally {
    loading.value = false
  }
}

async function clearLogs() {
  if (!isSuperAdmin.value) return
  if (!confirm(tr('really_delete_audit_log'))) return
  loading.value = true
  error.value = ''
  try {
    const res = await fetch(`${apiBase}/audit-logs`, { method: 'DELETE', headers: authHeaders() })
    if (!res.ok) throw new Error(await res.text())
    logs.value = []
    message.value = tr('admin_audit_log_cleared')
  } catch (e) {
    error.value = tr('admin_audit_log_delete_error') + ': ' + e
  } finally {
    loading.value = false
  }
}

onMounted(() => {
  fetchLogs()
})
</script>

<template>
  <div class="stack">
    <div v-if="message" class="message">{{ message }}</div>
    <div v-if="error" class="error">{{ error }}</div>
    <section class="card">
      <h3>{{ tr('admin_audit_log_title') }}</h3>
      <AdminDataTable
        :columns="columns"
        :rows="logs"
        :loading="loading"
        :page-size="20"
        persist-key="admin-audit-log"
        :empty-text="tr('admin_audit_log_empty_text')"
        @refresh="fetchLogs"
      >
        <template #actions>
          <IconButton icon="trash" variant="danger" :label="tr('admin_audit_log_button_clear')" @click="clearLogs" :disabled="loading || !logs.length" />
        </template>
        <template #cell-payload="{ row }">
          <pre style="white-space:pre-wrap;word-break:break-word;max-width:400px;">{{ row.payload }}</pre>
        </template>
      </AdminDataTable>
    </section>
  </div>
</template>

<style scoped>
.stack { display: flex; flex-direction: column; gap: 0.75rem; }
.card { border: 1px solid #e5e7eb; border-radius: 8px; padding: 1rem; background: #fff; }
.message { color: #065f46; background: #ecfdf3; border: 1px solid #a7f3d0; padding: 0.5rem; border-radius: 6px; }
.error { color: #991b1b; background: #fef2f2; border: 1px solid #fecaca; padding: 0.5rem; border-radius: 6px; }
</style>
