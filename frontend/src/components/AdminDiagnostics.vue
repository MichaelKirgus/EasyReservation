<script setup>
import { ref, onMounted, onUnmounted, watch, computed } from 'vue'
import { adminFetch } from '../utils/adminApi'
import { useTranslation } from '../composables/useTranslation'

const { tr } = useTranslation()

// Client-Zeit und Zeitzone
const clientTime = ref(new Date().toISOString())
const clientTimezone = ref(Intl.DateTimeFormat().resolvedOptions().timeZone)

let clientTimer = null
function updateClientTime() {
  clientTime.value = new Date().toISOString()
}
import IconButton from './IconButton.vue'
import AdminDataTable from './AdminDataTable.vue'

// Worker-Status: dedicated endpoint for reliability
const workerStatus = ref([])
const workerLoading = ref(false)
const workerDriver = ref('')
const workerTtl = ref(0)
const workerColumns = [
  { key: 'worker_id', label: 'Worker-ID', sortable: true },
  { key: 'status', label: 'Status', sortable: true },
  { key: 'ip', label: 'IP', sortable: true },
  { key: 'last_heartbeat_at', label: 'Letzter Heartbeat', sortable: true },
  { key: 'memory_mb', label: 'Speicher (MB)', sortable: true },
  { key: 'redis_latency_ms', label: 'Redis-Latenz (ms)', sortable: true },
  { key: 'db_latency_ms', label: 'DB-Latenz (ms)', sortable: true },
  { key: 'total_jobs', label: 'Jobs gesamt', sortable: true },
  { key: 'last_job_at', label: 'Letzter Job', sortable: true },
  { key: 'last_job_duration_ms', label: 'Dauer (ms)', sortable: true },
  { key: 'active_jobs', label: 'Aktive Jobs' },
]



const apiKey = ref(localStorage.getItem('admin_auth_session') || sessionStorage.getItem('admin_auth_session') || '')
const routePrefix = ref('admin')
const loading = ref(false)
const message = ref('')
const error = ref('')
const diagnostics = ref(null)
const frontendVersion = typeof __APP_VERSION__ !== 'undefined' ? __APP_VERSION__ : 'unbekannt'
const autoRefreshEnabled = ref(Boolean(localStorage.getItem('admin_diag_autorefresh') === '1'));
const refreshMs = 2000
let timerId = null
const lastAutoErrorAt = ref(0)

const auditLogEnabled = import.meta.env.VITE_AUDIT_LOG !== 'FALSE'
const auditLogCount = ref(null)
const auditLogLoading = ref(false)
const auditLogError = ref('')

const jobColumns = [
  { key: 'finished_at', label: 'Fertig', sortable: true },
  { key: 'job', label: 'Job', sortable: true },
  { key: 'queue', label: 'Queue', sortable: true },
  { key: 'status', label: 'Status', sortable: true },
  { key: 'runtime_ms', label: 'Dauer', sortable: true },
  { key: 'message', label: 'Nachricht', sortable: false },
]

function setMessage(msg) { message.value = msg; error.value = '' }
function setError(msg, opts = {}) {
  if (opts.auto) {
    const now = Date.now()
    if (now - lastAutoErrorAt.value < 30000) return
    lastAutoErrorAt.value = now
  }
  error.value = msg; message.value = ''
}

const fetchWithAuth = (relative, opts = {}) => adminFetch(relative, opts, { apiKeyRef: apiKey, routePrefixRef: routePrefix })

async function loadDiagnostics(opts = {}) {
  if (!apiKey.value) { setError(tr('please_login_api_key_missing'), opts); return }
  loading.value = true
  try {
    const res = await fetchWithAuth('diagnostics')
    const text = await res.text()
    if (!res.ok) throw new Error(text)
    diagnostics.value = JSON.parse(text)
    if (!opts.auto) setMessage(tr(''))
  } catch (e) {
    setError(tr('error_loading') + ': ' + e, opts)
  } finally {
    loading.value = false
  }
}

async function loadAuditLogCount() {
  auditLogLoading.value = true
  auditLogError.value = ''
  try {
    const res = await fetchWithAuth('audit-log/count')
    if (!res.ok) throw new Error('Fehler beim Laden der Audit-Log-Anzahl')
    const data = await res.json()
    auditLogCount.value = data.count
  } catch (e) {
    auditLogError.value = e.message || String(e)
  } finally {
    auditLogLoading.value = false
  }
}

async function loadWorkerStats(opts = {}) {
  workerLoading.value = true
  try {
    const res = await fetchWithAuth('worker-stats')
    if (!res.ok) throw new Error(await res.text())
    const data = await res.json()
    workerStatus.value = data.workers || []
    workerDriver.value = data.driver || ''
    workerTtl.value = data.ttl || 0
  } catch (e) {
    if (!opts.auto) setError('Worker-Stats: ' + e)
  } finally {
    workerLoading.value = false
  }
}

function startAutoRefresh() {
  stopAutoRefresh()
  if (!autoRefreshEnabled.value) return
  timerId = setInterval(() => {
    loadDiagnostics({ auto: true })
    loadAuditLogCount()
    loadWorkerStats({ auto: true })
  }, refreshMs)
}

function stopAutoRefresh() {
  if (timerId) {
    clearInterval(timerId)
    timerId = null
  }
}

function formatDateTime(val) {
  if (!val) return '–'
  const d = new Date(val)
  if (Number.isNaN(d.getTime())) return val
  return new Intl.DateTimeFormat('de-DE', { day: '2-digit', month: '2-digit', year: 'numeric', hour: '2-digit', minute: '2-digit', second: '2-digit' }).format(d)
}

function statusClass(status) {
  if (!status) return ''
  return status === 'ok' ? 'status-ok' : 'status-failed'
}

function latencyLabel(entry) {
  if (!entry) return '–'
  const latency = typeof entry.latency_ms === 'number' ? `${entry.latency_ms} ms` : '–'
  if (entry.status === 'ok') return latency
  return `${latency} (Fehler)`
}

function handleKeyUpdate(e) {
  apiKey.value = e.detail || ''
}

watch(autoRefreshEnabled, (val) => {
  localStorage.setItem('admin_diag_autorefresh', val ? '1' : '0')
  if (val) {
    loadDiagnostics({ auto: true })
    loadAuditLogCount()
    loadWorkerStats({ auto: true })
    startAutoRefresh()
  } else {
    stopAutoRefresh()
  }
})

onMounted(() => {
  window.addEventListener('api-key-updated', handleKeyUpdate)
  if (apiKey.value) {
    loadDiagnostics()
    loadAuditLogCount()
    loadWorkerStats()
    if (autoRefreshEnabled.value) startAutoRefresh()
  }
})

onUnmounted(() => {
  window.removeEventListener('api-key-updated', handleKeyUpdate)
  stopAutoRefresh()
})
</script>

<template>
  <div class="stack">
    <div class="top-bar">
      <h3>Diagnose</h3>
      <div class="actions">
        <IconButton
          icon="repeat"
          size="sm"
          :variant="autoRefreshEnabled ? 'primary' : 'ghost'"
          :aria-pressed="autoRefreshEnabled"
          label="Automatisch aktualisieren (2s)"
          @click="autoRefreshEnabled = !autoRefreshEnabled"
        />
        <IconButton icon="refresh" size="sm" label="Aktualisieren" @click="loadDiagnostics" :disabled="loading" />
      </div>
    </div>

    <div v-if="message" class="message">{{ message }}</div>
    <div v-if="error" class="error">{{ error }}</div>

    <div class="card">
      <div class="card-header">
        <h4>System</h4>
        <span class="muted" v-if="diagnostics?.timestamp">Stand: {{ formatDateTime(diagnostics.timestamp) }}</span>
      </div>
      <div class="info-grid" v-if="diagnostics?.app">
        <div class="info-item">
          <div class="label">App</div>
          <div class="value">{{ diagnostics.app.name }} ({{ diagnostics.app.environment }})</div>
        </div>
        <div class="info-item">
          <div class="label">Frontend-Version</div>
          <div class="value">{{ frontendVersion }}</div>
        </div>
        <div class="info-item">
          <div class="label">Backend-Version</div>
          <div class="value">{{ diagnostics.app.app_version }}</div>
        </div>
        <div class="info-item">
          <div class="label">PHP</div>
          <div class="value">{{ diagnostics.app.php_version }}</div>
        </div>
        <div class="info-item">
          <div class="label">Laravel</div>
          <div class="value">{{ diagnostics.app.laravel_version }}</div>
        </div>
        <div class="info-item">
          <div class="label">Queue</div>
          <div class="value">{{ diagnostics.app.queue_connection }}</div>
        </div>
        <div class="info-item">
          <div class="label">Cache</div>
          <div class="value">{{ diagnostics.app.cache_store }}</div>
        </div>
        <div class="info-item">
          <div class="label">Laufende Jobs</div>
          <div class="value">{{ workerStatus.reduce((sum, w) => sum + (Array.isArray(w.active_jobs) ? w.active_jobs.length : 0), 0) }}</div>
        </div>
        <div class="info-item">
          <div class="label">Aktive Worker</div>
          <div class="value">{{ workerStatus.filter(w => w.status === 'online').length }} / {{ workerStatus.length }}</div>
        </div>
        <div class="info-item">
          <div class="label">Queue-Typ</div>
          <div class="value">{{ diagnostics.queue.connection }}</div>
        </div>
        <div class="info-item">
          <div class="label">Serverzeit</div>
          <div class="value">{{ formatDateTime(diagnostics?.server_time) }}</div>
        </div>
        <div class="info-item">
          <div class="label">Server-Zeitzone</div>
          <div class="value">{{ diagnostics?.server_timezone }}</div>
        </div>
        <div class="info-item">
          <div class="label">Lokale Zeit (Client)</div>
          <div class="value">{{ formatDateTime(clientTime) }}</div>
        </div>
        <div class="info-item">
          <div class="label">Lokale Zeitzone (Client)</div>
          <div class="value">{{ clientTimezone }}</div>
        </div>
       </div>
      <p v-else class="muted">Keine Daten geladen.</p>
    </div>

    <div class="card">
      <div class="card-header">
        <h4>Scheduler</h4>
      </div>
      <div class="info-grid" v-if="diagnostics?.scheduler">
        <div class="info-item">
          <div class="label">Letzte Ausführung</div>
          <div class="value">{{ formatDateTime(diagnostics.scheduler.last_executed_at) }}</div>
        </div>
        <div class="info-item">
          <div class="label">Nächste geplante Ausführung</div>
          <div class="value">{{ formatDateTime(diagnostics.scheduler.next_run_at) }}</div>
        </div>
        <div class="info-item">
          <div class="label">Status</div>
          <div class="value">
            <span :class="['pill', diagnostics.scheduler.active ? 'pill-ok' : 'pill-failed']">
              {{ diagnostics.scheduler.active ? 'aktiv' : 'inaktiv' }}
            </span>
          </div>
        </div>
      </div>
      <p v-else class="muted">Keine Scheduler-Daten vorhanden.</p>
    </div>

    <div class="card">
      <div class="card-header">
        <h4>Latenz</h4>
      </div>
      <div class="latency-grid">
        <div class="latency-item" v-for="(entry, key) in diagnostics?.latency || {}" :key="key" :class="statusClass(entry.status)">
          <div class="label">{{ key.toUpperCase() }}</div>
          <div class="value">{{ latencyLabel(entry) }}</div>
          <div class="muted" v-if="entry?.error">{{ entry.error }}</div>
        </div>
      </div>
    </div>

    <div class="card">
      <div class="card-header">
        <h4>Audit-Log</h4>
      </div>
      <div class="info-grid">
        <div class="info-item">
          <div class="label">Aktiviert</div>
          <div class="value" :style="{ color: auditLogEnabled ? '#15803d' : '#b91c1c' }">
            {{ auditLogEnabled ? 'Ja' : 'Nein' }}
          </div>
        </div>
        <div class="info-item">
          <div class="label">Einträge</div>
          <div class="value">
            <template v-if="auditLogLoading">Lade...</template>
            <template v-else-if="auditLogError">{{ auditLogError }}</template>
            <template v-else>{{ auditLogCount }}</template>
          </div>
        </div>
      </div>
    </div>

    <div class="card">
<div class="card-header">
  <h4>Worker-Status</h4>
  <span class="muted" v-if="workerDriver">Treiber: {{ workerDriver }} · TTL: {{ workerTtl }}s</span>
</div>
      <AdminDataTable
        :columns="workerColumns"
        :rows="workerStatus"
        :loading="workerLoading"
        :page-size="20"
        persist-key="admin-worker-status"
        empty-text="Keine aktiven Worker gefunden."
      >
        <template #cell-status="{ value }">
          <span :class="['pill', value === 'online' ? 'pill-ok' : 'pill-failed']">{{ value }}</span>
        </template>
        <template #cell-last_heartbeat_at="{ value }">{{ formatDateTime(value) }}</template>
        <template #cell-last_job_at="{ value }">{{ formatDateTime(value) }}</template>
        <template #cell-redis_latency_ms="{ value }">{{ value != null ? value + ' ms' : '–' }}</template>
        <template #cell-db_latency_ms="{ value }">{{ value != null ? value + ' ms' : '–' }}</template>
        <template #cell-last_job_duration_ms="{ value }">{{ value != null ? value + ' ms' : '–' }}</template>
        <template #cell-active_jobs="{ row }">
          <ul v-if="row.active_jobs && row.active_jobs.length">
            <li v-for="(job, idx) in row.active_jobs" :key="idx">
              {{ job.name || job.id || JSON.stringify(job) }}
            </li>
          </ul>
          <span v-else>–</span>
        </template>
      </AdminDataTable>
    </div>

    <div class="card">
      <div class="card-header">
        <h4>Letzte Worker-Aktionen</h4>
        <span class="muted">Quelle: job_logs</span>
      </div>
      <p v-if="diagnostics?.queue?.error" class="error">{{ diagnostics.queue.error }}</p>
      <AdminDataTable
        v-else
        :columns="jobColumns"
        :rows="diagnostics?.queue?.recent || []"
        :loading="loading"
        :page-size="20"
        persist-key="admin-diagnostics"
        empty-text="Noch keine Einträge vorhanden."
        @refresh="loadDiagnostics"
        @auto-refresh="loadDiagnostics({ auto: true })"
      >
        <template #cell-finished_at="{ value }">{{ formatDateTime(value) }}</template>
        <template #cell-status="{ value }"><span :class="['pill', value === 'processed' ? 'pill-ok' : 'pill-failed']">{{ value }}</span></template>
        <template #cell-runtime_ms="{ value }">{{ value != null ? value + ' ms' : '–' }}</template>
        <template #cell-message="{ value }"><span class="wrap">{{ value || '–' }}</span></template>
      </AdminDataTable>
    </div>
  </div>
</template>

<style scoped>
.stack { display: flex; flex-direction: column; gap: 0.75rem; }
.top-bar { display: flex; align-items: center; justify-content: space-between; gap: 0.75rem; }
.actions { display: flex; gap: 0.5rem; align-items: center; flex-wrap: wrap; }
.card { border: 1px solid var(--border-strong); border-radius: 8px; padding: 0.75rem; background: var(--surface); display: flex; flex-direction: column; gap: 0.5rem; color: var(--text); box-shadow: 0 2px 8px var(--shadow); }
.card-header { display: flex; align-items: center; justify-content: space-between; gap: 0.75rem; }
.info-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(180px, 1fr)); gap: 0.5rem; }
.info-item { padding: 0.5rem; border: 1px solid var(--border); border-radius: 6px; background: var(--surface-muted); color: var(--text); }
.label { font-weight: 600; color: var(--text-muted); }
.value { font-weight: 700; color: var(--text); word-break: break-word; }
.muted { color: var(--text-muted); font-size: 0.9rem; }
.latency-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(160px, 1fr)); gap: 0.5rem; }
.latency-item { padding: 0.75rem; border: 1px solid var(--border); border-radius: 8px; background: var(--surface-muted); color: var(--text); }
.status-ok { border-color: #bbf7d0; background: #f0fdf4; }
.status-failed { border-color: #fecaca; background: #fef2f2; }
.message { color: var(--success-text); background: var(--success-bg); border: 1px solid var(--success-border); padding: 0.5rem; border-radius: 6px; }
.error { color: var(--error-text); background: var(--error-bg); border: 1px solid var(--error-border); padding: 0.5rem; border-radius: 6px; }
.table { width: 100%; border-collapse: collapse; }
th, td { border-bottom: 1px solid var(--border-strong); padding: 0.5rem; text-align: left; color: var(--text); }
.table-wrapper { overflow-x: auto; }
.pill { display: inline-flex; align-items: center; padding: 0.15rem 0.45rem; border-radius: 999px; font-size: 0.85rem; text-transform: uppercase; letter-spacing: 0.02em; }
.pill-ok { background: var(--success-bg); color: var(--success-text); border: 1px solid var(--success-border); }
.pill-failed { background: var(--error-bg); color: var(--error-text); border: 1px solid var(--error-border); }
.wrap { max-width: 320px; white-space: pre-wrap; word-break: break-word; }
.inline { display: inline-flex; align-items: center; gap: 0.35rem; font-weight: 600; color: var(--text); }
</style>
